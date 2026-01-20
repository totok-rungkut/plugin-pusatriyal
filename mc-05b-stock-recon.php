<?php
/**
 * MC-05B - SUPERVISOR RECONCILIATION & MOZART COURIER
 * @version 7.8.21 (Payload Standardized)
 * Purpose: Jembatan (Bridge) antara T_POOL_TRANSACTIONS dengan MOZART ENGINE (MC-03).
 * Rules: Cash-Based, No-Recalculate HPP, Direct Posting.
 */

defined('ABSPATH') || exit;

// -----------------------------------------------------------------------------
// 1. KURIR MOZART (POST HANDLER)
// -----------------------------------------------------------------------------
add_action('admin_init', function() {
    if (isset($_POST['post_to_mozart_batch']) && check_admin_referer('puri_mozart_bridge')) {
        
        global $wpdb;
        $refs = $_POST['refs'] ?? [];
        
        if (empty($refs)) {
            add_settings_error('puri_msg', 'err', 'Pilih minimal satu transaksi.', 'error');
            return;
        }

        $engine = puri_mozart(); // Memanggil Instance MC-03
        $success_count = 0;
        $fail_count = 0;
        $t_items = puri_table_name('T_ITEMS');

        foreach ($refs as $ref_id) {
            // A. Ambil Data Header dari Pool
            $pool = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . puri_table_name('T_POOL_TRANSACTIONS') . " WHERE ref_id = %s AND status = 'pending'", 
                $ref_id
            ));

            if (!$pool) continue;

            // B. DECODE SNAPSHOT (Koreksi nama kolom ke items_snapshot)
            $raw_items = json_decode($pool->items_snapshot, true); // 
            $clean_items = [];
            
            if ($raw_items) {
                foreach ($raw_items as $raw) {
                    $clean_items[] = [
                        'item_id'     => intval($raw['sql_id']), // [cite: 1327]
                        'sku'         => $raw['sku'],
                        'name'        => get_the_title($raw['wp_post_id']),
                        'qty'         => floatval($raw['qty']),
                        'total_valas' => floatval($raw['riyal']),
                        'unit_price'  => floatval($raw['idr'] / ($raw['riyal'] ?: 1)), 
                        'subtotal_idr'=> floatval($raw['idr'])
                    ];
                }
            }

            if (empty($clean_items)) { $fail_count++; continue; }

            // C. RESOLUSI AKUN DINAMIS (MC-28)
            $is_buyback = ($pool->trade_mode === 'buy');
            $pay_account = ($pool->payment_method === 'cash') ? puri_gl('cash_drawer') : puri_gl('default_bank'); // [cite: 1709, 1724]

            // D. SUSUN NAMPAN MATANG (Standardized for Mozart)
            $nampan = [
                'source'            => 'pos_submission', // Match dengan mc-03c 
                'source_ref'        => $pool->ref_id,
                'created_at'        => $pool->trx_date,
                'counterparty_id'   => $pool->customer_id ?: "-",
                'counterparty_name' => $pool->customer_name ?: "Nasabah Umum",
                'is_buyback'        => $is_buyback,
				'total_hpp'         => floatval($pool->total_hpp),    // <-- PATCH: Kirim HPP yang sudah lahir di POS
                'description'       => ($is_buyback ? "Buyback: " : "Sales: ") . ($pool->notes ?: $pool->ref_id),
                'total_idr'         => floatval($pool->total_idr),
                'items'             => $clean_items,
                'payments'          => [ // Struktur Array untuk MC-03C [cite: 1683]
                    [
                        'account_code' => $pay_account,
                        'amount'       => floatval($pool->total_idr)
                    ]
                ],
                'options'           => [
                    'recalculate_hpp' => false, // Proteksi HPP [cite: 1395]
                    'force_cash_mode' => true
                ],
                'snapshot_json'     => "" // Akan diisi otomatis oleh Mozart store_json
            ];

            // Tambahkan snapshot JSON untuk Audit Trail Mozart [cite: 1621]
            $nampan['snapshot_json'] = json_encode($nampan);

            // E. EKSEKUSI KE MOZART
            $result = $engine->execute('pos_submission', $nampan); //

            if (!is_wp_error($result)) {
                // Update Status Pool
                $wpdb->update(
                    puri_table_name('T_POOL_TRANSACTIONS'), 
                    ['status' => 'posted'], 
                    ['ref_id' => $ref_id]
                );
                $success_count++;
            } else {
                $fail_count++;
            }
        }



        if ($success_count > 0) {
            add_settings_error('puri_msg', 'success', "Sukses memposting $success_count transaksi.", 'updated');
        }
        if ($fail_count > 0) {
            add_settings_error('puri_msg', 'err', "$fail_count transaksi gagal (SKU mismatch atau DB error).", 'error');
        }
    }
});

