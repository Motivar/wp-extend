/**
 * Import/Export admin screen (Extend WP > Import/Export).
 *
 * Lazy-loaded by the Dynamic Asset Loader on `.wrap[data-page="ewp-import-export"]`
 * (Extend_WP_Import_Export::register_dynamic_assets()). Handles the form submit:
 *
 * - Export (JSON or PHP): asks `ewp/v1/export`, shows the result in the preview
 *   panel (templates/admin-view/export-preview.php) with Copy and Download buttons.
 * - Import: reads the chosen JSON file and posts every content type to `ewp/v1/import`.
 *
 * Uses the globals of awm-global-script.js (`awm_ajax_call`, `ewp_jsVanillaSerialize`,
 * `awmGlobals`) and the strings in `ewpImportExport.strings`.
 *
 * @since 1.15.0 Replaces assets/js/admin/import-export.js; adds the PHP method and the preview panel.
 */
import { normalizeMethod, exportPreview, exportFileName, skippedMessage } from './ewp-import-export/helpers';

const FORM_ID = 'awm-form-ewp-import-export';

/** Text and MIME type of the export currently previewed. */
const state = { text: '', extension: 'json', mime: 'application/json' };

/**
 * A localized string.
 *
 * @param {string} key      Key of `ewpImportExport.strings`.
 * @param {string} fallback Text when the key is missing.
 * @return {string} The string.
 */
function t( key, fallback = '' ) {
	const strings = ( window.ewpImportExport && window.ewpImportExport.strings ) || {};
	return strings[ key ] || fallback;
}

/**
 * Append one line to the import log. Values from the uploaded file are inserted
 * as text, never parsed as HTML.
 *
 * @param {HTMLElement}                        logDiv Log container.
 * @param {Array<string|{b: (string|number)}>} parts  Text, or `{b: value}` for bold text.
 * @return {void}
 */
function importLogLine( logDiv, parts ) {
	const line = document.createElement( 'p' );
	parts.forEach( ( part ) => {
		if ( part !== null && typeof part === 'object' ) {
			const bold = document.createElement( 'b' );
			bold.textContent = String( part.b );
			line.appendChild( bold );
			return;
		}
		line.appendChild( document.createTextNode( String( part ) ) );
	} );
	logDiv.appendChild( line );
}

/**
 * Call a REST route of the `ewp/v1` namespace through awm_ajax_call().
 *
 * @param {Object|Array} data       Request data (an array of `key=value` strings for GET).
 * @param {string}       method     HTTP method, `get` or `post`.
 * @param {string}       actionCase Route name (`export`, `import`).
 * @return {Promise<*>} The decoded response.
 */
function sendRequest( data, method, actionCase ) {
	return new Promise( ( resolve, reject ) => {
		window.awm_ajax_call( {
			data,
			method,
			url: window.awmGlobals.url + '/wp-json/ewp/v1/' + actionCase + '/',
			callback: ( response ) => {
				if ( response ) {
					resolve( response );
					return;
				}
				reject( { message: t( 'error', 'An error occurred' ), response } );
			},
			errorCallback: ( error ) => reject( { message: ( error && error.message ) || t( 'error', 'An error occurred' ), response: error } ),
		} );
	} );
}

/**
 * Show an export in the preview panel.
 *
 * @param {HTMLFormElement} form     The import/export form.
 * @param {string}          method   Export method.
 * @param {string|Object}   response REST answer.
 * @return {void}
 */
function showPreview( form, method, response ) {
	const panel = form.querySelector( '[data-ewp-export-preview]' );
	if ( ! panel ) {
		return;
	}
	const preview = exportPreview( method, response );
	state.text = preview.text;
	state.extension = preview.extension;
	state.mime = preview.mime;

	panel.querySelector( '[data-ewp-export-code]' ).textContent = preview.text;
	const notice = panel.querySelector( '[data-ewp-export-notice]' );
	const message = skippedMessage( t( 'skipped', 'Skipped (no PHP generator): %s' ), preview.skipped );
	notice.textContent = message;
	notice.hidden = message === '';
	placeBelowSubmit( form, panel );
	panel.hidden = false;
}

/**
 * Move the preview panel under the form's submit area, so a long export never
 * pushes "Perform Action" out of view.
 *
 * @param {HTMLFormElement} form  The import/export form.
 * @param {HTMLElement}     panel The preview panel.
 * @return {void}
 */
function placeBelowSubmit( form, panel ) {
	const submitArea = form.querySelector( '.awm-form-submit-area' );
	if ( submitArea && submitArea.nextElementSibling !== panel ) {
		submitArea.after( panel );
	}
}

/**
 * Hide the preview when the action changes away from Export (the moved panel is
 * no longer inside the field row that `show-when` toggles).
 *
 * @param {Event} event Change anywhere in the document.
 * @return {void}
 */
function onActionChange( event ) {
	if ( ! event.target.matches( `#${ FORM_ID } select[name="case"]` ) || event.target.value === 'export' ) {
		return;
	}
	const panel = event.target.form?.querySelector( '[data-ewp-export-preview]' );
	if ( panel ) {
		panel.hidden = true;
	}
}

