<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('NGUK_Reminders') && defined('NGUK_PLUGIN_PATH')) {
    require_once NGUK_PLUGIN_PATH . 'includes/class-reminders.php';
}

if (!function_exists('nguk_pdf_escape')) {

    function nguk_pdf_escape($text) {

        $text = wp_strip_all_tags((string) $text);

        $text = str_replace(array('₦', '£'), array('NGN ', 'GBP '), $text);

        $text = preg_replace('/[^\x20-\x7E]/', '', $text);

        return str_replace(
            array('\\', '(', ')'),
            array('\\\\', '\\(', '\\)'),
            $text
        );

    }

}

if (!function_exists('nguk_build_receipt_pdf')) {

    function nguk_build_receipt_pdf($lines) {

        $content = "BT\n/F1 18 Tf\n50 790 Td\n";

        $first_line = true;

        foreach ($lines as $line) {

            $wrapped_lines = explode(
                "\n",
                wordwrap((string) $line, 82, "\n", true)
            );

            foreach ($wrapped_lines as $wrapped_line) {

                if ($first_line) {

                    $content .= '(' . nguk_pdf_escape($wrapped_line) . ") Tj\n";

                    $content .= "/F1 11 Tf\n14 TL\n";

                    $first_line = false;

                } else {

                    $content .= "T*\n(" . nguk_pdf_escape($wrapped_line) . ") Tj\n";

                }

            }

        }

        $content .= "ET";

        $objects = array(
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n"
        );

        $pdf = "%PDF-1.4\n";

        $offsets = array(0);

        foreach ($objects as $object) {

            $offsets[] = strlen($pdf);

            $pdf .= $object;

        }

        $xref_offset = strlen($pdf);

        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";

        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index < count($offsets); $index++) {

            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);

        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";

        $pdf .= "startxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;

    }

}

class NGUK_Dashboard {

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

        if (
            !isset($_GET['download_receipt']) &&
            !isset($_GET['receipt_id'])
        ) {

            return;

        }

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'nguk_transactions';

        $nguk_force_panel = '';

        $transaction_id = isset($_GET['receipt_id'])
            ? intval($_GET['receipt_id'])
            : intval($_GET['download_receipt']);

        $transaction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $transactions_table WHERE id = %d",
                $transaction_id
            )
        );

        if (!$transaction) {

            wp_die('Receipt not found.');

        }

        $business_name = get_option('nguk_business_name');

        $business_phone = get_option('nguk_business_phone');

        $business_email = get_option('nguk_business_email');

        $business_address = get_option('nguk_business_address');

        $receipt_uk_bank_details = $transaction->uk_bank_details;

        $receipt_pounds_sent = 0;

        if (floatval($transaction->buy_rate) > 0) {

            $receipt_pounds_sent =
                floatval($transaction->naira_amount) / floatval($transaction->buy_rate);

        }

        if (empty($receipt_uk_bank_details)) {

            $customers_table = $wpdb->prefix . 'nguk_customers';

            $receipt_customer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $customers_table WHERE customer_name = %s ORDER BY id DESC LIMIT 1",
                    $transaction->customer_name
                )
            );

            if ($receipt_customer) {

                $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

                $receipt_beneficiaries = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM $beneficiaries_table WHERE customer_id = %d",
                        $receipt_customer->id
                    )
                );

                if (count($receipt_beneficiaries) === 1) {

                    $receipt_beneficiary = $receipt_beneficiaries[0];

                    $receipt_uk_bank_details = implode(
                        "\n",
                        array_filter(
                            array(
                                $receipt_beneficiary->bank_name,
                                $receipt_beneficiary->account_number,
                                $receipt_beneficiary->sort_code
                            )
                        )
                    );

                } elseif (!empty($receipt_customer->uk_bank_details)) {

                    $receipt_uk_bank_details = $receipt_customer->uk_bank_details;

                }

            }

        }

        $receipt_invoice_number = 'INV' . (6700 + intval($transaction->id));

        $pdf_lines = array(
            'Transaction Receipt',
            '',
            $business_name,
            $business_phone,
            $business_email,
            $business_address,
            '',
            'Invoice: ' . $receipt_invoice_number,
            'Date: ' . date('d M Y h:i A', strtotime($transaction->created_at)),
            'Customer: ' . strtoupper($transaction->customer_name),
            'Beneficiary: ' . strtoupper($transaction->beneficiary_name),
            '',
            'Nigeria Bank Details:',
            $transaction->nigeria_bank_details,
            '',
            'Receiver Bank Details:',
            $receipt_uk_bank_details,
            '',
            'Naira Paid: NGN ' . number_format($transaction->naira_amount, 2),
            'Pounds Sent: GBP ' . number_format($receipt_pounds_sent, 2),
            'Buy Rate: ' . number_format($transaction->buy_rate, 2),
            'Status: ' . $transaction->status
        );

        $pdf = nguk_build_receipt_pdf($pdf_lines);

        while (ob_get_level()) {

            ob_end_clean();

        }

        nocache_headers();

        header('Content-Type: application/pdf');

        header('Content-Disposition: attachment; filename="' . $receipt_invoice_number . '-receipt.pdf"');

        header('Content-Length: ' . strlen($pdf));

        echo $pdf;

        exit;

    }

    public function dashboard_page() {

        if (
            isset($_GET['ukng_view']) ||
            isset($_GET['ukng_view_customer']) ||
            isset($_GET['ukng_view_receipt']) ||
            isset($_GET['ukng_receipt_id']) ||
            isset($_GET['ukng_add_customer']) ||
            isset($_GET['ukng_delete_transaction']) ||
            isset($_GET['ukng_clear_outstanding']) ||
            isset($_GET['ukng_delete_commission'])
        ) {

            if (!class_exists('UKNG_Dashboard')) {

                require_once NGUK_PLUGIN_PATH . 'includes/class-ukng-dashboard.php';

            }

            $dashboard = new UKNG_Dashboard();
            $dashboard->dashboard_page();
            return;

        }

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'nguk_transactions';

        $nguk_force_panel = '';

        $beneficiary_name_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM $transactions_table LIKE %s",
                'beneficiary_name'
            )
        );

        if (!$beneficiary_name_column) {

            $wpdb->query(
                "ALTER TABLE $transactions_table ADD beneficiary_name varchar(255) NULL"
            );

        }

        /* =========================
           VIEW RECEIPT
        ========================== */

        if (isset($_GET['download_receipt'])) {

            $transactions_table = $wpdb->prefix . 'nguk_transactions';

            $transaction_id = intval($_GET['download_receipt']);

            $transaction = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $transactions_table WHERE id = %d",
                    $transaction_id
                )
            );

            if (!$transaction) {

                wp_die('Receipt not found.');

            }

            $business_name = get_option('nguk_business_name');

            $business_phone = get_option('nguk_business_phone');

            $business_email = get_option('nguk_business_email');

            $business_address = get_option('nguk_business_address');

            $receipt_uk_bank_details = $transaction->uk_bank_details;

            $receipt_pounds_sent = 0;

            if (floatval($transaction->buy_rate) > 0) {

                $receipt_pounds_sent =
                    floatval($transaction->naira_amount) / floatval($transaction->buy_rate);

            }

            if (empty($receipt_uk_bank_details)) {

                $customers_table = $wpdb->prefix . 'nguk_customers';

                $receipt_customer = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $customers_table WHERE customer_name = %s ORDER BY id DESC LIMIT 1",
                        $transaction->customer_name
                    )
                );

                if ($receipt_customer) {

                    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

                    $receipt_beneficiaries = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM $beneficiaries_table WHERE customer_id = %d",
                            $receipt_customer->id
                        )
                    );

                    if (count($receipt_beneficiaries) === 1) {

                        $receipt_beneficiary = $receipt_beneficiaries[0];

                        $receipt_uk_bank_details = implode(
                            "\n",
                            array_filter(
                                array(
                                    $receipt_beneficiary->bank_name,
                                    $receipt_beneficiary->account_number,
                                    $receipt_beneficiary->sort_code
                                )
                            )
                        );

                    } elseif (!empty($receipt_customer->uk_bank_details)) {

                        $receipt_uk_bank_details = $receipt_customer->uk_bank_details;

                    }

                }

            }

            $receipt_invoice_number = 'INV' . (6700 + intval($transaction->id));

            $pdf_lines = array(
                'Transaction Receipt',
                '',
                $business_name,
                $business_phone,
                $business_email,
                $business_address,
                '',
                'Invoice: ' . $receipt_invoice_number,
                'Date: ' . date('d M Y h:i A', strtotime($transaction->created_at)),
                'Customer: ' . strtoupper($transaction->customer_name),
                'Beneficiary: ' . strtoupper($transaction->beneficiary_name),
                '',
                'Nigeria Bank Details:',
                $transaction->nigeria_bank_details,
                '',
                'Receiver Bank Details:',
                $receipt_uk_bank_details,
                '',
                'Naira Paid: NGN ' . number_format($transaction->naira_amount, 2),
                'Pounds Sent: GBP ' . number_format($receipt_pounds_sent, 2),
                'Buy Rate: ' . number_format($transaction->buy_rate, 2),
                'Status: ' . $transaction->status
            );

            $pdf = nguk_build_receipt_pdf($pdf_lines);

            nocache_headers();

            header('Content-Type: application/pdf');

            header('Content-Disposition: attachment; filename="' . $receipt_invoice_number . '-receipt.pdf"');

            header('Content-Length: ' . strlen($pdf));

            echo $pdf;

            exit;

        }

        if (isset($_GET['view_receipt'])) {

            $transactions_table = $wpdb->prefix . 'nguk_transactions';

            $transaction_id = intval($_GET['view_receipt']);

            $transaction = $wpdb->get_row(
                "SELECT * FROM $transactions_table WHERE id = $transaction_id"
            );

            if ($transaction) {

                ?>
<style>

#wpadminbar,
#adminmenu,
#adminmenuback,
#adminmenuwrap,
.notice,
.update-nag,
#screen-meta,
#screen-meta-links,
#wpfooter {
    display:none !important;
}

#wpcontent,
#wpfooter {
    margin-left:0 !important;
}

#wpbody-content {
    padding:0 !important;
}

.wrap {
    margin:0 auto !important;
    max-width:940px;
    background:#fff;
    padding:0;
    border-radius:22px;
    overflow:hidden;
    box-shadow:0 24px 70px rgba(15,23,42,0.16);
}

body {
    background:#eef2f7 !important;
}

.nguk-receipt-shell {
    padding:42px;
}

.nguk-receipt-hero {
    background:linear-gradient(135deg,#0f172a,#0f766e);
    color:#fff;
    padding:34px 42px;
    display:flex;
    justify-content:space-between;
    gap:28px;
    align-items:center;
}

.nguk-receipt-brand {
    display:flex;
    gap:18px;
    align-items:center;
}

.nguk-receipt-logo {
    width:86px;
    height:86px;
    object-fit:contain;
    background:#fff;
    border-radius:18px;
    padding:10px;
}

.nguk-receipt-hero h1,
.nguk-receipt-hero p {
    color:#fff;
    margin:0;
}

.nguk-receipt-company {
    font-size:28px;
    font-weight:800;
    letter-spacing:0;
}

.nguk-receipt-meta {
    text-align:right;
}

.nguk-receipt-meta strong {
    display:block;
    font-size:26px;
    margin-bottom:8px;
}

.nguk-status-pill {
    display:inline-block;
    padding:7px 12px;
    border-radius:999px;
    background:#dcfce7;
    color:#166534;
    font-weight:800;
}

.nguk-receipt-summary {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:16px;
    margin-bottom:24px;
}

.nguk-summary-card,
.nguk-detail-card,
.nguk-transaction-card {
    border:1px solid #e5e7eb;
    border-radius:16px;
    background:#fff;
    box-shadow:0 10px 28px rgba(15,23,42,0.06);
}

.nguk-summary-card {
    padding:18px;
}

.nguk-summary-card span,
.nguk-transaction-table th {
    color:#64748b;
    font-size:12px;
    text-transform:uppercase;
    font-weight:800;
}

.nguk-summary-card strong {
    display:block;
    margin-top:8px;
    font-size:24px;
    color:#0f172a;
}

.nguk-receipt-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
    margin-bottom:24px;
}

.nguk-detail-card {
    padding:22px;
}

.nguk-detail-card h2,
.nguk-transaction-card h2 {
    margin:0 0 16px;
    color:#0f766e;
    font-size:18px;
}

.nguk-detail-card p {
    margin:0 0 16px;
    line-height:1.55;
}

.nguk-transaction-card {
    padding:22px;
}

.nguk-transaction-table {
    width:100%;
    border-collapse:collapse;
}

.nguk-transaction-table td,
.nguk-transaction-table th {
    padding:13px 0;
    border-bottom:1px solid #eef2f7;
    text-align:left;
}

.nguk-transaction-table td {
    font-weight:700;
    color:#0f172a;
}

.nguk-receipt-actions {
    padding:0 42px 34px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}

