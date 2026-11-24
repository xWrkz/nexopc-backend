<?php
/**
 * Plugin Name: NexoPC ERP
 * Description: Indicadores, reportes, kardex y fichas para el ERP de NexoPC. Requiere manage_woocommerce.
 * Version: 1.0.0
 *
 * Se apoya en las funciones de nexopc-admin-api.php (se carga antes por orden alfabético):
 * nexopc_order_item, nexopc_order_payment_state, nexopc_stock_history, nexopc_stock_history_push.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------------------------ */
/* Ventas                                                              */
/* ------------------------------------------------------------------ */

/** Pedidos (sin borradores de checkout) creados entre dos marcas de tiempo, del más antiguo al más reciente. */
function nexopc_erp_orders_between($start, $end) {
    $statuses = array_diff(array_keys(wc_get_order_statuses()), array('wc-checkout-draft'));
    return wc_get_orders(array('limit' => -1, 'type' => 'shop_order', 'status' => $statuses, 'date_created' => $start . '...' . $end, 'orderby' => 'date', 'order' => 'ASC'));
}

/** Ingreso efectivo de un pedido: lo cobrado menos lo reembolsado. Cero si no se cobró. */
function nexopc_erp_order_revenue($order) {
    $state = nexopc_order_payment_state($order);
    if ($state !== 'paid' && $state !== 'refunded') return 0.0;
    return max(0.0, (float) $order->get_total() - (float) $order->get_total_refunded());
}

function nexopc_erp_local_date($order) {
    $date = $order->get_date_created();
    return $date ? wp_date('Y-m-d', $date->getTimestamp()) : '';
}

/** Categoría principal (la primera) de un producto, con caché por petición. */
function nexopc_erp_primary_category($product_id) {
    static $cache = array();
    if (isset($cache[$product_id])) return $cache[$product_id];
    $terms = get_the_terms($product_id, 'product_cat');
    $name = is_array($terms) && $terms ? $terms[0]->name : 'Sin categoría';
    return $cache[$product_id] = $name;
}

/**
 * Agrega un conjunto de pedidos. Las series diarias cubren cada día de [$start, $end] aunque no haya ventas.
 */
