<?php
if (!defined('ABSPATH')) {
    exit;
}


/**
 * show error messages to the admin
 */

class Extend_WP_Notices
{
    private $message;
    private $class;
    public function __construct()
    {
        add_action('admin_notices', array($this, 'render'), 999);
    }

    public function set_message($message)
    {
        $this->message = $message;
    }

    public function set_class($class)
    {
        $this->class = $class;
    }

    public function render()
    {
        printf('<div class="filox-admin-message %s"><p>%s</p></div>', $this->class, $this->message);
    }
}
