<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility facade for the logger REST layer.
 *
 * The `extend-wp/v1/logs*` routes are generated from
 * EWP\Surfaces\Resources\Logger_Resource since 1.5.0. This class only keeps
 * the filter-parameter registry entry point callers may still reach.
 *
 * @package    EWP\Logger
 * @author     Motivar
 *
 * @since 1.0.0
 */
class EWP_Logger_API
{
    /**
     * The recognised filter parameter names.
     *
     * The list and its `ewp_logger_filter_params` filter live in
     * EWP_Logger_Query::params().
     *
     * @return array List of recognised filter parameter names.
     *
     * @since 1.2.0
     */
    public static function get_filter_params()
    {
        return EWP_Logger_Query::params();
    }
}
