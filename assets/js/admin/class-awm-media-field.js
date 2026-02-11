/**
 * AWMMediaField — Unified media field handler for single image and gallery.
 *
 * Features:
 * - Opens WordPress media library per-field (no shared frame bug)
 * - Pre-selects existing images when the frame opens
 * - Appends new images (gallery) or replaces (single image, max=1)
 * - Removes individual images
 * - Drag-to-reorder via jQuery UI Sortable (gallery mode only)
 * - Fully localised via awmMediaFieldData
 *
 * Loaded dynamically by the EWP Dynamic Asset Loader when a
 * .awm-media-field element is detected in the DOM.
 *
 * @package ExtendWP
 * @since   2.0.0
 */
class AWMMediaField {

    /* --------------------------------------------------------
     * Static properties
     * -------------------------------------------------------- */

    /** @type {string} Selector for field containers */
    static CONTAINER_SELECTOR = '.awm-media-field';

    /** @type {string} Selector for the add / select button */
    static BUTTON_SELECTOR = '.awm-media-field-button';

    /** @type {string} Selector for the image list */
    static LIST_SELECTOR = '.awm-gallery-images-list';

    /** @type {string} Selector for individual image items */
    static ITEM_SELECTOR = '.awm-gallery-image';

    /** @type {string} Selector for the remove link */
    static REMOVE_SELECTOR = '.awm-remove-image';

    /** @type {object} Localised strings (populated by wp_localize_script) */
    static strings = (typeof awmMediaFieldData !== 'undefined' && awmMediaFieldData)
        ? awmMediaFieldData
        : {
            selectImages: 'Select Images',
            selectImage: 'Select Image',
            useImages: 'Use selected images',
            useImage: 'Use selected image',
            addImages: 'Add Images',
            editGallery: 'Edit Gallery',
            remove: 'Remove',
            insertMedia: 'Insert media',
            removeMedia: 'Remove media',
        };

    /* --------------------------------------------------------
     * Constructor
     * -------------------------------------------------------- */

    /**
     * Initialise the handler on a single .awm-media-field container.
     *
     * @param {HTMLElement} container The .awm-media-field element.
     */
    constructor(container) {
        /** @type {HTMLElement} */
        this.container = container;

        /** @type {string} Meta / field identifier */
        this.fieldId = container.dataset.id || '';

        /** @type {number} 0 = unlimited (gallery), 1 = single image */
        this.maxImages = parseInt(container.dataset.max, 10) || 0;

        /** @type {boolean} */
        this.isSingle = (this.maxImages === 1);

        /** @type {HTMLUListElement} */
        this.list = container.querySelector(AWMMediaField.LIST_SELECTOR);

        /** @type {HTMLButtonElement} */
        this.button = container.querySelector(AWMMediaField.BUTTON_SELECTOR);

        /**
         * Allowed library types from data-library attribute.
         * Empty string = all types, 'image' = images only.
         * @type {string}
         */
        this.libraryType = (container.dataset.library || '').trim();

        /** @type {object|null} wp.media frame instance (lazy-created) */
        this._frame = null;

        this._bindEvents();
        this._initSortable();
    }

    /* --------------------------------------------------------
     * Event binding
     * -------------------------------------------------------- */

    /**
     * Attach click handlers for the add button and remove links.
     */
    _bindEvents() {
        /* Add / Select button */
        if (this.button) {
            this.button.addEventListener('click', this._onButtonClick.bind(this));
        }

        /* Remove links — delegated on the list */
        if (this.list) {
            this.list.addEventListener('click', this._onRemoveClick.bind(this));
        }
    }

    /* --------------------------------------------------------
     * wp.media frame
     * -------------------------------------------------------- */

    /**
     * Get or lazily create the wp.media frame for this field.
     *
     * @return {object} wp.media frame.
     */
    _getFrame() {
        if (this._frame) {
            return this._frame;
        }

        var strings = AWMMediaField.strings;
        var title = this.isSingle ? strings.selectImage : strings.selectImages;
        var buttonText = this.isSingle ? strings.useImage : strings.useImages;

        /* Build frame options */
        var frameOpts = {
            title: title,
            button: { text: buttonText },
            multiple: !this.isSingle
        };

        /* Apply library type filter only when explicitly set */
        if (this.libraryType) {
            frameOpts.library = { type: this.libraryType };
        }

        this._frame = wp.media(frameOpts);

        /* Pre-select existing images when frame opens */
        this._frame.on('open', this._onFrameOpen.bind(this));

        /* Handle selection */
        this._frame.on('select', this._onFrameSelect.bind(this));

        return this._frame;
    }

    /**
     * Button click — open the media frame.
     *
     * @param {Event} event Click event.
     */
    _onButtonClick(event) {
        event.preventDefault();
        this._getFrame().open();
    }

    /**
     * Frame open — pre-select already chosen images.
     */
    _onFrameOpen() {
        var selection = this._frame.state().get('selection');
        var existingIds = this._getExistingIds();

        /* Clear current selection */
        selection.reset([]);

        if (existingIds.length === 0) {
            return;
        }

        /* Fetch and add each existing attachment to the selection */
        existingIds.forEach(function (id) {
            var attachment = wp.media.attachment(id);
            attachment.fetch();
            selection.add(attachment);
        });
    }

    /**
     * Frame select — process chosen images.
     */
    _onFrameSelect() {
        var selection = this._frame.state().get('selection');

        if (this.isSingle) {
            this._handleSingleSelect(selection);
            return;
        }

        this._handleGallerySelect(selection);
    }

    /* --------------------------------------------------------
     * Selection handlers
     * -------------------------------------------------------- */