function nexopc_erp_aggregate($orders, $start, $end, $top = 5) {
    $tz = wp_timezone();
    $totals = array('revenue' => 0.0, 'orders' => 0, 'paidOrders' => 0, 'units' => 0, 'pendingAmount' => 0.0, 'pendingOrders' => 0, 'refundedAmount' => 0.0, 'failedOrders' => 0);
    $daily = array();
    for ($day = (new DateTime('@' . $start))->setTimezone($tz)->setTime(0, 0); $day->getTimestamp() <= $end; $day->modify('+1 day')) {
        $daily[$day->format('Y-m-d')] = array('date' => $day->format('Y-m-d'), 'revenue' => 0.0, 'orders' => 0, 'paidOrders' => 0);
    }
    $by_status = array();
    $by_method = array();
    $products = array();
    $categories = array();

    foreach ($orders as $order) {
        $state = nexopc_order_payment_state($order);
        $revenue = nexopc_erp_order_revenue($order);
        $day = nexopc_erp_local_date($order);
        $status = $order->get_status();
        $totals['orders']++;
        $by_status[$status] = ($by_status[$status] ?? 0) + 1;
        if (isset($daily[$day])) $daily[$day]['orders']++;

        if ($state === 'pending') { $totals['pendingAmount'] += (float) $order->get_total(); $totals['pendingOrders']++; }
        if ($state === 'failed' || $state === 'cancelled') $totals['failedOrders']++;
        $totals['refundedAmount'] += (float) $order->get_total_refunded();
        if ($state !== 'paid') {
            if ($revenue > 0) { $totals['revenue'] += $revenue; if (isset($daily[$day])) $daily[$day]['revenue'] += $revenue; }
            continue;
        }

        $totals['revenue'] += $revenue;
        $totals['paidOrders']++;
        if (isset($daily[$day])) { $daily[$day]['revenue'] += $revenue; $daily[$day]['paidOrders']++; }
        $method = $order->get_payment_method() ?: 'otro';
        if (!isset($by_method[$method])) $by_method[$method] = array('method' => $method, 'title' => $order->get_payment_method_title() ?: $method, 'revenue' => 0.0, 'orders' => 0);
        $by_method[$method]['revenue'] += $revenue;
        $by_method[$method]['orders']++;

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            $quantity = (int) $item->get_quantity();
            $line = (float) $item->get_total();
            $totals['units'] += $quantity;
            if (!isset($products[$product_id])) $products[$product_id] = array('productId' => $product_id, 'name' => $item->get_name(), 'quantity' => 0, 'revenue' => 0.0);
            $products[$product_id]['quantity'] += $quantity;
            $products[$product_id]['revenue'] += $line;
            $category = $product_id ? nexopc_erp_primary_category($product_id) : 'Sin categoría';
            if (!isset($categories[$category])) $categories[$category] = array('name' => $category, 'quantity' => 0, 'revenue' => 0.0);
            $categories[$category]['quantity'] += $quantity;
            $categories[$category]['revenue'] += $line;
        }
    }

    $by_revenue = function ($a, $b) { return $b['revenue'] <=> $a['revenue']; };
    usort($products, $by_revenue);
    usort($categories, $by_revenue);
    usort($by_method, $by_revenue);
    $round = function ($rows) { return array_map(function ($row) { $row['revenue'] = round($row['revenue'], 2); return $row; }, array_values($rows)); };
    $totals['avgTicket'] = $totals['paidOrders'] ? round($totals['revenue'] / $totals['paidOrders'], 2) : 0;
    foreach (array('revenue', 'pendingAmount', 'refundedAmount') as $key) $totals[$key] = round($totals[$key], 2);

    return array(
        'totals' => $totals,
        'daily' => $round($daily),
        'byStatus' => $by_status,
        'byPaymentMethod' => $round($by_method),
        'topProducts' => $round(array_slice($products, 0, $top)),
        'byCategory' => $round($categories),
    );
}

function nexopc_erp_count_orders($statuses) {
    $query = wc_get_orders(array('limit' => 1, 'paginate' => true, 'type' => 'shop_order', 'status' => $statuses, 'return' => 'ids'));
    return (int) $query->total;
}

function nexopc_erp_dashboard() {
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $today = (clone $now)->setTime(0, 0);
    $month_start = (clone $today)->modify('first day of this month');
    $prev_start = (clone $month_start)->modify('-1 month');
    // Mismo tramo del mes anterior (p. ej. 1–23 oct frente a 1–23 nov), acotado al último día de ese mes.
    $prev_end = (clone $prev_start)->setDate((int) $prev_start->format('Y'), (int) $prev_start->format('m'), min((int) $now->format('d'), (int) $prev_start->format('t')))->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    $series_start = (clone $today)->modify('-29 days');
    $query_start = min($prev_start->getTimestamp(), $series_start->getTimestamp());

    $orders = nexopc_erp_orders_between($query_start, $now->getTimestamp());
    $in = function ($from, $to) use ($orders) {
        return array_values(array_filter($orders, function ($order) use ($from, $to) { $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0; return $ts >= $from && $ts <= $to; }));
    };

    $month = nexopc_erp_aggregate($in($month_start->getTimestamp(), $now->getTimestamp()), $month_start->getTimestamp(), $now->getTimestamp());
    $previous = nexopc_erp_aggregate($in($prev_start->getTimestamp(), $prev_end->getTimestamp()), $prev_start->getTimestamp(), $prev_end->getTimestamp());
    $last30 = nexopc_erp_aggregate($in($series_start->getTimestamp(), $now->getTimestamp()), $series_start->getTimestamp(), $now->getTimestamp(), 5);
    $today_data = nexopc_erp_aggregate($in($today->getTimestamp(), $now->getTimestamp()), $today->getTimestamp(), $now->getTimestamp());

    $recent = wc_get_orders(array('limit' => 6, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => array_diff(array_keys(wc_get_order_statuses()), array('wc-checkout-draft'))));

    return rest_ensure_response(array(
        'generatedAt' => $now->format(DATE_ATOM),
        'today' => array('revenue' => $today_data['totals']['revenue'], 'orders' => $today_data['totals']['orders']),
        'month' => $month['totals'],
        'previousMonth' => array('revenue' => $previous['totals']['revenue'], 'orders' => $previous['totals']['orders'], 'paidOrders' => $previous['totals']['paidOrders']),
        'attention' => array(
            'toShip' => nexopc_erp_count_orders(array('processing')),
            'awaitingPayment' => nexopc_erp_count_orders(array('pending', 'on-hold')),
            'onHold' => nexopc_erp_count_orders(array('on-hold')),
        ),
        'daily' => $last30['daily'],
        'byStatus' => $last30['byStatus'],
        'topProducts' => $last30['topProducts'],
        'stock' => nexopc_erp_stock_summary(6),
        'recent' => array_map(function ($order) { return nexopc_order_item($order); }, $recent),
    ));
}

