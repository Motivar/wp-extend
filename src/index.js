const { registerBlockType } = wp.blocks;
const { InspectorControls } = wp.blockEditor || wp.editor;
const { PanelBody, TextControl, ToggleControl, RangeControl, SelectControl, TextareaControl, ColorPicker } = wp.components;
const { useEffect, useState } = wp.element;
const { apiFetch } = wp;
const { RichText } = wp.blockEditor;


if (typeof wp !== 'undefined' && wp.blocks && wp.blockEditor && wp.components && wp.element && typeof ewp_blocks !== 'undefined') {
  Object.keys(ewp_blocks).forEach((key) => {
    const block = ewp_blocks[key];
    const namespace = `${block.namespace}/${block.name}`;
    console.log('Registering block:', namespace);
    console.log('Block attributes:', block.attributes);

    registerBlockType(namespace, {
      title: block.title,
      icon: block.icon,
      category: block.category,
      attributes: {
        ...block.attributes,
        isValid: { type: 'boolean', default: true }, // Add validation as an attribute
      },
      edit: function EditBlock(props) {
        const { attributes, setAttributes } = props;

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
          // For select controls, we're now receiving the direct value (not an object)
          // because we transformed the options format to use value/label pairs
          const attributeData = block.attributes[identifier];
          
          setInputValues({
            ...inputValues,
            [identifier]: newValue, // Store the value in UI state
          });
          
          // Save the value directly to block attributes
          setAttributes({ [identifier]: newValue });

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

        // Render inputs for each attribute
        const renderInputs = () => {
          return Object.entries(block.attributes).map(([key, data]) => {
            const label = data.required ? `${data.label} *` : data.label;

            // Explanation text with the correct positioning
            const explanation = data.explanation ? wp.element.createElement(
              'small',
              {
                style: {
                  display: 'block',
                  marginTop: '0px', // No margin at the top
                  marginBottom: '10px', // Margin before the input
                  letterSpacing: 'normal', // Normal letter spacing
                  color: '#555',
                  lineHeight: '1.5', // Optional: makes text more readable
                },
              },
              data.explanation
            ) : null;

            // Switch based on render_type and input type
            switch (data.render_type) {
              case 'color':
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    <ColorPicker
                      color={props.attributes[key] || ''}
                      onChangeComplete={(value) => handleInputChange(key, value.hex)}
                      disableAlpha
                    />
                  </div>
                );
              case 'textarea':
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    {data.wp_editor ? (
                      <RichText
                        tagName="p"
                        value={props.attributes[key] || ''}
                        onChange={(value) => handleInputChange(key, value)}
                        placeholder={data.placeholder || ''}
                        style={{
                          border: '1px solid #ccd0d4',
                          padding: '10px',
                          borderRadius: '4px',
                          backgroundColor: '#fff',
                          minHeight: '150px',
                        }}
                      />
                    ) : (
                        <TextareaControl
                          value={props.attributes[key] || ''}
                          onChange={(value) => handleInputChange(key, value)}
                          style={{
                          border: '1px solid #ccd0d4',
                          padding: '10px',
                          borderRadius: '4px',
                          backgroundColor: '#fff',
                          minHeight: '150px',
                        }}
                      />
                    )}
                  </div>
                );
              case 'boolean':
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    <ToggleControl
                      checked={!!props.attributes[key]}
                      onChange={(value) => handleInputChange(key, value)}
                    />
                  </div>
                );
              case 'string':
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    <TextControl
                      value={props.attributes[key] || ''}
                      onChange={(value) => handleInputChange(key, value)}
                    />
                  </div>
                );
              case 'number':
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    <RangeControl
                      value={props.attributes[key] || 0}
                      onChange={(value) => handleInputChange(key, value)}
                      min={data.min || 0}
                      max={data.max || 100}
                    />
                  </div>
                );
              case 'select':
                // Transform options to the format expected by WordPress SelectControl
                const selectOptions = Array.isArray(data.options) ? data.options.map(opt => ({
                  value: opt.option,
                  label: opt.label
                })) : [];
                
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation} {/* Explanation text between the label and input */}
                    <SelectControl
                      value={props.attributes[key] || ''}
                      options={selectOptions}
                      onChange={(value) => handleInputChange(key, value)}
                    />
                  </div>
                );
              default:
                return null;
            }
          });
        };

        return (
          <>
            <InspectorControls>
              <PanelBody title={block.title + ' Settings'} initialOpen={true}>
                {renderInputs()}
              </PanelBody>
            </InspectorControls>

            <div className={props.className}>
              {Object.keys(errorMessages).map((key) => (
                <p key={key} style={{ color: 'red' }}>{errorMessages[key]}</p>
              ))}
              <div dangerouslySetInnerHTML={{ __html: content }} />
            </div>
          </>
        );
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