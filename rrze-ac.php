<?php

/*
  Plugin Name: RRZE-Access-Control
  Plugin URI: https://gitlab.rrze.fau.de/rrze-webteam/rrze-ac
  Version: 1.4.2
  Description: Es ermöglicht das Schützen von Dateien/Dokumente durch Benutzerbezogene Funktionen und IP-Adresse.
  Author: RRZE-Webteam
  Author URI: https://blogs.fau.de/webworking/
 */

/*
  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

// Sprachdateien werden eingebunden.
load_plugin_textdomain('rrze-ac', FALSE, dirname(plugin_basename(__FILE__)) . '/languages');

add_action('plugins_loaded', array('RRZE_AC', 'instance'));

register_activation_hook(__FILE__, array('RRZE_AC', 'activation'));
register_deactivation_hook(__FILE__, array('RRZE_AC', 'deactivation'));

class RRZE_AC {

    const version = '1.4.2';
    
    const option_name = 'rrze_ac';
    const version_option_name = 'rrze_ac_version';
    const enabled_option_name = 'rrze_ac_enabled';
    
    const php_version = '5.5'; // Minimal erforderliche PHP-Version
    const wp_version = '4.7'; // Minimal erforderliche WordPress-Version
    
    const settings_error_transient = 'rrze-ac-settings-error-';
    const settings_error_transient_expiration = 30;
    
    const notice_transient = 'rrze-ac-notice-';
    const notice_transient_expiration = 30;
    
    const protected_dirname = '_protected';
    const access_permission_meta_key = '_access_permission';
    
    const user_isnt_logged_in = 0;
    const user_ip_isnt_in_range = 1;
    const user_isnt_sso_logged_in = 2;
    const user_hasnt_affiliation = 4;
    const user_hasnt_entitlement = 8;
    
    public $permission_status = NULL;
    
    public $plugin_file = NULL;
    
    public $list_table_obj = NULL; // WP_List_Table object
    
    private $websso_plugin = 'fau-websso/fau-websso.php';
    
    private $websso_option_name = '_fau_websso';
    
    private $person_affiliation = NULL;
    
    private $person_entitlement = NULL;
    
    protected static $options;
    
    protected static $instance = NULL; // Singleton instance

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function __construct() {
        
        $this->plugin_file = __FILE__;

        // Enthaltene Optionen.
        self::$options = self::get_options();
        
        self::update_version();
                
        add_action('init', array($this, 'request_file'), 0);
        
        add_action('init', array($this, 'check_rewrite'));
                
        if(get_site_option(self::enabled_option_name)) {
            
            require_once(plugin_dir_path(__FILE__) . 'includes/list-table.php');
            require_once(plugin_dir_path(__FILE__) . 'includes/network.php');
            
            add_action('wp_enqueue_media', array($this, 'enqueue_media'));

            add_filter('attachment_fields_to_edit', array($this, 'attachment_fields_to_edit'), 10, 2);

            add_filter('upload_dir', array($this, 'change_upload_directory'), 999);

            add_filter('image_downsize', array($this, 'image_downsize_placeholder'), 999, 3);

            add_action('template_redirect', array($this, 'template_redirect'), 0);
            
            // Bezieht sich nur auf den Backend-Bereich
            if (is_admin()) {
                add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

                add_action('admin_menu', array($this, 'access_menu'));
                
                add_action('admin_init', array($this, 'admin_actions'));
                add_action('admin_init', array($this, 'admin_settings'));

                add_action('load-post-new.php', array($this, 'post_enqueue_scripts'));
                add_action('load-post.php', array($this, 'post_enqueue_scripts'));
                add_action('post_submitbox_misc_actions', array($this, 'post_protection_submitbox'));

                add_action('save_post', array($this, 'save_post_data'));
                
                add_action("manage_edit-page_columns", array($this, 'manage_pages_column'));
                add_filter("manage_page_posts_custom_column", array($this, 'manage_pages_custom_column'), 10, 2);

                add_action('add_meta_boxes', array($this, 'attachment_edit_meta_box'));
                add_action('admin_enqueue_scripts', array($this, 'attachment_edit_enqueue_scripts'));

                add_filter('attachment_fields_to_save', array($this, 'save_attachment_edit_fields'), 10, 2);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                add_action('load-media-new.php', array($this, 'load_media_new'));
                add_action('load-upload.php', array($this, 'load_upload'));

                add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
                    $settings_link = '<a href="' . $this->options_url(array('page' => 'rrze-ac-settings')) . '">' . esc_html(__('Einstellungen', 'rrze-ac')) . '</a>';
                    array_unshift($links, $settings_link);
                    return $links;
                });                
                
                add_action('admin_notices', array($this, 'admin_notices'));
                
            // Bezieht sich nur auf den Frontend-Bereich
            } else {
                // Menüelemente die geschützte Objekte verlinken sind abgeschlossen
                add_filter('wp_nav_menu_objects', array($this, 'nav_menu_objects'), 10, 1);
                
                // Anpassung des Abfrageobjekts
                add_filter('pre_get_posts', array($this, 'pre_get_posts'));
            }
            
        } else {
            add_action('admin_notices', array($this, 'admin_error_notice'));
            add_action('network_admin_notices', array($this, 'admin_error_notice'));            
        }
                
    }
        
    /*
     * Wird durchgeführt wenn das Plugin aktiviert wird.
     * @return void
     */

    public static function activation() {
        // Überprüft die Systemanforderungen.
        self::system_requirements();

        self::$options = self::get_options();

        self::update_version();
    }

    /*
     * Wird durchgeführt wenn das Plugin deaktiviert wird
     * @return void
     */

    public static function deactivation() {
        delete_site_option(self::enabled_option_name);
    }

    /*
     * Überprüft die Systemanforderungen.
     * @return void
     */

    private static function system_requirements() {
        global $is_apache;
        
        $error = '';

        // Überprüft die minimal erforderliche PHP-Version.
        if (version_compare(PHP_VERSION, self::php_version, '<')) {
            $error = sprintf(__('Die PHP-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die PHP-Version %s.', 'rrze-ac'), PHP_VERSION, self::php_version);
        }

        // Überprüft die minimal erforderliche WP-Version.
        elseif (version_compare($GLOBALS['wp_version'], self::wp_version, '<')) {
            $error = sprintf(__('Die Wordpress-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die Wordpress-Version %s.', 'rrze-ac'), $GLOBALS['wp_version'], self::wp_version);
        }

        // Überprüft das Webserver-Software.
        elseif (!$is_apache) {
            $error = __('Der Web-Server-Software %s ist nicht kompatibel. Bitte verwenden Sie stattdessen den Apache-Web-Server-Software.', 'rrze-ac');
        }

        // Überprüft das Webserver-Software.
        elseif (!$is_apache) {
            $error = __('Der Web-Server-Software ist nicht kompatibel. Bitte verwenden Sie stattdessen den Apache-Web-Server-Software.', 'rrze-ac');
        }
        
        // Überprüft Multisite-Einstellung.
        elseif (!is_multisite()) {
            $error = __('Die Wordpress-Instanz ist keine Multisite.', 'rrze-ac');
        }
        
        // Überprüft Rewrite-Modul.
        elseif (!got_mod_rewrite() || !is_writable(get_home_path() . '.htaccess')) {
            $error = __('Der Web-Server-Software unterstützt das Rewrite-Modul nicht.', 'rrze-ac');
        }
        
        // Wenn die Überprüfung fehlschlägt, dann wird das Plugin automatisch deaktiviert.
        if (!empty($error)) {
            deactivate_plugins(plugin_basename(__FILE__), FALSE, TRUE);
            wp_die($error);
        }
    }

    public static function update_version() {
        if (get_option(self::version_option_name, NULL) != self::version) {
            update_option(self::version_option_name, self::version);
        }
    }
        
    public function check_rewrite() {
        if (is_admin() && !get_site_option(self::enabled_option_name)) {
            global $pagenow;
            if ($this->check_rewrite_rules()) {
                add_site_option(self::enabled_option_name, 1);
                wp_redirect(admin_url($pagenow ? $pagenow : ''));
                exit();
            }
        }
    }
    
    public function admin_error_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $message = __('Das RRZE-Access-Control-Plugin wurde nicht richtig konfiguriert. Die Dateien/Dokumente können nicht geschützt werden.', 'rrze-ac');
        $message .= ' ';
        if(is_network_admin() || is_super_admin()) {
            $message .= __('Die folgende Rewrite-Befehle müssen in der htaccess-Datei nach der WordPress-Befehl &#8222;RewriteRule ^index\.php$ - [L]&#8220; hinzugefügt werden.', 'rrze-ac');
            $message .= '<p>' . implode('<br>', $this->rewrite_rules()) . '</p>';
        } else {
            $message .= __('Bitte wenden Sie sich an den Systemadministrator.', 'rrze-ac');                
        }
        ?>
        <div class="error">
            <p><?php echo $message; ?></p>
        </div>
        <?php
    }
    
    private function check_rewrite_rules() {
        $upload_dir = wp_upload_dir();

        $protected_test = self::protected_upload_dir('/access_rewrite_test.txt?access_rewrite_test=1', TRUE);

        $check_url = $upload_dir['baseurl'] . $protected_test;
        $check = wp_remote_get($check_url, array('sslverify' => FALSE, 'httpversion' => '1.1'));
        if (is_wp_error($check) || !isset($check['response']['code']) || 200 != $check['response']['code'] || !isset($check['body']) || 'rewrite test passed' != $check['body']) {
            return FALSE;
        }
        
        return TRUE;
    }
    
    private function rewrite_rules() {
        
        $uploads_path = '';
         
        if (!get_site_option('ms_files_rewriting')) {
            $uploads_path .= 'wp-content(?:/uploads)?(?:/sites/[0-9]+)?';
        } else {            
            $uploads_path .= '(?:wp-content/uploads)?(?:files)?';
        }
        
        if (!is_subdomain_install()) {
            $uploads_path = '(?:[_0-9a-zA-Z-]+/)?' . $uploads_path;
        }
        
        $protected_path = $uploads_path . '(' . self::protected_upload_dir('/.*\.\w+)$', TRUE);

        $rewrite_rules = array(
            '# Beginn Access Rewrite Rules',
            'RewriteRule ^' . $protected_path . ' index.php?protected_file=$1 [QSA,L]',
            '# End Access Rewrite Rules'
        );

        return $rewrite_rules;
    }
    
    /*
     * Standard Einstellungen werden definiert.
     * @return array
     */
    private static function default_options() {
        $options = array(
            'permissions' => array(
                'logged-in' =>  array(
                    'permission_key' => 'logged-in',
                    'description'    => __('Angemeldeten Benutzer', 'rrze-ac'),
                    'select'         => __('Angemeldeten Benutzer', 'rrze-ac'),
                    'logged_in'      => 1,
                    'sso_logged_in'  => 0,
                    'affiliation'    => '',
                    'entitlement'    => '',
                    'ip_address'     => '',
                    'core'           => 1,
                    'active'         => 1
                ),
                'all' =>  array(
                    'permission_key' => 'all',
                    'description'    => __('Alle', 'rrze-ac' ),
                    'select'         => __('Alle', 'rrze-ac' ),
                    'logged_in'      => 0,
                    'sso_logged_in'  => 0,
                    'affiliation'    => '',
                    'entitlement'    => '',                    
                    'ip_address'     => '',
                    'core'           => 1,
                    'active'         => 1
                )
            ),           
            'default_permission' => 'logged-in'
        );

        return $options;
    }

    /*
     * Standard Berechtigung wird definiert.
     * @return array
     */    
    private static function default_permission() {
        $permission = array(
            'permission_key' => '',
            'description'    => '',
            'select'         => '',
            'logged_in'      => 0,
            'sso_logged_in'  => 0,
            'affiliation'    => '',
            'entitlement'    => '',
            'ip_address'     => '',
            'core'           => 0,
            'active'         => 0
        );
        
        return $permission;
    }
    
    /*
     * Gibt die Einstellungen zurück.
     * @return object
     */
    private static function get_options() {
        $defaults = self::default_options();
        $default_permission = self::default_permission();
        $options = (array) get_option(self::option_name);

        $options = wp_parse_args($options, $defaults);
        $options['permissions'] = wp_parse_args($options['permissions'], $defaults['permissions']);
        foreach($options['permissions'] as $key => $permission) {
            $options['permissions'][$key] = self::combine_atts($default_permission, $permission);
        }
        
        return $options;
    }
    
    private static function combine_atts($default_atts, $atts) {
        $atts = (array)$atts;
        $combine_atts = array();
        foreach ($default_atts as $key => $default) {
            if (array_key_exists($key, $atts)) {
                $combine_atts[$key] = $atts[$key];
            } else {
                $combine_atts[$key] = $default;
            }
        }
        
        return $combine_atts;        
    }

    public function load_media_new() {
        add_action('admin_enqueue_scripts', array($this, 'media_new_enqueue_scripts'));
        add_action('admin_footer-media-new.php', array($this, 'media_new_js'));
        add_action('post-upload-ui', array($this, 'media_new_upload_ui'));
        add_action('pre-plupload-upload-ui', array($this, 'media_new_upload_ui_notice'));        
    }
    
    public function load_upload() {       
        add_filter('media_row_actions', array($this, 'media_row_actions'), 10, 2);
        add_filter('manage_upload_columns', array($this, 'manage_upload_columns'));
        add_action('manage_media_custom_column', array($this, 'manage_media_custom_column'), 10, 2);
        add_action('admin_head-upload.php', array($this, 'media_custom_column_styles'));
        add_action('admin_footer-upload.php', array($this, 'media_bulk_actions_js'));
        add_action('admin_notices', array($this, 'media_admin_notices'));
        
        $this->bulk_actions();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('access', plugins_url('css/access.css', __FILE__ ), 'all', NULL);
    }

    public function enqueue_media() {
        wp_enqueue_style('access-att-fields', plugins_url('css/attachment-fields.css', __FILE__ ), 'all', NULL);
        wp_enqueue_script('access-att-fields', plugins_url('js/attachment-fields.js', __FILE__ ), array('media-editor'), NULL, TRUE);
    }
    
    public function post_enqueue_scripts() {
        wp_enqueue_script('access-post', plugins_url('/js/post-edit.js', __FILE__ ), array('jquery-ui-slider'), NULL, TRUE);       
    }
    
    public function attachment_edit_enqueue_scripts() {
        $screen = get_current_screen();
        if (!isset($screen->id) || 'attachment' !== $screen->id) {
            return;
        }

        wp_enqueue_style('access-att-edit', plugins_url( 'css/attachment-edit.css', __FILE__ ), 'all', NULL);
    }
        
    private function get_permission($permission_key) {
        if(empty($permission_key)) {
            return array();
        }
        
        $permission = array();
        foreach(self::$options['permissions'] as $key => $value) {
            if($key == $permission_key) {
                $permission =  array(
                    'permission_key' => $permission_key,
                    'description' => $value['description'],
                    'select' => $value['select'],
                    'logged_in' => $value['logged_in'],
                    'sso_logged_in' => $value['sso_logged_in'],
                    'ip_address' => $value['ip_address'],
                    'core' => $value['core'],
                    'active' => $value['active']
                );
            }
        }
                
        return $permission;
    }
    
    public function access_menu() {
        $this->validate_actions();
        
        $access_page = add_menu_page(__('Zugriffsschutz', 'rrze-ac'), __('Zugriffsschutz', 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'), 'dashicons-shield');
        add_submenu_page('rrze-ac', __('Berechtigungen', 'rrze-ac'), __('Berechtigungen', 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'));
        add_action( "load-{$access_page}", array($this, 'load_access_page'));
        add_action( "load-{$access_page}", array($this, 'access_screen_options'));
                
        add_submenu_page('rrze-ac', __('Einstellungen', 'rrze-ac'), __('Einstellungen', 'rrze-ac'), 'manage_options', 'rrze-ac-settings', array($this, 'access_settings_page'));
    }
    
    public function load_access_page() {

    }
    
    public function access_screen_options() {
        new RRZE_AC_List_Table();
        
        $option = 'per_page';
        $args = array(
            'label' => __('Einträge pro Seite:', 'rrze-ac'),
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
                <?php echo esc_html(__('Zugriffsschutz &rsaquo; Berechtigungen', 'rrze-ac')); ?>
                <?php if (empty($action)): ?>
                <a href="<?php echo $this->options_url(array('action' => 'new')); ?>" class="add-new-h2"><?php _e('Neue Berechtigung hinzufügen', 'rrze-ac'); ?></a>
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
        $input = (array) $this->request_var(self::option_name);
        $nonce = $this->request_var('_wpnonce');        
        
        if (!wp_verify_nonce($nonce, 'rrze-ac-new-options')) {
            wp_die(__('Schummeln, was?', 'rrze-ac'));
        }

        $permission_key = $this->validate_new($input);
        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect(self::options_url(array('action' => 'new')));
            exit();
        }

        $this->add_admin_notice(__('Die Berechtigung wurde hinzugefügt.', 'rrze-ac'));
        wp_redirect(self::options_url(array('action' => 'edit', 'permission' => $permission_key)));
        exit();   
    }
    
    private function validate_edit_action() {
        $input = (array) $this->request_var(self::option_name);
        $nonce = $this->request_var('_wpnonce');
        
        if (!wp_verify_nonce($nonce, 'rrze-ac-edit-options')) {
            wp_die(__('Schummeln, was?', 'rrze-ac'));
        }
        
        if (!isset($input['permission_key'])) {
            wp_die(__('Berechtigung existiert nicht.', 'rrze-ac'));
        }
        
        $permission_key = $input['permission_key'];
        $permission = $this->get_permission($permission_key);
        if (!$permission) {
            wp_die(__('Berechtigung existiert nicht.', 'rrze-ac'));
        }               
        
        $validation = $this->validate_edit($permission, $input);

        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect(self::options_url(array('action' => 'edit', 'permission' => $permission_key)));
            exit();
        }

        if ($validation) {
            $this->add_admin_notice(__('Die Berechtigung wurde aktualisiert.', 'rrze-ac'));
        }
        
        wp_redirect(self::options_url(array('action' => 'edit', 'permission' => $permission_key)));
        exit();     
    }
    
    private function validate_settings_action() {
        $input = (array) $this->request_var(self::option_name);
        $nonce = $this->request_var('_wpnonce');
                  
        if (!wp_verify_nonce($nonce, 'rrze-ac-settings-options')) {
            wp_die(__('Schummeln, was?', 'rrze-ac'));
        }

        $validation = $this->validate_settings($input);

        if ($this->settings_errors()) {
            foreach ($this->settings_errors() as $error) {
                if ($error['message']) {
                    $this->add_admin_notice($error['message'], 'error');
                }
            }
            wp_redirect($this->options_url(array('page' => 'rrze-ac-settings')));
            exit();
        }

        if ($validation) {
            $this->add_admin_notice(__('Die Einstellungen wurden aktualisiert.', 'rrze-ac'));
        }
        
        wp_redirect($this->options_url(array('page' => 'rrze-ac-settings')));
        exit();
    }
    
    private function set_new_page() {
        ?>
        <h2><?php echo esc_html(__('Neue Berechtigung hinzufügen', 'rrze-ac')); ?></h2>
        <form action="<?php echo self::options_url(array('action' => 'new')); ?>" method="post">
        <?php
        settings_fields('rrze-ac-new');
        do_settings_sections('rrze-ac-new');
        submit_button(__('Neue Berechtigung hinzufügen', 'rrze-ac'));
        ?>
        </form>
        <?php        
    }
    
    private function set_edit_page() {        
        ?>
        <h2><?php echo esc_html(__('Berechtigung bearbeiten', 'rrze-ac')); ?></h2>
        <form action="<?php echo self::options_url(array('action' => 'edit')) ?>" method="post">
        <?php
        settings_fields('rrze-ac-edit');
        do_settings_sections('rrze-ac-edit');
        submit_button(__('Änderungen übernehmen', 'rrze-ac'));
        ?>
        </form>
        <?php
    }
    
    private function set_default_page() {        
        $list_table = new RRZE_AC_List_Table();
        $list_table->prepare_items();
        ?>
        <form method="get">
        <input type="hidden" name="page" value="rrze-ac">
        <?php
        $list_table->search_box(__('Suche', 'rrze-ac'), 'search_id');
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
                <?php echo esc_html(__('Zugriffsschutz &rsaquo; Einstellungen', 'rrze-ac')); ?>
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
        add_settings_section('rrze-ac-new-section', FALSE, '__return_false', 'rrze-ac-new');
        add_settings_field('permission_key', __('Berechtigung', 'rrze-ac'), array($this, 'permission_key_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('logged_in', __('Angemeldet', 'rrze-ac'), array($this, 'permission_logged_in_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permission_sso_logged_in_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('ip_address', __('IP-Adressen zulassen', 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('select', __('Kurzbeschreibung', 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-new', 'rrze-ac-new-section');        
        add_settings_field('description', __('Beschreibung', 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-new', 'rrze-ac-new-section');
                        
        add_settings_section('rrze-ac-edit-section', FALSE, '__return_false', 'rrze-ac-edit');    
        add_settings_field('permission_key', __('Berechtigung', 'rrze-ac'), array($this, 'permission_key_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('logged_in', __('Angemeldet', 'rrze-ac'), array($this, 'permission_logged_in_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permission_sso_logged_in_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('ip_address', __('IP-Adressen zulassen', 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('select', __('Kurzbeschreibung', 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');        
        add_settings_field('description', __('Beschreibung', 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
                
        add_settings_section('rrze-ac-settings-section', FALSE, '__return_false', 'rrze-ac-settings');        
        add_settings_field('default_permission', __('Standardberechtigung', 'rrze-ac'), array($this, 'default_permission_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');        
    }
    
    public function permission_key_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->get_permission($permission_key);
        $readonly = $permission_key ? ' readonly="readonly"' : '';
        $permission_key = isset($settings_errors['permission_key']['value']) && !$readonly ? $settings_errors['permission_key']['value'] : $permission_key;
        $field_invalid = !empty($settings_errors['permission_key']['error']) ? 'field-invalid' : '';        
        ?>
        <input type="hidden" value="<?php echo !empty($permission['active']) ? 1 : 0; ?>" name="<?php printf('%s[active]', self::option_name); ?>">
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo $permission_key; ?>" name="<?php printf('%s[permission_key]', self::option_name); ?>"<?php echo $readonly; ?>>
        <?php
    }

    public function permission_logged_in_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->get_permission($permission_key);
        $checked = !empty($permission['logged_in']) ? TRUE : FALSE;
        $checked = !empty($settings_errors['logged_in']['value']) ? TRUE : $checked;
        ?>
        <label for="permission_logged_in">
            <input id="permission_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[logged_in]', self::option_name); ?>" value="1"> <?php _e('Der Benutzer muss angemeldet sein.', 'rrze-ac'); ?>
        </label>
        <?php
    }
    
    public function permission_sso_logged_in_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->get_permission($permission_key);
        $checked = !empty($permission['sso_logged_in']) ? TRUE : FALSE;
        $checked = !empty($settings_errors['sso_logged_in']['value']) ? TRUE : $checked;
        ?>
        <label for="permission_sso_logged_in">
            <input id="permission_sso_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[sso_logged_in]', self::option_name); ?>" value="1"> <?php _e('Der Benutzer muss SSO angemeldet sein.', 'rrze-ac'); ?>
        </label>
        <?php
    }
        
    public function permission_select_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->get_permission($permission_key);      
        $select = isset($permission['select']) ? sanitize_text_field($permission['select']) : '';
        $select = isset($settings_errors['select']['value']) ? sanitize_text_field($settings_errors['select']['value']) : $select;
        $field_invalid = !empty($settings_errors['select']['error']) ? 'field-invalid' : '';
        ?>
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo $select; ?>" name="<?php printf('%s[select]', self::option_name); ?>">
        <?php
    }
    
    public function permission_description_field() {
        $settings_errors = $this->settings_errors();
        $permission_key = $this->request_var('permission');
        $permission = $this->get_permission($permission_key);
        $description = isset($permission['description']) ? esc_textarea($permission['description']) : '';
        $description = isset($settings_errors['description']['value']) ? esc_textarea($settings_errors['description']['value']) : $description;
        ?>
        <textarea id="description" cols="50" rows="5" name="<?php printf('%s[description]', self::option_name); ?>"><?php echo $description; ?></textarea>
        <?php
    }
    
    public function default_permission_field() {
        $default_permission = $this->get_default_permission();
        $permissions = $this->get_the_permissions();
        ?>
        <select id="access-permission-select" name="<?php printf('%s[default_permission]', self::option_name); ?>">
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
        $permission = $this->get_permission($permission_key);
        $ip_address = !empty($permission['ip_address']) ? (array) $permission['ip_address'] : array('');
        $ip_address = isset($settings_errors['ip_address']['value']) ? (array) $settings_errors['ip_address']['value'] : $ip_address;
        foreach($ip_address as $key => $value) {
            $field_invalid = isset($settings_errors['ip_address-' . $key]) ? 'field-invalid' : '';
        ?>
        <div id="ipAddressDiv">
            <?php if($key == 0) : ?>
            <p><input type="text" id="ipAddressInput" class="regular-text <?php echo $field_invalid; ?>" name="<?php printf('%s[ip_address][%d]', self::option_name, $key); ?>" value="<?php echo (isset($ip_address[$key])) ? $ip_address[$key] : $value; ?>"> <span id="addInput" class="dashicons dashicons-plus"> </span></p>
            <?php else : ?>
            <p><input type="text" id="ipAddressInput-<?php echo $key; ?>" class="regular-text <?php echo $field_invalid; ?>" name="<?php printf('%s[ip_address][%d]', self::option_name, $key); ?>" value="<?php echo (isset($ip_address[$key])) ? $ip_address[$key] : $value; ?>"> <span id="removeInput" class="remove-input dashicons dashicons-no" onclick="removeMe(<?php echo $key; ?>)"> </span></p>
            <?php endif; ?>
        </div>
        <?php } ?>
        <script>
            jQuery(document).ready(function($) {
                var i = $('#ipAddressDiv p').size();
                $('#addInput').click(function() {
                    $('<p><input type="text" id="ipAddressInput-' + i +'" class="regular-text" name="<?php echo self::option_name; ?>[ip_address][' + i +']" value=""> <span id="removeInput" class="remove-input dashicons dashicons-no" onclick="removeMe('+ i +')"> </span></p>').appendTo(ipAddressDiv);
                    i++;
                    $('.remove-input').css('cursor', 'pointer');
                    return false;
                });
                removeMe = function(id) {
                    if( i > 1 ) {
                       $('#ipAddressInput-'+id).parents('p').remove();
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
        
        $permission = $this->get_permission($permission_key);
        
        if($page == 'rrze-ac' && !empty($permission)) {
            switch ($action) {
                case 'activate':
                    if (!wp_verify_nonce($nonce, 'activate')) {
                        wp_die(__('Schummeln, was?', 'rrze-ac'));
                    }                   
                    if ($this->action_activate($permission)) {
                        $this->add_admin_notice(__('Die Berechtigung wurde aktiviert.', 'rrze-ac'));
                        wp_redirect($this->options_url());
                        exit();                        
                    }
                    break;
                case 'deactivate':
                    if (!wp_verify_nonce($nonce, 'deactivate')) {
                        wp_die(__('Schummeln, was?', 'rrze-ac'));
                    }
                    if ($this->action_activate($permission, 0)) {
                        $this->add_admin_notice(__('Die Berechtigung wurde deaktiviert.', 'rrze-ac'));
                        wp_redirect($this->options_url());
                        exit();                        
                    }
                    break;
                case 'delete':
                    if (!wp_verify_nonce($nonce, 'delete')) {
                        wp_die(__('Schummeln, was?', 'rrze-ac'));
                    }
                    if ($this->action_delete($permission)) {
                        $this->add_admin_notice(__('Die Berechtigung wurde gelöscht.', 'rrze-ac'));
                        wp_redirect($this->options_url());
                        exit();                        
                    }
                    break;
                default:
                    break;
            }
        }
    }
                
    public function process_bulk_activate($permission_keys = array(), $activate = 1) {
        foreach ($permission_keys as $value) {
            $permission = $this->get_permission($value);
            $this->action_activate($permission, $activate);
        }
        wp_redirect($this->options_url());
        exit();
    }
    
    public function process_bulk_delete($permission_keys = array()) {
        foreach ($permission_keys as $value) {
            $permission = $this->get_permission($value);
            $this->action_delete($permission);
        }
        wp_redirect($this->options_url());
        exit();
    }
    
    private function action_activate($permission, $activate = 1) {
        if(!isset($permission['permission_key']) || !$activate && $permission['permission_key'] == $this->get_default_permission()) {
            return FALSE;
        }                
        $permission_key = $permission['permission_key'];
        self::$options['permissions'][$permission_key]['active'] = $activate;
        return update_option(self::option_name, self::$options);   
    }
    
    private function action_delete($permission) {
        if(!isset($permission['permission_key']) 
                || $permission['core'] 
                || $permission['permission_key'] == $this->get_default_permission()
                || !empty($this->count_meta_keys($permission['permission_key']))) {
            return FALSE;
        }
        $permission_key = $permission['permission_key'];
        unset(self::$options['permissions'][$permission_key]);
        return update_option(self::option_name, self::$options);
    }
            
    private function validate_settings($input) {
        if (!isset($input['default_permission']) 
                || !isset(self::$options['permissions'][$input['default_permission']]) 
                || !self::$options['permissions'][$input['default_permission']]['active']) {
            return FALSE;
        }        
        self::$options['default_permission'] = $input['default_permission'];       
        return update_option(self::option_name, self::$options);
    }
    
    private function validate_new($input) {
        $input = (array) $input;
        
        $permission_key = !empty($input['permission_key']) ? sanitize_title($input['permission_key']) : '';
        
        if(!$permission_key) {
            $this->add_settings_error('permission_key', '', __('Berechtigung erforderlich.', 'rrze-ac'));
        } elseif (isset(self::$options['permissions'][$permission_key])) {
            $this->add_settings_error('permission_key', $permission_key, __('Berechtigung existiert bereits.', 'rrze-ac'));
        } else {
            $this->add_settings_error('permission_key', $permission_key, '', FALSE);
        }
           
        $select = !empty($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';
        
        if(!$select) {
            $this->add_settings_error('select', '', __('Kurzbeschreibung erforderlich.', 'rrze-ac'));
        } else {
            $this->add_settings_error('select', $select, '', FALSE);
        }
        
        $ip_address = !empty($input['ip_address']) && is_array($input['ip_address']) ? array_filter($input['ip_address']) : '';
        $ip_range = $this->get_ip_range($ip_address);
        $ip_address = !empty($ip_range) ? $ip_range : '';
        
        $description = !empty($input['description']) ? $input['description'] : '';
        
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
        
        self::$options['permissions'] = array_merge(self::$options['permissions'], array($permission_key => $new_permission));
        
        if (update_option(self::option_name, self::$options)) {
            return $permission_key;
        }
        
        return FALSE;
    }
        
    private function validate_edit($permission, $input) {
        $permission_key = $permission['permission_key'];
        
        $select = isset($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';       
        if(!$select) {
            $this->add_settings_error('select', '', __('Kurzbeschreibung erforderlich.', 'rrze-ac'));
        } else {
            $this->add_settings_error('select', $select, '', FALSE);
        }
      
        $permission['select'] = $select;
        
        $description = isset($input['description']) ? $input['description'] : '';
        $permission['description'] = $description;
        
        $ip_address = !empty($input['ip_address']) && is_array($input['ip_address']) ? array_filter($input['ip_address']) : '';
        $ip_range = $this->get_ip_range($ip_address);
        $ip_address = !empty($ip_range) ? $ip_range : '';
        $permission['ip_address'] = $ip_address;
        
        $logged_in = !empty($input['logged_in']) ? 1 : 0;
        $sso_logged_in = !empty($input['sso_logged_in']) ? 1 : 0;
                
        if ($this->settings_errors()) {
            $this->add_settings_error('logged_in', $logged_in, '', FALSE);
            $this->add_settings_error('sso_logged_in', $sso_logged_in, '', FALSE);
            $this->add_settings_error('ip_address', $ip_address, '', FALSE);
            $this->add_settings_error('description', $description, '', FALSE);
            return FALSE;
        }    
        
        if(!$permission['core']) {
            $permission['logged_in'] = $logged_in;
            $permission['sso_logged_in'] = $sso_logged_in;
        }
                
        self::$options['permissions'][$permission_key] = $permission;
        
        return update_option(self::option_name, self::$options);
    }
    
    private function meta_values() {
        global $wpdb;
        
        $metas = array();
        
        $result = $wpdb->get_results ("
            SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '" . self::access_permission_meta_key . "'
            AND ((p.post_type = 'attachment' AND p.post_status = 'inherit') OR (p.post_type = 'page' AND p.post_status = 'publish'))");
            
        foreach ($result as $r) {
            $metas[$r->post_id] = $r->meta_value;
        }
        
        return $metas;
    }
    
    public function count_meta_keys($permission_key) {
        $metas = $this->meta_values();
        return array_keys($metas, $permission_key, TRUE);
    }
    
    public function options_url($atts = array()) {
        $atts = array_merge(
            array(
                'page' => 'rrze-ac'
            ), $atts
        );

        if (isset($atts['action'])) {
            switch ($atts['action']) {
                case 'activate':
                    $atts['nonce'] = wp_create_nonce('activate');
                    break;
                case 'deactivate':
                    $atts['nonce'] = wp_create_nonce('deactivate');
                    break;
                case 'delete':
                    $atts['nonce'] = wp_create_nonce('delete');
                    break;                
                default:
                    break;
            }
        }
        
        return add_query_arg($atts, get_admin_url(NULL, 'admin.php'));
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
    
    public function admin_notices() {        
        $this->display_admin_notices();
    }
    
    public function add_admin_notice($message, $class = 'updated') {
        $allowed_classes = array('error', 'updated');
        if (!in_array($class, $allowed_classes)) {
            $class = 'updated';
        }

        $transient = self::notice_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $notices = maybe_unserialize($transient_value ? $transient_value : array());
        $notices[$class][] = $message;

        set_transient($transient, $notices, self::notice_transient_expiration);
    }

    public function display_admin_notices() {
        $transient = self::notice_transient . get_current_user_id();
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
        $transient = self::settings_error_transient . get_current_user_id();
        $transient_value = get_transient($transient);
        $errors = maybe_unserialize($transient_value ? $transient_value : array());
        $errors[$field] = array('value' => $value, 'message' => $message, 'error' => $error);

        set_transient($transient, $errors, self::settings_error_transient_expiration);
    }
    
    public function settings_errors() {
        $transient = self::settings_error_transient . get_current_user_id();
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
        delete_transient(self::settings_error_transient . get_current_user_id());
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
                    $this->add_settings_error('ip_address-' . $key, $ip_address, sprintf(__('Die IP-Adresse %s ist nicht gültig.', 'rrze-ac'), $value));
                }
            }
        }
        return $ip_range;
    }
    
    public function change_upload_directory($param) {

        if (isset($_POST['access_protected']) && 'on' == $_POST['access_protected']) {
            $param['subdir'] = self::protected_upload_dir($param['subdir'], TRUE);
            $param['path'] = $param['basedir'] . $param['subdir'];
            $param['url'] = $param['baseurl'] . $param['subdir'];
        }

        return $param;
    }
    
    public function attachment_fields_to_edit($form_fields, $post) {

        if (!is_null(get_current_screen())) {
            return $form_fields;
        }

        $permission = get_post_meta( $post->ID, self::access_permission_meta_key, TRUE );

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = $this->get_default_permission();
        }

        ob_start();
        ?>
        <tr id="access-attachment-fields">
            <th class="label" scope="row">
                <label for="attachments-1054405-attachment_tag">
                    <span class="alignleft"><?php esc_html_e('Zugriffsbeschränkung', 'rrze-ac'); ?></span>
                    <br class="clear">
                </label>
            </th>
            <td class="field">
                <input type="hidden" name="attachments[<?php echo $post->ID ?>][access_protection_toggle]" value="off">
                <input class="radio access-protection-toggle" type="checkbox" id="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" name="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" <?php checked($this->is_attachment_protected($post->ID )); ?>>
                <p id="access-attachment-permissions-field">
                    <label for="attachments[<?php echo $post->ID; ?>][access_permission_select]"><?php esc_html_e('Berechtigung', 'rrze-ac' ); ?></label>
                    <select class="access-permission-select" id="attachments[<?php echo $post->ID; ?>][access_permission_select]" name="attachments[<?php echo $post->ID; ?>][access_permission_select]">
                        <?php foreach ($permissions as $key => $data) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                            <?php echo sanitize_text_field($data['select']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <script>
                    jQuery(document).ready(function($) {
                        $('#access-attachment-fields').trigger('accessLoaded', <?php echo $post->ID; ?>);
                    });
                </script>
            <td>
        </tr>
        <?php
        $form_fields['access_permission_fields']['tr'] = ob_get_clean();

        return $form_fields;
    }

    public function save_attachment_edit_fields($post, $attachment) {

        if (!isset($attachment['access_protection_toggle'])) {
            return $post;
        }
        
        $attachment_id = $post['ID'];

        switch ($attachment['access_protection_toggle']) {

            case 'off' :
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return $post;
                }
                
                delete_post_meta($attachment_id, self::access_permission_meta_key);

                return $post;

            case 'on':
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_to_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return $post;
                }
                
                if (!isset($_POST['access_permission_select']) || empty($_POST['access_permission_select'])) {
                    return $post;
                }

                $permissions = $this->get_the_permissions();

                if (!isset($permissions[$_POST['access_permission_select']])) {
                    delete_post_meta($attachment_id, self::access_permission_meta_key);
                } else {
                    update_post_meta($attachment_id, self::access_permission_meta_key, $_POST['access_permission_select']);
                }
                
                return $post;
                
            default:
                return $post;
        }
    }
       
    public function get_default_permission() {
        $permissions = $this->get_the_permissions();
        $default_permission = isset($permissions[self::$options['default_permission']]) && $permissions[self::$options['default_permission']]['active'] ? self::$options['default_permission'] : 'logged-in';       
        return $default_permission;
    }
    
    public function get_the_permissions() {
        $access_permissions = self::$options['permissions'];
        return apply_filters('access_edit_permissions', $access_permissions);
    }

    private function get_the_permission($post_id) {

        if (get_post_type($post_id) == 'attachment') {
            return $this->get_attachment_permission($post_id);
        }
        
        $permission = get_post_meta($post_id, self::access_permission_meta_key, TRUE);

        return !empty($permission) ? $permission : FALSE;
    }
    
    private function check_author_permission($post_id) {
        if(!is_user_logged_in()) {
            return FALSE;
        }
        
        if (current_user_can('manage_options')) {
            return TRUE;
        }
        
        $current_user = wp_get_current_user();
                
        $post = get_post($post_id);
                
        $post_author = $post->post_author;
        
        $authors = $this->post_authors($post_id, $post_author);
                
        if(isset($authors[$current_user->ID])) {
            return TRUE;
        }
        
        return FALSE;
    }
        
    private function post_authors($post_id, $post_author) {
        $authors = array();

        // CMS-Workflow stuff
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if($this->is_plugin_active('cms-workflow/cms-workflow.php')) {
            $authors = $this->workflow_authors($post_id);
        }
        
        $authors[$post_author] = $post_author;
        
        return $authors;
    }
    
    private function workflow_authors($post_id) {
        global $wpdb;
        
        $authors = array();
        
        $workflow_authors = $wpdb->get_col($wpdb->prepare(
            "
            SELECT t.name 
            FROM $wpdb->terms AS t
            INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id
            INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.taxonomy IN ('workflow_author') AND tr.object_id IN (%d) ORDER BY t.name ASC
            ",
            $post_id
            )
        );
        
        if($workflow_authors) {
            foreach ($workflow_authors as $author) {
                $user = get_user_by('login', $author);
                if (!$user || !is_user_member_of_blog($user->ID)) {
                    continue;
                }

                $authors[$user->ID] = $user->ID;
            }
        }
        
        return $authors;        
    }
    
    private function get_attachment_permission($attachment_id) {
        if (!$this->is_attachment_protected($attachment_id)) {
            return FALSE;
        }
                
        $permission = get_post_meta($attachment_id, self::access_permission_meta_key, TRUE);

        return empty($permission) ? $this->get_default_permission() : $permission;        
    }
    
    private function is_attachment_protected($attachment_id) {

        $file = get_post_meta($attachment_id, '_wp_attached_file', TRUE);

        if (!empty($file) && (0 === stripos($file, self::protected_upload_dir('/')))) {
            return TRUE;
        }

        return FALSE;
    }
    
    private function check_ip_address_range($ip_address) {
        if(empty($ip_address) || !is_array($ip_address)) {
            return TRUE;
        }
        
        $remote_addr = $this->get_remote_ip_address();

        if($remote_addr === FALSE) {
            return FALSE;
        }

        $ip = IP::fromStringIP($remote_addr);
        
        if($ip->isInRanges($ip_address)) {
            return TRUE;
        }
        
        return FALSE;        
    }
    
    private function get_remote_ip_address() {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip_address = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip_address = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip_address = FALSE;
        }
        
        return $ipaddress;
    }
        
    private function check_sso_logged_in() {
        if (!$this->is_plugin_active($this->websso_plugin)) {
            return FALSE;
        }
        
        if (is_multisite()) {
            $options = get_site_option($this->websso_option_name);
        } else {
            $options = get_option($this->websso_option_name);
        }
        
        if (!isset($options['simplesaml_include']) || !isset($options['simplesaml_auth_source'])) {
            return FALSE;
        }
        
        include_once(WP_CONTENT_DIR . $options['simplesaml_include']);
        
        if(!class_exists('SimpleSAML_Auth_Simple')) {
            return FALSE;
        }

        $as = new SimpleSAML_Auth_Simple($options['simplesaml_auth_source']);
        
        if ($as->isAuthenticated()) {
            $attributes = $as->getAttributes();
            $this->person_affiliation = isset($attributes['urn:mace:dir:attribute-def:eduPersonAffiliation'][0]) ? $attributes['urn:mace:dir:attribute-def:eduPersonAffiliation'][0] : NULL;
            $this->person_entitlement = isset($attributes['urn:mace:dir:attribute-def:eduPersonEntitlement'][0]) ? $attributes['urn:mace:dir:attribute-def:eduPersonEntitlement'][0] : NULL;                 
            return TRUE;
        }
        
        $as->requireAuth(); // redirect to IdP
        exit();
    }
    
    private function check_permission($post_id) {
        
        if(empty($post_id)) {
            return FALSE;
        }              
                       
        if($this->check_author_permission($post_id)) {
            return TRUE;
        }
        
        if (!$permission = $this->get_the_permission($post_id)) {
            return TRUE;
        }
        
        $permissions = $this->get_the_permissions();
        
        // set permission to default permission if not exist or not active
        if (!isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = $this->get_default_permission();
        }
                
        // check if permission is set to be logged in
        if (!is_user_logged_in() && isset($permissions[$permission]['logged_in']) && $permissions[$permission]['logged_in']) {
            $this->set_permission_status(self::user_isnt_logged_in);
            return FALSE;
        }
             
        // check if permission is set to be sso logged in
        elseif (!empty($permissions[$permission]['sso_logged_in']) && !$this->check_sso_logged_in()) {
            $this->set_permission_status(self::user_isnt_sso_logged_in);
            return FALSE;
        }      
        
        // check if permission is set to ip address
        elseif (!empty($permissions[$permission]['ip_address']) && !$this->check_ip_address_range($permissions[$permission]['ip_address'])) {
            $this->set_permission_status(self::user_ip_isnt_in_range);
            return FALSE;
        }
                
        return TRUE;
    }
    
    public function attachment_edit_meta_box() {
        add_meta_box(
            'attachment-protection-metabox',
            __('Zugriffsbeschränkung', 'rrze-ac' ),
            array($this, 'post_protection_metabox'),
            'attachment',
            'side'
        );
    }
        
    public function post_protection_metabox($post) {
        wp_nonce_field('attachment_protection_metabox', 'attachment_protection_metabox_nonce');

        $permission = get_post_meta($post->ID, self::access_permission_meta_key, TRUE);

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = $this->get_default_permission();
        }
        ?>
        <input type="hidden" name="access_protection_toggle" value="off">
        <input type="checkbox" id="access-protection-toggle" name="access_protection_toggle" <?php checked($this->is_attachment_protected($post->ID)); ?>>
        <label class="access-protection-toggle" for="access-protection-toggle">
            <span aria-role="hidden" class="access-on button button-primary" data-access-content="<?php esc_attr_e('Berechtigung aktivieren', 'rrze-ac'); ?>"></span>
            <span aria-role="hidden" class="access-off" data-access-content="<?php esc_attr_e('Berechtigung entfernen', 'rrze-ac'); ?>"></span>
        </label>
        <div class="access-permission-select">
            <label for="access-permission-select">
                <span class="description"><?php esc_html_e('Berechtigung', 'rrze-ac'); ?></span>
            </label>
            <select id="access-permission-select" name="access_permission_select">
            <?php foreach ($permissions as $key => $data) : ?>
                <?php if (!$data['active']) continue; ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                    <?php echo sanitize_text_field($data['select']); ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
    
    public function post_protection_submitbox() {
        global $post;
        
        if (get_post_type($post->ID) != 'page') {
            return;
        }

        wp_nonce_field('post_protection_submitbox', 'post_protection_submitbox_nonce');


        $permission = get_post_meta($post->ID, self::access_permission_meta_key, TRUE);

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = 'all';
        }
        
        $label = $permissions[$permission]['select'];
        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon';
        ?>
        <div id="post-protection-wrap" class="misc-pub-section">
            <span>
                <span id="access-icon" class="<?php echo $class; ?> dashicons dashicons-shield"></span>
                <?php _e('Berechtigung:', 'rrze-ac'); ?>
                <b id="post-protection-label"><?php echo $label; ?></b>
            </span>
            <a href="#" id="edit-post-protection" class="edit-post-protection hide-if-no-js">
                <span aria-hidden="true"><?php _e('Bearbeiten', 'rrze-ac'); ?></span>
                <span class="screen-reader-text"><?php _e('Berechtigung bearbeiten', 'rrze-ac'); ?></span>
            </a>
            <div id="post-protection-field" class="hide-if-js">
                <select id="access-permission-select" name="access_permission_select">
                <?php foreach ($permissions as $key => $data) : ?>
                    <?php if (!$data['active']) continue; ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                        <?php echo sanitize_text_field($data['select']); ?>
                    </option>
                <?php endforeach; ?>
                </select>
                <a href="#" class="save-post-protection hide-if-no-js button"><?php _e('OK', 'rrze-ac'); ?></a>
                <a href="#" class="cancel-post-protection hide-if-no-js button-cancel"><?php _e('Abbrechen', 'rrze-ac'); ?></a>
            </div>
        </div>        
        <?php
    }
    
    public function save_post_data($post_id) {
        
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit'])) {
            return;
        }

        if (!isset($_POST['post_protection_submitbox_nonce']) || !wp_verify_nonce($_POST['post_protection_submitbox_nonce'], 'post_protection_submitbox')) {
            return;
        }

        if (get_post_type($post_id) != 'page') {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!isset($_POST['access_permission_select']) || empty($_POST['access_permission_select'])) {
            return;
        }
        
        $permissions = $this->get_the_permissions();

        if (isset($permissions[$_POST['access_permission_select']]) && 'all' == $_POST['access_permission_select']) {
            delete_post_meta($post_id, self::access_permission_meta_key);
        } elseif (isset($permissions[$_POST['access_permission_select']])) {
            update_post_meta($post_id, self::access_permission_meta_key, $_POST['access_permission_select']);
        }
        
    }
  
    public function save_attachment_data($attachment_id) {

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit'])) {
            return;
        }

        if (!isset($_POST['attachment_protection_metabox_nonce']) || !wp_verify_nonce($_POST['attachment_protection_metabox_nonce'], 'attachment_protection_metabox')) {
            return;
        }    
        
        if (!current_user_can('edit_post', $attachment_id)) {
            return;
        }

        if (!isset($_POST['access_protection_toggle'])) {
            return;
        }
        
        switch ($_POST['access_protection_toggle']) {

            case 'off' :
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return;
                }
                
                delete_post_meta($attachment_id, self::access_permission_meta_key);

                break;

            case 'on':
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_to_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return;
                }
                
                if (!isset($_POST['access_permission_select']) || empty($_POST['access_permission_select'])) {
                    return;
                }

                $permissions = $this->get_the_permissions();

                if (!isset($permissions[$_POST['access_permission_select']])) {
                    delete_post_meta($attachment_id, self::access_permission_meta_key);
                } else {
                    update_post_meta($attachment_id, self::access_permission_meta_key, $_POST['access_permission_select']);
                }
                
                break;
            
            default: return;
        }
    }
        
    private function move_attachment_from_protected($attachment_id) {

        $file = get_post_meta($attachment_id, '_wp_attached_file', TRUE);

        if (0 !== stripos($file, self::protected_upload_dir('/'))) {
            return TRUE;
        }

        $new_reldir = ltrim(dirname($file), self::protected_upload_dir('/'));

        return $this->move_attachment_files($attachment_id, $new_reldir);
    }
  
    private function move_attachment_to_protected($attachment_id) {

        $file = get_post_meta($attachment_id, '_wp_attached_file', TRUE);

        if (0 === stripos($file, self::protected_upload_dir('/'))) {
            return TRUE;
        }

        $reldir = dirname($file);
        if (in_array($reldir, array('\\', '/', '.'), TRUE)) {
            $reldir = '';
        }

        $new_reldir = path_join(self::protected_upload_dir(), $reldir);

        return $this->move_attachment_files($attachment_id, $new_reldir);
    }
    
    private function move_attachment_files($attachment_id, $new_reldir) {

        if ('attachment' != get_post_type($attachment_id)) {
            return new WP_Error('not_attachment', sprintf(
                __('Das Post %d is kein Medien-Post-Type.', 'rrze-ac'), $attachment_id
            ));
        }
        
        if (path_is_absolute($new_reldir)) {
            return new WP_Error('new_reldir_not_relative', sprintf(
                __('Der neu angegebenen Pfad %s ist absolut. Der neue Pfad muss ein Pfad relativ zum WP-Uploads-Verzeichnis sein.', 'rrze-ac'), $new_relpath
            ));
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        $file = get_post_meta($attachment_id, '_wp_attached_file', TRUE);

        $backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', TRUE);

        $upload_dir = wp_upload_dir();

        $old_reldir = dirname($file);
        if (in_array($old_reldir, array('\\', '/', '.'), TRUE)) {
            $old_reldir = '';
        }

        if ($new_reldir === $old_reldir) {
            return NULL;
        }

        $old_fulldir = path_join($upload_dir['basedir'], $old_reldir);
        $new_fulldir = path_join($upload_dir['basedir'], $new_reldir);

        if (!wp_mkdir_p($new_fulldir)) {
            return new WP_Error('wp_mkdir_p_error', sprintf(
                __('Ein Fehler ist aufgetreten bei der Erstellung des Verzeichnis %s', 'rrze-ac'), $new_fulldir
            ));
        }

        $meta_sizes = array();
        if (isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $meta_sizes[] = $size['file'];
            }
        }

        $backup_sizes = array();
        if (is_array($backups)) {
            foreach ($backups as $size) {
                $backup_sizes[] = $size['file'];
            }
        }

        $old_basenames = $new_basenames = array_merge(array(basename($file)), $meta_sizes, $backup_sizes);

        $orig_basename = basename($file);
        if (is_array($backups) && isset($backups['full-orig'])) {
            $orig_basename = $backups['full-orig']['file'];
        }

        $orig_filename = pathinfo($orig_basename);
        $orig_filename = $orig_filename['filename'];
        $conflict = TRUE;
        $number = 1;
        $separator = '#';
        $med_filename = $orig_filename;

        while ($conflict) {
            $conflict = FALSE;
            foreach ($new_basenames as $basename) {
                if (is_file(path_join($new_fulldir, $basename))) {
                    $conflict = TRUE;
                    break;
                }
            }

            if ($conflict) {
                $new_filename = "$orig_filename$number";
                $number++;
                $pattern = "$separator$med_filename";
                $replace = "$separator$new_filename";
                $new_basenames = explode($separator, ltrim(str_replace($pattern, $replace, $separator . implode($separator, $new_basenames)), $separator));
                $med_filename = $new_filename;
            }
        }

        $unique_old_basenames = array_values(array_unique($old_basenames));
        $unique_new_basenames = array_values(array_unique($new_basenames));

        $i = count($unique_old_basenames);
        while ($i--) {
            $old_fullpath = path_join($old_fulldir, $unique_old_basenames[$i]);
            $new_fullpath = path_join($new_fulldir, $unique_new_basenames[$i]);

            rename($old_fullpath, $new_fullpath);

            if (!is_file($new_fullpath)) {
                return new WP_Error('rename_failed', sprintf(
                    __('Die Datei kann nicht von %s nach %s verschoben werden.', 'rrze-ac'), $old_fullpath, $new_fullpath
                ));
            }
        }

        $meta['file'] = path_join($new_reldir, $new_basenames[0]);
        update_post_meta($attachment_id, '_wp_attached_file', $meta['file']);

        if ($new_basenames[0] != $old_basenames[0]) {
            $orig_basename = ltrim(str_replace($pattern, $replace, $separator . $orig_basename), $separator);

            if (is_array($meta['sizes'])) {
                $i = 0;
                foreach ($meta['sizes'] as $size => $data) {
                    $meta['sizes'][$size]['file'] = $new_basenames[++$i];
                }
            }

            if (is_array($backups)) {
                $i = 0;
                $l = count($backups);
                $new_backup_sizes = array_slice($new_basenames, -$l, $l);

                foreach ($backups as $size => $data) {
                    $backups[$size]['file'] = $new_backup_sizes[$i++];
                }
                update_post_meta($attachment_id, '_wp_attachment_backup_sizes', $backups);
            }
        }

        update_post_meta($attachment_id, '_wp_attachment_metadata', $meta);

        $guid = path_join($new_fulldir, $orig_basename);
        wp_update_post(array('ID' => $attachment_id, 'guid' => $guid));

        return TRUE;
    }
    
    public function request_file() {
        if (isset($_GET['protected_file']) && !empty($_GET['protected_file'])) {
            
            if (isset($_GET['access_rewrite_test']) && $_GET['access_rewrite_test']) {
                die('rewrite test passed');
            }
            
            $this->get_file($_GET['protected_file']);
            exit();
        }
    }
    
    private function get_file($rel_file) {

        $rel_file = isset($rel_file) ? $rel_file : '';
        $upload_dir = wp_upload_dir();

        if (empty($upload_dir['basedir'])) {
            status_header(404);
            wp_die(__('Die angeforderte Datei wurde nicht gefunden.', 'rrze-ac'));
        }

        $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);

        if (!is_file($file)) {
            $rel_file = str_replace('_protected', '', rtrim($rel_file, '/'));
            $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);
            if (!is_file($file)) {
                status_header(404);
                wp_die(__('Die angeforderte Datei wurde nicht gefunden.', 'rrze-ac'));
            }
        }

        $mime = wp_check_filetype($file);

        if (isset($mime['type']) && $mime['type']) {
            $mimetype = $mime['type'];
        } else {
            status_header(403);
            wp_die(__('Die Anfrage wurde mangels Berechtigung des Clients nicht durchgeführt.', 'rrze-ac'));
        }

        $file_info = pathinfo($rel_file);

        // Start der Berechtigungsprüfungen
        if (0 === stripos($file_info['dirname'] . '/', self::protected_upload_dir('/', TRUE))) {

            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', 1);
            }

            if (!defined('DONOTCACHEOBJECT')) {
                define('DONOTCACHEOBJECT', 1);
            }

            if (!defined('DONOTMINIFY')) {
                define('DONOTMINIFY', 1);
            }

            global $wpdb;
            $attachments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s", 
                    '_wp_attachment_metadata', 
                    '%' . $file_info['basename'] . '%'
                ), ARRAY_A
            );

            $attachment_id = 0;
            foreach ($attachments as $attachment) {

                $meta_value = unserialize($attachment['meta_value']);

                if (ltrim(dirname($meta_value['file']), '/') == ltrim($file_info['dirname'], '/')) {
                    $attachment_id = $attachment['post_id'];
                    break;
                }
            }

            if (!$this->check_permission($attachment_id)) {
                status_header(403);
                wp_die($this->permission_forbidden_message($attachment_id));
            }
            
        } // Ende der Berechtigungsprüfungen

        header('Content-Type: ' . $mimetype);
        header('Content-Length: ' . filesize($file));

        $last_modified = gmdate('D, d M Y H:i:s', filemtime($file));
        $etag = '"' . md5($last_modified) . '"';
        header("Last-Modified: $last_modified GMT");
        header('ETag: ' . $etag);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');

        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : FALSE;

        if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $_SERVER['HTTP_IF_MODIFIED_SINCE'] = FALSE;
        }

        $client_last_modified = trim($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        $client_modified_timestamp = $client_last_modified ? strtotime($client_last_modified) : 0;

        $modified_timestamp = strtotime($last_modified);

        if (($client_last_modified && $client_etag) ? (($client_modified_timestamp >= $modified_timestamp) && ($client_etag == $etag)) : (($client_modified_timestamp >= $modified_timestamp) || ($client_etag == $etag))) {
            status_header(304);  // Not Modified
            exit();
        }

        if (ob_get_length()) {
            ob_clean();
        }

        flush();

        readfile($file);
        exit();
    }

    private static function protected_upload_dir($path = '', $in_url = FALSE) {
        $dirpath = $in_url ? '/' : '';
        $dirpath .= self::protected_dirname;
        $dirpath .= $path;

        return $dirpath;
    }
    
    public function image_downsize_placeholder($img, $attachment_id, $size) {
        $upload_dir = wp_upload_dir();

        if (isset($img[0]) && 0 !== strpos(ltrim($img[0], $upload_dir['baseurl']), self::protected_upload_dir('/', TRUE))) {
            return $img;
        }
        
        if ($this->check_permission($attachment_id)) {
            return $img;
        }
        
        if (!$this->is_attachment_protected($attachment_id)) {
            remove_filter('image_downsize', array($this, 'image_downsize_placeholder'), 999, 3);
            
            $placeholder = wp_get_attachment_image_src($attachment_id, $size);
            
            add_filter('image_downsize', array($this, 'image_downsize_placeholder'), 999, 3);
            
            return $placeholder;
        } else {
            list($width, $height) = image_constrain_size_for_editor(1024, 1024, $size);

            return array(
                plugins_url('images/media-placeholder.jpg', __FILE__),
                $width,
                $height,
                FALSE
            );
        }
    }
    
    public function media_row_actions($actions, $post) {

        if (!$this->check_permission($post->ID)) {
            return array(esc_html__('Sie verfügen nicht über ausreichende Berechtigungen, um auf die Datei zugreifen zu können.', 'rrze-ac'));
        }
        
        return $actions;
    }
    
    public function manage_pages_column($columns) {
        $columns['access_info'] = '<span title="' . esc_attr__('Zugriffsbeschränkung', 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
        return $columns;
    }

    public function manage_pages_custom_column($column_name, $post_id) {
        if ('access_info' != $column_name) {
            return;
        }

        if (!$permission = $this->get_the_permission($post_id)) {
            return;
        }

        $error = '';
        $permissions = $this->get_the_permissions();

        if (!isset($permissions[$permission])) {
            $permission = $this->get_default_permission();
            $error = __('Berechtigung nicht vorhanden bzw. wurde entfernt', 'rrze-ac');
        }

        if (!$permissions[$permission]['active']) {
            $error = __('Berechtigung wurde deaktiviert', 'rrze-ac');
            $permission = $this->get_default_permission();
        }
        
        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];
        
        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(esc_attr__('Ein Fehler ist aufgetreten: %1$s und ist durch die Standardberechtigung &bdquo;%2$s&ldquo; ersetzt worden.', 'rrze-ac'), $error, $description) . '" class="access-error-icon dashicons dashicons-shield"></span>';
        
        echo $description;
    }
    
    public function manage_upload_columns($columns) {
        $columns['access_info'] = '<span title="' . esc_attr__('Zugriffsbeschränkung', 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
        return $columns;
    }
    
    public function manage_media_custom_column($column_name, $post_id) {
        if ('access_info' != $column_name) {
            return;
        }

        if (!$permission = $this->get_the_permission($post_id)) {
            return;
        }

        $error = '';
        $permissions = $this->get_the_permissions();

        if (!isset($permissions[$permission])) {
            $permission = $this->get_default_permission();
            $error = __('Berechtigung nicht vorhanden bzw. wurde entfernt', 'rrze-ac');
        }

        if (!$permissions[$permission]['active']) {
            $error = __('Berechtigung wurde deaktiviert', 'rrze-ac');
            $permission = $this->get_default_permission();
        }
        
        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];
        
        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(esc_attr__('Ein Fehler ist aufgetreten: %1$s und ist durch die Standardberechtigung &bdquo;%2$s&ldquo; ersetzt worden.', 'rrze-ac'), $error, $description) . '" class="access-error-icon dashicons dashicons-shield"></span>';
        
        echo $description;
    }
    
    public function media_custom_column_styles() {
        ?>

        <style type="text/css">
            .column-access_info {
                width: 120px;
            }
        </style>

        <?php
    }
    
    public function media_bulk_actions_js() {

        if (!current_user_can('edit_posts')) {
            return;
        }

        $bulk_actions = array();
        if (!isset($_GET['access-show-protected'])) {
            $bulk_actions['access-protect'] = esc_html__('Berechtigung aktivieren', 'rrze-ac');
        }
        if (!isset($_GET['access-show-unprotected'])) {
            $bulk_actions['access-unprotect'] = esc_html__('Berechtigung entfernen', 'rrze-ac');
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $.each(<?php echo json_encode($bulk_actions); ?>, function (index, value) {
                    $('<option>')
                        .val(index)
                        .text(value)
                        .appendTo('select[name="action"]')
                        .clone()
                        .appendTo('select[name="action2"]');
                });
            });
        </script>
        <?php
    }
    
    public function media_admin_notices() {

        $screen = get_current_screen();
        if ('upload' === $screen->id) {

            if (isset($_REQUEST['access-protected']) && (int) $_REQUEST['access-protected']) {
                $message = sprintf(
                    _n(
                        'Mediendatei ist nun geschützt.', //singular
                        '%s Mediendateien sind nun geschützt.', //plural
                        $_REQUEST['access-protected'], 'rrze-ac'
                    ), number_format_i18n($_REQUEST['access-protected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-protected', $_SERVER['REQUEST_URI']);
            }

            if (isset($_REQUEST['access-unprotected']) && (int) $_REQUEST['access-unprotected']) {
                $message = sprintf(
                    _n(
                        'Dateischutz auf Mediendatei entfernt.', //singular
                        'Dateischutz auf %s Mediendateien entfernt.', //plural
                        $_REQUEST['access-unprotected'], 'rrze-ac'
                    ), number_format_i18n($_REQUEST['access-unprotected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-unprotected', $_SERVER['REQUEST_URI']);
            }
        }
    }
    
    public function bulk_actions() {
        $wp_list_table = _get_list_table('WP_Media_List_Table');
        $action = $wp_list_table->current_action();

        $allowed_actions = array(
            'access-protect',
            'access-unprotect'
        );
        if (!in_array($action, $allowed_actions)) {
            return;
        }

        check_admin_referer('bulk-media');

        if (isset($_REQUEST['media'])) {
            $media_ids = array_map('intval', $_REQUEST['media']);
        }
        
        if (empty($media_ids)) {
            return;
        }

        $location = 'upload.php';
        if ($referer = wp_get_referer()) {
            if (FALSE !== strpos($referer, 'upload.php')) {
                $location = remove_query_arg(
                    array('access-protected', 'access-unprotected', 'trashed', 'untrashed', 'deleted', 'message', 'ids', 'posted'), $referer
                );
            }
        }

        $pagenum = $wp_list_table->get_pagenum();
        if ($pagenum > 1) {
            $location = add_query_arg('paged', $pagenum, $location);
        }
        
        switch ($action) {

            case 'access-protect':
                if (!current_user_can('edit_posts')) {
                    wp_die(__('Sie sind nicht erlaubt, Mediendateien zu dem geschützten Verzeichnis hinzuzufügen.', 'rrze-ac'));
                }
                
                $protected = 0;
                foreach ((array) $media_ids as $media_id) {

                    if (!current_user_can('edit_post', $media_id)) {
                        continue;
                    }

                    if ($this->is_attachment_protected($media_id)) {
                        continue;
                    }

                    $move_attachment = $this->move_attachment_to_protected($media_id);

                    if (is_wp_error($move_attachment)) {
                        wp_die(__('Ein Fehler ist aufgetreten als Sie versucht haben, die Mediendateien in dem geschützten Verzeichnis zu verschieben.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message());
                    }
                    
                    $protected++;
                }

                $location = add_query_arg(array(
                    'access-protected' => $protected,
                    'ids' => join(',', $media_ids)
                ), $location);
                break;

            case 'access-unprotect':
                if (!current_user_can('edit_posts')) {
                    wp_die(__('Sie sind nicht erlaubt, Mediendateien von dem geschützten Verzeichnis zu entfernen.', 'rrze-ac'));
                }
                
                $unprotected = 0;
                foreach ((array) $media_ids as $media_id) {

                    if (!current_user_can('edit_post', $media_id)) {
                        continue;
                    }

                    if (!$this->is_attachment_protected($media_id)) {
                        continue;
                    }
                    
                    $move_attachment = $this->move_attachment_from_protected($media_id);

                    if (is_wp_error($move_attachment)) {
                        wp_die(__('Ein Fehler ist aufgetreten beim Verschieben der Mediendateien in dem geschützten Verzeichnis.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message());
                    }
                    
                    delete_post_meta($media_id, self::access_permission_meta_key);

                    $unprotected++;
                }

                $location = add_query_arg(array(
                    'access-unprotected' => $unprotected,
                    'ids' => join(',', $media_ids)
                ), $location);
                break;

            default: return;
        }

        $location = remove_query_arg(array('action', 'action2', 'media'), $location);

        wp_redirect($location);
        exit();       
    }
    
    public function media_new_enqueue_scripts() {
        $screen = get_current_screen();
        
        if ('media' == $screen->base && 'add' == $screen->action) {
            wp_enqueue_style('access-media-new', plugins_url('css/media-new.css', __FILE__), 'all', NULL);
        }
    }
    
    public function media_new_js() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                'use strict';
                var input = $('input[name="access_protected"]'),
                    ctrl = document.getElementById('access_protected'),
                    ui = $('#plupload-upload-ui');

                function state(check) {
                    return 'access-' + (check == 'on' ? '' : 'un') + 'checked';
                }

                input.on('change', function () {
                    var check = ctrl.checked ? 'on' : 'off';
                    ui.removeClass(state(check == 'on' ? 'off' : 'on'))
                            .addClass(state(check));

                    wpUploaderInit.multipart_params.access_protected = check;
                });

                setTimeout(function () {
                    input.change();
                }, 200);
            });
        </script>
        <?php
    }
    
    public function media_new_upload_ui() {

        $screen = get_current_screen();
        if ('media' == $screen->base && 'add' == $screen->action) :
            ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="access_protected">
                                <?php esc_html_e('Zugiffsbeschränkung', 'rrze-ac'); ?>
                            </label>
                        </th>
                        <td>
                            <label for="access_protected">
                                <?php $options = get_option('access_options'); ?>
                                <input type="checkbox" id="access_protected" name="access_protected">
                                <span class="description">
                                    <?php esc_html_e('Aktivieren', 'rrze-ac'); ?>
                                </span>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php
        endif;
    }
    
    public function media_new_upload_ui_notice() {

        $screen = get_current_screen();
        if (isset($screen->base) && 'media' == $screen->base && 'add' == $screen->action) :
            ?>
            <div class="access-tag">
                <span aria-role="hidden" class="dashicons dashicons-shield"></span>
                <?php _e('Neue Dateien sind geschützt', 'rrze-ac'); ?>
            </div>
        <?php
        endif;
    }
        
    public function template_redirect() {
        global $wp_query;
        
        if(is_singular() && !empty($wp_query->posts)) {
            foreach($wp_query->posts as $post) {
                if(in_array($post->post_type, array('page', 'attachment')) && !$this->check_permission($post->ID)) {
                    status_header(403);
                    wp_die($this->permission_forbidden_message($post->ID));
                }
            }
        }
    }

    public function pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query() || $query->is_singular) {
            return $query;
        }

        $post_not_in = array();
        $permissions = $this->get_the_permissions();
        $permission_metas = $this->get_permission_metas();

        foreach ($permission_metas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !$this->check_author_permission($pm->post_id)) {
                $post_not_in[] = $pm->post_id;
            }
        }

        if (!empty($post_not_in)) {
            $query->set('post__not_in', $post_not_in);
        }

        return $query;
    }

    private function get_permission_metas() {
        global $wpdb;

        $query = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '%s' 
            AND p.post_status = 'publish'
            AND (p.post_type = 'page' OR p.post_type = 'attachment')";

        return $wpdb->get_results($wpdb->prepare($query, self::access_permission_meta_key));
    }    
    
    public function nav_menu_objects($menu_items) {
        foreach ($menu_items as $key => $menu_item) {
            if($menu_item->object == 'page' && !$this->check_permission($menu_item->object_id)) {
                unset($menu_items[$key]);
            }
        }
        
        return $menu_items;
    }
            
    private function permission_forbidden_message($post_id = NULL) {
        $message = '';
        
        $post_type = get_post_type($post_id);

        if($this->get_permission_status(self::user_isnt_logged_in) && $post_type == 'page') {
            $permalink = get_permalink($post_id);
            $message = sprintf(__('Der Zugriff auf diese Seite ist nur für Mitglieder dieser Webseite möglich. <a href="%s">Bitte melden Sie sich mit Ihrer IdM-Kennung an</a>, um den Inhalt der Seite zu sehen.', 'rrze-ac'), wp_login_url($permalink));
        } elseif($this->get_permission_status(self::user_isnt_logged_in) && $post_type == 'attachment') {
            $permalink = get_permalink($post_id);
            $message = sprintf(__('Der Zugriff auf diese Datei ist nur für Mitglieder dieser Webseite möglich. <a href="%s">Bitte melden Sie sich mit Ihrer IdM-Kennung an</a>, um die Datei herunterzuladen.', 'rrze-ac'), wp_login_url($permalink));        
        } elseif($this->get_permission_status(self::user_ip_isnt_in_range) && $post_type == 'page') {
            $message = __('Sie verfügen nicht über ausreichende Berechtigungen, um die Seite anzusehen. Falls Sie glauben, Sie müssten Zugriff auf die Seite haben, bitte kontaktieren Sie den Ansprechpartner der Webseite.', 'rrze-ac');
        } elseif($this->get_permission_status(self::user_ip_isnt_in_range) && $post_type == 'attachment') {
            $message = __('Sie verfügen nicht über ausreichende Berechtigungen, um auf die Datei zugreifen zu können. Falls Sie glauben, Sie müssten Zugriff auf die Datei haben, bitte kontaktieren Sie den Ansprechpartner der Webseite.', 'rrze-ac');
        } else {
            $message = __('Sie verfügen nicht über ausreichende Berechtigungen, um diesen Bereich anzusehen. Falls Sie glauben, Sie müssten Zugriff auf diesen Bereich haben, bitte kontaktieren Sie den Ansprechpartner der Webseite.', 'rrze-ac');            
        }
        
        return $message;        
    }
    
    private function get_permission_status($bitmask) {
        return ($this->permission_status & (1 << $bitmask)) != 0;
    }
    
    private function set_permission_status($bitmask, $new = TRUE) {
        $this->permission_status = ($this->permission_status & ~(1 << $bitmask)) | ($new << $bitmask);
    }
    
    private function is_plugin_active($plugin) {
        return in_array($plugin, (array) get_option('active_plugins', array())) || $this->is_plugin_active_for_network($plugin);
    }
 
    private function is_plugin_active_for_network($plugin) {
        if (!is_multisite()) {
            return FALSE;
        }

        $plugins = get_site_option('active_sitewide_plugins');
        if (isset($plugins[$plugin])) {
                return TRUE;
        }

        return FALSE;
    }
   
}
