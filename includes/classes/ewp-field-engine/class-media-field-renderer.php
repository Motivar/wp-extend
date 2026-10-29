<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified media field HTML for single image and gallery modes.
 *
 * Backs awm_media_field_html() and the `image` / `awm_gallery` field types.
 * Markup lives in templates/fields/media.php; the JavaScript class
 * AWMMediaField handles the wp.media frame, pre-selection, sorting and removal.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Media_Field_Renderer
{
    /** @var Template_Loader Template resolver. */
    private $templates;

    /**
     * @param Template_Loader|null $templates Template resolver (default: plugin templates).
     */
    public function __construct(?Template_Loader $templates = null)
    {
        $this->templates = $templates ?: new Template_Loader();
    }

    /**
     * Enqueue the media library and the sortable script, once per request.
     *
     * @return void
     */
    public static function enqueue()
    {
        if (did_action('wp_enqueue_media')) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
    }

    /**
     * Unified media field HTML for single image and gallery modes.
     *
     * Renders a container with image previews, remove buttons, hidden inputs,
     * and an add/select button.
     *
     * @since 1.0.0
     *
     * @param string $meta          The meta key / field name used for input names.
     * @param mixed  $val           Current value: single attachment ID or array of IDs.
     * @param int    $max_images    Maximum number of images (0 = unlimited gallery, 1 = single image).
     * @param string $allowed_types Comma-separated MIME types for the media library filter.
     *                              'image' for images only (gallery default), '' for all file types (single default).
     * @return string HTML output.
     */
    public function render($meta, $val, $max_images = 0, $allowed_types = '')
    {
        $images    = self::normalise_ids($val);
        $is_single = ($max_images === 1);

        /* Resolve allowed types: gallery defaults to 'image', single defaults to all */
        if ($allowed_types === '' && $max_images !== 1) {
            $allowed_types = 'image';
        }

        $content = $this->templates->render('fields/media', array(
            'meta'          => $meta,
            'is_single'     => $is_single,
            'max_images'    => $max_images,
            'allowed_types' => $allowed_types,
            'items'         => self::items($images),
            'button_label'  => self::button_label($is_single, empty($images)),
        ));

        /**
         * Filter the unified media field HTML output.
         *
         * @since 1.0.0
         *
         * @param string $content       Generated HTML.
         * @param string $meta          Meta key / field name.
         * @param array  $images        Array of attachment IDs.
         * @param int    $max_images    Maximum number of images.
         * @param string $allowed_types Allowed MIME types.
         */
        return apply_filters('awm_media_field_html_filter', $content, $meta, $images, $max_images, $allowed_types);
    }

    /**
     * Normalise a value to a list of attachment IDs.
     *
     * @param mixed $val Single ID or array of IDs.
     * @return int[] Attachment IDs (zero values dropped for arrays).
     */
    public static function normalise_ids($val)
    {
        if (empty($val)) {
            return array();
        }
        return is_array($val) ? array_filter(array_map('absint', $val)) : array(absint($val));
    }

    /**
     * Preview data per attachment that still exists.
     *
     * @param int[] $images Attachment IDs.
     * @return array<int, array{id:int, thumb:string}>
     */
    private static function items(array $images)
    {
        $items = array();
        foreach ($images as $image_id) {
            if (!$image_id || !get_attached_file($image_id)) {
                continue;
            }
            $items[] = array('id' => $image_id, 'thumb' => self::thumbnail($image_id));
        }
        return $items;
    }

    /**
     * Thumbnail URL, else the WordPress mime-type icon, else the document icon.
     *
     * @param int $image_id Attachment ID.
     * @return string
     */
    private static function thumbnail($image_id)
    {
        $thumb = wp_get_attachment_thumb_url($image_id);
        if ($thumb) {
            return $thumb;
        }
        $icon = wp_mime_type_icon(get_post_mime_type($image_id));
        return $icon ? $icon : home_url() . '/wp-includes/images/media/document.png';
    }

    /**
     * Label of the add / select button.
     *
     * @param bool $is_single Single-file mode.
     * @param bool $empty     No attachment selected yet.
     * @return string Translated label.
     */
    private static function button_label($is_single, $empty)
    {
        if ($is_single) {
            return $empty ? __('Select Image', 'extend-wp') : __('Change Image', 'extend-wp');
        }
        return $empty ? __('Select Images', 'extend-wp') : __('Add Images', 'extend-wp');
    }
}
