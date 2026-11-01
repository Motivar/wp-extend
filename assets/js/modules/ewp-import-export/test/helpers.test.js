/**
 * Unit tests for the Import/Export screen helpers (`npm run test:js`).
 */
import { normalizeMethod, exportPreview, exportFileName, skippedMessage, rowsFromFiles } from '../helpers';

describe( 'normalizeMethod', () => {
	it( 'keeps php and falls back to json', () => {
		expect( normalizeMethod( 'php' ) ).toBe( 'php' );
		expect( normalizeMethod( 'json' ) ).toBe( 'json' );
		expect( normalizeMethod( 'xml' ) ).toBe( 'json' );
		expect( normalizeMethod( undefined ) ).toBe( 'json' );
	} );
} );

describe( 'exportPreview', () => {
	it( 'uses the code and skipped list of a PHP answer', () => {
		const out = exportPreview( 'php', { code: '<?php echo 1;', skipped: [ 'a', 'b' ] } );
		expect( out ).toEqual( { text: '<?php echo 1;', skipped: [ 'a', 'b' ], extension: 'php', mime: 'application/x-httpd-php' } );
	} );

	it( 'passes a JSON string through', () => {
		const out = exportPreview( 'json', '{"a":1}' );
		expect( out.text ).toBe( '{"a":1}' );
		expect( out.extension ).toBe( 'json' );
		expect( out.skipped ).toEqual( [] );
	} );

	it( 'pretty prints a JSON object', () => {
		expect( exportPreview( 'json', { a: 1 } ).text ).toBe( '{\n  "a": 1\n}' );
	} );
} );

describe( 'exportFileName', () => {
	it( 'stamps the name with the date', () => {
		expect( exportFileName( 'php', new Date( '2026-11-01T10:00:00.000Z' ) ) ).toBe( 'content_export_2026-11-01T10-00-00-000Z.php' );
	} );
} );

describe( 'skippedMessage', () => {
	it( 'lists skipped types', () => {
		expect( skippedMessage( 'Skipped: %s', [ 'x', 'y' ] ) ).toBe( 'Skipped: x, y' );
	} );

	it( 'is empty without skipped types', () => {
		expect( skippedMessage( 'Skipped: %s', [] ) ).toBe( '' );
	} );
} );

describe( 'per object export', () => {
	const files = {
		'ewp-config/format.json': '{\n    "format": 2\n}\n',
		'ewp-config/ewp_fields/b.json': '{"hash":"b"}\n',
		'ewp-config/ewp_fields/a.json': '{"hash":"a"}\n',
		'ewp_search/c.json': '{"hash":"c"}\n',
		'ewp-config/ewp_fields/broken.json': '{nope',
		'ewp-config/ewp_fields/list.json': '[1,2]',
		'readme.txt': 'ignored',
	};

	it( 'previews every file under its path and downloads a zip', () => {
		const out = exportPreview( 'json', { files: { 'ewp-config/format.json': '{"format":2}\n' }, types: {} } );
		expect( out.text ).toBe( '// ewp-config/format.json\n{"format":2}\n' );
		expect( out.extension ).toBe( 'zip' );
		expect( out.mime ).toBe( 'application/zip' );
		expect( out.files ).toEqual( { 'ewp-config/format.json': '{"format":2}\n' } );
	} );

	it( 'groups row files by their folder and skips the marker and invalid files', () => {
		const { rows, invalid } = rowsFromFiles( files );
		expect( rows ).toEqual( { ewp_fields: [ { hash: 'a' }, { hash: 'b' } ], ewp_search: [ { hash: 'c' } ] } );
		expect( invalid ).toEqual( [ 'ewp-config/ewp_fields/broken.json', 'ewp-config/ewp_fields/list.json' ] );
	} );
} );
