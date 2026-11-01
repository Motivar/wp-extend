<?php
/**
 * PHP export of content definitions: the printer and the generators of
 * EWP\Content\Content_Php_Export (since 1.15.0).
 */

use EWP\Content\Content_Php_Export;
use EWP\Content\Php_Printer;

class Test_Content_Php_Export extends WP_UnitTestCase
{
    /**
     * Parse code with the PHP tokenizer; throws ParseError when it is not valid PHP.
     *
     * @param string $code PHP source.
     *
     * @return void
     */
    private function assertValidPhp($code)
    {
        $this->assertNotEmpty(token_get_all($code, TOKEN_PARSE));
    }

    /**
     * Quotes, backslashes and a trailing backslash survive a round trip.
     *
     * @return void
     */
    public function test_printer_escapes_strings()
    {
        $printer = new Php_Printer();
        $values  = ["It's", 'say "hi"', 'back\\slash', 'ends with \\', "quote\\'mix", '$notvar {$x}', "multi\nline", '?> <?php exit;'];
        foreach ($values as $value) {
            $code = $printer->print_value($value);
            $this->assertValidPhp('<?php return ' . $code . ';');
            $this->assertSame($value, eval('return ' . $code . ';'));
        }
    }

    /**
     * Native scalars, nested arrays and keys round trip; lists print without keys.
     *
     * @return void
     */
    public function test_printer_round_trips_arrays()
    {
        $printer = new Php_Printer();
        $data    = ['a' => 1, 'b' => true, 'c' => null, 'd' => 1.5, 'it\'s' => ['x', 'y'], 'e' => [], 'f' => [5 => 'five', 'k' => ['n' => false]]];
        $code    = $printer->print_value($data);
        $this->assertValidPhp('<?php return ' . $code . ';');
        $this->assertSame($data, eval('return ' . $code . ';'));
        $this->assertStringContainsString("'x',", $printer->print_array(['x', 'y']));
        $this->assertStringNotContainsString('0 =>', $printer->print_array(['x', 'y']));
    }

    /**
     * Same input, same output; indentation is stable.
     *
     * @return void
     */
    public function test_printer_is_deterministic_and_indented()
    {
        $printer = new Php_Printer();
        $data    = ['a' => ['b' => 1]];
        $this->assertSame($printer->print_value($data), $printer->print_value($data));
        $this->assertSame("[\n    'a' => [\n        'b' => 1\n    ]\n]", $printer->print_value($data));
    }

    /**
     * Translatable keys wrap string values only; the keys are configurable.
     *
     * @return void
     */
    public function test_printer_wraps_translatable_strings()
    {
        $printer = new Php_Printer();
        $code    = $printer->print_value(['label' => "Owner's", 'title' => '', 'explanation' => 'Help', 'other' => 'Plain', 'case' => ['label' => 3]]);
        $this->assertStringContainsString("'label' => __('Owner\\'s', 'extend-wp')", $code);
        $this->assertStringContainsString("'title' => ''", $code);
        $this->assertStringContainsString("'explanation' => __('Help', 'extend-wp')", $code);
        $this->assertStringContainsString("'other' => 'Plain'", $code);
        $this->assertStringContainsString("'label' => 3", $code);

        $custom = new Php_Printer(['other'], 'my-domain');
        $this->assertSame("__('Plain', 'my-domain')", $custom->print_value(['other' => 'Plain']) === '' ? '' : trim(explode('=>', $custom->print_value(['other' => 'Plain']))[1], " \n]"));
    }

    /**
     * The legacy awm_print_php() wrapper now escapes and returns the entries only.
     *
     * @return void
     */
    public function test_legacy_awm_print_php_wrapper_escapes()
    {
        $out = awm_print_php(['label' => "It's"]);
        $this->assertValidPhp('<?php return [' . $out . '];');
        $this->assertStringContainsString("\\'", $out);
    }

