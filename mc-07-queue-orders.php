<?php
/**
 * MC 07 - Queue & Order Manager (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Manage web bookings (submitted from MC_06) into a SQL order queue table
 *  - Provide admin UI to view and process orders (move to POS)
 *
 * Improvements:
 *  - Define T_ORDERS constant centrally here
 *  - Create orders table via dbDelta, idempotent
 *  - Provide wp_ajax and wp_ajax_nopriv for puri_submit_booking (validate public nonce)
 *  - Admin UI: sanitize outputs and require manage_options for management actions
 */

defined('ABSPATH') || exit;

// Define orders table constant if not present
if (!defined('T_ORDERS')) define('T_ORDERS', 'puri_orders');

add_action('admin_init', function() {
    // Auto-create orders table if admin
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $table = $wpdb->prefix . T_ORDERS;
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_date datetime DEFAULT CURRENT_TIMESTAMP,
        cust_name varchar(100) NOT NULL,
        cust_phone varchar(20),
        items_json json NOT NULL,
        total_idr decimal(19,4) DEFAULT 0,
        status enum('pending','processed','cancelled') DEFAULT 'pending',
        PRIMARY KEY (id)
    ) $collate;";
    dbDelta($sql);
});

/* ------------------------
   Admin page render (Queue)
   ------------------------ */
function puri_render_queue_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    // Cancel action
    if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $wpdb->update($wpdb->prefix . T_ORDERS, ['status' => 'cancelled'], ['id' => $id]);
    }

    $orders = $wpdb->get_results("SELECT * FROM " . puri_table_name('T_ORDERS') . " WHERE status = 'pending' ORDER BY order_date DESC");
    ?>
    <div class="wrap">
      <h1>📋 Antrean Reservasi Mandiri</h1>
      <p style="color:#64748b">Klik "Proses ke POS" untuk memindahkan data antrean ke layar Kasir.</p>

      <table class="widefat striped">
        <thead><tr><th>Waktu</th><th>Customer</th><th>Rincian Pesanan</th><th>Total Estimasi</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (!$orders): ?>
            <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Tidak ada antrean saat ini.</td></tr>
        <?php else: foreach ($orders as $ord): $items = json_decode($ord->items_json); ?>
            <tr>
                <td><?php echo esc_html(date('H:i', strtotime($ord->order_date))); ?><br><small><?php echo esc_html(date('d/m', strtotime($ord->order_date))); ?></small></td>
                <td><strong><?php echo esc_html($ord->cust_name); ?></strong><br><a href="https://wa.me/<?php echo esc_attr($ord->cust_phone); ?>" target="_blank" class="button">📲 WhatsApp</a></td>
                <td><ul style="margin:0;padding:0;list-style:none;"><?php foreach ($items as $it) echo '<li>• ' . esc_html($it->sku) . ' (' . intval($it->qty) . ')</li>'; ?></ul></td>
                <td><strong style="color:#059669">Rp <?php echo number_format($ord->total_idr); ?></strong></td>
                <td>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=puri-pos&order_id=' . intval($ord->id))); ?>">🚀 PROSES KE POS</a>
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=puri-queue&action=cancel&id=' . intval($ord->id))); ?>" onclick="return confirm('Batalkan pesanan?')">BATAL</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/* ------------------------
   AJAX: submit booking (from frontend)
   - Accepts both logged-in and guest (wp_ajax_nopriv)
   - Validates public nonce 'puri_public_action'
   ------------------------ */
add_action('wp_ajax_puri_submit_booking', 'puri_submit_booking_handler');
add_action('wp_ajax_nopriv_puri_submit_booking', 'puri_submit_booking_handler');

function puri_submit_booking_handler() {
    // Validate nonce (public)
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'puri_public_action')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }

    global $wpdb;
    $payload_raw = isset($_POST['payload']) ? $_POST['payload'] : null;
    if (is_null($payload_raw)) wp_send_json_error(['message' => 'Empty payload']);

    $payload = json_decode(stripslashes($payload_raw), true);
    if (!is_array($payload) || empty($payload['cart']) || empty($payload['name'])) {
        wp_send_json_error(['message' => 'Invalid booking payload']);
    }

    $cust_name = sanitize_text_field($payload['name']);
    $cust_phone = sanitize_text_field($payload['phone'] ?? '');
    $items_json = wp_json_encode($payload['cart']);
    $total_idr = floatval($payload['total_idr'] ?? 0);

    $ins = $wpdb->insert($wpdb->prefix . T_ORDERS, [
        'cust_name' => $cust_name,
        'cust_phone' => $cust_phone,
        'items_json' => $items_json,
        'total_idr' => $total_idr,
        'status' => 'pending',
        'order_date' => current_time('mysql')
    ]);
    if ($ins === false) wp_send_json_error(['message' => 'DB insert failed']);

    wp_send_json_success(['order_id' => $wpdb->insert_id]);
}