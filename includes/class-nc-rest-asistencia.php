<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API para el módulo de Asistencia.
 * Rutas: materias, sesiones de asistencia, reportes, dashboard, historial por alumno.
 */
class NC_Rest_Asistencia {

  private static $schema_ensured = false;

  private static function ensure_tables() {
    if (self::$schema_ensured) return;
    try {
      NC_Asistencia_DB::maybe_upgrade();
      self::$schema_ensured = true;
    } catch (Throwable $e) {
      error_log('[NC_Asistencia] maybe_upgrade: ' . $e->getMessage());
    }
  }

  private static function ns() {
    return 'conducta/v1';
  }

  public static function can_access() {
    // Permitir acceso a todos los roles habilitados para el módulo (incluye Docente)
    return NC_Roles::user_can_access();
  }

  /** Usuario puede editar/eliminar cualquier asistencia (no docente / no funcionario de oficina). */
  public static function can_manage_attendance() {
    return NC_Roles::user_can_manage_all_attendance();
  }

  /** Puede editar/eliminar una sesión concreta. */
  public static function user_can_edit_sesion(array $row, ?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;
    if (self::can_manage_attendance()) return true;
    return (int) ($row['creado_por'] ?? 0) === (int) $user_id;
  }

  private static function err($message, int $status = 400) {
    return new WP_Error('nc_error', $message, ['status' => $status]);
  }

  /** Etiquetas de reporte para sesiones tomadas desde Lista simulacro. */
  private static function is_simulacro_sesion_row(array $r): bool {
    if ((int) ($r['simulacro_id'] ?? 0) > 0) {
      return true;
    }
    return strcasecmp(trim((string) ($r['subgrupo'] ?? '')), 'Simulacro') === 0;
  }

  private static function apply_simulacro_sesion_display(array &$r): void {
    if (!self::is_simulacro_sesion_row($r)) {
      return;
    }
    $r['es_simulacro'] = 1;
    $mn = trim((string) ($r['materia_nombre'] ?? ''));
    if ($mn !== '' && stripos($mn, 'simulacro') === false) {
      $r['materia_nombre'] = $mn . ' — Simulacro';
    }
    $r['grupo_nombre'] = 'Simulacro';
    $r['grupo_id'] = null;
  }

  private static function ok($data, int $status = 200) {
    return new WP_REST_Response($data, $status);
  }

  private static function int_or_null($v): ?int {
    $n = is_numeric($v) ? (int)$v : 0;
    return $n > 0 ? $n : null;
  }

  private static function norm_str($v): string {
    return trim(sanitize_text_field((string)($v ?? '')));
  }

  /** Parámetro GET/REST (algunos hosts no pasan query params no registrados). */
  private static function req_query_str(WP_REST_Request $req, string $key): string {
    $v = $req->get_param($key);
    if ($v !== null && $v !== '') {
      return self::norm_str($v);
    }
    $q = $req->get_query_params();
    if (isset($q[$key]) && $q[$key] !== '') {
      return self::norm_str($q[$key]);
    }
    if (isset($_GET[$key])) {
      return self::norm_str($_GET[$key]);
    }
    return '';
  }

  private static function req_query_int(WP_REST_Request $req, string $key): int {
    $v = $req->get_param($key);
    if ($v === null || $v === '') {
      $q = $req->get_query_params();
      $v = $q[$key] ?? 0;
    }
    return is_numeric($v) ? (int) $v : 0;
  }

