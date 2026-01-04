<?php
/**
 * =============================================================================
 * MC 04 - Procurement Hub (Kulakan / Pembelian Stok)
 * =============================================================================
 * 
 * @package     Pusat Riyal
 * @module      MC-04
 * @version     6.0.8
 * @author      Denmas Totok (refactor by Copilot)
 * @updated     2026-01-04
 * 
 * =============================================================================
 * FEATURES
 * =============================================================================
 * 
 *   [1] Dual input mode:  By Nominal / By Quantity
 *   [2] Dynamic column order & width via CSS class
 *   [3] Multi-source payment (Kas Laci + Bank)
 *   [4] Real-time balance validation
 *   [5] Auto-calculate:  Total IDR = Total SAR × Kurs
 *   [6] Submit enabled only when balanced & confirmed
 * 
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 * 
 * [6.0.8] 2026-01-04
 *   - Merged: CSS order swap + grid-template-columns per mode
 *   - Fixed:  Tab switching dengan onclick
 *   - Fixed: Column wrapper (.col-sku, .col-qty, etc)
 *   - Fixed: Enable/disable logic per mode
 *   - Maintained: Original calculation logic
 * 
 * =============================================================================
 */

defined('ABSPATH') || exit;

/**
 * Register submenu
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'puri-transaksi',
        'Procurement / Kulakan',
        '📦 Kulakan Brot',
        'manage_options',
        'puri-procurement',
        'puri_render_procurement_page'
    );
}, 20);

/**
 * Render Procurement Page
 */
