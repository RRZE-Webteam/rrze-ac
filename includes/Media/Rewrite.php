<?php

namespace RRZE\AccessControl\Media;

defined('ABSPATH') || exit;

use RRZE\AccessControl\{Config, Options};

class Rewrite
{
    public static function maybeHandleRewriteCheck()
    {
        if (empty($_GET['access_rewrite_test']) || empty($_GET['protected_file'])) {
            return;
        }

        http_response_code(200);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'rewrite test passed';
        exit();
    }

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
                update_site_option($enabledOptionName, 1);
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
        if (is_wp_error($check)) {
            self::setRewriteCheckError(sprintf(
                /* translators: 1: The rewrite check URL, 2: The WordPress error message. */
                __('Rewrite check failed for %1$s. WordPress returned: %2$s', 'rrze-ac'),
                esc_url_raw($checkUrl),
                $check->get_error_message()
            ));
            return false;
        }

        $responseCode = wp_remote_retrieve_response_code($check);
        $body = trim(wp_remote_retrieve_body($check));

        if (200 != $responseCode || 'rewrite test passed' != $body) {
            self::setRewriteCheckError(sprintf(
                /* translators: 1: The rewrite check URL, 2: The HTTP status code, 3: The response body. */
                __('Rewrite check failed for %1$s. HTTP status: %2$s. Response body: %3$s', 'rrze-ac'),
                esc_url_raw($checkUrl),
                (string) $responseCode,
                $body !== '' ? esc_html(wp_trim_words($body, 20, '...')) : __('Empty response body', 'rrze-ac')
            ));
            return false;
        }

        delete_site_transient(Config::get('rewrite_check_error_transient'));
        return true;
    }

    protected static function setRewriteCheckError($message)
    {
        set_site_transient(
            Config::get('rewrite_check_error_transient'),
            $message,
            Config::get('rewrite_check_error_transient_expiration')
        );
    }

    protected static function getRewriteCheckError()
    {
        return get_site_transient(Config::get('rewrite_check_error_transient'));
    }

    protected static function rewriteRules()
    {
        $rewriteRules = [];
        $protectedDirname = preg_quote(Config::get('protected_upload_dirname'), '/');

        $rewriteRules[] = '# BEGIN RRZE ACCESS CONTROL WP PLUGIN';
        if (is_subdomain_install()) {
            $rewriteRules[] =
                'RewriteRule ^wp-content(?:\/uploads(?:\/sites\/[0-9]+)?|\/blogs\.dir\/[0-9]+\/files)(\/' . $protectedDirname . '\/.*\.\w+)$ index.php?protected_file=$1 [QSA,L]';
        } else {
            $rewriteRules[] =
                'RewriteRule ^([_0-9a-zA-Z-]+\/)wp-content(?:\/uploads(?:\/sites\/[0-9]+)?|\/blogs\.dir\/[0-9]+\/files)(\/' . $protectedDirname . '\/.*\.\w+)$ index.php?protected_file=$1 [QSA,L]';
        }
        if (get_site_option('ms_files_rewriting')) {
            $rewriteRules[] = 'RewriteRule ^files(\/' . $protectedDirname . '\/.*\.\w+)$ index.php?protected_file=$1 [QSA,L]';
        }
        $rewriteRules[] = '# END RRZE ACCESS CONTROL WP PLUGIN';

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
            if ($rewriteCheckError = self::getRewriteCheckError()) {
                $message .= '<p>' . esc_html($rewriteCheckError) . '</p>';
            }
        } else {
            $message .= __("Please contact your system administrator.", 'rrze-ac');
        } ?>
        <div class="error">
            <p><?php echo $message; ?></p>
        </div>
<?php
    }
}
