<?php
/**
 * MC-05B - SUPERVISOR RECONCILIATION & APPROVAL
 * @version 7.7.0
 * Purpose: Verifikasi pooling dari kasir, Explode Preview, dan Bulk Execution.
 */

defined('ABSPATH') || exit;

function puri_render_reconciliation_page() {
    global $wpdb;
    
    // 1. DATA POOLING AGGREGATOR
    // Mengambil semua transient yang berawalan 'puri_opname_pool_'
    $all_transients = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_puri_opname_pool_%'");
    
    $pending_reports = [];
    foreach ($all_transients as $transient_name) {
        $user_id = str_replace('_transient_puri_opname_pool_', '', $transient_name);
        $data = get_transient('puri_opname_pool_' . $user_id);
        if ($data) {
            $user_info = get_userdata($user_id);
            $pending_reports[$user_id] = [
                'user_display' => $user_info ? $user_info->display_name : 'Unknown',
                'status'       => $data['status'],
                'items'        => $data['data'],
                'time'         => $data['time']
            ];
        }
    }

    ?>
    <style>
        .opname-header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; border-radius: 4px; position: sticky; top: 32px; z-index: 100; }
        .bulk-actions-bar { display: flex; align-items: center; gap: 15px; }
        .selection-counter { font-weight: bold; color: #2271b1; background: #f0f6fb; padding: 5px 12px; border-radius: 20px; border: 1px solid #dcdcde; }
        .card-report { background: #fff; border: 1px solid #ccd0d4; margin-bottom: 30px; border-radius: 4px; overflow: hidden; }
        .card-report-header { background: #f6f7f7; padding: 12px 20px; border-bottom: 1px solid #ccd0d4; display: flex; justify-content: space-between; }
        .badge-diff { background: #d63638; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        .badge-matched { background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        .explode-preview { font-size: 11px; color: #666; background: #f9f9f9; padding: 10px; border-radius: 4px; margin-top: 5px; display: none; }
        .btn-preview { cursor: pointer; color: #2271b1; text-decoration: underline; font-size: 12px; }
    </style>

    <div class="wrap">
        <h1>搭 Verifikasi Stock Opname (EOD)</h1>

        <div class="opname-header">
            <div class="bulk-actions-bar">
                <input type="checkbox" id="select-all-global" style="transform: scale(1.2);"> <strong>Select All Reports</strong>
                <span class="selection-counter" id="total-selected">0 baris akan diproses</span>
            </div>
            <div class="action-buttons">
                <button type="submit" form="form-reconciliation" name="action" value="post_gl" class="button button-primary button-large">✅ Posting ke GL</button>
                <button type="submit" form="form-reconciliation" name="action" value="investigate" class="button button-secondary button-large">🔍 Lempar ke Investigasi</button>
            </div>
        </div>

        <?php if (empty($pending_reports)) : ?>
            <div class="notice notice-info"><p>Tidak ada laporan opname pending saat ini.</p></div>
        <?php else : ?>
            <form id="form-reconciliation" method="post">
                <?php foreach ($pending_reports as $uid => $report) : ?>
                    <div class="card-report">
                        <div class="card-report-header">
                            <div>
                                <input type="checkbox" class="report-checkbox" name="selected_users[]" value="<?php echo $uid; ?>">
                                <strong>Laporan: <?php echo $report['user_display']; ?></strong> 
                                <span class="description" style="margin-left:10px;">🕒 <?php echo $report['time']; ?></span>
                            </div>
                            <span class="badge-<?php echo strtolower($report['status']); ?>"><?php echo $report['status']; ?></span>
                        </div>
                        
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th width="30%">Item / Paket</th>
                                    <th width="15%">Buku</th>
                                    <th width="15%">Fisik</th>
                                    <th width="15%">Selisih</th>
                                    <th>Analisa / Explode</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report['items'] as $item) : 
                                    $diff_class = ($item['diff'] < 0) ? 'color:red;' : (($item['diff'] > 0) ? 'color:green;' : '');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $item['name']; ?></strong><br>
                                        <small><?php echo $item['sku']; ?> (<?php echo strtoupper($item['type']); ?>)</small>
                                    </td>
                                    <td><?php echo number_format($item['sys'], 2); ?></td>
                                    <td><strong><?php echo number_format($item['phys'], 2); ?></strong></td>
                                    <td style="<?php echo $diff_class; ?> font-weight:bold;">
                                        <?php echo ($item['diff'] > 0 ? '+' : '') . number_format($item['diff'], 2); ?>
                                    </td>
                                    <td>
                                        <?php if ($item['type'] === 'package' && $item['diff'] != 0) : ?>
                                            <span class="btn-preview" onclick="jQuery('#explode-<?php echo $uid . $item['item_id']; ?>').toggle();">
                                                👁️ Lihat rincian isi paket
                                            </span>
                                            <div id="explode-<?php echo $uid . $item['item_id']; ?>" class="explode-preview">
                                                <strong>Estimasi Explode (HPP Based):</strong><br>
                                                <?php 
                                                    // Logic Preview T_LOCKS
                                                    $locks = $wpdb->get_results($wpdb->prepare("SELECT l.quantity, i.name FROM ".puri_table_name('T_LOCKS')." l JOIN ".puri_table_name('T_ITEMS')." i ON l.child_item_id = i.id WHERE l.parent_item_id = %d", $item['item_id']));
                                                    foreach($locks as $l) {
                                                        echo "• " . ($l->quantity * $item['diff']) . " unit " . $l->name . "<br>";
                                                    }
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function updateCounter() {
            let count = $('.report-checkbox:checked').length;
            $('#total-selected').text(count + ' laporan dipilih untuk diproses');
        }

        $('#select-all-global').on('change', function() {
            $('.report-checkbox').prop('checked', $(this).prop('checked'));
            updateCounter();
        });

        $('.report-checkbox').on('change', function() {
            updateCounter();
            if(!$(this).prop('checked')) $('#select-all-global').prop('checked', false);
        });
    });
    </script>
    <?php
}