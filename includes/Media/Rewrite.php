<?php

namespace RRZE\AccessControl\Media;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Options;

class Rewrite
{
    public static function init()
    {
        add_action('init', [__CLASS__, 'checkRewrite']);

        $enabledOptionName = Options::getEnabledOptionName();
        if (!get_site_option($enabledOptionName)) {
            add_action('admin_notices', [__CLASS__, 'adminErrorNotice']);
            add_action('network_admin_notices', [__CLASS__, 'adminErrorNotice']);
            return;
        }
    }

    public static function checkRewrite()
    {
        $enabledOptionName = Options::getEnabledOptionName();
        if (is_admin() && !get_site_option($enabledOptionName)) {
            global $pagenow;
            if (self::checkRewriteRules()) {
                add_site_option($enabledOptionName, 1);
                wp_redirect(admin_url($pagenow ? $pagenow : ''));
                exit();
            }
        }
    }

    protected static function checkRewriteRules()
    {
        $uploadDir = wp_upload_dir();

        $protectedTest = Files::protectedUploadDir('/access_rewrite_test.txt?access_rewrite_test=1', true);

        $checkUrl = $uploadDir['baseurl'] . $protectedTest;
        $check = wp_remote_get($checkUrl, array('sslverify' => false, 'httpversion' => '1.1'));
        if (is_wp_error($check) || !isset($check['response']['code']) || 200 != $check['response']['code'] || !isset($check['body']) || 'rewrite test passed' != $check['body']) {
            return false;
        }

        return true;
    }

    protected static function rewriteRules()
    {
        $uploadsPath = '';

        if (!get_site_option('ms_files_rewriting')) {
            $uploadsPath .= 'wp-content(?:/uploads)?(?:/sites/[0-9]+)?';
        } else {
            $uploadsPath .= '(?:wp-content/uploads)?(?:files)?';
        }

        if (!is_subdomain_install()) {
            $uploadsPath = '(?:[_0-9a-zA-Z-]+/)?' . $uploadsPath;
        }

        $protectedPath = $uploadsPath . '(' . Files::protectedUploadDir('/.*\.\w+)$', true);

        $rewriteRules = array(
            '# Beginn Access Rewrite Rules',
            'RewriteRule ^' . $protectedPath . ' index.php?protected_file=$1 [QSA,L]',
            '# End Access Rewrite Rules'
        );

        return $rewriteRules;
    }

    public static function adminErrorNotice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = __("The RRZE Access Control Plugin is not configured properly. The files and documents can not be protected.", 'rrze-ac');
        $message .= ' ';
        if (is_network_admin() || is_super_admin()) {
            $message .= __("The following rewrite commands must be added in the .htaccess file after the WordPress command &#8222;RewriteRule ^index\\.php$ - [L]&#8220;.", 'rrze-ac');
            $message .= '<p>' . implode('<br>', self::rewriteRules()) . '</p>';
        } else {
            $message .= __("Please contact your system administrator.", 'rrze-ac');
        } ?>
        <div class="error">
            <p><?php echo $message; ?></p>
        </div>
<?php
    }
}
