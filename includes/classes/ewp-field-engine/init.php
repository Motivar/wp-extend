<?php

/**
 * Field engine: OOP renderers behind awm_show_content(). Docs: README.md.
 *
 * Required by includes/functions/library.php (same copy of the plugin), since
 * Composer may load library.php from another bundled copy of Extend WP; the
 * first copy to load the engine wins.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('EWP\Fields\Field_Registry', false)) {
    return;
}

require_once __DIR__ . '/class-field-context.php';
require_once __DIR__ . '/class-field-output.php';
require_once __DIR__ . '/interface-field-type.php';
require_once __DIR__ . '/class-field-attributes.php';
require_once __DIR__ . '/class-template-loader.php';
require_once __DIR__ . '/types/class-input-field.php';
require_once __DIR__ . '/types/class-textarea-field.php';
require_once __DIR__ . '/types/class-select-field.php';
require_once __DIR__ . '/types/class-radio-field.php';
require_once __DIR__ . '/types/class-checkbox-multiple-field.php';
require_once __DIR__ . '/class-field-registry.php';
