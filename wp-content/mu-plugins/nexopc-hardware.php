<?php
/**
 * Modelo técnico NexoPC.
 *
 * WooCommerce conserva comercio e inventario. Estas tablas solo guardan datos
 * técnicos normalizados y relaciones de compatibilidad explícitas.
 */
if (!defined('ABSPATH')) exit;

const NEXOPC_HARDWARE_SCHEMA_VERSION = '1.0.1';

function nexopc_hardware_table($name) { global $wpdb; return $wpdb->prefix . 'nexopc_' . $name; }

function nexopc_hardware_install_schema() {
    global $wpdb;
    if (get_option('nexopc_hardware_schema_version') === NEXOPC_HARDWARE_SCHEMA_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $types = nexopc_hardware_table('tipos_componente');
    $sockets = nexopc_hardware_table('sockets');
    $memory = nexopc_hardware_table('tipos_memoria');
    $formats = nexopc_hardware_table('formatos');
    $interfaces = nexopc_hardware_table('interfaces_almacenamiento');
    $connectors = nexopc_hardware_table('conectores_energia');
    $products = nexopc_hardware_table('producto_componente');
    $cpu = nexopc_hardware_table('cpu_especificaciones');
    $board = nexopc_hardware_table('placa_especificaciones');
    $board_memory = nexopc_hardware_table('placa_memoria_compatible');
    $ram = nexopc_hardware_table('ram_especificaciones');
    $gpu = nexopc_hardware_table('gpu_especificaciones');
    $storage = nexopc_hardware_table('almacenamiento_especificaciones');
    $case = nexopc_hardware_table('gabinete_especificaciones');
    $case_formats = nexopc_hardware_table('gabinete_formato_compatible');
    $psu = nexopc_hardware_table('fuente_especificaciones');
    $ports = nexopc_hardware_table('producto_conector_energia');
    dbDelta("CREATE TABLE $types (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $sockets (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $memory (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, familia varchar(24) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $formats (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $interfaces (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $connectors (id bigint unsigned NOT NULL AUTO_INCREMENT, slug varchar(64) NOT NULL, nombre varchar(120) NOT NULL, activo tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY slug (slug)) $charset;");
    dbDelta("CREATE TABLE $products (product_id bigint unsigned NOT NULL, tipo_componente_id bigint unsigned NOT NULL, PRIMARY KEY (product_id), KEY tipo_componente_id (tipo_componente_id)) $charset;");
    dbDelta("CREATE TABLE $cpu (product_id bigint unsigned NOT NULL, socket_id bigint unsigned NOT NULL, tdp_w decimal(8,2) NULL, PRIMARY KEY (product_id), KEY socket_id (socket_id)) $charset;");
    dbDelta("CREATE TABLE $board (product_id bigint unsigned NOT NULL, socket_id bigint unsigned NOT NULL, formato_id bigint unsigned NULL, ranuras_m2 smallint unsigned NULL, puertos_sata smallint unsigned NULL, PRIMARY KEY (product_id), KEY socket_id (socket_id), KEY formato_id (formato_id)) $charset;");
    dbDelta("CREATE TABLE $board_memory (placa_product_id bigint unsigned NOT NULL, tipo_memoria_id bigint unsigned NOT NULL, PRIMARY KEY (placa_product_id,tipo_memoria_id)) $charset;");
    dbDelta("CREATE TABLE $ram (product_id bigint unsigned NOT NULL, tipo_memoria_id bigint unsigned NOT NULL, capacidad_gb smallint unsigned NULL, PRIMARY KEY (product_id), KEY tipo_memoria_id (tipo_memoria_id)) $charset;");
    dbDelta("CREATE TABLE $gpu (product_id bigint unsigned NOT NULL, tipo_memoria_id bigint unsigned NULL, memoria_gb smallint unsigned NULL, longitud_mm smallint unsigned NULL, grosor_ranuras decimal(4,1) NULL, tdp_w decimal(8,2) NULL, fuente_recomendada_w smallint unsigned NULL, PRIMARY KEY (product_id), KEY tipo_memoria_id (tipo_memoria_id)) $charset;");
    dbDelta("CREATE TABLE $storage (product_id bigint unsigned NOT NULL, interfaz_id bigint unsigned NOT NULL, capacidad_gb int unsigned NULL, PRIMARY KEY (product_id), KEY interfaz_id (interfaz_id)) $charset;");
    dbDelta("CREATE TABLE $case (product_id bigint unsigned NOT NULL, longitud_gpu_max_mm smallint unsigned NULL, altura_cooler_max_mm smallint unsigned NULL, bahias_2_5 smallint unsigned NULL, bahias_3_5 smallint unsigned NULL, PRIMARY KEY (product_id)) $charset;");
    dbDelta("CREATE TABLE $case_formats (gabinete_product_id bigint unsigned NOT NULL, formato_id bigint unsigned NOT NULL, PRIMARY KEY (gabinete_product_id,formato_id)) $charset;");
    dbDelta("CREATE TABLE $psu (product_id bigint unsigned NOT NULL, potencia_continua_w smallint unsigned NOT NULL, PRIMARY KEY (product_id)) $charset;");
    dbDelta("CREATE TABLE $ports (product_id bigint unsigned NOT NULL, conector_id bigint unsigned NOT NULL, cantidad smallint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (product_id,conector_id)) $charset;");
    nexopc_hardware_seed_catalogs();
    update_option('nexopc_hardware_schema_version', NEXOPC_HARDWARE_SCHEMA_VERSION, false);
}
add_action('plugins_loaded', 'nexopc_hardware_install_schema', 25);

function nexopc_hardware_install_storage_support_schema() {
    global $wpdb;
    $table = nexopc_hardware_table('placa_interfaz_almacenamiento');
    $charset = $wpdb->get_charset_collate();
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table (placa_product_id bigint unsigned NOT NULL, interfaz_id bigint unsigned NOT NULL, PRIMARY KEY (placa_product_id,interfaz_id), KEY interfaz_id (interfaz_id)) $charset");
}
add_action('plugins_loaded', 'nexopc_hardware_install_storage_support_schema', 26);

function nexopc_hardware_install_gpu_memory_capacity() {
    global $wpdb;
    $table = nexopc_hardware_table('gpu_especificaciones');
    $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'memoria_gb'));
    if (!$column) $wpdb->query("ALTER TABLE $table ADD COLUMN memoria_gb smallint unsigned NULL AFTER tipo_memoria_id");
}
add_action('plugins_loaded', 'nexopc_hardware_install_gpu_memory_capacity', 28);

