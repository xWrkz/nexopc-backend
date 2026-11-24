<?php
/**
 * Datos de demostración para el ERP de NexoPC.
 *
 * Uso (dentro del contenedor de WordPress, con el mu-plugin nexopc-erp.php ya desplegado):
 *   php seed-demo.php            Informa qué haría y qué datos demo existen. No cambia nada.
 *   php seed-demo.php --apply    Crea clientes, pedidos, stock inicial y números de serie demo.
 *   php seed-demo.php --purge    Borra todo lo marcado como demo y devuelve el stock a su estado previo.
 *
 * Todo lo creado queda marcado (_nexopc_demo, created_via=nexopc-demo, origen=demo, correos
 * @demo.nexopc.test) para poder borrarlo sin tocar datos reales. No envía correos.
 * Solo usa productos publicados con precio que NO gestionaban stock; guarda su estado previo
 * y --purge lo restaura. Ningún producto queda agotado por la demo (stock final >= 1).
 */
if (php_sapi_name() !== 'cli') exit(1);

if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require (getenv('WP_LOAD_PATH') ?: '/var/www/html/wp-load.php');
require_once ABSPATH . 'wp-admin/includes/user.php';

const NEXOPC_DEMO_META = '_nexopc_demo';
const NEXOPC_DEMO_STOCK_META = '_nexopc_demo_prev_stock';
const NEXOPC_DEMO_SOURCE = 'nexopc-demo';
const NEXOPC_DEMO_DOMAIN = '@demo.nexopc.test';
const NEXOPC_DEMO_ORDERS = 48;
const NEXOPC_DEMO_DAYS = 60;

// Nada de correos: ni de pedidos ni de cuentas nuevas.
add_filter('pre_wp_mail', '__return_false');

$arguments = array_slice($argv, 1);
$apply = in_array('--apply', $arguments, true);
$purge = in_array('--purge', $arguments, true);

function demo_out($text) { fwrite(STDOUT, $text . PHP_EOL); }

