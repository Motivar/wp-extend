<?php

/**
 * AWM Modal Field Template
 * 
 * Renders the modal overlay HTML structure for awm_modal field type.
 * This template is loaded via REST API and can be customized via filters.
 * 
 * Available variables:
 * @var string $modal_id     Unique modal identifier
 * @var string $modal_title  Modal header title
 * @var string $fields_html  Rendered fields HTML from awm_show_content()
 * @var array  $args         Original field arguments
 * 
 * @package ExtendWP
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filter modal wrapper classes
 * 
 * @param array  $classes   Default wrapper classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$wrapper_classes = apply_filters('awm_modal_wrapper_classes', array(
    'ewp-ai-modal-overlay',
    'awm-modal-overlay',
), $modal_id, $args);

/**
 * Filter modal dialog classes
 * 
 * @param array  $classes   Default dialog classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$dialog_classes = apply_filters('awm_modal_dialog_classes', array(
    'ewp-ai-modal',
    'awm-modal',
), $modal_id, $args);

/**
 * Filter modal header classes
 * 
 * @param array  $classes   Default header classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$header_classes = apply_filters('awm_modal_header_classes', array(
    'ewp-ai-modal-header',
), $modal_id, $args);

/**
 * Filter modal body classes
 * 
 * @param array  $classes   Default body classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$body_classes = apply_filters('awm_modal_body_classes', array(
    'ewp-ai-modal-body',
    'awm-modal-body',
), $modal_id, $args);

/**
 * Filter modal footer classes
 * 
 * @param array  $classes   Default footer classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$footer_classes = apply_filters('awm_modal_footer_classes', array(
    'ewp-ai-modal-footer',
), $modal_id, $args);

/**
 * Filter save button text
 * 
 * @param string $text      Default button text
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$save_text = apply_filters('awm_modal_save_button_text', __('Save', 'extend-wp'), $modal_id, $args);

/**
 * Filter cancel button text
 * 
 * @param string $text      Default button text
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$cancel_text = apply_filters('awm_modal_cancel_button_text', __('Cancel', 'extend-wp'), $modal_id, $args);

/**
 * Filter save button classes
 * 
 * @param array  $classes   Default button classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$save_button_classes = apply_filters('awm_modal_save_button_classes', array(
    'button',
    'button-primary',
    'awm-modal-save',
), $modal_id, $args);

/**
 * Filter cancel button classes
 * 
 * @param array  $classes   Default button classes
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
$cancel_button_classes = apply_filters('awm_modal_cancel_button_classes', array(
    'button',
    'awm-modal-cancel',
), $modal_id, $args);

/**
 * Action before modal wrapper
 * 
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
do_action('awm_modal_before_wrapper', $modal_id, $args);
?>

<div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>" id="awm-modal-<?php echo esc_attr($modal_id); ?>">
    <div class="<?php echo esc_attr(implode(' ', $dialog_classes)); ?>" role="dialog" aria-modal="true" aria-labelledby="awm-modal-title-<?php echo esc_attr($modal_id); ?>">

        <?php
        /**
         * Action before modal header
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_before_header', $modal_id, $args);
        ?>

        <div class="<?php echo esc_attr(implode(' ', $header_classes)); ?>">
            <h2 id="awm-modal-title-<?php echo esc_attr($modal_id); ?>"><?php echo esc_html($modal_title); ?></h2>
            <button type="button" class="ewp-ai-modal-close" aria-label="<?php esc_attr_e('Close', 'extend-wp'); ?>">✕</button>
        </div>

        <?php
        /**
         * Action after modal header
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_after_header', $modal_id, $args);
        ?>

        <?php
        /**
         * Action before modal body
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_before_body', $modal_id, $args);
        ?>

        <div class="<?php echo esc_attr(implode(' ', $body_classes)); ?>">
            <?php
            /**
             * Filter modal body content
             * 
             * @param string $fields_html Rendered fields HTML
             * @param string $modal_id    Modal identifier
             * @param array  $args        Field arguments
             * @since 1.2.0
             */
            echo apply_filters('awm_modal_body_content', $fields_html, $modal_id, $args);
            ?>
        </div>

        <?php
        /**
         * Action after modal body
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_after_body', $modal_id, $args);
        ?>

        <?php
        /**
         * Action before modal footer
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_before_footer', $modal_id, $args);
        ?>

        <div class="<?php echo esc_attr(implode(' ', $footer_classes)); ?>">
            <?php
            /**
             * Action at start of modal footer
             * 
             * @param string $modal_id  Modal identifier
             * @param array  $args      Field arguments
             * @since 1.2.0
             */
            do_action('awm_modal_footer_start', $modal_id, $args);
            ?>

            <?php
            /**
             * Filter modal footer buttons HTML
             *
             * Allows customization of the default Save/Cancel buttons.
             * Return custom HTML to replace default buttons.
             *
             * @param string $buttons_html          Default buttons HTML
             * @param array  $save_button_classes   Save button CSS classes
             * @param string $save_text             Save button text
             * @param array  $cancel_button_classes Cancel button CSS classes
             * @param string $cancel_text           Cancel button text
             * @param string $modal_id              Modal identifier
             * @param array  $args                  Field arguments
             * @since 1.2.0
             */
            $buttons_html = '';
            ob_start();
            include awm_path . 'templates/admin-view/modal-footer-buttons.php';
            $default_buttons = ob_get_clean();

            echo apply_filters(
                'awm_modal_footer_buttons_html',
                $default_buttons,
                $save_button_classes,
                $save_text,
                $cancel_button_classes,
                $cancel_text,
                $modal_id,
                $args
            );
            ?>

            <?php
            /**
             * Action at end of modal footer
             * 
             * @param string $modal_id  Modal identifier
             * @param array  $args      Field arguments
             * @since 1.2.0
             */
            do_action('awm_modal_footer_end', $modal_id, $args);
            ?>
        </div>

        <?php
        /**
         * Action after modal footer
         * 
         * @param string $modal_id  Modal identifier
         * @param array  $args      Field arguments
         * @since 1.2.0
         */
        do_action('awm_modal_after_footer', $modal_id, $args);
        ?>

    </div>
</div>

<?php
/**
 * Action after modal wrapper
 * 
 * @param string $modal_id  Modal identifier
 * @param array  $args      Field arguments
 * @since 1.2.0
 */
do_action('awm_modal_after_wrapper', $modal_id, $args);
