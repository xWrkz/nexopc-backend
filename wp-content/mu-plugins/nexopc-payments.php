<?php
/**
 * Plugin Name: NexoPC Payments
 * Description: Checkout de la tienda headless y cobros con Mercado Pago (Checkout Pro vía API de Orders).
 * Version: 1.0.0
 * Author: Equipo NexoPC
 *
 * Flujo:
 *  1. El servidor Next.js llama a POST nexopc/v1/checkout con el secreto compartido.
 *     Se valida el carrito contra el catálogo y se crea el pedido de WooCommerce.
 *  2. Si el método es Mercado Pago, se crea una order en MP y se devuelve su checkout_url.
 *  3. MP avisa a POST nexopc/v1/mercadopago/webhook. Se valida la firma y se vuelve a
 *     consultar la order a la API (nunca se confía en el cuerpo del aviso).
 *  4. La página de confirmación consulta GET nexopc/v1/checkout/status con la clave del pedido.
 */

if (!defined('ABSPATH')) {
    exit;
}

const NEXOPC_MP_API = 'https://api.mercadopago.com';
const NEXOPC_MP_ORDER_META = '_nexopc_mp_order_id';
const NEXOPC_MP_STATUS_META = '_nexopc_mp_status';
const NEXOPC_MP_DETAIL_META = '_nexopc_mp_status_detail';
const NEXOPC_MP_METHOD_META = '_nexopc_mp_payment_method';
const NEXOPC_MP_SYNCED_META = '_nexopc_mp_synced_at';
const NEXOPC_ORDER_SOURCE = 'nexopc-store';
const NEXOPC_CHECKOUT_MAX_LINES = 50;
const NEXOPC_CHECKOUT_MAX_QTY = 20;
const NEXOPC_PAYMENT_METHODS = array(
    'bacs' => 'Transferencia bancaria',
    'cod' => 'Pago contraentrega',
    'mercadopago' => 'Mercado Pago',
);

function nexopc_env($name) {
    $value = getenv($name);
    if (($value === false || $value === '') && defined($name)) {
        $value = constant($name);
    }
    return is_string($value) ? trim($value) : '';
}

function nexopc_store_url() {
    return untrailingslashit(esc_url_raw(nexopc_env('NEXOPC_STORE_URL') ?: 'https://nexopc.wrkz.net'));
}

/**
 * Las llamadas servidor a servidor desde Next.js usan el mismo secreto que el registro
 * (NEXOPC_SIGNUP_SECRET). Sin él, el checkout queda cerrado.
 */
function nexopc_store_permission(WP_REST_Request $request) {
    $secret = function_exists('nexopc_signup_secret') ? nexopc_signup_secret() : '';
    if (!$secret) {
        return new WP_Error('nexopc_checkout_disabled', 'El servicio de pedidos no está disponible en este momento.', array('status' => 503));
    }
    $provided = (string) $request->get_header('x-nexopc-store-secret');
    if (!$provided || !hash_equals($secret, $provided)) {
        return new WP_Error('rest_forbidden', 'No autorizado.', array('status' => 401));
    }
    return true;
}

function nexopc_checkout_error($message, $status = 422, $fields = array()) {
    // Objeto vacío y no [] para que el frontend siempre reciba un mapa campo → errores.
    return new WP_Error('nexopc_checkout_error', $message, array('status' => $status, 'fields' => $fields ?: new stdClass()));
}

function nexopc_money_string($value) {
    return number_format((float) $value, 2, '.', '');
}

/* ------------------------------------------------------------------ */
/* Checkout                                                            */
/* ------------------------------------------------------------------ */

/** Agrupa líneas repetidas y descarta cantidades fuera de rango. */
function nexopc_checkout_lines($raw_items) {
    if (!is_array($raw_items) || !$raw_items) {
        return nexopc_checkout_error('Tu carrito está vacío.', 422, array('items' => array('Tu carrito está vacío.')));
    }
    $lines = array();
    foreach ($raw_items as $raw) {
        $product_id = absint($raw['productId'] ?? 0);
        $variation_id = absint($raw['variationId'] ?? 0);
        $quantity = (int) ($raw['quantity'] ?? 0);
        if (!$product_id || $quantity < 1) {
            return nexopc_checkout_error('Revisa los productos de tu carrito.', 422, array('items' => array('Revisa los productos de tu carrito.')));
        }
        $key = $product_id . ':' . $variation_id;
        if (!isset($lines[$key])) {
            $lines[$key] = array('productId' => $product_id, 'variationId' => $variation_id, 'quantity' => 0);
        }
        $lines[$key]['quantity'] += $quantity;
        if ($lines[$key]['quantity'] > NEXOPC_CHECKOUT_MAX_QTY) {
            $message = 'No puedes solicitar más de ' . NEXOPC_CHECKOUT_MAX_QTY . ' unidades de un mismo producto.';
            return nexopc_checkout_error($message, 422, array('items' => array($message)));
        }
    }
    if (count($lines) > NEXOPC_CHECKOUT_MAX_LINES) {
        $message = 'Tu pedido no puede tener más de ' . NEXOPC_CHECKOUT_MAX_LINES . ' productos distintos.';
        return nexopc_checkout_error($message, 422, array('items' => array($message)));
    }
    return array_values($lines);
}