.nguk-receipt-actions .button {
    border-radius:10px;
    font-weight:700;
}

.wrap > div[style*="text-align:center"] {
    background:linear-gradient(135deg,#0f172a,#0f766e);
    color:#fff;
    padding:34px 42px;
    margin:0 !important;
    text-align:left !important;
}

.wrap > div[style*="text-align:center"] img {
    max-width:96px !important;
    background:#fff;
    padding:10px;
    border-radius:18px;
    box-shadow:0 12px 30px rgba(15,23,42,0.2);
}

.wrap > div[style*="text-align:center"] h1,
.wrap > div[style*="text-align:center"] p {
    color:#fff;
}

.wrap > div[style*="text-align:center"] h1 {
    font-size:30px;
    font-weight:800;
    margin-top:14px !important;
}

.wrap > h1 {
    margin:0 !important;
    padding:34px 42px 8px;
    font-size:28px;
    font-weight:800;
    color:#0f172a;
}

.wrap > h1:after {
    content:'';
    display:block;
    width:70px;
    height:4px;
    background:#0f766e;
    border-radius:999px;
    margin-top:12px;
}

.wrap > div[style*="display:flex"] {
    padding:0 42px;
    gap:18px !important;
}

.wrap > div[style*="display:flex"] > div,
.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] {
    border:1px solid #e5e7eb !important;
    border-radius:18px !important;
    box-shadow:0 12px 32px rgba(15,23,42,0.07);
    background:#fff;
}

.wrap > div[style*="display:flex"] h2,
.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] h2 {
    color:#0f766e !important;
    font-size:18px;
    font-weight:800;
}

.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] {
    margin:24px 42px 0 !important;
}

.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] table td {
    border-bottom:1px solid #eef2f7;
}

.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] table tr:last-child td {
    border-bottom:0;
}

.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] table td:first-child {
    color:#64748b;
    text-transform:uppercase;
    font-size:12px;
    font-weight:800;
}

.wrap > div[style*="border:1px solid #ddd"][style*="margin-bottom:30px"] table td:last-child {
    font-weight:800;
    color:#0f172a;
}

.wrap > p[style*="margin-top:20px"] {
    padding:28px 42px 36px;
    margin:0 !important;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}

.wrap > p[style*="margin-top:20px"] .button {
    margin-left:0 !important;
    border-radius:10px;
    font-weight:700;
}

@media print {
    .nguk-receipt-actions {
        display:none !important;
    }
    body {
        background:#fff !important;
    }
    .wrap {
        box-shadow:none;
        border-radius:0;
    }
}

@media (max-width:760px) {
    .nguk-receipt-hero,
    .nguk-receipt-brand {
        display:block;
        text-align:left;
    }
    .nguk-receipt-meta {
        text-align:left;
        margin-top:22px;
    }
    .nguk-receipt-shell {
        padding:24px;
    }
    .nguk-receipt-summary,
    .nguk-receipt-grid {
        grid-template-columns:1fr;
    }
}

</style>
                <div class="wrap">
                    <?php

$business_logo = self::business_logo_url();

$business_name = get_option('nguk_business_name');

$business_phone = get_option('nguk_business_phone');

$business_email = get_option('nguk_business_email');

$business_address = get_option('nguk_business_address');

$receipt_uk_bank_details = $transaction->uk_bank_details;

$receipt_pounds_sent = 0;

if (floatval($transaction->buy_rate) > 0) {

    $receipt_pounds_sent =
        floatval($transaction->naira_amount) / floatval($transaction->buy_rate);

}

if (empty($receipt_uk_bank_details)) {

    $customers_table = $wpdb->prefix . 'nguk_customers';

    $receipt_customer = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $customers_table WHERE customer_name = %s ORDER BY id DESC LIMIT 1",
            $transaction->customer_name
        )
    );

    if ($receipt_customer) {

        $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

        $receipt_beneficiaries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $beneficiaries_table WHERE customer_id = %d",
                $receipt_customer->id
            )
        );

        if (count($receipt_beneficiaries) === 1) {

            $receipt_beneficiary = $receipt_beneficiaries[0];

            $receipt_uk_bank_details = implode(
                "\n",
                array_filter(
                    array(
                        $receipt_beneficiary->bank_name,
                        $receipt_beneficiary->account_number,
                        $receipt_beneficiary->sort_code
                    )
                )
            );

        } elseif (!empty($receipt_customer->uk_bank_details)) {

            $receipt_uk_bank_details = $receipt_customer->uk_bank_details;

        }

    }

}

$receipt_invoice_number = 'INV' . (6700 + intval($transaction->id));

$receipt_url = admin_url(
    'admin.php?page=nguk-transfer&view_receipt=' . intval($transaction->id)
);

$receipt_share_text = implode(
    "\n",
    array(
        'Transaction Receipt',
        'Invoice: ' . $receipt_invoice_number,
        'Business: ' . $business_name,
        'Customer: ' . strtoupper($transaction->customer_name),
        'Naira Paid: NGN ' . number_format($transaction->naira_amount, 2),
        'Pounds Sent: GBP ' . number_format($receipt_pounds_sent, 2),
        'Buy Rate: ' . number_format($transaction->buy_rate, 2),
        'Status: ' . $transaction->status,
        '',
        'Receiver Bank Details:',
        $receipt_uk_bank_details,
        '',
        'Receipt Link: ' . $receipt_url
    )
);

$whatsapp_receipt_url = 'https://wa.me/?text=' . rawurlencode($receipt_share_text);

$email_receipt_url = 'mailto:?subject=' . rawurlencode(
    'Receipt ' . $receipt_invoice_number . ' - ' . $business_name
) . '&body=' . rawurlencode($receipt_share_text);

$receipt_qr_url = self::qr_code_url(
    array(
        'Transaction ID: ' . $receipt_invoice_number,
        'Customer: ' . strtoupper($transaction->customer_name),
        'Amount: GBP ' . number_format($receipt_pounds_sent, 2),
        'Status: ' . $transaction->status
    )
);

$download_receipt_lines = array(
    'Transaction Receipt',
    '',
    $business_name,
    $business_phone,
    $business_email,
    $business_address,
    '',
    'Invoice: ' . $receipt_invoice_number,
    'Date: ' . date('d M Y h:i A', strtotime($transaction->created_at)),
        'Customer: ' . strtoupper($transaction->customer_name),
        'Beneficiary: ' . strtoupper($transaction->beneficiary_name),
    '',
    'Nigeria Bank Details:',
    $transaction->nigeria_bank_details,
    '',
    'Receiver Bank Details:',
    $receipt_uk_bank_details,
    '',
    'Naira Paid: NGN ' . number_format($transaction->naira_amount, 2),
    'Pounds Sent: GBP ' . number_format($receipt_pounds_sent, 2),
    'Buy Rate: ' . number_format($transaction->buy_rate, 2),
    'Status: ' . $transaction->status
);

?>

<div style="text-align:center;margin-bottom:25px;">

    <?php if (!empty($business_logo)) : ?>

        <img src="<?php echo esc_url($business_logo); ?>"
             style="max-width:120px;height:auto;margin-bottom:15px;">

    <?php endif; ?>

    <h1 style="margin:0;">
        <?php echo esc_html($business_name); ?>
    </h1>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_phone); ?>
    </p>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_email); ?>
    </p>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_address); ?>
    </p>

</div>

  <h1 style="margin-bottom:30px;">
    Transaction Receipt
</h1>

<div style="
display:flex;
justify-content:space-between;
gap:20px;
margin-bottom:30px;
">

    <div style="
    width:48%;
    border:1px solid #ddd;
    padding:20px;
    border-radius:10px;
    ">

        <h2 style="margin-top:0;color:#2271b1;">
            Sender Details
        </h2>

        <p>
            <strong>Customer:</strong><br>
            <?php echo esc_html(strtoupper($transaction->customer_name)); ?>
        </p>

        <p>
            <strong>Nigeria Bank:</strong><br>
       <?php echo nl2br(esc_html($transaction->nigeria_bank_details)); ?>
        </p>

    </div>

    <div style="
    width:48%;
    border:1px solid #ddd;
    padding:20px;
    border-radius:10px;
    ">

        <h2 style="margin-top:0;color:#2271b1;">
            Receiver Details
        </h2>

        <p>
            <strong>Beneficiary Bank:</strong><br>
         <?php echo nl2br(esc_html($receipt_uk_bank_details)); ?>
        </p>

    </div>

</div>

<div style="
border:1px solid #ddd;
padding:20px;
border-radius:10px;
margin-bottom:30px;
">

    <h2 style="margin-top:0;color:#2271b1;">
        Transaction Details
    </h2>

    <table style="width:100%;">

        <tr>
            <td style="padding:10px 0;">
                <strong>Transaction ID</strong>
            </td>

            <td>
                <?php echo esc_html($receipt_invoice_number); ?>
            </td>
        </tr>

        <tr>
            <td style="padding:10px 0;">
                <strong>Date</strong>
            </td>

            <td>
                <?php echo date('d M Y h:i A'); ?>
            </td>
        </tr>

        <tr>
            <td style="padding:10px 0;">
                <strong>Naira Paid</strong>
            </td>

            <td>
                ₦<?php echo number_format($transaction->naira_amount, 2); ?>
            </td>
        </tr>

        <tr>
            <td style="padding:10px 0;">
                <strong>Pounds Sent</strong>
            </td>

            <td>
                £<?php echo number_format($receipt_pounds_sent, 2); ?>
            </td>
        </tr>

        <tr>
            <td style="padding:10px 0;">
                <strong>Buy Rate</strong>
            </td>

            <td>
                <?php echo number_format($transaction->buy_rate, 2); ?>
            </td>
        </tr>

        <tr>
            <td style="padding:10px 0;">
                <strong>Status</strong>
            </td>

            <td style="color:green;font-weight:bold;">
                <?php echo esc_html($transaction->status); ?>
            </td>
        </tr>

    </table>

</div>

<div style="
border:1px solid #ddd;
padding:20px;
border-radius:10px;
margin-bottom:30px;
display:flex;
gap:18px;
align-items:center;
">
    <img src="<?php echo esc_url($receipt_qr_url); ?>"
         alt="Receipt QR code"
         style="width:150px;height:150px;">
    <div>
        <h2 style="margin-top:0;color:#2271b1;">
            Receipt QR Code
        </h2>
        <p style="margin-bottom:0;">
            Scan to view transaction ID, customer, amount, and status.
        </p>
    </div>
</div>

                    <p style="margin-top:20px;">

                        <button onclick="window.print()"
                                class="button button-primary">

                            Print Receipt

                        </button>

                        <a href="<?php echo esc_url($whatsapp_receipt_url); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="button"
                           style="background:#25D366;border-color:#25D366;color:#fff;margin-left:8px;">

                           Share on WhatsApp

                        </a>

                        <a href="<?php echo esc_url($email_receipt_url); ?>"
                           class="button"
                           style="margin-left:8px;">

                           Email Receipt

                        </a>

                        <button type="button"
                                id="downloadReceiptButton"
                                class="button"
                                style="margin-left:8px;">

                            Download Receipt

                        </button>

                        <a href="?page=nguk-transfer"
                           class="button">

                           Back

                        </a>

                    </p>

                    <script>
                    (function(){

                        var receiptFilename = <?php echo wp_json_encode($receipt_invoice_number . '-receipt.html'); ?>;

                        document.getElementById('downloadReceiptButton').addEventListener('click', function(){

                            var receipt = document.querySelector('.wrap').cloneNode(true);

                            var actions = receipt.querySelector('p[style*="margin-top:20px"]');

                            if(actions){

                                actions.remove();

                            }

                            var html =
                                '<!doctype html>' +
                                '<html>' +
                                '<head>' +
                                '<meta charset="utf-8">' +
                                '<meta name="viewport" content="width=device-width, initial-scale=1">' +
                                '<title><?php echo esc_js($receipt_invoice_number); ?> Receipt</title>' +
                                '<style>' +
                                (document.querySelector('style') ? document.querySelector('style').innerHTML : '') +
                                'body{margin:0;background:#e9e9e9;font-family:Arial,Helvetica,sans-serif;color:#111827;}' +
                                '.wrap{max-width:940px;margin:30px auto;background:#fff;padding:0;box-sizing:border-box;border-radius:22px;overflow:hidden;}' +
                                'table{border-collapse:collapse;width:100%;}' +
                                'td,th{vertical-align:top;}' +
                                '@media print{body{background:#fff}.wrap{margin:0 auto;box-shadow:none}}' +
                                '</style>' +
                                '</head>' +
                                '<body>' +
                                receipt.outerHTML +
                                '</body>' +
                                '</html>';

                            var blob = new Blob(
                                [html],
                                { type: 'text/html' }
                            );

                            var url = URL.createObjectURL(blob);

                            var link = document.createElement('a');

                            link.href = url;

                            link.download = receiptFilename;

                            document.body.appendChild(link);

                            link.click();

                            document.body.removeChild(link);

                            setTimeout(function(){

                                URL.revokeObjectURL(url);

                            }, 1000);

                        });

                    })();
                    </script>

                </div>

                <?php

                return;
            }
        }
