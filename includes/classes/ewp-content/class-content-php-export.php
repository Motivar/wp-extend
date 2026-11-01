<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-php-printer.php';

/**
 * Exports UI-built content definitions (field groups, post types, taxonomies)
 * as one PHP file that registers them in code.
 *
 * The one implementation behind `method=php` of `GET extend-wp/v1/export`
 * (`ewp/v1/export`), `wp ewp content export --method=php` and the
 * `ewp-content/export` ability, the PHP export of the Import/Export admin
 * screen, and the deprecated `AWM_API::php_code()`.
 *
 * Each content type has a generator (type => callable) that returns the PHP
 * for the rows of that type. Types without a generator are reported as
 * skipped. Add or replace generators with the `ewp_php_export_generators`
 * filter.
 *
 * @package    EWP\Content
 * @author     Motivar
 *
 * @since 1.15.0
 */
final class Content_Php_Export
{
    /** Filter hooked by the generated code of each field group position. */
    const POSITION_FILTERS = [
        'post_type'  => 'awm_add_meta_boxes_filter',
        'ewp_block'  => 'ewp_gutenburg_blocks_filter',
        'taxonomy'   => 'awm_add_term_meta_boxes_filter',
        'customizer' => 'awm_add_customizer_settings_filter',
        'options'    => 'awm_add_options_boxes_filter',
        'user'       => 'awm_add_user_boxes_filter',
    ];

    /**
     * Generate the PHP for the given content types.
     *
     * @param string[] $content_types Content type ids (e.g. ewp_fields, ewp_post_types, ewp_taxonomies).
     *
     * @return array{code: string, skipped: string[]} The PHP file contents (header, ABSPATH guard and a
     *                                                 comment section per type and object) and the types
     *                                                 without a generator.
     */
    public function export(array $content_types)
    {
        $content_types = array_values(array_unique(array_filter(array_map('strval', $content_types))));
        $generators    = $this->generators();
        $printer       = new Php_Printer();
        $sections      = [];
        $skipped       = [];

        foreach ($content_types as $type) {
            if (!isset($generators[$type]) || !is_callable($generators[$type])) {
                $skipped[] = $type;
                continue;
            }
            $code       = trim((string) call_user_func($generators[$type], $printer, $type));
            $sections[] = $code !== '' ? $code : $this->comment_block('Content type ' . $type . ': no rows.');
        }

        return ['code' => $this->header() . implode("\n\n", $sections) . ($sections === [] ? '' : "\n"), 'skipped' => $skipped];
    }

    /**
     * The generators by content type, after the `ewp_php_export_generators` filter.
     *
     * @return array<string, callable> Content type id => callable( Php_Printer $printer, string $type ): string.
     */
    public function generators()
    {
        $generators = [
            'ewp_fields'     => [$this, 'generate_fields'],
            'ewp_post_types' => [$this, 'generate_post_types'],
            'ewp_taxonomies' => [$this, 'generate_taxonomies'],
        ];

        /**
         * Filters the generators that turn the rows of a content type into PHP for the PHP export.
         *
         * A generator receives the printer and the content type id and returns the PHP statements
         * for all the rows of that type (no opening tag), or an empty string when there is nothing
         * to write. Content types without a generator are reported in the export's `skipped` list.
         *
         * @since 1.15.0
         *
         * @param array<string, callable> $generators Content type id => callable( Php_Printer $printer, string $type ): string.
         */
        $generators = apply_filters('ewp_php_export_generators', $generators);

        return is_array($generators) ? $generators : [];
    }

