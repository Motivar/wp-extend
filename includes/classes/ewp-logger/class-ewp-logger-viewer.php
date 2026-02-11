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
            'title'       => __('Log Viewer', 'extend-wp'),
            'callback'    => [$this, 'get_viewer_fields'],
            'parent'      => 'extend-wp',
            'order'       => 1000000000000,
            'cap'         => 'manage_options',
            'hide_submit' => true,
        ];

        return $options;
    }

    /**
     * Return the viewer page fields (filter bar + results container).
     *
     * Uses awm_show_content field format for the filter form.
     * The results are rendered client-side by the JS class.
     *
     * @return array Fields array for awm_show_content.
     *
     * @since 1.0.0
     */
    public function get_viewer_fields()
    {
        $settings = EWP_Logger_Settings::get_settings();

        return [
            'ewp_log_viewer_wrap' => [
                'case'  => 'html',
                'value' => $this->render_viewer_html($settings),
                'exclude_meta' => true,
            ],
        ];
    }

    /**
     * Render the log viewer HTML.
     *
     * Outputs the filter bar, results table container, and pagination.
     * The .ewp-log-viewer-wrap class triggers Dynamic Asset Loader.
     *
     * @param array $settings Logger settings.
     *
     * @return string HTML output.
     *
     * @since 1.0.0
     */
    private function render_viewer_html($settings)
    {
        $default_level = esc_attr($settings['ewp_logger_default_level'] ?? '');
        $nonce         = wp_create_nonce('wp_rest');
        $rest_url      = esc_url(rest_url('extend-wp/v1'));

        ob_start();
        ?>
<div class="ewp-log-viewer-wrap" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>"
 data-default-level="<?php echo $default_level; ?>">

 <!-- Filter Bar -->
 <div class="ewp-log-filters">
  <div class="ewp-log-filter-row">
   <label for="ewp-log-filter-owner"><?php esc_html_e('Owner', 'extend-wp'); ?></label>
   <select id="ewp-log-filter-owner" class="ewp-log-filter" data-filter="owner">
    <option value=""><?php esc_html_e('All', 'extend-wp'); ?></option>
   </select>

   <label for="ewp-log-filter-action-type"><?php esc_html_e('Action Type', 'extend-wp'); ?></label>
   <select id="ewp-log-filter-action-type" class="ewp-log-filter" data-filter="action_type">
    <option value=""><?php esc_html_e('All', 'extend-wp'); ?></option>
   </select>

   <label for="ewp-log-filter-object-type"><?php esc_html_e('Object Type', 'extend-wp'); ?></label>
   <select id="ewp-log-filter-object-type" class="ewp-log-filter" data-filter="object_type">
    <option value=""><?php esc_html_e('All', 'extend-wp'); ?></option>
    <option value="post_type"><?php esc_html_e('Post Type', 'extend-wp'); ?></option>
    <option value="taxonomy"><?php esc_html_e('Taxonomy', 'extend-wp'); ?></option>
    <option value="user"><?php esc_html_e('User', 'extend-wp'); ?></option>
    <option value="option"><?php esc_html_e('Option', 'extend-wp'); ?></option>
    <option value="custom_content"><?php esc_html_e('Custom Content', 'extend-wp'); ?></option>
    <option value="database"><?php esc_html_e('Database', 'extend-wp'); ?></option>
    <option value="system"><?php esc_html_e('System', 'extend-wp'); ?></option>
   </select>
  </div>

  <div class="ewp-log-filter-row">
   <label for="ewp-log-filter-behaviour"><?php esc_html_e('Behaviour', 'extend-wp'); ?></label>
   <select id="ewp-log-filter-behaviour" class="ewp-log-filter" data-filter="behaviour">
    <option value=""><?php esc_html_e('All', 'extend-wp'); ?></option>
    <option value="1"><?php esc_html_e('Success', 'extend-wp'); ?></option>
    <option value="2"><?php esc_html_e('Warning', 'extend-wp'); ?></option>
    <option value="0"><?php esc_html_e('Error', 'extend-wp'); ?></option>
   </select>

   <label for="ewp-log-filter-level"><?php esc_html_e('Level', 'extend-wp'); ?></label>
   <select id="ewp-log-filter-level" class="ewp-log-filter" data-filter="level">
    <option value=""><?php esc_html_e('All', 'extend-wp'); ?></option>
    <option value="editor" <?php selected($default_level, 'editor'); ?>><?php esc_html_e('Editor', 'extend-wp'); ?>
    </option>
    <option value="developer" <?php selected($default_level, 'developer'); ?>>
     <?php esc_html_e('Developer', 'extend-wp'); ?></option>
   </select>

   <label for="ewp-log-filter-date-from"><?php esc_html_e('From', 'extend-wp'); ?></label>
   <input type="date" id="ewp-log-filter-date-from" class="ewp-log-filter" data-filter="date_from" />

   <label for="ewp-log-filter-date-to"><?php esc_html_e('To', 'extend-wp'); ?></label>
   <input type="date" id="ewp-log-filter-date-to" class="ewp-log-filter" data-filter="date_to" />
  </div>

  <div class="ewp-log-filter-row ewp-log-filter-actions">
   <button type="button" id="ewp-log-filter-apply" class="button button-primary">
    <?php esc_html_e('Filter', 'extend-wp'); ?>
   </button>
   <button type="button" id="ewp-log-filter-reset" class="button">
    <?php esc_html_e('Reset', 'extend-wp'); ?>
   </button>
   <span id="ewp-log-total" class="ewp-log-total"></span>
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
            'src'          => awm_url . 'assets/css/admin/ewp-log-viewer.css',
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