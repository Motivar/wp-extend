/**
 * Pure helpers of the Import/Export admin screen module (ewp-import-export.js).
 */

/** File extension and MIME type per export method. */
export const FORMATS = {
	json: { extension: 'json', mime: 'application/json' },
	php: { extension: 'php', mime: 'application/x-httpd-php' },
};

/**
 * Reduce the selected export method to a known one.
 *
 * @param {string} value Value of the method select.
 * @return {string} `php` or `json`.
 */
export function normalizeMethod( value ) {
	return value === 'php' ? 'php' : 'json';
}

/**
 * Turn the export REST answer into what the preview shows and downloads.
 *
 * JSON answers with a string (or an object when a filter changed it); PHP
 * answers `{ code, skipped }`.
 *
 * @param {string}        method   Export method (`json` or `php`).
 * @param {string|Object} response REST answer.
 * @return {{text: string, skipped: string[], extension: string, mime: string}} Preview data.
 */
export function exportPreview( method, response ) {
	const format = FORMATS[ normalizeMethod( method ) ];
	if ( response !== null && typeof response === 'object' && typeof response.code === 'string' ) {
		return {
			text: response.code,
			skipped: Array.isArray( response.skipped ) ? response.skipped.map( String ) : [],
			extension: format.extension,
			mime: format.mime,
		};
	}
	const text = typeof response === 'string' ? response : JSON.stringify( response, null, 2 );
	return { text, skipped: [], extension: format.extension, mime: format.mime };
}

/**
 * File name of a download, stamped with the time.
 *
 * @param {string} extension File extension without the dot.
 * @param {Date}   date      Timestamp (default: now).
 * @return {string} For example `content_export_2026-11-01T10-00-00-000Z.php`.
 */
export function exportFileName( extension, date = new Date() ) {
	return `content_export_${ date.toISOString().replace( /[:.]/g, '-' ) }.${ extension }`;
}

/**
 * Message that lists the content types a PHP export had no generator for.
 *
 * @param {string}   template Text with a `%s` placeholder.
 * @param {string[]} skipped  Skipped content types.
 * @return {string} The message, empty when nothing was skipped.
 */
export function skippedMessage( template, skipped ) {
	if ( ! Array.isArray( skipped ) || skipped.length === 0 ) {
		return '';
	}
	return String( template ).replace( '%s', skipped.join( ', ' ) );
}
