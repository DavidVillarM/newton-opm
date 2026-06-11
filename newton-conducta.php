<?php
/**
 * Plugin Name: Newton Conducta
 * Description: Sistema de evaluación de conducta para Newton (alumnos, evaluaciones).
 * Version: 0.1.0
 * Author: Newton
 */

if (!defined('ABSPATH')) exit;

define('NC_VERSION', '0.1.0');
define('NC_PATH', plugin_dir_path(__FILE__));
define('NC_URL', plugin_dir_url(__FILE__));

require_once NC_PATH . 'includes/class-nc-roles.php';
require_once NC_PATH . 'includes/class-nc-db.php';
require_once NC_PATH . 'includes/class-nc-asistencia-db.php';
require_once NC_PATH . 'includes/class-nc-examenes-db.php';
require_once NC_PATH . 'includes/class-nc-examenes-import.php';
require_once NC_PATH . 'includes/class-nc-rest.php';
require_once NC_PATH . 'includes/class-nc-rest-asistencia.php';
require_once NC_PATH . 'includes/class-nc-rest-examenes.php';
require_once NC_PATH . 'includes/class-nc-shortcode.php';

register_activation_hook(__FILE__, function () {
  NC_DB::activate();
  NC_Asistencia_DB::ensure_schema();
  update_option('nc_asistencia_schema_version', NC_Asistencia_DB::schema_version(), false);
  NC_Examenes_DB::ensure_schema();
  update_option('nc_examenes_schema_version', NC_Examenes_DB::schema_version(), false);
});

// Migraciones de esquema: solo cuando cambia la versión (no dbDelta en cada página).
add_action('plugins_loaded', function () {
  try {
    NC_DB::maybe_upgrade();
    NC_Asistencia_DB::maybe_upgrade();
    NC_Examenes_DB::maybe_upgrade();
  } catch (Throwable $e) {
    error_log('[NC] schema upgrade: ' . $e->getMessage());
  }
}, 5);

// Evitar que caches (Hostinger/LiteSpeed/Cloudflare, etc.) sirvan respuestas viejas del REST.
// Esto explica el comportamiento de "recién aparece en unos minutos".
add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
  if (!headers_sent()) {
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
  return $served;
}, 10, 4);

add_action('init', function () {
  NC_Roles::init();
  NC_Shortcode::init();
});

add_action('rest_api_init', function () {
  NC_Rest::init();
});
