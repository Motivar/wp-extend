const { registerBlockType } = wp.blocks;
const { InspectorControls } = wp.blockEditor || wp.editor;
const { PanelBody, TextControl, RangeControl } = wp.components;
const { useEffect, useState } = wp.element;
const { apiFetch } = wp;

if (typeof wp !== 'undefined' && wp.blocks && wp.blockEditor && wp.components && wp.element && typeof ewp_blocks !== 'undefined') {
  Object.keys(ewp_blocks).forEach((key) => {
    const block = ewp_blocks[key];
    const namespace = `${block.namespace}/${block.name}`;

    registerBlockType(namespace, {
      title: block.title,
      icon: block.icon,
      category: block.category,
      attributes: {
        ...block.attributes,
        isValid: { type: 'boolean', default: true }, // Add validation as an attribute
      },
      edit: function EditBlock(props) {
        const { attributes, setAttributes, clientId } = props;

        // Initialize inputValues with default or initial values from block.attributes
        const initialValues = Object.keys(block.attributes).reduce((acc, attrKey) => {
          const attribute = block.attributes[attrKey];
          acc[attrKey] = attributes[attrKey] || attribute.default || '';
          return acc;
        }, {});

        const [content, setContent] = useState('');
        const [inputValues, setInputValues] = useState(initialValues);
        const [errorMessages, setErrorMessages] = useState({});  // Track errors for this block instance

        const handleInputChange = (identifier, newValue) => {
          setInputValues({
            ...inputValues,
            [identifier]: newValue,
          });

          // Validate field upon change
          validateField(identifier, newValue);
        };

        const validateField = (identifier, value) => {
          if (block.attributes[identifier]?.required && !value) {
            setErrorMessages((prevErrors) => ({
              ...prevErrors,
              [identifier]: `${block.attributes[identifier].label || identifier} is required.`
            }));
            setAttributes({ isValid: false }); // Mark block as invalid
          } else {
            // Clear the error for this field if it's valid
            setErrorMessages((prevErrors) => {
              const { [identifier]: _, ...rest } = prevErrors;  // Remove the error for this field
              return rest;
            });
            setAttributes({ isValid: true }); // Mark block as valid
          }
        };

        useEffect(() => {
          // Fetch and set content logic
          const queryParams = new URLSearchParams(inputValues).toString();
          apiFetch({ path: `${block.namespace}/${block.name}/preview?${queryParams}` }).then((html) => {
            setContent(html);
            var data = { response: html, block: block };
            const event = new CustomEvent("ewp_dynamic_block_on_change", { detail: data });
            document.dispatchEvent(event);
          });
        }, [JSON.stringify(inputValues)]);

        // Check all required fields for this block instance
        const validateRequiredFields = () => {
          let hasErrors = false;
          Object.entries(block.attributes).forEach(([key, data]) => {
            if (data.required && !inputValues[key]) {
              setErrorMessages((prevErrors) => ({
                ...prevErrors,
                [key]: `${data.label || key} is required.`
              }));
              hasErrors = true;
            }
          });

          setAttributes({ isValid: !hasErrors });
          return !hasErrors;  // Return the validation result
        };

        // Prevent block saving if this block instance is invalid
        useEffect(() => {
          validateRequiredFields();
        }, [inputValues]); // Re-run validation when input values change

        if (typeof block.attributes === 'object' && block.attributes !== null) {
          var elements = [];
          Object.entries(block.attributes).forEach(([key, data]) => {
            switch (data.render_type) {
              case 'select':
                elements.push(wp.element.createElement(wp.components.SelectControl, {
                  label: data.label,
                  value: props.attributes[key],
                  options: data.options,
                  onChange: function (value) {
                    setAttributes({ [key]: value });
                    handleInputChange(key, value);
                  }
                }));
                break;
              case 'color':
                elements.push(wp.element.createElement(wp.components.ColorPicker, {
                  label: data.label,
                  color: props.attributes[key],
                  onChangeComplete: function (value) {
                    const colorValue = value.hex;
                    setAttributes({ [key]: colorValue });
                    handleInputChange(key, colorValue);
                  }
                }));
                break;
              case 'textarea':
                elements.push(wp.element.createElement(wp.components.TextareaControl, {
                  label: data.label,
                  value: props.attributes[key],
                  onChange: function (value) {
                    setAttributes({ [key]: value });
                    handleInputChange(key, value);
                  }
                }));
                break;
              case 'number':
                elements.push(wp.element.createElement(wp.components.RangeControl, {
                  label: data.label,
                  value: props.attributes[key],
                  onChange: function (value) {
                    setAttributes({ [key]: value });
                    handleInputChange(key, value);
                  },
                  min: data.attributes.min || 0,
                  max: data.attributes.max || 100,
                  step: data.attributes.step || 1
                }));
                break;
              case 'string':
                elements.push(wp.element.createElement(wp.components.TextControl, {
                  label: data.label,
                  value: props.attributes[key],
                  onChange: function (value) {
                    setAttributes({ [key]: value });
                    handleInputChange(key, value);
                  }
                }));
                break;
              case 'boolean':
                elements.push(wp.element.createElement(wp.components.ToggleControl, {
                  label: data.label,
                  checked: props.attributes[key],
                  onChange: function (value) {
                    setAttributes({ [key]: value });
                    handleInputChange(key, value);
                  }
                }));
                break;
              default:
                // Handle other types
                break;
            }
          });

          return [
            wp.element.createElement(
              InspectorControls,
              null,
              wp.element.createElement(
                PanelBody,
                {
                  title: block.title + ' Settings', initialOpen: true
                },
                elements
              )
            ),
            wp.element.createElement(
              'div',
              {
                className: props.className,
              },
              // Display individual error messages
              Object.keys(errorMessages).map((key) => (
                wp.element.createElement('p', { style: { color: 'red' } }, errorMessages[key])
              )),
              wp.element.createElement(
                'div',
                {
                  dangerouslySetInnerHTML: { __html: content }
                }
              )
            ),
          ];
        }
      },
      save: function (props) {
        // Prevent save if the block instance is invalid
        if (!props.attributes.isValid) {
          return null;
        }
        return null; // Server-side rendering, so no save needed
      },
    });
  });
}