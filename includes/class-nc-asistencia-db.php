<?php
if (!defined('ABSPATH')) exit;

/**
 * Esquema de base de datos para el módulo de Asistencia.
 * Tablas: materias, materia_docentes, asistencias, asistencia_items, asistencia_modificaciones.
 * Las ediciones desde "Editar asistencia" se guardan en wp_conducta_asistencia_modificaciones
 * y se aplican al leer (ítems y totales de presentes).
 */
class NC_Asistencia_DB {

  /** Versión del esquema de asistencia (incrementar al agregar tablas/columnas). */
  public static function schema_version(): string {
    return '1.2.1';
  }

  /**
   * Ejecuta ensure_schema solo cuando cambió la versión (evita dbDelta en cada request).
   */
  public static function maybe_upgrade(): void {
    if (!function_exists('get_option')) {
      return;
    }
    $stored = (string) get_option('nc_asistencia_schema_version', '0.0.0');
    if (version_compare($stored, self::schema_version(), '>=')) {
      return;
    }
    self::ensure_schema();
    update_option('nc_asistencia_schema_version', self::schema_version(), false);
  }

  /**
   * Crea o actualiza las tablas del módulo de asistencia.
   */
  public static function ensure_schema() {
    global $wpdb;
    if (!$wpdb instanceof wpdb) {
      return;
    }
    if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
      return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $t_mat   = $wpdb->prefix . 'conducta_materias';
    $t_mat_d = $wpdb->prefix . 'conducta_materia_docentes';
    $t_asis  = $wpdb->prefix . 'conducta_asistencias';
    $t_items = $wpdb->prefix . 'conducta_asistencia_items';
    $t_mod   = $wpdb->prefix . 'conducta_asistencia_modificaciones';

    // 1) Materias (nombre de la materia, ej. Álgebra, Física)
    $sql = "CREATE TABLE $t_mat (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(191) NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY nombre (nombre)
    ) $charset;";
    dbDelta($sql);

