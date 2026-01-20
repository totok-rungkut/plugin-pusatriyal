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

                // 1. RESOLUSI ARAH & LABEL (PSAK COMPLIANT)
                $action     = $params['source'] ?? ''; 
                $is_buyback = $params['is_buyback'] ?? false;
                
                // Tentukan Prefix & Arah Mutasi
                $prefix = "Procurement"; // Default
                $direction = 1;          // Default Bertambah (+)

                if ($action === 'pos_submission') {
                    if ($is_buyback) {
                        $prefix = "POS Buyback";
                        $direction = 1;  // Buyback = Barang Masuk (+)
                    } else {
                        $prefix = "POS Sales";
                        $direction = -1; // Sales = Barang Keluar (-)
                    }
                }

                // 2. HITUNG PERUBAHAN QTY (DIKALI ARAH)
                $qty_change = $qty * $direction;
                $item_name = $it['name'] ?? '';   //-- ambil dari nampan, kalau gak ada ambil dari tabel
                if (empty($item_name)) {
                    $item_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT name FROM " . puri_table_name('T_ITEMS') . " WHERE id = %d", $item_id
                    )) ?: 'Unknown Item #' . $item_id;
                }
                // 3. COMPOSER DESKRIPSI LEDGER
                $mutation_desc = sprintf(
                    "%s: %s (Qty: %s)", 
                    $prefix,
                    $item_name, 
                    number_format($qty, 0, ',', '.')
                );

                if (!empty($params['description'])) {
                    $mutation_desc .= " | " . $params['description'];
                }

                // 4. INSERT HISTORY KE T_LEDGER
                $wpdb->insert($table_ledger, [
                    'trx_date'    => $params['created_at'] ?? current_time('mysql'),
                    'location_id' => $loc_id,
                    'item_id'     => $item_id,
                    'qty_change'  => $qty_change, // Sekarang sudah benar (+/-)
                    'trx_type'    => $action,
                    'ref_id'      => $params['source_ref'],
                    'description' => $mutation_desc
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