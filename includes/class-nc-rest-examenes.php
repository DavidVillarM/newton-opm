<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API para Puntajes / Exámenes.
 * Rutas: exámenes (CRUD), exámenes/:id/materias, exámenes/:id/puntajes, puntajes (listado filtrable).
 */
class NC_Rest_Examenes {

  private static $schema_ensured = false;

  private static function ensure_tables() {
    if (self::$schema_ensured) return;
    try {
      NC_Examenes_DB::maybe_upgrade();
      self::$schema_ensured = true;
    } catch (Throwable $e) {
      error_log('[NC_Examenes] ensure_schema: ' . $e->getMessage());
    }
  }

  private static function ns() {
    return 'conducta/v1';
  }

  public static function can_access() {
    return NC_Roles::user_can_access();
  }

  public static function can_edit_notas() {
    return NC_Roles::user_is_admin();
  }

  private static function err($message, int $status = 400) {
    return new WP_Error('nc_error', $message, ['status' => $status]);
  }

  private static function ok($data, int $status = 200) {
    return new WP_REST_Response($data, $status);
  }

  private static function norm_str($v): string {
    return trim(sanitize_text_field((string)($v ?? '')));
  }

  private static function int_or_null($v): ?int {
    $n = is_numeric($v) ? (int)$v : 0;
    return $n > 0 ? $n : null;
  }

