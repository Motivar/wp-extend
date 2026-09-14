<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The wp-admin field-builder and modal helper routes (REST-only, generated
 * from Field_Builder_Resource) must keep answering the admin scripts.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Field_Builder_Case extends Case_Base
{
    /** Option the modal round-trip writes to. */
    const OPTION = 'ewp_self_test_modal';

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('REST: GET /extend-wp/v1/get-case-fields?field=input — expect 200 and the input-type select markup.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/get-query-fields, /get-position-fields?position=post_type — expect 200 and markup.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/get-php-code?awm_post_id=0 — expect 200 and an empty string for an unknown row.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/awm-map-options — expect 200 and the lat/lng defaults.', 'extend-wp'),
            __('REST: inject a modal definition via awm_modal_field_definition_lookup, GET /modal-fields (option view) — expect modal_html; POST /modal-save — expect the option written; delete it in cleanup.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $o = [];

        $o['case']     = $this->rest('GET', '/extend-wp/v1/get-case-fields', ['field' => 'input', 'name' => 'awm_fields[0][case]', 'meta' => 'awm_fields', 'id' => 0]);
        $o['query']    = $this->rest('GET', '/extend-wp/v1/get-query-fields', ['field' => 'post_type', 'name' => 'query_fields[0][query_type]', 'meta' => 'query_fields', 'id' => 0]);
        $o['position'] = $this->rest('GET', '/extend-wp/v1/get-position-fields', ['position' => 'post_type', 'name' => 'awm_positions[0][case]', 'id' => 0]);
        $o['php']      = $this->rest('GET', '/extend-wp/v1/get-php-code', ['awm_post_id' => 0]);
        $o['map']      = $this->rest('GET', '/extend-wp/v1/awm-map-options');

        $definition = function ($found, $meta_key) {
            return $meta_key === self::OPTION
                ? ['note' => ['label' => 'Note', 'case' => 'input', 'type' => 'text']]
                : $found;
        };
        add_filter('awm_modal_field_definition_lookup', $definition, 10, 2);

        $o['modal']  = $this->rest('GET', '/extend-wp/v1/modal-fields', ['meta_key' => self::OPTION, 'view' => 'option', 'modal_title' => 'Self test']);
        $o['save']   = $this->rest('POST', '/extend-wp/v1/modal-save', ['meta_key' => self::OPTION, 'view' => 'option', 'values' => ['note' => 'hello']]);
        $o['stored'] = get_option(self::OPTION);

        remove_filter('awm_modal_field_definition_lookup', $definition, 10);

        return ['observed' => $o];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o = $context['observed'];

        return [
            $this->check('rest', 'GET /get-case-fields returns the input-type markup', $this->status($o, 'case') === 200 && is_string($o['case']['data']) && strpos($o['case']['data'], 'awm_fields') !== false, $this->detail($o, 'case')),
            $this->check('rest', 'GET /get-query-fields returns 200', $this->status($o, 'query') === 200 && is_string($o['query']['data']), $this->detail($o, 'query')),
            $this->check('rest', 'GET /get-position-fields returns the post-type markup', $this->status($o, 'position') === 200 && strpos((string) $o['position']['data'], 'awm_positions') !== false, $this->detail($o, 'position')),
            $this->check('rest', 'GET /get-php-code returns an empty string for an unknown row', $this->status($o, 'php') === 200 && $o['php']['data'] === '', $this->detail($o, 'php')),
            $this->check('rest', 'GET /awm-map-options returns the map defaults', $this->status($o, 'map') === 200 && isset($o['map']['data']['lat']), $this->detail($o, 'map')),
            $this->check('rest', 'GET /modal-fields renders the injected modal', $this->status($o, 'modal') === 200 && !empty($o['modal']['data']['modal_html']), $this->detail($o, 'modal')),
            $this->check('rest', 'POST /modal-save stores the option', $this->status($o, 'save') === 200 && isset($o['stored']['note']) && $o['stored']['note'] === 'hello', $this->detail($o, 'save')),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        delete_option(self::OPTION);

        return [sprintf(__('Deleted option %s.', 'extend-wp'), self::OPTION)];
    }
}
