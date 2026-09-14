<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * In-admin AI diagnosis for the EWP Logger.
 *
 * Adds a "Describe the issue" box to the log viewer. The described issue is
 * combined with log context gathered through the shared logger query layer
 * and sent to the WordPress AI Client, which returns a plain-language
 * diagnosis. No external agent or connector is involved.
 *
 * Degrades silently when AI is unsupported or no provider is configured.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.3.0
 */
class EWP_Logger_Diagnose
{
    /**
     * Maximum entries included as context in a single diagnosis.
     *
     * Bounds both prompt size and cost.
     *
     * @var int
     */
    const MAX_CONTEXT_ENTRIES = 40;

    /**
     * Maximum characters accepted for the issue description.
     *
     * @var int
     */
    const MAX_ISSUE_CHARS = 2000;

    /**
     * Abilities instance used to gather context.
     *
     * Reused rather than duplicated so the diagnosis sees exactly what an
     * external AI agent would see.
     *
     * @var EWP_Logger_Query
     */
    private $query;

    /**
     * Constructor.
     *
     * @param EWP_Logger_Query|null $query Query layer shared with every logger surface.
     *
     * @since 1.3.0
     */
    public function __construct(?EWP_Logger_Query $query = null)
    {
        $this->query = $query ?: new EWP_Logger_Query();
    }

    /**
     * Register hooks.
     *
     * @return void
     *
     * @since 1.3.0
     */
    public function init()
    {
        /* POST extend-wp/v1/logs/diagnose is generated from EWP\Surfaces\Resources\Logger_Resource over diagnose(). */
        add_filter('ewp_logger_viewer_fields', [$this, 'add_viewer_fields'], 50);
        add_filter('ewp_register_dynamic_assets', [$this, 'register_assets']);
    }

    /**
     * Whether the diagnose feature can run.
     *
     * Requires core AI support (WordPress 7.0+) and at least one provider
     * with credentials configured. Both are needed: wp_supports_ai() reports
     * only whether AI is permitted here, not whether it is usable.
     *
     * @return bool True when available.
     *
     * @since 1.3.0
     */
    public static function is_available()
    {
        if (!function_exists('wp_supports_ai') || !function_exists('wp_ai_client_prompt')) {
            return false;
        }

        if (!wp_supports_ai()) {
            return false;
        }

        return self::has_configured_provider();
    }

