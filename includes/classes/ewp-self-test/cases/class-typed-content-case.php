<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The typed content abilities: a field group, a post type and a taxonomy
 * each go through list → create → get → update → delete on their own
 * `ewp-fields/*` and `ewp-wp-content/*` abilities, plus the vocabulary
 * and search-filter reads. Rows are written to the real definition
 * tables and removed again in the same run; cleanup catches leftovers.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Typed_Content_Case extends Case_Base
{
    /** Title prefix every row this case writes carries, so cleanup can find it. */
    const MARKER = 'Self-test typed ';

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Ability: ewp-fields/list-field-vocabulary — expect the valid field cases and position cases.', 'extend-wp'),
            __('Ability: ewp-fields/create-field-group with one text field positioned on posts, then get, update (title) and delete it (confirm: true).', 'extend-wp'),
            __('Ability: ewp-wp-content/create-post-type "selftest_pt", then get, update and delete it.', 'extend-wp'),
            __('Ability: ewp-wp-content/create-taxonomy "selftest_tax" on posts, then get, update and delete it.', 'extend-wp'),
            __('Ability: ewp-fields/list-field-groups, ewp-wp-content/list-post-types, list-taxonomies, ewp-search/list-filters — expect 200-style payloads.', 'extend-wp'),
            __('Cleanup: delete any row whose title starts with the self-test marker.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $o = [];

        $o['vocab'] = $this->ability('ewp-fields/list-field-vocabulary');

        $o['field'] = $this->cycle('ewp-fields', 'field-group', [
            'title'         => self::MARKER . 'field group',
            'awm_fields'    => [['key' => 'selftest_text', 'label' => 'Self-test text', 'case' => 'input', 'type' => 'text']],
            'awm_positions' => [['case' => 'post_type', 'post_types' => ['post'], 'context' => 'normal', 'priority' => 'high']],
            'awm_type'      => 'simple_use',
        ]);

        $o['post_type'] = $this->cycle('ewp-wp-content', 'post-type', [
            'title'     => self::MARKER . 'post type',
            'post_name' => 'selftest_pt',
            'plural'    => 'Self-test items',
            'singular'  => 'Self-test item',
            'prefix'    => 'ewp',
        ]);

        $o['taxonomy'] = $this->cycle('ewp-wp-content', 'taxonomy', [
            'title'         => self::MARKER . 'taxonomy',
            'taxonomy_name' => 'selftest_tax',
            'name'          => 'Self-test terms',
            'label'         => 'Self-test term',
            'prefix'        => 'ewp',
            'post_types'    => ['post'],
        ]);

        $o['list_fields']     = $this->ability('ewp-fields/list-field-groups', ['limit' => 5]);
        $o['list_post_types'] = $this->ability('ewp-wp-content/list-post-types', ['limit' => 5]);
        $o['list_taxonomies'] = $this->ability('ewp-wp-content/list-taxonomies', ['limit' => 5]);
        $o['list_filters']    = $this->ability('ewp-search/list-filters', ['limit' => 5]);

        return ['observed' => $o];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        $checks[] = $this->ability_check($o, 'vocab', 'ewp-fields/list-field-vocabulary lists field and position cases', function ($data) {
            return !empty($data['field_cases']) && !empty($data['position_cases']);
        });

        foreach (['field' => 'ewp-fields/*-field-group', 'post_type' => 'ewp-wp-content/*-post-type', 'taxonomy' => 'ewp-wp-content/*-taxonomy'] as $key => $label) {
            $checks = array_merge($checks, $this->cycle_checks($o[$key], $label));
        }

        foreach (['list_fields' => 'ewp-fields/list-field-groups', 'list_post_types' => 'ewp-wp-content/list-post-types', 'list_taxonomies' => 'ewp-wp-content/list-taxonomies', 'list_filters' => 'ewp-search/list-filters'] as $key => $ability) {
            $checks[] = $this->ability_check($o, $key, $ability . ' returns a row collection', function ($data) {
                return isset($data['count']) && isset($data['items']) && is_array($data['items']);
            });
        }

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        $messages = [];
        $service  = new \EWP\Content\Content_Service();

        foreach (['ewp_fields', 'ewp_post_types', 'ewp_taxonomies'] as $type) {
            $rows = $service->list_items($type, ['search' => self::MARKER, 'limit' => 50]);
            $ids  = [];
            foreach ((array) $rows['items'] as $row) {
                if (strpos((string) $row['title'], self::MARKER) === 0) {
                    $ids[] = (int) $row['id'];
                }
            }
            if ($ids !== []) {
                $service->delete_items($type, $ids);
                $messages[] = sprintf(__('Deleted %1$d leftover row(s) from %2$s.', 'extend-wp'), count($ids), $type);
            }
        }

        return $messages ?: [__('Nothing to clean.', 'extend-wp')];
    }

    /**
     * create → get → update → delete on one entity's abilities.
     *
     * @param string $category Ability category.
     * @param string $singular Entity slug in ability names.
     * @param array  $input    Create input (flat meta).
     *
     * @return array create, get, update, delete results.
     */
    private function cycle($category, $singular, array $input)
    {
        $r  = ['create' => $this->ability($category . '/create-' . $singular, $input)];
        $id = !empty($r['create']['ok']) && !empty($r['create']['data']['id']) ? (int) $r['create']['data']['id'] : 0;

        $r['get']    = $id ? $this->ability($category . '/get-' . $singular, ['id' => $id]) : null;
        $r['update'] = $id ? $this->ability($category . '/update-' . $singular, ['id' => $id, 'title' => $input['title'] . ' (updated)']) : null;
        $r['delete'] = $id ? $this->ability($category . '/delete-' . $singular, ['ids' => [$id], 'confirm' => true]) : null;

        return $r;
    }

    /**
     * @param array  $r     Cycle results.
     * @param string $label Ability family label.
     *
     * @return array Checks.
     */
    private function cycle_checks(array $r, $label)
    {
        return [
            $this->ability_check($r, 'create', $label . ': create returns a row with an id', function ($data) {
                return !empty($data['id']);
            }),
            $this->ability_check($r, 'get', $label . ': get returns the row', function ($data) {
                return !empty($data['id']) && !empty($data['meta']);
            }),
            $this->ability_check($r, 'update', $label . ': update changes the title', function ($data) {
                return isset($data['title']) && substr($data['title'], -9) === '(updated)';
            }),
            $this->ability_check($r, 'delete', $label . ': delete (confirm: true) removes the row', function ($data) {
                return isset($data['count']) && $data['count'] === 1;
            }),
        ];
    }
}
