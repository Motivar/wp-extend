<?php
/**
 * EWP\Db\Where and EWP\Db\Legacy_In_List in isolation: exact SQL fragments
 * and bind values for every node shape, read-mode containment, strict-mode
 * exceptions and adversarial input. No table needed.
 */

use EWP\Db\Invalid_Where;
use EWP\Db\Legacy_In_List;
use EWP\Db\Where;

class Test_Db_Where extends WP_UnitTestCase
{
    /** Columns of the imaginary table. */
    const COLUMNS = array('id' => 'id', 'status' => 'status', 'title' => 'title', 'post_id' => 'post_id', 'start_date' => 'start_date', 'deleted' => 'deleted');

    /**
     * Build in read mode.
     *
     * @param mixed $node    WHERE node.
     * @param array $options Extra options.
     * @return array Output of Where::build().
     */
    private function read($node, array $options = array())
    {
        return Where::build($node, self::COLUMNS, $options + array('prefix' => 'wp_'));
    }

    /**
     * One condition, typed placeholders, column bound first.
     *
     * @return void
     */
    public function test_single_conditions_bind_column_then_value()
    {
        $this->assertSame(array('sql' => '%i = %d', 'values' => array('id', 5), 'errors' => array()), $this->read(array('column' => 'id', 'value' => 5)));
        $this->assertSame(array('%i LIKE %s', array('title', '%a%')), array_slice(array_values($this->read(array('column' => 'title', 'compare' => 'like', 'value' => '%a%'))), 0, 2));
        $this->assertSame(array('%i >= %f', array('id', 1.5)), array_slice(array_values($this->read(array('column' => 'id', 'compare' => '>=', 'value' => 1.5))), 0, 2));
        $this->assertSame(array('%i = %d', array('deleted', 1)), array_slice(array_values($this->read(array('column' => 'deleted', 'value' => true))), 0, 2));
    }

    /**
     * Groups nest with parentheses; the legacy root shape works.
     *
     * @return void
     */
    public function test_groups_and_legacy_root_shape()
    {
        $node = array(
            'clause' => array(
                array('operator' => 'AND', 'clause' => array(
                    array('column' => 'post_id', 'value' => 29, 'compare' => '='),
                    array('column' => 'status', 'value' => 'available', 'compare' => '='),
                )),
                array('operator' => 'OR', 'clause' => array(
                    array('column' => 'start_date', 'value' => '2026-12-20', 'compare' => '>='),
                    array('column' => 'start_date', 'value' => '2026-12-22', 'compare' => '<='),
                )),
            ),
        );
        $built = $this->read($node);

        $this->assertSame('((%i = %d AND %i = %s) AND (%i >= %s OR %i <= %s))', $built['sql']);
        $this->assertSame(array('post_id', 29, 'status', 'available', 'start_date', '2026-12-20', 'start_date', '2026-12-22'), $built['values']);
    }

    /**
     * A plain list is an AND group; one child needs no parentheses; empty groups vanish.
     *
     * @return void
     */
    public function test_lists_single_children_and_empty_groups()
    {
        $this->assertSame('(%i = %d AND %i = %s)', $this->read(array(array('column' => 'id', 'value' => 1), array('column' => 'status', 'value' => 'a')))['sql']);
        $this->assertSame('%i = %d', $this->read(array('clause' => array(array('clause' => array(array('column' => 'id', 'value' => 1))))))['sql']);
        $this->assertSame('', $this->read(array('clause' => array(array('clause' => array()))))['sql']);
        $this->assertSame('', $this->read('')['sql']);
    }

