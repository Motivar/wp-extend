<?php

/**
 * Field engine: save services behind the procedural awm_* helpers in
 * includes/functions/library.php. Required by ../init.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-meta-saver.php';
require_once __DIR__ . '/class-translation-sync.php';
