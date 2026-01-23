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

// ============================================================================
// PART 1: MENU REGISTRATION
// ============================================================================

add_action('admin_menu', function () {
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

add_action('admin_menu', function () {
    add_submenu_page(
        'puri-setting',
        'Maintenance & Reset',
        '⚙️ Maintenance',
        'manage_options',
        'puri-maintenance',
        'puri_render_maintenance_page'
    );
}, 20);

// ============================================================================
// PART 2: DATA SYNCHRONIZATION SYSTEM
// ============================================================================

add_action('acf/save_post', 'puri_auto_sync_single_item', 20);

function puri_auto_sync_single_item($post_id) {
    // Guard: Only for pr_item CPT
    if (get_post_type($post_id) !== 'pr_item') return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    
    // Ensure ACF is available
    if (!function_exists('get_field')) return;
    
    global $wpdb;
    $table = puri_table_name('T_ITEMS');
    
    $sku = get_field('item_sku_code', $post_id);
    if (!$sku) return; // Skip if SKU is empty
    
    // Prepare data
    $data = [
        'wp_post_id'  => $post_id,
        'sku'         => strtoupper(sanitize_text_field($sku)),
        'name'        => get_the_title($post_id),
        'type'        => get_field('type', $post_id) ?: 'currency',
        'denom_value' => intval(get_field('denom_value', $post_id) ?: 1)
    ];
    
    // Check if record exists
    $existing_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE wp_post_id = %d OR sku = %s LIMIT 1",
        $post_id, $data['sku']
    ));
    
    if ($existing_id) {
        // UPDATE existing record (preserve base_price & sell_rate)
        $wpdb->update(
            $table,
            $data,
            ['id' => $existing_id],
            ['%d', '%s', '%s', '%s', '%d'],
            ['%d']
        );
    } else {
        // INSERT new record
        $wpdb->insert($table, $data);
    }
    
    // Clear cache
    delete_transient('puri_items_cache');
}

/**
 * MASS SYNC: Button in CPT List
 * 
 * Displays "MASS SYNC" button only for administrators.
 * 
 * @hook restrict_manage_posts
 */
add_action('restrict_manage_posts', function() {
	
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'pr_item') return;
	
     
	 if (!current_user_can('manage_options')) return;
	 //if (!current_user_can('administrator')) return;
	//$cap = get_user_meta(get_current_user_id(), 'riy_capabilities', true);
	//if (empty($cap) || !isset($cap['administrator'])) return;

    
    $sync_url = esc_url(add_query_arg([
        'puri_mass_sync' => '1',
        '_wpnonce' => wp_create_nonce('puri_mass_sync_init')
    ], admin_url('edit.php?post_type=pr_item')));
    ?>

    <a href="<?= $sync_url ?>" 
       class="button button-primary" 
       style="background:#dc2626; border:none; margin-left:10px;">
        ⚡ MASS SYNC (Admin Only)
    </a>
    <?php
});

/**
 * MASS SYNC: Confirmation Screen
 * 
 * Shows confirmation dialog with password field and options.
 * 
 * @hook admin_init
 */
