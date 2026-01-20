<?php
/**
 * MC 08 - Stock Transfer & Laci Controller (Mozart Compatible)
 * Version: 7.3.16 (FIXED)
 * Author: Denmas Totok / Refactor by Mozart Team
 *
 * CHANGELOG v7.3.15:
 * - ✅ Fix kolom 'qty' → 'balance' (sesuai schema MC-01)
 * - ✅ Fix location_id sesuai data real dari T_STOCK
 * - ✅ Tambah error handling & debug mode
 * - ✅ Sinkronisasi dengan Mozart Engine
 */

defined('ABSPATH') || exit;

add_action('wp_ajax_puri_execute_transfer_ajax', 'puri_execute_transfer_ajax_handler');

function puri_render_transfer_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    // ✅ FIX: Gunakan kolom 'balance' dan ambil location_id yang ada
$items = $wpdb->get_results("
    SELECT i.id, i.wp_post_id, i.sku, i.name, i.denom_value,
           COALESCE(s.balance, 0) as stock_gudang,
           s.location_id as current_location
    FROM " . puri_table_name('T_ITEMS') . " i
    LEFT JOIN " . puri_table_name('T_STOCK') . " s
      ON i.id = s.item_id
         OR i.wp_post_id = s.item_id   -- 🔑 bridging ke ID CPT lama
    WHERE i.type = 'currency'
    ORDER BY i.denom_value ASC, i.sku ASC
");

    // 🔍 DEBUG: Cek apakah query berhasil
    if (empty($items)) {
        echo '<div class="notice notice-warning"><p>⚠️ <b>DEBUG:</b> Tidak ada data item ditemukan. Pastikan tabel T_ITEMS dan T_STOCK sudah terisi.</p></div>';
    }

    // Untuk summary: calculate total riyal and breakdown
    $summary = [];
    $total_riyal = 0;
    foreach ($items as $it) {
        $bendels = floor(floatval($it->stock_gudang) / 100);
        $riyal   = $bendels * intval($it->denom_value) * 100;
        $summary[] = [
            'sku'    => $it->sku,
            'denom'  => $it->denom_value,
            'qty'    => $bendels,
            'nilai'  => $riyal,
        ];
        $total_riyal += $riyal;
    }

    // "Stok dalam perjalanan" - bisa diganti query real
    $stok_perjalanan = 0; // TODO: Hitung dari T_LEDGER status 'in_transit'

    // "Total Asset" - hitung dari inventory value
    $total_asset_idr = $wpdb->get_var("
        SELECT SUM(s.balance * i.base_price) 
        FROM " . puri_table_name('T_STOCK') . " s
        JOIN " . puri_table_name('T_ITEMS') . " i ON s.item_id = i.id
    ") ?: 0;
    ?>
    <style>
    /* Chrome, Safari, Edge - Remove number spinner */
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    
    .puri-transfer-wrap { 
        display:flex; 
        width:98%; 
        flex-wrap:wrap; 
        background:#fdfdfd; 
        border:1px solid #bbb; 
        border-radius:6px; 
        padding:10px; 
        margin-bottom:16px; 
    }
    
    /* Stock Panel Container */
    .puri-stock-grid { 
        display: flex; 
        gap: 20px 25px; 
        flex-wrap: wrap; 
        justify-content: flex-start; 
        padding:0 10px;
    }

    /* Stock Card Wrapper */
    .puri-stock-card { 
        border: 1.5px solid #bbb; 
        border-radius:4px; 
        padding:14px 12px 13px; 
        box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.08); 
        background: #fafbfc; 
        display: flex; 
        flex-direction: column; 
        max-width: 250px; 
        min-width: 230px; 
        box-sizing:border-box;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .puri-stock-card:hover { 
        box-shadow: 4px 4px 6px rgba(0, 0, 0, 0.15); 
        transform: translateY(-2px); 
    }
    
    .puri-stock-card:active { 
        box-shadow: none; 
        transform: translateY(0); 
    }
    
    .puri-stock-card:focus-visible { 
        outline: 3px solid rgba(119, 51, 5, 0.25); 
        outline-offset: 2px;
    }
    
    .puri-stock-badge { 
        display:inline-block; 
        font-weight:bold; 
        font-size:13px; 
        padding:1px 4px 0; 
        border-radius:4px; 
        margin-bottom:2px;
    }
    
    .puri-badge-grey { background:#eee; color:#ccc; border:1px solid #ddd;}
    .puri-badge-blue { background:#eff6ff; color:#2461cb; border:1px solid #60a5fa;}
    .puri-badge-red { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5;}
    
    /* Transfer Panel Container */
    .puri-transfer-panel { 
        display:flex; 
        flex-direction:column; 
        border:1.5px solid #bbb; 
        border-radius:7px; 
        background:#fff; 
        padding:13px 13px 8px 18px; 
        margin-bottom:13px; 
    }
    
    .puri-transfer-list { 
        margin:14px 0 8px 0;
        padding:0;
        min-height:80px;
        list-style:none;
        font-size:15px;
    }
    
    .puri-transfer-list li { 
        margin-bottom:4px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        border-bottom: 1px dotted #999;
        padding: 4px 0;
    }
    
    .puri-laci-btn { 
        background:#fff; 
        color:#333; 
        border:1px solid #bbb; 
        border-radius:0px 25px 25px 0px; 
        padding:3.5px 16px; 
        cursor:pointer; 
        margin-left:-1px; 
        min-height:30px; 
        width:100%;
        transition: all 0.2s ease;
    }
    
    .puri-laci-btn:enabled:hover { 
        background: #065f46; 
        color:#fff; 
        border:1px solid #22d3ee;
    }
    
    .puri-laci-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .puri-remove-item { 
        color:#e11d48; 
        background:none; 
        border:none; 
        font-size:18px; 
        padding:0 8px 0 0; 
        cursor:pointer;
    }
    
    /* Highlight animation */
    .puri-transfer-list li.just-added {
      background-color: rgba(199, 182, 61, 0.14);
      transition: background-color 0.85s ease;
    }
    
    .puri-transfer-list li.fading {
      background-color: transparent;
      opacity: 0.65;
      transition: opacity 2.1s ease-out;
    }

    /* Summary Panel Container */
    .puri-summary-panel { 
        border:1.5px solid #bbb; 
        border-radius:8px; 
        background:#fff; 
        padding:0px; 
        padding-bottom: 10px; 
        display:flex; 
        flex-direction:column; 
        justify-content:center; 
        overflow: hidden;
    }
    
    .puri-summary-panel .header {
        height: 45px; 
        background-color:#ccc;  
        border-radius:0; 
        padding-top: 18px;
    }
    
    .puri-summary-panel .title { 
        font-weight:bold; 
        font-size:30px; 
        text-align:center; 
        color:#333; 
    }
    
    .puri-summary-panel .subtitle {
        margin-top:8px; 
        margin-bottom:8px; 
        padding-left:0px; 
        font-weight:600; 
        font-size:13px; 
        display:flex; 
        justify-content:flex-start;
    }
    
    .puri-summary-panel .subtitle .value {
        margin-left:auto; 
        font-size:larger;
    }
    
    .puri-summary-panel.body {
        padding: 5px 20px; 
        display:flex; 
        flex-direction:column; 
        border:0px; 
    }
    
    .puri-summary-panel table { 
        padding-left: 20px;
        padding-right: 10%; 
        margin-bottom:5px;
    }
    
    .puri-summary-total { 
        font-weight:bold; 
        font-size: 21px; 
        color:#111;
    }
    
    .puri-summary-asset { 
        font-weight:700; 
        font-size:1.5em; 
        color:#129e4d; 
        padding:25px 11px;
        border-top:2px solid #999; 
        margin-top:15px; 
        display:flex;
    }
    
    .puri-summary-asset .value { 
        text-align:right; 
        font-weight:300; 
        margin-left:auto;
    }
    
    .puri-summary-asset .currency {  
        margin-left:4px; 
        font-weight:500; 
        font-size: small; 
    }
    
    .puri-check { margin-right:4px;}
    
    /* Procurement Panel Container */
    .puri-container-resv { 
        border:1px dashed #bbb;
        font-style:italic; 
        padding:36px 0; 
        color:#aaa;
        text-align:center;
        margin-top:18px;
    }
    
    /* Responsive tweak */
    @media (max-width:1000px){
        .puri-transfer-main { display:block; }
        .puri-transfer-right { margin-top:20px;}
    }
    </style>

    <div class="wrap">
        <h1>🔄 Stock Transfer Hub v7.3.15</h1>
        
        <div class="puri-transfer-wrap">
            <div style="display:flex; flex-direction:column; width:100%;">
                <div class="puri-transfer-main" style="display: flex; gap: 15px;">
                    <!-- GRID STOCK GUDANG -->
                    <div style="flex:2;min-width:320px;">
                        <div style="display:flex;align-items:center;margin-bottom:8px">
                            <span style="font-size:1.5em;margin-right:7px">📦</span>
                            <span style="font-weight:900;font-size:22px;color:#333">ADMINISTRASI GUDANG</span>
                        </div>
                        
                        <div class="puri-stock-grid" id="stockGrid">
                            <?php if (empty($items)): ?>
                                <div style="padding:40px;text-align:center;color:#999;width:100%;">
                                    <p>⚠️ Tidak ada data stock. Silakan tambahkan item terlebih dahulu.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($items as $it):
                                    $bendels = floor(floatval($it->stock_gudang) / 100);
                                    
                                    // Coloring logic
                                    if ($bendels >= 3)          $badge = 'puri-badge-blue';
                                    else if ($bendels >= 1)     $badge = 'puri-badge-red';
                                    else                        $badge = 'puri-badge-grey';
                                ?>
                                  <div class="puri-stock-card" 
                                       data-id="<?php echo intval($it->id); ?>" 
                                       data-denom="<?php echo intval($it->denom_value); ?>"
                                       tabindex="0">
                                    <div style="display:grid; grid-template-columns: 1fr 80px;">
                                        <div style="font-weight:700;font-size:1.8em;margin-bottom:5px">
                                            <?php echo esc_html($it->sku); ?>
                                        </div>
                                        <div style="font-size:10px;color:#888;text-align:right;">
                                            Pecahan <?php echo intval($it->denom_value); ?> riyal
                                        </div>						
                                    </div>
                                    
                                    <div style="display:grid; grid-template-columns: 90px 1fr;">
                                        <span class="puri-stock-badge <?php echo $badge; ?>">
                                            Bendel: <b><?php echo $bendels; ?></b>
                                        </span>
                                        <div style="font-size:1.1rem; color: #3c6; text-align:right;">
                                            <b><?php echo number_format($bendels * intval($it->denom_value) * 100); ?></b> riyal
                                        </div>
                                    </div>
                                    
                                    <!-- Input dan tombol transfer -->
                                    <div class="puri-card-submitter" style="display:flex;align-items:center;margin-top:6px;">
                                        <input type="number" 
                                               min="1" 
                                               max="<?php echo $bendels; ?>" 
                                               style="width:60px; border-radius: 4px 0px 0px 4px; min-height:30px; text-align:center;" 
                                               placeholder="qty" 
                                               class="bendel-qty-input"
                                               <?php echo $bendels <= 0 ? 'disabled' : ''; ?>
                                        />
                                        <button class="puri-laci-btn" 
                                                type="button" 
                                                data-id="<?php echo intval($it->id); ?>" 
                                                data-sku="<?php echo esc_attr($it->sku); ?>" 
                                                data-denom="<?php echo intval($it->denom_value); ?>"
                                                <?php echo $bendels <= 0 ? 'disabled' : ''; ?>>
                                            keranjang &raquo;
                                        </button>
                                    </div>
                                  </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- PANEL KANAN: TRANSFER + SUMMARY -->
                    <div class="puri-transfer-right" style="flex:1;min-width:310px; max-width:340px;">
                        <!-- TRANSFER STOCK -->
                        <form class="puri-transfer-panel" id="transferForm" onsubmit="return false;">
                            <strong style="font-size:18px;letter-spacing:1px;">TRANSFER STOCK</strong>
                            <ul id="transferList" class="puri-transfer-list"></ul>
                            <div style="margin:10px 0;">
                                <label>
                                    <input type="checkbox" id="confirmTransfer" class="puri-check">
                                    Data sudah benar
                                </label>
                            </div>
                            <button id="btnTransfer" 
                                    type="button" 
                                    class="button" 
                                    style="width:100%;background:#68d96f;color:#111;font-weight:900;border:1.5px solid #41a152;font-size:15px" 
                                    disabled>
                                Pindahkan ke Laci
                            </button>
                        </form>
                        
                        <!-- SUMMARY STOCK -->
                        <div class="puri-summary-panel">
                          <div class="header">
                            <div class="title">SUMMARY STOCK</div>
                          </div>
                          <div class="puri-summary-panel body">
                            <div class="subtitle">
                                Total stok Riyal di gudang:
                                <span class="value"><?php echo number_format($total_riyal); ?> riyal</span>
                            </div>
                            <div style="margin:6px 0 4px 0;font-size:12px;font-weight:600;">Terdiri dari:</div>
                            <table style="width:100%;font-size:12.5px;">
                            <?php foreach($summary as $row):
                                echo '<tr>
                                    <td style="width:90px;">' . esc_html($row['sku']) . '</td>
                                    <td style="text-align:right;">' . 
                                    ($row['qty'] > 0 ? number_format($row['nilai']) : '<span style="color:#e11d48">0</span>'). 
                                    '</td>
                                    </tr>';
                            endforeach; ?>
                            </table>

                            <div class="subtitle">
                                Stok dalam perjalanan: 
                                <span class="value"><?php echo number_format($stok_perjalanan); ?> riyal</span>
                            </div>
                          </div>  	
                          <div class="puri-summary-asset">
                            Total Asset: 
                            <span class="value"> 
                                <b><?php echo number_format($total_asset_idr); ?></b>
                            </span> 
                            <span class="currency">IDR</span>
                          </div>
                        </div>
                    </div>
                </div>
                
                <div class="puri-container-resv">
                    <span style="font-size:1.35em;opacity:.39">CONTAINER RESERVED FOR PROCUREMENT</span>
                </div>
            </div>  
        </div>
    </div>

    <script>
    (() => {
        // == DOM REFS ==
        const grid = document.getElementById('stockGrid');
        const transferList = document.getElementById('transferList');
        const btnTransfer = document.getElementById('btnTransfer');
        const confirmChk = document.getElementById('confirmTransfer');

        // State
        let transfers = []; // [{id, sku, denom, qty}]
        let lastAdded = null; // { id, timestamp } untuk highlight

        // Helpers
        function findIdx(id) {
            return transfers.findIndex(x => x.id == id);
        }

        function addToTransfers(id, sku, denom, qtyToAdd, max) {
            const idx = findIdx(id);
            if (idx >= 0) {
                const newQty = transfers[idx].qty + qtyToAdd;
                if (newQty > max) {
                    alert(`Maksimal stok: ${max} bendel`);
                    return false;
                }
                transfers[idx].qty = newQty;
            } else {
                if (qtyToAdd > max) {
                    alert(`Maksimal stok: ${max} bendel`);
                    return false;
                }
                transfers.push({ id: id, sku: sku, denom: denom, qty: qtyToAdd });
            }
            
            lastAdded = { id: String(id), ts: Date.now() };
            renderTransferList();
            return true;
        }

        // Render transfer list
        function renderTransferList() {
            transferList.innerHTML = '';
            
            if (transfers.length === 0) {
                const li = document.createElement('li');
                li.innerHTML = '<span style="color:#bbb">- belum ada item transfer -</span>';
                transferList.appendChild(li);
            } else {
                transfers.forEach(function(it){
                    const li = document.createElement('li');
                    li.setAttribute('data-id', String(it.id));
                    const label = it.sku.toUpperCase().startsWith('COIN') ? 'pack' : 'bendel';
                    li.innerHTML = `${it.qty} ${label} × <b>${it.sku}</b> 
                        <button class="puri-remove-item" data-id="${it.id}" title="hapus">&#10005;</button>`;
                    transferList.appendChild(li);
                });
            }

            // Delete handler
            transferList.querySelectorAll('.puri-remove-item').forEach(function(delBtn){
                delBtn.onclick = function(ev) {
                    ev.stopPropagation();
                    const id = delBtn.getAttribute('data-id');
                    transfers = transfers.filter(x => x.id != id);
                    renderTransferList();

                    // Play remove sound
                    if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
                        window.PURI.playSoundFx(window.PURI.SFX.fx_remove_from_row, { allowOverlap: true });
                    }
                };
            });
            
            // Highlight animation untuk item terakhir
            if (lastAdded && lastAdded.id) {
                const targetLi = transferList.querySelector(`li[data-id="${lastAdded.id}"]`);
                if (targetLi) {
                    targetLi.classList.remove('fading');
                    targetLi.classList.add('just-added');

                    setTimeout(() => {
                        targetLi.classList.add('fading');
                        targetLi.classList.remove('just-added');
                        setTimeout(() => { lastAdded = null; }, 700);
                    }, 600);
                } else {
                    lastAdded = null;
                }
            }

            // Update button state
            btnTransfer.disabled = !(transfers.length > 0 && confirmChk.checked);
        }

        // Attach handlers to cards
        if (grid) {
            grid.querySelectorAll('.puri-stock-card').forEach(function(card){
                const btn = card.querySelector('.puri-laci-btn');
                const input = card.querySelector('.bendel-qty-input');
                if (!btn || !input) return;

                const id = btn.dataset.id;
                const sku = btn.dataset.sku;
                const denom = btn.dataset.denom;
                const max = parseInt(input.getAttribute('max') || '0', 10) || 0;

                // Button click handler
                btn.addEventListener('click', function(ev){
                    ev.stopPropagation();
                    const qty = parseInt(input.value || 0, 10);
                    if (!qty || qty < 1 || qty > max) { 
                        input.focus(); 
                        input.select(); 
                        return; 
                    }
                    
                    if (addToTransfers(id, sku, denom, qty, max)) {
                        if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
                            window.PURI.playSoundFx(window.PURI.SFX.fx_card_clicked, { allowOverlap: true });
                        }
                        input.value = '';
                    }
                });

                // Card click handler (outside submitter area)
                card.addEventListener('click', function(ev){
                    if (ev.target.closest('.puri-card-submitter')) return;
                    if (max <= 0) return; // No stock
                    
                    if (addToTransfers(id, sku, denom, 1, max)) {
                        if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
                            window.PURI.playSoundFx(window.PURI.SFX.fx_card_clicked, { allowOverlap: true });
                        }
                    }
                });

                // Keyboard support
                card.addEventListener('keypress', function(ev){
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        card.click();
                    }
                });
            });
        }

        // Checkbox handler
        if (confirmChk) {
            confirmChk.onchange = renderTransferList;
        }

        // Submit handler
        if (btnTransfer) {
            btnTransfer.onclick = function() {
                if (btnTransfer.disabled) return;
                
                if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
                    window.PURI.playSoundFx(window.PURI.SFX.fx_need_attention, { allowOverlap: true });
                }
                            
                btnTransfer.disabled = true;
                const originalText = btnTransfer.textContent;
                btnTransfer.textContent = "Memproses...";
                
                fetch(ajaxurl, {
                    method: "POST",
                    body: new URLSearchParams({
                        action: "puri_execute_transfer_ajax",
                        puri_admin_nonce: "<?php echo esc_js(wp_create_nonce('puri_admin_action')); ?>",
                        items: JSON.stringify(transfers)
                    })
                })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        alert(res.data.message || "Sukses pindah stok!");
                        location.reload();
                    } else {
                        alert("Gagal: " + (res?.data?.message || 'Unknown error'));
                        btnTransfer.disabled = false;
                        btnTransfer.textContent = originalText;
                    }
                })
                .catch(err => {
                    console.error('AJAX Error:', err);
                    alert("ERROR AJAX: " + err.message);
                    btnTransfer.disabled = false;
                    btnTransfer.textContent = originalText;
                });
            };
        }

        // Initial render
        renderTransferList();
    })();
    </script>
    <?php
}

