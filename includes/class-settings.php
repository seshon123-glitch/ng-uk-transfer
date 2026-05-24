<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Settings {

    // SAVE SETTINGS
    public static function save_settings() {

        if (isset($_POST['nguk_save_settings'])) {

            update_option(
                'nguk_buy_rate',
                sanitize_text_field($_POST['buy_rate'])
            );

            update_option(
                'nguk_sell_rate',
                sanitize_text_field($_POST['sell_rate'])
            );

            update_option(
                'nguk_business_name',
                sanitize_text_field($_POST['business_name'])
            );

            update_option(
                'nguk_business_email',
                sanitize_email($_POST['business_email'])
            );

            update_option(
                'nguk_business_phone',
                sanitize_text_field($_POST['business_phone'])
            );

            update_option(
                'nguk_business_address',
                sanitize_textarea_field($_POST['business_address'])
            );

            update_option(
                'nguk_business_website',
                sanitize_text_field($_POST['business_website'])
            );

            echo '
            <div class="notice notice-success is-dismissible">
                <p>Settings saved successfully.</p>
            </div>
            ';

        }

    }

    // SETTINGS FORM
    public static function settings_form() {

        $buy_rate = get_option('nguk_buy_rate', '2000');

        $sell_rate = get_option('nguk_sell_rate', '1900');

        $business_name = get_option('nguk_business_name', '');

        $business_email = get_option('nguk_business_email', '');

        $business_phone = get_option('nguk_business_phone', '');

        $business_address = get_option('nguk_business_address', '');

        $business_website = get_option('nguk_business_website', '');

        echo '

        <div style="
            background:#fff;
            padding:25px;
            margin-top:30px;
            border-radius:12px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        ">

            <h2>Business & Exchange Settings</h2>

            <form method="post">

                <table class="form-table">

                    <tr>
                        <th>Buy Rate</th>

                        <td>
                            <input
                                type="number"
                                name="buy_rate"
                                value="'.$buy_rate.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th>Sell Rate</th>

                        <td>
                            <input
                                type="number"
                                name="sell_rate"
                                value="'.$sell_rate.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th>Business Name</th>

                        <td>
                            <input
                                type="text"
                                name="business_name"
                                value="'.$business_name.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th>Business Email</th>

                        <td>
                            <input
                                type="email"
                                name="business_email"
                                value="'.$business_email.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th>Business Phone</th>

                        <td>
                            <input
                                type="text"
                                name="business_phone"
                                value="'.$business_phone.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                    <tr>
                        <th>Business Address</th>

                        <td>
                            <textarea
                                name="business_address"
                                rows="4"
                                class="large-text"
                            >'.$business_address.'</textarea>
                        </td>
                    </tr>

                    <tr>
                        <th>Business Website</th>

                        <td>
                            <input
                                type="text"
                                name="business_website"
                                value="'.$business_website.'"
                                class="regular-text"
                            >
                        </td>
                    </tr>

                </table>

                <p>
                    <input
                        type="submit"
                        name="nguk_save_settings"
                        class="button button-primary"
                        value="Save Settings"
                    >
                </p>

            </form>

        </div>

        ';

    }

}