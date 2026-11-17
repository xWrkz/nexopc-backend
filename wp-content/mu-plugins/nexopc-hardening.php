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

const NEXOPC_AUTH_FAIL_LIMIT = 20;
const NEXOPC_AUTH_FAIL_WINDOW = 15 * MINUTE_IN_SECONDS;

/** Personal de la tienda: único perfil que puede usar el panel, GraphQL de escritura o el login de WordPress. */
function nexopc_is_staff() {
    return current_user_can('manage_woocommerce') || current_user_can('edit_posts');
}

/**
 * Backend headless: la tienda es nexopc.wrkz.net. Las plantillas públicas de WordPress (incluidas la
 * tienda, el carrito y el checkout clásicos de WooCommerce y wc-ajax) redirigen allí para que nadie
 * pueda comprar ni registrarse saltándose las reglas de la tienda (cuenta obligatoria, validaciones).
 * Prioridad -100: antes de WC_AJAX (0) y de redirect_canonical (10), que expone /author/<usuario>/.
 */
add_action('template_redirect', function () {
    if (nexopc_is_staff()) {
        return;
    }
    $store = function_exists('nexopc_store_url') ? nexopc_store_url() : 'https://nexopc.wrkz.net';
    wp_redirect($store, 302, 'NexoPC');
    exit;
}, -100);

/**
 * Ninguna pasarela de WooCommerce queda disponible: los pedidos solo nacen en nexopc/v1/checkout, que
 * asigna el método de pago por su cuenta. Así la Store API, GraphQL o el checkout clásico no pueden
 * crear pedidos aunque alguien llegue a ellos.
 */
add_filter('woocommerce_available_payment_gateways', '__return_empty_array', 100);
add_filter('pre_option_woocommerce_enable_guest_checkout', function () {
    return 'no';
});

/** La Store API (carrito y checkout públicos de WooCommerce) no la usa ningún cliente de NexoPC. */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (null === $result && preg_match('#^/+wc/store(/|$)#i', $request->get_route())) {
        return new WP_Error('rest_forbidden', 'No disponible.', array('status' => 403));
    }
    return $result;
}, 10, 3);

/**
 * GraphQL público solo lee el catálogo. Las mutaciones de WooGraphQL (registerCustomer, addToCart,
 * checkout, updateCustomer…) saltaban las validaciones y límites de la tienda, y `users` revelaba el
 * usuario administrador.
 */
add_filter('graphql_pre_resolve_field', function ($nil, $source, $args, $context, $info, $type_name, $field_key) {
    if (nexopc_is_staff()) {
        return $nil;
    }
    // `User` cubre cualquier camino hacia un usuario (users, user, node, nodeByUri…).
    if ('RootMutation' === $type_name || 'User' === $type_name || ('RootQuery' === $type_name && in_array($field_key, array('users', 'user'), true))) {
        throw new \GraphQL\Error\UserError('No autorizado.');
    }
    return $nil;
}, 10, 7);

/**
 * Inicio de sesión de WordPress (wp-login.php, JWT, contraseñas de aplicación):
 * - Los clientes solo ingresan por la tienda (nexopc/v1/customers/login, con sus propios límites), así
 *   que sus contraseñas no se pueden probar por estas otras puertas.
 * - Mensaje genérico para no revelar si el usuario o correo existe.
 * - Límite de intentos fallidos por IP (Apache ya resuelve la IP real que entrega Traefik).
 */
function nexopc_auth_fail_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return $ip ? 'nexopc_authfail_' . md5($ip) : '';
}

add_filter('authenticate', function ($user) {
    $key = nexopc_auth_fail_key();
    if ($key && (int) get_transient($key) >= NEXOPC_AUTH_FAIL_LIMIT) {
        return new WP_Error('too_many_attempts', 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.');
    }
    if ($user instanceof WP_User && array_values((array) $user->roles) === array('customer')) {
        return new WP_Error('invalid_credentials', 'Credenciales incorrectas.');
    }
    if (is_wp_error($user) && array_intersect($user->get_error_codes(), array('invalid_username', 'invalid_email', 'incorrect_password'))) {
        return new WP_Error('invalid_credentials', 'Credenciales incorrectas.');
    }
    return $user;
}, 99);

add_action('wp_login_failed', function () {
    $key = nexopc_auth_fail_key();
    if ($key) {
        set_transient($key, (int) get_transient($key) + 1, NEXOPC_AUTH_FAIL_WINDOW);
    }
});

/**
 * Recuperar contraseña desde wp-login.php revela qué correos existen y hoy no hay envío de correos.
 * El personal puede restablecerla por WP-CLI; los clientes, escribiendo a soporte.
 */
add_action('login_init', function () {
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    if (in_array($action, array('lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register'), true)) {
        wp_safe_redirect(wp_login_url());
        exit;
    }
});
add_filter('allow_password_reset', '__return_false');

add_filter('wp_is_application_passwords_available_for_user', function ($available, $user) {
    return $available && $user instanceof WP_User && array_values((array) $user->roles) !== array('customer');
}, 10, 2);
