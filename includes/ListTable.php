<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class ListTable extends \WP_List_Table
{

    protected $main;

    public $listData = [];

    public function __construct(Main $main)
    {
        $this->main = $main;

        $this->listData = permissions()->getThePermissions();
        foreach ($this->listData as $key => $data) {
            $this->listData[$key]['default'] = ($data['permission_key'] == permissions()->getDefaultPermission()) ? 1 : 0;
        }

        parent::__construct([
            'singular' => 'rrzeac',
            'plural' => 'rrzeacs',
            'ajax' => false
        ]);
    }

    public function single_row($item)
    {
        $class = $item['active'] ? 'active ' : 'inactive';
        $class .= $item['default'] ? 'default-permission' : '';
        echo $class ? '<tr class="' . trim($class) . '">' : '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'permission_key':
            case 'select':
                $item[$column_name] = !empty($item[$column_name]) ? $item[$column_name] : '';
                break;
            case 'description':
                $item[$column_name] = !empty($item[$column_name]) ? esc_html(wp_trim_words(sanitize_textarea_field($item[$column_name]))) : '';
                break;
            case 'domain':
            case 'ip_address':
                $item[$column_name] = !empty($item[$column_name]) ? implode('<br>', $item[$column_name]) : '';
                break;
            case 'logged_in':
                $item[$column_name] = !empty($item[$column_name]) ? '<span class="dashicons dashicons-yes"></span>' : '';
                break;
            case 'sso_logged_in':
                $item[$column_name] = !empty($item[$column_name]) ? '<span class="dashicons dashicons-yes"></span>' : '';
                $item[$column_name] .= !empty($item['affiliation']) ? '<br>' . implode(' ', $item['affiliation']) : '';
                $item[$column_name] .= !empty($item['entitlement']) ? '<br>' . implode(' ', $item['entitlement']) : '';
                break;
        }

        return $item[$column_name];
    }

    public function column_permission_key($item)
    {
        // Build row actions
        $actions = [];
        $format = '%1$s %2$s';
        if (!$item['core']) {
            $actions['edit'] = '<a href="' . esc_url(Utils::actionUrl(['action' => 'edit', 'permission' => $item['permission_key']])) . '">' . esc_html(__("Edit", 'rrze-ac')) . '</a>';
        }
        if ($item['active']) {
            $format = '<strong>%1$s</strong> %2$s';
            $actions['deactivate'] = '<a href="' . esc_url(Utils::actionUrl(['action' => 'deactivate', 'permission' => $item['permission_key']])) . '">' . esc_html(__("Deactivate", 'rrze-ac')) . '</a>';
        } else {
            $actions['activate'] = '<a href="' . esc_url(Utils::actionUrl(array('action' => 'activate', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Activate", 'rrze-ac')) . '</a>';
            if (empty(Post::countMetaKeys($item['permission_key']))) {
                $actions['delete'] = '<a href="' . esc_url(Utils::actionUrl(array('action' => 'delete', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Delete", 'rrze-ac')) . '</a>';
            }
        }
        return sprintf($format, strtoupper($item['permission_key']), $this->row_actions($actions));
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="%1$s[]" value="%2$s">', $this->_args['singular'], $item['permission_key']);
    }

    public function get_columns()
    {
        $columns = array(
            'cb' => '<input type="checkbox">', // Render a checkbox instead of text
            'permission_key' => __("Permission", 'rrze-ac'),
            'select' => __("Short Description", 'rrze-ac'),
            'description' => __("Description", 'rrze-ac'),
            'logged_in' => __("Logged-in", 'rrze-ac'),
            'sso_logged_in' => __('SSO', 'rrze-ac'),
            'domain' => __("Allow domain", 'rrze-ac'),
            'ip_address' => __("Allow IP address", 'rrze-ac')
        );
        return $columns;
    }

    public function get_sortable_columns()
    {
        $sortableColumns = [
            'permission_key' => ['permission_key', false],
            'select' => ['select', false],
            'logged_in' => ['logged_in', false],
            'sso_logged_in' => ['sso_logged_in', false]
        ];
        return $sortableColumns;
    }

    public function get_bulk_actions()
    {
        $actions = [
            'activate' => __("Activate", 'rrze-ac'),
            'deactivate' => __("Deactivate", 'rrze-ac'),
            'delete' => __("Delete", 'rrze-ac')
        ];
        return $actions;
    }

    public function process_bulk_action()
    {
        $permissionKeys = $this->main->settings->requestVar($this->_args['singular']);

        if (!empty($permissionKeys) && is_array($permissionKeys)) {
            switch ($this->current_action()) {
                case 'activate':
                    $this->process_bulk_activate($permissionKeys);
                    break;
                case 'deactivate':
                    $this->process_bulk_activate($permissionKeys, 0);
                    break;
                case 'delete':
                    $this->process_bulk_delete($permissionKeys);
                    break;
            }
        }
    }

    private function process_bulk_delete($permissionKeys)
    {
        foreach ($permissionKeys as $value) {
            $permission = permissions()->getPermission($value);
            $this->main->settings->action_delete($permission);
        }
        wp_redirect(Utils::actionUrl());
        exit();
    }

    private function process_bulk_activate($permissionKeys, $activate = 1)
    {
        foreach ($permissionKeys as $value) {
            $permission = permissions()->getPermission($value);
            $this->main->settings->action_activate($permission, $activate);
        }
        wp_redirect(Utils::actionUrl());
        exit();
    }

    public function prepare_items()
    {
        $this->_column_headers = $this->get_column_info();

        if (isset($_GET['s']) && mb_strlen(trim($_GET['s'])) > 0) {
            $search = trim($_GET['s']);
            foreach ($this->listData as $key => $data) {
                $permissionKey = mb_stripos($data['permission_key'], $search) === false ? true : false;
                $select = mb_stripos($data['select'], $search) === false ? true : false;
                $description = mb_stripos($data['description'], $search) === false ? true : false;

                $domain = !empty($data['domain']) ? $data['domain'] : [];
                $dom = true;
                foreach ($domain as $value) {
                    if (isset($value) && mb_stripos($value, $search) !== false) {
                        $dom = false;
                        break;
                    }
                }

                $ipAddress = !empty($data['ip_address']) ? $data['ip_address'] : [];
                $ip = true;
                foreach ($ipAddress as $value) {
                    if (isset($value) && mb_stripos($value, $search) !== false) {
                        $ip = false;
                        break;
                    }
                }

                if ($permissionKey && $select && $description && $ip && $dom) {
                    unset($this->listData[$key]);
                }
            }
        }

        $this->process_bulk_action();

        $perPage = $this->get_items_per_page('rrzeacs_per_page', 20);
        $currentPage = $this->get_pagenum();
        $totalItems = count($this->listData);

        $this->items = array_slice($this->listData, (($currentPage - 1) * $perPage), $perPage);

        $this->set_pagination_args([
            'total_items' => $totalItems, // Total number of items
            'per_page' => $perPage, // How many items to show on a page
            'total_pages' => ceil($totalItems / $perPage)   // Total number of pages
        ]);
    }
}
