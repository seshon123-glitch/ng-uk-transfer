<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Frontend_Website {

    const VERSION = '2.2-astra-layout-menu4';
    const HOME_OPTION = 'nguk_public_home_page_id';
    const CONTACT_OPTION = 'nguk_public_contact_page_id';
    const VERSION_OPTION = 'nguk_public_site_version';

    public static function init() {
        add_shortcode('nguk_public_logo', array(__CLASS__, 'logo_shortcode'));
        add_shortcode('nguk_buy_rate', array(__CLASS__, 'buy_rate_shortcode'));
        add_shortcode('nguk_sell_rate', array(__CLASS__, 'sell_rate_shortcode'));
        add_shortcode('nguk_rate_banner', array(__CLASS__, 'rate_banner_shortcode'));
        add_shortcode('nguk_rate_ticker', array(__CLASS__, 'rate_ticker_shortcode'));
        add_shortcode('nguk_transfer_calculator', array(__CLASS__, 'calculator_shortcode'));
        add_shortcode('nguk_contact_form', array(__CLASS__, 'contact_form_shortcode'));

        add_filter('body_class', array(__CLASS__, 'body_classes'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('init', array(__CLASS__, 'handle_contact_form'));
    }

    public static function body_classes($classes) {
        if (is_singular('page')) {
            $page_id = get_queried_object_id();

            if (
                intval(get_option(self::HOME_OPTION)) === intval($page_id) ||
                intval(get_option(self::CONTACT_OPTION)) === intval($page_id)
            ) {
                $classes[] = 'nguk-public-page';
            }
        }

        return $classes;
    }

    public static function maybe_setup_site() {
        $installed_version = get_option(self::VERSION_OPTION, '');

        if ($installed_version !== self::VERSION) {
            self::setup_site();
            update_option(self::VERSION_OPTION, self::VERSION);
        }
    }

    public static function setup_site() {
        self::maybe_activate_astra();

        $home_id = self::create_or_update_page(
            'Home',
            'home',
            self::home_content(),
            self::HOME_OPTION
        );

        $contact_id = self::create_or_update_page(
            'Contact',
            'contact',
            self::contact_content(),
            self::CONTACT_OPTION
        );

        if ($home_id) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $home_id);
        }

        if ($contact_id && !get_option('nguk_business_email')) {
            update_option('nguk_business_email', 'info@daphkoy.com');
        }

        self::create_public_menu($home_id, $contact_id);
        self::sync_block_theme_navigation($home_id, $contact_id);
    }

    private static function maybe_activate_astra() {
        $astra_theme = wp_get_theme('astra');

        if ($astra_theme && $astra_theme->exists() && get_stylesheet() !== 'astra') {
            switch_theme('astra');
        }
    }

    private static function create_public_menu($home_id, $contact_id) {
        $menu_name = 'NG-UK Public Menu';
        $menu = wp_get_nav_menu_object($menu_name);

        if (!$menu) {
            $menu_id = wp_create_nav_menu($menu_name);
        } else {
            $menu_id = intval($menu->term_id);
        }

        if (!$menu_id || is_wp_error($menu_id)) {
            return;
        }

        self::clear_menu_items($menu_id);
        self::ensure_menu_page_item($menu_id, 'Home', $home_id);
        self::ensure_menu_custom_item(
            $menu_id,
            'Rates',
            home_url('/#live-rates'),
            array()
        );
        self::ensure_menu_page_item($menu_id, 'Contact', $contact_id);
        self::ensure_menu_custom_item(
            $menu_id,
            'Login',
            'https://remit.daphnex.co.uk/wp-admin',
            array('nguk-menu-login')
        );

        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['primary'] = $menu_id;
        $locations['menu-1'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }

    private static function clear_menu_items($menu_id) {
        $items = wp_get_nav_menu_items($menu_id);

        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            wp_delete_post(intval($item->ID), true);
        }
    }

    private static function sync_block_theme_navigation($home_id, $contact_id) {
        if (!$home_id || !$contact_id || !post_type_exists('wp_navigation')) {
            return;
        }

        $navigation_content = self::block_navigation_content($home_id, $contact_id);

        $navigation_posts = get_posts(
            array(
                'post_type' => 'wp_navigation',
                'post_status' => array('publish', 'draft'),
                'numberposts' => -1
            )
        );

        if (!$navigation_posts) {
            wp_insert_post(
                array(
                    'post_title' => 'NG-UK Public Navigation',
                    'post_name' => 'ng-uk-public-navigation',
                    'post_type' => 'wp_navigation',
                    'post_status' => 'publish',
                    'post_content' => $navigation_content
                )
            );
            return;
        }

        foreach ($navigation_posts as $navigation_post) {
            wp_update_post(
                array(
                    'ID' => intval($navigation_post->ID),
                    'post_content' => $navigation_content
                )
            );
        }
    }

    private static function block_navigation_content($home_id, $contact_id) {
        $home_url = home_url('/');
        $rates_url = home_url('/#live-rates');
        $contact_url = get_permalink($contact_id);
        $login_url = 'https://remit.daphnex.co.uk/wp-admin';

        return '<!-- wp:navigation-link {"label":"Home","type":"page","id":' . intval($home_id) . ',"url":"' . esc_url($home_url) . '","kind":"post-type","isTopLevelLink":true} /-->' . "\n"
            . '<!-- wp:navigation-link {"label":"Rates","url":"' . esc_url($rates_url) . '","kind":"custom","isTopLevelLink":true} /-->' . "\n"
            . '<!-- wp:navigation-link {"label":"Contact","type":"page","id":' . intval($contact_id) . ',"url":"' . esc_url($contact_url) . '","kind":"post-type","isTopLevelLink":true} /-->' . "\n"
            . '<!-- wp:navigation-link {"label":"Login","url":"' . esc_url($login_url) . '","kind":"custom","className":"nguk-menu-login","isTopLevelLink":true} /-->';
    }

    private static function ensure_menu_page_item($menu_id, $title, $page_id) {
        if (!$page_id) {
            return;
        }

        $items = wp_get_nav_menu_items($menu_id);

        if ($items) {
            foreach ($items as $item) {
                if (intval($item->object_id) === intval($page_id) && $item->object === 'page') {
                    return;
                }
            }
        }

        wp_update_nav_menu_item(
            $menu_id,
            0,
            array(
                'menu-item-title' => $title,
                'menu-item-object' => 'page',
                'menu-item-object-id' => intval($page_id),
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish'
            )
        );
    }

    private static function ensure_menu_custom_item($menu_id, $title, $url, $classes = array()) {
        $items = wp_get_nav_menu_items($menu_id);

        if ($items) {
            foreach ($items as $item) {
                if ($item->title === $title && $item->url === $url) {
                    update_post_meta(intval($item->ID), '_menu_item_classes', $classes);
                    return;
                }
            }
        }

        $item_id = wp_update_nav_menu_item(
            $menu_id,
            0,
            array(
                'menu-item-title' => $title,
                'menu-item-url' => $url,
                'menu-item-type' => 'custom',
                'menu-item-status' => 'publish'
            )
        );

        if ($item_id && !is_wp_error($item_id)) {
            update_post_meta(intval($item_id), '_menu_item_classes', $classes);
        }
    }

    private static function create_or_update_page($title, $slug, $content, $option_name) {
        $page_id = intval(get_option($option_name, 0));

        if ($page_id <= 0 || get_post_status($page_id) === false) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            $page_id = $existing ? intval($existing->ID) : 0;
        }

        $page_data = array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
            'ping_status' => 'closed'
        );

        if ($page_id > 0) {
            $page_data['ID'] = $page_id;
            $result = wp_update_post($page_data, true);
        } else {
            $result = wp_insert_post($page_data, true);
        }

        if (is_wp_error($result)) {
            return 0;
        }

        $page_id = intval($result);
        update_option($option_name, $page_id);
        update_post_meta($page_id, '_nguk_auto_created_page', '1');
        update_post_meta($page_id, '_nguk_elementor_editable', '1');
        update_post_meta($page_id, '_wp_page_template', 'default');
        update_post_meta($page_id, 'site-content-layout', 'full-width-stretched');
        update_post_meta($page_id, 'site-sidebar-layout', 'no-sidebar');
        update_post_meta($page_id, 'theme-transparent-header-meta', 'disabled');
        update_post_meta($page_id, 'ast-title-bar-display', 'disabled');
        update_post_meta($page_id, 'footer-sml-layout', 'disabled');
        update_post_meta($page_id, '_elementor_template_type', 'wp-page');
        self::set_elementor_page_content($page_id, $content);

        return $page_id;
    }

    private static function set_elementor_page_content($page_id, $content) {
        $elementor_data = array(
            array(
                'id' => 'ngukSection',
                'elType' => 'section',
                'settings' => array(),
                'elements' => array(
                    array(
                        'id' => 'ngukColumn',
                        'elType' => 'column',
                        'settings' => array('_column_size' => 100),
                        'elements' => array(
                            array(
                                'id' => 'ngukContent',
                                'elType' => 'widget',
                                'widgetType' => 'text-editor',
                                'settings' => array('editor' => $content),
                                'elements' => array()
                            )
                        ),
                        'isInner' => false
                    )
                ),
                'isInner' => false
            )
        );

        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'wp-page');
        update_post_meta($page_id, '_elementor_page_settings', array('template' => 'default'));
    }

    private static function sync_elementor_page_content($page_id, $content) {
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);

        if ($elementor_data === '') {
            return;
        }

        $decoded = json_decode($elementor_data, true);

        if (!is_array($decoded)) {
            return;
        }

        $updated = self::replace_elementor_editor_content($decoded, $content);

        if ($updated) {
            update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($decoded)));
        }
    }

    private static function replace_elementor_editor_content(&$nodes, $content) {
        $updated = false;

        if (!is_array($nodes)) {
            return false;
        }

        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }

            if (
                isset($node['widgetType'], $node['settings']['editor']) &&
                $node['widgetType'] === 'text-editor' &&
                (
                    strpos($node['settings']['editor'], 'nguk-public-home') !== false ||
                    strpos($node['settings']['editor'], 'nguk-public-contact') !== false ||
                    strpos($node['settings']['editor'], 'nguk_') !== false
                )
            ) {
                $node['settings']['editor'] = $content;
                $updated = true;
            }

            if (isset($node['elements']) && is_array($node['elements'])) {
                $updated = self::replace_elementor_editor_content($node['elements'], $content) || $updated;
            }
        }

        return $updated;
    }

    private static function home_content() {
        return <<<'HTML'
<main class="nguk-public-home">
    [nguk_rate_banner]
    [nguk_rate_ticker]

    <section class="nguk-public-hero" id="rates">
        <div class="nguk-public-hero__content">
            <div class="nguk-public-logo">[nguk_public_logo]</div>
            <p class="nguk-public-eyebrow">Daphkoy Remittance Services</p>
            <h1>FAST &amp; SECURE NIGERIA ⇄ UK MONEY TRANSFERS</h1>
            <p class="nguk-public-subtitle">Competitive exchange rates, secure transactions and fast processing.</p>
            <div class="nguk-public-actions">
                <a href="#live-rates" class="nguk-public-button nguk-public-button--primary">Check Rates</a>
                <a href="/contact/" class="nguk-public-button nguk-public-button--secondary">Contact Us</a>
                <a href="https://remit.daphnex.co.uk/wp-admin" class="nguk-public-button nguk-public-button--dark">Admin Login</a>
            </div>
        </div>
    </section>

    <section class="nguk-public-section" id="live-rates">
        <div class="nguk-public-section-heading">
            <p>Live Exchange Rates</p>
            <h2>Current rates for Nigeria and UK transfers</h2>
        </div>
        <div class="nguk-rate-grid">
            <article class="nguk-rate-card">
                <span>Current Buy Rate</span>
                <strong>₦[nguk_buy_rate] / £1</strong>
            </article>
            <article class="nguk-rate-card">
                <span>Current Sell Rate</span>
                <strong>₦[nguk_sell_rate] / £1</strong>
            </article>
        </div>
    </section>

    <section class="nguk-public-section">
        <div class="nguk-public-section-heading">
            <p>Transfer Calculator</p>
            <h2>Estimate your transfer instantly</h2>
        </div>
        [nguk_transfer_calculator]
    </section>

    <section class="nguk-public-section">
        <div class="nguk-public-section-heading">
            <p>How It Works</p>
            <h2>Simple, secure and direct</h2>
        </div>
        <div class="nguk-info-grid nguk-info-grid--three">
            <article><span>1</span><h3>Contact Us</h3><p>Speak with our team and confirm the latest rate for your transfer.</p></article>
            <article><span>2</span><h3>Send Payment</h3><p>Make payment using the agreed details and share your confirmation.</p></article>
            <article><span>3</span><h3>Receive Funds</h3><p>We process the transfer quickly and confirm once funds are received.</p></article>
        </div>
    </section>

    <section class="nguk-public-section">
        <div class="nguk-public-section-heading">
            <p>Why Choose Us</p>
            <h2>Built for reliable money movement</h2>
        </div>
        <div class="nguk-info-grid">
            <article><span>⚡</span><h3>Fast Transfers</h3><p>Efficient processing for urgent personal and business transfers.</p></article>
            <article><span>🔐</span><h3>Secure Transactions</h3><p>Clear records and careful handling for every customer transaction.</p></article>
            <article><span>📈</span><h3>Competitive Rates</h3><p>Live rates pulled directly from our active transfer system.</p></article>
            <article><span>🤝</span><h3>Trusted Service</h3><p>A practical remittance service focused on customer confidence.</p></article>
            <article><span>🇬🇧</span><h3>UK Based</h3><p>Support for customers transferring between the UK and Nigeria.</p></article>
            <article><span>☎</span><h3>Reliable Support</h3><p>Get help before, during and after your transfer.</p></article>
        </div>
    </section>

    <section class="nguk-public-cta">
        <h2>Ready to send money?</h2>
        <p>Contact Daphkoy today to check the latest rate and start your transfer.</p>
        <a href="/contact/" class="nguk-public-button nguk-public-button--primary">Contact Us</a>
    </section>

    <section class="nguk-public-section" id="contact">
        <div class="nguk-public-section-heading">
            <p>Contact</p>
            <h2>Speak with our transfer team</h2>
        </div>
        [nguk_contact_form]
    </section>

    <footer class="nguk-public-footer">
        <div>[nguk_public_logo]</div>
        <p>Daphkoy Remittance Services. Fast and secure Nigeria ⇄ UK money transfers.</p>
    </footer>
</main>
HTML;
    }

    private static function contact_content() {
        return <<<'HTML'
<main class="nguk-public-contact">
    <section class="nguk-public-contact-hero">
        <div class="nguk-public-logo">[nguk_public_logo]</div>
        <p class="nguk-public-eyebrow">Contact Daphkoy</p>
        <h1>Contact Us</h1>
        <p>Send your message and our team will respond as soon as possible.</p>
    </section>

    <section class="nguk-public-section">
        [nguk_contact_form]
    </section>
</main>
HTML;
    }

    public static function logo_shortcode() {
        $logo = self::business_logo_url();

        if ($logo) {
            return '<img src="' . esc_url($logo) . '" alt="Daphkoy" class="nguk-public-logo-img">';
        }

        return '<strong class="nguk-public-logo-text">Daphkoy</strong>';
    }

    public static function buy_rate_shortcode() {
        return esc_html(number_format(floatval(get_option('nguk_buy_rate', '2000')), 2));
    }

    public static function sell_rate_shortcode() {
        return esc_html(number_format(floatval(get_option('nguk_sell_rate', '1900')), 2));
    }

    public static function rate_banner_shortcode() {
        $buy_rate = number_format(floatval(get_option('nguk_buy_rate', '2000')), 2);
        $sell_rate = number_format(floatval(get_option('nguk_sell_rate', '1900')), 2);

        return '<section class="nguk-rate-banner">
            <div class="nguk-rate-banner__track">
                <strong>TODAY\'S LIVE EXCHANGE RATE</strong>
                <span>BUY RATE: ₦' . esc_html($buy_rate) . ' / £1</span>
                <span>SELL RATE: ₦' . esc_html($sell_rate) . ' / £1</span>
                <span>Updated Automatically</span>
            </div>
        </section>';
    }

    public static function rate_ticker_shortcode() {
        $buy_rate = number_format(floatval(get_option('nguk_buy_rate', '2000')), 2);
        $sell_rate = number_format(floatval(get_option('nguk_sell_rate', '1900')), 2);
        $updated = esc_html(date_i18n('d M Y H:i', current_time('timestamp')));
        $item = 'BUY RATE ₦' . esc_html($buy_rate) . ' / £1 &nbsp; • &nbsp; SELL RATE ₦' . esc_html($sell_rate) . ' / £1 &nbsp; • &nbsp; LAST UPDATED ' . $updated;

        return '<div class="nguk-rate-ticker"><div class="nguk-rate-ticker__inner"><span>' . $item . '</span><span>' . $item . '</span></div></div>';
    }

    public static function calculator_shortcode() {
        $buy_rate = floatval(get_option('nguk_buy_rate', '2000'));
        $sell_rate = floatval(get_option('nguk_sell_rate', '1900'));

        return '<div class="nguk-calculator" data-buy-rate="' . esc_attr($buy_rate) . '" data-sell-rate="' . esc_attr($sell_rate) . '">
            <label>Naira amount<input type="number" min="0" step="0.01" class="nguk-calc-naira" placeholder="Enter naira amount"></label>
            <label>Pound amount<input type="number" min="0" step="0.01" class="nguk-calc-pound" placeholder="Enter pound amount"></label>
            <label>Rate mode<select class="nguk-calc-mode"><option value="buy">Use Buy Rate</option><option value="sell">Use Sell Rate</option></select></label>
            <div class="nguk-calculator__result">Enter an amount to calculate.</div>
        </div>';
    }

    public static function contact_form_shortcode() {
        $sent = isset($_GET['nguk_contact_sent']) && $_GET['nguk_contact_sent'] === '1';
        $error = isset($_GET['nguk_contact_error']) ? sanitize_text_field(wp_unslash($_GET['nguk_contact_error'])) : '';

        ob_start();
        ?>
        <div class="nguk-contact-card">
            <?php if ($sent) { ?>
                <div class="nguk-contact-message nguk-contact-message--success">Thank you. Your message has been sent successfully.</div>
            <?php } elseif ($error !== '') { ?>
                <div class="nguk-contact-message nguk-contact-message--error"><?php echo esc_html($error); ?></div>
            <?php } ?>

            <form method="post" class="nguk-contact-form">
                <?php wp_nonce_field('nguk_public_contact', 'nguk_public_contact_nonce'); ?>
                <input type="hidden" name="nguk_public_contact_submit" value="1">
                <label>Full Name<input type="text" name="full_name" required></label>
                <label>Email Address<input type="email" name="email" required></label>
                <label>Phone Number<input type="tel" name="phone"></label>
                <label>Message<textarea name="message" rows="6" required></textarea></label>
                <button type="submit">Send Message</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_contact_form() {
        if (!isset($_POST['nguk_public_contact_submit'])) {
            return;
        }

        $redirect = wp_get_referer() ? wp_get_referer() : home_url('/contact/');

        if (
            !isset($_POST['nguk_public_contact_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nguk_public_contact_nonce'])), 'nguk_public_contact')
        ) {
            wp_safe_redirect(add_query_arg('nguk_contact_error', rawurlencode('Security check failed. Please try again.'), $redirect));
            exit;
        }

        $full_name = isset($_POST['full_name']) ? sanitize_text_field(wp_unslash($_POST['full_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if ($full_name === '' || !is_email($email) || $message === '') {
            wp_safe_redirect(add_query_arg('nguk_contact_error', rawurlencode('Please complete all required fields with a valid email address.'), $redirect));
            exit;
        }

        $body = "New Daphkoy contact enquiry\n\n";
        $body .= "Name: $full_name\n";
        $body .= "Email: $email\n";
        $body .= "Phone: $phone\n\n";
        $body .= "Message:\n$message\n";

        $headers = array(
            'Reply-To: ' . $full_name . ' <' . $email . '>'
        );

        $sent = wp_mail('info@daphkoy.com', 'New Daphkoy contact enquiry', $body, $headers);

        if (!$sent) {
            wp_safe_redirect(add_query_arg('nguk_contact_error', rawurlencode('Message could not be sent. Please try again.'), $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg('nguk_contact_sent', '1', remove_query_arg('nguk_contact_error', $redirect)));
        exit;
    }

    public static function enqueue_assets() {
        if (!is_singular('page')) {
            return;
        }

        global $post;

        if (
            !$post ||
            (
                strpos($post->post_content, 'nguk_') === false &&
                intval(get_option(self::HOME_OPTION)) !== intval($post->ID) &&
                intval(get_option(self::CONTACT_OPTION)) !== intval($post->ID)
            )
        ) {
            return;
        }

        wp_register_style('nguk-public-website', false, array(), self::VERSION);
        wp_enqueue_style('nguk-public-website');
        wp_add_inline_style('nguk-public-website', self::styles());

        wp_register_script('nguk-public-website', false, array(), self::VERSION, true);
        wp_enqueue_script('nguk-public-website');
        wp_add_inline_script('nguk-public-website', self::scripts());
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

    private static function styles() {
        return <<<'CSS'
.nguk-public-home,.nguk-public-contact{font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f5f7fb}
.nguk-public-home *,.nguk-public-contact *{box-sizing:border-box}
body.nguk-public-page .site,
body.nguk-public-page #page,
body.nguk-public-page #content,
body.nguk-public-page .site-main,
body.nguk-public-page .content-area,
body.nguk-public-page .ast-primary-header-bar,
body.nguk-public-page .ast-container{margin-top:0!important}
body.nguk-public-page main#wp--skip-link--target,
body.nguk-public-page .wp-site-blocks,
body.nguk-public-page .is-layout-constrained,
body.nguk-public-page .has-global-padding{margin-top:0!important}
body:has(.nguk-public-home) .site-content,
body:has(.nguk-public-contact) .site-content,
body.nguk-public-page .site-content,
body.nguk-public-page #primary,
body.nguk-public-page .site-main,
body.nguk-public-page main#wp--skip-link--target,
body.nguk-public-page .wp-site-blocks,
body.nguk-public-page .has-global-padding,
body:has(.nguk-public-home) .ast-container,
body:has(.nguk-public-contact) .ast-container,
body.nguk-public-page .ast-container,
body.nguk-public-page .ast-article-single,
body.nguk-public-page .entry,
body.nguk-public-page article.page,
body:has(.nguk-public-home) .entry-content,
body:has(.nguk-public-contact) .entry-content,
body.nguk-public-page .entry-content{max-width:none!important;width:100%!important;margin:0!important;padding:0!important}
body:has(.nguk-public-home) .entry-header,
body:has(.nguk-public-contact) .entry-header,
body.nguk-public-page .entry-header,
body.nguk-public-page .entry-title,
body.nguk-public-page .page-title,
body.nguk-public-page .wp-block-post-title,
body.nguk-public-page .ast-single-post-order{display:none!important}
.nguk-public-home,.nguk-public-contact{width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw);overflow:hidden}
.nguk-public-home{display:flex;flex-direction:column}
.nguk-public-home>*{order:10}
.nguk-public-home>.nguk-rate-banner{order:1}
.nguk-public-home>.nguk-rate-ticker{order:2}
.nguk-public-home>.nguk-public-hero{order:3}
.nguk-public-home>#live-rates{order:4}
.main-header-menu .nguk-menu-login>a,
.ast-builder-menu .nguk-menu-login>a,
.menu .nguk-menu-login>a{background:#0f766e!important;color:#fff!important;border-radius:8px;padding:11px 18px!important;font-weight:900!important;line-height:1!important}
.main-header-menu .nguk-menu-login>a:hover,
.ast-builder-menu .nguk-menu-login>a:hover,
.menu .nguk-menu-login>a:hover{background:#12372a!important;color:#fff!important}
.nguk-rate-banner{overflow:hidden;background:#10223f;color:#fff;padding:12px 0;margin:0}
.nguk-rate-banner__track{display:flex;gap:34px;white-space:nowrap;animation:ngukSlide 22s linear infinite;font-weight:900;font-size:18px}
.nguk-rate-banner__track>*{padding-left:28px}
.nguk-public-hero{min-height:560px;background:linear-gradient(rgba(5,31,45,.72),rgba(5,31,45,.58)),url("https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?auto=format&fit=crop&w=1800&q=80") center/cover;display:flex;align-items:center;padding:64px 7vw;color:#fff;margin:0}
.nguk-public-hero__content{max-width:860px}
.nguk-public-logo-img{max-height:82px;width:auto;object-fit:contain}
.nguk-public-logo-text{display:inline-block;font-size:30px;font-weight:900;color:#0f766e}
.nguk-public-eyebrow{font-weight:900;text-transform:uppercase;letter-spacing:0;color:#fbbf24;margin:18px 0 8px}
.nguk-public-hero h1,.nguk-public-contact-hero h1{font-size:clamp(38px,6vw,78px);line-height:1.02;margin:0 0 18px;font-weight:900;color:inherit}
.nguk-public-subtitle,.nguk-public-hero p{font-size:20px;line-height:1.6;max-width:700px}
.nguk-public-actions{display:flex;gap:14px;flex-wrap:wrap;margin-top:26px}
.nguk-public-button{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:900}
.nguk-public-button--primary{background:#0f766e;color:#fff}
.nguk-public-button--secondary{background:#fff;color:#0f172a}
.nguk-public-button--dark{background:#111827;color:#fff}
.nguk-public-section{padding:70px 7vw;background:#fff}
.nguk-public-section:nth-of-type(even){background:#f8fafc}
.nguk-public-section-heading{max-width:780px;margin-bottom:28px}
.nguk-public-section-heading p{margin:0 0 8px;color:#0f766e;text-transform:uppercase;font-weight:900}
.nguk-public-section-heading h2{margin:0;font-size:clamp(28px,4vw,46px);font-weight:900;color:#0f172a}
.nguk-rate-grid,.nguk-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.nguk-info-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.nguk-info-grid--three{grid-template-columns:repeat(3,minmax(0,1fr))}
.nguk-rate-card,.nguk-info-grid article,.nguk-contact-card{background:#fff;border:1px solid #dbe4ee;border-radius:8px;padding:26px;box-shadow:0 12px 30px rgba(15,23,42,.08)}
.nguk-rate-card span,.nguk-info-grid span{display:block;color:#64748b;text-transform:uppercase;font-weight:900;margin-bottom:10px}
.nguk-rate-card strong{font-size:36px;font-weight:900;color:#020617}
.nguk-info-grid h3{margin:0 0 8px;font-size:22px;font-weight:900;color:#0f172a}
.nguk-info-grid p{margin:0;line-height:1.6;color:#334155;font-weight:700}
.nguk-rate-ticker{overflow:hidden;background:#0f766e;color:#fff;padding:13px 0;margin:0}
.nguk-rate-ticker__inner{display:flex;width:max-content;animation:ngukTicker 26s linear infinite}
.nguk-rate-ticker span{padding-right:60px;font-weight:900;white-space:nowrap}
.nguk-calculator{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;background:#10223f;color:#fff;border-radius:8px;padding:24px}
.nguk-calculator label,.nguk-contact-form label{display:flex;flex-direction:column;gap:7px;font-weight:900}
.nguk-calculator input,.nguk-calculator select,.nguk-contact-form input,.nguk-contact-form textarea{width:100%;min-height:46px;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font:inherit}
.nguk-calculator__result{grid-column:1/-1;background:#fff;color:#0f172a;border-radius:8px;padding:18px;font-size:22px;font-weight:900}
.nguk-public-cta{text-align:center;background:#12372a;color:#fff;padding:70px 7vw}
.nguk-public-cta h2{font-size:42px;margin:0 0 10px;color:#fff;font-weight:900}
.nguk-public-cta p{font-size:18px;margin:0 0 24px;color:#e5e7eb}
.nguk-contact-form{display:grid;gap:16px}
.nguk-contact-form button{border:0;background:#0f766e;color:#fff;border-radius:8px;min-height:48px;font-weight:900;cursor:pointer}
.nguk-contact-message{border-radius:8px;padding:14px 16px;margin-bottom:16px;font-weight:900}
.nguk-contact-message--success{background:#dcfce7;color:#14532d}
.nguk-contact-message--error{background:#fee2e2;color:#991b1b}
.nguk-public-contact-hero{background:#12372a;color:#fff;padding:90px 7vw}
.nguk-public-contact-hero p{font-size:18px;max-width:720px;color:#e5e7eb}
.nguk-public-footer{background:#0f172a;color:#e5e7eb;padding:34px 7vw;text-align:center;font-weight:800}
@keyframes ngukSlide{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@keyframes ngukTicker{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@media(max-width:900px){.nguk-rate-grid,.nguk-info-grid,.nguk-info-grid--three,.nguk-calculator{grid-template-columns:1fr}.nguk-public-hero{min-height:auto;padding:54px 24px}.nguk-public-section,.nguk-public-cta,.nguk-public-contact-hero{padding:48px 22px}.nguk-rate-card strong{font-size:30px}.main-header-menu .nguk-menu-login>a,.ast-builder-menu .nguk-menu-login>a,.menu .nguk-menu-login>a{display:inline-flex!important;margin:8px 0!important}}
CSS;
    }

    private static function scripts() {
        return <<<'JS'
(function(){
    function bindCalculator(calculator){
        var nairaInput = calculator.querySelector('.nguk-calc-naira');
        var poundInput = calculator.querySelector('.nguk-calc-pound');
        var modeInput = calculator.querySelector('.nguk-calc-mode');
        var result = calculator.querySelector('.nguk-calculator__result');
        var buyRate = parseFloat(calculator.getAttribute('data-buy-rate')) || 0;
        var sellRate = parseFloat(calculator.getAttribute('data-sell-rate')) || 0;

        function activeRate(){
            return modeInput.value === 'sell' ? sellRate : buyRate;
        }

        function formatMoney(value, currency){
            return currency + ' ' + value.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        nairaInput.addEventListener('input', function(){
            var rate = activeRate();
            var naira = parseFloat(nairaInput.value) || 0;
            var pounds = rate > 0 ? naira / rate : 0;
            poundInput.value = pounds > 0 ? pounds.toFixed(2) : '';
            result.textContent = naira > 0 ? formatMoney(naira, 'NGN') + ' is approximately ' + formatMoney(pounds, 'GBP') : 'Enter an amount to calculate.';
        });

        poundInput.addEventListener('input', function(){
            var rate = activeRate();
            var pounds = parseFloat(poundInput.value) || 0;
            var naira = pounds * rate;
            nairaInput.value = naira > 0 ? naira.toFixed(2) : '';
            result.textContent = pounds > 0 ? formatMoney(pounds, 'GBP') + ' is approximately ' + formatMoney(naira, 'NGN') : 'Enter an amount to calculate.';
        });

        modeInput.addEventListener('change', function(){
            if(nairaInput.value){
                nairaInput.dispatchEvent(new Event('input'));
            } else if(poundInput.value){
                poundInput.dispatchEvent(new Event('input'));
            }
        });
    }

    document.querySelectorAll('.nguk-calculator').forEach(bindCalculator);
})();
JS;
    }

}
