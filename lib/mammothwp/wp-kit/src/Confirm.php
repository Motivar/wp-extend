<?php
/**
 * Explicit confirmation for destructive operations.
 *
 * Neither the Abilities API nor a bare REST call has a confirmation step,
 * so destructive operations may demand a literal `confirm: true`. On the
 * CLI the adapter maps `--yes` onto the same flag.
 *
 * @package MammothWP\WpKit
 * @since   0.1.0
 */

namespace MammothWP\WpKit;

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
     * The flag is coerced like any boolean field, so only `true`, `1`,
     * `"true"`, `"yes"` or `"on"` confirm; `"no"`, `"off"` and arbitrary
     * strings do not.
     *
     * @param array $input Raw or normalised input.
     *
     * @return true|\WP_Error
     *
     * @since 0.1.0
     */
    public static function check(array $input)
    {
        $flag = isset($input[self::FIELD]) ? self::field()->normalize($input[self::FIELD]) : null;
        if ($flag === true) {
            return true;
        }

        return new \WP_Error(
            'mmwp_confirm_required',
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
