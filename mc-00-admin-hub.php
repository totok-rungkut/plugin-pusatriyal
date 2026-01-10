<?php
/**
 * MC 00 - Admin Hub & SKU Manager (Refactor 4 Pilar)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 * - Memecah navigasi menjadi 4 Pilar Utama: Master, Transaksi, Mutasi, Keuangan.
 * - Register CPT pr_item (Master SKU) dan sinkronisasi SQL.
 * - Integrasi [MC 24] CoA Manager ke dalam pilar Master.
 *
 * Security:
 * - Akses ketat 'manage_options' dengan puri_check_cap().
 * - Proteksi Nonce pada setiap aksi sinkronisasi.
 */

defined('ABSPATH') || exit;

if (!defined('PURI_VERSION')) define('PURI_VERSION', '6.0.1');

// --- HELPERS (Tetap dipertahankan dari v6.0.1) ---
function puri_table_name($const_name) {
    global $wpdb;
    return (defined($const_name)) ? $wpdb->prefix . constant($const_name) : '';
}

function puri_check_cap($cap = 'manage_options') {
    if (!current_user_can($cap)) wp_die(__('Unauthorized', 'puri'));
}

function puri_create_admin_nonce_field() {
    wp_nonce_field('puri_admin_action', 'puri_admin_nonce');
}

// --- REGISTER CPT ---
add_action('init', function() {
    register_post_type('pr_item', [
        'labels' => ['name' => 'Master Items SKU', 'singular_name' => 'Item SKU'],
        'public' => true, 'show_in_menu' => false, 'supports' => ['title'],
    ]);
});

