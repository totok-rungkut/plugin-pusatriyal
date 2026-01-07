<?php

/**
 * =============================================================================
 * MC 24 - User Role Manager (Custom Roles & Capabilities for Pusat Riyal)
 * =============================================================================
 *
 * @package     Pusat Riyal
 * @module      MC-24
 * @version     6.0.1
 * @author      Denmas Totok / Copilot
 * @updated     2026-01-05
 *
 * =============================================================================
 * PURPOSE
 * =============================================================================
 *
 * Menyediakan prosedur pendaftaran role dan capability khusus yang sesuai 
 * workflow operasional Pusat Riyal. Memastikan akses, otorisasi, dan 
 * granular permission berjalan melalui role wordpress yang terstruktur dan aman.
 *
 * - Mendaftarkan role: finance, kasir, admin_officer dengan matrix akses granular
 * - Mendefinisikan capabilities workflow: procurement, transfer, POS, expense dsb
 * - Assign seluruh capability ke administrator sebagai "owner"
 * - Helper untuk validasi authorizer (user berhak otorisasi PIN)
 *
 * =============================================================================
 * ROLE & CAPABILITY STRUCTURE
 * =============================================================================
 *
 *   - administrator (owner) : Semua capability, termasuk otorisasi seluruh aksi
 *   - finance               : Otorisasi procurement, transfer, POS, expense, recon, dsb
 *   - kasir                 : Performa POS dan transfer stok ke laci
 *   - admin_officer         : Penerimaan barang, request transfer, mengubah kurs, dsb
 *
 * Capability direpresentasi via constant yang mudah dipakai pada permission check:
 *   PURI_CAP_KULAKAN (Procurement), PURI_CAP_TRANSFER (Stock Transfer), dsb
 *
 * =============================================================================
 * HOW TO USE
 * =============================================================================
 *
 * - Termasuk otomatis pada module loader plugin. Tidak perlu dipanggil manual.
 * - Untuk permission check pada handler:
 *     if (!current_user_can('puri_can_procurement')) wp_die('Unauthorized');
 * - Untuk validasi role otorisasi (by PIN):
 *     puri_can_current_user_authorize();
 *
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 *
 * [6.0.1] 2026-01-05
 *   - Initial version: Custom role/cap struct & helper
 *
 * =============================================================================
 */
 
 
defined( 'ABSPATH' ) || exit;

// Definisikan cap granular agar mudah maintenance.
// Bisa tambahkan/ubah sesuai workflow baru di masa depan.
const PURI_CAP_KULAKAN    = 'puri_can_procurement';        // Akses/eksekusi Procurement/Kulakan (MC 04)
const PURI_CAP_TRANSFER   = 'puri_can_transfer_stock';     // Transfer Gudang ke Laci (MC 08)
const PURI_CAP_POS        = 'puri_can_pos';                // Jualan POS (MC 05)
const PURI_CAP_EXPENSE    = 'puri_can_expense';            // Input pengeluaran kas/bank (MC 10)
const PURI_CAP_OTORISASI  = 'puri_can_otorisasi';          // Melakukan otorisasi (by PIN/PW)
const PURI_CAP_REKON      = 'puri_can_rekonsiliasi';       // Rekonsiliasi bank/stock opname

add_action('init', function() {
    // =============== ROLE: FINANCE ===============
    if (!get_role('finance')) {
        add_role(
            'finance',
            __('Finance', 'puri'),
            array(
                'read'                 => true,
                PURI_CAP_KULAKAN      => true,
                PURI_CAP_TRANSFER     => true,
                PURI_CAP_POS          => true,
                PURI_CAP_EXPENSE      => true,
                PURI_CAP_OTORISASI    => true,
                PURI_CAP_REKON        => true,
                'edit_posts'          => false,
                'delete_posts'        => false,
            )
        );
    }
    // =============== ROLE: KASIR ===============
    if (!get_role('kasir')) {
        add_role(
            'kasir',
            __('Kasir', 'puri'),
            array(
                'read'                 => true,
                PURI_CAP_POS          => true,
                PURI_CAP_TRANSFER     => true,
                // Tidak ada otorisasi, tidak boleh rekon bank, tidak boleh kulakan, tidak expense
                'edit_posts'          => false,
                'delete_posts'        => false,
            )
        );
    }
    // =============== ROLE: ADMIN OFFICER ===============
    if (!get_role('admin_officer')) {
        add_role(
            'admin_officer',
            __('Admin Officer', 'puri'),
            array(
                'read'                 => true,
                PURI_CAP_KULAKAN      => true,
                PURI_CAP_TRANSFER     => true,
                PURI_CAP_REKON        => true,
                // Tidak boleh POS, tidak expense, tidak otorisasi
                'edit_posts'          => false,
                'delete_posts'        => false,
            )
        );
    }

    // =============== Assign capability to administrator (owner) ===============
    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->add_cap(PURI_CAP_KULAKAN);
        $admin_role->add_cap(PURI_CAP_TRANSFER);
        $admin_role->add_cap(PURI_CAP_POS);
        $admin_role->add_cap(PURI_CAP_EXPENSE);
        $admin_role->add_cap(PURI_CAP_OTORISASI);
        $admin_role->add_cap(PURI_CAP_REKON);
    }
});

// Helper: Array untuk validasi role/otorisasi di handler (misal: siapa yang boleh authorize by PIN)
function puri_valid_authorizer_roles() {
    return array('administrator', 'finance');
}

function puri_can_current_user_authorize() {
    $user = wp_get_current_user();
    $valid_roles = puri_valid_authorizer_roles();
    foreach ($user->roles as $role) {
        if (in_array($role, $valid_roles)) return true;
    }
    return (current_user_can(PURI_CAP_OTORISASI));
}

/**
 * Usage (di handler otorisasi/event):
 *   if (!current_user_can('puri_can_transfer_stock')) wp_die('Tidak berwenang transfer stok');
 *   if (!current_user_can('puri_can_otorisasi')) wp_die('Akses otorisasi ditolak');
 */
