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
