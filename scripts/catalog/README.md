# Catálogo curado de NexoPC

Productos reales seleccionados por presencia en el mercado peruano. Cada ficha
técnica sale de la página oficial del fabricante o de Open Icecat, y conserva sus
URLs en `sources`.

No uses BuildCores OpenDB como fuente: su licencia es CC BY-NC-SA y NexoPC es una
actividad comercial. Tampoco copies datos ni imágenes de Amazon o de otras tiendas.

## Datos

`data/*.json` contiene un arreglo por categoría. Si un dato no está confirmado va
en `null`, con `verified: false` y el motivo en `notes`. Nunca se estima: el armador
trata un dato ausente como aviso, pero un dato inventado da compatibilidades falsas.

## Precios

`prices.json` guarda un precio de referencia en soles con IGV por `key`, separado
de las fichas porque cambia mucho más seguido. Sale de `falabella.com.pe`: la
mediana de las ofertas vigentes del mismo modelo, sin precio CMR ni precio
tachado. Solo se guardan el número, el vendedor y la URL, nunca textos ni
imágenes de la tienda.

```bash
node scripts/catalog/update-prices.js            # todo el catálogo
node scripts/catalog/update-prices.js --only=cpu-ryzen-5-7600 --dry-run
```

El script descarta otras variantes (color, Ti/XT, ARGB, kits de PC armada) y los
listados de `EXCLUDE`, revisados a mano. Cuando Falabella no tiene un producto se
conserva el precio cargado a mano desde otra tienda peruana, con su `source`.
Revisa el resultado antes de importarlo: es una referencia de mercado, no el
costo del proveedor.

Sin `--prices`, el importador no toca precio ni stock de productos existentes, así
que no borra lo cargado desde el panel. Un producto nuevo sin precio queda
publicado como "Precio por confirmar" y WooCommerce no permite comprarlo.

## Importar

```bash
node scripts/catalog/validate.js

docker cp scripts/catalog/data <contenedor>:/tmp/nexopc-catalog
docker cp scripts/catalog/images <contenedor>:/tmp/images
docker cp scripts/catalog/import-catalog.php <contenedor>:/tmp/import-catalog.php
docker cp scripts/catalog/prices.json <contenedor>:/tmp/nexopc-prices.json

# Simulación
docker exec -u www-data <contenedor> php /tmp/import-catalog.php /tmp/nexopc-catalog

# Aplicar y traer imágenes
docker exec -u www-data <contenedor> php /tmp/import-catalog.php /tmp/nexopc-catalog --apply --with-images

# Aplicar también los precios de referencia
docker exec -u www-data <contenedor> php /tmp/import-catalog.php /tmp/nexopc-catalog --apply --prices=/tmp/nexopc-prices.json
```

Repetir la importación actualiza por SKU. Los productos con `verified: false` quedan
en borrador hasta completar sus datos.

`--retire-old` pasa a borrador todo lo que no pertenece al catálogo curado, y
`--restore-old` lo devuelve a publicado. Ninguno borra productos. Haz un respaldo
antes de usarlos.

Las imágenes salen de Open Icecat o de la página del fabricante. Cuando el CDN del
fabricante no entrega el archivo a descargas automáticas (AMD), la imagen oficial
va en `images/<key>.jpg|png|webp` y el importador la usa antes que `image_url`.
Copia también esa carpeta al contenedor junto a `data`.

Si los datos vienen de Open Icecat, la ficha debe mantener la mención
`Specs Icecat`, que el importador añade a la descripción corta.
