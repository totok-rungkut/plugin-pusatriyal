<?php
/**
 * MC 10 - Expense & Operational Cost (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Admin UI to record OPEX (debit expense account, credit cash/bank)
 *
 * Improvements:
 *  - Nonce & capability checks
 *  - Input validation (amount > 0)
 *  - Uses $puri_engine->post_journal for double-entry
 *  - Sanitized output and success / error messaging
 */

defined('ABSPATH') || exit;

function puri_render_expense_page() {
    puri_check_cap('manage_options');
    global $wpdb, $puri_engine;

    $success_msg = '';
    if (isset($_POST['puri_submit_expense'])) {
        if (!wp_verify_nonce($_POST['puri_admin_nonce'] ?? '', 'puri_admin_action')) {
            $success_msg = '<div class="notice notice-error"><p>Nonce verification failed.</p></div>';
        } else {
            $amount = floatval($_POST['amount'] ?? 0);
            $expense_acc = sanitize_text_field($_POST['expense_account'] ?? '');
            $payment_acc = sanitize_text_field($_POST['payment_account'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $ref_id = 'EXP-' . date('YmdHis');

            if ($amount <= 0 || !$expense_acc || !$payment_acc) {
                $success_msg = '<div class="notice notice-error"><p>Validasi gagal. Periksa kembali input.</p></div>';
            } else {
                // Post Journal (Debit expense, Credit cash)
                $res1 = $puri_engine->post_journal($ref_id, $expense_acc, $amount, 0, $description);
                $res2 = $puri_engine->post_journal($ref_id, $payment_acc, 0, $amount, $description);
                if (is_wp_error($res1) || is_wp_error($res2)) {
                    $success_msg = '<div class="notice notice-error"><p>Gagal mencatat jurnal: ' . esc_html(($res1->get_error_message()?:$res2->get_error_message())) . '</p></div>';
                } else {
                    $success_msg = '<div class="notice notice-success"><p>Berhasil! Biaya sebesar Rp ' . number_format($amount) . ' telah dicatat.</p></div>';
                }
            }
        }
    }

    $expense_accounts = $wpdb->get_results("SELECT code, name FROM " . puri_table_name('T_CHART') . " WHERE type = 'EXPENSE' ORDER BY code ASC");
    $cash_accounts = $wpdb->get_results("SELECT code, name FROM " . puri_table_name('T_CHART') . " WHERE is_cash = 1 ORDER BY code ASC");

    ?>
    <div class="wrap">
      <h1>💸 Input Biaya Operasional</h1>
      <?php echo $success_msg; ?>
      <form method="post">
        <?php puri_create_admin_nonce_field(); ?>
        <table class="form-table">
          <tr><th><label>Kategori Biaya</label></th><td>
            <select name="expense_account" required>
              <option value="">-- Pilih Kategori --</option>
              <?php foreach($expense_accounts as $acc): ?><option value="<?php echo esc_attr($acc->code); ?>"><?php echo esc_html($acc->code . ' - ' . $acc->name); ?></option><?php endforeach; ?>
            </select>
          </td></tr>
          <tr><th><label>Nominal (Rp)</label></th><td><input type="number" name="amount" required></td></tr>
          <tr><th><label>Sumber Dana</label></th><td>
            <select name="payment_account" required><option value="">-- Pilih Kas/Bank --</option><?php foreach($cash_accounts as $acc): ?><option value="<?php echo esc_attr($acc->code); ?>"><?php echo esc_html($acc->code . ' - ' . $acc->name); ?></option><?php endforeach; ?></select>
          </td></tr>
          <tr><th><label>Keterangan</label></th><td><textarea name="description" rows="3"></textarea></td></tr>
        </table>
        <p><button type="submit" name="puri_submit_expense" class="button button-primary">CATAT PENGELUARAN SEKARANG</button></p>
      </form>
    </div>
    <?php
}