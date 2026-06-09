/**
 * EWP REST Health — Admin Shell
 *
 * Reads the PHP-rendered plugin multiselect and method filter checkboxes.
 * Initialises Swagger UI only when the effective spec URL changes — preventing
 * reloads when the user interacts with endpoints inside Swagger UI.
 *
 * Features:
 *  - Route search that DOM-filters Swagger UI operations without spec reload
 *  - Start / Stop live traffic monitor (auto-stops after 10 min)
 *  - Captured payloads panel with per-route "Show test data" toggle
 *  - Health status panel (last run results)
 *
 * @package EWP\RestHealth
 * @version 1.2.0
 */
(function () {
    'use strict';

    const cfg          = window.ewpRestHealth || {};
    const STORAGE_KEY  = 'ewp_rh_selected_plugins';
    const POLL_MS      = 4000;   // poll interval while monitoring is active

    document.addEventListener('DOMContentLoaded', () => {
        const wrap = document.querySelector('.ewp-rest-health-wrap');
        if (!wrap) return;
        new EWPRestHealth(wrap);
    });

    // =========================================================================

    class EWPRestHealth {

        constructor(wrap) {
            this.wrap           = wrap;
            this.nonce          = wrap.dataset.nonce   || '';
            this.restUrl        = wrap.dataset.restUrl || '';
            this.str            = cfg.strings || {};
            this.swaggerUi      = null;
            this.currentSpecUrl = null;
            this.monitorPoller  = null;
            this.countdownTimer = null;

            // ── DOM refs ────────────────────────────────────────────────────
            this.$search        = wrap.querySelector('#ewp-rh-search');
            this.$swaggerPanel  = wrap.querySelector('#ewp-rh-swagger-panel');
            this.$refreshBtn    = wrap.querySelector('.ewp-rh-refresh');
            this.$monitorStart  = wrap.querySelector('.ewp-rh-monitor-start');
            this.$monitorStop   = wrap.querySelector('.ewp-rh-monitor-stop');
            this.$monitorStatus = wrap.querySelector('.ewp-rh-monitor-status');
            this.$countdown     = wrap.querySelector('.ewp-rh-monitor-countdown');
            this.$payloadsPanel = wrap.querySelector('.ewp-rh-payloads-panel');
            this.$payloadsList  = wrap.querySelector('.ewp-rh-payloads-list');
            this.$payloadsClear = wrap.querySelector('.ewp-rh-payloads-clear');

            // Plugin select + method CBs are sibling PHP-rendered fields
            this.$plugins   = document.getElementById('ewp-rh-plugin-select');
            this.$methodCbs = Array.from(wrap.querySelectorAll('.ewp-rh-method-cb'));

            this.bindEvents();
            this.restoreAndInit();
            this.loadMonitorStatus();
            this.loadPayloads();
        }

        // -------------------------------------------------------------------------
        // Events
        // -------------------------------------------------------------------------

        bindEvents() {
            // Prevent AWM form from submitting (Swagger Execute can bubble)
            const form = this.wrap.closest('form');
            if (form) form.addEventListener('submit', ev => ev.preventDefault());

            // Plugin selection → reload spec
            if (this.$plugins) {
                this.$plugins.addEventListener('change', () => {
                    this.saveSelection(this.getSelected());
                    this.initSwagger();
                });
            }

            // Method checkboxes → reload spec
            this.$methodCbs.forEach(cb => cb.addEventListener('change', () => this.initSwagger()));

            // Refresh → force spec reload
            if (this.$refreshBtn) {
                this.$refreshBtn.addEventListener('click', () => {
                    this.currentSpecUrl = null;
                    this.initSwagger();
                });
            }

            // Search input → DOM-filter Swagger operations (no spec reload)
            if (this.$search) {
                this.$search.addEventListener('input', () => this.filterSwaggerOps(this.$search.value.trim()));
            }

            // Monitor start / stop
            if (this.$monitorStart) this.$monitorStart.addEventListener('click', () => this.startMonitor());
            if (this.$monitorStop)  this.$monitorStop.addEventListener('click',  () => this.stopMonitor());

            // Clear captured payloads
            if (this.$payloadsClear) this.$payloadsClear.addEventListener('click', () => this.clearPayloads());
        }

        // -------------------------------------------------------------------------
        // Restore selection and boot Swagger UI
        // -------------------------------------------------------------------------

        restoreAndInit() {
            if (!this.$plugins) return;
            const saved = this.restoreSelection();
            if (saved.length) {
                Array.from(this.$plugins.options).forEach(opt => {
                    opt.selected = saved.includes(opt.value);
                });
                this.initSwagger();
            }
        }

        getSelected()   {
            return this.$plugins
                ? Array.from(this.$plugins.selectedOptions).map(o => o.value)
                : [];
        }

        getSelectedMethods() {
            return this.$methodCbs.filter(cb => cb.checked).map(cb => cb.value);
        }

        // -------------------------------------------------------------------------
        // Swagger UI — only reinit when spec URL changes
        // -------------------------------------------------------------------------

        initSwagger() {
            const selected = this.getSelected();

            if (!selected.length) {
                this.currentSpecUrl = null;
                if (this.$swaggerPanel) {
                    this.$swaggerPanel.innerHTML = '<p class="ewp-rh-hint">'
                        + e(this.str.selectPlugin || 'Select a plugin above.') + '</p>';
                }
                return;
            }

            if (typeof SwaggerUIBundle === 'undefined') {
                if (this.$swaggerPanel) {
                    this.$swaggerPanel.innerHTML = '<p class="ewp-rh-hint notice notice-error">'
                        + e(this.str.swaggerError || 'Swagger UI not loaded.') + '</p>';
                }
                return;
            }

            const methods = this.getSelectedMethods();
            const specUrl = this.restUrl + 'openapi?'
                + selected.map(p => 'plugins[]=' + encodeURIComponent(p)).join('&')
                + (methods.length < 5 ? '&' + methods.map(m => 'methods[]=' + m).join('&') : '');

            // KEY FIX: bail if this exact spec is already rendered
            if (specUrl === this.currentSpecUrl && this.swaggerUi) return;

            this.currentSpecUrl = specUrl;
            if (this.$swaggerPanel) this.$swaggerPanel.innerHTML = '';

            const nonce = this.nonce;
            this.swaggerUi = SwaggerUIBundle({
                url:              specUrl,
                dom_id:           '#ewp-rh-swagger-panel',
                presets:          [SwaggerUIBundle.presets.apis],
                layout:           'BaseLayout',
                tryItOutEnabled:  true,
                filter:           false,   // we supply our own search box above
                deepLinking:      false,
                requestInterceptor(req) {
                    req.headers['X-WP-Nonce'] = nonce;
                    return req;
                },
                onComplete: () => {
                    // Re-apply any active search after spec finishes rendering
                    if (this.$search && this.$search.value.trim()) {
                        this.filterSwaggerOps(this.$search.value.trim());
                    }
                },
            });
        }

        // -------------------------------------------------------------------------
        // Route search — DOM filter (no spec reload)
        // -------------------------------------------------------------------------

        filterSwaggerOps(text) {
            const q = text.toLowerCase();
            const panel = this.wrap.querySelector('#ewp-rh-swagger-panel');
            if (!panel) return;

            // Filter individual operations
            panel.querySelectorAll('.opblock').forEach(op => {
                const path = (op.querySelector('.opblock-summary-path')?.textContent || '').toLowerCase();
                const meth = (op.querySelector('.opblock-summary-method')?.textContent || '').toLowerCase();
                op.style.display = (!q || path.includes(q) || meth.includes(q)) ? '' : 'none';
            });

            // Hide tag sections whose all operations are hidden
            panel.querySelectorAll('.opblock-tag-section').forEach(section => {
                const visible = [...section.querySelectorAll('.opblock')]
                    .some(op => op.style.display !== 'none');
                section.style.display = (!q || visible) ? '' : 'none';
            });
        }

        // -------------------------------------------------------------------------
        // Monitor — Start / Stop / Poll
        // -------------------------------------------------------------------------

        async loadMonitorStatus() {
            try {
                const data = await this.get('monitor');
                this.applyMonitorState(data);
            } catch (_) {}
        }

        async startMonitor() {
            const plugins = this.getSelected();
            if (!plugins.length) {
                alert('Select at least one plugin to monitor.');
                return;
            }
            try {
                const data = await this.post('monitor', { action: 'start', plugins });
                this.applyMonitorState(data);
                this.startPolling();
            } catch (_) {}
        }

        async stopMonitor() {
            try {
                const data = await this.post('monitor', { action: 'stop', plugins: [] });
                this.applyMonitorState(data);
                this.stopPolling();
                // Refresh payloads once after stopping
                await this.loadPayloads();
            } catch (_) {}
        }

        startPolling() {
            this.stopPolling();
            this.monitorPoller = setInterval(async () => {
                try {
                    const data = await this.get('monitor');
                    this.applyMonitorState(data);
                    // Also refresh payloads while active
                    await this.loadPayloads();
                    if (!data.active) this.stopPolling();
                } catch (_) {}
            }, POLL_MS);
        }

        stopPolling() {
            if (this.monitorPoller) {
                clearInterval(this.monitorPoller);
                this.monitorPoller = null;
            }
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        }

        applyMonitorState(data) {
            const active    = !!data.active;
            const count     = data.captured_count || 0;
            const remaining = data.remaining_sec  || 0;

            if (this.$monitorStart) this.$monitorStart.hidden = active;
            if (this.$monitorStop)  this.$monitorStop.hidden  = !active;

            if (this.$monitorStatus) {
                this.$monitorStatus.textContent = active
                    ? '● Monitoring — ' + count + ' captured'
                    : (count > 0 ? count + ' payloads captured' : 'Off');
                this.$monitorStatus.className = 'ewp-rh-monitor-status '
                    + (active ? 'ewp-rh-monitor-on' : 'ewp-rh-monitor-off');
            }

            // Countdown timer
            if (this.$countdown) {
                if (active && remaining > 0) {
                    this.$countdown.hidden = false;
                    this.runCountdown(remaining);
                } else {
                    this.$countdown.hidden   = true;
                    this.$countdown.textContent = '';
                    this.stopCountdown();
                }
            }

            // If auto-stopped, reload payloads
            if (!active && this.monitorPoller) {
                this.stopPolling();
                this.loadPayloads();
            }
        }

        runCountdown(initialSecs) {
            this.stopCountdown();
            let secs = initialSecs;
            const tick = () => {
                if (!this.$countdown) return;
                const m = Math.floor(secs / 60);
                const s = String(secs % 60).padStart(2, '0');
                this.$countdown.textContent = '(auto-stops in ' + m + ':' + s + ')';
                if (secs <= 0) {
                    this.stopCountdown();
                    this.loadMonitorStatus();
                }
                secs--;
            };
            tick();
            this.countdownTimer = setInterval(tick, 1000);
        }

        stopCountdown() {
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        }

        // -------------------------------------------------------------------------
        // Captured Payloads
        // -------------------------------------------------------------------------

        async loadPayloads() {
            try {
                const payloads = await this.get('monitor/payloads');
                this.renderPayloads(payloads);
            } catch (_) {}
        }

        renderPayloads(payloads) {
            if (!this.$payloadsPanel || !this.$payloadsList) return;

            if (!payloads || !payloads.length) {
                this.$payloadsPanel.hidden = true;
                return;
            }

            this.$payloadsPanel.hidden = false;
            this.$payloadsList.innerHTML = payloads.map((p, idx) => {
                const id      = 'ewp-rh-pl-' + idx;
                const params  = Object.assign({}, p.query_params || {}, p.body_params || {}, p.url_params || {});
                const json    = JSON.stringify(params, null, 2);
                const status  = p.status >= 400 ? 'ewp-rh-pl-err' : 'ewp-rh-pl-ok';
                const icon    = p.status >= 400 ? '✗' : '✓';

                return '<div class="ewp-rh-payload-row">'
                    + '<div class="ewp-rh-pl-header" data-target="' + id + '">'
                    + '<span class="ewp-rh-pl-icon ' + status + '">' + icon + '</span>'
                    + '<span class="ewp-rh-pl-method">' + e(p.method) + '</span>'
                    + '<span class="ewp-rh-pl-route" title="' + e(p.route) + '">' + e(p.route) + '</span>'
                    + '<span class="ewp-rh-pl-status">' + e(p.status) + '</span>'
                    + '<span class="ewp-rh-pl-time">' + e(p.timestamp) + '</span>'
                    + '<button class="button-link ewp-rh-pl-toggle" type="button" aria-expanded="false" aria-controls="' + id + '">'
                    + 'Show test data ▾</button>'
                    + '</div>'
                    + '<div class="ewp-rh-pl-body" id="' + id + '" hidden>'
                    + '<pre class="ewp-rh-pl-code">' + e(json) + '</pre>'
                    + '<button class="button-secondary ewp-rh-pl-copy" type="button" data-json="' + e(json) + '">'
                    + 'Copy params</button>'
                    + '</div>'
                    + '</div>';
            }).join('');

            // Wire toggle + copy buttons
            this.$payloadsList.querySelectorAll('.ewp-rh-pl-toggle').forEach(btn => {
                btn.addEventListener('click', () => {
                    const body     = document.getElementById(btn.getAttribute('aria-controls'));
                    const expanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', String(!expanded));
                    btn.textContent = expanded ? 'Show test data ▾' : 'Hide test data ▴';
                    if (body) body.hidden = expanded;
                });
            });

            this.$payloadsList.querySelectorAll('.ewp-rh-pl-copy').forEach(btn => {
                btn.addEventListener('click', () => {
                    navigator.clipboard.writeText(btn.dataset.json || '').then(() => {
                        const orig = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(() => { btn.textContent = orig; }, 1500);
                    });
                });
            });
        }

        async clearPayloads() {
            if (!confirm('Clear all captured payloads?')) return;
            try {
                await this.delete_('monitor/payloads');
                this.renderPayloads([]);
                if (this.$monitorStatus) {
                    this.$monitorStatus.textContent = 'Off';
                }
            } catch (_) {}
        }

        // -------------------------------------------------------------------------
        // localStorage
        // -------------------------------------------------------------------------

        restoreSelection() {
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || []; }
            catch (_) { return []; }
        }

        saveSelection(sel) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(sel)); }
            catch (_) {}
        }

        // -------------------------------------------------------------------------
        // HTTP helpers
        // -------------------------------------------------------------------------

        async get(path) {
            const res = await fetch(this.restUrl + path, { headers: { 'X-WP-Nonce': this.nonce } });
            if (!res.ok) throw new Error(res.statusText);
            return res.json();
        }

        async post(path, body) {
            const res = await fetch(this.restUrl + path, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.nonce },
                body:    JSON.stringify(body),
            });
            if (!res.ok) throw new Error(res.statusText);
            return res.json();
        }

        async delete_(path) {
            const res = await fetch(this.restUrl + path, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': this.nonce },
            });
            if (!res.ok) throw new Error(res.statusText);
            return res.json();
        }
    }

    // =========================================================================
    // Utility: HTML-escape
    // =========================================================================

    function e(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
