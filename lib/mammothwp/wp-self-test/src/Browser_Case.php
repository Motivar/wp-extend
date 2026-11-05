<?php
/**
 * Ready-made case class for manifest entries that only declare `browser`
 * checks: it has no PHP phases, the runner adds the browser checks.
 *
 *     {"id": "admin-smoke", "label": "Admin smoke", "class": "MammothWP\\SelfTest\\Browser_Case",
 *      "layers": ["browser"], "browser": {"crawl": true}}
 *
 * @package MammothWP\SelfTest
 * @since   2.1.0
 */

namespace MammothWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Browser_Case extends Case_Base
{
    /**
     * Steps shown in previews.
     *
     * @return string[]
     *
     * @since 2.1.0
     */
    public function preview()
    {
        return $this->browser_steps();
    }

    /**
     * Nothing to do in PHP.
     *
     * @return array
     *
     * @since 2.1.0
     */
    public function run()
    {
        return [];
    }

    /**
     * No PHP checks; the runner appends the browser checks.
     *
     * @param array $context Value returned by run().
     *
     * @return array[]
     *
     * @since 2.1.0
     */
    public function validate(array $context)
    {
        unset($context);

        return [];
    }
}