// -----------------------------------------------------------------------------
// 2. RENDER HALAMAN (THE VIEWER)
// -----------------------------------------------------------------------------
function puri_render_reconciliation_page() {
    global $wpdb;
    settings_errors('puri_msg');

    // Fetch Pending Data
    $table_pool = puri_table_name('T_POOL_TRANSACTIONS');
    $results = $wpdb->get_results("
        SELECT t.*, u.display_name 
        FROM $table_pool t
        LEFT JOIN {$wpdb->users} u ON t.created_by = u.ID
        WHERE t.status = 'pending'
        ORDER BY t.created_at ASC
    ");
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🎼 Supervisor Reconciliation (Pool Control)</h1>
        <p class="description">
            Validasi Transaksi Kasir &rarr; Posting ke Mozart Engine (MC-03).<br>
            <span class="dashicons dashicons-lock"></span> <strong>Mode:</strong> Cash-Based | HPP Locked (No Recalc).
        </p>
        <hr class="wp-header-end">

        <?php if (empty($results)): ?>
            <div class="notice notice-info inline"><p>✅ Tidak ada antrian transaksi di Pool.</p></div>
        <?php else: ?>
            <form method="post" id="recon-form">
                <?php wp_nonce_field('puri_mozart_bridge'); ?>
                
                <div class="tablenav top" style="background:#fff; padding:10px; border:1px solid #c3c4c7; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                    <div class="alignleft">
                        <label style="font-weight:600;"><input type="checkbox" id="select-all-global"> Pilih Semua</label>
                        <span id="counter-span" style="margin-left:15px; background:#f0f0f1; padding:2px 8px; border-radius:4px;">0 terpilih</span>
                    </div>
                    <div class="alignright">
                        <button type="submit" name="post_to_mozart_batch" class="button button-primary" id="btn-process" disabled>
                            🚀 Sajikan ke Mozart
                        </button>
                    </div>
                </div>

                <div id="pool-list">
                    <?php foreach ($results as $row): 
						$items = json_decode($row->items_snapshot, true); // Sinkron dengan DB [cite: 1594]                        $is_cash = ($row->payment_method === 'cash');
                        $edge_color = $is_cash ? '#00a32a' : '#2271b1'; 
                    ?>
                    <div class="postbox" style="margin-bottom:15px; border-left:4px solid <?php echo $edge_color; ?>;">
                        <div class="postbox-header" style="display:flex; justify-content:space-between; align-items:center; padding:10px;">
                            <div style="flex:2;">
                                <label>
                                    <input type="checkbox" name="refs[]" value="<?php echo $row->ref_id; ?>" class="report-checkbox">
                                    <strong style="font-family:monospace; font-size:1.1em; margin-left:5px;"><?php echo $row->ref_id; ?></strong>
                                </label>
                                <span style="color:#ccc; margin:0 8px;">|</span>
                                <?php echo date('d-M H:i', strtotime($row->created_at)); ?>
                                <span style="color:#ccc; margin:0 8px;">|</span>
                                👤 <?php echo esc_html($row->display_name ?: 'System'); ?>
                            </div>
                            <div style="flex:1; text-align:right;">
                                <strong style="font-size:1.1em; color:<?php echo $edge_color; ?>;">
                                    <?php echo number_format($row->total_amount, 0, ',', '.'); ?>
                                </strong>
                                <span style="text-transform:uppercase; font-size:10px; background:#f0f0f1; padding:2px 5px; border-radius:3px; margin-left:5px;">
                                    <?php echo $row->payment_method; ?>
                                </span>
                            </div>
                            <button type="button" class="button button-link" onclick="jQuery('#detail-<?php echo $row->ref_id; ?>').slideToggle()">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </button>
                        </div>
                        
                        <div class="inside" id="detail-<?php echo $row->ref_id; ?>" style="display:none; padding:0; border-top:1px solid #f0f0f1;">
                            <table class="widefat striped" style="border:none;">
                                <thead>
                                    <tr>
                                        <th style="padding-left:35px;">Item</th>
                                        <th class="tr">Qty</th>
                                        <th class="tr">Rate (Jual)</th>
                                        <th class="tr">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($items): foreach($items as $it): ?>
                                    <tr>
                                        <td style="padding-left:35px;">
                                            <strong><?php echo esc_html($it['sku']); ?></strong><br>
                                            <small><?php echo esc_html($it['name']); ?></small>
                                        </td>
                                        <td class="tr"><?php echo number_format($it['qty'], 2); ?></td>
                                        <td class="tr"><?php echo number_format($it['rate'], 0, ',', '.'); ?></td>
                                        <td class="tr"><?php echo number_format($it['qty'] * $it['rate'], 0, ',', '.'); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Simple Checkbox Logic
        $('#select-all-global').change(function() {
            $('.report-checkbox').prop('checked', $(this).prop('checked')).trigger('change');
        });
        $('.report-checkbox').change(function() {
            let count = $('.report-checkbox:checked').length;
            $('#counter-span').text(count + ' terpilih');
            $('#btn-process').prop('disabled', count === 0);
            if(!$(this).prop('checked')) $('#select-all-global').prop('checked', false);
        });
    });
    </script>
    <?php
}