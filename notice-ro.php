<?php
/**
 * Plugin Name: notice.ro
 * Description: Trimite SMS-uri automat la schimbarea statusului unei comenzi WooCommerce folosind template-uri Notice.ro.
 * Version:     4.3
 * Author:      Notice
 * Text Domain: notice-sms-connector
 */
if (!defined('ABSPATH')) { exit; }
global $wpdb;
 $SAWP_LOG_TABLE = $wpdb->prefix . 'sawp_sms_logs';

// Înregistrează statusul "Plată în așteptare" în WooCommerce
add_action('init', 'sawp_register_payment_pending_status');
function sawp_register_payment_pending_status() {
    register_post_status('wc-payment-pending', array(
        'label'                     => _x('Plată în așteptare', 'Order status', 'notice-sms-connector'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Plată în așteptare (%s)', 'Plată în așteptare (%s)', 'notice-sms-connector')
    ));
    
    // Înregistrează statusul "Plată cu cardul efectuată"
    register_post_status('wc-card-paid', array(
        'label'                     => _x('Plată cu cardul efectuată', 'Order status', 'notice-sms-connector'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Plată cu cardul efectuată (%s)', 'Plată cu cardul efectuată (%s)', 'notice-sms-connector')
    ));
}

// Adaugă statusurile la lista de statusuri disponibile
add_filter('wc_order_statuses', 'sawp_add_custom_statuses_to_list');
function sawp_add_custom_statuses_to_list($order_statuses) {
    $order_statuses['wc-payment-pending'] = _x('Plată în așteptare', 'Order status', 'notice-sms-connector');
    $order_statuses['wc-card-paid'] = _x('Plată cu cardul efectuată', 'Order status', 'notice-sms-connector');
    return $order_statuses;
}

add_action('wp_footer', function(){ if (function_exists('is_checkout') && is_checkout()) echo '<style>#sawp-otp-verify{width:100%!important;display:block!important}</style>'; });

add_action('wp_footer',function(){ if(function_exists('is_checkout')&&is_checkout()){ $o=get_option('sawp_otp_opts',[]); $s=get_option('sawp_opts',[]); $enabled=!empty($o['enabled'])&&!empty($o['template_id'])&&!empty($s['token']); $methods=(isset($o['methods'])&&is_array($o['methods']))?array_values($o['methods']):[]; echo '<script>!function($){var en='.($enabled?'true':'false').', ms='.( $methods?json_encode(array_values($methods)):'[]' ).';function tg(){var m=$(\'input[name=payment_method]:checked\').val()||"";var need=en&&(ms.length?ms.indexOf(m)!==-1:true);var sel="#place_order, .wc-block-components-checkout-place-order-button"; if(need){$(sel).hide();}else{$(sel).show().prop("disabled",false);} }$(document).on("change payment_method_selected updated_checkout",tg);$(tg);} (jQuery);</script>'; }});
/* ================== ACTIVARE ================== */
register_activation_hook(__FILE__, 'sawp_activate');
function sawp_activate() {
    // Resetează toate datele vechi la activare
    sawp_reset_all_data();
    
    // Creează tabela de log-uri
    sawp_create_log_table();
    
    // Setează opțiunea pentru a reseta transient-urile la prima rulare
    update_option('sawp_just_activated', true);
}
function sawp_reset_all_data() {
    // Șterge opțiunile
    delete_option('sawp_opts');
    
    // Șterge toate transient-urile
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    // Șterge tabela de log-uri dacă există
    global $wpdb;
    $table_name = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
function sawp_create_log_table() {
    global $wpdb, $SAWP_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$SAWP_LOG_TABLE} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        date_sent DATETIME NOT NULL,
        order_id VARCHAR(50) NULL,
        phone VARCHAR(20) NULL,
        template_id INT(11) NULL,
        template_name VARCHAR(255) NULL,
        status_code INT(11) NULL,
        response LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY date_sent (date_sent)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
/* ================== UTIL / TABELĂ ================== */
function sawp_table_exists() {
    global $wpdb, $SAWP_LOG_TABLE;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $SAWP_LOG_TABLE));
    return ($found === $SAWP_LOG_TABLE);
}
function sawp_maybe_create_table() { 
    if (!sawp_table_exists()) { 
        sawp_create_log_table(); 
    } 
}
function sawp_column_exists( $table, $column ) {
    global $wpdb;
    $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
    return ( $col === $column );
}
function sawp_maybe_upgrade_table() {
    global $wpdb, $SAWP_LOG_TABLE;
    sawp_maybe_create_table();
    if ( ! sawp_column_exists( $SAWP_LOG_TABLE, 'template_name' ) ) {
        $wpdb->query( "ALTER TABLE {$SAWP_LOG_TABLE} ADD COLUMN template_name VARCHAR(255) NULL AFTER template_id" ); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
/* ==== Helpers template-uri ==== */
function sawp_get_template_map() {
    $map = [];
    $tpls = get_transient('sawp_tpl_list');
    if ($tpls === false || !is_array($tpls)) {
        $opts = get_option('sawp_opts', []);
        $tk = trim($opts['token'] ?? '');
        if ($tk) {
            $res = wp_remote_get('https://api.notice.ro/api/v1/templates', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tk,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);
            if (!is_wp_error($res) && 200 === (int) wp_remote_retrieve_response_code($res)) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                
                // Debug log
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SAWP Templates Response: ' . print_r($body, true));
                }
                
                // Gestionare mai bună a structurii răspunsului
                if (isset($body['data']) && is_array($body['data'])) {
                    $tpls = $body['data'];
                } elseif (isset($body['templates']) && is_array($body['templates'])) {
                    $tpls = $body['templates'];
                } elseif (is_array($body)) {
                    $tpls = $body;
                } else {
                    $tpls = [];
                }
                set_transient('sawp_tpl_list', $tpls, 5 * MINUTE_IN_SECONDS);
            }
        }
    }
    if (is_array($tpls)) {
        foreach ($tpls as $tpl) {
            if (isset($tpl['id'])) {
                $map[(int)$tpl['id']] = $tpl['name'] ?? ($tpl['text'] ?? 'Template necunoscut');
            }
        }
    }
    return $map;
}
function sawp_resolve_template_name($template_id, $stored_name = '') {
    $template_id = (int) $template_id;
    if (!empty($stored_name)) { return $stored_name; }
    $map = sawp_get_template_map();
    if (isset($map[$template_id]) && $map[$template_id] !== '') {
        return $map[$template_id];
    }
    return $template_id ? '['.$template_id.']' : '—';
}
/* ================== DEZINSTALARE ================== */
register_uninstall_hook(__FILE__, 'sawp_uninstall');
function sawp_uninstall() {
    global $wpdb;
    delete_option('sawp_opts');
    $table = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
    foreach ($_COOKIE as $name => $value) {
        if (strpos($name, 'sawp_') === 0) {
            setcookie($name, '', time() - 3600, defined('COOKIEPATH') ? COOKIEPATH : '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
        }
    }
}
/* ================== INIT ================== */
add_action('plugins_loaded', 'sawp_init');
function sawp_init() {
    // Resetează transient-urile la activare
    if (get_option('sawp_just_activated')) {
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        delete_transient('sawp_received_sms');
        delete_option('sawp_just_activated');
    }
    
    sawp_maybe_create_table();
    sawp_maybe_upgrade_table();
    if (!class_exists('WooCommerce')) { 
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
    add_action('admin_post_sawp_fetch_received', 'sawp_handle_fetch_received');
    
    // Adaugă handler pentru resetare
    add_action('wp_ajax_sawp_reset_plugin', 'sawp_reset_plugin_handler');
    add_action('wp_ajax_sawp_force_refresh', 'sawp_force_refresh_handler');
    add_action('wp_ajax_sawp_reset_token_data', 'sawp_reset_token_data_handler');
    
    // Adaugă verificare la schimbarea token-ului
    add_action('update_option_sawp_opts', 'sawp_check_token_change', 10, 2);
    
    // Adaugă acțiunea pentru detectarea AWB-ului
    add_action('updated_post_meta', 'sawp_detect_awb_from_colete_online', 10, 4);
}
function sawp_notice_wc_missing() {
    echo '<div class="notice notice-error"><p><strong>notice.ro</strong> necesită WooCommerce activ.</p></div>';
}
/* ================== MENIU ADMIN ================== */
function sawp_admin_menu() {
    add_menu_page('notice.ro','notice.ro','manage_options','sawp-settings','sawp_render_settings_page','dashicons-email-alt2',56);
    add_submenu_page('sawp-settings','Confirmări comenzi','Confirmări comenzi','manage_options','sawp-confirmari','sawp_render_confirmations_page');
    add_submenu_page('sawp-settings','SMS-uri primite','SMS-uri primite','manage_options','sawp-received','sawp_render_received_page');
     /* --- Nou: Setări OTP --- */
    add_submenu_page('sawp-settings','Setări OTP','Setări OTP','manage_options','sawp-otp','sawp_render_otp_page');
}

/* ================== SETĂRI ================== */
function sawp_register_settings() {
    register_setting('sawp_group', 'sawp_opts', 'sawp_sanitize_options');
    add_settings_section('sawp_main', '', '__return_false', 'sawp-settings');
    add_settings_field('sawp_token','<span class="dashicons dashicons-admin-network"></span> Token API','sawp_field_token','sawp-settings','sawp_main');
    add_settings_field('sawp_test','<span class="dashicons dashicons-admin-site-alt3"></span> Test conexiune','sawp_field_test','sawp-settings','sawp_main');
    
    // Statusuri fără „Ciornă”
    $statuses_raw = wc_get_order_statuses();
    $statuses = [];
    foreach ($statuses_raw as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        $skip_slugs = ['pending','draft','ciorna','cart-abandoned','abandoned','payment-pending'];
        $skip_by_label = in_array(mb_strtolower(trim($label)), ['ciornă','ciorna'], true);
        if (in_array($slug, $skip_slugs, true) || $skip_by_label) { continue; }
        if (isset($statuses[$key])) { continue; }
        $statuses[$key] = $label;
    }
    
    foreach ($statuses as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        add_settings_field(
            "sawp_status_{$slug}",
            '<span class="dashicons dashicons-yes"></span> ' . sprintf('Trimite SMS la %s', esc_html($label)),
            'sawp_field_status_row',
            'sawp-settings',
            'sawp_main',
            ['slug' => $slug, 'label' => $label, 'is_pending' => false]
        );
    }
    
    // „Ciornă” (pending)
    $slug = 'pending';
    add_settings_field(
        "sawp_status_{$slug}",
        '<span class="dashicons dashicons-yes"></span> Trimite SMS la Ciornă',
        'sawp_field_status_row',
        'sawp-settings',
        'sawp_main',
        ['slug' => $slug, 'label' => 'Ciornă', 'is_pending' => true]
    );
    
    // „Plată în așteptare” (payment-pending)
    $slug = 'payment-pending';
    add_settings_field(
        "sawp_status_{$slug}",
        '<span class="dashicons dashicons-yes"></span> Trimite SMS la Plată în așteptare',
        'sawp_field_status_row',
        'sawp-settings',
        'sawp_main',
        ['slug' => $slug, 'label' => 'Plată în așteptare', 'is_payment_pending' => true]
    );
    
    // „Plată cu cardul efectuată” (card-paid)
    $slug = 'card-paid';
    add_settings_field(
        "sawp_status_{$slug}",
        '<span class="dashicons dashicons-yes"></span> Trimite SMS la Plată cu cardul efectuată',
        'sawp_field_status_row',
        'sawp-settings',
        'sawp_main',
        ['slug' => $slug, 'label' => 'Plată cu cardul efectuată', 'is_card_paid' => true]
    );
}
// Funcție de sanitizare a opțiunilor
function sawp_sanitize_options($input) {
    $output = [];
    
    // Sanitizează token-ul
    if (isset($input['token'])) {
        $output['token'] = sanitize_text_field($input['token']);
    }
    
    // Sanitizează setările pentru fiecare status
    $statuses_raw = wc_get_order_statuses();
    $statuses = [];
    foreach ($statuses_raw as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        $skip_slugs = ['draft','ciorna','cart-abandoned','abandoned'];
        $skip_by_label = in_array(mb_strtolower(trim($label)), ['ciornă','ciorna'], true);
        if (!in_array($slug, $skip_slugs, true) && !$skip_by_label) {
            $statuses[] = $slug;
        }
    }
    $statuses[] = 'pending'; // Adăugăm pending separat
    $statuses[] = 'payment-pending'; // Adăugăm payment-pending separat
    $statuses[] = 'card-paid'; // Adăugăm card-paid separat
    
    foreach ($statuses as $slug) {
        // Enable checkbox
        if (isset($input["enable_{$slug}"])) {
            $output["enable_{$slug}"] = (bool) $input["enable_{$slug}"];
        } else {
            $output["enable_{$slug}"] = false;
        }
        
        // Template ID
        if (isset($input["tpl_{$slug}"])) {
            $output["tpl_{$slug}"] = absint($input["tpl_{$slug}"]);
        } else {
            $output["tpl_{$slug}"] = 0;
        }
    }
    
    return $output;
}
function sawp_field_status_row( $args ) {
    $slug       = isset($args['slug']) ? (string) $args['slug'] : '';
    $is_pending = ! empty( $args['is_pending'] );
    $is_payment_pending = ! empty( $args['is_payment_pending'] );
    $is_card_paid = ! empty( $args['is_card_paid'] );
    $opts    = get_option( 'sawp_opts', [] );
    $enabled = ! empty( $opts[ "enable_{$slug}" ] );
    $sel     = isset( $opts[ "tpl_{$slug}" ] ) ? (string) $opts[ "tpl_{$slug}" ] : '';
    
    // Template-urile
    $tpls = get_transient( 'sawp_tpl_list' );
    if ( false === $tpls ) {
        $tk = trim( $opts['token'] ?? '' );
        if ( $tk !== '' ) {
            $res = wp_remote_get(
                'https://api.notice.ro/api/v1/templates',
                [ 'headers' => [ 'Authorization' => 'Bearer ' . $tk ] ]
            );
            if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                $body = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( is_array( $body ) ) {
                    $tpls = isset( $body['data']) && is_array( $body['data']) ? $body['data'] : $body;
                    set_transient( 'sawp_tpl_list', $tpls, 5 * MINUTE_IN_SECONDS );
                }
            }
        }
    }
    if ( ! is_array( $tpls ) ) { $tpls = []; }
    
    // Textul de preview
    $preview_text = '';
    if ( $sel !== '' ) {
        foreach ( $tpls as $tpl ) {
            $tpl_id = isset( $tpl['id'] ) ? (string) $tpl['id'] : '';
            $name = isset( $tpl['name'] ) ? (string) $tpl['name'] : '';
            $text = isset( $tpl['text'] ) ? (string) $tpl['text'] : '';
            if ( $tpl_id === $sel ) {
                $preview_text = $text;
                break;
            }
        }
    }
    
    // Structura HTML completă pentru fiecare rând
    ?>
    <div class="sawp-field-container">
        <div class="sawp-toggle-row">
            <label class="sawp-switch">
                <input type="checkbox" class="sawp-switch-input" data-slug="<?php echo esc_attr($slug); ?>" name="sawp_opts[enable_<?php echo esc_attr($slug); ?>]" <?php checked($enabled, true); ?>>
                <span class="sawp-slider"></span>
            </label>
            <?php if ($is_pending): ?>
                <span class="sawp-pending-note">(Abandon coș)</span>
            <?php endif; ?>
            <?php if ($is_payment_pending): ?>
                <span class="sawp-pending-note">(Plată neconfirmată)</span>
            <?php endif; ?>
            <?php if ($is_card_paid): ?>
                <span class="sawp-pending-note">(Plată cu cardul)</span>
            <?php endif; ?>
        </div>
        
        <div class="sawp-template-container" id="tpl-container-<?php echo esc_attr($slug); ?>" style="<?php echo $enabled ? '' : 'display: none;'; ?>">
            <select name="sawp_opts[tpl_<?php echo esc_attr($slug); ?>]" class="sawp-template-select" data-slug="<?php echo esc_attr($slug); ?>">
                <option value=""><?php esc_html_e('Alege template-ul', 'notice-sms-connector'); ?></option>
                <?php foreach ($tpls as $tpl): ?>
                    <?php 
                    $id = isset($tpl['id']) ? (string) $tpl['id'] : '';
                    $name = isset($tpl['name']) ? (string) $tpl['name'] : '';
                    $text = isset($tpl['text']) ? (string) $tpl['text'] : '';
                    ?>
                    <option value="<?php echo esc_attr($id); ?>" data-text="<?php echo esc_attr($text); ?>" <?php selected($id, $sel); ?>>
                        [<?php echo esc_html($id); ?>] <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div class="sawp-preview" id="preview-<?php echo esc_attr($slug); ?>" style="<?php echo empty($preview_text) ? 'display: none;' : ''; ?>">
                <?php echo esc_html($preview_text); ?>
            </div>
        </div>
    </div>
    <?php
}
/* ================== HANDLERE ADMIN ================== */
function sawp_handle_refresh_templates() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_refresh_tpl_nonce']) || !wp_verify_nonce($_GET['sawp_refresh_tpl_nonce'], 'sawp_refresh_tpl')) { wp_die('Nonce invalid.'); }
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    wp_safe_redirect(add_query_arg(['page' => 'sawp-settings', 'tpl_refreshed' => '1'], admin_url('admin.php'))); exit;
}
function sawp_handle_export_logs() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_export_logs_nonce']) || !wp_verify_nonce($_GET['sawp_export_logs_nonce'], 'sawp_export_logs')) { wp_die('Nonce invalid.'); }
    global $wpdb, $SAWP_LOG_TABLE;
    $logs = $wpdb->get_results("SELECT * FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC"); // phpcs:ignore WordPress.DB.PreparedSQL
    if (!$logs) { wp_die('Nu există log-uri.'); }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    $filename = 'sms_logs_' . gmdate('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Data trimitere', 'Order ID', 'Telefon', 'Template ID', 'Nume Template', 'Status Code', 'Răspuns API']);
    foreach ($logs as $row) {
        fputcsv(
            $output,
            [
                $row->id,
                $row->date_sent,
                $row->order_id,
                $row->phone,
                $row->template_id,
                sawp_resolve_template_name($row->template_id, $row->template_name),
                $row->status_code,
                $row->response,
            ]
        );
    }
    fclose($output);
    exit;
}
function sawp_reset_plugin_handler() {
    check_ajax_referer('sawp_reset_plugin', 'nonce');
    
    // Șterge opțiunile
    delete_option('sawp_opts');
    
    // Șterge toate transient-urile
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    // Șterge tabela de log-uri
    global $wpdb;
    $table_name = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    wp_send_json_success();
}
function sawp_force_refresh_handler() {
    check_ajax_referer('sawp_force_refresh', 'nonce');
    
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    wp_die();
}
function sawp_reset_token_data_handler() {
    check_ajax_referer('sawp_reset_token', 'nonce');
    
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    wp_die();
}
function sawp_check_token_change($old_value, $new_value) {
    // Verifică dacă token-ul s-a schimbat
    if (isset($old_value['token']) && isset($new_value['token']) && 
        $old_value['token'] !== $new_value['token']) {
        // Resetează toate datele legate de token
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        delete_transient('sawp_received_sms');
        delete_transient('sawp_received_last_update');
    }
}
/* ================== PAGINA PRINCIPALĂ (SETĂRI) ================== */
function sawp_render_settings_page() {
    sawp_maybe_create_table();
    $opts = get_option('sawp_opts', []);
    $ok_contact = isset($_GET['sms_contact']) && 'success' === ($_GET['sms_contact']);
    $ok_refresh = isset($_GET['tpl_refreshed']) && '1' === ($_GET['tpl_refreshed']);
    global $wpdb, $SAWP_LOG_TABLE;
    
    // Corectare: Folosim date_i18n pentru a obține data curentă în fusul orar al site-ului
    $today = date_i18n('Y-m-d');
    
    // Corectare: Folosim funcția DATE din MySQL pentru a extrage doar data
    $count_today = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$SAWP_LOG_TABLE} WHERE DATE(date_sent) = %s",
        $today
    ));
    
    $token = trim($opts['token'] ?? '');
    $user_units = get_transient('sawp_user_units');
    
    // Fetch user units if not in cache
    if (false === $user_units && $token) {
        $response = wp_remote_get('https://api.notice.ro/api/v1/user/units', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ],
            'timeout' => 15
        ]);
        
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body)) {
                $user_units = $body;
                set_transient('sawp_user_units', $user_units, 5 * MINUTE_IN_SECONDS);
                
                // Debug log pentru a vedea structura răspunsului
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SAWP User Units Response: ' . print_r($body, true));
                }
            }
        }
    }
    
    // Calculăm creditele rămase
    $total_credits = 0;
    $remaining_credits = 0;
    $subscription_name = '';
    
    if (!empty($user_units)) {
        // Verificăm structura răspunsului
        if (isset($user_units['credits'])) {
            if (is_array($user_units['credits'])) {
                // Dacă credits este un array (posibil structură nouă)
                $total_credits = $user_units['credits']['total'] ?? 0;
                $remaining_credits = $user_units['credits']['remaining'] ?? $user_units['credits']['remaining_credits'] ?? 0;
            } else {
                // Dacă credits este un număr (structura veche)
                $total_credits = $user_units['credits'];
                // Încercăm să găsim creditele rămase în alte câmpuri
                $remaining_credits = $user_units['remaining_credits'] ?? $user_units['remaining'] ?? $user_units['credits_remaining'] ?? 0;
            }
        }
        
        // Preluăm informațiile despre abonament - căutăm în mai multe locuri posibile
        if (isset($user_units['subscription'])) {
            $subscription_name = $user_units['subscription']['name'] ?? 
                               $user_units['subscription']['plan_name'] ?? 
                               $user_units['subscription']['type'] ?? 
                               $user_units['plan'] ?? 
                               $user_units['plan_name'] ?? '';
        } else {
            // Căutăm direct în rădăcină
            $subscription_name = $user_units['plan_name'] ?? 
                               $user_units['subscription_type'] ?? 
                               $user_units['account_type'] ?? '';
        }
        
        // Dacă nu avem credite rămase, le calculăm pe baza SMS-urilor trimise
        if ($total_credits > 0 && $remaining_credits == 0) {
            $total_sent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$SAWP_LOG_TABLE} WHERE status_code = 200");
            $remaining_credits = max(0, $total_credits - $total_sent);
        }
    }
    ?>
    <div class="wrap">
        <h1 class="sawp-main-title">
            <span class="sawp-title-icon">📱</span>
            notice.ro
        </h1>
        <div class="sawp-title-line"></div>
        
        <?php if (!sawp_table_exists()) : ?>
            <div class="notice notice-error"><p>Atenție: tabela de log lipsește. Am încercat să o creăm automat.</p></div>
        <?php endif; ?>
        <?php if ($ok_contact) : ?><div class="notice notice-success is-dismissible"><p>Mesaj trimis!</p></div><?php endif; ?>
        <?php if ($ok_refresh) : ?><div class="notice notice-success is-dismissible"><p>Template‑urile au fost reîmprospătate cu succes.</p></div><?php endif; ?>
        
        <div class="sawp-dashboard">
            <div class="sawp-main-panel">
                <div class="sawp-panel-header">
                    <h2>Setări SMS</h2>
                    <div class="sawp-header-actions">
                        <button type="button" class="sawp-btn sawp-btn-primary" id="sawp-force-refresh">
                            <span class="dashicons dashicons-update-alt"></span> Reîmprospătează forțat
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($user_units)) : ?>
                    <div class="sawp-account-card">
                        <div class="sawp-card-header">
                            <div class="sawp-logo-container">
                                <img src="https://i.imgur.com/eyaTYdm.png" alt="Notice" class="sawp-logo">
                            </div>
                            <div class="sawp-social-icons">
                                <a href="https://www.facebook.com/profile.php?id=61576955305181" target="_blank" class="sawp-social-icon sawp-facebook">
                                    <svg class="e-font-icon-svg e-fab-facebook" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M504 256C504 119 393 8 256 8S8 119 8 256c0 123.78 90.69 226.38 209.25 245V327.69h-63V256h63v-54.64c0-62.15 37-96.48 93.67-96.48 27.14 0 55.52 4.84 55.52 4.84v61h-31.28c-30.8 0-40.41 19.12-40.41 38.73V256h68.78l-11 71.69h-57.78V501C413.31 482.38 504 379.78 504 256z"></path></svg>
                                </a>
                                <a href="https://www.instagram.com/notice.ro/" target="_blank" class="sawp-social-icon sawp-instagram">
                                    <svg class="e-font-icon-svg e-fab-instagram" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"></path></svg>
                                </a>
                                <a href="https://www.tiktok.com/@notice.ro" target="_blank" class="sawp-social-icon sawp-tiktok">
                                    <svg class="e-font-icon-svg e-fab-tiktok" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M448,209.91a210.06,210.06,0,0,1-122.77-39.25V349.38A162.55,162.55,0,1,1,185,188.31V278.2a74.62,74.62,0,1,0,52.23,71.18V0l88,0a121.18,121.18,0,0,0,1.86,22.17h0A122.18,122.18,0,0,0,381,102.39a121.43,121.43,0,0,0,67,20.14Z"></path></svg>
                                </a>
                                <a href="https://www.youtube.com/@notice.romania" target="_blank" class="sawp-social-icon sawp-youtube">
                                    <svg class="e-font-icon-svg e-fab-youtube" viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg"><path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"></path></svg>
                                </a>
                            </div>
                            <?php if ($subscription_name) : ?>
                                <div class="sawp-status-badge <?php echo strtolower($subscription_name) === 'trial' ? 'sawp-trial' : 'sawp-active'; ?>">
                                    <?php echo esc_html($subscription_name); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="sawp-card-content">
                            <div class="sawp-credits-info">
                                <div class="sawp-credits-label">Credite rămase</div>
                                <div class="sawp-credits-value">
                                    <span class="sawp-credits-remaining"><?php echo esc_html($remaining_credits); ?></span>
                                    <span class="sawp-credits-separator">/</span>
                                    <span class="sawp-credits-total"><?php echo esc_html($total_credits); ?></span>
                                </div>
                                 <div class="sawp-credits-bar">
                                    <div class="sawp-credits-progress" style="width: <?php echo esc_attr(($total_credits > 0) ? ($remaining_credits / $total_credits * 100) : 0); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="options.php" class="sawp-settings-form" id="sawp-settings-form">
                    <?php settings_fields('sawp_group'); do_settings_sections('sawp-settings'); ?>
                    <div class="sawp-form-actions">
                        <button type="submit" class="sawp-btn sawp-btn-primary sawp-btn-large" id="sawp-save-settings">
                            <span class="dashicons dashicons-saved"></span> Salvează modificările
                        </button>
                    </div>
                </form>
                
                <!-- Card de resetare -->
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Resetare Plugin</h3>
                    </div>
                    <div class="sawp-card-content">
                        <p>Resetează complet toate setările și datele pluginului. </p>
                        <button type="button" class="sawp-btn sawp-btn-error" id="sawp-reset-plugin">
                            <span class="dashicons dashicons-trash"></span> Resetare completă
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="sawp-sidebar">
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Contact Support</h3>
                    </div>
                    <div class="sawp-card-content">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('sms_contact','sms_contact_nonce'); ?>
                            <input type="hidden" name="action" value="sawp_contact">
                            <div class="sawp-form-group">
                                <label for="sms_name">Nume</label>
                                <input id="sms_name" name="sms_name" class="sawp-input" required>
                            </div>
                            <div class="sawp-form-group">
                                <label for="sms_email">Email</label>
                                <input id="sms_email" name="sms_email" type="email" class="sawp-input" required>
                            </div>
                            <div class="sawp-form-group">
                                <label for="sms_message">Mesaj</label>
                                <textarea id="sms_message" name="sms_message" class="sawp-textarea" rows="5" required></textarea>
                            </div>
                            <button type="submit" class="sawp-btn sawp-btn-primary sawp-btn-block">
                                Trimite mesaj
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Informații companie</h3>
                    </div>
                    <div class="sawp-card-content">
                        <div class="sawp-company-info">
                            <div class="sawp-company-name">BUSINESS INCEPTION SRL</div>
                            <div class="sawp-company-details">
                                <div><strong>Cod fiscal:</strong> 41455280</div>
                                <div><strong>Nr. Reg. Com.:</strong> J40/9917/2019</div>
                                <div><strong>Email:</strong> <a href="mailto:contact@notice.ro">contact@notice.ro</a></div>
                                <div><strong>Suport:</strong> <a href="tel:+40772203628">+40 772 203 628</a></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>SMS-uri Trimise</h3>
                        <div class="sawp-header-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_refresh_templates'),'sawp_refresh_tpl','sawp_refresh_tpl_nonce')); ?>" class="sawp-btn sawp-btn-primary sawp-btn-sm">
                                <span class="dashicons dashicons-update-alt"></span>
                            </a>
                        </div>
                    </div>
                    <div class="sawp-card-content">
                        <div class="sawp-stats-grid">
                            <div class="sawp-stat-item">
                                <div class="sawp-stat-value"><?php echo intval($count_today); ?></div>
                                <div class="sawp-stat-label">SMS-uri azi</div>
                            </div>
                            <div class="sawp-stat-item">
                                <?php 
                                $total_sms = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$SAWP_LOG_TABLE}");
                                ?>
                                <div class="sawp-stat-value"><?php echo $total_sms; ?></div>
                                <div class="sawp-stat-label">SMS-uri totale</div>
                            </div>
                        </div>
                        
                        <div class="sawp-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_export_logs'),'sawp_export_logs','sawp_export_logs_nonce')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-download"></span> Exportă log-uri
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-confirmari')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-list-view"></span> Toate comenzile
                            </a>
                        </div>
                        
                        <h4>Ultimele SMS-uri</h4>
                        <div class="sawp-recent-sms">
                            <?php
                            $recent = $wpdb->get_results("SELECT order_id, phone, template_id, template_name, status_code, date_sent FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC LIMIT 4"); // phpcs:ignore WordPress.DB.PreparedSQL
                            if ($recent) {
                                foreach ($recent as $r) {
                                    $ok   = (intval($r->status_code) === 200) ? 'Da' : 'Nu';
                                    $t_nm = sawp_resolve_template_name($r->template_id, $r->template_name);
                                    echo '<div class="sawp-sms-item">';
                                    echo '<div class="sawp-sms-status ' . ($ok === 'Da' ? 'sawp-success' : 'sawp-error') . '">' . esc_html($ok) . '</div>';
                                    echo '<div class="sawp-sms-details">';
                                    echo '<div class="sawp-sms-order">#' . esc_html($r->order_id) . '</div>';
                                    echo '<div class="sawp-sms-phone">' . esc_html($r->phone) . '</div>';
                                    echo '<div class="sawp-sms-template">' . esc_html($t_nm) . '</div>';
                                    echo '<div class="sawp-sms-date">' . esc_html(date('H:i', strtotime($r->date_sent))) . '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            } else { 
                                echo '<div class="sawp-no-data">Nu există SMS-uri</div>'; 
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>SMS-uri primite</h3>
                        <div class="sawp-header-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_fetch_received'),'sawp_fetch_received','sawp_fetch_received_nonce')); ?>" class="sawp-btn sawp-btn-primary sawp-btn-sm">
                                <span class="dashicons dashicons-update-alt"></span>
                            </a>
                        </div>
							
                    </div>
                    <div class="sawp-card-content">
                        <?php
                        $received = get_transient('sawp_received_sms');
                        $received_count = 0;
                        
                        if (is_array($received)) {
                            $received_count = count($received);
                        }
                        ?>
                        <div class="sawp-stats-grid">
                            <div class="sawp-stat-item">
                                <div class="sawp-stat-value"><?php echo esc_html($received_count); ?></div>
                                <div class="sawp-stat-label">SMS-uri primite</div>
                            </div>
                            <div class="sawp-stat-item">
                                <?php 
                                $unread_count = 0;
                                if (is_array($received)) {
                                    foreach ($received as $sms) {
                                        if (empty($sms['read_at'])) {
                                            $unread_count++;
                                        }
                                    }
                                }
                                ?>
                                <div class="sawp-stat-value"><?php echo esc_html($unread_count); ?></div>
                                <div class="sawp-stat-label">Necitite</div>
                            </div>
                        </div>
                        
                        <div class="sawp-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-received')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-email-alt"></span> Vezi toate
                            </a>
                        </div>
                        
                        <?php if ($received_count > 0) : ?>
                            <h4>Ultimele SMS-uri primite</h4>
                            <div class="sawp-received-sms">
                                <?php
                                $recent_received = array_slice($received, 0, 3);
                                foreach ($recent_received as $sms) :
                                    $from_raw = sawp_extract_phone_raw($sms);
                                    $message = sawp_extract_message($sms);
                                    ?>
                                    <div class="sawp-sms-item">
                                        <div class="sawp-sms-status sawp-info">
                                            <span class="dashicons dashicons-email-alt"></span>
                                        </div>
                                        <div class="sawp-sms-details">
                                            <div class="sawp-sms-phone"><?php echo esc_html($from_raw); ?></div>
                                            <div class="sawp-sms-message"><?php echo esc_html(substr($message, 0, 30)) . (strlen($message) > 30 ? '...' : ''); ?></div>
                                            <div class="sawp-sms-date"><?php echo esc_html(sawp_extract_datetime_display($sms)); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <a href="https://api.whatsapp.com/send/?phone=+40772203628&text=Sunt+pe+Notice+si+am+nevoie+de+ajutor+cu%3A%20&type=phone_number&app_absent=0"
           class="sawp-whatsapp-float" target="_blank" rel="noopener noreferrer">
            <span class="dashicons dashicons-whatsapp"></span>
        </a>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Funcție pentru afișare/ascundere template-uri
        function toggleTemplateContainer() {
            $('.sawp-switch-input').each(function() {
                var slug = $(this).data('slug');
                var isChecked = $(this).is(':checked');
                var container = $('#tpl-container-' + slug);
                
                if (isChecked) {
                    container.show();
                } else {
                    container.hide();
                }
            });
        }
        
        // Inițializare la încărcare pagină
        toggleTemplateContainer();
        
        // Eveniment la schimbarea switch-ului
        $(document).on('change', '.sawp-switch-input', function() {
            toggleTemplateContainer();
        });
        
        // Actualizează previzualizarea la schimbarea template-ului
        $(document).on('change', '.sawp-template-select', function() {
            var slug = $(this).data('slug');
            var selectedOption = $(this).find('option:selected');
            var previewText = selectedOption.data('text') || '';
            var preview = $('#preview-' + slug);
            
            if (previewText) {
                preview.text(previewText).show();
            } else {
                preview.hide();
            }
        });
        
        // Resetare plugin
        $('#sawp-reset-plugin').on('click', function() {
            if (confirm('Sigur doriți să resetați complet pluginul? Această acțiune este ireversibilă!')) {
                $(this).prop('disabled', true).text('Se resetează...');
                
                $.post(ajaxurl, {
                    action: 'sawp_reset_plugin',
                    nonce: '<?php echo wp_create_nonce('sawp_reset_plugin'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Pluginul a fost resetat cu succes!');
                        location.reload();
                    } else {
                        alert('A apărut o eroare la resetare.');
                    }
                });
            }
        });
        
        // Reîmprospătare forțată
        $('#sawp-force-refresh').on('click', function() {
            $(this).prop('disabled', true).find('.dashicons').addClass('spin');
            
            $.post(ajaxurl, {
                action: 'sawp_force_refresh',
                nonce: '<?php echo wp_create_nonce('sawp_force_refresh'); ?>'
            }, function(response) {
                location.reload();
            });
        });
        
        // Salvare setări cu feedback
        $('#sawp-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $('#sawp-save-settings');
            
            $submitBtn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Se salvează...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=sawp_save_settings&nonce=<?php echo wp_create_nonce('sawp_save_settings'); ?>',
                success: function(response) {
                    if (response.success) {
                        $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvat!');
                        setTimeout(function() {
                            $submitBtn.html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                        }, 2000);
                    } else {
                        alert('A apărut o eroare la salvare: ' + response.data);
                        $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                    }
                },
                error: function() {
                    alert('A apărut o eroare la salvare.');
                    $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                }
            });
        });
    });
    </script>
    <?php
}
// Handler pentru salvarea setărilor via AJAX
add_action('wp_ajax_sawp_save_settings', 'sawp_save_settings_handler');
function sawp_save_settings_handler() {
    check_ajax_referer('sawp_save_settings', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Nu aveți permisiunea necesară.');
    }
    
    // Procesează și salvează opțiunile
    if (isset($_POST['sawp_opts'])) {
        $sanitized_opts = sawp_sanitize_options($_POST['sawp_opts']);
        update_option('sawp_opts', $sanitized_opts);
        
        // Resetează transient-urile legate de template-uri
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        
        wp_send_json_success();
    }
    
    wp_send_json_error('Nu s-au primit datele pentru salvare.');
}
/* ================== CONFIRMĂRI COMENZI ================== */
function sawp_render_confirmations_page() {
    sawp_maybe_create_table();
    global $wpdb, $SAWP_LOG_TABLE;
    $logs = $wpdb->get_results( "SELECT order_id, template_id, template_name, status_code, date_sent FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL
    ?>
    <div class="wrap">
        <div class="sawp-page-header">
            <div class="sawp-page-title-container">
                <h1 class="sawp-page-title">
                    <span class="dashicons dashicons-list-view"></span>
                    Confirmări comenzi
                </h1>
            </div>
            <div class="sawp-page-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-settings')); ?>" class="sawp-btn sawp-btn-secondary">
                   <span class="dashicons dashicons-arrow-left-alt"></span>  Înapoi la setări
                </a>
            </div>
        </div>
        <div class="sawp-title-line"></div>
        
        <style>
        /* Stiluri specifice pentru pagina de confirmări comenzi */
        .sawp-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .sawp-page-title-container {
            flex: 1;
        }
        
        .sawp-page-actions {
            display: flex;
            gap: 12px;
        }
        
        .sawp-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #e5e7eb;
            margin-bottom: 24px;
        }
        
        .sawp-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f9fafb;
        }
        
        .sawp-card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sawp-card-content {
            padding: 20px;
        }
        
        .sawp-table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .sawp-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }
        
        .sawp-table th,
        .sawp-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sawp-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .sawp-table tr:hover {
            background: #f3f4f6;
        }
        
        .sawp-table tr:last-child td {
            border-bottom: none;
        }
        
        .sawp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-btn-primary {
            background: #6366f1;
            color: white;
            border: 1px solid #4f46e5;
        }
        
        .sawp-btn-primary:hover {
            background: #4f46e5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .sawp-btn-secondary {
            background: white;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .sawp-btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .sawp-badge.sawp-success {
            background: #10b981;
            color: white;
        }
        
        .sawp-badge.sawp-error {
            background: #ef4444;
            color: white;
        }
        
        .sawp-link {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }
        
        .sawp-link:hover {
            text-decoration: underline;
        }
        
        .sawp-no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sawp-header-actions {
            display: flex;
            gap: 8px;
        }
        
        .sawp-title-line {
            height: 3px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 2px;
            margin-bottom: 24px;
        }
			
		
			
        </style>
        
        <div class="sawp-card">
            <div class="sawp-card-header">
                <h3>Istoric SMS-uri trimise</h3>
                <div class="sawp-header-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_export_logs'),'sawp_export_logs','sawp_export_logs_nonce')); ?>" class="sawp-btn sawp-btn-primary">
                        <span class="dashicons dashicons-download"></span> Exportă log-uri
                    </a>
                </div>
            </div>
            <div class="sawp-card-content">
                <div class="sawp-table-container">
                    <table class="sawp-table">
                        <thead>
                            <tr>
                                <th>Nr. Comenzii</th>
                                <th>Template</th>
                                <th>Trimis SMS</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ( $logs ) {
                                foreach ( $logs as $row ) {
                                    $sent = ( intval( $row->status_code ) === 200 ) ? 'Da' : 'Nu';
                                    $t_nm = sawp_resolve_template_name($row->template_id, $row->template_name);
                                    
                                    echo '<tr>';
                                    echo '<td>#' . esc_html( $row->order_id ) . '</td>';
                                    echo '<td>' . esc_html($t_nm) . '</td>';
                                    echo '<td><span class="sawp-badge ' . ($sent === 'Da' ? 'sawp-success' : 'sawp-error') . '">' . esc_html( $sent ) . '</span></td>';
                                    echo '<td>' . esc_html( date_i18n('d M Y H:i', strtotime($row->date_sent)) ) . '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="4" class="sawp-no-data">Nu există confirmări.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
}
/* ================== SMS‑URI PRIMITE ================== */
function sawp_render_received_page() {
    $received = get_transient('sawp_received_sms');
    $last_update = get_transient('sawp_received_last_update');
    ?>
    <div class="wrap">
        <div class="sawp-page-header">
            <div class="sawp-page-title-container">
                <h1 class="sawp-page-title">
                    <span class="dashicons dashicons-email-alt"></span>
                    SMS-uri primite
                </h1>
            </div>
            <div class="sawp-page-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-settings')); ?>" class="sawp-btn sawp-btn-secondary">
                    <span class="dashicons dashicons-arrow-left-alt"></span> Înapoi la setări
                </a>
            </div>
        </div>
        <div class="sawp-title-line"></div>
        
        <style>
        /* Stiluri specifice pentru pagina de SMS-uri primite */
        .sawp-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .sawp-page-title-container {
            flex: 1;
        }
        
        .sawp-page-actions {
            display: flex;
            gap: 12px;
        }
        
        .sawp-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #e5e7eb;
            margin-bottom: 24px;
        }
        
        .sawp-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f9fafb;
        }
        
        .sawp-card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sawp-card-content {
            padding: 20px;
        }
        
        .sawp-table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
            margin-top: 16px;
        }
        
        .sawp-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }
        
        .sawp-table th,
        .sawp-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sawp-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .sawp-table tr:hover {
            background: #f3f4f6;
        }
        
        .sawp-table tr:last-child td {
            border-bottom: none;
        }
        
        .sawp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-btn-primary {
            background: #6366f1;
            color: white;
            border: 1px solid #4f46e5;
        }
        
        .sawp-btn-primary:hover {
            background: #4f46e5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .sawp-btn-secondary {
            background: white;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .sawp-btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .sawp-badge.sawp-success {
            background: #10b981;
            color: white;
        }
        
        .sawp-badge.sawp-error {
            background: #ef4444;
            color: white;
        }
        
        .sawp-link {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }
        
        .sawp-link:hover {
            text-decoration: underline;
        }
        
        .sawp-no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
			
		
        
        .sawp-header-actions {
            display: flex;
            gap: 8px;
        }
        
        .sawp-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .sawp-stat-item {
            text-align: center;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sawp-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #6366f1;
        }
        
        .sawp-stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .sawp-last-update {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 16px;
            text-align: right;
        }
        
        .sawp-title-line {
            height: 3px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 2px;
            margin-bottom: 24px;
        }
        </style>
        
        <div class="sawp-card">
            <div class="sawp-card-header">
                <h3>SMS-uri primite</h3>
                <div class="sawp-header-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_fetch_received'),'sawp_fetch_received','sawp_fetch_received_nonce')); ?>" class="sawp-btn sawp-btn-primary">
                        <span class="dashicons dashicons-update-alt"></span> Reîmprospătează
                    </a>
                </div>
				
				<div class="sawp-header-actions">
    <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>" class="sawp-btn sawp-btn-primary sawp-btn-sm">
        <span class="dashicons dashicons-cart"></span> Comenzi
    </a>
</div>

				
				
            </div>
            <div class="sawp-card-content">
                <?php if ($last_update) : ?>
                    <div class="sawp-last-update">Ultima actualizare: <?php echo esc_html(date_i18n('d M Y H:i:s', $last_update)); ?></div>
                <?php endif; ?>
                
                <?php if (is_wp_error($received)) : ?>
                    <div class="notice notice-error"><p>Eroare la preluarea SMS-urilor: <?php echo esc_html($received->get_error_message()); ?></p></div>
                <?php elseif (empty($received)) : ?>
                    <div class="sawp-no-data">Nu există SMS-uri primite.</div>
                <?php else : ?>
                    <div class="sawp-stats-grid">
                        <div class="sawp-stat-item">
                            <div class="sawp-stat-value"><?php echo count($received); ?></div>
                            <div class="sawp-stat-label">SMS-uri primite</div>
                        </div>
                        <div class="sawp-stat-item">
                            <?php 
                            $unread_count = 0;
                            foreach ($received as $sms) {
                                if (empty($sms['read_at'])) {
                                    $unread_count++;
                                }
                            }
                            ?>
                            <div class="sawp-stat-value"><?php echo esc_html($unread_count); ?></div>
                            <div class="sawp-stat-label">Necitite</div>
                        </div>
                    </div>
                    
                    <div class="sawp-table-container">
                        <table class="sawp-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Telefon</th>
                                    <th>Mesaj</th>
                                    <th>Comandă</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($received as $sms) :
                                    $created_disp = sawp_extract_datetime_display($sms);
                                    $from_raw     = sawp_extract_phone_raw($sms);
                                    $message      = sawp_extract_message($sms);
                                    $order_id     = $from_raw ? sawp_find_order_by_phone($from_raw) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $created_disp ? esc_html($created_disp) : '—'; ?></td>
                                        <td><?php echo $from_raw ? esc_html($from_raw) : '—'; ?></td>
                                        <td><?php echo $message !== '' ? esc_html($message) : '—'; ?></td>
                                        <td>
                                            <?php
                                            if ($order_id) {
                                                $order_url = admin_url("post.php?post={$order_id}&action=edit");
                                                echo '<a href="'.esc_url($order_url).'" class="sawp-link">#'.esc_html($order_id).'</a>';
                                            } else { echo '—'; }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
/* ================== FETCH SMS‑URI PRIMITE ================== */
function sawp_handle_fetch_received() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_fetch_received_nonce']) || !wp_verify_nonce($_GET['sawp_fetch_received_nonce'], 'sawp_fetch_received')) {
        wp_die('Nonce invalid.');
    }
    
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    if (!$token) { 
        wp_safe_redirect(add_query_arg(['page' => 'sawp-received', 'error' => '1'], admin_url('admin.php'))); 
        exit; 
    }
    
    $response = wp_remote_get('https://api.notice.ro/api/v1/sms-in', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        set_transient('sawp_received_sms', $response, 15 * MINUTE_IN_SECONDS);
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data = [];
        
        // Handle response structure according to API documentation
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) { 
                $data = $body['data']; 
            } elseif (isset($body[0])) { 
                $data = $body; 
            }
        }
        
        set_transient('sawp_received_sms', $data, 15 * MINUTE_IN_SECONDS);
        set_transient('sawp_received_last_update', time(), 15 * MINUTE_IN_SECONDS);
    }
    
    wp_safe_redirect(admin_url('admin.php?page=sawp-received')); 
    exit;
}
/* ================== CÂMPURI SETĂRI SIMPLE ================== */
function sawp_field_token() {
    $opts = get_option('sawp_opts', []);
    $token = $opts['token'] ?? '';
    
    echo '<div style="display: flex; gap: 10px; align-items: center;">';
    printf(
        '<input type="text" name="sawp_opts[token]" value="%s" class="sawp-input" placeholder="Introduceți token-ul API">',
        esc_attr($token)
    );
    
    if (!empty($token)) {
        echo '<button type="button" class="button button-secondary" id="sawp-reset-token">Resetează token</button>';
    }
    echo '</div>';
    
    // Adaugă script pentru resetare
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#sawp-reset-token').on('click', function() {
            if (confirm('Sigur doriți să resetați token-ul? Aceasta va șterge toate datele temporare.')) {
                $('input[name="sawp_opts[token]"]').val('');
                $.post(ajaxurl, {
                    action: 'sawp_reset_token_data',
                    nonce: '<?php echo wp_create_nonce('sawp_reset_token'); ?>'
                }, function() {
                    location.reload();
                });
            }
        });
    });
    </script>
    <?php
}
function sawp_field_test() {
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    
    if (!$token) { 
        echo '<span class="sawp-test-error">Token nu este setat.</span>'; 
        return; 
    }
    
    $res = wp_remote_post('https://api.notice.ro/api/v1/sms-out', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'number' => '0700000000',
            'template_id' => 0,
            'variables' => ['order_id' => 'TEST']
        ]),
        'timeout' => 15,
    ]);
    
    if (is_wp_error($res)) {
        echo '<span class="sawp-test-error">Eroare: ' . esc_html($res->get_error_message()) . '</span>';
    } else {
        $status_code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        
        if (200 === (int) $status_code) {
            echo '<span class="sawp-test-success">Conexiune OK</span>';
        } else {
            echo '<span class="sawp-test-error">Eroare (' . esc_html($status_code) . '): ' . esc_html($body) . '</span>';
        }
    }
}
/* ================== CSS/JS ================== */
function sawp_enqueue_assets($hook) {
    $allowed = ['toplevel_page_sawp-settings','sawp_page_sawp-confirmari','sawp_page_sawp-received'];
    if ( ! in_array($hook, $allowed, true) ) { return; }
    
    // Înregistrăm un fișier CSS specific pentru plugin
    wp_register_style('sawp-admin-styles', false);
    wp_enqueue_style('sawp-admin-styles');
    
    wp_enqueue_style('common');
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');
    
    $css = <<<CSS
/* Variabile CSS */
:root {
    --sawp-primary: #6366f1;
    --sawp-primary-dark: #4f46e5;
    --sawp-secondary: #8b5cf6;
    --sawp-success: #10b981;
    --sawp-error: #ef4444;
    --sawp-warning: #f59e0b;
    --sawp-info: #3b82f6;
    --sawp-gray-50: #f9fafb;
    --sawp-gray-100: #f3f4f6;
    --sawp-gray-200: #e5e7eb;
    --sawp-gray-300: #d1d5db;
    --sawp-gray-400: #9ca3af;
    --sawp-gray-500: #6b7280;
    --sawp-gray-600: #4b5563;
    --sawp-gray-700: #374151;
    --sawp-gray-800: #1f2937;
    --sawp-gray-900: #111827;
    --sawp-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --sawp-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --sawp-border-radius: 8px;
    --sawp-border-color: #e5e7eb;
}
/* Reset și stiluri de bază */
.sawp-dashboard {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-top: 24px;
}
.sawp-main-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 28px;
    font-weight: 700;
    color: var(--sawp-gray-800);
    margin-bottom: 16px;
}
.sawp-title-line {
    height: 3px;
    background: linear-gradient(90deg, var(--sawp-primary), var(--sawp-secondary));
    border-radius: 2px;
    margin-bottom: 24px;
}
.sawp-page-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    font-weight: 700;
    color: var(--sawp-gray-800);
    margin-bottom: 16px;
}
.sawp-page-title .dashicons {
    font-size: 28px;
    color: var(--sawp-primary);
}
.sawp-title-icon {
    font-size: 32px;
}
/* Card-uri */
.sawp-card {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    overflow: hidden;
    margin-bottom: 24px;
    border: 1px solid var(--sawp-border-color);
}
.sawp-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--sawp-border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: var(--sawp-gray-50);
}
.sawp-card-header h2,
.sawp-card-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--sawp-gray-800);
}
.sawp-card-content {
    padding: 20px;
}
/* Butoane */
.sawp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--sawp-border-radius);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.sawp-btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
.sawp-btn-primary {
    background: var(--sawp-primary);
    color: white;
    border: 1px solid var(--sawp-primary-dark);
}
.sawp-btn-primary:hover {
    background: var(--sawp-primary-dark);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.sawp-btn-secondary {
    background: white;
    color: var(--sawp-gray-700);
    border: 1px solid var(--sawp-border-color);
}
.sawp-btn-secondary:hover {
    background: var(--sawp-gray-50);
    border-color: var(--sawp-gray-300);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}
.sawp-btn-error {
    background: var(--sawp-error);
    color: white;
    border: 1px solid #dc2626;
}
.sawp-btn-error:hover {
    background: #dc2626;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.sawp-btn-large {
    padding: 12px 24px;
    font-size: 16px;
}
.sawp-btn-block {
    display: flex;
    width: 100%;
    justify-content: center;
}
/* Formulare */
.sawp-input,
.sawp-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    font-size: 14px;
    transition: border-color 0.2s ease;
    background-color: white;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
}
.sawp-input:focus,
.sawp-textarea:focus {
    outline: none;
    border-color: var(--sawp-primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.sawp-textarea {
    resize: vertical;
    min-height: 100px;
}
.sawp-form-group {
    margin-bottom: 16px;
}
.sawp-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: var(--sawp-gray-700);
}
.sawp-form-actions {
    margin-top: 24px;
    margin-bottom:20px;
    padding-top: 24px;
    border-top: 1px solid var(--sawp-border-color);
}
/* Panou principal */
.sawp-main-panel {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    padding: 24px;
    border: 1px solid var(--sawp-border-color);
}
.sawp-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--sawp-border-color);
}
.sawp-panel-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: var(--sawp-gray-800);
}
/* Card cont */
.sawp-account-card {
    background: linear-gradient(135deg, var(--sawp-primary) 0%, var(--sawp-secondary) 100%);
    color: white;
    margin-bottom: 24px;
    border: none;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
.sawp-account-card .sawp-card-header {
    border-bottom-color: rgba(255, 255, 255, 0.2);
    background-color: transparent;
}
.sawp-account-card .sawp-card-header h3 {
    color: white;
}
.sawp-logo-container {
    display: flex;
    align-items: center;
}
.sawp-logo {
    height: 60px;
    width: auto;
}
.sawp-social-icons {
    display: flex;
    gap: 12px;
}
.sawp-social-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    color: white;
    transition: all 0.2s ease;
}
.sawp-social-icon:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}
.sawp-social-icon svg {
    width: 16px;
    height: 16px;
    fill: currentColor;
}
.sawp-status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.sawp-status-badge.sawp-trial {
    background: rgba(255, 255, 255, 0.2);
}
.sawp-status-badge.sawp-active {
    background: var(--sawp-success);
}
.sawp-credits-info {
    margin-bottom: 16px;
}
.sawp-credits-label {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 8px;
}
.sawp-credits-value {
    font-size: 32px;
    font-weight: 700;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.sawp-credits-remaining {
    font-size: 48px;
}
.sawp-credits-separator {
    font-size: 24px;
    opacity: 0.7;
}
.sawp-credits-total {
    font-size: 24px;
    opacity: 0.7;
}
.sawp-credits-bar {
    height: 8px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    margin-top: 12px;
    overflow: hidden;
    position: relative;
}
.sawp-credits-progress {
    height: 100%;
    background: white;
    border-radius: 4px;
    transition: width 0.5s ease;
}
.sawp-credits-indicator {
    position: absolute;
    top: -10px;
    transform: translateX(-50%);
    transition: left 0.5s ease;
}
.sawp-indicator-pin {
    width: 0;
    height: 0;
    border-left: 8px solid transparent;
    border-right: 8px solid transparent;
    border-bottom: 12px solid white;
    margin: 0 auto 4px;
}
.sawp-indicator-phone {
    width: 20px;
    height: 20px;
    background: var(--sawp-primary);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.sawp-indicator-phone::before {
    content: "📱";
    font-size: 12px;
}
.sawp-expiry-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    opacity: 0.9;
}
/* Sidebar */
.sawp-sidebar .sawp-card {
    margin-bottom: 20px;
}
.sawp-company-info {
    display: grid;
    gap: 8px;
}
.sawp-company-name {
    font-weight: 600;
    color: var(--sawp-gray-800);
}
.sawp-company-details {
    font-size: 14px;
    color: var(--sawp-gray-600);
}
.sawp-company-details a {
    color: var(--sawp-primary);
    text-decoration: none;
}
.sawp-company-details a:hover {
    text-decoration: underline;
}
/* Statistici */
.sawp-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.sawp-stat-item {
    text-align: center;
    padding: 16px;
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
.sawp-stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--sawp-primary);
}
.sawp-stat-label {
    font-size: 12px;
    color: var(--sawp-gray-600);
    margin-top: 4px;
}
.sawp-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}
/* SMS-uri recente */
.sawp-recent-sms,
.sawp-received-sms {
    display: grid;
    gap: 8px;
}
.sawp-sms-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
.sawp-sms-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.sawp-sms-status.sawp-success {
    background: var(--sawp-success);
    color: white;
}
.sawp-sms-status.sawp-error {
    background: var(--sawp-error);
    color: white;
}
.sawp-sms-status.sawp-info {
    background: var(--sawp-info);
    color: white;
}
.sawp-sms-details {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    font-size: 12px;
}
.sawp-sms-order {
    font-weight: 600;
}
.sawp-sms-phone {
    color: var(--sawp-gray-600);
}
.sawp-sms-template,
.sawp-sms-message {
    color: var(--sawp-gray-500);
}
.sawp-sms-date {
    color: var(--sawp-gray-400);
}
/* Tabele - Stiluri îmbunătățite */
.sawp-table-container {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    border: 1px solid var(--sawp-border-color);
    overflow: hidden;
    margin-top: 16px;
}
.sawp-table-responsive {
    overflow-x: auto;
}
.sawp-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}
.sawp-table th,
.sawp-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--sawp-border-color);
}
.sawp-table th {
    background: var(--sawp-gray-50);
    font-weight: 600;
    color: var(--sawp-gray-700);
    border-bottom: 2px solid var(--sawp-border-color);
}
.sawp-table tr:hover {
    background: var(--sawp-gray-50);
}
.sawp-table tr:last-child td {
    border-bottom: none;
}
.sawp-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}
.sawp-badge.sawp-success {
    background: var(--sawp-success);
    color: white;
}
.sawp-badge.sawp-error {
    background: var(--sawp-error);
    color: white;
}
/* Linkuri */
.sawp-link {
    color: var(--sawp-primary);
    text-decoration: none;
    font-weight: 500;
}
.sawp-link:hover {
    text-decoration: underline;
}
/* No data */
.sawp-no-data {
    text-align: center;
    padding: 40px;
    color: var(--sawp-gray-500);
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
/* WhatsApp float */
.sawp-whatsapp-float {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 56px;
    height: 56px;
    background: #25D366;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    text-decoration: none;
    z-index: 1000;
    transition: transform 0.2s ease;
}
.sawp-whatsapp-float:hover {
    transform: scale(1.1);
}
.sawp-whatsapp-float .dashicons {
    color: white;
    font-size: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Switch */
.sawp-field-container {
    margin-bottom: 20px;
    padding: 16px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    background: var(--sawp-gray-50);
}
.sawp-toggle-row {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}
.sawp-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 24px;
    margin-right: 12px;
}
.sawp-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.sawp-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--sawp-gray-300);
    transition: .4s;
    border-radius: 24px;
}
.sawp-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
input:checked + .sawp-slider {
    background-color: var(--sawp-primary);
}
input:checked + .sawp-slider:before {
    transform: translateX(24px);
}
.sawp-pending-note {
    font-style: italic;
    color: var(--sawp-gray-600);
}
/* Template container */
.sawp-template-container {
    background: white;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    padding: 12px;
}
.sawp-template-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    margin-bottom: 12px;
    font-size: 14px;
    background-color: white;
}
.sawp-preview {
    padding: 12px;
    background: var(--sawp-gray-50);
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    font-size: 13px;
    line-height: 1.5;
}
/* Test conexiune */
.sawp-test-error {
    color: var(--sawp-error);
    font-weight: 500;
}
.sawp-test-success {
    color: var(--sawp-success);
    font-weight: 600;
}
/* Last update */
.sawp-last-update {
    font-size: 12px;
    color: var(--sawp-gray-500);
    margin-bottom: 16px;
    text-align: right;
}
/* Header actions */
.sawp-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
/* Form settings */
.sawp-settings-form .form-table th {
    padding: 16px 16px 16px 0;
    width: 200px;
    vertical-align: top;
}
.sawp-settings-form .form-table td {
    padding: 16px 0;
}
/* Buton reîmprospătare mic */
.sawp-btn-sm .dashicons {
    font-size: 16px;
    margin: 0;
}
	
