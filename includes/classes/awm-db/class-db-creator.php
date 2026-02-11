<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * class to create/update/edit custom databases
 */

class AWM_DB_Creator
{
    private $data;
    public function __construct($data)
    {
        $this->dbUpdate($data);
    }

    /**
     * data structure
     * array('dbName'=>array(
     * 'data'=>array('dbKey'=>sqlData),
     * 'primaryKey=>'dbKey'
     * 'index=>'dbKey'
     * 'version'=>'version' =>string for version
     * ))
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
                $registeredVersion = get_option('ewp_version_' . $table) ?: 0;
                $currentVersion = isset($tableData['version']) ? $tableData['version'] : strtotime('now');

                // If there is no version registered or there is a mismatch between versions, prepare the SQL query
                if ($currentVersion != $registeredVersion || $registeredVersion === 0) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                    global $wpdb;
                    $wpdb->show_errors();

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
                        if (is_array($tableData['index'])) {
                            foreach ($tableData['index'] as $index) {
                                if (is_array($index)) {
                                    // Composite index
                                    $sqlInsertString[] = 'INDEX (' . implode(',', $index) . ')';
                                } else {
                                    // Single column index
                                    $sqlInsertString[] = 'INDEX (' . $index . ')';
                                }
                            }
                        } else {
                            // Backward compatibility: single string index
                            $sqlInsertString[] = 'INDEX (' . $tableData['index'] . ')';
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
                    update_option('ewp_version_' . $table, $currentVersion, false);
                    $message = sprintf(__('Table "%s" just updated! Current version is %s.', 'filox'), $table, $currentVersion);
                    $databasesUpdated[] = $message;
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
        $current_type = strtoupper($existing_column->COLUMN_TYPE);
        $current_nullable = ($existing_column->IS_NULLABLE === 'YES');
        $current_default = $existing_column->COLUMN_DEFAULT;
        
        // Compare data type
        if (strtoupper($new_parts['type']) !== $current_type) {
            return true;
        }
        
        // Compare nullable constraint
        if ($new_parts['nullable'] !== $current_nullable) {
            return true;
        }
        
        // Compare default value (if specified in new definition)
        if (isset($new_parts['default']) && $new_parts['default'] !== $current_default) {
            return true;
        }
        
        return false;
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
        
        // Extract data type (everything before first space or end of string)
        if (preg_match('/^([^\s]+)/', $definition, $matches)) {
            $parts['type'] = strtoupper($matches[1]);
        }
        
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

    public static function check_table_exists($tableName)
    {
        global $wpdb;

        // Add the prefix to the table name
        $tableName = "{$wpdb->prefix}{$tableName}";

        // Check if result is already cached
        if (isset(self::$table_existence_cache[$tableName])) {
            return self::$table_existence_cache[$tableName];
        }

        // Query INFORMATION_SCHEMA once if not cached
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $tableName
            )
        );

        // Cache the result for future calls within the same request
        self::$table_existence_cache[$tableName] = ($result > 0);
        return self::$table_existence_cache[$tableName];
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

            // Enable error logging for wpdb
            $wpdb->show_errors();

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



    public static function insert_db_data($tableName, $data, $primary_key = 'id')
    {
        // Call wpdb to get access to table prefix
        global $wpdb;

        // Enable error handling
        $wpdb->show_errors();

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

            // Enable error logging for wpdb
            $wpdb->show_errors();

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

            // Enable error logging for wpdb
            $wpdb->show_errors();

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