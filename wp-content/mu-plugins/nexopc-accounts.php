<?php
/**
 * Plugin Name: NexoPC Accounts
 * Description: Inicio de sesión, perfil e historial de pedidos de los clientes de la tienda headless.
 * Version: 1.0.0
 * Author: Equipo NexoPC
 *
 * Las sesiones son las nativas de WordPress (WP_Session_Tokens): se guardan en el usuario, caducan solas,
 * se pueden revocar y se invalidan al cambiar la contraseña. Next.js guarda "{id}.{token}" en una cookie
 * HttpOnly y lo reenvía en X-NexoPC-Session junto con el secreto compartido de la tienda.
 */

if (!defined('ABSPATH')) {
    exit;
}

const NEXOPC_SESSION_TTL = 14 * DAY_IN_SECONDS;
const NEXOPC_LOGIN_IP_LIMIT = 10;
const NEXOPC_LOGIN_EMAIL_LIMIT = 8;
const NEXOPC_LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;
const NEXOPC_ACCOUNT_ORDERS_LIMIT = 30;
// Hash de una clave aleatoria descartada; solo sirve para igualar el tiempo de respuesta.
const NEXOPC_DUMMY_PASSWORD_HASH = '$wp$2y$10$F18WSnQTBlnzZikt.yVzfu0wu03XsZC3.CVsUb66CBFFcrro6T6sa';

function nexopc_account_error($message, $status = 422, $fields = array(), $code = 'nexopc_account_error') {
    return new WP_Error($code, $message, array('status' => $status, 'fields' => $fields ?: new stdClass()));
}

/** Intentos fallidos en una ventana fija. Los ingresos correctos no cuentan. */
function nexopc_account_throttled($bucket, $limit) {
    return (int) get_transient('nexopc_login_' . md5($bucket)) >= $limit;
}

function nexopc_account_record_failure($bucket) {
    $key = 'nexopc_login_' . md5($bucket);
    set_transient($key, (int) get_transient($key) + 1, NEXOPC_LOGIN_WINDOW);
}

/** Solo los clientes de la tienda inician sesión aquí; el personal usa el panel de administración. */
function nexopc_is_store_customer($user) {
    return $user instanceof WP_User && in_array('customer', (array) $user->roles, true);
}

/** Cliente dueño de la sesión enviada por Next.js, o null si falta, no es válida o expiró. */
function nexopc_session_user(WP_REST_Request $request) {
    static $cache = array();
    $header = (string) $request->get_header('x-nexopc-session');
    if (array_key_exists($header, $cache)) {
        return $cache[$header];
    }
    $user = null;
    if (preg_match('/^(\d{1,12})\.([A-Za-z0-9]{20,64})$/', $header, $match)) {
        $candidate = get_userdata((int) $match[1]);
        if (nexopc_is_store_customer($candidate) && WP_Session_Tokens::get_instance($candidate->ID)->verify($match[2])) {
            $user = $candidate;
        }
    }
    $cache[$header] = $user;
    return $user;
}

/** Secreto de la tienda + sesión de cliente válida. */
function nexopc_customer_permission(WP_REST_Request $request) {
    $allowed = nexopc_store_permission($request);
    if (true !== $allowed) {
        return $allowed;
    }
    if (!nexopc_session_user($request)) {
        return new WP_Error('nexopc_session_invalid', 'Tu sesión expiró. Vuelve a iniciar sesión.', array('status' => 401));
    }
    return true;
}

function nexopc_account_profile(WP_User $user) {
    $customer = class_exists('WC_Customer') ? new WC_Customer($user->ID) : null;
    return array(
        'id' => $user->ID,
        'firstName' => $customer ? ($customer->get_billing_first_name() ?: $user->first_name) : $user->first_name,
        'lastName' => $customer ? ($customer->get_billing_last_name() ?: $user->last_name) : $user->last_name,
        'email' => $user->user_email,
        'phone' => $customer ? $customer->get_billing_phone() : '',
        'address1' => $customer ? $customer->get_billing_address_1() : '',
        'city' => $customer ? $customer->get_billing_city() : '',
        'region' => $customer ? $customer->get_billing_state() : '',
        'postcode' => $customer ? $customer->get_billing_postcode() : '',
    );
}

