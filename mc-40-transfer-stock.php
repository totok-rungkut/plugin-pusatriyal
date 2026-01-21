<?php
/**
 * ============================================================================
 * MC-40 - PURI STOCK TRANSFER HUB
 * ============================================================================
 * @package     Pusat Riyal Smart Suite
 * @version     1.0.0
 * @author      Mozart Engine Team
 * @created     2026-01-21
 * @prefix      riy_ (WordPress table prefix)
 * 
 * @description
 * Modul untuk memindahkan lokasi stok valas antar gudang/laci tanpa
 * mengubah nilai aset. Transfer murni perpindahan fisik barang.
 * 
 * @dependencies
 * - Mozart Engine v7.4.0+ (MC-03)
 * - Configuration Base (MC-29) untuk ACF Locations
 * - Master Items Bridge (MC-01, MC-17)
 * 
 * @architecture
 * - Frontend: Vanilla ES6 + jQuery (AJAX)
 * - Backend: WordPress + Mozart Symphony Integration
 * - Database: riy_puri_inventory_balance, riy_puri_inventory_ledger
 * 
 * @security
 * - Capability: can_entry (Kasir level dapat akses)
 * - Nonce validation pada semua POST/AJAX
 * - SQL Injection prevention via $wpdb->prepare()
 * 
 * @workflow
 * 1. User pilih Source & Target Location
 * 2. User pilih SKU + Qty (kelipatan 100 pcs)
 * 3. Add to cart (validasi stock availability)
 * 4. Confirm & Execute → Mozart handles dual-location update
 * 5. Real-time AJAX refresh stock display
 * ============================================================================
 */

defined('ABSPATH') || exit;

/**
 * ============================================================================
 * SECTION 1: MENU REGISTRATION
 * ============================================================================
 */
 // sudah dihandle oleh mc-00-hub
/*
add_action('admin_menu', function() {
    add_submenu_page(
        'puri-master',
        'Stock Transfer',
        '🔄 Transfer Stock',
        'can_entry',
        'puri-transfer-stock',
        'puri_render_transfer_page'
    );
});
*/

/**
 * ============================================================================
 * SECTION 2: AJAX ENDPOINTS
 * ============================================================================
 */

/**
 * AJAX: Get Stock by Location
 * Returns: Array of items with current balance
 */
add_action('wp_ajax_puri_get_stock_by_location', function() {
    check_ajax_referer('puri_transfer_nonce', 'nonce');
    
    $location_id = sanitize_text_field($_POST['location_id'] ?? '');
    
    if (empty($location_id)) {
        wp_send_json_error(['message' => 'Location ID required']);
    }
    
    global $wpdb;
    $items_table = $wpdb->prefix . 'puri_pr_master_items';
    $stock_table = $wpdb->prefix . 'puri_inventory_balance';
    
    $stocks = $wpdb->get_results($wpdb->prepare("
        SELECT 
            t.id AS item_id,
            t.wp_post_id,
            t.sku,
            t.name,
            t.denom_value,
            COALESCE(s.balance, 0) AS balance
        FROM {$items_table} t
        LEFT JOIN {$stock_table} s 
            ON t.id = s.item_id AND s.location_id = %s
        WHERE t.type = 'currency'
        ORDER BY t.denom_value ASC
    ", $location_id));
    
    wp_send_json_success(['stocks' => $stocks]);
});

/**
 * AJAX: Check Item Availability
 * Validates if requested qty is available in source location
 */
add_action('wp_ajax_puri_check_stock_availability', function() {
    check_ajax_referer('puri_transfer_nonce', 'nonce');
    
    $item_id     = intval($_POST['item_id'] ?? 0);
    $location_id = sanitize_text_field($_POST['location_id'] ?? '');
    $qty         = intval($_POST['qty'] ?? 0);
    
    global $wpdb;
    $stock_table = $wpdb->prefix . 'puri_inventory_balance';
    
    $current_balance = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(balance, 0) FROM {$stock_table} 
         WHERE item_id = %d AND location_id = %s",
        $item_id, $location_id
    ));
    
    $available = floatval($current_balance);
    
    wp_send_json_success([
        'available'     => $available,
        'is_sufficient' => ($available >= $qty)
    ]);
});

/**
 * ============================================================================
 * SECTION 3: FORM SUBMISSION HANDLER
 * ============================================================================
 */
