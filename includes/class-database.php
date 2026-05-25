<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Database {

    public static function create_tables() {

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $transactions_table = $wpdb->prefix . 'nguk_transactions';

        $customers_table = $wpdb->prefix . 'nguk_customers';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        /*
        =========================================
        TRANSACTIONS TABLE
        =========================================
        */

        $transactions_sql = "CREATE TABLE $transactions_table (

            id mediumint(9) NOT NULL AUTO_INCREMENT,

            customer_name varchar(255) NOT NULL,

            naira_amount float NOT NULL,

            pounds_amount float NOT NULL,

            profit float NOT NULL,

            buy_rate float NOT NULL,

            sell_rate float NOT NULL,

            nigeria_bank_details text NOT NULL,

            uk_bank_details text NOT NULL,

            status varchar(50) DEFAULT 'Pending',

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id)

        ) $charset_collate;";

        dbDelta($transactions_sql);

        /*
        =========================================
        CUSTOMERS TABLE
        =========================================
        */

        $customers_sql = "CREATE TABLE $customers_table (

            id mediumint(9) NOT NULL AUTO_INCREMENT,

            customer_name varchar(255) NOT NULL,

            phone_number varchar(100) NULL,

            address longtext NULL,

            notes longtext NULL,

            nigeria_bank_details longtext NOT NULL,

            uk_bank_details longtext NOT NULL,

            kyc_documents longtext NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id)

        ) $charset_collate;";

        dbDelta($customers_sql);
        /*
=========================================
BANK ACCOUNTS TABLE
=========================================
*/

$bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

$bank_accounts_sql = "CREATE TABLE $bank_accounts_table (

    id mediumint(9) NOT NULL AUTO_INCREMENT,

    account_type varchar(50) NOT NULL,

    bank_name varchar(255) NOT NULL,

    account_name varchar(255) NOT NULL,

    account_number varchar(255) NOT NULL,

    extra_details text NULL,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id)

) $charset_collate;";

dbDelta($bank_accounts_sql);

    }

}