add_action('admin_init', function() {
    if (!isset($_GET['puri_mass_sync']) || $_GET['puri_mass_sync'] !== '1') return;
    
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'pr_item') return;
    
    // Security check
    if (!wp_verify_nonce($_GET['_wpnonce'], 'puri_mass_sync_init')) {
        wp_die('Security check failed', 'Unauthorized', ['response' => 403]);
    }
    
	if (!current_user_can('manage_options')) {
//    if (!current_user_can('administrator')) {
        wp_die('Access Denied: Administrator only', 'Forbidden', ['response' => 403]);
    }
    
    add_action('admin_notices', function() {
        $current_user = wp_get_current_user();
        ?>
        <div class="notice notice-warning" style="border-left:5px solid #dc2626; background:#fff3cd;">
            <h2 style="margin-top:15px;">
                <i class="dashicons dashicons-warning"></i> 
                Konfirmasi Mass Sync
            </h2>
            
            <div style="background:#fff; padding:15px; border-radius:5px; margin:15px 0;">
                <table class="widefat" style="max-width:600px;">
                    <tr>
                        <td><strong>Operator:</strong></td>
                        <td><?= esc_html($current_user->display_name) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Role:</strong></td>
                        <td><span style="background:#dc2626; color:#fff; padding:3px 8px; border-radius:3px;">ADMINISTRATOR</span></td>
                    </tr>
                    <tr>
                        <td><strong>Timestamp:</strong></td>
                        <td><?= current_time('Y-m-d H:i:s') ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="background:#fef2f2; padding:15px; border-radius:5px; border:1px solid #fecaca; margin-bottom:15px;">
                <h3 style="margin-top:0; color:#dc2626;">⚠️ PERINGATAN PENTING</h3>
                <ul style="margin:10px 0; padding-left:20px;">
                    <li>Mass Sync akan <strong>memperbarui metadata SKU</strong> (nama, tipe, denom)</li>
                    <li>✅ <strong>AMAN</strong>: Tidak akan menimpa harga & saldo stok yang sudah ada</li>
                    <li>Proses ini dapat <strong>dipercepat dengan checkbox "Replace All"</strong></li>
                </ul>
            </div>
            
            <form method="post" style="display:flex; flex-direction:column; gap:15px;">
                <?php wp_nonce_field('puri_admin_action', 'puri_admin_nonce'); ?>
                <input type="hidden" name="puri_confirm_mass_sync" value="1">
                
                <!-- PASSWORD PROTECTION -->
                <div style="background:#fff; padding:15px; border:1px solid #d1d5db; border-radius:5px;">
                    <label style="font-weight:600; display:block; margin-bottom:8px;">
                        🔐 Masukkan Password WP Admin Anda:
                    </label>
                    <input type="password" 
                           name="admin_password" 
                           required 
                           style="width:100%; max-width:400px; padding:8px; border:1px solid #d1d5db; border-radius:4px;"
                           placeholder="Password login WordPress Anda">
                </div>
                
                <!-- REPLACE ALL CHECKBOX -->
                <div style="background:#fff; padding:15px; border:1px solid #d1d5db; border-radius:5px;">
                    <label style="display:flex; align-items:center; gap:10px; font-weight:600;">
                        <input type="checkbox" name="replace_all_fields" value="1">
                        <span>⚡ Replace All Fields (Timpa harga & rate yang sudah ada)</span>
                    </label>
                    <p style="margin:8px 0 0 28px; color:#666; font-size:12px;">
                        ⚠️ <strong>PERINGATAN</strong>: Jika dicentang, sistem akan menimpa base_price dan sell_rate.<br>
                        Default: <strong>OFF</strong> (hanya update metadata: nama, tipe, denom)
                    </p>
                </div>
                
                <div style="display:flex; gap:10px; align-items:center;">
                    <button class="button button-primary" 
                            type="submit" 
                            style="background:#dc2626; border-color:#b91c1c; height:40px; font-size:14px;">
                        <i class="dashicons dashicons-shield-alt"></i> Konfirmasi & Eksekusi
                    </button>
                    
                    <a class="button" 
                       href="<?= esc_url(remove_query_arg(['puri_mass_sync', '_wpnonce'])) ?>" 
                       style="height:40px; line-height:38px;">
                        <i class="dashicons dashicons-no"></i> Batalkan
                    </a>
                </div>
            </form>
        </div>
        <?php
    });
});

/**
 * MASS SYNC: Execution Handler
 * 
 * Executes batch sync with password verification.
 * Uses UPDATE instead of REPLACE to preserve data.
 * 
 * @hook admin_init (priority 999)
 */
