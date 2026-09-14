<?php

namespace EWP\SelfTest\Cases;

use Motivar\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity log: write one entry (ewp-logger/write-entry, falling back to
 * ewp_log() when abilities are unavailable), then read it back through the
 * REST routes, the `wp ewp log` commands and the read abilities. cleanup()
 * deletes every entry owned by `ewp-self-test`.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Logger_Case extends Case_Base
{
    const ACTION_TYPE = 'self_test';

    /** {@inheritDoc} */
    public function availability()
    {
        if (!class_exists('EWP\\Logger\\EWP_Logger') || !\EWP\Logger\EWP_Logger::is_enabled()) {
            return ['available' => false, 'reason' => __('Logging is switched off on this site (EWP Logger disabled).', 'extend-wp')];
        }

        return ['available' => true, 'reason' => ''];
    }

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register the "ewp-self-test" log owner and a "self_test" action type.', 'extend-wp'),
            __('Ability: ewp-logger/write-entry (or ewp_log() when abilities are unavailable) — write one developer-level entry and keep its request_id.', 'extend-wp'),
            __('REST: GET /extend-wp/v1/logs?owner=ewp-self-test, /logs/types, /logs/owners — expect 200 and the entry present.', 'extend-wp'),
            __('CLI: wp ewp log list --owner=ewp-self-test, wp ewp log stats, wp ewp log types — expect the entry / stats / the self_test type; wp ewp log cleanup --months=1200 (a 100-year window, deletes nothing).', 'extend-wp'),
            __('Ability: ewp-logger/search (owner), ewp-logger/get-request-trace (request_id), ewp-logger/list-vocabulary.', 'extend-wp'),
            __('Cleanup: delete every log entry owned by ewp-self-test.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        \EWP\Logger\EWP_Logger::register_owner(self::OWNER, 'Extend WP self-test');
        \EWP\Logger\EWP_Logger::register_action_type(self::OWNER, self::ACTION_TYPE, 'Self test', 'Entry written by the self-test suite.');


        $o       = [];
        $message = 'Self-test entry ' . gmdate('c');

        $o['write'] = $this->ability('ewp-logger/write-entry', [
            'owner'       => self::OWNER,
            'action_type' => self::ACTION_TYPE,
            'message'     => $message,
            'level'       => 'developer',
            'behaviour'   => 'success',
        ]);

        if (empty($o['write']['ok'])) {
            $o['write_fallback'] = (bool) ewp_log(self::OWNER, self::ACTION_TYPE, $message, [], 'developer', 'self-test', 1);
        }

        $o['request_id'] = \EWP\Logger\EWP_Logger::get_request_id();

        // Entries are queued and written on shutdown; persist now so the reads below can see them.
        if (class_exists('EWP\\Logger\\EWP_Logger_Queue')) {
            \EWP\Logger\EWP_Logger_Queue::flush();
        }

        $o['rest_logs']   = $this->rest('GET', '/extend-wp/v1/logs', ['owner' => self::OWNER, 'limit' => 5]);
        $o['rest_types']  = $this->rest('GET', '/extend-wp/v1/logs/types');
        $o['rest_owners'] = $this->rest('GET', '/extend-wp/v1/logs/owners');

        $o['cli_list']  = $this->cli(['EWP\\Logger\\EWP_Logger_CLI', 'list_logs'], [], ['owner' => self::OWNER, 'limit' => 5]);
        $o['cli_stats'] = $this->cli(['EWP\\Logger\\EWP_Logger_CLI', 'stats'], [], []);
        $o['cli_types'] = $this->cli(['EWP\\Logger\\EWP_Logger_CLI', 'types'], [], []);
        // Retention cleanup with a 100-year window: exercises the command without deleting anything real.
        $o['cli_cleanup'] = $this->cli(['EWP\\Logger\\EWP_Logger_CLI', 'cleanup'], [], ['months' => 1200]);

        $o['ability_search'] = $this->ability('ewp-logger/search', ['owner' => self::OWNER, 'limit' => 5]);
        $o['ability_trace']  = $this->ability('ewp-logger/get-request-trace', ['request_id' => $o['request_id']]);
        $o['ability_vocab']  = $this->ability('ewp-logger/list-vocabulary');

        return ['observed' => $o, 'owner' => self::OWNER];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        if ($this->abilities_available()) {
            $checks[] = $this->check('ability', 'ewp-logger/write-entry writes an entry', !empty($o['write']['ok']) && !empty($o['write']['data']['logged']), $o['write']['error'] ?: 'request ' . $o['request_id']);
        } else {
            $checks[] = $this->check('core', 'ewp_log() writes an entry (abilities unavailable)', !empty($o['write_fallback']), 'request ' . $o['request_id']);
        }

        $body = wp_json_encode($o['rest_logs']['data']);
        $checks[] = $this->check('rest', 'GET /logs?owner=ewp-self-test returns 200 and contains the entry', $o['rest_logs']['status'] === 200 && strpos($body, self::OWNER) !== false, 'HTTP ' . $o['rest_logs']['status']);
        $checks[] = $this->check('rest', 'GET /logs/types returns 200', $o['rest_types']['status'] === 200, 'HTTP ' . $o['rest_types']['status']);
        $checks[] = $this->check('rest', 'GET /logs/owners returns 200', $o['rest_owners']['status'] === 200, 'HTTP ' . $o['rest_owners']['status']);

        if ($this->cli_available()) {
            $checks[] = $this->check('cli', 'wp ewp log list --owner=ewp-self-test shows the entry', !empty($o['cli_list']['ok']) && strpos(wp_json_encode($o['cli_list']['printed']), self::OWNER) !== false, $o['cli_list']['error'] ?: __('ok', 'extend-wp'));
            $checks[] = $this->check('cli', 'wp ewp log stats prints statistics', !empty($o['cli_stats']['ok']) && !empty($o['cli_stats']['printed']), $o['cli_stats']['error'] ?: __('ok', 'extend-wp'));
            $checks[] = $this->check('cli', 'wp ewp log types lists the self_test type', !empty($o['cli_types']['ok']) && strpos(wp_json_encode($o['cli_types']['printed']), self::ACTION_TYPE) !== false, $o['cli_types']['error'] ?: __('ok', 'extend-wp'));
            $checks[] = $this->check('cli', 'wp ewp log cleanup --months=1200 runs (deletes nothing)', !empty($o['cli_cleanup']['ok']), $o['cli_cleanup']['error'] ?: (isset($o['cli_cleanup']['success'][0]) ? $o['cli_cleanup']['success'][0] : __('ok', 'extend-wp')));
        } else {
            foreach (['wp ewp log list', 'wp ewp log stats', 'wp ewp log types', 'wp ewp log cleanup'] as $cmd) {
                $checks[] = $this->skip('cli', $cmd, __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
            }
        }

        if (!$this->abilities_available()) {
            foreach (['ewp-logger/search', 'ewp-logger/get-request-trace', 'ewp-logger/list-vocabulary'] as $name) {
                $checks[] = $this->skip('ability', $name, __('Abilities API not available on this site.', 'extend-wp'));
            }
            return $checks;
        }

        $checks[] = $this->check('ability', 'ewp-logger/search finds the entry by owner', !empty($o['ability_search']['ok']) && strpos(wp_json_encode($o['ability_search']['data']), self::OWNER) !== false, $o['ability_search']['error'] ?: __('ok', 'extend-wp'));
        $checks[] = $this->check('ability', 'ewp-logger/get-request-trace returns the request', !empty($o['ability_trace']['ok']) && strpos(wp_json_encode($o['ability_trace']['data']), $o['request_id']) !== false, $o['ability_trace']['error'] ?: 'request ' . $o['request_id']);
        $checks[] = $this->check('ability', 'ewp-logger/list-vocabulary lists the ewp-self-test owner', !empty($o['ability_vocab']['ok']) && strpos(wp_json_encode($o['ability_vocab']['data']), self::OWNER) !== false, $o['ability_vocab']['error'] ?: __('ok', 'extend-wp'));

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        unset($context);

        $storage = \EWP\Logger\EWP_Logger::instance()->get_storage();

        if (!$storage) {
            return [__('Logger storage not initialised; nothing removed.', 'extend-wp')];
        }

        $before = $storage->count(['owner' => self::OWNER]);
        $storage->delete_by_filters(['owner' => self::OWNER]);

        return [sprintf(__('Deleted %d log entr(y/ies) owned by %s.', 'extend-wp'), (int) $before, self::OWNER)];
    }
}
