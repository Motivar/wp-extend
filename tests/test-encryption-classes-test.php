<?php
/**
 * Field_Encryption / Option_Field_Path and the awm_* wrappers delegating to them.
 */

use EWP\Fields\Encryption\Field_Encryption;
use EWP\Fields\Encryption\Option_Field_Path;

class Test_Encryption_Classes extends WP_UnitTestCase
{
    /** @var array Encrypted password field config. */
    private $config = array('case' => 'input', 'type' => 'password', 'encrypt' => true);

    /**
     * Clean request state.
     *
     * @return void
     */
    public function tear_down()
    {
        $_POST = $_REQUEST = array();
        $GLOBALS['awm_field_configs'] = array();
        parent::tear_down();
    }

    /**
     * Encryption applies to flagged input fields of supported types only.
     *
     * @return void
     */
    public function test_should_encrypt_field_rules()
    {
        $enc = Field_Encryption::instance();
        $this->assertTrue($enc->should_encrypt_field($this->config));
        $this->assertTrue($enc->should_encrypt_field(array('case' => 'input', 'encrypt' => true)));
        $this->assertFalse($enc->should_encrypt_field(array('case' => 'input', 'type' => 'text')));
        $this->assertFalse($enc->should_encrypt_field(array('case' => 'textarea', 'encrypt' => true)));
        $this->assertFalse($enc->should_encrypt_field(array('case' => 'input', 'type' => 'color', 'encrypt' => true)));
        $this->assertFalse($enc->should_encrypt_field(array('encrypt' => true)));
        $this->assertSame(awm_should_encrypt_field($this->config), $enc->should_encrypt_field($this->config));
    }

    /**
     * Values round-trip and the before/after filters run.
     *
     * @return void
     */
    public function test_encrypt_decrypt_round_trip_with_filters()
    {
        $enc   = Field_Encryption::instance();
        $upper = function ($v) {
            return strtoupper($v);
        };
        add_filter('ewp_encrypt_field_value', $upper);
        $cipher = awm_encrypt_field_value('secret', $this->config);
        remove_filter('ewp_encrypt_field_value', $upper);

        $this->assertStringStartsWith('ewp_enc:', $cipher);
        $config = $this->config + array('show_masked' => false);
        $this->assertSame('SECRET', $enc->decrypt_value($cipher, $config, true));

        $suffix = function ($v) {
            return $v . '!';
        };
        add_filter('ewp_decrypt_field_value', $suffix);
        $this->assertSame('SECRET!', awm_decrypt_field_value($cipher, $config, false));
        remove_filter('ewp_decrypt_field_value', $suffix);
        $this->assertNotSame('SECRET', awm_decrypt_field_value($cipher, $this->config, true), 'masked for display by default');
    }

    /**
     * Empty, encrypted and masked values pass through unchanged.
     *
     * @return void
     */
    public function test_encrypt_pass_through()
    {
        $enc    = Field_Encryption::instance();
        $cipher = $enc->encrypt_value('abc', $this->config);

        $this->assertSame('', $enc->encrypt_value('', $this->config));
        $this->assertSame($cipher, $enc->encrypt_value($cipher, $this->config));
        $masked = EWP_Encryption::mask($cipher);
        $this->assertSame($masked, $enc->encrypt_value($masked, $this->config));
        $this->assertSame('', $enc->decrypt_value('', $this->config));
    }

    /**
     * Save-path decision: write, skip masked, marker-only fields.
     *
     * @return void
     */
    public function test_meta_save_encryption()
    {
        $GLOBALS['awm_field_configs'] = array('enc_key' => $this->config);
        list($action, $value) = awm_meta_save_encryption('enc_key', 'plain');
        $this->assertSame('write', $action);
        $this->assertStringStartsWith('ewp_enc:', $value);

        $masked = EWP_Encryption::mask($value);
        $this->assertSame('skip', awm_meta_save_encryption('enc_key', $masked)[0]);
        $this->assertSame(array('write', 'x'), awm_meta_save_encryption('other', 'x'));
        $this->assertStringStartsWith('ewp_enc:', awm_meta_save_encryption('other', 'x', array('other'))[1]);
        $this->assertSame(array('write', array(1)), awm_meta_save_encryption('enc_key', array(1)));
    }

