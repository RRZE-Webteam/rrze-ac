<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Utils
{
    /**
     * Check if the plugin is active.
     * @return boolean
     */
    public static function isPluginActive(string $plugin): bool
    {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        return is_plugin_active($plugin);
    }

    /**
     * Check if the plugin is active for network.
     * @return boolean
     */
    public static function isPluginActiveForNetwork(string $plugin): bool
    {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        return is_plugin_active_for_network($plugin);
    }

    /**
     * Encode email address
     * @param string $email
     * @return string
     */
    public static function encodeEmail(string $email): string
    {
        $output = '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            for ($i = 0; $i < mb_strlen($email); $i++) {
                $output .= '&#' . ord($email[$i]) . ';';
            }
        }
        return $output;
    }

    /**
     * Encrypt and decrypt string
     * @param string $string
     * @param string $action
     * @return string
     */
    public static function crypt($string, $action = 'encrypt')
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

    /**
     * Generate a URL with an action
     * @param array $atts
     * @return string URL
     */
    public static function actionUrl($atts = [])
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
}
