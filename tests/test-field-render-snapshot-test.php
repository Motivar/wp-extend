<?php
/**
 * Golden HTML snapshots of awm_show_content() for every stable field case in
 * every view, so the field-engine refactor (EWP\Fields) can move rendering out
 * of library.php without changing the markup admin JS/CSS and external
 * callers (filox, themes) depend on.
 *
 * Snapshots live in tests/fixtures/field-snapshots/. A missing snapshot is
 * written on first run; set EWP_UPDATE_SNAPSHOTS=1 to rewrite them after an
 * intentional markup change (and record the change in CHANGELOG.md).
 */

if (!function_exists('ewp_snapshot_callback')) {
    /**
     * Callback rendered by the `function` field fixture.
     *
     * @param mixed $object_id Object id (ignored; varies per run). awm_prepare_field() also calls it without one.
     * @return string
     */
    function ewp_snapshot_callback($object_id = 0)
    {
        return '<b>callback output</b>';
    }
}

class Test_Field_Render_Snapshot extends WP_UnitTestCase
{
    /** @var string Snapshot directory. */
    private const DIR = __DIR__ . '/fixtures/field-snapshots/';

    /** @var int Post with stored meta. */
    private static $post_id;

    /** @var int Term with stored meta. */
    private static $term_id;

    /** @var int User with stored meta. */
    private static $user_id;

    /**
     * Create the objects whose stored values the fields render.
     *
     * @param WP_UnitTest_Factory $factory Factory.
     * @return void
     */
    public static function wpSetUpBeforeClass($factory)
    {
        self::$post_id = $factory->post->create();
        self::$term_id = $factory->term->create(array('taxonomy' => 'category'));
        self::$user_id = $factory->user->create();
        foreach (self::stored_values() as $key => $value) {
            update_post_meta(self::$post_id, $key, $value);
            update_term_meta(self::$term_id, $key, $value);
            update_user_meta(self::$user_id, $key, $value);
            update_option($key, $value);
        }
    }

    /**
     * Values stored for the fixture fields.
     *
     * @return array<string, mixed>
     */
    private static function stored_values()
    {
        return array(
            'snap_text'      => 'Hello "world" <b>',
            'snap_number'    => '42abc',
            'snap_check_on'  => '1',
            'snap_time'      => '2026-10-29 14:35:00',
            'snap_password'  => 'secret',
            'snap_hidden'    => 'hid',
            'snap_textarea'  => "line1\nline2",
            'snap_select'    => 'b',
            'snap_radio'     => 'a',
            'snap_multi'     => array('a', 'c'),
            'snap_multi_sel' => array('a', 'c'),
            'snap_multi_excl' => array('b'),
            'snap_rich'      => '<b>bold</b> and <i>italic</i> text',
            'snap_gallery'   => array(999998, 999999),
            'snap_image'     => 999999,
            'snap_map'       => array('lat' => '37.98', 'lng' => '23.72', 'address' => 'Athens "GR"'),
            'snap_section'   => array('sec_text' => 'in section', 'sec_select' => 'c'),
            'sec_text'       => 'kept input',
            'snap_tabs'      => array('tab_one' => array('t_text' => 'tab value')),
            'snap_rep'       => array(array('r_text' => 'row 1', 'r_select' => 'a'), array('r_text' => 'row "2"', 'r_select' => 'b')),
            'snap_oif'       => 'post_type:post',
            'snap_oif_ids'   => array(999999),
        );
    }

