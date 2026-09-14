<?php
if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('WP_CLI')) {
	return;
}

/**
 * `wp ewp options *` entry points.
 *
 * The commands are declared once in EWP\Surfaces\Resources\Options_Resource
 * and registered by the kit's Cli_Adapter, together with the matching REST
 * routes and `ewp-options/*` abilities. This class only keeps the static
 * callables the self-test suite invokes in-process.
 *
 * @package    EWP\OptionsPortability
 * @author     Motivar
 *
 * @since 1.0.0
 */
class EWP_Options_Portability_CLI
{
	/**
	 * Kept for callers that used to register the commands here.
	 *
	 * @param EWP_Options_Portability|null $portability Unused.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function init($portability = null)
	{
	}

	/** @see Options_Resource `export` */
	public static function export($args, $assoc_args)
	{
		return self::run('export', $args, $assoc_args);
	}

	/** @see Options_Resource `import` */
	public static function import_options($args, $assoc_args)
	{
		return self::run('import', $args, $assoc_args);
	}

	/** @see Options_Resource `pages` */
	public static function list_pages($args, $assoc_args)
	{
		return self::run('pages', $args, $assoc_args);
	}

	/**
	 * @param string $operation  Operation key on the `options` resource.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Named arguments.
	 * @return mixed
	 *
	 * @since 1.5.0
	 */
	private static function run($operation, $args, $assoc_args)
	{
		$registry = class_exists('EWP\\Surfaces\\EWP_Surfaces') ? \EWP\Surfaces\EWP_Surfaces::instance()->registry() : null;
		$found    = $registry ? $registry->find('options', $operation) : null;

		if ($found === null) {
			\WP_CLI::error('The options commands are unavailable: the Extend WP surfaces did not boot.');
			return null;
		}

		return \Gnnpls\WP\Adapters\Cli_Adapter::invoke($found, (array) $args, (array) $assoc_args);
	}
}