/**
 * Export the selected content types and preview the result.
 *
 * @param {HTMLFormElement} form The import/export form.
 * @return {Promise<void>}
 */
async function runExport( form ) {
	const method = normalizeMethod( form.querySelector( '[name="method"]' )?.value );
	try {
		const response = await sendRequest( window.ewp_jsVanillaSerialize( form ), 'get', 'export' );
		showPreview( form, method, response );
	} catch ( error ) {
		const panel = form.querySelector( '[data-ewp-export-preview]' );
		if ( panel ) {
			showPreview( form, 'json', '' );
			const notice = panel.querySelector( '[data-ewp-export-notice]' );
			notice.textContent = error.message || t( 'error', 'An error occurred' );
			notice.hidden = false;
		}
	}
}

/**
 * Import the file chosen in the form, one request per content type.
 *
 * @param {HTMLFormElement} form The import/export form.
 * @return {void}
 */
function runImport( form ) {
	const logDiv = document.getElementById( 'import-message' );
	logDiv.textContent = '';
	const fileInput = form.querySelector( 'input[type="file"]' );
	if ( ! fileInput || fileInput.files.length === 0 ) {
		logDiv.textContent = t( 'noFile', 'Please upload a file.' );
		return;
	}
	const reader = new FileReader();
	reader.onload = ( event ) => importFile( form, logDiv, event.target.result );
	reader.readAsText( fileInput.files[ 0 ] );
}

/**
 * Post the content types of an export file to the import route.
 *
 * @param {HTMLFormElement} form    The import/export form.
 * @param {HTMLElement}     logDiv  Log container.
 * @param {string}          content File contents.
 * @return {Promise<void>}
 */
async function importFile( form, logDiv, content ) {
	let jsonData;
	try {
		jsonData = JSON.parse( content );
	} catch ( error ) {
		logDiv.textContent = t( 'invalidJson', 'Invalid JSON file.' );
		return;
	}
	const formData = window.ewp_jsVanillaSerialize( form, true );
	for ( const key of Object.keys( jsonData ) ) {
		if ( key === 'modified' || key === 'pagination' ) {
			continue;
		}
		const entries = Object.keys( jsonData[ key ] ).length;
		importLogLine( logDiv, [ 'Importing ', { b: entries }, ' entries for ', { b: key } ] );
		try {
			await sendRequest( { ...formData, content_type: key, content: jsonData[ key ], length: entries }, 'post', 'import' );
			importLogLine( logDiv, [ 'Successfully imported ', { b: entries }, ' entries for ', { b: key } ] );
		} catch ( error ) {
			importLogLine( logDiv, [ 'Error importing entries for ', { b: key }, ': ' + ( error.message || error ) ] );
		}
	}
	importLogLine( logDiv, [ 'All imports completed.' ] );
}

/**
 * Handle the form submit: export or import, depending on the selected action.
 *
 * @param {SubmitEvent} event Submit event.
 * @return {void}
 */
function onSubmit( event ) {
	const form = document.getElementById( FORM_ID );
	if ( form === null || event.target !== form ) {
		return;
	}
	event.preventDefault();
	const action = form.querySelector( 'select[name="case"]' ).value;
	if ( action === 'import' ) {
		runImport( form );
		return;
	}
	if ( action === 'export' ) {
		runExport( form );
		return;
	}
	sendRequest( window.ewp_jsVanillaSerialize( form ), 'get', action ).catch( () => {} );
}

/**
 * Download the previewed export as a file.
 *
 * @return {void}
 */
function downloadExport() {
	const url = window.URL.createObjectURL( new Blob( [ state.text ], { type: state.mime } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = exportFileName( state.extension );
	link.hidden = true;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
}

/**
 * Copy the previewed export to the clipboard.
 *
 * @param {HTMLElement} button The Copy button, its label shows the result.
 * @return {Promise<void>}
 */
async function copyExport( button ) {
	const label = button.textContent;
	try {
		await navigator.clipboard.writeText( state.text );
		button.textContent = t( 'copied', 'Copied' );
		window.setTimeout( () => {
			button.textContent = label;
		}, 1500 );
	} catch ( error ) {
		const code = button.closest( '[data-ewp-export-preview]' ).querySelector( '[data-ewp-export-code]' );
		window.getSelection().selectAllChildren( code );
	}
}

/**
 * Delegated clicks of the preview panel's buttons.
 *
 * @param {MouseEvent} event Click.
 * @return {void}
 */
function onClick( event ) {
	const copy = event.target.closest( '[data-ewp-export-copy]' );
	if ( copy ) {
		copyExport( copy );
		return;
	}
	if ( event.target.closest( '[data-ewp-export-download]' ) ) {
		downloadExport();
	}
}

document.addEventListener( 'submit', onSubmit );
document.addEventListener( 'click', onClick );
document.addEventListener( 'change', onActionChange );