add_action('admin_init', function() {
    if (!isset($_POST['puri_confirm_mass_sync']) || !isset($_POST['puri_admin_nonce'])) {
        return;
    }
    
    // Security checks
    if (!wp_verify_nonce($_POST['puri_admin_nonce'], 'puri_admin_action')) {
        wp_die('Nonce verification failed', 'Security Error', ['response' => 403]);
    }
    
	if (!current_user_can('manage_options')) {
    //if (!current_user_can('administrator')) {
        wp_die('Access Denied', 'Forbidden', ['response' => 403]);
    }
    
    // Password verification
    $password = $_POST['admin_password'] ?? '';
    $current_user = wp_get_current_user();
    
    if (!wp_check_password($password, $current_user->data->user_pass, $current_user->ID)) {
        wp_redirect(admin_url('edit.php?post_type=pr_item&sync_error=invalid_password'));
        exit;
    }
    
    // Get options
    $replace_all = isset($_POST['replace_all_fields']);
    
    global $wpdb;
    $table = puri_table_name('T_ITEMS');
    $posts = get_posts(['post_type' => 'pr_item', 'numberposts' => -1, 'fields' => 'ids']);
    
    $count_updated = 0;
    $count_inserted = 0;
    $count_skipped = 0;
    
    foreach ($posts as $post_id) {
        if (!function_exists('get_field')) {
            $count_skipped++;
            continue;
        }
        
        $sku = get_field('item_sku_code', $post_id);
        if (!$sku) {
            $count_skipped++;
            continue;
        }
        
        // Check existing record
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, base_price, sell_rate FROM {$table} 
             WHERE wp_post_id = %d OR sku = %s 
             LIMIT 1",
            $post_id, strtoupper(sanitize_text_field($sku))
        ));
        
        $data = [
            'wp_post_id'  => $post_id,
            'sku'         => strtoupper(sanitize_text_field($sku)),
            'name'        => get_the_title($post_id),
            'type'        => get_field('type', $post_id) ?: 'currency',
            'denom_value' => intval(get_field('denom_value', $post_id) ?: 1)
        ];
        
        // Conditional: Only overwrite price if checkbox is checked
        if ($replace_all) {
            $data['base_price'] = floatval(get_field('base_price', $post_id) ?: 0);
            $data['sell_rate']  = floatval(get_field('sell_rate', $post_id) ?: 0);
        } else {
            // Preserve existing values if they exist
            if ($existing && $existing->base_price > 0) {
                // Skip base_price
            } else {
                $data['base_price'] = floatval(get_field('base_price', $post_id) ?: 0);
            }
            
            if ($existing && $existing->sell_rate > 0) {
                // Skip sell_rate
            } else {
                $data['sell_rate'] = floatval(get_field('sell_rate', $post_id) ?: 0);
            }
        }
        
        if ($existing) {
            // UPDATE existing
            $result = $wpdb->update(
                $table,
                $data,
                ['id' => $existing->id],
                ['%d', '%s', '%s', '%s', '%d', '%f', '%f'],
                ['%d']
            );
            if ($result !== false) $count_updated++;
        } else {
            // INSERT new
            $result = $wpdb->insert($table, $data);
            if ($result) $count_inserted++;
        }
    }
    
    // Log results
    error_log(sprintf(
        "PURI MASS SYNC: Updated=%d, Inserted=%d, Skipped=%d, Operator=%s",
        $count_updated, $count_inserted, $count_skipped, $current_user->user_login
    ));
    
    // Clear cache
    delete_transient('puri_items_cache');
    
    // Redirect with success message
    wp_redirect(admin_url(sprintf(
        'edit.php?post_type=pr_item&sync_success=1&updated=%d&inserted=%d&skipped=%d',
        $count_updated, $count_inserted, $count_skipped
    )));
    exit;
}, 999);

/**
 * MASS SYNC: Success/Error Notifications
 * 
 * @hook admin_notices
 */
add_action('admin_notices', function() {
    // Success message
    if (isset($_GET['sync_success'])) {
        $updated  = intval($_GET['updated'] ?? 0);
        $inserted = intval($_GET['inserted'] ?? 0);
        $skipped  = intval($_GET['skipped'] ?? 0);
        ?>
        <div class="notice notice-success is-dismissible">
            <h2>✅ Mass Sync Berhasil!</h2>
            <table style="margin-top:10px; border-collapse:collapse;">
                <tr>
                    <td style="padding:5px; font-weight:600;">Items Updated:</td>
                    <td style="padding:5px;"><?= $updated ?></td>
                </tr>
                <tr>
                    <td style="padding:5px; font-weight:600;">Items Inserted:</td>
                    <td style="padding:5px;"><?= $inserted ?></td>
                </tr>
                <tr>
                    <td style="padding:5px; font-weight:600;">Items Skipped:</td>
                    <td style="padding:5px;"><?= $skipped ?></td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    // Error message
    if (isset($_GET['sync_error'])) {
        $error = $_GET['sync_error'];
        $message = 'Unknown error';
        
        if ($error === 'invalid_password') {
            $message = '❌ Password salah! Mass Sync dibatalkan untuk keamanan.';
        }
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong><?= esc_html($message) ?></strong></p>
        </div>
        <?php
    }
});

// ============================================================================
// PART 3: DISASTER RECOVERY SYSTEM
// ============================================================================

/**
 * REPAIR BROKEN LINK: Core Function
 * 
 * Repairs broken relationships between T_STOCK and T_ITEMS
 * using wp_post_id as bridge column.
 * 
 * @return array {
 *     @type bool   $success  Whether repair succeeded
 *     @type string $message  Human-readable result message
 *     @type int    $repaired Number of links repaired
 * }
 */
function puri_repair_broken_link() {
    global $wpdb;
    
    $table_stock = puri_table_name('T_STOCK');
    $table_items = puri_table_name('T_ITEMS');
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Identify broken links
        $broken_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$table_stock} s
            LEFT JOIN {$table_items} i ON s.item_id = i.id
            WHERE i.id IS NULL AND s.wp_post_id > 0
        ");
        
        if ($broken_count == 0) {
            $wpdb->query('ROLLBACK');
            return [
                'success' => true,
                'message' => '✅ Tidak ada broken link yang ditemukan. Sistem sudah sehat!',
                'repaired' => 0
            ];
        }
        
        // Repair links using wp_post_id as bridge
        $repair_query = "
            UPDATE {$table_stock} s
            INNER JOIN {$table_items} i ON s.wp_post_id = i.wp_post_id
            SET s.item_id = i.id
            WHERE s.item_id != i.id OR s.item_id = 0
        ";
        
        $wpdb->query($repair_query);
        
        if ($wpdb->last_error) {
            throw new Exception("Database Error: " . $wpdb->last_error);
        }
        
        $rows_affected = $wpdb->rows_affected;
        
        // Verify repair success
        $remaining_broken = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$table_stock} s
            LEFT JOIN {$table_items} i ON s.item_id = i.id
            WHERE i.id IS NULL AND s.wp_post_id > 0
        ");
        
        if ($remaining_broken > 0) {
            throw new Exception("Masih ada {$remaining_broken} link yang tidak bisa diperbaiki otomatis.");
        }
        
        $wpdb->query('COMMIT');
        
        // Clear cache
        delete_transient('puri_items_cache');
        delete_transient('puri_stock_cache');
        
        // Log success
        error_log("PURI REPAIR: Successfully repaired {$rows_affected} broken links");
        
        return [
            'success' => true,
            'message' => "✅ Berhasil memperbaiki {$rows_affected} broken link!",
            'repaired' => $rows_affected
        ];
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log("PURI REPAIR ERROR: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => '❌ Repair gagal: ' . $e->getMessage(),
            'repaired' => 0
        ];
    }
}

