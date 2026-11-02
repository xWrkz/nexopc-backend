<?php
/**
 * Plugin Name: NexoPC Hardening
 * Description: Reduce la superficie pública de WordPress en el backend headless de NexoPC.
 * Version: 1.0.0
 * Author: Equipo NexoPC
 * Author URI: https://github.com/TU-USUARIO/nexopc-backend
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ningún cliente de NexoPC usa XML-RPC y permite probar contraseñas en bloque.
 */
if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    status_header(403);
    exit;
}

/**
 * Evita enumerar administradores. El ERP sigue usando users/me con su token JWT.
 */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (null === $result && !is_user_logged_in() && preg_match('#^/+wp/v2/users(/|$)#i', $request->get_route())) {
        return new WP_Error('rest_forbidden', 'No autorizado.', array('status' => 401));
    }

    return $result;
}, 10, 3);

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    return 'users' === $name ? false : $provider;
}, 10, 2);
