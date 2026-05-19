/**
 * Repeater Module
 * Handles repeater field cloning, ordering, and management
 * Lazy-loaded only when repeater fields are present
 */

/**
 * Helper function to swap input attributes between two repeater elements
 * 
 * @param {HTMLElement} elem1 - First repeater element
 * @param {HTMLElement} elem2 - Second repeater element
 * @param {string} repeater - Repeater ID
 * @param {number} counter1 - Original counter of first element
 * @param {number} counter2 - Original counter of second element
 */
function swapInputAttributes(elem1, elem2, repeater, counter1, counter2) {
    // Process all inputs in both elements
    updateInputAttributes(elem1, repeater, counter1, counter2);
    updateInputAttributes(elem2, repeater, counter2, counter1);
}

/**
 * Updates input attributes in a repeater element
 * 
 * @param {HTMLElement} elem - Repeater element to update
 * @param {string} repeater - Repeater ID
 * @param {number} oldCounter - Original counter value
 * @param {number} newCounter - New counter value
 */
function updateInputAttributes(elem, repeater, oldCounter, newCounter) {
    // CRITICAL: Save all TinyMCE content to textareas BEFORE processing
    // AND capture content with OLD IDs before they change
    var wpEditorContentMap = {}; // Map old ID -> content
    var wpEditors = elem.querySelectorAll('textarea.wp-editor-area');
    wpEditors.forEach(function (textarea) {
        if (textarea.id && typeof tinymce !== 'undefined') {
            var editor = tinymce.get(textarea.id);
            if (editor) {
                try {
                    editor.save(); // Force save content to textarea
                    EWPDynamicAssetLoader.log('[AWM Reorder] Saved content from TinyMCE to textarea:', textarea.id);
                } catch (e) {
                    EWPDynamicAssetLoader.log('[AWM Reorder] Error saving editor content:', e);
                }
            }
            // Capture content with OLD ID
            wpEditorContentMap[textarea.id] = textarea.value || '';
            EWPDynamicAssetLoader.log('[AWM Reorder] Captured content for OLD ID:', textarea.id, 'Length:', wpEditorContentMap[textarea.id].length);
            if (wpEditorContentMap[textarea.id].length > 0) {
                var preview = wpEditorContentMap[textarea.id].substring(0, 100);
                EWPDynamicAssetLoader.log('[AWM Reorder] Content preview:', preview);
            } else {
                EWPDynamicAssetLoader.log('[AWM Reorder] WARNING: No content captured!');
            }
        }
    });

    // Update all input elements
    var inputs = elem.querySelectorAll('input, select, textarea');
    inputs.forEach(function(input) {
        // Update name attribute
        if (input.name) {
            input.name = input.name.replace(
                new RegExp(repeater + '\\[' + oldCounter + '\\]', 'g'), 
                repeater + '[' + newCounter + ']'
            );
        }
        
        // Update id attribute - INCLUDING wp-editor textareas
        if (input.id) {
            var oldId = input.id;
            input.id = input.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );

            // If this is a wp-editor textarea, restore content AND update wrapper IDs
            if (input.classList.contains('wp-editor-area') && wpEditorContentMap[oldId]) {
                input.value = wpEditorContentMap[oldId];
                EWPDynamicAssetLoader.log('[AWM Reorder] Restored content to NEW ID:', input.id, 'from OLD ID:', oldId, 'Length:', wpEditorContentMap[oldId].length);
                if (wpEditorContentMap[oldId].length > 0) {
                    var restorePreview = wpEditorContentMap[oldId].substring(0, 100);
                    EWPDynamicAssetLoader.log('[AWM Reorder] Restored content preview:', restorePreview);
                }

                // CRITICAL: Update wp-editor wrapper IDs to match new textarea ID
                // WordPress creates wrappers like: wp-{id}-wrap, wp-{id}-editor-container, etc.
                // But we need to find them by traversing up from the textarea since IDs might vary

                // Try to find wrapper by ID first
                var oldWrap = document.getElementById('wp-' + oldId + '-wrap');
                var oldContainer = document.getElementById('wp-' + oldId + '-editor-container');
                var oldToolbar = document.getElementById('wp-' + oldId + '-editor-tools');

                // If not found by ID, try to find by traversing up from textarea
                if (!oldWrap) {
                    // Look for parent with class 'wp-editor-wrap'
                    var parent = input.parentElement;
                    while (parent && !parent.classList.contains('wp-editor-wrap')) {
                        parent = parent.parentElement;
                    }
                    if (parent && parent.classList.contains('wp-editor-wrap')) {
                        oldWrap = parent;
                        EWPDynamicAssetLoader.log('[AWM Reorder] Found wrapper by traversal:', oldWrap.id || 'no-id');
                    }
                }

                if (!oldContainer) {
                    // Look for parent with class 'wp-editor-container'
                    var editorContainer = input.parentElement;
                    if (editorContainer && editorContainer.classList.contains('wp-editor-container')) {
                        oldContainer = editorContainer;
                    }
                }

                if (oldWrap) {
                    oldWrap.id = 'wp-' + input.id + '-wrap';
                    EWPDynamicAssetLoader.log('[AWM Reorder] Updated wrapper ID:', oldWrap.id);
                } else {
                    EWPDynamicAssetLoader.log('[AWM Reorder] WARNING: Wrapper not found for OLD ID:', 'wp-' + oldId + '-wrap');
                }
                if (oldContainer) {
                    oldContainer.id = 'wp-' + input.id + '-editor-container';
                    EWPDynamicAssetLoader.log('[AWM Reorder] Updated container ID:', oldContainer.id);
                } else {
                    EWPDynamicAssetLoader.log('[AWM Reorder] WARNING: Container not found for OLD ID:', 'wp-' + oldId + '-editor-container');
                }
                if (oldToolbar) {
                    oldToolbar.id = 'wp-' + input.id + '-editor-tools';
                }
            }
        }
    });
    
    // Update data-input attributes on containers
    var containers = elem.querySelectorAll('[data-input]');
    containers.forEach(function(container) {
        var dataInput = container.getAttribute('data-input');
        if (dataInput) {
            container.setAttribute('data-input', dataInput.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            ));
        }
        
        // Also update the id attribute if it contains the element prefix
        if (container.id && container.id.includes('awm-element-')) {
            container.id = container.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
    });
    
    // Update media field containers (unified image/gallery)
    var mediaContainers = elem.querySelectorAll('.awm-media-field');
    mediaContainers.forEach(function (container) {
        if (container.id) {
            container.id = container.id.replace(
                new RegExp(repeater + '_' + oldCounter + '_', 'g'), 
                repeater + '_' + newCounter + '_'
            );
        }
        /* Reset data-awm-init so AWMMediaField re-initialises the cloned field */
        container.removeAttribute('data-awm-init');
    });


    var inputs = elem.querySelectorAll('textarea.wp-editor-area');
    if (inputs) {
        inputs.forEach(function (input) {
            // At this point, IDs have been updated and content restored to NEW IDs
            var content = input.value || '';
            var editorId = input.id; // This is the NEW ID

            EWPDynamicAssetLoader.log('[AWM Reorder] Queueing editor with NEW ID for reinitialization:', editorId, 'Content length:', content.length);
            if (content.length > 0) {
                var queuePreview = content.substring(0, 100);
                EWPDynamicAssetLoader.log('[AWM Reorder] Queue content preview:', queuePreview);
            } else {
                EWPDynamicAssetLoader.log('[AWM Reorder] WARNING: Queueing with EMPTY content!');
            }

            if (window.awmRepeaterEditorQueue) {
                window.awmRepeaterEditorQueue.add(editorId, content);
                // Process this single editor after a short delay
                // Use captured values in closure to avoid stale references
                setTimeout(function () {
                    window.awmRepeaterEditorQueue.initEditor(editorId, content);
                }, 300);
            } else {
                // Fallback to direct initialization if queue not available
                if (typeof awm_initialize_repeater_wp_editor === 'function') {
                    awm_initialize_repeater_wp_editor(editorId);
                }
            }
        });
    }
}

