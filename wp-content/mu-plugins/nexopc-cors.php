<?php
/**
 * Plugin Name: NexoPC CORS
 * Description: Habilita CORS para las aplicaciones web autorizadas de NexoPC.
 * Version: 1.0.0
 * Author: Equipo NexoPC
 * Author URI: https://github.com/TU-USUARIO/nexopc-backend
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Habilita CORS para el frontend headless
 */
add_action('init', function () {
    $allowed_origins = array(
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://nexopc.wrkz.net',
        'https://erp.wrkz.net',
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';

    if ($origin && in_array($origin, $allowed_origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && $origin && in_array($origin, $allowed_origins, true)) {
        status_header(204);
        exit();
    }
}, 15);
