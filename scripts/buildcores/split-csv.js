#!/usr/bin/env node

/**
 * NexoPC - Divisor de CSVs para WooCommerce
 * Divide archivos CSV grandes en partes menores a 2 MB
 * manteniendo los encabezados en cada parte.
 */

const fs = require('fs');
const path = require('path');

const outputDir = path.join(__dirname, 'output');
const chunksDir = path.join(outputDir, 'chunks-under-2mb');

if (!fs.existsSync(chunksDir)) {
  fs.mkdirSync(chunksDir, { recursive: true });
}

// Tamaño máximo aproximado por parte (1.5 MB para estar muy por debajo de los 2 MB)
const MAX_BYTES_PER_CHUNK = 1.5 * 1024 * 1024;

const filesToSplit = fs.readdirSync(outputDir).filter(f => f.endsWith('.csv') && !f.includes('-part'));

console.log('✂️  NexoPC CSV Splitter (límite < 2 MB)\n');

for (const file of filesToSplit) {
  const filePath = path.join(outputDir, file);
  const stats = fs.statSync(filePath);

  if (stats.size < 1.8 * 1024 * 1024) {
    // Si ya pesa menos de 1.8 MB, solo lo copiamos directamente
    const target = path.join(chunksDir, file);
    fs.copyFileSync(filePath, target);
    console.log(`✅ ${file} (${(stats.size / 1024).toFixed(0)} KB) -> Ya pesa menos de 2 MB (copiado directo)`);
    continue;
  }

  console.log(`📦 Dividiendo ${file} (${(stats.size / (1024 * 1024)).toFixed(2)} MB)...`);

  const content = fs.readFileSync(filePath, 'utf8');
  const lines = content.split('\n');
  if (lines.length <= 1) continue;

  const header = lines[0];
  const dataLines = lines.slice(1).filter(l => l.trim().length > 0);

  let currentChunkLines = [];
  let currentChunkSize = Buffer.byteLength(header, 'utf8') + 1;
  let partIndex = 1;
  const baseName = file.replace('.csv', '');

  for (const line of dataLines) {
    const lineSize = Buffer.byteLength(line, 'utf8') + 1;

    if (currentChunkSize + lineSize > MAX_BYTES_PER_CHUNK && currentChunkLines.length > 0) {
      const chunkFileName = `${baseName}-part${partIndex}.csv`;
      const chunkFilePath = path.join(chunksDir, chunkFileName);
      const chunkContent = [header, ...currentChunkLines].join('\n') + '\n';

      fs.writeFileSync(chunkFilePath, chunkContent, 'utf8');
      const sizeKB = (Buffer.byteLength(chunkContent, 'utf8') / 1024).toFixed(0);
      console.log(`   📄 Creado: ${chunkFileName} (${sizeKB} KB - ${currentChunkLines.length} productos)`);

      partIndex++;
      currentChunkLines = [line];
      currentChunkSize = Buffer.byteLength(header, 'utf8') + 1 + lineSize;
    } else {
      currentChunkLines.push(line);
      currentChunkSize += lineSize;
    }
  }

  if (currentChunkLines.length > 0) {
    const chunkFileName = `${baseName}-part${partIndex}.csv`;
    const chunkFilePath = path.join(chunksDir, chunkFileName);
    const chunkContent = [header, ...currentChunkLines].join('\n') + '\n';

    fs.writeFileSync(chunkFilePath, chunkContent, 'utf8');
    const sizeKB = (Buffer.byteLength(chunkContent, 'utf8') / 1024).toFixed(0);
    console.log(`   📄 Creado: ${chunkFileName} (${sizeKB} KB - ${currentChunkLines.length} productos)`);
  }
}

console.log(`\n🎉 Todos los archivos se guardaron en:\n   ${chunksDir}`);
console.log('   ¡Todos pesan menos de 1.5 MB y están listos para subir a WooCommerce!');
