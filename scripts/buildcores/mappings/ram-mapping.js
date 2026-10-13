/**
 * NexoPC - Mapeo de RAM (Memoria RAM)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'RAM',
  woocommerceCategory: 'Memoria RAM',
  folderNames: ['RAM', 'ram', 'rams', 'Memory', 'memory'],

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
    if (!data.ram_type) {
      return { valid: false, reason: 'Falta campo requerido: ram_type' };
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

    if (options.minRamCapacity && capacity < options.minRamCapacity) {
      return false;
    }

    if (options.tier === 'mid-high') {
      // Filtrar kits de al menos 16GB DDR4 o DDR5
      const ramType = cleanString(data.ram_type).toUpperCase();
      if (!ramType.includes('DDR4') && !ramType.includes('DDR5')) return false;
      if (capacity < 16) return false;
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
    const ramType = cleanString(data.ram_type);
    const capacity = cleanNumber(data.capacity);
    const speed = cleanNumber(data.speed);

    const specs = {
      'Tipo de RAM': ramType,
      'Capacidad Total': `${capacity} GB`,
      'Velocidad': speed ? `${speed} MHz` : '',
      'Fabricante': manufacturer
    };

    if (data.modules && typeof data.modules === 'object') {
      const q = data.modules.quantity;
      const c = data.modules.capacity_gb;
      if (q && c) specs['Configuración'] = `${q}x${c} GB`;
    }
    if (data.cas_latency) {
      specs['Latencia CAS'] = `CL${data.cas_latency}`;
    }
    if (data.timings) {
      specs['Timings'] = data.timings;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, ramType, speed ? `${speed}MHz` : '', `${capacity}GB`].filter(Boolean).join(', ');

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
      attr_tipo_ram: ramType,
      attr_tdp: '',
      attr_formato: cleanString(data.form_factor || ''),
      attr_longitud_gpu: '',
      attr_vatios: '',
      attr_tipo_almacenamiento: '',
      attr_capacidad_gb: capacity !== null ? String(capacity) : '',
      attr_marca: manufacturer,
      meta_opendb_id: data.opendb_id,
      meta__opendb_id: data.opendb_id,
      meta_manufacturer: manufacturer
    };
  }
};
