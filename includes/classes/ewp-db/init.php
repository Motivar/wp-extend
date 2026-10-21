<?php
/**
 * Loads the EWP\Db classes (prepared-statement layer behind AWM_DB_Creator).
 *
 * @package extend-wp
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-invalid-where.php';
require_once __DIR__ . '/class-table-columns.php';
require_once __DIR__ . '/class-legacy-in-list.php';
require_once __DIR__ . '/class-where.php';
require_once __DIR__ . '/class-sql.php';
require_once __DIR__ . '/class-shadow.php';
