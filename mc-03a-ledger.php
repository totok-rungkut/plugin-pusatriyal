<?php
/**
 * MC-03A - INVENTORY LEDGER SPECIALIST (MOZART COMPATIBLE)
 * @version 7.2.0
 * Responsibilities:
 * - Mencatat setiap mutasi stok ke Kartu Stok (T_LEDGER)
 * - Mendukung pelacakan lokasi (Location-aware)
 * - Menjaga audit trail transaksi (siapa, kapan, di mana)
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Inventory_Ledger_V7')) {
    class PURI_Inventory_Ledger_V7 {

        /**
         * Mencatat Mutasi ke Kartu Stok
         */
        public function record_mutation($p) {
            global $wpdb;
            
            // PENYESUAIAN 1: Dukungan Lokasi
            // Jika tidak ada lokasi, default ke 'laci_kasir' (seperti di MC-03B)
            $location = isset($p['location_id']) ? $p['location_id'] : 'laci_kasir';

            return $wpdb->insert(puri_table_name('T_LEDGER'), [
                'item_id'     => $p['item_id'],
                'location_id' => $location, // Tambahan: Agar kartu stok per gudang akurat
                'qty_change'  => floatval($p['qty']),
                'ref_id'      => $p['ref_id'],
                'description' => $p['description'] ?? 'System Movement',
                'created_at'  => current_time('mysql'),
                'user_id'     => get_current_user_id()
            ]);
        }
    }
}
