<? php
/**
 * =============================================================================
 * MC 23 - Chart of Accounts (CoA) Manager
 * =============================================================================
 * 
 * @package     Pusat Riyal
 * @module      MC-23
 * @version     6.0.2
 * @author      Denmas Totok (refactor by Copilot)
 * @updated     2026-01-03
 * 
 * =============================================================================
 * PURPOSE / TUJUAN
 * =============================================================================
 * 
 * Modul ini menyediakan antarmuka admin untuk mengelola Chart of Accounts (CoA),
 * yaitu daftar akun-akun keuangan yang digunakan dalam sistem akuntansi: 
 * 
 *   - ASSET    : Kas Laci, Bank, Persediaan, Piutang
 *   - EQUITY   : Modal Disetor, Laba Ditahan
 *   - REVENUE  : Pendapatan Penjualan Valas
 *   - EXPENSE  : Biaya Operasional (Listrik, Gaji, dll)
 * 
 * =============================================================================
 * FEATURES / FITUR
 * =============================================================================
 * 
 *   [1] Tambah akun baru (CREATE)
 *   [2] Edit akun existing (UPDATE via REPLACE)
 *   [3] Hapus akun (DELETE)
 *   [4] Tandai akun sebagai Kas/Bank untuk laporan arus kas
 *   [5] Tampilkan daftar semua akun dalam tabel
 * 
 * =============================================================================
 * DATABASE TABLE
 * =============================================================================
 * 
 * Table:  {prefix}puri_acct_chart (constant:  T_CHART)
 * 
 * | Column   | Type        | Description                        |
 * |----------|-------------|------------------------------------|
 * | code     | VARCHAR(20) | Kode akun unik (PRIMARY KEY)       |
 * | name     | VARCHAR(100)| Nama akun                          |
 * | type     | VARCHAR(20) | ASSET/EQUITY/REVENUE/EXPENSE       |
 * | is_cash  | TINYINT(1)  | 1 = Akun Kas/Bank, 0 = Bukan       |
 * 
 * =============================================================================
 * SECURITY MEASURES
 * =============================================================================
 * 
 *   ✓ Capability check    : puri_check_cap('manage_options') 
 *                           Hanya Administrator yang bisa akses
 *   ✓ Nonce verification  : wp_nonce_field() untuk form submission
 *                           wp_verify_nonce() untuk delete action
 *   ✓ Input sanitization  : sanitize_text_field() untuk semua input
 *   ✓ Output escaping     : esc_html(), esc_url(), esc_attr()
 * 
 * =============================================================================
 * DEPENDENCIES / KETERGANTUNGAN
 * =============================================================================
 * 
 *   - mc-00-admin-hub. php  : puri_check_cap(), puri_table_name()
 *   - mc-01-core.php       : T_CHART constant, table creation
 * 
 * =============================================================================
 * USAGE / CARA AKSES
 * =============================================================================
 * 
 * Admin Menu Path: 
 *   Dashboard → Master & Tools → 📖 CoA Manager
 * 
 * Direct URL:
 *   /wp-admin/admin. php?page=puri-coa
 * 
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 * 
 * [6.0.2] 2026-01-03
 *   - Added: puri_check_cap() capability check at function start
 *   - Added:  Nonce verification for DELETE action
 *   - Added:  Nonce verification for ADD/UPDATE action
 *   - Fixed: Secure delete URL generation with per-item nonce
 *   - Improved: Complete input sanitization
 * 
 * [6.0.1] 2025-12-xx
 *   - Initial refactored version
 *   - Basic CRUD functionality
 * 
 * =============================================================================
 */

defined('ABSPATH') || exit;

function puri_render_coa_manager_page() {
    // ✅ FIX: Tambahkan capability check di awal
    puri_check_cap('manage_options');
    
    global $wpdb;
    $table = puri_table_name('T_CHART');

    // 1. Handle Delete - ✅ FIX: Tambahkan nonce verification
    if (isset($_GET['del']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'puri_coa_delete_' . $_GET['del'])) {
            $wpdb->delete($table, ['code' => sanitize_text_field($_GET['del'])]);
            echo '<div class="notice notice-success"><p>Akun berhasil dihapus.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Verifikasi keamanan gagal. </p></div>';
        }
    }

    // 2. Handle Add / Update - ✅ FIX:  Tambahkan nonce verification
    if (isset($_POST['add_coa'])) {
        if (! isset($_POST['puri_coa_nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['puri_coa_nonce']), 'puri_coa_save')) {
            echo '<div class="notice notice-error"><p>Verifikasi keamanan gagal.</p></div>';
        } else {
            $wpdb->replace($table, [
                'code' => strtoupper(sanitize_text_field($_POST['code'])),
                'name' => sanitize_text_field($_POST['name']),
                'type' => sanitize_text_field($_POST['type']),
                'is_cash' => isset($_POST['is_cash']) ? 1 : 0
            ]);
            echo '<div class="notice notice-success"><p>Akun berhasil disimpan.</p></div>';
        }
    }

    $charts = $wpdb->get_results("SELECT * FROM $table ORDER BY code ASC");
    ?>
    <div class="wrap">
        <h1>📖 CoA Manager (Chart of Accounts)</h1>
        <div class="proc-card" style="background:#fff; padding:20px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom:20px;">
            <h3>Tambah / Edit Akun</h3>
            <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <? php wp_nonce_field('puri_coa_save', 'puri_coa_nonce'); ?>
                <div>Kode Akun: <br><input type="text" name="code" required placeholder="Mis:  1101"></div>
                <div>Nama Akun: <br><input type="text" name="name" required placeholder="Mis: Kas Laci"></div>
                <div>Tipe Akun: <br>
                    <select name="type">
                        <option value="ASSET">ASSET</option>
                        <option value="EQUITY">EQUITY</option>
                        <option value="REVENUE">REVENUE</option>
                        <option value="EXPENSE">EXPENSE</option>
                    </select>
                </div>
                <div style="padding-bottom:10px;"><label><input type="checkbox" name="is_cash"> Akun Kas/Bank? </label></div>
                <button type="submit" name="add_coa" class="button button-primary">Simpan Akun</button>
            </form>
        </div>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Akun</th>
                    <th>Tipe</th>
                    <th>Status Kas</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($charts)) : ?>
                    <tr><td colspan="5" style="text-align:center;">Belum ada data CoA. </td></tr>
                <?php else :  ?>
                    <? php foreach($charts as $c): 
                        // ✅ FIX: Generate nonce untuk setiap delete link
                        $delete_nonce = wp_create_nonce('puri_coa_delete_' .  $c->code);
                        $delete_url = add_query_arg([
                            'page' => 'puri-coa',
                            'del' => $c->code,
                            '_wpnonce' => $delete_nonce
                        ], admin_url('admin. php'));
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($c->code); ?></code></td>
                        <td><strong><? php echo esc_html($c->name); ?></strong></td>
                        <td><?php echo esc_html($c->type); ?></td>
                        <td><?php echo $c->is_cash ? '✅ Kas/Bank' : '-'; ?></td>
                        <td>
                            <a href="<?php echo esc_url($delete_url); ?>" 
                               class="btn-rem" 
                               style="color:#ef4444; text-decoration:none;"
                               onclick="return confirm('Hapus akun ini? ')">Hapus</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <? php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}