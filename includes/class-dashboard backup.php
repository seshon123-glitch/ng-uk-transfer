<?php
if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Dashboard {

    public function dashboard_page() {

        global $wpdb;

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

                <div class="wrap">

                    <h1>Transaction Receipt</h1>

                    <table class="widefat striped" style="max-width:800px;">
<tr>
    <th>Date</th>

    <td>
        <?php echo date('d M Y h:i A'); ?>
    </td>
</tr>
                        <tr>
                            <th>Customer</th>
                            <td><?php echo esc_html($transaction->customer_name); ?></td>
                        </tr>

                        <tr>
                            <th>Naira Paid</th>
                            <td>₦<?php echo number_format($transaction->naira_amount, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Pounds</th>
                            <td>£<?php echo number_format($transaction->pounds_amount, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Profit</th>
                            <td style="color:green;">
                                £<?php echo number_format($transaction->profit, 2); ?>
                            </td>
                        </tr>

                        <tr>
                            <th>Buy Rate</th>
                            <td><?php echo number_format($transaction->buy_rate, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Sell Rate</th>
                            <td><?php echo number_format($transaction->sell_rate, 2); ?></td>
                        </tr>

                        <tr>
                            <th>Status</th>
                            <td><?php echo esc_html($transaction->status); ?></td>
                        </tr>

                    </table>

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
        if (isset($_POST['save_transaction'])) {

    $transactions_table = $wpdb->prefix . 'nguk_transactions';

    $customer_id = intval($_POST['customer_id']);

    $naira_amount = floatval($_POST['naira_amount']);

    $buy_rate = floatval($_POST['buy_rate']);

    $sell_rate = floatval($_POST['sell_rate']);

    if ($sell_rate > 0) {

        $pounds_amount = $naira_amount / $sell_rate;

    } else {

        $pounds_amount = 0;

    }
 $profit = ($naira_amount / $sell_rate) - ($naira_amount / $buy_rate);
    $customers_table = $wpdb->prefix . 'nguk_customers';

    $customer = $wpdb->get_row(
        "SELECT * FROM $customers_table WHERE id = $customer_id"
    );

    $customer_name = $customer ? $customer->customer_name : '';

    $wpdb->insert(

        $transactions_table,

        array(

            'customer_name' => $customer_name,

            'naira_amount' => $naira_amount,

            'pounds_amount' => $pounds_amount,

            'profit' => $profit,

            'buy_rate' => $buy_rate,

            'sell_rate' => $sell_rate,

            'status' => 'Pending'

        )

    );
    echo '<div class="updated"><p>Transaction created successfully.</p></div>';

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

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-top:20px;">

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

                <h2>Registered Customers</h2>

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

                    $customers = $wpdb->get_results(
                        "SELECT * FROM $customers_table ORDER BY id DESC"
                    );

                    if ($customers) {

                        $count = 1;

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

            </div>
            <div style="background:#fff;padding:25px;border-radius:12px;margin-top:30px;">

    <h2>Create Transaction</h2>

    <form method="post">

        <table class="form-table">

            <tr>

                <th>Select Customer</th>

                <td>

                    <select name="customer_id">

                        <option value="">
                            Select Customer
                        </option>

                        <?php

                        $customers_table = $wpdb->prefix . 'nguk_customers';

                        $customers = $wpdb->get_results(
                            "SELECT * FROM $customers_table ORDER BY customer_name ASC"
                        );

                        if ($customers) {

                            foreach ($customers as $customer) {

                                ?>

                                <option value="<?php echo $customer->id; ?>">

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

                <th>Naira Paid</th>

                <td>

                    <input type="number"
                           name="naira_amount"
                           class="regular-text">

                </td>

            </tr>

            <tr>

                <th>Buy Rate</th>

                <td>

                    <input type="number"
                           name="buy_rate"
                           class="regular-text">

                </td>

            </tr>

            <tr>

                <th>Sell Rate</th>

                <td>

                    <input type="number"
                           name="sell_rate"
                           class="regular-text">

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

$transactions = $wpdb->get_results(
    "SELECT * FROM $transactions_table ORDER BY id DESC"
);

if ($transactions) {

    $count = 1;

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
                £<?php echo number_format($transaction->pounds_amount, 2); ?>
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

            </div>

        </div>

        <?php
    }
}
