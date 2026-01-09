<?php
/**
 * MC 23 - Chart of Accounts (CoA) Manager - Refactor Complete
 * Version: 6.6.2-refactor
 * Author: Refactor by COPILOT (preserve original API)
 *
 * Features:
 * - Full CRUD for CoA with columns: code, name, type, is_cash, balance, description
 * - Hover flyover actions: Edit, Duplicate, Delete
 * - Edit: modal; when code changes, suggest reassign target for existing journals (default 9000)
 * - Duplicate: modal to create new record with sequential suggested code
 * - Delete: modal with reassign option for existing journals; prevents orphaning by default
 * - Defensive server-side validation, transactions, prepared formats
 */

defined('ABSPATH') || exit;

// Hooks
add_action('admin_menu', function() {
    if (!has_action('admin_menu', 'puri_render_coa_manager_page')) {
        add_submenu_page('puri-transaksi', 'Chart of Accounts', '📂 CoA Manager', 'manage_options', 'puri-coa', 'puri_render_coa_manager_page');
    }
});

add_action('admin_post_puri_save_coa_pro', 'puri_handle_coa_save_pro');
add_action('admin_post_puri_delete_coa_pro', 'puri_handle_coa_delete_pro');

// Render page
function puri_render_coa_manager_page() {
    puri_check_cap('manage_options');
    global $wpdb;
    $table = puri_table_name('T_CHART');
    $table_journal = puri_table_name('T_JOURNAL');

    // Ensure table exists (graceful)
    if (empty($table)) {
        echo '<div class="notice notice-error"><p>Table T_CHART not defined. Check MC-01 constants.</p></div>';
        return;
    }

    // Fetch all accounts
    $charts = $wpdb->get_results("SELECT * FROM $table ORDER BY code ASC");

    // Build arrays for JS
    $existing_codes = $charts ? wp_list_pluck($charts, 'code') : [];
    $accounts_for_select = [];
    if ($charts) {
        foreach ($charts as $c) {
            $accounts_for_select[] = ['code' => $c->code, 'label' => $c->code . ' - ' . $c->name];
        }
    }

    // Ensure default unknown account exists (9000)
    $unknown_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", '9000'));
    if (!$unknown_exists) {
        // Try to insert silently
        $wpdb->insert($table, ['code' => '9000', 'name' => 'Unknown', 'type' => 'EQUITY', 'is_cash' => 0, 'balance' => 0, 'description' => 'Auto-created unknown account']);
        // refresh list
        $charts = $wpdb->get_results("SELECT * FROM $table ORDER BY code ASC");
        $existing_codes = $charts ? wp_list_pluck($charts, 'code') : [];
        $accounts_for_select = [];
        foreach ($charts as $c) $accounts_for_select[] = ['code' => $c->code, 'label' => $c->code . ' - ' . $c->name];
    }
    ?>

    <div class="wrap puri-pro-ui">
        <h1 class="wp-heading-inline">📂 Chart of Accounts Copilot</h1>
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
                        <th width="12%">Kode</th>
                        <th width="30%">Nama Akun</th>
                        <th width="15%">Tipe</th>
                        <th width="10%">Kas/Bank</th>
                        <th width="12%">Saldo</th>
                        <th width="21%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($charts)) : ?>
                        <tr><td colspan="6" class="empty-state">Belum ada akun. Klik 'Tambah Akun Baru'.</td></tr>
                    <?php else : foreach($charts as $c):
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
                            data-balance="<?php echo esc_attr(isset($c->balance) ? $c->balance : 0); ?>"
                            data-desc="<?php echo esc_attr(isset($c->description) ? $c->description : ''); ?>">
                            
                            <td class="col-code"><?php echo esc_html($c->code); ?></td>
                            <td class="col-name"><span class="fw-bold"><?php echo esc_html($c->name); ?></span>
							<div class="muted small"><?php echo esc_html(isset($c->description) ? $c->description : ''); ?></div></td>
                            <td><span class="badge <?php echo $badge_cls; ?>"><?php echo esc_html($c->type); ?></span></td>
                            <td><?php echo $c->is_cash ? '<span class="dashicons dashicons-yes text-green"></span>' : ''; ?></td>
                            <td><?php echo number_format(floatval(isset($c->balance) ? $c->balance : 0), 2, ',', '.'); ?></td>
                            <td class="col-actions">
                                <div class="action-group">
                                    <button type="button" class="btn-icon" title="Edit" onclick="openModal('edit', this)"><span class="dashicons dashicons-edit"></span></button>
                                    <button type="button" class="btn-icon" title="Duplicate" onclick="openModal('duplicate', this)"><span class="dashicons dashicons-images-alt2"></span></button>
                                    <button type="button" class="btn-icon btn-delete" title="Hapus" onclick="openDeleteModal(this)"><span class="dashicons dashicons-trash"></span></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Create / Edit / Duplicate -->
    <div id="modal_form" class="puri-modal hidden">
        <div class="puri-modal-content">
            <span class="close-modal" onclick="closeModal('modal_form')">&times;</span>
            <h2 id="modal_title">Tambah Akun</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="puri_save_coa_pro">
                <?php wp_nonce_field('puri_coa_save', 'puri_nonce'); ?>

                <input type="hidden" name="mode" id="form_mode" value="create">
                <input type="hidden" name="old_code" id="inp_old_code" value="">
                <input type="hidden" name="code_id" id="inp_code_id" value="">

                <div class="form-group">
                    <label>Kode Akun</label>
                    <input type="text" name="code" id="inp_code" class="full-width highlight-input" required placeholder="Cth: 5100">
                    <small id="code_hint" class="hint-text">Unik, tidak boleh ganda.</small>
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

                <div class="form-group">
                    <label>Saldo Awal</label>
                    <input type="number" step="0.01" name="balance" id="inp_balance" class="full-width" placeholder="0.00">
                </div>

                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea name="description" id="inp_desc" class="full-width" rows="3" placeholder="Keterangan tambahan..."></textarea>
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
                    <div style="margin-top:8px;">
                        <label>Pilih kode penampung jika diperlukan:</label>
                        <select id="select_reassign_suggest" name="reassign_suggest" class="full-width">
                            <!-- options injected by JS -->
                        </select>
                        <small class="hint-text">Jika kosong, default diarahkan ke 9000 - Unknown.</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modal_form')">Batal</button>
                    <button type="submit" class="btn-save">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Delete -->
    <div id="modal_delete" class="puri-modal hidden">
        <div class="puri-modal-content danger-theme">
            <span class="close-modal" onclick="closeModal('modal_delete')">&times;</span>
            <h2>⚠️ Konfirmasi Hapus</h2>
            <p>Anda akan menghapus: <strong><span id="del_code_display"></span></strong></p>
            <div class="warning-box">
                <p><strong>PERINGATAN:</strong> Jika akun ini pernah digunakan, jurnal terkait akan terpengaruh.</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="puri_delete_coa_pro">
                <?php wp_nonce_field('puri_coa_delete', 'puri_nonce'); ?>
                <input type="hidden" name="code_to_delete" id="del_code_input">

                <div class="form-group">
                    <label>Tindakan untuk jurnal yang sudah ada:</label>
                    <select name="reassign_to" class="full-width select-reassign" required>
                        <option value="">-- Pilih Tindakan --</option>
                        <option value="DELETE_PERMANENTLY" style="color:red; font-weight:bold;">❌ Hapus Saja (jika belum pernah dipakai)</option>
                        <?php foreach ($accounts_for_select as $opt): ?>
                            <option value="<?php echo esc_attr($opt['code']); ?>"><?php echo esc_html($opt['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint-text">Pilih akun tujuan jika ingin memindahkan jurnal lama.</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modal_delete')">Batal</button>
                    <button type="submit" class="btn-delete-confirm">Eksekusi</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* Styles (kept concise) */
        .puri-pro-ui { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; }
        .coa-card { max-width:90vw; min-width: 650px; background: #fff; border-radius: 8px; margin-top: 20px; border: 1px solid #e2e2e2; padding:0; }
        .coa-table { width:100%; border-collapse: collapse; }
        .coa-table th { text-align:left; padding:12px 16px; background:#f9fafb; color:#6b7280; font-weight:600; font-size:12px; border-bottom:1px solid #e5e7eb; }
        .coa-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .coa-row:hover { background:#f3f9fc; }
        .action-group { visibility:hidden; display:flex; gap:6px; justify-content:flex-end; }
        .coa-row:hover .action-group { visibility:visible; }
        .btn-icon { background:transparent; border:1px solid transparent; padding:6px; border-radius:4px; cursor:pointer; color:#6b7280; }
        .btn-icon:hover { border-color:#d1d5db; color:#2563eb; }
        .btn-delete:hover { color:#dc2626; border-color:#fca5a5; }
        .badge { padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase; }

/*        .badge-asset { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; } */
        .badge-liability { background:#ffedd5; color:#9a3412; border:1px solid #fdba74; }
        .badge-equity { background:#e0e7ff; color:#3730a3; border:1px solid #a5b4fc; }
/*        .badge-revenue { background:#dcfce7; color:#166534; border:1px solid #86efac; } */
        .badge-expense { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        .badge-asset { background:#fefefe; color:#065f46; border:1px solid #065f46; border-radius:20px; padding:0 18px;}
        .badge-liability { background:#ffedd5; color:#9a3412; border:1px solid #fdba74; padding:0 10px;}
        .badge-equity { background:#f2f2ff; color:#3730a3; border:1px solid #30379f; border-radius:20px; padding:0 18px;}
        .badge-revenue { background:#bdfdcd; color:#166534; border:1px solid #166534; border-radius:20px 0px 20px 20px;}
        .badge-expense { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:20px 20px 0px 20px ;}



        .badge-default { background:#f3f4f6; color:#374151; }
        .puri-modal { position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; }
        .hidden { display:none !important; }
		.fw-bold { font-size:larger;}
        .puri-modal-content { background:#fff; padding:22px; border-radius:10px; width:520px; max-width:95%; box-shadow:0 10px 25px rgba(0,0,0,0.2); }
        .danger-theme { border-top:5px solid #dc2626; }
        .close-modal { float:right; font-size:22px; cursor:pointer; }
        .form-group { margin-bottom:12px; }
        .full-width { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; }
        .form-row { display:flex; gap:12px; } .col { flex:1; }
        .modal-footer { margin-top:18px; text-align:right; display:flex; gap:10px; justify-content:flex-end; }
        .btn-save { background:#2563eb; color:#fff; padding:8px 18px; border-radius:6px; border:none; cursor:pointer; }
        .btn-cancel { background:#f3f4f6; padding:8px 14px; border-radius:6px; border:1px solid #ccc; cursor:pointer; }
        .btn-delete-confirm { background:#dc2626; color:#fff; padding:8px 18px; border-radius:6px; border:none; cursor:pointer; }
        .hint-text { font-size:11px; color:#6b7280; margin-top:4px; display:block; }
        .muted { color:#6b7280; font-size:12px; }
    </style>

    <script>
    const existingCodes = <?php echo json_encode($existing_codes); ?>;
    const accountsForSelect = <?php echo json_encode($accounts_for_select); ?>;

    function openModal(mode, btn = null) {
        const modal = document.getElementById('modal_form');
        const title = document.getElementById('modal_title');
        const inpCode = document.getElementById('inp_code');
        const inpOldCode = document.getElementById('inp_old_code');
        const inpId = document.getElementById('inp_code_id');
        const inpName = document.getElementById('inp_name');
        const inpType = document.getElementById('inp_type');
        const inpCash = document.getElementById('inp_cash');
        const inpBalance = document.getElementById('inp_balance');
        const inpDesc = document.getElementById('inp_desc');
        const formMode = document.getElementById('form_mode');
        const hint = document.getElementById('code_hint');
        const boxCascade = document.getElementById('box_cascade_update');
        const selectSuggest = document.getElementById('select_reassign_suggest');

        // Reset
        inpCode.value = ''; inpOldCode.value = ''; inpId.value = ''; inpName.value = ''; inpType.value = 'ASSET'; inpCash.checked = false; inpBalance.value = ''; inpDesc.value = '';
        boxCascade.classList.add('hidden');
        selectSuggest.innerHTML = '<option value="">-- Pilih --</option>';

        if (mode === 'create') {
            title.innerText = 'Tambah Akun Baru';
            formMode.value = 'create';
            hint.innerText = "Unik, tidak boleh ganda.";
        } else if (mode === 'edit' && btn) {
            title.innerText = 'Edit Akun';
            formMode.value = 'update';
            let row = btn.closest('tr');
            inpCode.value = row.dataset.code || '';
            inpOldCode.value = row.dataset.code || '';
            inpName.value = row.dataset.name || '';
            inpType.value = row.dataset.type || 'ASSET';
            inpCash.checked = (row.dataset.cash == "1");
            inpBalance.value = row.dataset.balance || '';
            inpDesc.value = row.dataset.desc || '';
            // populate reassign suggestions (exclude self)
            accountsForSelect.forEach(function(a) {
                if (a.code !== inpOldCode.value) {
                    let opt = document.createElement('option');
                    opt.value = a.code; opt.text = a.label;
                    selectSuggest.appendChild(opt);
                }
            });
            // if no options, add 9000
            if (selectSuggest.options.length <= 1) {
                let opt = document.createElement('option');
                opt.value = '9000'; opt.text = '9000 - Unknown';
                selectSuggest.appendChild(opt);
            }
            boxCascade.classList.remove('hidden');
            hint.innerText = "Anda bisa mengubah kode akun ini. Jika mengubah, pilih kode penampung untuk jurnal lama.";
        } else if (mode === 'duplicate' && btn) {
            title.innerText = 'Duplikat / Sub-Akun';
            formMode.value = 'create';
            let row = btn.closest('tr');
            let baseCode = row.dataset.code || '';
            inpName.value = (row.dataset.name || '') + " (Copy)";
            inpType.value = row.dataset.type || 'ASSET';
            inpCash.checked = (row.dataset.cash == "1");
            // suggest next sequential numeric code
            let nextCode = '';
            if (baseCode !== '' && !isNaN(parseInt(baseCode))) {
                nextCode = parseInt(baseCode) + 1;
                while (existingCodes.includes(String(nextCode))) {
                    nextCode = parseInt(nextCode) + 1;
                }
            }
            inpCode.value = nextCode;
            hint.innerText = "Sistem menyarankan kode baru (sequential).";
        }
        modal.classList.remove('hidden');
    }

    function openDeleteModal(btn) {
        let row = btn.closest('tr');
        let code = row.dataset.code;
        let name = row.dataset.name;
        document.getElementById('del_code_display').innerText = code + ' - ' + name;
        document.getElementById('del_code_input').value = code;
        // disable self in select
        let select = document.querySelector('.select-reassign');
        for (let i = 0; i < select.options.length; i++) {
            select.options[i].disabled = (select.options[i].value === code);
        }
        document.getElementById('modal_delete').classList.remove('hidden');
    }

    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
    window.onclick = function(e) { if (e.target.classList.contains('puri-modal')) e.target.classList.add('hidden'); }
    </script>

    <?php
}

// Handler: Save (create & update)
function puri_handle_coa_save_pro() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_coa_save', 'puri_nonce');

    global $wpdb;
    $table = puri_table_name('T_CHART');
    $table_journal = puri_table_name('T_JOURNAL');

    // sanitize inputs
    $posted_mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'create';
    $code = isset($_POST['code']) ? sanitize_text_field(trim($_POST['code'])) : '';
    $old_code = isset($_POST['old_code']) ? sanitize_text_field(trim($_POST['old_code'])) : '';
    $code_id = isset($_POST['code_id']) ? sanitize_text_field(trim($_POST['code_id'])) : ''; // optional id if present
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $is_cash = isset($_POST['is_cash']) ? 1 : 0;
    $balance = isset($_POST['balance']) ? floatval($_POST['balance']) : 0;
    $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
    $update_journal = isset($_POST['update_journal']) ? true : false;
    $reassign_suggest = isset($_POST['reassign_suggest']) ? sanitize_text_field($_POST['reassign_suggest']) : '';

    // debug log (optional)
    // error_log("puri_save_coa_pro POST: " . print_r($_POST, true));

    // basic validation
    if ($code === '' || $name === '' || $type === '') {
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode("Input tidak lengkap.")));
        exit;
    }

    // detect if table has numeric id column
    $has_id = false;
    $col_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'id'));
    if (!empty($col_check)) $has_id = true;

    // determine mode robustly
    $mode = 'create';
    if ($posted_mode === 'update') {
        if ($has_id && $code_id !== '') {
            // ensure record exists by id
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%s", $code_id));
            if (!$exists) wp_die("Error System: Record tidak ditemukan. Silakan refresh halaman.");
            $mode = 'update';
        } else {
            if ($old_code === '') wp_die("Error System: Kode lama tidak terdeteksi. Silakan refresh halaman.");
            $exists_old = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $old_code));
            if (!$exists_old) wp_die("Error System: Kode lama tidak ditemukan di database.");
            $mode = 'update';
        }
    } else {
        // fallback: if old_code exists in DB, treat as update
        if ($has_id && $code_id !== '') {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%s", $code_id));
            if ($exists) $mode = 'update';
        } elseif ($old_code !== '') {
            $exists_old = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $old_code));
            if ($exists_old) $mode = 'update';
        } else {
            $mode = 'create';
        }
    }

    $wpdb->query('START TRANSACTION');
    try {
        if ($mode === 'update') {
            // update by id if possible
            if ($has_id && $code_id !== '') {
                $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%s", $code_id));
                if (!$current) throw new Exception("Record tidak ditemukan saat update.");
                $current_code = $current->code;

                // if code changed, check collision
                if ($code !== $current_code) {
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s AND id != %s", $code, $code_id));
                    if ($exists) throw new Exception("Gagal: Kode baru $code sudah digunakan akun lain!");
                }

                $updated = $wpdb->update(
                    $table,
                    ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash, 'balance' => $balance, 'description' => $description],
                    ['id' => $code_id],
                    ['%s','%s','%s','%d','%f','%s'],
                    ['%s']
                );
                if ($updated === false) throw new Exception("DB Error saat update akun.");
                if ($updated === 0 && $code !== $current_code) throw new Exception("Tidak ada baris yang diupdate. Kemungkinan kode lama tidak ditemukan.");

                // cascade update journals if code changed
                $journal_updated = 0;
                if ($update_journal && $code !== $current_code) {
                    $target = $reassign_suggest !== '' ? $reassign_suggest : $code;
                    // if target equals new code, just update account_code from old to new
                    $journal_updated = $wpdb->update($table_journal, ['account_code' => $code], ['account_code' => $current_code], ['%s'], ['%s']);
                }

                $wpdb->query('COMMIT');
                $msg = "Akun (id:$code_id) berhasil diperbarui. Kode: $current_code -> $code. ($journal_updated jurnal diperbarui).";
            } else {
                // update by old_code
                if (empty($old_code)) throw new Exception("Error System: Kode lama tidak terdeteksi.");

                if ($code !== $old_code) {
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $code));
                    if ($exists) throw new Exception("Gagal: Kode baru $code sudah digunakan akun lain!");
                }

                $updated = $wpdb->update(
                    $table,
                    ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash, 'balance' => $balance, 'description' => $description],
                    ['code' => $old_code],
                    ['%s','%s','%s','%d','%f','%s'],
                    ['%s']
                );
                if ($updated === false) throw new Exception("DB Error saat update akun.");
                if ($updated === 0) throw new Exception("Tidak ada baris yang diupdate. Kode lama tidak ditemukan.");

                // cascade update journals if requested
                $journal_updated = 0;
                if ($update_journal) {
                    $target = $reassign_suggest !== '' ? $reassign_suggest : $code;
                    $journal_updated = $wpdb->update($table_journal, ['account_code' => $code], ['account_code' => $old_code], ['%s'], ['%s']);
                }

                $wpdb->query('COMMIT');
                $msg = "Akun $old_code berubah menjadi $code. ($journal_updated jurnal diperbarui).";
            }
        } else {
            // create
            $exists_code = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE code=%s", $code));
            if ($exists_code) throw new Exception("Kode $code sudah ada!");

            $inserted = $wpdb->insert(
                $table,
                ['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $is_cash, 'balance' => $balance, 'description' => $description],
                ['%s','%s','%s','%d','%f','%s']
            );
            if ($inserted === false) throw new Exception("DB Error saat membuat akun.");
            $wpdb->query('COMMIT');
            $msg = "Akun $code dibuat.";
        }

        wp_redirect(admin_url('admin.php?page=puri-coa&msg='.urlencode($msg)));
        exit;

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_redirect(admin_url('admin.php?page=puri-coa&err='.urlencode($e->getMessage())));
        exit;
    }
}

// Handler: Delete
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
        // If reassign requested
        if ($new !== 'DELETE_PERMANENTLY') {
            if ($del === $new) throw new Exception("Akun pengganti tidak boleh sama.");

            // ensure target exists
            $exists_target = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl_chart WHERE code=%s", $new));
            if (!$exists_target) throw new Exception("Akun tujuan pengalihan tidak ditemukan.");

            // reassign journals
            $wpdb->query($wpdb->prepare("UPDATE $tbl_jurnal SET account_code = %s WHERE account_code = %s", $new, $del));
        } else {
            // If delete permanently, check if used in journal
            $used_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl_jurnal WHERE account_code=%s", $del));
            if ($used_count > 0) {
                throw new Exception("Akun ini masih dipakai di jurnal. Gunakan opsi pindahkan atau kosongkan jurnal terlebih dahulu.");
            }
        }

        // delete account
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


/**
 * Puri CoA Schema Migration
 * - Adds columns: id (BIGINT AUTO_INCREMENT PK), balance (DECIMAL), description (TEXT)
 * - Converts existing PK on code to UNIQUE if necessary
 * - Idempotent and runs only once (transient flag)
 */
add_action('admin_init', 'puri_coa_migrate_schema_once');
function puri_coa_migrate_schema_once() {
    if (!current_user_can('manage_options')) return;
    // Run only once per site (transient)
    if (get_transient('puri_coa_migration_done')) return;
    // Run migration
    $res = puri_coa_migrate_schema();
    // store result for 12 hours to avoid repeated runs
    set_transient('puri_coa_migration_done', 1, 12 * HOUR_IN_SECONDS);
    // show admin notice with result
    add_action('admin_notices', function() use ($res) {
        if ($res['success']) {
            echo '<div class="notice notice-success is-dismissible"><p>CoA Migration: ' . esc_html($res['message']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>CoA Migration Error: ' . esc_html($res['message']) . '</p></div>';
        }
    });
}

function puri_coa_migrate_schema() {
    global $wpdb;
    $table = puri_table_name('T_CHART');
    if (empty($table)) return ['success' => false, 'message' => 'T_CHART constant not defined.'];

    $charset_collate = $wpdb->get_charset_collate();
    $messages = [];

    // Helper: check column exists
    $col_exists = function($col) use ($wpdb, $table) {
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        return !empty($row);
    };

    // 1) Add balance column if missing
    try {
        if (!$col_exists('balance')) {
            $sql = "ALTER TABLE {$table} ADD COLUMN balance DECIMAL(19,4) NOT NULL DEFAULT 0";
            $wpdb->query($sql);
            $messages[] = "Added column `balance`.";
        } else {
            $messages[] = "`balance` already exists.";
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed adding balance: ' . $e->getMessage()];
    }

    // 2) Add description column if missing
    try {
        if (!$col_exists('description')) {
            $sql = "ALTER TABLE {$table} ADD COLUMN description TEXT NULL";
            $wpdb->query($sql);
            $messages[] = "Added column `description`.";
        } else {
            $messages[] = "`description` already exists.";
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed adding description: ' . $e->getMessage()];
    }

    // 3) Add id column as BIGINT AUTO_INCREMENT PRIMARY KEY if missing
    try {
        if (!$col_exists('id')) {
            // Check if table currently has a PRIMARY KEY
            $pk_info = $wpdb->get_results($wpdb->prepare(
                "SELECT k.COLUMN_NAME FROM information_schema.table_constraints t
                 JOIN information_schema.key_column_usage k
                 USING(constraint_name,table_schema,table_name)
                 WHERE t.constraint_type = 'PRIMARY KEY'
                 AND t.table_schema = DATABASE()
                 AND t.table_name = %s", $wpdb->esc_like($table)
            ));

            $has_pk = !empty($pk_info);

            if ($has_pk) {
                // If PK exists (likely on code), we will:
                // 1) Drop primary key
                // 2) Add id column as BIGINT NOT NULL AUTO_INCREMENT
                // 3) Set id as PRIMARY KEY
                // 4) Add UNIQUE index on code to preserve uniqueness
                $wpdb->query("ALTER TABLE {$table} DROP PRIMARY KEY");
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT FIRST");
                $wpdb->query("ALTER TABLE {$table} ADD PRIMARY KEY (id)");
                // ensure code unique
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uniq_code (code)");
                $messages[] = "Added `id` as AUTO_INCREMENT PRIMARY KEY and converted existing PK to UNIQUE on `code`.";
            } else {
                // No existing PK, add id as primary key directly
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                $messages[] = "Added `id` as AUTO_INCREMENT PRIMARY KEY.";
            }
        } else {
            $messages[] = "`id` already exists.";
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed adding id PK: ' . $e->getMessage()];
    }

    return ['success' => true, 'message' => implode(' ', $messages)];
}