// --- REFACTOR MENU 4 PILAR ---
add_action('admin_menu', function() {
    global $menu;
    // Separator untuk memisahkan menu Pusat Riyal dari menu default WP
    $menu[29] = ['*', 'read', 'separator-puri', '', 'wp-menu-separator'];
    $menu[34] = ['*', 'read', 'separator-puri', '', 'wp-menu-separator'];

    // DASBOR
    add_menu_page('Dasbor', 'Dasbor', 'manage_options', 'puri-dashboard', 'puri_render_welcome_screen', 'dashicons-dashboard', 30);
    add_submenu_page('puri-dashboard', 'Home', '🏠 Home', 'manage_options', 'puri-dashboard', 'puri_render_welcome_screen');
    add_submenu_page('puri-dashboard', 'Monitoring', '🖥️ Monitoring', 'manage_options', 'puri-monitoring', 'puri_render_monitoring_page');

    // PEMBELIAN
    add_menu_page('Pembelian', 'Pembelian', 'manage_options', 'puri-purchase', 'puri_landing_purchase', 'dashicons-store', 31);
    add_submenu_page('puri-purchase', 'Kulakan/procurement', '▼ Pembeilan/Kulakan', 'manage_options', 'puri-procurement', 'puri_render_procurement_page');
    add_submenu_page('puri-purchase', 'Tagihan', '▼ Tagihan', 'manage_options', 'puri-expenses', 'puri_render_expense_page');
    add_submenu_page('puri-purchase', 'Pemasok', '▼ Pemasok', 'manage_options', 'edit.php?post_type=pr_vendor');
    add_submenu_page('puri-purchase', 'Konsinyasi', '▼ Konsinyasi', 'manage_options', 'puri-consignment', 'puri_render_consignment_page');

    // PENJUALAN & PEMBAYARAN
    add_menu_page('Penjualan & Pembayaran', 'Penjualan', 'manage_options', 'puri-sales', 'puri_landing_sales', 'dashicons-cart', 31);
	add_submenu_page('puri-sales', 'Cockpit POS', '▶ Cockpit POS !', 'manage_options', 'puri-cockpit-pos', 'cockpit_render_page');
    //add_submenu_page('puri-sales', 'Kasir POS', '▶ Kasir POS', 'manage_options', 'puri-pos', 'puri_render_pos_page');
    //add_submenu_page('puri-sales', 'Antrean / Estimasi', '▶ Estimasi', 'manage_options', 'puri-queue', 'puri_render_queue_page');
    add_submenu_page('puri-sales', 'Pelanggan', '▶ Pelanggan', 'manage_options', 'edit.php?post_type=pr_customer');

    // AKUNTANSI
    add_menu_page('Akuntansi', 'Akuntansi', 'manage_options', 'puri-accounting', 'puri_landing_accounting', 'dashicons-analytics', 32);
    add_submenu_page('puri-accounting', 'Transfer Laci', '◕ Transfer Laci', 'manage_options', 'puri-stock-transfer', 'puri_render_transfer_page');
	add_submenu_page('puri-accounting', 'Jurnal Umum', '◕ Jurnal Umum', 'manage_options', 'puri-manual-journal', 'puri_render_manual_journal_page');
	add_submenu_page('puri-accounting', 'Stock Opname', '◕ Stock Opname', 'puri_can_rekonsiliasi', 'puri-stock-adj', 'puri_render_stock_adjustment_page');
    add_submenu_page('puri-accounting', 'Chart of Accounts', '◕ CoA Manager', 'manage_options', 'puri-coa', 'puri_render_coa_manager_page');
	add_submenu_page('puri-accounting', 'Pemetaan akun General Ledger', '◕ Pemetaan akun GL', 'manage_options', 'puri-gl-mapping', 'puri_render_gl_mapping_page');
//    add_submenu_page('puri-accounting', 'Rekonsiliasi', '◕ Rekonsiliasi', 'manage_options', 'puri-balance-sheet', 'puri_render_balance_sheet_page');

    // REPORTIN
    add_menu_page('Laporan', 'Laporan', 'manage_options', 'puri-reporting', 'puri_landing_report', 'dashicons-analytics', 32);
    add_submenu_page('puri-reporting', 'Mutasi Riyal', '◕ Mutasi Riyal', 'manage_options', 'puri-riyal-report', 'puri_render_riyal_mutation_report');
    add_submenu_page('puri-reporting', 'Summary Transaksi Customer', '◕ Summary Cust', 'manage_options', 'puri-pivot-report', 'puri_render_pivot_report_page');
    add_submenu_page('puri-reporting', 'Summary Transaksi Harian', '◕ Transaksi Harian', 'manage_options', 'puri-journal', 'puri_render_journal_page');
    add_submenu_page('puri-reporting', 'Laba / Rugi', '◕ Laba / Rugi', 'manage_options', 'puri-pl-report', 'puri_render_pl_page');
    add_submenu_page('puri-reporting', 'Neraca', '◕ Neraca', 'manage_options', 'puri-balance-sheet', 'puri_render_balance_sheet_page');
	add_submenu_page('puri-reporting', 'Jurnal Stok', '◕ Jurnal Stok', 'manage_options', 'puri-inventory-ledger', 'puri_render_inventory_ledger_page');


    // PERBANKAN
    add_menu_page('Perbankan', 'Perbankan', 'manage_options', 'puri-banking', 'puri_render_profile_page', 'dashicons-bank', 33);
    add_submenu_page('puri-banking', 'Akun Terhubung', '■ Integrasi Excel', 'manage_options', 'puri-webhook', 'puri_render_webhook_page');

    // SETTING (non-transaksional)
    add_menu_page('Setting', 'Setting', 'manage_options', 'puri-setting', 'puri_render_maintenance_page', 'dashicons-admin-settings', 33);
    add_submenu_page('puri-setting', 'Profile', '◬ Profile', 'manage_options', 'puri-profile', 'puri_render_profile_page');
    add_submenu_page('puri-setting', 'Master Item SKU', '◬ Produk & Jasa', 'manage_options', 'edit.php?post_type=pr_item');
    add_submenu_page('puri-setting', 'Maintenance', '◬️ Maintenance', 'manage_options', 'puri-maintenance', 'puri_render_maintenance_page');
    add_submenu_page('puri-setting', 'Mass Sync SKU', '◬ Mass Sync SKU', 'manage_options', 'puri-mass-sync', 'puri_render_mass_sync_page');






/*
    // PILAR 1: MASTER & TOOLS
    add_menu_page('Master & Tools', 'Master & Tools', 'manage_options', 'puri-master', 'puri_render_welcome_screen', 'dashicons-admin-generic', 35);
    add_submenu_page('puri-master', 'Home', '🏠 Home', 'manage_options', 'puri-master', 'puri_render_welcome_screen');
    add_submenu_page('puri-master', 'Master SKU', '📋 Master SKU', 'manage_options', 'edit.php?post_type=pr_item');
    add_submenu_page('puri-master', 'Supplier', '🏢 Master Supplier', 'manage_options', 'edit.php?post_type=pr_vendor');
    add_submenu_page('puri-master', 'Customer', '👥 Master Customer', 'manage_options', 'edit.php?post_type=pr_customer');
    add_submenu_page('puri-master', 'CoA Manager', '📖 CoA Manager', 'manage_options', 'puri-coa', 'puri_render_coa_manager_page'); // [MC 24]
    add_submenu_page('puri-master', 'Profil & Bank', '🏨 Profil & Bank', 'manage_options', 'puri-profile', 'puri_render_profile_page_dummy');
    add_submenu_page('puri-master', 'Integrasi Excel', '🔗 Integrasi Excel', 'manage_options', 'puri-webhook', 'puri_render_webhook_page');
    add_submenu_page('puri-master', 'Maintenance', '⚙️ Maintenance', 'manage_options', 'puri-maintenance', 'puri_render_maintenance_page');

    // PILAR 2: TRANSAKSI
    add_menu_page('Transaksi', 'Transaksi', 'manage_options', 'puri-transaksi', 'puri_render_pos_page', 'dashicons-cart', 36);
    add_submenu_page('puri-transaksi', 'Kasir POS', '💰 Kasir POS', 'manage_options', 'puri-pos', 'puri_render_pos_page');
    add_submenu_page('puri-transaksi', 'Antrean', '📋 Antrean', 'manage_options', 'puri-queue', 'puri_render_queue_page');
    add_submenu_page('puri-transaksi', 'Transfer Laci', '🚚 Transfer Laci', 'manage_options', 'puri-stock-transfer', 'puri_render_transfer_page');
    add_submenu_page('puri-transaksi', 'Kulakan Brot', '📦 Kulakan Brot', 'manage_options', 'puri-procurement', 'puri_render_procurement_page'); // [MC 04] Refactor
    add_submenu_page('puri-transaksi', 'Konsinyasi', '🤝 Konsinyasi', 'manage_options', 'puri-consignment', 'puri_render_consignment_page');

    // PILAR 3: REPORT MUTASI
    add_menu_page('Report Mutasi', 'Report Mutasi', 'manage_options', 'puri-reports', 'puri_render_riyal_mutation_report', 'dashicons-chart-area', 36);
    add_submenu_page('puri-reports', 'Mutasi Riyal', '📜 Mutasi Riyal', 'manage_options', 'puri-riyal-report', 'puri_render_riyal_mutation_report');
    add_submenu_page('puri-reports', 'Jurnal Stok', '📦 Jurnal Stok', 'manage_options', 'puri-inventory-ledger', 'puri_render_inventory_ledger_page');
    add_submenu_page('puri-reports', 'Pivot Sales', '📊 Pivot Sales', 'manage_options', 'puri-pivot-report', 'puri_render_pivot_report_page'); // [MC 22]

    // PILAR 4: LAPORAN KEUANGAN
    add_menu_page('Laporan Keuangan', 'Laporan Keu', 'manage_options', 'puri-finance', 'puri_render_balance_sheet_page', 'dashicons-analytics', 36);
    add_submenu_page('puri-finance', 'Input Biaya', '💸 Input Biaya', 'manage_options', 'puri-expenses', 'puri_render_expense_page');
    add_submenu_page('puri-finance', 'Jurnal Umum', '📖 Jurnal Umum', 'manage_options', 'puri-journal', 'puri_render_journal_page');
    add_submenu_page('puri-finance', 'Laba / Rugi', '📈 Laba / Rugi', 'manage_options', 'puri-pl-report', 'puri_render_pl_page');
    add_submenu_page('puri-finance', 'Neraca & Kas', '⚖️ Neraca & Kas', 'manage_options', 'puri-balance-sheet', 'puri_render_balance_sheet_page');
    add_submenu_page('puri-finance', 'Monitoring', '🖥️ Monitoring', 'manage_options', 'puri-monitoring', 'puri_render_monitoring_page');
*/

}, 1);