function puri_render_procurement_page() {
    puri_check_cap('manage_options');

    global $wpdb;
    
    $items_table = puri_table_name('T_ITEMS');
    $items = $wpdb->get_results(
        "SELECT id, sku, name, denom_value, base_price 
         FROM {$items_table} 
         WHERE type = 'currency' 
         ORDER BY denom_value ASC"
    );
    
    $vendors = get_posts([
        'post_type'      => 'pr_vendor',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC'
    ]);
    
    $chart_table = puri_table_name('T_CHART');
    $banks = $wpdb->get_results(
        "SELECT code, name FROM {$chart_table} WHERE is_cash = 1 ORDER BY code ASC"
    );

    // Display notices
    if (isset($_GET['puri_procure_ok'])) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html(urldecode($_GET['puri_procure_ok'])) . '</p></div>';
    }
    if (isset($_GET['puri_procure_err'])) {
        echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html(urldecode($_GET['puri_procure_err'])) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>📦 Form Kulakan / Pembelian Stok</h1>

        <style>
            /* ═══════════════════════════════════════════════════════════════
               LAYOUT CONTAINER
               ═══════════════════════════════════════════════════════════════ */
            .proc-container { 
                display: grid; 
                grid-template-columns: 220px 1fr 380px; 
                gap: 20px; 
                margin-top: 20px; 
            }
            .proc-panel { 
                background: #fff; 
                border: 1px solid #d1d5db; 
                border-radius: 8px; 
                padding:  16px; 
            }
            .proc-listbox { 
                width: 100%; 
                height: 400px; 
                border: 1px solid #cbd5e1; 
                border-radius: 4px; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               TABS
               ═══════════════════════════════════════════════════════════════ */
            .proc-tabs { 
                display: flex; 
                gap: 8px; 
                margin-bottom: 16px; 
                border-bottom: 2px solid #e5e7eb; 
                padding-bottom: 8px; 
            }
            .proc-tab { 
                padding: 8px 16px; 
                background: #f1f5f9; 
                border: none; 
                cursor: pointer; 
                border-radius: 4px 4px 0 0; 
                font-weight:  600; 
            }
            .proc-tab:hover { 
                background: #e2e8f0; 
            }
            .proc-tab.active { 
                background: #7c3aed; 
                color: #fff; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               ITEM ROW - Base + Mode-specific Grid
               ═══════════════════════════════════════════════════════════════ */
            .proc-row { 
                display: grid; 
                grid-template-columns: 140px 120px 100px 100px 140px 50px; 
                gap: 8px; 
                align-items: center; 
                margin-bottom: 8px; 
            }
            
            /* Mode Nominal:  [Item 140] [TotalSAR 128] [Kurs 100] [Qty 75] [TotalIDR 140] [Action 50] */
            .proc-row.nominal { 
                grid-template-columns: 140px 110px 90px 75px 140px 50px; 
            }
            
            /* Mode Quantity: [Item 140] [Qty 75] [Kurs 100] [TotalSAR 128] [TotalIDR 140] [Action 50] */
            .proc-row.quantity { 
                grid-template-columns: 140px 75px 90px 110px 140px 50px; 
            }
            
            .proc-row input, .proc-row select { 
                padding:  6px; 
                border: 1px solid #cbd5e1; 
                border-radius: 4px; 
                text-align: right; 
                width: 100%; 
                box-sizing: border-box; 
            }
            .proc-row select { 
                text-align: left; 
            }
            .proc-row .readonly { 
                background: #f8fafc; 
                color: #64748b; 
                border-color: #e2e8f0; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               COLUMN ORDER - CSS Flexbox Order untuk Swap
               ═══════════════════════════════════════════════════════════════ */
            .col-sku    { order: 1; }
            .col-riyal  { order: 2; }
            .col-kurs   { order: 3; }
            .col-qty    { order: 4; }
            .col-idr    { order: 5; }
            .col-action { order: 6; }

            /* Mode Quantity: Swap Qty (order 2) dan Riyal (order 4) */
            .proc-row.quantity .col-qty   { order: 2; }
            .proc-row.quantity .col-riyal { order: 4; }
            
            /* ═══════════════════════════════════════════════════════════════
               BUTTONS
               ═══════════════════════════════════════════════════════════════ */
            .btn-remove { 
                color: #ef4444; 
                border: 1px solid #ef4444; 
                background: #fff; 
                cursor: pointer; 
                border-radius: 4px; 
                font-weight: bold; 
                padding: 4px 10px;
                font-size: 14px;
            }
            .btn-remove:hover {
                background: #fef2f2;
            }
            
            /* ═══════════════════════════════════════════════════════════════
               PAYMENT ROW
               ═══════════════════════════════════════════════════════════════ */
            .proc-payment-row { 
                display: grid; 
                grid-template-columns:  1fr 140px 50px; 
                gap: 8px; 
                align-items: center; 
                margin-bottom: 8px; 
            }
            .proc-payment-row select, 
            .proc-payment-row input { 
                padding: 6px; 
                border:  1px solid #cbd5e1; 
                border-radius: 4px; 
            }
            .proc-payment-row input { 
                text-align: right; 
                font-weight: 700; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               SUMMARY
               ═══════════════════════════════════════════════════════════════ */
            .proc-summary { 
                background: #f8fafc; 
                padding: 12px; 
                border-radius: 6px; 
                margin: 12px 0; 
            }
            .proc-summary-row { 
                display: flex; 
                justify-content: space-between; 
                padding:  6px 0; 
            }
            .proc-summary-row.total { 
                font-weight: 900; 
                font-size: 18px; 
                border-top: 2px solid #0f172a; 
                padding-top: 10px; 
                margin-top: 8px; 
            }
            .proc-summary-row.selisih { 
                color: #ef4444; 
                font-weight: 700; 
            }
            .proc-summary-row.selisih.zero { 
                color: #10b981; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               SUBMIT BUTTON
               ═══════════════════════════════════════════════════════════════ */
            .btn-submit { 
                width: 100%; 
                padding: 12px; 
                background: #059669; 
                color: #fff; 
                border: none; 
                border-radius: 6px; 
                font-weight:  900; 
                cursor:  pointer; 
                font-size: 16px; 
            }
            .btn-submit:hover: not(:disabled) {
                background: #047857;
            }
            .btn-submit:disabled { 
                background: #cbd5e1; 
                cursor:  not-allowed; 
            }
            .btn-submit.processing { 
                background: #6b7280; 
            }
            
            /* ═══════════════════════════════════════════════════════════════
               MISC
               ═══════════════════════════════════════════════════════════════ */
            .history-table { 
                margin-top: 24px; 
            }
            .vendor-required { 
                border-color: #ef4444 ! important; 
            }
        </style>

        <!-- FORM MENCAKUP SEMUA PANEL -->
        <form method="post" id="proc_form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('puri_procure_action', 'puri_procure_nonce'); ?>
            <input type="hidden" name="action" value="puri_procure_submit">
            <input type="hidden" name="mode" id="proc_mode" value="nominal">
            <input type="hidden" name="vendor_id" id="vendor_id_hidden" value="">

            <div class="proc-container">
                
                <!-- ═══════════════════════════════════════════════════════════
                     PANEL 1: VENDOR LIST
                     ═══════════════════════════════════════════════════════════ -->
                <div class="proc-panel">
                    <h3 style="margin-top: 0">Vendor / Supplier <span style="color:#ef4444">*</span></h3>
                    <select id="vendor_listbox" class="proc-listbox" size="20">
                        <option value="">-- Pilih Vendor --</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo esc_attr($v->ID); ?>"><?php echo esc_html($v->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="margin-top: 8px; color:#6b7280; font-size:  12px;">
                        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=pr_vendor')); ?>" target="_blank">+ Tambah Vendor Baru</a>
                    </p>
                </div>

                <!-- ═══════════════════════════════════════════════════════════
                     PANEL 2: DETAIL TRANSAKSI
                     ═══════════════════════════════════════════════════════════ -->
                <div class="proc-panel">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px">
                        <h2 style="margin:  0">Detail Transaksi</h2>
                        <div style="color:#6b7280"><?php echo esc_html(date_i18n('l, d F Y')); ?></div>
                    </div>

                    <!-- TABS -->
                    <div class="proc-tabs">
                        <button type="button" class="proc-tab active" id="tab_nominal">By Nominal</button>
                        <button type="button" class="proc-tab" id="tab_qty">By Quantity</button>
                    </div>

                    <!-- HEADER ROW -->
                    <div id="header_row" class="proc-row nominal" style="font-weight: 700; background:#f1f5f9; padding: 8px 4px; border-radius: 4px;">
                        <div class="col-sku">Item</div>
                        <div class="col-riyal" style="text-align: right">Total SAR</div>
                        <div class="col-kurs" style="text-align: right">Kurs Beli</div>
                        <div class="col-qty" style="text-align: right">Qty (pcs)</div>
                        <div class="col-idr" style="text-align: right">Total IDR</div>
                        <div class="col-action"></div>
                    </div>

                    <!-- DATA ROWS CONTAINER -->
                    <div id="proc_rows"></div>

                    <button type="button" id="btn_add_row" class="button" style="margin-top: 8px">+ Tambah Item</button>

                    <!-- SUMMARY -->
                    <div class="proc-summary">
                        <div class="proc-summary-row total">
                            <span>Total Pembelian</span>
                            <span>Rp <span id="total_pembelian">0</span></span>
                        </div>
                    </div>

                    <!-- CONFIRM -->
                    <div style="display: flex; align-items: center; gap: 8px; margin:  12px 0">
                        <input type="checkbox" id="confirm_data" name="confirm_ok" value="1">
                        <label for="confirm_data" style="font-weight: 600">Data sudah benar dan siap diproses</label>
                    </div>

                    <!-- SUBMIT -->
                    <button type="submit" id="btn_submit" class="btn-submit" disabled>
                        SUBMIT PEMBELIAN
                    </button>
                </div>

                <!-- ═══════════════════════════════════════════════════════════
                     PANEL 3: SUMBER PEMBAYARAN
                     ═══════════════════════════════════════════════════════════ -->
                <div class="proc-panel">
                    <h3 style="margin-top: 0">Sumber Pembayaran <span style="color:#ef4444">*</span></h3>
                    
                    <div id="payment_sources"></div>

                    <button type="button" id="btn_add_payment" class="button" style="width: 100%; margin-top: 8px">+ Tambah Sumber</button>

                    <div class="proc-summary" style="margin-top: 16px">
                        <div class="proc-summary-row">
                            <span>Total Dibayar</span>
                            <span>Rp <span id="total_bayar">0</span></span>
                        </div>
                        <div class="proc-summary-row selisih" id="selisih_row">
                            <span>Selisih</span>
                            <span id="selisih_amount">Rp 0</span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 12px; padding: 10px; background: #fef3c7; border-radius: 6px; font-size: 12px;">
                        <strong>⚠️ Penting: </strong> Total pembayaran harus sama dengan total pembelian. 
                    </div>
                </div>
            </div>
        </form>

        <!-- ═══════════════════════════════════════════════════════════════════
             HISTORY TABLE
             ═══════════════════════════════════════════════════════════════════ -->
        <div class="proc-panel history-table">
            <h3>📋 Riwayat Pembelian Bulan Ini</h3>
            <?php
            $journal_table = puri_table_name('T_JOURNAL');
            $current_month = date_i18n('Y-m');
            
            $hist = $wpdb->get_results($wpdb->prepare("
                SELECT ref_id, MIN(trx_date) as trx_date, MIN(description) as description, 
                       SUM(debit) as total_debit
                FROM {$journal_table}
                WHERE account_code = '1401' 
                  AND DATE_FORMAT(trx_date, '%%Y-%%m') = %s
                  AND ref_id LIKE 'PRO-%%'
                GROUP BY ref_id
                ORDER BY MIN(trx_date) DESC
                LIMIT 10
            ", $current_month));
            ?>
            <table class="widefat striped" style="table-layout: fixed; width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 140px;">Tanggal</th>
                        <th style="width: 160px;">Kode Ref</th>
                        <th>Deskripsi</th>
                        <th style="width: 150px; text-align: right;">Total (IDR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hist)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color:  #6b7280; padding: 20px;">
                                Belum ada pembelian bulan ini. 
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($hist as $h): ?>
                        <tr>
                            <td style="vertical-align: top;"><?php echo esc_html(date_i18n('d M Y, H:i', strtotime($h->trx_date))); ?></td>
                            <td style="vertical-align: top;"><code><?php echo esc_html($h->ref_id); ?></code></td>
                            <td style="word-wrap: break-word; white-space: normal; vertical-align: top;">
                                <?php echo esc_html($h->description); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; vertical-align: top;">
                                Rp <?php echo number_format_i18n($h->total_debit); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         JAVASCRIPT
         ═══════════════════════════════════════════════════════════════════════ -->
    <script>
    (function(){
        'use strict';

        // ═══════════════════════════════════════════════════════════════
        // DATA FROM PHP
        // ═══════════════════════════════════════════════════════════════
        
        var items = <?php echo wp_json_encode(array_map(function($item) {
            return [
                'id'          => intval($item->id),
                'sku'         => $item->sku,
                'denom_value' => intval($item->denom_value),
                'base_price'  => floatval($item->base_price)
            ];
        }, $items)); ?>;
        
        var banks = <?php echo wp_json_encode(array_map(function($bank) {
            return [
                'code' => $bank->code,
                'name' => $bank->name
            ];
        }, $banks)); ?>;

        // ═══════════════════════════════════════════════════════════════
        // DOM ELEMENTS
        // ═══════════════════════════════════════════════════════════════
        
        var rowsContainer    = document.getElementById('proc_rows');
        var paymentsContainer = document.getElementById('payment_sources');
        var headerRow        = document.getElementById('header_row');
        var modeInput        = document.getElementById('proc_mode');
        var vendorListbox    = document.getElementById('vendor_listbox');
        var vendorHidden     = document.getElementById('vendor_id_hidden');
        var totalPembelianEl = document.getElementById('total_pembelian');
        var totalBayarEl     = document.getElementById('total_bayar');
        var selisihEl        = document.getElementById('selisih_amount');
        var selisihRow       = document.getElementById('selisih_row');
        var submitBtn        = document.getElementById('btn_submit');
        var confirmChk       = document.getElementById('confirm_data');
        var procForm         = document.getElementById('proc_form');

        var mode = 'nominal';
        var rowCounter = 0;
        var paymentCounter = 0;

        // ═══════════════════════════════════════════════════════════════
        // UTILITY FUNCTIONS
        // ═══════════════════════════════════════════════════════════════
        
        function formatNumber(n) {
            return new Intl.NumberFormat('id-ID').format(Math.round(parseFloat(n || 0)));
        }
        
        function parseNumber(v) {
            v = String(v || '').replace(/[^\d,.\-]/g, '');
            // Handle format Indonesia:  1.000.000 atau 1.000,50
            if (v.indexOf(',') !== -1) {
                // Ada koma = decimal separator Indonesia
                v = v.replace(/\./g, '').replace(',', '.');
            } else {
                // Hanya titik = thousand separator
                v = v.replace(/\./g, '');
            }
            return v === '' ? 0 : parseFloat(v);
        }

        // ═══════════════════════════════════════════════════════════════
        // VENDOR SELECTION
        // ═══════════════════════════════════════════════════════════════
        
        vendorListbox.onchange = function() {
            vendorHidden.value = this.value;
            this.classList.remove('vendor-required');
            updateTotals();
        };

        // ═══════════════════════════════════════════════════════════════
        // BUILD ITEM ROW
        // ═══════════════════════════════════════════════════════════════
        
        function buildRow() {
            var idx = rowCounter++;
            var row = document.createElement('div');
            row.className = 'proc-row ' + mode;

            // === COL SKU ===
            var colSku = document.createElement('div');
            colSku.className = 'col-sku';
            var sel = document.createElement('select');
            sel.name = 'items[' + idx + '][id]';
            sel.required = true;
            sel.innerHTML = '<option value="">-- Pilih --</option>';
            items.forEach(function(it) {
                var o = document.createElement('option');
                o.value = it.id;
                o.text = it.sku + ' (SAR ' + it.denom_value + ')';
                o.dataset.denom = it.denom_value || 1;
                sel.appendChild(o);
            });
            colSku.appendChild(sel);

            // === COL QTY ===
            var colQty = document.createElement('div');
            colQty.className = 'col-qty';
            var qty = document.createElement('input');
            qty.type = 'text';
            qty.name = 'items[' + idx + '][qty]';
            qty.placeholder = '0';
            colQty.appendChild(qty);

            // === COL KURS ===
            var colKurs = document.createElement('div');
            colKurs.className = 'col-kurs';
            var kurs = document.createElement('input');
            kurs.type = 'text';
            kurs.name = 'items[' + idx + '][kurs]';
            kurs.placeholder = 'Rp';
            colKurs.appendChild(kurs);

            // === COL RIYAL (Total SAR) ===
            var colRiyal = document.createElement('div');
            colRiyal.className = 'col-riyal';
            var riyal = document.createElement('input');
            riyal.type = 'text';
            riyal.name = 'items[' + idx + '][total_riyal]';
            riyal.placeholder = '0';
            colRiyal.appendChild(riyal);

            // === COL IDR (Total Rupiah) - Always readonly ===
            var colIdr = document.createElement('div');
            colIdr.className = 'col-idr';
            var idr = document.createElement('input');
            idr.type = 'text';
            idr.name = 'items[' + idx + '][total_rupiah]';
            idr.className = 'readonly';
            idr.readOnly = true;
            idr.value = '0';
            colIdr.appendChild(idr);

            // === COL ACTION ===
            var colAction = document.createElement('div');
            colAction.className = 'col-action';
            var btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.className = 'btn-remove';
            btnRemove.textContent = '×';
            btnRemove.title = 'Hapus baris';
            btnRemove.onclick = function() {
                row.remove();
                updateTotals();
            };
            colAction.appendChild(btnRemove);

            // === APPEND COLUMNS ===
            row.appendChild(colSku);
            row.appendChild(colRiyal);
            row.appendChild(colKurs);
            row.appendChild(colQty);
            row.appendChild(colIdr);
            row.appendChild(colAction);

            // === RECALC FUNCTION ===
            // FORMULA: [Total IDR] = [Total SAR] × [Kurs Beli]
            function recalc() {
                var selected = sel.selectedOptions[0];
                var denom = (selected && selected.value) ? parseNumber(selected.dataset.denom) : 0;
                var kursVal = parseNumber(kurs.value);

                if (mode === 'nominal') {
                    // By Nominal:  User input Total SAR → Calculate Qty
                    var trVal = parseNumber(riyal.value);
                    var qtyVal = denom > 0 ? (trVal / denom) : 0;
                    qty.value = denom > 0 ? formatNumber(qtyVal) : '0';
                    idr.value = formatNumber(trVal * kursVal);
                } else {
                    // By Quantity:  User input Qty → Calculate Total SAR
                    var qtyVal = parseNumber(qty.value);
                    var trVal = qtyVal * denom;
                    riyal.value = formatNumber(trVal);
                    idr.value = formatNumber(trVal * kursVal);
                }
                updateTotals();
            }

            // === EVENT BINDINGS ===
            sel.onchange = recalc;
            kurs.oninput = recalc;
            qty.oninput = function() { if (mode === 'quantity') recalc(); };
            riyal.oninput = function() { if (mode === 'nominal') recalc(); };

            // === APPLY INITIAL MODE ===
            applyRowMode(row);

            rowsContainer.appendChild(row);
            return row;
        }

        // ═══════════════════════════════════════════════════════════════
        // BUILD PAYMENT ROW
        // ═══════════════════════════════════════════════════════════════
        
        function buildPaymentRow() {
            var idx = paymentCounter++;
            var row = document.createElement('div');
            row.className = 'proc-payment-row';

            var sel = document.createElement('select');
            sel.name = 'payments[' + idx + '][account]';
            sel.required = true;
            sel.innerHTML = '<option value="">-- Pilih Sumber --</option>';
            banks.forEach(function(b) {
                var o = document.createElement('option');
                o.value = b.code;
                o.text = b.code + ' - ' + b.name;
                sel.appendChild(o);
            });

            var amount = document.createElement('input');
            amount.type = 'text';
            amount.name = 'payments[' + idx + '][amount]';
            amount.placeholder = 'Rp 0';
            amount.oninput = updateTotals;

            var btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.className = 'btn-remove';
            btnRemove.textContent = '×';
            btnRemove.title = 'Hapus sumber';
            btnRemove.onclick = function() {
                row.remove();
                updateTotals();
            };

            row.appendChild(sel);
            row.appendChild(amount);
            row.appendChild(btnRemove);

            paymentsContainer.appendChild(row);
            return row;
        }

        // ═══════════════════════════════════════════════════════════════
        // APPLY MODE TO SINGLE ROW (Enable/Disable Logic)
        // ═══════════════════════════════════════════════════════════════
        
        function applyRowMode(row) {
            var qtyInput = row.querySelector('.col-qty input');
            var riyalInput = row.querySelector('.col-riyal input');

            if (mode === 'nominal') {
                // By Nominal:  Riyal editable, Qty readonly
                qtyInput.readOnly = true;
                qtyInput.classList.add('readonly');
                riyalInput.readOnly = false;
                riyalInput.classList.remove('readonly');
            } else {
                // By Quantity: Qty editable, Riyal readonly
                qtyInput.readOnly = false;
                qtyInput.classList.remove('readonly');
                riyalInput.readOnly = true;
                riyalInput.classList.add('readonly');
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // SET MODE - Switch Column Order via CSS Class
        // ═══════════════════════════════════════════════════════════════
        
        function setMode(newMode) {
            mode = newMode;
            modeInput.value = newMode;

            // Update header row class
            headerRow.classList.remove('nominal', 'quantity');
            headerRow.classList.add(newMode);

            // Update all data rows
            rowsContainer.querySelectorAll('.proc-row').forEach(function(row) {
                row.classList.remove('nominal', 'quantity');
                row.classList.add(newMode);
                applyRowMode(row);
            });

            // Update tab appearance
            if (newMode === 'nominal') {
                document.getElementById('tab_nominal').classList.add('active');
                document.getElementById('tab_qty').classList.remove('active');
            } else {
                document.getElementById('tab_qty').classList.add('active');
                document.getElementById('tab_nominal').classList.remove('active');
            }

            updateTotals();
        }

        // ═══════════════════════════════════════════════════════════════
        // UPDATE TOTALS & VALIDATE
        // Formula: Total Pembelian = SUM(Total IDR)
        // ═══════════════════════════════════════════════════════════════
        
        function updateTotals() {
            // Calculate Total Pembelian
            var totalBelanja = 0;
            rowsContainer.querySelectorAll('.proc-row').forEach(function(r) {
                var idrInput = r.querySelector('.col-idr input');
                if (idrInput) totalBelanja += parseNumber(idrInput.value);
            });
            totalPembelianEl.textContent = formatNumber(totalBelanja);

            // Calculate Total Bayar
            var totalBayar = 0;
            paymentsContainer.querySelectorAll('.proc-payment-row').forEach(function(r) {
                var amountInput = r.querySelector('input[name*="amount"]');
                if (amountInput) totalBayar += parseNumber(amountInput.value);
            });
            totalBayarEl.textContent = formatNumber(totalBayar);

            // Calculate Selisih
            var diff = totalBayar - totalBelanja;
            selisihEl.textContent = 'Rp ' + formatNumber(Math.abs(diff));

            // Update selisih display
            if (Math.abs(diff) < 1 && totalBelanja > 0) {
                selisihRow.classList.add('zero');
                selisihRow.classList.remove('selisih');
                selisihEl.textContent = 'Rp 0 ✓';
            } else {
                selisihRow.classList.remove('zero');
                selisihRow.classList.add('selisih');
                if (diff > 0) {
                    selisihEl.textContent = '(lebih) Rp ' + formatNumber(diff);
                } else if (diff < 0) {
                    selisihEl.textContent = '(kurang) Rp ' + formatNumber(Math.abs(diff));
                }
            }

            // Validate form
            validateForm();
        }

        function validateForm() {
            var totalBelanja = 0;
            rowsContainer.querySelectorAll('.proc-row').forEach(function(r) {
                var idrInput = r.querySelector('.col-idr input');
                if (idrInput) totalBelanja += parseNumber(idrInput.value);
            });

            var totalBayar = 0;
            paymentsContainer.querySelectorAll('.proc-payment-row').forEach(function(r) {
                var amountInput = r.querySelector('input[name*="amount"]');
                if (amountInput) totalBayar += parseNumber(amountInput.value);
            });

            var vendorSelected = vendorListbox.value !== '';
            var hasItems = totalBelanja > 0;
            var balanced = Math.abs(totalBayar - totalBelanja) < 1;
            var confirmed = confirmChk.checked;

            submitBtn.disabled = !(vendorSelected && hasItems && balanced && confirmed);
        }

        // ═══════════════════════════════════════════════════════════════
        // EVENT BINDINGS
        // ═══════════════════════════════════════════════════════════════
        
        document.getElementById('tab_nominal').onclick = function() { setMode('nominal'); };
        document.getElementById('tab_qty').onclick = function() { setMode('quantity'); };
        document.getElementById('btn_add_row').onclick = function() { buildRow(); };
        document.getElementById('btn_add_payment').onclick = function() { buildPaymentRow(); };
        confirmChk.onchange = updateTotals;

        // Form submit handler
        procForm.onsubmit = function(e) {
            if (! vendorListbox.value) {
                e.preventDefault();
                vendorListbox.classList.add('vendor-required');
                vendorListbox.focus();
                alert('Silakan pilih vendor terlebih dahulu!');
                return false;
            }
            submitBtn.disabled = true;
            submitBtn.classList.add('processing');
            submitBtn.textContent = '⏳ MEMPROSES...';
        };

        // ═══════════════════════════════════════════════════════════════
        // INITIALIZATION
        // ═══════════════════════════════════════════════════════════════
        
        buildRow();
        buildPaymentRow();
        buildPaymentRow();
        setMode('nominal');

    })();
    </script>
    <?php
}

/**
 * =============================================================================
 * SUBMIT HANDLER
 * =============================================================================
 */
add_action('admin_post_puri_procure_submit', 'puri_handle_procurement_submit');

function puri_handle_procurement_submit() {
    puri_check_cap('manage_options');
    check_admin_referer('puri_procure_action', 'puri_procure_nonce');
    
    global $wpdb;
    
    $engine = puri_engine();
    
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $vendor_name = get_the_title($vendor_id) ?: 'Vendor #' . $vendor_id;
    $vendor_code = get_field('vendor_code', $vendor_id);
    if (empty($vendor_code)) {
        $vendor_code = 'VND-' . str_pad($vendor_id, 2, '0', STR_PAD_LEFT);
    }

    $items    = $_POST['items'] ?? [];
    $payments = $_POST['payments'] ?? [];
    
    // Validasi
    if (empty($vendor_id)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Vendor harus dipilih! '), admin_url('admin.php?page=puri-procurement')));
        exit;
    }
    
    if (empty($items)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Minimal 1 item harus diisi!'), admin_url('admin.php?page=puri-procurement')));
        exit;
    }
    
    if (empty($payments)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Sumber pembayaran harus diisi!'), admin_url('admin.php? page=puri-procurement')));
        exit;
    }
    
    $ref_id = 'PRO-' . date('ymdHi') . wp_rand(100, 999);
    
    $wpdb->query('START TRANSACTION');
    
    try {
        $total_belanja = 0;
        $items_processed = [];
        
        foreach ($items as $it) {
            $item_id = intval($it['id'] ?? 0);
            
            // Parse format Indonesia
            $qty_raw = str_replace(['. ', ','], ['', '.'], $it['qty'] ?? '0');
            $qty = floatval($qty_raw);
            
            $kurs_raw = str_replace(['.', ','], ['', '.'], $it['kurs'] ?? '0');
            $kurs = floatval($kurs_raw);
            
            if ($item_id <= 0 || $qty <= 0 || $kurs <= 0) {
                continue;
            }
            
            $item = $engine->get_item($item_id);
            if (!$item) {
                throw new Exception("Item ID {$item_id} tidak ditemukan");
            }
            
            // Update stok
            $res = $engine->update_stock_atomic('gudang_utama', $item_id, $qty);
            if (is_wp_error($res)) {
                throw new Exception('Gagal update stok: ' . $res->get_error_message());
            }
            
            // Calculate moving average HPP
            $new_avg = $engine->calculate_moving_avg($item_id, $qty, $kurs, true);
            if (is_wp_error($new_avg)) {
                throw new Exception('Gagal hitung HPP: ' . $new_avg->get_error_message());
            }
            
            // Insert ledger
            $wpdb->insert(
                puri_table_name('T_LEDGER'),
                [
                    'location_id' => 'gudang_utama',
                    'item_id'     => $item_id,
                    'qty_change'  => $qty,
                    'ref_id'      => $ref_id,
                    'description' => sprintf('Kulakan dari %s | Kurs:  Rp %s | HPP baru: Rp %s',
                        $vendor_name,
                        number_format($kurs, 0, ',', '.'),
                        number_format($new_avg, 2, ',', '.')
                    ),
                    'trx_date' => current_time('mysql')
                ],
                ['%s', '%d', '%f', '%s', '%s', '%s']
            );
            
            $denom = intval($item->denom_value) ?: 1;
            $total_riyal = $qty * $denom;
            $subtotal = $total_riyal * $kurs;
            $total_belanja += $subtotal;
            
            $items_processed[] = [
                'sku'         => $item->sku,
                'qty'         => $qty,
                'denom'       => $denom,
                'total_riyal' => $total_riyal,
                'kurs'        => $kurs,
                'subtotal'    => $subtotal,
                'new_hpp'     => $new_avg
            ];
        }
        
        if (empty($items_processed)) {
            throw new Exception('Tidak ada item valid yang diproses');
        }
        
        // Build description
        $desc_items = array_map(function($it) {
            return sprintf('SAR %s (%s riyal @%s)',
                number_format($it['denom']),
                number_format($it['total_riyal']),
                number_format($it['kurs'], 0, ',', '.')
            );
        }, $items_processed);
        
        $journal_desc = sprintf('Kulakan %s [%s] :  %s',
            $vendor_code,
            $vendor_name,
            implode(' ; ', $desc_items)
        );
        
        // Post journal debit persediaan
        $engine->post_journal($ref_id, '1401', $total_belanja, 0, $journal_desc, [
            'vendor_id'   => $vendor_id,
            'vendor_name' => $vendor_name,
            'items'       => $items_processed,
            'total_idr'   => $total_belanja
        ]);
        
        // Post journal credit per payment
        $total_bayar = 0;
        foreach ($payments as $pmt) {
            $acc_code = sanitize_text_field($pmt['account'] ?? '');
            $amount_raw = str_replace(['.', ','], ['', '.'], $pmt['amount'] ?? '0');
            $amount = floatval($amount_raw);
            
            if (empty($acc_code) || $amount <= 0) continue;
            
            $engine->post_journal($ref_id, $acc_code, 0, $amount, "Pembayaran kulakan {$ref_id} via {$acc_code}");
            $total_bayar += $amount;
        }
        
        // Validate balance
        if (abs($total_bayar - $total_belanja) > 1) {
            throw new Exception(sprintf('Total pembayaran (Rp %s) tidak sama dengan total belanja (Rp %s)',
                number_format($total_bayar),
                number_format($total_belanja)
            ));
        }
        
        $wpdb->query('COMMIT');
        
        wp_redirect(add_query_arg(
            'puri_procure_ok',
            urlencode("Berhasil!  Ref:  {$ref_id} | Total:  Rp " . number_format($total_belanja)),
            admin_url('admin. php?page=puri-procurement')
        ));
        exit;
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PURI Procurement ERROR] ' . $e->getMessage());
        }
        
        wp_redirect(add_query_arg(
            'puri_procure_err',
            urlencode('Gagal:  ' . $e->getMessage()),
            admin_url('admin.php?page=puri-procurement')
        ));
        exit;
    }
}