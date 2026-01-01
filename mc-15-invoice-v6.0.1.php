<?php
/**
 * MC 15 - Centralized Invoice & Receipt (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Printable invoice page (admin hidden submenu)
 *
 * Improvements:
 *  - Capability check
 *  - Safe lookup of journal entry (by ref_id) with defensive checks
 *  - Escaped outputs for legal/profile data and snapshot
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    // Keep submenu hidden as in prior design
    add_submenu_page(null, 'Cetak Invoice', 'Cetak Invoice', 'manage_options', 'puri-print-invoice', 'puri_render_invoice_page');
});

function puri_render_invoice_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $ref_id = isset($_GET['ref_id']) ? sanitize_text_field($_GET['ref_id']) : '';
    if (!$ref_id) wp_die('ID Transaksi tidak ditemukan.');

    $journal_entry = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM " . puri_table_name('T_JOURNAL') . " WHERE ref_id = %s AND snapshot_json IS NOT NULL LIMIT 1
    ", $ref_id));

    if (!$journal_entry) wp_die('Data transaksi tidak ditemukan atau tidak memiliki rincian.');

    $snapshot = json_decode($journal_entry->snapshot_json, true);
    $co = [
        'logo' => function_exists('get_field') ? get_field('cp_logo','option') : '',
        'name' => function_exists('get_field') ? (get_field('cp_name','option') ?: 'Pusat Riyal') : 'Pusat Riyal',
        'bi' => function_exists('get_field') ? (get_field('cp_bi_license','option') ?: '-') : '-',
        'npwp' => function_exists('get_field') ? (get_field('cp_npwp','option') ?: '-') : '-',
        'address' => function_exists('get_field') ? (get_field('cp_address','option') ?: '-') : '-',
        'city' => function_exists('get_field') ? (get_field('cp_city','option') ?: '-') : '-',
        'phone' => function_exists('get_field') ? (get_field('cp_phone','option') ?: '-') : '-',
    ];
    ?>
    <style>
      @media print { .no-print { display:none!important } #adminmenuback,#adminmenuwrap,#wpadminbar,#wpfooter { display:none!important } body{margin:0;padding:0} }
      body{font-family:Courier,monospace;background:#fff;color:#000;padding:20px}
      .invoice-box{max-width:400px;margin:auto;border:1px solid #eee;padding:16px}
    </style>

    <div class="no-print" style="text-align:center;margin-bottom:12px">
      <button onclick="window.print()" class="button button-primary">🖨️ CETAK INVOICE</button>
      <button onclick="window.close()" class="button">TUTUP</button>
    </div>

    <div class="invoice-box">
      <div style="text-align:center;border-bottom:2px dashed #000;padding-bottom:8px;margin-bottom:8px">
        <?php if ($co['logo']): ?><img src="<?php echo esc_url($co['logo']); ?>" style="max-height:60px;margin-bottom:8px"><br><?php endif; ?>
        <strong style="display:block;font-size:16px"><?php echo esc_html($co['name']); ?></strong>
        <small>Izin BI: <?php echo esc_html($co['bi']); ?> | NPWP: <?php echo esc_html($co['npwp']); ?></small><br>
        <small><?php echo esc_html($co['address'] . ', ' . $co['city']); ?> | Telp: <?php echo esc_html($co['phone']); ?></small>
      </div>

      <table style="width:100%;font-size:13px">
        <tr><td>No. Ref</td><td>: <?php echo esc_html($ref_id); ?></td></tr>
        <tr><td>Tanggal</td><td>: <?php echo esc_html(date('d/m/Y H:i', strtotime($journal_entry->trx_date))); ?></td></tr>
        <tr><td>Customer</td><td>: <?php echo esc_html($snapshot['customer'] ?? 'Umum'); ?></td></tr>
        <tr><td>Kasir</td><td>: <?php echo esc_html(get_the_author_meta('display_name', $journal_entry->created_by)); ?></td></tr>
      </table>

      <hr>

      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <thead><tr><th style="text-align:left">Deskripsi</th><th style="text-align:right">Qty</th><th style="text-align:right">Total IDR</th></tr></thead>
        <tbody>
          <?php if (!empty($snapshot['items']) && is_array($snapshot['items'])): foreach ($snapshot['items'] as $it): ?>
            <tr>
              <td><?php echo esc_html($it['sku'] . ' ' . ($it['type']=='package' ? '(Paket)' : 'SAR '.$it['denom_value'])); ?></td>
              <td style="text-align:right"><?php echo number_format(intval($it['qty'])); ?></td>
              <td style="text-align:right"><?php echo number_format(intval($it['qty'] * ($it['denom_value'] ?? $it['denom'] ?? 1) * (($it['sell_rate'] ?? 0) - ($it['discount_rate'] ?? 0)))); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <?php if (!empty($snapshot['inventory_explode'])): ?>
        <div style="background:#f9f9f9;padding:8px;margin-top:8px;border-radius:6px">
          <strong>Rincian Fisik (Isi Paket)</strong>
          <?php foreach ($snapshot['inventory_explode'] as $ex): ?>
            <div style="display:flex;justify-content:space-between"><span>• <?php echo esc_html($ex['sku']); ?></span><span><?php echo number_format(intval($ex['qty_intrinsik'])); ?> Lembar</span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div style="border-top:2px dashed #000;margin-top:10px;padding-top:8px;text-align:right">
        <div>Total SAR: <?php echo number_format(intval($snapshot['total_riyal'] ?? 0)); ?></div>
        <div style="font-weight:900;font-size:18px">TOTAL BAYAR (IDR): Rp <?php echo number_format(intval($snapshot['total_idr'] ?? 0)); ?></div>
      </div>

      <div style="text-align:center;margin-top:12px;font-size:11px">Terima kasih telah bertransaksi di <?php echo esc_html($co['name']); ?>.</div>
    </div>
    <?php
}