function demo_existing_orders() {
    $orders = wc_get_orders(array('limit' => -1, 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_query' => array(array('key' => NEXOPC_DEMO_META, 'value' => '1'))));
    // Doble control: solo pedidos creados por este script.
    return array_values(array_filter($orders, function ($order) { return $order->get_created_via() === NEXOPC_DEMO_SOURCE; }));
}

function demo_existing_users() {
    $users = get_users(array('meta_key' => NEXOPC_DEMO_META, 'meta_value' => '1'));
    return array_values(array_filter($users, function ($user) { return array_values($user->roles) === array('customer') && substr($user->user_email, -strlen(NEXOPC_DEMO_DOMAIN)) === NEXOPC_DEMO_DOMAIN; }));
}

function demo_stocked_products() {
    return get_posts(array('post_type' => array('product', 'product_variation'), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => NEXOPC_DEMO_STOCK_META));
}

function demo_units_table() { global $wpdb; return $wpdb->prefix . 'nexopc_unidades_inventario'; }
function demo_assign_table() { global $wpdb; return $wpdb->prefix . 'nexopc_pedido_item_unidad'; }

function demo_report() {
    global $wpdb;
    $units = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . demo_units_table() . " WHERE origen = 'demo'");
    demo_out(sprintf('Datos demo actuales: %d pedidos, %d clientes, %d productos con stock demo, %d unidades con serie.', count(demo_existing_orders()), count(demo_existing_users()), count(demo_stocked_products()), $units));
}

/* ------------------------------------------------------------------ */
/* Borrado                                                             */
/* ------------------------------------------------------------------ */

function demo_purge() {
    global $wpdb;
    $orders = demo_existing_orders();
    foreach ($orders as $order) {
        $item_ids = array_map('absint', array_keys($order->get_items()));
        if ($item_ids) $wpdb->query("DELETE FROM " . demo_assign_table() . " WHERE order_item_id IN (" . implode(',', $item_ids) . ")");
        foreach ($order->get_refunds() as $refund) $refund->delete(true);
        $order->delete(true);
    }
    demo_out(sprintf('Pedidos demo borrados: %d', count($orders)));

    $users = demo_existing_users();
    foreach ($users as $user) wp_delete_user($user->ID);
    demo_out(sprintf('Clientes demo borrados: %d', count($users)));

    $products = demo_stocked_products();
    foreach ($products as $product_id) {
        $prev = get_post_meta($product_id, NEXOPC_DEMO_STOCK_META, true);
        $product = wc_get_product($product_id);
        if ($product && is_array($prev)) {
            $product->set_manage_stock(!empty($prev['manage']));
            $product->set_stock_quantity($prev['qty']);
            $product->set_low_stock_amount($prev['low'] === null ? '' : $prev['low']);
            $product->set_stock_status($prev['status'] ?: 'instock');
            $product->save();
            if (is_array($prev['history']) && $prev['history']) update_post_meta($product_id, '_nexopc_stock_history', $prev['history']);
            else delete_post_meta($product_id, '_nexopc_stock_history');
        }
        delete_post_meta($product_id, NEXOPC_DEMO_STOCK_META);
    }
    demo_out(sprintf('Productos con stock restaurado: %d', count($products)));

    $unit_ids = array_map('absint', (array) $wpdb->get_col("SELECT id FROM " . demo_units_table() . " WHERE origen = 'demo'"));
    if ($unit_ids) {
        $wpdb->query("DELETE FROM " . demo_assign_table() . " WHERE unidad_id IN (" . implode(',', $unit_ids) . ")");
        $wpdb->query("DELETE FROM " . demo_units_table() . " WHERE origen = 'demo'");
    }
    demo_out(sprintf('Unidades con serie borradas: %d', count($unit_ids)));
}

/* ------------------------------------------------------------------ */
/* Generación                                                          */
/* ------------------------------------------------------------------ */

function demo_pick(array $weights) {
    $roll = mt_rand(1, array_sum($weights));
    foreach ($weights as $key => $weight) { $roll -= $weight; if ($roll <= 0) return $key; }
    return array_key_first($weights);
}

function demo_slug($text) { return str_replace('-', '', sanitize_title($text)); }

function demo_customers_data() {
    // Personas ficticias. Distrito, código de departamento de WooCommerce y dirección inventada.
    return array(
        array('Lucía', 'Ramírez Torres', 'Miraflores', 'LMA', 'Av. Larco 845, dpto. 302'),
        array('Diego', 'Fernández Quispe', 'Santiago de Surco', 'LMA', 'Jr. Los Pinos 128'),
        array('Valeria', 'Castillo Rojas', 'Cayma', 'ARE', 'Calle Misti 410'),
        array('Andrés', 'Mendoza Huamán', 'Trujillo', 'LAL', 'Av. España 1520'),
        array('Camila', 'Vargas Salazar', 'San Isidro', 'LMA', 'Calle Las Begonias 233, of. 5'),
        array('Mateo', 'Gutiérrez Flores', 'Wanchaq', 'CUS', 'Av. de la Cultura 902'),
        array('Sofía', 'Paredes Chávez', 'Los Olivos', 'LMA', 'Av. Carlos Izaguirre 677'),
        array('Joaquín', 'Herrera Ccori', 'Piura', 'PIU', 'Av. Grau 1104'),
        array('Daniela', 'Silva Navarro', 'Chiclayo', 'LAM', 'Calle Elías Aguirre 356'),
        array('Sebastián', 'Ríos Calderón', 'San Miguel', 'LMA', 'Av. La Marina 2155'),
        array('Ximena', 'Morales Aguilar', 'Yanahuara', 'ARE', 'Pasaje Sucre 118'),
        array('Renato', 'Campos Villanueva', 'Huancayo', 'JUN', 'Jr. Real 845'),
        array('Fernanda', 'Núñez Espinoza', 'Jesús María', 'LMA', 'Av. Salaverry 1890'),
        array('Gabriel', 'Torres Medina', 'Víctor Larco Herrera', 'LAL', 'Calle Los Cedros 271'),
        array('Mariana', 'León Zapata', 'La Molina', 'LMA', 'Av. Javier Prado Este 5670'),
    );
}

function demo_create_customers() {
    $states = (array) WC()->countries->get_states('PE');
    $now = time();
    $created = array();
    foreach (demo_customers_data() as $index => list($first, $last, $district, $state, $address)) {
        $email = demo_slug($first) . '.' . demo_slug(strtok($last, ' ')) . NEXOPC_DEMO_DOMAIN;
        $registered = $now - mt_rand(62, 120) * DAY_IN_SECONDS - mt_rand(0, 20 * HOUR_IN_SECONDS);
        $user_id = wp_insert_user(array('user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password(32), 'first_name' => $first, 'last_name' => $last, 'display_name' => $first . ' ' . $last, 'role' => 'customer', 'user_registered' => gmdate('Y-m-d H:i:s', $registered)));
        if (is_wp_error($user_id)) { demo_out('  No se pudo crear ' . $email . ': ' . $user_id->get_error_message()); continue; }
        update_user_meta($user_id, NEXOPC_DEMO_META, 1);
        update_user_meta($user_id, 'nexopc_dni', (string) mt_rand(70000000, 79999999));
        $state = isset($states[$state]) ? $state : 'LIM';
        $phone = '9' . mt_rand(10000000, 99999999);
        $customer = new WC_Customer($user_id);
        $address_data = array('first_name' => $first, 'last_name' => $last, 'address_1' => $address, 'city' => $district, 'state' => $state, 'postcode' => (string) mt_rand(15001, 15099), 'country' => 'PE');
        foreach ($address_data as $key => $value) { $customer->{"set_billing_$key"}($value); $customer->{"set_shipping_$key"}($value); }
        $customer->set_billing_email($email);
        $customer->set_billing_phone($phone);
        $customer->save();
        // Algunos clientes compran más que otros.
        $created[] = array('id' => $user_id, 'weight' => $index < 4 ? 4 : ($index < 9 ? 2 : 1), 'billing' => array_merge($address_data, array('email' => $email, 'phone' => $phone)));
    }
    return $created;
}

function demo_product_pool() {
    $products = wc_get_products(array('status' => 'publish', 'type' => 'simple', 'limit' => -1));
    return array_values(array_filter($products, function ($product) {
        return (float) $product->get_price() > 0 && !$product->get_manage_stock() && $product->get_meta(NEXOPC_KIT_TYPE_META) !== 'kit';
    }));
}

function demo_status_for($age_days, $method) {
    if ($age_days >= 10) $status = demo_pick(array('completed' => 70, 'processing' => 8, 'cancelled' => 8, 'failed' => 8, 'refunded' => 6));
    elseif ($age_days >= 3) $status = demo_pick(array('completed' => 35, 'processing' => 38, 'unpaid' => 12, 'failed' => 6, 'cancelled' => 9));
    else $status = demo_pick(array('processing' => 45, 'unpaid' => 27, 'completed' => 13, 'failed' => 10, 'cancelled' => 5));
    if ($status === 'unpaid') return $method === 'mercadopago' ? 'pending' : 'on-hold';
    if ($status === 'failed' && $method !== 'mercadopago') return 'cancelled';
    return $status;
}

/** Plan de pedidos (sin crear nada): fecha, cliente, líneas, método y estado final. */
function demo_plan_orders(array $customers, array $pool) {
    $tz = wp_timezone();
    $now = time();
    $midnight = (new DateTime('today', $tz))->getTimestamp();
    $weights = array();
    foreach ($customers as $index => $customer) $weights[$index] = $customer['weight'];
    $plan = array();
    for ($i = 0; $i < NEXOPC_DEMO_ORDERS; $i++) {
        if ($i < 3) $ts = mt_rand($midnight, max($midnight, $now - 10 * MINUTE_IN_SECONDS)); // pedidos de hoy
        else {
            $age = (int) floor(NEXOPC_DEMO_DAYS * pow(mt_rand() / mt_getrandmax(), 1.5));
            $day = (new DateTime('today', $tz))->modify("-$age days")->setTime(mt_rand(9, 22), mt_rand(0, 59), mt_rand(0, 59));
            $ts = min($day->getTimestamp(), $now - 15 * MINUTE_IN_SECONDS);
        }
        $age_days = (int) floor(($now - $ts) / DAY_IN_SECONDS);
        $method = demo_pick(array('mercadopago' => 70, 'bacs' => 20, 'cod' => 10));
        $lines = array();
        $count = demo_pick(array(1 => 60, 2 => 30, 3 => 10));
        foreach ((array) array_rand($pool, $count) as $key) $lines[] = array('product' => $pool[$key], 'qty' => demo_pick(array(1 => 85, 2 => 15)));
        $plan[] = array('ts' => $ts, 'customer' => $customers[demo_pick($weights)], 'lines' => $lines, 'method' => $method, 'status' => demo_status_for($age_days, $method));
    }
    usort($plan, function ($a, $b) { return $a['ts'] <=> $b['ts']; });
    return $plan;
}

/** Fecha las notas recién creadas de un pedido con la hora del evento que las generó. */
function demo_redate_notes($order_id, $ts, array &$seen) {
    foreach (wc_get_order_notes(array('order_id' => $order_id)) as $note) {
        if (isset($seen[$note->id])) continue;
        $seen[$note->id] = true;
        wp_update_comment(array('comment_ID' => $note->id, 'comment_date' => wp_date('Y-m-d H:i:s', $ts), 'comment_date_gmt' => gmdate('Y-m-d H:i:s', $ts)));
    }
}

function demo_create_order(array $entry) {
    $methods = array('mercadopago' => 'Mercado Pago', 'bacs' => 'Transferencia bancaria', 'cod' => 'Pago contraentrega');
    $now = time();
    $ts = $entry['ts'];
    $billing = $entry['customer']['billing'];
    $shipping = $billing;
    unset($shipping['email']);

    $order = wc_create_order(array('customer_id' => $entry['customer']['id'], 'created_via' => NEXOPC_DEMO_SOURCE));
    if (is_wp_error($order)) { demo_out('  wc_create_order falló: ' . $order->get_error_message()); return null; }
    foreach ($entry['lines'] as $line) $order->add_product($line['product'], $line['qty']);
    $order->set_address($billing, 'billing');
    $order->set_address($shipping, 'shipping');
    $order->set_payment_method($entry['method']);
    $order->set_payment_method_title($methods[$entry['method']]);
    if (mt_rand(1, 100) <= 20) $order->set_customer_note(array('Llamar antes de entregar.', 'Dejar en portería si no estoy.', 'Entregar después de las 6 p. m.', 'Es un regalo, por favor sin factura dentro.')[mt_rand(0, 3)]);
    $order->set_date_created($ts);
    $order->update_meta_data(NEXOPC_DEMO_META, 1);
    $order->calculate_totals(false);
    $order->save();

    $seen = array();
    demo_redate_notes($order->get_id(), $ts, $seen);
    $status = $entry['status'];
    $is_mp = $entry['method'] === 'mercadopago';
    $paid = in_array($status, array('processing', 'completed', 'refunded'), true);

    if ($is_mp) {
        $order->update_meta_data('_nexopc_mp_payment_method', array('credit_card visa', 'credit_card master', 'debit_card visa', 'account_money account_money', 'credit_card amex')[mt_rand(0, 4)]);
        $order->update_meta_data('_nexopc_mp_status', $paid ? 'processed' : ($status === 'failed' ? 'failed' : ($status === 'cancelled' ? 'canceled' : 'action_required')));
        $order->update_meta_data('_nexopc_mp_status_detail', $paid ? 'accredited' : ($status === 'failed' ? 'rejected' : ($status === 'cancelled' ? 'canceled' : 'waiting_payment')));
        $order->save();
    }

    if (!$is_mp && ($paid || $status === 'on-hold')) {
        $hold_ts = min($now, $ts + 60);
        $order->update_status('on-hold', $entry['method'] === 'bacs' ? 'Esperando la transferencia del cliente.' : 'Pago contraentrega: se cobra al entregar.');
        demo_redate_notes($order->get_id(), $hold_ts, $seen);
    }

    if ($paid) {
        $paid_ts = min($now, $ts + ($is_mp ? mt_rand(1, 15) * MINUTE_IN_SECONDS : mt_rand(2, 30) * HOUR_IN_SECONDS));
        $reference = $is_mp ? 'DEMO' . mt_rand(100000000, 999999999) : ($entry['method'] === 'bacs' ? 'OP-' . mt_rand(100000, 999999) : 'CE-' . mt_rand(1000, 9999));
        $order->set_date_paid($paid_ts);
        $order->set_transaction_id($reference);
        $order->save();
        $order->update_status('processing', $is_mp ? sprintf('Pago aprobado en Mercado Pago (%s). Transacción %s.', $order->get_meta('_nexopc_mp_payment_method'), $reference) : 'Cobro registrado. Referencia: ' . $reference . '.');
        demo_redate_notes($order->get_id(), $paid_ts, $seen);

        if ($status === 'completed' || $status === 'refunded') {
            $done_ts = min($now - MINUTE_IN_SECONDS, $paid_ts + mt_rand(18, 96) * HOUR_IN_SECONDS);
            $order->set_date_completed($done_ts);
            $order->save();
            $order->update_status('completed', 'Entregado al cliente por courier.');
            demo_redate_notes($order->get_id(), $done_ts, $seen);
            if ($status === 'refunded') {
                $refund_ts = min($now - MINUTE_IN_SECONDS, $done_ts + mt_rand(2, 6) * DAY_IN_SECONDS);
                $order->update_status('refunded', 'Devolución aprobada: producto con falla de fábrica.');
                demo_redate_notes($order->get_id(), $refund_ts, $seen);
                foreach ($order->get_refunds() as $refund) { $refund->set_date_created($refund_ts); $refund->save(); }
            }
        }
    } elseif ($status === 'failed') {
        $order->update_status('failed', 'Mercado Pago: pago rechazado por el emisor de la tarjeta.');
        demo_redate_notes($order->get_id(), min($now, $ts + 5 * MINUTE_IN_SECONDS), $seen);
    } elseif ($status === 'cancelled') {
        $order->update_status('cancelled', 'Cancelado a pedido del cliente.');
        demo_redate_notes($order->get_id(), min($now, $ts + mt_rand(1, 20) * HOUR_IN_SECONDS), $seen);
    }
    return wc_get_order($order->get_id());
}

function demo_apply() {
    global $wpdb;
    if (!function_exists('nexopc_stock_history_push') || !has_action('woocommerce_reduce_order_item_stock')) {
        demo_out('Falta el mu-plugin nexopc-erp.php (kardex). Despliega el backend antes de generar la demo.');
        exit(1);
    }
    if (demo_existing_orders() || demo_existing_users() || demo_stocked_products()) {
        demo_out('Ya hay datos demo. Ejecuta primero --purge.');
        exit(1);
    }
    $pool = demo_product_pool();
    if (count($pool) < 10) { demo_out('No hay suficientes productos publicados con precio y sin stock gestionado.'); exit(1); }
    mt_srand(crc32(gmdate('Y-m-d')));

    $customers = demo_create_customers();
    demo_out(sprintf('Clientes demo creados: %d', count($customers)));
    $plan = demo_plan_orders($customers, $pool);

    // Stock inicial: lo vendido en la demo más un margen, para que nada termine agotado.
    $sold = array();
    foreach ($plan as $entry) {
        if (in_array($entry['status'], array('pending', 'failed', 'cancelled'), true)) continue;
        foreach ($entry['lines'] as $line) $sold[$line['product']->get_id()] = ($sold[$line['product']->get_id()] ?? 0) + $line['qty'];
    }
    $stocked = array();
    foreach ($plan as $entry) foreach ($entry['lines'] as $line) $stocked[$line['product']->get_id()] = $line['product'];
    shuffle($pool);
    foreach (array_slice($pool, 0, 40) as $product) $stocked[$product->get_id()] = $product;
    $start_ts = (new DateTime('today', wp_timezone()))->modify('-' . (NEXOPC_DEMO_DAYS + 1) . ' days')->setTime(9, 0)->getTimestamp();
    foreach ($stocked as $id => $product) {
        update_post_meta($id, NEXOPC_DEMO_STOCK_META, array('manage' => $product->get_manage_stock(), 'qty' => $product->get_stock_quantity(), 'low' => $product->get_low_stock_amount() === '' ? null : $product->get_low_stock_amount(), 'status' => $product->get_stock_status(), 'history' => get_post_meta($id, '_nexopc_stock_history', true)));
        $initial = ($sold[$id] ?? 0) + (mt_rand(1, 100) <= 22 ? mt_rand(1, 2) : mt_rand(4, 24));
        $product = wc_get_product($id);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($initial);
        $product->set_low_stock_amount(3);
        $product->set_stock_status('instock');
        $product->save();
        delete_post_meta($id, '_nexopc_stock_history');
        update_post_meta($id, '_nexopc_stock_history', array(array('date' => wp_date(DATE_ATOM, $start_ts), 'user' => 0, 'type' => 'initial', 'mode' => 'set', 'quantity' => $initial, 'previous' => 0, 'result' => $initial, 'reason' => 'Inventario inicial (demo)', 'demo' => true)));
    }
    demo_out(sprintf('Productos con stock demo: %d', count($stocked)));

    $created = array();
    foreach ($plan as $entry) {
        $order = demo_create_order($entry);
        if ($order) $created[$order->get_id()] = $order;
    }
    $statuses = array();
    foreach ($created as $order) $statuses[$order->get_status()] = ($statuses[$order->get_status()] ?? 0) + 1;
    demo_out(sprintf('Pedidos demo creados: %d %s', count($created), wp_json_encode($statuses)));

    // El kardex registró las ventas con la hora actual: se fechan con la hora de pago/creación del pedido.
    foreach (array_keys($stocked) as $id) {
        $history = nexopc_stock_history($id);
        foreach ($history as &$move) {
            if (!empty($move['orderId']) && isset($created[$move['orderId']])) {
                $order = $created[$move['orderId']];
                $date = $order->get_date_paid() ?: $order->get_date_created();
                if ($move['type'] === 'return') $date = $order->get_date_modified() ?: $date;
                $move['date'] = wp_date(DATE_ATOM, $date->getTimestamp());
            }
        }
        unset($move);
        usort($history, function ($a, $b) { return strtotime($b['date']) <=> strtotime($a['date']); });
        update_post_meta($id, '_nexopc_stock_history', $history);
    }

    // Números de serie: unidades de 3 productos vendidos; una por pedido completado queda despachada.
    $units = 0;
    $completed_items = array();
    foreach ($created as $order) {
        if (!$order->has_status('completed')) continue;
        foreach ($order->get_items() as $item_id => $item) $completed_items[(int) $item->get_product_id()][] = $item_id;
    }
    $serial_products = array_slice(array_keys($completed_items), 0, 3);
    foreach ($serial_products as $product_id) {
        $received = wp_date('Y-m-d H:i:s', $start_ts + DAY_IN_SECONDS);
        for ($n = 0; $n < 5; $n++) {
            $serial = 'NXD' . strtoupper(substr(md5($product_id . '-' . $n . '-' . wp_rand()), 0, 10));
            $wpdb->insert(demo_units_table(), array('sellable_id' => $product_id, 'serial_numero' => $serial, 'serial_normalizado' => $serial, 'estado' => 'available', 'origen' => 'demo', 'proveedor' => 'Distribuidor mayorista (demo)', 'recibido_at' => $received, 'garantia_hasta' => wp_date('Y-m-d', strtotime('+1 year')), 'notas' => 'Unidad de demostración', 'created_at' => current_time('mysql', true)));
            $unit_id = (int) $wpdb->insert_id;
            if (!$unit_id) continue;
            $units++;
            if ($n === 0 && !empty($completed_items[$product_id])) {
                $wpdb->insert(demo_assign_table(), array('order_item_id' => $completed_items[$product_id][0], 'unidad_id' => $unit_id, 'asignado_at' => current_time('mysql', true), 'asignado_por' => null));
                $wpdb->update(demo_units_table(), array('estado' => 'shipped'), array('id' => $unit_id));
            }
        }
    }
    demo_out(sprintf('Unidades con serie creadas: %d', $units));
}

if ($purge) { demo_purge(); demo_report(); exit(0); }
if ($apply) { demo_apply(); demo_report(); exit(0); }

demo_report();
demo_out(sprintf('Con --apply se crearían 15 clientes y %d pedidos de los últimos %d días, con stock demo para %d productos candidatos.', NEXOPC_DEMO_ORDERS, NEXOPC_DEMO_DAYS, count(demo_product_pool())));
demo_out('Con --purge se borra todo lo marcado como demo.');
