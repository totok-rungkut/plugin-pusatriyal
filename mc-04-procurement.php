<?php
/**
 * MC 04 - PROCUREMENT HUB (STABLE HYBRID - PATCH 7.3.9)
 * UI/UX: 7.3.14 | Processor: Mozart Core
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
	$locations = get_option('puri_inv_locations', [['id' => 'gudang-00', 'name' => 'Gudang Utama'] ]);  // Ambil data lokasi dari setting MC-29 (wp_options)
    //$banks = $wpdb->get_results("SELECT code, name FROM " . puri_table_name('T_CHART') . " WHERE is_cash = 1 ORDER BY code ASC");
	
// PATCH: Ambil Nama dan Saldo Akhir akun Kas/Bank

// PATCH: Hitung saldo = saldo_awal (T_CHART.balance) + mutasi (SUM debit-credit dari T_JOURNAL)
$banks = $wpdb->get_results("
    SELECT c.code, c.name,
           ROUND(
               COALESCE(c.balance, 0) +
               COALESCE(j.mutasi, 0), 2
           ) AS live_balance
    FROM " . puri_table_name('T_CHART') . " c
    LEFT JOIN (
        SELECT account_code, SUM(debit - credit) AS mutasi
        FROM " . puri_table_name('T_JOURNAL') . "
        GROUP BY account_code
    ) j ON j.account_code = c.code
    WHERE c.is_cash = 1
    ORDER BY c.code ASC
");

    if (isset($_GET['puri_procure_ok'])) echo '<div class="notice notice-success"><p>✅ ' . esc_html(urldecode($_GET['puri_procure_ok'])) . '</p></div>';
    ?>

    <style>
        /* CSS STABLE v6.6.12 */
        .proc-container { display: grid; grid-template-columns: 220px 1fr 400px; gap: 20px; margin-top: 20px; }
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
        .btn-submit { width: 60%; padding: 15px; background: #059669; color: #fff; border: none; border-radius: 6px; font-size: bigger; font-weight: 700; cursor: pointer; margin-left:auto;}
		
.parent {display:flex;flex-direction:column;min-height:99%; gap:4px;}
.parent h3 {height:40px;margin:0;font-size:28px;text-align:center;}
.pay_info_box {height:30px;padding:10px; background:#f0f9ff;}
.info_saldo_box {padding:10px; min-height:30px; border-top:2px solid #999; background: #fcf8f4; padding-top: 15px;}
.content_wrap {flex:1 1 auto;min-height:0;display:grid;grid-template-rows:auto 1fr auto;}
.payment_row {padding:10px;background:#bfb;overflow-y:auto;min-height:0;}
.btn_wrap {display:flex;padding-top:10px;justify-content:center;align-items:flex-start;}
.btn_wrap button {min-width:200px;}

.pay-warning { color: #e11d48; font-weight: bold; font-size: 11px; margin-top: 2px; display: block; }
.input-error { border: 2px solid #e11d48 !important; background-color: #fff1f2 !important; }

.proc-tab.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; background: #e2e8f0; color: #94a3b8; }
		
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

<hr>
<div style="margin-top:5px; font-size: 22px; font-weight: 500; padding:10px 15px;text-align:right;">
    TOTAL: <span id="total_pembelian" style="font-weight: 300;">Rp 0</span>
</div>
<hr>

<div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px; background: #f0f9ff; padding: 10px; border-radius: 6px; border: 1px solid #bae6fd; margin-top: 35px;">
    <div>
        <label style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">📍 Lokasi Simpan:</label>
        <select name="location_id" id="location_id" style="padding: 5px; border-radius: 4px; border: 1px solid #cbd5e1; background: #f1f5f9; cursor: not-allowed;">
            <?php foreach ($locations as $loc): ?>
                <option value="<?php echo esc_attr($loc['id']); ?>" <?php selected($loc['id'], 'gudang-00'); ?>>
                    <?php echo esc_html($loc['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="margin-top: 18px;">
        <input type="checkbox" id="confirm_data"> <label for="confirm_data" style="font-weight: 600;">Data sudah benar</label>
    </div>
	<button type="submit" id="btn_submit" class="btn-submit" disabled>SUBMIT PEMBELIAN  <i data-lucide="send" style="width: 16px; height: 16px; margin-left: 15px;"></i>  </button>

</div>






                </div>

                <div class="proc-panel">
				<div class="parent">
                    <h3>Pembayaran</h3>
					
					<div id="pay_info_box" >
					   <div id="info_diff" style="font-weight: bold; margin-top: 5px; color: #e11d48;">KURANG: Rp 0</div>
					</div>					
					
    <div class="content_wrap">
                    <div id="payment_rows"></div>
		<div class="btn_wrap">			
                    <button type="button" id="btn_add_payment" class="button" style="width:100%">+ Tambah Bayar</button>
		</div>			
   </div>
					
					<div class="info_saldo_box" >
						<div id="info_balance">Pilih akun untuk lihat saldo...</div>
					</div>
                </div>
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
		
		
// --- Patch: Reset Tab State saat Init Sesi Baru ---
    const startTabs = [document.getElementById('tab_nominal'), document.getElementById('tab_qty')];
    startTabs.forEach(t => { if(t) t.classList.remove('disabled'); });		
		
 //       const fmt = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
 
 // Menggunakan maximumFractionDigits: 2 agar konsisten untuk mata uang
const fmt = (n) => new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
}).format(n || 0);
 
// PATCH: Cleaner angka sesuai format Indonesia
const clnInput = (v) => {
    if (!v) return 0;
    return parseFloat(
        String(v).replace(/\./g, '').replace(',', '.')
    ) || 0;
};




// PATCH: Cleaner angka, aman untuk angka mentah dari DB
const clnRaw = (v) => {
    if (!v) return 0;
    if (typeof v === 'number') return v; // kalau sudah angka, langsung pakai
    let s = String(v).trim();

    // Jika string sudah dalam format mentah (punya titik desimal, tanpa ribuan)
    if (/^\d+(\.\d+)?$/.test(s)) {
        return parseFloat(s);
    }

    // Jika format Indonesia (ribuan titik, desimal koma)
    return parseFloat(
        s.replace(/\./g, '')   // hapus titik ribuan
         .replace(',', '.')    // ganti koma desimal jadi titik
    ) || 0;
};

function updateTotals() {
            let bel = 0; document.querySelectorAll('.item-row .col-idr-val').forEach(i => bel += clnInput(i.value));
            let bay = 0; 

            document.querySelectorAll('#payment_rows > div').forEach(row => {
                const sel = row.querySelector('.pay-acc');
                const amtInput = row.querySelector('.pay-amt');
                const amtVal = clnInput(amtInput.value);
                const balLimit = clnInput(sel.selectedOptions[0]?.dataset.bal || 0);
                
                bay += amtVal;

                // Tampilkan Warning tapi tidak blokir input
                const warningId = 'warn-' + sel.name;
                let warnEl = row.querySelector('.pay-warning');
                
                if (sel.value && amtVal > balLimit) {
                    amtInput.style.backgroundColor = '#fff7ed'; // Warna orange tipis (warning)
                    if (!warnEl) {
                        warnEl = document.createElement('span');
                        warnEl.className = 'pay-warning';
                        warnEl.style.color = '#f59e0b'; // Warna Orange (Warning), bukan merah error
                        row.appendChild(warnEl);
                    }
                    warnEl.textContent = '⚠️ Saldo kurang (Modal talangan)';
                } else {
                    amtInput.style.backgroundColor = '';
                    if (warnEl) warnEl.remove();
                }
            });

            document.getElementById('total_pembelian').textContent = 'Rp ' + fmt(bel);
            
            let diff = bel - bay;
            const infoDiff = document.getElementById('info_diff');
            
            if (Math.abs(diff) < 1 && bel > 0) {
                infoDiff.textContent = '✅ STATUS: KLOP';
                infoDiff.style.color = '#059669';
            } else {
                infoDiff.textContent = (diff > 0 ? 'KURANG: Rp ' : 'LEBIH: Rp ') + fmt(Math.abs(diff));
                infoDiff.style.color = '#e11d48';
            }

            // KEMBALI KE SYARAT AWAL (Tanpa blokir saldo)
            const hasVendor = document.getElementById('vendor_id_hidden').value !== '';
            const isKlop = (Math.abs(diff) < 1 && bel > 0);
            const isConfirmed = document.getElementById('confirm_data').checked;

            document.getElementById('btn_submit').disabled = !(hasVendor && isKlop && isConfirmed);
			
			updateModeLock();
			
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
                <div style="order:1"><select name="items[${idx}][item_id]" required tabindex="1"><option value="">- Item -</option>${items.map(i=>`<option value="${i.wp_post_id}" data-denom="${i.denom_value}">${i.sku}</option>`).join('')}</select></div>
                <div class="c2" style="order:2"><input type="text" name="items[${idx}][total_riyal]" class="sar-in" tabindex="${tiSAR}"></div>
                <div style="order:3"><input type="text" name="items[${idx}][kurs]" class="ks-in" tabindex="3"></div>
                <div class="c4" style="order:4"><input type="text" name="items[${idx}][qty]" class="qty-in" tabindex="${tiQTY}"></div>
                <div style="order:5"><input type="text" class="readonly col-idr-val" readonly value="0" tabindex="-1"></div>
                <div style="order:6"><button type="button" class="btn-remove" tabindex="-1">×</button></div>
            `;
			
            const sel = row.querySelector('select'), sar = row.querySelector('.sar-in'), ks = row.querySelector('.ks-in'), qty = row.querySelector('.qty-in'), idr = row.querySelector('.col-idr-val');

            function sync() {
                const den = clnInput(sel.selectedOptions[0]?.dataset.denom || 1), kurs = clnInput(ks.value);
                if (mode === 'nominal') {
                    const s = clnInput(sar.value); qty.value = fmt(s / den); idr.value = fmt(s * kurs);
                } else {
                    const q = clnInput(qty.value); sar.value = fmt(q * den); idr.value = fmt(q * den * kurs);
                }
                updateTotals();
            }

            [sar, ks, qty].forEach(el => el.oninput = function() { if(el !== ks) this.value = fmt(clnInput(this.value)); sync(); });
            sel.onchange = sync;
            row.querySelector('.btn-remove').onclick = () => { row.remove(); updateTotals(); updateModeLock(); };
            document.getElementById('proc_rows').appendChild(row);
            if(mode === 'quantity') { sar.readOnly = true; sar.classList.add('readonly'); qty.readOnly = false; qty.classList.remove('readonly'); row.querySelector('.c2').style.order=4; row.querySelector('.c4').style.order=2; }
            else { sar.readOnly = false; sar.classList.remove('readonly'); qty.readOnly = true; qty.classList.add('readonly'); row.querySelector('.c2').style.order=2; row.querySelector('.c4').style.order=4; }
        }

// PATCH: Fungsi untuk mengunci/membuka Tab Mode
function updateModeLock() {
    const rows = document.querySelectorAll('.item-row');
    
    // Logic: Jika tidak ada baris, hasData otomatis false
    const hasData = Array.from(rows).some(row => {
        const sel = row.querySelector('select').value;
        const valas = clnInput(row.querySelector('.sar-in').value);
        const qty = clnInput(row.querySelector('.qty-in').value);
        return sel !== "" || valas > 0 || qty > 0;
    });

    const tabs = [document.getElementById('tab_nominal'), document.getElementById('tab_qty')];
    
    // Patch: Jika tidak ada baris, pastikan semua disabled dihapus
    if (rows.length === 0 || !hasData) {
        tabs.forEach(t => {
            if(t) t.classList.remove('disabled');
        });
    } else {
        tabs.forEach(t => {
            if(t && !t.classList.contains('active')) t.classList.add('disabled');
        });
    }
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

	// Masukkan data saldo ke dalam atribut data-balance
	div.innerHTML = `
		<select name="payments[${idx}][account]" class="pay-acc" required>
			<option value="" data-bal="0">-- Pilih Kas/Bank --</option>
${banks.map(b => `<option value="${b.code}" data-bal="${b.live_balance || 0}">${b.name}</option>`).join('')}
		</select>
		<input type="text" name="payments[${idx}][amount]" class="pay-amt" placeholder="Rp 0">
		<button type="button" class="btn-remove">×</button>`;

	const sel = div.querySelector('.pay-acc');
	const amt = div.querySelector('.pay-amt');

	// Event saat pilih Akun (Tampilkan Saldo)
	sel.onchange = function() {
		const opt = this.selectedOptions[0];
		const bal = clnRaw(opt.dataset.bal);
		document.getElementById('info_balance').innerHTML = `Saldo <b>${opt.text}</b>: <span style="color:${bal < 0 ? 'red' : 'green'}">Rp ${fmt(bal)}</span>`;
		updateTotals();
	};

	amt.oninput = function() { this.value = fmt(clnInput(this.value)); updateTotals(); };
	div.querySelector('.btn-remove').onclick = () => { div.remove(); updateTotals(); };

	document.getElementById('payment_rows').appendChild(div);
	updateTotals();
};



        document.getElementById('btn_add_row').onclick = buildRow;
        document.getElementById('confirm_data').onchange = updateTotals;
        buildRow();
    })();

// activate font Lucide ------------------
jQuery(document).ready(function($) { if (typeof lucide !== 'undefined') { lucide.createIcons(); } });

	
</script>

<?php
}

// -----------------------------------------------------------------------------
// POST HANDLER - MOZART BRIDGE (RESULT 6.6.12 COMPATIBLE)
// -----------------------------------------------------------------------------
/**
 * HANDLER: MC-04 PROCUREMENT HUB (Mozart Bridge)
 * Version: 7.3.14 (Stable Hybrid)
 * Logic: Cash-Based System, Single Source of Truth
 */
add_action('admin_post_puri_procure_submit', function() {
    puri_check_cap('manage_options');
	
	if (!isset($_POST['puri_procure_nonce']) || !wp_verify_nonce($_POST['puri_procure_nonce'], 'puri_procure_action')) {
        wp_die("Sesi kedaluwarsa atau akses tidak sah. Silakan refresh halaman.");
    }
	
    global $wpdb;

    // 1. DATA GATHERING (Identitas Partner & Referensi)
    $vendor_id   = intval($_POST['vendor_id']);
    $vendor_post = get_post($vendor_id);
    $vendor_name = $vendor_post ? $vendor_post->post_title : 'Unknown Vendor';
    $vendor_code = get_post_meta($vendor_id, 'vendor_code', true) ?: 'VND-' . $vendor_id;
	$location_id = sanitize_text_field($_POST['location_id'] ?? 'MAIN');
    
    $description = sanitize_text_field($_POST['description'] ?? '');
    $items_raw   = $_POST['items'] ?? [];
    $pays_raw    = $_POST['payments'] ?? [];
    
    // Referensi Unik Transaksi
    $source_ref  = 'PRO-' . current_time('Ymd') . '-' . strtoupper(wp_generate_password(3, false));

    try {
        if (empty($items_raw)) throw new Exception("Daftar item tidak boleh kosong.");

        $total_belanja_idr = 0;
        $items_for_engine  = []; // Menjadi sumber tunggal data item (Logic & Audit)

		// Helper function lokal untuk membersihkan angka (Ribuan Titik/Koma)
			$clean_num = function($val) {
			if (is_array($val)) return 0;
			// Hapus titik ribuan, ganti koma desimal menjadi titik jika ada
			$v = str_replace('.', '', $val); 
			$v = str_replace(',', '.', $v); 
			return floatval($v);
		};

        // 2. LOOPING & DATA ENRICHMENT (Menyusun Nampan Matang untuk Mozart)
        foreach ($items_raw as $it) {
            $item_id     = intval($it['item_id']);
            // Pembersihan karakter non-numeric jika ada formatting ribuan dari JS
			$qty         = $clean_num($it['qty']); 
			$kurs        = $clean_num($it['kurs']);
			$total_valas = $clean_num($it['total_riyal']);            

            if ($qty <= 0 && $total_valas <= 0) continue;

            // AMBIL INFO ITEM: Agar Mozart bisa meledakkan (explode) SKU untuk deskripsi jurnal
            $it_info = $wpdb->get_row($wpdb->prepare(
                "SELECT sku, denom_value, name FROM ".puri_table_name('T_ITEMS')." WHERE id = %d", 
                $item_id
            ));
            
            $sku   = $it_info ? $it_info->sku : 'SKU-'.$item_id;
            $denom = $it_info ? $it_info->denom_value : 1;
            $name  = $it_info ? $it_info->name : 'Unknown Item';

            // KALKULASI: Total IDR adalah Volume Uang (Valas) x Kurs
            $subtotal_idr = $total_valas * $kurs;
            $total_belanja_idr += $subtotal_idr;

            // Masukkan ke array tunggal (Items Engine)
            $items_for_engine[] = [
                'item_id'     => $item_id,
                'sku'         => $sku,
                'name'        => $name,
                'denom'       => $denom,
                'qty'         => $qty,          // Lembaran Fisik
                'total_valas' => $total_valas,  // Volume Uang
                'unit_price'  => $kurs,         // Kurs Beli (Basis HPP)
                'subtotal_idr'=> $subtotal_idr
            ];
        }

        // 3. PAYMENTS GATHERING (Verifikasi Cash-Based)
        $total_pembayaran = 0;
        $payments_for_engine = [];
        foreach ($pays_raw as $p) {
			$amount = $clean_num($p['amount']);
            if ($amount <= 0) continue;

            $total_pembayaran += $amount;
            $payments_for_engine[] = [
                'account_code' => sanitize_text_field($p['account']),
                'amount'       => $amount
            ];
        }

        // VALIDASI AKHIR: Harus Balance (Hanya Cash-Base, Tidak Boleh Ada Hutang)
        if (abs($total_belanja_idr - $total_pembayaran) > 0.01) {
            throw new Exception("Transaksi tidak balance! Belanja: ".number_format($total_belanja_idr).", Pembayaran: ".number_format($total_pembayaran));
        }

        // 4. BUNDLING TRX_PARAM GENERIK (Format Baku untuk Mozart MC-03)
        $trx_param = [
            'source'            => 'procurement',
            'source_ref'        => $source_ref,
            'counterparty_id'   => $vendor_code,
            'counterparty_name' => $vendor_name,
            'total_idr'         => $total_belanja_idr,
            'description'       => $description,
			'location_id'       => $location_id, 
            'created_at'        => current_time('mysql'),
            'created_by'        => get_current_user_id(),
            'items'             => $items_for_engine,    // Mozart akan meracik deskripsi dari sini
            'payments'          => $payments_for_engine, // Dasar penjurnalan sisi Credit (Cash/Bank)
            'recalculate_hpp'   => true
        ];

        // 5. SNAPSHOT JSON (Membekukan seluruh trx_param sebagai Audit Trail)
		if (abs($total_belanja_idr - $total_pembayaran) > 0.1) {
             throw new Exception("Transaksi tidak balance! Belanja: " . number_format($total_belanja_idr) . ", Pembayaran: " . number_format($total_pembayaran));
        }
		
        $trx_param['snapshot_json'] = json_encode($trx_param);

        // 6. DELIVERY TO MOZART (The Conductor)
        // Mozart mengurus: DB Transaction, Stok, Ledger, & Jurnal matang via Accountant
        $eng_ref = puri_mozart()->execute('procurement', $trx_param);

        if (is_wp_error($eng_ref)) {
            throw new Exception($eng_ref->get_error_message());
        }

        // 7. FINISH & REDIRECT
        wp_safe_redirect(admin_url('admin.php?page=puri-procurement&puri_procure_ok=' . urlencode($source_ref)));
        exit;

    } catch (Exception $e) {
        wp_die("Kesalahan Orkestrasi Mozart (MC-04): " . $e->getMessage());
    }
});


// AUTO-BRIDGE WP_ID TO SQL_ID (REVISED v7.3.14)
if (!function_exists('puri_get_item_sql_id')) {
    function puri_get_item_sql_id($wp_id) {
        global $wpdb; 
        $t = puri_table_name('T_ITEMS');
        
        // 1. Coba cari ID yang sudah ada
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE wp_post_id = %d", $wp_id));
        
        // 2. Jika tidak ada, jangan asal INSERT. 
        // Dilema: Jika dipaksa INSERT, metadata (denom, sku) mungkin belum siap di WP.
        if (!$id) {
            $sku   = get_post_meta($wp_id, 'item_sku_code', true) ?: 'SKU-'.$wp_id;
            $denom = get_post_meta($wp_id, 'denom_value', true) ?: 1;
            $name  = get_the_title($wp_id);

            $wpdb->insert($t, [
                'wp_post_id'  => $wp_id, 
                'sku'         => $sku,
                'name'        => $name, 
                'type'        => 'currency', 
                'denom_value' => $denom,
                'created_at'  => current_time('mysql')
            ]);
            $id = $wpdb->insert_id;
        }
        
        return $id;
    }
}