<?php
/**
 * =============================================================================
 * MC 23 - Chart of Accounts (CoA) Manager - PRO UI (Refactor & Hardened)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     6.6.2-refactor
 * @author      Denmas Totok (Refactor by Gemini)
 *
 * Catatan perubahan:
 * - Validasi server-side lebih ketat untuk mencegah insert ganda saat edit.
 * - Mendukung update berdasarkan numeric `id` jika kolom tersedia; fallback ke `code`.
 * - Memeriksa hasil update dan melempar error jika tidak ada baris terpengaruh.
 * - Menambahkan logging debug sementara untuk membantu tracing POST payload.
 * =============================================================================
 */

defined('ABSPATH') || exit;

// 1. MENU & HOOKS (tetap sama)
add_action('admin_menu', function() {
    if (!has_action('admin_menu', 'puri_render_coa_manager_page')) {
        add_submenu_page('puri-transaksi', 'Chart of Accounts', '📂 CoA Manager', 'manage_options', 'puri-coa', 'puri_render_coa_manager_page');
    }
});

add_action('admin_post_puri_save_coa_pro', 'puri_handle_coa_save_pro');
add_action('admin_post_puri_delete_coa_pro', 'puri_handle_coa_delete_pro');

// 2. RENDER PAGE (UI hampir sama, ditambah hidden id)
function puri_render_coa_manager_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    global $wpdb;
    $table_chart = puri_table_name('T_CHART');

// --- DEBUG START ---
echo "<div class='notice notice-warning is-dismissible'>
    <p>🔍 <strong>DEBUG MODE:</strong> Script sedang membaca tabel: <code>{$table_chart}</code></p>
