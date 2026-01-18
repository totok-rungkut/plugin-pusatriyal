<?php
/**
 * MC-03C - ACCOUNTING JOURNAL SPECIALIST (MOZART COMPATIBLE)
 * @version 7.4.0 (Standardized ref_id)
 * Responsibilities:
 * - Menentukan pemetaan akun GL (Chart of Accounts)
 * - Eksekusi Double-Entry (Debit/Kredit) ke T_JOURNAL (puri_journal_entries)
 * - Helper function untuk insert journal guna mencegah Fatal Error
 */

defined('ABSPATH') || exit;

/**
 * HELPER: Menjamin fungsi insert journal selalu tersedia untuk Mozart
 */
if (!function_exists('puri_insert_journal')) {
    function puri_insert_journal($date, $ref_id, $acc, $debit, $credit, $desc) {
        global $wpdb;
        return $wpdb->insert(puri_table_name('T_JOURNAL'), [
            'trx_date'     => $date,
            'ref_id'       => $ref_id, // Standar DB: ref_id
            'account_code' => $acc,
            'debit'        => $debit,
            'credit'       => $credit,
            'description'  => $desc
        ]);
    }
}

if (!class_exists('PURI_Accounting_Journal_V7')) {
    class PURI_Accounting_Journal_V7 {
        
        /**
         * Entry Point: Mapping Action ke Akun GL
         */
        public function post_to_gl($action, $p) {
            // Hitung total nilai transaksi (Qty x Harga)
            $amount = abs($p['qty'] * ($p['unit_price'] ?? 0));
            
            // Keamanan: Abaikan jika nilai 0 atau data referensi tidak ada
            if ($amount <= 0 || empty($p['ref_id'])) return;

            $inventory_acc = puri_gl('inventory'); // Default: 1401
            $target_acc    = '';

            switch ($action) {
                case 'adjustment_indirect': 
                    $target_acc = puri_gl('suspense'); 
                    break;
                
                case 'adjustment_direct':   
                    $target_acc = ($p['qty'] > 0) ? puri_gl('opname_gain') : puri_gl('opname_loss'); 
                    break;
                
                case 'procurement':         
                    $target_acc = $p['payment_account'] ?? puri_gl('ap_trade'); 
                    break;
                
                case 'sales':                
                case 'buyback':
                    $target_acc = puri_gl('cogs');
                    break;

                default: 
                    throw new Exception("Mozart Journal Error: Mapping GL tidak ditemukan untuk aksi: " . $action);
            }

            // EKSEKUSI JURNAL (Standar ref_id)
            $this->commit_double_entry(
                $p['ref_id'], 
                $inventory_acc, 
                $target_acc, 
                $amount, 
                $p['qty'], 
                $p['description']
            );
        }

        /**
         * Reversal Logic (Untuk pembatalan transaksi dari Investigasi)
         */
        public function post_reversal($p) {
            $date     = current_time('mysql');
            $amount   = abs($p['amount']);
            $suspense = puri_gl('suspense');
            $real_acc = ($p['is_gain']) ? puri_gl('opname_gain') : puri_gl('opname_loss');
            
            // Konsistensi: Selalu gunakan ref_id
            $ref_id   = "REV-" . ($p['ref_id'] ?? 'UNKNOWN');
            $desc     = "[REVERSAL] " . ($p['description'] ?? "Koreksi Investigasi");

            if ($p['is_gain']) {
                puri_insert_journal($date, $ref_id, $suspense, $amount, 0, $desc);
                puri_insert_journal($date, $ref_id, $real_acc, 0, $amount, $desc);
            } else {
                puri_insert_journal($date, $ref_id, $real_acc, $amount, 0, $desc);
                puri_insert_journal($date, $ref_id, $suspense, 0, $amount, $desc);
            }
        }

        /**
         * THE DOUBLE ENTRY EXECUTOR
         */
        private function commit_double_entry($ref_id, $inv_acc, $target_acc, $val, $qty, $desc) {
            $date = current_time('mysql');
            
            if ($qty > 0) {
                // Barang Masuk: Persediaan (D), Kas/Hutang (K)
                puri_insert_journal($date, $ref_id, $inv_acc, $val, 0, $desc);
                puri_insert_journal($date, $ref_id, $target_acc, 0, $val, $desc);
            } else {
                // Barang Keluar: HPP (D), Persediaan (K)
                puri_insert_journal($date, $ref_id, $target_acc, $val, 0, $desc);
                puri_insert_journal($date, $ref_id, $inv_acc, 0, $val, $desc);
            }
        }
    }
}