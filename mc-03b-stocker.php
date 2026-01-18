<?php
/**
 * MC-03B - STOCK & VALUATION (LITE SCALABLE)
 * @version 7.3.9
 * Logic: Base_price as Global HPP | Cost_avg as Reserved/Mirror
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Stock_Controller_V7')) {
    class PURI_Stock_Controller_V7 {

        public function update_balance($action, $p) {
            global $wpdb;
            $tbl_stock = puri_table_name('T_STOCK');
            $tbl_items = puri_table_name('T_ITEMS');

            $item_id  = intval($p['item_id']);
            $new_qty  = floatval($p['qty']);
            // Fallback location: Procurement -> Gudang | Others -> Laci
            $location = $p['location_id'] ?? (($action === 'procurement') ? 'gudang_utama' : 'laci_kasir');

            // 1. UPDATE STOK (Kolom 'qty')
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$tbl_stock} (item_id, location_id, qty, last_updated) 
                 VALUES (%d, %s, %f, NOW()) 
                 ON DUPLICATE KEY UPDATE qty = qty + %f, last_updated = NOW()",
                $item_id, $location, $new_qty, $new_qty
            ));

            // 2. KALKULASI MOVING AVERAGE (Hanya di Procurement)
            if ($action === 'procurement' && isset($p['unit_price'])) {
                $buy_price = floatval($p['unit_price']);

                // Ambil data Global HPP & Total Stok Nasional
                $data = $wpdb->get_row($wpdb->prepare(
                    "SELECT i.base_price, SUM(s.qty) as total_stock 
                     FROM {$tbl_items} i 
                     LEFT JOIN {$tbl_stock} s ON i.id = s.item_id 
                     WHERE i.id = %d GROUP BY i.id", $item_id
                ));

                $old_hpp = floatval($data->base_price ?? 0);
                $stock_after = floatval($data->total_stock ?? 0);
                
                // Hitung stok sebelum transaksi ini (karena INSERT sudah jalan di atas)
                $stock_before = max(0, $stock_after - $new_qty);

                // Rumus: (Nilai Stok Lama + Nilai Beli Baru) / Total Stok Baru
                $total_value = ($stock_before * $old_hpp) + ($new_qty * $buy_price);
                $new_hpp = ($stock_after > 0) ? ($total_value / $stock_after) : $buy_price;

                // 3. SINKRONISASI (Update Master & Mirror ke Stock)
                $wpdb->update($tbl_items, ['base_price' => $new_hpp], ['id' => $item_id]);
                
                // Mirror ke cost_avg agar jika nanti scalable, datanya tidak kosong
                $wpdb->update($tbl_stock, ['cost_avg' => $new_hpp], [
                    'item_id' => $item_id, 
                    'location_id' => $location
                ]);
            }
        }
    }
}