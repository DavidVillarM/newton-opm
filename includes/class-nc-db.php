<?php
if (!defined('ABSPATH')) exit;

class NC_DB {

  /**
   * Versión del esquema (no del plugin). Incrementar cuando se agreguen tablas/columnas.
   */
  public static function schema_version(): string {
    return '1.0.7';
  }

  /**
   * Corre dbDelta si el esquema cambió (sin desactivar/activar el plugin).
   */
  public static function maybe_upgrade() {
    // Siempre asegurar que foto_url sea TEXT (por compatibilidad con instalaciones viejas)
    // Nota: estas rutinas autogestionan el nombre real de la tabla.
    self::force_alumnos_foto_url_text();
    self::normalize_alumnos_foto_url_values();

    $stored = get_option('nc_schema_version', '0.0.0');
    if (version_compare($stored, self::schema_version(), '>=')) {
      return;
    }
    self::ensure_schema_updates();
    self::migrate_conducta_column_names();
    self::migrate_aula_to_grupo_columns();
    self::migrate_alumnos_legacy();
    update_option('nc_schema_version', self::schema_version());
  }

  public static function activate() {
    self::ensure_schema_updates();
    self::migrate_conducta_column_names();
    self::migrate_aula_to_grupo_columns();
    self::migrate_alumnos_legacy();
    update_option('nc_schema_version', self::schema_version());
  }

  /**
   * Crea / actualiza el esquema. Es seguro ejecutarlo múltiples veces.
   */
  public static function ensure_schema_updates() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $t_fac   = $wpdb->prefix . 'conducta_facultades';
    $t_car   = $wpdb->prefix . 'conducta_carreras';
    $t_cur   = $wpdb->prefix . 'conducta_cursos';
    $t_aul   = $wpdb->prefix . 'conducta_aulas';
    $t_al    = $wpdb->prefix . 'conducta_alumnos';
    $t_al_c  = $wpdb->prefix . 'conducta_alumno_cursos';
    $t_al_a  = $wpdb->prefix . 'conducta_alumno_aulas';
    $t_eval  = $wpdb->prefix . 'conducta_evaluaciones';