/* Animatie spin */
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
/* Responsive adjustments */
@media screen and (max-width: 782px) {
    .sawp-dashboard {
        grid-template-columns: 1fr;
    }
    
    .sawp-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .sawp-actions {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .sawp-sms-details {
        grid-template-columns: 1fr;
    }
	

	
}
CSS;
    
    wp_add_inline_style('sawp-admin-styles', $css);
}
/* ================== DETECTARE AWB DIN COLETE ONLINE ================== */
// Funcție helper care returnează AWB-ul sau fallback
function sawp_get_order_awb( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return 'în curs de generare';
    }

    // întâi verificăm dacă avem AWB normalizat
    $awb = $order->get_meta('_notice_awb');
    if ($awb) return $awb;

    // fallback: căutăm în câteva chei cunoscute
    $candidates = [
        'awb_cargus',
        '_tracking_number',
        'awb',
        '_awb',
        'awb_number',
        '_awb_number',
        'coleteonline_awb',
        '_coleteonline_awb',
        'coleteonline_tracking_number',
        '_coleteonline_tracking_number',
        'coleteonline_courier_awb',
        '_coleteonline_courier_awb',
    ];
    foreach ($candidates as $key) {
        $val = $order->get_meta($key);
        if (is_string($val) && $val !== '') {
            return trim($val);
        }
    }

    // fallback: căutare "fuzzy" în toate metadatele
    foreach ($order->get_meta_data() as $meta) {
        $mkey = (string) $meta->key;
        $mval = $meta->value;
        if (stripos($mkey,'colete') !== false && (stripos($mkey,'awb') !== false || stripos($mkey,'track') !== false)) {
            if (is_string($mval) && $mval !== '') {
                return trim($mval);
            }
            if (is_array($mval) && !empty($mval['awb'])) {
                return trim((string)$mval['awb']);
            }
        }
    }

    // fallback: WooCommerce Shipment Tracking
    $sti = $order->get_meta('_wc_shipment_tracking_items');
    if (is_array($sti) && !empty($sti)) {
        foreach ($sti as $item) {
            if (!empty($item['tracking_number'])) {
                return trim((string)$item['tracking_number']);
            }
        }
    }

    // fallback final
    return 'în curs de generare';
}

