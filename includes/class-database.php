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

            beneficiary_name varchar(255) NULL,

            naira_amount float NOT NULL,

            pounds_amount float NOT NULL,

            profit float NOT NULL,

            buy_rate float NOT NULL,

            sell_rate float NOT NULL,

            nigeria_bank_details text NOT NULL,

            uk_bank_details text NOT NULL,

            status varchar(50) DEFAULT 'Pending',

            tracking_code varchar(50) NULL,

            status_updated_at datetime NULL,

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
/*
=========================================
BENEFICIARIES TABLE
=========================================
*/

$beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

$beneficiaries_sql = "CREATE TABLE $beneficiaries_table (

    id mediumint(9) NOT NULL AUTO_INCREMENT,

    customer_id mediumint(9) NOT NULL,

    beneficiary_name varchar(255) NOT NULL,

    bank_name varchar(255) NOT NULL,

    account_name varchar(255) NOT NULL,

    account_number varchar(255) NOT NULL,

    sort_code varchar(255) NULL,

    notes text NULL,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id)

) $charset_collate;";

dbDelta($beneficiaries_sql);

self::create_ukng_tables($charset_collate);

    }

    public static function maybe_update_tables() {

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'nguk_transactions';
        $customers_table = $wpdb->prefix . 'nguk_customers';

        self::create_ukng_tables($wpdb->get_charset_collate());

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $transactions_table
            )
        );

        if ($table_exists !== $transactions_table) {

            return;

        }

        $tracking_code_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'tracking_code'
            )
        );

        if (!$tracking_code_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD tracking_code varchar(50) NULL"
            );

        }

        $status_updated_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'status_updated_at'
            )
        );

        if (!$status_updated_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD status_updated_at datetime NULL"
            );

        }

        $kyc_documents_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $customers_table LIKE %s",
                'kyc_documents'
            )
        );

        if (!$kyc_documents_column) {

            $wpdb->query(
                "ALTER TABLE $customers_table ADD kyc_documents longtext NULL"
            );

        }

    }

    public static function generate_tracking_code() {

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'nguk_transactions';

        do {

            $code = 'NGUK-' . strtoupper(wp_generate_password(8, false, false));

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $transactions_table WHERE tracking_code = %s LIMIT 1",
                    $code
                )
            );

        } while ($exists);

        return $code;

    }

    public static function generate_ukng_tracking_code() {

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'ukng_transactions';

        do {

            $code = 'UKNG-' . strtoupper(wp_generate_password(8, false, false));

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $transactions_table WHERE tracking_code = %s LIMIT 1",
                    $code
                )
            );

        } while ($exists);

        return $code;

    }

    public static function create_ukng_tables($charset_collate = '') {

        global $wpdb;

        if (empty($charset_collate)) {

            $charset_collate = $wpdb->get_charset_collate();

        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $customers_table = $wpdb->prefix . 'ukng_customers';
        $beneficiaries_table = $wpdb->prefix . 'ukng_beneficiaries';
        $transactions_table = $wpdb->prefix . 'ukng_transactions';
        $commissions_table = $wpdb->prefix . 'ukng_commissions';

        $customers_sql = "CREATE TABLE $customers_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            customer_name varchar(255) NOT NULL,
            phone_number varchar(100) NULL,
            email varchar(255) NULL,
            address longtext NULL,
            notes longtext NULL,
            kyc_documents longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($customers_sql);

        $ukng_kyc_documents_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $customers_table LIKE %s",
                'kyc_documents'
            )
        );

        if (!$ukng_kyc_documents_column) {

            $wpdb->query(
                "ALTER TABLE $customers_table ADD kyc_documents longtext NULL"
            );

        }

        $beneficiaries_sql = "CREATE TABLE $beneficiaries_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            customer_id mediumint(9) NOT NULL,
            beneficiary_name varchar(255) NOT NULL,
            bank_name varchar(255) NOT NULL,
            account_name varchar(255) NOT NULL,
            account_number varchar(255) NOT NULL,
            notes text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($beneficiaries_sql);

        $transactions_sql = "CREATE TABLE $transactions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            customer_id mediumint(9) NOT NULL,
            beneficiary_id mediumint(9) NOT NULL,
            customer_name varchar(255) NOT NULL,
            beneficiary_name varchar(255) NOT NULL,
            pounds_sent float NOT NULL,
            commission_amount float NOT NULL,
            total_paid float NOT NULL,
            exchange_rate float NOT NULL,
            naira_amount float NOT NULL,
            beneficiary_bank_details text NOT NULL,
            status varchar(50) DEFAULT 'Pending',
            outstanding_status varchar(20) DEFAULT 'Outstanding',
            amount_paid float DEFAULT 0,
            tracking_code varchar(50) NULL,
            status_updated_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($transactions_sql);

        $outstanding_status_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'outstanding_status'
            )
        );

        if (!$outstanding_status_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD outstanding_status varchar(20) DEFAULT 'Outstanding'"
            );

        }

        $amount_paid_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'amount_paid'
            )
        );

        if (!$amount_paid_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD amount_paid float DEFAULT 0"
            );

        }

        $wpdb->query(
            "UPDATE $transactions_table SET amount_paid = 0 WHERE amount_paid IS NULL"
        );

        $wpdb->query(
            "UPDATE $transactions_table SET outstanding_status = 'Outstanding' WHERE outstanding_status IS NULL OR outstanding_status = ''"
        );

        $commissions_sql = "CREATE TABLE $commissions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            min_amount float NOT NULL,
            max_amount float NULL,
            commission_type varchar(20) NOT NULL DEFAULT 'fixed',
            commission_value float NOT NULL,
            label varchar(255) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($commissions_sql);

        $commission_count = intval(
            $wpdb->get_var("SELECT COUNT(*) FROM $commissions_table")
        );

        if ($commission_count === 0) {

            $defaults = array(
                array(1, 100, 'fixed', 5, 'GBP 1 - GBP 100'),
                array(101, 200, 'fixed', 10, 'GBP 101 - GBP 200'),
                array(201, 300, 'fixed', 15, 'GBP 201 - GBP 300'),
                array(301, 400, 'fixed', 20, 'GBP 301 - GBP 400'),
                array(401, 500, 'fixed', 22, 'GBP 401 - GBP 500'),
                array(501, 600, 'fixed', 25, 'GBP 501 - GBP 600'),
                array(601, 700, 'fixed', 27, 'GBP 601 - GBP 700'),
                array(701, 899, 'fixed', 28, 'GBP 701 - GBP 899'),
                array(900, 1000, 'fixed', 30, 'GBP 900 - GBP 1,000'),
                array(1001, null, 'percent', 3, 'GBP 1,001 and above')
            );

            foreach ($defaults as $default) {

                $wpdb->insert(
                    $commissions_table,
                    array(
                        'min_amount' => $default[0],
                        'max_amount' => $default[1],
                        'commission_type' => $default[2],
                        'commission_value' => $default[3],
                        'label' => $default[4]
                    )
                );

            }

        }

    }

}
