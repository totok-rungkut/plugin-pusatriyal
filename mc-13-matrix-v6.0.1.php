<?php
/**
 * MC 13 - Sales Matrix & Pivot Report (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Analyze SKU performance: quantity, SAR volume, IDR omzet, and margin estimates.
 *
 * Improvements:
 *  - Capability check for admin access
 *  - Safe reading of snapshot_json, defense against malformed JSON
 *  - Uses puri_table_name() helper for tables
 *  - Escape outputs for safe display
 */

defined('ABSPATH') || exit;

function puri_render_matrix_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $m = isset($_GET['m']) ? intval($_GET['m']) : date('m');
    $y = isset($_GET['y']) ? intval($_GET['y']) : date('Y');

    $sales_logs = $wpdb->get_results($wpdb->prepare("
        SELECT snapshot_json 
        FROM " . puri_table_name('T_JOURNAL') . " 
        WHERE account_code = '4100' 
          AND MONTH(trx_date) = %d AND YEAR(trx_date) = %d
          AND snapshot_json IS NOT NULL
    ", $m, $y));

    $matrix = [];
    if ($sales_logs) {
        foreach ($sales_logs as $log) {
            $data = json_decode($log->snapshot_json, true);
            if (!is_array($data) || !isset($data['items'])) continue;
            foreach ($data['items'] as $item) {
                $sku = sanitize_text_field($item['sku'] ?? '');
                if ($sku === '') continue;
                if (!isset($matrix[$sku])) {
                    $matrix[$sku] = [
                        'name' => sanitize_text_field($item['name'] ?? ''),
                        'qty' => 0,
                        'total_sar' => 0,
                        'total_idr' => 0,
                        'avg_hpp' => floatval($item['base_price'] ?? 0),
                    ];
                }
                $qty = intval($item['qty'] ?? 0);
                $denom = intval($item['denom'] ?? ($item['denom_value'] ?? 1));
                $rate = floatval($item['rate'] ?? ($item['sell_rate'] ?? 0));
                $matrix[$sku]['qty'] += $qty;
                $matrix[$sku]['total_sar'] += ($qty * $denom);
                $matrix[$sku]['total_idr'] += ($qty * $denom * $rate);
            }
        }
    }

    ?>
    <div class="wrap">
      <h1>📈 Matriks Penjualan & Analisis Margin</h1>
      <p style="color:#64748b">Period: <?php echo esc_html(date('F', mktime(0,0,0,$m,1)) . " {$y}"); ?></p>

      <table class="widefat striped">
        <thead><tr><th>SKU / Nama Item</th><th style="text-align:center">Volume (SAR)</th><th style="text-align:right">Omzet (IDR)</th><th style="text-align:right">Est. HPP Total</th><th style="text-align:right">Margin</th></tr></thead>
        <tbody>
        <?php if (empty($matrix)): ?>
          <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Belum ada data penjualan untuk periode ini.</td></tr>
        <?php else:
            foreach ($matrix as $sku => $val):
                $est_hpp_total = $val['total_sar'] * $val['avg_hpp'];
                $margin = $val['total_idr'] - $est_hpp_total;
        ?>
          <tr>
            <td><code><?php echo esc_html($sku); ?></code><br><strong><?php echo esc_html($val['name']); ?></strong></td>
            <td style="text-align:center"><?php echo number_format($val['total_sar']); ?></td>
            <td style="text-align:right">Rp <?php echo number_format($val['total_idr']); ?></td>
            <td style="text-align:right;color:#64748b">Rp <?php echo number_format($est_hpp_total); ?></td>
            <td style="text-align:right;color:#059669">Rp <?php echo number_format($margin); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}