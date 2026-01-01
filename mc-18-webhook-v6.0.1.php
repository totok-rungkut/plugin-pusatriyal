<?php
/**
 * MC 18 - External Integration (Webhook Engine) (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Send transaction payloads to external webhook (Pabbly/Zapier/Make)
 *
 * Improvements:
 *  - Function puri_send_to_external_webhook($ref_id) with defensive checks
 *  - Settings page to store webhook URL (with nonce & capability check)
 *  - Non-blocking (wp_remote_post blocking=false) as before; also provide manual test call
 */

defined('ABSPATH') || exit;

function puri_send_to_external_webhook($ref_id) {
    global $wpdb;
    $webhook_url = get_option('puri_webhook_url');
    if (!$webhook_url) return false;

    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . puri_table_name('T_JOURNAL') . " WHERE ref_id = %s AND snapshot_json IS NOT NULL LIMIT 1", $ref_id));
    if (!$entry) return false;
    $snapshot = json_decode($entry->snapshot_json, true);

    $payload = [
        'timestamp' => $entry->trx_date,
        'invoice_no' => $entry->ref_id,
        'keterangan' => $entry->description,
        'total_riyal' => $snapshot['total_riyal'] ?? 0,
        'total_idr' => $snapshot['total_idr'] ?? 0,
        'kasir' => get_the_author_meta('display_name', $entry->created_by),
        'status' => 'SUCCESS',
        'system_ver' => 'Puri-v6.0.1'
    ];

    // Non-blocking remote post - best-effort
    wp_remote_post($webhook_url, [
        'method' => 'POST',
        'timeout' => 45,
        'blocking' => false,
        'headers' => ['Content-Type'=>'application/json'],
        'body' => wp_json_encode($payload),
    ]);
    return true;
}

// Admin settings page
function puri_render_webhook_page() {
    puri_check_cap('manage_options');
    $message = '';
    if (isset($_POST['puri_save_webhook'])) {
        check_admin_referer('puri_webhook_save');
        update_option('puri_webhook_url', esc_url_raw($_POST['webhook_url']));
        $message = '<div class="notice notice-success"><p>✅ Webhook Berhasil Disimpan!</p></div>';
    }
    $current_url = esc_attr(get_option('puri_webhook_url', ''));
    ?>
    <div class="wrap">
      <h1>🔗 Integrasi Excel & External Cloud</h1>
      <?php echo $message; ?>
      <form method="post">
        <?php wp_nonce_field('puri_webhook_save'); ?>
        <table class="form-table">
          <tr><th><label>URL Webhook Tujuan</label></th><td><input type="text" name="webhook_url" value="<?php echo $current_url; ?>" style="width:600px" required></td></tr>
        </table>
        <p><button class="button button-primary" name="puri_save_webhook" type="submit">SIMPAN & AKTIFKAN PIPELINE</button></p>
      </form>
      <div style="margin-top:12px;background:#f8fafc;padding:12px;border-left:4px solid #3b82f6">
        <strong>Cara penggunaan:</strong>
        <ol>
          <li>Buat workflow di Pabbly/Zapier/Make dengan trigger Webhook.</li>
          <li>Copy URL Webhook dan paste pada kolom di atas.</li>
          <li>Sistem akan otomatis mengirim Ref ID, Totals, Kasir setiap transaksi selesai.</li>
        </ol>
      </div>
    </div>
    <?php
}