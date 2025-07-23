<?php
/**
 * Plugin Name: Notice SMS Notifications
 * Description: Trimite SMS-uri automat la schimbarea statusului unei comenzi WooCommerce folosind template-uri Notice.ro.
 * Version:     3.4
 * Author:      Constantin
 */

if (! defined('ABSPATH')) {
    exit;
}

global $wpdb;
$SAWP_LOG_TABLE = $wpdb->prefix . 'sawp_sms_logs';

register_activation_hook(__FILE__, 'sawp_create_log_table');
/**
 * Creează tabela pentru log SMS-uri la activarea plugin-ului
 */
function sawp_create_log_table()
{
    global $wpdb, $SAWP_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$SAWP_LOG_TABLE} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        date_sent DATETIME NOT NULL,
        order_id VARCHAR(50) NULL,
        phone VARCHAR(20) NULL,
        template_id INT(11) NULL,
        status_code INT(11) NULL,
        response TEXT NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Initialize plugin
add_action('plugins_loaded', 'sawp_init');
function sawp_init()
{
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', 'sawp_notice_wc_missing');
        return;
    }
    add_action('admin_menu', 'sawp_admin_menu');
    add_action('admin_init', 'sawp_register_settings');
    add_action('admin_enqueue_scripts', 'sawp_enqueue_assets');
    add_action('woocommerce_order_status_changed', 'sawp_send_sms', 10, 4);
    add_action('admin_post_sawp_contact', 'sawp_handle_contact');
    add_action('admin_post_nopriv_sawp_contact', 'sawp_handle_contact');
    add_action('admin_post_sawp_refresh_templates', 'sawp_handle_refresh_templates');
    add_action('admin_post_sawp_export_logs', 'sawp_handle_export_logs');
}

/**
 * Admin notice when WooCommerce is missing
 */
function sawp_notice_wc_missing()
{
    echo '<div class="notice notice-error"><p><strong>Notice SMS Notifications</strong> necesită WooCommerce activ.</p></div>';
}

/**
 * Add plugin settings menu
 */
function sawp_admin_menu()
{
    add_menu_page(
        'Notice SMS Notifications',
        'Notice SMS Notifications',
        'manage_options',
        'sawp-settings',
        'sawp_render_settings_page',
        'dashicons-email-alt2',
        56
    );
}

/**
 * Register settings and fields
 */
function sawp_register_settings()
{
    register_setting('sawp_group', 'sawp_opts');
    add_settings_section('sawp_main', '', '__return_false', 'sawp-settings');

    add_settings_field('sawp_token', '<span class="dashicons dashicons-admin-network"></span> Token API', 'sawp_field_token', 'sawp-settings', 'sawp_main');
    add_settings_field('sawp_test', '<span class="dashicons dashicons-admin-site-alt3"></span> Test conexiune', 'sawp_field_test', 'sawp-settings', 'sawp_main');

    foreach (wc_get_order_statuses() as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        add_settings_field(
            "sawp_enable_{$slug}",
            '<span class="dashicons dashicons-yes-alt2"></span> Trimite SMS la ' . $label,
            'sawp_field_enable',
            'sawp-settings',
            'sawp_main',
            [ 'slug' => $slug ]
        );
        add_settings_field(
            "sawp_tpl_{$slug}",
            '',
            'sawp_field_tpl',
            'sawp-settings',
            'sawp_main',
            [ 'slug' => $slug, 'label' => $label ]
        );
    }
}

/**
 * Handler pentru butonul “Reîmprospătează template-uri”
 */
function sawp_handle_refresh_templates()
{
    if (! current_user_can('manage_options')) {
        wp_die('Nu ai permisiunea necesară.');
    }
    if (! isset($_GET['sawp_refresh_tpl_nonce'])
         || ! wp_verify_nonce($_GET['sawp_refresh_tpl_nonce'], 'sawp_refresh_tpl')) {
        wp_die('Nonce invalid.');
    }
    delete_transient('sawp_tpl_list');
    wp_redirect(add_query_arg(
        [ 'page' => 'sawp-settings', 'tpl_refreshed' => '1' ],
        admin_url('admin.php')
    ));
    exit;
}

/**
 * Handle export SMS logs
 */
