/**
 * Filter popover content of the bundled DataViews 4.15.4, with input filters.
 *
 * webpack.config.js replaces DataViews' internal
 * `build-module/components/dataviews-filters/search-widget.js` with this file
 * (NormalModuleReplacementPlugin), so the native filter panel — the toolbar
 * funnel, "Add filter", the chips and "Reset" — also hosts the date, number
 * and text filters a content type declares (see helpers.js filterSpec()).
 * DataViews 4.15 only knows filters that pick from elements; newer versions
 * have range operators but need the @wordpress/components of WordPress 6.9+,
 * which the fleet cannot rely on (docs/content-list-dataviews.md).
 *
 * Element filters render DataViews' own widget unchanged. An input filter is
 * recognised by its single element carrying `ewpFilter` (inputElements()).
 *
 * Written with createElement, not JSX: webpack picks a module's loaders from
 * its original path (node_modules, which Babel skips), so this file is not
 * transpiled and must be plain modern JavaScript; for the same reason its own
 * imports resolve from DataViews' folder, hence the `@modules` alias.
 */
import { createElement as h, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';
// The original widget; the query string keeps the replacement plugin from matching it again.
import OriginalSearchWidget from '@ewp-dataviews-build/components/dataviews-filters/search-widget.js?ewp-original';
import { parseInputValue, encodeInputValue, targetField, withFilterValue } from '@modules/ewp-dataviews-list/helpers'; // An alias: the swapped module resolves imports from DataViews' folder.

/**
 * Spec of an input filter, from its element.
 *
 * @param {Object} filter DataViews normalised filter.
 * @return {Object|null} Spec, null for element filters.
 */
function inputSpec( filter ) {
	const element = filter && filter.elements && filter.elements.length === 1 ? filter.elements[ 0 ] : null;
	return element && element.ewpFilter ? element.ewpFilter : null;
}

/**
 * Labels of the two inputs of a range.
 *
 * @param {string} type `date` or `number`.
 * @return {string[]} [ from label, to label ].
 */
function rangeLabels( type ) {
	return type === 'number'
		? [ __( 'Minimum', 'extend-wp' ), __( 'Maximum', 'extend-wp' ) ]
		: [ __( 'From', 'extend-wp' ), __( 'To', 'extend-wp' ) ];
}

/**
 * Inputs of a date / number range or a text filter, applied with the button or Enter.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.filter       DataViews normalised filter.
 * @param {Object}   props.view         Current view.
 * @param {Function} props.onChangeView View setter.
 * @param {Object}   props.spec         Filter spec.
 * @return {Element} Widget.
 */
function InputFilterWidget( { filter, view, onChangeView, spec } ) {
	const current = ( view.filters || [] ).find( ( f ) => f.field === filter.field );
	const [ parts, setParts ] = useState( () => {
		const parsed = parseInputValue( current ? current.value : undefined );
		return { ...parsed, field: targetField( spec, parsed.field ) };
	} );
	const set = ( key ) => ( value ) => setParts( ( prev ) => ( { ...prev, [ key ]: value } ) );
	const apply = ( event ) => {
		if ( event ) {
			event.preventDefault();
		}
		onChangeView( {
			...view,
			page: 1,
			filters: withFilterValue( view.filters, filter.field, current ? current.operator : filter.operators[ 0 ], encodeInputValue( spec, parts ) ),
		} );
	};
	const inputType = spec.type === 'text' ? 'text' : spec.type;
	const [ fromLabel, toLabel ] = rangeLabels( spec.type );
	const control = ( props ) => h( TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, type: inputType, ...props } );
	const step = spec.type === 'number' ? 'any' : undefined;
	return h(
		'form',
		{ className: 'ewp-dataviews-filter', onSubmit: apply },
		spec.fields.length > 1 && h( SelectControl, {
			label: __( 'Applies to', 'extend-wp' ),
			value: parts.field,
			options: spec.fields.map( ( option ) => ( { value: String( option.value ), label: option.label } ) ),
			onChange: set( 'field' ),
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} ),
		spec.type === 'text'
			? control( { label: filter.name, value: parts.text || '', onChange: set( 'text' ) } )
			: h(
				'div',
				{ className: 'ewp-dataviews-filter__range' },
				control( { label: fromLabel, step, value: parts.from || '', onChange: set( 'from' ) } ),
				control( { label: toLabel, step, value: parts.to || '', onChange: set( 'to' ) } )
			),
		h( 'div', { className: 'ewp-dataviews-filter__actions' }, h( Button, { variant: 'primary', type: 'submit', size: 'compact' }, __( 'Apply', 'extend-wp' ) ) )
	);
}

/**
 * Popover content of a filter: the input widget for input filters, DataViews' list otherwise.
 *
 * @param {Object} props Props of DataViews' SearchWidget ({ filter, view, onChangeView }).
 * @return {Element} Widget.
 */
export default function SearchWidget( props ) {
	const spec = inputSpec( props.filter );
	if ( ! spec ) {
		return h( OriginalSearchWidget, props );
	}
	return h( InputFilterWidget, { ...props, spec } );
}
