<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * End-to-end check of the field engine on the real site, in place of
 * clicking through an edit screen: render a post meta box covering every
 * major field case, submit the form the way a browser does (enabled
 * controls only, checked boxes, selected options), edit some values, save
 * through EWP_Meta_Save_Guard (the save_post path, with a real nonce), then
 * compare the stored meta and the re-rendered form with what was submitted.
 *
 * It does not run the admin JavaScript (repeater cloning, tab switching).
 *
 * @package EWP\SelfTest
 * @since   1.11.0
 */
class Field_Engine_Case extends Case_Base
{
    /** Meta box id registered for the run. */
    const BOX = 'ewp_self_test_fields';

    /** Prefix of every meta key the case writes. */
    const PREFIX = 'ewp_st_';

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Create a draft post and register a meta box (input text/number/checkbox/hidden/password with encryption, textarea, select multiple, radio, checkbox_multiple, map, section, awm_tab, repeater) for it.', 'extend-wp'),
            __('Render the box with awm_show_content(), submit it like a browser (edited values), save through EWP_Meta_Save_Guard with the post nonce.', 'extend-wp'),
            __('Expect every posted key stored as submitted (the encrypted one encrypted, unchecked checkbox deleted, repeater template row not saved), and the re-rendered form to submit the same values.', 'extend-wp'),
            __('Cleanup: delete the post and its meta.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $post_id = wp_insert_post(['post_title' => 'EWP field engine self-test', 'post_status' => 'draft', 'post_type' => 'post']);
        if (!$post_id || is_wp_error($post_id)) {
            return ['observed' => ['error' => 'post not created']];
        }
        foreach (self::seed() as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $register = function ($boxes) {
            $boxes[self::BOX] = ['title' => 'Self-test', 'postTypes' => ['post'], 'context' => 'normal', 'library' => self::library()];
            return $boxes;
        };
        add_filter('awm_add_meta_boxes_filter', $register, PHP_INT_MAX - 1);

        $first     = (string) awm_show_content(self::library(), $post_id, 'post');
        $submitted = self::edit(self::submit($first));

        /*a browser POST fills both superglobals; the guard reads the nonce from $_REQUEST*/
        $previous = [$_POST, $_REQUEST];
        $_POST    = wp_slash($submitted + ['_wpnonce' => wp_create_nonce('update-post_' . $post_id)]);
        $_REQUEST = $_POST;
        $saved_keys = \EWP_Meta_Save_Guard::save('post', $post_id, 'post');
        list($_POST, $_REQUEST) = $previous;

        $stored = [];
        foreach ((array) $submitted['awm_custom_meta'] as $key) {
            $stored[$key] = get_post_meta($post_id, $key, true);
        }
        $second = (string) awm_show_content(self::library(), $post_id, 'post');
        remove_filter('awm_add_meta_boxes_filter', $register, PHP_INT_MAX - 1);

        return [
            'post_id'  => $post_id,
            'observed' => [
                'first'      => $first,
                'submitted'  => $submitted,
                'saved_keys' => $saved_keys,
                'stored'     => $stored,
                'resubmit'   => self::submit($second),
            ],
        ];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o = $context['observed'];
        if (isset($o['error'])) {
            return [$this->check('core', 'Fixture post created', false, $o['error'])];
        }
        $s      = $o['submitted'];
        $p      = self::PREFIX;
        $stored = $o['stored'];
        $secret = isset($stored[$p . 'secret']) ? (string) $stored[$p . 'secret'] : '';

        return [
            $this->check('core', 'Every field case renders (one container, all inputs named)', self::renders_all($o['first']), ''),
            $this->check('core', 'The save guard accepted every posted key', count($o['saved_keys']) === count((array) $s['awm_custom_meta']) && !empty($o['saved_keys']), implode(', ', (array) $o['saved_keys'])),
            $this->check('core', 'Plain values are stored as submitted (text, number, textarea, radio, hidden)', self::stored_as_submitted($stored, $s, ['text', 'number', 'notes', 'choice', 'hidden']), wp_json_encode(array_intersect_key($stored, array_flip([$p . 'text', $p . 'number'])))),
            $this->check('core', 'An unchecked checkbox deletes its meta', '' === $stored[$p . 'flag'] && !isset($s[$p . 'flag']), (string) $stored[$p . 'flag']),
            $this->check('core', 'Multi-value fields are stored as arrays (select multiple, checkbox_multiple)', self::stored_as_submitted($stored, $s, ['multi', 'boxes']), wp_json_encode([$stored[$p . 'multi'], $stored[$p . 'boxes']])),
            $this->check('core', 'Nested fields are stored as one array (section, awm_tab, map)', self::stored_as_submitted($stored, $s, ['section', 'tabs', 'map']), wp_json_encode($stored[$p . 'tabs'])),
            $this->check('core', 'Repeater rows are stored without the template row', self::stored_as_submitted($stored, $s, ['rows']) && !isset($s[$p . 'rows']['template']) && count((array) $stored[$p . 'rows']) === 2, wp_json_encode($stored[$p . 'rows'])),
            $this->check('core', 'The encrypted field is stored encrypted and decrypts to the submitted value', \EWP_Encryption::is_encrypted($secret) && \EWP_Encryption::decrypt($secret) === $s[$p . 'secret'], substr($secret, 0, 12)),
            $this->check('core', 'The re-rendered form submits the same values (encrypted field masked)', self::same_resubmit($s, $o['resubmit']), wp_json_encode(self::diff_keys($s, $o['resubmit']))),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        if (empty($context['post_id'])) {
            return [];
        }
        wp_delete_post((int) $context['post_id'], true);

        return [sprintf(__('Deleted post %d and its meta.', 'extend-wp'), (int) $context['post_id'])];
    }

    /**
     * The meta box library: one field per major case.
     *
     * @return array
     */
    private static function library()
    {
        $p       = self::PREFIX;
        $options = ['a' => ['label' => 'A'], 'b' => ['label' => 'B'], 'c' => ['label' => 'C']];
        $inner   = ['inner_text' => ['case' => 'input', 'type' => 'text', 'label' => 'Inner']];

        return [
            $p . 'text'    => ['case' => 'input', 'type' => 'text', 'label' => 'Text'],
            $p . 'number'  => ['case' => 'input', 'type' => 'number', 'label' => 'Number'],
            $p . 'flag'    => ['case' => 'input', 'type' => 'checkbox', 'label' => 'Flag'],
            $p . 'hidden'  => ['case' => 'input', 'type' => 'hidden', 'label' => 'Hidden'],
            $p . 'secret'  => ['case' => 'input', 'type' => 'password', 'label' => 'Secret', 'encrypt' => true],
            $p . 'notes'   => ['case' => 'textarea', 'label' => 'Notes'],
            $p . 'multi'   => ['case' => 'select', 'label' => 'Multi', 'options' => $options, 'attributes' => ['multiple' => true]],
            $p . 'choice'  => ['case' => 'radio', 'label' => 'Choice', 'options' => $options],
            $p . 'boxes'   => ['case' => 'checkbox_multiple', 'label' => 'Boxes', 'options' => $options],
            $p . 'map'     => ['case' => 'map', 'label' => 'Map'],
            $p . 'section' => ['case' => 'section', 'label' => 'Section', 'include' => $inner],
            $p . 'tabs'    => ['case' => 'awm_tab', 'label' => 'Tabs', 'awm_tabs' => ['one' => ['label' => 'One', 'include' => $inner], 'two' => ['label' => 'Two', 'include' => $inner]]],
            $p . 'rows'    => ['case' => 'repeater', 'label' => 'Rows', 'include' => $inner + ['row_pick' => ['case' => 'select', 'label' => 'Pick', 'options' => $options]]],
        ];
    }

    /**
     * Values stored before the form renders.
     *
     * @return array
     */
    private static function seed()
    {
        $p = self::PREFIX;

        return [
            $p . 'text'    => 'before',
            $p . 'number'  => '7',
            $p . 'flag'    => '1',
            $p . 'hidden'  => 'kept',
            $p . 'notes'   => "line one\nline two",
            $p . 'multi'   => ['a', 'c'],
            $p . 'choice'  => 'b',
            $p . 'boxes'   => ['b'],
            $p . 'map'     => ['lat' => '37.98', 'lng' => '23.72', 'address' => 'Athens'],
            $p . 'section' => ['inner_text' => 'in section'],
            $p . 'tabs'    => ['one' => ['inner_text' => 'tab one'], 'two' => ['inner_text' => 'tab two']],
            $p . 'rows'    => [['inner_text' => 'row 1', 'row_pick' => 'a'], ['inner_text' => 'row 2', 'row_pick' => 'b']],
        ];
    }

    /**
     * What a user changes before saving.
     *
     * @param array $submitted Browser submission of the first render.
     * @return array
     */
    private static function edit(array $submitted)
    {
        $p = self::PREFIX;
        $submitted[$p . 'text']   = 'after "quoted" & <b>bold</b>';
        $submitted[$p . 'number'] = '42';
        $submitted[$p . 'secret'] = 's3cret-value';
        $submitted[$p . 'multi']  = ['b'];
        $submitted[$p . 'boxes']  = ['a', 'c'];
        $submitted[$p . 'choice'] = 'c';
        unset($submitted[$p . 'flag']);
        $submitted[$p . 'section']['inner_text']    = 'section edited';
        $submitted[$p . 'tabs']['two']['inner_text'] = 'tab two edited';
        $submitted[$p . 'rows'][1]['inner_text']     = 'row 2 edited';
        $submitted[$p . 'map']['address']           = 'Thessaloniki';

        return $submitted;
    }

    /**
     * Build what a browser posts for a rendered form: enabled named controls,
     * checked checkboxes/radios, selected options, textarea content.
     *
     * @param string $html Rendered form markup.
     * @return array Request array, as PHP would parse it.
     */
    private static function submit($html)
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><form>' . $html . '</form>');
        libxml_clear_errors();

        $pairs = [];
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//input[@name] | //textarea[@name] | //select[@name]') as $node) {
            if ($node->hasAttribute('disabled')) {
                continue;
            }
            foreach (self::control_values($node) as $value) {
                $pairs[] = rawurlencode($node->getAttribute('name')) . '=' . rawurlencode($value);
            }
        }
        parse_str(implode('&', $pairs), $request);

        return $request;
    }

