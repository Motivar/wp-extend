/**
 * Pure helpers of the DataViews content list (no DOM, no network), so the
 * view → request mapping and the field mapping can be reasoned about and
 * tested on their own.
 */

/** Keys of the normalised row that are read directly (everything else is meta or a rendered cell). */
export const ROW_KEYS = [ 'id', 'title', 'status', 'created', 'modified', 'user_id' ];

/** Row actions the list implements natively; other filter-added actions are shown as links. */
export const NATIVE_ACTIONS = [ 'edit', 'duplicate', 'delete' ];

/** Status values shown with the "published" icon; DISABLED_STATUSES get "not allowed"; anything else "tag". */
export const ACTIVE_STATUSES = [ 'enabled', 'active', 'publish', 'published' ];
export const DISABLED_STATUSES = [ 'disabled', 'inactive', 'draft' ];

/**
 * Icon key of a "Set status" action. DataViews 4.15 shows a bulk action in the
 * footer only when it has an icon, so every bulk action needs one.
 *
 * @param {string} status Status value.
 * @return {string} `published`, `notAllowed` or `tag`.
 */
export function statusIcon( status ) {
	if ( ACTIVE_STATUSES.includes( status ) ) {
		return 'published';
	}
	return DISABLED_STATUSES.includes( status ) ? 'notAllowed' : 'tag';
}

/**
 * Query arguments for `{restPath}/query` from a DataViews view.
 *
 * Only what the server reads is sent (search, filters with a value, sort,
 * page, perPage); layout and visible fields stay in the browser. Filters of
 * declared input filters (date, number, text; see filterSpec()) are
 * translated into the standard view operators the REST list understands
 * (`between`, `afterInc`, `beforeInc`, `greaterThanOrEqual`,
 * `lessThanOrEqual`, `contains`) by requestFilters().
 *
 * @param {Object} view    DataViews view.
 * @param {Object} [specs] Filter specs by field id (filterSpecs()); none = filters sent as they are.
 * @return {Object} Query arguments (`view`, `render`, `with_meta`).
 */
export function queryArgs( view, specs = {} ) {
	return { view: requestView( view, specs ), render: true, with_meta: true };
}

/**
 * The view the server reads (search, translated filters, sort, page, perPage).
 *
 * @param {Object} view    DataViews view.
 * @param {Object} [specs] Filter specs by field id.
 * @return {Object} Request view.
 */
export function requestView( view, specs = {} ) {
	const request = {
		search: view.search || '',
		filters: requestFilters( view.filters || [], specs ),
		page: view.page || 1,
		perPage: view.perPage || 20,
	};
	if ( view.sort && view.sort.field ) {
		request.sort = { field: view.sort.field, direction: view.sort.direction || 'desc' };
	}
	return request;
}

/**
 * Whether a filter value is set (DataViews keeps filters without a value while the user picks).
 *
 * @param {*} value Filter value.
 * @return {boolean} True when set.
 */
export function hasValue( value ) {
	return value !== undefined && value !== null && value !== '' && ! ( Array.isArray( value ) && value.length === 0 );
}

/**
 * Filters as the server reads them: empty ones dropped, input filters translated.
 *
 * @param {Object[]} filters View filters ({ field, operator, value }).
 * @param {Object}   specs   Filter specs by field id.
 * @return {Object[]} Request filters.
 */
export function requestFilters( filters, specs ) {
	const out = [];
	filters.forEach( ( filter ) => {
		if ( ! filter || ! hasValue( filter.value ) ) {
			return;
		}
		const spec = specs[ filter.field ];
		if ( ! spec || ! isInputFilter( spec ) ) {
			const fitted = spec ? fitElementFilter( filter, spec ) : filter;
			out.push( { field: fitted.field, operator: fitted.operator, value: fitted.value } );
			return;
		}
		out.push( ...inputConditions( spec, parseInputValue( filter.value ) ) );
	} );
	return out;
}

/** Filter types entered in the panel (the others pick from elements). */
export const INPUT_FILTER_TYPES = [ 'date', 'number', 'text' ];

/** Filter types picked from elements. */
export const ELEMENT_FILTER_TYPES = [ 'select', 'multiselect' ];

/** Default operators of the element filter types. */
export const ELEMENT_OPERATORS = { select: [ 'is', 'isNot' ], multiselect: [ 'isAny', 'isNone' ] };

/** Operators of a `select` declared `multiple` (since 1.13.0): one chip "is any: A, B". */
export const MULTIPLE_OPERATORS = [ 'isAny', 'isNone' ];

/** Single-value operator => its list counterpart, and back (fitElementFilter()). */
const TO_LIST = { is: 'isAny', isNot: 'isNone' };
const TO_SINGLE = { isAny: 'is', isNone: 'isNot' };

