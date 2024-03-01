<?php

namespace RRZE\AccessControl\SSO;

defined('ABSPATH') || exit;

use function RRZE\AccessControl\plugin;

/**
 * SimpleSAML
 */
class SimpleSAML
{
    /**
     * SimpleSAML options
     * @var object
     */
    private $options;

    /**
     * __construct
     */
    public function __construct($options)
    {
        $this->options = (object) $options;
    }

    /**
     * loaded
     * @return mixed
     */
    public function loaded()
    {
        $simplesaml = $this->loadSimpleSAML();
        if (is_wp_error($simplesaml)) {
            add_action('admin_init', function () use ($simplesaml) {
                if (current_user_can('activate_plugins')) {
                    $error = $simplesaml->get_error_message();
                    $pluginData = get_plugin_data(plugin()->getFile());
                    $pluginName = $pluginData['Name'];
                    $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';
                    add_action($tag, function () use ($pluginName, $error) {
                        printf(
                            '<div class="notice notice-error"><p>' .
                                /* translators: 1: The plugin name, 2: The error string. */
                                __('Plugins: %1$s: %2$s', 'rrze-ac') .
                                '</p></div>',
                            esc_html($pluginName),
                            esc_html($error)
                        );
                    });
                }
            });
            return false;
        }
        return $simplesaml;
    }

    /**
     * loadSimpleSAML
     * @return mixed
     */
    protected function loadSimpleSAML()
    {
        if (file_exists(WP_CONTENT_DIR . $this->options->simplesaml_include)) {
            require_once(WP_CONTENT_DIR . $this->options->simplesaml_include);
            try {
                $auth = new \SimpleSAML\Auth\Simple($this->options->simplesaml_auth_source);
            } catch (\Exception $e) {
                return new \WP_Error('simplesaml_auth_error', $e->getMessage());
            }
            return $auth;
        }
        return new \WP_Error('simplesaml_could_not_be_loaded', __('The simpleSAML library could not be loaded.', 'rrze-ac'));
    }
}
