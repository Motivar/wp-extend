/**
 * TinyMCE Editor Module
 * Handles wp_editor initialization and management
 * Lazy-loaded only when wp_editor fields are present
 */

/**
 * Editor utility: shared activation helpers (Safari-safe)
 */
window.AWMEditorUtil = window.AWMEditorUtil || (function () {
    'use strict';
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

    function isTemplate(elem) {
        const wrapper = elem.closest('.awm-repeater-content');
        return wrapper && wrapper.classList.contains('temp-source');
    }

    function waitForEditor(id, callback, tries = 0) {
        if (window.tinymce && tinymce.get(id) && tinymce.get(id).initialized) {
            callback(tinymce.get(id));
            return;
        }
        if (tries > 40) return; // ~4s
        setTimeout(() => waitForEditor(id, callback, tries + 1), 100);
    }

    function activateVisual(id, editor) {
        // Preserve current scroll to avoid jump
        var y = window.scrollY;
        try { window.wpActiveEditor = id; } catch (e) { }
        if (isSafari && typeof window.switchEditors !== 'undefined' && typeof window.switchEditors.go === 'function') {
            try { window.switchEditors.go(id, 'tmce'); } catch (e) { }
            // Restore scroll ASAP after mode switch
            setTimeout(function(){ try { window.scrollTo(0, y); } catch (e) {} }, 0);
        }
        // Do not force-show or focus to prevent auto-scroll
        try { editor.save(); } catch (e) { }
    }

    function initNonRepeaterEditors() {
        const textareas = document.querySelectorAll('textarea.wp-editor-area');
        if (!textareas || !textareas.length) return;
        textareas.forEach((ta) => {
            if (!ta.id || isTemplate(ta)) return;
            waitForEditor(ta.id, (editor) => activateVisual(ta.id, editor));
        });
    }

    function runOnLoad() {
        initNonRepeaterEditors();
        setTimeout(initNonRepeaterEditors, 400);
        setTimeout(initNonRepeaterEditors, 1200);
    }

    return { isSafari, waitForEditor, activateVisual, initNonRepeaterEditors, runOnLoad };
})();

/**
 * Repeater WP Editor Lazy Initialization Queue
 * Prevents premature TinyMCE initialization that causes blank visual mode
 * Queues editors for delayed initialization after page load
 */
window.awmRepeaterEditorQueue = window.awmRepeaterEditorQueue || {
    editors: [],
    processing: false,

    /**
     * Add editor to initialization queue
     * @param {string} editorId - The textarea ID
     * @param {string} content - The textarea content to preserve
     */
    add: function (editorId, content) {
        // Check if already queued
        var exists = this.editors.some(function (e) { return e.id === editorId; });
        if (!exists) {
            this.editors.push({ id: editorId, content: content || '' });
            EWPDynamicAssetLoader.log('[AWM Editor Queue] Added:', editorId, 'Queue size:', this.editors.length);
        }
    },

    /**
     * Process all queued editors with staggered initialization
     */
    process: function () {
        if (this.processing || this.editors.length === 0) {
            return;
        }

        this.processing = true;
        EWPDynamicAssetLoader.log('[AWM Editor Queue] Processing', this.editors.length, 'editors');

        var self = this;
        var index = 0;

        function initNext() {
            if (index >= self.editors.length) {
                self.processing = false;
                EWPDynamicAssetLoader.log('[AWM Editor Queue] All editors initialized');
                return;
            }

            var editor = self.editors[index];
            index++;

            // Initialize this editor
            self.initEditor(editor.id, editor.content);

            // Wait before initializing next editor to avoid race conditions
            setTimeout(initNext, 200);
        }

        // Start processing after a delay to ensure DOM is ready
        setTimeout(initNext, 500);
    },

    /**
     * Initialize a single editor with content preservation
     * @param {string} editorId - The textarea ID
     * @param {string} content - The content to set
     */
    initEditor: function (editorId, content) {
        var textarea = document.getElementById(editorId);
        if (!textarea) {
            console.warn('[AWM Editor Queue] Textarea not found:', editorId);
            return;
        }

        // Ensure content is preserved in textarea
        if (content && !textarea.value) {
            textarea.value = content;
        } else if (!content && textarea.value) {
            content = textarea.value;
        }

        var initPreview = content.substring(0, 50) + (content.length > 50 ? '...' : '');
        EWPDynamicAssetLoader.log('[AWM Editor Queue] Initializing:', editorId, 'Content length:', content.length, 'Preview:', initPreview);

        // Use the existing initialization function
        if (typeof awm_initialize_repeater_wp_editor === 'function') {
            awm_initialize_repeater_wp_editor(editorId);
        }
    }
};

