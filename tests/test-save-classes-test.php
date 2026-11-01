<?php
/**
 * EWP\Fields\Save\Meta_Saver and Translation_Sync, exercised directly:
 * nested-key resolution, empty-value deletes, per-view writes, blocked keys,
 * the update action and the WPML paths with filters (WPML is absent).
 */

use EWP\Fields\Save\Meta_Saver;
use EWP\Fields\Save\Translation_Sync;

class Test_Save_Classes extends WP_UnitTestCase
{
    /**
     * Register a post box with one auto-translated and one plain field.
     *
     * @return void
     */
    private function register_box()
    {
        add_filter('awm_add_meta_boxes_filter', function ($boxes) {
            $boxes['ewp_sc_box'] = array(
                'title'     => 'Save classes box',
                'postTypes' => array('post'),
                'library'   => array(
                    'ewp_sc_tr'    => array('label' => 'T', 'case' => 'input', 'type' => 'text', 'auto_translate' => true),
                    'ewp_sc_plain' => array('label' => 'P', 'case' => 'input', 'type' => 'text'),
                ),
            );
            return $boxes;
        });
    }

    /**
     * Resolving a plain key returns the key and its posted value.
     *
     * @return void
     */
    public function test_resolve_plain_key()
    {
        $arr = array();
        $this->assertSame(array('a', 'x'), (new Meta_Saver())->resolve_value('a', array('a' => 'x'), $arr));
        $this->assertSame(array('a' => 'x'), $arr);
        $this->assertSame(array('b', ''), (new Meta_Saver())->resolve_value('b', array(), $arr));
    }

    /**
     * A nested key `a[b][c]` resolves to the base key and the nested array.
     *
     * @return void
     */
    public function test_resolve_nested_key()
    {
        $arr = array();
        $posted = array('a' => array('b' => array('c' => 'deep', 'd' => 'other')));
        list($k, $val) = (new Meta_Saver())->resolve_value('a[b][c]', $posted, $arr);
        $this->assertSame('a', $k);
        $this->assertSame(array('b' => array('c' => 'deep')), $val);
        $this->assertSame(array('a' => array('b' => array('c' => 'deep'))), $arr);
    }

    /**
     * update_vars writes post meta, deletes on empty and returns the resolved values.
     *
     * @return void
     */
    public function test_update_vars_post_write_and_delete()
    {
        $id = self::factory()->post->create();
        $saver = new Meta_Saver();
        $out = $saver->update_vars(array('ewp_sc_a', 'ewp_sc_b'), array('ewp_sc_a' => 'one', 'ewp_sc_b' => 'two'), $id, 'post');
        $this->assertSame('one', get_post_meta($id, 'ewp_sc_a', true));
        $this->assertSame(array('ewp_sc_a' => 'one', 'ewp_sc_b' => 'two'), $out);

        $saver->update_vars(array('ewp_sc_a'), array('ewp_sc_a' => ''), $id, 'post');
        $this->assertSame('', get_post_meta($id, 'ewp_sc_a', true));
        $this->assertSame('', (string) get_post_meta($id, 'ewp_sc_a', true));
        $this->assertFalse(metadata_exists('post', $id, 'ewp_sc_a'));
        $this->assertSame('two', get_post_meta($id, 'ewp_sc_b', true));
    }

    /**
     * Nested keys are stored as the nested array under the base key.
     *
     * @return void
     */
    public function test_update_vars_nested_key_stored()
    {
        $id = self::factory()->post->create();
        (new Meta_Saver())->update_vars(array('ewp_sc_n[x][y]'), array('ewp_sc_n' => array('x' => array('y' => 'v'))), $id, 'post');
        $this->assertSame(array('x' => array('y' => 'v')), get_post_meta($id, 'ewp_sc_n', true));
    }

    /**
     * The term and user views write to term and user meta.
     *
     * @return void
     */
    public function test_write_term_and_user_views()
    {
        $term = self::factory()->term->create();
        $user = self::factory()->user->create();
        $saver = new Meta_Saver();

        $saver->write('term', $term, 'ewp_sc_t', 'tv');
        $saver->write('user', $user, 'ewp_sc_u', 'uv');
        $this->assertSame('tv', get_term_meta($term, 'ewp_sc_t', true));
        $this->assertSame('uv', get_user_meta($user, 'ewp_sc_u', true));
        $this->assertFalse(metadata_exists('post', $user, 'ewp_sc_u'));

        $saver->write('term', $term, 'ewp_sc_t', '');
        $saver->write('user', $user, 'ewp_sc_u', array());
        $this->assertFalse(metadata_exists('term', $term, 'ewp_sc_t'));
        $this->assertFalse(metadata_exists('user', $user, 'ewp_sc_u'));
    }

    /**
     * Blocked keys (capabilities) are never written.
     *
     * @return void
     */
    public function test_blocked_key_skipped()
    {
        global $wpdb;
        $user = self::factory()->user->create(array('role' => 'subscriber'));
        $key = $wpdb->get_blog_prefix() . 'capabilities';
        (new Meta_Saver())->update_vars(array($key), array($key => array('administrator' => true)), $user, 'user');
        $this->assertFalse(user_can($user, 'manage_options'));
    }

