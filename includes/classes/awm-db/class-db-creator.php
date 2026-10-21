<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * class to create/update/edit custom databases
 */

class AWM_DB_Creator
{
    /** Prefix of the option holding each table's applied schema version. */
    const VERSION_OPTION_PREFIX = 'ewp_version_';

    /** Flag set once every existing version option has been switched to autoload. */
    const AUTOLOAD_MIGRATED_OPTION = 'ewp_db_versions_autoloaded';

    /** @var bool Whether the autoload migration was checked in this request. */
    private static $autoload_checked = false;

    private $data;

    /**
     * Create or upgrade the given tables.
     *
     * @param array $data Table definitions, see dbUpdate().
     */
    public function __construct($data)
    {
        self::maybe_autoload_version_options();
        $this->dbUpdate($data);
    }

    /**
     * Switch existing `ewp_version_*` options to autoload, once per site.
     *
     * Before 1.5.9 they were stored with autoload off, so every request that
     * instantiated this class ran one option query per registered table
     * (21 per admin request on a filox site). New versions are written
     * autoloaded by dbUpdate(); this converts the rows written before.
     *
     * @return void
     */
    public static function maybe_autoload_version_options()
    {
        if (self::$autoload_checked) {
            return;
        }
        self::$autoload_checked = true;
        if (get_option(self::AUTOLOAD_MIGRATED_OPTION)) {
            return;
        }

        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::VERSION_OPTION_PREFIX) . '%'
        ));
        if (!empty($names)) {
            self::set_autoload($names);
        }
        update_option(self::AUTOLOAD_MIGRATED_OPTION, 1, true);
    }

    /**
     * Mark options as autoloaded.
     *
     * @param string[] $names Option names.
     * @return void
     */
    private static function set_autoload(array $names)
    {
        if (function_exists('wp_set_option_autoload_values')) {
            wp_set_option_autoload_values(array_fill_keys($names, true));
            return;
        }

        // WordPress 6.2–6.3: no bulk API yet.
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN ($placeholders)", $names)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        wp_cache_delete('alloptions', 'options');
        foreach ($names as $name) {
            wp_cache_delete($name, 'options');
        }
    }

    /**
     * Drop a custom table and forget its schema version.
     *
     * Deleting `ewp_version_{table}` matters: dbUpdate() only builds a table
     * when the registered version differs, so a table dropped while its
     * option stays behind would never be recreated, and check_table_exists()
     * would keep reporting it. Use this instead of a raw DROP TABLE.
     *
     * @param string $tableName Table name without the WordPress prefix (letters, digits, `_`).
     * @return bool True when the table no longer exists, false on an invalid name or a failed DROP.
     */
    public static function drop_table($tableName)
    {
        global $wpdb;

        $tableName = (string) $tableName;
        if (!self::is_identifier($tableName)) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $tableName));
        if ($result === false) {
            error_log('SQL Error: could not drop table ' . $tableName . ': ' . $wpdb->last_error);
            return false;
        }

        delete_option(self::VERSION_OPTION_PREFIX . $tableName);
        unset(self::$table_existence_cache[$wpdb->prefix . $tableName]);

        /**
         * Fires after a custom table was dropped through AWM_DB_Creator::drop_table().
         *
         * @since 1.5.9
         *
         * @param string $tableName Table name without the WordPress prefix.
         */
        do_action('ewp_database_dropped', $tableName);

        return true;
    }

    /**
     * Remove columns from a custom table.
     *
     * Foreign keys defined on a removed column go first. The primary key,
     * unknown columns and invalid names are skipped (primary key logged); the
     * rest go in one ALTER TABLE. Usually driven by a schema's `dropColumns`
     * list on a version bump (see dbUpdate()); callable directly for one-off
     * migrations.
     *
     * @param string   $tableName Table name without the WordPress prefix.
     * @param string[] $columns   Column names to remove.
     * @return string[]|false Columns removed (possibly none), false on an invalid or missing table or a failed ALTER.
     */
    public static function drop_columns($tableName, array $columns)
    {
        global $wpdb;

        $tableName = (string) $tableName;
        if (!self::is_identifier($tableName) || !self::check_table_exists($tableName)) {
            return false;
        }

        $removable = self::removable_columns($tableName, $columns);
        if (empty($removable)) {
            return array();
        }

        self::remove_foreign_keys_on($tableName, $removable);
        $clauses = implode(', ', array_fill(0, count($removable), 'DROP COLUMN %i'));
        $sql     = $wpdb->prepare('ALTER TABLE %i ' . $clauses, array_merge(array($wpdb->prefix . $tableName), $removable)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
        if ($wpdb->query($sql) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            error_log('Failed to remove columns (' . implode(',', $removable) . ") from table {$tableName}: " . $wpdb->last_error);
            return false;
        }

        /**
         * Fires after columns were removed from a custom table.
         *
         * @since 1.5.9
         *
         * @param string   $tableName Table name without the WordPress prefix.
         * @param string[] $removable Columns removed.
         */
        do_action('ewp_database_columns_dropped', $tableName, $removable);

        return $removable;
    }

    /**
     * Remove every row of a custom table and reset its AUTO_INCREMENT.
     *
     * Uses TRUNCATE; when InnoDB refuses it because another table references
     * this one through a foreign key (content type `_main` tables), falls back
     * to DELETE + `AUTO_INCREMENT = 1`. That fallback fails, and nothing is
     * deleted, while referencing rows exist: empty the child table first
     * (`{type}_data` before `{type}_main`). Schema and version are kept.
     * Caches built from the table (transients) are not flushed: listen to
     * `ewp_database_truncated` for that.
     *
     * @param string $tableName Table name without the WordPress prefix.
     * @return bool True when the table is now empty.
     */
    public static function truncate_table($tableName)
    {
        global $wpdb;

        $tableName = (string) $tableName;
        if (!self::is_identifier($tableName) || !self::check_table_exists($tableName)) {
            return false;
        }

        $prefixed = $wpdb->prefix . $tableName;
        if ($wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', $prefixed)) === false && !self::empty_referenced_table($prefixed)) {
            error_log("Failed to truncate table {$tableName}: " . $wpdb->last_error);
            return false;
        }

        /**
         * Fires after a custom table was emptied through AWM_DB_Creator::truncate_table().
         *
         * @since 1.5.9
         *
         * @param string $tableName Table name without the WordPress prefix.
         */
        do_action('ewp_database_truncated', $tableName);

        return true;
    }

    /**
     * Empty a table that TRUNCATE refused (referenced by a foreign key).
     *
     * @param string $prefixed Table name with the WordPress prefix.
     * @return bool True when every row was deleted and the counter reset.
     */
    private static function empty_referenced_table($prefixed)
    {
        global $wpdb;

        if ($wpdb->query($wpdb->prepare('DELETE FROM %i', $prefixed)) === false) {
            return false;
        }
        return $wpdb->query($wpdb->prepare('ALTER TABLE %i AUTO_INCREMENT = 1', $prefixed)) !== false;
    }

    /**
     * Columns from a schema's `dropColumns` that are no longer declared.
     *
     * @param string   $tableName Table name without the WordPress prefix (for the log).
     * @param string[] $requested Columns listed in `dropColumns`.
     * @param string[] $declared  Columns declared in `data`.
     * @return string[] Requested columns not declared; declared ones are logged and kept.
     */
    private static function undeclared_columns($tableName, array $requested, array $declared)
    {
        $declared = array_map('strtolower', $declared);
        return array_values(array_filter($requested, function ($column) use ($declared, $tableName) {
            if (!in_array(strtolower((string) $column), $declared, true)) {
                return true;
            }
            error_log("Keeping column {$column} of table {$tableName}: listed in 'dropColumns' but still declared in 'data'.");
            return false;
        }));
    }

    /**
     * Requested columns that exist and are not part of the primary key.
     *
     * @param string   $tableName Table name without the WordPress prefix.
     * @param string[] $columns   Requested column names.
     * @return string[] Column names, as stored, safe to remove.
     */
    private static function removable_columns($tableName, array $columns)
    {
        global $wpdb;

        $existing = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $wpdb->prefix . $tableName)) as $row) {
            $existing[strtolower($row->Field)] = array('name' => $row->Field, 'primary' => $row->Key === 'PRI');
        }

        $removable = array();
        foreach ($columns as $column) {
            $key = strtolower((string) $column);
            if (!self::is_identifier($key) || !isset($existing[$key])) {
                continue;
            }
            if ($existing[$key]['primary']) {
                error_log("Keeping column {$column} of table {$tableName}: it is part of the primary key.");
                continue;
            }
            $removable[] = $existing[$key]['name'];
        }
        return array_values(array_unique($removable));
    }

    /**
     * Remove the foreign keys defined on the given columns of a table.
     *
     * @param string   $tableName Table name without the WordPress prefix.
     * @param string[] $columns   Column names.
     * @return void
     */
    private static function remove_foreign_keys_on($tableName, array $columns)
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($columns), '%s'));
        $constraints  = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL AND COLUMN_NAME IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(DB_NAME, $wpdb->prefix . $tableName), $columns)
        ));
        foreach ($constraints as $constraint) {
            if ($wpdb->query($wpdb->prepare('ALTER TABLE %i DROP FOREIGN KEY %i', $wpdb->prefix . $tableName, $constraint)) === false) {
                error_log("Failed to remove foreign key {$constraint} on table {$tableName}: " . $wpdb->last_error);
            }
        }
    }

    /**
     * Whether a table or column name is a plain identifier (letters, digits, `_`).
     *
     * @param string $name Name to check.
     * @return bool True when safe to use as an identifier.
     */
    private static function is_identifier($name)
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', (string) $name);
    }

    /**
     * Forget per-request state (table-existence cache, autoload check).
     *
     * @return void
     */
    public static function reset()
    {
        self::$table_existence_cache = [];
        self::$autoload_checked      = false;
    }

    /**
     * Create or upgrade tables from their definitions.
     *
     * Structure: array('table' => array(
     *   'data'        => array('column' => 'SQL definition', …),
     *   'primaryKey'  => 'column',
     *   'index'       => 'column' | array('column', array('a', 'b'), 'c(191)', …),
     *   'foreignKey'  => array(array('key' => 'column', 'ref' => 'table(column)'), …),
     *   'dropColumns' => array('column', …), // optional: removed on the next version bump (see drop_columns())
     *   'version'     => 'string', // bump to apply changes; omitted = rebuilt on every load
     * ))
     *
     * Changes run only when `version` differs from `ewp_version_{table}`: new
     * columns are added, changed definitions modified, missing indexes and
     * foreign keys added, listed `dropColumns` removed. Columns that merely
     * disappear from `data` are kept.
     *
     * @param array $dbData Table name (without prefix) => definition.
     * @return void
     */

    public function dbUpdate($dbData)
    {
        $databasesUpdated = [];
        $databasesNotUpdated = [];

        try {
            foreach ($dbData as $table => $tableData) {
                // Validate table name and data
                if (empty($table) || empty($tableData)) {
                    throw new Exception(sprintf(__('Invalid table or data for table: %s.', 'filox'), $table));
                }

                /* Check table version */
                $registeredVersion = get_option(self::VERSION_OPTION_PREFIX . $table) ?: 0;
                $currentVersion = isset($tableData['version']) ? $tableData['version'] : strtotime('now');

                // If there is no version registered or there is a mismatch between versions, prepare the SQL query
                if ($currentVersion != $registeredVersion || $registeredVersion === 0) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                    global $wpdb;

                    $charset_collate = $wpdb->get_charset_collate();
                    $sqlInsertString = $tableKeys = $foreignKeys = [];

                    if (isset($tableData['data']) && !empty($tableData['data'])) {
                        foreach ($tableData['data'] as $tableKey => $tableSettings) {
                            $sqlInsertString[] = $tableKey . ' ' . $tableSettings;
                            $tableKeys[$tableKey] = $tableSettings;
                        }
                    }

                    if (isset($tableData['primaryKey'])) {
                        $sqlInsertString[] = 'PRIMARY KEY  (' . $tableData['primaryKey'] . ')';
                    }

                    if (isset($tableData['index'])) {
                        // A string is one index (single column or comma-separated
                        // list); an array lists indexes, each a column or a column list.
                        foreach ((array) $tableData['index'] as $index) {
                            $definition = self::index_definition($index);
                            if ($definition === null) {
                                error_log("Skipping invalid index definition on table {$table}: " . wp_json_encode($index));
                                continue;
                            }
                            $sqlInsertString[] = $definition;
                        }
                    }

                    // Store foreign keys for later processing (after table creation)
                    if (isset($tableData['foreignKey']) && !empty($tableData['foreignKey'])) {
                        foreach ($tableData['foreignKey'] as $foreignKey) {
                            $foreignKeys[$foreignKey['key']] = $foreignKey['ref'];
                        }
                    }

                    $sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . $table . ' (' . implode(',', $sqlInsertString) . ') ' . $charset_collate;

                    // Execute the SQL query (without foreign keys)
                    dbDelta($sql);

                    // Add foreign keys separately after table creation to avoid dbDelta issues
                    if (!empty($foreignKeys)) {
                        $this->ewp_add_foreign_keys($table, $foreignKeys, $wpdb);
                    }

                    // Explicitly listed columns are removed on a version bump only.
                    if ($registeredVersion !== 0 && !empty($tableData['dropColumns'])) {
                        self::drop_columns($table, self::undeclared_columns($table, (array) $tableData['dropColumns'], array_keys($tableKeys)));
                    }

                    // dbDelta cannot match unnamed `INDEX (col)` definitions, so on
                    // an existing table it never adds a new index: add missing ones here.
                    if ($registeredVersion !== 0 && isset($tableData['index'])) {
                        self::ensure_indexes($table, $tableData['index']);
                    }

                    // Handle version mismatch and add missing columns or modify existing ones
                    if ($currentVersion !== $registeredVersion && $registeredVersion !== 0) {
                        foreach ($tableKeys as $id => $data) {
                            // Check if column exists and get its current definition
                            $existing_column = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
                                     FROM INFORMATION_SCHEMA.COLUMNS 
                                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                                    DB_NAME,
                                    $wpdb->prefix . $table,
                                    $id
                                )
                            );
                            
                            if (empty($existing_column)) {
                                // Column doesn't exist, add it
                                $result = $wpdb->query("ALTER TABLE {$wpdb->prefix}{$table} ADD COLUMN {$id} {$data}");
                                if ($result === false) {
                                    error_log("Failed to add column {$id} to table {$table}: " . $wpdb->last_error);
                                }
                            } else {
                                // Column exists, check if definition has changed
                                $needs_modification = self::mtv_column_definition_changed($existing_column, $data);
                                
                                if ($needs_modification) {
                                    // Modify the existing column
                                    $result = $wpdb->query("ALTER TABLE {$wpdb->prefix}{$table} MODIFY COLUMN {$id} {$data}");
                                    if ($result === false) {
                                        error_log("Failed to modify column {$id} in table {$table}: " . $wpdb->last_error);
                                    } else {
                                        error_log("Successfully modified column {$id} in table {$table}");
                                    }
                                }
                            }
                        }
                    }

                    // Check for errors
                    if (!empty($wpdb->last_error)) {
                        $message = sprintf(__('Table "%s" not updated! The error is: <strong>%s</strong>.', 'filox'), $table, $wpdb->last_error);
                        $databasesNotUpdated[] = $message;
                        continue; // Skip to the next table
                    }

                    // Update table version
                    update_option(self::VERSION_OPTION_PREFIX . $table, $currentVersion, true);
                    $message = sprintf(__('Table "%s" just updated! Current version is %s.', 'filox'), $table, $currentVersion);
                    $databasesUpdated[] = $message;
                    /**
                     * Fires after a custom table was created or upgraded to a new schema version.
                     *
                     * @since 1.0.0
                     *
                     * @param string     $table          Table name without the WordPress prefix.
                     * @param array      $tableData      Table definition (data, primaryKey, index, foreignKey, version).
                     * @param string|int $currentVersion Schema version now stored in `ewp_version_{$table}`.
                     */
                    do_action('ewp_database_updated', $table, $tableData, $currentVersion);
                }
            }
        } catch (Exception $e) {
            error_log('Error in dbUpdate: ' . $e->getMessage());
            $databasesNotUpdated[] = sprintf(__('Error in table "%s": %s', 'filox'), $table, $e->getMessage());

            // Log the DB error via EWP Logger
            ewp_log(
                'extend-wp',
                'db_error',
                sprintf('DB update failed for table "%s": %s', $table, $e->getMessage()),
                [
                    'table'     => $table,
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ],
                'developer',
                'database',
                0
            );
        }

        // Display messages for updated tables
        if (!empty($databasesUpdated)) {
            $message = new Extend_WP_Notices();
            $message->set_message(implode('<br>', $databasesUpdated));
            $message->set_class('updated');
        }

        // Display error messages for tables that failed to update
        if (!empty($databasesNotUpdated)) {
            $error_message = new Extend_WP_Notices();
            $error_message->set_message(implode('<br>', $databasesNotUpdated));
            $error_message->set_class('error');
        }
    }

    private static $table_existence_cache = [];

    /**
     * Compare existing column definition with new definition to determine if modification is needed
     * 
     * @param object $existing_column Column information from INFORMATION_SCHEMA.COLUMNS
     * @param string $new_definition New column definition (e.g., 'VARCHAR(32) NOT NULL')
     * @return bool True if column needs modification, false otherwise
     */
    private static function mtv_column_definition_changed($existing_column, $new_definition)
    {
        if (empty($existing_column) || empty($new_definition)) {
            return false;
        }

        // Parse the new definition to extract components
        $new_parts = self::mtv_parse_column_definition($new_definition);
        
        // Get current column properties
        $current_type = self::normalize_column_type($existing_column->COLUMN_TYPE);
        $current_nullable = ($existing_column->IS_NULLABLE === 'YES');
        $current_default = self::normalize_column_default($existing_column->COLUMN_DEFAULT);
        
        // Compare data type
        if (self::normalize_column_type($new_parts['type']) !== $current_type) {
            return true;
        }
        
        // Compare nullable constraint
        if ($new_parts['nullable'] !== $current_nullable) {
            return true;
        }
        
        // Compare default value (if specified in new definition)
        if (isset($new_parts['default']) && (string) $new_parts['default'] !== (string) $current_default) {
            return true;
        }
        
        return false;
    }

    /**
     * Comparable form of a column type: upper case, single spaces, integer
     * display widths removed (MySQL 8 reports `bigint unsigned` for a column
     * declared `bigint(20) unsigned`), INTEGER spelled INT.
     *
     * @param string $type Column type as declared or as read from INFORMATION_SCHEMA.
     * @return string Normalised type.
     */
    private static function normalize_column_type($type)
    {
        $type = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $type)));
        $type = preg_replace('/\bINTEGER\b/', 'INT', $type);
        return preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)\s*\(\d+\)/', '$1', $type);
    }

    /**
     * Comparable form of a column default read from INFORMATION_SCHEMA
     * (MariaDB quotes string defaults and reports `NULL` as a string).
     *
     * @param string|null $default Raw COLUMN_DEFAULT.
     * @return string|null Default without surrounding quotes, null for none.
     */
    private static function normalize_column_default($default)
    {
        if ($default === null || strtoupper((string) $default) === 'NULL') {
            return null;
        }
        $default = trim((string) $default, "'\"");
        return strtoupper($default) === 'CURRENT_TIMESTAMP()' ? 'CURRENT_TIMESTAMP' : $default;
    }

    /**
     * Parse column definition string to extract type, nullable, default, etc.
     * 
     * @param string $definition Column definition (e.g., 'VARCHAR(32) NOT NULL DEFAULT "test"')
     * @return array Parsed components
     */
    private static function mtv_parse_column_definition($definition)
    {
        $definition = trim($definition);
        $parts = [
            'type' => '',
            'nullable' => true,
            'default' => null,
            'auto_increment' => false
        ];
        
        // Data type: everything before the first column attribute, so
        // modifiers such as UNSIGNED / ZEROFILL stay part of the type.
        $type = preg_split('/\s+(?:NOT\s+NULL|NULL|DEFAULT|AUTO_INCREMENT|PRIMARY|UNIQUE|KEY|COMMENT|COLLATE|CHARACTER\s+SET|CHARSET|ON\s+UPDATE|GENERATED|AS|CHECK|REFERENCES)\b/i', $definition, 2);
        $parts['type'] = strtoupper(trim($type[0]));
        
        // Check for NOT NULL
        if (stripos($definition, 'NOT NULL') !== false) {
            $parts['nullable'] = false;
        }
        
        // Check for AUTO_INCREMENT
        if (stripos($definition, 'AUTO_INCREMENT') !== false) {
            $parts['auto_increment'] = true;
        }
        
        // Extract DEFAULT value
        if (preg_match('/DEFAULT\s+([^\s]+)/i', $definition, $matches)) {
            $default_value = $matches[1];
            // Remove quotes if present
            $default_value = trim($default_value, '"\'');
            // Handle special MySQL defaults
            if (strtoupper($default_value) === 'CURRENT_TIMESTAMP') {
                $parts['default'] = 'CURRENT_TIMESTAMP';
            } else {
                $parts['default'] = $default_value;
            }
        }
        
        return $parts;
    }

    /**
     * Add every declared index that the table does not have yet.
     *
     * An index counts as present when an existing index covers exactly the
     * same columns (and prefix lengths) in the same order, whatever its name,
     * so tables created by older versions (auto-named indexes) get no
     * duplicates.
     *
     * @param string       $table   Table name without the WordPress prefix.
     * @param array|string $indexes Declared indexes: a column (`col`, `col(191)`),
     *                              a comma-separated list, or an array of columns.
     * @return void
     */
    private static function ensure_indexes($table, $indexes)
    {
        global $wpdb;

        $existing = self::index_signatures($table);
        foreach ((array) $indexes as $index) {
            $columns = self::parse_index_columns($index);
            if ($columns === null) {
                error_log("Skipping invalid index definition on table {$table}: " . wp_json_encode($index));
                continue;
            }
            $signature = implode(',', array_map(function ($column) {
                return strtolower($column['name']) . ($column['length'] ? '(' . $column['length'] . ')' : '');
            }, $columns));
            if (in_array($signature, $existing, true)) {
                continue;
            }
            $parts = array_map(function ($column) {
                return $column['length'] ? '%i(' . $column['length'] . ')' : '%i';
            }, $columns);
            $sql = $wpdb->prepare(
                'ALTER TABLE %i ADD INDEX (' . implode(', ', $parts) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and integer lengths only.
                array_merge(array($wpdb->prefix . $table), array_column($columns, 'name'))
            );
            if ($wpdb->query($sql) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                error_log("Failed to add index ({$signature}) to table {$table}: " . $wpdb->last_error);
            }
        }
    }

    /**
     * `INDEX (...)` clause for CREATE TABLE from a declared index.
     *
     * @param array|string $index Declared index, see parse_index_columns().
     * @return string|null Clause with backtick-quoted columns, null when invalid.
     */
    private static function index_definition($index)
    {
        $columns = self::parse_index_columns($index);
        if ($columns === null) {
            return null;
        }
        return 'INDEX (' . implode(',', array_map(function ($column) {
            return '`' . $column['name'] . '`' . ($column['length'] ? '(' . $column['length'] . ')' : '');
        }, $columns)) . ')';
    }

    /**
     * Column signatures of a table's indexes, e.g. `meta_key,meta_value(191)`.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return string[] Index name => signature.
     */
    private static function index_signatures($table)
    {
        global $wpdb;

        $indexes = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $wpdb->prefix . $table)) as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = strtolower($row->Column_name) . ($row->Sub_part ? '(' . (int) $row->Sub_part . ')' : '');
        }
        return array_map(function ($columns) {
            ksort($columns);
            return implode(',', $columns);
        }, $indexes);
    }

    /**
     * Parse one declared index into columns with optional prefix lengths.
     *
     * @param array|string $index `col`, `col(191)`, `a,b` or `['a', 'b(20)']`.
     * @return array[]|null List of { name, length }, null when any part is not a plain identifier.
     */
    private static function parse_index_columns($index)
    {
        $columns = array();
        foreach (array_map('trim', is_array($index) ? $index : explode(',', (string) $index)) as $part) {
            if (!preg_match('/^([A-Za-z0-9_]+)\s*(?:\((\d+)\))?$/', (string) $part, $m)) {
                return null;
            }
            $columns[] = array('name' => $m[1], 'length' => isset($m[2]) ? (int) $m[2] : 0);
        }
        return $columns ?: null;
    }

    /**
     * Add foreign keys to a table after creation
     * 
     * This method adds foreign keys separately from table creation to avoid dbDelta() issues.
     * If the source or referenced table is not InnoDB, the foreign key is skipped but the column remains.
     * 
     * @param string $table Table name (without prefix)
     * @param array $foreignKeys Array of foreign keys: ['column_name' => 'referenced_table(column)']
     * @param object $wpdb WordPress database object
     */
    private function ewp_add_foreign_keys($table, $foreignKeys, $wpdb)
    {
        $full_table_name = $wpdb->prefix . $table;

        // Check if source table is InnoDB
        $source_engine = $wpdb->get_var($wpdb->prepare(
            "SELECT ENGINE 
             FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s",
            DB_NAME,
            $full_table_name
        ));

        if (strtoupper($source_engine) !== 'INNODB') {
            error_log("Skipping all foreign keys for table {$table}: Source table uses {$source_engine} engine (InnoDB required for foreign keys)");
            return;
        }

        foreach ($foreignKeys as $key => $ref) {
            // Generate constraint name
            $constraint_name = 'fk_' . $table . '_' . $key;

            // Check if foreign key constraint already exists
            $existing_fk = $wpdb->get_var($wpdb->prepare(
                "SELECT CONSTRAINT_NAME 
                 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                 WHERE TABLE_SCHEMA = %s 
                 AND TABLE_NAME = %s 
                 AND COLUMN_NAME = %s 
                 AND CONSTRAINT_NAME = %s",
                DB_NAME,
                $full_table_name,
                $key,
                $constraint_name
            ));

            // Skip if constraint already exists
            if ($existing_fk) {
                continue;
            }

            // Extract referenced table name and column
            $ref_table = preg_replace('/\(.*\)$/', '', $ref);
            $ref_column = preg_match('/\(([^)]+)\)/', $ref, $matches) ? $matches[1] : 'ID';
            $full_ref_table = $wpdb->prefix . $ref_table;

            // Check if referenced table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_SCHEMA = %s 
                 AND TABLE_NAME = %s",
                DB_NAME,
                $full_ref_table
            ));

            if (!$table_exists) {
                error_log("Skipping foreign key {$constraint_name} for table {$table}: Referenced table {$full_ref_table} does not exist yet");
                continue;
            }

            // Check if referenced column exists
            $column_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = %s 
                 AND TABLE_NAME = %s 
                 AND COLUMN_NAME = %s",
                DB_NAME,
                $full_ref_table,
                $ref_column
            ));

            if (!$column_exists) {
                error_log("Skipping foreign key {$constraint_name} for table {$table}: Referenced column {$ref_column} does not exist in table {$full_ref_table}");
                continue;
            }

            // Check referenced table engine
            $ref_engine = $wpdb->get_var($wpdb->prepare(
                "SELECT ENGINE 
                 FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_SCHEMA = %s 
                 AND TABLE_NAME = %s",
                DB_NAME,
                $full_ref_table
            ));

            if (strtoupper($ref_engine) !== 'INNODB') {
                error_log("Skipping foreign key {$constraint_name} for table {$table}: Referenced table {$full_ref_table} uses {$ref_engine} engine (InnoDB required)");
                continue;
            }

            // Build and execute the ALTER TABLE statement
            $sql = "ALTER TABLE {$full_table_name} 
                    ADD CONSTRAINT {$constraint_name} 
                    FOREIGN KEY ({$key}) 
                    REFERENCES {$wpdb->prefix}{$ref}";

            $result = $wpdb->query($sql);

            if ($result === false) {
                error_log("Failed to add foreign key {$constraint_name} to table {$table}. SQL: {$sql}. Error: " . $wpdb->last_error);
            }
        }
    }

    /**
     * Whether a custom table exists, cached per request.
     *
     * A table whose `ewp_version_{table}` option is set was created by
     * dbUpdate() and is trusted without a query (the option is autoloaded
     * since 1.5.9). Other tables are checked with `SHOW TABLES LIKE`, which
     * replaced a slower INFORMATION_SCHEMA lookup.
     *
     * @param string $tableName Table name without the WordPress prefix.
     * @return bool True when the table exists.
     */
    public static function check_table_exists($tableName)
    {
        global $wpdb;

        $prefixed = $wpdb->prefix . $tableName;
        if (isset(self::$table_existence_cache[$prefixed])) {
            return self::$table_existence_cache[$prefixed];
        }

        if (get_option(self::VERSION_OPTION_PREFIX . $tableName)) {
            self::$table_existence_cache[$prefixed] = true;
            return true;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefixed)));
        self::$table_existence_cache[$prefixed] = ($found === $prefixed);
        return self::$table_existence_cache[$prefixed];
    }



    /**
     * Runs a select query in the custom Filox DB
     * 
     * @param tableName The name of the table to run the query on
     * @param select The columns to be retrieved. Can be just "*" to select everything or a comma seperated array with the column names
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * @param orderBy Array with the column name and the type of order (ASC or DESC). Defaults to nothing
     * @param limit The number of rows to be returned from the SQL query. Defaults to nothing
     * 
     * @see where_clause (To be implemented after testing)
     * @see orderBy
     */

    public static function get_db_data($tableName, $select = '*', $where_clause = '', $orderBy = array(), $limit = '', $offset = 0, $prepare_only = false, $debug = false)
    {
        try {
            if (!isset($tableName) || !isset($select)) {
                throw new Exception('Table name or select clause is missing.');
            }

            // Check if table exists
            if (!self::check_table_exists($tableName)) {
                throw new Exception("Table '{$tableName}' does not exist.");
            }

            // Call wpdb to get access to table prefix
            global $wpdb;


            // Add the table prefix to the passed-in data table
            $tableName = "{$wpdb->prefix}$tableName";

            // If "select" is an array, implode it with separating commas. If not, select everything
            $select = is_array($select) ? implode(',', $select) : "*";
            $sql = [];

            // Prepare the SQL query
            $sql[] = "SELECT {$select} FROM {$tableName}";

            // Add WHERE clause if provided
            if ($where_clause !== '' && !empty($where_clause)) {
                $sql[] = "WHERE " . self::recursiveQueryBuilder($where_clause);
            }

            // Add ORDER BY clause if provided
            if (!empty($orderBy)) {
                if (!isset($orderBy['column']) || !isset($orderBy['type'])) {
                    throw new Exception('Invalid orderBy clause. "column" and "type" are required.');
                }
                $sql[] = "ORDER BY {$orderBy['column']} {$orderBy['type']}";
            }

            // Add LIMIT and OFFSET clauses if provided
            if ($limit > 0 && $limit !== '') {
                $sql[] = "LIMIT {$limit}";
            }
            if ($offset !== 0) {
                $sql[] = "OFFSET {$offset}";
            }

            // Build the final SQL query
            $sql = implode(' ', $sql);

            // Debug mode
            if ($debug) {
                error_log("SQL Query: {$sql}");
                error_log("Last Error: {$wpdb->last_error}");
            }

            // If prepare_only is true, return the SQL query
            if ($prepare_only) {
                return $sql;
            }

            // Execute the query and check for errors
            $results = $wpdb->get_results($sql, ARRAY_A);

            if ($wpdb->last_error) {
                throw new Exception('Database query failed: ' . $wpdb->last_error);
            }
            // Clean the results using stripslashes_deep
            $results = stripslashes_deep($results);
            return $results;
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }



    /**
     * Insert one row into a custom table.
     *
     * @param string $tableName   Table name without the WordPress prefix.
     * @param array  $data        Column => value.
     * @param string $primary_key Primary key column, used to return the new id; empty for tables without one.
     * @return array|false `[ $primary_key => id ]`, or `['success' => true, 'rows_affected' => n]` for tables
     *                     without a primary key, false on failure.
     */
    public static function insert_db_data($tableName, $data, $primary_key = 'id')
    {
        // Call wpdb to get access to table prefix
        global $wpdb;

        // Enable error handling

        try {
            if (!$tableName || !$data || empty($data)) {
                throw new Exception('Invalid table name or data.');
            }

            // Check if table exists
            if (!self::check_table_exists($tableName)) {
                throw new Exception("Table '{$tableName}' does not exist.");
            }

            // Add the table prefix to the passed-in data table
            $tableName = "{$wpdb->prefix}$tableName";
            $final_data = array();

            // Remove the primary key if it's present in the data
            if (isset($data[$primary_key])) {
                unset($data[$primary_key]);
            }

            // Prepare the data for insertion
            foreach ($data as $key => $value) {
                $final_data[$key] = addslashes(maybe_serialize($value));
            }

            // Insert new row
            $result = $wpdb->insert($tableName, $final_data);

            // Check for errors in insertion
            if ($result === false) {
                throw new Exception('Database insert failed: ' . $wpdb->last_error);
            }
            
            // Try to get the last insert ID using MySQL's LAST_INSERT_ID() first
            $last_insert_id = $wpdb->get_var("SELECT LAST_INSERT_ID()");
            
            // Fallback to WordPress's built-in insert_id if LAST_INSERT_ID() fails
            if (!$last_insert_id) {
                $last_insert_id = $wpdb->insert_id;
            }
            
            // Handle tables without auto-increment (like metadata tables with composite keys)
            if (!$last_insert_id && empty($primary_key)) {
                // For metadata tables, return success without ID
                return array(
                    'success' => true,
                    'rows_affected' => $result
                );
            }
            
            // Final check - if we still don't have an ID for tables that should have one
            if (!$last_insert_id) {
                throw new Exception('Unable to fetch last insert ID.');
            }

            return array(
                $primary_key => $last_insert_id,
            );
        } catch (Exception $e) {
            // Log the error and return false
            error_log('SQL Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Runs an update query in the custom Filox database
     * 
     * @param tableName The name of the table to run the query on
     * @param updateClause An array of column-value key pairs to update. Represents the new values
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */

    public static function update_db_data($tableName, $updateClause, $where_clause = '', $unique = false)
    {
        try {
            // Check that the parameters are valid
            if (empty($tableName) || empty($updateClause)) {
                throw new Exception('Invalid parameters: Table name and update clause are required.');
            }

            // Check if table exists
            if (!self::check_table_exists($tableName)) {
                throw new Exception("Table '{$tableName}' does not exist.");
            }
            // Call wpdb to get the table prefix
            global $wpdb;


            // Add the prefix to the table name
            $tableName = "{$wpdb->prefix}{$tableName}";

            // Format the update clause
            $updateClause = self::functionToFormatTheClause($updateClause);
            if (empty($updateClause)) {
                throw new Exception('Invalid update clause: It cannot be empty.');
            }

            // Prepare the SQL query
            $sql = "UPDATE {$tableName} SET {$updateClause}";

            // Add the WHERE clause if provided
            if (!empty($where_clause)) {
                $sql .= " WHERE " . self::recursiveQueryBuilder($where_clause);
            }

            // Run the update query
            $query = $wpdb->query($sql);

            // Check for errors
            if ($query === false || !empty($wpdb->last_error)) {
                throw new Exception('Database update failed: ' . $wpdb->last_error);
            }

            return true; // Query executed successfully, regardless of rows affected

        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }

    /**
     * Runs a delete query in the custom Filox database
     * 
     * @param tableName The name of the table to run the query on
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */

    public static function delete_db_data($tableName, $where_clause = '')
    {
        try {
            if (empty($tableName)) {
                throw new Exception('Table name is required.');
            }

            // Check if table exists
            if (!self::check_table_exists($tableName)) {
                throw new Exception("Table '{$tableName}' does not exist.");
            }

            // Call wpdb to get the table prefix
            global $wpdb;


            // Add the prefix to the table name
            $tableName = "{$wpdb->prefix}{$tableName}";

            // Prepare the SQL query
            $sql = "DELETE FROM {$tableName}";

            // Add the WHERE clause if provided
            if (!empty($where_clause)) {
                $sql .= " WHERE " . self::recursiveQueryBuilder($where_clause);
            } else {
                throw new Exception('A WHERE clause is required for DELETE operations to prevent accidental data loss.');
            }

            // Execute the query
            $result = $wpdb->query($sql);

            // Check for errors
            if ($result === false) {
                throw new Exception('Database delete failed: ' . $wpdb->last_error);
            }

            return $result; // Return the number of rows affected
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error in delete_db_data: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }

    /**
     * this function decides to insert or update the db data
     * @param tableName The name of the table to run the query on
     * @param updateClause An array of column-value key pairs to update. Represents the new values
     * @param where_clause An associative array containing all the information to limit the query. Contains what follows after "WHERE".
     * 
     * @see where_clause (To be implemented after testing)
     */
    public static function insert_update_db_data($tableName, $data, $where_clause = array(), $unique = false)
    {
        try {
            if (empty($tableName)) {
                throw new Exception('Table name is required.');
            }

            // Check if table exists
            if (!self::check_table_exists($tableName)) {
                throw new Exception("Table '{$tableName}' does not exist.");
            }

            if (empty($data)) {
                throw new Exception('Data to insert or update is required.');
            }

            // If a WHERE clause is provided, check for existing data
            if (!empty($where_clause)) {
                $results = self::get_db_data($tableName, '*', $where_clause);
                if (!empty($results)) {
                    // Handle unique constraint if specified
                    if ($unique) {
                        $data[$unique] = $results[0][$unique];
                        foreach ($results as $result_key => $result) {
                            if ($result_key != 0) {
                                $d_where_clause = array(
                                    "clause" => array(
                                        array(
                                            "operator" => "AND",
                                            "clause" => array(
                                                array("column" => $unique, "value" => $result[$unique], "compare" => "="),
                                            )
                                        ),
                                    )
                                );
                                // Delete duplicate rows
                                self::delete_db_data($tableName, $d_where_clause);
                            }
                        }
                    }

                    // Perform update if data exists
                    $update_result = self::update_db_data($tableName, $data, $where_clause, $unique);

                    if ($update_result === false) {
                        throw new Exception('Database update failed.');
                    }
                    if ($unique) {
                        return array(
                            $unique => $results[0][$unique]
                        );
                    }

                    return $update_result; // Return boolean
                }
            }
            // Perform insert if no existing data
            $insert_result = self::insert_db_data($tableName, $data, $unique ?: '');
            if ($insert_result === false) {
                throw new Exception('Database insert failed.');
            }
            return $insert_result; // Return the ID of the inserted row
        } catch (Exception $e) {
            // Log the error
            error_log('SQL Error in insert_update_db_data: ' . $e->getMessage());
            return false; // Return false to indicate failure
        }
    }


    /**
     * Function that dynamically creates the WHERE syntax for a an SQL query
     * 
     * @param array The array that gets passed in to create the WHERE statement
     * 
     */

    private static function recursiveQueryBuilder($array, $it = 1)
    {
        if (!is_array($array)) {
            return $array;
        }

        if ($it == 1) $flxRecursiveQuery = array();

        // An array of sub queries to be connected by the connecting operator
        global $flxRecursiveQuery;

        // The sql query as a string
        $sql = '';

        // The operator that connects all the constraints together (AND,OR)
        $connectingOperator = '';

        $sqlString = '';

        $addParenthesis = true;

        if (isset($array) && !empty($array)) {
            foreach ($array as $key => $value) {
                // First operator parsed is the connecting operator
                if ($key === "operator") {
                    $connectingOperator = $value;
                }


                // Check that the value is not an empty array
                if (is_array($value) && !empty($value)) {

                    for ($i = 0; $i < sizeof($value); $i++) {
                        // The sql query as a string
                        $sql = '';

                        if ($it == 2 && $addParenthesis) {
                            $sql .= "(";
                            $addParenthesis = false;
                        }

                        if (self::array_depth($value[$i]) > 3) {
                            return self::recursiveQueryBuilder($value[$i], $it = 2);
                        }
                        // Using parentheses in any case. More parentheses is not a problem. A lack there of does.
                        $sql .= "(";

                        // Check if the operator is set. If it's not set we expect only one query and not a combination
                        $operator = isset($value[$i]["operator"]) ? $value[$i]["operator"] : '';

                        // Loop over the clause to get the column name, the value and the copmarison operator
                        foreach ($value[$i]["clause"] as $key => $clause) {

                            $val = is_string($clause["value"]) ? "'{$clause["value"]}'" : $clause["value"];
                            // Concatenate the sql query
                            if ($clause["compare"] == 'IN' || $clause["compare"] == 'NOT IN') {
                                $val = "{$clause["value"]}";
                            }
                            $sql .= "{$clause["column"]} {$clause["compare"]} {$val}";

                            // If an operator is given and there are more clauses to parse add the operator to the query
                            if ($key < (sizeof($value[$i]["clause"]) - 1) && $operator !== '') {
                                $sql .= " {$operator} ";
                            }
                        }
                        // Close the parentheses
                        $sql .= ")";

                        // Push the query to the table.

                        if ($i < sizeof($value) - 1) {
                            $sql .= " {$connectingOperator}";
                        }

                        $flxRecursiveQuery[] = $sql;
                    }

                    if ($it == 2) {
                        $flxRecursiveQuery[] = ")";
                    }

                    // Connect the sql strings by introducton the connecting operator between them
                    $sqlString = implode(" ", $flxRecursiveQuery);

                    $flxRecursiveQuery = array();
                    if ($it == 1) {
                        return $sql;
                    }

                    return $sqlString;
                    // }

                    // return " {$value["column"]} {$value["compare"]} {$value["value"]}";
                }
            }
        }
    }

    /**
     * Build a legacy `SET col='value',…` fragment (slashes added, values serialised).
     *
     * @param array $clause Column => value.
     * @return string SET fragment without the SET keyword.
     */
    private static function functionToFormatTheClause($clause)
    {
        if (isset($clause) && !empty($clause)) {
            $setStatement = array();
            foreach ($clause as $key => $value) {
                $escaped_value = addslashes(maybe_serialize($value)); // Escape special characters
                $setStatement[] = $key . '=\'' . $escaped_value . '\',';
            }
        }

        return substr(implode('', $setStatement), 0, -1);
    }

    /**
     * Nesting depth of an array, used by the legacy WHERE builder.
     *
     * @param array $array Array to measure.
     * @return int Depth, 1 for a flat array.
     */
    private static function array_depth(array $array)
    {
        $max_depth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = self::array_depth($value) + 1;

                if ($depth > $max_depth) {
                    $max_depth = $depth;
                }
            }
        }

        return $max_depth;
    }
}