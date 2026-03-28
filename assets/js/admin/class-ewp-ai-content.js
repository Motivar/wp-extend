/**
 * EWP AI Content Generator — Modal UI + Editor Integration.
 *
 * Features:
 *  - Generator modal: multi-select tasks, prompt preview, Accept All / Retry.
 *  - Business onboarding modal (on-demand from settings page).
 *  - Gutenberg: content inserted as native blocks via wp.blocks.rawHandler.
 *  - Classic editor: TinyMCE / textarea fallback.
 *
 * Loaded via Dynamic Asset Loader (.awm-modal-trigger[data-modal-id*="ai_generator"])
 * Hooks into awm_modal_fields_loaded event to initialize AI-specific functionality.
 *
 * @package EWP\AIContent
 * @since   1.0.3
 */
class EWPAiContent {

	/** @type {object} */
	static config = typeof ewpAiContent !== 'undefined' ? ewpAiContent : {};

	/** @type {object} { title, excerpt, full_content } */
	results = {};

	/** @type {object} last generation params for Retry */
	lastParams = {};

	/** @type {HTMLElement|null} */
	currentModal = null;

	constructor() {
		this.s = EWPAiContent.config.strings || {};
		this.init();
	}

	init() {
		// Listen for modal loaded event from awm_modal_field.js
		document.addEventListener('awm_modal_fields_loaded', (e) => {
			const container = e.detail?.container;
			const overlay = container?.closest('.awm-modal-overlay');
			if (!overlay) { return; }

			const modalId = overlay.id;
			if (modalId && modalId.includes('ai_generator')) {
				this.initGeneratorModal(overlay);
			}
		});

		// Settings page: auto-generate business context after modal saves
		if (EWPAiContent.config.isSettingsPage) {
			this.initSettingsPage();
		}
	}

	// ── Settings page ────────────────────────────────────────────────────────

	initSettingsPage() {
		document.addEventListener('awm_modal_fields_saved', e => {
			const overlay = e.detail?.overlay;
			if (!overlay || !overlay.id.includes('business_data')) { return; }
			this.generateBusinessContext();
		});
	}

	// ── Generator Modal ─────────────────────────────────────────────────────

	initGeneratorModal(overlay) {
		this.results = {};
		this.lastParams = {};
		this.currentModal = overlay;

		// Get post ID from trigger button data
		const trigger = document.querySelector('.awm-modal-trigger[data-modal-id*="ai_generator"]');
		const postId = trigger ? parseInt(trigger.dataset.objectId, 10) : 0;
		const frontUrl = trigger?.dataset.frontendUrl || '';
		const screenshot = trigger?.dataset.screenshotEnabled === '1';

		// Sync provider/model on provider change
		const providerSel = overlay.querySelector('[name*="[provider]"]');
		if (providerSel) {
			providerSel.addEventListener('change', () => this.syncModels(overlay));
		}

		// Prompt preview toggle
		const promptToggle = overlay.querySelector('.ewp-ai-prompt-toggle');
		if (promptToggle) {
			promptToggle.addEventListener('click', () => this.togglePromptPreview(overlay, postId));
		}

		// Generate button
		const generateBtn = overlay.querySelector('.ewp-ai-generate-btn');
		if (generateBtn) {
			generateBtn.addEventListener('click', () => this.runGenerate(overlay, postId, frontUrl, screenshot));
		}

		// Accept All button
		const acceptBtn = overlay.querySelector('.ewp-ai-accept-all');
		if (acceptBtn) {
			acceptBtn.addEventListener('click', () => {
				this.applyToEditor(this.results);
				if (typeof AWMModalField !== 'undefined' && AWMModalField.instance) {
					AWMModalField.instance.closeModal();
				}
			});
		}

		// Retry button
		const retryBtn = overlay.querySelector('.ewp-ai-retry');
		if (retryBtn) {
			retryBtn.addEventListener('click', () => this.runGenerate(overlay, postId, frontUrl, screenshot));
		}

		// Initial model sync
		this.syncModels(overlay);
	}

	// ── Providers ────────────────────────────────────────────────────────────

	syncModels(modal) {
		if (!modal) { return; }
		const providerSel = modal.querySelector('[name*="[provider]"]');
		const modelSel = modal.querySelector('[name*="[model]"]');
		if (!providerSel || !modelSel) { return; }

		const selected = providerSel.value;
		let first = null;

		Array.from(modelSel.options).forEach(opt => {
			const show = opt.dataset.provider === selected;
			opt.hidden = !show;
			opt.disabled = !show;
			if (show && !first) { first = opt; }
		});

		if (modelSel.selectedOptions[0] && modelSel.selectedOptions[0].hidden && first) {
			modelSel.value = first.value;
		}
	}

