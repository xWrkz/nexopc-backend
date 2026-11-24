<?php
/**
 * Plugin Name: NexoPC Admin API
 * Description: API privada para el ERP de NexoPC. Requiere manage_woocommerce.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

const NEXOPC_KIT_TYPE_META = '_nexopc_product_type';
const NEXOPC_KIT_ITEMS_META = '_nexopc_kit_items';
const NEXOPC_KIT_PRICING_META = '_nexopc_kit_pricing_mode';
const NEXOPC_KIT_DISCOUNT_META = '_nexopc_kit_discount';
const NEXOPC_INTERNAL_MODEL_CODE_META = '_nexopc_internal_model_code';

require_once __DIR__ . '/nexopc-hardware.php';
require_once __DIR__ . '/nexopc-traceability.php';

function nexopc_admin_permission() {
    return current_user_can('manage_woocommerce');
}

function nexopc_error($code, $message, $status = 400, $fields = array()) {
    return new WP_Error($code, $message, array('status' => $status, 'fields' => $fields));
}

function nexopc_request_data(WP_REST_Request $request) {
    $data = $request->get_json_params();
    return is_array($data) ? $data : array();
}

function nexopc_money($value) {
    if ($value === null || $value === '') return '';
    return wc_format_decimal($value);
}

function nexopc_bool($value) {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function nexopc_media_item($id) {
    $id = absint($id);
    if (!$id) return null;
    return array(
        'id' => $id,
        'url' => wp_get_attachment_url($id),
        'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
        'title' => get_the_title($id),
    );
}

function nexopc_term_item($term) {
    return array('id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => (int) $term->parent, 'count' => (int) $term->count);
}

function nexopc_product_type(WC_Product $product) {
    return $product->get_meta(NEXOPC_KIT_TYPE_META) === 'kit' ? 'kit' : $product->get_type();
}

function nexopc_product_item($product, $detail = false) {
    if (!$product instanceof WC_Product) return null;
    $image_id = $product->get_image_id();
    $categories = wp_get_post_terms($product->get_id(), 'product_cat');
    $tags = wp_get_post_terms($product->get_id(), 'product_tag');
    $data = array(
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'slug' => $product->get_slug(),
        'sku' => $product->get_sku(),
        'internalModelCode' => $product->get_meta(NEXOPC_INTERNAL_MODEL_CODE_META),
        'type' => nexopc_product_type($product),
        'status' => $product->get_status(),
        'catalogVisibility' => $product->get_catalog_visibility(),
        'featured' => $product->get_featured(),
        'regularPrice' => $product->get_regular_price(),
        'salePrice' => $product->get_sale_price(),
        'price' => $product->get_price(),
        'stockStatus' => $product->get_stock_status(),
        'manageStock' => $product->get_manage_stock(),
        'stockQuantity' => $product->get_stock_quantity(),
        'lowStockAmount' => $product->get_low_stock_amount(),
        'backorders' => $product->get_backorders(),
        'image' => nexopc_media_item($image_id),
        'categories' => array_map('nexopc_term_item', is_wp_error($categories) ? array() : $categories),
        'tags' => array_map('nexopc_term_item', is_wp_error($tags) ? array() : $tags),
        'modifiedAt' => $product->get_date_modified() ? $product->get_date_modified()->date(DATE_ATOM) : null,
        'brand' => nexopc_brand_for_product($product->get_id()),
        'serialPolicy' => nexopc_serial_policy_for_product($product->get_id()),
        'hardware' => nexopc_hardware_effective_item($product->get_id()),
    );
    if (!$detail) return $data;
    $gallery = array_filter(array_map('nexopc_media_item', $product->get_gallery_image_ids()));
    $attributes = array();
    foreach ($product->get_attributes() as $attribute) {
        $options = $attribute->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names')) : $attribute->get_options();
        $attributes[] = array(
            'id' => $attribute->is_taxonomy() ? wc_attribute_taxonomy_id_by_name($attribute->get_name()) : 0,
            'name' => wc_attribute_label($attribute->get_name()),
            'taxonomy' => $attribute->get_name(),
            'options' => array_values($options),
            'visible' => $attribute->get_visible(),
            'variation' => $attribute->get_variation(),
        );
    }
    $data['description'] = $product->get_description();
    $data['shortDescription'] = $product->get_short_description();
    $data['gallery'] = $gallery;
    $data['weight'] = $product->get_weight();
    $data['dimensions'] = array('length' => $product->get_length(), 'width' => $product->get_width(), 'height' => $product->get_height());
    $data['attributes'] = $attributes;
    $data['variations'] = array();
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            $data['variations'][] = array(
                'id' => $variation->get_id(), 'sku' => $variation->get_sku(), 'regularPrice' => $variation->get_regular_price(),
                'salePrice' => $variation->get_sale_price(), 'price' => $variation->get_price(), 'stockStatus' => $variation->get_stock_status(),
                'manageStock' => $variation->get_manage_stock(), 'stockQuantity' => $variation->get_stock_quantity(), 'weight' => $variation->get_weight(),
                'image' => nexopc_media_item($variation->get_image_id()), 'attributes' => $variation->get_attributes(), 'status' => $variation->get_status(),
                'hardware' => nexopc_hardware_effective_item($variation->get_id()), 'serialPolicy' => nexopc_serial_policy_for_product($variation->get_id()),
            );
        }
    }
    if (nexopc_product_type($product) === 'kit') {
        $data['kit'] = array(
            'pricingMode' => $product->get_meta(NEXOPC_KIT_PRICING_META) ?: 'fixed',
            'discount' => (float) $product->get_meta(NEXOPC_KIT_DISCOUNT_META),
            'items' => array_values((array) $product->get_meta(NEXOPC_KIT_ITEMS_META)),
        );
    }
    return $data;
}

function nexopc_validate_common_product($data, $is_publish, $requires_sellable_fields = true) {
    $errors = array();
    if (empty(trim((string) ($data['name'] ?? '')))) $errors['name'] = 'El nombre es obligatorio.';
    if ($is_publish && empty($data['categories'])) $errors['categories'] = 'Selecciona al menos una categoría para publicar.';
    if ($is_publish && $requires_sellable_fields && empty(trim((string) ($data['sku'] ?? '')))) $errors['sku'] = 'El SKU es obligatorio para publicar.';
    if ($is_publish && $requires_sellable_fields && ($data['regularPrice'] ?? '') === '') $errors['regularPrice'] = 'El precio regular es obligatorio para publicar.';
    if (isset($data['regularPrice']) && $data['regularPrice'] !== '' && (!is_numeric($data['regularPrice']) || (float) $data['regularPrice'] <= 0)) $errors['regularPrice'] = 'El precio regular debe ser un número mayor que cero.';
    if (isset($data['salePrice']) && $data['salePrice'] !== '' && !is_numeric($data['salePrice'])) $errors['salePrice'] = 'El precio de oferta no es válido.';
    if (($data['salePrice'] ?? '') !== '' && ($data['regularPrice'] ?? '') !== '' && (float) $data['salePrice'] > (float) $data['regularPrice']) $errors['salePrice'] = 'El precio de oferta no puede ser mayor que el precio regular.';
    $weight = trim((string) ($data['weight'] ?? ''));
    if ($weight !== '' && (!is_numeric($weight) || (float) $weight < 0)) $errors['weight'] = 'El peso debe ser un número igual o mayor que cero.';
    $dimensions = (array) ($data['dimensions'] ?? array());
    $dimension_keys = array('length' => 'largo', 'width' => 'ancho', 'height' => 'alto');
    $has_dimension = false;
    foreach ($dimension_keys as $key => $label) {
        $value = trim((string) ($dimensions[$key] ?? ''));
        if ($value !== '') $has_dimension = true;
        if ($value !== '' && (!is_numeric($value) || (float) $value < 0)) $errors['dimensions.' . $key] = 'El ' . $label . ' debe ser un número igual o mayor que cero.';
    }
    if ($has_dimension) foreach ($dimension_keys as $key => $label) if (trim((string) ($dimensions[$key] ?? '')) === '') $errors['dimensions.' . $key] = 'Completa el ' . $label . ' para registrar dimensiones.';
    if (!empty($errors)) return nexopc_error('nexopc_validation_error', 'Revisa los campos marcados.', 422, $errors);
    return true;
}

function nexopc_store_units() {
    return rest_ensure_response(array(
        'weight' => get_option('woocommerce_weight_unit', 'kg'),
        'dimension' => get_option('woocommerce_dimension_unit', 'cm'),
    ));
}

function nexopc_apply_stock(WC_Product $product, $data) {
    $manage = array_key_exists('manageStock', $data) ? nexopc_bool($data['manageStock']) : false;
    $product->set_manage_stock($manage);
    if ($manage && array_key_exists('stockQuantity', $data)) $product->set_stock_quantity(max(0, (int) $data['stockQuantity']));
    if (isset($data['stockStatus'])) $product->set_stock_status($data['stockStatus'] === 'outofstock' ? 'outofstock' : 'instock');
    if (isset($data['lowStockAmount'])) $product->set_low_stock_amount($data['lowStockAmount'] === '' ? '' : max(0, (int) $data['lowStockAmount']));
    if (isset($data['backorders'])) $product->set_backorders(in_array($data['backorders'], array('no', 'notify', 'yes'), true) ? $data['backorders'] : 'no');
}

function nexopc_apply_images(WC_Product $product, $data) {
    if (array_key_exists('imageId', $data)) $product->set_image_id(absint($data['imageId']));
    if (array_key_exists('galleryIds', $data)) $product->set_gallery_image_ids(array_map('absint', (array) $data['galleryIds']));
}

function nexopc_apply_common(WC_Product $product, $data) {
    $product->set_name(sanitize_text_field($data['name'] ?? ''));
    if (isset($data['slug'])) $product->set_slug(sanitize_title($data['slug']));
    if (array_key_exists('sku', $data)) $product->set_sku(sanitize_text_field($data['sku']));
    if (array_key_exists('internalModelCode', $data)) $product->update_meta_data(NEXOPC_INTERNAL_MODEL_CODE_META, sanitize_text_field($data['internalModelCode']));
    if (isset($data['description'])) $product->set_description(wp_kses_post($data['description']));
    if (isset($data['shortDescription'])) $product->set_short_description(wp_kses_post($data['shortDescription']));
    if (isset($data['regularPrice'])) $product->set_regular_price(nexopc_money($data['regularPrice']));
    if (isset($data['salePrice'])) $product->set_sale_price(nexopc_money($data['salePrice']));
    if (isset($data['status'])) $product->set_status(in_array($data['status'], array('draft', 'publish', 'private'), true) ? $data['status'] : 'draft');
    if (isset($data['catalogVisibility'])) $product->set_catalog_visibility(in_array($data['catalogVisibility'], array('visible', 'catalog', 'search', 'hidden'), true) ? $data['catalogVisibility'] : 'visible');
    if (isset($data['featured'])) $product->set_featured(nexopc_bool($data['featured']));
    if (isset($data['weight'])) $product->set_weight(wc_format_decimal($data['weight']));
    if (isset($data['dimensions'])) {
        $dimensions = (array) $data['dimensions'];
        $product->set_length(wc_format_decimal($dimensions['length'] ?? ''));
        $product->set_width(wc_format_decimal($dimensions['width'] ?? ''));
        $product->set_height(wc_format_decimal($dimensions['height'] ?? ''));
    }
    nexopc_apply_stock($product, $data);
    nexopc_apply_images($product, $data);
}

function nexopc_apply_terms($product_id, $data) {
    if (array_key_exists('categories', $data)) wp_set_object_terms($product_id, array_map('absint', (array) $data['categories']), 'product_cat');
    if (array_key_exists('tags', $data)) wp_set_object_terms($product_id, array_map('absint', (array) $data['tags']), 'product_tag');
}

function nexopc_attribute_taxonomy($id) {
    $name = wc_attribute_taxonomy_name_by_id(absint($id));
    return $name ?: '';
}

function nexopc_apply_attributes(WC_Product $product, $attributes_input) {
    $attributes = array();
    foreach ((array) $attributes_input as $position => $input) {
        $taxonomy = !empty($input['taxonomy']) ? sanitize_key($input['taxonomy']) : nexopc_attribute_taxonomy($input['id'] ?? 0);
        if (!$taxonomy || !taxonomy_exists($taxonomy)) continue;
        $options = array_values(array_filter(array_map('sanitize_text_field', (array) ($input['options'] ?? array()))));
        if (!$options) continue;
        wp_set_object_terms($product->get_id(), $options, $taxonomy, false);
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
        $attribute->set_name($taxonomy);
        $attribute->set_options(wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'term_id')));
        $attribute->set_position($position);
        $attribute->set_visible(!empty($input['visible']));
        $attribute->set_variation(!empty($input['variation']));
        $attributes[$taxonomy] = $attribute;
    }
    $product->set_attributes($attributes);
}

function nexopc_variation_attributes($input) {
    $attributes = array();
    foreach ((array) ($input['attributes'] ?? array()) as $attribute) {
        $taxonomy = !empty($attribute['taxonomy']) ? sanitize_key($attribute['taxonomy']) : nexopc_attribute_taxonomy($attribute['id'] ?? 0);
        $option = sanitize_title($attribute['option'] ?? '');
        if ($taxonomy && $option) $attributes[$taxonomy] = $option;
    }
    return $attributes;
}

function nexopc_save_variations(WC_Product_Variable $parent, $items) {
    $seen = array();
    foreach ((array) $items as $item) {
        $attributes = nexopc_variation_attributes($item);
        if (!$attributes) continue;
        if (empty(trim((string) ($item['sku'] ?? '')))) return nexopc_error('nexopc_variation_sku_required', 'Cada variación debe tener un SKU.', 422);
        if (($item['regularPrice'] ?? '') === '') return nexopc_error('nexopc_variation_price_required', 'Cada variación debe tener un precio regular.', 422);
        ksort($attributes);
        $key = wp_json_encode($attributes);
        if (isset($seen[$key])) return nexopc_error('nexopc_duplicate_variation', 'Hay combinaciones de variación duplicadas.', 422);
        $seen[$key] = true;
        $variation = !empty($item['id']) ? wc_get_product(absint($item['id'])) : null;
        if (!$variation || !$variation->is_type('variation')) $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());
        $variation->set_attributes($attributes);
        $variation->set_sku(sanitize_text_field($item['sku'] ?? ''));
        $variation->set_regular_price(nexopc_money($item['regularPrice'] ?? ''));
        $variation->set_sale_price(nexopc_money($item['salePrice'] ?? ''));
        $variation->set_manage_stock(!empty($item['manageStock']));
        if (!empty($item['manageStock']) && array_key_exists('stockQuantity', $item)) $variation->set_stock_quantity(max(0, (int) $item['stockQuantity']));
        $variation->set_stock_status(($item['stockStatus'] ?? 'instock') === 'outofstock' ? 'outofstock' : 'instock');
        $variation->set_weight(wc_format_decimal($item['weight'] ?? ''));
        if (isset($item['imageId'])) $variation->set_image_id(absint($item['imageId']));
        $variation->set_status(($item['status'] ?? 'publish') === 'private' ? 'private' : 'publish');
        $variation->save();
        if (array_key_exists('hardware', $item)) {
            if ($item['hardware'] === null || !empty($item['hardware']['inheritParent'])) nexopc_hardware_clear_product($variation->get_id());
            else {
                $hardware = nexopc_hardware_save_product($variation->get_id(), $item['hardware']);
                if (is_wp_error($hardware)) return $hardware;
            }
        }
        if (array_key_exists('serialPolicy', $item)) nexopc_set_serial_policy($variation->get_id(), $item['serialPolicy']);
    }
    WC_Product_Variable::sync($parent->get_id());
    return true;
}

function nexopc_validate_kit_items($product_id, $items) {
    if (count((array) $items) < 2) return nexopc_error('nexopc_kit_items_required', 'Un kit debe incluir al menos dos componentes.', 422);
    $unique = array();
    $normal = array();
    foreach ((array) $items as $item) {
        $target_id = absint($item['variationId'] ?? $item['productId'] ?? 0);
        $quantity = max(1, absint($item['quantity'] ?? 1));
        $target = wc_get_product($target_id);
        if (!$target || $target_id === absint($product_id) || $target->get_meta(NEXOPC_KIT_TYPE_META) === 'kit') return nexopc_error('nexopc_invalid_kit_item', 'El kit contiene un componente no permitido.', 422);
        if (isset($unique[$target_id])) return nexopc_error('nexopc_duplicate_kit_item', 'No repitas componentes dentro de un kit.', 422);
        $unique[$target_id] = true;
        $normal[] = array('productId' => absint($item['productId'] ?? $target->get_parent_id() ?: $target->get_id()), 'variationId' => !empty($item['variationId']) ? absint($item['variationId']) : null, 'quantity' => $quantity);
    }
    return $normal;
}

function nexopc_recalculate_kit($kit_id) {
    static $running = false;
    if ($running) return;
    $running = true;
    $kit = wc_get_product($kit_id);
    if (!$kit || $kit->get_meta(NEXOPC_KIT_TYPE_META) !== 'kit') { $running = false; return; }
    $items = (array) $kit->get_meta(NEXOPC_KIT_ITEMS_META);
    $possible = PHP_INT_MAX;
    $sum = 0.0;
    $available = true;
    foreach ($items as $item) {
        $component = wc_get_product(absint($item['variationId'] ?: $item['productId']));
        if (!$component || !$component->is_in_stock()) { $available = false; break; }
        $quantity = max(1, absint($item['quantity']));
        $sum += (float) $component->get_price() * $quantity;
        if ($component->managing_stock()) $possible = min($possible, (int) floor(max(0, $component->get_stock_quantity()) / $quantity));
    }
    if ($kit->get_meta(NEXOPC_KIT_PRICING_META) === 'sum') {
        $discount = min(100, max(0, (float) $kit->get_meta(NEXOPC_KIT_DISCOUNT_META)));
        $calculated = round($sum * (1 - $discount / 100), wc_get_price_decimals());
        $kit->set_regular_price((string) $sum);
        $kit->set_sale_price($discount > 0 ? (string) $calculated : '');
        $kit->set_price((string) ($discount > 0 ? $calculated : $sum));
    }
    $kit->set_manage_stock(false);
    $kit->set_stock_status($available && $possible !== 0 ? 'instock' : 'outofstock');
    $kit->save();
    $running = false;
}

function nexopc_save_kit(WC_Product $product, $data) {
    $kit_data = (array) ($data['kit'] ?? array());
    $items = nexopc_validate_kit_items($product->get_id(), $kit_data['items'] ?? array());
    if (is_wp_error($items)) return $items;
    $mode = ($kit_data['pricingMode'] ?? 'fixed') === 'sum' ? 'sum' : 'fixed';
    $discount = min(100, max(0, (float) ($kit_data['discount'] ?? 0)));
    $product->update_meta_data(NEXOPC_KIT_TYPE_META, 'kit');
    $product->update_meta_data(NEXOPC_KIT_ITEMS_META, $items);
    $product->update_meta_data(NEXOPC_KIT_PRICING_META, $mode);
    $product->update_meta_data(NEXOPC_KIT_DISCOUNT_META, $discount);
    $product->save();
    nexopc_recalculate_kit($product->get_id());
    return true;
}

function nexopc_save_product(WP_REST_Request $request, $existing_id = 0) {
    $data = nexopc_request_data($request);
    $status = $data['status'] ?? 'draft';
    $type = in_array($data['type'] ?? 'simple', array('simple', 'variable', 'kit'), true) ? $data['type'] : 'simple';
    $validation = nexopc_validate_common_product($data, $status === 'publish', $type !== 'variable');
    if (is_wp_error($validation)) return $validation;
    if ($status === 'publish' && $type === 'variable' && empty($data['variations'])) return nexopc_error('nexopc_variations_required', 'Crea al menos una variación antes de publicar.', 422);
    if ($type === 'variable') {
        // El padre solo agrupa variantes: su SKU e importes no son unidades vendibles.
        $data['sku'] = '';
        $data['regularPrice'] = '';
        $data['salePrice'] = '';
    }
    try {
        $existing = $existing_id ? wc_get_product($existing_id) : null;
        if ($existing_id && !$existing) return nexopc_error('nexopc_not_found', 'Producto no encontrado.', 404);
        if ($type === 'variable') $product = $existing && $existing->is_type('variable') ? $existing : new WC_Product_Variable($existing_id ?: 0);
        else $product = $existing && !$existing->is_type('variable') ? $existing : new WC_Product_Simple($existing_id ?: 0);
        nexopc_apply_common($product, $data);
        if ($type === 'kit') $product->set_manage_stock(false);
        $product_id = $product->save();
        nexopc_apply_terms($product_id, $data);
        $brand = nexopc_set_product_brand($product_id, $data['brandId'] ?? 0);
        if (is_wp_error($brand)) return $brand;
        $serial_policy = nexopc_set_serial_policy($product_id, $data['serialPolicy'] ?? 'none');
        if (is_wp_error($serial_policy)) return $serial_policy;
        $hardware = nexopc_hardware_save_product($product_id, $data['hardware'] ?? null);
        if (is_wp_error($hardware)) return $hardware;
        if ($type === 'variable') {
            nexopc_apply_attributes($product, $data['attributes'] ?? array());
            $product->save();
            $result = nexopc_save_variations($product, $data['variations'] ?? array());
            if (is_wp_error($result)) return $result;
        }
        if ($type === 'kit') {
            $result = nexopc_save_kit($product, $data);
            if (is_wp_error($result)) return $result;
        } elseif ($existing && $existing->get_meta(NEXOPC_KIT_TYPE_META) === 'kit') {
            $product->delete_meta_data(NEXOPC_KIT_TYPE_META);
            $product->delete_meta_data(NEXOPC_KIT_ITEMS_META);
            $product->delete_meta_data(NEXOPC_KIT_PRICING_META);
            $product->delete_meta_data(NEXOPC_KIT_DISCOUNT_META);
            $product->save();
        }
        return new WP_REST_Response(nexopc_product_item(wc_get_product($product_id), true), $existing_id ? 200 : 201);
    } catch (Exception $error) {
        return nexopc_error('nexopc_product_save_failed', $error->getMessage(), 422);
    }
}

function nexopc_list_products(WP_REST_Request $request) {
    $page = max(1, absint($request->get_param('page') ?: 1));
    $per_page = min(100, max(1, absint($request->get_param('perPage') ?: 20)));
    $args = array('limit' => $per_page, 'page' => $page, 'paginate' => true, 'status' => $request->get_param('status') ?: array('publish', 'draft', 'private'));
    if ($request->get_param('search')) $args['s'] = sanitize_text_field($request->get_param('search'));
    if ($request->get_param('category')) $args['category'] = array(absint($request->get_param('category')));
    if ($request->get_param('sku')) $args['sku'] = sanitize_text_field($request->get_param('sku'));
    if ($request->get_param('stockStatus')) $args['stock_status'] = sanitize_text_field($request->get_param('stockStatus'));
    $query = wc_get_products($args);
    $items = array_values(array_filter(array_map(function ($product) use ($request) {
        $type = $request->get_param('type');
        if ($type && nexopc_product_type($product) !== $type) return null;
        return nexopc_product_item($product);
    }, $query->products)));
    return rest_ensure_response(array('items' => $items, 'page' => $page, 'perPage' => $per_page, 'total' => (int) $query->total, 'totalPages' => (int) $query->max_num_pages));
}

function nexopc_get_product(WP_REST_Request $request) {
    $product = wc_get_product(absint($request['id']));
    return $product ? rest_ensure_response(nexopc_product_item($product, true)) : nexopc_error('nexopc_not_found', 'Producto no encontrado.', 404);
}

function nexopc_delete_product(WP_REST_Request $request) {
    $product = wc_get_product(absint($request['id']));
    if (!$product) return nexopc_error('nexopc_not_found', 'Producto no encontrado.', 404);
    if (nexopc_bool($request->get_param('force'))) {
        $product->delete(true);
        return new WP_REST_Response(null, 204);
    }
    $product->delete(false);
    return rest_ensure_response(array('id' => absint($request['id']), 'status' => 'trash'));
}

function nexopc_restore_product(WP_REST_Request $request) {
    $product_id = absint($request['id']);
    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'product') return nexopc_error('nexopc_not_found', 'Producto no encontrado.', 404);
    $restored = wp_untrash_post($product_id);
    if (!$restored) return nexopc_error('nexopc_restore_failed', 'No se pudo restaurar el producto.', 422);
    $product = wc_get_product($product_id);
    return rest_ensure_response(nexopc_product_item($product, true));
}

function nexopc_bulk_products(WP_REST_Request $request) {
    $data = nexopc_request_data($request);
    $ids = array_map('absint', (array) ($data['ids'] ?? array()));
    $action = sanitize_key($data['action'] ?? '');
    if (!$ids || !in_array($action, array('publish', 'draft', 'trash', 'set_category'), true)) return nexopc_error('nexopc_invalid_bulk_action', 'Acción masiva no válida.', 422);
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product) continue;
        if ($action === 'trash') $product->delete(false);
        elseif ($action === 'set_category') wp_set_object_terms($id, array(absint($data['categoryId'] ?? 0)), 'product_cat');
        else { $product->set_status($action); $product->save(); }
    }
    return rest_ensure_response(array('updated' => count($ids)));
}

function nexopc_taxonomy_list($taxonomy) {
    $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 100, 'orderby' => 'name'));
    return rest_ensure_response(array('items' => array_map('nexopc_term_item', is_wp_error($terms) ? array() : $terms)));
}

function nexopc_taxonomy_save(WP_REST_Request $request, $taxonomy, $id = 0) {
    $data = nexopc_request_data($request);
    $name = sanitize_text_field($data['name'] ?? '');
    if (!$name) return nexopc_error('nexopc_term_name_required', 'El nombre es obligatorio.', 422, array('name' => 'El nombre es obligatorio.'));
    $args = array('slug' => sanitize_title($data['slug'] ?? ''), 'description' => wp_kses_post($data['description'] ?? ''));
    if ($taxonomy === 'product_cat') $args['parent'] = absint($data['parent'] ?? 0);
    $result = $id ? wp_update_term($id, $taxonomy, array_merge(array('name' => $name), $args)) : wp_insert_term($name, $taxonomy, $args);
    if (is_wp_error($result)) return $result;
    $term_id = $id ?: $result['term_id'];
    return rest_ensure_response(nexopc_term_item(get_term($term_id, $taxonomy)));
}

function nexopc_taxonomy_delete(WP_REST_Request $request, $taxonomy) {
    $result = wp_delete_term(absint($request['id']), $taxonomy);
    return $result ? new WP_REST_Response(null, 204) : nexopc_error('nexopc_term_delete_failed', 'No se pudo eliminar el elemento.', 422);
}

function nexopc_list_attributes() {
    $items = array();
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        $items[] = array('id' => (int) $attribute->attribute_id, 'name' => $attribute->attribute_label, 'slug' => $attribute->attribute_name, 'taxonomy' => $taxonomy, 'type' => $attribute->attribute_type, 'terms' => array_map('nexopc_term_item', is_wp_error($terms) ? array() : $terms));
    }
    return rest_ensure_response(array('items' => $items));
}

function nexopc_save_attribute(WP_REST_Request $request, $id = 0) {
    $data = nexopc_request_data($request);
    $name = sanitize_text_field($data['name'] ?? '');
    if (!$name) return nexopc_error('nexopc_attribute_name_required', 'El nombre del atributo es obligatorio.', 422);
    $args = array('name' => $name, 'slug' => sanitize_title($data['slug'] ?? $name), 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false);
    $result = $id ? wc_update_attribute($id, $args) : wc_create_attribute($args);
    if (is_wp_error($result)) return $result;
    delete_transient('wc_attribute_taxonomies');
    return rest_ensure_response(array('id' => (int) ($id ?: $result)));
}

function nexopc_delete_attribute(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (!wc_get_attribute($id)) return nexopc_error('nexopc_attribute_not_found', 'Atributo no encontrado.', 404);
    $result = wc_delete_attribute($id);
    if (!$result) return nexopc_error('nexopc_attribute_delete_failed', 'No se pudo eliminar el atributo.', 422);
    delete_transient('wc_attribute_taxonomies');
    return new WP_REST_Response(null, 204);
}

function nexopc_attribute_term_save(WP_REST_Request $request) {
    $taxonomy = nexopc_attribute_taxonomy($request['id']);
    if (!$taxonomy || !taxonomy_exists($taxonomy)) return nexopc_error('nexopc_attribute_not_found', 'Atributo no encontrado.', 404);
    return nexopc_taxonomy_save($request, $taxonomy, absint($request->get_param('termId')));
}

function nexopc_upload_media(WP_REST_Request $request) {
    $files = $request->get_file_params();
    if (empty($files['file'])) return nexopc_error('nexopc_media_required', 'Selecciona un archivo.', 422);
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_id = media_handle_upload('file', 0);
    if (is_wp_error($attachment_id)) return $attachment_id;
    return new WP_REST_Response(nexopc_media_item($attachment_id), 201);
}

function nexopc_pagination_args(WP_REST_Request $request) {
    return array('page' => max(1, absint($request->get_param('page') ?: 1)), 'per_page' => min(100, max(1, absint($request->get_param('perPage') ?: 20))));
}

function nexopc_inventory_list(WP_REST_Request $request) {
    $paging = nexopc_pagination_args($request);
    $args = array('limit' => $paging['per_page'], 'page' => $paging['page'], 'paginate' => true, 'status' => $request->get_param('includeDrafts') ? array('publish', 'draft', 'private') : array('publish', 'private'));
    if ($request->get_param('search')) $args['s'] = sanitize_text_field($request->get_param('search'));
    if ($request->get_param('stockStatus')) $args['stock_status'] = sanitize_text_field($request->get_param('stockStatus'));
    $query = wc_get_products($args);
    $items = array();
    foreach ($query->products as $product) {
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) { $variation = wc_get_product($variation_id); if ($variation) $items[] = array('id' => $variation->get_id(), 'parentId' => $product->get_id(), 'name' => $product->get_name() . ' · ' . implode(' / ', $variation->get_variation_attributes()), 'sku' => $variation->get_sku(), 'stockQuantity' => $variation->get_stock_quantity(), 'manageStock' => $variation->get_manage_stock(), 'stockStatus' => $variation->get_stock_status(), 'lowStockAmount' => $variation->get_low_stock_amount(), 'image' => nexopc_media_item($variation->get_image_id() ?: $product->get_image_id())); }
        } else $items[] = array('id' => $product->get_id(), 'parentId' => null, 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'stockQuantity' => $product->get_stock_quantity(), 'manageStock' => $product->get_manage_stock(), 'stockStatus' => $product->get_stock_status(), 'lowStockAmount' => $product->get_low_stock_amount(), 'image' => nexopc_media_item($product->get_image_id()));
    }
    return rest_ensure_response(array('items' => $items, 'page' => $paging['page'], 'perPage' => $paging['per_page'], 'total' => (int) $query->total, 'totalPages' => (int) $query->max_num_pages));
}

function nexopc_inventory_adjust(WP_REST_Request $request) {
    $data = nexopc_request_data($request); $product = wc_get_product(absint($data['productId'] ?? 0));
    if (!$product) return nexopc_error('nexopc_stock_product_not_found', 'Producto no encontrado.', 404);
    $mode = ($data['mode'] ?? 'increase') === 'set' ? 'set' : (($data['mode'] ?? 'increase') === 'decrease' ? 'decrease' : 'increase');
    $quantity = absint($data['quantity'] ?? 0); if (!$quantity && $mode !== 'set') return nexopc_error('nexopc_stock_quantity_required', 'Indica una cantidad mayor a cero.', 422);
    $previous = $product->get_manage_stock() ? (int) $product->get_stock_quantity() : 0;
    $product->set_manage_stock(true); $product->save();
    $new_stock = $mode === 'set' ? wc_update_product_stock($product, $quantity, 'set') : wc_update_product_stock($product, $quantity, $mode);
    $product = wc_get_product($product->get_id());
    $product->set_stock_status($new_stock > 0 ? 'instock' : 'outofstock'); $product->save();
    $history = nexopc_stock_history_push($product->get_id(), array('type' => 'adjust', 'mode' => $mode, 'quantity' => $quantity, 'reason' => sanitize_text_field($data['reason'] ?? ''), 'previous' => $previous, 'result' => (int) $new_stock));
    return rest_ensure_response(array('id' => $product->get_id(), 'stockQuantity' => $product->get_stock_quantity(), 'stockStatus' => $product->get_stock_status(), 'history' => array_slice($history, 0, 20)));
}

/** Historial de movimientos (kardex) de un producto o variante: lista de arrays, el más reciente primero. */
function nexopc_stock_history($product_id) {
    $history = get_post_meta($product_id, '_nexopc_stock_history', true);
    return is_array($history) ? array_values(array_filter($history, 'is_array')) : array();
}

