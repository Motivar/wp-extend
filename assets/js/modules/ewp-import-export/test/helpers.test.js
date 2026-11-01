/**
 * Unit tests for the Import/Export screen helpers (`npm run test:js`).
 */
import { normalizeMethod, exportPreview, exportFileName, skippedMessage } from '../helpers';

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
