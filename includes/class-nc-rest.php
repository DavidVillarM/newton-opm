<?php
if (!defined('ABSPATH')) exit;

class NC_Rest {

  // Cache simple de columnas existentes por tabla (compatibilidad con esquemas viejos)
  private static array $col_cache = [];

  private static function table_has_column(string $table, string $col): bool {
    $key = $table . '|' . $col;
    if (array_key_exists($key, self::$col_cache)) return (bool) self::$col_cache[$key];
    global $wpdb;
    $sql = $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col);
    $exists = (bool) $wpdb->get_var($sql);
    self::$col_cache[$key] = $exists;
    return $exists;
  }

  public static function init() {
    // Se llama desde rest_api_init en el plugin principal.
    // Por eso registramos las rutas directamente acá.
    self::register_routes();
  }

  public static function can_access() {
    return NC_Roles::user_can_access();
  }

  /** Solo administradores (no Docente ni Funcionario de Oficina) pueden gestionar la config de subgrupos. */
  public static function can_manage_subgrupos_config() {
    return NC_Roles::user_is_admin();
  }

  public static function register_routes() {
    $ns = 'conducta/v1';

    // -------- Info del usuario actual --------
    register_rest_route($ns, '/user/permissions', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [__CLASS__, 'get_user_permissions'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Facultades --------
    register_rest_route($ns, '/facultades', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_facultades'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_facultad'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    register_rest_route($ns, '/facultades/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_facultad'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_facultad'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Carreras --------
    register_rest_route($ns, '/carreras', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_carreras'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_carrera'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    register_rest_route($ns, '/carreras/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_carrera'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_carrera'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Cursos --------
    register_rest_route($ns, '/cursos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_cursos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_curso'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    register_rest_route($ns, '/cursos/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_curso'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_curso'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Aulas --------
    register_rest_route($ns, '/aulas', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_aulas'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_aula'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    register_rest_route($ns, '/aulas/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_aula'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_aula'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Configuración de subgrupos (curso/carrera) - solo admin --------
    register_rest_route($ns, '/subgrupos-config', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_subgrupos_config'],
        'permission_callback' => [__CLASS__, 'can_manage_subgrupos_config'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_subgrupos_config'],
        'permission_callback' => [__CLASS__, 'can_manage_subgrupos_config'],
      ],
    ]);
    register_rest_route($ns, '/subgrupos-config/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_subgrupos_config'],
        'permission_callback' => [__CLASS__, 'can_manage_subgrupos_config'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_subgrupos_config'],
        'permission_callback' => [__CLASS__, 'can_manage_subgrupos_config'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_subgrupos_config'],
        'permission_callback' => [__CLASS__, 'can_manage_subgrupos_config'],
      ],
    ]);

    // -------- Alumnos --------
    register_rest_route($ns, '/alumnos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_alumnos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_alumno'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/alumnos/subgrupos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_alumnos_subgrupos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    register_rest_route($ns, '/alumnos/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_alumno'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_alumno'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_alumno'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Eliminación masiva de alumnos --------
    register_rest_route($ns, '/alumnos/bulk-delete', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_delete_alumnos'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Asignación masiva de curso a alumnos --------
    register_rest_route($ns, '/alumnos/bulk-curso', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_update_alumnos_curso'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Asignación masiva de subgrupo a alumnos --------
    register_rest_route($ns, '/alumnos/bulk-subgrupo', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_update_alumnos_subgrupo'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Agregar masivamente alumno a un grupo (aula) --------
    register_rest_route($ns, '/alumnos/bulk-aula-add', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_add_alumnos_aula'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Desvincular masivamente alumnos de un grupo (aula) --------
    register_rest_route($ns, '/alumnos/bulk-aula-remove', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_remove_alumnos_aula'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Asignación masiva de facultad/carrera a alumnos --------
    register_rest_route($ns, '/alumnos/bulk-facultad-carrera', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [__CLASS__, 'bulk_update_alumnos_facultad_carrera'],
      'permission_callback' => [__CLASS__, 'can_access'],
    ]);

    // -------- Evaluaciones (batch) --------
    register_rest_route($ns, '/evaluaciones', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_evaluacion_batch'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // Historial de conducta por alumno
    register_rest_route($ns, '/alumnos/(?P<id>\d+)/conducta', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'get_alumno_conducta'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
      [
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => [__CLASS__, 'create_alumno_conducta'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);

    // Export historial de conducta por alumno (CSV/HTML imprimible)
    register_rest_route($ns, '/alumnos/(?P<id>\d+)/conducta/export', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'export_alumno_conducta'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);

    // Editar registro de conducta (solo el evaluador que lo creó): esquema items
    register_rest_route($ns, '/conducta-items/(?P<id>\d+)', [
      [
        'methods'  => WP_REST_Server::EDITABLE,
        'callback' => [__CLASS__, 'update_conducta_item'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);

    // Editar registro de conducta (solo el evaluador que lo creó): esquema legacy
    register_rest_route($ns, '/conducta-legacy/(?P<id>\d+)', [
      [
        'methods'  => WP_REST_Server::EDITABLE,
        'callback' => [__CLASS__, 'update_conducta_legacy'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);

    // Mis registros de conducta (solo los creados por el usuario actual)
    register_rest_route($ns, '/conducta/mis-registros', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'mis_registros_conducta'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);
    register_rest_route($ns, '/conducta/mis-registros/(?P<id>\d+)', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'mis_registros_conducta_detalle'],
        'permission_callback' => function () { return is_user_logged_in(); },
      ],
    ]);

    // Dashboard: estadísticas por aula y generales
    register_rest_route($ns, '/dashboard/stats', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'dashboard_stats'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Dashboard: lista de alumnos con estado de evaluado en el mes actual
    register_rest_route($ns, '/dashboard/alumnos-evaluados', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'dashboard_alumnos_evaluados'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Dashboard: distribución de conducta por aula (0-5) en rango de fechas
    register_rest_route($ns, '/dashboard/conducta-por-aula', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'dashboard_conducta_por_aula'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Reportes por fecha: listado de evaluaciones en rango
    register_rest_route($ns, '/reportes/fecha', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'reportes_por_fecha'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Usuarios con permisos (para filtro "Registró" en reporte por fecha): Administrator, Director, Funcionarios Administrativos, Docentes, Funcionarios de Oficina
    register_rest_route($ns, '/reportes/usuarios-registro', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'reportes_usuarios_registro'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Export reporte por fecha (Excel/CSV o PDF/HTML)
    register_rest_route($ns, '/reportes/export', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [__CLASS__, 'reportes_export'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA MANEJO DE FOTOS DE ALUMNOS
    // ============================================================================
    
    // Upload y eliminación de foto de alumno
    register_rest_route($ns, '/alumnos/(?P<id>\d+)/foto', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'upload_alumno_foto'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_alumno_foto'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA IMPORTACIÓN DE ALUMNOS
    // ============================================================================
    
    // Importar alumnos desde archivo Excel
    register_rest_route($ns, '/alumnos/import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'import_alumnos_excel'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA EXPORTACIÓN DE RENDIMIENTO INDIVIDUAL
    // ============================================================================
    
    // Exportar rendimiento de alumno individual en CSV
    register_rest_route($ns, '/alumnos/(?P<id>\d+)/export/csv', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_alumno_csv'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // Exportar rendimiento de alumno individual en PDF
    register_rest_route($ns, '/alumnos/(?P<id>\d+)/export/pdf', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_alumno_pdf'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA EXPORTACIÓN DE RENDIMIENTO POR AULA
    // ============================================================================
    
    // Exportar rendimiento de aula en CSV
    register_rest_route($ns, '/aulas/(?P<id>\d+)/export/csv', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_aula_csv'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // Exportar rendimiento de aula en PDF
    register_rest_route($ns, '/aulas/(?P<id>\d+)/export/pdf', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_aula_pdf'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA EXPORTACIÓN DE RENDIMIENTO POR CURSO
    // ============================================================================
    
    // Exportar rendimiento de curso en CSV
    register_rest_route($ns, '/cursos/(?P<id>\d+)/export/csv', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_curso_csv'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    // Exportar rendimiento de curso en PDF
    register_rest_route($ns, '/cursos/(?P<id>\d+)/export/pdf', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_curso_pdf'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ============================================================================
    // RUTAS PARA REPORTE GENERAL
    // ============================================================================
    
    // Exportar reporte general (admite filtros)
    register_rest_route($ns, '/reportes/general', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_reporte_general'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    NC_Rest_Asistencia::register_routes($ns);
    NC_Rest_Examenes::register_routes($ns);
  }

  // ---------------- Helpers ----------------

  private static function json_params(WP_REST_Request $req): array {
    $p = $req->get_json_params();
    return is_array($p) ? $p : [];
  }

  private static function int_or_null($v): ?int {
    $n = is_numeric($v) ? (int)$v : 0;
    return $n > 0 ? $n : null;
  }

  private static function norm_str($v): string {
    return trim(sanitize_text_field((string)($v ?? '')));
  }

  private static function ok($data, int $status = 200) {
    return new WP_REST_Response($data, $status);
  }

  private static function err($message, int $status = 400) {
    return new WP_Error('nc_error', $message, ['status' => $status]);
  }

  private static function db_fail($message, int $status = 500) {
    return new WP_Error('nc_db_error', $message, ['status' => $status]);
  }

  private static function norm_name($s) {
    $s = trim((string) $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  }

  /**
   * Sanitiza foto_url para guardar en BD. Acepta URL absoluta o relativa;
   * esc_url_raw() devuelve vacío para URLs relativas, por eso normalizamos antes.
   */
  /**
   * Lee foto_url del request: primero del body JSON en bruto (más fiable), luego de $p.
   */
  private static function get_foto_url_from_request(WP_REST_Request $req, array $p) {
    $raw_body = $req->get_body();
    if (is_string($raw_body) && $raw_body !== '') {
      $decoded = json_decode($raw_body, true);
      if (is_array($decoded) && array_key_exists('foto_url', $decoded)) {
        $v = $decoded['foto_url'];
        if (is_string($v)) return $v;
        if ($v === null) return '';
      }
    }
    return $p['foto_url'] ?? $req->get_param('foto_url') ?? '';
  }

  private static function sanitize_foto_url($value) {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || $raw === '0' || strtolower($raw) === 'null') {
      return null;
    }
    
    // Si ya es una URL completa (http/https), usarla directamente
    if (preg_match('#^https?://#i', $raw)) {
      $safe = esc_url_raw($raw);
      return $safe !== '' ? $safe : $raw;
    }
    
    // Si empieza con //, agregar el protocolo
    if (strpos($raw, '//') === 0) {
      $normalized = (is_ssl() ? 'https:' : 'http:') . $raw;
      $safe = esc_url_raw($normalized);
      return $safe !== '' ? $safe : $normalized;
    }
    
    // Si es una ruta relativa que empieza con /, convertir a URL absoluta
    if (preg_match('#^/#', $raw)) {
      $normalized = home_url($raw);
      $safe = esc_url_raw($normalized);
      return $safe !== '' ? $safe : $normalized;
    }
    
    // Si esc_url_raw devuelve vacío pero tenemos un valor, intentar usarlo de todas formas
    // (puede ser una URL válida que WordPress no reconoce por alguna razón)
    $safe = esc_url_raw($raw);
    if ($safe !== '') {
      return $safe;
    }
    
    // Último recurso: devolver el valor original si parece una URL válida
    if (filter_var($raw, FILTER_VALIDATE_URL) !== false) {
      return $raw;
    }
    
    // Si no parece una URL válida, devolver null
    return null;
  }

  private static function ensure_unique_name($table, $field, $value, $exclude_id = 0, $id_field = 'id') {
    global $wpdb;
    $value = self::norm_name($value);
    if ($value === '') return self::err('Nombre es obligatorio.');
    $sql = $wpdb->prepare("SELECT {$id_field} FROM {$table} WHERE {$field} = %s", $value);
    if ($exclude_id) $sql .= $wpdb->prepare(" AND {$id_field} != %d", (int)$exclude_id);
    $found = $wpdb->get_var($sql);
    if ($found) return self::err('Ya existe un registro con ese nombre.');
    return true;
  }


  // ---------------- Facultades ----------------

  public static function list_facultades(WP_REST_Request $req) {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_facultades';

    $rows = $wpdb->get_results("SELECT id, nombre, activo FROM $t WHERE activo=1 ORDER BY nombre ASC", ARRAY_A);
    return self::ok($rows);
  }

  public static function create_facultad(WP_REST_Request $req) {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_facultades';

    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');

    if ($nombre === '') return self::err('Nombre requerido.');

    // Verificar duplicados correctamente (409 si ya existe)
    $u = self::ensure_unique_name($t, 'nombre', $nombre);
    if (is_wp_error($u)) return $u;

    $wpdb->insert($t, ['nombre' => $nombre, 'activo' => 1], ['%s', '%d']);
    $id = (int)$wpdb->insert_id;

    return self::ok(['id' => $id, 'nombre' => $nombre], 201);
  }

  public static function update_facultad(WP_REST_Request $req) {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_facultades';

    $id = (int)$req['id'];
    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');
    
    if ($nombre==='') return self::err('Nombre requerido.');
    $u = self::ensure_unique_name($t, 'nombre', $nombre, $id);
    if (is_wp_error($u)) return $u;

    $wpdb->update($t, ['nombre' => $nombre], ['id' => $id], ['%s'], ['%d']);
    return self::ok(['id' => $id, 'nombre' => $nombre]);
  }

  public static function delete_facultad(WP_REST_Request $req) {
    global $wpdb;
    $t_fac   = $wpdb->prefix . 'conducta_facultades';
    $t_car   = $wpdb->prefix . 'conducta_carreras';
    $t_al    = $wpdb->prefix . 'conducta_alumnos';
    $t_aulas = $wpdb->prefix . 'conducta_aulas';
    $id = (int)$req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    // limpiar referencias
    $car_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_car WHERE facultad_id=%d", $id));
    if (!empty($car_ids)) {
      $in = implode(',', array_map('intval', $car_ids));
      $wpdb->query("UPDATE $t_al SET carrera_id=NULL WHERE carrera_id IN ($in)");
      $wpdb->query("UPDATE $t_aulas SET carrera_id=NULL WHERE carrera_id IN ($in)");
    }
    $wpdb->delete($t_car, ['facultad_id' => $id], ['%d']);
    $wpdb->update($t_al, ['facultad_id' => null], ['facultad_id' => $id]);
    $wpdb->update($t_aulas, ['facultad_id' => null], ['facultad_id' => $id]);

    $ok = $wpdb->delete($t_fac, ['id' => $id], ['%d']);
    if ($ok === false) return self::db_fail('No se pudo eliminar.');
    return self::ok(['ok' => true]);
  }

  // ---------------- Carreras ----------------

  public static function list_carreras(WP_REST_Request $req) {
    global $wpdb;
    $t_car = $wpdb->prefix . 'conducta_carreras';
    $t_fac = $wpdb->prefix . 'conducta_facultades';

    $facultad_id = self::int_or_null($req->get_param('facultad_id'));

    $where = 'c.activo=1';
    $params = [];
    if ($facultad_id) {
      $where .= ' AND c.facultad_id=%d';
      $params[] = $facultad_id;
    }

    $sql = "SELECT c.id, c.nombre, c.facultad_id, f.nombre AS facultad_nombre
            FROM $t_car c
            LEFT JOIN $t_fac f ON f.id=c.facultad_id
            WHERE $where
            ORDER BY f.nombre ASC, c.nombre ASC";

    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return self::ok($rows);
  }

  public static function create_carrera(WP_REST_Request $req) {
    global $wpdb;
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');
    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);

    if ($nombre === '' || !$facultad_id) return self::err('Campos obligatorios: nombre y facultad_id.');

    // Evitar duplicados dentro de la misma facultad.
    $dup = (int)$wpdb->get_var(
      $wpdb->prepare("SELECT id FROM $t_car WHERE facultad_id=%d AND nombre=%s LIMIT 1", $facultad_id, $nombre)
    );
    if ($dup) return self::err('Ya existe un registro con ese nombre.', 409);

    $wpdb->insert($t_car, [
      'nombre' => $nombre,
      'facultad_id' => $facultad_id,
      'activo' => 1,
    ], ['%s', '%d', '%d']);

    $id = (int)$wpdb->insert_id;
    return self::ok(['id' => $id, 'nombre' => $nombre, 'facultad_id' => $facultad_id], 201);
  }

  public static function update_carrera(WP_REST_Request $req) {
    global $wpdb;
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $id = (int)$req['id'];
    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');
    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);

    if ($nombre === '' || !$facultad_id) return self::err('Campos obligatorios: nombre y facultad_id.');

    $wpdb->update($t_car, [
      'nombre' => $nombre,
      'facultad_id' => $facultad_id,
    ], ['id' => $id], ['%s', '%d'], ['%d']);

    return self::ok(['id' => $id, 'nombre' => $nombre, 'facultad_id' => $facultad_id]);
  }

  public static function delete_carrera(WP_REST_Request $req) {
    global $wpdb;
    $t_car   = $wpdb->prefix . 'conducta_carreras';
    $t_al    = $wpdb->prefix . 'conducta_alumnos';
    $t_aulas = $wpdb->prefix . 'conducta_aulas';
    $id = (int)$req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $wpdb->update($t_al, ['carrera_id' => null], ['carrera_id' => $id]);
    $wpdb->update($t_aulas, ['carrera_id' => null], ['carrera_id' => $id]);

    $ok = $wpdb->delete($t_car, ['id' => $id], ['%d']);
    if ($ok === false) return self::db_fail('No se pudo eliminar.');
    return self::ok(['ok' => true]);
  }

  // ---------------- Cursos ----------------

  public static function list_cursos(WP_REST_Request $req) {
    global $wpdb;
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_fac = $wpdb->prefix . 'conducta_facultades';
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $activo_param = $req->get_param('activo');
    $show_all = (is_string($activo_param) && strtolower($activo_param) === 'all');

    $sql = "SELECT c.id, c.nombre, c.facultad_id, c.carrera_id, c.activo,
                   f.nombre AS facultad_nombre,
                   r.nombre AS carrera_nombre
            FROM $t_cur c
            LEFT JOIN $t_fac f ON f.id=c.facultad_id
            LEFT JOIN $t_car r ON r.id=c.carrera_id";
    if (!$show_all) {
      $sql .= " WHERE c.activo=1";
    }
    $sql .= " ORDER BY c.nombre ASC";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return self::ok($rows);
  }

  public static function create_curso(WP_REST_Request $req) {
    global $wpdb;
    $t_cur = $wpdb->prefix . 'conducta_cursos';

    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');
    
    if ($nombre==='') return self::err('Nombre requerido.');
    $u = self::ensure_unique_name($t_cur, 'nombre', $nombre);
    if (is_wp_error($u)) return $u;

    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
    $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);

    $wpdb->insert($t_cur, [
      'nombre' => $nombre,
      'facultad_id' => $facultad_id,
      'carrera_id' => $carrera_id,
      'activo' => 1,
    ], ['%s', '%d', '%d', '%d']);

    $id = (int)$wpdb->insert_id;
    return self::ok(['id' => $id, 'nombre' => $nombre, 'facultad_id' => $facultad_id, 'carrera_id' => $carrera_id], 201);
  }

  public static function update_curso(WP_REST_Request $req) {
    global $wpdb;
    $t_cur = $wpdb->prefix . 'conducta_cursos';

    $id = (int)$req['id'];
    $p = self::json_params($req);

    $nombre = self::norm_str($p['nombre'] ?? '');
    
    if ($nombre==='') return self::err('Nombre requerido.');
    $u = self::ensure_unique_name($t_cur, 'nombre', $nombre, $id);
    if (is_wp_error($u)) return $u;

    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
    $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
    // Aceptar activo explícitamente (0 o 1) para no confundir con "no enviado"
    $activo = array_key_exists('activo', $p) ? (int) $p['activo'] : null;
    if ($activo !== null) {
      $activo = $activo ? 1 : 0;
    }

    $data = [
      'nombre' => $nombre,
      'facultad_id' => $facultad_id,
      'carrera_id' => $carrera_id,
    ];
    $format = ['%s', '%d', '%d'];
    if ($activo !== null) {
      $data['activo'] = $activo;
      $format[] = '%d';
    }
    $wpdb->update($t_cur, $data, ['id' => $id], $format, ['%d']);

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, nombre, facultad_id, carrera_id, activo FROM $t_cur WHERE id = %d", $id), ARRAY_A);
    return self::ok($row ?: ['id' => $id, 'nombre' => $nombre, 'facultad_id' => $facultad_id, 'carrera_id' => $carrera_id, 'activo' => 1]);
  }

  public static function delete_curso(WP_REST_Request $req) {
    global $wpdb;
    $t_cur   = $wpdb->prefix . 'conducta_cursos';
    $t_aul   = $wpdb->prefix . 'conducta_aulas';
    $t_al    = $wpdb->prefix . 'conducta_alumnos';
    $t_eval  = $wpdb->prefix . 'conducta_evaluaciones';
    $id = (int)$req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $wpdb->update($t_al, ['curso_id' => null], ['curso_id' => $id]);
    $wpdb->update($t_aul, ['curso_id' => null], ['curso_id' => $id]);
    $wpdb->update($t_eval, ['curso_id' => null], ['curso_id' => $id]);

    $ok = $wpdb->delete($t_cur, ['id' => $id], ['%d']);
    if ($ok === false) return self::db_fail('No se pudo eliminar.');
    return self::ok(['ok' => true]);
  }

  // ---------------- Aulas ----------------

  public static function list_aulas(WP_REST_Request $req) {
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_fac = $wpdb->prefix . 'conducta_facultades';
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $curso_id     = self::int_or_null($req->get_param('curso_id'));
    $facultad_id  = self::int_or_null($req->get_param('facultad_id'));
    $carrera_id   = self::int_or_null($req->get_param('carrera_id'));

    $where = 'a.activo=1';
    $params = [];
    if ($curso_id) { $where .= ' AND a.curso_id=%d'; $params[] = $curso_id; }
    if ($facultad_id) { $where .= ' AND a.facultad_id=%d'; $params[] = $facultad_id; }
    if ($carrera_id) { $where .= ' AND a.carrera_id=%d'; $params[] = $carrera_id; }

    $sel_subgrupos = self::table_has_column($t_aul, 'subgrupos') ? ', a.subgrupos' : '';
    $sql = "SELECT a.id, a.nombre, a.turno, a.curso_id, a.facultad_id, a.carrera_id{$sel_subgrupos},
                   c.nombre AS curso_nombre,
                   f.nombre AS facultad_nombre,
                   r.nombre AS carrera_nombre
            FROM $t_aul a
            LEFT JOIN $t_cur c ON c.id=a.curso_id
            LEFT JOIN $t_fac f ON f.id=a.facultad_id
            LEFT JOIN $t_car r ON r.id=a.carrera_id
            WHERE $where
            ORDER BY a.nombre ASC";

    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    // Enriquecer subgrupos desde conducta_subgrupos_config si el aula no tiene subgrupos definidos (por curso)
    $t_sg = $wpdb->prefix . 'conducta_subgrupos_config';
    $table_exists = $wpdb->get_var($wpdb->prepare(
      "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
      $t_sg
    ));
    if ($table_exists && $rows) {
      $curso_ids = array_unique(array_filter(array_column($rows, 'curso_id')));
      $carrera_ids = array_unique(array_filter(array_column($rows, 'carrera_id')));
      $by_curso = [];
      $by_carrera = [];
      if ($curso_ids) {
        $ph = implode(',', array_fill(0, count($curso_ids), '%d'));
        $configs = $wpdb->get_results($wpdb->prepare(
          "SELECT curso_id, subgrupos FROM $t_sg WHERE tipo = 'curso' AND curso_id IN ($ph)",
          $curso_ids
        ), ARRAY_A);
        foreach ($configs ?: [] as $c) {
          $cid = (int) $c['curso_id'];
          if (!isset($by_curso[$cid]) || $c['subgrupos'] !== '') $by_curso[$cid] = $c['subgrupos'];
        }
      }
      if ($carrera_ids) {
        $ph = implode(',', array_fill(0, count($carrera_ids), '%d'));
        $configs = $wpdb->get_results($wpdb->prepare(
          "SELECT carrera_id, subgrupos FROM $t_sg WHERE tipo = 'carrera' AND carrera_id IN ($ph)",
          $carrera_ids
        ), ARRAY_A);
        foreach ($configs ?: [] as $c) {
          $cid = (int) $c['carrera_id'];
          if (!isset($by_carrera[$cid]) || $c['subgrupos'] !== '') $by_carrera[$cid] = $c['subgrupos'];
        }
      }
      foreach ($rows as &$row) {
        $sub = $row['subgrupos'] ?? '';
        if ($sub !== '' && $sub !== null) continue;
        if (!empty($row['curso_id']) && isset($by_curso[(int)$row['curso_id']])) {
          $row['subgrupos'] = $by_curso[(int)$row['curso_id']];
        } elseif (!empty($row['carrera_id']) && isset($by_carrera[(int)$row['carrera_id']])) {
          $row['subgrupos'] = $by_carrera[(int)$row['carrera_id']];
        }
      }
      unset($row);
    }

    return self::ok($rows);
  }

  public static function create_aula(WP_REST_Request $req) {
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';

    $p = self::json_params($req);
    $nombre = self::norm_str($p['nombre'] ?? '');
    
    if ($nombre==='') return self::err('Nombre requerido.');
if ($nombre === '') return self::err('El nombre del aula es obligatorio.');

    $curso_id    = self::int_or_null($p['curso_id'] ?? null);
    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
    $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
    $turno       = self::norm_str($p['turno'] ?? '');
    $subgrupos   = array_key_exists('subgrupos', $p) ? trim(sanitize_text_field((string) ($p['subgrupos'] ?? ''))) : null;
    if ($subgrupos !== null && $subgrupos === '') $subgrupos = null;

    $insert_data = [
      'nombre' => $nombre,
      'curso_id' => $curso_id,
      'facultad_id' => $facultad_id,
      'carrera_id' => $carrera_id,
      'turno' => $turno,
      'activo' => 1,
    ];
    $insert_fmt = ['%s', '%d', '%d', '%d', '%s', '%d'];
    if (self::table_has_column($t_aul, 'subgrupos') && $subgrupos !== null) {
      $insert_data['subgrupos'] = $subgrupos;
      $insert_fmt[] = '%s';
    }
    $wpdb->insert($t_aul, $insert_data, $insert_fmt);

    $id = (int)$wpdb->insert_id;
    $out = ['id' => $id, 'nombre' => $nombre, 'curso_id' => $curso_id, 'facultad_id' => $facultad_id, 'carrera_id' => $carrera_id, 'turno' => $turno];
    if (self::table_has_column($t_aul, 'subgrupos')) $out['subgrupos'] = $subgrupos;
    return self::ok($out, 201);
  }

  public static function update_aula(WP_REST_Request $req) {
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';

    $id = (int)$req['id'];
    $p = self::json_params($req);

    $nombre = self::norm_str($p['nombre'] ?? '');
    
    if ($nombre==='') return self::err('Nombre requerido.');

    $curso_id    = self::int_or_null($p['curso_id'] ?? null);
    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
    $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
    $turno       = self::norm_str($p['turno'] ?? '');
    $subgrupos   = array_key_exists('subgrupos', $p) ? trim(sanitize_text_field((string) ($p['subgrupos'] ?? ''))) : null;
    if ($subgrupos !== null && $subgrupos === '') $subgrupos = null;

    $update_data = ['nombre' => $nombre, 'curso_id' => $curso_id, 'facultad_id' => $facultad_id, 'carrera_id' => $carrera_id, 'turno' => $turno];
    $update_fmt = ['%s', '%d', '%d', '%d', '%s'];
    if (self::table_has_column($t_aul, 'subgrupos')) {
      $update_data['subgrupos'] = $subgrupos;
      $update_fmt[] = '%s';
    }
    $wpdb->update($t_aul, $update_data, ['id' => $id], $update_fmt, ['%d']);

    $out = ['id' => $id, 'nombre' => $nombre, 'curso_id' => $curso_id, 'facultad_id' => $facultad_id, 'carrera_id' => $carrera_id, 'turno' => $turno];
    if (self::table_has_column($t_aul, 'subgrupos')) $out['subgrupos'] = $subgrupos;
    return self::ok($out);
  }

  public static function delete_aula(WP_REST_Request $req) {
    global $wpdb;
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';

    $id = (int)$req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $wpdb->update($t_al, ['aula_id' => null], ['aula_id' => $id]);
    $wpdb->update($t_eval, ['aula_id' => null], ['aula_id' => $id]);

    $ok = $wpdb->delete($t_aul, ['id' => $id], ['%d']);
    if ($ok === false) return self::db_fail('No se pudo eliminar.');
    return self::ok(['ok' => true]);
  }

  // ---------------- Configuración de subgrupos (curso/carrera) ----------------

  public static function list_subgrupos_config(WP_REST_Request $req) {
    global $wpdb;
    $t_sg  = $wpdb->prefix . 'conducta_subgrupos_config';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $rows = $wpdb->get_results(
      "SELECT s.id, s.tipo, s.curso_id, s.carrera_id, s.subgrupos, s.created_at,
              c.nombre AS curso_nombre, r.nombre AS carrera_nombre
       FROM $t_sg s
       LEFT JOIN $t_cur c ON c.id = s.curso_id
       LEFT JOIN $t_car r ON r.id = s.carrera_id
       ORDER BY s.tipo ASC, s.id ASC",
      ARRAY_A
    );
    return self::ok($rows ?: []);
  }

  public static function create_subgrupos_config(WP_REST_Request $req) {
    global $wpdb;
    $t_sg = $wpdb->prefix . 'conducta_subgrupos_config';

    $p = self::json_params($req);
    $tipo = trim(sanitize_text_field((string) ($p['tipo'] ?? 'curso')));
    if (!in_array($tipo, ['curso', 'carrera'], true)) $tipo = 'curso';

    $curso_id   = self::int_or_null($p['curso_id'] ?? null);
    $carrera_id = self::int_or_null($p['carrera_id'] ?? null);
    $subgrupos  = trim(sanitize_text_field((string) ($p['subgrupos'] ?? '')));
    if ($subgrupos === '') return self::err('El campo subgrupos es obligatorio (ej: 1,2,3 o A,B,C).');

    if ($tipo === 'curso' && ($curso_id === null || $curso_id <= 0)) {
      return self::err('Para tipo curso debe seleccionar un curso.');
    }
    if ($tipo === 'carrera' && ($carrera_id === null || $carrera_id <= 0)) {
      return self::err('Para tipo carrera debe seleccionar una carrera.');
    }
    if ($tipo === 'curso') $carrera_id = null;
    if ($tipo === 'carrera') $curso_id = null;

    $wpdb->insert($t_sg, [
      'tipo' => $tipo,
      'curso_id' => $curso_id,
      'carrera_id' => $carrera_id,
      'subgrupos' => $subgrupos,
    ], ['%s', '%d', '%d', '%s']);

    $id = (int) $wpdb->insert_id;
    return self::ok(['id' => $id, 'tipo' => $tipo, 'curso_id' => $curso_id, 'carrera_id' => $carrera_id, 'subgrupos' => $subgrupos], 201);
  }

  public static function get_subgrupos_config(WP_REST_Request $req) {
    global $wpdb;
    $t_sg  = $wpdb->prefix . 'conducta_subgrupos_config';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_car = $wpdb->prefix . 'conducta_carreras';

    $id = (int) $req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT s.id, s.tipo, s.curso_id, s.carrera_id, s.subgrupos, s.created_at,
              c.nombre AS curso_nombre, r.nombre AS carrera_nombre
       FROM $t_sg s
       LEFT JOIN $t_cur c ON c.id = s.curso_id
       LEFT JOIN $t_car r ON r.id = s.carrera_id
       WHERE s.id = %d",
      $id
    ), ARRAY_A);
    if (!$row) return self::err('No encontrado.', 404);
    return self::ok($row);
  }

  public static function update_subgrupos_config(WP_REST_Request $req) {
    global $wpdb;
    $t_sg = $wpdb->prefix . 'conducta_subgrupos_config';

    $id = (int) $req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $p = self::json_params($req);
    $tipo = array_key_exists('tipo', $p) ? trim(sanitize_text_field((string) $p['tipo'])) : null;
    $curso_id   = array_key_exists('curso_id', $p) ? self::int_or_null($p['curso_id']) : null;
    $carrera_id = array_key_exists('carrera_id', $p) ? self::int_or_null($p['carrera_id']) : null;
    $subgrupos  = array_key_exists('subgrupos', $p) ? trim(sanitize_text_field((string) $p['subgrupos'])) : null;

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, tipo, curso_id, carrera_id, subgrupos FROM $t_sg WHERE id = %d", $id), ARRAY_A);
    if (!$row) return self::err('No encontrado.', 404);

    if ($tipo !== null) {
      if (!in_array($tipo, ['curso', 'carrera'], true)) $tipo = $row['tipo'];
      $row['tipo'] = $tipo;
    }
    if ($curso_id !== null) $row['curso_id'] = $curso_id;
    if ($carrera_id !== null) $row['carrera_id'] = $carrera_id;
    if ($subgrupos !== null) {
      if ($subgrupos === '') return self::err('El campo subgrupos no puede quedar vacío.');
      $row['subgrupos'] = $subgrupos;
    }

    $tipo = $row['tipo'];
    if ($tipo === 'curso') $row['carrera_id'] = null;
    if ($tipo === 'carrera') $row['curso_id'] = null;

    $wpdb->update($t_sg, [
      'tipo' => $row['tipo'],
      'curso_id' => $row['curso_id'],
      'carrera_id' => $row['carrera_id'],
      'subgrupos' => $row['subgrupos'],
    ], ['id' => $id], ['%s', '%d', '%d', '%s']);

    return self::ok(['id' => $id, 'tipo' => $row['tipo'], 'curso_id' => $row['curso_id'], 'carrera_id' => $row['carrera_id'], 'subgrupos' => $row['subgrupos']]);
  }

  public static function delete_subgrupos_config(WP_REST_Request $req) {
    global $wpdb;
    $t_sg = $wpdb->prefix . 'conducta_subgrupos_config';

    $id = (int) $req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $ok = $wpdb->delete($t_sg, ['id' => $id], ['%d']);
    if ($ok === false) return self::db_fail('No se pudo eliminar.');
    return self::ok(['ok' => true]);
  }

  // ---------------- Alumnos ----------------

  /**
   * GET /alumnos/:id — devuelve un alumno por ID (para modal con datos frescos, incl. foto_url).
   */
  public static function get_alumno(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    if ($id <= 0) return self::err('ID inválido.');

    $t_al  = $wpdb->prefix . 'conducta_alumnos';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_fac = $wpdb->prefix . 'conducta_facultades';
    $t_car = $wpdb->prefix . 'conducta_carreras';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';

    $use_rel = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c)
      && ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
    $has_grupo_col = self::table_has_column($t_al, 'grupo_id');

    $sel_curso_col = 'a.curso_id';
    $sel_aula_col = 'a.aula_id';
    $sel_curso_nombre = 'c.nombre';
    $sel_aula_nombre = 'au.nombre';
    $join_group_expr = 'a.aula_id';

    if ($use_rel) {
      $sel_curso_col = "(SELECT ac2.curso_id
                         FROM $t_al_c ac2
                         INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                         WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1
                         ORDER BY c2.nombre ASC
                         LIMIT 1)";
      $sel_aula_col = "(SELECT ag2.aula_id
                        FROM $t_al_a ag2
                        INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                        WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1
                        ORDER BY au2.nombre ASC
                        LIMIT 1)";
      $sel_curso_nombre = "(SELECT GROUP_CONCAT(DISTINCT c2.nombre ORDER BY c2.nombre SEPARATOR ', ')
                           FROM $t_al_c ac2
                           INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                           WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1)";
      $sel_aula_nombre = "(SELECT GROUP_CONCAT(DISTINCT au2.nombre ORDER BY au2.nombre SEPARATOR ', ')
                          FROM $t_al_a ag2
                          INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                          WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1)";
    }

    $rel_group_expr = "(SELECT ag2.aula_id
                        FROM $t_al_a ag2
                        INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                        WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1
                        ORDER BY au2.nombre ASC
                        LIMIT 1)";
    if ($has_grupo_col) {
      $join_group_expr = $use_rel
        ? "COALESCE(a.grupo_id, a.aula_id, $rel_group_expr)"
        : 'COALESCE(a.grupo_id, a.aula_id)';
    } elseif ($use_rel) {
      $join_group_expr = "COALESCE(a.aula_id, $rel_group_expr)";
    }

    $sel_subgrupo = self::table_has_column($t_al, 'subgrupo') ? ', a.subgrupo' : '';
    $sql = "SELECT a.id, a.nombres, a.apellidos, a.ci, a.foto_url, $sel_curso_col AS curso_id, $sel_aula_col AS aula_id, a.facultad_id, a.carrera_id{$sel_subgrupo},
                   COALESCE(NULLIF({$sel_curso_nombre}, ''), c.nombre) AS curso_nombre,
                   COALESCE(NULLIF({$sel_aula_nombre}, ''), au.nombre) AS aula_nombre,
                   f.nombre AS facultad_nombre,
                   r.nombre AS carrera_nombre
            FROM $t_al a
            LEFT JOIN $t_cur c ON c.id = a.curso_id
            LEFT JOIN $t_aul au ON au.id = $join_group_expr
            LEFT JOIN $t_fac f ON f.id = a.facultad_id
            LEFT JOIN $t_car r ON r.id = a.carrera_id
            WHERE a.id = %d AND a.activo = 1";
    $row = $wpdb->get_row($wpdb->prepare($sql, $id), ARRAY_A);
    if (!$row) return self::err('Alumno no encontrado.', 404);

    if (isset($row['foto_url']) && ($row['foto_url'] === '0' || $row['foto_url'] === 0)) {
      $row['foto_url'] = null;
    }
    $row['nombre'] = trim(($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''));
    return self::ok($row);
  }

  public static function list_alumnos(WP_REST_Request $req) {
    global $wpdb;
    $t_al  = $wpdb->prefix . 'conducta_alumnos';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_fac = $wpdb->prefix . 'conducta_facultades';
    $t_car = $wpdb->prefix . 'conducta_carreras';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';

    $use_rel = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c)
      && ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);

    // Compat: algunos sitios arrancaron con columnas singular (nombre/apellido).
    // Si existen, las usamos como fallback para no devolver vacíos.
    $has_nombre   = self::table_has_column($t_al, 'nombre');
    $has_apellido = self::table_has_column($t_al, 'apellido');

    $search      = self::norm_str($req->get_param('search'));
    $curso_id    = self::int_or_null($req->get_param('curso_id'));
    $aula_id     = self::int_or_null($req->get_param('aula_id'));
    $facultad_id = self::int_or_null($req->get_param('facultad_id'));
    $facultad_ids = [];
    $raw_fac_ids = $req->get_param('facultad_ids');
    if (is_string($raw_fac_ids) && $raw_fac_ids !== '') {
      foreach (explode(',', $raw_fac_ids) as $part) {
        $fid = (int) trim($part);
        if ($fid > 0) {
          $facultad_ids[] = $fid;
        }
      }
    } elseif (is_array($raw_fac_ids)) {
      foreach ($raw_fac_ids as $x) {
        $fid = (int) $x;
        if ($fid > 0) {
          $facultad_ids[] = $fid;
        }
      }
    }
    $facultad_ids = array_values(array_unique($facultad_ids));
    $carrera_id  = self::int_or_null($req->get_param('carrera_id'));
    $subgrupo    = self::norm_str($req->get_param('subgrupo'));
    $materia_id  = self::int_or_null($req->get_param('materia_id'));

    // Compat: el frontend puede enviar order_by o sort_by
    $order_by = self::norm_str($req->get_param('order_by'));
    if ($order_by === '') $order_by = self::norm_str($req->get_param('sort_by'));
    // Compat valores antiguos
    if ($order_by === 'apellido') $order_by = 'apellidos';
    if ($order_by === 'nombre') $order_by = 'nombres';

    $order    = strtoupper(self::norm_str($req->get_param('order')));
    $order    = in_array($order, ['ASC','DESC'], true) ? $order : 'ASC';

    $order_sql = 'a.nombres ASC, a.apellidos ASC';
    if ($order_by === 'apellidos') $order_sql = "a.apellidos $order, a.nombres $order";
    elseif ($order_by === 'nombres') $order_sql = "a.nombres $order, a.apellidos $order";
    elseif ($order_by === 'ci') $order_sql = "a.ci $order";

    $where = 'a.activo=1';
    $params = [];
    $join_course = '';
    $join_aula = '';
    $curso_expr = 'a.curso_id';
    $aula_expr = 'a.aula_id';
    $sel_curso_col = 'a.curso_id';
    $sel_aula_col = 'a.aula_id';

    if ($use_rel && $curso_id) {
      $join_course = "INNER JOIN $t_al_c ac ON ac.alumno_id=a.id AND ac.curso_id=%d AND ac.activo=1";
      $curso_expr = 'ac.curso_id';
      $sel_curso_col = 'ac.curso_id AS curso_id';
      $params[] = $curso_id;
    } elseif ($curso_id) {
      $where .= ' AND a.curso_id=%d';
      $params[] = $curso_id;
    }

    if ($use_rel && $aula_id) {
      $join_aula = "INNER JOIN $t_al_a ag ON ag.alumno_id=a.id AND ag.aula_id=%d AND ag.activo=1";
      $aula_expr = 'ag.aula_id';
      $sel_aula_col = 'ag.aula_id AS aula_id';
      $params[] = $aula_id;
    } elseif ($aula_id) {
      $where .= ' AND a.aula_id=%d';
      $params[] = $aula_id;
    }

    // Si el frontend no filtra por curso/aula, para instalaciones nuevas sin columnas legacy
    // (curso_id/aula_id en conducta_alumnos) seleccionamos un curso y un grupo activos
    // desde las tablas de relación, para que los modales puedan registrar correctamente.
    if ($use_rel && !$curso_id) {
      $sel_curso_col = "(SELECT ac2.curso_id
                         FROM $t_al_c ac2
                         INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                         WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1
                         ORDER BY c2.nombre ASC
                         LIMIT 1)";
    }
    if ($use_rel && !$aula_id) {
      $sel_aula_col = "(SELECT ag2.aula_id
                        FROM $t_al_a ag2
                        INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                        WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1
                        ORDER BY au2.nombre ASC
                        LIMIT 1)";
    }

    if (!empty($facultad_ids)) {
      $placeholders = implode(',', array_fill(0, count($facultad_ids), '%d'));
      $where .= " AND a.facultad_id IN ($placeholders)";
      foreach ($facultad_ids as $fid) {
        $params[] = $fid;
      }
    } elseif ($facultad_id) {
      $where .= ' AND a.facultad_id=%d';
      $params[] = $facultad_id;
    }
    if ($carrera_id)  { $where .= ' AND a.carrera_id=%d'; $params[] = $carrera_id; }
    if ($subgrupo !== '' && self::table_has_column($t_al, 'subgrupo')) {
      $where .= ' AND a.subgrupo=%s';
      $params[] = $subgrupo;
    }
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($materia_id && $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") === $t_am) {
      $where .= ' AND EXISTS (SELECT 1 FROM ' . $t_am . ' am WHERE am.alumno_id=a.id AND am.materia_id=%d)';
      $params[] = $materia_id;
    }

    if ($search !== '') {
      // match nombres, apellidos, CI (compat con columnas viejas)
      // Usamos COALESCE para buscar en ambas columnas (singular y plural) de forma unificada
      $like = '%' . $wpdb->esc_like($search) . '%';
      
      // Construir expresiones COALESCE para nombres y apellidos
      $coalesce_nombres = $has_nombre 
        ? "COALESCE(NULLIF(a.nombres,''), NULLIF(a.nombre,''), '')" 
        : "COALESCE(a.nombres, '')";
      
      $coalesce_apellidos = $has_apellido 
        ? "COALESCE(NULLIF(a.apellidos,''), NULLIF(a.apellido,''), '')" 
        : "COALESCE(a.apellidos, '')";
      
      // Buscar en CI, nombres individuales, apellidos individuales, y nombre completo concatenado
      $where .= " AND (a.ci LIKE %s OR {$coalesce_nombres} LIKE %s OR {$coalesce_apellidos} LIKE %s OR CONCAT({$coalesce_nombres},' ',{$coalesce_apellidos}) LIKE %s)";
      array_push($params, $like, $like, $like, $like);
    }

    $sel_nombres = 'a.nombres';
    $sel_apellidos = 'a.apellidos';
    $sel_legacy = '';
    if ($has_nombre) { $sel_nombres = "COALESCE(NULLIF(a.nombres,''), a.nombre)"; $sel_legacy .= ', a.nombre'; }
    if ($has_apellido) { $sel_apellidos = "COALESCE(NULLIF(a.apellidos,''), a.apellido)"; $sel_legacy .= ', a.apellido'; }

    // Compatibilidad con instalaciones viejas: algunas tablas guardan el texto
    // de facultad/carrera en columnas `facultad` y `carrera` (además de *_id).
    $has_fac_txt = self::table_has_column($t_al, 'facultad');
    $has_car_txt = self::table_has_column($t_al, 'carrera');

    $sel_facultad_nombre = 'f.nombre';
    $sel_carrera_nombre  = 'r.nombre';
    if ($has_fac_txt) {
      $sel_legacy .= ', a.facultad';
      $sel_facultad_nombre = "COALESCE(NULLIF(f.nombre,''), NULLIF(a.facultad,''))";
    }
    if ($has_car_txt) {
      $sel_legacy .= ', a.carrera';
      $sel_carrera_nombre = "COALESCE(NULLIF(r.nombre,''), NULLIF(a.carrera,''))";
    }

    $sel_subgrupo = self::table_has_column($t_al, 'subgrupo') ? ', a.subgrupo' : '';
    $sel_curso_nombre = 'c.nombre';
    $sel_aula_nombre = 'au.nombre';
    if ($use_rel) {
      $sel_curso_nombre = "(SELECT GROUP_CONCAT(DISTINCT c2.nombre ORDER BY c2.nombre SEPARATOR ', ')
                           FROM $t_al_c ac2
                           INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                           WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1)";
      $sel_aula_nombre = "(SELECT GROUP_CONCAT(DISTINCT au2.nombre ORDER BY au2.nombre SEPARATOR ', ')
                          FROM $t_al_a ag2
                          INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                          WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1)";
    }
    $sel_curso = $sel_curso_col;
    $sel_aula  = $sel_aula_col;
    $sql = "SELECT DISTINCT a.id, {$sel_nombres} AS nombres, {$sel_apellidos} AS apellidos, a.ci, a.foto_url,
                   $sel_curso, $sel_aula, a.facultad_id, a.carrera_id{$sel_subgrupo}{$sel_legacy},
                   {$sel_curso_nombre} AS curso_nombre,
                   {$sel_aula_nombre} AS aula_nombre,
                   {$sel_facultad_nombre} AS facultad_nombre,
                   {$sel_carrera_nombre} AS carrera_nombre
            FROM $t_al a
            {$join_course}
            {$join_aula}
            LEFT JOIN $t_cur c ON c.id=$curso_expr
            LEFT JOIN $t_aul au ON au.id=$aula_expr
            LEFT JOIN $t_fac f ON f.id=a.facultad_id
            LEFT JOIN $t_car r ON r.id=a.carrera_id
            WHERE $where
            ORDER BY $order_sql";

    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    // Compat: algunos frontends/datos viejos esperan nombre/apellido.
    foreach ($rows as &$row) {
      // Normalizar foto_url: instalaciones viejas guardaron "0" (sin foto).
      if (isset($row['foto_url']) && ($row['foto_url'] === '0' || $row['foto_url'] === 0)) {
        $row['foto_url'] = null;
      }
      if (!isset($row['nombre']))   $row['nombre']   = trim(($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''));
      if (!isset($row['apellido'])) $row['apellido'] = $row['apellidos'] ?? '';
    }
    unset($row);
    return self::ok($rows);
  }

  /**
   * Devuelve la lista de subgrupos distintos presentes en alumnos, según filtros.
   * GET /alumnos/subgrupos?aula_id=...&materia_id=...&curso_id=...&facultad_id=...&carrera_id=...
   */
  public static function list_alumnos_subgrupos(WP_REST_Request $req) {
    global $wpdb;
    $t_al  = $wpdb->prefix . 'conducta_alumnos';
    if (!self::table_has_column($t_al, 'subgrupo')) {
      return self::ok(['items' => []]);
    }
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $use_rel = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c)
      && ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);

    $curso_id    = self::int_or_null($req->get_param('curso_id'));
    $aula_id     = self::int_or_null($req->get_param('aula_id'));
    $facultad_id = self::int_or_null($req->get_param('facultad_id'));
    $carrera_id  = self::int_or_null($req->get_param('carrera_id'));
    $materia_id  = self::int_or_null($req->get_param('materia_id'));

    $where = "a.activo=1 AND a.subgrupo IS NOT NULL AND a.subgrupo <> ''";
    $params = [];
    $join_course = '';
    $join_aula = '';
    if ($use_rel && $curso_id) {
      $join_course = "INNER JOIN $t_al_c ac ON ac.alumno_id=a.id AND ac.curso_id=%d AND ac.activo=1";
      $params[] = $curso_id;
    } elseif ($curso_id) {
      $where .= ' AND a.curso_id=%d';
      $params[] = $curso_id;
    }
    if ($use_rel && $aula_id) {
      $join_aula = "INNER JOIN $t_al_a ag ON ag.alumno_id=a.id AND ag.aula_id=%d AND ag.activo=1";
      $params[] = $aula_id;
    } elseif ($aula_id) {
      $where .= ' AND a.aula_id=%d';
      $params[] = $aula_id;
    }
    if ($facultad_id) { $where .= ' AND a.facultad_id=%d'; $params[] = $facultad_id; }
    if ($carrera_id)  { $where .= ' AND a.carrera_id=%d'; $params[] = $carrera_id; }

    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($materia_id && $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") === $t_am) {
      $where .= ' AND EXISTS (SELECT 1 FROM ' . $t_am . ' am WHERE am.alumno_id=a.id AND am.materia_id=%d)';
      $params[] = $materia_id;
    }

    $sql = "SELECT DISTINCT a.subgrupo
            FROM $t_al a
            {$join_course}
            {$join_aula}
            WHERE $where
            ORDER BY a.subgrupo ASC";
    $rows = $params ? $wpdb->get_col($wpdb->prepare($sql, $params)) : $wpdb->get_col($sql);
    $items = array_values(array_filter(array_map('strval', $rows ?: []), fn($v) => trim($v) !== ''));
    return self::ok(['items' => $items]);
  }

  public static function create_alumno(WP_REST_Request $req) {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $has_rel_c = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c);
    $has_rel_a = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);

    $p = self::json_params($req);
    if (empty($p)) {
      $p = $req->get_body_params();
    }
    $foto_url_param = self::get_foto_url_from_request($req, $p);

    $nombres  = self::norm_str($p['nombres'] ?? $p['nombre'] ?? '');
    $apellidos = self::norm_str($p['apellidos'] ?? $p['apellido'] ?? '');
    $ci       = self::norm_str($p['ci'] ?? '');

    if ($nombres === '' || $apellidos === '' || $ci === '') {
      return self::err('Campos obligatorios: nombres, apellidos y ci.');
    }

    $curso_id    = self::int_or_null($p['curso_id'] ?? null);
    $aula_id     = self::int_or_null($p['aula_id'] ?? ($p['grupo_id'] ?? null));
    $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
    $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
    $subgrupo    = isset($p['subgrupo']) ? trim(sanitize_text_field((string) $p['subgrupo'])) : null;
    if ($subgrupo !== null && $subgrupo === '') $subgrupo = null;
    $foto_url = self::sanitize_foto_url($foto_url_param);
    
    // 🔍 DEBUG TEMPORAL - Remover después de solucionar
    error_log('[NC_DEBUG] create_alumno - foto_url_param recibido: ' . var_export($foto_url_param, true));
    error_log('[NC_DEBUG] create_alumno - foto_url sanitizada: ' . var_export($foto_url, true));
    error_log('[NC_DEBUG] create_alumno - payload completo: ' . json_encode($p));
    

    // Compat: nombre completo
    $nombre_full = trim($nombres . ' ' . $apellidos);

    $data = [
      'nombres' => $nombres,
      'apellidos' => $apellidos,
      'ci' => $ci,
      'curso_id' => $curso_id,
      'grupo_id' => $aula_id,
      'aula_id' => $aula_id,
      'facultad_id' => $facultad_id,
      'carrera_id' => $carrera_id,
      'foto_url' => $foto_url,
      'activo' => 1,
    ];
    $formats = ['%s','%s','%s','%d','%d','%d','%d','%d','%s','%d'];
    if (self::table_has_column($t_al, 'subgrupo') && $subgrupo !== null) {
      $data['subgrupo'] = $subgrupo;
      $formats[] = '%s';
    }
    // Compat: si existen columnas viejas, también las rellenamos.
    if (self::table_has_column($t_al, 'nombre')) {
      $data['nombre'] = $nombre_full;
      $formats[] = '%s'; // ✅ NO usar splice
    }

    if (self::table_has_column($t_al, 'apellido')) {
      $data['apellido'] = $apellidos;
      // El formato va al final si agregamos al final
      $formats[] = '%s';
    }

    $wpdb->insert($t_al, $data, $formats);

    $id = (int)$wpdb->insert_id;

    // Multi-pertenencia: guardamos también en las tablas de relación (append).
    if ($id > 0) {
      if ($has_rel_c && $curso_id) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $t_al_c (alumno_id, curso_id) VALUES (%d,%d)",
          $id,
          $curso_id
        ));
      }
      if ($has_rel_a && $aula_id) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $t_al_a (alumno_id, aula_id) VALUES (%d,%d)",
          $id,
          $aula_id
        ));
      }
    }

    if ($id && class_exists('NC_Rest_Asistencia') && ($curso_id || $aula_id || $carrera_id || $facultad_id)) {
      NC_Rest_Asistencia::auto_inscribir_alumno_curso_materias($id, $curso_id, $aula_id, true);
    }
    if ($id && $aula_id && class_exists('NC_Rest_Examenes')) {
      NC_Rest_Examenes::auto_registrar_alumno_examenes_nu($id, $aula_id);
    }
    $out = [
      'id' => $id,
      'nombres' => $nombres,
      'apellidos' => $apellidos,
      'ci' => $ci,
      'curso_id' => $curso_id,
      'aula_id' => $aula_id,
      'facultad_id' => $facultad_id,
      'carrera_id' => $carrera_id,
      'foto_url' => $foto_url,
    ];
    if (self::table_has_column($t_al, 'subgrupo')) $out['subgrupo'] = $subgrupo;
    return self::ok($out, 201);
  }

  // ============================================================================
    // REEMPLAZAR FUNCIÓN COMPLETA update_alumno()
    // Desde línea ~1039 hasta ~1092
    // ============================================================================
    
    public static function update_alumno(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para editar datos de alumnos.', 403);
      }
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
    
      $id = (int)$req['id'];
      $p = self::json_params($req);
      if (empty($p)) {
        $p = $req->get_body_params();
      }
      
      // 🔍 DEBUG - Ver qué llega del frontend
      error_log('═══════════════════════════════════════════════════════');
      error_log('[UPDATE_ALUMNO] ID: ' . $id);
      error_log('[UPDATE_ALUMNO] Payload completo: ' . json_encode($p));
      
      $foto_url_param = self::get_foto_url_from_request($req, $p);
      
      error_log('[UPDATE_ALUMNO] foto_url_param extraído: ' . var_export($foto_url_param, true));
      error_log('[UPDATE_ALUMNO] array_key_exists foto_url: ' . (array_key_exists('foto_url', $p) ? 'SÍ' : 'NO'));
    
      $nombres  = self::norm_str($p['nombres'] ?? $p['nombre'] ?? '');
      $apellidos = self::norm_str($p['apellidos'] ?? $p['apellido'] ?? '');
      $ci       = self::norm_str($p['ci'] ?? '');
    
      if ($nombres === '' || $apellidos === '' || $ci === '') {
        return self::err('Campos obligatorios: nombres, apellidos y ci.');
      }
    
      $curso_id    = self::int_or_null($p['curso_id'] ?? null);
      $aula_id     = self::int_or_null($p['aula_id'] ?? ($p['grupo_id'] ?? null));
      $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
      $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
      $subgrupo    = array_key_exists('subgrupo', $p) ? trim(sanitize_text_field((string) ($p['subgrupo'] ?? ''))) : null;
      if ($subgrupo !== null && $subgrupo === '') $subgrupo = null;
      $foto_url    = self::sanitize_foto_url($foto_url_param);
    
      error_log('[UPDATE_ALUMNO] foto_url después de sanitize: ' . var_export($foto_url, true));
    
      $nombre_full = trim($nombres . ' ' . $apellidos);
    
      // ✅ SOLUCIÓN: Base data SIN foto_url
      $data = [
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'ci' => $ci,
        'curso_id' => $curso_id,
        'grupo_id' => $aula_id,
        'aula_id' => $aula_id,
        'facultad_id' => $facultad_id,
        'carrera_id' => $carrera_id,
      ];
      $format = ['%s','%s','%s','%d','%d','%d','%d','%d'];
      if (self::table_has_column($t_al, 'subgrupo')) {
        $data['subgrupo'] = $subgrupo;
        $format[] = '%s';
      }
      
      // ✅ CRÍTICO: SOLO actualizar foto_url si:
      // 1. El campo fue enviado explícitamente (array_key_exists)
      // 2. Y tiene un valor válido (no null, no vacío)
      if (array_key_exists('foto_url', $p)) {
        error_log('[UPDATE_ALUMNO] ⚠️ foto_url fue enviado en el payload');
        
        if ($foto_url !== null && $foto_url !== '') {
          error_log('[UPDATE_ALUMNO] ✅ foto_url tiene valor válido, SE ACTUALIZARÁ a: ' . $foto_url);
          $data['foto_url'] = $foto_url;
          $format[] = '%s';
        } else {
          error_log('[UPDATE_ALUMNO] ⚠️ foto_url es NULL/vacío pero fue enviado');
          error_log('[UPDATE_ALUMNO] Opción A: NO actualizar (mantener foto existente)');
          error_log('[UPDATE_ALUMNO] Opción B: Actualizar a NULL (borrar foto)');
          
          // 🎯 DECISIÓN: ¿Qué hacer cuando se envía foto_url = null?
          
          // OPCIÓN A: NO actualizar (mantener foto existente) - RECOMENDADO
          // (No hacer nada, foto_url no se incluye en $data)
          
          // OPCIÓN B: Actualizar a NULL (borrar foto explícitamente)
          // Descomentar estas líneas si querés permitir borrar fotos:
          // $data['foto_url'] = null;
          // $format[] = '%s';
        }
      } else {
        error_log('[UPDATE_ALUMNO] ✅ foto_url NO fue enviado, NO se actualizará (mantiene valor existente)');
      }
    
      // Esquemas viejos: si existen columnas singular, mantenemos actualizado también ahí.
      if (self::table_has_column($t_al, 'nombre')) {
        $data['nombre'] = $nombre_full;
        $format[] = '%s';
      }
    
      if (self::table_has_column($t_al, 'apellido')) {
        $data['apellido'] = $apellidos;
        $format[] = '%s';
      }
    
      error_log('[UPDATE_ALUMNO] Data final a actualizar: ' . json_encode($data));
      error_log('═══════════════════════════════════════════════════════');
    
      $wpdb->update($t_al, $data, ['id' => $id], $format, ['%d']);

      // Multi-pertenencia: append en tablas relación (no borra relaciones anteriores).
      $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
      $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
      $has_rel_c = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c);
      $has_rel_a = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
      if ($has_rel_c && $curso_id) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $t_al_c (alumno_id, curso_id) VALUES (%d,%d)",
          $id,
          $curso_id
        ));
      }
      if ($has_rel_a && $aula_id) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $t_al_a (alumno_id, aula_id) VALUES (%d,%d)",
          $id,
          $aula_id
        ));
      }

      if (class_exists('NC_Rest_Asistencia') && ($curso_id || $aula_id || $carrera_id || $facultad_id)) {
        NC_Rest_Asistencia::auto_inscribir_alumno_curso_materias($id, $curso_id, $aula_id, true);
      }
      if ($aula_id && class_exists('NC_Rest_Examenes')) {
        NC_Rest_Examenes::auto_registrar_alumno_examenes_nu($id, $aula_id);
      }

      return self::ok(['updated' => true]);
    }

  public static function delete_alumno(WP_REST_Request $req) {
    if (!NC_Roles::user_is_admin()) {
      return self::err('No tienes permisos para eliminar alumnos.', 403);
    }
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $has_rel_c = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c);
    $has_rel_a = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
    $id = (int)$req['id'];
    $wpdb->update($t_al, ['activo' => 0], ['id' => $id], ['%d'], ['%d']);
    if ($has_rel_c) $wpdb->update($t_al_c, ['activo' => 0], ['alumno_id' => $id], ['%d'], ['%d']);
    if ($has_rel_a) $wpdb->update($t_al_a, ['activo' => 0], ['alumno_id' => $id], ['%d'], ['%d']);
    return self::ok(['deleted' => true]);
  }
  
  public static function list_conducta_alumno(WP_REST_Request $req) {
      global $wpdb;
    
      $alumno_id = (int)$req['id'];
    
      $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
      $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
      $t_usr  = $wpdb->users;
    
      // filtros opcionales
      $from = self::norm_str($req->get_param('from'));
      $to   = self::norm_str($req->get_param('to'));
    
      $where = "it.alumno_id=%d";
      $params = [$alumno_id];
    
      if ($from !== '') { $where .= " AND h.fecha >= %s"; $params[] = $from; }
      if ($to   !== '') { $where .= " AND h.fecha <= %s"; $params[] = $to; }
    
      // si existe columna activo en hdr, filtramos activos
      if (self::table_has_column($t_hdr, 'activo')) {
        $where .= " AND (h.activo=1 OR h.activo IS NULL)";
      }
    
      $sql = "
        SELECT
          h.id AS evaluacion_id,
          h.fecha,
          h.curso_id,
          h.aula_id,
          h.observacion_general,
          h.evaluador_user_id,
          u.display_name AS evaluador_nombre,
    
          it.id AS item_id,
          it.observacion AS observacion_item,
    
          it.responsabilidad_academica,
          it.respeto_convivencia,
          it.participacion_actitud,
          it.autocontrol_disciplina,
          it.autonomia_compromiso,
          it.presentacion_orden
    
        FROM $t_it it
        INNER JOIN $t_hdr h ON h.id = it.evaluacion_id
        LEFT JOIN $t_usr u ON u.ID = h.evaluador_user_id
        WHERE $where
        ORDER BY h.fecha DESC, it.id DESC
      ";
    
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    
      return self::ok($rows);
    }
    
    
    // ---------------- Conducta por alumno (historial + registrar) ----------------

    
  private static function fetch_alumno_conducta_rows(int $alumno_id, string $from, string $to, string $tipo, string $user_q): array {
    global $wpdb;

    $t_hdr   = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_items = $wpdb->prefix . 'conducta_evaluaciones_items';
    $t_users = $wpdb->users; // wp_users
    $t_legacy = $wpdb->prefix . 'conducta_evaluaciones';

    // ---------------- Nuevo esquema (hdr + items) ----------------
    $where = "i.alumno_id = %d";
    $params = [$alumno_id];

    if ($from !== '') { $where .= " AND h.fecha >= %s"; $params[] = $from; }
    if ($to   !== '') { $where .= " AND h.fecha <= %s"; $params[] = $to; }

    if (self::table_has_column($t_hdr, 'activo')) {
      $where .= " AND (h.activo=1 OR h.activo IS NULL)";
    }

    // filtro por usuario (display_name LIKE) opcional
    $user_like = null;
    if ($user_q !== '') {
      $user_like = '%' . $wpdb->esc_like($user_q) . '%';
    }

    $sql = "
      SELECT
        i.id AS item_id,
        h.id AS evaluacion_id,
        h.fecha,
        h.observacion_general,
        h.evaluador_user_id,
        u.display_name AS evaluador_nombre,
        i.observacion AS observacion_item,

        i.responsabilidad_academica,
        i.respeto_convivencia,
        i.participacion_actitud,
        i.autocontrol_disciplina,
        i.autonomia_compromiso,
        i.presentacion_orden,

        (SELECT COUNT(*) FROM $t_items i2 WHERE i2.evaluacion_id = h.id) AS items_count
      FROM $t_items i
      INNER JOIN $t_hdr h ON h.id = i.evaluacion_id
      LEFT JOIN $t_users u ON u.ID = h.evaluador_user_id
      WHERE $where
    ";

    if ($user_like !== null) {
      $sql .= " AND u.display_name LIKE %s";
      $params[] = $user_like;
    }

    $sql .= " ORDER BY h.fecha DESC, h.created_at DESC, i.id DESC LIMIT 500";

    $rows_hdrit = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    // ---------------- Esquema viejo (tabla única con c1..c6) ----------------
    $has_legacy = self::table_has_column($t_legacy, 'c1') && self::table_has_column($t_legacy, 'alumno_id');

    $rows_legacy = [];
    if ($has_legacy) {
      $where2 = "e.alumno_id = %d";
      $params2 = [$alumno_id];

      if ($from !== '') { $where2 .= " AND e.fecha >= %s"; $params2[] = $from; }
      if ($to   !== '') { $where2 .= " AND e.fecha <= %s"; $params2[] = $to; }

      $sql2 = "
        SELECT
          NULL AS item_id,
          e.id AS evaluacion_id,
          e.fecha,
          NULL AS observacion_general,
          e.evaluador_user_id,
          u.display_name AS evaluador_nombre,
          e.observacion AS observacion_item,

          e.c1 AS responsabilidad_academica,
          e.c2 AS respeto_convivencia,
          e.c3 AS participacion_actitud,
          e.c4 AS autocontrol_disciplina,
          e.c5 AS autonomia_compromiso,
          e.c6 AS presentacion_orden,

          1 AS items_count
        FROM $t_legacy e
        LEFT JOIN $t_users u ON u.ID = e.evaluador_user_id
        WHERE $where2
      ";

      if ($user_like !== null) {
        $sql2 .= " AND u.display_name LIKE %s";
        $params2[] = $user_like;
      }

      $sql2 .= " ORDER BY e.fecha DESC, e.created_at DESC, e.id DESC LIMIT 500";

      $rows_legacy = $wpdb->get_results($wpdb->prepare($sql2, $params2), ARRAY_A);
    }

    $out = array_merge($rows_hdrit ?: [], $rows_legacy ?: []);

    // normalizar / calcular tipo
    foreach ($out as &$r) {
      foreach (['responsabilidad_academica','respeto_convivencia','participacion_actitud','autocontrol_disciplina','autonomia_compromiso','presentacion_orden'] as $k) {
        if (!isset($r[$k]) || $r[$k] === null) $r[$k] = 0;
        else $r[$k] = (int)$r[$k];
      }
      $r['evaluador_user_id'] = isset($r['evaluador_user_id']) ? (int)$r['evaluador_user_id'] : null;

      $count = isset($r['items_count']) ? (int)$r['items_count'] : 1;
      $r['tipo'] = ($count > 1) ? 'grupal' : 'individual';
    }
    unset($r);

    // filtrar tipo post-merge
    if ($tipo === 'grupal' || $tipo === 'individual') {
      $out = array_values(array_filter($out, fn($r) => ($r['tipo'] ?? '') === $tipo));
    }

    // ordenar por fecha DESC + evaluacion_id DESC
    usort($out, function($a, $b) {
      $fa = $a['fecha'] ?? '';
      $fb = $b['fecha'] ?? '';
      if ($fa === $fb) {
        return ((int)($b['evaluacion_id'] ?? 0)) <=> ((int)($a['evaluacion_id'] ?? 0));
      }
      return strcmp($fb, $fa);
    });

    return $out;
  }

  public static function export_alumno_conducta(WP_REST_Request $req) {
    global $wpdb;

    $alumno_id = (int)$req['id'];

    $from = self::norm_str($req->get_param('from'));
    $to   = self::norm_str($req->get_param('to'));
    $tipo = self::norm_str($req->get_param('tipo')); // '', individual, grupal
    $user_q = self::norm_str($req->get_param('user'));
    if ($user_q === '') $user_q = self::norm_str($req->get_param('evaluador')); // display_name (parcial)

    $format = strtolower(self::norm_str($req->get_param('format')));
    if (!in_array($format, ['csv','html'], true)) $format = 'csv';

    $rows = self::fetch_alumno_conducta_rows($alumno_id, $from, $to, $tipo, $user_q);

    // datos del alumno para nombre de archivo / encabezado
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $al = $wpdb->get_row($wpdb->prepare("SELECT id, nombres, apellidos, ci FROM $t_al WHERE id=%d", $alumno_id), ARRAY_A);

    $safe_name = $al ? preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim(($al['nombres'] ?? '') . '_' . ($al['apellidos'] ?? ''))) : ('alumno_' . $alumno_id);
    $stamp = gmdate('Ymd-His');

    if ($format === 'html') {
      $title = 'Historial de conducta';
      $header = $al
        ? ('<div><strong>Alumno:</strong> ' . esc_html(trim(($al['nombres'] ?? '') . ' ' . ($al['apellidos'] ?? ''))) . ' &nbsp; <strong>CI:</strong> ' . esc_html($al['ci'] ?? '') . '</div>')
        : ('<div><strong>Alumno ID:</strong> ' . (int)$alumno_id . '</div>');

      $filters = [];
      if ($from !== '') $filters[] = 'Desde: ' . esc_html($from);
      if ($to !== '')   $filters[] = 'Hasta: ' . esc_html($to);
      if ($tipo !== '') $filters[] = 'Tipo: ' . esc_html($tipo);
      if ($user_q !== '') $filters[] = 'Usuario: ' . esc_html($user_q);
      $filters_html = $filters ? '<div class="meta"><strong>Filtros:</strong> ' . implode(' | ', $filters) . '</div>' : '';

      $rows_html = '';
      foreach ($rows as $r) {
        $rows_html .= '<tr>'
          . '<td>' . esc_html($r['fecha'] ?? '') . '</td>'
          . '<td>' . esc_html($r['tipo'] ?? '') . '</td>'
          . '<td>' . esc_html($r['evaluador_nombre'] ?? '') . '</td>'
          . '<td>' . esc_html($r['observacion_general'] ?? '') . '</td>'
          . '<td>' . esc_html($r['observacion_item'] ?? '') . '</td>'
          . '<td class="n">' . (int)($r['responsabilidad_academica'] ?? 0) . '</td>'
          . '<td class="n">' . (int)($r['respeto_convivencia'] ?? 0) . '</td>'
          . '<td class="n">' . (int)($r['participacion_actitud'] ?? 0) . '</td>'
          . '<td class="n">' . (int)($r['autocontrol_disciplina'] ?? 0) . '</td>'
          . '<td class="n">' . (int)($r['autonomia_compromiso'] ?? 0) . '</td>'
          . '<td class="n">' . (int)($r['presentacion_orden'] ?? 0) . '</td>'
          . '</tr>';
      }

      $html = '<!doctype html><html><head><meta charset="utf-8" />'
        . '<title>' . esc_html($title) . '</title>'
        . '<style>
            body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111}
            h1{font-size:18px;margin:0 0 8px}
            .meta{margin:6px 0 14px;color:#444;font-size:12px}
            table{width:100%;border-collapse:collapse;font-size:12px}
            th,td{border:1px solid #ddd;padding:6px;vertical-align:top}
            th{background:#f5f5f5;text-align:left}
            td.n{text-align:center}
            @media print{button{display:none} body{margin:0}}
          </style></head><body>'
        . '<button onclick="window.print()" style="padding:8px 10px;margin-bottom:10px">Imprimir / Guardar PDF</button>'
        . '<h1>' . esc_html($title) . '</h1>'
        . $header
        . $filters_html
        . '<table><thead><tr>'
        . '<th>Fecha</th><th>Tipo</th><th>Registró</th><th>Obs. general</th><th>Obs. alumno</th>'
        . '<th>Resp.Acad.</th><th>Respeto/Conv.</th><th>Part.Act.</th><th>Autocont.Disc.</th><th>Auton.Comp.</th><th>Pres.Orden</th>'
        . '</tr></thead><tbody>'
        . ($rows_html !== '' ? $rows_html : '<tr><td colspan="11">Sin registros.</td></tr>')
        . '</tbody></table></body></html>';

      // ✅ Output directo (no usar WP_REST_Response)
      if (ob_get_level()) {
        ob_end_clean();
        }
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-cache, must-revalidate');
      echo $html;
      exit;
      }

    // CSV (con BOM para Excel)
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");

    $headers = [
      'fecha','tipo','registrado_por','obs_general','obs_alumno',
      'responsabilidad_academica','respeto_convivencia','participacion_actitud','autocontrol_disciplina','autonomia_compromiso','presentacion_orden'
    ];
    fputcsv($fh, $headers);

    foreach ($rows as $r) {
      fputcsv($fh, [
        $r['fecha'] ?? '',
        $r['tipo'] ?? '',
        $r['evaluador_nombre'] ?? '',
        $r['observacion_general'] ?? '',
        $r['observacion_item'] ?? '',
        (int)($r['responsabilidad_academica'] ?? 0),
        (int)($r['respeto_convivencia'] ?? 0),
        (int)($r['participacion_actitud'] ?? 0),
        (int)($r['autocontrol_disciplina'] ?? 0),
        (int)($r['autonomia_compromiso'] ?? 0),
        (int)($r['presentacion_orden'] ?? 0),
      ]);
    }

    // ✅ Output directo
    if (ob_get_level()) {
      ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="conducta-' . $safe_name . '-' . $stamp . '.csv"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    
    rewind($fh);
    fpassthru($fh);
    fclose($fh);
    exit;
  }


public static function get_alumno_conducta(WP_REST_Request $req) {
    $alumno_id = (int)$req['id'];

    $from = self::norm_str($req->get_param('from'));
    $to   = self::norm_str($req->get_param('to'));
    $tipo = self::norm_str($req->get_param('tipo'));   // '', individual, grupal
    $user_q = self::norm_str($req->get_param('user'));
    if ($user_q === '') $user_q = self::norm_str($req->get_param('evaluador')); // display_name (parcial)

    $out = self::fetch_alumno_conducta_rows($alumno_id, $from, $to, $tipo, $user_q);

    return self::ok($out);
  }

public static function create_alumno_conducta(WP_REST_Request $req) {
      global $wpdb;
    
      $alumno_id = (int)$req['id'];
      $p = self::json_params($req);
    
      $t_hdr   = $wpdb->prefix . 'conducta_evaluaciones_hdr';
      $t_items = $wpdb->prefix . 'conducta_evaluaciones_items';
    
      $fecha = self::norm_str($p['fecha'] ?? '');
      if ($fecha === '') {
        // default hoy en formato YYYY-MM-DD
        $fecha = gmdate('Y-m-d');
      }
    
      $curso_id = self::int_or_null($p['curso_id'] ?? null);
      $aula_id  = self::int_or_null($p['aula_id'] ?? null);
    
      $obs_item = sanitize_text_field((string)($p['observacion_item'] ?? ''));
    
      $scores = $p['scores'] ?? [];
      $getScore = function($k) use ($scores) {
        $v = isset($scores[$k]) ? (int)$scores[$k] : 0;
        if ($v < 0) $v = 0;
        if ($v > 5) $v = 5;
        return $v;
      };
    
      $resp_acad = $getScore('responsabilidad_academica');
      $resp_conv = $getScore('respeto_convivencia');
      $part_act  = $getScore('participacion_actitud');
      $autodisc  = $getScore('autocontrol_disciplina');
      $autocomp  = $getScore('autonomia_compromiso');
      $presord   = $getScore('presentacion_orden');
    
      $user_id = get_current_user_id();
    
      // 1) Crear cabecera (hdr)
      $hdr_data = [
        'fecha' => $fecha,
        'curso_id' => $curso_id,
        'aula_id' => $aula_id,
        'observacion_general' => null,
        'creado_por' => $user_id,           // si existe en tu tabla
        'evaluador_user_id' => $user_id,
        'observacion' => null,
        'activo' => 1,
      ];
    
      // Algunos hostings tienen columnas diferentes, insertamos solo si existen
      // (si no existe, WordPress igual falla). Por eso filtramos por columnas reales.
      $hdr_cols = $wpdb->get_col("DESC $t_hdr", 0);
      $hdr_data = array_intersect_key($hdr_data, array_flip($hdr_cols));
    
      $hdr_formats = [];
      foreach ($hdr_data as $k => $v) {
        if ($v === null) $hdr_formats[] = '%s';
        else if (is_int($v)) $hdr_formats[] = '%d';
        else $hdr_formats[] = '%s';
      }
    
      $ok = $wpdb->insert($t_hdr, $hdr_data, $hdr_formats);
      if (!$ok) {
        return self::err('No se pudo crear cabecera de conducta.');
      }
    
      $evaluacion_id = (int)$wpdb->insert_id;
    
      // 2) Crear item (por alumno)
      $item_data = [
        'evaluacion_id' => $evaluacion_id,
        'alumno_id' => $alumno_id,
        'responsabilidad_academica' => $resp_acad,
        'respeto_convivencia' => $resp_conv,
        'participacion_actitud' => $part_act,
        'autocontrol_disciplina' => $autodisc,
        'autonomia_compromiso' => $autocomp,
        'presentacion_orden' => $presord,
        'observacion' => $obs_item,
      ];
    
      $item_cols = $wpdb->get_col("DESC $t_items", 0);
      $item_data = array_intersect_key($item_data, array_flip($item_cols));
    
      $item_formats = [];
      foreach ($item_data as $k => $v) {
        $item_formats[] = is_int($v) ? '%d' : '%s';
      }
    
      $ok2 = $wpdb->insert($t_items, $item_data, $item_formats);
      if (!$ok2) {
        // rollback simple: borrar hdr si no pudo crear item
        $wpdb->delete($t_hdr, ['id' => $evaluacion_id], ['%d']);
        return self::err('No se pudo crear el detalle de conducta del alumno.');
      }
    
      return self::ok([
        'created' => true,
        'evaluacion_id' => $evaluacion_id,
      ], 201);
    }

  /**
   * PATCH /conducta-items/:id — Actualizar un ítem de conducta (solo quien lo creó).
   */
  public static function update_conducta_item(WP_REST_Request $req) {
    global $wpdb;
    $item_id = (int) $req['id'];
    $t_hdr   = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it    = $wpdb->prefix . 'conducta_evaluaciones_items';
    $current  = get_current_user_id();
    if (!$current) return self::err('No autorizado.', 401);

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT i.id, i.evaluacion_id, h.evaluador_user_id FROM $t_it i INNER JOIN $t_hdr h ON h.id = i.evaluacion_id WHERE i.id = %d",
      $item_id
    ), ARRAY_A);
    if (!$row || (int)$row['evaluador_user_id'] !== $current) {
      return self::err('No podés editar un registro que no creaste.', 403);
    }

    $p = self::json_params($req);
    $scores = $p['scores'] ?? [];
    $get = function($k) use ($scores) {
      $v = isset($scores[$k]) ? (int)$scores[$k] : null;
      if ($v !== null) $v = max(0, min(5, $v));
      return $v;
    };
    $updates = [];
    $formats = [];
    foreach (['responsabilidad_academica','respeto_convivencia','participacion_actitud','autocontrol_disciplina','autonomia_compromiso','presentacion_orden'] as $col) {
      $val = $get($col);
      if ($val !== null) { $updates[$col] = $val; $formats[] = '%d'; }
    }
    if (array_key_exists('observacion_item', $p)) {
      $updates['observacion'] = self::norm_str($p['observacion_item'] ?? '');
      $formats[] = '%s';
    }
    if (!empty($updates)) {
      $wpdb->update($t_it, $updates, ['id' => $item_id], $formats, ['%d']);
    }
    $fecha = self::norm_str($p['fecha'] ?? '');
    if ($fecha !== '' && self::table_has_column($t_hdr, 'fecha')) {
      $wpdb->update($t_hdr, ['fecha' => $fecha], ['id' => (int)$row['evaluacion_id']], ['%s'], ['%d']);
    }
    return self::ok(['updated' => true, 'item_id' => $item_id]);
  }

  /**
   * PATCH /conducta-legacy/:id — Actualizar un registro legacy de conducta (solo quien lo creó).
   */
  public static function update_conducta_legacy(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t_legacy = $wpdb->prefix . 'conducta_evaluaciones';
    $current  = get_current_user_id();
    if (!$current) return self::err('No autorizado.', 401);

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, evaluador_user_id FROM $t_legacy WHERE id = %d",
      $id
    ), ARRAY_A);
    if (!$row || (int)$row['evaluador_user_id'] !== $current) {
      return self::err('No podés editar un registro que no creaste.', 403);
    }

    $p = self::json_params($req);
    $scores = $p['scores'] ?? [];
    $get = function($k) use ($scores) {
      $v = isset($scores[$k]) ? (int)$scores[$k] : null;
      if ($v !== null) $v = max(0, min(5, $v));
      return $v;
    };
    $cols = ['responsabilidad_academica' => 'c1', 'respeto_convivencia' => 'c2', 'participacion_actitud' => 'c3', 'autocontrol_disciplina' => 'c4', 'autonomia_compromiso' => 'c5', 'presentacion_orden' => 'c6'];
    $updates = [];
    $formats = [];
    foreach ($cols as $key => $col) {
      $val = $get($key);
      if ($val !== null) { $updates[$col] = $val; $formats[] = '%d'; }
    }
    if (array_key_exists('observacion_item', $p)) {
      $updates['observacion'] = self::norm_str($p['observacion_item'] ?? '');
      $formats[] = '%s';
    }
    $fecha = self::norm_str($p['fecha'] ?? '');
    if ($fecha !== '') { $updates['fecha'] = $fecha; $formats[] = '%s'; }
    if (!empty($updates)) {
      $wpdb->update($t_legacy, $updates, ['id' => $id], $formats, ['%d']);
    }
    return self::ok(['updated' => true, 'id' => $id]);
  }

  /**
   * GET /conducta/mis-registros?tipo=individual|grupal&search=&curso_id=&aula_id=&from=&to=
   * Lista registros de conducta creados por el usuario actual.
   */
  public static function mis_registros_conducta(WP_REST_Request $req) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado.', 401);

    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_cur  = $wpdb->prefix . 'conducta_cursos';

    $tipo     = self::norm_str($req->get_param('tipo')); // 'individual', 'grupal', o ''
    $search   = self::norm_str($req->get_param('search'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $aula_id  = self::int_or_null($req->get_param('aula_id'));
    $from     = self::norm_str($req->get_param('from'));
    $to       = self::norm_str($req->get_param('to'));

    $out = [];

    // Legacy: conducta_evaluaciones (siempre individual)
    if (self::table_has_column($t_eval, 'evaluador_user_id')) {
      $where = "e.evaluador_user_id = %d";
      $params = [$user_id];
      if ($from !== '') { $where .= " AND e.fecha >= %s"; $params[] = $from; }
      if ($to !== '')   { $where .= " AND e.fecha <= %s"; $params[] = $to; }
      if ($curso_id)    { $where .= " AND e.curso_id = %d"; $params[] = $curso_id; }
      if ($aula_id)     { $where .= " AND e.aula_id = %d";  $params[] = $aula_id; }
      if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= " AND (a.nombres LIKE %s OR a.apellidos LIKE %s OR a.ci LIKE %s)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
      }
      $sql = "SELECT e.id, e.id AS item_id, e.fecha, e.alumno_id, e.curso_id, e.aula_id, e.evaluador_user_id,
                     e.c1 AS responsabilidad_academica, e.c2 AS respeto_convivencia, e.c3 AS participacion_actitud,
                     e.c4 AS autocontrol_disciplina, e.c5 AS autonomia_compromiso, e.c6 AS presentacion_orden, e.observacion AS observacion_item,
                     a.nombres, a.apellidos, a.ci,
                     au.nombre AS aula_nombre, c.nombre AS curso_nombre
              FROM $t_eval e
              LEFT JOIN $t_al a ON a.id = e.alumno_id
              LEFT JOIN $t_aul au ON au.id = e.aula_id
              LEFT JOIN $t_cur c ON c.id = e.curso_id
              WHERE $where
              ORDER BY e.fecha DESC, e.id DESC";
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
      foreach ($rows as $r) {
        $r['tipo'] = 'individual';
        $r['evaluacion_id'] = $r['id'];
        $r['es_legacy'] = true;
        if ($tipo === 'grupal') continue;
        $out[] = $r;
      }
    }

    // Nuevo esquema: hdr + items
    if (self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'evaluacion_id')) {
      $where = "h.evaluador_user_id = %d";
      $params = [$user_id];
      if ($from !== '') { $where .= " AND h.fecha >= %s"; $params[] = $from; }
      if ($to !== '')   { $where .= " AND h.fecha <= %s"; $params[] = $to; }
      if ($curso_id)    { $where .= " AND h.curso_id = %d"; $params[] = $curso_id; }
      if ($aula_id)     { $where .= " AND h.aula_id = %d";  $params[] = $aula_id; }
      if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= " AND (a.nombres LIKE %s OR a.apellidos LIKE %s OR a.ci LIKE %s)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
      }
      $sql = "SELECT h.id AS evaluacion_id, h.fecha, i.id AS item_id, i.alumno_id, h.curso_id, h.aula_id, h.evaluador_user_id, h.observacion_general,
                     i.responsabilidad_academica, i.respeto_convivencia, i.participacion_actitud, i.autocontrol_disciplina, i.autonomia_compromiso, i.presentacion_orden, i.observacion AS observacion_item,
                     a.nombres, a.apellidos, a.ci,
                     au.nombre AS aula_nombre, c.nombre AS curso_nombre
              FROM $t_hdr h
              INNER JOIN $t_it i ON i.evaluacion_id = h.id
              LEFT JOIN $t_al a ON a.id = i.alumno_id
              LEFT JOIN $t_aul au ON au.id = h.aula_id
              LEFT JOIN $t_cur c ON c.id = h.curso_id
              WHERE $where
              ORDER BY h.fecha DESC, h.id DESC, i.id DESC";
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
      foreach ($rows as $r) {
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_it WHERE evaluacion_id=%d", (int)$r['evaluacion_id']));
        $r['tipo'] = $count > 1 ? 'grupal' : 'individual';
        $r['es_legacy'] = false;
        if ($tipo === 'individual' && $count > 1) continue;
        if ($tipo === 'grupal' && $count <= 1) continue;
        $out[] = $r;
      }
    }

    // Para tipo=grupal devolver agrupado por evaluacion_id (una fila por evaluación grupal)
    if ($tipo === 'grupal') {
      $by_hdr = [];
      foreach ($out as $r) {
        $eid = (int) $r['evaluacion_id'];
        if (!isset($by_hdr[$eid])) {
          $by_hdr[$eid] = [
            'evaluacion_id' => $eid,
            'fecha' => $r['fecha'],
            'aula_nombre' => $r['aula_nombre'] ?? '',
            'curso_nombre' => $r['curso_nombre'] ?? '',
            'cantidad_alumnos' => 0,
            'es_legacy' => !empty($r['es_legacy']),
          ];
        }
        $by_hdr[$eid]['cantidad_alumnos']++;
      }
      return self::ok(['items' => array_values($by_hdr)]);
    }

    return self::ok(['items' => $out]);
  }

  /**
   * GET /conducta/mis-registros/:id — detalle de una evaluación grupal (todos los ítems).
   */
  public static function mis_registros_conducta_detalle(WP_REST_Request $req) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado.', 401);

    $evaluacion_id = (int) $req['id'];
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_cur  = $wpdb->prefix . 'conducta_cursos';
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';

    if (!self::table_has_column($t_hdr, 'fecha')) {
      return self::err('Evaluación no encontrada.', 404);
    }

    $hdr = $wpdb->get_row($wpdb->prepare(
      "SELECT h.id, h.fecha, h.curso_id, h.aula_id, h.evaluador_user_id, h.observacion_general FROM $t_hdr h WHERE h.id = %d AND h.evaluador_user_id = %d",
      $evaluacion_id, $user_id
    ), ARRAY_A);
    if (!$hdr) {
      return self::err('No tenés permiso para ver esta evaluación o no existe.', 404);
    }

    $items = $wpdb->get_results($wpdb->prepare(
      "SELECT i.id AS item_id, i.alumno_id, i.responsabilidad_academica, i.respeto_convivencia, i.participacion_actitud,
              i.autocontrol_disciplina, i.autonomia_compromiso, i.presentacion_orden, i.observacion AS observacion_item,
              a.nombres, a.apellidos, a.ci
       FROM $t_it i
       LEFT JOIN $t_al a ON a.id = i.alumno_id
       WHERE i.evaluacion_id = %d
       ORDER BY a.apellidos, a.nombres",
      $evaluacion_id
    ), ARRAY_A);

    $aula_nombre = '';
    $curso_nombre = '';
    if ($hdr['aula_id']) {
      $aula_nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id = %d", $hdr['aula_id']));
    }
    if ($hdr['curso_id']) {
      $curso_nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_cur WHERE id = %d", $hdr['curso_id']));
    }
    $hdr['aula_nombre'] = $aula_nombre;
    $hdr['curso_nombre'] = $curso_nombre;

    return self::ok(['header' => $hdr, 'items' => $items]);
  }

  // ---------------- Evaluaciones (batch) ----------------

  public static function create_evaluacion_batch(WP_REST_Request $req) {
    global $wpdb;
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';

    $p = self::json_params($req);

    $fecha = self::norm_str($p['fecha'] ?? '');
    $curso_id = self::int_or_null($p['curso_id'] ?? null);
    $aula_id  = self::int_or_null($p['aula_id'] ?? null);
    $observacion = self::norm_str($p['observacion'] ?? '');

    $items = $p['items'] ?? [];
    if ($fecha === '' || !$aula_id || !$curso_id) return self::err('Campos obligatorios: fecha, curso_id, aula_id.');
    if (!is_array($items) || !count($items)) return self::err('items es obligatorio y debe ser un array.');

    $user_id = get_current_user_id();

    // Si hay múltiples items, usar el nuevo esquema (hdr + items) para marcarlo como grupal
    $use_new_schema = count($items) > 1 && self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'evaluacion_id');

    if ($use_new_schema) {
      // Crear cabecera (hdr)
      $hdr_data = [
        'fecha' => $fecha,
        'curso_id' => $curso_id,
        'aula_id' => $aula_id,
        'observacion_general' => $observacion,
        'evaluador_user_id' => $user_id,
      ];

      if (self::table_has_column($t_hdr, 'activo')) {
        $hdr_data['activo'] = 1;
      }

      $wpdb->insert($t_hdr, $hdr_data);
      $evaluacion_id = (int)$wpdb->insert_id;

      if (!$evaluacion_id) {
        return self::err('No se pudo crear la cabecera de la evaluación.');
      }

      // Crear items (uno por alumno)
      $inserted = 0;
      foreach ($items as $it) {
        if (!is_array($it)) continue;
        $alumno_id = self::int_or_null($it['alumno_id'] ?? null);
        if (!$alumno_id) continue;

        $c1 = (int)($it['responsabilidad_academica'] ?? $it['c1'] ?? 0);
        $c2 = (int)($it['respeto_convivencia'] ?? $it['c2'] ?? 0);
        $c3 = (int)($it['participacion_actitud'] ?? $it['c3'] ?? 0);
        $c4 = (int)($it['autocontrol_disciplina'] ?? $it['c4'] ?? 0);
        $c5 = (int)($it['autonomia_compromiso'] ?? $it['c5'] ?? 0);
        $c6 = (int)($it['presentacion_orden'] ?? $it['c6'] ?? 0);

        // clamp 0..5 (0 = correcta, 5 = inaceptable)
        $vals = [&$c1,&$c2,&$c3,&$c4,&$c5,&$c6];
        foreach ($vals as &$v) { $v = max(0, min(5, (int)$v)); }

        $item_data = [
          'evaluacion_id' => $evaluacion_id,
          'alumno_id' => $alumno_id,
          'responsabilidad_academica' => $c1,
          'respeto_convivencia' => $c2,
          'participacion_actitud' => $c3,
          'autocontrol_disciplina' => $c4,
          'autonomia_compromiso' => $c5,
          'presentacion_orden' => $c6,
        ];

        $item_cols = $wpdb->get_col("DESC $t_it", 0);
        $item_data = array_intersect_key($item_data, array_flip($item_cols));

        $wpdb->insert($t_it, $item_data);
        $inserted++;
      }

      if ($inserted === 0) {
        // Rollback: eliminar hdr si no se insertó ningún item
        $wpdb->delete($t_hdr, ['id' => $evaluacion_id], ['%d']);
        return self::err('No se pudo insertar ninguna evaluación (items inválidos).');
      }

      return self::ok(['id' => $evaluacion_id, 'inserted' => $inserted], 201);
    } else {
      // Esquema legacy: un registro por alumno (siempre individual)
      $inserted = 0;
      foreach ($items as $it) {
        if (!is_array($it)) continue;
        $alumno_id = self::int_or_null($it['alumno_id'] ?? null);
        if (!$alumno_id) continue;

        $c1 = (int)($it['responsabilidad_academica'] ?? $it['c1'] ?? 0);
        $c2 = (int)($it['respeto_convivencia'] ?? $it['c2'] ?? 0);
        $c3 = (int)($it['participacion_actitud'] ?? $it['c3'] ?? 0);
        $c4 = (int)($it['autocontrol_disciplina'] ?? $it['c4'] ?? 0);
        $c5 = (int)($it['autonomia_compromiso'] ?? $it['c5'] ?? 0);
        $c6 = (int)($it['presentacion_orden'] ?? $it['c6'] ?? 0);

        // clamp 0..5 (0 = correcta, 5 = inaceptable)
        $vals = [&$c1,&$c2,&$c3,&$c4,&$c5,&$c6];
        foreach ($vals as &$v) { $v = max(0, min(5, (int)$v)); }

        $wpdb->insert($t_eval, [
          'alumno_id' => $alumno_id,
          'curso_id' => $curso_id,
          'aula_id' => $aula_id,
          'fecha' => $fecha,
          'evaluador_user_id' => $user_id,
          'c1' => $c1,
          'c2' => $c2,
          'c3' => $c3,
          'c4' => $c4,
          'c5' => $c5,
          'c6' => $c6,
          'observacion' => $observacion,
        ], ['%d','%d','%d','%s','%d','%d','%d','%d','%d','%d','%d','%s']);

        $inserted++;
      }

      if ($inserted === 0) return self::err('No se pudo insertar ninguna evaluación (items inválidos).');

      return self::ok(['id' => time(), 'inserted' => $inserted], 201);
    }
  }
  
  
  
  public static function create_conducta_alumno(WP_REST_Request $req) {
      global $wpdb;
    
      $alumno_id = (int)$req['id'];
    
      $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
      $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
    
      $p = self::json_params($req);
    
      // fecha: default hoy
      $fecha = self::norm_str($p['fecha'] ?? '');
      if ($fecha === '') $fecha = current_time('Y-m-d');
    
      $curso_id = self::int_or_null($p['curso_id'] ?? null);
      $aula_id  = self::int_or_null($p['aula_id'] ?? null);
    
      $observacion_general = self::norm_str($p['observacion_general'] ?? '');
      $observacion_item    = self::norm_str($p['observacion_item'] ?? '');
    
      // scores (default 0)
      $scores = $p['scores'] ?? [];
      $resp_acad = (int)($scores['responsabilidad_academica'] ?? 0);
      $resp_conv = (int)($scores['respeto_convivencia'] ?? 0);
      $part_act  = (int)($scores['participacion_actitud'] ?? 0);
      $autodisc  = (int)($scores['autocontrol_disciplina'] ?? 0);
      $autocomp  = (int)($scores['autonomia_compromiso'] ?? 0);
      $presord   = (int)($scores['presentacion_orden'] ?? 0);
    
      $user_id = get_current_user_id();
      if (!$user_id) return self::err('No autorizado.');
    
      // 1) Insert HDR (evento)
      $hdr = [
        'fecha' => $fecha,
        'curso_id' => $curso_id,
        'aula_id' => $aula_id,
        'observacion_general' => $observacion_general,
        'evaluador_user_id' => $user_id,
      ];
    
      // si existe columna activo, la seteamos
      if (self::table_has_column($t_hdr, 'activo')) {
        $hdr['activo'] = 1;
      }
    
      // si tu hdr tiene 'creado_por' (según tu captura aparece), también lo seteamos
      if (self::table_has_column($t_hdr, 'creado_por')) {
        $hdr['creado_por'] = $user_id;
      }
    
      $wpdb->insert($t_hdr, $hdr);
    
      $evaluacion_id = (int)$wpdb->insert_id;
      if (!$evaluacion_id) return self::err('No se pudo crear la cabecera de la evaluación.');
    
      // 2) Insert ITEM (por alumno)
      $item = [
        'evaluacion_id' => $evaluacion_id,
        'alumno_id' => $alumno_id,
    
        'responsabilidad_academica' => $resp_acad,
        'respeto_convivencia' => $resp_conv,
        'participacion_actitud' => $part_act,
        'autocontrol_disciplina' => $autodisc,
        'autonomia_compromiso' => $autocomp,
        'presentacion_orden' => $presord,
    
        'observacion' => $observacion_item,
      ];
    
      $wpdb->insert($t_it, $item);
    
      $item_id = (int)$wpdb->insert_id;
      if (!$item_id) return self::err('Se creó la evaluación pero no se pudo registrar el alumno.');
    
      return self::ok([
        'evaluacion_id' => $evaluacion_id,
        'item_id' => $item_id,
      ], 201);
    }

  // ---------------- Dashboard y reportes por fecha ----------------

  /**
   * GET /dashboard/stats — estadísticas generales y por aula.
   */
  public static function dashboard_stats(WP_REST_Request $req) {
    global $wpdb;
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';

    $total_alumnos = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_al WHERE activo=1");
    $total_aulas   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_aul WHERE activo=1");

    $total_eval_legacy = 0;
    if (self::table_has_column($t_eval, 'id')) {
      $total_eval_legacy = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_eval");
    }
    $total_eval_hdr = 0;
    if (self::table_has_column($t_hdr, 'id')) {
      $total_eval_hdr = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_hdr");
    }
    $total_registros_items = 0;
    if (self::table_has_column($t_it, 'id')) {
      $total_registros_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_it");
    }
    $total_evaluaciones = $total_eval_legacy + $total_eval_hdr;

    // Alumnos evaluados en el mes actual
    $mes_actual_inicio = gmdate('Y-m-01');
    $mes_actual_fin = gmdate('Y-m-t');
    $alumnos_evaluados_mes = 0;
    
    // Contar alumnos únicos con evaluaciones en el mes actual (esquema nuevo)
    if (self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'alumno_id')) {
      $alumnos_evaluados_mes = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT i.alumno_id) FROM $t_it i 
         INNER JOIN $t_hdr h ON h.id = i.evaluacion_id 
         WHERE h.fecha >= %s AND h.fecha <= %s",
        $mes_actual_inicio, $mes_actual_fin
      ));
    }
    
    // Contar alumnos únicos con evaluaciones en el mes actual (esquema legacy)
    if (self::table_has_column($t_eval, 'fecha') && self::table_has_column($t_eval, 'alumno_id')) {
      $legacy_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT alumno_id) FROM $t_eval 
         WHERE fecha >= %s AND fecha <= %s",
        $mes_actual_inicio, $mes_actual_fin
      ));
      // Usar el máximo entre ambos esquemas (puede haber alumnos en ambos)
      $alumnos_evaluados_mes = max($alumnos_evaluados_mes, $legacy_count);
    }

    // Rango de fechas para "evaluaciones por aula" (mismo criterio que el gráfico: registros en el período)
    $from_por_aula = self::norm_str($req->get_param('from'));
    $to_por_aula   = self::norm_str($req->get_param('to'));
    if ($from_por_aula === '' || $to_por_aula === '') {
      $to_por_aula   = gmdate('Y-m-d');
      $from_por_aula = gmdate('Y-m-d', strtotime('-30 days'));
    }
    $por_aula = [];
    $aulas_rows = $wpdb->get_results("SELECT id, nombre FROM $t_aul WHERE activo=1 ORDER BY nombre", ARRAY_A);
    foreach ($aulas_rows as $a) {
      $aid = (int) $a['id'];
      $count_al = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_al WHERE activo=1 AND aula_id=%d", $aid));
      // Contar registros de conducta (no sesiones): legacy = 1 registro por fila; nuevo esquema = 1 registro por ítem
      $count_ev = 0;
      if (self::table_has_column($t_eval, 'aula_id') && self::table_has_column($t_eval, 'fecha')) {
        $count_ev += (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM $t_eval WHERE aula_id=%d AND fecha >= %s AND fecha <= %s",
          $aid, $from_por_aula, $to_por_aula
        ));
      }
      if (self::table_has_column($t_hdr, 'aula_id') && self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'evaluacion_id')) {
        $count_ev += (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM $t_it i INNER JOIN $t_hdr h ON h.id = i.evaluacion_id WHERE h.aula_id=%d AND h.fecha >= %s AND h.fecha <= %s",
          $aid, $from_por_aula, $to_por_aula
        ));
      }
      $por_aula[] = [
        'aula_id'   => $aid,
        'aula_nombre' => $a['nombre'],
        'alumnos'   => $count_al,
        'evaluaciones' => $count_ev,
      ];
    }

    return self::ok([
      'total_alumnos' => $total_alumnos,
      'total_aulas'   => $total_aulas,
      'total_evaluaciones' => $total_evaluaciones,
      'total_registros_conducta' => $total_eval_legacy + $total_registros_items,
      'alumnos_evaluados_mes' => $alumnos_evaluados_mes,
      'por_aula'      => $por_aula,
    ]);
  }

  /**
   * GET /dashboard/alumnos-evaluados?filter=evaluados|no_evaluados|todos&aula_id=&curso_id=
   * Lista de alumnos con su estado de evaluado en el mes actual
   */
  public static function dashboard_alumnos_evaluados(WP_REST_Request $req) {
    global $wpdb;
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_cur  = $wpdb->prefix . 'conducta_cursos';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';

    $filter = self::norm_str($req->get_param('filter')); // 'evaluados', 'no_evaluados', 'todos'
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $search   = self::norm_str($req->get_param('search')); // nombre, apellido o CI

    $use_rel = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c)
      && ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);

    // Mes actual
    $mes_actual_inicio = gmdate('Y-m-01');
    $mes_actual_fin = gmdate('Y-m-t');

    // Obtener todos los alumnos activos (y para instalaciones nuevas, sus cursos/grupos reales desde tablas de relación)
    $where = ["a.activo=1"];
    $params = [];

    $join_course = '';
    $join_aula = '';

    // Si no hay esquema nuevo, los filtros siguen dependiendo de columnas legacy.
    if (!$use_rel) {
      if ($aula_id) {
        $where[] = "a.aula_id=%d";
        $params[] = $aula_id;
      }
      if ($curso_id) {
        $where[] = "a.curso_id=%d";
        $params[] = $curso_id;
      }
    }

    // Campos a devolver (IDs simples + nombres agregados)
    if ($use_rel) {
      // Nombres agregados: mostramos TODOS los cursos/grupos activos del alumno.
      $sel_curso_nombre = "(SELECT GROUP_CONCAT(DISTINCT c2.nombre ORDER BY c2.nombre SEPARATOR ', ')
                             FROM $t_al_c ac2
                             INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                             WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1)";
      $sel_aula_nombre = "(SELECT GROUP_CONCAT(DISTINCT au2.nombre ORDER BY au2.nombre SEPARATOR ', ')
                            FROM $t_al_a ag2
                            INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                            WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1)";

      // IDs simples para el modal:
      // - Si el frontend filtró por curso/aula, devolvemos el ID filtrado (el alumno está dentro de ese filtro).
      // - Si no filtró, devolvemos el primer curso/grupo activo desde la relación.
      if ($curso_id) {
        $join_course = "INNER JOIN $t_al_c ac ON ac.alumno_id=a.id AND ac.curso_id=%d AND ac.activo=1
                         INNER JOIN $t_cur c_f ON c_f.id=ac.curso_id AND c_f.activo=1";
        $params[] = $curso_id;
        $sel_curso_id = "ac.curso_id AS curso_id";
      } else {
        $sel_curso_id = "(SELECT ac2.curso_id
                          FROM $t_al_c ac2
                          INNER JOIN $t_cur c2 ON c2.id=ac2.curso_id
                          WHERE ac2.alumno_id=a.id AND ac2.activo=1 AND c2.activo=1
                          ORDER BY c2.nombre ASC
                          LIMIT 1) AS curso_id";
      }

      if ($aula_id) {
        $join_aula = "INNER JOIN $t_al_a ag ON ag.alumno_id=a.id AND ag.aula_id=%d AND ag.activo=1
                      INNER JOIN $t_aul au_f ON au_f.id=ag.aula_id AND au_f.activo=1";
        $params[] = $aula_id;
        $sel_aula_id = "ag.aula_id AS aula_id";
      } else {
        $sel_aula_id = "(SELECT ag2.aula_id
                         FROM $t_al_a ag2
                         INNER JOIN $t_aul au2 ON au2.id=ag2.aula_id
                         WHERE ag2.alumno_id=a.id AND ag2.activo=1 AND au2.activo=1
                         ORDER BY au2.nombre ASC
                         LIMIT 1) AS aula_id";
      }
    } else {
      $sel_curso_id = "a.curso_id AS curso_id";
      $sel_aula_id  = "a.aula_id AS aula_id";
      $sel_curso_nombre = "c.nombre AS curso_nombre";
      $sel_aula_nombre  = "au.nombre AS aula_nombre";
    }

    $sql = "SELECT DISTINCT
              a.id, a.nombres, a.apellidos, a.ci,
              {$sel_curso_id},
              {$sel_aula_id},
              {$sel_curso_nombre} AS curso_nombre,
              {$sel_aula_nombre}  AS aula_nombre
            FROM $t_al a
            {$join_course}
            {$join_aula}
            " . (!$use_rel ? "LEFT JOIN $t_cur c ON c.id=a.curso_id LEFT JOIN $t_aul au ON au.id=a.aula_id" : "") . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.apellidos, a.nombres";

    $alumnos = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    // Obtener IDs de alumnos evaluados en el mes actual
    $evaluados_ids = [];
    
    // Esquema nuevo (hdr + items)
    if (self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'alumno_id')) {
      $eval_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT i.alumno_id FROM $t_it i 
         INNER JOIN $t_hdr h ON h.id = i.evaluacion_id 
         WHERE h.fecha >= %s AND h.fecha <= %s",
        $mes_actual_inicio, $mes_actual_fin
      ));
      $evaluados_ids = array_merge($evaluados_ids, array_map('intval', $eval_ids));
    }
    
    // Esquema legacy
    if (self::table_has_column($t_eval, 'fecha') && self::table_has_column($t_eval, 'alumno_id')) {
      $eval_ids_legacy = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT alumno_id FROM $t_eval 
         WHERE fecha >= %s AND fecha <= %s",
        $mes_actual_inicio, $mes_actual_fin
      ));
      $evaluados_ids = array_merge($evaluados_ids, array_map('intval', $eval_ids_legacy));
    }
    
    $evaluados_ids = array_unique($evaluados_ids);

    // Filtrar por búsqueda (nombre, apellido, CI)
    if ($search !== '') {
      $search_lower = mb_strtolower($search);
      $alumnos = array_filter($alumnos, function ($al) use ($search_lower) {
        $n = mb_strtolower(trim(($al['nombres'] ?? '') . ' ' . ($al['apellidos'] ?? '')));
        $ci = (string) ($al['ci'] ?? '');
        return (strpos($n, $search_lower) !== false) || (strpos($ci, $search_lower) !== false) ||
               (strpos(mb_strtolower($al['nombres'] ?? ''), $search_lower) !== false) ||
               (strpos(mb_strtolower($al['apellidos'] ?? ''), $search_lower) !== false);
      });
      $alumnos = array_values($alumnos);
    }

    // Marcar alumnos como evaluados o no
    $result = [];
    foreach ($alumnos as $al) {
      $alumno_id = (int) $al['id'];
      $evaluado = in_array($alumno_id, $evaluados_ids, true);
      
      // Aplicar filtro
      if ($filter === 'evaluados' && !$evaluado) continue;
      if ($filter === 'no_evaluados' && $evaluado) continue;
      
      $result[] = [
        'id' => $alumno_id,
        'nombres' => $al['nombres'] ?? '',
        'apellidos' => $al['apellidos'] ?? '',
        'ci' => $al['ci'] ?? '',
        'curso_id' => (int)($al['curso_id'] ?? 0),
        'aula_id' => (int)($al['aula_id'] ?? 0),
        'curso_nombre' => $al['curso_nombre'] ?? '',
        'aula_nombre' => $al['aula_nombre'] ?? '',
        'evaluado_mes' => $evaluado,
      ];
    }

    return self::ok([
      'alumnos' => $result,
      'mes_actual' => gmdate('Y-m'),
      'total_evaluados' => count($evaluados_ids),
      'total_no_evaluados' => count($alumnos) - count($evaluados_ids),
    ]);
  }

  /**
   * GET /dashboard/conducta-por-aula?from=&to= — distribución de puntuaciones 0-5 por aula.
   */
  public static function dashboard_conducta_por_aula(WP_REST_Request $req) {
    global $wpdb;
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';

    $from = self::norm_str($req->get_param('from'));
    $to   = self::norm_str($req->get_param('to'));
    if ($from === '' || $to === '') {
      $to   = gmdate('Y-m-d');
      $from = gmdate('Y-m-d', strtotime('-30 days'));
    }

    $out = [];
    $aula_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT id FROM $t_aul WHERE activo=1 ORDER BY nombre"
    ));
    foreach ($aula_ids as $aid) {
      $aid = (int) $aid;
      $nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d", $aid));
      $dist = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
      if (self::table_has_column($t_eval, 'fecha')) {
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT c1,c2,c3,c4,c5,c6 FROM $t_eval WHERE aula_id=%d AND fecha >= %s AND fecha <= %s",
          $aid, $from, $to
        ), ARRAY_A);
        foreach ($rows as $r) {
          $avg = round(( (int)($r['c1']??0) + (int)($r['c2']??0) + (int)($r['c3']??0) + (int)($r['c4']??0) + (int)($r['c5']??0) + (int)($r['c6']??0) ) / 6);
          $avg = max(0, min(5, (int)$avg));
          $dist[$avg]++;
        }
      }
      if (self::table_has_column($t_hdr, 'aula_id') && self::table_has_column($t_it, 'evaluacion_id')) {
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT i.responsabilidad_academica,i.respeto_convivencia,i.participacion_actitud,i.autocontrol_disciplina,i.autonomia_compromiso,i.presentacion_orden
           FROM $t_it i INNER JOIN $t_hdr h ON h.id=i.evaluacion_id
           WHERE h.aula_id=%d AND h.fecha >= %s AND h.fecha <= %s",
          $aid, $from, $to
        ), ARRAY_A);
        foreach ($rows as $r) {
          $avg = round(( (int)($r['responsabilidad_academica']??0) + (int)($r['respeto_convivencia']??0) + (int)($r['participacion_actitud']??0) + (int)($r['autocontrol_disciplina']??0) + (int)($r['autonomia_compromiso']??0) + (int)($r['presentacion_orden']??0) ) / 6);
          $avg = max(0, min(5, (int)$avg));
          $dist[$avg]++;
        }
      }
      $out[] = ['aula_id' => $aid, 'aula_nombre' => $nombre ?: '', 'distribucion' => $dist];
    }
    return self::ok(['from' => $from, 'to' => $to, 'por_aula' => $out]);
  }

  /**
   * GET /reportes/usuarios-registro — usuarios con permisos (para filtro "Registró"): Administrator, Director, Funcionarios Administrativos, Docentes, Funcionarios de Oficina.
   */
  public static function reportes_usuarios_registro(WP_REST_Request $req) {
    $users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]);
    $out = [];
    foreach ($users as $user) {
      if (!NC_Roles::user_can_access($user->ID)) continue;
      $out[] = [
        'id' => (int) $user->ID,
        'display_name' => $user->display_name ?: $user->user_login,
        'user_email' => $user->user_email ?: '',
      ];
    }
    return self::ok(['items' => $out]);
  }

  /**
   * GET /reportes/fecha?from=&to=&aula_id=&evaluador_user_id= — listado de evaluaciones en rango de fechas.
   */
  public static function reportes_por_fecha(WP_REST_Request $req) {
    global $wpdb;
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    $t_hdr  = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $t_it   = $wpdb->prefix . 'conducta_evaluaciones_items';
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';
    $t_usr  = $wpdb->users;

    $from = self::norm_str($req->get_param('from'));
    $to   = self::norm_str($req->get_param('to'));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $evaluador_user_id = self::int_or_null($req->get_param('evaluador_user_id'));

    if ($from === '' || $to === '') {
      return self::err('Parámetros from y to (YYYY-MM-DD) son obligatorios.');
    }

    $out = [];

    // Legacy: conducta_evaluaciones
    if (self::table_has_column($t_eval, 'fecha')) {
      $where = "e.fecha >= %s AND e.fecha <= %s";
      $params = [$from, $to];
      if ($aula_id) {
        $where .= " AND e.aula_id = %d";
        $params[] = $aula_id;
      }
      if ($evaluador_user_id) {
        $where .= " AND e.evaluador_user_id = %d";
        $params[] = $evaluador_user_id;
      }
      $sql = "SELECT e.id, e.fecha, e.alumno_id, e.curso_id, e.aula_id, e.evaluador_user_id,
                     e.c1, e.c2, e.c3, e.c4, e.c5, e.c6, e.observacion,
                     a.nombres, a.apellidos, a.ci,
                     au.nombre AS aula_nombre,
                     u.display_name AS evaluador_nombre
              FROM $t_eval e
              LEFT JOIN $t_al a ON a.id = e.alumno_id
              LEFT JOIN $t_aul au ON au.id = e.aula_id
              LEFT JOIN $t_usr u ON u.ID = e.evaluador_user_id
              WHERE $where
              ORDER BY e.fecha DESC, e.id DESC";
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
      foreach ($rows as $r) {
        $r['tipo'] = 'individual';
        $r['responsabilidad_academica'] = (int)($r['c1'] ?? 0);
        $r['respeto_convivencia'] = (int)($r['c2'] ?? 0);
        $r['participacion_actitud'] = (int)($r['c3'] ?? 0);
        $r['autocontrol_disciplina'] = (int)($r['c4'] ?? 0);
        $r['autonomia_compromiso'] = (int)($r['c5'] ?? 0);
        $r['presentacion_orden'] = (int)($r['c6'] ?? 0);
        $out[] = $r;
      }
    }

    // Nuevo esquema: hdr + items
    if (self::table_has_column($t_hdr, 'fecha') && self::table_has_column($t_it, 'evaluacion_id')) {
      $where = "h.fecha >= %s AND h.fecha <= %s";
      $params = [$from, $to];
      if ($aula_id) {
        $where .= " AND h.aula_id = %d";
        $params[] = $aula_id;
      }
      if ($evaluador_user_id) {
        $where .= " AND h.evaluador_user_id = %d";
        $params[] = $evaluador_user_id;
      }
      $sql = "SELECT h.id AS evaluacion_id, h.fecha, i.alumno_id, h.curso_id, h.aula_id, h.evaluador_user_id,
                     h.observacion_general, i.observacion AS observacion_item,
                     i.responsabilidad_academica, i.respeto_convivencia, i.participacion_actitud, i.autocontrol_disciplina, i.autonomia_compromiso, i.presentacion_orden,
                     a.nombres, a.apellidos, a.ci,
                     au.nombre AS aula_nombre,
                     u.display_name AS evaluador_nombre
              FROM $t_hdr h
              INNER JOIN $t_it i ON i.evaluacion_id = h.id
              LEFT JOIN $t_al a ON a.id = i.alumno_id
              LEFT JOIN $t_aul au ON au.id = h.aula_id
              LEFT JOIN $t_usr u ON u.ID = h.evaluador_user_id
              WHERE $where
              ORDER BY h.fecha DESC, h.id DESC, i.id DESC";
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
      foreach ($rows as $r) {
        $r['tipo'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_it WHERE evaluacion_id=%d", (int)$r['evaluacion_id'])) > 1 ? 'grupal' : 'individual';
        $out[] = $r;
      }
    }

    usort($out, function ($a, $b) {
      $fa = $a['fecha'] ?? '';
      $fb = $b['fecha'] ?? '';
      if ($fa !== $fb) return strcmp($fb, $fa);
      return ((int)($b['evaluacion_id'] ?? $b['id'] ?? 0)) <=> ((int)($a['evaluacion_id'] ?? $a['id'] ?? 0));
    });

    return self::ok($out);
  }

  /**
   * GET /reportes/export?from=&to=&aula_id=&format=csv|html — exportar reporte (Excel/PDF).
   */
  public static function reportes_export(WP_REST_Request $req) {
      $from = self::norm_str($req->get_param('from'));
      $to   = self::norm_str($req->get_param('to'));
      $aula_id = self::norm_str($req->get_param('aula_id'));
      $evaluador_user_id = $req->get_param('evaluador_user_id');
      $format = strtolower(self::norm_str($req->get_param('format')));
      
      // Soportar xlsx, csv, html, pdf
      if (!in_array($format, ['csv', 'html', 'pdf', 'xlsx'], true)) $format = 'csv';
    
      $req->set_param('from', $from);
      $req->set_param('to', $to);
      $req->set_param('aula_id', $aula_id !== '' ? (int)$aula_id : null);
      $req->set_param('evaluador_user_id', $evaluador_user_id !== '' && $evaluador_user_id !== null ? (int) $evaluador_user_id : null);
      $response = self::reportes_por_fecha($req);
      if (is_wp_error($response)) return $response;
      $data = $response->get_data();
      $rows = is_array($data) ? $data : [];
    
      $stamp = gmdate('Ymd-His');
      $filename = "reporte-conducta-{$from}-{$to}-{$stamp}";
      
      // ✅ Exportar a Excel
      if ($format === 'xlsx') {
        return self::export_to_excel($rows, $filename, $from, $to);
      }
      
      // ✅ Exportar a PDF (usando TCPDF)
      if ($format === 'pdf') {
        return self::generate_reporte_conducta_pdf($rows, $filename, $from, $to);
      }
    
      // ✅ HTML - Output directo (no usar WP_REST_Response)
      if ($format === 'html') {
        // Limpiar buffer
        if (ob_get_level()) {
          ob_end_clean();
        }
        
        $title = 'Reporte de Conducta por Fecha - Newton Centro de Estudios';
        $header = '<div style="margin-bottom:20px;padding:15px;background:#f5f5f5;border-radius:5px;">
          <p style="margin:5px 0;"><strong>Desde:</strong> ' . esc_html($from) . ' &nbsp; <strong>Hasta:</strong> ' . esc_html($to) . '</p>
          <p style="margin:5px 0;color:#666;font-size:0.9em;">Total de registros: ' . count($rows) . '</p>
        </div>';
        $headers = ['Fecha', 'Tipo', 'Alumno', 'CI', 'Aula', 'Registró', 'Resp.Acad.', 'Respeto/Conv.', 'Part.Act.', 'Autocont.Disc.', 'Auton.Comp.', 'Pres.Orden', 'Obs.'];
        $body = '';
        foreach ($rows as $r) {
          $nombre = trim(($r['nombres'] ?? '') . ' ' . ($r['apellidos'] ?? ''));
          $body .= '<tr>';
          $body .= '<td>' . esc_html($r['fecha'] ?? '') . '</td>';
          $body .= '<td>' . esc_html($r['tipo'] ?? '') . '</td>';
          $body .= '<td>' . esc_html($nombre) . '</td>';
          $body .= '<td>' . esc_html($r['ci'] ?? '') . '</td>';
          $body .= '<td>' . esc_html($r['aula_nombre'] ?? '') . '</td>';
          $body .= '<td>' . esc_html($r['evaluador_nombre'] ?? '') . '</td>';
          $body .= '<td class="n">' . (int)($r['responsabilidad_academica'] ?? 0) . '</td>';
          $body .= '<td class="n">' . (int)($r['respeto_convivencia'] ?? 0) . '</td>';
          $body .= '<td class="n">' . (int)($r['participacion_actitud'] ?? 0) . '</td>';
          $body .= '<td class="n">' . (int)($r['autocontrol_disciplina'] ?? 0) . '</td>';
          $body .= '<td class="n">' . (int)($r['autonomia_compromiso'] ?? 0) . '</td>';
          $body .= '<td class="n">' . (int)($r['presentacion_orden'] ?? 0) . '</td>';
          $body .= '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">' . esc_html($r['observacion'] ?? $r['observacion_item'] ?? '') . '</td>';
          $body .= '</tr>';
        }
        $tableBody = $body ?: '<tr><td colspan="13" style="text-align:center;padding:20px;color:#999;">Sin registros en el rango seleccionado.</td></tr>';
        
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>'
          . '<title>' . esc_html($title) . '</title>'
          . '<style>
            body{font-family:"Segoe UI",Arial,sans-serif;margin:20px;color:#222;background:#fff;}
            h1{font-size:1.5em;color:#1a73e8;margin-bottom:10px;border-bottom:2px solid #1a73e8;padding-bottom:10px;}
            table{width:100%;border-collapse:collapse;margin-top:12px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}
            th,td{border:1px solid #ddd;padding:10px;text-align:left;}
            th{background:#1a73e8;color:#fff;font-weight:600;text-transform:uppercase;font-size:0.85em;}
            td{background:#fff;}
            tr:nth-child(even) td{background:#f9f9f9;}
            tr:hover td{background:#f0f7ff;}
            td.n{text-align:center;font-weight:600;}
            .no-print{display:block;margin-bottom:15px;}
            button{background:#1a73e8;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;}
            button:hover{background:#1557b0;}
            @media print{
              .no-print{display:none !important;}
              body{margin:0;padding:10px;}
              table{font-size:9px;}
              th,td{padding:6px;}
              h1{font-size:1.2em;}
            }
          </style></head><body>'
          . '<div class="no-print"><button onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button></div>'
          . '<h1>' . esc_html($title) . '</h1>' . $header
          . '<table><thead><tr>' . implode('', array_map(function ($h) { return '<th>' . esc_html($h) . '</th>'; }, $headers)) . '</tr></thead><tbody>'
          . $tableBody . '</tbody></table></body></html>';
        
        // ✅ Output directo
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo $html;
        exit;
      }
    
      // ✅ CSV - Output directo
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
      header('Cache-Control: max-age=0');
      header('Pragma: no-cache');
    
      $output = fopen('php://output', 'w');
      
      // BOM UTF-8 para que Excel lo abra bien
      fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
      
      $headers = ['Fecha', 'Aula', 'Alumno', 'CI', 'Tipo', 'Responsabilidad Académica', 'Respeto y Convivencia', 'Participación y Actitud', 'Autocontrol y Disciplina', 'Autonomía y Compromiso', 'Presentación y Orden', 'Observación', 'Registrado por'];
      fputcsv($output, $headers);
      
      foreach ($rows as $r) {
        $nombre = trim(($r['nombres'] ?? '') . ' ' . ($r['apellidos'] ?? ''));
        fputcsv($output, [
          $r['fecha'] ?? '',
          $r['aula_nombre'] ?? '',
          $nombre,
          $r['ci'] ?? '',
          $r['tipo'] ?? '',
          (int)($r['responsabilidad_academica'] ?? 0),
          (int)($r['respeto_convivencia'] ?? 0),
          (int)($r['participacion_actitud'] ?? 0),
          (int)($r['autocontrol_disciplina'] ?? 0),
          (int)($r['autonomia_compromiso'] ?? 0),
          (int)($r['presentacion_orden'] ?? 0),
          $r['observacion'] ?? $r['observacion_item'] ?? '',
          $r['evaluador_nombre'] ?? '',
        ]);
      }
      
      fclose($output);
      exit;
    }

  
  /**
     * POST /alumnos/:id/foto - Sube una foto para un alumno
     * 
     * @param WP_REST_Request $req Request con el ID del alumno y el archivo
     * @return WP_REST_Response|WP_Error
     */
    public static function upload_alumno_foto(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para modificar fotos de alumnos.', 403);
      }
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $alumno_id = (int)$req['id'];
    
      // Verificar que el alumno existe
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t_al WHERE id = %d AND activo = 1",
        $alumno_id
      ));
    
      if (!$exists) {
        return self::err('Alumno no encontrado', 404);
      }
    
      // Obtener el archivo subido
      $files = $req->get_file_params();
      
      if (empty($files['foto'])) {
        return self::err('No se recibió ningún archivo. Usa el campo "foto" para subir la imagen.');
      }
    
      $file = $files['foto'];
    
      // Validar tipo de archivo
      $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      $file_type = $file['type'];
      
      if (!in_array($file_type, $allowed_types)) {
        return self::err('Tipo de archivo no permitido. Usa: JPG, PNG, GIF o WEBP.');
      }
    
      // Validar tamaño (máximo 5MB)
      $max_size = 5 * 1024 * 1024; // 5MB
      if ($file['size'] > $max_size) {
        return self::err('El archivo es demasiado grande. Máximo 5MB.');
      }
    
      // Cargar dependencias de WordPress para manejo de archivos
      if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
      }
    
      // Configurar el upload
      $upload_overrides = [
        'test_form' => false,
        'unique_filename_callback' => function($dir, $name, $ext) use ($alumno_id) {
          // Crear nombre único: alumno_{id}_{timestamp}{extension}
          return 'alumno_' . $alumno_id . '_' . time() . $ext;
        }
      ];
    
      // Subir el archivo
      $uploaded_file = wp_handle_upload($file, $upload_overrides);
    
      if (isset($uploaded_file['error'])) {
        return self::err('Error al subir archivo: ' . $uploaded_file['error']);
      }
    
      // Obtener la URL del archivo subido
      $foto_url = $uploaded_file['url'];
    
      // Antes de guardar la nueva foto, eliminar la foto anterior si existe
      $foto_anterior = $wpdb->get_var($wpdb->prepare(
        "SELECT foto_url FROM $t_al WHERE id = %d",
        $alumno_id
      ));
    
      if ($foto_anterior && !empty($foto_anterior)) {
        self::delete_foto_file($foto_anterior);
      }
    
      // Actualizar la BD con la nueva URL
      $updated = $wpdb->update(
        $t_al,
        ['foto_url' => $foto_url],
        ['id' => $alumno_id],
        ['%s'],
        ['%d']
      );
    
      if ($updated === false) {
        // Si falla el update, intentar eliminar el archivo recién subido
        self::delete_foto_file($foto_url);
        return self::err('Error al actualizar la base de datos.');
      }
    
      return self::ok([
        'foto_url' => $foto_url,
        'message' => 'Foto actualizada correctamente'
      ]);
    }
    
    /**
     * DELETE /alumnos/:id/foto - Elimina la foto de un alumno
     * 
     * @param WP_REST_Request $req Request con el ID del alumno
     * @return WP_REST_Response
     */
    public static function delete_alumno_foto(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para eliminar fotos de alumnos.', 403);
      }
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $alumno_id = (int)$req['id'];
    
      // Obtener la URL actual
      $foto_url = $wpdb->get_var($wpdb->prepare(
        "SELECT foto_url FROM $t_al WHERE id = %d AND activo = 1",
        $alumno_id
      ));
    
      if (!$foto_url) {
        return self::ok(['message' => 'El alumno no tiene foto asignada']);
      }
    
      // Eliminar el archivo físico
      self::delete_foto_file($foto_url);
    
      // Actualizar BD
      $wpdb->update(
        $t_al,
        ['foto_url' => null],
        ['id' => $alumno_id],
        ['%s'],
        ['%d']
      );
    
      return self::ok(['message' => 'Foto eliminada correctamente']);
    }
    
    // ============================================================================
    // FUNCIONES AUXILIARES
    // ============================================================================
    
    /**
     * Elimina el archivo físico de una foto dado su URL
     * 
     * @param string $foto_url URL de la foto a eliminar
     * @return void
     */
    private static function delete_foto_file($foto_url) {
      if (empty($foto_url)) return;
    
      // Obtener el path del archivo desde la URL
      $upload_dir = wp_upload_dir();
      $base_url = $upload_dir['baseurl'];
      $base_dir = $upload_dir['basedir'];
    
      // Si la URL es del sitio, obtener el path
      if (strpos($foto_url, $base_url) === 0) {
        $file_path = str_replace($base_url, $base_dir, $foto_url);
        if (file_exists($file_path)) {
          @unlink($file_path);
        }
      }
    }
    
    /**
     * Convierte imagen base64 a archivo y retorna URL
     * (Opcional - solo si quieres soportar base64 además de uploads)
     * 
     * @param string $base64_string String base64 de la imagen
     * @return string|null URL del archivo guardado o null si falla
     */
    private static function save_base64_image($base64_string) {
      // Extraer datos
      if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_string, $matches)) {
        return null;
      }
    
      $image_type = $matches[1];
      $image_data = base64_decode($matches[2]);
    
      if ($image_data === false) {
        return null;
      }
    
      // Validar tipo de imagen
      $allowed_types = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
      if (!in_array(strtolower($image_type), $allowed_types)) {
        return null;
      }
    
      // Generar nombre de archivo único
      $filename = 'alumno_' . uniqid() . '_' . time() . '.' . $image_type;
    
      // Obtener directorio de uploads
      $upload_dir = wp_upload_dir();
      $upload_path = $upload_dir['path'] . '/' . $filename;
      $upload_url = $upload_dir['url'] . '/' . $filename;
    
      // Guardar archivo
      if (file_put_contents($upload_path, $image_data)) {
        return $upload_url;
      }
    
      return null;
    }
    
    
    /**
     * POST /alumnos/import - Importa alumnos desde archivo Excel
     * 
     * Body params (FormData):
     * - excel: Archivo Excel (.xlsx, .xls)
     * - curso_id: (opcional) ID del curso por defecto
     * - aula_id: (opcional) ID del aula por defecto
     * - skip_duplicates: (opcional, default true) Omitir CIs duplicados
     * - update_existing: (opcional, default false) Actualizar alumnos existentes
     * 
     * @param WP_REST_Request $req
     * @return WP_REST_Response|WP_Error
     */
    public static function import_alumnos_excel(WP_REST_Request $req) {
      global $wpdb;
      
      // Verificar que se subió un archivo
      $files = $req->get_file_params();
      
      if (empty($files['excel'])) {
        return self::err('No se recibió ningún archivo Excel. Usa el campo "excel".');
      }
    
      $file = $files['excel'];
    
      // Validar que sea Excel
      $allowed_types = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel.sheet.macroEnabled.12'
      ];
      
      $file_type = $file['type'];
      $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      
      if (!in_array($file_type, $allowed_types) && !in_array($file_ext, ['xls', 'xlsx', 'xlsm'])) {
        return self::err('Tipo de archivo no permitido. Usa archivos Excel (.xlsx, .xls).');
      }
    
      // Procesar el archivo
      try {
        $result = self::process_excel_import($file['tmp_name'], $req);
        return self::ok($result);
      } catch (Exception $e) {
        return self::err('Error al procesar archivo: ' . $e->getMessage());
      }
    }
    
    // ============================================================================
    // FUNCIONES AUXILIARES
    // ============================================================================
    
    /**
     * Procesa el archivo Excel y retorna resultados de importación
     * 
     * @param string $file_path Path temporal del archivo Excel
     * @param WP_REST_Request $req Request original
     * @return array Resultados de la importación
     * @throws Exception Si hay error al procesar el archivo
     */
    private static function process_excel_import($file_path, WP_REST_Request $req) {
      global $wpdb;
      
      // Verificar si PHPSpreadsheet está instalado
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        throw new Exception('PHPSpreadsheet no está instalado. Ejecuta: composer require phpoffice/phpspreadsheet');
      }
      
      require_once $autoload_path;
      
      // Cargar el archivo Excel
      try {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($file_path);
      } catch (Exception $e) {
        // Intentar con formato XLS si XLSX falla
        try {
          $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
          $spreadsheet = $reader->load($file_path);
        } catch (Exception $e2) {
          throw new Exception('No se pudo leer el archivo Excel. Asegúrate de que sea un formato válido.');
        }
      }
      
      $worksheet = $spreadsheet->getActiveSheet();
      $rows = $worksheet->toArray();
    
      if (empty($rows)) {
        throw new Exception('El archivo Excel está vacío');
      }
    
      // Primera fila debe contener los encabezados
      $headers = array_shift($rows);
      
      // Mapeo de columnas (buscar índices)
      $col_map = self::map_excel_columns($headers);
      
      if (!$col_map['valid']) {
        throw new Exception('No se encontraron las columnas requeridas: ' . implode(', ', $col_map['missing']));
      }
    
      // Obtener parámetros opcionales del request
      $params = $req->get_body_params();
      $curso_id_default = isset($params['curso_id']) ? (int)$params['curso_id'] : null;
      $aula_id_default = isset($params['aula_id']) ? (int)$params['aula_id'] : null;
      $skip_duplicates = isset($params['skip_duplicates']) ? filter_var($params['skip_duplicates'], FILTER_VALIDATE_BOOLEAN) : true;
      $update_existing = isset($params['update_existing']) ? filter_var($params['update_existing'], FILTER_VALIDATE_BOOLEAN) : false;
    
      $results = [
        'total_rows' => count($rows),
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => []
      ];
    
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_fac = $wpdb->prefix . 'conducta_facultades';
      $t_car = $wpdb->prefix . 'conducta_carreras';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
    
      // Cache para facultades, carreras, cursos y aulas
      $facultad_cache = [];
      $carrera_cache = [];
      $curso_cache = [];
      $aula_cache = [];
    
      foreach ($rows as $row_num => $row) {
        $line_num = $row_num + 2; // +2 porque array_shift quitó 1 y empezamos en 1
        
        try {
          // Extraer datos
          $apellidos = trim((string)($row[$col_map['apellidos']] ?? ''));
          $nombres = trim((string)($row[$col_map['nombres']] ?? ''));
          $ci = trim((string)($row[$col_map['ci']] ?? ''));
          $carrera_nombre = trim((string)($row[$col_map['carrera']] ?? ''));
          $facultad_nombre = trim((string)($row[$col_map['facultad']] ?? ''));
          $aula_nombre = isset($col_map['aula']) ? trim((string)($row[$col_map['aula']] ?? '')) : '';
          $curso_nombre = isset($col_map['curso']) ? trim((string)($row[$col_map['curso']] ?? '')) : '';
    
          // Validar datos obligatorios
          if (empty($nombres) || empty($apellidos) || empty($ci)) {
            $results['errors'][] = [
              'line' => $line_num,
              'message' => 'Faltan datos obligatorios (nombres, apellidos o CI)'
            ];
            $results['skipped']++;
            continue;
          }
    
          // Normalizar CI (solo números)
          $ci = preg_replace('/[^0-9]/', '', $ci);
          
          if (empty($ci)) {
            $results['errors'][] = [
              'line' => $line_num,
              'message' => 'CI inválido'
            ];
            $results['skipped']++;
            continue;
          }
    
          // Verificar si ya existe
          $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $t_al WHERE ci = %s",
            $ci
          ));
    
          if ($existing) {
            if ($skip_duplicates && !$update_existing) {
              $results['skipped']++;
              continue;
            }
          }
    
          // Buscar o crear facultad
          $facultad_id = null;
          if (!empty($facultad_nombre)) {
            if (isset($facultad_cache[$facultad_nombre])) {
              $facultad_id = $facultad_cache[$facultad_nombre];
            } else {
              $fac = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_fac WHERE nombre = %s",
                $facultad_nombre
              ));
              
              if ($fac) {
                $facultad_id = (int)$fac->id;
              } else {
                // Crear facultad
                $wpdb->insert($t_fac, [
                  'nombre' => $facultad_nombre,
                  'activo' => 1
                ], ['%s', '%d']);
                $facultad_id = (int)$wpdb->insert_id;
              }
              
              $facultad_cache[$facultad_nombre] = $facultad_id;
            }
          }
    
          // Buscar o crear carrera
          $carrera_id = null;
          if (!empty($carrera_nombre) && $facultad_id) {
            $cache_key = $facultad_id . '|' . $carrera_nombre;
            
            if (isset($carrera_cache[$cache_key])) {
              $carrera_id = $carrera_cache[$cache_key];
            } else {
              $car = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_car WHERE nombre = %s AND facultad_id = %d",
                $carrera_nombre,
                $facultad_id
              ));
              
              if ($car) {
                $carrera_id = (int)$car->id;
              } else {
                // Crear carrera
                $wpdb->insert($t_car, [
                  'facultad_id' => $facultad_id,
                  'nombre' => $carrera_nombre,
                  'activo' => 1
                ], ['%d', '%s', '%d']);
                $carrera_id = (int)$wpdb->insert_id;
              }
              
              $carrera_cache[$cache_key] = $carrera_id;
            }
          }
    
          // Buscar o crear curso
          $curso_id = $curso_id_default;
          if (!empty($curso_nombre)) {
            if (isset($curso_cache[$curso_nombre])) {
              $curso_id = $curso_cache[$curso_nombre];
            } else {
              $cur = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_cur WHERE nombre = %s AND activo = 1",
                $curso_nombre
              ));
              
              if ($cur) {
                $curso_id = (int)$cur->id;
              } else {
                // Crear curso
                $wpdb->insert($t_cur, [
                  'nombre' => $curso_nombre,
                  'facultad_id' => $facultad_id,
                  'carrera_id' => $carrera_id,
                  'activo' => 1
                ], ['%s', '%d', '%d', '%d']);
                $curso_id = (int)$wpdb->insert_id;
              }
              
              $curso_cache[$curso_nombre] = $curso_id;
            }
          }
    
          // Buscar o crear aula
          $aula_id = $aula_id_default;
          if (!empty($aula_nombre)) {
            $cache_key = ($curso_id ? $curso_id : '0') . '|' . $aula_nombre;
            
            if (isset($aula_cache[$cache_key])) {
              $aula_id = $aula_cache[$cache_key];
            } else {
              $aul = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_aul WHERE nombre = %s AND activo = 1",
                $aula_nombre
              ));
              
              if ($aul) {
                $aula_id = (int)$aul->id;
              } else {
                // Crear aula
                $wpdb->insert($t_aul, [
                  'nombre' => $aula_nombre,
                  'curso_id' => $curso_id,
                  'facultad_id' => $facultad_id,
                  'carrera_id' => $carrera_id,
                  'activo' => 1
                ], ['%s', '%d', '%d', '%d', '%d']);
                $aula_id = (int)$wpdb->insert_id;
              }
              
              $aula_cache[$cache_key] = $aula_id;
            }
          }
    
          // Preparar datos del alumno
          $alumno_data = [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'ci' => $ci,
            'curso_id' => $curso_id,
            'aula_id' => $aula_id,
            'facultad_id' => $facultad_id,
            'carrera_id' => $carrera_id,
            'activo' => 1
          ];
          
          $formats = ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d'];
    
          // Insertar o actualizar
          if ($existing && $update_existing) {
            $wpdb->update(
              $t_al,
              $alumno_data,
              ['id' => $existing->id],
              $formats,
              ['%d']
            );
            if (class_exists('NC_Rest_Asistencia')) {
              NC_Rest_Asistencia::sync_alumno_materias((int) $existing->id, $curso_id, $aula_id, true);
            }
            $results['updated']++;
          } else if (!$existing) {
            $wpdb->insert($t_al, $alumno_data, $formats);
            $new_alumno_id = (int) $wpdb->insert_id;
            if ($new_alumno_id > 0 && class_exists('NC_Rest_Asistencia')) {
              NC_Rest_Asistencia::sync_alumno_materias($new_alumno_id, $curso_id, $aula_id, true);
            }
            $results['imported']++;
          }
    
        } catch (Exception $e) {
          $results['errors'][] = [
            'line' => $line_num,
            'message' => $e->getMessage()
          ];
          $results['skipped']++;
        }
      }
    
      return $results;
    }
    
    /**
     * Mapea las columnas del Excel a los campos necesarios
     * 
     * @param array $headers Array de encabezados del Excel
     * @return array Mapeo de columnas con índices
     */
    private static function map_excel_columns($headers) {
      $required = [
        'apellidos' => ['Apellidos', 'apellidos', 'APELLIDOS', 'Apellido'],
        'nombres' => ['Nombres:', 'Nombres', 'nombres', 'NOMBRES', 'Nombre'],
        'ci' => ['Número de cédula', 'CI', 'Cedula', 'Cédula', 'CEDULA', 'ci', 'Número de Cédula'],
      ];
      
      $optional = [
        'carrera' => ['¿Qué carrera desea seguir?', 'Carrera', 'carrera', 'CARRERA'],
        'facultad' => ['¿En qué universidad desea estudiar?', 'Universidad', 'Facultad', 'universidad', 'facultad'],
        'aula' => ['Aula', 'aula', 'AULA', 'Aulas', 'aulas'],
        'curso' => ['Curso', 'curso', 'CURSO', 'Cursos', 'cursos']
      ];
    
      $map = [];
      $found = [];
    
      foreach ($headers as $idx => $header) {
        $header = trim($header);
        
        // Buscar campos requeridos
        foreach ($required as $field => $variations) {
          foreach ($variations as $variation) {
            if (strcasecmp($header, $variation) === 0) {
              $map[$field] = $idx;
              $found[$field] = true;
              break 2;
            }
          }
        }
        
        // Buscar campos opcionales
        foreach ($optional as $field => $variations) {
          foreach ($variations as $variation) {
            if (strcasecmp($header, $variation) === 0) {
              $map[$field] = $idx;
              $found[$field] = true;
              break 2;
            }
          }
        }
      }
    
      $missing = [];
      foreach (array_keys($required) as $field) {
        if (!isset($found[$field])) {
          $missing[] = $field;
        }
      }
    
      return array_merge([
        'valid' => empty($missing),
        'missing' => $missing,
      ], $map);
    }
    
    /**
     * GET /alumnos/:id/export/csv - Exporta rendimiento de alumno en CSV
     * 
     * @param WP_REST_Request $req
     * @return void (genera download directo)
     */
    public static function export_alumno_csv(WP_REST_Request $req) {
      global $wpdb;
      $alumno_id = (int)$req['id'];
      
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
    
      $alumno = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, c.nombre AS curso_nombre, au.nombre AS aula_nombre 
         FROM $t_al a
         LEFT JOIN $t_cur c ON a.curso_id = c.id
         LEFT JOIN $t_aul au ON a.aula_id = au.id
         WHERE a.id = %d",
        $alumno_id
      ), ARRAY_A);
    
      if (!$alumno) {
        wp_die('Alumno no encontrado', 'Error', ['response' => 404]);
      }
    
      $evaluaciones = $wpdb->get_results($wpdb->prepare(
        "SELECT fecha, c1, c2, c3, c4, c5, c6, observacion
         FROM $t_eval
         WHERE alumno_id = %d
         ORDER BY fecha DESC",
        $alumno_id
      ), ARRAY_A);
    
      // Limpiar cualquier output previo
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      // Headers con codificación UTF-8
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="rendimiento_' . sanitize_file_name($alumno['nombres'] . '_' . $alumno['apellidos']) . '_' . date('Y-m-d') . '.csv"');
      header('Pragma: no-cache');
      header('Expires: 0');
    
      $output = fopen('php://output', 'w');
      
      // BOM UTF-8 removido - causa problemas de lectura en algunos programas
      // Si necesitas BOM para Excel, descomenta la siguiente línea:
      // fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
      // Información del alumno
      fputcsv($output, ['Reporte de Rendimiento - Newton OPM']);
      fputcsv($output, ['']);
      fputcsv($output, ['Alumno', $alumno['nombres'] . ' ' . $alumno['apellidos']]);
      fputcsv($output, ['CI', $alumno['ci']]);
      fputcsv($output, ['Curso', $alumno['curso_nombre'] ?? 'N/A']);
      fputcsv($output, ['Aula', $alumno['aula_nombre'] ?? 'N/A']);
      fputcsv($output, ['Fecha de Reporte', date('Y-m-d H:i:s')]);
      fputcsv($output, ['']);
    
      // Encabezados sin caracteres problemáticos
      fputcsv($output, [
        'Fecha',
        'Responsabilidad Académica',
        'Respeto y Convivencia',
        'Participación y Actitud',
        'Autocontrol y Disciplina',
        'Autonomía y Compromiso',
        'Presentación y Orden',
        'Promedio',
        'Observaciones'
      ]);
    
      // Datos
      $total_promedio = 0;
      $count_eval = 0;
    
      foreach ($evaluaciones as $eval) {
        $promedio = ($eval['c1'] + $eval['c2'] + $eval['c3'] + $eval['c4'] + $eval['c5'] + $eval['c6']) / 6;
        $total_promedio += $promedio;
        $count_eval++;
    
        fputcsv($output, [
          $eval['fecha'],
          $eval['c1'],
          $eval['c2'],
          $eval['c3'],
          $eval['c4'],
          $eval['c5'],
          $eval['c6'],
          number_format($promedio, 2),
          $eval['observacion'] ?? ''
        ]);
      }
    
      // Resumen
      fputcsv($output, ['']);
      fputcsv($output, ['RESUMEN']);
      fputcsv($output, ['Total de Evaluaciones', $count_eval]);
      if ($count_eval > 0) {
        fputcsv($output, ['Promedio General', number_format($total_promedio / $count_eval, 2)]);
      }
    
      fclose($output);
      exit;
    }
    
    // ============================================================================
    // EXPORTACIÓN POR AULA
    // ============================================================================
    
    public static function export_aula_csv(WP_REST_Request $req) {
      global $wpdb;
      $aula_id = (int)$req['id'];
      
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
    
      $aula = $wpdb->get_row($wpdb->prepare(
        "SELECT a.nombre AS aula_nombre, c.nombre AS curso_nombre
         FROM $t_aul a
         LEFT JOIN $t_cur c ON a.curso_id = c.id
         WHERE a.id = %d",
        $aula_id
      ), ARRAY_A);
    
      if (!$aula) {
        wp_die('Aula no encontrada', 'Error', ['response' => 404]);
      }
    
      $alumnos = $wpdb->get_results($wpdb->prepare(
        "SELECT 
           a.id,
           a.nombres,
           a.apellidos,
           a.ci,
           COUNT(e.id) AS total_evaluaciones,
           AVG((e.c1 + e.c2 + e.c3 + e.c4 + e.c5 + e.c6) / 6) AS promedio_general,
           AVG(e.c1) AS promedio_c1,
           AVG(e.c2) AS promedio_c2,
           AVG(e.c3) AS promedio_c3,
           AVG(e.c4) AS promedio_c4,
           AVG(e.c5) AS promedio_c5,
           AVG(e.c6) AS promedio_c6
         FROM $t_al a
         LEFT JOIN $t_eval e ON a.id = e.alumno_id
         WHERE a.aula_id = %d AND a.activo = 1
         GROUP BY a.id
         ORDER BY a.apellidos, a.nombres",
        $aula_id
      ), ARRAY_A);
    
      // Limpiar output
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="rendimiento_aula_' . sanitize_file_name($aula['aula_nombre']) . '_' . date('Y-m-d') . '.csv"');
      header('Pragma: no-cache');
      header('Expires: 0');
    
      $output = fopen('php://output', 'w');
      fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
      fputcsv($output, ['Reporte de Rendimiento por Aula - Newton OPM']);
      fputcsv($output, ['']);
      fputcsv($output, ['Aula', $aula['aula_nombre']]);
      fputcsv($output, ['Curso', $aula['curso_nombre'] ?? 'N/A']);
      fputcsv($output, ['Fecha de Reporte', date('Y-m-d H:i:s')]);
      fputcsv($output, ['Total de Alumnos', count($alumnos)]);
      fputcsv($output, ['']);
    
      fputcsv($output, [
        'Apellidos',
        'Nombres',
        'CI',
        'Total Evaluaciones',
        'Promedio General',
        'Prom. Responsabilidad Académica',
        'Prom. Respeto y Convivencia',
        'Prom. Participación y Actitud',
        'Prom. Autocontrol y Disciplina',
        'Prom. Autonomía y Compromiso',
        'Prom. Presentación y Orden',
        'Estado'
      ]);
    
      $suma_promedios = 0;
      $count_con_eval = 0;
    
      foreach ($alumnos as $alumno) {
        $promedio = floatval($alumno['promedio_general'] ?? 0);
        $estado = $promedio >= 4 ? 'Excelente' : ($promedio >= 3 ? 'Bueno' : ($promedio > 0 ? 'Regular' : 'Sin evaluaciones'));
        
        if ($promedio > 0) {
          $suma_promedios += $promedio;
          $count_con_eval++;
        }
    
        fputcsv($output, [
          $alumno['apellidos'],
          $alumno['nombres'],
          $alumno['ci'],
          $alumno['total_evaluaciones'],
          number_format($promedio, 2),
          number_format(floatval($alumno['promedio_c1'] ?? 0), 2),
          number_format(floatval($alumno['promedio_c2'] ?? 0), 2),
          number_format(floatval($alumno['promedio_c3'] ?? 0), 2),
          number_format(floatval($alumno['promedio_c4'] ?? 0), 2),
          number_format(floatval($alumno['promedio_c5'] ?? 0), 2),
          number_format(floatval($alumno['promedio_c6'] ?? 0), 2),
          $estado
        ]);
      }
    
      fputcsv($output, ['']);
      fputcsv($output, ['ESTADISTICAS DEL AULA']);
      fputcsv($output, ['Total de Alumnos', count($alumnos)]);
      fputcsv($output, ['Alumnos con Evaluaciones', $count_con_eval]);
      if ($count_con_eval > 0) {
        fputcsv($output, ['Promedio General del Aula', number_format($suma_promedios / $count_con_eval, 2)]);
      }
    
      fclose($output);
      exit;
    }
    
    // ============================================================================
    // EXPORTACIÓN POR CURSO
    // ============================================================================
    
    public static function export_curso_csv(WP_REST_Request $req) {
      global $wpdb;
      $curso_id = (int)$req['id'];
      
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
    
      $curso = $wpdb->get_row($wpdb->prepare(
        "SELECT nombre AS curso_nombre FROM $t_cur WHERE id = %d",
        $curso_id
      ), ARRAY_A);
    
      if (!$curso) {
        wp_die('Curso no encontrado', 'Error', ['response' => 404]);
      }
    
      $alumnos = $wpdb->get_results($wpdb->prepare(
        "SELECT 
           a.id,
           a.nombres,
           a.apellidos,
           a.ci,
           au.nombre AS aula_nombre,
           COUNT(e.id) AS total_evaluaciones,
           AVG((e.c1 + e.c2 + e.c3 + e.c4 + e.c5 + e.c6) / 6) AS promedio_general
         FROM $t_al a
         LEFT JOIN $t_eval e ON a.id = e.alumno_id
         LEFT JOIN $t_aul au ON a.aula_id = au.id
         WHERE a.curso_id = %d AND a.activo = 1
         GROUP BY a.id
         ORDER BY au.nombre, a.apellidos, a.nombres",
        $curso_id
      ), ARRAY_A);
    
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="rendimiento_curso_' . sanitize_file_name($curso['curso_nombre']) . '_' . date('Y-m-d') . '.csv"');
      header('Pragma: no-cache');
      header('Expires: 0');
    
      $output = fopen('php://output', 'w');
      fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
      fputcsv($output, ['Reporte de Rendimiento por Curso - Newton OPM']);
      fputcsv($output, ['']);
      fputcsv($output, ['Curso', $curso['curso_nombre']]);
      fputcsv($output, ['Fecha de Reporte', date('Y-m-d H:i:s')]);
      fputcsv($output, ['Total de Alumnos', count($alumnos)]);
      fputcsv($output, ['']);
    
      fputcsv($output, [
        'Apellidos',
        'Nombres',
        'CI',
        'Aula',
        'Total Evaluaciones',
        'Promedio General',
        'Estado'
      ]);
    
      foreach ($alumnos as $alumno) {
        $promedio = floatval($alumno['promedio_general'] ?? 0);
        $estado = $promedio >= 4 ? 'Excelente' : ($promedio >= 3 ? 'Bueno' : ($promedio > 0 ? 'Regular' : 'Sin evaluaciones'));
    
        fputcsv($output, [
          $alumno['apellidos'],
          $alumno['nombres'],
          $alumno['ci'],
          $alumno['aula_nombre'] ?? 'N/A',
          $alumno['total_evaluaciones'],
          number_format($promedio, 2),
          $estado
        ]);
      }
    
      fclose($output);
      exit;
    }

    
    /**
     * GET /alumnos/:id/export/pdf - Exporta rendimiento de alumno en PDF
     */
    public static function export_alumno_pdf(WP_REST_Request $req) {
      global $wpdb;
      $alumno_id = (int)$req['id'];
      
      
      // Verificar TCPDF
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        return new WP_Error('library_missing', 'TCPDF no está instalado. Ejecuta: composer require tecnickcom/tcpdf', ['status' => 500]);
      }
      
      require_once $autoload_path;
      
      $data = self::get_alumno_data_for_export($alumno_id);
      if (is_wp_error($data)) {
        return $data;
      }
      
      self::generate_alumno_pdf($data);
      exit;
    }
    
    /**
     * GET /aulas/:id/export/pdf - Exporta rendimiento de aula en PDF
     */
    public static function export_aula_pdf(WP_REST_Request $req) {
      global $wpdb;
      $aula_id = (int)$req['id'];
      
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        return new WP_Error('library_missing', 'TCPDF no está instalado', ['status' => 500]);
      }
      
      require_once $autoload_path;
      
      $data = self::get_aula_data_for_export($aula_id);
      if (is_wp_error($data)) {
        return $data;
      }
      
      self::generate_aula_pdf($data);
      exit;
    }
    
    /**
     * GET /cursos/:id/export/pdf - Exporta rendimiento de curso en PDF
     */
    public static function export_curso_pdf(WP_REST_Request $req) {
      global $wpdb;
      $curso_id = (int)$req['id'];
      
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        return new WP_Error('library_missing', 'TCPDF no está instalado', ['status' => 500]);
      }
      
      require_once $autoload_path;
      
      $data = self::get_curso_data_for_export($curso_id);
      if (is_wp_error($data)) {
        return $data;
      }
      
      self::generate_curso_pdf($data);
      exit;
    }
    
    // Funciones auxiliares de obtención de datos
    
    private static function get_alumno_data_for_export($alumno_id) {
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $t_fac = $wpdb->prefix . 'conducta_facultades';
      $t_car = $wpdb->prefix . 'conducta_carreras';
    
      $alumno = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, 
                c.nombre AS curso_nombre, 
                au.nombre AS aula_nombre,
                f.nombre AS facultad_nombre,
                ca.nombre AS carrera_nombre
         FROM $t_al a
         LEFT JOIN $t_cur c ON a.curso_id = c.id
         LEFT JOIN $t_aul au ON a.aula_id = au.id
         LEFT JOIN $t_fac f ON a.facultad_id = f.id
         LEFT JOIN $t_car ca ON a.carrera_id = ca.id
         WHERE a.id = %d",
        $alumno_id
      ), ARRAY_A);
    
      if (!$alumno) {
        return new WP_Error('not_found', 'Alumno no encontrado', ['status' => 404]);
      }
    
      $evaluaciones = $wpdb->get_results($wpdb->prepare(
        "SELECT fecha, c1, c2, c3, c4, c5, c6, observacion
         FROM $t_eval
         WHERE alumno_id = %d
         ORDER BY fecha DESC",
        $alumno_id
      ), ARRAY_A);
    
      return ['alumno' => $alumno, 'evaluaciones' => $evaluaciones];
    }
    
    private static function get_aula_data_for_export($aula_id) {
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
    
      $aula = $wpdb->get_row($wpdb->prepare(
        "SELECT a.nombre AS aula_nombre, c.nombre AS curso_nombre
         FROM $t_aul a
         LEFT JOIN $t_cur c ON a.curso_id = c.id
         WHERE a.id = %d",
        $aula_id
      ), ARRAY_A);
    
      if (!$aula) {
        return new WP_Error('not_found', 'Aula no encontrada', ['status' => 404]);
      }
    
      $alumnos = $wpdb->get_results($wpdb->prepare(
        "SELECT 
           a.nombres, a.apellidos, a.ci,
           COUNT(e.id) AS total_eval,
           AVG((e.c1 + e.c2 + e.c3 + e.c4 + e.c5 + e.c6) / 6) AS promedio
         FROM $t_al a
         LEFT JOIN $t_eval e ON a.id = e.alumno_id
         WHERE a.aula_id = %d AND a.activo = 1
         GROUP BY a.id
         ORDER BY a.apellidos, a.nombres",
        $aula_id
      ), ARRAY_A);
    
      return ['aula' => $aula, 'alumnos' => $alumnos];
    }
    
    private static function get_curso_data_for_export($curso_id) {
      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
    
      $curso = $wpdb->get_row($wpdb->prepare(
        "SELECT nombre AS curso_nombre FROM $t_cur WHERE id = %d",
        $curso_id
      ), ARRAY_A);
    
      if (!$curso) {
        return new WP_Error('not_found', 'Curso no encontrado', ['status' => 404]);
      }
    
      $alumnos = $wpdb->get_results($wpdb->prepare(
        "SELECT 
           a.nombres, a.apellidos, a.ci,
           au.nombre AS aula_nombre,
           COUNT(e.id) AS total_eval,
           AVG((e.c1 + e.c2 + e.c3 + e.c4 + e.c5 + e.c6) / 6) AS promedio
         FROM $t_al a
         LEFT JOIN $t_eval e ON a.id = e.alumno_id
         LEFT JOIN $t_aul au ON a.aula_id = au.id
         WHERE a.curso_id = %d AND a.activo = 1
         GROUP BY a.id
         ORDER BY au.nombre, a.apellidos, a.nombres",
        $curso_id
      ), ARRAY_A);
    
      return ['curso' => $curso, 'alumnos' => $alumnos];
    }
    
    // Funciones de generación de PDF
    
    private static function generate_alumno_pdf($data) {
      $alumno = $data['alumno'];
      $evaluaciones = $data['evaluaciones'];
      
      // ✅ Limpiar cualquier output previo
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
      $pdf->SetCreator('Newton OPM');
      $pdf->SetTitle('Rendimiento de Alumno');
      $pdf->setPrintHeader(false);
      $pdf->setPrintFooter(false);
      $pdf->SetMargins(15, 15, 15);
      $pdf->AddPage();
      
      // Título
      $pdf->SetFont('helvetica', 'B', 16);
      $pdf->Cell(0, 10, 'Reporte de Rendimiento Individual', 0, 1, 'C');
      
      $pdf->SetFont('helvetica', '', 11);
      $pdf->Cell(0, 6, 'Alumno: ' . htmlspecialchars($alumno['nombres'] . ' ' . $alumno['apellidos']), 0, 1);
      $pdf->Cell(0, 6, 'CI: ' . htmlspecialchars($alumno['ci']), 0, 1);
      $pdf->Cell(0, 6, 'Curso: ' . htmlspecialchars($alumno['curso_nombre'] ?? 'N/A'), 0, 1);
      $pdf->Cell(0, 6, 'Aula: ' . htmlspecialchars($alumno['aula_nombre'] ?? 'N/A'), 0, 1);
      $pdf->Ln(5);
      
      // Tabla de evaluaciones
      $pdf->SetFont('helvetica', 'B', 10);
      $pdf->Cell(0, 8, 'Historial de Evaluaciones', 0, 1, 'L');
      
      if (empty($evaluaciones)) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Sin evaluaciones registradas', 0, 1);
      } else {
        $pdf->SetFont('helvetica', '', 8);
        $html = '<table border="1" cellpadding="3">
          <tr style="background-color:#1a73e8;color:#fff;font-weight:bold;">
            <th width="15%">Fecha</th>
            <th width="8%">C1</th>
            <th width="8%">C2</th>
            <th width="8%">C3</th>
            <th width="8%">C4</th>
            <th width="8%">C5</th>
            <th width="8%">C6</th>
            <th width="10%">Promedio</th>
            <th width="27%">Observación</th>
          </tr>';
        
        foreach ($evaluaciones as $eval) {
          $promedio = ($eval['c1'] + $eval['c2'] + $eval['c3'] + $eval['c4'] + $eval['c5'] + $eval['c6']) / 6;
          $html .= '<tr>
            <td>' . htmlspecialchars($eval['fecha']) . '</td>
            <td align="center">' . $eval['c1'] . '</td>
            <td align="center">' . $eval['c2'] . '</td>
            <td align="center">' . $eval['c3'] . '</td>
            <td align="center">' . $eval['c4'] . '</td>
            <td align="center">' . $eval['c5'] . '</td>
            <td align="center">' . $eval['c6'] . '</td>
            <td align="center"><b>' . number_format($promedio, 2) . '</b></td>
            <td>' . htmlspecialchars($eval['observacion'] ?? '') . '</td>
          </tr>';
        }
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
      }
      
      $filename = 'rendimiento_' . sanitize_file_name($alumno['ci']) . '_' . date('Y-m-d') . '.pdf';
      
      // ✅ Forzar descarga
      $pdf->Output($filename, 'D');
      exit;
    }
    
    private static function generate_aula_pdf($data) {
      if (ob_get_level()) {
        ob_end_clean();
      }
      $aula = $data['aula'];
      $alumnos = $data['alumnos'];
      
      $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
      $pdf->SetCreator('Newton OPM');
      $pdf->SetTitle('Rendimiento Aula - ' . $aula['aula_nombre']);
      $pdf->setPrintHeader(false);
      $pdf->setPrintFooter(false);
      $pdf->SetMargins(15, 15, 15);
      $pdf->AddPage();
      
      $pdf->SetFont('helvetica', 'B', 18);
      $pdf->Cell(0, 10, 'Rendimiento por Aula', 0, 1, 'C');
      $pdf->SetFont('helvetica', '', 10);
      $pdf->Cell(0, 5, 'Aula: ' . $aula['aula_nombre'], 0, 1);
      $pdf->Cell(0, 5, 'Curso: ' . ($aula['curso_nombre'] ?? 'N/A'), 0, 1);
      $pdf->Ln(5);
      
      $pdf->SetFont('helvetica', '', 8);
      $html = '<table border="1" cellpadding="3">
        <tr style="background-color:#333; color:#fff;">
          <th width="30%">Apellidos</th>
          <th width="30%">Nombres</th>
          <th width="15%">CI</th>
          <th width="10%">Eval.</th>
          <th width="15%">Promedio</th>
        </tr>';
      
      foreach ($alumnos as $a) {
        $prom = floatval($a['promedio'] ?? 0);
        $html .= '<tr>
          <td>' . htmlspecialchars($a['apellidos']) . '</td>
          <td>' . htmlspecialchars($a['nombres']) . '</td>
          <td>' . htmlspecialchars($a['ci']) . '</td>
          <td align="center">' . $a['total_eval'] . '</td>
          <td align="center"><b>' . number_format($prom, 2) . '</b></td>
        </tr>';
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, true, false, '');
      
      $filename = 'rendimiento_aula_' . sanitize_file_name($aula['aula_nombre']) . '_' . date('Y-m-d') . '.pdf';
      $pdf->Output($filename, 'D');
      exit;
    }
    
    private static function generate_curso_pdf($data) {
      if (ob_get_level()) {
        ob_end_clean();
      }
      $curso = $data['curso'];
      $alumnos = $data['alumnos'];
      
      $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
      $pdf->SetCreator('Newton OPM');
      $pdf->SetTitle('Rendimiento Curso - ' . $curso['curso_nombre']);
      $pdf->setPrintHeader(false);
      $pdf->setPrintFooter(false);
      $pdf->SetMargins(15, 15, 15);
      $pdf->AddPage();
      
      $pdf->SetFont('helvetica', 'B', 18);
      $pdf->Cell(0, 10, 'Rendimiento por Curso', 0, 1, 'C');
      $pdf->SetFont('helvetica', '', 10);
      $pdf->Cell(0, 5, 'Curso: ' . $curso['curso_nombre'], 0, 1);
      $pdf->Ln(5);
      
      $pdf->SetFont('helvetica', '', 8);
      $html = '<table border="1" cellpadding="3">
        <tr style="background-color:#333; color:#fff;">
          <th width="25%">Apellidos</th>
          <th width="25%">Nombres</th>
          <th width="15%">CI</th>
          <th width="15%">Aula</th>
          <th width="10%">Eval.</th>
          <th width="10%">Prom.</th>
        </tr>';
      
      foreach ($alumnos as $a) {
        $prom = floatval($a['promedio'] ?? 0);
        $html .= '<tr>
          <td>' . htmlspecialchars($a['apellidos']) . '</td>
          <td>' . htmlspecialchars($a['nombres']) . '</td>
          <td>' . htmlspecialchars($a['ci']) . '</td>
          <td>' . htmlspecialchars($a['aula_nombre'] ?? 'N/A') . '</td>
          <td align="center">' . $a['total_eval'] . '</td>
          <td align="center"><b>' . number_format($prom, 2) . '</b></td>
        </tr>';
      }
      $html .= '</table>';
      $pdf->writeHTML($html, true, false, true, false, '');
      
      $filename = 'rendimiento_curso_' . sanitize_file_name($curso['curso_nombre']) . '_' . date('Y-m-d') . '.pdf';
      $pdf->Output($filename, 'D');
      exit;
    }
    
    
    /**
     * GET /reportes/general?format=csv|pdf&filtro=curso|aula&id=X
     * Genera reporte general con múltiples filtros
     * 
     * Parámetros GET:
     * - format: 'csv' o 'pdf' (default: 'csv')
     * - filtro: 'todos', 'curso', 'aula' (default: 'todos')
     * - id: ID del curso o aula (requerido si filtro es 'curso' o 'aula')
     * 
     * @param WP_REST_Request $req
     * @return WP_REST_Response|void
     */
    public static function export_reporte_general(WP_REST_Request $req) {
      global $wpdb;
      
      $format = $req->get_param('format') ?? 'csv';
      $filtro = $req->get_param('filtro') ?? 'todos';
      $id = (int)($req->get_param('id') ?? 0);
      
      $t_al = $wpdb->prefix . 'conducta_alumnos';
      $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $t_fac = $wpdb->prefix . 'conducta_facultades';
      $t_car = $wpdb->prefix . 'conducta_carreras';
    
      // Construir query según filtro
      $where = "a.activo = 1";
      
      if ($filtro === 'curso' && $id > 0) {
        $where .= " AND a.curso_id = $id";
      } else if ($filtro === 'aula' && $id > 0) {
        $where .= " AND a.aula_id = $id";
      }
    
      // Query principal
      $alumnos = $wpdb->get_results(
        "SELECT 
           a.id,
           a.nombres,
           a.apellidos,
           a.ci,
           c.nombre AS curso_nombre,
           au.nombre AS aula_nombre,
           f.nombre AS facultad_nombre,
           ca.nombre AS carrera_nombre,
           COUNT(e.id) AS total_evaluaciones,
           AVG((e.c1 + e.c2 + e.c3 + e.c4 + e.c5 + e.c6) / 6) AS promedio_general,
           MAX(e.fecha) AS ultima_evaluacion
         FROM $t_al a
         LEFT JOIN $t_eval e ON a.id = e.alumno_id
         LEFT JOIN $t_cur c ON a.curso_id = c.id
         LEFT JOIN $t_aul au ON a.aula_id = au.id
         LEFT JOIN $t_fac f ON a.facultad_id = f.id
         LEFT JOIN $t_car ca ON a.carrera_id = ca.id
         WHERE $where
         GROUP BY a.id
         ORDER BY au.nombre, a.apellidos, a.nombres",
        ARRAY_A
      );
    
      if ($format === 'pdf') {
        return self::generate_reporte_general_pdf($alumnos, $filtro, $id);
      } else {
        return self::generate_reporte_general_csv($alumnos, $filtro, $id);
      }
    }
    
    /**
     * Genera reporte general en CSV
     * 
     * @param array $alumnos Datos de alumnos
     * @param string $filtro Tipo de filtro aplicado
     * @param int $id ID del filtro
     * @return void (genera download directo)
     */
    private static function generate_reporte_general_csv($alumnos, $filtro, $id) {
      $filename = 'reporte_general_' . date('Y-m-d') . '.csv';
      
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Pragma: no-cache');
      header('Expires: 0');
    
      $output = fopen('php://output', 'w');
      fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
      fputcsv($output, ['Reporte General de Rendimiento - Newton OPM']);
      fputcsv($output, ['']);
      fputcsv($output, ['Fecha de Reporte', date('Y-m-d H:i:s')]);
      fputcsv($output, ['Total de Alumnos', count($alumnos)]);
      fputcsv($output, ['']);
    
      // Encabezados
      fputcsv($output, [
        'Apellidos',
        'Nombres',
        'CI',
        'Facultad',
        'Carrera',
        'Curso',
        'Aula',
        'Total Eval.',
        'Prom. General',
        'Última Eval.',
        'Estado'
      ]);
    
      // Datos
      $suma_promedios = 0;
      $count_con_eval = 0;
      
      foreach ($alumnos as $alumno) {
        $promedio = floatval($alumno['promedio_general'] ?? 0);
        $estado = $promedio >= 4 ? 'Excelente' : ($promedio >= 3 ? 'Bueno' : ($promedio > 0 ? 'Regular' : 'Sin evaluaciones'));
        
        if ($promedio > 0) {
          $suma_promedios += $promedio;
          $count_con_eval++;
        }
        
        fputcsv($output, [
          $alumno['apellidos'],
          $alumno['nombres'],
          $alumno['ci'],
          $alumno['facultad_nombre'] ?? 'N/A',
          $alumno['carrera_nombre'] ?? 'N/A',
          $alumno['curso_nombre'] ?? 'N/A',
          $alumno['aula_nombre'] ?? 'N/A',
          $alumno['total_evaluaciones'],
          number_format($promedio, 2),
          $alumno['ultima_evaluacion'] ?? 'N/A',
          $estado
        ]);
      }
    
      // Estadísticas finales
      fputcsv($output, ['']);
      fputcsv($output, ['ESTADÍSTICAS GENERALES']);
      fputcsv($output, ['Total de Alumnos', count($alumnos)]);
      fputcsv($output, ['Alumnos con Evaluaciones', $count_con_eval]);
      if ($count_con_eval > 0) {
        fputcsv($output, ['Promedio General', number_format($suma_promedios / $count_con_eval, 2)]);
      }
    
      fclose($output);
      exit;
    }
    
    /**
     * Genera reporte general en PDF
     * 
     * @param array $alumnos Datos de alumnos
     * @param string $filtro Tipo de filtro aplicado
     * @param int $id ID del filtro
     * @return WP_Error|void
     */
    private static function generate_reporte_general_pdf($alumnos, $filtro, $id) {
         $autoload_path = NC_PATH . 'vendor/autoload.php';
          if (!file_exists($autoload_path)) {
            return new WP_Error('library_missing', 'TCPDF no está instalado', ['status' => 500]);
          }
          
          require_once $autoload_path;
          
          // ✅ Limpiar buffer
          if (ob_get_level()) {
            ob_end_clean();
          }
          
          $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8'); // Landscape
          $pdf->SetCreator('Newton OPM');
          $pdf->SetTitle('Reporte General');
          $pdf->setPrintHeader(false);
          $pdf->setPrintFooter(false);
          $pdf->SetMargins(10, 15, 10);
          $pdf->AddPage();
          
          $pdf->SetFont('helvetica', 'B', 16);
          $pdf->Cell(0, 10, 'Reporte General de Rendimiento', 0, 1, 'C');
          $pdf->SetFont('helvetica', '', 9);
          $pdf->Cell(0, 5, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
          $pdf->Cell(0, 5, 'Total Alumnos: ' . count($alumnos), 0, 1, 'C');
          $pdf->Ln(5);
          
          // Calcular estadísticas
          $suma_promedios = 0;
          $count_con_eval = 0;
          foreach ($alumnos as $a) {
            $p = floatval($a['promedio_general'] ?? 0);
            if ($p > 0) {
              $suma_promedios += $p;
              $count_con_eval++;
            }
          }
          
          $pdf->SetFont('helvetica', '', 7);
          $html = '<table border="1" cellpadding="2">
            <tr style="background-color:#333; color:#fff; font-weight:bold;">
              <th width="12%">Apellidos</th>
              <th width="12%">Nombres</th>
              <th width="8%">CI</th>
              <th width="12%">Facultad</th>
              <th width="12%">Carrera</th>
              <th width="10%">Curso</th>
              <th width="10%">Aula</th>
              <th width="6%">Eval.</th>
              <th width="8%">Promedio</th>
              <th width="10%">Estado</th>
            </tr>';
          
          foreach ($alumnos as $a) {
            $prom = floatval($a['promedio_general'] ?? 0);
            $estado = $prom >= 4 ? 'Excelente' : ($prom >= 3 ? 'Bueno' : ($prom > 0 ? 'Regular' : 'Sin eval.'));
            
            $html .= '<tr>
              <td>' . htmlspecialchars($a['apellidos']) . '</td>
              <td>' . htmlspecialchars($a['nombres']) . '</td>
              <td>' . htmlspecialchars($a['ci']) . '</td>
              <td>' . htmlspecialchars($a['facultad_nombre'] ?? 'N/A') . '</td>
              <td>' . htmlspecialchars($a['carrera_nombre'] ?? 'N/A') . '</td>
              <td>' . htmlspecialchars($a['curso_nombre'] ?? 'N/A') . '</td>
              <td>' . htmlspecialchars($a['aula_nombre'] ?? 'N/A') . '</td>
              <td align="center">' . $a['total_evaluaciones'] . '</td>
              <td align="center"><b>' . number_format($prom, 2) . '</b></td>
              <td align="center">' . $estado . '</td>
            </tr>';
          }
          $html .= '</table>';
          
          $pdf->writeHTML($html, true, false, true, false, '');
          
          // Resumen al final
          if ($count_con_eval > 0) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 5, 'Promedio General: ' . number_format($suma_promedios / $count_con_eval, 2) . ' / 5.00', 0, 1, 'C');
          }
          
          $filename = 'reporte_general_' . date('Y-m-d') . '.pdf';
          $pdf->Output($filename, 'D');
          exit;
        }
        
        private static function export_to_excel($rows, $filename, $from, $to) {
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        wp_die('PhpSpreadsheet no está instalado. Ejecutar: composer require phpoffice/phpspreadsheet', 'Error', ['response' => 500]);
      }
      
      require_once $autoload_path;
      
      // ✅ SIN declaraciones use - usamos nombres completos
      $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
      $sheet = $spreadsheet->getActiveSheet();
      
      // Título del reporte
      $sheet->setCellValue('A1', 'Reporte de Conducta por Fecha - Newton Centro de Estudios');
      $sheet->mergeCells('A1:M1');
      $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
      $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      
      // Info del rango
      $sheet->setCellValue('A2', 'Desde: ' . $from . ' | Hasta: ' . $to . ' | Total registros: ' . count($rows));
      $sheet->mergeCells('A2:M2');
      $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      
      // Encabezados (fila 4)
      $headers = [
        'Fecha', 'Aula', 'Alumno', 'CI', 'Tipo', 
        'Responsabilidad Académica', 'Respeto y Convivencia', 'Participación y Actitud', 
        'Autocontrol y Disciplina', 'Autonomía y Compromiso', 'Presentación y Orden', 
        'Observación', 'Registrado por'
      ];
      
      $col = 'A';
      foreach ($headers as $header) {
        $sheet->setCellValue($col . '4', $header);
        $col++;
      }
      
      // Estilo de encabezados
      $headerStyle = $sheet->getStyle('A4:M4');
      $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
      $headerStyle->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()
        ->setARGB('FF1a73e8');
      $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      
      // Datos (empezando en fila 5)
      $row = 5;
      foreach ($rows as $r) {
        $nombre = trim(($r['nombres'] ?? '') . ' ' . ($r['apellidos'] ?? ''));
        
        $sheet->setCellValue('A' . $row, $r['fecha'] ?? '');
        $sheet->setCellValue('B' . $row, $r['aula_nombre'] ?? '');
        $sheet->setCellValue('C' . $row, $nombre);
        $sheet->setCellValue('D' . $row, $r['ci'] ?? '');
        $sheet->setCellValue('E' . $row, $r['tipo'] ?? '');
        $sheet->setCellValue('F' . $row, (int)($r['responsabilidad_academica'] ?? 0));
        $sheet->setCellValue('G' . $row, (int)($r['respeto_convivencia'] ?? 0));
        $sheet->setCellValue('H' . $row, (int)($r['participacion_actitud'] ?? 0));
        $sheet->setCellValue('I' . $row, (int)($r['autocontrol_disciplina'] ?? 0));
        $sheet->setCellValue('J' . $row, (int)($r['autonomia_compromiso'] ?? 0));
        $sheet->setCellValue('K' . $row, (int)($r['presentacion_orden'] ?? 0));
        $sheet->setCellValue('L' . $row, $r['observacion'] ?? $r['observacion_item'] ?? '');
        $sheet->setCellValue('M' . $row, $r['evaluador_nombre'] ?? '');
        
        $row++;
      }
      
      // Ajustar anchos de columna
      $sheet->getColumnDimension('A')->setWidth(12); // Fecha
      $sheet->getColumnDimension('B')->setWidth(10); // Aula
      $sheet->getColumnDimension('C')->setWidth(25); // Alumno
      $sheet->getColumnDimension('D')->setWidth(12); // CI
      $sheet->getColumnDimension('E')->setWidth(12); // Tipo
      $sheet->getColumnDimension('F')->setWidth(22); // Resp.Acad.
      $sheet->getColumnDimension('G')->setWidth(20); // Respeto/Conv.
      $sheet->getColumnDimension('H')->setWidth(24); // Part.Act.
      $sheet->getColumnDimension('I')->setWidth(22); // Autocont.Disc.
      $sheet->getColumnDimension('J')->setWidth(24); // Auton.Comp.
      $sheet->getColumnDimension('K')->setWidth(20); // Pres.Orden
      $sheet->getColumnDimension('L')->setWidth(30); // Observación
      $sheet->getColumnDimension('M')->setWidth(20); // Registró
      
      // Bordes
      $lastRow = $row - 1;
      if ($lastRow >= 5) {
        $sheet->getStyle('A4:M' . $lastRow)
          ->getBorders()
          ->getAllBorders()
          ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // Centrar valores numéricos
        $sheet->getStyle('F5:K' . $lastRow)
          ->getAlignment()
          ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      }
      
      // Generar y enviar archivo
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
      header('Cache-Control: max-age=0');
      header('Pragma: no-cache');
      
      $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
      $writer->save('php://output');
      exit;
    }
    
    /**
     * Genera PDF de reporte de conducta por fecha
     */
    private static function generate_reporte_conducta_pdf($rows, $filename, $from, $to) {
      $autoload_path = NC_PATH . 'vendor/autoload.php';
      if (!file_exists($autoload_path)) {
        wp_die('TCPDF no está instalado', 'Error', ['response' => 500]);
      }
      
      require_once $autoload_path;
      
      // ✅ Limpiar buffer
      if (ob_get_level()) {
        ob_end_clean();
      }
      
      $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8'); // Landscape
      $pdf->SetCreator('Newton OPM');
      $pdf->SetTitle('Reporte de Conducta por Fecha');
      $pdf->setPrintHeader(false);
      $pdf->setPrintFooter(false);
      $pdf->SetMargins(10, 15, 10);
      $pdf->AddPage();
      
      // Título
      $pdf->SetFont('helvetica', 'B', 14);
      $pdf->Cell(0, 8, 'Reporte de Conducta por Fecha', 0, 1, 'C');
      
      $pdf->SetFont('helvetica', '', 10);
      $pdf->Cell(0, 6, 'Desde: ' . $from . ' | Hasta: ' . $to, 0, 1, 'C');
      $pdf->Cell(0, 6, 'Total de registros: ' . count($rows), 0, 1, 'C');
      $pdf->Ln(3);
      
      if (empty($rows)) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 8, 'Sin registros en el rango seleccionado.', 0, 1, 'C');
      } else {
        $pdf->SetFont('helvetica', '', 7);
        
        $html = '<table border="1" cellpadding="3">
          <tr style="background-color:#1a73e8;color:#fff;font-weight:bold;">
            <th width="7%">Fecha</th>
            <th width="6%">Aula</th>
            <th width="15%">Alumno</th>
            <th width="8%">CI</th>
            <th width="7%">Tipo</th>
            <th width="10%">Resp.Acad.</th>
            <th width="10%">Respeto/Conv.</th>
            <th width="10%">Part.Act.</th>
            <th width="10%">Autocont.Disc.</th>
            <th width="10%">Auton.Comp.</th>
            <th width="10%">Pres.Orden</th>
            <th width="15%">Observación</th>
          </tr>';
        
        foreach ($rows as $r) {
          $nombre = trim(($r['nombres'] ?? '') . ' ' . ($r['apellidos'] ?? ''));
          
          $html .= '<tr>
            <td>' . htmlspecialchars($r['fecha'] ?? '') . '</td>
            <td>' . htmlspecialchars($r['aula_nombre'] ?? '') . '</td>
            <td>' . htmlspecialchars($nombre) . '</td>
            <td>' . htmlspecialchars($r['ci'] ?? '') . '</td>
            <td>' . htmlspecialchars($r['tipo'] ?? '') . '</td>
            <td align="center">' . (int)($r['responsabilidad_academica'] ?? 0) . '</td>
            <td align="center">' . (int)($r['respeto_convivencia'] ?? 0) . '</td>
            <td align="center">' . (int)($r['participacion_actitud'] ?? 0) . '</td>
            <td align="center">' . (int)($r['autocontrol_disciplina'] ?? 0) . '</td>
            <td align="center">' . (int)($r['autonomia_compromiso'] ?? 0) . '</td>
            <td align="center">' . (int)($r['presentacion_orden'] ?? 0) . '</td>
            <td>' . htmlspecialchars(substr($r['observacion'] ?? $r['observacion_item'] ?? '', 0, 100)) . '</td>
          </tr>';
        }
        
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
      }
      
      // ✅ Forzar descarga
      $pdf->Output($filename . '.pdf', 'D');
      exit;
    }

    /**
     * Obtiene los permisos del usuario actual
     */
    public static function get_user_permissions(WP_REST_Request $req) {
      $is_admin = NC_Roles::user_is_admin();
      $can_view_reportes_asistencia = NC_Roles::user_can_view_reportes_asistencia();
      
      return self::ok([
        'is_admin' => $is_admin,
        'can_view_evaluator' => $is_admin,
        'can_manage_students' => $is_admin,
        'can_manage_courses' => $is_admin,
        'can_manage_aulas' => $is_admin,
        'can_manage_facultades' => $is_admin,
        'can_view_reports' => $is_admin,
        'can_view_reportes_asistencia' => $can_view_reportes_asistencia,
        'can_manage_attendance' => NC_Roles::user_can_manage_all_attendance(),
        'is_docente_or_oficina' => NC_Roles::user_is_docente_or_oficina(),
      ]);
    }

    /**
     * Elimina múltiples alumnos (marcando como inactivos)
     */
    public static function bulk_delete_alumnos(WP_REST_Request $req) {
      // Solo admins pueden eliminar
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para eliminar alumnos.', 403);
      }

      global $wpdb;
      $t_al = $wpdb->prefix . 'conducta_alumnos';

      $p = self::json_params($req);
      $ids = $p['ids'] ?? [];

      if (!is_array($ids) || empty($ids)) {
        return self::err('Debe proporcionar un array de IDs de alumnos.');
      }

      // Filtrar solo IDs numéricos
      $ids = array_filter($ids, 'is_numeric');
      $ids = array_map('intval', $ids);

      if (empty($ids)) {
        return self::err('No se proporcionaron IDs válidos.');
      }

      // Preparar placeholders para la consulta
      $placeholders = implode(',', array_fill(0, count($ids), '%d'));
      
      // Marcar como inactivos
      $sql = "UPDATE $t_al SET activo = 0 WHERE id IN ($placeholders)";
      $wpdb->query($wpdb->prepare($sql, $ids));

      $affected = $wpdb->rows_affected;

      return self::ok([
        'deleted' => true,
        'count' => $affected,
        'ids' => $ids
      ]);
    }

    /**
   * Asigna masivamente un curso a una lista de alumnos.
   * NO toca facultad_id ni carrera_id (eso se maneja por separado).
     */
    public static function bulk_update_alumnos_curso(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para asignar cursos masivamente.', 403);
      }
      global $wpdb;

      $t_al  = $wpdb->prefix . 'conducta_alumnos';
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
      $has_rel_c = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c);

      $p = self::json_params($req);
      $curso_id = self::int_or_null($p['curso_id'] ?? null);
      $ids      = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];

      if (!$curso_id || $curso_id <= 0) {
        return self::err('curso_id es obligatorio.', 400);
      }
      if (empty($ids)) {
        return self::err('Debe indicar al menos un alumno.', 400);
      }

      $curso = $wpdb->get_row($wpdb->prepare("SELECT id FROM $t_cur WHERE id=%d AND activo=1", $curso_id), ARRAY_A);
      if (!$curso) {
        return self::err('Curso no encontrado o inactivo.', 404);
      }

      $ids = array_filter($ids, fn($v) => is_int($v) && $v > 0);
      if (empty($ids)) {
        return self::err('No se proporcionaron IDs de alumnos válidos.', 400);
      }

      $placeholders = implode(',', array_fill(0, count($ids), '%d'));
      $sql = "UPDATE $t_al SET curso_id=%d WHERE id IN ($placeholders)";

      $params = [];
      $params[] = $curso_id;
      foreach ($ids as $id) {
        $params[] = $id;
      }

      $prepared = $wpdb->prepare($sql, $params);
      $res = $wpdb->query($prepared);
      if ($res === false) {
        return self::db_fail('No se pudo actualizar el curso para los alumnos seleccionados.');
      }

      // Multi-curso: además de actualizar el curso "principal" del alumno,
      // guardamos la relación (append) en la tabla de relación.
      if ($has_rel_c) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $insSql = "INSERT IGNORE INTO $t_al_c (alumno_id, curso_id)
                   SELECT id, %d FROM $t_al WHERE id IN ($placeholders)";
        $insParams = array_merge([$curso_id], $ids);
        $wpdb->query($wpdb->prepare($insSql, $insParams));
      }

      if (class_exists('NC_Rest_Asistencia')) {
        foreach ($ids as $aid) {
          NC_Rest_Asistencia::sync_alumno_materias((int) $aid, $curso_id, null, true);
        }
      }

      return self::ok(['updated' => (int)$res, 'curso_id' => $curso_id, 'alumno_ids' => $ids]);
    }

    /**
     * Asigna masivamente un subgrupo a alumnos SIN tocar curso/facultad/carrera.
     */
    public static function bulk_update_alumnos_subgrupo(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para asignar subgrupos masivamente.', 403);
      }
      global $wpdb;

      $t_al = $wpdb->prefix . 'conducta_alumnos';
      if (!self::table_has_column($t_al, 'subgrupo')) {
        return self::err('La columna subgrupo no existe en la tabla de alumnos.', 500);
      }

      $p = self::json_params($req);
      $subgrupo = isset($p['subgrupo']) ? trim(sanitize_text_field((string)$p['subgrupo'])) : '';
      $ids      = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];

      if ($subgrupo === '') {
        return self::err('El subgrupo es obligatorio.', 400);
      }
      $ids = array_filter($ids, fn($v) => is_int($v) && $v > 0);
      if (empty($ids)) {
        return self::err('Debe indicar al menos un alumno.', 400);
      }

      $placeholders = implode(',', array_fill(0, count($ids), '%d'));
      $sql = "UPDATE $t_al SET subgrupo=%s WHERE id IN ($placeholders)";

      $params = [];
      $params[] = $subgrupo;
      foreach ($ids as $id) {
        $params[] = $id;
      }

      $prepared = $wpdb->prepare($sql, $params);
      $res = $wpdb->query($prepared);
      if ($res === false) {
        return self::db_fail('No se pudo actualizar el subgrupo para los alumnos seleccionados.');
      }

      return self::ok(['updated' => (int)$res, 'subgrupo' => $subgrupo, 'alumno_ids' => $ids]);
    }

    /**
     * Agrega masivamente una pertenencia alumno -> aula/grupo (NO borra otras pertenencias).
     * POST /alumnos/bulk-aula-add
     * Body: { aula_id: 1, alumno_ids: [1,2,3] }
     */
    public static function bulk_add_alumnos_aula(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para agregar alumnos a grupos.', 403);
      }
      global $wpdb;
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';

      // Si la tabla de relación no existe todavía, no podemos multi-grupo.
      if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") !== $t_al_a) {
        return self::err('La tabla de relación alumno-aula no existe.', 500);
      }

      $p = self::json_params($req);
      $aula_id = self::int_or_null($p['aula_id'] ?? null);
      $ids = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];

      if (!$aula_id || $aula_id <= 0) return self::err('aula_id es obligatorio.', 400);
      $ids = array_filter($ids, fn($v) => is_int($v) && $v > 0);
      if (empty($ids)) return self::err('Debe indicar al menos un alumno.', 400);

      $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_aul WHERE id=%d AND activo=1 LIMIT 1", $aula_id));
      if (!$exists) return self::err('Aula/grupo no encontrado o inactivo.', 404);

      $placeholders = implode(',', array_fill(0, count($ids), '(%d,%d,1)'));
      $params = [];
      foreach ($ids as $aid) {
        $params[] = $aid;
        $params[] = $aula_id;
      }

      $sql = "INSERT INTO $t_al_a (alumno_id, aula_id, activo) VALUES $placeholders
              ON DUPLICATE KEY UPDATE activo=1";
      $prepared = $wpdb->prepare($sql, $params);
      $res = $wpdb->query($prepared);

      $auto_inscritos = 0;
      if ($res !== false && class_exists('NC_Rest_Asistencia')) {
        $curso_id_aula = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT curso_id FROM $t_aul WHERE id=%d LIMIT 1",
          $aula_id
        ));
        foreach ($ids as $aid) {
          NC_Rest_Asistencia::auto_inscribir_alumno_curso_materias((int) $aid, $curso_id_aula, $aula_id);
          if (class_exists('NC_Rest_Examenes')) {
            NC_Rest_Examenes::auto_registrar_alumno_examenes_nu((int) $aid, $aula_id);
          }
          $auto_inscritos++;
        }
      }

      return self::ok([
        'updated' => (int)$res,
        'aula_id' => $aula_id,
        'alumno_ids' => array_values($ids),
        'auto_inscritos_materias' => $auto_inscritos,
      ]);
    }

    /**
     * Desvincula masivamente alumnos de un grupo/aula (marca activo=0 en la relación).
     * POST /alumnos/bulk-aula-remove
     * Body: { aula_id: 1, alumno_ids: [1,2,3] }
     */
    public static function bulk_remove_alumnos_aula(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para desvincular alumnos de grupos.', 403);
      }
      global $wpdb;
      $t_al   = $wpdb->prefix . 'conducta_alumnos';
      $t_aul  = $wpdb->prefix . 'conducta_aulas';
      $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
      $has_rel_a = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);

      $p = self::json_params($req);
      $aula_id = self::int_or_null($p['aula_id'] ?? null);
      $ids = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];

      if (!$aula_id || $aula_id <= 0) return self::err('aula_id es obligatorio.', 400);
      $ids = array_values(array_filter($ids, fn($v) => is_int($v) && $v > 0));
      if (empty($ids)) return self::err('Debe indicar al menos un alumno.', 400);

      $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_aul WHERE id=%d AND activo=1 LIMIT 1", $aula_id));
      if (!$exists) return self::err('Aula/grupo no encontrado o inactivo.', 404);

      $updated = 0;
      if ($has_rel_a) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$aula_id], $ids);
        $sql = "UPDATE $t_al_a SET activo=0 WHERE aula_id=%d AND alumno_id IN ($placeholders) AND activo=1";
        $res = $wpdb->query($wpdb->prepare($sql, $params));
        if ($res === false) {
          return self::db_fail('No se pudo desvincular a los alumnos del grupo.');
        }
        $updated = (int) $res;

        foreach ($ids as $aid) {
          $legacy_aula = (int) $wpdb->get_var($wpdb->prepare("SELECT aula_id FROM $t_al WHERE id=%d LIMIT 1", $aid));
          if ($legacy_aula !== $aula_id) {
            continue;
          }
          $new_aula = $wpdb->get_var($wpdb->prepare(
            "SELECT ag.aula_id
             FROM $t_al_a ag
             INNER JOIN $t_aul au ON au.id=ag.aula_id
             WHERE ag.alumno_id=%d AND ag.activo=1 AND au.activo=1
             ORDER BY au.nombre ASC
             LIMIT 1",
            $aid
          ));
          $new_aula = $new_aula ? (int) $new_aula : null;
          $data = ['aula_id' => $new_aula];
          $fmt  = ['%d'];
          if (self::table_has_column($t_al, 'grupo_id')) {
            $data['grupo_id'] = $new_aula;
            $fmt[] = '%d';
          }
          $wpdb->update($t_al, $data, ['id' => $aid], $fmt, ['%d']);
        }
      } else {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$aula_id], $ids);
        $sets = ['aula_id=NULL'];
        if (self::table_has_column($t_al, 'grupo_id')) {
          $sets[] = 'grupo_id=NULL';
        }
        $sql = "UPDATE $t_al SET " . implode(',', $sets) . " WHERE aula_id=%d AND id IN ($placeholders) AND activo=1";
        $res = $wpdb->query($wpdb->prepare($sql, $params));
        if ($res === false) {
          return self::db_fail('No se pudo desvincular a los alumnos del grupo.');
        }
        $updated = (int) $res;
      }

      return self::ok([
        'updated' => $updated,
        'aula_id' => $aula_id,
        'alumno_ids' => $ids,
      ]);
    }

    /**
     * Asigna masivamente facultad y/o carrera a alumnos.
     */
