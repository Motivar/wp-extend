/**
 * Unit tests for the DataViews list helpers (`npm run test:js`).
 */
import {
	queryArgs,
	fieldValue,
	renderedCell,
	extraActions,
	displayValue,
	restoreView,
	stripTags,
	statusIcon,
	filterSpec,
	filterSpecs,
	requestFilters,
	encodeInputValue,
	parseInputValue,
	inputConditions,
	inputLabel,
	inputElements,
	withFilterValue,
	viewLinkUrl,
	fitElementFilter,
	fitViewFilters,
	NO_VALUE,
} from '../helpers';

const statusField = { id: 'status', elements: [ { value: 'enabled', label: 'Enabled' }, { value: 'disabled', label: 'Disabled' } ] };

describe( 'queryArgs', () => {
	it( 'sends only search, filters with a value, sort and paging', () => {
		const args = queryArgs( {
			type: 'table',
			search: 'red',
			fields: [ 'status' ],
			layout: {},
			page: 2,
			perPage: 50,
			sort: { field: 'title', direction: 'asc' },
			filters: [
				{ field: 'status', operator: 'isAny', value: [ 'enabled' ] },
				{ field: 'colour', operator: 'isAny', value: [] },
				{ field: 'size', operator: 'is', value: undefined },
			],
		} );
		expect( args ).toEqual( {
			render: true,
			with_meta: true,
			view: {
				search: 'red',
				page: 2,
				perPage: 50,
				sort: { field: 'title', direction: 'asc' },
				filters: [ { field: 'status', operator: 'isAny', value: [ 'enabled' ] } ],
			},
		} );
	} );

	it( 'defaults page, perPage and omits an empty sort', () => {
		expect( queryArgs( {} ).view ).toEqual( { search: '', filters: [], page: 1, perPage: 20 } );
	} );
} );

describe( 'field values', () => {
	const item = {
		id: 7,
		title: 'Apple',
		status: 'enabled',
		meta: { colour: 'red', tags: [ 'a', 'b' ] },
		rendered: { cells: { colour: '<span>Red</span>', extra: '<b>42</b>' }, actions: { edit: '<a>Edit</a>', invoice: '<a>Invoice</a>' } },
	};

	it( 'reads row keys, then meta, then the rendered cell text', () => {
		expect( fieldValue( item, { id: 'title' } ) ).toBe( 'Apple' );
		expect( fieldValue( item, { id: 'colour' } ) ).toBe( 'red' );
		expect( fieldValue( item, { id: 'extra', column: 'extra' } ) ).toBe( '42' );
		expect( fieldValue( item, { id: 'missing' } ) ).toBe( '' );
	} );

	it( 'finds the rendered cell by legacy column key', () => {
		expect( renderedCell( item, { id: 'created', column: 'ewp_date' } ) ).toBe( '' );
		expect( renderedCell( item, { id: 'colour', column: 'colour' } ) ).toBe( '<span>Red</span>' );
		expect( renderedCell( { id: 1 }, { id: 'colour' } ) ).toBe( '' );
	} );

	it( 'keeps only filter-added row actions', () => {
		expect( extraActions( item ) ).toEqual( [ '<a>Invoice</a>' ] );
		expect( extraActions( { id: 1 } ) ).toEqual( [] );
	} );

	it( 'shows element labels and joins lists', () => {
		expect( displayValue( 'disabled', statusField ) ).toBe( 'Disabled' );
		expect( displayValue( [ 'a', 'b' ], { id: 'tags' } ) ).toBe( 'a, b' );
		expect( displayValue( null, { id: 'x' } ) ).toBe( '' );
		expect( stripTags( '<p>Hi <b>there</b></p>' ) ).toBe( 'Hi there' );
	} );
} );

