<?php
/**
 * MC 17 - Security & Maintenance Hub (Final Fixed v6.0.10)
 * Version: 6.0.10
 * Author: Denmas Totok (Refactor by AI)
 *
 * Purpose:
 * - Maintenance Tool: Repair Tables & Sync Data
 * - Security Tool: Hard Reset (Truncate)
 * * Changelog v6.0.10:
 * - CRITICAL FIX: Restore 'puri_get_integrity_status' with function_exists check.
 * (Mencegah error 'undefined function' di MC-00 atau 'redeclare' error).
 * - FIX: Mass Sync logic removes ghost items (Clean Sync).
 * - KEEP: Hard Reset logic uses WP Password (Legacy v6.0.1).
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'puri-setting', 
        'Maintenance & Reset', 
        '⚠️ Maintenance', 
        'manage_options', 
        'puri-maintenance', 
        'puri_render_maintenance_page'
    );
	
	    // NEW: User Manager submenu
    add_submenu_page(
        'puri-setting',
        'User & Role Manager',
        '👥 Users & Roles',
        'manage_options',
        'puri-user-manager',
        'puri_render_user_manager_page'
    );

	
});

add_action('admin_init', function() {
    global $wpdb;

    // =========================================================================
    // 1. HANDLER: REPAIR & SYNC
    // =========================================================================
    if (isset($_POST['puri_do_maintenance_action']) && check_admin_referer('puri_mt_action')) {
        puri_check_cap('manage_options');
        
        // --- A. REPAIR TABLES ---
        if (isset($_POST['puri_do_repair_tables'])) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $collate = $wpdb->get_charset_collate();
            
            // Tabel Items (Bridge wp_post_id)
            $sql_items = "CREATE TABLE IF NOT EXISTS " . puri_table_name('T_ITEMS') . " (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                wp_post_id bigint(20) UNSIGNED DEFAULT 0, 
                sku varchar(50) NOT NULL,
                name varchar(100) NOT NULL,
                type enum('currency','goods','package') DEFAULT 'currency',
                denom_value int DEFAULT 1,
                base_price decimal(19,4) DEFAULT 0,
                sell_rate decimal(19,4) DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY sku (sku),
                KEY idx_wp_post_id (wp_post_id)
            ) $collate;";
            
            // Tabel Stock
            $sql_stock = "CREATE TABLE IF NOT EXISTS " . puri_table_name('T_STOCK') . " (
                location_id varchar(20) NOT NULL,
                item_id bigint(20) NOT NULL,
                qty decimal(19,4) DEFAULT 0,
                cost_avg decimal(19,4) DEFAULT 0,
                PRIMARY KEY (location_id, item_id)
            ) $collate;";
            
            dbDelta($sql_items);
            dbDelta($sql_stock);
            
            // Patch Column Check
            $row = $wpdb->get_results("SHOW COLUMNS FROM " . puri_table_name('T_ITEMS') . " LIKE 'wp_post_id'");
            if (empty($row)) {
                $wpdb->query("ALTER TABLE " . puri_table_name('T_ITEMS') . " ADD COLUMN wp_post_id bigint(20) UNSIGNED DEFAULT 0 AFTER id");
                $wpdb->query("ALTER TABLE " . puri_table_name('T_ITEMS') . " ADD INDEX idx_wp_post_id (wp_post_id)");
            }
            
            puri_nuke_cache(); 
            set_transient('puri_mt_notice', 'Struktur Tabel & Bridge ID Diperbaiki!');
        }
        
        // --- B. MASS SYNC (GHOST BUSTER MODE) ---
        if (isset($_POST['puri_do_mass_sync'])) {
            $posts = get_posts(['post_type'=>'pr_item','numberposts'=>-1]);
            $count_upd = 0;
            $valid_wp_ids = [];
            
            // 1. Update/Insert Data yang ada di WP
            foreach ($posts as $p) {
                $sku = get_field('item_sku_code', $p->ID);
                if (!$sku) continue;
                
                $valid_wp_ids[] = $p->ID; 
                
                $wpdb->replace(puri_table_name('T_ITEMS'), [
                    'wp_post_id'  => $p->ID, 
                    'sku'         => sanitize_text_field($sku),
                    'name'        => $p->post_title,
                    'type'        => get_field('type', $p->ID),
                    'denom_value' => intval(get_field('denom_value', $p->ID) ?: 1),
                    'sell_rate'   => floatval(get_field('sell_rate', $p->ID) ?: 0)
                ]);
                $count_upd++;
            }

            // 2. Hapus Ghost Items (Ada di SQL tapi tidak ada di WP)
            $deleted_ghosts = 0;
            if (!empty($valid_wp_ids)) {
                $ids_str = implode(',', array_map('intval', $valid_wp_ids));
                // Hapus hanya yang punya Bridge ID > 0 tapi ID-nya tidak ada di WP
                $deleted_ghosts = $wpdb->query("DELETE FROM " . puri_table_name('T_ITEMS') . " WHERE wp_post_id > 0 AND wp_post_id NOT IN ($ids_str)");
            } elseif ($count_upd == 0) {
                // Jika WP kosong, SQL juga harus kosong
                $deleted_ghosts = $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_ITEMS'));
            }

            puri_nuke_cache(); 
            set_transient('puri_mt_notice', "Sync Selesai: $count_upd updated, $deleted_ghosts hantu dibersihkan.");
        }
		
		// NEW: User Role Update Handler
		if (isset($_POST['puri_update_user_role']) && check_admin_referer('puri_user_role_update')) {
			puri_check_cap('manage_options');
			
			$user_id = intval($_POST['user_id']);
			$new_role = sanitize_text_field($_POST['user_role']);
			
			// Validate role
			$valid_puri_roles = ['kasir', 'kasir_plus', 'finance', 'ceo', 'administrator'];
			$valid_wp_roles = ['subscriber', 'contributor', 'author', 'editor'];
			$all_valid_roles = array_merge($valid_puri_roles, $valid_wp_roles);
			
			if (in_array($new_role, $all_valid_roles)) {
				$user = get_user_by('id', $user_id);
				if ($user) {
					$user->set_role($new_role);
					set_transient('puri_user_notice', 'Role berhasil diupdate untuk ' . $user->display_name);
				}
			}
			
			wp_redirect(admin_url('admin.php?page=puri-user-manager'));
			exit;
		}
		
		// NEW: Bulk Upgrade Kasir → Kasir Plus
		if (isset($_POST['puri_bulk_upgrade_kasir']) && check_admin_referer('puri_bulk_action')) {
			puri_check_cap('manage_options');
			
			$kasir_users = get_users(['role' => 'kasir']);
			$upgraded = 0;
			
			foreach ($kasir_users as $user) {
				$user->set_role('kasir_plus');
				$upgraded++;
			}
			
			set_transient('puri_user_notice', "$upgraded kasir berhasil di-upgrade ke Kasir Plus");
			wp_redirect(admin_url('admin.php?page=puri-user-manager'));
			exit;
		}

        
        wp_redirect(admin_url('admin.php?page=puri-maintenance'));
        exit;
    }

    // =========================================================================
    // 2. HANDLER: HARD RESET (LEGACY v6.0.1 LOGIC)
    // =========================================================================
    if (isset($_POST['puri_do_hard_reset']) && check_admin_referer('puri_reset_action')) {
        puri_check_cap('manage_options');
        
        $pass = $_POST['reset_confirm_password'] ?? '';
        $current_user = wp_get_current_user();
        
        // Verifikasi Password WP Asli
        if (!wp_check_password($pass, $current_user->data->user_pass, $current_user->ID)) {
            set_transient('puri_mt_error', "Password Salah! Reset dibatalkan.");
            wp_redirect(admin_url('admin.php?page=puri-maintenance')); 
            exit;
        }
        
        $targets = $_POST['reset_targets'] ?? [];
        if (empty($targets)) {
            set_transient('puri_mt_error', "Pilih target data dulu.");
            wp_redirect(admin_url('admin.php?page=puri-maintenance')); 
            exit;
        }
        
        if (in_array('m_journal', $targets)) { 
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_JOURNAL')); 
        }
        if (in_array('m_stock', $targets)) { 
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_LEDGER')); 
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_STOCK'));
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_LOCKS'));  
        }
        if (in_array('m_consign', $targets)) { 
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_CONSIGN')); 
        }
        if (in_array('m_item', $targets)) {
            $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_ITEMS'));
        }
        if (in_array('m_vendor', $targets)) {
            $vs = get_posts(['post_type'=>'pr_vendor','numberposts'=>-1]);
            foreach($vs as $v) wp_delete_post($v->ID, true);
        }
        if (in_array('m_customer', $targets)) {
            $cs = get_posts(['post_type'=>'pr_customer','numberposts'=>-1]);
            foreach($cs as $c) wp_delete_post($c->ID, true);
        }
		// --- ADDED: POOL SYSTEM RESET (Following mc-17 legacy pattern) ---
        if (in_array('m_pool', $targets)) { 
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "puri_pool_transactions");
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "puri_pool_stock");
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "puri_pool_journal");
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "puri_pool_eod_batches");
            $logs[] = "Seluruh Arsitektur Pool (4 Tabel) berhasil dikosongkan.";
        }
		
		
		
        
        puri_nuke_cache(); 
        set_transient('puri_mt_notice', "Hard Reset Berhasil.");
        wp_redirect(admin_url('admin.php?page=puri-maintenance'));
        exit;
    }
});

/**
 * NUCLEAR CACHE CLEAR
 */
