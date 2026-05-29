<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WP Rocket compatibility class
 *
 * Keeps the EWP dynamic asset loader out of WP Rocket's JavaScript
 * optimizations (delay, defer, minify/combine), so it stays available to
 * bootstrap module loading. Webpack chunks resolve via a server-provided
 * publicPath (awmGlobals.buildUrl) and are injected at runtime, so they are
 * never seen by WP Rocket and need no exclusion.
 *
 * Works regardless of the plugin's install path — the exclusion
 * pattern uses filename matching, so it applies whether extend-wp
 * is installed standalone or embedded inside another plugin.
 *
 * @since 1.0.0
 */
class EWP_WP_Rocket
{
	/**
	 * Scripts to exclude from WP Rocket optimizations (filenames only).
	 *
	 * @var array
	 */
	private static $default_exclusions = array(
		// Matches both the external entry script (build/global/awm-global-script.js)
		// and its inline localized data tag (id="awm-global-script-js-extra", which
		// defines awmGlobals). Both must run on page load for modules to initialize.
		'awm-global-script',
		'class-dynamic-asset-loader',
	);

	/**
	 * Constructor — registers WP Rocket exclusion filters.
	 *
	 * No WP_ROCKET_VERSION guard: these hooks only ever fire when WP Rocket is
	 * running, so the filters are harmless no-ops otherwise. (A guard here would
	 * also be wrong — this class is instantiated at plugin-load time, before
	 * wp-rocket defines its constant, since EWP loads first alphabetically.)
	 *
	 * @return void
	 */
	public function __construct()
	{
		add_filter('rocket_delay_js_exclusions', array($this, 'exclude_from_delay_js'));
		add_filter('rocket_exclude_js', array($this, 'exclude_from_minify_combine'));
		add_filter('rocket_defer_js_exclusions', array($this, 'exclude_from_defer'));
	}

	/**
	 * Return the merged exclusions list (defaults + developer additions).
	 *
	 * Developers can extend the list via the `ewp_wp_rocket_js_exclusions` filter.
	 *
	 * @example
	 * add_filter( 'ewp_wp_rocket_js_exclusions', function ( $patterns ) {
	 *     $patterns[] = 'my-critical-script.js';
	 *     return $patterns;
	 * } );
	 *
	 * @return array File-name patterns to exclude.
	 */
	private static function get_exclusions()
	{
		/**
		 * Filter the list of JS filename patterns excluded from WP Rocket.
		 *
		 * @since 1.0.0
		 *
		 * @param array $patterns Default filename patterns (e.g. 'class-dynamic-asset-loader.js').
		 */
		return apply_filters('ewp_wp_rocket_js_exclusions', self::$default_exclusions);
	}

	/**
	 * Exclude scripts from WP Rocket Delay JavaScript Execution.
	 *
	 * @param array $exclusions Current delay JS exclusions.
	 * @return array Modified exclusions.
	 */
	public function exclude_from_delay_js($exclusions)
	{
		return $this->merge_exclusions($exclusions);
	}

	/**
	 * Exclude scripts from WP Rocket JS minification and combination.
	 *
	 * @param array $exclusions Current minify/combine exclusions.
	 * @return array Modified exclusions.
	 */
	public function exclude_from_minify_combine($exclusions)
	{
		return $this->merge_exclusions($exclusions);
	}

	/**
	 * Exclude scripts from WP Rocket Defer JavaScript.
	 *
	 * @param array $exclusions Current defer exclusions.
	 * @return array Modified exclusions.
	 */
	public function exclude_from_defer($exclusions)
	{
		return $this->merge_exclusions($exclusions);
	}

	/**
	 * Merge EWP exclusion patterns into an existing WP Rocket exclusions array.
	 *
	 * Avoids duplicates by checking whether each pattern already exists.
	 *
	 * @param array $exclusions Existing WP Rocket exclusions.
	 * @return array Merged exclusions.
	 */
	private function merge_exclusions($exclusions)
	{
		$ewp_patterns = self::get_exclusions();

		foreach ($ewp_patterns as $pattern) {
			if (!in_array($pattern, $exclusions, true)) {
				$exclusions[] = $pattern;
			}
		}

		return $exclusions;
	}
}

new EWP_WP_Rocket();