  private static function asistencias_has_column(string $column): bool {
    global $wpdb;
    static $cache = [];
    $t = $wpdb->prefix . 'conducta_asistencias';
    if (isset($cache[$column])) {
      return $cache[$column];
    }
    $cache[$column] = ((int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
      $t,
      $column
    ))) > 0;
    return $cache[$column];
  }

  public static function register_routes(string $ns) {
    // -------- Materias --------
    register_rest_route($ns, '/materias', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_materias'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_materia'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/materias/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_materia'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_materia'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_materia'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/materias/(?P<id>\d+)/docentes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_materia_docentes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'add_materia_docente'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/materias/(?P<id>\d+)/docentes/(?P<user_id>\d+)', [
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'remove_materia_docente'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Sesiones de asistencia --------
    register_rest_route($ns, '/asistencia/sesiones', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_sesiones'],
        'permission_callback' => [__CLASS__, 'can_access'],
        'args'                => [
          'fecha_desde' => ['type' => 'string', 'required' => false],
          'fecha_hasta' => ['type' => 'string', 'required' => false],
          'materia_id'  => ['type' => 'integer', 'required' => false],
          'aula_id'     => ['type' => 'integer', 'required' => false],
          'grupo_id'    => ['type' => 'integer', 'required' => false],
          'solo_mias'   => ['type' => 'integer', 'required' => false],
        ],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_sesion'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/sesiones/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_sesion'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_sesion'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_sesion'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    // -------- Dashboard y listados --------
    register_rest_route($ns, '/asistencia/dashboard/historial', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'dashboard_historial'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/ping', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'ping'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/dashboard', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function($req) {
          try {
            return self::dashboard($req);
          } catch (Throwable $e) {
            error_log('[NC_Asistencia] dashboard wrapper error: ' . $e->getMessage());
            error_log('[NC_Asistencia] dashboard wrapper trace: ' . $e->getTraceAsString());
            return new WP_REST_Response([
              'mes_actual' => date('Y-m'),
              'mes_anterior' => date('Y-m', strtotime('-1 month')),
              'promedio_actual' => 0,
              'promedio_anterior' => 0,
              'sesiones_mes_actual' => 0,
              'sesiones_mes_anterior' => 0,
              'por_aula' => [],
              'error' => 'Error al cargar el dashboard',
            ], 200);
          }
        },
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/lista-alumnos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'lista_alumnos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/alumnos/(?P<id>\d+)/materias', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'alumno_materias_list'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'alumno_materias_set'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/cursos/(?P<id>\d+)/materias', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'curso_materias_list'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'curso_materias_set'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/cursos/(?P<id>\d+)/materias/aplicar', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'curso_materias_aplicar'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/materias/(?P<id>\d+)/alumnos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'materia_alumnos_list'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    // Grupos/aulas que tienen alumnos inscriptos en una materia (para dropdown de "Grupo")
    register_rest_route($ns, '/asistencia/materias/(?P<id>\d+)/grupos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'materia_grupos_list'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/materias/(?P<id>\d+)/inscribir', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'materia_inscribir_bulk'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/materias/(?P<id>\d+)/quitar', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'materia_quitar_bulk'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/alumno/(?P<id>\d+)/historial', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'historial_alumno'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/items/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_item'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    
    // ✨ NUEVA RUTA: Obtener totales actualizados de presentes
    register_rest_route($ns, '/asistencia/sesiones/(?P<id>\d+)/total-presentes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_total_presentes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // Listar usuarios (para asignar docentes a materias)
    register_rest_route($ns, '/usuarios', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_usuarios'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Docentes (crear/asignar rol docente) --------
    register_rest_route($ns, '/docentes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_docentes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'crear_docente'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/docentes/asignar', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'asignar_docente'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/docentes/(?P<id>\d+)/materias', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_docente_materias'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/docentes/(?P<id>\d+)/cursos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_docente_cursos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Exportación reportes asistencia --------
    register_rest_route($ns, '/asistencia/export/csv', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_csv'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/export/pdf', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_pdf'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/alumno/(?P<id>\d+)/export/csv', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_alumno_historial_csv'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/alumno/(?P<id>\d+)/export/pdf', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_alumno_historial_pdf'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/lista-alumnos/export/xlsx', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'export_lista_alumnos_xlsx'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);

    // -------- Simulacros --------
    register_rest_route($ns, '/asistencia/simulacros/config', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'simulacros_config'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/simulacros', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_simulacros'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_simulacro'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/asistencia/simulacros/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_simulacro'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_simulacro'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
  }

  // ---------------- Materias ----------------

  public static function list_materias(WP_REST_Request $req) {
    try {
      self::ensure_tables();
    } catch (Throwable $e) {
      return self::err('Error inicial: ' . $e->getMessage(), 500);
    }
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
      return self::ok(['items' => []]);
    }
    $only_activas = $req->get_param('activo');
    $where = '1=1';
    $params = [];
    if ($only_activas !== null && $only_activas !== '') {
      $where .= ' AND activo=%d';
      $params[] = (int)(bool)$only_activas;
    }
    $sql = "SELECT id, nombre, activo, created_at FROM $t WHERE $where ORDER BY nombre ASC";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  public static function get_materia(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t = $wpdb->prefix . 'conducta_materias';
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, nombre, activo, created_at FROM $t WHERE id=%d", $id), ARRAY_A);
    if (!$row) return self::err('Materia no encontrada', 404);
    return self::ok($row);
  }

  public static function create_materia(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $p = $req->get_json_params() ?: [];
    $nombre = self::norm_str($p['nombre'] ?? '');
    if ($nombre === '') return self::err('El nombre de la materia es obligatorio.');
    $t = $wpdb->prefix . 'conducta_materias';
    $r = $wpdb->insert($t, [
      'nombre' => $nombre,
      'activo' => 1,
    ]);
    if ($r === false) return self::err('Error al crear la materia', 500);
    $id = (int) $wpdb->insert_id;
    return self::ok(['id' => $id], 201);
  }

  public static function update_materia(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $p = $req->get_json_params() ?: [];
    $nombre = self::norm_str($p['nombre'] ?? '');
    $activo = isset($p['activo']) ? (int)(bool)$p['activo'] : null;
    $t = $wpdb->prefix . 'conducta_materias';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d", $id));
    if (!$exists) return self::err('Materia no encontrada', 404);
    $data = [];
    if ($nombre !== '') $data['nombre'] = $nombre;
    if ($activo !== null) $data['activo'] = $activo;
    if (empty($data)) return self::ok(['updated' => true]);
    $wpdb->update($t, $data, ['id' => $id]);
    return self::ok(['updated' => true]);
  }

  public static function delete_materia(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t = $wpdb->prefix . 'conducta_materias';
    $t_d = $wpdb->prefix . 'conducta_materia_docentes';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d", $id));
    if (!$exists) return self::err('Materia no encontrada', 404);
    $wpdb->delete($t_d, ['materia_id' => $id]);
    $wpdb->delete($t, ['id' => $id]);
    return self::ok(['deleted' => true]);
  }

  public static function list_materia_docentes(WP_REST_Request $req) {
    global $wpdb;
    $materia_id = (int) $req['id'];
    $t_md = $wpdb->prefix . 'conducta_materia_docentes';
    $users = $wpdb->prefix . 'users';
    $sql = "SELECT md.id, md.user_id, md.activo, md.created_at,
                   u.display_name
            FROM $t_md md
            LEFT JOIN $users u ON u.ID = md.user_id
            WHERE md.materia_id=%d AND md.activo=1
            ORDER BY u.display_name";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $materia_id), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  public static function add_materia_docente(WP_REST_Request $req) {
    global $wpdb;
    $materia_id = (int) $req['id'];
    $p = $req->get_json_params() ?: [];
    $user_id = (int)($p['user_id'] ?? 0);
    if ($user_id <= 0) return self::err('user_id obligatorio.');
    $t_m = $wpdb->prefix . 'conducta_materias';
    $t_md = $wpdb->prefix . 'conducta_materia_docentes';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_m WHERE id=%d", $materia_id)))
      return self::err('Materia no encontrada', 404);
    $ex = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $t_md WHERE materia_id=%d AND user_id=%d",
      $materia_id, $user_id
    ));
    if ($ex) return self::ok(['id' => (int)$ex, 'already_exists' => true]);
    $wpdb->insert($t_md, ['materia_id' => $materia_id, 'user_id' => $user_id, 'activo' => 1]);
    return self::ok(['id' => (int)$wpdb->insert_id], 201);
  }

  public static function remove_materia_docente(WP_REST_Request $req) {
    global $wpdb;
    $materia_id = (int) $req['id'];
    $user_id = (int) $req['user_id'];
    $t_md = $wpdb->prefix . 'conducta_materia_docentes';
    $wpdb->delete($t_md, ['materia_id' => $materia_id, 'user_id' => $user_id]);
    return self::ok(['deleted' => true]);
  }

  // ---------------- Sesiones de asistencia ----------------

  public static function list_sesiones(WP_REST_Request $req) {
    self::ensure_tables();
    try {
      return self::list_sesiones_query($req);
    } catch (Throwable $e) {
      error_log('[NC_Asistencia] list_sesiones: ' . $e->getMessage());
      return self::err('Error al listar sesiones de asistencia.', 500);
    }
  }

  private static function list_sesiones_query(WP_REST_Request $req) {
    global $wpdb;
    NC_Asistencia_DB::maybe_upgrade();

    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $users = $wpdb->prefix . 'users';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_md = $wpdb->prefix . 'conducta_materia_docentes';

    $fecha_desde = self::req_query_str($req, 'fecha_desde');
    $fecha_hasta = self::req_query_str($req, 'fecha_hasta');
    $materia_id = self::int_or_null(self::req_query_int($req, 'materia_id'));
    $grupo_id = self::int_or_null(self::req_query_int($req, 'grupo_id'));
    $aula_id = self::int_or_null(self::req_query_int($req, 'aula_id'));
    $filter_grupo = $grupo_id ?: $aula_id;
    $solo_mias = self::req_query_int($req, 'solo_mias');
    $current_id = get_current_user_id();

    $has_grupo_col = self::asistencias_has_column('grupo_id');
    $has_sim_col = self::asistencias_has_column('simulacro_id');
    $has_subgrupo_col = self::asistencias_has_column('subgrupo');
    $has_modificado_col = self::asistencias_has_column('modificado_por');
    $has_md_table = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_md) . "'") === $t_md);

    $where = 'a.activo=1';
    $params = [];
    if ($fecha_desde !== '') { $where .= ' AND a.fecha>=%s'; $params[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where .= ' AND a.fecha<=%s'; $params[] = $fecha_hasta; }
    if ($materia_id) { $where .= ' AND a.materia_id=%d'; $params[] = $materia_id; }
    if ($filter_grupo) {
      if ($has_grupo_col) {
        $where .= ' AND (a.grupo_id=%d OR a.aula_id=%d)';
        $params[] = $filter_grupo;
        $params[] = $filter_grupo;
      } else {
        $where .= ' AND a.aula_id=%d';
        $params[] = $filter_grupo;
      }
    }
    // Mis asistencias registradas: solo listas que registró el usuario actual.
    if ($solo_mias && $current_id) {
      $where .= ' AND a.creado_por=%d';
      $params[] = $current_id;
    }

    $group_identifier_expr = $has_grupo_col ? 'COALESCE(a.grupo_id, a.aula_id)' : 'a.aula_id';
    $sel_grupo_id = $has_grupo_col ? 'a.grupo_id' : 'NULL AS grupo_id';
    $sel_simulacro_id = $has_sim_col ? 'a.simulacro_id' : '0 AS simulacro_id';
    $sel_subgrupo = $has_subgrupo_col ? 'a.subgrupo' : "'' AS subgrupo";
    $sel_modificado_por = $has_modificado_col ? 'a.modificado_por' : 'NULL AS modificado_por';

    if ($has_sim_col) {
      $grupo_nombre_expr = "CASE WHEN a.simulacro_id > 0 OR LOWER(COALESCE(a.subgrupo, '')) = 'simulacro' THEN 'Simulacro' ELSE au_g.nombre END";
    } else {
      $grupo_nombre_expr = $has_subgrupo_col
        ? "CASE WHEN LOWER(COALESCE(a.subgrupo, '')) = 'simulacro' THEN 'Simulacro' ELSE au_g.nombre END"
        : 'au_g.nombre';
    }

    if ($has_md_table) {
      $es_reemplazante_expr = "(EXISTS (
        SELECT 1 FROM $t_md md
        WHERE md.materia_id = a.materia_id AND md.user_id = a.creado_por AND md.activo = 1
      ) AND a.docente_encargado_id IS NOT NULL AND a.docente_encargado_id <> a.creado_por)";
    } else {
      $es_reemplazante_expr = '(a.docente_encargado_id IS NOT NULL AND a.docente_encargado_id <> a.creado_por)';
    }

    $join_modificado = $has_modificado_col
      ? "LEFT JOIN $users u3 ON u3.ID=a.modificado_por"
      : "LEFT JOIN $users u3 ON 1=0";

    $sql = "SELECT a.id, a.fecha, a.materia_id, $sel_grupo_id, a.aula_id, a.curso_id, $sel_simulacro_id, $sel_subgrupo,
                   a.creado_por, a.docente_encargado_id, $sel_modificado_por,
                   a.observacion_general, a.created_at, a.modified_at,
                   m.nombre AS materia_nombre,
                   c.nombre AS curso_nombre,
                   au_f.nombre AS aula_fisica_nombre,
                   $grupo_nombre_expr AS grupo_nombre,
                   u1.display_name AS creado_por_nombre,
                   u2.display_name AS docente_encargado_nombre,
                   CASE WHEN $es_reemplazante_expr THEN 1 ELSE 0 END AS es_reemplazante,
                   u3.display_name AS modificado_por_nombre
            FROM $t_asis a
            LEFT JOIN $t_mat m ON m.id=a.materia_id
            LEFT JOIN $t_cur c ON c.id=a.curso_id
            LEFT JOIN $t_aul au_f ON au_f.id=a.aula_id
            LEFT JOIN $t_aul au_g ON au_g.id = $group_identifier_expr
            LEFT JOIN $users u1 ON u1.ID=a.creado_por
            LEFT JOIN $users u2 ON u2.ID=a.docente_encargado_id
            $join_modificado
            WHERE $where
            ORDER BY a.fecha DESC, a.created_at DESC
            LIMIT 2000";

    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    if ($rows === null) {
      error_log('[NC_Asistencia] list_sesiones SQL error: ' . $wpdb->last_error);
      error_log('[NC_Asistencia] list_sesiones SQL: ' . $sql);
      // Consulta mínima de respaldo (sin JOINs complejos)
      $sql_fb = "SELECT a.id, a.fecha, a.materia_id, a.aula_id, a.curso_id, a.creado_por, a.docente_encargado_id,
                        a.created_at, a.modified_at, m.nombre AS materia_nombre, c.nombre AS curso_nombre
                 FROM $t_asis a
                 LEFT JOIN $t_mat m ON m.id=a.materia_id
                 LEFT JOIN $t_cur c ON c.id=a.curso_id
                 WHERE $where
                 ORDER BY a.fecha DESC, a.created_at DESC
                 LIMIT 2000";
      $rows = $params ? $wpdb->get_results($wpdb->prepare($sql_fb, $params), ARRAY_A) : $wpdb->get_results($sql_fb, ARRAY_A);
      if ($rows === null) {
        return self::err('Error al consultar sesiones de asistencia.', 500);
      }
      foreach ($rows as &$r) {
        $r['grupo_nombre'] = '';
        $r['aula_fisica_nombre'] = '';
        $r['creado_por_nombre'] = '';
        $r['docente_encargado_nombre'] = '';
        $r['modificado_por_nombre'] = '';
        $r['es_reemplazante'] = 0;
        $r['presentes_total'] = '0/0';
      }
      unset($r);
    }
    if (!$rows) {
      return self::ok(['items' => [], 'total' => 0]);
    }

    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $counts = $wpdb->get_results($wpdb->prepare(
      "SELECT i.asistencia_id, COUNT(*) AS total, COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS presentes
       FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       WHERE i.asistencia_id IN ($placeholders)
       GROUP BY i.asistencia_id",
      $ids
    ), ARRAY_A);
    $by_id = [];
    foreach ($counts as $c) { $by_id[(int)$c['asistencia_id']] = [(int)$c['total'], (int)$c['presentes']]; }
    foreach ($rows as &$r) {
      $r['presentes_total'] = isset($by_id[$r['id']]) ? $by_id[$r['id']][1] . '/' . $by_id[$r['id']][0] : '0/0';
      self::apply_simulacro_sesion_display($r);
    }
    unset($r);
    $response = self::ok(['items' => $rows, 'total' => count($rows)]);
    $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->header('Pragma', 'no-cache');
    return $response;
  }

  public static function get_sesion(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $users = $wpdb->prefix . 'users';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT a.id, a.fecha, a.materia_id, a.aula_id, a.grupo_id, a.curso_id, a.simulacro_id, a.subgrupo, a.creado_por, a.docente_encargado_id,
              a.observacion_general, a.created_at, a.modified_at,
              m.nombre AS materia_nombre, au.nombre AS aula_nombre,
              u1.display_name AS creado_por_nombre, u2.display_name AS docente_encargado_nombre
       FROM $t_asis a
       LEFT JOIN $t_mat m ON m.id=a.materia_id
       LEFT JOIN $t_aul au ON au.id=a.aula_id
       LEFT JOIN $users u1 ON u1.ID=a.creado_por
       LEFT JOIN $users u2 ON u2.ID=a.docente_encargado_id
       WHERE a.id=%d AND a.activo=1",
      $id
    ), ARRAY_A);
    if (!$row) return self::err('Sesión no encontrada', 404);

    $items = $wpdb->get_results($wpdb->prepare(
      "SELECT i.id, i.alumno_id,
              COALESCE(m.asistio, i.asistio) AS asistio,
              COALESCE(NULLIF(TRIM(m.observacion), ''), i.observacion) AS observacion,
              i.modified_at
       FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       LEFT JOIN $t_al al ON al.id = i.alumno_id
       WHERE i.asistencia_id = %d
       ORDER BY al.apellidos ASC, al.nombres ASC",
      $id
    ), ARRAY_A);
    $alumno_ids = array_unique(array_column($items, 'alumno_id'));
    $alumnos = [];
    if ($alumno_ids) {
      $ph = implode(',', array_map('intval', $alumno_ids));
      $al_rows = $wpdb->get_results("SELECT id, nombres, apellidos, ci FROM $t_al WHERE id IN ($ph)", ARRAY_A);
      foreach ($al_rows as $a) { $alumnos[(int)$a['id']] = $a; }
    }
    foreach ($items as &$it) {
      $it['alumno_nombres'] = $alumnos[(int)$it['alumno_id']]['nombres'] ?? '';
      $it['alumno_apellidos'] = $alumnos[(int)$it['alumno_id']]['apellidos'] ?? '';
      $it['alumno_ci'] = $alumnos[(int)$it['alumno_id']]['ci'] ?? '';
    }
    unset($it);
    $row['items'] = $items;
    $total = count($items);
    $presentes = array_sum(array_column($items, 'asistio'));
    $row['presentes_total'] = $presentes . '/' . $total;
    self::apply_simulacro_sesion_display($row);
    return self::ok($row);
  }

  public static function create_sesion(WP_REST_Request $req) {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);
    $p = $req->get_json_params() ?: [];
    $fecha = self::norm_str($p['fecha'] ?? '');
    $materia_id = (int)($p['materia_id'] ?? 0);
    $grupo_id = (int)($p['grupo_id'] ?? 0);
    $aula_id = (int)($p['aula_id'] ?? 0);
    $curso_id = self::int_or_null($p['curso_id'] ?? null);
    $docente_encargado_id = self::int_or_null($p['docente_encargado_id'] ?? null);
    $observacion_general = isset($p['observacion_general'])
      ? sanitize_textarea_field((string)$p['observacion_general'])
      : '';
    $subgrupo = isset($p['subgrupo']) ? trim(sanitize_text_field((string) $p['subgrupo'])) : null;
    $subgrupo = ($subgrupo !== '' && $subgrupo !== null) ? $subgrupo : null;
    $simulacro_id = (int) ($p['simulacro_id'] ?? 0);
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    if ($fecha === '' || !$materia_id || (!$aula_id && !$grupo_id && $simulacro_id <= 0)) {
      return self::err('fecha, materia_id y al menos grupo_id o aula_id son obligatorios.');
    }

    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_cm = $wpdb->prefix . 'conducta_curso_materias';
    $t_al_c = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';

    // Lista simulacro: grupo mezclado; no inferir curso/grupo desde alumnos.
    if ($simulacro_id > 0) {
      $t_sim = $wpdb->prefix . 'conducta_simulacros';
      $sim_ok = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_sim WHERE id=%d AND activo=1", $simulacro_id));
      if (!$sim_ok) {
        return self::err('Simulacro no encontrado.', 404);
      }
      $grupo_id = 0;
      $curso_id = null;
      $subgrupo = 'Simulacro';
    }

    // El curso se infiere por materia + grupo (no se selecciona manualmente en front).
    // Si no se puede inferir, caemos al legacy.
    if (!$curso_id && $simulacro_id <= 0) {
      $has_rel = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_c) . "'") === $t_al_c)
        && ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
      if ($grupo_id && $has_rel) {
        $curso_id = $wpdb->get_var($wpdb->prepare(
          "SELECT cm.curso_id
           FROM $t_cm cm
           WHERE cm.materia_id = %d
             AND EXISTS (
               SELECT 1
               FROM $t_al_a ag
               INNER JOIN $t_al_c ac ON ac.alumno_id = ag.alumno_id AND ac.curso_id = cm.curso_id AND ac.activo = 1
               INNER JOIN $t_al al ON al.id = ag.alumno_id AND al.activo = 1
               WHERE ag.aula_id = %d AND ag.activo = 1
             )
           LIMIT 1",
          $materia_id,
          $grupo_id
        ));
      }
      if (!$curso_id) {
        if ($grupo_id) {
          $curso_id = $wpdb->get_var($wpdb->prepare(
            "SELECT curso_id FROM $t_al WHERE grupo_id=%d AND activo=1 LIMIT 1",
            $grupo_id
          ));
        } elseif ($aula_id) {
          $curso_id = $wpdb->get_var($wpdb->prepare(
            "SELECT curso_id FROM $t_al WHERE aula_id=%d AND activo=1 LIMIT 1",
            $aula_id
          ));
        }
      }
      $curso_id = $curso_id ? (int)$curso_id : null;
    }

    // Fecha/hora de creación en zona horaria de Paraguay (America/Asuncion)
    $dt_py = new DateTimeImmutable('now', new DateTimeZone('America/Asuncion'));
    $created_at_py = $dt_py->format('Y-m-d H:i:s');

    $insert_data = [
      'fecha' => $fecha,
      'materia_id' => $materia_id,
      'grupo_id' => $grupo_id ?: null,
      'aula_id' => $aula_id ?: null,
      'curso_id' => $curso_id,
      'creado_por' => $user_id,
      'docente_encargado_id' => $docente_encargado_id,
      'observacion_general' => $observacion_general !== '' ? $observacion_general : null,
      'created_at' => $created_at_py,
      'activo' => 1,
    ];
    if ($subgrupo !== null && $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'subgrupo'",
      $t_asis
    ))) {
      $insert_data['subgrupo'] = $subgrupo;
    }
    if ($simulacro_id > 0 && $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'simulacro_id'",
      $t_asis
    ))) {
      $insert_data['simulacro_id'] = $simulacro_id;
    }
    $wpdb->insert($t_asis, $insert_data);
    $asistencia_id = (int) $wpdb->insert_id;
    if ($asistencia_id <= 0) return self::err('Error al crear la sesión', 500);

    foreach ($items as $it) {
      $alumno_id = (int)($it['alumno_id'] ?? 0);
      if ($alumno_id <= 0) continue;
      $asistio = isset($it['asistio']) ? (int)(bool)$it['asistio'] : 1;
      $obs = isset($it['observacion']) ? sanitize_textarea_field((string)$it['observacion']) : null;
      $wpdb->insert($t_items, [
        'asistencia_id' => $asistencia_id,
        'alumno_id' => $alumno_id,
        'asistio' => $asistio,
        'observacion' => $obs ?: null,
      ]);
    }
    return self::ok(['id' => $asistencia_id], 201);
  }

  public static function update_sesion(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);

    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, creado_por, docente_encargado_id FROM $t_asis WHERE id=%d AND activo=1", $id), ARRAY_A);
    if (!$row) return self::err('Sesión no encontrada', 404);
    if (!self::user_can_edit_sesion($row, $user_id)) {
      return self::err('No tiene permiso para editar esta asistencia', 403);
    }

    $raw = $req->get_body();

    // Leer body crudo primero (evitar que parse_str corrompa JSON largo en form-urlencoded)
    $p = [];
    if (is_string($raw) && $raw !== '') {
      $trimmed = trim($raw);
      if ($trimmed !== '' && $trimmed[0] === '{') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $p = $decoded;
      } elseif (strpos($raw, 'items=') === 0) {
        $value = substr($raw, 6);
        $value = urldecode($value);
        $decoded = json_decode($value, true);
        if (is_array($decoded)) $p = ['items' => $decoded];
      }
    }
    if (!is_array($p) || empty($p)) {
      $p = $req->get_json_params();
    }
    if (!is_array($p) || empty($p)) {
      $raw = @file_get_contents('php://input');
      if (is_string($raw) && $raw !== '') {
        $trimmed = trim($raw);
        if ($trimmed !== '' && $trimmed[0] === '{') {
          $decoded = json_decode($raw, true);
          if (is_array($decoded)) $p = $decoded;
        } elseif (strpos($raw, 'items=') === 0) {
          $value = substr($raw, 6);
          $value = urldecode($value);
          $decoded = json_decode($value, true);
          if (is_array($decoded)) $p = ['items' => $decoded];
        }
      }
    }
    $p = is_array($p) ? $p : [];
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : null;
    // #region agent log
    @file_put_contents($log_path, json_encode(['location'=>'update_sesion:parsed','message'=>'items parseados','data'=>['itemsCount'=>$items===null?0:count($items),'sample'=>$items&&count($items)>0?array_slice($items,0,2):[]],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'E'])."\n", FILE_APPEND | LOCK_EX);
    // #endregion
    if ($items === null) {
      return self::err('Faltan los datos de asistencia (items). Envíe el body en JSON con clave "items".', 400);
    }

    // Actualizar materia y docente si se envían
    $update_sesion = [];
    if (array_key_exists('materia_id', $p)) {
      $materia_id = (int)($p['materia_id'] ?? 0);
      if ($materia_id > 0) $update_sesion['materia_id'] = $materia_id;
    }
    if (array_key_exists('docente_encargado_id', $p)) {
      $update_sesion['docente_encargado_id'] = self::int_or_null($p['docente_encargado_id']);
    }
    if (array_key_exists('observacion_general', $p)) {
      $obsGen = sanitize_textarea_field((string)($p['observacion_general'] ?? ''));
      $update_sesion['observacion_general'] = ($obsGen !== '') ? $obsGen : null;
    }
    if (!empty($update_sesion)) {
      $wpdb->update($t_asis, $update_sesion, ['id' => $id]);
    }

    $current = $wpdb->get_results($wpdb->prepare(
      "SELECT id, alumno_id, asistio, observacion FROM $t_items WHERE asistencia_id=%d",
      $id
    ), ARRAY_A);
    $by_id = [];
    $by_alumno = [];
    foreach ($current as $c) {
      $by_id[(int)$c['id']] = $c;
      $by_alumno[(int)$c['alumno_id']] = $c;
    }

    // Insertar nuevos alumnos (invitados) que aún no están en la sesión
    foreach ($items as $it) {
      $alumno_id = (int)($it['alumno_id'] ?? 0);
      if ($alumno_id <= 0) continue;
      if (isset($by_alumno[$alumno_id])) continue;
      $asistio_raw = $it['asistio'] ?? 1;
      $asistio = ($asistio_raw === 0 || $asistio_raw === '0' || $asistio_raw === false) ? 0 : 1;
      $obs = isset($it['observacion']) ? sanitize_textarea_field((string)$it['observacion']) : '';
      if ($obs === null) $obs = '';
      $wpdb->insert($t_items, [
        'asistencia_id' => $id,
        'alumno_id' => $alumno_id,
        'asistio' => $asistio,
        'observacion' => $obs,
      ], ['%d', '%d', '%d', '%s']);
      if ($wpdb->last_error) {
        return self::err('Error al agregar alumno a la sesión: ' . $wpdb->last_error, 500);
      }
      $new_id = (int) $wpdb->insert_id;
      $by_id[$new_id] = ['id' => $new_id, 'alumno_id' => $alumno_id, 'asistio' => $asistio, 'observacion' => $obs];
      $by_alumno[$alumno_id] = $by_id[$new_id];
    }

    // Guardar modificaciones en wp_conducta_asistencia_modificaciones
    foreach ($items as $it) {
      $item_id = (int)($it['id'] ?? 0);
      $alumno_id = (int)($it['alumno_id'] ?? 0);
      if ($alumno_id <= 0 && $item_id <= 0) continue;
      $asistio_raw = $it['asistio'] ?? 1;
      $asistio = ($asistio_raw === 0 || $asistio_raw === '0' || $asistio_raw === false) ? 0 : 1;
      $obs = isset($it['observacion']) ? sanitize_textarea_field((string)$it['observacion']) : '';
      if ($obs === null) $obs = '';
      $ex = ($item_id > 0 && isset($by_id[$item_id])) ? $by_id[$item_id] : ($by_alumno[$alumno_id] ?? null);
      if ($ex) {
        $wpdb->replace($t_mod, [
          'asistencia_id' => $id,
          'asistencia_item_id' => (int) $ex['id'],
          'asistio' => $asistio,
          'observacion' => $obs,
          'modificado_por' => $user_id,
        ], ['%d', '%d', '%d', '%s', '%d']);
        if ($wpdb->last_error) {
          return self::err('Error al guardar modificación: ' . $wpdb->last_error, 500);
        }
      }
    }
    // #region agent log
    @file_put_contents($log_path, json_encode(['location'=>'update_sesion:after-replace','message'=>'después de replace en modificaciones','data'=>['last_error'=>$wpdb->last_error,'itemsProcessed'=>count($items)],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'F'])."\n", FILE_APPEND | LOCK_EX);
    // #endregion
    $wpdb->query($wpdb->prepare("UPDATE $t_asis SET modified_at=%s, modificado_por=%d WHERE id=%d", current_time('mysql'), $user_id, $id));

    // Totales aplicando modificaciones (COALESCE mod.asistio, item.asistio)
    $row_counts = $wpdb->get_row($wpdb->prepare(
      "SELECT COUNT(*) AS total,
              COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS presentes
       FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       WHERE i.asistencia_id = %d",
      $id
    ), ARRAY_A);
    $total = (int)($row_counts['total'] ?? 0);
    $presentes = (int)($row_counts['presentes'] ?? 0);
    // #region agent log
    @file_put_contents($log_path, json_encode(['location'=>'update_sesion:return','message'=>'totales calculados','data'=>['total'=>$total,'presentes'=>$presentes,'presentes_total'=>$presentes.'/'.$total],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'G'])."\n", FILE_APPEND | LOCK_EX);
    // #endregion
    return self::ok([
      'updated' => true,
      'presentes' => $presentes,
      'total' => $total,
      'presentes_total' => $presentes . '/' . $total,
    ]);
  }

  /**
   * Eliminar (desactivar) una sesión de asistencia.
   * Pueden eliminar: administradores, quien la registró o el docente asignado.
   */
  public static function delete_sesion(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);

    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, creado_por, docente_encargado_id FROM $t_asis WHERE id=%d AND activo=1", $id), ARRAY_A);
    if (!$row) return self::err('Sesión no encontrada', 404);
    if (!self::user_can_edit_sesion($row, $user_id)) {
      return self::err('No tiene permiso para eliminar esta asistencia', 403);
    }

    $wpdb->update($t_asis, ['activo' => 0], ['id' => $id], ['%d'], ['%d']);
    if ($wpdb->last_error) return self::err('Error al eliminar la sesión', 500);
    return self::ok(['deleted' => true]);
  }

  /** Actualizar un solo ítem de asistencia (historial por alumno). */
  public static function update_item(WP_REST_Request $req) {
    global $wpdb;
    $item_id = (int) $req['id'];
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);

    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';

    $item = $wpdb->get_row($wpdb->prepare(
      "SELECT i.id, i.asistencia_id, i.asistio, i.observacion FROM $t_items i WHERE i.id=%d",
      $item_id
    ), ARRAY_A);
    if (!$item) return self::err('Registro no encontrado', 404);

    $sesion = $wpdb->get_row($wpdb->prepare(
      "SELECT id, creado_por, docente_encargado_id FROM $t_asis WHERE id=%d AND activo=1",
      $item['asistencia_id']
    ), ARRAY_A);
    if (!$sesion) return self::err('Sesión no encontrada', 404);

    if (!self::user_can_edit_sesion($sesion, $user_id)) {
      return self::err('No tiene permiso para editar este registro', 403);
    }

    $p = $req->get_json_params();
    if (!is_array($p) || empty($p)) {
      $raw = $req->get_body();
      if (is_string($raw) && $raw !== '') {
        $ct = $req->get_header('Content-Type') ?: '';
        if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
          parse_str($raw, $form);
          if (is_array($form)) {
            $p['asistio'] = $form['asistio'] ?? null;
            $p['observacion'] = $form['observacion'] ?? null;
          }
        } else {
          $dec = json_decode($raw, true);
          if (is_array($dec)) $p = $dec;
        }
      }
    }
    $p = is_array($p) ? $p : [];
    if (!isset($p['asistio'])) $p['asistio'] = $req->get_param('asistio');
    if (!isset($p['observacion'])) $p['observacion'] = $req->get_param('observacion');
    $asistio = isset($p['asistio']) && $p['asistio'] !== '' ? (int)(bool)$p['asistio'] : (int)$item['asistio'];
    $observacion = isset($p['observacion']) ? sanitize_textarea_field((string)$p['observacion']) : (string)($item['observacion'] ?? '');

    $updated = $wpdb->update(
      $t_items,
      ['asistio' => $asistio, 'observacion' => $observacion],
      ['id' => $item_id],
      ['%d', '%s'],
      ['%d']
    );
    if ($updated === false && $wpdb->last_error) {
      return self::err('Error BD al actualizar: ' . $wpdb->last_error, 500);
    }
    $wpdb->replace($t_mod, [
      'asistencia_id' => (int) $item['asistencia_id'],
      'asistencia_item_id' => $item_id,
      'asistio' => $asistio,
      'observacion' => $observacion,
      'modificado_por' => $user_id,
    ], ['%d', '%d', '%d', '%s', '%d']);
    $wpdb->query($wpdb->prepare("UPDATE $t_asis SET modified_at=%s, modificado_por=%d WHERE id=%d", current_time('mysql'), $user_id, $item['asistencia_id']));

    $updated_item = $wpdb->get_row($wpdb->prepare(
      "SELECT i.id, i.asistencia_id, i.alumno_id, i.asistio, i.observacion FROM $t_items i WHERE i.id=%d",
      $item_id
    ), ARRAY_A);

    $presentes = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) FROM $t_items i LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id WHERE i.asistencia_id = %d",
      $item['asistencia_id']
    ));

    $total = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t_items WHERE asistencia_id=%d",
      $item['asistencia_id']
    ));
    
    $porcentaje = $total > 0 ? round(($presentes / $total) * 100, 2) : 0;
    
    return self::ok([
      'updated' => true,
      'item' => $updated_item,
      'total_presentes' => $presentes,
      'total_estudiantes' => $total,
      'porcentaje' => $porcentaje,
      'formato' => "{$presentes}/{$total}"
    ]);
  }

  /** 
   * Obtiene el total actualizado de presentes para una sesión de asistencia.
   * Endpoint: GET /asistencia/sesiones/{id}/total-presentes
   */
  public static function get_total_presentes(WP_REST_Request $req) {
    global $wpdb;
    $asistencia_id = (int) $req['id'];
    
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    
    // Validar que la sesión existe
    $sesion = $wpdb->get_row($wpdb->prepare(
      "SELECT id FROM $t_asis WHERE id=%d AND activo=1",
      $asistencia_id
    ), ARRAY_A);
    if (!$sesion) return self::err('Sesión no encontrada', 404);
    
    // Contar presentes aplicando modificaciones (COALESCE mod.asistio, item.asistio)
    $presentes = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(COALESCE(m.asistio, i.asistio)) FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       WHERE i.asistencia_id = %d",
      $asistencia_id
    ));
    
    // Contar total de estudiantes
    $total = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t_items WHERE asistencia_id=%d",
      $asistencia_id
    ));
    
    // Calcular porcentaje
    $porcentaje = $total > 0 ? round(($presentes / $total) * 100, 2) : 0;
    
    return self::ok([
      'presentes' => $presentes,
      'total' => $total,
      'porcentaje' => $porcentaje,
      'formato' => "{$presentes}/{$total}"
    ]);
  }

  /** Endpoint de prueba: devuelve { "ok": true } si el módulo responde. */
  public static function ping(WP_REST_Request $req) {
    return self::ok(['ok' => true]);
  }

  // ---------------- Dashboard ----------------

  public static function dashboard(WP_REST_Request $req) {
    // Respuesta vacía por defecto
    $empty = [
      'mes_actual' => date('Y-m'),
      'mes_anterior' => date('Y-m', strtotime('-1 month')),
      'promedio_actual' => 0,
      'promedio_anterior' => 0,
      'sesiones_mes_actual' => 0,
      'sesiones_mes_anterior' => 0,
      'por_aula' => [],
    ];

    // Envolver todo en try-catch para capturar cualquier error
    try {
      global $wpdb;
      $t_asis = $wpdb->prefix . 'conducta_asistencias';
      $t_items = $wpdb->prefix . 'conducta_asistencia_items';
      $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';
      $t_aul = $wpdb->prefix . 'conducta_aulas';

      // Verificar que las tablas existan
      $tables = $wpdb->get_col('SHOW TABLES');
      if (!is_array($tables)) {
        error_log('[NC_Asistencia] dashboard: No se pudieron obtener las tablas');
        return self::ok($empty);
      }

      if (!in_array($t_asis, $tables, true) || !in_array($t_items, $tables, true)) {
        return self::ok($empty);
      }
      $subq_totales = "(SELECT i.asistencia_id, COUNT(*) AS total, COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS presentes FROM $t_items i LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id GROUP BY i.asistencia_id)";

      // Obtener mes del parámetro o usar el mes actual
      $mes_param = self::norm_str($req->get_param('mes'));
      if (empty($mes_param) || !preg_match('/^\d{4}-\d{2}$/', $mes_param)) {
        $mes_actual = date('Y-m');
      } else {
        $mes_actual = $mes_param;
      }
      $mes_anterior = date('Y-m', strtotime($mes_actual . '-01 -1 month'));

      // Consulta para mes actual (totales con modificaciones aplicadas)
      $totales = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(i.total),0) AS total_registros,
                  COALESCE(SUM(i.presentes),0) AS total_presentes
           FROM (SELECT id FROM $t_asis WHERE activo=1 AND DATE_FORMAT(fecha,'%%Y-%%m')=%s) a
           LEFT JOIN $subq_totales i ON i.asistencia_id=a.id",
          $mes_actual
        ),
        ARRAY_A
      );
      if (!is_array($totales)) {
        $totales = ['sesiones' => 0, 'total_registros' => 0, 'total_presentes' => 0];
      }

      // Consulta para mes anterior (totales con modificaciones aplicadas)
      $totales_ant = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(i.total),0) AS total_registros,
                  COALESCE(SUM(i.presentes),0) AS total_presentes
           FROM (SELECT id FROM $t_asis WHERE activo=1 AND DATE_FORMAT(fecha,'%%Y-%%m')=%s) a
           LEFT JOIN $subq_totales i ON i.asistencia_id=a.id",
          $mes_anterior
        ),
        ARRAY_A
      );
      if (!is_array($totales_ant)) {
        $totales_ant = ['sesiones' => 0, 'total_registros' => 0, 'total_presentes' => 0];
      }

      // Consulta por aula
      $por_aula = [];
      if (in_array($t_aul, $tables, true)) {
        $por_aula_raw = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT a.aula_id, au.nombre AS aula_nombre,
                    COUNT(DISTINCT a.id) AS sesiones,
                    COALESCE(SUM(ii.total),0) AS total_registros,
                    COALESCE(SUM(ii.presentes),0) AS total_presentes
             FROM $t_asis a
             LEFT JOIN $t_aul au ON au.id=a.aula_id
             LEFT JOIN $subq_totales ii ON ii.asistencia_id=a.id
             WHERE a.activo=1 AND DATE_FORMAT(a.fecha,'%%Y-%%m')=%s
             GROUP BY a.aula_id, au.nombre
             ORDER BY au.nombre",
            $mes_actual
          ),
          ARRAY_A
        );
        $por_aula = is_array($por_aula_raw) ? $por_aula_raw : [];
      }

      // Calcular promedios
      $promedio_actual = 0;
      $tr = isset($totales['total_registros']) ? (float)$totales['total_registros'] : 0;
      if ($tr > 0 && isset($totales['total_presentes'])) {
        $promedio_actual = round(100 * (float)$totales['total_presentes'] / $tr, 1);
      }

      $promedio_anterior = 0;
      $tr_ant = isset($totales_ant['total_registros']) ? (float)$totales_ant['total_registros'] : 0;
      if ($tr_ant > 0 && isset($totales_ant['total_presentes'])) {
        $promedio_anterior = round(100 * (float)$totales_ant['total_presentes'] / $tr_ant, 1);
      }

      // Calcular porcentajes por aula
      foreach ($por_aula as $k => $a) {
        $por_aula[$k]['porcentaje'] = 0;
        $r = isset($a['total_registros']) ? (float)$a['total_registros'] : 0;
        if ($r > 0 && isset($a['total_presentes'])) {
          $por_aula[$k]['porcentaje'] = round(100 * (float)$a['total_presentes'] / $r, 1);
        }
      }

      return self::ok([
        'mes_actual' => $mes_actual,
        'mes_anterior' => $mes_anterior,
        'promedio_actual' => $promedio_actual,
        'promedio_anterior' => $promedio_anterior,
        'sesiones_mes_actual' => (int)($totales['sesiones'] ?? 0),
        'sesiones_mes_anterior' => (int)($totales_ant['sesiones'] ?? 0),
        'por_aula' => $por_aula,
      ]);
    } catch (Throwable $e) {
      error_log('[NC_Asistencia] dashboard error: ' . $e->getMessage());
      error_log('[NC_Asistencia] dashboard trace: ' . $e->getTraceAsString());
      return self::ok($empty);
    }
  }

  /** Dashboard histórico: datos para gráficos. ?agrupacion=mes|semanas|dias (default mes). */
  public static function dashboard_historial(WP_REST_Request $req) {
    $empty = ['meses' => [], 'por_aula' => []];
    try {
      global $wpdb;
      $t_asis = $wpdb->prefix . 'conducta_asistencias';
      $t_items = $wpdb->prefix . 'conducta_asistencia_items';
      $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';
      $t_aul = $wpdb->prefix . 'conducta_aulas';

      $tables = $wpdb->get_col('SHOW TABLES');
      if (!is_array($tables) || !in_array($t_asis, $tables, true) || !in_array($t_items, $tables, true)) {
        return self::ok($empty);
      }

      $agrupacion = strtolower((string) $req->get_param('agrupacion'));
      if (!in_array($agrupacion, ['dias', 'semanas', 'mes'], true)) {
        $agrupacion = 'mes';
      }

      $meses = [];
      $subq = "(SELECT i.asistencia_id, COUNT(*) AS total, COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS presentes FROM $t_items i LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id GROUP BY i.asistencia_id)";

      if ($agrupacion === 'dias') {
        $meses_data = $wpdb->get_results(
          "SELECT DATE_FORMAT(a.fecha,'%Y-%m-%d') AS mes,
                  COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(i.total),0) AS total_registros,
                  COALESCE(SUM(i.presentes),0) AS total_presentes
           FROM $t_asis a
           LEFT JOIN $subq i ON i.asistencia_id=a.id
           WHERE a.activo=1 AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           GROUP BY a.fecha
           ORDER BY a.fecha ASC",
          ARRAY_A
        );
      } elseif ($agrupacion === 'semanas') {
        $meses_data = $wpdb->get_results(
          "SELECT CONCAT(YEAR(a.fecha), '-S', LPAD(WEEK(a.fecha,3),2,'0')) AS mes,
                  COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(i.total),0) AS total_registros,
                  COALESCE(SUM(i.presentes),0) AS total_presentes
           FROM $t_asis a
           LEFT JOIN $subq i ON i.asistencia_id=a.id
           WHERE a.activo=1 AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
           GROUP BY YEAR(a.fecha), WEEK(a.fecha,3)
           ORDER BY YEAR(a.fecha) ASC, WEEK(a.fecha,3) ASC",
          ARRAY_A
        );
      } else {
        $meses_data = $wpdb->get_results(
          "SELECT DATE_FORMAT(a.fecha,'%Y-%m') AS mes,
                  COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(i.total),0) AS total_registros,
                  COALESCE(SUM(i.presentes),0) AS total_presentes
           FROM $t_asis a
           LEFT JOIN $subq i ON i.asistencia_id=a.id
           WHERE a.activo=1 AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           GROUP BY DATE_FORMAT(a.fecha,'%Y-%m')
           ORDER BY mes ASC",
          ARRAY_A
        );
      }

      foreach ($meses_data as $m) {
        $pct = 0;
        $tr = (float)($m['total_registros'] ?? 0);
        if ($tr > 0) {
          $pct = round(100 * (float)($m['total_presentes'] ?? 0) / $tr, 1);
        }
        $meses[] = [
          'mes' => $m['mes'],
          'porcentaje' => $pct,
          'sesiones' => (int)($m['sesiones'] ?? 0),
        ];
      }

      // Datos por aula por mes
      $por_aula = [];
      if (in_array($t_aul, $tables, true)) {
        $aulas_meses = $wpdb->get_results(
          "SELECT DATE_FORMAT(a.fecha,'%Y-%m') AS mes,
                  a.aula_id,
                  au.nombre AS aula_nombre,
                  COUNT(DISTINCT a.id) AS sesiones,
                  COALESCE(SUM(ii.total),0) AS total_registros,
                  COALESCE(SUM(ii.presentes),0) AS total_presentes
           FROM $t_asis a
           LEFT JOIN $t_aul au ON au.id=a.aula_id
           LEFT JOIN $subq ii ON ii.asistencia_id=a.id
           WHERE a.activo=1 AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           GROUP BY DATE_FORMAT(a.fecha,'%Y-%m'), a.aula_id, au.nombre
           ORDER BY mes DESC, au.nombre",
          ARRAY_A
        );
        $por_aula_map = [];
        foreach ($aulas_meses as $am) {
          $aula_id = (int)$am['aula_id'];
          $mes = $am['mes'];
          if (!isset($por_aula_map[$aula_id])) {
            $por_aula_map[$aula_id] = ['aula_id' => $aula_id, 'aula_nombre' => $am['aula_nombre'], 'meses' => []];
          }
          $pct = 0;
          $tr = (float)($am['total_registros'] ?? 0);
          if ($tr > 0) {
            $pct = round(100 * (float)($am['total_presentes'] ?? 0) / $tr, 1);
          }
          $por_aula_map[$aula_id]['meses'][] = [
            'mes' => $mes,
            'porcentaje' => $pct,
            'sesiones' => (int)($am['sesiones'] ?? 0),
          ];
        }
        $por_aula = array_values($por_aula_map);
      }

      return self::ok(['meses' => $meses, 'por_aula' => $por_aula]);
    } catch (Throwable $e) {
      error_log('[NC_Asistencia] dashboard_historial error: ' . $e->getMessage());
      return self::ok($empty);
    }
  }

  // ---------------- Lista alumnos (con promedio asistencia) ----------------

  public static function lista_alumnos(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';

    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $search = self::norm_str($req->get_param('search'));
    $subgrupo = trim(sanitize_text_field((string) ($req->get_param('subgrupo') ?? '')));
    $materia_id = self::int_or_null($req->get_param('materia_id'));

    $where = 'a.activo=1';
    $params = [];
    if ($aula_id) { $where .= ' AND a.aula_id=%d'; $params[] = $aula_id; }
    if ($curso_id) { $where .= ' AND a.curso_id=%d'; $params[] = $curso_id; }
    if ($subgrupo !== '') {
      $col_subgrupo = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'subgrupo'",
        $t_al
      ));
      if ($col_subgrupo) {
        $where .= ' AND a.subgrupo=%s';
        $params[] = $subgrupo;
      }
    }
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($materia_id && $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") === $t_am) {
      $where .= ' AND EXISTS (SELECT 1 FROM ' . $t_am . ' am WHERE am.alumno_id=a.id AND am.materia_id=%d)';
      $params[] = $materia_id;
    }
    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $where .= ' AND (a.nombres LIKE %s OR a.apellidos LIKE %s OR a.ci LIKE %s)';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }

    $sel_subgrupo = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'subgrupo'",
      $t_al
    )) ? ', a.subgrupo' : '';
    $sql = "SELECT a.id, a.nombres, a.apellidos, a.ci, a.foto_url, a.curso_id, a.aula_id{$sel_subgrupo},
                   c.nombre AS curso_nombre, au.nombre AS aula_nombre
            FROM $t_al a
            LEFT JOIN $t_cur c ON c.id=a.curso_id
            LEFT JOIN $t_aul au ON au.id=a.aula_id
            WHERE $where
            ORDER BY a.apellidos ASC, a.nombres ASC";
    $alumnos = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    if (!$alumnos) return self::ok(['items' => []]);

    $alumno_ids = array_column($alumnos, 'id');
    $ph = implode(',', array_map('intval', $alumno_ids));
    $stats = $wpdb->get_results(
      "SELECT i.alumno_id, COUNT(*) AS total_clases, COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS asistidas
       FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       INNER JOIN $t_asis s ON s.id=i.asistencia_id AND s.activo=1
       WHERE i.alumno_id IN ($ph)
       GROUP BY i.alumno_id",
      ARRAY_A
    );
    $stats_by_id = [];
    foreach ($stats as $s) { $stats_by_id[(int)$s['alumno_id']] = [(int)$s['total_clases'], (int)$s['asistidas']]; }
    foreach ($alumnos as &$a) {
      $st = $stats_by_id[(int)$a['id']] ?? [0, 0];
      $a['total_clases'] = $st[0];
      $a['asistidas'] = $st[1];
      $a['promedio_texto'] = $st[1] . '/' . $st[0];
      $a['porcentaje'] = $st[0] > 0 ? round(100 * $st[1] / $st[0], 1) : 0;
    }
    unset($a);
    return self::ok(['items' => $alumnos]);
  }

  /** GET /asistencia/alumnos/:id/materias — materias en las que está inscrito el alumno (CASS). */
  public static function alumno_materias_list(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $alumno_id = (int) $req['id'];
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return self::ok(['items' => [], 'materia_ids' => []]);
    }
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT am.materia_id, m.nombre AS materia_nombre FROM $t_am am
       LEFT JOIN $t_mat m ON m.id=am.materia_id
       WHERE am.alumno_id=%d ORDER BY m.nombre",
      $alumno_id
    ), ARRAY_A);
    $items = $rows ?: [];
    $materia_ids = array_values(array_unique(array_column($items, 'materia_id')));
    return self::ok(['items' => $items, 'materia_ids' => $materia_ids]);
  }

  /** POST /asistencia/alumnos/:id/materias — define materias en las que está inscrito (CASS). Body: { materia_ids: [1, 2] } */
  public static function alumno_materias_set(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $alumno_id = (int) $req['id'];
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return self::err('Tabla de inscripción por materia no disponible.', 503);
    }
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_al WHERE id=%d AND activo=1", $alumno_id))) {
      return self::err('Alumno no encontrado.', 404);
    }
    $p = $req->get_json_params() ?: [];
    $materia_ids = isset($p['materia_ids']) && is_array($p['materia_ids']) ? array_map('intval', $p['materia_ids']) : [];
    $materia_ids = array_values(array_unique(array_filter($materia_ids, function ($id) { return $id > 0; })));
    $wpdb->delete($t_am, ['alumno_id' => $alumno_id], ['%d']);
    foreach ($materia_ids as $mid) {
      $wpdb->insert($t_am, ['alumno_id' => $alumno_id, 'materia_id' => $mid], ['%d', '%d']);
    }
    return self::ok(['updated' => true, 'materia_ids' => $materia_ids]);
  }

  /** GET /asistencia/cursos/:id/materias — materias asignadas a un curso. */
  public static function curso_materias_list(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $curso_id = (int) $req['id'];
    $t_cm = $wpdb->prefix . 'conducta_curso_materias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_cm) . "'") !== $t_cm) {
      return self::ok(['items' => [], 'materia_ids' => []]);
    }
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT cm.materia_id, m.nombre AS materia_nombre FROM $t_cm cm
       LEFT JOIN $t_mat m ON m.id=cm.materia_id
       WHERE cm.curso_id=%d ORDER BY m.nombre ASC",
      $curso_id
    ), ARRAY_A);
    $items = array_filter($rows ?: [], function ($r) { return !empty($r['materia_id']); });
    $materia_ids = array_values(array_unique(array_column($items, 'materia_id')));
    return self::ok(['items' => $items, 'materia_ids' => $materia_ids]);
  }

  /** POST /asistencia/cursos/:id/materias/aplicar — inscribe a todos los alumnos del curso en las materias configuradas. */
  public static function curso_materias_aplicar(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $curso_id = (int) $req['id'];
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $alumno_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT id FROM $t_al WHERE curso_id=%d AND activo=1",
      $curso_id
    ));
    $alumno_ids = array_map('intval', $alumno_ids ?: []);
    $inscritos = 0;
    foreach ($alumno_ids as $aid) {
      $out = self::sync_alumno_materias((int) $aid, $curso_id, null, true);
      $inscritos += (int) ($out['inserted'] ?? 0);
    }
    return self::ok(['inscritos' => $inscritos, 'alumnos' => count($alumno_ids)]);
  }

  /** POST /asistencia/cursos/:id/materias — define materias asignadas a un curso. Body: { materia_ids: [1,2,3] } */
  public static function curso_materias_set(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $curso_id = (int) $req['id'];
    $t_cm = $wpdb->prefix . 'conducta_curso_materias';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_cm) . "'") !== $t_cm) {
      return self::err('Tabla de materias por curso no disponible.', 503);
    }
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_cur WHERE id=%d", $curso_id))) {
      return self::err('Curso no encontrado.', 404);
    }
    $p = $req->get_json_params() ?: [];
    $materia_ids = isset($p['materia_ids']) && is_array($p['materia_ids']) ? array_map('intval', $p['materia_ids']) : [];
    $materia_ids = array_values(array_unique(array_filter($materia_ids, function ($id) { return $id > 0; })));
    $wpdb->delete($t_cm, ['curso_id' => $curso_id], ['%d']);
    foreach ($materia_ids as $mid) {
      $wpdb->insert($t_cm, ['curso_id' => $curso_id, 'materia_id' => $mid], ['%d', '%d']);
    }
    return self::ok(['updated' => true, 'materia_ids' => $materia_ids]);
  }

  /** Obtiene IDs de materias asignadas a un curso (para auto-inscripción). */
  public static function get_curso_materia_ids($curso_id) {
    if (!$curso_id || $curso_id < 1) return [];
    global $wpdb;
    $t_cm = $wpdb->prefix . 'conducta_curso_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_cm) . "'") !== $t_cm) return [];
    $ids = $wpdb->get_col($wpdb->prepare("SELECT materia_id FROM $t_cm WHERE curso_id=%d", $curso_id));
    return array_map('intval', $ids ?: []);
  }

  private static function normalize_text_key($text) {
    $txt = strtolower((string) $text);
    if (function_exists('remove_accents')) {
      $txt = remove_accents($txt);
    }
    $txt = preg_replace('/\s+/', ' ', trim($txt));
    return $txt;
  }

  private static function normalize_text_tokens($text): array {
    $txt = strtolower((string) $text);
    if (function_exists('remove_accents')) {
      $txt = remove_accents($txt);
    }
    $parts = preg_split('/[^a-z0-9]+/', $txt, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_values($parts) : [];
  }

  private static function is_nu_group_name($grupo_nombre): bool {
    $tokens = self::normalize_text_tokens($grupo_nombre);
    if (empty($tokens)) return false;
    // Compatibilidad: algunos grupos se nombran "Nu" y otros "N".
    return in_array('nu', $tokens, true) || in_array('n', $tokens, true);
  }

  private static function materia_name_contains_any($materia_nombre, array $needles): bool {
    $name = self::normalize_text_key($materia_nombre);
    if ($name === '') return false;
    foreach ($needles as $needle) {
      $n = self::normalize_text_key((string) $needle);
      if ($n !== '' && strpos($name, $n) !== false) {
        return true;
      }
    }
    return false;
  }

  private static function apply_cea_group_career_overrides(array $materias_rows, $grupo_nombre, $facultad_nombre, $carrera_nombre): array {
    $grupo_key = self::normalize_text_key($grupo_nombre);
    $fac_key = self::normalize_text_key($facultad_nombre);
    $car_key = self::normalize_text_key($carrera_nombre);
    $rows = $materias_rows;

    // Grupo Nu (Ing. Informática): incluye Programación y excluye Física.
    if (preg_match('/(^|[^a-z])nu($|[^a-z])/', $grupo_key)) {
      $rows = array_values(array_filter($rows, function ($r) {
        $nombre = (string) ($r['nombre'] ?? '');
        if (self::materia_name_contains_any($nombre, ['fisica', 'física'])) {
          return false;
        }
        return true;
      }));
      // "Programación" se mantiene explícitamente incluida si existe en el curso.
    }

    // Grupo Xi: Química solo para carreras/facultades CYT.
    if (preg_match('/(^|[^a-z])xi($|[^a-z])/', $grupo_key)) {
      $is_cyt = (
        strpos($car_key, 'cyt') !== false ||
        strpos($car_key, 'ciencia') !== false ||
        strpos($car_key, 'tecnolog') !== false ||
        strpos($fac_key, 'cyt') !== false ||
        strpos($fac_key, 'ciencia') !== false ||
        strpos($fac_key, 'tecnolog') !== false
      );
      if (!$is_cyt) {
        $rows = array_values(array_filter($rows, function ($r) {
          $nombre = (string) ($r['nombre'] ?? '');
          return !self::materia_name_contains_any($nombre, ['quimica', 'química']);
        }));
      }
    }

    return $rows;
  }

  private static function resolve_curso_id_from_aula($aula_id): int {
    $aula_id = is_numeric($aula_id) ? (int) $aula_id : 0;
    if ($aula_id <= 0) return 0;
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $curso_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT curso_id FROM $t_aul WHERE id=%d LIMIT 1",
      $aula_id
    ));
    return $curso_id > 0 ? $curso_id : 0;
  }

  private static function detect_cea_carrera_category($facultad_nombre, $carrera_nombre): ?string {
    $fac = self::normalize_text_key($facultad_nombre);
    $car = self::normalize_text_key($carrera_nombre);
    if ($car === '' && $fac === '') return null;
    if (strpos($car, 'medic') !== false) return 'medicina';
    if (strpos($car, 'informatic') !== false) return 'ingenieria_informatica';
    if (
      strpos($car, 'civil') !== false ||
      strpos($car, 'industrial') !== false ||
      strpos($car, 'electromecan') !== false
    ) return 'ingenieria_general';
    if (
      strpos($car, 'econom') !== false || strpos($car, 'administr') !== false ||
      strpos($fac, 'econom') !== false || strpos($fac, 'administr') !== false
    ) return 'economicas';
    if (strpos($car, 'human') !== false || strpos($fac, 'human') !== false) return 'humanidades';
    if (strpos($car, 'derecho') !== false || strpos($fac, 'derecho') !== false) return 'derecho';
    if (
      strpos($car, 'cyt') !== false || strpos($car, 'c y t') !== false || strpos($car, 'c&t') !== false ||
      strpos($car, 'ciencia') !== false || strpos($car, 'tecnolog') !== false ||
      strpos($fac, 'cyt') !== false || strpos($fac, 'c y t') !== false || strpos($fac, 'c&t') !== false ||
      strpos($fac, 'ciencia') !== false || strpos($fac, 'tecnolog') !== false
    ) return 'cyt';
    if (strpos($car, 'ingenier') !== false || strpos($fac, 'ingenier') !== false) return 'ingenieria_general';
    return null;
  }

  private static function cea_category_subjects(string $category): array {
    $map = [
      'medicina' => ['quimica inorganica', 'quimica organica', 'fisica', 'matematicas', 'guarani', 'estudios paraguayos', 'biologia', 'anatomia', 'castellano'],
      'ingenieria_general' => ['fisica', 'trigonometria', 'geometria analitica', 'aritmetica y algebra'],
      'ingenieria_informatica' => ['programacion', 'trigonometria', 'geometria analitica', 'aritmetica y algebra'],
      'cyt' => ['fisica', 'historia y geografia', 'algebra', 'aritmetica', 'castellano', 'estudios paraguayos', 'geometria y trigonometria', 'guarani', 'quimica inorganica', 'introduccion al calculo'],
      'economicas' => ['algebra', 'aritmetica', 'castellano', 'estudios paraguayos', 'introduccion a la administracion', 'introduccion a la contabilidad', 'introduccion a la economia', 'guarani'],
      'humanidades' => ['algebra', 'aritmetica', 'castellano', 'estudios paraguayos', 'guarani', 'historia y geografia', 'metodologia'],
      'derecho' => ['algebra', 'aritmetica', 'castellano', 'estudios paraguayos', 'guarani', 'historia y geografia'],
    ];
    return $map[$category] ?? [];
  }

  private static function materia_patterns_for_subject(string $subject_key): array {
    $s = self::normalize_text_key($subject_key);
    $map = [
      'quimica inorganica' => ['quimica inorganica'],
      'quimica organica' => ['quimica organica'],
      'fisica' => ['fisica'],
      'matematicas' => ['matematica', 'matematicas'],
      'guarani' => ['guarani'],
      'estudios paraguayos' => ['estudios paraguayos'],
      'biologia' => ['biologia'],
      'anatomia' => ['anatomia'],
      'castellano' => ['castellano'],
      'trigonometria' => ['trigonometria'],
      'geometria analitica' => ['geometria analitica'],
      'aritmetica y algebra' => ['aritmetica y algebra', 'aritmetica algebra'],
      'programacion' => ['programacion'],
      'historia y geografia' => ['historia y geografia'],
      'algebra' => ['algebra'],
      'aritmetica' => ['aritmetica'],
      // Acepta variantes comunes para evitar que CYT quede sin esta materia por diferencias de nomenclatura.
      'geometria y trigonometria' => [
        'geometria y trigonometria',
        'trigonometria y geometria',
        'geometria/trigonometria',
        'trigonometria/geometria',
        'geometria trigonometria',
      ],
      'introduccion al calculo' => ['introduccion al calculo'],
      'introduccion a la administracion' => ['introduccion a la administracion'],
      'introduccion a la contabilidad' => ['introduccion a la contabilidad'],
      'introduccion a la economia' => ['introduccion a la economia'],
      'metodologia' => ['metodologia'],
    ];
    return $map[$s] ?? [$s];
  }

  private static function materia_matches_subject($materia_nombre, string $subject_key): bool {
    $name = self::normalize_text_key($materia_nombre);
    if ($name === '') return false;
    $sk = self::normalize_text_key($subject_key);
    // Evitar que "Aritmética y Álgebra" satisfaga la búsqueda de Álgebra o Aritmética sueltas
    // (CEA y otras listas que piden ambas materias por separado).
    if ($sk === 'algebra' || $sk === 'aritmetica') {
      $has_ari = strpos($name, 'aritmetica') !== false;
      $has_alg = strpos($name, 'algebra') !== false;
      if ($has_ari && $has_alg) {
        return false;
      }
    }
    // Fallback robusto para la materia combinada de CYT, aunque venga en otro orden o separador.
    if ($sk === 'geometria y trigonometria') {
      $has_geo = strpos($name, 'geometr') !== false;
      $has_tri = strpos($name, 'trigonom') !== false;
      if ($has_geo && $has_tri) return true;
    }
    foreach (self::materia_patterns_for_subject($subject_key) as $pattern) {
      $p = self::normalize_text_key($pattern);
      if ($p !== '' && (strpos($name, $p) !== false || strpos($p, $name) !== false)) {
        return true;
      }
    }
    return false;
  }

  private static function materia_ids_for_cea_carrera($facultad_nombre, $carrera_nombre): array {
    $category = self::detect_cea_carrera_category($facultad_nombre, $carrera_nombre);
    if (!$category) return [];
    $subjects = self::cea_category_subjects($category);
    if (empty($subjects)) return [];
    global $wpdb;
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $rows = $wpdb->get_results("SELECT id, nombre FROM $t_mat WHERE activo=1", ARRAY_A);
    $ids = [];
    foreach ($subjects as $subject) {
      foreach (($rows ?: []) as $r) {
        $mid = (int) ($r['id'] ?? 0);
        if ($mid <= 0) continue;
        if (self::materia_matches_subject((string) ($r['nombre'] ?? ''), (string) $subject)) {
          $ids[] = $mid;
          break;
        }
      }
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
  }

  private static function materia_ids_for_subject_keys(array $subject_keys): array {
    $subjects = array_values(array_filter(array_map('strval', $subject_keys), static function ($s) {
      return trim((string) $s) !== '';
    }));
    if (empty($subjects)) return [];
    global $wpdb;
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $rows = $wpdb->get_results("SELECT id, nombre FROM $t_mat WHERE activo=1", ARRAY_A);
    $ids = [];
    foreach ($subjects as $subject) {
      foreach (($rows ?: []) as $r) {
        $mid = (int) ($r['id'] ?? 0);
        if ($mid <= 0) continue;
        if (self::materia_matches_subject((string) ($r['nombre'] ?? ''), (string) $subject)) {
          $ids[] = $mid;
          break;
        }
      }
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
      return $id > 0;
    })));
  }

  /**
   * Coincidencia estricta para el paquete Nu/N: evita que "algebra" enganche
   * "aritmetica y algebra" antes que la materia suelta "Álgebra".
   */
  private static function nu_bundle_row_matches_target(string $name_key, string $target_key, bool $is_exam_row): bool {
    if ($name_key === '' || $target_key === '') return false;
    $tokens = self::normalize_text_tokens($name_key);
    if (empty($tokens)) return false;

    if ($target_key === 'algebra') {
      if ($is_exam_row) {
        return in_array('algebra', $tokens, true)
          || in_array('algebras', $tokens, true);
      }
      if (in_array('aritmetica', $tokens, true) || in_array('aritmeticas', $tokens, true)) {
        return false;
      }
      return in_array('algebra', $tokens, true) || in_array('algebras', $tokens, true);
    }

    if ($target_key === 'aritmetica') {
      if ($is_exam_row) {
        return in_array('aritmetica', $tokens, true) || in_array('aritmeticas', $tokens, true);
      }
      if (in_array('algebra', $tokens, true) || in_array('algebras', $tokens, true)) {
        return false;
      }
      return in_array('aritmetica', $tokens, true) || in_array('aritmeticas', $tokens, true);
    }

    if ($target_key === 'geometria analitica') {
      $has_geo = in_array('geometria', $tokens, true) || in_array('geometrias', $tokens, true);
      $has_anal = in_array('analitica', $tokens, true) || in_array('analiticas', $tokens, true)
        || in_array('analitico', $tokens, true) || in_array('analiticos', $tokens, true);
      if (!$has_geo || !$has_anal) return false;
      if (in_array('trigonometria', $tokens, true) || in_array('trigonometrias', $tokens, true)) {
        return false;
      }
      return true;
    }

    if ($target_key === 'trigonometria') {
      if (in_array('geometria', $tokens, true) && in_array('trigonometria', $tokens, true)) {
        return false;
      }
      return in_array('trigonometria', $tokens, true) || in_array('trigonometrias', $tokens, true);
    }

    if ($target_key === 'programacion') {
      return in_array('programacion', $tokens, true) || in_array('programaciones', $tokens, true)
        || in_array('informatica', $tokens, true) || in_array('informaticas', $tokens, true);
    }

    return false;
  }

  private static function materia_ids_for_nu_bundle(): array {
    global $wpdb;
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $rows = $wpdb->get_results("SELECT id, nombre FROM $t_mat WHERE activo=1", ARRAY_A);
    if (empty($rows)) return [];

    $targets = ['algebra', 'aritmetica', 'geometria analitica', 'trigonometria', 'programacion'];
    $picked = [];

    foreach ($targets as $target) {
      $target_key = self::normalize_text_key($target);

      // 1) Materia base (no examen), priorizando coincidencia exacta del nombre normalizado.
      $best_base_exact = 0;
      $best_base_token = 0;
      $best_base_fuzzy = 0;
      foreach ($rows as $r) {
        $mid = (int) ($r['id'] ?? 0);
        $name = (string) ($r['nombre'] ?? '');
        if ($mid <= 0 || $name === '') continue;
        $name_key = self::normalize_text_key($name);
        $is_exam = (strpos($name_key, 'examen') !== false);
        if ($is_exam) continue;
        if ($name_key === $target_key) {
          $best_base_exact = $mid;
          break;
        }
        if ($best_base_token <= 0 && self::nu_bundle_row_matches_target($name_key, $target_key, false)) {
          $best_base_token = $mid;
        }
        if ($best_base_fuzzy <= 0 && self::materia_matches_subject($name, $target)) {
          $best_base_fuzzy = $mid;
        }
      }
      if ($best_base_exact > 0) {
        $picked[] = $best_base_exact;
      } elseif ($best_base_token > 0) {
        $picked[] = $best_base_token;
      } elseif ($best_base_fuzzy > 0) {
        $picked[] = $best_base_fuzzy;
      }

      // 2) Examen de la materia (si existe): token "examen" + materia; evita enganches por subcadena.
      $best_exam_exact = 0;
      $best_exam_token = 0;
      $best_exam_fuzzy = 0;
      foreach ($rows as $r) {
        $mid = (int) ($r['id'] ?? 0);
        $name = (string) ($r['nombre'] ?? '');
        if ($mid <= 0 || $name === '') continue;
        $name_key = self::normalize_text_key($name);
        $is_exam = (strpos($name_key, 'examen') !== false);
        if (!$is_exam) continue;
        if (strpos($name_key, 'examen') === 0 && strpos($name_key, $target_key) !== false) {
          $best_exam_exact = $mid;
          break;
        }
        if ($best_exam_token <= 0 && self::nu_bundle_row_matches_target($name_key, $target_key, true)) {
          $best_exam_token = $mid;
        }
        if ($best_exam_fuzzy <= 0 && self::materia_matches_subject($name, $target)) {
          $best_exam_fuzzy = $mid;
        }
      }
      if ($best_exam_exact > 0) {
        $picked[] = $best_exam_exact;
      } elseif ($best_exam_token > 0) {
        $picked[] = $best_exam_token;
      } elseif ($best_exam_fuzzy > 0) {
        $picked[] = $best_exam_fuzzy;
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $picked), static function ($id) {
      return $id > 0;
    })));
  }

  private static function resolve_alumno_auto_context(int $alumno_id): array {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_fac = $wpdb->prefix . 'conducta_facultades';
    $t_car = $wpdb->prefix . 'conducta_carreras';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT al.curso_id, al.aula_id, al.facultad_id, al.carrera_id, fa.nombre AS facultad_nombre, ca.nombre AS carrera_nombre
       FROM $t_al al
       LEFT JOIN $t_fac fa ON fa.id=al.facultad_id
       LEFT JOIN $t_car ca ON ca.id=al.carrera_id
       WHERE al.id=%d LIMIT 1",
      $alumno_id
    ), ARRAY_A);
    return [
      'curso_id' => (int) ($row['curso_id'] ?? 0),
      'aula_id' => (int) ($row['aula_id'] ?? 0),
      'facultad_nombre' => (string) ($row['facultad_nombre'] ?? ''),
      'carrera_nombre' => (string) ($row['carrera_nombre'] ?? ''),
    ];
  }

  private static function resolve_auto_materia_ids(int $alumno_id, int $curso_id, int $aula_id, string $facultad_nombre, string $carrera_nombre): array {
    global $wpdb;
    $curso_nombre = '';
    if ($curso_id > 0) {
      $t_cur = $wpdb->prefix . 'conducta_cursos';
      $curso_nombre = (string) $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_cur WHERE id=%d LIMIT 1", $curso_id));
    }
    $curso_key = self::normalize_text_key($curso_nombre);
    if ($curso_key === '') {
      return [];
    }
    // Regla explícita solicitada para grupo Nu.
    if ($aula_id > 0) {
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $aula_nombre = (string) $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d LIMIT 1", $aula_id));
      if (self::is_nu_group_name($aula_nombre)) {
        $nu_ids = self::materia_ids_for_nu_bundle();
        if (!empty($nu_ids)) return $nu_ids;
      }
    }
    // Prioridad: para CEA gana carrera (más específico).
    if (strpos($curso_key, 'cea') !== false) {
      $by_carrera = self::materia_ids_for_cea_carrera($facultad_nombre, $carrera_nombre);
      if (!empty($by_carrera)) return $by_carrera;
      return self::get_curso_materia_ids($curso_id);
    }
    // CASS y otros cursos usan materias configuradas por curso (sin reglas CEA).
    return self::get_curso_materia_ids($curso_id);
  }

  public static function sync_alumno_materias($alumno_id, $curso_id = null, $aula_id = null, $replace_existing = true): array {
    self::ensure_tables();
    $alumno_id = is_numeric($alumno_id) ? (int) $alumno_id : 0;
    $curso_id = is_numeric($curso_id) ? (int) $curso_id : 0;
    $aula_id = is_numeric($aula_id) ? (int) $aula_id : 0;
    if ($alumno_id <= 0) return ['inserted' => 0, 'materia_ids' => []];
    if ($curso_id <= 0 && $aula_id > 0) {
      $curso_id = self::resolve_curso_id_from_aula($aula_id);
    }
    $ctx = self::resolve_alumno_auto_context($alumno_id);
    if ($curso_id <= 0) $curso_id = (int) ($ctx['curso_id'] ?? 0);
    if ($aula_id <= 0) $aula_id = (int) ($ctx['aula_id'] ?? 0);
    $facultad_nombre = (string) ($ctx['facultad_nombre'] ?? '');
    $carrera_nombre = (string) ($ctx['carrera_nombre'] ?? '');
    $materia_ids = self::resolve_auto_materia_ids($alumno_id, $curso_id, $aula_id, $facultad_nombre, $carrera_nombre);

    global $wpdb;
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return ['inserted' => 0, 'materia_ids' => []];
    }
    if ($replace_existing) {
      $wpdb->delete($t_am, ['alumno_id' => $alumno_id], ['%d']);
    }
    if (empty($materia_ids)) {
      return ['inserted' => 0, 'materia_ids' => []];
    }
    $inserted = 0;
    foreach ($materia_ids as $mid) {
      $mid = (int) $mid;
      if ($mid <= 0) continue;
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $t_am WHERE alumno_id=%d AND materia_id=%d",
        $alumno_id, $mid
      ));
      if (!$exists) {
        $ok = $wpdb->insert($t_am, ['alumno_id' => $alumno_id, 'materia_id' => $mid], ['%d', '%d']);
        if ($ok !== false) $inserted++;
      }
    }
    // Respaldo: al sincronizar materias para Nu, también asegurar registro en exámenes objetivo.
    if ($aula_id > 0 && class_exists('NC_Rest_Examenes')) {
      NC_Rest_Examenes::auto_registrar_alumno_examenes_nu($alumno_id, $aula_id);
    }
    return ['inserted' => $inserted, 'materia_ids' => array_values($materia_ids)];
  }

  /** Inscribe un alumno en materias del curso. */
  public static function auto_inscribir_alumno_curso_materias($alumno_id, $curso_id, $aula_id = null, $replace_existing = false) {
    self::sync_alumno_materias($alumno_id, $curso_id, $aula_id, (bool) $replace_existing);
  }

  /** GET /asistencia/materias/:id/alumnos — IDs de alumnos inscritos en una materia (CASS). */
  public static function materia_alumnos_list(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $materia_id = (int) $req['id'];
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return self::ok(['alumno_ids' => []]);
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT alumno_id FROM $t_am WHERE materia_id=%d", $materia_id));
    $ids = array_map('intval', $ids ?: []);
    return self::ok(['alumno_ids' => $ids]);
  }

  /** POST /asistencia/materias/:id/inscribir — Inscribir varios alumnos en una materia. Body: { alumno_ids: [1,2,3] } */
  public static function materia_inscribir_bulk(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $materia_id = (int) $req['id'];
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return self::err('Tabla de inscripción por materia no disponible.', 503);
    }
    $p = $req->get_json_params() ?: [];
    $alumno_ids = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];
    $alumno_ids = array_values(array_unique(array_filter($alumno_ids, function ($id) { return $id > 0; })));
    if (empty($alumno_ids)) {
      return self::ok(['inscritos' => 0]);
    }
    $placeholders = implode(',', array_fill(0, count($alumno_ids), '%d'));
    $valid_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT id FROM $t_al WHERE id IN ($placeholders) AND activo=1",
      $alumno_ids
    ));
    if (empty($valid_ids)) {
      return self::ok(['inscritos' => 0]);
    }
    $values = [];
    $params = [];
    foreach ($valid_ids as $aid) {
      $values[] = '(%d,%d)';
      $params[] = $aid;
      $params[] = $materia_id;
    }
    $sql = "INSERT IGNORE INTO $t_am (alumno_id, materia_id) VALUES " . implode(',', $values);
    $affected = $wpdb->query($wpdb->prepare($sql, $params));
    return self::ok(['inscritos' => (int) $affected]);
  }

  /** POST /asistencia/materias/:id/quitar — Quitar varios alumnos de una materia. Body: { alumno_ids: [1,2,3] } */
  public static function materia_quitar_bulk(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $materia_id = (int) $req['id'];
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") !== $t_am) {
      return self::ok(['quitados' => 0]);
    }
    $p = $req->get_json_params() ?: [];
    $alumno_ids = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? array_map('intval', $p['alumno_ids']) : [];
    $alumno_ids = array_values(array_unique(array_filter($alumno_ids, function ($id) { return $id > 0; })));
    if (empty($alumno_ids)) {
      return self::ok(['quitados' => 0]);
    }
    $placeholders = implode(',', array_fill(0, count($alumno_ids), '%d'));
    $params = array_merge([$materia_id], $alumno_ids);
    $deleted = $wpdb->query($wpdb->prepare(
      "DELETE FROM $t_am WHERE materia_id=%d AND alumno_id IN ($placeholders)",
      $params
    ));
    return self::ok(['quitados' => (int) $deleted]);
  }

  /** GET /asistencia/materias/:id/grupos — Grupos (aulas) que tienen alumnos inscriptos a esa materia. */
  public static function materia_grupos_list(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $materia_id = (int) $req['id'];

    $t_am   = $wpdb->prefix . 'conducta_alumno_materias';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $t_al   = $wpdb->prefix . 'conducta_alumnos';
    $t_aul  = $wpdb->prefix . 'conducta_aulas';

    if ($materia_id <= 0) return self::ok(['items' => []]);

    $has_am   = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_am) . "'") === $t_am);
    $has_rel  = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
    if (!$has_am || !$has_rel) return self::ok(['items' => []]);

    $sql = "SELECT DISTINCT au.id, au.nombre
            FROM $t_am am
            INNER JOIN $t_al_a ag ON ag.alumno_id = am.alumno_id AND ag.activo = 1
            INNER JOIN $t_al a ON a.id = am.alumno_id AND a.activo = 1
            INNER JOIN $t_aul au ON au.id = ag.aula_id AND au.activo = 1
            WHERE am.materia_id = %d
            ORDER BY au.nombre ASC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $materia_id), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  // ---------------- Historial por alumno ----------------

  public static function historial_alumno(WP_REST_Request $req) {
    global $wpdb;
    $alumno_id = (int) $req['id'];
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));

    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $users = $wpdb->prefix . 'users';

    $where = "i.alumno_id=%d AND s.activo=1";
    $params = [$alumno_id];
    if ($fecha_desde !== '') { $where .= ' AND s.fecha>=%s'; $params[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where .= ' AND s.fecha<=%s'; $params[] = $fecha_hasta; }

    $sql = "SELECT i.id AS item_id, COALESCE(mod_asist.asistio, i.asistio) AS asistio,
                   COALESCE(NULLIF(TRIM(mod_asist.observacion), ''), i.observacion) AS observacion,
                   i.modified_at AS item_modified_at,
                   s.id AS asistencia_id, s.fecha, s.materia_id, s.simulacro_id, s.subgrupo, s.creado_por, s.docente_encargado_id,
                   s.created_at AS sesion_created_at,
                   m.nombre AS materia_nombre,
                   u.display_name AS docente_nombre,
                   u_creador.display_name AS creado_por_nombre
            FROM $t_items i
            INNER JOIN $t_asis s ON s.id=i.asistencia_id
            LEFT JOIN $t_mod mod_asist ON mod_asist.asistencia_id = i.asistencia_id AND mod_asist.asistencia_item_id = i.id
            LEFT JOIN $t_mat m ON m.id=s.materia_id
            LEFT JOIN $users u ON u.ID=s.docente_encargado_id
            LEFT JOIN $users u_creador ON u_creador.ID=s.creado_por
            WHERE $where
            ORDER BY s.fecha DESC, s.created_at DESC";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    foreach ($rows as &$row) {
      self::apply_simulacro_sesion_display($row);
    }
    unset($row);

    $where_resumen = "i.alumno_id=%d";
    $params_resumen = [$alumno_id];
    if ($fecha_desde !== '') { $where_resumen .= ' AND s.fecha>=%s'; $params_resumen[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where_resumen .= ' AND s.fecha<=%s'; $params_resumen[] = $fecha_hasta; }
    $tot = $wpdb->get_row($wpdb->prepare(
      "SELECT COUNT(*) AS total, COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS asistidas
       FROM $t_items i
       LEFT JOIN $t_mod m ON m.asistencia_id = i.asistencia_id AND m.asistencia_item_id = i.id
       INNER JOIN $t_asis s ON s.id=i.asistencia_id AND s.activo=1
       WHERE $where_resumen",
      $params_resumen
    ), ARRAY_A);
    $total_clases = (int)($tot['total'] ?? 0);
    $asistidas = (int)($tot['asistidas'] ?? 0);
    $porcentaje = $total_clases > 0 ? round(100 * $asistidas / $total_clases, 1) : 0;

    return self::ok([
      'items' => $rows ?: [],
      'resumen' => [
        'total_clases' => $total_clases,
        'asistidas' => $asistidas,
        'promedio_texto' => $asistidas . '/' . $total_clases,
        'porcentaje' => $porcentaje,
      ],
    ]);
  }

  /** Datos de historial de asistencia de un alumno (misma lógica que historial_alumno) para exportación */
  private static function get_historial_rows_for_export($alumno_id, $fecha_desde, $fecha_hasta) {
    global $wpdb;
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod  = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $users = $wpdb->prefix . 'users';
    $where = "i.alumno_id=%d AND s.activo=1";
    $params = [$alumno_id];
    if ($fecha_desde !== '') { $where .= ' AND s.fecha>=%s'; $params[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where .= ' AND s.fecha<=%s'; $params[] = $fecha_hasta; }
    $sql = "SELECT i.id AS item_id, COALESCE(mod_asist.asistio, i.asistio) AS asistio,
                   COALESCE(NULLIF(TRIM(mod_asist.observacion), ''), i.observacion) AS observacion,
                   i.modified_at AS item_modified_at,
                   s.id AS asistencia_id, s.materia_id, s.fecha, m.nombre AS materia_nombre,
                   u.display_name AS docente_nombre,
                   u_creador.display_name AS creado_por_nombre
            FROM $t_items i
            INNER JOIN $t_asis s ON s.id=i.asistencia_id
            LEFT JOIN $t_mod mod_asist ON mod_asist.asistencia_id = i.asistencia_id AND mod_asist.asistencia_item_id = i.id
            LEFT JOIN $t_mat m ON m.id=s.materia_id
            LEFT JOIN $users u ON u.ID=s.docente_encargado_id
            LEFT JOIN $users u_creador ON u_creador.ID=s.creado_por
            WHERE $where
            ORDER BY s.fecha DESC, s.created_at DESC";
    return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
  }

  /** Día de la semana en español para YYYY-MM-DD (zona horaria del servidor). */
  private static function dia_semana_es_largo(string $ymd): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt || $dt->format('Y-m-d') !== $ymd) return '';
    $n = (int) $dt->format('N');
    $map = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    return $map[$n] ?? '';
  }

  /**
   * GET /asistencia/lista-alumnos/export/xlsx
   * Mismos filtros que lista-alumnos + fecha_desde / fecha_hasta (opcionales).
   * Columnas por sesión: "Materia - DíaSemana YYYY-MM-DD"; celdas Sí/No con fondo verde/rojo.
   */
  public static function export_lista_alumnos_xlsx(WP_REST_Request $req) {
    self::ensure_tables();
    $autoload = defined('NC_PATH') ? NC_PATH . 'vendor/autoload.php' : '';
    if ($autoload !== '' && is_file($autoload)) {
      require_once $autoload;
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
      return self::err('La exportación a Excel requiere PhpSpreadsheet. Ejecute composer install en el plugin.', 503);
    }

    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    if ($fecha_desde !== '' && $fecha_hasta !== '' && $fecha_desde > $fecha_hasta) {
      return self::err('El rango de fechas es inválido.', 400);
    }

    $inner = new WP_REST_Request('GET', '');
    foreach (['aula_id', 'curso_id', 'materia_id', 'search', 'subgrupo'] as $p) {
      $v = $req->get_param($p);
      if ($v !== null && $v !== '') {
        $inner->set_param($p, $v);
      }
    }
    $lista_resp = self::lista_alumnos($inner);
    if ($lista_resp instanceof WP_Error) {
      return $lista_resp;
    }
    $body = $lista_resp->get_data();
    $alumnos = (is_array($body) && isset($body['items']) && is_array($body['items'])) ? $body['items'] : [];
    if (!$alumnos) {
      return self::err('No hay alumnos para exportar con los filtros indicados.', 400);
    }

    $filt_materia_id = self::int_or_null($req->get_param('materia_id'));

    $by_key = [];
    $rows_by_alumno = [];

    foreach ($alumnos as $al) {
      $aid = (int) ($al['id'] ?? 0);
      if ($aid <= 0) {
        continue;
      }
      $hist = self::get_historial_rows_for_export($aid, $fecha_desde, $fecha_hasta);
      $rows_by_alumno[$aid] = [];
      foreach ($hist as $it) {
        if ($filt_materia_id && (int) ($it['materia_id'] ?? 0) !== $filt_materia_id) {
          continue;
        }
        $sesion_id = (int) ($it['asistencia_id'] ?? 0);
        $fecha = trim((string) ($it['fecha'] ?? ''));
        $materia = trim((string) ($it['materia_nombre'] ?? 'Materia'));
        $key = $sesion_id > 0 ? ('sid_' . $sesion_id) : ($materia . '__' . $fecha);
        $dia = self::dia_semana_es_largo($fecha);
        $label = $dia !== '' ? ($materia . ' - ' . $dia . ' ' . $fecha) : ($materia . ' - ' . $fecha);
        if (!isset($by_key[$key])) {
          $by_key[$key] = ['key' => $key, 'fecha' => $fecha, 'materia' => $materia, 'label' => $label];
        }
        $asistio = (isset($it['asistio']) && ((int) $it['asistio'] === 1 || $it['asistio'] === true || $it['asistio'] === '1'));
        $rows_by_alumno[$aid][$key] = $asistio ? 'Sí' : 'No';
      }
    }

    $cols = array_values($by_key);
    usort($cols, static function ($a, $b) {
      $fa = (string) ($a['fecha'] ?? '');
      $fb = (string) ($b['fecha'] ?? '');
      if ($fa !== $fb) {
        return strcmp($fa, $fb);
      }
      return strcmp((string) ($a['materia'] ?? ''), (string) ($b['materia'] ?? ''));
    });
    if (!$cols) {
      return self::err('No hay asistencias para exportar con los filtros actuales.', 400);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Asistencias');

    $headerStyle = [
      'font' => ['bold' => true],
      'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'ECEFF1'],
      ],
      'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
    ];
    $fill_si = [
      'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'C8E6C9'],
      ],
    ];
    $fill_no = [
      'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFCDD2'],
      ],
    ];

    $sheet->setCellValue('A1', 'CI');
    $sheet->setCellValue('B1', 'Estudiante');
    $sheet->setCellValue('C1', 'Grupo');
    $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

    $col_index = 4;
    foreach ($cols as $cdef) {
      $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_index);
      $sheet->setCellValue($letter . '1', $cdef['label']);
      $sheet->getStyle($letter . '1')->applyFromArray($headerStyle);
      $col_index++;
    }

    $row = 2;
    foreach ($alumnos as $al) {
      $aid = (int) ($al['id'] ?? 0);
      $ci = trim((string) ($al['ci'] ?? ''));
      $nom = trim((string) ($al['nombres'] ?? ''));
      $ape = trim((string) ($al['apellidos'] ?? ''));
      $nombre = trim($ape . ', ' . $nom);
      $nombre = preg_replace('/^,\s*/', '', $nombre);
      $grupo = trim((string) ($al['aula_nombre'] ?? ''));

      $sheet->setCellValue('A' . $row, $ci);
      $sheet->setCellValue('B' . $row, $nombre);
      $sheet->setCellValue('C' . $row, $grupo);

      $col_index = 4;
      foreach ($cols as $cdef) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_index);
        $key = $cdef['key'];
        $val = isset($rows_by_alumno[$aid][$key]) ? $rows_by_alumno[$aid][$key] : '';
        $sheet->setCellValue($letter . $row, $val);
        if ($val === 'Sí') {
          $sheet->getStyle($letter . $row)->applyFromArray($fill_si);
        } elseif ($val === 'No') {
          $sheet->getStyle($letter . $row)->applyFromArray($fill_no);
        }
        $col_index++;
      }
      $row++;
    }

    foreach (range(1, $col_index - 1) as $i) {
      $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }

    $aula_nombre = 'todos';
    $aula_id_param = self::int_or_null($req->get_param('aula_id'));
    if ($aula_id_param) {
      global $wpdb;
      $t_aul = $wpdb->prefix . 'conducta_aulas';
      $n = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d", $aula_id_param));
      if (!empty($n)) {
        $aula_nombre = (string) $n;
      }
    }
    $rango = ($fecha_desde !== '' || $fecha_hasta !== '') ? ($fecha_desde ?: 'inicio') . '_a_' . ($fecha_hasta ?: 'hoy') : 'sin-rango';
    $filename = 'asistencias-lista-alumnos-' . sanitize_file_name($aula_nombre) . '-' . str_replace(['/', '\\'], '-', $rango) . '-' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }

  public static function export_alumno_historial_csv(WP_REST_Request $req) {
    $alumno_id = (int) $req['id'];
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $al = $wpdb->get_row($wpdb->prepare("SELECT nombres, apellidos, ci FROM $t_al WHERE id=%d", $alumno_id), ARRAY_A);
    $nombre_completo = trim(($al['nombres'] ?? '') . ' ' . ($al['apellidos'] ?? ''));
    $ci = $al['ci'] ?? '';
    $rows = self::get_historial_rows_for_export($alumno_id, $fecha_desde, $fecha_hasta);
    $cabeceras = ['FECHA', 'MATERIA', 'DOCENTE', 'REGISTRADO POR', 'ÚLTIMA MODIFICACIÓN', 'ASISTIÓ', 'OBSERVACIÓN'];
    $out = [];
    foreach ($rows as $r) {
      $ultima_mod = !empty($r['item_modified_at']) ? $r['item_modified_at'] : '-';
      $out[] = [
        $r['fecha'] ?? '',
        $r['materia_nombre'] ?? '',
        $r['docente_nombre'] ?? '-',
        $r['creado_por_nombre'] ?? '-',
        $ultima_mod,
        (isset($r['asistio']) && (int)$r['asistio'] === 1) ? 'Sí' : 'No',
        $r['observacion'] ?? '',
      ];
    }
    $filename = 'asistencia-alumno-' . sanitize_file_name($ci ?: $alumno_id) . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh, $cabeceras);
    foreach ($out as $row) fputcsv($fh, $row);
    fclose($fh);
    exit;
  }

  public static function export_alumno_historial_pdf(WP_REST_Request $req) {
    $alumno_id = (int) $req['id'];
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $al = $wpdb->get_row($wpdb->prepare("SELECT nombres, apellidos, ci FROM $t_al WHERE id=%d", $alumno_id), ARRAY_A);
    $nombre_completo = trim(($al['nombres'] ?? '') . ' ' . ($al['apellidos'] ?? ''));
    $ci = $al['ci'] ?? '';
    $rows = self::get_historial_rows_for_export($alumno_id, $fecha_desde, $fecha_hasta);
    $titulo = 'Reporte de asistencia - ' . $nombre_completo . ($ci ? ' (CI: ' . $ci . ')' : '');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($titulo) . '</title>';
    $html .= '<style>table{border-collapse:collapse;width:100%;}th,td{border:1px solid #333;padding:6px;text-align:left;}th{background:#eee;font-size:10px;}</style></head><body>';
    $html .= '<h1>' . esc_html($titulo) . '</h1><table><tr><th>FECHA</th><th>MATERIA</th><th>DOCENTE</th><th>REGISTRADO POR</th><th>ÚLTIMA MODIFICACIÓN</th><th>ASISTIÓ</th><th>OBSERVACIÓN</th></tr>';
    foreach ($rows as $r) {
      $ultima_mod = !empty($r['item_modified_at']) ? $r['item_modified_at'] : '-';
      $asistio = (isset($r['asistio']) && (int)$r['asistio'] === 1) ? 'Sí' : 'No';
      $html .= '<tr><td>' . esc_html($r['fecha'] ?? '') . '</td><td>' . esc_html($r['materia_nombre'] ?? '') . '</td><td>' . esc_html($r['docente_nombre'] ?? '-') . '</td><td>' . esc_html($r['creado_por_nombre'] ?? '-') . '</td><td>' . esc_html($ultima_mod) . '</td><td>' . esc_html($asistio) . '</td><td>' . esc_html($r['observacion'] ?? '') . '</td></tr>';
    }
    $html .= '</table></body></html>';
    $filename = 'asistencia-alumno-' . sanitize_file_name($ci ?: $alumno_id) . '-' . date('Y-m-d') . '.pdf';
    $autoload_path = defined('NC_PATH') ? (NC_PATH . 'vendor/autoload.php') : '';
    if ($autoload_path !== '' && is_file($autoload_path)) require_once $autoload_path;
    if (!class_exists('TCPDF')) {
      return self::err('La exportación en PDF requiere la librería TCPDF en el servidor.', 503);
    }
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetTitle($titulo);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
  }

  // ---------------- Export CSV / PDF ----------------

  private static function parse_ymd_date(string $value): ?DateTimeImmutable {
    if ($value === '') return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) return null;
    return $dt->format('Y-m-d') === $value ? $dt : null;
  }

  private static function weekday_short_es(string $ymd): string {
    $dt = self::parse_ymd_date($ymd);
    if (!$dt) return '';
    $w = (int) $dt->format('N');
    $map = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie', 6 => 'Sab', 7 => 'Dom'];
    return $map[$w] ?? '';
  }

  private static function calendar_header_label(string $ymd, string $materia_label = 'Todas las materias'): string {
    $dt = self::parse_ymd_date($ymd);
    if (!$dt) return $ymd;
    $materia = trim($materia_label) !== '' ? trim($materia_label) : 'Todas las materias';
    return $materia . ' + ' . self::weekday_short_es($ymd) . ' + ' . $dt->format('d/m/Y');
  }

  /**
   * Devuelve fechas calendario para vista semanal (lunes a sabado) o por rango.
   * - Si hay rango, usa ese rango y filtra solo lunes..sabado.
   * - Si no hay rango, toma la semana de $fecha_ref (o hoy) y devuelve lunes..sabado.
   */
  private static function calendar_dates(string $fecha_ref, string $fecha_desde, string $fecha_hasta): array {
    if ($fecha_desde !== '' && $fecha_hasta !== '') {
      $desde = self::parse_ymd_date($fecha_desde);
      $hasta = self::parse_ymd_date($fecha_hasta);
      if (!$desde || !$hasta || $desde > $hasta) return [];
      $out = [];
      for ($d = $desde; $d <= $hasta; $d = $d->modify('+1 day')) {
        $weekday = (int) $d->format('N');
        if ($weekday >= 1 && $weekday <= 6) $out[] = $d->format('Y-m-d');
      }
      return $out;
    }

    $base = self::parse_ymd_date($fecha_ref);
    if (!$base) $base = new DateTimeImmutable('today');
    $weekday = (int) $base->format('N');
    $monday = $base->modify('-' . ($weekday - 1) . ' day');
    $out = [];
    for ($i = 0; $i < 6; $i++) $out[] = $monday->modify('+' . $i . ' day')->format('Y-m-d');
    return $out;
  }

  private static function alumnos_por_aula(int $aula_id): array {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_al_a = $wpdb->prefix . 'conducta_alumno_aulas';
    $use_rel_a = ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($t_al_a) . "'") === $t_al_a);
    return $use_rel_a
      ? ($wpdb->get_results($wpdb->prepare(
          "SELECT a.id, a.nombres, a.apellidos, a.ci
           FROM $t_al a
           INNER JOIN $t_al_a ag ON ag.alumno_id=a.id AND ag.aula_id=%d AND ag.activo=1
           WHERE a.activo=1
           ORDER BY a.apellidos, a.nombres",
          $aula_id
        ), ARRAY_A) ?: [])
      : ($wpdb->get_results($wpdb->prepare(
          "SELECT id, nombres, apellidos, ci FROM $t_al WHERE aula_id=%d AND activo=1 ORDER BY apellidos, nombres",
          $aula_id
        ), ARRAY_A) ?: []);
  }

  /**
   * Mapa asistencia por alumno y fecha para vista calendario.
   * Valor por celda:
   * - '-' sin sesiones en ese dia
   * - 'Sí' si asistio a todas sus sesiones del dia
   * - 'No' si no asistio a ninguna
   * - 'x/y' si asistio parcialmente
   */
  private static function calendar_attendance_map(int $aula_id, array $alumno_ids, string $fecha_desde, string $fecha_hasta, ?int $materia_id = null): array {
    if (!$alumno_ids || $fecha_desde === '' || $fecha_hasta === '') return [];
    global $wpdb;
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $ids = implode(',', array_map('intval', $alumno_ids));
    $where_materia = '';
    $params = [$aula_id, $fecha_desde, $fecha_hasta];
    if (!empty($materia_id)) {
      $where_materia = ' AND s.materia_id=%d';
      $params[] = (int) $materia_id;
    }
    $sql = "SELECT i.alumno_id, s.fecha,
                   COUNT(*) AS total,
                   COALESCE(SUM(COALESCE(m.asistio, i.asistio)), 0) AS presentes
            FROM $t_items i
            INNER JOIN $t_asis s ON s.id=i.asistencia_id AND s.activo=1
            LEFT JOIN $t_mod m ON m.asistencia_id=i.asistencia_id AND m.asistencia_item_id=i.id
            WHERE s.aula_id=%d AND s.fecha>=%s AND s.fecha<=%s$where_materia
              AND i.alumno_id IN ($ids)
            GROUP BY i.alumno_id, s.fecha";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    $map = [];
    foreach ($rows as $r) {
      $alumno_id = (int) ($r['alumno_id'] ?? 0);
      $fecha = (string) ($r['fecha'] ?? '');
      $total = (int) ($r['total'] ?? 0);
      $presentes = (int) ($r['presentes'] ?? 0);
      if ($alumno_id <= 0 || $fecha === '' || $total <= 0) continue;
      if ($presentes >= $total) $val = 'Sí';
      elseif ($presentes <= 0) $val = 'No';
      else $val = $presentes . '/' . $total;
      if (!isset($map[$alumno_id])) $map[$alumno_id] = [];
      $map[$alumno_id][$fecha] = $val;
    }
    return $map;
  }

  public static function export_csv(WP_REST_Request $req) {
    $fecha = self::norm_str($req->get_param('fecha'));
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $materia_id = self::int_or_null($req->get_param('materia_id'));
    $vista = strtolower(self::norm_str($req->get_param('vista')));
    $es_calendario = in_array($vista, ['calendario', 'semana'], true);
    if (!$aula_id) return self::err('Grupo aula es obligatorio para exportar.');
    if (!$es_calendario && $fecha_desde !== '' && $fecha_hasta !== '') {
      $fecha = $fecha_desde;
    }
    if (!$es_calendario && $fecha === '' && ($fecha_desde === '' || $fecha_hasta === '')) return self::err('Indique fecha o rango (fecha_desde y fecha_hasta) para exportar.');

    global $wpdb;
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $aula_nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d", $aula_id));

    if ($es_calendario) {
      $fechas = self::calendar_dates($fecha, $fecha_desde, $fecha_hasta);
      if (!$fechas) return self::err('Fechas inválidas para vista calendario. Use formato YYYY-MM-DD.', 400);
      $alumnos_aula = self::alumnos_por_aula((int) $aula_id);
      $materia_label = 'Todas las materias';
      if ($materia_id) {
        $m_sel = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_mat WHERE id=%d", $materia_id));
        if (!empty($m_sel)) $materia_label = (string) $m_sel;
      }
      $cabeceras = ['Nombres', 'Apellidos', 'CI'];
      foreach ($fechas as $f) $cabeceras[] = self::calendar_header_label($f, $materia_label);
      $alumno_ids = array_map(static function($a) { return (int) ($a['id'] ?? 0); }, $alumnos_aula);
      $map = self::calendar_attendance_map((int) $aula_id, $alumno_ids, $fechas[0], $fechas[count($fechas) - 1], $materia_id);
      $out = [];
      foreach ($alumnos_aula as $al) {
        $al_id = (int) ($al['id'] ?? 0);
        $fila = [$al['nombres'] ?? '', $al['apellidos'] ?? '', $al['ci'] ?? ''];
        foreach ($fechas as $f) $fila[] = $map[$al_id][$f] ?? '-';
        $out[] = $fila;
      }
      $titulo_fecha = $fechas[0] . '_a_' . $fechas[count($fechas) - 1];
      $filename = 'reporte-asistencia-calendario-' . sanitize_file_name($aula_nombre ?: 'aula') . '-' . $titulo_fecha . '.csv';
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      $fh = fopen('php://output', 'w');
      fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
      fputcsv($fh, $cabeceras);
      foreach ($out as $row) fputcsv($fh, $row);
      fclose($fh);
      exit;
    }

    if ($fecha_desde !== '' && $fecha_hasta !== '') {
      if ($materia_id) {
        $sesiones = $wpdb->get_results($wpdb->prepare(
          "SELECT a.id, a.materia_id, a.fecha FROM $t_asis a WHERE a.activo=1 AND a.fecha>=%s AND a.fecha<=%s AND a.aula_id=%d AND a.materia_id=%d ORDER BY a.fecha ASC, a.id ASC",
          $fecha_desde, $fecha_hasta, $aula_id, $materia_id
        ), ARRAY_A);
      } else {
        $sesiones = $wpdb->get_results($wpdb->prepare(
          "SELECT a.id, a.materia_id, a.fecha FROM $t_asis a WHERE a.activo=1 AND a.fecha>=%s AND a.fecha<=%s AND a.aula_id=%d ORDER BY a.fecha ASC, a.id ASC",
          $fecha_desde, $fecha_hasta, $aula_id
        ), ARRAY_A);
      }
    } else {
      $sesiones = $wpdb->get_results($wpdb->prepare(
        "SELECT id, materia_id, fecha FROM $t_asis WHERE activo=1 AND fecha=%s AND aula_id=%d ORDER BY id",
        $fecha, $aula_id
      ), ARRAY_A);
    }
    if (!$sesiones) return self::err('No hay registros de asistencia para ese rango y grupo aula. Ajuste fechas o grupo aula.', 400);

    $materias = [];
    $use_fecha_en_col = (count($sesiones) > 1 || ($fecha_desde !== '' && $fecha_hasta !== ''));
    foreach ($sesiones as $s) {
      $m = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_mat WHERE id=%d", $s['materia_id']));
      $nombre = $m ?: 'Materia ' . $s['materia_id'];
      $materias[$s['id']] = $use_fecha_en_col ? ($s['fecha'] . ' - ' . $nombre) : $nombre;
    }

    $alumnos_aula = self::alumnos_por_aula((int) $aula_id);
    $cabeceras = ['Nombres', 'Apellidos', 'CI'];
    foreach ($materias as $nombre) $cabeceras[] = $nombre;

    $out = [];
    foreach ($alumnos_aula as $al) {
      $fila = [$al['nombres'], $al['apellidos'], $al['ci']];
      foreach (array_keys($materias) as $asistencia_id) {
        $v = $wpdb->get_row($wpdb->prepare(
          "SELECT asistio FROM $t_items WHERE asistencia_id=%d AND alumno_id=%d",
          $asistencia_id, $al['id']
        ), ARRAY_A);
        $fila[] = ($v && (int)$v['asistio'] === 1) ? 'Sí' : 'No';
      }
      $out[] = $fila;
    }

    $titulo_fecha = ($fecha_desde !== '' && $fecha_hasta !== '') ? ($fecha_desde . '_a_' . $fecha_hasta) : $fecha;
    $filename = 'reporte-asistencia-' . sanitize_file_name($aula_nombre ?: 'aula') . '-' . str_replace(['/', ' '], ['-', ''], $titulo_fecha) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh, $cabeceras);
    foreach ($out as $row) fputcsv($fh, $row);
    fclose($fh);
    exit;
  }

  public static function export_pdf(WP_REST_Request $req) {
    $fecha = self::norm_str($req->get_param('fecha'));
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $materia_id = self::int_or_null($req->get_param('materia_id'));
    $vista = strtolower(self::norm_str($req->get_param('vista')));
    $es_calendario = in_array($vista, ['calendario', 'semana'], true);
    if (!$aula_id) return self::err('Grupo aula es obligatorio para exportar.');
    if (!$es_calendario && $fecha_desde !== '' && $fecha_hasta !== '') {
      $fecha = $fecha_desde;
    }
    if (!$es_calendario && $fecha === '' && ($fecha_desde === '' || $fecha_hasta === '')) return self::err('Indique fecha o rango (fecha_desde y fecha_hasta) para exportar.');

    global $wpdb;
    $t_asis = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $aula_nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d", $aula_id));

    if ($es_calendario) {
      $fechas = self::calendar_dates($fecha, $fecha_desde, $fecha_hasta);
      if (!$fechas) return self::err('Fechas inválidas para vista calendario. Use formato YYYY-MM-DD.', 400);
      $alumnos_aula = self::alumnos_por_aula((int) $aula_id);
      $materia_label = 'Todas las materias';
      if ($materia_id) {
        $m_sel = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_mat WHERE id=%d", $materia_id));
        if (!empty($m_sel)) $materia_label = (string) $m_sel;
      }
      $alumno_ids = array_map(static function($a) { return (int) ($a['id'] ?? 0); }, $alumnos_aula);
      $map = self::calendar_attendance_map((int) $aula_id, $alumno_ids, $fechas[0], $fechas[count($fechas) - 1], $materia_id);
      $titulo = 'Reporte de asistencia calendario - ' . ($aula_nombre ?: 'Aula') . ' - ' . $fechas[0] . ' a ' . $fechas[count($fechas) - 1];
      $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($titulo) . '</title>';
      $html .= '<style>table{border-collapse:collapse;width:100%;}th,td{border:1px solid #333;padding:6px;text-align:left;}th{background:#eee;}</style></head><body>';
      $html .= '<h1>' . esc_html($titulo) . '</h1><table><tr><th>Nombres</th><th>Apellidos</th><th>CI</th>';
      foreach ($fechas as $f) $html .= '<th>' . esc_html(self::calendar_header_label($f, $materia_label)) . '</th>';
      $html .= '</tr>';
      foreach ($alumnos_aula as $al) {
        $al_id = (int) ($al['id'] ?? 0);
        $html .= '<tr><td>' . esc_html($al['nombres'] ?? '') . '</td><td>' . esc_html($al['apellidos'] ?? '') . '</td><td>' . esc_html($al['ci'] ?? '') . '</td>';
        foreach ($fechas as $f) $html .= '<td>' . esc_html($map[$al_id][$f] ?? '-') . '</td>';
        $html .= '</tr>';
      }
      $html .= '</table></body></html>';
      $filename = 'reporte-asistencia-calendario-' . sanitize_file_name($aula_nombre ?: 'aula') . '-' . str_replace(['/', ' '], ['-', ''], $fechas[0] . '_a_' . $fechas[count($fechas) - 1]) . '.pdf';
      $autoload_path = defined('NC_PATH') ? (NC_PATH . 'vendor/autoload.php') : '';
      if ($autoload_path !== '' && is_file($autoload_path)) {
        require_once $autoload_path;
      }
      if (!class_exists('TCPDF')) {
        return self::err('La exportación en PDF requiere la librería TCPDF en el servidor. Contacte al administrador o use la opción Exportar CSV.', 503);
      }
      $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
      $pdf->SetTitle($titulo);
      $pdf->AddPage();
      $pdf->writeHTML($html, true, false, true, false, '');
      $pdf->Output($filename, 'D');
      exit;
    }

    if ($fecha_desde !== '' && $fecha_hasta !== '') {
      if ($materia_id) {
        $sesiones = $wpdb->get_results($wpdb->prepare(
          "SELECT a.id, a.materia_id, a.fecha FROM $t_asis a WHERE a.activo=1 AND a.fecha>=%s AND a.fecha<=%s AND a.aula_id=%d AND a.materia_id=%d ORDER BY a.fecha ASC, a.id ASC",
          $fecha_desde, $fecha_hasta, $aula_id, $materia_id
        ), ARRAY_A);
      } else {
        $sesiones = $wpdb->get_results($wpdb->prepare(
          "SELECT a.id, a.materia_id, a.fecha FROM $t_asis a WHERE a.activo=1 AND a.fecha>=%s AND a.fecha<=%s AND a.aula_id=%d ORDER BY a.fecha ASC, a.id ASC",
          $fecha_desde, $fecha_hasta, $aula_id
        ), ARRAY_A);
      }
    } else {
      $sesiones = $wpdb->get_results($wpdb->prepare(
        "SELECT id, materia_id, fecha FROM $t_asis WHERE activo=1 AND fecha=%s AND aula_id=%d ORDER BY id",
        $fecha, $aula_id
      ), ARRAY_A);
    }
    if (!$sesiones) return self::err('No hay registros de asistencia para ese rango y grupo aula. Ajuste fechas o grupo aula.', 400);

    $materias = [];
    $use_fecha_en_col = (count($sesiones) > 1 || ($fecha_desde !== '' && $fecha_hasta !== ''));
    foreach ($sesiones as $s) {
      $m = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_mat WHERE id=%d", $s['materia_id']));
      $nombre = $m ?: 'Materia ' . $s['materia_id'];
      $materias[$s['id']] = $use_fecha_en_col ? ($s['fecha'] . ' - ' . $nombre) : $nombre;
    }

    $alumnos_aula = self::alumnos_por_aula((int) $aula_id);

    $titulo_fecha = ($fecha_desde !== '' && $fecha_hasta !== '') ? ($fecha_desde . ' a ' . $fecha_hasta) : $fecha;
    $titulo = 'Reporte de asistencia - ' . ($aula_nombre ?: 'Aula') . ' - ' . $titulo_fecha;
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($titulo) . '</title>';
    $html .= '<style>table{border-collapse:collapse;width:100%;}th,td{border:1px solid #333;padding:6px;text-align:left;}th{background:#eee;}</style></head><body>';
    $html .= '<h1>' . esc_html($titulo) . '</h1><table><tr><th>Nombres</th><th>Apellidos</th><th>CI</th>';
    foreach ($materias as $nombre) $html .= '<th>' . esc_html($nombre) . '</th>';
    $html .= '</tr>';
    foreach ($alumnos_aula as $al) {
      $html .= '<tr><td>' . esc_html($al['nombres']) . '</td><td>' . esc_html($al['apellidos']) . '</td><td>' . esc_html($al['ci']) . '</td>';
      foreach (array_keys($materias) as $asistencia_id) {
        $v = $wpdb->get_row($wpdb->prepare(
          "SELECT asistio FROM $t_items WHERE asistencia_id=%d AND alumno_id=%d",
          $asistencia_id, $al['id']
        ), ARRAY_A);
        $html .= '<td>' . (($v && (int)$v['asistio'] === 1) ? 'Sí' : 'No') . '</td>';
      }
      $html .= '</tr>';
    }
    $html .= '</table></body></html>';

    $filename = 'reporte-asistencia-' . sanitize_file_name($aula_nombre ?: 'aula') . '-' . str_replace(['/', ' '], ['-', ''], $titulo_fecha) . '.pdf';
    $autoload_path = defined('NC_PATH') ? (NC_PATH . 'vendor/autoload.php') : '';
    if ($autoload_path !== '' && is_file($autoload_path)) {
      require_once $autoload_path;
    }
    if (!class_exists('TCPDF')) {
      return self::err('La exportación en PDF requiere la librería TCPDF en el servidor. Contacte al administrador o use la opción Exportar CSV.', 503);
    }
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetTitle($titulo);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
  }

  /** Lista usuarios con rol docente */
  public static function list_docentes(WP_REST_Request $req) {
    $role = 'docente';
    if (!get_role($role)) {
      add_role($role, 'Docente', ['read' => true]);
    }
    $users = get_users(['role' => $role, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]);
    $out = [];
    foreach ($users as $u) {
      $out[] = ['id' => (int)$u->ID, 'display_name' => $u->display_name, 'user_email' => $u->user_email, 'user_login' => $u->user_login];
    }
    return self::ok(['items' => $out]);
  }

  /**
   * Lista materias asociadas a un docente (user_id) según conducta_materia_docentes.
   */
  public static function list_docente_materias(WP_REST_Request $req) {
    global $wpdb;
    $docente_id = (int) $req['id'];
    if ($docente_id <= 0) return self::err('ID de docente inválido.', 400);

    $t_md = $wpdb->prefix . 'conducta_materia_docentes';
    $t_m  = $wpdb->prefix . 'conducta_materias';

    $sql = "SELECT m.id, m.nombre, m.activo
            FROM $t_md md
            LEFT JOIN $t_m m ON m.id = md.materia_id
            WHERE md.user_id = %d AND md.activo = 1 AND m.id IS NOT NULL
            ORDER BY m.nombre ASC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $docente_id), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  /**
   * Lista cursos asociados a un docente (cursos que tienen al menos una materia que el docente dicta).
   */
  public static function list_docente_cursos(WP_REST_Request $req) {
    global $wpdb;
    $docente_id = (int) $req['id'];
    if ($docente_id <= 0) return self::err('ID de docente inválido.', 400);
    $materia_id = self::int_or_null($req->get_param('materia_id'));

    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_cm  = $wpdb->prefix . 'conducta_curso_materias';
    $t_md  = $wpdb->prefix . 'conducta_materia_docentes';

    $sql = "SELECT DISTINCT c.id, c.nombre, c.activo
            FROM $t_cur c
            INNER JOIN $t_cm cm ON cm.curso_id = c.id
            INNER JOIN $t_md md ON md.materia_id = cm.materia_id AND md.user_id = %d AND md.activo = 1
            WHERE c.activo = 1
            ORDER BY c.nombre ASC";
    $params = [$docente_id];
    if ($materia_id) {
      $sql = "SELECT DISTINCT c.id, c.nombre, c.activo
              FROM $t_cur c
              INNER JOIN $t_cm cm ON cm.curso_id = c.id
              INNER JOIN $t_md md ON md.materia_id = cm.materia_id AND md.user_id = %d AND md.activo = 1
              WHERE c.activo = 1 AND cm.materia_id = %d
              ORDER BY c.nombre ASC";
      $params[] = $materia_id;
    }

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  /** Crea nuevo usuario con rol docente (solo roles admin) */
  public static function crear_docente(WP_REST_Request $req) {
    if (!NC_Roles::user_is_admin()) return self::err('Sin permiso para crear docentes', 403);
    if (!current_user_can('create_users')) return self::err('Sin permiso para crear usuarios', 403);
    $p = $req->get_json_params() ?: [];
    $email = self::norm_str($p['email'] ?? '');
    $display_name = self::norm_str($p['display_name'] ?? '');
    $pass_raw = isset($p['password']) ? trim((string)$p['password']) : '';
    $password = $pass_raw !== '' ? $pass_raw : wp_generate_password(12, true);
    if ($email === '') return self::err('El correo electrónico es obligatorio.');
    if (email_exists($email)) return self::err('Ya existe un usuario con ese correo.');
    $user_login = sanitize_user(str_replace(['@', '.'], '_', $email), true);
    if (username_exists($user_login)) $user_login = $user_login . '_' . wp_rand(100, 999);
    $user_id = wp_create_user($user_login, $password, $email);
    if (is_wp_error($user_id)) return self::err($user_id->get_error_message(), 400);
    $user = get_user_by('id', $user_id);
    $user->set_role('docente');
    if ($display_name !== '') wp_update_user(['ID' => $user_id, 'display_name' => $display_name]);
    return self::ok(['id' => $user_id], 201);
  }

  /** Asigna rol docente a usuario existente (solo roles admin) */
  public static function asignar_docente(WP_REST_Request $req) {
    if (!NC_Roles::user_is_admin()) return self::err('Sin permiso para asignar docentes', 403);
    if (!current_user_can('promote_users')) return self::err('Sin permiso para cambiar roles', 403);
    $p = $req->get_json_params() ?: [];
    $user_id = (int)($p['user_id'] ?? 0);
    if ($user_id <= 0) return self::err('user_id obligatorio.');
    $user = get_user_by('id', $user_id);
    if (!$user) return self::err('Usuario no encontrado', 404);
    if (!get_role('docente')) add_role('docente', 'Docente', ['read' => true]);
    $user->set_role('docente');
    return self::ok(['updated' => true]);
  }

  public static function list_usuarios(WP_REST_Request $req) {
    global $wpdb;
    $search = self::norm_str($req->get_param('search'));
    $users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]);
    $out = [];
    foreach ($users as $u) {
      if ($search !== '' && stripos($u->display_name, $search) === false && stripos($u->user_email, $search) === false) continue;
      $out[] = ['id' => (int)$u->ID, 'display_name' => $u->display_name, 'user_email' => $u->user_email];
    }
    return self::ok(['items' => $out]);
  }

  // ---------------- Simulacros ----------------

  /** Docente fijo "Examen" para simulacros. */
  private static function resolve_docente_examen_id(): ?int {
    if (!get_role('docente')) {
      add_role('docente', 'Docente', ['read' => true]);
    }
    $users = get_users(['role' => 'docente', 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]);
    $fuzzy = null;
    foreach ($users as $u) {
      $key = strtolower(trim((string) $u->display_name));
      if ($key === 'examen') {
        return (int) $u->ID;
      }
      if ($fuzzy === null && strpos($key, 'examen') !== false) {
        $fuzzy = (int) $u->ID;
      }
    }
    return $fuzzy;
  }

  /** Materias tipo examen (nombre contiene "examen"). */
  private static function list_materias_examen(): array {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_materias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
      return [];
    }
    $rows = $wpdb->get_results(
      "SELECT id, nombre, activo FROM $t WHERE activo=1 AND LOWER(nombre) LIKE '%examen%' ORDER BY nombre ASC",
      ARRAY_A
    );
    return $rows ?: [];
  }

  public static function simulacros_config(WP_REST_Request $req) {
    self::ensure_tables();
    $docente_id = self::resolve_docente_examen_id();
    $docente_name = '';
    if ($docente_id) {
      $u = get_user_by('id', $docente_id);
      $docente_name = $u ? (string) $u->display_name : 'Examen';
    }
    return self::ok([
      'docente_examen_id' => $docente_id,
      'docente_examen_nombre' => $docente_name !== '' ? $docente_name : 'Examen',
      'materias_examen' => self::list_materias_examen(),
    ]);
  }

  public static function list_simulacros(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_simulacros';
    $t_m = $wpdb->prefix . 'conducta_materias';
    $t_a = $wpdb->prefix . 'conducta_aulas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
      return self::ok(['items' => []]);
    }
    $t_sa = $wpdb->prefix . 'conducta_simulacro_alumnos';
    $rows = $wpdb->get_results(
      "SELECT s.id, s.nombre, s.materia_id, s.aula_id, s.docente_encargado_id, s.created_at,
              m.nombre AS materia_nombre,
              a.nombre AS aula_nombre,
              (SELECT COUNT(*) FROM $t_sa sa WHERE sa.simulacro_id = s.id) AS total_alumnos
       FROM $t s
       LEFT JOIN $t_m m ON m.id = s.materia_id
       LEFT JOIN $t_a a ON a.id = s.aula_id
       WHERE s.activo=1
       ORDER BY s.created_at DESC, s.id DESC",
      ARRAY_A
    );
    return self::ok(['items' => $rows ?: []]);
  }

  public static function get_simulacro(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $id = (int) $req['id'];
    $t = $wpdb->prefix . 'conducta_simulacros';
    $t_m = $wpdb->prefix . 'conducta_materias';
    $t_a = $wpdb->prefix . 'conducta_aulas';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_sa = $wpdb->prefix . 'conducta_simulacro_alumnos';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT s.*, m.nombre AS materia_nombre, a.nombre AS aula_nombre
       FROM $t s
       LEFT JOIN $t_m m ON m.id = s.materia_id
       LEFT JOIN $t_a a ON a.id = s.aula_id
       WHERE s.id=%d AND s.activo=1",
      $id
    ), ARRAY_A);
    if (!$row) {
      return self::err('Simulacro no encontrado', 404);
    }

    $docente_name = '';
    if (!empty($row['docente_encargado_id'])) {
      $u = get_user_by('id', (int) $row['docente_encargado_id']);
      $docente_name = $u ? (string) $u->display_name : '';
    }
    $row['docente_nombre'] = $docente_name;

    $alumnos = $wpdb->get_results($wpdb->prepare(
      "SELECT a.id, a.nombres, a.apellidos, a.ci, a.foto_url
       FROM $t_sa sa
       INNER JOIN $t_al a ON a.id = sa.alumno_id AND a.activo = 1
       WHERE sa.simulacro_id = %d
       ORDER BY a.apellidos ASC, a.nombres ASC",
      $id
    ), ARRAY_A);

    $row['alumnos'] = $alumnos ?: [];
    return self::ok($row);
  }

  public static function create_simulacro(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) {
      return self::err('No autorizado', 401);
    }

    $p = $req->get_json_params() ?: [];
    $materia_id = (int) ($p['materia_id'] ?? 0);
    $aula_id = (int) ($p['aula_id'] ?? 0);
    $alumno_ids = isset($p['alumno_ids']) && is_array($p['alumno_ids']) ? $p['alumno_ids'] : [];
    $nombre = self::norm_str($p['nombre'] ?? '');

    if ($materia_id <= 0) {
      return self::err('Seleccione el tipo de examen (materia).');
    }
    if ($aula_id <= 0) {
      return self::err('Seleccione el aula física.');
    }

    $docente_id = self::resolve_docente_examen_id();
    if (!$docente_id) {
      return self::err('No se encontró el docente "Examen". Creá un usuario docente con ese nombre.', 400);
    }

    $t_m = $wpdb->prefix . 'conducta_materias';
    $materia = $wpdb->get_row($wpdb->prepare("SELECT id, nombre FROM $t_m WHERE id=%d AND activo=1", $materia_id), ARRAY_A);
    if (!$materia) {
      return self::err('Materia de examen no encontrada.', 404);
    }
    $materia_nombre = (string) ($materia['nombre'] ?? '');
    if (stripos($materia_nombre, 'examen') === false) {
      return self::err('La materia seleccionada no es un examen válido.');
    }

    $t_a = $wpdb->prefix . 'conducta_aulas';
    $aula = $wpdb->get_row($wpdb->prepare("SELECT id, nombre FROM $t_a WHERE id=%d", $aula_id), ARRAY_A);
    if (!$aula) {
      return self::err('Aula física no encontrada.', 404);
    }

    $ids = [];
    foreach ($alumno_ids as $aid) {
      $n = (int) $aid;
      if ($n > 0) {
        $ids[$n] = $n;
      }
    }
    $ids = array_values($ids);
    if (empty($ids)) {
      return self::err('Agregue al menos un alumno a la lista personalizada.');
    }

    $aula_label = trim(explode('->', (string) ($aula['nombre'] ?? ''))[0]);
    if ($nombre === '') {
      $dt = new DateTimeImmutable('now', new DateTimeZone('America/Asuncion'));
      $nombre = $materia_nombre . ' — Aula ' . ($aula_label !== '' ? $aula_label : $aula_id) . ' — ' . $dt->format('d/m/Y H:i');
    }

    $t = $wpdb->prefix . 'conducta_simulacros';
    $t_sa = $wpdb->prefix . 'conducta_simulacro_alumnos';
    $dt_py = new DateTimeImmutable('now', new DateTimeZone('America/Asuncion'));

    $wpdb->insert($t, [
      'nombre' => $nombre,
      'materia_id' => $materia_id,
      'aula_id' => $aula_id,
      'docente_encargado_id' => $docente_id,
      'creado_por' => $user_id,
      'created_at' => $dt_py->format('Y-m-d H:i:s'),
      'activo' => 1,
    ]);
    $sim_id = (int) $wpdb->insert_id;
    if ($sim_id <= 0) {
      return self::err('Error al crear el simulacro.', 500);
    }

    $t_al = $wpdb->prefix . 'conducta_alumnos';
    foreach ($ids as $alumno_id) {
      $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_al WHERE id=%d AND activo=1", $alumno_id));
      if (!$exists) {
        continue;
      }
      $wpdb->insert($t_sa, [
        'simulacro_id' => $sim_id,
        'alumno_id' => $alumno_id,
      ]);
    }

    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_sa WHERE simulacro_id=%d", $sim_id));
    if ($count <= 0) {
      $wpdb->update($t, ['activo' => 0], ['id' => $sim_id]);
      return self::err('Ningún alumno válido en la lista.');
    }

    return self::ok(['id' => $sim_id, 'nombre' => $nombre, 'total_alumnos' => $count], 201);
  }

  public static function delete_simulacro(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $id = (int) $req['id'];
    $t = $wpdb->prefix . 'conducta_simulacros';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d AND activo=1", $id));
    if (!$exists) {
      return self::err('Simulacro no encontrado', 404);
    }
    if (!self::can_manage_attendance() && (int) $wpdb->get_var($wpdb->prepare("SELECT creado_por FROM $t WHERE id=%d", $id)) !== get_current_user_id()) {
      return self::err('No tiene permiso para eliminar este simulacro', 403);
    }
    $wpdb->update($t, ['activo' => 0], ['id' => $id]);
    return self::ok(['deleted' => true]);
  }
}