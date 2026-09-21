<?php
/**
 * Plugin Name: NexoPC CORS
 * Description: Habilita CORS para el frontend headless de NexoPC en localhost:3000.
 * Version: 1.0.0
 * Author: Equipo NexoPC
 * Author URI: https://github.com/TU-USUARIO/nexopc-backend
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Habilita CORS para el frontend headless
 */
add_action('init', function () {
    // Origen permitido (frontend Next.js)
    header("Access-Control-Allow-Origin: http://localhost:3000");
    
    // Métodos HTTP permitidos
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    
    // Headers permitidos
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    
    // Permitir credenciales (cookies, JWT)
    header("Access-Control-Allow-Credentials: true");
    
    // Manejar preflight requests (OPTIONS)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        status_header(200);
        exit();
    }
}, 15); // Prioridad 15 para ejecutarse después de los plugins principales