	// ── Prompt preview ───────────────────────────────────────────────────────

	async togglePromptPreview(modal, postId) {
		if (!modal) { return; }
		const wrapper = modal.querySelector('.ewp-ai-prompt-preview');
		const btn = modal.querySelector('.ewp-ai-prompt-toggle');
		if (!wrapper) { return; }

		const isOpen = wrapper.style.display !== 'none';

		if (isOpen) {
			wrapper.style.display = 'none';
			if (btn) { btn.textContent = '▶ ' + (this.s.preview_prompt || 'Preview Prompt').replace(/^[▶▼]\s*/, ''); }
			return;
		}

		wrapper.style.display = 'block';
		if (btn) { btn.textContent = '▼ ' + (this.s.preview_prompt || 'Preview Prompt').replace(/^[▶▼]\s*/, ''); }

		const loading = wrapper.querySelector('.ewp-ai-prompt-loading');
		const content = wrapper.querySelector('.ewp-ai-prompt-content');
		if (loading) { loading.style.display = 'block'; }
		if (content) { content.style.display = 'none'; }

		const tasks = this.getSelectedTasks(modal);
		const task = tasks[0] || 'title';
		const instruct = modal.querySelector('[name*="[instructions]"]')?.value || '';
		const transMode = modal.querySelector('[name*="[translation_mode]"]:checked')?.value || '';
		const cfg = EWPAiContent.config;

		try {
			const url = `${cfg.restUrl}prompt-preview?post_id=${postId}&task=${task}&instructions=${encodeURIComponent(instruct)}&translation_mode=${transMode}`;
			const resp = await fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce } });
			if (!resp.ok) { throw new Error('Preview failed'); }
			const data = await resp.json();
			const sysPre = wrapper.querySelector('#ewp-prompt-system');
			const usrPre = wrapper.querySelector('#ewp-prompt-user');
			if (sysPre) { sysPre.textContent = data.system || ''; }
			if (usrPre) { usrPre.textContent = data.user || ''; }
			if (loading) { loading.style.display = 'none'; }
			if (content) { content.style.display = 'block'; }
		} catch {
			if (loading) { loading.textContent = 'Failed to load preview.'; }
		}
	}

	// ── Generate ─────────────────────────────────────────────────────────────

	getSelectedTasks(modal) {
		if (!modal) { return []; }
		return Array.from(
			modal.querySelectorAll('[name*="[tasks]"]:checked')
		).map(el => el.value);
	}

	async runGenerate(modal, postId, frontUrl, screenshot) {
		if (!modal) { return; }

		const tasks = this.getSelectedTasks(modal);
		if (!tasks.length) { return; }

		const provider = modal.querySelector('[name*="[provider]"]')?.value || '';
		const model = modal.querySelector('[name*="[model]"]')?.value || '';
		const instruct = modal.querySelector('[name*="[instructions]"]')?.value || '';
		const transMode = modal.querySelector('[name*="[translation_mode]"]:checked')?.value || '';

		this.lastParams = { postId, frontUrl, screenshot, tasks, provider, model, instruct, transMode };
		this.results = {};

		// Hide results, show progress.
		const resultsSection = modal.querySelector('.ewp-ai-results-section');
		const progress = modal.querySelector('.ewp-ai-modal-progress');
		const errorEl = modal.querySelector('.ewp-ai-modal-error');
		const generateBtn = modal.querySelector('.ewp-ai-generate-btn');

		if (resultsSection) { resultsSection.style.display = 'none'; }
		if (errorEl) { errorEl.style.display = 'none'; }
		if (progress) { progress.style.display = 'flex'; }
		if (generateBtn) { generateBtn.disabled = true; }

		// Screenshot capture.
		let imageBase64 = '';
		const cfg = EWPAiContent.config;
		if (screenshot && frontUrl && typeof html2canvas !== 'undefined') {
			const progressLabel = modal.querySelector('.ewp-ai-progress-label');
			if (progressLabel) { progressLabel.textContent = cfg.strings?.capturing || 'Capturing…'; }
			try {
				imageBase64 = await this.captureScreenshot(frontUrl);
			} catch { /* non-fatal */ }
		}

		const progressLabel = modal.querySelector('.ewp-ai-progress-label');

		for (const task of tasks) {
			if (progressLabel) {
				progressLabel.textContent = `${cfg.strings?.generating || 'Generating'} ${task}…`;
			}
			const body = { post_id: postId, task, provider, model, instructions: instruct, translation_mode: transMode };
			if (imageBase64) { body.image_base64 = imageBase64; body.image_mime = 'image/jpeg'; }

			try {
				const resp = await fetch(cfg.restUrl + 'generate', {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify(body),
				});
				const data = await resp.json();
				if (!resp.ok) {
					throw new Error(data.message || cfg.strings?.error_generic || 'Error');
				}
				this.results[task] = data.content || '';
			} catch (err) {
				if (progress) { progress.style.display = 'none'; }
				if (generateBtn) { generateBtn.disabled = false; }
				if (errorEl) {
					errorEl.style.display = 'block';
					errorEl.querySelector('p').textContent = err.message || (cfg.strings?.error_generic || 'Error');
				}
				return;
			}
		}

		// Show results.
		if (progress) { progress.style.display = 'none'; }
		if (generateBtn) { generateBtn.disabled = false; }
		this.showResults(modal, tasks);
	}

	showResults(modal, tasks) {
		if (!modal) { return; }

		const section = modal.querySelector('.ewp-ai-results-section');
		if (!section) { return; }

		// Show/hide result items based on tasks
		tasks.forEach(task => {
			const item = section.querySelector(`.ewp-ai-result-item[data-task="${task}"]`);
			const textEl = item && item.querySelector('.ewp-ai-result-text');
			if (!item || !textEl) { return; }

			item.style.display = 'block';
			if (task === 'full_content') {
				textEl.innerHTML = this.results[task] || '';
			} else {
				textEl.textContent = this.results[task] || '';
			}
		});

		// Show results section and update footer buttons
		section.style.display = 'block';
		const generateBtn = modal.querySelector('.ewp-ai-generate-btn');
		const acceptBtn = modal.querySelector('.ewp-ai-accept-all');
		const retryBtn = modal.querySelector('.ewp-ai-retry');

		if (generateBtn) { generateBtn.style.display = 'none'; }
		if (acceptBtn) { acceptBtn.style.display = 'inline-block'; }
		if (retryBtn) { retryBtn.style.display = 'inline-block'; }
	}

	// ── Editor integration ───────────────────────────────────────────────────

	applyToEditor(results) {
		if (results.title) { this.setTitle(results.title); }
		if (results.excerpt) { this.setExcerpt(results.excerpt); }
		if (results.full_content) { this.setContent(results.full_content); }
	}

	setTitle(value) {
		const titleInput = document.getElementById( 'title' );
		if ( titleInput ) {
			titleInput.value = value;
			titleInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return;
		}
		if ( window.wp && wp.data ) {
			wp.data.dispatch( 'core/editor' ).editPost( { title: value } );
		}
	}

	setExcerpt(value) {
		const excerptArea = document.getElementById( 'excerpt' );
		if ( excerptArea ) {
			excerptArea.value = value;
			excerptArea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return;
		}
		if ( window.wp && wp.data ) {
			wp.data.dispatch( 'core/editor' ).editPost( { excerpt: value } );
		}
	}

	setContent(html) {
		// Gutenberg — parse HTML into native blocks via rawHandler.
		if (window.wp && wp.blocks && wp.data && wp.data.select('core/block-editor')) {
			const blocks = wp.blocks.rawHandler({ HTML: html });
			wp.data.dispatch('core/block-editor').resetBlocks(blocks);
			return;
		}
	// Classic TinyMCE.
		if ( window.tinyMCE ) {
			const editor = tinyMCE.get( 'content' );
			if ( editor && ! editor.isHidden() ) {
				editor.setContent(html);
				editor.fire( 'change' );
				return;
			}
		}
		// Classic text tab.
		const contentArea = document.getElementById( 'content' );
		if ( contentArea ) {
			contentArea.value = html;
			contentArea.dispatchEvent(new Event('input', { bubbles: true }));
		}
	}

	// ── Screenshot ───────────────────────────────────────────────────────────

	captureScreenshot(frontendUrl) {
		return new Promise( ( resolve, reject ) => {
			if (!frontendUrl) { resolve(''); return; }
			const iframe = document.createElement( 'iframe' );
			iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:1280px;height:900px;border:none;visibility:hidden;';
			document.body.appendChild( iframe );
			const cleanup = () => { if (iframe.parentNode) { iframe.parentNode.removeChild(iframe); } };
			iframe.onload = () => {
				setTimeout( () => {
					html2canvas(iframe.contentDocument.body, { useCORS: true, allowTaint: false, scale: 0.5, windowWidth: 1280, windowHeight: 900, logging: false })
						.then(canvas => {
							cleanup();
							const base64 = canvas.toDataURL('image/jpeg', 0.7).split(';base64,')[1] || '';
							resolve(base64);
						})
						.catch(err => { cleanup(); reject(err); });
				}, 1000 );
			};
			iframe.onerror = () => { cleanup(); reject(new Error('iframe error')); };
			iframe.src = frontendUrl;
		} );
	}

	// ── Business Context Auto-generation ────────────────────────────────────

	/**
	 * Called after awm_modal_fields_saved fires for the business_data modal.
	 * Calls the generate-business-context endpoint (reads from DB — no payload),
	 * then populates the business_context textarea on the settings page.
	 */
	generateBusinessContext() {
		const cfg = EWPAiContent.config;
		const textarea = document.querySelector('[name*="[business_context]"]');
		if (!textarea) { return; }

		// Show loading overlay
		this.showLoadingOverlay(this.s.generating_context || 'Generating business context...');

		// Failsafe: ensure overlay is hidden after 60 seconds no matter what
		const timeoutId = setTimeout(() => {
			this.hideLoadingOverlay();
			this.showErrorNotice('Request timed out. Please try again.');
		}, 60000);

		try {
			awm_ajax_call({
				url: cfg.restUrl + 'generate-business-context',
				method: 'POST',
				data: {},
				callback: (data, options) => {
					clearTimeout(timeoutId);
					this.hideLoadingOverlay();

					// Check if response contains an error (REST API returns 200 with error payload)
					if (data && data.code && data.message) {
						this.showErrorNotice(data.message);
						console.error('[EWP AI] generateBusinessContext failed:', data);
						return;
					}

					if (data && data.business_context) {
						textarea.value = data.business_context;
						// Mark field dirty so EWP settings form saves it.
						textarea.dispatchEvent(new Event('change', { bubbles: true }));
					}
				},
				errorCallback: (status, responseText, options) => {
					clearTimeout(timeoutId);
					this.hideLoadingOverlay();

					// Parse error message
					let errorMsg = this.s.error_generic || 'Failed to generate business context.';
					try {
						if (responseText) {
							const response = JSON.parse(responseText);
							if (response && response.message) {
								errorMsg = response.message;
							}
						}
					} catch (e) {
					// Use default message if JSON parse fails
					}

					// Show error message
					this.showErrorNotice(errorMsg);

					// Log for debugging
					console.error('[EWP AI] generateBusinessContext failed:', {
						status: status,
						message: errorMsg,
						responseText: responseText
					});
				}
			});
		} catch (e) {
			clearTimeout(timeoutId);
			this.hideLoadingOverlay();
			this.showErrorNotice('An unexpected error occurred: ' + e.message);
			console.error('[EWP AI] generateBusinessContext exception:', e);
		}
	}

	/**
	 * Show error notice below the business context field
	 */
	showErrorNotice(message) {
		const textarea = document.querySelector('[name*="[business_context]"]');
		if (!textarea) { return; }

		const wrap = textarea.closest('.awm-field-wrap') ?? textarea.parentElement;

		// Remove any existing error notice
		const existing = wrap.querySelector('.ewp-ai-error-notice');
		if (existing) { existing.remove(); }

		// Create new error notice
		const notice = document.createElement('div');
		notice.className = 'notice notice-error ewp-ai-error-notice';
		notice.style.marginTop = '8px';
		notice.innerHTML = `<p>${message}</p>`;

		wrap.appendChild(notice);

		// Auto-remove after 10 seconds
		setTimeout(() => notice.remove(), 10000);
	}

	/**
	 * Show loading overlay with message
	 */
	showLoadingOverlay(message) {
		if (this.loadingOverlay) { return; }

		this.loadingOverlay = document.createElement('div');
		this.loadingOverlay.className = 'ewp-ai-loading-overlay';
		this.loadingOverlay.innerHTML = `
			<div class="ewp-ai-loading-content">
				<span class="spinner is-active"></span>
				<p>${message}</p>
			</div>
		`;
		document.body.appendChild(this.loadingOverlay);
		document.body.classList.add('ewp-ai-loading');
	}

	/**
	 * Hide loading overlay
	 */
	hideLoadingOverlay() {
		if (this.loadingOverlay) {
			this.loadingOverlay.remove();
			this.loadingOverlay = null;
			document.body.classList.remove('ewp-ai-loading');
		}
	}
}

// Self-instantiate.
new EWPAiContent();
