/**
 * NexoPC - Mapeo de CPU (Procesadores)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'CPU',
  woocommerceCategory: 'Procesadores',
  folderNames: ['CPU', 'cpu', 'cpus', 'Processor', 'processors'],

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
    const socket = data.socket || data.specifications?.socket;
    if (!socket) {
      return { valid: false, reason: 'Falta campo requerido: socket' };
    }
    const tdp = cleanNumber(data.specifications?.tdp ?? data.tdp);
    if (tdp === null) {
      return { valid: false, reason: 'Falta campo requerido: tdp' };
    }
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables (ej. gama, TDP mínimo).
   */
  filter(data, options = {}) {
    const tdp = cleanNumber(data.specifications?.tdp ?? data.tdp, 0);

    if (options.minTdp && tdp < options.minTdp) {
      return false;
    }

    if (options.tier === 'curated' || options.curated) {
      const modernSockets = ['AM5', 'AM4', 'LGA1700', 'LGA1851', 'LGA1200'];
      const socket = cleanString(data.socket || data.specifications?.socket).replace(/\s+/g, '').toUpperCase();
      const isModernSocket = modernSockets.some(s => socket.includes(s));
      if (!isModernSocket) return false;

      const name = cleanString(data.metadata?.name).toUpperCase();
      const mfr = cleanString(data.metadata?.manufacturer).toUpperCase();
      if (name.includes('THREADRIPPER') || name.includes('EPYC') || name.includes('XEON') || name.includes('PENTIUM') || name.includes('CELERON')) {
        return false;
      }
      if (mfr.includes('AMD') && !name.includes('RYZEN')) return false;
      if (mfr.includes('INTEL') && !name.includes('CORE') && !name.includes('ULTRA')) return false;

      const cores = cleanNumber(data.cores?.total ?? data.cores, 0);
      if (cores > 0 && cores < 4) return false;
      if (tdp < 35) return false;
      return true;
    }

    if (options.tier === 'mid-high') {
      // Filtrar plataformas y procesadores modernos / gama media-alta
      const modernSockets = ['AM5', 'AM4', 'LGA1700', 'LGA1200', 'LGA1151', 'sTR5', 'TR4'];
      const socket = cleanString(data.socket || data.specifications?.socket).replace(/\s+/g, '').toUpperCase();
      const isModernSocket = modernSockets.some(s => socket.includes(s));
      if (!isModernSocket) return false;
      if (tdp < 50) return false;
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
    const socket = cleanString(data.socket || data.specifications?.socket);
    const tdp = cleanNumber(data.specifications?.tdp ?? data.tdp);

    const specs = {
      'Socket': socket,
      'TDP': `${tdp} W`,
      'Fabricante': manufacturer
    };

    const cores = data.cores?.total ?? data.cores;
    if (cores) {
      specs['Núcleos'] = typeof cores === 'object' ? (cores.total || cores.performance || '') : cores;
    }

    const threads = data.cores?.threads ?? data.threads;
    if (threads) {
      specs['Hilos'] = threads;
    }

    const baseClock = data.clocks?.performance?.base ?? data.base_clock;
    if (baseClock) {
      specs['Frecuencia Base'] = `${baseClock} GHz`;
    }

    const boostClock = data.clocks?.performance?.boost ?? data.boost_clock;
    if (boostClock) {
      specs['Frecuencia Boost'] = `${boostClock} GHz`;
    }

    const ramType = cleanString(
      Array.isArray(data.specifications?.memory?.types)
        ? data.specifications.memory.types.join('/')
        : (data.ram_type || '')
    );

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, data.metadata?.series || data.series, socket].filter(Boolean).join(', ');
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
      attr_socket: socket,
      attr_tipo_ram: ramType,
      attr_tdp: tdp !== null ? String(tdp) : '',
      attr_formato: '',
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