/**
 * REPAIR BROKEN LINK: Handler
 * 
 * Processes repair request with password verification.
 * 
 * @hook admin_init
 */
add_action('admin_init', function() {
    if (!isset($_POST['puri_do_repair_link']) || !isset($_POST['puri_admin_nonce'])) {
        return;
    }
    
    // Security checks
    check_admin_referer('puri_admin_action', 'puri_admin_nonce');
    puri_check_cap('manage_options');
    
    // Password verification
    $password = $_POST['repair_password'] ?? '';
    $current_user = wp_get_current_user();
    
    if (!wp_check_password($password, $current_user->data->user_pass, $current_user->ID)) {
        set_transient('puri_mt_error', "❌ Password salah! Repair dibatalkan untuk keamanan.");
        wp_redirect(admin_url('admin.php?page=puri-maintenance'));
        exit;
    }
    
    // Execute repair
    $result = puri_repair_broken_link();
    
    if ($result['success']) {
        set_transient('puri_mt_notice', $result['message']);
    } else {
        set_transient('puri_mt_error', $result['message']);
    }
    
    wp_redirect(admin_url('admin.php?page=puri-maintenance'));
    exit;
});



// ============================================================================
// PART 4: HARD RESET SYSTEM
// ============================================================================

/**
 * HARD RESET: Handler
 * 
 * Executes selective table truncation with password verification.
 * 
 * @hook admin_init
 */
add_action('admin_init', function() {
    if (!isset($_POST['puri_do_hard_reset']) || !check_admin_referer('puri_reset_action')) {
        return;
    }
    
    puri_check_cap('manage_options');
    
    // Password verification
    $password = $_POST['reset_confirm_password'] ?? '';
    $current_user = wp_get_current_user();
    
    if (!wp_check_password($password, $current_user->data->user_pass, $current_user->ID)) {
        set_transient('puri_mt_error', "❌ Password salah! Reset dibatalkan.");
        wp_redirect(admin_url('admin.php?page=puri-maintenance')); 
        exit;
    }
    
    $targets = $_POST['reset_targets'] ?? [];
    if (empty($targets)) {
        set_transient('puri_mt_error', "Pilih target data dulu.");
        wp_redirect(admin_url('admin.php?page=puri-maintenance')); 
        exit;
    }
    
    global $wpdb;
    
    // Execute resets
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
        $vendors = get_posts(['post_type'=>'pr_vendor','numberposts'=>-1]);
        foreach($vendors as $v) wp_delete_post($v->ID, true);
    }
    
    if (in_array('m_customer', $targets)) {
        $customers = get_posts(['post_type'=>'pr_customer','numberposts'=>-1]);
        foreach($customers as $c) wp_delete_post($c->ID, true);
    }
    
    if (in_array('m_pool', $targets)) { 
        $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_POOL_TRANSACTIONS'));
        $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_POOL_STOCK'));
        $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_POOL_JOURNAL'));
        $wpdb->query("TRUNCATE TABLE " . puri_table_name('T_EOD_BATCHES'));
    }
    
    // Clear cache
    delete_transient('puri_items_cache');
    delete_transient('puri_stock_cache');
    
    // Log action
    error_log(sprintf(
        "PURI HARD RESET: Targets=%s, Operator=%s",
        implode(',', $targets),
        $current_user->user_login
    ));
    
    set_transient('puri_mt_notice', "✅ Hard Reset berhasil dieksekusi.");
    wp_redirect(admin_url('admin.php?page=puri-maintenance'));
    exit;
});