</div>";
// --- DEBUG END ---

    // Ambil Data
    $charts = $wpdb->get_results("SELECT * FROM $table_chart ORDER BY code ASC");

    // Data untuk JS
    $existing_codes = [];
    if ($charts) {
        $existing_codes = wp_list_pluck($charts, 'code');
    }
    ?>

    <div class="wrap puri-pro-ui">
        <h1 class="wp-heading-inline">📂 Chart of Accounts</h1>
        <button class="page-title-action btn-new-account" onclick="openModal('create')">Tambah Akun Baru</button>
        <hr class="wp-header-end">

        <?php if (isset($_GET['msg'])): ?>
            <div class="notice notice-success is-dismissible"><p>✅ <?php echo esc_html(urldecode($_GET['msg'])); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="notice notice-error is-dismissible"><p>⚠️ <?php echo esc_html(urldecode($_GET['err'])); ?></p></div>
        <?php endif; ?>

        <div class="card coa-card">
            <table class="coa-table">
                <thead>
                    <tr>
                        <th width="15%">Kode</th>
                        <th width="40%">Nama Akun</th>
                        <th width="20%">Tipe</th>
                        <th width="10%">Kas/Bank</th>
                        <th width="15%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($charts)) : ?>
                        <tr><td colspan="5" class="empty-state">Belum ada akun. Klik 'Tambah Akun Baru'.</td></tr>
                    <?php else : foreach($charts as $c):
                        // Badge CSS Class logic
                        $badge_cls = 'badge-default';
                        if (strpos($c->type, 'ASSET') !== false) $badge_cls = 'badge-asset';
                        elseif (strpos($c->type, 'LIABILITY') !== false) $badge_cls = 'badge-liability';
                        elseif (strpos($c->type, 'EQUITY') !== false) $badge_cls = 'badge-equity';
                        elseif (strpos($c->type, 'REVENUE') !== false) $badge_cls = 'badge-revenue';
                        elseif (strpos($c->type, 'EXPENSE') !== false) $badge_cls = 'badge-expense';
                    ?>
                        <tr class="coa-row"
                            data-code="<?php echo esc_attr($c->code); ?>"
                            data-name="<?php echo esc_attr($c->name); ?>"
                            data-type="<?php echo esc_attr($c->type); ?>"
                            data-cash="<?php echo esc_attr($c->is_cash); ?>"
                            <?php if (isset($c->id)) : ?> data-id="<?php echo esc_attr($c->id); ?>" <?php endif; ?>>
                            
                            <td class="col-code"><?php echo esc_html($c->code); ?></td>
                            <td class="col-name">
                                <span class="fw-bold"><?php echo esc_html($c->name); ?></span>
                            </td>
                            <td><span class="badge <?php echo $badge_cls; ?>"><?php echo esc_html($c->type); ?></span></td>
                            <td><?php echo $c->is_cash ? '<span class="dashicons dashicons-yes text-green"></span>' : ''; ?></td>

                            <td class="col-actions">
                                <div class="action-group">
                                    <button type="button" class="btn-icon" title="Edit" onclick="openModal('edit', this)">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                    <button type="button" class="btn-icon" title="Duplicate" onclick="openModal('duplicate', this)">
                                        <span class="dashicons dashicons-images-alt2"></span>
                                    </button>
                                    <button type="button" class="btn-icon btn-delete" title="Hapus" onclick="openDeleteModal(this)">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modal_form" class="puri-modal hidden">
        <div class="puri-modal-content">
            <span class="close-modal" onclick="closeModal('modal_form')">&times;</span>
            <h2 id="modal_title">Tambah Akun</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="puri_save_coa_pro">
                <?php wp_nonce_field('puri_coa_save', 'puri_nonce'); ?>

                <input type="hidden" name="mode" id="form_mode" value="create">
                <input type="hidden" name="old_code" id="inp_old_code" value="">
                <input type="hidden" name="id" id="inp_id" value="">

                <div class="form-group">
                    <label>Kode Akun</label>
                    <input type="text" name="code" id="inp_code" class="full-width highlight-input" required placeholder="Cth: 5100">
                    <small id="code_hint" class="hint-text">Unik, tidak boleh ganda.</small>
                </div>

                <div id="box_cascade_update" class="notice notice-warning inline hidden" style="margin-bottom:15px; padding:10px; border-left-color:#f59e0b;">
                    <label style="font-weight:600; color:#b45309;">
                        <input type="checkbox" name="update_journal" value="1" checked>
                        Update Riwayat Jurnal (Cascade)
                    </label>
                    <p style="font-size:11px; margin:5px 0 0 0; color:#666;">
                        <i class="dashicons dashicons-warning" style="font-size:14px; vertical-align:middle;"></i>
                        Kode lama akan diganti menjadi kode baru di semua jurnal transaksi yang sudah ada.
                    </p>
                </div>

                <div class="form-group">
                    <label>Nama Akun</label>
                    <input type="text" name="name" id="inp_name" class="full-width" required placeholder="Cth: Biaya Operasional">
                </div>

                <div class="form-row">
                    <div class="col">
                        <label>Tipe Akun</label>
                        <select name="type" id="inp_type" class="full-width" required>
                            <optgroup label="Neraca (Balance Sheet)">
                                <option value="ASSET">ASSET</option>
                                <option value="LIABILITY">LIABILITY</option>
                                <option value="EQUITY">EQUITY</option>
                            </optgroup>
                            <optgroup label="Laba Rugi (P&L)">
                                <option value="REVENUE">REVENUE</option>
                                <option value="EXPENSE">EXPENSE</option>
                                <option value="OTHER REVENUE">OTHER REVENUE</option>
                                <option value="OTHER EXPENSE">OTHER EXPENSE</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col" style="display:flex; align-items:flex-end; padding-bottom:10px;">
                        <label class="chk-label">
                            <input type="checkbox" name="is_cash" id="inp_cash" value="1"> Ini adalah akun Kas/Bank
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modal_form')">Batal</button>
                    <button type="submit" class="btn-save">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal_delete" class="puri-modal hidden">
        <div class="puri-modal-content danger-theme">
            <span class="close-modal" onclick="closeModal('modal_delete')">&times;</span>
            <h2>⚠️ Konfirmasi Hapus</h2>
            <p>Anda akan menghapus: <strong><span id="del_code_display"></span></strong></p>
            <div class="warning-box">
                <p><strong>PERINGATAN:</strong> Jika akun ini pernah digunakan, jurnal terkait akan rusak (orphaned).</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="puri_delete_coa_pro">
                <?php wp_nonce_field('puri_coa_delete', 'puri_nonce'); ?>
                <input type="hidden" name="code_to_delete" id="del_code_input">

                <div class="form-group">
                    <label>Tindakan:</label>
                    <select name="reassign_to" class="full-width select-reassign" required>
                        <option value="">-- Pilih Tindakan --</option>
                        <option value="DELETE_PERMANENTLY" style="color:red; font-weight:bold;">❌ JANGAN PINDAHKAN (Hapus Saja)</option>
                        <optgroup label="Pindahkan ke Akun Lain:">
                            <?php foreach($charts as $c): ?>
                                <option value="<?php echo esc_attr($c->code); ?>"><?php echo esc_html($c->code .' - '.$c->name); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <p class="hint-text">Pilih "Hapus Saja" jika akun ini belum pernah dipakai transaksi.</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modal_delete')">Batal</button>
                    <button type="submit" class="btn-delete-confirm">Eksekusi</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* (CSS sama seperti sebelumnya — dipersingkat di sini untuk kejelasan) */
        .puri-pro-ui { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
        .coa-card { max-width:90vw; min-width: 550px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; margin-top: 20px; border: 1px solid #e2e2e2; }
        .coa-table { width: 100%; border-collapse: collapse; }
        .coa-table th { text-align: left; padding: 15px 20px; background: #f9fafb; color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; }
        .coa-table td { padding: 12px 20px; border-bottom: 1px solid #f3f4f6; color: #111827; font-size: 14px; vertical-align: middle; }
        .coa-row:hover { background-color: #f3f9fc; }
        .action-group { visibility: hidden; display: flex; gap: 5px; justify-content: flex-end; }
        .coa-row:hover .action-group { visibility: visible; }
        .btn-icon { background: transparent; border: 1px solid transparent; cursor: pointer; padding: 5px; border-radius: 4px; color: #6b7280; }
        .btn-icon:hover { background: #fff; border-color: #d1d5db; color: #2563eb; }
        .btn-delete:hover { color: #dc2626; border-color: #fca5a5; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; display: inline-block; text-transform:uppercase; letter-spacing:0.5px;}
        .badge-asset { background: #d1fae5; color: #065f46; border:1px solid #6ee7b7; }
        .badge-liability { background: #ffedd5; color: #9a3412; border:1px solid #fdba74; }
        .badge-equity { background: #e0e7ff; color: #3730a3; border:1px solid #a5b4fc; }
        .badge-revenue { background: #dcfce7; color: #166534; border:1px solid #86efac; }
        .badge-expense { background: #fee2e2; color: #991b1b; border:1px solid #fca5a5; }
        .badge-default { background: #f3f4f6; color: #374151; }
        .text-green { color: #16a34a; font-weight: bold; }
        .highlight-input { font-weight: bold; font-family: monospace; letter-spacing: 0.5px; }
        .hint-text { font-size: 11px; color: #6b7280; margin-top: 4px; display: block; }
        .puri-modal { position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .hidden { display: none !important; }
        .puri-modal-content { background-color: #fff; padding: 25px; border-radius: 10px; width: 450px; max-width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .danger-theme { border-top: 5px solid #dc2626; }
        .close-modal { float: right; font-size: 24px; cursor: pointer; }
        .form-group { margin-bottom: 15px; }
        .full-width { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;}
        .form-row { display: flex; gap: 15px; } .col { flex: 1; }
        .modal-footer { margin-top: 25px; text-align: right; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-save { background: #2563eb; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; }
        .btn-cancel { background: #f3f4f6; padding: 8px 15px; border-radius: 6px; border: 1px solid #ccc; cursor: pointer; }
        .btn-delete-confirm { background: #dc2626; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; }
        .warning-box { background: #fff5f5; border-left: 4px solid #fca5a5; padding: 10px; font-size: 13px; margin: 15px 0; color: #991b1b; }
    </style>

    <script>
    const existingCodes = <?php echo json_encode($existing_codes); ?>;

    function openModal(mode, btn = null) {
        const modal = document.getElementById('modal_form');
        const title = document.getElementById('modal_title');
        const inpCode = document.getElementById('inp_code');
        const inpOldCode = document.getElementById('inp_old_code');
        const inpId = document.getElementById('inp_id');
        const inpName = document.getElementById('inp_name');
        const inpType = document.getElementById('inp_type');
        const inpCash = document.getElementById('inp_cash');
        const formMode = document.getElementById('form_mode');
        const hint = document.getElementById('code_hint');
        const boxCascade = document.getElementById('box_cascade_update');

        // Reset UI
        inpCode.value = ''; inpOldCode.value = ''; inpId.value = ''; inpName.value = ''; inpType.value = 'ASSET'; inpCash.checked = false;
        boxCascade.classList.add('hidden');

        if (mode === 'create') {
            title.innerText = 'Tambah Akun Baru';
            formMode.value = 'create';
            hint.innerText = "Unik, tidak boleh ganda.";
        }
        else if (mode === 'edit' && btn) {
            title.innerText = 'Edit Akun';
            // set mode first (defensive)
            formMode.value = 'update';
            let row = btn.closest('tr');

            // Populate Data (pastikan dataset ada)
            inpCode.value = row.dataset.code || '';
            inpOldCode.value = row.dataset.code || ''; // CRITICAL: Harus diisi untuk WHERE clause
            if (row.dataset.id) inpId.value = row.dataset.id;
            inpName.value = row.dataset.name || '';
            inpType.value = row.dataset.type || 'ASSET';
            inpCash.checked = (row.dataset.cash == "1");

            hint.innerText = "Anda bisa mengubah kode akun ini.";
            boxCascade.classList.remove('hidden');
        }
        else if (mode === 'duplicate' && btn) {
            title.innerText = 'Duplikat / Sub-Akun';
            formMode.value = 'create';
            let row = btn.closest('tr');
            let baseCode = row.dataset.code || '';
            inpName.value = (row.dataset.name || '') + " (Sub)";
            inpType.value = row.dataset.type || 'ASSET';
            inpCash.checked = (row.dataset.cash == "1");
            let nextCode = "";
            if (baseCode !== '' && !isNaN(parseInt(baseCode))) {
                nextCode = parseInt(baseCode) + 1;
                if (existingCodes.includes(String(nextCode))) nextCode = "";
            }
            inpCode.value = nextCode;
            hint.innerText = "Sistem menyarankan kode baru.";
        }
        modal.classList.remove('hidden');
    }

    function openDeleteModal(btn) {
        let row = btn.closest('tr');
        let code = row.dataset.code;
        let name = row.dataset.name;
        document.getElementById('del_code_display').innerText = code + ' - ' + name;
        document.getElementById('del_code_input').value = code;
        let select = document.querySelector('.select-reassign');
        for (let i = 0; i < select.options.length; i++) {
            // Disable self
            select.options[i].disabled = (select.options[i].value === code);
        }
        document.getElementById('modal_delete').classList.remove('hidden');
    }

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
    window.onclick = function(e) { if (e.target.classList.contains('puri-modal')) e.target.classList.add('hidden'); }
    </script>

    <?php
}

// 3. HANDLER: SAVE (CREATE & UPDATE WITH CASCADE) - lebih defensif
function puri_handle_coa_save_pro() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_coa_save', 'puri_nonce');

    global $wpdb;
    $table = puri_table_name('T_CHART');
    $table_journal = puri_table_name('T_JOURNAL');

    // Ambil input dengan sanitasi
    $posted_mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'create';
    $code = isset($_POST['code']) ? sanitize_text_field(trim($_POST['code'])) : '';
    $old_code = isset($_POST['old_code']) ? sanitize_text_field(trim($_POST['old_code'])) : '';
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $is_cash = isset($_POST['is_cash']) ? 1 : 0;
    $update_journal = isset($_POST['update_journal']) ? true : false;

    // Debug log sementara (hapus setelah debugging)
    error_log("puri_save_coa_pro POST: " . print_r($_POST, true));

    // Deteksi apakah tabel memiliki kolom 'id' (numeric PK)
    $has_id = false;
    $col_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'id'));
    if (!empty($col_check)) $has_id = true;

    // Tentukan mode akhir dengan validasi DB
    $mode = 'create';
    if ($posted_mode === 'update') {
        if ($has_id && $id > 0) {
            // jika id diberikan, pastikan ada record dengan id tersebut
            $exists_by_id = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d", $id));
            if (!$exists_by_id) wp_die("Error System: Record dengan id tidak ditemukan. Silakan refresh halaman.");
            $mode = 'update';
        } else {
            // fallback ke old_code
            if ($old_code === '') wp_die("Error System: Kode lama tidak terdeteksi. Silakan refresh halaman.");
            $exists_old = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $old_code));
            if (!$exists_old) wp_die("Error System: Kode lama tidak ditemukan di database.");
            $mode = 'update';
        }
    } else {
        // jika client tidak eksplisit update, tapi old_code atau id valid ada, anggap update
        if ($has_id && $id > 0) {
            $exists_by_id = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d", $id));
            if ($exists_by_id) $mode = 'update';
        } elseif ($old_code !== '') {
            $exists_old = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $old_code));
            if ($exists_old) $mode = 'update';
        } else {
            $mode = 'create';
        }
    }

    // Basic validation
    if ($code === '' || $name === '' || $type === '') {
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode("Input tidak lengkap.")));
        exit;
    }

    // Mulai transaksi
    $wpdb->query('START TRANSACTION');
    try {
        if ($mode === 'update') {
            // Jika update berdasarkan id (lebih aman), gunakan id sebagai WHERE
            if ($has_id && $id > 0) {
                // Jika code berubah, cek collision
                $current_code = $wpdb->get_var($wpdb->prepare("SELECT code FROM $table WHERE id=%d", $id));
                if ($current_code === null) throw new Exception("Record tidak ditemukan saat update.");
                if ($code !== $current_code) {
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s AND id != %d", $code, $id));
                    if ($exists) {
                        throw new Exception("Gagal: Kode baru $code sudah digunakan akun lain!");
                    }
                }

                $updated = $wpdb->update(
                    $table,
                    ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash],
                    ['id' => $id],
                    ['%s','%s','%s','%d'],
                    ['%d']
                );

                if ($updated === false) throw new Exception("DB Error saat update akun.");
                if ($updated === 0 && $code !== $current_code) {
                    throw new Exception("Tidak ada baris yang diupdate. Kemungkinan kode lama tidak ditemukan.");
                }

                // Cascade update jurnal jika code berubah
                $journal_updated = 0;
                if ($update_journal && $code !== $current_code) {
                    $journal_updated = $wpdb->update(
                        $table_journal,
                        ['account_code' => $code],
                        ['account_code' => $current_code],
                        ['%s'],
                        ['%s']
                    );
                }

                $wpdb->query('COMMIT');
                $msg = "Akun (id:$id) berhasil diperbarui. Kode: $current_code -> $code. ($journal_updated jurnal diperbarui).";
            } else {
                // Update berdasarkan old_code (fallback)
                if (empty($old_code)) throw new Exception("Error System: Kode lama tidak terdeteksi. Silakan refresh halaman.");

                // Cek apakah kode berubah?
                if ($code !== $old_code) {
                    // Cek collision (kode baru sudah ada?)
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $code));
                    if ($exists) {
                        throw new Exception("Gagal: Kode baru $code sudah digunakan akun lain!");
                    }

                    // Update PK (code) dan kolom lain
                    $updated = $wpdb->update(
                        $table,
                        ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash],
                        ['code' => $old_code],
                        ['%s','%s','%s','%d'],
                        ['%s']
                    );

                    if ($updated === false) throw new Exception("DB Error saat update akun.");
                    if ($updated === 0) throw new Exception("Tidak ada baris yang diupdate. Kode lama tidak ditemukan.");

                    // Cascade Update Jurnal
                    $journal_updated = 0;
                    if ($update_journal) {
                        $journal_updated = $wpdb->update(
                            $table_journal,
                            ['account_code' => $code],
                            ['account_code' => $old_code],
                            ['%s'],
                            ['%s']
                        );
                    }

                    $wpdb->query('COMMIT');
                    $msg = "Akun $old_code berubah menjadi $code. ($journal_updated jurnal diperbarui).";
                } else {
                    // Kode tidak berubah, hanya update kolom lain
                    $updated = $wpdb->update(
                        $table,
                        ['name' => $name, 'type' => $type, 'is_cash' => $is_cash],
                        ['code' => $code],
                        ['%s','%s','%d'],
                        ['%s']
                    );
                    if ($updated === false) throw new Exception("DB Error saat update akun.");
                    $wpdb->query('COMMIT');
                    $msg = "Akun $code berhasil diperbarui.";
                }
            }
        } else {
            // Mode Create
            // Pastikan kode belum ada
            $exists_code = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $code));
            if ($exists_code) throw new Exception("Kode $code sudah ada!");

            $inserted = $wpdb->insert(
                $table,
                ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash],
                ['%s','%s','%s','%d']
            );
            if ($inserted === false) throw new Exception("DB Error saat membuat akun.");
            $wpdb->query('COMMIT');
            $msg = "Akun $code dibuat.";
        }

        wp_redirect(admin_url('admin.php?page=puri-coa&msg='.urlencode($msg)));
        exit;

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        // Jika exception berasal dari collision atau validasi, redirect dengan pesan
        $err = $e->getMessage();
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode($err)));
        exit;
    }
}

// 4. HANDLER: DELETE (lebih aman)
function puri_handle_coa_delete_pro() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_coa_delete', 'puri_nonce');
    global $wpdb;
    $tbl_chart = puri_table_name('T_CHART');
    $tbl_jurnal = puri_table_name('T_JOURNAL');

    $del = isset($_POST['code_to_delete']) ? sanitize_text_field($_POST['code_to_delete']) : '';
    $new = isset($_POST['reassign_to']) ? sanitize_text_field($_POST['reassign_to']) : '';

    if (empty($del) || $new === '') {
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode("Pilihan tidak valid."))); exit;
    }

    $wpdb->query('START TRANSACTION');
    try {
        // Jika user memilih reassign ke akun lain
        if ($new !== 'DELETE_PERMANENTLY') {
            if ($del === $new) throw new Exception("Akun pengganti tidak boleh sama.");

            // Pastikan target reassign ada
            $exists_target = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl_chart WHERE code=%s", $new));
            if (!$exists_target) throw new Exception("Akun tujuan pengalihan tidak ditemukan.");

            $wpdb->query($wpdb->prepare(
                "UPDATE $tbl_jurnal SET account_code = %s WHERE account_code = %s",
                $new, $del
            ));
        } else {
            // Jika DELETE_PERMANENTLY, kita tetap mengizinkan penghapusan.
            // Opsional: cek apakah ada jurnal yang memakai akun ini dan tolak jika ada.
            $used_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl_jurnal WHERE account_code=%s", $del));
            if ($used_count > 0) {
                // Izinkan penghapusan permanen tapi beri peringatan; di sini kita tolak untuk mencegah orphan
                throw new Exception("Akun ini masih dipakai di jurnal. Gunakan opsi pindahkan atau kosongkan jurnal terlebih dahulu.");
            }
        }

        // Hapus akun
        $deleted = $wpdb->delete($tbl_chart, ['code' => $del], ['%s']);
        if ($deleted === false) throw new Exception("DB Error: Gagal menghapus akun.");
        if ($deleted === 0) throw new Exception("Akun tidak ditemukan atau sudah dihapus.");

        $wpdb->query('COMMIT');
        wp_redirect(admin_url('admin.php?page=puri-coa&msg='.urlencode("Akun $del berhasil dihapus.")));
        exit;

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode($e->getMessage())));
        exit;
    }
}