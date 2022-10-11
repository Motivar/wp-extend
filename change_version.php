<?php
if (!defined('ABSPATH')) {
   exit;
}


class Change_Version
{

   public function __construct()
   {
      add_action('admin_init', array($this, 'awm_sync'));
   }

   public function awm_sync()
   {
      if (isset($_REQUEST['awm_sync'])) {
         /*get fields*/
         $args = array(
            'post_type' => 'awm_field',
            'post_status' => array('publish'),
            'numberposts' => -1,
         );
         $fields = get_posts($args);
         if (!empty($fields)) {
            $libraries = awm_fields_usages() + awm_fields_positions() + awm_fields_configuration();
            //$user=get_current_user_id();
            $args = array('main' => 'ewp_fields_main', 'meta' => 'ewp_fields_data');

            foreach ($fields as $field) {
               $data = array(
                  'table_id' => 'new',
                  'title' => $field->post_title,
                  'status' => $field->post_status == 'publish' ? 'enabled' : 'disabled',
                  'user' => $field->post_author

               );
               foreach (array_keys($libraries) as $key) {
                  $data[$key] = get_post_meta($field->ID, $key, true);
               }
               $data['awm_custom_meta'] = array_keys($libraries);

               $id = awm_custom_content_save($data, $args);
               echo $field->ID . '->' . $id . '<br>';
               wp_delete_post($field->ID, true);
            }
         }
      }
   }
}

new Change_Version();
