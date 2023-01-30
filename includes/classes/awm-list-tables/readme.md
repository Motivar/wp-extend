# Register custom list table

Developers can use Filox to quickly create a custom list table as easily as calling a simple function. The function to be called is

```
flx_add_table_list
```

## Disclaimer

Since `flx_add_table_list` modifies the left-hand admin navigation bar, the function must be called after `admin_menu` action. Eg.

```
add_action('admin_menu', function (){
    flx_add_table_list($args);
})
```

## Function breakdown

`flx_add_table_list` takes in just one argument. That argument is an array and includes everything the function needs to register the custom table.

```
function flx_add_table_list($args) {
    // do magic
}
```

### Array keys (args)

`$list_name`: The name of the list that will be displayed in the menu navigation bar and as a header in the list page

`$columns`: The array of columns to be displayed. Must be of format array(key => label) where key is the distinction factor and label is the string that will be shown on the list. Eg.

```
...
'columns' => array(
        'age' => 'Age',
        'first_name' => 'First name'
),
...
```

The list will show 'First name' and not 'first_name'

`$sortable`: An array of all sortable columns. Can be none, one or many of the values in \$columns Array mast be of format array('key' => array('key' => true)). Eg. for array `$user_data` we could have

```
...
'sortable' => array('age' => array('age', true)),
...
```

Now users can be sorted (ascending or descending) by their age

`$table_name`: The name of the DB table to retrieve the data from (no prefix)

`$results_per_page`: The number of results to show per page. Handles pagination

`$icon_url`: The path to the icon image to show on the menu navigation bar

`$is_sub_list`: Optional mixed variable that determines whether the new table list is going to have its own menu page or not. If not given in or if given `false`, the new table list will reside in its own menu page. Else, if sublist **is a string**, that string is used as the name of the menu page that the custom table list is gonna reside under

`$is_data_encrypted`: Boolean value to determine whether the data to be retrieved is stored in an encrypted manner in the database

# Full example

```
add_action('admin_menu', function () {
    $args = array(
        'list_name' => __('transactions', 'filox'), // i18n is available
        'columns' => array(
            'title' => 'Title',
            'date' => 'Date',
            'booking_id' => 'Booking ID',
            'payment_type' => 'Payment Type',
            'status' => 'Status',

        ),
        'sortable' => array('date' => array('date', true)),
        'table_name' => 'flx_transactions',
        'results_per_page' => 50,
        'icon_url' => '',
        'parent' => 'filox-settings',
        'is_data_encrypted' => true
    );

    flx_add_table_list($args);
});
```

# Customizing output

The `AWM_List_Table` class lets you customize every and any of the columns you wish to output by applying a unique filter for each column rendering function. That filter uses the data value for the specific column for the specific row of data.

## Filter name

The filter name uses a `column_$column_filter`. For example, if a column has the name of 'profile_picture' then the respective filter will equal `column_profile_picture_filter`. The filter will, most likely, return a url to the profile picture in this case. Add your own callback and filter and output that image as you wish
