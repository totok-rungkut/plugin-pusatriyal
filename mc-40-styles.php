<?php
/**
 * ============================================================================
 * MC-40 STYLES - Stock Transfer UI Stylesheet
 * ============================================================================
 * @package     Pusat Riyal Smart Suite
 * @version     1.0.0
 * @description Clean, professional SaaS-style CSS untuk Transfer Stock Module
 * ============================================================================
 */

defined('ABSPATH') || exit;

function puri_transfer_styles() {
    ?>
    <style>
        /* ================================================================
         * ROOT VARIABLES
         * ================================================================ */
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* ================================================================
         * LAYOUT STRUCTURE
         * ================================================================ */
        .puri-transfer-wrapper {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text-main);
            max-width: 96%;
            margin: 20px auto;
        }
        
        .puri-transfer-wrapper h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            font-size: 28px;
        }
        
        .version-badge {
            font-size: 12px;
            background: var(--bg-light);
            padding: 4px 10px;
            border-radius: 12px;
            color: var(--text-muted);
            font-weight: normal;
        }
        
        /* ================================================================
         * ROW LAYOUTS
         * ================================================================ */
        .transfer-row-top {
            display: grid;
            grid-template-columns: 65% 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .transfer-row-bottom {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        /* ================================================================
         * CARD COMPONENTS
         * ================================================================ */
        .transfer-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 20px;
            transition: box-shadow 0.2s;
        }
        
        .transfer-card:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: var(--bg-light);
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            flex: 1;
        }
        
        .card-header .icon {
            font-size: 20px;
        }
        
        .card-header.cart-header {
            justify-content: space-between;
        }
        
        .cart-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
            min-height: 100px;
        }
        
        .card-footer {
            padding: 15px 20px;
            background: var(--bg-light);
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        /* ================================================================
         * FORM CONTROLS
         * ================================================================ */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-grid-3 {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            align-items: start;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            font-size: 13px;
            color: var(--text-main);
        }
        
        .puri-select,
        .puri-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .puri-select:focus,
        .puri-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .helper-text {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .helper-text.error {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        .helper-text.success {
            color: var(--success-color);
            font-weight: 600;
        }
        
        /* ================================================================
         * BUTTONS
         * ================================================================ */
        .btn-add-cart,
        .btn-execute {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-add-cart {
            background: var(--success-color);
            color: white;
            width: 100%;
        }
        
        .btn-add-cart:hover:not(:disabled) {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }
        
        .btn-add-cart:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .btn-execute {
            background: var(--primary-color);
            color: white;
            width: 100%;
            padding: 12px 20px;
            font-size: 15px;
        }
        
        .btn-execute:hover:not(:disabled) {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }
        
        .btn-execute:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .btn-wrapper {
            display: flex;
            align-items: flex-end;
			margin: auto;
        }
        
        /* ================================================================
         * CART COMPONENTS
         * ================================================================ */
        .cart-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            font-style: italic;
            font-size: 13px;
        }
        
        .cart-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .cart-item:hover {
            background: #f1f5f9;
        }
        
        .cart-item-info {
            flex: 1;
        }
        
        .cart-item-sku {
            font-weight: 600;
            color: var(--text-main);
            font-size: 14px;
        }
        
        .cart-item-qty {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 2px;
        }
        
        .cart-item-remove {
            background: var(--danger-color);
            color: white;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cart-item-remove:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        .confirm-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }
        
        .confirm-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        /* ================================================================
         * STOCK DISPLAY
         * ================================================================ */
        .stock-display {
            min-height: 200px;
        }
        
        .placeholder-text {
            text-align: center;
            color: var(--text-muted);
            padding: 60px 20px;
            font-style: italic;
            font-size: 13px;
        }
        
        .stock-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .stock-table thead {
            background: var(--bg-light);
        }
        
        .stock-table th {
            padding: 10px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            border-bottom: 2px solid var(--border-color);
        }
        
        .stock-table td {
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }
        
        .stock-table tbody tr:hover {
            background: var(--bg-light);
        }
        
        .stock-sku {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .stock-balance {
            font-weight: 600;
        }
        
        .stock-balance.low {
            color: var(--danger-color);
        }
        
        .stock-balance.ok {
            color: var(--success-color);
        }
        
        /* ================================================================
         * LOADING STATE
         * ================================================================ */
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .loading::after {
            content: '⏳ Loading...';
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* ================================================================
         * RESPONSIVE DESIGN
         * ================================================================ */
        @media (max-width: 1200px) {
            .transfer-row-top {
                grid-template-columns: 60% 40%;
            }
        }
        
        @media (max-width: 992px) {
            .transfer-row-top,
            .transfer-row-bottom {
                grid-template-columns: 1fr;
            }
            
            .form-grid-3 {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <?php
}