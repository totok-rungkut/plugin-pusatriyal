<?php
/**
 * MC 26 - Stock Opname & Adjustment (Perpetual System)
 * Version: 2.0.0 (Sesuai SOP Internal Control)
 * Author: Denmas Totok (Refactor by Gemini)
 *
 * Purpose:
 * - Mencatat hasil Opname Harian.
 * - PERPETUAL: Selisih langsung update stok & jurnal keuangan saat itu juga.
 * - Menggunakan akun perantara "Selisih Sementara" (Suspense Account).
 *
 * Logic Flow:
 * 1. User Input Fisik -> Sistem Hitung Selisih (Diff).
 * 2. Update Stok DB (Atomic).
 * 3. Catat Ledger (Kartu Stok).
 * 4. Catat Jurnal Keuangan (Tahap A):
 * - Jika Kurang: Dr. Selisih Sementara / Cr. Persediaan
 * - Jika Lebih:  Dr. Persediaan / Cr. Selisih Sementara
 */

defined('ABSPATH') || exit;

// Registrasi Menu
/*
add_action('admin_menu', function() {
    add_submenu_page(
        'puri-transaksi',
        'Stock Opname',
        '⚖️ Stock Opname',
        'puri_can_rekonsiliasi', // Capability khusus (Finance/Spv)
        'puri-stock-adj',
        'puri_render_stock_adjustment_page'
    );
});
*/
// Handler Submit
add_action('admin_post_puri_submit_adjustment', 'puri_handle_adjustment_submit');