    /**
     * Field libraries, one snapshot each (per view).
     *
     * @return array<string, array>
     */
    private static function libraries()
    {
        $options = array('a' => array('label' => 'A'), 'b' => array('label' => 'B'), 'c' => array('label' => 'C'));
        return array(
            'input-text' => array('snap_text' => array('case' => 'input', 'type' => 'text', 'label' => 'Text', 'explanation' => 'Explain', 'class' => array('one', 'two'), 'attributes' => array('placeholder' => 'Type', 'data-x' => array('p', 'q')), 'required' => true)),
            'input-default' => array('snap_missing' => array('case' => 'input', 'type' => 'text', 'label' => 'Default', 'default' => 'dflt', 'attributes' => array('id' => 'custom-id'))),
            'input-number' => array('snap_number' => array('case' => 'input', 'type' => 'number', 'label' => 'Number', 'attributes' => array('min' => 1, 'max' => 99))),
            'input-checkbox-on' => array('snap_check_on' => array('case' => 'input', 'type' => 'checkbox', 'label' => 'On')),
            'input-checkbox-off' => array('snap_check_off' => array('case' => 'input', 'type' => 'checkbox', 'label' => 'Off', 'after_message' => 'After')),
            'input-time' => array('snap_time' => array('case' => 'input', 'type' => 'time', 'label' => 'Time')),
            'input-password' => array('snap_password' => array('case' => 'input', 'type' => 'password', 'label' => 'Pass')),
            'input-hidden' => array('snap_hidden' => array('case' => 'input', 'type' => 'hidden', 'label' => 'Hidden')),
            'input-show-when' => array('snap_text' => array('case' => 'input', 'type' => 'email', 'label' => 'Email', 'show-when' => array('snap_check_on' => array('values' => array('1' => true))), 'hide-label' => true)),
            'textarea' => array('snap_textarea' => array('case' => 'textarea', 'label' => 'Area')),
            'select' => array('snap_select' => array('case' => 'select', 'label' => 'Select', 'options' => $options)),
            'radio' => array('snap_radio' => array('case' => 'radio', 'label' => 'Radio', 'options' => $options)),
            'checkbox-multiple' => array('snap_multi' => array('case' => 'checkbox_multiple', 'label' => 'Multi', 'options' => $options)),
            'select-multiple' => array('snap_multi_sel' => array('case' => 'select', 'label' => 'Multi select', 'options' => $options, 'attributes' => array('multiple' => true))),
            'select-optgroups' => array('snap_select' => array('case' => 'select', 'label' => 'Groups', 'options' => array(
                'a' => array('label' => 'A', 'optgroup' => 'g1'),
                'b' => array('label' => 'B'),
                'c' => array('label' => 'C', 'optgroup' => 'g2'),
                'd' => array('label' => 'D', 'optgroup' => 'g1'),
                'optgroups' => array('g1' => array('label' => 'Group One'), 'g2' => array('label' => 'Group Two')),
            ))),
            'select-remove-empty' => array('snap_select' => array('case' => 'select', 'label' => 'No empty', 'options' => $options, 'removeEmpty' => true)),
            'select-no-style' => array('snap_select' => array('case' => 'select', 'label' => 'No style', 'options' => $options, 'no_style' => true)),
            'select-extra' => array('snap_select' => array('case' => 'select', 'label' => 'Extra', 'required' => true, 'options' => array(
                'a' => array('label' => 'A', 'extra' => array('data-price' => '10', 'title' => 'say hi')),
                'b' => array('label' => 'B <em>bold</em>'),
            ))),
            'select-empty-options' => array('snap_select' => array('case' => 'select', 'label' => 'Empty', 'options' => array())),
            'textarea-strip-html' => array('snap_rich' => array('case' => 'textarea', 'label' => 'Strip', 'awm_strip_html' => true, 'required' => true, 'attributes' => array('placeholder' => 'Write'))),
            'textarea-html-value' => array('snap_rich' => array('case' => 'textarea', 'label' => 'Raw')),
            'radio-required' => array('snap_radio' => array('case' => 'radio', 'label' => 'Radio req', 'options' => $options, 'required' => true)),
            'radio-disable-wrapper' => array('snap_radio' => array('case' => 'radio', 'label' => 'Radio bare', 'options' => $options, 'disable_wrapper' => true)),
            'checkbox-multiple-no-apply-all' => array('snap_multi' => array('case' => 'checkbox_multiple', 'label' => 'Multi', 'options' => $options, 'disable_apply_all' => true, 'explanation' => 'Pick')),
            'checkbox-multiple-exclusive-none' => array('snap_multi_none' => array('case' => 'checkbox_multiple', 'label' => 'Exclusive', 'options' => $options, 'apply_all_mode' => 'exclusive')),
            'checkbox-multiple-exclusive-selected' => array('snap_multi_excl' => array('case' => 'checkbox_multiple', 'label' => 'Exclusive', 'options' => $options, 'apply_all_mode' => 'exclusive')),
            'checkbox-multiple-apply-all-label' => array('snap_multi' => array('case' => 'checkbox_multiple', 'label' => 'Multi', 'options' => $options, 'apply_all_label' => 'Everything')),
            'message' => array('snap_msg' => array('case' => 'message', 'label' => 'Msg', 'value' => '<p>Body</p>')),
            'html' => array('snap_html' => array('case' => 'html', 'label' => 'Html', 'value' => '<em>raw</em>', 'show_label' => true)),
            'button' => array('snap_btn' => array('case' => 'button', 'label' => 'Go', 'link' => 'https://example.com/?a=1&b=2')),
            'function' => array('snap_fn' => array('case' => 'function', 'label' => 'Fn', 'explanation' => 'Exp', 'callback' => 'ewp_snapshot_callback')),
            'message-empty' => array('snap_msg' => array('case' => 'message', 'label' => 'Msg')),
            'button-underscore' => array('_snap_btn' => array('case' => 'button', 'label' => '<b>Go</b>', 'class' => array('button'), 'attributes' => array('target' => '_blank'))),
            'gallery' => array('snap_gallery' => array('case' => 'awm_gallery', 'label' => 'Gallery', 'explanation' => 'Pick images')),
            'image' => array('snap_image' => array('case' => 'image', 'label' => 'Image', 'explanation' => 'One image')),
            'image-empty' => array('snap_image_none' => array('case' => 'image', 'label' => 'Image')),
            'map' => array('snap_map' => array('case' => 'map', 'label' => 'Map', 'required' => true)),
            'section' => array('snap_section' => array('case' => 'section', 'label' => 'Section', 'explanation' => 'Grouped', 'include' => array(
                'sec_text'   => array('case' => 'input', 'type' => 'text', 'label' => 'Inner text'),
                'sec_select' => array('case' => 'select', 'label' => 'Inner select', 'options' => $options),
            ))),
            'section-keep-inputs' => array('snap_section' => array('case' => 'section', 'label' => 'Kept section', 'keep_inputs' => true, 'include' => array(
                'sec_text' => array('case' => 'input', 'type' => 'text', 'label' => 'Kept'),
            ))),
            'tabs' => array('snap_tabs' => array('case' => 'awm_tab', 'label' => 'Tabs', 'explanation' => 'Per tab', 'awm_tabs' => array(
                'tab_one' => array('label' => 'One', 'include' => array('t_text' => array('case' => 'input', 'type' => 'text', 'label' => 'T text'))),
                'tab_two' => array('label' => 'Two', 'include' => array('t_check' => array('case' => 'input', 'type' => 'checkbox', 'label' => 'T check'))),
            ))),
            'repeater' => array('snap_rep' => array('case' => 'repeater', 'label' => 'Rows', 'explanation' => 'Many', 'row_title' => 'Row %d', 'item_name' => 'Item', 'maxrows' => 5, 'include' => array(
                'r_text'   => array('case' => 'input', 'type' => 'text', 'label' => 'R text'),
                'r_select' => array('case' => 'select', 'label' => 'R select', 'options' => $options),
            ))),
            'repeater-min-rows' => array('snap_rep_empty' => array('case' => 'repeater', 'label' => 'Min', 'minrows' => 2, 'include' => array(
                'r_text' => array('case' => 'input', 'type' => 'text', 'label' => 'R text'),
            ))),
            'repeater-bare' => array('snap_rep' => array('case' => 'repeater', 'label' => 'Bare', 'no_template' => true, 'hide_buttons' => true, 'include' => array(
                'r_text' => array('case' => 'input', 'type' => 'text', 'label' => 'R text'),
            ))),
            'repeater-prepopulated' => array('snap_rep_empty' => array('case' => 'repeater', 'label' => 'Pre', 'prePopulated' => array(array('r_text' => 'pre')), 'include' => array(
                'r_text' => array('case' => 'input', 'type' => 'text', 'label' => 'R text'),
            ))),
            'modal' => array('snap_modal' => array('case' => 'awm_modal', 'label' => 'Modal', 'modal_title' => 'Edit "it"', 'button_label' => 'Open', 'include' => array(
                'm_text' => array('case' => 'input', 'type' => 'text', 'label' => 'M text'),
            ))),
            'modal-empty' => array('snap_modal' => array('case' => 'awm_modal', 'label' => 'Modal')),
            /*types pinned so post types registered by other tests in the same run do not leak into the list*/
            'object-id-filter' => array('snap_oif' => array('case' => 'object_id_filter', 'label' => 'Objects', 'explanation' => 'Pick',
                'post_types'     => array('post', 'page', 'attachment'),
                'taxonomies'     => array('category', 'post_tag', 'post_format'),
                'custom_content' => array('ewp_fields', 'ewp_post_types', 'ewp_taxonomies', 'ewp_search', 'ewp_content_type'),
            )),
            'html-strip' => array(
                'snap_text'  => array('case' => 'input', 'type' => 'text', 'label' => 'Dropped', 'order' => 1),
                'snap_strip' => array('case' => 'html', 'value' => '<p>only this</p>', 'strip' => true, 'order' => 2),
            ),
            'html-empty' => array('snap_html_empty' => array('case' => 'html', 'label' => 'Empty html')),
            'show-zero' => array(
                'snap_text'   => array('case' => 'input', 'type' => 'text', 'label' => 'Shown'),
                'snap_hidden' => array('case' => 'input', 'type' => 'text', 'label' => 'Not shown', 'show' => 0),
            ),
            'label-class-no-show' => array('snap_text' => array('case' => 'input', 'type' => 'text', 'label' => 'Uncounted', 'label_class' => array('custom-row', 'awm_no_show'))),
            'disable-elements' => array('snap_check_on' => array('case' => 'input', 'type' => 'checkbox', 'label' => 'Toggle', 'disable-elements' => array('#a', '#b'))),
            'input-submit' => array('snap_submit' => array('case' => 'input', 'type' => 'submit', 'label' => 'Send', 'attributes' => array('value' => 'Send'))),
            'unknown-case' => array('snap_unknown' => array('case' => 'no_such_case', 'label' => 'Unknown')),
            'mixed-order' => array(
                'snap_select' => array('case' => 'select', 'label' => 'Second', 'options' => $options, 'order' => 2),
                'snap_text'   => array('case' => 'input', 'type' => 'text', 'label' => 'First', 'order' => 1),
                'awm-id'      => 'snap-box',
            ),
        );
    }

