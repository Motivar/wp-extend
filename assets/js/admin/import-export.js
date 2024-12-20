function ewp_init_import_export_button(form) {
 /* Determine the HTTP method based on action_case */
 var action_case = form.querySelector('select[name="case"]').value;
 let method = 'get';
 let form_data;

 switch (action_case) {
  case 'import':
   method = 'post';

   // Check for file inputs
   const hasFileInputs = form.querySelector('input[type="file"]');
   if (hasFileInputs) {
    form_data = new FormData(form); // Use FormData for file uploads
   } else {
    form_data = ewp_jsVanillaSerialize(form); // Use serialized data for non-file forms
   }
   break;
  default:
   // For GET requests, always use serialized data
   form_data = ewp_jsVanillaSerialize(form);
   break;
 }

 console.log(form_data);

 var defaults = {
  form: form,
  data: form_data,
  method: method,
  url: awmGlobals.url + "/wp-json/ewp/v1/" + action_case + "/",
  callback: 'ewp_import_export_callback'
 };
 awm_ajax_call(defaults);
}

/*add form submit event listener*/
document.addEventListener('submit', function (event) {
 var form = document.getElementById('awm-form-ewp-import-export');
 console.log(form);
 if (form !== null) {
  event.preventDefault();
  ewp_init_import_export_button(form);
 }
});

function ewp_import_export_callback(response, options) {


 try {
  // Convert response to a string if it's an object
  const data =
   typeof response === 'object'
    ? JSON.stringify(response, null, 2)
    : response;

  // Determine content type and file extension
  const contentType =
   options.method === 'php' ? 'application/octet-stream' : 'application/json';
  const fileExtension = options.method === 'php' ? 'php' : 'json';

  // Create a file name with a timestamp
  const fileName = `content_export_${new Date()
   .toISOString()
   .replace(/[:.]/g, '-')}.${fileExtension}`;

  // Create a blob from the response data
  const blob = new Blob([data], { type: contentType });

  // Create a URL for the blob
  const url = window.URL.createObjectURL(blob);

  // Create a temporary anchor element to trigger download
  const a = document.createElement('a');
  a.style.display = 'none';
  a.href = url;
  a.download = fileName;

  // Append the anchor to the body, trigger download, and clean up
  document.body.appendChild(a);
  a.click();
  window.URL.revokeObjectURL(url);
  a.remove();
 } catch (error) {
  console.error('Error processing the request:', error);
 }
}