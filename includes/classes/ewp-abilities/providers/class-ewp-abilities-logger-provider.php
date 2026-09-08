<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write ability for the EWP activity log.
 *
 * The logger's own abilities class is read-only by design, so the write path
 * lives here. This is the ability behind every `flx_log()`-style call: filox
 * and its siblings all funnel into `ewp_log()` with their own owner slug, so
 * one generic write ability covers them all.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Logger_Provider extends EWP_Abilities_Provider
{
    /**
     * Ability category slug, shared with the logger read abilities.
     *
     * @var string
     */
    const CATEGORY = 'ewp-logger';

    /**
     * {@inheritDoc}
     */
    public function category()
    {
        return self::CATEGORY;
    }

    /**
     * Register the category after the logger module has had its chance.
     *
     * @return int
     *
     * @since 1.4.0
     */
    protected function category_priority()
    {
        return 20;
    }

    /**
     * {@inheritDoc}
     */
    public function category_args()
    {
        return [
            'label'       => __('EWP Logger', 'extend-wp'),
            'description' => __('Read and write the EWP activity log.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        return [
            self::CATEGORY . '/write-entry' => $this->definition(
                __('Write a log entry', 'extend-wp'),
                __('Write one entry to the EWP activity log. Use it to record what an automated task did, so the change is auditable next to everything else on the site. Call the logger list-vocabulary ability first to reuse an owner and action type that already exist here rather than inventing new ones. Set behaviour to error when recording a failure.', 'extend-wp'),
                $this->write_input_schema(),
                $this->write_output_schema(),
                [$this, 'run_write_entry'],
                $this->capability_permission($this->viewer_capability(), self::CATEGORY . '/write-entry'),
                $this->meta_write(false)
            ),
        ];
    }

    /**
     * Capability required to write a log entry.
     *
     * @return string
     *
     * @since 1.4.0
     */
    protected function viewer_capability()
    {
        if (class_exists('EWP\Logger\EWP_Logger')) {
            return \EWP\Logger\EWP_Logger::get_viewer_capability();
        }

        return 'manage_options';
    }

    /**
     * Input schema for the write ability.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function write_input_schema()
    {
        return EWP_Abilities_Schema::input([
            'owner'       => [
                'type'        => 'string',
                'description' => __('Which plugin or subsystem the entry belongs to, for example filox or extend-wp.', 'extend-wp'),
            ],
            'action_type' => [
                'type'        => 'string',
                'description' => __('A short machine readable action key, for example content_save or import_run.', 'extend-wp'),
            ],
            'message'     => [
                'type'        => 'string',
                'description' => __('A human readable one line summary of what happened.', 'extend-wp'),
            ],
            'data'        => [
                'type'                 => 'object',
                'additionalProperties' => true,
                'description'          => __('Structured payload stored with the entry. Do not put credentials or personal data here.', 'extend-wp'),
            ],
            'level'       => [
                'type'        => 'string',
                'enum'        => ['editor', 'developer'],
                'description' => __('Audience for the entry. Defaults to editor.', 'extend-wp'),
            ],
            'object_type' => [
                'type'        => 'string',
                'description' => __('What the entry is about, for example post, term or custom_content.', 'extend-wp'),
            ],
            'behaviour'   => [
                'type'        => 'string',
                'enum'        => ['error', 'success', 'warning'],
                'description' => __('Outcome of the action. Defaults to success.', 'extend-wp'),
            ],
        ], ['owner', 'action_type', 'message']);
    }

    /**
     * Output schema for the write ability.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function write_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'logged'     => ['type' => 'boolean'],
                'owner'      => ['type' => 'string'],
                'request_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Behaviour label to storage value.
     *
     * @param string $behaviour Behaviour label.
     *
     * @return int
     *
     * @since 1.4.0
     */
    protected function behaviour_value($behaviour)
    {
        $map = ['error' => 0, 'success' => 1, 'warning' => 2];

        return isset($map[$behaviour]) ? $map[$behaviour] : 1;
    }

    /**
     * Write one log entry.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_write_entry($input = null)
    {
        if (!function_exists('ewp_log') || !class_exists('EWP\Logger\EWP_Logger')) {
            return $this->error('ewp_abilities_logger_unavailable', __('The EWP Logger is not available on this site.', 'extend-wp'), 503);
        }

        if (!\EWP\Logger\EWP_Logger::is_enabled()) {
            return $this->error('ewp_abilities_logger_disabled', __('Logging is switched off on this site, so the entry was not written.', 'extend-wp'), 503);
        }

        $input = $this->normalize_input($input);
        $data  = isset($input['data']) ? $input['data'] : [];
        $data  = is_object($data) ? json_decode(wp_json_encode($data), true) : $data;

        $logged = ewp_log(
            (string) $input['owner'],
            (string) $input['action_type'],
            (string) $input['message'],
            is_array($data) ? $data : [],
            isset($input['level']) ? (string) $input['level'] : 'editor',
            isset($input['object_type']) ? (string) $input['object_type'] : '',
            $this->behaviour_value(isset($input['behaviour']) ? (string) $input['behaviour'] : 'success')
        );

        return [
            'logged'     => (bool) $logged,
            'owner'      => (string) $input['owner'],
            'request_id' => \EWP\Logger\EWP_Logger::get_request_id(),
        ];
    }
}
