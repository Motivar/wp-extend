<?php
/**
 * Projects Field declarations onto the three surfaces.
 *
 * One Field becomes a REST `args` entry, a WP-CLI synopsis item (plus the
 * `## OPTIONS` text for `wp help`), and a JSON Schema property. The mapping
 * lives here so the surfaces can never disagree about a parameter.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Field_Map
{
    /* ---------------------------------------------------------------------
     * REST
     * ------------------------------------------------------------------ */

    /**
     * Build the `args` array for register_rest_route().
     *
     * @param Field[] $fields Fields to project.
     *
     * @return array<string,array>
     *
     * @since 0.1.0
     */
    public static function to_rest_args(array $fields)
    {
        $args = [];

        foreach ($fields as $field) {
            $arg = [
                'type'              => self::json_type($field),
                'required'          => $field->is_required(),
                'description'       => $field->description(),
                'sanitize_callback' => self::rest_sanitizer($field),
                'validate_callback' => self::rest_validator($field),
            ];

            if ($field->has_default()) {
                $arg['default'] = $field->default_of();
            }

            $args[$field->name()] = array_merge($arg, self::constraints($field));
        }

        return $args;
    }

    /* ---------------------------------------------------------------------
     * JSON Schema (abilities)
     * ------------------------------------------------------------------ */

    /**
     * Build an object schema for an ability input.
     *
     * @param Field[] $fields Fields to project.
     *
     * @return array
     *
     * @since 0.1.0
     */
    public static function to_json_schema(array $fields)
    {
        $properties = [];
        $required   = [];

        foreach ($fields as $field) {
            $properties[$field->name()] = self::to_json_property($field);
            if ($field->is_required()) {
                $required[] = $field->name();
            }
        }

        $schema = [
            'type'       => 'object',
            'default'    => [],
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Build one JSON Schema property.
     *
     * @param Field $field Field to project.
     *
     * @return array
     *
     * @since 0.1.0
     */
    public static function to_json_property(Field $field)
    {
        $property = ['type' => self::json_type($field)];

        if ($field->description() !== '') {
            $property['description'] = $field->description();
        }
        if ($field->has_default()) {
            $property['default'] = $field->default_of();
        }

        return array_merge($property, self::constraints($field));
    }

    /* ---------------------------------------------------------------------
     * WP-CLI
     * ------------------------------------------------------------------ */

    /**
     * Build the synopsis array WP_CLI::add_command() accepts.
     *
     * @param Field[] $fields  Fields to project.
     * @param bool    $confirm Whether to append a --yes flag.
     *
     * @return array<int,array>
     *
     * @since 0.1.0
     */
    public static function to_synopsis(array $fields, $confirm = false)
    {
        $synopsis = [];

        foreach ($fields as $field) {
            $synopsis[] = self::synopsis_item($field);
        }

        if ($confirm) {
            $synopsis[] = [
                'type'        => 'flag',
                'name'        => 'yes',
                'optional'    => true,
                'description' => 'Skip the confirmation for this destructive command.',
            ];
        }

        $synopsis[] = [
            'type'        => 'assoc',
            'name'        => 'format',
            'optional'    => true,
            'description' => 'Output format.',
            'options'     => ['table', 'json', 'csv', 'yaml'],
        ];

        return $synopsis;
    }

    /**
     * Build the `## OPTIONS` section for `wp help`.
     *
     * @param Field[] $fields  Fields to describe.
     * @param bool    $confirm Whether the command accepts --yes.
     *
     * @return string
     *
     * @since 0.1.0
     */
    public static function describe_cli(array $fields, $confirm = false)
    {
        $lines = ['## OPTIONS', ''];

        foreach (self::to_synopsis($fields, $confirm) as $item) {
            $lines[] = self::synopsis_token($item);
            $lines[] = ': ' . ($item['description'] !== '' ? $item['description'] : 'No description.');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Turn WP-CLI positional and named arguments into an input array.
     *
     * Positional fields consume `$args` in declaration order; a positional
     * list field consumes every remaining argument. Named arguments use the
     * dashed form of the field name (`with_meta` becomes `--with-meta`).
     *
     * @param Field[] $fields     Fields to read.
     * @param array   $args       Positional arguments.
     * @param array   $assoc_args Named arguments.
     *
     * @return array Raw input keyed by field name.
     *
     * @since 0.1.0
     */
    public static function from_cli(array $fields, array $args, array $assoc_args)
    {
        $input = [];
        $args  = array_values($args);

        foreach ($fields as $field) {
            if ($field->is_positional()) {
                $input[$field->name()] = self::take_positional($field, $args);
                continue;
            }

            $key = self::cli_key($field);
            if (!array_key_exists($key, $assoc_args)) {
                continue;
            }

            $input[$field->name()] = $field->type() === Field::T_BOOL && $assoc_args[$key] === true
                ? true
                : $assoc_args[$key];
        }

        return $input;
    }

    /**
     * Dashed CLI name of a field.
     *
     * @param Field $field Field.
     *
     * @return string
     */
    public static function cli_key(Field $field)
    {
        $custom = $field->cli_name_of();

        return $custom !== null && $custom !== '' ? $custom : str_replace('_', '-', $field->name());
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @param Field $field Field.
     *
     * @return string|string[] JSON Schema type.
     */
    private static function json_type(Field $field)
    {
        switch ($field->type()) {
            case Field::T_INT:
                return 'integer';
            case Field::T_BOOL:
                return 'boolean';
            case Field::T_OBJECT:
                return 'object';
            case Field::T_INT_LIST:
            case Field::T_ARRAY:
                return ['array', 'string'];
            case Field::T_ENUM:
                return $field->is_multiple() ? ['array', 'string'] : 'string';
        }

        return 'string';
    }

    /**
     * Enum, bounds, items and nested properties shared by REST and JSON Schema.
     *
     * @param Field $field Field.
     *
     * @return array
     */
    private static function constraints(Field $field)
    {
        $out = [];

        if ($field->type() === Field::T_ENUM) {
            $choices = $field->choices();
            if ($choices !== []) {
                $out[$field->is_multiple() ? 'items' : 'enum'] = $field->is_multiple()
                    ? ['type' => 'string', 'enum' => $choices]
                    : $choices;
            }
        }

        if ($field->type() === Field::T_INT_LIST) {
            $out['items'] = ['type' => 'integer'];
        }

        if ($field->type() === Field::T_ARRAY) {
            $out['items'] = ['type' => $field->items_type()];
        }

        if ($field->type() === Field::T_INT) {
            if ($field->min_of() !== null) {
                $out['minimum'] = $field->min_of();
            }
            if ($field->max_of() !== null) {
                $out['maximum'] = $field->max_of();
            }
        }

        if ($field->type() === Field::T_STRING && $field->max_length_of() !== null) {
            $out['maxLength'] = $field->max_length_of();
        }

        if ($field->type() === Field::T_OBJECT) {
            $out['additionalProperties'] = true;
            if ($field->properties_of() !== []) {
                $out['properties'] = [];
                foreach ($field->properties_of() as $child) {
                    $out['properties'][$child->name()] = self::to_json_property($child);
                }
            }
        }

        return $out;
    }

    /**
     * @param Field $field Field.
     *
     * @return callable
     */
    private static function rest_sanitizer(Field $field)
    {
        return function ($value) use ($field) {
            $normalized = $field->normalize($value);

            return $normalized instanceof \WP_Error ? $value : $normalized;
        };
    }

    /**
     * @param Field $field Field.
     *
     * @return callable
     */
    private static function rest_validator(Field $field)
    {
        return function ($value) use ($field) {
            $normalized = $field->normalize($value);
            if ($normalized instanceof \WP_Error) {
                return $normalized;
            }

            return $field->validate($normalized);
        };
    }

    /**
     * @param Field $field Field.
     *
     * @return array WP-CLI synopsis item.
     */
    private static function synopsis_item(Field $field)
    {
        $is_list = in_array($field->type(), [Field::T_INT_LIST, Field::T_ARRAY], true) || $field->is_multiple();

        if ($field->is_positional()) {
            return [
                'type'        => 'positional',
                'name'        => $field->name(),
                'optional'    => !$field->is_required(),
                'repeating'   => $is_list,
                'description' => $field->description(),
            ];
        }

        $item = [
            'type'        => $field->type() === Field::T_BOOL ? 'flag' : 'assoc',
            'name'        => self::cli_key($field),
            'optional'    => !$field->is_required(),
            'description' => $field->description(),
        ];

        if ($field->type() === Field::T_ENUM && $field->choices() !== []) {
            $item['options'] = $field->choices();
        }

        return $item;
    }

    /**
     * @param array $item Synopsis item.
     *
     * @return string Token as shown in `wp help`.
     */
    private static function synopsis_token(array $item)
    {
        if ($item['type'] === 'positional') {
            $token = '<' . $item['name'] . '>' . (!empty($item['repeating']) ? '...' : '');
        } elseif ($item['type'] === 'flag') {
            $token = '--' . $item['name'];
        } else {
            $value = isset($item['options']) ? implode('|', $item['options']) : $item['name'];
            $token = '--' . $item['name'] . '=<' . $value . '>';
        }

        return !empty($item['optional']) ? '[' . $token . ']' : $token;
    }

    /**
     * @param Field $field Positional field.
     * @param array $args  Remaining positional args (consumed in place).
     *
     * @return mixed
     */
    private static function take_positional(Field $field, array &$args)
    {
        $is_list = in_array($field->type(), [Field::T_INT_LIST, Field::T_ARRAY], true) || $field->is_multiple();

        if ($is_list) {
            $taken = $args;
            $args  = [];
            return $taken;
        }

        return $args === [] ? null : array_shift($args);
    }
}