    /**
     * IN from arrays and legacy strings, empty lists, NOT IN.
     *
     * @return void
     */
    public function test_in_lists()
    {
        $this->assertSame(array('%i IN (%s, %d)', array('status', 'a', 3)), array_slice(array_values($this->read(array('column' => 'status', 'compare' => 'IN', 'value' => array('a', 3)))), 0, 2));
        $this->assertSame(array('%i IN (%s, %s)', array('status', 'a', "o'b")), array_slice(array_values($this->read(array('column' => 'status', 'compare' => 'IN', 'value' => "('a','o\\'b')"))), 0, 2));
        $this->assertSame('1=0', $this->read(array('column' => 'status', 'compare' => 'IN', 'value' => array()))['sql']);
        $this->assertSame('1=1', $this->read(array('column' => 'status', 'compare' => 'NOT IN', 'value' => '()'))['sql']);
    }

    /**
     * IS NULL, BETWEEN.
     *
     * @return void
     */
    public function test_null_and_between()
    {
        $this->assertSame(array('%i IS NOT NULL', array('title')), array_slice(array_values($this->read(array('column' => 'title', 'compare' => 'is not null'))), 0, 2));
        $this->assertSame(array('%i BETWEEN %s AND %s', array('start_date', 'a', 'b')), array_slice(array_values($this->read(array('column' => 'start_date', 'compare' => 'BETWEEN', 'value' => array('a', 'b')))), 0, 2));
    }

    /**
     * Sub-queries resolve the other table's columns and bind in order.
     *
     * @return void
     */
    public function test_subquery()
    {
        $node = array('column' => 'id', 'compare' => 'IN', 'subquery' => array(
            'table' => 'type_data',
            'select' => 'content_id',
            'where' => array('column' => 'meta_value', 'compare' => 'LIKE', 'value' => '%x%'),
        ));
        $built = $this->read($node, array('columns_of' => function ($table) {
            return $table === 'type_data' ? array('content_id' => 'content_id', 'meta_value' => 'meta_value') : array();
        }));

        $this->assertSame('%i IN (SELECT %i FROM %i WHERE %i LIKE %s)', $built['sql']);
        $this->assertSame(array('id', 'content_id', 'wp_type_data', 'meta_value', '%x%'), $built['values']);
    }

    /**
     * Read mode: every invalid condition becomes 1=0, never a wider filter.
     *
     * @dataProvider invalid_conditions
     *
     * @param array $node Invalid condition.
     * @return void
     */
    public function test_invalid_conditions_match_nothing_in_read_mode($node)
    {
        $built = $this->read(array('operator' => 'AND', 'clause' => array(array('column' => 'id', 'value' => 1), $node)));

        $this->assertSame('(%i = %d AND 1=0)', $built['sql']);
        $this->assertCount(1, $built['errors']);
    }

    /**
     * Strict mode: the same input throws.
     *
     * @dataProvider invalid_conditions
     *
     * @param array $node Invalid condition.
     * @return void
     */
    public function test_invalid_conditions_throw_in_strict_mode($node)
    {
        $this->expectException(Invalid_Where::class);
        Where::build(array($node), self::COLUMNS, array('strict' => true));
    }

    /**
     * Invalid or hostile conditions.
     *
     * @return array[] Cases.
     */
    public function invalid_conditions()
    {
        return array(
            'unknown column'           => array(array('column' => 'password', 'value' => 1)),
            'column injection'         => array(array('column' => 'id = 1 OR 1', 'value' => 1)),
            'qualified column'         => array(array('column' => 'wp_users.user_pass', 'value' => 1)),
            'compare injection'        => array(array('column' => 'id', 'compare' => '= 1 OR 1 =', 'value' => 1)),
            'unknown compare'          => array(array('column' => 'id', 'compare' => 'REGEXP', 'value' => '.*')),
            'array for scalar compare' => array(array('column' => 'id', 'compare' => '=', 'value' => array(1))),
            'null value'               => array(array('column' => 'id', 'compare' => '=', 'value' => null)),
            'IN legacy injection'      => array(array('column' => 'status', 'compare' => 'IN', 'value' => "('x') OR '1'='1'")),
            'IN closing paren'         => array(array('column' => 'status', 'compare' => 'IN', 'value' => "('x'),(SELECT user_pass FROM wp_users)")),
            'IN raw sub-select'        => array(array('column' => 'status', 'compare' => 'IN', 'value' => '(SELECT content_id FROM wp_x)')),
            'IN nested arrays'         => array(array('column' => 'status', 'compare' => 'IN', 'value' => array(array('a')))),
            'BETWEEN one value'        => array(array('column' => 'id', 'compare' => 'BETWEEN', 'value' => array(1))),
            'subquery bad table'       => array(array('column' => 'id', 'compare' => 'IN', 'subquery' => array('table' => 'x; DROP', 'select' => 'id'))),
            'no column or clause'      => array(array('value' => 1)),
        );
    }

