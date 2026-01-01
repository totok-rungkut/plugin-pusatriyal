<?php
/**
 * MC 04 - Procurement Hub (Pembelian Brot)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Admin form to record procurement (kulakan) into Gudang Utama.
 *  - Update stock, ledger, HPP (moving avg), and post accounting journals.
 *
 * Improvements v6.0.1:
 *  - Nonce protection (puri_admin_action) and capability checks.
 *  - Sanitization & strict validation of posted payload.
 *  - Atomic stock updates via $puri_engine->update_stock_atomic.
 *  - DB transaction around multi-step operations to preserve consistency.
 *  - Proper escaping on HTML outputs.
 *
 * Note:
 *  - Requires MC_01 (tables) and MC_03 (engine) active.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    // Menu registration is centralized in MC 00; assume submenu points to puri-procurement
    // If needed, MC00 registers the link to this render function.
});

/* ------------------------
   AJAX registration
   ------------------------ */
add_action('wp_ajax_puri_get_procurement_data', 'puri_get_procurement_data_handler');
add_action('wp_ajax_puri_submit_procurement_ajax', 'puri_submit_procurement_ajax_handler');

/* ------------------------
   Render Procurement Page
   ------------------------ */
function puri_render_procurement_page() {
    puri_check_cap('manage_options');
    global $wpdb, $puri_engine;

    if (!defined('T_ITEMS') || !isset($puri_engine)) {
        echo '<div class="notice notice-error"><p><strong>⚠ ARSITEKTUR BELUM SIAP:</strong> Pastikan modul Core & Engine aktif.</p></div>';
        return;
    }

    $items = $wpdb->get_results("SELECT id, sku FROM " . puri_table_name('T_ITEMS') . " WHERE type = 'currency' ORDER BY denom_value ASC, sku ASC");
    $banks = $wpdb->get_results("SELECT code, name FROM " . puri_table_name('T_CHART') . " WHERE is_cash = 1");
    $vendors = get_posts(['post_type' => 'pr_vendor','posts_per_page' => -1,'orderby' => 'title','order' => 'ASC']);

    // Render page (keaslian markup & UX dipertahankan), tambahkan nonce field untuk AJAX
    ?>
    <div class="wrap">
    <style>
    /* minimal styles retained for readability */
    .panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:18px}
    </style>

    <div class="panel">
      <h2>📦 SALDO GUDANG UTAMA</h2>
      <div id="stock-summary-container"><em style="color:#94a3b8">Menghitung saldo…</em></div>
    </div>

    <div class="panel">
      <h2>📥 FORM KULAKAN</h2>
      <form id="procurement-form">
        <?php puri_create_admin_nonce_field(); /* Adds puri_admin_nonce */ ?>

        <div style="display:flex;gap:18px;margin-bottom:12px">
          <div style="flex:1">
            <label><strong>SUMBER DANA</strong></label>
            <select name="payment_account" required>
              <?php foreach($banks as $b): ?>
                <option value="<?php echo esc_attr($b->code); ?>"><?php echo esc_html($b->name); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:2">
            <label><strong>VENDOR</strong></label>
            <select name="vendor_post_id" required>
              <option value="">-- Pilih Vendor --</option>
              <?php foreach($vendors as $v): ?>
                <option value="<?php echo intval($v->ID); ?>"><?php echo esc_html($v->post_title); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <table>
          <thead><tr><th>ITEM</th><th>QTY (BROT)</th><th>HARGA / PCS</th><th></th></tr></thead>
          <tbody id="repeater-body">
            <tr>
              <td>
                <select name="items[0][id]" required>
                  <?php foreach($items as $it): ?>
                    <option value="<?php echo intval($it->id); ?>"><?php echo esc_html($it->sku); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" name="items[0][qty]" required></td>
              <td><input type="number" name="items[0][price]" required></td>
              <td>-</td>
            </tr>
          </tbody>
        </table>

        <br>
        <button type="button" id="add-row">+ Tambah Item</button>
        <hr>
        <button type="submit" class="button button-primary">KONFIRMASI PEMBELIAN</button>
      </form>
    </div>

    <div class="panel">
      <h2>📄 HISTORI BULAN INI</h2>
      <table>
        <thead><tr><th>Tanggal</th><th>Kode</th><th>Vendor</th><th>Snapshot</th><th style="text-align:right">IDR</th></tr></thead>
        <tbody id="history-table-body"></tbody>
      </table>
    </div>

    </div>

    <script>
    (function($){
      let idx = 1;
      $('#add-row').on('click', function(){
        const html = `<tr>
          <td><select name="items[${idx}][id]"><?php foreach($items as $it): ?><option value="<?php echo intval($it->id); ?>"><?php echo esc_js($it->sku); ?></option><?php endforeach; ?></select></td>
          <td><input type="number" name="items[${idx}][qty]" required></td>
          <td><input type="number" name="items[${idx}][price]" required></td>
          <td><button type="button" class="btn-del">Hapus</button></td>
        </tr>`;
        $('#repeater-body').append(html);
        idx++;
      });

      $(document).on('click', '.btn-del', function(){ $(this).closest('tr').remove(); });

      function refresh(){
        $.post(ajaxurl, { action: 'puri_get_procurement_data', puri_admin_nonce: jQuery('input[name="puri_admin_nonce"]').val() }, function(res){
          if(res.success){
            $('#stock-summary-container').html(res.data.stock_html);
            $('#history-table-body').html(res.data.history_html);
          } else {
            console.error(res);
          }
        });
      }
      refresh();

      $('#procurement-form').on('submit', function(e){
        e.preventDefault();
        const data = $(this).serialize();
        $.post(ajaxurl, data + '&action=puri_submit_procurement_ajax', function(res){
          if(res.success){
            alert(res.data.message);
            $('#procurement-form')[0].reset();
            $('#repeater-body').html(`<?php ob_start(); ?> <tr>
              <td><select name="items[0][id]" required><?php foreach($items as $it): ?><option value="<?php echo intval($it->id); ?>"><?php echo esc_js($it->sku); ?></option><?php endforeach; ?></select></td>
              <td><input type="number" name="items[0][qty]" required></td>
              <td><input type="number" name="items[0][price]" required></td>
              <td>-</td>
            </tr><?php echo str_replace("\n","",ob_get_clean()); ?>`);
            refresh();
          } else {
            alert('Gagal: ' + (res.data.message || 'Unknown'));
          }
        });
      });
    })(jQuery);
    </script>
    <?php
}

