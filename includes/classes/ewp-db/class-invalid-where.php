<?php
/**
 * Exception for a WHERE that cannot be built safely.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by Where in strict mode (writes) and by Sql for invalid statements,
 * so an UPDATE or DELETE never runs with a dropped or widened condition.
 */
class Invalid_Where extends \InvalidArgumentException
{
}
