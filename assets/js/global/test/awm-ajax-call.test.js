/**
 * Unit tests for awm_ajax_call() response handling (`npm run test:js`).
 *
 * Regression for 1.14.2: the webpack bundle is strict mode, so attaching `element`
 * to a primitive (string) JSON response threw and the success callback never ran.
 */

/**
 * Install a fake XMLHttpRequest that answers every request with a fixed body.
 *
 * @param {string} body Raw response text.
 * @return {void}
 */
function mockXhr( body ) {
	global.XMLHttpRequest = function () {
		this.status = 200;
		this.readyState = 0;
		this.responseText = body;
		this.addEventListener = jest.fn();
		this.open = jest.fn();
		this.setRequestHeader = jest.fn();
		this.send = () => {
			this.readyState = 4;
			this.onreadystatechange();
		};
	};
}

describe( 'awm_ajax_call', () => {
	let errorCallback;

	beforeAll( () => {
		global.EWPDynamicAssetLoader = { log: jest.fn() };
		global.awmGlobals = { nonce: 'n' };
		require( '../awm-global-script.js' );
	} );

	beforeEach( () => {
		errorCallback = jest.fn();
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		console.error.mockRestore();
	} );

	test( 'passes a JSON string response to the callback untouched', () => {
		mockXhr( '"<div>html</div>"' );
		const callback = jest.fn();
		const element = document.createElement( 'div' );
		window.awm_ajax_call( { url: '/x', callback, errorCallback, element } );

		expect( errorCallback ).not.toHaveBeenCalled();
		expect( callback ).toHaveBeenCalledTimes( 1 );
		expect( callback.mock.calls[ 0 ][ 0 ] ).toBe( '<div>html</div>' );
		expect( callback.mock.calls[ 0 ][ 1 ] ).toEqual( expect.objectContaining( { url: '/x', element } ) );
	} );

	test( 'attaches element to an object response', () => {
		mockXhr( '{"ok":true}' );
		const callback = jest.fn();
		const element = document.createElement( 'div' );
		window.awm_ajax_call( { url: '/x', callback, errorCallback, element } );

		expect( errorCallback ).not.toHaveBeenCalled();
		expect( callback.mock.calls[ 0 ][ 0 ] ).toEqual( { ok: true, element } );
	} );
} );