/** Devuelve el producto vendible de la línea o un error pensado para el cliente. */
function nexopc_checkout_product($line) {
    $unavailable = nexopc_checkout_error('Uno de los productos de tu pedido ya no está disponible.', 409);
    $parent = wc_get_product($line['productId']);
    if (!$parent || $parent->is_type('variation') || $parent->get_status() !== 'publish') {
        return $unavailable;
    }

    $product = $parent;
    if ($line['variationId']) {
        $product = wc_get_product($line['variationId']);
        if (!$product || !$product->is_type('variation') || $product->get_parent_id() !== $parent->get_id() || $product->get_status() !== 'publish') {
            return $unavailable;
        }
    } elseif ($parent->is_type('variable')) {
        return nexopc_checkout_error(sprintf('Selecciona una variante válida para %s.', $parent->get_name()), 422);
    }

    $name = $product->get_name();
    if ($product->get_stock_status() !== 'instock') {
        return nexopc_checkout_error(sprintf('%s ya no está disponible.', $name), 409);
    }
    if (!$product->is_purchasable() || !((float) $product->get_price() > 0)) {
        return nexopc_checkout_error(sprintf('%s no tiene un precio confirmado.', $name), 409);
    }
    $stock = $product->managing_stock() ? $product->get_stock_quantity() : null;
    if ($stock !== null && !$product->backorders_allowed() && $stock < $line['quantity']) {
        return nexopc_checkout_error(sprintf('Solo quedan %d unidades de %s.', max(0, $stock), $name), 409);
    }
    return $product;
}

function nexopc_checkout_customer($raw) {
    $raw = is_array($raw) ? $raw : array();
    $customer = array(
        'first_name' => sanitize_text_field($raw['firstName'] ?? ''),
        'last_name' => sanitize_text_field($raw['lastName'] ?? ''),
        'email' => sanitize_email($raw['email'] ?? ''),
        'phone' => sanitize_text_field($raw['phone'] ?? ''),
        'address_1' => sanitize_text_field($raw['address1'] ?? ''),
        'city' => sanitize_text_field($raw['city'] ?? ''),
        'state' => sanitize_text_field($raw['region'] ?? ''),
        'postcode' => sanitize_text_field($raw['postcode'] ?? ''),
        'country' => 'PE',
    );
    // Next.js ya valida con zod; esto es la última barrera frente a llamadas manipuladas.
    $fields = array();
    foreach (array('first_name' => 'firstName', 'last_name' => 'lastName', 'address_1' => 'address1', 'city' => 'city', 'state' => 'region', 'postcode' => 'postcode') as $key => $field) {
        $length = mb_strlen($customer[$key]);
        if ($length < 2 || $length > 120) $fields[$field] = array('Completa este campo.');
    }
    if (!$customer['email'] || !is_email($customer['email'])) $fields['email'] = array('Ingresa un correo válido.');
    if (!preg_match('/^[0-9+\-\s()]{7,25}$/', $customer['phone'])) $fields['phone'] = array('Ingresa un teléfono válido.');
    if ($fields) {
        return nexopc_checkout_error('Revisa los campos marcados antes de continuar.', 422, $fields);
    }
    $customer['note'] = mb_substr(sanitize_textarea_field($raw['notes'] ?? ''), 0, 500);
    return $customer;
}

