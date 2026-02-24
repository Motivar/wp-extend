/**
 * EWP Options Portability — Client-side handler for export/import.
 *
 * Intercepts form submissions on the Import/Export page for
 * export_options and import_options action cases. Uses the
 * existing ewp_jsVanillaSerialize() and awm_ajax_call() utilities.
 *
 * Design: No hardcoded field names. Form data is serialized via
 * ewp_jsVanillaSerialize() and the REST endpoints validate all
 * required parameters. File inputs are found by type, not name.
 *
 * Loaded via Dynamic Asset Loader when .ewp-options-portability-wrap is present.
 *
 * @package    EWP\OptionsPortability
 * @author     Motivar
 * @version    1.1.0
 * @since      1.0.0
 */
class EWPOptionsPortability {

	/**
	 * Static reference to the singleton instance.
	 * @type {EWPOptionsPortability|null}
	 */
	static instance = null;

	/**
	 * Localized configuration from PHP.
	 * @type {object}
	 */
	static config = typeof ewpOptionsPortability !== 'undefined' ? ewpOptionsPortability : {};

	/**
	 * Form element reference.
	 * @type {HTMLFormElement|null}
	 */
	form = null;

	/**
	 * Status message container reference.
	 * @type {HTMLElement|null}
	 */
	messageContainer = null;

	/**
	 * Constructor — cache DOM references and bind events.
	 */
	constructor() {
		this.form = document.getElementById('awm-form-ewp-import-export');

		if (!this.form) {
			return;
		}

		this.messageContainer = document.getElementById('options-import-message');
		this.bindEvents();
	}

	/**
	 * Bind the form submit event with capture phase.
	 *
	 * Runs BEFORE the existing import-export.js handler so we can
	 * intercept export_options/import_options cases and prevent
	 * the default handler from processing them.
	 *
	 * @return {void}
	 */
	bindEvents() {
		this.form.addEventListener('submit', (event) => {
			this.handleSubmit(event);
		}, true);
	}

	/**
	 * Handle form submission — serialize form, route by action case.
	 *
	 * Uses ewp_jsVanillaSerialize() to read all form values as an object.
	 * The serialized data is forwarded to the REST endpoint which validates
	 * all required fields — JS makes no assumptions about field names.
	 *
	 * @param {Event} event Submit event.
	 * @return {void}
	 */
	handleSubmit(event) {
		const formData = ewp_jsVanillaSerialize(this.form, true);

		if (!formData || !formData.case) {
			return;
		}

		switch (formData.case) {
			case 'export_options':
				event.preventDefault();
				event.stopImmediatePropagation();
				this.handleExport(formData);
				break;

			case 'import_options':
				event.preventDefault();
				event.stopImmediatePropagation();
				this.handleImport();
				break;
		}
	}

	/**
	 * Handle options export — serialize form and send to REST.
	 *
	 * Sends the full serialized form data to the export endpoint.
	 * The REST endpoint extracts the pages and returns an error
	 * response if the required fields are missing.
	 *
	 * @param {object} formData Serialized form data object.
	 * @return {void}
	 */
	handleExport(formData) {
		const strings = EWPOptionsPortability.config.strings || {};

		this.showMessage(strings.exporting || 'Exporting...', 'info');

		const defaults = {
			data: formData,
			method: 'GET',
			url: EWPOptionsPortability.config.restUrl + 'export/',
			callback: (response) => {
				if (response) {
					this.triggerFileDownload(response);
					this.showMessage(strings.exportSuccess || 'Export completed successfully.', 'success');
					return;
				}
				this.showMessage(strings.error || 'An error occurred.', 'error');
			},
			errorCallback: (errorData) => {
				this.handleRestError(errorData, strings);
			},
			log: true,
		};

		awm_ajax_call(defaults);
	}

	/**
	 * Handle options import — find the visible file input by type.
	 *
	 * Finds file inputs inside visible show-when containers rather than
	 * relying on a specific name attribute. The REST endpoint validates
	 * the JSON payload structure.
	 *
	 * @return {void}
	 */
	handleImport() {
		const strings = EWPOptionsPortability.config.strings || {};
		const fileInput = this.findVisibleFileInput();

		if (!fileInput || fileInput.files.length === 0) {
			this.showMessage(strings.noFile || 'Please upload a JSON file.', 'error');
			return;
		}

		const reader = new FileReader();

		reader.onload = (event) => {
			this.processImportFile(event.target.result);
		};

		reader.readAsText(fileInput.files[0]);
	}