function nexopc_erp_report(WP_REST_Request $request) {
    $tz = wp_timezone();
    $from = nexopc_valid_date_param($request->get_param('from'));
    $to = nexopc_valid_date_param($request->get_param('to'));
    $end = $to ? new DateTime($to . ' 23:59:59', $tz) : (new DateTime('now', $tz))->setTime(23, 59, 59);
    $start = $from ? new DateTime($from . ' 00:00:00', $tz) : (clone $end)->modify('-29 days')->setTime(0, 0);
    if ($start > $end) return nexopc_error('nexopc_invalid_range', 'La fecha inicial debe ser anterior a la final.', 422);
    if ($end->getTimestamp() - $start->getTimestamp() > 400 * DAY_IN_SECONDS) return nexopc_error('nexopc_range_too_long', 'El rango máximo es de un año.', 422);

    $report = nexopc_erp_aggregate(nexopc_erp_orders_between($start->getTimestamp(), $end->getTimestamp()), $start->getTimestamp(), $end->getTimestamp(), 10);
    $report['from'] = $start->format('Y-m-d');
    $report['to'] = $end->format('Y-m-d');
    return rest_ensure_response($report);
}

/* ------------------------------------------------------------------ */
/* Inventario                                                          */
/* ------------------------------------------------------------------ */

/** Resumen de existencias de todo el catálogo publicado (no solo de una página del listado). */
function nexopc_erp_stock_summary($alert_limit = 10) {
    global $wpdb;
    $default_low = absint(get_option('woocommerce_notify_low_stock_amount', 2));
    $rows = $wpdb->get_results(
        "SELECT p.ID AS id, p.post_title AS name, p.post_parent AS parent, l.sku, l.stock_quantity AS qty, l.stock_status AS status, l.max_price AS price, ms.meta_value AS manage, ls.meta_value AS low
         FROM {$wpdb->posts} p
         JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
         LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock'
         LEFT JOIN {$wpdb->postmeta} ls ON ls.post_id = p.ID AND ls.meta_key = '_low_stock_amount'
         WHERE p.post_type IN ('product', 'product_variation') AND p.post_status IN ('publish', 'private')",
        ARRAY_A
    );
    $summary = array('products' => 0, 'managed' => 0, 'low' => 0, 'out' => 0, 'units' => 0, 'value' => 0.0, 'lowThreshold' => $default_low, 'alerts' => array());
    $alerts = array();
    foreach ((array) $rows as $row) {
        $summary['products']++;
        $managed = $row['manage'] === 'yes';
        $qty = $row['qty'] === null ? null : (int) $row['qty'];
        $low = $row['low'] !== null && $row['low'] !== '' ? (int) $row['low'] : $default_low;
        $state = null;
        if ($row['status'] === 'outofstock') { $summary['out']++; $state = 'out'; }
        if ($managed) {
            $summary['managed']++;
            if ($qty !== null && $qty > 0) {
                $summary['units'] += $qty;
                $summary['value'] += $qty * (float) $row['price'];
                if ($qty <= $low && $state === null) { $summary['low']++; $state = 'low'; }
            }
        }
        if ($state) $alerts[] = array('id' => (int) $row['id'], 'parentId' => (int) $row['parent'] ?: null, 'name' => $row['name'], 'sku' => (string) $row['sku'], 'stockQuantity' => $qty, 'lowStockAmount' => $low, 'state' => $state);
    }
    usort($alerts, function ($a, $b) { return array($a['state'] === 'out' ? 0 : 1, (int) $a['stockQuantity']) <=> array($b['state'] === 'out' ? 0 : 1, (int) $b['stockQuantity']); });
    $summary['alerts'] = array_slice($alerts, 0, $alert_limit);
    $summary['value'] = round($summary['value'], 2);
    return $summary;
}