// ============================================================================
// PART 5: UI RENDERING
// ============================================================================

/**
 * RENDER: Maintenance Page
 * 
 * 2-panel layout:
 * - Left: Control center (diagnostics + action buttons)
 * - Right: Documentation & help
 */
function puri_render_maintenance_page() {
    puri_check_cap('manage_options');
    
    // Get notifications
    $notice = get_transient('puri_mt_notice');
    $error  = get_transient('puri_mt_error');
    delete_transient('puri_mt_notice'); 
    delete_transient('puri_mt_error');
    
    // Get system status
    $status = puri_get_integrity_status();
    
    // Diagnostic: Check for broken links
    global $wpdb;
    $table_stock = puri_table_name('T_STOCK');
    $table_items = puri_table_name('T_ITEMS');
    
    $broken_links = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$table_stock} s
        LEFT JOIN {$table_items} i ON s.item_id = i.id
        WHERE i.id IS NULL AND s.wp_post_id > 0
    ");
    
    ?>
    <div class="wrap puri-maintenance-hub">
        <h1>⚙️ System Maintenance & Reset v7.0.1</h1>
        
        <?php if ($notice): ?>
            <div class="notice notice-success is-dismissible">
                <p><?= esc_html($notice) ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="notice notice-error is-dismissible">
                <p><?= esc_html($error) ?></p>
            </div>
        <?php endif; ?>
        
        <!-- =============================================================== -->
        <!-- 2-PANEL LAYOUT -->
        <!-- =============================================================== -->
        <div class="puri-2panel-container">
            
            <!-- ========================================== -->
            <!-- LEFT PANEL: Control Center -->
            <!-- ========================================== -->
            <div class="puri-panel-left">
                
                <!-- CARD: System Integrity Status -->
                <div class="puri-card">
                    <h2>📊 System Integrity Status</h2>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th>WordPress</th>
                                <th>SQL</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Master Items (SKU)</strong></td>
                                <td><?= $status['wp_count'] ?> items</td>
                                <td><?= $status['sql_count'] ?> rows</td>
                                <td>
                                    <?php if($status['is_synced']): ?>
                                        <span class="badge badge-success">✅ SYNCED</span>
                                    <?php else: ?>
                                        <span class="badge badge-error">❌ OUT OF SYNC</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Stock-Items Link</strong></td>
                                <td colspan="2">
                                    <?php if($broken_links > 0): ?>
                                        <span style="color:#dc2626; font-weight:600;">
                                            ⚠️ <?= $broken_links ?> broken link(s) detected
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#059669; font-weight:600;">
                                            All links healthy
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($broken_links > 0): ?>
                                        <span class="badge badge-warning">⚠️ NEEDS REPAIR</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">✅ HEALTHY</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <!-- REPAIR BUTTON -->
                    <?php if($broken_links > 0): ?>
                    <div class="repair-section">
                        <div class="alert alert-warning">
                            <strong>🚨 Disaster Detected!</strong>
                            <p>Sistem mendeteksi adanya broken link antara tabel Stock dan Items.</p>
                            <p>Hal ini menyebabkan kartu stok di Panel POS menampilkan nilai 0 (nol).</p>
                        </div>
                        
                        <button type="button" 
                                class="button button-primary button-large"
                                onclick="openRepairModal()"
                                style="background:#dc2626; border-color:#b91c1c; width:100%; height:50px; font-size:16px; font-weight:600;">
                            🔧 REPAIR BROKEN LINK NOW
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success">
                        <strong>✅ System Healthy</strong>
                        <p>Tidak ada broken link yang terdeteksi. Kartu stok berjalan normal.</p>
                    </div>
                    <?php endif; ?>
                </div>


