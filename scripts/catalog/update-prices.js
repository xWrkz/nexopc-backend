#!/usr/bin/env node
/**
 * Actualiza prices.json con precios de referencia de falabella.com.pe.
 *
 * Uso: node update-prices.js [--only=<clave,clave>] [--dry-run]
 *
 * Busca cada producto del catálogo por nombre y por número de parte, descarta los
 * listados que no son el mismo modelo (otra variante, color, kits de PC armada) y
 * toma la mediana de las ofertas vigentes, sin precio CMR ni precio tachado.
 * Solo guarda el número, el vendedor y la URL: nunca descripciones ni imágenes.
 *
 * Los precios cargados a mano desde otra tienda (source distinto de Falabella) se
 * conservan cuando Falabella no tiene el producto.
 */
const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'data');
const PRICES_FILE = path.join(ROOT, 'prices.json');
const SOURCE = 'falabella.com.pe';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36';
const CONCURRENCY = 4;

// Listados que pasan el filtro automático pero no son el producto del catálogo.
// /./ descarta todos los candidatos: Falabella solo tiene otras marcas o variantes.
const EXCLUDE = {
  'case-msi-mag-forge-120a-airflow': /fuente/i,
  'mb-asrock-x870-pro-rs-wifi': /./,
  'ram-kingston-ddr5-5600-16': /fury|so-?dimm|port[aá]til/i,
  'ram-kingston-ddr5-5600-32': /fury|so-?dimm|port[aá]til/i,
  'mb-msi-b850-gaming-plus-wifi': /tuf/i,
  'mb-msi-pro-b760m-p': /b760m-e/i,
  'mb-msi-pro-h810m-b': /h810m-e/i,
  'mb-asus-prime-b860m-k': /b860m-a/i,
  'storage-hiksemi-wave-pro-p-512': /sata/i,
  'gpu-xfx-rx9070xt-swift-triple-16g': /./,
  'gpu-xfx-rx9070xt-swift-white-triple-16g': /./,
  'gpu-gigabyte-rtx5060-windforce-max-oc-8g': /eagle/i,
  'gpu-asus-rtx5060ti-dual-oc-8g': /./,
};

// Palabras que, si aparecen solo en el listado, indican otra variante del producto.
const VARIANT_WORDS = ['wh', 'mesh', 'ti', 'xt', 'x3d', 'super', 'rgb', 'argb', 'white', 'blanco', 'blanca', 'wifi', 'g', 'f', 'kf', 'k', 'x', 'v2', 'v3', 'plus', 'pro', 'max', 'digital', 'se', 'gt'];
const REQUIRED_WORDS = ['ti', 'xt', 'x3d', 'kf', 'g', 'f', 'v2', 'v3', 'rgb', 'argb', 'white', 'wifi', 'd4', 'plus'];
const GENERIC_WORDS = ['amd', 'intel', 'ryzen', 'core', 'ultra', 'geforce', 'radeon', 'nvidia', 'rtx', 'rx', 'gaming', 'edition', 'oc', 'gddr6', 'gddr7', 'ddr4', 'ddr5', 'black', 'airflow', 'tower', 'mid', 'fan', 'triple', 'speedster', 'swift', 'with', 'and'];
const NOT_A_PART = [' pc gamer ', ' laptop ', ' computadora ', ' cpu gamer ', ' combo ', ' kit gamer ', ' notebook ', ' pc de escritorio ', ' all in one ', ' pull ', ' reacondicionado ', ' usado '];

const normalize = (text) => ' ' + text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/(\d)\s*(gb|tb|w)\b/g, '$1$2')
  .replace(/\b1024gb\b/g, '1tb').replace(/\b(2048|2000)gb\b/g, '2tb')
  .replace(/\b(\d{4})\s+(xt|ti)\b/g, '$1$2')
  .replace(/\bt-?(create|force)\b/g, 't $1')
  .replace(/[^a-z0-9]+/g, ' ') + ' ';
const compact = (text) => text.toLowerCase().replace(/[^a-z0-9]/g, '');
const toNumber = (text) => Number(String(text).replace(/,/g, ''));
const median = (values) => { const sorted = [...values].sort((a, b) => a - b); return sorted[Math.floor((sorted.length - 1) / 2)]; };
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function search(query) {
  const url = 'https://www.falabella.com.pe/falabella-pe/search?Ntt=' + encodeURIComponent(query);
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const response = await fetch(url, { headers: { 'User-Agent': USER_AGENT, 'Accept-Language': 'es-PE' } });
      const html = await response.text();
      const match = html.match(/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s);
      if (!match) return [];
      return (JSON.parse(match[1]).props.pageProps.results || []).slice(0, 12).map((result) => ({
        name: result.displayName,
        url: result.url,
        seller: result.sellerName,
        prices: Object.fromEntries((result.prices || []).map((price) => [price.type, toNumber(price.price[0])])),
      }));
    } catch {
      await sleep(2000);
    }
  }
  return [];
}