/**
 * Handles the reordering of repeater elements when moving up or down
 * 
 * @param {HTMLElement} elem - The DOM element that triggered the action
 * @param {boolean} action - True for move up, false for move down
 */
function awm_repeater_order(elem, action) {
    try {
        // Get the repeater container element
        var repeater_div = elem.closest('.awm-repeater-content');
        if (repeater_div) {
            var repeater = repeater_div.getAttribute('data-id');
            var counter = parseInt(repeater_div.getAttribute('data-counter'));
            var parent = repeater_div.parentNode;

            // Find the element to swap with based on action (up or down)
            var targetElement = action ? repeater_div.previousElementSibling : repeater_div.nextElementSibling;

            // Only proceed if there's an element to swap with and it's not the template
            if (targetElement && !targetElement.classList.contains('temp-source')) {
                var targetCounter = parseInt(targetElement.getAttribute('data-counter'));

                console.log('Swapping element', counter, action ? 'up with' : 'down with', targetCounter);

                // Execute all reordering actions synchronously
                awm_execute_reorder_actions(repeater_div, targetElement, repeater, counter, targetCounter, action);


                return true;
            }
        }
        return false;
    } catch (error) {
        console.error('Error in awm_repeater_order:', error);
        console.error('Error stack:', error.stack);
        return false;
    }
}

