<?php
/**
 * Importa el catálogo curado de NexoPC dentro del contenedor de WordPress.
 *
 * Uso (como www-data):
 *   php import-catalog.php <carpeta-data> [--apply] [--with-images] [--prices=<archivo>] [--retire-old] [--restore-old]
 *
 * Sin --apply solo informa lo que haría. Es idempotente: localiza cada producto
 * por SKU. Los productos no verificados quedan en borrador. Precio y stock solo
 * cambian con --prices (precio regular desde el archivo) o al crear el producto:
 * actualizar la ficha técnica no borra lo cargado desde el panel. --retire-old pasa a
 * borrador todo lo que no pertenece al catálogo curado, sin borrar nada, y
 * --restore-old lo devuelve a publicado.
 */
if (php_sapi_name() !== 'cli') exit(1);

if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require (getenv('WP_LOAD_PATH') ?: '/var/www/html/wp-load.php');
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const NEXOPC_IMPORT_KEY_META = '_nexopc_catalog_key';
const NEXOPC_IMPORT_RETIRED_META = '_nexopc_retired_at';
const NEXOPC_IMPORT_CATEGORIES = array('cpu' => 'procesadores', 'motherboard' => 'placas-madre', 'ram' => 'memoria-ram', 'gpu' => 'tarjetas-graficas', 'storage' => 'almacenamiento', 'psu' => 'fuentes-de-poder', 'case' => 'gabinetes', 'cooler' => 'refrigeracion');
const NEXOPC_IMPORT_TYPES = array('cpu' => 'procesador', 'motherboard' => 'placa_madre', 'ram' => 'memoria_ram', 'gpu' => 'tarjeta_grafica', 'storage' => 'almacenamiento', 'psu' => 'fuente_poder', 'case' => 'gabinete', 'cooler' => 'refrigeracion');
const NEXOPC_IMPORT_LABELS = array('am4' => 'AM4', 'am5' => 'AM5', 'lga1700' => 'LGA 1700', 'lga1851' => 'LGA 1851', 'ddr4' => 'DDR4', 'ddr5' => 'DDR5', 'gddr6' => 'GDDR6', 'gddr6x' => 'GDDR6X', 'gddr7' => 'GDDR7', 'mini_itx' => 'Mini-ITX', 'micro_atx' => 'Micro-ATX', 'atx' => 'ATX', 'e_atx' => 'E-ATX', 'm2_nvme' => 'M.2 NVMe', 'm2_sata' => 'M.2 SATA', 'sata_2_5' => 'SATA 2.5"', 'sata_3_5' => 'SATA 3.5"', 'aire' => 'Aire', 'liquido' => 'Líquida');

$arguments = array_slice($argv, 1);
$data_dir = $arguments[0] ?? '';
$apply = in_array('--apply', $arguments, true);
$with_images = in_array('--with-images', $arguments, true);
$images_dir = dirname(rtrim($data_dir, '/')) . '/images';
$prices = array();
foreach ($arguments as $argument) {
    if (strpos($argument, '--prices=') !== 0) continue;
    $prices_file = substr($argument, 9);
    if (!is_file($prices_file)) { fwrite(STDOUT, "No existe el archivo de precios $prices_file" . PHP_EOL); exit(1); }
    $prices = json_decode(file_get_contents($prices_file), true, 32, JSON_THROW_ON_ERROR)['products'] ?? array();
}

function nexopc_import_log($message) { fwrite(STDOUT, $message . PHP_EOL); }
function nexopc_import_id($table, $slug) { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . nexopc_hardware_table($table) . ' WHERE slug = %s', $slug)); }
function nexopc_import_ids($table, $slugs) { return array_values(array_filter(array_map(function ($slug) use ($table) { return nexopc_import_id($table, $slug); }, (array) $slugs))); }
function nexopc_import_label($value) { return NEXOPC_IMPORT_LABELS[$value] ?? $value; }
function nexopc_import_labels($values) { return implode(', ', array_map('nexopc_import_label', (array) $values)); }
function nexopc_import_yes_no($value) { return $value === null ? null : ($value ? 'Sí' : 'No'); }
function nexopc_import_unit($value, $unit) { return $value === null ? null : $value . ' ' . $unit; }

