<?php
/**
 * MC 25 - Cockpit Extended POS
 * Version: 6.1.0 (Fix Stock Logic)
 * Author: Denmas Totok (Refactor by Gemini)
 *
 * Purpose:
 * - Pusat kontrol kasir (Cockpit).
 * - Parent Menu: 'puri-transaksi'.
 * - FIX v6.1.0: Perbaikan logika pembacaan stok (Post ID -> SKU -> T_ITEMS -> T_STOCK).
 */

defined('ABSPATH') || exit;

class Puri_Cockpit_POS {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX Endpoints
        add_action('wp_ajax_puri_pos_get_stock_summary', [$this, 'ajax_get_stock_summary']);
        add_action('wp_ajax_puri_pos_get_daily_mutation', [$this, 'ajax_get_daily_mutation']);
        add_action('wp_ajax_puri_pos_checkout', [$this, 'ajax_process_checkout']);
    }

    public function register_menu() {
        add_submenu_page(
            'puri-transaksi',  
            'Cockpit POS',
            '🛒 Cockpit POS',  
            'manage_options', 
            'puri-cockpit-pos',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'puri-cockpit-pos') === false) return;

        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
        wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
    }

    /**
     * ------------------------------------------------
     * RENDERING (VIEW)
     * ------------------------------------------------
     */
    public function render_page() {
        // AMBIL DATA
        $items = $this->get_items_for_dropdown(); // Stock Logic Fixed
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
                                        <label class="radio-label">
                                            <input type="radio" name="cust_type" value="registered"> 
                                            <i class="fa-solid fa-id-card"></i> Member
                                        </label>
                                        <label class="radio-label">
                                            <input type="radio" name="cust_type" value="walkin" checked> 
                                            <i class="fa-solid fa-walking"></i> Walk-in (Umum)
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group mb-2" id="box_registered" style="display:none;">
                                    <select id="customer_select" style="width:100%">
                                        <option value="">-- Pilih Member --</option>
                                        <?php foreach($customers as $c): 
                                            $label = esc_html($c->post_title);
                                            if($c->phone && $c->phone != '-') $label .= " ({$c->phone})";
                                        ?>
                                            <option value="<?php echo $c->ID; ?>" 
                                                    data-type="<?php echo esc_attr($c->type); ?>"
                                                    data-phone="<?php echo esc_attr($c->phone); ?>">
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="cust_badge" class="badge hidden"></div> 
                                </div>

                                <div id="box_walkin">
                                    <div class="form-group mb-1">
                                        <input type="text" id="wic_name" class="puri-input" placeholder="Nama Lengkap (Sesuai KTP)*">
                                    </div>
                                    <div class="form-row mb-1">
                                        <div class="col"><input type="text" id="wic_nik" class="puri-input" placeholder="NIK / SIM*"></div>
                                        <div class="col"><input type="text" id="wic_phone" class="puri-input" placeholder="No. HP*"></div>
                                    </div>
                                    <div class="form-group mb-1">
                                        <textarea id="wic_address" class="puri-input" style="height:40px; resize:none;" placeholder="Alamat Domisili*"></textarea>
                                    </div>
                                    <div class="form-group mb-1">
                                        <label class="small-label">Foto KTP*</label>
                                        <input type="file" id="wic_ktp" class="puri-input" accept="image/*" style="padding-top:4px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="panel panel-input">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-money-bill-wave"></i> 1.1.2 Input Transaksi</span>
                                <button type="button" class="button button-small" id="btn_clear_form" title="Hapus Baris (Reset)"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="panel-body">
                                
                                <div class="item-selection-row mb-2">
                                    <div class="item-image-box">
                                        <img id="item_img_preview" src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/no-image.png'; ?>" alt="Item">
                                    </div>
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
                                        <div class="rate-display">
                                            Info Kurs Jual Dasar: <span id="info_base_rate" class="text-blue">0</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row mb-2">
                                    <div class="col">
                                        <label class="small-label">QTY (Lembar Fisik)</label>
                                        <input type="number" id="inp_qty" class="puri-input highlight-input" placeholder="0" min="1">
                                    </div>
                                    <div class="col">
                                        <label class="small-label">Total Riyal (SAR Amount)</label>
                                        <input type="number" id="inp_total_riyal" class="puri-input" placeholder="0">
                                    </div>
                                </div>

                                <div class="form-row mb-2">
                                    <div class="col">
                                        <label class="small-label">Kurs Jual (IDR)</label>
                                        <input type="number" id="inp_rate" class="puri-input bg-gray" value="0" readonly>
                                        <input type="hidden" id="base_rate_hidden" value="0">
                                    </div>
                                    <div class="col">
                                        <label class="small-label">Total Rupiah (IDR)</label>
                                        <input type="text" id="inp_total_idr" class="puri-input bg-gray" value="Rp 0" readonly>
                                    </div>
                                </div>

                                <input type="hidden" id="inp_denom" value="0">
                                <input type="hidden" id="current_stock" value="0">
                                <input type="hidden" id="is_finance" value="<?php echo $is_finance_or_admin ? '1' : '0'; ?>">

                                <button type="button" id="btn_add_cart" class="button button-primary button-large full-width" disabled>
                                    <i class="fa-solid fa-plus"></i> Masukkan Keranjang
                                </button>
                                <div id="stock_warning" class="text-red hidden" style="text-align:center; font-size:11px; margin-top:5px;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Melebihi Stok Laci!
                                </div>

                            </div>
                        </div>

                    </div>

                    <div class="flex-col col-right-cart">
                        <div class="panel panel-cart">
                            <div class="panel-header"><i class="fa-solid fa-cart-shopping"></i> 1.2 Keranjang (Cart)</div>
                            <div class="panel-body cart-scroll">
                                <table class="wp-list-table widefat fixed striped" id="cart_table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th width="60" class="tc">Qty</th>
                                            <th class="tr">Riyal</th>
                                            <th class="tr">IDR</th>
                                            <th width="40" class="tc">#</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="empty-cart"><td colspan="5" align="center" style="padding: 30px; color:#999;">Keranjang masih kosong</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="panel-footer cart-summary-box">
                                <div class="summary-line"><span>Subtotal Riyal</span><span id="cart_total_riyal" class="val-riyal">0</span></div>
                                <div class="summary-line main"><span>Grand Total (IDR)</span><span id="cart_total_idr" class="val-idr">Rp 0</span></div>
                                <div class="checkout-area">
                                    <label class="chk-valid"><input type="checkbox" id="chk_valid" checked> Data & Uang sudah benar</label>
                                    <button type="button" id="btn_checkout" class="button button-primary button-hero"><i class="fa-solid fa-cash-register"></i> PROSES CHECKOUT</button>
                                </div>
                            </div>
                        </div>
                    </div> 
                </div> 

                <div class="flex-row bottom-row">
                    <div class="flex-col col-history">
                        <div class="panel panel-history">
                            <div class="panel-header"><span><i class="fa-solid fa-clock-rotate-left"></i> Mutasi Harian</span><button class="button button-small" id="btn_refresh_history"><i class="fa-solid fa-sync"></i></button></div>
                            <div class="panel-body table-scroll"><table class="wp-list-table widefat striped dense" id="history_table"><thead><tr><th>Jam</th><th>Ref ID</th><th class="tr">Riyal</th><th class="tr">IDR</th><th width="30"></th></tr></thead><tbody></tbody></table></div>
                        </div>
                    </div>
                    <div class="flex-col col-stock">
                        <div class="panel panel-stock">
                            <div class="panel-header"><span><i class="fa-solid fa-boxes-stacked"></i> Stok Laci & Ikhtisar</span></div>
                            <div class="panel-body table-scroll"><table class="wp-list-table widefat striped dense" id="stock_table"><thead><tr><th>SKU</th><th class="tr">Sisa Stok</th><th class="tr">Sales Today</th></tr></thead><tbody></tbody></table></div>
                        </div>
                    </div>
                </div> 
            </div>
        </div>

        <style>
            .puri-cockpit-wrapper { box-sizing: border-box; padding-top: 10px; }
            .cockpit-container { display: flex; flex-direction: column; gap: 15px; margin-top: 15px; }
            .flex-row { display: flex; gap: 15px; width: 100%; flex-wrap: wrap; }
            .flex-col { display: flex; flex-direction: column; gap: 15px; }
            .flex-between { display: flex; justify-content: space-between; align-items: center; }
            .tr { text-align: right; } .tc { text-align: center; } .mb-2 { margin-bottom: 10px; } .mb-1 { margin-bottom: 8px; } .mt-1 { margin-top: 5px; }
            .hidden { display: none; }
            .text-red { color: #d63638; } .text-blue { color: #2271b1; font-weight: bold; }

            .col-left-input { flex: 0 0 380px; } 
            .col-right-cart { flex: 1; min-width: 400px; } 
            .col-history { flex: 4; }
            .col-stock { flex: 6; }

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

            .radio-group { display: flex; gap: 15px; background: #f0f0f1; padding: 8px; border-radius: 4px; border: 1px solid #dcdcde; }
            .radio-label { font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 5px; }
            .badge { display: inline-block; padding: 2px 8px; background: #2271b1; color: white; border-radius: 3px; font-size: 10px; margin-top: 5px; }

            .form-row { display: flex; gap: 10px; }
            .form-row .col { flex: 1; }
            
            .cart-scroll { overflow-y: auto; height: 320px; padding: 0; border-bottom: 1px solid #eee; }
            #cart_table th { position: sticky; top: 0; z-index: 10; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
            .cart-summary-box { background: #fafafa; padding: 20px; }
            .summary-line { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
            .summary-line.main { font-size: 18px; font-weight: bold; border-top: 1px dashed #ccc; padding-top: 12px; margin-top: 8px; }
            .val-idr { color: #d63638; }
            .button-hero { height: 45px !important; font-size: 15px !important; width: 100%; margin-top: 15px !important; justify-content: center; display: flex; gap: 8px; align-items: center; }
            .table-scroll { max-height: 250px; overflow-y: auto; padding: 0; }
            
            @media (max-width: 1000px) {
                .flex-row { flex-direction: column; }
                .col-left-input, .col-right-cart { width: 100%; flex: auto; }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            
            const Cockpit = { cart: [] };

            function init() {
                loadStockAndHistory();
                setupListeners();
                $('#item_select').select2({ placeholder: "Pilih Item...", width: '100%' });
                $('#customer_select').select2({ placeholder: "Pilih Customer...", width: '100%', allowClear: true });
            }

            function setupListeners() {
                $('input[name="cust_type"]').change(function() {
                    let type = $(this).val();
                    if(type === 'registered') { $('#box_registered').slideDown(); $('#box_walkin').slideUp(); } 
                    else { $('#box_registered').slideUp(); $('#box_walkin').slideDown(); $('#customer_select').val(null).trigger('change'); }
                });

                $('#customer_select').on('select2:select', function (e) {
                    let opt = $(this).find(':selected');
                    let type = (opt.data('type') || 'Member').toLowerCase();
                    $('#cust_badge').text(type.toUpperCase()).removeClass('hidden');
                    checkRateEditable(type);
                });
                
                $('#customer_select').on('select2:clear', function (e) {
                    $('#cust_badge').addClass('hidden').text('');
                    checkRateEditable('');
                });

                $('#item_select').on('select2:select', function(e) {
                    let opt = $(this).find(':selected');
                    let denom = parseFloat(opt.data('denom')) || 0;
                    let rate = parseFloat(opt.data('rate')) || 0;
                    let stock = parseFloat(opt.data('stock')) || 0;
                    let img = opt.data('img') || '<?php echo plugin_dir_url(__FILE__) . 'assets/img/no-image.png'; ?>';

                    $('#inp_denom').val(denom);
                    $('#base_rate_hidden').val(rate);
                    $('#current_stock').val(stock);
                    $('#item_img_preview').attr('src', img);
                    
                    $('#inp_rate').val(rate);
                    $('#info_base_rate').text(rate.toLocaleString());
                    $('#inp_qty').val('').focus();
                    $('#inp_total_riyal').val('');
                    $('#inp_total_idr').val('Rp 0');
                    
                    checkStockLock();
                });

                $('#inp_qty').on('input', function() {
                    let qty = parseFloat($(this).val()) || 0;
                    let denom = parseFloat($('#inp_denom').val()) || 0;
                    let riyal = qty * denom;
                    $('#inp_total_riyal').val(riyal > 0 ? riyal : '');
                    calcFinalIDR(); checkStockLock();
                });

                $('#inp_total_riyal').on('input', function() {
                    let riyal = parseFloat($(this).val()) || 0;
                    let denom = parseFloat($('#inp_denom').val()) || 0;
                    if(denom > 0) {
                        let qty = riyal / denom;
                        $('#inp_qty').val(qty > 0 ? qty : '');
                    }
                    calcFinalIDR(); checkStockLock();
                });

                $('#inp_rate').on('input', function() { calcFinalIDR(); });
                $('#btn_clear_form').click(function() { $('#item_select').val(null).trigger('change'); $('#inp_qty').val(''); $('#inp_total_riyal').val(''); $('#inp_total_idr').val('Rp 0'); });
                $('#btn_add_cart').click(addToCart);
                $('#inp_qty').keypress(function(e){ if(e.which == 13 && !$('#btn_add_cart').prop('disabled')) $('#btn_add_cart').click(); });
                $(document).on('click', '.btn-remove-item', function() { Cockpit.cart.splice($(this).data('index'), 1); renderCart(); });
                $('#btn_checkout').click(handleCheckout);
                $('#btn_refresh_history').click(loadStockAndHistory);
            }

            function checkRateEditable(custType) {
                let isFinance = $('#is_finance').val() === '1';
                let isAgen = (custType === 'agen' || custType === 'agent');
                if(isFinance || isAgen) { $('#inp_rate').prop('readonly', false).removeClass('bg-gray').addClass('highlight-input'); } 
                else { $('#inp_rate').prop('readonly', true).addClass('bg-gray').removeClass('highlight-input'); $('#inp_rate').val($('#base_rate_hidden').val()); calcFinalIDR(); }
            }

            function calcFinalIDR() {
                let riyal = parseFloat($('#inp_total_riyal').val()) || 0;
                let rate = parseFloat($('#inp_rate').val()) || 0;
                let idr = riyal * rate;
                $('#inp_total_idr').val('Rp ' + idr.toLocaleString('id-ID'));
            }

            function checkStockLock() {
                let qty = parseFloat($('#inp_qty').val()) || 0;
                let stock = parseFloat($('#current_stock').val()) || 0;
                let btn = $('#btn_add_cart');
                let warn = $('#stock_warning');
                if(qty > 0 && qty <= stock) { btn.prop('disabled', false); warn.addClass('hidden'); } 
                else if (qty > stock) { btn.prop('disabled', true); warn.removeClass('hidden'); } 
                else { btn.prop('disabled', true); warn.addClass('hidden'); }
            }

            function addToCart() {
                let itemId = $('#item_select').val();
                let itemName = $('#item_select option:selected').text();
                let denom = parseFloat($('#inp_denom').val());
                let rate = parseFloat($('#inp_rate').val());
                let qty = parseFloat($('#inp_qty').val());
                let riyal = parseFloat($('#inp_total_riyal').val());
                let idr = riyal * rate; 
                if(!itemId || qty <= 0) return;
                Cockpit.cart.push({ id: itemId, name: itemName.split('(')[0], denom: denom, rate: rate, qty: qty, riyal: riyal, idr: idr });
                $('#btn_clear_form').click(); renderCart();
            }

            function renderCart() {
                let tbody = $('#cart_table tbody'); tbody.empty();
                let sumRiyal = 0; let sumIDR = 0;
                if(Cockpit.cart.length === 0) { tbody.html('<tr class="empty-cart"><td colspan="5" align="center" style="padding:20px; color:#aaa;">Keranjang kosong</td></tr>'); } 
                else {
                    Cockpit.cart.forEach((item, index) => {
                        sumRiyal += item.riyal; sumIDR += item.idr;
                        tbody.append(`<tr><td>${item.name}</td><td class="tc">${item.qty}</td><td class="tr">${item.riyal.toLocaleString()}</td><td class="tr">${item.idr.toLocaleString()}</td><td class="tc"><button class="button button-small btn-remove-item" data-index="${index}"><i class="fa fa-times" style="color:red"></i></button></td></tr>`);
                    });
                }
                $('#cart_total_riyal').text(sumRiyal.toLocaleString('en-US'));
                $('#cart_total_idr').text('Rp ' + sumIDR.toLocaleString('id-ID'));
            }

            function handleCheckout() {
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
                let totalStr = $('#cart_total_idr').text();
                Swal.fire({ title: 'Terima Pembayaran?', text: `Total: ${totalStr}. Uang sudah diterima?`, icon: 'question', showCancelButton: true, confirmButtonText: 'Ya, Proses!', confirmButtonColor: '#2271b1' }).then((res) => { if (res.isConfirmed) processTransaction(mode, custData); });
            }

            function processTransaction(mode, custData) {
                Swal.fire({title: 'Memproses...', text: 'Mengupload data...', didOpen: () => Swal.showLoading()});
                let formData = new FormData();
                formData.append('action', 'puri_pos_checkout');
                formData.append('nonce', '<?php echo wp_create_nonce("puri_pos_checkout"); ?>');
                formData.append('cart', JSON.stringify(Cockpit.cart));
                formData.append('cust_mode', mode);
                if(mode === 'walkin') {
                    formData.append('wic_name', custData.name); formData.append('wic_nik', custData.nik); formData.append('wic_phone', custData.phone); formData.append('wic_address', custData.address); formData.append('wic_ktp', $('#wic_ktp')[0].files[0]);
                } else { formData.append('cust_id', custData.id); }
                $.ajax({ url: ajaxurl, type: 'POST', data: formData, processData: false, contentType: false, success: function(response) { if(response.success) { Swal.fire('Sukses!', 'Ref: ' + response.data.ref_id, 'success'); Cockpit.cart = []; $('#wic_name').val(''); $('#wic_nik').val(''); $('#wic_phone').val(''); $('#wic_address').val(''); $('#wic_ktp').val(''); $('#btn_clear_form').click(); renderCart(); loadStockAndHistory(); } else { Swal.fire('Gagal', response.data, 'error'); } }, error: function() { Swal.fire('Error', 'Server Error', 'error'); } });
            }

            function loadStockAndHistory() {
                $.get(ajaxurl, { action: 'puri_pos_get_stock_summary' }, function(res){ if(res.success) { let html = ''; res.data.forEach(s => { html += `<tr><td>${s.name}</td><td class="tr"><strong>${s.qty}</strong></td><td class="tr">${s.sales_today}</td></tr>`; }); $('#stock_table tbody').html(html); } });
                $.get(ajaxurl, { action: 'puri_pos_get_daily_mutation' }, function(res){ if(res.success) { let html = ''; res.data.forEach(m => { html += `<tr><td>${m.time}</td><td>${m.ref_id}</td><td class="tr">${m.total_riyal}</td><td class="tr">${m.total_idr}</td><td><i class="fa fa-check" style="color:green"></i></td></tr>`; }); $('#history_table tbody').html(html); } });
            }
            init();
        });
        </script>
        <?php
    }

    /**
     * ------------------------------------------------
     * BACKEND HELPER (FIX STOCK LOGIC + CUST KEY)
     * ------------------------------------------------
     */
    
    private function get_items_for_dropdown() {
        global $wpdb;
        $posts = get_posts(['post_type'=>'pr_item', 'posts_per_page'=>-1, 'post_status'=>'publish', 'orderby'=>'title', 'order'=>'ASC']);
        $results = [];
        $tbl_stock = puri_table_name('T_STOCK');
        $tbl_items = puri_table_name('T_ITEMS');

        foreach($posts as $p) {
            $denom = get_post_meta($p->ID, '_puri_denom', true) ?: 0;
            $rate = get_post_meta($p->ID, '_puri_sell_rate', true) ?: 0;
            $img = get_the_post_thumbnail_url($p->ID, 'thumbnail');
            
            // --- FIX STOCK LOGIC (v6.1.0) ---
            $sku = get_post_meta($p->ID, 'item_sku_code', true); 
            if (!$sku) $sku = get_post_meta($p->ID, '_puri_item_sku', true); // Fallback

            $stock = 0;
            if ($sku && $tbl_stock && $tbl_items) {
                // Query: T_STOCK JOIN T_ITEMS
                $query = "SELECT s.balance FROM $tbl_stock s INNER JOIN $tbl_items i ON s.item_id = i.id WHERE i.sku = %s AND s.location_id = 'laci_kasir' LIMIT 1";
                $stock = $wpdb->get_var($wpdb->prepare($query, $sku));
            }

            $p->denom = $denom;
            $p->sell_rate = $rate;
            $p->stock_laci = $stock ?: 0;
            $p->img_url = $img;
            $results[] = $p;
        }

        usort($results, function($a, $b) { return $a->denom <=> $b->denom; });
        return $results;
    }

    private function get_customers_for_dropdown() {
        $posts = get_posts(['post_type' => 'pr_customer', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $results = [];
        foreach($posts as $p) {
            $type = get_post_meta($p->ID, '_puri_cust_type', true);
            if(empty($type)) $type = get_post_meta($p->ID, 'cust_type', true);
            if(empty($type)) $type = get_post_meta($p->ID, 'type', true);
            if(empty($type)) $type = 'Member';
            
            $phone = get_post_meta($p->ID, '_puri_cust_phone', true);
            if(empty($phone)) $phone = get_post_meta($p->ID, 'cust_phone', true);
            if(empty($phone)) $phone = '-';
            
            $p->type = $type; $p->phone = $phone; $results[] = $p;
        }
        return $results;
    }

    // === CHECKOUT BACKEND ===
    public function ajax_process_checkout() {
        check_ajax_referer('puri_pos_checkout', 'nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $cart = json_decode(stripslashes($_POST['cart']), true);
        $cust_mode = sanitize_text_field($_POST['cust_mode']);
        if(empty($cart)) wp_send_json_error('Keranjang kosong');

        $wpdb->query('START TRANSACTION');
        try {
            $customer_id = 0; $customer_name = '';

            if($cust_mode === 'walkin') {
                $wic_name = sanitize_text_field($_POST['wic_name']);
                $wic_nik = sanitize_text_field($_POST['wic_nik']);
                $wic_phone = sanitize_text_field($_POST['wic_phone']);
                $wic_address = sanitize_textarea_field($_POST['wic_address']);

                $customer_id = wp_insert_post(['post_type'=>'pr_customer', 'post_title'=>$wic_name . ' (Walk-in)', 'post_status'=>'publish']);
                if(is_wp_error($customer_id)) throw new Exception('Gagal membuat data pelanggan.');

                update_post_meta($customer_id, '_puri_cust_type', 'umum'); 
                update_post_meta($customer_id, '_puri_cust_phone', $wic_phone);
                update_post_meta($customer_id, '_puri_cust_nik', $wic_nik);
                update_post_meta($customer_id, '_puri_cust_address', $wic_address);

                if (!empty($_FILES['wic_ktp']['name'])) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    $attachment_id = media_handle_upload('wic_ktp', $customer_id);
                    if (is_wp_error($attachment_id)) throw new Exception('Gagal upload KTP: ' . $attachment_id->get_error_message());
                    update_post_meta($customer_id, '_puri_cust_ktp_image', wp_get_attachment_url($attachment_id));
                }
                $customer_name = $wic_name;
            } else {
                $customer_id = intval($_POST['cust_id']);
                $customer_name = get_the_title($customer_id);
            }

            $ref_id = 'POS-' . date('YmdHis') . '-' . rand(100,999);
            $trx_date = current_time('mysql');
            $total_riyal = 0; $total_idr = 0;

            foreach($cart as $item) {
                // ... (Logic Checkout using T_ITEMS mapping for Stock update) ...
                $item_id = intval($item['id']); // This is Post ID
                
                // Need to find SQL ID first for stock update
                $sku = get_post_meta($item_id, 'item_sku_code', true);
                if(!$sku) $sku = get_post_meta($item_id, '_puri_item_sku', true);
                
                $sql_id = 0;
                if($sku) {
                    $sql_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . puri_table_name('T_ITEMS') . " WHERE sku = %s", $sku));
                }
                if(!$sql_id) throw new Exception("SKU tidak ditemukan di sistem untuk item $item_id");

                $qty = floatval($item['qty']);
                $denom = floatval($item['denom']);
                $rate = floatval($item['rate']);
                
                $tbl_stock = puri_table_name('T_STOCK');
                $curr = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $tbl_stock WHERE item_id = %d AND location_id = 'laci_kasir' FOR UPDATE", $sql_id));
                
                if($curr < $qty) throw new Exception("Stok fisik kurang untuk SKU: $sku (Sisa: $curr)");

                $wpdb->query($wpdb->prepare("UPDATE $tbl_stock SET balance = balance - %f, last_updated = %s WHERE item_id = %d AND location_id = 'laci_kasir'", $qty, $trx_date, $sql_id));
                
                $wpdb->insert(puri_table_name('T_LEDGER'), [
                    'location_id' => 'laci_kasir', 'item_id' => $sql_id, 'qty_change' => -$qty,
                    'ref_id' => $ref_id, 'description' => "POS Sales to $customer_name", 'trx_date' => $trx_date
                ]);

                $total_riyal += ($item['riyal']);
                $total_idr += ($item['idr']);
            }

            $snapshot = ['items' => $cart, 'customer_id' => $customer_id, 'customer_name' => $customer_name, 'total_riyal' => $total_riyal, 'total_idr' => $total_idr];
            $wpdb->insert(puri_table_name('T_JOURNAL'), [
                'ref_id' => $ref_id, 'trx_date' => $trx_date, 'description' => "POS Sales: $customer_name",
                'amount_idr' => $total_idr, 'snapshot_json' => json_encode($snapshot), 'status' => 'completed'
            ]);

            $wpdb->query('COMMIT');
            if(function_exists('puri_send_to_external_webhook')) puri_send_to_external_webhook($ref_id);
            wp_send_json_success(['ref_id' => $ref_id]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_get_daily_mutation() {
        global $wpdb;
        $today = date('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ref_id, trx_date, snapshot_json FROM " . puri_table_name('T_JOURNAL') . " WHERE trx_date LIKE %s ORDER BY trx_date DESC", $today.'%'));
        $data = [];
        foreach($rows as $r) {
            $json = json_decode($r->snapshot_json, true);
            $data[] = ['time' => date('H:i', strtotime($r->trx_date)), 'ref_id' => $r->ref_id, 'total_riyal' => number_format($json['total_riyal'] ?? 0), 'total_idr' => number_format($json['total_idr'] ?? 0)];
        }
        wp_send_json_success($data);
    }

    public function ajax_get_stock_summary() {
        global $wpdb;
        $today = date('Y-m-d');
        // Fix: Join with T_STOCK correctly using SQL ID mapping if needed, but T_STOCK item_id is SQL ID.
        // T_ITEMS has name. We need to join T_STOCK s -> T_ITEMS i.
        $sql = "SELECT i.name as name, s.balance as qty, i.id as item_id 
                FROM " . puri_table_name('T_STOCK') . " s
                JOIN " . puri_table_name('T_ITEMS') . " i ON s.item_id = i.id
                WHERE s.location_id = 'laci_kasir' ORDER BY i.name ASC";
        $results = $wpdb->get_results($sql);
        $final = [];
        foreach($results as $row) {
            $sales = $wpdb->get_var($wpdb->prepare("SELECT SUM(qty_change) FROM " . puri_table_name('T_LEDGER') . " WHERE location_id='laci_kasir' AND item_id = %d AND trx_date LIKE %s AND qty_change < 0", $row->item_id, $today.'%'));
            $final[] = ['name' => $row->name, 'qty' => number_format($row->qty), 'sales_today' => number_format(abs($sales ?? 0))];
        }
        wp_send_json_success($final);
    }
}

new Puri_Cockpit_POS();