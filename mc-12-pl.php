<?php
/**
 * MC 12 - Profit & Loss Dashboard (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Monthly Profit & Loss (Revenue - Expense) summary
 *
 * Improvements:
 *  - Capability check
 *  - Query corrected & escaped output
 *  - Keep UX filter for month/year same as prior
 */

defined('ABSPATH') || exit;

function puri_render_pl_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $filter_month = isset($_GET['m']) ? intval($_GET['m']) : date('m');
    $filter_year = isset($_GET['y']) ? intval($_GET['y']) : date('Y');

    // Revenue
    $revenue_data = $wpdb->get_results($wpdb->prepare("
        SELECT j.account_code, c.name, SUM(j.credit - j.debit) as total
        FROM " . puri_table_name('T_JOURNAL') . " j
        JOIN " . puri_table_name('T_CHART') . " c ON j.account_code = c.code
        WHERE c.type = 'REVENUE' AND MONTH(j.trx_date) = %d AND YEAR(j.trx_date) = %d
        GROUP BY j.account_code
    ", $filter_month, $filter_year));

    // Expense
    $expense_data = $wpdb->get_results($wpdb->prepare("
        SELECT j.account_code, c.name, SUM(j.debit - j.credit) as total
        FROM " . puri_table_name('T_JOURNAL') . " j
        JOIN " . puri_table_name('T_CHART') . " c ON j.account_code = c.code
        WHERE c.type = 'EXPENSE' AND MONTH(j.trx_date) = %d AND YEAR(j.trx_date) = %d
        GROUP BY j.account_code
    ", $filter_month, $filter_year));

    $total_revenue = 0;
    $total_expense = 0;
    ?>
    <div class="wrap">
      <h1>📊 Laporan Laba / Rugi (P&L)</h1>

      <form method="get" style="margin-bottom:12px">
        <input type="hidden" name="page" value="puri-pl-report">
        <label>Periode:</label>
        <select name="m">
          <?php for ($i=1;$i<=12;$i++): ?>
            <option value="<?php echo $i; ?>" <?php selected($filter_month,$i); ?>><?php echo date('F', mktime(0,0,0,$i,1)); ?></option>
          <?php endfor; ?>
        </select>
        <input type="number" name="y" value="<?php echo esc_attr($filter_year); ?>" style="width:100px">
        <button class="button" type="submit">LIHAT LAPORAN</button>
      </form>

      <div style="background:#fff;padding:18px;border:1px solid #e2e8f0;border-radius:12px">
        <h3>PENDAPATAN</h3>
        <table class="widefat">
          <tbody>
            <?php foreach($revenue_data as $rev): $total_revenue += floatval($rev->total); ?>
              <tr><td><?php echo esc_html($rev->name . ' (' . $rev->account_code . ')'); ?></td><td style="text-align:right">Rp <?php echo number_format($rev->total); ?></td></tr>
            <?php endforeach; ?>
            <tr style="font-weight:900;background:#f1f5f9"><td>TOTAL PENDAPATAN</td><td style="text-align:right">Rp <?php echo number_format($total_revenue); ?></td></tr>
          </tbody>
        </table>

        <h3 style="margin-top:16px">BIAYA OPERASIONAL</h3>
        <table class="widefat">
          <tbody>
            <?php foreach($expense_data as $exp): $total_expense += floatval($exp->total); ?>
              <tr><td><?php echo esc_html($exp->name . ' (' . $exp->account_code . ')'); ?></td><td style="text-align:right;color:#e11d48">Rp <?php echo number_format($exp->total); ?></td></tr>
            <?php endforeach; ?>
            <tr style="font-weight:900;background:#f1f5f9"><td>TOTAL BIAYA</td><td style="text-align:right;color:#e11d48">Rp <?php echo number_format($total_expense); ?></td></tr>
          </tbody>
        </table>

        <?php $net_profit = $total_revenue - $total_expense; ?>
        <div style="margin-top:12px;padding:12px;border-top:2px solid #0f172a;font-weight:900">
          LABA BERSIH: <span style="float:right;color:<?php echo $net_profit>=0? '#10b981':'#f43f5e'; ?>">Rp <?php echo number_format($net_profit); ?></span>
        </div>
      </div>
    </div>
    <?php
}