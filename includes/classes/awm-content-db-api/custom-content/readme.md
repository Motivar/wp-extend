# Custom Content Type Objects for WordPress

This package provides an efficient way to create and manage custom content type objects in WordPress using two relational tables, similar to the relationship between `wp_posts` and `wp_postmeta`. The goal is to give developers a robust and flexible way to handle structured content beyond the limitations of custom post types and their metadata.

---

## Key Features

1. **Efficient Data Storage:**
   - Main data is stored in a table with the `_main` extension.
   - Additional attributes or fields are stored in a related table with the `_data` extension, similar to the `wp_postmeta` concept.

2. **Relational Table Design:**
   - By separating main object data and additional metadata, queries are optimized and scale better for complex data.

3. **Custom Admin Pages:**
   - Easily create custom admin pages to manage and display custom content objects.

4. **Dynamic Metabox Registration:**
   - Define custom fields for content objects using metaboxes, enabling flexibility for developers and content editors.

5. **Built-In CRUD Operations:**
   - Simplified functions to create, read, update, and delete custom content objects.

6. **Similar API to Posts:**
   - The API mimics the familiar structure of working with WordPress posts and postmeta, reducing the learning curve.

---

## Why Use This Package?

1. **Overcome Limitations of Custom Post Types:**
   - Custom post types are stored in `wp_posts`, which can lead to performance issues as the site scales.
   - This package allows you to structure your data in dedicated tables, making queries faster and more efficient.

2. **Relational Data Made Easy:**
   - WordPress core does not natively support complex relationships or table joins.
   - With the `_main` and `_data` tables, you can build relationships and store structured data efficiently.

3. **Optimized Query Performance:**
   - Queries targeting specific content types are faster due to separate tables.
   - Meta information is stored in a dedicated table, avoiding the clutter and performance bottlenecks of `wp_postmeta`.

4. **Customizable Admin Interface:**
   - Developers can create custom admin interfaces tailored to the needs of the content type, providing a better UX.

---

## How to Use

### 1. Installation
   - It comes installed with the plugin

### 2. Register a Custom Content Type Object
Use the class to register a new content type by passing arguments that define the main table and data table structure.

```php
$args = array(
    'custom_id' => 'my_content_type',
    'custom_list' => array(
        'list_name' => 'My Custom Content',
        'capability' => 'manage_options',
        'results_per_page' => 50,
        'metaboxes' => $this->ewp_fields_metas()
    )
);

new AWM_Add_Custom_List($args);
```

### Example: Registering Defaults
You can extend functionality by setting up default configurations using the `register_defaults` method.

```php
public function register_defaults($data)
{
    $data['fields'] = array(
        'parent' => 'extend-wp',
        'statuses' => array(
            'enabled' => array('label' => __('Enabled', 'extend-wp')),
            'disabled' => array('label' => __('Disabled', 'extend-wp')),
        ),
        'show_new' => false,
        'list_name' => __('Fields', 'extend-wp'),
        'list_name_singular' => __('Field', 'extend-wp'),
        'order' => 1,
        'capability' => 'activate_plugins',
        'version' => 0.01,
        'metaboxes' => $this->ewp_fields_metas()
    );
    return $data;
}

public function ewp_fields_metas()
{
    $boxes = array();
    $boxes['awm_metas'] = array(
        'title' => __('Fields Configuration', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'high',
        'callback' => 'awm_fields_configuration',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_position_settings'] = array(
        'title' => __('Fields Position', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'low',
        'callback' => 'awm_fields_positions',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_php_usage'] = array(
        'title' => __('Php usage', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'low',
        'callback' => 'awm_php_views',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_fields_usage'] = array(
        'title' => __('Fields Usage', 'extend-wp'),
        'context' => 'side',
        'priority' => 'low',
        'callback' => 'awm_fields_usages',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_fields_dev_notes'] = array(
        'title' => __('Developer notes', 'extend-wp'),
        'context' => 'side',
        'priority' => 'low',
        'callback' => 'awm_dev_notes',
        'auto_translate' => true,
        'order' => 1,
    );
    return $boxes;
}
```

### 3. Defining Tables
- The main table (`{prefix}_my_content_type_main`) will store essential details such as ID, title, and primary attributes.
- The data table (`{prefix}_my_content_type_data`) will store additional fields as key-value pairs.

### 4. Using Metaboxes
Metaboxes allow developers to add custom fields for additional content customization. These fields are dynamically added to the data table and made available in the admin interface. Users can attach metaboxes using the interface available at `admin.php?page=ewp_fields`.

### 5. Adding and Managing Custom Content
Users can add and manage custom content through the admin interface at `admin.php?page=ewp_content_type`.

### 6. CRUD Operations
CRUD operations are built-in and abstracted for convenience:

- **Create:**
  ```php
  $id = awm_custom_content_save('my_content_type', $_POST);
  ```

- **Read:**
  ```php
  $content = awm_get_db_content('my_content_type', array('include' => array($id)));
  ```

- **Update:**
  The same function as create can be used to update an existing record.

- **Delete:**
  ```php
  awm_custom_content_delete('my_content_type', array($id));
  ```

### 7. Admin Interface
When the custom content type is registered, a new menu item will appear in the WordPress admin. The interface allows users to:
   - View a paginated list of custom content items.
   - Add new items or edit existing ones using custom forms defined in the metaboxes.

---

## Example Use Cases
1. **Product Catalog:**
   - Store product details and specifications in the main table while keeping additional attributes like color, size, or ratings in the data table.

2. **Event Management:**
   - Store event metadata (date, location, attendees) efficiently in the relational tables.

3. **Custom Forms:**
   - Save form submissions with custom fields dynamically mapped to the `_data` table.

---

## Performance Tips
- Avoid storing large amounts of text or HTML in the main table—keep it to core details.
- Use indexes on key columns in both the `_main` and `_data` tables to optimize search queries.
- Regularly clean up unused or obsolete entries to avoid bloating the database.

---

## Known Limitations
- This system is designed to work within WordPress but relies on custom tables, which may require adjustments when migrating or backing up.
- Not fully compatible with all WordPress core functionalities like WP_Query unless extended.

---

## Conclusion
This package provides developers with an efficient, scalable, and maintainable way to handle structured content within WordPress. By leveraging custom tables and relational data storage, you can overcome the limitations of custom post types and optimize your content for performance.

