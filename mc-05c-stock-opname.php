<?php
/**
 * MC-05C - STOCK OPNAME (ENTRY POOLING - TWO PANEL UX)
 * @version 7.6.0
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'puri-transaksi', 
        'Stock Opname', 
        '📋 Stock Opname', 
        'can_entry', 
        'puri-opname', 
        'puri_render_bulk_opname_page'
    );
});

function puri_render_bulk_opname_page() {
    global $wpdb;
    $user_id = get_current_user_id();
    $tbl_items = puri_table_name('T_ITEMS');
    $tbl_stock = puri_table_name('T_STOCK');

    // 1. HANDLER SUBMIT
    if (isset($_POST['puri_save_draft_opname'])) {
        $counts = $_POST['phys_counts'] ?? [];
        $pooling_data = [];
        $has_diff = false;

        foreach ($counts as $item_id => $phys_qty) {
            $sys_qty  = floatval($_POST['sys_qty'][$item_id]);
            $phys_qty = floatval($phys_qty);
            $diff_qty = $phys_qty - $sys_qty;

            if ($diff_qty != 0) {
                $has_diff = true;
                $pooling_data[$item_id] = [
                    'item_id' => $item_id,
                    'type'    => $_POST['type'][$item_id],
                    'sys'     => $sys_qty,
                    'phys'    => $phys_qty,
                    'diff'    => $diff_qty
                ];
            }
        }

        set_transient('puri_opname_pool_' . $user_id, [
            'status' => $has_diff ? 'DIFF' : 'MATCHED',
            'data'   => $pooling_data,
            'time'   => current_time('mysql')
        ], DAY_IN_SECONDS);

        echo "<div class='updated'><p>✅ Laporan EOD terkirim ke Supervisor.</p></div>";
    }

    $items = $wpdb->get_results($wpdb->prepare("
        SELECT i.id, i.name, i.sku, i.type, i.denom_value, COALESCE(s.qty, 0) as system_qty
        FROM {$tbl_items} i
        LEFT JOIN {$tbl_stock} s ON i.id = s.item_id AND s.location_id = %s
        WHERE i.type IN ('currency', 'package')
        ORDER BY i.type DESC, i.sku ASC
    ", 'laci_kasir'));

    ?>
	<style>
    /* 1. Container Utama: Gunakan max-width 100% agar tidak overflow */
    .puri-opname-container { 
        display: flex; 
        gap: 20px; 
        margin-top: 20px; 
        align-items: flex-start;
        max-width: 100%; /* Kunci agar tidak meluap ke kanan */
        box-sizing: border-box;
    }

    /* 2. Panel Utama (Tabel): Biarkan fleksibel mengambil sisa ruang */
    .panel-main { 
        flex: 1; /* Mengambil semua ruang sisa yang tersedia */
        min-width: 0; /* Penting agar flex-item bisa mengecil di dalam container */
    }

    /* 3. Panel Samping (Petunjuk): Lebar Tetap (Fixed width) */
    .panel-side { 
        flex: 0 0 320px; /* Lebar Sidebar petunjuk tetap 320px */
        position: sticky; 
        top: 50px; 
    }

    /* 4. Perbaikan Responsif untuk layar kecil */
    @media (max-width: 1100px) {
        .puri-opname-container { 
            flex-direction: column; /* Tumpuk ke bawah jika layar terlalu sempit */
        }
        .panel-side { 
            flex: 1 0 auto; 
            width: 100%; 
            min-width: 100%;
            position: static; 
        }
    }

    /* UI Styling */
    .puri-card { 
        background: #fff; 
        border: 1px solid #ccd0d4; 
        padding: 20px; 
        border-radius: 4px; 
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }
    
    /* Memastikan tabel tidak memaksa lebar keluar */
    .panel-main table {
        width: 100% !important;
        table-layout: fixed; /* Mencegah kolom meluap jika teks terlalu panjang */
    }
    
    .panel-main td { overflow: hidden; text-overflow: ellipsis; }


    /*
    .puri-opname-container { display: flex; gap: 20px; margin-top: 20px; align-items: flex-start; max-width: 99%; }
        .panel-main { flex: 0 0 75%; }
        .panel-side { flex: 0 0 25%; min-width: 350px; position: sticky; top: 50px; }
        .puri-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
    */
		.badge-pkg { background: #0073aa; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
        .badge-cur { background: #f0f0f1; color: #3c434a; padding: 2px 6px; border-radius: 3px; font-size: 10px; border: 1px solid #dcdcde; }
        .help-title { border-bottom: 2px solid #ffb900; padding-bottom: 10px; margin-bottom: 15px; font-weight: bold; }
        .help-list { padding-left: 18px; line-height: 1.6; }
        .help-list li { margin-bottom: 10px; }
        .alert-box { background: #fff8e5; border-left: 4px solid #ffb900; padding: 10px; font-size: 13px; margin-top: 15px; }
    </style>

    <div class="wrap">
        <h1>📋 Stock Opname Fisik</h1>
        <div class="puri-opname-wrapper">
			<div class="puri-opname-container">
				<div class="panel-main">
					<form method="post" class="puri-card">
						<table class="widefat striped">
							<thead>
								<tr>
									<th width="45%">Items</th>
									<th width="15%">Tipe</th>
									<th width="15%" style="text-align:right;">Stok Buku</th>
									<th width="25%">Fisik</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($items as $it): ?>
								<tr>
									<td>
										<strong><?php echo esc_html($it->name); ?></strong><br>
										<small class="description">Ref: <?php echo $it->sku; ?></small>
									</td>
									<td>
										<?php if($it->type == 'package'): ?>
											<span class="badge-pkg">PACKAGE</span>
										<?php else: ?>
											<span class="badge-cur">CURRENCY</span>
										<?php endif; ?>
									</td>
									<td align="right"><code><?php echo number_format($it->system_qty, 2); ?></code></td>
									<td>
										<input type="hidden" name="sys_qty[<?php echo $it->id; ?>]" value="<?php echo $it->system_qty; ?>">
										<input type="hidden" name="type[<?php echo $it->id; ?>]" value="<?php echo $it->type; ?>">
										<input type="hidden" name="sku[<?php echo $it->id; ?>]" value="<?php echo $it->sku; ?>">
										<input type="hidden" name="name[<?php echo $it->id; ?>]" value="<?php echo $it->name; ?>">
										
										<input type="number" step="0.01" name="phys_counts[<?php echo $it->id; ?>]" 
											   style="width:100%; text-align:right; font-weight:bold; font-size: 14px; padding: 5px;" 
											   placeholder="0" required>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<div style="margin-top:20px; text-align:right;">
							<button type="submit" name="puri_save_draft_opname" class="button button-primary button-large">📤 Kirim Laporan EOD</button>
						</div>
					</form>
				</div>

				<div class="panel-side">
					<div class="puri-card">
						<div class="help-title">💡 Petunjuk Penghitungan</div>
						<ul class="help-list">
							<li><strong>Item Valas (Currency):</strong> Hitung lembaran fisik satu per satu. Pastikan denominasi sesuai dengan baris item.</li>
							<li><strong>Item Paket (Bundling):</strong> Cukup hitung jumlah <strong>Amplop/Bundle</strong> yang masih utuh dan tersegel.</li>
							<li><strong>Akurasi:</strong> Jika ada selisih, sistem akan mendeteksi secara otomatis saat Anda klik submit.</li>
						</ul>

						<div class="alert-box">
							<strong>⚠️ Implikasi Selisih:</strong>
							<p>Laporan Anda akan ditandai <b>"MATCHED"</b> jika sesuai, atau <b>"DIFF"</b> jika ada selisih.</p>
							<p>Setiap selisih akan memicu investigasi oleh Finance dan akan dicatat sebagai beban/pendapatan operasional setelah dikonfirmasi.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
    </div>
    <?php
}