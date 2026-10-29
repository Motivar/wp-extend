<?php
/**
 * Field partial: the explanation under a label.
 *
 * Override in a theme at `extend-wp/fields/explanation.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $text Explanation (translated; may contain basic HTML).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<span class="awm-explanation"><?php echo wp_kses_post($variables['text']); ?></span><?php