/*
=========================================
SAVE BENEFICIARY
=========================================
*/

if (isset($_POST['save_beneficiary'])) {

    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

    $wpdb->insert(

        $beneficiaries_table,

        array(

            'customer_id' => intval($_POST['customer_id']),

            'beneficiary_name' => strtoupper(sanitize_text_field($_POST['beneficiary_name'])),

            'bank_name' => sanitize_text_field($_POST['bank_name']),

            'account_name' => strtoupper(sanitize_text_field($_POST['beneficiary_name'])),

            'account_number' => sanitize_text_field($_POST['account_number']),

            'sort_code' => sanitize_text_field($_POST['sort_code']),

            'notes' => sanitize_textarea_field($_POST['beneficiary_notes'])

        )

    );

    echo '<div class="updated"><p>Beneficiary saved successfully.</p></div>';

}

/*
=========================================
SAVE CUSTOMER
=========================================
*/

if (isset($_POST['save_customer'])) {

    $customers_table = $wpdb->prefix . 'nguk_customers';
    $kyc_documents = self::upload_kyc_documents();

    $wpdb->insert(

        $customers_table,

        array(

            'customer_name' => strtoupper(sanitize_text_field($_POST['customer_name'])),

            'phone_number' => sanitize_text_field($_POST['phone_number']),

            'address' => sanitize_textarea_field($_POST['address']),

            'notes' => '',

            'nigeria_bank_details' => '',

            'uk_bank_details' => sanitize_textarea_field($_POST['uk_bank_details']),

            'kyc_documents' => wp_json_encode($kyc_documents)

        )

    );

    echo '<div class="updated"><p>Customer created successfully.</p></div>';

}

/*
=========================================
UPDATE CUSTOMER NOTES
=========================================
*/

if (isset($_POST['update_customer_notes'])) {

    $customers_table = $wpdb->prefix . 'nguk_customers';

    $customer_id = isset($_POST['customer_id'])
        ? intval($_POST['customer_id'])
        : 0;

    $customer_notes = isset($_POST['customer_notes'])
        ? sanitize_textarea_field($_POST['customer_notes'])
        : '';

    if ($customer_id > 0) {

        $wpdb->update(

            $customers_table,

            array(
                'notes' => $customer_notes
            ),

            array(
                'id' => $customer_id
            )

        );

        echo '<div class="updated"><p>Customer note / risk assessment updated successfully.</p></div>';

    }

}

if (isset($_GET['delete_customer'])) {

    $customers_table = $wpdb->prefix . 'nguk_customers';
    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';
    $customer_id = intval($_GET['delete_customer']);

    if ($customer_id > 0 && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'nguk_delete_customer_' . $customer_id)) {

        $wpdb->delete($beneficiaries_table, array('customer_id' => $customer_id), array('%d'));
        $wpdb->delete($customers_table, array('id' => $customer_id), array('%d'));

        echo '<div class="updated"><p>Customer deleted successfully.</p></div>';
        $nguk_force_panel = 'customers';

    } else {

        echo '<div class="notice notice-error"><p>Customer delete request could not be verified.</p></div>';

    }

}
        /* =========================
           VIEW CUSTOMER
        ========================== */

        if (isset($_GET['view_customer'])) {

            $customers_table = $wpdb->prefix . 'nguk_customers';

            $customer_id = intval($_GET['view_customer']);

            $customer = $wpdb->get_row(
                "SELECT * FROM $customers_table WHERE id = $customer_id"
            );

            if ($customer) {

                ?>

                <div class="wrap">
                    <?php

$business_logo = self::business_logo_url();

$business_name = get_option('nguk_business_name');

$business_phone = get_option('nguk_business_phone');

$business_email = get_option('nguk_business_email');

$business_address = get_option('nguk_business_address');

?>

<div style="text-align:center;margin-bottom:25px;">

    <?php if (!empty($business_logo)) : ?>

        <img src="<?php echo esc_url($business_logo); ?>"
             style="max-width:120px;height:auto;margin-bottom:15px;">

    <?php endif; ?>

    <h1 style="margin:0;">
        <?php echo esc_html($business_name); ?>
    </h1>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_phone); ?>
    </p>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_email); ?>
    </p>

    <p style="margin:5px 0;">
        <?php echo esc_html($business_address); ?>
    </p>

</div>

                    <h1>Customer Profile</h1>

                    <table class="widefat striped" style="max-width:800px;">

                        <tr>
                            <th>Customer Name</th>
                            <td class="nguk-name-strong"><?php echo esc_html(strtoupper($customer->customer_name)); ?></td>
                        </tr>

                        <tr>
                            <th>Phone</th>
                            <td><?php echo esc_html($customer->phone_number); ?></td>
                        </tr>

                        <tr>
                            <th>Address</th>
                            <td><?php echo esc_html($customer->address); ?></td>
                        </tr>

                        <tr>
                            <th>Nigeria Bank</th>
                            <td><?php echo esc_html($customer->nigeria_bank_details); ?></td>
                        </tr>

                        <tr>
                            <th>UK Bank</th>
                            <td><?php echo esc_html($customer->uk_bank_details); ?></td>
                        </tr>

                        <tr>
                            <th>Notes</th>
                            <td><?php echo esc_html($customer->notes); ?></td>
                        </tr>

                        <tr>
                            <th>KYC Documents</th>
                            <td><?php self::render_kyc_documents(isset($customer->kyc_documents) ? $customer->kyc_documents : ''); ?></td>
                        </tr>

                    </table>
<hr style="margin:40px 0;">

<h2>Add Beneficiary</h2>

<form method="post">

<input type="hidden"
       name="customer_id"
       value="<?php echo $customer->id; ?>">

<table class="form-table">

<tr>
    <th>Beneficiary / Account Holder Name</th>

    <td>
        <input type="text"
               name="beneficiary_name"
               class="regular-text"
               required>
    </td>
</tr>

<tr>
    <th>Bank Name</th>

    <td>
        <?php
        $nguk_uk_banks = array(
            'Bank of Scotland',
            'Barclays',
            'Chase UK',
            'Clydesdale Bank',
            'Co-operative Bank',
            'First Direct',
            'Halifax',
            'HSBC',
            'Lloyds Bank',
            'Metro Bank',
            'Monzo',
            'Nationwide',
            'NatWest',
            'Revolut',
            'Royal Bank of Scotland (RBS)',
            'Santander',
            'Starling Bank',
            'TSB',
            'Virgin Money',
            'Yorkshire Bank'
        );
        $selected_bank_name = isset($_POST['bank_name']) ? sanitize_text_field($_POST['bank_name']) : '';
        ?>
        <select id="nguk_uk_bank_select"
                name="bank_name"
                class="regular-text nguk-bank-select"
                required>
            <option value="">Select UK bank</option>
            <?php foreach ($nguk_uk_banks as $bank_name) { ?>
                <option value="<?php echo esc_attr($bank_name); ?>" <?php selected($selected_bank_name, $bank_name); ?>>
                    <?php echo esc_html($bank_name); ?>
                </option>
            <?php } ?>
        </select>
    </td>
</tr>

<tr>
    <th>Account Number</th>

    <td>
        <input type="text"
               name="account_number"
               class="regular-text"
               required>
    </td>
</tr>

<tr>
    <th>Sort Code</th>

    <td>
        <input type="text"
               name="sort_code"
               class="regular-text">
    </td>
</tr>

<tr>
    <th>Notes</th>

    <td>
        <textarea name="beneficiary_notes"
                  class="large-text"></textarea>
    </td>
</tr>

</table>

<p>

<input type="submit"
       name="save_beneficiary"
       class="button button-primary"
       value="Save Beneficiary">

</p>

</form>
<hr style="margin:40px 0;">

<h2>Saved Beneficiaries</h2>

<?php

$beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

$beneficiaries = $wpdb->get_results(

    $wpdb->prepare(

        "SELECT * FROM $beneficiaries_table
         WHERE customer_id = %d
         ORDER BY id DESC",

        $customer->id

    )

);