/* ------------------------
   Helper: Get procurement data (AJAX)
   ------------------------ */
function puri_get_procurement_data_handler() {
    // Nonce & capability: since this runs in admin context, require admin nonce
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

    global $wpdb;
    $stocks = $wpdb->get_results("
        SELECT i.sku, i.denom_value, s.qty 
        FROM " . puri_table_name('T_STOCK') . " s 
        JOIN " . puri_table_name('T_ITEMS') . " i ON s.item_id = i.id 
        WHERE s.location_id = 'gudang_utama'
        ORDER BY i.denom_value ASC, i.id ASC
    ");

    $stock_html = '';
    if ($stocks) {
        foreach ($stocks as $s) {
            $total_pcs = (float)$s->qty;
            $brot = floor($total_pcs / 1000);
            $bendel = floor(($total_pcs % 1000) / 100);
            $riyal_value = $total_pcs * (int)$s->denom_value;
            $stock_html .= "<div class='stock-card'><strong>" . esc_html($s->sku) . "</strong>
                <div><span class='st-label'>Saldo :</span> <span class='st-val'>{$brot} brot - {$bendel} bendel</span></div>
                <div><span class='st-label'>Value :</span> <span class='st-val' style='color:#059669;'>" . number_format($riyal_value) . " Riyal</span></div>
            </div>";
        }
    } else {
        $stock_html = "<p style='color:#94a3b8;'>Belum ada stok di Gudang Utama.</p>";
    }

    $history = $wpdb->get_results($wpdb->prepare("SELECT trx_date, snapshot_json, debit FROM " . puri_table_name('T_JOURNAL') . " WHERE account_code = '1401' AND MONTH(trx_date) = %d AND YEAR(trx_date) = %d ORDER BY id DESC LIMIT 10", date('m'), date('Y')));
    $history_html = '';
    if ($history) {
        foreach ($history as $h) {
            $snapshot = json_decode($h->snapshot_json, true);
            $v_code = isset($snapshot['vendor_code']) ? esc_html($snapshot['vendor_code']) : '-';
            $v_name = isset($snapshot['vendor_name']) ? esc_html($snapshot['vendor_name']) : '-';
            $snap_text = isset($snapshot['summary_text']) ? esc_html($snapshot['summary_text']) : '-';
            $history_html .= "<tr>
                <td>" . esc_html(date('d/m H:i', strtotime($h->trx_date))) . "</td>
                <td><strong>" . $v_code . "</strong></td>
                <td>" . $v_name . "</td>
                <td style='color:#475569; font-family:monospace;'>" . $snap_text . "</td>
                <td style='text-align:right; font-weight:700;'>" . number_format($h->debit) . "</td>
            </tr>";
        }
    } else {
        $history_html = "<tr><td colspan='5' style='text-align:center;'>Belum ada data histori.</td></tr>";
    }

    wp_send_json_success(['stock_html' => $stock_html, 'history_html' => $history_html]);
}

/* ------------------------
   Handler: Submit Procurement (AJAX)
   ------------------------ */
function puri_submit_procurement_ajax_handler() {
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

    global $wpdb, $puri_engine;
    if (!isset($puri_engine)) wp_send_json_error(['message' => 'Engine not available']);

    // Validate posted structure
    $items_raw = isset($_POST['items']) ? $_POST['items'] : null;
    $v_id = isset($_POST['vendor_post_id']) ? intval($_POST['vendor_post_id']) : 0;
    $pay_acc = isset($_POST['payment_account']) ? sanitize_text_field($_POST['payment_account']) : '';

    if (empty($items_raw) || !is_array($items_raw) || $v_id <= 0 || empty($pay_acc)) {
        wp_send_json_error(['message' => 'Invalid payload']);
    }

    // Prepare processing
    $v_code = get_field('vendor_code', $v_id);
    $v_name = get_the_title($v_id);
    $total_idr = 0;
    $snap_parts = [];
    $ref_id = 'PRO-' . date('Ymd-His');

    // Start DB transaction for atomicity
    $wpdb->query('START TRANSACTION');
    try {
        foreach ($items_raw as $it) {
            $id = isset($it['id']) ? intval($it['id']) : 0;
            $qty_brot = isset($it['qty']) ? intval($it['qty']) : 0;
            $price = isset($it['price']) ? floatval($it['price']) : 0.0;

            if ($id <= 0 || $qty_brot <= 0 || $price < 0) {
                throw new Exception('Invalid item entry');
            }

            $pcs = $qty_brot * 1000; // 1 Brot = 1000 Pcs
            $sku = $wpdb->get_var($wpdb->prepare("SELECT sku FROM " . puri_table_name('T_ITEMS') . " WHERE id=%d", $id));
            $total_idr += ($pcs * $price);
            $snap_parts[] = "{$qty_brot} brot x " . esc_html($sku) . " @" . number_format($price);

            // Atomic stock update
            $ok = $puri_engine->update_stock_atomic('gudang_utama', $id, $pcs);
            if (is_wp_error($ok)) throw new Exception($ok->get_error_message());

            // Ledger record (physical)
            $ins = $wpdb->insert(puri_table_name('T_LEDGER'), [
                'location_id' => 'gudang_utama',
                'item_id' => $id,
                'qty_change' => $pcs,
                'ref_id' => $ref_id,
                'description' => "Kulakan dari " . $v_name,
                'trx_date' => current_time('mysql'),
            ]);
            if ($ins === false) throw new Exception($wpdb->last_error);

            // Update moving avg
            $new_avg = $puri_engine->calculate_moving_avg($id, $pcs, $price);
            if (is_wp_error($new_avg)) {
                throw new Exception($new_avg->get_error_message());
            }
        }

        $summary_text = implode(" \n ", $snap_parts);
        $full_desc = "Kulakan:\n $summary_text \n [V:" . esc_html($v_code) . "-" . esc_html($v_name) . "]";
        $journal_snapshot = [
            'summary_text' => $summary_text,
            'vendor_code' => $v_code,
            'vendor_name' => $v_name
        ];

        // Post accounting (double entry)
        $res1 = $puri_engine->post_journal($ref_id, '1401', $total_idr, 0, $full_desc, $journal_snapshot);
        if (is_wp_error($res1)) throw new Exception($res1->get_error_message());
        $res2 = $puri_engine->post_journal($ref_id, $pay_acc, 0, $total_idr, $full_desc);
        if (is_wp_error($res2)) throw new Exception($res2->get_error_message());

        $wpdb->query('COMMIT');
        wp_send_json_success(['message' => "Sukses mencatat kulakan dari " . esc_html($v_name) . "."]);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => 'Procurement failed: ' . $e->getMessage()]);
    }
}