/**
 * AWM Modal Field — JavaScript Handler
 * 
 * Handles opening/closing modal overlays for awm_modal field type,
 * loading modal HTML and fields via REST API, and saving values back to storage.
 * 
 * Modal HTML is rendered server-side via PHP template (templates/admin-view/modal-field.php).
 * Loaded dynamically via EWP Dynamic Asset Loader when .awm-modal-trigger exists.
 * Uses awm_ajax_call for REST interactions and awm_init_inputs for field initialization.
 * 
 * @package ExtendWP
 * @since   1.2.0
 */
class AWMModalField {

	/**
	 * Configuration from localized script data
	 * 
	 * @type {object}
	 */
	static config = typeof awmModalField !== 'undefined' ? awmModalField : {};

	/**
	 * Currently active modal element
	 * 
	 * @type {HTMLElement|null}
	 */
	activeModal = null;

	/**
	 * Escape key handler reference for cleanup
	 * 
	 * @type {Function|null}
	 */
	escHandler = null;

	/**
	 * Current trigger element reference
	 * 
	 * @type {HTMLElement|null}
	 */
	currentTrigger = null;

	/**
	 * Constructor — initializes event bindings
	 */
	constructor() {
		this.strings = AWMModalField.config.strings || {};
		this.restUrl = AWMModalField.config.restUrl || '/wp-json/extend-wp/v1/';
		this.init();
	}

	/**
	 * Initialize event listeners on all modal trigger buttons
	 * 
	 * @return {void}
	 */
	init() {
		document.querySelectorAll('.awm-modal-trigger').forEach(btn => {
			btn.addEventListener('click', (e) => this.openModal(e.currentTarget));
		});
	}

	/**
	 * Open modal overlay by loading HTML from REST API
	 * 
	 * Modal HTML is rendered server-side via PHP template.
	 * Field definitions are looked up server-side using meta_key and optional option_page.
	 * Uses awm_ajax_call for REST request.
	 * 
	 * @param {HTMLElement} trigger The trigger button element
	 * @return {void}
	 */
	openModal(trigger) {
		const modalId = trigger.dataset.modalId || '';
		const metaKey = trigger.dataset.metaKey || '';
		const view = trigger.dataset.view || 'post';
		const objectId = trigger.dataset.objectId || '0';
		const modalTitle = trigger.dataset.modalTitle || this.strings.edit || 'Edit';
		const optionPage = trigger.dataset.optionPage || '';

		this.currentTrigger = trigger;
		document.body.classList.add('ewp-ai-modal-open');

		const url = new URL(this.restUrl + 'modal-fields/', window.location.origin);
		url.searchParams.set('modal_id', modalId);
		url.searchParams.set('meta_key', metaKey);
		url.searchParams.set('view', view);
		url.searchParams.set('object_id', objectId);
		url.searchParams.set('modal_title', modalTitle);
		if (optionPage) {
			url.searchParams.set('option_page', optionPage);
		}

		awm_ajax_call({
			method: 'GET',
			url: url.toString(),
			callback: 'awm_modal_field_response',
			errorCallback: 'awm_modal_field_error',
		});
	}

	/**
	 * Handle successful modal HTML response
	 * 
	 * @param {object} data Response data with modal_html
	 * @return {void}
	 */
	static handleModalResponse(data) {
		const instance = AWMModalField.instance;
		const s = instance.strings;

		if (!data.modal_html) {
			instance.handleError(s.error || 'Invalid modal response');
			return;
		}

		const container = document.createElement('div');
		container.innerHTML = data.modal_html;
		const overlay = container.firstElementChild;

		document.body.appendChild(overlay);
		instance.activeModal = overlay;

		instance.bindModalEvents(overlay, instance.currentTrigger);
		instance.initializeNestedFields(overlay.querySelector('.awm-modal-body'));

		requestAnimationFrame(() => overlay.classList.add('ewp-ai-modal--visible'));
		awm_init_inputs();
	}

	/**
	 * Handle modal fetch error
	 * 
	 * @param {object} error Error object
	 * @return {void}
	 */
	static handleModalError(error) {
		const instance = AWMModalField.instance;
		instance.handleError(error.message || instance.strings.error || 'Failed to load modal');
	}

	/**
	 * Handle error and cleanup
	 * 
	 * @param {string} message Error message
	 * @return {void}
	 */
	handleError(message) {
		document.body.classList.remove('ewp-ai-modal-open');
		alert(message);
	}

