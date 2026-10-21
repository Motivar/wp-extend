<?php
/**
 * AWM_DB_Creator schema lifecycle on real tables: create, version upgrades
 * (add / modify columns, indexes, foreign keys) with existing rows kept,
 * no DDL when the version is unchanged, and drop + recreate.
 *
 * WP_UnitTestCase rewrites CREATE/DROP TABLE into TEMPORARY tables during a
 * test, and temporary tables are invisible to INFORMATION_SCHEMA, which
 * dbUpdate() reads to detect changed columns. This class turns that rewrite
 * off, so every DDL statement creates a real table and implicitly commits:
 * tear_down() therefore drops the tables and deletes the version options
 * itself instead of relying on the transaction rollback.
 */

class Test_Db_Creator_Schema_Lifecycle extends WP_UnitTestCase
{
    /** @var string[] Tables (without prefix) created by the current test. */
    private $tables = array();

    /** @var string[] Queries captured by capture_queries(). */
    private $queries = array();

    /**
     * Allow real CREATE/DROP TABLE for this test.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        AWM_DB_Creator::reset();
        $this->tables  = array();
        $this->queries = array();
    }

    /**
     * Drop every table the test created (children first) and its version option.
     *
     * @return void
     */
    public function tear_down()
    {
        global $wpdb;
        remove_filter('query', array($this, 'record_query'));
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($this->tables) as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
            delete_option(AWM_DB_Creator::VERSION_OPTION_PREFIX . $table);
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        AWM_DB_Creator::reset();
        parent::tear_down();
    }

    /**
     * `query` filter: remember the SQL.
     *
     * @param string $sql Query about to run.
     * @return string Unchanged query.
     */
    public function record_query($sql)
    {
        $this->queries[] = $sql;
        return $sql;
    }

    /**
     * Start recording queries.
     *
     * @return void
     */
    private function capture_queries()
    {
        $this->queries = array();
        add_filter('query', array($this, 'record_query'));
    }

    /**
     * Captured queries matching a pattern.
     *
     * @param string $pattern Regular expression.
     * @return string[] Matching queries.
     */
    private function captured($pattern)
    {
        return array_values(array_filter($this->queries, function ($sql) use ($pattern) {
            return (bool) preg_match($pattern, $sql);
        }));
    }

    /**
     * Unique table name, remembered for cleanup.
     *
     * @param string $suffix Readable suffix.
     * @return string Table name without prefix.
     */
    private function table_name($suffix)
    {
        $table          = 'ewp_lc_' . $suffix . '_' . strtolower(wp_generate_password(5, false));
        $this->tables[] = $table;
        return $table;
    }

    /**
     * Run AWM_DB_Creator for one table, as a new request would.
     *
     * @param string $table  Table name without prefix.
     * @param array  $schema Table definition.
     * @return void
     */
    private function apply($table, array $schema)
    {
        AWM_DB_Creator::reset();
        new AWM_DB_Creator(array($table => $schema));
    }

