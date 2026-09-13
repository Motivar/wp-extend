<?php
/**
 * Resolves and enforces the capability an operation requires.
 *
 * A resolver may be `true` (public), `false` (never), a capability string,
 * or a callable `fn(Operation $op, array $input, Context $ctx)` returning
 * one of those. The result passes through `mwp_operation_capability` so a
 * host plugin can override it centrally.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Capability
{
    /**
     * Resolve the declared capability for this call.
     *
     * @param mixed     $resolver Declared resolver.
     * @param Operation $op       Operation.
     * @param array     $input    Normalised input.
     * @param Context   $ctx      Invocation context.
     *
     * @return bool|string
     *
     * @since 0.1.0
     */
    public static function resolve($resolver, Operation $op, array $input, Context $ctx)
    {
        $resolved = is_callable($resolver) && !is_string($resolver)
            ? call_user_func($resolver, $op, $input, $ctx)
            : $resolver;

        if (function_exists('apply_filters')) {
            /**
             * Filter the capability required by an operation.
             *
             * @param bool|string $resolved Capability string, true (public) or false (denied).
             * @param Operation   $op       Operation being authorised.
             * @param array       $input    Normalised input.
             * @param Context     $ctx      Invocation context.
             *
             * @since 0.1.0
             */
            $resolved = apply_filters('mwp_operation_capability', $resolved, $op, $input, $ctx);
        }

        return $resolved;
    }

    /**
     * Enforce a resolved capability.
     *
     * @param bool|string $resolved Resolved capability.
     * @param Context     $ctx      Invocation context.
     *
     * @return true|\WP_Error
     *
     * @since 0.1.0
     */
    public static function check($resolved, Context $ctx)
    {
        if ($resolved === true) {
            return true;
        }

        if ($resolved === false || $resolved === '' || $resolved === null) {
            return self::forbidden();
        }

        if ($ctx->is_unattended_cli()) {
            return true;
        }

        if (function_exists('current_user_can') && current_user_can((string) $resolved)) {
            return true;
        }

        return self::forbidden();
    }

    /**
     * 401 for anonymous callers, 403 for an authenticated user who lacks
     * the capability, matching WordPress REST conventions.
     *
     * @return \WP_Error
     */
    private static function forbidden()
    {
        $anonymous = function_exists('is_user_logged_in') && !is_user_logged_in();

        return new \WP_Error(
            'mwp_forbidden',
            'You do not have permission to perform this action.',
            ['status' => $anonymous ? 401 : 403]
        );
    }
}
