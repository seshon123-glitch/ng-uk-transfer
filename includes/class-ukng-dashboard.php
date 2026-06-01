<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('NGUK_Reminders') && defined('NGUK_PLUGIN_PATH')) {
    require_once NGUK_PLUGIN_PATH . 'includes/class-reminders.php';
}

class UKNG_Dashboard {

    private static function upload_kyc_documents($field_name = 'kyc_documents') {

        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
            return array();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $documents = array();
        $files = $_FILES[$field_name];

        foreach ((array) $files['name'] as $index => $name) {
            if (empty($name) || !isset($files['error'][$index]) || intval($files['error'][$index]) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = array(
                'name' => sanitize_file_name($name),
                'type' => isset($files['type'][$index]) ? $files['type'][$index] : '',
                'tmp_name' => isset($files['tmp_name'][$index]) ? $files['tmp_name'][$index] : '',
                'error' => isset($files['error'][$index]) ? $files['error'][$index] : 0,
                'size' => isset($files['size'][$index]) ? $files['size'][$index] : 0
            );

            $upload = wp_handle_upload($file, array('test_form' => false));

            if (!empty($upload['url'])) {
                $documents[] = array(
                    'name' => sanitize_text_field($name),
                    'url' => esc_url_raw($upload['url'])
                );
            }
        }

        return $documents;

    }

    private static function render_kyc_documents($documents_json) {

        $documents = json_decode((string) $documents_json, true);

        if (!is_array($documents) || empty($documents)) {
            echo '<span>No documents</span>';
            return;
        }

        foreach ($documents as $index => $document) {
            if (empty($document['url'])) {
                continue;
            }

            $name = !empty($document['name'])
                ? $document['name']
                : 'Document ' . ($index + 1);

            echo '<a class="button" style="margin:2px;" target="_blank" rel="noopener noreferrer" href="' . esc_url($document['url']) . '">' . esc_html($name) . '</a>';
        }

    }

    private static function business_logo_url() {

        $business_logo = trim((string) get_option('nguk_business_logo'));

        if ($business_logo === '') {
            return '';
        }

        if (ctype_digit($business_logo)) {
            $attachment_url = wp_get_attachment_image_url(intval($business_logo), 'full');
            return $attachment_url ? $attachment_url : '';
        }

        if (preg_match('/localhost|127\.0\.0\.1|local sites/i', $business_logo)) {
            return '';
        }

        return esc_url_raw($business_logo);

    }

    private static function qr_code_url($lines) {

        $data = implode("\n", $lines);

        return 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . rawurlencode($data);

    }

    public static function download_receipt_pdf() {

        if (!isset($_GET['ukng_receipt_id'])) {
            return;
        }

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'ukng_transactions';
        $transaction_id = intval($_GET['ukng_receipt_id']);

        $transaction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $transactions_table WHERE id = %d",
                $transaction_id
            )
        );

        if (!$transaction) {
            wp_die('Receipt not found.');
        }

