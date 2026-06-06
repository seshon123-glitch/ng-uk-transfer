<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Customers {

    // SAVE CUSTOMER
    public static function save_customer() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_customers';

        if (isset($_POST['nguk_save_customer'])) {

            $customer_name = sanitize_text_field(
                $_POST['customer_name']
            );

            $phone_number = sanitize_text_field(
                $_POST['phone_number']
            );

            $address = sanitize_textarea_field(
                $_POST['address']
            );

            $notes = sanitize_textarea_field(
                $_POST['notes']
            );

            $nigeria_bank_details =
                sanitize_textarea_field(
                    $_POST['nigeria_bank_details']
                );

            $uk_bank_details =
                isset($_POST['uk_bank_details'])
                    ? sanitize_textarea_field($_POST['uk_bank_details'])
                    : '';

            // SAVE CUSTOMER
            $wpdb->insert(

                $table,

                array(

                    'customer_name' =>
                        $customer_name,

                    'phone_number' =>
                        $phone_number,

                    'address' =>
                        $address,

                    'notes' =>
                        $notes,

                    'nigeria_bank_details' =>
                        $nigeria_bank_details,

                    'uk_bank_details' =>
                        $uk_bank_details

                )

            );

            echo '

            <div class="notice notice-success">

                <p>
                    Customer saved successfully.
                </p>

            </div>

            ';

        }

    }

    // GET CUSTOMERS
    public static function get_customers() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_customers';

        return $wpdb->get_results(

            "SELECT * FROM $table
             ORDER BY customer_name ASC"

        );

    }

    // CUSTOMER FORM
    public static function customer_form() {

        echo '

        <div style="
            background:#fff;
            padding:25px;
            margin-top:30px;
            border-radius:12px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        ">

            <h2>Register Customer</h2>

            <form method="post">

                <table class="form-table">

                    <tr>

                        <th>Customer Name</th>

                        <td>

                            <input
                                type="text"
                                name="customer_name"
                                class="regular-text"
                                required
                            >

                        </td>

                    </tr>

                    <tr>

                        <th>Phone Number</th>

                        <td>

                            <input
                                type="text"
                                name="phone_number"
                                class="regular-text"
                                required
                            >

                        </td>

                    </tr>

                    <tr>

                        <th>Address (Optional)</th>

                        <td>

                            <input
                                type="text"
                                name="address"
                                class="large-text"
                                placeholder="Enter address"
                            >

                        </td>

                    </tr>

                    <tr>

                        <th>Nigeria Bank Details</th>

                        <td>

                            <textarea
                                name="nigeria_bank_details"
                                rows="4"
                                class="large-text"
                                required
                            ></textarea>

                        </td>

                    </tr>

                    <tr>

                        <th>Notes / Risk Assessment</th>

                        <td>

                            <textarea
                                name="notes"
                                rows="5"
                                class="large-text"
                                placeholder="Compliance notes or risk assessment"
                            ></textarea>

                        </td>

                    </tr>

                </table>

                <p>

                    <input
                        type="submit"
                        name="nguk_save_customer"
                        class="button button-primary"
                        value="Save Customer"
                    >

                </p>

            </form>

        </div>

        ';

    }

    // CUSTOMERS TABLE
    public static function customers_table() {

        global $wpdb;

        $table = $wpdb->prefix . 'nguk_customers';

        $customers = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY customer_name ASC"
        );

        echo '

        <div style="
            background:#fff;
            padding:25px;
            margin-top:30px;
            border-radius:12px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        ">

            <h2>Registered Customers</h2>

            <input
                type="text"
                id="customerSearch"
                placeholder="Search customer..."
                style="
                    width:300px;
                    padding:10px;
                    margin-bottom:20px;
                "
            >

            <table
                class="widefat striped"
                id="customersTable"
            >

                <thead>

                    <tr>

                        <th>No.</th>

                        <th>Customer Name</th>

                        <th>Phone</th>

                        <th>Address</th>

                        <th>Nigeria Bank</th>

                        <th>UK Bank</th>

                        <th>Notes</th>

                    </tr>

                </thead>

                <tbody>

        ';

        if ($customers) {

            $number = 1;

            foreach ($customers as $customer) {

                echo '

                <tr>

                    <td>' . $number . '</td>

                    <td>' . esc_html($customer->customer_name) . '</td>

                    <td>' . esc_html(isset($customer->phone_number) ? $customer->phone_number : '') . '</td>

                    <td>' . esc_html(isset($customer->address) ? $customer->address : '') . '</td>

                    <td>' . esc_html($customer->nigeria_bank_details) . '</td>

                    <td>' . esc_html($customer->uk_bank_details) . '</td>

                    <td>' . esc_html(isset($customer->notes) ? $customer->notes : '') . '</td>

                </tr>

                ';

                $number++;

            }

        } else {

            echo '

            <tr>

                <td colspan="7">
                    No customers found.
                </td>

            </tr>

            ';

        }

        echo '

                </tbody>

            </table>

        </div>

        <script>

        document.getElementById("customerSearch")
        .addEventListener("keyup", function() {

            let filter =
                this.value.toLowerCase();

            let rows =
                document.querySelectorAll(
                    "#customersTable tbody tr"
                );

            rows.forEach(function(row) {

                let text =
                    row.innerText.toLowerCase();

                row.style.display =
                    text.includes(filter)
                    ? ""
                    : "none";

            });

        });

        </script>

        ';

    }

}
