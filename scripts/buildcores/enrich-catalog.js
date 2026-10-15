/**
 * NexoPC - Generador de Catálogo Enriquecido Completo (700 Componentes)
 * 1. Aplica Precios Reales del Mercado Peruano (Soles PEN con IGV) para todas las categorías.
 * 2. Busca entre 4 a 7 imágenes en diferentes ángulos/perspectivas (Frontal, Lateral, Posterior, Puertos, Empaque).
 * 3. Completa los componentes que antes no tenían imágenes mediante búsqueda de ASINs.
 * 4. Almacena en caché progresivo (image-cache.json) para ejecución reanudable e idempotente.
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const { getPeruPrice } = require('./peru-pricing');
const { CSV_HEADERS } = require('./utils');
const { createObjectCsvWriter } = require('csv-writer');

const SCRIPT_DIR = __dirname;
const CACHE_FILE = path.join(SCRIPT_DIR, 'image-gallery-cache.json');
const OUTPUT_DIR = path.join(SCRIPT_DIR, 'output');
const DATA_DIR = path.join(SCRIPT_DIR, 'data/open-db');

const CAT_FOLDERS = {
  'Procesadores': 'CPU',
  'Tarjetas Gráficas': 'GPU',
  'Placas Madre': 'Motherboard',
  'Memoria RAM': 'RAM',
  'Almacenamiento': 'Storage',
  'Fuentes de Poder': 'PSU',
  'Gabinetes': 'PCCase',
  'Refrigeración': 'CPUCooler'
};

// Cargar caché si existe
let imageCache = {};
if (fs.existsSync(CACHE_FILE)) {
  try {
    imageCache = JSON.parse(fs.readFileSync(CACHE_FILE, 'utf8'));
    console.log(`📦 Caché cargado: ${Object.keys(imageCache).length} productos en caché.`);
  } catch (e) {
    console.warn('⚠️ No se pudo leer el archivo de caché, iniciando uno nuevo.');
  }
}

function saveCache() {
  fs.writeFileSync(CACHE_FILE, JSON.stringify(imageCache, null, 2), 'utf8');
}

function parseCSVLine(line) {
  const result = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    if (char === '"') {
      if (inQuotes && line[i + 1] === '"') {
        current += '"';
        i++;
      } else {
        inQuotes = !inQuotes;
      }
    } else if (char === ',' && !inQuotes) {
      result.push(current);
      current = '';
    } else {
      current += char;
    }
  }
  result.push(current);
  return result;
}

function checkImageUrl(url) {
  return new Promise((resolve) => {
    const req = https.request(url, { method: 'HEAD', timeout: 4000 }, (res) => {
      const len = parseInt(res.headers['content-length'] || '0', 10);
      // Amazon retorna un gif transparente de 43 bytes cuando la imagen no existe
      resolve(res.statusCode === 200 && len > 1000 ? { url, len } : null);
    });
    req.on('error', () => resolve(null));
    req.on('timeout', () => { req.destroy(); resolve(null); });
    req.end();
  });
}

async function getAsinGallery(asin, maxImages = 7) {
  if (!asin || asin.length !== 10) return [];

  const suffixes = [
    'LZZZZZZZ.jpg',
    'PT01.LZZZZZZZ.jpg',
    'PT02.LZZZZZZZ.jpg',
    'PT03.LZZZZZZZ.jpg',
    'PT04.LZZZZZZZ.jpg',
    'PT05.LZZZZZZZ.jpg',
    'PT06.LZZZZZZZ.jpg',
    'PT07.LZZZZZZZ.jpg',
    'PT08.LZZZZZZZ.jpg'
  ];

  const checks = await Promise.all(
    suffixes.map(s => checkImageUrl(`https://images-na.ssl-images-amazon.com/images/P/${asin}.01.${s}`))
  );

  return checks.filter(Boolean).map(c => c.url).slice(0, maxImages);
}

function cleanSearchQuery(name) {
  return String(name || '')
    .replace(/\b(Black|White|Silver|Gray|Red|Blue|Gold|Yellow|Green|Orange|Purple)\b/gi, '')
    .replace(/\b(GDDR6X|GDDR6|GDDR5X|GDDR5|HBM2e|HBM2)\b/gi, '')
    .replace(/\bOEM\/Tray\b/gi, '')
    .replace(/\b(Micro-ATX|Mini-ITX|Extended ATX|ATX)\b/gi, '')
    .replace(/\b(288-pin DIMM|DIMM|SO-DIMM)\b/gi, '')
    .replace(/\b(80\+ Gold|80\+ Bronze|80\+ Platinum|80\+ Titanium|80\+ White)\b/gi, '')
    .replace(/\b(Fully Modular|Semi-Modular|Non-Modular)\b/gi, '')
    .replace(/\s*\/\s*/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function searchAmazon(query) {
  return new Promise((resolve) => {
    const cleanQuery = cleanSearchQuery(query);

    const url = `https://www.amazon.com/s?k=${encodeURIComponent(cleanQuery)}`;
    const req = https.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9'
      },
      timeout: 7000
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        const matches = [...data.matchAll(/data-asin="(B[0-9A-Z]{9})"/g)].map(m => m[1]);
        const unique = [...new Set(matches.filter(a => a && a.length === 10))];
        resolve(unique.slice(0, 5));
      });
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function resolveGalleryForProduct(itemJson, rawName, categoryName) {
  const sku = itemJson.opendb_id;

  // 1. Si ya tiene al menos 3 imágenes en caché, reutilizar
  if (imageCache[sku] && Array.isArray(imageCache[sku]) && imageCache[sku].length >= 3) {
    return imageCache[sku];
  }

  // 2. Probar ASIN existente en el JSON
  const listings = itemJson.retailer_listings || itemJson.identifiers?.retailer_listings || [];
  const amzListing = listings.find(l => l.source === 'amazon' && l.source_product_id);
  let asin = amzListing?.source_product_id;

  if (asin) {
    const gallery = await getAsinGallery(asin, 7);
    if (gallery.length >= 3) {
      imageCache[sku] = gallery;
      return gallery;
    }
  }

  // 3. Buscar ASIN por Nombre Limpio del producto
  const cleanedName = cleanSearchQuery(rawName);
  await delay(150);
  const nameAsins = await searchAmazon(cleanedName);

  for (const candidateAsin of nameAsins) {
    const gallery = await getAsinGallery(candidateAsin, 7);
    if (gallery.length >= 3) {
      imageCache[sku] = gallery;
      return gallery;
    }
  }

  // 4. Si aún no encuentra, probar con MPN o nombre corto
  const mpn = itemJson.identifiers?.identifiers?.find(id => id.type === 'mpn')?.value;
  if (mpn) {
    await delay(150);
    const mpnAsins = await searchAmazon(mpn);
    for (const candidateAsin of mpnAsins) {
      const gallery = await getAsinGallery(candidateAsin, 7);
      if (gallery.length >= 2) {
        imageCache[sku] = gallery;
        return gallery;
      }
    }
  }

  // 5. Conservar lo que se tenga si había al menos 1 o 2 imágenes
  if (imageCache[sku] && imageCache[sku].length > 0) {
    return imageCache[sku];
  }
  if (asin) {
    const partial = await getAsinGallery(asin, 7);
    if (partial.length > 0) {
      imageCache[sku] = partial;
      return partial;
    }
  }

  return [];
}

