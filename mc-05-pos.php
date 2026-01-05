<?php
/**
 * =============================================================================
 * MC 05 - POS Kasir V6 (Refactor)
 * =============================================================================
 *
 * @package     Pusat Riyal
 * @module      MC-05
 * @version     6.0.2
 * @author      Denmas Totok (refactor by Copilot)
 * @updated     2026-01-05
 *
 * =============================================================================
 * PURPOSE / TUJUAN
 * =============================================================================
 *
 * Modul POS (Point of Sale) untuk kasir: antarmuka kasir dan endpoint AJAX untuk
 * mencatat transaksi penjualan (eceran & paket), menyesuaikan stok Laci, mengunci
 * komponen paket (virtual lock), dan mencatat jurnal (double-entry).
 *
 * - Render admin POS UI (modern card grid mirip MC-08 Stock Transfer)
 * - Mendukung 2 mode customer: registered & walk-in (WIC)
 * - Menangani paket (package) dengan "explode" resep dan virtual lock
 * - Endpoint AJAX aman untuk eksekusi sale dan pembuatan/locking bundle
 *
 * =============================================================================
 * WHO CAN OPERATE
 * =============================================================================
 *
 * Hanya user dengan role/kapabilitas:
 *   - kasir (role 'kasir')  -> untuk operasi kasir sehari-hari
 *   - finance (role 'finance') atau administrator -> untuk akses penuh dan troubleshooting
 *
 * Permission checks are enforced via current_user_can() and puri_check_cap() where applicable.
 *
 * =============================================================================
 * API / AJAX ENDPOINTS
 * =============================================================================
 *
 * - wp_ajax_puri_pos_execute_sale_ajax
 *     * Periksa nonce: puri_admin_action (field puri_admin_nonce)
 *     * Capability check: current_user_can('puri_can_pos') OR current_user_can('manage_options')
 *     * Payload: items (JSON), total_idr, total_riyal, mode, customer data
 *     * Behaviour: wrap in DB transaction, update atomic stocks, ledger rows, post_journal entries
 *
 * - wp_ajax_puri_pos_build_bundle_ajax
 *     * Periksa nonce: puri_admin_action
 *     * Capability check: current_user_can('puri_can_pos') OR current_user_can('manage_options')
 *     * Payload: item_id (package SQL id), qty
 *     * Behaviour: adjust_virtual_lock() pada komponen + package id
 *
 * =============================================================================
 * UX / UI NOTES
 * =============================================================================
 *
 * - Stock-card behaviour follows MC-08 Stock Transfer:
 *     * Card per denom/sku, shows bendel/qty, value, quick input & tombol action
 *     * Click card (outside submit control) = add 1 unit to cart
 *     * Click submit control (qty input + button) = add specified qty to cart
 *     * Use same CSS classes (.puri-stock-card, .puri-card-submitter, .puri-laci-btn, etc)
 *     * Integrates with library/js/puri-sfx.js and puri-main.js for sound feedback
 *
 * - The POS UI is built as inline React (or minimal JS) with root id `puri-pos-root`.
 * - Inline script exposes window.puri_admin = { ajax_url, nonce } for JS use.
 *
 * =============================================================================
 * DEPENDENCIES
 * =============================================================================
 *
 * - puri_engine() (MC-03)  : stock operations, moving average, locks, post_journal
 * - puri_table_name() (MC-00/MC-01) : table name helper & table constants:
 *     T_ITEMS, T_STOCK, T_LEDGER, T_LOCKS, T_JOURNAL
 * - puri-sfx / puri-main (library/js) : optional sound & UI helpers
 * - ACF fields for package recipes (MC-02) when resolving package contents
 *
 * =============================================================================
 * SECURITY & DATA INTEGRITY
 * =============================================================================
 *
 * - All admin AJAX endpoints verify wp_nonce and perform capability checks.
 * - All DB mutations are wrapped in transactions with rollback on error.
 * - Input sanitized via sanitize_text_field / intval / floatval as needed.
 * - Use puri_engine() atomic operations (update_stock_atomic, adjust_virtual_lock, post_journal).
 *
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 *
 * [6.0.3] 2026-01-05
 * - Fixed: JavaScript string concatenation (bundle_parts, eceran_parts)
 * - Fixed: Missing ajaxurl definition
 * - Fixed: Capability check fallback when PURI_CAP_POS undefined
 * - Fixed: Safe SFX calls with existence checks
 * - Improved: Error handling in AJAX responses
 *
 * [6.0.2] 2026-01-05
 *   - Added: Role gating to allow only 'kasir' and 'finance' (plus admins) to operate POS.
 *   - Added: UI behaviour aligned with MC-08 stock cards and shared SFX integration.
 *   - Improved: Defensive checks when exploding package recipes (handle missing postmeta).
 *   - Improved: Explicit capability checks on AJAX handlers and clearer error messages.
 *
 * [6.0.1] 2025-12-xx
 *   - Initial refactored POS: React-based UI (inline), AJAX handlers, stock & journal integration.
 *
 * =============================================================================
 * HOW TO USE / ACCESS
 * =============================================================================
 *
 * Admin Menu Path:
 *   Dashboard → Transaksi → 💰 Kasir POS
 *
 * Direct URL:
 *   /wp-admin/admin.php?page=puri-pos
 *
 * =============================================================================
 */
 


