<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Transactions {

    // SAVE TRANSACTION
    public static function save_transaction() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_transactions';

        if (isset($_POST['nguk_save_transaction'])) {

            $customer_id = intval($_POST['customer_id']);

            $customers_table = $wpdb->prefix . 'nguk_customers';

            $customer = $wpdb->get_row(
                "SELECT * FROM $customers_table WHERE id = $customer_id"
            );

            if (!$customer) {
                return;
            }

            $beneficiary_name = '';
            $beneficiary_bank_details = $customer->uk_bank_details;
            $beneficiaries_table = $wpdb->prefix . 'nguk_beneficiaries';
            $beneficiary_id = isset($_POST['beneficiary_id'])
                ? intval($_POST['beneficiary_id'])
                : 0;

            if ($beneficiary_id > 0) {
                $beneficiary = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $beneficiaries_table WHERE id = %d AND customer_id = %d",
                        $beneficiary_id,
                        $customer_id
                    )
                );
            } else {
                $beneficiary = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $beneficiaries_table WHERE customer_id = %d ORDER BY id DESC LIMIT 1",
                        $customer_id
                    )
                );
            }

            if ($beneficiary) {
                $beneficiary_name = strtoupper($beneficiary->beneficiary_name);
                $beneficiary_bank_details = implode(
                    "\n",
                    array_filter(
                        array(
                            $beneficiary->bank_name,
                            $beneficiary->account_number,
                            $beneficiary->sort_code
                        )
                    )
                );
            }

            $naira_amount = floatval(
                $_POST['naira_amount']
            );

            $buy_rate = get_option(
                'nguk_buy_rate',
                '2000'
            );

            $sell_rate = get_option(
                'nguk_sell_rate',
                '1900'
            );

            // CALCULATIONS
            $pounds_amount = $naira_amount / $buy_rate;

            $sell_equivalent = $naira_amount / $sell_rate;

            $profit = $sell_equivalent - $pounds_amount;

            // INSERT
            $wpdb->insert(

                $table,

                array(

                    'customer_name' => $customer->customer_name,

                    'beneficiary_name' => $beneficiary_name,

                    'naira_amount' => $naira_amount,

                    'pounds_amount' => $pounds_amount,

                    'profit' => $profit,

                    'buy_rate' => $buy_rate,

                    'sell_rate' => $sell_rate,

                    'nigeria_bank_details' => $customer->nigeria_bank_details,

                    'uk_bank_details' => $beneficiary_bank_details,

                    'status' => 'Pending',

                    'tracking_code' => NGUK_Database::generate_tracking_code(),

                    'status_updated_at' => current_time('mysql')

                )

            );

            NGUK_Database::clear_monthly_cache('nguk');

            echo '
            <div class="notice notice-success is-dismissible">
                <p>Transaction created successfully.</p>
            </div>
            ';

        }

    }

    // DELETE TRANSACTION
    public static function delete_transaction() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_transactions';

        if (isset($_GET['delete_transaction'])) {

            $id = intval($_GET['delete_transaction']);

            $wpdb->delete(
                $table,
                array('id' => $id)
            );

            NGUK_Database::clear_monthly_cache('nguk');

            echo '
            <div class="notice notice-success is-dismissible">
                <p>Transaction deleted successfully.</p>
            </div>
            ';

        }

    }

    // TRANSACTION FORM
    public static function transaction_form() {

        $customers = NGUK_Customers::get_customers();

        echo '

        <div style="
            background:#fff;
            padding:25px;
            margin-top:30px;
            border-radius:12px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        ">

            <h2>Create Transaction</h2>

            <form method="post">

                <table class="form-table">

                    <tr>

                        <th>Select Customer</th>

                        <td>

                            <select
                                name="customer_id"
                                required
                            >

                                <option value="">
                                    Select Customer
                                </option>';

                                foreach ($customers as $customer) {

                                    echo '

                                    <option value="'.$customer->id.'">
                                        '.$customer->customer_name.'
                                    </option>

                                    ';

                                }

                                echo '

                            </select>

                        </td>

                    </tr>

                    <tr>

                        <th>Naira Paid</th>

                        <td>

                            <input
                                type="number"
                                name="naira_amount"
                                class="regular-text"
                                required
                            >

                        </td>

                    </tr>

                </table>

                <p>

                    <input
                        type="submit"
                        name="nguk_save_transaction"
                        class="button button-primary"
                        value="Create Transaction"
                    >

                </p>

            </form>

        </div>

        ';

    }

    // GET TRANSACTIONS
    public static function get_transactions() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_transactions';

        return $wpdb->get_results(
            "SELECT * FROM $table ORDER BY id DESC"
        );

    }

    // TOTAL PROFIT
    public static function total_profit() {

        $transactions = self::get_transactions();

        $profit = 0;

        if ($transactions) {

            foreach ($transactions as $transaction) {

                $profit += $transaction->profit;

            }

        }

        return $profit;

    }

    // MONTHLY TOTALS
    public static function monthly_totals() {

        $transactions = self::get_transactions();

        $monthly_profit = 0;

        $monthly_naira = 0;

        $monthly_pounds = 0;

        $current_month = date('Y-m');

        if ($transactions) {

            foreach ($transactions as $transaction) {

                if (
                    date(
                        'Y-m',
                        strtotime($transaction->created_at)
                    ) == $current_month
                ) {

                    $monthly_profit += $transaction->profit;

                    $monthly_naira += $transaction->naira_amount;

                    $monthly_pounds += $transaction->pounds_amount;

                }

            }

        }

        return array(
            'profit' => $monthly_profit,
            'naira' => $monthly_naira,
            'pounds' => $monthly_pounds
        );

    }

}
