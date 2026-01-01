<?php
/**
 * MC 23 - Reserved / Admin Welcome (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Reserved module (alias to Admin Hub / welcome screen)
 *  - Kept for backward compatibility in menu flows where MC 00 previously duplicated
 *
 * Implementation:
 *  - Simple alias to puri_render_welcome_screen() from MC 00
 */

defined('ABSPATH') || exit;

function puri_render_reserved_page() {
    puri_check_cap('manage_options');
    // Reuse welcome screen implementation
    if (function_exists('puri_render_welcome_screen')) {
        puri_render_welcome_screen();
    } else {
        echo '<div class="wrap"><h1>Pusat Riyal</h1><p>Portal admin — modul inti belum dimuat.</p></div>';
    }
}