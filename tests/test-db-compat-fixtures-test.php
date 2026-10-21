<?php
/**
 * Compatibility of EWP\Db\Where with the pre-1.6 builder on every WHERE
 * recorded from real callers (filox self-test run + admin lists on DDEV,
 * tests/fixtures/db-baseline/fixtures.json).
 *
 * For each recorded WHERE a table is built with the columns it uses and rows
 * generated from the recorded values (and neighbours: ±1 day, ±1, other
 * strings). The old SQL and the new prepared SQL must select exactly the
 * same rows. Sub-select strings (only the old admin-list search produced
 * them) are out of scope: that caller now passes a subquery node.
 */

use EWP\Db\Sql;
use EWP\Db\Table_Columns;

class Test_Db_Compat_Fixtures extends WP_UnitTestCase
{
    /** Rows generated per table. */
    const ROWS = 90;

    /**
     * Recorded WHERE nodes passed straight to AWM_DB_Creator.
     *
     * @return array[] Label => [ where node ].
     */
    public function recorded_wheres()
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/db-baseline/fixtures.json'), true);
        $position = array('AWM_DB_Creator::get_db_data' => 2, 'AWM_DB_Creator::update_db_data' => 2, 'AWM_DB_Creator::delete_db_data' => 1);
        $cases    = array();
        foreach ($fixtures as $fixture) {
            if (!isset($position[$fixture['entry']])) {
                continue;
            }
            foreach ($fixture['args'] as $args) {
                $where = isset($args[$position[$fixture['entry']]]) ? $args[$position[$fixture['entry']]] : null;
                // Out of scope: sub-select strings (see above) and values holding a
                // backslash — the old builder let MySQL reinterpret `\'` inside a
                // pasted value, the new one binds it literally (test below).
                $json = wp_json_encode($where);
                if (!is_array($where) || empty($where) || strpos($json, 'SELECT') !== false || strpos($json, '\\\\') !== false) {
                    continue;
                }
                $label         = $fixture['call'] . ' ' . substr(md5(wp_json_encode($where)), 0, 8);
                $cases[$label] = array($where);
            }
        }
        return $cases;
    }

    /**
     * The recorded corpus is not empty (guards against a broken fixture path).
     *
     * @return void
     */
    public function test_corpus_has_filox_shapes()
    {
        $labels = array_keys($this->recorded_wheres());
        $this->assertGreaterThan(10, count($labels));
        $this->assertNotEmpty(preg_grep('/filox-class-rates-rest\.php:251/', $labels), 'the multi-group rates WHERE must be in the corpus');
    }

    /**
     * Old and new SQL select the same rows.
     *
     * @dataProvider recorded_wheres
     *
     * @param array $where Recorded WHERE node.
     * @return void
     */
    public function test_new_where_selects_the_same_rows($where)
    {
        global $wpdb;

        $candidates = array();
        $this->collect($where, $candidates);
        $table = 'ewp_compat_' . substr(md5(wp_json_encode($where)), 0, 10);
        $this->create_table($table, array_keys($candidates));
        $this->fill_table($table, $candidates);

        $legacy_sql = 'SELECT _rowkey FROM ' . $wpdb->prefix . $table . ' WHERE ' . AWM_DB_Creator::legacy_where_sql($where);
        $legacy     = $wpdb->get_col($legacy_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- legacy SQL under test.
        $this->assertSame('', $wpdb->last_error, 'old SQL failed: ' . $legacy_sql);

        $built = Sql::select($table, array('_rowkey'), $where, array(), '', 0);
        $this->assertSame(array(), $built['errors'], 'the new builder rejected a recorded WHERE');
        $new = $wpdb->get_col($built['sql']); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by EWP\Db\Sql.

        sort($legacy);
        sort($new);
        $this->assertSame($legacy, $new, "rows differ\nold: {$legacy_sql}\nnew: {$built['sql']}");
    }

    /**
     * Intended difference: a backslash in a value is matched literally. The
     * recorded case is the baseline probe `ewp_status=enabled' OR '1'='1`,
     * which reached the old builder slashed (`enabled\' OR \'1\'=\'1`) and
     * was reinterpreted by MySQL; now it is one literal string.
     *
     * @return void
     */
    public function test_backslashes_in_values_are_literal()
    {
        global $wpdb;
        $table = 'ewp_compat_backslash';
        $this->create_table($table, array('status'));
        $slashed = "enabled\\' OR \\'1\\'=\\'1";
        $wpdb->insert($wpdb->prefix . $table, array('status' => $slashed));
        $wpdb->insert($wpdb->prefix . $table, array('status' => "enabled' OR '1'='1"));
        $wpdb->insert($wpdb->prefix . $table, array('status' => 'enabled'));

        $built = Sql::select($table, array('status'), array('column' => 'status', 'value' => $slashed), array(), '', 0);

        $this->assertSame(array($slashed), $wpdb->get_col($built['sql'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by EWP\Db\Sql.
    }

    /**
     * Collect column => candidate values from a WHERE node.
     *
     * @param mixed $node       Node.
     * @param array $candidates Column => values (filled).
     * @return void
     */
    private function collect($node, array &$candidates)
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['column'])) {
            $column = (string) $node['column'];
            foreach ($this->values_for($node) as $value) {
                $candidates[$column][] = $value;
            }
            return;
        }
        foreach ($node as $child) {
            $this->collect($child, $candidates);
        }
    }

    /**
     * Recorded value of a condition plus neighbours that sit on both sides of it.
     *
     * @param array $condition Condition node.
     * @return string[] Candidate values.
     */
    private function values_for(array $condition)
    {
        $value = isset($condition['value']) ? $condition['value'] : '';
        $items = is_string($value) && strpos(ltrim($value), '(') === 0 ? (array) \EWP\Db\Legacy_In_List::parse($value) : (array) $value;
        $out   = array('zzz-other');
        foreach ($items as $item) {
            $item  = (string) $item;
            $out[] = $item;
            $out[] = str_replace('%', '', $item);
            $out[] = str_replace('%', 'xx', $item);
            if (is_numeric($item)) {
                $out[] = (string) ((int) $item + 1);
                $out[] = (string) ((int) $item - 1);
            }
            $time = strtotime($item);
            if ($time !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $item)) {
                $format = strlen($item) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d';
                $out[]  = gmdate($format, $time - DAY_IN_SECONDS);
                $out[]  = gmdate($format, $time + DAY_IN_SECONDS);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Temporary table with a `_rowkey` and one text column per WHERE column.
     *
     * @param string   $table   Table name without prefix.
     * @param string[] $columns Column names.
     * @return void
     */
    private function create_table($table, array $columns)
    {
        global $wpdb;
        $definitions = array('`_rowkey` int NOT NULL AUTO_INCREMENT PRIMARY KEY');
        foreach ($columns as $column) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $column);
            $definitions[] = '`' . $column . '` varchar(191) NULL';
        }
        $wpdb->query('CREATE TABLE ' . $wpdb->prefix . $table . ' (' . implode(', ', $definitions) . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test fixture, identifiers checked above.
        Table_Columns::forget($table);
    }

    /**
     * Deterministic rows mixing every column's candidates.
     *
     * @param string $table      Table name without prefix.
     * @param array  $candidates Column => values.
     * @return void
     */
    private function fill_table($table, array $candidates)
    {
        global $wpdb;
        $columns = array_keys($candidates);
        for ($i = 0; $i < self::ROWS; $i++) {
            $row = array();
            foreach ($columns as $k => $column) {
                $values       = $candidates[$column];
                $row[$column] = $values[($i * (7 + 2 * $k) + 3 * $k) % count($values)];
            }
            $wpdb->insert($wpdb->prefix . $table, $row);
        }
    }
}