function nexopc_account_login(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $data = is_array($data) ? $data : array();
    $email = sanitize_email($data['email'] ?? '');
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    if (!$email || !is_email($email) || $password === '' || strlen($password) > 128) {
        return nexopc_account_error('Ingresa tu correo y contraseña.', 422);
    }

    $client_ip = sanitize_text_field((string) $request->get_header('x-nexopc-client-ip'));
    if (($client_ip && nexopc_account_throttled('ip|' . $client_ip, NEXOPC_LOGIN_IP_LIMIT))
        || nexopc_account_throttled('email|' . strtolower($email), NEXOPC_LOGIN_EMAIL_LIMIT)) {
        return nexopc_account_error('Recibimos demasiados intentos. Espera unos minutos antes de volver a intentarlo.', 429);
    }

    $user = get_user_by('email', $email);
    if ($user && nexopc_is_store_customer($user)) {
        $valid = wp_check_password($password, $user->user_pass, $user->ID);
    } else {
        // Mismo costo que una clave real, para que el tiempo de respuesta no revele si la cuenta existe.
        wp_check_password($password, NEXOPC_DUMMY_PASSWORD_HASH);
        $valid = false;
    }
    if (!$valid) {
        if ($client_ip) nexopc_account_record_failure('ip|' . $client_ip);
        nexopc_account_record_failure('email|' . strtolower($email));
        // Mismo mensaje si el correo no existe o la clave falla: no revelamos qué cuentas existen.
        return nexopc_account_error('Correo o contraseña incorrectos.', 401, array(), 'nexopc_login_failed');
    }

    $expires = time() + NEXOPC_SESSION_TTL;
    $token = WP_Session_Tokens::get_instance($user->ID)->create($expires);
    return rest_ensure_response(array(
        'session' => $user->ID . '.' . $token,
        'expiresAt' => gmdate('c', $expires),
        'customer' => nexopc_account_profile($user),
    ));
}

function nexopc_account_logout(WP_REST_Request $request) {
    $header = (string) $request->get_header('x-nexopc-session');
    if (preg_match('/^(\d{1,12})\.([A-Za-z0-9]{20,64})$/', $header, $match)) {
        WP_Session_Tokens::get_instance((int) $match[1])->destroy($match[2]);
    }
    return rest_ensure_response(array('ok' => true));
}

function nexopc_account_me(WP_REST_Request $request) {
    return rest_ensure_response(nexopc_account_profile(nexopc_session_user($request)));
}

function nexopc_account_order(WC_Order $order) {
    $items = array();
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $image_id = $product ? $product->get_image_id() : 0;
        $parent = $product && $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $items[] = array(
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'total' => nexopc_money_string((float) $item->get_total() + (float) $item->get_total_tax()),
            'image' => $image_id ? (string) wp_get_attachment_image_url($image_id, 'thumbnail') : null,
            'slug' => $parent ? $parent->get_slug() : null,
        );
    }
    $created = $order->get_date_created();
    $paid = $order->get_date_paid();
    return array(
        'id' => $order->get_id(),
        'number' => (string) $order->get_order_number(),
        'orderKey' => $order->get_order_key(),
        'createdAt' => $created ? $created->format('c') : null,
        'paidAt' => $paid ? $paid->format('c') : null,
        'status' => $order->get_status(),
        'paid' => $order->is_paid(),
        'paymentMethod' => $order->get_payment_method(),
        'paymentMethodTitle' => $order->get_payment_method_title(),
        'mpStatusDetail' => (string) $order->get_meta('_nexopc_mp_status_detail'),
        'total' => nexopc_money_string($order->get_total()),
        'items' => $items,
    );
}

function nexopc_account_orders(WP_REST_Request $request) {
    $user = nexopc_session_user($request);
    $orders = wc_get_orders(array(
        'customer_id' => $user->ID,
        'type' => 'shop_order',
        'limit' => NEXOPC_ACCOUNT_ORDERS_LIMIT,
        'orderby' => 'date',
        'order' => 'DESC',
    ));
    return rest_ensure_response(array_map('nexopc_account_order', $orders));
}

add_action('rest_api_init', function () {
    register_rest_route('nexopc/v1', '/customers/login', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'nexopc_account_login',
        'permission_callback' => 'nexopc_store_permission',
    ));
    register_rest_route('nexopc/v1', '/customers/logout', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'nexopc_account_logout',
        'permission_callback' => 'nexopc_store_permission',
    ));
    register_rest_route('nexopc/v1', '/customers/me', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'nexopc_account_me',
        'permission_callback' => 'nexopc_customer_permission',
    ));
    register_rest_route('nexopc/v1', '/customers/orders', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'nexopc_account_orders',
        'permission_callback' => 'nexopc_customer_permission',
    ));
});