function puri_nuke_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_puri_%'");
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_puri_%'");
    if (function_exists('wp_cache_flush')) wp_cache_flush();
}

/**
 * PURI Integrity Check (SAFE GUARDED)
 * Fungsi ini dibutuhkan oleh MC-00. Kita definisikan di sini jika belum ada.
 */
if (!function_exists('puri_get_integrity_status')) {
    function puri_get_integrity_status() {
        global $wpdb;
        $wp_count = wp_count_posts('pr_item')->publish;
        $table_items = puri_table_name('T_ITEMS');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_items'") != $table_items) {
            $sql_count = 0;
        } else {
            $sql_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_items WHERE wp_post_id > 0");
        }
        
        $synced = ($wp_count == $sql_count);

        // Return Hybrid Format (Kompatibel dengan semua versi MC-00)
        return [
            // Format Baru
            'is_synced' => $synced,
            'wp_count'  => $wp_count,
            'sql_count' => $sql_count,
            // Format Lama (Legacy)
            'items' => [
                'wp'     => $wp_count,
                'sql'    => $sql_count,
                'synced' => $synced
            ]
        ];
    }
}

function puri_render_maintenance_page() {
    $notice = get_transient('puri_mt_notice');
    $error  = get_transient('puri_mt_error');
    delete_transient('puri_mt_notice'); 
    delete_transient('puri_mt_error');
    
    // Panggil fungsi status (sekarang aman)
    $status = puri_get_integrity_status();
    
    // Ambil data (aman karena format hybrid)
    $is_synced = $status['is_synced'];
    $wp_count  = $status['wp_count'];
    $sql_count = $status['sql_count'];

    ?>
    <div class="wrap">
      <h1>⚠️ System Maintenance & Reset</h1>
      
      <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
      <?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
      
      <div class="card" style="margin-top:20px;">
        <h2>📊 System Integrity Status</h2>
        <table class="widefat fixed striped">
           <thead><tr><th>Component</th><th>WordPress Data</th><th>SQL Data</th><th>Status</th></tr></thead>
           <tbody>
             <tr>
               <td>Master Items (SKU)</td>
               <td><?php echo $wp_count; ?> items</td>
               <td><?php echo $sql_count; ?> rows</td>
               <td>
                 <?php if($is_synced): ?>
                   <span style="color:green;font-weight:bold;">✅ SYNCED</span>
                 <?php else: ?>
                   <span style="color:red;font-weight:bold;">❌ OUT OF SYNC</span>
                 <?php endif; ?>
               </td>
             </tr>
           </tbody>
        </table>
        
        <form method="post" style="margin-top:15px;">
           <?php wp_nonce_field('puri_mt_action'); ?>
           <input type="hidden" name="puri_do_maintenance_action" value="1">
           <button type="submit" name="puri_do_repair_tables" class="button button-secondary">🛠 Perbaiki Struktur Tabel (SQL)</button>
           <button type="submit" name="puri_do_mass_sync" class="button button-secondary">⚡ Mass Sync SKU ke SQL</button>
        </form>
      </div>
      
      <form method="post" style="margin-top:30px;" class="card">
        <h2 style="color:#dc2626;">☠️ DANGER ZONE: Hard Reset</h2>
        <p>Pilih data yang ingin DIHAPUS PERMANEN:</p>
        <?php wp_nonce_field('puri_reset_action'); ?>
        
        <p>
           <label><input type="checkbox" name="reset_targets[]" value="m_journal"> <strong>Jurnal Akuntansi</strong> (T_JOURNAL)</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_stock"> <strong>Data Stok & Mutasi</strong> (T_STOCK, T_LEDGER)</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_consign"> <strong>Data Konsinyasi</strong> (T_CONSIGN)</label><br>
		   <label><input type="checkbox" name="reset_targets[]" value="m_pool"> <strong>Arsitektur Pool POS</strong> (T_POOL_TRANSACTIONS, STOCK, JOURNAL, EOD)</label>
           <hr>
           <label><input type="checkbox" name="reset_targets[]" value="m_item"> <strong>Master Item SQL</strong> (T_ITEMS - <em>Perlu Sync Ulang</em>)</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_vendor"> Master Vendor (CPT)</label><br>
           <label><input type="checkbox" name="reset_targets[]" value="m_customer"> Master Customer (CPT)</label>
        </p>
        
        <div style="background:#fff1f2;padding:12px;border:1px solid #fecaca">
          <label><strong>Password Login Anda:</strong></label><br>
          <input type="password" name="reset_confirm_password" required style="width:300px;padding:8px;margin-top:5px;"><br>
          <button type="submit" name="puri_do_hard_reset" class="button button-primary" style="background:#dc2626;border-color:#b91c1c;" onclick="return confirm('YAKIN MENGHAPUS DATA? TINDAKAN INI TIDAK BISA DIBATALKAN!')">🔥 EKSEKUSI PENGHAPUSAN DATA</button>
        </div>
      </form>
    </div>
    <?php
}


