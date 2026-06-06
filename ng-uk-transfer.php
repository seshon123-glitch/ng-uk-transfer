<?php
/*
Plugin Name: Daphkoy Limited Money Transfer
Plugin URI: https://daphkoy.com
Description: Nigeria to United Kingdom Money Transfer Plugin
Version: 2.8.1
Author: Beejay
GitHub URI: https://github.com/seshon123-glitch/ng-uk-transfer
GitHub Plugin URI: seshon123-glitch/ng-uk-transfer
Primary Branch: main
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| CONSTANTS
|--------------------------------------------------------------------------
*/

define('NGUK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('NGUK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NGUK_PLUGIN_VERSION', '2.8.1');
define('NGUK_COMPANY_NAME', 'Daphkoy Limited');
define('NGUK_DEFAULT_LOGO_URL', NGUK_PLUGIN_URL . 'assets/images/daphkoy-logo.png');
define('NGUK_ACCESS_CAP', 'nguk_access_transfers');
define('NGUK_PROCESS_CAP', 'nguk_process_transfers');
define('NGUK_CUSTOMER_CAP', 'nguk_manage_transfer_customers');
define('NGUK_RECEIPT_CAP', 'nguk_view_transfer_receipts');
define('NGUK_REPORTS_CAP', 'nguk_manage_transfer_reports');
define('NGUK_SETTINGS_CAP', 'nguk_manage_transfer_settings');
define('NGUK_DELETE_CAP', 'nguk_delete_transfer_records');
define('NGUK_DB_VERSION', '2.8');

/*
|--------------------------------------------------------------------------
| ROLE / ACCESS CONTROL
|--------------------------------------------------------------------------
*/

function nguk_transfer_capabilities() {
    return array(
        NGUK_ACCESS_CAP,
        NGUK_PROCESS_CAP,
        NGUK_CUSTOMER_CAP,
        NGUK_RECEIPT_CAP,
        NGUK_REPORTS_CAP,
        NGUK_SETTINGS_CAP,
        NGUK_DELETE_CAP
    );
}

function nguk_setup_roles() {
    add_role(
        'transfer_staff',
        'Transfer Staff',
        array(
            'read' => true,
            NGUK_ACCESS_CAP => true,
            NGUK_PROCESS_CAP => true,
            NGUK_CUSTOMER_CAP => true,
            NGUK_RECEIPT_CAP => true
        )
    );

    $administrator = get_role('administrator');

    if ($administrator) {
        foreach (nguk_transfer_capabilities() as $capability) {
            $administrator->add_cap($capability);
        }
    }

    $transfer_staff = get_role('transfer_staff');

    if ($transfer_staff) {
        $transfer_staff->add_cap('read');
        $transfer_staff->add_cap(NGUK_ACCESS_CAP);
        $transfer_staff->add_cap(NGUK_PROCESS_CAP);
        $transfer_staff->add_cap(NGUK_CUSTOMER_CAP);
        $transfer_staff->add_cap(NGUK_RECEIPT_CAP);
        $transfer_staff->remove_cap(NGUK_REPORTS_CAP);
        $transfer_staff->remove_cap(NGUK_SETTINGS_CAP);
        $transfer_staff->remove_cap(NGUK_DELETE_CAP);
    }
}

function nguk_is_transfer_staff() {
    $user = wp_get_current_user();

    return $user && in_array('transfer_staff', (array) $user->roles, true);
}

function nguk_staff_dashboard_url($direction = 'nguk') {
    $args = array(
        'page' => 'nguk-transfer'
    );

    if ($direction === 'ukng') {
        $args['ukng_view'] = 'transactions';
    } else {
        $args['nguk_view'] = 'transactions';
    }

    return add_query_arg($args, admin_url('admin.php'));
}

function nguk_staff_allowed_admin_page() {
    if (!nguk_is_transfer_staff()) {
        return true;
    }

    if (wp_doing_ajax()) {
        return true;
    }

    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    return $page === 'nguk-transfer';
}

function nguk_transfer_staff_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if ($user instanceof WP_User && in_array('transfer_staff', (array) $user->roles, true)) {
        return nguk_staff_dashboard_url();
    }

    return $redirect_to;
}

function nguk_restrict_transfer_staff_admin() {
    if (!is_admin() || nguk_staff_allowed_admin_page()) {
        return;
    }

    wp_safe_redirect(nguk_staff_dashboard_url());
    exit;
}

