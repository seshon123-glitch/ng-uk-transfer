<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Receipts {

    // VIEW RECEIPT
    public static function view_receipt() {

        if (!isset($_GET['receipt_id'])) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_transactions';

        $id = intval($_GET['receipt_id']);

        $transaction = $wpdb->get_row(
            "SELECT * FROM $table WHERE id = $id"
        );

        if (!$transaction) {

            echo '
            <div class="notice notice-error">
                <p>Receipt not found.</p>
            </div>
            ';

            return;

        }

        // BUSINESS DETAILS
        $business_name = get_option(
            'nguk_business_name',
            ''
        );

        $business_email = get_option(
            'nguk_business_email',
            ''
        );

        $business_phone = get_option(
            'nguk_business_phone',
            ''
        );

        $business_address = get_option(
            'nguk_business_address',
            ''
        );

        $business_website = get_option(
            'nguk_business_website',
            ''
        );

        echo '

        <div class="wrap">

            <div style="
                background:#fff;
                max-width:900px;
                margin:30px auto;
                padding:40px;
                border-radius:12px;
                box-shadow:0 2px 15px rgba(0,0,0,0.1);
            ">

                <div style="
                    text-align:center;
                    margin-bottom:40px;
                ">

                    <h1>'.$business_name.'</h1>

                    <p>'.$business_address.'</p>

                    <p>'.$business_phone.'</p>

                    <p>'.$business_email.'</p>

                    <p>'.$business_website.'</p>

                    <hr>

                    <h2>
                        Transaction Receipt
                    </h2>

                </div>

                <table class="widefat striped">

                    <tbody>

                        <tr>
                            <th>Customer Name</th>

                            <td>
                                '.$transaction->customer_name.'
                            </td>
                        </tr>

                        <tr>
                            <th>Beneficiary Name</th>

                            <td>
                                '.(isset($transaction->beneficiary_name) ? $transaction->beneficiary_name : '').'
                            </td>
                        </tr>

                        <tr>
                            <th>Naira Paid</th>

                            <td>
                                ₦'.number_format(
                                    $transaction->naira_amount
                                ).'
                            </td>
                        </tr>

                        <tr>
                            <th>Pounds Equivalent</th>

                            <td>
                                £'.number_format(
                                    $transaction->pounds_amount,
                                    2
                                ).'
                            </td>
                        </tr>

                        <tr>
                            <th>Buy Rate Used</th>

                            <td>
                                ₦'.number_format(
                                    $transaction->buy_rate
                                ).'
                            </td>
                        </tr>

                        <tr>
                            <th>Nigeria Bank Details</th>

                            <td>
                                '.$transaction->nigeria_bank_details.'
                            </td>
                        </tr>

                        <tr>
                            <th>UK Bank Details</th>

                            <td>
                                '.$transaction->uk_bank_details.'
                            </td>
                        </tr>

                        <tr>
                            <th>Status</th>

                            <td>
                                '.$transaction->status.'
                            </td>
                        </tr>

                        <tr>
                            <th>Transaction Date</th>

                            <td>
                                '.$transaction->created_at.'
                            </td>
                        </tr>

                    </tbody>

                </table>

                <div style="
                    margin-top:30px;
                    text-align:center;
                ">

                    <button
                        onclick="window.print();"
                        class="button button-primary"
                    >
                        Print Receipt
                    </button>

                </div>

            </div>

        </div>

        ';

        exit;

    }

}
