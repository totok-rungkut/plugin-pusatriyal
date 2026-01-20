<?php
/**
 * =============================================================================
 * MC-28 - PURI GL MAPPING & POSTING PROFILE
 * =============================================================================
 * @package     Pusat Riyal Smart Suite
 * @version     7.3.14 (Stable Hybrid)
 * @author      Denmas Totok | Mozart Engine Team
 * @description Jembatan orkestrasi antara transaksi fisik (POS/Procure) dengan 
 * General Ledger. Mengatur aliran otomatis Debet/Kredit.
 * =============================================================================
 */

defined('ABSPATH') || exit;

/**
 * 1. GLOBAL ACCESSOR: puri_gl()
 * Digunakan oleh Mozart Engine (MC-03) untuk mengambil kode akun[cite: 841].
 */
if (!function_exists('puri_gl')) {
    function puri_gl($key) {
        $map = get_option('puri_gl_settings', []);
        $defaults = [
            'purchase_cost'     => '5101', // Biaya Pembelian [cite: 808]
            'sales_retail'      => '4101', // Pendapatan Penjualan [cite: 823]
            'inventory'         => '1401', // Persediaan Barang [cite: 811]
            'cogs'              => '5100', // Harga Pokok Penjualan [cite: 812]
            'cash_drawer'       => '1101', // Kas Laci [cite: 741]
            'default_bank'      => '1102', // Bank Utama
            'suspense'          => '1999', // Selisih Opname [cite: 625]
            'ar_trade'          => '1201', // Piutang Usaha
            'ap_trade'          => '2101', // Hutang Usaha
            'inventory_transit' => '1402', // Stok Perjalanan
            'consignment'       => '2102', // Hutang Konsinyasi
        ];
        return $map[$key] ?: ($defaults[$key] ?? '0000');
    }
}

/**
 * 2. POST HANDLER
 */