function nexopc_checkout(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $data = is_array($data) ? $data : array();

    $method = sanitize_key($data['paymentMethod'] ?? '');
    if (!array_key_exists($method, NEXOPC_PAYMENT_METHODS)) {
        return nexopc_checkout_error('Elige una forma de pago válida.', 422, array('paymentMethod' => array('Elige una forma de pago válida.')));
    }
    if ($method === 'mercadopago' && !nexopc_env('MP_ACCESS_TOKEN')) {
        return nexopc_checkout_error('El pago con Mercado Pago no está disponible en este momento.', 503);
    }

    $customer = nexopc_checkout_customer($data['customer'] ?? null);
    if (is_wp_error($customer)) return $customer;

    $lines = nexopc_checkout_lines($data['items'] ?? null);
    if (is_wp_error($lines)) return $lines;

    $products = array();
    foreach ($lines as $line) {
        $product = nexopc_checkout_product($line);
        if (is_wp_error($product)) return $product;
        $products[] = array($product, $line['quantity']);
    }

    $order = wc_create_order(array('status' => 'pending', 'created_via' => NEXOPC_ORDER_SOURCE));
    if (is_wp_error($order)) {
        error_log('[NexoPC Payments] wc_create_order falló: ' . $order->get_error_code());
        return nexopc_checkout_error('No pudimos procesar tu solicitud en este momento.', 500);
    }

    foreach ($products as list($product, $quantity)) {
        $order->add_product($product, $quantity);
    }
    $note = $customer['note'];
    unset($customer['note']);
    $order->set_address($customer, 'billing');
    $shipping = $customer;
    unset($shipping['email']);
    $order->set_address($shipping, 'shipping');
    $order->set_customer_note($note);
    $order->set_payment_method($method);
    $order->set_payment_method_title(NEXOPC_PAYMENT_METHODS[$method]);
    $client_ip = sanitize_text_field((string) $request->get_header('x-nexopc-client-ip'));
    if ($client_ip && filter_var($client_ip, FILTER_VALIDATE_IP)) {
        $order->set_customer_ip_address($client_ip);
    }
    $order->calculate_totals();
    $order->save();

    $checkout_url = null;
    if ($method === 'mercadopago') {
        $mp = nexopc_mp_create_order($order);
        if (is_wp_error($mp)) {
            $order->update_status('cancelled', 'No se pudo iniciar el pago en Mercado Pago.');
            return nexopc_checkout_error('No pudimos iniciar el pago con Mercado Pago. Inténtalo nuevamente en unos minutos.', 502);
        }
        $checkout_url = $mp['checkout_url'];
        // WooCommerce cancela los impagos con esta tarea, pero solo la programa al guardar sus ajustes.
        $hold_minutes = absint(get_option('woocommerce_hold_stock_minutes', 60));
        if ($hold_minutes && !wp_next_scheduled('woocommerce_cancel_unpaid_orders')) {
            wp_schedule_single_event(time() + $hold_minutes * MINUTE_IN_SECONDS, 'woocommerce_cancel_unpaid_orders');
        }
    } else {
        // Igual que antes: transferencia y contraentrega quedan en espera de coordinación.
        $order->update_status('on-hold', 'Pedido registrado desde la tienda.');
    }

    return new WP_REST_Response(array(
        'ok' => true,
        'orderId' => $order->get_id(),
        'orderNumber' => (string) $order->get_order_number(),
        'orderKey' => $order->get_order_key(),
        'status' => $order->get_status(),
        'paymentMethod' => $method,
        'checkoutUrl' => $checkout_url,
    ), 201);
}

/** Estado de un pedido para la página de confirmación. Exige la clave del pedido. */
function nexopc_checkout_status(WP_REST_Request $request) {
    $order = wc_get_order(absint($request->get_param('order')));
    $key = (string) $request->get_param('key');
    if (!$order || !$key || !hash_equals($order->get_order_key(), $key)) {
        return nexopc_checkout_error('Pedido no encontrado.', 404);
    }

    // Respaldo por si el webhook se retrasa: una consulta a MP como máximo cada 10 s por pedido.
    $mp_order_id = (string) $order->get_meta(NEXOPC_MP_ORDER_META);
    if ($mp_order_id && $order->has_status('pending') && time() - (int) $order->get_meta(NEXOPC_MP_SYNCED_META) >= 10) {
        nexopc_mp_sync($mp_order_id);
        $order = wc_get_order($order->get_id());
    }

    return rest_ensure_response(array(
        'orderNumber' => (string) $order->get_order_number(),
        'status' => $order->get_status(),
        'paymentMethod' => $order->get_payment_method(),
        'paid' => $order->is_paid(),
        'total' => nexopc_money_string($order->get_total()),
        // waiting_retry = la tarjeta fue rechazada y el comprador puede reintentar en MP.
        'mpStatus' => (string) $order->get_meta(NEXOPC_MP_STATUS_META),
        'mpStatusDetail' => (string) $order->get_meta(NEXOPC_MP_DETAIL_META),
    ));
}

