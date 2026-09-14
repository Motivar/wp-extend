/**
 * Self-test dashboard (Tools › Self-test).
 *
 * Two actions on the mwp-self-test/v1 REST routes: preview the steps, or
 * run the selected cases with cleanup and show validation, summary and a
 * raw-JSON download. All markup comes from the <template> elements in
 * templates/dashboard.php; this file only clones and fills them. Plain
 * script, no build step; enqueued by Motivar\SelfTest\Admin on its screen.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */
(function () {
  'use strict';

  const STRINGS = (typeof mwpSelfTest !== 'undefined' && mwpSelfTest.strings) ? mwpSelfTest.strings : {};

  const log = (message, data) => {
    if (typeof window.console !== 'undefined' && typeof console.debug === 'function' && window.mwpSelfTestDebug) {
      console.debug('[Self-test] ' + message, data);
    }
  };

  class MwpSelfTestDashboard {
  constructor(root) {
    this.root = root;
    this.restUrl = root.dataset.restUrl;
    this.nonce = root.dataset.nonce;
    this.status = root.querySelector('[data-role="status"]');
    this.panels = {
      preview: root.querySelector('[data-role="preview"]'),
      results: root.querySelector('[data-role="results"]'),
    };
    this.downloadUrl = null;

    root.addEventListener('click', (event) => this.onClick(event));
    root.addEventListener('change', (event) => this.onChange(event));
  }

  onClick(event) {
    const button = event.target.closest('button[data-action]');
    if (!button) {
      return;
    }
    if (button.dataset.action === 'preview') {
      this.preview();
    } else if (button.dataset.action === 'run') {
      this.run();
    }
  }

  onChange(event) {
    if (event.target.dataset.action === 'select-all') {
      this.root.querySelectorAll('[data-role="case-checkbox"]:not(:disabled)').forEach((box) => {
        box.checked = event.target.checked;
      });
    }
  }

  selectedIds() {
    return Array.from(this.root.querySelectorAll('[data-role="case-checkbox"]:checked')).map((box) => box.value);
  }

  async request(path, method = 'GET', body = null) {
    const url = new URL(this.restUrl + path);
    const options = {
      method,
      headers: { 'X-WP-Nonce': this.nonce, 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };

    if (method === 'GET' && body) {
      Object.entries(body).forEach(([key, value]) => url.searchParams.set(key, Array.isArray(value) ? value.join(',') : value));
    } else if (body) {
      options.body = JSON.stringify(body);
    }

    const response = await fetch(url.toString(), options);
    const data = await response.json();

    if (!response.ok) {
      throw new Error(data && data.message ? data.message : STRINGS.error || 'Request failed.');
    }

    return data;
  }

  setBusy(message) {
    this.root.classList.toggle('mwp-self-test--busy', Boolean(message));
    this.root.querySelectorAll('button[data-action]').forEach((button) => {
      button.disabled = Boolean(message);
    });
    this.status.textContent = message || '';
  }

  showPanel(name) {
    Object.entries(this.panels).forEach(([key, panel]) => {
      panel.hidden = key !== name;
    });
  }

  template(name) {
    return this.root.querySelector(`template[data-template="${name}"]`).content.firstElementChild.cloneNode(true);
  }

  fill(node, slot, text) {
    const selector = `[data-slot="${slot}"]`;
    const target = node.matches(selector) ? node : node.querySelector(selector);
    if (target) {
      target.textContent = text;
    }
    return target;
  }

  badge(node, slot, status) {
    const target = this.fill(node, slot, STRINGS[status === 'error' ? 'error_status' : status] || status);
    if (target) {
      target.className = `mwp-self-test__badge mwp-self-test__badge--${status}`;
    }
  }

  async preview() {
    const ids = this.selectedIds();
    if (!ids.length) {
      this.status.textContent = STRINGS.noSelection || '';
      return;
    }

    this.setBusy(STRINGS.previewing);
    try {
      const cases = await this.request('/preview', 'GET', { ids });
      const body = this.panels.preview.querySelector('[data-role="preview-body"]');
      body.replaceChildren();

      cases.forEach((item) => {
        const node = this.template('preview-case');
        this.fill(node, 'label', item.label);
        if (!item.available) {
          this.fill(node, 'reason', `${STRINGS.unavailable || 'unavailable'}: ${item.reason}`).hidden = false;
        }
        const steps = node.querySelector('[data-slot="steps"]');
        item.steps.forEach((step) => {
          const li = this.template('preview-step');
          this.fill(li, 'step', step);
          steps.appendChild(li);
        });
        body.appendChild(node);
      });

      this.showPanel('preview');
      this.setBusy('');
    } catch (error) {
      log('preview failed', error);
      this.setBusy('');
      this.status.textContent = error.message;
    }
  }

  async run() {
    const ids = this.selectedIds();
    if (!ids.length) {
      this.status.textContent = STRINGS.noSelection || '';
      return;
    }

    this.setBusy(STRINGS.running);
    try {
      const report = await this.request('/run', 'POST', { ids, cleanup: true });
      this.renderResults(report);
      this.showPanel('results');
      this.setBusy('');
    } catch (error) {
      log('run failed', error);
      this.setBusy('');
      this.status.textContent = error.message;
    }
  }

  renderResults(report) {
    const body = this.panels.results.querySelector('[data-role="results-body"]');
    body.replaceChildren();

    (report.results || []).forEach((result) => {
      const node = this.template('result-case');
      this.badge(node, 'status', result.status);
      this.fill(node, 'label', result.label);
      this.fill(node, 'duration', `${result.duration_ms} ms`);

      if (result.message) {
        this.fill(node, 'message', result.message).hidden = false;
      }

      const checks = node.querySelector('[data-slot="checks"]');
      (result.checks || []).forEach((check) => {
        const row = this.template('result-check');
        this.badge(row, 'status', check.status);
        this.fill(row, 'layer', check.layer).className = `mwp-self-test__layer mwp-self-test__layer--${check.layer}`;
        this.fill(row, 'label', check.label);
        this.fill(row, 'detail', check.detail || '');
        checks.appendChild(row);
      });

      if (result.cleanup && result.cleanup.length) {
        const list = node.querySelector('[data-slot="cleanup"]');
        list.hidden = false;
        result.cleanup.forEach((message) => {
          const li = this.template('list-item');
          this.fill(li, 'text', message);
          list.appendChild(li);
        });
      }

      body.appendChild(node);

      const cell = this.root.querySelector(`tr[data-case="${result.id}"] [data-role="last-result"]`);
      if (cell) {
        cell.replaceChildren();
        const badge = document.createElement('span');
        badge.className = `mwp-self-test__badge mwp-self-test__badge--${result.status}`;
        badge.textContent = STRINGS[result.status === 'error' ? 'error_status' : result.status] || result.status;
        cell.appendChild(badge);
      }
    });

    this.renderSummary(report);
    this.renderDownload(report);
  }

  renderSummary(report) {
    const host = this.panels.results.querySelector('[data-role="summary"]');
    host.replaceChildren();

    if (!report || !report.summary) {
      return;
    }

    const node = this.template('summary');
    const s = report.summary;
    this.fill(node, 'finished', report.finished_at);
    this.fill(node, 'duration', `${report.duration_ms} ms`);
    this.fill(node, 'cases', `${s.passed} ${STRINGS.pass || 'pass'} · ${s.failed} ${STRINGS.fail || 'fail'} · ${s.skipped} ${STRINGS.skip || 'skipped'} · ${s.errors} ${STRINGS.error_status || 'error'}`);
    this.fill(node, 'checks', `${s.checks.pass} ${STRINGS.pass || 'pass'} · ${s.checks.fail} ${STRINGS.fail || 'fail'} · ${s.checks.skip} ${STRINGS.skip || 'skipped'}`);
    host.appendChild(node);
  }

  renderDownload(report) {
    const link = this.panels.results.querySelector('[data-role="download"]');
    if (this.downloadUrl) {
      URL.revokeObjectURL(this.downloadUrl);
    }
    this.downloadUrl = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' }));
    link.href = this.downloadUrl;
    link.download = `mwp-self-test-report-${(report.finished_at || '').replace(/[:+]/g, '-')}.json`;
  }
}

  document.querySelectorAll('.mwp-self-test').forEach((root) => {
    if (!root.dataset.mwpSelfTestReady) {
      root.dataset.mwpSelfTestReady = '1';
      new MwpSelfTestDashboard(root);
    }
  });
})();
