

<?php
/* *******
MC 08 DARURAT 
****/


defined('ABSPATH') || exit;

// Register admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Transfer via Mozart',
        'Transfer Mozart',
        'manage_options',
        'puri-transfer-mozart',
        'puri_render_transfer_mozart_page',
        'dashicons-randomize',
        56
    );
});

// Helper: get locations from ACF option
function puri_get_inventory_locations() {
    $locations = get_option('puri_inv_locations', []);
    if (empty($locations)) {
        return [
            ['id' => puri_get_default_location(), 'name' => 'Gudang Utama'],
            ['id' => 'laci-kasir', 'name' => 'Laci Kasir']
        ];
    }
    return $locations;
}

// Helper: validate location id
function puri_validate_location($location_id) {
    foreach (puri_get_inventory_locations() as $loc) {
        if (!empty($loc['id']) && $loc['id'] === $location_id) return true;
    }
    return false;
}

// Helper: location name
function puri_get_location_name($location_id) {
    foreach (puri_get_inventory_locations() as $loc) {
        if (!empty($loc['id']) && $loc['id'] === $location_id) return $loc['name'];
    }
    return $location_id;
}

// Admin page renderer
function puri_render_transfer_mozart_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $locations = puri_get_inventory_locations();
    $from_loc = isset($_GET['from_loc']) ? sanitize_text_field($_GET['from_loc']) : puri_get_default_location();
    $to_loc   = isset($_GET['to_loc'])   ? sanitize_text_field($_GET['to_loc'])   : (isset($locations[1]['id']) ? $locations[1]['id'] : 'laci-kasir');

    if (!puri_validate_location($from_loc)) $from_loc = puri_get_default_location();
    if (!puri_validate_location($to_loc))   $to_loc   = (isset($locations[1]['id']) ? $locations[1]['id'] : 'laci-kasir');

    $tbl_items = puri_table_name('T_ITEMS');
    $tbl_stock = puri_table_name('T_STOCK');

    // SELECT with bridging: show stock at from_loc
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT 
            i.id,
            i.wp_post_id,
            i.sku,
            i.name,
            i.denom_value,
            COALESCE(s.balance, 0) AS stock_balance
        FROM {$tbl_items} i
        LEFT JOIN {$tbl_stock} s
          ON (i.id = s.item_id OR i.wp_post_id = s.item_id)
         AND s.location_id = %s
        WHERE i.type = 'currency'
        ORDER BY i.denom_value ASC, i.sku ASC
    ", $from_loc));

    $nonce = wp_create_nonce('puri_transfer_mozart');
    ?>
    <div class="wrap">
        <h1>Transfer Stok via Mozart</h1>

        <div style="display:flex; gap:16px; margin:16px 0;">
            <div>
                <label><strong>Transfer dari</strong></label>
                <select id="from_loc">
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo esc_attr($loc['id']); ?>" <?php selected($loc['id'], $from_loc); ?>>
                            <?php echo esc_html($loc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><strong>Transfer ke</strong></label>
                <select id="to_loc">
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo esc_attr($loc['id']); ?>" <?php selected($loc['id'], $to_loc); ?>>
                            <?php echo esc_html($loc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button id="btn_change_loc" class="button">Ganti lokasi</button>
        </div>

        <p><em>Klik kartu untuk menambah 1 bendel, atau isi qty lalu klik “Tambah”.</em></p>

        <div id="itemGrid" style="display:flex; flex-wrap:wrap; gap:14px;">
            <?php if (empty($items)): ?>
                <div class="notice notice-warning"><p>Tidak ada stok di lokasi ini.</p></div>
            <?php else: foreach ($items as $it):
                $bendels = floor(floatval($it->stock_balance) / 100);
            ?>
                <div class="card" style="border:1px solid #ccc; padding:10px; width:240px;">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?php echo esc_html($it->sku); ?></strong>
                        <span><?php echo intval($it->denom_value); ?> riyal</span>
                    </div>
                    <div>Stok: <strong><?php echo $bendels; ?></strong> bendel</div>
                    <div style="margin-top:8px; display:flex; gap:8px;">
                        <input type="number" min="1" max="<?php echo $bendels; ?>" value="1" class="qty" style="width:70px;">
                        <button class="button addBtn"
                                data-id="<?php echo intval($it->id); ?>"
                                data-wp="<?php echo intval($it->wp_post_id); ?>"
                                data-sku="<?php echo esc_attr($it->sku); ?>"
                                data-denom="<?php echo intval($it->denom_value); ?>"
                                data-max="<?php echo $bendels; ?>">
                            Tambah
                        </button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <hr>

        <h2>Keranjang Transfer</h2>
        <ul id="transferList" style="list-style:disc; padding-left:20px;"></ul>
        <button id="btnSubmit" class="button button-primary" disabled>Proses via Mozart</button>

        <script>
        (function(){
            const grid = document.getElementById('itemGrid');
            const list = document.getElementById('transferList');
            const btnSubmit = document.getElementById('btnSubmit');
            const btnChangeLoc = document.getElementById('btn_change_loc');
            const fromSel = document.getElementById('from_loc');
            const toSel = document.getElementById('to_loc');

            let cart = [];

            function renderCart(){
                list.innerHTML = '';
                if (cart.length === 0) {
                    btnSubmit.disabled = true;
                    const li = document.createElement('li');
                    li.textContent = 'Belum ada item.';
                    list.appendChild(li);
                    return;
                }
                cart.forEach(it => {
                    const li = document.createElement('li');
                    li.textContent = `${it.qty} bendel × ${it.sku} (denom ${it.denom})`;
                    list.appendChild(li);
                });
                btnSubmit.disabled = false;
            }

            function addItem(data, qty, max){
                qty = parseInt(qty || 0, 10);
                max = parseInt(max || 0, 10);
                if (!qty || qty < 1 || qty > max) return;

                const idx = cart.findIndex(x => x.id == data.id);
                if (idx >= 0) {
                    const newQty = cart[idx].qty + qty;
                    if (newQty > max) return;
                    cart[idx].qty = newQty;
                } else {
                    cart.push({ id: data.id, wp_post_id: data.wp, sku: data.sku, denom: data.denom, qty: qty });
                }
                renderCart();
            }

            if (grid) {
                grid.querySelectorAll('.addBtn').forEach(btn => {
                    btn.addEventListener('click', function(){
                        const card = btn.closest('.card');
                        const qtyInput = card.querySelector('.qty');
                        addItem({
                            id: btn.dataset.id,
                            wp: btn.dataset.wp,
                            sku: btn.dataset.sku,
                            denom: btn.dataset.denom
                        }, qtyInput.value, btn.dataset.max);
                    });
                    // click card adds 1
                    btn.closest('.card').addEventListener('click', function(e){
                        if (e.target.classList.contains('addBtn') || e.target.classList.contains('qty')) return;
                        addItem({
                            id: btn.dataset.id,
                            wp: btn.dataset.wp,
                            sku: btn.dataset.sku,
                            denom: btn.dataset.denom
                        }, 1, btn.dataset.max);
                    });
                });
            }

            if (btnChangeLoc) {
                btnChangeLoc.addEventListener('click', function(){
                    const url = new URL(window.location.href);
                    url.searchParams.set('from_loc', fromSel.value);
                    url.searchParams.set('to_loc', toSel.value);
                    window.location.href = url.toString();
                });
            }

            if (btnSubmit) {
                btnSubmit.addEventListener('click', function(){
                    if (cart.length === 0) return;
                    btnSubmit.disabled = true;
                    btnSubmit.textContent = 'Memproses...';

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: new URLSearchParams({
                            action: 'puri_transfer_mozart_execute',
                            nonce: '<?php echo esc_js($nonce); ?>',
                            from_location: fromSel.value,
                            to_location: toSel.value,
                            items: JSON.stringify(cart)
                        })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res?.success) {
                            alert(res?.data?.message || 'Transfer sukses via Mozart');
                            location.reload();
                        } else {
                            alert('Gagal: ' + (res?.data?.message || 'Unknown error'));
                            btnSubmit.disabled = false;
                            btnSubmit.textContent = 'Proses via Mozart';
                        }
                    })
                    .catch(err => {
                        alert('AJAX Error: ' + err.message);
                        btnSubmit.disabled = false;
                        btnSubmit.textContent = 'Proses via Mozart';
                    });
                });
            }

            renderCart();
        })();
        </script>
    </div>
    <?php
}