/**
 * Get wp-editor arguments from localized PHP configuration
 * Uses DRY principle by getting config from awm_get_wp_editor_args()
 * 
 * @param {string} editorId - The ID of the editor
 * @return {Object} TinyMCE configuration object
 */
function awm_get_tinymce_args(editorId) {
    // Get localized wp_editor args from PHP
    const wpEditorArgs = (typeof awmGlobals !== 'undefined' && awmGlobals.wpEditorArgs)
        ? awmGlobals.wpEditorArgs
        : {};

    // Helper to convert a stringified function to a real function
    function awmStringToFunction(maybeFn) {
        if (typeof maybeFn !== 'string') return maybeFn;
        const s = maybeFn.trim();
        if (!s) return undefined;
        // Accept only plain function syntax for safety
        const looksLikeFn = s.startsWith('function');
        if (!looksLikeFn) return undefined;
        try {
            // Wrap in parentheses so it evaluates to a function expression
            /* eslint no-eval: 0 */
            const fn = eval('(' + s + ')');
            return typeof fn === 'function' ? fn : undefined;
        } catch (e) {
            return undefined;
        }
    }

    // Base TinyMCE configuration
    const baseConfig = {
        selector: '#' + editorId,
        theme: 'modern',
        skin: 'lightgray',
        language: 'en',
        relative_urls: false,
        remove_script_host: false,
        convert_urls: false,
        browser_spellcheck: true,
        fix_list_elements: true,
        entities: '38,amp,60,lt,62,gt',
        entity_encoding: 'raw',
        keep_styles: false,
        paste_webkit_styles: 'font-weight font-style color',
        paste_strip_class_attributes: 'mso',
        paste_remove_spans: true,
        paste_remove_styles: true,
        paste_auto_cleanup_on_paste: true,
        wpeditimage_disable_captions: false,
        wpeditimage_html5_captions: true,
        plugins: 'charmap,colorpicker,hr,lists,media,paste,tabfocus,textcolor,fullscreen,wordpress,wpautoresize,wpeditimage,wpgallery,wplink,wpdialogs,wpview',
        formats: {
            alignleft: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'left' } },
                { selector: 'img,table,dl.wp-caption', classes: 'alignleft' }
            ],
            aligncenter: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'center' } },
                { selector: 'img,table,dl.wp-caption', classes: 'aligncenter' }
            ],
            alignright: [
                { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li', styles: { textAlign: 'right' } },
                { selector: 'img,table,dl.wp-caption', classes: 'alignright' }
            ],
            strikethrough: { inline: 'del' }
        },
        setup: function (editor) {
            // Ensure editor shows properly and is interactive
            editor.on('init', function () {
                editor.show();
            });

            // Save content on change
            editor.on('change', function () {
                editor.save();
            });

            // Save content on blur
            editor.on('blur', function () {
                editor.save();
            });
        }
    };

    // Merge PHP configuration with base config (normalize callbacks)
    if (wpEditorArgs.tinymce) {
        const phpTiny = Object.assign({}, wpEditorArgs.tinymce);
        // Normalize callback fields that TinyMCE expects to be functions
        if (typeof phpTiny.setup === 'string') {
            const parsedSetup = awmStringToFunction(phpTiny.setup);
            if (parsedSetup) {
                phpTiny.setup = parsedSetup;
            } else {
                delete phpTiny.setup; // fallback to baseConfig.setup
            }
        }
        Object.assign(baseConfig, phpTiny);
    }

    // Apply other wp_editor settings
    if (wpEditorArgs.wpautop !== undefined) {
        baseConfig.wpautop = wpEditorArgs.wpautop;
    }

    return baseConfig;
}

/**
 * Initialize wp-editor in repeater context
 * Handles TinyMCE editor setup for cloned repeater fields
 * 
 * @param {string} editorId - The ID of the wp-editor textarea
 */
