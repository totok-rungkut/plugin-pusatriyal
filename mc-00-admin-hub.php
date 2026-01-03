<?php
/**
 * MC 00 - Admin Hub & SKU Manager (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Centralized WP Admin menu ("Pusat Riyal") and grouping of submenus.
 *  - Register CPT pr_item (Master SKU) with safe show_in_menu=false
 *  - Provide mass-sync helper for syncing ACF CPT -> SQL master table
 *
 * Security & Changes in 6.0.1:
 *  - All admin actions require 'manage_options'
 *  - Admin mass-sync action validated via nonce and current_user_can
 *  - Utilities added: puri_table_name(), puri_check_cap(), puri_create_admin_nonce()
 *  - Minimal UX change: same menu labels/icons preserved
 *
 * Notes:
 *  - This file focuses on menu, CPT registration, sync helper and JS nonce provisioning.
 *  - Functional business logic (DB schema, engine) remains in their own modules.
 */

defined('ABSPATH') || exit;

if (!defined('PURI_VERSION')) define('PURI_VERSION', '6.0.1');

/**
 * Helper: get full table name by defined constant (T_ITEMS, etc).
 * Keeps usage consistent across modules.
 */
function puri_table_name($const_name) {
    global $wpdb;
    if (defined($const_name)) {
        return $wpdb->prefix . constant($const_name);
    }
    return '';
}

/**
 * Capability helper: abort if current user lacks capability.
 */
function puri_check_cap($cap = 'manage_options') {
    if (!current_user_can($cap)) {
        wp_die(__('Unauthorized', 'puri'));
    }
}

/**
 * Create and echo a nonce input for admin forms.
 * Use name 'puri_admin_nonce' and action 'puri_admin_action' consistently.
 */
function puri_create_admin_nonce_field() {
    wp_nonce_field('puri_admin_action', 'puri_admin_nonce');
}

/**
 * Create a JS-safe nonce object for localized scripts.
 */
function puri_create_admin_nonce() {
    return wp_create_nonce('puri_admin_action');
}

/**
 * Register CPT pr_item (Master SKU).
 * show_in_menu = false to avoid duplicate menu entries; main menu links to edit.php?post_type=pr_item
 */
add_action('init', function() {
    $labels = [
        'name'               => __('Master Items SKU', 'puri'),
        'singular_name'      => __('Item SKU', 'puri'),
        'add_new'            => __('Tambah SKU Baru', 'puri'),
        'add_new_item'       => __('Tambah SKU Baru', 'puri'),
        'edit_item'          => __('Edit Item', 'puri'),
    ];

    register_post_type('pr_item', [
        'labels'            => $labels,
        'public'            => true,
        'show_in_menu'      => false,
        'supports'          => ['title'],
        'has_archive'       => false,
        'rewrite'           => ['slug' => 'item-sku'],
    ]);
});

/**
 * Consolidated Admin Menu: "Pusat Riyal" and submenus.
 * All pages require 'manage_options' except where safe to allow lesser roles (none here).
 */
