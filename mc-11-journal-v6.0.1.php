<?php
/**
 * MC 11 - Journal & General Ledger Viewer (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Provide admin UI to view general ledger / journal entries by date
 *
 * Improvements:
 *  - Capability check (manage_options)
 *  - Safe query usage and output escaping
 *  - Preserve original filters (date)
 */

defined('ABSPATH') || exit;

function puri_render_journal_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $filter_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');

    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT j.*, c.name as account_name 
        FROM " . puri_table_name('T_JOURNAL') . " j
        LEFT JOIN " . puri_table_name('T_CHART') . " c ON j.account_code = c.code
        WHERE DATE(j.trx_date) = %s
        ORDER BY j.id DESC
    ", $filter_date));

    $total_debit = 0;
    $total_credit = 0;
    ?>
    <div class="wrap">
      <h1>📖 Jurnal Umum & Buku Besar</h1>

      <form method="get" style="margin-bottom:12px">
        <input type="hidden" name="page" value="puri-journal">
        <label>Tanggal: </label>
        <input type="date" name="date" value="<?php echo esc_attr($filter_date); ?>">
        <button class="button" type="submit">TAMPILKAN</button>
      </form>

      <table class="widefat striped">
        <thead><tr><th>Waktu</th><th>Ref ID</th><th>Akun / Keterangan</th><th style="text-align:right">Debit</th><th style="text-align:right">Kredit</th></tr></thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Tidak ada mutasi jurnal pada tanggal ini.</td></tr>
        <?php else:
          foreach($logs as $l):
            $total_debit += floatval($l->debit);
            $total_credit += floatval($l->credit);
        ?>
          <tr>
            <td><?php echo esc_html(date('H:i', strtotime($l->trx_date))); ?></td>
            <td><code><?php echo esc_html($l->ref_id); ?></code></td>
            <td><strong><?php echo esc_html($l->account_name); ?></strong><br><small><?php echo esc_html($l->description); ?></small></td>
            <td style="text-align:right;color:#059669"><?php echo $l->debit>0?number_format($l->debit):'-'; ?></td>
            <td style="text-align:right;color:#e11d48"><?php echo $l->credit>0?number_format($l->credit):'-'; ?></td>
          </tr>
        <?php endforeach; ?>
          <tr style="background:#f8fafc;font-weight:900;border-top:2px solid #0f172a;">
            <td colspan="3" style="text-align:right">TOTAL HARIAN</td>
            <td style="text-align:right;color:#059669">Rp <?php echo number_format($total_debit); ?></td>
            <td style="text-align:right;color:#e11d48">Rp <?php echo number_format($total_credit); ?></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}