/* ------------------------------------------------------------------ */
/* Mercado Pago                                                        */
/* ------------------------------------------------------------------ */

function nexopc_mp_request($method, $path, $body = null) {
    $token = nexopc_env('MP_ACCESS_TOKEN');
    if (!$token) {
        return new WP_Error('nexopc_mp_not_configured', 'Mercado Pago no está configurado.');
    }
    $args = array(
        'method' => $method,
        'timeout' => 15,
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
    );
    if ($method === 'POST') {
        $args['headers']['Content-Type'] = 'application/json';
        $args['headers']['X-Idempotency-Key'] = wp_generate_uuid4();
        $args['body'] = wp_json_encode($body === null ? new stdClass() : $body);
    }

    $response = wp_remote_request(NEXOPC_MP_API . $path, $args);
    if (is_wp_error($response)) {
        error_log('[NexoPC Payments] Error de red con Mercado Pago (' . $method . ' ' . $path . '): ' . $response->get_error_message());
        return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $detail = is_array($data) ? wp_json_encode($data['errors'] ?? $data['message'] ?? $data) : '';
        error_log('[NexoPC Payments] Mercado Pago respondió ' . $code . ' (' . $method . ' ' . $path . '): ' . mb_substr((string) $detail, 0, 500));
        return new WP_Error('nexopc_mp_http_error', 'Mercado Pago rechazó la solicitud.', array('status' => $code));
    }
    return $data;
}

/**
 * MP exige que total_amount sea igual a la suma de unit_price × quantity.
 * Si el redondeo no cuadra (descuentos, envío), se envía una sola línea con el total.
 */
function nexopc_mp_items(WC_Order $order) {
    $items = array();
    $sum = 0.0;
    foreach ($order->get_items() as $item) {
        $quantity = (int) $item->get_quantity();
        if ($quantity < 1) continue;
        $unit = round(((float) $item->get_total() + (float) $item->get_total_tax()) / $quantity, 2);
        $sum += $unit * $quantity;
        $items[] = array('title' => mb_substr($item->get_name(), 0, 250), 'quantity' => $quantity, 'unit_price' => nexopc_money_string($unit));
    }
    if (!$items || abs($sum - (float) $order->get_total()) >= 0.005) {
        return array(array(
            'title' => sprintf('Pedido NexoPC #%s', $order->get_order_number()),
            'quantity' => 1,
            'unit_price' => nexopc_money_string($order->get_total()),
        ));
    }
    return $items;
}

function nexopc_mp_create_order(WC_Order $order) {
    $return_url = add_query_arg(
        array('pedido' => $order->get_id(), 'clave' => $order->get_order_key()),
        nexopc_store_url() . '/confirmacion'
    );
    $mp = nexopc_mp_request('POST', '/v1/orders', array(
        'type' => 'online',
        'processing_mode' => 'manual',
        'total_amount' => nexopc_money_string($order->get_total()),
        'external_reference' => (string) $order->get_id(),
        'description' => sprintf('Pedido NexoPC #%s', $order->get_order_number()),
        'payer' => array(
            'email' => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        ),
        'items' => nexopc_mp_items($order),
        'config' => array('online' => array(
            'success_url' => $return_url,
            'failure_url' => $return_url,
            'pending_url' => $return_url,
            'auto_return' => 'all',
        )),
    ));
    if (is_wp_error($mp)) return $mp;
    if (empty($mp['id']) || empty($mp['checkout_url']) || strpos($mp['checkout_url'], 'https://') !== 0) {
        error_log('[NexoPC Payments] Respuesta de Mercado Pago sin id o checkout_url.');
        return new WP_Error('nexopc_mp_invalid_response', 'Respuesta inválida de Mercado Pago.');
    }

    $order->update_meta_data(NEXOPC_MP_ORDER_META, sanitize_text_field($mp['id']));
    $order->update_meta_data(NEXOPC_MP_STATUS_META, sanitize_key($mp['status'] ?? 'created'));
    $order->save();
    $order->add_order_note('Order de Mercado Pago creada: ' . sanitize_text_field($mp['id']));
    return $mp;
}

