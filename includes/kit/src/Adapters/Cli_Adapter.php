<?php
/**
 * Registers a Resource's operations as WP-CLI commands.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP\Adapters;

use Motivar\WP\Context;
use Motivar\WP\Field_Map;
use Motivar\WP\Operation;
use Motivar\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

final class Cli_Adapter
{
    /** @var Resource */
    private $resource;

    /**
     * @param Resource $resource Resource to expose.
     */
    public function __construct(Resource $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Register the commands once `init` has run (or now, if it already has).
     *
     * Operations may gate their surfaces on state that a module only knows
     * on `init`, so commands are added at priority 5 rather than at boot.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register()
    {
        if (!class_exists('WP_CLI') || !function_exists('add_action')) {
            return;
        }

        if (did_action('init')) {
            $this->register_commands();
            return;
        }

        add_action('init', [$this, 'register_commands'], 5);
    }

    /**
     * Register one command per CLI-enabled operation.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register_commands()
    {
        $base = $this->resource->cli_base();
        if (!class_exists('WP_CLI') || $base === null || $base === '') {
            return;
        }

        foreach ($this->resource->ops() as $operation) {
            $binding = $operation->cli_binding();
            if (!$operation->is_on(Context::CLI) || $binding === null) {
                continue;
            }

            $confirm = in_array(Context::CLI, $operation->confirm_surfaces(), true);

            \WP_CLI::add_command(
                trim($base . ' ' . $binding['name']),
                function ($args, $assoc_args) use ($operation) {
                    self::invoke($operation, (array) $args, (array) $assoc_args);
                },
                [
                    'shortdesc' => $operation->label_of(),
                    'longdesc'  => self::longdesc($operation, $confirm),
                    'synopsis'  => Field_Map::to_synopsis($operation->fields(), $confirm),
                ]
            );
        }
    }

    /**
     * Run an operation from CLI arguments and print the outcome.
     *
     * Public so shims and tests can call a command without WP-CLI's dispatcher.
     *
     * @param Operation $operation  Operation.
     * @param array     $args       Positional arguments.
     * @param array     $assoc_args Named arguments.
     *
     * @return mixed The operation result, after printing.
     *
     * @since 0.1.0
     */
    public static function invoke(Operation $operation, array $args, array $assoc_args)
    {
        $input = Field_Map::from_cli($operation->fields(), $args, $assoc_args);
        if (!empty($assoc_args['yes'])) {
            $input['confirm'] = true;
        }

        $result = $operation->run($input, Context::cli($args, $assoc_args));

        if ($result instanceof \WP_Error) {
            \WP_CLI::error($result->get_error_message());
            return $result;
        }

        self::present($operation, $result, $input, isset($assoc_args['format']) ? (string) $assoc_args['format'] : null);

        return $result;
    }

    /**
     * Print a result according to the operation's CLI hints.
     *
     * @param Operation   $operation Operation.
     * @param mixed       $result    Result.
     * @param array       $input     Input used.
     * @param string|null $format    Requested --format.
     *
     * @return void
     */
    private static function present(Operation $operation, $result, array $input, $format)
    {
        $hints = (array) $operation->cli_binding();

        if (isset($hints['presenter']) && is_callable($hints['presenter'])) {
            call_user_func($hints['presenter'], $result, $input, $format);
            return;
        }

        if (isset($hints['columns']) && is_array($hints['columns'])) {
            self::table($result, $hints['columns'], $format ?: 'table');
            return;
        }

        if (isset($hints['success'])) {
            \WP_CLI::success(self::render($hints['success'], $result, $input));
            return;
        }

        self::print_value($result, $format ?: (isset($hints['default_format']) ? $hints['default_format'] : 'json'));
    }

    /**
     * @param mixed    $result  Result holding rows (a list, or an array with `items`).
     * @param string[] $columns Keys to show, in order.
     * @param string   $format  Output format.
     *
     * @return void
     */
    private static function table($result, array $columns, $format)
    {
        $rows = is_array($result) && isset($result['items']) && is_array($result['items']) ? $result['items'] : (array) $result;

        if ($rows === []) {
            \WP_CLI::success('No items found.');
            return;
        }

        $display = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $line = [];
            foreach ($columns as $column) {
                $line[$column] = isset($row[$column]) && is_scalar($row[$column]) ? $row[$column] : '';
            }
            $display[] = $line;
        }

        \WP_CLI\Utils\format_items($format, $display, $columns);
    }

    /**
     * Replace `%key%` placeholders from the result, then the input.
     *
     * @param string $template Template.
     * @param mixed  $result   Result.
     * @param array  $input    Input.
     *
     * @return string
     */
    private static function render($template, $result, array $input)
    {
        $source = array_merge($input, is_array($result) ? $result : []);

        return preg_replace_callback('/%([a-z0-9_.]+)%/i', function ($match) use ($source) {
            $value = $source;
            foreach (explode('.', $match[1]) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return '';
                }
                $value = $value[$segment];
            }

            return is_scalar($value) ? (string) $value : '';
        }, (string) $template);
    }

    /**
     * @param mixed  $value  Value to print.
     * @param string $format Output format.
     *
     * @return void
     */
    private static function print_value($value, $format)
    {
        if (method_exists('WP_CLI', 'print_value')) {
            \WP_CLI::print_value($value, ['format' => $format]);
            return;
        }

        \WP_CLI::line(is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_PRETTY_PRINT));
    }

    /**
     * @param Operation $operation Operation.
     * @param bool      $confirm   Whether --yes applies.
     *
     * @return string `wp help` long description.
     */
    private static function longdesc(Operation $operation, $confirm)
    {
        $parts = [];
        if ($operation->description_of() !== '') {
            $parts[] = $operation->description_of();
            $parts[] = '';
        }
        $parts[] = Field_Map::describe_cli($operation->fields(), $confirm);

        return implode("\n", $parts);
    }
}