/** Añade un movimiento al kardex sin re-guardar el producto completo (no dispara hooks de producto). */
function nexopc_stock_history_push($product_id, array $entry) {
    $history = nexopc_stock_history($product_id);
    array_unshift($history, array_merge(array('date' => current_time(DATE_ATOM), 'user' => get_current_user_id()), $entry));
    $history = array_slice($history, 0, 200);
    update_post_meta($product_id, '_nexopc_stock_history', $history);
    return $history;
}

/** Datos de entrega para el panel: el departamento se muestra por nombre, no por código. */
function nexopc_order_shipping($order) {
    $country = $order->get_shipping_country() ?: 'PE';
    $states = (array) WC()->countries->get_states($country);
    $state = $order->get_shipping_state() ?: $order->get_billing_state();
    return array(
        'name' => trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name()),
        'phone' => $order->get_shipping_phone() ?: $order->get_billing_phone(),
        'address' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
        'district' => $order->get_shipping_city() ?: $order->get_billing_city(),
        'region' => $states[$state] ?? $state,
        'postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
        'note' => $order->get_customer_note(),
    );
}

/**
 * Estado de cobro derivado del pedido. WooCommerce no lo guarda aparte:
 * refunded > paid (tiene fecha de pago o está en un estado pagado) > failed > cancelled > pending.
 */
