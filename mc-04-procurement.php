<?php
/**
 * =============================================================================
 * MC 04 - Procurement Hub (Kulakan / Pembelian Stok)
 * =============================================================================
 * 
 * @package     Pusat Riyal
 * @module      MC-04
 * @version     6.0.6
 * @author      Denmas Totok (refactor by Copilot)
 * @updated     2026-01-03
 * 
 * =============================================================================
 * PURPOSE / TUJUAN
 * =============================================================================
 * 
 * Modul untuk mengelola pembelian stok valas dari vendor/supplier: 
 *   - Input pembelian dengan dual mode (By Nominal / By Quantity)
 *   - Multi-source payment (Kas Laci + Bank)
 *   - Real-time balance validation
 *   - Auto-calculate HPP dengan Moving Average
 *   - Double-entry journal accounting
 * 
 * =============================================================================
 * FEATURES / FITUR
 * =============================================================================
 * 
 *   [1] Dual input mode: By Nominal (total riyal) atau By Quantity (pcs)
 *   [2] Multi-source payment dari berbagai akun kas/bank
 *   [3] Real-time validation:  submit hanya aktif jika balance
 *   [4] Auto-update stok gudang
 *   [5] Auto-calculate HPP (Moving Average)
 *   [6] Ledger entry untuk audit trail
 *   [7] Double-entry journal (Debit Persediaan, Credit Kas/Bank)
 *   [8] Riwayat pembelian bulan berjalan
 * 
 * =============================================================================
 * FLOW PROCESS
 * =============================================================================
 * 
 *   1. User pilih vendor
 *   2. User input item + qty/nominal + kurs beli
 *   3. User pilih sumber pembayaran (bisa multiple)
 *   4. System validasi:  Total Belanja == Total Bayar
 *   5. User confirm & submit
 *   6. Backend process: 
 *      a. START TRANSACTION
 *      b. Update stok gudang (atomic)
 *      c. Calculate moving average HPP
 *      d. Insert ledger entry
 *      e. Post journal (debit persediaan)
 *      f. Post journal (credit kas/bank) per sumber
 *      g.  COMMIT (atau ROLLBACK jika error)
 * 
 * =============================================================================
 * SECURITY MEASURES
 * =============================================================================
 * 
 *   ✓ Capability check:  puri_check_cap('manage_options')
 *   ✓ Nonce verification: wp_nonce_field / check_admin_referer
 *   ✓ Input sanitization:  intval, floatval, sanitize_text_field
 *   ✓ Output escaping: esc_html, esc_attr, esc_url
 *   ✓ SQL injection prevention: $wpdb->prepare
 *   ✓ Transaction rollback on error
 *   ✓ Double-click protection (JavaScript)
 * 
 * =============================================================================
 * DEPENDENCIES
 * =============================================================================
 * 
 *   - mc-00-admin-hub.php  :  puri_check_cap(), puri_table_name()
 *   - mc-01-core.php       : Table constants (T_ITEMS, T_LEDGER, T_JOURNAL, T_CHART)
 *   - mc-03-engine.php     : puri_engine(), update_stock_atomic(), 
 *                            calculate_moving_avg(), post_journal()
 *   - mc-19-crud-partnership.php : CPT pr_vendor
 * 
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 * 
 * [6.0.6] 2026-01-03
 *   - Fixed: Integrasi dengan new engine mc-03 (puri_engine() helper)
 *   - Fixed: Tambah calculate_moving_avg() untuk update HPP
 *   - Fixed: Vendor ID tidak terkirim di form
 *   - Fixed:  Nonce check di AJAX handler
 *   - Fixed:  Floating point comparison di JavaScript
 *   - Added: Double-click protection
 *   - Added: Success/error notice display
 *   - Improved: Header dokumentasi lengkap
 * 
 * [6.0.5] 2025-12-xx
 *   - Added: Dual mode (By Nominal / By Quantity)
 *   - Added:  Multi-source payment
 *   - Added: Real-time balance validation
 * 
 * =============================================================================
 */

defined('ABSPATH') || exit;

/**
 * Register submenu (jika belum didaftarkan di mc-00)
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
 * AJAX Handler:  Get procurement data (items, vendors, banks)
 */
