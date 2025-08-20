/**
 * Dynamic Block Events Handler
 * 
 * Handles dispatching ewp_dynamic_block_on_change events in admin area
 * to ensure JavaScript behaviors work properly for dynamic blocks
 * 
 * @class AWMDynamicBlockEvents
 * @author Nikolaos Giannopoulos
 * @version 1.0.0
 */
class AWMDynamicBlockEvents {
    
    /**
     * Initialize the dynamic block events handler
     * 
     * @static
     * @return {void}
     */
    static init() {
        // Initialize on DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.scanAndDispatchEvents();
                this.setupObserver();
            });
        } else {
            this.scanAndDispatchEvents();
            this.setupObserver();
        }
    }
    
    /**
     * Scan for dynamic blocks and dispatch events
     * 
     * @static
     * @return {void}
     */
    static scanAndDispatchEvents() {
        // Find all elements with data-block-script attribute
        const dynamicBlocks = document.querySelectorAll('[data-block-script]');
        
        dynamicBlocks.forEach(blockElement => {
            this.dispatchBlockEvent(blockElement);
        });
    }
    
    /**
     * Dispatch ewp_dynamic_block_on_change event for a specific block
     * 
     * @static
     * @param {HTMLElement} blockElement - The block element
     * @return {void}
     */
    static dispatchBlockEvent(blockElement) {
        const blockScript = blockElement.getAttribute('data-block-script');
        const blockNamespace = blockElement.getAttribute('data-block-namespace') || '';
        const blockName = blockElement.getAttribute('data-block-name') || '';
        
        if (!blockScript) {
            return;
        }
        
        // Create block data object similar to Gutenberg implementation
        const blockData = {
            script: blockScript,
            namespace: blockNamespace,
            name: blockName
        };
        
        // Create event data
        const eventData = {
            response: blockElement.innerHTML,
            block: blockData
        };
        
        // Dispatch the custom event
        const event = new CustomEvent('ewp_dynamic_block_on_change', {
            detail: eventData
        });
        
        document.dispatchEvent(event);
        
        // Log for debugging
        console.log('AWM Dynamic Block Event dispatched:', blockData);
    }
    
    /**
     * Setup MutationObserver to watch for dynamically added blocks
     * 
     * @static
     * @return {void}
     */
    static setupObserver() {
        // Only setup observer if MutationObserver is supported
        if (typeof MutationObserver === 'undefined') {
            return;
        }
        
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                // Check for added nodes
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        // Check if the added node itself has data-block-script
                        if (node.hasAttribute && node.hasAttribute('data-block-script')) {
                            this.dispatchBlockEvent(node);
                        }
                        
                        // Check for child elements with data-block-script
                        const childBlocks = node.querySelectorAll ? node.querySelectorAll('[data-block-script]') : [];
                        childBlocks.forEach(childBlock => {
                            this.dispatchBlockEvent(childBlock);
                        });
                    }
                });
            });
        });
        
        // Start observing
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    /**
     * Manually trigger event for a specific block element
     * 
     * @static
     * @param {HTMLElement|string} element - Block element or selector
     * @return {boolean} Success status
     */
    static triggerBlockEvent(element) {
        let blockElement;
        
        if (typeof element === 'string') {
            blockElement = document.querySelector(element);
        } else {
            blockElement = element;
        }
        
        if (!blockElement || !blockElement.hasAttribute('data-block-script')) {
            console.warn('AWM Dynamic Block Events: Invalid block element provided');
            return false;
        }
        
        this.dispatchBlockEvent(blockElement);
        return true;
    }
}

// Auto-initialize when script loads
AWMDynamicBlockEvents.init();

// Make available globally for manual triggering
window.AWMDynamicBlockEvents = AWMDynamicBlockEvents;