// ========================================================================
// NEW: USER MANAGER PAGE RENDERER
// ========================================================================

function puri_render_user_manager_page() {
    
    $notice = get_transient('puri_user_notice');
    delete_transient('puri_user_notice');
    
    // Get users by role
    $admin_users = get_users(['role' => 'administrator']);
    $ceo_users = get_users(['role' => 'ceo']);
    $finance_users = get_users(['role' => 'finance']);
    $kasir_plus_users = get_users(['role' => 'kasir_plus']);
    $kasir_users = get_users(['role' => 'kasir']);
    
    $all_puri_users = array_merge($admin_users, $ceo_users, $finance_users, $kasir_plus_users, $kasir_users);
    
    ?>
    <div class="wrap">
        <h1>👥 Puri User & Role Manager</h1>
        
        <?php if ($notice): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($notice); ?></p>
            </div>
        <?php endif; ?>
        
        <!-- SECTION 1: ROLE STATISTICS -->
        <div class="card">
            <h2>📊 Role Distribution</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:25%;">Role</th>
                        <th style="width:45%;">Capabilities</th>
                        <th style="width:15%;">Users</th>
                        <th style="width:15%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#f0f9ff;">
                        <td><strong>🔴 Administrator</strong></td>
                        <td>
                            <code>manage_options</code>, 
                            <code>manage_finance</code>, 
                            <code>can_entry</code>, 
                            <small>+ ALL WP CAPS</small>
                        </td>
                        <td><strong><?php echo count($admin_users); ?></strong></td>
                        <td>-</td>
                    </tr>
                    <tr style="background:#fef3c7;">
                        <td><strong>🟡 CEO</strong></td>
                        <td>
                            <code>manage_finance</code>, 
                            <code>can_entry</code>, 
                            <code>edit_posts</code>
                        </td>
                        <td><strong><?php echo count($ceo_users); ?></strong></td>
                        <td>
                            <?php if (empty($ceo_users)): ?>
                                <small style="color:#666;">Role disabled</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>🟢 Finance Manager</strong></td>
                        <td>
                            <code>manage_finance</code>, 
                            <code>can_entry</code>, 
                            <code>upload_files</code>
                        </td>
                        <td><strong><?php echo count($finance_users); ?></strong></td>
                        <td>-</td>
                    </tr>
                    <tr style="background:#dbeafe;">
                        <td><strong>🔵 Kasir + Author</strong></td>
                        <td>
                            <code>can_entry</code>, 
                            <code>edit_posts</code>, 
                            <code>publish_posts</code>
                        </td>
                        <td><strong><?php echo count($kasir_plus_users); ?></strong></td>
                        <td>
                            <?php if (count($kasir_users) > 0): ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('puri_bulk_action'); ?>
                                    <button type="submit" name="puri_bulk_upgrade_kasir" 
                                            class="button button-small"
                                            onclick="return confirm('Upgrade semua Kasir basic ke Kasir Plus?')">
                                        ⬆️ Bulk Upgrade
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>⚪ Kasir (Basic)</strong></td>
                        <td>
                            <code>can_entry</code>, 
                            <code>read</code>
                        </td>
                        <td><strong><?php echo count($kasir_users); ?></strong></td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- SECTION 2: USER LIST WITH QUICK EDIT -->
        <div class="card" style="margin-top:20px;">
            <h2>📋 Manage Puri Users</h2>
            
            <?php if (empty($all_puri_users)): ?>
                <p>Belum ada user dengan Puri roles. Silakan buat user baru melalui menu WP-Admin → Users.</p>
                <a href="<?php echo admin_url('user-new.php'); ?>" class="button button-primary">
                    ➕ Add New User
                </a>
            <?php else: ?>
                
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:15%;">User</th>
                            <th style="width:20%;">Email</th>
                            <th style="width:15%;">Current Role</th>
                            <th style="width:30%;">Capabilities</th>
                            <th style="width:20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_puri_users as $user): ?>
                            <?php
                            $role_slug = $user->roles[0] ?? 'none';
                            $role_color = [
                                'administrator' => '#dc2626',
                                'ceo'           => '#f59e0b',
                                'finance'       => '#10b981',
                                'kasir_plus'    => '#3b82f6',
                                'kasir'         => '#6b7280'
                            ];
                            $color = $role_color[$role_slug] ?? '#000';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($user->user_login); ?></strong><br>
                                    <small style="color:#666;"><?php echo esc_html($user->display_name); ?></small>
                                </td>
                                <td>
                                    <a href="mailto:<?php echo esc_attr($user->user_email); ?>">
                                        <?php echo esc_html($user->user_email); ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="display:inline-block;padding:4px 10px;background:<?php echo $color; ?>;color:#fff;border-radius:3px;font-size:11px;font-weight:bold;">
                                        <?php echo esc_html(strtoupper(str_replace('_', ' ', $role_slug))); ?>
                                    </span>
                                </td>
                                <td style="font-size:11px;">
                                    <?php if ($user->has_cap('manage_options')): ?>
                                        <span style="color:#dc2626;">● Admin</span> &nbsp;
                                    <?php endif; ?>
                                    <?php if ($user->has_cap('manage_finance')): ?>
                                        <span style="color:#10b981;">● Finance</span> &nbsp;
                                    <?php endif; ?>
                                    <?php if ($user->has_cap('can_entry')): ?>
                                        <span style="color:#3b82f6;">● Entry</span> &nbsp;
                                    <?php endif; ?>
                                    <?php if ($user->has_cap('edit_posts')): ?>
                                        <span style="color:#f59e0b;">● Author</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display:flex;gap:5px;align-items:center;">
                                        <?php wp_nonce_field('puri_user_role_update'); ?>
                                        <input type="hidden" name="user_id" value="<?php echo $user->ID; ?>">
                                        
                                        <select name="user_role" style="font-size:11px;padding:4px;">
                                            <optgroup label="Puri Roles">
                                                <option value="administrator" <?php selected($role_slug, 'administrator'); ?>>Admin</option>
                                                <option value="finance" <?php selected($role_slug, 'finance'); ?>>Finance</option>
                                                <option value="kasir_plus" <?php selected($role_slug, 'kasir_plus'); ?>>Kasir Plus</option>
                                                <option value="kasir" <?php selected($role_slug, 'kasir'); ?>>Kasir</option>
                                            </optgroup>
                                            <optgroup label="Downgrade to WP">
                                                <option value="subscriber">→ Subscriber</option>
                                                <option value="author">→ Author</option>
                                            </optgroup>
                                        </select>
                                        
                                        <button type="submit" name="puri_update_user_role" 
                                                class="button button-small button-primary">
                                            Update
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php endif; ?>
        </div>
        
        <!-- SECTION 3: CAPABILITY MATRIX (REFERENCE) -->
        <div class="card" style="margin-top:20px;">
            <h2>🔐 Capability Matrix (Quick Reference)</h2>
            <p style="color:#666;font-size:13px;">
                Use this table to understand what each role can access in the system.
            </p>
            
            <table class="widefat fixed" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th>Menu / Feature</th>
                        <th>Required Cap</th>
                        <th>Admin</th>
                        <th>CEO</th>
                        <th>Finance</th>
                        <th>Kasir+</th>
                        <th>Kasir</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Dashboard</td>
                        <td><code>read</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                    </tr>
                    <tr>
                        <td>POS Cockpit</td>
                        <td><code>can_entry</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                    </tr>
                    <tr>
                        <td>Input Opname</td>
                        <td><code>can_entry</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                    </tr>
                    <tr>
                        <td>Procurement (Kulakan)</td>
                        <td><code>manage_finance</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Financial Reports</td>
                        <td><code>manage_finance</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Expenses (Biaya)</td>
                        <td><code>manage_finance</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Write Blog Posts</td>
                        <td><code>edit_posts</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>✅</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Media Library</td>
                        <td><code>upload_files</code></td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>✅</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Setup & Maintenance</td>
                        <td><code>manage_options</code></td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>Plugins / Themes</td>
                        <td><code>manage_options</code></td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                    <tr>
                        <td>User Management</td>
                        <td><code>manage_options</code></td>
                        <td>✅</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                        <td>❌</td>
                    </tr>
                </tbody>
            </table>
        </div>
		
		<!-- SECTION 4: QUICK GUIDES -->
    <div class="card" style="margin-top:20px;">
        <h2>📚 Quick Setup Guides</h2>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:15px;">
            
            <div style="border:1px solid #ddd;padding:15px;border-radius:5px;">
                <h3 style="margin-top:0;color:#3b82f6;">➕ Add New Kasir</h3>
                <ol style="font-size:13px;line-height:1.8;">
                    <li>Go to <strong>Users → Add New</strong></li>
                    <li>Fill username, email, password</li>
                    <li>Set Role: <strong>PURI Kasir</strong></li>
                    <li>Click "Add New User"</li>
                </ol>
                <p style="font-size:12px;color:#666;margin:0;">
                    User akan otomatis dapat akses ke POS Cockpit saja.
                </p>
            </div>
            
            <div style="border:1px solid #ddd;padding:15px;border-radius:5px;">
                <h3 style="margin-top:0;color:#10b981;">⬆️ Upgrade Kasir → Kasir Plus</h3>
                <ol style="font-size:13px;line-height:1.8;">
                    <li>Cari user di tabel di atas</li>
                    <li>Ubah dropdown role ke <strong>Kasir Plus</strong></li>
                    <li>Click "Update"</li>
                </ol>
                <p style="font-size:12px;color:#666;margin:0;">
                    User akan dapat tambahan akses ke Posts & Media Library.
                </p>
            </div>
            
            <div style="border:1px solid #ddd;padding:15px;border-radius:5px;">
                <h3 style="margin-top:0;color:#f59e0b;">👔 Promote to Finance Manager</h3>
                <ol style="font-size:13px;line-height:1.8;">
                    <li>Pastikan user sudah paham sistem</li>
                    <li>Ubah role ke <strong>Finance</strong></li>
                    <li>User akan dapat akses Procurement & Reports</li>
                </ol>
                <p style="font-size:12px;color:#666;margin:0;">
                    Finance Manager bisa approve transaksi besar.
                </p>
            </div>
            
            <div style="border:1px solid #ddd;padding:15px;border-radius:5px;">
                <h3 style="margin-top:0;color:#dc2626;">🔽 Downgrade / Remove Access</h3>
                <ol style="font-size:13px;line-height:1.8;">
                    <li>Ubah role ke <strong>Subscriber</strong></li>
                    <li>User hanya bisa login & edit profil sendiri</li>
                    <li>Tidak bisa akses menu Puri apapun</li>
                </ol>
                <p style="font-size:12px;color:#666;margin:0;">
                    Gunakan untuk suspend akses tanpa hapus user.
                </p>
            </div>
            
        </div>
    </div>
    
</div>

<style>
    .card {min-width: 1042px;}
    .card h2 {
        border-bottom: 2px solid #2271b1;
        padding-bottom: 10px;
        margin-bottom: 15px;
    }
</style>
<?php
}