    /**
     * PHP that registers every UI-built field group (ewp_fields).
     *
     * @param Php_Printer $printer Printer.
     * @param string      $type    Content type id (ewp_fields).
     *
     * @return string
     */
    public function generate_fields(Php_Printer $printer, $type = 'ewp_fields')
    {
        $blocks = [];
        foreach ($this->rows($type) as $row) {
            $blocks[] = $this->fields_code($row, awm_get_db_content_meta($type, $row['content_id']), $printer);
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * PHP that registers one field group, one add_filter per position.
     *
     * @param array       $row     ewp_fields row (content_id, content_title, modified, …).
     * @param array       $meta    Row meta (awm_fields, awm_positions, awm_type, awm_explanation).
     * @param Php_Printer $printer Printer.
     *
     * @return string PHP statements, empty when the group has no positions.
     */
    public function fields_code(array $row, array $meta, Php_Printer $printer)
    {
        $positions = isset($meta['awm_positions']) && is_array($meta['awm_positions']) ? $meta['awm_positions'] : [];
        $blocks    = [];
        $counter   = 0;
        foreach ($positions as $position) {
            $case = is_array($position) && isset($position['case']) ? (string) $position['case'] : '';
            if (!isset(self::POSITION_FILTERS[$case])) {
                continue;
            }
            $key         = $row['content_id'] . '_' . $counter;
            $group       = $row;
            $group['fields']      = isset($meta['awm_fields']) ? $meta['awm_fields'] : [];
            $group['position']    = $position;
            $group['type']        = isset($meta['awm_type']) ? $meta['awm_type'] : [];
            $group['explanation'] = isset($meta['awm_explanation']) ? $meta['awm_explanation'] : '';
            $boxes                = awm_create_boxes($case, [$key => $group]);
            if (!empty($boxes)) {
                $blocks[] = $this->add_filter_code(self::POSITION_FILTERS[$case], $boxes, $printer, '+=');
            }
            $counter++;
        }
        if ($blocks === []) {
            return '';
        }
        $title = isset($row['content_title']) ? (string) $row['content_title'] : '';

        return $this->comment_block('Field group: ' . $title . ' (#' . $row['content_id'] . ')') . "\n" . implode("\n\n", $blocks);
    }

    /**
     * PHP that registers every UI-built post type (ewp_post_types) through `ewp_get_post_types_filter`.
     *
     * @param Php_Printer $printer Printer (replaced with one that translates the type's names).
     * @param string      $type    Content type id (ewp_post_types).
     *
     * @return string
     */
    public function generate_post_types(Php_Printer $printer, $type = 'ewp_post_types')
    {
        $printer = new Php_Printer(['sn', 'pl', 'description']);
        $blocks  = [];
        foreach ($this->rows($type) as $row) {
            $definition = $this->post_type_definition($row, awm_get_db_content_meta($type, $row['content_id']));
            if ($definition === []) {
                continue;
            }
            $blocks[] = $this->comment_block('Post type: ' . key($definition) . ' (#' . $row['content_id'] . ')') . "\n"
                . $this->add_filter_code('ewp_get_post_types_filter', $definition, $printer, 'merge');
        }

        return implode("\n\n", $blocks);
    }

    /**
     * PHP that registers every UI-built taxonomy (ewp_taxonomies) through `ewp_get_taxonomies_filter`.
     *
     * @param Php_Printer $printer Printer (replaced with one that translates the taxonomy's names).
     * @param string      $type    Content type id (ewp_taxonomies).
     *
     * @return string
     */
    public function generate_taxonomies(Php_Printer $printer, $type = 'ewp_taxonomies')
    {
        $printer = new Php_Printer(['name', 'label']);
        $blocks  = [];
        foreach ($this->rows($type) as $row) {
            $definition = $this->taxonomy_definition(awm_get_db_content_meta($type, $row['content_id']));
            if ($definition === []) {
                continue;
            }
            $blocks[] = $this->comment_block('Taxonomy: ' . key($definition) . ' (#' . $row['content_id'] . ')') . "\n"
                . $this->add_filter_code('ewp_get_taxonomies_filter', $definition, $printer, 'merge');
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The `ewp_get_post_types_filter` entry of a stored post type, shaped like EWP_WP_Content::get_post_types().
     *
     * @param array $row  ewp_post_types row.
     * @param array $meta Row meta.
     *
     * @return array post type name => definition; empty when the row has no title or name.
     */
    public function post_type_definition(array $row, array $meta)
    {
        if (empty($row['content_title']) || empty($meta['post_name'])) {
            return [];
        }
        $prefix = !empty($meta['prefix']) ? $meta['prefix'] : 'ewp';
        $name   = $prefix . '_' . awm_clean_string(strtolower($meta['post_name']));
        $singular = isset($meta['singular']) ? (string) $meta['singular'] : '';
        $access = ['fullAccess' => ['administrator'], 'semiAccess' => []];
        foreach (array_keys($access) as $level) {
            foreach (isset($meta[$level]) ? (array) $meta[$level] : [] as $role) {
                $access[$level][] = $role;
            }
        }

        return [$name => [
            'post'                    => $name,
            'sn'                      => $singular,
            'pl'                      => isset($meta['plural']) ? (string) $meta['plural'] : '',
            'custom_gallery'          => isset($meta['gallery']) ? $meta['gallery'] : false,
            'args'                    => isset($meta['args']) ? $meta['args'] : false,
            'flx_enable'              => true,
            'slug'                    => $prefix . '_' . awm_clean_string(strtolower($singular)),
            'taxonomies_connected'    => isset($meta['taxonomies']) ? $meta['taxonomies'] : [],
            'meta_boxes_connected'    => isset($meta['meta_boxes']) ? $meta['meta_boxes'] : [],
            'disable_gutenburg'       => isset($meta['disable_gutenburg']) ? $meta['disable_gutenburg'] : false,
            'description'             => isset($meta['description']) ? $meta['description'] : '',
            'hierarchical'            => isset($meta['hierarchical']) ? $meta['hierarchical'] : false,
            'flx_custom_template'     => isset($meta['custom_template']) ? $meta['custom_template'] : false,
            'archive_template_source' => isset($meta['archive_template_source']) ? $meta['archive_template_source'] : '',
            'single_template_source'  => isset($meta['single_template_source']) ? $meta['single_template_source'] : '',
            'inherit_metas'           => isset($meta['inherit_metas']) ? (array) $meta['inherit_metas'] : [],
            'admin_access'            => $access,
        ]];
    }

    /**
     * The `ewp_get_taxonomies_filter` entry of a stored taxonomy, shaped like EWP_WP_Content::get_taxonomies().
     *
     * @param array $meta Row meta of an ewp_taxonomies row.
     *
     * @return array taxonomy name => definition; empty when the row has no taxonomy name.
     */
    public function taxonomy_definition(array $meta)
    {
        if (empty($meta['taxonomy_name'])) {
            return [];
        }
        $prefix = !empty($meta['prefix']) ? $meta['prefix'] : 'ewp';
        $name   = $prefix . '_' . awm_clean_string(strtolower($meta['taxonomy_name']));

        return [$name => [
            'name'              => isset($meta['name']) ? (string) $meta['name'] : '',
            'label'             => isset($meta['label']) ? (string) $meta['label'] : '',
            'custom-slug'       => true,
            'post_types'        => isset($meta['post_types']) ? $meta['post_types'] : [],
            'template'          => isset($meta['template']) ? $meta['template'] : '',
            'template_source'   => isset($meta['template_source']) ? $meta['template_source'] : '',
            'inherit_metas'     => isset($meta['inherit_metas']) ? (array) $meta['inherit_metas'] : [],
            'show_admin_column' => isset($meta['show_admin_column']) ? $meta['show_admin_column'] : false,
        ]];
    }

    /**
     * Rows of a content type in a stable order.
     *
     * @param string $type Content type id.
     *
     * @return array[]
     */
    private function rows($type)
    {
        $rows = awm_get_db_content($type, ['order_by' => ['column' => 'content_id', 'type' => 'asc']]);
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        awm_get_db_content_metas($type, array_column($rows, 'content_id'));

        return $rows;
    }

    /**
     * An add_filter() call that adds the given entries to the filtered array.
     *
     * @param string      $filter  Filter name.
     * @param array       $entries Entries to add.
     * @param Php_Printer $printer Printer.
     * @param string      $mode    `+=` appends (keeps existing keys), `merge` assigns each key.
     *
     * @return string
     */
    private function add_filter_code($filter, array $entries, Php_Printer $printer, $mode)
    {
        $body = '';
        if ($mode === 'merge') {
            foreach ($entries as $key => $entry) {
                $body .= '    $items[' . $printer->quote($key) . '] = ' . $printer->print_value($entry, 1) . ";\n";
            }
        } else {
            $body = '    $items += ' . $printer->print_value($entries, 1) . ";\n";
        }

        return 'add_filter(' . $printer->quote($filter) . ", function (\$items) {\n" . $body . "    return \$items;\n});";
    }

    /**
     * A one-line block comment, safe against `*` + `/` and line breaks in the text.
     *
     * @param string $text Comment text.
     *
     * @return string
     */
    private function comment_block($text)
    {
        $text = trim(preg_replace('/\s+/', ' ', str_replace('*/', '* /', $text)));

        return '/* ' . $text . ' */';
    }

    /**
     * The file header: opening tag, description and the ABSPATH guard.
     *
     * @return string
     */
    private function header()
    {
        return "<?php\n/**\n * Extend WP content export (PHP).\n *\n * Registers the exported field groups, post types and taxonomies in code.\n */\n\n"
            . "if (!defined('ABSPATH')) {\n    exit;\n}\n\n";
    }
}