function nexopc_import_hardware($category, $specs) {
    $spec = function ($key) use ($specs) { return $specs[$key] ?? null; };
    $values = array();
    if ($category === 'cpu') $values = array('socketId' => nexopc_import_id('sockets', $spec('socket')), 'tdpWatts' => $spec('tdp_w'), 'integratedGraphics' => $spec('igpu'), 'coolerIncluded' => $spec('cooler_included'), 'supportedMemoryTypeIds' => nexopc_import_ids('tipos_memoria', $spec('memory_types')));
    if ($category === 'motherboard') {
        $interfaces = array_merge($spec('m2_slots') ? array('m2_nvme') : array(), $spec('sata_ports') ? array('sata_2_5', 'sata_3_5') : array());
        $values = array('socketId' => nexopc_import_id('sockets', $spec('socket')), 'formFactorId' => nexopc_import_id('formatos', $spec('form')), 'm2Slots' => $spec('m2_slots'), 'sataPorts' => $spec('sata_ports'), 'ramSlots' => $spec('ram_slots'), 'supportedMemoryTypeIds' => nexopc_import_ids('tipos_memoria', $spec('memory_types')), 'supportedStorageInterfaceIds' => nexopc_import_ids('interfaces_almacenamiento', $interfaces));
    }
    if ($category === 'ram') $values = array('memoryTypeId' => nexopc_import_id('tipos_memoria', $spec('memory_type')), 'capacityGb' => $spec('capacity_gb'), 'modules' => $spec('modules'), 'speedMts' => $spec('speed_mts'));
    if ($category === 'gpu') $values = array('memoryTypeId' => nexopc_import_id('tipos_memoria', $spec('memory_type')), 'gpuMemoryGb' => $spec('memory_gb'), 'gpuLengthMm' => $spec('length_mm'), 'gpuSlots' => $spec('slots'), 'tdpWatts' => $spec('tdp_w'), 'recommendedPsuWatts' => $spec('psu_w'));
    if ($category === 'storage') $values = array('storageInterfaceId' => nexopc_import_id('interfaces_almacenamiento', $spec('interface')), 'capacityGb' => $spec('capacity_gb'));
    if ($category === 'psu') $values = array('continuousWatts' => $spec('watts'));
    if ($category === 'case') $values = array('maxGpuLengthMm' => $spec('max_gpu_mm'), 'maxCoolerHeightMm' => $spec('max_cooler_mm'), 'maxRadiatorMm' => $spec('max_radiator_mm'), 'bays25' => $spec('bays_2_5'), 'bays35' => $spec('bays_3_5'), 'supportedFormFactorIds' => nexopc_import_ids('formatos', $spec('formats')));
    if ($category === 'cooler') $values = array('coolerType' => $spec('type'), 'coolerHeightMm' => $spec('height_mm'), 'radiatorMm' => $spec('radiator_mm'), 'maxTdpWatts' => $spec('tdp_max_w'), 'supportedSocketIds' => nexopc_import_ids('sockets', $spec('sockets')));
    return array('componentTypeId' => nexopc_import_id('tipos_componente', NEXOPC_IMPORT_TYPES[$category]), 'specs' => $values);
}

function nexopc_import_spec_rows($product) {
    $spec = function ($key) use ($product) { return $product['specs'][$key] ?? null; };
    $rows = array('cpu' => array('Socket' => nexopc_import_label($spec('socket')), 'Núcleos / hilos' => $spec('cores') !== null ? $spec('cores') . ' / ' . $spec('threads') : null, 'TDP' => nexopc_import_unit($spec('tdp_w'), 'W'), 'Memoria compatible' => nexopc_import_labels($spec('memory_types')), 'Gráficos integrados' => nexopc_import_yes_no($spec('igpu')), 'Incluye disipador' => nexopc_import_yes_no($spec('cooler_included'))),
        'motherboard' => array('Socket' => nexopc_import_label($spec('socket')), 'Chipset' => $spec('chipset'), 'Formato' => nexopc_import_label($spec('form')), 'Memoria compatible' => nexopc_import_labels($spec('memory_types')), 'Ranuras RAM' => $spec('ram_slots'), 'Ranuras M.2' => $spec('m2_slots'), 'Puertos SATA' => $spec('sata_ports')),
        'ram' => array('Tipo' => nexopc_import_label($spec('memory_type')), 'Capacidad total' => nexopc_import_unit($spec('capacity_gb'), 'GB'), 'Módulos' => $spec('modules'), 'Velocidad' => nexopc_import_unit($spec('speed_mts'), 'MT/s')),
        'gpu' => array('Chip' => $spec('chip'), 'Memoria' => $spec('memory_gb') !== null ? $spec('memory_gb') . ' GB ' . nexopc_import_label($spec('memory_type')) : null, 'Longitud' => nexopc_import_unit($spec('length_mm'), 'mm'), 'Grosor' => nexopc_import_unit($spec('slots'), 'ranuras'), 'Consumo' => nexopc_import_unit($spec('tdp_w'), 'W'), 'Fuente recomendada' => nexopc_import_unit($spec('psu_w'), 'W')),
        'storage' => array('Interfaz' => nexopc_import_label($spec('interface')), 'Capacidad' => nexopc_import_unit($spec('capacity_gb'), 'GB'), 'PCIe' => $spec('pcie_gen') ? 'Gen ' . $spec('pcie_gen') : null, 'Formato' => $spec('form')),
        'psu' => array('Potencia' => nexopc_import_unit($spec('watts'), 'W'), 'Certificación' => $spec('certification'), 'Modular' => $spec('modular')),
        'case' => array('Formatos de placa' => nexopc_import_labels($spec('formats')), 'GPU máxima' => nexopc_import_unit($spec('max_gpu_mm'), 'mm'), 'Disipador máximo' => nexopc_import_unit($spec('max_cooler_mm'), 'mm'), 'Radiador máximo' => $spec('max_radiator_mm') ? $spec('max_radiator_mm') . ' mm' : null, 'Bahías 2.5"' => $spec('bays_2_5'), 'Bahías 3.5"' => $spec('bays_3_5')),
        'cooler' => array('Tipo' => nexopc_import_label($spec('type')), 'Sockets' => nexopc_import_labels($spec('sockets')), 'Altura' => nexopc_import_unit($spec('height_mm'), 'mm'), 'Radiador' => nexopc_import_unit($spec('radiator_mm'), 'mm'), 'TDP declarado' => nexopc_import_unit($spec('tdp_max_w'), 'W')));
    return array_filter(array_merge($rows[$product['category']], array('Fabricante' => $product['brand'], 'Código de fabricante' => $product['mpn'] ?? null)), function ($value) { return $value !== null && $value !== ''; });
}

