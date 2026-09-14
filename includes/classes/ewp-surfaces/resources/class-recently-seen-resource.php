<?php

namespace EWP\Surfaces\Resources;

use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The front-end "recently seen" recorder (`POST ewp/v1/recently-seen/{id}`)
 * as a REST-only resource over EWP_Recently_Seen_UTIL.
 *
 * Deliberately anonymous by default: the public script records views for
 * visitors who are not logged in. It only accepts the id of a published
 * post and writes nothing but the visitor's own session. The route exists
 * only while post types are configured for the feature.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Recently_Seen_Resource extends Resource
{
    /** @var \EWP_Recently_Seen_UTIL */
    private $service;

    /**
     * @param \EWP_Recently_Seen_UTIL $service Recently-seen service.
     */
    public function __construct(\EWP_Recently_Seen_UTIL $service)
    {
        $this->service = $service;
    }

    /** {@inheritDoc} */
    public function name()
    {
        return 'recently-seen';
    }

    /** {@inheritDoc} */
    public function label()
    {
        return __('Recently seen', 'extend-wp');
    }

    /** {@inheritDoc} */
    public function service()
    {
        return $this->service;
    }

    /** {@inheritDoc} */
    public function rest_namespace()
    {
        return 'ewp/v1';
    }

    /**
     * A front-end recorder only, and only while the feature is configured.
     *
     * @return string[]
     */
    public function surfaces()
    {
        $service = $this->service;

        return function () use ($service) {
            return $service->post_types() ? [Context::REST] : [];
        };
    }

    /**
     * @return string
     */
    public function surfaces_reason()
    {
        return __('anonymous front-end view recorder; a command or ability has no visitor session to write to', 'extend-wp');
    }

    /**
     * Public by default (filter `ewp_recently_seen_public`), else any logged-in user.
     *
     * @return callable
     */
    public function capability()
    {
        return function () {
            /**
             * Whether the recently-seen route accepts anonymous requests.
             *
             * @param bool $public Default true; return false to require a logged-in user.
             *
             * @since 1.5.0
             */
            return apply_filters('ewp_recently_seen_public', true) ? true : is_user_logged_in();
        };
    }

    /** {@inheritDoc} */
    public function operations()
    {
        return [
            'record' => Operation::write('record')
                ->annotations(['idempotent' => true])
                ->label(__('Record a viewed post', 'extend-wp'))
                ->description(__('Add a published post to the visitor\'s recently-seen list, kept in their PHP session per post type.', 'extend-wp'))
                ->input([
                    Field::int('id')->required()->min(1)
                        ->describe(__('The id of a published post.', 'extend-wp'))
                        ->validate_with(function ($id) {
                            return get_post_status((int) $id) === 'publish';
                        }),
                ])
                ->output(['type' => 'boolean'])
                ->rest('POST', '(?P<id>\d+)'),
        ];
    }
}
