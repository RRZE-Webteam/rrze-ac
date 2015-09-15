<?php

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class RRZE_AC_List_Table extends WP_List_Table {

    public $list_data = array();

    public function __construct() {
        if (class_exists('RRZE_AC')) {
            $this->list_data = RRZE_AC::get_the_permissions();
            foreach ($this->list_data as $key => $data) {
                $this->list_data[$key]['default'] = ($data['permission_key'] == RRZE_AC::get_default_permission()) ? 1 : 0;
            }
        }
        
        parent::__construct(array(
            'singular' => 'plugin',
            'plural' => 'plugins',
            'ajax' => FALSE
        ));
    }

	public function single_row($item) {
        $class = $item['active'] ? 'active ' : '';
        $class .= $item['default'] ? 'default-permission' : '';
		echo $class ? '<tr class="' . trim($class) . '">' : '<tr>';
		$this->single_row_columns($item);
		echo '</tr>';
	}
    
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'permission_key':
            case 'select':
            case 'description':
                $item[$column_name] = !empty($item[$column_name]) ? $item[$column_name] : '';
                break;                
            case 'ip_address':
                $item[$column_name] = !empty($item[$column_name]) ? implode('<br>', $item[$column_name]) : '';
                break;                
            case 'logged_in':
                $item[$column_name] = !empty($item[$column_name]) ? '<span class="dashicons dashicons-yes"></span>' : '';
        }
        
        return $item[$column_name];
    }

    public function column_permission_key($item) {
        $page = isset($_REQUEST['page']) ? esc_attr($_REQUEST['page']) : '';
        $id = $item['permission_key'];
        // Create a nonce
        $wp_nonce = wp_create_nonce('access_action');
        // Build row actions
        $actions['edit'] = sprintf('<a href="?page=%1$s&action=%2$s&permission=%3$s">%4$s</a>', $page, 'edit', $item['permission_key'], __('Bearbeiten', 'rrze-ac'));                
        
        if($item['active'] && !$item['core'] && !$item['default']) {
            $actions['deactivate'] = sprintf('<a href="?page=%1$s&action=%2$s&permission=%3$s&_wpnonce=%4$s">%5$s</a>', $page, 'deactivate', $item['permission_key'], $wp_nonce, __('Deaktivieren', 'rrze-ac'));
        } elseif(!$item['active'] && !$item['core'] && !$item['default']) {
            $actions['activate'] = sprintf('<a href="?page=%1$s&action=%2$s&permission=%3$s&_wpnonce=%4$s">%5$s</a>', $page, 'activate', $item['permission_key'],$wp_nonce,  __('Aktivieren', 'rrze-ac'));
            if(count($this->list_data) > 1) {
                $actions['delete'] = sprintf('<a href="?page=%1$s&action=%2$s&permission=%3$s&_wpnonce=%4$s">%5$s</a>', $page, 'delete', $item['permission_key'], $wp_nonce, __('Löschen', 'rrze-ac'));
            }
        }
        // Return the title contents
        return sprintf('%1$s %2$s',
                /* $1%s */ $item['permission_key'],
                /* $2%s */ $this->row_actions($actions)
        );
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="access_bulk_action[]" value="%s" />', $item['permission_key']);
    }

    public function get_columns() {
        $columns = array(
            'cb' => '<input type="checkbox" />', // Render a checkbox instead of text
            'permission_key' => __('Berechtigung', 'rrze-ac'),
            'select' => __('Kurzbeschreibung', 'rrze-ac'),
            'description' => __('Beschreibung', 'rrze-ac'),
            'logged_in' => __('Angemeldet', 'rrze-ac'),
            'ip_address' => __('IP-Adressbereiche', 'rrze-ac')
        );
        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = array(
            'permission_key' => array('permission_key', FALSE),
            'logged_in' => array('logged_in', FALSE)
        );
        return $sortable_columns;
    }
  
    public function get_bulk_actions() {
        $actions = array(
            'bulk-activate' => __('Aktivieren', 'rrze-ac'),
            'bulk-deactivate' => __('Deaktivieren', 'rrze-ac'),
            'bulk-delete' => __('Löschen', 'rrze-ac')
        );
        return $actions;
    }

    public function usort_reorder($a, $b) {
        // If no sort, default to user_creation_time
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'logged_in';
        // If no order, default to asc
        $order = (!empty($_GET['order'])) ? $_GET['order'] : 'asc';
        // Determine sort order
        $result = strcmp( $a[$orderby], $b[$orderby] );
        // Send final sort direction to usort
        return ( $order === 'asc' ) ? $result : -$result;
    }

    public function prepare_items() {
        $this->_column_headers = $this->get_column_info();

        usort($this->list_data, array(&$this, 'usort_reorder'));
                
        $per_page = $this->get_items_per_page('access_per_page', 5);
        $current_page = $this->get_pagenum();
        $total_items = count($this->list_data);

        $this->items = array_slice($this->list_data, (($current_page - 1) * $per_page), $per_page);

        $this->set_pagination_args(array(
            'total_items' => $total_items, // Total number of items
            'per_page' => $per_page, // How many items to show on a page
            'total_pages' => ceil($total_items / $per_page) // Total number of pages
        ));
    }

}
