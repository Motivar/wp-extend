<?php
/**
 * Lazy `class_alias()` shims for the pre-1.0 `Gnnpls\WP\*` class names.
 *
 * @package MammothWP\WpKit
 * @since   1.0.0
 */

namespace MammothWP\WpKit\Compat;

if (!defined('ABSPATH')) {
    exit;
}

final class Legacy_Aliases
{
    /**
     * Old namespace prefix.
     *
     * @var string
     */
    const OLD_PREFIX = 'Gnnpls\\WP\\';

    /**
     * New namespace prefix.
     *
     * @var string
     */
    const NEW_PREFIX = 'MammothWP\\WpKit\\';

    /**
     * Every class that was public in 0.x, relative to the namespace root.
     *
     * @var string[]
     */
    const CLASSES = [
        'Kit',
        'Registry',
        'Resource',
        'Operation',
        'Field',
        'Field_Map',
        'Context',
        'Capability',
        'Confirm',
        'Inventory',
        'Adapters\\Rest_Adapter',
        'Adapters\\Cli_Adapter',
        'Adapters\\Ability_Adapter',
    ];

    /**
     * Whether the autoloader is installed.
     *
     * @var bool
     */
    private static $registered = false;

    /**
     * Install the autoloader (idempotent).
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * Alias an old class name to the new one the first time it is used.
     *
     * The autoloader fires once per class per request, so the deprecation
     * notice is naturally emitted once.
     *
     * @param string $class Requested class name.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function autoload($class)
    {
        $new = self::new_name($class);
        if ($new === null || !(class_exists($new) || interface_exists($new) || trait_exists($new))) {
            return;
        }

        class_alias($new, $class);
        self::notify($class, $new);
    }

    /**
     * New name for an old class name, or null when it is not a legacy class.
     *
     * @param string $class Fully qualified class name.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public static function new_name($class)
    {
        $class = ltrim((string) $class, '\\');
        if (strpos($class, self::OLD_PREFIX) !== 0) {
            return null;
        }

        $relative = substr($class, strlen(self::OLD_PREFIX));
        return in_array($relative, self::CLASSES, true) ? self::NEW_PREFIX . $relative : null;
    }

    /**
     * Emit the core deprecation notice when WordPress provides one.
     *
     * @param string $old Old class name.
     * @param string $new Replacement class name.
     *
     * @return void
     *
     * @since 1.0.0
     */
    private static function notify($old, $new)
    {
        if (function_exists('_deprecated_class')) {
            _deprecated_class($old, '1.0.0', $new);
        }
    }
}