/**
 * Execute all reordering actions synchronously
 * 
 * @param {HTMLElement} repeater_div - The repeater element being moved
 * @param {HTMLElement} targetElement - The target element to swap with
 * @param {string} repeater - Repeater ID
 * @param {number} counter - Original counter of moving element
 * @param {number} targetCounter - Original counter of target element
 * @param {boolean} action - True for move up, false for move down
 */
function awm_execute_reorder_actions(repeater_div, targetElement, repeater, counter, targetCounter, action) {
    console.log('Step 1: Calculating DOM positions...');
    // Store original positions for DOM manipulation
    var nextSibling = action ? targetElement : repeater_div.nextElementSibling.nextElementSibling;

    console.log('Step 2: Swapping data-counter attributes...');
    // Swap data-counter attributes
    repeater_div.setAttribute('data-counter', targetCounter);
    targetElement.setAttribute('data-counter', counter);

    console.log('Step 3: Updating element IDs...');
    // Update IDs to reflect new positions
    repeater_div.id = 'awm-' + repeater + '-' + targetCounter;
    targetElement.id = 'awm-' + repeater + '-' + counter;

    console.log('Step 4: Swapping input attributes...');
    // Preserve all input values and update their names/ids
    swapInputAttributes(repeater_div, targetElement, repeater, counter, targetCounter);

    console.log('Step 5: Moving DOM elements...');
    // Move the DOM elements
    repeater_div.parentNode.insertBefore(repeater_div, nextSibling);

    console.log('Step 6: All DOM operations completed');
}

/**
 * Clone repeater row with all field values
 * 
 * @param {HTMLElement} elem - The element that triggered the clone
 * @param {Object} prePopulated - Pre-populated values for new row
 */
function ewp_repeater_clone_row(elem, prePopulated = []) {
    // Find the repeater content div that contains this element
    const repeater_div = elem.closest('.awm-repeater-content');

    // Find the parent repeater that contains this element
    const repeater_parent = elem.closest('.repeater');

    if (!repeater_div || !repeater_parent) return false;

    // Get the repeater ID from the repeater content div
    const repeaterId = repeater_div.getAttribute('data-id');

    // Store all input values from the current row
    const originalValues = {};
    const inputs = repeater_div.querySelectorAll('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');

    // Create a map of all input values by their input-key attribute
    inputs.forEach(function (input) {
        const key = input.getAttribute('input-key');
        if (key) {
            if (input.type === 'checkbox' || input.type === 'radio') {
                originalValues[key] = input.checked;
            } else {
                originalValues[key] = input.value;
            }
        }
    });

    // Find the template source for this specific repeater
    const templateSource = repeater_parent.querySelector(`.awm-repeater-content.temp-source[data-id="${repeaterId}"]`);
    if (!templateSource) return false;
    console.log(templateSource);
    // Call the repeater function with the add button from the template source
    const addButton = templateSource.querySelector(`.awm-repeater-add[data-id="${repeaterId}"] .awm-add`);
    console.log(addButton);
    if (addButton) {
        repeater(addButton, originalValues);
    }
}

/**
 * Main repeater function - handles adding and removing repeater rows
 * 
 * @param {HTMLElement} elem - The button element that triggered the action
 * @param {Object} prePopulated - Pre-populated values for new row
 */
