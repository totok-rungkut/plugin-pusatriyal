<?php
/**
 * ============================================================================
 * MC-05 - COCKPIT EXTENDED POINT OF SALE SYSTEM  --> disaster blank history
 * ============================================================================
 * 
 * @package     Puri_Money_Changer
 * @subpackage  Cockpit_POS
 * @version     7.8.40 (UX Restructured - Pool Transaction Phase 1)
 * @author      Denmas Totok (Architecture & Core Logic)
 * @refactor    Gemini AI Assistant (Code Optimization)
 * @since       2024-01-14
 * 
 * ============================================================================
 * CHANGELOG v6.10.25
 * ============================================================================
 * [2026-01-14] MAJOR UX RESTRUCTURE
 * - TOP ROW: Divided into 3 panels (Customer KYC | Item Transaction | Cart)
 * - BOTTOM ROW: Kept stable (History | Stock Summary)
 * - NEW: Customer Quick Registration Modal
 * - NEW: Enhanced cart with payment/delivery options
 * - PREPARED: Pool transaction architecture (Phase 1 foundation)
 * 
 * [Previous v6.8.9]
 * - FIX: JSON Snapshot stored in BOTH debit/credit journal entries
 * - FIX: Recursive JSON decode for mutation history
 * - FIX: Moving average cost calculation on BUY mode
 * */


defined('ABSPATH') || exit;
global $wpdb;


class Puri_Cockpit_POS {

    public function __construct() {
        // Menu registration handled by MC-00 master controller
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX Endpoints - Core Operations
        add_action('wp_ajax_puri_pos_get_stock_summary', [$this, 'ajax_get_stock_summary']);
        add_action('wp_ajax_puri_pos_get_daily_mutation', [$this, 'ajax_get_daily_mutation']);
        add_action('wp_ajax_puri_pos_checkout', [$this, 'ajax_process_checkout']);
		
        // ✅ AJAX Endpoints - Pool Transaction CRUD		
        add_action('wp_ajax_puri_pos_get_pool_snapshot', [$this, 'get_pool_snapshot']);
		add_action('wp_ajax_puri_pos_get_pool_history', [$this, 'get_pool_history']);
		add_action('wp_ajax_puri_pos_void_pool_transaction', [$this, 'void_pool_transaction']);
		
        // AJAX Endpoints - Customer Management (NEW v6.10.25)
        add_action('wp_ajax_puri_pos_create_customer', [$this, 'ajax_create_customer']);
		add_action('wp_ajax_puri_pos_upload_customer_id', [$this, 'ajax_upload_customer_id']);
    }


/**
 * AJAX: Get Pool Transaction Snapshot (untuk Edit)
 */
public function get_pool_snapshot() {
    // check_ajax_referer('puri_pos_checkout', 'nonce');  // temporary for debug
    global $wpdb;

error_log("🔍 POOL SNAPSHOT REQUEST: " . print_r($_POST, true));

$ref_id = sanitize_text_field($_POST['ref_id'] ?? '');

if (empty($ref_id)) {
    error_log("❌ POOL SNAPSHOT: Empty ref_id received. POST data: " . json_encode($_POST));

		wp_send_json_error('Reference ID tidak valid');
    }

    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    // ✅ Ambil data transaksi
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tbl_pool} WHERE ref_id = %s",
        $ref_id
    ));

    if (!$row) {
            error_log("❌ POOL SNAPSHOT: Transaction not found - {$ref_id}");		
        wp_send_json_error('Transaksi tidak ditemukan');
    }

    // ✅ Validasi: Hanya pending/verified yang bisa diedit
        if (!in_array($row->status, ['pending', 'verified'])) {
            error_log("⚠️ POOL SNAPSHOT: Invalid status - {$row->status}");
            wp_send_json_error('Transaksi sudah diposting, tidak bisa diedit');
        }

    // ✅ Parse items snapshot
    $items_snapshot = json_decode($row->items_snapshot, true);
    
        if (empty($items_snapshot) || !is_array($items_snapshot)) {
            error_log("❌ POOL SNAPSHOT: Invalid items_snapshot - " . $row->items_snapshot);
            wp_send_json_error('Data item tidak valid');
        }

    // ✅ Rebuild cart structure untuk frontend
	// 🔧 FIXED: Rebuild cart structure dengan wp_post_id
    $tbl_items = puri_table_name('T_ITEMS');
    $cart_data = [];
	
    foreach ($items_snapshot as $item) {
		// Get item_id with proper fallback
		$item_id = intval($item['item_id'] ?? $item['id'] ?? 0);
		
		if ($item_id <= 0) {
			error_log("⚠️ POOL SNAPSHOT: Invalid item_id in snapshot");
			continue;
		}

		// 🔧 FIXED: Fetch wp_post_id from T_ITEMS
		$wp_post_id = $wpdb->get_var($wpdb->prepare(
			"SELECT wp_post_id FROM {$tbl_items} WHERE id = %d",
			$item_id
		));

		if (!$wp_post_id) {
			error_log("⚠️ POOL SNAPSHOT: wp_post_id not found for item_id {$item_id}");
			$wp_post_id = 0; // Fallback ke 0
		}
		
        $cart_data[] = [
            'id' => intval($item['item_id'] ?? $item['id'] ?? 0),
            'item_id' => intval($item['item_id'] ?? $item['id'] ?? 0),
            'sku' => $item['sku'] ?? '',
            'name' => $item['name'] ?? '',
            'denom' => floatval($item['denom'] ?? 0),
            'qty' => floatval($item['qty'] ?? 0),
            'rate' => floatval($item['rate'] ?? 0),
            'total_valas' => floatval($item['qty'] ?? 0) * floatval($item['denom'] ?? 0),
            'subtotal_idr' => floatval($item['qty'] ?? 0) * floatval($item['denom'] ?? 0) * floatval($item['rate'] ?? 0)
        ];
    }
        if (empty($cart_data)) {
            error_log("❌ POOL SNAPSHOT: No valid items after rebuild");
            wp_send_json_error('Tidak ada item valid dalam transaksi');
        }

        error_log("✅ POOL SNAPSHOT: Success - {$ref_id}, Items: " . count($cart_data));

    wp_send_json_success([
        'ref_id' => $row->ref_id,
        'trade_mode' => $row->trade_mode,
        'cust_id' => $row->customer_id,
        'cart' => $cart_data,
        'pay_method' => $row->payment_method,
        'delivery_method' => $row->delivery_method ?? 'pickup'
    ]);
}


/**
 * AJAX: Void Pool Transaction (Soft Delete + Stock Reversal)
 */
/**
 * AJAX: Void Pool Transaction (Soft Delete + Stock Reversal)
 * Version: 7.3.40 (FIXED)
 */
public function void_pool_transaction() {
    check_ajax_referer('puri_pos_checkout', 'nonce');
    global $wpdb;

    // ✅ PATCH 1: Get ref_id with proper validation
    $ref_id = sanitize_text_field($_POST['ref_id'] ?? '');
    
    // ✅ PATCH 2: Check silent mode BEFORE validation
    $is_silent = isset($_POST['silent_mode']) && $_POST['silent_mode'] === '1';

    // ✅ PATCH 3: CRITICAL FIX - Only return error if ref_id is ACTUALLY empty
    if (empty($ref_id)) {
        error_log("❌ VOID ERROR: Empty ref_id received. POST data: " . json_encode($_POST));
        wp_send_json_error('Reference ID tidak valid');
        return; // ✅ Add explicit return to prevent further execution
    }

    if ($is_silent) {
        error_log("🔇 SILENT VOID MODE: {$ref_id}");
    } else {
        error_log("🗑️ NORMAL VOID MODE: {$ref_id}");
    }

    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    $tbl_pool_stock = puri_table_name('T_POOL_STOCK');
    $tbl_stock = puri_table_name('T_STOCK');

    // ✅ PATCH 4: Check if transaction exists
    $transaction = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tbl_pool} WHERE ref_id = %s",
        $ref_id
    ));

    if (!$transaction) {
        error_log("❌ VOID ERROR: Transaction not found - {$ref_id}");
        wp_send_json_error('Transaksi tidak ditemukan');
        return;
    }

    // ✅ PATCH 5: Validate status - only pending/verified can be voided
    if (!in_array($transaction->status, ['pending', 'verified'])) {
        error_log("⚠️ VOID ERROR: Invalid status - {$transaction->status} for {$ref_id}");
        wp_send_json_error("Transaksi sudah {$transaction->status}, tidak bisa dihapus");
        return;
    }

    // ✅ START TRANSACTION
    $wpdb->query('START TRANSACTION');

    try {
        error_log("🗑️ VOIDING TRANSACTION: {$ref_id} | Status was: {$transaction->status}");
        
        // ====================================================================
        // STEP 1: UPDATE STATUS TO 'VOID'
        // ====================================================================
        $update_result = $wpdb->update(
            $tbl_pool,
            [
                'status' => 'void', 
                'notes' => 'Voided by user ' . get_current_user_id() . ' at ' . current_time('mysql')
            ],
            ['ref_id' => $ref_id]
        );

        if ($update_result === false) {
            throw new Exception("Failed to update transaction status: " . $wpdb->last_error);
        }

        error_log("✅ Step 1: Status updated to VOID");

        // ====================================================================
        // STEP 2: REVERSE PHYSICAL STOCK
        // ====================================================================
        $pool_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tbl_pool_stock} WHERE ref_id = %s",
            $ref_id
        ));

        if (empty($pool_items)) {
            error_log("⚠️ WARNING: No stock movements found for {$ref_id}");
        }

        $reversed_count = 0;
        foreach ($pool_items as $item) {
            $item_id = intval($item->item_id);
            $location_id = $item->location_id;
            $qty_change = floatval($item->qty_change);

            if ($qty_change == 0) {
                continue; // Skip items with zero movement
            }

            // ✅ REVERSE LOGIC: If qty_change was -5 (sell), now add +5 (return stock)
            $reverse_qty = -1 * $qty_change;

            error_log("🔄 Reversing: Item {$item_id} @ {$location_id}: {$qty_change} → {$reverse_qty}");

            // ================================================================
            // UPDATE T_STOCK
            // ================================================================
            $stock_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tbl_stock} WHERE item_id = %d AND location_id = %s",
                $item_id, $location_id
            ));

            if ($stock_exists) {
                // Update existing record
                $stock_update = $wpdb->query($wpdb->prepare(
                    "UPDATE {$tbl_stock} 
                     SET balance = balance + %f, 
                         updated_at = %s,
                         last_ref = %s
                     WHERE item_id = %d AND location_id = %s",
                    $reverse_qty, 
                    current_time('mysql'),
                    'VOID-' . $ref_id,
                    $item_id, 
                    $location_id
                ));

                if ($stock_update === false) {
                    throw new Exception("Failed to update stock for item {$item_id}: " . $wpdb->last_error);
                }

                $reversed_count++;
                error_log("✅ Stock reversed for item {$item_id}");
            } else {
                // ⚠️ WARNING: Stock record doesn't exist (shouldn't happen in normal flow)
                error_log("⚠️ WARNING: Stock record not found for item {$item_id} @ {$location_id}");
                
                // Create new record with reversed quantity
                $stock_insert = $wpdb->insert($tbl_stock, [
                    'item_id' => $item_id,
                    'location_id' => $location_id,
                    'balance' => $reverse_qty,
                    'updated_at' => current_time('mysql'),
                    'last_ref' => 'VOID-' . $ref_id
                ]);

                if ($stock_insert === false) {
                    throw new Exception("Failed to insert stock for item {$item_id}: " . $wpdb->last_error);
                }

                $reversed_count++;
                error_log("✅ Stock record created for item {$item_id}");
            }
        }

        error_log("✅ Step 2: {$reversed_count} stock movements reversed");

        // ====================================================================
        // STEP 3: COMMIT TRANSACTION
        // ====================================================================
        $wpdb->query('COMMIT');
        error_log("✅ VOID SUCCESS: {$ref_id} marked as void, stock reversed");

        // ====================================================================
        // STEP 4: SEND SUCCESS RESPONSE
        // ====================================================================
        $response_message = $is_silent 
            ? "Transaction {$ref_id} voided silently" 
            : "Transaksi berhasil dibatalkan dan stok dikembalikan";

        wp_send_json_success([
            'message' => $response_message,
            'ref_id' => $ref_id,
            'silent_mode' => $is_silent,
            'items_reversed' => $reversed_count,
            'debug' => [
                'old_status' => $transaction->status,
                'new_status' => 'void',
                'total_riyal' => $transaction->total_riyal,
                'total_idr' => $transaction->total_idr
            ]
        ]);

    } catch (Exception $e) {
        // ====================================================================
        // ERROR HANDLING: ROLLBACK
        // ====================================================================
        $wpdb->query('ROLLBACK');
        
        $error_msg = $e->getMessage();
        error_log('❌ Void Error: ' . $error_msg);
        error_log('❌ Full Exception: ' . print_r($e, true));
        
        wp_send_json_error([
            'message' => 'Gagal membatalkan transaksi: ' . $error_msg,
            'ref_id' => $ref_id,
            'debug' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}

	
public function get_pool_history() {
    global $wpdb;
    
    $today_str = current_time('Y-m-d');
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    // ✅ JOIN dengan wp_users untuk ambil cashier name
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            p.ref_id,
            DATE_FORMAT(p.trx_date, '%%H:%%i') as time,
            p.trade_mode,
            p.customer_name,
            p.total_riyal,
            p.total_idr,
            p.status,
            p.payment_method,
            p.created_by,
            u.display_name as cashier_name
         FROM {$tbl_pool} p
         LEFT JOIN {$wpdb->users} u ON p.created_by = u.ID
         WHERE DATE(p.trx_date) = %s
         ORDER BY p.trx_date DESC
         LIMIT 100",
        $today_str
    ));
    
    // ✅ Process dengan struktur yang KONSISTEN
    $summary = [
        'pending' => 0,
        'verified' => 0,
        'posted' => 0,
        'void' => 0,
        'total_idr' => 0
    ];
    
    $data = [];
    foreach ($rows as $r) {
        // Accumulate summary
        $summary[$r->status]++;
        $summary['total_idr'] += floatval($r->total_idr);
        
        $data[] = [
            'ref_id' => $r->ref_id,
            'time' => $r->time,
            'trade_mode' => $r->trade_mode,
            'customer_name' => $r->customer_name,
            'total_amount' => floatval($r->total_idr), // ✅ RENAME untuk match frontend
            'status' => $r->status,
            'cashier' => $r->cashier_name ?: 'System' // ✅ TAMBAHKAN FIELD INI!
        ];
    }
    
    wp_send_json_success([
        'transactions' => $data,
        'summary' => $summary
    ]);
}

    /**
     * Enqueue CSS/JS Assets
     * Loads external libraries if not already present
     */
    public function enqueue_assets($hook) {
        // SweetAlert2 for modal dialogs
        if(!wp_script_is('sweetalert2', 'enqueued')) {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        }
        
        // Select2 for enhanced dropdowns
        if(!wp_script_is('select2', 'enqueued')) {
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
        }
        
        // Font Awesome icons
        wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
    }


