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


            foreach ($fields as $field) {
               $data = array(
                  'title' => $field->post_title,
                  'status' => $field->post_status == 'publish' ? 'enabled' : 'disabled',
                  'user' => $field->post_author

               );
               foreach (array_keys($libraries) as $key) {
                  $data[$key] = get_post_meta($field->ID, $key, true);
               }
               $data['awm_custom_meta'] = array_keys($libraries);

               $id = awm_custom_content_save('ewp_fields', $data);
               if (is_wp_error($id)) {
                  die($id->get_error_message());
               }
               if ($id) {
                  echo $field->ID . '->' . $id . '<br>';
                  wp_delete_post($field->ID, true);
               }
            }
         }
      }
   }
}

new Change_Version();