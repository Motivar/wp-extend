<?php
/**
 * Describes which surface is invoking an operation and as whom.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Context
{
    const REST    = 'rest';
    const CLI     = 'cli';
    const ABILITY = 'ability';

    /** @var string */
    private $surface;
    /** @var mixed */
    private $raw;
    /** @var int */
    private $user_id;

    /**
     * @param string $surface One of the surface constants.
     * @param mixed  $raw     Surface-specific raw request data.
     */
    private function __construct($surface, $raw)
    {
        $this->surface = $surface;
        $this->raw     = $raw;
        $this->user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    /**
     * @param \WP_REST_Request $request REST request.
     *
     * @return Context
     */
    public static function rest($request)
    {
        return new self(self::REST, $request);
    }

    /**
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return Context
     */
    public static function cli(array $args, array $assoc_args)
    {
        return new self(self::CLI, ['args' => $args, 'assoc_args' => $assoc_args]);
    }

    /**
     * @param mixed $input Ability input.
     *
     * @return Context
     */
    public static function ability($input)
    {
        return new self(self::ABILITY, $input);
    }

    /**
     * Build a context for direct PHP calls and tests.
     *
     * @param string $surface Surface name.
     * @param mixed  $raw     Raw payload.
     *
     * @return Context
     */
    public static function make($surface, $raw = null)
    {
        return new self((string) $surface, $raw);
    }

    /** @return string */
    public function surface()
    {
        return $this->surface;
    }

    /** @return mixed */
    public function raw()
    {
        return $this->raw;
    }

    /** @return int */
    public function user_id()
    {
        return $this->user_id;
    }

    /**
     * A WP-CLI run with no --user: the shell operator is trusted as an admin.
     *
     * @return bool
     */
    public function is_unattended_cli()
    {
        return $this->surface === self::CLI && $this->user_id === 0;
    }
}