    // 2) Docentes por materia (N:M)
    $sql = "CREATE TABLE $t_mat_d (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      materia_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY materia_user (materia_id, user_id),
      KEY materia_id (materia_id),
      KEY user_id (user_id)
    ) $charset;";
    dbDelta($sql);

    // 2b) Inscripción alumno-materia (CASS: alumno inscrito en Análisis 2, Análisis Vectorial o ambas)
    $t_am = $wpdb->prefix . 'conducta_alumno_materias';
    $sql = "CREATE TABLE $t_am (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      alumno_id BIGINT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY alumno_materia (alumno_id, materia_id),
      KEY alumno_id (alumno_id),
      KEY materia_id (materia_id)
    ) $charset;";
    dbDelta($sql);

    // 2c) Materias por curso (ej. CPA Ingeniería → Trigonometría, Álgebra, etc.)
    $t_cm = $wpdb->prefix . 'conducta_curso_materias';
    $sql = "CREATE TABLE $t_cm (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      curso_id BIGINT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY curso_materia (curso_id, materia_id),
      KEY curso_id (curso_id),
      KEY materia_id (materia_id)
    ) $charset;";
    dbDelta($sql);

    // 3) Cabecera de asistencia (una por "tomar lista": fecha + materia + grupo/aula [+ subgrupo opcional])
    $sql = "CREATE TABLE $t_asis (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      fecha DATE NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      grupo_id BIGINT UNSIGNED NULL,
      aula_id BIGINT UNSIGNED NULL,
      curso_id BIGINT UNSIGNED NULL,
      subgrupo VARCHAR(20) NULL,
      simulacro_id BIGINT UNSIGNED NULL,
      creado_por BIGINT UNSIGNED NOT NULL,
      docente_encargado_id BIGINT UNSIGNED NULL,
      modificado_por BIGINT UNSIGNED NULL,
      observacion_general TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (id),
      KEY fecha (fecha),
      KEY materia_id (materia_id),
      KEY grupo_id (grupo_id),
      KEY aula_id (aula_id),
      KEY curso_id (curso_id),
      KEY subgrupo (subgrupo),
      KEY simulacro_id (simulacro_id),
      KEY creado_por (creado_por)
    ) $charset;";
    dbDelta($sql);
    self::ensure_asistencias_subgrupo_column();
    self::ensure_asistencias_grupo_id_column();
    self::ensure_asistencias_simulacro_id_column();

    // 4) Items de asistencia (un registro por alumno en esa lista)
    $sql = "CREATE TABLE $t_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      asistencia_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      asistio TINYINT(1) NOT NULL DEFAULT 1,
      observacion TEXT NULL,
      modified_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY asistencia_alumno (asistencia_id, alumno_id),
      KEY asistencia_id (asistencia_id),
      KEY alumno_id (alumno_id)
    ) $charset;";
    dbDelta($sql);

    // 5) Modificaciones manuales a la lista (editar asistencia)
    $sql = "CREATE TABLE $t_mod (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      asistencia_id BIGINT UNSIGNED NOT NULL,
      asistencia_item_id BIGINT UNSIGNED NOT NULL,
      asistio TINYINT(1) NOT NULL DEFAULT 1,
      observacion TEXT NULL,
      modificado_por BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY asistencia_item (asistencia_id, asistencia_item_id),
      KEY asistencia_id (asistencia_id)
    ) $charset;";
    dbDelta($sql);

    // 6) Simulacros (listas personalizadas para exámenes)
    $t_sim = $wpdb->prefix . 'conducta_simulacros';
    $sql = "CREATE TABLE $t_sim (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(191) NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      aula_id BIGINT UNSIGNED NOT NULL,
      docente_encargado_id BIGINT UNSIGNED NOT NULL,
      creado_por BIGINT UNSIGNED NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY materia_id (materia_id),
      KEY aula_id (aula_id),
      KEY docente_encargado_id (docente_encargado_id),
      KEY activo (activo)
    ) $charset;";
    dbDelta($sql);

    $t_sim_al = $wpdb->prefix . 'conducta_simulacro_alumnos';
    $sql = "CREATE TABLE $t_sim_al (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      simulacro_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY simulacro_alumno (simulacro_id, alumno_id),
      KEY simulacro_id (simulacro_id),
      KEY alumno_id (alumno_id)
    ) $charset;";
    dbDelta($sql);
  }

  /** Añade columna subgrupo a conducta_asistencias si no existe. */
  private static function ensure_asistencias_subgrupo_column() {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_asistencias';
    $exists = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'subgrupo'",
      $t
    ));
    if ($exists) return;
    $wpdb->query("ALTER TABLE `$t` ADD COLUMN subgrupo VARCHAR(20) NULL AFTER curso_id, ADD KEY subgrupo (subgrupo)");
  }

  /** Añade columna grupo_id a conducta_asistencias si no existe y migra desde aula_id. */
  private static function ensure_asistencias_grupo_id_column() {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_asistencias';
    $hasGrupo = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'grupo_id'",
      $t
    ));
    if (!$hasGrupo) {
      $wpdb->query("ALTER TABLE `$t` ADD COLUMN grupo_id BIGINT UNSIGNED NULL AFTER materia_id, ADD KEY grupo_id (grupo_id)");
      $hasAula = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'aula_id'",
        $t
      ));
      if ($hasAula) {
        $wpdb->query("UPDATE `$t` SET grupo_id = aula_id WHERE grupo_id IS NULL AND aula_id IS NOT NULL");
      }
    }
  }

  /** Añade columna simulacro_id a conducta_asistencias si no existe. */
  private static function ensure_asistencias_simulacro_id_column() {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_asistencias';
    $exists = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'simulacro_id'",
      $t
    ));
    if ($exists) return;
    $wpdb->query("ALTER TABLE `$t` ADD COLUMN simulacro_id BIGINT UNSIGNED NULL AFTER subgrupo, ADD KEY simulacro_id (simulacro_id)");
  }
}