/** Element value standing for "no value yet" of an input filter. */
export const NO_VALUE = '__ewp_no_value';

/**
 * Normalised filter spec of a field from its PHP configuration.
 *
 * A field declares a filter with `filter: { type, elements?, operators?, multiple?, fields?, field?, isPrimary? }`
 * (`multiple`: a `select` picking several values with `isAny` / `isNone`, since 1.13.0)
 * (EWP_Content_List_UI::normalize_filter()); a field with `elements` and no `filter` keeps the
 * pre-1.12 behaviour (an `isAny` / `isNone` filter, or its `operators`).
 *
 * @param {Object} field Field configuration.
 * @return {Object|null} { type, elements, operators, fields, field, isPrimary, label } or null when not filterable.
 */
export function filterSpec( field ) {
	const declared = field.filter && typeof field.filter === 'object' ? field.filter : null;
	if ( ! declared ) {
		if ( ! field.elements || ! field.elements.length ) {
			return null;
		}
		return { type: 'multiselect', elements: field.elements, operators: field.operators || ELEMENT_OPERATORS.multiselect, fields: [], field: field.id, isPrimary: false, label: field.label };
	}
	const type = INPUT_FILTER_TYPES.concat( ELEMENT_FILTER_TYPES ).includes( declared.type ) ? declared.type : null;
	const elements = declared.elements || field.elements || [];
	if ( ! type || ( ELEMENT_FILTER_TYPES.includes( type ) && ! elements.length ) ) {
		return null;
	}
	return {
		type,
		elements: ELEMENT_FILTER_TYPES.includes( type ) ? elements : [],
		operators: declared.operators || field.operators || ( type === 'select' && declared.multiple ? MULTIPLE_OPERATORS : ELEMENT_OPERATORS[ type ] ) || [ 'is' ],
		fields: Array.isArray( declared.fields ) ? declared.fields : [],
		field: declared.field || field.id,
		isPrimary: !! declared.isPrimary,
		label: field.label,
	};
}

/**
 * Filter specs of every filterable field.
 *
 * @param {Object[]} fields Field configurations.
 * @return {Object} Field id => spec.
 */
export function filterSpecs( fields ) {
	const specs = {};
	fields.forEach( ( field ) => {
		const spec = filterSpec( field );
		if ( spec ) {
			specs[ field.id ] = spec;
		}
	} );
	return specs;
}

/**
 * Fit a stored element filter to its spec's operators (since 1.13.0), so a view saved or linked
 * before a filter became multi-select (`product is 12`) opens as `product isAny [12]`, and a list
 * value on a single-value filter keeps its first value. Other filters are returned unchanged.
 *
 * @param {Object} filter View filter ({ field, operator, value }).
 * @param {Object} spec   Filter spec (filterSpec()).
 * @return {Object} Filter.
 */
export function fitElementFilter( filter, spec ) {
	if ( ! filter || ! spec || isInputFilter( spec ) || spec.operators.includes( filter.operator ) ) {
		return filter;
	}
	const list = TO_LIST[ filter.operator ];
	if ( list && spec.operators.includes( list ) ) {
		return { ...filter, operator: list, value: hasValue( filter.value ) ? [].concat( filter.value ).map( String ) : undefined };
	}
	const single = TO_SINGLE[ filter.operator ];
	if ( single && spec.operators.includes( single ) ) {
		const first = Array.isArray( filter.value ) ? filter.value[ 0 ] : filter.value;
		return { ...filter, operator: single, value: hasValue( first ) ? String( first ) : undefined };
	}
	return filter;
}

/**
 * Fit every element filter of a view to its spec (fitElementFilter()).
 *
 * @param {Object[]} filters View filters.
 * @param {Object}   specs   Filter specs by field id.
 * @return {Object[]} Filters.
 */
export function fitViewFilters( filters, specs ) {
	return ( Array.isArray( filters ) ? filters : [] ).map( ( filter ) => ( filter && specs[ filter.field ] ? fitElementFilter( filter, specs[ filter.field ] ) : filter ) );
}

/**
 * Whether a spec is entered in the panel (date / number / text) rather than picked from elements.
 *
 * @param {Object} spec Filter spec.
 * @return {boolean} True for input filters.
 */
export function isInputFilter( spec ) {
	return !! spec && INPUT_FILTER_TYPES.includes( spec.type );
}

/**
 * Decode the stored value of an input filter (a JSON string: { from?, to?, text?, field? }).
 *
 * @param {*} value Stored value.
 * @return {Object} Parts; {} when empty or malformed.
 */
export function parseInputValue( value ) {
	if ( typeof value !== 'string' || value === '' || value === NO_VALUE ) {
		return {};
	}
	try {
		const parsed = JSON.parse( value );
		return parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ? parsed : {};
	} catch ( error ) {
		return {};
	}
}