	/**
	 * Bind modal event handlers
	 * 
	 * @param {HTMLElement} overlay Modal overlay element
	 * @param {HTMLElement} trigger Original trigger button
	 * @return {void}
	 */
	bindModalEvents(overlay, trigger) {
		overlay.querySelectorAll('.ewp-ai-modal-close, .awm-modal-cancel').forEach(btn => {
			btn.addEventListener('click', () => this.closeModal());
		});

		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) {
				this.closeModal();
			}
		});

		const saveBtn = overlay.querySelector('.awm-modal-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', () => this.saveModal(trigger));
		}

		this.escHandler = (e) => {
			if (e.key === 'Escape') {
				this.closeModal();
			}
		};
		document.addEventListener('keydown', this.escHandler);
	}

	/**
	 * Initialize any nested field behaviors (select2, datepickers, maps, etc.)
	 * 
	 * Uses awm_init_inputs() to initialize all EWP field types.
	 * 
	 * @param {HTMLElement} container Container element with fields
	 * @return {void}
	 */
	initializeNestedFields(container) {
		if (!container) {
			return;
		}


		// Dispatch custom event for additional initialization
		const event = new CustomEvent('awm_modal_fields_loaded', {
			detail: { container },
			bubbles: true,
		});
		container.dispatchEvent(event);
	}

	/**
	 * Save modal field values directly to DB via REST API
	 * 
	 * Uses awm_ajax_call for REST request.
	 * Values are saved immediately — no hidden input needed.
	 * 
	 * @param {HTMLElement} trigger Original trigger button
	 * @return {void}
	 */
	saveModal(trigger) {
		const overlay = this.activeModal;
		if (!overlay) {
			return;
		}

		const metaKey = trigger.dataset.metaKey || '';
		const view = trigger.dataset.view || 'post';
		const objectId = trigger.dataset.objectId || '0';

		const saveBtn = overlay.querySelector('.awm-modal-save');
		const s = this.strings;

		if (saveBtn) {
			saveBtn.disabled = true;
			saveBtn.textContent = s.saving || 'Saving…';
		}

		const values = this.serializeModalForm(overlay, metaKey);

		awm_ajax_call({
			method: 'POST',
			url: this.restUrl + 'modal-save/',
			data: {
				meta_key: metaKey,
				view: view,
				object_id: objectId,
				values: values,
			},
			callback: 'awm_modal_field_save_response',
			errorCallback: 'awm_modal_field_save_error',
		});
	}

	/**
	 * Handle successful save response
	 * 
	 * @param {object} data Response data
	 * @return {void}
	 */
	static handleSaveResponse(data) {
		const instance = AWMModalField.instance;
		const overlay = instance.activeModal;

		// Dispatch custom event for post-save actions
		if (overlay) {
			const event = new CustomEvent('awm_modal_fields_saved', {
				detail: {
					data,
					overlay,
					trigger: instance.currentTrigger
				},
				bubbles: true,
			});
			overlay.dispatchEvent(event);
		}

		instance.closeModal();
	}

	/**
	 * Handle save error
	 * 
	 * @param {object} error Error object
	 * @return {void}
	 */
	static handleSaveError(error) {
		const instance = AWMModalField.instance;
		const overlay = instance.activeModal;
		const saveBtn = overlay ? overlay.querySelector('.awm-modal-save') : null;
		const s = instance.strings;

		if (saveBtn) {
			saveBtn.disabled = false;
			saveBtn.textContent = s.save || 'Save';
		}

		alert(error.message || s.error || 'Save failed');
	}

	/**
	 * Serialize modal form fields into object
	 * 
	 * @param {HTMLElement} overlay Modal overlay element
	 * @param {string}      metaKey Meta key prefix
	 * @return {object}     Serialized field values
	 */
	serializeModalForm(overlay, metaKey) {
		const data = {};
		const inputs = overlay.querySelectorAll(
			'.awm-modal-body input:not([type="button"]):not([type="submit"]):not([type="reset"]), ' +
			'.awm-modal-body textarea, ' +
			'.awm-modal-body select'
		);

		inputs.forEach(input => {
			if (input.closest('[data-counter="template"]')) {
				return;
			}

			if (!input.name || input.name === 'awm_custom_meta[]') {
				return;
			}

			let value = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;

			if (input.type === 'select-multiple') {
				value = Array.from(input.selectedOptions).map(opt => opt.value);
			}

			this.setNestedValue(data, input.name, value);
		});

		return data[metaKey] || data;
	}

	/**
	 * Set nested value in object by parsing bracket notation
	 * 
	 * @param {object} obj   Target object
	 * @param {string} path  Path with bracket notation (e.g., "meta[key][subkey]")
	 * @param {mixed}  value Value to set
	 * @return {void}
	 */
	setNestedValue(obj, path, value) {
		const keys = path.replace(/\[(\w+)\]/g, '.$1').split('.');
		let cur = obj;

		for (let i = 0; i < keys.length - 1; i++) {
			const k = keys[i];
			if (!cur[k] || typeof cur[k] !== 'object') {
				cur[k] = {};
			}
			cur = cur[k];
		}

		cur[keys[keys.length - 1]] = value;
	}

	/**
	 * Close and remove modal overlay
	 * 
	 * @return {void}
	 */
	closeModal() {
		const overlay = this.activeModal;
		if (!overlay) {
			return;
		}

		overlay.classList.remove('ewp-ai-modal--visible');

		setTimeout(() => {
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
			document.body.classList.remove('ewp-ai-modal-open');
			this.activeModal = null;
		}, 200);

		if (this.escHandler) {
			document.removeEventListener('keydown', this.escHandler);
			this.escHandler = null;
		}
	}

	/**
	 * Escape HTML entities for safe output
	 * 
	 * @param {string} str String to escape
	 * @return {string}    Escaped string
	 */
	escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}
}

// Create singleton instance and store reference for callbacks
AWMModalField.instance = new AWMModalField();

/**
 * Global callback for modal HTML response
 * 
 * @param {object} data Response data with modal_html
 * @return {void}
 */
function awm_modal_field_response(data) {
	AWMModalField.handleModalResponse(data);
}

/**
 * Global callback for modal HTML error
 * 
 * @param {object} error Error object
 * @return {void}
 */
function awm_modal_field_error(error) {
	AWMModalField.handleModalError(error);
}

/**
 * Global callback for save response
 * 
 * @param {object} data Response data
 * @return {void}
 */
function awm_modal_field_save_response(data) {
	AWMModalField.handleSaveResponse(data);
}

/**
 * Global callback for save error
 * 
 * @param {object} error Error object
 * @return {void}
 */
function awm_modal_field_save_error(error) {
	AWMModalField.handleSaveError(error);
}
