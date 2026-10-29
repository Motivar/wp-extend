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
            'message' => array('snap_msg' => array('case' => 'message', 'label' => 'Msg', 'value' => '<p>Body</p>')),
            'html' => array('snap_html' => array('case' => 'html', 'label' => 'Html', 'value' => '<em>raw</em>', 'show_label' => true)),
            'button' => array('snap_btn' => array('case' => 'button', 'label' => 'Go', 'link' => 'https://example.com/?a=1&b=2')),
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
        /*checkbox_multiple suffixes option ids with rand()*/
        $html = preg_replace('/(snap_multi_[a-z_]+)_\d+"/', '$1_{RAND}"', $html);
        return str_replace('><', ">\n<", trim($html)) . "\n";
    }
}
