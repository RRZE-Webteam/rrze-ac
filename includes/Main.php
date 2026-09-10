<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;
use RRZE\AccessControl\Media\Rewrite;

class Main
{
    public $options;
    public $optionName;

    public $settings;
    public $page_slug;
    public $settings_prefix;

    public function __construct()
    {
        $this->options = Options::getOptions();
        $this->optionName = Options::getOptionName();

        $this->settings = new Settings($this);
    }

    public function loaded()
    {
        Rewrite::init();

        Files::init();

        Post::init();

        Attachment::init();

        permissions()->loaded();

        add_filter('plugin_action_links_' . plugin()->getBaseName(), function ($links) {
            $settings_link = '<a href="' . esc_url(Utils::actionUrl(['tab' => 'general'])) . '">' . esc_html(__("Settings", 'rrze-ac')) . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        add_action('admin_notices', [$this->settings, 'adminNotices']);

        add_action('template_redirect', [$this, 'templateRedirect'], 0);

        add_filter('body_class', [$this, 'bodyClasses']);

        add_filter('wp_die_handler', [Access::class, 'filterDieHandler']);
    }

    public function adminEnqueueScripts()
    {
        $version = Config::get('version');

        wp_register_style(
            'rrze-ac-admin',
            plugins_url('build/rrze-ac-admin.css', plugin()->getBasename()),
            [],
            $version
        );

        wp_register_script(
            'rrze-ac-admin',
            plugins_url('build/rrze-ac-admin.js', plugin()->getBasename()),
            ['jquery', 'jquery-ui-slider', 'media-editor'],
            $version,
            true
        );

        wp_enqueue_style('rrze-ac-admin');

        $screen = get_current_screen();

        if (isset($screen->id) && 'page' == $screen->id) {
            wp_enqueue_script('rrze-ac-admin');
        } elseif (isset($screen->id) && 'settings_page_rrze-ac' == $screen->id) {
            wp_enqueue_script('rrze-ac-admin');
        } elseif (isset($screen->base) && 'upload' == $screen->base) {
            wp_enqueue_script('rrze-ac-admin');
        } elseif (isset($screen->base) && 'media' == $screen->base) {
            wp_enqueue_script('rrze-ac-admin');
        }
    }

    public function templateRedirect()
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (is_page() || is_attachment()) {
            global $post;
            if (!Access::try($post->ID)) {
                wp_die(
                    wp_kses(Access::permissionMessage($post->ID, $this->options), Access::allowedErrorHtml()),
                    esc_html__('Login is required', 'rrze-ac'),
                    [
                        'response' => '403',
                        'back_link' => false,
                        'rrze_ac_permission_error' => true
                    ]
                );
            }
        }
    }

    public function bodyClasses($classes)
    {
        if (!is_singular()) {
            return $classes;
        }

        $postId = get_queried_object_id();
        if (!$postId) {
            return $classes;
        }

        if (!permissions()->getThePermission($postId)) {
            return $classes;
        }

        $classes[] = 'rrze-ac-protected';

        return array_unique($classes);
    }
}