    /**
     * Whether at least one AI provider has credentials configured.
     *
     * wp_supports_ai() only reports whether AI is permitted in this
     * environment - it returns true even with no provider set up - so the
     * provider registry has to be consulted separately. Uses each provider's
     * own availability check, which inspects locally stored credentials and
     * performs no network request, and the result is memoised per request.
     *
     * @return bool True when a provider could actually answer a prompt.
     *
     * @since 1.3.0
     */
    private static function has_configured_provider()
    {
        static $configured = null;

        if ($configured !== null) {
            return $configured;
        }

        $configured = false;

        if (!class_exists('\\WordPress\\AiClient\\AiClient')) {
            return $configured;
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();

            foreach ($registry->getRegisteredProviderIds() as $provider_id) {
                if ($registry->isProviderConfigured($provider_id)) {
                    $configured = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // A provider SDK that cannot even report availability is unusable.
            $configured = false;
        }

        /**
         * Filter whether an AI provider is considered configured.
         *
         * Lets a site short-circuit provider detection, for example when
         * credentials are supplied by a custom provider implementation.
         *
         * @param bool $configured True when a configured provider was found.
         *
         * @since 1.3.0
         */
        $configured = (bool) apply_filters('ewp_logger_has_ai_provider', $configured);

        return $configured;
    }

    /**
     * Diagnose an issue against the log.
     *
     * @param array $input `issue` (required), `range` (filters|all|24h|7d|30d|90d) and,
     *                     for `filters`, the viewer's filter values (comma joined
     *                     or lists): date_from, date_to, owner, action_type,
     *                     object_type, level, search_text, behaviour and any
     *                     `ewp_logger_filter_params` extension.
     *
     * @return array|\WP_Error `{answer, context}` or an error (503 when AI is unavailable).
     *
     * @since 1.3.0
     * @since 1.5.0 Takes the normalised input array instead of a WP_REST_Request.
     */
    public function diagnose(array $input)
    {
        if (!self::is_available()) {
            return new \WP_Error(
                'ewp_logger_ai_unavailable',
                __('AI features are not available on this site.', 'extend-wp'),
                ['status' => 503]
            );
        }

        $issue = trim((string) ($input['issue'] ?? ''));

        if ($issue === '') {
            return new \WP_Error(
                'ewp_logger_missing_issue',
                __('Describe the issue you want diagnosed.', 'extend-wp'),
                ['status' => 400]
            );
        }

        $issue = mb_substr(sanitize_textarea_field($issue), 0, self::MAX_ISSUE_CHARS);

        $scope = $this->resolve_scope($input);

        $context = $this->gather_context($issue, $scope);

        /**
         * Filter the log context assembled for an AI diagnosis.
         *
         * @param array  $context Gathered context.
         * @param string $issue   The described issue.
         *
         * @since 1.3.0
         */
        $context = apply_filters('ewp_logger_diagnose_context', $context, $issue);

        $answer = $this->run_prompt($issue, $context);

        if (is_wp_error($answer)) {
            return $answer;
        }

        return [
            'answer'  => $answer,
            'context' => [
                'date_from'       => $context['stats']['date_from'] ?? '',
                'date_to'         => $context['stats']['date_to'] ?? '',
                'total_in_window' => $context['stats']['total'] ?? 0,
                'entries_used'    => count($context['entries']),
                'filters_applied' => $context['filters_applied'],
            ],
        ];
    }

    /**
     * Named lookback presets offered in the UI.
     *
     * @return array Preset key => relative date expression.
     *
     * @since 1.3.0
     */
    public static function get_range_presets()
    {
        return [
            '24h' => '-1 day',
            '7d'  => '-7 days',
            '30d' => '-30 days',
            '90d' => '-90 days',
        ];
    }

    /**
     * Resolve which log entries the diagnosis should look at.
     *
     * Either a named lookback preset, or - by default - the log viewer's
     * current filters, so narrowing the table above also narrows what the
     * model is asked to reason about.
     *
     * @param array $input Normalised input.
     *
     * @return array Ability-shaped query input.
     *
     * @since 1.3.0
     */
    private function resolve_scope(array $input)
    {
        $range   = (string) ($input['range'] ?? 'filters');
        $presets = self::get_range_presets();

        if (isset($presets[$range])) {
            return [
                'date_from'       => gmdate('Y-m-d', strtotime($presets[$range])),
                'date_to'         => gmdate('Y-m-d'),
                'filters_applied' => [],
            ];
        }

        // "All" means everything still on disk: the retention window is the
        // real extent of the data, so there is nothing older to reach for.
        if ($range === 'all') {
            $settings  = EWP_Logger_Settings::get_settings();
            $retention = max(1, absint($settings['retention_months'] ?? 6));

            return [
                'date_from'       => gmdate('Y-m-d', strtotime('-' . $retention . ' months')),
                'date_to'         => gmdate('Y-m-d'),
                'filters_applied' => [],
            ];
        }

        // Default: mirror the viewer's filters.
        $scope   = [];
        $applied = [];

        foreach (['date_from', 'date_to'] as $key) {
            $value = is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
            if ($value !== '') {
                $scope[$key] = EWP_Logger_Formatter::normalize_date($value);
            }
        }

        foreach (['owner', 'action_type', 'object_type'] as $key) {
            $value = $this->split_multi($input[$key] ?? null);
            if (!empty($value)) {
                $scope[$key] = $value;
                $applied[]   = $key;
            }
        }

        $level = $this->split_multi($input['level'] ?? null);
        if (!empty($level)) {
            // Storage whitelists a single level, so take the first.
            $scope['level'] = is_array($level) ? reset($level) : $level;
            $applied[]      = 'level';
        }

        $search_text = is_scalar($input['search_text'] ?? null) ? trim((string) $input['search_text']) : '';
        if ($search_text !== '') {
            $scope['search_text'] = $search_text;
            $applied[]            = 'search_text';
        }

        // Anything else a plugin registered via `ewp_logger_filter_params` -
        // the same extension point the REST log list already honours - so a
        // plugin that added a viewer filter gets it respected here too.
        $handled = ['date_from', 'date_to', 'owner', 'action_type', 'object_type', 'level', 'search_text', 'behaviour'];

        foreach (EWP_Logger_API::get_filter_params() as $param) {
            if (in_array($param, $handled, true)) {
                continue;
            }

            $value = $this->split_multi($input[$param] ?? null);
            if ($value === null) {
                continue;
            }

            $scope[$param] = $value;
            $applied[]     = $param;
        }

        // The viewer sends behaviour as storage integers; abilities take labels.
        $behaviour = $this->split_multi($input['behaviour'] ?? null);
        if (!empty($behaviour)) {
            $labels = [];
            foreach ((array) $behaviour as $value) {
                $labels[] = EWP_Logger_Formatter::behaviour_label((int) $value);
            }
            $scope['behaviour'] = array_values(array_unique($labels));
            $applied[]          = 'behaviour';
        }

        $scope['filters_applied'] = $applied;

        return $scope;
    }

    /**
     * Split a comma-joined multi-select value.
     *
     * @param string|array|null $value Raw value: comma joined string or a list.
     *
     * @return string|array|null Single value, list, or null when empty.
     *
     * @since 1.3.0
     */
    private function split_multi($value)
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (strpos($value, ',') === false) {
            return $value;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), function ($v) {
            return $v !== '';
        }));

