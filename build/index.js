(()=>{function e(t){return e="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},e(t)}function t(e,t){var n=Object.keys(e);if(Object.getOwnPropertySymbols){var r=Object.getOwnPropertySymbols(e);t&&(r=r.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),n.push.apply(n,r)}return n}function n(e){for(var n=1;n<arguments.length;n++){var o=null!=arguments[n]?arguments[n]:{};n%2?t(Object(o),!0).forEach((function(t){r(e,t,o[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(o)):t(Object(o)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(o,t))}))}return e}function r(t,n,r){var o;return o=function(t,n){if("object"!=e(t)||!t)return t;var r=t[Symbol.toPrimitive];if(void 0!==r){var o=r.call(t,"string");if("object"!=e(o))return o;throw new TypeError("@@toPrimitive must return a primitive value.")}return String(t)}(n),(n="symbol"==e(o)?o:o+"")in t?Object.defineProperty(t,n,{value:r,enumerable:!0,configurable:!0,writable:!0}):t[n]=r,t}function o(e,t){return function(e){if(Array.isArray(e))return e}(e)||function(e,t){var n=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=n){var r,o,a,l,c=[],i=!0,u=!1;try{if(a=(n=n.call(e)).next,0===t){if(Object(n)!==n)return;i=!1}else for(;!(i=(r=a.call(n)).done)&&(c.push(r.value),c.length!==t);i=!0);}catch(e){u=!0,o=e}finally{try{if(!i&&null!=n.return&&(l=n.return(),Object(l)!==l))return}finally{if(u)throw o}}return c}}(e,t)||function(e,t){if(e){if("string"==typeof e)return a(e,t);var n=Object.prototype.toString.call(e).slice(8,-1);return"Object"===n&&e.constructor&&(n=e.constructor.name),"Map"===n||"Set"===n?Array.from(e):"Arguments"===n||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)?a(e,t):void 0}}(e,t)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()}function a(e,t){(null==t||t>e.length)&&(t=e.length);for(var n=0,r=new Array(t);n<t;n++)r[n]=e[n];return r}var l=wp.blocks.registerBlockType,c=(wp.blockEditor||wp.editor).InspectorControls,i=wp.components,u=i.PanelBody,s=(i.TextControl,i.RangeControl,wp.element),p=s.useEffect,b=s.useState,f=wp.apiFetch;"undefined"!=typeof wp&&wp.blocks&&wp.blockEditor&&wp.components&&wp.element&&"undefined"!=typeof ewp_blocks&&Object.keys(ewp_blocks).forEach((function(t){var a=ewp_blocks[t],i="".concat(a.namespace,"/").concat(a.name);l(i,{title:a.title,icon:a.icon,category:a.category,attributes:a.attributes,edit:function(t){var l=t.attributes,i=t.setAttributes,s=Object.keys(a.attributes).reduce((function(e,t){var n=a.attributes[t];return e[t]=l[t]||n.default||"",e}),{}),m=o(b(""),2),y=m[0],w=m[1],v=o(b(s),2),h=v[0],g=v[1],d=function(e,t){g(n(n({},h),{},r({},e,t)))};if(p((function(){var e=new URLSearchParams(h).toString();f({path:"".concat(a.namespace,"/").concat(a.name,"/preview?").concat(e)}).then((function(e){w(e);var t=new CustomEvent("ewp_dynamic_block_on_change",{detail:{response:e,block:a}});document.dispatchEvent(t)}))}),[JSON.stringify(h)]),"object"===e(a.attributes)&&null!==a.attributes){var O=[];return Object.entries(a.attributes).forEach((function(e){var n=o(e,2),a=n[0],l=n[1];switch(l.render_type){case"select":O.push(wp.element.createElement(wp.components.SelectControl,{label:l.label,value:t.attributes[a],options:l.options,onChange:function(e){i(r({},a,e)),d(a,e)}}));break;case"color":O.push(wp.element.createElement(wp.components.ColorPicker,{label:l.label,color:t.attributes[a],onChangeComplete:function(e){var t=e.hex;i(r({},a,t)),d(a,t)}}));break;case"textarea":O.push(wp.element.createElement(wp.components.TextareaControl,{label:l.label,value:t.attributes[a],onChange:function(e){i(r({},a,e)),d(a,e)}}));break;case"number":O.push(wp.element.createElement(wp.components.RangeControl,{label:l.label,value:t.attributes[a],onChange:function(e){i(r({},a,e)),d(a,e)},min:l.attributes.min||0,max:l.attributes.max||100,step:l.attributes.step||1}));break;case"string":O.push(wp.element.createElement(wp.components.TextControl,{label:l.label,value:t.attributes[a],onChange:function(e){i(r({},a,e)),d(a,e)}}));break;case"boolean":O.push(wp.element.createElement(wp.components.ToggleControl,{label:l.label,checked:t.attributes[a],onChange:function(e){i(r({},a,e)),d(a,e)}}))}})),[wp.element.createElement(c,null,wp.element.createElement(u,{title:a.title+" Settings",initialOpen:!0},O)),wp.element.createElement("div",{className:t.className,dangerouslySetInnerHTML:{__html:y}})]}},save:function(){return null}})}))})();
//# sourceMappingURL=index.js.map