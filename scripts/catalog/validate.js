#!/usr/bin/env node
/**
 * Valida el catálogo curado antes de importarlo.
 *
 * Uso: node validate.js [carpeta-data]
 * Sale con código 1 si hay errores de esquema o huecos que romperían el armador.
 */
const fs = require('fs');
const path = require('path');

const dataDir = path.resolve(process.argv[2] || path.join(__dirname, 'data'));
const SOCKETS = ['am4', 'am5', 'lga1700', 'lga1851'];
const FORMATS = ['mini_itx', 'micro_atx', 'atx', 'e_atx'];
const SYSTEM_MEMORY = ['ddr4', 'ddr5'];
const GRAPHICS_MEMORY = ['gddr5', 'gddr6', 'gddr6x', 'gddr7'];
const INTERFACES = ['m2_nvme', 'm2_sata', 'sata_2_5', 'sata_3_5'];

const isNumber = (value) => typeof value === 'number' && value >= 0;
const isOneOf = (options) => (value) => options.includes(value);
const isListOf = (options) => (value) => Array.isArray(value) && value.length > 0 && value.every((item) => options.includes(item));
const isBoolean = (value) => typeof value === 'boolean';

const SPECS = {
  cpu: { socket: isOneOf(SOCKETS), tdp_w: isNumber, memory_types: isListOf(SYSTEM_MEMORY), igpu: isBoolean, cooler_included: isBoolean, cores: isNumber, threads: isNumber },
  motherboard: { chipset: (value) => typeof value === 'string' && value !== '', socket: isOneOf(SOCKETS), form: isOneOf(FORMATS), memory_types: isListOf(SYSTEM_MEMORY), ram_slots: isNumber, m2_slots: isNumber, sata_ports: isNumber },
  ram: { memory_type: isOneOf(SYSTEM_MEMORY), capacity_gb: isNumber, modules: isNumber, speed_mts: isNumber },
  gpu: { chip: (value) => typeof value === 'string' && value !== '', memory_type: isOneOf(GRAPHICS_MEMORY), memory_gb: isNumber, length_mm: isNumber, slots: isNumber, tdp_w: isNumber, psu_w: isNumber },
  storage: { interface: isOneOf(INTERFACES), capacity_gb: isNumber },
  psu: { watts: isNumber, certification: (value) => typeof value === 'string' && value !== '' },
  case: { formats: isListOf(FORMATS), max_gpu_mm: isNumber, max_cooler_mm: isNumber, max_radiator_mm: isNumber, bays_2_5: isNumber, bays_3_5: isNumber },
  cooler: { type: isOneOf(['aire', 'liquido']), sockets: isListOf(SOCKETS) },
};

function loadProducts() {
  return fs.readdirSync(dataDir)
    .filter((file) => file.endsWith('.json'))
    .flatMap((file) => JSON.parse(fs.readFileSync(path.join(dataDir, file), 'utf8')).map((product) => ({ ...product, file })));
}

function validateProduct(product, errors) {
  const label = `${product.file}: ${product.key || product.name || 'sin key'}`;
  for (const field of ['key', 'name', 'brand', 'category', 'summary_es']) {
    if (!product[field]) errors.push(`${label}: falta ${field}`);
  }
  if (!SPECS[product.category]) {
    errors.push(`${label}: categoría desconocida ${product.category}`);
    return;
  }
  if (!Array.isArray(product.sources) || !product.sources.length) errors.push(`${label}: sin fuentes`);
  if ((product.sources || []).some((url) => /buildcores|amazon\.|pcpartpicker/i.test(url))) errors.push(`${label}: fuente prohibida`);

  const specs = product.specs || {};
  for (const [field, isValid] of Object.entries(SPECS[product.category])) {
    if (specs[field] === null || specs[field] === undefined) {
      if (product.verified && field !== 'cooler_included') errors.push(`${label}: verified con ${field} vacío`);
    } else if (!isValid(specs[field])) {
      errors.push(`${label}: ${field} inválido (${JSON.stringify(specs[field])})`);
    }
  }
  if (product.category === 'cooler' && product.verified) {
    const measure = specs.type === 'liquido' ? specs.radiator_mm : specs.height_mm;
    if (!isNumber(measure)) errors.push(`${label}: verified sin medida de ${specs.type}`);
  }
}

function checkCoverage(products, errors) {
  const published = products.filter((product) => product.verified);
  const of = (category) => published.filter((product) => product.category === category);
  for (const socket of SOCKETS) {
    if (!of('cpu').some((item) => item.specs.socket === socket)) errors.push(`Cobertura: sin CPU ${socket}`);
    if (!of('motherboard').some((item) => item.specs.socket === socket)) errors.push(`Cobertura: sin placa ${socket}`);
    if (!of('cooler').some((item) => item.specs.sockets.includes(socket))) errors.push(`Cobertura: sin refrigeración ${socket}`);
  }
  for (const memory of SYSTEM_MEMORY) {
    if (!of('ram').some((item) => item.specs.memory_type === memory)) errors.push(`Cobertura: sin RAM ${memory}`);
  }
  for (const form of ['micro_atx', 'atx']) {
    if (!of('case').some((item) => item.specs.formats.includes(form))) errors.push(`Cobertura: sin gabinete ${form}`);
  }
}

const products = loadProducts();
const errors = [];
products.forEach((product) => validateProduct(product, errors));

const keys = products.map((product) => product.key);
keys.filter((key, index) => keys.indexOf(key) !== index).forEach((key) => errors.push(`Key duplicada: ${key}`));
checkCoverage(products, errors);
const prices = checkPrices(products, errors);

function checkPrices(products, errors) {
  const file = path.join(__dirname, 'prices.json');
  if (!fs.existsSync(file)) return {};
  const prices = JSON.parse(fs.readFileSync(file, 'utf8')).products || {};
  const known = new Set(products.map((product) => product.key));
  for (const [key, price] of Object.entries(prices)) {
    if (!known.has(key)) errors.push(`prices.json: ${key} no existe en el catálogo`);
    if (!(typeof price.pen === 'number' && price.pen > 0)) errors.push(`prices.json: ${key} sin precio válido`);
    if (!price.source || !/^\d{4}-\d{2}-\d{2}$/.test(price.checked_at || '')) errors.push(`prices.json: ${key} sin fuente o fecha`);
  }
  return prices;
}

const summary = {};
for (const product of products) {
  const row = summary[product.category] ??= { total: 0, verificados: 0, conImagen: 0, conPrecio: 0 };
  row.total += 1;
  row.verificados += product.verified ? 1 : 0;
  row.conImagen += product.image_url ? 1 : 0;
  row.conPrecio += prices[product.key] ? 1 : 0;
}
console.table(summary);
console.log(`Total: ${products.length}`);

if (errors.length) {
  console.error(`\n${errors.length} errores:`);
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}
console.log('Catálogo válido.');