function awm_initialize_repeater_wp_editor(editorId) {
    // Immediate check to prevent multiple simultaneous inits
    if (window.awmEditorInitializing && window.awmEditorInitializing[editorId]) {
        return; // Already initializing this editor
    }
    
    // Mark as initializing globally
    if (!window.awmEditorInitializing) window.awmEditorInitializing = {};
    window.awmEditorInitializing[editorId] = true;
    
    // CRITICAL: Preserve textarea content before any operations
    var textarea = document.getElementById(editorId);
    var preservedContent = '';
    if (textarea) {
        preservedContent = textarea.value || '';
        EWPDynamicAssetLoader.log('[AWM Editor Init] Preserving content for', editorId, '- Length:', preservedContent.length);
    }

    // Wait longer to ensure any other init processes complete first
    setTimeout(() => {
        // First, properly destroy any existing editor instance
        if (typeof tinymce !== 'undefined') {
            // Ensure wrapper is in a clean state and hidden during init to avoid UI glitches
            var wrap = document.getElementById('wp-' + editorId + '-wrap');
            var container = document.getElementById('wp-' + editorId + '-editor-container');
            if (wrap) {
                wrap.classList.remove('html-active');
                wrap.classList.add('tmce-active');
                // Hide visually but keep layout to prevent jumps
                wrap.style.visibility = 'hidden';
            }
            // Remove any existing QuickTags toolbar for this cloned editor to prevent duplicates
            var qtToolbar = document.getElementById('qt_' + editorId + '_toolbar');
            if (qtToolbar && qtToolbar.parentNode) {
                qtToolbar.parentNode.removeChild(qtToolbar);
            }
            // Check if there's an existing editor instance
            if (tinymce.get(editorId)) {
                // Save content to textarea before removing
                tinymce.get(editorId).save();
                // Backup content again in case save modified it
                if (textarea && textarea.value) {
                    preservedContent = textarea.value;
                }
                // Remove the editor instance
                tinymce.remove('#' + editorId);
            }

            // RESTORE content to textarea after cleanup
            if (textarea && preservedContent) {
                textarea.value = preservedContent;
                EWPDynamicAssetLoader.log('[AWM Editor Init] Restored content to textarea:', editorId);
            }

            // Nuclear cleanup: remove ALL TinyMCE instances related to this editor
            if (container) {
                // First pass: remove all TinyMCE containers
                const mceContainers = container.querySelectorAll('.mce-tinymce, .mce-container');
                mceContainers.forEach(function(el) { el.remove(); });
                
                // Second pass: remove any remaining non-textarea, non-quicktags children
                Array.prototype.slice.call(container.children).forEach(function(child) {
                    if (child.tagName && child.tagName.toLowerCase() === 'textarea') return;
                    if (child.id && child.id.indexOf('qt_') === 0) return; // Keep QuickTags
                    child.remove();
                });
            }
            
            // Global cleanup: find and remove any orphaned TinyMCE instances
            document.querySelectorAll('.mce-tinymce').forEach(function(mceEl) {
                // Check if this belongs to our editor by iframe ID or container proximity
                const iframe = mceEl.querySelector('iframe');
                if (iframe && iframe.id) {
                    const baseId = editorId.replace(/template/g, '').replace(/__/g, '_').replace(/_+/g, '_');
                    if (iframe.id.indexOf(baseId) !== -1 || iframe.id.indexOf(editorId) !== -1) {
                        mceEl.remove();
                    }
                }
            });

            // Force remove any existing TinyMCE instances by all possible ID variations
            const possibleIds = [
                editorId,
                editorId.replace(/_/g, '__'), // double underscore variant
                editorId.replace(/template/g, '0'), // template replacement variant
            ];
            possibleIds.forEach(function(id) {
                if (tinymce.get(id)) {
                    tinymce.remove('#' + id);
                }
            });

            // Get our custom TinyMCE configuration
            const tinymceConfig = awm_get_tinymce_args(editorId);

            // Initialize the new editor instance with custom configuration
            tinymce.init(tinymceConfig);
            // After init, ensure Visual is active and editor is interactive (Safari-safe)
            if (window.AWMEditorUtil) {
                window.AWMEditorUtil.waitForEditor(editorId, function (editor) {
                    // CRITICAL: Force content sync from textarea to TinyMCE
                    if (preservedContent && textarea) {
                        // Ensure textarea has the content
                        textarea.value = preservedContent;
                        // Force TinyMCE to load content from textarea
                        try {
                            editor.setContent(preservedContent);
                            editor.save(); // Sync back to textarea
                            var syncPreview = preservedContent.substring(0, 50) + (preservedContent.length > 50 ? '...' : '');
                            EWPDynamicAssetLoader.log('[AWM Editor Init] Content synced to TinyMCE:', editorId, 'Content:', syncPreview);
                        } catch (e) {
                            console.warn('[AWM Editor Init] Error setting content:', e);
                        }
                    }

                    window.AWMEditorUtil.activateVisual(editorId, editor);
                    // Reveal wrapper now that TinyMCE is ready
                    if (wrap) {
                        // Prefer reveal on skin loaded for stable UI
                        var reveal = function(){
                            try { editor.execCommand('mceRepaint'); } catch (e) {}
                            wrap.style.visibility = '';
                            // Clear the global init flag
                            if (window.awmEditorInitializing) {
                                delete window.awmEditorInitializing[editorId];
                            }
                            // Verify content is visible
                            var editorContent = editor.getContent();
                            if (editorContent && editorContent.length > 0) {
                                EWPDynamicAssetLoader.log('[AWM Editor Init] SUCCESS - Content visible in visual mode:', editorId);
                            } else if (preservedContent && preservedContent.length > 0) {
                                console.warn('[AWM Editor Init] WARNING - Content not visible, retrying...', editorId);
                                // Retry setting content
                                try {
                                    editor.setContent(preservedContent);
                                } catch (e) { }
                            }
                        };
                        try {
                            editor.once('SkinLoaded', reveal);
                            // Fallback in case event doesn't fire
                            setTimeout(reveal, 300);
                        } catch (e) { setTimeout(reveal, 300); }
                    }
                    // Reinitialize QuickTags toolbar once after TinyMCE is set up
                    try {
                        if (window.quicktags) {
                            quicktags({ id: editorId });
                            if (window.QTags && typeof QTags._buttonsInit === 'function') {
                                QTags._buttonsInit();
                            }
                        }
                    } catch (e) { /* noop */ }
                });
            }
        }
    }, 500); // Longer delay to prevent race conditions
}

