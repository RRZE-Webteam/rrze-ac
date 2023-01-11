<?php

namespace RRZE\AccessControl;

use RRZE\AccessControl\Network\IP;
use RRZE\AccessControl\Network\RemoteAddress;
use RRZE\AccessControl\Crawler\Siteimprove;
use WP_Error;

defined('ABSPATH') || exit;

class Main
{
    public $options;
    public $option_name;
    public $enabled_option_name;

    public $settings;
    public $page_slug;
    public $settings_prefix;

    public $protected_dirname = '_protected';
    public $access_permission_meta_key = '_access_permission';

    private $user_isnt_logged_in = 0;
    private $user_ip_isnt_in_range = 1;
    private $user_isnt_sso_logged_in = 2;
    private $user_hasnt_affiliation = 4;
    private $user_hasnt_entitlement = 8;
    private $user_domain_not_allowed = 16;
    private $wrong_password = 32;

    private $permission_status = null;

    private $sso_plugin = 'rrze-sso/rrze-sso.php';
    // Backward compatibility
    private $websso_plugin = 'fau-websso/fau-websso.php';

    private $sso_option_name = 'rrze_sso';
    // Backward compatibility
    private $websso_option_name = '_fau_websso';

    private $simplesaml_auth = null;

    protected $person_attributes = null;

    protected $person_affiliation = null;

    protected $person_entitlement = null;

    public function __construct()
    {
        $this->options = Options::getOptions();
        $this->option_name = Options::getOptionName();
        $this->enabled_option_name = Options::getEnabledOptionName();

        $this->settings = new Settings($this);

        add_action('init', array($this, 'request_file'), 0);

        add_action('init', array($this, 'check_rewrite'));

        add_action('init', array($this, 'register_post_status'));

        if (get_site_option($this->enabled_option_name)) {
            add_filter('upload_dir', array($this, 'change_upload_directory'), 999);

            add_filter('image_downsize', array($this, 'image_downsize_placeholder'), 999, 3);

            add_action('template_redirect', array($this, 'template_redirect'), 0);

            // WP-REST-API
            add_filter("rest_page_query", array($this, 'rest_filter'));
            add_filter("rest_attachment_query", array($this, 'rest_filter'));
            // Pending development
            add_filter('rest_post_dispatch', function ($result, $server, $request) {
                return $result;
            }, 10, 3);


            // Bezieht sich nur auf den Backend-Bereich
            if (is_admin()) {
                add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

                add_action('post_submitbox_misc_actions', array($this, 'post_protection_submitbox'));

                add_action('save_post', array($this, 'save_post_data'));

                add_action("manage_edit-page_columns", array($this, 'manage_pages_column'));
                add_filter("manage_page_posts_custom_column", array($this, 'manage_pages_custom_column'), 10, 2);

                add_action('add_meta_boxes', array($this, 'attachment_edit_meta_box'));

                add_filter('attachment_fields_to_edit', array($this, 'attachment_fields_to_edit'), 10, 2);
                add_filter('attachment_fields_to_save', array($this, 'save_attachment_edit_fields'), 10, 2);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                add_action('load-media-new.php', array($this, 'load_media_new'));
                add_action('load-upload.php', array($this, 'load_upload'));

                add_filter('plugin_action_links_' . plugin()->getBaseName(), function ($links) {
                    $settings_link = '<a href="' . $this->action_url(array('page' => 'rrze-ac-settings')) . '">' . esc_html(__("Settings", 'rrze-ac')) . '</a>';
                    array_unshift($links, $settings_link);
                    return $links;
                });

                add_action('admin_notices', array($this->settings, 'admin_notices'));

                add_filter('rrze_menu_walker_nav_menu_edit', array($this, 'walker_nav_menu_edit'), 10, 5);

                add_action('views_edit-page', array($this, 'views_edit'));
                add_filter('pre_get_posts', array($this, 'pre_get_posts_list'));

                // Bezieht sich nur auf den Frontend-Bereich
            } else {
                // Menüelemente die geschützte Objekte verlinken sind abgeschlossen
                //add_filter('wp_nav_menu_objects', array($this, 'nav_menu_objects'), 10, 1);

                // Anpassung des Abfrageobjekts
                add_filter('pre_get_posts', array($this, 'pre_get_posts_single'));
            }
        } else {
            add_action('admin_notices', array($this, 'admin_error_notice'));
            add_action('network_admin_notices', array($this, 'admin_error_notice'));
        }
    }

    public function check_rewrite()
    {
        if (is_admin() && !get_site_option($this->enabled_option_name)) {
            global $pagenow;
            if ($this->check_rewrite_rules()) {
                add_site_option($this->enabled_option_name, 1);
                wp_redirect(admin_url($pagenow ? $pagenow : ''));
                exit();
            }
        }
    }

