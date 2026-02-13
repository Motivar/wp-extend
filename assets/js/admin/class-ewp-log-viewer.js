/**
 * EWPLogViewer — Lightweight AJAX-powered log viewer for the EWP Logger.
 *
 * Fetches log entries from the REST API endpoint and renders them
 * in a filterable, paginated table with expandable row details.
 *
 * Filter fields are rendered server-side via awm_show_content and
 * identified by [data-filter] attributes on their input/select elements.
 *
 * Loaded via Dynamic Asset Loader when .ewp-log-viewer-wrap is in the DOM.
 *
 * @class EWPLogViewer
 * @version 1.1.0
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

        /** @type {number} Current page */
        this.currentPage = EWPLogViewer.DEFAULTS.page;

        /** @type {number} Results per page */
        this.perPage = EWPLogViewer.DEFAULTS.perPage;

        /**
         * The page-level wrapper that contains both awm filter fields
         * and the results container. Defaults to the closest
         * .awm-show-content ancestor or falls back to the form.
         *
         * @type {HTMLElement}
         */
        this.pageWrap = container.closest('.awm-show-content')
            || container.closest('form')
            || document;

        /** @type {HTMLFormElement|null} Parent form for serialization and reset */
        this.form = container.closest('form') || null;

        this.initElements();
        this.bindEvents();
        this.updateActionTypeOptions();
        this.applyFilters();
    }

    /**
     * Cache DOM element references.
     *
     * Filter selects/inputs live outside .ewp-log-viewer-wrap,
     * rendered by awm_show_content as siblings. We query the
     * broader pageWrap to locate them via [data-filter].
     *
     * @returns {void}
     */
    initElements() {
        this.tbody = this.container.querySelector('#ewp-log-tbody');
        this.pagination = this.container.querySelector('#ewp-log-pagination');
        this.totalEl = this.container.querySelector('#ewp-log-total');
        this.filterBtn = this.container.querySelector('#ewp-log-filter-apply');
        this.resetBtn = this.container.querySelector('#ewp-log-filter-reset');
        this.perPageSelect = this.container.querySelector('#ewp-log-per-page');
        this.exportBtn = this.container.querySelector('#ewp-log-export-csv');
        this.deleteBtn = this.container.querySelector('#ewp-log-delete-filtered');
        this.ownerSelect = this.pageWrap.querySelector('[name="owner[]"]');
        this.actionTypeSelect = this.pageWrap.querySelector('[name="action_type[]"]');

        /** @type {Array} Last fetched entries for CSV export */
        this.lastEntries = [];
        this.lastTotal = 0;
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

        // Per-page change → reload
        if (this.perPageSelect) {
            this.perPageSelect.addEventListener('change', () => {
                this.perPage = parseInt(this.perPageSelect.value, 10) || EWPLogViewer.DEFAULTS.perPage;
                this.currentPage = 1;
                this.applyFilters();
            });
        }

        // Export CSV
        if (this.exportBtn) {
            this.exportBtn.addEventListener('click', () => {
                this.exportCsv();
            });
        }

        // Delete filtered entries
        if (this.deleteBtn) {
            this.deleteBtn.addEventListener('click', () => {
                this.deleteFiltered();
            });
        }

        // Owner change → show/hide action type options by data-owner
        if (this.ownerSelect) {
            this.ownerSelect.addEventListener('change', () => {
                this.updateActionTypeOptions();
            });
        }

        // Row click → toggle detail expansion
        this.tbody.addEventListener('click', (e) => {
            this.handleRowClick(e);
        });

        // Pagination click
        this.pagination.addEventListener('click', (e) => {
            this.handlePaginationClick(e);
        });
    }

    /**
     * Collect all filter values by serializing the parent form.
     *
     * Sends raw form field names as-is to the REST API.
     * PHP handles all field-name-to-query-arg mapping via a
     * filterable map, keeping JS fully abstract.
     *
     * @returns {Object} Filter key-value pairs keyed by form field name.
     */
    getFilters() {
        if (!this.form) {
            return {};
        }

        const formData = ewp_jsVanillaSerialize(this.form, true);
        const filters = {};

        for (const key in formData) {
            if (!formData.hasOwnProperty(key)) {
                continue;
            }

            const cleanKey = key.replace('[]', '');
            const value = formData[key];

            // Skip empty values
            if (value === '' || value === undefined || value === null) {
                continue;
            }

            // Join arrays (multi-select) with comma
            if (Array.isArray(value)) {
                const nonEmpty = value.filter((v) => v !== '');
                if (nonEmpty.length) {
                    filters[cleanKey] = nonEmpty.join(',');
                }
                continue;
            }

            filters[cleanKey] = value;
        }

        return filters;
    }

    /**
     * Fetch logs from the REST API via awm_ajax_call and render results.
     *
     * @returns {void}
     */
    applyFilters() {
        const filters = this.getFilters();

        this.setLoading(true);

        awm_ajax_call({
            method: 'get',
            url: this.restUrl + '/logs',
            data: Object.assign({}, filters, {
                page: this.currentPage,
                per_page: this.perPage,
            }),
            callback: (json) => {
                this.lastEntries = json.data || [];
                this.lastTotal = json.total || 0;
                this.renderRows(this.lastEntries);
                this.renderPagination(this.lastTotal, json.page || 1, json.per_page || this.perPage);
                this.updateTotal(this.lastTotal);
                this.setLoading(false);
            },
            errorCallback: (errorData) => {
                this.renderError(errorData.message || 'Request failed');
                this.setLoading(false);
            },
        });
    }

    /**
     * Reset all form fields to their server-rendered defaults and reload.
     *
     * Uses the native form.reset() which restores every field to its
     * initial HTML state — including defaults set by awm_show_content.
     * Developer-added fields are reset automatically.
     *
     * @returns {void}
     */
    resetFilters() {
        if (this.form) {
            this.form.reset();
        }

        // Explicitly deselect all multi-select options
        this.pageWrap.querySelectorAll('select[multiple]').forEach((select) => {
            Array.from(select.options).forEach((opt) => {
                opt.selected = false;
            });
            // Trigger change for any UI library listening
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Reset per-page to default
        if (this.perPageSelect) {
            this.perPageSelect.value = String(EWPLogViewer.DEFAULTS.perPage);
            this.perPage = EWPLogViewer.DEFAULTS.perPage;
        }

        this.updateActionTypeOptions();
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
        const ownerLabel = entry.owner_label || entry.owner || '';
        const actionLabel = entry.action_type_label || entry.action_type || '';
        const objectLabel = entry.object_type_label || entry.object_type || '';
        const userLabel = entry.user_display_name || entry.user_id || '-';
        const style = hidden ? ' style="display:none;"' : '';
        const groupAttr = groupId ? ` data-group-member="${this.escapeHtml(groupId)}"` : '';

        return `<tr class="${rowClass}" data-log-id="${entry.log_id || ''}" data-expandable="true"${groupAttr}${style}>
            <td class="ewp-col-date">${this.escapeHtml(entry.created_at || '')}</td>
            <td class="ewp-col-owner">${this.escapeHtml(ownerLabel)}</td>
            <td class="ewp-col-action">${this.escapeHtml(actionLabel)}</td>
            <td class="ewp-col-object">${this.escapeHtml(objectLabel)}</td>
            <td class="ewp-col-level"><span class="ewp-level-badge ewp-level-${entry.level || 'editor'}">${this.escapeHtml(entry.level || '')}</span></td>
            <td class="ewp-col-behaviour"><span class="${statusClass}">${statusLabel}</span></td>
            <td class="ewp-col-user">${this.escapeHtml(String(userLabel))}</td>
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
     * Show/hide action type options based on the selected owner.
     *
     * Each <option> carries a data-owner attribute set server-side.
     * When an owner is selected only matching options are visible;
     * when no owner is selected all options are shown.
     *
     * @returns {void}
     */
    updateActionTypeOptions() {
        if (!this.actionTypeSelect) {
            return;
        }

        // Collect selected owners (supports multi-select)
        let selectedOwners = [];
        if (this.ownerSelect) {
            if (this.ownerSelect.multiple) {
                selectedOwners = Array.from(this.ownerSelect.selectedOptions)
                    .map((o) => o.value)
                    .filter((v) => v !== '');
            } else if (this.ownerSelect.value) {
                selectedOwners = [this.ownerSelect.value];
            }
        }

        const options = this.actionTypeSelect.querySelectorAll('option[data-owner]');

        options.forEach((opt) => {
            const owner = opt.getAttribute('data-owner');
            const visible = !selectedOwners.length || selectedOwners.includes(owner);

            opt.hidden = !visible;
            opt.disabled = !visible;

            // Deselect hidden options
            if (!visible && opt.selected) {
                opt.selected = false;
            }
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
     * Export all filtered entries as a CSV file.
     *
     * Fetches all matching entries (up to 10000) using the current filters,
     * generates a CSV string, and triggers a browser download.
     *
     * @returns {void}
     */
    exportCsv() {
        const filters = this.getFilters();

        this.exportBtn.disabled = true;
        this.exportBtn.textContent = 'Exporting...';

        awm_ajax_call({
            method: 'get',
            url: this.restUrl + '/logs',
            data: Object.assign({}, filters, {
                page: 1,
                per_page: 10000,
            }),
            callback: (json) => {
                const entries = json.data || [];
                this.downloadCsv(entries);
                this.exportBtn.disabled = false;
                this.exportBtn.textContent = 'Export CSV';
            },
            errorCallback: () => {
                alert('Failed to export CSV.');
                this.exportBtn.disabled = false;
                this.exportBtn.textContent = 'Export CSV';
            },
        });
    }

    /**
     * Generate CSV content from entries and trigger browser download.
     *
     * @param {Array} entries - Array of log entry objects.
     * @returns {void}
     */
    downloadCsv(entries) {
        if (!entries.length) {
            alert('No entries to export.');
            return;
        }

        const columns = [
            { key: 'created_at', header: 'Date' },
            { key: 'owner_label', header: 'Owner' },
            { key: 'action_type_label', header: 'Action' },
            { key: 'object_type_label', header: 'Object Type' },
            { key: 'level', header: 'Level' },
            { key: 'behaviour_label', header: 'Status' },
            { key: 'user_display_name', header: 'User' },
            { key: 'message', header: 'Message' },
            { key: 'object_id', header: 'Object ID' },
            { key: 'request_id', header: 'Request ID' },
            { key: 'request_context', header: 'Request Context' },
        ];

        const rows = [columns.map((c) => c.header)];

        entries.forEach((entry) => {
            rows.push(columns.map((c) => {
                const val = entry[c.key] !== undefined && entry[c.key] !== null
                    ? String(entry[c.key])
                    : '';
                // Escape double quotes and wrap in quotes
                return '"' + val.replace(/"/g, '""') + '"';
            }));
        });

        const csv = rows.map((r) => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = 'ewp-logs-' + new Date().toISOString().slice(0, 10) + '.csv';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Delete all log entries matching the current filters.
     *
     * Shows a confirmation dialog with the total count before proceeding.
     * Sends a DELETE request to the REST API.
     *
     * @returns {void}
     */
    deleteFiltered() {
        const total = this.lastTotal;

        if (total === 0) {
            alert('No entries to delete.');
            return;
        }

        const confirmed = confirm(
            'Are you sure you want to delete ' + total + ' log entries matching the current filters?\n\nThis action cannot be undone.'
        );

        if (!confirmed) {
            return;
        }

        const filters = this.getFilters();

        this.deleteBtn.disabled = true;
        this.deleteBtn.textContent = 'Deleting...';

        awm_ajax_call({
            method: 'DELETE',
            url: this.restUrl + '/logs',
            data: filters,
            callback: (json) => {
                this.deleteBtn.disabled = false;
                this.deleteBtn.textContent = 'Delete Filtered';
                alert(json.message || 'Entries deleted.');
                this.currentPage = 1;
                this.applyFilters();
            },
            errorCallback: () => {
                this.deleteBtn.disabled = false;
                this.deleteBtn.textContent = 'Delete Filtered';
                alert('Failed to delete entries.');
            },
        });
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
