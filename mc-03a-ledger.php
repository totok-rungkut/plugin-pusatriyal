<?php
/**
 * =============================================================================
 * MC-03a - PURI Inventory Ledger (Mozart Bridge)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     7.3.14
 * @author      Mozart Engine Core
 * * Responsibilities:
 * - Mencatat riwayat mutasi stok barang ke tabel T_LEDGER.
 * - Menjaga audit trail pergerakan barang (Qty Change & Balance Snapshot).
 * - Sinkronisasi ref_id dengan Jurnal Umum untuk pelacakan lintas modul.
 * =============================================================================
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Inventory_Ledger_V7')) {
    class PURI_Inventory_Ledger_V7 {

        /**
         * Record Mutation
         * Mencatat setiap baris item dari nampan ke dalam sejarah ledger.
         */
        public function record_mutation($params) {
            global $wpdb;
            $table_ledger = puri_table_name('T_LEDGER'); // Sinkron MC-01: puri_inventory_ledger
			$loc_id       = $params['location_id'] ?? 'MAIN'; // Fallback
			
            if (empty($params['items'])) {
                return; // Guard clause jika tidak ada item
            }

            foreach ($params['items'] as $it) {
                $item_id = intval($it['item_id']);
                $qty     = floatval($it['qty']);

                if ($qty == 0) continue;

                // 1. COMPOSER DESKRIPSI LEDGER (Spesifik untuk Mutasi Barang)
                // Berbeda dengan jurnal, ledger fokus pada detail barang per baris.
                $mutation_desc = sprintf(
                    "Procurement: %s (Qty: %s)", 
                    $it['name'], 
                    number_format($qty, 0, ',', '.')
                );

                // Jika ada catatan tambahan dari nampan
                if (!empty($params['description'])) {
                    $mutation_desc .= " | " . $params['description'];
                }

                // 2. INSERT HISTORY
                // Menggunakan ref_id agar bisa di-trace ke T_JOURNAL
                $wpdb->insert($table_ledger, [
					'trx_date'    => $params['created_at'],
					'location_id' => $loc_id, // Sekarang dinamis dari nampan
					'item_id'     => intval($it['item_id']),
					'qty_change'  => floatval($it['qty']),
					'trx_type'    => $params['source'],
					'ref_id'      => $params['source_ref'],
					'description' => "Procurement to {$loc_id}: " . $it['name']
                ]);

                // Fail-safe check
                if ($wpdb->last_error) {
                    throw new Exception("Ledger Error: " . $wpdb->last_error);
                }
            }

            return true;
        }
    }
}