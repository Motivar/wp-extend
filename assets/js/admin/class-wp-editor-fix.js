/**
 * AWM WP Editor Fix Class
 * 
 * Handles WordPress editor initialization issues where visual mode
 * doesn't display content properly on page reload.
 * 
 * @class AWMWPEditorFix
 * @author Your Name
 * @version 1.0.0
 */
class AWMWPEditorFix {
    /**
     * Class constructor
     * Initializes the editor fix functionality
     */
    constructor() {
        this.editors = new Map();
        this.initializeEditorFix();
    }

    /**
     * Initialize the editor fix system
     * Sets up event listeners and monitors for TinyMCE editors
     */
    initializeEditorFix() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupEditorMonitoring());
        } else {
            this.setupEditorMonitoring();
        }
    }

    /**
     * Set up monitoring for TinyMCE editors
     * Detects when editors are initialized and applies fixes
     */
    setupEditorMonitoring() {
        // Check if TinyMCE is available
        if (typeof tinymce === 'undefined') {
            // Retry after a short delay if TinyMCE isn't loaded yet
            setTimeout(() => this.setupEditorMonitoring(), 100);
            return;
        }

        // Monitor for editor initialization
        this.monitorEditorInitialization();
        
        // Apply fixes to existing editors
        this.fixExistingEditors();
        
        // Set up periodic check for new editors
        this.startPeriodicCheck();
    }

    /**
     * Monitor TinyMCE editor initialization events
     * Applies fixes when new editors are created
     */
    monitorEditorInitialization() {
        // Listen for TinyMCE init events
        tinymce.on('AddEditor', (e) => {
            this.handleEditorAdd(e.editor);
        });

        // Listen for editor setup events
        tinymce.on('SetupEditor', (e) => {
            this.handleEditorSetup(e.editor);
        });
    }

    /**
     * Handle when a new editor is added
     * 
     * @param {Object} editor - TinyMCE editor instance
     */
    handleEditorAdd(editor) {
        // Store editor reference
        this.editors.set(editor.id, editor);
        
        // Apply fix when editor is initialized
        editor.on('init', () => {
            this.applyEditorFix(editor);
        });
    }

    /**
     * Handle editor setup phase
     * 
     * @param {Object} editor - TinyMCE editor instance
     */
    handleEditorSetup(editor) {
        // Ensure proper content loading
        editor.on('LoadContent', () => {
            this.refreshEditorContent(editor);
        });
    }

    /**
     * Apply fixes to existing editors
     * Handles editors that were already initialized
     */
    fixExistingEditors() {
        if (!tinymce.editors) return;

        tinymce.editors.forEach(editor => {
            if (editor && editor.initialized) {
                this.applyEditorFix(editor);
            }
        });
    }

    /**
     * Apply the main editor fix
     * Forces the visual editor to display content properly
     * 
     * @param {Object} editor - TinyMCE editor instance
     */
    applyEditorFix(editor) {
        if (!editor || !editor.initialized) return;

        try {
            // Get the textarea content
            const textarea = document.getElementById(editor.id);
            if (!textarea) return;

            const content = textarea.value;
            
            // Only proceed if there's content but visual editor is empty
            if (content && this.isVisualEditorEmpty(editor)) {
                this.forceContentRefresh(editor, content);
            }
        } catch (error) {
            console.warn('AWM WP Editor Fix: Error applying fix to editor', editor.id, error);
        }
    }

    /**
     * Check if the visual editor appears empty
     * 
     * @param {Object} editor - TinyMCE editor instance
     * @return {boolean} True if visual editor appears empty
     */
    isVisualEditorEmpty(editor) {
        const visualContent = editor.getContent();
        const cleanContent = visualContent.replace(/<p><br[^>]*><\/p>/gi, '').trim();
        return cleanContent === '' || cleanContent === '<p></p>';
    }

    /**
     * Force content refresh in the visual editor
     * 
     * @param {Object} editor - TinyMCE editor instance
     * @param {string} content - Content to set
     */
    forceContentRefresh(editor, content) {
        // Set content in visual editor
        editor.setContent(content);
        
        // Trigger change event to ensure synchronization
        editor.fire('change');
        
        // Force a save to textarea
        editor.save();
        
        // Refresh the editor display
        setTimeout(() => {
            editor.nodeChanged();
        }, 50);
    }

    /**
     * Refresh editor content from textarea
     * 
     * @param {Object} editor - TinyMCE editor instance
     */
    refreshEditorContent(editor) {
        setTimeout(() => {
            this.applyEditorFix(editor);
        }, 100);
    }

    /**
     * Start periodic check for new editors
     * Ensures any dynamically added editors are handled
     */
    startPeriodicCheck() {
        setInterval(() => {
            this.checkForNewEditors();
        }, 2000);
    }

    /**
     * Check for new editors that may have been added dynamically
     * Applies fixes to any newly discovered editors
     */
    checkForNewEditors() {
        if (!tinymce.editors) return;

        tinymce.editors.forEach(editor => {
            if (editor && editor.initialized && !this.editors.has(editor.id)) {
                this.handleEditorAdd(editor);
                this.applyEditorFix(editor);
            }
        });
    }

    /**
     * Manual fix trigger for specific editor
     * Can be called externally to force a fix
     * 
     * @param {string} editorId - ID of the editor to fix
     */
    static fixEditor(editorId) {
        const editor = tinymce.get(editorId);
        if (editor && editor.initialized) {
            const instance = new AWMWPEditorFix();
            instance.applyEditorFix(editor);
        }
    }

    /**
     * Fix all editors on the page
     * Static method for external use
     */
    static fixAllEditors() {
        const instance = new AWMWPEditorFix();
        instance.fixExistingEditors();
    }
}

// Initialize the editor fix system when script loads
document.addEventListener('DOMContentLoaded', () => {
    new AWMWPEditorFix();
});

// Make class available globally for external access
window.AWMWPEditorFix = AWMWPEditorFix;
