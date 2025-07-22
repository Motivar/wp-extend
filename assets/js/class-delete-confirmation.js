/**
 * Delete Confirmation Handler Class
 * 
 * A reusable JavaScript class for handling delete confirmations in WordPress admin.
 * Provides confirmation dialogs for both individual delete actions and bulk delete operations.
 * 
 * @author Filox
 * @version 1.0.0
 * @since 1.0.0
 */
class AWMDeleteConfirmation {
    
    /**
     * Configuration object for the confirmation handler
     * @type {Object}
     * @static
     */
    static CONFIG = {
        SELECTORS: {
            DELETE_BUTTON: '.awm-delete-content-id',
            BULK_ACTION_SELECT: 'select[name="action"], select[name="action2"]',
            BULK_ACTION_FORM: '#posts-filter',
            APPLY_BUTTON: '#doaction, #doaction2'
        },
        ACTIONS: {
            DELETE: 'delete'
        }
    };

    /**
     * Get localized messages from WordPress localization
     * Falls back to English if localization is not available
     * 
     * @return {Object} Localized messages object
     * @static
     */
    static getLocalizedMessages() {
        // Check if WordPress localization is available
        if (typeof awmDeleteConfirmationL10n !== 'undefined') {
            return {
                SINGLE_DELETE: awmDeleteConfirmationL10n.singleDeleteMessage,
                BULK_DELETE: awmDeleteConfirmationL10n.bulkDeleteMessage,
                NO_ITEMS_SELECTED: awmDeleteConfirmationL10n.noItemsSelectedMessage
            };
        }
        
        // Fallback to English messages if localization is not available
        return {
            SINGLE_DELETE: 'Are you sure you want to delete this item? This action cannot be undone.',
            BULK_DELETE: 'Are you sure you want to delete the selected items? This action cannot be undone and will permanently remove all selected data.',
            NO_ITEMS_SELECTED: 'Please select at least one item to delete.'
        };
    }

    /**
     * Initialize the delete confirmation handler
     * 
     * @param {Object} options - Configuration options to override defaults
     */
    constructor(options = {}) {
        this.config = { ...AWMDeleteConfirmation.CONFIG, ...options };
        this.messages = AWMDeleteConfirmation.getLocalizedMessages();
        this.init();
    }

    /**
     * Initialize event listeners and setup
     * 
     * @private
     */
    init() {
        this.bindDeleteButtonEvents();
        this.bindBulkActionEvents();
    }

    /**
     * Bind click events to individual delete buttons
     * 
     * @private
     */
    bindDeleteButtonEvents() {
        const deleteButtons = document.querySelectorAll(this.config.SELECTORS.DELETE_BUTTON);
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', (event) => {
                this.handleDeleteButtonClick(event);
            });
        });
    }

    /**
     * Bind events to bulk action forms
     * 
     * @private
     */
    bindBulkActionEvents() {
        const applyButtons = document.querySelectorAll(this.config.SELECTORS.APPLY_BUTTON);
        
        applyButtons.forEach(button => {
            button.addEventListener('click', (event) => {
                this.handleBulkActionClick(event);
            });
        });
    }

    /**
     * Handle individual delete button clicks
     * 
     * @param {Event} event - The click event
     * @private
     */
    handleDeleteButtonClick(event) {
        const confirmed = this.showConfirmation(this.messages.SINGLE_DELETE);
        
        if (!confirmed) {
            event.preventDefault();
            event.stopPropagation();
            return false;
        }
        
        return true;
    }

    /**
     * Handle bulk action form submissions
     * 
     * @param {Event} event - The click event
     * @private
     */
    handleBulkActionClick(event) {
        const form = event.target.closest('form');
        if (!form) return true;

        const actionSelect = form.querySelector(this.config.SELECTORS.BULK_ACTION_SELECT);
        if (!actionSelect) return true;

        const selectedAction = actionSelect.value;
        
        // Only intercept delete actions
        if (selectedAction !== this.config.ACTIONS.DELETE) {
            return true;
        }

        // Check if any items are selected
        const selectedItems = this.getSelectedItems(form);
        if (selectedItems.length === 0) {
            this.showAlert(this.messages.NO_ITEMS_SELECTED);
            event.preventDefault();
            return false;
        }

        // Show confirmation for bulk delete
        const confirmed = this.showConfirmation(this.messages.BULK_DELETE);
        
        if (!confirmed) {
            event.preventDefault();
            event.stopPropagation();
            return false;
        }
        
        return true;
    }

    /**
     * Get selected items from checkboxes in the form
     * 
     * @param {HTMLElement} form - The form element
     * @return {Array} Array of selected item values
     * @private
     */
    getSelectedItems(form) {
        const checkboxes = form.querySelectorAll('input[type="checkbox"][name="id[]"]:checked');
        return Array.from(checkboxes).map(checkbox => checkbox.value);
    }

    /**
     * Show confirmation dialog
     * 
     * @param {string} message - The confirmation message
     * @return {boolean} True if confirmed, false otherwise
     * @private
     */
    showConfirmation(message) {
        return window.confirm(message);
    }

    /**
     * Show alert dialog
     * 
     * @param {string} message - The alert message
     * @private
     */
    showAlert(message) {
        window.alert(message);
    }

    /**
     * Reinitialize the confirmation handler
     * Useful when new delete buttons are added dynamically
     * 
     * @public
     */
    reinitialize() {
        this.init();
    }

    /**
     * Update configuration
     * 
     * @param {Object} newConfig - New configuration options
     * @public
     */
    updateConfig(newConfig) {
        this.config = { ...this.config, ...newConfig };
    }

    /**
     * Destroy the confirmation handler by removing all event listeners
     * 
     * @public
     */
    destroy() {
        const deleteButtons = document.querySelectorAll(this.config.SELECTORS.DELETE_BUTTON);
        const applyButtons = document.querySelectorAll(this.config.SELECTORS.APPLY_BUTTON);
        
        deleteButtons.forEach(button => {
            button.removeEventListener('click', this.handleDeleteButtonClick);
        });
        
        applyButtons.forEach(button => {
            button.removeEventListener('click', this.handleBulkActionClick);
        });
    }
}

/**
 * Auto-initialize delete confirmation when DOM is ready
 * Creates a global instance for easy access
 */
document.addEventListener('DOMContentLoaded', function() {
    // Create global instance
    window.awmDeleteConfirmation = new AWMDeleteConfirmation();
    
    // Make the class available globally for other developers
    window.AWMDeleteConfirmation = AWMDeleteConfirmation;
});