if ($beneficiaries) {

?>

<table class="widefat striped" style="max-width:1000px;">

<thead>

<tr>

<th>Beneficiary / Account Holder</th>

<th>Bank</th>

<th>Account Number</th>

<th>Sort Code</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php foreach ($beneficiaries as $beneficiary) { ?>

<tr>

<td>
<span class="nguk-name-strong"><?php echo esc_html(strtoupper($beneficiary->beneficiary_name)); ?></span>
</td>

<td>
<?php echo esc_html($beneficiary->bank_name); ?>
</td>

<td>
<?php echo esc_html($beneficiary->account_number); ?>
</td>

<td>
<?php echo esc_html($beneficiary->sort_code); ?>
</td>
<td>

<a href="?page=nguk-transfer&nguk_view=payments&customer_id=<?php echo $customer->id; ?>&beneficiary_id=<?php echo $beneficiary->id; ?>#nguk-create-transaction"

class="button button-primary">

Send Money

</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php

} else {

    echo '<p>No beneficiaries found for this customer.</p>';

}

?>
                    <p style="margin-top:20px;">

                        <a href="?page=nguk-transfer"
                           class="button button-primary">

                           Back to Dashboard

                        </a>

                    </p>

                </div>

                <?php

                return;
            }
        }
        if (isset($_POST['save_business_settings'])) {

    update_option('nguk_business_name', sanitize_text_field($_POST['business_name']));

    update_option('nguk_business_phone', sanitize_text_field($_POST['business_phone']));

    update_option('nguk_business_email', sanitize_email($_POST['business_email']));

    update_option('nguk_business_address', sanitize_textarea_field($_POST['business_address']));

    update_option('nguk_business_website', sanitize_text_field($_POST['business_website']));

    update_option('nguk_business_logo', esc_url_raw($_POST['business_logo']));

    echo '<div class="updated"><p>Business settings saved successfully.</p></div>';
}
if (isset($_POST['save_dashboard_theme'])) {

    $theme = isset($_POST['dashboard_theme']) && $_POST['dashboard_theme'] === 'dark'
        ? 'dark'
        : 'normal';

    update_option('nguk_dashboard_theme', $theme);

    echo '<div class="updated"><p>Dashboard theme saved successfully.</p></div>';

}
if (isset($_POST['save_exchange_rates'])) {

    update_option('nguk_buy_rate', sanitize_text_field($_POST['buy_rate']));

    update_option('nguk_sell_rate', sanitize_text_field($_POST['sell_rate']));

    update_option(
        'nguk_transfer_fee',
        isset($_POST['transfer_fee']) ? sanitize_text_field($_POST['transfer_fee']) : '0'
    );

    echo '<div class="updated"><p>Exchange rates updated successfully.</p></div>';

}
if (isset($_POST['save_bank_account'])) {

    $bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

    $wpdb->insert(

        $bank_accounts_table,

        array(

            'account_type' => 'Nigeria',

            'bank_name' => sanitize_text_field($_POST['bank_name']),

            'account_name' => sanitize_text_field($_POST['account_name']),

            'account_number' => sanitize_text_field($_POST['account_number']),

            'extra_details' => sanitize_text_field($_POST['extra_details'])

        )

    );

    echo '<div class="updated"><p>Bank account saved successfully.</p></div>';

}
if (isset($_GET['delete_bank_account'])) {

    $bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

    $bank_account_id = intval($_GET['delete_bank_account']);

    $wpdb->delete(

        $bank_accounts_table,

        array(
            'id' => $bank_account_id
        )

    );

    echo '<div class="updated"><p>Bank account deleted successfully.</p></div>';

}

        if (isset($_POST['save_transaction'])) {

    $transactions_table = $wpdb->prefix . 'nguk_transactions';

    $customer_id = isset($_POST['customer_id'])
        ? intval($_POST['customer_id'])
        : 0;

    $beneficiary_id = isset($_POST['beneficiary_id'])
        ? intval($_POST['beneficiary_id'])
        : 0;

    $naira_amount = floatval($_POST['naira_amount']);

    $buy_rate = floatval(get_option('nguk_buy_rate', '2000'));

    $sell_rate = floatval(get_option('nguk_sell_rate', '1900'));

    if ($buy_rate > 0) {

        $pounds_amount = $naira_amount / $buy_rate;

    } else {

        $pounds_amount = 0;

    }
    if ($sell_rate > 0 && $buy_rate > 0) {

        $profit =
            ($naira_amount / $sell_rate) - ($naira_amount / $buy_rate);

    } else {

        $profit = 0;

    }
    $customers_table = $wpdb->prefix . 'nguk_customers';

    $customer = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $customers_table WHERE id = %d",
            $customer_id
        )
    );

    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

    $beneficiary = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $beneficiaries_table WHERE id = %d AND customer_id = %d",
            $beneficiary_id,
            $customer_id
        )
    );

    if (!$customer || !$beneficiary) {

        echo '<div class="notice notice-error"><p>Please select a valid customer and one of that customer\'s beneficiaries.</p></div>';

    } else {

$customer_name = strtoupper($customer->customer_name);
    $nigeria_bank = sanitize_textarea_field($_POST['nigeria_bank_details']);

    $uk_bank = implode(
        "\n",
        array_filter(
            array(
                $beneficiary->bank_name,
                $beneficiary->account_number,
                $beneficiary->sort_code
            )
        )
    );

    if (!isset($_POST['confirm_transaction'])) {

        ?>

        <div class="wrap">

            <h1>Confirm Transaction</h1>

            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:25px;max-width:900px;">

                <h2>Transaction Preview</h2>

                <table class="widefat striped nguk-transaction-table">

                    <tbody>

                        <tr>
                            <th>Customer</th>
                            <td class="nguk-name-strong"><?php echo esc_html(strtoupper($customer_name)); ?></td>
                        </tr>

                        <tr>
                            <th>Beneficiary</th>
                            <td class="nguk-name-strong"><?php echo esc_html(strtoupper($beneficiary->beneficiary_name)); ?></td>
                        </tr>

                        <tr>
                            <th>Nigeria Bank</th>
                            <td><?php echo nl2br(esc_html($nigeria_bank)); ?></td>
                        </tr>

                        <tr>
                            <th>Receiver Bank Details</th>
                            <td><?php echo nl2br(esc_html($uk_bank)); ?></td>
                        </tr>

                        <tr>
                            <th>Naira Paid</th>
                            <td>₦<?php echo number_format($naira_amount, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Pounds Sent</th>
                            <td>£<?php echo number_format($pounds_amount, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Buy Rate</th>
                            <td><?php echo number_format($buy_rate, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Status</th>
                            <td>Pending</td>
                        </tr>

                    </tbody>

                </table>

                <form method="post"
                      style="margin-top:20px;">

                    <input type="hidden"
                           name="customer_id"
                           value="<?php echo intval($customer_id); ?>">

                    <input type="hidden"
                           name="beneficiary_id"
                           value="<?php echo intval($beneficiary_id); ?>">

                    <input type="hidden"
                           name="nigeria_bank_details"
                           value="<?php echo esc_attr($nigeria_bank); ?>">

                    <input type="hidden"
                           name="naira_amount"
                           value="<?php echo esc_attr($naira_amount); ?>">

                    <input type="hidden"
                           name="confirm_transaction"
                           value="1">

                    <input type="submit"
                           name="save_transaction"
                           class="button button-primary"
                           value="Confirm and Submit Transaction">

                    <button type="button"
                            onclick="history.back();"
                            class="button">
                        Back to Edit
                    </button>

                </form>

            </div>

        </div>

        <?php

        return;

    }

    $transaction_inserted = $wpdb->insert(

        $transactions_table,

        array(

            'customer_name' => $customer_name,

            'beneficiary_name' => strtoupper($beneficiary->beneficiary_name),

            'naira_amount' => $naira_amount,

            'pounds_amount' => $pounds_amount,

            'profit' => $profit,

            'buy_rate' => $buy_rate,

            'sell_rate' => $sell_rate,
            'nigeria_bank_details' => $nigeria_bank,

            'uk_bank_details' => $uk_bank,

            'status' => 'Pending',

            'tracking_code' => NGUK_Database::generate_tracking_code(),

            'status_updated_at' => current_time('mysql')

        )

    );

    if ($transaction_inserted === false) {

        echo '<div class="notice notice-error"><p>Transaction could not be created. ' . esc_html($wpdb->last_error) . '</p></div>';

        $nguk_force_panel = 'payments';

    } else {

        $nguk_force_panel = 'transactions';

        echo '<div class="updated"><p>Transaction has been successfully submitted.</p></div>';

    }

    }

}
if (isset($_POST['update_transaction_status'])) {

    $transactions_table = $wpdb->prefix . 'nguk_transactions';

    $transaction_id = isset($_POST['transaction_id'])
        ? intval($_POST['transaction_id'])
        : 0;

    $new_status = isset($_POST['transaction_status'])
        ? sanitize_text_field($_POST['transaction_status'])
        : '';

    $allowed_statuses = array(
        'Pending',
        'Payment Received',
        'Processing',
        'Paid Out',
        'Paid',
        'Returned',
        'Cancelled'
    );

    if (
        $transaction_id > 0 &&
        in_array($new_status, $allowed_statuses, true)
    ) {

        $wpdb->update(

            $transactions_table,

            array(
                'status' => $new_status,
                'status_updated_at' => current_time('mysql')
            ),

            array(
                'id' => $transaction_id
            )

        );

        echo '<div class="updated"><p>Transaction status updated successfully.</p></div>';

    } else {

        echo '<div class="notice notice-error"><p>Please select a valid transaction status.</p></div>';

    }

}
if (isset($_GET['delete_transaction'])) {

    global $wpdb;

    $transactions_table = $wpdb->prefix . 'nguk_transactions';

    $transaction_id = intval($_GET['delete_transaction']);

    $wpdb->delete(

        $transactions_table,

        array(
            'id' => $transaction_id
        )

    );

    echo '<div class="updated"><p>Transaction deleted successfully.</p></div>';

}

$nguk_current_panel = isset($_GET['nguk_view'])
    ? sanitize_key($_GET['nguk_view'])
    : '';

if (!empty($nguk_force_panel)) {

    $nguk_current_panel = $nguk_force_panel;

} elseif (
    isset($_GET['customer_id']) ||
    isset($_GET['beneficiary_id'])
) {

    $nguk_current_panel = 'payments';

} elseif (
    isset($_GET['transaction_search']) ||
    isset($_GET['transaction_page']) ||
    isset($_GET['transaction_created']) ||
    isset($_GET['transaction_from_date']) ||
    isset($_GET['transaction_to_date']) ||
    isset($_GET['transaction_quick_date'])
) {

    $nguk_current_panel = 'transactions';

} elseif (
    isset($_GET['create_customer']) ||
    isset($_GET['delete_customer']) ||
    isset($_GET['view_customer_note']) ||
    isset($_GET['customer_search']) ||
    isset($_GET['customer_page'])
) {

    $nguk_current_panel = 'customers';

} elseif (isset($_GET['reminder_id'])) {

    $nguk_current_panel = 'reminders';

} elseif (isset($_GET['monthly_page'])) {

    $nguk_current_panel = 'reports';

} elseif (empty($nguk_current_panel)) {

    $nguk_current_panel = 'overview';

}

$nguk_allowed_panels = array(
    'overview',
    'payments',
    'customers',
    'transactions',
    'reminders',
    'reports',
    'settings'
);

if (!in_array($nguk_current_panel, $nguk_allowed_panels, true)) {

    $nguk_current_panel = 'overview';

}

$nguk_panel_class = function($panel) use ($nguk_current_panel) {

    return $panel === $nguk_current_panel
        ? 'nguk-tab-panel is-active'
        : 'nguk-tab-panel';

};
        ?>

        <?php $nguk_dashboard_theme = get_option('nguk_dashboard_theme', 'normal'); ?>

        <div class="wrap nguk-dashboard-shell <?php echo $nguk_dashboard_theme === 'dark' ? 'nguk-dashboard-dark' : ''; ?>">

            <div class="nguk-app-hero">
                <div>
                    <p class="nguk-app-kicker">Daphkoy Operations</p>
                    <h1>NG-UK Money Transfer Dashboard</h1>
                    <p class="nguk-app-subtitle">Manage rates, customers, transfers, receipts, and monthly performance.</p>
                </div>
                <div class="nguk-hero-actions">
                    <button type="button"
                            class="nguk-account-button"
                            id="ngukAccountButton"
                            aria-expanded="false">
                        <span class="dashicons dashicons-admin-users"></span>
                        Account
                    </button>

                    <a href="?page=nguk-transfer&nguk_view=payments#nguk-create-transaction"
                       class="button button-primary nguk-hero-action">
                       New Transfer
                    </a>

                    <form id="ngukUkngSwitchForm"
                          method="get"
                          action="<?php echo esc_url(admin_url('admin.php')); ?>"
                          style="margin:0;">
                        <input type="hidden" name="page" value="nguk-transfer">
                        <input type="hidden" name="ukng_view" value="overview">
                        <button type="submit"
                                class="button nguk-ukng-switch-button">
                            UK to Nigeria
                        </button>
                    </form>
                </div>
            </div>

            <div class="nguk-account-panel"
                 id="ngukAccountPanel">
                <h2>Account Access</h2>
                <a href="<?php echo esc_url(wp_login_url(admin_url('admin.php?page=nguk-transfer'))); ?>"
                   class="button button-primary">
                   Login
                </a>
                <a href="<?php echo esc_url(wp_registration_url()); ?>"
                   class="button">
                   Register
                </a>
                <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"
                   class="button">
                   Forgot Password
                </a>
                <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"
                   class="button"
                   style="background:#ef4444;border-color:#ef4444;color:#fff;">
                   Logout
                </a>
            </div>

            <nav class="nguk-app-nav" aria-label="Dashboard sections">
                <a href="?page=nguk-transfer&nguk_view=overview" class="nguk-nav-button" data-nguk-tab="overview" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('overview');return false;}">Overview</a>
                <a href="?page=nguk-transfer&nguk_view=payments" class="nguk-nav-button" data-nguk-tab="payments" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('payments');return false;}">Payments</a>
                <a href="?page=nguk-transfer&nguk_view=customers" class="nguk-nav-button" data-nguk-tab="customers" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('customers');return false;}">Customers</a>
                <a href="?page=nguk-transfer&nguk_view=transactions" class="nguk-nav-button" data-nguk-tab="transactions" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('transactions');return false;}">Transactions</a>
                <a href="?page=nguk-transfer&nguk_view=reminders" class="nguk-nav-button" data-nguk-tab="reminders" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('reminders');return false;}"><?php echo wp_kses_post(NGUK_Reminders::render_badge('nguk')); ?></a>
                <a href="?page=nguk-transfer&nguk_view=reports" class="nguk-nav-button" data-nguk-tab="reports" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('reports');return false;}">Reports</a>
                <a href="?page=nguk-transfer&nguk_view=settings" class="nguk-nav-button" data-nguk-tab="settings" onclick="if(window.ngukShowDashboardPanel){window.ngukShowDashboardPanel('settings');return false;}">Settings</a>
            </nav>

            <?php NGUK_Reminders::render_ticker('nguk_view', 'nguk'); ?>

            <script>
            (function(){
                var panelsReady = false;

                function closestPanel(element){
                    while(element && element !== document.body){
                        if(element.tagName && element.tagName.toLowerCase() === 'div'){
                            return element;
                        }
                        element = element.parentNode;
                    }
                    return null;
                }

                function findPanelByHeading(headingText){
                    var headings = document.querySelectorAll('h2');
                    for(var index = 0; index < headings.length; index++){
                        if(headings[index].textContent.replace(/\s+/g, ' ').trim() === headingText){
                            return closestPanel(headings[index]);
                        }
                    }
                    return null;
                }

                function addPanel(element, panel){
                    if(!element){
                        return;
                    }
                    element.classList.add('nguk-tab-panel');
                    element.setAttribute('data-nguk-panel', panel);
                }

                function addPanelByHeading(headingText, panel, useParent){
                    var element = findPanelByHeading(headingText);
                    if(element && useParent && element.parentElement){
                        element = element.parentElement;
                    }
                    addPanel(element, panel);
                }

                function setupPanels(){
                    panelsReady = true;

                    var rateCard = document.querySelector('.nguk-rate-card');
                    if(rateCard && rateCard.parentElement){
                        addPanel(rateCard.parentElement, 'overview');
                    }

                    var rateLink = document.querySelector('a[href*="edit_rates"]');
                    if(rateLink && rateLink.parentElement){
                        addPanel(rateLink.parentElement, 'overview');
                    }

                    addPanel(document.getElementById('nguk-rate-settings'), 'overview');
                    addPanelByHeading('Business Details', 'settings', false);
                    addPanel(document.getElementById('nguk-create-customer'), 'customers');
                    addPanelByHeading('Registered Customers', 'customers', false);
                    addPanelByHeading('Register Bank Account', 'payments', true);
                    addPanel(document.getElementById('nguk-create-transaction'), 'payments');
                    addPanelByHeading('Recent Transactions', 'transactions', false);
                    addPanelByHeading('Monthly Turnovers', 'reports', false);
                }

                window.ngukShowDashboardPanel = function(panel){
                    panel = panel || 'overview';

                    if(!panelsReady || !document.querySelectorAll('.nguk-tab-panel').length){
                        setupPanels();
                    }

                    var panels = document.querySelectorAll('.nguk-tab-panel');
                    var activePanels = document.querySelectorAll('.nguk-tab-panel[data-nguk-panel="' + panel + '"]');

                    if(!activePanels.length){
                        panel = 'overview';
                        activePanels = document.querySelectorAll('.nguk-tab-panel[data-nguk-panel="overview"]');
                    }

                    for(var index = 0; index < panels.length; index++){
                        panels[index].classList.remove('is-active');
                    }

                    for(var activeIndex = 0; activeIndex < activePanels.length; activeIndex++){
                        activePanels[activeIndex].classList.add('is-active');

                        var parentPanel = activePanels[activeIndex].parentElement;

                        while(parentPanel && parentPanel !== document.body){

                            if(parentPanel.classList && parentPanel.classList.contains('nguk-tab-panel')){

                                parentPanel.classList.add('is-active');

                            }

                            parentPanel = parentPanel.parentElement;

                        }
                    }

                    var buttons = document.querySelectorAll('.nguk-nav-button');
                    for(var buttonIndex = 0; buttonIndex < buttons.length; buttonIndex++){
                        buttons[buttonIndex].classList.remove('is-active');
                    }

                    var activeButton = document.querySelector('.nguk-nav-button[data-nguk-tab="' + panel + '"]');
                    if(activeButton){
                        activeButton.classList.add('is-active');
                    }

                    try {
                        var currentUrl = new URL(window.location.href);
                        currentUrl.searchParams.set('nguk_view', panel);
                        window.history.replaceState({}, '', currentUrl.toString());
                    } catch(error) {}

                    if(window.location.hash){
                        try {
                            var target = document.querySelector(window.location.hash);
                            if(target && target.offsetParent !== null){
                                target.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            }
                        } catch(error) {}
                    }
                };

                window.ngukInitialDashboardPanel = function(){
                    var forcedPanel = '<?php echo esc_js($nguk_force_panel); ?>';
                    var params = null;

                    try {
                        params = new URLSearchParams(window.location.search);
                    } catch(error) {}

                    if(forcedPanel){
                        return forcedPanel;
                    }
                    if(params && params.get('nguk_view')){
                        return params.get('nguk_view');
                    }
                    if(params && (
                        params.has('customer_id') ||
                        params.has('beneficiary_id')
                    )){
                        return 'payments';
                    }
                    if(params && (
                        params.has('transaction_search') ||
                        params.has('transaction_page') ||
                        params.has('transaction_created')
                    )){
                        return 'transactions';
                    }
                    if(params && (
                        params.has('create_customer') ||
                        params.has('view_customer_note') ||
                        params.has('customer_search') ||
                        params.has('customer_page')
                    )){
                        return 'customers';
                    }
                    if(params && params.has('reminder_id')){
                        return 'reminders';
                    }
                    if(params && params.has('monthly_page')){
                        return 'reports';
                    }
                    return 'overview';
                };

                if(document.readyState === 'loading'){
                    document.addEventListener('DOMContentLoaded', function(){
                        setupPanels();
                        window.ngukShowDashboardPanel(window.ngukInitialDashboardPanel());
                        var buttons = document.querySelectorAll('.nguk-nav-button');
                        for(var index = 0; index < buttons.length; index++){
                            buttons[index].addEventListener('click', function(event){
                                event.preventDefault();
                                window.ngukShowDashboardPanel(this.getAttribute('data-nguk-tab'));
                            });
                        }
                    });
                } else {
                    setupPanels();
                    window.ngukShowDashboardPanel(window.ngukInitialDashboardPanel());
                    var buttons = document.querySelectorAll('.nguk-nav-button');
                    for(var index = 0; index < buttons.length; index++){
                        buttons[index].addEventListener('click', function(event){
                            event.preventDefault();
                            window.ngukShowDashboardPanel(this.getAttribute('data-nguk-tab'));
                        });
                    }
                }
            })();
            </script>

            <?php

            $dashboard_buy_rate = get_option('nguk_buy_rate', '2000');

            $dashboard_sell_rate = get_option('nguk_sell_rate', '1900');

            $dashboard_transfer_fee = get_option('nguk_transfer_fee', '0');

            $dashboard_customers_table = $wpdb->prefix . 'nguk_customers';
            $dashboard_transactions_table = $wpdb->prefix . 'nguk_transactions';
            $dashboard_customer_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $dashboard_customers_table"));
            $dashboard_transaction_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $dashboard_transactions_table"));

            ?>

            <style>
                .wrap {
                    max-width:1360px;
                }

                .nguk-app-hero {
                    background:linear-gradient(135deg,#10223f,#176b87);
                    color:#fff;
                    padding:28px;
                    border-radius:18px;
                    display:flex;
                    justify-content:space-between;
                    gap:20px;
                    align-items:center;
                    box-shadow:0 18px 45px rgba(15,35,66,0.22);
                    margin-top:18px;
                }

                .nguk-app-hero h1 {
                    color:#fff;
                    margin:0;
                    font-size:30px;
                    font-weight:800;
                    letter-spacing:0;
                }

                .nguk-app-kicker,
                .nguk-app-subtitle {
                    margin:0;
                    color:#dbeafe;
                }

                .nguk-app-kicker {
                    text-transform:uppercase;
                    font-size:12px;
                    font-weight:700;
                    margin-bottom:7px;
                }

                .nguk-app-subtitle {
                    margin-top:8px;
                    font-size:14px;
                }

                .nguk-hero-action {
                    min-width:130px;
                    text-align:center;
                    background:#22c55e !important;
                    border-color:#22c55e !important;
                    font-weight:700;
                }

                .nguk-ukng-switch-button {
                    min-height:48px;
                    min-width:180px;
                    padding:10px 22px !important;
                    border-radius:12px !important;
                    background:#f59e0b !important;
                    border-color:#f59e0b !important;
                    color:#111827 !important;
                    font-size:15px !important;
                    font-weight:900 !important;
                    text-align:center;
                    box-shadow:0 12px 28px rgba(245,158,11,0.32) !important;
                }

                .nguk-ukng-switch-button:hover {
                    background:#fbbf24 !important;
                    border-color:#fbbf24 !important;
                    color:#111827 !important;
                }

                .nguk-hero-actions {
                    display:flex;
                    gap:10px;
                    align-items:center;
                    flex-wrap:wrap;
                    justify-content:flex-end;
                }

                .nguk-account-button {
                    min-height:38px;
                    border:1px solid rgba(255,255,255,0.35);
                    background:rgba(255,255,255,0.12);
                    color:#fff;
                    border-radius:10px;
                    padding:7px 14px;
                    display:inline-flex;
                    align-items:center;
                    gap:7px;
                    font-weight:800;
                    cursor:pointer;
                }

                .nguk-account-button:hover,
                .nguk-account-button.is-active {
                    background:#fff;
                    color:#0f766e;
                }

                .nguk-account-panel {
                    display:none;
                    background:#fff;
                    border:1px solid #e5e7eb;
                    border-radius:16px;
                    padding:18px;
                    margin:14px 0;
                    box-shadow:0 10px 28px rgba(15,23,42,0.08);
                    align-items:center;
                    gap:10px;
                    flex-wrap:wrap;
                }

                .nguk-account-panel.is-open {
                    display:flex;
                }

                .nguk-account-panel h2 {
                    margin:0 12px 0 0;
                    font-size:18px;
                    min-width:150px;
                }

                .nguk-app-nav {
                    background:#fff;
                    border:1px solid #e5e7eb;
                    border-radius:14px;
                    padding:8px;
                    margin:18px 0;
                    display:flex;
                    flex-wrap:wrap;
                    gap:8px;
                    box-shadow:0 8px 24px rgba(15,23,42,0.06);
                    position:sticky;
                    top:42px;
                    z-index:5;
                }

                .nguk-nav-button {
                    border:0;
                    background:transparent;
                    color:#334155;
                    padding:10px 14px;
                    border-radius:10px;
                    font-weight:700;
                    cursor:pointer;
                    display:inline-block;
                    text-decoration:none;
                }

                .nguk-nav-button.is-active {
                    background:#0f766e;
                    color:#fff;
                    box-shadow:0 8px 18px rgba(15,118,110,0.22);
                }

                .nguk-tab-panel {
                    display:none;
                }

                .nguk-tab-panel.is-active {
                    display:block;
                }

                .nguk-section-card,
                .nguk-tab-panel,
                .wrap > div[style*="background:#fff"],
                body.wp-admin div[style*="background:#fff"][style*="border-radius:12px"] {
                    border-radius:16px !important;
                    border:1px solid #e5e7eb;
                    box-shadow:0 10px 26px rgba(15,23,42,0.06) !important;
                }

                .wrap h2 {
                    font-size:20px;
                    font-weight:800;
                    color:#0f172a;
                    margin-top:0;
                }

                .form-table th {
                    color:#334155;
                    font-weight:700;
                }

                .form-table input,
                .form-table textarea,
                .form-table select,
                .regular-text,
                input[type="search"],
                select {
                    border:1px solid #cbd5e1 !important;
                    border-radius:10px !important;
                    min-height:42px;
                    padding:8px 12px;
                    box-shadow:none !important;
                }

                .button,
                .button-primary {
                    border-radius:10px !important;
                    min-height:38px;
                    padding:4px 14px !important;
                    font-weight:700;
                }

                .widefat {
                    border:1px solid #e5e7eb;
                    border-radius:14px;
                    overflow:hidden;
                    box-shadow:0 4px 16px rgba(15,23,42,0.04);
                }

                .widefat thead th {
                    background:#f8fafc;
                    color:#475569;
                    font-weight:800;
                }

                .widefat tbody tr:nth-child(even) {
                    background:#f8fafc;
                }

                .nguk-name-strong {
                    color:#020617;
                    font-weight:900;
                    text-transform:uppercase;
                }

                .nguk-transaction-table td {
                    color:#020617;
                    font-weight:800;
                }

                .nguk-transaction-table td:nth-child(2),
                .nguk-transaction-table td:nth-child(3),
                .nguk-transaction-table td:nth-child(4),
                .nguk-transaction-table td:nth-child(5) {
                    font-weight:900;
                }

                .nguk-dashboard-dark {
                    background:#0f172a;
                    color:#e5e7eb;
                    padding:20px;
                    border-radius:16px;
                }

                .nguk-dashboard-dark .nguk-app-nav,
                .nguk-dashboard-dark .nguk-account-panel,
                .nguk-dashboard-dark .nguk-tab-panel,
                .nguk-dashboard-dark .nguk-section-card,
                .nguk-dashboard-dark > div[style*="background:#fff"],
                .nguk-dashboard-dark div[style*="background:#fff"] {
                    background:#111827 !important;
                    border-color:#334155 !important;
                    color:#e5e7eb !important;
                }

                .nguk-dashboard-dark h1,
                .nguk-dashboard-dark h2,
                .nguk-dashboard-dark h3,
                .nguk-dashboard-dark p,
                .nguk-dashboard-dark th,
                .nguk-dashboard-dark td,
                .nguk-dashboard-dark label {
                    color:#e5e7eb !important;
                }

                .nguk-dashboard-dark .widefat,
                .nguk-dashboard-dark .widefat td,
                .nguk-dashboard-dark .widefat th {
                    background:#111827 !important;
                    color:#e5e7eb !important;
                    border-color:#334155 !important;
                }

                .nguk-dashboard-dark .nguk-name-strong,
                .nguk-dashboard-dark .nguk-transaction-table td {
                    color:#e5e7eb !important;
                }

                .nguk-dashboard-dark .widefat tbody tr:nth-child(even),
                .nguk-dashboard-dark .widefat thead th {
                    background:#1f2937 !important;
                }

                .nguk-dashboard-dark input,
                .nguk-dashboard-dark select,
                .nguk-dashboard-dark textarea {
                    background:#020617 !important;
                    color:#e5e7eb !important;
                    border-color:#475569 !important;
                }

                @keyframes ngukRatePulse {
                    0%, 100% { box-shadow:0 2px 12px rgba(0,0,0,0.08); transform:translateY(0); }
                    50% { box-shadow:0 8px 24px rgba(34,113,177,0.25); transform:translateY(-2px); }
                }

                .nguk-rate-card {
                    background:#fff;
                    padding:25px;
                    border-radius:12px;
                    border-left:6px solid #2271b1;
                    animation:ngukRatePulse 2.4s ease-in-out infinite;
                }

                .nguk-rate-card h2 {
                    margin:0 0 12px;
                    font-size:22px;
                    font-weight:800;
                }

                .nguk-rate-card strong {
                    display:block;
                    font-size:42px;
                    line-height:1.1;
                    color:#111827;
                }

                .nguk-overview-panel {
                    background:#fff;
                    padding:28px;
                    border-radius:16px;
                    margin-top:24px;
                    border:1px solid #e5e7eb;
                    box-shadow:0 10px 26px rgba(15,23,42,0.06);
                }

                .nguk-overview-panel h2 {
                    margin:0 0 18px;
                    font-size:22px;
                    font-weight:800;
                }

                .nguk-overview-grid {
                    display:grid;
                    grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
                    gap:18px;
                }

                .nguk-overview-card {
                    background:#f8fafc;
                    border:1px solid #dbe4ee;
                    border-radius:12px;
                    padding:20px 22px;
                    min-height:100px;
                }

                .nguk-overview-card span,
                .nguk-overview-card h2 {
                    display:block;
                    margin:0 0 10px;
                    color:#64748b;
                    font-size:13px;
                    font-weight:900;
                    text-transform:uppercase;
                }

                .nguk-overview-card strong {
                    display:block;
                    color:#0f172a;
                    font-size:30px;
                    line-height:1.1;
                    font-weight:900;
                }
            </style>

            <div class="<?php echo esc_attr($nguk_panel_class('overview')); ?>"
                 data-nguk-panel="overview">

                <div class="nguk-overview-panel">
                    <h2>Overview</h2>

                    <div class="nguk-overview-grid">
                        <div class="nguk-overview-card">
                            <span>Buy Rate</span>
                            <strong>&#8358;<?php echo esc_html(number_format(floatval($dashboard_buy_rate), 2)); ?></strong>
                        </div>

                        <div class="nguk-overview-card">
                            <span>Sell Rate</span>
                            <strong>&#8358;<?php echo esc_html(number_format(floatval($dashboard_sell_rate), 2)); ?></strong>
                        </div>

                        <div class="nguk-overview-card">
                            <span>Customers</span>
                            <strong><?php echo esc_html(number_format($dashboard_customer_count)); ?></strong>
                        </div>

                        <div class="nguk-overview-card">
                            <span>Transactions</span>
                            <strong><?php echo esc_html(number_format($dashboard_transaction_count)); ?></strong>
                        </div>

                        <?php NGUK_Reminders::render_overview_widget('nguk-overview-card', 'nguk'); ?>
                    </div>
                </div>

            </div>

            <p class="<?php echo esc_attr($nguk_panel_class('overview')); ?>"
               data-nguk-panel="overview"
               style="margin-top:15px;">
                <a href="?page=nguk-transfer&edit_rates=1#nguk-rate-settings"
                   class="button button-primary">
                   Change Rates
                </a>
            </p>

            <?php if (isset($_GET['edit_rates'])) { ?>

            <div id="nguk-rate-settings"
                 class="<?php echo esc_attr($nguk_panel_class('overview')); ?>"
                 data-nguk-panel="overview"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:20px;">

                <h2>Exchange Rates</h2>

                <form method="post" enctype="multipart/form-data">
                    <table class="form-table">
                        <tr>
                            <th>Buy Rate</th>
                            <td>
                                <input type="number"
                                       name="buy_rate"
                                       class="regular-text"
                                       step="0.01"
                                       value="<?php echo esc_attr($dashboard_buy_rate); ?>"
                                       required>
                            </td>
                        </tr>

                        <tr>
                            <th>Sell Rate</th>
                            <td>
                                <input type="number"
                                       name="sell_rate"
                                       class="regular-text"
                                       step="0.01"
                                       value="<?php echo esc_attr($dashboard_sell_rate); ?>"
                                       required>
                            </td>
                        </tr>

                        <tr>
                            <th>Transfer Fee (NGN)</th>
                            <td>
                                <input type="number"
                                       name="transfer_fee"
                                       class="regular-text"
                                       step="0.01"
                                       value="<?php echo esc_attr($dashboard_transfer_fee); ?>">
                                <p class="description">Used by the public transfer calculator.</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <input type="submit"
                               name="save_exchange_rates"
                               class="button button-primary"
                               value="Save Rates">

                        <a href="?page=nguk-transfer"
                           class="button">
                           Cancel
                        </a>
                    </p>
                </form>

            </div>

            <?php } ?>

            <?php NGUK_Reminders::render_panel($nguk_panel_class('reminders'), 'nguk_view', 'nguk'); ?>

            <div style="display:none;">

                <div style="background:#fff;padding:25px;border-radius:12px;">
                    <h2>Total Transactions</h2>
                    <h1>11</h1>
                </div>

                <div style="background:#fff;padding:25px;border-radius:12px;">
                    <h2>Total Profit</h2>
                    <h1>£84.54</h1>
                </div>

                <div style="background:#fff;padding:25px;border-radius:12px;">
                    <h2>Total Naira</h2>
                    <h1>₦4,409,006.00</h1>
                </div>

                <div style="background:#fff;padding:25px;border-radius:12px;">
                    <h2>Total Pounds</h2>
                    <h1>£2,260.52</h1>
                </div>

            </div>
            <div class="<?php echo esc_attr($nguk_panel_class('settings')); ?>"
                 data-nguk-panel="settings"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

    <h2>Business Details</h2>

    <form method="post">

        <table class="form-table">

            <tr>
                <th>Business Name</th>
                <td>
                    <input type="text"
                           name="business_name"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('nguk_business_name')); ?>">
                </td>
            </tr>

            <tr>
                <th>Phone Number</th>
                <td>
                    <input type="text"
                           name="business_phone"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('nguk_business_phone')); ?>">
                </td>
            </tr>

            <tr>
                <th>Email Address</th>
                <td>
                    <input type="email"
                           name="business_email"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('nguk_business_email')); ?>">
                </td>
            </tr>

            <tr>
                <th>Business Address</th>
                <td>
                    <textarea name="business_address"
                              class="large-text"><?php echo esc_textarea(get_option('nguk_business_address')); ?></textarea>
                </td>
            </tr>

            <tr>
                <th>Website</th>
                <td>
                    <input type="text"
                           name="business_website"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('nguk_business_website')); ?>">
              <td>

    <input type="text"
           name="business_logo"
           id="business_logo"
           class="regular-text"
           value="<?php echo esc_attr(get_option('nguk_business_logo')); ?>">

    <button type="button"
            class="button"
            id="upload_logo_button">

        Upload Logo

    </button>

    <p>
        Upload business logo using WordPress Media Library.
    </p>

