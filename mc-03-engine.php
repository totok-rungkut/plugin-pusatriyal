<?php
/**
 * MC 03 - PURI Engine V7 (MOZART)
 * Version: 7.4.0 (MC-40 Transfer Support)
 * 
 * CHANGELOG v7.4.0:
 * - Added stock_transfer action handler
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/mc-03a-ledger.php';
require_once __DIR__ . '/mc-03b-stocker.php';
require_once __DIR__ . '/mc-03c-journalist.php';

if (!class_exists('PURI_Engine_Mozart')) {
    class PURI_Engine_Mozart {
        protected $ledger;
        protected $stocker;
        protected $journalist;

        public function __construct() {
            $this->ledger     = new PURI_Inventory_Ledger_V7();
            $this->stocker    = new PURI_Stock_Controller_V7();
            $this->journalist = new PURI_Accounting_Journal_V7();
        }

        public function execute($action_type, $params) {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                // 1. Store JSON snapshot
                $this->store_json($action_type, $params);

                // 2. Execute based on action type
                if ($action_type === 'stock_transfer') {
                    // SPECIAL: Transfer doesn't need journal posting
                    $this->stocker->update_balance($action_type, $params);
                    $this->ledger->record_mutation($params);
                    // NO journalist call - pure inventory movement
                } else {
                    // Normal flow: procurement, pos_submission
                    $this->stocker->update_balance($action_type, $params);
                    $this->ledger->record_mutation($params);
                    $this->journalist->post_to_gl($action_type, $params);
                }

                $wpdb->query('COMMIT');
                return true;
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('execution_failed', $e->getMessage());
            }
        }

        private function store_json($action_type, $params) {
            global $wpdb;
            if (empty($params['snapshot_json'])) return;

            $wpdb->replace(puri_table_name('T_JSON'), [
                'ref_id'        => $params['source_ref'],
                'source_module' => $action_type,
                'snapshot_json' => $params['snapshot_json'],
                'created_at'    => $params['created_at'] ?? current_time('mysql')
            ]);
        }

        public function execute_reversal($params) {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                $this->journalist->post_reversal($params);
                $wpdb->query('COMMIT');
                return true;
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('reversal_failed', $e->getMessage());
            }
        }
    }
}

function puri_mozart() {
    static $instance = null;
    if (null === $instance) $instance = new PURI_Engine_Mozart();
    return $instance;
}