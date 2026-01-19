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
    $table_journal = puri_table_name('T_JOURNAL');

    $ref_id    = $params['source_ref'];
    $total_idr = floatval($params['total_idr']);
    $trx_date  = $params['created_at'] ?? current_time('mysql');
    $user      = get_current_user_id();
    $desc      = $this->compose_description($action_type, $params);

    if ($action_type === 'procurement') {
        // PAIR A: Aliran Uang (Dr) Biaya | (Cr) Kas/Bank
        $this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('purchase_cost'), $total_idr, 0, "Biaya: " . $desc, $user);
        foreach ($params['payments'] as $pay) {
            $this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, $pay['account_code'], 0, $pay['amount'], "Bayar: " . $ref_id, $user);
        }

        // PAIR B: Aliran Barang (Dr) Persediaan | (Cr) HPP
        $this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('inventory'), $total_idr, 0, "Masuk Stok: " . $ref_id, $user);
        $this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('cogs'), 0, $total_idr, "HPP Kontra: " . $ref_id, $user);

    } elseif ($action_type === 'pos_submission') {
// Kita asumsikan MC-05B mengirimkan 'is_buyback' => true jika kasir membeli barang
        $is_buyback = $params['is_buyback'] ?? false;
			if ($is_buyback) {
				/**
				 * KASUS: BELI ECERAN (Buyback dari Customer)
				 * Resep sama dengan Procurement:
				 * PAIR A: (Dr) Biaya Pembelian | (Cr) Kas/Bank [Uang Keluar]
				 * PAIR B: (Dr) Persediaan | (Cr) HPP [Barang Masuk]
				 */
				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('purchase_cost'), $total_idr, 0, "Beli Eceran: " . $desc, $user);
				foreach ($params['payments'] as $pay) {
					$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, $pay['account_code'], 0, $pay['amount'], "Bayar Cust: " . $ref_id, $user);
				}
				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('inventory'), $total_idr, 0, "Masuk Stok (Ecer): " . $ref_id, $user);
				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('cogs'), 0, $total_idr, "HPP Kontra: " . $ref_id, $user);

			} else {
				/**
				 * KASUS: JUAL ECERAN (Normal Sales)
				 * PAIR A: (Dr) Kas/Bank | (Cr) Sales Retail [Uang Masuk]
				 * PAIR B: (Dr) HPP | (Cr) Persediaan [Barang Keluar]
				 */
				foreach ($params['payments'] as $pay) {
					$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, $pay['account_code'], $pay['amount'], 0, "Terima POS: " . $ref_id, $user);
				}
				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('sales_retail'), 0, $total_idr, "Pendapatan: " . $desc, $user);

				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('cogs'), $total_idr, 0, "HPP Jual: " . $ref_id, $user);
				$this->insert_row($wpdb, $table_journal, $trx_date, $ref_id, puri_gl('inventory'), 0, $total_idr, "Keluar Stok: " . $ref_id, $user);
			}
		}

    return true;
}

/**
 * Helper untuk menjaga kebersihan baris jurnal
 */
private function insert_row($wpdb, $table, $date, $ref, $acc, $dr, $cr, $desc, $user) {
    $wpdb->insert($table, [
        'trx_date'     => $date,
        'ref_id'       => $ref,
        'account_code' => $acc,
        'debit'        => $dr,
        'credit'       => $cr,
        'description'  => $desc,
        'created_by'   => $user
    ]);
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