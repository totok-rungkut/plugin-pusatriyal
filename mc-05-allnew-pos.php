<?php
/**
 * ============================================================================
 * MC-05 - COCKPIT EXTENDED POINT OF SALE SYSTEM
 * ============================================================================
 * 
 * @package     Puri_Money_Changer
 * @subpackage  Cockpit_POS
 * @version     6.10.32 (UX Restructured - Pool Transaction Phase 1)
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

class Puri_Cockpit_POS {

    public function __construct() {
        // Menu registration handled by MC-00 master controller
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX Endpoints - Core Operations
        add_action('wp_ajax_puri_pos_get_stock_summary', [$this, 'ajax_get_stock_summary']);
        add_action('wp_ajax_puri_pos_get_daily_mutation', [$this, 'ajax_get_daily_mutation']);
        add_action('wp_ajax_puri_pos_checkout', [$this, 'ajax_process_checkout']);
        add_action('wp_ajax_puri_pos_get_pool_snapshot', [$this, 'get_pool_snapshot']);
		add_action('wp_ajax_puri_pos_get_pool_history', [$this, 'get_pool_history']);
		add_action('wp_ajax_puri_pos_void_pool_transaction', [$this, 'void_pool_transaction']);
		
        // AJAX Endpoints - Customer Management (NEW v6.10.25)
        add_action('wp_ajax_puri_pos_create_customer', [$this, 'ajax_create_customer']);
		
		// ubah nama file menjadi format : KTP_nama-anda_123456.png
		add_action('wp_ajax_puri_pos_upload_customer_id', [$this, 'ajax_upload_customer_id']);
    }


public function get_pool_snapshot() {
    global $wpdb;
    check_ajax_referer('puri_pos_checkout', 'nonce'); // Re-use nonce checkout

    $ref_id = sanitize_text_field($_POST['ref_id']);
    $table  = puri_table_name('T_POOL_TRANSACTIONS');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE ref_id = %s AND status = 'pending'",
        $ref_id
    ));

    if (!$row) {
        wp_send_json_error('Data tidak ditemukan atau sudah diposting.');
    }

    // Ambil data customer dari ID yang tersimpan
    $cust_id = $row->customer_id;
    // (Optional: Join dengan master customer jika perlu detail lebih)

    wp_send_json_success([
        'trade_mode' => $row->trade_mode,
        'cust_id'    => $row->customer_id,
        'cart'       => json_decode($row->cart_snapshot, true), // Mengembalikan state cart utuh
        'pay_method' => $row->payment_method
    ]);
}

public function void_pool_transaction() {
        global $wpdb;
        check_ajax_referer('puri_pos_checkout', 'nonce');

        $ref_id = sanitize_text_field($_POST['ref_id']);
        
        $wpdb->query('START TRANSACTION');
        
        // 1. Update status di T_POOL_TRANSACTIONS menjadi void
        $wpdb->update(puri_table_name('T_POOL_TRANSACTIONS'), 
            ['status' => 'void'], 
            ['ref_id' => $ref_id]
        );

        // 2. KEMBALIKAN STOK FISIK (Sangat Penting!)
        // Cari semua item di T_POOL_STOCK terkait ref_id ini
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id, qty FROM ".puri_table_name('T_POOL_STOCK')." WHERE ref_id = %s",
            $ref_id
        ));

        foreach ($items as $item) {
            // Logika kebalikan: jika dulu jual (minus), sekarang tambah (plus)
            // Ini memanggil fungsi sync stok internal Anda
            $this->reverse_physical_stock($item->item_id, $item->qty);
        }

        $wpdb->query('COMMIT');
        wp_send_json_success('Transaction voided and stock restored.');
    }
	
public function get_pool_history() {
    global $wpdb;
    $table_trans = puri_table_name('T_POOL_TRANSACTIONS');
    $table_cust  = $wpdb->prefix . "posts"; // Jika customer disimpan di WP Posts

    // Ambil data pending hari ini
    $results = $wpdb->get_results("
        SELECT p.*, DATE_FORMAT(p.created_at, '%H:%i') as time 
        FROM $table_trans p
        WHERE p.status = 'pending' 
        ORDER BY p.created_at DESC 
        LIMIT 20
    ");

    $data = [];
    foreach ($results as $r) {
        $data[] = [
            'ref_id'       => $r->ref_id,
            'time'         => $r->time,
            'trade_mode'   => $r->trade_mode,
            'customer_name'=> $this->get_customer_name($r->customer_id), // Helper function Anda
            'total_amount' => $r->total_amount,
            'status'       => $r->status
        ];
    }

    wp_send_json_success($data);
    wp_die();
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
        $items = $this->get_items_for_dropdown(); 
        $customers = $this->get_customers_for_dropdown();
        $is_finance_or_admin = current_user_can('can_entry');
			
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
                                                        data-address="<?php echo esc_attr($c->address); ?>">
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
                                <div class="form-group mb-2">
                                    <label class="small-label">Select Item (SKU) *</label>
                                    <select id="item_select" class="puri-input">
                                        <option value="" data-denom="0" data-rate="0">-- Select Item --</option>
                                        <?php foreach($items as $it): ?>
                                            <option value="<?php echo $it->ID; ?>" 
                                                    data-denom="<?php echo esc_attr($it->denom); ?>"
                                                    data-rate="<?php echo esc_attr($it->sell_rate); ?>"
                                                    data-stock="<?php echo esc_attr($it->stock_laci); ?>"
                                                    data-img="<?php echo esc_attr($it->img_url); ?>">
                                                <?php echo esc_html($it->post_title); ?> (Stock: <?php echo $it->stock_laci; ?>)
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

    this.$cust
      .on('select2:select', () => this.selectCustomer())
      .on('select2:clear',  () => this.clearCustomer());

    this.$item.on('select2:select', () => this.selectItem());

    this.$qty.on('input',   () => this.syncFromQty());
    this.$riyal.on('input', () => this.syncFromRiyal());
    this.$rate.on('input',  () => this.calcFinalIDR());

    this.$btnAdd.on('click', () => this.addToCart());
    $('#btn_checkout').on('click', () => this.handleCheckout());

    // Event delegation untuk tombol hapus di cart
    $(document).on('click','.btn-remove-item', e => 
      this.removeItem($(e.currentTarget).data('index'))
    );

    $('#btn_refresh_history').on('click', () => this.loadStockAndHistory());
  }

  /* ---------------- CUSTOMER LOGIC ---------------- */
  selectCustomer() {
    const o = this.$cust.find(':selected');
    this.currentCustomer = {
      id: o.val(),
      name: o.text(),
      type: o.data('type') || 'Member'
    };
    $('#customer_info_box').removeClass('hidden');
    this.checkRateEditable(this.currentCustomer.type);
  }

  clearCustomer() {
    this.currentCustomer = null;
    $('#customer_info_box').addClass('hidden');
    this.checkRateEditable('');
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

    if (!this.cart.length) {
      return $tb.html(`<tr><td colspan="5" align="center">Cart is empty</td></tr>`);
    }

    this.cart.forEach((x, n) => {
      totalV += x.total_valas; totalI += x.subtotal_idr;
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

    $('#cart_total_riyal').text(U.fmt(totalV));
    $('#cart_total_idr').text('Rp ' + U.idr(totalI));
  }

/* =====================================================
   * RENDER HISTORY (POOL MONITOR)
   * ===================================================== */
  renderHistoryTable() {
    const $tb = $('#history_table tbody').empty();
    
    if (!this.historyData || this.historyData.length === 0) {
      $tb.html('<tr><td colspan="5" align="center" style="padding:20px;">Belum ada transaksi di pool hari ini.</td></tr>');
      return;
    }

    this.historyData.forEach((h, index) => {
      // Styling berdasarkan mode
      const modeColor = h.trade_mode === 'sell' ? '#2271b1' : '#d63638';
      const modeLabel = h.trade_mode === 'sell' ? 'JUAL' : 'BELI';
      
      $tb.append(`
        <tr class="pool-row">
          <td class="tc"><strong>${h.time}</strong></td>
          <td>
            <span class="badge-mode" style="background:${modeColor}; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px;">
              ${modeLabel}
            </span>
            <code style="font-weight:bold; margin-left:5px;">${h.ref_id}</code>
          </td>
          <td>${h.customer_name || 'Walking Customer'}</td>
          <td class="tr" style="font-family:monospace; font-weight:bold;">
            ${U.idr(h.total_amount)}
          </td>
          <td class="tc">
            <div class="btn-group-history">
              <button onclick="window.Cockpit.editFromPool('${h.ref_id}')" class="button button-small" title="Edit Transaksi">
                <i class="fa fa-pencil-alt" style="color:#2271b1"></i>
              </button>

              <button onclick="window.Cockpit.confirmVoid('${h.ref_id}')" class="button button-small" title="Hapus Permanen">
                <i class="fa fa-trash" style="color:#d63638"></i>
              </button>
            </div>
          </td>
        </tr>
      `);
    });
  }

  // Helper untuk konfirmasi penghapusan (Void)
  confirmVoid(refId) {
    Swal.fire({
      title: 'Hapus Transaksi?',
      text: "Data akan dihapus dari Pool dan stok fisik dikembalikan.",
      icon: 'error',
      showCancelButton: true,
      confirmButtonColor: '#d63638',
      confirmButtonText: 'Ya, Hapus!'
    }).then((result) => {
      if (result.isConfirmed) {
        this.silentVoid(refId); // Menggunakan fungsi yang kita buat di Part 3.1
        Swal.fire('Deleted', 'Transaksi berhasil dihapus.', 'success');
        this.loadStockAndHistory(); // Refresh tabel
      }
    });
  }


/* =====================================================
 * EDIT FROM POOL LOGIC  - CRUD SCHEME
 * ===================================================== */
editFromPool(refId) {
    Swal.fire({
        title: 'Edit Transaksi?',
        text: "Data akan dikembalikan ke keranjang untuk diperbaiki.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2271b1',
        confirmButtonText: 'Ya, Bongkar Keranjang'
    }).then((result) => {
        if (result.isConfirmed) {
            U.ajax({
                data: { 
                    action: 'puri_pos_get_pool_snapshot', 
                    ref_id: refId,
                    nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
                },
                success: (r) => {
                    if (r.success) {
                        // 1. Restore State
                        this.cart = r.data.cart;
                        this.tradeMode = r.data.trade_mode;
                        
                        // 2. Update UI Radio Button Mode
                        $(`input[name="trade_mode"][value="${this.tradeMode}"]`).prop('checked', true);
                        
                        // 3. Update Customer (Trigger Select2)
                        this.$cust.val(r.data.cust_id).trigger('change');
                        
                        // 4. Update Payment Method
                        this.$payMethod.val(r.data.pay_method);

                        // 5. Render & Lock
                        this.renderCart();
                        this.applyModeGuard();
                        this.lockSession(); // Kunci mode agar tidak berubah saat edit

                        // 6. Jalankan Fungsi Hapus/Void pada data lama di Pool
                        // Agar tidak double saat di-checkout ulang nanti
                        this.silentVoid(refId);

                        Swal.fire('Restored', 'Silakan lakukan perbaikan.', 'success');
                    } else {
                        Swal.fire('Error', r.data, 'error');
                    }
                }
            });
        }
    });
}

silentVoid(refId) {
    U.ajax({
        data: { 
            action: 'puri_pos_void_pool', 
            ref_id: refId,
            mode: 'silent' // Tanpa alert karena ini proses edit
        }
    });
}



  /* ---------------- SESSION & DATA ---------------- */
  lockSession() { $('input[name="trade_mode"]').prop('disabled', true); }
  unlockSession() { $('input[name="trade_mode"]').prop('disabled', false); }

  applyModeGuard() {
    this.$panel.removeClass('mode-buy mode-sell').addClass(`mode-${this.tradeMode}`);
  }

  loadStockAndHistory() {
    U.ajax({
      data:{ action:'puri_pos_get_stock_summary' },
      success: r => { if(r.success) { this.stockData = r.data; this.renderStockTable(); } }
    });
	U.ajax({
        data: { action: 'puri_pos_get_pool_history' },
        success: r => {
            if(r.success) {
                this.historyData = r.data;
                this.renderHistoryTable(); // Memanggil fungsi yang baru kita buat
            }
        }
    });
  }

  renderStockTable() {
    // Logic sorting (Tetap sama)
    const html = this.stockData.map(s => `
      <tr>
        <td><strong>${s.name}</strong></td>
        <td class="tr">${U.fmt(s.qty_end)}</td>
        <td class="tr">${U.fmt(s.sar_end)}</td>
      </tr>`).join('');
    $('#stock_table tbody').html(html);
  }

  /* ---------------- CHECKOUT (Sinkron Mozart) ---------------- */
  handleCheckout() {
    if (!this.cart.length) return Swal.fire('Empty','Cart is empty','warning');
    if (!this.currentCustomer) return Swal.fire('Customer','Select customer','error');

    Swal.fire({
      title: 'Confirm Transaction?',
      text: `Total: ${$('#cart_total_idr').text()}`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Process'
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

    U.ajax({
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: r => {
        if(r.success) {
          Swal.fire('Success', 'Ref: ' + r.data.ref_id, 'success');
          this.cart = [];
          this.unlockSession();
          this.renderCart();
          this.loadStockAndHistory(); // Refresh stok real-time
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
    private function get_items_for_dropdown() {
        global $wpdb;
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
                $query = "SELECT (COALESCE(s.qty, 0) - COALESCE(l.qty_lock, 0)) as ready_stock, 
                                 i.denom_value, i.sell_rate 
                          FROM {$tbl_items} i 
                          LEFT JOIN {$tbl_stock} s ON i.id = s.item_id AND s.location_id = 'laci_kasir' 
                          LEFT JOIN {$tbl_locks} l ON i.id = l.item_id 
                          WHERE i.sku = %s LIMIT 1";
                $engine_data = $wpdb->get_row($wpdb->prepare($query, $sku));
                
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
            
            $p->type = $type;
            $p->phone = $phone;
            $p->nik = $nik;
            $p->address = $address;
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
		update_field('cust_nik', $cust_nik, $post_id);
        update_field('cust_phone', $cust_phone, $post_id);
        update_field('cust_ktp_image', $attachment_id, $post_id); // SEKARANG TERSEDIA
        update_field('cust_type', $ctype, $post_id);
		
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
            'address' => $address . ', ' . $city
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
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    
    // =========================================================================
    // 1. EXTRACT REQUEST DATA
    // =========================================================================
    $trade_mode = sanitize_text_field($_POST['trade_mode'] ?? 'sell');
    $trade_mode = in_array($trade_mode, ['sell', 'buy']) ? $trade_mode : 'sell';
    
    $cart = json_decode(stripslashes($_POST['cart']), true);
    $cust_mode = sanitize_text_field($_POST['cust_mode']);
    $customer_id = intval($_POST['cust_id']);
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'cash');
    $delivery_method = sanitize_text_field($_POST['delivery_method'] ?? 'pickup');

    if (empty($cart)) {
        wp_send_json_error('Cart is empty');
    }

    // =========================================================================
    // 2. TRANSACTION SETUP
    // =========================================================================
    $wpdb->query('START TRANSACTION');
    
    try {
        // Customer data
        $customer_name = get_the_title($customer_id);
        $customer_nik = get_post_meta($customer_id, '_puri_cust_nik', true) ?: '-';
        
        // Generate ref_id
        $ref_id = ($trade_mode == 'buy' ? 'BUY-' : 'POS-') . date('YmdHis') . '-' . rand(100, 999);
        $trx_date = current_time('mysql');
        
        // Initialize totals
        $total_riyal = 0;
        $total_idr = 0;
        $total_hpp = 0;
        
        // Table references
        $tbl_items = puri_table_name('T_ITEMS');
        $tbl_stock = puri_table_name('T_STOCK');
        $tbl_pool_stock = puri_table_name('T_POOL_STOCK');
        $location_id = 'laci_kasir';

        // =====================================================================
        // 3. COST MAPPING (for SELL mode HPP calculation)
        // =====================================================================
        $cost_map = [];
        if ($trade_mode === 'sell') {
            $item_wp_ids = array_column($cart, 'id');
            $item_sql_ids = [];
            
            foreach ($item_wp_ids as $wp_id) {
                $sql_id = puri_get_item_sql_id($wp_id);
                if ($sql_id) $item_sql_ids[] = $sql_id;
            }

            if (!empty($item_sql_ids)) {
                $placeholders = implode(',', array_fill(0, count($item_sql_ids), '%d'));
                $query = "SELECT i.id, COALESCE(s.cost_avg, i.base_price, 0) AS cost_price
                          FROM {$tbl_items} i
                          LEFT JOIN {$tbl_stock} s ON i.id = s.item_id AND s.location_id = %s
                          WHERE i.id IN ($placeholders)";
                $params = array_merge([$location_id], $item_sql_ids);
                $rows = $wpdb->get_results($wpdb->prepare($query, ...$params));
                
                foreach ($rows as $r) {
                    $cost_map[$r->id] = floatval($r->cost_price);
                }
            }
        }

        // =====================================================================
        // 4. PROCESS CART ITEMS
        // =====================================================================
        $items_snapshot = [];
        
        foreach ($cart as $item) {
            $wp_post_id = (int) $item['id'];
            $qty = (int) $item['qty'];
            $riyal = (float) $item['riyal'];
            $idr = (float) $item['idr'];
            
            $item_sql_id = puri_get_item_sql_id($wp_post_id);
            if ($qty <= 0) continue;

            // -----------------------------------------------------------------
            // 4A. LOCK STOCK (FOR UPDATE)
            // -----------------------------------------------------------------
            $curr = $wpdb->get_var($wpdb->prepare(
                "SELECT qty FROM $tbl_stock WHERE item_id = %d AND location_id = %s FOR UPDATE",
                $item_sql_id, $location_id
            ));

            if (is_null($curr)) {
                // Create stock record if not exists
                $wpdb->insert($tbl_stock, [
                    'item_id' => $item_sql_id,
                    'location_id' => $location_id,
                    'qty' => 0,
                    'last_updated' => current_time('mysql')
                ]);
                $curr = 0;
            }

            // -----------------------------------------------------------------
            // 4B. MODE-SPECIFIC LOGIC
            // -----------------------------------------------------------------
            if ($trade_mode === 'sell') {
                // SELL: Check stock availability
                if ($curr < $qty) {
                    throw new Exception("Insufficient stock for item ID: $wp_post_id (Available: $curr, Needed: $qty)");
                }

                // Update real stock (for validation)
                $wpdb->query($wpdb->prepare(
                    "UPDATE $tbl_stock SET qty = qty - %d, last_updated = %s 
                     WHERE item_id = %d AND location_id = %s",
                    $qty, current_time('mysql'), $item_sql_id, $location_id
                ));

                // Calculate HPP
                $cost_price = $cost_map[$item_sql_id] ?? 0;
                $item_hpp = $cost_price * $qty;
                $total_hpp += $item_hpp;
                
            } else {
                // BUY: Moving average cost calculation
                $current_base_price = $wpdb->get_var($wpdb->prepare(
                    "SELECT base_price FROM $tbl_items WHERE id = %d", $item_sql_id
                ));
                $current_base_price = floatval($current_base_price);

                $old_asset_val = $curr * $current_base_price;
                $new_asset_val = $idr;
                $total_new_qty = $curr + $qty;

                $new_avg_price = 0;
                if ($total_new_qty > 0) {
                    $new_avg_price = ($old_asset_val + $new_asset_val) / $total_new_qty;
                }

                // Update base price with new average
                $wpdb->update($tbl_items, ['base_price' => $new_avg_price], ['id' => $item_sql_id]);

                // Update real stock (increase)
                $wpdb->query($wpdb->prepare(
                    "UPDATE $tbl_stock SET qty = qty + %d, last_updated = %s 
                     WHERE item_id = %d AND location_id = %s",
                    $qty, current_time('mysql'), $item_sql_id, $location_id
                ));
            }

            // -----------------------------------------------------------------
            // 4C. INSERT TO POOL STOCK (Shadow Ledger)
            // -----------------------------------------------------------------
            $wpdb->insert($tbl_pool_stock, [
                'ref_id' => $ref_id,
                'item_id' => $item_sql_id,
                'location_id' => $location_id,
                'qty_change' => ($trade_mode === 'sell') ? -$qty : +$qty,
                'created_at' => current_time('mysql')
            ]);

            // -----------------------------------------------------------------
            // 4D. BUILD SNAPSHOT
            // -----------------------------------------------------------------
            $items_snapshot[] = [
                'sku' => get_field('item_sku_code', $wp_post_id),
                'wp_post_id' => $wp_post_id,
                'sql_id' => $item_sql_id,
                'qty' => $qty,
                'riyal' => $riyal,
                'idr' => $idr,
                'hpp' => ($trade_mode === 'sell') ? ($cost_price ?? 0) : 0
            ];

            $total_riyal += $riyal;
            $total_idr += $idr;
        }

        // =====================================================================
        // 5. INSERT TO POOL TRANSACTIONS (Main Staging)
        // =====================================================================
        $tbl_pool_trx = puri_table_name('T_POOL_TRANSACTIONS');
        
        $wpdb->insert($tbl_pool_trx, [
            'ref_id' => $ref_id,
            'trx_date' => $trx_date,
            'trade_mode' => $trade_mode,
            'customer_id' => $customer_id,
            'customer_name' => $customer_name,
            'customer_nik' => $customer_nik,
            'payment_method' => $payment_method,
            'delivery_method' => $delivery_method,
            'total_riyal' => $total_riyal,
            'total_idr' => $total_idr,
            'total_hpp' => $total_hpp,
            'items_snapshot' => json_encode($items_snapshot),
            'status' => 'pending',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);

        // =====================================================================
        // 6. ❌ SKIP JOURNAL POSTING (will be done at EOD)
        // =====================================================================
        // OLD CODE (REMOVED):
        // puri_insert_journal(...);
        
        // NEW BEHAVIOR:
        // Journals will be created by EOD posting process

        // =====================================================================
        // 7. COMMIT & RESPOND
        // =====================================================================
        $wpdb->query('COMMIT');
        
        wp_send_json_success([
            'ref_id' => $ref_id,
            'status' => 'pending',
            'message' => 'Transaction staged in POOL. Will be posted at End of Day.',
            'total_idr' => number_format($total_idr, 0, ',', '.'),
            'pool_mode' => true
        ]);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
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
     * Back-calculation logic for opening balance
     */
    public function ajax_get_stock_summary() {
        global $wpdb;
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');

        // Ledger data: separate IN and OUT
        $ledger_data = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id, 
                    SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) as qty_in,
                    SUM(CASE WHEN qty_change < 0 THEN ABS(qty_change) ELSE 0 END) as qty_out
             FROM " . puri_table_name('T_LEDGER') . " 
             WHERE location_id = 'laci_kasir' 
             AND trx_date >= %s AND trx_date <= %s 
             GROUP BY item_id",
            $start_date, $end_date
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
        $items = $wpdb->get_results("
            SELECT i.id, i.name, i.sku, i.type, i.denom_value, 
                   s.qty as stock_phys, l.qty_lock 
            FROM " . puri_table_name('T_ITEMS') . " i 
            LEFT JOIN " . puri_table_name('T_STOCK') . " s ON i.id = s.item_id AND s.location_id = 'laci_kasir' 
            LEFT JOIN " . puri_table_name('T_LOCKS') . " l ON i.id = l.item_id 
            WHERE i.type IN ('currency', 'package')
        ");

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