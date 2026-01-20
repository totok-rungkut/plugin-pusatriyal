<?php
/**
 * MC-05A - INVESTIGATION TOWER (REVERSAL HUB)
 * @version 7.3.11 (MOZART COMPATIBLE)
 * * Deskripsi:
 * Menampilkan saldo menggantung di akun Suspense yang menunggu hasil investigasi.
 * Mengeksekusi Reversal Jurnal via Mozart Engine.
 */

defined('ABSPATH') || exit;

// Menu Akses: Hanya untuk Supervisor/Admin
add_action('admin_menu', function() {
    add_submenu_page(
        'puri-transaksi', 
        'Investigation Tower', 
        '🔍 Investigasi', 
        'manage_options', 
        'puri-investigasi', 
        'puri_render_investigasi_page'
    );
});

/**
 * AJAX HANDLER: Batch Reversal Execution
 */
add_action('wp_ajax_puri_batch_reversal', function() {
    check_ajax_referer('puri_mozart_rev', 'nonce');
    
    if (!current_user_can('manage_options')) wp_send_json_error('Otoritas tidak cukup.');

    $refs = $_POST['refs'] ?? [];
    if (empty($refs)) wp_send_json_error('Tidak ada transaksi yang dipilih.');

    global $wpdb;
    $t_journal = puri_table_name('T_JOURNAL');
    $engine    = puri_mozart();
    $susp_acc  = puri_gl('suspense');
    $success   = 0;
    $errors    = [];

    foreach ($refs as $ref) {
        // 1. Cari data jurnal original di akun Suspense
        $orig = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_journal WHERE ref_id = %s AND account_code = %s LIMIT 1",
            $ref, $susp_acc
        ));

        if ($orig) {
            /**
             * LOGIC ARAH REVERSAL:
             * Jika di jurnal original Suspense ada di DEBIT (debit > 0), 
             * artinya itu adalah 'Loss' (Barang Kurang).
             * Maka is_gain = false.
             */
            $is_gain = ($orig->credit > 0); 
            $amount  = ($is_gain) ? $orig->credit : $orig->debit;

            $result = $engine->execute_reversal([
                'original_ref' => $ref,
                'amount'       => $amount,
                'is_gain'      => $is_gain,
                'description'  => 'Investigasi selesai. Saldo dipindahkan ke akun riil.'
            ]);

            if (is_wp_error($result)) {
                $errors[] = $ref . ": " . $result->get_error_message();
            } else {
                $success++;
            }
        }
    }

    if ($success > 0) {
        wp_send_json_success("Berhasil memproses $success transaksi.");
    } else {
        wp_send_json_error("Gagal: " . implode(', ', $errors));
    }
});

/**
 * UI RENDERER: Investigation Dashboard
 */
function puri_render_investigasi_page() {
    global $wpdb;
    $susp_acc  = puri_gl('suspense');
    $t_journal = puri_table_name('T_JOURNAL');

    // Query: Cari jurnal Suspense yang BELUM memiliki tandingan REVERSAL
    $results = $wpdb->get_results($wpdb->prepare("
        SELECT a.* FROM $t_journal a
        LEFT JOIN $t_journal b ON b.ref_id = CONCAT('REV-', a.ref_id)
        WHERE a.account_code = %s 
          AND a.ref_id NOT LIKE 'REV-%%'
          AND b.id IS NULL
        ORDER BY a.trx_date DESC
    ", $susp_acc));

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🔍 Investigation Tower</h1>
        <hr class="wp-header-end">

        <div class="notice notice-info inline">
            <p>Daftar di bawah adalah selisih stok yang statusnya masih <strong>Pending (Indirect)</strong>. Konfirmasi untuk mengakui sebagai Biaya atau Pendapatan riil.</p>
        </div>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <button type="button" id="do-batch-rev" class="button button-primary" disabled>
                    Konfirmasi <span id="select-count">0</span> Item Terpilih
                </button>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></td>
                    <th style="width: 15%;">Tanggal</th>
                    <th style="width: 15%;">No. Referensi</th>
                    <th>Keterangan Investigasi</th>
                    <th style="text-align: right; width: 15%;">Nominal (IDR)</th>
                    <th style="text-align: center; width: 10%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($results): foreach ($results as $row): 
                    $amt = ($row->debit > 0) ? $row->debit : $row->credit;
                    $is_loss = ($row->debit > 0);
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" name="refs[]" value="<?php echo $row->ref_id; ?>" class="rev-checkbox">
                    </th>
                    <td><?php echo date('d/m/Y H:i', strtotime($row->trx_date)); ?></td>
                    <td><code><?php echo $row->ref_id; ?></code></td>
                    <td><?php echo esc_html($row->description); ?></td>
                    <td style="text-align: right;">
                        <strong style="color: <?php echo $is_loss ? '#d63638' : '#00a32a'; ?>">
                            <?php echo number_format($amt, 0, ',', '.'); ?>
                        </strong>
                    </td>
                    <td style="text-align: center;">
                        <span class="badge" style="background: #ffb900; color: #000; padding: 2px 6px; border-radius: 4px; font-size: 11px;">PENDING</span>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px;">Semua investigasi telah diselesaikan. Saldo Suspense bersih.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Checkbox management
        $('.rev-checkbox, #cb-select-all-1').change(function() {
            if(this.id === 'cb-select-all-1') $('.rev-checkbox').prop('checked', this.checked);
            
            const checkedCount = $('.rev-checkbox:checked').length;
            $('#select-count').text(checkedCount);
            $('#do-batch-rev').prop('disabled', checkedCount === 0);
        });

        // Ajax Execution
        $('#do-batch-rev').click(function() {
            const selectedRefs = [];
            $('.rev-checkbox:checked').each(function() {
                selectedRefs.push($(this).val());
            });

            if(!confirm('Anda yakin ingin mengakui ' + selectedRefs.length + ' transaksi ini secara final di laporan keuangan?')) return;

            const btn = $(this);
            btn.prop('disabled', true).text('Memproses Reversal...');

            $.post(ajaxurl, {
                action: 'puri_batch_reversal',
                refs: selectedRefs,
                nonce: '<?php echo wp_create_nonce("puri_mozart_rev"); ?>'
            }, function(response) {
                if(response.success) {
                    alert('Sukses: ' + response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    btn.prop('disabled', false).text('Coba Lagi');
                }
            });
        });
    });
    </script>
    <style>
        .widefat strong { font-family: 'Courier New', Courier, monospace; font-size: 1.1em; }
        .badge { font-weight: bold; }
    </style>
    <?php
}