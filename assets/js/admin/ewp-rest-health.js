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
            this.$plugins        = document.getElementById('ewp-rh-plugin-select');
            this.$methodCbs      = Array.from(wrap.querySelectorAll('.ewp-rh-method-cb'));
            this.capturedPayloads = []; // kept in memory to pre-fill inline textareas
            this.swaggerObserver  = null;

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
                    if (this.$search && this.$search.value.trim()) {
                        this.filterSwaggerOps(this.$search.value.trim());
                    }
                    this.setupSwaggerInjection();
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
        // Swagger inline test injection
        // -------------------------------------------------------------------------

        setupSwaggerInjection() {
            if (this.swaggerObserver) this.swaggerObserver.disconnect();

            const panel = document.querySelector('#ewp-rh-swagger-panel');
            if (!panel) return;

            // Initial pass — inject into already-open operations
            panel.querySelectorAll('.opblock-body').forEach(b => this.injectTestArea(b));

            // Watch for operations being expanded by the user
            this.swaggerObserver = new MutationObserver(mutations => {
                mutations.forEach(m => {
                    m.addedNodes.forEach(node => {
                        if (!(node instanceof HTMLElement)) return;
                        if (node.classList.contains('opblock-body')) {
                            this.injectTestArea(node);
                        }
                        node.querySelectorAll?.('.opblock-body').forEach(b => this.injectTestArea(b));
                    });
                });
            });

            this.swaggerObserver.observe(panel, { childList: true, subtree: true });
        }

        injectTestArea(body) {
            if (!body || body.querySelector('.ewp-rh-inline-test')) return;

            const opblock = body.closest('.opblock');
            if (!opblock) return;

            const rawRoute = (opblock.querySelector('.opblock-summary-path')?.textContent || '').trim();
            const method   = (opblock.querySelector('.opblock-summary-method')?.textContent || '').trim().toUpperCase();
            if (!rawRoute || !method) return;

            // Pre-fill with captured payload if available
            const captured = this.capturedPayloads.find(p => p.route === rawRoute && p.method === method);
            const params   = captured
                ? Object.assign({}, captured.url_params || {}, captured.query_params || {}, captured.body_params || {})
                : {};
            ['_wpnonce', 'context'].forEach(k => delete params[k]);
            const prefill = Object.keys(params).length ? JSON.stringify(params, null, 2) : '{}';

            const section = document.createElement('div');
            section.className = 'ewp-rh-inline-test';
            section.innerHTML =
                '<div class="ewp-rh-inline-header">Quick Test'
                + (captured ? ' <span class="ewp-rh-inline-badge">● captured params</span>' : '')
                + '</div>'
                + '<textarea class="ewp-rh-pl-textarea ewp-rh-inline-ta" rows="4">'
                + e(prefill) + '</textarea>'
                + '<div class="ewp-rh-pl-btn-row">'
                + '<button class="button button-primary ewp-rh-inline-exec" type="button">▶ Execute</button>'
                + '<button class="button-secondary ewp-rh-inline-fmt" type="button" title="Format JSON">{ }</button>'
                + '</div>'
                + '<div class="ewp-rh-pl-result" hidden></div>';

            body.appendChild(section);

            const ta      = section.querySelector('.ewp-rh-inline-ta');
            const execBtn = section.querySelector('.ewp-rh-inline-exec');
            const fmtBtn  = section.querySelector('.ewp-rh-inline-fmt');
            const result  = section.querySelector('.ewp-rh-pl-result');

            fmtBtn.addEventListener('click', () => {
                try { ta.value = JSON.stringify(JSON.parse(ta.value), null, 2); } catch (_) {}
            });

            execBtn.addEventListener('click', () => {
                let p = {};
                try { p = JSON.parse(ta.value || '{}'); } catch (_) {
                    result.hidden    = false;
                    result.className = 'ewp-rh-pl-result ewp-rh-pl-result-err';
                    result.textContent = 'Invalid JSON in textarea';
                    return;
                }
                this.executeInlineTest(rawRoute, method, p, result, execBtn);
            });
        }

        async executeInlineTest(route, method, params, resultEl, btn) {
            const orig = btn.textContent;
            btn.disabled     = true;
            btn.textContent  = '…';
            resultEl.hidden  = false;
            resultEl.className = 'ewp-rh-pl-result ewp-rh-pl-result-loading';
            resultEl.textContent = 'Executing…';

            try {
                const data = await this.post('test', { route, method, params });
                const ok   = data.status >= 200 && data.status < 300;
                resultEl.className = 'ewp-rh-pl-result ' + (ok ? 'ewp-rh-pl-result-ok' : 'ewp-rh-pl-result-err');
                resultEl.innerHTML =
                    '<span class="ewp-rh-res-status">' + e(data.status) + ' · ' + e(data.duration_ms) + 'ms</span>'
                    + '<button class="button-link ewp-rh-res-copy" type="button" style="float:right" '
                    +   'data-json="' + e(JSON.stringify(data.response, null, 2)) + '">Copy</button>'
                    + '<pre class="ewp-rh-pl-code" style="margin-top:6px;max-height:200px">'
                    + e(JSON.stringify(data.response, null, 2)) + '</pre>';
                resultEl.querySelector('.ewp-rh-res-copy')
                    ?.addEventListener('click', ev => this.copyToClipboard(ev.currentTarget));
            } catch (_) {
                resultEl.className  = 'ewp-rh-pl-result ewp-rh-pl-result-err';
                resultEl.textContent = 'Request failed';
            } finally {
                btn.disabled    = false;
                btn.textContent = orig;
            }
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
                this.capturedPayloads = payloads || [];
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
                const id       = 'ewp-rh-pl-' + idx;
                const idResp   = 'ewp-rh-resp-' + idx;
                const idResult = 'ewp-rh-res-' + idx;
                const params   = Object.assign({}, p.url_params || {}, p.query_params || {}, p.body_params || {});
                ['_wpnonce', 'context'].forEach(k => delete params[k]);
                const paramsJson = JSON.stringify(params, null, 2);
                const respJson   = JSON.stringify(p.response ?? null, null, 2);
                const hasResp    = p.response !== null && p.response !== undefined;
                const errCls     = p.status >= 400 ? 'ewp-rh-pl-err' : 'ewp-rh-pl-ok';
                const icon       = p.status >= 400 ? '✗' : '✓';
                const emptyParams = Object.keys(params).length === 0;

                return '<div class="ewp-rh-payload-row" data-idx="' + idx + '">'
                    // Header
                    + '<div class="ewp-rh-pl-header">'
                    + '<span class="ewp-rh-pl-icon ' + errCls + '">' + icon + '</span>'
                    + '<span class="ewp-rh-pl-method">' + e(p.method) + '</span>'
                    + '<span class="ewp-rh-pl-route" title="' + e(p.route) + '">' + e(p.route) + '</span>'
                    + '<span class="ewp-rh-pl-status">' + e(p.status) + '</span>'
                    + '<span class="ewp-rh-pl-time">' + e(p.timestamp) + '</span>'
                    + '<span class="ewp-rh-pl-actions">'
                    + '<button class="button-link ewp-rh-pl-toggle" type="button" '
                    +   'aria-expanded="false" aria-controls="' + id + '">Show test data ▾</button>'
                    + '</span>'
                    + '</div>'

                    // Expandable body
                    + '<div class="ewp-rh-pl-body" id="' + id + '" hidden>'

                    // ── Editable test params textarea
                    + '<p class="ewp-rh-pl-section-label">Test parameters <small>(edit freely)</small></p>'
                    + '<textarea class="ewp-rh-pl-textarea" rows="' + (emptyParams ? 3 : Math.min(12, paramsJson.split('\n').length + 1)) + '">'
                    + (emptyParams ? '{}' : e(paramsJson))
                    + '</textarea>'
                    + '<div class="ewp-rh-pl-btn-row">'
                    + '<button class="button ewp-rh-pl-use-swagger" type="button" '
                    +   'data-route="' + e(p.route) + '" data-method="' + e(p.method) + '">⬆ Try in Swagger</button>'
                    + '<button class="button-secondary ewp-rh-pl-copy" type="button" '
                    +   'data-json="' + e(paramsJson) + '">Copy</button>'
                    + '</div>'

                    // Captured response (collapsible)
                    + (hasResp
                        ? '<p class="ewp-rh-pl-section-label">Captured response '
                          + '<button class="button-link ewp-rh-resp-toggle" aria-expanded="false" aria-controls="' + idResp + '">Show ▾</button></p>'
                          + '<div id="' + idResp + '" hidden>'
                          + '<pre class="ewp-rh-pl-code ewp-rh-pl-resp">' + e(respJson) + '</pre>'
                          + '<button class="button-secondary ewp-rh-pl-copy" type="button" data-json="' + e(respJson) + '">Copy response</button>'
                          + '</div>'
                        : '')
                    + '</div></div>';
            }).join('');

            // Wire events
            this.$payloadsList.querySelectorAll('.ewp-rh-pl-toggle').forEach(btn =>
                btn.addEventListener('click', () => this.toggleBlock(btn))
            );
            this.$payloadsList.querySelectorAll('.ewp-rh-resp-toggle').forEach(btn =>
                btn.addEventListener('click', () => this.toggleBlock(btn))
            );
            this.$payloadsList.querySelectorAll('.ewp-rh-pl-copy').forEach(btn =>
                btn.addEventListener('click', () => this.copyToClipboard(btn))
            );
            this.$payloadsList.querySelectorAll('.ewp-rh-pl-use-swagger').forEach(btn => {
                btn.addEventListener('click', () => {
                    const row = btn.closest('.ewp-rh-payload-row');
                    const ta  = row?.querySelector('.ewp-rh-pl-textarea');
                    let params = {};
                    try { params = JSON.parse(ta?.value || '{}'); } catch (_) {}
                    this.useInSwagger(btn.dataset.route, btn.dataset.method, params, btn);
                });
            });
        }

        // ── Toggle a collapsible block
        toggleBlock(btn) {
            const target   = document.getElementById(btn.getAttribute('aria-controls'));
            const expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!expanded));
            const label = btn.textContent.replace(/\s*[▾▴]\s*$/, '').trim();
            btn.textContent = label + (expanded ? ' ▾' : ' ▴');
            if (target) target.hidden = expanded;
        }

        copyToClipboard(btn) {
            navigator.clipboard.writeText(btn.dataset.json || '').then(() => {
                const orig = btn.textContent;
                btn.textContent = '✓ Copied!';
                setTimeout(() => { btn.textContent = orig; }, 1500);
            });
        }

        // ── Try in Swagger:
        //   1. Find + scroll to the operation
        //   2. Expand it via Swagger UI's own layoutActions (most reliable) or summary-control click
        //   3. Once expanded, our MutationObserver has already injected the Quick Test section
        //   4. Pre-fill that textarea + auto-click our own Execute button (plain button, not React)
        useInSwagger(route, method, params, triggerBtn) {
            const panel = document.querySelector('#ewp-rh-swagger-panel');
            if (!panel) return;

            // 1. Find the operation block by method + path text
            let block = null;
            const norm = route.replace(/\(\?P<[^>]+>[^)]+\)/g, '{$1}');
            panel.querySelectorAll('.opblock').forEach(op => {
                if (block) return;
                const path = (op.querySelector('.opblock-summary-path')?.textContent || '').trim();
                const meth = (op.querySelector('.opblock-summary-method')?.textContent || '').trim().toUpperCase();
                if (meth === method.toUpperCase() && (path === route || path === norm)) block = op;
            });

            if (!block) {
                alert('Endpoint not found in Swagger UI.\nMake sure the plugin is selected and spec is loaded.');
                return;
            }

            const orig = triggerBtn.textContent;
            triggerBtn.disabled = true;

            // 2. Scroll
            block.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // 3. Expand the operation.
            //    Swagger UI 5 uses an inner <button class="opblock-summary-control"> as the
            //    actual expand trigger — NOT the outer .opblock-summary div.
            //    We also try Swagger UI's own layoutActions API as a secondary method.
            const alreadyOpen = block.classList.contains('is-open');
            if (!alreadyOpen) {
                // Primary: click the correct inner control button
                const ctrl = block.querySelector('button.opblock-summary-control')
                          || block.querySelector('.opblock-summary');
                ctrl?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

                // Secondary: use Swagger UI's internal layoutActions if available
                try {
                    const sys = this.swaggerUi?.getSystem?.();
                    if (sys) {
                        const blockId = block.id || ''; // "operations-{tag}-{opId}"
                        const m = blockId.match(/^operations-(.+?)-(.+)$/);
                        if (m) sys.layoutActions.show(['operations', m[1], m[2]], true);
                    }
                } catch (_) {}
            }

            // 4. After expansion, fill our Quick Test textarea and click our Execute button.
            //    We target OUR injected elements (plain DOM, not React) — no event simulation needed.
            const fillAndRun = () => {
                // If Quick Test section not injected yet (race), inject it now
                if (!block.querySelector('.ewp-rh-inline-test')) {
                    const body = block.querySelector('.opblock-body');
                    if (body) this.injectTestArea(body);
                }

                const ta      = block.querySelector('.ewp-rh-inline-ta');
                const execBtn = block.querySelector('.ewp-rh-inline-exec');

                if (ta) {
                    ta.value = JSON.stringify(params, null, 2);
                }

                if (execBtn) {
                    execBtn.click(); // our plain button — always works
                } else {
                    // opblock didn't expand — show a clear message
                    alert('Could not expand the endpoint. Click it manually in Swagger UI first, then try again.');
                }

                triggerBtn.textContent = execBtn ? '✓ Executed' : '⚠ Expand manually';
                setTimeout(() => {
                    triggerBtn.textContent = orig;
                    triggerBtn.disabled    = false;
                }, 2500);
            };

            // Wait for expansion + MutationObserver injection (longer if we triggered expand)
            setTimeout(fillAndRun, alreadyOpen ? 50 : 700);
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