        $receipt_number = 'UKNG' . (9000 + intval($transaction->id));
        $html = self::receipt_html($transaction, $receipt_number, false);

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $receipt_number . '-receipt.html"');
        header('Content-Length: ' . strlen($html));

        echo $html;
        exit;

    }

    public static function view_receipt() {

        if (!isset($_GET['ukng_view_receipt'])) {
            return;
        }

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'ukng_transactions';
        $transaction_id = intval($_GET['ukng_view_receipt']);

        $transaction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $transactions_table WHERE id = %d",
                $transaction_id
            )
        );

        if (!$transaction) {
            wp_die('Receipt not found.');
        }

        $receipt_number = 'UKNG' . (9000 + intval($transaction->id));

        echo self::receipt_html($transaction, $receipt_number, true);
        exit;

    }

    private static function receipt_html($transaction, $receipt_number, $show_actions) {

        $business_logo = self::business_logo_url();
        $business_name = get_option('nguk_business_name');
        $business_phone = get_option('nguk_business_phone');
        $business_email = get_option('nguk_business_email');
        $business_address = get_option('nguk_business_address');
        $download_url = admin_url('admin.php?page=nguk-transfer&ukng_receipt_id=' . intval($transaction->id));
        $back_url = admin_url('admin.php?page=nguk-transfer&ukng_view=transactions');
        $qr_url = self::qr_code_url(
            array(
                'Transaction ID: ' . $receipt_number,
                'Customer: ' . $transaction->customer_name,
                'Amount: GBP ' . number_format($transaction->total_paid, 2),
                'Status: ' . $transaction->status
            )
        );

        ob_start();
        ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($receipt_number); ?> Receipt</title>
    <style>
        body{margin:0;background:#edf2f7;font-family:Arial,Helvetica,sans-serif;color:#111827}
        #wpadminbar,#adminmenu,#adminmenuback,#adminmenuwrap,.notice,.update-nag,#screen-meta,#screen-meta-links,#wpfooter{display:none!important}
        #wpcontent,#wpfooter{margin-left:0!important}
        #wpbody-content{padding:0!important}
        .ukng-receipt-shell{max-width:940px;margin:28px auto;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(15,23,42,.16)}
        .ukng-receipt-hero{background:#12372a;color:#fff;padding:30px;display:flex;justify-content:space-between;gap:22px;align-items:flex-start}
        .ukng-receipt-brand{display:flex;gap:16px;align-items:center}
        .ukng-receipt-logo{width:86px;height:86px;border-radius:16px;background:#fff;object-fit:contain;padding:8px}
        .ukng-receipt-fallback{width:86px;height:86px;border-radius:16px;background:#f59e0b;color:#111827;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:900}
        .ukng-receipt-hero h1,.ukng-receipt-hero p{margin:0;color:#fff}
        .ukng-receipt-company h1{font-size:26px;margin-bottom:8px}
        .ukng-receipt-company p{line-height:1.5}
        .ukng-receipt-meta{text-align:right}
        .ukng-receipt-meta span{display:block;opacity:.8;font-size:12px;text-transform:uppercase;font-weight:800}
        .ukng-receipt-meta strong{display:block;font-size:24px;margin:4px 0 12px}
        .ukng-receipt-body{padding:30px}
        .ukng-receipt-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:22px}
        .ukng-receipt-card{border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:18px}
        .ukng-receipt-card span{display:block;font-size:12px;text-transform:uppercase;font-weight:800;color:#64748b}
        .ukng-receipt-card strong{display:block;margin-top:8px;font-size:22px;color:#12372a}
        .ukng-receipt-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
        .ukng-receipt-box{border:1px solid #e5e7eb;border-radius:14px;padding:20px}
        .ukng-receipt-box h2{margin:0 0 14px;color:#0f766e;font-size:18px}
        .ukng-receipt-box p{margin:0 0 12px;line-height:1.6}
        .ukng-receipt-table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden}
        .ukng-receipt-table th,.ukng-receipt-table td{padding:14px;border-bottom:1px solid #e5e7eb;text-align:left}
        .ukng-receipt-table th{background:#f8fafc;color:#334155;width:42%}
        .ukng-receipt-qr{margin-top:22px;border:1px solid #e5e7eb;border-radius:14px;padding:18px;display:flex;gap:16px;align-items:center}
        .ukng-receipt-qr img{width:150px;height:150px}
        .ukng-receipt-qr strong{display:block;color:#12372a;margin-bottom:6px}
        .ukng-status{color:#16a34a;font-weight:900}
        .ukng-receipt-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}
        .ukng-receipt-actions a,.ukng-receipt-actions button{border:0;border-radius:8px;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer}
        .ukng-primary{background:#0f766e;color:#fff}
        .ukng-secondary{background:#e5e7eb;color:#111827}
        @media(max-width:760px){.ukng-receipt-hero,.ukng-receipt-grid,.ukng-receipt-summary{display:block}.ukng-receipt-card,.ukng-receipt-box{margin-bottom:14px}.ukng-receipt-meta{text-align:left;margin-top:18px}}
        @media print{body{background:#fff}.ukng-receipt-shell{margin:0 auto;box-shadow:none}.ukng-receipt-actions{display:none}}
    </style>
</head>
<body>
    <div class="ukng-receipt-shell">
        <div class="ukng-receipt-hero">
            <div class="ukng-receipt-brand">
                <?php if (!empty($business_logo)) { ?>
                    <img class="ukng-receipt-logo" src="<?php echo esc_url($business_logo); ?>" alt="">
                <?php } else { ?>
                    <div class="ukng-receipt-fallback">UK</div>
                <?php } ?>
                <div class="ukng-receipt-company">
                    <h1><?php echo esc_html($business_name); ?></h1>
                    <p><?php echo esc_html($business_phone); ?></p>
                    <p><?php echo esc_html($business_email); ?></p>
                    <p><?php echo esc_html($business_address); ?></p>
                </div>
            </div>
            <div class="ukng-receipt-meta">
                <span>UK to Nigeria Receipt</span>
                <strong><?php echo esc_html($receipt_number); ?></strong>
                <span>Date</span>
                <strong><?php echo esc_html(date('d M Y', strtotime($transaction->created_at))); ?></strong>
            </div>
        </div>

        <div class="ukng-receipt-body">
            <div class="ukng-receipt-summary">
                <div class="ukng-receipt-card"><span>Pounds Sent</span><strong>GBP <?php echo esc_html(number_format($transaction->pounds_sent, 2)); ?></strong></div>
                <div class="ukng-receipt-card"><span>Commission</span><strong>GBP <?php echo esc_html(number_format($transaction->commission_amount, 2)); ?></strong></div>
                <div class="ukng-receipt-card"><span>Total Paid</span><strong>GBP <?php echo esc_html(number_format($transaction->total_paid, 2)); ?></strong></div>
            </div>

            <div class="ukng-receipt-grid">
                <div class="ukng-receipt-box">
                    <h2>Sender Details</h2>
                    <p><strong>Customer</strong><br><?php echo esc_html($transaction->customer_name); ?></p>
                    <p><strong>Amount Paid By Customer</strong><br>GBP <?php echo esc_html(number_format($transaction->total_paid, 2)); ?></p>
                </div>
                <div class="ukng-receipt-box">
                    <h2>Beneficiary Details</h2>
                    <p><strong>Beneficiary</strong><br><?php echo esc_html($transaction->beneficiary_name); ?></p>
                    <p><strong>Bank Details</strong><br><?php echo nl2br(esc_html($transaction->beneficiary_bank_details)); ?></p>
                </div>
            </div>

            <table class="ukng-receipt-table">
                <tr><th>Transaction ID</th><td><?php echo esc_html($receipt_number); ?></td></tr>
                <tr><th>Date Created</th><td><?php echo esc_html(date('d M Y h:i A', strtotime($transaction->created_at))); ?></td></tr>
                <tr><th>Exchange Rate</th><td>NGN <?php echo esc_html(number_format($transaction->exchange_rate, 2)); ?></td></tr>
                <tr><th>Beneficiary Gets</th><td>NGN <?php echo esc_html(number_format($transaction->naira_amount, 2)); ?></td></tr>
                <tr><th>Status</th><td class="ukng-status"><?php echo esc_html($transaction->status); ?></td></tr>
            </table>

            <div class="ukng-receipt-qr">
                <img src="<?php echo esc_url($qr_url); ?>" alt="Receipt QR code">
                <div>
                    <strong>Receipt QR Code</strong>
                    <p>Scan to view transaction ID, customer, amount, and status.</p>
                </div>
            </div>

            <?php if ($show_actions) { ?>
                <div class="ukng-receipt-actions">
                    <button type="button" class="ukng-primary" onclick="window.print()">Print Receipt</button>
                    <a class="ukng-primary" href="<?php echo esc_url($download_url); ?>">Download</a>
                    <a class="ukng-secondary" href="<?php echo esc_url($back_url); ?>">Back</a>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();

    }

    private static function calculate_commission($pounds_sent) {

        global $wpdb;

        $commissions_table = $wpdb->prefix . 'ukng_commissions';

        $rule = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $commissions_table
                 WHERE min_amount <= %f
                   AND (max_amount IS NULL OR max_amount = 0 OR max_amount >= %f)
                 ORDER BY min_amount DESC
                 LIMIT 1",
                $pounds_sent,
                $pounds_sent
            )
        );

        if (!$rule) {
            return 0;
        }

        if ($rule->commission_type === 'percent') {
            return ($pounds_sent * floatval($rule->commission_value)) / 100;
        }

        return floatval($rule->commission_value);

    }

    private static function current_view() {

        $view = isset($_GET['ukng_view'])
            ? sanitize_key($_GET['ukng_view'])
            : 'overview';

        $allowed = array(
            'overview',
            'customers',
            'payments',
            'transactions',
            'outstanding',
            'commissions',
            'reminders',
            'reports',
            'settings'
        );

        return in_array($view, $allowed, true) ? $view : 'overview';

    }

    private static function panel_class($panel, $current_view) {

        return $panel === $current_view
            ? 'ukng-panel is-active'
            : 'ukng-panel';

    }

    private static function status_options($selected_status) {

        $statuses = array(
            'Pending',
            'Payment Received',
            'Processing',
            'Paid Out',
            'Returned',
            'Cancelled'
        );

        foreach ($statuses as $status) {
            echo '<option value="' . esc_attr($status) . '" ' . selected($selected_status, $status, false) . '>' . esc_html($status) . '</option>';
        }

    }

    public function dashboard_page() {

        global $wpdb;

        NGUK_Database::create_ukng_tables();

        $customers_table = $wpdb->prefix . 'ukng_customers';
        $beneficiaries_table = $wpdb->prefix . 'ukng_beneficiaries';
        $transactions_table = $wpdb->prefix . 'ukng_transactions';
        $commissions_table = $wpdb->prefix . 'ukng_commissions';

        $current_view = self::current_view();

        if (isset($_GET['ukng_view_receipt'])) {
            self::view_receipt();
            return;
        }

        if (isset($_POST['ukng_save_rate'])) {
            update_option('ukng_exchange_rate', sanitize_text_field($_POST['exchange_rate']));
            echo '<div class="updated"><p>UK to Nigeria exchange rate saved.</p></div>';
            $current_view = 'settings';
        }

        if (isset($_POST['ukng_save_theme'])) {
            $theme = isset($_POST['dashboard_theme']) && $_POST['dashboard_theme'] === 'dark'
                ? 'dark'
                : 'normal';
            update_option('nguk_dashboard_theme', $theme);
            echo '<div class="updated"><p>Dashboard theme saved.</p></div>';
            $current_view = 'settings';
        }

        if (isset($_POST['ukng_save_customer'])) {
            $kyc_documents = self::upload_kyc_documents();

            $wpdb->insert(
                $customers_table,
                array(
                    'customer_name' => sanitize_text_field($_POST['customer_name']),
                    'phone_number' => sanitize_text_field($_POST['phone_number']),
                    'email' => sanitize_email($_POST['email']),
                    'address' => sanitize_textarea_field($_POST['address']),
                    'notes' => sanitize_textarea_field($_POST['notes']),
                    'kyc_documents' => wp_json_encode($kyc_documents)
                )
            );
            echo '<div class="updated"><p>UK-Nigeria customer saved.</p></div>';
            $current_view = 'customers';
        }

        if (isset($_GET['ukng_delete_customer'])) {
            $customer_id = intval($_GET['ukng_delete_customer']);

            if ($customer_id > 0 && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'ukng_delete_customer_' . $customer_id)) {
                $wpdb->delete($beneficiaries_table, array('customer_id' => $customer_id), array('%d'));
                $wpdb->delete($customers_table, array('id' => $customer_id), array('%d'));
                echo '<div class="updated"><p>UK-Nigeria customer deleted.</p></div>';
                $current_view = 'customers';
            } else {
                echo '<div class="notice notice-error"><p>Customer delete request could not be verified.</p></div>';
            }
        }

        if (isset($_POST['ukng_save_beneficiary'])) {
            $wpdb->insert(
                $beneficiaries_table,
                array(
                    'customer_id' => intval($_POST['customer_id']),
                    'beneficiary_name' => sanitize_text_field($_POST['beneficiary_name']),
                    'bank_name' => sanitize_text_field($_POST['bank_name']),
                    'account_name' => sanitize_text_field($_POST['account_name']),
                    'account_number' => sanitize_text_field($_POST['account_number']),
                    'notes' => sanitize_textarea_field($_POST['notes'])
                )
            );
            echo '<div class="updated"><p>Beneficiary saved.</p></div>';
            $current_view = 'customers';
        }

        if (isset($_POST['ukng_save_commission'])) {
            $max_amount = trim((string) $_POST['max_amount']);

            $wpdb->insert(
                $commissions_table,
                array(
                    'min_amount' => floatval($_POST['min_amount']),
                    'max_amount' => $max_amount === '' ? null : floatval($max_amount),
                    'commission_type' => sanitize_text_field($_POST['commission_type']),
                    'commission_value' => floatval($_POST['commission_value']),
                    'label' => sanitize_text_field($_POST['label'])
                )
            );
            echo '<div class="updated"><p>Commission rule saved.</p></div>';
            $current_view = 'commissions';
        }

        if (isset($_POST['ukng_update_commission'])) {
            $max_amount = trim((string) $_POST['max_amount']);

            $wpdb->update(
                $commissions_table,
                array(
                    'min_amount' => floatval($_POST['min_amount']),
                    'max_amount' => $max_amount === '' ? null : floatval($max_amount),
                    'commission_type' => sanitize_text_field($_POST['commission_type']),
                    'commission_value' => floatval($_POST['commission_value']),
                    'label' => sanitize_text_field($_POST['label'])
                ),
                array('id' => intval($_POST['commission_id']))
            );

            echo '<div class="updated"><p>Commission rule updated.</p></div>';
            $current_view = 'commissions';
        }

        if (isset($_GET['ukng_delete_commission'])) {
            $wpdb->delete($commissions_table, array('id' => intval($_GET['ukng_delete_commission'])));
            echo '<div class="updated"><p>Commission rule deleted.</p></div>';
            $current_view = 'commissions';
        }

        if (isset($_POST['ukng_create_transaction'])) {
            $customer_id = intval($_POST['customer_id']);
            $beneficiary_id = intval($_POST['beneficiary_id']);
            $pounds_sent = floatval($_POST['pounds_sent']);
            $exchange_rate = floatval(get_option('ukng_exchange_rate', '2000'));

            $customer = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id)
            );

            $beneficiary = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $beneficiaries_table WHERE id = %d AND customer_id = %d",
                    $beneficiary_id,
                    $customer_id
                )
            );

            if (!$customer || !$beneficiary || $pounds_sent <= 0 || $exchange_rate <= 0) {
                echo '<div class="notice notice-error"><p>Please select a valid customer, beneficiary, pounds amount, and exchange rate.</p></div>';
                $current_view = 'payments';
            } else {
                $commission = self::calculate_commission($pounds_sent);
                $total_paid = $pounds_sent + $commission;
                $naira_amount = $pounds_sent * $exchange_rate;
                $beneficiary_bank_details = implode(
                    "\n",
                    array_filter(
                        array(
                            $beneficiary->bank_name,
                            $beneficiary->account_name,
                            $beneficiary->account_number
                        )
                    )
                );

                $inserted = $wpdb->insert(
                    $transactions_table,
                    array(
                        'customer_id' => $customer->id,
                        'beneficiary_id' => $beneficiary->id,
                        'customer_name' => $customer->customer_name,
                        'beneficiary_name' => $beneficiary->beneficiary_name,
                        'pounds_sent' => $pounds_sent,
                        'commission_amount' => $commission,
                        'total_paid' => $total_paid,
                        'exchange_rate' => $exchange_rate,
                        'naira_amount' => $naira_amount,
                        'beneficiary_bank_details' => $beneficiary_bank_details,
                        'status' => 'Pending',
                        'outstanding_status' => 'Outstanding',
                        'amount_paid' => 0,
                        'tracking_code' => NGUK_Database::generate_ukng_tracking_code(),
                        'status_updated_at' => current_time('mysql')
                    )
                );

                if ($inserted === false) {
                    echo '<div class="notice notice-error"><p>Transaction could not be created. ' . esc_html($wpdb->last_error) . '</p></div>';
                    $current_view = 'payments';
                } else {
                    echo '<div class="updated"><p>UK to Nigeria transaction created.</p></div>';
                    $current_view = 'transactions';
                }
            }
        }

        if (isset($_POST['ukng_update_status'])) {
            $wpdb->update(
                $transactions_table,
                array(
                    'status' => sanitize_text_field($_POST['transaction_status']),
                    'status_updated_at' => current_time('mysql')
                ),
                array('id' => intval($_POST['transaction_id']))
            );
            echo '<div class="updated"><p>Transaction status updated.</p></div>';
            $current_view = 'transactions';
        }

        if (isset($_GET['ukng_delete_transaction'])) {
            $wpdb->delete($transactions_table, array('id' => intval($_GET['ukng_delete_transaction'])));
            echo '<div class="updated"><p>Transaction deleted.</p></div>';
            $current_view = 'transactions';
        }

        if (isset($_POST['ukng_update_outstanding_payment'])) {
            $transaction_id = intval($_POST['transaction_id']);
            $amount_paid = max(0, floatval($_POST['amount_paid']));

            $transaction = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $transactions_table WHERE id = %d",
                    $transaction_id
                )
            );

            if ($transaction) {
                $amount_paid = min($amount_paid, floatval($transaction->total_paid));
                $remaining_balance = max(0, floatval($transaction->total_paid) - $amount_paid);

                $wpdb->update(
                    $transactions_table,
                    array(
                        'amount_paid' => $amount_paid,
                        'outstanding_status' => $remaining_balance <= 0 ? 'Cleared' : 'Outstanding'
                    ),
                    array('id' => $transaction_id)
                );

                echo '<div class="updated"><p>Customer wallet updated.</p></div>';
                $current_view = 'outstanding';
            }
        }

        if (isset($_GET['ukng_clear_outstanding'])) {
            $clear_transaction = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT total_paid FROM $transactions_table WHERE id = %d",
                    intval($_GET['ukng_clear_outstanding'])
                )
            );

            $wpdb->update(
                $transactions_table,
                array(
                    'outstanding_status' => 'Cleared',
                    'amount_paid' => $clear_transaction ? floatval($clear_transaction->total_paid) : 0
                ),
                array('id' => intval($_GET['ukng_clear_outstanding']))
            );
            echo '<div class="updated"><p>Outstanding balance cleared.</p></div>';
            $current_view = 'outstanding';
        }

        if (isset($_GET['ukng_view_customer'])) {
            $this->customer_profile(intval($_GET['ukng_view_customer']));
            return;
        }

        $exchange_rate = get_option('ukng_exchange_rate', '2000');
        $customer_search = isset($_GET['ukng_customer_search'])
            ? sanitize_text_field(wp_unslash($_GET['ukng_customer_search']))
            : '';
        $transaction_search = isset($_GET['ukng_transaction_search'])
            ? sanitize_text_field(wp_unslash($_GET['ukng_transaction_search']))
            : '';

        $all_customers = $wpdb->get_results("SELECT * FROM $customers_table ORDER BY customer_name ASC");
        $customers = $all_customers;

        if ($customer_search !== '') {
            $customer_like = '%' . $wpdb->esc_like($customer_search) . '%';
            $customers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $customers_table
                     WHERE customer_name LIKE %s
                        OR phone_number LIKE %s
                     ORDER BY customer_name ASC",
                    $customer_like,
                    $customer_like
                )
            );
        }

        $beneficiaries = $wpdb->get_results("SELECT * FROM $beneficiaries_table ORDER BY beneficiary_name ASC");

        if ($transaction_search !== '') {
            $transaction_like = '%' . $wpdb->esc_like($transaction_search) . '%';
            $transaction_id_search = preg_replace('/\D/', '', $transaction_search);
            $transaction_id = 0;

            if ($transaction_id_search !== '') {
                $transaction_id = intval($transaction_id_search);

                if (stripos($transaction_search, 'UKNG') === 0) {
                    $transaction_id = max(0, $transaction_id - 9000);
                }
            }

            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.*, c.phone_number AS customer_phone
                     FROM $transactions_table t
                     LEFT JOIN $customers_table c ON c.id = t.customer_id
                     WHERE t.customer_name LIKE %s
                        OR t.beneficiary_name LIKE %s
                        OR c.phone_number LIKE %s
                        OR t.tracking_code LIKE %s
                        OR CONCAT('UKNG', t.id + 9000) LIKE %s
                        OR t.id = %d
                     ORDER BY t.id DESC
                     LIMIT 50",
                    $transaction_like,
                    $transaction_like,
                    $transaction_like,
                    $transaction_like,
                    $transaction_like,
                    $transaction_id
                )
            );
        } else {
            $transactions = $wpdb->get_results(
                "SELECT t.*, c.phone_number AS customer_phone
                 FROM $transactions_table t
                 LEFT JOIN $customers_table c ON c.id = t.customer_id
                 ORDER BY t.id DESC
                 LIMIT 50"
            );
        }

        $outstanding_transactions = $wpdb->get_results(
            "SELECT * FROM $transactions_table
             WHERE outstanding_status IS NULL
                OR outstanding_status = ''
                OR outstanding_status = 'Outstanding'
             ORDER BY id DESC"
        );
        $commission_rules = $wpdb->get_results("SELECT * FROM $commissions_table ORDER BY min_amount ASC");
        $total_outstanding = 0;
        $total_wallet_paid = 0;
        $total_remaining_balance = 0;

        foreach ($outstanding_transactions as $outstanding_transaction) {
            $wallet_paid = min(floatval($outstanding_transaction->amount_paid), floatval($outstanding_transaction->total_paid));
            $remaining_balance = max(0, floatval($outstanding_transaction->total_paid) - $wallet_paid);
            $total_outstanding += floatval($outstanding_transaction->total_paid);
            $total_wallet_paid += $wallet_paid;
            $total_remaining_balance += $remaining_balance;
        }

        $month_totals = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%M %Y') as month_year,
                    COUNT(*) as total_transactions,
                    SUM(pounds_sent) as total_pounds,
                    SUM(commission_amount) as total_profit,
                    SUM(total_paid) as total_paid,
                    SUM(naira_amount) as total_naira
             FROM $transactions_table
             GROUP BY YEAR(created_at), MONTH(created_at)
             ORDER BY created_at DESC"
        );

        ?>
        <?php $dashboard_theme = get_option('nguk_dashboard_theme', 'normal'); ?>
        <div class="wrap ukng-dashboard <?php echo $dashboard_theme === 'dark' ? 'ukng-dashboard-dark' : ''; ?>">
            <div class="ukng-hero">
                <div>
                    <p>UK-Nigeria Operations</p>
                    <h1>UK-Nigeria Money Transfer Dashboard</h1>
                    <p>Manage customers sending GBP from the UK to Nigerian beneficiaries.</p>
                </div>
                <div class="ukng-switch">
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer')); ?>">Nigeria to UK</a>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=overview')); ?>">UK to Nigeria</a>
                </div>
            </div>

            <nav class="ukng-nav">
                <?php
                $nav_items = array(
                    'overview' => 'Overview',
                    'payments' => 'Payments',
                    'customers' => 'Customers',
                    'transactions' => 'Transactions',
                    'outstanding' => 'Outstanding Balance',
                    'commissions' => 'Commissions',
                    'reminders' => NGUK_Reminders::render_badge('ukng'),
                    'reports' => 'Reports',
                    'settings' => 'Settings'
                );
                foreach ($nav_items as $view => $label) {
                    $class = $view === $current_view ? 'is-active' : '';
                    echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=' . $view)) . '">' . wp_kses_post($label) . '</a>';
                }
                ?>
            </nav>

            <?php NGUK_Reminders::render_ticker('ukng_view', 'ukng'); ?>

            <style>
                .ukng-dashboard{max-width:1360px}
                .ukng-hero{background:#12372a;color:#fff;padding:28px;border-radius:16px;display:flex;justify-content:space-between;gap:20px;align-items:center;margin-top:18px}
                .ukng-hero h1,.ukng-hero p{color:#fff;margin:0}
                .ukng-hero h1{font-size:30px;margin:6px 0}
                .ukng-switch{display:flex;gap:10px;flex-wrap:wrap}
                .ukng-switch .button-primary{background:#f59e0b!important;border-color:#f59e0b!important;color:#111827!important;font-weight:900!important}
                .ukng-nav{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:8px;margin:18px 0;display:flex;gap:8px;flex-wrap:wrap}
                .ukng-nav a{padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700;color:#334155}
                .ukng-nav a.is-active{background:#0f766e;color:#fff}
                .ukng-panel{display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-top:18px;box-shadow:0 10px 26px rgba(15,23,42,.06)}
                .ukng-panel.is-active{display:block}
                .ukng-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
                .ukng-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px}
                .ukng-card span{display:block;color:#64748b;font-weight:800;text-transform:uppercase;font-size:12px}
                .ukng-card strong{display:block;margin-top:8px;font-size:24px;color:#0f172a}
                .ukng-dashboard input,.ukng-dashboard select,.ukng-dashboard textarea{border-radius:8px!important;min-height:40px}
                .ukng-rate-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;margin-top:18px;display:flex;justify-content:space-between;gap:18px;align-items:center;box-shadow:0 10px 26px rgba(15,23,42,.06)}
                .ukng-rate-panel strong{display:block;font-size:40px;line-height:1;color:#12372a;margin-top:6px}
                .ukng-rate-panel form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
                .ukng-rate-panel label{display:block;font-weight:800;color:#334155;margin-bottom:6px}
                .ukng-search-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}
                .ukng-search-form input[type="search"]{min-width:280px;max-width:100%;width:360px}
                .ukng-dashboard-dark{background:#0f172a;color:#e5e7eb;padding:20px;border-radius:14px}
                .ukng-dashboard-dark .ukng-panel,.ukng-dashboard-dark .ukng-nav,.ukng-dashboard-dark .ukng-rate-panel,.ukng-dashboard-dark .ukng-card{background:#111827;border-color:#334155;color:#e5e7eb}
                .ukng-dashboard-dark h1,.ukng-dashboard-dark h2,.ukng-dashboard-dark h3,.ukng-dashboard-dark p,.ukng-dashboard-dark th,.ukng-dashboard-dark td,.ukng-dashboard-dark label{color:#e5e7eb}
                .ukng-dashboard-dark .widefat,.ukng-dashboard-dark .widefat td,.ukng-dashboard-dark .widefat th{background:#111827;color:#e5e7eb;border-color:#334155}
                .ukng-dashboard-dark input,.ukng-dashboard-dark select,.ukng-dashboard-dark textarea{background:#020617!important;color:#e5e7eb!important;border-color:#475569!important}
                @media(max-width:900px){.ukng-grid{grid-template-columns:1fr}.ukng-hero{display:block}}
            </style>

            <div class="ukng-rate-panel">
                <div>
                    <span>Today's Rate</span>
                    <strong>NGN <?php echo esc_html(number_format(floatval($exchange_rate), 2)); ?></strong>
                </div>
                <form method="post">
                    <div>
                        <label for="ukng_today_rate">Modify Today's Rate</label>
                        <input id="ukng_today_rate"
                               type="number"
                               step="0.01"
                               name="exchange_rate"
                               value="<?php echo esc_attr($exchange_rate); ?>"
                               required>
                    </div>
                    <input type="submit"
                           name="ukng_save_rate"
                           class="button button-primary"
                           value="Save Rate">
                </form>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('overview', $current_view)); ?>">
                <h2>Overview</h2>
                <div class="ukng-grid">
                    <div class="ukng-card"><span>Today's Rate</span><strong><?php echo esc_html(number_format(floatval($exchange_rate), 2)); ?></strong></div>
                    <div class="ukng-card"><span>Customers</span><strong><?php echo esc_html(count($all_customers)); ?></strong></div>
                    <div class="ukng-card"><span>Transactions</span><strong><?php echo esc_html(count($transactions)); ?></strong></div>
                    <div class="ukng-card"><span>Outstanding Balance</span><strong>GBP <?php echo esc_html(number_format($total_outstanding, 2)); ?></strong></div>
                    <?php NGUK_Reminders::render_overview_widget('ukng-card', 'ukng'); ?>
                </div>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('payments', $current_view)); ?>">
                <h2>Create UK to Nigeria Transaction</h2>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Customer</th>
                            <td>
                                <select id="ukng_customer_select" name="customer_id" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($all_customers as $customer) { ?>
                                        <option value="<?php echo intval($customer->id); ?>" <?php selected(isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0, $customer->id); ?>>
                                            <?php echo esc_html($customer->customer_name); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Beneficiary</th>
                            <td>
                                <select id="ukng_beneficiary_select" name="beneficiary_id" required>
                                    <option value="">Select Beneficiary</option>
                                    <?php foreach ($beneficiaries as $beneficiary) { ?>
                                        <option value="<?php echo intval($beneficiary->id); ?>"
                                                data-customer-id="<?php echo intval($beneficiary->customer_id); ?>"
                                                <?php selected(isset($_GET['beneficiary_id']) ? intval($_GET['beneficiary_id']) : 0, $beneficiary->id); ?>>
                                            <?php echo esc_html($beneficiary->beneficiary_name . ' - ' . $beneficiary->bank_name); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Pounds Sent</th>
                            <td><input type="number" step="0.01" min="1" id="ukng_pounds_sent" name="pounds_sent" required></td>
                        </tr>
                        <tr>
                            <th>Rate</th>
                            <td><input type="number" step="0.01" id="ukng_rate" value="<?php echo esc_attr($exchange_rate); ?>" readonly></td>
                        </tr>
                        <tr>
                            <th>Beneficiary Gets</th>
                            <td><input type="text" id="ukng_naira_preview" readonly></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="ukng_create_transaction" class="button button-primary" value="Create Transaction"></p>
                </form>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('customers', $current_view)); ?>">
                <h2>Customers</h2>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=customers&ukng_add_customer=1')); ?>">Add Customer</a></p>

                <form method="get" class="ukng-search-form">
                    <input type="hidden" name="page" value="nguk-transfer">
                    <input type="hidden" name="ukng_view" value="customers">
                    <input type="search"
                           name="ukng_customer_search"
                           value="<?php echo esc_attr($customer_search); ?>"
                           placeholder="Search by first name, last name, or phone number">
                    <input type="submit" class="button button-primary" value="Search">
                    <?php if ($customer_search !== '') { ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=customers')); ?>">Clear</a>
                    <?php } ?>
                </form>

                <?php if (isset($_GET['ukng_add_customer'])) { ?>
                    <form method="post" enctype="multipart/form-data">
                        <table class="form-table">
                            <tr><th>Name</th><td><input type="text" name="customer_name" required></td></tr>
                            <tr><th>Phone</th><td><input type="text" name="phone_number"></td></tr>
                            <tr><th>Email</th><td><input type="email" name="email"></td></tr>
                            <tr>
                                <th>Address</th>
                                <td>
                                    <textarea name="address" class="large-text"></textarea>
                                </td>
                            </tr>
                            <tr><th>Notes</th><td><textarea name="notes" class="large-text"></textarea></td></tr>
                            <tr><th>KYC Documents</th><td><input type="file" name="kyc_documents[]" multiple><p class="description">Upload one or more KYC documents.</p></td></tr>
                        </table>
                        <p><input type="submit" name="ukng_save_customer" class="button button-primary" value="Save Customer"></p>
                    </form>
                <?php } ?>

                <table class="widefat striped">
                    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>KYC Documents</th><th>Profile</th><th>Delete</th></tr></thead>
                    <tbody>
                        <?php if ($customers) { ?>
                            <?php foreach ($customers as $customer) { ?>
                                <tr>
                                    <td><?php echo esc_html($customer->customer_name); ?></td>
                                    <td><?php echo esc_html($customer->phone_number); ?></td>
                                    <td><?php echo esc_html($customer->email); ?></td>
                                    <td><?php self::render_kyc_documents(isset($customer->kyc_documents) ? $customer->kyc_documents : ''); ?></td>
                                    <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view_customer=' . intval($customer->id))); ?>">View Profile</a></td>
                                    <td>
                                        <a class="button"
                                           title="Delete customer"
                                           style="background:#dc2626;border-color:#dc2626;color:#fff;"
                                           onclick="return confirm('Delete this customer and their beneficiaries? Existing transaction history will remain.');"
                                           href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=nguk-transfer&ukng_view=customers&ukng_delete_customer=' . intval($customer->id)), 'ukng_delete_customer_' . intval($customer->id))); ?>">
                                            <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="6">No UK-Nigeria customers found.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('transactions', $current_view)); ?>">
                <h2>Recent Transactions</h2>

                <form method="get" class="ukng-search-form">
                    <input type="hidden" name="page" value="nguk-transfer">
                    <input type="hidden" name="ukng_view" value="transactions">
                    <input type="search"
                           name="ukng_transaction_search"
                           value="<?php echo esc_attr($transaction_search); ?>"
                           placeholder="Search by name, phone number, or transaction ID">
                    <input type="submit" class="button button-primary" value="Search">
                    <?php if ($transaction_search !== '') { ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=transactions')); ?>">Clear</a>
                    <?php } ?>
                </form>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>No.</th><th>Transaction ID</th><th>Customer</th><th>Phone</th><th>Beneficiary</th><th>Pounds</th><th>Commission</th><th>Total Paid</th><th>Naira</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transactions) { $count = 1; ?>
                            <?php foreach ($transactions as $transaction) { ?>
                                <tr>
                                    <td><?php echo $count++; ?></td>
                                    <td><?php echo esc_html('UKNG' . (9000 + intval($transaction->id))); ?></td>
                                    <td><?php echo esc_html($transaction->customer_name); ?></td>
                                    <td><?php echo esc_html(isset($transaction->customer_phone) ? $transaction->customer_phone : ''); ?></td>
                                    <td><?php echo esc_html($transaction->beneficiary_name); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($transaction->pounds_sent, 2)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($transaction->commission_amount, 2)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($transaction->total_paid, 2)); ?></td>
                                    <td>NGN <?php echo esc_html(number_format($transaction->naira_amount, 2)); ?></td>
                                    <td>
                                        <form method="post" style="display:flex;gap:6px;align-items:center;">
                                            <input type="hidden" name="transaction_id" value="<?php echo intval($transaction->id); ?>">
                                            <select name="transaction_status"><?php self::status_options($transaction->status); ?></select>
                                            <input type="submit" name="ukng_update_status" class="button" value="Update">
                                        </form>
                                    </td>
                                    <td>
                                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view_receipt=' . intval($transaction->id))); ?>">Receipt</a>
                                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_receipt_id=' . intval($transaction->id))); ?>">Download</a>
                                        <a class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_delete_transaction=' . intval($transaction->id))); ?>" onclick="return confirm('Delete this transaction?');">Delete</a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="11">No UK to Nigeria transactions found.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('outstanding', $current_view)); ?>">
                <h2>Customer Wallet / Outstanding Balance</h2>
                <div class="ukng-grid" style="margin-bottom:18px;">
                    <div class="ukng-card"><span>Total Outstanding</span><strong>GBP <?php echo esc_html(number_format($total_outstanding, 2)); ?></strong></div>
                    <div class="ukng-card"><span>Total Paid</span><strong>GBP <?php echo esc_html(number_format($total_wallet_paid, 2)); ?></strong></div>
                    <div class="ukng-card"><span>Remaining Balance</span><strong>GBP <?php echo esc_html(number_format($total_remaining_balance, 2)); ?></strong></div>
                </div>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Total Outstanding</th>
                            <th>Total Paid</th>
                            <th>Remaining Balance</th>
                            <th>Update Payment</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($outstanding_transactions) { ?>
                            <?php foreach ($outstanding_transactions as $transaction) { ?>
                                <?php
                                $amount_paid = min(floatval($transaction->amount_paid), floatval($transaction->total_paid));
                                $remaining_balance = max(0, floatval($transaction->total_paid) - $amount_paid);
                                ?>
                                <tr>
                                    <td><?php echo esc_html($transaction->customer_name); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($transaction->total_paid, 2)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($amount_paid, 2)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($remaining_balance, 2)); ?></td>
                                    <td>
                                        <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                            <input type="hidden" name="transaction_id" value="<?php echo intval($transaction->id); ?>">
                                            <input type="number" step="0.01" min="0" max="<?php echo esc_attr($transaction->total_paid); ?>" name="amount_paid" value="<?php echo esc_attr($amount_paid); ?>" style="width:120px;">
                                            <input type="submit" name="ukng_update_outstanding_payment" class="button" value="Save">
                                        </form>
                                    </td>
                                    <td>
                                        <a class="button" style="background:#16a34a;color:#fff;border-color:#16a34a;" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=outstanding&ukng_clear_outstanding=' . intval($transaction->id))); ?>" onclick="return confirm('Delete this outstanding balance after customer payment?');">Delete Balance</a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="6">No outstanding UK to Nigeria balances found.</td></tr>
                        <?php } ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Totals</th>
                            <th>GBP <?php echo esc_html(number_format($total_outstanding, 2)); ?></th>
                            <th>GBP <?php echo esc_html(number_format($total_wallet_paid, 2)); ?></th>
                            <th>GBP <?php echo esc_html(number_format($total_remaining_balance, 2)); ?></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('commissions', $current_view)); ?>">
                <h2>Commission Rules</h2>
                <form method="post">
                    <table class="form-table">
                        <tr><th>Label</th><td><input type="text" name="label" placeholder="GBP 1 - GBP 100"></td></tr>
                        <tr><th>Min GBP</th><td><input type="number" step="0.01" name="min_amount" required></td></tr>
                        <tr><th>Max GBP</th><td><input type="number" step="0.01" name="max_amount" placeholder="Leave empty for no maximum"></td></tr>
                        <tr>
                            <th>Commission Type</th>
                            <td>
                                <select name="commission_type">
                                    <option value="fixed">Fixed GBP</option>
                                    <option value="percent">Percent</option>
                                </select>
                            </td>
                        </tr>
                        <tr><th>Value</th><td><input type="number" step="0.01" name="commission_value" required></td></tr>
                    </table>
                    <p><input type="submit" name="ukng_save_commission" class="button button-primary" value="Add Commission Rule"></p>
                </form>

                <table class="widefat striped">
                    <thead><tr><th>Label</th><th>Min</th><th>Max</th><th>Type</th><th>Value</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($commission_rules as $rule) { ?>
                            <tr>
                                <form method="post">
                                    <td>
                                        <input type="hidden" name="commission_id" value="<?php echo intval($rule->id); ?>">
                                        <input type="text" name="label" value="<?php echo esc_attr($rule->label); ?>">
                                    </td>
                                    <td><input type="number" step="0.01" name="min_amount" value="<?php echo esc_attr($rule->min_amount); ?>"></td>
                                    <td><input type="number" step="0.01" name="max_amount" value="<?php echo empty($rule->max_amount) ? '' : esc_attr($rule->max_amount); ?>" placeholder="No max"></td>
                                    <td>
                                        <select name="commission_type">
                                            <option value="fixed" <?php selected($rule->commission_type, 'fixed'); ?>>Fixed GBP</option>
                                            <option value="percent" <?php selected($rule->commission_type, 'percent'); ?>>Percent</option>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" name="commission_value" value="<?php echo esc_attr($rule->commission_value); ?>"></td>
                                    <td>
                                        <input type="submit" name="ukng_update_commission" class="button button-primary" value="Save">
                                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=commissions&ukng_delete_commission=' . intval($rule->id))); ?>" onclick="return confirm('Delete this commission rule?');">Delete</a>
                                    </td>
                                </form>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php NGUK_Reminders::render_panel(self::panel_class('reminders', $current_view), 'ukng_view', 'ukng'); ?>

            <div class="<?php echo esc_attr(self::panel_class('reports', $current_view)); ?>">
                <h2>Monthly Turnovers</h2>
                <table class="widefat striped"
                       style="margin-top:20px;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;font-size:15px;font-weight:700;box-shadow:0 14px 32px rgba(15,23,42,0.09);">
                    <thead style="background:#12372a;color:#fff;">
                        <tr>
                            <th style="color:#fff;padding:14px 12px;">No.</th>
                            <th style="color:#fff;padding:14px 12px;">Month</th>
                            <th style="color:#fff;padding:14px 12px;">Transactions</th>
                            <th style="color:#fff;padding:14px 12px;">Total Pounds</th>
                            <th style="color:#fff;padding:14px 12px;">Total Profit</th>
                            <th style="color:#fff;padding:14px 12px;">Total Paid</th>
                            <th style="color:#fff;padding:14px 12px;">Total Naira</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($month_totals) { ?>
                            <?php $monthly_count = 1; ?>
                            <?php foreach ($month_totals as $month) { ?>
                                <tr style="height:60px;font-weight:700;">
                                    <td style="font-weight:900;color:#12372a;"><?php echo esc_html($monthly_count++); ?></td>
                                    <td style="font-weight:900;color:#111827;"><?php echo esc_html($month->month_year); ?></td>
                                    <td><?php echo esc_html(number_format($month->total_transactions)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($month->total_pounds, 2)); ?></td>
                                    <td style="color:#15803d;font-weight:900;">GBP <?php echo esc_html(number_format($month->total_profit, 2)); ?></td>
                                    <td>GBP <?php echo esc_html(number_format($month->total_paid, 2)); ?></td>
                                    <td>NGN <?php echo esc_html(number_format($month->total_naira, 2)); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="7">No monthly turnover data found.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="<?php echo esc_attr(self::panel_class('settings', $current_view)); ?>">
                <h2>UK to Nigeria Settings</h2>
                <p>Today's Rate can be edited from the rate panel at the top of this dashboard.</p>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Dashboard Theme</th>
                            <td>
                                <select name="dashboard_theme">
                                    <option value="normal" <?php selected($dashboard_theme, 'normal'); ?>>Normal Mode</option>
                                    <option value="dark" <?php selected($dashboard_theme, 'dark'); ?>>Dark Mode</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><input type="submit" name="ukng_save_theme" class="button button-primary" value="Save Theme"></p>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var beneficiaryOptions = $('#ukng_beneficiary_select option').clone();

            function filterBeneficiaries(){
                var customerId = $('#ukng_customer_select').val();
                var selectedBeneficiary = '<?php echo isset($_GET['beneficiary_id']) ? intval($_GET['beneficiary_id']) : ''; ?>';
                $('#ukng_beneficiary_select').empty();
                beneficiaryOptions.each(function(){
                    var option = $(this).clone();
                    if(option.val() === '' || String(option.data('customer-id')) === String(customerId)){
                        $('#ukng_beneficiary_select').append(option);
                    }
                });
                if(selectedBeneficiary){
                    $('#ukng_beneficiary_select').val(selectedBeneficiary);
                }
            }

            function calculatePreview(){
                var pounds = parseFloat($('#ukng_pounds_sent').val()) || 0;
                var rate = parseFloat($('#ukng_rate').val()) || 0;
                $('#ukng_naira_preview').val(pounds > 0 && rate > 0 ? 'NGN ' + (pounds * rate).toFixed(2) : '');
            }

            $('#ukng_customer_select').on('change', filterBeneficiaries);
            $('#ukng_pounds_sent').on('keyup change', calculatePreview);
            filterBeneficiaries();
        });
        </script>
        <?php

    }

    private function customer_profile($customer_id) {

        global $wpdb;

        $customers_table = $wpdb->prefix . 'ukng_customers';
        $beneficiaries_table = $wpdb->prefix . 'ukng_beneficiaries';

        $customer = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id)
        );

        if (!$customer) {
            echo '<div class="wrap"><p>Customer not found.</p></div>';
            return;
        }

        $beneficiaries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $beneficiaries_table WHERE customer_id = %d ORDER BY id DESC",
                $customer->id
            )
        );

        ?>
        <div class="wrap">
            <h1>UK-Nigeria Customer Profile</h1>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=customers')); ?>">Back to UK-Nigeria Dashboard</a></p>

            <table class="widefat striped" style="max-width:900px;">
                <tr><th>Name</th><td><?php echo esc_html($customer->customer_name); ?></td></tr>
                <tr><th>Phone</th><td><?php echo esc_html($customer->phone_number); ?></td></tr>
                <tr><th>Email</th><td><?php echo esc_html($customer->email); ?></td></tr>
                <tr><th>Address</th><td><?php echo esc_html($customer->address); ?></td></tr>
                <tr><th>Notes</th><td><?php echo esc_html($customer->notes); ?></td></tr>
                <tr><th>KYC Documents</th><td><?php self::render_kyc_documents(isset($customer->kyc_documents) ? $customer->kyc_documents : ''); ?></td></tr>
            </table>

            <hr style="margin:30px 0;">

            <h2>Add Nigerian Beneficiary</h2>
            <form method="post">
                <input type="hidden" name="customer_id" value="<?php echo intval($customer->id); ?>">
                <table class="form-table">
                    <tr><th>Beneficiary Name</th><td><input type="text" name="beneficiary_name" required></td></tr>
                    <tr><th>Bank Name</th><td><input type="text" name="bank_name" required></td></tr>
                    <tr><th>Account Name</th><td><input type="text" name="account_name" required></td></tr>
                    <tr><th>Account Number</th><td><input type="text" name="account_number" required></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" class="large-text"></textarea></td></tr>
                </table>
                <p><input type="submit" name="ukng_save_beneficiary" class="button button-primary" value="Save Beneficiary"></p>
            </form>

            <h2>Registered Beneficiaries</h2>
            <table class="widefat striped">
                <thead><tr><th>Name</th><th>Bank</th><th>Account Name</th><th>Account Number</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if ($beneficiaries) { ?>
                        <?php foreach ($beneficiaries as $beneficiary) { ?>
                            <tr>
                                <td><?php echo esc_html($beneficiary->beneficiary_name); ?></td>
                                <td><?php echo esc_html($beneficiary->bank_name); ?></td>
                                <td><?php echo esc_html($beneficiary->account_name); ?></td>
                                <td><?php echo esc_html($beneficiary->account_number); ?></td>
                                <td>
                                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=nguk-transfer&ukng_view=payments&customer_id=' . intval($customer->id) . '&beneficiary_id=' . intval($beneficiary->id))); ?>">
                                        Send Money
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr><td colspan="5">No beneficiaries found for this customer.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php

    }

}




