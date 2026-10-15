/**
 * NexoPC - Mapeo de CPU Cooler (Refrigeración / Disipadores)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'Cooler',
  woocommerceCategory: 'Refrigeración',
  folderNames: ['CPUCooler', 'cpucooler', 'Cooler', 'cooler', 'Coolers', 'coolers'],

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
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables.
   */
  filter(data, options = {}) {
    if (options.tier === 'curated' || options.curated) {
      const sockets = Array.isArray(data.cpu_sockets) ? data.cpu_sockets.join(' ') : cleanString(data.cpu_sockets);
      const modernSockets = ['AM5', 'AM4', 'LGA1700', 'LGA1851', 'LGA1200'];
      const normalizedSockets = sockets.replace(/\s+/g, '').toUpperCase();
      const isModern = modernSockets.some(s => normalizedSockets.includes(s));
      if (data.cpu_sockets && data.cpu_sockets.length > 0 && !isModern) return false;

      const topMfrs = ['THERMALRIGHT', 'DEEPCOOL', 'NZXT', 'CORSAIR', 'ARCTIC', 'COOLER MASTER', 'BE QUIET', 'NOCTUA', 'ASUS', 'MSI'];
      const mfr = cleanString(data.metadata?.manufacturer).toUpperCase();
      if (!topMfrs.some(m => mfr.includes(m))) return false;
      return true;
    }

    if (options.tier === 'mid-high') {
      const sockets = Array.isArray(data.cpu_sockets) ? data.cpu_sockets.join(' ') : '';
      const modernSockets = ['AM5', 'AM4', 'LGA1700', 'LGA1851', 'LGA1200'];
      const normalizedSockets = sockets.replace(/\s+/g, '').toUpperCase();
      const isModern = modernSockets.some(s => normalizedSockets.includes(s));
      if (data.cpu_sockets && data.cpu_sockets.length > 0 && !isModern) return false;
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
    const isWater = Boolean(data.water_cooled);
    const coolerType = isWater ? 'Refrigeración Líquida (AIO)' : 'Disipador por Aire';
    const radSize = cleanNumber(data.radiator_size);
    const height = cleanNumber(data.height);
    const sockets = Array.isArray(data.cpu_sockets) ? data.cpu_sockets.join(', ') : cleanString(data.cpu_sockets || '');

    const specs = {
      'Tipo de Refrigeración': coolerType,
      'Sockets Compatibles': sockets,
      'Fabricante': manufacturer
    };

    if (radSize) specs['Tamaño de Radiador'] = `${radSize} mm`;
    if (height) specs['Altura del Disipador'] = `${height} mm`;
    if (data.max_fan_rpm) specs['RPM Máximo'] = `${data.max_fan_rpm} RPM`;
    if (data.min_noise_level) specs['Nivel de Ruido'] = `${data.min_noise_level} dBA`;

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, coolerType, radSize ? `${radSize}mm` : ''].filter(Boolean).join(', ');
    const price = options.basePrices?.[this.category] ?? options.basePrice ?? 0;

    return {
      name: fullName,
      sku: data.opendb_id,
      regular_price: price,
      sale_price: '',
      categories: this.woocommerceCategory,
      tags,
      images: '',
      description,
      attr_socket: sockets,
      attr_tipo_ram: '',
      attr_tdp: '',
      attr_formato: coolerType,
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