function nexopc_erp_stock_summary_endpoint() {
    return rest_ensure_response(nexopc_erp_stock_summary(50));
}

function nexopc_erp_product_label($product_id) {
    static $cache = array();
    if (isset($cache[$product_id])) return $cache[$product_id];
    $product = wc_get_product($product_id);
    if (!$product) return $cache[$product_id] = array('name' => 'Producto #' . $product_id, 'sku' => '', 'parentId' => null);
    $name = $product->get_name();
    if ($product->is_type('variation')) $name = $product->get_title() . ' · ' . implode(' / ', $product->get_variation_attributes());
    return $cache[$product_id] = array('name' => $name, 'sku' => $product->get_sku(), 'parentId' => $product->get_parent_id() ?: null);
}

function nexopc_erp_user_name($user_id) {
    static $cache = array();
    $user_id = absint($user_id);
    if (!$user_id) return 'Sistema';
    if (!isset($cache[$user_id])) { $user = get_userdata($user_id); $cache[$user_id] = $user ? $user->display_name : 'Usuario #' . $user_id; }
    return $cache[$user_id];
}

/** Kardex: movimientos de stock de un producto o de todo el catálogo, del más reciente al más antiguo. */
function nexopc_erp_inventory_movements(WP_REST_Request $request) {
    global $wpdb;
    $paging = nexopc_pagination_args($request);
    $product_id = absint($request->get_param('productId'));
    $type = sanitize_key($request->get_param('type'));
    if ($product_id) {
        $sources = array($product_id => nexopc_stock_history($product_id));
    } else {
        $sources = array();
        foreach ((array) $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_nexopc_stock_history'", ARRAY_A) as $row) {
            $history = maybe_unserialize($row['meta_value']);
            if (is_array($history)) $sources[(int) $row['post_id']] = array_values(array_filter($history, 'is_array'));
        }
    }

    $movements = array();
    foreach ($sources as $id => $history) {
        foreach ($history as $index => $entry) {
            $mode = $entry['mode'] ?? 'increase';
            $entry_type = $entry['type'] ?? 'adjust';
            if ($type && $entry_type !== $type) continue;
            $quantity = (int) ($entry['quantity'] ?? 0);
            $result = isset($entry['result']) ? (int) $entry['result'] : null;
            $previous = isset($entry['previous']) ? (int) $entry['previous'] : null;
            $delta = $mode === 'increase' ? $quantity : ($mode === 'decrease' ? -$quantity : ($previous !== null && $result !== null ? $result - $previous : null));
            $label = nexopc_erp_product_label($id);
            $movements[] = array(
                'key' => $id . '-' . $index,
                'date' => $entry['date'] ?? null,
                'productId' => $id,
                'productName' => $label['name'],
                'sku' => $label['sku'],
                'type' => $entry_type,
                'mode' => $mode,
                'quantity' => $quantity,
                'delta' => $delta,
                'previous' => $previous,
                'result' => $result,
                'reason' => (string) ($entry['reason'] ?? ''),
                'orderId' => isset($entry['orderId']) ? (int) $entry['orderId'] : null,
                'orderNumber' => isset($entry['orderNumber']) ? (string) $entry['orderNumber'] : null,
                'user' => nexopc_erp_user_name($entry['user'] ?? 0),
                'demo' => !empty($entry['demo']),
            );
        }
    }
    usort($movements, function ($a, $b) { return strtotime((string) $b['date']) <=> strtotime((string) $a['date']); });
    $total = count($movements);
    return rest_ensure_response(array('items' => array_slice($movements, ($paging['page'] - 1) * $paging['per_page'], $paging['per_page']), 'page' => $paging['page'], 'perPage' => $paging['per_page'], 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $paging['per_page']))));
}

