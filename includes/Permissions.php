<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

use RRZE\AccessControl\Media\Files;
use RRZE\AccessControl\Network\{IP, RemoteAddress};
use RRZE\AccessControl\SSO\SimpleSAML;
use RRZE\AccessControl\Crawler\Siteimprove;

class Permissions
{
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

    public function logInfo($data)
    {
        if (!$this->infoLoggingEnabled()) {
            return;
        }

        $message = $data['message'] ?? '';

        if (empty($message)) {
            return;
        }

        unset($data['plugin'], $data['message']);

        do_action('rrze.log.info', 'RRZE-AC: ' . $message, $data);
    }

    public function infoLoggingEnabled()
    {
        return !empty($this->options['log_info_messages']);
    }

    public function loaded()
    {
        add_filter('rest_request_before_callbacks', [$this, 'restRequestBeforeCallbacks'], 10, 3);
    }

    public function restRequestBeforeCallbacks($response, $handler, $request)
    {
        if ($response instanceof \WP_Error || $response instanceof \WP_REST_Response) {
            return $response;
        }

        $urlParams = $request->get_url_params();
        $postId = isset($urlParams['id']) ? absint($urlParams['id']) : 0;

        if (!$postId) {
            return $response;
        }

        $postType = get_post_type($postId);
        if (!in_array($postType, Config::get('post_types'), true)) {
            return $response;
        }

        if (Access::try($postId)) {
            return $response;
        }

        return new \WP_Error(
            'rest_cannot_access',
            __('Unauthorized access to the protected resource.', 'rrze-ac'),
            ['status' => rest_authorization_required_code()]
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

        $permissionKey = strtolower($permissionKey);
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

        $permission = get_post_meta($postId, Post::accessPermissionMetaKey(), true);

        return !empty($permission) ? $permission : false;
    }

    public function checkPrivilegedAccess()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        if (current_user_can('rrze_websupport_site_admin')) {
            return true;
        }

        return false;
    }

    public function currentUserCanViewContentPermission($postId)
    {
        if (!current_user_can('edit_post', $postId)) {
            return false;
        }

        if ($this->currentUserCanChangeContentPermission($postId)) {
            return true;
        }

        return $this->contentPermissionIsSet($postId);
    }

    public function currentUserCanChangeContentPermission($postId = 0)
    {
        if ($this->currentUserCanManageContentPermissions()) {
            return true;
        }

        if ($postId && !current_user_can('edit_post', $postId)) {
            return false;
        }

        return $this->currentUserMeetsPermissionEditorRole();
    }

    public function currentUserCanManageContentPermissions()
    {
        return is_super_admin() || current_user_can('manage_options');
    }

    public function currentUserMeetsPermissionEditorRole()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $selectedRole = $this->getPermissionEditorRole();
        $selectedLevel = $this->roleLevel($selectedRole);
        $user = wp_get_current_user();

        if (!$this->roleHasLevelCapability($selectedRole)) {
            return in_array($selectedRole, (array) $user->roles, true);
        }