</td>
            </tr>

        </table>

        <p>

            <input type="submit"
                   name="save_business_settings"
                   class="button button-primary"
                   value="Save Business Details">

        </p>

    </form>

    <hr style="margin:24px 0;">

    <h2>Dashboard Theme</h2>

    <form method="post">
        <table class="form-table">
            <tr>
                <th>Theme Mode</th>
                <td>
                    <select name="dashboard_theme">
                        <option value="normal" <?php selected(get_option('nguk_dashboard_theme', 'normal'), 'normal'); ?>>Normal Mode</option>
                        <option value="dark" <?php selected(get_option('nguk_dashboard_theme', 'normal'), 'dark'); ?>>Dark Mode</option>
                    </select>
                </td>
            </tr>
        </table>

        <p>
            <input type="submit"
                   name="save_dashboard_theme"
                   class="button button-primary"
                   value="Save Theme">
        </p>
    </form>

</div>

<?php if (isset($_GET['create_customer'])) { ?>

            <div id="nguk-create-customer"
                 class="<?php echo esc_attr($nguk_panel_class('customers')); ?>"
                 data-nguk-panel="customers"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

                <h2>Create Customer</h2>

                <form method="post" enctype="multipart/form-data">

                    <table class="form-table">

                        <tr>
                            <th>Customer Name</th>
                            <td>
                                <input type="text"
                                       name="customer_name"
                                       class="regular-text"
                                       required>
                            </td>
                        </tr>

                        <tr>
                            <th>Phone Number</th>
                            <td>
                                <input type="text"
                                       name="phone_number"
                                       class="regular-text"
                                       required>
                            </td>
                        </tr>

                        <tr>
                            <th>Address</th>
                            <td>
                                <textarea name="address"
                                          class="large-text"></textarea>
                            </td>
                        </tr>

                        <tr>
                            <th>UK Bank Details</th>
                            <td>
                                <textarea name="uk_bank_details"
                                          class="large-text"
                                          rows="4"
                                          required></textarea>
                            </td>
                        </tr>

                        <tr>
                            <th>KYC Documents</th>
                            <td>
                                <input type="file"
                                       name="kyc_documents[]"
                                       multiple>
                                <p class="description">Upload one or more KYC documents.</p>
                            </td>
                        </tr>

                    </table>

                    <p>
                        <input type="submit"
                               name="save_customer"
                               class="button button-primary"
                               value="Save Customer">

                        <a href="?page=nguk-transfer"
                           class="button">
                           Cancel
                        </a>
                    </p>

                </form>

            </div>

<?php } ?>

            <div class="<?php echo esc_attr($nguk_panel_class('customers')); ?>"
                 data-nguk-panel="customers"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

                <h2>Registered Customers</h2>

                <?php

                $customers_table = $wpdb->prefix . 'nguk_customers';

                if (isset($_GET['view_customer_note'])) {

                    $note_customer_id = intval($_GET['view_customer_note']);

                    $note_customer = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT customer_name, notes FROM $customers_table WHERE id = %d",
                            $note_customer_id
                        )
                    );

                    if ($note_customer) {

                        ?>

                        <div id="customer-note-view"
                             style="background:#f6f7f7;border-left:4px solid #2271b1;padding:15px;margin:15px 0;">

                            <h3 style="margin-top:0;">
                                Note / Risk Assessment for <?php echo esc_html(strtoupper($note_customer->customer_name)); ?>
                            </h3>

                            <p style="white-space:pre-wrap;margin-bottom:0;">
                                <?php echo !empty($note_customer->notes) ? esc_html($note_customer->notes) : 'No note / risk assessment has been saved yet.'; ?>
                            </p>

                        </div>

                        <?php

                    }

                }

                ?>

                <p>
                    <a href="?page=nguk-transfer&create_customer=1#nguk-create-customer"
                       class="button button-primary">
                       Create Customer
                    </a>
                </p>

                <form method="get"
                      style="margin:15px 0 20px;">

                    <input type="hidden"
                           name="page"
                           value="nguk-transfer">

                    <input type="hidden"
                           name="nguk_view"
                           value="transactions">

                    <input type="search"
                           name="customer_search"
                           class="regular-text"
                           placeholder="Search customer by name or phone"
                           value="<?php echo esc_attr(isset($_GET['customer_search']) ? sanitize_text_field($_GET['customer_search']) : ''); ?>">

                    <input type="submit"
                           class="button"
                           value="Search">

                    <a href="?page=nguk-transfer&nguk_view=transactions"
                       class="button">
                       Clear
                    </a>

                </form>

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Customer Name</th>
                            <th>Phone</th>
                            <th>KYC Documents</th>
                            <th>Note / Risk Assessment</th>
                            <th>View Note</th>
                            <th>Profile</th>
                            <th>Delete</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php

                    $customers_table = $wpdb->prefix . 'nguk_customers';

                    $customers_per_page = 15;

                    $customer_page = isset($_GET['customer_page'])
                        ? max(1, intval($_GET['customer_page']))
                        : 1;

                    $customer_search = isset($_GET['customer_search'])
                        ? sanitize_text_field($_GET['customer_search'])
                        : '';

                    if (!empty($customer_search)) {

                        $customer_like = '%' . $wpdb->esc_like($customer_search) . '%';

                        $all_customers = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT * FROM $customers_table
                                 WHERE customer_name LIKE %s
                                    OR phone_number LIKE %s
                                 ORDER BY id DESC",
                                $customer_like,
                                $customer_like
                            )
                        );

                    } else {

                        $all_customers = $wpdb->get_results(
                            "SELECT * FROM $customers_table ORDER BY id DESC"
                        );

                    }

                    $customer_total_pages = max(
                        1,
                        ceil(count($all_customers) / $customers_per_page)
                    );

                    $customer_page = min($customer_page, $customer_total_pages);

                    $customers = array_slice(
                        $all_customers,
                        ($customer_page - 1) * $customers_per_page,
                        $customers_per_page
                    );

                    if ($customers) {

                        $count = (($customer_page - 1) * $customers_per_page) + 1;

                        foreach ($customers as $customer) {

                            ?>

                            <tr>

                                <td><?php echo $count++; ?></td>

                                <td>
                                    <?php echo esc_html(strtoupper($customer->customer_name)); ?>
                                </td>

                                <td>
                                    <?php echo esc_html($customer->phone_number); ?>
                                </td>

                                <td>
                                    <?php self::render_kyc_documents(isset($customer->kyc_documents) ? $customer->kyc_documents : ''); ?>
                                </td>

                                <td>
                                    <form method="post"
                                          style="margin:0;">

                                        <input type="hidden"
                                               name="customer_id"
                                               value="<?php echo intval($customer->id); ?>">

                                        <textarea name="customer_notes"
                                                  rows="3"
                                                  style="width:100%;min-width:260px;"><?php echo esc_textarea($customer->notes); ?></textarea>

                                        <p style="margin:6px 0 0;">
                                            <input type="submit"
                                                   name="update_customer_notes"
                                                   class="button"
                                                   value="Save Note">
                                        </p>

                                    </form>
                                </td>

                                <td>
                                    <a href="?page=nguk-transfer&view_customer_note=<?php echo intval($customer->id); ?>#customer-note-view"
                                       class="button">
                                       View
                                    </a>
                                </td>

                                <td>

                                    <a href="?page=nguk-transfer&view_customer=<?php echo $customer->id; ?>"
                                       class="button button-primary">

                                       View Profile

                                    </a>

                                </td>

                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=nguk-transfer&nguk_view=customers&delete_customer=' . intval($customer->id)), 'nguk_delete_customer_' . intval($customer->id))); ?>"
                                       class="button"
                                       title="Delete customer"
                                       onclick="return confirm('Delete this customer and their beneficiaries? Existing transaction history will remain.');"
                                       style="background:#dc2626;border-color:#dc2626;color:#fff;">
                                        <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
                                    </a>
                                </td>

                            </tr>

                            <?php
                        }
                    }

                    ?>

                    </tbody>

                </table>

                <?php if ($customer_total_pages > 1) { ?>

                    <p style="margin-top:15px;">

                        <?php for ($page_number = 1; $page_number <= $customer_total_pages; $page_number++) { ?>

                            <a class="button <?php echo $page_number == $customer_page ? 'button-primary' : ''; ?>"
                               href="?page=nguk-transfer&customer_page=<?php echo $page_number; ?><?php echo !empty($customer_search) ? '&customer_search=' . urlencode($customer_search) : ''; ?>">
                               <?php echo $page_number; ?>
                            </a>

                        <?php } ?>

                    </p>

                <?php } ?>

            </div>
            <div class="<?php echo esc_attr($nguk_panel_class('payments')); ?>"
                 data-nguk-panel="payments"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">
