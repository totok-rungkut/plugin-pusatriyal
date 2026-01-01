<?php
/**
 * MC 22 - Pivot Sales Explorer (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Pivot/aggregate sales snapshot data into per-SKU metrics
 *
 * Improvements:
 *  - Capability gate
 *  - Support filters: daily/monthly/yearly/range
 *  - Defensive parsing and aggregation
 */

defined('ABSPATH') || exit;

function puri_render_pivot_report_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $filter_type = isset($_GET['ftype']) ? sanitize_text_field($_GET['ftype']) : 'daily';
    $start_date = isset($_GET['sdate']) ? sanitize_text_field($_GET['sdate']) : current_time('Y-m-d');
    $end_date = isset($_GET['edate']) ? sanitize_text_field($_GET['edate']) : $start_date;

    if ($filter_type === 'daily') {
        $where = $wpdb->prepare("DATE(j.trx_date) = %s", $start_date);
    } elseif ($filter_type === 'monthly') {
        $m = date('m', strtotime($start_date)); $y = date('Y', strtotime($start_date));
        $where = $wpdb->prepare("MONTH(j.trx_date) = %s AND YEAR(j.trx_date) = %s", $m, $y);
    } elseif ($filter_type === 'yearly') {
        $y = date('Y', strtotime($start_date));
        $where = $wpdb->prepare("YEAR(j.trx_date) = %s", $y);
    } else {
        $where = $wpdb->prepare("DATE(j.trx_date) BETWEEN %s AND %s", $start_date, $end_date);
    }

    $sales_data = $wpdb->get_results("
        SELECT snapshot_json, trx_date 
        FROM " . puri_table_name('T_JOURNAL') . " j
        WHERE account_code = '4100' AND snapshot_json IS NOT NULL
    " . ($where ? " AND {$where}" : "") );

    $pivot = [];
    if ($sales_data) {
        foreach ($sales_data as $row) {
            $snap = json_decode($row->snapshot_json, true);
            if (!isset($snap['items']) || !is_array($snap['items'])) continue;
            foreach ($snap['items'] as $it) {
                $sku = sanitize_text_field($it['sku'] ?? '');
                if ($sku === '') continue;
                if (!isset($pivot[$sku])) $pivot[$sku] = ['name'=>sanitize_text_field($it['name'] ?? ''),'total_qty'=>0,'total_sar'=>0,'total_idr'=>0,'avg_hpp'=>floatval($it['base_price'] ?? 0)];
                $qty = intval($it['qty'] ?? 0);
                $denom = intval($it['denom'] ?? ($it['denom_value'] ?? 1));
                $rate_net = floatval($it['rate'] ?? ($it['sell_rate'] ?? 0)) - floatval($it['discount_rate'] ?? 0);
                $pivot[$sku]['total_qty'] += $qty;
                $pivot[$sku]['total_sar'] += ($qty * $denom);
                $pivot[$sku]['total_idr'] += ($qty * $denom * $rate_net);
            }
        }
    }

    ?>
    <div class="wrap">
      <h1>🔎 Pivot Explorer: Penjualan Item</h1>
      <form method="get" style="margin-bottom:12px">
        <input type="hidden" name="page" value="puri-pivot-report">
        <label>Tipe:</label>
        <select name="ftype"><option value="daily" <?php selected($filter_type,'daily'); ?>>Harian</option><option value="monthly" <?php selected($filter_type,'monthly'); ?>>Bulanan</option><option value="yearly" <?php selected($filter_type,'yearly'); ?>>Tahunan</option><option value="range" <?php selected($filter_type,'range'); ?>>Range</option></select>
        <input type="date" name="sdate" value="<?php echo esc_attr($start_date); ?>">
        <?php if ($filter_type === 'range'): ?><input type="date" name="edate" value="<?php echo esc_attr($end_date); ?>"><?php endif; ?>
        <button class="button" type="submit">TAMPILKAN DATA</button>
      </form>

      <table class="widefat striped">
        <thead><tr><th>SKU & Nama Item</th><th style="text-align:center">Total Qty</th><th style="text-align:center">Total SAR</th><th style="text-align:right">Total Omzet (IDR)</th><th style="text-align:right">Est. Margin</th></tr></thead>
        <tbody>
        <?php if (empty($pivot)): ?>
          <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Tidak ada data penjualan untuk periode ini.</td></tr>
        <?php else:
          $grand_total_idr = 0; $grand_total_margin = 0;
          foreach ($pivot as $sku => $d):
            $hpp_total = $d['total_sar'] * $d['avg_hpp'];
            $margin = $d['total_idr'] - $hpp_total;
            $grand_total_idr += $d['total_idr'];
            $grand_total_margin += $margin;
        ?>
          <tr>
            <td><code><?php echo esc_html($sku); ?></code><br><strong><?php echo esc_html($d['name']); ?></strong></td>
            <td style="text-align:center"><?php echo number_format($d['total_qty']); ?></td>
            <td style="text-align:center"><?php echo number_format($d['total_sar']); ?> SAR</td>
            <td style="text-align:right;color:#059669">Rp <?php echo number_format($d['total_idr']); ?></td>
            <td style="text-align:right">Rp <?php echo number_format($margin); ?></td>
          </tr>
        <?php endforeach; ?>
          <tr style="background:#f8fafc;font-weight:900"><td colspan="3" style="text-align:right">TOTAL KESELURUHAN</td><td style="text-align:right;color:#059669">Rp <?php echo number_format($grand_total_idr); ?></td><td style="text-align:right">Rp <?php echo number_format($grand_total_margin); ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}