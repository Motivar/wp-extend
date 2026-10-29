<?php
/**
 * Fields flagged `encrypt` are stored encrypted when saved through the
 * meta-box save path (EWP_Meta_Save_Guard → awm_save_custom_meta()).
 *
 * Before 1.11.0 the encryption ran on `update_{$type}_meta`, an action whose
 * return value WordPress ignores, so such values were stored in plain text.
 */

class Test_Meta_Encryption extends WP_UnitTestCase
{
    /** @var int Post saved in each test. */
    private $post_id;

    /** @var callable Meta box registration. */
    private $register;

    /**
     * Admin user, a post, and a meta box with one encrypted and one plain field.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $this->post_id  = self::factory()->post->create();
        $this->register = function ($boxes) {
            $boxes['ewp_enc_test'] = array('title' => 'Enc', 'postTypes' => array('post'), 'library' => array(
                'enc_secret' => array('case' => 'input', 'type' => 'password', 'label' => 'Secret', 'encrypt' => true),
                'enc_plain'  => array('case' => 'input', 'type' => 'text', 'label' => 'Plain'),
                'enc_marked' => array('case' => 'input', 'type' => 'text', 'label' => 'Marked'),
            ));
            return $boxes;
        };
        add_filter('awm_add_meta_boxes_filter', $this->register, PHP_INT_MAX - 1);
        $GLOBALS['awm_field_configs'] = array();
    }

    /**
     * Remove the box and the fake request.
     *
     * @return void
     */
    public function tear_down()
    {
        remove_filter('awm_add_meta_boxes_filter', $this->register, PHP_INT_MAX - 1);
        $_POST = $_REQUEST = array();
        parent::tear_down();
    }

    /**
     * Post a save request for the test post.
     *
     * @param array $fields Field values.
     * @param array $marked Keys posted in ewp_encrypt[].
     * @return string[] Saved keys.
     */
    private function save(array $fields, array $marked = array())
    {
        $_POST = wp_slash($fields + array(
            'awm_custom_meta' => array_keys($fields),
            'ewp_encrypt'     => $marked,
            '_wpnonce'        => wp_create_nonce('update-post_' . $this->post_id),
        ));
        $_REQUEST = $_POST;
        return EWP_Meta_Save_Guard::save('post', $this->post_id, 'post');
    }

    /**
     * A new value of an encrypted field (marker posted by the form) is stored encrypted.
     *
     * @return void
     */
    public function test_new_value_is_encrypted()
    {
        $this->save(array('enc_secret' => 'top secret', 'enc_plain' => 'visible'), array('enc_secret'));

        $stored = get_post_meta($this->post_id, 'enc_secret', true);
        $this->assertTrue(EWP_Encryption::is_encrypted($stored));
        $this->assertSame('top secret', EWP_Encryption::decrypt($stored));
        $this->assertSame('visible', get_post_meta($this->post_id, 'enc_plain', true));
    }

    /**
     * The field config alone (registered by a render in the same request) is enough.
     *
     * @return void
     */
    public function test_config_without_marker_encrypts()
    {
        $GLOBALS['awm_field_configs']['enc_secret'] = array('case' => 'input', 'type' => 'password', 'encrypt' => true);
        $this->save(array('enc_secret' => 'from config'));
        $this->assertTrue(EWP_Encryption::is_encrypted(get_post_meta($this->post_id, 'enc_secret', true)));
    }

    /**
     * A masked value (field left untouched) keeps the stored ciphertext.
     *
     * @return void
     */
    public function test_masked_value_keeps_ciphertext()
    {
        $this->save(array('enc_secret' => 'original'), array('enc_secret'));
        $cipher = get_post_meta($this->post_id, 'enc_secret', true);

        $this->save(array('enc_secret' => EWP_Encryption::mask($cipher)), array('enc_secret'));
        $this->assertSame($cipher, get_post_meta($this->post_id, 'enc_secret', true));
    }

    /**
     * An already encrypted value is not encrypted twice; unflagged keys stay plain.
     *
     * @return void
     */
    public function test_no_double_encryption_and_plain_keys()
    {
        $cipher = EWP_Encryption::encrypt('once');
        $this->save(array('enc_secret' => $cipher, 'enc_marked' => 'plain text'), array('enc_secret'));
        $this->assertSame($cipher, get_post_meta($this->post_id, 'enc_secret', true));
        $this->assertSame('plain text', get_post_meta($this->post_id, 'enc_marked', true));
    }

    /**
     * The rendered form masks the stored secret instead of printing it.
     *
     * @return void
     */
    public function test_render_masks_stored_secret()
    {
        $this->save(array('enc_secret' => 'never shown'), array('enc_secret'));
        $html = awm_show_content(array('enc_secret' => array('case' => 'input', 'type' => 'password', 'label' => 'Secret', 'encrypt' => true)), $this->post_id, 'post');
        $this->assertStringNotContainsString('never shown', $html);
        $this->assertStringContainsString('name="ewp_encrypt[]" value="enc_secret"', $html);
    }
}
