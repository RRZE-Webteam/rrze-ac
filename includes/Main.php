<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;

class Main
{
    public $options;
    public $option_name;
    public $enabled_option_name;

    public $settings;
    public $page_slug;
    public $settings_prefix;

    public function __construct()
    {
        $this->options = Options::getOptions();
        $this->option_name = Options::getOptionName();
        $this->enabled_option_name = Options::getEnabledOptionName();

        $this->settings = new Settings($this);

        add_action('init', array($this, 'request_file'), 0);

        add_action('init', array($this, 'check_rewrite'));

        add_action('init', array($this, 'register_post_status'));

        if (!get_site_option($this->enabled_option_name)) {
            add_action('admin_notices', array($this, 'admin_error_notice'));
            add_action('network_admin_notices', array($this, 'admin_error_notice'));
            return;
        }

        Post::init();

        Attachment::init();

        Files::init();

        add_filter('plugin_action_links_' . plugin()->getBaseName(), function ($links) {
            $settings_link = '<a href="' . $this->action_url(array('page' => 'rrze-ac-settings')) . '">' . esc_html(__("Settings", 'rrze-ac')) . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('admin_notices', array($this->settings, 'admin_notices'));

        add_filter('rrze_menu_walker_nav_menu_edit', array($this, 'walker_nav_menu_edit'), 10, 5);

        // Menüelemente die geschützte Objekte verlinken sind abgeschlossen
        // add_filter('wp_nav_menu_objects', array($this, 'nav_menu_objects'), 10, 1);

        // Anpassung des Abfrageobjekts
        add_filter('pre_get_posts', array($this, 'pre_get_posts_single'));

        add_action('views_edit-page', array($this, 'views_edit'));
        add_filter('pre_get_posts', array($this, 'pre_get_posts_list'));

        add_action('template_redirect', array($this, 'template_redirect'), 0);

        // WP-REST-API
        add_filter("rest_page_query", array($this, 'rest_filter'));
        add_filter("rest_attachment_query", array($this, 'rest_filter'));
        // Pending development
        add_filter('rest_post_dispatch', function ($result, $server, $request) {
            return $result;
        }, 10, 3);
    }

    public function request_file()
    {
        Files::request_file();
    }

    public function change_upload_directory($param)
    {
        return Files::change_upload_directory($param);
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

        $protected_test = Files::protected_upload_dir('/access_rewrite_test.txt?access_rewrite_test=1', true);

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

        $protected_path = $uploads_path . '(' . Files::protected_upload_dir('/.*\.\w+)$', true);

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
            plugins_url('build/access.style.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_style(
            'rrze-ac-attachment',
            plugins_url('build/attachment.style.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_style(
            'rrze-ac-media',
            plugins_url('build/media.style.css', plugin()->getBasename()),
            plugin()->getVersion()
        );

        wp_register_script(
            'rrze-ac-upload',
            plugins_url('build/upload.style.js', plugin()->getBasename()),
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

        return $wpdb->get_results($wpdb->prepare($query, Post::ACCESS_PERMISSION_META_KEY));
    }

    protected function meta_values()
    {
        global $wpdb;

        $metas = [];

        $result = $wpdb->get_results("
            SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '" . Post::ACCESS_PERMISSION_META_KEY . "'
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

    public function action_url($atts = [])
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
            if ($menu_item->object == 'page' && !Access::try($menu_item->object_id)) {
                unset($menu_items[$key]);
            }
        }

        return $menu_items;
    }

    public function template_redirect()
    {
        if (is_page() || is_attachment()) {
            global $post;
            if (!Access::try($post->ID)) {
                wp_die(
                    Access::permission_message($post->ID, $this->options),
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
        $post_not_in = [];
        $permissions = permissions()->get_the_permissions();
        $permission_metas = permissions()->get_permission_metas($args['post_type']);

        foreach ($permission_metas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !permissions()->check_author_permission($pm->post_id)) {
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

        $post_not_in = [];
        $permissions = permissions()->get_the_permissions();
        $permission_metas = $this->get_permission_metas();

        foreach ($permission_metas as $pm) {
            if (isset($permissions[$pm->meta_value]) && $permissions[$pm->meta_value]['active'] && !permissions()->check_author_permission($pm->post_id)) {
                $post_not_in[] = $pm->post_id;
            }
        }

        if (!empty($post_not_in)) {
            $query->set('post__not_in', $post_not_in);
        }

        return $query;
    }

    public function walker_nav_menu_edit($output, $item, $depth, $args, $id)
    {
        $permission = get_post_meta($item->object_id, Post::ACCESS_PERMISSION_META_KEY, true);
        $permissions = permissions()->get_the_permissions();
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
            'label_count'               => _n_noop(
                /* translators: %s: label count */
                'Protected <span class="count">(%s)</span>',
                'Protected <span class="count">(%s)</span>',
                'rrze-ac'
            ),
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
                        'key' => Post::ACCESS_PERMISSION_META_KEY,
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
                'key' => Post::ACCESS_PERMISSION_META_KEY,
                'compare' => 'EXISTS'
            ]
        ];

        $query->set('meta_query', $meta_query);

        return $query;
    }
}
