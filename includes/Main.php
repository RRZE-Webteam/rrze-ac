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

        add_filter('plugin_action_links_' . plugin()->getBaseName(), function ($links) {
            $settings_link = '<a href="' . Utils::actionUrl(['page' => 'rrze-ac-settings']) . '">' . esc_html(__("Settings", 'rrze-ac')) . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        add_action('admin_notices', [$this->settings, 'adminNotices']);

        add_action('template_redirect', [$this, 'templateRedirect'], 0);
    }

    public function adminEnqueueScripts()
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

    public function templateRedirect()
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (is_page() || is_attachment()) {
            global $post;
            if (!Access::try($post->ID)) {
                wp_die(
                    Access::permissionMessage($post->ID, $this->options),
                    __('Login is required', 'rrze-ac'),
                    [
                        'response' => '403',
                        'back_link' => false
                    ]
                );
            }
        }
    }
}
