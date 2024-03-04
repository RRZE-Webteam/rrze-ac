<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;
use RRZE\AccessControl\Network\{IP, RemoteAddress};
use RRZE\AccessControl\SSO\SimpleSAML;
use RRZE\AccessControl\Crawler\Siteimprove;

class Permissions
{
    const SSO_PLUGIN = 'rrze-sso/rrze-sso.php';

    const SSO_PLUGIN_OPTION_NAME = 'rrze_sso';

    public $user_isnt_logged_in = 0;

    public $user_ip_isnt_in_range = 1;

    public $user_isnt_sso_logged_in = 2;

    public $user_hasnt_affiliation = 4;

    public $user_hasnt_entitlement = 8;

    public $user_domain_not_allowed = 16;

    public $wrong_password = 32;

    public $permission_status = null;

    /**
     * protected $options
     * @var array
     */
    protected $options = [];

    /**
     * public $simplesamlAuth
     * @var null|object
     */
    public $simplesamlAuth = null;

    /**
     * public $personAttributes
     * @var null|array
     */
    public $personAttributes = null;

    /**
     * public $personAffiliation
     * @var null|array
     */
    public $personAffiliation = null;

    /**
     * public $personEntitlement
     * @var null|array
     */
    public $personEntitlement = null;

    public function __construct()
    {
        $this->options = Options::getOptions();
    }

    public function loaded()
    {
        // WP-REST-API
        add_filter(
            'rest_authentication_errors',
            fn ($result) => empty($result) && !is_user_logged_in()
                ? new \WP_Error(
                    'rest_cannot_access',
                    __('Unauthorized access to the REST API.', 'rrze-ac'),
                    ['status' => rest_authorization_required_code()]
                )
                : $result
        );
    }

    public function getDefaultPermission()
    {
        $permissions = $this->getThePermissions();
        $default_permission = isset($permissions[$this->options['default_permission']]) && $permissions[$this->options['default_permission']]['active'] ? $this->options['default_permission'] : 'logged-in';
        return $default_permission;
    }

    public function getPermission($permissionKey)
    {
        if (empty($permissionKey)) {
            return [];
        }

        $permission = [];
        foreach ($this->options['permissions'] as $key => $value) {
            if ($key == $permissionKey) {
                $permission =  [
                    'permission_key' => $permissionKey,
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
                ];
            }
        }

        return $permission;
    }

    public function getThePermissions()
    {
        $permissions = $this->options['permissions'];
        return apply_filters('rrze_ac_permissions', $permissions);
    }

    public function getThePermission($postId)
    {
        if (get_post_type($postId) == 'attachment') {
            return $this->getAttachmentPermission($postId);
        }

        $permission = get_post_meta($postId, Post::ACCESS_PERMISSION_META_KEY, true);

        return !empty($permission) ? $permission : false;
    }

    public function checkAuthorPermission($postId)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $current_user = wp_get_current_user();

        $post = get_post($postId);

        $post_author = $post->post_author;

        $authors = $this->postAuthors($postId, $post_author);

        if (isset($authors[$current_user->ID])) {
            return true;
        }

