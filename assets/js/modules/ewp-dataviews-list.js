/**
 * DataViews admin list for custom content types.
 *
 * Mounted on `.ewp-dataviews-list` (templates/admin-view/content-list.php),
 * loaded by the Dynamic Asset Loader (EWP_Content_List_UI::register_assets()).
 * Reads the configuration from `data-config` and talks to the type's REST
 * routes: GET `{restPath}/query` (view → rows, total, rendered legacy cells),
 * POST `{restPath}/status` and DELETE `{restPath}/delete`.
 *
 * Declared filters (since 1.12.0) live in DataViews' own filter panel: element
 * filters natively, date / number / text filters through the panel widget that
 * webpack swaps into the bundled DataViews (ewp-dataviews-list/filter-widget.js).
 * Links marked `data-ewp-dataviews-view-link="{type}"` follow the current view.
 * Only one filter popover is open at a time (since 1.13.0, ewp-dataviews-list/single-popover.js).
 */
import { createRoot, useState, useEffect, useMemo, useCallback, RawHTML } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { copy, notAllowed, pencil, published, tag, trash } from '@wordpress/icons';
// Includes the DataViews stylesheet (see the SCSS file for why it is imported there).
import '../../css/admin/sass/_ewp-dataviews-list.scss';
import {
	queryArgs,
	requestView,
	filterSpecs,
	isInputFilter,
	inputElements,
	viewLinkUrl,
	fieldValue,
	renderedCell,
	extraActions,
	displayValue,
	restoreView,
	fitViewFilters,
	statusIcon,
} from './ewp-dataviews-list/helpers';
import { installSinglePopover } from './ewp-dataviews-list/single-popover';

/** Icons of the status actions, by statusIcon() key. */
const STATUS_ICONS = { published, notAllowed, tag };

/**
 * Dev logging helper — delegates to EWPDynamicAssetLoader.log when available
 * (silent unless the loader's debug flag is on).
 *
 * @param {string} message Log message.
 * @param {*}      [data]  Optional payload.
 * @return {void}
 */
const ewpLog = ( message, data ) => {
	if ( typeof window.EWPDynamicAssetLoader !== 'undefined' && typeof window.EWPDynamicAssetLoader.log === 'function' ) {
		window.EWPDynamicAssetLoader.log( '[EWP DataViews] ' + message, data );
	}
};

/** Layouts offered by the list. */
const DEFAULT_LAYOUTS = { table: {}, list: {} };

/**
 * Read a saved view; storage may be unavailable (private windows, blocked site data).
 *
 * @param {string} key Storage key.
 * @return {Object|null} Saved view.
 */
function loadView( key ) {
	try {
		const raw = window.localStorage.getItem( key );
		return raw ? JSON.parse( raw ) : null;
	} catch ( error ) {
		return null;
	}
}

/**
 * Save the view (best effort).
 *
 * @param {string} key  Storage key.
 * @param {Object} view View.
 * @return {void}
 */
function saveView( key, view ) {
	try {
		window.localStorage.setItem( key, JSON.stringify( view ) );
	} catch ( error ) {
		ewpLog( 'View not saved', error );
	}
}

/** Words of the input filter chips. */
const CHIP_WORDS = { from: __( 'from', 'extend-wp' ), to: __( 'to', 'extend-wp' ) };

/**
 * DataViews field definitions from the PHP configuration.
 *
 * A field is filterable when it has `elements` (pre-1.12) or declares a `filter`
 * (select, multiselect, date, number, text; see helpers.js filterSpec()). Input
 * filters (date, number, text) get one element built from their current value
 * (inputElements()), so DataViews 4.15 shows them in its panel and labels the chip.
 *
 * @param {Object}   config  List configuration.
 * @param {Object}   specs   Filter specs by field id (filterSpecs()).
 * @param {Object[]} filters Current view filters.
 * @return {Object[]} Fields.
 */
function buildFields( config, specs, filters ) {
	return config.fields.map( ( field ) => {
		const definition = {
			id: field.id,
			label: field.label,
			type: field.type,
			enableSorting: !! field.enableSorting,
			enableHiding: field.enableHiding !== false,
			enableGlobalSearch: false,
			getValue: ( { item } ) => fieldValue( item, field ),
			render: ( { item } ) => renderField( item, field, config ),
		};
		const spec = specs[ field.id ];
		if ( ! spec ) {
			return definition;
		}
		if ( isInputFilter( spec ) ) {
			const current = ( filters || [] ).find( ( f ) => f.field === field.id );
			definition.elements = inputElements( spec, current ? current.value : undefined, CHIP_WORDS );
			definition.filterBy = { operators: [ 'is' ], isPrimary: spec.isPrimary };
			return definition;
		}
		definition.elements = spec.elements;
		definition.filterBy = { operators: spec.operators, isPrimary: spec.isPrimary };
		return definition;
	} );
}

