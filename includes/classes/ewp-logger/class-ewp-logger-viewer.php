<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight admin log viewer page for the EWP Logger.
 *
 * Registers an EWP options page that renders a filter bar and results container.
 * Data is fetched via the REST API endpoint and rendered client-side by the
 * EWPLogViewer JavaScript class. JS/CSS are loaded via the Dynamic Asset Loader.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_Viewer
{
    /**
     * Initialize the viewer hooks.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_filter('awm_add_options_boxes_filter', [$this, 'register_viewer_page'], 101);
        add_filter('ewp_register_dynamic_assets', [$this, 'register_assets']);
    }

    /**
     * Register the log viewer as an EWP options sub-page.
     *
     * @param array $options Existing options pages.
     *
     * @return array Modified options pages.
     *
     * @since 1.0.0
     */
    public function register_viewer_page($options)
    {
        $options['ewp-log-viewer'] = [
            'title'       => apply_filters('ewp_whitelabel_logger_filter', __('EWP Logger', 'extend-wp')),
            'callback'    => [$this, 'get_viewer_fields'],
            'order'       => 1000000000000,
            'cap'         => EWP_Logger::get_viewer_capability(),
            'hide_submit' => true,
            'parent' => false,
        ];

        return $options;
    }

    /**
     * Return the viewer page fields.
     *
     * Filter selects are registered as awm_show_content fields
     * and auto-populated from registered owners, types, etc.
     * Results table is rendered as an HTML field.
     *
     * @return array Fields array for awm_show_content.
     *
     * @since 1.2.0
     */
    public function get_viewer_fields()
    {
        $retentions_months = isset(EWP_Logger_Settings::get_settings()['retentions_months']) ? EWP_Logger_Settings::get_settings()['retentions_months'] : 6;


        $fields = [];
        $week = date('d-m-Y', strtotime('-1 week'));
        $today = date('d-m-Y');
        $maxDate = date('d-m-Y', strtotime('now'));
        $minDate = date('d-m-Y', strtotime('-' .  $retentions_months . ' months'));

        // Date From filter
        $fields['date_from'] = [
            'case' => 'date',
            'attributes' => array('data-change' => 'date_to', 'value' => $week),
            'label'        => __('From', 'extend-wp'),
            'exclude_meta' => true,
            'date-params' => array('maxDate' => $maxDate, 'minDate' => $minDate),
        ];
        // Date To filter
        $fields['date_to'] = [
            'case' => 'date',
            'label'        => __('To', 'extend-wp'),
            'attributes'   => ['value' => $today],
            'exclude_meta' => true,
            'date-params' => array('maxDate' => $maxDate, 'minDate' => $minDate),
        ];

        // Owner filter
        $fields['owner'] = [
            'case'         => 'select',
            'label'        => __('Owner', 'extend-wp'),
            'options'      => $this->build_owner_options(),
            'attributes'   => ['multiple' => true],
            'exclude_meta' => true,
        ];

        // Action Type filter
        $fields['action_type'] = [
            'case'         => 'select',
            'label'        => __('Action Type', 'extend-wp'),
            'options'      => $this->build_action_type_options(),
            'attributes'   => ['multiple' => true],
            'exclude_meta' => true,
        ];

        // Object Type filter
        $fields['object_type'] = [
            'case'         => 'select',
            'label'        => __('Object Type', 'extend-wp'),
            'options'      => $this->build_object_type_options(),
            'attributes'   => ['multiple' => true],
            'exclude_meta' => true,
        ];

        // Behaviour filter (success / warning / error)
        $fields['behaviour'] = [
            'case'         => 'select',
            'label'        => __('Behaviour', 'extend-wp'),
            'options'      => [
                '1' => ['label' => __('Success', 'extend-wp')],
                '2' => ['label' => __('Warning', 'extend-wp')],
                '0' => ['label' => __('Error', 'extend-wp')],
            ],
            'attributes'   => ['multiple' => true],
            'exclude_meta' => true,
        ];

        // Level filter
        $fields['level'] = [
            'case'         => 'select',
            'label'        => __('Level', 'extend-wp'),
            'options'      => [
                'editor'    => ['label' => __('Editor', 'extend-wp')],
                'developer' => ['label' => __('Developer', 'extend-wp')],
            ],
            'attributes'   => ['multiple' => true],
            'exclude_meta' => true,
        ];



        // Filter / Reset buttons + results table + pagination (HTML block)
        $fields['ewp_log_viewer_results'] = [
            'case'         => 'html',
            'value'        => $this->render_results_html(),
            'exclude_meta' => true,
        ];

        /**
         * Filter the viewer fields before rendering.
         *
         * Allows external plugins to add/remove/modify filter fields.
         *
         * @param array $fields awm_show_content field definitions.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_viewer_fields', $fields);
    }

    /**
     * Build select options from registered owners.
     *
     * @return array Options in awm format: ['value' => ['label' => '...']].
     *
     * @since 1.2.0
     */
    private function build_owner_options()
    {
        $options = [];

        foreach (EWP_Logger::get_registered_owners() as $owner) {
            $options[$owner] = ['label' => EWP_Logger::resolve_owner_label($owner)];
        }

        /**
         * Filter the owner options for the log viewer.
         *
         * @param array $options Owner options in awm format.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_viewer_owner_options', $options);
    }

    /**
     * Build select options from registered action types (flattened across owners).
     *
     * Each option includes a data-owner extra attribute so JS can
     * filter action types by owner client-side.
     *
     * @return array Options in awm format.
     *
     * @since 1.2.0
     */
    private function build_action_type_options()
    {
        $options = [];
        $seen    = [];

        foreach (EWP_Logger::get_registered_types() as $owner => $types) {
            foreach ($types as $type_key => $type_data) {
                // Avoid duplicate keys across owners
                if (isset($seen[$type_key])) {
                    continue;
                }
                $seen[$type_key] = true;

                $options[$type_key] = [
                    'label' => $type_data['label'],
                    'extra' => ['data-owner' => $owner],
                ];
            }
        }

        /**
         * Filter the action type options for the log viewer.
         *
         * @param array $options Action type options in awm format.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_viewer_action_type_options', $options);
    }

    /**
     * Build select options for object types.
     *
     * @return array Options in awm format.
     *
     * @since 1.2.0
     */
    private function build_object_type_options()
    {
        $keys = ['post_type', 'taxonomy', 'user', 'option', 'custom_content', 'database', 'system'];
        $options = [];

        foreach ($keys as $key) {
            $options[$key] = ['label' => EWP_Logger::resolve_object_type_label($key)];
        }

        /**
         * Filter the object type options for the log viewer.
         *
         * @param array $options Object type options in awm format.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_viewer_object_type_options', $options);
    }

    /**
     * Render the results HTML (buttons, table, pagination).
     *
     * The .ewp-log-viewer-wrap class triggers the Dynamic Asset Loader.
     *
     * @return string HTML output.
     *
     * @since 1.2.0
     */
    private function render_results_html()
    {
        $nonce    = wp_create_nonce('wp_rest');
        $rest_url = esc_url(rest_url('extend-wp/v1'));

        ob_start();
?>
        <div class="ewp-log-viewer-wrap" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">

            <!-- Filter Actions -->
            <div class="ewp-log-filter-actions">
                <button type="button" id="ewp-log-filter-apply" class="button button-primary">
                    <?php esc_html_e('Filter', 'extend-wp'); ?>
                </button>
                <button type="button" id="ewp-log-filter-reset" class="button">
                    <?php esc_html_e('Reset', 'extend-wp'); ?>
                </button>
                <span id="ewp-log-total" class="ewp-log-total"></span>

                <!-- Toolbar: per-page, export, delete -->
                <div class="ewp-log-toolbar">
                    <label for="ewp-log-per-page" class="ewp-log-toolbar-label">
                        <?php esc_html_e('Per page:', 'extend-wp'); ?>
                    </label>
                    <select id="ewp-log-per-page" class="ewp-log-per-page">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                    </select>

                    <button type="button" id="ewp-log-export-csv" class="button"
                        title="<?php esc_attr_e('Export filtered entries as CSV', 'extend-wp'); ?>">
                        <?php esc_html_e('Export CSV', 'extend-wp'); ?>
                    </button>

                    <button type="button" id="ewp-log-delete-filtered" class="button ewp-log-btn-danger"
                        title="<?php esc_attr_e('Delete all entries matching current filters', 'extend-wp'); ?>">
                        <?php esc_html_e('Delete Filtered', 'extend-wp'); ?>
                    </button>
                </div>
            </div>

            <!-- Results Table -->
            <div class="ewp-log-results">
                <table class="wp-list-table widefat fixed striped ewp-log-table">
                    <thead>
                        <tr>
                            <th class="ewp-col-date"><?php esc_html_e('Date', 'extend-wp'); ?></th>
                            <th class="ewp-col-owner"><?php esc_html_e('Owner', 'extend-wp'); ?></th>
                            <th class="ewp-col-action"><?php esc_html_e('Action', 'extend-wp'); ?></th>
                            <th class="ewp-col-object"><?php esc_html_e('Object Type', 'extend-wp'); ?></th>
                            <th class="ewp-col-level"><?php esc_html_e('Level', 'extend-wp'); ?></th>
                            <th class="ewp-col-behaviour"><?php esc_html_e('Status', 'extend-wp'); ?></th>
                            <th class="ewp-col-user"><?php esc_html_e('User', 'extend-wp'); ?></th>
                            <th class="ewp-col-message"><?php esc_html_e('Message', 'extend-wp'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ewp-log-tbody">
                        <tr class="ewp-log-loading">
                            <td colspan="8"><?php esc_html_e('Loading...', 'extend-wp'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="ewp-log-pagination" id="ewp-log-pagination"></div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Register JS/CSS via Dynamic Asset Loader.
     *
     * Assets are loaded only when .ewp-log-viewer-wrap is present in the DOM.
     *
     * @param array $assets Existing registered assets.
     *
     * @return array Modified assets array.
     *
     * @since 1.0.0
     */
    public function register_assets($assets)
    {
        $assets[] = [
            'handle'       => 'ewp-log-viewer-style',
            'selector'     => '.ewp-log-viewer-wrap',
            'type'         => 'style',
            'src'          => awm_url . 'assets/css/admin/ewp-log-viewer.min.css',
            'version'      => '1.0.0',
            'context'      => 'admin',
        ];

        $assets[] = [
            'handle'       => 'ewp-log-viewer-script',
            'selector'     => '.ewp-log-viewer-wrap',
            'type'         => 'script',
            'src'          => awm_url . 'assets/js/admin/class-ewp-log-viewer.js',
            'version'      => '1.0.0',
            'context'      => 'admin',
            'in_footer'    => true,
            'dependencies' => [],
            'module'       => false,
        ];

        return $assets;
    }
}