/** Compara la firma x-signature (HMAC-SHA256 de "id:{data.id};request-id:{x-request-id};ts:{ts};"). */
function nexopc_mp_valid_signature($signature_header, $request_id, $data_id, $secret) {
    $ts = '';
    $hash = '';
    foreach (explode(',', (string) $signature_header) as $part) {
        $pieces = explode('=', $part, 2);
        if (count($pieces) !== 2) continue;
        $key = strtolower(trim($pieces[0]));
        if ($key === 'ts') $ts = trim($pieces[1]);
        if ($key === 'v1') $hash = trim($pieces[1]);
    }
    if (!ctype_digit($ts) || !$hash) return false;

    $request_id = trim((string) $request_id);
    // Los SDK oficiales firman data.id tal cual; la documentación pide minúsculas para ids alfanuméricos.
    foreach (array_unique(array($data_id, strtolower($data_id))) as $id) {
        $parts = array();
        if ($id !== '') $parts[] = 'id:' . $id;
        if ($request_id !== '') $parts[] = 'request-id:' . $request_id;
        $parts[] = 'ts:' . $ts;
        if (hash_equals(hash_hmac('sha256', implode(';', $parts) . ';', $secret), $hash)) {
            return true;
        }
    }
    return false;
}

function nexopc_mp_webhook(WP_REST_Request $request) {
    $secret = nexopc_env('MP_WEBHOOK_SECRET');
    if (!$secret) {
        error_log('[NexoPC Payments] Webhook recibido sin MP_WEBHOOK_SECRET configurado.');
        return new WP_REST_Response(array('ok' => false), 503);
    }

    // PHP convierte "data.id" en "data_id" dentro de $_GET; se lee la query original.
    $data_id = '';
    if (preg_match('/(?:^|&)data\.id=([^&]*)/', (string) ($_SERVER['QUERY_STRING'] ?? ''), $match)) {
        $data_id = trim(rawurldecode($match[1]));
    }
    if (!nexopc_mp_valid_signature($request->get_header('x-signature'), $request->get_header('x-request-id'), $data_id, $secret)) {
        error_log('[NexoPC Payments] Webhook con firma inválida. request-id: ' . sanitize_text_field((string) $request->get_header('x-request-id')));
        return new WP_REST_Response(array('ok' => false), 401);
    }

    $body = $request->get_json_params();
    $type = (string) ($request->get_param('type') ?: (is_array($body) ? ($body['type'] ?? '') : ''));
    if ($type !== 'order' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $data_id)) {
        return new WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
    }

    $result = nexopc_mp_sync($data_id);
    // Si MP no respondió, devolvemos 500 para que reintente el aviso más tarde.
    return new WP_REST_Response(array('ok' => !is_wp_error($result)), is_wp_error($result) ? 500 : 200);
}

/** Lee la order en MP y aplica su estado al pedido de WooCommerce. Idempotente. */
function nexopc_mp_sync($mp_order_id) {
    $mp = nexopc_mp_request('GET', '/v1/orders/' . rawurlencode($mp_order_id));
    if (is_wp_error($mp)) return $mp;

    $order = wc_get_order(absint($mp['external_reference'] ?? 0));
    if (!$order || (string) $order->get_meta(NEXOPC_MP_ORDER_META) !== (string) ($mp['id'] ?? '')) {
        error_log('[NexoPC Payments] Order de Mercado Pago sin pedido asociado: ' . sanitize_text_field((string) ($mp['id'] ?? '')));
        return false;
    }

    // Candado simple: el webhook y la página de confirmación pueden llegar a la vez.
    $lock = 'nexopc_mp_lock_' . $order->get_id();
    if (!add_option($lock, time(), '', 'no')) {
        if (time() - (int) get_option($lock) < 60) return true;
        delete_option($lock);
        if (!add_option($lock, time(), '', 'no')) return true;
    }
    try {
        nexopc_mp_apply(wc_get_order($order->get_id()), $mp);
    } finally {
        delete_option($lock);
    }
    return true;
}

