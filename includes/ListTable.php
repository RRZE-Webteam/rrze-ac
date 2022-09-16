<?php

namespace RRZE\AccessControl;

use WP_List_Table;

defined('ABSPATH') || exit;

class ListTable extends WP_List_Table {

    protected $main;

    public $list_data = array();

    public function __construct(Main $main) {
        $this->main = $main;

        $this->list_data = $this->main->get_the_permissions();
        foreach ($this->list_data as $key => $data) {
            $this->list_data[$key]['default'] = ($data['permission_key'] == $this->main->get_default_permission()) ? 1 : 0;
        }

        parent::__construct(array(
            'singular' => 'rrzeac',
            'plural' => 'rrzeacs',
            'ajax' => FALSE
        ));
    }

    public function single_row($item) {
        $class = $item['active'] ? 'active ' : 'inactive';
        $class .= $item['default'] ? 'default-permission' : '';
        echo $class ? '<tr class="' . trim($class) . '">' : '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    public function column_default($item, $column_name) {
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
            case 'sso_logged_in':
                $item[$column_name] = !empty($item[$column_name]) ? '<span class="dashicons dashicons-yes"></span>' : '';
        }

        return $item[$column_name];
    }

    public function column_permission_key($item) {
        // Build row actions
        $actions = array();
        if (!$item['core']) {
            $actions['edit'] = '<a href="' . esc_url($this->main->action_url(array('action' => 'edit', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Edit", 'rrze-ac')) . '</a>';
        }
        if (!$item['core'] && !$item['default']) {
            if ($item['active']) {
                $actions['deactivate'] = '<a href="' . esc_url($this->main->action_url(array('action' => 'deactivate', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Deactivate", 'rrze-ac')) . '</a>';
            } else {
                $actions['activate'] = '<a href="' . esc_url($this->main->action_url(array('action' => 'activate', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Activate", 'rrze-ac')) . '</a>';
                if (empty($this->main->count_meta_keys($item['permission_key']))) {
                    $actions['delete'] = '<a href="' . esc_url($this->main->action_url(array('action' => 'delete', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Delete", 'rrze-ac')) . '</a>';
                }
            }
        }
        return sprintf('%1$s %2$s', $item['permission_key'], $this->row_actions($actions));
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="%1$s[]" value="%2$s">', $this->_args['singular'], $item['permission_key']);
    }

    public function get_columns() {
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

    public function get_sortable_columns() {
        $sortable_columns = array(
            'permission_key' => array('permission_key', FALSE),
            'select' => array('select', FALSE),
            'logged_in' => array('logged_in', FALSE),
            'sso_logged_in' => array('sso_logged_in', FALSE)
        );
        return $sortable_columns;
    }

    public function get_bulk_actions() {
        $actions = array(
            'activate' => __("Activate", 'rrze-ac'),
            'deactivate' => __("Deactivate", 'rrze-ac'),
            'delete' => __("Delete", 'rrze-ac')
        );
        return $actions;
    }

    public function process_bulk_action() {
        $permission_keys = $this->main->settings->request_var($this->_args['singular']);

        if(!empty($permission_keys) && is_array($permission_keys)) {
            switch ($this->current_action()) {
                case 'activate':
                    $this->process_bulk_activate($permission_keys);
                    break;
                case 'deactivate':
                    $this->process_bulk_activate($permission_keys, 0);
                    break;
                case 'delete':
                    $this->process_bulk_delete($permission_keys);
                    break;
            }
        }
    }

    private function process_bulk_delete($permission_keys) {
        foreach ($permission_keys as $value) {
            $permission = $this->main->get_permission($value);
            $this->main->settings->action_delete($permission);
        }
        wp_redirect($this->main->action_url());
        exit();
    }

    private function process_bulk_activate($permission_keys, $activate = 1) {
        foreach ($permission_keys as $value) {
            $permission = $this->main->get_permission($value);
            $this->main->settings->action_activate($permission, $activate);
        }
        wp_redirect($this->main->action_url());
        exit();
    }

    public function prepare_items() {
        $this->_column_headers = $this->get_column_info();

        usort($this->list_data, array(&$this, 'sort_data'));

        if (isset($_GET['s']) && mb_strlen(trim($_GET['s'])) > 0) {
            $search = trim($_GET['s']);
            foreach ($this->list_data as $key => $data) {
                $permission_key = mb_stripos($data['permission_key'], $search) === FALSE ? TRUE : FALSE;
                $select = mb_stripos($data['select'], $search) === FALSE ? TRUE : FALSE;
                $description = mb_stripos($data['description'], $search) === FALSE ? TRUE : FALSE;

                $domain = !empty($data['domain']) ? $data['domain'] : array();
                $dom = TRUE;
                foreach ($domain as $value) {
                    if (isset($value) && mb_stripos($value, $search) !== FALSE) {
                        $dom = FALSE;
                        break;
                    }
                }

                $ip_address = !empty($data['ip_address']) ? $data['ip_address'] : array();
                $ip = TRUE;
                foreach ($ip_address as $value) {
                    if (isset($value) && mb_stripos($value, $search) !== FALSE) {
                        $ip = FALSE;
                        break;
                    }
                }

                if ($permission_key && $select && $description && $ip && $dom) {
                    unset($this->list_data[$key]);
                }
            }
        }

        $this->process_bulk_action();

        $per_page = $this->get_items_per_page('rrzeacs_per_page', 20);
        $current_page = $this->get_pagenum();
        $total_items = count($this->list_data);

        $this->items = array_slice($this->list_data, (($current_page - 1) * $per_page), $per_page);

        $this->set_pagination_args(array(
            'total_items' => $total_items, // Total number of items
            'per_page' => $per_page, // How many items to show on a page
            'total_pages' => ceil($total_items / $per_page)   // Total number of pages
        ));
    }

    public function sort_data($a, $b) {
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'select';
        $order = (!empty($_GET['order'])) ? $_GET['order'] : 'asc';
        $result = strnatcmp($a[$orderby], $b[$orderby]);
        return ($order === 'asc') ? $result : -$result;
    }

}