function nguk_transfer_staff_admin_menu() {
    if (!nguk_is_transfer_staff()) {
        return;
    }

    global $menu;

    foreach ((array) $menu as $index => $item) {
        $slug = isset($item[2]) ? $item[2] : '';

        if ($slug !== 'nguk-transfer') {
            remove_menu_page($slug);
        }
    }
}

function nguk_transfer_staff_admin_bar($show_admin_bar) {
    return nguk_is_transfer_staff() ? false : $show_admin_bar;
}

function nguk_dashboard_footer_version($footer_text) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    if ($page !== 'nguk-transfer') {
        return $footer_text;
    }

    return 'Version ' . esc_html(NGUK_PLUGIN_VERSION);
}

function nguk_ajax_customer_search() {
    if (!current_user_can(NGUK_ACCESS_CAP)) {
        wp_send_json_error(array('message' => 'You do not have permission to search customers.'), 403);
    }

    check_ajax_referer('nguk_customer_search', 'nonce');

    global $wpdb;

    $direction = isset($_GET['direction']) && $_GET['direction'] === 'ukng'
        ? 'ukng'
        : 'nguk';
    $term = isset($_GET['term'])
        ? sanitize_text_field(wp_unslash($_GET['term']))
        : '';

    if (strlen($term) < 2) {
        wp_send_json_success(array());
    }

    $table = $direction === 'ukng'
        ? $wpdb->prefix . 'ukng_customers'
        : $wpdb->prefix . 'nguk_customers';
    $like = '%' . $wpdb->esc_like($term) . '%';

    $customers = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, customer_name, phone_number
             FROM $table
             WHERE customer_name LIKE %s
                OR phone_number LIKE %s
             ORDER BY is_favourite DESC, customer_name ASC
             LIMIT 20",
            $like,
            $like
        )
    );

    $results = array();

    foreach ((array) $customers as $customer) {
        $results[] = array(
            'id' => intval($customer->id),
            'name' => strtoupper($customer->customer_name),
            'phone' => isset($customer->phone_number) ? $customer->phone_number : ''
        );
    }

    wp_send_json_success($results);
}

function nguk_apply_default_branding() {
    if (get_option('nguk_branding_version') === '2.4') {
        return;
    }

    update_option('nguk_business_name', NGUK_COMPANY_NAME);
    update_option('nguk_business_logo', NGUK_DEFAULT_LOGO_URL);
    update_option('nguk_branding_version', '2.4');
}

/*
|--------------------------------------------------------------------------
| INCLUDE FILES
|--------------------------------------------------------------------------
*/

require_once NGUK_PLUGIN_PATH . 'includes/class-database.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-reminders.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-productivity.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-dashboard.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-ukng-dashboard.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-frontend-website.php';

/*
|--------------------------------------------------------------------------
| CREATE DATABASE TABLES
|--------------------------------------------------------------------------
*/

function nguk_activate_plugin() {
    nguk_setup_roles();
    nguk_apply_default_branding();
    NGUK_Database::create_tables();
    NGUK_Database::add_performance_indexes();
    NGUK_Frontend_Website::setup_site();
    update_option('nguk_db_version', NGUK_DB_VERSION);
    update_option('nguk_public_site_version', NGUK_Frontend_Website::VERSION);
}

register_activation_hook(__FILE__, 'nguk_activate_plugin');

add_action('plugins_loaded', 'nguk_setup_roles');
add_action('plugins_loaded', 'nguk_apply_default_branding');
add_action('plugins_loaded', array('NGUK_Database', 'maybe_update_tables'));
add_action('plugins_loaded', array('NGUK_Frontend_Website', 'init'));
add_action('init', array('NGUK_Frontend_Website', 'maybe_setup_site'), 20);
add_filter('login_redirect', 'nguk_transfer_staff_login_redirect', 10, 3);
add_filter('show_admin_bar', 'nguk_transfer_staff_admin_bar');
add_filter('update_footer', 'nguk_dashboard_footer_version', 999);
add_action('wp_ajax_nguk_customer_search', 'nguk_ajax_customer_search');
add_action('admin_init', 'nguk_restrict_transfer_staff_admin', 1);
add_action('admin_menu', 'nguk_transfer_staff_admin_menu', 999);

/*
|--------------------------------------------------------------------------
| LOAD DASHBOARD
|--------------------------------------------------------------------------
*/