    // 1) Facultades
    $sql = "CREATE TABLE $t_fac (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(191) NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY nombre (nombre)
    ) $charset;";
    dbDelta($sql);

    // ---- Fixes de compatibilidad (instalaciones anteriores) ----
    // 1) `foto_url` pudo quedar como INT/TINYINT; forzamos a TEXT.
    // 2) Normalizamos valores "0" (sin foto) a NULL.
    self::force_alumnos_foto_url_text();
    self::normalize_alumnos_foto_url_values();

    // 2) Carreras (dentro de facultad)
    $sql = "CREATE TABLE $t_car (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      facultad_id BIGINT UNSIGNED NOT NULL,
      nombre VARCHAR(191) NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY facultad_id (facultad_id),
      KEY nombre (nombre)
    ) $charset;";
    dbDelta($sql);

    // 3) Cursos
    $sql = "CREATE TABLE $t_cur (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(191) NOT NULL,
      facultad_id BIGINT UNSIGNED NULL,
      carrera_id BIGINT UNSIGNED NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY nombre (nombre),
      KEY facultad_id (facultad_id),
      KEY carrera_id (carrera_id)
    ) $charset;";
    dbDelta($sql);
    self::ensure_cursos_activo_column();

    // 4) Aulas (subgrupos: ej. "1,2,3" o "A,B,C" cuando el aula usa subgrupos para lista)
    $sql = "CREATE TABLE $t_aul (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(191) NOT NULL,
      curso_id BIGINT UNSIGNED NULL,
      facultad_id BIGINT UNSIGNED NULL,
      carrera_id BIGINT UNSIGNED NULL,
      turno VARCHAR(50) NULL,
      subgrupos VARCHAR(100) NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY nombre (nombre),
      KEY curso_id (curso_id),
      KEY facultad_id (facultad_id),
      KEY carrera_id (carrera_id)
    ) $charset;";
    dbDelta($sql);
    self::ensure_aulas_subgrupos_column();

    // 4b) Configuración de subgrupos por curso o por carrera (para uso dinámico en asistencias)
    $t_sg = $wpdb->prefix . 'conducta_subgrupos_config';
    $sql = "CREATE TABLE $t_sg (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      tipo VARCHAR(20) NOT NULL DEFAULT 'curso',
      curso_id BIGINT UNSIGNED NULL,
      carrera_id BIGINT UNSIGNED NULL,
      subgrupos VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY tipo (tipo),
      KEY curso_id (curso_id),
      KEY carrera_id (carrera_id)
    ) $charset;";
    dbDelta($sql);

    // 5) Alumnos (subgrupo: ej. "1","2","3" para Informática; "A","B","C" para otras ingenierías)
    // Compat: dejamos columnas antiguas nombre/apellido/facultad/carrera si existieran.
    // foto_url: TEXT NULL para poder guardar URLs largas y permitir NULL cuando no hay foto.
    $sql = "CREATE TABLE $t_al (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombres VARCHAR(191) NOT NULL,
      apellidos VARCHAR(191) NOT NULL,
      ci VARCHAR(30) NOT NULL,
      curso_id BIGINT UNSIGNED NULL,
      grupo_id BIGINT UNSIGNED NULL,
      aula_id BIGINT UNSIGNED NULL,
      facultad_id BIGINT UNSIGNED NULL,
      carrera_id BIGINT UNSIGNED NULL,
      subgrupo VARCHAR(20) NULL,
      foto_url TEXT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY ci (ci),
      KEY nombres (nombres),
      KEY apellidos (apellidos),
      KEY curso_id (curso_id),
      KEY grupo_id (grupo_id),
      KEY aula_id (aula_id),
      KEY facultad_id (facultad_id),
      KEY carrera_id (carrera_id),
      KEY subgrupo (subgrupo)
    ) $charset;";
    dbDelta($sql);
    self::ensure_alumnos_subgrupo_column();

    // 5b) Relación alumno <-> cursos (permite multi-curso)
    $sql = "CREATE TABLE $t_al_c (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      alumno_id BIGINT UNSIGNED NOT NULL,
      curso_id BIGINT UNSIGNED NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY alumno_curso (alumno_id, curso_id),
      KEY alumno_id (alumno_id),
      KEY curso_id (curso_id)
    ) $charset;";
    dbDelta($sql);

    // 5c) Relación alumno <-> aulas/grupos (permite multi-grupo)
    $sql = "CREATE TABLE $t_al_a (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      alumno_id BIGINT UNSIGNED NOT NULL,
      aula_id BIGINT UNSIGNED NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY alumno_aula (alumno_id, aula_id),
      KEY alumno_id (alumno_id),
      KEY aula_id (aula_id)
    ) $charset;";
    dbDelta($sql);

    // Backfill: si existen valores legacy en conducta_alumnos (una sola pertenencia),
    // duplicamos esos valores a las tablas de relación (insert ignore para no duplicar).
    $wpdb->query(
      "INSERT IGNORE INTO $t_al_c (alumno_id, curso_id)
       SELECT id, curso_id FROM $t_al
       WHERE activo=1 AND curso_id IS NOT NULL AND curso_id<>0"
    );
    $wpdb->query(
      "INSERT IGNORE INTO $t_al_a (alumno_id, aula_id)
       SELECT id, aula_id FROM $t_al
       WHERE activo=1 AND aula_id IS NOT NULL AND aula_id<>0"
    );

    // 6) Evaluaciones (una fila por alumno/fecha) — esquema legacy
    $sql = "CREATE TABLE $t_eval (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      alumno_id BIGINT UNSIGNED NOT NULL,
      curso_id BIGINT UNSIGNED NULL,
      grupo_id BIGINT UNSIGNED NULL,
      aula_id BIGINT UNSIGNED NULL,
      fecha DATE NOT NULL,
      evaluador_user_id BIGINT UNSIGNED NOT NULL,
      c1 TINYINT UNSIGNED NOT NULL,
      c2 TINYINT UNSIGNED NOT NULL,
      c3 TINYINT UNSIGNED NOT NULL,
      c4 TINYINT UNSIGNED NOT NULL,
      c5 TINYINT UNSIGNED NOT NULL,
      c6 TINYINT UNSIGNED NOT NULL,
      observacion TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY alumno_id (alumno_id),
      KEY curso_id (curso_id),
      KEY grupo_id (grupo_id),
      KEY aula_id (aula_id),
      KEY fecha (fecha)
    ) $charset;";
    dbDelta($sql);

    // 7) Cabecera de evaluaciones (individual/grupal) — nuevo esquema
    $t_hdr = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    $sql = "CREATE TABLE $t_hdr (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      fecha DATE NOT NULL,
      curso_id BIGINT UNSIGNED NULL,
      grupo_id BIGINT UNSIGNED NULL,
      aula_id BIGINT UNSIGNED NULL,
      observacion_general TEXT NULL,
      observacion TEXT NULL,
      evaluador_user_id BIGINT UNSIGNED NOT NULL,
      creado_por BIGINT UNSIGNED NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY fecha (fecha),
      KEY grupo_id (grupo_id),
      KEY aula_id (aula_id),
      KEY curso_id (curso_id)
    ) $charset;";
    dbDelta($sql);

    // 8) Items de evaluación por alumno (dimensiones de conducta)
    $t_items = $wpdb->prefix . 'conducta_evaluaciones_items';
    $sql = "CREATE TABLE $t_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      evaluacion_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      responsabilidad_academica TINYINT UNSIGNED NOT NULL DEFAULT 0,
      respeto_convivencia TINYINT UNSIGNED NOT NULL DEFAULT 0,
      participacion_actitud TINYINT UNSIGNED NOT NULL DEFAULT 0,
      autocontrol_disciplina TINYINT UNSIGNED NOT NULL DEFAULT 0,
      autonomia_compromiso TINYINT UNSIGNED NOT NULL DEFAULT 0,
      presentacion_orden TINYINT UNSIGNED NOT NULL DEFAULT 0,
      observacion TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY evaluacion_id (evaluacion_id),
      KEY alumno_id (alumno_id)
    ) $charset;";
    dbDelta($sql);

    // ---- Backfill/compatibilidad mínima ----
    // Si existe una columna antigua `nombre` y/o `apellido` (por versiones previas),
    // no la borramos. Simplemente nos aseguramos de rellenarla al crear/editar desde REST.
  }

  /**
   * Fuerza `foto_url` a TEXT NULL si la columna existe (numérico → TEXT, NOT NULL → NULL).
   */
  private static function force_alumnos_foto_url_text() {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';

    $col = $wpdb->get_row($wpdb->prepare(
      "SELECT DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'foto_url'",
      $t_al
    ), ARRAY_A);

    if (!$col) return;
    $type = strtolower((string)($col['DATA_TYPE'] ?? ''));
    $nullable = strtoupper((string)($col['IS_NULLABLE'] ?? 'NO'));

    $needs_change = false;
    if (!in_array($type, ['text','tinytext','mediumtext','longtext','varchar','char'], true)) {
      $needs_change = true;
    }
    if ($nullable === 'NO') {
      $needs_change = true;
    }
    if ($needs_change) {
      $wpdb->query("ALTER TABLE $t_al MODIFY foto_url TEXT NULL");
    }
  }

  /**
   * Normaliza el valor "0" (o 0) a NULL para que el front interprete SIN FOTO.
   */
  private static function normalize_alumnos_foto_url_values() {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    // "0" suele venir de columnas numéricas o de payloads antiguos.
    $wpdb->query("UPDATE $t_al SET foto_url = NULL WHERE foto_url = '0'");
  }

  /**
   * Añade/migra columnas de grupo (grupo_id) desde aula_id en las tablas que usaban aula_id.
   */
  private static function migrate_aula_to_grupo_columns() {
    global $wpdb;

    // 1) conducta_alumnos: agregar grupo_id y copiar desde aula_id
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    if (self::table_has_column($t_al, 'aula_id') && !self::table_has_column($t_al, 'grupo_id')) {
      $wpdb->query("ALTER TABLE `$t_al` ADD COLUMN grupo_id BIGINT UNSIGNED NULL AFTER curso_id, ADD KEY grupo_id (grupo_id)");
      $wpdb->query("UPDATE `$t_al` SET grupo_id = aula_id WHERE grupo_id IS NULL AND aula_id IS NOT NULL");
    }

    // 2) conducta_evaluaciones (legacy)
    $t_eval = $wpdb->prefix . 'conducta_evaluaciones';
    if (self::table_has_column($t_eval, 'aula_id') && !self::table_has_column($t_eval, 'grupo_id')) {
      $wpdb->query("ALTER TABLE `$t_eval` ADD COLUMN grupo_id BIGINT UNSIGNED NULL AFTER curso_id, ADD KEY grupo_id (grupo_id)");
      $wpdb->query("UPDATE `$t_eval` SET grupo_id = aula_id WHERE grupo_id IS NULL AND aula_id IS NOT NULL");
    }

    // 3) conducta_evaluaciones_hdr (nuevo esquema)
    $t_hdr = $wpdb->prefix . 'conducta_evaluaciones_hdr';
    if (self::table_has_column($t_hdr, 'aula_id') && !self::table_has_column($t_hdr, 'grupo_id')) {
      $wpdb->query("ALTER TABLE `$t_hdr` ADD COLUMN grupo_id BIGINT UNSIGNED NULL AFTER curso_id, ADD KEY grupo_id (grupo_id)");
      $wpdb->query("UPDATE `$t_hdr` SET grupo_id = aula_id WHERE grupo_id IS NULL AND aula_id IS NOT NULL");
    }
  }

  /**
   * Renombra columnas de conducta en conducta_evaluaciones_items al nuevo esquema de dimensiones.
   */
  private static function migrate_conducta_column_names() {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_evaluaciones_items';
    $renames = [
      'habla_molestando' => 'responsabilidad_academica',
      'irrespetuoso' => 'respeto_convivencia',
      'musica' => 'participacion_actitud',
      'vapea_fuma' => 'autocontrol_disciplina',
      'dania_muebles' => 'autonomia_compromiso',
      'ropa_inadecuada' => 'presentacion_orden',
    ];
    foreach ($renames as $old => $new) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $t, $old
      ));
      if ($exists) {
        $wpdb->query("ALTER TABLE `$t` CHANGE COLUMN `$old` `$new` TINYINT UNSIGNED NOT NULL DEFAULT 0");
      }
    }
  }

  /** Comprueba si una tabla tiene una columna (NC_DB no usa NC_Rest::table_has_column). */
  private static function table_has_column($table, $column) {
    global $wpdb;
    $r = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
      $table,
      $column
    ));
    return (int) $r > 0;
  }

  /** Añade columna subgrupo a conducta_alumnos si no existe (para grupos 1,2,3 / A,B,C). */
  private static function ensure_alumnos_subgrupo_column() {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';
    if (self::table_has_column($t_al, 'subgrupo')) return;
    $wpdb->query("ALTER TABLE `$t_al` ADD COLUMN subgrupo VARCHAR(20) NULL AFTER carrera_id, ADD KEY subgrupo (subgrupo)");
  }

  /** Añade columna subgrupos a conducta_aulas si no existe (ej. "1,2,3" o "A,B,C"). */
  private static function ensure_aulas_subgrupos_column() {
    global $wpdb;
    $t_aul = $wpdb->prefix . 'conducta_aulas';
    if (self::table_has_column($t_aul, 'subgrupos')) return;
    $wpdb->query("ALTER TABLE `$t_aul` ADD COLUMN subgrupos VARCHAR(100) NULL AFTER turno");
  }

  /** Añade columna activo a conducta_cursos si no existe (para toggle activo/inactivo). */
  private static function ensure_cursos_activo_column() {
    global $wpdb;
    $t_cur = $wpdb->prefix . 'conducta_cursos';
    if (self::table_has_column($t_cur, 'activo')) return;
    $wpdb->query("ALTER TABLE `$t_cur` ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER carrera_id");
  }

  /**
   * Migración desde versiones anteriores (tabla alumnos con columna `nombre` / `apellido`).
   * - Si `nombres` o `apellidos` están vacíos, intenta rellenar usando `nombre`/`apellido`
   *   o, en su defecto, haciendo split del nombre completo.
   */
  private static function migrate_alumnos_legacy() {
    global $wpdb;
    $t_al = $wpdb->prefix . 'conducta_alumnos';

    // ¿Existe columna legacy `nombre`?
    $has_nombre = (bool) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'nombre'",
      $t_al
    ));
    $has_apellido = (bool) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'apellido'",
      $t_al
    ));

    // Backfill directo desde columnas legacy.
    if ($has_nombre) {
      $wpdb->query("UPDATE $t_al SET nombres = COALESCE(NULLIF(nombres,''), NULLIF(nombre,''), '') WHERE nombres = '' OR nombres IS NULL");
    }
    if ($has_apellido) {
      $wpdb->query("UPDATE $t_al SET apellidos = COALESCE(NULLIF(apellidos,''), NULLIF(apellido,''), '') WHERE apellidos = '' OR apellidos IS NULL");
    }

    // Split del campo `nombres` si apellidos sigue vacío.
    $rows = $wpdb->get_results("SELECT id, nombres, apellidos FROM $t_al WHERE (apellidos = '' OR apellidos IS NULL) AND (nombres <> '' AND nombres IS NOT NULL) LIMIT 5000");
    foreach ($rows as $r) {
      $full = trim((string)$r->nombres);
      if ($full === '') continue;
      $parts = preg_split('/\s+/', $full);
      if (!$parts || count($parts) < 2) continue;
      $apellido = array_pop($parts);
      $nombre = implode(' ', $parts);
      $wpdb->update($t_al, ['nombres' => $nombre, 'apellidos' => $apellido], ['id' => (int)$r->id]);
    }
  }
}
