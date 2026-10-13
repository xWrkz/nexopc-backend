/**
 * NexoPC - Mapeo de Storage (Almacenamiento)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

/**
 * Normaliza el tipo de almacenamiento a categorías estándar: NVMe, SATA o HDD.
 */
function normalizeStorageType(storageType, iface, formFactor) {
  const combined = `${storageType} ${iface} ${formFactor}`.toUpperCase();
  if (combined.includes('NVME') || combined.includes('PCIE') || combined.includes('M.2 PCIE')) {
    return 'NVMe';
  }
  if (combined.includes('HDD') || combined.includes('HARD DRIVE') || combined.includes('SPINNING')) {
    return 'HDD';
  }
  if (combined.includes('SATA')) {
    return 'SATA';
  }
  return cleanString(storageType || iface || 'SSD');
}

module.exports = {
  category: 'Storage',
  woocommerceCategory: 'Almacenamiento',
  folderNames: ['Storage', 'storage', 'InternalHardDrive', 'internalharddrive', 'SSD', 'HDD'],

  /**
   * Valida que el JSON posea los campos mínimos requeridos.
   */
  validate(data) {
    if (!data.opendb_id) {
      return { valid: false, reason: 'Falta campo requerido: opendb_id' };
    }
    if (!data.metadata?.name) {
      return { valid: false, reason: 'Falta campo requerido: metadata.name' };
    }
    const capacity = cleanNumber(data.capacity);
    if (capacity === null || capacity <= 0) {
      return { valid: false, reason: 'Falta campo requerido o inválido: capacity' };
    }
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables.
   */
  filter(data, options = {}) {
    const capacity = cleanNumber(data.capacity, 0);

    if (options.tier === 'mid-high') {
      // Filtrar unidades de al menos 480/500 GB preferentemente SSD / NVMe
      if (capacity < 480) return false;
    }

    return true;
  },

  /**
   * Transforma el objeto JSON al formato de fila CSV para WooCommerce.
   */
  map(data, options = {}) {
    const manufacturer = cleanString(data.metadata?.manufacturer);
    const rawName = cleanString(data.metadata?.name);
    const fullName = formatProductName(manufacturer, rawName);
    const capacity = cleanNumber(data.capacity);
    const iface = cleanString(data.interface || '');
    const storageType = cleanString(data.storage_type || '');
    const formFactor = cleanString(data.form_factor || '');
    const normalizedType = normalizeStorageType(storageType, iface, formFactor);

    const specs = {
      'Tipo de Almacenamiento': normalizedType,
      'Capacidad': capacity >= 1000 ? `${(capacity / 1000).toFixed(0)} TB (${capacity} GB)` : `${capacity} GB`,
      'Interfaz': iface,
      'Factor de Forma': formFactor,
      'Fabricante': manufacturer
    };

    if (data.cache) {
      specs['Caché'] = `${data.cache} MB`;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, normalizedType, formFactor, `${capacity}GB`].filter(Boolean).join(', ');

    return {
      name: fullName,
      sku: data.opendb_id,
      regular_price: options.basePrice ?? 0,
      sale_price: '',
      categories: this.woocommerceCategory,
      tags,
      images: '',
      description,
      attr_socket: '',
      attr_tipo_ram: '',
      attr_tdp: '',
      attr_formato: formFactor,
      attr_longitud_gpu: '',
      attr_vatios: '',
      attr_tipo_almacenamiento: normalizedType,
      attr_capacidad_gb: capacity !== null ? String(capacity) : '',
      attr_marca: manufacturer,
      meta_opendb_id: data.opendb_id,
      meta__opendb_id: data.opendb_id,
      meta_manufacturer: manufacturer
    };
  }
};
