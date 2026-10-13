/**
 * NexoPC - Mapeo de GPU (Tarjetas Gráficas)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

module.exports = {
  category: 'GPU',
  woocommerceCategory: 'Tarjetas Gráficas',
  folderNames: ['GPU', 'gpu', 'gpus', 'VideoCard', 'videocards'],

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
    const length = cleanNumber(data.length ?? data.gpu_length);
    const memory = cleanNumber(data.memory);
    if (length === null && memory === null && data.tdp === undefined) {
      return { valid: false, reason: 'Faltan especificaciones técnicas mínimas (length, memory, tdp)' };
    }
    return { valid: true };
  },

  /**
   * Filtra según criterios configurables (ej. VRAM mínima, TDP).
   */
  filter(data, options = {}) {
    const memory = cleanNumber(data.memory, 0);
    const tdp = cleanNumber(data.tdp, 0);

    if (options.minGpuVram && memory < options.minGpuVram) {
      return false;
    }

    if (options.tier === 'mid-high') {
      // Filtrar tarjetas con al menos 6GB de VRAM o TDP > 115W para gaming moderno
      if (memory > 0 && memory < 6) return false;
      if (tdp > 0 && tdp < 90) return false;
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
    const length = cleanNumber(data.length ?? data.gpu_length);
    const memory = cleanNumber(data.memory);
    const tdp = cleanNumber(data.tdp);
    const chipset = cleanString(data.chipset || data.chipset_manufacturer || '');

    const specs = {
      'Chipset': chipset,
      'Memoria VRAM': memory !== null ? `${memory} GB` : '',
      'Longitud': length !== null ? `${length} mm` : '',
      'Consumo TDP': tdp !== null ? `${tdp} W` : '',
      'Fabricante': manufacturer
    };

    if (data.memory_type) {
      specs['Tipo de Memoria'] = data.memory_type;
    }
    if (data.boost_clock) {
      specs['Reloj Boost'] = `${data.boost_clock} MHz`;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, chipset, data.memory_type].filter(Boolean).join(', ');

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
      attr_tdp: tdp !== null ? String(tdp) : '',
      attr_formato: '',
      attr_longitud_gpu: length !== null ? String(length) : '',
      attr_vatios: '',
      attr_tipo_almacenamiento: '',
      attr_capacidad_gb: memory !== null ? String(memory) : '',
      attr_marca: manufacturer,
      meta_opendb_id: data.opendb_id,
      meta__opendb_id: data.opendb_id,
      meta_manufacturer: manufacturer
    };
  }
};
