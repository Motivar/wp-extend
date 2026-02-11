const { registerBlockType } = wp.blocks;
const { InspectorControls } = wp.blockEditor || wp.editor;
const { PanelBody, TextControl, ToggleControl, RangeControl, SelectControl, TextareaControl, ColorPicker, Button } = wp.components;
const { useEffect, useState } = wp.element;
const { apiFetch } = wp;
const { RichText } = wp.blockEditor;
const { MediaUpload, MediaUploadCheck } = wp.blockEditor;


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
        const [imageDataCache, setImageDataCache] = useState({});  // Cache for gallery image data

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

        // Helper function to fetch image data for gallery fields
        const fetchImageData = async (imageIds) => {
          const newImageData = {};
          
          for (const imageId of imageIds) {
            if (!imageDataCache[imageId]) {
              try {
                const response = await apiFetch({
                  path: `/wp/v2/media/${imageId}`
                });
                newImageData[imageId] = {
                  url: response.media_details?.sizes?.thumbnail?.source_url || response.source_url,
                  alt: response.alt_text || `Image ${imageId}`
                };
              } catch (error) {
                console.warn(`Failed to fetch image data for ID ${imageId}:`, error);
                newImageData[imageId] = {
                  url: '',
                  alt: `Image ${imageId}`
                };
              }
            }
          }
          
          if (Object.keys(newImageData).length > 0) {
            setImageDataCache(prev => ({ ...prev, ...newImageData }));
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

        const getNumberSetting = (attributeData, settingKey, defaultValue) => {
          if (!attributeData || typeof attributeData !== 'object') {
            return defaultValue;
          }

          const directValue = attributeData[settingKey];
          const nestedValue = attributeData.attributes && attributeData.attributes[settingKey];
          const rawValue = (directValue ?? nestedValue);

          if (rawValue === '' || rawValue === null || typeof rawValue === 'undefined') {
            return defaultValue;
          }

          const parsed = Number(rawValue);
          return Number.isFinite(parsed) ? parsed : defaultValue;
        };

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
                      value={props.attributes[key] ?? 0}
                      onChange={(value) => handleInputChange(key, value)}
                      min={getNumberSetting(data, 'min', 0)}
                      max={getNumberSetting(data, 'max', 100)}
                      step={getNumberSetting(data, 'step', 1)}
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
              case 'gallery':
                const galleryImages = props.attributes[key] || [];
                const imageIds = Array.isArray(galleryImages) ? galleryImages : [];
                
                // Fetch image data when component mounts or imageIds change
                useEffect(() => {
                  if (imageIds.length > 0) {
                    fetchImageData(imageIds);
                  }
                }, [imageIds.join(',')]);
                
                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation}
                    
                    <MediaUploadCheck>
                      <MediaUpload
                        onSelect={(media) => {
                          const newImageIds = media.map(item => item.id);
                          handleInputChange(key, newImageIds);
                        }}
                        allowedTypes={['image']}
                        multiple={true}
                        gallery={true}
                        value={imageIds}
                        render={({ open }) => (
                          <div>
                            {imageIds.length > 0 && (
                              <div style={{ 
                                display: 'grid', 
                                gridTemplateColumns: 'repeat(auto-fill, minmax(80px, 1fr))', 
                                gap: '8px',
                                marginBottom: '15px'
                              }}>
                                {imageIds.map((imageId, index) => {
                                  const imgData = imageDataCache[imageId];
                                  return (
                                    <div key={imageId} style={{ 
                                      position: 'relative',
                                      width: '80px',
                                      height: '80px'
                                    }}>
                                      {imgData?.url ? (
                                        <img 
                                          src={imgData.url}
                                          alt={imgData.alt}
                                          style={{ 
                                            width: '100%', 
                                            height: '100%', 
                                            objectFit: 'cover',
                                            borderRadius: '4px',
                                            border: '1px solid #ddd'
                                          }}
                                        />
                                      ) : (
                                        <div style={{
                                          width: '100%',
                                          height: '100%',
                                          backgroundColor: '#f0f0f0',
                                          borderRadius: '4px',
                                          border: '1px solid #ddd',
                                          display: 'flex',
                                          alignItems: 'center',
                                          justifyContent: 'center',
                                          fontSize: '12px',
                                          color: '#666'
                                        }}>
                                          Loading...
                                        </div>
                                      )}
                                      <button
                                        onClick={(e) => {
                                          e.preventDefault();
                                          e.stopPropagation();
                                          const newImageIds = imageIds.filter(id => id !== imageId);
                                          handleInputChange(key, newImageIds);
                                        }}
                                        type="button"
                                        style={{
                                          position: 'absolute',
                                          top: '2px',
                                          right: '2px',
                                          width: '18px',
                                          height: '18px',
                                          padding: '0',
                                          margin: '0',
                                          border: '1px solid white',
                                          borderRadius: '50%',
                                          backgroundColor: '#dc3545',
                                          color: 'white',
                                          fontSize: '12px',
                                          fontWeight: 'bold',
                                          lineHeight: '16px',
                                          display: 'flex',
                                          alignItems: 'center',
                                          justifyContent: 'center',
                                          cursor: 'pointer',
                                          boxShadow: '0 1px 3px rgba(0,0,0,0.3)',
                                          zIndex: '10'
                                        }}
                                        onMouseEnter={(e) => {
                                          e.target.style.backgroundColor = '#c82333';
                                        }}
                                        onMouseLeave={(e) => {
                                          e.target.style.backgroundColor = '#dc3545';
                                        }}
                                      >
                                        ×
                                      </button>
                                    </div>
                                  );
                                })}
                              </div>
                            )}
                            
                            <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
                              <Button 
                                onClick={open}
                                variant="secondary"
                              >
                                {imageIds.length > 0 ? 'Edit Gallery' : 'Select Images'}
                              </Button>
                              
                              {imageIds.length > 0 && (
                                <Button 
                                  onClick={() => handleInputChange(key, [])}
                                  variant="secondary"
                                  isDestructive={true}
                                >
                                  Clear All Images
                                </Button>
                              )}
                            </div>
                          </div>
                        )}
                      />
                    </MediaUploadCheck>
                  </div>
                );
              case 'image':
                const singleImageId = props.attributes[key] || '';

                // Fetch image data for single image
                useEffect(() => {
                  if (singleImageId) {
                    fetchImageData([parseInt(singleImageId, 10)]);
                  }
                }, [singleImageId]);

                const singleImgData = singleImageId ? imageDataCache[parseInt(singleImageId, 10)] : null;

                return (
                  <div key={key}>
                    <label style={{ display: 'block', marginBottom: '4px' }}>{label}</label>
                    {explanation}

                    <MediaUploadCheck>
                      <MediaUpload
                        onSelect={(media) => {
                          handleInputChange(key, String(media.id));
                        }}
                        allowedTypes={['image']}
                        multiple={false}
                        gallery={false}
                        value={singleImageId ? parseInt(singleImageId, 10) : undefined}
                        render={({ open }) => (
                          <div>
                            {singleImageId && (
                              <div style={{
                                position: 'relative',
                                width: '120px',
                                height: '120px',
                                marginBottom: '10px'
                              }}>
                                {singleImgData?.url ? (
                                  <img
                                    src={singleImgData.url}
                                    alt={singleImgData.alt}
                                    style={{
                                      width: '100%',
                                      height: '100%',
                                      objectFit: 'cover',
                                      borderRadius: '4px',
                                      border: '1px solid #ddd'
                                    }}
                                  />
                                ) : (
                                  <div style={{
                                    width: '100%',
                                    height: '100%',
                                    backgroundColor: '#f0f0f0',
                                    borderRadius: '4px',
                                    border: '1px solid #ddd',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontSize: '12px',
                                    color: '#666'
                                  }}>
                                    Loading...
                                  </div>
                                )}
                                <button
                                  onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    handleInputChange(key, '');
                                  }}
                                  type="button"
                                  style={{
                                    position: 'absolute',
                                    top: '2px',
                                    right: '2px',
                                    width: '18px',
                                    height: '18px',
                                    padding: '0',
                                    margin: '0',
                                    border: '1px solid white',
                                    borderRadius: '50%',
                                    backgroundColor: '#dc3545',
                                    color: 'white',
                                    fontSize: '12px',
                                    fontWeight: 'bold',
                                    lineHeight: '16px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    cursor: 'pointer',
                                    boxShadow: '0 1px 3px rgba(0,0,0,0.3)',
                                    zIndex: '10'
                                  }}
                                  onMouseEnter={(e) => {
                                    e.target.style.backgroundColor = '#c82333';
                                  }}
                                  onMouseLeave={(e) => {
                                    e.target.style.backgroundColor = '#dc3545';
                                  }}
                                >
                                  ×
                                </button>
                              </div>
                            )}

                            <Button
                              onClick={open}
                              variant="secondary"
                            >
                              {singleImageId ? 'Change Image' : 'Select Image'}
                            </Button>
                          </div>
                        )}
                      />
                    </MediaUploadCheck>
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