<?php
/**
 * Runs the browser layer: shells out to the Node runner of the
 * `@mammothwp/wp-self-test-e2e` package and folds its JSON results into
 * regular self-test checks.
 *
 * Browser checks are skipped (never failed) when the runner cannot start:
 * production, no `proc_open`, Node missing, or the e2e package not installed
 * next to the plugin's manifest.
 *
 * @package MammothWP\SelfTest
 * @since   2.1.0
 */

namespace MammothWP\SelfTest\Browser;

use MammothWP\SelfTest\Config;

if (!defined('ABSPATH')) {
    exit;
}

final class Browser_Runner
{
    /** npm package that provides the Node runner. */
    const PACKAGE = '@mammothwp/wp-self-test-e2e';

    /** Default seconds the Node process may take per case. */
    const DEFAULT_TIMEOUT = 300;

    /** Parent directories searched for node_modules above the manifest. */
    const SEARCH_DEPTH = 6;

    /** @var callable fn(string[] $command, array $env, int $timeout, string $cwd): array{exit: int, stdout: string, stderr: string} */
    private $executor;

    /**
     * @param callable|null $executor Process executor; default runs proc_open. Injectable for tests.
     */
    public function __construct($executor = null)
    {
        $this->executor = $executor ?: [self::class, 'execute'];
    }

    /**
     * Run one case's browser checks.
     *
     * @param array $definition Case definition (with `browser`, `manifest_dir`, `id`, `plugin`).
     * @param int   $user_id    User the browser logs in as.
     *
     * @return array[] Checks: {layer: browser, label, status, detail}.
     *
     * @since 2.1.0
     */
    public function run(array $definition, $user_id)
    {
        $plan = $this->plan($definition);
        if (isset($plan['skip'])) {
            return [self::row('Browser checks', 'skip', $plan['skip'])];
        }

        $token = Login_Token::issue($user_id);
        if (is_wp_error($token)) {
            return [self::row('Browser checks', 'skip', $token->get_error_message())];
        }

        $files = [tempnam(sys_get_temp_dir(), 'mmwpcfg'), tempnam(sys_get_temp_dir(), 'mmwpres')];

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private temp file.
            file_put_contents($files[0], wp_json_encode($this->config($definition, $plan['root'])));
            $outcome = call_user_func($this->executor, $plan['command'], $this->environment($files, $token), $plan['timeout'], $plan['root']);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private temp file.
            $json = (string) file_get_contents($files[1]);
        } finally {
            Login_Token::revoke_all();
            array_map('unlink', $files);
        }