<div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

<h2>Register Bank Account</h2>

<form method="post">

<table class="form-table">

<tr>
    <th>Account Type</th>

    <td>
        <select name="account_type" disabled>

            <option value="Nigeria">
                Nigeria Bank
            </option>

        </select>
        <input type="hidden"
               name="account_type"
               value="Nigeria">
    </td>
</tr>

<tr>
    <th>Bank Name</th>

    <td>
        <input type="text"
               name="bank_name"
               class="regular-text">
    </td>
</tr>

<tr>
    <th>Account Name</th>

    <td>
        <input type="text"
               name="account_name"
               class="regular-text">
    </td>
</tr>

<tr>
    <th>Account Number</th>

    <td>
        <input type="text"
               name="account_number"
               class="regular-text">
    </td>
</tr>

<tr>
    <th>Sort Code / Extra Details</th>

    <td>
        <input type="text"
               name="extra_details"
               class="regular-text">
    </td>
</tr>

</table>

<p>

<input type="submit"
       name="save_bank_account"
       class="button button-primary"
       value="Save Bank Account">

</p>

</form>

<hr style="margin:30px 0;">

<h2>Registered Nigeria Bank Accounts</h2>

<?php

$bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

$registered_nigeria_accounts = $wpdb->get_results(

    "SELECT * FROM $bank_accounts_table
     WHERE account_type LIKE '%Nigeria%'
     ORDER BY bank_name ASC"

);

