<?php
/**
 * Plugin Name: NexoPC Customers
 * Description: Registro de clientes desde la tienda headless de NexoPC.
 * Version: 1.0.0
 * Author: Equipo NexoPC
 */

if (!defined('ABSPATH')) {
    exit;
}

const NEXOPC_DNI_META = 'nexopc_dni';
const NEXOPC_SIGNUP_RATE_LIMIT = 5;
const NEXOPC_SIGNUP_RATE_WINDOW = HOUR_IN_SECONDS;

/**
 * Secreto compartido con el servidor Next.js. Sin él, el registro queda deshabilitado:
 * el endpoint nunca debe quedar abierto a llamadas directas desde internet.
 */
function nexopc_signup_secret() {
    $secret = getenv('NEXOPC_SIGNUP_SECRET');
    if (!$secret && defined('NEXOPC_SIGNUP_SECRET')) {
        $secret = NEXOPC_SIGNUP_SECRET;
    }
    return is_string($secret) && strlen($secret) >= 32 ? $secret : '';
}

function nexopc_signup_permission(WP_REST_Request $request) {
    $secret = nexopc_signup_secret();
    if (!$secret) {
        return new WP_Error('nexopc_signup_disabled', 'El registro no está disponible en este momento.', array('status' => 503));
    }
    $provided = (string) $request->get_header('x-nexopc-signup-secret');
    if (!$provided || !hash_equals($secret, $provided)) {
        return new WP_Error('rest_forbidden', 'No autorizado.', array('status' => 401));
    }
    return true;
}

function nexopc_signup_error($message, $status = 422, $fields = array()) {
    return new WP_Error('nexopc_signup_error', $message, array('status' => $status, 'fields' => $fields));
}

function nexopc_signup_rate_limited($client_ip) {
    if (!$client_ip) {
        return false;
    }
    $key = 'nexopc_signup_' . md5($client_ip);
    $count = (int) get_transient($key);
    if ($count >= NEXOPC_SIGNUP_RATE_LIMIT) {
        return true;
    }
    set_transient($key, $count + 1, NEXOPC_SIGNUP_RATE_WINDOW);
    return false;
}

function nexopc_normalize_pe_phone($phone) {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 11 && strpos($digits, '51') === 0) {
        $digits = substr($digits, 2);
    }
    return preg_match('/^9\d{8}$/', $digits) ? $digits : '';
}

function nexopc_dni_exists($dni) {
    $users = get_users(array(
        'meta_key' => NEXOPC_DNI_META,
        'meta_value' => $dni,
        'number' => 1,
        'fields' => 'ID',
    ));
    return !empty($users);
}

function nexopc_register_customer(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $data = is_array($data) ? $data : array();

    $first_name = sanitize_text_field($data['firstName'] ?? '');
    $last_name = sanitize_text_field($data['lastName'] ?? '');
    $email = sanitize_email($data['email'] ?? '');
    $phone = nexopc_normalize_pe_phone($data['phone'] ?? '');
    $dni = preg_replace('/\D+/', '', (string) ($data['dni'] ?? ''));
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $accepted = !empty($data['acceptTerms']);

    // El frontend ya valida; esto es la última barrera frente a datos manipulados.
    $fields = array();
    $name_pattern = '/^[\p{L}\'’ .-]{2,60}$/u';
    if (!preg_match($name_pattern, $first_name)) $fields['firstName'] = array('Ingresa tus nombres.');
    if (!preg_match($name_pattern, $last_name)) $fields['lastName'] = array('Ingresa tus apellidos.');
    if (!$email || !is_email($email)) $fields['email'] = array('Ingresa un correo válido.');
    if (!$phone) $fields['phone'] = array('Ingresa un celular peruano de 9 dígitos.');
    if (!preg_match('/^\d{8}$/', $dni)) $fields['dni'] = array('El DNI debe tener 8 dígitos.');
    if (strlen($password) < 8 || strlen($password) > 128 || !preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        $fields['password'] = array('Usa al menos 8 caracteres con letras y números.');
    }
    if (!$accepted) $fields['acceptTerms'] = array('Debes aceptar los términos y la política de privacidad.');
    if ($fields) {
        return nexopc_signup_error('Revisa los campos marcados.', 422, $fields);
    }

    $client_ip = sanitize_text_field((string) $request->get_header('x-nexopc-client-ip'));
    if (nexopc_signup_rate_limited($client_ip)) {
        return nexopc_signup_error('Recibimos demasiados intentos. Espera un momento antes de volver a intentarlo.', 429);
    }

    if (email_exists($email)) {
        return nexopc_signup_error('Ya existe una cuenta con este correo.', 409, array('email' => array('Ya existe una cuenta con este correo.')));
    }
    if (nexopc_dni_exists($dni)) {
        return nexopc_signup_error('Ya existe una cuenta con este DNI.', 409, array('dni' => array('Ya existe una cuenta con este DNI.')));
    }

    $base_username = sanitize_user(current(explode('@', $email)), true);
    $username = $base_username ?: 'cliente';
    $suffix = 1;
    while (username_exists($username)) {
        $username = $base_username . $suffix;
        $suffix++;
    }

    $user_id = wp_insert_user(array(
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => trim($first_name . ' ' . $last_name),
        'role' => 'customer',
    ));
    if (is_wp_error($user_id)) {
        error_log('[NexoPC Customers] wp_insert_user falló: ' . $user_id->get_error_code());
        return nexopc_signup_error('No pudimos crear tu cuenta. Inténtalo nuevamente.', 500);
    }

    update_user_meta($user_id, NEXOPC_DNI_META, $dni);
    update_user_meta($user_id, 'nexopc_terms_accepted_at', gmdate('c'));

    if (class_exists('WC_Customer')) {
        $customer = new WC_Customer($user_id);
        $customer->set_billing_first_name($first_name);
        $customer->set_billing_last_name($last_name);
        $customer->set_billing_email($email);
        $customer->set_billing_phone($phone);
        $customer->set_billing_country('PE');
        $customer->save();
    }

    return new WP_REST_Response(array('ok' => true, 'customerId' => $user_id), 201);
}

add_action('rest_api_init', function () {
    register_rest_route('nexopc/v1', '/customers/register', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'nexopc_register_customer',
        'permission_callback' => 'nexopc_signup_permission',
    ));
});