    /**
     * save() fires awm_custom_meta_update_action with its arguments, and does nothing for empty data.
     *
     * @return void
     */
    public function test_save_fires_action()
    {
        $id = self::factory()->post->create();
        $seen = array();
        $cb = function () use (&$seen) {
            $seen[] = func_get_args();
        };
        add_action('awm_custom_meta_update_action', $cb, 10, 5);
        (new Meta_Saver())->save(array('ewp_sc_s'), array('ewp_sc_s' => 'z'), $id, 'post', 'page');
        (new Meta_Saver())->save(array(), array(), $id, 'post', 'page');
        remove_action('awm_custom_meta_update_action', $cb, 10);

        $this->assertCount(1, $seen);
        $this->assertSame(array(array('ewp_sc_s'), array('ewp_sc_s' => 'z'), $id, 'post', 'page'), $seen[0]);
        $this->assertSame('z', get_post_meta($id, 'ewp_sc_s', true));
    }

    /**
     * Without WPML nothing is hidden; the filter can force it.
     *
     * @return void
     */
    public function test_hidden_in_language_filter()
    {
        $sync = new Translation_Sync();
        $field = array('auto_translate' => '1');
        $this->assertFalse($sync->hidden_in_current_language($field));
        $this->assertFalse($sync->hidden_in_current_language('nope'));

        add_filter('awm_field_hidden_in_current_language', '__return_true');
        $this->assertTrue($sync->hidden_in_current_language($field));
        remove_filter('awm_field_hidden_in_current_language', '__return_true');
    }

    /**
     * translatable_fields passes its result through awm_auto_translate_fields_filter.
     *
     * @return void
     */
    public function test_translatable_fields_filter()
    {
        $cb = function ($fields, $case, $metabox) {
            return array_merge($fields, array('from_filter_' . $case . '_' . $metabox));
        };
        $this->register_box();
        $sync = new Translation_Sync();
        $this->assertContains('ewp_sc_tr', $sync->translatable_fields('ewp_sc_box', 'post'));
        $this->assertNotContains('ewp_sc_plain', $sync->translatable_fields('ewp_sc_box', 'post'));

        add_filter('awm_auto_translate_fields_filter', $cb, 10, 3);
        $this->assertContains('from_filter_post_ewp_sc_box', $sync->translatable_fields('ewp_sc_box', 'post'));
        remove_filter('awm_auto_translate_fields_filter', $cb, 10);
    }

    /**
     * Without WPML auto_translate is a no-op (no meta copied, no error).
     *
     * @return void
     */
    public function test_auto_translate_noop_without_wpml()
    {
        $this->register_box();
        $id = self::factory()->post->create();
        (new Translation_Sync())->auto_translate(array('k'), array(), $id, 'post');
        (new Translation_Sync())->auto_translate(array('ewp_sc_tr'), array('ewp_sc_tr' => 'v', 'awm_metabox' => array('ewp_sc_box'), 'awm_metabox_case' => 'post'), $id, 'post');
        $this->assertFalse(metadata_exists('post', $id, 'ewp_sc_tr'));
    }

    /**
     * source_value and write_translation: posted wins for the original, empty deletes.
     *
     * @return void
     */
    public function test_source_value_and_write_translation()
    {
        $orig = self::factory()->post->create();
        $trans = self::factory()->post->create();
        update_post_meta($orig, 'ewp_sc_k', 'stored');
        $sync = new Translation_Sync();

        $this->assertSame('posted', $sync->source_value('post', $orig, $orig, 'ewp_sc_k', array('ewp_sc_k' => 'posted')));
        $this->assertSame('stored', $sync->source_value('post', $trans, $orig, 'ewp_sc_k', array('ewp_sc_k' => 'posted')));
        $this->assertSame('', $sync->source_value('user', $orig, $orig, 'ewp_sc_k', array()));

        $sync->write_translation('post', $trans, 'ewp_sc_k', 'copy');
        $this->assertSame('copy', get_post_meta($trans, 'ewp_sc_k', true));
        $sync->write_translation('post', $trans, 'ewp_sc_k', '');
        $this->assertFalse(metadata_exists('post', $trans, 'ewp_sc_k'));
    }

    /**
     * translated_ids builds original/translations from WPML filters and applies awm_translated_ids_filter.
     *
     * @return void
     */
    public function test_translated_ids_via_filters()
    {
        global $sitepress;
        $prev = $sitepress;
        $sitepress = new class {
            /** @return string Default language. */
            public function get_default_language()
            {
                return 'en';
            }
        };
        $langs = function () {
            return array('en' => array(), 'el' => array(), 'fr' => array());
        };
        $objid = function ($id, $type, $ret, $lang) {
            return $id + ('en' === $lang ? 0 : ('el' === $lang ? 100 : 200));
        };
        add_filter('wpml_active_languages', $langs);
        add_filter('wpml_object_id', $objid, 10, 4);
        $post = self::factory()->post->create();

        $ids = (new Translation_Sync())->translated_ids($post, 'post');

        remove_filter('wpml_active_languages', $langs);
        remove_filter('wpml_object_id', $objid, 10);
        $sitepress = $prev;
        $this->assertSame(array('original' => $post, 'translations' => array($post + 100, $post + 200)), $ids);
    }
}
