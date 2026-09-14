<?php

namespace EWP\SelfTest\Cases;

use EWP\Surfaces\EWP_Surfaces;
use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The editor preview route of a dynamic block (REST-only, one
 * Block_Preview_Resource per block) must render through the block's own
 * callback with the request parameters as attributes.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Block_Preview_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register a fixture block (selftest/echo) with a render callback that echoes its attributes.', 'extend-wp'),
            __('REST: GET /selftest/echo/preview?greeting=hi as an editor — expect 200 and the rendered markup; as a subscriber — expect 403.', 'extend-wp'),
        ];
    }

    /**
     * Render callback of the fixture block.
     *
     * @param array $attributes Block attributes.
     *
     * @return string
     */
    public function render_fixture(array $attributes)
    {
        return '<p class="selftest-echo">' . esc_html((string) ($attributes['greeting'] ?? '')) . '</p>';
    }

    /** {@inheritDoc} */
    public function run()
    {
        $block = [
            'namespace'       => 'selftest',
            'name'            => 'echo',
            'render_callback' => [$this, 'render_fixture'],
            'script'          => 'selftest-echo',
        ];
        EWP_Surfaces::instance()->register_block_preview_routes($block, \EWP_Dynamic_Blocks::instance());

        $o['editor'] = $this->rest('GET', '/selftest/echo/preview', ['greeting' => 'hi']);

        $user       = get_current_user_id();
        $subscriber = wp_insert_user(['user_login' => 'selftest_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        if (!is_wp_error($subscriber)) {
            wp_set_current_user($subscriber);
            $o['subscriber'] = $this->rest('GET', '/selftest/echo/preview', ['greeting' => 'hi']);
            wp_set_current_user($user);
        }

        return ['observed' => $o, 'subscriber' => is_wp_error($subscriber) ? 0 : $subscriber];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o = $context['observed'];

        $checks = [
            $this->check('rest', 'GET /{namespace}/{name}/preview renders the block with its attributes', $this->status($o, 'editor') === 200 && strpos((string) $o['editor']['data'], 'selftest-echo">hi<') !== false && strpos((string) $o['editor']['data'], 'data-block-script="selftest-echo"') !== false, $this->detail($o, 'editor')),
        ];
        if (isset($o['subscriber'])) {
            $checks[] = $this->check('rest', 'GET /{namespace}/{name}/preview needs edit_posts', $this->status($o, 'subscriber') === 403, $this->detail($o, 'subscriber'));
        }

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        if (!empty($context['subscriber'])) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($context['subscriber']);
        }

        return [__('Deleted the fixture subscriber; the fixture route lives only in this request.', 'extend-wp')];
    }
}