// Detectează și salvează AWB când pluginul Colete-Online îl scrie în meta
function sawp_detect_awb_from_colete_online($meta_id, $post_id, $meta_key, $meta_value){
    if (get_post_type($post_id) !== 'shop_order') return;

    $key_l = strtolower((string)$meta_key);
    if (strpos($key_l,'colete') === false) return;
    if (strpos($key_l,'awb') === false && strpos($key_l,'track') === false) return;

    $order = wc_get_order($post_id);
    if (!$order) return;

    // dacă deja avem un AWB salvat, nu mai suprascriem
    $already = $order->get_meta('_notice_awb');
    if (!empty($already)) return;

    $awb = '';
    if (is_string($meta_value) && $meta_value !== '') {
        $awb = trim($meta_value);
    } elseif (is_array($meta_value)) {
        if (!empty($meta_value['awb'])) $awb = trim((string)$meta_value['awb']);
        elseif (!empty($meta_value['tracking_number'])) $awb = trim((string)$meta_value['tracking_number']);
    }

    if ($awb === '') return;

    $order->update_meta_data('_notice_awb', $awb);
    $order->save();
}

// Adăugăm o acțiune pentru a salva AWB-ul detectat
add_action('wp_ajax_save_coleteonline_awb', 'sawp_save_coleteonline_awb');
function sawp_save_coleteonline_awb() {
    check_ajax_referer('save_awb_nonce', 'nonce');
    
    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error('Permission denied');
    }
    
    $order_id = intval($_POST['order_id']);
    $awb = sanitize_text_field($_POST['awb']);
    
    if (empty($order_id) || empty($awb)) {
        wp_send_json_error('Missing data');
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
    }
    
    // Salvăm AWB-ul
    $order->update_meta_data('_notice_awb', $awb);
    $order->save();
    
    wp_send_json_success(array('awb' => $awb));
}