    /**
     * Option paths: plain, bracketed, malformed.
     *
     * @return void
     */
    public function test_option_field_paths()
    {
        $this->assertSame(array('option' => null, 'field' => 'key'), Option_Field_Path::parse('key'));
        $this->assertSame(array('option' => 'a', 'field' => 'b'), awm_parse_encryption_field_path('a[b]'));
        $this->assertNull(Option_Field_Path::parse('a[b'));
        $this->assertNull(Option_Field_Path::parse('a[b][c]'));
    }

    /**
     * Option field encryption validates option name, shape and value.
     *
     * @return void
     */
    public function test_encrypt_option_field()
    {
        $value  = array('k' => 'v', 'e' => '');
        $result = awm_encrypt_option_field($value, 'k', 'opt', 'opt');
        $this->assertStringStartsWith('ewp_enc:', $result['k']);
        $this->assertNull(awm_encrypt_option_field($value, 'k', 'opt', 'other'));
        $this->assertNull(awm_encrypt_option_field($value, 'missing', 'opt'));
        $this->assertNull(awm_encrypt_option_field('str', 'k', 'opt'));
        $this->assertNull(awm_encrypt_option_field($value, 'e', 'opt'));
        $this->assertNull(awm_encrypt_option_field($result, 'k', 'opt'));
        $this->assertFalse(awm_should_encrypt_field_value(''));
        $this->assertTrue(awm_should_encrypt_field_value('x'));
    }

    /**
     * After-save encryption stores ciphertext once, without recursing.
     *
     * @return void
     */
    public function test_encrypt_option_after_save_without_recursion()
    {
        add_action('updated_option', 'awm_encrypt_option_after_save', 10, 3);
        $calls = 0;
        $count = function () use (&$calls) {
            $calls++;
        };
        $_POST = array('awm_metabox_case' => 'option', 'ewp_encrypt' => array('ewp_enc_opt[api]', 'bad[', 'other[api]'));
        update_option('ewp_enc_opt', array('api' => 'tok', 'name' => 'n'));
        add_action('updated_option', $count);
        update_option('ewp_enc_opt', array('api' => 'tok2', 'name' => 'n'));
        remove_action('updated_option', $count);
        remove_action('updated_option', 'awm_encrypt_option_after_save', 10);

        $stored = get_option('ewp_enc_opt');
        $this->assertStringStartsWith('ewp_enc:', $stored['api']);
        $this->assertSame('tok2', EWP_Encryption::decrypt($stored['api']));
        $this->assertSame('n', $stored['name']);
        $this->assertSame(2, $calls, 'one user save plus one re-save');
    }

    /**
     * Other requests are left alone.
     *
     * @return void
     */
    public function test_option_after_save_ignores_other_requests()
    {
        $_POST = array('ewp_encrypt' => array('ewp_enc_opt2[api]'));
        update_option('ewp_enc_opt2', array('api' => 'tok'));
        awm_encrypt_option_after_save('ewp_enc_opt2', null, null);
        $this->assertSame('tok', get_option('ewp_enc_opt2')['api']);
    }

    /**
     * Deprecated meta wrappers keep their behaviour.
     *
     * @return void
     */
    public function test_deprecated_meta_wrappers()
    {
        $GLOBALS['awm_field_configs'] = array('enc_key' => $this->config);
        $cipher = awm_encrypt_meta_on_save(1, 2, 'enc_key', 'plain');
        $this->assertStringStartsWith('ewp_enc:', $cipher);
        $this->assertSame($cipher, awm_encrypt_meta_on_save(1, 2, 'enc_key', $cipher));
        $this->assertSame('plain', awm_encrypt_meta_on_save(1, 2, 'unflagged', 'plain'));
        $this->assertSame('', awm_encrypt_meta_on_save(1, 2, 'enc_key', ''));
        $_POST['ewp_encrypt'] = array('unflagged');
        $this->assertStringStartsWith('ewp_enc:', awm_encrypt_meta_on_save(1, 2, 'unflagged', 'plain'));

        $this->assertSame('plain', awm_decrypt_meta_on_retrieve($cipher, 2, 'enc_key', true));
        $this->assertSame($cipher, awm_decrypt_meta_on_retrieve($cipher, 2, 'unflagged', true));
        $this->assertSame('', awm_decrypt_meta_on_retrieve('', 2, 'enc_key', true));
    }
}
