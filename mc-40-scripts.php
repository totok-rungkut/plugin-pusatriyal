<?php
/**
 * ============================================================================
 * MC-40 SCRIPTS - Stock Transfer JavaScript Controller
 * ============================================================================
 * @package     Pusat Riyal Smart Suite
 * @version     1.0.0
 * @description ES6 Class-based Stock Transfer Manager dengan AJAX real-time
 * ============================================================================
 */

defined('ABSPATH') || exit;

function puri_transfer_scripts() {
    $ajax_nonce = wp_create_nonce('puri_transfer_nonce');
    ?>
    <script>
    /**
     * ========================================================================
     * PURI STOCK TRANSFER MANAGER (ES6 Class)
     * ========================================================================
     * Handles all client-side logic for stock transfer operations including:
     * - Cart management
     * - Stock availability checking
     * - Real-time AJAX stock display updates
     * - Form validation
     * ========================================================================
     */
    class StockTransferManager {
        constructor() {
            // State Management
            this.cart = [];
            this.sourceLocation = null;
            this.targetLocation = null;
            this.selectedItem = null;
            
            // AJAX Configuration
            this.ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            this.nonce = '<?php echo $ajax_nonce; ?>';
            
            // Initialize
            this.init();
        }
        
        /**
         * Initialize Event Listeners
         */
        init() {
            // Location Change Events
            jQuery('#source_location').on('change', (e) => {
                this.sourceLocation = e.target.value;
                const selectedOption = e.target.selectedOptions[0];
                const locationName = selectedOption ? selectedOption.textContent : 'Source Location';
                
                jQuery('#source_label').text(locationName);
                this.refreshSourceStock();
                this.validateLocations();
                this.updateButtonState();
            });
            
            jQuery('#target_location').on('change', (e) => {
                this.targetLocation = e.target.value;
                const selectedOption = e.target.selectedOptions[0];
                const locationName = selectedOption ? selectedOption.textContent : 'Target Location';
                
                jQuery('#target_label').text(locationName);
                this.refreshTargetStock();
                this.validateLocations();
                this.updateButtonState();
            });
            
            // Item Selection Event
            jQuery('#item_select').on('change', (e) => {
                this.selectedItem = this.getSelectedItemData();
                this.checkAvailability();
                this.updateButtonState();
            });
            
            // Quantity Input Event
            jQuery('#qty_input').on('input', () => {
                this.checkAvailability();
                this.updateButtonState();
            });
            
            // Add to Cart Button
            jQuery('#btn_add_to_cart').on('click', () => this.addToCart());
            
            // Confirm Checkbox
            jQuery('#confirm_data').on('change', () => this.updateButtonState());
            
            // Form Submit Validation
            jQuery('#transfer-form').on('submit', (e) => this.handleSubmit(e));
        }
        
        /**
         * Get Selected Item Data from Dropdown
         */
        getSelectedItemData() {
            const select = document.getElementById('item_select');
            const option = select.selectedOptions[0];
            
            if (!option || !option.value) return null;
            
            return {
                item_id: parseInt(option.value),
                wp_post_id: parseInt(option.dataset.wpId),
                sku: option.dataset.sku,
                name: option.dataset.name,
                denom: parseInt(option.dataset.denom)
            };
        }
        
        /**
         * Check Stock Availability via AJAX
         */
        async checkAvailability() {
            if (!this.selectedItem || !this.sourceLocation) {
                jQuery('#qty_helper').text('Stok tersedia: -').removeClass('error success');
                return;
            }
            
            const qty = parseInt(jQuery('#qty_input').val()) || 0;
            
            if (qty < 100 || qty % 100 !== 0) {
                jQuery('#qty_helper')
                    .text('⚠️ Qty harus kelipatan 100')
                    .removeClass('success').addClass('error');
                return;
            }
            
            try {
                const response = await jQuery.ajax({
                    url: this.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'puri_check_stock_availability',
                        nonce: this.nonce,
                        item_id: this.selectedItem.item_id,
                        location_id: this.sourceLocation,
                        qty: qty
                    }
                });
                
                if (response.success) {
                    const { available, is_sufficient } = response.data;
                    const helperEl = jQuery('#qty_helper');
                    
                    if (is_sufficient) {
                        helperEl
                            .text(`✓ Stok tersedia: ${this.formatNumber(available)} pcs`)
                            .removeClass('error').addClass('success');
                    } else {
                        helperEl
                            .text(`✗ Stok tidak cukup! Tersedia: ${this.formatNumber(available)} pcs`)
                            .removeClass('success').addClass('error');
                    }
                }
            } catch (error) {
                console.error('Check availability error:', error);
            }
        }
        
        /**
         * Add Item to Cart
         */
        addToCart() {
            if (!this.selectedItem) {
                alert('Pilih item terlebih dahulu');
                return;
            }
            
            const qty = parseInt(jQuery('#qty_input').val()) || 0;
            
            // Validation
            if (qty < 100 || qty % 100 !== 0) {
                alert('Qty harus kelipatan 100 pcs');
                return;
            }
            
            // Check if item already in cart
            const existingIndex = this.cart.findIndex(
                item => item.item_id === this.selectedItem.item_id
            );
            
            if (existingIndex >= 0) {
                // Update qty
                this.cart[existingIndex].qty += qty;
            } else {
                // Add new item
                this.cart.push({
                    ...this.selectedItem,
                    qty: qty
                });
            }
            
            // Reset form
            jQuery('#item_select').val('');
            jQuery('#qty_input').val('');
            jQuery('#qty_helper').text('Stok tersedia: -').removeClass('error success');
            this.selectedItem = null;
            
            // Update UI
            this.renderCart();
            this.updateButtonState();
        }
        
        /**
         * Remove Item from Cart
         */
        removeFromCart(index) {
            this.cart.splice(index, 1);
            this.renderCart();
            this.updateButtonState();
        }
        
        /**
         * Render Cart UI
         */
        renderCart() {
            const container = jQuery('#cart_container');
            const cartCount = jQuery('#cart_count');
            
            // Update cart count
            cartCount.text(`${this.cart.length} item${this.cart.length !== 1 ? 's' : ''}`);
            
            if (this.cart.length === 0) {
                container.html(`
                    <div class="cart-empty">
                        <p>Keranjang masih kosong. Tambahkan item untuk transfer.</p>
                    </div>
                `);
                return;
            }
            
            let html = '<div class="cart-items">';
            
            this.cart.forEach((item, index) => {
                html += `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-sku">${item.sku}</div>
                            <div class="cart-item-qty">Qty: ${this.formatNumber(item.qty)} pcs</div>
                        </div>
                        <button type="button" class="cart-item-remove" data-index="${index}">×</button>
                    </div>
                    <input type="hidden" name="items[${index}][item_id]" value="${item.item_id}">
                    <input type="hidden" name="items[${index}][qty]" value="${item.qty}">
                `;
            });
            
            html += '</div>';
            container.html(html);
            
            // Attach remove event
            jQuery('.cart-item-remove').on('click', (e) => {
                const index = parseInt(jQuery(e.currentTarget).data('index'));
                this.removeFromCart(index);
            });
        }
        
        /**
         * Refresh Source Location Stock Display
         */
        async refreshSourceStock() {
            if (!this.sourceLocation) {
                jQuery('#source_stock_display').html(
                    '<p class="placeholder-text">Pilih Source Location untuk melihat stok</p>'
                );
                return;
            }
            
            jQuery('#source_stock_display').html('<div class="loading"></div>');
            
            try {
                const response = await jQuery.ajax({
                    url: this.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'puri_get_stock_by_location',
                        nonce: this.nonce,
                        location_id: this.sourceLocation
                    }
                });
                
                if (response.success) {
                    this.renderStockTable(response.data.stocks, '#source_stock_display');
                }
            } catch (error) {
                console.error('Refresh source stock error:', error);
                jQuery('#source_stock_display').html(
                    '<p class="placeholder-text error">Error loading stock data</p>'
                );
            }
        }
        
        /**
         * Refresh Target Location Stock Display
         */
        async refreshTargetStock() {
            if (!this.targetLocation) {
                jQuery('#target_stock_display').html(
                    '<p class="placeholder-text">Pilih Target Location untuk melihat stok</p>'
                );
                return;
            }
            
            jQuery('#target_stock_display').html('<div class="loading"></div>');
            
            try {
                const response = await jQuery.ajax({
                    url: this.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'puri_get_stock_by_location',
                        nonce: this.nonce,
                        location_id: this.targetLocation
                    }
                });
                
                if (response.success) {
                    this.renderStockTable(response.data.stocks, '#target_stock_display');
                }
            } catch (error) {
                console.error('Refresh target stock error:', error);
                jQuery('#target_stock_display').html(
                    '<p class="placeholder-text error">Error loading stock data</p>'
                );
            }
        }
        
        /**
         * Render Stock Table
         */
        renderStockTable(stocks, targetSelector) {
            if (!stocks || stocks.length === 0) {
                jQuery(targetSelector).html(
                    '<p class="placeholder-text">Tidak ada stok di lokasi ini</p>'
                );
                return;
            }
            
            let html = `
                <table class="stock-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Nama Item</th>
                            <th>Bendel (100 pcs)</th>
                            <th>Total Pieces</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            stocks.forEach(stock => {
                const balance = parseFloat(stock.balance);
                const bundleCount = Math.floor(balance / 100);
                const balanceClass = balance > 0 ? 'ok' : 'low';
                
                html += `
                    <tr>
                        <td><span class="stock-sku">${stock.sku}</span></td>
                        <td>${stock.name}</td>
                        <td>${this.formatNumber(bundleCount)} bendel</td>
                        <td><span class="stock-balance ${balanceClass}">${this.formatNumber(balance)} pcs</span></td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            jQuery(targetSelector).html(html);
        }
        
        /**
         * Validate Locations (Source != Target)
         */
        validateLocations() {
            if (!this.sourceLocation || !this.targetLocation) return true;
            
            if (this.sourceLocation === this.targetLocation) {
                alert('Source dan Target location tidak boleh sama!');
                jQuery('#target_location').val('');
                this.targetLocation = null;
                return false;
            }
            
            return true;
        }
        
        /**
         * Update Button States
         */
        updateButtonState() {
            // Add to Cart Button
            const canAddToCart = this.selectedItem && 
                                this.sourceLocation && 
                                parseInt(jQuery('#qty_input').val() || 0) >= 100;
            
            jQuery('#btn_add_to_cart').prop('disabled', !canAddToCart);
            
            // Execute Button
            const canExecute = this.cart.length > 0 && 
                              this.sourceLocation && 
                              this.targetLocation && 
                              jQuery('#confirm_data').is(':checked');
            
            jQuery('#btn_execute').prop('disabled', !canExecute);
        }
        
        /**
         * Handle Form Submit
         */
        handleSubmit(e) {
            if (this.cart.length === 0) {
                e.preventDefault();
                alert('Keranjang kosong! Tambahkan item terlebih dahulu.');
                return false;
            }
            
            if (!jQuery('#confirm_data').is(':checked')) {
                e.preventDefault();
                alert('Harap konfirmasi bahwa data sudah benar sebelum execute.');
                return false;
            }
            
            // Show loading state
            const btnExecute = jQuery('#btn_execute');
            btnExecute.prop('disabled', true);
            btnExecute.html('<span class="icon">⏳</span> Processing...');
            
            return true;
        }
        
        /**
         * Format Number with Thousand Separator
         */
        formatNumber(num) {
            return new Intl.NumberFormat('id-ID').format(num || 0);
        }
    }
    
    /**
     * ========================================================================
     * INITIALIZE ON DOM READY
     * ========================================================================
     */
    jQuery(document).ready(function() {
        // Instantiate Stock Transfer Manager
        window.stockTransferManager = new StockTransferManager();
        
        console.log('✅ MC-40 Stock Transfer Manager initialized');
    });
    </script>
    <?php
}
?>