describe( 'restoreView', () => {
	const defaults = { type: 'table', search: '', filters: [], page: 1, perPage: 20, sort: { field: 'created', direction: 'desc' }, fields: [ 'status' ], layout: {} };

	it( 'returns the defaults without a saved view', () => {
		expect( restoreView( defaults, null, [ 'title', 'status' ] ) ).toBe( defaults );
	} );

	it( 'drops unknown fields and sorts, and starts on page 1 without a search', () => {
		const view = restoreView( defaults, {
			type: 'list',
			perPage: 50,
			page: 4,
			search: 'old',
			sort: { field: 'gone', direction: 'asc' },
			fields: [ 'status', 'gone' ],
			filters: [ { field: 'status', operator: 'isAny', value: [ 'enabled' ] }, { field: 'gone', operator: 'is', value: 1 } ],
		}, [ 'title', 'status', 'created' ] );
		expect( view.type ).toBe( 'list' );
		expect( view.perPage ).toBe( 50 );
		expect( view.page ).toBe( 1 );
		expect( view.search ).toBe( '' );
		expect( view.sort ).toEqual( defaults.sort );
		expect( view.fields ).toEqual( [ 'status' ] );
		expect( view.filters ).toEqual( [ { field: 'status', operator: 'isAny', value: [ 'enabled' ] } ] );
	} );
} );

describe( 'statusIcon', () => {
	it( 'maps active, disabled and other statuses to an icon key', () => {
		expect( statusIcon( 'enabled' ) ).toBe( 'published' );
		expect( statusIcon( 'active' ) ).toBe( 'published' );
		expect( statusIcon( 'disabled' ) ).toBe( 'notAllowed' );
		expect( statusIcon( 'archived' ) ).toBe( 'tag' );
	} );
} );