    /**
     * A field group with two positions becomes one valid add_filter per position, labels escaped.
     *
     * @return void
     */
    public function test_fields_generator_prints_one_filter_per_position()
    {
        $row  = ['content_id' => 7, 'content_title' => "Owner's box", 'modified' => '2026-01-01 00:00:00'];
        $meta = [
            'awm_fields'    => [['key' => 'owner_name', 'label' => "Owner's \"name\"", 'case' => 'input', 'type' => 'text']],
            'awm_positions' => [['case' => 'post_type', 'post_types' => ['post']], ['case' => 'user'], ['case' => 'bogus']],
            'awm_type'      => 'post',
        ];
        $code = (new Content_Php_Export())->fields_code($row, $meta, new Php_Printer());

        $this->assertValidPhp("<?php\n" . $code);
        $this->assertSame(1, substr_count($code, "add_filter('awm_add_meta_boxes_filter'"));
        $this->assertSame(1, substr_count($code, "add_filter('awm_add_user_boxes_filter'"));
        $this->assertStringNotContainsString('bogus', $code);
        $this->assertStringContainsString("'owner_name'", $code);
        $this->assertStringContainsString("__('Owner\\'s \"name\"', 'extend-wp')", $code);
        $this->assertStringContainsString("Owner's box", $code);
    }

    /**
     * A stored post type and taxonomy print the shape the registering filters expect.
     *
     * @return void
     */
    public function test_post_type_and_taxonomy_definitions()
    {
        $export = new Content_Php_Export();
        $type   = $export->post_type_definition(
            ['content_title' => 'Book'],
            ['post_name' => 'Book', 'singular' => "Reader's book", 'plural' => 'Books', 'taxonomies' => ['ewp_genre'], 'fullAccess' => ['editor']]
        );
        $this->assertSame(['ewp_book'], array_keys($type));
        $this->assertSame(['administrator', 'editor'], $type['ewp_book']['admin_access']['fullAccess']);
        $this->assertSame([], $export->post_type_definition(['content_title' => ''], ['post_name' => 'x']));

        $tax = $export->taxonomy_definition(['taxonomy_name' => 'Genre', 'name' => 'Genre', 'label' => 'Genres', 'post_types' => ['ewp_book']]);
        $this->assertSame(['ewp_genre'], array_keys($tax));
        $this->assertSame(['ewp_book'], $tax['ewp_genre']['post_types']);
        $this->assertSame([], $export->taxonomy_definition([]));
    }

    /**
     * export() returns a guarded, valid PHP file and lists the types without a generator.
     *
     * @return void
     */
    public function test_export_skips_types_without_generator()
    {
        $result = (new Content_Php_Export())->export(['ewp_fields', 'ewp_search', 'ewp_post_types', 'ewp_taxonomies', 'unknown_type']);

        $this->assertSame(['ewp_search', 'unknown_type'], $result['skipped']);
        $this->assertStringStartsWith('<?php', $result['code']);
        $this->assertStringContainsString("if (!defined('ABSPATH'))", $result['code']);
        $this->assertValidPhp($result['code']);
    }

    /**
     * The generators filter adds a type and replaces a built-in one.
     *
     * @return void
     */
    public function test_generators_filter()
    {
        add_filter('ewp_php_export_generators', function ($generators) {
            $generators['my_type']       = function (Php_Printer $printer, $type) {
                return '/* custom ' . $type . ' */' . "\n" . '$my = ' . $printer->print_value(['label' => "A'b"]) . ';';
            };
            $generators['ewp_post_types'] = function () {
                return '';
            };
            return $generators;
        });

        $result = (new Content_Php_Export())->export(['my_type', 'ewp_post_types']);

        $this->assertSame([], $result['skipped']);
        $this->assertStringContainsString('/* custom my_type */', $result['code']);
        $this->assertStringContainsString('no rows', $result['code']);
        $this->assertValidPhp($result['code']);
    }
}