function nexopc_import_description($product) {
    $items = '';
    foreach (nexopc_import_spec_rows($product) as $label => $value) $items .= '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $value) . '</li>';
    return '<p>' . esc_html($product['summary_es']) . '</p><h4>Especificaciones técnicas</h4><ul>' . $items . '</ul>';
}

function nexopc_import_short_description($product) {
    $uses_icecat = ($product['image_source'] ?? '') === 'icecat' || count(preg_grep('/icecat/i', (array) $product['sources'])) > 0;
    return '<p>' . esc_html($product['summary_es']) . '</p>' . ($uses_icecat ? '<p>Datos: <a href="https://icecat.biz">Specs Icecat</a>.</p>' : '');
}

function nexopc_import_brand($name) {
    global $wpdb;
    $table = $wpdb->prefix . 'nexopc_marcas';
    $slug = sanitize_title($name);
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s", $slug));
    if ($id) return $id;
    $wpdb->insert($table, array('nombre' => $name, 'slug' => $slug));
    return (int) $wpdb->insert_id;
}

function nexopc_import_image($product_id, $product) {
    global $images_dir;
    // Una imagen guardada en images/<key>.<ext> tiene prioridad: hay fabricantes
    // cuyo CDN no entrega archivos a descargas automáticas.
    $local = current(array_filter(array_map(function ($extension) use ($images_dir, $product) { return "$images_dir/{$product['key']}.$extension"; }, array('jpg', 'png', 'webp')), 'is_file')) ?: '';
    $url = $product['image_url'] ?? '';
    if (!$url && $local) $url = 'local:' . $product['key'];
    if (!$url || get_post_meta($product_id, '_nexopc_image_url', true) === $url) return null;
    if ($local) {
        $temporary = wp_tempnam($local);
        if (!copy($local, $temporary)) return new WP_Error('nexopc_import_image', 'No se pudo copiar ' . $local);
    } else {
        $temporary = download_url($url, 30);
    }
    if (is_wp_error($temporary)) return $temporary;
    $extension = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp')[wp_get_image_mime($temporary)] ?? '';
    if (!$extension) { @unlink($temporary); return new WP_Error('nexopc_import_image', 'El archivo no es una imagen admitida.'); }
    $attachment = media_handle_sideload(array('name' => sanitize_title($product['key']) . '.' . $extension, 'tmp_name' => $temporary), $product_id, $product['name']);
    if (is_wp_error($attachment)) { @unlink($temporary); return $attachment; }
    update_post_meta($attachment, '_wp_attachment_image_alt', $product['name']);
    update_post_meta($product_id, '_nexopc_image_url', $url);
    return $attachment;
}

