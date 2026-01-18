<?php
/**
 * MC 25 - Cockpit Extended POS
 * Version: 6.8.9 (Fix JSON Snapshot on Cash Journal)
 * Author: Denmas Totok (Refactor by Gemini)
 *
 * Purpose:
 * - Pusat kontrol kasir (Cockpit).
 * - SINGLE POINT OF TRUTH (SPOT) RESTORED & PATCHED.
 * - Logic: Saldo Awal dihitung mundur (Akhir - Mutasi).
 * - Journal: Menggunakan mapping GL (puri_gl) yang benar.
 * * FIX LOG v6.8.9:
 * 1. JSON Snapshot sekarang disimpan DI KEDUA SISI JURNAL (Debit & Kredit).
 * Ini menjamin data 'Total Riyal' terbaca saat menarik history Kas (1101).
 * 2. Fix escaping JSON pada fungsi get_daily_mutation.
 */

defined('ABSPATH') || exit;

class Puri_Cockpit_POS {

    public function __construct() {
        // add_action('admin_menu', [$this, 'register_menu']); // Menu handled by MC-00
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX Endpoints
        add_action('wp_ajax_puri_pos_get_stock_summary', [$this, 'ajax_get_stock_summary']);
        add_action('wp_ajax_puri_pos_get_daily_mutation', [$this, 'ajax_get_daily_mutation']);
        add_action('wp_ajax_puri_pos_checkout', [$this, 'ajax_process_checkout']);
    }

    public function enqueue_assets($hook) {
        if(!wp_script_is('sweetalert2', 'enqueued')) {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        }
        if(!wp_script_is('select2', 'enqueued')) {
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
        }
        wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
    }

