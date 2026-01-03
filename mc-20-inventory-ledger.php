<?php
/**
 * MC 20 - Inventory Ledger Viewer (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Show detailed physical stock mutations (per pcs/lembar)
 *
 * Improvements:
 *  - Capability gate
 *  - Safe queries and escaping
 *  - Keep original filter by date
 */

defined('ABSPATH') || exit;

function puri_render_inventory_ledger_page() {
    puri_check_cap('manage_options');
    global $wpdb;
    $filter_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');

    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT l.*, i.sku, i.denom_value 
        FROM " . puri_table_name('T_LEDGER') . " l
        JOIN " . puri_table_name('T_ITEMS') . " i ON l.item_id = i.id
        WHERE DATE(l.trx_date) = %s
        ORDER BY l.id DESC
    ", $filter_date));

    ?>
    <div class="wrap">
      <h1>📦 Jurnal Persediaan (Stock Ledger)</h1>
      <form method="get"><input type="hidden" name="page" value="puri-inventory-ledger"><label>Filter Tanggal:</label> <input type="date" name="date" value="<?php echo esc_attr($filter_date); ?>"><button class="button" type="submit">TAMPILKAN</button></form>

      <table class="widefat striped">
        <thead><tr><th>Waktu</th><th>Ref ID</th><th>Lokasi</th><th>SKU Item</th><th>Mutasi (Pcs)</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Belum ada mutasi stok pada tanggal ini.</td></tr>
        <?php else: foreach ($logs as $l): ?>
          <tr>
            <td><?php echo esc_html(date('H:i:s', strtotime($l->trx_date))); ?></td>
            <td><code><?php echo esc_html($l->ref_id); ?></code></td>
            <td><span style="background:#f1f5f9;padding:3px 8px;border-radius:5px"><?php echo esc_html(strtoupper(str_replace('_',' ',$l->location_id))); ?></span></td>
            <td><strong><?php echo esc_html($l->sku); ?></strong> (SAR <?php echo esc_html($l->denom_value); ?>)</td>
            <td style="font-weight:800;color:<?php echo $l->qty_change>0?'#059669':'#e11d48'; ?>"><?php echo ($l->qty_change>0?'+':'') . number_format($l->qty_change); ?></td>
            <td><small><?php echo esc_html($l->description); ?></small></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}