    /**
     * Unknown group operators fail like conditions.
     *
     * @return void
     */
    public function test_operator_allow_list()
    {
        $this->assertSame('1=0', $this->read(array('operator' => 'OR 1=1 OR', 'clause' => array(array('column' => 'id', 'value' => 1))))['sql']);
        $this->assertSame('(%i = %d OR %i = %d)', $this->read(array('operator' => 'or', 'clause' => array(array('column' => 'id', 'value' => 1), array('column' => 'id', 'value' => 2))))['sql']);
    }

    /**
     * Hostile nesting depth and size are capped.
     *
     * @return void
     */
    public function test_depth_and_size_caps()
    {
        $node = array('column' => 'id', 'value' => 1);
        for ($i = 0; $i < Where::MAX_DEPTH + 5; $i++) {
            $node = array('clause' => array($node));
        }
        $this->assertSame('1=0', $this->read($node)['sql']);

        $many  = array_fill(0, Where::MAX_CONDITIONS + 1, array('column' => 'id', 'value' => 1));
        $built = $this->read($many);
        $this->assertStringEndsWith('AND 1=0)', $built['sql']);
        $this->assertNotEmpty($built['errors']);
    }

    /**
     * Legacy IN lists the parser accepts.
     *
     * @dataProvider legacy_lists
     *
     * @param string $list     Input.
     * @param array  $expected Parsed values.
     * @return void
     */
    public function test_legacy_in_list_parses($list, array $expected)
    {
        $this->assertSame($expected, Legacy_In_List::parse($list));
    }

    /**
     * Valid legacy lists.
     *
     * @return array[] Input and values.
     */
    public function legacy_lists()
    {
        return array(
            'quoted strings'       => array("('a','b')", array('a', 'b')),
            'spaces'               => array(" ( 'a' , 'b' ) ", array('a', 'b')),
            'numbers'              => array('(1, 2, -3, 4.5)', array(1, 2, -3, 4.5)),
            'quoted numbers'       => array("('12','13')", array('12', '13')),
            'double quotes'        => array('("a","b")', array('a', 'b')),
            'escaped quote'        => array("('o\\'brien')", array("o'brien")),
            'doubled quote'        => array("('o''brien')", array("o'brien")),
            'comma inside quotes'  => array("('a,b','c')", array('a,b', 'c')),
            'paren inside quotes'  => array("('a)','b')", array('a)', 'b')),
            'empty'                => array('()', array()),
            'empty string item'    => array("('')", array('')),
        );
    }

    /**
     * Strings that are not plain lists are refused.
     *
     * @dataProvider not_lists
     *
     * @param mixed $input Input.
     * @return void
     */
    public function test_legacy_in_list_refuses($input)
    {
        $this->assertNull(Legacy_In_List::parse($input));
    }

    /**
     * Invalid or hostile legacy lists.
     *
     * @return array[] Inputs.
     */
    public function not_lists()
    {
        return array(
            'no parens'           => array("'a','b'"),
            'or injection'        => array("('a') OR '1'='1'"),
            'or inside'           => array("('a' OR 1=1)"),
            'sub-select'          => array('(SELECT id FROM wp_users)'),
            'second list'         => array("('a'),('b')"),
            'comment'             => array("('a') -- "),
            'unterminated quote'  => array("('a)"),
            'bare word'           => array('(abc)'),
            'trailing comma'      => array("('a',)"),
            'not a string'        => array(array('a')),
        );
    }
}