  private static function norm_key(string $s): string {
    $s = strtolower(trim($s));
    $s = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $s);
    return preg_replace('/[^a-z0-9]+/', '', $s);
  }

  private static function norm_tokens(string $s): array {
    $s = strtolower(trim($s));
    $s = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $s);
    $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_values($parts) : [];
  }

  private static function ingenieria_grupo_key(string $nombre): string {
    $k = self::norm_key($nombre);
    $tokens = self::norm_tokens($nombre);
    if ($k === '') return '';
    if (in_array('nu', $tokens, true) || in_array('n', $tokens, true)) return 'nu';
    if (in_array('mu', $tokens, true) || in_array('m', $tokens, true)) return 'mu';
    if (in_array('zeta', $tokens, true) || in_array('z', $tokens, true)) return 'zeta';
    if (strpos($k, 'nu') !== false) return 'nu';
    if (strpos($k, 'mu') !== false) return 'mu';
    if (strpos($k, 'zeta') !== false) return 'zeta';
    return '';
  }

  private static function ingenieria_materia_key(string $nombre): string {
    $k = self::norm_key($nombre);
    if ($k === '') return '';
    $map = [
      'algebra' => 'algebra',
      'aritmetica' => 'aritmetica',
      'trigonometria' => 'trigonometria',
      'geometriaanalitica' => 'geometriaanalitica',
      'fisica' => 'fisica',
      'informatica' => 'informatica',
    ];
    return isset($map[$k]) ? $map[$k] : '';
  }

  private static function validate_ingenieria_grupos_y_materia(array $grupo_ids, array $valid_temas): ?string {
    if (empty($grupo_ids)) return 'Debe seleccionar al menos un grupo de Ingeniería (Nu, Mu o Zeta).';
    if (empty($valid_temas)) return 'Debe seleccionar una materia válida para Ingeniería.';
    $materiaKey = self::ingenieria_materia_key((string) ($valid_temas[0]['nombre'] ?? ''));
    if ($materiaKey === '') {
      return 'Materia de Ingeniería no válida. Use: Algebra, Aritmetica, Trigonometria, Geometria Analitica, Fisica o Informatica.';
    }
    global $wpdb;
    $t_aulas = $wpdb->prefix . 'conducta_aulas';
    $ids = array_values(array_unique(array_filter(array_map('intval', $grupo_ids), static function ($v) { return $v > 0; })));
    if (empty($ids)) return 'Debe seleccionar al menos un grupo válido.';
    $rows = $wpdb->get_results("SELECT id, nombre FROM $t_aulas WHERE id IN (" . implode(',', $ids) . ")", ARRAY_A);
    if (count($rows ?: []) !== count($ids)) return 'Algunos grupos seleccionados no existen.';
    $grupoKeys = [];
    foreach ($rows as $r) {
      $gk = self::ingenieria_grupo_key((string) ($r['nombre'] ?? ''));
      if ($gk === '') {
        return 'Solo se permiten grupos Nu, Mu y Zeta para exámenes de Ingeniería.';
      }
      $grupoKeys[$gk] = true;
    }
    // La materia elegida debe ser válida para TODOS los grupos seleccionados.
    if ($materiaKey === 'informatica' && count($grupoKeys) !== 1) {
      return 'La materia Informatica solo puede usarse si el examen aplica únicamente al grupo Nu.';
    }
    if ($materiaKey === 'informatica' && !isset($grupoKeys['nu'])) {
      return 'La materia Informatica solo puede usarse con el grupo Nu.';
    }
    if ($materiaKey === 'fisica' && isset($grupoKeys['nu'])) {
      return 'La materia Fisica no puede combinarse con el grupo Nu.';
    }
    if ($materiaKey === 'fisica' && (!isset($grupoKeys['mu']) && !isset($grupoKeys['zeta']))) {
      return 'La materia Fisica solo puede usarse con grupos Mu y/o Zeta.';
    }
    return null;
  }

  private static function examen_target_key(string $nombre): string {
    $k = self::norm_key($nombre);
    if ($k === '') return '';
    if (strpos($k, 'algebra') !== false) return 'algebra';
    if (strpos($k, 'aritmetica') !== false) return 'aritmetica';
    if (strpos($k, 'geometriaanalitica') !== false) return 'geometriaanalitica';
    if (strpos($k, 'trigonometria') !== false) return 'trigonometria';
    if (strpos($k, 'programacion') !== false || strpos($k, 'informatica') !== false) return 'programacion';
    return '';
  }

  /**
   * Registra líneas base (puntaje 0) de exámenes de Ingeniería para alumnos del grupo Nu.
   * Se aplica únicamente a: Algebra, Aritmetica, Geometria Analitica, Trigonometria y Programacion.
   */
  public static function auto_registrar_alumno_examenes_nu($alumno_id, $aula_id): array {
    self::ensure_tables();
    $alumno_id = is_numeric($alumno_id) ? (int) $alumno_id : 0;
    $aula_id = is_numeric($aula_id) ? (int) $aula_id : 0;
    if ($alumno_id <= 0 || $aula_id <= 0) return ['registrados' => 0, 'examen_ids' => []];
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $aula_nombre = (string) $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $t_aul WHERE id=%d LIMIT 1", $aula_id));
    if (self::ingenieria_grupo_key($aula_nombre) !== 'nu') {
      return ['registrados' => 0, 'examen_ids' => []];
    }

    $allowed = [
      'algebra' => true,
      'aritmetica' => true,
      'geometriaanalitica' => true,
      'trigonometria' => true,
      'programacion' => true,
    ];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_ea = $wpdb->prefix . 'conducta_examen_aulas';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';

    $examenes = $wpdb->get_results($wpdb->prepare(
      "SELECT e.id, e.nombre
       FROM $t_ex e
       INNER JOIN $t_ea ea ON ea.examen_id=e.id
       WHERE e.activo=1 AND e.tipo='ingenieria' AND ea.aula_id=%d",
      $aula_id
    ), ARRAY_A);
    if (empty($examenes)) return ['registrados' => 0, 'examen_ids' => []];

    $registrados = 0;
    $touch_examen_ids = [];
    foreach ($examenes as $ex) {
      $examen_id = (int) ($ex['id'] ?? 0);
      if ($examen_id <= 0) continue;
      $target = self::examen_target_key((string) ($ex['nombre'] ?? ''));
      $temas = $wpdb->get_results($wpdb->prepare(
        "SELECT id, nombre FROM $t_et WHERE examen_id=%d ORDER BY orden ASC, id ASC",
        $examen_id
      ), ARRAY_A);
      if ($target === '' && !empty($temas)) {
        $target = self::examen_target_key((string) ($temas[0]['nombre'] ?? ''));
      }
      if ($target === '' || !isset($allowed[$target])) continue;
      if (empty($temas)) continue;
      $tema_id = (int) ($temas[0]['id'] ?? 0);
      if ($tema_id <= 0) continue;

      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t_ep WHERE examen_id=%d AND alumno_id=%d AND tema_id=%d AND materia_id IS NULL LIMIT 1",
        $examen_id,
        $alumno_id,
        $tema_id
      ));
      if ($exists) {
        $touch_examen_ids[] = $examen_id;
        continue;
      }
      $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $t_ep (examen_id, alumno_id, materia_id, tema_id, puntaje)
         VALUES (%d,%d,NULL,%d,%f)",
        $examen_id,
        $alumno_id,
        $tema_id,
        0
      ));
      if ($ok !== false) {
        $registrados++;
        $touch_examen_ids[] = $examen_id;
      }
    }
    return [
      'registrados' => $registrados,
      'examen_ids' => array_values(array_unique(array_map('intval', $touch_examen_ids))),
    ];
  }

  private static function cross_norm(string $s): string {
    $s = strtolower(trim((string) $s));
    $s = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string) $s));
  }

  /**
   * Devuelve clave canónica de materia para cruzar asistencia vs puntajes.
   */
  private static function cross_materia_key(string $nombre): string {
    $n = self::cross_norm($nombre);
    if ($n === '') return '';
    $compact = str_replace(' ', '', $n);
    $map = [
      'gua' => 'guarani',
      'guarani' => 'guarani',
      'cas' => 'castellano',
      'castellano' => 'castellano',
      'est' => 'estudios paraguayos',
      'estudiosparaguayos' => 'estudios paraguayos',
      'mat' => 'matematica',
      'matematica' => 'matematica',
      'fis' => 'fisica',
      'fisica' => 'fisica',
      'ino' => 'quimica inorganica',
      'ind' => 'quimica inorganica',
      'quimicainorganica' => 'quimica inorganica',
      'org' => 'quimica organica',
      'quimicaorganica' => 'quimica organica',
      'bio' => 'biologia',
      'biologia' => 'biologia',
      'ana' => 'anatomia',
      'anatomia' => 'anatomia',
      'algebra' => 'algebra',
      'aritmetica' => 'aritmetica',
      'geometriaanalitica' => 'geometria analitica',
      'geometria' => 'geometria',
    ];
    if (isset($map[$compact])) return $map[$compact];
    // Heurísticas por contenido
    if (strpos($n, 'geometria') !== false && strpos($n, 'analit') !== false) return 'geometria analitica';
    if (strpos($n, 'quimica') !== false && strpos($n, 'inorgan') !== false) return 'quimica inorganica';
    if (strpos($n, 'quimica') !== false && strpos($n, 'organ') !== false) return 'quimica organica';
    if (strpos($n, 'estudios') !== false && strpos($n, 'paragu') !== false) return 'estudios paraguayos';
    if (strpos($n, 'mate') !== false) return 'matematica';
    if (strpos($n, 'fis') !== false) return 'fisica';
    if (strpos($n, 'bio') !== false) return 'biologia';
    if (strpos($n, 'ana') !== false) return 'anatomia';
    if (strpos($n, 'castell') !== false) return 'castellano';
    if (strpos($n, 'guaran') !== false) return 'guarani';
    return $n;
  }

  private static function medicina_materia_ids_map(): array {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT id, nombre FROM " . $wpdb->prefix . "conducta_materias WHERE activo=1", ARRAY_A);
    $dict = [];
    foreach ($rows ?: [] as $r) {
      $dict[self::norm_key((string)$r['nombre'])] = (int)$r['id'];
    }
    $aliases = [
      'Guaraní' => ['guarani'],
      'Castellano' => ['castellano'],
      'Estudios paraguayos' => ['estudiosparaguayos'],
      'Matemática' => ['matematica'],
      'Física' => ['fisica'],
      'Química Inorgánica' => ['quimicainorganica','quimicainorganico'],
      'Química Orgánica' => ['quimicaorganica','quimicaorganico'],
      'Biología' => ['biologia'],
      'Anatomía' => ['anatomia'],
    ];
    $out = [];
    foreach ($aliases as $label => $keys) {
      $id = 0;
      foreach ($keys as $k) {
        if (!empty($dict[$k])) { $id = (int) $dict[$k]; break; }
      }
      $out[$label] = $id;
    }
    return $out;
  }

  private static function hydrate_rows_for_medicina(array $rows): array {
    $ids = self::medicina_materia_ids_map();
    foreach ($rows as &$r) {
      if ((int)($r['materia_id'] ?? 0) <= 0) {
        $label = isset($r['materia_nombre']) ? (string)$r['materia_nombre'] : '';
        $r['materia_id'] = isset($ids[$label]) ? (int)$ids[$label] : 0;
      }
      if (!isset($r['puntos_item']) || (float)$r['puntos_item'] <= 0) $r['puntos_item'] = 1.0;
    }
    unset($r);
    return $rows;
  }

  public static function register_routes(string $ns) {
    register_rest_route($ns, '/examenes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_examenes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_examen'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_examen'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [__CLASS__, 'update_examen'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [__CLASS__, 'delete_examen'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/materias', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_examen_materias'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/puntajes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_examen_puntajes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'save_examen_puntajes'],
        'permission_callback' => [__CLASS__, 'can_edit_notas'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/alumnos', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_examen_alumnos'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/puntajes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_puntajes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/reportes/general/cruce-asistencia-puntajes', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'reporte_cruce_asistencia_puntajes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/puntajes/historial', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_puntajes_historial'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/items', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'list_examen_items'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/items/import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'import_examen_items'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/shuffle', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_examen_shuffle'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/shuffle/import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'import_examen_shuffle'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/resultados/import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'import_examen_resultados'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/medicina/pdf/import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'import_medicina_pdf_puntajes'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/resumen', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_examen_resumen'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/(?P<id>\d+)/detalle', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [__CLASS__, 'get_examen_detalle'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
    register_rest_route($ns, '/examenes/medicina/auto-import', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'create_medicina_from_import'],
        'permission_callback' => [__CLASS__, 'can_access'],
      ],
    ]);
  }

  public static function list_examenes(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $where = 'e.activo=1';
    $params = [];
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    $tipo = self::norm_str($req->get_param('tipo'));
    if ($curso_id) { $where .= ' AND e.curso_id=%d'; $params[] = $curso_id; }
    if ($fecha_desde !== '') { $where .= ' AND e.fecha>=%s'; $params[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where .= ' AND e.fecha<=%s'; $params[] = $fecha_hasta; }
    if ($tipo !== '' && in_array($tipo, ['medicina', 'ingenieria'], true)) { $where .= ' AND e.tipo=%s'; $params[] = $tipo; }
    $sql = "SELECT e.id, e.nombre, e.tipo, e.curso_id, e.fecha, e.creado_por, e.created_at,
                   c.nombre AS curso_nombre
            FROM $t_ex e
            LEFT JOIN $t_cur c ON c.id=e.curso_id
            WHERE $where
            ORDER BY e.fecha DESC, e.created_at DESC";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return self::ok([
      'items' => $rows ?: [],
      'can_edit_notas' => self::can_edit_notas(),
    ]);
  }

  public static function list_puntajes_historial(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $t_h = $wpdb->prefix . 'conducta_examen_puntajes_historial';
    $t_m = $wpdb->prefix . 'conducta_materias';
    $t_t = $wpdb->prefix . 'conducta_examen_temas';
    $t_e = $wpdb->prefix . 'conducta_examenes';
    $t_a = $wpdb->prefix . 'conducta_alumnos';

    $where = '1=1';
    $params = [];
    $alumno_id = self::int_or_null($req->get_param('alumno_id'));
    $examen_id = self::int_or_null($req->get_param('examen_id'));
    if ($alumno_id) { $where .= ' AND h.alumno_id=%d'; $params[] = $alumno_id; }
    if ($examen_id) { $where .= ' AND h.examen_id=%d'; $params[] = $examen_id; }
    $limit = min(500, max(1, (int) ($req->get_param('limit') ?: 120)));

    $sql = "SELECT h.id, h.puntaje_id, h.examen_id, h.alumno_id, h.materia_id, h.tema_id,
                   h.puntaje_anterior, h.puntaje_nuevo, h.editado_por, h.editado_por_login, h.editado_por_nombre, h.motivo, h.created_at,
                   e.nombre AS examen_nombre, e.fecha AS examen_fecha,
                   COALESCE(m.nombre, t.nombre) AS materia_nombre,
                   a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.ci AS alumno_ci
            FROM $t_h h
            LEFT JOIN $t_m m ON m.id=h.materia_id
            LEFT JOIN $t_t t ON t.id=h.tema_id
            LEFT JOIN $t_e e ON e.id=h.examen_id
            LEFT JOIN $t_a a ON a.id=h.alumno_id
            WHERE $where
            ORDER BY h.created_at DESC
            LIMIT %d";
    $params[] = $limit;
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  public static function get_examen(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT e.id, e.nombre, e.tipo, e.curso_id, e.fecha, e.creado_por, e.created_at, c.nombre AS curso_nombre
       FROM $t_ex e LEFT JOIN $t_cur c ON c.id=e.curso_id WHERE e.id=%d AND e.activo=1",
      $id
    ), ARRAY_A);
    if (!$row) return self::err('Examen no encontrado', 404);
    $tipo = (string) ($row['tipo'] ?? 'ingenieria');
    $row['materias'] = ($tipo === 'medicina') ? self::_get_examen_materias($id) : [];
    $row['temas'] = ($tipo === 'ingenieria') ? self::_get_examen_temas($id) : [];
    $row['grupos'] = self::_get_examen_aulas($id);
    /* Ingeniería antigua: solo conducta_examen_materias (antes de existir temas propios). */
    if ($tipo === 'ingenieria' && empty($row['temas'])) {
      $row['materias'] = self::_get_examen_materias($id);
    }
    return self::ok($row);
  }

  private static function _get_examen_temas(int $examen_id): array {
    global $wpdb;
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, orden, nombre, puntos_maximos FROM $t_et WHERE examen_id=%d ORDER BY orden ASC, id ASC",
      $examen_id
    ), ARRAY_A);
    return $rows ?: [];
  }

  private static function _get_examen_materias(int $examen_id): array {
    global $wpdb;
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT em.id, em.materia_id, em.puntos_maximos, m.nombre AS materia_nombre
       FROM $t_em em LEFT JOIN $t_mat m ON m.id=em.materia_id WHERE em.examen_id=%d ORDER BY m.nombre",
      $examen_id
    ), ARRAY_A);
    return $rows ?: [];
  }

  private static function _get_examen_aulas(int $examen_id): array {
    global $wpdb;
    $t_ea = $wpdb->prefix . 'conducta_examen_aulas';
    $t_a = $wpdb->prefix . 'conducta_aulas';
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT ea.aula_id, a.nombre AS aula_nombre
       FROM $t_ea ea
       LEFT JOIN $t_a a ON a.id=ea.aula_id
       WHERE ea.examen_id=%d
       ORDER BY a.nombre ASC, ea.aula_id ASC",
      $examen_id
    ), ARRAY_A);
    return $rows ?: [];
  }

  public static function list_examen_materias(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $ex = $wpdb->get_row($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $id), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);
    return self::ok(['items' => self::_get_examen_materias($id)]);
  }

  public static function create_examen(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);
    $p = $req->get_json_params() ?: [];
    $nombre = self::norm_str($p['nombre'] ?? '');
    $tipo = self::norm_str($p['tipo'] ?? 'ingenieria');
    if (!in_array($tipo, ['medicina', 'ingenieria'], true)) $tipo = 'ingenieria';
    if ($tipo === 'medicina') {
      return self::err('Para Medicina use la importación automática desde la pestaña Medicina (importar).');
    }
    $curso_id = self::int_or_null($p['curso_id'] ?? null);
    $grupo_ids = isset($p['grupo_ids']) && is_array($p['grupo_ids']) ? array_values(array_unique(array_map('intval', $p['grupo_ids']))) : [];
    $fecha = self::norm_str($p['fecha'] ?? '');
    $temas = isset($p['temas']) && is_array($p['temas']) ? $p['temas'] : [];
    $total_raw = $p['total_puntos'] ?? null;
    $total_puntos = isset($total_raw) ? (int) $total_raw : 0;
    $valid_temas = [];
    foreach ($temas as $t) {
      $nom = self::norm_str($t['nombre'] ?? '');
      if ($nom === '') {
        continue;
      }
      $puntos_raw = $t['puntos_maximos'] ?? 10;
      $puntos = (int) $puntos_raw;
      if (!is_numeric($puntos_raw) || floor((float) $puntos_raw) !== (float) $puntos_raw || $puntos <= 0) {
        return self::err('Los puntos máximos deben ser enteros positivos.');
      }
      $valid_temas[] = ['nombre' => $nom, 'puntos_maximos' => $puntos];
    }
    if ($nombre === '') return self::err('El nombre del examen es obligatorio.');
    if ($fecha === '') return self::err('La fecha es obligatoria.');
    if ($total_raw !== null && (!is_numeric($total_raw) || floor((float) $total_raw) !== (float) $total_raw)) {
      return self::err('El total del examen debe ser un número entero.');
    }
    if (empty($valid_temas)) {
      if ($total_puntos <= 0) {
        return self::err('Debe indicar el total del examen (mayor que cero).');
      }
      // Ingeniería simplificada: un único tema interno "Total".
      $valid_temas[] = ['nombre' => 'Total', 'puntos_maximos' => $total_puntos];
    }
    if (!empty($valid_temas) && count($valid_temas) > 1) {
      return self::err('Para Ingeniería debe seleccionar una sola materia por examen.');
    }
    $ruleErr = self::validate_ingenieria_grupos_y_materia($grupo_ids, $valid_temas);
    if ($ruleErr !== null) return self::err($ruleErr);

    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $t_ea = $wpdb->prefix . 'conducta_examen_aulas';
    $wpdb->insert($t_ex, [
      'nombre'    => $nombre,
      'tipo'      => $tipo,
      'curso_id'  => $curso_id,
      'fecha'     => $fecha,
      'creado_por'=> $user_id,
      'activo'    => 1,
    ]);
    $examen_id = (int) $wpdb->insert_id;
    if ($examen_id <= 0) return self::err('Error al crear el examen', 500);

    $orden = 0;
    foreach ($valid_temas as $t) {
      $wpdb->insert($t_et, [
        'examen_id'      => $examen_id,
        'orden'          => $orden++,
        'nombre'         => $t['nombre'],
        'puntos_maximos' => $t['puntos_maximos'],
      ]);
    }
    foreach ($grupo_ids as $gid) {
      if ($gid <= 0) continue;
      $wpdb->insert($t_ea, [
        'examen_id' => $examen_id,
        'aula_id' => $gid,
      ]);
    }
    return self::ok(['id' => $examen_id], 201);
  }

  public static function update_examen(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $p = $req->get_json_params() ?: [];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $ex = $wpdb->get_row($wpdb->prepare("SELECT id, tipo FROM $t_ex WHERE id=%d AND activo=1", $id), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);
    $tipo_cur = (string) ($ex['tipo'] ?? 'ingenieria');

    $data = [];
    if (array_key_exists('nombre', $p)) { $data['nombre'] = self::norm_str($p['nombre']); }
    if (array_key_exists('tipo', $p) && in_array($p['tipo'], ['medicina', 'ingenieria'], true)) { $data['tipo'] = $p['tipo']; }
    if (array_key_exists('curso_id', $p)) { $data['curso_id'] = self::int_or_null($p['curso_id']); }
    if (array_key_exists('fecha', $p) && self::norm_str($p['fecha']) !== '') { $data['fecha'] = self::norm_str($p['fecha']); }
    if (!empty($data)) $wpdb->update($t_ex, $data, ['id' => $id]);

    if ($tipo_cur === 'ingenieria' && isset($p['temas']) && is_array($p['temas'])) {
      $t_et = $wpdb->prefix . 'conducta_examen_temas';
      $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
      $valid = [];
      foreach ($p['temas'] as $t) {
        $nom = self::norm_str($t['nombre'] ?? '');
        if ($nom === '') {
          continue;
        }
        $puntos = isset($t['puntos_maximos']) ? round((float) $t['puntos_maximos'], 2) : 10.0;
        $valid[] = ['nombre' => $nom, 'puntos_maximos' => $puntos];
      }
      if (!empty($valid)) {
        $t_em_cl = $wpdb->prefix . 'conducta_examen_materias';
        $wpdb->delete($t_em_cl, ['examen_id' => $id], ['%d']);
        $wpdb->delete($t_ep, ['examen_id' => $id], ['%d']);
        $wpdb->delete($t_et, ['examen_id' => $id], ['%d']);
        $orden = 0;
        foreach ($valid as $t) {
          $wpdb->insert($t_et, [
            'examen_id'      => $id,
            'orden'          => $orden++,
            'nombre'         => $t['nombre'],
            'puntos_maximos' => $t['puntos_maximos'],
          ]);
        }
      }
    }

    $materias = isset($p['materias']) && is_array($p['materias']) ? $p['materias'] : null;
    if ($materias !== null && $tipo_cur === 'medicina') {
      $t_em = $wpdb->prefix . 'conducta_examen_materias';
      $wpdb->delete($t_em, ['examen_id' => $id], ['%d']);
      foreach ($materias as $m) {
        $materia_id = (int)($m['materia_id'] ?? 0);
        $puntos = isset($m['puntos_maximos']) ? round((float)$m['puntos_maximos'], 2) : 10.0;
        if ($materia_id <= 0) continue;
        $wpdb->insert($t_em, ['examen_id' => $id, 'materia_id' => $materia_id, 'puntos_maximos' => $puntos]);
      }
    }
    if (array_key_exists('grupo_ids', $p) && is_array($p['grupo_ids'])) {
      $t_ea = $wpdb->prefix . 'conducta_examen_aulas';
      $wpdb->delete($t_ea, ['examen_id' => $id], ['%d']);
      $gids = array_values(array_unique(array_map('intval', $p['grupo_ids'])));
      foreach ($gids as $gid) {
        if ($gid <= 0) continue;
        $wpdb->insert($t_ea, ['examen_id' => $id, 'aula_id' => $gid]);
      }
    }
    return self::ok(['updated' => true]);
  }

  public static function delete_examen(WP_REST_Request $req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_sh = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_ei = $wpdb->prefix . 'conducta_examen_items';
    $t_ea = $wpdb->prefix . 'conducta_examen_aulas';
    $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d", $id));
    if (!$ex) return self::err('Examen no encontrado', 404);
    $wpdb->delete($t_er, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_sh, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_ei, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_ea, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_ep, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_et, ['examen_id' => $id], ['%d']);
    $wpdb->delete($t_em, ['examen_id' => $id], ['%d']);
    $wpdb->update($t_ex, ['activo' => 0], ['id' => $id], ['%d'], ['%d']);
    return self::ok(['deleted' => true]);
  }

  public static function list_examen_alumnos(WP_REST_Request $req) {
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    $ex = $wpdb->get_row($wpdb->prepare("SELECT id, curso_id FROM $t_ex WHERE id=%d AND activo=1", $examen_id), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);
    $curso_id = (int)($ex['curso_id'] ?? 0);
    $ex_aulas = self::_get_examen_aulas($examen_id);
    $exam_aula_ids = array_values(array_filter(array_map(static function ($r) {
      return (int) ($r['aula_id'] ?? 0);
    }, $ex_aulas)));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $con_respuestas = (int) $req->get_param('con_respuestas') === 1;
    $where = 'a.activo=1';
    $params = [];
    $join_rsp = '';
    if ($con_respuestas) {
      $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
      $join_rsp = " INNER JOIN (SELECT DISTINCT alumno_id FROM $t_er WHERE examen_id=%d) rx ON rx.alumno_id=a.id ";
      $params[] = $examen_id;
    }
    if ($aula_id) { $where .= ' AND a.aula_id=%d'; $params[] = $aula_id; }
    if (!empty($exam_aula_ids)) {
      $where .= ' AND a.aula_id IN (' . implode(',', array_map('intval', $exam_aula_ids)) . ')';
    }
    if ($curso_id > 0) { $where .= ' AND a.curso_id=%d'; $params[] = $curso_id; }
    $sql = "SELECT a.id, a.nombres, a.apellidos, a.ci, a.curso_id, a.aula_id,
                   c.nombre AS curso_nombre, au.nombre AS aula_nombre
            FROM $t_al a
            $join_rsp
            LEFT JOIN $t_cur c ON c.id=a.curso_id
            LEFT JOIN $t_aul au ON au.id=a.aula_id
            WHERE $where ORDER BY a.apellidos ASC, a.nombres ASC";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  public static function list_examen_puntajes(WP_REST_Request $req) {
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . $wpdb->prefix . "conducta_examenes WHERE id=%d AND activo=1", $examen_id));
    if (!$ex) return self::err('Examen no encontrado', 404);
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT p.id, p.alumno_id, p.materia_id, p.tema_id, p.puntaje,
               a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.ci AS alumno_ci,
               COALESCE(m.nombre, et.nombre) AS materia_nombre
       FROM $t_ep p
       LEFT JOIN $t_al a ON a.id=p.alumno_id
       LEFT JOIN $t_mat m ON m.id=p.materia_id
       LEFT JOIN $t_et et ON et.id=p.tema_id AND et.examen_id=p.examen_id
       WHERE p.examen_id=%d ORDER BY a.apellidos, a.nombres, COALESCE(m.nombre, et.nombre)",
      $examen_id
    ), ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  public static function save_examen_puntajes(WP_REST_Request $req) {
    self::ensure_tables();
    if (!self::can_edit_notas()) {
      return self::err('No tiene permisos para modificar puntajes.', 403);
    }
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_eph = $wpdb->prefix . 'conducta_examen_puntajes_historial';
    $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id));
    if (!$ex) return self::err('Examen no encontrado', 404);
    $p = $req->get_json_params() ?: [];
    $puntajes = isset($p['puntajes']) && is_array($p['puntajes']) ? $p['puntajes'] : [];
    $motivo = self::norm_str($p['motivo'] ?? '');
    $canEdit = self::can_edit_notas();
    $uid = get_current_user_id();
    $u = $uid ? get_userdata($uid) : null;
    $ulogin = $u ? (string) ($u->user_login ?? '') : '';
    $uname = $u ? (string) ($u->display_name ?? '') : '';
    $saved = 0;
    $updated = 0;
    $created = 0;
    $blocked = 0;
    $audited = 0;
    $rejected = 0;
    $rejected_reasons = [];
    $max_by_tema = [];
    $max_by_materia = [];
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $tema_rows = $wpdb->get_results($wpdb->prepare("SELECT id, puntos_maximos FROM $t_et WHERE examen_id=%d", $examen_id), ARRAY_A);
    foreach ($tema_rows ?: [] as $tr) {
      $max_by_tema[(int) ($tr['id'] ?? 0)] = (int) round((float) ($tr['puntos_maximos'] ?? 0));
    }
    $mat_rows = $wpdb->get_results($wpdb->prepare("SELECT materia_id, puntos_maximos FROM $t_em WHERE examen_id=%d", $examen_id), ARRAY_A);
    foreach ($mat_rows ?: [] as $mr) {
      $max_by_materia[(int) ($mr['materia_id'] ?? 0)] = (int) round((float) ($mr['puntos_maximos'] ?? 0));
    }
    foreach ($puntajes as $row) {
      $alumno_id = (int) ($row['alumno_id'] ?? 0);
      $materia_id = (int) ($row['materia_id'] ?? 0);
      $tema_id = (int) ($row['tema_id'] ?? 0);
      $puntaje_raw = $row['puntaje'] ?? null;
      $puntaje = isset($puntaje_raw) ? (int) $puntaje_raw : 0;
      if ($alumno_id <= 0) {
        continue;
      }
      if (!is_numeric($puntaje_raw) || floor((float) $puntaje_raw) !== (float) $puntaje_raw) {
        $rejected++;
        $rejected_reasons[] = 'Puntaje inválido (debe ser entero).';
        continue;
      }
      if ($puntaje < 0) {
        $rejected++;
        $rejected_reasons[] = 'Puntaje inválido (no puede ser negativo).';
        continue;
      }
      $maxPermitido = 0;
      if ($tema_id > 0) {
        $maxPermitido = isset($max_by_tema[$tema_id]) ? (int) $max_by_tema[$tema_id] : 0;
      } elseif ($materia_id > 0) {
        $maxPermitido = isset($max_by_materia[$materia_id]) ? (int) $max_by_materia[$materia_id] : 0;
      }
      if ($maxPermitido > 0 && $puntaje > $maxPermitido) {
        $rejected++;
        $rejected_reasons[] = 'Puntaje excede el máximo permitido (' . $maxPermitido . ').';
        continue;
      }
      $prev = null;
      if ($tema_id > 0) {
        $prev = $wpdb->get_row($wpdb->prepare(
          "SELECT id, puntaje FROM $t_ep WHERE examen_id=%d AND alumno_id=%d AND tema_id=%d LIMIT 1",
          $examen_id,
          $alumno_id,
          $tema_id
        ), ARRAY_A);
      } elseif ($materia_id > 0) {
        $prev = $wpdb->get_row($wpdb->prepare(
          "SELECT id, puntaje FROM $t_ep WHERE examen_id=%d AND alumno_id=%d AND materia_id=%d AND tema_id IS NULL LIMIT 1",
          $examen_id,
          $alumno_id,
          $materia_id
        ), ARRAY_A);
      }

      $prevVal = $prev ? round((float) ($prev['puntaje'] ?? 0), 2) : null;
      $isUpdate = $prev && $prevVal !== null;
      $changed = $isUpdate ? (abs($prevVal - $puntaje) > 0.0001) : true;
      if ($isUpdate && $changed && !$canEdit) {
        $blocked++;
        continue;
      }

      if ($tema_id > 0) {
        $wpdb->query($wpdb->prepare(
          "INSERT INTO $t_ep (examen_id, alumno_id, materia_id, tema_id, puntaje) VALUES (%d,%d,NULL,%d,%f)
           ON DUPLICATE KEY UPDATE puntaje=VALUES(puntaje), modified_at=CURRENT_TIMESTAMP",
          $examen_id,
          $alumno_id,
          $tema_id,
          $puntaje
        ));
        $saved++;
        $isUpdate ? $updated++ : $created++;
      } elseif ($materia_id > 0) {
        $wpdb->query($wpdb->prepare(
          "INSERT INTO $t_ep (examen_id, alumno_id, materia_id, tema_id, puntaje) VALUES (%d,%d,%d,NULL,%f)
           ON DUPLICATE KEY UPDATE puntaje=VALUES(puntaje), modified_at=CURRENT_TIMESTAMP",
          $examen_id,
          $alumno_id,
          $materia_id,
          $puntaje
        ));
        $saved++;
        $isUpdate ? $updated++ : $created++;
      }

      if ($isUpdate && $changed && $uid > 0 && $canEdit) {
        $curr = null;
        if ($tema_id > 0) {
          $curr = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $t_ep WHERE examen_id=%d AND alumno_id=%d AND tema_id=%d LIMIT 1",
            $examen_id,
            $alumno_id,
            $tema_id
          ), ARRAY_A);
        } elseif ($materia_id > 0) {
          $curr = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $t_ep WHERE examen_id=%d AND alumno_id=%d AND materia_id=%d AND tema_id IS NULL LIMIT 1",
            $examen_id,
            $alumno_id,
            $materia_id
          ), ARRAY_A);
        }
        $puntajeId = (int) ($curr['id'] ?? ($prev['id'] ?? 0));
        $wpdb->insert($t_eph, [
          'puntaje_id' => $puntajeId > 0 ? $puntajeId : null,
          'examen_id' => $examen_id,
          'alumno_id' => $alumno_id,
          'materia_id' => $materia_id > 0 ? $materia_id : null,
          'tema_id' => $tema_id > 0 ? $tema_id : null,
          'puntaje_anterior' => $prevVal,
          'puntaje_nuevo' => $puntaje,
          'editado_por' => $uid,
          'editado_por_login' => $ulogin !== '' ? $ulogin : null,
          'editado_por_nombre' => $uname !== '' ? $uname : null,
          'motivo' => $motivo !== '' ? $motivo : null,
        ]);
        $audited++;
      }
    }
    return self::ok([
      'saved' => $saved,
      'created' => $created,
      'updated' => $updated,
      'blocked' => $blocked,
      'audited' => $audited,
      'rejected' => $rejected,
      'errors' => array_slice(array_values(array_unique($rejected_reasons)), 0, 10),
      'can_edit_notas' => $canEdit,
    ]);
  }

  public static function list_puntajes(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $t_aul = $wpdb->prefix . 'conducta_aulas';

    $where = 'e.activo=1';
    $params = [];
    $alumno_id = self::int_or_null($req->get_param('alumno_id'));
    $materia_id = self::int_or_null($req->get_param('materia_id'));
    $tema_id = self::int_or_null($req->get_param('tema_id'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $examen_id = self::int_or_null($req->get_param('examen_id'));
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    $tipo_f = self::norm_str($req->get_param('tipo'));
    if ($alumno_id) { $where .= ' AND p.alumno_id=%d'; $params[] = $alumno_id; }
    if ($materia_id) { $where .= ' AND p.materia_id=%d'; $params[] = $materia_id; }
    if ($tema_id) { $where .= ' AND p.tema_id=%d'; $params[] = $tema_id; }
    if ($curso_id) { $where .= ' AND e.curso_id=%d'; $params[] = $curso_id; }
    if ($aula_id) { $where .= ' AND a.aula_id=%d'; $params[] = $aula_id; }
    if ($examen_id) { $where .= ' AND p.examen_id=%d'; $params[] = $examen_id; }
    if ($fecha_desde !== '') { $where .= ' AND e.fecha>=%s'; $params[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $where .= ' AND e.fecha<=%s'; $params[] = $fecha_hasta; }
    if ($tipo_f !== '' && in_array($tipo_f, ['medicina', 'ingenieria'], true)) { $where .= ' AND e.tipo=%s'; $params[] = $tipo_f; }

    $sql = "SELECT p.id, p.examen_id, p.alumno_id, p.materia_id, p.tema_id, p.puntaje,
                   e.nombre AS examen_nombre, e.tipo AS examen_tipo, e.fecha AS examen_fecha, e.curso_id,
                   a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.ci AS alumno_ci, a.aula_id,
                   COALESCE(m.nombre, et.nombre) AS materia_nombre,
                   COALESCE(em.puntos_maximos, et.puntos_maximos) AS puntos_maximos,
                   c.nombre AS curso_nombre,
                   au.nombre AS aula_nombre
            FROM $t_ep p
            INNER JOIN $t_ex e ON e.id=p.examen_id
            LEFT JOIN $t_al a ON a.id=p.alumno_id
            LEFT JOIN $t_mat m ON m.id=p.materia_id
            LEFT JOIN $t_em em ON em.examen_id=p.examen_id AND em.materia_id=p.materia_id
            LEFT JOIN $t_et et ON et.id=p.tema_id AND et.examen_id=p.examen_id
            LEFT JOIN $t_cur c ON c.id=e.curso_id
            LEFT JOIN $t_aul au ON au.id=a.aula_id
            WHERE $where
            ORDER BY e.fecha DESC, a.apellidos, a.nombres, COALESCE(m.nombre, et.nombre)";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return self::ok(['items' => $rows ?: []]);
  }

  /**
   * Cruce backend de asistencia vs puntajes por alumno y materia canónica.
   * Preparado para Reporte General.
   */
  public static function reporte_cruce_asistencia_puntajes(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;

    $alumno_id = self::int_or_null($req->get_param('alumno_id'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    $aula_id = self::int_or_null($req->get_param('aula_id'));
    $fecha_desde = self::norm_str($req->get_param('fecha_desde'));
    $fecha_hasta = self::norm_str($req->get_param('fecha_hasta'));
    $tipo_f = self::norm_str($req->get_param('tipo'));

    // ---- PUNTAJES ----
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $pw = 'e.activo=1';
    $pp = [];
    if ($alumno_id) { $pw .= ' AND p.alumno_id=%d'; $pp[] = $alumno_id; }
    if ($curso_id) { $pw .= ' AND e.curso_id=%d'; $pp[] = $curso_id; }
    if ($aula_id) { $pw .= ' AND a.aula_id=%d'; $pp[] = $aula_id; }
    if ($fecha_desde !== '') { $pw .= ' AND e.fecha>=%s'; $pp[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $pw .= ' AND e.fecha<=%s'; $pp[] = $fecha_hasta; }
    if ($tipo_f !== '' && in_array($tipo_f, ['medicina', 'ingenieria'], true)) { $pw .= ' AND e.tipo=%s'; $pp[] = $tipo_f; }
    $sqlP = "SELECT p.examen_id, p.alumno_id, p.materia_id, p.tema_id, p.puntaje,
                    e.nombre AS examen_nombre, e.tipo AS examen_tipo, e.fecha AS examen_fecha,
                    a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.ci AS alumno_ci, a.aula_id, a.curso_id,
                    COALESCE(m.nombre, et.nombre) AS materia_nombre,
                    COALESCE(em.puntos_maximos, et.puntos_maximos) AS puntos_maximos
             FROM $t_ep p
             INNER JOIN $t_ex e ON e.id=p.examen_id
             LEFT JOIN $t_al a ON a.id=p.alumno_id
             LEFT JOIN $t_mat m ON m.id=p.materia_id
             LEFT JOIN $t_em em ON em.examen_id=p.examen_id AND em.materia_id=p.materia_id
             LEFT JOIN $t_et et ON et.id=p.tema_id AND et.examen_id=p.examen_id
             WHERE $pw";
    $pRows = $pp ? $wpdb->get_results($wpdb->prepare($sqlP, $pp), ARRAY_A) : $wpdb->get_results($sqlP, ARRAY_A);

    // ---- ASISTENCIA ----
    $t_as = $wpdb->prefix . 'conducta_asistencias';
    $t_ai = $wpdb->prefix . 'conducta_asistencia_items';
    $t_amod = $wpdb->prefix . 'conducta_asistencia_modificaciones';
    $aw = 's.activo=1';
    $ap = [];
    if ($alumno_id) { $aw .= ' AND i.alumno_id=%d'; $ap[] = $alumno_id; }
    if ($curso_id) { $aw .= ' AND a.curso_id=%d'; $ap[] = $curso_id; }
    if ($aula_id) { $aw .= ' AND a.aula_id=%d'; $ap[] = $aula_id; }
    if ($fecha_desde !== '') { $aw .= ' AND s.fecha>=%s'; $ap[] = $fecha_desde; }
    if ($fecha_hasta !== '') { $aw .= ' AND s.fecha<=%s'; $ap[] = $fecha_hasta; }
    $sqlA = "SELECT i.alumno_id, s.materia_id, s.fecha,
                    a.nombres AS alumno_nombres, a.apellidos AS alumno_apellidos, a.ci AS alumno_ci, a.aula_id, a.curso_id,
                    m.nombre AS materia_nombre,
                    COUNT(*) AS total_clases,
                    COALESCE(SUM(COALESCE(md.asistio, i.asistio)),0) AS asistidas
             FROM $t_ai i
             INNER JOIN $t_as s ON s.id=i.asistencia_id
             LEFT JOIN $t_amod md ON md.asistencia_id=i.asistencia_id AND md.asistencia_item_id=i.id
             LEFT JOIN $t_al a ON a.id=i.alumno_id
             LEFT JOIN $t_mat m ON m.id=s.materia_id
             WHERE $aw
             GROUP BY i.alumno_id, s.materia_id, m.nombre, a.nombres, a.apellidos, a.ci, a.aula_id, a.curso_id";
    $aRows = $ap ? $wpdb->get_results($wpdb->prepare($sqlA, $ap), ARRAY_A) : $wpdb->get_results($sqlA, ARRAY_A);

    $out = [];
    $keyset = [];
    $mk = static function ($alumno_id, $mat_key) {
      return (string) $alumno_id . '|' . $mat_key;
    };

    foreach ($pRows ?: [] as $r) {
      $matKey = self::cross_materia_key((string) ($r['materia_nombre'] ?? ''));
      if ($matKey === '') continue;
      $k = $mk((int) $r['alumno_id'], $matKey);
      if (!isset($keyset[$k])) {
        $keyset[$k] = [
          'alumno_id' => (int) $r['alumno_id'],
          'alumno_nombres' => (string) ($r['alumno_nombres'] ?? ''),
          'alumno_apellidos' => (string) ($r['alumno_apellidos'] ?? ''),
          'alumno_ci' => (string) ($r['alumno_ci'] ?? ''),
          'curso_id' => (int) ($r['curso_id'] ?? 0),
          'aula_id' => (int) ($r['aula_id'] ?? 0),
          'materia_key' => $matKey,
          'materia_nombre' => (string) ($r['materia_nombre'] ?? $matKey),
          'puntaje_obtenido' => 0.0,
          'puntaje_total' => 0.0,
          'puntaje_porcentaje' => 0.0,
          'clases_total' => 0,
          'clases_asistidas' => 0,
          'asistencia_porcentaje' => 0.0,
          'examenes_count' => 0,
        ];
      }
      $keyset[$k]['puntaje_obtenido'] += (float) ($r['puntaje'] ?? 0);
      $keyset[$k]['puntaje_total'] += (float) ($r['puntos_maximos'] ?? 0);
      $keyset[$k]['examenes_count']++;
    }

    foreach ($aRows ?: [] as $r) {
      $matKey = self::cross_materia_key((string) ($r['materia_nombre'] ?? ''));
      if ($matKey === '') continue;
      $k = $mk((int) $r['alumno_id'], $matKey);
      if (!isset($keyset[$k])) {
        $keyset[$k] = [
          'alumno_id' => (int) $r['alumno_id'],
          'alumno_nombres' => (string) ($r['alumno_nombres'] ?? ''),
          'alumno_apellidos' => (string) ($r['alumno_apellidos'] ?? ''),
          'alumno_ci' => (string) ($r['alumno_ci'] ?? ''),
          'curso_id' => (int) ($r['curso_id'] ?? 0),
          'aula_id' => (int) ($r['aula_id'] ?? 0),
          'materia_key' => $matKey,
          'materia_nombre' => (string) ($r['materia_nombre'] ?? $matKey),
          'puntaje_obtenido' => 0.0,
          'puntaje_total' => 0.0,
          'puntaje_porcentaje' => 0.0,
          'clases_total' => 0,
          'clases_asistidas' => 0,
          'asistencia_porcentaje' => 0.0,
          'examenes_count' => 0,
        ];
      }
      $keyset[$k]['clases_total'] += (int) ($r['total_clases'] ?? 0);
      $keyset[$k]['clases_asistidas'] += (int) ($r['asistidas'] ?? 0);
    }

    foreach ($keyset as $row) {
      $pt = (float) ($row['puntaje_total'] ?? 0);
      $po = (float) ($row['puntaje_obtenido'] ?? 0);
      $ct = (int) ($row['clases_total'] ?? 0);
      $ca = (int) ($row['clases_asistidas'] ?? 0);
      $row['puntaje_obtenido'] = round($po, 2);
      $row['puntaje_total'] = round($pt, 2);
      $row['puntaje_porcentaje'] = $pt > 0 ? round(100 * $po / $pt, 2) : 0.0;
      $row['asistencia_porcentaje'] = $ct > 0 ? round(100 * $ca / $ct, 2) : 0.0;
      $out[] = $row;
    }

    usort($out, static function ($a, $b) {
      $na = trim((string) ($a['alumno_apellidos'] ?? '') . ' ' . (string) ($a['alumno_nombres'] ?? ''));
      $nb = trim((string) ($b['alumno_apellidos'] ?? '') . ' ' . (string) ($b['alumno_nombres'] ?? ''));
      $c = strcmp($na, $nb);
      if ($c !== 0) return $c;
      return strcmp((string) ($a['materia_key'] ?? ''), (string) ($b['materia_key'] ?? ''));
    });

    return self::ok([
      'items' => $out,
      'meta' => [
        'rows' => count($out),
        'alumnos_unicos' => count(array_unique(array_map(static function ($r) { return (int) $r['alumno_id']; }, $out))),
      ],
    ]);
  }

  private static function get_uploaded_tmp_file(WP_REST_Request $req): array {
    $files = $req->get_file_params();
    if (empty($files['file']) || !is_array($files['file'])) {
      return [null, 'No se recibió el archivo (campo file).'];
    }
    $f = $files['file'];
    if (!empty($f['error']) && (int) $f['error'] !== UPLOAD_ERR_OK) {
      return [null, 'Error al subir el archivo.'];
    }
    $tmp = isset($f['tmp_name']) ? (string) $f['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      return [null, 'Archivo temporal inválido.'];
    }
    return [$tmp, null];
  }

  public static function list_examen_items(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_ei = $wpdb->prefix . 'conducta_examen_items';
    $t_m = $wpdb->prefix . 'conducta_materias';
    $ok = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id));
    if (!$ok) return self::err('Examen no encontrado', 404);
    $limit = min(2000, max(1, (int) $req->get_param('limit') ?: 500));
    $offset = max(0, (int) $req->get_param('offset'));
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT i.id, i.orden_canonico, i.materia_id, i.enunciado, i.opcion_a, i.opcion_b, i.opcion_c, i.opcion_d,
              i.respuesta_correcta, i.puntos_item, m.nombre AS materia_nombre
       FROM $t_ei i LEFT JOIN $t_m m ON m.id=i.materia_id
       WHERE i.examen_id=%d
       ORDER BY i.orden_canonico ASC
       LIMIT %d OFFSET %d",
      $examen_id,
      $limit,
      $offset
    ), ARRAY_A);
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_ei WHERE examen_id=%d", $examen_id));
    return self::ok(['items' => $rows ?: [], 'total' => $total]);
  }

  public static function import_examen_items(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $ex = $wpdb->get_row($wpdb->prepare("SELECT id, tipo FROM $t_ex WHERE id=%d AND activo=1", $examen_id), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);

    [$tmp, $err] = self::get_uploaded_tmp_file($req);
    if ($err) return self::err($err);

    $name = isset($_FILES['file']['name']) ? strtolower((string) $_FILES['file']['name']) : '';
    $rows = [];
    try {
      if (preg_match('/\.docx$/i', $name)) {
        $rows = NC_Examenes_Import::parse_items_docx($tmp);
      } else {
        $rows = NC_Examenes_Import::parse_items_xlsx($tmp);
      }
    } catch (Throwable $e) {
      return self::err($e->getMessage());
    }

    if (empty($rows)) {
      return self::err('No se encontraron ítems válidos en el archivo. Revise el formato (tabla Word o Excel con columnas orden, materia_id, enunciado).');
    }
    if (($ex['tipo'] ?? '') === 'medicina') {
      $rows = self::hydrate_rows_for_medicina($rows);
    }
    foreach ($rows as $r) {
      if ((int) ($r['materia_id'] ?? 0) <= 0) {
        return self::err('No se pudo resolver la materia de algunos ítems. Verifique que existan materias activas: Guaraní, Castellano, Estudios paraguayos, Matemática, Física, Química Inorgánica, Química Orgánica, Biología y Anatomía.');
      }
    }
    NC_Examenes_Import::replace_items($examen_id, $rows);
    return self::ok([
      'imported' => count($rows),
      'tipo'     => $ex['tipo'],
    ], 201);
  }

  public static function get_examen_shuffle(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id))) {
      return self::err('Examen no encontrado', 404);
    }
    $t_s = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_i = $wpdb->prefix . 'conducta_examen_items';
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT s.posicion_hoja, s.item_id, i.orden_canonico
       FROM $t_s s INNER JOIN $t_i i ON i.id=s.item_id
       WHERE s.examen_id=%d ORDER BY s.posicion_hoja ASC",
      $examen_id
    ), ARRAY_A);
    return self::ok(['items' => $rows ?: [], 'count' => count($rows ?: [])]);
  }

  public static function import_examen_shuffle(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id))) {
      return self::err('Examen no encontrado', 404);
    }
    [$tmp, $err] = self::get_uploaded_tmp_file($req);
    if ($err) return self::err($err);
    $name = isset($_FILES['file']['name']) ? strtolower((string) $_FILES['file']['name']) : '';
    if (!preg_match('/\.xlsx$/i', $name) && !preg_match('/\.xls$/i', $name)) {
      return self::err('El orden aleatorio debe importarse desde un archivo Excel (.xlsx).');
    }
    try {
      $pairs = NC_Examenes_Import::parse_shuffle_xlsx($tmp);
    } catch (Throwable $e) {
      return self::err($e->getMessage());
    }
    if (empty($pairs)) {
      return self::err('No se leyeron filas de posicion_hoja / orden_canonico.');
    }
    $n_items = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM " . $wpdb->prefix . "conducta_examen_items WHERE examen_id=%d",
      $examen_id
    ));
    if ($n_items <= 0) {
      return self::err('Primero cargue los ítems del examen.');
    }
    NC_Examenes_Import::replace_shuffle($examen_id, $pairs);
    $inserted = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM " . $wpdb->prefix . "conducta_examen_shuffle WHERE examen_id=%d",
      $examen_id
    ));
    $warnings = [];
    if ($inserted !== $n_items) {
      $warnings[] = 'Filas insertadas (' . $inserted . ') no coinciden con cantidad de ítems (' . $n_items . '). Revise orden_canonico y duplicados.';
    }
    return self::ok(['imported' => $inserted, 'warnings' => $warnings], 201);
  }

  public static function import_examen_resultados(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id))) {
      return self::err('Examen no encontrado', 404);
    }
    [$tmp, $err] = self::get_uploaded_tmp_file($req);
    if ($err) return self::err($err);
    $name = isset($_FILES['file']['name']) ? strtolower((string) $_FILES['file']['name']) : '';
    if (!preg_match('/\.xlsx$/i', $name) && !preg_match('/\.xls$/i', $name)) {
      return self::err('Los resultados deben importarse desde Excel (.xlsx): primera columna CI, siguientes respuestas por posición de hoja.');
    }
    try {
      $alumnos_rows = NC_Examenes_Import::parse_resultados_xlsx($tmp);
    } catch (Throwable $e) {
      return self::err($e->getMessage());
    }
    if (empty($alumnos_rows)) {
      return self::err('No se encontraron filas con CI.');
    }
    $out = NC_Examenes_Import::import_resultados($examen_id, $alumnos_rows);
    return self::ok($out, 201);
  }

  public static function import_medicina_pdf_puntajes(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $ex = $wpdb->get_row($wpdb->prepare("SELECT id, tipo FROM $t_ex WHERE id=%d AND activo=1", $examen_id), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);
    if (($ex['tipo'] ?? '') !== 'medicina') return self::err('Esta importación solo aplica a exámenes de Medicina.');

    [$tmp, $err] = self::get_uploaded_tmp_file($req);
    if ($err) return self::err($err);
    $name = isset($_FILES['file']['name']) ? strtolower((string) $_FILES['file']['name']) : '';
    $isPdf = (bool) preg_match('/\.pdf$/i', $name);
    $isXlsx = (bool) preg_match('/\.(xlsx|xls)$/i', $name);
    $isDocx = (bool) preg_match('/\.docx$/i', $name);
    if (!$isPdf && !$isXlsx && !$isDocx) {
      return self::err('El archivo debe ser PDF, Excel (.xlsx/.xls) o Word (.docx).');
    }
    try {
      if ($isPdf) {
        $parsed = NC_Examenes_Import::parse_medicina_pdf_puntajes($tmp);
      } elseif ($isXlsx) {
        $parsed = NC_Examenes_Import::parse_medicina_xlsx_puntajes($tmp);
      } else {
        $parsed = NC_Examenes_Import::parse_medicina_docx_puntajes($tmp);
      }
    } catch (Throwable $e) {
      return self::err($e->getMessage());
    }
    $cols = isset($parsed['columnas']) && is_array($parsed['columnas']) ? $parsed['columnas'] : [];
    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : [];
    $maxDet = isset($parsed['maximos_detectados']) && is_array($parsed['maximos_detectados']) ? $parsed['maximos_detectados'] : [];
    $estParseo = isset($parsed['estadisticas_parseo']) && is_array($parsed['estadisticas_parseo']) ? $parsed['estadisticas_parseo'] : [];
    if (empty($cols) || empty($rows)) return self::err('No se encontraron datos válidos en el archivo.');

    $codeToMateria = [
      'GUA' => 'Guaraní',
      'CAS' => 'Castellano',
      'EST' => 'Estudios paraguayos',
      'MAT' => 'Matemática',
      'FIS' => 'Física',
      'INO' => 'Química Inorgánica',
      'ORG' => 'Química Orgánica',
      'BIO' => 'Biología',
      'ANA' => 'Anatomía',
    ];
    $idsMap = self::medicina_materia_ids_map();
    $defaultMax = ['GUA'=>10,'CAS'=>10,'EST'=>10,'MAT'=>15,'FIS'=>15,'INO'=>10,'ORG'=>10,'BIO'=>10,'ANA'=>10];
    $materiaIds = [];
    foreach ($cols as $c) {
      $label = isset($codeToMateria[$c]) ? $codeToMateria[$c] : '';
      $mid = ($label !== '' && isset($idsMap[$label])) ? (int) $idsMap[$label] : 0;
      if ($mid <= 0) {
        return self::err('No se pudo mapear la columna ' . $c . ' a una materia activa.');
      }
      $materiaIds[$c] = $mid;
    }

    $existsRows = $wpdb->get_results($wpdb->prepare("SELECT materia_id, puntos_maximos FROM $t_em WHERE examen_id=%d", $examen_id), ARRAY_A);
    $existsByMateria = [];
    foreach ($existsRows ?: [] as $r) {
      $existsByMateria[(int)$r['materia_id']] = (float)($r['puntos_maximos'] ?? 0);
    }
    foreach ($cols as $c) {
      $mid = (int) $materiaIds[$c];
      $max = isset($existsByMateria[$mid]) && $existsByMateria[$mid] > 0
        ? $existsByMateria[$mid]
        : (isset($defaultMax[$c]) ? (float) $defaultMax[$c] : (float) ($maxDet[$c] ?? 0));
      if ($max <= 0) $max = (float) ($maxDet[$c] ?? 10);
      $wpdb->query($wpdb->prepare(
        "INSERT INTO $t_em (examen_id, materia_id, puntos_maximos) VALUES (%d,%d,%f)
         ON DUPLICATE KEY UPDATE puntos_maximos=VALUES(puntos_maximos)",
        $examen_id,
        $mid,
        round($max, 2)
      ));
    }

    $wpdb->delete($t_ep, ['examen_id' => $examen_id], ['%d']);
    $imported = 0;
    $skipped = 0;
    $errors = [];
    foreach ($rows as $r) {
      $ci = isset($r['ci']) ? (string) $r['ci'] : '';
      $alumno_id = NC_Examenes_Import::find_alumno_id_by_ci($ci);
      if (!$alumno_id) {
        $skipped++;
        $errors[] = 'CI sin alumno: ' . $ci;
        continue;
      }
      $puntajes = isset($r['puntajes']) && is_array($r['puntajes']) ? $r['puntajes'] : [];
      foreach ($cols as $c) {
        $mid = (int) $materiaIds[$c];
        $p = isset($puntajes[$c]) ? round((float) $puntajes[$c], 2) : 0.0;
        $wpdb->query($wpdb->prepare(
          "INSERT INTO $t_ep (examen_id, alumno_id, materia_id, tema_id, puntaje) VALUES (%d,%d,%d,NULL,%f)
           ON DUPLICATE KEY UPDATE puntaje=VALUES(puntaje), modified_at=CURRENT_TIMESTAMP",
          $examen_id,
          (int) $alumno_id,
          $mid,
          $p
        ));
      }
      $imported++;
    }
    return self::ok([
      'imported' => $imported,
      'skipped' => $skipped,
      'errors' => array_slice(array_unique($errors), 0, 80),
      'materias' => count($cols),
      'pdf_parseo' => $estParseo,
      'parseo' => $estParseo,
    ], 201);
  }

  public static function get_examen_resumen(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $alumno_f = self::int_or_null($req->get_param('alumno_id'));
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $ex = $wpdb->get_row($wpdb->prepare(
      "SELECT id, nombre, tipo, fecha, curso_id FROM $t_ex WHERE id=%d AND activo=1",
      $examen_id
    ), ARRAY_A);
    if (!$ex) return self::err('Examen no encontrado', 404);

    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    $t_mat = $wpdb->prefix . 'conducta_materias';
    $t_em = $wpdb->prefix . 'conducta_examen_materias';
    $t_et = $wpdb->prefix . 'conducta_examen_temas';

    $where = 'p.examen_id=%d';
    $params = [$examen_id];
    if ($alumno_f) {
      $where .= ' AND p.alumno_id=%d';
      $params[] = $alumno_f;
    }
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT p.alumno_id, p.materia_id, p.tema_id, p.puntaje,
              a.nombres, a.apellidos, a.ci,
              COALESCE(m.nombre, et.nombre) AS materia_nombre,
              COALESCE(em.puntos_maximos, et.puntos_maximos) AS puntos_maximos
       FROM $t_ep p
       LEFT JOIN $t_al a ON a.id=p.alumno_id
       LEFT JOIN $t_mat m ON m.id=p.materia_id
       LEFT JOIN $t_em em ON em.examen_id=p.examen_id AND em.materia_id=p.materia_id
       LEFT JOIN $t_et et ON et.id=p.tema_id AND et.examen_id=p.examen_id
       WHERE $where
       ORDER BY a.apellidos, a.nombres, COALESCE(m.nombre, et.nombre)",
      $params
    ), ARRAY_A);

    $by_alumno = [];
    foreach ($rows ?: [] as $r) {
      $aid = (int) $r['alumno_id'];
      if (!isset($by_alumno[$aid])) {
        $by_alumno[$aid] = [
          'alumno_id'  => $aid,
          'nombres'    => $r['nombres'],
          'apellidos'  => $r['apellidos'],
          'ci'         => $r['ci'],
          'materias'   => [],
          'total'      => 0.0,
          'max_total'  => 0.0,
        ];
      }
      $max = (float) ($r['puntos_maximos'] ?? 0);
      $p = (float) ($r['puntaje'] ?? 0);
      $mid = isset($r['materia_id']) && $r['materia_id'] !== null ? (int) $r['materia_id'] : 0;
      $tid = isset($r['tema_id']) && $r['tema_id'] !== null ? (int) $r['tema_id'] : 0;
      $by_alumno[$aid]['materias'][] = [
        'materia_id'      => $mid > 0 ? $mid : null,
        'tema_id'         => $tid > 0 ? $tid : null,
        'materia_nombre'  => $r['materia_nombre'],
        'puntaje'         => $p,
        'puntos_maximos'  => $max,
        'porcentaje'      => $max > 0 ? round(($p / $max) * 100, 1) : null,
      ];
      $by_alumno[$aid]['total'] += $p;
      $by_alumno[$aid]['max_total'] += $max;
    }
    foreach ($by_alumno as &$b) {
      $b['porcentaje_total'] = $b['max_total'] > 0 ? round(($b['total'] / $b['max_total']) * 100, 1) : null;
    }
    unset($b);

    return self::ok([
      'examen'  => $ex,
      'alumnos' => array_values($by_alumno),
    ]);
  }

  public static function get_examen_detalle(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $examen_id = (int) $req['id'];
    $alumno_id = self::int_or_null($req->get_param('alumno_id'));
    if (!$alumno_id) {
      return self::err('Parámetro alumno_id obligatorio.');
    }
    $t_ex = $wpdb->prefix . 'conducta_examenes';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_ex WHERE id=%d AND activo=1", $examen_id))) {
      return self::err('Examen no encontrado', 404);
    }
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_i = $wpdb->prefix . 'conducta_examen_items';
    $t_m = $wpdb->prefix . 'conducta_materias';
    $t_sh = $wpdb->prefix . 'conducta_examen_shuffle';

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT r.respuesta_alumno, r.es_correcta, r.puntos_obtenidos,
              i.orden_canonico, i.enunciado, i.opcion_a, i.opcion_b, i.opcion_c, i.opcion_d,
              i.respuesta_correcta, i.puntos_item, i.materia_id,
              m.nombre AS materia_nombre,
              s.posicion_hoja
       FROM $t_er r
       INNER JOIN $t_i i ON i.id=r.item_id
       LEFT JOIN $t_m m ON m.id=i.materia_id
       LEFT JOIN $t_sh s ON s.examen_id=r.examen_id AND s.item_id=r.item_id
       WHERE r.examen_id=%d AND r.alumno_id=%d
       ORDER BY (s.posicion_hoja IS NULL), s.posicion_hoja ASC, i.orden_canonico ASC",
      $examen_id,
      $alumno_id
    ), ARRAY_A);

    return self::ok(['items' => $rows ?: []]);
  }

  public static function create_medicina_from_import(WP_REST_Request $req) {
    self::ensure_tables();
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) return self::err('No autorizado', 401);

    $nombre = self::norm_str($req->get_param('nombre'));
    $fecha = self::norm_str($req->get_param('fecha'));
    $curso_id = self::int_or_null($req->get_param('curso_id'));
    if ($nombre === '' || $fecha === '') {
      return self::err('Debe enviar nombre y fecha del examen.');
    }

    $t_ex = $wpdb->prefix . 'conducta_examenes';
    $wpdb->insert($t_ex, [
      'nombre' => $nombre,
      'tipo' => 'medicina',
      'curso_id' => $curso_id,
      'fecha' => $fecha,
      'creado_por' => $user_id,
      'activo' => 1,
    ]);
    $examen_id = (int) $wpdb->insert_id;
    if ($examen_id <= 0) return self::err('No se pudo crear el examen', 500);
    return self::ok(['id' => $examen_id], 201);
  }
}