/**
 * Point the links that follow this list's filters (`data-ewp-dataviews-view-link="{type}"`,
 * e.g. an export button) at the current view, and announce the view with the
 * `ewp-dataviews-list:view` event on the mount element.
 *
 * @param {Element} element Mount element.
 * @param {string}  type    Content type (config.type).
 * @param {Object}  request Request view (requestView()).
 * @return {void}
 */
function publishView( element, type, request ) {
	document.querySelectorAll( '[data-ewp-dataviews-view-link]' ).forEach( ( link ) => {
		if ( link.dataset.ewpDataviewsViewLink !== type ) {
			return;
		}
		if ( ! link.dataset.ewpDataviewsBaseHref ) {
			link.dataset.ewpDataviewsBaseHref = link.getAttribute( 'href' ) || '';
		}
		link.setAttribute( 'href', viewLinkUrl( link.dataset.ewpDataviewsBaseHref, request ) );
	} );
	if ( element ) {
		element.dispatchEvent( new window.CustomEvent( 'ewp-dataviews-list:view', { bubbles: true, detail: { type, view: request } } ) );
	}
}

/**
 * Cell of a row: the title links to the edit screen (plus filter-added row actions),
 * other columns show the legacy HTML cell when there is one, else the value.
 *
 * @param {Object} item   Row.
 * @param {Object} field  Field configuration.
 * @param {Object} config List configuration.
 * @return {Element} Cell.
 */
function renderField( item, field, config ) {
	if ( field.id === 'title' ) {
		const extras = extraActions( item );
		return (
			<div>
				<a className="ewp-dataviews-list__title" href={ config.editUrl + item.id }>
					{ item.title || sprintf( /* translators: %d: item id. */ __( '#%d (no title)', 'extend-wp' ), item.id ) }
				</a>
				{ extras.length > 0 && (
					<div className="ewp-dataviews-list__row-actions">
						{ extras.map( ( html, index ) => <RawHTML key={ index }>{ html }</RawHTML> ) }
					</div>
				) }
			</div>
		);
	}
	const html = field.html ? renderedCell( item, field ) : '';
	if ( html !== '' ) {
		return <RawHTML>{ html }</RawHTML>;
	}
	return displayValue( fieldValue( item, field ), field );
}

/**
 * Confirmation modal of the delete action.
 *
 * @param {Object}   props                   Props.
 * @param {Object[]} props.items             Selected rows.
 * @param {Function} props.closeModal        Close the modal.
 * @param {Function} props.onActionPerformed Called after the delete.
 * @param {Function} props.onDelete          Performs the delete.
 * @return {Element} Modal body.
 */