function nexopc_order_payment_state($order) {
    $status = $order->get_status();
    if ($status === 'refunded' || (float) $order->get_total_refunded() > 0) return 'refunded';
    if ($order->get_date_paid() || in_array($status, array('processing', 'completed'), true)) return 'paid';
    if ($status === 'failed') return 'failed';
    if ($status === 'cancelled') return 'cancelled';
    return 'pending';
}

/** Estados de pedido que corresponden a cada estado de cobro (para filtrar en la consulta). */
function nexopc_payment_state_statuses($state) {
    $map = array('paid' => array('processing', 'completed'), 'pending' => array('pending', 'on-hold'), 'failed' => array('failed'), 'cancelled' => array('cancelled'), 'refunded' => array('refunded'));
    return $map[$state] ?? null;
}

function nexopc_order_date($date) {
    return $date ? $date->date(DATE_ATOM) : null;
}

/** Unidades físicas (números de serie) asignadas a cada línea del pedido, indexadas por order_item_id. */
function nexopc_order_assigned_units($order) {
    global $wpdb;
    $ids = array_map('absint', array_keys($order->get_items()));
    if (!$ids) return array();
    $p = $wpdb->prefix . 'nexopc_';
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT a.order_item_id, u.id, u.serial_numero, u.estado FROM {$p}pedido_item_unidad a JOIN {$p}unidades_inventario u ON u.id = a.unidad_id WHERE a.order_item_id IN ($placeholders) ORDER BY a.id", $ids), ARRAY_A);
    $units = array();
    foreach ((array) $rows as $row) $units[(int) $row['order_item_id']][] = array('id' => (int) $row['id'], 'serial' => (string) $row['serial_numero'], 'status' => $row['estado']);
    return $units;
}

