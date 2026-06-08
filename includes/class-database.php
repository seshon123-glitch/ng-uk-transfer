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

            is_favourite tinyint(1) NOT NULL DEFAULT 0,

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
self::create_reminders_table($charset_collate);

    }

    public static function maybe_update_tables() {

        global $wpdb;

        $current_db_version = defined('NGUK_DB_VERSION') ? NGUK_DB_VERSION : '2.8';
        $installed_db_version = get_option('nguk_db_version', '0');

        if (version_compare($installed_db_version, $current_db_version, '>=')) {
            return;
        }

        $transactions_table = $wpdb->prefix . 'nguk_transactions';
        $customers_table = $wpdb->prefix . 'nguk_customers';

        self::create_ukng_tables($wpdb->get_charset_collate());
        self::create_reminders_table($wpdb->get_charset_collate());

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

        $nguk_favourite_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $customers_table LIKE %s",
                'is_favourite'
            )
        );

        if (!$nguk_favourite_column) {

            $wpdb->query(
                "ALTER TABLE $customers_table ADD is_favourite tinyint(1) NOT NULL DEFAULT 0"
            );

        }

        self::add_performance_indexes();

        update_option('nguk_db_version', $current_db_version);

    }

    private static function index_exists($table, $index_name) {

        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM $table WHERE Key_name = %s",
                $index_name
            )
        );

        return !empty($exists);

    }

    private static function table_exists($table) {

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        ) === $table;

    }

    private static function add_index_if_missing($table, $index_name, $columns) {

        global $wpdb;

        if (!self::table_exists($table)) {
            return;
        }

        if (self::index_exists($table, $index_name)) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE $table ADD INDEX $index_name ($columns)"
        );

    }

    public static function add_performance_indexes() {

        global $wpdb;

        $nguk_transactions = $wpdb->prefix . 'nguk_transactions';
        $nguk_customers = $wpdb->prefix . 'nguk_customers';
        $nguk_beneficiaries = $wpdb->prefix . 'nguk_beneficiaries';
        $ukng_transactions = $wpdb->prefix . 'ukng_transactions';
        $ukng_customers = $wpdb->prefix . 'ukng_customers';
        $ukng_beneficiaries = $wpdb->prefix . 'ukng_beneficiaries';

        self::add_index_if_missing($nguk_transactions, 'created_at', 'created_at');
        self::add_index_if_missing($nguk_transactions, 'status', 'status');
        self::add_index_if_missing($nguk_transactions, 'tracking_code', 'tracking_code');
        self::add_index_if_missing($nguk_transactions, 'customer_name', 'customer_name(191)');
        self::add_index_if_missing($nguk_transactions, 'beneficiary_name', 'beneficiary_name(191)');

        self::add_index_if_missing($nguk_customers, 'customer_name', 'customer_name(191)');
        self::add_index_if_missing($nguk_customers, 'phone_number', 'phone_number');
        self::add_index_if_missing($nguk_customers, 'is_favourite', 'is_favourite');

        self::add_index_if_missing($nguk_beneficiaries, 'customer_id', 'customer_id');
        self::add_index_if_missing($nguk_beneficiaries, 'beneficiary_name', 'beneficiary_name(191)');

        self::add_index_if_missing($ukng_transactions, 'created_at', 'created_at');
        self::add_index_if_missing($ukng_transactions, 'status', 'status');
        self::add_index_if_missing($ukng_transactions, 'outstanding_status', 'outstanding_status');
        self::add_index_if_missing($ukng_transactions, 'customer_id', 'customer_id');
        self::add_index_if_missing($ukng_transactions, 'beneficiary_id', 'beneficiary_id');
        self::add_index_if_missing($ukng_transactions, 'tracking_code', 'tracking_code');
        self::add_index_if_missing($ukng_transactions, 'customer_name', 'customer_name(191)');
        self::add_index_if_missing($ukng_transactions, 'beneficiary_name', 'beneficiary_name(191)');
        self::add_index_if_missing($ukng_transactions, 'outstanding_deleted_at', 'outstanding_deleted_at');

        self::add_index_if_missing($ukng_customers, 'customer_name', 'customer_name(191)');
        self::add_index_if_missing($ukng_customers, 'phone_number', 'phone_number');
        self::add_index_if_missing($ukng_customers, 'is_favourite', 'is_favourite');

        self::add_index_if_missing($ukng_beneficiaries, 'customer_id', 'customer_id');
        self::add_index_if_missing($ukng_beneficiaries, 'beneficiary_name', 'beneficiary_name(191)');

    }

    public static function monthly_cache_key($direction = 'nguk') {

        return $direction === 'ukng'
            ? 'nguk_monthly_stats_ukng_v1'
            : 'nguk_monthly_stats_nguk_v1';

    }

    public static function clear_monthly_cache($direction = '') {

        if ($direction === 'nguk' || $direction === '') {
            delete_transient(self::monthly_cache_key('nguk'));
        }

        if ($direction === 'ukng' || $direction === '') {
            delete_transient(self::monthly_cache_key('ukng'));
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

    public static function create_reminders_table($charset_collate = '') {

        if (class_exists('NGUK_Reminders')) {
            NGUK_Reminders::create_table($charset_collate);
        }

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
            is_favourite tinyint(1) NOT NULL DEFAULT 0,
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
            auto_commission_amount float NOT NULL DEFAULT 0,
            commission_amount float NOT NULL,
            total_paid float NOT NULL,
            exchange_rate float NOT NULL,
            naira_amount float NOT NULL,
            beneficiary_bank_details text NOT NULL,
            status varchar(50) DEFAULT 'Pending',
            outstanding_status varchar(20) DEFAULT 'Outstanding',
            amount_paid float DEFAULT 0,
            outstanding_deleted_at datetime NULL,
            outstanding_previous_status varchar(20) NULL,
            tracking_code varchar(50) NULL,
            status_updated_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($transactions_sql);

        $auto_commission_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'auto_commission_amount'
            )
        );

        if (!$auto_commission_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD auto_commission_amount float NOT NULL DEFAULT 0 AFTER pounds_sent"
            );

            $wpdb->query(
                "UPDATE $transactions_table SET auto_commission_amount = commission_amount WHERE auto_commission_amount = 0"
            );

        }

        $ukng_favourite_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $customers_table LIKE %s",
                'is_favourite'
            )
        );

        if (!$ukng_favourite_column) {

            $wpdb->query(
                "ALTER TABLE $customers_table ADD is_favourite tinyint(1) NOT NULL DEFAULT 0"
            );

        }

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

        $outstanding_deleted_at_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'outstanding_deleted_at'
            )
        );

        if (!$outstanding_deleted_at_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD outstanding_deleted_at datetime NULL"
            );

        }

        $outstanding_previous_status_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'outstanding_previous_status'
            )
        );

        if (!$outstanding_previous_status_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD outstanding_previous_status varchar(20) NULL"
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

    public static function cleanup_ukng_outstanding_recycle_bin() {

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'ukng_transactions';

        if (!self::table_exists($transactions_table)) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $transactions_table
                 SET outstanding_status = 'Cleared',
                     amount_paid = total_paid,
                     outstanding_deleted_at = NULL,
                     outstanding_previous_status = NULL
                 WHERE outstanding_status = 'Deleted'
                   AND outstanding_deleted_at IS NOT NULL
                   AND outstanding_deleted_at <= %s",
                $cutoff
            )
        );

        if ($wpdb->rows_affected > 0) {
            self::clear_monthly_cache('ukng');
        }

    }

}
