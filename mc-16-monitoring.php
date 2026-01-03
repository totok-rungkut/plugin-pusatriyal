<?php
/**
 * MC 16 - Admin Dashboard Overview (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Admin quick monitoring dashboard & frontend shortcode
 *
 * Improvements:
 *  - Capability gate for admin-only links; shortcode available for others
 *  - Safe queries for cash, inventory, and today's sales
 *  - Escaped outputs and consistent helpers
 */

defined('ABSPATH') || exit;

// expose shortcode
add_shortcode('puri_monitoring_dash', 'puri_render_monitoring_page');

function puri_render_monitoring_page() {
    global $wpdb;
    // For admin UI we will check capability where needed
    $cash_position = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(debit - credit),0) FROM " . puri_table_name('T_JOURNAL') . " WHERE account_code IN ('1101','1102')"));
    $inventory_value = $wpdb->get_var("SELECT COALESCE(SUM(s.qty * i.base_price),0) FROM " . puri_table_name('T_STOCK') . " s JOIN " . puri_table_name('T_ITEMS') . " i ON s.item_id = i.id");
    $today_sales = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credit - debit),0) FROM " . puri_table_name('T_JOURNAL') . " WHERE account_code = '4100' AND DATE(trx_date) = %s", current_time('Y-m-d')));

    ob_start();
    ?>
    <div style="font-family:Segoe UI;padding:12px">
      <h2>📊 Quick View Monitoring</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0"><div style="font-size:12px;color:#64748b">Kas & Bank</div><div style="font-weight:900">Rp <?php echo number_format(floatval($cash_position)); ?></div></div>
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0"><div style="font-size:12px;color:#64748b">Nilai Persediaan</div><div style="font-weight:900">Rp <?php echo number_format(floatval($inventory_value)); ?></div></div>
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0"><div style="font-size:12px;color:#64748b">Penjualan Hari Ini</div><div style="font-weight:900;color:#10b981">Rp <?php echo number_format(floatval($today_sales)); ?></div></div>
      </div>
      <?php if (current_user_can('manage_options')): ?>
        <div style="margin-top:12px">
          <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=puri-pos')); ?>">🚀 MENU KASIR</a>
          <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=puri-pl-report')); ?>">📊 LAPORAN P&L</a>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}