function nexopc_mp_apply(WC_Order $order, array $mp) {
    $status = sanitize_key($mp['status'] ?? '');
    $detail = sanitize_key($mp['status_detail'] ?? '');
    $payment = $mp['transactions']['payments'][0] ?? array();
    $payment_id = sanitize_text_field((string) ($payment['id'] ?? ''));
    $method = sanitize_text_field(trim(($payment['payment_method']['type'] ?? '') . ' ' . ($payment['payment_method']['id'] ?? '')));

    $previous = $order->get_meta(NEXOPC_MP_STATUS_META) . '/' . $order->get_meta(NEXOPC_MP_DETAIL_META);
    $order->update_meta_data(NEXOPC_MP_STATUS_META, $status);
    $order->update_meta_data(NEXOPC_MP_DETAIL_META, $detail);
    $order->update_meta_data(NEXOPC_MP_SYNCED_META, time());
    if ($method) $order->update_meta_data(NEXOPC_MP_METHOD_META, $method);
    $order->save();

    if ($status === 'processed' && $detail === 'accredited') {
        $total_ok = abs((float) ($mp['total_amount'] ?? 0) - (float) $order->get_total()) < 0.01;
        $paid_ok = !isset($mp['total_paid_amount']) || (float) $mp['total_paid_amount'] + 0.005 >= (float) $order->get_total();
        $currency_ok = strtoupper((string) ($mp['currency'] ?? $order->get_currency())) === strtoupper($order->get_currency());
        if (!$total_ok || !$paid_ok || !$currency_ok) {
            if (!$order->has_status('on-hold')) {
                $order->update_status('on-hold', 'Mercado Pago informó un pago cuyo monto o moneda no coincide con el pedido. Revísalo antes de despachar.');
            }
            return;
        }
        if ($order->is_paid()) return;
        $note = sprintf('Pago aprobado en Mercado Pago (%s). Transacción %s.', $method ?: 'medio no informado', $payment_id ?: '—');
        if ($order->needs_payment()) {
            $order->add_order_note($note);
            $order->payment_complete($payment_id);
        } elseif (!$order->has_status('on-hold')) {
            // Pagó después de que el pedido fuera cancelado: queda en revisión manual.
            $order->set_transaction_id($payment_id);
            $order->set_date_paid(time());
            $order->update_status('on-hold', $note . ' El pedido estaba ' . wc_get_order_status_name($order->get_status()) . ': confirma stock o reembolsa.');
        }
        return;
    }

    if (in_array($status, array('canceled', 'expired', 'failed'), true) || in_array($detail, array('rejected', 'expired', 'canceled'), true)) {
        if ($order->has_status('pending')) {
            $order->update_status($status === 'failed' || $detail === 'rejected' ? 'failed' : 'cancelled', sprintf('Mercado Pago: %s / %s.', $status, $detail));
        }
        return;
    }

    // created, processing, action_required: el pedido sigue pendiente.
    if ($previous !== $status . '/' . $detail && $status !== 'created') {
        $order->add_order_note(sprintf('Mercado Pago: %s / %s.', $status, $detail ?: '—'));
    }
}

/* ------------------------------------------------------------------ */
/* Hooks de WooCommerce                                                */
/* ------------------------------------------------------------------ */

// WooCommerce solo cancela pedidos impagos creados por su propio checkout; incluimos los de la tienda.
add_filter('woocommerce_cancel_unpaid_order', function ($cancel, $order) {
    return $cancel || ($order instanceof WC_Order && $order->get_created_via() === NEXOPC_ORDER_SOURCE && $order->get_payment_method() === 'mercadopago');
}, 10, 2);

// Al cancelar un pedido impago, también se cancela su order en MP para que no se pueda pagar después.
add_action('woocommerce_order_status_cancelled', function ($order_id, $order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== 'mercadopago' || $order->get_transaction_id()) return;
    $mp_order_id = (string) $order->get_meta(NEXOPC_MP_ORDER_META);
    if (!$mp_order_id || in_array($order->get_meta(NEXOPC_MP_STATUS_META), array('canceled', 'expired', 'processed'), true)) return;
    $result = nexopc_mp_request('POST', '/v1/orders/' . rawurlencode($mp_order_id) . '/cancel');
    if (!is_wp_error($result)) {
        $order->update_meta_data(NEXOPC_MP_STATUS_META, 'canceled');
        $order->save_meta_data();
    }
}, 10, 2);

add_action('rest_api_init', function () {
    register_rest_route('nexopc/v1', '/checkout', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'nexopc_checkout',
        'permission_callback' => 'nexopc_store_permission',
    ));
    register_rest_route('nexopc/v1', '/checkout/status', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'nexopc_checkout_status',
        'permission_callback' => 'nexopc_store_permission',
    ));
    // Público: la autenticidad se comprueba con la firma de Mercado Pago.
    register_rest_route('nexopc/v1', '/mercadopago/webhook', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'nexopc_mp_webhook',
        'permission_callback' => '__return_true',
    ));
});
