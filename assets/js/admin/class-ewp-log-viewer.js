/**
 * EWPLogViewer — Lightweight AJAX-powered log viewer for the EWP Logger.
 *
 * Fetches log entries from the REST API endpoint and renders them
 * in a filterable, paginated table with expandable row details.
 *
 * Loaded via Dynamic Asset Loader when .ewp-log-viewer-wrap is in the DOM.
 *
 * @class EWPLogViewer
 * @version 1.0.0
 * @since 1.0.0
 */
class EWPLogViewer {
    /**
     * Static defaults for the viewer configuration.
     *
     * @type {Object}
     */
    static DEFAULTS = {
        perPage: 50,
        page: 1,
    };

    /**
     * Create a new EWPLogViewer instance.
     *
     * @param {HTMLElement} container - The .ewp-log-viewer-wrap element.
     */
    constructor(container) {
        /** @type {HTMLElement} */
        this.container = container;

        /** @type {string} REST API base URL */
        this.restUrl = container.dataset.restUrl || '';

        /** @type {string} WP REST nonce */
        this.nonce = container.dataset.nonce || '';

        /** @type {string} Default log level filter */
        this.defaultLevel = container.dataset.defaultLevel || 'editor';

        /** @type {number} Current page */
        this.currentPage = EWPLogViewer.DEFAULTS.page;

        /** @type {number} Results per page */
        this.perPage = EWPLogViewer.DEFAULTS.perPage;

        /** @type {Object} Cached action types by owner */
        this.actionTypes = {};

        this.initElements();
        this.bindEvents();
        this.loadOwners();
        this.loadTypes();
        this.applyFilters();
    }

    /**
     * Cache DOM element references.
     *
     * @returns {void}
     */
    initElements() {
        this.tbody = this.container.querySelector('#ewp-log-tbody');
        this.pagination = this.container.querySelector('#ewp-log-pagination');
        this.totalEl = this.container.querySelector('#ewp-log-total');
        this.filterBtn = this.container.querySelector('#ewp-log-filter-apply');
        this.resetBtn = this.container.querySelector('#ewp-log-filter-reset');
        this.ownerSelect = this.container.querySelector('[data-filter="owner"]');
        this.actionTypeSelect = this.container.querySelector('[data-filter="action_type"]');
        this.levelSelect = this.container.querySelector('[data-filter="level"]');
    }

    /**
     * Bind event listeners for filters, pagination, and row expansion.
     *
     * @returns {void}
     */
    bindEvents() {
        this.filterBtn.addEventListener('click', () => {
            this.currentPage = 1;
            this.applyFilters();
        });

        this.resetBtn.addEventListener('click', () => {
            this.resetFilters();
        });

        // Owner change → update action type options
        this.ownerSelect.addEventListener('change', () => {
            this.updateActionTypeOptions();
        });

        // Row click → toggle detail expansion
        this.tbody.addEventListener('click', (e) => {
            this.handleRowClick(e);
        });

        // Pagination click
        this.pagination.addEventListener('click', (e) => {
            this.handlePaginationClick(e);
        });

        // Set default level
        if (this.defaultLevel && this.levelSelect) {
            this.levelSelect.value = this.defaultLevel;
        }
    }

    /**
     * Collect current filter values from the form.
     *
     * @returns {Object} Filter key-value pairs.
     */
    getFilters() {
        const filters = {};
        const filterEls = this.container.querySelectorAll('.ewp-log-filter');

        filterEls.forEach((el) => {
            const key = el.dataset.filter;
            const val = el.value;
            if (key && val !== '') {
                filters[key] = val;
            }
        });

        return filters;
    }