    // =========================================================================
    // 1. RENDER PAGE
    // =========================================================================
    public function cockpit_render_page() {
        $items = $this->get_items_for_dropdown(); 
        $customers = $this->get_customers_for_dropdown();
        $is_finance_or_admin = current_user_can('manage_options'); 
        ?>
        <div class="wrap puri-cockpit-wrapper">
            <h1 class="wp-heading-inline"><i class="fa-solid fa-gauge-high"></i> Cockpit P.O.S</h1>
            <hr class="wp-header-end">

            <audio id="fx_item_choosed" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/click.mp3'; ?>"></audio>
            <audio id="fx_stock_empty" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/error.mp3'; ?>"></audio>
            <audio id="fx_cart_clicked" src="<?php echo plugin_dir_url(__FILE__) . 'assets/sfx/cash-register.mp3'; ?>"></audio>

            <div class="cockpit-container">
                <div class="flex-row top-row">
                    <div class="flex-col col-left-input">
                        
                        <div class="panel panel-customer">
                            <div class="panel-header"><i class="fa-solid fa-user"></i> 1.1.1 Identifikasi Customer</div>
                            <div class="panel-body">
                                <div class="form-group mb-2">
                                    <div class="radio-group">
                                        <label class="radio-label"><input type="radio" name="cust_type" value="registered"> <i class="fa-solid fa-id-card"></i> Member</label>
                                        <label class="radio-label"><input type="radio" name="cust_type" value="walkin" checked> <i class="fa-solid fa-walking"></i> Walk-in</label>
                                    </div>
                                </div>
                                <div class="form-group mb-2" id="box_registered" style="display:none;">
                                    <select id="customer_select" style="width:100%">
                                        <option value="">-- Pilih Member --</option>
                                        <?php foreach($customers as $c): echo "<option value='{$c->ID}' data-type='{$c->type}' data-phone='{$c->phone}' data-nik='{$c->nik}'>".esc_html($c->post_title)."</option>"; endforeach; ?>
                                    </select>
                                    <div id="cust_badge" class="badge center-fixed hidden"></div> 
                                </div>
                                <div id="box_walkin">
                                    <div class="form-group mb-1"><input type="text" id="wic_name" class="puri-input" placeholder="Nama Lengkap*"></div>
                                    <div class="form-row mb-1">
                                        <div class="col"><input type="text" id="wic_nik" class="puri-input" placeholder="NIK/SIM*"></div>
                                        <div class="col"><input type="text" id="wic_phone" class="puri-input" placeholder="No. HP*"></div>
                                    </div>
                                    <div class="form-group mb-1"><textarea id="wic_address" class="puri-input" style="height:40px; resize:none;" placeholder="Alamat*"></textarea></div>
                                    <div class="form-group mb-1"><label class="small-label">Foto KTP*</label><input type="file" id="wic_ktp" class="puri-input" accept="image/*" style="padding-top:4px;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="panel panel-input" id="panelInputTransaksi">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-money-bill-wave"></i> 1.1.2 Input Transaksi</span>
                                
                                <div class="puri-trade-mode">
                                    <label><input type="radio" name="trade_mode" value="sell" checked> Jual SAR</label>
                                    <label><input type="radio" name="trade_mode" value="buy"> Beli SAR</label>
                                </div>
                                
                                <button type="button" class="button button-small" id="btn_clear_form" title="Reset Input"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="panel-body">
                                <div class="item-selection-row mb-2">
                                    <div class="item-image-box"><img id="item_img_preview" src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/no-image.png'; ?>"></div>
                                    <div class="item-dropdown-box">
                                        <label class="small-label">Pilih Item (Urut Denom)*</label>
                                        <select id="item_select" class="puri-input">
                                            <option value="" data-denom="0" data-rate="0">-- Pilih Item --</option>
                                            <?php foreach($items as $it): ?>
                                                <option value="<?php echo $it->ID; ?>" 
                                                        data-denom="<?php echo esc_attr($it->denom); ?>"
                                                        data-rate="<?php echo esc_attr($it->sell_rate); ?>"
                                                        data-stock="<?php echo esc_attr($it->stock_laci); ?>"
                                                        data-img="<?php echo esc_attr($it->img_url); ?>">
                                                    <?php echo esc_html($it->post_title); ?> (Stok: <?php echo $it->stock_laci; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="rate-display">Info Kurs Jual Dasar: <span id="info_base_rate" class="text-blue">0</span></div>
                                    </div>
                                </div>
                                <div class="form-row mb-2">
                                    <div class="col"><label class="small-label">QTY</label><input type="number" id="inp_qty" class="puri-input highlight-input" placeholder="0" min="1"></div>
                                    <div class="col"><label class="small-label">Total Riyal</label><input type="number" id="inp_total_riyal" class="puri-input" placeholder="0"></div>
                                </div>
                                <div class="form-row mb-2">
                                    <div class="col"><label class="small-label">Kurs (IDR)</label><input type="number" id="inp_rate" class="puri-input bg-gray" value="0" readonly><input type="hidden" id="base_rate_hidden" value="0"></div>
                                    <div class="col"><label class="small-label">Total IDR</label><input type="text" id="inp_total_idr" class="puri-input bg-gray" value="Rp 0" readonly></div>
                                </div>
                                <input type="hidden" id="inp_denom" value="0"><input type="hidden" id="current_stock" value="0"><input type="hidden" id="is_finance" value="<?php echo $is_finance_or_admin ? '1' : '0'; ?>">
                                <button type="button" id="btn_add_cart" class="button button-primary button-large full-width" disabled><i class="fa-solid fa-plus"></i> Masukkan Keranjang</button>
                                <div id="stock_warning" class="text-red hidden" style="text-align:center; font-size:11px; margin-top:5px;"><i class="fa-solid fa-triangle-exclamation"></i> Melebihi Stok Laci!</div>
                            </div>
                        </div>
                    </div>

                    <div class="flex-col col-right-cart">
                        <div class="panel panel-cart">
                            <div class="panel-header"><i class="fa-solid fa-cart-shopping"></i> 1.2 Keranjang</div>
                            <div class="panel-body cart-scroll">
                                <table class="wp-list-table widefat fixed striped" id="cart_table">
                                    <thead><tr><th>Item</th><th width="60" class="tc">Qty</th><th class="tr">Riyal</th><th class="tr">IDR</th><th width="40" class="tc">#</th></tr></thead>
                                    <tbody><tr class="empty-cart"><td colspan="5" align="center" style="padding: 30px; color:#999;">Keranjang masih kosong</td></tr></tbody>
                                </table>
                            </div>
                            <div class="panel-footer cart-summary-box">
                                <div class="summary-line"><span>Subtotal Riyal</span><span id="cart_total_riyal" class="val-riyal">0</span></div>
                                <div class="summary-line main"><span>Grand Total (IDR)</span><span id="cart_total_idr" class="val-idr">Rp 0</span></div>
                                <div class="checkout-area">
                                    <label class="chk-valid"><input type="checkbox" id="chk_valid" checked> Data & Uang benar</label>
                                    <button type="button" id="btn_checkout" class="button button-primary button-hero"><i class="fa-solid fa-cash-register"></i> PROSES CHECKOUT</button>
                                </div>
                            </div>
                        </div>
                    </div> 
                </div> 

                <div class="flex-row bottom-row">
                    <div class="flex-col col-history">
                        <div class="panel panel-history">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-clock-rotate-left"></i> Mutasi Harian (Today)</span>
                                <div id="box_history_summary" class="hidden" style="flex: 1; text-align: right; margin-right: 15px; font-size: 11px;">
                                    <span style="color:#2271b1; font-weight:700;">SAR <span id="val_sum_riyal">0</span></span> | <span style="color:#d63638; font-weight:700;">Rp <span id="val_sum_idr">0</span></span>
                                </div>
                                <button class="button button-small" id="btn_refresh_history"><i class="fa-solid fa-sync"></i></button>
                            </div>
                            <div class="panel-body table-scroll">
                                <table class="wp-list-table widefat striped dense" id="history_table">
                                    <thead><tr><th>Jam</th><th>Ref ID</th><th class="tr">Riyal</th><th class="tr">IDR</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex-col col-stock">
                        <div class="panel panel-stock">
                            <div class="panel-header"><span><i class="fa-solid fa-table-list"></i> Kartu Stok (Bulan Ini)</span></div>
                            <div class="panel-body table-scroll">
<table class="wp-list-table widefat striped dense" id="stock_table">
    <thead>
        <tr class="sortable-header">
            <th data-sort="name">Item <i class="fa-solid fa-sort"></i></th>
            
            <th class="tr" data-sort="qty_start" style="background:#f0f0f1; border-left:2px solid #ccc;">Awal (Pcs)</th>
            <th class="tr text-red" data-sort="qty_out">Keluar</th>
            <th class="tr text-green" data-sort="qty_in">Masuk</th>
            <th class="tr" data-sort="qty_end" style="background:#e6f7ff; font-weight:bold;">S.Akhir</th>
            
            <th class="tr text-red" data-sort="sar_out" style="border-left:2px solid #ccc;">SAR Out</th>
            <th class="tr text-green" data-sort="sar_in">SAR In</th>
            <th class="tr" data-sort="sar_end" style="background:#e6f7ff; font-weight:bold;">Saldo SAR</th>
        </tr>
    </thead>
    <tbody></tbody>
</table>
                            </div>
                        </div>
                    </div>
                </div> 
            </div>
        </div>

        <style>
            .jual {background-color: #f4f4f4 !important; color: #333;}
            .beli {background-color: #8e44ad !important; color: #fff !important; }
            #panelInputTransaksi.jual .panel-header {background-color: #f4f4f4 !important; color: #333;}
            #panelInputTransaksi.beli .panel-header {background-color: #8e44ad !important; color: #fff !important;}
        
            .mode-sell { border-left: 6px solid #0aaf1a; background-color: #ddf7e0 !important;  } 
            .mode-buy { border-left: 6px solid #e67e22; background-color: #f3e8e3 !important; }
        
            .puri-cockpit-wrapper { box-sizing: border-box; padding-top: 10px; }
            .cockpit-container { display: flex; flex-direction: column; gap: 15px; margin-top: 15px; }
            .flex-row { display: flex; gap: 15px; width: 100%; flex-wrap: wrap; }
            .flex-col { display: flex; flex-direction: column; gap: 15px; }
            .tr { text-align: right; } .tc { text-align: center; } .mb-2 { margin-bottom: 10px; } .mb-1 { margin-bottom: 8px; }
            .hidden { display: none; }
            .text-red { color: #d63638; } .text-blue { color: #2271b1; font-weight: bold; }
            .text-green { color: #059669; }

            .col-left-input { flex: 1 0; min-width:calc(50% - 15px) } .col-right-cart { flex: 1; min-width: 400px; } 
            .col-history { flex: 4; } .col-stock { flex: 8; }

            .panel { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 2px rgba(0,0,0,.05); border-radius: 6px; display: flex; flex-direction: column; height: 100%; }
            .panel-header { background: #f6f7f7; padding: 10px 15px; font-weight: 600; border-bottom: 1px solid #c3c4c7; font-size: 13px; display: flex; justify-content: space-between; align-items: center; color: #1d2327; }
            .panel-body { padding: 15px; flex-grow: 1; }

            .puri-input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #8c8f94; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
            .bg-gray { background-color: #f0f0f1; color: #646970; }
            .highlight-input { border-color: #2271b1; font-weight: bold; background: #fff; }
            .small-label { font-size: 11px; color: #646970; font-weight: 600; display: block; margin-bottom: 4px; text-transform: uppercase; }
            
            .item-selection-row { display: flex; gap: 10px; }
            .item-image-box { width: 60px; height: 60px; background: #eee; border: 1px solid #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
            .item-image-box img { width: 100%; height: 100%; object-fit: cover; }
            .item-dropdown-box { flex: 1; }
            .rate-display { font-size: 11px; color: #666; margin-top: 2px; text-align: right; }
            
            .center-fixed { position: fixed; left: 50%; top: 50%; text-align: center; }
            .radio-group { display: flex; gap: 15px; background: #f0f0f1; padding: 8px; border-radius: 4px; border: 1px solid #dcdcde; }
            .radio-label { font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 5px; }
            .badge { display: inline-block; padding: 2px 8px; color: #1C94FF15; text-align:center; font-size:70px; z-index:200; transform : rotate(-15deg); border-radius: 3px; margin-top: 5px; }
            .form-row { display: flex; gap: 10px; } .form-row .col { flex: 1; }
            .cart-scroll { overflow-y: auto; height: 320px; padding: 0; border-bottom: 1px solid #eee; }
            #cart_table th { position: sticky; top: 0; z-index: 10; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
            .cart-summary-box { background: #fafafa; padding: 20px; }
            .summary-line { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
            .summary-line.main { font-size: 18px; font-weight: bold; border-top: 1px dashed #ccc; padding-top: 12px; margin-top: 8px; }
            .val-idr { color: #d63638; }
            .button-hero { height: 45px !important; font-size: 15px !important; width: 100%; margin-top: 15px !important; justify-content: center; display: flex; gap: 8px; align-items: center; }
            
            .table-scroll { height: 350px; overflow-y: auto; padding: 0; border-bottom: 1px solid #ddd; position: relative; background: #fff; }
            .table-scroll thead th { position: sticky; top: 0; background: #f0f0f1; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.1); cursor: pointer; user-select: none; }
            .table-scroll thead th:hover { background: #e0e0e1; }
            #history_table, #stock_table { border-top: none; margin-top: 0; width: 100%; border-collapse: collapse; font-size: 11px; }
            #stock_table td { padding: 6px 8px; vertical-align: middle; }
            
            @media (max-width: 1200px) {
                .flex-row { flex-direction: column; }
                .col-left-input, .col-right-cart { width: 100%; flex: auto; }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            
            const Cockpit = { cart: [], stockData: [] };
            let sortKey = 'denom'; 
            let sortAsc = true;
            let tradeMode = 'sell'; // Global var for trade mode

            function parseNum(val) { return parseFloat(val) || 0; }
            function fmt(n) { return n.toLocaleString('en-US'); }
            function fmtIDR(n) { return n.toLocaleString('id-ID'); }

            function init() {
                loadStockAndHistory();
                setupListeners();
                $('#item_select').select2({ placeholder: "Pilih Item...", width: '100%' });
                $('#customer_select').select2({ placeholder: "Pilih Customer...", width: '100%', allowClear: true });
                // Initial Mode Guard
                applyModeGuard();
            }

            function setupListeners() {
                // Customer Type Switcher
                $('input[name="cust_type"]').change(function() {
                    let type = $(this).val();
                    if(type === 'registered') { $('#box_registered').slideDown(); $('#box_walkin').slideUp(); } 
                    else { $('#box_registered').slideUp(); $('#box_walkin').slideDown(); $('#customer_select').val(null).trigger('change'); }
                });
                
                // Trade Mode Handler
                $('input[name="trade_mode"]').on('change', function () {
                    tradeMode = $(this).val();
                    applyModeGuard();
                });

                // Customer Select Badge
                $('#customer_select').on('select2:select', function (e) {
                    let opt = $(this).find(':selected');
                    let type = (opt.data('type') || 'Member').toLowerCase();
                    $('#cust_badge').text(type.toUpperCase()).removeClass('hidden');
                    checkRateEditable(type);
                });

                $('#customer_select').on('select2:clear', function (e) { $('#cust_badge').addClass('hidden').text(''); checkRateEditable(''); });

                // Item Selection
                $('#item_select').on('select2:select', function(e) {
                    let opt = $(this).find(':selected');
                    let denom = parseNum(opt.data('denom'));
                    let rate = parseNum(opt.data('rate'));
                    let stock = parseNum(opt.data('stock'));
                    let img = opt.data('img') || '<?php echo plugin_dir_url(__FILE__) . 'assets/img/no-image.png'; ?>';

                    if (denom <= 0) Swal.fire('Error Data', 'Item ini memiliki Denominasi 0.', 'error');

                    $('#inp_denom').val(denom); $('#base_rate_hidden').val(rate); $('#current_stock').val(stock); $('#item_img_preview').attr('src', img);
                    $('#inp_rate').val(rate); $('#info_base_rate').text(rate.toLocaleString());
                    $('#inp_qty').val('').focus(); $('#inp_total_riyal').val(''); $('#inp_total_idr').val('Rp 0');
                    checkStockLock();
                });

                // Calculations
                $('#inp_qty').on('input', function() {
                    let qty = parseNum($(this).val());
                    let denom = parseNum($('#inp_denom').val());
                    let riyal = qty * denom;
                    if (denom > 0) $('#inp_total_riyal').val(riyal > 0 ? riyal : '');
                    calcFinalIDR(); checkStockLock(); 
                });

                $('#inp_total_riyal').on('input', function() {
                    let riyal = parseNum($(this).val());
                    let denom = parseNum($('#inp_denom').val());
                    if(denom > 0) { let qty = riyal / denom; $('#inp_qty').val(qty > 0 ? qty : ''); }
                    calcFinalIDR(); checkStockLock(); 
                });

                $('#inp_rate').on('input', function() { calcFinalIDR(); });

                // Clear Form (INPUT ONLY) - Do NOT reset trade mode here!
                $('#btn_clear_form').click(function() { 
                    $('#item_select').val(null).trigger('change'); 
                    $('#inp_qty').val(''); $('#inp_total_riyal').val(''); $('#inp_total_idr').val('Rp 0'); 
                    $('#btn_add_cart').prop('disabled', true);
                    
                    // [LOGIC 5 PATCH] Only reset trade mode if CART IS EMPTY
                    if(Cockpit.cart.length === 0) {
                        tradeMode = 'sell';
                        $('input[name="trade_mode"][value="sell"]').prop('checked', true);
                        applyModeGuard();
                        $('input[name="trade_mode"]').prop('disabled', false); // Unlock
                    }
                });

                $('#btn_add_cart').click(addToCart);
                $('#inp_qty').keypress(function(e){ if(e.which == 13 && !$('#btn_add_cart').prop('disabled')) $('#btn_add_cart').click(); });
                $(document).on('click', '.btn-remove-item', function() { removeItem($(this).data('index')); });
                $('#btn_checkout').click(handleCheckout);
                $('#btn_refresh_history').click(loadStockAndHistory);

                // Sorting
                $('.sortable-header th').click(function() {
                    let key = $(this).data('sort');
                    if(key) {
                        if(sortKey === key) sortAsc = !sortAsc;
                        else { sortKey = key; sortAsc = true; }
                        renderStockTable();
                    }
                });
            }

            function checkRateEditable(custType) {
                let isFinance = $('#is_finance').val() === '1';
                let isAgen = (custType === 'agen' || custType === 'agent');
                if(isFinance || isAgen) { $('#inp_rate').prop('readonly', false).removeClass('bg-gray').addClass('highlight-input'); } 
                else { $('#inp_rate').prop('readonly', true).addClass('bg-gray').removeClass('highlight-input'); $('#inp_rate').val($('#base_rate_hidden').val()); calcFinalIDR(); }
            }

            function calcFinalIDR() {
                let riyal = parseNum($('#inp_total_riyal').val());
                let rate = parseNum($('#inp_rate').val());
                let idr = riyal * rate;
                $('#inp_total_idr').val('Rp ' + idr.toLocaleString('id-ID'));
            }

            function checkStockLock() {
                let qty = parseNum($('#inp_qty').val());
                let riyal = parseNum($('#inp_total_riyal').val());
                let stock = parseNum($('#current_stock').val());
                let denom = parseNum($('#inp_denom').val());
                let btn = $('#btn_add_cart');
                let warn = $('#stock_warning');
                
                // [LOGIC 4 PATCH] Conditional Stock Validation
                // Sell: Qty <= Stock. Buy: No Limit (just > 0)
                let stockCheck = (tradeMode === 'buy') ? true : (qty <= stock);
                
                let isValid = (qty > 0) && (riyal > 0) && (denom > 0) && stockCheck;
                if(isValid) { btn.prop('disabled', false); warn.addClass('hidden'); } 
                else {
                    btn.prop('disabled', true);
                    if (tradeMode === 'sell' && qty > stock) warn.removeClass('hidden').html('<i class="fa-solid fa-triangle-exclamation"></i> Melebihi Stok Laci!');
                    else if (denom === 0 && qty > 0) warn.removeClass('hidden').html('<i class="fa-solid fa-triangle-exclamation"></i> Error: Denom 0');
                    else warn.addClass('hidden');
                }
            }

            function addToCart() {
                let itemId = $('#item_select').val();
                let itemName = $('#item_select option:selected').text().trim();
                let denom = parseNum($('#inp_denom').val());
                let rate = parseNum($('#inp_rate').val());
                let qty = parseNum($('#inp_qty').val());
                let riyal = parseNum($('#inp_total_riyal').val());
                let idr = riyal * rate; 

                if(!itemId) { Swal.fire('Gagal', 'Pilih Item dulu', 'error'); return; }
                if(qty <= 0) { Swal.fire('Gagal', 'Qty tidak boleh 0', 'warning'); return; }
                
                Cockpit.cart.push({ id: itemId, name: itemName.split('(')[0], denom: denom, rate: rate, qty: qty, riyal: riyal, idr: idr });
                let sfx = document.getElementById('fx_cart_clicked'); if(sfx) sfx.play();
                
                // [LOGIC 5 PATCH] Lock Session on First Item
                lockSession();

                $('#btn_clear_form').click(); // This will not reset trade mode because cart is not empty
                renderCart();
            }

            function lockSession() {
                $('input[name="trade_mode"]').prop('disabled', true);
            }

            function unlockSession() {
                $('input[name="trade_mode"]').prop('disabled', false);
                // Reset to Sell (optional, but requested in logic 1 state)
                tradeMode = 'sell';
                $('input[name="trade_mode"][value="sell"]').prop('checked', true);
                applyModeGuard();
            }

            function removeItem(index) {
                Cockpit.cart.splice(index, 1);
                renderCart();
                // [LOGIC 5 PATCH] Unlock if empty
                if(Cockpit.cart.length === 0) unlockSession();
            }

            function renderCart() {
                let tbody = $('#cart_table tbody'); tbody.empty();
                let sumRiyal = 0; let sumIDR = 0;
                if(Cockpit.cart.length === 0) { tbody.html('<tr class="empty-cart"><td colspan="5" align="center" style="padding:20px; color:#aaa;">Keranjang kosong</td></tr>'); } 
                else {
                    Cockpit.cart.forEach((item, index) => {
                        sumRiyal += item.riyal; sumIDR += item.idr;
                        tbody.append(`<tr><td>${item.name}</td><td class="tc">${item.qty}</td><td class="tr">${fmt(item.riyal)}</td><td class="tr">${fmtIDR(item.idr)}</td><td class="tc"><button class="button button-small btn-remove-item" data-index="${index}"><i class="fa fa-times" style="color:red"></i></button></td></tr>`);
                    });
                }
                $('#cart_total_riyal').text(fmt(sumRiyal));
                $('#cart_total_idr').text('Rp ' + fmtIDR(sumIDR));
            }

            function handleCheckout() {
// [DEBUG 1] Cek nilai di layar sebelum dikirim
let debug_val = $('#cart_total_riyal').text();
console.log("debug code- total riyal = " + debug_val);
		
                if(Cockpit.cart.length === 0) return Swal.fire('Kosong', 'Keranjang belanja kosong', 'warning');
                if(!$('#chk_valid').is(':checked')) return Swal.fire('Konfirmasi', 'Mohon centang "Data & Uang sudah benar"', 'info');
                
                let mode = $('input[name="cust_type"]:checked').val();
                let custData = {};
                if(mode === 'registered') {
                    custData.id = $('#customer_select').val();
                    if(!custData.id) return Swal.fire('Error', 'Pilih Customer Member dulu', 'error');
                    custData.name = $('#customer_select option:selected').text();
                } else {
                    custData.name = $('#wic_name').val(); custData.nik = $('#wic_nik').val(); custData.phone = $('#wic_phone').val(); custData.address = $('#wic_address').val();
                    if(!custData.name || !custData.nik || !custData.phone || !custData.address || !$('#wic_ktp')[0].files[0]) return Swal.fire('Data Belum Lengkap', 'Lengkapi data Walk-in & Upload KTP', 'error');
                }
                
                // [LOGIC 6 PATCH] Dynamic Confirmation Text
                let titleText = (tradeMode === 'buy') ? 'Apakah setuju untuk membeli?' : 'Apakah sudah terima uang kas?';
                let btnColor = (tradeMode === 'buy') ? '#d63638' : '#2271b1';
                let htmlText = `Total: <b>${$('#cart_total_idr').text()}</b>.<br>` + ((tradeMode === 'buy') ? 'Stok akan bertambah.' : 'Stok akan berkurang.');

                Swal.fire({ 
                    title: titleText, 
                    html: htmlText, 
                    icon: 'question', 
                    showCancelButton: true, 
                    confirmButtonText: 'Proceed', // [LOGIC 7] Proceed button
                    confirmButtonColor: btnColor 
                }).then((res) => { if (res.isConfirmed) processTransaction(mode, custData); });
            }

            function processTransaction(mode, custData) {
                Swal.fire({title: 'Memproses...', text: 'Mengupload data...', didOpen: () => Swal.showLoading()});
                let formData = new FormData();
                formData.append('action', 'puri_pos_checkout');
                formData.append('nonce', '<?php echo wp_create_nonce("puri_pos_checkout"); ?>');
                formData.append('cart', JSON.stringify(Cockpit.cart));
                formData.append('cust_mode', mode);
                formData.append('trade_mode', tradeMode); // [LOGIC 7] Send TradeMode

                if(mode === 'walkin') {
                    formData.append('wic_name', custData.name); formData.append('wic_nik', custData.nik); formData.append('wic_phone', custData.phone); formData.append('wic_address', custData.address); formData.append('wic_ktp', $('#wic_ktp')[0].files[0]);
                } else { formData.append('cust_id', custData.id); }
                
                $.ajax({ url: ajaxurl, type: 'POST', data: formData, processData: false, contentType: false, success: function(response) { 
                        if(response.success) { 
                            Swal.fire('Sukses!', 'Ref: ' + response.data.ref_id, 'success'); 
                            Cockpit.cart = []; 
                            // Full Reset incl Session Unlock
                            unlockSession();
                            
                            $('#wic_name').val(''); $('#wic_nik').val(''); $('#wic_phone').val(''); $('#wic_address').val(''); $('#wic_ktp').val(''); 
                            $('#btn_clear_form').click(); renderCart(); loadStockAndHistory(); 
                        } else { Swal.fire('Gagal', response.data, 'error'); } 
                    }, error: function() { Swal.fire('Error', 'Server Error', 'error'); } 
                });
            }

            function loadStockAndHistory() {
                // Mutasi Harian
                $.ajax({
                    url: ajaxurl, data: { action: 'puri_pos_get_daily_mutation' }, cache: false, 
                    success: function(res) { 
                        if(res.success) { 
                            let html = ''; let sumRiyal = 0; let sumIDR = 0;
                            res.data.forEach(m => { 
                                sumRiyal += parseNum(m.total_riyal.replace(/,/g, ''));
                                sumIDR += parseNum(m.total_idr.replace(/,/g, ''));
                                html += `<tr><td>${m.time}</td><td>${m.ref_id}</td><td class="tr">${m.total_riyal}</td><td class="tr">${m.total_idr}</td></tr>`; 
                            }); 
                            $('#history_table tbody').html(html); 
                            $('#val_sum_riyal').text(fmt(sumRiyal)); $('#val_sum_idr').text(fmtIDR(sumIDR));
                            $('#box_history_summary').removeClass('hidden');
                        } 
                    }
                });
                
                // Kartu Stok
                $.ajax({
                    url: ajaxurl, data: { action: 'puri_pos_get_stock_summary' }, cache: false,
                    success: function(res){ 
                        if(res.success) { Cockpit.stockData = res.data; renderStockTable(); } 
                    }
                });
            }

function renderStockTable() {
                // Sorting Logic
                Cockpit.stockData.sort((a, b) => {
// [PATCH] Jika sort kolom 'name', kita paksa pakai 'denom' agar urutan 1, 5, 10, 20
                    if (sortKey === 'name') {
                        // Pastikan denom diperlakukan sebagai angka
                        let denA = parseFloat(a.denom) || 0;
                        let denB = parseFloat(b.denom) || 0;
                        return sortAsc ? (denA - denB) : (denB - denA);
                    }

                    // Default Logic untuk kolom lain (Qty, Riyal, IDR)
                    let valA = a[sortKey]; 
                    let valB = b[sortKey];
                    
                    if(typeof valA === 'string') valA = valA.toLowerCase();
                    if(typeof valB === 'string') valB = valB.toLowerCase();
                    
                    if (valA < valB) return sortAsc ? -1 : 1;
                    if (valA > valB) return sortAsc ? 1 : -1;
                    return 0;
					});

                // Icon Sorting Update
                $('.sortable-header th i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
                $(`.sortable-header th[data-sort="${sortKey}"] i`).removeClass('fa-sort').addClass(sortAsc ? 'fa-sort-up' : 'fa-sort-down');

                let html = '';
                Cockpit.stockData.forEach(s => {
                    // Style Helper
                    let bgEnd = 'background:#e6f7ff; font-weight:bold;';
                    let borderLeft = 'border-left:2px solid #eee;';
                    let clsZero = 'color:#ccc;';

                    html += `<tr>
                        <td><strong>${s.name}</strong><br><small style="color:#888">${s.sku}</small></td>
                        
                        <td class="tr" style="${borderLeft} ${s.qty_start===0?clsZero:''}">${fmt(s.qty_start)}</td>
                        <td class="tr text-red" style="${s.qty_out===0?clsZero:''}">${s.qty_out > 0 ? '-' + fmt(s.qty_out) : '0'}</td>
                        <td class="tr text-green" style="${s.qty_in===0?clsZero:''}">${s.qty_in > 0 ? '+' + fmt(s.qty_in) : '0'}</td>
                        <td class="tr" style="${bgEnd} ${s.qty_end===0?clsZero:'color:#000;'}">${fmt(s.qty_end)}</td>
                        
                        <td class="tr text-red" style="${borderLeft} ${s.sar_out===0?clsZero:''}">${fmt(s.sar_out)}</td>
                        <td class="tr text-green" style="${s.sar_in===0?clsZero:''}">${fmt(s.sar_in)}</td>
                        <td class="tr" style="${bgEnd} ${s.sar_end===0?clsZero:'color:#2271b1;'}">${fmt(s.sar_end)}</td>
                    </tr>`;
                });
                $('#stock_table tbody').html(html);
            }
            
            function applyModeGuard() {
                const $panel = $('.panel-header');
                $panel.removeClass('mode-sell mode-buy');

                if (tradeMode === 'sell') {
                    $panel.addClass('mode-sell');
                    $('#btn_add_cart').text('Masukkan Keranjang (Jual)').removeClass('beli').addClass('jual');
                    $('#inp_qty, #inp_total_riyal').prop('disabled', false);
                    $('#inp_rate').prop('readonly', true).val($('#base_rate_hidden').val()).addClass('bg-gray').removeClass('highlight-input');
                } else {
                    $panel.addClass('mode-buy');
                    $('#btn_add_cart').text('Masukkan Keranjang (Beli)').removeClass('jual').addClass('beli');
                    $('#inp_qty, #inp_total_riyal').prop('disabled', false);
                    $('#inp_rate').prop('readonly', false).removeClass('bg-gray').addClass('highlight-input');
                }
                calcFinalIDR(); 
            }
            
            init();
        });
        </script>
		
        <?php
    }

    // =========================================================================
    // 2. BACKEND HELPERS & AJAX
    // =========================================================================
    
    private function get_items_for_dropdown() {
        global $wpdb;
        $posts = get_posts(['post_type' => 'pr_item', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $results = [];
        $tbl_items = puri_table_name('T_ITEMS'); $tbl_stock = puri_table_name('T_STOCK'); $tbl_locks = puri_table_name('T_LOCKS');

        foreach($posts as $p) {
            $img = get_the_post_thumbnail_url($p->ID, 'thumbnail');
            $sku = get_post_meta($p->ID, 'item_sku_code', true) ?: get_post_meta($p->ID, '_puri_item_sku', true); 
            $denom = get_post_meta($p->ID, '_puri_denom', true) ?: get_post_meta($p->ID, 'denom', true) ?: 0;
            $rate = get_post_meta($p->ID, '_puri_sell_rate', true) ?: 0;
            $stock = 0;
            
            if ($sku && $tbl_items) {
                $query = "SELECT (COALESCE(s.qty, 0) - COALESCE(l.qty_lock, 0)) as ready_stock, i.denom_value, i.sell_rate FROM {$tbl_items} i LEFT JOIN {$tbl_stock} s ON i.id = s.item_id AND s.location_id = 'laci_kasir' LEFT JOIN {$tbl_locks} l ON i.id = l.item_id WHERE i.sku = %s LIMIT 1";
                $engine_data = $wpdb->get_row($wpdb->prepare($query, $sku));
                if ($engine_data) {
                    $stock = $engine_data->ready_stock;
                    if (floatval($engine_data->denom_value) > 0) $denom = floatval($engine_data->denom_value);
                    if (floatval($engine_data->sell_rate) > 0) $rate = floatval($engine_data->sell_rate);
                }
            }
            $p->denom = $denom; $p->sell_rate = $rate; $p->stock_laci = $stock ?: 0; $p->img_url = $img;
            $results[] = $p;
        }
        usort($results, function($a, $b) { return $a->denom <=> $b->denom; });
        return $results;
    }   
    
    private function get_customers_for_dropdown() {
        $posts = get_posts(['post_type' => 'pr_customer', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $results = [];
        foreach($posts as $p) {
            $type = get_post_meta($p->ID, '_puri_cust_type', true) ?: 'Member';
            $phone = get_post_meta($p->ID, '_puri_cust_phone', true) ?: '-';
			$nik = get_post_meta($p->ID, '_puri_cust_nik', true) ?: '-';
            $p->type = $type; $p->phone = $phone; $p->nik = $nik; $results[] = $p;
        }
        return $results;
    }

public function ajax_process_checkout() {
        check_ajax_referer('puri_pos_checkout', 'nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $trade_mode = $_POST['trade_mode'] ?? 'sell';
        $trade_mode = in_array($trade_mode, ['sell','buy']) ? $trade_mode : 'sell';
        $cart = json_decode(stripslashes($_POST['cart']), true);
        $cust_mode = sanitize_text_field($_POST['cust_mode']);
        
        if(empty($cart)) wp_send_json_error('Keranjang kosong');

        $wpdb->query('START TRANSACTION');
        try {
            // 1. DATA PELANGGAN
            $customer_id = 0; 
            $customer_name = ''; 
            $customer_nik  = '';
            
            if($cust_mode === 'walkin') {
                $wic_name = sanitize_text_field($_POST['wic_name']);
                $customer_nik = sanitize_text_field($_POST['wic_nik']);
                
                $customer_id = wp_insert_post(['post_type'=>'pr_customer', 'post_title'=>$wic_name . ' (Walk-in)', 'post_status'=>'publish']);
                if(is_wp_error($customer_id)) throw new Exception('Gagal membuat data pelanggan.');
                
                update_post_meta($customer_id, '_puri_cust_type', 'umum'); 
                update_post_meta($customer_id, '_puri_cust_phone', sanitize_text_field($_POST['wic_phone']));
                update_post_meta($customer_id, '_puri_cust_nik', $customer_nik);
                update_post_meta($customer_id, '_puri_cust_address', sanitize_textarea_field($_POST['wic_address']));
                
                if (!empty($_FILES['wic_ktp']['name'])) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php'); require_once(ABSPATH . 'wp-admin/includes/file.php'); require_once(ABSPATH . 'wp-admin/includes/media.php');
                    $attachment_id = media_handle_upload('wic_ktp', $customer_id);
                    if (!is_wp_error($attachment_id)) update_post_meta($customer_id, '_puri_cust_ktp_image', wp_get_attachment_url($attachment_id));
                }
                $customer_name = $wic_name;
                
            } else {
                $customer_id = intval($_POST['cust_id']);
                $customer_name = get_the_title($customer_id);
                $customer_nik = get_post_meta($customer_id, '_puri_cust_nik', true) ?: '-';
            } 

            // 2. SETUP TRANSAKSI
            $ref_id = ($trade_mode == 'buy' ? 'BUY-' : 'POS-') . date('YmdHis') . '-' . rand(100,999);
            $trx_date = current_time('mysql');
            
            $total_riyal = 0; 
            $total_idr = 0;
            $total_hpp   = 0;
            
            $tbl_items = puri_table_name('T_ITEMS'); 
            $tbl_stock = puri_table_name('T_STOCK'); 
            $tbl_ledger = puri_table_name('T_LEDGER');
            $location_id = 'laci_kasir';

            // 3. COST MAPPING (OPTIMISASI HPP)
            // Hanya diperlukan jika mode SELL. Jika BUY, kita justru akan membentuk harga baru.
            $cost_map = [];
            if ($trade_mode === 'sell') {
				$item_wp_ids = array_column($cart, 'id'); // WP Post IDs
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
					foreach ($rows as $r) $cost_map[$r->id] = floatval($r->cost_price);
				}

            }

            // 4. LOOP ITEMS
foreach ($cart as $item) {
    $wp_post_id = (int) $item['id'];   // CPT Post ID
    $qty        = (int) $item['qty'];
    $riyal      = (float) $item['riyal'];
    $idr        = (float) $item['idr'];

    // translate ke SQL ID
    $item_sql_id = puri_get_item_sql_id($wp_post_id);

    if ($qty <= 0) continue;

    // Lock Stock pakai SQL ID
    $curr = $wpdb->get_var($wpdb->prepare(
        "SELECT qty FROM $tbl_stock WHERE item_id = %d AND location_id = %s FOR UPDATE",
        $item_sql_id, $location_id
    ));
    if (is_null($curr)) {
        $wpdb->insert($tbl_stock, [
            'item_id'     => $item_sql_id,
            'location_id' => $location_id,
            'qty'         => 0,
            'last_updated'=> current_time('mysql')
        ]);
        $curr = 0;
    }


                // --- LOGIC PER MODE ---
// --- LOGIC PER MODE ---
if ($trade_mode === 'sell') {
    // [MODE JUAL]
    if ($curr < $qty) throw new Exception("Stok kurang untuk item ID: $wp_post_id");

    // Update Stock pakai SQL ID
    $wpdb->query($wpdb->prepare(
        "UPDATE $tbl_stock SET qty = qty - %d, last_updated = %s WHERE item_id = %d AND location_id = %s",
        $qty, current_time('mysql'), $item_sql_id, $location_id
    ));

    // Hitung HPP (pakai cost_map keyed by post_id)
    $cost_price = $cost_map[$wp_post_id] ?? 0;
    $item_hpp   = $cost_price * $qty;
    $total_hpp += $item_hpp;
} else {
    // [MODE BELI] - MOVING AVERAGE LOGIC

    // Ambil harga modal lama dari T_ITEMS (pakai SQL ID)
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

    // Update Harga Modal Baru (pakai SQL ID)
    $wpdb->update($tbl_items, ['base_price' => $new_avg_price], ['id' => $item_sql_id]);

    // Opsional: Update juga cost_avg di T_STOCK (pakai SQL ID)
    // $wpdb->update($tbl_stock, ['cost_avg' => $new_avg_price], ['item_id' => $item_sql_id, 'location_id' => $location_id]);

    // Update Stock (Tambah) pakai SQL ID
    $wpdb->query($wpdb->prepare(
        "UPDATE $tbl_stock SET qty = qty + %d, last_updated = %s WHERE item_id = %d AND location_id = %s",
        $qty, current_time('mysql'), $item_sql_id, $location_id
    ));
}

                // Catat di Ledger (Kartu Stok)
				

                $wpdb->insert($tbl_ledger, [
                    'location_id' => $location_id,
                    'item_id'     => $item_sql_id ,
                    'qty_change'  => ($trade_mode === 'sell') ? -$qty : +$qty,
                    'trx_type'    => $trade_mode, 
                    'ref_id'      => $ref_id,
                    'description' => ($trade_mode === 'sell') ? "POS.Jual - $customer_name (-$qty pcs)" : "POS.Beli - $customer_name (+$qty pcs)" ,
                    'trx_date'    => $trx_date,
                ]);

                $total_riyal += $riyal;
                $total_idr   += $idr;
            }





// [DEBUG 2] Cek nilai variable PHP sebelum dibungkus JSON
error_log("2. debug code - kirim ke json = " . $total_riyal);
// Tambahan: Intip struktur items juga untuk memastikan riyal ada di tiap item
error_log("2. debug code - struktur cart = " . print_r($cart, true));

// transform cart sebelum masuk snapshot
$items_snapshot = [];
foreach ($cart as $item) {
    $wp_post_id = (int) $item['id'];
    $items_snapshot[] = [
        'sku'        => get_field('item_sku_code', $wp_post_id),
        'wp_post_id' => $wp_post_id,
        'qty'        => (int) $item['qty'],
        'riyal'      => (float) $item['riyal'],
        'idr'        => (float) $item['idr']
    ];
}



            // 5. JURNAL & SNAPSHOT
            $snapshot = [
                'mode'          => $trade_mode, 
                'items'         => $items_snapshot, 
                'total_riyal'   => $total_riyal, 
                'total_idr'     => $total_idr, 
                'customer'      => $customer_name, 
                'customer_nik'  => $customer_nik,
                'created_at'    => current_time('mysql'),
                'trx_type'      => $trade_mode
            ];

            // Mapping GL (Fallback Default)
            $gl_cash = puri_gl('cash_drawer') ?: '1101'; 
            $gl_sales = puri_gl('sales_cash') ?: '4100'; 
            $gl_inventory = puri_gl('inventory') ?: '1400'; 
            $gl_cogs = puri_gl('cogs') ?: '5100';
            
            if ($trade_mode === 'sell') {
                // Jurnal JUAL
                puri_insert_journal($trx_date, $ref_id, $gl_cash, $total_idr, 0, "POS Sell SAR - $customer_name", json_encode($snapshot));
                puri_insert_journal($trx_date, $ref_id, $gl_sales, 0, $total_idr, "Sales SAR - $customer_name", json_encode($snapshot));
                
                // Jurnal HPP (Jika ada HPP)
                if ($total_hpp > 0) {
                    puri_insert_journal($trx_date, $ref_id, $gl_cogs, $total_hpp, 0, "HPP SAR - $customer_name", json_encode($snapshot));
                    puri_insert_journal($trx_date, $ref_id, $gl_inventory, 0, $total_hpp, "Inventory Out SAR - $customer_name", json_encode($snapshot));
                }
            } else {
                // Jurnal BELI
                puri_insert_journal($trx_date, $ref_id, $gl_inventory, $total_idr, 0, "Inventory In (Buy) - $customer_name", json_encode($snapshot));
                puri_insert_journal($trx_date, $ref_id, $gl_cash, 0, $total_idr, "Cash Out Buy - $customer_name", json_encode($snapshot));
            }

            $wpdb->query('COMMIT');
            wp_send_json_success(['ref_id' => $ref_id]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

public function ajax_get_daily_mutation() {
    global $wpdb;
    $today_str = current_time('Y-m-d');
    $gl_cash = puri_gl('cash_drawer') ?: '1111';
    
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ref_id, trx_date, snapshot_json, debit, credit 
         FROM " . puri_table_name('T_JOURNAL') . " 
         WHERE (ref_id LIKE 'POS-%%' OR ref_id LIKE 'BUY-%%') 
			AND account_code = %s 
			AND trx_date LIKE %s 
		 GROUP BY ref_id
         ORDER BY trx_date DESC", 
        $gl_cash, 
        $today_str . '%'
    ));        
    
    $data = [];
    
    if($rows) {
        foreach($rows as $r) {
            // =========================================================
            // 1. LOGIKA DECODE BERTINGKAT (RECURSIVE DECODE) - FINAL FIX
            // =========================================================
            
            $raw_data = $r->snapshot_json;
            // Bersihkan karakter sampah (Invisible characters) dulu
            $clean_json = stripslashes($raw_data);
            $clean_json = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean_json);
            
            // Decode Tahap 1
            $json = json_decode($clean_json, true);
            
            // [FIX UTAMA] Cek di luar blok error: Jika hasil decode masih STRING, decode lagi!
            if (is_string($json)) {
                $json = json_decode($json, true);
            }

            // Fallback: Jika masih gagal, coba raw data asli
            if (!is_array($json)) {
                $json = json_decode($raw_data, true);
                if (is_string($json)) {
                    $json = json_decode($json, true);
                }
            }


// [DEBUG 3] Cek nilai akhir setelah dibedah
// Kita catat Ref ID juga supaya tahu transaksi mana yang 0
error_log("3. debug code - json explode (Ref: " . $r->ref_id . ") = " . $riyal_amount);
                
// Jika hasilnya 0, kita intip JSON mentahnya
if ($riyal_amount == 0) {
 error_log("3. debug code - RAW JSON (Ref: " . $r->ref_id . ") = " . $r->snapshot_json);
}



            
            // =========================================================
            // 2. LOGIKA BACA NILAI (WATERFALL / LINEAR)
            // =========================================================
            
            $riyal_amount = 0;

            // Cek 1: Total Riyal (Format Baru)
            // Pakai IF biasa, bukan ELSEIF
            if (isset($json['total_riyal']) && is_numeric($json['total_riyal'])) {
                $val = floatval($json['total_riyal']);
                if ($val > 0) $riyal_amount = $val;
            }

            // Cek 2: Total-Riyal (Format Lama) - Hanya jika belum ketemu
            if ($riyal_amount == 0 && isset($json['total-riyal']) && is_numeric($json['total-riyal'])) {
                $val = floatval($json['total-riyal']);
                if ($val > 0) $riyal_amount = $val;
            }

            // Cek 3: Hitung Manual dari Items (Benteng Terakhir)
            if ($riyal_amount == 0 && !empty($json['items']) && is_array($json['items'])) {
                foreach ($json['items'] as $it) {
                    $r_val = 0;
                    if (isset($it['riyal'])) $r_val = floatval($it['riyal']);
                    elseif (isset($it['total_riyal'])) $r_val = floatval($it['total_riyal']);
                    $riyal_amount += $r_val;
                }
            }

            // =========================================================

            $amount_idr = ($r->debit > 0) ? $r->debit : $r->credit;
            
			$arah = (strpos($r->ref_id, 'BUY-') === 0) ? -1 : 1;

			$amount_idr = $amount_idr * $arah;
			$riyal_amount = $riyal_amount * $arah;
			
            $data[] = [
                'time'        => date('H:i', strtotime($r->trx_date)), 
                'ref_id'      => $r->ref_id, 
                'total_riyal' => number_format($riyal_amount), 
                'total_idr'   => number_format(floatval($amount_idr))
            ];
        }
    }
    
    wp_send_json_success($data);
}


// === API KARTU STOK (REVISED LOGIC: BACK CALCULATION) ===

public function ajax_get_stock_summary() {
        global $wpdb;
        $start_date = date('Y-m-01 00:00:00');
        $end_date   = date('Y-m-t 23:59:59');
        
        // -----------------------------------------------------------
        // 1. QUERY LEDGER: PISAHKAN IN (Positif) & OUT (Negatif)
        // -----------------------------------------------------------
        // Kita gunakan ABS() pada qty_out agar nilainya jadi positif (misal -5 jadi 5)
        // supaya enak dilihat di tabel (Kolom Keluar: 5).
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
        
        // Mapping biar akses cepat pakai item_id
        $map_mutasi = [];
        foreach($ledger_data as $l) { 
            $map_mutasi[$l->item_id] = [
                'in'  => floatval($l->qty_in),
                'out' => floatval($l->qty_out)
            ]; 
        }

        // -----------------------------------------------------------
        // 2. GABUNGKAN DENGAN MASTER BARANG & STOK FISIK
        // -----------------------------------------------------------
        // T_STOCK adalah kebenaran mutlak saldo SAAT INI (Ending Balance)
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
            
            // A. Saldo Akhir (Dari Database Realtime)
            $qty_end = ($it->type == 'package') ? floatval($it->qty_lock) : floatval($it->stock_phys);
            
            // B. Mutasi (Dari Map Ledger di atas)
            $qty_in  = isset($map_mutasi[$it->id]) ? $map_mutasi[$it->id]['in'] : 0;
            $qty_out = isset($map_mutasi[$it->id]) ? $map_mutasi[$it->id]['out'] : 0;
            
            // C. Hitung Mundur Saldo Awal (Back Calculation)
            // Rumus Logika: Saldo Sekarang - Yang Masuk + Yang Keluar = Saldo Awal Bulan
            $qty_start = $qty_end - $qty_in + $qty_out;

            // D. Hitungan Valuasi (Riyal)
            $sar_start = $qty_start * $denom;
            $sar_in    = $qty_in * $denom;
            $sar_out   = $qty_out * $denom;
            $sar_end   = $qty_end * $denom;

            $final_data[] = [
                'name'      => $it->name, 
                'sku'       => $it->sku, 
                'denom'     => $denom,
                
                // Key ini HARUS SAMA persis dengan yang diminta JavaScript renderStockTable
                'qty_start' => $qty_start,
                'qty_out'   => $qty_out, 
                'qty_in'    => $qty_in,  
                'qty_end'   => $qty_end,
                
                'sar_out'   => $sar_out,
                'sar_in'    => $sar_in,
                'sar_end'   => $sar_end
            ];
        }
        
        wp_send_json_success($final_data);
    }
	
	

}


// Inisialisasi class sekali
$puri_cockpit_pos = new Puri_Cockpit_POS();

// Wrapper global agar kompatibel dengan menu di mc-00
if (!function_exists('cockpit_render_page')) {
    function cockpit_render_page() {
        global $puri_cockpit_pos;
        if ($puri_cockpit_pos instanceof Puri_Cockpit_POS) {
            $puri_cockpit_pos->cockpit_render_page();
        } else {
            echo '<div class="wrap"><h1>Error</h1><p>Class Puri_Cockpit_POS belum diinisialisasi.</p></div>';
        }
    }
}