function repeater(elem, prePopulated = []) {
    var repeater_div = elem.closest('.awm-repeater');

    var maxRows = repeater_div.getAttribute('maxrows') ? parseInt(repeater_div.getAttribute('maxrows')) : 0;
    var repeater = repeater_div.getAttribute('data-id');

    var clicked_element = elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]');
    var add = false;
    if (elem.classList.contains('awm-add')) {
        add = true;
        var last_element = document.querySelectorAll('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content:not(.temp-source)[data-id="' + repeater + '"]');
        var old_counter = last_element.length - 1;
        switch (last_element.length) {
            case 1:
                old_counter = 0;
                break;
            case 0:
                old_counter = -1;
                break;
        }
        var new_counter = old_counter + 1;
        if (maxRows == 0 || new_counter < maxRows) {
            var template = document.querySelector('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content.temp-source[data-id="' + repeater + '"]');
            var cloned = template.cloneNode(true);
            cloned.classList.add('cloned');
            cloned.classList.remove('temp-source');

            var inner = cloned.innerHTML;
            var res = inner.replace(/template/g, new_counter);
            cloned.innerHTML = res;

            var selects = cloned.querySelectorAll('.ss-main');
            if (selects) {
                selects.forEach(function (select) {
                    select.innerHTML = '';
                });
            }
            var inputs = cloned.querySelectorAll('input,select,textarea');
            if (inputs) {
                inputs.forEach(function (input) {
                    input.removeAttribute('disabled');
                    input.removeAttribute('readonly');
                    input.classList.remove('hasDatepicker');
                    input.classList.remove('awm-callback-checked');
                    input.removeAttribute('data-id');
                    input.removeAttribute('style');
                    input.removeAttribute('checked');
                });
            }
            cloned = awm_repeater_clone(cloned, new_counter, repeater);
            cloned.querySelector(':scope > .awm-actions .awm-repeater-add').innerHTML = '';
            cloned.querySelector('input[input-key="awm_key"]').value = new Date().getTime() + new_counter;
            jQuery('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content.temp-source[data-id="' + repeater + '"]').before(cloned);
            cloned.classList.remove('cloned');
            template.parentNode.insertBefore(cloned, template);
            var template_inputs = template.querySelectorAll('input,select,textarea');
            if (template_inputs) {
                template_inputs.forEach(function (input) {
                    input.setAttribute('disabled', true);
                    input.setAttribute('readonly', true);
                });
            }
            if (typeof awm_init_inputs === 'function') {
                awm_init_inputs();
            }

            /* Re-initialise AWMMediaField instances inside the cloned row */
            var mediaFields = cloned.querySelectorAll('.awm-media-field');
            if (mediaFields.length > 0) {
                mediaFields.forEach(function (mf) {
                    mf.removeAttribute('data-awm-init');
                });
                if (typeof AWMMediaField !== 'undefined') {
                    AWMMediaField.init();
                }
            }

            var inputs = cloned.querySelectorAll('input,select,textarea');

            if (inputs) {
                inputs.forEach(function (input) {
                    if (input.classList.contains('wp-editor-area')) {
                        // Queue the editor for delayed initialization to preserve content
                        var content = input.value || '';
                        if (window.awmRepeaterEditorQueue) {
                            window.awmRepeaterEditorQueue.add(input.id, content);
                            // Process this single editor after a short delay
                            setTimeout(function () {
                                window.awmRepeaterEditorQueue.initEditor(input.id, content);
                            }, 300);
                        } else {
                        // Fallback to direct initialization if queue not available
                            if (typeof awm_initialize_repeater_wp_editor === 'function') {
                                awm_initialize_repeater_wp_editor(input.id);
                            }
                        }
                    }
                    var inputKey = input.getAttribute('input-key') || '';
                    if (inputKey && prePopulated[inputKey]) {
                        input.value = prePopulated[inputKey];
                        input.dispatchEvent(new Event('change'));
                    }
                });
            }

        }
    } else {
        var rep_wrapper = elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]');
        rep_wrapper.querySelectorAll('input,select,textarea').forEach(function (input) {
            input.value = 0;
            input.dispatchEvent(new Event('change'));
        });
        elem.closest('.awm-repeater[data-id="' + repeater + '"] .awm-repeater-content[data-id="' + repeater + '"]').outerHTML = '';
    }
    var elements = document.querySelectorAll('.awm-repeater[data-id="' + repeater + '"] > input:last-child,.awm-repeater[data-id="' + repeater + '"] > select:last-child,.awm-repeater[data-id="' + repeater + '"] > textarea:last-child');
    var data = { repeater_id: repeater, add: add, elem: clicked_element };
    var event = new CustomEvent("awm_repeater_change_row", { detail: data });
    document.dispatchEvent(event);
    if (elements.length > 0) {
        var last = elements[elements.length - 1];
        last.dispatchEvent(new Event('change'));
    }
    return true;
}