if ($registered_nigeria_accounts) {

?>

<table class="widefat striped">

    <thead>

        <tr>
            <th>Bank Name</th>
            <th>Account Name</th>
            <th>Account Number</th>
            <th>Sort Code / Extra Details</th>
            <th>Action</th>
        </tr>

    </thead>

    <tbody>

        <?php foreach ($registered_nigeria_accounts as $account) { ?>

            <tr>
                <td><?php echo esc_html($account->bank_name); ?></td>
                <td><?php echo esc_html($account->account_name); ?></td>
                <td><?php echo esc_html($account->account_number); ?></td>
                <td><?php echo esc_html($account->extra_details); ?></td>
                <td>
                    <a href="?page=nguk-transfer&delete_bank_account=<?php echo intval($account->id); ?>"
                       class="button"
                       onclick="return confirm('Are you sure you want to delete this bank account?');"
                       style="background:red;color:white;border-color:red;">
                       Delete
                    </a>
                </td>
            </tr>

        <?php } ?>

    </tbody>

</table>

<?php

} else {

    echo '<p>No Nigeria bank accounts registered yet.</p>';

}

?>

</div>

</div>

<div id="nguk-create-transaction"
     class="<?php echo esc_attr($nguk_panel_class('payments')); ?>"
     data-nguk-panel="payments"
     style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

    <h2>Create Transaction</h2>

    <form method="post">

        <table class="form-table">
 <tr>

<th>Select Nigeria Bank</th>

<td>

<select name="nigeria_bank_details" style="width:100%;">

<option value="">
Select Nigeria Bank
</option>

<?php

$bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

$nigeria_accounts = $wpdb->get_results(

    "SELECT * FROM $bank_accounts_table
     WHERE account_type LIKE '%Nigeria%'
     ORDER BY bank_name ASC"

);

if ($nigeria_accounts) {

    foreach ($nigeria_accounts as $account) {

        $details =
            $account->bank_name . "\n" .
            $account->account_name . "\n" .
            $account->account_number . "\n" .
            $account->extra_details;

        ?>

        <option value="<?php echo esc_attr($details); ?>">

            <?php echo esc_html($account->bank_name . ' - ' . $account->account_name); ?>

        </option>

        <?php
    }
}

?>

</select>

</td>

</tr>

            <tr>

                <th>Select Customer</th>

                <td>

                   <select id="customer_select"
        name="customer_id"
        required>
<option value="">
    Select Customer
</option>

<?php

$selected_customer_id = isset($_GET['customer_id'])
    ? intval($_GET['customer_id'])
    : '';

$customers_table = $wpdb->prefix . 'nguk_customers';

$customers = $wpdb->get_results(
    "SELECT * FROM $customers_table ORDER BY customer_name ASC"
);

if ($customers) {

    foreach ($customers as $customer) {
?>

                               <option value="<?php echo $customer->id; ?>"
<?php selected($selected_customer_id, $customer->id); ?>>

                                    <?php echo esc_html(strtoupper($customer->customer_name)); ?>

                                </option>

                                <?php
                            }
                        }

                        ?>

                    </select>

                </td>

            </tr>
            <tr>

<th>Select Beneficiary</th>

<td>

<select id="beneficiary_select"
        name="beneficiary_id"
        style="width:100%;"
        required>

<option value="">
Select Beneficiary
</option>

<?php

$beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

$all_beneficiaries = $wpdb->get_results(

    "SELECT * FROM $beneficiaries_table
     ORDER BY beneficiary_name ASC"

);

if ($all_beneficiaries) {

    $selected_beneficiary_id = isset($_GET['beneficiary_id'])
        ? intval($_GET['beneficiary_id'])
        : '';

    foreach ($all_beneficiaries as $beneficiary) {

        ?>

       <option

value="<?php echo $beneficiary->id; ?>"
data-customer-id="<?php echo $beneficiary->customer_id; ?>"

data-bank="<?php echo esc_attr($beneficiary->bank_name); ?>"

data-account-number="<?php echo esc_attr($beneficiary->account_number); ?>"

data-sort-code="<?php echo esc_attr($beneficiary->sort_code); ?>"

<?php selected($selected_beneficiary_id, $beneficiary->id); ?>

>

            <?php
            echo esc_html(
                strtoupper($beneficiary->beneficiary_name) .
                ' - ' .
                $beneficiary->bank_name
            );
            ?>

        </option>

        <?php
    }
}

?>

</select>
<input type="hidden"
       id="uk_bank_details"
       name="uk_bank_details">

</td>

</tr>

            <tr>

                <th>Naira Paid</th>

                <td>

                    <input type="number"
                    id="naira_amount"
                           name="naira_amount"
                           class="regular-text">

                </td>

            </tr>

            <tr>

                <th>Buy Rate</th>

                <td>
<input type="number"
       id="buy_rate"
       name="buy_rate"
       class="regular-text"
       value="<?php echo esc_attr(get_option('nguk_buy_rate', '2000')); ?>"
       readonly>

                </td>

            </tr>

            <tr>

                <th>Sell Rate</th>

                <td>

                    <input type="number"
                    id="sell_rate"
                           name="sell_rate"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('nguk_sell_rate', '1900')); ?>"
                           readonly>

                </td>

            </tr>
            <tr>

    <th>Pounds Amount</th>

    <td>

        <input type="number"
               id="pounds_amount"
               name="pounds_amount"
               class="regular-text"
               readonly>

    </td>

</tr>

        </table>

        <p>

            <input type="submit"
                   name="save_transaction"
                   class="button button-primary"
                   value="Create Transaction">

        </p>

    </form>

</div>

            <div class="<?php echo esc_attr($nguk_panel_class('transactions')); ?>"
                 data-nguk-panel="transactions"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

                <h2>Recent Transactions</h2>

                <form method="get"
                      style="margin:15px 0 20px;">

                    <input type="hidden"
                           name="page"
                           value="nguk-transfer">

                    <input type="search"
                           name="transaction_search"
                           class="regular-text"
                           placeholder="Search by sender, beneficiary, or phone"
                           value="<?php echo esc_attr(isset($_GET['transaction_search']) ? sanitize_text_field($_GET['transaction_search']) : ''); ?>">

                    <label>
                        From Date
                        <input type="date"
                               name="transaction_from_date"
                               value="<?php echo esc_attr(isset($_GET['transaction_from_date']) ? sanitize_text_field($_GET['transaction_from_date']) : ''); ?>">
                    </label>

                    <label>
                        To Date
                        <input type="date"
                               name="transaction_to_date"
                               value="<?php echo esc_attr(isset($_GET['transaction_to_date']) ? sanitize_text_field($_GET['transaction_to_date']) : ''); ?>">
                    </label>

                    <input type="submit"
                           class="button"
                           value="Search">

                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'nguk-transfer', 'nguk_view' => 'transactions', 'transaction_quick_date' => 'today', 'transaction_search' => isset($_GET['transaction_search']) ? sanitize_text_field($_GET['transaction_search']) : ''), admin_url('admin.php'))); ?>"
                       class="button">
                       Today's Transactions
                    </a>

                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'nguk-transfer', 'nguk_view' => 'transactions', 'transaction_quick_date' => 'yesterday', 'transaction_search' => isset($_GET['transaction_search']) ? sanitize_text_field($_GET['transaction_search']) : ''), admin_url('admin.php'))); ?>"
                       class="button">
                       Yesterday's Transactions
                    </a>

                    <a href="?page=nguk-transfer"
                       class="button">
                       Clear
                    </a>

                </form>

                <?php

                $transactions_table = $wpdb->prefix . 'nguk_transactions';

                $nguk_transaction_count = $wpdb->get_var(
                    "SELECT COUNT(*) FROM $transactions_table"
                );

                ?>

                <p>
                    <strong>Total saved transactions:</strong>
                    <?php echo esc_html(number_format(intval($nguk_transaction_count))); ?>
                </p>

                <table class="widefat striped nguk-transaction-table">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Customer</th>
                            <th>Naira Paid</th>
                            <th>Pounds</th>
                            <th>Profit/Loss</th>
                            <th>Status</th>
                            <th>Change Status</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>

                    <tbody>

                       <?php

$transactions_per_page = 15;

$transaction_page = isset($_GET['transaction_page'])
    ? max(1, intval($_GET['transaction_page']))
    : 1;

$transaction_search = isset($_GET['transaction_search'])
    ? sanitize_text_field($_GET['transaction_search'])
    : '';

$transaction_from_date = isset($_GET['transaction_from_date'])
    ? sanitize_text_field($_GET['transaction_from_date'])
    : '';

$transaction_to_date = isset($_GET['transaction_to_date'])
    ? sanitize_text_field($_GET['transaction_to_date'])
    : '';

$transaction_quick_date = isset($_GET['transaction_quick_date'])
    ? sanitize_key($_GET['transaction_quick_date'])
    : '';

if ($transaction_quick_date === 'today') {
    $transaction_from_date = current_time('Y-m-d');
    $transaction_to_date = current_time('Y-m-d');
} elseif ($transaction_quick_date === 'yesterday') {
    $transaction_from_date = date('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
    $transaction_to_date = $transaction_from_date;
}

$transaction_has_date_filter = preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_from_date)
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_to_date);

if (!empty($transaction_search) || $transaction_has_date_filter) {

    $transaction_like = '%' . $wpdb->esc_like($transaction_search) . '%';

    $customers_table = $wpdb->prefix . 'nguk_customers';

    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

    $transaction_id_search = preg_replace('/\D/', '', $transaction_search);
    $transaction_id = $transaction_id_search !== '' ? intval($transaction_id_search) : 0;
    $transaction_where = array('1=1');
    $transaction_params = array();

    if (!empty($transaction_search)) {
        $transaction_where[] = "(t.customer_name LIKE %s
            OR t.beneficiary_name LIKE %s
            OR c.phone_number LIKE %s
            OR t.tracking_code LIKE %s
            OR t.id = %d
            OR EXISTS (
                SELECT 1
                FROM $beneficiaries_table b
                WHERE b.customer_id = c.id
                  AND b.beneficiary_name LIKE %s
            ))";
        $transaction_params[] = $transaction_like;
        $transaction_params[] = $transaction_like;
        $transaction_params[] = $transaction_like;
        $transaction_params[] = $transaction_like;
        $transaction_params[] = $transaction_id;
        $transaction_params[] = $transaction_like;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_from_date)) {
        $transaction_where[] = 't.created_at >= %s';
        $transaction_params[] = $transaction_from_date . ' 00:00:00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_to_date)) {
        $transaction_where[] = 't.created_at <= %s';
        $transaction_params[] = $transaction_to_date . ' 23:59:59';
    }

    $transaction_sql = "SELECT DISTINCT t.*
         FROM $transactions_table t
         LEFT JOIN $customers_table c
            ON c.customer_name = t.customer_name
         WHERE " . implode(' AND ', $transaction_where) . "
         ORDER BY t.id DESC";

    $all_transactions = $transaction_params
        ? $wpdb->get_results($wpdb->prepare($transaction_sql, $transaction_params))
        : $wpdb->get_results($transaction_sql);

} else {

    $all_transactions = $wpdb->get_results(
        "SELECT * FROM $transactions_table ORDER BY id DESC"
    );

}