/**
 * Encode the parts of an input filter as its stored value; '' when nothing is set.
 *
 * @param {Object} spec  Filter spec.
 * @param {Object} parts { from?, to?, text?, field? }.
 * @return {string} Stored value.
 */
export function encodeInputValue( spec, parts ) {
	const clean = {};
	const keys = spec.type === 'text' ? [ 'text' ] : [ 'from', 'to' ];
	keys.forEach( ( key ) => {
		const value = parts[ key ] === undefined || parts[ key ] === null ? '' : String( parts[ key ] ).trim();
		if ( value !== '' ) {
			clean[ key ] = value;
		}
	} );
	if ( Object.keys( clean ).length === 0 ) {
		return '';
	}
	if ( spec.fields.length ) {
		clean.field = targetField( spec, parts.field );
	}
	return JSON.stringify( clean );
}

/**
 * The server field an input filter applies to: the chosen one of `fields`, else the first, else the spec's field.
 *
 * @param {Object} spec   Filter spec.
 * @param {string} chosen Chosen field.
 * @return {string} Field id.
 */
export function targetField( spec, chosen ) {
	const allowed = spec.fields.map( ( option ) => String( option.value ) );
	if ( ! allowed.length ) {
		return spec.field;
	}
	return allowed.includes( chosen ) ? chosen : allowed[ 0 ];
}

/**
 * Standard view conditions of an input filter.
 *
 * @param {Object} spec  Filter spec.
 * @param {Object} parts Decoded value.
 * @return {Object[]} Conditions ({ field, operator, value }).
 */
export function inputConditions( spec, parts ) {
	const field = targetField( spec, parts.field );
	if ( spec.type === 'text' ) {
		return parts.text ? [ { field, operator: 'contains', value: String( parts.text ) } ] : [];
	}
	const number = spec.type === 'number';
	const from = parts.from !== undefined && parts.from !== '' ? ( number ? Number( parts.from ) : String( parts.from ) ) : null;
	const to = parts.to !== undefined && parts.to !== '' ? ( number ? Number( parts.to ) : String( parts.to ) ) : null;
	if ( ( number && from !== null && Number.isNaN( from ) ) || ( number && to !== null && Number.isNaN( to ) ) ) {
		return [];
	}
	if ( from !== null && to !== null ) {
		return [ { field, operator: 'between', value: [ from, to ] } ];
	}
	if ( from !== null ) {
		return [ { field, operator: number ? 'greaterThanOrEqual' : 'afterInc', value: from } ];
	}
	if ( to !== null ) {
		return [ { field, operator: number ? 'lessThanOrEqual' : 'beforeInc', value: to } ];
	}
	return [];
}

/**
 * Chip text of an input filter value, e.g. "2026-01-01 – 2026-01-31", "≥ 200", "Check-out: from 2026-01-01".
 *
 * @param {Object} spec  Filter spec.
 * @param {Object} parts Decoded value.
 * @param {Object} words Translated words: { from, to }.
 * @return {string} Text; '' when empty.
 */
export function inputLabel( spec, parts, words = { from: 'from', to: 'to' } ) {
	if ( spec.type === 'text' ) {
		return parts.text ? String( parts.text ) : '';
	}
	let text = '';
	if ( parts.from && parts.to ) {
		text = parts.from + ' – ' + parts.to;
	} else if ( parts.from ) {
		text = ( spec.type === 'number' ? '≥ ' : words.from + ' ' ) + parts.from;
	} else if ( parts.to ) {
		text = ( spec.type === 'number' ? '≤ ' : words.to + ' ' ) + parts.to;
	}
	if ( text === '' || spec.fields.length < 2 ) {
		return text;
	}
	const field = targetField( spec, parts.field );
	const option = spec.fields.find( ( o ) => String( o.value ) === field );
	return ( option ? option.label : field ) + ': ' + text;
}

/**
 * DataViews elements of an input filter: DataViews 4.15 shows a filter only for a field with
 * elements and labels the chip from the element matching the value, so an input filter gets one
 * element — its current value — carrying the spec (`ewpFilter`) for the panel widget.
 *
 * @param {Object} spec  Filter spec.
 * @param {*}      value Current stored value (undefined when not filtered).
 * @param {Object} words Translated words: { from, to }.
 * @return {Object[]} Elements.
 */
export function inputElements( spec, value, words ) {
	const label = inputLabel( spec, parseInputValue( value ), words );
	return [ { value: label !== '' ? value : NO_VALUE, label: label !== '' ? label : spec.label, ewpFilter: spec } ];
}

/**
 * Replace (or add) the value of one filter in a filter list.
 *
 * @param {Object[]} filters  View filters.
 * @param {string}   field    Field id.
 * @param {string}   operator Operator.
 * @param {*}        value    New value ('' or undefined keeps the filter without a value).
 * @return {Object[]} Filters.
 */
