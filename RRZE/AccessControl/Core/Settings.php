<?php

namespace RRZE\AccessControl\Core;

use RRZE\AccessControl\Main;
use RRZE\AccessControl\Network\IPUtils;
use RRZE\AccessControl\WordPress\ListTable;

defined('ABSPATH') || exit;

class Settings {

    protected $main;

    protected $option_name;
    
    protected $options;

    protected $settings_error_transient = 'rrze-ac-settings-error-';
    protected $settings_error_transient_expiration = 30;

    protected $notice_transient = 'rrze-ac-notice-';
    protected $notice_transient_expiration = 30;

    protected $access_permission_meta_key;

    public function __construct(Main $main) {
        $this->main = $main;
        $this->option_name = $this->main->option_name;
        $this->options = $this->main->options;

        $this->access_permission_meta_key = $this->main->access_permission_meta_key;
        
        add_action('admin_menu', array($this, 'access_menu'));
        
        add_action('admin_init', array($this, 'admin_actions'));
        add_action('admin_init', array($this, 'admin_settings'));        
    }
    
    public function access_menu() {
        $this->validate_actions();
        
        $access_page = add_menu_page(__("Access Protection", 'rrze-ac'), __("Access Protection", 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'), 'dashicons-shield');
        add_submenu_page('rrze-ac', __("Permissions", 'rrze-ac'), __("Permissions", 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'));
        add_action( "load-{$access_page}", array($this, 'load_access_page'));
        add_action( "load-{$access_page}", array($this, 'access_screen_options'));
                
        add_submenu_page('rrze-ac', __("Settings", 'rrze-ac'), __("Settings", 'rrze-ac'), 'manage_options', 'rrze-ac-settings', array($this, 'access_settings_page'));
    }
    
    public function load_access_page() {
        new ListTable($this->main);
    }

    public function access_screen_options() {
        $option = 'per_page';
        $args = array(
            'label' => __("Items per page:", 'rrze-ac'),
            'default' => 20,
            'option' => 'rrzeacs_per_page'
        );
        
        add_screen_option($option, $args);
    }
    
    public function access_permissions_page() {
        $action = $this->request_var('action');
        $option_page = $this->request_var('option_page');
        ?>
        <div class="wrap">
            <h2>
                <?php echo esc_html(__("Permissions", 'rrze-ac')); ?>
                <?php if (empty($action)): ?>
                <a href="<?php echo $this->main->action_url(array('action' => 'new')); ?>" class="add-new-h2"><?php _e("Add New Permission", 'rrze-ac'); ?></a>
                <?php endif; ?>
            </h2>
            <?php
            if ($action == 'new' || $option_page == 'rrze-ac-new') {
                $this->set_new_page();
            } elseif ($action == 'edit' || $option_page == 'rrze-ac-edit') {
                $this->set_edit_page();
            } else {
                $this->set_default_page();
            }
            ?>
        </div>
        <?php
        $this->delete_settings_errors();
    }    

    private function validate_actions() {
        $action = $this->request_var('action');
        $option_page = $this->request_var('option_page');
        
        if ($option_page == 'rrze-ac-new') {
            $this->validate_new_action();
        } elseif ($option_page == 'rrze-ac-edit') {
            $this->validate_edit_action();
        } elseif ($option_page == 'rrze-ac-settings') {
            $this->validate_settings_action();
        }
    }
    
    private function validate_new_action() {
        $input = (array) $this->request_var($this->option_name);
        $nonce = $this->request_var('_wpnonce');        
        
        if (!wp_verify_nonce($nonce, 'rrze-ac-new-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }

        $permission_key = $this->validate_new($input);
        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect($this->main->action_url(array('action' => 'new')));
            exit();
        }

        $this->add_admin_notice(__("The permission has been added.", 'rrze-ac'));
        wp_redirect($this->main->action_url(array('action' => 'edit', 'permission' => $permission_key)));
        exit();   
    }
    
    private function validate_edit_action() {
        $input = (array) $this->request_var($this->option_name);
        $nonce = $this->request_var('_wpnonce');
        
        if (!wp_verify_nonce($nonce, 'rrze-ac-edit-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }
        
        if (!isset($input['permission_key'])) {
            wp_die(__("Permission does not exist.", 'rrze-ac'));
        }
        
        $permission_key = $input['permission_key'];
        $permission = $this->main->get_permission($permission_key);
        if (!$permission) {
            wp_die(__("Permission does not exist.", 'rrze-ac'));
        }               
        
        $validation = $this->validate_edit($permission, $input);

        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect($this->main->action_url(array('action' => 'edit', 'permission' => $permission_key)));
            exit();
        }

        if ($validation) {
            $this->add_admin_notice(__("The permission has been updated.", 'rrze-ac'));
        }
        
        wp_redirect($this->main->action_url(array('action' => 'edit', 'permission' => $permission_key)));
        exit();     
    }
    
    private function validate_settings_action() {
        $input = (array) $this->request_var($this->option_name);
        $nonce = $this->request_var('_wpnonce');
                  
        if (!wp_verify_nonce($nonce, 'rrze-ac-settings-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }

        $validation = $this->validate_settings($input);

        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect($this->main->action_url(array('page' => 'rrze-ac-settings')));
            exit();
        }

        if ($validation) {
            $this->add_admin_notice(__("The settings have been updated.", 'rrze-ac'));
        }
        
        wp_redirect($this->main->action_url(array('page' => 'rrze-ac-settings')));
        exit();
    }

    private function validate_new($input) {
        $input = (array) $input;
        
        $permission_key = !empty($input['permission_key']) ? sanitize_title($input['permission_key']) : '';
        
        if(!$permission_key) {
            $this->add_settings_error('permission_key', '', __("Permission required.", 'rrze-ac'));
        } elseif (isset($this->options['permissions'][$permission_key])) {
            $this->add_settings_error('permission_key', $permission_key, __("Permission already exists.", 'rrze-ac'));
        } else {
            $this->add_settings_error('permission_key', $permission_key, '', FALSE);
        }
           
        $select = !empty($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';
        
        if(!$select) {
            $this->add_settings_error('select', '', __("Short description required.", 'rrze-ac'));
        } else {
            $this->add_settings_error('select', $select, '', FALSE);
        }
        
        $ip_address = !empty($input['ip_address']) && is_array($input['ip_address']) ? array_filter($input['ip_address']) : '';
        $ip_range = $this->get_ip_range($ip_address);
        $ip_address = !empty($ip_range) ? $ip_range : '';
        
        $description = !empty($input['description']) ? sanitize_textarea_field($input['description']) : '';
        
        $logged_in = !empty($input['logged_in']) ? 1 : 0;
        $sso_logged_in = !empty($input['sso_logged_in']) ? 1 : 0;
                
        if ($this->settings_errors()) {
            $this->add_settings_error('logged_in', $logged_in, '', FALSE);
            $this->add_settings_error('sso_logged_in', $sso_logged_in, '', FALSE);
            $this->add_settings_error('ip_address', $ip_address, '', FALSE);
            $this->add_settings_error('description', $description, '', FALSE);
            return FALSE;
        }        
        
        $new_permission = array(
            'permission_key' => $permission_key,
            'logged_in' => $logged_in,
            'sso_logged_in' => $sso_logged_in,
            'ip_address' => $ip_address,
            'select' => $select,
            'description' => $description,
            'core' => 0,
            'active' => 1,            
        );
        
        $this->options['permissions'] = array_merge($this->options['permissions'], array($permission_key => $new_permission));
        
        if (update_option($this->option_name, $this->options)) {
            return $permission_key;
        }
        
        return FALSE;
    }
    
    private function validate_edit($permission, $input) {
        $permission_key = $permission['permission_key'];
        
        $select = isset($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';
        if(!$select) {
            $this->add_settings_error('select', '', __("Short description required.", 'rrze-ac'));
        } else {
            $this->add_settings_error('select', $select, '', FALSE);
        }
      
        $permission['select'] = $select;
        
        $description = !empty($input['description']) ? sanitize_textarea_field($input['description']) : '';
        $permission['description'] = $description;
        
        $ip_address = !empty($input['ip_address']) && is_array($input['ip_address']) ? array_filter($input['ip_address']) : '';
        $ip_range = $this->get_ip_range($ip_address);
        $ip_address = !empty($ip_range) ? $ip_range : '';
        $permission['ip_address'] = $ip_address;
                
        $logged_in = !empty($input['logged_in']) ? 1 : 0;
        $sso_logged_in = !empty($input['sso_logged_in']) ? 1 : 0;
                
        $affiliation = $sso_logged_in && !empty(trim($input['affiliation'])) ? array_map('trim', explode(',', trim($input['affiliation']))) : '';
        $permission['affiliation'] = $affiliation;
        
        if ($this->settings_errors()) {
            $this->add_settings_error('logged_in', $logged_in, '', FALSE);
            $this->add_settings_error('sso_logged_in', $sso_logged_in, '', FALSE);
            $this->add_settings_error('affiliation', $affiliation, '', FALSE);
            $this->add_settings_error('ip_address', $ip_address, '', FALSE);
            $this->add_settings_error('description', $description, '', FALSE);
            return FALSE;
        }    
        
        if(!$permission['core']) {
            $permission['logged_in'] = $logged_in;
            $permission['sso_logged_in'] = $sso_logged_in;
        }
                
        $this->options['permissions'][$permission_key] = $permission;
      
        return update_option($this->option_name, $this->options);
    }

    private function get_ip_range($ip_address) {
        $ip_range = array();
        if(!empty($ip_address)) {
            foreach($ip_address as $key => $value) {
                $sanitized_value = IPUtils::sanitizeIpRange($value);
                if(!is_null($sanitized_value)) {
                    $ip_range[] = $sanitized_value;
                } else {
                    $ip_range[] = $value;
                    $this->add_settings_error('ip_address-' . $key, $ip_address, sprintf(__("The IP address %s is not valid.", 'rrze-ac'), $value));
                }
            }
        }
        return $ip_range;
    }
        
    public function request_var($param, $default = '') {
        if (isset($_POST[$param])) {
            return $_POST[$param];
        }
        
        if (isset($_GET[$param])) {
            return $_GET[$param];
        }
        
        return $default;
    }

    private function set_new_page() {
        ?>
        <h2><?php echo esc_html(__("Add New Permission", 'rrze-ac')); ?></h2>
        <form action="<?php echo $this->main->action_url(array('action' => 'new')); ?>" method="post">
        <?php
        settings_fields('rrze-ac-new');
        do_settings_sections('rrze-ac-new');
        submit_button(__("Add New Permission", 'rrze-ac'));
        ?>
        </form>
        <?php        
    }
    
    private function set_edit_page() {        
        ?>
        <h2><?php echo esc_html(__("Edit permission", 'rrze-ac')); ?></h2>
        <form action="<?php echo $this->main->action_url(array('action' => 'edit')) ?>" method="post">
        <?php
        settings_fields('rrze-ac-edit');
        do_settings_sections('rrze-ac-edit');
        submit_button(__("Save Changes", 'rrze-ac'));
        ?>
        </form>
        <?php
    }
    
    private function set_default_page() {        
        $list_table = new ListTable($this->main);
        $list_table->prepare_items();
        ?>
        <form method="get">
        <input type="hidden" name="page" value="rrze-ac">
        <?php
        $list_table->search_box(__("Search", 'rrze-ac'), 'search_id');
        ?>
        </form>
        <form method="post">
        <?php
        $list_table->views();
        $list_table->display();
        ?>
        </form>
        <?php        
        
    }
    
    public function access_settings_page() {
        ?>
        <div class="wrap">
            <h2>
                <?php echo esc_html(__("Settings", 'rrze-ac')); ?>
            </h2>
            <?php $this->settings_page(); ?>
        </div>
        <?php
        $this->delete_settings_errors();
    }
    
    public function settings_page() {
        ?>
        <form method="post">
        <?php
        settings_fields('rrze-ac-settings');
        do_settings_sections('rrze-ac-settings');
        submit_button();
        ?>
        </form>
        <?php        
    }

    public function admin_settings() {
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $sso_logged_in = !empty($permission['sso_logged_in']) ? TRUE : FALSE;
        
        add_settings_section('rrze-ac-new-section', FALSE, '__return_false', 'rrze-ac-new');
        add_settings_field('permission_key', __("Permission", 'rrze-ac'), array($this, 'permission_key_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permission_logged_in_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permission_sso_logged_in_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-new', 'rrze-ac-new-section');
                        
        add_settings_section('rrze-ac-edit-section', FALSE, '__return_false', 'rrze-ac-edit');    
        add_settings_field('permission_key', __("Permission", 'rrze-ac'), array($this, 'permission_key_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permission_logged_in_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permission_sso_logged_in_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        if ($sso_logged_in) {
            add_settings_field('affiliation', '&#8212; ' . __("Person affiliation", 'rrze-ac'), array($this, 'permission_affiliation_field'), 'rrze-ac-edit', 'rrze-ac-edit-section'); 
        }
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
                
        add_settings_section('rrze-ac-settings-section', FALSE, '__return_false', 'rrze-ac-settings');        
        add_settings_field('default_permission', __("Standard Permission", 'rrze-ac'), array($this, 'default_permission_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');        
    }
    
    public function permission_key_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $readonly = $permission_key ? ' readonly="readonly"' : '';
        $permission_key = isset($settings_errors['permission_key']['value']) && !$readonly ? $settings_errors['permission_key']['value'] : $permission_key;
        $field_invalid = !empty($settings_errors['permission_key']['error']) ? 'field-invalid' : '';        
        ?>
        <input type="hidden" value="<?php echo !empty($permission['active']) ? 1 : 0; ?>" name="<?php printf('%s[active]', $this->option_name); ?>">
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo $permission_key; ?>" name="<?php printf('%s[permission_key]', $this->option_name); ?>"<?php echo $readonly; ?>>
        <?php
    }

    public function permission_logged_in_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $checked = !empty($permission['logged_in']) ? TRUE : FALSE;
        $checked = !empty($settings_errors['logged_in']['value']) ? TRUE : $checked;
        ?>
        <label for="permission_logged_in">
            <input id="permission_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[logged_in]', $this->option_name); ?>" value="1"> <?php _e("The user must be logged-in and a member of the website.", 'rrze-ac'); ?>
        </label>
        <?php
    }
    
    public function permission_sso_logged_in_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $checked = !empty($permission['sso_logged_in']) ? TRUE : FALSE;
        $checked = !empty($settings_errors['sso_logged_in']['value']) ? TRUE : $checked;
        ?>
        <label for="permission_sso_logged_in">
            <input id="permission_sso_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[sso_logged_in]', $this->option_name); ?>" value="1"> <?php _e("The user must be SSO logged-in.", 'rrze-ac'); ?>
        </label>
        <?php
    }
        
    public function permission_affiliation_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $affiliation = !empty($permission['affiliation']) ? implode(', ', (array) $permission['affiliation']) : '';
        $affiliation = isset($settings_errors['affiliation']['value']) ? implode(', ', (array) $settings_errors['affiliation']['value']) : $affiliation;    
        ?>
        <input class="regular-text" type="text" value="<?php echo $affiliation; ?>" name="<?php printf('%s[affiliation]', $this->option_name); ?>">
        <p class="description"><?php _e('Separate person affiliations with commas.', 'rrze-ac'); ?></p>
        <?php
    }
    
    public function permission_select_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);      
        $select = isset($permission['select']) ? sanitize_text_field($permission['select']) : '';
        $select = isset($settings_errors['select']['value']) ? sanitize_text_field($settings_errors['select']['value']) : $select;
        $field_invalid = !empty($settings_errors['select']['error']) ? 'field-invalid' : '';
        ?>
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo $select; ?>" name="<?php printf('%s[select]', $this->option_name); ?>">
        <?php
    }
    
    public function permission_description_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $description = isset($permission['description']) ? esc_textarea($permission['description']) : '';
        $description = isset($settings_errors['description']['value']) ? esc_textarea($settings_errors['description']['value']) : $description;
        ?>
        <textarea id="description" cols="50" rows="5" name="<?php printf('%s[description]', $this->option_name); ?>"><?php echo $description; ?></textarea>
        <?php
    }
    
    public function default_permission_field() {
        $default_permission = $this->main->get_default_permission();
        $permissions = $this->main->get_the_permissions();
        ?>
        <select id="access-permission-select" name="<?php printf('%s[default_permission]', $this->option_name); ?>">
        <?php foreach ($permissions as $key => $data) : ?>
            <?php if (!$data['active']) continue; ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($default_permission, $key); ?>>
                <?php echo sanitize_text_field($data['select']); ?>
            </option>
        <?php endforeach; ?>
            </select>
        <?php
    }
    
    public function permission_ip_address_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->main->get_permission($permission_key);
        $ip_address = !empty($permission['ip_address']) ? (array) $permission['ip_address'] : array('');
        $ip_address = isset($settings_errors['ip_address']['value']) ? (array) $settings_errors['ip_address']['value'] : $ip_address;
        foreach($ip_address as $key => $value) {
            $field_invalid = isset($settings_errors['ip_address-' . $key]) ? 'field-invalid' : '';
        ?>
        <div id="ipAddressDiv">
            <?php if($key == 0) : ?>
            <p><input type="text" id="ipAddress" class="regular-text <?php echo $field_invalid; ?>" name="<?php printf('%s[ip_address][%d]', $this->option_name, $key); ?>" value="<?php echo (isset($ip_address[$key])) ? $ip_address[$key] : $value; ?>"> <span id="addInput" class="dashicons dashicons-plus"> </span></p>
            <?php else : ?>
            <p><input type="text" id="ipAddress-<?php echo $key; ?>" class="regular-text <?php echo $field_invalid; ?>" name="<?php printf('%s[ip_address][%d]', $this->option_name, $key); ?>" value="<?php echo (isset($ip_address[$key])) ? $ip_address[$key] : $value; ?>"> <span id="removeInput" class="remove-input dashicons dashicons-no" onclick="removeMe(<?php echo $key; ?>)"> </span></p>
            <?php endif; ?>
        </div>
        <?php } ?>
        <script>
            jQuery(document).ready(function($) {
                var i = $('#ipAddressDiv p').size();
                $('#addInput').click(function() {
                    $('<p><input type="text" id="ipAddress-' + i +'" class="regular-text" name="<?php echo $this->option_name; ?>[ip_address][' + i +']" value=""> <span id="removeInput" class="remove-input dashicons dashicons-no" onclick="removeMe('+ i +')"> </span></p>').appendTo('#ipAddressDiv');
                    i++;
                    $('.remove-input').css('cursor', 'pointer');
                    return false;
                });
                removeMe = function(id) {
                    if( i > 1 ) {
                       $('#ipAddress-' + id).parents('p').remove();
                        i--;
                    }
                    return false;
                }
            });
        </script>        
        <?php
    }

    public function admin_actions() {
        $page = $this->request_var('page');
        $action = $this->request_var('action');
        $permission_key = $this->request_var('permission');
        $nonce = $this->request_var('nonce');
        
        $permission = $this->main->get_permission($permission_key);
        
        if($page == 'rrze-ac' && !empty($permission)) {
            switch ($action) {
                case 'activate':
                    if (!wp_verify_nonce($nonce, 'activate')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }                   
                    if ($this->action_activate($permission)) {
                        $this->add_admin_notice(__("The permission has been enabled.", 'rrze-ac'));
                        wp_redirect($this->main->action_url());
                        exit();                        
                    }
                    break;
                case 'deactivate':
                    if (!wp_verify_nonce($nonce, 'deactivate')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_activate($permission, 0)) {
                        $this->add_admin_notice(__("The permission has been disabled.", 'rrze-ac'));
                        wp_redirect($this->main->action_url());
                        exit();                        
                    }
                    break;
                case 'delete':
                    if (!wp_verify_nonce($nonce, 'delete')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_delete($permission)) {
                        $this->add_admin_notice(__("The permission has been deleted.", 'rrze-ac'));
                        wp_redirect($this->main->action_url());
                        exit();                        
                    }
                    break;
                default:
                    break;
            }
        }
    }
    
    public function action_activate($permission, $activate = 1) {
        if(!isset($permission['permission_key']) || !$activate && $permission['permission_key'] == $this->main->get_default_permission()) {
            return FALSE;
        }                
        $permission_key = $permission['permission_key'];
        $this->options['permissions'][$permission_key]['active'] = $activate;
        return update_option($this->option_name, $this->options);   
    }
    
    public function action_delete($permission) {
        if(!isset($permission['permission_key']) 
                || $permission['core'] 
                || $permission['permission_key'] == $this->main->get_default_permission()
                || !empty($this->main->count_meta_keys($permission['permission_key']))) {
            return FALSE;
        }
        $permission_key = $permission['permission_key'];
        unset($this->options['permissions'][$permission_key]);
        return update_option($this->option_name, $this->options);
    }
    
    private function validate_settings($input) {
        if (!isset($input['default_permission']) 
                || !isset($this->options['permissions'][$input['default_permission']]) 
                || !$this->options['permissions'][$input['default_permission']]['active']) {
            return FALSE;
        }        
        $this->options['default_permission'] = $input['default_permission'];       
        return update_option($this->option_name, $this->options);
    }

    public function admin_notices() {        
        $this->display_admin_notices();
    }
    
    public function add_admin_notice($message, $class = 'updated') {
        $allowed_classes = array('error', 'updated');
        if (!in_array($class, $allowed_classes)) {
            $class = 'updated';
        }

        $transient = $this->notice_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $notices = maybe_unserialize($transient_value ? $transient_value : array());
        $notices[$class][] = $message;

        set_transient($transient, $notices, $this->notice_transient_expiration);
    }

    public function display_admin_notices() {
        $transient = $this->notice_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $notices = maybe_unserialize($transient_value ? $transient_value : '');
        
        if (is_array($notices)) {
            foreach ($notices as $class => $messages) {
                foreach ($messages as $message) :
                    ?>
                    <div class="<?php echo $class; ?>">
                        <p><?php echo $message; ?></p>
                    </div>
                    <?php
                endforeach;
            }
        }
        
        delete_transient($transient);

    }
    
    public function add_settings_error($field, $value = '', $message = '', $error = TRUE) {
        $transient = $this->settings_error_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $errors = maybe_unserialize($transient_value ? $transient_value : array());
        $errors[$field] = array('value' => $value, 'message' => $message, 'error' => $error);

        set_transient($transient, $errors, $this->settings_error_transient_expiration);
    }
    
    public function settings_errors() {
        $transient = $this->settings_error_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $errors = (array) maybe_unserialize($transient_value ? $transient_value : '');
        
        foreach ($errors as $error) {
            if (!empty($error['error'])) {
                return $errors;
            }
        }
        
        return FALSE;
    }
    
    public function delete_settings_errors() {     
        delete_transient($this->settings_error_transient . get_current_user_id());
    }
    
}
