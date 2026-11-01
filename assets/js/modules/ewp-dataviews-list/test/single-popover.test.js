/**
 * Unit tests for the single open filter popover (`npm run test:js`).
 *
 * @jest-environment jsdom
 */
import { chipsToClose, closeOtherPopovers, installSinglePopover, CHIP_SELECTOR, POPOVER_SELECTOR } from '../single-popover';

/**
 * Two filter chips (Status open, Product closed), the Status popover and a table cell.
 *
 * @return {Object} Elements.
 */
function setup() {
	document.body.innerHTML =
		'<div class="dataviews-filters__summary-chip" data-id="status" aria-expanded="true"><span>Status</span></div>' +
		'<div class="dataviews-filters__summary-chip" data-id="product" aria-expanded="false"><span>Product</span></div>' +
		'<div class="components-popover dataviews-filters__summary-popover"><input type="checkbox" /></div>' +
		'<table><tbody><tr><td>cell</td></tr></tbody></table>';
	const status = document.querySelector( '[data-id="status"]' );
	const product = document.querySelector( '[data-id="product"]' );
	// Toggle like DataViews' Dropdown: a click flips aria-expanded.
	[ status, product ].forEach( ( chip ) => chip.addEventListener( 'click', () => chip.setAttribute( 'aria-expanded', chip.getAttribute( 'aria-expanded' ) === 'true' ? 'false' : 'true' ) ) );
	return { status, product, option: document.querySelector( POPOVER_SELECTOR + ' input' ), cell: document.querySelector( 'td' ) };
}

describe( 'single filter popover', () => {
	it( 'closes the open popover when another chip is pressed', () => {
		const { status, product } = setup();
		expect( chipsToClose( product.firstChild, document ) ).toEqual( [ status ] );
		expect( closeOtherPopovers( { target: product.firstChild }, document ) ).toBe( 1 );
		expect( status.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		product.click();
		expect( document.querySelectorAll( CHIP_SELECTOR + '[aria-expanded="true"]' ) ).toHaveLength( 1 );
	} );

	it( 'closes the open popover on a press outside it', () => {
		const { status, cell } = setup();
		closeOtherPopovers( { target: cell }, document );
		expect( status.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'leaves presses inside the popover and on its own chip alone', () => {
		const { status, option } = setup();
		expect( chipsToClose( option, document ) ).toEqual( [] );
		expect( chipsToClose( status.firstChild, document ) ).toEqual( [] );
		expect( chipsToClose( null, document ) ).toEqual( [] );
		expect( status.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	} );

	it( 'installs one capture listener per document', () => {
		const { status, product } = setup();
		const doc = document.implementation.createHTMLDocument( 'other' );
		expect( installSinglePopover( document ) ).toBe( true );
		expect( installSinglePopover( document ) ).toBe( false );
		expect( installSinglePopover( doc ) ).toBe( true );
		product.dispatchEvent( new window.Event( 'pointerdown', { bubbles: true } ) );
		expect( status.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );
} );
