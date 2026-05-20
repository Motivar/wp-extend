/**
 * Maps Module
 * Handles Google Maps initialization and marker management
 * Lazy-loaded only when map fields are present
 */

let markers = [];
let awm_map_options = [];
let awm_call_map = false;

/**
 * Initialize maps if present on the page
 */
function awm_add_map() {
    const map = document.getElementsByClassName("awm_map");
    if (typeof (map) != 'undefined' && map != null && map.length > 0 && typeof (awmGlobals) != 'undefined' && awmGlobals != null && !awm_call_map) {
        awm_call_map = true;
        awm_ajax_call({
            method: 'GET',
            url: awmGlobals.url + '/wp-json/extend-wp/v1/awm-map-options/',
            callback: 'awm_call_maps_api'
        });
    }
}

/**
 * Load Google Maps API and initialize maps
 * @param {Object} data Map options from API
 */
function awm_call_maps_api(data) {
    awm_map_options = data;
    let src = "//maps.googleapis.com/maps/api/js?libraries=places&callback=awmInitMap";
    if (awm_map_options.key !== null) {
        src += '&key=' + awm_map_options.key;
    }
    const script = document.createElement("script");
    script.type = "text/javascript";
    script.src = src;
    script.async = true;
    script.defer = true;
    document.body.appendChild(script);
}

/**
 * Initialize all Google Maps on the page
 */
function awmInitMap() {
    const maps = document.getElementsByClassName("awm_map");

    for (let i = 0; i < maps.length; i++) {
        const map_id = maps[i].id;
        const myLatlng = {
            lat: parseFloat(document.getElementById(map_id + '_lat').value !== '' ? document.getElementById(map_id + '_lat').value : awm_map_options['lat']),
            lng: parseFloat(document.getElementById(map_id + '_lat').value !== '' ? document.getElementById(map_id + '_lng').value : awm_map_options['lng'])
        };

        const map = new google.maps.Map(document.getElementById(map_id), {
            zoom: 10,
            center: myLatlng,
        });
        const marker = new google.maps.Marker({
            position: myLatlng,
            map: map
        });
        markers.push(marker);
        google.maps.event.addListener(map, 'click', function (event) {
            placeMarker(map, event.latLng, map_id);
        });

        // Search box initialization
        const input = document.getElementById(map_id + '_search_box');
        const searchBox = new google.maps.places.SearchBox(input);

        // Bias the SearchBox results towards current map's viewport
        map.addListener('bounds_changed', function () {
            searchBox.setBounds(map.getBounds());
        });
        searchBox.addListener('places_changed', function () {
            const places = searchBox.getPlaces();

            if (places.length == 0) {
                return;
            }

            // For each place, get the icon, name and location
            let bounds = new google.maps.LatLngBounds();
            places.forEach(function (place) {
                if (!place.geometry) {
                    console.log("Returned place contains no geometry");
                    return;
                }

                placeMarker(map, place.geometry.location, map_id);
                map.fitBounds(bounds);
            });
        });
    }
}

/**
 * Remove all markers from the map
 */
function removeMarkers() {
    for (let i = 0; i < markers.length; i++) {
        markers[i].setMap(null);
    }
}

/**
 * Place a marker on the map and update location fields
 * @param {Object} map Google Maps instance
 * @param {Object} location LatLng location
 * @param {string} map_id Map element ID
 */
function placeMarker(map, location, map_id) {
    removeMarkers();
    
    // Publish coordinates to hidden fields
    document.getElementById(map_id + '_lat').value = location.lat();
    document.getElementById(map_id + '_lng').value = location.lng();

    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({
        'latLng': location,
    }, function (results, status) {
        if (status == google.maps.GeocoderStatus.OK) {
            if (results[0]) {
                document.getElementById(map_id + '_address').value = results[0].formatted_address;
                document.getElementById(map_id + '_search_box').value = results[0].formatted_address;
            } else {
                document.getElementById(map_id + '_address').value = '-';
                document.getElementById(map_id + '_search_box').value = '-';
            }
        }
    });

    // Create and place marker
    const marker = new google.maps.Marker({
        position: location,
        map: map
    });

    markers.push(marker);
    map.panTo(marker.getPosition());
    map.fitBounds();
}

/**
 * Prevent Enter key submission in search box
 */
function noenter() {
    return !(window.event && window.event.keyCode == 13);
}

// Export functions
export {
    awm_add_map,
    awm_call_maps_api,
    awmInitMap,
    removeMarkers,
    placeMarker,
    noenter
};