	/**
	 * Process the imported file contents.
	 *
	 * Validates structure client-side for quick feedback, then sends
	 * raw JSON to the REST endpoint which performs full validation.
	 *
	 * @param {string} fileContent Raw JSON string from the file.
	 * @return {void}
	 */
	processImportFile(fileContent) {
		const strings = EWPOptionsPortability.config.strings || {};

		let jsonData;
		try {
			jsonData = JSON.parse(fileContent);
		} catch (e) {
			this.showMessage(strings.invalidFile || 'Invalid JSON file.', 'error');
			return;
		}

		/* Quick client-side format check — REST does full validation */
		if (!jsonData.ewp_options_export) {
			this.showMessage(strings.invalidFormat || 'This file is not an EWP Options export.', 'error');
			return;
		}

		/* URL diff confirmation */
		const currentHome = EWPOptionsPortability.config.homeUrl || '';
		const exportHome = jsonData.home_url || '';
		let skipUrlReplace = false;

		if (exportHome && currentHome && exportHome !== currentHome) {
			const confirmMsg = (strings.urlDiffConfirm || 'The export was created on %s. URLs will be replaced with %s. Continue?')
				.replace('%s', exportHome)
				.replace('%s', currentHome);

			if (!confirm(confirmMsg)) {
				return;
			}
		}

		/* Version mismatch warning */
		if (jsonData.plugin_version) {
			const currentVersion = this.getPluginVersion();
			const exportMajor = parseInt(jsonData.plugin_version.split('.')[0], 10);
			const currentMajor = parseInt(currentVersion.split('.')[0], 10);

			if (exportMajor !== currentMajor) {
				const versionMsg = (strings.versionMismatch || 'Warning: Plugin version mismatch (export: %s, current: %s).')
					.replace('%s', jsonData.plugin_version)
					.replace('%s', currentVersion);

				if (!confirm(versionMsg + ' Continue?')) {
					return;
				}
			}
		}

		this.showMessage(strings.importing || 'Importing...', 'info');

		/* Send to REST — endpoint handles all validation */
		const defaults = {
			data: {
				data: JSON.stringify(jsonData),
				dry_run: false,
				skip_url_replace: skipUrlReplace,
			},
			method: 'POST',
			url: EWPOptionsPortability.config.restUrl + 'import/',
			callback: (response) => {
				if (response) {
					this.displayImportResults(response);
					return;
				}
				this.showMessage(strings.error || 'An error occurred.', 'error');
			},
			errorCallback: (errorData) => {
				this.handleRestError(errorData, strings);
			},
			log: true,
		};

		awm_ajax_call(defaults);
	}

	/**
	 * Display import results in the message container.
	 *
	 * @param {object} result Import summary from REST API.
	 * @return {void}
	 */
	displayImportResults(result) {
		const strings = EWPOptionsPortability.config.strings || {};
		let html = '';

		/* Status badge */
		const statusClass = result.dry_run ? 'ewp-op-info' : 'ewp-op-success';
		const statusMsg = result.dry_run
			? (strings.importDryRun || 'Dry-run completed. No changes were made.')
			: (strings.importSuccess || 'Import completed successfully.');

		html += '<div class="ewp-op-message ' + statusClass + '">' + statusMsg + '</div>';

		/* Stats */
		html += '<div class="ewp-op-stats">';
		html += '<p>' + (strings.pagesImported || 'Pages imported: %d').replace('%d', result.pages_imported ? result.pages_imported.length : 0) + '</p>';
		html += '<p>' + (strings.pagesSkipped || 'Pages skipped: %d').replace('%d', result.pages_skipped ? Object.keys(result.pages_skipped).length : 0) + '</p>';
		html += '<p>' + (strings.fieldsImported || 'Fields imported: %d').replace('%d', result.fields_imported || 0) + '</p>';

		if (result.url_replace_applied) {
			html += '<p>' + (strings.urlsReplaced || 'URL replacements applied: %d pairs').replace('%d', result.url_replacements_count || 0) + '</p>';
		}
		html += '</div>';

		/* Warnings */
		if (result.warnings && result.warnings.length > 0) {
			html += '<div class="ewp-op-warnings">';
			result.warnings.forEach((warning) => {
				html += '<div class="ewp-op-message ewp-op-warning">' + this.escapeHtml(warning) + '</div>';
			});
			html += '</div>';
		}

		/* Skipped pages detail */
		if (result.pages_skipped && Object.keys(result.pages_skipped).length > 0) {
			html += '<div class="ewp-op-skipped">';
			html += '<strong>Skipped pages:</strong><ul>';
			Object.entries(result.pages_skipped).forEach(([key, reason]) => {
				html += '<li><code>' + this.escapeHtml(key) + '</code>: ' + this.escapeHtml(reason) + '</li>';
			});
			html += '</ul></div>';
		}

		this.setMessageHtml(html);
	}