/**
 * Detect and queue all repeater wp_editor fields on page load
 * Prevents premature initialization that causes blank visual mode
 */
function awm_queue_repeater_editors_on_load() {
    // Find all wp-editor textareas inside repeater content (not templates)
    var repeaterEditors = document.querySelectorAll('.awm-repeater-content:not(.temp-source) textarea.wp-editor-area');

    if (!repeaterEditors || repeaterEditors.length === 0) {
        EWPDynamicAssetLoader.log('[AWM Editor Queue] No repeater editors found on page load');
        return;
    }

    EWPDynamicAssetLoader.log('[AWM Editor Queue] Found', repeaterEditors.length, 'repeater editors on page load');

    repeaterEditors.forEach(function (textarea) {
        if (textarea.id) {
            var content = textarea.value || '';

            // Only queue if there's content to preserve
            if (content.trim().length > 0) {
                window.awmRepeaterEditorQueue.add(textarea.id, content);

                // Prevent WordPress from auto-initializing this editor
                // by temporarily hiding it from TinyMCE's auto-init
                textarea.setAttribute('data-awm-queued', 'true');
            }
        }
    });
}

/**
 * Initialize TinyMCE editors on page load
 * Waits for all WordPress scripts to load before processing
 */
function initTinyMCEEditors() {
    // First, queue any existing repeater editors
    awm_queue_repeater_editors_on_load();

    // Then process the queue after a delay
    setTimeout(function () {
        if (window.awmRepeaterEditorQueue && typeof window.awmRepeaterEditorQueue.process === 'function') {
            window.awmRepeaterEditorQueue.process();
        }
    }, 300);

    // Initialize non-repeater editors
    if (window.AWMEditorUtil && typeof window.AWMEditorUtil.runOnLoad === 'function') {
        window.AWMEditorUtil.runOnLoad();
    }
}

// Export initialization function
export { initTinyMCEEditors };
