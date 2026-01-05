<?php
/**
 * MC 05 - POS Kasir (Hybrid Refactor)
 * Version: 6.1.5
 * Changes:
 * - Base Logic: v6.1.3 (Stable Cart & SyncUI)
 * - Feature: Added KYC (WIC) Panel with NIK & WA validation.
 * - UI: Added Kurs Jual display on Card Stock.
 * - Flow: Auto-Redirect to Invoice after success.
 */

defined('ABSPATH') || exit;

add_action('wp_ajax_puri_pos_execute_sale_v615', 'puri_pos_execute_sale_handler_v615');

function puri_render_pos_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    // 1. DATA MASTER ITEMS (Laci Kasir)
    $items = $wpdb->get_results("
        SELECT i.id, i.sku, i.name, i.type, i.denom_value, i.sell_rate,
        COALESCE(s.qty, 0) as physical_qty,
        COALESCE(l.qty_lock, 0) as locked_qty
        FROM " . puri_table_name('T_ITEMS') . " i
        LEFT JOIN " . puri_table_name('T_STOCK') . " s ON i.id = s.item_id AND s.location_id = 'laci_kasir'
        LEFT JOIN " . puri_table_name('T_LOCKS') . " l ON i.id = l.item_id
        ORDER BY i.type ASC, i.denom_value ASC
    ");

    $customers = get_posts(['post_type' => 'pr_customer', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);

    ?>
    <div class="wrap">
        <style>
            .pos-wrapper { display: grid; grid-template-columns: 1fr 380px; gap: 20px; margin-top: 20px; }
            .pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
            .pos-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; transition: 0.2s; }
            .pos-card:hover { border-color: #3b82f6; transform: translateY(-2px); }
            
            .card-sku { font-weight: 900; font-size: 16px; display: block; color: #0f172a; }
            .card-name { font-size: 11px; color: #64748b; display: block; height: 30px; margin-bottom: 5px; }
            .card-rate { font-weight: 800; font-size: 13px; color: #2563eb; display: block; margin-top: 5px; } /* Kurs Jual */
            .card-stock-badge { font-weight: 800; font-size: 11px; padding: 4px 10px; border-radius: 99px; background: #f1f5f9; }
            .card-stock-badge.low { background: #fee2e2; color: #ef4444; }
            
            .card-ctrl { margin-top: 15px; display: flex; gap: 5px; }
            .in-num { width: 55px; text-align: center; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; }
            .btn-send { flex: 1; background: #0f172a; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; }

            .cart-sidebar { background: #fff; border: 1px solid #d1d5db; border-radius: 12px; padding: 20px; position: sticky; top: 40px; }
            .kyc-panel { background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
            .kyc-input { width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; }
            
            .btn-pay { width: 100%; padding: 15px; background: #059669; color: #fff; border: none; border-radius: 8px; font-weight: 900; font-size: 18px; cursor: pointer; }
            .btn-pay:disabled { background: #cbd5e1; cursor: not-allowed; }
        </style>

        <h1 style="font-weight: 900;">💰 POS Kasir v6.1.5</h1>

        <div class="pos-wrapper">
            <div class="pos-main">
                <div class="pos-grid">
                    <?php foreach ($items as $it): 
                        $stok_bebas = $it->physical_qty - $it->locked_qty;
                    ?>
                    <div class="pos-card" data-id="<?php echo $it->id; ?>" data-sku="<?php echo esc_attr($it->sku); ?>" data-rate="<?php echo $it->sell_rate; ?>" data-denom="<?php echo $it->denom_value; ?>" data-origin-stock="<?php echo $stok_bebas; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <span class="card-sku"><?php echo esc_html($it->sku); ?></span>
                            <span class="card-stock-badge">📦 <?php echo number_format($stok_bebas); ?></span>
                        </div>
                        <span class="card-name"><?php echo esc_html($it->name); ?></span>
                        
                        <span class="card-rate">Kurs: Rp <?php echo number_format($it->sell_rate); ?></span>

                        <div class="card-ctrl">
                            <input type="number" class="in-num" value="1" min="1">
                            <button class="btn-send">KIRIM</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="cart-sidebar">
                <h3>🛒 Keranjang</h3>
                
                <div style="margin-bottom:15px;">
                    <label style="font-size:11px; font-weight:700;">PILIH CUSTOMER</label>
                    <select id="sel-customer" style="width:100%; padding:8px; margin-top:4px;">
                        <option value="0">-- Walk-in Customer (WIC) --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?php echo $c->ID; ?>"><?php echo esc_html($c->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="wic-panel" class="kyc-panel">
                    <label style="font-size:10px; font-weight:800; color:#475569;">DATA KYC (WAJIB WIC)</label>
                    <input type="text" id="wic-name" class="kyc-input" placeholder="Nama Lengkap">
                    <input type="number" id="wic-wa" class="kyc-input" placeholder="WhatsApp (62xxx)">
                    <input type="number" id="wic-nik" class="kyc-input" placeholder="NIK KTP (16 Digit)">
                </div>

                <div id="display-cart" style="min-height:150px; margin-bottom:15px; border-bottom:1px solid #f1f5f9;"></div>
                
                <div style="font-weight:900; font-size:22px; margin-bottom:15px; display:flex; justify-content:space-between;">
                    <span>TOTAL</span><span id="grand-total">Rp 0</span>
                </div>
                <button id="exec-pay" class="btn-pay" disabled>BAYAR & CETAK</button>
            </div>
        </div>

        <script>
        (function($){
            let cart = [];
            const soundClick = new Audio('https://assets.mixkit.co/active_storage/sfx/2500/2500-preview.mp3');

            function syncUI() {
                $('.pos-card').each(function(){
                    const $card = $(this);
                    const id = $card.data('id');
                    const origin = parseInt($card.data('origin-stock'));
                    const inCart = cart.filter(x => x.id === id).reduce((acc, curr) => acc + curr.qty, 0);
                    const currentDisplay = origin - inCart;
                    $card.find('.card-stock-badge').text('📦 ' + currentDisplay).toggleClass('low', currentDisplay <= 0);
                });
                renderCart();
            }

            function renderCart() {
                const $list = $('#display-cart');
                if (!cart.length) { $list.html('<p style="text-align:center;color:#94a3b8;margin-top:50px;">Kosong</p>'); $('#grand-total').text('Rp 0'); $('#exec-pay').prop('disabled', true); return; }
                let html = '', total = 0;
                cart.forEach((item, idx) => {
                    const line = item.qty * item.denom * item.rate;
                    total += line;
                    html += `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f8fafc; font-size:12px;">
                        <span><b>${item.sku}</b> x${item.qty}</span>
                        <span><b>Rp ${new Intl.NumberFormat().format(line)}</b> <a href="#" class="rem-item" data-idx="${idx}" style="color:red;text-decoration:none;margin-left:5px;">&times;</a></span>
                    </div>`;
                });
                $list.html(html);
                $('#grand-total').text('Rp ' + new Intl.NumberFormat().format(total));
                $('#exec-pay').prop('disabled', false);
            }

            // GUNA BASE LOGIC 6.1.3 UNTUK CART
            $('.btn-send').on('click', function(){
                const $card = $(this).closest('.pos-card');
                const qty = parseInt($card.find('.in-num').val()) || 1;
                const id = $card.data('id'), sku = $card.data('sku'), rate = $card.data('rate'), denom = $card.data('denom');
                const origin = parseInt($card.data('origin-stock'));
                const inCart = cart.filter(x => x.id === id).reduce((acc, curr) => acc + curr.qty, 0);

                if (inCart + qty > origin) { alert('Stok tidak cukup!'); return; }
                soundClick.currentTime = 0; soundClick.play();
                const exist = cart.find(x => x.id === id);
                if (exist) exist.qty += qty; else cart.push({ id, sku, rate, denom, qty });
                syncUI();
            });

            $(document).on('click', '.rem-item', function(e){ e.preventDefault(); cart.splice($(this).data('idx'), 1); syncUI(); });

            $('#sel-customer').on('change', function(){
                $('#wic-panel').toggle($(this).val() == '0');
            });

            // AJAX EXECUTION WITH KYC & REDIRECT
            $('#exec-pay').on('click', function(){
                const customer_id = $('#sel-customer').val();
                if (customer_id == '0') {
                    if (!$('#wic-name').val() || $('#wic-nik').val().length !== 16) {
                        alert('Data KYC WIC tidak valid! NIK harus 16 digit.'); return;
                    }
                }

                const btn = $(this);
                btn.prop('disabled', true).text('MEMPROSES...');

                $.post(ajaxurl, {
                    action: 'puri_pos_execute_sale_v615',
                    puri_admin_nonce: '<?php echo wp_create_nonce("puri_admin_action"); ?>',
                    customer_id: customer_id,
                    wic_data: {
                        name: $('#wic-name').val(),
                        wa: $('#wic-wa').val(),
                        nik: $('#wic-nik').val()
                    },
                    cart: JSON.stringify(cart)
                }, function(res){
                    if (res.success) { 
                        alert('Transaksi Berhasil!'); 
                        // REDIRECT TO INVOICE
                        location.href = 'admin.php?page=puri-invoice&ref_id=' + res.data.ref_id;
                    } else { 
                        alert('Gagal: ' + (res.data.message || res.data)); 
                        btn.prop('disabled', false).text('BAYAR & CETAK'); 
                    }
                }).fail(function(xhr) {
                    alert("SERVER ERROR!\nStatus: " + xhr.status + "\nResponse: " + xhr.responseText.substring(0, 100));
                    btn.prop('disabled', false).text('BAYAR & CETAK');
                });
            });

            syncUI();
        })(jQuery);
        </script>
    </div>
    <?php
}

/**
 * AJAX HANDLER: Power Logic v5.1.7
 */
function puri_pos_execute_sale_handler_v615() {
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    global $wpdb, $puri_engine;

    if (!isset($puri_engine)) {
        global $puri_engine;
        $puri_engine = $GLOBALS['puri_engine_v6'] ?? null;
    }
    if (!$puri_engine) wp_send_json_error(['message' => 'Engine PURI tidak merespon.']);

    $customer_id = intval($_POST['customer_id']);
    $wic_data = $_POST['wic_data'] ?? [];
    $cart = json_decode(stripslashes($_POST['cart']), true);
    if (empty($cart)) wp_send_json_error(['message' => 'Keranjang kosong.']);

    $ref_id = 'SLS-' . date('YmdHis');
    $wpdb->query('START TRANSACTION');

    try {
        $total_idr = 0;
        $items_log = [];

        foreach ($cart as $it) {
            $id = intval($it['id']);
            $qty = intval($it['qty']);
            $total_idr += ($qty * $it['denom'] * $it['rate']);
            $items_log[] = "{$qty}x {$it['sku']}";

            $item_data = $wpdb->get_row($wpdb->prepare("SELECT type, sku FROM ".puri_table_name('T_ITEMS')." WHERE id=%d", $id));
            
            if ($item_data->type === 'package') {
                $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'item_sku_code' AND meta_value = %s LIMIT 1", $item_data->sku));
                $recipe = function_exists('get_field') ? get_field('package_contents', $post_id) : null;
                
                if ($recipe) {
                    foreach ($recipe as $comp) {
                        $comp_sku = get_field('item_sku_code', $comp['p_item_ref']);
                        $comp_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ".puri_table_name('T_ITEMS')." WHERE sku=%s", $comp_sku));
                        $total_pcs = intval($comp['p_qty']) * $qty;
                        $puri_engine->update_stock_atomic('laci_kasir', $comp_id, -$total_pcs);
                        $puri_engine->adjust_virtual_lock($comp_id, -$total_pcs);
                    }
                }
                $puri_engine->adjust_virtual_lock($id, -$qty);
            } else {
                $res = $puri_engine->update_stock_atomic('laci_kasir', $id, -$qty);
                if (is_wp_error($res)) throw new Exception($res->get_error_message());
            }

            $wpdb->insert(puri_table_name('T_LEDGER'), [
                'location_id' => 'laci_kasir', 'item_id' => $id, 'qty_change' => -$qty,
                'ref_id' => $ref_id, 'description' => 'Penjualan POS', 'trx_date' => current_time('mysql')
            ]);
        }

        // DOUBLE ENTRY JOURNAL
        $desc = "Sales: " . implode(', ', $items_log);
        if ($customer_id == 0) $desc .= " (WIC: {$wic_data['name']})";

        $puri_engine->post_journal($ref_id, '1101', $total_idr, 0, $desc);
        $puri_engine->post_journal($ref_id, '4100', 0, $total_idr, $desc);

        $wpdb->query('COMMIT');
        wp_send_json_success(['message' => 'Lunas!', 'ref_id' => $ref_id]);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}