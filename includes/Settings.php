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

        add_action('admin_menu', array($this, 'sub_page_menu'));

        add_action('admin_init', array($this, 'adminActions'));
        add_action('admin_init', array($this, 'adminSettings'));
    }

    public function sub_page_menu()
    {
        $this->validateActions();

        $accessPage = add_submenu_page(
            'options-general.php',
            __("Access Control", 'rrze-ac'),
            __("Access Control", 'rrze-ac'),
            'manage_options',
            'rrze-ac',
            array($this, 'accessControlPage'),
            90
        );
        add_action("load-{$accessPage}", array($this, 'loadAccessPage'));
        add_action("load-{$accessPage}", array($this, 'accessScreenOptions'));
    }

    public function loadAccessPage()
    {
        if ($this->currentTab() != 'permissions') {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        $this->listTable = new ListTable($this->main);
        $this->listTable->prepare_items();
    }

    public function accessScreenOptions()
    {
        if ($this->currentTab() != 'permissions') {
            return;
        }

        $option = 'per_page';
        $args = array(
            'label' => __("Items per page:", 'rrze-ac'),
            'default' => 20,
            'option' => 'rrzeacs_per_page'
        );

        add_screen_option($option, $args);
    }

    public function accessControlPage()
    {
    ?>
        <div class="wrap rrze-ac">
            <h1><?php echo esc_html(__("Access Control", 'rrze-ac')); ?></h1>
            <?php
            $this->renderTabs();
            switch ($this->currentTab()) {
                case 'permissions':
                    $this->permissionsPage();
                    break;
                case 'advanced':
                    $this->settingsPage('rrze-ac-advanced');
                    break;
                default:
                    $this->settingsPage();
                    break;
            } ?>
        </div>
    <?php
        $this->deleteSettingsErrors();
    }

    public function access_permissions_page()
    {
    ?>
        <div class="wrap rrze-ac">
            <h1><?php echo esc_html(__("Access Control", 'rrze-ac')); ?></h1>
            <?php
            $this->renderTabs('permissions');
            $this->permissionsPage(); ?>
        </div>
    <?php
        $this->deleteSettingsErrors();
    }

    private function permissionsPage()
    {
        $action = $this->requestVar('action');
        $option_page = $this->requestVar('option_page'); ?>
        <h2>
            <?php echo esc_html(__("Permissions", 'rrze-ac')); ?>
            <?php if (empty($action)) : ?>
                <a href="<?php echo esc_url(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'new'))); ?>" class="add-new-h2"><?php esc_html_e("Add New Permission", 'rrze-ac'); ?></a>
            <?php endif; ?>
        </h2>
        <?php
        if ($action == 'new' || $option_page == 'rrze-ac-new') {
            $this->setNewPage();
        } elseif ($action == 'edit' || $option_page == 'rrze-ac-edit') {
            $this->setEditPage();
        } else {
            $this->setDefaultPage();
        }
    }

    private function currentTab()
    {
        $tab = $this->requestVar('tab', 'general');
        $action = $this->requestVar('action');
        $option_page = $this->requestVar('option_page');

        if ($action == 'new' || $action == 'edit' || $option_page == 'rrze-ac-new' || $option_page == 'rrze-ac-edit') {
            return 'permissions';
        }

        if ($tab == 'settings') {
            return 'general';
        }

        if (in_array($tab, array('general', 'permissions', 'advanced'))) {
            return $tab;
        }

        return 'general';
    }

    private function renderTabs($activeTab = '')
    {
        $activeTab = $activeTab ? $activeTab : $this->currentTab();
        $tabs = array(
            'general' => __("General", 'rrze-ac'),
            'permissions' => __("Permissions", 'rrze-ac'),
            'advanced' => __("Advanced", 'rrze-ac')
        ); ?>
        <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr(__("Access Control", 'rrze-ac')); ?>">
            <?php foreach ($tabs as $tab => $label) : ?>
                <a href="<?php echo esc_url(Utils::actionUrl(array('tab' => $tab))); ?>" class="nav-tab <?php echo $activeTab == $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php
    }

    private function validateActions()
    {
        $action = $this->requestVar('action');
        $option_page = $this->requestVar('option_page');

        if ($option_page == 'rrze-ac-new') {
            $this->validateNewAction();
        } elseif ($option_page == 'rrze-ac-edit') {
            $this->validateEditAction();
        } elseif (in_array($option_page, array('rrze-ac-settings', 'rrze-ac-advanced'))) {
            $this->validateSettingsAction($option_page);
        }
    }

    private function validateNewAction()
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-ac-new-options')) {
            wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
        }

        $permissionKey = $this->validateNew($input);
        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'new')));
            exit();
        }

        $this->addAdminNotice(__("The permission has been added.", 'rrze-ac'));
        wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'edit', 'permission' => $permissionKey)));
        exit();
    }

    private function validateEditAction()
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-ac-edit-options')) {
            wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
        }

        if (!isset($input['permission_key'])) {
            wp_die(esc_html__("Permission does not exist.", 'rrze-ac'));
        }

        $permissionKey = $input['permission_key'];
        $permission = permissions()->getPermission($permissionKey);
        if (!$permission) {
            wp_die(esc_html__("Permission does not exist.", 'rrze-ac'));
        }

        $validation = $this->validateEdit($permission, $input);

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'edit', 'permission' => $permissionKey)));
            exit();
        }

        if ($validation) {
            $this->addAdminNotice(__("The permission has been updated.", 'rrze-ac'));
        }

        wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'edit', 'permission' => $permissionKey)));
        exit();
    }

    private function validateSettingsAction($optionPage = 'rrze-ac-settings')
    {
        $input = (array) $this->requestVar($this->optionName);
        $nonce = $this->requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, $optionPage . '-options')) {
            wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
        }

        $validation = $this->validate_settings($input, $optionPage);
        $tab = $this->tabFromOptionPage($optionPage);

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_safe_redirect(Utils::actionUrl(array('tab' => $tab)));
            exit();
        }

        if ($validation) {
            $this->addAdminNotice(__("The settings have been updated.", 'rrze-ac'));
        }

        wp_safe_redirect(Utils::actionUrl(array('tab' => $tab)));
        exit();
    }

    private function tabFromOptionPage($optionPage)
    {
        switch ($optionPage) {
            case 'rrze-ac-advanced':
                return 'advanced';
            default:
                return 'general';
        }
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

        $ssoPluginIsAvailableAndActive = permissions()->ssoPluginIsAvailableAndActive();
        $logged_in = !$ssoPluginIsAvailableAndActive && !empty($input['logged_in']) ? 1 : 0;
        $ssoLoggedIn = $ssoPluginIsAvailableAndActive && !empty($input['sso_logged_in']) ? 1 : 0;

        $crawlers = $this->crawlerKeysFromInput($input);

        if ($this->settingsErrors()) {
            $this->addSettingsError('logged_in', $logged_in, '', false);
            $this->addSettingsError('sso_logged_in', $ssoLoggedIn, '', false);
            $this->addSettingsError('domain', $domain, '', false);
            $this->addSettingsError('ip_address', $ipAddress, '', false);
            $this->addSettingsError('password', $password, '', false);
            $this->addSettingsError('crawlers', $crawlers, '', false);
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
            'crawlers' => $crawlers,
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

        $ssoPluginIsAvailableAndActive = permissions()->ssoPluginIsAvailableAndActive();
        $logged_in = !$ssoPluginIsAvailableAndActive && !empty($input['logged_in']) ? 1 : 0;
        $ssoLoggedIn = $ssoPluginIsAvailableAndActive ? (!empty($input['sso_logged_in']) ? 1 : 0) : $permission['sso_logged_in'];

        $crawlers = $this->crawlerKeysFromInput($input);
        $permission['crawlers'] = $crawlers;

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
            $this->addSettingsError('crawlers', $crawlers, '', false);
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
        <form action="<?php echo esc_url(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'new'))); ?>" method="post">
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
        <form action="<?php echo esc_url(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'edit'))); ?>" method="post">
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
        <div class="wrap rrze-ac">
            <h1><?php echo esc_html(__("Access Control", 'rrze-ac')); ?></h1>
            <?php $this->renderTabs('general'); ?>
            <?php $this->settingsPage(); ?>
        </div>
    <?php
        $this->deleteSettingsErrors();
    }

    public function settingsPage($settingsPage = 'rrze-ac-settings')
    {
    ?>
        <form method="post">
            <?php
            settings_fields($settingsPage);
            do_settings_sections($settingsPage);
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
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-new', 'rrze-ac-new-section');
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-new', 'rrze-ac-new-section');

        add_settings_section('rrze-ac-new-access-section', __("Access Conditions", 'rrze-ac'), [$this, 'accessConditionsSection'], 'rrze-ac-new');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permissionSSOLoggedInField'), 'rrze-ac-new', 'rrze-ac-new-access-section');
        } else {
            add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permissionLoggedInField'), 'rrze-ac-new', 'rrze-ac-new-access-section');
        }
        add_settings_field('domain', __("Allow hostname", 'rrze-ac'), array($this, 'permission_domain_field'), 'rrze-ac-new', 'rrze-ac-new-access-section');
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-new', 'rrze-ac-new-access-section');
        add_settings_field('password', __("Password", 'rrze-ac'), array($this, 'permission_password_field'), 'rrze-ac-new', 'rrze-ac-new-access-section');

        add_settings_section('rrze-ac-new-crawler-section', __("Crawler and Monitoring Systems", 'rrze-ac'), [$this, 'crawlerAndMonitoringSection'], 'rrze-ac-new');
        $this->addCrawlerSettingsFields('rrze-ac-new', 'rrze-ac-new-crawler-section');

        add_settings_section('rrze-ac-edit-section', false, '__return_false', 'rrze-ac-edit');
        add_settings_field('permission_key', __("Permission", 'rrze-ac'), array($this, 'permissionKeyField'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('select', __("Short Description", 'rrze-ac'), array($this, 'permission_select_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');
        add_settings_field('description', __("Description", 'rrze-ac'), array($this, 'permission_description_field'), 'rrze-ac-edit', 'rrze-ac-edit-section');

        add_settings_section('rrze-ac-edit-access-section', __("Access Conditions", 'rrze-ac'), [$this, 'accessConditionsSection'], 'rrze-ac-edit');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('sso_logged_in', __('SSO', 'rrze-ac'), array($this, 'permissionSSOLoggedInField'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
        } else {
            add_settings_field('logged_in', __("Logged-in", 'rrze-ac'), array($this, 'permissionLoggedInField'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
        }
        if ($ssoPluginIsAvailableAndActive && $ssoLoggedIn) {
            add_settings_field('affiliation', '&#8212; ' . __("Person affiliation", 'rrze-ac'), array($this, 'permission_affiliation_field'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
            add_settings_field('entitlement', '&#8212; ' . __("Person entitlement", 'rrze-ac'), array($this, 'permission_entitlement_field'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
        }
        add_settings_field('domain', __("Allow hostname", 'rrze-ac'), array($this, 'permission_domain_field'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
        add_settings_field('ip_address', __("Allow IP address", 'rrze-ac'), array($this, 'permission_ip_address_field'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');
        add_settings_field('password', __("Password", 'rrze-ac'), array($this, 'permission_password_field'), 'rrze-ac-edit', 'rrze-ac-edit-access-section');

        add_settings_section('rrze-ac-edit-crawler-section', __("Crawler and Monitoring Systems", 'rrze-ac'), [$this, 'crawlerAndMonitoringSection'], 'rrze-ac-edit');
        $this->addCrawlerSettingsFields('rrze-ac-edit', 'rrze-ac-edit-crawler-section');

        add_settings_section('rrze-ac-settings-section', false, '__return_false', 'rrze-ac-settings');
        add_settings_field('default_permission', __("Standard Permission", 'rrze-ac'), array($this, 'default_permission_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');
        add_settings_field('permission_editor_role', __("Minimum Role for Access Control", 'rrze-ac'), array($this, 'permission_editor_role_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');
        if ($ssoPluginIsAvailableAndActive) {
            add_settings_field('automatic_sso_authentication', __("Automatic SSO Authentication", 'rrze-ac'), array($this, 'automatic_sso_authentication_field'), 'rrze-ac-settings', 'rrze-ac-settings-section');
        }
        add_settings_field('contact_admin_name', __("Contact", 'rrze-ac'), [$this, 'contact_admin_name_field'], 'rrze-ac-settings', 'rrze-ac-settings-section');

        add_settings_section('rrze-ac-advanced-default-section', __('General Notice', 'rrze-ac'), '__return_false', 'rrze-ac-advanced');
        add_settings_field('use_default_access_denied_texts', __('Use default texts', 'rrze-ac'), [$this, 'useDefaultAccessDeniedTextsField'], 'rrze-ac-advanced', 'rrze-ac-advanced-default-section');
        add_settings_field('access_denied_default_title', __('Title', 'rrze-ac'), [$this, 'access_denied_default_title_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-default-section');
        add_settings_field('access_denied_default_msg', __('Reason', 'rrze-ac'), [$this, 'access_denied_default_msg_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-default-section');

        if ($ssoPluginIsAvailableAndActive) {
            add_settings_section('rrze-ac-advanced-sso-section', __('Single Sign-On', 'rrze-ac'), '__return_false', 'rrze-ac-advanced');
            add_settings_field('use_default_sso_texts', __('Use default texts', 'rrze-ac'), [$this, 'useDefaultSsoTextsField'], 'rrze-ac-advanced', 'rrze-ac-advanced-sso-section');
            add_settings_field('user_isnt_sso_logged_in_title', __('Title', 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_title_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-sso-section');
            add_settings_field('user_isnt_sso_logged_in_msg', __('Reason', 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_msg_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-sso-section');
            add_settings_field('user_isnt_sso_logged_in_link_txt', __('Call to action', 'rrze-ac'), [$this, 'user_isnt_sso_logged_in_link_txt_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-sso-section');
        } else {
            add_settings_section('rrze-ac-advanced-login-section', __('Login', 'rrze-ac'), '__return_false', 'rrze-ac-advanced');
            add_settings_field('use_default_login_texts', __('Use default texts', 'rrze-ac'), [$this, 'useDefaultLoginTextsField'], 'rrze-ac-advanced', 'rrze-ac-advanced-login-section');
            add_settings_field('user_isnt_logged_in_title', __('Title', 'rrze-ac'), [$this, 'user_isnt_logged_in_title_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-login-section');
            add_settings_field('user_isnt_logged_in_msg', __('Reason', 'rrze-ac'), [$this, 'user_isnt_logged_in_msg_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-login-section');
            add_settings_field('user_isnt_logged_in_link_txt', __('Call to action', 'rrze-ac'), [$this, 'user_isnt_logged_in_link_txt_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-login-section');
        }
        add_settings_section('rrze-ac-advanced-password-section', __('Password', 'rrze-ac'), '__return_false', 'rrze-ac-advanced');
        add_settings_field('use_default_password_texts', __('Use default texts', 'rrze-ac'), [$this, 'useDefaultPasswordTextsField'], 'rrze-ac-advanced', 'rrze-ac-advanced-password-section');
        add_settings_field('access_denied_password_title', __('Title', 'rrze-ac'), [$this, 'access_denied_password_title_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-password-section');
        add_settings_field('access_denied_password_msg', __('Reason', 'rrze-ac'), [$this, 'access_denied_password_msg_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-password-section');
        add_settings_field('access_denied_password_link_txt', __('Call to action', 'rrze-ac'), [$this, 'access_denied_password_link_txt_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-password-section');

        if ($this->canManageDebuggingSettings()) {
            add_settings_section('rrze-ac-advanced-debugging-section', __("Debugging", 'rrze-ac'), '__return_false', 'rrze-ac-advanced');
            add_settings_field('log_info_messages', __("Logging", 'rrze-ac'), [$this, 'log_info_messages_field'], 'rrze-ac-advanced', 'rrze-ac-advanced-debugging-section');
        }
    }

    public function accessConditionsSection()
    {
        echo '<p class="description">';
        esc_html_e('Access is granted when at least one of the following conditions is fulfilled. The conditions are evaluated as OR rules, not as AND rules.', 'rrze-ac');
        echo '</p>';
    }

    public function crawlerAndMonitoringSection()
    {
        echo '<p class="description">';
        esc_html_e('Independent of the access conditions above, known crawlers, bots, and monitoring systems may need access to the websites. These can be allowed through the following selection.', 'rrze-ac');
        echo '</p>';
    }

    public function permissionKeyField()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $readonly = $permissionKey ? ' readonly="readonly"' : '';
        $permissionKey = isset($settingsErrors['permission_key']['value']) && !$readonly ? $settingsErrors['permission_key']['value'] : $permissionKey;
        $field_invalid = !empty($settingsErrors['permission_key']['error']) ? 'field-invalid' : ''; ?>
        <input type="hidden" value="<?php echo esc_attr(!empty($permission['active']) ? 1 : 0); ?>" name="<?php echo esc_attr(sprintf('%s[active]', $this->optionName)); ?>">
        <input class="regular-text <?php echo esc_attr($field_invalid); ?>" type="text" value="<?php echo esc_attr(strtoupper($permissionKey)); ?>" name="<?php echo esc_attr(sprintf('%s[permission_key]', $this->optionName)); ?>"<?php echo esc_attr($readonly); ?>>
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
            <input id="permission_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php echo esc_attr(sprintf('%s[logged_in]', $this->optionName)); ?>" value="1"> <?php esc_html_e("The user must be logged-in and a member of the website.", 'rrze-ac'); ?>
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
            <input id="permission_sso_logged_in" type="checkbox" <?php checked($checked); ?> name="<?php echo esc_attr(sprintf('%s[sso_logged_in]', $this->optionName)); ?>" value="1"> <?php esc_html_e("The user must be SSO logged-in.", 'rrze-ac'); ?>
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
        <textarea id="affiliation" cols="50" rows="3" name="<?php echo esc_attr(sprintf('%s[affiliation]', $this->optionName)); ?>"><?php echo esc_textarea($affiliation); ?></textarea>
        <p class="description"><?php esc_html_e('Enter one person affiliation per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_entitlement_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $entitlement = !empty($permission['entitlement']) ? implode(PHP_EOL, (array) $permission['entitlement']) : '';
        $entitlement = isset($settingsErrors['entitlement']['value']) ? implode(PHP_EOL, (array) $settingsErrors['entitlement']['value']) : $entitlement; ?>
        <textarea id="entitlement" cols="50" rows="3" name="<?php echo esc_attr(sprintf('%s[entitlement]', $this->optionName)); ?>"><?php echo esc_textarea($entitlement); ?></textarea>
        <p class="description"><?php esc_html_e('Enter one person entitlement per line.', 'rrze-ac'); ?></p>
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
        <input class="regular-text <?php echo esc_attr($field_invalid); ?>" type="text" value="<?php echo esc_attr($select); ?>" name="<?php echo esc_attr(sprintf('%s[select]', $this->optionName)); ?>">
    <?php
    }

    public function permission_description_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $description = isset($permission['description']) ? esc_textarea($permission['description']) : '';
        $description = isset($settingsErrors['description']['value']) ? esc_textarea($settingsErrors['description']['value']) : $description; ?>
        <textarea id="description" cols="50" rows="3" name="<?php echo esc_attr(sprintf('%s[description]', $this->optionName)); ?>"><?php echo esc_textarea($description); ?></textarea>
    <?php
    }

    public function default_permission_field()
    {
        $default_permission = permissions()->getDefaultPermission();
        $permissions = permissions()->getThePermissions(); ?>
        <select id="access-permission-select" name="<?php echo esc_attr(sprintf('%s[default_permission]', $this->optionName)); ?>">
            <?php foreach ($permissions as $key => $data) : ?>
                <?php if (!$data['active']) {
                    continue;
                } ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($default_permission, $key); ?>>
                    <?php echo esc_html($this->permissionSelectLabel($key, $data)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('This permission is used as a fallback when a protected page or media file does not have a valid assigned permission, for example when the assigned permission has been deleted or disabled. It does not automatically protect all unprotected content.', 'rrze-ac'); ?>
        </p>
    <?php
    }

    public function permission_editor_role_field()
    {
        $selectedRole = permissions()->getPermissionEditorRole();
        $roles = wp_roles()->roles; ?>
        <select id="permission_editor_role" name="<?php echo esc_attr(sprintf('%s[permission_editor_role]', $this->optionName)); ?>">
            <?php foreach ($roles as $role => $data) : ?>
                <option value="<?php echo esc_attr($role); ?>" <?php selected($selectedRole, $role); ?>>
                    <?php echo esc_html(translate_user_role($data['name'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Users with this role or a role with a higher WordPress level may select and change access restrictions for content they can edit.', 'rrze-ac'); ?>
        </p>
    <?php
    }

    private function permissionSelectLabel($key, $permission)
    {
        if (!empty($permission['core'])) {
            switch ($key) {
                case 'public':
                    return __('Publicly accessible', 'rrze-ac');
                case 'logged-in':
                    return __('Login required', 'rrze-ac');
                default:
                    break;
            }
        }

        return !empty($permission['select']) ? sanitize_text_field($permission['select']) : '';
    }

    public function automatic_sso_authentication_field()
    {
        $sso_auth_process = $this->options['automatic_sso_authentication'];
        $checked = !empty($sso_auth_process) ? true : false; ?>
        <label for="automatic_sso_authentication">
            <input id="automatic_sso_authentication" type="checkbox" <?php checked($checked); ?> name="<?php echo esc_attr(sprintf('%s[automatic_sso_authentication]', $this->optionName)); ?>" value="1"> <?php esc_html_e("Enable automatic SSO authentication process", 'rrze-ac'); ?>
        </label>
    <?php
    }

    public function contact_admin_name_field()
    {
        $admin_contact = $this->options['contact_admin_name']; ?>
        <input type="text" id="contact_admin_name" class="regular-text" name="<?php echo esc_attr(sprintf('%s[contact_admin_name]', $this->optionName)); ?>" value="<?php echo esc_attr($admin_contact); ?>">
        <p class="description">
            <?php printf(
                '%1$s: <strong><i>%2$s</i></strong><br>%3$s<br>%4$s',
                esc_html__('The name of the contact that corresponds to the website administration email address', 'rrze-ac'),
                esc_html(get_option('admin_email')),
                esc_html__('The contact will be displayed in all access denied messages.', 'rrze-ac'),
                esc_html__('If this field is left empty, all users with the administrator role will be listed as contacts in all access denied messages.', 'rrze-ac')
            ); ?>
        </p>
    <?php
    }

    public function log_info_messages_field()
    {
        $checked = !empty($this->options['log_info_messages']) ? true : false; ?>
        <label for="log_info_messages">
            <input id="log_info_messages" type="checkbox" <?php checked($checked); ?> name="<?php echo esc_attr(sprintf('%s[log_info_messages]', $this->optionName)); ?>" value="1"> <?php esc_html_e("Send informational messages to the info channel.", 'rrze-ac'); ?>
        </label>
    <?php
    }

    public function user_isnt_logged_in_title_field()
    {
        $this->messageTextField('user_isnt_logged_in_title', 'login', 'text');
    }

    public function user_isnt_logged_in_msg_field()
    {
        $this->messageTextField('user_isnt_logged_in_msg', 'login', 'textarea', 3);
    }

    public function user_isnt_logged_in_link_txt_field()
    {
        $this->messageTextField('user_isnt_logged_in_link_txt', 'login', 'text');
    }

    public function user_isnt_sso_logged_in_title_field()
    {
        $this->messageTextField('user_isnt_sso_logged_in_title', 'sso', 'text');
    }

    public function user_isnt_sso_logged_in_msg_field()
    {
        $this->messageTextField('user_isnt_sso_logged_in_msg', 'sso', 'textarea', 3);
    }

    public function user_isnt_sso_logged_in_link_txt_field()
    {
        $this->messageTextField('user_isnt_sso_logged_in_link_txt', 'sso', 'text');
    }

    public function access_denied_default_title_field()
    {
        $this->messageTextField('access_denied_default_title', 'access_denied', 'text');
    }

    public function access_denied_default_msg_field()
    {
        $this->messageTextField('access_denied_default_msg', 'access_denied', 'textarea', 5);
    }

    public function access_denied_password_msg_field()
    {
        $this->messageTextField('access_denied_password_msg', 'password', 'textarea', 3);
    }

    public function access_denied_password_title_field()
    {
        $this->messageTextField('access_denied_password_title', 'password', 'text');
    }

    public function access_denied_password_link_txt_field()
    {
        $this->messageTextField('access_denied_password_link_txt', 'password', 'text');
    }

    public function useDefaultAccessDeniedTextsField()
    {
        $this->useDefaultTextsField('access_denied');
    }

    public function useDefaultLoginTextsField()
    {
        $this->useDefaultTextsField('login');
    }

    public function useDefaultSsoTextsField()
    {
        $this->useDefaultTextsField('sso');
    }

    public function useDefaultPasswordTextsField()
    {
        $this->useDefaultTextsField('password');
    }

    private function useDefaultTextsField($group)
    {
        $key = 'use_default_' . $group . '_texts';
        $checked = !empty($this->options[$key]); ?>
        <label class="rrze-ac-pill-switch">
            <input class="rrze-ac-default-text-toggle" type="checkbox" name="<?php echo esc_attr(sprintf('%s[%s]', $this->optionName, $key)); ?>" value="1" data-rrze-ac-text-group="<?php echo esc_attr($group); ?>" <?php checked($checked); ?>>
            <span aria-hidden="true"></span>
            <?php esc_html_e('Use translated default texts', 'rrze-ac'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Custom texts are shown exactly as entered, regardless of the website language. Only the default texts are translated to match the active website language.', 'rrze-ac'); ?>
        </p>
    <?php
    }

    private function messageTextField($key, $group, $type, $rows = 0)
    {
        $useDefaults = !empty($this->options['use_default_' . $group . '_texts']);
        $defaults = Config::get('default_options');
        $value = $useDefaults ? $defaults[$key] : $this->options[$key];
        $readonly = $useDefaults ? ' readonly' : ''; ?>
        <div class="rrze-ac-message-text<?php echo $useDefaults ? ' is-readonly' : ''; ?>" data-rrze-ac-text-group="<?php echo esc_attr($group); ?>">
            <?php if ($type === 'textarea') : ?>
                <textarea id="<?php echo esc_attr($key); ?>" cols="50" rows="<?php echo absint($rows); ?>" name="<?php echo esc_attr(sprintf('%s[%s]', $this->optionName, $key)); ?>"<?php echo esc_attr($readonly); ?>><?php echo esc_textarea($value); ?></textarea>
            <?php else : ?>
                <input type="text" id="<?php echo esc_attr($key); ?>" class="regular-text" name="<?php echo esc_attr(sprintf('%s[%s]', $this->optionName, $key)); ?>" value="<?php echo esc_attr($value); ?>"<?php echo esc_attr($readonly); ?>>
            <?php endif; ?>
        </div>
    <?php
    }

    public function permission_domain_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $domain = !empty($permission['domain']) ? implode(PHP_EOL, (array) $permission['domain']) : '';
        $domain = isset($settingsErrors['domain']['value']) ? implode(PHP_EOL, (array) $settingsErrors['domain']['value']) : $domain; ?>
        <textarea id="domain" cols="50" rows="3" name="<?php echo esc_attr(sprintf('%s[domain]', $this->optionName)); ?>"><?php echo esc_textarea($domain); ?></textarea>
        <p class="description"><?php esc_html_e('Enter one hostname per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_ip_address_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $ipAddress = !empty($permission['ip_address']) ? implode(PHP_EOL, (array) $permission['ip_address']) : '';
        $ipAddress = isset($settingsErrors['ip_address']['value']) ? implode(PHP_EOL, (array) $settingsErrors['ip_address']['value']) : $ipAddress; ?>
        <textarea id="ip_address" cols="50" rows="3" name="<?php echo esc_attr(sprintf('%s[ip_address]', $this->optionName)); ?>"><?php echo esc_textarea($ipAddress); ?></textarea>
        <p class="description"><?php esc_html_e('Enter one IP address per line.', 'rrze-ac'); ?></p>
    <?php
    }

    public function permission_password_field()
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $password = !empty($permission['password']) ? esc_html($permission['password']) : '';
        $password = isset($settingsErrors['password']['value']) ? esc_html($settingsErrors['password']['value']) : $password; ?>
        <input type="text" id="password" class="regular-text" name="<?php echo esc_attr(sprintf('%s[password]', $this->optionName)); ?>" value="<?php echo esc_attr($password); ?>">
        <p class="description"><?php esc_html_e('Allows access using a password (alphanumeric value between 8 and 32 characters).', 'rrze-ac'); ?></p>
    <?php
    }

    private function crawlerKeysFromInput(array $input): array
    {
        $crawlerKeys = isset($input['crawlers']) && is_array($input['crawlers'])
            ? $input['crawlers']
            : [];
        $availableCrawlerKeys = array_keys(permissions()->getCrawlers());
        $crawlerKeys = array_map('sanitize_key', $crawlerKeys);
        $crawlerKeys = array_intersect($crawlerKeys, $availableCrawlerKeys);

        return array_values(array_unique($crawlerKeys));
    }

    private function addCrawlerSettingsFields(string $page, string $section): void
    {
        foreach (permissions()->getCrawlers() as $crawlerKey => $crawler) {
            add_settings_field(
                'crawler-' . $crawlerKey,
                $crawler['title'],
                [$this, 'permissionCrawlerField'],
                $page,
                $section,
                [
                    'crawler_key' => $crawlerKey,
                    'crawler' => $crawler
                ]
            );
        }
    }

    public function permissionCrawlerField(array $args)
    {
        $settingsErrors = $this->settingsErrors();
        $permissionKey = $this->requestVar('permission');
        $permission = permissions()->getPermission($permissionKey);
        $crawlerKey = $args['crawler_key'];
        $crawler = $args['crawler'];
        $selectedCrawlers = !empty($permission['crawlers']) ? (array) $permission['crawlers'] : [];

        if (isset($settingsErrors['crawlers']['value'])) {
            $selectedCrawlers = (array) $settingsErrors['crawlers']['value'];
        }

        $inputId = 'permission-crawler-' . $crawlerKey;
        $details = [];
        $contacts = [];

        if (!empty($crawler['user_agent'])) {
            $details[] = esc_html__('UserAgent:', 'rrze-ac') . ' <code>' . esc_html($crawler['user_agent']) . '</code>';
        }

        if (!empty($crawler['contact_email'])) {
            $contacts[] = '<a href="mailto:' . esc_attr($crawler['contact_email']) . '">' . esc_html($crawler['contact_email']) . '</a>';
        }

        if (!empty($crawler['contact_url'])) {
            $contacts[] = '<a href="' . esc_url($crawler['contact_url']) . '">' . esc_html($crawler['contact_url']) . '</a>';
        }

        if (!empty($contacts)) {
            $details[] = esc_html__('Contact:', 'rrze-ac') . ' ' . implode(', ', $contacts);
        }
        ?>
        <label for="<?php echo esc_attr($inputId); ?>">
            <input id="<?php echo esc_attr($inputId); ?>" type="checkbox" name="<?php echo esc_attr(sprintf('%s[crawlers][]', $this->optionName)); ?>" value="<?php echo esc_attr($crawlerKey); ?>" <?php checked(in_array($crawlerKey, $selectedCrawlers, true)); ?>>
            <?php
            printf(
                esc_html__('Grant access to %s.', 'rrze-ac'),
                esc_html($crawler['title'])
            );
            ?>
        </label>
        <?php

        if (!empty($details)) {
            echo '<br>(' . esc_html__('Crawler details:', 'rrze-ac') . ' ' . wp_kses(
                implode(', ', $details),
                [
                    'a' => [
                        'href' => []
                    ],
                    'code' => []
                ]
            ) . ')';
        }
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
                        wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_activate($permission)) {
                        $this->addAdminNotice(__("The permission has been enabled.", 'rrze-ac'));
                        wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions')));
                        exit();
                    }
                    break;
                case 'deactivate':
                    if (!wp_verify_nonce($nonce, 'deactivate')) {
                        wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_activate($permission, 0)) {
                        $this->addAdminNotice(__("The permission has been disabled.", 'rrze-ac'));
                        wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions')));
                        exit();
                    }
                    break;
                case 'delete':
                    if (!wp_verify_nonce($nonce, 'delete')) {
                        wp_die(esc_html__("Something went wrong.", 'rrze-ac'));
                    }
                    if ($this->action_delete($permission)) {
                        $this->addAdminNotice(__("The permission has been deleted.", 'rrze-ac'));
                        wp_safe_redirect(Utils::actionUrl(array('tab' => 'permissions')));
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

    private function validate_settings($input, $optionPage = 'rrze-ac-settings')
    {
        if (
            isset($input['default_permission'])
            && isset($this->options['permissions'][$input['default_permission']])
            && $this->options['permissions'][$input['default_permission']]['active']
        ) {
            $this->options['default_permission'] = $input['default_permission'];
        }

        if (permissions()->ssoPluginIsAvailableAndActive() && $optionPage == 'rrze-ac-settings') {
            $this->options['automatic_sso_authentication'] = isset($input['automatic_sso_authentication']) ? 1 : 0;
        }

        if ($optionPage == 'rrze-ac-settings' && isset($input['permission_editor_role'])) {
            $role = sanitize_key($input['permission_editor_role']);
            if (wp_roles()->is_role($role)) {
                $this->options['permission_editor_role'] = $role;
            }
        }

        if (isset($input['contact_admin_name'])) {
            $this->options['contact_admin_name'] = esc_html(sanitize_text_field($input['contact_admin_name']));
        }

        if ($optionPage == 'rrze-ac-advanced' && $this->canManageDebuggingSettings()) {
            $this->options['log_info_messages'] = isset($input['log_info_messages']) ? 1 : 0;
        }

        if ($optionPage == 'rrze-ac-advanced') {
            $this->updateMessageTexts($input, 'access_denied', ['access_denied_default_title', 'access_denied_default_msg']);
            if (permissions()->ssoPluginIsAvailableAndActive()) {
                $this->updateMessageTexts($input, 'sso', ['user_isnt_sso_logged_in_title', 'user_isnt_sso_logged_in_msg', 'user_isnt_sso_logged_in_link_txt']);
            } else {
                $this->updateMessageTexts($input, 'login', ['user_isnt_logged_in_title', 'user_isnt_logged_in_msg', 'user_isnt_logged_in_link_txt']);
            }
            $this->updateMessageTexts($input, 'password', ['access_denied_password_title', 'access_denied_password_msg', 'access_denied_password_link_txt']);
        }

        if ($this->settingsErrors()) {
            return false;
        }

        return update_option($this->optionName, $this->options);
    }

    private function updateTextOption($input, $key, $type = 'text')
    {
        if (!isset($input[$key])) {
            return;
        }

        if ($type == 'textarea') {
            $value = esc_html(sanitize_textarea_field($input[$key]));
        } else {
            $value = esc_html(sanitize_text_field($input[$key]));
        }

        $this->options[$key] = $value ?: $this->options[$key];
    }

    private function updateMessageTexts($input, $group, $keys)
    {
        $toggleKey = 'use_default_' . $group . '_texts';
        $this->options[$toggleKey] = isset($input[$toggleKey]) ? 1 : 0;

        if ($this->options[$toggleKey]) {
            return;
        }

        foreach ($keys as $key) {
            $this->updateTextOption($input, $key, strpos($key, '_msg') !== false ? 'textarea' : 'text');
        }
    }

    private function canManageDebuggingSettings()
    {
        if (is_multisite()) {
            return is_super_admin();
        }

        return current_user_can('manage_options');
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
                    <div class="rrze-ac <?php echo esc_attr($class); ?>">
                        <p><?php echo wp_kses_post($message); ?></p>
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