function nexopc_hardware_seed($table, $rows) { global $wpdb; foreach ($rows as $row) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (slug,nombre" . (isset($row[2]) ? ',familia' : '') . ") VALUES (%s,%s" . (isset($row[2]) ? ',%s' : '') . ')', ...$row)); }
function nexopc_hardware_seed_catalogs() {
    nexopc_hardware_seed(nexopc_hardware_table('tipos_componente'), array(array('procesador','Procesador'),array('placa_madre','Placa madre'),array('memoria_ram','Memoria RAM'),array('tarjeta_grafica','Tarjeta gráfica'),array('almacenamiento','Almacenamiento'),array('gabinete','Gabinete'),array('fuente_poder','Fuente de poder'),array('refrigeracion','Refrigeración'),array('monitor','Monitor'),array('periferico','Periférico')));
    nexopc_hardware_seed(nexopc_hardware_table('sockets'), array(array('am4','AM4'),array('am5','AM5'),array('lga1700','LGA 1700'),array('lga1851','LGA 1851')));
    nexopc_hardware_seed(nexopc_hardware_table('tipos_memoria'), array(array('ddr4','DDR4','sistema'),array('ddr5','DDR5','sistema'),array('gddr6','GDDR6','grafica'),array('gddr6x','GDDR6X','grafica'),array('gddr7','GDDR7','grafica')));
    nexopc_hardware_seed(nexopc_hardware_table('formatos'), array(array('mini_itx','Mini-ITX'),array('micro_atx','Micro-ATX'),array('atx','ATX'),array('e_atx','E-ATX')));
    nexopc_hardware_seed(nexopc_hardware_table('interfaces_almacenamiento'), array(array('m2_nvme','M.2 NVMe'),array('m2_sata','M.2 SATA'),array('sata_2_5','SATA 2.5 pulgadas'),array('sata_3_5','SATA 3.5 pulgadas')));
    nexopc_hardware_seed(nexopc_hardware_table('conectores_energia'), array(array('pcie_6_2','PCIe 6+2 pines'),array('pcie_12vhpwr','12VHPWR / 12V-2x6'),array('cpu_8','CPU EPS 8 pines')));
}
function nexopc_hardware_seed_product_categories() {
    if (taxonomy_exists('product_cat') && !term_exists('perifericos', 'product_cat')) wp_insert_term('Periféricos', 'product_cat', array('slug' => 'perifericos', 'description' => 'Teclados, mouse, audífonos, webcams y otros accesorios para PC.'));
}
add_action('init', 'nexopc_hardware_seed_product_categories', 30);

function nexopc_hardware_rows($name) { global $wpdb; $table = nexopc_hardware_table($name); return $wpdb->get_results("SELECT id, slug, nombre" . ($name === 'tipos_memoria' ? ', familia' : '') . " FROM $table WHERE activo = 1 ORDER BY nombre", ARRAY_A); }
function nexopc_hardware_catalogs() { return rest_ensure_response(array('tiposComponente'=>nexopc_hardware_rows('tipos_componente'),'sockets'=>nexopc_hardware_rows('sockets'),'tiposMemoria'=>nexopc_hardware_rows('tipos_memoria'),'formatos'=>nexopc_hardware_rows('formatos'),'interfacesAlmacenamiento'=>nexopc_hardware_rows('interfaces_almacenamiento'),'conectoresEnergia'=>nexopc_hardware_rows('conectores_energia'))); }
function nexopc_hardware_id($table, $id) { global $wpdb; $id=absint($id); return $id && $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . nexopc_hardware_table($table) . ' WHERE id = %d AND activo = 1',$id)) ? $id : 0; }
function nexopc_hardware_number($data,$key,$label,$required=false) { $value=$data[$key] ?? null; if ($value === '' || $value === null) return $required ? nexopc_error('nexopc_hardware_validation','Revisa la ficha técnica.',422,array('hardware.specs.'.$key=>$label.' es obligatorio.')) : null; if (!is_numeric($value) || (float)$value < 0) return nexopc_error('nexopc_hardware_validation','Revisa la ficha técnica.',422,array('hardware.specs.'.$key=>$label.' debe ser un número igual o mayor que cero.')); return (float)$value; }
function nexopc_hardware_relation($data,$key,$table,$label,$required=false) { $id=absint($data[$key] ?? 0); if (!$id && !$required) return 0; if (!nexopc_hardware_id($table,$id)) return nexopc_error('nexopc_hardware_validation','Revisa la ficha técnica.',422,array('hardware.specs.'.$key=>'Selecciona '.$label.' de la lista.')); return $id; }
function nexopc_hardware_sync_ids($table,$foreign,$product_id,$ids,$valid_table) { global $wpdb; $column = $table === 'placa_memoria_compatible' ? 'tipo_memoria_id' : ($table === 'placa_interfaz_almacenamiento' ? 'interfaz_id' : 'formato_id'); $wpdb->delete(nexopc_hardware_table($table),array($foreign=>$product_id)); foreach (array_unique(array_filter(array_map('absint',(array)$ids))) as $id) if (nexopc_hardware_id($valid_table,$id)) $wpdb->insert(nexopc_hardware_table($table),array($foreign=>$product_id, $column=>$id)); }
function nexopc_hardware_clear_product($product_id) {
    global $wpdb;
    foreach (array('cpu_especificaciones','placa_especificaciones','ram_especificaciones','gpu_especificaciones','almacenamiento_especificaciones','gabinete_especificaciones','fuente_especificaciones') as $table) $wpdb->delete(nexopc_hardware_table($table),array('product_id'=>$product_id));
    $wpdb->delete(nexopc_hardware_table('placa_memoria_compatible'),array('placa_product_id'=>$product_id));
    $wpdb->delete(nexopc_hardware_table('placa_interfaz_almacenamiento'),array('placa_product_id'=>$product_id));
    $wpdb->delete(nexopc_hardware_table('gabinete_formato_compatible'),array('gabinete_product_id'=>$product_id));
    $wpdb->delete(nexopc_hardware_table('producto_conector_energia'),array('product_id'=>$product_id));
    $wpdb->delete(nexopc_hardware_table('producto_componente'),array('product_id'=>$product_id));
}
function nexopc_hardware_save_product($product_id, $hardware) {
    global $wpdb;
    if ($hardware === null) return true;
    if (!is_array($hardware) || empty($hardware['componentTypeId'])) { nexopc_hardware_clear_product($product_id); return true; }
    $type_id=nexopc_hardware_relation($hardware,'componentTypeId','tipos_componente','un tipo de componente',true); if (is_wp_error($type_id)) return $type_id;
    $type=$wpdb->get_var($wpdb->prepare('SELECT slug FROM '.nexopc_hardware_table('tipos_componente').' WHERE id=%d',$type_id)); $spec=(array)($hardware['specs']??array());
    nexopc_hardware_clear_product($product_id);
    $wpdb->replace(nexopc_hardware_table('producto_componente'),array('product_id'=>$product_id,'tipo_componente_id'=>$type_id));
    $socket=nexopc_hardware_relation($spec,'socketId','sockets','un socket',$type==='procesador'||$type==='placa_madre'); if(is_wp_error($socket))return $socket;
    if ($type==='procesador') { $tdp=nexopc_hardware_number($spec,'tdpWatts','El TDP');if(is_wp_error($tdp))return $tdp;$wpdb->insert(nexopc_hardware_table('cpu_especificaciones'),array('product_id'=>$product_id,'socket_id'=>$socket,'tdp_w'=>$tdp)); }
    if ($type==='placa_madre') { $format=nexopc_hardware_relation($spec,'formFactorId','formatos','un formato');if(is_wp_error($format))return $format;$wpdb->insert(nexopc_hardware_table('placa_especificaciones'),array('product_id'=>$product_id,'socket_id'=>$socket,'formato_id'=>$format?:null,'ranuras_m2'=>absint($spec['m2Slots']??0),'puertos_sata'=>absint($spec['sataPorts']??0)));nexopc_hardware_sync_ids('placa_memoria_compatible','placa_product_id',$product_id,$spec['supportedMemoryTypeIds']??array(),'tipos_memoria');nexopc_hardware_sync_ids('placa_interfaz_almacenamiento','placa_product_id',$product_id,$spec['supportedStorageInterfaceIds']??array(),'interfaces_almacenamiento'); }
    if ($type==='memoria_ram') { $memory=nexopc_hardware_relation($spec,'memoryTypeId','tipos_memoria','un tipo de memoria',true);if(is_wp_error($memory))return $memory;$capacity=nexopc_hardware_number($spec,'capacityGb','La capacidad');if(is_wp_error($capacity))return $capacity;$wpdb->insert(nexopc_hardware_table('ram_especificaciones'),array('product_id'=>$product_id,'tipo_memoria_id'=>$memory,'capacidad_gb'=>$capacity)); }
    if ($type==='tarjeta_grafica') { $memory=nexopc_hardware_relation($spec,'memoryTypeId','tipos_memoria','un tipo de memoria');if(is_wp_error($memory))return $memory;$memory_gb=nexopc_hardware_number($spec,'gpuMemoryGb','La memoria gráfica');if(is_wp_error($memory_gb))return $memory_gb;$length=nexopc_hardware_number($spec,'gpuLengthMm','La longitud de la GPU');if(is_wp_error($length))return $length;$tdp=nexopc_hardware_number($spec,'tdpWatts','El TDP');if(is_wp_error($tdp))return $tdp;$psu=nexopc_hardware_number($spec,'recommendedPsuWatts','La fuente recomendada');if(is_wp_error($psu))return $psu;$wpdb->insert(nexopc_hardware_table('gpu_especificaciones'),array('product_id'=>$product_id,'tipo_memoria_id'=>$memory?:null,'memoria_gb'=>$memory_gb,'longitud_mm'=>$length,'grosor_ranuras'=>$spec['gpuSlots']!==''?(float)($spec['gpuSlots']??0):null,'tdp_w'=>$tdp,'fuente_recomendada_w'=>$psu)); }
    if ($type==='almacenamiento') { $interface=nexopc_hardware_relation($spec,'storageInterfaceId','interfaces_almacenamiento','una interfaz',true);if(is_wp_error($interface))return $interface;$capacity=nexopc_hardware_number($spec,'capacityGb','La capacidad');if(is_wp_error($capacity))return $capacity;$wpdb->insert(nexopc_hardware_table('almacenamiento_especificaciones'),array('product_id'=>$product_id,'interfaz_id'=>$interface,'capacidad_gb'=>$capacity)); }
    if ($type==='gabinete') { $gpu=nexopc_hardware_number($spec,'maxGpuLengthMm','La longitud máxima de GPU');if(is_wp_error($gpu))return $gpu;$wpdb->insert(nexopc_hardware_table('gabinete_especificaciones'),array('product_id'=>$product_id,'longitud_gpu_max_mm'=>$gpu,'altura_cooler_max_mm'=>nexopc_hardware_number($spec,'maxCoolerHeightMm','La altura máxima de cooler'),'bahias_2_5'=>absint($spec['bays25']??0),'bahias_3_5'=>absint($spec['bays35']??0)));nexopc_hardware_sync_ids('gabinete_formato_compatible','gabinete_product_id',$product_id,$spec['supportedFormFactorIds']??array(),'formatos'); }
    if ($type==='fuente_poder') { $watts=nexopc_hardware_number($spec,'continuousWatts','La potencia continua',true);if(is_wp_error($watts))return $watts;$wpdb->insert(nexopc_hardware_table('fuente_especificaciones'),array('product_id'=>$product_id,'potencia_continua_w'=>$watts)); }
    return true;
}
function nexopc_hardware_product_item($product_id) { global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT p.tipo_componente_id,t.slug,t.nombre FROM '.nexopc_hardware_table('producto_componente').' p JOIN '.nexopc_hardware_table('tipos_componente').' t ON t.id=p.tipo_componente_id WHERE p.product_id=%d',$product_id),ARRAY_A); if(!$row)return null; return array('componentTypeId'=>(int)$row['tipo_componente_id'],'componentType'=>array('slug'=>$row['slug'],'name'=>$row['nombre']),'specs'=>nexopc_hardware_specs((int)$product_id,$row['slug'])); }
function nexopc_hardware_specs($id,$type) {
    global $wpdb;
    $tables=array('procesador'=>'cpu_especificaciones','placa_madre'=>'placa_especificaciones','memoria_ram'=>'ram_especificaciones','tarjeta_grafica'=>'gpu_especificaciones','almacenamiento'=>'almacenamiento_especificaciones','gabinete'=>'gabinete_especificaciones','fuente_poder'=>'fuente_especificaciones');
    if(empty($tables[$type])) return array();
    $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.nexopc_hardware_table($tables[$type]).' WHERE product_id=%d',$id),ARRAY_A)?:array();
    if($type==='procesador') return array('socketId'=>(int)($row['socket_id']??0),'tdpWatts'=>$row['tdp_w']??null);
    if($type==='placa_madre') return array('socketId'=>(int)($row['socket_id']??0),'formFactorId'=>(int)($row['formato_id']??0),'m2Slots'=>(int)($row['ranuras_m2']??0),'sataPorts'=>(int)($row['puertos_sata']??0),'supportedMemoryTypeIds'=>array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT tipo_memoria_id FROM '.nexopc_hardware_table('placa_memoria_compatible').' WHERE placa_product_id=%d',$id))),'supportedStorageInterfaceIds'=>array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT interfaz_id FROM '.nexopc_hardware_table('placa_interfaz_almacenamiento').' WHERE placa_product_id=%d',$id))));
    if($type==='memoria_ram') return array('memoryTypeId'=>(int)($row['tipo_memoria_id']??0),'capacityGb'=>(int)($row['capacidad_gb']??0));
    if($type==='tarjeta_grafica') return array('memoryTypeId'=>(int)($row['tipo_memoria_id']??0),'gpuMemoryGb'=>(int)($row['memoria_gb']??0),'gpuLengthMm'=>(int)($row['longitud_mm']??0),'gpuSlots'=>$row['grosor_ranuras']??null,'tdpWatts'=>$row['tdp_w']??null,'recommendedPsuWatts'=>(int)($row['fuente_recomendada_w']??0));
    if($type==='almacenamiento') { $interface_id=(int)($row['interfaz_id']??0); return array('storageInterfaceId'=>$interface_id,'storageInterfaceSlug'=>$interface_id ? $wpdb->get_var($wpdb->prepare('SELECT slug FROM '.nexopc_hardware_table('interfaces_almacenamiento').' WHERE id=%d',$interface_id)) : null,'capacityGb'=>(int)($row['capacidad_gb']??0)); }
    if($type==='gabinete') return array('maxGpuLengthMm'=>(int)($row['longitud_gpu_max_mm']??0),'maxCoolerHeightMm'=>(int)($row['altura_cooler_max_mm']??0),'bays25'=>(int)($row['bahias_2_5']??0),'bays35'=>(int)($row['bahias_3_5']??0),'supportedFormFactorIds'=>array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT formato_id FROM '.nexopc_hardware_table('gabinete_formato_compatible').' WHERE gabinete_product_id=%d',$id))));
    if($type==='fuente_poder') return array('continuousWatts'=>(int)($row['potencia_continua_w']??0));
    return array();
}