// Adăugăm script pentru a detecta AWB-ul din DOM
add_action('admin_footer', 'sawp_add_coleteonline_awb_detection_script');
function sawp_add_coleteonline_awb_detection_script() {
    global $post;
    
    // Verificăm dacă suntem pe pagina de editare a unei comenzi
    if (!$post || get_post_type($post) !== 'shop_order') {
        return;
    }
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Funcție pentru a extrage AWB-ul din DOM
        function extractAWBFromDOM() {
            var $awbElement = $('.coleteonline-courier-awb');
            if ($awbElement.length) {
                return $awbElement.text().trim();
            }
            return null;
        }
        
        // Funcție pentru a salva AWB-ul
        function saveAWB(awb) {
            if (!awb) return;
            
            var orderId = $('#post_ID').val();
            if (!orderId) return;
            
            $.post(ajaxurl, {
                action: 'save_coleteonline_awb',
                order_id: orderId,
                awb: awb,
                nonce: '<?php echo wp_create_nonce('save_awb_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    console.log('AWB salvat:', response.data.awb);
                } else {
                    console.log('Eroare la salvarea AWB:', response.data);
                }
            });
        }
        
        // Verificăm periodic dacă există AWB în DOM
        var checkInterval = setInterval(function() {
            var awb = extractAWBFromDOM();
            if (awb) {
                saveAWB(awb);
                // Oprim verificarea după ce am găsit AWB-ul
                clearInterval(checkInterval);
            }
        }, 1000);
        
        // Verificăm și la click pe butonul de download
        $(document).on('click', '.coleteonline-do-download-awb', function() {
            // Așteptăm puțin pentru a permite actualizarea DOM-ului
            setTimeout(function() {
                var awb = extractAWBFromDOM();
                if (awb) {
                    saveAWB(awb);
                } else {
                    // Dacă nu găsim AWB-ul imediat, încercăm din nou după 2 secunde
                    setTimeout(function() {
                        var awb = extractAWBFromDOM();
                        if (awb) {
                            saveAWB(awb);
                        }
                    }, 2000);
                }
            }, 500);
        });
        
        // Verificăm și la schimbarea statusului comenzii
        $(document).on('change', '#order_status', function() {
            setTimeout(function() {
                var awb = extractAWBFromDOM();
                if (awb) {
                    saveAWB(awb);
                }
            }, 1000);
        });
    });
    </script>
    <?php
}

