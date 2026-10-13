#!/usr/bin/env node

/**
 * NexoPC - Importador de BuildCores Open DB
 * Genera archivos CSV compatibles con WooCommerce y WP All Import
 * a partir de la base de datos abierta BuildCores Open DB.
 */

const fs = require('fs');
const path = require('path');
const { program } = require('commander');
const { createObjectCsvWriter } = require('csv-writer');
const { CSV_HEADERS } = require('./utils');

// Cargar mapeadores por categoría
const mappings = {
  CPU: require('./mappings/cpu-mapping'),
  GPU: require('./mappings/gpu-mapping'),
  Motherboard: require('./mappings/motherboard-mapping'),
  RAM: require('./mappings/ram-mapping'),
  Storage: require('./mappings/storage-mapping'),
  PSU: require('./mappings/psu-mapping'),
  Case: require('./mappings/case-mapping')
};

// Configurar CLI con commander
program
  .name('import-buildcores')
  .description('Importador de BuildCores Open DB a CSV para WooCommerce (NexoPC)')
  .version('1.0.0')
  .option('-c, --categories <categories>', 'Categorías a procesar separadas por coma (ej: CPU,GPU,RAM)', 'CPU,GPU,Motherboard,RAM,Storage,PSU,Case')
  .option('-d, --data-dir <path>', 'Directorio de datos de BuildCores', path.join(__dirname, 'data'))
  .option('-o, --output-dir <path>', 'Directorio de salida para CSVs y logs', path.join(__dirname, 'output'))
  .option('-l, --limit <number>', 'Límite máximo de productos por categoría', parseInt)
  .option('-p, --base-price <number>', 'Precio base regular por defecto en WooCommerce', 0)
  .option('-t, --tier <tier>', 'Filtro de gama: all | mid-high', 'all')
  .option('--min-tdp <number>', 'Filtro: TDP mínimo en watts', parseInt)
  .option('--min-gpu-vram <number>', 'Filtro: VRAM mínima en GB para GPUs', parseInt)
  .option('--min-ram-capacity <number>', 'Filtro: Capacidad mínima en GB para RAM', parseInt)
  .option('-u, --unified', 'Genera también un archivo CSV unificado con todos los productos', false)
  .parse(process.argv);

const options = program.opts();

/**
 * Sistema de Logging con archivo diario
 */
class Logger {
  constructor(outputDir) {
    this.outputDir = outputDir;
    if (!fs.existsSync(outputDir)) {
      fs.mkdirSync(outputDir, { recursive: true });
    }
    const today = new Date().toISOString().slice(0, 10);
    this.logFile = path.join(outputDir, `import-${today}.log`);
    this.stream = fs.createWriteStream(this.logFile, { flags: 'a', encoding: 'utf8' });
  }

  log(level, message) {
    const timestamp = new Date().toISOString();
    const entry = `[${timestamp}] [${level.toUpperCase()}] ${message}\n`;
    this.stream.write(entry);
  }

  info(message) { this.log('INFO', message); }
  warn(message) { this.log('WARN', message); }
  error(message) { this.log('ERROR', message); }

  close() {
    this.stream.end();
  }
}

/**
 * Localiza el directorio físico de una categoría dentro del directorio de datos.
 * Soporta tanto `data/CPU` como `data/open-db/CPU` o variantes de nombres.
 */
function findCategoryDir(baseDataDir, candidateNames) {
  const possibleRoots = [
    baseDataDir,
    path.join(baseDataDir, 'open-db'),
    path.join(baseDataDir, 'buildcores-open-db'),
    path.join(baseDataDir, 'buildcores-open-db', 'open-db')
  ];

  for (const root of possibleRoots) {
    if (!fs.existsSync(root)) continue;

    for (const name of candidateNames) {
      const fullPath = path.join(root, name);
      if (fs.existsSync(fullPath) && fs.statSync(fullPath).isDirectory()) {
        return fullPath;
      }
    }
  }

  return null;
}

/**
 * Colector de valores únicos de atributos
 */
