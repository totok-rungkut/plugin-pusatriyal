<?php
/**
 * MC 08 - Stock Transfer & Laci Controller (Refactor UX to Modern Grid)
 * Version: 6.0.2
 * Author: Denmas Totok / Copilot / UX Re-layout by Copilot (image1)
 *
 * - Layout dan konstanta tetap sinkron dengan core plugin (puri_table_name, dll)
 * - UX dirancang mengikuti skema layout pada image1:
 *      Panel grid stok → Form transfer → Summary stock
 */

defined('ABSPATH') || exit;

add_action('wp_ajax_puri_execute_transfer_ajax', 'puri_execute_transfer_ajax_handler');

function puri_render_transfer_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    // Ambil data inventory dari Gudang Utama, pecahan per denom, urut ASC
    $items = $wpdb->get_results("
        SELECT i.id, i.sku, i.name, i.denom_value,
               COALESCE(s.qty, 0) as stock_gudang
        FROM " . puri_table_name('T_ITEMS') . " i
        LEFT JOIN " . puri_table_name('T_STOCK') . " s
          ON i.id = s.item_id AND s.location_id = 'gudang_utama'
        WHERE i.type = 'currency'
        ORDER BY i.denom_value ASC, i.sku ASC
    ");

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

    // "Stok dalam perjalanan" dummy / bisa digantikan query real jika ada
    $stok_perjalanan = 6500;

    // "Total Asset" dummy atau ganti query jika punya summary IDR real
    $total_asset_idr = 1428637500;
    ?>
    <style>
	
	/* Chrome, Safari, Edge */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
    .puri-transfer-wrap { display:flex; width:98%; flex:wrap; background:#fdfdfd; border:1px solid #bbb; border-radius:6px; padding:10px; margin-bottom:16px; }
	
    /* Stock Panel Container */
	//.puri-stock-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; }
	.puri-stock-grid { display: flex; gap: 20px 25px; flex-wrap: wrap; justify-content: flex-start; padding:0 10px;}

    /* Stock Card Wrapper */
    .puri-stock-card { border: 1.5px solid #bbb; border-radius:4px; padding:14px 12px 13px; box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.08); background: #fafbfc; display: flex; flex-direction: column; max-width: 250px; min-width: 230px; box-sizing:border-box;}
    .puri-stock-badge { display:inline-block; font-weight:bold; font-size:13px; padding:1px 4px 0; border-radius:4px; margin-bottom:2px;}
	.puri-stock-card { cursor: pointer; }
	.puri-stock-card:hover { box-shadow: 4px 4px 2px rgba(0, 0, 0, 0.6); transform: translateY(-2px); }
	.puri-stock-card:active { box-shadow: none; transform: translateY(0) }
	.puri-stock-card:focus-visible { outline: 3px solid rgba(119, 51, 5, 0.25); outline-offset: 2px;}
	.puri-stock-card .puri-laci-btn { cursor: pointer; }
    .puri-badge-grey { background:#eee; color:#ccc; border:1px solid #ddd;}
    .puri-badge-blue { background:#eff6ff; color:#2461cb; border:1px solid #60a5fa;}
    .puri-badge-red { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5;}
    		
	/* Transfer Panel Container */
    .puri-transfer-panel { display:flex; flex-direction:column; border:1.5px solid #bbb; border-radius:7px; background:#fff; padding:13px 13px 8px 18px; margin-bottom:13px; }
    .puri-transfer-list li { margin-bottom:4px; display:flex; align-items:center; justify-content:space-between; border-bottom: 1px dotted #999;}
    .puri-laci-btn, .puri-laci-btn[disabled] { background:#fff; color:#333; border:1px solid #bbb; border-radius:0px 25px 25px 0px; padding:3.5px 16px; cursor:pointer; margin-left:-1px; min-height:30px; width:100%;}
    .puri-laci-btn:enabled:hover { background: #065f46; color:#fff; border:1px solid #22d3ee;}
    .puri-remove-item { color:#e11d48; background:none; border:none; font-size:18px; padding:0 8px 0 0; cursor:pointer;}
	/* highlight saat item baru ditambahkan, lalu fade-out */
	.puri-transfer-list li.just-added {
	  background-color: rgba(199, 182, 61, 0.14);
	  transition: background-color 0.85s ease, opacity 0.6s ease;
	}
	.puri-transfer-list li.fading {
	  background-color: transparent;
	  opacity: 0.65;
	  transition: opacity 2.1s ease-out;
	}

	/* Summary Panel Container */
    .puri-summary-panel { border:1.5px solid #bbb; border-radius:8px; background:#fff; padding:0px; padding-bottom: 10px; display:flex; flex-direction:column; justify-content:center; overflow: hidden;}
	.puri-summary-panel .header {height: 45px; background-color:#ccc;  border-radius:0; padding-top: 18px;}
	.puri-summary-panel .title { font-weight:bold; font-size:30px; text-align:center; color:#333; }
	.puri-summary-panel .subtitle {margin-top:8px; margin-bottom:8px; padding-left:0px; font-weight:600; font-size:13px; display:flex; justify-content:flex-start;}
	.puri-summary-panel .subtitle .value {margin-left:auto; font-size:larger;}
	.puri-summary-panel.body {padding: 5px 20px; display :flex; flex-direction:column; border:0px; }
    .puri-summary-panel table { padding-left: 20px;padding-right: 10%; margin-bottom:5px;}
    .puri-summary-total { font-weight:bold; font-size: 21px; color:#111;}
    .puri-summary-asset { font-weight:700; font-size:1.5em; color:#129e4d; padding:25px 11px;border-top:2px solid #999; margin-top:15px; display:flex;}
    .puri-summary-asset .value { text-align:right; font-weight:300; margin-left:auto;}
    .puri-summary-asset .currency {  margin-left:4px; font-weight:500; font-size: small; }
    .puri-check { margin-right:4px;}
    
	/* Procurement Panel Container */
	.puri-container-resv { border:1px dashed #bbb;font-style:italic; padding:36px 0; color:#aaa;text-align:center;margin-top:18px;}
	
	
    /* Responsive tweak */
    @media (max-width:1000px){
        .puri-transfer-main { display:block; }
        .puri-transfer-right { margin-top:20px;}
    }
    </style>
    <div class="puri-transfer-wrap">
	<div style="display:flex; flex-direction:column;">
      <div class="puri-transfer-main" style="display: flex; gap: 15px;">
        <!-- GRID STOCK GUDANG -->
        <div style="flex:2;min-width:320px;">
            <div style="display:flex;align-items:center;margin-bottom:8px">
                <span style="font-size:1.5em;margin-right:7px">📦</span>
                <span style="font-weight:900;font-size:22px;color:#333">ADMINISTRASI GUDANG</span>
            </div>
            <div class="puri-stock-grid" id="stockGrid">
                <?php foreach($items as $it):
                    $bendels = floor(floatval($it->stock_gudang) / 100);
                    // coloring: green (≥15), blue (5-14), red (1-4)
                    if ($bendels >= 3)          $badge = 'puri-badge-blue';
                     else if ($bendels >= 1)     $badge = 'puri-badge-red';
                    else                        $badge = 'puri-badge-grey';
                ?>
                  <div class="puri-stock-card" data-id="<?php echo intval($it->id); ?>" data-denom="<?php echo intval($it->denom_value); ?>">
					<div style="display:grid; grid-template-columns: 1fr 80px;">
						<div style="font-weight:700;font-size:1.8em;margin-bottom:5px"><?php echo esc_html($it->sku); ?></div>
						<div style="font-size:10px;color:#888;text-align:right;">Pecahan <?php echo intval($it->denom_value); ?> riyal</div>						
					</div>
					<div style="display:grid; grid-template-columns: 90px 1fr;">
						<span class="puri-stock-badge <?php echo $badge; ?>"> Bendel: <b><?php echo $bendels; ?></b> </span>
						<div style="font-size:1.1rem; color: #3c6; text-align:right;"><b><?php echo number_format($bendels * intval($it->denom_value) * 100); ?></b> riyal</div>
					</div>
                    <!-- input amount dan tombol pindah ke transfer -->
                    <div class= "puri-card-submitter" style="display:flex;align-items:center;margin-top:6px;">
                        <input type="number" min="1" max="<?php echo $bendels; ?>" style="width:60px; border-radius: 4px 0px 0px 4px;  min-height15px !important; height:15px !important; line-height:15px !important; text-align:center;" placeholder="qty" class="bendel-qty-input"/>
                        <button class="puri-laci-btn" type="button" data-id="<?php echo intval($it->id); ?>" data-sku="<?php echo esc_attr($it->sku); ?>" data-denom="<?php echo intval($it->denom_value); ?>">keranjang >></button>
                    </div>
					
                  </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- PANEL KANAN: TRANSFER + SUMMARY -->
        <div class="puri-transfer-right" style="flex:1;min-width:310px; max-width:340px;">
            <!-- TRANSFER STOCK -->
            <form class="puri-transfer-panel" id="transferForm" onsubmit="return false;">
                <strong style="font-size:18px;letter-spacing:1px;">TRANSFER STOCK</strong>
                <ul id="transferList" class="puri-transfer-list" style="margin:14px 0 8px 0;padding:0;min-height:80px;list-style:none;font-size:15px"></ul>
                <div style="margin:10px 0;">
                    <label><input type="checkbox" id="confirmTransfer" class="puri-check">Data sudah benar</label>
                </div>
                <button id="btnTransfer" type="button" class="button" style="width:100%;background:#68d96f;color:#111;font-weight:900;border:1.5px solid #41a152;font-size:15px" disabled>Pindahkan ke Laci</button>
            </form>
            <!-- SUMMARY STOCK -->
            <div class="puri-summary-panel">
			  <div class="header">
                <div class="title">SUMMARY STOCK</div>
			  </div>
			  <div class="puri-summary-panel body">
                <div class="subtitle">Total stok Riyal di gudang:<span class="value"><?php echo number_format($total_riyal); ?> riyal</span></div>
                <div style="margin:6px 0 4px 0;font-size:12px;font-weight:600;">Terdiri dari:</div>
                <table style="width:100%;font-size:12.5px;">
                <?php foreach($summary as $row):
                    echo '<tr>
                        <td style="width:90px;">' . esc_html($row['sku']) . '</td>
                        <td style="text-align:right;">' . ($row['qty'] > 0 ? number_format($row['nilai']) : '<span style="color:#e11d48">0</span>'). '</td>
<!--                        <td style="text-align:right;padding-left:7px;">'.($row['qty'] > 0 ? '' : '<span style="color:#e11d48">0</span>').'</td>  -->
                        </tr>';
                endforeach; ?>
                </table>

                <div class="subtitle">Stok dalam perjalanan: <span class="value"><?php echo number_format($stok_perjalanan); ?> riyal</span></div>
			  </div>  	
			  <div class="puri-summary-asset">Total Asset: <span class="value"> 
				 <b><?php echo number_format($total_asset_idr); ?></b></span> <span class="currency">IDR</span>
			  </div>
            </div>
        </div>
      </div>
	<div class="puri-container-resv">
		<span style="font-size:1.35em;opacity:.39">CONTAINER RESERVED FOR PROCUREMENT</span>
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

    // state
    let transfers = []; // [{id, sku, denom, qty}]
    let lastAdded = null; // { id, timestamp } untuk highlight

    // helpers
    function findIdx(id) {
        return transfers.findIndex(x => x.id == id);
    }

    function addToTransfers(id, sku, denom, qtyToAdd, max) {
        const idx = findIdx(id);
        if (idx >= 0) {
            const newQty = transfers[idx].qty + qtyToAdd;
            if (newQty > max) return false; // exceed stok
            transfers[idx].qty = newQty;
        } else {
            if (qtyToAdd > max) return false;
            transfers.push({ id: id, sku: sku, denom: denom, qty: qtyToAdd });
        }
        // tandai item terakhir yang ditambahkan untuk highlight
        lastAdded = { id: String(id), ts: Date.now() };
        renderTransferList();
        return true;
    }

    // render transfer list
    function renderTransferList() {
        transferList.innerHTML = '';
        (transfers.length ? transfers : [{ empty: true }]).forEach(function(it){
            if (it.empty) {
                const li = document.createElement('li');
                li.innerHTML = '<span style="color:#bbb">- belum ada item transfer -</span>';
                transferList.appendChild(li);
            } else {
                const li = document.createElement('li');
                li.setAttribute('data-id', String(it.id));
                const label = it.sku.toUpperCase().startsWith('COIN') ? 'pack' : 'bendel';
                li.innerHTML = it.qty + ' ' + label + ' &times; <b>' + it.sku + '</b> ' +
                    `<button class="puri-remove-item" data-id="${it.id}" title="hapus">&#10005;</button>`;
                transferList.appendChild(li);
            }
        });

        // delete handler
		transferList.querySelectorAll('.puri-remove-item').forEach(function(delBtn){
			delBtn.onclick = function(ev) {
				ev.stopPropagation();
				const id = delBtn.getAttribute('data-id');
				transfers = transfers.filter(x => x.id != id);
				renderTransferList();

				// play remove sound (gunakan PURI jika tersedia, fallback ke URL)
				if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
					window.PURI.playSoundFx(window.PURI.SFX.fx_remove_from_row, { allowOverlap: true });
				} else {
					new Audio('https://assets.mixkit.co/active_storage/sfx/2569/2569-preview.mp3').play().catch(()=>{});
				}
			};
		});
		
        // jika ada item terakhir yang ditambahkan, highlight barisnya sebentar
        if (lastAdded && lastAdded.id) {
            const targetLi = transferList.querySelector(`li[data-id="${lastAdded.id}"]`);
            if (targetLi) {
                // reset kelas bila ada
                targetLi.classList.remove('fading');
                targetLi.classList.add('just-added');

                // setelah 1 detik, mulai fade lalu hapus kelas
                setTimeout(() => {
                    targetLi.classList.add('fading');
                    targetLi.classList.remove('just-added');
                    // bersihkan lastAdded setelah animasi selesai (safety)
                    setTimeout(() => { lastAdded = null; }, 700);
                }, 600);
            } else {
                // jika tidak ditemukan (mis. item dihapus cepat), clear marker
                lastAdded = null;
            }
        }

        btnTransfer.disabled = !(transfers.length > 0 && confirmChk.checked);
    }

    // attach handlers to cards (safe guard if grid missing)
    if (grid) {
        grid.querySelectorAll('.puri-stock-card').forEach(function(card){
            const btn = card.querySelector('.puri-laci-btn');
            const input = card.querySelector('.bendel-qty-input');
            if (!btn || !input) return; // safety

            const id = btn.dataset.id;
            const sku = btn.dataset.sku;
            const denom = btn.dataset.denom;
            const max = parseInt(input.getAttribute('max') || '0', 10) || 0;

            // button: read input and add that qty
            btn.addEventListener('click', function(ev){
                ev.stopPropagation(); // prevent card click
                const qty = parseInt(input.value || 0, 10);
                if (!qty || qty < 1 || qty > max) { input.focus(); input.select(); return; }
                addToTransfers(id, sku, denom, qty, max);
				// play sound feedback
				if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
				  window.PURI.playSoundFx(window.PURI.SFX.fx_card_clicked, { allowOverlap: true });
				} else {
				  new Audio('https://assets.mixkit.co/active_storage/sfx/1117/1117-preview.mp3').play().catch(()=>{});
				}
                input.value = '';
            });

            // card click: only trigger when click is outside the control container
            card.addEventListener('click', function(ev){
                // ignore clicks inside the submitter container (markup uses puri-card-submitter)
                if (ev.target.closest('.puri-card-submitter')) return;
                addToTransfers(id, sku, denom, 1, max);
				// play sound feedback
				if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
				  window.PURI.playSoundFx(window.PURI.SFX.fx_card_clicked, { allowOverlap: true });
				} else {
				  new Audio('https://assets.mixkit.co/active_storage/sfx/1117/1117-preview.mp3').play().catch(()=>{});
				}

            });

            // allow normal input behavior (no stopPropagation needed)
        });
    }

    // checkbox sync
    if (confirmChk) {
        confirmChk.onchange = function() {
            renderTransferList();
        };
    }

    // submit handler
    if (btnTransfer) {
        btnTransfer.onclick = function() {
            if (btnTransfer.disabled) return;
			
			// play "need attention" sound when user clicks the transfer button
			if (window.PURI && typeof window.PURI.playSoundFx === 'function') {
			  window.PURI.playSoundFx(window.PURI.SFX.fx_need_attention, { allowOverlap: true });
			} else {
			  new Audio('https://assets.mixkit.co/active_storage/sfx/2575/2575-preview.mp3').play().catch(()=>{});
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
            }).then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        alert(res.data.message || "Sukses pindah stok!");
                        location.reload();
                    } else {
                        alert("Gagal: " + (res && res.data && res.data.message ? res.data.message : (res && res.data ? res.data : 'Unknown error')));
                        btnTransfer.disabled = false;
                        btnTransfer.textContent = originalText;
                    }
                })
                .catch(_ => {
                    alert("ERROR AJAX");
                    btnTransfer.disabled = false;
                    btnTransfer.textContent = originalText;
                });
        };
    }

    // initial render
    renderTransferList();
})();
</script>


    <?php
}

function puri_execute_transfer_ajax_handler() {
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized']);

    global $wpdb, $puri_engine;
	if (!isset($puri_engine) && function_exists('puri_engine')) $puri_engine = puri_engine();
    if (!isset($puri_engine)) wp_send_json_error(['message'=>'Engine not available']);

    $items_raw = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : null;
    if (!is_array($items_raw) || empty($items_raw)) wp_send_json_error(['message'=>'Invalid payload']);

    $wpdb->query('START TRANSACTION');
    try {
        foreach ($items_raw as $it) {
            $id = intval($it['id']);
            $bendel = intval($it['qty']);
            if ($id<=0 || $bendel<=0) throw new Exception('Invalid item');
            $pcs = $bendel * 100;
            // Subtract from gudang, add to laci
            $res1 = $puri_engine->update_stock_atomic('gudang_utama', $id, -$pcs);
            if (is_wp_error($res1)) throw new Exception($res1->get_error_message());
            $res2 = $puri_engine->update_stock_atomic('laci_kasir', $id, $pcs);
            if (is_wp_error($res2)) throw new Exception($res2->get_error_message());
            // ledger entries
            $ins1 = $wpdb->insert(puri_table_name('T_LEDGER'), ['location_id'=>'gudang_utama','item_id'=>$id,'qty_change'=>-$pcs,'ref_id'=>'TFR-'.date('YmdHis'),'description'=>'Transfer ke Laci','trx_date'=>current_time('mysql')]);
            $ins2 = $wpdb->insert(puri_table_name('T_LEDGER'), ['location_id'=>'laci_kasir','item_id'=>$id,'qty_change'=>$pcs,'ref_id'=>'TFR-'.date('YmdHis'),'description'=>'Transfer dari Gudang','trx_date'=>current_time('mysql')]);
            if ($ins1 === false || $ins2 === false) throw new Exception($wpdb->last_error);
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['message'=>'Stok berhasil dipindah ke laci!']);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message'=>'Transfer failed: '.$e->getMessage()]);
    }
}