function DeleteModal( { items, closeModal, onActionPerformed, onDelete } ) {
	const [ busy, setBusy ] = useState( false );
	const confirm = async () => {
		setBusy( true );
		await onDelete( items );
		setBusy( false );
		closeModal();
		if ( onActionPerformed ) {
			onActionPerformed( items );
		}
	};
	return (
		<div>
			<p>
				{ sprintf(
					/* translators: %d: number of items. */
					_n( 'Permanently delete %d item? This cannot be undone.', 'Permanently delete %d items? This cannot be undone.', items.length, 'extend-wp' ),
					items.length
				) }
			</p>
			<div className="ewp-dataviews-list__modal-actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ busy }>
					{ __( 'Cancel', 'extend-wp' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ confirm } isBusy={ busy } disabled={ busy }>
					{ __( 'Delete', 'extend-wp' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * DataViews actions: edit, duplicate, one "set status" per status, delete; duplicate,
 * set status and delete follow the type's `surfaces` (canDuplicate, canSetStatus,
 * canDelete). Every
 * action has an icon: DataViews 4.15 lists a bulk action in the selection
 * footer only when it has one.
 *
 * @param {Object}   config   List configuration.
 * @param {Function} setState Status / delete runner: (kind, items, status?) => Promise.
 * @return {Object[]} Actions.
 */
function buildActions( config, setState ) {
	const actions = [
		{
			id: 'edit',
			label: __( 'Edit', 'extend-wp' ),
			icon: pencil,
			isPrimary: true,
			callback: ( items ) => {
				window.location.href = config.editUrl + items[ 0 ].id;
			},
		},
	];
	if ( config.canDuplicate !== false ) {
		actions.push( {
			id: 'duplicate',
			label: __( 'Duplicate', 'extend-wp' ),
			icon: copy,
			callback: ( items ) => {
				window.location.href = config.duplicateUrl + items[ 0 ].id;
			},
		} );
	}
	if ( config.canSetStatus ?? config.writable ) {
		config.statuses.forEach( ( status ) => {
			actions.push( {
				id: 'status-' + status.value,
				/* translators: %s: status label. */
				label: sprintf( __( 'Set status: %s', 'extend-wp' ), status.label ),
				icon: STATUS_ICONS[ statusIcon( status.value ) ],
				supportsBulk: true,
				isEligible: ( item ) => item.status !== status.value,
				callback: ( items ) => setState( 'status', items, status.value ),
			} );
		} );
	}
	if ( config.canDelete ) {
		actions.push( {
			id: 'delete',
			label: __( 'Delete', 'extend-wp' ),
			icon: trash,
			isDestructive: true,
			supportsBulk: true,
			hideModalHeader: false,
			modalHeader: __( 'Delete items', 'extend-wp' ),
			RenderModal: ( props ) => <DeleteModal { ...props } onDelete={ ( items ) => setState( 'delete', items ) } />,
		} );
	}
	return actions;
}

/**
 * The list.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.config  List configuration.
 * @param {Element} props.element Mount element.
 * @return {Element} List.
 */
function ContentList( { config, element } ) {
	const specs = useMemo( () => filterSpecs( config.fields ), [ config ] );
	const fieldIds = useMemo( () => config.fields.map( ( f ) => f.id ), [ config ] );
	const [ view, setView ] = useState( () => {
		const restored = restoreView( config.defaultView, loadView( config.storageKey ), fieldIds );
		// Saved / linked filters from before a filter became multi-select (since 1.13.0).
		return { ...restored, filters: fitViewFilters( restored.filters, specs ) };
	} );
	const fields = useMemo( () => buildFields( config, specs, view.filters ), [ config, specs, view.filters ] );
	const [ result, setResult ] = useState( { items: [], total: 0, total_pages: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		publishView( element, config.type, requestView( view, specs ) );
		apiFetch( { path: addQueryArgs( config.restPath + '/query', queryArgs( view, specs ) ) } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setResult( response );
				}
			} )
			.catch( ( error ) => {
				ewpLog( 'Query failed', error );
				if ( ! cancelled ) {
					setNotice( { status: 'error', message: error.message || __( 'The list could not be loaded.', 'extend-wp' ) } );
				}
			} )
			.finally( () => ! cancelled && setLoading( false ) );
		return () => {
			cancelled = true;
		};
	}, [ view, reload, config.restPath, config.type, specs, element ] );

	const onChangeView = useCallback( ( next ) => {
		setView( next );
		saveView( config.storageKey, { ...next, search: '', page: 1 } );
	}, [ config.storageKey ] );

	const runAction = useCallback( async ( kind, items, status ) => {
		const ids = items.map( ( item ) => item.id ).join( ',' );
		try {
			if ( kind === 'status' ) {
				const response = await apiFetch( { path: config.restPath + '/status', method: 'POST', data: { ids, status } } );
				/* translators: %d: number of items. */
				setNotice( { status: 'success', message: sprintf( _n( '%d item updated.', '%d items updated.', response.count, 'extend-wp' ), response.count ) } );
			} else {
				const response = await apiFetch( { path: addQueryArgs( config.restPath + '/delete', { ids } ), method: 'DELETE' } );
				/* translators: %d: number of items. */
				setNotice( { status: 'success', message: sprintf( _n( '%d item deleted.', '%d items deleted.', response.count, 'extend-wp' ), response.count ) } );
			}
		} catch ( error ) {
			ewpLog( 'Action failed', { kind, error } );
			setNotice( { status: 'error', message: error.message || __( 'The action failed.', 'extend-wp' ) } );
		}
		setReload( ( n ) => n + 1 );
	}, [ config.restPath ] );

	const actions = useMemo( () => buildActions( config, runAction ), [ config, runAction ] );

	return (
		<>
			{ notice && (
				<Notice className="ewp-dataviews-list__notice" status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			<DataViews
				data={ result.items || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				isLoading={ loading }
				paginationInfo={ { totalItems: result.total || 0, totalPages: result.total_pages || 0 } }
				defaultLayouts={ DEFAULT_LAYOUTS }
				getItemId={ ( item ) => String( item.id ) }
			/>
		</>
	);
}

/**
 * Mount every list on the page once.
 *
 * @return {void}
 */
function mount() {
	installSinglePopover( document );
	document.querySelectorAll( '.ewp-dataviews-list:not([data-mounted])' ).forEach( ( element ) => {
		let config;
		try {
			config = JSON.parse( element.dataset.config || '{}' );
		} catch ( error ) {
			ewpLog( 'Invalid configuration', error );
			return;
		}
		element.dataset.mounted = '1';
		element.textContent = '';
		createRoot( element ).render( <ContentList config={ config } element={ element } /> );
		ewpLog( 'Mounted', { type: config.type } );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
