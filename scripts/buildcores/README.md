# 🚀 NexoPC BuildCores Importer

Módulo CLI para transformar y exportar componentes de hardware de la base de datos abierta **[BuildCores Open DB](https://github.com/buildcores/buildcores-open-db)** a archivos CSV compatibles con **WooCommerce** y **WP All Import**.

Diseñado específicamente para el ecosistema **NexoPC**, garantizando que todos los atributos técnicos necesarios para el **Configurador Inteligente de Compatibilidad** (Socket, Tipo de RAM, TDP, Formato, Longitud de GPU, Vatios, Capacidad) queden estandarizados y vinculados.

---

## 📁 Estructura del Módulo

```
scripts/buildcores/
├── data/                         # Base de datos clonada de BuildCores (git-ignored)
│   ├── CPU/
│   ├── GPU/
│   ├── Motherboard/
│   ├── RAM/
│   ├── Storage/
│   ├── PSU/
│   └── Case/
├── mappings/                     # Reglas de validación, filtrado y transformación
│   ├── cpu-mapping.js
│   ├── gpu-mapping.js
│   ├── motherboard-mapping.js
│   ├── ram-mapping.js
│   ├── storage-mapping.js
│   ├── psu-mapping.js
│   └── case-mapping.js
├── output/                       # Resultados generados
│   ├── attribute-values.json     # Diccionario de términos únicos para WooCommerce
│   ├── *-products.csv            # CSVs por categoría
│   ├── all-products.csv          # CSV unificado (opcional)
│   └── import-YYYY-MM-DD.log     # Registro detallado de auditoría
├── import-buildcores.js          # Script ejecutable principal
├── utils.js                      # Utilidades compartidas y columnas CSV
├── package.json
└── README.md
```

---

## 🛠️ Instalación y Requisitos

1. **Requisitos:**
   - Node.js versión 18 o superior.
   - Acceso a Git para clonar el repositorio de BuildCores.

2. **Instalar dependencias:**
   ```bash
   cd scripts/buildcores
   npm install
   ```

3. **Obtener los datos de BuildCores:**
   ```bash
   # Dentro de scripts/buildcores/
   git clone https://github.com/buildcores/buildcores-open-db.git data
   ```
   *(Si el clon se hace con carpeta raíz `open-db/`, el script detecta automáticamente las rutas).*

---

## 🚀 Uso del Script

### 1. Ejecutar importación completa
```bash
node import-buildcores.js
```

### 2. Ejecutar con CSV unificado y límite por categoría
```bash
node import-buildcores.js --unified --limit=50
```

### 3. Filtrar categorías específicas
```bash
node import-buildcores.js --categories=CPU,GPU,Motherboard,RAM
```

### 4. Filtrar por gama media-alta / gaming
```bash
node import-buildcores.js --tier=mid-high
```

### 5. Configurar precio base por defecto
```bash
node import-buildcores.js --base-price=100
```

---

## ⚙️ Opciones CLI de `import-buildcores.js`

| Opción | Alias | Tipo | Por defecto | Descripción |
|---|---|---|---|---|
| `--categories` | `-c` | string | `CPU,GPU,Motherboard,RAM,Storage,PSU,Case` | Categorías a procesar separadas por coma |
| `--data-dir` | `-d` | string | `./data` | Ruta al directorio con datos JSON de BuildCores |
| `--output-dir` | `-o` | string | `./output` | Ruta de salida para CSVs y logs |
| `--limit` | `-l` | number | *ilimitado* | Límite máximo de productos a procesar por categoría |
| `--base-price` | `-p` | number | `0` | Precio regular base para WooCommerce |
| `--tier` | `-t` | string | `all` | Filtro de gama (`all` o `mid-high`) |
| `--min-tdp` | | number | *ninguno* | Filtra CPUs o GPUs con TDP mínimo en watts |
| `--min-gpu-vram` | | number | *ninguno* | Filtra GPUs con VRAM mínima en GB |
| `--min-ram-capacity` | | number | *ninguno* | Filtra kits de RAM con capacidad mínima en GB |
| `--unified` | `-u` | boolean | `false` | Genera además el archivo `all-products.csv` |

---

## 📊 Salidas Generadas

1. **CSVs por categoría (`output/*-products.csv`):**
   - Encabezados nativos de WooCommerce y WP All Import:
     `Name, SKU, Regular price, Sale price, Categories, Tags, Images, Description, Attribute: socket, Attribute: tipo-ram, Attribute: tdp, Attribute: formato, Attribute: longitud-gpu, Attribute: vatios, Attribute: tipo-almacenamiento, Attribute: capacidad-gb, Attribute: marca, Meta: opendb_id, Meta: _opendb_id, Meta: manufacturer`
2. **Diccionario de términos (`output/attribute-values.json`):**
   - Lista organizada y ordenada con los valores únicos de cada atributo, facilitando la creación previa de términos en WooCommerce.
3. **Logs de auditoría (`output/import-YYYY-MM-DD.log`):**
   - Detalle de cada archivo omitido por validación o error de sintaxis, con timestamps ISO.
