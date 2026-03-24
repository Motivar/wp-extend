/**
 * EWP AI Content Generator — Meta box interactions.
 *
 * Handles the full generate → preview → accept/discard workflow
 * in the AI Content meta box. Uses awm_ajax_call() for all REST
 * communication and html2canvas (optional) for screenshot capture.
 *
 * Loaded via Dynamic Asset Loader when .ewp-ai-content-metabox is present.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWPAiContent {

	/**
	 * Localized config from PHP (ewpAiContent).
	 * @type {object}
	 */
	static config = typeof ewpAiContent !== 'undefined' ? ewpAiContent : {};

	/**
	 * Root meta box element.
	 * @type {HTMLElement|null}
	 */
	metaBox = null;

	/**
	 * Last generated content string (held for accept action).
	 * @type {string}
	 */
	lastContent = '';

	/**
	 * Last requested task (held for regenerate action).
	 * @type {string}
	 */
	lastTask = '';

	/**
	 * Constructor — cache DOM references and bind events.
	 */
	constructor() {
		this.metaBox = document.querySelector( '.ewp-ai-content-metabox' );

		if ( ! this.metaBox ) {
			return;
		}

		this.bindEvents();
	}

	// -------------------------------------------------------------------------
	// Event binding
	// -------------------------------------------------------------------------

	/**
	 * Bind all button events inside the meta box.
	 */
	bindEvents() {
		const generateBtn   = this.metaBox.querySelector( '#ewp-ai-generate-btn' );
		const acceptBtn     = this.metaBox.querySelector( '#ewp-ai-accept-btn' );
		const regenerateBtn = this.metaBox.querySelector( '#ewp-ai-regenerate-btn' );
		const discardBtn    = this.metaBox.querySelector( '#ewp-ai-discard-btn' );
		const providerSel   = this.metaBox.querySelector( '#ewp-ai-provider' );

		if ( generateBtn )   generateBtn.addEventListener( 'click', () => this.onGenerate() );
		if ( acceptBtn )     acceptBtn.addEventListener( 'click',   () => this.onAccept() );
		if ( regenerateBtn ) regenerateBtn.addEventListener( 'click', () => this.onGenerate() );
		if ( discardBtn )    discardBtn.addEventListener( 'click',   () => this.onDiscard() );

		// Filter model options when provider changes.
		if ( providerSel ) {
			providerSel.addEventListener( 'change', () => this.syncModels() );
			this.syncModels(); // initial filter on load
		}
	}

	// -------------------------------------------------------------------------
	// Generate flow
	// -------------------------------------------------------------------------

	/**
	 * Trigger content generation.
	 * Captures a screenshot first if enabled, then calls the REST endpoint.
	 */
	async onGenerate() {
		const task     = this.getFieldValue( '#ewp-ai-task' );
		const provider = this.getFieldValue( '#ewp-ai-provider' );
		const model    = this.getFieldValue( '#ewp-ai-model' );
		const instruct = this.getFieldValue( '#ewp-ai-instructions' );
		const transMod = this.getTranslationMode();
		const postId   = parseInt( this.metaBox.dataset.postId, 10 );
		const cfg      = EWPAiContent.config;

		this.lastTask = task;
		this.hideResult();
		this.hideError();
		this.setGenerating( true );

		let imageBase64 = '';
		let imageMime   = 'image/jpeg';

		// Screenshot capture (only when enabled and html2canvas is loaded).
		if ( cfg.includeScreenshot && typeof html2canvas !== 'undefined' ) {
			this.setProgressLabel( cfg.strings.capturing );
			try {
				imageBase64 = await this.captureScreenshot();
			} catch ( err ) {
				// Non-fatal — proceed without screenshot.
				console.warn( '[EWP AI] Screenshot capture failed:', err );
			}
		}

		this.setProgressLabel( cfg.strings.generating );

		const data = {
			post_id:          postId,
			task:             task,
			provider:         provider,
			model:            model,
			instructions:     instruct,
			translation_mode: transMod,
			image_base64:     imageBase64,
			image_mime:       imageMime,
		};

		awm_ajax_call( {
			method:        'POST',
			url:           cfg.restUrl + 'generate',
			data:          data,
			log:           false,
			callback:      ( response ) => this.onGenerateSuccess( response ),
			errorCallback: ( error )    => this.onGenerateError( error ),
		} );
	}

	/**
	 * Handle successful generation response.
	 *
	 * @param {object} response REST response data.
	 */
	onGenerateSuccess( response ) {
		this.setGenerating( false );

		if ( ! response || ! response.content ) {
			this.showError( EWPAiContent.config.strings.error_generic );
			return;
		}

		this.lastContent = response.content;
		this.showResult( response.content, response.task );
	}

	/**
	 * Handle generation error.
	 *
	 * @param {object} error Error data from awm_ajax_call errorCallback.
	 */
	onGenerateError( error ) {
		this.setGenerating( false );

		const message = ( error && error.message )
			? error.message
			: EWPAiContent.config.strings.error_generic;

		this.showError( message );
	}

	// -------------------------------------------------------------------------
	// Accept / Discard
	// -------------------------------------------------------------------------

	/**
	 * Accept the generated content — populate the appropriate editor field.
	 * No server call needed; user must click Update/Publish to save.
	 */
	onAccept() {
		if ( ! this.lastContent ) {
			return;
		}

		const task = this.lastTask;

		if ( 'title' === task ) {
			this.setTitle( this.lastContent );
		} else if ( 'excerpt' === task ) {
			this.setExcerpt( this.lastContent );
		} else if ( 'full_content' === task ) {
			this.setContent( this.lastContent );
		}

		this.onDiscard();
	}

	/**
	 * Discard the generated content — reset the meta box to initial state.
	 */
	onDiscard() {
		this.lastContent = '';
		this.hideResult();
		this.hideError();
	}

	// -------------------------------------------------------------------------
	// Editor field population
	// -------------------------------------------------------------------------

	/**
	 * Set the post title (Classic and Gutenberg).
	 *
	 * @param {string} value New title text.
	 */
	setTitle( value ) {
		// Classic editor.
		const titleInput = document.getElementById( 'title' );
		if ( titleInput ) {
			titleInput.value = value;
			titleInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return;
		}

		// Gutenberg.
		if ( window.wp && wp.data ) {
			wp.data.dispatch( 'core/editor' ).editPost( { title: value } );
		}
	}

	/**
	 * Set the post excerpt (Classic and Gutenberg).
	 *
	 * @param {string} value New excerpt text.
	 */
	setExcerpt( value ) {
		// Classic editor.
		const excerptArea = document.getElementById( 'excerpt' );
		if ( excerptArea ) {
			excerptArea.value = value;
			excerptArea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return;
		}

		// Gutenberg.
		if ( window.wp && wp.data ) {
			wp.data.dispatch( 'core/editor' ).editPost( { excerpt: value } );
		}
	}

	/**
	 * Set the post content (Classic TinyMCE and Gutenberg).
	 *
	 * @param {string} value New content HTML.
	 */
	setContent( value ) {
		// Classic editor — TinyMCE active.
		if ( window.tinyMCE ) {
			const editor = tinyMCE.get( 'content' );
			if ( editor && ! editor.isHidden() ) {
				editor.setContent( value );
				editor.fire( 'change' );
				return;
			}
		}

		// Classic editor — Text tab active.
		const contentArea = document.getElementById( 'content' );
		if ( contentArea ) {
			contentArea.value = value;
			contentArea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			return;
		}

		// Gutenberg — insert as a single HTML block.
		if ( window.wp && wp.blocks && wp.data ) {
			const block  = wp.blocks.createBlock( 'core/html', { content: value } );
			const blocks = wp.data.select( 'core/block-editor' ).getBlocks();

			if ( blocks.length > 0 ) {
				// Replace all existing blocks.
				wp.data.dispatch( 'core/block-editor' ).replaceBlocks(
					blocks.map( b => b.clientId ),
					[ block ]
				);
			} else {
				wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Screenshot capture
	// -------------------------------------------------------------------------

	/**
	 * Capture a screenshot of the post frontend via html2canvas.
	 *
	 * Opens the post frontend URL in a hidden iframe, waits for it to
	 * load, then captures it with html2canvas. Returns a base64 JPEG
	 * string (without the data URI prefix).
	 *
	 * @returns {Promise<string>} Base64 JPEG string, or '' on failure.
	 */
	captureScreenshot() {
		return new Promise( ( resolve, reject ) => {
			const frontendUrl = this.metaBox.dataset.frontendUrl;

			if ( ! frontendUrl ) {
				resolve( '' );
				return;
			}

			const iframe = document.createElement( 'iframe' );
			iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:1280px;height:900px;border:none;visibility:hidden;';
			document.body.appendChild( iframe );

			const cleanup = () => {
				if ( iframe.parentNode ) {
					iframe.parentNode.removeChild( iframe );
				}
			};

			iframe.onload = () => {
				// Brief delay to allow the page to fully render.
				setTimeout( () => {
					html2canvas( iframe.contentDocument.body, {
						useCORS:         true,
						allowTaint:      false,
						scale:           0.5, // reduce size
						windowWidth:     1280,
						windowHeight:    900,
						logging:         false,
					} )
					.then( canvas => {
						cleanup();
						// Export as JPEG (quality 0.7) and strip the data URI prefix.
						const dataUrl = canvas.toDataURL( 'image/jpeg', 0.7 );
						const base64  = dataUrl.split( ';base64,' )[ 1 ] || '';
						resolve( base64 );
					} )
					.catch( err => {
						cleanup();
						reject( err );
					} );
				}, 1000 );
			};

			iframe.onerror = () => {
				cleanup();
				reject( new Error( 'iframe failed to load' ) );
			};

			// Set src after attaching handlers.
			iframe.src = frontendUrl;
		} );
	}

	// -------------------------------------------------------------------------
	// UI helpers
	// -------------------------------------------------------------------------

	/**
	 * Show/hide the generate button loading state.
	 *
	 * @param {boolean} loading
	 */
	setGenerating( loading ) {
		const btn      = this.metaBox.querySelector( '#ewp-ai-generate-btn' );
		const progress = this.metaBox.querySelector( '#ewp-ai-progress' );

		if ( btn ) {
			btn.disabled   = loading;
			btn.classList.toggle( 'ewp-ai--loading', loading );
		}

		if ( progress ) {
			progress.style.display = loading ? 'flex' : 'none';
		}
	}

	/**
	 * Update the progress label text.
	 *
	 * @param {string} label
	 */
	setProgressLabel( label ) {
		const el = this.metaBox.querySelector( '#ewp-ai-progress-label' );
		if ( el ) {
			el.textContent = label;
		}
	}

	/**
	 * Show the result preview area.
	 *
	 * @param {string} content Generated content.
	 * @param {string} task    Task type (used to format preview).
	 */
	showResult( content, task ) {
		const wrapper     = this.metaBox.querySelector( '#ewp-ai-result' );
		const contentArea = this.metaBox.querySelector( '#ewp-ai-result-content' );

		if ( ! wrapper || ! contentArea ) {
			return;
		}

		// For full_content show rendered HTML preview; for title/excerpt show plain text.
		if ( 'full_content' === task ) {
			contentArea.innerHTML = content;
		} else {
			contentArea.textContent = content;
		}

		wrapper.style.display = 'block';
	}

	/**
	 * Hide the result preview area.
	 */
	hideResult() {
		const wrapper = this.metaBox.querySelector( '#ewp-ai-result' );
		if ( wrapper ) {
			wrapper.style.display = 'none';
		}
	}

	/**
	 * Show an error message.
	 *
	 * @param {string} message
	 */
	showError( message ) {
		const wrapper = this.metaBox.querySelector( '#ewp-ai-error' );
		const label   = this.metaBox.querySelector( '#ewp-ai-error-message' );

		if ( wrapper ) wrapper.style.display = 'block';
		if ( label )   label.textContent     = message;
	}

	/**
	 * Hide the error area.
	 */
	hideError() {
		const wrapper = this.metaBox.querySelector( '#ewp-ai-error' );
		if ( wrapper ) {
			wrapper.style.display = 'none';
		}
	}

	/**
	 * Filter model <option> elements to only show those matching the
	 * currently selected provider.
	 */
	syncModels() {
		const providerSel = this.metaBox.querySelector( '#ewp-ai-provider' );
		const modelSel    = this.metaBox.querySelector( '#ewp-ai-model' );

		if ( ! providerSel || ! modelSel ) {
			return;
		}

		const selected = providerSel.value;
		let   first    = null;

		Array.from( modelSel.options ).forEach( opt => {
			const show      = opt.dataset.provider === selected;
			opt.hidden      = ! show;
			opt.disabled    = ! show;
			if ( show && ! first ) {
				first = opt;
			}
		} );

		// Select first visible option if current selection is hidden.
		if ( modelSel.selectedOptions[ 0 ] && modelSel.selectedOptions[ 0 ].hidden && first ) {
			modelSel.value = first.value;
		}
	}

	/**
	 * Get the value of a select or input inside the meta box.
	 *
	 * @param {string} selector CSS selector.
	 * @returns {string}
	 */
	getFieldValue( selector ) {
		const el = this.metaBox.querySelector( selector );
		return el ? el.value : '';
	}

	/**
	 * Get the selected translation mode (only when WPML is active).
	 *
	 * @returns {string} 'translate', 'recreate', or ''.
	 */
	getTranslationMode() {
		const checked = this.metaBox.querySelector( 'input[name="ewp_ai_translation_mode"]:checked' );
		return checked ? checked.value : '';
	}
}

// Self-instantiate — Dynamic Asset Loader only injects this file when
// .ewp-ai-content-metabox is present on the page.
new EWPAiContent();
