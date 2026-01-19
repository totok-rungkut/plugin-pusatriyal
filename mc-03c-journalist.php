<?php
/**
 * =============================================================================
 * MC-03c - PURI Accounting Journalist (Mozart Bridge)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     7.3.14
 * @author      Mozart Engine Core
 * * Responsibilities:
 * - Menulis Jurnal Umum (General Ledger) ke tabel T_JOURNAL.
 * - Bertindak sebagai "Composer" narasi jurnal yang informatif secara otomatis.
 * - Mengambil referensi akun secara dinamis via MC-28 (GL Mapping).
 * - Menjamin integritas data Audit Trail dengan menyimpan Snapshot JSON.
 * * Workflow Cash-Based:
 * 1. (Dr) Persediaan/Biaya (Berdasarkan total_idr dari MC-04)
 * 2. (Cr) Kas/Bank (Berdasarkan rincian payments dari MC-04)
 * =============================================================================
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Accounting_Journal_V7')) {
    class PURI_Accounting_Journal_V7 {

        /**
         * Post to General Ledger
         * Fungsi utama untuk mencatat transaksi ke jurnal.
         */
        public function post_to_gl($action_type, $params) {
            global $wpdb;
            $table_journal = puri_table_name('T_JOURNAL'); // Sinkron MC-01

            // 1. DATA PREPARATION
            $ref_id      = $params['source_ref']; // Contoh: PRO-20260119-XYZ
            $total_idr   = floatval($params['total_idr']);
            $created_by  = $params['created_by'] ?? get_current_user_id();
            $trx_date    = $params['created_at'] ?? current_time('mysql');
            $snapshot    = $params['snapshot_json'] ?? null;

            // 2. COMPOSER: Merakit Narasi Deskripsi Otomatis
            $description = $this->compose_description($action_type, $params);

            // 3. POSTING DEBET (Sisi Penerimaan Barang/Biaya)
            // Mengambil akun 'inventory' secara dinamis dari MC-28
            $debit_account = ($action_type === 'procurement') ? puri_gl('inventory') : puri_gl('cogs');

            $wpdb->insert($table_journal, [
                'trx_date'      => $trx_date,
                'ref_id'        => $ref_id,
                'account_code'  => $debit_account,
                'debit'         => $total_idr,
                'credit'        => 0,
                'description'   => $description,
                'snapshot_json' => $snapshot, // Audit Trail hanya di baris pertama
                'created_by'    => $created_by
            ]);

            // 4. POSTING KREDIT (Sisi Pembayaran - Cash Based)
            // Meloop rincian pembayaran dari nampan MC-04
            if (!empty($params['payments'])) {
                foreach ($params['payments'] as $pay) {
                    $wpdb->insert($table_journal, [
                        'trx_date'      => $trx_date,
                        'ref_id'        => $ref_id,
                        'account_code'  => $pay['account_code'], // Akun Kas/Bank pilihan user
                        'debit'         => 0,
                        'credit'        => floatval($pay['amount']),
                        'description'   => $description,
                        'snapshot_json' => null,
                        'created_by'    => $created_by
                    ]);
                }
            }

            return true;
        }

        /**
         * Private Composer: Meracik teks deskripsi dari nampan
         */
        private function compose_description($action_type, $params) {
            $vendor_label = $params['counterparty_name'] ?? 'Vendor';
            $vendor_code  = $params['counterparty_id'] ?? 'N/A';
            
            // Ekstrak rincian item (Contoh: "1.000 SAR 100, 500 SAR 50")
            $item_parts = [];
            if (!empty($params['items'])) {
                foreach ($params['items'] as $it) {
                    // Menggunakan SKU dari nampan MC-04
                    $qty_fmt = number_format($it['qty'], 0, ',', '.');
                    $item_parts[] = "{$qty_fmt} {$it['sku']}";
                }
            }

            $prefix = ($action_type === 'procurement') ? "Kulakan dr" : "Penjualan ke";
            $main_desc = "{$prefix} {$vendor_label} [{$vendor_code}]: " . implode(', ', $item_parts);

            // Tambahkan catatan manual jika ada
            if (!empty($params['description'])) {
                // Membersihkan prefix ref_id jika sudah ada agar tidak double
                $clean_note = str_replace("[{$params['source_ref']}] ", "", $params['description']);
                $main_desc .= " | Note: " . $clean_note;
            }

            return $main_desc;
        }

        /**
         * Reversal Journal (Untuk pembatalan transaksi)
         */
        public function post_reversal($params) {
            // Logika reversal akan membalik posisi debet dan kredit dari snapshot_json
            // Akan diimplementasikan pada fase berikutnya.
            return true;
        }
    }
}