async function main() {
  console.log('🚀 Iniciando Enriquecimiento de Catálogo NexoPC...');
  console.log('   - 700 Componentes');
  console.log('   - Calibración de Precios de Mercado Perú (PEN con IGV)');
  console.log('   - Búsqueda de Múltiples Perspectivas (4 a 7 imágenes por producto)');

  const csvPath = path.join(OUTPUT_DIR, 'all-products.csv');
  if (!fs.existsSync(csvPath)) {
    console.error(`❌ No se encontró ${csvPath}`);
    process.exit(1);
  }

  const rawLines = fs.readFileSync(csvPath, 'utf8').split(/\r?\n/).filter(Boolean);
  const headerCols = parseCSVLine(rawLines[0]);
  const headers = CSV_HEADERS;

  const skuIdx = headerCols.indexOf('SKU');
  const nameIdx = headerCols.indexOf('Name');
  const catIdx = headerCols.indexOf('Categories');
  const regPriceIdx = headerCols.indexOf('Regular price');
  const imgIdx = headerCols.indexOf('Images');

  const categoryBuckets = {};

  console.log(`📋 Leyendo ${rawLines.length - 1} filas del catálogo...`);

  const queue = [];
  for (let i = 1; i < rawLines.length; i++) {
    queue.push({ index: i, raw: rawLines[i] });
  }

  const results = new Array(queue.length);
  let processedCount = 0;
  const CONCURRENCY = 6;

  async function worker() {
    while (queue.length > 0) {
      const item = queue.shift();
      if (!item) break;

      const cols = parseCSVLine(item.raw);
      const sku = cols[skuIdx];
      const name = cols[nameIdx];
      const category = cols[catIdx];
      const folder = CAT_FOLDERS[category];

      // Leer JSON original de open-db
      let itemJson = {};
      if (folder) {
        const jsonPath = path.join(DATA_DIR, folder, `${sku}.json`);
        if (fs.existsSync(jsonPath)) {
          itemJson = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
        }
      }

      // 1. CALCULAR PRECIO REAL PERÚ
      const peruPrice = getPeruPrice(itemJson, folder || category);

      // 2. RESOLVER GALERÍA DE PERSPECTIVAS (4 a 7 imágenes)
      let gallery = [];
      try {
        gallery = await resolveGalleryForProduct(itemJson, name, category);
      } catch (err) {
        // Silencioso en worker
      }

      const imagesVal = gallery.length > 0 ? gallery.join(', ') : (cols[imgIdx] || '');

      const rowObj = {};
      for (let c = 0; c < headerCols.length; c++) {
        const h = headerCols[c];
        const matchingHeader = headers.find(hdr => hdr.title === h);
        const key = matchingHeader ? matchingHeader.id : h;
        rowObj[key] = cols[c];
      }

      rowObj['regular_price'] = peruPrice;
      rowObj['images'] = imagesVal;

      results[item.index - 1] = rowObj;

      processedCount++;
      if (processedCount % 20 === 0 || processedCount === rawLines.length - 1) {
        saveCache();
        console.log(`   [Progreso: ${processedCount}/${rawLines.length - 1}] (${category}) S/ ${peruPrice} [${gallery.length} imgs] - ${name.slice(0, 35)}`);
      }
    }
  }

  const workers = Array.from({ length: CONCURRENCY }, () => worker());
  await Promise.all(workers);

  const rows = results.filter(Boolean);
  for (const r of rows) {
    const category = r.categories;
    categoryBuckets[category] = categoryBuckets[category] || [];
    categoryBuckets[category].push(r);
  }

  saveCache();
  console.log(`\n💾 Guardando catálogo completo actualizado...`);

  // Escribir all-products.csv y all-products-enriched.csv
  const unifiedCsvWriter = createObjectCsvWriter({
    path: path.join(OUTPUT_DIR, 'all-products-enriched.csv'),
    header: CSV_HEADERS,
    encoding: 'utf8'
  });
  await unifiedCsvWriter.writeRecords(rows);

  const mainCsvWriter = createObjectCsvWriter({
    path: path.join(OUTPUT_DIR, 'all-products.csv'),
    header: CSV_HEADERS,
    encoding: 'utf8'
  });
  await mainCsvWriter.writeRecords(rows);

  // Escribir por categoría
  const catFileMap = {
    'Procesadores': 'cpu-products.csv',
    'Tarjetas Gráficas': 'gpu-products.csv',
    'Placas Madre': 'motherboard-products.csv',
    'Memoria RAM': 'ram-products.csv',
    'Almacenamiento': 'storage-products.csv',
    'Fuentes de Poder': 'psu-products.csv',
    'Gabinetes': 'case-products.csv',
    'Refrigeración': 'cooler-products.csv'
  };

  for (const [catName, catRows] of Object.entries(categoryBuckets)) {
    const fileName = catFileMap[catName];
    if (fileName) {
      const catWriter = createObjectCsvWriter({
        path: path.join(OUTPUT_DIR, fileName),
        header: CSV_HEADERS,
        encoding: 'utf8'
      });
      await catWriter.writeRecords(catRows);
    }
  }

  // Estadísticas finales
  let totalWithGallery = 0;
  let totalImagesCount = 0;
  let imageCounts = {};

  rows.forEach(r => {
    const count = r.images ? r.images.split(',').length : 0;
    if (count > 0) totalWithGallery++;
    totalImagesCount += count;
    imageCounts[count] = (imageCounts[count] || 0) + 1;
  });

  console.log('\n=========================================');
  console.log('🎉 CATÁLOGO ACTUALIZADO CON ÉXITO');
  console.log(`Total componentes: ${rows.length}`);
  console.log(`Componentes con imágenes: ${totalWithGallery}/${rows.length}`);
  console.log(`Total enlaces de imagen generados: ${totalImagesCount}`);
  console.log('\nDistribución de imágenes por producto:');
  console.table(imageCounts);
  console.log('=========================================\n');
}

main().catch(err => {
  console.error('❌ Error fatal:', err);
  process.exit(1);
});
