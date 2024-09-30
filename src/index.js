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
   attributes: block.attributes,
   edit: function (props) {
    const { attributes, setAttributes } = props;
    // Initialize inputValues with default or initial values from block.attributes
    const initialValues = Object.keys(block.attributes).reduce((acc, attrKey) => {
     const attribute = block.attributes[attrKey];
     acc[attrKey] = attributes[attrKey] || attribute.default || '';
     return acc;
    }, {});


    const [content, setContent] = useState('');
    const [inputValues, setInputValues] = useState(initialValues);

    const handleInputChange = (identifier, newValue) => {
     setInputValues({
      ...inputValues,
      [identifier]: newValue,
     });
    };

    useEffect(() => {
     // Your existing logic to fetch and set content
     const queryParams = new URLSearchParams(inputValues).toString();
     apiFetch({ path: `${block.namespace}/${block.name}/preview?${queryParams}` }).then((html) => {
       setContent(html);
       var data = { response: html, block: block };
       const event = new CustomEvent("ewp_dynamic_block_on_change", { detail: data });
       document.dispatchEvent(event);
     });
    }, [JSON.stringify(inputValues)]);

    if (typeof block.attributes === 'object' && block.attributes !== null) {
     var elements = [];
     Object.entries(block.attributes).forEach(([key, data]) => {
      // key is the attribute name
      // value is the attribute value
      // You can use a switch statement to handle different types

      switch (data.render_type) {
       case 'select':
        elements.push(wp.element.createElement(wp.components.SelectControl, {
         label: data.label,
         value: props.attributes[key],
         options: data.options,
         onChange: function (value) {
          setAttributes({ [key]: value });
          handleInputChange(key, value)
         }
        }));
        break;
        case 'color':
          elements.push(wp.element.createElement(wp.components.ColorPicker, {
            label: data.label,
            color: props.attributes[key], // Use 'color' instead of 'value'
            onChangeComplete: function (value) {
              // Extract the color from 'value.hex' instead of 'value' directly
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
         onChange: function (value) { setAttributes({ [key]: value }); handleInputChange(key, value) }
        }));
        break;
       case 'number':
        elements.push(wp.element.createElement(wp.components.RangeControl, {
         label: data.label,
         value: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); handleInputChange(key, value) },
         min: data.attributes.min || 0,
         max: data.attributes.max || 100,
         step: data.attributes.step || 1
        }));
        break;
       case 'string':
        elements.push(wp.element.createElement(wp.components.TextControl, {
         label: data.label,
         value: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); handleInputChange(key, value) }
        }));
        break;
       case 'boolean':
        elements.push(wp.element.createElement(wp.components.ToggleControl, {
         label: data.label,
         checked: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); handleInputChange(key, value) }
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
        dangerouslySetInnerHTML: { __html: content }
       }
      ),
     ];
    }
   },
   save: function () {
    return null;
   },
  });
 });
}