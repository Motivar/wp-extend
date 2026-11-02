/**
 * Unit tests for the Import/Export zip helpers (`npm run test:js`).
 */
import { deflateRawSync } from 'zlib';
import { TextEncoder as NodeTextEncoder, TextDecoder as NodeTextDecoder } from 'util';
import { ReadableStream as NodeReadableStream, DecompressionStream as NodeDecompressionStream } from 'stream/web';
import { Blob as NodeBlob } from 'buffer';
import { crc32, createZip, isZip, readZip } from '../zip';

global.TextEncoder = global.TextEncoder || NodeTextEncoder;
global.TextDecoder = global.TextDecoder || NodeTextDecoder;

/**
 * UTF-8 bytes of a string.
 *
 * @param {string} text Text.
 * @return {Uint8Array} Bytes.
 */
const encode = ( text ) => new TextEncoder().encode( text );

describe( 'crc32', () => {
	it( 'matches the standard check value', () => {
		expect( crc32( encode( '123456789' ) ) ).toBe( 0xcbf43926 );
		expect( crc32( new Uint8Array( 0 ) ) ).toBe( 0 );
	} );
} );

describe( 'createZip / readZip', () => {
	it( 'round-trips UTF-8 paths and contents', async () => {
		const files = {
			'ewp-config/format.json': '{\n    "format": 2\n}\n',
			'ewp-config/ewp_fields/abc.json': '{"title":"Ελληνικά é"}\n',
		};
		const bytes = createZip( files, new Date( 2026, 10, 1, 12, 30, 10 ) );
		expect( isZip( bytes ) ).toBe( true );
		expect( await readZip( bytes ) ).toEqual( files );
	} );

	it( 'rejects bytes that are not an archive', async () => {
		expect( isZip( encode( '{"a":1}' ) ) ).toBe( false );
		await expect( readZip( encode( 'not a zip at all, definitely not' ) ) ).rejects.toThrow( 'Not a zip archive.' );
	} );

	it( 'reads deflated entries (archives re-packed by the OS)', async () => {
		const saved = { Blob: global.Blob, Response: global.Response, DecompressionStream: global.DecompressionStream };
		global.DecompressionStream = NodeDecompressionStream;
		global.Blob = NodeBlob;
		global.Response = class {
			constructor( stream ) {
				this.stream = stream;
			}
			async arrayBuffer() {
				const chunks = [];
				for await ( const chunk of this.stream ) {
					chunks.push( Buffer.from( chunk ) );
				}
				return new Uint8Array( Buffer.concat( chunks ) ).buffer;
			}
		};
		NodeBlob.prototype.stream = NodeBlob.prototype.stream || function () {
			return NodeReadableStream.from( [ this ] );
		};

		const text = '{"title":"deflated"}\n'.repeat( 20 );
		const stored = createZip( { 'ewp_fields/x.json': text } );
		const compressed = deflateRawSync( Buffer.from( text ) );
		const view = new DataView( stored.buffer );
		const nameLength = view.getUint16( 26, true );
		const local = stored.slice( 0, 30 + nameLength );
		const central = stored.slice( 30 + nameLength + text.length, stored.length - 22 );
		const end = stored.slice( stored.length - 22 );
		[ local, central ].forEach( ( header, i ) => {
			const v = new DataView( header.buffer );
			v.setUint16( i === 0 ? 8 : 10, 8, true );
			v.setUint32( i === 0 ? 18 : 20, compressed.length, true );
		} );
		const delta = text.length - compressed.length;
		const endView = new DataView( end.buffer );
		endView.setUint32( 16, endView.getUint32( 16, true ) - delta, true );
		const bytes = new Uint8Array( [ ...local, ...compressed, ...central, ...end ] );

		try {
			expect( await readZip( bytes ) ).toEqual( { 'ewp_fields/x.json': text } );
		} finally {
			Object.assign( global, saved );
		}
	} );
} );
