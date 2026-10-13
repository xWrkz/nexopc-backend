/**
 * NexoPC - Mapeo de Case (Gabinetes)
 * BuildCores Open DB -> WooCommerce
 */

const { formatProductName, cleanString, cleanNumber, generateDescription } = require('../utils');

/**
 * Normaliza el formato de gabinete a un estándar legible.
 */
function normalizeCaseFormFactor(formFactor) {
  const f = cleanString(formFactor);
  if (!f) return 'ATX Mid Tower';
  return f;
}

module.exports = {
  category: 'Case',
  woocommerceCategory: 'Gabinetes',
  folderNames: ['Case', 'case', 'cases', 'PCCase', 'pccase', 'PCCases'],

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
    const maxGpuLength = cleanNumber(data.max_gpu_length ?? data.gpu_length ?? data.gpu_clearance ?? data.dimensions?.max_gpu_length);

    if (options.tier === 'mid-high') {
      // Filtrar gabinetes con buen espacio para GPUs modernas (al menos 300 mm si está especificado)
      if (maxGpuLength !== null && maxGpuLength < 300) return false;
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
    const formFactor = normalizeCaseFormFactor(data.form_factor);
    const maxGpuLength = cleanNumber(data.max_gpu_length ?? data.gpu_length ?? data.gpu_clearance ?? data.dimensions?.max_gpu_length);

    const specs = {
      'Factor de Forma Gabinete': formFactor,
      'Longitud Máxima de GPU': maxGpuLength !== null ? `${maxGpuLength} mm` : '',
      'Fabricante': manufacturer
    };

    if (data.side_panel) {
      specs['Panel Lateral'] = data.side_panel;
    }
    if (data.color && Array.isArray(data.color)) {
      specs['Color'] = data.color.join(', ');
    } else if (data.color) {
      specs['Color'] = data.color;
    }

    const description = generateDescription(data, this.woocommerceCategory, specs);
    const tags = [manufacturer, formFactor, maxGpuLength ? `GPU-${maxGpuLength}mm` : ''].filter(Boolean).join(', ');

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
      attr_longitud_gpu: maxGpuLength !== null ? String(maxGpuLength) : '',
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
