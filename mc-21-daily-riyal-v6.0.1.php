<?php
/**
 * MC 21 - Daily Cash & Riyal Report (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Daily mutation report combined (Riyal & IDR)
 *
 * Improvements:
 *  - Capability gate
 *  - Safer snapshot explode (handles package composition)
 *  - Print CSS to hide WP admin chrome
 */

defined('ABSPATH') || exit;

function puri_render_riyal_mutation_report() {
    puri_check_cap('manage_options');
    global $wpdb;
    $filter_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT j.ref_id, MIN(j.trx_date) as tgl, MIN(j.description) as deskripsi, SUM(j.debit - j.credit) as total_idr,
        (SELECT GROUP_CONCAT(CONCAT(l.item_id, ':', l.qty_change)) FROM " . puri_table_name('T_LEDGER') . " l WHERE l.ref_id = j.ref_id) as item_payload
        FROM " . puri_table_name('T_JOURNAL') . " j
        WHERE j.account_code = '1101' AND DATE(j.trx_date) = %s
        GROUP BY j.ref_id
        ORDER BY tgl ASC
    ", $filter_date));

    ?>
    <style>
      @media print { #wpadminbar,#adminmenuback,#adminmenuwrap,#wpfooter,.no-print{display:none!important} body{margin:0;padding:0} }
    </style>

    <div class="wrap">
      <h1>LAPORAN MUTASI RIYAL & KAS</h1>
      <p>Periode: <?php echo esc_html(date('d F Y', strtotime($filter_date))); ?></p>

      <form method="get" class="no-print"><input type="hidden" name="page" value="puri-riyal-report"><label>Pilih Tanggal:</label> <input type="date" name="date" value="<?php echo esc_attr($filter_date); ?>"> <button class="button" type="submit">TAMPILKAN</button> <button type="button" onclick="window.print()" class="button">🖨 CETAK LAPORAN</button></form>

      <table class="widefat striped">
        <thead><tr><th>Waktu</th><th>Deskripsi</th><th style="text-align:right">Mutasi Riyal</th><th style="text-align:right">Mutasi IDR</th><th class="no-print">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$results): ?>
          <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">--- Tidak ada mutasi uang pada tanggal ini ---</td></tr>
        <?php else: foreach ($results as $r):
            $net_riyal = 0;
            if (!empty($r->item_payload)) {
                $pairs = explode(',', $r->item_payload);
                foreach ($pairs as $p) {
                    list($item_id,$qty_change) = array_map('intval', explode(':',$p));
                    $it = $wpdb->get_row($wpdb->prepare("SELECT type, sku, denom_value FROM " . puri_table_name('T_ITEMS') . " WHERE id = %d", $item_id));
                    if (!$it) continue;
                    if ($it->type === 'package') {
                        // explode package recipe to compute intrinsic SAR value
                        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='item_sku_code' AND meta_value=%s LIMIT 1", $it->sku));
                        $recipe = get_field('package_contents', $post_id);
                        $bundle_val = 0;
                        if (is_array($recipe)) {
                            foreach ($recipe as $comp) {
                                $c_post_ref = intval($comp['p_item_ref']);
                                $c_sku = get_field('item_sku_code', $c_post_ref);
                                $c_denom = $wpdb->get_var($wpdb->prepare("SELECT denom_value FROM " . puri_table_name('T_ITEMS') . " WHERE sku = %s", $c_sku));
                                $bundle_val += intval($comp['p_qty']) * intval($c_denom);
                            }
                        }
                        $net_riyal += ($qty_change * $bundle_val);
                    } else {
                        $net_riyal += ($qty_change * ($it->denom_value ?? 1));
                    }
                }
            }
        ?>
          <tr>
            <td><?php echo esc_html(date('d/m/y H:i', strtotime($r->tgl))); ?><br><small><?php echo esc_html($r->ref_id); ?></small></td>
            <td><?php echo nl2br(esc_html($r->deskripsi)); ?></td>
            <td style="text-align:right;font-weight:800"><?php echo number_format(abs($net_riyal)) . ' SAR'; ?> <?php echo $net_riyal<0?'<span style="color:red">(CR)</span>':'<span style="color:green">(DB)</span>'; ?></td>
            <td style="text-align:right;color:#2e7d32;font-weight:800">Rp <?php echo number_format(abs($r->total_idr)); ?></td>
            <td class="no-print" style="text-align:right"><?php if (strpos($r->ref_id,'SLS-')===0): ?><a href="<?php echo esc_url(admin_url('admin.php?page=puri-print-invoice&ref_id=' . $r->ref_id)); ?>" target="_blank" class="button">📄 CETAK INVOICE</a><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}