function sawp_handle_export_logs()
{
    if (! current_user_can('manage_options')) {
        wp_die('Permisiune refuzată.');
    }
    if (! isset($_GET['sawp_export_logs_nonce'])
         || ! wp_verify_nonce($_GET['sawp_export_logs_nonce'], 'sawp_export_logs')) {
        wp_die('Nonce invalid.');
    }
    global $wpdb, $SAWP_LOG_TABLE;
    $logs = $wpdb->get_results("SELECT * FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC");
    if (! $logs) {
        wp_die('Nu există log-uri.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sms_logs_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, [ 'ID', 'Data trimitere', 'Order ID', 'Telefon', 'Template ID', 'Status Code', 'Răspuns API' ]);
    foreach ($logs as $row) {
        fputcsv($output, [ $row->id, $row->date_sent, $row->order_id, $row->phone, $row->template_id, $row->status_code, $row->response ]);
    }
    exit;
}

/**
 * Render settings page
 */
function sawp_render_settings_page()
{
    $opts = get_option('sawp_opts', []);
    $ok_contact = isset($_GET['sms_contact']) && 'success' === $_GET['sms_contact'];
    $ok_refresh = isset($_GET['tpl_refreshed']) && '1' === $_GET['tpl_refreshed'];

    // Count SMS sent today
    global $wpdb, $SAWP_LOG_TABLE;
    $today_start = gmdate('Y-m-d') . ' 00:00:00';
    $today_end   = gmdate('Y-m-d') . ' 23:59:59';
    $count_today = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$SAWP_LOG_TABLE} WHERE date_sent BETWEEN %s AND %s",
        $today_start,
        $today_end
    ));
    ?>
    <div class="wrap">
        <div class="sawp-header">
            <h1>SMS Auto Woo PRO</h1>
            <a href="https://notice.ro/" target="_blank">
                <img src="https://notice.ro/wp-content/uploads/2023/11/logo-color-icon.png" alt="Notice">
            </a>
        </div>
        <?php if ($ok_contact): ?>
            <div class="notice notice-success is-dismissible"><p>Mesaj trimis!</p></div>
        <?php endif; ?>
        <?php if ($ok_refresh): ?>
            <div class="notice notice-success is-dismissible"><p>Template‑urile au fost reîmprospătate cu succes.</p></div>
        <?php endif; ?>

        <div class="sawp-container">
            <div class="sawp-left">
                <h2><span class="dashicons dashicons-admin-settings"></span> Setări SMS</h2>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=sawp_refresh_templates'),
                        'sawp_refresh_tpl',
                        'sawp_refresh_tpl_nonce'
                    )); ?>" class="button button-secondary">
                        Reîmprospătează template‑uri
                    </a>
                </p>
                <form method="post" action="options.php">
                    <?php settings_fields('sawp_group'); ?>
                    <?php do_settings_sections('sawp-settings'); ?>
                    <?php submit_button('Salvează modificările', 'primary'); ?>
                </form>
            </div>
            <div class="sawp-right">
                <h2><span class="dashicons dashicons-email-alt2"></span> Contact Support</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sms_contact', 'sms_contact_nonce'); ?>
                    <input type="hidden" name="action" value="sawp_contact">
                    <table class="form-table">
                        <tr>
                            <th><label for="sms_name">Nume</label></th>
                            <td><input id="sms_name" name="sms_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="sms_email">Email</label></th>
                            <td><input id="sms_email" name="sms_email" type="email" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="sms_message">Mesaj</label></th>
                            <td><textarea id="sms_message" name="sms_message" class="large-text" rows="5" required></textarea></td>
                        </tr>
                    </table>
                    <?php submit_button('Trimite mesaj', 'primary'); ?>
                </form>

                <h2><span class="dashicons dashicons-analytics"></span> Status SMS-uri</h2>
                <p>SMS-uri trimise azi: <strong><?php echo intval($count_today); ?></strong></p>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin-post.php?action=sawp_export_logs'),
                        'sawp_export_logs',
                        'sawp_export_logs_nonce'
                    )); ?>" class="button button-secondary">
                        Exportă log-uri SMS-uri
                    </a>
                </p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Field: API token
 */
