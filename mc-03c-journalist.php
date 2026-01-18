<?php
/**
 * MC-03C - ACCOUNTING JOURNAL SPECIALIST (MOZART COMPATIBLE)
 * @version 7.2.0
 * Responsibilities:
 * - Menentukan pemetaan akun GL (Chart of Accounts)
 * - Eksekusi Double-Entry (Debit/Kredit) ke T_JOURNAL
 * - Mendukung multi-channel payment (Kas, Bank, atau Hutang)
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Accounting_Journal_V7')) {
    class PURI_Accounting_Journal_V7 {
        
        /**
         * Entry Point: Mapping Action ke Akun GL
         */
        public function post_to_gl($action, $p) {
            // Hitung total nilai transaksi (Qty x Harga)
            $amount = abs($p['qty'] * ($p['unit_price'] ?? 0));
            
            // Abaikan jika nilai 0 (mencegah sampah di jurnal)
            if ($amount <= 0) return;

            $inventory_acc = puri_gl('inventory'); // Default: 1401 (Persediaan)
            $target_acc    = '';

            switch ($action) {
                // Skenario 1: Opname via Investigasi (Gantung di Suspense)
                case 'adjustment_indirect': 
                    $target_acc = puri_gl('suspense'); 
                    break;
                
                // Skenario 2: Opname Langsung (Masuk ke Pendapatan/Beban Selisih)
                case 'adjustment_direct':   
                    $target_acc = ($p['qty'] > 0) ? puri_gl('opname_gain') : puri_gl('opname_loss'); 
                    break;
                
                // Skenario 3: Procurement (Kulakan dari Vendor)
                case 'procurement':         
                    // Cek apakah ada kiriman akun pembayaran spesifik (Kas/Bank)
                    // Jika tidak ada, default ke Hutang Dagang (AP Trade)
                    $target_acc = isset($p['payment_account']) ? $p['payment_account'] : puri_gl('ap_trade'); 
                    break;
                
                // Skenario 4: Penjualan/Buyback di POS
                case 'sales':               
                case 'buyback':
                    $target_acc = puri_gl('cogs'); // Lawannya adalah Harga Pokok Penjualan
                    break;

                default: 
                    throw new Exception("Mapping GL tidak ditemukan untuk aksi: " . $action);
            }

            // EKSEKUSI JURNAL (Baris yang Anda cari)
            $this->commit_double_entry(
                $p['ref_no'], 
                $inventory_acc, 
                $target_acc, 
                $amount, 
                $p['qty'], 
                $p['description']
            );
        }

        /**
         * Reversal Logic (Untuk pembatalan transaksi)
         */
        public function post_reversal($p) {
            $date = current_time('mysql');
            $amount = abs($p['amount']);
            $suspense = puri_gl('suspense');
            $real_acc = ($p['is_gain']) ? puri_gl('opname_gain') : puri_gl('opname_loss');
            $ref_rev = "REV-" . $p['original_ref'];
            $desc = "[REVERSAL] " . ($p['description'] ?? "Koreksi Investigasi");

            if ($p['is_gain']) {
                puri_insert_journal($date, $ref_rev, $suspense, $amount, 0, $desc);
                puri_insert_journal($date, $ref_rev, $real_acc, 0, $amount, $desc);
            } else {
                puri_insert_journal($date, $ref_rev, $real_acc, $amount, 0, $desc);
                puri_insert_journal($date, $ref_rev, $suspense, 0, $amount, $desc);
            }
        }

        /**
         * THE DOUBLE ENTRY EXECUTOR
         * Logika Debit/Kredit otomatis berdasarkan tanda Qty (+/-)
         */
        private function commit_double_entry($ref, $inv_acc, $target_acc, $val, $qty, $desc) {
            $date = current_time('mysql');
            
            /**
             * ATURAN MAIN:
             * Jika Qty Positif (Barang Masuk / Kulakan):
             * - DEBIT  : Persediaan (Aset bertambah)
             * - KREDIT : Kas/Hutang/Lawan (Aset berkurang/Kewajiban bertambah)
             */
            if ($qty > 0) {
                // Debit Inventory
                puri_insert_journal($date, $ref, $inv_acc, $val, 0, $desc);
                // Kredit Lawannya
                puri_insert_journal($date, $ref, $target_acc, 0, $val, $desc);
            } 
            
            /**
             * Jika Qty Negatif (Barang Keluar / Penjualan):
             * - DEBIT  : Lawan/COGS (Biaya bertambah)
             * - KREDIT : Persediaan (Aset berkurang)
             */
            else {
                // Debit Lawannya (HPP)
                puri_insert_journal($date, $ref, $target_acc, $val, 0, $desc);
                // Kredit Inventory
                puri_insert_journal($date, $ref, $inv_acc, 0, $val, $desc);
            }
        }
    }
}