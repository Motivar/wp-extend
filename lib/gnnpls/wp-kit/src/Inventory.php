<?php
/**
 * Flat export of everything a Registry exposes, per surface.
 *
 * Feeds parity tests, generated test-manifest coverage and route inventories.
 *
 * @package Gnnpls\WP
 * @since   0.1.0
 */

namespace Gnnpls\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Inventory
{
    /**
     * One row per operation.
     *
     * @param Registry $registry Registry to export.
     *
     * @return array<int,array>
     *
     * @since 0.1.0
     */
    public static function export(Registry $registry)
    {
        $rows = [];

        foreach ($registry->all() as $resource) {
            foreach ($resource->ops() as $operation) {
                $rows[] = [
                    'resource'  => $resource->name(),
                    'operation' => $operation->name(),
                    'kind'      => $operation->kind(),
                    'surfaces'  => [
                        Context::REST    => self::rest_routes($resource, $operation),
                        Context::CLI     => self::cli_commands($resource, $operation),
                        Context::ABILITY => self::abilities($resource, $operation),
                    ],
                    'excluded'  => $operation->surface_reasons(),
                    'confirm'   => $operation->confirm_surfaces(),
                ];
            }
        }

        return $rows;
    }

    /**
     * REST namespaces in use, with the resource that owns each.
     *
     * @param Registry $registry Registry to inspect.
     *
     * @return array<string,string[]> Namespace => resource names.
     *
     * @since 0.1.0
     */
    public static function rest_namespaces(Registry $registry)
    {
        $namespaces = [];

        foreach ($registry->all() as $resource) {
            $namespace = $resource->rest_namespace();
            if ($namespace === null || $namespace === '') {
                continue;
            }
            $namespaces[$namespace][] = $resource->name();
        }

        return $namespaces;
    }

    /**
     * @return string[] e.g. ["GET /ns/base/path"].
     */
    public static function rest_routes(Resource $resource, Operation $operation)
    {
        $namespace = $resource->rest_namespace();
        if (!$operation->is_on(Context::REST) || $namespace === null || $namespace === '') {
            return [];
        }

        $routes = [];
        foreach ($operation->rest_bindings() as $binding) {
            $routes[] = $binding['method'] . ' /' . trim($namespace, '/') . self::join($resource->rest_base(), $binding['path']);
        }

        return $routes;
    }

    /**
     * @return string[] e.g. ["my-plugin content list"].
     */
    public static function cli_commands(Resource $resource, Operation $operation)
    {
        $binding = $operation->cli_binding();
        if (!$operation->is_on(Context::CLI) || $binding === null) {
            return [];
        }

        return [trim($resource->cli_base() . ' ' . $binding['name'])];
    }

    /**
     * @return string[] e.g. ["my-plugin-content/list-items"].
     */
    public static function abilities(Resource $resource, Operation $operation)
    {
        $category = $resource->ability_category();
        if (!$operation->is_on(Context::ABILITY) || $category === null || $operation->ability_slug() === null) {
            return [];
        }

        return [$category . '/' . $operation->ability_slug()];
    }

    /**
     * Join a base and a path with exactly one slash.
     *
     * @param string $base Base path.
     * @param string $path Relative path.
     *
     * @return string
     */
    public static function join($base, $path)
    {
        $base = '/' . trim((string) $base, '/');
        $path = trim((string) $path, '/');

        return $path === '' ? $base : rtrim($base, '/') . '/' . $path;
    }
}
