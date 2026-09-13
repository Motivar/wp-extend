<?php
/**
 * One input parameter, declared once and projected onto REST args, a
 * WP-CLI synopsis and a JSON Schema property by Field_Map.
 *
 * Fields are value objects: every fluent setter returns a modified clone.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Field
{
    const T_STRING   = 'string';
    const T_INT      = 'int';
    const T_BOOL     = 'bool';
    const T_ENUM     = 'enum';
    const T_OBJECT   = 'object';
    const T_INT_LIST = 'int_list';
    const T_ARRAY    = 'array';
    const T_CUSTOM   = 'custom';

    /** @var string */
    private $name;
    /** @var string */
    private $type;
    /** @var bool */
    private $required = false;
    /** @var bool */
    private $has_default = false;
    /** @var mixed */
    private $default = null;
    /** @var int|float|null */
    private $min = null;
    /** @var int|float|null */
    private $max = null;
    /** @var int|null */
    private $max_length = null;
    /** @var string */
    private $description = '';
    /** @var array|callable|null */
    private $choices = null;
    /** @var callable|null */
    private $validator = null;
    /** @var callable|null */
    private $sanitizer = null;
    /** @var bool */
    private $multiple = false;
    /** @var bool */
    private $positional = false;
    /** @var string */
    private $items_type = 'string';
    /** @var Field[] */
    private $properties = [];
    /** @var string|null */
    private $cli_name = null;
    /** @var array|null */
    private $schema = null;
    /** @var bool */
    private $additional_properties = true;

    /**
     * @param string $name Parameter name (snake_case).
     * @param string $type One of the T_* constants.
     */
    private function __construct($name, $type)
    {
        $this->name = (string) $name;
        $this->type = (string) $type;
    }

    /* ---------------------------------------------------------------------
     * Factories
     * ------------------------------------------------------------------ */

    /** @return Field */
    public static function string($name)
    {
        return new self($name, self::T_STRING);
    }

    /** @return Field */
    public static function int($name)
    {
        return new self($name, self::T_INT);
    }

    /** @return Field */
    public static function bool($name)
    {
        return new self($name, self::T_BOOL);
    }

    /**
     * @param string         $name    Parameter name.
     * @param array|callable $choices Allowed values, or a callable returning them.
     *
     * @return Field
     */
    public static function enum($name, $choices)
    {
        $field          = new self($name, self::T_ENUM);
        $field->choices = $choices;
        return $field;
    }

    /** @return Field */
    public static function object($name)
    {
        return new self($name, self::T_OBJECT);
    }

    /** @return Field */
    public static function int_list($name)
    {
        $field             = new self($name, self::T_INT_LIST);
        $field->items_type = 'integer';
        return $field;
    }

    /** @return Field */
    public static function array($name)
    {
        return new self($name, self::T_ARRAY);
    }

    /**
     * A field described by a verbatim JSON Schema fragment.
     *
     * For shapes the typed factories cannot express (union types, nested
     * repeaters derived from a field library). The schema is emitted as-is
     * for abilities; REST takes its `type`; the CLI reads it as JSON.
     *
     * @param string $name   Field name.
     * @param array  $schema JSON Schema fragment.
     *
     * @return Field
     */
    public static function custom($name, array $schema)
    {
        $field         = new self($name, self::T_CUSTOM);
        $field->schema = $schema;
        return $field;
    }

    /* ---------------------------------------------------------------------
     * Fluent modifiers (each returns a clone)
     * ------------------------------------------------------------------ */

    /** @return Field */
    public function required($required = true)
    {
        $clone           = clone $this;
        $clone->required = (bool) $required;
        return $clone;
    }

    /** @return Field */
    public function default_value($value)
    {
        $clone              = clone $this;
        $clone->has_default = true;
        $clone->default     = $value;
        return $clone;
    }

    /** @return Field */
    public function min($min)
    {
        $clone      = clone $this;
        $clone->min = $min;
        return $clone;
    }

    /** @return Field */
    public function max($max)
    {
        $clone      = clone $this;
        $clone->max = $max;
        return $clone;
    }

    /** @return Field */
    public function max_length($length)
    {
        $clone             = clone $this;
        $clone->max_length = (int) $length;
        return $clone;
    }

    /** @return Field */
    public function describe($description)
    {
        $clone              = clone $this;
        $clone->description = (string) $description;
        return $clone;
    }

    /**
     * @param callable $validator fn($value, Field $field): true|false|\WP_Error
     *
     * @return Field
     */
    public function validate_with(callable $validator)
    {
        $clone            = clone $this;
        $clone->validator = $validator;
        return $clone;
    }

    /**
     * @param callable $sanitizer fn($value, Field $field): mixed
     *
     * @return Field
     */
    public function sanitize_with(callable $sanitizer)
    {
        $clone            = clone $this;
        $clone->sanitizer = $sanitizer;
        return $clone;
    }

    /** @return Field */
    public function multiple($multiple = true)
    {
        $clone           = clone $this;
        $clone->multiple = (bool) $multiple;
        return $clone;
    }

    /**
     * CLI hint: expose the field as `--<name>` instead of the dashed field name.
     *
     * @param string $name Flag name without dashes.
     *
     * @return Field
     */
    public function cli_name($name)
    {
        $clone           = clone $this;
        $clone->cli_name = (string) $name;
        return $clone;
    }

    /** CLI hint: consume a positional argument instead of --name. @return Field */
    public function positional($positional = true)
    {
        $clone             = clone $this;
        $clone->positional = (bool) $positional;
        return $clone;
    }

    /** @return Field */
    public function items($type)
    {
        $clone             = clone $this;
        $clone->items_type = (string) $type;
        return $clone;
    }

    /** Whether an object accepts keys beyond its declared properties. @return Field */
    public function additional_properties($allowed = true)
    {
        $clone                        = clone $this;
        $clone->additional_properties = (bool) $allowed;
        return $clone;
    }

    /**
     * @param Field[] $properties Nested fields for object types.
     *
     * @return Field
     */
    public function properties(array $properties)
    {
        $clone             = clone $this;
        $clone->properties = array_values($properties);
        return $clone;
    }

    /* ---------------------------------------------------------------------
     * Getters
     * ------------------------------------------------------------------ */

    public function name()
    {
        return $this->name;
    }

    public function type()
    {
        return $this->type;
    }

    public function is_required()
    {
        return $this->required;
    }

    public function has_default()
    {
        return $this->has_default;
    }

    public function default_of()
    {
        return $this->default;
    }

    public function min_of()
    {
        return $this->min;
    }

    public function max_of()
    {
        return $this->max;
    }

    public function max_length_of()
    {
        return $this->max_length;
    }

    public function description()
    {
        return $this->description;
    }

    public function is_multiple()
    {
        return $this->multiple;
    }

    public function is_positional()
    {
        return $this->positional;
    }

    /** @return string|null */
    public function cli_name_of()
    {
        return $this->cli_name;
    }

    /** @return array|null Verbatim schema of a custom field. */
    public function schema_of()
    {
        return $this->schema;
    }

    /** @return bool */
    public function allows_additional_properties()
    {
        return $this->additional_properties;
    }

    public function items_type()
    {
        return $this->items_type;
    }

    /** @return Field[] */
    public function properties_of()
    {
        return $this->properties;
    }

    /**
     * Resolve the allowed values of an enum.
     *
     * @return string[]
     */
    public function choices()
    {
        $choices = is_callable($this->choices) ? call_user_func($this->choices) : $this->choices;

        return is_array($choices) ? array_values(array_map('strval', $choices)) : [];
    }

    /* ---------------------------------------------------------------------
     * Normalisation and validation
     * ------------------------------------------------------------------ */

    /**
     * Coerce a raw value (from REST, CLI or an ability) into the field type.
     *
     * @param mixed $raw Raw input.
     *
     * @return mixed|\WP_Error Normalised value, null for "absent", or an error.
     *
     * @since 0.1.0
     */
    public function normalize($raw)
    {
        if ($raw === null || $raw === '') {
            return $this->has_default ? $this->default : null;
        }

        $value = $this->coerce($raw);
        if ($value instanceof \WP_Error) {
            return $value;
        }

        if ($this->sanitizer !== null) {
            $value = call_user_func($this->sanitizer, $value, $this);
        }

        return $value;
    }

    /**
     * Validate an already normalised value.
     *
     * @param mixed $value Normalised value (null when absent).
     *
     * @return true|\WP_Error
     *
     * @since 0.1.0
     */
    public function validate($value)
    {
        if ($this->is_empty($value)) {
            return $this->required ? $this->error('required', 'is required') : true;
        }

        $bounds = $this->check_bounds($value);
        if ($bounds !== true) {
            return $bounds;
        }

        $choices = $this->check_choices($value);
        if ($choices !== true) {
            return $choices;
        }

        if ($this->validator === null) {
            return true;
        }

        $custom = call_user_func($this->validator, $value, $this);
        if ($custom instanceof \WP_Error) {
            return $custom;
        }

        return $custom === false ? $this->error('invalid', 'is invalid') : true;
    }

    /**
     * Whether a normalised value counts as "not provided".
     *
     * @param mixed $value Normalised value.
     *
     * @return bool
     */
    public function is_empty($value)
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param mixed $raw Raw value.
     *
     * @return mixed|\WP_Error
     */
    private function coerce($raw)
    {
        switch ($this->type) {
            case self::T_STRING:
                return is_scalar($raw) ? (string) $raw : $this->error('type', 'must be a string');
            case self::T_INT:
                return is_numeric($raw) ? (int) $raw : $this->error('type', 'must be an integer');
            case self::T_BOOL:
                return $this->coerce_bool($raw);
            case self::T_ENUM:
                return $this->multiple ? $this->coerce_list($raw, 'string') : (is_scalar($raw) ? (string) $raw : $this->error('type', 'must be a string'));
            case self::T_OBJECT:
                return $this->coerce_object($raw);
            case self::T_INT_LIST:
                return $this->coerce_list($raw, 'integer');
            case self::T_ARRAY:
                return $this->coerce_list($raw, $this->items_type);
            case self::T_CUSTOM:
                return $this->coerce_custom($raw);
        }

        return $raw;
    }

    /**
     * @param mixed $raw Raw value.
     *
     * @return bool|\WP_Error
     */
    private function coerce_bool($raw)
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $token = strtolower(trim((string) $raw));
        if (in_array($token, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($token, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $this->error('type', 'must be true or false');
    }

    /**
     * @param mixed  $raw  Raw value: array, comma separated string, or scalar.
     * @param string $type Item type: integer|string.
     *
     * @return array|\WP_Error
     */
    private function coerce_list($raw, $type)
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        } elseif (is_scalar($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return $this->error('type', 'must be a list');
        }

        $items = [];
        foreach ($raw as $item) {
            if ($item === '' || $item === null) {
                continue;
            }
            if ($type === 'integer') {
                if (!is_numeric($item)) {
                    return $this->error('type', 'must contain integers only');
                }
                $items[] = (int) $item;
                continue;
            }
            $items[] = is_scalar($item) ? (string) $item : $item;
        }

        return $items;
    }

    /**
     * Custom fields pass through; a JSON string is decoded when the schema
     * describes an object or a list.
     *
     * @param mixed $raw Raw value.
     *
     * @return mixed
     */
    private function coerce_custom($raw)
    {
        $types = isset($this->schema['type']) ? (array) $this->schema['type'] : [];
        if (is_string($raw) && (in_array('object', $types, true) || in_array('array', $types, true))) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return is_object($raw) ? (array) $raw : $raw;
    }

    /**
     * @param mixed $raw Array or JSON object string.
     *
     * @return array|\WP_Error
     */
    private function coerce_object($raw)
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return $this->error('type', 'must be a JSON object');
            }
            $raw = $decoded;
        }

        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (!is_array($raw)) {
            return $this->error('type', 'must be an object');
        }

        foreach ($this->properties as $child) {
            if (!array_key_exists($child->name(), $raw)) {
                continue;
            }
            $value = $child->normalize($raw[$child->name()]);
            if ($value instanceof \WP_Error) {
                return $value;
            }
            $raw[$child->name()] = $value;
        }

        return $raw;
    }

    /**
     * @param mixed $value Normalised value.
     *
     * @return true|\WP_Error
     */
    private function check_bounds($value)
    {
        if ($this->type === self::T_INT) {
            if ($this->min !== null && $value < $this->min) {
                return $this->error('min', sprintf('must be at least %s', $this->min));
            }
            if ($this->max !== null && $value > $this->max) {
                return $this->error('max', sprintf('must be at most %s', $this->max));
            }
        }

        if ($this->type === self::T_STRING && $this->max_length !== null && strlen($value) > $this->max_length) {
            return $this->error('max_length', sprintf('must be at most %d characters', $this->max_length));
        }

        return true;
    }

    /**
     * @param mixed $value Normalised value.
     *
     * @return true|\WP_Error
     */
    private function check_choices($value)
    {
        if ($this->type !== self::T_ENUM) {
            return true;
        }

        $choices = $this->choices();
        if ($choices === []) {
            return true;
        }

        foreach ((array) $value as $candidate) {
            if (!in_array((string) $candidate, $choices, true)) {
                return $this->error('enum', sprintf('must be one of: %s', implode(', ', $choices)));
            }
        }

        return true;
    }

    /**
     * Build a parameter error with a 400 status.
     *
     * @param string $code    Short code suffix.
     * @param string $message Human readable fragment.
     *
     * @return \WP_Error
     */
    private function error($code, $message)
    {
        return new \WP_Error(
            'mwp_invalid_param',
            sprintf('%s %s.', $this->name, $message),
            ['status' => 400, 'param' => $this->name, 'reason' => $code]
        );
    }
}