function sawp_field_token()
{
    $opts = get_option('sawp_opts', []);
    printf(
        '<input type="text" name="sawp_opts[token]" value="%s" style="width:100%%;padding:8px;border:1px solid #2196F3;border-radius:4px;background:#e8f4fd;">',
        esc_attr($opts['token'] ?? '')
    );
}

/**
 * Field: Connection test
 */
function sawp_field_test()
{
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    if (! $token) {
        echo '<span style="color:red;">Token nu este setat.</span>';
        return;
    }
    $resp = wp_remote_post(
        'https://api.notice.ro/api/v1/sms-out',
        [
            'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([ 'number'=>'0700000000','template_id'=>0,'variables'=>['order_id'=>'TEST'] ]),
        ]
    );
    if (is_wp_error($resp) || 200 !== wp_remote_retrieve_response_code($resp)) {
        echo '<span style="color:red;">❌ Eroare</span>';
    } else {
        echo '<span style="font-size:1.5rem;color:green;line-height: 50px;">✔️</span> Conexiune OK';
    }
}

/**
 * Field: Enable SMS
 */
function sawp_field_enable($args)
{
    $slug = $args['slug'];
    $opts = get_option('sawp_opts', []);
    $checked = ! empty($opts["enable_{$slug}"]) ? 'checked' : '';
    echo '<label class="sawp-switch"><input type="checkbox" name="sawp_opts[enable_'.$slug.']" data-slug="'.$slug.'" class="sawp-switch-in" '.$checked.'><span class="sawp-slider"></span></label>';
}

/**
 * Field: Template select
 */
function sawp_field_tpl($args)
{
    $slug = $args['slug'];
    $opts = get_option('sawp_opts', []);
    $sel  = $opts["tpl_{$slug}"] ?? '';
    $tpls = get_transient('sawp_tpl_list');
    if (false === $tpls && ($tk = trim($opts['token'] ?? ''))) {
        $res = wp_remote_get('https://api.notice.ro/api/v1/templates', [ 'headers'=>[ 'Authorization'=>'Bearer ' . $tk ] ]);
        if (! is_wp_error($res) && 200 === wp_remote_retrieve_response_code($res)) {
            $tpls = json_decode(wp_remote_retrieve_body($res), true);
            set_transient('sawp_tpl_list', $tpls, HOUR_IN_SECONDS);
        }
    }
    echo '<tr class="sawp-tpl-row" data-slug="'.$slug.'"><th></th><td>';
    echo '<select name="sawp_opts[tpl_'.$slug.']" class="sawp-tpl" data-slug="'.$slug.'" style="width:100%;padding:6px;border:1px solid #2196F3;border-radius:4px;">';
    echo '<option value="">Alege templetul</option>';
    if (is_array($tpls)) {
        foreach ($tpls as $tpl) {
            printf(
                '<option value="%1$s" data-text="%2$s" %3$s>[%1$s] %4$s</option>',
                esc_attr($tpl['id']),
                esc_attr($tpl['text'] ?? ''),
                selected($tpl['id'], $sel, false),
                esc_html($tpl['name'])
            );
        }
    }
    echo '</select> ';
    echo '<span class="sawp-tpl-icon" data-slug="'.$slug.'" style="font-size:1.5rem;vertical-align:middle;margin-left:8px;">'.(empty($sel)?'😞':'😊').'</span>';
    echo '<div id="sawp-preview-'.$slug.'" class="sawp-prev" style="margin-top:8px;padding:10px;background:#fff;border:1px solid #2196F3;border-radius:4px;">'.($sel?:'—').'</div>';
    echo '</td></tr>';
}

/**
 * Enqueue admin styles & scripts
 */