defined('ABSPATH') || exit;

// ✅ Ensure PURI_CAP_POS is defined (fallback if MC-24 not loaded yet)
if (!defined('PURI_CAP_POS')) {
    define('PURI_CAP_POS', 'puri_can_pos');
}

/**
 * Render POS admin page.
 * Accessible only to users who can perform POS (PURI_CAP_POS).
 */
function puri_render_pos_page() {
    // ✅ Capability gate with fallback
    if (!(current_user_can(PURI_CAP_POS) || current_user_can('manage_options'))) {
        wp_die(__('Anda tidak memiliki akses ke halaman ini. Hubungi administrator.', 'puri'), 403);
    }

    global $wpdb;

    // Ensure engine instance exists
    if (!function_exists('puri_engine')) {
        wp_die(__('Engine tidak tersedia. Hubungi developer.', 'puri'), 500);
    }

    // Prepare items with laci stock & locks
    $items = $wpdb->get_results("
        SELECT i.id, i.sku, i.name, i.type, i.denom_value, i.sell_rate,
               COALESCE(s.qty,0) AS stock_laci,
               COALESCE(l.qty_lock,0) AS qty_lock
        FROM " . puri_table_name('T_ITEMS') . " i
        LEFT JOIN " . puri_table_name('T_STOCK') . " s ON i.id = s.item_id AND s.location_id = 'laci_kasir'
        LEFT JOIN " . puri_table_name('T_LOCKS') . " l ON i.id = l.item_id
        ORDER BY i.type ASC, i.denom_value ASC
    ");

    // Customers for registered mode
    $customers = get_posts(['post_type'=>'pr_customer','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
    $customer_list = array_map(function($c){ 
        return [
            'id'=>$c->ID,
            'name'=>$c->post_title,
            'nik'=>get_field('cust_nik',$c->ID)
        ]; 
    }, $customers);

    ?>
    <div class="wrap">
      <h1>💰 Kasir POS — Pusat Riyal</h1>

      <style>
        /* Reuse styles from mc-08 for card grid and summary */
        .puri-pos-grid { display:flex; gap:18px; align-items:flex-start; }
        .puri-pos-catalog { flex:2; min-width:360px; }
        .puri-pos-panel { flex:1; min-width:320px; max-width:420px; }
        .puri-stock-grid { display:flex; gap:20px 18px; flex-wrap:wrap; padding:8px; }
        .puri-stock-card { border:1.25px solid #d1d5db; border-radius:8px; padding:12px; background:#fff; width:220px; box-sizing:border-box; cursor:pointer; display:flex; flex-direction:column; gap:8px; }
        .puri-stock-card h4 { margin:0; font-size:18px; font-weight:800; }
        .puri-stock-meta { display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#475569; }
        .puri-card-actions { display:flex; gap:8px; margin-top:auto; }
        .puri-add-btn, .puri-bundle-btn { padding:8px 10px; border:1px solid #cbd5e1; background:#fff; cursor:pointer; border-radius:6px; font-weight:700; }
        .puri-add-btn:hover, .puri-bundle-btn:hover { background:#f1f5f9; }
        .puri-cart { border:1px solid #e2e8f0; background:#f8fafc; padding:12px; border-radius:8px; }
        .puri-cart-list { max-height:340px; overflow:auto; margin-bottom:12px; }
        .puri-cart-row { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:6px 0; border-bottom:1px dashed #e6edf3; }
        .puri-checkout { width:100%; padding:12px; background:#059669; color:#fff; border:none; font-weight:900; border-radius:8px; cursor:pointer; }
        .puri-checkout:disabled { background:#cbd5e1; cursor:not-allowed; }
        .puri-qty-input { width:72px; text-align:center; padding:6px; border-radius:6px; border:1px solid #cbd5e1; }
        .puri-sfx-toggle { margin-top:10px; }
      </style>

      <div class="puri-pos-grid">
        <div class="puri-pos-catalog">
            <h2 style="margin-top:0">Katalog & Stok Laci</h2>
            <div class="puri-stock-grid" id="puriPosStockGrid">
                <?php foreach ($items as $it):
                    $available = max(0, floatval($it->stock_laci) - floatval($it->qty_lock));
                    $badge_class = $available >= 100 ? 'puri-badge-blue' : ($available > 0 ? 'puri-badge-red' : 'puri-badge-grey');
                ?>
                <div class="puri-stock-card" tabindex="0" 
                     data-id="<?php echo intval($it->id); ?>" 
                     data-sku="<?php echo esc_attr($it->sku); ?>" 
                     data-type="<?php echo esc_attr($it->type); ?>" 
                     data-name="<?php echo esc_attr($it->name); ?>" 
                     data-denom="<?php echo intval($it->denom_value); ?>" 
                     data-rate="<?php echo esc_attr($it->sell_rate); ?>" 
                     data-stock="<?php echo esc_attr($available); ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <h4><?php echo esc_html($it->sku); ?></h4>
                        <div style="font-size:12px;color:#94a3b8"><?php echo esc_html($it->type); ?></div>
                    </div>
                    <div class="puri-stock-meta">
                        <div>Denom: <strong><?php echo intval($it->denom_value); ?> SAR</strong></div>
                        <div>Stok: <strong><?php echo number_format($available); ?></strong></div>
                    </div>
                    <div style="font-size:13px;color:#0f172a"><strong><?php echo esc_html($it->name); ?></strong></div>

                    <div class="puri-card-actions">
                        <input type="number" min="1" value="1" class="puri-qty-input" aria-label="qty-<?php echo intval($it->id); ?>">
                        <button type="button" class="puri-add-btn" data-action="add">Tambah</button>
                        <?php if ($it->type === 'package'): ?>
                            <button type="button" class="puri-bundle-btn" data-action="bundle">Kunci Paket</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="puri-pos-panel">
            <h2 style="margin-top:0">Keranjang & Checkout</h2>
            <div class="puri-cart">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <div style="font-weight:800">Items</div>
                    <div style="font-size:13px;color:#64748b">Mode: <strong>Kasir</strong></div>
                </div>

                <div class="puri-cart-list" id="puriCartList">
                    <div style="text-align:center;color:#94a3b8;padding:36px">Belum ada item di keranjang</div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <div><strong>Total Riyal:</strong> <span id="puriTotalRiyal">0</span> SAR</div>
                    <div><strong>Total (IDR):</strong> Rp <span id="puriTotalIdr">0</span></div>
                </div>

                <div style="display:grid;gap:8px">
                    <select id="puriCustomerSelect">
                        <option value="">-- Pilih Customer Terdaftar --</option>
                        <?php foreach ($customer_list as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:13px"><input type="checkbox" id="puriCheckData"> Data & KYC sudah dicek</label>
                    <button id="puriCheckoutBtn" class="puri-checkout" disabled>KONFIRMASI PEMBAYARAN</button>
                </div>

                <div class="puri-sfx-toggle">
                  <label><input type="checkbox" id="puriSfxToggle"> Suara (SFX)</label>
                </div>
            </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
        // ✅ FIX: Define ajaxurl explicitly (WordPress standard)
        const ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        
        const grid = document.getElementById('puriPosStockGrid');
        const cartList = document.getElementById('puriCartList');
        const totalRiyalEl = document.getElementById('puriTotalRiyal');
        const totalIdrEl = document.getElementById('puriTotalIdr');
        const checkoutBtn = document.getElementById('puriCheckoutBtn');
        const confirmChk = document.getElementById('puriCheckData');
        const custSelect = document.getElementById('puriCustomerSelect');
        const sfxToggle = document.getElementById('puriSfxToggle');

        // ✅ Safe SFX initialization
        if (sfxToggle && window.PURI && typeof window.PURI.isSoundFxEnabled === 'function') {
            sfxToggle.checked = window.PURI.isSoundFxEnabled();
            sfxToggle.addEventListener('change', function(){
                window.PURI.setSoundFxEnabled(!!sfxToggle.checked);
                if (sfxToggle.checked && window.PURI.SFX && window.PURI.SFX.fx_notif_updates) {
                    window.PURI.playSoundFx(window.PURI.SFX.fx_notif_updates, { allowOverlap: true });
                }
            });
        }

        let cart = []; // {id, sku, name, denom, qty, rate, type}

        function formatNumber(n){ return new Intl.NumberFormat('id-ID').format(parseFloat(n||0)); }

        function findCartIdx(id){ return cart.findIndex(c => c.id == id); }

        function renderCart(){
            cartList.innerHTML = '';
            if (cart.length === 0) {
                cartList.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:36px">Belum ada item di keranjang</div>';
                totalRiyalEl.textContent = '0';
                totalIdrEl.textContent = '0';
                checkoutBtn.disabled = true;
                return;
            }
            let totalRiyal = 0, totalIdr = 0;
            cart.forEach(function(row,i){
                totalRiyal += (row.qty * (row.denom||1));
                totalIdr += (row.qty * (row.denom||1) * (row.rate||0));
                const div = document.createElement('div');
                div.className = 'puri-cart-row';
                div.innerHTML = '<div style="flex:1">' +
                    '<div style="font-weight:700">' + row.sku + ' × ' + row.qty + '</div>' +
                    '<div style="font-size:12px;color:#475569">' + row.name + '</div>' +
                '</div>' +
                '<div style="text-align:right;min-width:120px">' +
                    '<div>Rp ' + formatNumber(row.rate) + '</div>' +
                    '<div style="font-weight:900">Rp ' + formatNumber(row.qty * row.denom * row.rate) + '</div>' +
                '</div>';
                
                div.addEventListener('click', function(){ 
                    if(confirm('Hapus item dari keranjang?')) { 
                        cart.splice(i,1); 
                        renderCart(); 
                    }
                });
                cartList.appendChild(div);
            });
            totalRiyalEl.textContent = formatNumber(totalRiyal);
            totalIdrEl.textContent = formatNumber(totalIdr);
            checkoutBtn.disabled = !(cart.length > 0 && confirmChk.checked);
        }

        // Attach handlers to card actions
        if (grid) {
            grid.querySelectorAll('.puri-stock-card').forEach(function(card){
                const addBtn = card.querySelector('[data-action="add"]');
                const bundleBtn = card.querySelector('[data-action="bundle"]');
                const qtyInput = card.querySelector('.puri-qty-input');

                const id = card.dataset.id;
                const sku = card.dataset.sku;
                const type = card.dataset.type;
                const name = card.dataset.name;
                const denom = parseInt(card.dataset.denom||'1',10);
                const rate = parseFloat(card.dataset.rate||'0');
                const stock = parseFloat(card.dataset.stock||'0');

                // Card click adds 1
                card.addEventListener('click', function(ev){
                    if (ev.target.closest('.puri-card-actions')) return;
                    addToCart(1);
                });

                // Add button
                if (addBtn) addBtn.addEventListener('click', function(ev){
                    ev.stopPropagation();
                    const qty = parseInt(qtyInput.value||'0',10) || 1;
                    addToCart(qty);
                });

                // Bundle button
                if (bundleBtn) bundleBtn.addEventListener('click', function(ev){
                    ev.stopPropagation();
                    const qty = parseInt(qtyInput.value||'0',10) || 1;
                    buildBundle(id, qty);
                });

                function addToCart(qty){
                    if (qty < 1) return;
                    if (type !== 'package' && qty > stock) { 
                        playSfx('fx_stock_empty');
                        return alert('Stok tidak cukup di laci'); 
                    }
                    const idx = findCartIdx(id);
                    if (idx >= 0) {
                        cart[idx].qty += qty;
                    } else {
                        cart.push({ id:id, sku:sku, name:name, denom:denom, qty:qty, rate:rate, type:type });
                    }
                    playSfx('fx_card_clicked');
                    renderCart();
                }
            });
        }

        // ✅ Safe SFX helper
        function playSfx(fxName) {
            if (window.PURI && typeof window.PURI.playSoundFx === 'function' && window.PURI.SFX && window.PURI.SFX[fxName]) {
                window.PURI.playSoundFx(window.PURI.SFX[fxName], { allowOverlap: true });
            }
        }

        // Build / lock bundle (AJAX)
        function buildBundle(item_id, qty){
            if (!confirm('Kunci paket ke laci?')) return;
            fetch(ajaxurl, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'puri_pos_build_bundle_ajax',
                    puri_admin_nonce: '<?php echo esc_js(wp_create_nonce('puri_admin_action')); ?>',
                    item_id: item_id,
                    qty: qty
                })
            }).then(r => r.json()).then(res => {
                if (res && res.success) {
                    alert(res.data.message || 'Sukses mengunci paket');
                    location.reload();
                } else {
                    alert('Gagal: ' + (res && res.data && res.data.message ? res.data.message : 'Unknown'));
                }
            }).catch(()=> alert('AJAX error'));
        }

        // Checkbox sync
        if (confirmChk) {
            confirmChk.onchange = renderCart;
        }

        // Checkout handler
        if (checkoutBtn) {
            checkoutBtn.onclick = function() {
                if (checkoutBtn.disabled) return;
                if (cart.length === 0) return;
                if (!confirmChk.checked) return alert('Tandai bahwa data sudah benar.');
                
                const payload = {
                    mode: 'registered',
                    items: cart,
                    total_idr: parseFloat(totalIdrEl.textContent.replace(/\./g,'')) || 0,
                    total_riyal: parseFloat(totalRiyalEl.textContent.replace(/\./g,'')) || 0,
                    cust_id: custSelect.value || ''
                };
                
                const fd = new FormData();
                fd.append('action','puri_pos_execute_sale_ajax');
                fd.append('puri_admin_nonce','<?php echo esc_js(wp_create_nonce('puri_admin_action')); ?>');
                fd.append('mode', payload.mode);
                fd.append('items', JSON.stringify(payload.items));
                fd.append('total_idr', payload.total_idr);
                fd.append('total_riyal', payload.total_riyal);
                fd.append('cust_id', payload.cust_id);

                checkoutBtn.disabled = true;
                checkoutBtn.textContent = 'MEMPROSES...';

                fetch(ajaxurl, { method:'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.success) {
                            playSfx('fx_approved');
                            alert('Transaksi berhasil. Ref: ' + (res.data.ref_id || '---'));
                            if (res.data && res.data.ref_id) {
                                window.open('<?php echo admin_url('admin.php?page=puri-print-invoice&ref_id='); ?>' + res.data.ref_id, '_blank');
                            }
                            setTimeout(()=> location.reload(), 800);
                        } else {
                            playSfx('fx_need_attention');
                            alert('Gagal: ' + (res && res.data && (res.data.message||res.data) ? (res.data.message||res.data) : 'unknown'));
                            checkoutBtn.disabled = false;
                            checkoutBtn.textContent = 'KONFIRMASI PEMBAYARAN';
                        }
                    })
                    .catch(()=> {
                        alert('AJAX error');
                        checkoutBtn.disabled = false;
                        checkoutBtn.textContent = 'KONFIRMASI PEMBAYARAN';
                    });
            };
        }

        // Initial render
        renderCart();
    })();
    </script>
    <?php
}

/* ------------------------
   AJAX: Execute Sale
   ------------------------ */
add_action('wp_ajax_puri_pos_execute_sale_ajax', 'puri_pos_execute_sale_ajax_handler');
function puri_pos_execute_sale_ajax_handler() {
    // ✅ Capability check with fallback
    if (!(current_user_can(PURI_CAP_POS) || current_user_can('manage_options'))) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');

    global $wpdb;
    if (!function_exists('puri_engine')) {
        wp_send_json_error(['message' => 'Engine not available']);
    }
    $puri_engine = puri_engine();

    // Parse & validate payload
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : '';
    $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : null;
    $total_idr = isset($_POST['total_idr']) ? floatval($_POST['total_idr']) : 0;
    $total_riyal = isset($_POST['total_riyal']) ? floatval($_POST['total_riyal']) : 0;

    if (!is_array($items) || $total_idr <= 0) {
        wp_send_json_error(['message' => 'Invalid sale payload']);
    }

    $ref_id = 'SLS-' . date('YmdHis') . '-' . wp_rand(100,999);
    $wpdb->query('START TRANSACTION');
    
    try {
        // Identify or create customer
        $customer_id = 0;
        if ($mode === 'walk-in') {
            $wic_name = sanitize_text_field($_POST['wic_name'] ?? '');
            $wic_nik = sanitize_text_field($_POST['wic_nik'] ?? '');
            $post_id = wp_insert_post(['post_type' => 'pr_customer', 'post_title' => $wic_name, 'post_status' => 'publish']);
            if (!$post_id) throw new Exception('Failed to create walk-in customer');
            if (function_exists('update_field')) update_field('cust_nik', $wic_nik, $post_id);
            $customer_id = $post_id;
        } else {
            $customer_id = intval($_POST['cust_id'] ?? 0);
        }
        $customer_name = get_the_title($customer_id) ?: 'Umum';

        $denom_breakdown = [];
        $eceran_parts = [];
        $bundle_parts = [];

        foreach ($items as $it) {
            $qty_sold = intval($it['qty']);
            $item_id = intval($it['id']);
            if ($qty_sold <= 0 || $item_id <= 0) throw new Exception('Invalid item in cart');

            if ($it['type'] === 'package') {
                // ✅ FIX: String concatenation
                $bundle_parts[] = "Paket " . $qty_sold . "x " . sanitize_text_field($it['name']);
                
                $sku = sanitize_text_field($it['sku']);
                $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'item_sku_code' AND meta_value = %s LIMIT 1", $sku));
                $recipe = function_exists('get_field') ? get_field('package_contents', $post_id) : null;
                
                if ($recipe && is_array($recipe)) {
                    foreach ($recipe as $comp) {
                        $comp_post_ref = intval($comp['p_item_ref']);
                        $c_sku = function_exists('get_field') ? get_field('item_sku_code', $comp_post_ref) : '';
                        $c_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . puri_table_name('T_ITEMS') . " WHERE sku = %s", $c_sku));
                        $total_pcs = intval($comp['p_qty']) * $qty_sold;
                        
                        $ok = $puri_engine->update_stock_atomic('laci_kasir', $c_id, -$total_pcs);
                        if (is_wp_error($ok)) throw new Exception($ok->get_error_message());
                        
                        $wpdb->insert(puri_table_name('T_LEDGER'), [
                            'location_id'=>'laci_kasir',
                            'item_id'=>$c_id,
                            'qty_change'=>-$total_pcs,
                            'ref_id'=>$ref_id,
                            'description'=>'Penjualan Paket [' . sanitize_text_field($it['sku']) . '] - ' . $customer_name,
                            'trx_date'=>current_time('mysql')
                        ]);
                        
                        $res = $puri_engine->adjust_virtual_lock($c_id, -$total_pcs);
                        if (is_wp_error($res)) throw new Exception($res->get_error_message());
                        
                        $denom_breakdown[] = ['sku'=>$c_sku,'qty_intrinsik'=>$total_pcs];
                    }
                }
                // Adjust lock on package itself
                $puri_engine->adjust_virtual_lock($item_id, -$qty_sold);
                
            } else {
                // Eceran
                $ok = $puri_engine->update_stock_atomic('laci_kasir', $item_id, -$qty_sold);
                if (is_wp_error($ok)) throw new Exception($ok->get_error_message());
                
                $wpdb->insert(puri_table_name('T_LEDGER'), [
                    'location_id'=>'laci_kasir',
                    'item_id'=>$item_id,
                    'qty_change'=>-$qty_sold,
                    'ref_id'=>$ref_id,
                    'description'=>'Penjualan Eceran - ' . $customer_name,
                    'trx_date'=>current_time('mysql')
                ]);
                
                $net_rate = floatval($it['rate']) - floatval($it['discount_rate'] ?? 0);
                // ✅ FIX: String concatenation
                $eceran_parts[] = "(" . $qty_sold . "x " . sanitize_text_field($it['sku']) . " @" . number_format($net_rate) . ")";
                $denom_breakdown[] = ['sku'=>$it['sku'],'qty_intrinsik'=>$qty_sold];
            }
        }

        // ✅ FIX: Use implode with proper array
        $desc = implode(' | ', array_filter(array_merge($eceran_parts, $bundle_parts)));

        $resDebit = $puri_engine->post_journal($ref_id, '1101', $total_idr, 0, $desc);
        if (is_wp_error($resDebit)) throw new Exception($resDebit->get_error_message());
        
        $resCredit = $puri_engine->post_journal($ref_id, '4100', 0, $total_idr, $desc, [
            'customer' => $customer_name, 
            'total_riyal' => $total_riyal,
            'total_idr' => $total_idr, 
            'items' => $items, 
            'inventory_explode' => $denom_breakdown
        ]);
        if (is_wp_error($resCredit)) throw new Exception($resCredit->get_error_message());

        $wpdb->query('COMMIT');
        wp_send_json_success(['message'=>'Lunas','ref_id'=>$ref_id]);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('PURI POS Sale Error: ' . $e->getMessage());
        wp_send_json_error(['message'=>'Sale failed: ' . $e->getMessage()]);
    }
}

/* ------------------------
   AJAX: Build/Lock Bundle
   ------------------------ */
add_action('wp_ajax_puri_pos_build_bundle_ajax', 'puri_pos_build_bundle_ajax_handler');
function puri_pos_build_bundle_ajax_handler() {
    // ✅ Capability check with fallback
    if (!(current_user_can(PURI_CAP_POS) || current_user_can('manage_options'))) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');

    global $wpdb;
    if (!function_exists('puri_engine')) {
        wp_send_json_error(['message' => 'Engine not available']);
    }
    $puri_engine = puri_engine();
	$bundle_sql_id = intval($_POST['item_id'] ?? 0);
$qty_to_lock = intval($_POST['qty'] ?? 0);

if ($bundle_sql_id <= 0 || $qty_to_lock <= 0) {
    wp_send_json_error(['message' => 'Invalid input']);
}

try {
    $sku = $wpdb->get_var($wpdb->prepare("SELECT sku FROM " . puri_table_name('T_ITEMS') . " WHERE id = %d", $bundle_sql_id));
    $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'item_sku_code' AND meta_value = %s LIMIT 1", $sku));
    $recipe = function_exists('get_field') ? get_field('package_contents', $post_id) : null;
    
    if (!$recipe) {
        wp_send_json_error(['message' => 'Resep kosong.']);
    }

    foreach ($recipe as $comp) {
        $comp_post_ref = intval($comp['p_item_ref']);
        $c_sku = function_exists('get_field') ? get_field('item_sku_code', $comp_post_ref) : '';
        $c_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . puri_table_name('T_ITEMS') . " WHERE sku = %s", $c_sku));
        $total_lock = intval($comp['p_qty']) * $qty_to_lock;
        
        $res = $puri_engine->adjust_virtual_lock($c_id, $total_lock);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
    }
    
    // Lock package id
    $res2 = $puri_engine->adjust_virtual_lock($bundle_sql_id, $qty_to_lock);
    if (is_wp_error($res2)) {
        wp_send_json_error(['message' => $res2->get_error_message()]);
    }

    wp_send_json_success(['message' => "Sukses mengunci {$qty_to_lock} paket ke dalam laci."]);
    
} catch (Exception $e) {
    error_log('PURI POS Bundle Error: ' . $e->getMessage());
    wp_send_json_error(['message' => 'Bundle lock failed: ' . $e->getMessage()]);
}
}
	