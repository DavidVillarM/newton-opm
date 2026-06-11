<?php
if (!defined('ABSPATH')) exit;

/**
 * Esquema de base de datos para Puntajes / Exámenes.
 * Tablas: exámenes, examen_materias (Medicina / líneas por materia catálogo),
 * examen_temas (Ingeniería: temas propios del examen, no son materias),
 * examen_puntajes (puntaje por alumno por materia y/o por tema).
 */
class NC_Examenes_DB {

  public static function schema_version(): string {
    return '1.0.0';
  }

  public static function maybe_upgrade(): void {
    if (!function_exists('get_option')) {
      return;
    }
    $stored = (string) get_option('nc_examenes_schema_version', '0.0.0');
    if (version_compare($stored, self::schema_version(), '>=')) {
      return;
    }
    self::ensure_schema();
    update_option('nc_examenes_schema_version', self::schema_version(), false);
  }

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

    $t_ex  = $wpdb->prefix . 'conducta_examenes';
    $t_em  = $wpdb->prefix . 'conducta_examen_materias';
    $t_ep  = $wpdb->prefix . 'conducta_examen_puntajes';

    $sql = "CREATE TABLE $t_ex (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      nombre VARCHAR(255) NOT NULL,
      tipo VARCHAR(20) NOT NULL DEFAULT 'ingenieria',
      curso_id BIGINT UNSIGNED NULL,
      fecha DATE NOT NULL,
      creado_por BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (id),
      KEY tipo (tipo),
      KEY curso_id (curso_id),
      KEY fecha (fecha),
      KEY creado_por (creado_por)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_em (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      puntos_maximos DECIMAL(6,2) NOT NULL DEFAULT 10.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_materia (examen_id, materia_id),
      KEY examen_id (examen_id),
      KEY materia_id (materia_id)
    ) $charset;";
    dbDelta($sql);

    $t_et = $wpdb->prefix . 'conducta_examen_temas';
    $sql = "CREATE TABLE $t_et (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      orden INT UNSIGNED NOT NULL DEFAULT 0,
      nombre VARCHAR(255) NOT NULL,
      puntos_maximos DECIMAL(6,2) NOT NULL DEFAULT 10.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY examen_id (examen_id)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_ep (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NULL DEFAULT NULL,
      tema_id BIGINT UNSIGNED NULL DEFAULT NULL,
      puntaje DECIMAL(6,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_alumno_linea (examen_id, alumno_id, materia_id, tema_id),
      KEY examen_id (examen_id),
      KEY alumno_id (alumno_id),
      KEY materia_id (materia_id),
      KEY tema_id (tema_id)
    ) $charset;";
    dbDelta($sql);
    self::migrate_puntajes_temas_schema($t_ep);

    $t_ei = $wpdb->prefix . 'conducta_examen_items';
    $t_sh = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_eph = $wpdb->prefix . 'conducta_examen_puntajes_historial';
    $t_ea = $wpdb->prefix . 'conducta_examen_aulas';

    $sql = "CREATE TABLE $t_ei (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      orden_canonico INT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NOT NULL,
      enunciado LONGTEXT NOT NULL,
      opcion_a TEXT NULL,
      opcion_b TEXT NULL,
      opcion_c TEXT NULL,
      opcion_d TEXT NULL,
      respuesta_correcta VARCHAR(20) NOT NULL DEFAULT 'A',
      puntos_item DECIMAL(6,2) NOT NULL DEFAULT 1.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_orden (examen_id, orden_canonico),
      KEY examen_id (examen_id),
      KEY materia_id (materia_id)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_sh (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      posicion_hoja INT UNSIGNED NOT NULL,
      item_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_hoja (examen_id, posicion_hoja),
      UNIQUE KEY examen_item (examen_id, item_id),
      KEY examen_id (examen_id),
      KEY item_id (item_id)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_er (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      item_id BIGINT UNSIGNED NOT NULL,
      respuesta_alumno VARCHAR(20) NULL,
      es_correcta TINYINT(1) NOT NULL DEFAULT 0,
      puntos_obtenidos DECIMAL(6,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_alumno_item (examen_id, alumno_id, item_id),
      KEY examen_id (examen_id),
      KEY alumno_id (alumno_id),
      KEY item_id (item_id)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_eph (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      puntaje_id BIGINT UNSIGNED NULL DEFAULT NULL,
      examen_id BIGINT UNSIGNED NOT NULL,
      alumno_id BIGINT UNSIGNED NOT NULL,
      materia_id BIGINT UNSIGNED NULL DEFAULT NULL,
      tema_id BIGINT UNSIGNED NULL DEFAULT NULL,
      puntaje_anterior DECIMAL(6,2) NOT NULL DEFAULT 0.00,
      puntaje_nuevo DECIMAL(6,2) NOT NULL DEFAULT 0.00,
      editado_por BIGINT UNSIGNED NOT NULL,
      editado_por_login VARCHAR(120) NULL,
      editado_por_nombre VARCHAR(180) NULL,
      motivo VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY examen_id (examen_id),
      KEY alumno_id (alumno_id),
      KEY puntaje_id (puntaje_id),
      KEY created_at (created_at)
    ) $charset;";
    dbDelta($sql);

    $sql = "CREATE TABLE $t_ea (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      examen_id BIGINT UNSIGNED NOT NULL,
      aula_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY examen_aula (examen_id, aula_id),
      KEY examen_id (examen_id),
      KEY aula_id (aula_id)
    ) $charset;";
    dbDelta($sql);
  }

  /**
   * Migra tabla examen_puntajes de (solo materia_id) a (materia_id nullable + tema_id nullable).
   */
  private static function migrate_puntajes_temas_schema(string $t_ep): void {
    global $wpdb;
    $tbl = $t_ep;
    $has_tema = $wpdb->get_results("SHOW COLUMNS FROM `{$tbl}` LIKE 'tema_id'");
    if (!empty($has_tema)) {
      return;
    }
    $has_old_uniq = $wpdb->get_results("SHOW INDEX FROM `{$tbl}` WHERE Key_name='examen_alumno_materia'");
    if (!empty($has_old_uniq)) {
      $wpdb->query("ALTER TABLE `{$tbl}` DROP INDEX examen_alumno_materia");
    }
    $wpdb->query("ALTER TABLE `{$tbl}` ADD COLUMN tema_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER materia_id");
    $wpdb->query("ALTER TABLE `{$tbl}` MODIFY materia_id BIGINT UNSIGNED NULL DEFAULT NULL");
    $wpdb->query("ALTER TABLE `{$tbl}` ADD UNIQUE KEY examen_alumno_linea (examen_id, alumno_id, materia_id, tema_id)");
    $has_k = $wpdb->get_results("SHOW INDEX FROM `{$tbl}` WHERE Key_name='tema_id'");
    if (empty($has_k)) {
      $wpdb->query("ALTER TABLE `{$tbl}` ADD KEY tema_id (tema_id)");
    }
  }
}