// --- WELCOME SCREEN ---
function puri_render_welcome_screen() {
    puri_check_cap('manage_options');
    $co_name = function_exists('get_field') ? get_field('cp_name', 'option') : 'Pusat Riyal';
    echo '<div class="wrap"><div style="background:#fff;padding:40px;border-radius:12px;border:1px solid #c3c4c7;">';
    echo '<h1 style="font-weight:900;font-size:32px;">🛡️ PORTAL ' . esc_html($co_name) . ' (v' . esc_html(PURI_VERSION) . ')</h1>';
    echo '<p style="font-size:16px;color:#64748b;">Arsitektur 4 Pilar Aktif. Semua modul telah dienkapulasi dalam puri-centre.</p>';
    echo '</div></div>';
}

// --- MASS SYNC SKU (Logic dipertahankan dari v6.0.1) ---
add_action('restrict_manage_posts', function() {
    if (get_current_screen() && get_current_screen()->post_type === 'pr_item' && current_user_can('manage_options')) {
        $sync_url = esc_url(add_query_arg(['puri_mass_sync' => '1'], admin_url('edit.php?post_type=pr_item')));
        echo '<a href="' . $sync_url . '" class="button button-primary" style="background:#0f172a;border:none;margin-left:10px;">⚡ SINKRONISASI MASSAL KE SQL</a>';
    }
});