    /**
     * Columns of a real table.
     *
     * @param string $table Table name without prefix.
     * @return array Column name => { type, nullable, default }.
     */
    private function columns($table)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            DB_NAME,
            $wpdb->prefix . $table
        ));
        $columns = array();
        foreach ($rows as $row) {
            $columns[$row->COLUMN_NAME] = array(
                'type'     => strtolower($row->COLUMN_TYPE),
                'nullable' => $row->IS_NULLABLE === 'YES',
                'default'  => $row->COLUMN_DEFAULT,
            );
        }
        return $columns;
    }

    /**
     * Index name => ordered column list.
     *
     * @param string $table Table name without prefix.
     * @return array Index name => column names.
     */
    private function indexes($table)
    {
        global $wpdb;
        $indexes = array();
        foreach ($wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $wpdb->prefix . $table)) as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }
        return array_map('array_values', $indexes);
    }

    /**
     * Foreign keys of a table: constraint => "column → table(column)".
     *
     * @param string $table Table name without prefix.
     * @return array Constraint name => description.
     */
    private function foreign_keys($table)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
            DB_NAME,
            $wpdb->prefix . $table
        ));
        $keys = array();
        foreach ($rows as $row) {
            $keys[$row->CONSTRAINT_NAME] = $row->COLUMN_NAME . ' → ' . $row->REFERENCED_TABLE_NAME . '(' . $row->REFERENCED_COLUMN_NAME . ')';
        }
        return $keys;
    }

    /**
     * Version 1 of the test schema.
     *
     * @return array Table definition.
     */
    private function schema_v1()
    {
        return array(
            'data'       => array(
                'id'      => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'label'   => 'varchar(50) NOT NULL',
                'counter' => 'int(11) NOT NULL DEFAULT 0',
                'created' => 'datetime NOT NULL',
            ),
            'primaryKey' => 'id',
            'index'      => array('label'),
            'version'    => '1',
        );
    }

    /**
     * Insert a row through the DB layer.
     *
     * @param string $table Table name without prefix.
     * @param string $label Label value.
     * @return int New row id.
     */
    private function insert_row($table, $label)
    {
        $result = AWM_DB_Creator::insert_db_data($table, array('label' => $label, 'counter' => 7, 'created' => '2026-10-21 10:00:00'));
        $this->assertIsArray($result, 'insert_db_data failed');
        return (int) $result['id'];
    }

    /**
     * Version 1 creates the table, its primary key and index, and stores the
     * version autoloaded.
     *
     * @return void
     */
    public function test_create_builds_columns_keys_and_version()
    {
        $table = $this->table_name('create');
        $this->apply($table, $this->schema_v1());

        $columns = $this->columns($table);
        $this->assertSame(array('id', 'label', 'counter', 'created'), array_keys($columns));
        $this->assertSame('varchar(50)', $columns['label']['type']);
        $this->assertFalse($columns['label']['nullable']);
        $this->assertSame(array('id'), $this->indexes($table)['PRIMARY']);
        $this->assertContains(array('label'), array_values($this->indexes($table)));
        $this->assertSame('1', (string) get_option('ewp_version_' . $table));
        $this->assertTrue(AWM_DB_Creator::check_table_exists($table));
    }

    /**
     * Loading the same version again runs no DDL and reads no schema.
     *
     * @return void
     */
    public function test_same_version_runs_no_schema_queries()
    {
        $table = $this->table_name('noop');
        $this->apply($table, $this->schema_v1());
        wp_load_alloptions();

        $this->capture_queries();
        $this->apply($table, $this->schema_v1());

        $this->assertSame(array(), $this->captured('/\b(CREATE|ALTER|DROP)\b|INFORMATION_SCHEMA|DESCRIBE|SHOW\s/i'));
    }

    /**
     * A version bump adds new columns; existing rows keep their values and get
     * the new column's default.
     *
     * @return void
     */
    public function test_upgrade_adds_column_and_keeps_rows()
    {
        $table = $this->table_name('addcol');
        $this->apply($table, $this->schema_v1());
        $id = $this->insert_row($table, 'kept');

        $v2                    = $this->schema_v1();
        $v2['data']['note']    = "varchar(191) NOT NULL DEFAULT 'none'";
        $v2['data']['amount']  = 'decimal(10,2) NULL';
        $v2['version']         = '2';
        $this->apply($table, $v2);

        $columns = $this->columns($table);
        $this->assertArrayHasKey('note', $columns);
        $this->assertSame('decimal(10,2)', $columns['amount']['type']);
        $row = AWM_DB_Creator::get_db_data($table, '*', array('clause' => array(array('clause' => array(array('column' => 'id', 'value' => $id, 'compare' => '='))))));
        $this->assertSame('kept', $row[0]['label']);
        $this->assertSame('7', (string) $row[0]['counter']);
        $this->assertSame('none', $row[0]['note']);
        $this->assertSame('2', (string) get_option('ewp_version_' . $table));
    }

    /**
     * A version bump changes column definitions (type length, nullability,
     * default) without losing data.
     *
     * @return void
     */
    public function test_upgrade_modifies_column_definitions_and_keeps_rows()
    {
        $table = $this->table_name('modify');
        $this->apply($table, $this->schema_v1());
        $id = $this->insert_row($table, 'longer label survives');

        $v2                    = $this->schema_v1();
        $v2['data']['label']   = 'varchar(191) NULL';
        $v2['data']['counter'] = 'bigint(20) NOT NULL DEFAULT 5';
        $v2['version']         = '2';
        $this->apply($table, $v2);

        $columns = $this->columns($table);
        $this->assertSame('varchar(191)', $columns['label']['type']);
        $this->assertTrue($columns['label']['nullable']);
        $this->assertStringStartsWith('bigint', $columns['counter']['type']);
        $this->assertSame('5', trim((string) $columns['counter']['default'], "'"));
        $row = AWM_DB_Creator::get_db_data($table, '*', array('clause' => array(array('clause' => array(array('column' => 'id', 'value' => $id, 'compare' => '='))))));
        $this->assertSame('longer label survives', $row[0]['label']);
        $this->assertSame('7', (string) $row[0]['counter']);
    }

    /**
     * A version bump that only adds a column must not rewrite the columns
     * that did not change (each MODIFY COLUMN rebuilds the table).
     *
     * @return void
     */
    public function test_upgrade_leaves_unchanged_columns_alone()
    {
        $table = $this->table_name('stable');
        $this->apply($table, $this->schema_v1());

        $v2                 = $this->schema_v1();
        $v2['data']['note'] = 'varchar(191) NULL';
        $v2['version']      = '2';
        $this->capture_queries();
        $this->apply($table, $v2);

        $this->assertSame(array(), $this->captured('/MODIFY\s+COLUMN/i'));
    }

    /**
     * A version bump adds single and composite indexes.
     *
     * @return void
     */
    public function test_upgrade_adds_indexes()
    {
        $table = $this->table_name('index');
        $this->apply($table, $this->schema_v1());

        $v2            = $this->schema_v1();
        $v2['index']   = array('label', 'created', array('label', 'counter'), 'counter, label(20)', 'bad name; DROP');
        $v2['version'] = '2';
        $this->apply($table, $v2);

        $indexes = array_values($this->indexes($table));
        $this->assertContains(array('created'), $indexes);
        $this->assertContains(array('label', 'counter'), $indexes);
        $this->assertContains(array('counter', 'label'), $indexes);
        $this->assertCount(1, array_keys($indexes, array('label'), true), 'the existing label index must not be duplicated');

        $v2['version'] = '3';
        $this->capture_queries();
        $this->apply($table, $v2);
        $this->assertSame(array(), $this->captured('/ADD\s+INDEX/i'), 'indexes already present must not be added again');
    }

    /**
     * Columns removed from the schema stay in the table with their data:
     * upgrades never drop columns.
     *
     * @return void
     */
    public function test_removed_column_is_kept_with_its_data()
    {
        $table = $this->table_name('keep');
        $this->apply($table, $this->schema_v1());
        $id = $this->insert_row($table, 'still here');

        $v2 = $this->schema_v1();
        unset($v2['data']['counter']);
        $v2['version'] = '2';
        $this->apply($table, $v2);

        $this->assertArrayHasKey('counter', $this->columns($table));
        $row = AWM_DB_Creator::get_db_data($table, '*', array('clause' => array(array('clause' => array(array('column' => 'id', 'value' => $id, 'compare' => '='))))));
        $this->assertSame('7', (string) $row[0]['counter']);
    }

    /**
     * Foreign keys are added between InnoDB tables, once.
     *
     * @return void
     */
    public function test_foreign_key_is_added_once()
    {
        $parent = $this->table_name('parent');
        $child  = $this->table_name('child');
        $this->apply($parent, $this->schema_v1());

        $child_schema = array(
            'data'       => array(
                'id'        => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'parent_id' => 'bigint(20) unsigned NOT NULL',
            ),
            'primaryKey' => 'id',
            'index'      => array('parent_id'),
            'foreignKey' => array(array('key' => 'parent_id', 'ref' => $parent . '(id)')),
            'version'    => '1',
        );
        $this->apply($child, $child_schema);

        global $wpdb;
        $expected = array('fk_' . $child . '_parent_id' => 'parent_id → ' . $wpdb->prefix . $parent . '(id)');
        $this->assertSame($expected, $this->foreign_keys($child));

        $child_schema['version'] = '2';
        $this->apply($child, $child_schema);
        $this->assertSame($expected, $this->foreign_keys($child));
    }

    /**
     * drop_table() removes the table and its version, so the next load
     * creates it again from scratch.
     *
     * @return void
     */
    public function test_drop_table_then_recreate()
    {
        $table = $this->table_name('drop');
        $this->apply($table, $this->schema_v1());
        $this->insert_row($table, 'gone after drop');
        $fired = did_action('ewp_database_dropped');

        $this->assertTrue(AWM_DB_Creator::drop_table($table));

        $this->assertSame(array(), $this->columns($table));
        $this->assertFalse(get_option('ewp_version_' . $table));
        $this->assertFalse(AWM_DB_Creator::check_table_exists($table));
        $this->assertSame($fired + 1, did_action('ewp_database_dropped'));

        $this->apply($table, $this->schema_v1());
        $this->assertArrayHasKey('label', $this->columns($table));
        $this->assertSame(array(), AWM_DB_Creator::get_db_data($table, '*'));
    }

    /**
     * A raw DROP TABLE that leaves the version option behind is not repaired
     * by a reload with the same version: this is why drop_table() exists.
     *
     * @return void
     */
    public function test_raw_drop_with_stale_version_is_not_recreated()
    {
        global $wpdb;
        $table = $this->table_name('stale');
        $this->apply($table, $this->schema_v1());

        $wpdb->query($wpdb->prepare('DROP TABLE %i', $wpdb->prefix . $table));
        $this->apply($table, $this->schema_v1());
        $this->assertSame(array(), $this->columns($table));

        $this->assertTrue(AWM_DB_Creator::drop_table($table));
        $this->apply($table, $this->schema_v1());
        $this->assertArrayHasKey('label', $this->columns($table));
    }

    /**
     * Column comparison across servers: MySQL 8 drops integer display widths,
     * MariaDB quotes string defaults; neither may count as a change, while
     * real changes still do.
     *
     * @dataProvider column_comparison_cases
     *
     * @param string      $column_type Server COLUMN_TYPE.
     * @param string      $nullable    Server IS_NULLABLE.
     * @param string|null $default     Server COLUMN_DEFAULT.
     * @param string      $declared    Declared definition.
     * @param bool        $changed     Expected result.
     * @return void
     */
    public function test_column_comparison($column_type, $nullable, $default, $declared, $changed)
    {
        $method = new ReflectionMethod('AWM_DB_Creator', 'mtv_column_definition_changed');
        $method->setAccessible(true);
        $existing = (object) array('COLUMN_TYPE' => $column_type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default);

        $this->assertSame($changed, $method->invoke(null, $existing, $declared));
    }

    /**
     * Cases for test_column_comparison().
     *
     * @return array[] Server type, nullable, default, declared definition, expected.
     */
    public function column_comparison_cases()
    {
        return array(
            'MariaDB bigint unsigned'          => array('bigint(20) unsigned', 'NO', null, 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', false),
            'MySQL 8 bigint unsigned'          => array('bigint unsigned', 'NO', null, 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', false),
            'MySQL 8 int with default'         => array('int', 'NO', '0', 'int(11) NOT NULL DEFAULT 0', false),
            'MariaDB quoted string default'    => array('varchar(191)', 'NO', "'none'", "varchar(191) NOT NULL DEFAULT 'none'", false),
            'MariaDB NULL default'             => array('varchar(191)', 'YES', 'NULL', 'varchar(191) NULL', false),
            'MariaDB current_timestamp()'      => array('datetime', 'NO', 'current_timestamp()', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', false),
            'integer synonym'                  => array('int(11)', 'NO', null, 'integer NOT NULL', false),
            'longer varchar'                   => array('varchar(50)', 'NO', null, 'varchar(191) NOT NULL', true),
            'becomes nullable'                 => array('varchar(50)', 'NO', null, 'varchar(50) NULL', true),
            'int to bigint'                    => array('int(11)', 'NO', '0', 'bigint(20) NOT NULL DEFAULT 0', true),
            'signed to unsigned'               => array('bigint(20)', 'NO', null, 'bigint(20) unsigned NOT NULL', true),
            'default changed'                  => array('int(11)', 'NO', '0', 'int(11) NOT NULL DEFAULT 5', true),
        );
    }

    /**
     * drop_table() refuses names that are not plain identifiers.
     *
     * @return void
     */
    public function test_drop_table_rejects_invalid_names()
    {
        $this->assertFalse(AWM_DB_Creator::drop_table('posts; DROP TABLE wp_users'));
        $this->assertFalse(AWM_DB_Creator::drop_table('../posts'));
        $this->assertFalse(AWM_DB_Creator::drop_table(''));
    }
}