class AttributeCollector {
  constructor() {
    this.values = {
      'socket': new Set(),
      'tipo-ram': new Set(),
      'tdp': new Set(),
      'formato': new Set(),
      'longitud-gpu': new Set(),
      'vatios': new Set(),
      'tipo-almacenamiento': new Set(),
      'capacidad-gb': new Set(),
      'marca': new Set()
    };
  }

  add(row) {
    if (row.attr_socket) this.values['socket'].add(row.attr_socket);
    if (row.attr_tipo_ram) this.values['tipo-ram'].add(row.attr_tipo_ram);
    if (row.attr_tdp) this.values['tdp'].add(Number(row.attr_tdp));
    if (row.attr_formato) this.values['formato'].add(row.attr_formato);
    if (row.attr_longitud_gpu) this.values['longitud-gpu'].add(Number(row.attr_longitud_gpu));
    if (row.attr_vatios) this.values['vatios'].add(Number(row.attr_vatios));
    if (row.attr_tipo_almacenamiento) this.values['tipo-almacenamiento'].add(row.attr_tipo_almacenamiento);
    if (row.attr_capacidad_gb) this.values['capacidad-gb'].add(Number(row.attr_capacidad_gb));
    if (row.attr_marca) this.values['marca'].add(row.attr_marca);
  }

  toJSON() {
    const sortNumeric = arr => arr.filter(n => !Number.isNaN(n)).sort((a, b) => a - b);
    const sortAlpha = arr => arr.filter(Boolean).sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));

    return {
      'socket': sortAlpha(Array.from(this.values['socket'])),
      'tipo-ram': sortAlpha(Array.from(this.values['tipo-ram'])),
      'formato': sortAlpha(Array.from(this.values['formato'])),
      'tipo-almacenamiento': sortAlpha(Array.from(this.values['tipo-almacenamiento'])),
      'tdp': sortNumeric(Array.from(this.values['tdp'])),
      'vatios': sortNumeric(Array.from(this.values['vatios'])),
      'longitud-gpu': sortNumeric(Array.from(this.values['longitud-gpu'])),
      'capacidad-gb': sortNumeric(Array.from(this.values['capacidad-gb'])),
      'marca': sortAlpha(Array.from(this.values['marca']))
    };
  }
}

