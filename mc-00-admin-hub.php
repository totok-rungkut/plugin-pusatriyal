<?php
/**
 * MC 00 - Admin Hub & SKU Manager (Refactor 4 Pilar)
 * Version: 7.0.0
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

if (!defined('PURI_VERSION')) define('PURI_VERSION', '7.0.0');

// --- HELPERS (Tetap dipertahankan dari v6.0.1) ---
if (!function_exists('puri_check_cap')) {
    function puri_check_cap($cap = 'manage_options') {
        if (!current_user_can($cap)) {
            wp_die(__('Unauthorized', 'puri'));
        }
    }
}

function puri_table_name($const_name) {
    global $wpdb;
    return (defined($const_name)) ? $wpdb->prefix . constant($const_name) : '';
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
    add_menu_page('Dasbord Riyal', 'Dasbor', 'manage_options', 'puri-dashboard', 'puri_render_welcome_screen', 'dashicons-dashboard', 30);
    add_submenu_page('puri-dashboard', 'Home', '🏠 Home', 'manage_options', 'puri-dashboard', 'puri_render_welcome_screen');
    add_submenu_page('puri-dashboard', 'Monitoring', '🖥️ Monitoring', 'manage_options', 'puri-monitoring', 'puri_render_monitoring_page');

    // PEMBELIAN
    add_menu_page('Pembelian', 'Pembelian', 'manage_options', 'puri-purchase', 'puri_landing_purchase', 'dashicons-cart', 31);
    add_submenu_page('puri-purchase', 'Kulakan/procurement', 'Pembelian/Kulakan', 'manage_options', 'puri-procurement', 'puri_render_procurement_page');
    add_submenu_page('puri-purchase', 'Tagihan', 'Tagihan', 'manage_options', 'puri-expenses', 'puri_render_expense_page');
    add_submenu_page('puri-purchase', 'Pemasok', 'Pemasok', 'manage_options', 'edit.php?post_type=pr_vendor');

    // PENJUALAN & PEMBAYARAN
    add_menu_page('Penjualan & Pembayaran', 'Penjualan', 'manage_options', 'puri-sales', 'puri_landing_sales', 'dashicons-store', 31);
	add_submenu_page('puri-sales', 'Cockpit POS', 'Cockpit POS !', 'manage_options', 'puri-cockpit-pos', 'allnew_pos_render_page');
    add_submenu_page('puri-purchase', 'Konsinyasi', 'Konsinyasi', 'manage_options', 'puri-consignment', 'puri_render_consignment_page');
    add_submenu_page('puri-sales', 'Pelanggan', 'Pelanggan', 'manage_options', 'edit.php?post_type=pr_customer');
    //add_submenu_page('puri-sales', 'Kasir POS', 'Kasir POS', 'manage_options', 'puri-pos', 'puri_render_pos_page');
    //add_submenu_page('puri-sales', 'Antrean / Estimasi', '▶ Estimasi', 'manage_options', 'puri-queue', 'puri_render_queue_page');

    // AKUNTANSI
    add_menu_page('Accounting', 'Akuntansi', 'manage_options', 'puri-accounting', 'puri_landing_accounting', 'dashicons-analytics', 32);
    add_submenu_page('puri-accounting', 'Transfer Laci', '- Transfer Laci', 'manage_options', 'puri-stock-transfer', 'puri_render_transfer_page');
	add_submenu_page('puri-accounting', 'Jurnal Umum', '- Jurnal Umum', 'manage_options', 'puri-manual-journal', 'puri_render_manual_journal_page');
	add_submenu_page('puri-accounting', 'Stock Opname', '- Stock Opname', 'puri_can_rekonsiliasi', 'puri-stock-adj', 'puri_render_stock_adjustment_page');
    add_submenu_page('puri-accounting', 'Chart of Accounts', '- CoA Manager', 'manage_options', 'puri-coa', 'puri_render_coa_manager_page');
	add_submenu_page('puri-accounting', 'Pemetaan akun General Ledger', '- Pemetaan akun GL', 'manage_options', 'puri-gl-mapping', 'puri_render_gl_mapping_page');
//    add_submenu_page('puri-accounting', 'Rekonsiliasi', '◕ Rekonsiliasi', 'manage_options', 'puri-balance-sheet', 'puri_render_balance_sheet_page');
//	add_submenu_page('puri-accounting','EOD Dashboard','EOD Processing','manage_options','puri-eod-dashboard','eod_dashboard_render_page');


// --- PILAR 2: TRANSAKSI (Gaya Mozart v7) ---
add_submenu_page(
    'puri-accounting', 
    'Cockpit POS', 
    '🛒 Cockpit POS', 
    'read', 
    'puri-pos', 
    'allnew_pos_render_page'
);

// GANTI mc-06-opname DENGAN mc-05c-opname
add_submenu_page(
    'puri-accounting', 
    'Stock Opname', 
    '📋 Stock Opname', 
    'read', // Tetap 'read', karena switcher di dalam akan mengatur akses kasir/supervisor
    'puri-stock-opname', 
    'puri_render_bulk_opname_page' // Fungsi baru di MC-05C
);

// GANTI mc-06-opname DENGAN mc-05c-opname
add_submenu_page(
    'puri-accounting', 
    'Stock Roconcile', 
    '📋 Approval Opname', 
    'read', // Tetap 'read', karena switcher di dalam akan mengatur akses kasir/supervisor
    'puri-stock-recon', 
    'puri_render_reconciliation_page'  // Fungsi baru di MC-05C
);





// TAMBAHKAN MENU BARU MC-05D (Investigation Tower)
add_submenu_page(
    'puri-accounting', 
    'Investigation Tower', 
    '🔍 Investigasi Selisih', 
    'manage_options', // Hanya supervisor yang bisa melihat menu ini
    'puri-investigasi', 
    'puri_render_investigasi_page' // Fungsi baru di MC-05D
);




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
    add_menu_page('Setting', 'Setting/Master', 'manage_options', 'puri-setting', 'puri_render_maintenance_page', 'dashicons-admin-settings', 33);
    add_submenu_page('puri-setting', 'Profile', '◬ Profile', 'manage_options', 'puri-profile', 'puri_render_profile_page');
    add_submenu_page('puri-setting', 'Master Item SKU', '◬ Produk & Jasa', 'manage_options', 'edit.php?post_type=pr_item');
    add_submenu_page('puri-setting', 'Maintenance', '◬️ Maintenance', 'manage_options', 'puri-maintenance', 'puri_render_maintenance_page');
    add_submenu_page('puri-setting', 'Mass Sync SKU', '◬ Mass Sync SKU', 'manage_options', 'puri-mass-sync', 'puri_render_mass_sync_page');






}, 1);





// --- WELCOME SCREEN ---
function puri_render_welcome_screen() {
    puri_check_cap('manage_options');
    $co_name = function_exists('get_field') ? get_field('cp_name', 'option') : 'Pusat Riyal';
    echo '<div class="wrap"><div style="background:#fff;padding:40px;border-radius:12px;border:1px solid #c3c4c7;">';
    echo '<h1 style="font-weight:900;font-size:32px;">🛡️ PORTAL ' . esc_html($co_name) . ' (v' . esc_html(PURI_VERSION) . ')</h1>';
    echo '<p style="font-size:16px;color:#64748b;">In Colaboration with Github Copilot, Claude, OpenAI . Semua modul telah dienkapulasi dalam puri-centre.</p>';
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

			if (function_exists('get_field')) {
				$sku = get_field('item_sku_code', $post_id);
				if (!$sku) continue;
					$wpdb->replace(puri_table_name('T_ITEMS'), [
						'wp_post_id' => $post_id,
						'sku'        => strtoupper(sanitize_text_field($sku)),
						'name'       => get_the_title($post_id),
						'type'       => get_field('type', $post_id),
						'denom_value'=> intval(get_field('denom_value', $post_id) ?: 1),
						'sell_rate'  => floatval(get_field('sell_rate', $post_id) ?: 0)
					]);
			$count++; }
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
    $screen = get_current_screen();
    if (!$screen) return;

    // Hanya tampilkan di halaman dashboard & maintenance
    $allowed_pages = [
        'toplevel_page_puri-dashboard',
        'puri_page_puri-maintenance'
    ];
    if (!in_array($screen->id, $allowed_pages)) return;

    $status = puri_get_integrity_status();
    if (!$status['is_synced']) {
        ?>
        <div class="notice notice-warning is-dismissible puri-notice">
            <p>
                <strong>⚠ PURI Data Mismatch:</strong> Master SKU (<?php echo $status['wp_count']; ?>) 
                vs Database Mesin (<?php echo $status['sql_count']; ?>). 
                <a href="<?php echo admin_url('admin.php?page=puri-maintenance'); ?>" style="font-weight:bold; color:#d63638; text-decoration:underline;">
                    Lakukan Mass Sync Sekarang
                </a>
            </p>
        </div>
        <?php
    }
});

/************** NOTE *****

Catatan berikut jangan dihapus walaupun di-REFACTOR: 

mc13 : puri_render_data_io_page - Maintenance - Import/Export
mc15 : puri_render_invoice_page - Cetak Invoice
mc17 : puri_render_data_io_page - Maintenance - Import/Export
mc23 : puri_render_coa_manager_page - Chart of Accounts
mc25 : cockpit_render_page - Cockpit POS
mc26 : puri_render_stock_adjustment_page - Stock Opname
mc27 : puri_render_manual_journal_page - Jurnal Umum
mc28 : puri_render_gl_mapping_page - Pengaturan Akun GL

// code name: MOZART
* mc-03 : PURI_Engine_Mozart (The Conductor)
* mc-05 : Cockpit POS (The Performer)
* mc-05c: puri_render_opname_switcher (The Auditor - Entry & Recon)
* mc-05d: puri_render_investigasi_page (The Auditor - Investigation Tower)
*

************************************************************/