$transaction_query_error = $wpdb->last_error;

if (!is_array($all_transactions)) {

    $all_transactions = array();

}

$transaction_total_pages = max(
    1,
    ceil(count($all_transactions) / $transactions_per_page)
);

$transaction_page = min($transaction_page, $transaction_total_pages);

$transactions = array_slice(
    $all_transactions,
    ($transaction_page - 1) * $transactions_per_page,
    $transactions_per_page
);

?>

<?php if (!empty($transaction_query_error)) { ?>

    <tr>
        <td colspan="8">
            <strong>Transaction table error:</strong>
            <?php echo esc_html($transaction_query_error); ?>
        </td>
    </tr>

<?php } ?>

<?php

if ($transactions) {

    $count = (($transaction_page - 1) * $transactions_per_page) + 1;

    foreach ($transactions as $transaction) {

        ?>

        <tr>

            <td><?php echo $count++; ?></td>

            <td>
                <span class="nguk-name-strong"><?php echo esc_html(strtoupper($transaction->customer_name)); ?></span>
            </td>

            <td>
                ₦<?php echo number_format($transaction->naira_amount, 2); ?>
            </td>

            <td>
                £<?php echo floatval($transaction->buy_rate) > 0 ? number_format($transaction->naira_amount / $transaction->buy_rate, 2) : '0.00'; ?> 
            </td>

            <?php
            $transaction_profit = (floatval($transaction->sell_rate) > 0 && floatval($transaction->buy_rate) > 0)
                ? (floatval($transaction->naira_amount) / floatval($transaction->sell_rate)) - (floatval($transaction->naira_amount) / floatval($transaction->buy_rate))
                : floatval($transaction->profit);
            $transaction_profit_label = $transaction_profit < 0 ? 'Loss' : 'Profit';
            $transaction_profit_color = $transaction_profit < 0 ? '#dc2626' : '#15803d';
            $transaction_profit_display = $transaction_profit < 0 ? ($transaction_profit * -1) : $transaction_profit;
            ?>
            <td style="color:<?php echo esc_attr($transaction_profit_color); ?>;font-weight:900;">

                <?php echo esc_html($transaction_profit_label); ?> GBP <?php echo number_format($transaction_profit_display, 2); ?>

            </td>

            <td>
                <strong><?php echo esc_html($transaction->status); ?></strong>
            </td>

            <td>
                <form method="post"
                      style="display:flex;gap:6px;align-items:center;margin:0;">

                    <input type="hidden"
                           name="transaction_id"
                           value="<?php echo intval($transaction->id); ?>">

                    <select name="transaction_status">
                        <option value="Pending" <?php selected($transaction->status, 'Pending'); ?>>
                            Pending
                        </option>
                        <option value="Payment Received" <?php selected($transaction->status, 'Payment Received'); ?>>
                            Payment Received
                        </option>
                        <option value="Processing" <?php selected($transaction->status, 'Processing'); ?>>
                            Processing
                        </option>
                        <option value="Paid Out" <?php selected($transaction->status, 'Paid Out'); ?>>
                            Paid Out
                        </option>
                        <option value="Paid" <?php selected($transaction->status, 'Paid'); ?>>
                            Paid
                        </option>
                        <option value="Returned" <?php selected($transaction->status, 'Returned'); ?>>
                            Returned
                        </option>
                        <option value="Cancelled" <?php selected($transaction->status, 'Cancelled'); ?>>
                            Cancelled
                        </option>
                    </select>

                    <input type="submit"
                           name="update_transaction_status"
                           class="button"
                           value="Update">

                </form>
            </td>

            <td>

                <a href="?page=nguk-transfer&view_receipt=<?php echo $transaction->id; ?>"
                   class="button button-primary">

                   View Receipt

                </a>
                <a href="?page=nguk-transfer&delete_transaction=<?php echo $transaction->id; ?>"
   class="button"
   onclick="return confirm('Are you sure you want to delete this transaction?');"
   style="background:red;color:white;border-color:red;margin-left:5px;">

   Delete

</a>

            </td>

        </tr>

        <?php
    }
} else {

    ?>

    <tr>
        <td colspan="8">
            No transactions found yet.
        </td>
    </tr>

    <?php
}

?>

                    </tbody>

                </table>

                <?php if ($transaction_total_pages > 1) { ?>

                    <p style="margin-top:15px;">

                        <?php for ($page_number = 1; $page_number <= $transaction_total_pages; $page_number++) { ?>

                            <?php
                            $transaction_page_url = add_query_arg(
                                array_filter(
                                    array(
                                        'page' => 'nguk-transfer',
                                        'nguk_view' => 'transactions',
                                        'transaction_page' => $page_number,
                                        'transaction_search' => $transaction_search,
                                        'transaction_from_date' => $transaction_from_date,
                                        'transaction_to_date' => $transaction_to_date,
                                        'transaction_quick_date' => $transaction_quick_date
                                    )
                                ),
                                admin_url('admin.php')
                            );
                            ?>
                            <a class="button <?php echo $page_number == $transaction_page ? 'button-primary' : ''; ?>"
                               href="<?php echo esc_url($transaction_page_url); ?>">
                               <?php echo $page_number; ?>
                            </a>

                        <?php } ?>

                    </p>

                <?php } ?>

            </div>

        </div>
        <?php

$transactions_table = $wpdb->prefix . 'nguk_transactions';

$monthly_turnovers = $wpdb->get_results(

    "SELECT 

        DATE_FORMAT(created_at, '%M %Y') as month_year,

        COUNT(*) as total_transactions,

        SUM(naira_amount) as total_naira,

        SUM(pounds_amount) as total_pounds,

        SUM(
            CASE
                WHEN sell_rate > 0 AND buy_rate > 0
                THEN (naira_amount / sell_rate) - (naira_amount / buy_rate)
                ELSE profit
            END
        ) as total_profit

    FROM $transactions_table

    GROUP BY YEAR(created_at), MONTH(created_at)

    ORDER BY created_at DESC"

);

$monthly_per_page = 15;

$monthly_page = isset($_GET['monthly_page'])
    ? max(1, intval($_GET['monthly_page']))
    : 1;

$monthly_total_pages = max(
    1,
    ceil(count($monthly_turnovers) / $monthly_per_page)
);

$monthly_page = min($monthly_page, $monthly_total_pages);

$monthly_turnovers_page = array_slice(
    $monthly_turnovers,
    ($monthly_page - 1) * $monthly_per_page,
    $monthly_per_page
);

?>

<div class="<?php echo esc_attr($nguk_panel_class('reports')); ?>"
     data-nguk-panel="reports"
     style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

    <h2>Monthly Turnovers</h2>

    <table class="widefat striped"

style="
margin-top:20px;
border:1px solid #dbe4ee;
border-radius:14px;
overflow:hidden;
font-size:15px;
font-weight:700;
box-shadow:0 14px 32px rgba(15,23,42,0.09);
">

        <thead style="background:#12372a;color:#fff;">

            <tr>

                <th style="color:#fff;padding:14px 12px;">No.</th>

                <th style="color:#fff;padding:14px 12px;">Month</th>

                <th style="color:#fff;padding:14px 12px;">Total Transactions</th>

                <th style="color:#fff;padding:14px 12px;">Total Naira</th>

                <th style="color:#fff;padding:14px 12px;">Total Pounds</th>

                <th style="color:#fff;padding:14px 12px;">Total Profit/Loss</th>

            </tr>

        </thead>

        <tbody>

            <?php

            if ($monthly_turnovers_page) {

                $monthly_count = (($monthly_page - 1) * $monthly_per_page) + 1;

                foreach ($monthly_turnovers_page as $turnover) {

                    ?>

                 <tr style="height:60px;font-weight:700;">

                        <td style="font-weight:900;color:#12372a;">
                            <?php echo $monthly_count++; ?>
                        </td>

                        <td style="font-weight:900;color:#111827;">
                            <?php echo esc_html($turnover->month_year); ?>
                        </td>

                        <td>
                            <?php echo number_format($turnover->total_transactions); ?>
                        </td>

                        <td>
                            ₦<?php echo number_format($turnover->total_naira, 2); ?>
                        </td>

                        <td>
                            £<?php echo number_format($turnover->total_pounds, 2); ?>
                        </td>

                        <?php
                        $monthly_profit = floatval($turnover->total_profit);
                        $monthly_profit_label = $monthly_profit < 0 ? 'Loss' : 'Profit';
                        $monthly_profit_color = $monthly_profit < 0 ? '#dc2626' : '#15803d';
                        $monthly_profit_display = $monthly_profit < 0 ? ($monthly_profit * -1) : $monthly_profit;
                        ?>
                        <td style="color:<?php echo esc_attr($monthly_profit_color); ?>;font-weight:900;">
                            <?php echo esc_html($monthly_profit_label); ?> GBP <?php echo number_format($monthly_profit_display, 2); ?>
                        </td>

                    </tr>

                    <?php
                }

            } else {

                ?>

                <tr>

                    <td colspan="6">

                        No monthly turnover data found.

                    </td>

                </tr>

                <?php
            }

            ?>

        </tbody>

    </table>

    <?php if ($monthly_total_pages > 1) { ?>

        <p style="margin-top:15px;">

            <?php for ($page_number = 1; $page_number <= $monthly_total_pages; $page_number++) { ?>

                <a class="button <?php echo $page_number == $monthly_page ? 'button-primary' : ''; ?>"
                   href="?page=nguk-transfer&monthly_page=<?php echo $page_number; ?>">
                   <?php echo $page_number; ?>
                </a>

            <?php } ?>

        </p>

    <?php } ?>

</div>
        <script>

jQuery(document).ready(function($){

    $('#ngukAccountButton').on('click', function(){

        $('#ngukAccountPanel').toggleClass('is-open');

        $(this).toggleClass('is-active');

        $(this).attr(
            'aria-expanded',
            $('#ngukAccountPanel').hasClass('is-open') ? 'true' : 'false'
        );

    });

    $('#upload_logo_button').click(function(e){

        e.preventDefault();

        var image = wp.media({

            title: 'Upload Business Logo',

            multiple: false

        }).open()

        .on('select', function(){

            var uploaded_image = image.state().get('selection').first();

            var image_url = uploaded_image.toJSON().url;

            $('#business_logo').val(image_url);

        });

    });
    var beneficiaryOptions =
        $('#beneficiary_select option').clone();

    function buildBeneficiaryDetails(option){

        var bank =
            option.data('bank');

        if(!bank){

            return '';

        }

        return [
            bank,
            option.data('account-number'),
            option.data('sort-code')
        ].filter(Boolean).join("\n");

    }

    function populateBeneficiaries(customerId, selectedBeneficiaryId){

        var beneficiarySelect =
            $('#beneficiary_select');

        beneficiarySelect.empty();

        beneficiaryOptions.each(function(){

            var option =
                $(this).clone();

            if(
                option.val() == '' ||
                String(option.data('customer-id')) === String(customerId)
            ){

                beneficiarySelect.append(option);

            }

        });

        beneficiarySelect.val(selectedBeneficiaryId || '');

        if(!beneficiarySelect.val()){

            $('#uk_bank_details').val('');

        } else {

            $('#uk_bank_details').val(
                buildBeneficiaryDetails(
                    beneficiarySelect.find(':selected')
                )
            );

        }

    }

    $('#customer_select').change(function(){

        populateBeneficiaries($(this).val(), '');

    });

$('#beneficiary_select').change(function(){

    var selected =
        $(this).find(':selected');

    $('#uk_bank_details').val(
        buildBeneficiaryDetails(selected)
    );

});

populateBeneficiaries(
    $('#customer_select').val(),
    $('#beneficiary_select').val()
);

function calculatePounds(){

    var naira =
        parseFloat($('#naira_amount').val()) || 0;

    var buyRate =
        parseFloat($('#buy_rate').val()) || 0;

    if(naira > 0 && buyRate > 0){

        var pounds =
            naira / buyRate;

        $('#pounds_amount').val(
            pounds.toFixed(2)
        );

    }

}

$('#naira_amount').on('keyup change', function(){
    calculatePounds();
});

$('#buy_rate').on('keyup change', function(){
    calculatePounds();
});

});

</script>

        <?php
    }
}