export function withFilterValue( filters, field, operator, value ) {
	const next = { field, operator, value: value === '' ? undefined : value };
	const list = Array.isArray( filters ) ? filters : [];
	return list.some( ( f ) => f.field === field ) ? list.map( ( f ) => ( f.field === field ? { ...f, value: next.value } : f ) ) : list.concat( [ next ] );
}

/**
 * URL of a link that follows the list's current filters (`data-ewp-dataviews-view-link`):
 * the base URL plus `view` = the request view as JSON, without paging.
 *
 * @param {string} base        Base URL.
 * @param {Object} requestView Request view (requestView()).
 * @return {string} URL.
 */
export function viewLinkUrl( base, requestView ) {
	const { page, perPage, ...view } = requestView; // eslint-disable-line no-unused-vars
	const hash = base.indexOf( '#' );
	const path = hash === -1 ? base : base.slice( 0, hash );
	const fragment = hash === -1 ? '' : base.slice( hash );
	const cleaned = path.replace( /([?&])view=[^&]*(&|$)/, ( match, lead, tail ) => ( tail ? lead : '' ) );
	return cleaned + ( cleaned.indexOf( '?' ) === -1 ? '?' : '&' ) + 'view=' + encodeURIComponent( JSON.stringify( view ) ) + fragment;
}

/**
 * Value of a field on a row: a row key, a meta value, or the text of a rendered cell.
 *
 * @param {Object} item  Normalised row (with `meta` and `rendered`).
 * @param {Object} field Field configuration from PHP.
 * @return {*} Value.
 */
export function fieldValue( item, field ) {
	if ( ROW_KEYS.includes( field.id ) ) {
		return item[ field.id ];
	}
	if ( item.meta && Object.prototype.hasOwnProperty.call( item.meta, field.id ) ) {
		return item.meta[ field.id ];
	}
	return stripTags( renderedCell( item, field ) );
}

/**
 * Legacy cell HTML of a row (`ewp_column_{type}_column_content_filter`), or ''.
 *
 * @param {Object} item  Normalised row.
 * @param {Object} field Field configuration.
 * @return {string} HTML.
 */
export function renderedCell( item, field ) {
	const cells = item.rendered && item.rendered.cells ? item.rendered.cells : {};
	const html = cells[ field.column || field.id ];
	return typeof html === 'string' ? html : '';
}

/**
 * Row actions added by `ewp_row_actions_{type}_filter` beyond the native ones.
 *
 * @param {Object} item Normalised row.
 * @return {string[]} Link HTML.
 */
export function extraActions( item ) {
	const actions = item.rendered && item.rendered.actions ? item.rendered.actions : {};
	return Object.keys( actions )
		.filter( ( key ) => ! NATIVE_ACTIONS.includes( key ) )
		.map( ( key ) => actions[ key ] );
}

/**
 * Plain text of an HTML fragment.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function stripTags( html ) {
	return String( html || '' ).replace( /<[^>]*>/g, '' ).trim();
}

/**
 * Display text of a value: element label when the field has elements, lists joined.
 *
 * @param {*}      value Value.
 * @param {Object} field Field configuration.
 * @return {string} Text.
 */
export function displayValue( value, field ) {
	const values = Array.isArray( value ) ? value : [ value ];
	const labels = values
		.filter( ( v ) => v !== undefined && v !== null && v !== '' && v !== false )
		.map( ( v ) => {
			const element = ( field.elements || [] ).find( ( e ) => e.value === String( v ) );
			return element ? element.label : String( v );
		} );
	return labels.join( ', ' );
}

/**
 * Restore a saved view over the default one, keeping only known keys and fields.
 *
 * @param {Object}      defaults Default view from PHP.
 * @param {Object|null} saved    View saved in the browser, if any.
 * @param {string[]}    fieldIds Known field ids.
 * @return {Object} View.
 */
export function restoreView( defaults, saved, fieldIds ) {
	if ( ! saved || typeof saved !== 'object' ) {
		return defaults;
	}
	const known = ( id ) => fieldIds.includes( id );
	// Filters / search set by the page (e.g. from the URL) win over the saved ones (since 1.12.0).
	const pageFilters = Array.isArray( defaults.filters ) && defaults.filters.length > 0;
	return {
		...defaults,
		type: saved.type || defaults.type,
		perPage: saved.perPage || defaults.perPage,
		sort: saved.sort && known( saved.sort.field ) ? saved.sort : defaults.sort,
		fields: Array.isArray( saved.fields ) ? saved.fields.filter( known ) : defaults.fields,
		filters: pageFilters ? defaults.filters : ( Array.isArray( saved.filters ) ? saved.filters.filter( ( f ) => f && known( f.field ) ) : [] ),
		layout: saved.layout || defaults.layout,
		page: 1,
		search: defaults.search || '',
	};
}
