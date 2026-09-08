<?php
/**
 * Self-test dashboard.
 *
 * Variables from EWP_Self_Test_Admin::render():
 *
 * @var array[]     $cases    Cases from EWP_Self_Test_Runner::cases().
 * @var string      $rest_url REST base for the self-test routes.
 * @var string      $nonce    REST nonce.
 * @var string      $manifest Manifest file path.
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ewp-self-test" data-rest-url="<?php echo esc_attr($rest_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">

    <p class="ewp-self-test__intro">
        <?php esc_html_e('Exercises every REST route, WP-CLI command and ability the plugin exposes, on this site. Cases and their coverage come from one manifest, shared with the pre-push hook and CI. Test data is removed at the end of every run.', 'extend-wp'); ?>
        <code><?php echo esc_html(str_replace(ABSPATH, '', $manifest)); ?></code>
    </p>

    <div class="ewp-self-test__toolbar">
        <label class="ewp-self-test__select-all">
            <input type="checkbox" data-action="select-all" checked>
            <?php esc_html_e('All cases', 'extend-wp'); ?>
        </label>
        <button type="button" class="button" data-action="preview"><?php esc_html_e('Preview', 'extend-wp'); ?></button>
        <button type="button" class="button button-primary" data-action="run"><?php esc_html_e('Run + remove data', 'extend-wp'); ?></button>
        <span class="ewp-self-test__status" data-role="status" aria-live="polite"></span>
    </div>

    <table class="widefat striped ewp-self-test__cases">
        <thead>
            <tr>
                <th class="ewp-self-test__col-check"></th>
                <th><?php esc_html_e('Case', 'extend-wp'); ?></th>
                <th><?php esc_html_e('Category', 'extend-wp'); ?></th>
                <th><?php esc_html_e('Surfaces', 'extend-wp'); ?></th>
                <th><?php esc_html_e('Availability', 'extend-wp'); ?></th>
                <th><?php esc_html_e('Last result', 'extend-wp'); ?></th>
            </tr>
        </thead>
        <tbody data-role="cases">
            <?php foreach ($cases as $case) : ?>
                <tr data-case="<?php echo esc_attr($case['id']); ?>" class="<?php echo $case['available'] ? '' : 'ewp-self-test__case--unavailable'; ?>">
                    <td class="ewp-self-test__col-check">
                        <input type="checkbox" data-role="case-checkbox" value="<?php echo esc_attr($case['id']); ?>" <?php checked($case['available']); ?> <?php disabled(!$case['available']); ?>>
                    </td>
                    <td>
                        <strong><?php echo esc_html($case['label']); ?></strong>
                        <div class="ewp-self-test__id"><code><?php echo esc_html($case['id']); ?></code></div>
                    </td>
                    <td><?php echo esc_html($case['category']); ?></td>
                    <td>
                        <?php foreach ($case['layers'] as $layer) : ?>
                            <span class="ewp-self-test__layer ewp-self-test__layer--<?php echo esc_attr($layer); ?>"><?php echo esc_html($layer); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($case['available']) : ?>
                            <span class="ewp-self-test__badge ewp-self-test__badge--pass"><?php esc_html_e('available', 'extend-wp'); ?></span>
                        <?php else : ?>
                            <span class="ewp-self-test__badge ewp-self-test__badge--skip" title="<?php echo esc_attr($case['reason']); ?>"><?php esc_html_e('unavailable', 'extend-wp'); ?></span>
                            <div class="ewp-self-test__reason"><?php echo esc_html($case['reason']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-role="last-result"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <section class="ewp-self-test__panel" data-role="preview" hidden>
        <h2><?php esc_html_e('Preview', 'extend-wp'); ?></h2>
        <div data-role="preview-body"></div>
    </section>

    <section class="ewp-self-test__panel" data-role="results" hidden>
        <header class="ewp-self-test__results-header">
            <h2><?php esc_html_e('Results', 'extend-wp'); ?></h2>
            <a class="button" data-role="download" download="ewp-self-test-report.json" href="#"><?php esc_html_e('Download raw data (JSON)', 'extend-wp'); ?></a>
        </header>
        <div class="ewp-self-test__summary" data-role="summary"></div>
        <div data-role="results-body"></div>
    </section>

    <template data-template="preview-case">
        <article class="ewp-self-test__preview-case">
            <h3 data-slot="label"></h3>
            <p class="ewp-self-test__reason" data-slot="reason" hidden></p>
            <ol data-slot="steps"></ol>
        </article>
    </template>

    <template data-template="preview-step">
        <li data-slot="step"></li>
    </template>

    <template data-template="result-case">
        <article class="ewp-self-test__result">
            <header class="ewp-self-test__result-header">
                <span class="ewp-self-test__badge" data-slot="status"></span>
                <h3 data-slot="label"></h3>
                <span class="ewp-self-test__duration" data-slot="duration"></span>
            </header>
            <p class="ewp-self-test__message" data-slot="message" hidden></p>
            <table class="ewp-self-test__checks">
                <tbody data-slot="checks"></tbody>
            </table>
            <ul class="ewp-self-test__cleanup-list" data-slot="cleanup" hidden></ul>
        </article>
    </template>

    <template data-template="result-check">
        <tr>
            <td class="ewp-self-test__check-status"><span class="ewp-self-test__badge" data-slot="status"></span></td>
            <td class="ewp-self-test__check-layer"><span class="ewp-self-test__layer" data-slot="layer"></span></td>
            <td data-slot="label"></td>
            <td class="ewp-self-test__check-detail" data-slot="detail"></td>
        </tr>
    </template>

    <template data-template="list-item">
        <li data-slot="text"></li>
    </template>

    <template data-template="summary">
        <dl class="ewp-self-test__summary-grid">
            <div><dt><?php esc_html_e('Finished', 'extend-wp'); ?></dt><dd data-slot="finished"></dd></div>
            <div><dt><?php esc_html_e('Duration', 'extend-wp'); ?></dt><dd data-slot="duration"></dd></div>
            <div><dt><?php esc_html_e('Cases', 'extend-wp'); ?></dt><dd data-slot="cases"></dd></div>
            <div><dt><?php esc_html_e('Checks', 'extend-wp'); ?></dt><dd data-slot="checks"></dd></div>
        </dl>
    </template>
</div>
