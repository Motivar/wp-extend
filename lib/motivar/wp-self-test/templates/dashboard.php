<?php
/**
 * Self-test dashboard (Tools › Self-test).
 *
 * Variables from Motivar\SelfTest\Admin::render():
 *
 * @var array[] $cases    Cases from Runner::cases().
 * @var array   $plugins  Registered plugins keyed by slug (label, manifest).
 * @var string  $rest_url REST base for the self-test routes.
 * @var string  $nonce    REST nonce.
 * @var string  $error    Message when the manifests could not be loaded.
 * @var string  $version  Package version.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mwp-self-test" data-rest-url="<?php echo esc_attr($rest_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <h1><?php esc_html_e('Self-test', 'wp-self-test'); ?> <span class="mwp-self-test__version">v<?php echo esc_html($version); ?></span></h1>

    <?php if ($error !== '') : ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <p class="mwp-self-test__intro">
        <?php esc_html_e('Exercises every REST route, WP-CLI command and ability the registered plugins expose, on this site. Cases and their coverage come from each plugin\'s manifest, shared with the pre-push hook and CI. Test data is removed at the end of every run.', 'wp-self-test'); ?>
    </p>

    <?php if (!empty($plugins)) : ?>
        <ul class="mwp-self-test__plugins">
            <?php foreach ($plugins as $slug => $plugin) : ?>
                <li><strong><?php echo esc_html($plugin['label']); ?></strong> <code><?php echo esc_html($slug); ?></code> <span class="mwp-self-test__id"><?php echo esc_html(str_replace(ABSPATH, '', $plugin['manifest'])); ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="mwp-self-test__toolbar">
        <label class="mwp-self-test__filter">
            <span><?php esc_html_e('Plugin', 'wp-self-test'); ?></span>
            <select data-filter="plugin">
                <option value=""><?php esc_html_e('All plugins', 'wp-self-test'); ?></option>
                <?php foreach ($plugins as $slug => $plugin) : ?>
                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($plugin['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mwp-self-test__filter">
            <span><?php esc_html_e('Surface', 'wp-self-test'); ?></span>
            <select data-filter="layer">
                <option value=""><?php esc_html_e('All surfaces', 'wp-self-test'); ?></option>
                <option value="rest">REST</option>
                <option value="cli">WP-CLI</option>
                <option value="ability"><?php esc_html_e('Abilities', 'wp-self-test'); ?></option>
            </select>
        </label>
        <label class="mwp-self-test__select-all">
            <input type="checkbox" data-action="select-all" checked>
            <?php esc_html_e('All cases', 'wp-self-test'); ?>
        </label>
        <button type="button" class="button" data-action="preview"><?php esc_html_e('Preview', 'wp-self-test'); ?></button>
        <button type="button" class="button button-primary" data-action="run"><?php esc_html_e('Run + remove data', 'wp-self-test'); ?></button>
        <span class="mwp-self-test__status" data-role="status" aria-live="polite"></span>
    </div>

    <table class="widefat striped mwp-self-test__cases">
        <thead>
            <tr>
                <th class="mwp-self-test__col-check"></th>
                <th><?php esc_html_e('Case', 'wp-self-test'); ?></th>
                <th><?php esc_html_e('Plugin', 'wp-self-test'); ?></th>
                <th><?php esc_html_e('Category', 'wp-self-test'); ?></th>
                <th><?php esc_html_e('Surfaces', 'wp-self-test'); ?></th>
                <th><?php esc_html_e('Availability', 'wp-self-test'); ?></th>
                <th><?php esc_html_e('Last result', 'wp-self-test'); ?></th>
            </tr>
        </thead>
        <tbody data-role="cases">
            <?php foreach ($cases as $case) : ?>
                <tr data-case="<?php echo esc_attr($case['id']); ?>" data-plugin="<?php echo esc_attr($case['plugin']); ?>" data-layers="<?php echo esc_attr(implode(' ', $case['layers'])); ?>" class="<?php echo $case['available'] ? '' : 'mwp-self-test__case--unavailable'; ?>">
                    <td class="mwp-self-test__col-check">
                        <input type="checkbox" data-role="case-checkbox" value="<?php echo esc_attr($case['id']); ?>" <?php checked($case['available']); ?> <?php disabled(!$case['available']); ?>>
                    </td>
                    <td>
                        <strong><?php echo esc_html($case['label']); ?></strong>
                        <div class="mwp-self-test__id"><code><?php echo esc_html($case['id']); ?></code></div>
                    </td>
                    <td><code><?php echo esc_html($case['plugin']); ?></code></td>
                    <td><?php echo esc_html($case['category']); ?></td>
                    <td>
                        <?php foreach ($case['layers'] as $layer) : ?>
                            <span class="mwp-self-test__layer mwp-self-test__layer--<?php echo esc_attr($layer); ?>"><?php echo esc_html($layer); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($case['available']) : ?>
                            <span class="mwp-self-test__badge mwp-self-test__badge--pass"><?php esc_html_e('available', 'wp-self-test'); ?></span>
                        <?php else : ?>
                            <span class="mwp-self-test__badge mwp-self-test__badge--skip" title="<?php echo esc_attr($case['reason']); ?>"><?php esc_html_e('unavailable', 'wp-self-test'); ?></span>
                            <div class="mwp-self-test__reason"><?php echo esc_html($case['reason']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-role="last-result"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <section class="mwp-self-test__panel" data-role="preview" hidden>
        <h2><?php esc_html_e('Preview', 'wp-self-test'); ?></h2>
        <div data-role="preview-body"></div>
    </section>

    <section class="mwp-self-test__panel" data-role="results" hidden>
        <header class="mwp-self-test__results-header">
            <h2><?php esc_html_e('Results', 'wp-self-test'); ?></h2>
            <a class="button" data-role="download" download="mwp-self-test-report.json" href="#"><?php esc_html_e('Download raw data (JSON)', 'wp-self-test'); ?></a>
        </header>
        <div class="mwp-self-test__summary" data-role="summary"></div>
        <div data-role="results-body"></div>
    </section>

    <template data-template="preview-case">
        <article class="mwp-self-test__preview-case">
            <h3 data-slot="label"></h3>
            <p class="mwp-self-test__reason" data-slot="reason" hidden></p>
            <ol data-slot="steps"></ol>
        </article>
    </template>

    <template data-template="preview-step">
        <li data-slot="step"></li>
    </template>

    <template data-template="result-case">
        <article class="mwp-self-test__result">
            <header class="mwp-self-test__result-header">
                <span class="mwp-self-test__badge" data-slot="status"></span>
                <h3 data-slot="label"></h3>
                <span class="mwp-self-test__duration" data-slot="duration"></span>
            </header>
            <p class="mwp-self-test__message" data-slot="message" hidden></p>
            <table class="mwp-self-test__checks">
                <tbody data-slot="checks"></tbody>
            </table>
            <p class="mwp-self-test__filtered-note" data-slot="filtered" hidden></p>
            <ul class="mwp-self-test__cleanup-list" data-slot="cleanup" hidden></ul>
        </article>
    </template>

    <template data-template="result-check">
        <tr data-check-layer="">
            <td class="mwp-self-test__check-status"><span class="mwp-self-test__badge" data-slot="status"></span></td>
            <td class="mwp-self-test__check-layer"><span class="mwp-self-test__layer" data-slot="layer"></span></td>
            <td data-slot="label"></td>
            <td class="mwp-self-test__check-detail" data-slot="detail"></td>
        </tr>
    </template>

    <template data-template="list-item">
        <li data-slot="text"></li>
    </template>

    <template data-template="summary">
        <dl class="mwp-self-test__summary-grid">
            <div><dt><?php esc_html_e('Finished', 'wp-self-test'); ?></dt><dd data-slot="finished"></dd></div>
            <div><dt><?php esc_html_e('Duration', 'wp-self-test'); ?></dt><dd data-slot="duration"></dd></div>
            <div><dt><?php esc_html_e('Cases', 'wp-self-test'); ?></dt><dd data-slot="cases"></dd></div>
            <div><dt><?php esc_html_e('Checks', 'wp-self-test'); ?></dt><dd data-slot="checks"></dd></div>
        </dl>
    </template>
</div>