function nexopc_order_item($order, $detail = false) {
    $data = array(
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'paymentState' => nexopc_order_payment_state($order),
        'date' => nexopc_order_date($order->get_date_created()),
        'total' => $order->get_total(),
        'currency' => $order->get_currency(),
        'itemCount' => $order->get_item_count(),
        'paymentMethod' => $order->get_payment_method_title(),
        'demo' => (bool) $order->get_meta('_nexopc_demo'),
        'payment' => array(
            'method' => $order->get_payment_method(),
            'transactionId' => $order->get_transaction_id(),
            'paidAt' => nexopc_order_date($order->get_date_paid()),
            'mpOrderId' => (string) $order->get_meta('_nexopc_mp_order_id'),
            'mpStatus' => (string) $order->get_meta('_nexopc_mp_status'),
            'mpStatusDetail' => (string) $order->get_meta('_nexopc_mp_status_detail'),
            'mpPaymentMethod' => (string) $order->get_meta('_nexopc_mp_payment_method'),
        ),
        'customer' => array('id' => $order->get_customer_id(), 'name' => trim($order->get_formatted_billing_full_name()), 'email' => $order->get_billing_email()),
    );
    if (!$detail) return $data;

    $units = nexopc_order_assigned_units($order);
    $data['shipping'] = nexopc_order_shipping($order);
    $data['customer']['phone'] = $order->get_billing_phone();
    $data['customer']['dni'] = $order->get_customer_id() ? (string) get_user_meta($order->get_customer_id(), 'nexopc_dni', true) : '';
    $data['items'] = array_values(array_map(function ($item) use ($units) {
        $product = $item->get_product();
        $sellable = absint($item->get_variation_id() ?: $item->get_product_id());
        $policy = function_exists('nexopc_serial_policy_for_product') ? nexopc_serial_policy_for_product($sellable) : 'none';
        if ($policy === 'none' && $item->get_variation_id() && function_exists('nexopc_serial_policy_for_product')) $policy = nexopc_serial_policy_for_product($item->get_product_id());
        $quantity = (int) $item->get_quantity();
        return array(
            'itemId' => $item->get_id(),
            'productId' => $item->get_product_id(),
            'sellableId' => $sellable,
            'name' => $item->get_name(),
            'sku' => $product ? $product->get_sku() : '',
            'image' => $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
            'quantity' => $quantity,
            'unitPrice' => wc_format_decimal($quantity ? (float) $item->get_subtotal() / $quantity : 0, 2),
            'subtotal' => $item->get_subtotal(),
            'total' => $item->get_total(),
            'serialPolicy' => $policy,
            'units' => $units[$item->get_id()] ?? array(),
        );
    }, $order->get_items()));
    $data['totals'] = array('subtotal' => wc_format_decimal($order->get_subtotal(), 2), 'discount' => wc_format_decimal($order->get_total_discount(), 2), 'shipping' => wc_format_decimal($order->get_shipping_total(), 2), 'tax' => wc_format_decimal($order->get_total_tax(), 2), 'refunded' => wc_format_decimal($order->get_total_refunded(), 2), 'total' => $order->get_total());
    $data['dates'] = array('created' => nexopc_order_date($order->get_date_created()), 'paid' => nexopc_order_date($order->get_date_paid()), 'completed' => nexopc_order_date($order->get_date_completed()), 'modified' => nexopc_order_date($order->get_date_modified()));
    $data['createdVia'] = $order->get_created_via();
    $data['notes'] = array_map(function ($note) {
        return array('id' => (int) $note->id, 'date' => $note->date_created ? $note->date_created->date(DATE_ATOM) : null, 'content' => $note->content, 'customerNote' => (bool) $note->customer_note, 'author' => $note->added_by === 'system' ? 'Sistema' : $note->added_by);
    }, wc_get_order_notes(array('order_id' => $order->get_id())));
    return $data;
}