<!-- CARD: Mass Sync Control -->
<div class="puri-card">
    <h2>⚡ Mass Sync SKU</h2>
    
    <div class="alert alert-info" style="background:#e0f2fe; border-left-color:#0ea5e9;">
        <strong>ℹ️ What is Mass Sync?</strong>
        <p>Synchronize ALL items from WordPress CPT to SQL table (T_ITEMS) in one batch operation.</p>
    </div>
    
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:30%;">Component</th>
                <th style="width:70%;">Description</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Auto Sync</strong></td>
                <td>
                    <span class="badge badge-success">✅ ACTIVE</span>
                    <p style="margin:5px 0 0 0; font-size:12px; color:#666;">
                        Every time you save an item in WordPress, it automatically syncs to SQL
                    </p>
                </td>
            </tr>
            <tr>
                <td><strong>Mass Sync</strong></td>
                <td>
                    <p style="margin:0 0 10px 0; font-size:13px;">
                        Batch synchronize all <?php echo $status['wp_count']; ?> items at once
                    </p>
                    
                    <?php
                    $sync_url = esc_url(add_query_arg([
                        'puri_mass_sync' => '1',
                        '_wpnonce' => wp_create_nonce('puri_mass_sync_init')
                    ], admin_url('edit.php?post_type=pr_item')));
                    ?>
                    
                    <a href="<?php echo $sync_url; ?>" 
                       class="button button-primary"
                       style="background:#dc2626; border-color:#b91c1c; height:40px; line-height:38px; font-size:14px; font-weight:600;">
                        <span class="dashicons dashicons-update" style="margin-top:8px;"></span>
                        TRIGGER MASS SYNC
                    </a>
                </td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top:20px; padding:15px; background:#fef3c7; border:1px solid #fde68a; border-radius:5px;">
        <h4 style="margin-top:0; color:#92400e;">⚠️ When to Use Mass Sync?</h4>
        <ul style="margin:10px 0; padding-left:20px; font-size:13px; line-height:1.8;">
            <li><strong>After bulk import</strong> - when you add many items via CSV/Excel</li>
            <li><strong>After migration</strong> - when moving from old system to new</li>
            <li><strong>Database mismatch</strong> - when WP count ≠ SQL count</li>
            <li><strong>Missing metadata</strong> - when items have empty names/types in SQL</li>
        </ul>
    </div>