public function ajax_upload_customer_id() {
    check_ajax_referer('puri_pos_nonce', 'security');

    if (empty($_FILES['customer_id_file'])) {
        wp_send_json_error(['message' => 'No file uploaded']);
    }

    // Ambil data untuk penamaan file
    $id_type   = sanitize_text_field($_POST['id_type']); // KTP/SIM/dll
    $cust_name = sanitize_title($_POST['customer_name']); // "fajar-harianto"
    $cust_id   = preg_replace('/\s+/', '', sanitize_text_field($_POST['customer_id'])); // NIK tanpa spasi

    $ext = pathinfo($_FILES['customer_id_file']['name'], PATHINFO_EXTENSION);
    
    // FORMAT: {type id}_{nama}_{no.id}.{ext}
    $new_name = "{$id_type}_{$cust_name}_{$cust_id}.{$ext}";

    // Filter untuk memaksa nama file baru
    $filename_handler = function($filename) use ($new_name) { return $new_name; };
    add_filter('sanitize_file_name', $filename_handler, 10);

    $uploaded = wp_handle_upload($_FILES['customer_id_file'], ['test_form' => false]);
    remove_filter('sanitize_file_name', $filename_handler, 10);

    if (isset($uploaded['error'])) wp_send_json_error(['message' => $uploaded['error']]);

    // Masukkan ke Media Library
    $attachment = [
        'post_mime_type' => $uploaded['type'],
        'post_title'     => $new_name,
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment($attachment, $uploaded['file']);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $uploaded['file']));

    wp_send_json_success(['attachment_id' => $attach_id, 'url' => $uploaded['url']]);
}


    // =========================================================================
    // MAIN RENDER METHOD
    // =========================================================================
    
    /**
     * Render Cockpit POS Interface
     * 
     * Layout Structure:
     * ┌────────────────────────────────────────────────────────────┐
     * │ TOP ROW (3 Panels)                               │
     * │ ┌────────────┬────────────────┬──────────────────────────┐ │
     * │ │  Customer  │ Item Selection │   Cart & Checkout  │ │
     * │ │    KYC     │  Transaction   │   Payment/Delivery │ │
     * │ └────────────┴────────────────┴──────────────────────────┘ │
     * └────────────────────────────────────────────────────────────┘
     * 
     * ┌────────────────────────────────────────────────────────────┐
     * │ BOTTOM ROW (2 Panels - STABLE)                   │
     * │ ┌───────────────────┬────────────────────────────────────┐ │
     * │ │ Daily Mutation    │  Stock Card Summary       │ │
     * │ │ History           │  (Monthly Movement)       │ │
     * │ └───────────────────┴────────────────────────────────────┘ │
     * └────────────────────────────────────────────────────────────┘
     */
    public function allnew_pos_render_page() {
		global $wpdb;
        $items = $this->get_items_for_dropdown(); 
        $customers = $this->get_customers_for_dropdown();
        $is_finance_or_admin = current_user_can('can_entry');
		// Ambil lokasi default POS dari ACF option
		$locations   = get_option('puri_inv_locations', []);
		$default_loc = get_option('puri_pos_default_location', 'laci_kasir');	
        ?>
        <div class="wrap puri-cockpit-wrapper">
            <h1 class="wp-heading-inline">
                <i class="fa-solid fa-gauge-high"></i> Cockpit P.O.S 
                <span class="version-badge">v6.10.25</span>
            </h1>
            <hr class="wp-header-end">

            <!-- Audio FX -->
            <audio id="fx_item_choosed" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/click.mp3'; ?>"></audio>
            <audio id="fx_stock_empty" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/error.mp3'; ?>"></audio>
            <audio id="fx_cart_clicked" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/cash-register.mp3'; ?>"></audio>

            <div class="cockpit-container">
                
                <!-- ============================================ -->
                <!-- TOP ROW: 3 PANELS (RESTRUCTURED v6.10.25)  -->
                <!-- ============================================ -->
                <div class="flex-row top-row">
                    
                    <!-- PANEL 1: CUSTOMER IDENTIFICATION -->
                    <div class="flex-col col-customer">
                        <div class="panel panel-customer">
                            <div class="panel-header">
                                <i class="fa-solid fa-user-shield"></i> 1. Customer Identification (KYC)
                            </div>
                            <div class="panel-body">
                                
                                <!-- Trade Mode Selector -->
                                <div class="form-group mb-2">
                                    <label class="small-label">Trade Mode</label>
                                    <div class="radio-group mode-selector">
                                        <label class="radio-label mode-sell">
                                            <input type="radio" name="trade_mode" value="sell" checked> 
                                            <i class="fa-solid fa-hand-holding-usd"></i> Sell SAR
                                        </label>
                                        <label class="radio-label mode-buy">
                                            <input type="radio" name="trade_mode" value="buy"> 
                                            <i class="fa-solid fa-money-bill-trend-up"></i> Buy SAR
                                        </label>
                                    </div>
                                </div>

                                <!-- Customer Selection -->
                                <div class="form-group mb-2">
                                    <label class="small-label">Select Customer *</label>
                                    <div class="customer-select-wrapper">
                                        <select id="customer_select" style="width: calc(100% - 45px);">
                                            <option value="">-- Select Customer --</option>
                                            <?php foreach($customers as $c): ?>
<option value="<?php echo $c->ID; ?>" 
        data-type="<?php echo esc_attr($c->type); ?>"
        data-phone="<?php echo esc_attr($c->phone); ?>"
        data-nik="<?php echo esc_attr($c->nik); ?>"
        data-address="<?php echo esc_attr($c->address); ?>"
        data-citizenship="<?php echo esc_attr($c->citizenship); ?>"
        data-occupation="<?php echo esc_attr($c->occupation); ?>"
        data-purpose="<?php echo esc_attr($c->purpose); ?>">
    <?php echo esc_html($c->post_title); ?>
</option>

                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="btn_add_customer" class="button button-primary" title="Quick Add Customer">
                                            <i class="fa-solid fa-user-plus"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Customer Info Display -->
<!-- Customer Info Display -->
<div id="customer_info_box" class="customer-info-box hidden">
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-id-card"></i> NIK:</span>
        <span id="info_nik" class="info-value">-</span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-phone"></i> Phone:</span>
        <span id="info_phone" class="info-value">-</span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-location-dot"></i> Address:</span>
        <span id="info_address" class="info-value">-</span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-flag"></i> Citizenship:</span>
        <span id="info_citizenship" class="info-value">-</span>
    </div>
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-briefcase"></i> Occupation:</span>
        <span id="info_occupation" class="info-value">-</span> <!-- ✅ FIX: ID yang benar -->
    </div>
    <div class="info-row">
        <span class="info-label"><i class="fa-solid fa-plane"></i> Purpose:</span>
        <span id="info_purpose" class="info-value">-</span> <!-- ✅ BONUS: Tambahkan Purpose -->
    </div>
</div>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL 2: ITEM TRANSACTION -->
                    <div class="flex-col col-transaction">
                        <div class="panel panel-transaction" id="panelInputTransaksi">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-money-bill-wave"></i> 2. Item Transaction</span>
                                <button type="button" class="button button-small" id="btn_clear_form" title="Clear Form">
                                    <i class="fa-solid fa-eraser"></i>
                                </button>
                            </div>
                            <div class="panel-body">
                                
                                <!-- Item Selection Row -->
								
<?php
$items_table = $wpdb->prefix . 'puri_pr_master_items';
$stock_table = $wpdb->prefix . 'puri_inventory_balance';

// PERBAIKAN 1: Tambahkan t.sell_rate agar data rate tidak 0
$items = $wpdb->get_results($wpdb->prepare("
    SELECT t.id, t.wp_post_id, t.sku, t.name, t.denom_value, t.sell_rate,
           COALESCE(s.balance,0) AS stock_balance
    FROM {$items_table} t
    LEFT JOIN {$stock_table} s
      ON t.id = s.item_id AND s.location_id = %s
    WHERE t.type = 'currency'
    ORDER BY t.denom_value ASC
", $default_loc));
?>								
		
<div class="form-group mb-2">
    <label class="small-label">Select Item (SKU) *</label>
    <select id="item_select" class="puri-input">
        <option value="" data-denom="0" data-rate="0">-- Select Item --</option>
        
        <?php foreach($items as $it): ?>
            <option value="<?php echo esc_attr($it->id); ?>" 
                    data-sku="<?php echo esc_attr($it->sku); ?>"
                    data-denom="<?php echo esc_attr($it->denom_value); ?>"
                    data-rate="<?php echo esc_attr($it->sell_rate); ?>"
                    data-stock="<?php echo esc_attr($it->stock_balance); ?>">
                <?php echo esc_html($it->sku . ' - ' . $it->name ); ?>
            </option>
        <?php endforeach; ?>
        
    </select>
</div>
                                <!-- Transaction Input Grid -->
                                <div class="transaction-grid">
                                    <div class="grid-item">
                                        <label class="small-label">Qty</label>
                                        <input type="number" id="inp_qty" class="puri-input highlight-input" placeholder="0" min="1">
                                    </div>
                                    <div class="grid-item">
                                        <label class="small-label">Total Riyal</label>
                                        <input type="number" id="inp_total_riyal" class="puri-input" placeholder="0">
                                    </div>
                                    <div class="grid-item">
                                        <label class="small-label">Rate (IDR)</label>
                                        <input type="number" id="inp_rate" class="puri-input bg-gray" value="0" readonly>
                                    </div>
                                    <div class="grid-item">
                                        <label class="small-label">Total IDR</label>
                                        <input type="text" id="inp_total_idr" class="puri-input bg-gray" value="Rp 0" readonly>
                                    </div>
                                </div>

                                <!-- Item Preview & Action -->
                                <div class="item-preview-section">
                                    <div class="item-image-box">
                                        <img id="item_img_preview" src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/no-image.png'; ?>" alt="Item Preview">
                                    </div>
                                    <div class="item-action-box">
                                        <div class="rate-info">
                                            Base Rate: <span id="info_base_rate" class="text-blue">0</span>
                                        </div>
                                        <button type="button" id="btn_add_cart" class="button button-primary button-large" disabled>
                                            <i class="fa-solid fa-cart-plus"></i> Add to Cart
                                        </button>
                                        <div id="stock_warning" class="text-red hidden" style="text-align:center; font-size:11px; margin-top:5px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Exceeds Stock!
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden Inputs -->
                                <input type="hidden" id="inp_denom" value="0">
                                <input type="hidden" id="base_rate_hidden" value="0">
                                <input type="hidden" id="current_stock" value="0">
                                <input type="hidden" id="is_finance" value="<?php echo $is_finance_or_admin ? '1' : '0'; ?>">
								<input type="hidden" id="pos_location_id" value="<?php echo esc_attr($default_loc); ?>">

                            </div>
                        </div>
                    </div>

                    <!-- PANEL 3: CART & CHECKOUT -->
                    <div class="flex-col col-cart">
                        <div class="panel panel-cart">
                            <div class="panel-header">
                                <i class="fa-solid fa-cart-shopping"></i> 3. Shopping Cart
                            </div>
                            <div class="panel-body cart-scroll">
                                <table class="wp-list-table widefat fixed striped" id="cart_table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th width="50" class="tc">Qty</th>
                                            <th class="tr">Riyal</th>
                                            <th class="tr">IDR</th>
                                            <th width="40" class="tc">#</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="empty-cart">
                                            <td colspan="5" align="center" style="padding: 30px; color:#999;">
                                                <i class="fa-solid fa-cart-shopping" style="font-size:40px; opacity:0.3;"></i>
                                                <br>Cart is empty
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="panel-footer cart-summary-box">
                                
                                <!-- Payment & Delivery Options -->
                                <div class="checkout-options">
                                    <div class="option-row">
                                        <label class="small-label">Payment:</label>
                                        <select id="payment_method" class="puri-input-sm">
                                            <option value="cash">Cash</option>
                                            <option value="transfer">Bank Transfer</option>
                                            <option value="qris">QRIS</option>
                                        </select>
                                    </div>
                                    <div class="option-row">
                                        <label class="small-label">Delivery:</label>
                                        <select id="delivery_method" class="puri-input-sm">
                                            <option value="pickup">Picked Up</option>
                                            <option value="delivery">Delivery</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Summary Display -->
                                <div class="summary-line">
                                    <span>Subtotal Riyal</span>
                                    <span id="cart_total_riyal" class="val-riyal">0</span>
                                </div>
                                <div class="summary-line main">
                                    <span>Grand Total (IDR)</span>
                                    <span id="cart_total_idr" class="val-idr">Rp 0</span>
                                </div>

                                <!-- Checkout Actions -->
                                <div class="checkout-area">
                                    <label class="chk-valid">
                                        <input type="checkbox" id="chk_valid" checked> 
                                        Data & Amount Verified
                                    </label>
                                    <div class="button-group">
                                        <button type="button" id="btn_checkout" class="button button-primary button-hero">
                                            <i class="fa-solid fa-cash-register"></i> CHECKOUT
                                        </button>
                                        <button type="button" id="btn_reset_cart" class="button button-secondary">
                                            <i class="fa-solid fa-redo"></i> Reset
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div> <!-- End Top Row -->

                <!-- ============================================ -->
                <!-- BOTTOM ROW: 2 PANELS (STABLE - NO CHANGES) -->
                <!-- ============================================ -->
                <div class="flex-row bottom-row">
                    
                    <!-- Daily Mutation History -->
                    <div class="flex-col col-history">
                        <div class="panel panel-history">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-clock-rotate-left"></i> Daily Mutation (Today)</span>
                                <div id="box_history_summary" class="hidden" style="flex: 1; text-align: right; margin-right: 15px; font-size: 11px;">
                                    <span style="color:#2271b1; font-weight:700;">SAR <span id="val_sum_riyal">0</span></span> | 
                                    <span style="color:#d63638; font-weight:700;">Rp <span id="val_sum_idr">0</span></span>
                                </div>
                                <button class="button button-small" id="btn_refresh_history">
                                    <i class="fa-solid fa-sync"></i>
                                </button>
                            </div>
                            <div class="panel-body table-scroll">
                                <table class="wp-list-table widefat striped dense" id="history_table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Ref ID</th>
                                            <th class="tr">Riyal</th>
                                            <th class="tr">IDR</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Card Summary -->
                    <div class="flex-col col-stock">
                        <div class="panel panel-stock">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-table-list"></i> Stock Card (This Month)</span>
                            </div>
                            <div class="panel-body table-scroll">
                                <table class="wp-list-table widefat striped dense" id="stock_table">
                                    <thead>
                                        <tr class="sortable-header">
                                            <th data-sort="name">Item <i class="fa-solid fa-sort"></i></th>
                                            <th class="tr" data-sort="qty_start" style="background:#f0f0f1; border-left:2px solid #ccc;">Opening</th>
                                            <th class="tr text-red" data-sort="qty_out">Out</th>
                                            <th class="tr text-green" data-sort="qty_in">In</th>
                                            <th class="tr" data-sort="qty_end" style="background:#e6f7ff; font-weight:bold;">Closing</th>
                                            <th class="tr text-red" data-sort="sar_out" style="border-left:2px solid #ccc;">SAR Out</th>
                                            <th class="tr text-green" data-sort="sar_in">SAR In</th>
                                            <th class="tr" data-sort="sar_end" style="background:#e6f7ff; font-weight:bold;">SAR Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div> <!-- End Bottom Row -->

            </div> <!-- End Container -->
        </div> <!-- End Wrap -->

        <!-- ============================================ -->
        <!-- MODAL: QUICK ADD CUSTOMER                  -->
        <!-- ============================================ -->
        <div id="modal_add_customer" class="puri-modal" style="display:none;">
            <div class="puri-modal-content">
                <div class="puri-modal-header">
                    <h2><i class="fa-solid fa-user-plus"></i> Quick Add Customer</h2>
                    <button type="button" class="puri-modal-close">&times;</button>
                </div>
                <div class="puri-modal-body">
                    <form id="form_new_customer">
							<div class="col">
								<label class="small-label">Jenis Customer</label>
								<select name="cust_type" class="puri-input">
									<option value="umum">Umum</option>
									<option value="member">Member</option>
									<option value="agent">Agent</option>
									<option value="moneychanger">Money Changer</option>
									<option value="bank">Bank</option>
								</select>
							</div>
                        <div class="form-group">
                            <label class="small-label">Full Name *</label>
                            <input type="text" name="cust_name" class="puri-input" required>
                        </div>
                        <div class="form-row">
                            <div class="col">
                                <label class="small-label">Address *</label>
                                <input type="text" name="cust_address" class="puri-input" required>
                            </div>
                            <div class="col">
                                <label class="small-label">City *</label>
                                <input type="text" name="cust_city" class="puri-input" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="col">
                                <label class="small-label">Phone *</label>
                                <input type="tel" name="cust_phone" class="puri-input" required>
                            </div>
                            <div class="col">
                                <label class="small-label">Keperluan *</label>
                                <select name="cust_purpose" class="puri-input">
                                    <option value="umrah">Umrah</option>
                                    <option value="haji">Haji</option>
                                    <option value="wisata">Tourism</option>
                                    <option value="kerja">Work</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

						<div class="form-row">
							<div class="col">
								<label class="small-label">ID Type *</label>
								<select name="cust_id_type" id="cust_id_type" class="puri-input" required>
									<option value="KTP">KTP</option>
									<option value="SIM">SIM</option>
									<option value="NPWP">NPWP</option>
									<option value="Passport">NPWP</option>
									<option value="KUPVA">KUPVA</option>
								</select>
							</div>
							<div class="col">
								<label class="small-label">ID Number *</label>
								<input type="text" name="cust_id_number" id="cust_id_number" class="puri-input" required>
							</div>
						</div>

						<div class="form-group">
							<label class="small-label">Upload Photo ID *</label>
							<input type="file" name="cust_id_image" accept="image/*" required>
						</div>
											<div class="form-group" style="text-align:right; margin-top:20px;">
                            <button type="button" class="button" onclick="jQuery('#modal_add_customer').hide();">Cancel</button>
                            <button type="submit" class="button button-primary">
                                <i class="fa-solid fa-save"></i> Save Customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

<style>
		/* ============================================ */
		/* GLOBAL STYLES                              */
		/* ============================================ */
		.puri-cockpit-wrapper { box-sizing: border-box; padding-top: 10px; }
		.version-badge { font-size: 11px; background: #2271b1; color: white; padding: 2px 8px; border-radius: 3px; font-weight: normal; margin-left: 10px; }
		.cockpit-container { display: flex; flex-direction: column; gap: 15px; margin-top: 15px; }

		/* ============================================ */
		/* LAYOUT STRUCTURE                           */
		/* ============================================ */
		.flex-row { display: flex; gap: 15px; width: 100%; flex-wrap: wrap; }
		.flex-col { display: flex; flex-direction: column; gap: 15px; }
		.top-row .col-customer { flex: 1; min-width: 300px; }
		.top-row .col-transaction { flex: 1; min-width: 350px; }
		.top-row .col-cart { flex: 1; min-width: 400px; }
		.bottom-row .col-history { flex: 3; min-width: 300px; }
		.bottom-row .col-stock { flex: 7; min-width: 500px; }

		/* ============================================ */
		/* PANEL COMPONENTS                           */
		/* ============================================ */
		.panel { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 2px rgba(0,0,0,.05); border-radius: 6px; display: flex; flex-direction: column; height: 100%; }
		.panel-header { background: #f6f7f7; padding: 10px 15px; font-weight: 600; border-bottom: 1px solid #c3c4c7; font-size: 13px; display: flex; justify-content: space-between; align-items: center; color: #1d2327; }
		.panel-body { padding: 15px; flex-grow: 1; }
		.panel-footer { border-top: 1px solid #ddd; background: #fafafa; }

		/* ============================================ */
		/* FORM ELEMENTS                              */
		/* ============================================ */
		.puri-input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #8c8f94; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
		.puri-input-sm { height: 32px; padding: 0 8px; font-size: 13px; }
		.bg-gray { background-color: #f0f0f1; color: #646970; }
		.highlight-input { border-color: #2271b1; font-weight: bold; background: #fff; }
		.small-label { font-size: 11px; color: #646970; font-weight: 600; display: block; margin-bottom: 4px; text-transform: uppercase; }
		.form-group { margin-bottom: 12px; }
		.form-row { display: flex; gap: 10px; }
		.form-row .col { flex: 1; }
		.mb-2 { margin-bottom: 10px; }
		.mb-1 { margin-bottom: 8px; }

		/* ============================================ */
		/* CUSTOMER PANEL SPECIFIC                    */
		/* ============================================ */
		.mode-selector { display: flex; gap: 10px; background: #f0f0f1; padding: 8px; border-radius: 4px; border: 1px solid #dcdcde; }
		.radio-label { font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 3px; transition: all 0.2s; }
		.radio-label:hover { background: #e0e0e1; }
		.mode-sell input:checked ~ * { color: #0aaf1a; }
		.mode-buy input:checked ~ * { color: #e67e22; }
		.customer-select-wrapper { display: flex; gap: 5px; align-items: center; }
		#btn_add_customer { width: 40px; height: 38px; padding: 0; flex-shrink: 0; }
		.customer-info-box { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px; font-size: 12px; }
		.customer-info-box .info-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #e5e5e5; }
		.customer-info-box .info-row:last-child { border-bottom: none; }
		.customer-info-box .info-row .info-label { color: #646970; font-weight: 600; }
		.customer-info-box .info-row .info-value { color: #1d2327; }

		/* ============================================ */
		/* TRANSACTION PANEL SPECIFIC                 */
		/* ============================================ */
		.transaction-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
		.grid-item label { display: block; margin-bottom: 4px; }
		.item-preview-section { display: flex; gap: 10px; margin-top: 15px; }
		.item-image-box { width: 80px; height: 80px; background: #eee; border: 1px solid #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
		.item-image-box img { width: 100%; height: 100%; object-fit: cover; }
		.item-action-box { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
		.rate-info { font-size: 11px; color: #666; text-align: right; margin-bottom: 5px; }

		/* ============================================ */
		/* CART PANEL SPECIFIC                        */
		/* ============================================ */
		.cart-scroll { overflow-y: auto; height: 250px; padding: 0; border-bottom: 1px solid #eee; }
		#cart_table th { position: sticky; top: 0; z-index: 10; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
		.cart-summary-box { background: #fafafa; padding: 15px; }
		.checkout-options { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px dashed #ddd; }
		.option-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
		.option-row label { width: 80px; font-size: 12px; color: #646970; font-weight: 600; }
		.summary-line { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
		.summary-line.main { font-size: 18px; font-weight: bold; border-top: 2px solid #2271b1; padding-top: 12px; margin-top: 8px; }
		.val-idr { color: #d63638; font-weight: bold; }
		.val-riyal { color: #2271b1; font-weight: bold; }
		.checkout-area { margin-top: 15px; }
		.chk-valid { display: block; margin-bottom: 10px; font-size: 13px; }
		.button-group { display: flex; gap: 10px; }
		.button-hero { height: 45px !important; font-size: 15px !important; flex: 1; display: flex; justify-content: center; gap: 8px; align-items: center; }

		/* ============================================ */
		/* BOTTOM ROW TABLES (STABLE)                 */
		/* ============================================ */
		.table-scroll { height: 350px; overflow-y: auto; padding: 0; border-bottom: 1px solid #ddd; position: relative; background: #fff; }
		.table-scroll thead th { position: sticky; top: 0; background: #f0f0f1; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.1); cursor: pointer; user-select: none; }
		.table-scroll thead th:hover { background: #e0e0e1; }
		#history_table, #stock_table { border-top: none; margin-top: 0; width: 100%; border-collapse: collapse; font-size: 11px; }
		#stock_table td { padding: 6px 8px; vertical-align: middle; }

		/* ============================================ */
		/* MODAL STYLES                               */
		/* ============================================ */
		.puri-modal { position: fixed; z-index: 99999; inset: 0; background-color: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; }
		.puri-modal-content { background-color: #fff; border-radius: 8px; width: 90%; max-width: 600px; max-height: 

/* --------------- Status Row Styling -------------*/
tr.status-pending { background: #fffbeb; }
tr.status-verified { background: #eff6ff; }
tr.status-posted { background: #f0fdf4; opacity: 0.7; }
tr.status-void { background: #fef2f2; opacity: 0.5; text-decoration: line-through; }

/* --------------- Badge Styling -----------------*/
.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}


</style>


// ─────────────────────────────────────────────────────────────────┤ ES6 ARSITEKTUR ├──────────────────
<script>
jQuery($ => {

/* =====================================================
 * UTILITIES (Tetap, sudah bagus)
 * ===================================================== */
const U = {
  num : v => parseFloat(v) || 0,
  fmt : n => n.toLocaleString('en-US'),
  idr : n => n.toLocaleString('id-ID'),
  ajax: cfg => $.ajax(Object.assign({
    url: ajaxurl,
    cache: false,
    error: () => Swal.fire('Error','Server error','error')
  }, cfg))
};

/* =====================================================
 * MAIN CLASS: CockpitPOS
 * ===================================================== */
class CockpitPOS {

  constructor() {
    this.cart = [];
    this.stockData = [];
    this.currentCustomer = null;
	this.currentEditRef = null; // Menyimpan referensi asal jika sedang proses Edit
	
    this.tradeMode = 'sell'; // default
    this.sortKey = 'denom';
    this.sortAsc = true;

    this.cacheDom();
    this.init();
  }

  cacheDom() {
    this.$item   = $('#item_select');
    this.$cust   = $('#customer_select');
    this.$qty    = $('#inp_qty');
    this.$riyal  = $('#inp_total_riyal'); // Representasi total_valas
    this.$idr    = $('#inp_total_idr');
    this.$rate   = $('#inp_rate');
    this.$btnAdd = $('#btn_add_cart');
    this.$panel  = $('#panelInputTransaksi');
    this.$payMethod = $('#payment_method'); // TAMBAHAN: Untuk Mozart
  }

  init() {
    this.loadStockAndHistory();
    this.bindEvents();

    this.$item.select2({ placeholder:'Select Item...', width:'100%' });
    this.$cust.select2({ placeholder:'Select Customer...', width:'100%', allowClear:true });

    this.applyModeGuard();
  }

bindEvents() {
    // Mode Switcher
    $('input[name="trade_mode"]').on('change', e => {
        this.tradeMode = e.target.value;
        this.applyModeGuard();
    });

    // Customer Dropdown Events
    this.$cust
        .on('select2:select', () => this.selectCustomer())
        .on('select2:clear',  () => this.clearCustomer());

    // Item Selection
    this.$item.on('select2:select', () => this.selectItem());

    // Calculation Inputs
    this.$qty.on('input',   () => this.syncFromQty());
    this.$riyal.on('input', () => this.syncFromRiyal());
    this.$rate.on('input',  () => this.calcFinalIDR());

    // Cart Actions
    this.$btnAdd.on('click', () => this.addToCart());
    $('#btn_checkout').on('click', () => this.handleCheckout());
    $('#btn_reset_cart').on('click', () => {
        if (confirm('Reset cart?')) {
            this.cart = [];
            this.renderCart();
            this.unlockSession();
        }
    });

    // Item Removal (Event Delegation)
    $(document).on('click', '.btn-remove-item', e => 
        this.removeItem($(e.currentTarget).data('index'))
    );

    // History Refresh
    $('#btn_refresh_history').on('click', () => this.loadStockAndHistory());
// Pool Transaction Actions (Event Delegation)
$(document).on('click', '.edit-pool-btn', (e) => {
    const refId = $(e.currentTarget).data('ref-id');
    console.log('🖱️ Edit Button Clicked, ref_id:', refId);
    this.editFromPool(refId);
});

$(document).on('click', '.void-pool-btn', (e) => {
    const refId = $(e.currentTarget).data('ref-id');
    console.log('🖱️ Void Button Clicked, ref_id:', refId);
    this.confirmVoid(refId);
});

    // Clear Form Button
    $('#btn_clear_form').on('click', () => {
        this.$item.val('').trigger('change');
        this.$qty.val('');
        this.$riyal.val('');
        this.$rate.val('');
        this.$idr.val('Rp 0');
        $('#inp_denom').val('0');
        $('#base_rate_hidden').val('0');
        $('#current_stock').val('0');
    });


    // Open Modal
    $('#btn_add_customer').on('click', () => {
        $('#modal_add_customer').fadeIn(200);
    });
    
    // Close Modal - X Button
    $('.puri-modal-close').on('click', () => {
        $('#modal_add_customer').fadeOut(200);
    });
    
    // Close Modal - Background Click
    $('#modal_add_customer').on('click', function(e) {
        if ($(e.target).is('#modal_add_customer')) {
            $(this).fadeOut(200);
        }
    });
    
    // Form Submission
    $('#form_new_customer').on('submit', (e) => {
        e.preventDefault();
        this.submitNewCustomer();
    });
}



  /* ---------------- CUSTOMER LOGIC ---------------- */
selectCustomer() {
    const o = this.$cust.find(':selected');
    
    if (!o.val()) {
        this.clearCustomer();
        return;
    }

    console.log('🔍 DEBUG Customer Selection:');
    console.log('- Selected Value:', o.val());
    console.log('- Customer Name:', o.text());
    console.log('- Data NIK:', o.data('nik'));
    console.log('- Data Phone:', o.data('phone'));
    console.log('- Data Address:', o.data('address'));

    
    // Update internal state
    this.currentCustomer = {
        id: o.val(),
        name: o.text(),
        type: o.data('type') || 'member',
        nik: o.data('nik') || '-',
        phone: o.data('phone') || '-',
        address: o.data('address') || '-',
        citizenship: o.data('citizenship') || 'Indonesian',
        purpose: o.data('purpose') || '-',
        occupation: o.data('occupation') || '-'
    };
    
    // ✅ UPDATE DOM ELEMENTS (THIS WAS MISSING!)
    $('#info_nik').text(this.currentCustomer.nik);
    $('#info_phone').text(this.currentCustomer.phone);
    $('#info_address').text(this.currentCustomer.address);
    $('#info_citizenship').text(this.currentCustomer.citizenship);
    $('#info_occupation').text(this.currentCustomer.occupation);
    $('#info_purpose').text(this.currentCustomer.purpose);
    
    // Show info box
    $('#customer_info_box').removeClass('hidden');
    
    // Rate Editable Logic
    // Agent dan Bank bisa edit rate manual
    if (['agent', 'bank', 'moneychanger'].includes(this.currentCustomer.type)) {
        this.$rate.prop('readonly', false).removeClass('bg-gray');
        this.$rate.attr('placeholder', 'Custom rate for ' + this.currentCustomer.type);
    } else {
        this.$rate.prop('readonly', true).addClass('bg-gray');
    }
    
    console.log('✅ Customer Selected:', this.currentCustomer);
}

// ============================================================================
// PATCH 3: CLEAR CUSTOMER (Replace existing method)
// ============================================================================
clearCustomer() {
    this.currentCustomer = null;
    
    // Clear info display
    $('#info_nik').text('-');
    $('#info_phone').text('-');
    $('#info_address').text('-');
    $('#info_citizenship').text('-');
    
    // Hide info box
    $('#customer_info_box').addClass('hidden');
    
    // Reset rate to readonly
    this.$rate.prop('readonly', true).addClass('bg-gray');
}

// ============================================================================
// PATCH 4: SUBMIT NEW CUSTOMER (NEW METHOD)
// ============================================================================
async submitNewCustomer() {
    const form = $('#form_new_customer')[0];
    const fileInput = form.querySelector('[name="cust_id_image"]');
    
    // Validation
    if (!form.cust_name.value.trim()) {
        Swal.fire('Error', 'Customer name is required', 'error');
        return;
    }
    
    if (!form.cust_id_number.value.trim()) {
        Swal.fire('Error', 'ID Number is required', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Uploading...',
        text: 'Please wait',
        didOpen: () => Swal.showLoading()
    });
    
    let attachmentId = 0;
    
    // ========================================================================
    // STEP 1: Upload Image First (if exists)
    // ========================================================================
    if (fileInput && fileInput.files.length > 0) {
        const uploadData = new FormData();
        uploadData.append('action', 'puri_pos_upload_customer_id');
        uploadData.append('security', '<?php echo wp_create_nonce("puri_pos_nonce"); ?>');
        uploadData.append('customer_id_file', fileInput.files[0]);
        uploadData.append('id_type', form.cust_id_type.value);
        uploadData.append('customer_name', form.cust_name.value);
        uploadData.append('customer_id', form.cust_id_number.value);
        
        try {
            const uploadResult = await $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: uploadData,
                processData: false,
                contentType: false
            });
            
            if (uploadResult.success) {
                attachmentId = uploadResult.data.attachment_id;
                console.log('✅ Image uploaded, ID:', attachmentId);
            } else {
                throw new Error(uploadResult.data || 'Upload failed');
            }
        } catch (err) {
            Swal.fire('Upload Failed', err.message || 'Image upload error', 'error');
            return;
        }
    }
  
    // ========================================================================
    // STEP 2: Create Customer with Image Attachment ID
    // ========================================================================
    const formData = new FormData(form);
    formData.append('action', 'puri_pos_create_customer');
    formData.append('nonce', '<?php echo wp_create_nonce("puri_pos_create_customer"); ?>');
    formData.append('attachment_id', attachmentId);
    
    // Remove file from FormData (already uploaded)
    formData.delete('cust_id_image');
    
    try {
        const result = await $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        });
        
        if (result.success) {
            // Close modal
            $('#modal_add_customer').fadeOut(200);
            form.reset();
            
            // Add to dropdown with data attributes
            const newOption = new Option(result.data.name, result.data.id, true, true);
            $(newOption).attr({
                'data-type': result.data.type || 'member',
                'data-nik': result.data.nik,
                'data-phone': result.data.phone,
                'data-address': result.data.address,
                'data-citizenship': result.data.citizenship || 'Indonesian'
            });
            
            this.$cust.append(newOption).trigger('change');
            
            Swal.fire({
                icon: 'success',
                title: 'Customer Created!',
                text: 'Customer has been added to the system',
                timer: 2000,
                showConfirmButton: false
            });
            
            console.log('✅ Customer created:', result.data);
        } else {
            throw new Error(result.data || 'Failed to create customer');
        }
    } catch (err) {
        Swal.fire('Failed', err.message || 'Server error', 'error');
    }
}


  /* ---------------- CALCULATION LOGIC ---------------- */
  selectItem() {
    const o = this.$item.find(':selected');
    $('#inp_denom').val(U.num(o.data('denom')));
    $('#base_rate_hidden').val(U.num(o.data('rate')));
    $('#current_stock').val(U.num(o.data('stock')));
    
    this.$rate.val(U.num(o.data('rate')));
    this.$qty.val('');
    this.$riyal.val('');
    this.$idr.val('Rp 0');

    this.checkStockLock();
  }

  syncFromQty() {
    const d = U.num($('#inp_denom').val());
    if (d) this.$riyal.val(U.num(this.$qty.val()) * d || '');
    this.recalc();
  }

  syncFromRiyal() {
    const d = U.num($('#inp_denom').val());
    if (d) this.$qty.val(U.num(this.$riyal.val()) / d || '');
    this.recalc();
  }

  recalc() {
    const idr = U.num(this.$riyal.val()) * U.num(this.$rate.val());
    this.$idr.val('Rp ' + U.idr(idr));
    this.checkStockLock();
  }

  checkStockLock() {
    const qty = U.num(this.$qty.val());
    const stock = U.num($('#current_stock').val());
    const ok = qty > 0 && (this.tradeMode === 'buy' || qty <= stock);
    this.$btnAdd.prop('disabled', !ok);
  }

  /* ---------------- CART LOGIC (Sinkron Mozart) ---------------- */
  addToCart() {
    const o = this.$item.find(':selected');
    const item = {
      id:          this.$item.val(),
      sku:         o.data('sku'), // TAMBAHAN: Untuk Mozart Ledger
      name:        o.text().split('(')[0].trim(),
      denom:       $('#inp_denom').val(),
      qty:         U.num(this.$qty.val()),
      total_valas: U.num(this.$riyal.val()), // Ubah nama agar sinkron backend
      rate:        U.num(this.$rate.val()),
      subtotal_idr: U.num(this.$riyal.val()) * U.num(this.$rate.val()) // Ubah nama
    };

    this.cart.push(item);
    this.lockSession();
    this.renderCart();
  }

  removeItem(i) {
    this.cart.splice(i,1);
    this.renderCart();
    if (!this.cart.length) this.unlockSession();
  }

renderCart() {
    const $tb = $('#cart_table tbody').empty();
    let totalV = 0, totalI = 0;

    // Jika ada isi cart, lakukan loop
    if (this.cart.length > 0) {
        this.cart.forEach((x, n) => {
          totalV += x.total_valas; 
          totalI += x.subtotal_idr;
          $tb.append(`
            <tr>
              <td>${x.name}</td>
              <td class="tc">${x.qty}</td>
              <td class="tr">${U.fmt(x.total_valas)}</td>
              <td class="tr">${U.idr(x.subtotal_idr)}</td>
              <td class="tc">
                <button class="btn-remove-item" data-index="${n}">❌</button>
              </td>
            </tr>`);
        });
    } else {
        // Jika kosong, tampilkan placeholder
        $tb.html(`<tr><td colspan="5" align="center" style="padding: 30px; color:#999;">
                  <i class="fa-solid fa-cart-shopping" style="font-size:40px; opacity:0.3;"></i><br>Cart is empty</td></tr>`);
    }

    // UPDATE: Label total harus selalu diupdate (menjadi 0 jika cart kosong)
    $('#cart_total_riyal').text(U.fmt(totalV));
    $('#cart_total_idr').text('Rp ' + U.idr(totalI));
  }
  
/* =====================================================
   * RENDER HISTORY (POOL MONITOR)
   * ===================================================== */
renderHistoryTable() {
    const $tb = $('#history_table tbody').empty();
    
    // ✅ VALIDASI DATA DENGAN BENAR
    if (!this.historyData || !this.historyData.transactions || this.historyData.transactions.length === 0) {
        $tb.html('<tr><td colspan="4" align="center" style="padding:20px; color:#999;">Belum ada transaksi hari ini.</td></tr>');
        $('#box_history_summary').addClass('hidden'); // Hide summary jika kosong
        return;
    }

    // ✅ RENDER SETIAP BARIS
    this.historyData.transactions.forEach((h) => {
        // Status badge dengan warna berbeda
        const statusBadges = {
            'pending': '<span class="badge" style="background:#f59e0b; color:#fff;">⏳ Pending</span>',
            'verified': '<span class="badge" style="background:#3b82f6; color:#fff;">✓ Verified</span>',
            'posted': '<span class="badge" style="background:#10b981; color:#fff;">✅ Posted</span>',
            'void': '<span class="badge" style="background:#ef4444; color:#fff;">❌ Void</span>'
        };
        
        const statusBadge = statusBadges[h.status] || h.status;
        
        // Mode badge
        const modeColor = h.trade_mode === 'sell' ? '#2271b1' : '#d63638';
        const modeIcon = h.trade_mode === 'sell' ? 'fa-arrow-down' : 'fa-arrow-up';
        const modeLabel = h.trade_mode === 'sell' ? 'JUAL' : 'BELI';
        
        // ✅ Disable action buttons jika sudah posted/void
        const canEdit = (h.status === 'pending' || h.status === 'verified');
        const actionButtons = canEdit ? `
            <button type="button" class="button button-small edit-pool-btn" 
                    data-ref-id="${h.ref_id}" 
                    title="Edit">
                <i class="fa fa-pencil-alt" style="color:#2271b1"></i>
            </button>
            <button type="button" class="button button-small void-pool-btn" 
                    data-ref-id="${h.ref_id}" 
                    title="Void">
                <i class="fa fa-trash" style="color:#d63638"></i>
            </button>
        ` : `
            <span style="color:#999; font-size:10px;">Locked</span>
        `;
        
        $tb.append(`
            <tr class="status-${h.status}">
                <td class="tc"><strong>${h.time}</strong></td>
                <td>
                    <div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                        <span class="badge-mode" style="background:${modeColor}; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px;">
                            <i class="fa ${modeIcon}"></i> ${modeLabel}
                        </span>
                        ${statusBadge}
                    </div>
                    <code style="font-weight:bold; margin-top:3px; display:block;">${h.ref_id}</code>
                    <small style="color:#666;">${h.customer_name} | ${h.cashier}</small>
                </td>
                <td class="tr" style="font-family:monospace; font-weight:bold; color:${modeColor};">
                    ${h.trade_mode === 'sell' ? '+' : '-'} ${U.idr(h.total_amount)}
                </td>
                <td class="tc">
                    ${actionButtons}
                </td>
            </tr>
        `);
    });
    
    // ✅ UPDATE SUMMARY BADGE DI HEADER
    if (this.historyData.summary) {
        const s = this.historyData.summary;
        $('#box_history_summary').removeClass('hidden').html(`
            <span style="color:#f59e0b; font-weight:700;">⏳ ${s.pending}</span> |
            <span style="color:#3b82f6; font-weight:700;">✓ ${s.verified}</span> |
            <span style="color:#10b981; font-weight:700;">✅ ${s.posted}</span> |
            <span style="color:#d63638; font-weight:700;">Total: Rp ${U.idr(s.total_idr)}</span>
        `);
    }
}


  // Helper untuk konfirmasi penghapusan (Void)
confirmVoid(refId) {
    Swal.fire({
        title: 'Hapus Transaksi?',
        html: `
            <p>Transaksi <code>${refId}</code> akan dibatalkan.</p>
            <p><strong>Stok fisik akan dikembalikan.</strong></p>
        `,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#d63638',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            // ✅ Tampilkan loading
            Swal.fire({
                title: 'Processing...',
                text: 'Membatalkan transaksi',
                didOpen: () => Swal.showLoading()
            });

            U.ajax({
                data: {
                    action: 'puri_pos_void_pool_transaction',
                    ref_id: refId,
					silent_mode: '1',
                    nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
                },
                success: (r) => {
                    if (r.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: r.data.message || 'Transaksi berhasil dibatalkan',
                            timer: 2000
                        });
                        
                        // ✅ Refresh history & stock
                        this.loadStockAndHistory();
                    } else {
                        Swal.fire('Failed', r.data || 'Gagal menghapus transaksi', 'error');
                    }
                },
                error: (xhr, status, err) => {
                    console.error('❌ AJAX Error:', xhr.responseText);
                    Swal.fire('Error', 'Network error: ' + err, 'error');
                }
            });
        }
    });
}

// ✅ Silent void (untuk edit flow)
silentVoid(refId) {
    return new Promise((resolve, reject) => {
        U.ajax({
            data: { 
                action: 'puri_pos_void_pool_transaction', 
                ref_id: refId,
                nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
            },
            success: (r) => {
                if (r.success) {
                    console.log('✅ Silent void success:', refId);
                    resolve(r);
                } else {
                    console.error('❌ Silent void failed:', r.data);
                    reject(r.data);
                }
            },
            error: (xhr) => {
                console.error('❌ Silent void AJAX error:', xhr.responseText);
                reject(xhr.responseText);
            }
        });
    });
}


/* =====================================================
 * EDIT FROM POOL LOGIC  - CRUD SCHEME
 * ===================================================== */
/**
 * ============================================================================
 * EDIT FROM POOL - COMPLETE IMPLEMENTATION
 * ============================================================================
 * Flow:
 * 1. User clicks Edit button → Confirmation dialog
 * 2. Fetch transaction snapshot from backend
 * 3. Validate transaction status (must be pending/verified)
 * 4. Silent void old transaction (reverse stock)
 * 5. Restore data to cart & form
 * 6. User can modify & checkout as new transaction
 * ============================================================================
 */


/**
 * ============================================================================
 * EDIT FROM POOL - COMPLETE IMPLEMENTATION
 * ============================================================================
 * Flow:
 * 1. User clicks Edit button → Confirmation dialog
 * 2. Fetch transaction snapshot from backend
 * 3. Validate transaction status (must be pending/verified)
 * 4. Silent void old transaction (reverse stock)
 * 5. Restore data to cart & form
 * 6. User can modify & checkout as new transaction
 * ============================================================================
 */

editFromPool(refId) {
    console.log('🖱️ Edit Button Clicked, ref_id:', refId);
    
    // ========================================================================
    // STEP 1: CONFIRMATION DIALOG
    // ========================================================================
    Swal.fire({
        title: 'Edit Transaksi?',
        html: `
            <p>Transaksi <code>${refId}</code> akan dikembalikan ke keranjang untuk diperbaiki.</p>
            <p><strong>⚠️ Transaksi lama akan dibatalkan (void).</strong></p>
            <p style="color:#666; font-size:13px;">Stok fisik akan dikembalikan ke lokasi asal.</p>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2271b1',
        cancelButtonColor: '#999',
        confirmButtonText: '<i class="fa fa-box-open"></i> Ya, Bongkar Keranjang',
        cancelButtonText: 'Batal',
        reverseButtons: true
    }).then((result) => {
        if (!result.isConfirmed) {
            console.log('❌ Edit cancelled by user');
            return;
        }

        // ====================================================================
        // STEP 2: SHOW LOADING INDICATOR
        // ====================================================================
        Swal.fire({
            title: 'Memproses...',
            html: `
                <div style="padding:20px;">
                    <i class="fa fa-spinner fa-spin" style="font-size:40px; color:#2271b1;"></i>
                    <p style="margin-top:15px;">Mengambil data transaksi</p>
                </div>
            `,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });

        // ====================================================================
        // STEP 3: FETCH TRANSACTION SNAPSHOT
        // ====================================================================
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'puri_pos_get_pool_snapshot',
                ref_id: refId,
                nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
            },
            success: (response) => {
                console.log('📦 AJAX Response:', response);

                // ============================================================
                // VALIDATION: Check if request successful
                // ============================================================
                if (!response.success) {
                    console.error('❌ Snapshot fetch failed:', response.data);
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Mengambil Data',
                        text: response.data || 'Terjadi kesalahan saat mengambil data transaksi',
                        confirmButtonColor: '#d63638'
                    });
                    return;
                }

                const snapshot = response.data;
                
                // ============================================================
                // VALIDATION: Check data completeness
                // ============================================================
                if (!snapshot.cart || !Array.isArray(snapshot.cart) || snapshot.cart.length === 0) {
                    console.error('❌ Invalid cart data:', snapshot.cart);
                    Swal.fire({
                        icon: 'error',
                        title: 'Data Tidak Valid',
                        text: 'Keranjang transaksi kosong atau rusak',
                        confirmButtonColor: '#d63638'
                    });
                    return;
                }

                console.log('✅ Snapshot valid, proceeding to void...');

                // ============================================================
                // STEP 4: VOID OLD TRANSACTION (Silent Mode)
                // ============================================================
                this.silentVoid(refId)
                    .then(() => {
                        console.log('✅ Void completed successfully');
                        
                        // ====================================================
                        // STEP 5: RESTORE DATA TO CART & FORM
                        // ====================================================
                        this.restoreFromSnapshot(snapshot, refId);
                        
                        // ====================================================
                        // STEP 6: SUCCESS FEEDBACK
                        // ====================================================
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil Dikembalikan!',
                            html: `
                                <p>Transaksi <code>${refId}</code> berhasil dikembalikan ke keranjang.</p>
                                <p style="color:#666; margin-top:10px;">Silakan lakukan perbaikan dan checkout ulang.</p>
                            `,
                            timer: 2500,
                            showConfirmButton: false
                        });

                        // Scroll to transaction panel for better UX
                        setTimeout(() => {
                            $('html, body').animate({
                                scrollTop: $('#panelInputTransaksi').offset().top - 100
                            }, 500);
                        }, 500);
                    })
                    .catch((error) => {
                        console.error('❌ Void failed:', error);
                        
                        // ====================================================
                        // ERROR HANDLING: Void failed but data restored
                        // ====================================================
                        Swal.fire({
                            icon: 'warning',
                            title: 'Peringatan',
                            html: `
                                <p><strong>Gagal membatalkan transaksi lama.</strong></p>
                                <p>Error: ${error}</p>
                                <hr style="margin:15px 0;">
                                <p style="color:#666;">Data tetap ter-restore ke keranjang, tapi checkout mungkin akan gagal karena transaksi lama belum dibatalkan.</p>
                                <p style="color:#d63638; font-weight:bold;">Harap hubungi supervisor jika masalah berlanjut.</p>
                            `,
                            confirmButtonColor: '#f59e0b',
                            confirmButtonText: 'OK, Saya Mengerti'
                        });

                        // Still restore data for manual intervention
                        this.restoreFromSnapshot(snapshot, refId);
                    });

            },
            error: (xhr, status, err) => {
                console.error('❌ AJAX Error:', xhr.responseText);
                
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    html: `
                        <p>Gagal terhubung ke server</p>
                        <p style="color:#999; font-size:12px; margin-top:10px;">Error: ${err}</p>
                        <hr style="margin:15px 0;">
                        <p style="font-size:13px;">Coba:</p>
                        <ul style="text-align:left; padding-left:20px; font-size:13px;">
                            <li>Refresh halaman (F5)</li>
                            <li>Periksa koneksi internet</li>
                            <li>Hubungi IT support jika masalah berlanjut</li>
                        </ul>
                    `,
                    confirmButtonColor: '#d63638'
                });
            }
        });
    });
}

/**
 * ============================================================================
 * SILENT VOID - Promise-based Transaction Cancellation
 * ============================================================================
 * Cancels old transaction without showing alerts (for edit flow)
 * Returns Promise for proper async handling
 * ============================================================================
 */
silentVoid(refId) {
    return new Promise((resolve, reject) => {
        console.log('🔇 Starting silent void for:', refId);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { 
                action: 'puri_pos_void_pool_transaction', 
                ref_id: refId,
                silent_mode: '1', // Flag to skip user notifications in backend
                nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
            },
            success: (response) => {
                if (response.success) {
                    console.log('✅ Silent void successful:', refId);
                    resolve(response.data);
                } else {
                    console.error('❌ Silent void failed:', response.data);
                    reject(response.data || 'Void operation failed');
                }
            },
            error: (xhr, status, error) => {
                console.error('❌ Silent void AJAX error:', xhr.responseText);
                reject(`Network error: ${error}`);
            }
        });
    });
}

/**
 * ============================================================================
 * RESTORE FROM SNAPSHOT - Data Restoration Logic
 * ============================================================================
 * Restores transaction data to cart and form controls
 * ============================================================================
 */
restoreFromSnapshot(snapshot, refId) {
    console.log('📦 Restoring snapshot:', snapshot);
    
    // ========================================================================
    // 1. RESTORE TRADE MODE
    // ========================================================================
    this.tradeMode = snapshot.trade_mode;
    $(`input[name="trade_mode"][value="${this.tradeMode}"]`).prop('checked', true);
    this.applyModeGuard(); // Apply visual styling
    console.log('✅ Trade mode restored:', this.tradeMode);
    
    // ========================================================================
    // 2. RESTORE CUSTOMER
    // ========================================================================
    const custId = snapshot.cust_id;
    console.log('👤 Restoring customer ID:', custId);
    
    if (custId && custId > 0) {
        // Set value and trigger Select2 refresh
        this.$cust.val(custId).trigger('change.select2');
        
        // Wait for Select2 to finish rendering, then validate
        setTimeout(() => {
            const selectedOption = this.$cust.find(':selected');
            
            if (selectedOption.length && selectedOption.val() == custId) {
                console.log('✅ Customer restored:', selectedOption.text());
                this.selectCustomer(); // Populate customer info box
            } else {
                console.error('❌ Customer restoration failed for ID:', custId);
                
                Swal.fire({
                    icon: 'warning',
                    title: 'Customer Tidak Ditemukan',
                    text: 'Data customer tidak ter-restore. Silakan pilih ulang customer.',
                    confirmButtonColor: '#f59e0b',
                    timer: 3000
                });
            }
        }, 150); // Small delay for Select2 DOM update
        
    } else {
        console.warn('⚠️ No valid customer ID in snapshot');
        this.clearCustomer();
    }
    
    // ========================================================================
    // 3. RESTORE CART ITEMS
    // ========================================================================
    this.cart = snapshot.cart;
    console.log('🛒 Cart restored with items:', this.cart.length);
    
    // Validate cart data structure
    if (this.cart.length > 0) {
        // Check if first item has required fields
        const firstItem = this.cart[0];
        const requiredFields = ['id', 'item_id', 'sku', 'name', 'denom', 'qty', 'rate'];
        const missingFields = requiredFields.filter(field => !(field in firstItem));
        
        if (missingFields.length > 0) {
            console.error('❌ Cart items missing fields:', missingFields);
            console.error('Sample item:', firstItem);
        }
    }
    
    this.renderCart(); // Update cart display
    
    // ========================================================================
    // 4. RESTORE PAYMENT & DELIVERY OPTIONS
    // ========================================================================
    if (snapshot.pay_method) {
        this.$payMethod.val(snapshot.pay_method);
        console.log('💳 Payment method restored:', snapshot.pay_method);
    }
    
    if (snapshot.delivery_method) {
        $('#delivery_method').val(snapshot.delivery_method);
        console.log('🚚 Delivery method restored:', snapshot.delivery_method);
    }
    
    // ========================================================================
    // 5. SET EDIT MODE FLAG
    // ========================================================================
    this.currentEditRef = refId;
    console.log('✏️ Edit mode activated, replacing:', refId);
    
    // ========================================================================
    // 6. LOCK SESSION (Prevent mode switching during edit)
    // ========================================================================
    this.lockSession();
    
    // ========================================================================
    // 7. VISUAL FEEDBACK
    // ========================================================================
    // Add visual indicator that we're in edit mode
    const $panel = $('#panelInputTransaksi');
    $panel.addClass('edit-mode');
    
    // Add banner notification
    if ($('.edit-mode-banner').length === 0) {
        $panel.prepend(`
            <div class="edit-mode-banner" style="
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
                padding: 12px 20px;
                margin: -15px -15px 15px -15px;
                border-radius: 6px 6px 0 0;
                display: flex;
                align-items: center;
                gap: 10px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            ">
                <i class="fa fa-pencil-alt" style="font-size:18px;"></i>
                <div style="flex:1;">
                    <strong style="display:block; font-size:14px;">EDIT MODE</strong>
                    <small style="opacity:0.9;">Memperbaiki transaksi: <code style="background:rgba(255,255,255,0.2); padding:2px 6px; border-radius:3px;">${refId}</code></small>
                </div>
                <button type="button" class="button button-small" onclick="location.reload();" style="background:rgba(255,255,255,0.2); border:none; color:white;">
                    <i class="fa fa-times"></i> Batalkan Edit
                </button>
            </div>
        `);
    }
    
    console.log('✅ Snapshot restoration complete');
}

  /* ---------------- SESSION & DATA ---------------- */
	lockSession() { $('input[name="trade_mode"]').prop('disabled', true); }
	unlockSession() { $('input[name="trade_mode"]').prop('disabled', false); }

	fullReset() {
		this.cart = [];
		this.renderCart();
		this.unlockSession();
		this.$cust.val(null).trigger('change'); 
		this.clearCustomer(); 
		this.$item.val(null).trigger('change');
		$('#btn_clear_form').click(); 
		this.currentEditRef = null;
		$('#panelInputTransaksi').removeClass('edit-mode');
		$('.edit-mode-banner').remove();
		console.log('✅ UI State fully reset.');
	  }


  applyModeGuard() {
    this.$panel.removeClass('mode-buy mode-sell').addClass(`mode-${this.tradeMode}`);
  }

loadStockAndHistory() {
    // ✅ LOAD STOCK (existing code tetap)
    U.ajax({
        data: { action: 'puri_pos_get_stock_summary' },
        success: r => { 
            if(r.success) { 
                this.stockData = r.data; 
                this.renderStockTable(); 
            } else {
                console.error('❌ Stock Load Failed:', r.data);
            }
        },
        error: (xhr) => {
            console.error('❌ Stock AJAX Error:', xhr.responseText);
        }
    });
    
    // ✅ LOAD HISTORY (FIXED!)
    U.ajax({
        data: { action: 'puri_pos_get_pool_history' },
        success: r => {
            if(r.success) {
                console.log('✅ History Data Received:', r.data);
                this.historyData = r.data;
                this.renderHistoryTable(); // ✅ Sekarang akan jalan!
            } else {
                console.error('❌ History Load Failed:', r.data);
                $('#history_table tbody').html(
                    '<tr><td colspan="4" align="center" style="padding:20px; color:#d63638;">Error loading history</td></tr>'
                );
            }
        },
        error: (xhr) => {
            console.error('❌ History AJAX Error:', xhr.responseText);
            $('#history_table tbody').html(
                '<tr><td colspan="4" align="center" style="padding:20px; color:#d63638;">Network Error</td></tr>'
            );
        }
    });
}
renderStockTable() {
    // 1. Update Tabel Stock Card (Bagian Bawah)
    const html = this.stockData.map(s => `
      <tr>
        <td><strong>${s.name}</strong></td>
        <td class="tr">${U.fmt(s.qty_end)}</td>
        <td class="tr">${U.fmt(s.sar_end)}</td>
      </tr>`).join('');
    $('#stock_table tbody').html(html);

    // 2. PATCH: Update Dropdown Item (Select2) agar sinkron dengan stok terbaru
    this.stockData.forEach(s => {
        // Cari option yang memiliki SKU yang sama
        const $opt = this.$item.find(`option[data-sku="${s.sku}"]`);
        if ($opt.length) {
            // Update atribut data-stock dan label teksnya
            $opt.attr('data-stock', s.qty_end);
            $opt.text(`${s.sku} - ${s.name} (Stok: ${U.fmt(s.qty_end)})`);
        }
    });

    // Beritahu Select2 bahwa data telah berubah secara internal
    this.$item.trigger('change.select2'); 
  }
  
  
  /* ---------------- CHECKOUT (Sinkron Mozart) ---------------- */
handleCheckout() {
    if (!this.cart.length) return Swal.fire('Empty','Cart is empty','warning');
    if (!this.currentCustomer) return Swal.fire('Customer','Select customer','error');

    const isEditMode = this.currentEditRef !== null;
    const actionText = isEditMode ? 'Update Transaksi' : 'Proses Transaksi';
    
    Swal.fire({
        title: `${actionText}?`,
        html: `
            <p>Total: ${$('#cart_total_idr').text()}</p>
            ${isEditMode ? `<p style="color:#f59e0b; font-weight:bold;">⚠️ Ini akan mengganti transaksi: ${this.currentEditRef}</p>` : ''}
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: actionText,
        confirmButtonColor: isEditMode ? '#f59e0b' : '#2271b1'
    }).then(r => {
        if(r.isConfirmed) this.processTransaction();
    });
}


	processTransaction() {
		Swal.fire({ title:'Processing...', didOpen:()=>Swal.showLoading() });

		const fd = new FormData();
		fd.append('action', 'puri_pos_checkout');
		fd.append('nonce',  '<?php echo wp_create_nonce("puri_pos_checkout"); ?>');
		
		
		
		fd.append('cart',   JSON.stringify(this.cart)); // Sekarang isinya key Mozart
		fd.append('trade_mode', this.tradeMode);
		fd.append('cust_id',    this.currentCustomer.id);
		fd.append('payment_method', this.$payMethod.val()); // Kirim ID Akun (Kas/Bank)
		if (this.currentEditRef) fd.append('old_ref_id', this.currentEditRef);
		console.log('✏️ EDIT MODE: Replacing', this.currentEditRef);
		
		U.ajax({
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,

			// Inside processTransaction() success callback:
			success: r => {
				if(r.success) {
					const wasEditMode = this.currentEditRef !== null;
					
					Swal.fire({
						icon: 'success',
						title: wasEditMode ? 'Transaksi Diupdate!' : 'Checkout Berhasil!',
						html: `<p>Ref: <code>${r.data.ref_id}</code></p>`,
						timer: 2000,
						showConfirmButton: false
					});

					// Memanggil fungsi reset otomatis untuk membersihkan semua panel
					this.fullReset(); 
					
					// Refresh tabel mutasi dan stok di bagian bawah
					this.loadStockAndHistory(); 
				} else {
					Swal.fire('Failed', r.data, 'error');
				}
			}
		});
	}
}

// Global Export agar bisa diakses jika ada script luar (Optional)
window.Cockpit = new CockpitPOS();

});

// activate font Lucide ------------------
jQuery(document).ready(function($) { if (typeof lucide !== 'undefined') { lucide.createIcons(); } });

</script>



        <?php
    }

    // =========================================================================
    // BACKEND HELPERS & AJAX HANDLERS
    // =========================================================================

    /**
     * Get Items for Dropdown
     * Fetches all published items with stock info
     */
/**
     * Get Items for Dropdown
     * Fetches all published items with stock info based on POS Default Location
     */
    private function get_items_for_dropdown() {
        global $wpdb;
        
        // AMBIL DARI OPTION (Anti-Hardcode)
        $default_loc = get_option('puri_pos_default_location', 'laci_kasir');

        $posts = get_posts([
            'post_type' => 'pr_item',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $results = [];
        $tbl_items = puri_table_name('T_ITEMS');
        $tbl_stock = puri_table_name('T_STOCK');
        $tbl_locks = puri_table_name('T_LOCKS');

        foreach ($posts as $p) {
            $img = get_the_post_thumbnail_url($p->ID, 'thumbnail');
            $sku = get_post_meta($p->ID, 'item_sku_code', true) ?: get_post_meta($p->ID, '_puri_item_sku', true);
            $denom = get_post_meta($p->ID, '_puri_denom', true) ?: get_post_meta($p->ID, 'denom', true) ?: 0;
            $rate = get_post_meta($p->ID, '_puri_sell_rate', true) ?: 0;
            $stock = 0;

            if ($sku && $tbl_items) {
                // UPDATE QUERY: Menggunakan %s untuk location_id
                $query = "SELECT (COALESCE(s.balance, 0) - COALESCE(l.qty_lock, 0)) as ready_stock, 
                                 i.denom_value, i.sell_rate 
                          FROM {$tbl_items} i 
                          LEFT JOIN {$tbl_stock} s ON i.id = s.item_id AND s.location_id = %s 
                          LEFT JOIN {$tbl_locks} l ON i.id = l.item_id 
                          WHERE i.sku = %s LIMIT 1";
                
                // Masukkan $default_loc ke prepare
                $engine_data = $wpdb->get_row($wpdb->prepare($query, $default_loc, $sku));
                
                if ($engine_data) {
                    $stock = $engine_data->ready_stock;
                    if (floatval($engine_data->denom_value) > 0) $denom = floatval($engine_data->denom_value);
                    if (floatval($engine_data->sell_rate) > 0) $rate = floatval($engine_data->sell_rate);
                }
            }

            $p->denom = $denom;
            $p->sell_rate = $rate;
            $p->stock_laci = $stock ?: 0;
            $p->img_url = $img;
            $results[] = $p;
        }

        usort($results, function($a, $b) {
            return $a->denom <=> $b->denom;
        });

        return $results;
    }
	
    /**
     * Get Customers for Dropdown
     */
    private function get_customers_for_dropdown() {
        $posts = get_posts([
            'post_type' => 'pr_customer',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $results = [];
        foreach ($posts as $p) {
            $type = get_post_meta($p->ID, '_puri_cust_type', true) ?: 'Member';
            $phone = get_post_meta($p->ID, '_puri_cust_phone', true) ?: '-';
            $nik = get_post_meta($p->ID, '_puri_cust_nik', true) ?: '-';
            $address = get_post_meta($p->ID, '_puri_cust_address', true) ?: '-';
            $citizenship = get_post_meta($p->ID, '_puri_cust_citizenship', true) ?: '-';
            $occupation = get_post_meta($p->ID, '_puri_cust_occupation', true) ?: '-';
            $purpose = get_post_meta($p->ID, '_puri_cust_purpose', true) ?: '-';
            
            $p->type = $type;
            $p->phone = $phone;
            $p->nik = $nik;
            $p->address = $address;
            $p->citizenship = $citizenship;
            $p->occupation = $occupation;
            $p->purpose= $purpose;
            $results[] = $p;
        }
        return $results;
    }

	private function reverse_physical_stock($item_id, $qty) {
		// Jika qty di pool adalah -5 (jual), maka untuk mengembalikan harus di +5
		// Jika qty di pool adalah 5 (beli), maka untuk mengembalikan harus di -5
		$reverse_qty = $qty * -1; 
		return $this->update_physical_stock($item_id, $reverse_qty, 'adjust'); 
	}


    /**
     * AJAX: Create New Customer
     */
	 
    public function ajax_create_customer() {
        check_ajax_referer('puri_pos_create_customer', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $name = sanitize_text_field($_POST['cust_name']);
        $address = sanitize_text_field($_POST['cust_address']);
        $city = sanitize_text_field($_POST['cust_city']);
        $phone = sanitize_text_field($_POST['cust_phone']);
        $id_type = sanitize_text_field($_POST['cust_id_type']);
        $id_number = sanitize_text_field($_POST['cust_id_number']);
        $ctype = sanitize_text_field($_POST['cust_type']);
        $occupation = sanitize_text_field($_POST['cust_occupation']);
        $purpose = sanitize_text_field($_POST['cust_purpose']);
		$attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0; // Diambil dari hasil upload sebelumnya

        // Create customer post
        $customer_id = wp_insert_post([
            'post_type' => 'pr_customer',
            'post_title' => $name,
            'post_status' => 'publish'
        ]);

        if (is_wp_error($customer_id)) {
            wp_send_json_error('Failed to create customer');
        }

		// A. Update field ACF
        update_field('cust_address', $cust_address, $post_id);
		update_field('cust_city', $cust_city, $post_id);
		update_field('cust_id_type', $cust_id_type, $post_id);
        update_field('cust_nik', $cust_nik, $post_id);
        update_field('cust_phone', $cust_phone, $post_id);
        update_field('cust_ktp_image', $attachment_id, $post_id); // SEKARANG TERSEDIA
        update_field('cust_type', $ctype, $post_id);
        update_field('cust_occupation', $occupation, $post_id);
        update_field('cust_citizenship', $citizenship, $post_id);
        update_field('cust_purpose', $purpose, $post_id);
		
        // B. Save meta
        update_post_meta($customer_id, '_puri_cust_address', $address );
        update_post_meta($customer_id, '_puri_cust_city', $city);
        update_post_meta($customer_id, '_puri_cust_phone', $phone);
        update_post_meta($customer_id, '_puri_cust_id_type', $id_type);
        update_post_meta($customer_id, '_puri_cust_nik', $id_number);
        update_post_meta($customer_id, '_puri_cust_type', $ctype);
        update_post_meta($customer_id, '_puri_cust_occupation', $occupation);
        update_post_meta($customer_id, '_puri_cust_purpose', $purpose);
		update_post_meta($customer_id, '_puri_cust_ktp_id', $attachment_id);

		// C. TRIGGER LOGIKA HARMONY mc-19
        if (function_exists('puri_partnership_harmony_logic')) {
            puri_partnership_harmony_logic($customer_id);
        }

        // Handle image upload
        if (!empty($_FILES['cust_id_image']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            
            $attachment_id = media_handle_upload('cust_id_image', $customer_id);
            if (!is_wp_error($attachment_id)) {
                update_post_meta($customer_id, '_puri_cust_id_image', wp_get_attachment_url($attachment_id));
            }
        }

        wp_send_json_success([
            'id' => $customer_id,
            'name' => $name,
            'nik' => $id_number,
            'phone' => $phone,
            'address' => $address . ', ' . $city,
			'citizenship' => $citizenship
        ]);
    }

/**
 * AJAX: Process Checkout (Pool Transaction Mode)
 * Version: 6.10.25 - Pool Transaction Phase 1
 * 
 * Flow:
 * 1. Validate cart & customer
 * 2. INSERT into T_POOL_TRANSACTIONS (staging)
 * 3. INSERT into T_POOL_STOCK (shadow stock)
 * 4. UPDATE T_STOCK (real inventory - for validation)
 * 5. ❌ SKIP: T_JOURNAL & T_LEDGER (will be posted at EOD)
 */
 
public function ajax_process_checkout() {
    check_ajax_referer('puri_pos_checkout', 'nonce');
    global $wpdb;

    // ========================================================================
    // 1. COLLECT & VALIDATE INPUT
    // ========================================================================
    $customer_id = intval($_POST['cust_id'] ?? $_POST['customer_id'] ?? 0);
    $items_raw = json_decode(stripslashes($_POST['cart'] ?? $_POST['items'] ?? '[]'), true);
    $trade_mode = sanitize_text_field($_POST['trade_mode'] ?? 'sell');
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'cash');
	$delivery_method = sanitize_text_field($_POST['delivery_method'] ?? 'pickup');
    $location_id = sanitize_text_field($_POST['location_id'] ?? get_option('puri_pos_default_location', 'laci_kasir'));
	
// 🔧 PATCH: Detect EDIT mode
$old_ref_id = sanitize_text_field($_POST['old_ref_id'] ?? '');
$is_edit_mode = !empty($old_ref_id);

if ($is_edit_mode) {
    error_log("✏️ EDIT MODE DETECTED: Replacing {$old_ref_id}");
    
    // ✅ Validate old transaction exists and is void
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    $old_status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$tbl_pool} WHERE ref_id = %s",
        $old_ref_id
    ));
    
    if ($old_status !== 'void') {
        error_log("❌ EDIT ERROR: Old transaction not voided - status: {$old_status}");
        wp_send_json_error(['message' => 'Transaksi lama belum dibatalkan. Refresh page dan coba lagi.']);
    }
}

	
	

    if ($customer_id <= 0) {
        wp_send_json_error(['message' => 'Pilih customer terlebih dahulu']);
    }

    if (empty($items_raw)) {
        wp_send_json_error(['message' => 'Keranjang masih kosong']);
    }

    // ========================================================================
    // 2. GET CUSTOMER INFO
    // ========================================================================
    $customer = get_post($customer_id);
    $customer_name = $customer ? $customer->post_title : 'Unknown Customer';
    $customer_nik = get_post_meta($customer_id, '_puri_cust_nik', true) ?: '-';

    // ========================================================================
    // 3. GENERATE REFERENCE ID
    // ========================================================================
    $ref_id = 'POS-' . current_time('Ymd') . '-' . strtoupper(wp_generate_password(4, false));

    // ========================================================================
    // 4. CALCULATE TOTALS
    // ========================================================================
    $total_riyal = 0;
    $total_idr = 0;
    $total_hpp = 0;

    $tbl_items = puri_table_name('T_ITEMS');
    $tbl_stock = puri_table_name('T_STOCK');

    foreach ($items_raw as $it) {
        $qty = floatval($it['qty'] ?? 0);
        $denom = floatval($it['denom'] ?? 0);
        $rate = floatval($it['rate'] ?? 0);

        $riyal = $qty * $denom;
        $idr = $riyal * $rate;

        $total_riyal += $riyal;
        $total_idr += $idr;

        // Get cost_avg untuk HPP (hanya untuk SELL)
        if ($trade_mode === 'sell') {
            $item_id = intval($it['item_id'] ?? $it['id'] ?? 0);
            $cost_avg = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(cost_avg, 0) FROM {$tbl_stock} 
                 WHERE item_id = %d AND location_id = %s",
                $item_id, $location_id
            ));
            $total_hpp += $riyal * floatval($cost_avg);
        }
    }

    // ========================================================================
    // 5. INSERT INTO T_POOL_TRANSACTIONS (MAIN TABLE)
    // ========================================================================
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    $insert_result = $wpdb->insert($tbl_pool, [
        'ref_id' => $ref_id,
        'trx_date' => current_time('mysql'),
        'trade_mode' => $trade_mode,
        'customer_id' => $customer_id,
        'customer_name' => $customer_name,
        'customer_nik' => $customer_nik,
        'payment_method' => $payment_method,
        'delivery_method' => $delivery_method,
        'total_riyal' => $total_riyal,
        'total_idr' => $total_idr,
        'total_hpp' => $total_hpp,
        'items_snapshot' => json_encode($items_raw), // ✅ Simpan cart asli
        'status' => 'pending',
        'created_by' => get_current_user_id(),
        'created_at' => current_time('mysql')
    ]);

    if (!$insert_result) {
        error_log('❌ Pool Insert Error: ' . $wpdb->last_error);
        wp_send_json_error(['message' => 'Gagal menyimpan ke pool: ' . $wpdb->last_error]);
    }

    // ========================================================================
    // 6. INSERT INTO T_POOL_STOCK (SHADOW STOCK)
    // ========================================================================
    $tbl_pool_stock = puri_table_name('T_POOL_STOCK');
    
    foreach ($items_raw as $it) {
        $item_id = intval($it['item_id'] ?? $it['id'] ?? 0);
        $qty = floatval($it['qty'] ?? 0);
        $denom = floatval($it['denom'] ?? 0);

        if ($item_id <= 0 || $qty <= 0) continue;

        // ✅ Get wp_post_id
        $wp_post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wp_post_id FROM {$tbl_items} WHERE id = %d",
            $item_id
        ));

        // Direction: SELL = minus, BUY = plus
        $qty_change = ($trade_mode === 'sell') ? -$qty : $qty;

        $wpdb->insert($tbl_pool_stock, [
            'ref_id' => $ref_id,
            'item_id' => $item_id,
            'wp_post_id' => $wp_post_id ?: 0,
            'location_id' => $location_id,
            'qty_change' => $qty_change,
            'created_at' => current_time('mysql')
        ]);
    }

// ========================================================================
// 7. UPDATE T_STOCK (REAL INVENTORY)
// ========================================================================
// ✅ CRITICAL: ALWAYS update stock, regardless of edit mode
// Why? Because:
// - silentVoid() already reversed the old transaction stock
// - This new checkout is treated as a fresh transaction
// - Stock movements must always be recorded for audit trail

error_log("📦 PROCESSING STOCK UPDATE" . ($is_edit_mode ? " [EDIT MODE]" : " [NEW TRANSACTION]"));

foreach ($items_raw as $it) {
    $item_id = intval($it['item_id'] ?? $it['id'] ?? 0);
    $qty = floatval($it['qty'] ?? 0);
    
    if ($item_id <= 0 || $qty <= 0) {
        error_log("⚠️ Skipping invalid item: ID={$item_id}, Qty={$qty}");
        continue;
    }
    
    // Get wp_post_id for cross-reference
    $wp_post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT wp_post_id FROM {$tbl_items} WHERE id = %d",
        $item_id
    ));
    
    // Stock direction: SELL = minus, BUY = plus
    $qty_change = ($trade_mode === 'sell') ? -$qty : $qty;
    
    // Check if stock record exists
    $stock_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$tbl_stock} WHERE item_id = %d AND location_id = %s",
        $item_id, $location_id
    ));
    
    if ($stock_id) {
        // UPDATE existing record
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$tbl_stock} 
             SET balance = balance + %f, 
                 updated_at = %s,
                 last_ref = %s
             WHERE item_id = %d AND location_id = %s",
            $qty_change, 
            current_time('mysql'),
            $ref_id,
            $item_id, 
            $location_id
        ));
        
        if ($result === false) {
            throw new Exception("Stock update error for item {$item_id}: " . $wpdb->last_error);
        }
        
        // Get new balance for logging
        $new_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM {$tbl_stock} WHERE item_id = %d AND location_id = %s",
            $item_id, $location_id
        ));
        
        error_log("✅ Stock updated: Item #{$item_id} @ {$location_id} | Change: {$qty_change} | New Balance: {$new_balance}");
        
    } else {
        // INSERT new record (first occurrence)
        $result = $wpdb->insert($tbl_stock, [
            'item_id' => $item_id,
            'wp_post_id' => $wp_post_id ?: 0,
            'location_id' => $location_id,
            'balance' => $qty_change,
            'updated_at' => current_time('mysql'),
            'last_ref' => $ref_id
        ]);
        
        if ($result === false) {
            throw new Exception("Stock insert error for item {$item_id}: " . $wpdb->last_error);
        }
        
        error_log("✅ Stock created: Item #{$item_id} @ {$location_id} | Initial Balance: {$qty_change}");
    }
}

// ========================================================================
// 7b. EDIT MODE CONTEXT LOGGING
// ========================================================================
if ($is_edit_mode) {
    error_log("╔══════════════════════════════════════════════════════════");
    error_log("║ EDIT MODE TRANSACTION SUMMARY");
    error_log("╠══════════════════════════════════════════════════════════");
    error_log("║ Old Ref ID: {$old_ref_id} → Status: VOIDED");
    error_log("║ New Ref ID: {$ref_id} → Status: PENDING");
    error_log("║ Stock Flow:");
    error_log("║   1. silentVoid() reversed old transaction stock");
    error_log("║   2. New checkout applied fresh stock movements");
    error_log("║   3. Net result: Stock reflects NEW transaction quantities");
    error_log("╚══════════════════════════════════════════════════════════");
}



    // ========================================================================
    // 8. SUCCESS RESPONSE
    // ========================================================================
    wp_send_json_success([
        'ref_id' => $ref_id,
        'status' => 'pending',
        'message' => 'Transaksi tersimpan di pool, menunggu EOD posting',
        'total_riyal' => $total_riyal,
        'total_idr' => $total_idr,
        'items_count' => count($items_raw)
    ]);
}


/**
 * AJAX: Get Daily Mutation (Pool Transaction Mode)
 * Version: 6.10.25 - Pool Phase 1
 * 
 * Changes:
 * - Read from T_POOL_TRANSACTIONS instead of T_JOURNAL
 * - Show status indicator (pending/verified/posted)
 * - No need complex JSON parsing (data sudah clean)
 * 
 * @return JSON Array of today's transactions with summary
 */
public function ajax_get_daily_mutation() {
    global $wpdb;
    
    // =========================================================================
    // 1. GET TODAY'S POOL TRANSACTIONS
    // =========================================================================
    $today_str = current_time('Y-m-d');
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            ref_id,
            trx_date,
            trade_mode,
            customer_name,
            total_riyal,
            total_idr,
            status,
            payment_method,
            delivery_method
         FROM {$tbl_pool}
         WHERE DATE(trx_date) = %s
         ORDER BY trx_date DESC",
        $today_str
    ));

    // =========================================================================
    // 2. PROCESS DATA
    // =========================================================================
    $data = [];
    $sum_riyal = 0;
    $sum_idr = 0;

    if ($rows) {
        foreach ($rows as $r) {
            // Determine direction multiplier (SELL = +, BUY = -)
            $multiplier = ($r->trade_mode === 'sell') ? 1 : -1;
            
            $riyal_amount = floatval($r->total_riyal) * $multiplier;
            $idr_amount = floatval($r->total_idr) * $multiplier;
            
            // Accumulate totals
            $sum_riyal += $riyal_amount;
            $sum_idr += $idr_amount;
            
            // Status badge HTML
            $status_badge = '';
            switch ($r->status) {
                case 'pending':
                    $status_badge = '<span class="badge badge-warning" title="Pending EOD">⏳</span>';
                    break;
                case 'verified':
                    $status_badge = '<span class="badge badge-info" title="Verified, not posted">✓</span>';
                    break;
                case 'posted':
                    $status_badge = '<span class="badge badge-success" title="Posted to GL">✅</span>';
                    break;
                case 'void':
                    $status_badge = '<span class="badge badge-danger" title="Voided">❌</span>';
                    break;
            }
            
            // Format ref_id with status
            $ref_display = $r->ref_id . ' ' . $status_badge;
            
            // Build row data
            $data[] = [
                'time' => date('H:i', strtotime($r->trx_date)),
                'ref_id' => $ref_display,
                'customer' => esc_html($r->customer_name),
                'total_riyal' => number_format($riyal_amount, 0, ',', '.'),
                'total_idr' => number_format($idr_amount, 0, ',', '.'),
                'status' => $r->status,
                'payment' => $r->payment_method,
                'delivery' => $r->delivery_method
            ];
        }
    }

    // =========================================================================
    // 3. RETURN WITH SUMMARY
    // =========================================================================
    wp_send_json_success([
        'transactions' => $data,
        'summary' => [
            'total_riyal' => $sum_riyal,
            'total_idr' => $sum_idr,
            'count' => count($data)
        ]
    ]);
}


/**
     * AJAX: Get Stock Summary
     * Back-calculation logic for opening balance based on Dynamic Location
     */
    public function ajax_get_stock_summary() {
        global $wpdb;
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
        
        // AMBIL DARI OPTION (Bisa juga dikirim via POST jika ingin multi-lokasi di masa depan)
        $target_loc = sanitize_text_field($_POST['location_id'] ?? get_option('puri_pos_default_location', 'laci_kasir'));

        // Ledger data: separate IN and OUT
        // UPDATE QUERY: location_id = %s
        $ledger_data = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id, 
                    SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) as qty_in,
                    SUM(CASE WHEN qty_change < 0 THEN ABS(qty_change) ELSE 0 END) as qty_out
             FROM " . puri_table_name('T_LEDGER') . " 
             WHERE location_id = %s 
             AND trx_date >= %s AND trx_date <= %s 
             GROUP BY item_id",
            $target_loc, $start_date, $end_date
        ));

        // Map for quick access
        $map_mutasi = [];
        foreach ($ledger_data as $l) {
            $map_mutasi[$l->item_id] = [
                'in' => floatval($l->qty_in),
                'out' => floatval($l->qty_out)
            ];
        }

        // Get items with current stock
        // UPDATE QUERY: location_id = %s
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT i.id, i.name, i.sku, i.type, i.denom_value, 
                   s.balance as stock_phys, l.qty_lock 
            FROM " . puri_table_name('T_ITEMS') . " i 
            LEFT JOIN " . puri_table_name('T_STOCK') . " s ON i.id = s.item_id AND s.location_id = %s 
            LEFT JOIN " . puri_table_name('T_LOCKS') . " l ON i.id = l.item_id 
            WHERE i.type IN ('currency', 'package')
        ", $target_loc));

        $final_data = [];
        foreach ($items as $it) {
            $denom = (float) $it->denom_value;

            // Ending balance (from current database)
            $qty_end = ($it->type == 'package') ? floatval($it->qty_lock) : floatval($it->stock_phys);

            // Mutation from ledger
            $qty_in = isset($map_mutasi[$it->id]) ? $map_mutasi[$it->id]['in'] : 0;
            $qty_out = isset($map_mutasi[$it->id]) ? $map_mutasi[$it->id]['out'] : 0;

            // Back calculation: Opening = Closing - In + Out
            $qty_start = $qty_end - $qty_in + $qty_out;

            // Valuation (Riyal)
            $sar_start = $qty_start * $denom;
            $sar_in = $qty_in * $denom;
            $sar_out = $qty_out * $denom;
            $sar_end = $qty_end * $denom;

            $final_data[] = [
                'name' => $it->name,
                'sku' => $it->sku,
                'denom' => $denom,
                'qty_start' => $qty_start,
                'qty_out' => $qty_out,
                'qty_in' => $qty_in,
                'qty_end' => $qty_end,
                'sar_out' => $sar_out,
                'sar_in' => $sar_in,
                'sar_end' => $sar_end
            ];
        }

        wp_send_json_success($final_data);
    }
	
	
} // End Class


// ============================================================================
// INITIALIZATION
// ============================================================================

// Initialize class once
$puri_cockpit_pos = new Puri_Cockpit_POS();

// Wrapper function for MC-00 compatibility
if (!function_exists('allnew_pos_render_page')) {
    /**
     * Global wrapper function for menu rendering
     * Called by MC-00 master controller
     */
    function allnew_pos_render_page() {
        global $puri_cockpit_pos;
		$locations = get_option('puri_inv_locations', []);
		$default_loc = get_option('puri_pos_default_location', 'laci_kasir');
        if ($puri_cockpit_pos instanceof Puri_Cockpit_POS) {
            $puri_cockpit_pos->allnew_pos_render_page();
        } else {
            echo '<div class="wrap"><h1>Error</h1><p>Puri_Cockpit_POS class not initialized.</p></div>';
        }
    }
}

/**
 * ============================================================================
 * END OF FILE
 * ============================================================================
 * 
 * Version History:
 * - v6.10.25 (2026-01-14): UX Restructured, Customer KYC Modal, Payment/Delivery
 * - v6.8.9 (2025-12-XX): JSON Snapshot fix, Recursive decode, Moving average
 * - v6.8.0 (2025-11-XX): Initial SPOT implementation
 * 
 * Next Phase (v7.0.0 Planned):
 * - Pool transaction workflow
 * - End-of-day batch posting
 * - Cart CRUD with edit capability
 * - Reconciliation dashboard
 * 
 * Dependencies:
 * - WordPress 5.8+
 * - Custom Post Types: pr_item, pr_customer
 * - Custom Tables: T_ITEMS, T_STOCK, T_LEDGER, T_JOURNAL, T_LOCKS
 * - Helper Functions: puri_table_name(), puri_gl(), puri_insert_journal()
 * 
 * Browser Support:
 * - Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
 * 
 * Security:
 * - Nonce verification on all AJAX endpoints
 * - Capability checks (manage_options)
 * - SQL injection prevention (prepared statements)
 * - XSS prevention (esc_html, esc_attr)
 * 
 * Performance Notes:
 * - Stock locking uses FOR UPDATE to prevent race conditions
 * - Database transactions ensure ACID compliance
 * - Select2 library for enhanced dropdown performance
 * 
 * Known Issues:
 * - Concurrent checkouts may cause stock discrepancies (to be addressed in v7.0)
 * - Large cart operations (50+ items) may slow down on low-end servers
 * 
 * Support:
 * - Documentation: Internal wiki
 * - Issues: Project management system
 * 
 * ============================================================================
 */