add_action('admin_menu', function() {
    global $menu;

    // Add separator near position 29 (non-invasive)
    $menu[29] = ['', 'read', 'separator-puri', '', 'wp-menu-separator'];

    // Parent menu
    add_menu_page('Pusat Riyal', 'Pusat Riyal', 'manage_options', 'pr-dashboard', 'puri_render_welcome_screen', 'dashicons-shield', 30);

    // Home
    add_submenu_page('pr-dashboard', 'Home', '🏠 Home', 'manage_options', 'pr-dashboard', 'puri_render_welcome_screen');

    // Labels + Master Data links (ke edit.php?post_type=xxx)
    add_submenu_page('pr-dashboard', '', '<span style="color:#94a3b8;font-weight:700;border-top:1px solid #e2e8f0;display:block;padding-top:10px;margin-top:5px;pointer-events:none;">DATA MASTER</span>', 'manage_options', 'puri-sep-1', '__return_null');
    add_submenu_page('pr-dashboard', 'Master SKU', '📋 Master SKU', 'manage_options', 'edit.php?post_type=pr_item');
    add_submenu_page('pr-dashboard', 'Master Vendor', '🏢 Master Supplier', 'manage_options', 'edit.php?post_type=pr_vendor');
    add_submenu_page('pr-dashboard', 'Master Customer', '👥 Master Customer', 'manage_options', 'edit.php?post_type=pr_customer');

    // Transactions group
    add_submenu_page('pr-dashboard', '', '<span style="color:#94a3b8;font-weight:700;border-top:1px solid #e2e8f0;display:block;padding-top:10px;margin-top:5px;pointer-events:none;">TRANSAKSI</span>', 'manage_options', 'puri-sep-2', '__return_null');
    add_submenu_page('pr-dashboard', 'Kasir POS', '💰 Kasir POS', 'manage_options', 'puri-pos', 'puri_render_pos_page');
    add_submenu_page('pr-dashboard', 'Antrean Pesanan', '📋 Antrean', 'manage_options', 'puri-queue', 'puri_render_queue_page');
    add_submenu_page('pr-dashboard', 'Transfer Laci', '🚚 Transfer Laci', 'manage_options', 'puri-stock-transfer', 'puri_render_transfer_page');
    add_submenu_page('pr-dashboard', 'Pembelian Brot', '📦 Kulakan Brot', 'manage_options', 'puri-procurement', 'puri_render_procurement_page');
    add_submenu_page('pr-dashboard', 'Konsinyasi Agen', '🤝 Konsinyasi', 'manage_options', 'puri-consignment', 'puri_render_consignment_page');

    // Reports group
    add_submenu_page('pr-dashboard', '', '<span style="color:#94a3b8;font-weight:700;border-top:1px solid #e2e8f0;display:block;padding-top:10px;margin-top:5px;pointer-events:none;">LAPORAN & KEU</span>', 'manage_options', 'puri-sep-3', '__return_null');
    add_submenu_page('pr-dashboard', 'Input Biaya', '💸 Input Biaya', 'manage_options', 'puri-expenses', 'puri_render_expense_page');
    add_submenu_page('pr-dashboard', 'Mutasi Riyal', '📥 Mutasi Riyal', 'manage_options', 'puri-riyal-report', 'puri_render_riyal_mutation_report');
    add_submenu_page('pr-dashboard', 'Jurnal Umum', '📖 Jurnal Umum', 'manage_options', 'puri-journal', 'puri_render_journal_page');
    add_submenu_page('pr-dashboard', 'Jurnal Persediaan', '📦 Jurnal Stok', 'manage_options', 'puri-inventory-ledger', 'puri_render_inventory_ledger_page');
    add_submenu_page('pr-dashboard', 'Laba / Rugi', '📊 Laba / Rugi', 'manage_options', 'puri-pl-report', 'puri_render_pl_page');
    add_submenu_page('pr-dashboard', 'Matriks Penjualan', '📈 Matriks Sales', 'manage_options', 'puri-matrix', 'puri_render_matrix_page');
    add_submenu_page('pr-dashboard', 'Neraca & Kas', '⚖️ Neraca & Kas', 'manage_options', 'puri-balance-sheet', 'puri_render_balance_sheet_page');
    add_submenu_page('pr-dashboard', 'Monitoring', '🖥️ Monitoring', 'manage_options', 'puri-monitoring', 'puri_render_monitoring_page');
    add_submenu_page('pr-dashboard', 'Pivot Sales', '📊 Pivot Sales', 'manage_options', 'puri-pivot-report', 'puri_render_pivot_report_page');

    // System group
    add_submenu_page('pr-dashboard', '', '<span style="color:#94a3b8;font-weight:700;border-top:1px solid #e2e8f0;display:block;padding-top:10px;margin-top:5px;pointer-events:none;">SISTEM</span>', 'manage_options', 'puri-sep-4', '__return_null');
    add_submenu_page('pr-dashboard', 'Profil & Bank', '🏢 Profil & Bank', 'manage_options', 'puri-profile', 'puri_render_profile_page_dummy');
    add_submenu_page('pr-dashboard', 'Integrasi Excel', '🔗 Integrasi Excel', 'manage_options', 'puri-webhook', 'puri_render_webhook_page');
    add_submenu_page('pr-dashboard', 'Maintenance', '⚙️ Maintenance', 'manage_options', 'puri-maintenance', 'puri_render_maintenance_page');
}, 1);

/**
 * Helper: Render welcome screen (simple)
 */
function puri_render_welcome_screen() {
    puri_check_cap('manage_options');
    $co_name = function_exists('get_field') ? get_field('cp_name', 'option') : 'Pusat Riyal';
    echo '<div class="wrap"><div style="background:#fff;padding:40px;border-radius:12px;border:1px solid #c3c4c7;">';
    echo '<h1 style="font-weight:900;font-size:32px;">🛡️ PORTAL ' . esc_html($co_name) . ' (v' . esc_html(PURI_VERSION) . ')</h1>';
    echo '<p style="font-size:16px;color:#64748b;">Refactor 6.0.1 - Security hardening & helpers.</p>';
    echo '</div></div>';
}

/**
 * Add a mass-sync button in the pr_item list screen
 * The action triggers admin_init handler that validates nonce and capability.
 */
add_action('restrict_manage_posts', function() {
    if (get_current_screen() && get_current_screen()->post_type === 'pr_item' && current_user_can('manage_options')) {
        $sync_url = wp_nonce_url(
            add_query_arg(['puri_mass_sync' => '1'], admin_url('edit.php?post_type=pr_item')),
            'puri_mass_sync_action',  // action name
            'puri_sync_nonce'          // nonce key name
        );
        echo '<a href="' . $sync_url . '" class="button button-primary" style="background:#0f172a;border:none;margin-left:10px;">⚡ SINKRONISASI MASSAL KE SQL</a>';
    }
});