function nexopc_valid_date_param($value) {
    $value = sanitize_text_field((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function nexopc_orders_list(WP_REST_Request $request) {
    $paging = nexopc_pagination_args($request);
    $args = array('limit' => $paging['per_page'], 'page' => $paging['page'], 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order');
    $statuses = $request->get_param('status') ? array(sanitize_key($request->get_param('status'))) : null;
    if ($request->get_param('payment')) {
        $by_payment = nexopc_payment_state_statuses(sanitize_key($request->get_param('payment')));
        if ($by_payment === null) return nexopc_error('nexopc_invalid_payment_state', 'Estado de cobro no válido.', 422);
        $statuses = $statuses === null ? $by_payment : array_values(array_intersect($statuses, $by_payment));
        if (!$statuses) return rest_ensure_response(array('items' => array(), 'page' => $paging['page'], 'perPage' => $paging['per_page'], 'total' => 0, 'totalPages' => 1));
    }
    if ($statuses !== null) $args['status'] = $statuses;
    if ($request->get_param('search')) $args['s'] = sanitize_text_field($request->get_param('search'));
    if ($request->get_param('customerId')) $args['customer_id'] = absint($request->get_param('customerId'));
    $from = nexopc_valid_date_param($request->get_param('dateFrom'));
    $to = nexopc_valid_date_param($request->get_param('dateTo'));
    if ($from || $to) {
        $tz = wp_timezone();
        $start = $from ? (new DateTime($from . ' 00:00:00', $tz))->getTimestamp() : 0;
        $end = $to ? (new DateTime($to . ' 23:59:59', $tz))->getTimestamp() : time() + DAY_IN_SECONDS;
        $args['date_created'] = $start . '...' . $end;
    }
    $query = wc_get_orders($args);
    return rest_ensure_response(array('items' => array_map(function ($order) { return nexopc_order_item($order); }, $query->orders), 'page' => $paging['page'], 'perPage' => $paging['per_page'], 'total' => (int) $query->total, 'totalPages' => max(1, (int) $query->max_num_pages)));
}

function nexopc_order_get(WP_REST_Request $request) { $order = wc_get_order(absint($request['id'])); return $order ? rest_ensure_response(nexopc_order_item($order, true)) : nexopc_error('nexopc_order_not_found', 'Pedido no encontrado.', 404); }

function nexopc_order_update(WP_REST_Request $request) {
    $order = wc_get_order(absint($request['id']));
    if (!$order) return nexopc_error('nexopc_order_not_found', 'Pedido no encontrado.', 404);
    $data = nexopc_request_data($request);
    $note = sanitize_textarea_field($data['note'] ?? '');

    // Cobro registrado a mano (transferencia, contraentrega): fija la fecha de pago y pasa a "Procesando".
    if (($data['action'] ?? '') === 'markPaid') {
        if (!$order->needs_payment() && $order->get_date_paid()) return nexopc_error('nexopc_order_already_paid', 'El pedido ya figura como pagado.', 422);
        $reference = sanitize_text_field($data['reference'] ?? '');
        $order->add_order_note(trim('Cobro registrado manualmente desde el ERP.' . ($reference ? ' Referencia: ' . $reference . '.' : '') . ($note ? ' ' . $note : '')), 0, true);
        if ($order->needs_payment()) $order->payment_complete($reference);
        else { $order->set_date_paid(time()); if ($reference) $order->set_transaction_id($reference); $order->save(); if (!$order->has_status(array('processing', 'completed'))) $order->update_status('processing'); }
        return rest_ensure_response(nexopc_order_item(wc_get_order($order->get_id()), true));
    }

    $status = sanitize_key($data['status'] ?? '');
    if (!$status || !array_key_exists('wc-' . $status, wc_get_order_statuses())) return nexopc_error('nexopc_invalid_order_status', 'Estado de pedido no válido.', 422);
    if ($status === 'completed') { $serials = nexopc_validate_order_serials_before_dispatch($order); if (is_wp_error($serials)) return $serials; }
    $order->update_status($status, $note, !empty($data['notifyCustomer']));
    return rest_ensure_response(nexopc_order_item(wc_get_order($order->get_id()), true));
}

function nexopc_customer_summary($user) {
    $customer = new WC_Customer($user->ID);
    return array('id' => $user->ID, 'name' => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name, 'email' => $user->user_email, 'orders' => wc_get_customer_order_count($user->ID), 'spent' => wc_get_customer_total_spent($user->ID), 'registeredAt' => $user->user_registered, 'phone' => $customer->get_billing_phone(), 'dni' => (string) get_user_meta($user->ID, 'nexopc_dni', true), 'demo' => (bool) get_user_meta($user->ID, '_nexopc_demo', true));
}

function nexopc_customers_list(WP_REST_Request $request) {
    $paging = nexopc_pagination_args($request);
    $args = array('number' => $paging['per_page'], 'offset' => ($paging['page'] - 1) * $paging['per_page'], 'role__in' => array('customer', 'subscriber'), 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true);
    if ($request->get_param('search')) { $args['search'] = '*' . sanitize_text_field($request->get_param('search')) . '*'; $args['search_columns'] = array('user_login', 'user_email', 'display_name'); }
    $query = new WP_User_Query($args);
    $count = (int) $query->get_total();
    return rest_ensure_response(array('items' => array_map('nexopc_customer_summary', $query->get_results()), 'page' => $paging['page'], 'perPage' => $paging['per_page'], 'total' => $count, 'totalPages' => max(1, (int) ceil($count / $paging['per_page']))));
}

function nexopc_kit_stock_change($order, $direction) {
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if (!$product || $product->get_meta(NEXOPC_KIT_TYPE_META) !== 'kit') continue;
        $flag = $direction === 'decrease' ? '_nexopc_kit_stock_reduced' : '_nexopc_kit_stock_restored';
        if ($item->get_meta($flag)) continue;
        foreach ((array) $product->get_meta(NEXOPC_KIT_ITEMS_META) as $component_item) {
            $component = wc_get_product(absint($component_item['variationId'] ?: $component_item['productId']));
            if ($component && $component->managing_stock()) wc_update_product_stock($component, absint($component_item['quantity']) * $item->get_quantity(), $direction);
        }
        $item->update_meta_data($flag, 1);
        if ($direction === 'decrease') $item->delete_meta_data('_nexopc_kit_stock_restored');
        else $item->delete_meta_data('_nexopc_kit_stock_reduced');
        $item->save();
    }
}

add_action('woocommerce_reduce_order_stock', function ($order) { nexopc_kit_stock_change($order, 'decrease'); });
add_action('woocommerce_restore_order_stock', function ($order) { nexopc_kit_stock_change($order, 'increase'); });
add_action('woocommerce_update_product', function ($product_id) {
    $kits = get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => NEXOPC_KIT_ITEMS_META, 'compare' => 'EXISTS'))));
    foreach ($kits as $kit_id) nexopc_recalculate_kit($kit_id);
}, 20);