        return self::checks_from_results($json, $outcome);
    }

    /**
     * Whether the runner can start for a case, and its command when it can.
     *
     * @param array $definition Case definition.
     *
     * @return array{skip: string}|array{command: string[], root: string, timeout: int}
     *
     * @since 2.1.0
     */
    public function plan(array $definition)
    {
        if (!Login_Token::available()) {
            return ['skip' => __('Browser checks are not available on production.', Config::TEXT_DOMAIN)];
        }

        if (!function_exists('proc_open')) {
            return ['skip' => __('PHP proc_open() is disabled, so the Node runner cannot start.', Config::TEXT_DOMAIN)];
        }

        $root = self::find_root(isset($definition['manifest_dir']) ? (string) $definition['manifest_dir'] : '');
        if ($root === '') {
            return ['skip' => sprintf(__('%s is not installed (npm install it in the plugin, next to its manifest).', Config::TEXT_DOMAIN), self::PACKAGE)];
        }

        $package = $root . '/node_modules/' . self::PACKAGE;

        /**
         * Filter the command that starts the Node runner of the browser layer.
         *
         * @param string[] $command Default: `node <package>/e2e/bin/run.js`.
         * @param string   $package Directory of the installed e2e package.
         * @param array    $definition Case definition.
         *
         * @since 2.1.0
         */
        $command = (array) apply_filters('mmwp_self_test_browser_command', ['node', $package . '/e2e/bin/run.js'], $package, $definition);

        $probe = call_user_func($this->executor, [(string) $command[0], '--version'], [], 15, $root);
        if ($probe['exit'] !== 0) {
            return ['skip' => sprintf(__('Node.js was not found (tried "%s --version").', Config::TEXT_DOMAIN), $command[0])];
        }

        $timeout = isset($definition['browser']['timeout']) ? (int) $definition['browser']['timeout'] : self::DEFAULT_TIMEOUT;

        return ['command' => array_map('strval', $command), 'root' => $root, 'timeout' => $timeout];
    }

    /**
     * Nearest directory at or above the manifest that has the e2e package
     * in its node_modules.
     *
     * @param string $start Manifest directory.
     *
     * @return string Empty when not found.
     *
     * @since 2.1.0
     */
    public static function find_root($start)
    {
        $dir = rtrim((string) $start, '/');

        for ($i = 0; $dir !== '' && $i <= self::SEARCH_DEPTH; $i++) {
            if (is_file($dir . '/node_modules/' . self::PACKAGE . '/package.json')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return '';
    }

    /**
     * Turn the Node runner's results file into checks.
     *
     * Results shape: `{"checks": [{"label", "status": pass|fail|skip, "detail"}]}`.
     * A crash with no usable results is a failure, not a skip.
     *
     * @param string $json    Contents of the results file.
     * @param array  $outcome Process outcome: exit, stdout, stderr.
     *
     * @return array[]
     *
     * @since 2.1.0
     */
    public static function checks_from_results($json, array $outcome)
    {
        $data = json_decode((string) $json, true);

        if (!is_array($data) || empty($data['checks']) || !is_array($data['checks'])) {
            $tail = trim(substr(trim($outcome['stderr'] . "\n" . $outcome['stdout']), -600));

            return [self::row('Node runner', 'fail', sprintf('exit %d, no results written. %s', $outcome['exit'], $tail))];
        }

        $checks = [];
        foreach ($data['checks'] as $check) {
            $status   = isset($check['status']) && in_array($check['status'], ['pass', 'fail', 'skip'], true) ? $check['status'] : 'fail';
            $checks[] = self::row(isset($check['label']) ? (string) $check['label'] : 'Browser check', $status, isset($check['detail']) ? (string) $check['detail'] : '');
        }

        return $checks;
    }

    /**
     * One browser check row.
     *
     * @param string $label  Label.
     * @param string $status pass|fail|skip.
     * @param string $detail Detail.
     *
     * @return array
     *
     * @since 2.1.0
     */
    private static function row($label, $status, $detail = '')
    {
        return ['layer' => 'browser', 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * Case configuration handed to the Node runner (never contains the token).
     *
     * @param array  $definition Case definition.
     * @param string $root       Directory holding node_modules; specs resolve against it.
     *
     * @return array
     *
     * @since 2.1.0
     */
    private function config(array $definition, $root)
    {
        return [
            'case'      => isset($definition['id']) ? $definition['id'] : '',
            'plugin'    => isset($definition['plugin']) ? $definition['plugin'] : '',
            'root'      => $root,
            'site_url'  => home_url(),
            'admin_url' => admin_url(),
            'rest_url'  => rest_url('mmwp/v1/self-test/'),
            'browser'   => isset($definition['browser']) ? $definition['browser'] : [],
        ];
    }

    /**
     * Environment for the Node process. The token travels here, not in argv.
     *
     * @param string[] $files [config path, results path].
     * @param string   $token Login token.
     *
     * @return array<string,string>
     *
     * @since 2.1.0
     */
    private function environment(array $files, $token)
    {
        return [
            'MMWP_E2E_CONFIG'      => $files[0],
            'MMWP_E2E_RESULTS'     => $files[1],
            'MMWP_E2E_LOGIN_TOKEN' => $token,
        ];
    }

    /**
     * Default executor: run a command with a timeout.
     *
     * @param string[]             $command Program and arguments.
     * @param array<string,string> $env     Extra environment variables.
     * @param int                  $timeout Seconds before the process is killed.
     * @param string               $cwd     Working directory.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     *
     * @since 2.1.0
     */
    public static function execute(array $command, array $env, $timeout, $cwd)
    {
        $pipes   = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, array_merge(getenv(), $env)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the Node runner is the point of the browser layer.

        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start ' . $command[0]];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $err      = '';
        $deadline = time() + (int) $timeout;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                $err .= "\ntimed out after {$timeout}s";
                break;
            }

            usleep(50000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);

        return ['exit' => $status['running'] ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $code), 'stdout' => $out, 'stderr' => $err];
    }
}
