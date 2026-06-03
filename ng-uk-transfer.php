<?php
/*
Plugin Name: NG-UK Money Transfer
Plugin URI: https://daphkoy.com
Description: Nigeria to United Kingdom Money Transfer Plugin
Version: 2.1
Author: Beejay
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

/*
|--------------------------------------------------------------------------
| INCLUDE FILES
|--------------------------------------------------------------------------
*/

require_once NGUK_PLUGIN_PATH . 'includes/class-database.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-reminders.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-dashboard.php';
require_once NGUK_PLUGIN_PATH . 'includes/class-ukng-dashboard.php';

/*
|--------------------------------------------------------------------------
| CREATE DATABASE TABLES
|--------------------------------------------------------------------------
*/

register_activation_hook(__FILE__, array('NGUK_Database', 'create_tables'));

add_action('plugins_loaded', array('NGUK_Database', 'maybe_update_tables'));

/*
|--------------------------------------------------------------------------
| LOAD DASHBOARD
|--------------------------------------------------------------------------
*/

function nguk_load_dashboard() {

    add_menu_page(
        'NG-UK Transfer',
        'NG-UK Transfer',
        'manage_options',
        'nguk-transfer',
        'nguk_dashboard_page',
        'dashicons-money-alt',
        6
    );

    add_submenu_page(
        'nguk-transfer',
        'UK-Nigeria Transfer',
        'UK-Nigeria Transfer',
        'manage_options',
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

        NGUK_Dashboard::download_receipt_pdf();

    }

    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'nguk-transfer' &&
        isset($_GET['ukng_view_receipt'])
    ) {

        UKNG_Dashboard::view_receipt();

    }

    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'nguk-transfer' &&
        isset($_GET['ukng_receipt_id'])
    ) {

        UKNG_Dashboard::download_receipt_pdf();

    }

});
add_action(
    'admin_post_nguk_download_receipt',
    array('NGUK_Dashboard', 'download_receipt_pdf')
);
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_media();
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
