<?php
/**
 * Explicit confirmation for destructive operations.
 *
 * Neither the Abilities API nor a bare REST call has a confirmation step,
 * so destructive operations may demand a literal `confirm: true`. On the
 * CLI the adapter maps `--yes` onto the same flag.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Confirm
{
    const FIELD = 'confirm';

    /**
     * Whether this surface must confirm the operation.
     *
     * @param Operation $op  Operation.
     * @param Context   $ctx Invocation context.
     *
     * @return bool
     *
     * @since 0.1.0
     */
    public static function required(Operation $op, Context $ctx)
    {
        return in_array($ctx->surface(), $op->confirm_surfaces(), true);
    }

    /**
     * Check the confirmation flag in the input.
     *
     * @param array $input Raw or normalised input.
     *
     * @return true|\WP_Error
     *
     * @since 0.1.0
     */
    public static function check(array $input)
    {
        if (!empty($input[self::FIELD]) && $input[self::FIELD] !== 'false') {
            return true;
        }

        return new \WP_Error(
            'mwp_confirm_required',
            'This action permanently changes data. Pass confirm: true (or --yes on the command line) to proceed.',
            ['status' => 400]
        );
    }

    /**
     * The confirmation field, for schemas and synopses.
     *
     * @return Field
     *
     * @since 0.1.0
     */
    public static function field()
    {
        return Field::bool(self::FIELD)->describe('Must be true. Guards against accidental destructive calls.');
    }
}
