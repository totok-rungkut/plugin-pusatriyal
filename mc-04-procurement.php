<?php
/**
 * MC 04 - PROCUREMENT HUB (STABLE HYBRID - PATCH 7.3.9)
 * UI/UX: 7.3.11 | Processor: Mozart Core
 * Fix: Database Column & Missing Function Journal
 */

defined('ABSPATH') || exit;

function puri_render_procurement_page() {
    puri_check_cap('manage_options');
    global $wpdb;
    
    // 1. DATA FETCHING (ITEM & VENDOR)
    $items_table = puri_table_name('T_ITEMS');
    $items = $wpdb->get_results("SELECT id, wp_post_id, sku, name, denom_value FROM {$items_table} WHERE type = 'currency' ORDER BY denom_value ASC");
    $vendors = get_posts(['post_type' => 'pr_vendor', 'posts_per_page' => -1, 'post_status' => 'publish']);
    $banks = $wpdb->get_results("SELECT code, name FROM " . puri_table_name('T_CHART') . " WHERE is_cash = 1 ORDER BY code ASC");

    if (isset($_GET['puri_procure_ok'])) echo '<div class="notice notice-success"><p>✅ ' . esc_html(urldecode($_GET['puri_procure_ok'])) . '</p></div>';
    ?>

    <style>
        /* CSS STABLE v6.6.12 */
        .proc-container { display: grid; grid-template-columns: 220px 1fr 350px; gap: 20px; margin-top: 20px; }
        .proc-panel { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .proc-listbox { width: 100%; height: 450px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .proc-tabs { display: flex; gap: 5px; margin-bottom: 15px; background: #f1f5f9; padding: 5px; border-radius: 6px; }
        .proc-tab { flex: 1; padding: 10px; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; color: #64748b; }
        .proc-tab.active { background: #0ea5e9; color: #fff; }
        
        .proc-header { display: grid; grid-template-columns: 140px 120px 100px 110px 140px 40px; gap: 10px; font-weight: bold; padding: 10px; background: #f8fafc; }
        .proc-row { display: grid; grid-template-columns: 140px 120px 100px 110px 140px 40px; gap: 10px; align-items: center; margin-bottom: 10px; }
        
        .proc-row input, .proc-row select { padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; text-align: right; }
		.proc-row select { text-align: left; }
        .proc-row .readonly { background: #f1f5f9; color: #475569; font-weight: bold; }
        .btn-submit { width: 100%; padding: 15px; background: #059669; color: #fff; border: none; border-radius: 6px; font-weight: 900; cursor: pointer; }
    </style>

    <div class="wrap">
        <h1>📦 Procurement Hub v7.3.9</h1>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('puri_procure_action', 'puri_procure_nonce'); ?>
            <input type="hidden" name="action" value="puri_procure_submit">
            <input type="hidden" name="mode" id="proc_mode" value="nominal">
            <input type="hidden" name="vendor_id" id="vendor_id_hidden" value="">

            <div class="proc-container">
                <div class="proc-panel">
                    <h3>Vendor</h3>
                    <select id="vendor_listbox" class="proc-listbox" size="20">
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo $v->ID; ?>"><?php echo esc_html($v->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="proc-panel">
                    <div class="proc-tabs">
                        <button type="button" class="proc-tab active" id="tab_nominal">BY NOMINAL (SAR)</button>
                        <button type="button" class="proc-tab" id="tab_qty">BY QUANTITY (LBR)</button>
                    </div>

                    <div class="proc-header">
                        <div style="order:1">Item</div>
                        <div id="head_c2" style="order:2">Total SAR</div>
                        <div style="order:3">Kurs Beli</div>
                        <div id="head_c4" style="order:4">Qty (Lbr)</div>
                        <div style="order:5">Total IDR</div>
                    </div>

                    <div id="proc_rows" style="margin-top: 15px;"></div>
                    <button type="button" id="btn_add_row" class="button">+ Tambah Baris</button>

                    <div style="margin-top:30px; font-size: 20px; font-weight: 900; border-top: 2px solid #0ea5e9; padding-top:10px;">
                        TOTAL: <span id="total_pembelian">Rp 0</span>
                    </div>
                    <br>
                    <input type="checkbox" id="confirm_data"> <label>Data sudah benar</label>
                    <button type="submit" id="btn_submit" class="btn-submit" disabled>SUBMIT PEMBELIAN</button>
                </div>

                <div class="proc-panel">
                    <h3>Pembayaran</h3>
                    <div id="payment_rows"></div>
                    <button type="button" id="btn_add_payment" class="button" style="width:100%">+ Tambah Bayar</button>
                </div>
            </div>
        </form>
        
        <div class="proc-panel" style="margin-top:20px;">
            <h3>📋 Riwayat 5 Transaksi Terakhir</h3>
            <table class="widefat striped">
                <thead><tr><th>Ref</th><th>Deskripsi</th><th>Total</th></tr></thead>
                <tbody>
                    <?php 
                    $hist = $wpdb->get_results("SELECT ref_id, description, SUM(debit) as total FROM ".puri_table_name('T_JOURNAL')." WHERE ref_id LIKE 'PRO-%' GROUP BY ref_id ORDER BY id DESC LIMIT 5");
                    foreach($hist as $h) echo "<tr><td><code>{$h->ref_id}</code></td><td>{$h->description}</td><td>Rp ".number_format($h->total)."</td></tr>";
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function() {
        const items = <?php echo wp_json_encode($items); ?>;
        const banks = <?php echo wp_json_encode($banks); ?>;
        let mode = 'nominal', rIdx = 0, pIdx = 0;
        const fmt = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
        const cln = (v) => parseFloat(String(v).replace(/\./g, '').replace(',', '.') || 0);

        function updateTotals() {
            let bel = 0; document.querySelectorAll('.item-row .col-idr-val').forEach(i => bel += cln(i.value));
            let bay = 0; document.querySelectorAll('.pay-amt').forEach(i => bay += cln(i.value));
            document.getElementById('total_pembelian').textContent = 'Rp ' + fmt(bel);
            const isKlop = Math.abs(bay - bel) < 1 && bel > 0;
            document.getElementById('btn_submit').disabled = !(isKlop && document.getElementById('vendor_id_hidden').value && document.getElementById('confirm_data').checked);
        }

        function buildRow() {
            const idx = rIdx++;
            const row = document.createElement('div');
            row.className = 'proc-row item-row';
			
// Tentukan tabindex berdasarkan mode
	// SKU selalu 1, Kurs selalu 3, Total IDR selalu 5
	const tiSAR = (mode === 'nominal') ? 2 : 4;
	const tiQTY = (mode === 'nominal') ? 4 : 2;			
			
row.innerHTML = `
                <div style="order:1"><select name="items[${idx}][id]" required tabindex="1"><option value="">- Item -</option>${items.map(i=>`<option value="${i.wp_post_id}" data-denom="${i.denom_value}">${i.sku}</option>`).join('')}</select></div>
                <div class="c2" style="order:2"><input type="text" name="items[${idx}][total_riyal]" class="sar-in" tabindex="${tiSAR}"></div>
                <div style="order:3"><input type="text" name="items[${idx}][kurs]" class="ks-in" tabindex="3"></div>
                <div class="c4" style="order:4"><input type="text" name="items[${idx}][qty]" class="qty-in" tabindex="${tiQTY}"></div>
                <div style="order:5"><input type="text" class="readonly col-idr-val" readonly value="0" tabindex="-1"></div>
                <div style="order:6"><button type="button" class="btn-remove" tabindex="-1">×</button></div>
            `;
			
            const sel = row.querySelector('select'), sar = row.querySelector('.sar-in'), ks = row.querySelector('.ks-in'), qty = row.querySelector('.qty-in'), idr = row.querySelector('.col-idr-val');

            function sync() {
                const den = cln(sel.selectedOptions[0]?.dataset.denom || 1), kurs = cln(ks.value);
                if (mode === 'nominal') {
                    const s = cln(sar.value); qty.value = fmt(s / den); idr.value = fmt(s * kurs);
                } else {
                    const q = cln(qty.value); sar.value = fmt(q * den); idr.value = fmt(q * den * kurs);
                }
                updateTotals();
            }

            [sar, ks, qty].forEach(el => el.oninput = function() { if(el !== ks) this.value = fmt(cln(this.value)); sync(); });
            sel.onchange = sync;
            row.querySelector('.btn-remove').onclick = () => { row.remove(); updateTotals(); };
            document.getElementById('proc_rows').appendChild(row);
            if(mode === 'quantity') { sar.readOnly = true; sar.classList.add('readonly'); qty.readOnly = false; qty.classList.remove('readonly'); row.querySelector('.c2').style.order=4; row.querySelector('.c4').style.order=2; }
            else { sar.readOnly = false; sar.classList.remove('readonly'); qty.readOnly = true; qty.classList.add('readonly'); row.querySelector('.c2').style.order=2; row.querySelector('.c4').style.order=4; }
        }

        // document.getElementById('tab_nominal').onclick = function() { mode = 'nominal'; this.classList.add('active'); document.getElementById('tab_qty').classList.remove('active'); document.getElementById('proc_mode').value='nominal'; document.getElementById('proc_rows').innerHTML=''; buildRow(); };
        // document.getElementById('tab_qty').onclick = function() { mode = 'quantity'; this.classList.add('active'); document.getElementById('tab_nominal').classList.remove('active'); document.getElementById('proc_mode').value='quantity'; document.getElementById('proc_rows').innerHTML=''; buildRow(); };
		// ...baris di atas ini diganti dengan match berikut:
		// ...mulai .....
        function switchMode(newMode) {
            mode = newMode;
            document.getElementById('proc_mode').value = newMode;
            document.getElementById('proc_rows').innerHTML = ''; // Bersihkan baris lama
            
            // Logic Tab Active
            document.getElementById('tab_nominal').classList.toggle('active', mode === 'nominal');
            document.getElementById('tab_qty').classList.toggle('active', mode === 'quantity');

            // Logic Swap Header (Total SAR vs Qty)
            const hC2 = document.getElementById('head_c2');
            const hC4 = document.getElementById('head_c4');
            
            if (hC2 && hC4) {
                if (mode === 'nominal') {
                    hC2.style.order = 2; hC4.style.order = 4;
                } else {
                    hC2.style.order = 4; hC4.style.order = 2;
                }
            }
            
            buildRow(); // Buat baris baru sesuai mode
			
			// Otomatis fokus ke baris pertama elemen pertama agar user bisa langsung ngetik
            const firstSelect = document.querySelector('.item-row select');
            if (firstSelect) firstSelect.focus();
        }

        document.getElementById('tab_nominal').onclick = () => switchMode('nominal');
        document.getElementById('tab_qty').onclick = () => switchMode('quantity');
        // --- SELESAI PATCH 3 ---
		
		
        document.getElementById('vendor_listbox').onchange = function() { document.getElementById('vendor_id_hidden').value = this.value; updateTotals(); };
        document.getElementById('btn_add_payment').onclick = function() {
            const idx = pIdx++;
            const div = document.createElement('div');
            div.style = "display:grid; grid-template-columns: 1fr 120px 30px; gap:5px; margin-bottom:8px;";
            div.innerHTML = `<select name="payments[${idx}][account]" required>${banks.map(b=>`<option value="${b.code}">${b.name}</option>`).join('')}</select>
                             <input type="text" name="payments[${idx}][amount]" class="pay-amt">
                             <button type="button" class="btn-remove">×</button>`;
            div.querySelector('.pay-amt').oninput = function() { this.value = fmt(cln(this.value)); updateTotals(); };
            div.querySelector('.btn-remove').onclick = () => { div.remove(); updateTotals(); };
            document.getElementById('payment_rows').appendChild(div);
        };
        document.getElementById('btn_add_row').onclick = buildRow;
        document.getElementById('confirm_data').onchange = updateTotals;
        buildRow();
    })();
    </script>
<?php
}

// -----------------------------------------------------------------------------
// POST HANDLER - MOZART BRIDGE (RESULT 6.6.12 COMPATIBLE)
// -----------------------------------------------------------------------------
add_action('admin_post_puri_procure_submit', function() {
    check_admin_referer('puri_procure_action', 'puri_procure_nonce');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $ref = 'PRO-' . strtoupper(wp_generate_password(6, false));
        $items = $_POST['items'] ?? [];
        $pays = $_POST['payments'] ?? [];
        $vendor_id = intval($_POST['vendor_id']);
        
        foreach ($items as $it) {
            $qty = floatval(str_replace('.', '', $it['qty']));
            $kurs = floatval(str_replace('.', '', $it['kurs']));
            $sar = floatval(str_replace('.', '', $it['total_riyal']));
            if ($qty <= 0) continue;

            $sql_id = puri_get_item_sql_id($it['id']);
            $it_info = $wpdb->get_row($wpdb->prepare("SELECT sku, denom_value FROM " . puri_table_name('T_ITEMS') . " WHERE id = %d", $sql_id));

            // SNAPSHOT & JURNAL (MENGGANTI puri_insert_journal YANG MISSING)
            $snap = json_encode(['mode'=>$_POST['mode'], 'denom'=>$it_info->denom_value, 'kurs_beli'=>$kurs, 'total_valas'=>$sar, 'qty_lbr'=>$qty, 'vendor_id'=>$vendor_id]);
            
            // 1. Debit Persediaan
            $wpdb->insert(puri_table_name('T_JOURNAL'), [
                'trx_date' => current_time('mysql'), 'ref_id' => $ref, 'account_code' => '1103001', // Sesuaikan kode persediaan Anda
                'debit' => $sar * $kurs, 'credit' => 0, 'description' => "Procurement {$it_info->sku} | Snap: {$snap}"
            ]);

            // 2. Update HPP di Master (Hanya base_price, tanpa stock_qty karena error kolom)
            $wpdb->update(puri_table_name('T_ITEMS'), ['base_price' => $kurs], ['id' => $sql_id]);
        }

        // 3. Kredit Pembayaran
        foreach ($pays as $p) {
            $amt = floatval(str_replace('.', '', $p['amount']));
            if ($amt > 0) {
                $wpdb->insert(puri_table_name('T_JOURNAL'), [
                    'trx_date' => current_time('mysql'), 'ref_id' => $ref, 'account_code' => $p['account'],
                    'debit' => 0, 'credit' => $amt, 'description' => "Payment Procurement {$ref}"
                ]);
            }
        }

        $wpdb->query('COMMIT');
        wp_redirect(admin_url('admin.php?page=puri-procurement&puri_procure_ok=' . urlencode($ref)));
    } catch (Exception $e) { $wpdb->query('ROLLBACK'); wp_die($e->getMessage()); }
    exit;
});

// AUTO-BRIDGE WP_ID TO SQL_ID
if (!function_exists('puri_get_item_sql_id')) {
    function puri_get_item_sql_id($wp_id) {
        global $wpdb; $t = puri_table_name('T_ITEMS');
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE wp_post_id = %d", $wp_id));
        if (!$id) {
            $wpdb->insert($t, [
                'wp_post_id' => $wp_id, 'sku' => get_post_meta($wp_id, 'item_sku_code', true) ?: 'SKU-'.$wp_id,
                'name' => get_the_title($wp_id), 'type' => 'currency', 'denom_value' => get_post_meta($wp_id, 'denom_value', true) ?: 1
            ]);
            $id = $wpdb->insert_id;
        }
        return $id;
    }
}