    /**
     * Handle selection in single-image mode (max = 1).
     * Replaces any existing image with the newly selected one.
     *
     * @param {object} selection Backbone selection collection.
     */
    _handleSingleSelect(selection) {
        var attachment = selection.first();

        if (!attachment) {
            return;
        }

        /* Clear existing */
        this.list.innerHTML = '';

        /* Append the single image */
        this._appendImage(attachment.id, this._getThumbnailUrl(attachment));
        this._updateButtonLabel();
    }

    /**
     * Handle selection in gallery mode (max = 0, unlimited).
     * Replaces the entire list with the current selection to preserve order.
     *
     * @param {object} selection Backbone selection collection.
     */
    _handleGallerySelect(selection) {
        var self = this;

        /* Clear and re-render to match selection order */
        this.list.innerHTML = '';

        selection.each(function (attachment) {
            self._appendImage(attachment.id, self._getThumbnailUrl(attachment));
        });

        this._updateButtonLabel();
        this._initSortable();
    }

    /* --------------------------------------------------------
     * Remove
     * -------------------------------------------------------- */

    /**
     * Delegated click handler for remove links.
     *
     * @param {Event} event Click event.
     */
    _onRemoveClick(event) {
        var target = event.target;

        if (!target.classList.contains('awm-remove-image')) {
            return;
        }

        event.preventDefault();

        var li = target.closest(AWMMediaField.ITEM_SELECTOR);

        if (li) {
            li.remove();
        }

        this._updateButtonLabel();
    }

    /* --------------------------------------------------------
     * DOM helpers
     * -------------------------------------------------------- */

    /**
     * Append an image item to the list.
     *
     * @param {number} id       Attachment ID.
     * @param {string} thumbUrl Thumbnail URL.
     */
    _appendImage(id, thumbUrl) {
        var inputName = this.isSingle ? this.fieldId : this.fieldId + '[]';
        var strings = AWMMediaField.strings;

        var li = document.createElement('li');
        li.className = 'awm-gallery-image';
        li.dataset.imageId = id;

        li.innerHTML =
            '<div class="awm-img-wrapper"><img src="' + thumbUrl + '" alt=""></div>' +
            '<a href="#" class="awm-remove-image">' + strings.remove + '</a>' +
            '<input type="hidden" name="' + inputName + '" value="' + id + '">';

        this.list.appendChild(li);
    }

    /**
     * Get a thumbnail URL from an attachment model.
     *
     * For images, returns the thumbnail size URL.
     * For non-image files (PDFs, zips, docs, etc.), returns the
     * WordPress-generated icon URL so a recognisable file-type
     * icon is displayed instead of a broken/raw file link.
     *
     * @param {object} attachment wp.media attachment model.
     * @return {string} URL.
     */
    _getThumbnailUrl(attachment) {
        var attrs = attachment.attributes || attachment.toJSON();
        var sizes = attrs.sizes;

        /* Image with thumbnail size */
        if (sizes && sizes.thumbnail && sizes.thumbnail.url) {
            return sizes.thumbnail.url;
        }

        /* Image without explicit thumbnail — use full URL */
        if (attrs.type === 'image' && attrs.url) {
            return attrs.url;
        }

        /* Non-image attachment — prefer the WP icon (e.g. document.png, spreadsheet.png) */
        if (attrs.icon) {
            return attrs.icon;
        }

        /* Ultimate fallback */
        return attrs.url || '';
    }

    /**
     * Collect currently displayed image IDs from the DOM.
     *
     * @return {number[]} Array of attachment IDs.
     */
    _getExistingIds() {
        var items = this.list.querySelectorAll(AWMMediaField.ITEM_SELECTOR);
        var ids = [];

        items.forEach(function (item) {
            var id = parseInt(item.dataset.imageId, 10);
            if (id) {
                ids.push(id);
            }
        });

        return ids;
    }

    /**
     * Update the button label based on current image count.
     */
    _updateButtonLabel() {
        if (!this.button) {
            return;
        }

        var strings = AWMMediaField.strings;
        var hasImages = this.list.querySelectorAll(AWMMediaField.ITEM_SELECTOR).length > 0;

        if (this.isSingle) {
            this.button.textContent = hasImages ? strings.insertMedia : strings.selectImage;
            return;
        }

        this.button.textContent = hasImages ? strings.addImages : strings.selectImages;
    }

    /* --------------------------------------------------------
     * Sortable (gallery mode)
     * -------------------------------------------------------- */

    /**
     * Initialise jQuery UI Sortable on the image list (gallery mode only).
     */
    _initSortable() {
        if (this.isSingle || !this.list) {
            return;
        }

        /* jQuery UI sortable requires jQuery wrapper */
        if (typeof jQuery === 'undefined' || !jQuery.fn.sortable) {
            return;
        }

        jQuery(this.list).sortable({
            placeholder: 'awm-gallery-placeholder',
            cursor: 'move',
            opacity: 0.7,
            tolerance: 'pointer',
            items: '> ' + AWMMediaField.ITEM_SELECTOR
        });
    }

    /* --------------------------------------------------------
     * Static initialiser
     * -------------------------------------------------------- */

    /**
     * Discover and initialise all .awm-media-field containers on the page.
     * Safe to call multiple times — already-initialised containers are skipped.
     */
    static init() {
        var containers = document.querySelectorAll(AWMMediaField.CONTAINER_SELECTOR);

        containers.forEach(function (container) {
            /* Skip if already initialised */
            if (container.dataset.awmInit) {
                return;
            }

            container.dataset.awmInit = '1';
            new AWMMediaField(container);
        });
    }
}

/* Auto-initialise when DOM is ready */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', AWMMediaField.init);
} else {
    AWMMediaField.init();
}