/**
 * AJAX Handler untuk Transfer Stock
 * Compatible dengan Mozart Engine
 */
function puri_execute_transfer_ajax_handler() {
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message'=>'Unauthorized']);
    }

    global $wpdb;
    
    // ✅ Cek Mozart Engine
    if (!function_exists('puri_mozart')) {
        wp_send_json_error(['message'=>'Mozart Engine not available']);
    }

    $items_raw = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : null;
    if (!is_array($items_raw) || empty($items_raw)) {
        wp_send_json_error(['message'=>'Invalid payload']);
    }

    $wpdb->query('START TRANSACTION');
    try {
        $ref_id = 'TFR-' . current_time('YmdHis') . '-' . strtoupper(wp_generate_password(3, false));
        
        foreach ($items_raw as $it) {
            $item_id = intval($it['id']);
            $bendel = intval($it['qty']);
            
            if ($item_id <= 0 || $bendel <= 0) {
                throw new Exception('Invalid item data');
            }
            
            $pcs = $bendel * 100;
            
            // ✅ Update stock balance (gunakan kolom 'balance')
            // Kurangi dari lokasi asal
            $wpdb->query($wpdb->prepare(
                "UPDATE " . puri_table_name('T_STOCK') . " 
                 SET balance = balance - %d,
                     updated_at = %s,
                     last_ref = %s
                 WHERE item_id = %d 
                 AND location_id = %s",
                $pcs,
                current_time('mysql'),
                $ref_id,
                $item_id,
				puri_get_default_location()

            ));
            
            if ($wpdb->rows_affected === 0) {
                throw new Exception("Gagal mengurangi stok item ID: {$item_id}");
            }
            
            // ✅ Tambah ke lokasi tujuan (LACI_KASIR)
            // Gunakan UPSERT logic
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . puri_table_name('T_STOCK') . " 
                 WHERE item_id = %d AND location_id = 'LACI_KASIR'",
                $item_id
            ));
            
            if ($exists) {
                // Update existing
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . puri_table_name('T_STOCK') . " 
                     SET balance = balance + %d,
                         updated_at = %s,
                         last_ref = %s
                     WHERE item_id = %d 
                     AND location_id = 'LACI_KASIR'",
                    $pcs,
                    current_time('mysql'),
                    $ref_id,
                    $item_id
                ));
            } else {
                // Insert new
                $wpdb->insert(puri_table_name('T_STOCK'), [
                    'item_id'    => $item_id,
                    'location_id' => 'LACI_KASIR',
                    'balance'     => $pcs,
                    'updated_at'  => current_time('mysql'),
                    'last_ref'    => $ref_id
                ]);
            }
            
            // ✅ Record di Ledger (2 baris: keluar & masuk)
            // Keluar dari MAIN
            $wpdb->insert(puri_table_name('T_LEDGER'), [
                'location_id' => puri_get_default_location(),
                'item_id'     => $item_id,
                'qty_change'  => -$pcs,
                'trx_type'    => 'transfer_out',
                'ref_id'      => $ref_id,
                'description' => "Transfer ke Laci Kasir ({$bendel} bendel)",
                'trx_date'    => current_time('mysql')
            ]);
            
            if ($wpdb->insert_id === 0) {
                throw new Exception("Gagal mencatat ledger keluar untuk item ID: {$item_id}");
            }
            
            // Masuk ke LACI_KASIR
            $wpdb->insert(puri_table_name('T_LEDGER'), [
                'location_id' => 'LACI_KASIR',
                'item_id'     => $item_id,
                'qty_change'  => $pcs,
                'trx_type'    => 'transfer_in',
                'ref_id'      => $ref_id,
                'description' => "Transfer dari Gudang Utama ({$bendel} bendel)",
                'trx_date'    => current_time('mysql')
            ]);
            
            if ($wpdb->insert_id === 0) {
                throw new Exception("Gagal mencatat ledger masuk untuk item ID: {$item_id}");
            }
        }
        
        $wpdb->query('COMMIT');
        wp_send_json_success([
            'message' => "✅ Transfer berhasil! Ref: {$ref_id}",
            'ref_id'  => $ref_id
        ]);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('MC-08 Transfer Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => 'Transfer gagal: ' . $e->getMessage()
        ]);
    }
}