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
            $this->listData[$key]['default'] = !empty($data['core']) ? 1 : 0;
        }

        parent::__construct([
            'singular' => 'rrze-ac-permission',
            'plural' => 'rrze-ac-permissions',
            'ajax' => false
        ]);
    }

    public function single_row($item)
    {
        $class = $item['active'] ? 'active ' : 'inactive';
        $class .= $item['default'] ? ' default-permission' : '';
        echo $class ? '<tr class="' . esc_attr(trim($class)) . '">' : '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'permission_key':
            case 'select':
                $item[$column_name] = $this->permissionDisplayLabel($item, $column_name);
                break;
            case 'network_access':
                $item[$column_name] = $this->networkAccessColumn($item);
                break;
            case 'crawler':
                $item[$column_name] = $this->crawlerColumn($item);
                break;
            case 'logged_in':
                $item[$column_name] = $this->checkmarkColumn(!empty($item[$column_name]));
                break;
            case 'password':
                $item[$column_name] = $this->checkmarkColumn(!empty($item[$column_name]));
                break;
            case 'sso_logged_in':
                $item[$column_name] = $this->checkmarkColumn(!empty($item[$column_name]));
                break;
        }

        return $item[$column_name];
    }

    private function checkmarkColumn($enabled)
    {
        if (!$enabled) {
            return '';
        }

        return '<span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Yes', 'rrze-ac') . '</span>';
    }

    private function networkAccessColumn($item)
    {
        $values = [];

        if (!empty($item['domain'])) {
            $values = array_merge($values, (array) $item['domain']);
        }

        if (!empty($item['ip_address'])) {
            $values = array_merge($values, (array) $item['ip_address']);
        }

        $values = array_filter(array_map('trim', $values));

        if (empty($values)) {
            return '';
        }

        return '<pre class="rrze-ac-network-access">' . esc_html(implode(PHP_EOL, $values)) . '</pre>';
    }

    private function crawlerColumn($item)
    {
        $crawlers = [];
        $availableCrawlers = permissions()->getCrawlers();

        foreach ((array) ($item['crawlers'] ?? []) as $crawlerKey) {
            $crawler = $availableCrawlers[sanitize_key($crawlerKey)] ?? [];
            $crawlers[] = !empty($crawler['title'])
                ? $crawler['title']
                : $crawlerKey;
        }

        $crawlers = array_filter(array_unique($crawlers));

        if (empty($crawlers)) {
            return '';
        }

        return esc_html(implode(', ', $crawlers));
    }

    public function column_permission_key($item)
    {
        // Build row actions
        $actions = [];
        $format = '%1$s %2$s';
        if (!$item['core']) {
            $actions['edit'] = '<a href="' . esc_url(Utils::actionUrl(['tab' => 'permissions', 'action' => 'edit', 'permission' => $item['permission_key']])) . '">' . esc_html(__("Edit", 'rrze-ac')) . '</a>';

            if ($item['active']) {
                $format = '<strong>%1$s</strong> %2$s';
                $actions['deactivate'] = '<a href="' . esc_url(Utils::actionUrl(['tab' => 'permissions', 'action' => 'deactivate', 'permission' => $item['permission_key']])) . '">' . esc_html(__("Deactivate", 'rrze-ac')) . '</a>';
            } else {
                $actions['activate'] = '<a href="' . esc_url(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'activate', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Activate", 'rrze-ac')) . '</a>';
            }
        }

        if ($this->permissionCanBeDeleted($item)) {
            $actions['delete'] = '<a href="' . esc_url(Utils::actionUrl(array('tab' => 'permissions', 'action' => 'delete', 'permission' => $item['permission_key']))) . '">' . esc_html(__("Delete", 'rrze-ac')) . '</a>';
        }

        if ($item['active']) {
            $format = '<strong>%1$s</strong> %2$s';
        }

        return sprintf($format, $this->permissionDisplayLabel($item, 'permission_key'), $this->row_actions($actions));
    }

    private function permissionCanBeDeleted($item)
    {
        return empty($item['core'])
            && empty($item['default'])
            && empty(Post::countMetaKeys($item['permission_key']));
    }

    private function permissionDisplayLabel($item, $columnName)
    {
        if (!empty($item['core']) && $columnName == 'permission_key') {
            switch ($item['permission_key']) {
                case 'public':
                    return esc_html__('Public', 'rrze-ac');
                case 'logged-in':
                    return esc_html__('Logged-in', 'rrze-ac');
                default:
                    break;
            }
        }

        if (!empty($item['core']) && $columnName == 'select') {
            switch ($item['permission_key']) {
                case 'public':
                    return esc_html__('Publicly accessible', 'rrze-ac');
                case 'logged-in':
                    return esc_html__('Login required', 'rrze-ac');
                default:
                    break;
            }
        }

        if ($columnName == 'permission_key') {
            return esc_html(strtoupper($item['permission_key']));
        }

        return !empty($item[$columnName]) ? esc_html($item[$columnName]) : '';
    }

    public function get_columns()
    {
        $columns = array(
            'permission_key' => __("Permission", 'rrze-ac'),
            'select' => __("Short Description", 'rrze-ac'),
            'password' => __("Password", 'rrze-ac')
        );

        if (permissions()->ssoPluginIsAvailableAndActive()) {
            $columns['sso_logged_in'] = __('SSO', 'rrze-ac');
        } else {
            $columns['logged_in'] = __("Login", 'rrze-ac');
        }

        $columns['crawler'] = __("Crawler", 'rrze-ac');
        $columns['network_access'] = __("Network Access", 'rrze-ac');

        return $columns;
    }

    public function get_sortable_columns()
    {
        return [];
    }

    public function prepare_items()
    {
        $this->_column_headers = $this->get_column_info();

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