async function run() {
  const startTime = Date.now();
  const requestedCategories = options.categories.split(',').map(c => c.trim()).filter(Boolean);

  const dataDir = path.resolve(options.dataDir);
  const outputDir = path.resolve(options.outputDir);

  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const logger = new Logger(outputDir);
  const attributeCollector = new AttributeCollector();

  logger.info(`Iniciando importador NexoPC BuildCores v1.0.0`);
  logger.info(`Directorio de entrada: ${dataDir}`);
  logger.info(`Directorio de salida: ${outputDir}`);
  logger.info(`Categorías solicitadas: ${requestedCategories.join(', ')}`);
  logger.info(`Filtro tier: ${options.tier}`);

  console.log('🚀 NexoPC BuildCores Importer v1.0.0');
  console.log(`📁 Leyendo datos desde: ${dataDir}`);

  let totalProcessed = 0;
  let totalSkipped = 0;
  let totalErrors = 0;
  const allRows = [];
  const generatedCsvFiles = [];

  for (const catKey of requestedCategories) {
    const mapping = mappings[catKey];
    if (!mapping) {
      console.log(`⚠️ Categoría no reconocida: "${catKey}" (se omite)`);
      logger.warn(`Categoría no reconocida: "${catKey}"`);
      continue;
    }

    console.log(`📂 Procesando categoría: ${catKey}`);
    logger.info(`Procesando categoría: ${catKey}`);

    const categoryDir = findCategoryDir(dataDir, mapping.folderNames);

    if (!categoryDir) {
      console.log(`   ⚠️ Carpeta no encontrada para ${catKey} en ${dataDir}`);
      logger.warn(`No se encontró carpeta de datos para categoría ${catKey}`);
      continue;
    }

    const files = fs.readdirSync(categoryDir).filter(f => f.toLowerCase().endsWith('.json'));

    let processedCount = 0;
    let skippedCount = 0;
    let errorCount = 0;
    const categoryRows = [];

    const limit = options.limit ? Math.min(options.limit, files.length) : files.length;

    for (let i = 0; i < files.length; i++) {
      if (options.limit && processedCount >= options.limit) break;

      const filename = files[i];
      const filePath = path.join(categoryDir, filename);

      let rawContent;
      let json;
      try {
        rawContent = fs.readFileSync(filePath, 'utf8');
        json = JSON.parse(rawContent);
      } catch (err) {
        errorCount++;
        totalErrors++;
        logger.error(`[${catKey}] Error leyendo archivo corrupto ${filename}: ${err.message}`);
        continue;
      }

      // Validar schema del archivo
      const validation = mapping.validate(json);
      if (!validation.valid) {
        skippedCount++;
        totalSkipped++;
        logger.warn(`[${catKey}] Omitido ${filename}: ${validation.reason}`);
        continue;
      }

      // Aplicar filtros configurables
      if (!mapping.filter(json, options)) {
        skippedCount++;
        totalSkipped++;
        logger.info(`[${catKey}] Filtrado por criterios ${filename} (ID: ${json.opendb_id})`);
        continue;
      }

      // Mapear al modelo de WooCommerce
      try {
        const row = mapping.map(json, options);
        categoryRows.push(row);
        allRows.push(row);
        attributeCollector.add(row);
        processedCount++;
        totalProcessed++;
      } catch (err) {
        errorCount++;
        totalErrors++;
        logger.error(`[${catKey}] Error transformando ${filename}: ${err.message}`);
      }
    }

    // Escribir CSV específico de la categoría
    if (categoryRows.length > 0) {
      const csvFileName = `${catKey.toLowerCase()}-products.csv`;
      const csvFilePath = path.join(outputDir, csvFileName);

      const csvWriter = createObjectCsvWriter({
        path: csvFilePath,
        header: CSV_HEADERS,
        encoding: 'utf8'
      });

      await csvWriter.writeRecords(categoryRows);
      generatedCsvFiles.push(csvFilePath);
      logger.info(`Generado CSV para ${catKey}: ${csvFilePath} (${categoryRows.length} filas)`);
    }

    console.log(`   ✅ ${processedCount} productos procesados`);
    console.log(`   ⚠️  ${skippedCount} productos omitidos (campos faltantes o filtros)`);
    console.log(`   ❌ ${errorCount} errores`);
  }

  // Si se solicita CSV unificado
  if (options.unified && allRows.length > 0) {
    const unifiedPath = path.join(outputDir, 'all-products.csv');
    const unifiedWriter = createObjectCsvWriter({
      path: unifiedPath,
      header: CSV_HEADERS,
      encoding: 'utf8'
    });
    await unifiedWriter.writeRecords(allRows);
    generatedCsvFiles.push(unifiedPath);
    logger.info(`Generado CSV unificado: ${unifiedPath} (${allRows.length} productos)`);
  }

  // Guardar archivo de valores únicos de atributos
  const attributeValuesPath = path.join(outputDir, 'attribute-values.json');
  fs.writeFileSync(attributeValuesPath, JSON.stringify(attributeCollector.toJSON(), null, 2), 'utf8');
  logger.info(`Guardado diccionario de atributos en ${attributeValuesPath}`);

  const durationSec = ((Date.now() - startTime) / 1000).toFixed(1);

  logger.info(`Proceso finalizado. Total: ${totalProcessed}, Omitidos: ${totalSkipped}, Errores: ${totalErrors}`);
  logger.close();

  console.log('\n📊 Resumen:');
  console.log(`   - Total procesados: ${totalProcessed}`);
  console.log(`   - Omitidos: ${totalSkipped}`);
  console.log(`   - Errores: ${totalErrors}`);
  console.log(`   - CSVs generados en: ${outputDir}`);
  console.log(`   - Valores únicos de atributos: ${attributeValuesPath}`);
  console.log(`\n✨ Proceso completado en ${durationSec} segundos`);
}

run().catch(err => {
  console.error('❌ Error fatal en la ejecución:', err);
  process.exit(1);
});