        return false;
    }

    /**
     * Get Post Authors
     * @param  integer $postId
     * @param  integer $post_author
     * @return array
     */
    public function postAuthors($postId, $post_author)
    {
        $authors = [];

        // cms-workflow plugin stuff.
        if (Utils::isPluginActive('cms-workflow/cms-workflow.php')) {
            $authors = $this->workflowAuthors($postId);
        }

        $authors[$post_author] = $post_author;

        return $authors;
    }

    /**
     * Get Workflow Authors
     * CMS-Worfklow plugin stuff.
     * @param  integer $postId
     * @return array
     */
    public function workflowAuthors($postId)
    {
        global $wpdb;

        $authors = [];

        $workflowAuthors = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.name
                FROM $wpdb->terms AS t
                INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id
                INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy IN ('workflow_author') AND tr.object_id IN (%d) ORDER BY t.name ASC",
                $postId
            )
        );

        if ($workflowAuthors) {
            foreach ($workflowAuthors as $author) {
                $user = get_user_by('login', $author);
                if (!$user || !is_user_member_of_blog($user->ID)) {
                    continue;
                }

                $authors[$user->ID] = $user->ID;
            }
        }

        return $authors;
    }

    public function getAttachmentPermission($attachment_id)
    {
        if (!Files::is_attachment_protected($attachment_id)) {
            return false;
        }

        $permission = get_post_meta($attachment_id, Post::ACCESS_PERMISSION_META_KEY, true);

        return empty($permission) ? $this->getDefaultPermission() : $permission;
    }

    public function getPermissionStatus($bitmask)
    {
        return ($this->permission_status & (1 << $bitmask)) != 0;
    }

    public function set_permission_status($bitmask, $newBit = true)
    {
        $this->permission_status = ($this->permission_status & ~(1 << $bitmask)) | ($newBit << $bitmask);
    }

    /**
     * Is User A Member Of The Website?
     * @return boolean
     */
    public function isUserMember()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        return array_key_exists(
            get_current_blog_id(),
            get_blogs_of_user(get_current_user_id())
        );
    }

    /**
     * Check Password
     * @param  integer $postId
     * @param  string  $allowedPassword
     * @return boolean
     */
    public function checkPassword($postId, $allowedPassword = '')
    {
        if ('publish' != get_post_status($postId) || $allowedPassword === '') {
            return true;
        }
        $cookieName = 'rrze_ac_password_' . $postId;
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'rrze_ac_submit_password_wpnonce')) {
            $password = isset($_POST[$cookieName]) ? sanitize_text_field($_POST[$cookieName]) : '';
            if (preg_match('/^[a-z0-9]{8,32}$/i', $password) && $password == $allowedPassword) {
                setcookie($cookieName, Utils::crypt($password), strtotime('+1 hour'), COOKIEPATH, COOKIE_DOMAIN, true);
                $location = site_url(add_query_arg([], (string) wp_get_raw_referer()));
                wp_safe_redirect($location);
                exit;
            }
            return false;
        }

        if (isset($_COOKIE[$cookieName])) {
            $password = Utils::crypt($_COOKIE[$cookieName], 'decrypt');
            if (preg_match('/^[a-z0-9]{8,32}$/i', $password) && $password == $allowedPassword) {
                return true;
            }
        }

        unset($_COOKIE[$cookieName]);
        return false;
    }

    /**
     * Check Ip Address Range
     * @param  array  $ipAddress
     * @return boolean
     */
    public function checkIpAddressRange($ipAddress = [])
    {
        if (empty($ipAddress)) {
            return false;
        }

        if (!is_array($ipAddress)) {
            do_action(
                'rrze.log.warning',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => 'Wrong IP address range type. Must be an array.'
                ]
            );
            return false;
        }

        $remoteAddr = $this->getRemoteIpAddress();

        if (!$remoteAddr) {
            do_action(
                'rrze.log.warning',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => 'Remote IP address is UNKNOWN.'
                ]
            );
            return false;
        }

        $ip = IP::fromStringIP($remoteAddr);

        if ($ip->isInRanges($ipAddress)) {
            return true;
        }

        do_action(
            'rrze.log.notice',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => sprintf('Remote IP address %s is not in range.', $remoteAddr)
            ]
        );
        return false;
    }

    public function getRemoteIpAddress()
    {
        $remoteAddress = new RemoteAddress();
        return $remoteAddress->getIpAddress();
    }

    /**
     * checkRemoteDomain
     * @param array $allowedDomains
     * @return boolean
     */
    public function checkRemoteDomain($allowedDomains)
    {
        if (empty($allowedDomains) || !is_array($allowedDomains)) {
            return true;
        }

        $remoteAddr = $this->getRemoteIpAddress();

        if (!$remoteAddr) {
            do_action(
                'rrze.log.warning',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' =>
                    'Remote IP address is UNKNOWN.'
                ]
            );
            return false;
        }

        $ip = IP::fromStringIP($remoteAddr);
        $hostname = $ip->getHostname();

        if ($hostname === null) {
            do_action(
                'rrze.log.notice',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => sprintf('Cannot get hostname from remote IP address %s.', $remoteAddr)
                ]
            );
            return false;
        }

        foreach ($allowedDomains as $domain) {
            if (strrpos($domain, $hostname) !== false) {
                return true;
            }
        }

        do_action(
            'rrze.log.notice',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => sprintf('Remote hostname %s is not allowed.', $hostname)
            ]
        );
        return false;
    }

    /**
     * Check if user is SSO logged in
     * @return boolean
     */
    public function checkSSOLoggedIn()
    {
        if (!$this->simplesamlAuth()) {
            return false;
        }

        if (!$this->simplesamlAuth->isAuthenticated()) {
            \SimpleSAML\Session::getSessionFromRequest()->cleanup();
            if ($this->options['automatic_sso_authentication']) {
                $this->simplesamlAuth->requireAuth();
                \SimpleSAML\Session::getSessionFromRequest()->cleanup();
            }
            return false;
        }

        $this->personAttributes = $this->simplesamlAuth->getAttributes();
        $this->personAffiliation = $this->personAttributes['eduPersonAffiliation'] ?? [];
        $this->personEntitlement = $this->personAttributes['eduPersonEntitlement'] ?? [];

        return true;
    }

    /**
     * SSO: Check if an instance of SimpleSAML can be initialized
     * @return boolean
     */
    public function simplesamlAuth()
    {
        if (Utils::isPluginActive(self::SSO_PLUGIN)) {
            if (is_multisite()) {
                $options = get_site_option(self::SSO_PLUGIN_OPTION_NAME);
            } else {
                $options = get_option(self::SSO_PLUGIN_OPTION_NAME);
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

        $this->simplesamlAuth = new SimpleSAML($options);
        $this->simplesamlAuth = $this->simplesamlAuth->loaded();
        if ($this->simplesamlAuth === false) {
            return false;
        }

        return true;
    }

    /**
     * SSO: Check Person Affiliation
     * @param  array $affiliation
     * @return boolean
     */
    public function checkPersonAffiliation($affiliation)
    {
        if (empty($affiliation)) {
            return false;
        }

        if (!is_array($affiliation)) {
            do_action(
                'rrze.log.warning',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => 'Wrong person affiliation attribute value. Must be an array.',
                    'person_atributes' => $this->personAttributes
                ]
            );
            return false;
        }

        foreach ($affiliation as $attribute) {
            if (in_array($attribute, $this->personAffiliation)) {
                return true;
            }
        }

        do_action(
            'rrze.log.warning',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => 'Wrong person affiliation attribute.',
                'person_atributes' => $this->personAttributes
            ]
        );
        return false;
    }

    /**
     * SSO: Check Person Entitlement
     * @param  array $entitlement
     * @return boolean
     */
    public function checkPersonEntitlement($entitlement)
    {
        if (empty($entitlement)) {
            return false;
        }

        if (!is_array($entitlement)) {
            do_action(
                'rrze.log.warning',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => 'Wrong person entitlement attribute value. Must be an array.',
                    'person_atributes' => $this->personAttributes
                ]
            );
            return false;
        }

        foreach ($entitlement as $attribute) {
            if (in_array($attribute, $this->personEntitlement)) {
                return true;
            }
        }

        do_action(
            'rrze.log.warning',
            [
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => 'Wrong person entitlement attribute.',
                'person_atributes' => $this->personAttributes
            ]
        );
        return false;
    }

    /**
     * Check Siteimprove
     * @return boolean
     */
    public function checkSiteimprove()
    {
        $ipAddresses = Siteimprove::getIpAddresses();
        if (!empty($ipAddresses)) {
            if (!permissions()->checkIpAddressRange($ipAddresses)) {
                do_action(
                    'rrze.log.notice',
                    [
                        'plugin' => 'rrze-ac',
                        'type' => __METHOD__,
                        'message' => 'Crawler IP address is not in range.'
                    ]
                );
                return false;
            }
        }
        return true;
    }
}
