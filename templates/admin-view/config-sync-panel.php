<?php
/**
 * Settings › Extend WP › Export: configuration sync status and the "Migrate to per-object files" button.
 *
 * Behaviour: assets/js/modules/ewp-config-sync.js (loaded when `.ewp-config-sync` is on the page).
 *
 * @var array $variables {
 *     @type bool $dormant     A stored Auto export setting is ignored until the settings are saved again.
 *     @type bool $can_migrate The per-object migration button is offered (layout is still `single`, or the legacy file exists).
 * }
 *
 * @since 1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ewp-config-sync">
    <?php if (!empty($variables['dormant'])) : ?>
        <p class="notice notice-warning inline ewp-config-sync__dormant">
            <?php esc_html_e('Auto export is currently inactive: save these settings again to enable it.', 'extend-wp'); ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($variables['can_migrate'])) : ?>
        <p>
            <button type="button" class="button" data-ewp-config-sync-action="migrate">
                <?php esc_html_e('Migrate to per-object files', 'extend-wp'); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e('Writes one file per object to the ewp-config folder and renames ewp_configuration.json to ewp_configuration.legacy.json. Update the plugin on every importing environment first.', 'extend-wp'); ?>
        </p>
    <?php endif; ?>
    <p class="ewp-config-sync__result" role="status" aria-live="polite"></p>
</div>
