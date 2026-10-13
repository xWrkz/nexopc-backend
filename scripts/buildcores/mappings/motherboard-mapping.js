/**
 * NexoPC - Mapeo de Motherboard (Placas Madre)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'Motherboard',
  woocommerceCategory: 'Placas Madre',
  folderNames: ['Motherboard', 'motherboard', 'motherboards', 'Motherboards'],

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
    if (!data.socket) {
      return { valid: false, reason: 'Falta campo requerido: socket' };
    }
    const formFactor = data.form_factor;
    if (!formFactor) {
      return { valid: false, reason: 'Falta campo requerido: form_factor' };
    }
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables.
   */
  filter(data, options = {}) {
    if (options.tier === 'mid-high') {
      const modernSockets = ['AM5', 'AM4', 'LGA1700', 'LGA1200', 'LGA1851'];
      const socket = cleanString(data.socket).toUpperCase();
      const isModern = modernSockets.some(s => socket.includes(s));
      if (!isModern) return false;
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
    const socket = cleanString(data.socket);
    const formFactor = cleanString(data.form_factor);
    const ramType = cleanString(data.ram_type || data.memory?.ram_type || '');
    const chipset = cleanString(data.chipset || '');

    const specs = {
      'Socket': socket,
      'Factor de Forma': formFactor,
      'Tipo de RAM Soportada': ramType,
      'Chipset': chipset,
      'Fabricante': manufacturer
    };

    if (data.memory?.max) {
      specs['RAM Máxima'] = `${data.memory.max} GB`;
    }
    if (data.memory?.slots) {
      specs['Ranuras de Memoria'] = data.memory.slots;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, chipset, socket, formFactor, ramType].filter(Boolean).join(', ');

    return {
      name: fullName,
      sku: data.opendb_id,
      regular_price: options.basePrice ?? 0,
      sale_price: '',
      categories: this.woocommerceCategory,
      tags,
      images: '',
      description,
      attr_socket: socket,
      attr_tipo_ram: ramType,
      attr_tdp: '',
      attr_formato: formFactor,
      attr_longitud_gpu: '',
      attr_vatios: '',
      attr_tipo_almacenamiento: '',
      attr_capacidad_gb: '',
      attr_marca: manufacturer,
      meta_opendb_id: data.opendb_id,
      meta__opendb_id: data.opendb_id,
      meta_manufacturer: manufacturer
    };
  }
};
