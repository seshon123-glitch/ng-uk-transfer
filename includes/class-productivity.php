<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Productivity {

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'handle_favourite_toggle'));
        add_action('admin_init', array(__CLASS__, 'handle_export'));
    }

    public static function customer_table($direction) {
        global $wpdb;
        return $wpdb->prefix . ($direction === 'ukng' ? 'ukng_customers' : 'nguk_customers');
    }

    public static function transaction_table($direction) {
        global $wpdb;
        return $wpdb->prefix . ($direction === 'ukng' ? 'ukng_transactions' : 'nguk_transactions');
    }

    public static function beneficiary_table($direction) {
        global $wpdb;
        return $wpdb->prefix . ($direction === 'ukng' ? 'ukng_beneficiaries' : 'nguk_beneficiaries');
    }

    public static function handle_favourite_toggle() {
        if (!isset($_GET['nguk_toggle_favourite'])) {
            return;
        }

        if (!current_user_can(NGUK_CUSTOMER_CAP)) {
            wp_die('You do not have permission to manage favourite customers.');
        }

        $customer_id = intval($_GET['nguk_toggle_favourite']);
        $direction = isset($_GET['direction']) && $_GET['direction'] === 'ukng' ? 'ukng' : 'nguk';

        if (
            $customer_id <= 0 ||
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce($_GET['_wpnonce'], 'nguk_toggle_favourite_' . $direction . '_' . $customer_id)
        ) {
            wp_die('Favourite request could not be verified.');
        }

        global $wpdb;
        $table = self::customer_table($direction);
        $current = intval($wpdb->get_var($wpdb->prepare("SELECT is_favourite FROM $table WHERE id = %d", $customer_id)));

        $wpdb->update(
            $table,
            array('is_favourite' => $current ? 0 : 1),
            array('id' => $customer_id),
            array('%d'),
            array('%d')
        );

        wp_safe_redirect(remove_query_arg(array('nguk_toggle_favourite', 'direction', '_wpnonce')));
        exit;
    }

    public static function favourite_url($direction, $customer_id) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'nguk_toggle_favourite' => intval($customer_id),
                    'direction' => $direction
                )
            ),
            'nguk_toggle_favourite_' . $direction . '_' . intval($customer_id)
        );
    }

    public static function star_button($direction, $customer) {
        $is_favourite = !empty($customer->is_favourite);
        $label = $is_favourite ? 'Remove from favourites' : 'Add to favourites';

        return '<a class="nguk-favourite-star ' . ($is_favourite ? 'is-favourite' : '') . '" title="' . esc_attr($label) . '" href="' . esc_url(self::favourite_url($direction, $customer->id)) . '">&#9733;</a>';
    }

    public static function render_favourites_panel($direction, $panel_class = '') {
        if (!current_user_can(NGUK_CUSTOMER_CAP)) {
            return;
        }

        global $wpdb;
        $customers_table = self::customer_table($direction);
        $transactions_table = self::transaction_table($direction);
        $search_key = $direction . '_favourite_search';
        $search = isset($_GET[$search_key]) ? sanitize_text_field(wp_unslash($_GET[$search_key])) : '';
        $where = 'WHERE c.is_favourite = 1';
        $params = array();

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (c.customer_name LIKE %s OR c.phone_number LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($direction === 'ukng') {
            $sql = "SELECT c.id, c.customer_name, c.phone_number, c.is_favourite, MAX(t.created_at) as last_transaction_date
                    FROM $customers_table c
                    LEFT JOIN $transactions_table t ON t.customer_id = c.id
                    $where
                    GROUP BY c.id
                    ORDER BY c.customer_name ASC
                    LIMIT 50";
        } else {
            $sql = "SELECT c.id, c.customer_name, c.phone_number, c.is_favourite, MAX(t.created_at) as last_transaction_date
                    FROM $customers_table c
                    LEFT JOIN $transactions_table t ON t.customer_name = c.customer_name
                    $where
                    GROUP BY c.id
                    ORDER BY c.customer_name ASC
                    LIMIT 50";
        }

        $customers = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        $view_param = $direction === 'ukng' ? 'ukng_view' : 'nguk_view';
        $profile_arg = $direction === 'ukng' ? 'ukng_view_customer' : 'view_customer';
        ?>
        <div class="<?php echo esc_attr($panel_class); ?>" data-nguk-panel="favourites" style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">
            <h2>Favourite Customers</h2>
            <form method="get" style="margin:15px 0 20px;">
                <input type="hidden" name="page" value="nguk-transfer">
                <input type="hidden" name="<?php echo esc_attr($view_param); ?>" value="favourites">
                <input type="search" name="<?php echo esc_attr($search_key); ?>" class="regular-text" placeholder="Search favourite customers" value="<?php echo esc_attr($search); ?>">
                <input type="submit" class="button" value="Search">
            </form>
            <table class="widefat striped">
                <thead><tr><th></th><th>Customer Name</th><th>Phone Number</th><th>Last Transaction Date</th><th>Profile</th></tr></thead>
                <tbody>
                    <?php if ($customers) { ?>
                        <?php foreach ($customers as $customer) { ?>
                            <tr>
                                <td><?php echo wp_kses_post(self::star_button($direction, $customer)); ?></td>
                                <td class="nguk-name-strong"><?php echo esc_html(strtoupper($customer->customer_name)); ?></td>
                                <td><?php echo esc_html($customer->phone_number); ?></td>
                                <td><?php echo $customer->last_transaction_date ? esc_html(date_i18n('d M Y', strtotime($customer->last_transaction_date))) : 'No transactions'; ?></td>
                                <td><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&' . $profile_arg . '=' . intval($customer->id))); ?>">Open Profile</a></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr><td colspan="5">No favourite customers found.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function repeat_url($direction, $transaction_id) {
        if ($direction === 'ukng') {
            return admin_url('admin.php?page=nguk-transfer&ukng_view=payments&ukng_repeat_transaction=' . intval($transaction_id));
        }

        return admin_url('admin.php?page=nguk-transfer&nguk_view=payments&repeat_transaction=' . intval($transaction_id));
    }

    public static function render_history($direction, $customer) {
        global $wpdb;

        $transactions_table = self::transaction_table($direction);
        $customer_id = intval($customer->id);
        $page_key = $direction . '_history_page';
        $search_key = $direction . '_history_search';
        $range_key = $direction . '_history_range';
        $page = isset($_GET[$page_key]) ? max(1, intval($_GET[$page_key])) : 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        $search = isset($_GET[$search_key]) ? sanitize_text_field(wp_unslash($_GET[$search_key])) : '';
        $range = isset($_GET[$range_key]) ? sanitize_key(wp_unslash($_GET[$range_key])) : 'all';
        $where = array();
        $params = array();

        if ($direction === 'ukng') {
            $where[] = 'customer_id = %d';
            $params[] = $customer_id;
        } else {
            $where[] = 'customer_name = %s';
            $params[] = $customer->customer_name;
        }

        if ($range === '30') {
            $where[] = 'created_at >= %s';
            $params[] = date('Y-m-d 00:00:00', current_time('timestamp') - (30 * DAY_IN_SECONDS));
        } elseif ($range === '90') {
            $where[] = 'created_at >= %s';
            $params[] = date('Y-m-d 00:00:00', current_time('timestamp') - (90 * DAY_IN_SECONDS));
        } elseif ($range === '12m') {
            $where[] = 'created_at >= %s';
            $params[] = date('Y-m-d 00:00:00', strtotime('-12 months', current_time('timestamp')));
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(beneficiary_name LIKE %s OR status LIKE %s OR tracking_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $transactions_table WHERE $where_sql", $params)));
        $pages = max(1, ceil($count / $per_page));
        $page = min($page, $pages);
        $offset = ($page - 1) * $per_page;

        if ($direction === 'ukng') {
            $summary_sql = "SELECT COUNT(*) total_transactions, COALESCE(SUM(pounds_sent),0) total_sent, COALESCE(SUM(naira_amount),0) total_received, COALESCE(SUM(commission_amount),0) total_commission, MIN(created_at) first_transaction, MAX(created_at) last_transaction FROM $transactions_table WHERE $where_sql";
            $list_sql = "SELECT id, created_at, beneficiary_name, pounds_sent amount_sent, naira_amount amount_received, commission_amount commission, status, tracking_code FROM $transactions_table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        } else {
            $summary_sql = "SELECT COUNT(*) total_transactions, COALESCE(SUM(naira_amount),0) total_sent, COALESCE(SUM(pounds_amount),0) total_received, COALESCE(SUM(CASE WHEN sell_rate > 0 AND buy_rate > 0 THEN (naira_amount / sell_rate) - (naira_amount / buy_rate) ELSE profit END),0) total_commission, MIN(created_at) first_transaction, MAX(created_at) last_transaction FROM $transactions_table WHERE $where_sql";
            $list_sql = "SELECT id, created_at, beneficiary_name, naira_amount amount_sent, pounds_amount amount_received, CASE WHEN sell_rate > 0 AND buy_rate > 0 THEN (naira_amount / sell_rate) - (naira_amount / buy_rate) ELSE profit END commission, status, tracking_code FROM $transactions_table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        }

        $summary = $wpdb->get_row($wpdb->prepare($summary_sql, $params));
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, array($per_page, $offset))));
        $view_arg = $direction === 'ukng' ? 'ukng_view_customer' : 'view_customer';
        ?>
        <hr style="margin:35px 0;">
        <h2>Transaction History</h2>
        <div class="nguk-history-summary">
            <div><span>Total Transactions</span><strong><?php echo esc_html(number_format(intval($summary->total_transactions))); ?></strong></div>
            <div><span>Total Amount Sent</span><strong><?php echo esc_html(number_format(floatval($summary->total_sent), 2)); ?></strong></div>
            <div><span>Total Amount Received</span><strong><?php echo esc_html(number_format(floatval($summary->total_received), 2)); ?></strong></div>
            <div><span>Total Commission Generated</span><strong>GBP <?php echo esc_html(number_format(floatval($summary->total_commission), 2)); ?></strong></div>
            <div><span>First Transaction Date</span><strong><?php echo $summary->first_transaction ? esc_html(date_i18n('d M Y', strtotime($summary->first_transaction))) : '-'; ?></strong></div>
            <div><span>Last Transaction Date</span><strong><?php echo $summary->last_transaction ? esc_html(date_i18n('d M Y', strtotime($summary->last_transaction))) : '-'; ?></strong></div>
        </div>
        <form method="get" style="margin:15px 0;">
            <input type="hidden" name="page" value="nguk-transfer">
            <input type="hidden" name="<?php echo esc_attr($view_arg); ?>" value="<?php echo intval($customer->id); ?>">
            <input type="search" name="<?php echo esc_attr($search_key); ?>" class="regular-text" placeholder="Search history" value="<?php echo esc_attr($search); ?>">
            <select name="<?php echo esc_attr($range_key); ?>">
                <option value="all" <?php selected($range, 'all'); ?>>All Time</option>
                <option value="30" <?php selected($range, '30'); ?>>Last 30 Days</option>
                <option value="90" <?php selected($range, '90'); ?>>Last 90 Days</option>
                <option value="12m" <?php selected($range, '12m'); ?>>Last 12 Months</option>
            </select>
            <input type="submit" class="button" value="Filter">
        </form>
        <table class="widefat striped">
            <thead><tr><th>Date</th><th>Transaction Type</th><th>Amount</th><th>Beneficiary</th><th>Status</th><th>Tracking Code</th><th>Repeat</th></tr></thead>
            <tbody>
                <?php if ($rows) { ?>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('d M Y', strtotime($row->created_at))); ?></td>
                            <td><?php echo $direction === 'ukng' ? 'UK-NIG' : 'NIG-UK'; ?></td>
                            <td><?php echo esc_html(number_format(floatval($row->amount_sent), 2)); ?></td>
                            <td><?php echo esc_html(strtoupper($row->beneficiary_name)); ?></td>
                            <td><?php echo esc_html($row->status); ?></td>
                            <td><?php echo esc_html($row->tracking_code); ?></td>
                            <td><a class="button" href="<?php echo esc_url(self::repeat_url($direction, $row->id)); ?>">Repeat Transaction</a></td>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr><td colspan="7">No transaction history found.</td></tr>
                <?php } ?>
            </tbody>
        </table>
        <?php if ($pages > 1) { ?>
            <p style="margin-top:15px;">
                <?php for ($page_number = 1; $page_number <= $pages; $page_number++) { ?>
                    <?php
                    $url = add_query_arg(
                        array(
                            'page' => 'nguk-transfer',
                            $view_arg => intval($customer->id),
                            $page_key => $page_number,
                            $search_key => $search,
                            $range_key => $range
                        ),
                        admin_url('admin.php')
                    );
                    ?>
                    <a class="button <?php echo $page_number == $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($page_number); ?></a>
                <?php } ?>
            </p>
        <?php } ?>
        <?php
    }

    public static function handle_export() {
        if (!isset($_GET['nguk_export'])) {
            return;
        }

        $type = sanitize_key($_GET['nguk_export']);
        $direction = isset($_GET['direction']) && $_GET['direction'] === 'ukng' ? 'ukng' : 'nguk';
        $format = isset($_GET['format']) && $_GET['format'] === 'xlsx' ? 'xlsx' : 'csv';

        if ($type === 'reports' && !current_user_can(NGUK_REPORTS_CAP)) {
            wp_die('You do not have permission to export reports.');
        }

        if ($type === 'customers' && !current_user_can(NGUK_SETTINGS_CAP)) {
            wp_die('You do not have permission to export all customers.');
        }

        if ($type === 'transactions' && !current_user_can(NGUK_PROCESS_CAP)) {
            wp_die('You do not have permission to export transactions.');
        }

        $rows = self::export_rows($direction, $type);
        self::send_export($rows, $direction . '-' . $type, $format);
    }

    private static function export_rows($direction, $type) {
        global $wpdb;

        $customers_table = self::customer_table($direction);
        $transactions_table = self::transaction_table($direction);
        $from_date = isset($_GET['from_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '';
        $to_date = isset($_GET['to_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '';
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $customer = isset($_GET['customer']) ? sanitize_text_field(wp_unslash($_GET['customer'])) : '';
        $where = array('1=1');
        $params = array();

        if ($from_date !== '') {
            $where[] = 'created_at >= %s';
            $params[] = $from_date . ' 00:00:00';
        }

        if ($to_date !== '') {
            $where[] = 'created_at <= %s';
            $params[] = $to_date . ' 23:59:59';
        }

        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if ($customer !== '') {
            $where[] = 'customer_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($customer) . '%';
        }

        $where_sql = implode(' AND ', $where);

        if ($type === 'customers') {
            $customer_where = '';
            $customer_params = array();

            if ($customer !== '') {
                $customer_where = 'WHERE c.customer_name LIKE %s OR c.phone_number LIKE %s';
                $customer_params[] = '%' . $wpdb->esc_like($customer) . '%';
                $customer_params[] = '%' . $wpdb->esc_like($customer) . '%';
            }

            if ($direction === 'ukng') {
                $sql = "SELECT c.customer_name 'Customer Name', c.phone_number 'Phone Number', c.address 'Address', c.created_at 'Registration Date', COUNT(t.id) 'Total Transactions' FROM $customers_table c LEFT JOIN $transactions_table t ON t.customer_id = c.id $customer_where GROUP BY c.id ORDER BY c.customer_name ASC";
                return $customer_params ? $wpdb->get_results($wpdb->prepare($sql, $customer_params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
            }

            $sql = "SELECT c.customer_name 'Customer Name', c.phone_number 'Phone Number', c.address 'Address', c.created_at 'Registration Date', COUNT(t.id) 'Total Transactions' FROM $customers_table c LEFT JOIN $transactions_table t ON t.customer_name = c.customer_name $customer_where GROUP BY c.id ORDER BY c.customer_name ASC";
            return $customer_params ? $wpdb->get_results($wpdb->prepare($sql, $customer_params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        }

        if ($type === 'reports') {
            if ($direction === 'ukng') {
                $sql = "SELECT DATE_FORMAT(created_at, '%M %Y') 'Month', SUM(pounds_sent) 'Monthly Turnover', SUM(commission_amount) 'Monthly Profit', COUNT(*) 'Monthly Transaction Volume' FROM $transactions_table WHERE $where_sql GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY created_at DESC";
                return $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
            }

            $sql = "SELECT DATE_FORMAT(created_at, '%M %Y') 'Month', SUM(pounds_amount) 'Monthly Turnover', SUM(CASE WHEN sell_rate > 0 AND buy_rate > 0 THEN (naira_amount / sell_rate) - (naira_amount / buy_rate) ELSE profit END) 'Monthly Profit', COUNT(*) 'Monthly Transaction Volume' FROM $transactions_table WHERE $where_sql GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY created_at DESC";
            return $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        }

        if ($direction === 'ukng') {
            $sql = "SELECT CONCAT('UKNG', id + 9000) 'Transaction ID', created_at 'Date', customer_name 'Customer', beneficiary_name 'Beneficiary', 'UK-NIG' 'Transaction Type', pounds_sent 'Amount', commission_amount 'Commission', status 'Status', tracking_code 'Tracking Code' FROM $transactions_table WHERE $where_sql ORDER BY id DESC LIMIT 5000";
            return $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        }

        $sql = "SELECT CONCAT('NGUK', id + 6700) 'Transaction ID', created_at 'Date', customer_name 'Customer', beneficiary_name 'Beneficiary', 'NIG-UK' 'Transaction Type', naira_amount 'Amount', profit 'Commission', status 'Status', tracking_code 'Tracking Code' FROM $transactions_table WHERE $where_sql ORDER BY id DESC LIMIT 5000";
        return $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    }

    private static function send_export($rows, $filename, $format) {
        if ($format === 'xlsx' && class_exists('ZipArchive')) {
            self::send_xlsx($rows, $filename);
            return;
        }

        self::send_csv($rows, $filename);
    }

    private static function send_csv($rows, $filename) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '.csv"');

        $output = fopen('php://output', 'w');

        if (!empty($rows)) {
            fputcsv($output, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }

    private static function send_xlsx($rows, $filename) {
        $upload = wp_upload_dir();
        $path = trailingslashit($upload['basedir']) . sanitize_file_name($filename) . '-' . time() . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $row_number = 1;

        if (!empty($rows)) {
            $sheet .= self::xlsx_row(array_keys($rows[0]), $row_number++);

            foreach ($rows as $row) {
                $sheet .= self::xlsx_row(array_values($row), $row_number++);
            }
        }

        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '.xlsx"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        wp_delete_file($path);
        exit;
    }

    private static function xlsx_row($values, $row_number) {
        $xml = '<row r="' . intval($row_number) . '">';

        foreach ($values as $value) {
            $xml .= '<c t="inlineStr"><is><t>' . esc_html((string) $value) . '</t></is></c>';
        }

        return $xml . '</row>';
    }
}

NGUK_Productivity::init();
