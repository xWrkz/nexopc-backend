# NexoPC Backend (WordPress + WooCommerce + GraphQL)

Backend headless de NexoPC. Expone la API GraphQL para que el frontend Next.js pueda consumir productos, pedidos y usuarios.

## 🛠️ Stack Tecnológico

| Tecnología | Versión | Propósito |
|------------|---------|-----------|
| WordPress | 7.1.1 | CMS base |
| WooCommerce | 11.1.1 | E-commerce |
| WPGraphQL | 2.23.0 | API GraphQL |
| WooGraphQL | 0.21.2 | Extensión GraphQL para WooCommerce |
| JWT Authentication | 1.5.0 | Autenticación de usuarios |
| PHP | 8.2.29 | Lenguaje backend |
| MySQL | 8.4.0 | Base de datos |

## 📋 Requisitos previos

- **LocalWP** instalado (descarga desde https://localwp.com)
- **Node.js** 20+ (para el frontend)
- **Git**

## 🚀 Instalación paso a paso

### 1. Instalar LocalWP y crear el sitio

1. Descarga e instala LocalWP.
2. Crea un nuevo sitio:
   - **Nombre:** `nexopc-local`
   - **Dominio:** `nexopc-local.local`
   - **Web server:** nginx
   - **PHP:** 8.2.x
   - **Database:** MySQL 8.x
   - **WordPress:** Última versión
   - **Usuario:** `admin`
   - **Contraseña:** (la que definas)

### 2. Instalar WordPress y WooCommerce

1. Accede a `http://nexopc-local.local/wp-admin`.
2. Sigue el asistente de instalación.
3. Ve a **Plugins → Añadir nuevo** → busca **WooCommerce** → Instala y activa.
4. Configura WooCommerce:
   - **País:** Perú — La Libertad
   - **Moneda:** Soles (S/)
   - **Tipo de producto:** Electrónica y ordenadores

### 3. Instalar los plugins de GraphQL

Instala y activa **en este orden**:

| # | Plugin | Cómo instalarlo | Versión |
|---|--------|-----------------|---------|
| 1 | **WPGraphQL** | Plugins → Añadir nuevo → buscar "WPGraphQL" | 2.23.0+ |
| 2 | **WPGraphQL for WooCommerce (WooGraphQL)** | Descargar desde https://github.com/wp-graphql/wp-graphql-woocommerce/releases → Subir plugin | 0.21.2+ |
| 3 | **JWT Authentication for WP REST API** | Plugins → Añadir nuevo → buscar "JWT Authentication" | 1.5.0+ |

> ⚠️ **NO instalar "GraphQL for eCommerce"** (plugin antiguo, incompatible).

### 4. Configurar HPOS (CRÍTICO)

**WooGraphQL NO funciona con HPOS activo.** Debes desactivarlo:

1. Ve a **WooCommerce → Ajustes → Avanzado → Características**.
2. En **"Almacenamiento de datos de pedidos"**, selecciona:
   - ✅ **"Almacenamiento de entradas de WordPress (heredado)"**
   - ❌ Desmarca **"Almacenamiento de pedidos de alto rendimiento (HPOS)"**
3. Desmarca **"Activar el modo de compatibilidad"**.
4. Guarda.

### 5. Configurar JWT Authentication

1. Ve a **Ajustes → JWT Authentication**.
2. Haz clic en **"Generate New Key"**.
3. Copia el código generado.
4. Abre `wp-config.php` y pega el código **antes** de `/* That's all, stop editing! */`:

```php
define('JWT_AUTH_SECRET_KEY', 'tu-clave-generada-aqui');
define('JWT_AUTH_CORS_ENABLE', true);