describe( 'declared filters', () => {
	const fields = [
		{ id: 'title', label: 'Title' },
		statusField,
		{ id: 'dates', label: 'Dates', filter: { type: 'date', field: 'dates', fields: [ { value: 'check_in', label: 'Check-in' }, { value: 'created', label: 'Created' } ] } },
		{ id: 'total', label: 'Total', filter: { type: 'number', field: 'total', fields: [] } },
		{ id: 'note', label: 'Note', filter: { type: 'text', field: 'note_column', fields: [] } },
		{ id: 'source', label: 'Source', filter: { type: 'select', elements: [ { value: 'api', label: 'API' } ], operators: [ 'is' ] } },
		{ id: 'broken', label: 'Broken', filter: { type: 'select' } },
		{ id: 'odd', label: 'Odd', filter: { type: 'colour' } },
	];
	const specs = filterSpecs( fields );

	it( 'keeps the pre-1.12 element filters and reads declarations', () => {
		expect( Object.keys( specs ) ).toEqual( [ 'status', 'dates', 'total', 'note', 'source' ] );
		expect( specs.status ).toMatchObject( { type: 'multiselect', operators: [ 'isAny', 'isNone' ] } );
		expect( specs.source ).toMatchObject( { type: 'select', operators: [ 'is' ] } );
		expect( filterSpec( { id: 'x', elements: [ { value: 'a', label: 'A' } ], operators: [ 'isAll' ] } ).operators ).toEqual( [ 'isAll' ] );
		expect( filterSpec( { id: 'x', filter: { type: 'select' }, elements: [ { value: 'a', label: 'A' } ] } ).operators ).toEqual( [ 'is', 'isNot' ] );
	} );

	it( 'encodes and decodes input values', () => {
		expect( encodeInputValue( specs.total, { from: ' ', to: '' } ) ).toBe( '' );
		const value = encodeInputValue( specs.dates, { from: '2026-01-01', to: '2026-01-31', field: 'created' } );
		expect( parseInputValue( value ) ).toEqual( { from: '2026-01-01', to: '2026-01-31', field: 'created' } );
		expect( parseInputValue( encodeInputValue( specs.dates, { from: '2026-01-01', field: 'gone' } ) ).field ).toBe( 'check_in' );
		expect( parseInputValue( 'not json' ) ).toEqual( {} );
		expect( parseInputValue( NO_VALUE ) ).toEqual( {} );
		expect( parseInputValue( '[1]' ) ).toEqual( {} );
	} );

	it( 'translates ranges and text into standard operators', () => {
		expect( inputConditions( specs.dates, { from: '2026-01-01', to: '2026-01-31', field: 'created' } ) ).toEqual( [ { field: 'created', operator: 'between', value: [ '2026-01-01', '2026-01-31' ] } ] );
		expect( inputConditions( specs.dates, { from: '2026-01-01' } ) ).toEqual( [ { field: 'check_in', operator: 'afterInc', value: '2026-01-01' } ] );
		expect( inputConditions( specs.dates, { to: '2026-01-31' } ) ).toEqual( [ { field: 'check_in', operator: 'beforeInc', value: '2026-01-31' } ] );
		expect( inputConditions( specs.total, { from: '200', to: '450.5' } ) ).toEqual( [ { field: 'total', operator: 'between', value: [ 200, 450.5 ] } ] );
		expect( inputConditions( specs.total, { from: '200' } ) ).toEqual( [ { field: 'total', operator: 'greaterThanOrEqual', value: 200 } ] );
		expect( inputConditions( specs.total, { to: '9' } ) ).toEqual( [ { field: 'total', operator: 'lessThanOrEqual', value: 9 } ] );
		expect( inputConditions( specs.total, { from: 'abc' } ) ).toEqual( [] );
		expect( inputConditions( specs.note, { text: 'late' } ) ).toEqual( [ { field: 'note_column', operator: 'contains', value: 'late' } ] );
		expect( inputConditions( specs.note, {} ) ).toEqual( [] );
	} );

	it( 'sends element filters as they are and input filters translated', () => {
		const filters = [
			{ field: 'status', operator: 'isAny', value: [ 'enabled' ] },
			{ field: 'dates', operator: 'is', value: encodeInputValue( specs.dates, { from: '2026-01-01', to: '2026-01-31' } ) },
			{ field: 'total', operator: 'is', value: undefined },
			{ field: 'source', operator: 'is', value: 'api' },
		];
		expect( requestFilters( filters, specs ) ).toEqual( [
			{ field: 'status', operator: 'isAny', value: [ 'enabled' ] },
			{ field: 'check_in', operator: 'between', value: [ '2026-01-01', '2026-01-31' ] },
			{ field: 'source', operator: 'is', value: 'api' },
		] );
		expect( queryArgs( { filters }, specs ).view.filters ).toHaveLength( 3 );
	} );

	it( 'labels chips from the value', () => {
		expect( inputLabel( specs.dates, { from: '2026-01-01', to: '2026-01-31', field: 'created' } ) ).toBe( 'Created: 2026-01-01 – 2026-01-31' );
		expect( inputLabel( specs.total, { from: '200' } ) ).toBe( '≥ 200' );
		expect( inputLabel( specs.total, { to: '9' } ) ).toBe( '≤ 9' );
		expect( inputLabel( specs.note, { text: 'late' } ) ).toBe( 'late' );
		expect( inputLabel( specs.total, {} ) ).toBe( '' );
		const value = encodeInputValue( specs.total, { from: '1', to: '2' } );
		expect( inputElements( specs.total, value ) ).toEqual( [ { value, label: '1 – 2', ewpFilter: specs.total } ] );
		expect( inputElements( specs.total, undefined )[ 0 ] ).toMatchObject( { value: NO_VALUE, label: 'Total' } );
	} );

	it( 'replaces or adds a filter value', () => {
		const list = [ { field: 'a', operator: 'is', value: '1' } ];
		expect( withFilterValue( list, 'a', 'is', '2' ) ).toEqual( [ { field: 'a', operator: 'is', value: '2' } ] );
		expect( withFilterValue( list, 'a', 'is', '' ) ).toEqual( [ { field: 'a', operator: 'is', value: undefined } ] );
		expect( withFilterValue( undefined, 'b', 'is', '3' ) ).toEqual( [ { field: 'b', operator: 'is', value: '3' } ] );
	} );

	it( 'builds view links without paging and replaces an earlier view', () => {
		const view = { search: '', filters: [ { field: 'total', operator: 'between', value: [ 1, 2 ] } ], page: 3, perPage: 50 };
		const url = viewLinkUrl( 'https://x.test/wp-admin/admin-post.php?action=export&_wpnonce=abc', view );
		const query = new URL( url ).searchParams;
		expect( query.get( 'action' ) ).toBe( 'export' );
		expect( JSON.parse( query.get( 'view' ) ) ).toEqual( { search: '', filters: view.filters } );
		expect( viewLinkUrl( url, { search: 'x', filters: [] } ).match( /view=/g ) ).toHaveLength( 1 );
		expect( viewLinkUrl( '/export#top', { search: '' } ) ).toBe( '/export?view=' + encodeURIComponent( '{"search":""}' ) + '#top' );
	} );
} );