add_action('admin_post_puri_execute_transfer', function() {
    check_admin_referer('puri_transfer_action', 'puri_transfer_nonce');
    puri_check_cap('can_entry');
    
    global $wpdb;
    
    // 1. Sanitize Inputs
    $source_loc = sanitize_text_field($_POST['source_location'] ?? '');
    $target_loc = sanitize_text_field($_POST['target_location'] ?? '');
    $items_raw  = $_POST['items'] ?? [];
    
    // 2. Validation
    if (empty($source_loc) || empty($target_loc)) {
        wp_die('Location tidak boleh kosong');
    }
    
    if ($source_loc === $target_loc) {
        wp_die('Lokasi source dan target tidak boleh sama');
    }
    
    if (empty($items_raw)) {
        wp_die('Tidak ada item yang akan ditransfer');
    }
    
    // Validate locations exist in ACF options
    $valid_locations = array_column(
        get_option('puri_inv_locations', []), 
        'id'
    );
    
    if (!in_array($source_loc, $valid_locations) || !in_array($target_loc, $valid_locations)) {
        wp_die('Lokasi tidak valid');
    }
    
    // 3. Build Mozart Payload
    $items_payload = [];
    $items_table = $wpdb->prefix . 'puri_pr_master_items';
    
    foreach ($items_raw as $it) {
        $item_id = intval($it['item_id']);
        $qty     = intval($it['qty']);
        
        if ($item_id <= 0 || $qty <= 0) continue;
        
        // Get item bridge data
        $item_info = $wpdb->get_row($wpdb->prepare(
            "SELECT wp_post_id, sku, name, denom_value FROM {$items_table} WHERE id = %d",
            $item_id
        ));
        
        if (!$item_info) {
            wp_die("Item ID {$item_id} tidak ditemukan");
        }
        
        // Validate stock sufficiency (double check)
        $stock_table = $wpdb->prefix . 'puri_inventory_balance';
        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(balance, 0) FROM {$stock_table} 
             WHERE item_id = %d AND location_id = %s",
            $item_id, $source_loc
        ));
        
        if (floatval($available) < $qty) {
            wp_die("Stok {$item_info->sku} tidak mencukupi! Tersedia: {$available}, Diminta: {$qty}");
        }
        
        $items_payload[] = [
            'item_id'    => $item_id,
            'wp_post_id' => intval($item_info->wp_post_id),
            'qty'        => $qty,
            'sku'        => $item_info->sku,
            'name'       => $item_info->name
        ];
    }
    
    // 4. Generate Reference ID
    $ref_id = 'STR-' . current_time('Ymd') . '-' . strtoupper(wp_generate_password(4, false));
    
    // 5. Assemble Transaction Parameter (Mozart Format)
    $trx_param = [
        'source'          => 'stock_transfer',
        'source_ref'      => $ref_id,
        'source_location' => $source_loc,
        'target_location' => $target_loc,
        'created_at'      => current_time('mysql'),
        'items'           => $items_payload,
        'description'     => "Transfer: {$source_loc} → {$target_loc}"
    ];
    
    // 6. Execute via Mozart Symphony
    $result = puri_mozart()->execute('stock_transfer', $trx_param);
    
    if (is_wp_error($result)) {
        wp_die('Mozart Execution Error: ' . $result->get_error_message());
    }
    
    // 7. Success Redirect
    wp_redirect(admin_url('admin.php?page=puri-transfer-stock&success=' . urlencode($ref_id)));
    exit;
});

/**
 * ============================================================================
 * SECTION 4: PAGE RENDERER
 * ============================================================================
 */
