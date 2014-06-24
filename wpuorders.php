<?php

/*
Plugin Name: WP Utilities Orders
Description: Allow a simple product order
Version: 0.4
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpuOrders
{

    private $order_statuses = array();

    /* ----------------------------------------------------------
    Options
    ---------------------------------------------------------- */

    function set_options() {
        global $wpdb;
        $this->options = array(
            'id' => 'wpuorders',
            'level' => 'manage_options'
        );
        $this->messages = array();
        $this->data_table = $wpdb->prefix . $this->options['id'] . "_table";
        load_plugin_textdomain($this->options['id'], false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Allow translation for plugin name
        $this->options['name'] = $this->__('WPU Orders');
        $this->options['menu_name'] = $this->__('Orders');

        // Set values
        $this->order_statuses = array(
            'new' => $this->__('New') ,
            'processing' => $this->__('Processing') ,
            'complete' => $this->__('Complete') ,
            'closed' => $this->__('Closed') ,
            'cancelled' => $this->__('Cancelled')
        );

        // Get order methods
        $default_order_methods = array(
            'manual' => array(
                'name' => $this->__('Manual order')
            ) ,
            'bankcheck' => array(
                'name' => $this->__('Bank check')
            ) ,
            'banktransfer' => array(
                'name' => $this->__('Bank transfer')
            )
        );
        $new_order_methods = apply_filters('wpuorder_order_methods', $default_order_methods);
        $this->order_methods = array();
        if (is_array($new_order_methods)) {
            $this->order_methods = $new_order_methods;
        }
    }

    /* ----------------------------------------------------------
    Construct
    ---------------------------------------------------------- */

    function __construct() {
        $this->set_options();
    }

    function init() {
        $this->set_global_hooks();
        if (is_admin()) {
            $this->set_admin_hooks();
        } else {
            $this->set_public_hooks();
        }
    }

    /* ----------------------------------------------------------
    Hooks
    ---------------------------------------------------------- */

    private function set_global_hooks() {
    }

    private function set_public_hooks() {
    }

    private function set_admin_hooks() {
        add_action('admin_menu', array(&$this,
            'set_admin_menu'
        ));
        add_action('admin_bar_menu', array(&$this,
            'set_adminbar_menu'
        ) , 100);
        add_action('wp_dashboard_setup', array(&$this,
            'add_dashboard_widget'
        ));
        if (isset($_GET['page']) && $_GET['page'] == $this->options['id']) {
            add_action('wp_loaded', array(&$this,
                'set_admin_page_main_postAction'
            ));
            add_action('wp_loaded', array(&$this,
                'set_admin_page_single_main_postAction'
            ));
            add_action('admin_print_styles', array(&$this,
                'load_assets_css'
            ));
            add_action('admin_enqueue_scripts', array(&$this,
                'load_assets_js'
            ));
            add_action('admin_notices', array(&$this,
                'admin_notices'
            ));
        }
    }

    /* ----------------------------------------------------------
      Methods
    ---------------------------------------------------------- */

    /* Add a new order */

    function create_order($details) {
        $order_id = false;

        if (!isset($details) || !is_array($details)) {
            trigger_error("The details array is invalid");
            return false;
        }

        // Check amount
        if (!isset($details['amount']) || !is_numeric($details['amount'])) {
            trigger_error("The amount is invalid");
            return false;
        }

        // Check order name
        if (!isset($details['name'])) {
            $details['name'] = 'Order';
        }

        // Check user id
        if (!isset($details['user'])) {
            $details['user'] = 0;
        }
        if ($details['user'] == 'current') {
            $details['user'] = get_current_user_id();
        }
        if (!is_numeric($details['user'])) {
            trigger_error("The user is invalid");
            return false;
        }

        $details['controlkey'] = sha1(microtime() . $details['amount'] . $details['user']);

        // Request
        global $wpdb;
        $wpdb->flush();
        $wpdb->insert($this->data_table, array(
            'user' => $details['user'],
            'amount' => $details['amount'],
            'name' => $details['name'],
            'controlkey' => $details['controlkey'],
        ) , array(
            '%d',
            '%d',
            '%s',
            '%s'
        ));
        $insert_id = $wpdb->insert_id;
        if (is_numeric($insert_id)) {
            $order_id = $insert_id;
        }

        return $order_id;
    }

    /* Get order details */

    function get_order_details($id, $key = '') {
        global $wpdb;
        if (!is_numeric($id)) {
            return false;
        }
        $base_query = 'SELECT * FROM ' . $this->data_table . ' WHERE id=%d';
        if (!empty($key)) {
            $req = $wpdb->prepare($base_query . ' AND controlkey=%s', $id, $key);
        } else {
            $req = $wpdb->prepare($base_query, $id);
        }
        return $wpdb->get_row($req);
    }

    /* Update order */

    function update_order($id, $values) {
        global $wpdb;
        if (!is_numeric($id)) {
            return false;
        }

        // Check status value
        if (isset($values['status'])) {
            if (!array_key_exists($values['status'], $this->order_statuses)) {
                unset($values['status']);
            }
        }

        if (empty($values)) {
            return false;
        }

        $data_update = array();
        $data_update_format = array();

        // For each value add to update array
        foreach ($values as $key => $val) {
            $data_update[$key] = $val;
            switch ($key) {
                case 'amount':
                case 'user':
                    $format = '%d';
                    break;

                default:
                    $format = '%s';
            }
            $data_update_format[] = $format;
        }

        $wpdb->update($this->data_table, $data_update, array(
            'id' => $id
        ) , $data_update_format, array(
            '%d'
        ));

        do_action('wpuorder_post_update_order', $id, $values);
        return true;
    }

    /* Display price */

    function return_price($amount, $currency = 'euro') {
        return round($amount / 100, 2) . ' ' . $currency;
    }

    /* Display status */

    function return_status_name($status) {
        if (array_key_exists($status, $this->order_statuses)) {
            $status = $this->order_statuses[$status];
        }
        return $status;
    }

    /* Display date */

    function return_order_date($order_date) {
        $order_date_php = strtotime($order_date);
        return date($this->__('Y/m/d H:i:s') , $order_date_php);
    }

    /* Display user */

    function return_user_name($user_id) {
        $html = '';
        $order_user = get_user_by('id', $user_id);
        if ($order_user != false) {
            $html = '<a href="' . admin_url('user-edit.php?user_id=' . $user_id) . '">' . $order_user->data->user_nicename . '</a>';
        } else {
            $html = $this->__('Guest');
        }
        return $html;
    }

    /* Display select status */

    function return_status_select($status) {
        if (!array_key_exists($status, $this->order_statuses)) {
            return $status;
        }

        // Display only values after current status
        $hide_value = true;
        $html = '<select name="status" id="order_status">';
        foreach ($this->order_statuses as $val => $name) {
            if ($val == $status) {
                $hide_value = false;
            }
            if (!$hide_value) {

                $html.= '<option value="' . $val . '">' . $name . '</option>';
            }
        }
        $html.= '</select>';

        return $html;
    }

    /* ----------------------------------------------------------
    Admin
    ---------------------------------------------------------- */

    function set_admin_menu() {
        add_menu_page($this->options['name'], $this->options['menu_name'], $this->options['level'], $this->options['id'], array(&$this,
            'set_admin_page_main'
        ) , 'dashicons-chart-line');
    }

    function set_adminbar_menu($admin_bar) {
        $admin_bar->add_menu(array(
            'id' => $this->options['id'],
            'title' => $this->options['menu_name'],
            'href' => admin_url('admin.php?page=' . $this->options['id']) ,
            'meta' => array(
                'title' => $this->options['menu_name'],
            ) ,
        ));
    }

    function set_admin_page_main() {
        echo $this->get_wrapper_start($this->options['name']);

        if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
            $this->content_admin_page_single($_GET['order_id']);
        } else {
            $this->content_admin_page_main();
        }

        echo $this->get_wrapper_end();
    }

    function content_admin_page_main() {
        global $wpdb;

        $pager = $this->get_pager_limit(20, $this->data_table);
        $list = $wpdb->get_results("SELECT id, date, name, user, amount, currency, method, status FROM " . $this->data_table . " " . $pager['limit']);

        if (empty($list)) {
            echo '<p>' . __('No results yet', $this->options['id']) . '</p>';
        } else {
            foreach ($list as $order) {

                // Edit user id display
                $order->user = $this->return_user_name($order->user);

                // Edit date display
                $order->date = $this->return_order_date($order->date);

                // Edit price display
                $order->amount = $this->return_price($order->amount, $order->currency);
                unset($order->currency);

                // Edit status display
                $order->status = $this->return_status_name($order->status);

                // Add a column to view order details
                $order->last_column = '<a href="' . admin_url('admin.php?page=' . $this->options['id'] . '&order_id=' . $order->id) . '" class="button">' . $this->__('View order') . '</a>';
            }

            // Display order list
            echo $this->get_admin_table($list, array(
                'columns' => array(
                    $this->__('ID') ,
                    $this->__('Date') ,
                    $this->__('Name') ,
                    $this->__('User') ,
                    $this->__('Amount') ,
                    $this->__('Method') ,
                    $this->__('Status') ,
                    ''
                ) ,
                'pagenum' => $pager['pagenum'],
                'max_pages' => $pager['max_pages']
            ));
        }
    }

    function set_admin_page_main_postAction() {
        if (empty($_POST) || !isset($_POST['action-main-form-' . $this->options['id']]) || !wp_verify_nonce($_POST['action-main-form-' . $this->options['id']], 'action-main-form')) {
            return;
        }
        $this->messages[] = 'Success !';
    }

    function content_admin_page_single($order_id) {
        global $wpdb;
        $order = $this->get_order_details($order_id);
        echo '<p><a class="button" href="' . admin_url('admin.php?page=' . $this->options['id']) . '">' . $this->__('Back') . '</a></p>';
        if (is_object($order)) {
            echo '<form action="" method="post">';
            echo '<table style="max-width: 500px;">
    <tbody>
        <tr>
            <td>
                <strong>' . $this->__('Order name:') . '</strong> ' . $order->name . '
            </td>
            <td>
                <strong>' . $this->__('Date:') . '</strong> ' . $this->return_order_date($order->date) . '
            </td>
        </tr>
        <tr>
            <td>
                <strong>' . $this->__('Amount:') . '</strong> ' . $this->return_price($order->amount, $order->currency) . '
            </td>
            <td>
                <strong>' . $this->__('Method:') . '</strong> ' . $order->method . '
            </td>
        </tr>
        <tr>
            <td>
                <strong>' . $this->__('User:') . '</strong> ' . $this->return_user_name($order->user) . '
            </td>
            <td>
                <strong>' . $this->__('Status:') . '</strong> ' . $this->return_status_select($order->status) . '
            </td>
        </tr>
    </tbody>
</table>';

            // details
            if (!empty($order->details)) {
                echo '<div><strong>' . $this->__('Details:') . '</strong> <pre style="overflow: auto;max-width:500px;padding:10px;font-size: 12px;background-color: #FFFFFF;">' . $order->details . '</pre>';
            }

            wp_nonce_field('update-order_' . $this->options['id'], 'update-order_' . $this->options['id']);
            echo '<button type="submit" class="button button-primary">' . $this->__('Update order') . '</button>';
            echo '</form>';
        } else {
            echo '<p>' . $this->__('This order doesn’t exists') . '</p>';
        }
    }

    function set_admin_page_single_main_postAction() {
        if (empty($_POST) || !isset($_POST['update-order_' . $this->options['id']]) || !wp_verify_nonce($_POST['update-order_' . $this->options['id']], 'update-order_' . $this->options['id'])) {
            return;
        }

        if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
            return false;
        }

        $values = array();
        if (isset($_POST['status'])) {
            $values['status'] = $_POST['status'];
        }
        if (!empty($values)) {

            // Set order status to success
            $success = $this->update_order($_GET['order_id'], $values);
            if ($success === true) {

                $this->messages[] = $this->__('The order has been successfully updated.');
            }
        }
    }

    /* Widget Dashboard */
    function add_dashboard_widget() {
        wp_add_dashboard_widget($this->options['id'] . '_dashboard_widget', $this->options['name'], array(&$this,
            'content_dashboard_widget'
        ));
    }

    function content_dashboard_widget() {
        echo '<p>Hello World !</p>';
    }

    /* ----------------------------------------------------------
    Assets & Notices
    ---------------------------------------------------------- */

    /* Display notices */
    function admin_notices() {
        $return = '';

        if (!empty($this->messages)) {
            foreach ($this->messages as $message) {
                $return.= '<div class="updated"><p>' . $message . '</p></div>';
            }
        }

        // Empty messages
        $this->messages = array();
        echo $return;
    }

    function load_assets_js() {
        wp_enqueue_script($this->options['id'] . '_scripts', plugin_dir_url(__FILE__) . '/assets/js/script.js');
    }

    function load_assets_css() {
        wp_register_style($this->options['id'] . '_style', plugins_url('assets/css/style.css', __FILE__));
        wp_enqueue_style($this->options['id'] . '_style');
    }

    /* ----------------------------------------------------------
    Activation / Desactivation
    ---------------------------------------------------------- */

    function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Create or update table search
        dbDelta("CREATE TABLE " . $this->data_table . " (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `name` varchar(100) DEFAULT 'order',
            `user` int(11) unsigned NOT NULL DEFAULT '0',
            `currency` varchar(100) DEFAULT 'euro',
            `method` varchar(100) DEFAULT 'manual',
            `status` varchar(100) DEFAULT 'new',
            `controlkey` varchar(100),
            `details` TEXT varchar(100),
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8;");
    }

    function deactivate() {
    }

    function uninstall() {
        global $wpdb;
        $wpdb->query('DROP TABLE ' . $this->data_table);
    }

    /* ----------------------------------------------------------
    Utilities : Requests
    ---------------------------------------------------------- */

    private function get_pager_limit($perpage, $tablename = '') {
        global $wpdb;

        // Ensure good format for table name
        if (empty($tablename) || !preg_match('/^([A-Za-z0-9_-]+)$/', $tablename)) {
            return array(
                'pagenum' => 0,
                'max_pages' => 0,
                'limit' => '',
            );
        }

        // Ensure good format for perpage
        if (empty($perpage) || !is_numeric($perpage)) {
            $perpage = 20;
        }

        // Get number of elements in table
        $elements_count = $wpdb->get_var("SELECT COUNT(*) FROM " . $tablename);

        // Get max page number
        $max_pages = ceil($elements_count / $perpage);

        // Obtain Page Number
        $pagenum = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? $_GET['pagenum'] : 1);
        $pagenum = min($pagenum, $max_pages);

        // Set SQL limit
        $limit = 'LIMIT ' . ($pagenum * $perpage - $perpage) . ', ' . $perpage;
        return array(
            'pagenum' => $pagenum,
            'max_pages' => $max_pages,
            'limit' => $limit,
        );
    }

    /* ----------------------------------------------------------
    Utilities : Public
    ---------------------------------------------------------- */

    private function public_message($message = '') {
        get_header();
        echo '<div class="' . $this->options['id'] . '-message">' . $message . '</div>';
        get_footer();
        exit();
    }

    /* ----------------------------------------------------------
    Utilities : Translate
    ---------------------------------------------------------- */

    function __($string) {
        return __($string, $this->options['id']);
    }

    /* ----------------------------------------------------------
    Utilities : Display
    ---------------------------------------------------------- */

    private function get_wrapper_start($title) {
        return '<div class="wrap"><div id="icon-options-general" class="icon32"></div><h2 class="title">' . $title . '</h2><br />';
    }

    private function get_wrapper_end() {
        return '</div>';
    }

    private function get_admin_table($values, $args = array()) {
        $pagination = '';
        if (isset($args['pagenum'], $args['max_pages'])) {
            $page_links = paginate_links(array(
                'base' => add_query_arg('pagenum', '%#%') ,
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $args['max_pages'],
                'current' => $args['pagenum']
            ));
            if ($page_links) {
                $pagination = '<div class="tablenav"><div class="tablenav-pages" style="margin: 1em 0">' . $page_links . '</div></div>';
            }
        }
        $content = '<table class="widefat">';
        if (isset($args['columns']) && is_array($args['columns']) && !empty($args['columns'])) {
            $labels = '<tr><th>' . implode('</th><th>', $args['columns']) . '</th></tr>';
            $content.= '<thead>' . $labels . '</thead>';
            $content.= '<tfoot>' . $labels . '</tfoot>';
        }
        $content.= '<tbody>';
        foreach ($values as $id => $vals) {
            $content.= '<tr>';
            foreach ($vals as $val) {
                $content.= '<td>' . $val . '</td>';
            }
            $content.= '</tr>';
        }
        $content.= '</tbody>';
        $content.= '</table>';
        $content.= $pagination;
        return $content;
    }
}

$wpuOrders = false;

add_action('init', 'init_wpuOrders');

function init_wpuOrders() {
    global $wpuOrders;
    $wpuOrders = new wpuOrders();
    $wpuOrders->init();
}

/* Limited launch for activation/deactivation hook */

$temp_wpuOrders = new wpuOrders();

register_activation_hook(__FILE__, array(&$temp_wpuOrders,
    'activate'
));

register_deactivation_hook(__FILE__, array(&$temp_wpuOrders,
    'deactivate'
));