add_action('wp_ajax_puri_get_procurement_data', 'puri_get_procurement_data_handler');

function puri_get_procurement_data_handler() {
    // Security check
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    
    if (! current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }
    
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
    
    wp_send_json_success([
        'items'   => $items, 
        'vendors' => $vendors, 
        'banks'   => $banks
    ]);
}

/**
 * Render Procurement Page
 */
function puri_render_procurement_page() {
    // Security check
    puri_check_cap('manage_options');

    global $wpdb;
    
    // Get data untuk form
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

    // Display success/error notices
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
            .proc-container { display: grid; grid-template-columns: 220px 1fr 380px; gap: 20px; margin-top: 20px; }
            .proc-panel { background: #fff; border:  1px solid #d1d5db; border-radius: 8px; padding: 16px; }
            .proc-listbox { width: 100%; height: 400px; border: 1px solid #cbd5e1; border-radius: 4px; }
            .proc-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; }
            .proc-tab { padding: 8px 16px; background: #f1f5f9; border:  none; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: 600; }
            . proc-tab.active { background: #7c3aed; color: #fff; }
            . proc-row { display: grid; grid-template-columns: 140px 100px 100px 120px 140px 80px; gap: 8px; align-items: center; margin-bottom: 8px; }
            .proc-row input, .proc-row select { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: right; }
            . proc-row select { text-align: left; }
            . proc-row . readonly { background: #f8fafc; color: #64748b; }
            .proc-row .btn-sm { padding: 4px 8px; font-size: 11px; cursor: pointer; border:  1px solid #cbd5e1; border-radius: 3px; background: #fff; }
            . proc-row .btn-remove { color: #ef4444; border-color: #ef4444; }
            .proc-payment-row { display: grid; grid-template-columns: 1fr 140px 60px; gap: 8px; align-items:  center; margin-bottom: 8px; }
            .proc-payment-row select, .proc-payment-row input { padding:  6px; border: 1px solid #cbd5e1; border-radius:  4px; }
            .proc-payment-row input { text-align:  right; font-weight: 700; }
            . proc-summary { background: #f8fafc; padding: 12px; border-radius: 6px; margin:  12px 0; }
            .proc-summary-row { display: flex; justify-content: space-between; padding: 6px 0; }
            .proc-summary-row. total { font-weight: 900; font-size: 18px; border-top: 2px solid #0f172a; padding-top: 10px; margin-top: 8px; }
            .proc-summary-row.selisih { color: #ef4444; font-weight: 700; }
            . proc-summary-row.selisih. zero { color: #10b981; }
            .btn-submit { width: 100%; padding: 12px; background: #059669; color: #fff; border: none; border-radius:  6px; font-weight: 900; cursor: pointer; font-size: 16px; }
            .btn-submit:disabled { background: #cbd5e1; cursor: not-allowed; }
            .btn-submit.processing { background: #6b7280; }
            .history-table { margin-top: 24px; }
            .vendor-required { border-color: #ef4444 !important; }
        </style>

        <div class="proc-container">
            <!-- Vendor List -->
            <div class="proc-panel">
                <h3 style="margin-top: 0">Vendor / Supplier <span style="color:#ef4444">*</span></h3>
                <select id="vendor_listbox" class="proc-listbox" size="20" required>
                    <option value="">-- Pilih Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo esc_attr($v->ID); ?>"><?php echo esc_html($v->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="margin-top: 8px; color:#6b7280; font-size: 12px;">
                    <a href="<?php echo esc_url(admin_url('post-new.php? post_type=pr_vendor')); ?>" target="_blank">+ Tambah Vendor Baru</a>
                </p>
            </div>

            <!-- Detail Transaksi -->
            <div class="proc-panel">
                <div style="display:flex; justify-content:space-between; margin-bottom:12px">
                    <h2 style="margin: 0">Detail Transaksi</h2>
                    <div style="color:#6b7280"><?php echo esc_html(date_i18n('l, d F Y')); ?></div>
                </div>

                <div class="proc-tabs">
                    <button type="button" class="proc-tab active" id="tab_nominal">By Nominal</button>
                    <button type="button" class="proc-tab" id="tab_qty">By Quantity</button>
                </div>

                <form method="post" id="proc_form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <? php wp_nonce_field('puri_procure_action', 'puri_procure_nonce'); ?>
                    <input type="hidden" name="action" value="puri_procure_submit">
                    <input type="hidden" name="mode" id="proc_mode" value="nominal">
                    <!-- FIX: Tambahkan hidden input untuk vendor_id -->
                    <input type="hidden" name="vendor_id" id="vendor_id_hidden" value="">

                    <!-- Header Row -->
                    <div class="proc-row" style="font-weight:700; background:#f1f5f9; padding:8px 4px; border-radius:4px;">
                        <div>Item</div>
                        <div style="text-align:right">Qty (pcs)</div>
                        <div style="text-align: right">Kurs Beli</div>
                        <div style="text-align:right">Total SAR</div>
                        <div style="text-align:right">Total IDR</div>
                        <div></div>
                    </div>

                    <div id="proc_rows"></div>

                    <button type="button" id="btn_add_row" class="button" style="margin-top:8px">+ Tambah Item</button>

                    <div class="proc-summary">
                        <div class="proc-summary-row total">
                            <span>Total Pembelian</span>
                            <span>Rp <span id="total_pembelian">0</span></span>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; margin: 12px 0">
                        <input type="checkbox" id="confirm_data" name="confirm_ok" value="1">
                        <label for="confirm_data" style="font-weight:600">Data sudah benar dan siap diproses</label>
                    </div>

                    <button type="submit" id="btn_submit" class="btn-submit" disabled>
                        SUBMIT PEMBELIAN
                    </button>
                </form>
            </div>

            <!-- Sumber Pembayaran -->
            <div class="proc-panel">
                <h3 style="margin-top: 0">Sumber Pembayaran <span style="color:#ef4444">*</span></h3>
                
                <div id="payment_sources"></div>

                <button type="button" id="btn_add_payment" class="button" style="width:100%; margin-top:8px">+ Tambah Sumber</button>

                <div class="proc-summary" style="margin-top:16px">
                    <div class="proc-summary-row">
                        <span>Total Dibayar</span>
                        <span>Rp <span id="total_bayar">0</span></span>
                    </div>
                    <div class="proc-summary-row selisih" id="selisih_row">
                        <span>Selisih</span>
                        <span id="selisih_amount">Rp 0</span>
                    </div>
                </div>
                
                <div style="margin-top:12px; padding:10px; background:#fef3c7; border-radius:6px; font-size:12px;">
                    <strong>⚠️ Penting:</strong> Total pembayaran harus sama dengan total pembelian. 
                </div>
            </div>
        </div>

        <!-- History -->
        <div class="proc-panel history-table">
            <h3>📋 Riwayat Pembelian Bulan Ini</h3>
            <? php
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
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kode Ref</th>
                        <th>Deskripsi</th>
                        <th style="text-align:right">Total (IDR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hist)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:#6b7280; padding:20px;">
                                Belum ada pembelian bulan ini. 
                            </td>
                        </tr>
                    <?php else: foreach ($hist as $h): ?>
                        <tr>
                            <td><? php echo esc_html(date_i18n('d M Y, H:i', strtotime($h->trx_date))); ?></td>
                            <td><code><?php echo esc_html($h->ref_id); ?></code></td>
                            <td><?php echo esc_html(wp_trim_words($h->description, 10)); ?></td>
                            <td style="text-align:right; font-weight:700;">
                                Rp <?php echo number_format_i18n($h->total_debit); ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function(){
        'use strict';
        
        // Data dari PHP
        const items = <?php echo wp_json_encode(array_map(function($item) {
            return [
                'id'          => intval($item->id),
                'sku'         => esc_html($item->sku),
                'name'        => esc_html($item->name),
                'denom_value' => intval($item->denom_value),
                'base_price'  => floatval($item->base_price)
            ];
        }, $items)); ?>;
        
        const banks = <?php echo wp_json_encode(array_map(function($bank) {
            return [
                'code' => esc_html($bank->code),
                'name' => esc_html($bank->name)
            ];
        }, $banks)); ?>;
        
        // DOM Elements
        const rowsContainer     = document.getElementById('proc_rows');
        const paymentsContainer = document.getElementById('payment_sources');
        const modeInput         = document.getElementById('proc_mode');
        const vendorListbox     = document. getElementById('vendor_listbox');
        const vendorHidden      = document. getElementById('vendor_id_hidden');
        const totalPembelianEl  = document.getElementById('total_pembelian');
        const totalBayarEl      = document.getElementById('total_bayar');
        const selisihEl         = document.getElementById('selisih_amount');
        const selisihRow        = document.getElementById('selisih_row');
        const submitBtn         = document.getElementById('btn_submit');
        const confirmChk        = document.getElementById('confirm_data');
        const procForm          = document.getElementById('proc_form');
        
        let mode = 'nominal';
        let rowCounter = 0;
        let paymentCounter = 0;
        let isSubmitting = false;

        // ═══════════════════════════════════════════════════════════════
        // UTILITY FUNCTIONS
        // ═══════════════════════════════════════════════════════════════
        
        function formatNumber(n) {
            return new Intl. NumberFormat('id-ID').format(Math.round(parseFloat(n || 0)));
        }
        
        function parseNumber(v) {
            v = String(v || '').replace(/[^\d\.\-]/g, '');
            return v === '' ? 0 : parseFloat(v);
        }
        
        // FIX: Floating point comparison dengan tolerance
        function isBalanced(a, b) {
            return Math.abs(a - b) < 0.01;
        }

        // ═══════════════════════════════════════════════════════════════
        // VENDOR SELECTION
        // ═══════════════════════════════════════════════════════════════
        
        vendorListbox.addEventListener('change', function() {
            vendorHidden.value = this.value;
            this.classList.remove('vendor-required');
            validateForm();
        });

        // ═══════════════════════════════════════════════════════════════
        // ITEM ROW BUILDER
        // ═══════════════════════════════════════════════════════════════
        
        function makeItemSelect(name, selectedId) {
            const sel = document.createElement('select');
            sel.name = name;
            sel. required = true;
            sel.innerHTML = '<option value="">-- Pilih --</option>';
            
            items.forEach(it => {
                const o = document.createElement('option');
                o.value = it. id;
                o.text = it. sku + ' (SAR ' + it. denom_value + ')';
                o. dataset. denom = it. denom_value || 1;
                o. dataset.basePrice = it.base_price || 0;
                if (selectedId && selectedId == it.id) o.selected = true;
                sel.appendChild(o);
            });
            
            return sel;
        }

        function buildRow(data = {}) {
            const idx = rowCounter++;
            const row = document.createElement('div');
            row.className = 'proc-row';
            row.dataset.rowIndex = idx;

            const sel = makeItemSelect(`items[${idx}][id]`, data.item_id || '');
            
            const qty = document.createElement('input');
            qty.type = 'text';
            qty. name = `items[${idx}][qty]`;
            qty.placeholder = '0';
            qty.value = data.qty ?  formatNumber(data.qty) : '';
            qty.className = mode === 'nominal' ? 'readonly' : '';
            qty.readOnly = mode === 'nominal';

            const kurs = document.createElement('input');
            kurs.type = 'text';
            kurs.name = `items[${idx}][kurs]`;
            kurs. placeholder = 'Rp';
            kurs. value = data.kurs ? formatNumber(data.kurs) : '';

            const totalRiyal = document.createElement('input');
            totalRiyal.type = 'text';
            totalRiyal.name = `items[${idx}][total_riyal]`;
            totalRiyal. placeholder = '0';
            totalRiyal.value = data.total_riyal ? formatNumber(data.total_riyal) : '';
            totalRiyal.className = mode === 'qty' ? 'readonly' : '';
            totalRiyal. readOnly = mode === 'qty';

            const totalRupiah = document.createElement('input');
            totalRupiah. type = 'text';
            totalRupiah.name = `items[${idx}][total_rupiah]`;
            totalRupiah. className = 'readonly';
            totalRupiah. readOnly = true;
            totalRupiah.value = '0';

            const actions = document.createElement('div');
            const btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.className = 'btn-sm btn-remove';
            btnRemove.textContent = '×';
            btnRemove.title = 'Hapus baris';
            actions.appendChild(btnRemove);

            row.appendChild(sel);
            row.appendChild(qty);
            row.appendChild(kurs);
            row.appendChild(totalRiyal);
            row.appendChild(totalRupiah);
            row.appendChild(actions);

            function recalc() {
                const selected = sel.selectedOptions[0];
                const denom = selected && selected.dataset. denom ? parseNumber(selected.dataset.denom) : 1;
                const kursVal = parseNumber(kurs.value);

                if (mode === 'nominal') {
                    const trVal = parseNumber(totalRiyal.value);
                    const qtyVal = denom > 0 ? (trVal / denom) : 0;
                    qty.value = formatNumber(qtyVal);
                    totalRupiah.value = formatNumber(trVal * kursVal);
                } else {
                    const qtyVal = parseNumber(qty.value);
                    const trVal = qtyVal * denom;
                    totalRiyal.value = formatNumber(trVal);
                    totalRupiah. value = formatNumber(trVal * kursVal);
                }
                
                updateTotals();
            }

            sel.addEventListener('change', recalc);
            qty.addEventListener('input', recalc);
            kurs.addEventListener('input', recalc);
            totalRiyal.addEventListener('input', recalc);

            btnRemove.addEventListener('click', function() {
                row.remove();
                updateTotals();
            });

            return row;
        }

        // ═══════════════════════════════════════════════════════════════
        // PAYMENT ROW BUILDER
        // ═══════════════════════════════════════════════════════════════
        
        function buildPaymentRow(data = {}) {
            const idx = paymentCounter++;
            const row = document. createElement('div');
            row.className = 'proc-payment-row';
            row.dataset.paymentIndex = idx;

            const sel = document.createElement('select');
            sel.name = `payments[${idx}][account]`;
            sel.required = true;
            sel.innerHTML = '<option value="">-- Pilih Sumber --</option>';
            
            banks.forEach(b => {
                const o = document.createElement('option');
                o.value = b.code;
                o.text = b.code + ' - ' + b.name;
                if (data.account && data.account == b.code) o.selected = true;
                sel.appendChild(o);
            });

            const amount = document.createElement('input');
            amount.type = 'text';
            amount.name = `payments[${idx}][amount]`;
            amount.placeholder = 'Rp 0';
            amount.value = data. amount ? formatNumber(data.amount) : '';

            const btnRemove = document. createElement('button');
            btnRemove. type = 'button';
            btnRemove.className = 'btn-sm btn-remove';
            btnRemove.textContent = '×';
            btnRemove.title = 'Hapus sumber';

            amount.addEventListener('input', updateTotals);
            btnRemove. addEventListener('click', function() {
                row.remove();
                updateTotals();
            });

            row.appendChild(sel);
            row.appendChild(amount);
            row.appendChild(btnRemove);

            return row;
        }

        // ═══════════════════════════════════════════════════════════════
        // TOTALS & VALIDATION
        // ═══════════════════════════════════════════════════════════════
        
        function updateTotals() {
            let totalBelanja = 0;
            document.querySelectorAll('.proc-row').forEach(r => {
                const rupiah = r.querySelector('input[name*="total_rupiah"]');
                if (rupiah) totalBelanja += parseNumber(rupiah. value);
            });
            totalPembelianEl.textContent = formatNumber(totalBelanja);

            let totalBayar = 0;
            document.querySelectorAll('.proc-payment-row').forEach(r => {
                const amount = r. querySelector('input[name*="amount"]');
                if (amount) totalBayar += parseNumber(amount.value);
            });
            totalBayarEl.textContent = formatNumber(totalBayar);

            const selisih = totalBayar - totalBelanja;
            selisihEl.textContent = 'Rp ' + formatNumber(Math.abs(selisih));
            
            // FIX: Gunakan isBalanced() untuk floating point comparison
            if (isBalanced(totalBayar, totalBelanja) && totalBelanja > 0) {
                selisihRow.classList.add('zero');
                selisihRow. classList.remove('selisih');
                selisihEl.textContent = 'Rp 0 ✓';
            } else {
                selisihRow.classList.remove('zero');
                selisihRow. classList.add('selisih');
                
                if (selisih > 0) {
                    selisihEl.textContent = 'Rp ' + formatNumber(selisih) + ' (LEBIH)';
                } else if (selisih < 0) {
                    selisihEl.textContent = 'Rp ' + formatNumber(Math.abs(selisih)) + ' (KURANG)';
                }
            }

            validateForm();
        }
        
        function validateForm() {
            let totalBelanja = 0;
            document.querySelectorAll('.proc-row').forEach(r => {
                const rupiah = r.querySelector('input[name*="total_rupiah"]');
                if (rupiah) totalBelanja += parseNumber(rupiah.value);
            });

            let totalBayar = 0;
            document.querySelectorAll('.proc-payment-row').forEach(r => {
                const amount = r. querySelector('input[name*="amount"]');
                if (amount) totalBayar += parseNumber(amount.value);
            });
            
            const vendorSelected = vendorListbox.value !== '';
            const hasItems = totalBelanja > 0;
            const balanced = isBalanced(totalBayar, totalBelanja);
            const confirmed = confirmChk.checked;
            
            const canSubmit = vendorSelected && hasItems && balanced && confirmed && !isSubmitting;
            
            submitBtn.disabled = ! canSubmit;
        }

        // ═══════════════════════════════════════════════════════════════
        // MODE SWITCHING
        // ═══════════════════════════════════════════════════════════════
        
        function applyMode(newMode) {
            mode = newMode;
            modeInput.value = newMode;
            
            document.querySelectorAll('.proc-row').forEach(r => {
                const qty = r.querySelector('input[name*="[qty]"]');
                const totalRiyal = r.querySelector('input[name*="total_riyal"]');
                
                if (newMode === 'nominal') {
                    qty.readOnly = true;
                    qty.classList.add('readonly');
                    totalRiyal. readOnly = false;
                    totalRiyal.classList.remove('readonly');
                } else {
                    qty.readOnly = false;
                    qty.classList. remove('readonly');
                    totalRiyal.readOnly = true;
                    totalRiyal. classList.add('readonly');
                }
            });
        }

        document.getElementById('tab_nominal').addEventListener('click', function() {
            this.classList.add('active');
            document.getElementById('tab_qty').classList.remove('active');
            applyMode('nominal');
        });

        document.getElementById('tab_qty').addEventListener('click', function() {
            this.classList.add('active');
            document.getElementById('tab_nominal').classList.remove('active');
            applyMode('qty');
        });

        // ═══════════════════════════════════════════════════════════════
        // FORM SUBMISSION - DOUBLE-CLICK PROTECTION
        // ═══════════════════════════════════════════════════════════════
        
        procForm.addEventListener('submit', function(e) {
            // Validasi vendor
            if (!vendorListbox.value) {
                e.preventDefault();
                vendorListbox.classList.add('vendor-required');
                vendorListbox.focus();
                alert('Silakan pilih vendor terlebih dahulu! ');
                return false;
            }
            
            // Double-click protection
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            isSubmitting = true;
            submitBtn.disabled = true;
            submitBtn.classList.add('processing');
            submitBtn.textContent = '⏳ MEMPROSES...';
            
            // Fallback:  re-enable after 15 seconds jika tidak redirect
            setTimeout(function() {
                isSubmitting = false;
                submitBtn. disabled = false;
                submitBtn.classList.remove('processing');
                submitBtn. textContent = 'SUBMIT PEMBELIAN';
            }, 15000);
        });

        // ═══════════════════════════════════════════════════════════════
        // EVENT LISTENERS
        // ═══════════════════════════════════════════════════════════════
        
        document.getElementById('btn_add_row').addEventListener('click', function() {
            rowsContainer.appendChild(buildRow());
        });
        
        document. getElementById('btn_add_payment').addEventListener('click', function() {
            paymentsContainer.appendChild(buildPaymentRow());
        });

        confirmChk.addEventListener('change', validateForm);

        // ═══════════════════════════════════════════════════════════════
        // INITIALIZATION
        // ═══════════════════════════════════════════════════════════════
        
        // Add initial rows
        rowsContainer.appendChild(buildRow());
        paymentsContainer.appendChild(buildPaymentRow());
        paymentsContainer.appendChild(buildPaymentRow());
        
        updateTotals();
        
    })();
    </script>
    <? php
}

/**
 * =============================================================================
 * SUBMIT HANDLER - Process Procurement
 * =============================================================================
 */
add_action('admin_post_puri_procure_submit', 'puri_handle_procurement_submit');

function puri_handle_procurement_submit() {
    // Security checks
    puri_check_cap('manage_options');
    check_admin_referer('puri_procure_action', 'puri_procure_nonce');
    
    global $wpdb;
    
    // Gunakan helper function puri_engine() dari mc-03
    $engine = puri_engine();
    
    // Parse input
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $items     = $_POST['items'] ??  [];
    $payments  = $_POST['payments'] ?? [];
    
    // Validasi dasar
    if (empty($vendor_id)) {
        wp_redirect(add_query_arg(
            'puri_procure_err', 
            urlencode('Vendor harus dipilih! '), 
            admin_url('admin. php? page=puri-procurement')
        ));
        exit;
    }
    
    if (empty($items)) {
        wp_redirect(add_query_arg(
            'puri_procure_err', 
            urlencode('Minimal 1 item harus diisi!'), 
            admin_url('admin.php?page=puri-procurement')
        ));
        exit;
    }
    
    if (empty($payments)) {
        wp_redirect(add_query_arg(
            'puri_procure_err', 
            urlencode('Sumber pembayaran harus diisi!'), 
            admin_url('admin.php?page=puri-procurement')
        ));
        exit;
    }
    
    // Generate Reference ID
    $ref_id = 'PRO-' . date('Ymd-His') . '-' . wp_rand(100, 999);
    
    // Get vendor name for description
    $vendor_name = get_the_title($vendor_id) ?: 'Vendor #' . $vendor_id;
    
    // ═══════════════════════════════════════════════════════════════════════
    // START TRANSACTION
    // ═══════════════════════════════════════════════════════════════════════
    $wpdb->query('START TRANSACTION');
    
    try {
        $total_belanja = 0;
        $items_processed = [];
        
        // ───────────────────────────────────────────────────────────────────
        // PROCESS EACH ITEM
        // ───────────────────────────────────────────────────────────────────
        foreach ($items as $it) {
            $item_id = intval($it['id'] ?? 0);
            $qty     = floatval(str_replace([',', '.'], ['', '. '], $it['qty'] ?? 0));
            $kurs    = floatval(str_replace([',', '.'], ['', '.'], $it['kurs'] ?? 0));
            
            // Skip jika tidak valid
            if ($item_id <= 0 || $qty <= 0 || $kurs <= 0) {
                continue;
            }
            
            // Get item info
            $item = $engine->get_item($item_id);
            if (!$item) {
                throw new Exception("Item ID {$item_id} tidak ditemukan");
            }
            
            // 1. Update stok gudang (atomic)
            $res = $engine->update_stock_atomic('gudang_utama', $item_id, $qty);
            if (is_wp_error($res)) {
                throw new Exception('Gagal update stok:  ' . $res->get_error_message());
            }
            
            // 2. Calculate moving average HPP
            // PENTING: Ini yang sebelumnya TIDAK ADA! 
            $new_avg = $engine->calculate_moving_avg($item_id, $qty, $kurs, true);
            if (is_wp_error($new_avg)) {
                throw new Exception('Gagal hitung HPP: ' . $new_avg->get_error_message());
            }
            
            // 3. Insert ledger entry
            $ledger_result = $wpdb->insert(
                puri_table_name('T_LEDGER'),
                [
                    'location_id' => 'gudang_utama',
                    'item_id'     => $item_id,
                    'qty_change'  => $qty,
                    'ref_id'      => $ref_id,
                    'description' => sprintf(
                        'Kulakan dari %s | Kurs:  Rp %s | HPP baru: Rp %s',
                        $vendor_name,
                        number_format($kurs, 0, ',', '. '),
                        number_format($new_avg, 2, ',', '.')
                    ),
                    'trx_date'    => current_time('mysql')
                ],
                ['%s', '%d', '%f', '%s', '%s', '%s']
            );
            
            if ($ledger_result === false) {
                throw new Exception('Gagal insert ledger: ' .  $wpdb->last_error);
            }
            
            // Calculate subtotal
            $denom = intval($item->denom_value) ?: 1;
            $total_riyal = $qty * $denom;
            $subtotal = $total_riyal * $kurs;
            $total_belanja += $subtotal;
            
            // Track processed items
            $items_processed[] = [
                'sku'      => $item->sku,
                'qty'      => $qty,
                'denom'    => $denom,
                'kurs'     => $kurs,
                'subtotal' => $subtotal,
                'new_hpp'  => $new_avg
            ];
        }
        
        if (empty($items_processed)) {
            throw new Exception('Tidak ada item valid yang diproses');
        }
        
        // ───────────────────────────────────────────────────────────────────
        // POST JOURNAL:  DEBIT PERSEDIAAN
        // ───────────────────────────────────────────────────────────────────
        $desc_items = array_map(function($it) {
            return $it['sku'] . ' (' . number_format($it['qty']) . ' pcs)';
        }, $items_processed);
        
        $journal_desc = 'Kulakan dari ' . $vendor_name . ':  ' . implode(', ', $desc_items);
        
        $journal_result = $engine->post_journal(
            $ref_id,
            '1401', // Persediaan
            $total_belanja,
            0,
            $journal_desc,
            [
                'vendor_id'   => $vendor_id,
                'vendor_name' => $vendor_name,
                'items'       => $items_processed,
                'total_idr'   => $total_belanja
            ]
        );
        
        if (is_wp_error($journal_result)) {
            throw new Exception('Gagal post journal debit: ' . $journal_result->get_error_message());
        }
        
        // ───────────────────────────────────────────────────────────────────
        // POST JOURNAL: CREDIT KAS/BANK (per sumber pembayaran)
        // ───────────────────────────────────────────────────────────────────
        $total_bayar = 0;
        
        foreach ($payments as $pmt) {
            $acc_code = sanitize_text_field($pmt['account'] ?? '');
            $amount   = floatval(str_replace([',', '.'], ['', '.'], $pmt['amount'] ?? 0));
            
            if (empty($acc_code) || $amount <= 0) {
                continue;
            }
            
            $credit_result = $engine->post_journal(
                $ref_id,
                $acc_code,
                0,
                $amount,
                "Pembayaran kulakan {$ref_id} via {$acc_code}"
            );
            
            if (is_wp_error($credit_result)) {
                throw new Exception('Gagal post journal credit: ' .  $credit_result->get_error_message());
            }
            
            $total_bayar += $amount;
        }
        
        // ───────────────────────────────────────────────────────────────────
        // VALIDASI BALANCE
        // ───────────────────────────────────────────────────────────────────
        if (abs($total_bayar - $total_belanja) > 0.01) {
            throw new Exception(sprintf(
                'Total pembayaran (Rp %s) tidak sama dengan total belanja (Rp %s)',
                number_format($total_bayar),
                number_format($total_belanja)
            ));
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // COMMIT TRANSACTION
        // ═══════════════════════════════════════════════════════════════════
        $wpdb->query('COMMIT');
        
        // Redirect dengan success message
        wp_redirect(add_query_arg(
            'puri_procure_ok',
            urlencode("Berhasil!  Ref:  {$ref_id} | Total:  Rp " . number_format($total_belanja)),
            admin_url('admin.php? page=puri-procurement')
        ));
        exit;
        
    } catch (Exception $e) {
        // ═══════════════════════════════════════════════════════════════════
        // ROLLBACK ON ERROR
        // ═══════════════════════════════════════════════════════════════════
        $wpdb->query('ROLLBACK');
        
        // Log error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PURI Procurement ERROR] ' . $e->getMessage());
        }
        
        // Redirect dengan error message
        wp_redirect(add_query_arg(
            'puri_procure_err',
            urlencode('Gagal:  ' . $e->getMessage()),
            admin_url('admin. php?page=puri-procurement')
        ));
        exit;
    }
}