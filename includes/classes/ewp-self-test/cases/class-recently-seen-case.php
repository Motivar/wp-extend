<?php

namespace EWP\SelfTest\Cases;

use EWP\Surfaces\EWP_Surfaces;
use Gnnpls\SelfTest\Case_Base;
use Gnnpls\WP\Adapters\Rest_Adapter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The anonymous recently-seen recorder (REST-only, generated from
 * Recently_Seen_Resource) must accept a published post and reject anything else.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Recently_Seen_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Enable the feature for posts via ewp_recently_seen_post_types_filter and register its route now.', 'extend-wp'),
            __('Create a published and a draft post.', 'extend-wp'),
            __('REST: POST /ewp/v1/recently-seen/{published} as an anonymous visitor — expect 200 true; POST with the draft — expect 400.', 'extend-wp'),
            __('Delete both posts in cleanup.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $enable = function () {
            return ['post'];
        };
        add_filter('ewp_recently_seen_post_types_filter', $enable);

        $registry = EWP_Surfaces::instance()->registry();
        $resource = $registry ? ($registry->all()['recently-seen'] ?? null) : null;
        if ($resource) {
            (new Rest_Adapter($resource))->register_routes();
        }

        $published = wp_insert_post(['post_title' => 'Self-test seen', 'post_status' => 'publish', 'post_type' => 'post']);
        $draft     = wp_insert_post(['post_title' => 'Self-test draft', 'post_status' => 'draft', 'post_type' => 'post']);

        $user = get_current_user_id();
        wp_set_current_user(0);
        $o['record']  = $this->rest('POST', '/ewp/v1/recently-seen/' . $published);
        $o['draft']   = $this->rest('POST', '/ewp/v1/recently-seen/' . $draft);
        wp_set_current_user($user);

        $o['session'] = isset($_SESSION['ewp_recently_seen']['post']) ? (array) $_SESSION['ewp_recently_seen']['post'] : [];

        remove_filter('ewp_recently_seen_post_types_filter', $enable);

        return ['observed' => $o, 'posts' => [$published, $draft], 'resource' => (bool) $resource];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o  = $context['observed'];
        $id = $context['posts'][0];

        return [
            $this->check('rest', 'the recently-seen resource is registered', !empty($context['resource'])),
            $this->check('rest', 'POST /recently-seen/{id} records a published post anonymously', $this->status($o, 'record') === 200 && $o['record']['data'] === true && in_array($id, $o['session'], true), $this->detail($o, 'record')),
            $this->check('rest', 'POST /recently-seen/{id} rejects a draft', $this->status($o, 'draft') === 400, $this->detail($o, 'draft')),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        foreach ((array) ($context['posts'] ?? []) as $id) {
            if ($id) {
                wp_delete_post($id, true);
            }
        }
        unset($_SESSION['ewp_recently_seen']);

        return [__('Deleted the fixture posts and the session entry.', 'extend-wp')];
    }
}