function candidates(product, listings) {
  const words = normalize(product.name.replace(/\(.*?\)/g, '')).trim().split(' ').filter(Boolean);
  const brand = normalize(product.brand).trim();
  const generic = new Set([...GENERIC_WORDS, brand, ...brand.split(' ')]);
  const required = words.filter((word) => /\d/.test(word) || REQUIRED_WORDS.includes(word));
  const model = words.filter((word) => !/\d/.test(word) && word.length > 1 && !generic.has(word) && !required.includes(word));
  const mpn = product.mpn && compact(product.mpn).length >= 6 ? compact(product.mpn) : null;

  return listings.filter((listing) => {
    const name = normalize(listing.name);
    if (NOT_A_PART.some((word) => name.includes(word))) return false;
    if (EXCLUDE[product.key]?.test(listing.name)) return false;
    if (mpn && compact(listing.name).includes(mpn)) return true;
    if (!required.every((word) => name.includes(` ${word} `) || (word === 'white' && /blanc/.test(name)))) return false;
    if (model.length && model.filter((word) => name.includes(` ${word} `)).length < Math.ceil(model.length / 2)) return false;
    const isWhite = words.includes('white');
    return !VARIANT_WORDS.some((word) => name.includes(` ${word} `) && !words.includes(word) && !(isWhite && ['blanco', 'blanca', 'wh'].includes(word)));
  });
}

async function priceFor(product) {
  const queries = [product.name.replace(/\(.*?\)/g, '').trim(), product.mpn].filter(Boolean);
  const listings = new Map();
  for (const query of queries) {
    for (const listing of await search(query)) listings.set(listing.url, listing);
    await sleep(800);
  }
  const offers = candidates(product, [...listings.values()])
    .map((listing) => ({ ...listing, offer: listing.prices.eventPrice ?? listing.prices.internetPrice ?? listing.prices.normalPrice }))
    .filter((listing) => listing.offer > 0);
  if (!offers.length) return null;
  const pen = median(offers.map((listing) => listing.offer));
  const reference = offers.find((listing) => listing.offer === pen);
  return {
    pen,
    source: SOURCE,
    checked_at: new Date().toISOString().slice(0, 10),
    offers: offers.length,
    min: Math.min(...offers.map((listing) => listing.offer)),
    max: Math.max(...offers.map((listing) => listing.offer)),
    url: reference.url,
    seller: reference.seller,
  };
}

async function main() {
  const args = process.argv.slice(2);
  const only = (args.find((arg) => arg.startsWith('--only=')) || '').slice(7).split(',').filter(Boolean);
  const dryRun = args.includes('--dry-run');
  const catalog = fs.readdirSync(DATA_DIR).filter((file) => file.endsWith('.json'))
    .flatMap((file) => JSON.parse(fs.readFileSync(path.join(DATA_DIR, file), 'utf8')))
    .filter((product) => !only.length || only.includes(product.key));
  const current = fs.existsSync(PRICES_FILE) ? JSON.parse(fs.readFileSync(PRICES_FILE, 'utf8')) : { products: {} };
  const products = { ...current.products };

  const queue = [...catalog];
  await Promise.all(Array.from({ length: CONCURRENCY }, async () => {
    for (let product = queue.shift(); product; product = queue.shift()) {
      const price = await priceFor(product);
      const previous = products[product.key];
      if (price) products[product.key] = price;
      else if (previous?.source === SOURCE) delete products[product.key];
      const kept = previous && previous.source !== SOURCE ? `se conserva ${previous.source}` : 'sin precio';
      console.log(`${product.key}: ${price ? `S/ ${price.pen} (${price.offers} ofertas)` : kept}`);
    }
  }));

  const sorted = Object.fromEntries(Object.entries(products).sort(([a], [b]) => a.localeCompare(b)));
  const missing = catalog.filter((product) => !sorted[product.key]).length;
  console.log(`\n${Object.keys(sorted).length} con precio, ${missing} sin precio.`);
  if (!dryRun) fs.writeFileSync(PRICES_FILE, JSON.stringify({ currency: 'PEN', includes_tax: true, products: sorted }, null, 2) + '\n');
}

main().catch((error) => { console.error(error); process.exit(1); });
