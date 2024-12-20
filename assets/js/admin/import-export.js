async function ewp_init_import_export_button(form) {
  var action_case = form.querySelector('select[name="case"]').value;
  let method = 'get';
  let form_data = ewp_jsVanillaSerialize(form);
  let import_data;

  switch (action_case) {
    case 'import':
      method = 'post';
      var logDiv = document.getElementById('import-message');
      logDiv.innerHTML = '';
      const fileInput = form.querySelector('input[type="file"]');
      if (fileInput && fileInput.files.length > 0) {
        const reader = new FileReader();
        reader.onload = async function (event) {
          let fileContent = event.target.result;
          try {
            let form_data = ewp_jsVanillaSerialize(form, true);
            const jsonData = JSON.parse(fileContent);

            for (const key of Object.keys(jsonData)) {
              if (key !== 'modified') {
                const contentData = jsonData[key];
                const entries = Object.keys(contentData).length;
                logDiv.innerHTML += `<div>`;
                logDiv.innerHTML += `<p>Importing <b>${entries}</b> entries for <b>${key}</b></p>`;
                import_data = { ...form_data, content_type: key, content: contentData, length: entries };

                try {
                  const response = await sendRequest(import_data, method, action_case);
                  logDiv.innerHTML += `<p>Successfully imported <b>${entries}</b> entries for <b>${key}</b></p></div>`;
                } catch (error) {
                  logDiv.innerHTML += `<p>Error importing entries for <b>${key}</b>: ${error.message || error}</p></div>`;
                }
              }
            }
            logDiv.innerHTML += "<p>All imports completed.</p>";
          } catch (error) {
            console.error("Error parsing JSON file:", error);
            logDiv.innerHTML = "Invalid JSON file.";
          }
        };
        reader.readAsText(fileInput.files[0]);
        return;
      }
      logDiv.innerHTML = "Please upload a file.";
      return;
  }

  // Proceed to send the request for non-import actions
  sendRequest(form_data, method, action_case);
}



function sendRequest(form_data, method, action_case) {
  return new Promise((resolve, reject) => {
    var defaults = {
      data: form_data,
      method: method,
      url: awmGlobals.url + "/wp-json/ewp/v1/" + action_case + "/",
      callback: function (response, options) {
        if (response) {
          try {
            switch (method) {
              case 'get':
                prepare_file(response, options);
                break;
            }
            resolve(response); // Resolve the promise after processing
          } catch (error) {
            reject({
              message: `Error processing response: ${error.message}`,
              response,
            });
          }
        } else {
          reject({
            message: response?.error || 'Unknown error occurred.',
            response,
          });
        }
      },
      log: true,
    };

    awm_ajax_call(defaults);
  });
}


/*add form submit event listener*/
document.addEventListener('submit', function (event) {
  var form = document.getElementById('awm-form-ewp-import-export');
 if (form !== null) {
  event.preventDefault();
  ewp_init_import_export_button(form);
 }
});




function import_content() {
  var form = document.getElementById('awm-form-awm_import_options');
  if (form) {
    var file = document.getElementById('contents_file');
    file.addEventListener('change', function () {
      // Create a new FileReader() object
      let reader = new FileReader();
      // Setup the callback event to run when the file is read
      reader.onload = logFile;
      reader.readAsText(file.files[0]);

    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      awm_import_content(true);
      return false;
    });

  }
}

function prepare_file(response, options) {
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