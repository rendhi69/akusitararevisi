<?php
/**
 * AkurasiTara - Loader Laporan DSL.
 *
 * File ini hanya menjadi penghubung agar fitur laporan tetap terpisah
 * dari plugin utama AkurasiTara, tetapi masih berada dalam satu folder plugin.
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! defined('AKURASITARA_LAPORAN_DSL_PATH') ) {
    define('AKURASITARA_LAPORAN_DSL_PATH', plugin_dir_path(__FILE__));
}

if ( ! defined('AKURASITARA_LAPORAN_DSL_URL') ) {
    define('AKURASITARA_LAPORAN_DSL_URL', plugin_dir_url(__FILE__));
}

add_action('admin_enqueue_scripts', function($hook){
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'akurasitara_ext_reports') return;

    wp_enqueue_style(
        'akurasitara-laporan-dsl-admin',
        AKURASITARA_LAPORAN_DSL_URL . 'assets/laporan-dsl.css',
        array(),
        '2.2.25-iku1'
    );
});

require_once AKURASITARA_LAPORAN_DSL_PATH . 'akurasitara-ext-reports-dsl-role.php';