describe( 'restoreView with page filters', () => {
	it( 'keeps filters and search set by the page over the saved ones', () => {
		const defaults = { type: 'table', search: 'B-1', filters: [ { field: 'status', operator: 'isAny', value: [ 'enabled' ] } ], page: 1, perPage: 20, sort: { field: 'created', direction: 'desc' }, fields: [], layout: {} };
		const view = restoreView( defaults, { filters: [ { field: 'status', operator: 'isAny', value: [ 'disabled' ] } ] }, [ 'status' ] );
		expect( view.filters ).toEqual( defaults.filters );
		expect( view.search ).toBe( 'B-1' );
	} );
} );

describe( 'multi-select filters (1.13.0)', () => {
	const elements = [ { value: '11', label: 'Villa A' }, { value: '12', label: 'Villa B' } ];
	const multiple = filterSpec( { id: 'product', label: 'Product', filter: { type: 'select', multiple: true, elements } } );
	const single = filterSpec( { id: 'venue', label: 'Venue', filter: { type: 'select', elements } } );

	it( 'gives a `multiple` select the isAny / isNone operators', () => {
		expect( multiple.operators ).toEqual( [ 'isAny', 'isNone' ] );
		expect( single.operators ).toEqual( [ 'is', 'isNot' ] );
		expect( filterSpec( { id: 'p', filter: { type: 'select', multiple: true, operators: [ 'isNone' ], elements } } ).operators ).toEqual( [ 'isNone' ] );
	} );

	it( 'sends several values as one isAny / isNone condition', () => {
		const specs = { product: multiple };
		expect( requestFilters( [ { field: 'product', operator: 'isAny', value: [ '11', '12' ] } ], specs ) ).toEqual( [ { field: 'product', operator: 'isAny', value: [ '11', '12' ] } ] );
		expect( requestFilters( [ { field: 'product', operator: 'isNone', value: [ '12' ] } ], specs ) ).toEqual( [ { field: 'product', operator: 'isNone', value: [ '12' ] } ] );
	} );

	it( 'turns a saved single value into a list (old views and links keep working)', () => {
		expect( fitElementFilter( { field: 'product', operator: 'is', value: '12' }, multiple ) ).toEqual( { field: 'product', operator: 'isAny', value: [ '12' ] } );
		expect( fitElementFilter( { field: 'product', operator: 'isNot', value: 12 }, multiple ) ).toEqual( { field: 'product', operator: 'isNone', value: [ '12' ] } );
		expect( fitElementFilter( { field: 'product', operator: 'is', value: undefined }, multiple ) ).toEqual( { field: 'product', operator: 'isAny', value: undefined } );
		expect( requestFilters( [ { field: 'product', operator: 'is', value: '12' } ], { product: multiple } ) ).toEqual( [ { field: 'product', operator: 'isAny', value: [ '12' ] } ] );
	} );

	it( 'keeps the first value when a list reaches a single-value filter', () => {
		expect( fitElementFilter( { field: 'venue', operator: 'isAny', value: [ '11', '12' ] }, single ) ).toEqual( { field: 'venue', operator: 'is', value: '11' } );
	} );

	it( 'leaves matching, input and unknown filters alone', () => {
		const ok = { field: 'product', operator: 'isAny', value: [ '11' ] };
		expect( fitElementFilter( ok, multiple ) ).toBe( ok );
		const range = { field: 'total', operator: 'is', value: '{"from":"1"}' };
		expect( fitElementFilter( range, filterSpec( { id: 'total', label: 'Total', filter: { type: 'number' } } ) ) ).toBe( range );
		expect( fitViewFilters( [ ok, { field: 'other', operator: 'is', value: 'x' } ], { product: multiple } ) ).toEqual( [ ok, { field: 'other', operator: 'is', value: 'x' } ] );
		expect( fitViewFilters( undefined, {} ) ).toEqual( [] );
	} );
} );

describe( 'bundled DataViews', () => {
	it( 'is the version filter-widget.js replaces the widget of', () => {
		const fs = require( 'fs' );
		const path = require( 'path' );
		const root = path.resolve( __dirname, '../../../../../node_modules/@wordpress/dataviews' );
		expect( JSON.parse( fs.readFileSync( path.join( root, 'package.json' ), 'utf8' ) ).version ).toBe( '4.15.4' );
		expect( fs.existsSync( path.join( root, 'build-module/components/dataviews-filters/search-widget.js' ) ) ).toBe( true );
	} );
} );
