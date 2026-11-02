/**
 * Pure helpers of the Import/Export admin screen module (ewp-import-export.js).
 */

/** File extension and MIME type per export method. */
export const FORMATS = {
	json: { extension: 'json', mime: 'application/json' },
	php: { extension: 'php', mime: 'application/x-httpd-php' },
	zip: { extension: 'zip', mime: 'application/zip' },
};

/** Marker file of the per object layout; never imported as a row. */
export const FORMAT_FILE = 'format.json';

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
 * JSON answers with a string (or an object when a filter changed it); the per
 * object JSON layout answers `{ files, types }` (downloaded as a zip); PHP
 * answers `{ code, skipped }`.
 *
 * @param {string}        method   Export method (`json` or `php`).
 * @param {string|Object} response REST answer.
 * @return {{text: string, skipped: string[], extension: string, mime: string, files?: Object<string, string>}} Preview data.
 */
export function exportPreview( method, response ) {
	if ( response !== null && typeof response === 'object' && response.files && typeof response.files === 'object' ) {
		return { text: filesPreview( response.files ), skipped: [], extension: FORMATS.zip.extension, mime: FORMATS.zip.mime, files: response.files };
	}
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

/**
 * Preview text of a per object export: every file under a header line with its path.
 *
 * @param {Object<string, string>} files Path => contents.
 * @return {string} The listing.
 */
export function filesPreview( files ) {
	return Object.keys( files )
		.map( ( path ) => `// ${ path }\n${ String( files[ path ] ).replace( /\n$/, '' ) }` )
		.join( '\n\n' ) + '\n';
}

/**
 * Group the per object files of an archive into rows by content type.
 *
 * A row file is any `*.json` whose parent folder names the content type
 * (`ewp-config/{type}/{hash}.json`, also without the `ewp-config/` prefix);
 * the marker file and anything else are ignored.
 *
 * @param {Object<string, string>} files Path => JSON text.
 * @return {{rows: Object<string, Object[]>, invalid: string[]}} Rows by type, and the paths that were not valid JSON objects.
 */
export function rowsFromFiles( files ) {
	const rows = {};
	const invalid = [];
	Object.keys( files ).sort().forEach( ( path ) => {
		const parts = path.split( '/' ).filter( Boolean );
		if ( parts.length < 2 || ! parts[ parts.length - 1 ].endsWith( '.json' ) || parts[ parts.length - 1 ] === FORMAT_FILE ) {
			return;
		}
		const type = parts[ parts.length - 2 ];
		if ( ! /^[A-Za-z0-9_-]+$/.test( type ) || type === 'ewp-config' ) {
			return;
		}
		let row;
		try {
			row = JSON.parse( files[ path ] );
		} catch ( error ) {
			invalid.push( path );
			return;
		}
		if ( row === null || typeof row !== 'object' || Array.isArray( row ) ) {
			invalid.push( path );
			return;
		}
		( rows[ type ] = rows[ type ] || [] ).push( row );
	} );
	return { rows, invalid };
}