    /**
     * Values one control contributes to a submission.
     *
     * @param \DOMElement $node Form control.
     * @return string[]
     */
    private static function control_values(\DOMElement $node)
    {
        if ('textarea' === $node->nodeName) {
            return [$node->textContent];
        }
        if ('select' === $node->nodeName) {
            $values = [];
            foreach ($node->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $values[] = $option->getAttribute('value');
                }
            }
            return $values;
        }
        $type = strtolower($node->getAttribute('type'));
        if (in_array($type, ['checkbox', 'radio'], true) && !$node->hasAttribute('checked')) {
            return [];
        }
        if (in_array($type, ['submit', 'button'], true)) {
            return [];
        }
        return [$node->getAttribute('value')];
    }

    /**
     * Whether the first render is one container holding every field.
     *
     * @param string $html Rendered markup.
     * @return bool
     */
    private static function renders_all($html)
    {
        if (0 !== strpos($html, '<div id="" class="awm-show-content" count="')) {
            return false;
        }
        foreach (array_keys(self::library()) as $key) {
            if (false === strpos($html, 'name="' . $key) && false === strpos($html, 'data-input="' . $key . '"')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the given keys were stored exactly as submitted.
     *
     * @param array    $stored    Stored meta per key.
     * @param array    $submitted Submission.
     * @param string[] $suffixes  Key suffixes (after the prefix).
     * @return bool
     */
    private static function stored_as_submitted(array $stored, array $submitted, array $suffixes)
    {
        foreach ($suffixes as $suffix) {
            $key = self::PREFIX . $suffix;
            if (!isset($submitted[$key]) || $stored[$key] != $submitted[$key]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether re-rendering submits the same values, except the encrypted one (masked).
     *
     * @param array $submitted First (edited) submission.
     * @param array $resubmit  Submission of the re-rendered form.
     * @return bool
     */
    private static function same_resubmit(array $submitted, array $resubmit)
    {
        $secret = self::PREFIX . 'secret';
        if (!isset($resubmit[$secret]) || !\EWP_Encryption::is_masked((string) $resubmit[$secret])) {
            return false;
        }
        return [] === self::diff_keys($submitted, $resubmit);
    }

    /**
     * Keys whose values differ between two submissions (encrypted key ignored).
     *
     * @param array $a Submission.
     * @param array $b Submission.
     * @return string[]
     */
    private static function diff_keys(array $a, array $b)
    {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if (self::PREFIX . 'secret' === $key) {
                continue;
            }
            if (!isset($a[$key], $b[$key]) || $a[$key] != $b[$key]) {
                $diff[] = $key;
            }
        }
        return $diff;
    }
}