// Snippet untuk diletakkan di fungsi render MC-17 (Setup)
function puri_render_uninstall_settings() {
    $current_fallback = get_option( 'puri_uninstall_fallback_role', 'subscriber' );
    $delete_flag      = get_option( 'puri_delete_data_on_uninstall', 'no' );
    $wp_roles         = wp_roles()->get_names(); // Ambil semua role yang ada di WP
    ?>
    <div class="card">
        <h3>🛠 Uninstall Settings (Danger Zone)</h3>
        <table class="form-table">
            <tr>
                <th>Delete Data?</th>
                <td>
                    <input type="checkbox" name="puri_delete_data_on_uninstall" value="yes" <?php checked($delete_flag, 'yes'); ?>>
                    <p class="description">Hapus semua tabel database Puri saat plugin dihapus.</p>
                </td>
            </tr>
            <tr>
                <th>Fallback Role</th>
                <td>
                    <select name="puri_uninstall_fallback_role">
                        <?php foreach ( $wp_roles as $role_slug => $role_name ) : ?>
                            <?php if ( !in_array($role_slug, ['finance', 'kasir']) ) : ?>
                                <option value="<?php echo $role_slug; ?>" <?php selected($current_fallback, $role_slug); ?>>
                                    <?php echo $role_name; ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">User Finance/Kasir akan dipindahkan ke role ini saat plugin dihapus.</p>
                </td>
            </tr>
        </table>
    </div>
    <?php
}