public static function bulk_update_alumnos_facultad_carrera(WP_REST_Request $req) {
      if (!NC_Roles::user_is_admin()) {
        return self::err('No tienes permisos para asignar facultad/carrera masivamente.', 403);
      }
      global $wpdb;

      $t_al  = $wpdb->prefix . 'conducta_alumnos';
      $t_fac = $wpdb->prefix . 'conducta_facultades';
      $t_car = $wpdb->prefix . 'conducta_carreras';

      $p = self::json_params($req);
      $facultad_id = self::int_or_null($p['facultad_id'] ?? null);
      $carrera_id  = self::int_or_null($p['carrera_id'] ?? null);
      $ids         = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];

      if (!$facultad_id && !$carrera_id) {
        return self::err('Debe indicar al menos facultad_id o carrera_id.', 400);
      }
      $ids = array_filter($ids, fn($v) => is_int($v) && $v > 0);
      if (empty($ids)) {
        return self::err('Debe indicar al menos un alumno.', 400);
      }

      if ($facultad_id) {
        $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_fac WHERE id=%d AND activo=1", $facultad_id));
        if (!$ex) return self::err('Facultad no encontrada o inactiva.', 404);
      }
      if ($carrera_id) {
        $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_car WHERE id=%d AND activo=1", $carrera_id));
        if (!$ex) return self::err('Carrera no encontrada o inactiva.', 404);
      }

      $placeholders = implode(',', array_fill(0, count($ids), '%d'));
      $sets = [];
      $params = [];
      if ($facultad_id !== null) {
        $sets[] = 'facultad_id=%s';
        $params[] = $facultad_id;
      }
      if ($carrera_id !== null) {
        $sets[] = 'carrera_id=%s';
        $params[] = $carrera_id;
      }
      if (empty($sets)) {
        return self::err('Nada para actualizar.', 400);
      }

      $sql = "UPDATE $t_al SET " . implode(',', $sets) . " WHERE id IN ($placeholders)";
      foreach ($ids as $id) {
        $params[] = $id;
      }

      $prepared = $wpdb->prepare($sql, $params);
      $res = $wpdb->query($prepared);
      if ($res === false) {
        return self::db_fail('No se pudo actualizar facultad/carrera para los alumnos seleccionados.');
      }

      if (class_exists('NC_Rest_Asistencia')) {
        foreach ($ids as $aid) {
          NC_Rest_Asistencia::sync_alumno_materias((int) $aid, null, null, true);
        }
      }

      return self::ok([
        'updated' => (int)$res,
        'facultad_id' => $facultad_id,
        'carrera_id'  => $carrera_id,
        'alumno_ids'  => $ids,
      ]);
    }


}