        return empty($parts) ? null : $parts;
    }

    /**
     * Gather log context for the described issue.
     *
     * Uses the registered ability handlers so the model sees exactly the same
     * data an external AI agent would receive.
     *
     * @param string $issue The described issue.
     * @param array  $scope Ability-shaped query input from resolve_scope().
     *
     * @return array Context payload.
     *
     * @since 1.3.0
     */
    /**
     * Search with the ability defaults (7-day window, compact payloads),
     * so the model sees exactly what an external agent would see.
     *
     * @param array $input Search input.
     *
     * @return array Search payload.
     *
     * @since 1.5.0
     */
    private function search(array $input)
    {
        $result = $this->query->search($input, ['shape' => 'compact', 'window' => EWP_Logger_Query::DEFAULT_WINDOW_DAYS, 'limit' => 50, 'max' => 200]);
        unset($result['args']);

        return $result;
    }

    private function gather_context($issue, array $scope)
    {
        $applied = $scope['filters_applied'] ?? [];
        unset($scope['filters_applied']);

        // Stats only understand a window and an owner.
        $stats_input = array_intersect_key($scope, array_flip(['date_from', 'date_to']));
        if (isset($scope['owner']) && is_string($scope['owner'])) {
            $stats_input['owner'] = $scope['owner'];
        }

        $vocabulary = $this->query->vocabulary();
        $stats      = $this->query->stats($stats_input, EWP_Logger_Query::DEFAULT_WINDOW_DAYS);

        $entries = [];
        $seen    = [];

        $collect = function (array $found) use (&$entries, &$seen) {
            foreach ($found as $entry) {
                $log_id = $entry['log_id'] ?? '';
                if ($log_id !== '' && isset($seen[$log_id])) {
                    continue;
                }
                $seen[$log_id] = true;
                $entries[]     = $entry;
            }
        };

        // When the operator already chose an outcome filter, respect it rather
        // than overriding with our own failures-first assumption.
        if (in_array('behaviour', $applied, true)) {
            $primary = $this->search(array_merge($scope, [
                'limit' => self::MAX_CONTEXT_ENTRIES,
                'order' => 'DESC',
            ]));
            $collect($primary['entries']);
        } else {
            // Failures first - they are what a diagnosis usually hinges on.
            $failures = $this->search(array_merge($scope, [
                'behaviour' => ['error', 'warning'],
                'limit'     => self::MAX_CONTEXT_ENTRIES,
                'order'     => 'DESC',
            ]));
            $collect($failures['entries']);
        }

        // Top up with a keyword pass so non-error context is not missed. Skipped
        // when the operator supplied their own search text, which takes priority.
        $remaining = self::MAX_CONTEXT_ENTRIES - count($entries);
        if ($remaining > 0 && !in_array('search_text', $applied, true)) {
            $keywords = $this->extract_keywords($issue);

            if ($keywords !== '') {
                $keyword_hits = $this->search(array_merge($scope, [
                    'search_text' => $keywords,
                    'limit'       => $remaining,
                    'order'       => 'DESC',
                ]));
                $collect($keyword_hits['entries']);
            }
        }

        // Still nothing: fall back to plain recent activity in the same window,
        // dropping the narrowing filters that produced no rows. Without this the
        // model receives an empty context and cannot tell "nothing happened"
        // apart from "nothing matched the filters".
        if (empty($entries)) {
            $window_only = array_intersect_key($scope, array_flip(['date_from', 'date_to', 'owner']));

            $recent = $this->search(array_merge($window_only, [
                'limit' => self::MAX_CONTEXT_ENTRIES,
                'order' => 'DESC',
            ]));
            $collect($recent['entries']);

            if (!empty($entries)) {
                $applied[] = 'no rows matched the filters - showing recent activity instead';
            }
        }

        return [
            'vocabulary'      => $vocabulary,
            'stats'           => $stats,
            'entries'         => array_slice($entries, 0, self::MAX_CONTEXT_ENTRIES),
            'filters_applied' => $applied,
        ];
    }

    /**
     * Extract a search term from the issue description.
     *
     * Picks the longest distinctive words so the keyword pass is targeted
     * rather than matching common filler.
     *
     * @param string $issue The described issue.
     *
     * @return string Space-free search term, or empty string.
     *
     * @since 1.3.0
     */
    private function extract_keywords($issue)
    {
        $stopwords = [
            // Grammar filler.
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'when', 'what', 'why',
            'was', 'were', 'are', 'not', 'but', 'have', 'has', 'had', 'you', 'your',
            'they', 'their', 'there', 'been', 'about', 'into', 'after', 'before',
            'some', 'only', 'just', 'very', 'really', 'like', 'than', 'then', 'over',
            'something', 'anything', 'nothing', 'please', 'help', 'does', 'doesn',
            'cannot', 'cant', 'wont', 'will', 'would', 'could', 'should', 'seems', 'seem',
            // Time words - they describe when, never what.
            'yesterday', 'today', 'tomorrow', 'morning', 'afternoon', 'evening', 'night',
            'week', 'weeks', 'month', 'months', 'year', 'years', 'hour', 'hours',
            'since', 'still', 'again', 'recently', 'lately',
            // Words describing the fault itself rather than the subject of it.
            'error', 'errors', 'issue', 'issues', 'problem', 'problems',
            'failing', 'failed', 'fails', 'failure', 'broken', 'break', 'breaks',
            'working', 'work', 'works', 'stopped', 'stop', 'stops', 'started', 'starts',
            'wrong', 'weird', 'strange',
        ];

        $words = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($issue), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return '';
        }

        $candidates = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, $stopwords, true)) {
                continue;
            }
            $candidates[] = $word;
        }

        if (empty($candidates)) {
            return '';
        }

        // Longest word is the most distinctive signal available cheaply.
        usort($candidates, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        return $candidates[0];
    }

    /**
     * Send the issue and context to the AI client.
     *
     * @param string $issue   The described issue.
     * @param array  $context Gathered log context.
     *
     * @return string|\WP_Error The answer, or WP_Error on failure.
     *
     * @since 1.3.0
     */
    private function run_prompt($issue, array $context)
    {
        $system = $this->build_system_instruction();
        $prompt = $this->build_prompt($issue, $context);

        /**
         * Filter the system instruction used for AI log diagnosis.
         *
         * @param string $system The system instruction.
         *
         * @since 1.3.0
         */
        $system = apply_filters('ewp_logger_diagnose_system_instruction', $system);

        try {
            $result = wp_ai_client_prompt($prompt)
                ->using_system_instruction($system)
                ->generate_text();
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ewp_logger_diagnose_failed',
                sprintf(
                    /* translators: %s: error message */
                    __('The AI request failed: %s', 'extend-wp'),
                    $e->getMessage()
                ),
                ['status' => 502]
            );
        }

        if (is_wp_error($result)) {
            return new \WP_Error(
                'ewp_logger_diagnose_failed',
                sprintf(
                    /* translators: %s: error message */
                    __('The AI request failed: %s', 'extend-wp'),
                    $result->get_error_message()
                ),
                ['status' => 502]
            );
        }

        return (string) $result;
    }

    /**
     * Build the system instruction.
     *
     * @return string System instruction.
     *
     * @since 1.3.0
     */
    private function build_system_instruction()
    {
        return implode(' ', [
            'You are diagnosing a WordPress site using entries from its activity log.',
            'Base your answer only on the log entries provided; do not invent events, times or causes.',
            'If the entries do not explain the issue, say so plainly and name what additional information would help.',
            'Reference specific entries by log_id, and mention request_id when several entries share one request.',
            'Be concise: start with the most likely cause, then the evidence, then a suggested next step.',
        ]);
    }

    /**
     * Build the user prompt containing issue and context.
     *
     * @param string $issue   The described issue.
     * @param array  $context Gathered log context.
     *
     * @return string Prompt text.
     *
     * @since 1.3.0
     */
    private function build_prompt($issue, array $context)
    {
        $stats = $context['stats'];

        // Describe the owners actually present in this window. The registry
        // only knows owners that registered types during this request, so it
        // can disagree with what the log data actually contains - naming the
        // registry list alone would mislead the model.
        $owners = [];
        foreach (array_keys($stats['by_owner'] ?? []) as $slug) {
            $owners[] = $slug . ' (' . EWP_Logger::resolve_owner_label($slug) . ')';
        }

        $lines = [];
        $lines[] = 'REPORTED ISSUE:';
        $lines[] = $issue;
        $lines[] = '';
        $lines[] = 'LOG WINDOW: ' . ($stats['date_from'] ?? '') . ' to ' . ($stats['date_to'] ?? '');
        $lines[] = 'TOTAL ENTRIES IN WINDOW: ' . ($stats['total'] ?? 0);
        $lines[] = 'BREAKDOWN BY OUTCOME: ' . wp_json_encode($stats['by_behaviour'] ?? []);
        $lines[] = 'BREAKDOWN BY OWNER: ' . wp_json_encode($stats['by_owner'] ?? []);
        $lines[] = 'OWNERS IN THIS WINDOW: ' . ($owners ? implode(', ', $owners) : 'none');
        $lines[] = '';
        if (!empty($context['filters_applied'])) {
            $lines[] = 'ACTIVE FILTERS (the operator narrowed the log view to these): '
                . implode(', ', $context['filters_applied']);
        }

        $lines[] = 'LOG ENTRIES (most relevant first, newest first, data payloads truncated):';

        foreach ($context['entries'] as $entry) {
            $lines[] = wp_json_encode([
                'log_id'          => $entry['log_id'] ?? '',
                'created_at'      => $entry['created_at'] ?? '',
                'outcome'         => $entry['behaviour_label'] ?? '',
                'owner'           => $entry['owner'] ?? '',
                'action_type'     => $entry['action_type'] ?? '',
                'object_type'     => $entry['object_type'] ?? '',
                'object_id'       => $entry['object_id'] ?? '',
                'level'           => $entry['level'] ?? '',
                'user'            => $entry['user_display_name'] ?? '',
                'message'         => $entry['message'] ?? '',
                'request_id'      => $entry['request_id'] ?? '',
                'request_context' => $entry['request_context'] ?? '',
                'data_preview'    => $entry['data_preview'] ?? '',
            ]);
        }

        if (empty($context['entries'])) {
            $lines[] = '(no entries matched this window)';
        }

        return implode("\n", $lines);
    }

    /**
     * Add the diagnose UI to the log viewer fields.
     *
     * @param array $fields Existing viewer fields.
     *
     * @return array Modified fields.
     *
     * @since 1.3.0
     */
    public function add_viewer_fields($fields)
    {
        if (!self::is_available()) {
            return $fields;
        }

        $box = [
            'case'         => 'html',
            'value'        => $this->render_diagnose_html(),
            'exclude_meta' => true,
        ];

        // Sit directly above the results table, so the filters the box refers
        // to are rendered above it rather than below.
        $anchor = 'ewp_log_viewer_results';

        if (!isset($fields[$anchor])) {
            $fields['ewp_log_diagnose'] = $box;

            return $fields;
        }

        $reordered = [];
        foreach ($fields as $key => $field) {
            if ($key === $anchor) {
                $reordered['ewp_log_diagnose'] = $box;
            }
            $reordered[$key] = $field;
        }

        return $reordered;
    }

    /**
     * Render the diagnose box markup.
     *
     * @return string HTML output.
     *
     * @since 1.3.0
     */
    private function render_diagnose_html()
    {
        $nonce    = wp_create_nonce('wp_rest');
        $rest_url = esc_url(rest_url('extend-wp/v1'));

        ob_start();
?>
        <div class="ewp-log-diagnose" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">
            <label class="ewp-log-diagnose-label" for="ewp-log-diagnose-issue">
                <?php esc_html_e('Describe the issue', 'extend-wp'); ?>
            </label>
            <p class="ewp-log-diagnose-help">
                <?php esc_html_e('Explain the problem in your own words, then choose which log entries to examine.', 'extend-wp'); ?>
            </p>
            <textarea id="ewp-log-diagnose-issue" class="ewp-log-diagnose-input" rows="3"
                placeholder="<?php esc_attr_e('e.g. Bookings stopped saving yesterday afternoon', 'extend-wp'); ?>"></textarea>
            <div class="ewp-log-diagnose-actions">
                <label for="ewp-log-diagnose-range" class="ewp-log-diagnose-range-label">
                    <?php esc_html_e('Examine:', 'extend-wp'); ?>
                </label>
                <select id="ewp-log-diagnose-range" class="ewp-log-diagnose-range">
                    <option value="filters" selected><?php esc_html_e('Entries matching the filters above', 'extend-wp'); ?></option>
                    <option value="24h"><?php esc_html_e('Last 24 hours', 'extend-wp'); ?></option>
                    <option value="7d"><?php esc_html_e('Last 7 days', 'extend-wp'); ?></option>
                    <option value="30d"><?php esc_html_e('Last 30 days', 'extend-wp'); ?></option>
                    <option value="90d"><?php esc_html_e('Last 3 months', 'extend-wp'); ?></option>
                    <option value="all"><?php esc_html_e('All available logs', 'extend-wp'); ?></option>
                </select>
                <button type="button" id="ewp-log-diagnose-run" class="button button-primary">
                    <?php esc_html_e('Diagnose', 'extend-wp'); ?>
                </button>
                <span id="ewp-log-diagnose-status" class="ewp-log-diagnose-status"></span>
            </div>
            <div id="ewp-log-diagnose-result" class="ewp-log-diagnose-result" hidden></div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Register the diagnose script via the Dynamic Asset Loader.
     *
     * @param array $assets Existing registered assets.
     *
     * @return array Modified assets array.
     *
     * @since 1.3.0
     */
    public function register_assets($assets)
    {
        $assets[] = [
            'handle'    => 'ewp-log-diagnose-script',
            'selector'  => '.ewp-log-diagnose',
            'type'      => 'script',
            'src'       => awm_url . 'assets/js/admin/class-ewp-log-diagnose.js',
            'version'   => '1.0.0',
            'context'   => 'admin',
            'in_footer' => true,
            'module'    => false,
        ];

        return $assets;
    }
}