        return $this->currentUserRoleLevel() >= $selectedLevel;
    }

    public function getPermissionEditorRole()
    {
        $role = !empty($this->options['permission_editor_role']) ? sanitize_key($this->options['permission_editor_role']) : 'administrator';

        if (!wp_roles()->is_role($role)) {
            return 'administrator';
        }

        return $role;
    }

    public function roleLevel($role)
    {
        $roleObject = get_role($role);

        if (!$roleObject) {
            return 10;
        }

        $level = 0;
        foreach ($roleObject->capabilities as $capability => $enabled) {
            if (!$enabled || !preg_match('/^level_([0-9]+)$/', $capability, $matches)) {
                continue;
            }

            $level = max($level, (int) $matches[1]);
        }

        return $level;
    }

    private function roleHasLevelCapability($role)
    {
        $roleObject = get_role($role);

        if (!$roleObject) {
            return false;
        }

        foreach ($roleObject->capabilities as $capability => $enabled) {
            if ($enabled && preg_match('/^level_([0-9]+)$/', $capability)) {
                return true;
            }
        }

        return false;
    }

    private function currentUserRoleLevel()
    {
        $user = wp_get_current_user();
        $level = 0;

        foreach ((array) $user->roles as $role) {
            $level = max($level, $this->roleLevel($role));
        }

        return $level;
    }

    private function contentPermissionIsSet($postId)
    {
        if (empty($postId)) {
            return false;
        }

        if (get_post_type($postId) == 'attachment' && Files::isAttachmentProtected($postId)) {
            return true;
        }

        return !empty(get_post_meta($postId, Post::accessPermissionMetaKey(), true));
    }

    public function checkAuthorPermission($postId)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $current_user = wp_get_current_user();

        $post = get_post($postId);
        if (!$post) {
            return false;
        }

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

    public function getAttachmentPermission($attachmentId)
    {
        if (!Files::isAttachmentProtected($attachmentId)) {
            return false;
        }

        $permission = get_post_meta($attachmentId, Post::accessPermissionMetaKey(), true);

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
            do_action(
                'rrze.log.warning',
                'RRZE-AC: Wrong password submitted.',
                [
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'postID' => $postId,
                    'permalink' => get_permalink($postId)
                ]
            );
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
            return false;
        }

        $remoteAddr = $this->getRemoteIpAddress($ipAddress);

        if (!$remoteAddr) {
            $this->logInfo([
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => 'Remote IP address is UNKNOWN.'
            ]);
            return false;
        }

        $ip = IP::fromStringIP($remoteAddr);

        if ($ip->isInRanges($ipAddress)) {
            return true;
        }

        $this->logInfo([
            'plugin' => 'rrze-ac',
            'method' => __METHOD__,
            'message' => sprintf('Remote IP address %s is not in range.', $remoteAddr)
        ]);
        return false;
    }

    public function getRemoteIpAddress($ipAddress = [])
    {
        $remoteAddress = new RemoteAddress($ipAddress);
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
            $this->logInfo([
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => 'Remote IP address is UNKNOWN.'
            ]);
            return false;
        }

        $ip = IP::fromStringIP($remoteAddr);
        $hostname = $ip->getHostname();

        if ($hostname === null) {
            $this->logInfo([
                'plugin' => 'rrze-ac',
                'method' => __METHOD__,
                'message' => sprintf('Cannot get hostname from remote IP address %s.', $remoteAddr)
            ]);
            return false;
        }

        foreach ($allowedDomains as $domain) {
            if (strrpos($domain, $hostname) !== false) {
                return true;
            }
        }

        $this->logInfo([
            'plugin' => 'rrze-ac',
            'method' => __METHOD__,
            'message' => sprintf('Remote hostname %s is not allowed.', $hostname)
        ]);
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

    public function ssoPluginIsAvailableAndActive(): bool {
        return Utils::isPluginInstalledAndActive(Config::get('sso_plugin'));
    }

    /**
     * SSO: Check if an instance of SimpleSAML can be initialized
     * @return boolean
     */
    public function simplesamlAuth()
    {
        if ($this->ssoPluginIsAvailableAndActive()) {
            if (is_multisite()) {
                $options = get_site_option(Config::get('sso_plugin_option_name'));
            } else {
                $options = get_option(Config::get('sso_plugin_option_name'));
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
            return false;
        }

        foreach ($affiliation as $attribute) {
            if (in_array($attribute, $this->personAffiliation)) {
                return true;
            }
        }

        $this->logInfo([
            'plugin' => 'rrze-ac',
            'method' => __METHOD__,
            'message' => 'Wrong person affiliation attribute.',
            'person_atributes' => $this->personAttributes
        ]);
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
            return false;
        }

        foreach ($entitlement as $attribute) {
            if (in_array($attribute, $this->personEntitlement)) {
                return true;
            }
        }

        $this->logInfo([
            'plugin' => 'rrze-ac',
            'method' => __METHOD__,
            'message' => 'Wrong person entitlement attribute.',
            'person_atributes' => $this->personAttributes
        ]);
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
                $this->logInfo([
                    'plugin' => 'rrze-ac',
                    'method' => __METHOD__,
                    'message' => 'Crawler IP address is not in range.'
                ]);
                return false;
            }
        }
        return true;
    }
}
