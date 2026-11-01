/**
 * Minimal zip support for the Import/Export screen: write an uncompressed
 * (STORE) archive of text files, and read archives whose entries are stored or
 * deflated (deflate through the browser's DecompressionStream), so a zip that
 * was re-packed by the OS still imports. No zip64, no encryption.
 *
 * @since 1.15.0
 */

const SIG_LOCAL = 0x04034b50;
const SIG_CENTRAL = 0x02014b50;
const SIG_END = 0x06054b50;
const FLAG_UTF8 = 0x0800;

let crcTable = null;

/**
 * CRC-32 (IEEE) of a byte array.
 *
 * @param {Uint8Array} bytes Data.
 * @return {number} Unsigned CRC-32.
 */
export function crc32( bytes ) {
	if ( crcTable === null ) {
		crcTable = new Uint32Array( 256 );
		for ( let n = 0; n < 256; n++ ) {
			let c = n;
			for ( let k = 0; k < 8; k++ ) {
				c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
			}
			crcTable[ n ] = c >>> 0;
		}
	}
	let crc = 0xffffffff;
	for ( let i = 0; i < bytes.length; i++ ) {
		crc = crcTable[ ( crc ^ bytes[ i ] ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

/**
 * MS-DOS time and date fields of a timestamp.
 *
 * @param {Date} date Timestamp.
 * @return {{time: number, date: number}} Packed values.
 */
function dosDateTime( date ) {
	return {
		time: ( date.getHours() << 11 ) | ( date.getMinutes() << 5 ) | Math.floor( date.getSeconds() / 2 ),
		date: ( ( Math.max( date.getFullYear(), 1980 ) - 1980 ) << 9 ) | ( ( date.getMonth() + 1 ) << 5 ) | date.getDate(),
	};
}

/**
 * Build an uncompressed zip from text files.
 *
 * @param {Object<string, string>} files Path inside the archive => UTF-8 text.
 * @param {Date}                   when  Modification time of every entry (default: now).
 * @return {Uint8Array} The archive bytes.
 */
export function createZip( files, when = new Date() ) {
	const encoder = new TextEncoder();
	const stamp = dosDateTime( when );
	const locals = [];
	const centrals = [];
	let offset = 0;

	Object.keys( files ).forEach( ( path ) => {
		const name = encoder.encode( path );
		const data = encoder.encode( String( files[ path ] ) );
		const crc = crc32( data );

		const local = new DataView( new ArrayBuffer( 30 ) );
		local.setUint32( 0, SIG_LOCAL, true );
		local.setUint16( 4, 20, true );
		local.setUint16( 6, FLAG_UTF8, true );
		local.setUint16( 8, 0, true );
		local.setUint16( 10, stamp.time, true );
		local.setUint16( 12, stamp.date, true );
		local.setUint32( 14, crc, true );
		local.setUint32( 18, data.length, true );
		local.setUint32( 22, data.length, true );
		local.setUint16( 26, name.length, true );
		local.setUint16( 28, 0, true );
		locals.push( new Uint8Array( local.buffer ), name, data );

		const central = new DataView( new ArrayBuffer( 46 ) );
		central.setUint32( 0, SIG_CENTRAL, true );
		central.setUint16( 4, 20, true );
		central.setUint16( 6, 20, true );
		central.setUint16( 8, FLAG_UTF8, true );
		central.setUint16( 10, 0, true );
		central.setUint16( 12, stamp.time, true );
		central.setUint16( 14, stamp.date, true );
		central.setUint32( 16, crc, true );
		central.setUint32( 20, data.length, true );
		central.setUint32( 24, data.length, true );
		central.setUint16( 28, name.length, true );
		central.setUint32( 42, offset, true );
		centrals.push( new Uint8Array( central.buffer ), name );

		offset += 30 + name.length + data.length;
	} );

	const centralSize = centrals.reduce( ( sum, part ) => sum + part.length, 0 );
	const end = new DataView( new ArrayBuffer( 22 ) );
	end.setUint32( 0, SIG_END, true );
	end.setUint16( 8, Object.keys( files ).length, true );
	end.setUint16( 10, Object.keys( files ).length, true );
	end.setUint32( 12, centralSize, true );
	end.setUint32( 16, offset, true );

	return concat( [ ...locals, ...centrals, new Uint8Array( end.buffer ) ] );
}

/**
 * Join byte arrays.
 *
 * @param {Uint8Array[]} parts Parts in order.
 * @return {Uint8Array} One array.
 */
function concat( parts ) {
	const out = new Uint8Array( parts.reduce( ( sum, part ) => sum + part.length, 0 ) );
	let at = 0;
	parts.forEach( ( part ) => {
		out.set( part, at );
		at += part.length;
	} );
	return out;
}

/**
 * Whether bytes start like a zip archive (`PK\3\4`).
 *
 * @param {Uint8Array} bytes File bytes.
 * @return {boolean} True for a zip.
 */
export function isZip( bytes ) {
	return bytes.length >= 4 && new DataView( bytes.buffer, bytes.byteOffset, 4 ).getUint32( 0, true ) === SIG_LOCAL;
}

/**
 * Inflate raw deflate data.
 *
 * @param {Uint8Array} data Compressed bytes.
 * @return {Promise<Uint8Array>} Inflated bytes.
 */
async function inflateRaw( data ) {
	if ( typeof DecompressionStream === 'undefined' ) {
		throw new Error( 'This browser cannot read compressed zip entries.' );
	}
	const stream = new Blob( [ data ] ).stream().pipeThrough( new DecompressionStream( 'deflate-raw' ) );
	return new Uint8Array( await new Response( stream ).arrayBuffer() );
}

/**
 * Read the text files of a zip archive.
 *
 * @param {Uint8Array} bytes Archive bytes.
 * @return {Promise<Object<string, string>>} Path => UTF-8 text; folders are skipped.
 */
export async function readZip( bytes ) {
	const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
	let end = -1;
	for ( let i = bytes.length - 22; i >= Math.max( 0, bytes.length - 22 - 0xffff ); i-- ) {
		if ( view.getUint32( i, true ) === SIG_END ) {
			end = i;
			break;
		}
	}
	if ( end < 0 ) {
		throw new Error( 'Not a zip archive.' );
	}

	const decoder = new TextDecoder();
	const files = {};
	let at = view.getUint32( end + 16, true );
	const count = view.getUint16( end + 10, true );
	for ( let i = 0; i < count; i++ ) {
		if ( view.getUint32( at, true ) !== SIG_CENTRAL ) {
			throw new Error( 'Corrupt zip archive.' );
		}
		const method = view.getUint16( at + 10, true );
		const compressedSize = view.getUint32( at + 20, true );
		const nameLength = view.getUint16( at + 28, true );
		const skip = nameLength + view.getUint16( at + 30, true ) + view.getUint16( at + 32, true );
		const local = view.getUint32( at + 42, true );
		const name = decoder.decode( bytes.subarray( at + 46, at + 46 + nameLength ) );
		at += 46 + skip;
		if ( name.endsWith( '/' ) ) {
			continue;
		}
		const start = local + 30 + view.getUint16( local + 26, true ) + view.getUint16( local + 28, true );
		if ( method !== 0 && method !== 8 ) {
			throw new Error( `Unsupported zip compression (method ${ method }) for ${ name }.` );
		}
		const raw = bytes.subarray( start, start + compressedSize );
		files[ name ] = decoder.decode( method === 8 ? await inflateRaw( raw ) : raw );
	}
	return files;
}
