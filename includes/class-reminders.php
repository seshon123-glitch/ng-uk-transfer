<?php

if (!defined('ABSPATH')) {
    exit;
}

class NGUK_Reminders {

    public static function init() {
        add_action('wp_ajax_nguk_save_reminder', array(__CLASS__, 'ajax_save'));
        add_action('wp_ajax_nguk_delete_reminder', array(__CLASS__, 'ajax_delete'));
        add_action('wp_ajax_nguk_complete_reminder', array(__CLASS__, 'ajax_complete'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'nguk_reminders';
    }

    public static function create_table($charset_collate = '') {
        global $wpdb;

        if (empty($charset_collate)) {
            $charset_collate = $wpdb->get_charset_collate();
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            direction varchar(10) NOT NULL DEFAULT 'nguk',
            title varchar(255) NOT NULL,
            description longtext NULL,
            amount decimal(12,2) NULL,
            person_name varchar(255) NULL,
            due_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'Pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY direction (direction),
            KEY due_date (due_date),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql);

        $direction_column = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'direction')
        );

        if (!$direction_column) {
            $wpdb->query("ALTER TABLE $table ADD direction varchar(10) NOT NULL DEFAULT 'nguk' AFTER user_id");
            $wpdb->query("ALTER TABLE $table ADD INDEX direction (direction)");
        }
    }

    private static function normalize_direction($direction) {
        return $direction === 'ukng' ? 'ukng' : 'nguk';
    }

