<?php
/**
 * Field template: case `awm_tab`.
 *
 * Override in a theme at `extend-wp/fields/tabs.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes. The
 * first tab is open; `awm_open_tab()` (admin script) switches panels.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context Resolved field state.
 *     @type array                     $panels  Per tab: `id`, `label`, `inner` (rendered nested fields).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context     = $variables['context'];
$explanation = $context->explanation();
?>
<div class="awm-tab-wrapper"><div class="awm-tab-wrapper-title"><?php echo wp_kses_post($context->field('label', '')); ?></div><div class="awm-tab"><?php
foreach ($variables['panels'] as $index => $panel) :
?><div id="<?php echo esc_attr($panel['id']); ?>_tab" class="awm_tablinks <?php echo 0 === $index ? 'awm-tab-show active' : ''; ?>" onclick="awm_open_tab(event,'<?php echo esc_js($panel['id']); ?>')"><?php echo wp_kses_post($panel['label']); ?></div><?php
endforeach;
?></div><?php
foreach ($variables['panels'] as $index => $panel) :
?><div id="<?php echo esc_attr($panel['id']); ?>_content_tab" class="awm_tabcontent" <?php echo 0 === $index ? 'style="display: block;"' : ''; ?>><?php
    if ($explanation) :
?><div class="tab-explanation"><?php echo wp_kses_post($explanation); ?></div><?php
    endif;
    echo $panel['inner']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_content() output.
?></div><?php
endforeach;
?></div><?php