function sawp_enqueue_assets($hook)
{
    if ('toplevel_page_sawp-settings' !== $hook) {
        return;
    }
    wp_add_inline_style(
        'wp-admin',
        <<<'CSS'
    .sawp-header{border-top:4px solid #2196F3;padding:10px;display:flex;justify-content:space-between;align-items:center}
    .sawp-header img{height:50px}
    .sawp-container{display:grid;grid-template-columns:3fr 1fr;gap:20px;background:#f7f9fc;padding:20px;border:1px solid #2196F3;border-radius:8px}
    .sawp-left, .sawp-right{background:#fff;padding:16px;border-radius:3px}
    .form-table tr th{width:35%;padding:8px;vertical-align:middle}
    .form-table tr td{padding:8px}
    .sawp-switch{position:relative;display:inline-block;width:44px;height:24px}
    .sawp-switch-in{opacity:0;width:0;height:0}
    .sawp-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:34px;transition:.4s}
    .sawp-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.4s}
    .sawp-switch-in:checked+.sawp-slider{background:#2196F3}
    .sawp-switch-in:checked+.sawp-slider:before{transform:translateX(20px)}
    .sawp-tpl-row{display:none}
    .sawp-tpl{width:100%;padding:6px;border:1px solid #2196F3;border-radius:4px}
    .sawp-prev{margin-top:6px;padding:8px;background:#fff;border:1px solid #2196F3;border-radius:4px}
    .sawp-tpl-icon{font-size:1.5rem;vertical-align:middle;margin-left:8px}
    h2 .dashicons{vertical-align:middle;margin-right:8px;color:#2196F3}
    .button-primary{background:#8e44ad!important;border-color:#8e44ad!important;padding:14px 28px!important;font-size:1.3rem!important}
    CSS
    );
    wp_add_inline_script(
        'jquery-core',
        <<<'JS'
    jQuery(function($){
        $('input.sawp-switch-in').each(function(){
            var $cb = $(this), slug = $cb.data('slug');
            var row = $('.sawp-tpl-row[data-slug="'+slug+'"]');
            row.toggle($cb.is(':checked'));
            $cb.on('change', function(){ row.toggle($cb.is(':checked')); });
        });
        $('.sawp-tpl').on('change', function(){
            var slug = $(this).data('slug'), txt = $(this).find(':selected').data('text')||'—', icon = txt==='—'?'😞':'😊';
            $('#sawp-preview-'+slug).text(txt);
            $('.sawp-tpl-icon[data-slug="'+slug+'"]').text(icon);
        }).trigger('change');
    });
    JS
    );
}

/**
 * Send SMS on status change and log
 */
function sawp_send_sms($order_id, $old_status, $new_status, $order)
{
    $opts  = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    $tpl   = intval($opts['tpl_'.$new_status] ?? 0);
    $en    = ! empty($opts['enable_'.$new_status]);
    if (! $token || ! $tpl || ! $en) {
        return;
    }
    $phone = preg_replace('/\D+/', '', $order->get_billing_phone());
    if (!$phone) {
        return;
    }

    $args = [
        'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],
        'body'=>wp_json_encode([
            'number'=>$phone,
            'template_id'=>$tpl,
            'variables'=>[
                'order_id'=>$order->get_order_number(),
                'total'=>$order->get_total(),
                'awb'=>$order->get_meta('awb_cargus')?:$order->get_meta('_tracking_number'),
                'nume'=>$order->get_billing_first_name()
            ]
        ])
    ];
    $resp = wp_remote_post('https://api.notice.ro/api/v1/sms-out', $args);

    // log
    global $wpdb, $SAWP_LOG_TABLE;
    $status_code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
    $wpdb->insert(
        $SAWP_LOG_TABLE,
        [
            'date_sent'   => current_time('mysql', 1), // UTC
            'order_id'    => $order->get_order_number(),
            'phone'       => $phone,
            'template_id' => $tpl,
            'status_code' => $status_code,
            'response'    => wp_remote_retrieve_body($resp)
        ],
        ['%s','%s','%s','%d','%d','%s']
    );
}

/**
 * Handle contact form submission
 */
function sawp_handle_contact()
{
    if (!isset($_POST['sms_contact_nonce'])||!wp_verify_nonce($_POST['sms_contact_nonce'], 'sms_contact')) {
        wp_die('Nonce invalid');
    }
    $n=sanitize_text_field($_POST['sms_name']??'');
    $e=sanitize_email($_POST['sms_email']??'');
    $m=sanitize_textarea_field($_POST['sms_message']??'');
    $sent = wp_mail(
        'support@notice.ro',
        'Contact plugin '.$n,
        $m,
        [
          'From: '.$n.' <'.$e.'>',
          'Content-Type: text/plain; charset=UTF-8'
    ]
    );
    if (! $sent) {
        error_log('Eroare wp_mail: nu s-a putut trimite emailul către support@notice.ro');
    }

    wp_redirect(add_query_arg('sms_contact', 'success', wp_get_referer()));
    exit;
}