function nguk_load_dashboard() {

    add_menu_page(
        'Daphkoy Limited',
        'Daphkoy Limited',
        NGUK_ACCESS_CAP,
        'nguk-transfer',
        'nguk_dashboard_page',
        'dashicons-money-alt',
        6
    );

    add_submenu_page(
        'nguk-transfer',
        'UK-Nigeria Transfer',
        'UK-Nigeria Transfer',
        NGUK_ACCESS_CAP,
        'admin.php?page=nguk-transfer&ukng_view=overview'
    );

}

add_action('admin_menu', 'nguk_load_dashboard');
add_action('admin_init', function() {

    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'nguk-transfer' &&
        isset($_GET['download_receipt'])
    ) {

        if (!current_user_can(NGUK_RECEIPT_CAP)) {
            wp_die('You do not have permission to download receipts.');
        }

        NGUK_Dashboard::download_receipt_pdf();

    }

    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'nguk-transfer' &&
        isset($_GET['ukng_view_receipt'])
    ) {

        if (!current_user_can(NGUK_RECEIPT_CAP)) {
            wp_die('You do not have permission to view receipts.');
        }

        UKNG_Dashboard::view_receipt();

    }

    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'nguk-transfer' &&
        isset($_GET['ukng_receipt_id'])
    ) {

        if (!current_user_can(NGUK_RECEIPT_CAP)) {
            wp_die('You do not have permission to download receipts.');
        }

        UKNG_Dashboard::download_receipt_pdf();

    }

});
add_action(
    'admin_post_nguk_download_receipt',
    array('NGUK_Dashboard', 'download_receipt_pdf')
);
add_action('admin_enqueue_scripts', function() {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    if ($page !== 'nguk-transfer') {
        return;
    }

    $needs_media = isset($_GET['create_customer']) ||
        isset($_GET['ukng_add_customer']) ||
        isset($_GET['view_customer']) ||
        isset($_GET['ukng_view_customer']);

    if (!$needs_media) {
        return;
    }

    wp_enqueue_media();
});

add_action('admin_enqueue_scripts', function($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    if ($page !== 'nguk-transfer') {
        return;
    }

    wp_enqueue_style(
        'nguk-enhancements',
        NGUK_PLUGIN_URL . 'assets/nguk-enhancements.css',
        array(),
        NGUK_PLUGIN_VERSION
    );

    $nguk_view = isset($_GET['nguk_view']) ? sanitize_key($_GET['nguk_view']) : '';
    $ukng_view = isset($_GET['ukng_view']) ? sanitize_key($_GET['ukng_view']) : '';
    $is_ukng = isset($_GET['ukng_view']) || isset($_GET['transfer_direction']) && $_GET['transfer_direction'] === 'ukng';
    $active_view = $is_ukng ? $ukng_view : $nguk_view;
    $needs_charts = $active_view === '' || $active_view === 'overview' || $active_view === 'reports';
    $script_dependencies = array('jquery');

    if ($needs_charts) {
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
            array(),
            '4.4.7',
            true
        );

        $script_dependencies[] = 'chart-js';
    }

    wp_enqueue_script(
        'nguk-enhancements',
        NGUK_PLUGIN_URL . 'assets/nguk-enhancements.js',
        $script_dependencies,
        NGUK_PLUGIN_VERSION,
        true
    );

    wp_localize_script(
        'nguk-enhancements',
        'ngukEnhancements',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'customerSearchNonce' => wp_create_nonce('nguk_customer_search')
        )
    );
});

/*
|--------------------------------------------------------------------------
| DASHBOARD PAGE
|--------------------------------------------------------------------------
*/

function nguk_dashboard_page() {

    if (
        (
            isset($_GET['transfer_direction']) &&
            $_GET['transfer_direction'] === 'ukng'
        ) ||
        isset($_GET['ukng_view']) ||
        isset($_GET['ukng_view_customer']) ||
        isset($_GET['ukng_view_receipt']) ||
        isset($_GET['ukng_receipt_id']) ||
        isset($_GET['ukng_add_customer']) ||
        isset($_GET['ukng_delete_customer']) ||
        isset($_GET['ukng_delete_transaction']) ||
        isset($_GET['ukng_clear_outstanding']) ||
        isset($_GET['ukng_delete_commission'])
    ) {

        $dashboard = new UKNG_Dashboard();
        $dashboard->dashboard_page();
        return;

    }

    $dashboard = new NGUK_Dashboard();
    $dashboard->dashboard_page();
}