// Ventas y reposiciones automáticas de WooCommerce también quedan en el kardex.
add_action('woocommerce_reduce_order_item_stock', function ($item, $change, $order) {
    $product = $change['product'] ?? null;
    if (!$product instanceof WC_Product || !$order instanceof WC_Order) return;
    $from = (int) ($change['from'] ?? 0);
    $to = (int) ($change['to'] ?? 0);
    nexopc_stock_history_push($product->get_id(), array('type' => 'sale', 'mode' => 'decrease', 'quantity' => max(0, $from - $to), 'previous' => $from, 'result' => $to, 'orderId' => $order->get_id(), 'orderNumber' => (string) $order->get_order_number(), 'reason' => 'Venta · pedido #' . $order->get_order_number(), 'demo' => (bool) $order->get_meta('_nexopc_demo')));
}, 10, 3);

add_action('woocommerce_restore_order_item_stock', function ($item, $new_stock, $old_stock, $order) {
    $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
    if (!$product || !$order instanceof WC_Order) return;
    nexopc_stock_history_push($product->get_id(), array('type' => 'return', 'mode' => 'increase', 'quantity' => max(0, (int) $new_stock - (int) $old_stock), 'previous' => (int) $old_stock, 'result' => (int) $new_stock, 'orderId' => $order->get_id(), 'orderNumber' => (string) $order->get_order_number(), 'reason' => 'Reposición · pedido #' . $order->get_order_number() . ' ' . wc_get_order_status_name($order->get_status()), 'demo' => (bool) $order->get_meta('_nexopc_demo')));
}, 10, 4);

/** Unidades físicas con el nombre del producto (para la pestaña de números de serie). */
function nexopc_erp_units_list(WP_REST_Request $request) {
    global $wpdb;
    $p = $wpdb->prefix . 'nexopc_';
    $where = array('1=1');
    $args = array();
    if ($request->get_param('sellableId')) { $where[] = 'u.sellable_id = %d'; $args[] = absint($request->get_param('sellableId')); }
    if ($request->get_param('status')) { $where[] = 'u.estado = %s'; $args[] = sanitize_key($request->get_param('status')); }
    if ($request->get_param('search')) { $where[] = 'u.serial_normalizado LIKE %s'; $args[] = '%' . $wpdb->esc_like(strtoupper(preg_replace('/\s+/', '', sanitize_text_field($request->get_param('search'))))) . '%'; }
    $sql = "SELECT u.id, u.sellable_id AS sellableId, u.serial_numero AS serial, u.estado AS status, u.origen AS origin, u.proveedor AS supplier, u.recibido_at AS receivedAt, u.garantia_hasta AS warrantyUntil, u.notas AS notes, u.created_at AS createdAt, a.order_item_id AS orderItemId
            FROM {$p}unidades_inventario u LEFT JOIN {$p}pedido_item_unidad a ON a.unidad_id = u.id
            WHERE " . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT 200';
    $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
    $counts = array();
    foreach ((array) $wpdb->get_results("SELECT estado, COUNT(*) AS total FROM {$p}unidades_inventario GROUP BY estado", ARRAY_A) as $row) $counts[$row['estado']] = (int) $row['total'];
    $items = array_map(function ($row) {
        $label = nexopc_erp_product_label((int) $row['sellableId']);
        $order_id = $row['orderItemId'] ? (int) wc_get_order_id_by_order_item_id((int) $row['orderItemId']) : 0;
        $order = $order_id ? wc_get_order($order_id) : null;
        return array_merge($row, array('id' => (int) $row['id'], 'sellableId' => (int) $row['sellableId'], 'productName' => $label['name'], 'sku' => $label['sku'], 'orderId' => $order_id ?: null, 'orderNumber' => $order ? (string) $order->get_order_number() : null));
    }, (array) $rows);
    return rest_ensure_response(array('items' => $items, 'counts' => $counts));
}

