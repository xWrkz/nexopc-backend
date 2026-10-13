/**
 * NexoPC - Mapeo de PSU (Fuentes de Poder)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'PSU',
  woocommerceCategory: 'Fuentes de Poder',
  folderNames: ['PSU', 'psu', 'psus', 'PowerSupply', 'powersupply'],

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
    const wattage = cleanNumber(data.wattage);
    if (wattage === null || wattage <= 0) {
      return { valid: false, reason: 'Falta campo requerido o inválido: wattage' };
    }
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables.
   */
  filter(data, options = {}) {
    const wattage = cleanNumber(data.wattage, 0);

    if (options.tier === 'mid-high') {
      // Filtrar fuentes de al menos 600W para builds modernas
      if (wattage < 600) return false;
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
    const wattage = cleanNumber(data.wattage);
    const formFactor = cleanString(data.form_factor || 'ATX');
    const efficiency = cleanString(data.efficiency_rating || '');
    const modular = cleanString(data.modular || '');

    const specs = {
      'Potencia Nominal': `${wattage} W`,
      'Factor de Forma': formFactor,
      'Certificación': efficiency,
      'Modularidad': modular,
      'Fabricante': manufacturer
    };

    if (data.length) {
      specs['Longitud PSU'] = `${data.length} mm`;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, `${wattage}W`, formFactor, efficiency, modular].filter(Boolean).join(', ');

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
      attr_vatios: wattage !== null ? String(wattage) : '',
      attr_tipo_almacenamiento: '',
      attr_capacidad_gb: '',
      attr_marca: manufacturer,
      meta_opendb_id: data.opendb_id,
      meta__opendb_id: data.opendb_id,
      meta_manufacturer: manufacturer
    };
  }
};