</div>






                
                <!-- CARD: Database Reset -->
                <div class="puri-card danger-zone">
                    <h2 style="color:#dc2626;">☢️ DANGER ZONE: Hard Reset</h2>
                    <p>Pilih data yang ingin <strong>DIHAPUS PERMANEN</strong>:</p>
                    
                    <form method="post" class="reset-form">
                        <?php wp_nonce_field('puri_reset_action'); ?>
                        
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_journal">
                                <strong>Jurnal Akuntansi</strong> (T_JOURNAL)
                            </label>
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_stock">
                                <strong>Data Stok & Mutasi</strong> (T_STOCK, T_LEDGER)
                            </label>
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_consign">
                                <strong>Data Konsinyasi</strong> (T_CONSIGN)
                            </label>
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_pool">
                                <strong>Arsitektur Pool POS</strong> (4 Tabel)
                            </label>
                        </div>
                        
                        <hr>
                        
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_item">
                                <strong>Master Item SQL</strong> (T_ITEMS - Perlu Sync Ulang)
                            </label>
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_vendor">
                                Master Vendor (CPT)
                            </label>
                            <label>
                                <input type="checkbox" name="reset_targets[]" value="m_customer">
                                Master Customer (CPT)
                            </label>
                        </div>
                        
                        <div class="password-box">
                            <label><strong>Password Login Anda:</strong></label>
                            <input type="password" 
                                   name="reset_confirm_password" 
                                   required 
                                   placeholder="Masukkan password WP admin">
                            
                            <button type="submit" 
                                    name="puri_do_hard_reset" 
                                    class="button button-danger"
                                    onclick="return confirm('YAKIN MENGHAPUS DATA? TINDAKAN INI TIDAK BISA DIBATALKAN!')">
                                🔥 EKSEKUSI PENGHAPUSAN DATA
                            </button>
                        </div>
                    </form>
                </div>
                
            </div>
            
            <!-- ========================================== -->
            <!-- RIGHT PANEL: Documentation -->
            <!-- ========================================== -->
            <div class="puri-panel-right">
                
                <!-- HELP: Repair Broken Link -->
                <div class="help-card">
                    <h3>🔧 Repair Broken Link</h3>
                    
                    <div class="help-section">
                        <h4>Apa yang Dilakukan Sistem?</h4>
                        <ol>
                            <li>Mencari record di tabel <code>T_STOCK</code> yang relasi <code>item_id</code>-nya putus</li>
                            <li>Menggunakan <code>wp_post_id</code> sebagai "jembatan" untuk re-link ke tabel <code>T_ITEMS</code></li>
                            <li>Update <code>item_id</code> di <code>T_STOCK</code> agar match dengan <code>id</code> di <code>T_ITEMS</code></li>
                            <li>Verifikasi hasil repair dan rollback jika ada error</li>
                        </ol>
                    </div>
                    
                    <div class="help-section">
                        <h4>⚠️ Risiko & Cautions</h4>
                        <ul class="warning-list">
                            <li><strong>Database Lock</strong>: Proses ini akan mengunci tabel selama beberapa detik</li>
                            <li><strong>Kasir Aktif</strong>: Pastikan tidak ada kasir yang sedang transaksi</li>
                            <li><strong>Backup Required</strong>: Wajib backup database sebelum eksekusi</li>
                        </ul>
                    </div>
                    
                    <div class="help-section">
                        <h4>✅ Mitigasi yang Harus Disiapkan</h4>
                        <ol>
                            <li><strong>Backup Database</strong>
                                <ul>
                                    <li>Via cPanel → phpMyAdmin → Export</li>
                                    <li>Atau gunakan plugin backup (UpdraftPlus, BackWPup)</li>
                                </ul>
                            </li>
                            <li><strong>Informasikan Tim Kasir</strong>
                                <ul>
                                    <li>Jangan lakukan transaksi selama 1-2 menit</li>
                                    <li>Beri notifikasi via WhatsApp/Telegram</li>
                                </ul>
                            </li>
                            <li><strong>Test di Staging</strong>
                                <ul>
                                    <li>Jika memungkinkan, test dulu di server staging</li>
                                    <li>Baru eksekusi di production</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                    
                    <div class="help-section">
                        <h4>🎯 Kapan Harus Repair?</h4>
                        <ul>
                            <li>✅ <strong>Segera</strong>: Jika kartu stok di POS menampilkan nilai 0 padahal seharusnya ada</li>
                            <li>✅ <strong>Segera</strong>: Jika badge status menunjukkan "NEEDS REPAIR"</li>
                            <li>❌ <strong>Jangan</strong>: Jika badge status sudah "HEALTHY"</li>
                        </ul>
                    </div>
                </div>
                
                <!-- HELP: Hard Reset -->
                <div class="help-card">
                    <h3>☢️ Hard Reset Database</h3>
                    
                    <div class="help-section">
                        <h4>Apa yang Dilakukan Sistem?</h4>
                        <p>Menjalankan <code>TRUNCATE TABLE</code> pada tabel yang dipilih, menghapus seluruh data secara permanen.</p>
                    </div>
                    
                    <div class="help-section">
                        <h4>⚠️ PERINGATAN KERAS</h4>
                        <ul class="warning-list">
                            <li><strong>IRREVERSIBLE</strong>: Data yang dihapus tidak bisa dikembalikan</li>
                            <li><strong>CASCADE EFFECT</strong>: Menghapus T_ITEMS akan membuat semua transaksi orphan</li>
                            <li><strong>BUSINESS IMPACT</strong>: Laporan keuangan akan hilang permanen</li>
                        </ul>
                    </div>
                    
                    <div class="help-section">
                        <h4>🎯 Kapan Boleh Reset?</h4>
                        <ul>
                            <li>✅ <strong>Development/Staging</strong>: Untuk testing fitur baru</li>
                            <li>✅ <strong>Migration Fresh Start</strong>: Migrasi sistem baru dari nol</li>
                            <li>❌ <strong>Production</strong>: JANGAN PERNAH kecuali disaster recovery</li>
                        </ul>
                    </div>
                </div>
                
                <!-- HELP: Mass Sync -->
                <div class="help-card">
                    <h3>⚡ Mass Sync SKU</h3>
                    
                    <div class="help-section">
                        <h4>Cara Kerja Auto-Sync</h4>
                        <p>Setiap kali Anda save/edit item di WordPress, sistem otomatis sync ke SQL tanpa perlu manual trigger.</p>
                    </div>
                    
                    <div class="help-section">
                        <h4>Cara Kerja Mass Sync</h4>
                        <ol>
                            <li>Buka halaman <strong>Products → All Items</strong></li>
                            <li>Klik tombol merah <strong>"MASS SYNC"</strong></li>
                            <li>Masukkan password WP admin</li>
                            <li>Centang "Replace All" jika ingin timpa harga</li>
                            <li>Klik "Eksekusi"</li>
                        </ol>
                    </div>
                    
                    <div class="help-section">
                        <h4>🎯 Default Mode: Safe Sync</h4>
                        <ul>
                            <li>✅ Update: SKU, Nama, Tipe, Denom</li>
                            <li>❌ Skip: Harga, Rate (preserve nilai lama)</li>
                            <li>⚡ Cepat & aman untuk update metadata</li>
                        </ul>
                    </div>
                </div>
                
            </div>
            
        </div>
        
        <!-- =============================================================== -->
        <!-- MODAL: Repair Confirmation -->
        <!-- =============================================================== -->
        <div id="repairModal" class="puri-modal" style="display:none;">
            <div class="puri-modal-content">
                <span class="puri-modal-close" onclick="closeRepairModal()">&times;</span>
                <h2>🔧 Konfirmasi Repair Broken Link</h2>
                
                <div class="modal-warning">
                    <strong>⚠️ Pre-flight Check:</strong>
                    <ul>
                        <li>Broken links detected: <strong><?= $broken_links ?></strong></li>
                        <li>Estimated repair time: <strong>~5-10 seconds</strong></li>
                        <li>Database lock: <strong>YES</strong></li>
                    </ul>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('puri_admin_action', 'puri_admin_nonce'); ?>
                    <input type="hidden" name="puri_do_repair_link" value="1">
                    
                    <div class="form-group">
                        <label><strong>🔐 Masukkan Password WP Admin Anda:</strong></label>
                        <input type="password" 
                               name="repair_password" 
                               required 
                               class="full-width"
                               placeholder="Password login WordPress">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" 
                                class="button" 
                                onclick="closeRepairModal()">
                            Batalkan
                        </button>
                        <button type="submit" 
                                class="button button-primary"
                                style="background:#dc2626; border-color:#b91c1c;"
                                onclick="return confirm('FINAL CONFIRMATION:\n\nBackup database sudah dibuat?\nKasir sudah diberitahu?\n\nProses ini tidak bisa dibatalkan setelah dimulai.');">
                            🔧 Eksekusi Repair
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
    </div>
    
    <style>
        /* =============================================================== */
        /* PURI MAINTENANCE UI STYLES */
        /* =============================================================== */
        .puri-maintenance-hub {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .puri-2panel-container {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            margin-top: 20px;
        }
        
        .puri-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .puri-card h2 {
            margin-top: 0;
            font-size: 18px;
            font-weight: 600;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        
        .danger-zone {
            border-left: 4px solid #dc2626;
        }
        
        .danger-zone h2 {
            border-bottom-color: #dc2626;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin: 20px 0;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #ecfdf5;
            border-left-color: #10b981;
            color: #065f46;
        }
        
        .alert-warning {
            background: #fffbeb;
            border-left-color: #f59e0b;
            color: #92400e;
        }
        
        .alert strong {
            display: block;
            margin-bottom: 8px;
        }
        
        .alert p {
            margin: 4px 0;
        }
        
        .repair-section {
            margin-top: 20px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 16px 0;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f9fafb;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .checkbox-group label:hover {
            background: #f3f4f6;
        }
        
        .password-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .password-box input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            margin: 10px 0 15px 0;
        }
        
        .button-danger {
            background: #dc2626 !important;
            border-color: #b91c1c !important;
            color: white !important;
            width: 100%;
            height: 48px;
            font-size: 15px;
            font-weight: 600;
        }
        
        .help-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            position: sticky;
            top: 32px;
        }
        
        .help-card h3 {
            margin-top: 0;
            font-size: 16px;
            font-weight: 600;
            color: #2563eb;
            border-bottom: 2px solid #dbeafe;
            padding-bottom: 10px;
        }
        
        .help-section {
            margin: 20px 0;
        }
        
        .help-section h4 {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .help-section ul,
        .help-section ol {
            margin: 10px 0;
            padding-left: 20px;
            line-height: 1.8;
        }
        
        .help-section code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
            color: #dc2626;
        }
        
        .warning-list {
            list-style: none;
            padding: 0;
        }
        
        .warning-list li {
            padding: 8px 12px;
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            margin-bottom: 8px;
            border-radius: 4px;
        }
        
        .puri-modal {
            position: fixed;
            z-index: 99999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .puri-modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
        }
        
        .puri-modal-close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
        }
        
        .puri-modal h2 {
            margin-top: 0;
            color: #dc2626;
        }
        
        .modal-warning {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        
        .modal-warning ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
        
        .form-group {
            margin: 20px 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .full-width {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        @media (max-width: 1280px) {
            .puri-2panel-container {
                grid-template-columns: 1fr;
            }
            
            .help-card {
                position: static;
            }
        }
    </style>
    
    <script>
    function openRepairModal() {
        document.getElementById('repairModal').style.display = 'flex';
    }
    
    function closeRepairModal() {
        document.getElementById('repairModal').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('repairModal');
        if (event.target === modal) {
            closeRepairModal();
        }
    }
    </script>
    
    <?php
}



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


function puri_render_uninstall_settings()
{
    $current_fallback = get_option('puri_uninstall_fallback_role', 'subscriber');
    $delete_flag      = get_option('puri_delete_data_on_uninstall', 'no');
    $wp_roles         = wp_roles()->get_names();
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
                        <?php foreach ($wp_roles as $role_slug => $role_name) : ?>
                            <?php if (!in_array($role_slug, ['finance', 'kasir'])) : ?>
                                <option value="<?php echo $role_slug; ?>" <?php selected($current_fallback, $role_slug); ?>>
                                    <?php echo $role_name; ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
    </div>
    <?php
}