// Adăugăm un buton manual pentru a extrage AWB-ul
add_action('woocommerce_order_actions', 'sawp_add_extract_awb_action');
function sawp_add_extract_awb_action($actions) {
    $actions['extract_awb'] = __('Extrage AWB', 'notice-sms-connector');
    return $actions;
}

// Procesăm acțiunea de extragere AWB
add_action('woocommerce_order_action_extract_awb', 'sawp_process_extract_awb_action');
function sawp_process_extract_awb_action($order) {
    $awb = sawp_get_order_awb($order);
    
    if ($awb && $awb !== 'în curs de generare') {
        $order->update_meta_data('_notice_awb', $awb);
        $order->save();
        
        // Adăugăm o notificare
        wc_add_notice(__('AWB extras cu succes: ', 'notice-sms-connector') . $awb, 'success');
    } else {
        wc_add_notice(__('Nu s-a putut extrage AWB-ul. Verificați dacă a fost generat.', 'notice-sms-connector'), 'error');
    }
}
/* ================== TRIMITERE SMS ================== */
function sawp_send_sms($order_id, $old_status, $new_status, $order) {
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    
    // Verifică dacă este statusul "payment-pending"
    if ($new_status === 'payment-pending') {
        $en = !empty($opts["enable_payment-pending"]);
        $tpl = intval($opts["tpl_payment-pending"] ?? 0);
    } 
    // Verifică dacă este statusul "card-paid"
    elseif ($new_status === 'card-paid') {
        $en = !empty($opts["enable_card-paid"]);
        $tpl = intval($opts["tpl_card-paid"] ?? 0);
    }
    // Pentru toate celelalte statusuri
    else {
        $tpl = intval($opts["tpl_{$new_status}"] ?? 0);
        $en = !empty($opts["enable_{$new_status}"]);
    }
    
    if (!$token || !$tpl || !$en) { 
        return; 
    }
    
    $phone = preg_replace('/\D+/', '', $order->get_billing_phone());
    if (!$phone) { 
        return; 
    }
    
    // Get template name
    $template_name = 'Necunoscut';
    $tpls = get_transient('sawp_tpl_list');
    if (is_array($tpls)) {
        foreach ($tpls as $t) { 
            if (isset($t['id']) && (int)$t['id'] === $tpl) { 
                $template_name = $t['name'] ?? 'Necunoscut'; 
                break; 
            } 
        }
    }
    
    $payload = [
        'number' => $phone,
        'template_id' => $tpl,
        'variables' => [
            'order_id' => $order->get_order_number(),
            'total'    => $order->get_total(),
            'awb'      => sawp_get_order_awb($order),
            'nume'     => $order->get_billing_first_name(),
        ],
    ];
    
    $resp = wp_remote_post(
        'https://api.notice.ro/api/v1/sms-out',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]
    );
    
    $status_code = 0;
    $body = '';
    if (is_wp_error($resp)) {
        $status_code = 0;
        $body = $resp->get_error_message();
    } else {
        $status_code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
    }
    
    // Asigură existența tabelei înainte de log
    sawp_maybe_create_table();
    global $wpdb, $SAWP_LOG_TABLE;
    $wpdb->insert(
        $SAWP_LOG_TABLE,
        [
            'date_sent'     => current_time('mysql'),
            'order_id'      => $order->get_order_number(),
            'phone'         => $phone,
            'template_id'   => $tpl,
            'template_name' => $template_name,
            'status_code'   => $status_code,
            'response'      => $body,
        ],
        ['%s', '%s', '%s', '%d', '%s', '%d', '%s']
    );
}
/* ================== CONTACT ================== */
function sawp_handle_contact() {
    if (!isset($_POST['sms_contact_nonce']) || !wp_verify_nonce($_POST['sms_contact_nonce'],'sms_contact')) { wp_die('Nonce invalid'); }
    $n = sanitize_text_field($_POST['sms_name'] ?? '');
    $e = sanitize_email($_POST['sms_email'] ?? '');
    $m = sanitize_textarea_field($_POST['sms_message'] ?? '');
    wp_mail('support@notice.ro','Contact plugin '.$n,$m,['From: '.$n.' <'.$e.'>','Content-Type: text/plain; charset=UTF-8']);
    wp_safe_redirect(add_query_arg('sms_contact','success', wp_get_referer() ?: admin_url())); exit;
}
/* ================== HELPERI EXTRAGERE DATE API (SMS IN) ================== */
function sawp_first_non_empty(array $arr, array $keys) { 
    foreach ($keys as $k) { 
        if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) { 
            return $arr[$k]; 
        } 
    } 
    return ''; 
}
function sawp_extract_datetime_display(array $sms) {
    // Prioritize documented fields first
    $candidates = ['received_at', 'createdAt', 'created_at', 'timestamp', 'created', 'date'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested attributes if needed
    if (!$raw && isset($sms['attributes']['received_at'])) { 
        $raw = $sms['attributes']['received_at']; 
    }
    
    $ts = $raw ? strtotime($raw) : false;
    return $ts ? date('d M Y H:i', $ts) : '';
}
function sawp_extract_phone_raw(array $sms) {
    // Prioritize documented fields
    $candidates = ['from', 'sender', 'phone', 'number', 'msisdn', 'src', 'originator', 'mobile', 'phonenumber'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested contact if needed
    if (!$raw && isset($sms['contact']['phone'])) { 
        $raw = $sms['contact']['phone']; 
    }
    
    return is_string($raw) ? $raw : (is_numeric($raw) ? (string)$raw : '');
}
function sawp_extract_message(array $sms) {
    // Prioritize documented fields
    $candidates = ['message', 'body', 'text', 'content', 'msg', 'sms'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested payload if needed
    if (!$raw && isset($sms['payload']['message'])) { 
        $raw = $sms['payload']['message']; 
    }
    
    return is_string($raw) ? $raw : '';
}


/* ******************************************************************
 *                      ***  OTP – variantă stabilă ***
 * ******************************************************************/

/* 1) Înregistrăm opțiunile OTP ca să nu mai apară "allowed options list" */
add_action('admin_init', function () {
    register_setting('sawp_otp_group', 'sawp_otp_opts', 'sawp_sanitize_otp_opts');
});

/* 2) Sanitizare opțiuni (inclusiv metodele de plată) */
if (!function_exists('sawp_sanitize_otp_opts')) {
function sawp_sanitize_otp_opts($input) {
    $out = [];
    $out['enabled']        = !empty($input['enabled']);
    $out['template_id']    = isset($input['template_id']) ? absint($input['template_id']) : 0;
    $out['button_text']    = isset($input['button_text']) ? sanitize_text_field($input['button_text']) : 'Trimite SMS pentru comanda';
    $out['verify_text']    = isset($input['verify_text']) ? sanitize_text_field($input['verify_text']) : 'Verifică codul';
    $out['placeholder']    = isset($input['placeholder']) ? sanitize_text_field($input['placeholder']) : 'Cod OTP';
    $out['code_length']    = isset($input['code_length']) ? max(4, min(8, absint($input['code_length']))) : 6;
    $out['expire_minutes'] = isset($input['expire_minutes']) ? max(1, min(30, absint($input['expire_minutes']))) : 10;

    // Metode de plată
    $out['methods'] = [];
    if (!empty($input['methods']) && is_array($input['methods'])) {
        foreach ($input['methods'] as $m) {
            $m = sanitize_text_field($m);
            if ($m !== '') $out['methods'][] = $m;
        }
    }
    return $out;
}}
/* 3) Pagina „Setări OTP” (cu încărcare sigură a template-urilor) */
function sawp_render_otp_page() {
    $opts = get_option('sawp_opts', []);
    $otp  = get_option('sawp_otp_opts', []);
    $tk   = trim($opts['token'] ?? '');

    // templates
    $tpls = get_transient('sawp_tpl_list');
    if (!is_array($tpls)) {
        $tpls = [];
        if ($tk) {
            $res = wp_remote_get('https://api.notice.ro/api/v1/templates', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tk,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);
            if (!is_wp_error($res) && 200 === (int) wp_remote_retrieve_response_code($res)) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                if (isset($body['data']) && is_array($body['data']))            $tpls = $body['data'];
                elseif (isset($body['templates']) && is_array($body['templates'])) $tpls = $body['templates'];
                elseif (is_array($body))                                         $tpls = $body;
                set_transient('sawp_tpl_list', $tpls, 5 * MINUTE_IN_SECONDS);
            }
        }
    }

    // metode de plată
    $gateways = [];
    if (class_exists('WC_Payment_Gateways') && function_exists('WC')) {
        $gw = WC()->payment_gateways();
        if ($gw && method_exists($gw, 'payment_gateways')) {
            foreach ($gw->payment_gateways() as $g) {
                $gateways[$g->id] = $g->get_title();
            }
        }
    }
    $chosen = (array)($otp['methods'] ?? []);
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;"><span class="dashicons dashicons-shield"></span> Setări OTP</h1>
        <form method="post" action="options.php" style="max-width:800px;margin-top:20px;">
            <?php settings_fields('sawp_otp_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Activează OTP la checkout</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sawp_otp_opts[enabled]" <?php checked(!empty($otp['enabled']), true); ?>>
                            <span>Solicită codul primit prin SMS înainte de plată</span>
                        </label>
                        <?php if (!$tk): ?>
                            <p class="description" style="color:#d63638;">Setează întâi Token API în „Setări SMS”.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Template OTP (Notice.ro)</th>
                    <td>
                        <select name="sawp_otp_opts[template_id]" style="min-width:320px;">
                            <option value="">— alege template —</option>
                            <?php foreach ($tpls as $t):
                                $id   = (int)($t['id'] ?? 0);
                                $name = $t['name'] ?? ('['.$id.']'); ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($id, (int)($otp['template_id'] ?? 0)); ?>>
                                    [<?php echo esc_html($id); ?>] <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Exemplu: <code>Codul tău este {{otp_code}}</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Text buton trimitere SMS</th>
                    <td><input type="text" name="sawp_otp_opts[button_text]" value="<?php echo esc_attr($otp['button_text'] ?? 'Trimite SMS pentru comanda'); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row">Text buton verificare</th>
                    <td><input type="text" name="sawp_otp_opts[verify_text]" value="<?php echo esc_attr($otp['verify_text'] ?? 'Verifică codul'); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row">Placeholder câmp cod</th>
                    <td><input type="text" name="sawp_otp_opts[placeholder]" value="<?php echo esc_attr($otp['placeholder'] ?? 'Cod OTP'); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row">Lungime cod</th>
                    <td><input type="number" min="4" max="8" name="sawp_otp_opts[code_length]" value="<?php echo esc_attr($otp['code_length'] ?? 6); ?>" style="width:90px;"> cifre</td>
                </tr>
                <tr>
                    <th scope="row">Expiră în</th>
                    <td><input type="number" min="1" max="30" name="sawp_otp_opts[expire_minutes]" value="<?php echo esc_attr($otp['expire_minutes'] ?? 10); ?>" style="width:90px;"> minute</td>
                </tr>
                <tr>
                    <th scope="row">Activează pentru metode</th>
                    <td>
                        <?php if ($gateways): foreach ($gateways as $gid => $gname): ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="sawp_otp_opts[methods][]" value="<?php echo esc_attr($gid); ?>" <?php checked(in_array($gid, $chosen, true)); ?>>
                                <?php echo esc_html($gname . ' ['.$gid.']'); ?>
                            </label>
                        <?php endforeach; else: ?>
                            <em>Nu am găsit metode de plată.</em>
                        <?php endif; ?>
                        <p class="description">OTP e cerut doar pentru metodele bifate.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Salvează setările OTP'); ?>
        </form>
    </div>
    <?php
}

/* 4) UI + Logica front – fără interceptarea click-ului pe buton */
add_action('wp_enqueue_scripts', function () {
    if (!is_checkout()) return;
    $otp  = get_option('sawp_otp_opts', []);
    $opts = get_option('sawp_opts', []);
    if (empty($otp['enabled']) || empty($otp['template_id']) || empty($opts['token'])) return;

    wp_enqueue_script('jquery');
    $methods = (array)($otp['methods'] ?? []);

    $data = [
        'ajaxurl'        => admin_url('admin-ajax.php'),
        'nonce_send'     => wp_create_nonce('sawp_send_otp'),
        'nonce_verify'   => wp_create_nonce('sawp_verify_otp'),
        'buttonText'     => $otp['button_text'] ?? 'Trimite SMS pentru comanda',
        'verifyText'     => $otp['verify_text'] ?? 'Verifică codul',
        'placeholder'    => $otp['placeholder'] ?? 'Cod OTP',
        'allowedMethods' => array_values($methods),
        'messages'       => [
            'sent'      => 'SMS cu codul a fost trimis.',
            'sending'   => 'Se trimite SMS...',
            'invalid'   => 'Cod OTP invalid sau expirat.',
            'verified'  => 'Cod verificat. Puteți finaliza plata.',
            'enterCode' => 'Introduceți codul primit prin SMS.',
            'needPhone' => 'Completați numărul de telefon.',
        ],
    ];
    wp_add_inline_script('jquery', 'window.SAWP_OTP = '. wp_json_encode($data) .';', 'before');

    $inline = <<<JS
jQuery(function($){
  var cfg = window.SAWP_OTP || {};
  var state = { sent:false, verified:false, originalText:null };

  function btn(){
    // clasice + blocks
    var b = $('#place_order');
    if (b.length) return b;
    b = $('.wc-block-components-checkout-place-order-button, button.wc-block-components-button__button.wc-block-components-checkout-place-order-button');
    if (b.length) return b.first();
    b = $('button[name="woocommerce_checkout_place_order"]');
    return b.first();
  }

  function getMethod(){
    var el = $('input[name="payment_method"]:checked');
    return el.length ? el.val() : '';
  }

  function shouldGate(){
    var allowed = cfg.allowedMethods || [];
    var current = getMethod();
    if (!allowed.length) return true; // dacă nu e nimic bifat, aplică tuturor
    return allowed.indexOf(current) !== -1;
  }

  function getPhone(){
    var p = $('#billing_phone');
    return p.length ? p.val() : '';
  }

  function ensureOtpBox(){
    var box = $('#sawp-otp-box');
    if (box.length) return box;
    box = $('<div id="sawp-otp-box" style="margin:12px 0;padding:10px;border:1px solid #e5e7eb;border-radius:6px;display:none;"></div>');
    var row = $('<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"></div>');
    var send = $('<button type="button" id="sawp-otp-send" class="button"></button>').text(cfg.buttonText||'Trimite SMS pentru comanda');
    var input = $('<input type="text" id="sawp-otp-input" placeholder="'+(cfg.placeholder||'Cod OTP')+'" maxlength="8" style="flex:1;min-width:160px;padding:8px;border:1px solid #d1d5db;border-radius:6px;">');
    var verify = $('<button type="button" id="sawp-otp-verify" class="button button-primary"></button>').text(cfg.verifyText||'Verifică codul');
    var note = $('<div id="sawp-otp-note" style="margin-top:6px;font-size:12px;color:#6b7280;"></div>');
    row.append(send).append(input).append(verify);
    var placeWrap = $('.place-order, .wc-block-components-checkout-place-order').first();
    if (placeWrap.length) placeWrap.before(box);
    else btn().closest('div').before(box);
    box.append(row).append(note);
    return box;
  }

  function setButtonDisabled(disabled, newText){
    var b = btn();
    if (!b.length) return;
    if (state.originalText === null) state.originalText = b.text();
    if (disabled){
      b.prop('disabled', true).addClass('sawp-otp-disabled');
      if (newText) b.text(newText);
    } else {
      b.prop('disabled', false).removeClass('sawp-otp-disabled');
      b.text(state.originalText || 'Plasează comanda');
    }
  }

  function refresh(){
    if (shouldGate()){
      ensureOtpBox().show();
      if (!state.verified){
        setButtonDisabled(true, cfg.buttonText || 'Trimite SMS pentru comanda');
      } else {
        setButtonDisabled(false);
        $('#sawp-otp-note').css('color','#059669').text(cfg.messages?.verified || 'Cod verificat.');
      }
    } else {
      ensureOtpBox().hide();
      setButtonDisabled(false);
    }
  }

  // Trimite SMS
  $(document).on('click', '#sawp-otp-send', function(){
    var phone = (getPhone()||'').replace(/\\D+/g,'');
    if (!phone){
      $('#sawp-otp-note').css('color','#d14343').text(cfg.messages?.needPhone || 'Completați numărul de telefon.');
      return;
    }
    $('#sawp-otp-note').css('color','#6b7280').text(cfg.messages?.sending || 'Se trimite SMS...');
    $.post(cfg.ajaxurl, { action:'sawp_send_otp', nonce:cfg.nonce_send, phone:phone }, function(res){
      if (res && res.success){
        state.sent = true;
        $('#sawp-otp-note').css('color','#059669').text(cfg.messages?.sent || 'SMS trimis.');
      } else {
        state.sent = false;
        $('#sawp-otp-note').css('color','#d14343').text((res && res.data) ? res.data : 'Eroare la trimiterea SMS-ului');
      }
    });
  });

  // Verifică cod
  $(document).on('click', '#sawp-otp-verify', function(){
    var code = ($('#sawp-otp-input').val()||'').trim();
    if (!code){
      $('#sawp-otp-note').css('color','#d14343').text(cfg.messages?.enterCode || 'Introduceți codul.');
      return;
    }
    $.post(cfg.ajaxurl, { action:'sawp_verify_otp', nonce:cfg.nonce_verify, code:code }, function(res){
      if (res && res.success){
        jQuery('#place_order, .wc-block-components-checkout-place-order-button').show().prop('disabled',false);

		state.verified = true;
        setButtonDisabled(false);
        $('#sawp-otp-note').css('color','#059669').text(cfg.messages?.verified || 'Cod verificat.');
      } else {
        state.verified = false;
        setButtonDisabled(true, cfg.buttonText || 'Trimite SMS pentru comanda');
        $('#sawp-otp-note').css('color','#d14343').text(cfg.messages?.invalid || 'Cod invalid');
      }
    });
  });

  // Reacționează la schimbări de checkout/metodă
  $(document.body).on('updated_checkout payment_method_selected change', function(){
    refresh();
  });

  // init
  ensureOtpBox();
  refresh();
});
JS;
    wp_add_inline_script('jquery', $inline);

    // un pic de CSS ca disabled să fie clar
    $css = <<<CSS
.sawp-otp-disabled { opacity: .6; pointer-events: none; }
CSS;
    wp_add_inline_style('woocommerce-inline', $css);
});

/* 5) Fallback render (doar pentru a avea un anchor în DOM dacă e nevoie) */
add_action('woocommerce_review_order_before_submit', function () {
    $otp  = get_option('sawp_otp_opts', []);
    $opts = get_option('sawp_opts', []);
    if (empty($otp['enabled']) || empty($otp['template_id']) || empty($opts['token'])) return;
    echo '<div id="sawp-otp-box-server" style="display:none;"></div>';
}, 5);

/* 6) AJAX: send OTP */
add_action('wp_ajax_sawp_send_otp', 'sawp_send_otp_handler');
add_action('wp_ajax_nopriv_sawp_send_otp', 'sawp_send_otp_handler');
function sawp_send_otp_handler() {
    check_ajax_referer('sawp_send_otp', 'nonce');

    $otp   = get_option('sawp_otp_opts', []);
    $opts  = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    $tpl   = absint($otp['template_id'] ?? 0);

    if (empty($otp['enabled']) || !$tpl || !$token) wp_send_json_error('OTP dezactivat sau configurare incompletă.');

    $phone = isset($_POST['phone']) ? preg_replace('/\D+/', '', (string)$_POST['phone']) : '';
    if (!$phone) wp_send_json_error('Telefon invalid.');

    $len  = max(4, min(8, absint($otp['code_length'] ?? 6)));
    $code = '';
    for ($i=0; $i<$len; $i++) { $code .= wp_rand(0,9); }

    if (!WC()->session) { WC()->initialize_session(); }
    WC()->session->set('sawp_otp', [
        'code'     => $code,
        'phone'    => $phone,
        'created'  => time(),
        'expires'  => time() + (max(1, (int)($otp['expire_minutes'] ?? 10)) * 60),
        'verified' => false,
    ]);

    $resp = wp_remote_post('https://api.notice.ro/api/v1/sms-out', [
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'number'      => $phone,
            'template_id' => $tpl,
            'variables'   => [ 'otp_code' => $code ],
        ]),
        'timeout' => 20,
    ]);
    if (is_wp_error($resp)) wp_send_json_error($resp->get_error_message());
    if ((int)wp_remote_retrieve_response_code($resp) !== 200) wp_send_json_error('Eroare API Notice.');

    wp_send_json_success(true);
}

/* 7) AJAX: verify OTP */
add_action('wp_ajax_sawp_verify_otp', 'sawp_verify_otp_handler');
add_action('wp_ajax_nopriv_sawp_verify_otp', 'sawp_verify_otp_handler');
function sawp_verify_otp_handler() {
    check_ajax_referer('sawp_verify_otp', 'nonce');
    if (!WC()->session) { WC()->initialize_session(); }

    $posted = isset($_POST['code']) ? preg_replace('/\D+/', '', (string)$_POST['code']) : '';
    if (!$posted) wp_send_json_error('Cod lipsă.');

    $data = WC()->session->get('sawp_otp');
    if (empty($data) || empty($data['code'])) wp_send_json_error('Nicio cerere OTP activă.');
    if (time() > (int)$data['expires'])     wp_send_json_error('Cod expirat.');
    if ($posted !== (string)$data['code'])  wp_send_json_error('Cod incorect.');

    $data['verified'] = true;
    WC()->session->set('sawp_otp', $data);
    wp_send_json_success(true);
}

/* 8) Validare server-side: oprește checkout-ul fără OTP valid (doar pentru metodele bifate) */
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    $otp  = get_option('sawp_otp_opts', []);
    $opts = get_option('sawp_opts', []);
    if (empty($otp['enabled']) || empty($otp['template_id']) || empty($opts['token'])) return;

    $methods = (array)($otp['methods'] ?? []);
    $selected_method = '';
    if (is_array($data) && isset($data['payment_method'])) {
        $selected_method = sanitize_text_field($data['payment_method']);
    } elseif (isset($_POST['payment_method'])) {
        $selected_method = sanitize_text_field(wp_unslash($_POST['payment_method']));
    }
    if ($methods && $selected_method && !in_array($selected_method, $methods, true)) return;

    if (!WC()->session) { WC()->initialize_session(); }
    $sess = WC()->session->get('sawp_otp');

    if (empty($sess) || empty($sess['verified'])) {
        $errors->add('sawp_otp', __('Trebuie să validați codul OTP înainte de a plăti.', 'notice-sms-connector'));
        return;
    }
    if (time() > (int)$sess['expires']) {
        $errors->add('sawp_otp', __('Codul OTP a expirat. Reîncercați.', 'notice-sms-connector'));
        return;
    }
    $posted_phone = '';
    if (is_array($data) && isset($data['billing_phone'])) {
        $posted_phone = preg_replace('/\D+/', '', (string)$data['billing_phone']);
    } elseif (isset($_POST['billing_phone'])) {
        $posted_phone = preg_replace('/\D+/', '', (string)wp_unslash($_POST['billing_phone']));
    }
    if ($posted_phone && !empty($sess['phone']) && $posted_phone !== $sess['phone']) {
        $errors->add('sawp_otp', __('Numărul de telefon s-a modificat. Trimiteți din nou codul OTP.', 'notice-sms-connector'));
        return;
    }
}, 10, 2);


