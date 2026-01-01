<?php
/**
 * MC 17 - Security & Maintenance Hub (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Provide repair/sync and granular hard reset tools
 *
 * Improvements:
 *  - Nonce + password verification for hard reset (as before)
 *  - Capability checks
 *  - Safer operations (lists which tables will be truncated) and transient notices
 *  - Logging hook points are suggested (no persistent logs by default)
 *
 * WARNING: Hard reset is destructive. Only admins should use this.
 */

defined('ABSPATH') || exit;

add_action('admin_init', function() {
    // Repair & Sync operations handled here (same pattern as earlier)
    if (isset($_POST['puri_do_maintenance_action']) && check_admin_referer('puri_mt_action')) {
        puri_check_cap('manage_options');
        global $wpdb;
        if (isset($_POST['puri_do_repair_tables'])) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            // Recreate only items table as a repair example
            $collate = $wpdb->get_charset_collate();
            $sql_items = "CREATE TABLE IF NOT EXISTS " . puri_table_name('T_ITEMS') . " (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                sku varchar(50) NOT NULL,
                name varchar(100) NOT NULL,
                type enum('currency','goods','package') DEFAULT 'currency',
                denom_value int DEFAULT 1,
                base_price decimal(19,4) DEFAULT 0,
                sell_rate decimal(19,4) DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY sku (sku)
            ) $collate;";
            dbDelta($sql_items);
            set_transient('puri_mt_notice', 'Struktur Tabel Berhasil Diperbaiki!');
        }
        if (isset($_POST['puri_do_mass_sync'])) {
            // Sync CPT pr_item to SQL items table
            puri_check_cap('manage_options');
            $posts = get_posts(['post_type'=>'pr_item','numberposts'=>-1]);
            $count = 0;
            foreach ($posts as $p) {
                $sku = get_field('item_sku_code', $p->ID);
                if (!$sku) continue;
                $wpdb->replace($wpdb->prefix . T_ITEMS, [
                    'sku' => sanitize_text_field($sku),
                    'name' => get_the_title($p->ID),
                    'type' => get_field('type', $p->ID),
                    'denom_value' => intval(get_field('denom_value', $p->ID) ?: 1),
                    'sell_rate' => floatval(get_field('sell_rate', $p->ID) ?: 0)
                ]);
                $count++;
            }
            set_transient('puri_mt_notice', "Sukses sinkronisasi $count item SKU ke SQL!");
        }
        wp_redirect(admin_url('admin.php?page=puri-maintenance'));
        exit;
    }

    // Hard reset handling
    if (isset($_POST['puri_do_hard_reset']) && check_admin_referer('puri_reset_action')) {
        puri_check_cap('manage_options');
        global $wpdb;
        $submitted_pass = $_POST['reset_confirm_password'] ?? '';
        $targets = $_POST['reset_targets'] ?? [];
        $current_user = wp_get_current_user();
        if (!wp_check_password($submitted_pass, $current_user->data->user_pass, $current_user->ID)) {
            set_transient('puri_mt_error', "GAGAL: Password Administrator Salah! Data aman.");
            wp_redirect(admin_url('admin.php?page=puri-maintenance')); exit;
        }
        if (empty($targets)) {
            set_transient('puri_mt_error', "PERINGATAN: Tidak ada target data yang dipilih!");
            wp_redirect(admin_url('admin.php?page=puri-maintenance')); exit;
        }
        $deleted = [];
        if (in_array('acct_journal', $targets)) { $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_JOURNAL')); $deleted[]='Jurnal Akuntansi'; }
        if (in_array('inv_ledger', $targets)) { $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_LEDGER')); $deleted[]='Jurnal Stok (Ledger)'; }
        if (in_array('inv_stock', $targets)) { $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_STOCK')); $deleted[]='Saldo Stok'; }
        if (in_array('orders', $targets)) { $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . T_ORDERS); $deleted[]='Antrean Pesanan'; }
        if (in_array('consign', $targets)) { $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_CONSIGN')); $deleted[]='Data Konsinyasi'; }
        if (in_array('m_item', $targets)) {
            $posts = get_posts(['post_type'=>'pr_item','numberposts'=>-1,'fields'=>'ids']);
            foreach ($posts as $pid) wp_delete_post($pid, true);
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_ITEMS'));
            $deleted[]='Master SKU';
        }
        if (in_array('m_vendor', $targets)) {
            $posts = get_posts(['post_type'=>'pr_vendor','numberposts'=>-1,'fields'=>'ids']);
            foreach ($posts as $pid) wp_delete_post($pid, true);
            $deleted[]='Master Vendor';
        }
        if (in_array('m_customer', $targets)) {
            $posts = get_posts(['post_type'=>'pr_customer','numberposts'=>-1,'fields'=>'ids']);
            foreach ($posts as $pid) wp_delete_post($pid, true);
            $deleted[]='Master Customer';
        }
        set_transient('puri_mt_notice', "BERHASIL RESET: " . implode(", ", $deleted));
        wp_redirect(admin_url('admin.php?page=puri-maintenance')); exit;
    }
});

function puri_render_maintenance_page() {
    puri_check_cap('manage_options');
    $notice = get_transient('puri_mt_notice'); if ($notice) { echo '<div class="notice notice-success is-dismissible"><p>✅ '.esc_html($notice).'</p></div>'; delete_transient('puri_mt_notice'); }
    $error = get_transient('puri_mt_error'); if ($error) { echo '<div class="notice notice-error is-dismissible"><p>❌ '.esc_html($error).'</p></div>'; delete_transient('puri_mt_error'); }
    ?>
    <div class="wrap">
      <h1>⚙️ Maintenance Hub v6.0.1</h1>
      <form method="post">
        <?php wp_nonce_field('puri_mt_action'); ?>
        <input type="hidden" name="puri_do_maintenance_action" value="1">
        <p><button type="submit" name="puri_do_repair_tables" class="button">🛠 Perbaiki Struktur Tabel</button> <button type="submit" name="puri_do_mass_sync" class="button">⚡ Mass Sync SKU ke SQL</button></p>
      </form>

      <hr>

      <form method="post">
        <?php wp_nonce_field('puri_reset_action'); ?>
        <h2>HARD RESET (Destructive)</h2>
        <p>Pilih data yang ingin dihapus secara permanen:</p>
        <p><label><input type="checkbox" name="reset_targets[]" value="acct_journal"> Jurnal Akuntansi</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="inv_ledger"> Jurnal Stok</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="inv_stock"> Saldo Stok</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="orders"> Antrean Pesanan</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="consign"> Data Konsinyasi</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_item"> Master Item</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_vendor"> Master Vendor</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_customer"> Master Customer</label>
        </p>
        <div style="background:#fff1f2;padding:12px;border:1px solid #fecaca">
          <label>Password Administrator:</label><br>
          <input type="password" name="reset_confirm_password" required style="width:300px;padding:8px"><br>
          <button type="submit" name="puri_do_hard_reset" class="button button-primary" onclick="return confirm('Peringatan: Opsi ini tidak dapat dibatalkan. Lanjutkan?')">EKSEKUSI PENGHAPUSAN DATA</button>
        </div>
      </form>
    </div>
    <?php
}