function nexopc_import_product($item, $apply, $with_images, $price = null) {
    $sku = strtoupper('nx-' . $item['key']);
    $existing = wc_get_product_id_by_sku($sku);
    $status = !empty($item['verified']) ? 'publish' : 'draft';
    $amount = is_array($price) && is_numeric($price['pen'] ?? null) && $price['pen'] > 0 ? wc_format_decimal($price['pen'], 2) : null;
    nexopc_import_log(sprintf('%s %s [%s]%s', $existing ? 'actualizar' : 'crear', $sku, $status, $amount ? " S/ $amount" : ''));
    if (!$apply) return true;

    $term = get_term_by('slug', NEXOPC_IMPORT_CATEGORIES[$item['category']], 'product_cat');
    if (!$term) return new WP_Error('nexopc_import_category', 'Falta la categoría ' . NEXOPC_IMPORT_CATEGORIES[$item['category']]);

    $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
    $product->set_name($item['name']);
    $product->set_sku($sku);
    $product->set_status($status);
    $product->set_catalog_visibility('visible');
    $product->set_description(nexopc_import_description($item));
    $product->set_short_description(nexopc_import_short_description($item));
    $product->set_category_ids(array($term->term_id));
    if ($amount) {
        $product->set_regular_price($amount);
        $product->set_sale_price('');
        $product->update_meta_data('_nexopc_price_source', $price['source'] ?? '');
        $product->update_meta_data('_nexopc_price_checked_at', $price['checked_at'] ?? '');
    }
    if (!$existing) {
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
    }
    $product->update_meta_data(NEXOPC_IMPORT_KEY_META, $item['key']);
    $product->update_meta_data('_nexopc_mpn', $item['mpn'] ?? '');
    $product->update_meta_data('_nexopc_sources', (array) $item['sources']);
    $product->update_meta_data('_nexopc_verified', !empty($item['verified']) ? 'yes' : 'no');
    $product_id = $product->save();

    $hardware = nexopc_hardware_save_product($product_id, nexopc_import_hardware($item['category'], $item['specs']));
    if (is_wp_error($hardware)) {
        if (!empty($item['verified'])) return $hardware;
        nexopc_import_log('  ficha pendiente: ' . implode(', ', array_keys((array) $hardware->get_error_data()['fields'])));
    }
    $brand =nexopc_set_product_brand($product_id, nexopc_import_brand($item['brand']));
    if (is_wp_error($brand)) return $brand;

    if ($with_images) {
        $attachment = nexopc_import_image($product_id, $item);
        if (is_wp_error($attachment)) nexopc_import_log('  imagen no importada: ' . $attachment->get_error_message());
        elseif ($attachment) { $product = wc_get_product($product_id); $product->set_image_id($attachment); $product->save(); }
    }
    return true;
}

function nexopc_import_set_old_products($retire, $apply) {
    $query = array('limit' => -1, 'return' => 'ids', 'status' => $retire ? 'publish' : 'draft');
    $count = 0;
    foreach (wc_get_products($query) as $id) {
        $product = wc_get_product($id);
        $is_curated = (bool) $product->get_meta(NEXOPC_IMPORT_KEY_META);
        $was_retired = (bool) $product->get_meta(NEXOPC_IMPORT_RETIRED_META);
        if ($is_curated || (!$retire && !$was_retired)) continue;
        $count++;
        if (!$apply) continue;
        $product->set_status($retire ? 'draft' : 'publish');
        if ($retire) $product->update_meta_data(NEXOPC_IMPORT_RETIRED_META, gmdate('c'));
        else $product->delete_meta_data(NEXOPC_IMPORT_RETIRED_META);
        $product->save();
    }
    nexopc_import_log(sprintf('%s: %d productos', $retire ? 'retirar' : 'restaurar', $count));
}

$failures = 0;
if ($data_dir !== '' && $data_dir[0] !== '-') {
    if (!is_dir($data_dir)) { nexopc_import_log("No existe la carpeta $data_dir"); exit(1); }
    foreach (glob(rtrim($data_dir, '/') . '/*.json') as $file) {
        foreach (json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) as $item) {
            $result = nexopc_import_product($item, $apply, $with_images, $prices[$item['key']] ?? null);
            if (is_wp_error($result)) { $failures++; nexopc_import_log('  error en ' . $item['key'] . ': ' . $result->get_error_message()); }
        }
    }
}
if (in_array('--retire-old', $arguments, true)) {
    if ($failures) { nexopc_import_log('Hubo errores de importación: no se retira el catálogo anterior.'); exit(1); }
    nexopc_import_set_old_products(true, $apply);
}
if (in_array('--restore-old', $arguments, true)) nexopc_import_set_old_products(false, $apply);

nexopc_import_log(($apply ? 'Aplicado' : 'Simulación, sin cambios') . ". Errores: $failures");
exit($failures ? 1 : 0);
