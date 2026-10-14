
# Extend WP Search Filters

**Version:** 1.0.0  
**Author:** Your Name  
**License:** GPLv2 or later  
**Tags:** WordPress, Search Filters, REST API  

## Description

Extend WP Search Filters is a powerful and flexible tool for creating custom search filters in WordPress. It allows developers to define search filters with multiple configuration options, integrate with REST API endpoints, and display search forms with a shortcode.

## Features

- Define custom search filters using taxonomy, meta fields, or post properties.
- Integrate with REST API endpoints for dynamic filtering.
- Fully customizable search forms.
- Supports sorting and pagination.
- Async or non-async search methods.
- WPML compatible for multilingual setups.
- Developer-friendly with hooks and filters for customization.

## Usage

### Shortcode
Use the `[ewp_search id="filter_id"]` shortcode to display a search form on any page or post. Replace `filter_id` with the ID of the configured search filter.

Example:
```html
[ewp_search id="1"]
```

### Hooks and Filters

#### Filters
1. **`ewp_search_query_filter`**
   - **Description:** Modify WP_Query arguments for the search.
   - **Parameters:**
     - `$args` (array): WP_Query arguments.
     - `$params` (array): REST API request parameters.
     - `$conf` (array): Filter configuration.

2. **`ewp_search_prepare_form_fields_filter`**
   - **Description:** Customize form fields for the search filter.
   - **Parameters:**
     - `$form_fields` (array): Prepared form fields.
     - `$input_fields` (array): Raw input fields.
     - `$id` (string): Filter ID.

3. **`ewp_search_fields_configuration_filter`**
   - **Description:** Modify fields configuration for the search filter.
   - **Parameters:**
     - `$metas` (array): Fields configuration.

4. **`ewp_search_sorting_filter`**
   - **Description:** Customize sorting options in the search form.
   - **Parameters:**
     - `$box` (array): Sorting form fields.
     - `$params` (array): Request parameters.

5. **`ewp_query_fields_filter`**
   - **Description:** Modify query fields available for search configuration.
   - **Parameters:**
     - `$fields` (array): Query fields configuration.

6. **`ewp_search_result_path`**
   - **Description:** Modify the path of the displayed result cards.
   - **Parameters:**
     - `$path` (string): Path to the result card template.
     - `$wp_query` (object): WP_Query instance.

7. **`ewp_search_result_pagination_path`**
   - **Description:** Modify the path of the pagination template.
   - **Parameters:**
     - `$path` (string): Path to the pagination template.
     - `$wp_query` (object): WP_Query instance.

#### Actions
1. **`awm_register_content_db`**
   - **Description:** Register custom content configurations in the database.

### REST API

Each search filter automatically registers a REST API endpoint. The endpoint is structured as:
```
/wp-json/ewp-filter/v1/{filter_id}
```

Example:
```
GET /wp-json/ewp-filter/v1/1
```

#### Parameters
- `id` (string): The ID of the search filter.
- Additional parameters based on the filter configuration.

### Sorting
To enable sorting, configure sorting options in the search filter settings. Sorting is displayed in the form as a dropdown with options defined by the configuration.

### WPML Compatibility
Add a `lang` parameter to REST API requests or include a hidden `lang` field in the search form for WPML compatibility.

## Developer Notes

- **Change the card path of displayed results:**  
  ```php
  add_filter("ewp_search_result_path", $path, $wp_query);
  ```
- **Change the card pagination path:**  
  ```php
  add_filter("ewp_search_result_pagination_path", $path, $wp_query);
  ```
- **Trigger an event after results load (JavaScript):**  
  ```javascript
  document.addEventListener("ewp_search_results_loaded", function(e) {});
  ```
- **Reinitialize search forms (JavaScript):**  
  ```javascript
  ewp_search_forms();
  ```

### "All" option of multiple-checkbox filters

A filter input of type **Multiple checkbox**, or **Taxonomies** with view *Multiple checkbox*, has three extra settings in its "Filter type configuration":

| Setting | Field key | Values |
|---|---|---|
| All option behaviour | `apply_all_mode` | `toggle` (default): the apply-all option checks/unchecks every option and switches to "Deselect All". `exclusive`: an "All" tab that means "no filter" — checked when nothing else is, checking it unchecks the other options, checking any option unchecks it, unchecking the last option re-checks it. |
| All option label | `apply_all_label` | Text of the apply-all option (default "Select All"). Translated through the `extend-wp` text domain like the filter labels (WPML String Translation). |
| Hide the All option | `disable_apply_all` | Do not show the apply-all option. |

`prepare_form_fields()` passes them to the field (an empty `disable_apply_all`, an unknown `apply_all_mode` or an empty `apply_all_label` is dropped), so `ewp_search_prepare_form_fields_filter` receives them on the field array and can set them from code too.

The apply-all checkbox has no `name`, so it never posts a value; when only "All" is checked the field sends nothing and the search runs without a filter for it. Empty entries in an array parameter (`?cat[]=`) are ignored by the query, and an all-empty array adds no `tax_query` clause. On the front end, exclusive fields carry `data-apply-all-mode="exclusive"` on the field wrapper and the apply-all input (for CSS), and the labels' `selected` class follows the checkboxes. On a taxonomy archive the current term is pre-selected, so "All" renders unchecked there.

The settings are added to search filter rows only, through the `awm_case_fields_choices_filter` filter (see `HOOKS.md`); field groups are unchanged.

## Configuration Example

### Field Configuration
- Label: "Filter label"
- Case: "Select"
- Query Key: "my_filter"
- Query Type: "taxonomy"

### Sorting Configuration
- Label: "Name Ascending"
- Order By: "title"
- Order: "ASC"

### Filter Example
```php
add_filter('ewp_query_fields_filter', function ($fields) {
    $fields['custom_meta'] = [
        'label' => __('Custom Meta Field', 'extend-wp'),
        'field-choices' => [
            'meta_key' => [
                'label' => __('Meta Key', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
            ],
            'meta_compare' => [
                'label' => __('Comparison Operator', 'extend-wp'),
                'case' => 'select',
                'options' => ['=', '!=', '>', '<'],
            ],
        ],
    ];
    return $fields;
});
```

## Credits
Developed by Nikolaos Giannopoulos.  
For questions or support, please [contact us](n.giannopoulos@motivar.io).
