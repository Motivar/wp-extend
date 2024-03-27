const { registerBlockType } = wp.blocks;
const { InspectorControls } = wp.blockEditor || wp.editor;
const { PanelBody, TextControl, RangeControl } = wp.components;
const { useEffect, useState } = wp.element;
const { apiFetch } = wp;

if (ewp_blocks) {
 Object.keys(ewp_blocks).forEach((key) => {
  let block = ewp_blocks[key];
  let namespace = block.namespace + '/' + block.name;
  console.log(block);
  registerBlockType(namespace, {
   title: block.title,
   icon: block.icon,
   category: block.category,
   attributes: block.attributes,
   edit: function (props) {
    const { attributes, setAttributes } = props;
    const { taxonomy_ids, count } = attributes;
    const [content, setContent] = useState('');
    /*loop throught object block.attributes in order to create the panel*/

    //console.log('attributes', attributes);

    /*
    useEffect(() => {
     // Construct query parameters
     const queryParams = new URLSearchParams({
      taxonomy_ids: taxonomy_ids,
      count: count,
     }).toString();

     apiFetch({ path: `/mtv-reviews/v1/preview?${queryParams}` }).then((html) => {
      var data = JSON.parse(html.replace(/\'/g, '\"'));
      setContent(data);
     });
    }, [taxonomy_ids, count]); // Re-fetch whenever these attributes change
    */

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
         onChange: function (value) { setAttributes({ [key]: value }); }
        }));
        break;
       case 'color':
        elements.push(wp.element.createElement(wp.components.ColorPicker, {
         label: data.label,
         value: props.attributes[key],
         onChangeComplete: function (value) { setAttributes({ [key]: value }); }
        }));
        break;
       case 'textarea':
        elements.push(wp.element.createElement(wp.components.TextareaControl, {
         label: data.label,
         value: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); }
        }));
        break;
       case 'number':
        console.log('number');
        elements.push(wp.element.createElement(wp.components.RangeControl, {
         label: data.label,
         value: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); },
         min: 1,
         max: 10,
         step: 1
        }));
        break;
       case 'string':
        elements.push(wp.element.createElement(wp.components.TextControl, {
         label: data.label,
         value: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); }
        }));
        break;
       case 'boolean':
        elements.push(wp.element.createElement(wp.components.ToggleControl, {
         label: data.label,
         checked: props.attributes[key],
         onChange: function (value) { setAttributes({ [key]: value }); }
        }));
        break;
       // Add more cases as needed
       default:
        // Handle other types
        break;
      }
     });


     /*
     elements.push(wp.element.createElement(TextControl, {
      label: 'Taxonomy ddddddIDs',
      value: taxonomy_ids,
      //onChange: updateTaxonomyIds,
     }));
     elements.push(wp.element.createElement(RangeControl, {
      label: 'Number of Reviddddewds',
      value: count,
      //onChange: updateCount,
      min: 1,
      max: 10
     }));*/


     return [
      // InspectorControls for sidebar settings
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

      // Display the fetched content or a placeholder in the editor
      wp.element.createElement(
       'div',
       {
        className: props.className,
        dangerouslySetInnerHTML: { __html: content } // This prop renders the HTML
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