    /**
     * Views and the object id each renders against.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private static function views()
    {
        return array(
            'post'     => array('post', self::$post_id),
            'term-new' => array('term', 0),
            'term'     => array('term', self::$term_id),
            'user'     => array('user', self::$user_id),
            'option'   => array('option', 0),
            'none'     => array('none', 0),
            'restrict' => array('restrict_manage_posts', 0),
        );
    }

    /**
     * Every library renders exactly like its snapshot in every view.
     *
     * @return void
     */
    public function test_render_matches_snapshots()
    {
        $update = (bool) getenv('EWP_UPDATE_SNAPSHOTS');
        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0755, true);
        }
        foreach (self::libraries() as $name => $library) {
            foreach (self::views() as $view_name => $view) {
                $html = (string) awm_show_content($library, $view[1], $view[0]);
                $html = self::normalise($html);
                $file = self::DIR . $name . '.' . $view_name . '.html';
                if ($update || !file_exists($file)) {
                    file_put_contents($file, $html);
                }
                $this->assertSame(file_get_contents($file), $html, "Snapshot drift: {$name} / {$view_name}");
            }
        }
    }

    /**
     * Make output stable across runs: checkbox_multiple option ids vary per
     * run, whitespace inside tags is insignificant.
     *
     * @param string $html Rendered markup.
     * @return string
     */
    private static function normalise($html)
    {
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/\s+(\/?>)/', '$1', $html);
        /*the modal trigger carries the (per-run) object id*/
        $html = preg_replace('/data-object-id="\d+"/', 'data-object-id="{ID}"', $html);
        /*checkbox_multiple suffixes option ids with rand()*/
        $html = preg_replace('/(snap_multi_[a-z_]+)_\d+"/', '$1_{RAND}"', $html);
        return str_replace('><', ">\n<", trim($html)) . "\n";
    }
}