	/**
	 * Handle REST API error responses (4xx).
	 *
	 * Parses the error response body to extract the message from
	 * WP_Error JSON and displays it in the message container.
	 *
	 * @param {object} errorData Error data from awm_ajax_call { status, message, options }.
	 * @param {object} strings   Localized string map.
	 * @return {void}
	 */
	handleRestError(errorData, strings) {
		let msg = strings.error || 'An error occurred.';

		try {
			const parsed = JSON.parse(errorData.message);
			if (parsed && parsed.message) {
				msg = parsed.message;
			}
		} catch (e) {
			/* Not JSON — use raw message if available */
			if (errorData.message) {
				msg = errorData.message;
			}
		}

		this.showMessage(msg, 'error');
	}

	/* =========================================================
	 * Section: Utility Methods
	 * ========================================================= */

	/**
	 * Find the currently visible file input in the form.
	 *
	 * Searches for input[type="file"] elements that are inside
	 * a visible (not display:none) parent container. This avoids
	 * relying on a specific field name.
	 *
	 * @return {HTMLInputElement|null}
	 */
	findVisibleFileInput() {
		const fileInputs = this.form.querySelectorAll('input[type="file"]');

		for (let i = 0; i < fileInputs.length; i++) {
			const input = fileInputs[i];

			/* show-when hides fields by adding awm_no_show to the wrapper
			   and setting disabled on the input — skip disabled inputs */
			if (input.disabled) {
				continue;
			}

			return input;
		}

		return null;
	}

	/**
	 * Trigger a JSON file download from response data.
	 *
	 * @param {object|string} data Response data to save as file.
	 * @return {void}
	 */
	triggerFileDownload(data) {
		const jsonStr = typeof data === 'object' ? JSON.stringify(data, null, 2) : data;
		const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
		const fileName = 'ewp_options_export_' + timestamp + '.json';
		const blob = new Blob([jsonStr], { type: 'application/json' });
		const url = window.URL.createObjectURL(blob);

		const a = document.createElement('a');
		a.style.display = 'none';
		a.href = url;
		a.download = fileName;

		document.body.appendChild(a);
		a.click();
		window.URL.revokeObjectURL(url);
		a.remove();
	}

	/**
	 * Show a status message in the message container.
	 *
	 * @param {string} message Message text.
	 * @param {string} type    Message type: 'success', 'error', 'warning', 'info'.
	 * @return {void}
	 */
	showMessage(message, type) {
		const cssClass = 'ewp-op-' + type;
		this.setMessageHtml('<div class="ewp-op-message ' + cssClass + '">' + this.escapeHtml(message) + '</div>');
	}

	/**
	 * Set raw HTML in the message container.
	 *
	 * @param {string} html HTML content.
	 * @return {void}
	 */
	setMessageHtml(html) {
		if (!this.messageContainer) {
			return;
		}
		this.messageContainer.innerHTML = html;
	}

	/**
	 * Escape HTML entities for safe display.
	 *
	 * @param {string} str Input string.
	 * @return {string} Escaped string.
	 */
	escapeHtml(str) {
		const div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/**
	 * Get plugin version from localized config.
	 *
	 * @return {string} Plugin version or '0.0.0'.
	 */
	getPluginVersion() {
		return EWPOptionsPortability.config.pluginVersion || '0.0.0';
	}

	/**
	 * Create or return the singleton instance.
	 *
	 * @return {EWPOptionsPortability}
	 */
	static getInstance() {
		if (!EWPOptionsPortability.instance) {
			EWPOptionsPortability.instance = new EWPOptionsPortability();
		}
		return EWPOptionsPortability.instance;
	}
}

/* Initialize — handle both early and late script loading */
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', function () {
		EWPOptionsPortability.getInstance();
	});
} else {
	EWPOptionsPortability.getInstance();
}