/**
 * Execute mass-sync on admin_init.
 * NOTE: we intentionally protect by capability and nonce via transient (confirm via GET -> then render page with nonce form).
 */
add_action('admin_init', function() {
    // Step 1: Initial click from button (GET request dengan nonce)
    if (isset($_GET['puri_mass_sync']) && $_GET['puri_mass_sync'] === '1' && 
        get_current_screen() && get_current_screen()->post_type === 'pr_item') {
        
        // ✅ CRITICAL FIX: Verify nonce dari URL GET
        if (!isset($_GET['puri_sync_nonce']) || !wp_verify_nonce($_GET['puri_sync_nonce'], 'puri_mass_sync_action')) {
            wp_die('Security check failed. Nonce verification failed.', 'Unauthorized', ['response' => 403]);
        }
        
        puri_check_cap('manage_options');
        
        // Render confirmation UI with POST form
        add_action('admin_notices', function() {
            $action_url = esc_url(admin_url('edit.php?post_type=pr_item'));
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Mass Sync SKU ke SQL</strong> – Anda harus mengkonfirmasi operasi sinkronisasi massal.</p>';
            echo '<form method="post" style="display:inline-block;">';
            
            // ✅ Use helper function untuk create nonce field
            puri_create_admin_nonce_field();
            
            echo '<input type="hidden" name="puri_confirm_mass_sync" value="1" />';
            echo '<button class="button button-primary" type="submit">Konfirmasi Sinkronisasi</button>';
            echo '</form> ';
            
            // Cancel button dengan nonce di URL untuk clean redirect
            $cancel_url = wp_nonce_url(
                remove_query_arg(['puri_mass_sync', 'puri_sync_nonce']),
                'puri_cancel_sync',
                'cancel_nonce'
            );
            echo '<a class="button" href="' . esc_url($cancel_url) . '">Batalkan</a>';
            echo '</div>';
        });
    }

    // Step 2: Confirmed sync (POST request)
    if (isset($_POST['puri_confirm_mass_sync']) && isset($_POST['puri_admin_nonce'])) {
        // ✅ Verify POST nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['puri_admin_nonce']), 'puri_admin_action')) {
            wp_die('Nonce verification failed.', 'Security Error', ['response' => 403]);
        }
        
        puri_check_cap('manage_options');
        
        global $wpdb;
        $count = 0;
        $errors = [];
        
        // ✅ IMPROVEMENT: Add transaction for atomic operation
        $wpdb->query('START TRANSACTION');
        
        try {
            $posts = get_posts(['post_type' => 'pr_item', 'numberposts' => -1, 'fields' => 'ids']);
            
            foreach ($posts as $post_id) {
                $sku = get_field('item_sku_code', $post_id);
                if (!$sku) {
                    $errors[] = "Post ID {$post_id}: SKU kosong, dilewati";
                    continue;
                }
                
                // ✅ IMPROVEMENT: Validate data sebelum insert
                $item_data = [
                    'sku' => sanitize_text_field($sku),
                    'name' => get_the_title($post_id),
                    'type' => get_field('type', $post_id) ?: 'currency',
                    'denom_value' => max(1, intval(get_field('denom_value', $post_id) ?: 1)),
                    'sell_rate' => max(0, floatval(get_field('sell_rate', $post_id) ?: 0))
                ];
                
                $result = $wpdb->replace(
                    $wpdb->prefix . T_ITEMS, 
                    $item_data
                );
                
                if ($result === false) {
                    throw new Exception("Failed to sync post ID {$post_id}: " . $wpdb->last_error);
                }
                
                $count++;
            }
            
            // ✅ Commit jika semua berhasil
            $wpdb->query('COMMIT');
            
            // Success notice
            add_action('admin_notices', function() use ($count, $errors) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>✅ <strong>Sukses!</strong> ' . intval($count) . ' Item SKU telah disinkronkan ke tabel SQL.</p>';
                
                if (!empty($errors)) {
                    echo '<details><summary>Peringatan (' . count($errors) . ' item dilewati)</summary><ul>';
                    foreach (array_slice($errors, 0, 10) as $err) {
                        echo '<li>' . esc_html($err) . '</li>';
                    }
                    if (count($errors) > 10) {
                        echo '<li><em>... dan ' . (count($errors) - 10) . ' lainnya</em></li>';
                    }
                    echo '</ul></details>';
                }
                echo '</div>';
            });
            
        } catch (Exception $e) {
            // ✅ Rollback on error
            $wpdb->query('ROLLBACK');
            
            add_action('admin_notices', function() use ($e, $count) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>❌ <strong>Gagal!</strong> Sinkronisasi dibatalkan setelah ' . intval($count) . ' item.</p>';
                echo '<p>Error: ' . esc_html($e->getMessage()) . '</p>';
                echo '</div>';
            });
        }
    }
});