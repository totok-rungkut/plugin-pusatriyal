<?php
/**
 * =============================================================================
 * MC 04 - Procurement Hub (Kulakan / Pembelian Stok)
 * =============================================================================
 * 
 * @package     Pusat Riyal
 * @module      MC-04
 * @version     6.0.9
 * @author      Denmas Totok
 * @updated     2026-01-04
 * 
 * =============================================================================
 * FORMULA
 * =============================================================================
 * 
 *   [Total IDR] = [Total SAR] × [Kurs]
 *   
 *   Mode Nominal:  Total SAR = input user
 *   Mode Quantity: Total SAR = Qty × Denom
 * 
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 * 
 * [6.0.9] 2026-01-04
 *   - Merged: UX dari v6.0.7 + Logic dari v6.0.6
 *   - Fixed: parseNumber() adopsi v6.0.6 (simple)
 *   - Fixed: Tab switching dengan onclick
 *   - Fixed: Dynamic column order via CSS
 * 
 * =============================================================================
 */

defined('ABSPATH') || exit;

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
            .proc-panel { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; }
            .proc-listbox { width: 100%; height: 400px; border: 1px solid #cbd5e1; border-radius:  4px; }
            .proc-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; }
            .proc-tab { padding: 8px 16px; background: #f1f5f9; border: none; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: 600; }
            .proc-tab:hover { background: #e2e8f0; }
            .proc-tab.active { background: #7c3aed; color: #fff; }
            
            .proc-row { display: grid; grid-template-columns: 140px 120px 100px 100px 140px 50px; gap: 8px; align-items: center; margin-bottom: 8px; }
            .proc-row.nominal { grid-template-columns: 140px 120px 100px 80px 140px 50px; }
            .proc-row.quantity { grid-template-columns:  140px 80px 100px 120px 140px 50px; }
            .proc-row input, .proc-row select { padding: 6px; border: 1px solid #cbd5e1; border-radius:  4px; text-align: right; width: 100%; box-sizing: border-box; }
            .proc-row select { text-align: left; }
            .proc-row .readonly { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
            
            .col-sku { order: 1; }
            .col-riyal { order: 2; }
            .col-kurs { order: 3; }
            .col-qty { order: 4; }
            .col-idr { order: 5; }
            .col-action { order: 6; }
            .proc-row.quantity .col-qty { order: 2; }
            .proc-row.quantity .col-riyal { order: 4; }

            .btn-remove { color: #ef4444; border: 1px solid #ef4444; background: #fff; cursor: pointer; border-radius: 4px; font-weight: bold; padding: 4px 10px; }
            .btn-remove:hover { background: #fef2f2; }
            
            .proc-payment-row { display: grid; grid-template-columns: 1fr 140px 50px; gap: 8px; align-items: center; margin-bottom: 8px; }
            .proc-payment-row select, .proc-payment-row input { padding: 6px; border:  1px solid #cbd5e1; border-radius: 4px; }
            .proc-payment-row input { text-align: right; font-weight: 700; }
            
            .proc-summary { background: #f8fafc; padding: 12px; border-radius: 6px; margin:  12px 0; }
            .proc-summary-row { display: flex; justify-content: space-between; padding: 6px 0; }
            .proc-summary-row.total { font-weight: 900; font-size: 18px; border-top: 2px solid #0f172a; padding-top:  10px; margin-top: 8px; }
            .proc-summary-row.selisih { color: #ef4444; font-weight: 700; }
            .proc-summary-row.selisih.zero { color: #10b981; }
            
            .btn-submit { width: 100%; padding: 12px; background: #059669; color: #fff; border: none; border-radius: 6px; font-weight: 900; cursor: pointer; font-size: 16px; }
            .btn-submit:hover: not(:disabled) { background: #047857; }
            .btn-submit:disabled { background: #cbd5e1; cursor: not-allowed; }
            .btn-submit.processing { background: #6b7280; }
            
            .history-table { margin-top: 24px; }
            .vendor-required { border-color: #ef4444 !important; }
        </style>

        <form method="post" id="proc_form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('puri_procure_action', 'puri_procure_nonce'); ?>
            <input type="hidden" name="action" value="puri_procure_submit">
            <input type="hidden" name="mode" id="proc_mode" value="nominal">
            <input type="hidden" name="vendor_id" id="vendor_id_hidden" value="">

            <div class="proc-container">
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

                <div class="proc-panel">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px">
                        <h2 style="margin:  0">Detail Transaksi</h2>
                        <div style="color:#6b7280"><?php echo esc_html(date_i18n('l, d F Y')); ?></div>
                    </div>

                    <div class="proc-tabs">
                        <button type="button" class="proc-tab active" id="tab_nominal">By Nominal</button>
                        <button type="button" class="proc-tab" id="tab_qty">By Quantity</button>
                    </div>

                    <div id="header_row" class="proc-row nominal" style="font-weight: 700; background:#f1f5f9; padding:  8px 4px; border-radius: 4px;">
                        <div class="col-sku">Item</div>
                        <div class="col-riyal" style="text-align: right">Total SAR</div>
                        <div class="col-kurs" style="text-align: right">Kurs Beli</div>
                        <div class="col-qty" style="text-align: right">Qty (pcs)</div>
                        <div class="col-idr" style="text-align: right">Total IDR</div>
                        <div class="col-action"></div>
                    </div>

                    <div id="proc_rows"></div>

                    <button type="button" id="btn_add_row" class="button" style="margin-top: 8px">+ Tambah Item</button>

                    <div class="proc-summary">
                        <div class="proc-summary-row total">
                            <span>Total Pembelian</span>
                            <span>Rp <span id="total_pembelian">0</span></span>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px; margin:  12px 0">
                        <input type="checkbox" id="confirm_data" name="confirm_ok" value="1">
                        <label for="confirm_data" style="font-weight: 600">Data sudah benar dan siap diproses</label>
                    </div>

                    <button type="submit" id="btn_submit" class="btn-submit" disabled>SUBMIT PEMBELIAN</button>
                </div>

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
                        <strong>⚠️ Penting:</strong> Total pembayaran harus sama dengan total pembelian. 
                    </div>
                </div>
            </div>
        </form>

        <div class="proc-panel history-table">
            <h3>📋 Riwayat Pembelian Bulan Ini</h3>
            <?php
            $journal_table = puri_table_name('T_JOURNAL');
            $current_month = date_i18n('Y-m');
            $hist = $wpdb->get_results($wpdb->prepare("
                SELECT ref_id, MIN(trx_date) as trx_date, MIN(description) as description, SUM(debit) as total_debit
                FROM {$journal_table}
                WHERE account_code = '1401' AND DATE_FORMAT(trx_date, '%%Y-%%m') = %s AND ref_id LIKE 'PRO-%%'
                GROUP BY ref_id ORDER BY MIN(trx_date) DESC LIMIT 10
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
                        <tr><td colspan="4" style="text-align: center; color: #6b7280; padding: 20px;">Belum ada pembelian bulan ini. </td></tr>
                    <?php else: ?>
                        <?php foreach ($hist as $h): ?>
                        <tr>
                            <td style="vertical-align: top;"><?php echo esc_html(date_i18n('d M Y, H:i', strtotime($h->trx_date))); ?></td>
                            <td style="vertical-align: top;"><code><?php echo esc_html($h->ref_id); ?></code></td>
                            <td style="word-wrap: break-word; white-space: normal; vertical-align: top;"><?php echo esc_html($h->description); ?></td>
                            <td style="text-align: right; font-weight: 700; vertical-align: top;">Rp <?php echo number_format_i18n($h->total_debit); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function(){
        var items = <?php echo wp_json_encode($items ?: []); ?>;
        var banks = <?php echo wp_json_encode($banks ?: []); ?>;
        
        var rowsContainer = document.getElementById('proc_rows');
        var paymentsContainer = document.getElementById('payment_sources');
        var headerRow = document.getElementById('header_row');
        var modeInput = document.getElementById('proc_mode');
        var vendorListbox = document.getElementById('vendor_listbox');
        var vendorHidden = document.getElementById('vendor_id_hidden');
        var totalPembelianEl = document.getElementById('total_pembelian');
        var totalBayarEl = document.getElementById('total_bayar');
        var selisihEl = document.getElementById('selisih_amount');
        var selisihRow = document.getElementById('selisih_row');
        var submitBtn = document.getElementById('btn_submit');
        var confirmChk = document.getElementById('confirm_data');
        var procForm = document.getElementById('proc_form');

        var mode = 'nominal';
        var rowCounter = 0;
        var paymentCounter = 0;

        // ═══════════════════════════════════════════════════════════════
        // UTILITY - Adopsi dari v6.0.6
        // ═══════════════════════════════════════════════════════════════
        
        function formatNumber(n) {
            return new Intl.NumberFormat('id-ID').format(parseFloat(n || 0));
        }
        
		function parseNumber(v) {
			v = String(v || '');
			v = v.replace(/\./g, '');
			v = v.replace(',', '.');
			v = v.replace(/[^\d\.\-]/g, '');
		return v === '' ? 0 : parseFloat(v);
		}
		
        // ═══════════════════════════════════════════════════════════════
        // VENDOR
        // ═══════════════════════════════════════════════════════════════
        
        vendorListbox.onchange = function() {
            vendorHidden.value = this.value;
            this.classList.remove('vendor-required');
            updateTotals();
        };

        // ═══════════════════════════════════════════════════════════════
        // BUILD ROW - Adopsi logic dari v6.0.6
        // ═══════════════════════════════════════════════════════════════
        
		function buildRow() {
			var idx = rowCounter++;
			var row = document.createElement('div');
			row.className = 'proc-row ' + mode;

			// COL SKU
			var colSku = document.createElement('div'); colSku.className = 'col-sku';
			var sel = document.createElement('select'); sel.name = 'items['+idx+'][id]'; sel.required = true;
			sel.innerHTML = '<option value="">-- Pilih --</option>';
			items.forEach(function(it){
				var o = document.createElement('option');
				o.value = it.id;
				o.text = it.sku + ' (SAR ' + it.denom_value + ')';
				o.dataset.denom = it.denom_value || 1;
				sel.appendChild(o);
			});
			colSku.appendChild(sel);

			// COL QTY
			var colQty = document.createElement('div'); colQty.className = 'col-qty';
			var qty = document.createElement('input'); qty.type = 'text'; qty.name = 'items['+idx+'][qty]'; qty.placeholder = '0';
			colQty.appendChild(qty);

			// COL KURS
			var colKurs = document.createElement('div'); colKurs.className = 'col-kurs';
			var kurs = document.createElement('input'); kurs.type = 'text'; kurs.name = 'items['+idx+'][kurs]'; kurs.placeholder = 'Rp';
			colKurs.appendChild(kurs);

			// COL RIYAL
			var colRiyal = document.createElement('div'); colRiyal.className = 'col-riyal';
			var riyal = document.createElement('input'); riyal.type = 'text'; riyal.name = 'items['+idx+'][total_riyal]'; riyal.placeholder = '0';
			colRiyal.appendChild(riyal);

			// COL IDR (readonly)
			var colIdr = document.createElement('div'); colIdr.className = 'col-idr';
			var idr = document.createElement('input'); idr.type = 'text'; idr.name = 'items['+idx+'][total_rupiah]';
			idr.className = 'readonly'; idr.readOnly = true; idr.value = '0';
			colIdr.appendChild(idr);

			// ACTION
			var colAction = document.createElement('div'); colAction.className = 'col-action';
			var btnRemove = document.createElement('button'); btnRemove.type = 'button'; btnRemove.className = 'btn-remove';
			btnRemove.textContent = '×'; btnRemove.onclick = function(){ row.remove(); updateTotals(); };
			colAction.appendChild(btnRemove);

			// APPEND columns to row (DOM order can stay: sku, qty, kurs, riyal, idr, action)
			row.appendChild(colSku);
			row.appendChild(colQty);
			row.appendChild(colKurs);
			row.appendChild(colRiyal);
			row.appendChild(colIdr);
			row.appendChild(colAction);

			// RECALC - core logic (use parseNumber/formatNumber)
			function recalc() {
				var selected = sel.selectedOptions[0];
				var denom = (selected && selected.value) ? parseNumber(selected.dataset.denom) : 0;
				var kursVal = parseNumber(kurs.value);
				var trVal = 0; // total riyal numeric

				if (mode === 'nominal') {
					// Total SAR taken from riyal input (user input)
					trVal = parseNumber(riyal.value);
					// compute qty from denom
					var qtyVal = denom > 0 ? (trVal / denom) : 0;
					qty.value = denom > 0 ? formatNumber(qtyVal) : '0';
				} else {
					// By quantity: qty user input → total riyal = qty * denom
					var qtyVal = parseNumber(qty.value);
					trVal = qtyVal * denom;
					riyal.value = formatNumber(trVal);
				}

				// Fixed formula: Total IDR = Total SAR × Kurs
				var idrNumeric = trVal * kursVal;
				idr.value = formatNumber(idrNumeric);

				// update totals summary
				updateTotals();
			}

			// EVENT BINDINGS — always call recalc (recalc handles mode)
			sel.onchange = recalc;
			kurs.oninput = recalc;
			qty.oninput = recalc;
			riyal.oninput = recalc;

			// save ref so setMode() can call it later
			row._recalc = recalc;

			// apply readonly/enabled states before first recalc
			applyRowMode(row);

			// append row to DOM
			rowsContainer.appendChild(row);

			// initial calc (use the saved ref)
			if (typeof row._recalc === 'function') row._recalc();

// update keyboard tab order to match visual order
    assignTabIndexes();


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
            amount.oninput = function() {
                amount.value = formatNumber(parseNumber(amount.value));
                updateTotals();
            };

            var btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.className = 'btn-remove';
            btnRemove.textContent = '×';
            btnRemove.onclick = function() { row.remove(); updateTotals(); };

            row.appendChild(sel);
            row.appendChild(amount);
            row.appendChild(btnRemove);
            paymentsContainer.appendChild(row);
			
// update keyboard tab order to match visual order
    assignTabIndexes();
			
			
            return row;
        }

        // ═══════════════════════════════════════════════════════════════
        // APPLY ROW MODE - Enable/Disable
        // ═══════════════════════════════════════════════════════════════
        
			function applyRowMode(row) {
				var qtyInput = row.querySelector('.col-qty input');
				var riyalInput = row.querySelector('.col-riyal input');
				var kursInput = row.querySelector('.col-kurs input');
				var idrInput = row.querySelector('.col-idr input');

				if (!qtyInput || !riyalInput || !kursInput || !idrInput) return;

				// Kurs always enabled
				kursInput.readOnly = false;
				kursInput.classList.remove('readonly');

				// Total IDR always readonly
				idrInput.readOnly = true;
				idrInput.classList.add('readonly');

				if (mode === 'nominal') {
					riyalInput.readOnly = false;
					riyalInput.classList.remove('readonly');
					qtyInput.readOnly = true;
					qtyInput.classList.add('readonly');
				} else {
					qtyInput.readOnly = false;
					qtyInput.classList.remove('readonly');
					riyalInput.readOnly = true;
					riyalInput.classList.add('readonly');
				}
			}

        // ═══════════════════════════════════════════════════════════════
        // SET MODE - Tab Switching
        // ═══════════════════════════════════════════════════════════════
        
			function setMode(newMode) {
				mode = newMode;
				modeInput.value = newMode;

				headerRow.classList.remove('nominal','quantity');
				headerRow.classList.add(newMode);

				rowsContainer.querySelectorAll('.proc-row').forEach(function(row){
					row.classList.remove('nominal','quantity');
					row.classList.add(newMode);
					applyRowMode(row);
					if (typeof row._recalc === 'function') row._recalc();
				});

				// update tab visual classes
				if (newMode === 'nominal') {
					document.getElementById('tab_nominal').classList.add('active');
					document.getElementById('tab_qty').classList.remove('active');
				} else {
					document.getElementById('tab_qty').classList.add('active');
					document.getElementById('tab_nominal').classList.remove('active');
				}
				
			// update keyboard tab order to match visual order
				assignTabIndexes();

				updateTotals();
			}

function assignTabIndexes() {
  var tab = 1;
  var rows = rowsContainer.querySelectorAll('.proc-row');
  rows.forEach(function(row) {
    var orderSelectors = mode === 'nominal'
      ? ['.col-sku select', '.col-riyal input', '.col-kurs input', '.col-qty input', '.col-idr input']
      : ['.col-sku select', '.col-qty input', '.col-kurs input', '.col-riyal input', '.col-idr input'];

    orderSelectors.forEach(function(sel) {
      var el = row.querySelector(sel);
      if (el) {
        el.tabIndex = tab++;
      }
    });
  });

  // Payment sources and other controls: continue tabindex
  var paymentEls = paymentsContainer.querySelectorAll('select,input,button');
  paymentEls.forEach(function(el) {
    el.tabIndex = tab++;
  });

  // finally checkbox and submit
  if (confirmChk) confirmChk.tabIndex = tab++;
  if (submitBtn) submitBtn.tabIndex = tab++;
}



        // ═══════════════════════════════════════════════════════════════
        // UPDATE TOTALS - Adopsi dari v6.0.6
        // ═══════════════════════════════════════════════════════════════
        
        function updateTotals() {
            var totalBelanja = 0;
            rowsContainer.querySelectorAll('.proc-row').forEach(function(r) {
                totalBelanja += parseNumber(r.querySelector('.col-idr input').value);
            });
            totalPembelianEl.textContent = formatNumber(totalBelanja);

            var totalBayar = 0;
            paymentsContainer.querySelectorAll('.proc-payment-row').forEach(function(r) {
                totalBayar += parseNumber(r.querySelector('input[type="text"]').value);
            });
            totalBayarEl.textContent = formatNumber(totalBayar);

            var diff = totalBayar - totalBelanja;
            selisihEl.textContent = 'Rp ' + formatNumber(Math.abs(diff));

            // Validasi balance (toleransi < 1 rupiah)
            if (Math.abs(diff) < 1 && totalBelanja > 0) {
                selisihRow.classList.add('zero');
                selisihRow.classList.remove('selisih');
                selisihEl.textContent = 'Rp 0 ✓';
                submitBtn.disabled = !(confirmChk.checked && vendorListbox.value !== '');
            } else {
                selisihRow.classList.remove('zero');
                selisihRow.classList.add('selisih');
                if (diff > 0) {
                    selisihEl.textContent = '(lebih) Rp ' + formatNumber(diff);
                } else if (diff < 0) {
                    selisihEl.textContent = '(kurang) Rp ' + formatNumber(Math.abs(diff));
                }
                submitBtn.disabled = true;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // EVENT BINDINGS
        // ═══════════════════════════════════════════════════════════════
        
        document.getElementById('tab_nominal').onclick = function() { setMode('nominal'); };
        document.getElementById('tab_qty').onclick = function() { setMode('quantity'); };
        document.getElementById('btn_add_row').onclick = function() { buildRow(); };
        document.getElementById('btn_add_payment').onclick = function() { buildPaymentRow(); };
        confirmChk.onchange = updateTotals;

        procForm.onsubmit = function(e) {
            if (! vendorListbox.value) {
                e.preventDefault();
                vendorListbox.classList.add('vendor-required');
                vendorListbox.focus();
                alert('Silakan pilih vendor terlebih dahulu! ');
                return false;
            }
            submitBtn.disabled = true;
            submitBtn.classList.add('processing');
            submitBtn.textContent = '⏳ MEMPROSES...';
        };

        // ═══════════════════════════════════════════════════════════════
        // INIT
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
 * SUBMIT HANDLER - Adopsi dari v6.0.7
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
    $vendor_code = function_exists('get_field') ? get_field('vendor_code', $vendor_id) : '';
    if (empty($vendor_code)) {
        $vendor_code = 'VND-' . str_pad($vendor_id, 2, '0', STR_PAD_LEFT);
    }

    $items = $_POST['items'] ?? [];
    $payments = $_POST['payments'] ?? [];
    
    if (empty($vendor_id)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Vendor harus dipilih! '), admin_url('admin.php? page=puri-procurement')));
        exit;
    }
    
    if (empty($items)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Minimal 1 item harus diisi!'), admin_url('admin.php?page=puri-procurement')));
        exit;
    }
    
    if (empty($payments)) {
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Sumber pembayaran harus diisi!'), admin_url('admin.php?page=puri-procurement')));
        exit;
    }
    
    $ref_id = 'PRO-' . date('ymdHi') . wp_rand(100, 999);
    
    $wpdb->query('START TRANSACTION');
    
    try {
        $total_belanja = 0;
        $items_processed = [];
        
        foreach ($items as $it) {
            $item_id = intval($it['id'] ?? 0);
            $qty = floatval(str_replace(['. ', ','], ['', '. '], $it['qty'] ?? '0'));
            $kurs = floatval(str_replace(['.', ','], ['', '.'], $it['kurs'] ?? '0'));
            $total_riyal = floatval(str_replace(['.', ','], ['', '.'], $it['total_riyal'] ?? '0'));
            
            if ($item_id <= 0 || $qty <= 0 || $kurs <= 0) continue;
            
            $item = $engine->get_item($item_id);
            if (!$item) throw new Exception("Item ID {$item_id} tidak ditemukan");
            
            $res = $engine->update_stock_atomic('gudang_utama', $item_id, $qty);
            if (is_wp_error($res)) throw new Exception('Gagal update stok:  ' . $res->get_error_message());
            
            $new_avg = $engine->calculate_moving_avg($item_id, $qty, $kurs, true);
            if (is_wp_error($new_avg)) throw new Exception('Gagal hitung HPP: ' . $new_avg->get_error_message());
            
            $wpdb->insert(puri_table_name('T_LEDGER'), [
                'location_id' => 'gudang_utama',
                'item_id' => $item_id,
                'qty_change' => $qty,
                'ref_id' => $ref_id,
                'description' => sprintf('Kulakan dari %s | Kurs:  Rp %s | HPP baru:  Rp %s',
                    $vendor_name, number_format($kurs, 0, ',', '.'), number_format($new_avg, 2, ',', '.')),
                'trx_date' => current_time('mysql')
            ], ['%s', '%d', '%f', '%s', '%s', '%s']);
            
            $denom = intval($item->denom_value) ?: 1;
            $subtotal = $total_riyal * $kurs;
            $total_belanja += $subtotal;
            
            $items_processed[] = [
                'sku' => $item->sku, 'qty' => $qty, 'denom' => $denom,
                'total_riyal' => $total_riyal, 'kurs' => $kurs,
                'subtotal' => $subtotal, 'new_hpp' => $new_avg
            ];
        }
        
        if (empty($items_processed)) throw new Exception('Tidak ada item valid yang diproses');
        
        $desc_items = array_map(function($it) {
            return sprintf('SAR %s (%s riyal @%s)',
                number_format($it['denom']), number_format($it['total_riyal']), number_format($it['kurs'], 0, ',', '.'));
        }, $items_processed);
        
        $journal_desc = sprintf('Kulakan %s [%s] :  %s', $vendor_code, $vendor_name, implode(' ; ', $desc_items));
        
        $engine->post_journal($ref_id, '1401', $total_belanja, 0, $journal_desc, [
            'vendor_id' => $vendor_id, 'vendor_name' => $vendor_name,
            'items' => $items_processed, 'total_idr' => $total_belanja
        ]);
        
        $total_bayar = 0;
        foreach ($payments as $pmt) {
            $acc_code = sanitize_text_field($pmt['account'] ?? '');
            $amount = floatval(str_replace(['.', ','], ['', '.'], $pmt['amount'] ?? '0'));
            if (empty($acc_code) || $amount <= 0) continue;
            
            $engine->post_journal($ref_id, $acc_code, 0, $amount, "Pembayaran kulakan {$ref_id} via {$acc_code}");
            $total_bayar += $amount;
        }
        
        if (abs($total_bayar - $total_belanja) > 1) {
            throw new Exception(sprintf('Total pembayaran (Rp %s) tidak sama dengan total belanja (Rp %s)',
                number_format($total_bayar), number_format($total_belanja)));
        }
        
        $wpdb->query('COMMIT');
        
        wp_redirect(add_query_arg('puri_procure_ok',
            urlencode("Berhasil!  Ref:  {$ref_id} | Total:  Rp " . number_format($total_belanja)),
            admin_url('admin.php?page=puri-procurement')));
        exit;
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[PURI Procurement ERROR] ' . $e->getMessage());
        wp_redirect(add_query_arg('puri_procure_err', urlencode('Gagal:  ' . $e->getMessage()), admin_url('admin.php? page=puri-procurement')));
        exit;
    }
}