function puri_render_transfer_page() {
    puri_check_cap('can_entry');
    
    // Fetch Locations from ACF Options (MC-29)
    $locations = get_option('puri_inv_locations', [
        ['id' => 'gudang-00', 'name' => 'Gudang Utama'],
        ['id' => 'laci-kasir-01', 'name' => 'Laci Kasir']
    ]);
    
    // Fetch Available Items (for dropdown)
    global $wpdb;
    $items_table = $wpdb->prefix . 'puri_pr_master_items';
    $items = $wpdb->get_results("
        SELECT id, wp_post_id, sku, name, denom_value 
        FROM {$items_table} 
        WHERE type = 'currency' 
        ORDER BY denom_value ASC
    ");
    
    // Success Message
    $success_ref = isset($_GET['success']) ? esc_html($_GET['success']) : '';
    
    ?>
    <div class="wrap puri-transfer-wrapper">
        <h1>🔄 Stock Transfer Hub <span class="version-badge">v1.0.0</span></h1>
        
        <?php if ($success_ref): ?>
            <div class="notice notice-success is-dismissible">
                <p>✅ Transfer berhasil! Ref: <strong><?php echo $success_ref; ?></strong></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="transfer-form">
            <input type="hidden" name="action" value="puri_execute_transfer">
            <?php wp_nonce_field('puri_transfer_action', 'puri_transfer_nonce'); ?>
            
            <!-- ========== ROW 1: Input & Cart ========== -->
            <div class="transfer-row-top">
                
                <!-- Panel Left: Input Controls -->
                <div class="panel-input">
                    
                    <!-- Card 1: Location Selector -->
                    <div class="transfer-card">
                        <div class="card-header">
                            <span class="icon">📍</span>
                            <h3>Pilih Lokasi Transfer</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-grid-2">
                                <div class="form-group">
<?php $default_location_from = $locations[1]['id'];	?>
                                    <label>Source Location (Dari)</label>
                                    <select name="source_location" id="source_location" class="puri-select" required>
                                        <option value="">-- Pilih Source --</option>
                                        <?php foreach ($locations as $loc): ?>
                                            <option value="<?php echo esc_attr($loc['id']); ?>">
<?php echo ($loc['id'] == $default_location_id) ? 'selected' : ''; ?>>
											<?php echo esc_html($loc['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Target Location (Ke)</label>
                                    <select name="target_location" id="target_location" class="puri-select" required>
                                        <option value="">-- Pilih Target --</option>
                                        <?php foreach ($locations as $loc): ?>
                                            <option value="<?php echo esc_attr($loc['id']); ?>">
                                                <?php echo esc_html($loc['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 2: Item Input -->
                    <div class="transfer-card">
                        <div class="card-header">
                            <span class="icon">📦</span>
                            <h3>Tambah Item Transfer</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label>Pilih SKU</label>
                                    <select id="item_select" class="puri-select">
                                        <option value="">-- Pilih Item --</option>
                                        <?php foreach ($items as $item): ?>
                                            <option 
                                                value="<?php echo $item->id; ?>" 
                                                data-wp-id="<?php echo $item->wp_post_id; ?>"
                                                data-sku="<?php echo esc_attr($item->sku); ?>"
                                                data-name="<?php echo esc_attr($item->name); ?>"
                                                data-denom="<?php echo $item->denom_value; ?>">
                                                <?php echo esc_html($item->sku . ' - ' . $item->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Qty (pcs) - Min. 100</label>
                                    <input 
                                        type="number" 
                                        id="qty_input" 
                                        class="puri-input" 
                                        min="100" 
                                        step="100" 
                                        placeholder="Kelipatan 100">
                                    <small class="helper-text" id="qty_helper">Stok tersedia: -</small>
                                </div>
                                <div class="form-group btn-wrapper">
                                    <button type="button" id="btn_add_to_cart" class="btn-add-cart" disabled>
                                        <span class="icon">🛒</span> Taruh Keranjang
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <!-- Panel Right: Cart -->
                <div class="panel-cart">
                    <div class="transfer-card">
                        <div class="card-header cart-header">
                            <span class="icon">🛒</span>
                            <h3>Keranjang Transfer</h3>
                            <span class="cart-count" id="cart_count">0 item</span>
                        </div>
                        <div class="card-body">
                            <div id="cart_container" class="cart-empty">
                                <p>Keranjang masih kosong. Tambahkan item untuk transfer.</p>
                            </div>
                        </div>
                        <div class="card-footer">
                            <label class="confirm-checkbox">
                                <input type="checkbox" id="confirm_data">
                                <span>✓ Data sudah benar, siap eksekusi</span>
                            </label>
                            <button type="submit" id="btn_execute" class="btn-execute" disabled>
                                <span class="icon">⚡</span> Execute Transfer
                            </button>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <!-- ========== ROW 2: Stock Display ========== -->
            <div class="transfer-row-bottom">
                
                <!-- Panel Left: Source Stock -->
                <div class="panel-stock">
                    <div class="transfer-card">
                        <div class="card-header">
                            <span class="icon">📊</span>
                            <h3>Stok di <span id="source_label">Source Location</span></h3>
                        </div>
                        <div class="card-body">
                            <div id="source_stock_display" class="stock-display">
                                <p class="placeholder-text">Pilih Source Location untuk melihat stok</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Panel Right: Target Stock -->
                <div class="panel-stock">
                    <div class="transfer-card">
                        <div class="card-header">
                            <span class="icon">📊</span>
                            <h3>Stok di <span id="target_label">Target Location</span></h3>
                        </div>
                        <div class="card-body">
                            <div id="target_stock_display" class="stock-display">
                                <p class="placeholder-text">Pilih Target Location untuk melihat stok</p>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            
        </form>
    </div>
    
    <?php
    puri_transfer_styles();
    puri_transfer_scripts();
}

// Include external style dan script files
require_once __DIR__ . '/mc-40-styles.php';
require_once __DIR__ . '/mc-40-scripts.php';