add_action('admin_post_puri_save_gl_map', function() {
    check_admin_referer('puri_save_gl_map', 'puri_nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $clean_data = array_map('sanitize_text_field', $_POST['map'] ?? []);
    update_option('puri_gl_settings', $clean_data);
    wp_redirect(admin_url('admin.php?page=puri-gl-mapping&msg=1'));
    exit;
});

/**
 * 3. UI RENDERER
 */
function puri_render_gl_mapping_page() {
    global $wpdb;
    $charts  = $wpdb->get_results("SELECT code, name, type FROM " . puri_table_name('T_CHART') . " ORDER BY code ASC");
    $current = get_option('puri_gl_settings', []);

    // Helper: Render Select Row
    $row = function($key, $label) use ($charts, $current) {
        $val = $current[$key] ?? '';
        $options = array_map(fn($c) => 
            "<option value='{$c->code}' ".selected($val, $c->code, false).">{$c->code} - {$c->name} ({$c->type})</option>", 
            $charts
        );
        printf(
            '<tr><td><label>%s</label></td><td><select name="map[%s]" class="puri-select" data-key="%s"><option value="">-- Pilih Akun --</option>%s</select></td></tr>',
            $label, $key, $key, implode('', $options)
        );
    };
    ?>
	
    <style>
        :root { --soft-bg: #f8fafc; --border-clr: #e2e8f0; --accent: #2563eb; --accentgr: #15c037; --text-main: #1e293b; }
        .gl-wrapper { display: flex; gap: 24px; color: var(--text-main); font-size: 13px; margin-top: 20px; }
        .panel-left { flex: 0 0 73%; }
        .panel-right { flex: 0 0 25%; position: sticky; top: 40px; height: fit-content; }
        .gl-card { background: #fff; border: 1px solid var(--border-clr); border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .gl-card h3 { margin: 0 0 15px; font-size: 15px; display: flex; align-items: center; gap: 8px; color: var(--accent); border-bottom: 1px solid var(--border-clr); padding-bottom: 10px; }
        .form-table-gl { width: 100%; border-collapse: collapse; }
        .form-table-gl td { padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .form-table-gl label { font-weight: 500; color: #475569; }
        .puri-select { width: 100%; border: 1px solid var(--border-clr); border-radius: 4px; padding: 5px; }
        .puri-select:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
        .info-box { background: #eff6ff; border-left: 4px solid var(--accent); padding: 15px; border-radius: 4px; }
        .info-box-tips { background: #effff6; border-left: 4px solid var(--accentgr); padding: 15px; border-radius: 4px; margin-top: 15px; }
        .tooltip-box { background: #fff; border: 1px solid #fed7aa; border-left: 4px solid #f97316; padding: 15px; margin-top: 15px; min-height: 100px; }
        .btn-save { background: var(--accent); color: white; border: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; }
		.header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-create-coa { background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .btn-create-coa:hover { background: #059669; }
        
        /* Modal Style dari MC-23 */
        .puri-modal { position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; }
        .hidden { display:none !important; }
        .puri-modal-content { background:#fff; padding:25px; border-radius:10px; width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2); position: relative; }
        .close-modal { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #94a3b8; }
        .form-group { margin-bottom: 15px; }
        .full-width { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; }
    </style>

<div class="wrap">
        <div class="header-flex">
            <h1>⚙️ GL Mapping Configuration v7.3.15</h1>
            <button type="button" class="btn-create-coa" onclick="document.getElementById('modal_quick_coa').classList.remove('hidden')">
                <span class="dashicons dashicons-plus-alt" style="margin-top:2px;"></span> Create CoA
            </button>
        </div>
        
        <form method="post" action="<?= admin_url('admin-post.php') ?>">
            <input type="hidden" name="action" value="puri_save_gl_map">
            <?php wp_nonce_field('puri_save_gl_map', 'puri_nonce'); ?>

            <div class="gl-wrapper">
                <div class="panel-left">
                    <div class="gl-card">
                        <h3>📦 Procurement (Kulakan)</h3>
                        <table class="form-table-gl"><?php $row('default_bank', 'Bank Utama'); $row('ap_trade', 'Akun Hutang Usaha'); ?></table>
                    </div>

                    <div class="gl-card">
                        <h3>💰 Penjualan POS</h3>
                        <table class="form-table-gl"><?php 
							$row('cash_drawer', 'Kas Masuk Tunai'); 
							$row('ar_trade', 'Akun Piutang Usaha'); 
							$row('sales_retail', 'Pendapatan Penjualan'); 
							$row('consignment', 'Akun Konsinyasi'); ?>
						</table>
                    </div>

                    <div class="gl-card">
                        <h3>📋 Persediaan & Inventory</h3>
                        <table class="form-table-gl">
                            <?php 
                            $row('inventory', 'Persediaan Barang'); 
							$row('cogs', 'HPP'); 
                            $row('purchase_cost', 'Biaya Pembelian');
							$row('Inventory_transit', 'Barang dalam perjalanan'); 
                            ?>
                        </table>
                    </div>
                    <button type="submit" class="btn-save">Simpan Konfigurasi</button>
                </div>

                <div class="panel-right">
					<div class="info-box">
                        <strong>Apa itu Mapping GL?</strong>
                        <p>Fitur ini adalah jantung otomatisasi akuntansi. Setiap kali Kasir melakukan transaksi, sistem akan merujuk ke halaman ini untuk menentukan akun mana yang akan di-debet atau di-kredit secara otomatis.</p>
                        <p>Pastikan pemetaan akun sudah benar untuk menghasilkan Laporan Laba Rugi dan Neraca yang akurat secara real-time.</p>
                    </div>
					<div class="info-box-tips">
                        <strong>Tips Efisiensi:</strong>
                        <p>Jika akun yang Anda cari tidak ada di daftar, klik tombol <b>Create CoA</b> di atas untuk menambahkannya secara instan tanpa meninggalkan halaman ini.</p>
                    </div>
                    <div class="tooltip-box" id="gl-tooltip">
						<i>Arahkan kursor pada akun untuk bantuan.</i>
					</div>
                </div>
            </div>
        </form>
    </div>

    <div id="modal_quick_coa" class="puri-modal hidden">
        <div class="puri-modal-content">
            <span class="close-modal" onclick="document.getElementById('modal_quick_coa').classList.add('hidden')">&times;</span>
            <h2 style="margin-top:0;">📂 Tambah Akun Baru</h2>
            <form method="post" action="<?= admin_url('admin-post.php') ?>">
                <input type="hidden" name="action" value="puri_save_coa_pro">
                <?php wp_nonce_field('puri_coa_save', 'puri_nonce'); ?>
                <input type="hidden" name="mode" value="create">

                <div class="form-group">
                    <label>Kode Akun</label>
                    <input type="text" name="code" class="full-width" required placeholder="Cth: 5101">
                </div>
                <div class="form-group">
                    <label>Nama Akun</label>
                    <input type="text" name="name" class="full-width" required placeholder="Cth: Biaya Listrik">
                </div>
                <div class="form-group">
                    <label>Tipe Akun</label>
                    <select name="type" class="full-width">
                        <option value="ASSET">ASSET</option>
                        <option value="LIABILITY">LIABILITY</option>
                        <option value="EQUITY">EQUITY</option>
                        <option value="REVENUE">REVENUE</option>
                        <option value="EXPENSE">EXPENSE</option>
                    </select>
                </div>
                <div style="text-align:right; margin-top:20px;">
                    <button type="submit" class="btn-save" style="background:#10b981;">Simpan Akun</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        /**
         * ES6 GL Mapping Controller
         */
        class GLMappingController {
            constructor() {
                this.tooltips = {
                    default_bank: "Akun bank utama yang akan digunakan untuk pembayaran otomatis saat kulakan barang (Procurement).",
                    ap_trade: "Akun untuk mencatat kewajiban atau hutang kepada vendor jika pembelian dilakukan tidak secara tunai.",
                    cash_drawer: "Tempat penyimpanan uang fisik hasil penjualan di laci kasir sebelum disetor ke bank.",
                    ar_trade: "Akun untuk mencatat tagihan kepada pelanggan yang membeli barang namun belum membayar lunas.",
                    consignment: "Akun khusus untuk mencatat nilai barang milik mitra yang dititipkan di gerai kita.",
                    inventory: "Nilai aset barang valas/barang dagangan yang saat ini tersedia dan siap dijual.",
                    inventory_transit: "Mencatat nilai barang yang sudah dibayar dan keluar dari gudang lawan, namun belum sampai di tangan kita.",
                    cogs: "Harga Pokok Penjualan: Akun untuk mencatat harga modal dari barang yang berhasil terjual.",
                    suspense: "Akun penampung sementara jika ditemukan selisih antara jumlah fisik di laci dengan catatan di sistem.",
                    purchase_cost: "Akun beban yang mencatat seluruh biaya pembelian/kulakan. Saldo di sini akan mengurangi kas saat transaksi kulakan dieksekusi.",
                    sales_retail: "Akun pendapatan utama yang mencatat seluruh nilai omzet dari transaksi penjualan eceran di POS."
                };
                this.init();
            }

            init() {
                const selects = document.querySelectorAll('.puri-select');
                const tooltipContainer = document.getElementById('gl-tooltip');

                selects.forEach(select => {
                    const updateTooltip = () => {
                        const key = select.dataset.key;
                        tooltipContainer.innerHTML = `<strong>${select.previousElementSibling?.innerText || 'Info Akun'}:</strong><p>${this.tooltips[key] || 'Tidak ada penjelasan tersedia.'}</p>`;
                    };

                    select.addEventListener('focus', updateTooltip);
                    select.addEventListener('mouseenter', updateTooltip);
                });
            }
        }

        document.addEventListener('DOMContentLoaded', () => new GLMappingController());


/**
 * Sort option <select> by account code (numeric aware)
 */
function sortSelectByCode(selectEl) {
    const options = Array.from(selectEl.options);

    const placeholder = options.shift(); // "-- Pilih Akun --"

    options.sort((a, b) => {
        const codeA = parseInt(a.value, 10);
        const codeB = parseInt(b.value, 10);
        return codeA - codeB;
    });

    selectEl.innerHTML = '';
    selectEl.appendChild(placeholder);
    options.forEach(opt => selectEl.appendChild(opt));
}


		
		/**
 * Quick CoA Modal AJAX Injector
 * Tanpa ubah UX / markup
 */
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('modal_quick_coa');
    const form  = modal.querySelector('form');

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';

        const fd = new FormData(form);
        fd.append('action', 'puri_ajax_create_coa');
        fd.append('nonce', form.querySelector('[name="puri_nonce"]').value);

        fetch(ajaxurl, {
            method: 'POST',
            body: fd
        })
        .then(res => res.json())
        .then(json => {
            if (!json.success) {
                alert(json.data?.msg || 'Gagal menyimpan akun');
                return;
            }

            const { code, name, type } = json.data;
            const optionHTML = `<option value="${code}">${code} - ${name} (${type})</option>`;

            // Inject ke semua select mapping
            document.querySelectorAll('.puri-select').forEach(sel => {
                sel.insertAdjacentHTML('beforeend', optionHTML);
            });

            // Tutup modal & reset
            modal.classList.add('hidden');
            form.reset();
        })
        .catch(() => alert('AJAX error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerText = 'Simpan Akun';
        });
    });
});
		
		
		
    </script>
    <?php
}



/**
 * 4. AJAX HANDLER — Quick Create CoA (no reload)
 */
add_action('wp_ajax_puri_ajax_create_coa', function () {
    check_ajax_referer('puri_coa_save', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['msg' => 'Unauthorized']);
    }

    global $wpdb;

    $code = sanitize_text_field($_POST['code'] ?? '');
    $name = sanitize_text_field($_POST['name'] ?? '');
    $type = sanitize_text_field($_POST['type'] ?? '');

    if (!$code || !$name || !$type) {
        wp_send_json_error(['msg' => 'Data tidak lengkap']);
    }

    $table = puri_table_name('T_CHART');

    // Cegah duplikasi kode
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE code = %s",
        $code
    ));
    if ($exists) {
        wp_send_json_error(['msg' => 'Kode akun sudah ada']);
    }

    $wpdb->insert($table, [
        'code' => $code,
        'name' => $name,
        'type' => $type
    ]);

    wp_send_json_success([
        'code' => $code,
        'name' => $name,
        'type' => $type
    ]);
});