add_action('graphql_register_types', function () {
    if (!function_exists('register_graphql_object_type')) return;
    register_graphql_object_type('NexoPcHardware', array('fields' => array(
        'componentTypeSlug' => array('type' => 'String'), 'componentTypeName' => array('type' => 'String'),
        'socketId' => array('type' => 'Int'), 'memoryTypeId' => array('type' => 'Int'), 'formFactorId' => array('type' => 'Int'),
        'storageInterfaceId' => array('type' => 'Int'), 'storageInterfaceSlug' => array('type' => 'String'), 'supportedMemoryTypeIds' => array('type' => array('list_of' => 'Int')),
        'supportedStorageInterfaceIds' => array('type' => array('list_of' => 'Int')), 'supportedFormFactorIds' => array('type' => array('list_of' => 'Int')),
        'tdpWatts' => array('type' => 'Float'), 'capacityGb' => array('type' => 'Int'), 'gpuMemoryGb' => array('type' => 'Int'), 'gpuLengthMm' => array('type' => 'Int'),
        'gpuSlots' => array('type' => 'Float'), 'recommendedPsuWatts' => array('type' => 'Int'), 'maxGpuLengthMm' => array('type' => 'Int'),
        'maxCoolerHeightMm' => array('type' => 'Int'), 'bays25' => array('type' => 'Int'), 'bays35' => array('type' => 'Int'),
        'continuousWatts' => array('type' => 'Int'), 'm2Slots' => array('type' => 'Int'), 'sataPorts' => array('type' => 'Int'),
        'integratedGraphics' => array('type' => 'Boolean'), 'coolerIncluded' => array('type' => 'Boolean'), 'ramSlots' => array('type' => 'Int'), 'modules' => array('type' => 'Int'), 'speedMts' => array('type' => 'Int'),
        'maxRadiatorMm' => array('type' => 'Int'), 'coolerType' => array('type' => 'String'), 'coolerHeightMm' => array('type' => 'Int'), 'radiatorMm' => array('type' => 'Int'), 'maxTdpWatts' => array('type' => 'Int'),
        'supportedSocketIds' => array('type' => array('list_of' => 'Int')),
    )));
    register_graphql_object_type('NexoPcKit', array('fields' => array(
        'pricingMode' => array('type' => 'String'), 'discount' => array('type' => 'Float'), 'items' => array('type' => array('list_of' => 'String')),
    )));
    register_graphql_field('SimpleProduct', 'nexopcKit', array(
        'type' => 'NexoPcKit',
        'resolve' => function ($source) {
            $product = wc_get_product($source->ID);
            if (!$product || $product->get_meta(NEXOPC_KIT_TYPE_META) !== 'kit') return null;
            return array('pricingMode' => $product->get_meta(NEXOPC_KIT_PRICING_META), 'discount' => (float) $product->get_meta(NEXOPC_KIT_DISCOUNT_META), 'items' => array_map('wp_json_encode', (array) $product->get_meta(NEXOPC_KIT_ITEMS_META)));
        },
    ));
    $hardware_field = array(
        'type' => 'NexoPcHardware',
        'resolve' => function ($source) {
            $hardware = nexopc_hardware_product_item($source->ID);
            if (!$hardware) return null;
            return array_merge(array('componentTypeSlug' => $hardware['componentType']['slug'], 'componentTypeName' => $hardware['componentType']['name']), $hardware['specs']);
        },
    );
    register_graphql_field('SimpleProduct', 'nexopcHardware', $hardware_field);
    register_graphql_field('VariableProduct', 'nexopcHardware', $hardware_field);
});

