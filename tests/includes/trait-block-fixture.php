<?php
/**
 * Shared helpers for tests that declare blocks through
 * `ewp_gutenburg_blocks_filter`: register a set of block definitions, count
 * how often option callbacks run, and clean the block type registry.
 */

trait EWP_Block_Fixture
{
    /** @var array<string,int> Option callback runs, keyed by callback name. */
    protected static $callback_calls = [];

    /** @var array Block definitions the current test registers. */
    private $fixture_blocks = [];

    /** @var callable|null The filter added by use_blocks(). */
    private $fixture_filter = null;

    /**
     * Make `ewp_gutenburg_blocks_filter` return these blocks (and only these)
     * and forget anything EWP_Dynamic_Blocks gathered before.
     *
     * @param array $blocks Block id => definition.
     * @return void
     */
    protected function use_blocks(array $blocks)
    {
        $this->drop_blocks();
        $this->fixture_blocks = $blocks;
        $this->fixture_filter = function () {
            return $this->fixture_blocks;
        };
        add_filter('ewp_gutenburg_blocks_filter', $this->fixture_filter, PHP_INT_MAX);
        EWP_Dynamic_Blocks::reset();
    }

    /**
     * Remove the fixture filter and unregister the fixture block types.
     *
     * @return void
     */
    protected function drop_blocks()
    {
        if ($this->fixture_filter !== null) {
            remove_filter('ewp_gutenburg_blocks_filter', $this->fixture_filter, PHP_INT_MAX);
        }
        $registry = WP_Block_Type_Registry::get_instance();
        foreach ($this->fixture_blocks as $block) {
            $name = $block['namespace'] . '/' . $block['name'];
            if ($registry->is_registered($name)) {
                $registry->unregister($name);
            }
        }
        $this->fixture_blocks = [];
        $this->fixture_filter = null;
        EWP_Dynamic_Blocks::reset();
    }

    /**
     * A callback that records its runs and returns the given options.
     *
     * @param string $name    Counter name.
     * @param array  $options Options to return.
     * @return callable
     */
    protected static function counting_callback($name, array $options = ['a' => ['label' => 'A']])
    {
        self::$callback_calls[$name] = 0;
        return function () use ($name, $options) {
            self::$callback_calls[$name]++;
            return $options;
        };
    }

    /**
     * Runs of a counting callback.
     *
     * @param string $name Counter name.
     * @return int
     */
    protected static function calls($name)
    {
        return isset(self::$callback_calls[$name]) ? self::$callback_calls[$name] : 0;
    }

    /**
     * Pretend this is a wp-admin request (is_admin() true) on a list screen.
     *
     * @param string $screen Screen id.
     * @return void
     */
    protected function as_admin_screen($screen = 'edit-post')
    {
        set_current_screen($screen);
    }
}