/* ================== GĂSIRE COMANDĂ DUPĂ TELEFON ================== */
function sawp_find_order_by_phone($phone) {
    global $wpdb;
    $clean = preg_replace('/\D+/', '', (string)$phone);
    if ($clean === '') { return 0; }
    
    // Try different phone number formats
    $patterns = [
        '%' . $clean,                    // Exact match
        '%40' . substr($clean, 1),       // International format (407xxxx)
        '%' . substr($clean, 1)          // Without country code (7xxxx)
    ];
    
    foreach ($patterns as $pattern) {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_billing_phone' AND meta_value LIKE %s
             ORDER BY post_id DESC LIMIT 1",
            $pattern
        ));
        
        if ($order_id) {
            return intval($order_id);
        }
    }
    
    return 0;
}
/* ================== CONFIRMARE COMENZI PRIN SMS + BADGE ================== */

// Procesează SMS-urile de confirmare "Da", "DA", "da" - FĂRĂ SCHIMBAREA STATUSULUI
function sawp_process_order_confirmation_sms($sms_data) {
    $message = sawp_extract_message($sms_data);
    $phone = sawp_extract_phone_raw($sms_data);
    
    // Verifică dacă mesajul conține confirmarea (mai flexibil)
    $clean_message = strtolower(trim($message));
    $confirmation_keywords = ['da', 'yes', 'DA', 'Da', 'okay'];
    $is_confirmation = in_array($clean_message, $confirmation_keywords);
    
    if (!$is_confirmation || !$phone) {
        return false; // Nu este confirmare sau nu are telefon
    }
    
    $order_id = sawp_find_order_by_phone($phone);
    
    if (!$order_id) {
        return false; // Nu s-a găsit comandă pentru acest telefon
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        return false; // Comanda nu există
    }
    
    // Verifică dacă comanda nu este deja confirmată
    $already_confirmed = $order->get_meta('_sawp_sms_confirmed') === 'yes';
    if ($already_confirmed) {
        return false; // Este deja confirmată
    }
    
    // Marchează comanda ca confirmată prin SMS (DOAR BADGE)
    $order->update_meta_data('_sawp_sms_confirmed', 'yes');
    $order->update_meta_data('_sawp_sms_confirmed_at', current_time('mysql'));
    $order->update_meta_data('_sawp_sms_confirmed_phone', $phone);
    $order->save();
    
    // Adaugă o notă la comandă
    $order->add_order_note(
        sprintf(
            __('Comandă confirmată prin SMS de la %s', 'notice-sms-connector'),
            $phone
        )
    );
    
    return $order_id; // Returnează ID-ul comenzii confirmate
}

