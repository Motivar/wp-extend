<?php

namespace EWP\Surfaces\Resources;

use EWP\Surfaces\Rest_Health_Probes;
use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The interactive REST-health page features, declared as a REST-only
 * resource: probes, history, live monitor, OpenAPI export and the user's
 * page preferences. They still run through Operation::run() (validation,
 * super-admin check, hooks) and appear in the surfaces inventory, but a
 * command or ability would only ever be driven by the page itself.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Rest_Health_Probes_Resource extends Resource
{
    /** @var Rest_Health_Probes */
    private $service;

    /**
     * @param Rest_Health_Probes $service Probe service.
     */
    public function __construct(Rest_Health_Probes $service)
    {
        $this->service = $service;
    }

    /** {@inheritDoc} */
    public function name()
    {
        return 'rest-health-probes';
    }

    /** {@inheritDoc} */
    public function label()
    {
        return __('EWP REST Health probes', 'extend-wp');
    }

    /** {@inheritDoc} */
    public function service()
    {
        return $this->service;
    }

    /** {@inheritDoc} */
    public function rest_namespace()
    {
        return 'extend-wp/v1';
    }

    /** {@inheritDoc} */
    public function rest_base()
    {
        return '/rest-health';
    }

    /**
     * Every operation here drives a browser interaction on the REST-health page.
     *
     * @return string[]
     */
    public function surfaces()
    {
        return [Context::REST];
    }

    /**
     * @return string
     */
    public function surfaces_reason()
    {
        return __('interactive REST-health page features; only the page drives them', 'extend-wp');
    }

    /** Super administrators only, like the rest of the REST-health page. @return callable */
    public function capability()
    {
        return function () {
            return is_super_admin();
        };
    }

    /** {@inheritDoc} */
    public function operations()
    {
        $any    = ['type' => 'object', 'additionalProperties' => true];
        $route  = Field::string('route')->required()->describe(__('REST route to probe, e.g. /wp/v2/posts.', 'extend-wp'));
        $method = Field::string('method')->default_value('GET')->describe(__('HTTP method; GET by default.', 'extend-wp'));
        $flag   = function ($key) {
            return ['type' => 'object', 'properties' => [$key => ['type' => 'boolean']]];
        };

        return [
            'openapi' => Operation::read('openapi')
                ->label(__('OpenAPI document', 'extend-wp'))
                ->description(__('Generate an OpenAPI 3.0 document for the routes of the selected plugins, for the Swagger panel and the download button.', 'extend-wp'))
                ->input([
                    Field::array('plugins')->items('string')->describe(__('Plugin paths as reported by list-plugins; empty for every active plugin.', 'extend-wp')),
                    Field::array('methods')->items('string')->describe(__('HTTP methods to include; empty for all.', 'extend-wp')),
                ])
                ->args(function (array $input) {
                    return [(array) ($input['plugins'] ?? []), (array) ($input['methods'] ?? [])];
                })
                ->output($any)
                ->rest('GET', 'openapi'),

            'test' => Operation::write('test')
                ->label(__('Probe one route', 'extend-wp'))
                ->description(__('Send one request to a route as the current user and record the outcome in the route history.', 'extend-wp'))
                ->input([$route, $method, Field::object('params')->additional_properties()->describe(__('Request parameters (query for GET, body otherwise).', 'extend-wp'))])
                ->args(function (array $input) {
                    return [$input['route'], $input['method'], (array) ($input['params'] ?? [])];
                })
                ->output($any)
                ->rest('POST', 'test'),

            'batch' => Operation::write('batch')
                ->label(__('Probe several routes', 'extend-wp'))
                ->description(__('Probe up to the batch limit of routes in one request; each item names a route and a method.', 'extend-wp'))
                ->input([Field::array('routes_methods')->items('object')->required()->describe(__('List of {route, method} objects.', 'extend-wp'))])
                ->args(function (array $input) {
                    return [(array) $input['routes_methods']];
                })
                ->output($any)
                ->rest('POST', 'batch'),

            'history' => Operation::read('history')
                ->label(__('Route probe history', 'extend-wp'))
                ->description(__('Probe history of one route and method for the current user, newest first.', 'extend-wp'))
                ->input([$route, $method])
                ->output(['type' => 'array', 'items' => $any])
                ->rest('GET', 'history'),

            'clear-history' => Operation::destructive('clear_history')
                ->label(__('Clear route probe history', 'extend-wp'))
                ->description(__('Forget the probe history of one route and method for the current user.', 'extend-wp'))
                ->input([$route, $method])
                ->output($flag('cleared'))
                ->rest('DELETE', 'history'),

            'monitor' => Operation::read('monitor')
                ->label(__('Live monitor state', 'extend-wp'))
                ->description(__('Whether live monitoring is running, when it started, how long remains and how many requests were captured.', 'extend-wp'))
                ->output($any)
                ->rest('GET', 'monitor'),

            'update-monitor' => Operation::write('update_monitor')
                ->annotations(['idempotent' => true])
                ->label(__('Start or stop live monitoring', 'extend-wp'))
                ->description(__('Start capturing REST traffic of the selected plugins for ten minutes, or stop capturing.', 'extend-wp'))
                ->input([
                    Field::enum('action', ['start', 'stop'])->default_value('stop')->describe(__('start or stop.', 'extend-wp')),
                    Field::array('plugins')->items('string')->describe(__('Plugin paths whose namespaces to capture (start only).', 'extend-wp')),
                ])
                ->args(function (array $input) {
                    return [$input['action'], (array) ($input['plugins'] ?? [])];
                })
                ->output($any)
                ->rest('POST', 'monitor'),

            'payloads' => Operation::read('payloads')
                ->label(__('Captured payloads', 'extend-wp'))
                ->description(__('The last payload captured per route and method while monitoring.', 'extend-wp'))
                ->output($any)
                ->rest('GET', 'monitor/payloads'),

            'clear-payloads' => Operation::destructive('clear_payloads')
                ->label(__('Discard captured payloads', 'extend-wp'))
                ->description(__('Delete the captured payload file and reset the capture counter.', 'extend-wp'))
                ->output($flag('cleared'))
                ->rest('DELETE', 'monitor/payloads'),

            'preferences' => Operation::read('preferences')
                ->label(__('Page preferences', 'extend-wp'))
                ->description(__('The plugins and methods the current user last selected on the REST-health page.', 'extend-wp'))
                ->output($any)
                ->rest('GET', 'preferences'),

            'save-preferences' => Operation::write('save_preferences')
                ->annotations(['idempotent' => true])
                ->label(__('Save page preferences', 'extend-wp'))
                ->description(__('Remember the selected plugins and methods for the current user.', 'extend-wp'))
                ->input([
                    Field::array('plugins')->items('string')->describe(__('Selected plugin paths.', 'extend-wp')),
                    Field::array('methods')->items('string')->describe(__('Selected HTTP methods.', 'extend-wp')),
                ])
                ->args(function (array $input) {
                    return [(array) ($input['plugins'] ?? []), (array) ($input['methods'] ?? [])];
                })
                ->output($flag('saved'))
                ->rest('POST', 'preferences'),
        ];
    }
}
