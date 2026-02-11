/*inits*/
var awm_call_map = false;

function awm_init_inputs() {
    awm_add_map();
    awmCallbacks();
    awm_create_calendar();
    awmSelectrBoxes();
    awmInitForms();
    awmMultipleCheckBox();
}
awm_init_inputs();


jQuery('div.widgets-sortables').bind('sortstop', function (event, ui) {
    awm_init_inputs();
});


/*awm settings*/


/*awm_map*/
var markers = [];
var awm_map_options = [];

function awm_add_map() {
    var map = document.getElementsByClassName("awm_map");
    if (typeof (map) != 'undefined' && map != null && map.length > 0 && typeof (awmGlobals) != 'undefined' && awmGlobals != null && !awm_call_map) {
        awm_call_map = true;
        awm_ajax_call({
            method: 'GET',
            url: awmGlobals.url + '/wp-json/extend-wp/v1/awm-map-options/',
            callback: 'awm_call_maps_api'
        });
    }
}


function awm_call_maps_api(data) {
    awm_map_options = data;
    var src = "//maps.googleapis.com/maps/api/js?libraries=places&callback=awmInitMap";
    if (awm_map_options.key !== null) {
        src += '&key=' + awm_map_options.key;
    }
    var a = document.createElement("script");
    a.type = "text/javascript";
    a.src = src;
    a.async = !0;
    a.defer = !0
    document.body.appendChild(a)
}

function awmInitMap() {

    var maps = document.getElementsByClassName("awm_map");

    for (i = 0; i < maps.length; i++) {
        var map_id = maps[i].id;
        var myLatlng = {
            lat: parseFloat(document.getElementById(map_id + '_lat').value !== '' ? document.getElementById(map_id + '_lat').value : awm_map_options['lat']),
            lng: parseFloat(document.getElementById(map_id + '_lat').value !== '' ? document.getElementById(map_id + '_lng').value : awm_map_options['lng'])
        };

        var map = new google.maps.Map(document.getElementById(map_id), {
            zoom: 10,
            center: myLatlng,
        });
        var marker = new google.maps.Marker({
            position: myLatlng,
            map: map
        });
        markers.push(marker);
        google.maps.event.addListener(map, 'click', function (event) {
            placeMarker(map, event.latLng, map_id);
        });
        /*search box*/
        var input = document.getElementById(map_id + '_search_box');
        var searchBox = new google.maps.places.SearchBox(input);

        // Bias the SearchBox results towards current map's viewport.
        map.addListener('bounds_changed', function () {
            searchBox.setBounds(map.getBounds());
        });
        searchBox.addListener('places_changed', function () {
            var places = searchBox.getPlaces();

            if (places.length == 0) {
                return;
            }

            // For each place, get the icon, name and location.
            bounds = new google.maps.LatLngBounds();
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

function removeMarkers() {
    for (i = 0; i < markers.length; i++) {
        markers[i].setMap(null);
    }
}

function placeMarker(map, location, map_id) {
    removeMarkers();
    /*publish inputs to the hidden fields*/
    document.getElementById(map_id + '_lat').value = location.lat();
    document.getElementById(map_id + '_lng').value = location.lng();

    var geocoder = new google.maps.Geocoder();
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


    /*puublish the marker*/
    var marker = new google.maps.Marker({
        position: location,
        map: map
    });

    markers.push(marker);
    map.panTo(marker.getPosition());
    map.fitBounds();
}

function noenter() {
    return !(window.event && window.event.keyCode == 13);
}






function awmSelectrBoxes() {
    var elems = document.querySelectorAll('.awm-meta-field select,.awm-term-input select,.awm-user-input select');
    if (elems) {
        elems.forEach(function (elem) {
            if (elem.id != '' && !elem.getAttribute('data-id') && !elem.getAttribute('awm-template') && !elem.getAttribute('awm-skip-selectr')) {
                awm_selectr_box(elem);
            }
        });
    }
}







/**
 * 
 * get the query additional informations
 * @param {*} element the div to show
 */
function awm_get_query_fields(element) {
    if (!element.disabled) {
        var id = document.getElementById('ewp_content_id').value;
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name, meta: 'query_fields' },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-query-fields/?meta=query_fields&id=" + id + "&field=" + element.value + "&name=" + input_name,
            callback: 'awm_show_query_details',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_query_details(data, options) {

    var name = options.data.name.replace('query_fields', '').replace('[query_type]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-query_fields-' + name + ' .awm-query-type-configuration');
    if (element) {
        element.innerHTML = data;
    }
    awm_init_inputs();
}


function awm_get_case_fields(element) {
    if (!element.disabled) {

        var meta = element.getAttribute('input-name');
        var id = document.getElementById('ewp_content_id').value;
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name, meta: meta },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-case-fields/?meta=" + meta + "&id=" + id + "&field=" + element.value + "&name=" + input_name,
            callback: 'awm_show_field_details',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_field_details(data, options) {
    var name = options.data.name.replace(options.data.meta, '').replace('[case]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-' + options.data.meta + '-' + name + ' .awm-field-type-configuration');
    if (element) {
        element.innerHTML = data;
    }
    awm_init_inputs();
}


function awm_get_position_settings(element) {
    var id = document.getElementById('ewp_content_id').value;
    if (!element.disabled) {
        var input_name = element.getAttribute('name');
        var defaults = {
            data: { name: input_name },
            method: 'get',
            url: awmGlobals.url + "/wp-json/extend-wp/v1/get-position-fields/?id=" + id + "&position=" + element.value + "&name=" + input_name,
            callback: 'awm_show_position_settings',
        };
        awm_ajax_call(defaults);
    }
}

function awm_show_position_settings(data, options) {
    var name = options.data.name.replace('awm_positions', '').replace('[case]', '').replace('[', '').replace(']', '');
    var element = document.querySelector('#awm-awm_positions-' + name + ' .awm-position-configuration');
    if (element) {
        element.innerHTML = data;
    }
    setTimeout(() => {
        awm_init_inputs();
    }, 100);

}

function ewp_get_php_code(id) {

    var defaults = {
        method: 'get',
        url: awmGlobals.url + "/wp-json/extend-wp/v1/get-php-code/?awm_post_id=" + id,
        callback: 'awm_show_php_code',
    };
    awm_ajax_call(defaults);

}

function awm_show_php_code(data) {
    var element = document.getElementById('awm-php-code');
    if (element) {
        element.innerHTML = data;
    }
}





/**
 * with this function we create a rest options page entry
 * @param form string the name of the form
 * @param endpoint the endpoint to send results
 * @param callback the callback to manipulate the data
 * @param method the method to send the data
 */
function awm_options_rest_call(form, endpoint, callback, method) {
    window.event.preventDefault();
    window.event.stopPropagation();
    if (!document.getElementById(form)) {
        console.log('no such form');
    }

    var form_data = ewp_jsVanillaSerialize(document.getElementById(form));

    var defaults = {
        method: method,
        data: form_data,
        url: awmGlobals.url + "/wp-json/" + endpoint,
        callback: callback,
        loading: '#awm-rest-options-results',
    }
    awm_ajax_call(defaults);
}
/**
 * with this function we show the rest data of the call
 * @param data json_aray with the return of the rest
 */
function awm_rest_options_callback(data) {
    document.getElementById('awm-rest-options-results').innerHTML = data;
}



/* Gallery and image upload logic now handled by AWMMediaField class (class-awm-media-field.js) */