function puri_render_stock_adjustment_page() {
    // Security Gate
    if (!current_user_can('puri_can_rekonsiliasi') && !current_user_can('manage_options')) {
        wp_die('<div class="notice notice-error"><p>⛔ Akses Ditolak. Hubungi Administrator.</p></div>');
    }

    global $wpdb;
    
    // 1. Ambil Data Item (Hanya Tipe Valas)
    $items = $wpdb->get_results("
        SELECT id, sku, name, denom_value 
        FROM " . puri_table_name('T_ITEMS') . " 
        WHERE type = 'currency' 
        ORDER BY denom_value ASC
    ");
    
    // 2. Ambil Akun Penampung (Selisih Sementara)
    // Filter akun yg relevan (Biasanya kode kepala 19xx atau 6xxx tergantung COA)
    $adj_accounts = $wpdb->get_results("
        SELECT code, name, type 
        FROM " . puri_table_name('T_CHART') . " 
        ORDER BY code ASC
    ");

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">⚖️ Stock Opname Harian (Perpetual)</h1>
        <hr class="wp-header-end">
        
        <?php if (isset($_GET['msg'])): ?>
            <div class="notice notice-success is-dismissible"><p>✅ <?php echo esc_html(urldecode($_GET['msg'])); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="notice notice-error is-dismissible"><p>❌ <?php echo esc_html(urldecode($_GET['err'])); ?></p></div>
        <?php endif; ?>

        <div style="display:flex; gap:20px; margin-top:20px;">
            <div class="card" style="flex:1; max-width:500px; padding:0; overflow:hidden;">
                <div style="background:#f0f0f1; padding:15px; border-bottom:1px solid #c3c4c7;">
                    <strong>Form Hasil Perhitungan Fisik</strong>
                </div>
                <div style="padding:15px;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="puri_submit_adjustment">
                        <?php wp_nonce_field('puri_adj_action', 'puri_adj_nonce'); ?>

                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th style="width:120px;">Lokasi Opname</th>
                                <td>
                                    <select name="location_id" id="loc_select" style="width:100%" class="puri-select2">
                                        <option value="laci_kasir">Laci Kasir (Front Office)</option>
                                        <option value="gudang_utama">Gudang Utama (Vault)</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th>Item Valas</th>
                                <td>
                                    <select name="item_id" id="item_select" style="width:100%" class="puri-select2" required>
                                        <option value="">-- Pilih Item --</option>
                                        <?php foreach($items as $it): ?>
                                            <option value="<?php echo $it->id; ?>">
                                                <?php echo esc_html($it->sku . ' - ' . $it->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr style="background:#e6f7ff;">
                                <th>Stok Buku (Sistem)</th>
                                <td>
                                    <input type="text" id="sys_stock_view" value="-" readonly 
                                           style="background:transparent; border:none; font-weight:900; color:#0073aa; font-size:16px;">
                                    <input type="hidden" name="system_qty" id="sys_stock_val">
                                    <p class="description">Posisi stok per detik ini.</p>
                                </td>
                            </tr>

                            <tr style="background:#fffbe6;">
                                <th>Stok Fisik (Riil)</th>
                                <td>
                                    <input type="number" name="real_qty" id="real_qty_input" class="regular-text" required placeholder="0" 
                                           style="font-size:16px; font-weight:bold; border-color:#f59e0b;">
                                    <p class="description">Masukkan jumlah lembar fisik.</p>
                                </td>
                            </tr>

                            <tr>
                                <th>Selisih (Adj)</th>
                                <td>
                                    <strong id="diff_view" style="font-size:18px;">0</strong>
                                    <input type="hidden" name="diff_qty" id="diff_val">
                                </td>
                            </tr>

                            <tr>
                                <th>Akun Penampung</th>
                                <td>
                                    <select name="adj_account" style="width:100%" class="puri-select2" required>
                                        <option value="">-- Pilih Akun Selisih Sementara --</option>
                                        <?php foreach($adj_accounts as $ac): ?>
                                            <option value="<?php echo $ac->code; ?>">
                                                <?php echo esc_html($ac->code . ' - ' . $ac->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Saran: Gunakan akun <strong>"Selisih Sementara Kas & Valuta"</strong>.</p>
                                </td>
                            </tr>

                            <tr>
                                <th>Catatan</th>
                                <td>
                                    <textarea name="description" class="large-text" rows="2" 
                                              placeholder="Contoh: Opname Harian tgl 9 Jan, Shift Pagi."></textarea>
                                </td>
                            </tr>
                        </table>

                        <hr>
                        <button type="submit" class="button button-primary button-hero" style="width:100%;" 
                                onclick="return confirm('KONFIRMASI: Stok sistem akan langsung berubah mengikuti fisik. Lanjutkan?')">
                            SIMPAN HASIL OPNAME
                        </button>
                    </form>
                </div>
            </div>

            <div style="flex:1; color:#50575e;">
                <div class="card" style="padding:20px;">
                    <h3>ℹ️ SOP Opname Harian (Perpetual)</h3>
                    <p>Sistem ini menggunakan metode Perpetual Adjustment.</p>
                    
                    <ol>
                        <li><strong>Input Hasil Fisik</strong><br>
                            Masukkan jumlah lembar yang ada di laci. Sistem akan menghitung selisih otomatis.
                        </li>
                        <li><strong>Pilih Akun Penampung</strong><br>
                            Selalu gunakan akun <em>Selisih Sementara</em>. Jangan langsung menembak ke Beban/Pendapatan sebelum investigasi selesai.
                        </li>
                        <li><strong>Jurnal Otomatis (Tahap A)</strong><br>
                            <ul>
                                <li>Jika <strong>KURANG</strong>: <br><code>(Dr) Selisih Sementara</code> <br><code>(Cr) Persediaan Valas</code></li>
                                <li>Jika <strong>LEBIH</strong>: <br><code>(Dr) Persediaan Valas</code> <br><code>(Cr) Selisih Sementara</code></li>
                            </ul>
                        </li>
                        <li><strong>Investigasi (Tahap B)</strong><br>
                            Lakukan investigasi selisih. Setelah ketemu penyebabnya, buat <strong>Jurnal Umum (MC-11)</strong> untuk membalik akun Selisih Sementara ke akun yang benar (Beban Karyawan / System Error / Pendapatan).
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.puri-select2').select2();

        // Ambil stok saat item/lokasi berubah
        $('#item_select, #loc_select').on('change', function() {
            var itemId = $('#item_select').val();
            var locId = $('#loc_select').val();
            
            if(!itemId) return;

            // Reset UI
            $('#sys_stock_view').val('Loading...');
            $('#real_qty_input').val('');
            $('#diff_view').text('0');

            $.post(ajaxurl, {
                action: 'puri_get_current_stock_ajax',
                item_id: itemId,
                location: locId
            }, function(res) {
                if(res.success) {
                    $('#sys_stock_view').val(res.data.qty);
                    $('#sys_stock_val').val(res.data.qty);
                    calcDiff();
                } else {
                    alert('Gagal mengambil stok.');
                }
            });
        });

        // Hitung Selisih Realtime
        $('#real_qty_input').on('keyup change', calcDiff);

        function calcDiff() {
            var sys = parseFloat($('#sys_stock_val').val()) || 0;
            var real = parseFloat($('#real_qty_input').val());
            
            if(isNaN(real)) {
                $('#diff_view').text('0');
                $('#diff_val').val(0);
                return;
            }

            var diff = real - sys;
            $('#diff_val').val(diff);
            
            var diffText = diff > 0 ? '+' + diff : diff;
            var color = diff === 0 ? '#10b981' : (diff > 0 ? '#3b82f6' : '#ef4444'); // Hijau (Pas), Biru (Lebih), Merah (Kurang)
            
            $('#diff_view').text(diffText + ' lembar').css('color', color);
        }
    });
    </script>
    <?php
}

// Handler AJAX Get Stock (Ringan)
add_action('wp_ajax_puri_get_current_stock_ajax', function() {
    global $wpdb;
    $item_id = intval($_POST['item_id']);
    $loc_id = sanitize_text_field($_POST['location']);
    
    // Ambil Stok Langsung dari Tabel Saldo
    $qty = $wpdb->get_var($wpdb->prepare(
        "SELECT qty FROM ".puri_table_name('T_STOCK')." WHERE item_id=%d AND location_id=%s", 
        $item_id, $loc_id
    ));
    
    wp_send_json_success(['qty' => floatval($qty)]);
});

// Handler PROSES ADJUSTMENT
function puri_handle_adjustment_submit() {
    // 1. Security Check
    if (!current_user_can('puri_can_rekonsiliasi') && !current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_adj_action', 'puri_adj_nonce');

    global $wpdb;
    $engine = puri_engine();

    $loc_id = sanitize_text_field($_POST['location_id']);
    $item_id = intval($_POST['item_id']);
    $real_qty = floatval($_POST['real_qty']);
    $adj_acc = sanitize_text_field($_POST['adj_account']);
    $desc_user = sanitize_textarea_field($_POST['description']);
    $user_id = get_current_user_id();

    if ($item_id <= 0 || !$adj_acc) {
        wp_redirect(admin_url('admin.php?page=puri-stock-adj&err='.urlencode('Data item atau akun belum dipilih.'))); 
        exit;
    }

    $wpdb->query('START TRANSACTION');
    try {
        // 2. LOCK & Validasi Stok Terkini
        $curr_stock = $wpdb->get_var($wpdb->prepare(
            "SELECT qty FROM ".puri_table_name('T_STOCK')." WHERE item_id=%d AND location_id=%s FOR UPDATE", 
            $item_id, $loc_id
        ));
        $curr_stock = floatval($curr_stock);

        // 3. Hitung Diff (Adjustment)
        // Rumus: Real - Sistem = Adjustment
        // Contoh Kurang: Real 95 - Sistem 100 = -5 (Sistem harus dikurangi 5)
        $diff = $real_qty - $curr_stock;

        if ($diff == 0) {
            throw new Exception("Stok fisik sama dengan sistem. Tidak ada jurnal yang dibuat.");
        }

        // 4. Update Stok via Engine (Atomic)
        $res = $engine->update_stock_atomic($loc_id, $item_id, $diff);
        if (is_wp_error($res)) throw new Exception($res->get_error_message());

        // 5. Catat Ledger (Fisik)
        $ref_id = 'ADJ-' . date('ymdHi') . rand(100,999);
        
        $wpdb->insert(puri_table_name('T_LEDGER'), [
            'trx_date' => current_time('mysql'),
            'location_id' => $loc_id,
            'item_id' => $item_id,
            'qty_change' => $diff,
            'ref_id' => $ref_id,
            'description' => "Opname: $desc_user"
        ]);

        // 6. Hitung Valuasi Rupiah (Perpetual Valuation)
        $item_data = $engine->get_item($item_id);
        
        // Prioritas HPP (Base Price). Jika 0, pakai Harga Jual (Fallback)
        $hpp = floatval($item_data->base_price);
        if ($hpp <= 0) $hpp = floatval($item_data->sell_rate);
        
        $denom = intval($item_data->denom_value);
        $total_adj_value = abs($diff * $denom * $hpp); // Nilai Rupiah Mutlak

        // 7. Jurnal Akuntansi (Double Entry) - TAHAP A
        // Akun Persediaan default: 1401 (Sesuaikan dengan CoA Anda)
        $inventory_acc = '1401'; 

        if ($diff < 0) {
            // --- KASUS KURANG (LOSS) ---
            // Dr. Selisih Sementara (Penampung)
            // Cr. Persediaan Valas (Aset Berkurang)
            $engine->post_journal($ref_id, $adj_acc, $total_adj_value, 0, "Adj Shortage: $item_data->sku ($diff) - $desc_user");
            $engine->post_journal($ref_id, $inventory_acc, 0, $total_adj_value, "Adj Shortage Inventory: $item_data->sku");
            
        } else {
            // --- KASUS LEBIH (SURPLUS) ---
            // Dr. Persediaan Valas (Aset Bertambah)
            // Cr. Selisih Sementara (Penampung)
            $engine->post_journal($ref_id, $inventory_acc, $total_adj_value, 0, "Adj Surplus Inventory: $item_data->sku");
            $engine->post_journal($ref_id, $adj_acc, 0, $total_adj_value, "Adj Surplus: $item_data->sku (+$diff) - $desc_user");
        }

        $wpdb->query('COMMIT');
        
        // Notifikasi Sukses
        $msg = "Sukses! Opname tercatat. Ref: $ref_id. Stok disesuaikan " . ($diff>0?'+':'') . "$diff lembar. Nilai Adj: Rp " . number_format($total_adj_value);
        wp_redirect(admin_url('admin.php?page=puri-stock-adj&msg='.urlencode($msg)));
        exit;

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_redirect(admin_url('admin.php?page=puri-stock-adj&err='.urlencode($e->getMessage())));
        exit;
    }
}