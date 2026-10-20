<?php
/**
 * Detects pre-1.0 (gnnpls/wp-self-test) code that is still active.
 *
 * @package MammothWP\SelfTest
 * @since   1.0.0
 */

namespace MammothWP\SelfTest\Compat;

if (!defined('ABSPATH')) {
    exit;
}

final class Legacy
{
    /** Pre-1.0 namespace root. */
    const NAMESPACE_LEGACY = 'Gnnpls\\SelfTest\\';

    /**
     * Whether another plugin's old copy of the package declares this class
     * for real (not as an alias of ours), so that copy registers its own
     * surface and ours must not register a duplicate.
     *
     * @param string $suffix Class name below the old root, e.g. `Surfaces\Cli`.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function owned_elsewhere($suffix)
    {
        $legacy = self::NAMESPACE_LEGACY . $suffix;
        if (!class_exists($legacy, false)) {
            return false;
        }

        return (new \ReflectionClass($legacy))->getName() === $legacy;
    }
}