// Verifică SMS-urile pentru confirmări - DOAR MANUAL
function sawp_check_sms_confirmations() {
    $received_sms = get_transient('sawp_received_sms');
    
    if (is_array($received_sms)) {
        foreach ($received_sms as $sms) {
            // Verifică dacă SMS-ul a fost deja procesat
            $sms_id = $sms['id'] ?? md5(serialize($sms));
            $processed_key = 'sawp_processed_sms_' . $sms_id;
            
            if (!get_transient($processed_key)) {
                $order_id = sawp_process_order_confirmation_sms($sms);
                
                if ($order_id) {
                    // Marchează SMS-ul ca procesat (expiră după 24 de ore)
                    set_transient($processed_key, true, 24 * HOUR_IN_SECONDS);
                }
            }
        }
    }
}

// Adaugă coloană pentru confirmare SMS în lista de comenzi
add_filter('manage_edit-shop_order_columns', 'sawp_add_sms_confirmation_column');
function sawp_add_sms_confirmation_column($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        // Adaugă coloana după coloana "Status"
        if ($key === 'order_status') {
            $new_columns['sms_confirmation'] = __('Confirmare SMS', 'notice-sms-connector');
        }
    }
    
    return $new_columns;
}

// Afișează badge-ul mov în coloană
add_action('manage_shop_order_posts_custom_column', 'sawp_show_sms_confirmation_column');
function sawp_show_sms_confirmation_column($column) {
    global $post;
    
    if ($column === 'sms_confirmation') {
        $order = wc_get_order($post->ID);
        $is_confirmed = $order->get_meta('_sawp_sms_confirmed') === 'yes';
        
        if ($is_confirmed) {
            echo '<span class="sawp-sms-badge" style="background: #8B5CF6; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block;">';
            echo __('Confirmat SMS', 'notice-sms-connector');
            echo '</span>';
            
            // Afișează data confirmării dacă există
            $confirmed_at = $order->get_meta('_sawp_sms_confirmed_at');
            if ($confirmed_at) {
                echo '<br><small style="color: #666; font-size: 10px;">';
                echo date_i18n('d M H:i', strtotime($confirmed_at));
                echo '</small>';
            }
        } else {
            echo '<span style="color: #ccc;">—</span>';
        }
    }
}

// Adaugă buton în bara de acțiuni din pagina de comenzi
add_action('restrict_manage_posts', 'sawp_add_check_sms_button_orders_page');
function sawp_add_check_sms_button_orders_page($post_type) {
    if ($post_type === 'shop_order') {
        echo '<button type="button" id="sawp-check-sms-confirmations" class="button" style="margin-left: 10px;">';
        echo '<span class="dashicons dashicons-email-alt" style="margin-top: 3px;"></span> ';
        echo __('Verifică confirmări SMS', 'notice-sms-connector');
        echo '</button>';
        
        // Script pentru buton
        echo '<script>
        jQuery(document).ready(function($) {
            $("#sawp-check-sms-confirmations").on("click", function() {
                var $btn = $(this);
                $btn.prop("disabled", true).text("Se verifică...");
                
                $.post(ajaxurl, {
                    action: "sawp_check_sms_confirmations_ajax",
                    nonce: "' . wp_create_nonce('sawp_check_sms_confirmations') . '"
                }, function(response) {
                    if (response.success) {
                        alert("Verificare completă! " + response.data.message);
                        location.reload(); // Reîncarcă pentru a afișa noile badge-uri
                    } else {
                        alert("Eroare: " + response.data);
                    }
                    $btn.prop("disabled", false).html(\'<span class="dashicons dashicons-email-alt" style="margin-top: 3px;"></span> Verifică confirmări SMS\');
                }).fail(function() {
                    alert("Eroare la comunicarea cu serverul.");
                    $btn.prop("disabled", false).html(\'<span class="dashicons dashicons-email-alt" style="margin-top: 3px;"></span> Verifică confirmări SMS\');
                });
            });
        });
        </script>';
    }
}

// Handler AJAX pentru verificarea confirmărilor - FĂRĂ SCHIMBAREA STATUSULUI
add_action('wp_ajax_sawp_check_sms_confirmations_ajax', 'sawp_check_sms_confirmations_ajax_handler');
function sawp_check_sms_confirmations_ajax_handler() {
    check_ajax_referer('sawp_check_sms_confirmations', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune insuficientă.');
    }
    
    // Forțează reîmprospătarea SMS-urilor primite
    delete_transient('sawp_received_sms');
    
    // Reîncarcă SMS-urile de la API
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    
    if (!$token) {
        wp_send_json_error('Token API nu este setat.');
    }
    
    $response = wp_remote_get('https://api.notice.ro/api/v1/sms-in', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error('Eroare la preluarea SMS-urilor: ' . $response->get_error_message());
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $data = [];
    
    if (is_array($body)) {
        if (isset($body['data']) && is_array($body['data'])) { 
            $data = $body['data']; 
        } elseif (isset($body[0])) { 
            $data = $body; 
        }
    }
    
    set_transient('sawp_received_sms', $data, 15 * MINUTE_IN_SECONDS);
    set_transient('sawp_received_last_update', time(), 15 * MINUTE_IN_SECONDS);
    
    // Procesează confirmările - FĂRĂ SCHIMBAREA STATUSULUI
    $confirmed_count = 0;
    $total_sms = count($data);
    
    if (is_array($data)) {
        foreach ($data as $sms) {
            $order_id = sawp_process_order_confirmation_sms($sms);
            if ($order_id) {
                $confirmed_count++; // Numără doar comenzile confirmate cu succes
            }
        }
    }
    
    wp_send_json_success([
        'message' => sprintf(
            __('S-au procesat %d SMS-uri. %d comenzi confirmate prin SMS.', 'notice-sms-connector'), 
            $total_sms, 
            $confirmed_count
        ),
        'confirmed' => $confirmed_count,
        'total_sms' => $total_sms
    ]);
}

// Adaugă CSS pentru badge-uri și buton
add_action('admin_head', 'sawp_sms_confirmation_styles');
function sawp_sms_confirmation_styles() {
    ?>
    <style>
    .sawp-sms-badge {
        background: #8B5CF6 !important;
        color: white !important;
        padding: 4px 8px !important;
        border-radius: 12px !important;
        font-size: 11px !important;
        font-weight: bold !important;
        display: inline-block !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }
    
    .column-sms_confirmation {
        width: 120px !important;
        text-align: center !important;
    }
    
    #sawp-check-sms-confirmations .dashicons {
        margin-right: 4px;
    }
    </style>
    <?php
}


/* ------------------------------------------------------------
 *  Sursă AWB selectabilă (Auto / Colete-Online / Sameday / FAN)
 *  UI + salvare proprie (AJAX) + detecție AWB (Sameday & FAN).
 *  Înlocuiește blocul tău curent cu acesta.
 * ------------------------------------------------------------ */

/**
 * Normalizează AWB-ul: taie spațiile și îl face UPPERCASE.
 */
if ( ! function_exists( 'sawp_normalize_awb' ) ) {
	function sawp_normalize_awb( $awb ) {
		$awb = is_string( $awb ) ? $awb : (string) $awb;
		$awb = trim( $awb );
		$awb = preg_replace( '/\s+/', '', $awb );

		return strtoupper( $awb );
	}
}

/**
 * Opțiune separată: notice_awb_source
 * Valorile permise: auto / colete / sameday / fan
 */
if ( ! function_exists( 'sawp_get_awb_source' ) ) {
	function sawp_get_awb_source() {
		$allowed = array( 'auto', 'colete', 'sameday', 'fan', 'curiero' );
		$val     = get_option( 'notice_awb_source', 'auto' );

		$val = is_string( $val ) ? strtolower( trim( $val ) ) : 'auto';
		if ( ! in_array( $val, $allowed, true ) ) {
			$val = 'auto';
		}

		return $val;
	}
}

/**
 * 1) Înregistrăm câmpul în pagina de setări (dar salvăm cu AJAX).
 */
add_action(
	'admin_init',
	function () {
		add_settings_field(
			'sawp_awb_source',
			__( 'Sursă AWB', 'notice-sms-connector' ),
			'sawp_field_awb_source_pro',
			'sawp-settings',
			'sawp_main'
		);
	}
);

/**
 * 1b) AJAX: salvăm notice_awb_source când selectezi alt radio.
 */
add_action( 'wp_ajax_notice_save_awb_source', 'notice_save_awb_source' );
function notice_save_awb_source() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => 'no_permission' ) );
	}

	check_ajax_referer( 'notice_save_awb_source', 'nonce' );

	$allowed = array( 'auto', 'colete', 'sameday', 'fan', 'curiero' );
	$val     = isset( $_POST['awb_source'] ) ? sanitize_text_field( wp_unslash( $_POST['awb_source'] ) ) : 'auto';
	$val     = strtolower( trim( $val ) );

	if ( ! in_array( $val, $allowed, true ) ) {
		$val = 'auto';
	}

	update_option( 'notice_awb_source', $val );

	wp_send_json_success( array( 'saved' => $val ) );
}

/**
 * UI „card-uri” pentru Auto / Colete-Online / Sameday / FAN Courier.
 */
function sawp_field_awb_source_pro() {
	$val = sawp_get_awb_source();

	$logo_co  = 'https://govnet.ro/uploads/images/33_Colete%20Online%20Logo.jpeg';
	$logo_sd  = 'https://sameday.ro/app/themes/samedaytwo/public/images/logo/logo-sameday.svg';
	$logo_fan = 'https://www.fancourier.ro/wp-content/uploads/2023/03/logo.svg';
	$logo_curiero = 'https://curie.ro/wp-content/uploads/2020/03/CurieRO_logo_full.svg'; // opțional

	$choices = array(
		'auto'   => array(
			'label' => 'Auto',
			'desc'  => 'Detectează automat din toate integrările compatibile. Primul AWB găsit este folosit.',
			'logo'  => '',
		),
		'colete' => array(
			'label' => 'Colete-Online',
			'desc'  => 'Folosește exclusiv AWB-urile din pluginul Colete-Online.',
			'logo'  => $logo_co,
		),
		'sameday' => array(
			'label' => 'Sameday',
			'desc'  => 'Folosește exclusiv AWB-urile din pluginul Sameday.',
			'logo'  => $logo_sd,
		),
		'fan'    => array(
			'label' => 'FAN Courier',
			'desc'  => 'Folosește exclusiv AWB-urile din integrările FAN Courier (selfAWB / plugin WooCommerce).',
			'logo'  => $logo_fan,
		),
		
		'curiero' => array(
          'label' => 'Curiero',
          'desc'  => 'Folosește AWB-urile generate de pluginul Curiero.',
          'logo'  => $logo_curiero,
  ),
	);

	// Nonce pentru AJAX
	$nonce = wp_create_nonce( 'notice_save_awb_source' );

	// Stil + JS o singură dată.
	static $printed_assets = false;
	if ( ! $printed_assets ) {
		$printed_assets = true;
		?>
		<style>
			.sawp-awb-source-grid {
				display: flex;
				flex-wrap: wrap;
				gap: 16px;
				margin-top: 4px;
			}
			.sawp-awb-card {
				display: flex;
				align-items: flex-start;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 12px 16px;
				background: #fff;
				box-shadow: 0 1px 2px rgba(0,0,0,.04);
				cursor: pointer;
				max-width: 280px;
				flex: 1 1 240px;
				transition:
					box-shadow .15s ease,
					border-color .15s ease,
					transform .15s ease;
			}
			.sawp-awb-card:hover {
				box-shadow: 0 4px 10px rgba(0,0,0,.06);
				transform: translateY(-1px);
			}
			.sawp-awb-card.is-active {
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
			}
			.sawp-awb-card input[type="radio"] {
				margin-right: 12px;
				margin-top: 4px;
			}
			.sawp-awb-card-body {
				flex: 1;
			}
			.sawp-awb-card-logo {
				height: 24px;
				max-width: 120px;
				margin-bottom: 6px;
				object-fit: contain;
				display: block;
			}
			.sawp-awb-card-title {
				font-weight: 600;
				margin-bottom: 2px;
			}
			.sawp-awb-card-desc {
				margin: 0;
				font-size: 12px;
				line-height: 1.5;
				color: #50575e;
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var cards = document.querySelectorAll('.sawp-awb-card');
				var nonce = '<?php echo esc_js( $nonce ); ?>';

				function setActiveFromChecked() {
					cards.forEach(function (card) {
						card.classList.remove('is-active');
						var input = card.querySelector('input[type="radio"]');
						if (input && input.checked) {
							card.classList.add('is-active');
						}
					});
				}

				cards.forEach(function (card) {
					var input = card.querySelector('input[type="radio"]');
					if (!input) return;

					card.addEventListener('click', function (e) {
						if (e.target.tagName !== 'INPUT') {
							input.checked = true;
						}
						setActiveFromChecked();

						// Salvează imediat alegerea prin AJAX
						var fd = new FormData();
						fd.append('action', 'notice_save_awb_source');
						fd.append('awb_source', input.value);
						fd.append('nonce', nonce);

						if (typeof ajaxurl !== 'undefined') {
							fetch(ajaxurl, {
								method: 'POST',
								body: fd
							}).then(function (r) {
								return r.text();
							}).catch(function () {
								console.warn('Nu s-a putut salva Sursa AWB prin AJAX.');
							});
						}
					});
				});

				setActiveFromChecked();
			});
		</script>
		<?php
	}

	echo '<div class="sawp-awb-source-grid">';

	foreach ( $choices as $key => $choice ) {
		$is_active = ( $val === $key ) ? ' is-active' : '';
		echo '<label class="sawp-awb-card' . esc_attr( $is_active ) . '">';
		// folosim opțiune separată, nu array-ul sawp_opts
		echo '<input type="radio" name="notice_awb_source_fake" value="' . esc_attr( $key ) . '" ' . checked( $val, $key, false ) . ' />';
		echo '<div class="sawp-awb-card-body">';

		if ( ! empty( $choice['logo'] ) ) {
			echo '<img class="sawp-awb-card-logo" src="' . esc_url( $choice['logo'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" />';
		}

		echo '<div class="sawp-awb-card-title">' . esc_html( $choice['label'] ) . '</div>';
		echo '<p class="sawp-awb-card-desc">' . esc_html( $choice['desc'] ) . '</p>';
		echo '</div>';
		echo '</label>';
	}

	echo '</div>';
	echo '<p class="description">';
	echo esc_html__( 'Alegerea influențează ce sursă are prioritate când salvăm meta `_notice_awb` (folosit ca {awb} în șabloanele Notice.ro).', 'notice-sms-connector' );
	echo '</p>';
}

/**
 * 1c) Sync AWB FAN din DOM (butonul #fan_print_awb_btn) în _notice_awb,
 * ca să putem folosi {awb} în template.
 */
add_action( 'admin_footer', 'notice_sync_fan_awb_from_dom' );
function notice_sync_fan_awb_from_dom() {
	// Doar în admin, pe pagina de comenzi WooCommerce.
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// HPOS: ecranul e de tip "woocommerce_page_wc-orders".
	if ( $screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'shop_order' ) {
		return;
	}

	$awb_source = sawp_get_awb_source();
	// Dacă sursa nu e fan/auto, nu are rost să injectăm scriptul.
	if ( ! in_array( $awb_source, array( 'fan', 'auto' ), true ) ) {
		return;
	}

	$nonce = wp_create_nonce( 'notice_sync_fan_awb' );
	?>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var btn = document.querySelector('#fan_print_awb_btn');
			if (!btn) return;

			var awb     = btn.dataset.awbNumber || '';
			var orderId = btn.dataset.orderId   || '';
			if (!awb || !orderId) return;

			// Trimitem AWB-ul către server ca să fie salvat în _notice_awb.
			if (typeof ajaxurl === 'undefined') return;

			var fd = new FormData();
			fd.append('action',   'notice_sync_fan_awb');
			fd.append('nonce',    '<?php echo esc_js( $nonce ); ?>');
			fd.append('order_id', orderId);
			fd.append('awb',      awb);

			fetch(ajaxurl, {
				method: 'POST',
				body: fd
			}).then(function (resp) {
				return resp.text();
			}).catch(function () {
				console.warn('Nu s-a putut sincroniza AWB-ul FAN către Notice.');
			});
		});
	</script>
	<?php
}

