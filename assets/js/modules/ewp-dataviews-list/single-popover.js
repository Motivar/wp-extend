/**
 * One filter popover at a time (since 1.13.0).
 *
 * DataViews 4.15 renders each filter chip as its own uncontrolled `Dropdown`. A popover
 * closes when focus leaves it, except when focus lands in another `[role="dialog"]`, which
 * is exactly what the next chip's popover is: opening "Product" while "Status" is open left
 * both popovers on screen, one over the other.
 *
 * This module listens to `pointerdown` (capture phase) on the document: a press outside the
 * open filter popover and outside its own chip closes that popover by toggling its chip,
 * before the press opens anything else. So opening one filter closes the others, and a click
 * anywhere outside closes it. Presses inside a popover (its options, inputs, the operator
 * select) and on the open chip itself (which toggles it natively) are left alone.
 */

/** A filter chip of the panel (its `aria-expanded` follows its popover). */
export const CHIP_SELECTOR = '.dataviews-filters__summary-chip';

/** The popover of a filter chip (`contentClassName` of its Dropdown). */
export const POPOVER_SELECTOR = '.dataviews-filters__summary-popover';

/**
 * Chips whose popover should close for a press on `target`: every open chip, unless the
 * press is inside a filter popover or on that chip itself.
 *
 * @param {EventTarget|null} target Pressed element.
 * @param {Document|Element} root   Where the chips live.
 * @return {Element[]} Chips to toggle closed.
 */
export function chipsToClose( target, root ) {
	if ( ! target || typeof target.closest !== 'function' || target.closest( POPOVER_SELECTOR ) ) {
		return [];
	}
	return Array.from( root.querySelectorAll( CHIP_SELECTOR + '[aria-expanded="true"]' ) ).filter( ( chip ) => ! chip.contains( target ) );
}

/**
 * Close the open filter popovers a press does not belong to.
 *
 * @param {Event}            event Pointer event.
 * @param {Document|Element} root  Where the chips live.
 * @return {number} Popovers closed.
 */
export function closeOtherPopovers( event, root ) {
	const chips = chipsToClose( event.target, root );
	chips.forEach( ( chip ) => chip.click() );
	return chips.length;
}

/** Documents the listener is installed on (one listener per document, whatever the number of lists). */
const installed = new WeakSet();

/**
 * Install the listener on a document (idempotent).
 *
 * @param {Document} doc Document.
 * @return {boolean} True when installed by this call.
 */
export function installSinglePopover( doc ) {
	if ( ! doc || installed.has( doc ) ) {
		return false;
	}
	installed.add( doc );
	doc.addEventListener( 'pointerdown', ( event ) => closeOtherPopovers( event, doc ), true );
	return true;
}