    public static function active_count($direction = 'nguk') {
        global $wpdb;

        $table = self::table_name();
        $direction = self::normalize_direction($direction);

        return intval(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE user_id = %d AND direction = %s AND status IN ('Pending', 'Overdue')",
                    get_current_user_id(),
                    $direction
                )
            )
        );
    }

    public static function status_for_due_date($status, $due_date) {
        if ($status === 'Completed') {
            return 'Completed';
        }

        return $due_date < current_time('Y-m-d') ? 'Overdue' : 'Pending';
    }

    public static function visual_state($reminder) {
        if ($reminder->status === 'Completed') {
            return 'completed';
        }

        $today = current_time('Y-m-d');

        if ($reminder->due_date < $today || $reminder->status === 'Overdue') {
            return 'overdue';
        }

        $days_until_due = floor((strtotime($reminder->due_date) - strtotime($today)) / DAY_IN_SECONDS);
        return $days_until_due <= 7 ? 'due-soon' : 'pending';
    }

    public static function get_reminders($active_only = false, $direction = 'nguk') {
        global $wpdb;

        $table = self::table_name();
        $direction = self::normalize_direction($direction);

        if ($active_only) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table
                     WHERE user_id = %d
                       AND direction = %s
                       AND status IN ('Pending', 'Overdue')
                     ORDER BY due_date ASC, id DESC",
                    get_current_user_id(),
                    $direction
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE user_id = %d
                   AND direction = %s
                 ORDER BY FIELD(status, 'Pending', 'Overdue', 'Completed'), due_date ASC, id DESC",
                get_current_user_id(),
                $direction
            )
        );
    }

    public static function get_reminder($reminder_id, $direction = 'nguk') {
        global $wpdb;

        $table = self::table_name();
        $direction = self::normalize_direction($direction);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND user_id = %d AND direction = %s",
                $reminder_id,
                get_current_user_id(),
                $direction
            )
        );
    }

    public static function stats($direction = 'nguk') {
        $reminders = self::get_reminders(false, $direction);
        $stats = array('pending' => 0, 'overdue' => 0, 'next' => null);

        foreach ($reminders as $reminder) {
            $status = self::status_for_due_date($reminder->status, $reminder->due_date);

            if ($status === 'Completed') {
                continue;
            }

            if ($status === 'Overdue') {
                $stats['overdue']++;
            } else {
                $stats['pending']++;
            }

            if (!$stats['next'] || $reminder->due_date < $stats['next']->due_date) {
                $stats['next'] = $reminder;
            }
        }

        return $stats;
    }

    public static function render_badge($direction = 'nguk') {
        return 'Reminders <span class="nguk-reminder-badge">' . esc_html(self::active_count($direction)) . '</span>';
    }

    public static function render_ticker($view_param = 'nguk_view', $direction = 'nguk') {
        $direction = self::normalize_direction($direction);
        $reminders = self::get_reminders(true, $direction);

        if (empty($reminders)) {
            return;
        }

        $reminders_url = admin_url('admin.php?page=nguk-transfer&' . $view_param . '=reminders');
        ?>
        <div class="nguk-reminder-ticker" aria-label="Active reminders">
            <div class="nguk-reminder-track">
                <?php for ($loop = 0; $loop < 2; $loop++) { ?>
                    <?php foreach ($reminders as $reminder) { ?>
                        <a class="nguk-reminder-ticker-item <?php echo esc_attr(self::visual_state($reminder)); ?>"
                           href="<?php echo esc_url(add_query_arg('reminder_id', intval($reminder->id), $reminders_url)); ?>">
                            <span class="dashicons dashicons-bell"></span>
                            <strong>Reminder:</strong>
                            <?php echo esc_html(self::summary_text($reminder)); ?>
                        </a>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
        <?php
    }

    public static function render_overview_widget($card_class = 'nguk-rate-card', $direction = 'nguk') {
        $stats = self::stats($direction);
        $next = $stats['next'];
        ?>
        <div class="<?php echo esc_attr($card_class); ?> nguk-reminder-widget">
            <span>Reminder Dashboard</span>
            <strong><?php echo esc_html($stats['pending']); ?> Pending</strong>
            <p><b><?php echo esc_html($stats['overdue']); ?></b> overdue</p>
            <p>Next: <?php echo $next ? esc_html($next->title . ' - Due ' . self::format_date($next->due_date)) : 'No active reminders'; ?></p>
        </div>
        <?php
    }

    public static function render_panel($panel_class, $view_param = 'nguk_view', $direction = 'nguk') {
        $direction = self::normalize_direction($direction);
        $reminders = self::get_reminders(false, $direction);
        $selected = isset($_GET['reminder_id']) ? self::get_reminder(intval($_GET['reminder_id']), $direction) : null;
        ?>
        <div class="<?php echo esc_attr($panel_class); ?> nguk-reminders-panel" data-nguk-panel="reminders">
            <div class="nguk-reminders-head">
                <div>
                    <h2>Reminders</h2>
                    <p>Create payment, document, call-back, and follow-up reminders.</p>
                </div>
                <span class="nguk-reminder-count"><?php echo esc_html(self::active_count($direction)); ?> active</span>
            </div>

            <?php if ($selected) { ?>
                <div class="nguk-reminder-detail <?php echo esc_attr(self::visual_state($selected)); ?>">
                    <h3><?php echo esc_html($selected->title); ?></h3>
                    <p><?php echo nl2br(esc_html($selected->description)); ?></p>
                    <p>
                        <?php if ($selected->person_name) { ?><strong>Person:</strong> <?php echo esc_html($selected->person_name); ?> &nbsp; <?php } ?>
                        <?php if ($selected->amount !== null && $selected->amount !== '') { ?><strong>Amount:</strong> GBP <?php echo esc_html(number_format(floatval($selected->amount), 2)); ?> &nbsp; <?php } ?>
                        <strong>Due:</strong> <?php echo esc_html(self::format_date($selected->due_date)); ?>
                    </p>
                </div>
            <?php } ?>

            <form class="nguk-reminder-form" data-nguk-reminder-form>
                <input type="hidden" name="reminder_id" value="">
                <input type="hidden" name="direction" value="<?php echo esc_attr($direction); ?>">
                <div><label>Title</label><input type="text" name="title" required></div>
                <div><label>Person Name</label><input type="text" name="person_name"></div>
                <div><label>Amount</label><input type="number" step="0.01" min="0" name="amount"></div>
                <div><label>Due Date</label><input type="date" name="due_date" required></div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="Pending">Pending</option>
                        <option value="Completed">Completed</option>
                        <option value="Overdue">Overdue</option>
                    </select>
                </div>
                <div class="nguk-reminder-form-wide">
                    <label>Description / Notes</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <div class="nguk-reminder-actions">
                    <button type="submit" class="button button-primary">Save Reminder</button>
                    <button type="button" class="button" data-nguk-reminder-reset>Clear</button>
                    <span class="nguk-reminder-message" aria-live="polite"></span>
                </div>
            </form>

            <table class="widefat striped nguk-reminders-table">
                <thead>
                    <tr>
                        <th>No.</th><th>Title</th><th>Person</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Created</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reminders) { ?>
                        <?php $count = 1; ?>
                        <?php foreach ($reminders as $reminder) { ?>
                            <?php $status = self::status_for_due_date($reminder->status, $reminder->due_date); ?>
                            <tr class="nguk-reminder-row <?php echo esc_attr(self::visual_state($reminder)); ?>"
                                data-reminder='<?php echo esc_attr(wp_json_encode(self::row_data($reminder))); ?>'>
                                <td><?php echo esc_html($count++); ?></td>
                                <td><strong><?php echo esc_html($reminder->title); ?></strong><br><small><?php echo esc_html(wp_trim_words($reminder->description, 12)); ?></small></td>
                                <td><?php echo esc_html($reminder->person_name); ?></td>
                                <td><?php echo $reminder->amount !== null && $reminder->amount !== '' ? 'GBP ' . esc_html(number_format(floatval($reminder->amount), 2)) : ''; ?></td>
                                <td><?php echo esc_html(self::format_date($reminder->due_date)); ?></td>
                                <td><span class="nguk-reminder-status"><?php echo esc_html($status); ?></span></td>
                                <td><?php echo esc_html(self::format_date($reminder->created_at)); ?></td>
                                <td>
                                    <button type="button" class="button" data-nguk-reminder-edit>Edit</button>
                                    <?php if ($status !== 'Completed') { ?>
                                        <button type="button" class="button" data-nguk-reminder-complete="<?php echo intval($reminder->id); ?>">Complete</button>
                                    <?php } ?>
                                    <button type="button" class="button nguk-danger-button" data-nguk-reminder-delete="<?php echo intval($reminder->id); ?>">Delete</button>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr><td colspan="8">No reminders found.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php
        self::render_assets($view_param);
    }

    private static function row_data($reminder) {
        return array(
            'id' => intval($reminder->id),
            'title' => $reminder->title,
            'description' => $reminder->description,
            'amount' => $reminder->amount,
            'person_name' => $reminder->person_name,
            'due_date' => $reminder->due_date,
            'status' => self::status_for_due_date($reminder->status, $reminder->due_date)
        );
    }

    private static function summary_text($reminder) {
        $parts = array();

        if (!empty($reminder->person_name)) {
            $parts[] = $reminder->person_name;
        }

        if (!empty($reminder->amount)) {
            $parts[] = 'GBP ' . number_format(floatval($reminder->amount), 2);
        }

        $prefix = !empty($parts) ? implode(' - ', $parts) : $reminder->title;
        return $prefix . ' - Due ' . self::format_date($reminder->due_date);
    }

    private static function format_date($date) {
        return empty($date) ? '' : date_i18n('d M Y', strtotime($date));
    }

    private static function verify_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to manage reminders.'), 403);
        }

        check_ajax_referer('nguk_reminders', 'nonce');
    }

    public static function ajax_save() {
        self::verify_ajax();

        global $wpdb;

        $table = self::table_name();
        $reminder_id = isset($_POST['reminder_id']) ? intval($_POST['reminder_id']) : 0;
        $due_date = isset($_POST['due_date']) ? sanitize_text_field(wp_unslash($_POST['due_date'])) : '';
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'Pending';
        $direction = isset($_POST['direction']) ? self::normalize_direction(sanitize_text_field(wp_unslash($_POST['direction']))) : 'nguk';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            wp_send_json_error(array('message' => 'Please enter a valid due date.'), 400);
        }

        $status = in_array($status, array('Pending', 'Completed', 'Overdue'), true) ? $status : 'Pending';
        $status = self::status_for_due_date($status, $due_date);

        $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? floatval($_POST['amount']) : null;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $person_name = isset($_POST['person_name']) ? sanitize_text_field(wp_unslash($_POST['person_name'])) : '';

        if ($title === '') {
            wp_send_json_error(array('message' => 'Please enter a reminder title.'), 400);
        }

        $data = array(
            'user_id' => get_current_user_id(),
            'direction' => $direction,
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'person_name' => $person_name,
            'due_date' => $due_date,
            'status' => $status,
            'updated_at' => current_time('mysql')
        );

        if ($reminder_id > 0) {
            $wpdb->update(
                $table,
                $data,
                array('id' => $reminder_id, 'user_id' => get_current_user_id(), 'direction' => $direction)
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }

        wp_send_json_success(array('message' => 'Reminder saved.'));
    }

    public static function ajax_delete() {
        self::verify_ajax();

        global $wpdb;

        $wpdb->delete(
            self::table_name(),
            array('id' => intval($_POST['reminder_id']), 'user_id' => get_current_user_id())
        );

        wp_send_json_success(array('message' => 'Reminder deleted.'));
    }

    public static function ajax_complete() {
        self::verify_ajax();

        global $wpdb;

        $wpdb->update(
            self::table_name(),
            array('status' => 'Completed', 'updated_at' => current_time('mysql')),
            array('id' => intval($_POST['reminder_id']), 'user_id' => get_current_user_id())
        );

        wp_send_json_success(array('message' => 'Reminder completed.'));
    }

    public static function render_assets($view_param = 'nguk_view') {
        static $rendered = false;

        if ($rendered) {
            return;
        }

        $rendered = true;
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nguk_reminders'),
            'redirectUrl' => admin_url('admin.php?page=nguk-transfer&' . $view_param . '=reminders')
        );
        ?>
        <style>
            .nguk-reminder-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;margin-left:6px;padding:0 7px;border-radius:999px;background:#dc2626;color:#fff;font-size:12px;font-weight:900}
            .nguk-reminder-ticker{margin:16px 0 0;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;overflow:hidden;box-shadow:0 8px 22px rgba(15,23,42,.06)}
            .nguk-reminder-track{display:flex;width:max-content;animation:ngukReminderTicker 28s linear infinite}
            .nguk-reminder-ticker:hover .nguk-reminder-track{animation-play-state:paused}
            .nguk-reminder-ticker-item{display:flex;align-items:center;gap:7px;min-width:420px;padding:13px 18px;color:#111827;text-decoration:none;font-weight:800}
            .nguk-reminder-ticker-item.overdue{background:#fee2e2;color:#991b1b}
            .nguk-reminder-ticker-item.due-soon{background:#fef3c7;color:#92400e}
            .nguk-reminder-ticker-item.completed{background:#dcfce7;color:#166534}
            @keyframes ngukReminderTicker{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
            .nguk-reminder-widget p{margin:8px 0 0;font-weight:700;color:#475569}
            .nguk-reminders-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}
            .nguk-reminders-head h2,.nguk-reminders-head p{margin:0}
            .nguk-reminder-count{background:#0f766e;color:#fff;border-radius:999px;padding:8px 12px;font-weight:900}
            .nguk-reminder-form{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:20px}
            .nguk-reminder-form label{display:block;font-weight:800;margin-bottom:6px;color:#334155}
            .nguk-reminder-form input,.nguk-reminder-form select,.nguk-reminder-form textarea{width:100%}
            .nguk-reminder-form-wide{grid-column:1/-1}
            .nguk-reminder-actions{grid-column:1/-1;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .nguk-reminder-message{font-weight:800}
            .nguk-reminder-detail{border-left:5px solid #f59e0b;background:#fffbeb;padding:14px 16px;border-radius:10px;margin-bottom:16px}
            .nguk-reminder-detail.overdue{border-color:#dc2626;background:#fef2f2}
            .nguk-reminder-detail.completed{border-color:#16a34a;background:#f0fdf4}
            .nguk-reminders-table td,.nguk-reminders-table th{vertical-align:top;font-weight:700}
            .nguk-reminder-row.completed td{background:#f0fdf4}
            .nguk-reminder-row.due-soon td{background:#fffbeb}
            .nguk-reminder-row.overdue td{background:#fef2f2}
            .nguk-reminder-status{display:inline-flex;border-radius:999px;padding:5px 9px;background:#e2e8f0;font-weight:900}
            .nguk-reminder-row.completed .nguk-reminder-status{background:#bbf7d0;color:#166534}
            .nguk-reminder-row.due-soon .nguk-reminder-status{background:#fde68a;color:#92400e}
            .nguk-reminder-row.overdue .nguk-reminder-status{background:#fecaca;color:#991b1b}
            .nguk-danger-button{background:#dc2626!important;border-color:#dc2626!important;color:#fff!important}
            @media(max-width:900px){.nguk-reminder-form{grid-template-columns:1fr}.nguk-reminder-ticker-item{min-width:320px}.nguk-reminders-head{display:block}.nguk-reminders-table{display:block;overflow-x:auto}}
        </style>
        <script>
        window.ngukReminders = <?php echo wp_json_encode($config); ?>;
        jQuery(function($){
            function message(form, text, isError){
                form.find('.nguk-reminder-message').text(text).css('color', isError ? '#b32d2e' : '#15803d');
            }
            $(document).on('submit', '[data-nguk-reminder-form]', function(event){
                event.preventDefault();
                var form = $(this);
                var data = form.serializeArray();
                data.push({name:'action', value:'nguk_save_reminder'});
                data.push({name:'nonce', value:window.ngukReminders.nonce});
                message(form, 'Saving...', false);
                $.post(window.ngukReminders.ajaxUrl, data).done(function(){
                    window.location.href = window.ngukReminders.redirectUrl;
                }).fail(function(xhr){
                    var text = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Reminder could not be saved.';
                    message(form, text, true);
                });
            });
            $(document).on('click', '[data-nguk-reminder-edit]', function(){
                var row = $(this).closest('tr');
                var data = row.data('reminder');
                var form = $('[data-nguk-reminder-form]').first();
                form.find('[name="reminder_id"]').val(data.id);
                form.find('[name="title"]').val(data.title);
                form.find('[name="description"]').val(data.description);
                form.find('[name="amount"]').val(data.amount);
                form.find('[name="person_name"]').val(data.person_name);
                form.find('[name="due_date"]').val(data.due_date);
                form.find('[name="status"]').val(data.status);
                form[0].scrollIntoView({behavior:'smooth', block:'start'});
            });
            $(document).on('click', '[data-nguk-reminder-reset]', function(){
                var form = $(this).closest('form');
                form[0].reset();
                form.find('[name="reminder_id"]').val('');
                message(form, '', false);
            });
            function reminderAction(action, id){
                return $.post(window.ngukReminders.ajaxUrl, {
                    action: action,
                    nonce: window.ngukReminders.nonce,
                    reminder_id: id
                }).done(function(){
                    window.location.href = window.ngukReminders.redirectUrl;
                });
            }
            $(document).on('click', '[data-nguk-reminder-complete]', function(){
                reminderAction('nguk_complete_reminder', $(this).data('nguk-reminder-complete'));
            });
            $(document).on('click', '[data-nguk-reminder-delete]', function(){
                if(window.confirm('Delete this reminder?')){
                    reminderAction('nguk_delete_reminder', $(this).data('nguk-reminder-delete'));
                }
            });
        });
        </script>
        <?php
    }
}

NGUK_Reminders::init();
