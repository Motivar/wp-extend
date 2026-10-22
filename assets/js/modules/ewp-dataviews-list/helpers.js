/**
 * Pure helpers of the DataViews content list (no DOM, no network), so the
 * view → request mapping and the field mapping can be reasoned about and
 * tested on their own.
 */

/** Keys of the normalised row that are read directly (everything else is meta or a rendered cell). */
export const ROW_KEYS = [ 'id', 'title', 'status', 'created', 'modified', 'user_id' ];

/** Row actions the list implements natively; other filter-added actions are shown as links. */
export const NATIVE_ACTIONS = [ 'edit', 'duplicate', 'delete' ];

/**
 * Query arguments for `{restPath}/query` from a DataViews view.
 *
 * Only what the server reads is sent (search, filters with a value, sort,
 * page, perPage); layout and visible fields stay in the browser.
 *
 * @param {Object} view DataViews view.
 * @return {Object} Query arguments (`view`, `render`, `with_meta`).
 */
export function queryArgs( view ) {
	const filters = ( view.filters || [] ).filter( ( filter ) => {
		const value = filter.value;
		return value !== undefined && value !== null && value !== '' && ! ( Array.isArray( value ) && value.length === 0 );
	} );
	const request = {
		search: view.search || '',
		filters,
		page: view.page || 1,
		perPage: view.perPage || 20,
	};
	if ( view.sort && view.sort.field ) {
		request.sort = { field: view.sort.field, direction: view.sort.direction || 'desc' };
	}
	return { view: request, render: true, with_meta: true };
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
	return {
		...defaults,
		type: saved.type || defaults.type,
		perPage: saved.perPage || defaults.perPage,
		sort: saved.sort && known( saved.sort.field ) ? saved.sort : defaults.sort,
		fields: Array.isArray( saved.fields ) ? saved.fields.filter( known ) : defaults.fields,
		filters: Array.isArray( saved.filters ) ? saved.filters.filter( ( f ) => f && known( f.field ) ) : [],
		layout: saved.layout || defaults.layout,
		page: 1,
		search: '',
	};
}