    /**
     * Fetch logs from the REST API with current filters and render results.
     *
     * @returns {void}
     */
    applyFilters() {
        const filters = this.getFilters();
        const params = new URLSearchParams({
            ...filters,
            page: this.currentPage,
            per_page: this.perPage,
        });

        this.setLoading(true);

        fetch(`${this.restUrl}/logs?${params.toString()}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': this.nonce,
                'Content-Type': 'application/json',
            },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                return res.json();
            })
            .then((json) => {
                this.renderRows(json.data || []);
                this.renderPagination(json.total || 0, json.page || 1, json.per_page || this.perPage);
                this.updateTotal(json.total || 0);
            })
            .catch((err) => {
                this.renderError(err.message);
            })
            .finally(() => {
                this.setLoading(false);
            });
    }

    /**
     * Reset all filters to their defaults and reload.
     *
     * @returns {void}
     */
    resetFilters() {
        const filterEls = this.container.querySelectorAll('.ewp-log-filter');
        filterEls.forEach((el) => {
            if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else {
                el.value = '';
            }
        });

        // Restore default level
        if (this.defaultLevel && this.levelSelect) {
            this.levelSelect.value = this.defaultLevel;
        }

        this.currentPage = 1;
        this.applyFilters();
    }

    /**
     * Group entries by request_id into ordered groups.
     *
     * Maintains the original order from the API. Entries without
     * a request_id are treated as standalone (group of 1).
     *
     * @param {Array} entries - Flat array of log entry objects.
     * @returns {Array} Array of { requestId, context, entries[] } groups.
     */
    groupByRequest(entries) {
        const groups = [];
        let currentGroup = null;

        entries.forEach((entry) => {
            const rid = entry.request_id || '';

            // Continue current group if same request_id
            if (currentGroup && currentGroup.requestId === rid && rid !== '') {
                currentGroup.entries.push(entry);
                return;
            }

            // Start a new group
            currentGroup = {
                requestId: rid,
                context: entry.request_context || '',
                entries: [entry],
            };
            groups.push(currentGroup);
        });

        return groups;
    }

    /**
     * Render log entry rows into the table body, grouped by request_id.
     *
     * Groups with more than one entry get a collapsible header row
     * showing the request context, entry count, and timestamp.
     * Single-entry groups are rendered as plain rows.
     *
     * @param {Array} entries - Array of log entry objects.
     * @returns {void}
     */
    renderRows(entries) {
        if (!entries.length) {
            this.tbody.innerHTML = `<tr><td colspan="8" class="ewp-log-empty">No log entries found.</td></tr>`;
            return;
        }

        const groups = this.groupByRequest(entries);
        let html = '';

        groups.forEach((group) => {
            const isMulti = group.entries.length > 1;

            // Multi-entry group: render a collapsible header
            if (isMulti) {
                const firstDate = group.entries[0].created_at || '';
                const ctx = this.escapeHtml(group.context || group.requestId);
                const count = group.entries.length;
                const hasError = group.entries.some((e) => e.behaviour === 0);
                const hasWarning = !hasError && group.entries.some((e) => e.behaviour === 2);
                let headerClass = 'ewp-group-header';
                if (hasError) {
                    headerClass += ' ewp-group-has-error';
                }
                if (hasWarning) {
                    headerClass += ' ewp-group-has-warning';
                }

                html += `<tr class="${headerClass}" data-group-id="${this.escapeHtml(group.requestId)}" data-group-toggle="true">
                    <td class="ewp-col-date">${this.escapeHtml(firstDate)}</td>
                    <td colspan="7" class="ewp-group-context">
                        <span class="ewp-group-arrow">&#9654;</span>
                        <strong>${ctx}</strong>
                        <span class="ewp-group-count">${count} entries</span>
                    </td>
                </tr>`;

                // Render child rows (hidden by default)
                group.entries.forEach((entry) => {
                    html += this.buildEntryRow(entry, group.requestId, true);
                    html += this.buildDetailRow(entry, group.requestId, true);
                });

                return;
            }

            // Single-entry group: render as normal row
            const entry = group.entries[0];
            html += this.buildEntryRow(entry, '', false);
            html += this.buildDetailRow(entry, '', false);
        });

        this.tbody.innerHTML = html;
    }

    /**
     * Build the HTML for a single log entry row.
     *
     * @param {Object}  entry    - Log entry object.
     * @param {string}  groupId  - Parent group request_id (empty for standalone).
     * @param {boolean} hidden   - Whether the row starts hidden (grouped).
     * @returns {string} HTML string.
     */
    buildEntryRow(entry, groupId, hidden) {
        const behaviourInfo = this.getBehaviourInfo(entry.behaviour);
        let rowClass = 'ewp-log-row';
        if (behaviourInfo.key === 'error') {
            rowClass += ' ewp-log-row-error';
        }
        if (behaviourInfo.key === 'warning') {
            rowClass += ' ewp-log-row-warning';
        }
        if (groupId) {
            rowClass += ' ewp-group-child';
        }
        const statusLabel = behaviourInfo.label;
        const statusClass = behaviourInfo.cssClass;
        const message = this.escapeHtml(entry.message || '');
        const actionLabel = entry.action_type_label || entry.action_type || '';
        const style = hidden ? ' style="display:none;"' : '';
        const groupAttr = groupId ? ` data-group-member="${this.escapeHtml(groupId)}"` : '';

        return `<tr class="${rowClass}" data-log-id="${entry.log_id || ''}" data-expandable="true"${groupAttr}${style}>
            <td class="ewp-col-date">${this.escapeHtml(entry.created_at || '')}</td>
            <td class="ewp-col-owner">${this.escapeHtml(entry.owner || '')}</td>
            <td class="ewp-col-action">${this.escapeHtml(actionLabel)}</td>
            <td class="ewp-col-object">${this.escapeHtml(entry.object_type || '')}</td>
            <td class="ewp-col-level"><span class="ewp-level-badge ewp-level-${entry.level || 'editor'}">${this.escapeHtml(entry.level || '')}</span></td>
            <td class="ewp-col-behaviour"><span class="${statusClass}">${statusLabel}</span></td>
            <td class="ewp-col-user">${entry.user_id || '-'}</td>
            <td class="ewp-col-message">${message}</td>
        </tr>`;
    }

    /**
     * Build the HTML for a hidden detail/accordion row.
     *
     * @param {Object}  entry    - Log entry object.
     * @param {string}  groupId  - Parent group request_id.
     * @param {boolean} hidden   - Whether parent group is collapsed.
     * @returns {string} HTML string.
     */
    buildDetailRow(entry, groupId, hidden) {
        const groupAttr = groupId ? ` data-group-member="${this.escapeHtml(groupId)}"` : '';
        const style = hidden ? ' style="display:none;"' : '';

        return `<tr class="ewp-log-detail-row"${groupAttr} style="display:none;"${style ? ' data-group-hidden="true"' : ''}>
            <td colspan="8"><pre class="ewp-log-detail-data">${this.formatData(entry.data)}</pre></td>
        </tr>`;
    }

    /**
     * Handle click on a log row or group header.
     *
     * - Group header click: toggle visibility of all child rows.
     * - Entry row click: toggle the detail/accordion row.
     *
     * @param {Event} e - Click event.
     * @returns {void}
     */
    handleRowClick(e) {
        // Group header toggle
        const groupHeader = e.target.closest('tr[data-group-toggle="true"]');
        if (groupHeader) {
            this.toggleGroup(groupHeader);
            return;
        }

        // Individual entry detail toggle
        const row = e.target.closest('tr[data-expandable="true"]');
        if (!row) {
            return;
        }

        const detailRow = row.nextElementSibling;
        if (!detailRow || !detailRow.classList.contains('ewp-log-detail-row')) {
            return;
        }

        const isVisible = detailRow.style.display !== 'none';
        detailRow.style.display = isVisible ? 'none' : 'table-row';
        row.classList.toggle('ewp-log-row-expanded', !isVisible);
    }

    /**
     * Toggle visibility of all child rows in a request group.
     *
     * @param {HTMLElement} header - The group header <tr>.
     * @returns {void}
     */
    toggleGroup(header) {
        const groupId = header.dataset.groupId;
        if (!groupId) {
            return;
        }

        const isExpanded = header.classList.contains('ewp-group-expanded');
        const members = this.tbody.querySelectorAll(`tr[data-group-member="${groupId}"]`);

        members.forEach((row) => {
            // Detail rows stay hidden unless individually expanded
            if (row.classList.contains('ewp-log-detail-row')) {
                if (isExpanded) {
                    row.style.display = 'none';
                }
                return;
            }

            // Entry rows toggle
            row.style.display = isExpanded ? 'none' : 'table-row';
        });

        header.classList.toggle('ewp-group-expanded', !isExpanded);
    }

    /**
     * Render pagination controls.
     *
     * @param {number} total   - Total number of results.
     * @param {number} page    - Current page.
     * @param {number} perPage - Results per page.
     * @returns {void}
     */
    renderPagination(total, page, perPage) {
        const totalPages = Math.ceil(total / perPage);

        if (totalPages <= 1) {
            this.pagination.innerHTML = '';
            return;
        }

        let html = '<div class="ewp-log-pagination-inner">';

        // Previous button
        if (page > 1) {
            html += `<button type="button" class="button ewp-log-page-btn" data-page="${page - 1}">← Prev</button>`;
        }

        // Page info
        html += `<span class="ewp-log-page-info">Page ${page} of ${totalPages}</span>`;

        // Next button
        if (page < totalPages) {
            html += `<button type="button" class="button ewp-log-page-btn" data-page="${page + 1}">Next →</button>`;
        }

        html += '</div>';
        this.pagination.innerHTML = html;
    }

    /**
     * Handle pagination button clicks.
     *
     * @param {Event} e - Click event.
     * @returns {void}
     */
    handlePaginationClick(e) {
        const btn = e.target.closest('.ewp-log-page-btn');
        if (!btn) {
            return;
        }

        this.currentPage = parseInt(btn.dataset.page, 10) || 1;
        this.applyFilters();
    }

    /**
     * Load available owners from the REST API and populate the owner select.
     *
     * @returns {void}
     */
    loadOwners() {
        fetch(`${this.restUrl}/logs/owners`, {
            headers: { 'X-WP-Nonce': this.nonce },
        })
            .then((res) => res.json())
            .then((json) => {
                const owners = json.data || [];
                owners.forEach((owner) => {
                    const opt = document.createElement('option');
                    opt.value = owner;
                    opt.textContent = owner;
                    this.ownerSelect.appendChild(opt);
                });
            })
            .catch(() => {
                // Silently fail — filter still works without pre-populated options
            });
    }

    /**
     * Load all registered action types and cache them by owner.
     *
     * @returns {void}
     */
    loadTypes() {
        fetch(`${this.restUrl}/logs/types`, {
            headers: { 'X-WP-Nonce': this.nonce },
        })
            .then((res) => res.json())
            .then((json) => {
                const types = json.data || [];
                this.actionTypes = {};

                types.forEach((t) => {
                    if (!this.actionTypes[t.owner]) {
                        this.actionTypes[t.owner] = [];
                    }
                    this.actionTypes[t.owner].push(t);
                });

                this.updateActionTypeOptions();
            })
            .catch(() => {
                // Silently fail
            });
    }

    /**
     * Update the action type select options based on the selected owner.
     *
     * @returns {void}
     */
    updateActionTypeOptions() {
        const selectedOwner = this.ownerSelect.value;

        // Clear existing options except "All"
        while (this.actionTypeSelect.options.length > 1) {
            this.actionTypeSelect.remove(1);
        }

        // Gather types for selected owner (or all owners)
        let typesToShow = [];
        if (selectedOwner && this.actionTypes[selectedOwner]) {
            typesToShow = this.actionTypes[selectedOwner];
        } else {
            // Show all types from all owners
            Object.values(this.actionTypes).forEach((ownerTypes) => {
                typesToShow = typesToShow.concat(ownerTypes);
            });
        }

        // Deduplicate by type_key
        const seen = new Set();
        typesToShow.forEach((t) => {
            if (seen.has(t.type_key)) {
                return;
            }
            seen.add(t.type_key);

            const opt = document.createElement('option');
            opt.value = t.type_key;
            opt.textContent = t.label || t.type_key;
            this.actionTypeSelect.appendChild(opt);
        });
    }

    /**
     * Map a behaviour value to its display label, CSS class, and key.
     *
     * @param {number} behaviour - 0 = error, 1 = success, 2 = warning.
     * @returns {{ key: string, label: string, cssClass: string }}
     */
    getBehaviourInfo(behaviour) {
        const map = {
            0: { key: 'error',   label: '✗ Error',   cssClass: 'ewp-status-error' },
            1: { key: 'success', label: '✓ OK',      cssClass: 'ewp-status-ok' },
            2: { key: 'warning', label: '⚠ Warning', cssClass: 'ewp-status-warning' },
        };

        return map[behaviour] || map[1];
    }

    /**
     * Update the total count display.
     *
     * @param {number} total - Total matching entries.
     * @returns {void}
     */
    updateTotal(total) {
        this.totalEl.textContent = `${total} entries`;
    }

    /**
     * Show or hide the loading state.
     *
     * @param {boolean} loading - Whether loading is active.
     * @returns {void}
     */
    setLoading(loading) {
        this.container.classList.toggle('ewp-log-loading-state', loading);

        if (loading) {
            this.filterBtn.disabled = true;
        } else {
            this.filterBtn.disabled = false;
        }
    }

    /**
     * Render an error message in the table body.
     *
     * @param {string} message - Error message.
     * @returns {void}
     */
    renderError(message) {
        this.tbody.innerHTML = `<tr><td colspan="8" class="ewp-log-error">Error loading logs: ${this.escapeHtml(message)}</td></tr>`;
    }

    /**
     * Format the data payload for display in the detail row.
     *
     * @param {*} data - The data payload (object, string, or null).
     * @returns {string} Formatted string.
     */
    formatData(data) {
        if (!data) {
            return '-';
        }

        if (typeof data === 'string') {
            return this.escapeHtml(data);
        }

        try {
            return this.escapeHtml(JSON.stringify(data, null, 2));
        } catch (e) {
            return this.escapeHtml(String(data));
        }
    }

    /**
     * Escape HTML entities in a string.
     *
     * @param {string} str - Input string.
     * @returns {string} Escaped string.
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
}

// Auto-initialize when DOM is ready and the container exists
document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('.ewp-log-viewer-wrap');
    if (container) {
        new EWPLogViewer(container);
    }
});