    public function admin_error_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = __("The RRZE Access Control Plugin is not configured properly. The files and documents can not be protected.", 'rrze-ac');
        $message .= ' ';
        if (is_network_admin() || is_super_admin()) {
            $message .= __("The following rewrite commands must be added in the .htaccess file after the WordPress command &#8222;RewriteRule ^index\\.php$ - [L]&#8220;.", 'rrze-ac');
            $message .= '<p>' . implode('<br>', $this->rewrite_rules()) . '</p>';
        } else {
            $message .= __("Please contact your system administrator.", 'rrze-ac');
        } ?>
        <div class="error">
            <p><?php echo $message; ?></p>
        </div>
    <?php
    }

    protected function check_rewrite_rules()
    {
        $upload_dir = wp_upload_dir();

        $protected_test = $this->protected_upload_dir('/access_rewrite_test.txt?access_rewrite_test=1', true);

        $check_url = $upload_dir['baseurl'] . $protected_test;
        $check = wp_remote_get($check_url, array('sslverify' => false, 'httpversion' => '1.1'));
        if (is_wp_error($check) || !isset($check['response']['code']) || 200 != $check['response']['code'] || !isset($check['body']) || 'rewrite test passed' != $check['body']) {
            return false;
        }

        return true;
    }

    protected function rewrite_rules()
    {
        $uploads_path = '';

        if (!get_site_option('ms_files_rewriting')) {
            $uploads_path .= 'wp-content(?:/uploads)?(?:/sites/[0-9]+)?';
        } else {
            $uploads_path .= '(?:wp-content/uploads)?(?:files)?';
        }

        if (!is_subdomain_install()) {
            $uploads_path = '(?:[_0-9a-zA-Z-]+/)?' . $uploads_path;
        }

        $protected_path = $uploads_path . '(' . $this->protected_upload_dir('/.*\.\w+)$', true);

        $rewrite_rules = array(
            '# Beginn Access Rewrite Rules',
            'RewriteRule ^' . $protected_path . ' index.php?protected_file=$1 [QSA,L]',
            '# End Access Rewrite Rules'
        );

        return $rewrite_rules;
    }

    public function enqueue_scripts()
    {
        wp_register_style(
            'rrze-ac-access',
            plugins_url('build/access.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_style(
            'rrze-ac-attachment',
            plugins_url('build/attachment.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_style(
            'rrze-ac-media',
            plugins_url('build/media.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_script(
            'rrze-ac-upload',
            plugins_url('build/upload.js', plugin()->getBasename()),
            ['jquery', 'media-editor'],
            plugin()->getVersion(),
            true
        );

        wp_register_script(
            'rrze-ac-page',
            plugins_url('build/page.js', plugin()->getBasename()),
            ['jquery', 'jquery-ui-slider'],
            plugin()->getVersion(),
            true
        );

        wp_register_script(
            'rrze-ac-media',
            plugins_url('build/media.js', plugin()->getBasename()),
            ['jquery', 'jquery-ui-slider'],
            plugin()->getVersion(),
            true
        );

        wp_enqueue_style('rrze-ac-access');

        $screen = get_current_screen();
        if (isset($screen->id) && 'page' == $screen->id) {
            wp_enqueue_script('rrze-ac-page');
        } elseif (isset($screen->id) && 'attachment' == $screen->id) {
            wp_enqueue_style('rrze-ac-attachment');
        } elseif (isset($screen->base) && 'upload' == $screen->base) {
            wp_enqueue_script('rrze-ac-upload');
        } elseif (isset($screen->base) && 'media' == $screen->base) {
            wp_enqueue_style('rrze-ac-media');
            wp_enqueue_script('rrze-ac-media');
        }
    }

    public function load_media_new()
    {
        add_action('post-upload-ui', array($this, 'media_new_upload_ui'));
        add_action('pre-plupload-upload-ui', array($this, 'media_new_upload_ui_notice'));
    }

    public function load_upload()
    {
        add_filter('media_row_actions', array($this, 'media_row_actions'), 10, 2);
        add_filter('manage_upload_columns', array($this, 'manage_upload_columns'));
        add_action('manage_media_custom_column', array($this, 'manage_media_custom_column'), 10, 2);
        add_action('admin_head-upload.php', array($this, 'media_custom_column_styles'));
        add_action('admin_footer-upload.php', array($this, 'media_bulk_actions_js'));
        add_action('admin_notices', array($this, 'media_admin_notices'));

        $this->bulk_actions();
    }

    public function get_permission($permission_key)
    {
        if (empty($permission_key)) {
            return array();
        }

        $permission = array();
        foreach ($this->options['permissions'] as $key => $value) {
            if ($key == $permission_key) {
                $permission =  array(
                    'permission_key' => $permission_key,
                    'description' => $value['description'],
                    'select' => $value['select'],
                    'logged_in' => $value['logged_in'],
                    'sso_logged_in' => $value['sso_logged_in'],
                    'affiliation' => $value['affiliation'],
                    'entitlement' => $value['entitlement'],
                    'domain' => $value['domain'],
                    'ip_address' => $value['ip_address'],
                    'password' => $value['password'],
                    'siteimprove' => $value['siteimprove'],
                    'core' => $value['core'],
                    'active' => $value['active']
                );
            }
        }

        return $permission;
    }

    public function get_permission_metas($post_type = '')
    {
        global $wpdb;

        $pt_query = [
            'page' => "p.post_type = 'page'",
            'attachment' => "p.post_type = 'attachment'"
        ];

        switch ($post_type) {
            case 'page':
                unset($pt_query['attachment']);
                break;
            case 'attachment':
                unset($pt_query['page']);
                break;
            default:
                break;
        }

        $query = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '%s'
            AND p.post_status = 'publish'
            AND (" . implode(' OR ', $pt_query) . ")";

        return $wpdb->get_results($wpdb->prepare($query, $this->access_permission_meta_key));
    }

    protected function meta_values()
    {
        global $wpdb;

        $metas = array();

        $result = $wpdb->get_results("
            SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '" . $this->access_permission_meta_key . "'
            AND ((p.post_type = 'attachment' AND p.post_status = 'inherit') OR (p.post_type = 'page' AND p.post_status = 'publish'))");

        foreach ($result as $r) {
            $metas[$r->post_id] = $r->meta_value;
        }

        return $metas;
    }

    public function count_meta_keys($permission_key)
    {
        $metas = $this->meta_values();
        return array_keys($metas, $permission_key, true);
    }

    public function action_url($atts = array())
    {
        $atts = array_merge(
            array(
                'page' => 'rrze-ac'
            ),
            $atts
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

        return add_query_arg($atts, get_admin_url(null, 'admin.php'));
    }

    public function nav_menu_objects($menu_items)
    {
        foreach ($menu_items as $key => $menu_item) {
            if ($menu_item->object == 'page' && !$this->check_permission($menu_item->object_id)) {
                unset($menu_items[$key]);
            }
        }

        return $menu_items;
    }

    public function change_upload_directory($param)
    {
        if (isset($_POST['access_protected']) && 'on' == $_POST['access_protected']) {
            $param['subdir'] = $this->protected_upload_dir($param['subdir'], true);
            $param['path'] = $param['basedir'] . $param['subdir'];
            $param['url'] = $param['baseurl'] . $param['subdir'];
        }

        return $param;
    }

    public function attachment_fields_to_edit($form_fields, $post)
    {
        if (!is_null(get_current_screen())) {
            return $form_fields;
        }

        $permission = get_post_meta($post->ID, $this->access_permission_meta_key, true);

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = $this->get_default_permission();
        }

        ob_start(); ?>
        <tr id="access-attachment-fields">
            <th class="label" scope="row">
                <label for="attachments-1054405-attachment_tag">
                    <span class="alignleft"><?php esc_html_e("Access Restriction", 'rrze-ac'); ?></span>
                    <br class="clear">
                </label>
            </th>
            <td class="field">
                <input type="hidden" name="attachments[<?php echo $post->ID ?>][access_protection_toggle]" value="off">
                <input class="radio access-protection-toggle" type="checkbox" id="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" name="attachments[<?php echo $post->ID; ?>][access_protection_toggle]" <?php checked($this->is_attachment_protected($post->ID)); ?>>
                <p id="access-attachment-permissions-field">
                    <label for="attachments[<?php echo $post->ID; ?>][access_permission_select]"><?php esc_html_e("Permission", 'rrze-ac'); ?></label>
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

    public function save_attachment_edit_fields($post, $attachment)
    {
        if (!isset($attachment['access_protection_toggle'])) {
            return $post;
        }

        $attachment_id = $post['ID'];

        switch ($attachment['access_protection_toggle']) {

            case 'off':
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return $post;
                }

                delete_post_meta($attachment_id, $this->access_permission_meta_key);

                return $post;

            case 'on':
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_to_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return $post;
                }

                if (empty($attachment['access_permission_select'])) {
                    return $post;
                }

                $permissions = $this->get_the_permissions();

                if (!isset($permissions[$attachment['access_permission_select']])) {
                    delete_post_meta($attachment_id, $this->access_permission_meta_key);
                } else {
                    update_post_meta($attachment_id, $this->access_permission_meta_key, $attachment['access_permission_select']);
                }

                return $post;

            default:
                return $post;
        }
    }

    public function get_default_permission()
    {
        $permissions = $this->get_the_permissions();
        $default_permission = isset($permissions[$this->options['default_permission']]) && $permissions[$this->options['default_permission']]['active'] ? $this->options['default_permission'] : 'logged-in';
        return $default_permission;
    }

    public function get_the_permissions()
    {
        $access_permissions = $this->options['permissions'];
        return apply_filters('access_edit_permissions', $access_permissions);
    }

    protected function get_the_permission($post_id)
    {
        if (get_post_type($post_id) == 'attachment') {
            return $this->get_attachment_permission($post_id);
        }

        $permission = get_post_meta($post_id, $this->access_permission_meta_key, true);

        return !empty($permission) ? $permission : false;
    }

    protected function check_author_permission($post_id)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $current_user = wp_get_current_user();

        $post = get_post($post_id);

        $post_author = $post->post_author;

        $authors = $this->post_authors($post_id, $post_author);

        if (isset($authors[$current_user->ID])) {
            return true;
        }

        return false;
    }

    protected function post_authors($post_id, $post_author)
    {
        $authors = array();

        // CMS-Workflow stuff
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if ($this->is_plugin_active('cms-workflow/cms-workflow.php')) {
            $authors = $this->workflow_authors($post_id);
        }

        $authors[$post_author] = $post_author;

        return $authors;
    }

    protected function workflow_authors($post_id)
    {
        global $wpdb;

        $authors = array();

        $workflow_authors = $wpdb->get_col(
            $wpdb->prepare(
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

        if ($workflow_authors) {
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

    protected function get_attachment_permission($attachment_id)
    {
        if (!$this->is_attachment_protected($attachment_id)) {
            return false;
        }

        $permission = get_post_meta($attachment_id, $this->access_permission_meta_key, true);

        return empty($permission) ? $this->get_default_permission() : $permission;
    }

    protected function is_attachment_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (!empty($file) && (0 === stripos($file, $this->protected_upload_dir('/')))) {
            return true;
        }

        return false;
    }

    protected function check_ip_address_range($ip_address = [])
    {
        $remote_addr = $this->getRemoteIpAddress();

        if (!$remote_addr) {
            do_action('rrze.log.warning', ['plugin' => 'rrze-ac', 'method' => __METHOD__, 'message' => 'Remote IP address is UNKNOWN.']);
            return false;
        }

        $ip = IP::fromStringIP($remote_addr);

        if ($ip->isInRanges($ip_address)) {
            return true;
        }

        do_action('rrze.log.notice', ['plugin' => 'rrze-ac', 'method' => __METHOD__, 'message' => sprintf('Remote IP address %s is not in range.', $remote_addr)]);
        return false;
    }

    protected function checkRemoteDomain($allowedDomains)
    {
        if (empty($allowedDomains) || !is_array($allowedDomains)) {
            return true;
        }

        $remote_addr = $this->getRemoteIpAddress();

        if (!$remote_addr) {
            do_action('rrze.log.warning', ['plugin' => 'rrze-ac', 'method' => __METHOD__, 'message' => 'Remote IP address is UNKNOWN.']);
            return false;
        }

        $ip = IP::fromStringIP($remote_addr);
        $hostname = $ip->getHostname();

        if ($hostname === null) {
            do_action('rrze.log.notice', ['plugin' => 'rrze-ac', 'method' => __METHOD__, 'message' => sprintf('Cannot get hostname from remote IP address %s.', $remote_addr)]);
            return false;
        }

        foreach ($allowedDomains as $domain) {
            if (strrpos($domain, $hostname) !== false) {
                return true;
            }
        }

        do_action('rrze.log.notice', ['plugin' => 'rrze-ac', 'method' => __METHOD__, 'message' => sprintf('Remote hostname %s is not allowed.', $hostname)]);
        return false;
    }

    protected function getRemoteIpAddress()
    {
        $remoteAddress = new RemoteAddress();
        return $remoteAddress->getIpAddress();
    }

    protected function checkPassword($post_id, $allowedPassword = '')
    {
        if ('publish' != get_post_status($post_id) || $allowedPassword === '') {
            return true;
        }
        $cookieName = 'rrze_ac_password_' . $post_id;
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'rrze_ac_submit_password_wpnonce')) {
            $password = isset($_POST[$cookieName]) ? sanitize_text_field($_POST[$cookieName]) : '';
            if (preg_match('/^[a-z0-9]{8,32}$/i', $password) && $password == $allowedPassword) {
                setcookie($cookieName, $this->crypt($password), strtotime('+1 hour'), COOKIEPATH, COOKIE_DOMAIN, true);
                $location = site_url(add_query_arg([], (string) wp_get_raw_referer()));
                wp_safe_redirect($location);
                exit;
            }
            return false;
        }

        if (isset($_COOKIE[$cookieName])) {
            $password = $this->crypt($_COOKIE[$cookieName], 'decrypt');
            if (preg_match('/^[a-z0-9]{8,32}$/i', $password) && $password == $allowedPassword) {
                return true;
            }
        }

        unset($_COOKIE[$cookieName]);
        return false;
    }

    protected function crypt($string, $action = 'encrypt')
    {
        $secretKey = AUTH_KEY;
        $secretSalt = AUTH_SALT;

        $output = false;
        $encryptMethod = 'AES-256-CBC';
        $key = hash('sha256', $secretKey);
        $salt = substr(hash('sha256', $secretSalt), 0, 16);

        if ($action == 'encrypt') {
            $output = base64_encode(openssl_encrypt($string, $encryptMethod, $key, 0, $salt));
        } else if ($action == 'decrypt') {
            $output = openssl_decrypt(base64_decode($string), $encryptMethod, $key, 0, $salt);
        }

        return $output;
    }

    public function simplesaml_auth()
    {
        if ($this->is_plugin_active($this->sso_plugin)) {
            if (is_multisite()) {
                $options = get_site_option($this->sso_option_name);
            } else {
                $options = get_option($this->sso_option_name);
            }
            // Backward compatibility
        } elseif ($this->is_plugin_active($this->websso_plugin)) {
            if (is_multisite()) {
                $options = get_site_option($this->websso_option_name);
            } else {
                $options = get_option($this->websso_option_name);
            }
        } else {
            return false;
        }

        if (!isset($options['simplesaml_include']) || !isset($options['simplesaml_auth_source'])) {
            return false;
        }

        if (!file_exists(WP_CONTENT_DIR . $options['simplesaml_include'])) {
            return false;
        }

        $this->simplesaml_auth = new SimpleSAML($options);
        $this->simplesaml_auth = $this->simplesaml_auth->loaded();
        if ($this->simplesaml_auth === false) {
            return false;
        }

        return true;
    }

    protected function check_sso_logged_in()
    {
        if ($this->simplesaml_auth() === false) {
            return false;
        } elseif (!$this->simplesaml_auth->isAuthenticated()) {
            \SimpleSAML\Session::getSessionFromRequest()->cleanup();
            if ($this->options['automatic_sso_authentication']) {
                $this->simplesaml_auth->requireAuth();
                \SimpleSAML\Session::getSessionFromRequest()->cleanup();
            }
            return false;
        }

        $this->person_attributes = $this->simplesaml_auth->getAttributes();

        if ($this->is_plugin_active($this->sso_plugin)) {
            $this->person_affiliation = $this->person_attributes['eduPersonAffiliation'] ?? [];
            $this->person_entitlement = $this->person_attributes['eduPersonEntitlement'] ?? [];
            // Backward compatibility
        } elseif ($this->is_plugin_active($this->websso_plugin)) {
            $this->person_affiliation = isset($this->person_attributes['urn:mace:dir:attribute-def:eduPersonAffiliation']) ? $this->person_attributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] : [];
            $this->person_entitlement = isset($this->person_attributes['urn:mace:dir:attribute-def:eduPersonEntitlement']) ? $this->person_attributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] : [];
        } else {
            return false;
        }

        return true;
    }

    protected function check_person_affiliation($affiliation)
    {
        if (empty($affiliation) || empty($affiliation[0]) || !is_array($affiliation)) {
            return true;
        }

        foreach ($affiliation as $attribute) {
            if (in_array($attribute, $this->person_affiliation)) {
                return true;
            }
        }

        return false;
    }

    protected function check_person_entitlement($entitlement)
    {
        if (empty($entitlement) || empty($entitlement[0]) || !is_array($entitlement)) {
            return true;
        }

        foreach ($entitlement as $attribute) {
            if (in_array($attribute, $this->person_entitlement)) {
                return true;
            }
        }

        return false;
    }

    protected function check_permission($post_id = 0)
    {
        if (empty($post_id)) {
            return false;
        }

        if (!$permission = $this->get_the_permission($post_id)) {
            return true;
        }

        if ($this->check_author_permission($post_id)) {
            return true;
        }

        $permissions = $this->get_the_permissions();

        // set permission to default permission if not exist or not active
        if (!isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = $this->get_default_permission();
        }

        do_action(
            'rrze.log.info',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'postID' => $post_id,
                'permission' => $permission
            ]
        );

        $allowed = false;

        if ($permission == 'all') {
            $allowed = true;
        }

        if ($permission == 'logged-in' && is_user_logged_in()) {
            $allowed = true;
        }

        // check if permission is set to domain
        if (!$allowed && !empty($permissions[$permission]['domain'])) {
            if (!$this->checkRemoteDomain($permissions[$permission]['domain'])) {
                $this->set_permission_status($this->user_domain_not_allowed);
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'postID' => $post_id,
                        'permission' => $permission,
                        'status' => 'user_domain_not_allowed'
                    ]
                );
            } else {
                $allowed = true;
            }
        }

        // check if permission is set to ip address
        if (!$allowed && !empty($permissions[$permission]['ip_address'])) {
            if (!$this->check_ip_address_range($permissions[$permission]['ip_address'])) {
                $this->set_permission_status($this->user_ip_isnt_in_range);
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'postID' => $post_id,
                        'permission' => $permission,
                        'status' => 'user_ip_isnt_in_range'
                    ]
                );
            } else {
                $allowed = true;
            }
        }

        // check if permission is set to password
        if (!$allowed && !empty($permissions[$permission]['password'])) {
            if (!$this->checkPassword($post_id, $permissions[$permission]['password'])) {
                $this->set_permission_status($this->wrong_password);
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'postID' => $post_id,
                        'permission' => $permission,
                        'status' => 'wrong_password'
                    ]
                );
            } else {
                $allowed = true;
            }
        }

        // check if permission is set to siteimprove (crawler)
        if (!$allowed && !empty($permissions[$permission]['siteimprove'])) {
            $ipAddresses = apply_filters('rrze_ac_siteimprove_crawler_ip_addresses', Siteimprove::getIpAddresses());
            if (!empty($ipAddresses)) {
                if (!$this->check_ip_address_range($ipAddresses)) {
                    $this->set_permission_status($this->user_ip_isnt_in_range);
                    do_action(
                        'rrze.log.notice',
                        [
                            'plugin' => 'rrze-ac',
                            'postID' => $post_id,
                            'permission' => $permission,
                            'status' => 'user_ip_isnt_in_range'
                        ]
                    );
                } else {
                    $allowed = true;
                }
            }
        }

        // check if permission is set to be logged in
        if (!$allowed && !empty($permissions[$permission]['logged_in'])) {
            if (!is_user_logged_in()) {
                $this->set_permission_status($this->user_isnt_logged_in);
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'postID' => $post_id,
                        'permission' => $permission,
                        'status' => 'user_isnt_logged_in'
                    ]
                );
            } else {
                $allowed = true;
            }
        }

        // check if permission is set to be sso logged in
        $sso_logged_in = false;
        if (!$allowed && !empty($permissions[$permission]['sso_logged_in'])) {
            if (!$this->check_sso_logged_in()) {
                $this->set_permission_status($this->user_isnt_sso_logged_in);
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'postID' => $post_id,
                        'permission' => $permission,
                        'status' => 'user_isnt_sso_logged_in'
                    ]
                );
            } else {
                $sso_logged_in = true;
                $allowed = true;
            }
        }

        // require person affiliation OR person entitlement
        if (
            $sso_logged_in
            && !is_null($this->person_attributes)
            && (!empty($permissions[$permission]['affiliation']) || !empty($permissions[$permission]['entitlement']))
        ) {
            $allowed_person_affiliation = false;
            $allowed_person_entitlement = false;

            // check if permission is set to person affiliation
            if (!empty($permissions[$permission]['affiliation'])) {
                if (!$this->check_person_affiliation($permissions[$permission]['affiliation'])) {
                    $this->set_permission_status($this->user_hasnt_affiliation);
                    do_action(
                        'rrze.log.notice',
                        [
                            'plugin' => 'rrze-ac',
                            'postID' => $post_id,
                            'permission' => $permission,
                            'status' => 'user_hasnt_affiliation',
                            'allowed_person_affiliation' => $permissions[$permission]['affiliation'],
                            'person_atributes' => $this->person_attributes
                        ]
                    );
                } else {
                    $allowed_person_affiliation = true;
                }
            }

            // check if permission is set to person entitlement
            if (!empty($permissions[$permission]['entitlement'])) {
                if (!$this->check_person_entitlement($permissions[$permission]['entitlement'])) {
                    $this->set_permission_status($this->user_hasnt_entitlement);
                    do_action(
                        'rrze.log.notice',
                        [
                            'plugin' => 'rrze-ac',
                            'postID' => $post_id,
                            'permission' => $permission,
                            'status' => 'user_hasnt_entitlement',
                            'allowed_person_entitlement' => $permissions[$permission]['entitlement'],
                            'person_atributes' => $this->person_attributes
                        ]
                    );
                } else {
                    $allowed_person_entitlement = true;
                }
            }

            if (!$allowed_person_affiliation && !$allowed_person_entitlement) {
                $allowed = false;
            }
        }

        do_action(
            'rrze.log.info',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'postID' => $post_id,
                'permission' => $permission,
                'status' => $allowed ? 'allowed' : 'not allowed'
            ]
        );

        return $allowed;
    }

    public function attachment_edit_meta_box()
    {
        add_meta_box(
            'attachment-protection-metabox',
            __("Access Restriction", 'rrze-ac'),
            [$this, 'post_protection_metabox'],
            'attachment',
            'side'
        );
    }

    public function post_protection_metabox($post)
    {
        wp_nonce_field('attachment_protection_metabox', 'attachment_protection_metabox_nonce');

        $permission = get_post_meta($post->ID, $this->access_permission_meta_key, true);

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission]) || !$permissions[$permission]['active']) {
            $permission = $this->get_default_permission();
        } ?>
        <input type="hidden" name="access_protection_toggle" value="off">
        <input type="checkbox" id="access-protection-toggle" name="access_protection_toggle" <?php checked($this->is_attachment_protected($post->ID)); ?>>
        <label class="access-protection-toggle" for="access-protection-toggle">
            <span aria-role="hidden" class="access-on button button-primary" data-access-content="<?php esc_attr_e("Enable permission", 'rrze-ac'); ?>"></span>
            <span aria-role="hidden" class="access-off" data-access-content="<?php esc_attr_e("Remove permission", 'rrze-ac'); ?>"></span>
        </label>
        <div class="access-permission-select">
            <label for="access-permission-select">
                <span class="description"><?php esc_html_e("Permission:", 'rrze-ac'); ?></span>
            </label>
            <select id="access-permission-select" name="access_permission_select">
                <?php foreach ($permissions as $key => $data) : ?>
                    <?php if (!$data['active']) {
                        continue;
                    } ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                        <?php echo sanitize_text_field($data['select']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php
    }

    public function post_protection_submitbox()
    {
        global $post;

        if (get_post_type($post->ID) != 'page') {
            return;
        }

        wp_nonce_field('post_protection_submitbox', 'post_protection_submitbox_nonce');


        $permission = get_post_meta($post->ID, $this->access_permission_meta_key, true);

        $permissions = $this->get_the_permissions();

        if (empty($permission) || !isset($permissions[$permission])) {
            $permission = 'all';
        }

        $label = $permissions[$permission]['select'];
        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon'; ?>
        <div id="post-protection-wrap" class="misc-pub-section">
            <span>
                <span id="access-icon" class="<?php echo $class; ?> dashicons dashicons-shield"></span>
                <?php _e("Permission:", 'rrze-ac'); ?>
                <b id="post-protection-label"><?php echo $label; ?></b>
            </span>
            <a href="#" id="edit-post-protection" class="edit-post-protection hide-if-no-js">
                <span aria-hidden="true"><?php _e("Edit", 'rrze-ac'); ?></span>
                <span class="screen-reader-text"><?php _e("Edit permission", 'rrze-ac'); ?></span>
            </a>
            <div id="post-protection-field" class="hide-if-js">
                <select id="access-permission-select" name="access_permission_select">
                    <?php foreach ($permissions as $key => $data) : ?>
                        <?php if (!$data['active']) {
                            continue;
                        } ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($permission, $key); ?>>
                            <?php echo sanitize_text_field($data['select']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="#" class="save-post-protection hide-if-no-js button"><?php _e('OK', 'rrze-ac'); ?></a>
                <a href="#" class="cancel-post-protection hide-if-no-js button-cancel"><?php _e("Cancel", 'rrze-ac'); ?></a>
            </div>
        </div>
    <?php
    }

    public function save_post_data($post_id)
    {
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
            delete_post_meta($post_id, $this->access_permission_meta_key);
        } elseif (isset($permissions[$_POST['access_permission_select']])) {
            update_post_meta($post_id, $this->access_permission_meta_key, $_POST['access_permission_select']);
        }
    }

    public function save_attachment_data($attachment_id)
    {
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

            case 'off':
                remove_action('edit_attachment', array($this, 'save_attachment_data'));

                $move_attachment = $this->move_attachment_from_protected($attachment_id);

                add_action('edit_attachment', array($this, 'save_attachment_data'));

                if (is_wp_error($move_attachment)) {
                    return;
                }

                delete_post_meta($attachment_id, $this->access_permission_meta_key);

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
                    delete_post_meta($attachment_id, $this->access_permission_meta_key);
                } else {
                    update_post_meta($attachment_id, $this->access_permission_meta_key, $_POST['access_permission_select']);
                }

                break;

            default:
                return;
        }
    }

    protected function move_attachment_from_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (0 !== stripos($file, $this->protected_upload_dir('/'))) {
            return true;
        }

        $new_reldir = ltrim(dirname($file), $this->protected_upload_dir('/'));

        return $this->move_attachment_files($attachment_id, $new_reldir);
    }

    protected function move_attachment_to_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (0 === stripos($file, $this->protected_upload_dir('/'))) {
            return true;
        }

        $reldir = dirname($file);
        if (in_array($reldir, array('\\', '/', '.'), true)) {
            $reldir = '';
        }

        $new_reldir = path_join($this->protected_upload_dir(), $reldir);

        return $this->move_attachment_files($attachment_id, $new_reldir);
    }

    protected function move_attachment_files($attachment_id, $new_reldir)
    {
        if ('attachment' != get_post_type($attachment_id)) {
            return new WP_Error('not_attachment', sprintf(
                __("The post %d is not a Media Post-Type.", 'rrze-ac'),
                $attachment_id
            ));
        }

        if (path_is_absolute($new_reldir)) {
            return new WP_Error('new_reldir_not_relative', sprintf(
                __("The newly specified path %s is absolute. The new path must be a path relative to the WP uploads directory.", 'rrze-ac'),
                $new_reldir
            ));
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        $backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        $upload_dir = wp_upload_dir();

        $old_reldir = dirname($file);
        if (in_array($old_reldir, array('\\', '/', '.'), true)) {
            $old_reldir = '';
        }

        if ($new_reldir === $old_reldir) {
            return null;
        }

        $old_fulldir = path_join($upload_dir['basedir'], $old_reldir);
        $new_fulldir = path_join($upload_dir['basedir'], $new_reldir);

        if (!wp_mkdir_p($new_fulldir)) {
            return new WP_Error('wp_mkdir_p_error', sprintf(
                __("An error has occurred while creating the directory %s.", 'rrze-ac'),
                $new_fulldir
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
        $conflict = true;
        $number = 1;
        $separator = '#';
        $med_filename = $orig_filename;

        while ($conflict) {
            $conflict = false;
            foreach ($new_basenames as $basename) {
                if (is_file(path_join($new_fulldir, $basename))) {
                    $conflict = true;
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
                    __("The file can not be moved from %s to %s.", 'rrze-ac'),
                    $old_fullpath,
                    $new_fullpath
                ));
            }
        }

        $file = path_join($new_reldir, $new_basenames[0]);
        if (wp_attachment_is_image($attachment_id)) {
            $meta['file'] = $file;
        }

        update_post_meta($attachment_id, '_wp_attached_file', $file);

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

        $path = explode('/wp-content/', path_join($new_fulldir, $orig_basename));

        $permalink = site_url('/wp-content/' . $path[1]);

        global $wpdb;
        $wpdb->update($wpdb->posts, array('guid' => $permalink), array('ID' => $attachment_id), array('%s'), array('%d'));

        return true;
    }

    public function request_file()
    {
        if (isset($_GET['protected_file']) && !empty($_GET['protected_file'])) {
            if (isset($_GET['access_rewrite_test']) && $_GET['access_rewrite_test']) {
                die('rewrite test passed');
            }

            $this->get_file($_GET['protected_file']);
            exit();
        }
    }

    protected function get_file($rel_file)
    {
        $rel_file = isset($rel_file) ? $rel_file : '';
        $upload_dir = wp_upload_dir();

        if (empty($upload_dir['basedir'])) {
            wp_die(
                __('The requested file was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);

        if (!is_file($file)) {
            $rel_file = str_replace('_protected', '', rtrim($rel_file, '/'));
            $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);
            if (!is_file($file)) {
                wp_die(
                    __('The requested file was not found.', 'rrze-ac'),
                    __('Not Found', 'rrze-ac'),
                    [
                        'response' => '404',
                        'back_link' => false
                    ]
                );
            }
        }

        $mime = wp_check_filetype($file);

        if (isset($mime['type']) && $mime['type']) {
            $mimetype = $mime['type'];
        } else {
            wp_die(
                __('The request was due lack of client permission not performed.', 'rrze-ac'),
                __('Forbidden', 'rrze-ac'),
                [
                    'response' => '403',
                    'back_link' => false
                ]
            );
        }

        $file_info = pathinfo($rel_file);

        if (0 !== stripos($file_info['dirname'] . '/', $this->protected_upload_dir('/', true))) {
            wp_die(
                __('The requested file was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

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
        $attachment_dirname = trim($file_info['dirname'], '/\\');
        $attachment_file = $attachment_dirname . '/' . $file_info['basename'];

        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
                '_wp_attached_file',
                $attachment_file
            )
        );

        if (is_null($attachment)) {
            $attachment = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT post_id "
                        . "FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s "
                        . "AND post_id IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s) ",
                    '_wp_attachment_metadata',
                    '%' . $file_info['basename'] . '%',
                    '_wp_attached_file',
                    '%' . $attachment_dirname . '%'
                )
            );
        }

        if (is_null($attachment)) {
            wp_die(
                __('The requested attachment was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        $attachment_id = $attachment->post_id;

        if (!$this->check_permission($attachment_id)) {
            wp_die(
                $this->permission_message($attachment_id),
                __('Login is required', 'rrze-ac'),
                [
                    'response' => '403',
                    'back_link' => false
                ]
            );
        }

        header('Content-Type: ' . $mimetype);
        header('Content-Length: ' . filesize($file));

        $last_modified = gmdate('D, d M Y H:i:s', filemtime($file));
        $etag = '"' . md5($last_modified) . '"';
        header("Last-Modified: $last_modified GMT");
        header('ETag: ' . $etag);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');

        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : false;

        if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $_SERVER['HTTP_IF_MODIFIED_SINCE'] = false;
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

    protected function protected_upload_dir($path = '', $in_url = false)
    {
        $dirpath = $in_url ? '/' : '';
        $dirpath .= $this->protected_dirname;
        $dirpath .= $path;

        return $dirpath;
    }

    public function image_downsize_placeholder($img, $attachment_id, $size)
    {
        $upload_dir = wp_upload_dir();

        if (isset($img[0]) && 0 !== strpos(ltrim($img[0], $upload_dir['baseurl']), $this->protected_upload_dir('/', true))) {
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
                plugin()->getUrl('images') . 'media-placeholder.jpg',
                $width,
                $height,
                false
            );
        }
    }

    public function media_row_actions($actions, $post)
    {
        if (!$this->check_permission($post->ID)) {
            return array(esc_html__('You do not have sufficient permissions to access the file.', 'rrze-ac'));
        }

        return $actions;
    }

    public function manage_pages_column($columns)
    {
        $columns['access_info'] = '<span title="' . esc_attr__('Access Restriction', 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
        return $columns;
    }

    public function manage_pages_custom_column($column_name, $post_id)
    {
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
            $error = __("Permission does not exist or has been removed.", 'rrze-ac');
        }

        if (!$permissions[$permission]['active']) {
            $error = __("Permission has been disabled.", 'rrze-ac');
            $permission = $this->get_default_permission();
        }

        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];

        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(
                /* translators: 1: Error message, 2: Default permission. */
                esc_attr__('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'),
                $error,
                $description
            ) . '" class="access-error-icon dashicons dashicons-shield"></span>';

        echo $description;
    }

    public function manage_upload_columns($columns)
    {
        $columns['access_info'] = '<span title="' . esc_attr__("Access Restriction", 'rrze-ac') . '" class="dashicons dashicons-shield"></span>';
        return $columns;
    }

    public function manage_media_custom_column($column_name, $post_id)
    {
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
            $error = __("Permission does not exist or has been removed.", 'rrze-ac');
        }

        if (!$permissions[$permission]['active']) {
            $error = __("The permission has been disabled.", 'rrze-ac');
            $permission = $this->get_default_permission();
        }

        $class = $permission == 'all' ? 'access-all-icon' : 'access-icon';
        $permission = $permissions[$permission];

        $description = isset($permission['description']) && !empty($permission['description']) ? $permission['description'] : $permission['permission_key'];
        $description = !$error ?
            '<span title="' . esc_attr__($description) . '" class="' . $class . ' dashicons dashicons-shield"></span>' :
            '<span title="' . sprintf(esc_attr__('An error has occurred: %1$s and has been replaced by the default permission %2$s.', 'rrze-ac'), $error, $description) . '" class="access-error-icon dashicons dashicons-shield"></span>';

        echo $description;
    }

    public function media_custom_column_styles()
    {
    ?>

        <style type="text/css">
            .column-access_info {
                width: 120px;
            }
        </style>

    <?php
    }

    public function media_bulk_actions_js()
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $bulk_actions = array();
        if (!isset($_GET['access-show-protected'])) {
            $bulk_actions['access-protect'] = esc_html__("Enable permission", 'rrze-ac');
        }
        if (!isset($_GET['access-show-unprotected'])) {
            $bulk_actions['access-unprotect'] = esc_html__("Remove permission", 'rrze-ac');
        } ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $.each(<?php echo json_encode($bulk_actions); ?>, function(index, value) {
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

    public function media_admin_notices()
    {
        $screen = get_current_screen();
        if ('upload' === $screen->id) {
            if (isset($_REQUEST['access-protected']) && (int) $_REQUEST['access-protected']) {
                $message = sprintf(
                    _n(
                        "Media file is now protected.", //singular
                        "%s media files are now protected.", //plural
                        $_REQUEST['access-protected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-protected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-protected', $_SERVER['REQUEST_URI']);
            }

            if (isset($_REQUEST['access-unprotected']) && (int) $_REQUEST['access-unprotected']) {
                $message = sprintf(
                    _n(
                        "Data protection on Media file has been removed.", //singular
                        "Data protection on %s Media files has been removed.", //plural
                        $_REQUEST['access-unprotected'],
                        'rrze-ac'
                    ),
                    number_format_i18n($_REQUEST['access-unprotected'])
                );
                echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
                $_SERVER['REQUEST_URI'] = remove_query_arg('access-unprotected', $_SERVER['REQUEST_URI']);
            }
        }
    }

    public function bulk_actions()
    {
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
            if (false !== strpos($referer, 'upload.php')) {
                $location = remove_query_arg(
                    array('access-protected', 'access-unprotected', 'trashed', 'untrashed', 'deleted', 'message', 'ids', 'posted'),
                    $referer
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
                    wp_die(
                        __('You are not allowed to add media files to the protected directory.', 'rrze-ac'),
                        __('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
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
                        wp_die(
                            __('An error has occurred while moving the media files in the protected directory.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message(),
                            __('Internal Server Error', 'rrze-ac'),
                            [
                                'response' => '500',
                                'back_link' => true
                            ]
                        );
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
                    wp_die(
                        __('You are not allowed to remove media files from the protected directory.', 'rrze-ac'),
                        __('Forbidden', 'rrze-ac'),
                        [
                            'response' => '403',
                            'back_link' => true
                        ]
                    );
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
                        wp_die(
                            __('An error has occurred while removing the media files from the protected directory.', 'rrze-ac') . '<br/>' . $move_attachment->get_error_message(),
                            __('Internal Server Error', 'rrze-ac'),
                            [
                                'response' => '500',
                                'back_link' => true
                            ]
                        );
                    }

                    delete_post_meta($media_id, $this->access_permission_meta_key);

                    $unprotected++;
                }

                $location = add_query_arg(array(
                    'access-unprotected' => $unprotected,
                    'ids' => join(',', $media_ids)
                ), $location);
                break;

            default:
                return;
        }

        $location = remove_query_arg(array('action', 'action2', 'media'), $location);

        wp_redirect($location);
        exit();
    }

    public function media_new_upload_ui()
    {
        $screen = get_current_screen();
        if ('media' == $screen->base && 'add' == $screen->action) :
        ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="access_protected">
                                <?php esc_html_e("Access Restriction", 'rrze-ac'); ?>
                            </label>
                        </th>
                        <td>
                            <label for="access_protected">
                                <?php $options = get_option('access_options'); ?>
                                <input type="checkbox" id="access_protected" name="access_protected">
                                <span class="description">
                                    <?php esc_html_e("Activate", 'rrze-ac'); ?>
                                </span>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php
        endif;
    }

    public function media_new_upload_ui_notice()
    {
        $screen = get_current_screen();
        if (isset($screen->base) && 'media' == $screen->base && 'add' == $screen->action) :
        ?>
            <div class="access-tag">
                <span aria-role="hidden" class="dashicons dashicons-shield"></span>
                <?php _e("New files are protected", 'rrze-ac'); ?>
            </div>
<?php
        endif;
    }

    public function template_redirect()
    {
        if (is_page() || is_attachment()) {
            global $post;
            if (!$this->check_permission($post->ID)) {
                wp_die(
                    $this->permission_message($post->ID),
                    __('Login is required', 'rrze-ac'),
                    [
                        'response' => '403',
                        'back_link' => false
                    ]
                );
            }
        }
    }

    public function rest_filter($args)
    {
        $post_not_in = array();
        $permissions = $this->get_the_permissions();
        $permission_metas = $this->get_permission_metas($args['post_type']);

        foreach ($permission_metas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !$this->check_author_permission($pm->post_id)) {
                $post_not_in[] = $pm->post_id;
            }
        }

        if (!empty($post_not_in)) {
            $args['post__not_in'] = $post_not_in;
        }

        return $args;
    }

    public function pre_get_posts_single($query)
    {
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

    protected function permission_message($post_id)
    {
        $message = '';

        $post_type = get_post_type($post_id);

        if ($post_type == 'attachment' && !wp_attachment_is_image($post_id)) {
            $permalink = wp_get_attachment_url($post_id);
        } else {
            $permalink = get_permalink($post_id);
        }

        if ($this->get_permission_status($this->user_isnt_logged_in)) {
            $login_url = wp_login_url($permalink);
            $message .= '<h3>' . esc_html($this->options['user_isnt_logged_in_title']) . '</h3>';
            $message .= '<p>' . esc_html($this->options['user_isnt_logged_in_msg']) . '</p>';
            $message .= '<p><a href="' . $login_url . '">' . esc_html($this->options['user_isnt_logged_in_link_txt']) . '</a></p>';
            return $message;
        }

        if ($this->get_permission_status($this->user_isnt_sso_logged_in) && $this->simplesaml_auth) {
            $login_url = $this->simplesaml_auth->getLoginURL();
            $message .= '<h3>' . esc_html($this->options['user_isnt_sso_logged_in_title']) . '</h3>';
            $message .= '<p>' . esc_html($this->options['user_isnt_sso_logged_in_msg']) . '</p>';
            $message .= '<p><a href="' . $login_url . '">' . esc_html($this->options['user_isnt_sso_logged_in_link_txt']) . '</a></p>';
            return $message;
        }

        $message .= '<h3>' . esc_html($this->options['access_denied_default_title']) . '</h3>';

        if ($this->get_permission_status($this->wrong_password)) {
            $message .= '<p>' . esc_html($this->options['access_denied_password_msg']) . '</p>' . PHP_EOL;

            $fieldName = 'rrze_ac_password_' . $post_id;
            $message .= '<form method="post">' . PHP_EOL;
            $message .= wp_nonce_field('rrze_ac_submit_password_wpnonce', '_wpnonce', true, false) . PHP_EOL;
            $message .= '<input type="password" name="' . $fieldName . '" value="" style="padding: 0 8px; min-height: 23px;">' . PHP_EOL;
            $message .= '<input type="submit" name="rrze_ac_submit_password" id="submit" class="button button-primary" value="' . __('Send password', 'rrze-ac') . '"></p>' . PHP_EOL;
            $message .= '</form>' . PHP_EOL;
        }

        $message .= '<p>' . esc_html($this->options['access_denied_default_msg']) . '</p>';
        $message .= $this->get_contact();

        return $message;
    }

    protected function get_contact()
    {
        global $wpdb;

        $blog_prefix = $wpdb->get_blog_prefix(get_current_blog_id());
        $users = $wpdb->get_results(
            "SELECT user_id, user_id AS ID, user_login, display_name, user_email, meta_value
             FROM $wpdb->users, $wpdb->usermeta
             WHERE {$wpdb->users}.ID = {$wpdb->usermeta}.user_id AND meta_key = '{$blog_prefix}capabilities'
             ORDER BY {$wpdb->usermeta}.user_id"
        );

        if (empty($users)) {
            return '';
        }

        $output = '<h4>' . __("Contact persons", 'rrze-ac') . '</h4>';

        foreach ($users as $user) {
            $roles = unserialize($user->meta_value);
            if (isset($roles['administrator'])) {
                $output .= sprintf('<p>%1$s<br/>%2$s %3$s</p>' . "\n", $user->display_name, __("Email Address:", 'rrze-ac'), make_clickable($user->user_email));
            }
        }

        return $output;
    }

    protected function get_permission_status($bitmask)
    {
        return ($this->permission_status & (1 << $bitmask)) != 0;
    }

    protected function set_permission_status($bitmask, $newBit = true)
    {
        $this->permission_status = ($this->permission_status & ~(1 << $bitmask)) | ($newBit << $bitmask);
    }

    protected function is_plugin_active($plugin)
    {
        return in_array($plugin, (array) get_option('active_plugins', array())) || $this->is_plugin_active_for_network($plugin);
    }

    protected function is_plugin_active_for_network($plugin)
    {
        if (!is_multisite()) {
            return false;
        }

        $plugins = get_site_option('active_sitewide_plugins');
        if (isset($plugins[$plugin])) {
            return true;
        }

        return false;
    }

    public function walker_nav_menu_edit($output, $item, $depth, $args, $id)
    {
        $permission = get_post_meta($item->object_id, $this->access_permission_meta_key, true);
        $permissions = $this->get_the_permissions();
        $pos = strpos($output, '<span class="menu-item-title">');

        if (!empty($permission) && isset($permissions[$permission]) && $pos !== false) {
            $substr = array(
                substr($output, 0, $pos),
                '<span class="access-icon dashicons dashicons-shield"></span>',
                PHP_EOL,
                substr($output, $pos),
            );

            $output = implode('', $substr);
        }

        return $output;
    }

    public function register_post_status()
    {
        register_post_status('protected', [
            'label'                     => __('Protected', 'rrze-ac'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
            'label_count'               => _n_noop('Protected <span class="count">(%s)</span>', 'Protected <span class="count">(%s)</span>', 'rrze-ac'),
        ]);
    }

    public function views_edit($views)
    {
        global $wp_query, $post_type;

        if (!in_array($post_type, ['page'])) {
            return $views;
        }

        $query = new \WP_Query(
            [
                'post_type'  => $post_type,
                'meta_query' => [
                    [
                        'key' => $this->access_permission_meta_key,
                        'compare' => 'EXISTS'
                    ]
                ]
            ]
        );

        $count = $query->found_posts;
        $class = isset($wp_query->query['post_status']) && $wp_query->query['post_status'] == 'protected' ? ' class="current"' : '';

        $views['protected'] = sprintf(
            '<a href="%s"%s>%s</a>',
            admin_url(sprintf('edit.php?post_status=protected&post_type=%s', $post_type)),
            $class,
            sprintf(translate_nooped_plural(_n_noop('Protected <span class="count">(%s)</span>', 'Protected <span class="count">(%s)</span>'), $count, 'rrze-ac'), $count)
        );

        return $views;
    }

    public function pre_get_posts_list($query)
    {
        global $post_type;

        if (!is_admin() || !isset($query->query_vars['post_status']) || $query->query_vars['post_status'] != 'protected') {
            return $query;
        }

        if (!in_array($post_type, ['page'])) {
            return $query;
        }

        $query->set('post_status', ['publish', 'pending', 'draft', 'future', 'private', 'inherit', 'protected']);

        $meta_query = [
            [
                'key' => $this->access_permission_meta_key,
                'compare' => 'EXISTS'
            ]
        ];

        $query->set('meta_query', $meta_query);

        return $query;
    }
}
