/**
 * Initialize the Recently Seen tracking functionality when the DOM is fully loaded
 * This ensures all elements are available before executing the script
 */
document.addEventListener('DOMContentLoaded', () => {
 ewp_recently();
});


/**
 * Handles the Recently Seen tracking functionality
 * 1. Stores viewed content IDs in browser's localStorage organized by post type
 * 2. Sends an AJAX request to the server to update session-based tracking
 * 
 * The function uses data passed from WordPress via wp_localize_script:
 * - ewpRecentlySeen.id: The current post ID
 * - ewpRecentlySeen.post_type: The current post type
 */
function ewp_recently() {
 // Get current post ID and post type from localized script data
 let id = ewpRecentlySeen.id;
 let post_type = ewpRecentlySeen.post_type;
 
 // Retrieve existing recently seen data from localStorage or initialize empty object
 let seen = JSON.parse(localStorage.getItem('ewp_recently_seen') || '{}');
 
 /*check if seen[post_type] is set*/
 if (!seen[post_type]) {
  // Initialize array for this post type if it doesn't exist
  seen[post_type] = [];
 }
 
 /*check if id exists otherwise add it*/
 if (!seen[post_type].includes(id)) {
  // Add the current post ID to the array for this post type
  seen[post_type].push(id);
  // Save updated data back to localStorage
  localStorage.setItem('ewp_recently_seen', JSON.stringify(seen));
 }
 

 // Prepare AJAX request parameters
 var defaults = {
  method: 'post',
  url: awmGlobals.url + "/wp-json/ewp/v1/recently-seen/"+id,
 };
 
 // Send AJAX request to update server-side recently seen data
 awm_ajax_call(defaults);
}