/**
 * Clone repeater element and update all IDs and names
 * 
 * @param {HTMLElement} cloned - The cloned element
 * @param {number} new_counter - The new counter value
 * @param {string} repeater - The repeater ID
 */
function awm_repeater_clone(cloned, new_counter, repeater) {
    cloned.setAttribute('data-counter', new_counter);
    // Replace the problematic :scope selector with direct children selection
    var inputsAll = cloned.querySelectorAll('input,select,textarea');

    if (inputsAll && inputsAll.length > 0) {
        inputsAll.forEach(function (input) {
            var old_id = input.getAttribute('id');
            var label = cloned.querySelector('label[for="' + old_id + '"]');
            var namee, id;
            if (input.name) {
                // Get input-name and input-key attributes with fallbacks
                var inputName = input.getAttribute("input-name") || repeater;
                var inputKey = input.getAttribute("input-key") || '';
                
                // For wp-editor fields, if input-key is missing, try to get it from the label
                if (input.classList.contains('wp-editor-area') && (!inputKey || inputKey === '')) {
                    // Find the editor container
                    var editorContainer = input.closest('.awm-wp-editor');
                    if (editorContainer) {
                        // Try to get key from label text
                        var editorLabel = editorContainer.querySelector('.awm-input-label span');
                        if (editorLabel && editorLabel.textContent) {
                            inputKey = editorLabel.textContent.toLowerCase().trim();
                            // Update the input-key attribute for future reference
                            input.setAttribute('input-key', inputKey);
                        }
                    }
                }
                
                // Create proper name and id attributes
                namee = inputName + "[" + new_counter + "]" + "[" + inputKey + "]";
                id = namee.replace(/\[/g, '_').replace(/\]/g, '_');
                input.setAttribute("name", namee);
                input.setAttribute("id", id);
            }
            if (label) {
                label.setAttribute('for', id);
            }
            var image_input = input.closest('.awm-meta-field');
            if (image_input && image_input.classList.contains('awm-custom-image-meta')) {
                // Handle the main image container
                var imageContainer = image_input;
                if (imageContainer) {
                    // Get the label text to use as key if needed
                    var imageLabel = imageContainer.querySelector('.awm-input-label');
                    var labelText = '';
                    if (imageLabel) {
                        labelText = imageLabel.textContent.toLowerCase().trim();
                    }
                    
                    // Update data-input attribute
                    imageContainer.setAttribute('data-input', id);
                    
                    // Update media field container (unified image/gallery)
                    var mediaField = imageContainer.querySelector('.awm-media-field');
                    if (mediaField) {
                        mediaField.setAttribute('id', id + '-field');
                        mediaField.setAttribute('data-id', id);
                        /* Reset init flag so AWMMediaField re-initialises */
                        mediaField.removeAttribute('data-awm-init');
                        
                        // Find and update hidden input fields
                        var hiddenInputs = mediaField.querySelectorAll('input[type="hidden"]');
                        hiddenInputs.forEach(function (hiddenInput) {
                            // If input-key is missing or empty, use the label text
                            if (!hiddenInput.getAttribute('input-key') || hiddenInput.getAttribute('input-key') === '') {
                                hiddenInput.setAttribute('input-key', labelText);
                            }
                            // Update name and id attributes
                            var inputName = hiddenInput.getAttribute('input-name') || repeater;
                            var inputKey = hiddenInput.getAttribute('input-key') || labelText;
                            var isSingle = mediaField.getAttribute('data-max') === '1';
                            var newName = inputName + '[' + new_counter + '][' + inputKey + ']' + (isSingle ? '' : '[]');
                            var newId = newName.replace(/\[/g, '_').replace(/\]/g, '_');
                            hiddenInput.setAttribute('name', newName);
                            hiddenInput.setAttribute('id', newId);
                        });

                        // Update the button data-id
                        var mediaBtn = mediaField.querySelector('.awm-media-field-button');
                        if (mediaBtn) {
                            mediaBtn.setAttribute('data-id', id);
                        }
                    }
                }
            }

        });
    }
    cloned.setAttribute('id', 'awm-' + repeater + '-' + new_counter);
    cloned.setAttribute('data-id', repeater);
    return cloned;
}

/**
 * Initialize repeater functionality
 */
function initRepeaters() {
    // Repeater functions are now globally available
    // This is called when repeater fields are detected
}

// Export functions
export { repeater, ewp_repeater_clone_row, awm_repeater_order, initRepeaters };
