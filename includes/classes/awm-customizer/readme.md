# EWP Customizer settings

Developers can easily extend customizer settings

## Full example
```
add_filter('awm_add_customizer_settings_filter', function ($array) {
     $array['test'] => array(
    'title' => __('test', 'filox'),
    'description' => __('this is a test', 'filox'), /*optional*/
    'priority' => 100, /*optional*/
    'sections' => array(
     'test' => array(
      'title' => 'test',
      'order' => 40, /*optional*/
      'capability' => 'edit_theme_options',
      'description' => __('Add custom CSS here'), /*optional*/
      'library/callback' => callback or inputs
     )
    )
   );

    return $array;

});
```
