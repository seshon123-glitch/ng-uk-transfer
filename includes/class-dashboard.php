<?php
if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Dashboard {

    public function dashboard_page() {

        global $wpdb;

        $transactions_table = $wpdb->prefix . 'nguk_transactions';

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
    max-width:800px;
    background:#fff;
    padding:40px;
}

</style>
                <div class="wrap">
                    <?php

$business_logo = get_option('nguk_business_logo');

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
                        $receipt_beneficiary->account_name,
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
            <?php echo esc_html($transaction->customer_name); ?>
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
                <?php echo esc_html('INV' . (6700 + intval($transaction->id))); ?>
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

                    <p style="margin-top:20px;">

                        <button onclick="window.print()"
                                class="button button-primary">

                            Print Receipt

                        </button>

                        <a href="?page=nguk-transfer"
                           class="button">

                           Back

                        </a>

                    </p>

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

            'beneficiary_name' => sanitize_text_field($_POST['beneficiary_name']),

            'bank_name' => sanitize_text_field($_POST['bank_name']),

            'account_name' => sanitize_text_field($_POST['account_name']),

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

    $wpdb->insert(

        $customers_table,

        array(

            'customer_name' => sanitize_text_field($_POST['customer_name']),

            'phone_number' => sanitize_text_field($_POST['phone_number']),

            'address' => sanitize_textarea_field($_POST['address']),

            'notes' => sanitize_textarea_field($_POST['notes']),

            'nigeria_bank_details' => '',

            'uk_bank_details' => sanitize_textarea_field($_POST['uk_bank_details'])

        )

    );

    echo '<div class="updated"><p>Customer created successfully.</p></div>';

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

$business_logo = get_option('nguk_business_logo');

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
                            <td><?php echo esc_html($customer->customer_name); ?></td>
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

                    </table>
<hr style="margin:40px 0;">

<h2>Add Beneficiary</h2>

<form method="post">

<input type="hidden"
       name="customer_id"
       value="<?php echo $customer->id; ?>">

<table class="form-table">

<tr>
    <th>Beneficiary Name</th>

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
        <input type="text"
               name="bank_name"
               class="regular-text"
               required>
    </td>
</tr>

<tr>
    <th>Account Name</th>

    <td>
        <input type="text"
               name="account_name"
               class="regular-text"
               required>
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

<th>Name</th>

<th>Bank</th>

<th>Account Name</th>

<th>Account Number</th>

<th>Sort Code</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php foreach ($beneficiaries as $beneficiary) { ?>

<tr>

<td>
<?php echo esc_html($beneficiary->beneficiary_name); ?>
</td>

<td>
<?php echo esc_html($beneficiary->bank_name); ?>
</td>

<td>
<?php echo esc_html($beneficiary->account_name); ?>
</td>

<td>
<?php echo esc_html($beneficiary->account_number); ?>
</td>

<td>
<?php echo esc_html($beneficiary->sort_code); ?>
</td>
<td>

<a href="?page=nguk-transfer&customer_id=<?php echo $customer->id; ?>&beneficiary_id=<?php echo $beneficiary->id; ?>"

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

    update_option('nguk_business_logo', sanitize_text_field($_POST['business_logo']));

    echo '<div class="updated"><p>Business settings saved successfully.</p></div>';
}
if (isset($_POST['save_exchange_rates'])) {

    update_option('nguk_buy_rate', sanitize_text_field($_POST['buy_rate']));

    update_option('nguk_sell_rate', sanitize_text_field($_POST['sell_rate']));

    echo '<div class="updated"><p>Exchange rates updated successfully.</p></div>';

}
if (isset($_POST['save_bank_account'])) {

    $bank_accounts_table = $wpdb->prefix . 'nguk_bank_accounts';

    $wpdb->insert(

        $bank_accounts_table,

        array(

            'account_type' => sanitize_text_field($_POST['account_type']),

            'bank_name' => sanitize_text_field($_POST['bank_name']),

            'account_name' => sanitize_text_field($_POST['account_name']),

            'account_number' => sanitize_text_field($_POST['account_number']),

            'extra_details' => sanitize_text_field($_POST['extra_details'])

        )

    );

    echo '<div class="updated"><p>Bank account saved successfully.</p></div>';

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

        $profit = abs(
            ($naira_amount / $sell_rate) - ($naira_amount / $buy_rate)
        );

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

    $customer_name = $customer->customer_name;
    $nigeria_bank = sanitize_textarea_field($_POST['nigeria_bank_details']);

    $uk_bank = implode(
        "\n",
        array_filter(
            array(
                $beneficiary->bank_name,
                $beneficiary->account_name,
                $beneficiary->account_number,
                $beneficiary->sort_code
            )
        )
    );

    $wpdb->insert(

        $transactions_table,

        array(

            'customer_name' => $customer_name,

            'beneficiary_name' => $beneficiary->beneficiary_name,

            'naira_amount' => $naira_amount,

            'pounds_amount' => $pounds_amount,

            'profit' => $profit,

            'buy_rate' => $buy_rate,

            'sell_rate' => $sell_rate,
            'nigeria_bank_details' => $nigeria_bank,

'uk_bank_details' => $uk_bank,

            'status' => 'Pending'

        )

    );
    echo '<div class="updated"><p>Transaction created successfully.</p></div>';

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
        ?>

        <div class="wrap">

            <h1>NG-UK Money Transfer Dashboard</h1>

            <?php

            $dashboard_buy_rate = get_option('nguk_buy_rate', '2000');

            $dashboard_sell_rate = get_option('nguk_sell_rate', '1900');

            ?>

            <style>
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
            </style>

            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:20px;">

                <div class="nguk-rate-card">
                    <h2>Buy Rate</h2>
                    <strong>&#8358;<?php echo esc_html(number_format(floatval($dashboard_buy_rate), 2)); ?></strong>
                </div>

                <div class="nguk-rate-card">
                    <h2>Sell Rate</h2>
                    <strong>&#8358;<?php echo esc_html(number_format(floatval($dashboard_sell_rate), 2)); ?></strong>
                </div>

            </div>

            <p style="margin-top:15px;">
                <a href="?page=nguk-transfer&edit_rates=1#nguk-rate-settings"
                   class="button button-primary">
                   Change Rates
                </a>
            </p>

            <?php if (isset($_GET['edit_rates'])) { ?>

            <div id="nguk-rate-settings"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:20px;">

                <h2>Exchange Rates</h2>

                <form method="post">
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
            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

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

</div>

<?php if (isset($_GET['create_customer'])) { ?>

            <div id="nguk-create-customer"
                 style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

                <h2>Create Customer</h2>

                <form method="post">

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
                            <th>Notes / Risk Assessment</th>
                            <td>
                                <textarea name="notes"
                                          class="large-text"
                                          rows="4"></textarea>
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

            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

                <h2>Registered Customers</h2>

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

                    <input type="search"
                           name="customer_search"
                           class="regular-text"
                           placeholder="Search customer by name or phone"
                           value="<?php echo esc_attr(isset($_GET['customer_search']) ? sanitize_text_field($_GET['customer_search']) : ''); ?>">

                    <input type="submit"
                           class="button"
                           value="Search">

                    <a href="?page=nguk-transfer"
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
                            <th>Profile</th>
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
                                    <?php echo esc_html($customer->customer_name); ?>
                                </td>

                                <td>
                                    <?php echo esc_html($customer->phone_number); ?>
                                </td>

                                <td>

                                    <a href="?page=nguk-transfer&view_customer=<?php echo $customer->id; ?>"
                                       class="button button-primary">

                                       View Profile

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
            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">
<div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

<h2>Register Bank Account</h2>

<form method="post">

<table class="form-table">

<tr>
    <th>Account Type</th>

    <td>
        <select name="account_type">

            <option value="Nigeria">
                Nigeria Bank
            </option>

            <option value="UK">
                UK Bank
            </option>

        </select>
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

</div>
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

                                    <?php echo esc_html($customer->customer_name); ?>

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

data-account-name="<?php echo esc_attr($beneficiary->account_name); ?>"

data-account-number="<?php echo esc_attr($beneficiary->account_number); ?>"

data-sort-code="<?php echo esc_attr($beneficiary->sort_code); ?>"

<?php selected($selected_beneficiary_id, $beneficiary->id); ?>

>

            <?php
            echo esc_html(
                $beneficiary->beneficiary_name .
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

            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

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

                    <input type="submit"
                           class="button"
                           value="Search">

                    <a href="?page=nguk-transfer"
                       class="button">
                       Clear
                    </a>

                </form>

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Customer</th>
                            <th>Naira Paid</th>
                            <th>Pounds</th>
                            <th>Profit</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>

                    <tbody>

                       <?php

$transactions_table = $wpdb->prefix . 'nguk_transactions';

$transactions_per_page = 15;

$transaction_page = isset($_GET['transaction_page'])
    ? max(1, intval($_GET['transaction_page']))
    : 1;

$transaction_search = isset($_GET['transaction_search'])
    ? sanitize_text_field($_GET['transaction_search'])
    : '';

if (!empty($transaction_search)) {

    $transaction_like = '%' . $wpdb->esc_like($transaction_search) . '%';

    $customers_table = $wpdb->prefix . 'nguk_customers';

    $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';

    $all_transactions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT t.*
             FROM $transactions_table t
             LEFT JOIN $customers_table c
                ON c.customer_name = t.customer_name
             WHERE t.customer_name LIKE %s
                OR t.beneficiary_name LIKE %s
                OR c.phone_number LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM $beneficiaries_table b
                    WHERE b.customer_id = c.id
                      AND b.beneficiary_name LIKE %s
                )
             ORDER BY t.id DESC",
            $transaction_like,
            $transaction_like,
            $transaction_like,
            $transaction_like
        )
    );

} else {

    $all_transactions = $wpdb->get_results(
        "SELECT * FROM $transactions_table ORDER BY id DESC"
    );

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

if ($transactions) {

    $count = (($transaction_page - 1) * $transactions_per_page) + 1;

    foreach ($transactions as $transaction) {

        ?>

        <tr>

            <td><?php echo $count++; ?></td>

            <td>
                <?php echo esc_html($transaction->customer_name); ?>
            </td>

            <td>
                ₦<?php echo number_format($transaction->naira_amount, 2); ?>
            </td>

            <td>
                £<?php echo floatval($transaction->buy_rate) > 0 ? number_format($transaction->naira_amount / $transaction->buy_rate, 2) : '0.00'; ?> 
            </td>

            <td style="color:green;">

                £<?php echo number_format($transaction->profit, 2); ?>

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
}

?>

                    </tbody>

                </table>

                <?php if ($transaction_total_pages > 1) { ?>

                    <p style="margin-top:15px;">

                        <?php for ($page_number = 1; $page_number <= $transaction_total_pages; $page_number++) { ?>

                            <a class="button <?php echo $page_number == $transaction_page ? 'button-primary' : ''; ?>"
                               href="?page=nguk-transfer&transaction_page=<?php echo $page_number; ?><?php echo !empty($transaction_search) ? '&transaction_search=' . urlencode($transaction_search) : ''; ?>">
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

        SUM(profit) as total_profit

    FROM $transactions_table

    GROUP BY YEAR(created_at), MONTH(created_at)

    ORDER BY created_at DESC"

);

?>

<div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

    <h2>Monthly Turnovers</h2>

    <table class="widefat striped"

style="
margin-top:20px;
border-radius:12px;
overflow:hidden;
font-size:15px;
box-shadow:0 2px 10px rgba(0,0,0,0.05);
">

        <thead style="background:#f7f7f7;">

            <tr>

                <th>Month</th>

                <th>Total Transactions</th>

                <th>Total Naira</th>

                <th>Total Pounds</th>

                <th>Total Profit</th>

            </tr>

        </thead>

        <tbody>

            <?php

            if ($monthly_turnovers) {

                foreach ($monthly_turnovers as $turnover) {

                    ?>

                 <tr style="height:60px;">

                        <td>
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

                        <td style="color:green;">
                            £<?php echo number_format($turnover->total_profit, 2); ?>
                        </td>

                    </tr>

                    <?php
                }

            } else {

                ?>

                <tr>

                    <td colspan="5">

                        No monthly turnover data found.

                    </td>

                </tr>

                <?php
            }

            ?>

        </tbody>

    </table>

</div>
        <script>

jQuery(document).ready(function($){

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
            option.data('account-name'),
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
