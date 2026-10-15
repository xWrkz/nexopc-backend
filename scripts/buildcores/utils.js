/**
 * NexoPC BuildCores Importer - Utilidades compartidas
 */

/**
 * Formatea el nombre del producto asegurando que incluya el fabricante
 * sin duplicarlo si el nombre ya lo contiene.
 * 
 * @param {string} manufacturer 
 * @param {string} name 
 * @returns {string}
 */
function formatProductName(manufacturer, name) {
  const m = (manufacturer || '').trim();
  const n = (name || '').trim();
  if (!m) return n;
  if (!n) return m;

  // Evitar duplicar fabricante si el nombre ya inicia con él (case-insensitive)
  if (n.toLowerCase().startsWith(m.toLowerCase())) {
    return n;
  }
  return `${m} ${n}`;
}

/**
 * Limpia y normaliza una cadena de texto.
 * 
 * @param {any} val 
 * @returns {string}
 */
function cleanString(val) {
  if (val === null || val === undefined) return '';
  return String(val).trim();
}

/**
 * Normaliza y convierte a número si es válido; de lo contrario retorna null o default.
 * 
 * @param {any} val 
 * @param {number|null} defaultVal 
 * @returns {number|null}
 */
function cleanNumber(val, defaultVal = null) {
  if (val === null || val === undefined || val === '') return defaultVal;
  const num = Number(val);
  return Number.isNaN(num) ? defaultVal : num;
}

/**
 * Genera una descripción legible en HTML para WooCommerce con especificaciones técnicas.
 * 
 * @param {Object} item 
 * @param {string} category 
 * @param {Object} specs 
 * @returns {string}
 */
function generateDescription(item, category, specs = {}) {
  const name = formatProductName(item.metadata?.manufacturer, item.metadata?.name);
  const manufacturer = item.metadata?.manufacturer || 'NexoPC';
  const series = item.metadata?.series ? ` (Serie ${item.metadata.series})` : '';

  let html = `<p><strong>${name}</strong>${series} es un componente de alta calidad fabricado por <strong>${manufacturer}</strong>, optimizado para equipos de alto rendimiento y gaming.</p>`;
  
  const entries = Object.entries(specs).filter(([_, v]) => v !== undefined && v !== null && v !== '');
  if (entries.length > 0) {
    html += '<h4>Especificaciones Técnicas</h4><ul>';
    for (const [k, v] of entries) {
      html += `<li><strong>${k}:</strong> ${v}</li>`;
    }
    html += '</ul>';
  }

  html += '<p><em>Compatible con el configurador inteligente de PC de NexoPC.</em></p>';
  return html;
}

/**
 * Definición estándar de columnas CSV esperadas por WooCommerce y WP All Import
 */
const CSV_HEADERS = [
  { id: 'name', title: 'Name' },
  { id: 'sku', title: 'SKU' },
  { id: 'regular_price', title: 'Regular price' },
  { id: 'sale_price', title: 'Sale price' },
  { id: 'categories', title: 'Categories' },
  { id: 'tags', title: 'Tags' },
  { id: 'images', title: 'Images' },
  { id: 'description', title: 'Description' },
  { id: 'attr_socket', title: 'Attribute: socket' },
  { id: 'attr_tipo_ram', title: 'Attribute: tipo-ram' },
  { id: 'attr_tdp', title: 'Attribute: tdp' },
  { id: 'attr_formato', title: 'Attribute: formato' },
  { id: 'attr_longitud_gpu', title: 'Attribute: longitud-gpu' },
  { id: 'attr_vatios', title: 'Attribute: vatios' },
  { id: 'attr_tipo_almacenamiento', title: 'Attribute: tipo-almacenamiento' },
  { id: 'attr_capacidad_gb', title: 'Attribute: capacidad-gb' },
  { id: 'attr_marca', title: 'Attribute: marca' },
  { id: 'meta_opendb_id', title: 'Meta: opendb_id' },
  { id: 'meta__opendb_id', title: 'Meta: _opendb_id' },
  { id: 'meta_manufacturer', title: 'Meta: manufacturer' }
];

/**
 * Extrae la URL oficial de imagen de Amazon a partir del ASIN si está presente.
 * 
 * @param {Object} data
 * @returns {string}
 */
function getAmazonImageUrl(data) {
  const listings = data.retailer_listings || data.identifiers?.retailer_listings || [];
  const amz = listings.find(l => l.source === 'amazon' && l.source_product_id);
  if (amz?.source_product_id) {
    return `https://images-na.ssl-images-amazon.com/images/P/${amz.source_product_id}.01.LZZZZZZZ.jpg`;
  }
  return '';
}

module.exports = {
  formatProductName,
  cleanString,
  cleanNumber,
  generateDescription,
  getAmazonImageUrl,
  CSV_HEADERS
};
