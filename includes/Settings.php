<?php

namespace RRZE\AccessControl;

use RRZE\AccessControl\Network\IPUtils;

defined('ABSPATH') || exit;

class Settings
{
    protected $main;

    protected $optionName;

    protected $options;

    protected $listTable;

    protected $settingsErrorTransient;

    protected $settingsErrorTransientExpiration;

    protected $notice_transient;

    protected $notice_transient_expiration;

    public function __construct(Main $main)
    {
        $this->main = $main;
        $this->optionName = $this->main->optionName;
        $this->options = $this->main->options;
        $this->settingsErrorTransient = Config::get('settings_error_transient');
        $this->settingsErrorTransientExpiration = Config::get('settings_error_transient_expiration');
        $this->notice_transient = Config::get('notice_transient');
        $this->notice_transient_expiration = Config::get('notice_transient_expiration');

        add_action('admin_menu', array($this, 'adminMenu'));

        add_action('admin_init', array($this, 'adminActions'));
        add_action('admin_init', array($this, 'adminSettings'));
    }

    public function adminMenu()
    {
        $this->validateActions();

        $accessPage = add_menu_page(__("Access Restriction", 'rrze-ac'), __("Access Restriction", 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'), 'dashicons-shield');
        add_submenu_page('rrze-ac', __("Permissions", 'rrze-ac'), __("Permissions", 'rrze-ac'), 'manage_options', 'rrze-ac', array($this, 'access_permissions_page'));
        add_action("load-{$accessPage}", array($this, 'loadAccessPage'));
        add_action("load-{$accessPage}", array($this, 'accessScreenOptions'));

        add_submenu_page('rrze-ac', __("Settings", 'rrze-ac'), __("Settings", 'rrze-ac'), 'manage_options', 'rrze-ac-settings', array($this, 'accessSettingsPage'));
    }

    public function loadAccessPage()
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        $this->listTable = new ListTable($this->main);
        $this->listTable->prepare_items();
    }

    public function accessScreenOptions()
    {
        $option = 'per_page';
        $args = array(
            'label' => __("Items per page:", 'rrze-ac'),
            'default' => 20,
            'option' => 'rrzeacs_per_page'
        );

        add_screen_option($option, $args);
    }

    public function access_permissions_page()
    {
        $action = $this->requestVar('action');
        $option_page = $this->requestVar('option_page'); ?>
        <div class="wrap">
            <h2>
                <?php echo esc_html(__("Permissions", 'rrze-ac')); ?>
                <?php if (empty($action)) : ?>
                    <a href="<?php echo Utils::actionUrl(array('action' => 'new')); ?>" class="add-new-h2"><?php _e("Add New Permission", 'rrze-ac'); ?></a>
                <?php endif; ?>
            </h2>
            <?php
            if ($action == 'new' || $option_page == 'rrze-ac-new') {
                $this->setNewPage();
            } elseif ($action == 'edit' || $option_page == 'rrze-ac-edit') {
                $this->setEditPage();
            } else {
                $this->setDefaultPage();
            } ?>
        </div>
    <?php
        $this->deleteSettingsErrors();
    }

    private function validateActions()
    {
        $action = $this->requestVar('action');
        $option_page = $this->requestVar('option_page');

        if ($option_page == 'rrze-ac-new') {
            $this->validateNewAction();
        } elseif ($option_page == 'rrze-ac-edit') {
            $this->validateEditAction();
        } elseif ($option_page == 'rrze-ac-settings') {
            $this->validateSettingsAction();
        }
    }

    private function validateNewAction()
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-ac-new-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }

        $permissionKey = $this->validateNew($input);
        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Utils::actionUrl(array('action' => 'new')));
            exit();
        }

        $this->addAdminNotice(__("The permission has been added.", 'rrze-ac'));
        wp_redirect(Utils::actionUrl(array('action' => 'edit', 'permission' => $permissionKey)));
        exit();
    }

    private function validateEditAction()
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-ac-edit-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }

        if (!isset($input['permission_key'])) {
            wp_die(__("Permission does not exist.", 'rrze-ac'));
        }

        $permissionKey = $input['permission_key'];
        $permission = permissions()->getPermission($permissionKey);
        if (!$permission) {
            wp_die(__("Permission does not exist.", 'rrze-ac'));
        }

        $validation = $this->validateEdit($permission, $input);

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Utils::actionUrl(array('action' => 'edit', 'permission' => $permissionKey)));
            exit();
        }

        if ($validation) {
            $this->addAdminNotice(__("The permission has been updated.", 'rrze-ac'));
        }

        wp_redirect(Utils::actionUrl(array('action' => 'edit', 'permission' => $permissionKey)));
        exit();
    }

    private function validateSettingsAction()
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-ac-settings-options')) {
            wp_die(__("Something went wrong.", 'rrze-ac'));
        }

        $validation = $this->validate_settings($input);

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Utils::actionUrl(array('page' => 'rrze-ac-settings')));
            exit();
        }

        if ($validation) {
            $this->addAdminNotice(__("The settings have been updated.", 'rrze-ac'));
        }

        wp_redirect(Utils::actionUrl(array('page' => 'rrze-ac-settings')));
        exit();
    }

    private function validateNew($input)
    {
        $input = (array) $input;

        $permissionKey = !empty($input['permission_key']) ? sanitize_title($input['permission_key']) : '';

        if (!$permissionKey) {
            $this->addSettingsError('permission_key', '', __("Permission required.", 'rrze-ac'));
        } elseif (isset($this->options['permissions'][$permissionKey])) {
            $this->addSettingsError('permission_key', $permissionKey, __("Permission already exists.", 'rrze-ac'));
        } else {
            $this->addSettingsError('permission_key', $permissionKey, '', false);
        }

        $select = isset($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';
        if (!$select) {
            $this->addSettingsError('select', '', __("Short description required.", 'rrze-ac'));
        } else {
            $this->addSettingsError('select', $select, '', false);
        }

        $domain = isset($input['domain']) && !empty(trim($input['domain'])) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['domain'])))) : '';
        $domain = $this->getValidDomains($domain);
        $domain = !empty($domain) ? $domain : '';

        $ipAddress = isset($input['ip_address']) && !empty(trim($input['ip_address'])) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['ip_address'])))) : '';
        $ipRange = $this->getIpRange($ipAddress);
        $ipAddress = !empty($ipRange) ? $ipRange : '';

        $password = !empty($input['password']) ? sanitize_text_field($input['password']) : '';
        $password = preg_match('/^[a-z0-9]{8,32}$/i', $password) ? $password : '';

        $description = !empty($input['description']) ? sanitize_textarea_field($input['description']) : '';

        $logged_in = !empty($input['logged_in']) ? 1 : 0;
        $ssoPluginIsAvailableAndActive = permissions()->ssoPluginIsAvailableAndActive();
        $ssoLoggedIn = $ssoPluginIsAvailableAndActive && !empty($input['sso_logged_in']) ? 1 : 0;

        $siteimprove = !empty($input['siteimprove']) ? 1 : 0;

        if ($this->settingsErrors()) {
            $this->addSettingsError('logged_in', $logged_in, '', false);
            $this->addSettingsError('sso_logged_in', $ssoLoggedIn, '', false);
            $this->addSettingsError('domain', $domain, '', false);
            $this->addSettingsError('ip_address', $ipAddress, '', false);
            $this->addSettingsError('password', $password, '', false);
            $this->addSettingsError('siteimprove', $siteimprove, '', false);
            $this->addSettingsError('description', $description, '', false);
            return false;
        }

        $new_permission = array(
            'permission_key' => $permissionKey,
            'logged_in' => $logged_in,
            'sso_logged_in' => $ssoLoggedIn,
            'domain' => $domain,
            'ip_address' => $ipAddress,
            'password' => $password,
            'siteimprove' => $siteimprove,
            'select' => $select,
            'description' => $description,
            'core' => 0,
            'active' => 1,
        );

        $this->options['permissions'] = array_merge($this->options['permissions'], array($permissionKey => $new_permission));

        if (update_option($this->optionName, $this->options)) {
            return $permissionKey;
        }

        return false;
    }

    private function validateEdit($permission, $input)
    {
        $permissionKey = $permission['permission_key'];

        $select = isset($input['select']) ? wp_trim_words(sanitize_text_field($input['select']), 3, '') : '';
        if (!$select) {
            $this->addSettingsError('select', '', __("Short description required.", 'rrze-ac'));
        } else {
            $this->addSettingsError('select', $select, '', false);
        }

        $permission['select'] = $select;

        $description = !empty($input['description']) ? sanitize_textarea_field($input['description']) : '';
        $permission['description'] = $description;

        $domain = isset($input['domain']) && !empty(trim($input['domain'])) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['domain'])))) : '';
        $domain = $this->getValidDomains($domain);
        $permission['domain'] = !empty($domain) ? $domain : '';

        $ipAddress = isset($input['ip_address']) && !empty(trim($input['ip_address'])) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['ip_address'])))) : '';
        $ipRange = $this->getIpRange($ipAddress);
        $permission['ip_address'] = !empty($ipRange) ? $ipRange : '';

        $password = !empty($input['password']) ? sanitize_text_field($input['password']) : '';
        $permission['password'] = preg_match('/^[a-z0-9]{8,32}$/i', $password) ? $password : '';

        $logged_in = !empty($input['logged_in']) ? 1 : 0;
        $ssoPluginIsAvailableAndActive = permissions()->ssoPluginIsAvailableAndActive();
        $ssoLoggedIn = $ssoPluginIsAvailableAndActive ? (!empty($input['sso_logged_in']) ? 1 : 0) : $permission['sso_logged_in'];

        $siteimprove = !empty($input['siteimprove']) ? 1 : 0;
        $permission['siteimprove'] = $siteimprove;

        $affiliation = $permission['affiliation'];
        if ($ssoPluginIsAvailableAndActive) {
            $affiliation = $ssoLoggedIn && !empty($input['affiliation']) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['affiliation'])))) : '';
            $permission['affiliation'] = $affiliation;
        }

        $entitlement = $permission['entitlement'];
        if ($ssoPluginIsAvailableAndActive) {
            $entitlement = $ssoLoggedIn && !empty($input['entitlement']) ? array_unique(array_map('trim', explode(PHP_EOL, sanitize_textarea_field($input['entitlement'])))) : '';
            $permission['entitlement'] = $entitlement;
        }

        if ($this->settingsErrors()) {
            $this->addSettingsError('logged_in', $logged_in, '', false);
            $this->addSettingsError('sso_logged_in', $ssoLoggedIn, '', false);
            $this->addSettingsError('affiliation', $affiliation, '', false);
            $this->addSettingsError('entitlement', $entitlement, '', false);
            $this->addSettingsError('domain', $domain, '', false);
            $this->addSettingsError('ip_address', $ipAddress, '', false);
            $this->addSettingsError('password', $password, '', false);
            $this->addSettingsError('siteimprove', $siteimprove, '', false);
            $this->addSettingsError('description', $description, '', false);
            return false;
        }

        if (!$permission['core']) {
            $permission['logged_in'] = $logged_in;
            if ($ssoPluginIsAvailableAndActive) {
                $permission['sso_logged_in'] = $ssoLoggedIn;
            }
        }

        $this->options['permissions'][$permissionKey] = $permission;
        return update_option($this->optionName, $this->options);
    }

    protected function getValidDomains($domain)
    {
        $domainAry = [];
        if (!empty($domain)) {
            foreach ((array) $domain as $key => $value) {
                $value = trim($value);
                if (empty($value)) {
                    continue;
                }
                $domainAry[] = $value;
                if (filter_var($value, FILTER_VALIDATE_DOMAIN) === false) {
                    $this->addSettingsError(
                        'domain-' . $key,
                        $domain,
                        sprintf(
                            /* translators: %s: domain name */
                            __('The domain %s is not valid.', 'rrze-ac'),
                            $value
                        )
                    );
                }
            }
        }
        return $domainAry;
    }

    protected function getIpRange($ipAddress)
    {
        $ipRange = [];
        if (!empty($ipAddress)) {
            foreach ($ipAddress as $key => $value) {
                $value = trim($value);
                if (empty($value)) {
                    continue;
                }
                $sanitized_value = IPUtils::sanitizeIpRange($value);
                if (!is_null($sanitized_value)) {
                    $ipRange[] = $sanitized_value;
                } else {
                    $ipRange[] = $value;
                    $this->addSettingsError(
                        'ip_address-' . $key,
                        $ipAddress,
                        sprintf(
                            /* translators: %s: IP address */
                            __('The IP address %s is not valid.', 'rrze-ac'),
                            $value
                        )
                    );
                }
            }
        }
        return $ipRange;
    }

    public function requestVar($param, $default = '')
    {
        if (isset($_POST[$param])) {
            return $_POST[$param];
        }

        if (isset($_GET[$param])) {
            return $_GET[$param];
        }

        return $default;
    }

    private function setNewPage()
    {
    ?>
        <h2><?php echo esc_html(__("Add New Permission", 'rrze-ac')); ?></h2>
        <form action="<?php echo Utils::actionUrl(array('action' => 'new')); ?>" method="post">
            <?php
            settings_fields('rrze-ac-new');
            do_settings_sections('rrze-ac-new');
            submit_button(__("Add New Permission", 'rrze-ac')); ?>
        </form>
    <?php
    }

    private function setEditPage()
    {
    ?>
        <h2><?php echo esc_html(__("Edit permission", 'rrze-ac')); ?></h2>
        <form action="<?php echo Utils::actionUrl(array('action' => 'edit')) ?>" method="post">
            <?php
            settings_fields('rrze-ac-edit');
            do_settings_sections('rrze-ac-edit');
            submit_button(__("Save Changes", 'rrze-ac')); ?>
        </form>
    <?php
    }

    private function setDefaultPage()
    {
    ?>
        <form method="get">
            <input type="hidden" name="page" value="rrze-ac">
            <?php
            $this->listTable->search_box(__("Search", 'rrze-ac'), 'search_id'); ?>
        </form>
        <form method="post">
            <?php
            $this->listTable->views();
            $this->listTable->display(); ?>
        </form>
    <?php
    }

    public function accessSettingsPage()
    {
    ?>
        <div class="wrap">
            <h2>
                <?php echo esc_html(__("Settings", 'rrze-ac')); ?>
            </h2>
            <?php $this->settingsPage(); ?>
        </div>
    <?php
        $this->deleteSettingsErrors();
    }

    public function settingsPage()
    {
    ?>
        <form method="post">
            <?php
            settings_fields('rrze-ac-settings');
            do_settings_sections('rrze-ac-settings');
            submit_button(); ?>
        </form>
    <?php
    }

    public function adminSettings()
    {
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $ssoLoggedIn = !empty($permission['sso_logged_in']) ? true : false;
        $ssoPluginIsAvailableAndActive = permissions()->ssoPluginIsAvailableAndActive();

        add_settings_section('rrze-ac-new-section', false, '__return_false', 'rrze-ac-new');
        add_settings_field('permission_key', __("Permission", 'rrze-ac'), array($this, 'permissionKeyField'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permissionLoggedInField'), 'rrze-ac-new', 'rrze-ac-new-section');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permissionSSOLoggedInField'), 'rrze-ac-new', 'rrze-ac-new-section');
        }
        add_settings_field('domain', __("Allow domain", 'rrze-ac'), array($this, 'permission_domain_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('password', __("Password", 'rrze-ac'), array($this, 'permission_password_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('siteimprove', __("Siteimprove", 'rrze-ac'), array($this, 'permission_siteimprove_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-new', 'rrze-ac-new-section');

        add_settings_section('rrze-ac-edit-section', false, '__return_false', 'rrze-ac-edit');
        add_settings_field('permission_key', __("Permission", 'rrze-ac'), array($this, 'permissionKeyField'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permissionLoggedInField'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permissionSSOLoggedInField'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        }
        if ($ssoPluginIsAvailableAndActive && $ssoLoggedIn) {
            add_settings_field('affiliation', '&#8212; ' . __("Person affiliation", 'rrze-ac'), array($this, 'permission_affiliation_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
            add_settings_field('entitlement', '&#8212; ' . __("Person entitlement", 'rrze-ac'), array($this, 'permission_entitlement_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        }
        add_settings_field('domain', __("Allow domain", 'rrze-ac'), array($this, 'permission_domain_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('password', __("Password", 'rrze-ac'), array($this, 'permission_password_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('siteimprove', __("Siteimprove", 'rrze-ac'), array($this, 'permission_siteimprove_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');

        add_settings_section('rrze-ac-settings-section', false, '__return_false', 'rrze-ac-settings');
        add_settings_field('default_permission', __("Standard Permission", 'rrze-ac'), array($this, 'default_permission_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('automatic_sso_authentication', __("Automatic SSO Authentication", 'rrze-ac'), array($this, 'automatic_sso_authentication_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');
        }
        add_settings_field('contact_admin_name', __("Contact", 'rrze-ac'), [$this, 'contact_admin_name_field'], 'rrze-ac-settings', 'rrze-ac-settings-section');

        add_settings_section('rrze-ac-settings-msg-section', false, [$this, 'settingsMsgSection'], 'rrze-ac-settings');
        add_settings_field('user_isnt_logged_in_title', __("User Is Not Logged In (Title)", 'rrze-ac'), [$this, 'user_isnt_logged_in_title_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        add_settings_field('user_isnt_logged_in_msg', __("User Is Not Logged In (Message)", 'rrze-ac'), [$this, 'user_isnt_logged_in_msg_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        add_settings_field('user_isnt_logged_in_link_txt', __("User Is Not Logged In (Link Text)", 'rrze-ac'), [$this, 'user_isnt_logged_in_link_txt_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('user_isnt_sso_logged_in_title', __("User Is Not SSO Logged In (Title)", 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_title_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
            add_settings_field('user_isnt_sso_logged_in_msg', __("User Is Not SSO Logged In (Message)", 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_msg_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
            add_settings_field('user_isnt_sso_logged_in_link_txt', __("User Is Not SSO Logged In (Link Text)", 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_link_txt_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        }
        add_settings_field('access_denied_default_title', __("Access Denied Default (Title)", 'rrze-ac'), [$this, 'access_denied_default_title_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        add_settings_field('access_denied_default_msg', __("Access Denied Default (Message)", 'rrze-ac'), [$this, 'access_denied_default_msg_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
        add_settings_field('access_denied_password_msg', __("Access Denied Password (Message)", 'rrze-ac'), [$this, 'access_denied_password_msg_field'], 'rrze-ac-settings', 'rrze-ac-settings-msg-section');
    }

    public function settingsMsgSection()
    {
        echo '<h2>', __('Messages', 'rrze-ac'), '</h2>';
    }

    public function permissionKeyField()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $readonly = $permissionKey ? ' readonly="readonly"' : '';
        $permissionKey = isset($settingsErrors['permission_key']['value']) && !$readonly ? $settingsErrors['permission_key']['value'] : $permissionKey;
        $field_invalid = !empty($settingsErrors['permission_key']['error']) ? 'field-invalid' : ''; ?>
        <input type="hidden" value="<?php echo !empty($permission['active']) ? 1 : 0; ?>" name="<?php printf('%s[active]', $this->optionName); ?>">
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo strtoupper($permissionKey); ?>" name="<?php printf('%s[permission_key]', $this->optionName); ?>" <?php echo $readonly; ?>>
    <?php
    }

    public function permissionLoggedInField()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $checked = !empty($permission['logged_in']) ? true : false;
        $checked = !empty($settingsErrors['logged_in']['value']) ? true : $checked; ?>
        <label for="permission_logged_in">
            <input id="permission_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[logged_in]', $this->optionName); ?>" value="1"> <?php _e("The user must be logged-in and a member of the website.", 'rrze-ac'); ?>
        </label>
    <?php
    }

    public function permissionSSOLoggedInField()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $checked = !empty($permission['sso_logged_in']) ? true : false;
        $checked = !empty($settingsErrors['sso_logged_in']['value']) ? true : $checked; ?>
        <label for="permission_sso_logged_in">
            <input id="permission_sso_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[sso_logged_in]', $this->optionName); ?>" value="1"> <?php _e("The user must be SSO logged-in.", 'rrze-ac'); ?>
        </label>
    <?php
    }

    public function permission_affiliation_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $affiliation = !empty($permission['affiliation']) ? implode(PHP_EOL, (array) $permission['affiliation']) : '';
        $affiliation = isset($settingsErrors['affiliation']['value']) ? implode(PHP_EOL, (array) $settingsErrors['affiliation']['value']) : $affiliation; ?>
        <textarea id="affiliation" cols="50" rows="3" name="<?php printf('%s[affiliation]', $this->optionName); ?>"><?php echo $affiliation; ?></textarea>
        <p class="description"><?php _e('Enter one person affiliation per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_entitlement_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $entitlement = !empty($permission['entitlement']) ? implode(PHP_EOL, (array) $permission['entitlement']) : '';
        $entitlement = isset($settingsErrors['entitlement']['value']) ? implode(PHP_EOL, (array) $settingsErrors['entitlement']['value']) : $entitlement; ?>
        <textarea id="entitlement" cols="50" rows="3" name="<?php printf('%s[entitlement]', $this->optionName); ?>"><?php echo $entitlement; ?></textarea>
        <p class="description"><?php _e('Enter one person entitlement per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_select_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $select = isset($permission['select']) ? sanitize_text_field($permission['select']) : '';
        $select = isset($settingsErrors['select']['value']) ? sanitize_text_field($settingsErrors['select']['value']) : $select;
        $field_invalid = !empty($settingsErrors['select']['error']) ? 'field-invalid' : ''; ?>
        <input class="regular-text <?php echo $field_invalid; ?>" type="text" value="<?php echo $select; ?>" name="<?php printf('%s[select]', $this->optionName); ?>">
    <?php
    }

    public function permission_description_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $description = isset($permission['description']) ? esc_textarea($permission['description']) : '';
        $description = isset($settingsErrors['description']['value']) ? esc_textarea($settingsErrors['description']['value']) : $description; ?>
        <textarea id="description" cols="50" rows="3" name="<?php printf('%s[description]', $this->optionName); ?>"><?php echo $description; ?></textarea>
    <?php
    }

    public function default_permission_field()
    {
        $default_permission = permissions()->getDefaultPermission();
        $permissions = permissions()->getThePermissions(); ?>
        <select id="access-permission-select" name="<?php printf('%s[default_permission]', $this->optionName); ?>">
            <?php foreach ($permissions as $key => $data) : ?>
                <?php if (!$data['active']) {
                    continue;
                } ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($default_permission, $key); ?>>
                    <?php echo sanitize_text_field($data['select']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php
    }

    public function automatic_sso_authentication_field()
    {
        $sso_auth_process = $this->options['automatic_sso_authentication'];
        $checked = !empty($sso_auth_process) ? true : false; ?>
        <label for="automatic_sso_authentication">
            <input id="automatic_sso_authentication" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[automatic_sso_authentication]', $this->optionName); ?>" value="1"> <?php _e("Enable automatic SSO authentication process", 'rrze-ac'); ?>
        </label>
    <?php
    }

    public function contact_admin_name_field()
    {
        $admin_contact = $this->options['contact_admin_name']; ?>
        <input type="text" id="contact_admin_name" class="regular-text" name="<?php printf('%s[contact_admin_name]', $this->optionName); ?>" value="<?php echo $admin_contact; ?>">
        <p class="description">
            <?php printf(
                '%s: <strong><i>%s</strong><br>%s<br>%s',
                __('The name of the contact that corresponds to the website administration email address', 'rrze-ac'),
                get_option('admin_email'),
                __('The contact will be displayed in all access denied messages.', 'rrze-ac'),
                __('If this field is left empty, all users with the administrator role will be listed as contacts in all access denied messages.', 'rrze-ac')
            ); ?>
        </p>
    <?php
    }

    public function user_isnt_logged_in_title_field()
    {
        $title = $this->options['user_isnt_logged_in_title']; ?>
        <input type="text" id="user_isnt_logged_in_title" class="regular-text" name="<?php printf('%s[user_isnt_logged_in_title]', $this->optionName); ?>" value="<?php echo $title; ?>">
    <?php
    }

    public function user_isnt_logged_in_msg_field()
    {
        $msg = $this->options['user_isnt_logged_in_msg']; ?>
        <textarea id="user_isnt_logged_in_msg" cols="50" rows="3" name="<?php printf('%s[user_isnt_logged_in_msg]', $this->optionName); ?>"><?php echo $msg; ?></textarea>
    <?php
    }

    public function user_isnt_logged_in_link_txt_field()
    {
        $link_txt = $this->options['user_isnt_logged_in_link_txt']; ?>
        <input type="text" id="user_isnt_logged_in_link_txt" class="regular-text" name="<?php printf('%s[user_isnt_logged_in_link_txt]', $this->optionName); ?>" value="<?php echo $link_txt; ?>">
    <?php
    }

    public function user_isnt_sso_logged_in_title_field()
    {
        $title = $this->options['user_isnt_sso_logged_in_title']; ?>
        <input type="text" id="user_isnt_sso_logged_in_title" class="regular-text" name="<?php printf('%s[user_isnt_sso_logged_in_title]', $this->optionName); ?>" value="<?php echo $title; ?>">
    <?php
    }

    public function user_isnt_sso_logged_in_msg_field()
    {
        $msg = $this->options['user_isnt_sso_logged_in_msg']; ?>
        <textarea id="user_isnt_sso_logged_in_msg" cols="50" rows="3" name="<?php printf('%s[user_isnt_sso_logged_in_msg]', $this->optionName); ?>"><?php echo $msg; ?></textarea>
    <?php
    }

    public function user_isnt_sso_logged_in_link_txt_field()
    {
        $link_txt = $this->options['user_isnt_sso_logged_in_link_txt']; ?>
        <input type="text" id="user_isnt_sso_logged_in_link_txt" class="regular-text" name="<?php printf('%s[user_isnt_sso_logged_in_link_txt]', $this->optionName); ?>" value="<?php echo $link_txt; ?>">
    <?php
    }

    public function access_denied_default_title_field()
    {
        $title = $this->options['access_denied_default_title']; ?>
        <input type="text" id="access_denied_default_title" class="regular-text" name="<?php printf('%s[access_denied_default_title]', $this->optionName); ?>" value="<?php echo $title; ?>">
    <?php
    }

    public function access_denied_default_msg_field()
    {
        $msg = $this->options['access_denied_default_msg']; ?>
        <textarea id="access_denied_default_msg" cols="50" rows="5" name="<?php printf('%s[access_denied_default_msg]', $this->optionName); ?>"><?php echo $msg; ?></textarea>
    <?php
    }

    public function access_denied_password_msg_field()
    {
        $msg = $this->options['access_denied_password_msg']; ?>
        <textarea id="access_denied_password_msg" cols="50" rows="3" name="<?php printf('%s[access_denied_password_msg]', $this->optionName); ?>"><?php echo $msg; ?></textarea>
    <?php
    }

    public function permission_domain_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $domain = !empty($permission['domain']) ? implode(PHP_EOL, (array) $permission['domain']) : '';
        $domain = isset($settingsErrors['domain']['value']) ? implode(PHP_EOL, (array) $settingsErrors['domain']['value']) : $domain; ?>
        <textarea id="domain" cols="50" rows="3" name="<?php printf('%s[domain]', $this->optionName); ?>"><?php echo $domain; ?></textarea>
        <p class="description"><?php _e('Enter one domain per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_ip_address_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $ipAddress = !empty($permission['ip_address']) ? implode(PHP_EOL, (array) $permission['ip_address']) : '';
        $ipAddress = isset($settingsErrors['ip_address']['value']) ? implode(PHP_EOL, (array) $settingsErrors['ip_address']['value']) : $ipAddress; ?>
        <textarea id="ip_address" cols="50" rows="3" name="<?php printf('%s[ip_address]', $this->optionName); ?>"><?php echo $ipAddress; ?></textarea>
        <p class="description"><?php _e('Enter one IP address per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_password_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $password = !empty($permission['password']) ? esc_html($permission['password']) : '';
        $password = isset($settingsErrors['password']['value']) ? esc_html($settingsErrors['password']['value']) : $password; ?>
        <input type="text" id="password" class="regular-text" name="<?php printf('%s[password]', $this->optionName); ?>" value="<?php echo $password; ?>">
        <p class="description"><?php _e('Allows access using a password (alphanumeric value between 8 and 32 characters).', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_siteimprove_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $checked = !empty($permission['siteimprove']) ? true : false;
        $checked = !empty($settingsErrors['siteimprove']['value']) ? true : $checked; ?>
        <label for="permission_siteimprove">
            <input id="permission_siteimprove" type="checkbox" <?php checked($checked); ?> name="<?php printf('%s[siteimprove]', $this->optionName); ?>" value="1"> <?php _e("Allow Siteimprove crawler", 'rrze-ac'); ?>
        </label>
        <?php
    }

    public function adminActions()
    {
        $page = $this->requestVar('page');
        $action = $this->requestVar('action');
        $permissionKey = $this->requestVar('permission');
        $nonce = $this->requestVar('nonce');

        $permission = permissions()->getPermission($permissionKey);

        if ($page == 'rrze-ac' && !empty($permission)) {
            switch ($action) {
                case 'activate':
                    if (!wp_verify_nonce($nonce, 'activate')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_activate($permission)) {
                        $this->addAdminNotice(__("The permission has been enabled.", 'rrze-ac'));
                        wp_redirect(Utils::actionUrl());
                        exit();
                    }
                    break;
                case 'deactivate':
                    if (!wp_verify_nonce($nonce, 'deactivate')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_activate($permission, 0)) {
                        $this->addAdminNotice(__("The permission has been disabled.", 'rrze-ac'));
                        wp_redirect(Utils::actionUrl());
                        exit();
                    }
                    break;
                case 'delete':
                    if (!wp_verify_nonce($nonce, 'delete')) {
                        wp_die(__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_delete($permission)) {
                        $this->addAdminNotice(__("The permission has been deleted.", 'rrze-ac'));
                        wp_redirect(Utils::actionUrl());
                        exit();
                    }
                    break;
                default:
                    break;
            }
        }
    }

    public function action_activate($permission, $activate = 1)
    {
        if (!isset($permission['permission_key']) || !$activate && $permission['permission_key'] == permissions()->getDefaultPermission()) {
            return false;
        }
        $permissionKey = $permission['permission_key'];
        $this->options['permissions'][$permissionKey]['active'] = $activate;
        return update_option($this->optionName, $this->options);
    }

    public function action_delete($permission)
    {
        if (
            !isset($permission['permission_key'])
            || $permission['core']
            || $permission['permission_key'] == permissions()->getDefaultPermission()
            || !empty(Post::countMetaKeys($permission['permission_key']))
        ) {
            return false;
        }
        $permissionKey = $permission['permission_key'];
        unset($this->options['permissions'][$permissionKey]);
        return update_option($this->optionName, $this->options);
    }

    private function validate_settings($input)
    {
        if (
            isset($input['default_permission'])
            && isset($this->options['permissions'][$input['default_permission']])
            && $this->options['permissions'][$input['default_permission']]['active']
        ) {
            $this->options['default_permission'] = $input['default_permission'];
        }

        if (permissions()->ssoPluginIsAvailableAndActive()) {
            $this->options['automatic_sso_authentication'] = isset($input['automatic_sso_authentication']) ? 1 : 0;
        }

        $this->options['contact_admin_name'] = esc_html(sanitize_text_field($input['contact_admin_name']));

        $title = esc_html(sanitize_text_field($input['user_isnt_logged_in_title']));
        $this->options['user_isnt_logged_in_title'] = $title ?: $this->options['user_isnt_logged_in_title'];
        $msg = esc_html(sanitize_textarea_field($input['user_isnt_logged_in_msg']));
        $this->options['user_isnt_logged_in_msg'] = $msg ?: $this->options['user_isnt_logged_in_msg'];
        $link_txt = esc_html(sanitize_textarea_field($input['user_isnt_logged_in_link_txt']));
        $this->options['user_isnt_logged_in_link_txt'] = $link_txt ?: $this->options['user_isnt_logged_in_link_txt'];

        $title = esc_html(sanitize_text_field($input['user_isnt_sso_logged_in_title']));
        $this->options['user_isnt_sso_logged_in_title'] = $title ?: $this->options['user_isnt_sso_logged_in_title'];
        $msg = esc_html(sanitize_textarea_field($input['user_isnt_sso_logged_in_msg']));
        $this->options['user_isnt_sso_logged_in_msg'] = $msg ?: $this->options['user_isnt_sso_logged_in_msg'];
        $link_txt = esc_html(sanitize_textarea_field($input['user_isnt_sso_logged_in_link_txt']));
        $this->options['user_isnt_sso_logged_in_link_txt'] = $link_txt ?: $this->options['user_isnt_sso_logged_in_link_txt'];

        $title = esc_html(sanitize_text_field($input['access_denied_default_title']));
        $this->options['access_denied_default_title'] = $title ?: $this->options['access_denied_default_title'];
        $msg = esc_html(sanitize_textarea_field($input['access_denied_default_msg']));
        $this->options['access_denied_default_msg'] = $msg ?: $this->options['access_denied_default_msg'];

        $msg = esc_html(sanitize_textarea_field($input['access_denied_password_msg']));
        $this->options['access_denied_password_msg'] = $msg ?: $this->options['access_denied_password_msg'];

        return update_option($this->optionName, $this->options);
    }

    public function adminNotices()
    {
        $this->displayAdminNotices();
    }

    public function addAdminNotice($message, $class = 'updated')
    {
        $allowed_classes = array('error', 'updated');
        if (!in_array($class, $allowed_classes)) {
            $class = 'updated';
        }

        $transient = $this->notice_transient . get_current_user_id();
        $transientValue = get_transient($transient);
        $notices = maybe_unserialize($transientValue ? $transientValue : []);
        $notices[$class][] = $message;

        set_transient($transient, $notices, $this->notice_transient_expiration);
    }

    public function displayAdminNotices()
    {
        $transient = $this->notice_transient . get_current_user_id();
        $transientValue = get_transient($transient);
        $notices = maybe_unserialize($transientValue ? $transientValue : '');

        if (is_array($notices)) {
            foreach ($notices as $class => $messages) {
                foreach ($messages as $message) :
        ?>
                    <div class="<?php echo $class; ?>">
                        <p><?php echo $message; ?></p>
                    </div>
<?php
                endforeach;
            }
        }

        delete_transient($transient);
    }

    public function addSettingsError($field, $value = '', $message = '', $error = true)
    {
        $transient = $this->settingsErrorTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $errors = maybe_unserialize($transientValue ? $transientValue : []);
        $errors[$field] = array('value' => $value, 'message' => $message, 'error' => $error);

        set_transient($transient, $errors, $this->settingsErrorTransientExpiration);
    }

    public function settingsErrors()
    {
        $transient = $this->settingsErrorTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $errors = (array) maybe_unserialize($transientValue ? $transientValue : '');

        foreach ($errors as $error) {
            if (!empty($error['error'])) {
                return $errors;
            }
        }

        return false;
    }

    public function deleteSettingsErrors()
    {
        delete_transient($this->settingsErrorTransient . get_current_user_id());
    }
}