/* ------------------------------------------------------------------ */
/* Clientes y pedidos                                                  */
/* ------------------------------------------------------------------ */

function nexopc_erp_customer_get(WP_REST_Request $request) {
    $user = get_user_by('id', absint($request['id']));
    if (!$user) return nexopc_error('nexopc_customer_not_found', 'Cliente no encontrado.', 404);
    $customer = new WC_Customer($user->ID);
    $orders = wc_get_orders(array('customer_id' => $user->ID, 'limit' => 100, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC'));
    $stats = array('orders' => count($orders), 'paidOrders' => 0, 'revenue' => 0.0, 'pendingAmount' => 0.0, 'avgTicket' => 0, 'firstOrderAt' => null, 'lastOrderAt' => null);
    foreach ($orders as $order) {
        $state = nexopc_order_payment_state($order);
        $stats['revenue'] += nexopc_erp_order_revenue($order);
        if ($state === 'paid') $stats['paidOrders']++;
        if ($state === 'pending') $stats['pendingAmount'] += (float) $order->get_total();
        $date = nexopc_order_date($order->get_date_created());
        if ($date && (!$stats['lastOrderAt'] || $date > $stats['lastOrderAt'])) $stats['lastOrderAt'] = $date;
        if ($date && (!$stats['firstOrderAt'] || $date < $stats['firstOrderAt'])) $stats['firstOrderAt'] = $date;
    }
    $stats['revenue'] = round($stats['revenue'], 2);
    $stats['pendingAmount'] = round($stats['pendingAmount'], 2);
    $stats['avgTicket'] = $stats['paidOrders'] ? round($stats['revenue'] / $stats['paidOrders'], 2) : 0;
    $states = (array) WC()->countries->get_states($customer->get_billing_country() ?: 'PE');
    $state = $customer->get_billing_state();

    return rest_ensure_response(array_merge(nexopc_customer_summary($user), array(
        'roles' => array_values($user->roles),
        'address' => array('address' => $customer->get_billing_address_1(), 'district' => $customer->get_billing_city(), 'region' => $states[$state] ?? $state, 'postcode' => $customer->get_billing_postcode()),
        'stats' => $stats,
        'orderList' => array_map(function ($order) { return nexopc_order_item($order); }, $orders),
    )));
}

function nexopc_erp_order_note_add(WP_REST_Request $request) {
    $order = wc_get_order(absint($request['id']));
    if (!$order) return nexopc_error('nexopc_order_not_found', 'Pedido no encontrado.', 404);
    $data = nexopc_request_data($request);
    $content = sanitize_textarea_field($data['content'] ?? '');
    if ($content === '') return nexopc_error('nexopc_note_required', 'Escribe el contenido de la nota.', 422, array('content' => 'La nota no puede estar vacía.'));
    $order->add_order_note($content, !empty($data['customer']) ? 1 : 0, true);
    return new WP_REST_Response(nexopc_order_item(wc_get_order($order->get_id()), true), 201);
}

add_action('rest_api_init', function () {
    $permission = 'nexopc_admin_permission';
    register_rest_route('nexopc/v1', '/erp/dashboard', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_dashboard', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/erp/report', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_report', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/inventory/summary', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_stock_summary_endpoint', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/inventory/movements', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_inventory_movements', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/inventory/units', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_units_list', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/customers/(?P<id>\d+)', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_erp_customer_get', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/orders/(?P<id>\d+)/notes', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_erp_order_note_add', 'permission_callback' => $permission));
});
