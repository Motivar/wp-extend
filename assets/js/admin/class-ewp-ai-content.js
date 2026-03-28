/**
 * EWP AI Content Generator — Modal UI + Editor Integration.
 *
 * Features:
 *  - Generator modal: multi-select tasks, prompt preview, Accept All / Retry.
 *  - Business onboarding modal (on-demand from settings page).
 *  - Gutenberg: content inserted as native blocks via wp.blocks.rawHandler.
 *  - Classic editor: TinyMCE / textarea fallback.
 *
 * Loaded via Dynamic Asset Loader (.ewp-ai-content-metabox)
 * and also explicitly on the settings page.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWPAiContent {

	/** @type {object} */
	static config = typeof ewpAiContent !== 'undefined' ? ewpAiContent : {};

	/** @type {EWP_AI_Provider[]} */
	providers = [];

	/** @type {object} { title, excerpt, full_content } */
	results = {};

	/** @type {object} last generation params for Retry */
	lastParams = {};

	/** @type {HTMLElement|null} */
	generatorModal = null;

	/** @type {HTMLElement|null} */
	onboardingModal = null;

	constructor() {
		this.s = EWPAiContent.config.strings || {};
		this.init();
	}

	init() {
		const metaBox = document.querySelector('.ewp-ai-content-metabox');
		if (metaBox) {
			this.initMetaBox(metaBox);
		}
		if (EWPAiContent.config.isSettingsPage) {
			this.initSettingsPage();
		}
	}

	// ── Meta box ────────────────────────────────────────────────────────────

	initMetaBox(metaBox) {
		const btn = metaBox.querySelector('.ewp-ai-open-modal');
		if (btn) {
			btn.addEventListener('click', () => this.openGeneratorModal(metaBox));
		}
	}

	// ── Settings page ────────────────────────────────────────────────────────

	initSettingsPage() {
		// After awm_modal saves business_data, auto-generate the business_context
		// summary and populate the textarea on the settings page.
		document.addEventListener('awm_modal_fields_saved', e => {
			const overlay = e.detail?.overlay;
			if (!overlay || !overlay.id.includes('business_data')) { return; }
			this.generateBusinessContext();
		});
	}

	// ── Generator Modal ─────────────────────────────────────────────────────

	async openGeneratorModal(metaBox) {
		this.results = {};
		this.lastParams = {};

		const postId = parseInt(metaBox.dataset.postId, 10);
		const frontUrl = metaBox.dataset.frontendUrl || '';
		const screenshot = metaBox.dataset.screenshotEnabled === '1';
		const wpml = metaBox.dataset.wpmlActive === '1';

		// Build and mount modal.
		this.generatorModal = this.buildGeneratorModal(postId, frontUrl, screenshot, wpml);
		document.body.appendChild(this.generatorModal);
		document.body.classList.add('ewp-ai-modal-open');

		// Load providers into selects.
		await this.loadProviders();

		// Bind modal events.
		this.bindGeneratorEvents(postId, frontUrl, screenshot);

		// Animate in.
		requestAnimationFrame(() => this.generatorModal.classList.add('ewp-ai-modal--visible'));
	}

	buildGeneratorModal(postId, frontUrl, screenshot, wpml) {
		const s = this.s;
		const cfg = EWPAiContent.config;

		const overlay = document.createElement('div');
		overlay.className = 'ewp-ai-modal-overlay';
		overlay.id = 'ewp-ai-generator-modal';

		overlay.innerHTML = `
			<div class="ewp-ai-modal" role="dialog" aria-modal="true">
				<div class="ewp-ai-modal-header">
					<h2>✦ ${s.generate || 'Generate with AI'}</h2>
					<button type="button" class="ewp-ai-modal-close" aria-label="Close">✕</button>
				</div>
				<div class="ewp-ai-modal-body">
					<div class="ewp-ai-modal-row">
						<label class="ewp-ai-row-label">${s.generate || 'Generate'}</label>
						<div class="ewp-ai-task-checks">
							<label><input type="checkbox" name="ewp_tasks" value="title" checked> ${s.task_title || 'Title'}</label>
							<label><input type="checkbox" name="ewp_tasks" value="excerpt"> ${s.task_excerpt || 'Excerpt'}</label>
							<label><input type="checkbox" name="ewp_tasks" value="full_content"> ${s.task_content || 'Full Content'}</label>
						</div>
					</div>
					<div class="ewp-ai-modal-row ewp-ai-modal-row--2col">
						<div>
							<label class="ewp-ai-row-label">Provider</label>
							<select id="ewp-ai-modal-provider" class="ewp-ai-select">
								<option>${s.generating || 'Loading…'}</option>
							</select>
						</div>
						<div>
							<label class="ewp-ai-row-label">Model</label>
							<select id="ewp-ai-modal-model" class="ewp-ai-select"></select>
						</div>
					</div>
					${wpml ? `
					<div class="ewp-ai-modal-row ewp-ai-wpml-row">
						<label class="ewp-ai-row-label">${s.translate_mode || 'Translation Mode'}</label>
						<div class="ewp-ai-radio-group">
							<label><input type="radio" name="ewp_trans_mode" value="translate" checked> ${s.translate_label || 'Translate'}</label>
							<label><input type="radio" name="ewp_trans_mode" value="recreate"> ${s.recreate_label || 'Recreate'}</label>
						</div>
					</div>` : ''}
					<div class="ewp-ai-modal-row">
						<label class="ewp-ai-row-label">Instructions <span class="ewp-ai-optional">(optional)</span></label>
						<textarea id="ewp-ai-modal-instructions" rows="2" placeholder="${s.instructions_ph || 'Add specific instructions…'}"></textarea>
					</div>
					<div class="ewp-ai-modal-row ewp-ai-prompt-row">
						<button type="button" class="ewp-ai-prompt-toggle">${s.preview_prompt || '▶ Preview Prompt'}</button>
						<div class="ewp-ai-prompt-preview" style="display:none;">
							<div class="ewp-ai-prompt-loading">${s.loading_preview || 'Loading…'}</div>
							<div class="ewp-ai-prompt-content" style="display:none;">
								<div class="ewp-ai-prompt-section">
									<strong>System</strong>
									<pre class="ewp-ai-pre" id="ewp-prompt-system"></pre>
								</div>
								<div class="ewp-ai-prompt-section">
									<strong>User</strong>
									<pre class="ewp-ai-pre" id="ewp-prompt-user"></pre>
								</div>
							</div>
						</div>
					</div>
					<div class="ewp-ai-modal-progress" style="display:none;">
						<span class="spinner is-active"></span>
						<span class="ewp-ai-progress-label">${s.generating || 'Generating…'}</span>
					</div>
					<div class="ewp-ai-modal-error notice notice-error" style="display:none;"><p></p></div>
				</div>
				<div class="ewp-ai-modal-footer">
					<button type="button" class="button button-primary ewp-ai-generate-submit">${s.generate || '✦ Generate'}</button>
					<button type="button" class="button ewp-ai-modal-close-btn">${s.cancel || 'Cancel'}</button>
				</div>
				<div class="ewp-ai-results-section" style="display:none;">
					<div class="ewp-ai-modal-body">
						<div class="ewp-ai-result-item" data-task="title" style="display:none;">
							<strong>${s.task_title || 'Title'}</strong>
							<div class="ewp-ai-result-text"></div>
						</div>
						<div class="ewp-ai-result-item" data-task="excerpt" style="display:none;">
							<strong>${s.task_excerpt || 'Excerpt'}</strong>
							<div class="ewp-ai-result-text"></div>
						</div>
						<div class="ewp-ai-result-item" data-task="full_content" style="display:none;">
							<strong>${s.task_content || 'Full Content'}</strong>
							<div class="ewp-ai-result-text ewp-ai-result-text--content"></div>
						</div>
					</div>
					<div class="ewp-ai-modal-footer">
						<button type="button" class="button button-primary ewp-ai-accept-all">${s.accept_all || 'Accept All'}</button>
						<button type="button" class="button ewp-ai-retry">${s.retry || 'Retry'}</button>
						<button type="button" class="button ewp-ai-modal-close-btn">${s.cancel || 'Cancel'}</button>
					</div>
				</div>
			</div>`;

		return overlay;
	}

	bindGeneratorEvents(postId, frontUrl, screenshot) {
		const m = this.generatorModal;

		// Close.
		m.querySelectorAll('.ewp-ai-modal-close, .ewp-ai-modal-close-btn').forEach(btn => {
			btn.addEventListener('click', () => this.closeGeneratorModal());
		});
		m.addEventListener('click', e => {
			if (e.target === m) { this.closeGeneratorModal(); }
		});

		// Provider → model sync.
		const providerSel = m.querySelector('#ewp-ai-modal-provider');
		if ( providerSel ) {
			providerSel.addEventListener('change', () => this.syncModels());
		}

		// Prompt preview toggle.
		const promptToggle = m.querySelector('.ewp-ai-prompt-toggle');
		if (promptToggle) {
			promptToggle.addEventListener('click', () => this.togglePromptPreview(postId));
		}

		// Generate.
		const generateBtn = m.querySelector('.ewp-ai-generate-submit');
		if (generateBtn) {
			generateBtn.addEventListener('click', () => this.runGenerate(postId, frontUrl, screenshot));
		}

		// Accept All.
		const acceptBtn = m.querySelector('.ewp-ai-accept-all');
		if (acceptBtn) {
			acceptBtn.addEventListener('click', () => {
				this.applyToEditor(this.results);
				this.closeGeneratorModal();
			});
		}

		// Retry.
		const retryBtn = m.querySelector('.ewp-ai-retry');
		if (retryBtn) {
			retryBtn.addEventListener('click', () => this.runGenerate(postId, frontUrl, screenshot));
		}

		// Escape key.
		this._escHandler = e => { if (e.key === 'Escape') { this.closeGeneratorModal(); } };
		document.addEventListener('keydown', this._escHandler);
	}

	closeGeneratorModal() {
		if (!this.generatorModal) { return; }
		this.generatorModal.classList.remove('ewp-ai-modal--visible');
		setTimeout(() => {
			if (this.generatorModal && this.generatorModal.parentNode) {
				this.generatorModal.parentNode.removeChild(this.generatorModal);
			}
			this.generatorModal = null;
			document.body.classList.remove('ewp-ai-modal-open');
		}, 200);
		if (this._escHandler) {
			document.removeEventListener('keydown', this._escHandler);
		}
	}

	// ── Providers ────────────────────────────────────────────────────────────

	async loadProviders() {
		const cfg = EWPAiContent.config;
		try {
			const resp = await fetch(cfg.restUrl + 'providers', {
				headers: { 'X-WP-Nonce': cfg.nonce },
			});
			if (!resp.ok) { throw new Error('Failed to load providers'); }
			this.providers = await resp.json();
		} catch {
			this.providers = [];
		}

		const providerSel = this.generatorModal && this.generatorModal.querySelector('#ewp-ai-modal-provider');
		const modelSel = this.generatorModal && this.generatorModal.querySelector('#ewp-ai-modal-model');

		if (!providerSel || !modelSel) { return; }

		providerSel.innerHTML = '';
		modelSel.innerHTML = '';

		this.providers.forEach(p => {
			const opt = document.createElement('option');
			opt.value = p.id;
			opt.text = p.label;
			providerSel.appendChild(opt);

			Object.entries(p.models || {}).forEach(([id, label]) => {
				const mOpt = document.createElement('option');
				mOpt.value = id;
				mOpt.text = label;
				mOpt.dataset.provider = p.id;
				modelSel.appendChild(mOpt);
			});
		});

		this.syncModels();
	}

	syncModels() {
		const m = this.generatorModal;
		if (!m) { return; }
		const providerSel = m.querySelector('#ewp-ai-modal-provider');
		const modelSel = m.querySelector('#ewp-ai-modal-model');
		if (!providerSel || !modelSel) { return; }

		const selected = providerSel.value;
		let first = null;

		Array.from(modelSel.options).forEach(opt => {
			const show = opt.dataset.provider === selected;
			opt.hidden = !show;
			opt.disabled = !show;
			if (show && !first) { first = opt; }
		} );

		if (modelSel.selectedOptions[0] && modelSel.selectedOptions[0].hidden && first) {
			modelSel.value = first.value;
		}
	}

	// ── Prompt preview ───────────────────────────────────────────────────────

	async togglePromptPreview(postId) {
		const m = this.generatorModal;
		const wrapper = m && m.querySelector('.ewp-ai-prompt-preview');
		const btn = m && m.querySelector('.ewp-ai-prompt-toggle');
		if (!wrapper) { return; }

		const isOpen = wrapper.style.display !== 'none';

		if (isOpen) {
			wrapper.style.display = 'none';
			if (btn) { btn.textContent = (this.s.preview_prompt || '▶ Preview Prompt'); }
			return;
		}

		wrapper.style.display = 'block';
		if (btn) { btn.textContent = '▼ ' + (this.s.preview_prompt || 'Preview Prompt').replace(/^[▶▼]\s*/, ''); }

		const loading = wrapper.querySelector('.ewp-ai-prompt-loading');
		const content = wrapper.querySelector('.ewp-ai-prompt-content');
		if (loading) { loading.style.display = 'block'; }
		if (content) { content.style.display = 'none'; }

		const tasks = this.getSelectedTasks();
		const task = tasks[0] || 'title';
		const instruct = m.querySelector('#ewp-ai-modal-instructions')?.value || '';
		const transMode = m.querySelector('input[name="ewp_trans_mode"]:checked')?.value || '';
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

	getSelectedTasks() {
		if (!this.generatorModal) { return []; }
		return Array.from(
			this.generatorModal.querySelectorAll('input[name="ewp_tasks"]:checked')
		).map(el => el.value);
	}

	async runGenerate(postId, frontUrl, screenshot) {
		const m = this.generatorModal;
		if (!m) { return; }

		const tasks = this.getSelectedTasks();
		if (!tasks.length) { return; }

		const provider = m.querySelector('#ewp-ai-modal-provider')?.value || '';
		const model = m.querySelector('#ewp-ai-modal-model')?.value || '';
		const instruct = m.querySelector('#ewp-ai-modal-instructions')?.value || '';
		const transMode = m.querySelector('input[name="ewp_trans_mode"]:checked')?.value || '';

		this.lastParams = { postId, frontUrl, screenshot, tasks, provider, model, instruct, transMode };
		this.results = {};

		// Hide results, show progress.
		const resultsSection = m.querySelector('.ewp-ai-results-section');
		const footer = m.querySelector('.ewp-ai-modal-footer');
		const progress = m.querySelector('.ewp-ai-modal-progress');
		const errorEl = m.querySelector('.ewp-ai-modal-error');

		if (resultsSection) { resultsSection.style.display = 'none'; }
		if (errorEl) { errorEl.style.display = 'none'; }
		if (progress) { progress.style.display = 'flex'; }
		if (footer) { footer.querySelector('.ewp-ai-generate-submit').disabled = true; }

		// Screenshot capture.
		let imageBase64 = '';
		const cfg = EWPAiContent.config;
		if (screenshot && frontUrl && typeof html2canvas !== 'undefined') {
			const progressLabel = m.querySelector('.ewp-ai-progress-label');
			if (progressLabel) { progressLabel.textContent = cfg.strings?.capturing || 'Capturing…'; }
			try {
				imageBase64 = await this.captureScreenshot(frontUrl);
			} catch { /* non-fatal */ }
		}

		const progressLabel = m.querySelector('.ewp-ai-progress-label');

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
				if (footer) { footer.querySelector('.ewp-ai-generate-submit').disabled = false; }
				if (errorEl) {
					errorEl.style.display = 'block';
					errorEl.querySelector('p').textContent = err.message || (cfg.strings?.error_generic || 'Error');
				}
				return;
			}
		}

		// Show results.
		if (progress) { progress.style.display = 'none'; }
		if (footer) { footer.querySelector('.ewp-ai-generate-submit').disabled = false; }
		this.showResults(tasks);
	}

	showResults(tasks) {
		const m = this.generatorModal;
		if (!m) { return; }

		const section = m.querySelector('.ewp-ai-results-section');
		if (!section) { return; }

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

		section.style.display = 'block';
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
