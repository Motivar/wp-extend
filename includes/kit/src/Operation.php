<?php
/**
 * One operation of a Resource: what it takes, who may call it, what it
 * returns, and which service method runs it.
 *
 * `run()` is the single execution path shared by every surface. Adapters
 * only translate their transport's input and output around it.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Operation
{
    const KIND_READ        = 'read';
    const KIND_WRITE       = 'write';
    const KIND_DESTRUCTIVE = 'destructive';

    const SURFACES = [Context::REST, Context::CLI, Context::ABILITY];

    /** @var string */
    private $kind;
    /** @var string */
    private $handler;
    /** @var string */
    private $name = '';
    /** @var Resource|null */
    private $resource = null;
    /** @var string */
    private $label = '';
    /** @var string */
    private $description = '';
    /** @var Field[] */
    private $fields = [];
    /** @var array */
    private $output = [];
    /** @var mixed */
    private $capability = null;
    /** @var string[] */
    private $confirm = [];
    /** @var array<int,array{method:string,path:string,status:int}> */
    private $rest = [];
    /** @var array|null */
    private $cli = null;
    /** @var string|null */
    private $ability = null;
    /** @var string[]|callable */
    private $surfaces = self::SURFACES;
    /** @var string */
    private $surface_reason = '';
    /** @var array<string,bool> */
    private $annotations = [];
    /** @var callable|null */
    private $transform = null;
    /** @var callable|null */
    private $args = null;
    /** @var bool */
    private $allow_extra = false;
    /** @var object|null */
    private $service_override = null;

    /**
     * @param string $kind    Operation kind.
     * @param string $handler Service method name.
     */
    private function __construct($kind, $handler)
    {
        $this->kind        = $kind;
        $this->handler     = (string) $handler;
        $this->annotations = self::default_annotations($kind);
        $this->confirm     = $kind === self::KIND_DESTRUCTIVE ? [Context::ABILITY] : [];
    }

    /* ---------------------------------------------------------------------
     * Factories and declaration
     * ------------------------------------------------------------------ */

    /** @return Operation */
    public static function read($handler)
    {
        return new self(self::KIND_READ, $handler);
    }

    /** @return Operation */
    public static function write($handler)
    {
        return new self(self::KIND_WRITE, $handler);
    }

    /** @return Operation */
    public static function destructive($handler)
    {
        return new self(self::KIND_DESTRUCTIVE, $handler);
    }

    /** @return Operation */
    public function label($label)
    {
        $this->label = (string) $label;
        return $this;
    }

    /** @return Operation */
    public function description($description)
    {
        $this->description = (string) $description;
        return $this;
    }

    /**
     * @param Field[] $fields Input fields.
     *
     * @return Operation
     */
    public function input(array $fields)
    {
        $this->fields = array_values($fields);
        return $this;
    }

    /**
     * @param array $schema JSON Schema for the result.
     *
     * @return Operation
     */
    public function output(array $schema)
    {
        $this->output = $schema;
        return $this;
    }

    /**
     * @param mixed $resolver true|false|capability string|callable(Operation,array,Context).
     *
     * @return Operation
     */
    public function capability($resolver)
    {
        $this->capability = $resolver;
        return $this;
    }

    /**
     * @param string[]|bool $surfaces Surfaces that must confirm; true = all, false = none.
     *
     * @return Operation
     */
    public function confirm($surfaces = true)
    {
        if ($surfaces === true) {
            $surfaces = self::SURFACES;
        }
        $this->confirm = $surfaces === false ? [] : array_values((array) $surfaces);
        return $this;
    }

    /**
     * @param string $method HTTP method.
     * @param string $path   Path relative to the resource REST base.
     * @param int    $status Success status.
     *
     * @return Operation
     */
    public function rest($method, $path = '', $status = 200)
    {
        array_unshift($this->rest, ['method' => strtoupper($method), 'path' => (string) $path, 'status' => (int) $status]);
        return $this;
    }

    /**
     * Additional REST binding kept for compatibility.
     *
     * @return Operation
     */
    public function rest_alias($method, $path, $status = 200)
    {
        $this->rest[] = ['method' => strtoupper($method), 'path' => (string) $path, 'status' => (int) $status];
        return $this;
    }

    /**
     * @param string $name  Subcommand name under the resource CLI base.
     * @param array  $hints columns|success|presenter|default_format.
     *
     * @return Operation
     */
    public function cli($name, array $hints = [])
    {
        $this->cli = array_merge(['name' => (string) $name], $hints);
        return $this;
    }

    /** @return Operation */
    public function ability($slug)
    {
        $this->ability = (string) $slug;
        return $this;
    }

    /**
     * Restrict the surfaces this operation is exposed on.
     *
     * A callable is resolved every time a surface asks, so a module can
     * gate registration on state that is only known later (a setting read
     * on `init`, for example).
     *
     * @param string[]|callable $enabled Enabled surfaces, or fn(): string[].
     * @param string            $reason  Why the others are excluded (for the inventory).
     *
     * @return Operation
     */
    public function surfaces($enabled, $reason = '')
    {
        // A plain list of surface names is never callable; closures and [$obj, 'method'] pairs are.
        $this->surfaces       = is_callable($enabled) ? $enabled : array_values(array_intersect(self::SURFACES, (array) $enabled));
        $this->surface_reason = (string) $reason;
        return $this;
    }

    /** @return Operation */
    public function annotations(array $annotations)
    {
        $this->annotations = array_merge($this->annotations, $annotations);
        return $this;
    }

    /**
     * @param callable $transform fn($result, array $input, Context $ctx): mixed
     *
     * @return Operation
     */
    public function transform(callable $transform)
    {
        $this->transform = $transform;
        return $this;
    }

    /**
     * Override how normalised input becomes handler arguments.
     *
     * @param callable $mapper fn(array $input, Context $ctx): array positional args
     *
     * @return Operation
     */
    public function args(callable $mapper)
    {
        $this->args = $mapper;
        return $this;
    }

    /**
     * Run the handler on this object instead of the resource's service.
     *
     * @param object $service Service instance.
     *
     * @return Operation
     */
    public function on($service)
    {
        $this->service_override = is_object($service) ? $service : null;
        return $this;
    }

    /** Keep input keys that no Field declares. @return Operation */
    public function allow_extra($allow = true)
    {
        $this->allow_extra = (bool) $allow;
        return $this;
    }

    /**
     * Bind the operation to its resource (called by Resource).
     *
     * @param string   $name     Operation key.
     * @param Resource $resource Owning resource.
     *
     * @return Operation
     */
    public function attach($name, Resource $resource)
    {
        $this->name     = (string) $name;
        $this->resource = $resource;
        return $this;
    }

    /* ---------------------------------------------------------------------
     * Getters
     * ------------------------------------------------------------------ */

    public function kind()
    {
        return $this->kind;
    }

    public function is_read()
    {
        return $this->kind === self::KIND_READ;
    }

    public function handler()
    {
        return $this->handler;
    }

    public function name()
    {
        return $this->name;
    }

    /** @return Resource|null */
    public function resource()
    {
        return $this->resource;
    }

    public function label_of()
    {
        return $this->label !== '' ? $this->label : ucfirst(str_replace(['_', '-'], ' ', $this->name));
    }

    public function description_of()
    {
        return $this->description;
    }

    /** @return Field[] */
    public function fields()
    {
        return $this->fields;
    }

    public function output_schema()
    {
        return $this->output;
    }

    /** @return string[] */
    public function confirm_surfaces()
    {
        return $this->confirm;
    }

    /** @return array<int,array{method:string,path:string,status:int}> */
    public function rest_bindings()
    {
        return $this->rest;
    }

    /** @return array|null */
    public function cli_binding()
    {
        return $this->cli;
    }

    /** @return string|null */
    public function ability_slug()
    {
        return $this->ability;
    }

    /** @return string[] */
    public function enabled_surfaces()
    {
        if (is_callable($this->surfaces)) {
            return array_values(array_intersect(self::SURFACES, (array) call_user_func($this->surfaces)));
        }

        return $this->surfaces;
    }

    /** @return array<string,string> Excluded surface => reason. */
    public function surface_reasons()
    {
        $reasons = [];
        foreach (array_diff(self::SURFACES, $this->enabled_surfaces()) as $excluded) {
            $reasons[$excluded] = $this->surface_reason;
        }

        return $reasons;
    }

    public function is_on($surface)
    {
        return in_array($surface, $this->enabled_surfaces(), true);
    }

    /** @return array<string,bool> */
    public function annotations_of()
    {
        return $this->annotations;
    }

    /**
     * The declared capability resolver, or null when the resource default applies.
     *
     * @return mixed
     */
    public function capability_resolver()
    {
        return $this->capability;
    }

    /* ---------------------------------------------------------------------
     * Execution
     * ------------------------------------------------------------------ */

    /**
     * Authorise without executing (REST and ability permission callbacks).
     *
     * @param array   $input Raw input.
     * @param Context $ctx   Invocation context.
     *
     * @return true|\WP_Error
     *
     * @since 0.1.0
     */
    public function authorize(array $input, Context $ctx)
    {
        $normalized = $this->normalize($input, true);
        $resolved   = Capability::resolve($this->capability, $this, $normalized, $ctx);

        return Capability::check($resolved, $ctx);
    }

    /**
     * The single execution path.
     *
     * @param array   $input Raw input.
     * @param Context $ctx   Invocation context.
     *
     * @return mixed|\WP_Error
     *
     * @since 0.1.0
     */
    public function run(array $input, Context $ctx)
    {
        $normalized = $this->normalize($input, false);
        if ($normalized instanceof \WP_Error) {
            return $normalized;
        }

        $allowed = Capability::check(Capability::resolve($this->capability, $this, $normalized, $ctx), $ctx);
        if ($allowed instanceof \WP_Error) {
            return $allowed;
        }

        if (Confirm::required($this, $ctx)) {
            $confirmed = Confirm::check($input);
            if ($confirmed instanceof \WP_Error) {
                return $confirmed;
            }
        }

        if (function_exists('do_action')) {
            /**
             * Fires before an operation's service method runs.
             *
             * @param Operation $op    Operation.
             * @param array     $input Normalised input.
             * @param Context   $ctx   Invocation context.
             *
             * @since 0.1.0
             */
            do_action('mwp_operation_before_run', $this, $normalized, $ctx);
        }

        $result = $this->invoke($normalized, $ctx);

        if (!($result instanceof \WP_Error) && $this->transform !== null) {
            $result = call_user_func($this->transform, $result, $normalized, $ctx);
        }

        if (function_exists('apply_filters')) {
            /**
             * Filter an operation's result before it reaches the surface adapter.
             *
             * @param mixed     $result Result or WP_Error.
             * @param Operation $op     Operation.
             * @param array     $input  Normalised input.
             * @param Context   $ctx    Invocation context.
             *
             * @since 0.1.0
             */
            $result = apply_filters('mwp_operation_result', $result, $this, $normalized, $ctx);
        }

        return $result;
    }

    /**
     * Normalise and (optionally) validate raw input against the fields.
     *
     * @param array $input   Raw input.
     * @param bool  $lenient Skip validation errors (used by authorize()).
     *
     * @return array|\WP_Error
     */
    private function normalize(array $input, $lenient)
    {
        $out = $this->allow_extra ? $input : [];

        foreach ($this->fields as $field) {
            $raw   = array_key_exists($field->name(), $input) ? $input[$field->name()] : null;
            $value = $field->normalize($raw);

            if ($value instanceof \WP_Error) {
                if ($lenient) {
                    continue;
                }
                return $value;
            }

            if (!$lenient) {
                $valid = $field->validate($value);
                if ($valid instanceof \WP_Error) {
                    return $valid;
                }
            }

            if ($value !== null) {
                $out[$field->name()] = $value;
            }
        }

        if (isset($input[Confirm::FIELD])) {
            $out[Confirm::FIELD] = $input[Confirm::FIELD];
        }

        return $out;
    }

    /**
     * Call the service method with arguments derived from the input.
     *
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return mixed|\WP_Error
     */
    private function invoke(array $input, Context $ctx)
    {
        $service = $this->service_override;
        if ($service === null && $this->resource !== null) {
            $service = $this->resource->service_instance();
        }
        if ($service === null || !method_exists($service, $this->handler)) {
            return new \WP_Error('mwp_no_handler', sprintf('No handler "%s" for operation "%s".', $this->handler, $this->name), ['status' => 500]);
        }

        try {
            $args = $this->args !== null
                ? call_user_func($this->args, $input, $ctx)
                : $this->map_args($service, $input);

            if ($args instanceof \WP_Error) {
                return $args;
            }

            return call_user_func_array([$service, $this->handler], (array) $args);
        } catch (\Throwable $e) {
            return new \WP_Error('mwp_operation_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Default argument mapping: match parameters by name; leftover input keys
     * go to the first array-typed parameter that was not matched by name.
     *
     * @param object $service Service instance.
     * @param array  $input   Normalised input.
     *
     * @return array Positional arguments.
     */
    private function map_args($service, array $input)
    {
        $method   = new \ReflectionMethod($service, $this->handler);
        $args     = [];
        $leftover = $input;
        unset($leftover[Confirm::FIELD]);
        $catch_all = null;

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $input)) {
                $args[] = $input[$name];
                unset($leftover[$name]);
                continue;
            }

            $type = $parameter->getType();
            if ($catch_all === null && $type instanceof \ReflectionNamedType && $type->getName() === 'array') {
                $catch_all = count($args);
                $args[]    = [];
                continue;
            }

            $args[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        if ($catch_all !== null) {
            $args[$catch_all] = $leftover;
        }

        return $args;
    }

    /**
     * @param string $kind Operation kind.
     *
     * @return array<string,bool>
     */
    private static function default_annotations($kind)
    {
        switch ($kind) {
            case self::KIND_READ:
                return ['readonly' => true, 'idempotent' => true, 'destructive' => false];
            case self::KIND_DESTRUCTIVE:
                return ['readonly' => false, 'idempotent' => true, 'destructive' => true];
        }

        return ['readonly' => false, 'idempotent' => false, 'destructive' => false];
    }
}
