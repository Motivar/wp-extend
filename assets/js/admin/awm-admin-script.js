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


/*
 * Select/Upload image(s) event
 */
jQuery(document).on('click', '.awm_custom_image_upload_image_button', function (e) {
    e.preventDefault();
    var id = jQuery(this).closest('.awm-image-upload').attr('id');
    var selected_image = jQuery(this).attr('data-image');
    var button = jQuery(this),
        custom_uploader = wp.media({
            title: jQuery('#' + id).attr('data-add_label'),
            library: {
                // uncomment the next line if you want to attach image to the current post
                //uploadedTo : wp.media.view.settings.post.id, 
                type: ['video', 'image', 'application/pdf']
            },
            button: {
                text: 'Use this media' // button label text
            },
            multiple: jQuery('#' + id).attr('data-multiple') // for multiple image selection set to true
        });
    custom_uploader.on('select', function () { // it also has "open" and "close" events 
        var attachment = custom_uploader.state().get('selection').first().toJSON();
        jQuery(button).removeClass('button').html('<img class="true_pre_image" src="' + attachment.url + '" style="max-width:95%;display:block;" />').next().val(attachment.id).next().show();
        /* if you sen multiple to true, here is some code for getting the image IDs*/
        if (jQuery('#' + id).attr('data-multiple')) {
            var attachments = frame.state().get('selection'),
                attachment_ids = new Array(),
                i = 0;
            attachments.each(function (attachment) {
                attachment_ids[i] = attachment['id'];
                i++;
            });
        }

    });
    custom_uploader.on('open', function () {
        var selection = custom_uploader.state().get('selection');
        //remove all the selection first
        selection.each(function (image) {
            var attachment = wp.media.attachment(image.attributes.id);
            attachment.fetch();
            selection.remove(attachment ? [attachment] : []);
        });
        if (selected_image) {
            attachment = wp.media.attachment(selected_image);
            attachment.fetch();
            selection.add(attachment ? [attachment] : []);
        }

    });
    custom_uploader.open();
});

/*
 * Remove image event
 */
jQuery(document).on('click', '.awm_custom_image_remove_image_button', function () {
    jQuery(this).hide().prev().val('').prev().addClass('button').html('Insert media');
    return false;
});
/*awm settings*/


/*awm_map*/
var markers = [];
var awm_map_options = [];

function awm_add_map() {
    var map = document.getElementsByClassName("awm_map");
    if (typeof (map) != 'undefined' && map != null && map.length > 0 && typeof (awmGlobals) != 'undefined' && awmGlobals != null && !awm_call_map) {
        awm_call_map = true;
        awm_js_ajax_call(awmGlobals.url + '/wp-json/extend-wp/v1/awm-map-options/', 'awm_call_maps_api');
    }
}


function awm_call_maps_api(data) {
    awm_map_options = JSON.parse(data);
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
    var elems = document.querySelectorAll('.awm-meta-field select,.awm-term-input select');
    if (elems) {
        elems.forEach(function (elem) {
            if (elem.id != '' && !elem.getAttribute('data-ssid') && !elem.getAttribute('awm-template') && !elem.getAttribute('awm-skip-selectr')) {
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

    var form_data = jsVanillaSerialize(document.getElementById(form));

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



jQuery(document).ready(function ($) {
    var frame;

    $('.awm-upload-button').on('click', function (e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Select Images',
            button: { text: 'Use these images' },
            multiple: true
        });
        frame.open();
        frame.on('select', function () {
            var selection = frame.state().get('selection');
            var imageIds = [];
            selection.each(function (attachment) {
                imageIds.push(attachment.id);

                // Create image element and append to the list
                var imageUrl = attachment.attributes.url;
                $('#' + id + '-gallery .awm-gallery-images-list').append(
                    '<li class="awm-gallery-image" data-image-id="' + attachment.id + '">' +
                    '<img src="' + imageUrl + '" style="width:100px;height:100px;">' +
                    '<a href="#" class="awm-remove-image">Remove</a>' +
                    '<input type="hidden" name="' + id + '[]" value="' + attachment.id + '">' +
                    '</li>'
                );
            });
        });
    });

    // Remove image from the gallery
    $('body').on('click', '.awm-remove-image', function (e) {
        e.preventDefault();
        var id = $(this).closest('.awm-upload-button').attr('data-id');
        var $li = $(this).closest('li.awm-gallery-image');
        var removedImageId = $li.data('image-id');
        $li.remove();

        // Update the hidden input field
        var updatedIds = [];
        $('#' + id + '-gallery .awm-gallery-images-list li.awm-gallery-image').each(function () {
            updatedIds.push($(this).data('image-id'));
        });
        $('#' + id).val(updatedIds.join(','));
    });

    var galleries = jQuery('.awm-gallery-images-list');
    if (galleries.length > 0) {
        galleries.each(function () {
            var id = jQuery(this).closest('.awm-upload-button').attr('data-id');
            // Make the gallery images list sortable
            jQuery(this).sortable({
                placeholder: "ui-state-highlight",
                update: function (event, ui) {
                    //var sortedIds = $(this).sortable('toArray', { attribute: 'data-image-id' });
                    //$('#' + id).val(sortedIds.join(','));
                }
            });
        });
    }


});