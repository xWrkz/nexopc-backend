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

Los productos se cargan sin precio, así que WooCommerce no permite comprarlos.

## Importar

```bash
node scripts/catalog/validate.js

docker cp scripts/catalog/data <contenedor>:/tmp/nexopc-catalog
docker cp scripts/catalog/import-catalog.php <contenedor>:/tmp/import-catalog.php

# Simulación
docker exec -u www-data <contenedor> php /tmp/import-catalog.php /tmp/nexopc-catalog

# Aplicar y traer imágenes
docker exec -u www-data <contenedor> php /tmp/import-catalog.php /tmp/nexopc-catalog --apply --with-images
```

Repetir la importación actualiza por SKU. Los productos con `verified: false` quedan
en borrador hasta completar sus datos.

`--retire-old` pasa a borrador todo lo que no pertenece al catálogo curado, y
`--restore-old` lo devuelve a publicado. Ninguno borra productos. Haz un respaldo
antes de usarlos.

Si los datos vienen de Open Icecat, la ficha debe mantener la mención
`Specs Icecat`, que el importador añade a la descripción corta.