add_action('rest_api_init', function () {
    $permission = 'nexopc_admin_permission';
    register_rest_route('nexopc/v1', '/catalog/products', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_list_products', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_save_product', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/catalog/products/(?P<id>\\d+)', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_get_product', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::EDITABLE, 'callback' => function ($request) { return nexopc_save_product($request, absint($request['id'])); }, 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::DELETABLE, 'callback' => 'nexopc_delete_product', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/catalog/products/(?P<id>\\d+)/restore', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_restore_product', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/catalog/products/bulk', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_bulk_products', 'permission_callback' => $permission));
    foreach (array('categories' => 'product_cat', 'tags' => 'product_tag') as $route => $taxonomy) {
        register_rest_route('nexopc/v1', '/catalog/' . $route, array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => function () use ($taxonomy) { return nexopc_taxonomy_list($taxonomy); }, 'permission_callback' => $permission),
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => function ($request) use ($taxonomy) { return nexopc_taxonomy_save($request, $taxonomy); }, 'permission_callback' => $permission),
        ));
        register_rest_route('nexopc/v1', '/catalog/' . $route . '/(?P<id>\\d+)', array(
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => function ($request) use ($taxonomy) { return nexopc_taxonomy_save($request, $taxonomy, absint($request['id'])); }, 'permission_callback' => $permission),
            array('methods' => WP_REST_Server::DELETABLE, 'callback' => function ($request) use ($taxonomy) { return nexopc_taxonomy_delete($request, $taxonomy); }, 'permission_callback' => $permission),
        ));
    }
    register_rest_route('nexopc/v1', '/catalog/attributes', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_list_attributes', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_save_attribute', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/catalog/attributes/(?P<id>\\d+)', array(
        array('methods' => WP_REST_Server::EDITABLE, 'callback' => function ($request) { return nexopc_save_attribute($request, absint($request['id'])); }, 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::DELETABLE, 'callback' => 'nexopc_delete_attribute', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/catalog/attributes/(?P<id>\\d+)/terms', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_attribute_term_save', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/catalog/media', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_upload_media', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/hardware/catalogos', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_hardware_catalogs', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/catalog/marcas', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_brands_list', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_brand_save', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/inventory/unidades', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_inventory_units_list', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_inventory_unit_save', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/orders/(?P<id>\d+)/unidades', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_assign_inventory_unit', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/settings/units', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_store_units', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/inventory', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_inventory_list', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/inventory/adjust', array('methods' => WP_REST_Server::CREATABLE, 'callback' => 'nexopc_inventory_adjust', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/orders', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_orders_list', 'permission_callback' => $permission));
    register_rest_route('nexopc/v1', '/orders/(?P<id>\\d+)', array(
        array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_order_get', 'permission_callback' => $permission),
        array('methods' => WP_REST_Server::EDITABLE, 'callback' => 'nexopc_order_update', 'permission_callback' => $permission),
    ));
    register_rest_route('nexopc/v1', '/customers', array('methods' => WP_REST_Server::READABLE, 'callback' => 'nexopc_customers_list', 'permission_callback' => $permission));
});