add_action('admin_init', function() {
    if (isset($_GET['puri_mass_sync']) && $_GET['puri_mass_sync'] === '1' && get_current_screen() && get_current_screen()->post_type === 'pr_item') {
        puri_check_cap('manage_options');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Konfirmasi Mass Sync</strong></p>';
            echo '<form method="post" style="display:inline-block;">';
            puri_create_admin_nonce_field();
            echo '<input type="hidden" name="puri_confirm_mass_sync" value="1" />';
            echo '<button class="button button-primary" type="submit">Konfirmasi Sinkronisasi</button></form> ';
            echo '<a class="button" href="' . esc_url(remove_query_arg('puri_mass_sync')) . '">Batalkan</a></div>';
        });
    }

    if (isset($_POST['puri_confirm_mass_sync']) && isset($_POST['puri_admin_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field($_POST['puri_admin_nonce']), 'puri_admin_action')) wp_die('Nonce failed.');
        puri_check_cap('manage_options');
        global $wpdb;
        $posts = get_posts(['post_type' => 'pr_item', 'numberposts' => -1, 'fields' => 'ids']);
        $count = 0;
        foreach ($posts as $post_id) {
            $sku = get_field('item_sku_code', $post_id);
            if (!$sku) continue;
            $wpdb->replace(puri_table_name('T_ITEMS'), [
                'sku' => strtoupper(sanitize_text_field($sku)),
                'name' => get_the_title($post_id),
                'type' => get_field('type', $post_id),
                'denom_value' => intval(get_field('denom_value', $post_id) ?: 1),
                'sell_rate' => floatval(get_field('sell_rate', $post_id) ?: 0)
            ]);
            $count++;
        }
        add_action('admin_notices', function() use ($count) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Sukses! ' . intval($count) . ' Item SKU telah disinkronkan.</p></div>';
        });
    }
});


/** ------------------------------------------------------------------------/
 * Sync-Guard Notification
 * notifikasi : perlu dilakukan syncronisasi massal ... tombol mass sync
 *
 *   $status = puri_get_integrity_status();  <-- mc-17
 *
 *-------------------------------------------------------------------------*/
 
 
add_action('admin_notices', function() {
    // Hanya munculkan di halaman PURI agar tidak mengganggu area lain
    $screen = get_current_screen();
    if ( strpos($screen->id, 'puri') === false ) return;

    $status = puri_get_integrity_status();

    if (!$status['is_synced']) {
        ?>
        <div class="notice notice-warning is-dismissible puri-notice">
            <p>
                <strong>⚠️ PURI Data Mismatch:</strong> Ditemukan perbedaan data antara Master SKU (<?php echo $status['wp_count']; ?>) 
                dan Database Mesin (<?php echo $status['sql_count']; ?>). 
                <a href="<?php echo admin_url('admin.php?page=puri-maintenance'); ?>" style="font-weight:bold; color:#d63638; text-decoration:underline;">
                    Lakukan Mass Sync Sekarang
                </a>
            </p>
        </div>
        <?php
    }
});