/**
 * AJAX handler: salvează AWB FAN în meta _notice_awb.
 */
add_action( 'wp_ajax_notice_sync_fan_awb', 'notice_sync_fan_awb' );
function notice_sync_fan_awb() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => 'no_permission' ) );
	}

	check_ajax_referer( 'notice_sync_fan_awb', 'nonce' );

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$awb      = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';

	if ( ! $order_id || '' === $awb ) {
		wp_send_json_error( array( 'error' => 'missing_data' ) );
	}

	$awb = sawp_normalize_awb( $awb );

	// AWB FAN: numeric, 6–20 cifre.
	if ( ! preg_match( '/^[0-9]{6,20}$/', $awb ) ) {
		wp_send_json_error( array( 'error' => 'invalid_awb' ) );
	}

	$awb_source = sawp_get_awb_source();
	if ( ! in_array( $awb_source, array( 'fan', 'auto' ), true ) ) {
		wp_send_json_error( array( 'error' => 'source_not_fan' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'error' => 'no_order' ) );
	}

	$existing = (string) $order->get_meta( '_notice_awb' );
	// În modul Auto nu suprascriem un AWB existent identic.
	if ( 'auto' === $awb_source && $existing !== '' && $existing === $awb ) {
		wp_send_json_success( array( 'saved' => $existing, 'status' => 'no_change' ) );
	}

	if ( $existing === $awb ) {
		// Deja salvat, nu mai spamăm note.
		wp_send_json_success( array( 'saved' => $existing, 'status' => 'already_set' ) );
	}

	$order->update_meta_data( '_notice_awb', $awb );
	$order->add_order_note( sprintf( 'AWB (FAN Courier) setat automat din interfață: %s', $awb ) );
	$order->save();

	wp_send_json_success( array( 'saved' => $awb, 'status' => 'updated' ) );
}

/**
 * 2) Detecție AWB SAMEDAY din meta.
 */
add_action( 'updated_post_meta', 'sawp_detect_awb_from_sameday_meta', 10, 4 );
function sawp_detect_awb_from_sameday_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( get_post_type( $post_id ) !== 'shop_order' ) {
		return;
	}

	$key_l = strtolower( (string) $meta_key );

	if ( strpos( $key_l, 'sameday' ) === false ) {
		return;
	}
	if ( strpos( $key_l, 'awb' ) === false && strpos( $key_l, 'track' ) === false ) {
		return;
	}

	$order = wc_get_order( $post_id );
	if ( ! $order ) {
		return;
	}

	$awb_source = sawp_get_awb_source();

	if ( ! in_array( $awb_source, array( 'auto', 'sameday' ), true ) ) {
		return;
	}

	$existing = (string) $order->get_meta( '_notice_awb' );

	if ( 'auto' === $awb_source && $existing !== '' ) {
		return;
	}

	$candidate = '';

	if ( is_string( $meta_value ) && $meta_value !== '' ) {
		$candidate = $meta_value;
	} elseif ( is_array( $meta_value ) ) {
		foreach ( array( 'awb', 'awbNumber', 'awb_number', 'tracking_number', 'trackingNumber', 'parcel_awb', 'sameday_awb', 'sameday_awb_number' ) as $kk ) {
			if ( ! empty( $meta_value[ $kk ] ) ) {
				$candidate = (string) $meta_value[ $kk ];
				break;
			}
		}

		if ( ! $candidate ) {
			foreach ( array( 'parcels', 'parcel', 'packages', 'package' ) as $kk ) {
				if ( ! empty( $meta_value[ $kk ] ) && is_array( $meta_value[ $kk ] ) ) {
					foreach ( (array) $meta_value[ $kk ] as $p ) {
						if ( is_array( $p ) ) {
							foreach ( array( 'awb', 'awbNumber', 'awb_number', 'tracking_number', 'trackingNumber' ) as $kk2 ) {
								if ( ! empty( $p[ $kk2 ] ) ) {
									$candidate = (string) $p[ $kk2 ];
									break 3;
								}
							}
						}
					}
				}
			}
		}
	}

	if ( ! $candidate ) {
		return;
	}

	$candidate = sawp_normalize_awb( $candidate );

	if ( ! preg_match( '/^[A-Z0-9]{10,30}$/', $candidate ) ) {
		return;
	}

	$order->update_meta_data( '_notice_awb', $candidate );
	$order->add_order_note( sprintf( 'AWB (Sameday) setat automat: %s', $candidate ) );
	$order->save();
}

/**
 * 3) Detecție AWB FAN Courier din meta (fallback, dacă pluginul FAN salvează meta).
 */
add_action( 'updated_post_meta', 'sawp_detect_awb_from_fan_meta', 11, 4 );
function sawp_detect_awb_from_fan_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( get_post_type( $post_id ) !== 'shop_order' ) {
		return;
	}

	$key_l = strtolower( (string) $meta_key );

	// Fallback generic: ORICE meta care conține awb/track.
	if ( strpos( $key_l, 'awb' ) === false && strpos( $key_l, 'track' ) === false ) {
		return;
	}

	$order = wc_get_order( $post_id );
	if ( ! $order ) {
		return;
	}

	$awb_source = sawp_get_awb_source();

	if ( ! in_array( $awb_source, array( 'auto', 'fan' ), true ) ) {
		return;
	}

	$existing = (string) $order->get_meta( '_notice_awb' );

	if ( 'auto' === $awb_source && $existing !== '' ) {
		return;
	}

	$candidate = '';

	if ( is_string( $meta_value ) && $meta_value !== '' ) {
		$candidate = $meta_value;
	} elseif ( is_array( $meta_value ) ) {
		foreach ( array( 'awb', 'awbNumber', 'awb_number', 'tracking_number', 'trackingNumber', 'fan_awb', 'fancourier_awb' ) as $kk ) {
			if ( ! empty( $meta_value[ $kk ] ) ) {
				$candidate = (string) $meta_value[ $kk ];
				break;
			}
		}

		if ( ! $candidate ) {
			foreach ( array( 'parcels', 'parcel', 'packages', 'package' ) as $kk ) {
				if ( ! empty( $meta_value[ $kk ] ) && is_array( $meta_value[ $kk ] ) ) {
					foreach ( (array) $meta_value[ $kk ] as $p ) {
						if ( is_array( $p ) ) {
							foreach ( array( 'awb', 'awbNumber', 'awb_number', 'tracking_number', 'trackingNumber' ) as $kk2 ) {
								if ( ! empty( $p[ $kk2 ] ) ) {
									$candidate = (string) $p[ $kk2 ];
									break 3;
								}
							}
						}
					}
				}
			}
		}
	}

	if ( ! $candidate ) {
		return;
	}

	$candidate = sawp_normalize_awb( $candidate );

	if ( ! preg_match( '/^[0-9]{6,20}$/', $candidate ) ) {
		return;
	}

	$order->update_meta_data( '_notice_awb', $candidate );
	$order->add_order_note( sprintf( 'AWB (FAN Courier) setat automat (fallback meta): %s', $candidate ) );
	$order->save();
}

/**
 * 4) Detecție AWB din meta Curiero (plugin Curiero/CurieRO).
 * Curiero salvează de obicei meta-uri de tip awb_fan, awb_sameday, awb_dpd etc.
 */
add_action( 'updated_post_meta', 'sawp_detect_awb_from_curiero_meta', 12, 4 );
function sawp_detect_awb_from_curiero_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( get_post_type( $post_id ) !== 'shop_order' ) {
		return;
	}

	$order = wc_get_order( $post_id );
	if ( ! $order ) {
		return;
	}

	$awb_source = sawp_get_awb_source();
	if ( ! in_array( $awb_source, array( 'auto', 'curiero' ), true ) ) {
		return;
	}

	$existing = (string) $order->get_meta( '_notice_awb' );
	// În Auto nu suprascriem un AWB deja setat.
	if ( 'auto' === $awb_source && $existing !== '' ) {
		return;
	}

	$key_l = strtolower( (string) $meta_key );

	// Chei tipice Curiero: awb_fan, awb_sameday, awb_dpd, awb_urgent_cargus, awb_gls, awb_mygls, awb_bookurier, awb_innoship, awb_memex, awb_optimus_id etc.
	$is_curiero_key = ( strpos( $key_l, 'awb_' ) === 0 ) || ( strpos( $key_l, 'curiero' ) !== false );

	if ( ! $is_curiero_key ) {
		return;
	}

	$candidate = '';

	if ( is_string( $meta_value ) && $meta_value !== '' ) {
		$candidate = $meta_value;
	} elseif ( is_array( $meta_value ) ) {
		foreach ( array( 'awb', 'awbNumber', 'awb_number', 'tracking_number', 'trackingNumber' ) as $kk ) {
			if ( ! empty( $meta_value[ $kk ] ) ) {
				$candidate = (string) $meta_value[ $kk ];
				break;
			}
		}
	}

	if ( ! $candidate ) {
		return;
	}

	$candidate = sawp_normalize_awb( $candidate );

	// Curiero poate avea AWB numeric sau alfanumeric, deci validare mai permisivă.
	if ( ! preg_match( '/^[A-Z0-9]{6,30}$/', $candidate ) ) {
		return;
	}

	$order->update_meta_data( '_notice_awb', $candidate );
	$order->add_order_note( sprintf( 'AWB (Curiero) setat automat: %s', $candidate ) );
	$order->save();
}

/**
 * Sync AWB din link-uri de tip ...#awb=XXXX (ex: Curiero/Sameday UI) în _notice_awb
 */
add_action( 'admin_footer', 'notice_sync_curiero_awb_from_link' );
function notice_sync_curiero_awb_from_link() {
	if ( ! function_exists( 'get_current_screen' ) ) return;
	$screen = get_current_screen();
	if ( ! $screen ) return;

	// Woo orders (HPOS) sau comanda clasică
	if ( $screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'shop_order' ) return;

	$awb_source = sawp_get_awb_source();
	// Rulează doar dacă e curiero sau auto (ca să nu facă nimic când ai selectat altceva)
	if ( ! in_array( $awb_source, array( 'curiero', 'auto' ), true ) ) return;

	$nonce = wp_create_nonce( 'notice_sync_curiero_awb' );
	?>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			function getOrderId() {
				try {
					var u = new URL(location.href);
					return u.searchParams.get('id') || u.searchParams.get('post') ||
						(document.querySelector('input[name="post_ID"]') ? document.querySelector('input[name="post_ID"]').value : '');
				} catch(e) { return ''; }
			}

			// caută link cu #awb=
			var a = document.querySelector('a[href*="#awb="]');
			if (!a) return;

			var href = a.getAttribute('href') || '';
			var m = href.match(/[#?&]awb=([^&]+)/i);
			var awb = m ? decodeURIComponent(m[1]) : '';
			var orderId = getOrderId();

			if (!awb || !orderId) return;
			if (typeof ajaxurl === 'undefined') return;

			var fd = new FormData();
			fd.append('action', 'notice_sync_curiero_awb');
			fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
			fd.append('order_id', orderId);
			fd.append('awb', awb);

			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function(r){ return r.text(); })
				.catch(function(){ console.warn('Nu s-a putut sincroniza AWB-ul Curiero.'); });
		});
	</script>
	<?php
}

/**
 * AJAX handler: salvează AWB (din link #awb=) în meta _notice_awb.
 */
add_action( 'wp_ajax_notice_sync_curiero_awb', 'notice_sync_curiero_awb' );
function notice_sync_curiero_awb() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => 'no_permission' ) );
	}

	check_ajax_referer( 'notice_sync_curiero_awb', 'nonce' );

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$awb      = isset( $_POST['awb'] ) ? sanitize_text_field( wp_unslash( $_POST['awb'] ) ) : '';

	if ( ! $order_id || '' === $awb ) {
		wp_send_json_error( array( 'error' => 'missing_data' ) );
	}

	$awb = sawp_normalize_awb( $awb );

	// Sameday-style: alfanumeric (ex: 1GAV...)
	if ( ! preg_match( '/^[A-Z0-9]{8,30}$/', $awb ) ) {
		wp_send_json_error( array( 'error' => 'invalid_awb' ) );
	}

	$awb_source = sawp_get_awb_source();
	if ( ! in_array( $awb_source, array( 'curiero', 'auto' ), true ) ) {
		wp_send_json_error( array( 'error' => 'source_not_curiero' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'error' => 'no_order' ) );
	}

	$existing = (string) $order->get_meta( '_notice_awb' );

	// în Auto nu suprascriem dacă există deja ceva
	if ( 'auto' === $awb_source && $existing !== '' ) {
		wp_send_json_success( array( 'saved' => $existing, 'status' => 'auto_kept_existing' ) );
	}

	if ( $existing === $awb ) {
		wp_send_json_success( array( 'saved' => $existing, 'status' => 'already_set' ) );
	}

	$order->update_meta_data( '_notice_awb', $awb );
	$order->add_order_note( sprintf( 'AWB (Curiero/link #awb) setat automat: %s', $awb ) );
	$order->save();

	wp_send_json_success( array( 'saved' => $awb, 'status' => 'updated' ) );
}
