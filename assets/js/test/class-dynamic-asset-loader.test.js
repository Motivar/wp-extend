/**
 * Unit tests for EWPDynamicAssetLoader skipping assets WordPress already printed (`npm run test:js`).
 *
 * Regression for 1.14.3: assets with a WP-printed tag were injected a second time and executed twice.
 */

require( '../class-dynamic-asset-loader.js' );

const Loader = window.EWPDynamicAssetLoader;

/**
 * Build a script asset config.
 *
 * @param {Object} extra Overrides.
 * @return {Object} Asset.
 */
function scriptAsset( extra = {} ) {
	return { handle: 'filox-custom-inputs', type: 'script', src: 'https://x.test/a/custom-inputs.min.js', version: '2', selector: 'body', ...extra };
}

/**
 * Build a style asset config.
 *
 * @param {Object} extra Overrides.
 * @return {Object} Asset.
 */
function styleAsset( extra = {} ) {
	return { handle: 'filox-style', type: 'style', src: 'https://x.test/a/style.css', version: '2', selector: 'body', ...extra };
}

/**
 * Create a loader and run loadAsset for the asset.
 *
 * @param {Object} asset Asset config.
 * @return {Object} Loader.
 */
function load( asset ) {
	const loader = new Loader( { assets: [] } );
	loader.loadAsset( asset );
	return loader;
}

/**
 * Wait for the loader's dependency promise chain.
 *
 * @return {Promise} Resolves after microtasks.
 */
const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );

describe( 'EWPDynamicAssetLoader existing-asset detection', () => {
	beforeEach( () => {
		document.head.innerHTML = '';
		document.body.innerHTML = '';
	} );

	test( 'script with WP -js id is not injected', async () => {
		document.body.innerHTML = '<script id="filox-custom-inputs-js" src="https://x.test/a/custom-inputs.min.js?ver=1"></script>';
		const loader = load( scriptAsset() );
		await flush();
		expect( document.querySelectorAll( 'script' ).length ).toBe( 1 );
		expect( loader.isAssetLoaded( scriptAsset() ) ).toBe( true );
	} );

	test( 'script with same src and different ver is not injected', async () => {
		document.body.innerHTML = '<script id="other-id" src="https://x.test/a/custom-inputs.min.js?ver=1"></script>';
		load( scriptAsset() );
		await flush();
		expect( document.querySelectorAll( 'script' ).length ).toBe( 1 );
	} );

	test( 'similar path is not treated as present', async () => {
		document.body.innerHTML = '<script id="other-id" src="https://x.test/a/custom-inputs.min.js.map"></script>';
		load( scriptAsset() );
		await flush();
		expect( document.querySelectorAll( 'script' ).length ).toBe( 2 );
	} );

	test( 'absent script is injected once', async () => {
		const loader = load( scriptAsset() );
		loader.loadAsset( scriptAsset() );
		await flush();
		expect( document.querySelectorAll( 'script#filox-custom-inputs' ).length ).toBe( 1 );
	} );

	test( 'present asset skips localize export and still dispatches the loaded event', () => {
		document.body.innerHTML = '<script id="filox-custom-inputs-js"></script>';
		const loader = new Loader( { assets: [] } );
		loader.exportLocalizeSettingsAsGlobals = jest.fn();
		loader.dispatchLoadEvent = jest.fn();
		loader.loadAsset( scriptAsset( { localize: { name: 'x', data: {} } } ) );
		expect( loader.exportLocalizeSettingsAsGlobals ).not.toHaveBeenCalled();
		expect( loader.dispatchLoadEvent ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'style with WP -css id is not injected', async () => {
		document.head.innerHTML = '<link id="filox-style-css" rel="stylesheet" href="https://x.test/a/style.css?ver=1">';
		load( styleAsset() );
		await flush();
		expect( document.querySelectorAll( 'link' ).length ).toBe( 1 );
	} );

	test( 'style with same href and different ver is not injected', async () => {
		document.head.innerHTML = '<link id="zzz" rel="stylesheet" href="https://x.test/a/style.css?ver=9">';
		load( styleAsset() );
		await flush();
		expect( document.querySelectorAll( 'link' ).length ).toBe( 1 );
	} );

	test( 'absent style is injected once', async () => {
		const loader = load( styleAsset() );
		loader.loadAsset( styleAsset() );
		await flush();
		expect( document.querySelectorAll( 'link#filox-style' ).length ).toBe( 1 );
	} );
} );
