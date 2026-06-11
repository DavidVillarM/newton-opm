<?php
if (!defined('ABSPATH')) exit;

/**
 * Importación de ítems (Word/Excel), orden de hoja (Excel) y resultados por CI (Excel).
 */
class NC_Examenes_Import {

  private static function autoload(): void {
    if (defined('NC_PATH')) {
      $path = NC_PATH . 'vendor/autoload.php';
      if (is_file($path)) {
        require_once $path;
        return;
      }
    }
    $path2 = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($path2)) {
      require_once $path2;
    }
  }

  public static function norm_ci(string $ci): string {
    return preg_replace('/\D+/', '', $ci);
  }

  /** @return int|null */
  public static function find_alumno_id_by_ci(string $ci) {
    global $wpdb;
    $ci = trim($ci);
    if ($ci === '') {
      return null;
    }
    $norm = self::norm_ci($ci);
    if ($norm === '') {
      return null;
    }
    $t = $wpdb->prefix . 'conducta_alumnos';
    $sql = "SELECT id FROM $t WHERE activo=1 AND (ci=%s OR REPLACE(REPLACE(REPLACE(REPLACE(ci,'.',''),'-',''),' ',''),'/','')=%s) LIMIT 1";
    $id = $wpdb->get_var($wpdb->prepare($sql, $ci, $norm));
    if ($id) {
      return (int) $id;
    }
    /* PDF / import: cédulas de 8 dígitos o dígito extra; probar variantes solo si no hubo match exacto. */
    $variants = [];
    $len = strlen($norm);
    if ($len === 8) {
      $variants[] = substr($norm, 0, 7);
      $variants[] = substr($norm, 1);
      $variants[] = substr($norm, -7);
    } elseif ($len > 8) {
      $variants[] = substr($norm, 0, 8);
      $variants[] = substr($norm, -8);
    }
    foreach (array_unique(array_filter($variants)) as $v) {
      if ($v === $norm) {
        continue;
      }
      $id = $wpdb->get_var($wpdb->prepare($sql, $v, $v));
      if ($id) {
        return (int) $id;
      }
    }
    return null;
  }

  /** Normaliza letra de respuesta A-D (vacío = en blanco). */
  public static function norm_respuesta($v): string {
    if ($v === null || $v === '') {
      return '';
    }
    $s = strtoupper(trim((string) $v));
    if ($s === '' || $s === '-' || $s === '—' || strcasecmp($s, 'NULO') === 0 || strcasecmp($s, 'OM') === 0) {
      return '';
    }
    if (preg_match('/^[1-5]$/', $s)) {
      return ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E'][$s];
    }
    if (preg_match('/^[ABCDE]$/i', $s)) {
      return strtoupper($s[0]);
    }
    return strtoupper(substr($s, 0, 1));
  }

  /**
   * Parsea PDF de resultados Medicina con columnas por materia.
   * Formato esperado: CI + (GUA,CAS,EST,MAT,FIS,INO,ORG,BIO,ANA) + Total [+ Cambio].
   *
   * @return array{
   *   columnas: array<int,string>,
   *   rows: array<int, array{ci:string, puntajes: array<string,float>}>,
   *   maximos_detectados: array<string,float>
   * }
   */
  public static function parse_medicina_pdf_puntajes(string $path): array {
    self::autoload();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
      throw new RuntimeException('No se pudo leer el PDF de resultados.');
    }
    $text = '';
    $hasSmalot = class_exists(\Smalot\PdfParser\Parser::class);
    $paginas_pdf = 0;
    if ($hasSmalot) {
      try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);
        $paginas_pdf = count($pdf->getPages());
        $bloques = [];
        foreach ($pdf->getPages() as $page) {
          $pt = $page->getText();
          if (is_string($pt) && trim($pt) !== '') {
            $bloques[] = $pt;
          }
        }
        $text = trim(implode("\n", $bloques));
        if ($text === '') {
          $text = (string) $pdf->getText();
        }
      } catch (Throwable $e) {
        $text = '';
      }
    }
    if (trim($text) === '') {
      $text = self::extract_text_from_pdf($raw);
    }
    if (trim($text) === '') {
      // Fallback simple (algunos PDFs traen texto legible directo).
      $text = $raw;
    }
    $text = preg_replace("/\r\n?/", "\n", (string) $text);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $text))));
    if (empty($lines)) {
      throw new RuntimeException('El PDF no contiene texto legible.');
    }

    $cols = self::detect_medicina_cols($lines);
    if (count($cols) < 3) {
      throw new RuntimeException('No se pudieron detectar columnas de materias en el PDF (ej. GUA CAS EST MAT...).');
    }
    $nCols = count($cols);
    $rows = [];
    $maximos = [];
    $seenCi = [];
    $normalized = preg_replace('/\s+/u', ' ', (string) $text);
    $regexRowsCount = 0;
    $counterStats = [
      'contador_encontradas' => 0,
      'contador_descartadas' => 0,
      'contador_esperado_hasta' => 0,
    ];
    if (is_string($normalized) && $normalized !== '') {
      $regexRows = self::parse_medicina_rows_by_table_regex($normalized, $cols, $maximos);
      $regexRowsCount = count($regexRows);
      foreach ($regexRows as $r) {
        $ci = isset($r['ci']) ? (string) $r['ci'] : '';
        if ($ci === '' || isset($seenCi[$ci])) {
          continue;
        }
        $rows[] = $r;
        $seenCi[$ci] = 1;
      }
      $counterRows = self::parse_medicina_rows_by_counter($normalized, $nCols, $cols, $maximos, $counterStats);
      foreach ($counterRows as $r) {
        $ci = isset($r['ci']) ? (string) $r['ci'] : '';
        if ($ci === '' || isset($seenCi[$ci])) {
          continue;
        }
        $rows[] = $r;
        $seenCi[$ci] = 1;
      }
    }
    $candidates = $lines;
    if (is_string($normalized) && $normalized !== '') {
      // Inserta saltos: "10 5750350 Nombre" o "15521545Jean" (N°+CI pegado antes del nombre).
      $seg = preg_replace('/(?<!\d)(\d{1,3})\s+(\d{5,12})\s/u', "\n$1 $2 ", $normalized);
      /* CI pegada al N°: 5–12 dígitos (incl. cédulas de 8) antes de letra del nombre. */
      $seg = preg_replace('/(?<!\d)(\d{1,3})(\d{5,12})(?=\p{L})/u', "\n$1$2 ", $seg);
      $parts = array_values(array_filter(array_map('trim', explode("\n", (string) $seg))));
      foreach ($parts as $p) {
        $candidates[] = $p;
      }
      // Segundo barrido: cada fila suele empezar con "N° CI " seguido de letra (nombre).
      // Evita perder filas cuando el PDF junta páginas o líneas sin salto de línea.
      $patterns = [
        /* Tras la CI, nombre con letra (caso normal). */
        '/(?<![0-9])(\d{1,3})\s+(\d{5,12})\s+(?=\p{L})/u',
        /* Sin nombre en el PDF: tras la CI sigue directamente la primera nota (dígito). */
        '/(?<![0-9])(\d{1,3})\s+(\d{5,12})\s+(?=\d)/u',
      ];
      foreach ($patterns as $rx) {
        if (preg_match_all($rx, $normalized, $_m, PREG_OFFSET_CAPTURE)) {
          $offs = [];
          foreach ($_m[0] as $cap) {
            $offs[] = (int) $cap[1];
          }
          $offs = array_values(array_unique($offs));
          sort($offs, SORT_NUMERIC);
          $nOff = count($offs);
          for ($oi = 0; $oi < $nOff; $oi++) {
            $from = $offs[$oi];
            $to = ($oi + 1 < $nOff) ? $offs[$oi + 1] : strlen($normalized);
            $chunk = trim(substr($normalized, $from, $to - $from));
            if ($chunk !== '') {
              $candidates[] = $chunk;
            }
          }
        }
      }
    }

    $candidates = array_values(array_unique(array_map(static function ($s) {
      return trim((string) $s);
    }, $candidates)));

    $stats = [
      'candidatos_linea'   => count($candidates),
      'filas_parseadas'    => count($rows),
      'ci_duplicado'       => 0,
      'lineas_sin_parsear' => 0,
      'paginas_pdf'        => $paginas_pdf,
      'contador_encontradas' => (int) $counterStats['contador_encontradas'],
      'contador_descartadas' => (int) $counterStats['contador_descartadas'],
      'contador_esperado_hasta' => (int) $counterStats['contador_esperado_hasta'],
      'regex_tabla_encontradas' => (int) $regexRowsCount,
    ];
    foreach ($candidates as $ln) {
      $parsed = self::parse_medicina_row_line($ln, $nCols, $cols, $maximos);
      if (!$parsed) {
        if (preg_match('/^\d/u', trim((string) $ln))) {
          $stats['lineas_sin_parsear']++;
        }
        continue;
      }
      $ci = (string) $parsed['ci'];
      if ($ci === '') continue;
      if (isset($seenCi[$ci])) {
        $stats['ci_duplicado']++;
        continue;
      }
      $rows[] = $parsed;
      $seenCi[$ci] = 1;
      $stats['filas_parseadas']++;
    }

    if (empty($rows)) {
      $msg = 'No se detectaron filas válidas de alumnos en el PDF.';
      if (!$hasSmalot) {
        $msg .= ' Falta parser PDF del servidor (instale dependencia composer: smalot/pdfparser).';
      } else {
        $msg .= ' Texto extraído insuficiente o formato no compatible.';
      }
      throw new RuntimeException($msg);
    }
    return [
      'columnas'             => $cols,
      'rows'                 => $rows,
      'maximos_detectados'   => $maximos,
      'estadisticas_parseo'  => $stats,
    ];
  }

  /**
   * Parsea resultados de Medicina desde Excel (.xlsx/.xls).
   * Columnas mínimas: CI + materias (GUA,CAS,EST,MAT,FIS,INO/IND,ORG,BIO,ANA).
   *
   * @return array{
   *   columnas: array<int,string>,
   *   rows: array<int, array{ci:string, puntajes: array<string,float>}>,
   *   maximos_detectados: array<string,float>,
   *   estadisticas_parseo: array<string,int>
   * }
   */
  public static function parse_medicina_xlsx_puntajes(string $path): array {
    self::autoload();
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
      throw new RuntimeException('PhpSpreadsheet no disponible.');
    }
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $matrix = $sheet->toArray(null, true, true, false);
    if (empty($matrix)) {
      throw new RuntimeException('El Excel no contiene filas.');
    }
    return self::parse_medicina_matrix_puntajes($matrix, 'excel');
  }

  /**
   * Parsea resultados de Medicina desde Word (.docx) leyendo primera tabla.
   *
   * @return array{
   *   columnas: array<int,string>,
   *   rows: array<int, array{ci:string, puntajes: array<string,float>}>,
   *   maximos_detectados: array<string,float>,
   *   estadisticas_parseo: array<string,int>
   * }
   */
  public static function parse_medicina_docx_puntajes(string $path): array {
    self::autoload();
    if (!class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
      throw new RuntimeException('PHPWord no disponible.');
    }
    try {
      $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
      $tables = [];
      foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $el) {
          if ($el instanceof \PhpOffice\PhpWord\Element\Table) {
            $tables[] = $el;
          }
        }
      }
      if (empty($tables)) {
        throw new RuntimeException('El DOCX no contiene una tabla con resultados.');
      }
      $matrix = self::table_to_matrix($tables[0]);
      if (empty($matrix)) {
        throw new RuntimeException('No se pudieron leer filas de la tabla DOCX.');
      }
      return self::parse_medicina_matrix_puntajes($matrix, 'docx');
    } catch (Throwable $e) {
      throw new RuntimeException('No se pudo leer resultados desde DOCX: ' . $e->getMessage());
    }
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public static function parse_items_xlsx(string $path): array {
    self::autoload();
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
      throw new RuntimeException('PhpSpreadsheet no disponible.');
    }
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
      return [];
    }
    $header = array_shift($rows);
    $map = self::header_map_items($header);
    if (!isset($map['orden']) || !isset($map['materia_id']) || !isset($map['enunciado'])) {
      throw new RuntimeException('La hoja debe incluir columnas: orden (o n), materia_id (o materia), enunciado. Opcionales: a,b,c,d, correcta, puntos.');
    }
    $out = [];
    foreach ($rows as $r) {
      if (!is_array($r)) {
        continue;
      }
      $orden = self::cell_int($r[$map['orden']] ?? null);
      if ($orden <= 0) {
        continue;
      }
      $mid = (int) ($r[$map['materia_id']] ?? 0);
      $enun = isset($map['enunciado']) ? trim((string) ($r[$map['enunciado']] ?? '')) : '';
      if ($enun === '' && $mid <= 0) {
        continue;
      }
      $row = [
        'orden_canonico' => $orden,
        'materia_id'     => $mid,
        'enunciado'      => $enun,
        'opcion_a'       => isset($map['opcion_a']) ? trim((string) ($r[$map['opcion_a']] ?? '')) : '',
        'opcion_b'       => isset($map['opcion_b']) ? trim((string) ($r[$map['opcion_b']] ?? '')) : '',
        'opcion_c'       => isset($map['opcion_c']) ? trim((string) ($r[$map['opcion_c']] ?? '')) : '',
        'opcion_d'       => isset($map['opcion_d']) ? trim((string) ($r[$map['opcion_d']] ?? '')) : '',
        'respuesta_correcta' => 'A',
        'puntos_item'    => 1.0,
      ];
      if (isset($map['correcta'])) {
        $row['respuesta_correcta'] = self::norm_respuesta($r[$map['correcta']] ?? 'A') ?: 'A';
      }
      if (isset($map['puntos'])) {
        $p = isset($r[$map['puntos']]) ? (float) $r[$map['puntos']] : 1.0;
        $row['puntos_item'] = max(0, round($p, 2));
      }
      $out[] = $row;
    }
    return $out;
  }

  /**
   * Primera tabla del documento: columnas orden, materia_id, enunciado, a, b, c, d, correcta, puntos.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function parse_items_docx(string $path): array {
    self::autoload();
    if (class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
      try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
        $tables = [];
        foreach ($phpWord->getSections() as $section) {
          foreach ($section->getElements() as $el) {
            if ($el instanceof \PhpOffice\PhpWord\Element\Table) {
              $tables[] = $el;
            }
          }
        }
        if (!empty($tables)) {
          return self::rows_from_matrix(self::table_to_matrix($tables[0]));
        }
      } catch (Throwable $e) {
        // fallback
      }
    }

    // Fallback sin/contra PHPWord: lectura directa del XML de DOCX (formato 1..100 con correcta resaltada).
    $rows = self::parse_items_docx_via_xml($path);
    if (!empty($rows)) return $rows;
    throw new RuntimeException('No se pudieron leer ítems desde DOCX. El documento debe tener preguntas numeradas (1..100), opciones a/b/c/d(/e) y respuesta correcta resaltada.');
  }

  /**
   * Columnas: posicion_hoja, orden_canonico (primera fila encabezado o dos columnas numéricas sin título).
   *
   * @return array<int, array{posicion_hoja:int, orden_canonico:int}>
   */
  public static function parse_shuffle_xlsx(string $path): array {
    self::autoload();
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
      return [];
    }
    $header = $rows[0];
    $idx_hoja = null;
    $idx_canon = null;
    foreach ($header as $i => $h) {
      $k = self::norm_header_key($h);
      if ($k === '') {
        continue;
      }
      if (in_array($k, ['posicion_hoja', 'posicion', 'pos_hoja', 'hoja', 'casilla', 'n_hoja', 'examen'], true)) {
        $idx_hoja = $i;
      }
      if (in_array($k, ['orden_canonico', 'orden_canon', 'canonico', 'original', 'item_orden', 'orden_item'], true)) {
        $idx_canon = $i;
      }
      if ($k === 'orden' && $idx_canon === null) {
        $idx_canon = $i;
      }
    }
    $data_rows = $rows;
    if ($idx_hoja === null || $idx_canon === null) {
      /* Sin encabezado reconocible: fila 0 = datos si ambos son enteros */
      $a = self::cell_int($header[0] ?? null);
      $b = self::cell_int($header[1] ?? null);
      if ($a > 0 && $b > 0) {
        $idx_hoja = 0;
        $idx_canon = 1;
      } else {
        throw new RuntimeException('Excel de orden: use columnas posicion_hoja y orden_canonico (fila de títulos), o dos columnas numéricas sin título.');
      }
    } else {
      array_shift($data_rows);
    }
    $out = [];
    foreach ($data_rows as $r) {
      if (!is_array($r)) {
        continue;
      }
      $ph = self::cell_int($r[$idx_hoja] ?? null);
      $oc = self::cell_int($r[$idx_canon] ?? null);
      if ($ph > 0 && $oc > 0) {
        $out[] = ['posicion_hoja' => $ph, 'orden_canonico' => $oc];
      }
    }
    return $out;
  }

  /**
   * Col1 CI; siguientes columnas = respuestas en posición de hoja 1..N.
   *
   * @return array<int, array{ci:string, respuestas: array<int,string>}> posicion 1-based => letra
   */
  public static function parse_resultados_xlsx(string $path): array {
    self::autoload();
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
      return [];
    }
    $header = $rows[0];
    $ciCol = 0;
    $start = 1;

    // Detecta automáticamente:
    //  A) CI | R1 | R2 ...
    //  B) Nro | CI | R1 | R2 ...
    //  con o sin fila de encabezado.
    $k0 = self::norm_header_key($header[0] ?? '');
    $k1 = self::norm_header_key($header[1] ?? '');
    $hasHeader = false;

    if (preg_match('/^(ci|cedula|documento|id)$/', $k0)) {
      $hasHeader = true;
      $ciCol = 0;
      $start = 1;
    } elseif (preg_match('/^(nro|numero|num|orden|fila|n)$/', $k0) && preg_match('/^(ci|cedula|documento|id)$/', $k1)) {
      $hasHeader = true;
      $ciCol = 1;
      $start = 2;
    } else {
      // Sin encabezado: inferencia por contenido de la primera fila.
      $v0 = trim((string)($header[0] ?? ''));
      $v1 = trim((string)($header[1] ?? ''));
      $n0 = self::norm_ci($v0);
      $n1 = self::norm_ci($v1);
      $isNum0 = ($v0 !== '' && preg_match('/^\d+$/', $v0));
      $looksCi0 = (strlen($n0) >= 5);
      $looksCi1 = (strlen($n1) >= 5);
      if ($isNum0 && $looksCi1) {
        $ciCol = 1;
        $start = 2;
      } elseif ($looksCi0) {
        $ciCol = 0;
        $start = 1;
      } else {
        $ciCol = 0;
        $start = 1;
      }
    }

    if ($hasHeader) {
      array_shift($rows);
    }

    $out = [];
    foreach ($rows as $r) {
      if (!is_array($r)) {
        continue;
      }
      $ci = trim((string) ($r[$ciCol] ?? ''));
      if ($ci === '') {
        continue;
      }
      $resp = [];
      $max = count($r);
      for ($p = $start; $p < $max; $p++) {
        $resp[$p - $start + 1] = self::norm_respuesta($r[$p] ?? '');
      }
      $out[] = ['ci' => $ci, 'respuestas' => $resp];
    }
    return $out;
  }

  public static function replace_items(int $examen_id, array $rows): void {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_examen_items';
    $t_sh = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $wpdb->delete($t_er, ['examen_id' => $examen_id], ['%d']);
    $wpdb->delete($t_sh, ['examen_id' => $examen_id], ['%d']);
    $wpdb->delete($t_ep, ['examen_id' => $examen_id], ['%d']);
    $wpdb->delete($t, ['examen_id' => $examen_id], ['%d']);
    foreach ($rows as $row) {
      $wpdb->insert($t, [
        'examen_id'           => $examen_id,
        'orden_canonico'      => (int) $row['orden_canonico'],
        'materia_id'          => (int) $row['materia_id'],
        'enunciado'           => $row['enunciado'],
        'opcion_a'            => $row['opcion_a'] ?? '',
        'opcion_b'            => $row['opcion_b'] ?? '',
        'opcion_c'            => $row['opcion_c'] ?? '',
        'opcion_d'            => $row['opcion_d'] ?? '',
        'respuesta_correcta'  => $row['respuesta_correcta'] ?? 'A',
        'puntos_item'         => (float) ($row['puntos_item'] ?? 1),
      ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f']);
    }
  }

  public static function replace_shuffle(int $examen_id, array $pairs): void {
    global $wpdb;
    $t_i = $wpdb->prefix . 'conducta_examen_items';
    $t_s = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';
    $wpdb->delete($t_er, ['examen_id' => $examen_id], ['%d']);
    $wpdb->delete($t_ep, ['examen_id' => $examen_id], ['%d']);
    $wpdb->delete($t_s, ['examen_id' => $examen_id], ['%d']);
    foreach ($pairs as $p) {
      $ph = (int) $p['posicion_hoja'];
      $oc = (int) $p['orden_canonico'];
      $item_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t_i WHERE examen_id=%d AND orden_canonico=%d",
        $examen_id,
        $oc
      ));
      if ($item_id <= 0) {
        continue;
      }
      $wpdb->insert($t_s, [
        'examen_id'     => $examen_id,
        'posicion_hoja' => $ph,
        'item_id'       => $item_id,
      ], ['%d', '%d', '%d']);
    }
  }

  /**
   * @param array<int, array{ci:string, respuestas:array<int,string>}> $alumnos_rows
   * @return array{imported:int, skipped:int, errors: string[]}
   */
  public static function import_resultados(int $examen_id, array $alumnos_rows): array {
    global $wpdb;
    $t_sh = $wpdb->prefix . 'conducta_examen_shuffle';
    $t_i = $wpdb->prefix . 'conducta_examen_items';
    $t_er = $wpdb->prefix . 'conducta_examen_respuestas';
    $t_ep = $wpdb->prefix . 'conducta_examen_puntajes';

    $map_hoja = [];
    $rows_sh = $wpdb->get_results($wpdb->prepare(
      "SELECT s.posicion_hoja, s.item_id, i.orden_canonico, i.materia_id, i.respuesta_correcta, i.puntos_item
       FROM $t_sh s INNER JOIN $t_i i ON i.id=s.item_id WHERE s.examen_id=%d ORDER BY s.posicion_hoja ASC",
      $examen_id
    ), ARRAY_A);
    foreach ($rows_sh ?: [] as $r) {
      $map_hoja[(int) $r['posicion_hoja']] = $r;
    }
    if (empty($map_hoja)) {
      return ['imported' => 0, 'skipped' => 0, 'errors' => ['No hay tabla de orden (shuffle) cargada para este examen.']];
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];

    foreach ($alumnos_rows as $line) {
      $ci = $line['ci'];
      $alumno_id = self::find_alumno_id_by_ci($ci);
      if (!$alumno_id) {
        $skipped++;
        $errors[] = 'CI sin alumno: ' . $ci;
        continue;
      }
      $wpdb->delete($t_er, ['examen_id' => $examen_id, 'alumno_id' => $alumno_id], ['%d', '%d']);
      $by_materia = [];
      foreach ($map_hoja as $pos => $meta) {
        $item_id = (int) $meta['item_id'];
        $correcta = self::norm_respuesta($meta['respuesta_correcta'] ?? 'A');
        $puntos_item = (float) ($meta['puntos_item'] ?? 1);
        $mid = (int) $meta['materia_id'];
        $resp = isset($line['respuestas'][$pos]) ? $line['respuestas'][$pos] : '';
        $ok = ($resp !== '' && $correcta !== '' && $resp === $correcta);
        $pts = $ok ? $puntos_item : 0.0;
        $wpdb->insert($t_er, [
          'examen_id'        => $examen_id,
          'alumno_id'        => $alumno_id,
          'item_id'          => $item_id,
          'respuesta_alumno' => $resp === '' ? null : $resp,
          'es_correcta'      => $ok ? 1 : 0,
          'puntos_obtenidos' => $pts,
        ], ['%d', '%d', '%d', '%s', '%d', '%f']);
        if (!isset($by_materia[$mid])) {
          $by_materia[$mid] = 0.0;
        }
        $by_materia[$mid] += $pts;
      }
      foreach ($by_materia as $mid => $pts) {
        $wpdb->replace($t_ep, [
          'examen_id'  => $examen_id,
          'alumno_id'  => $alumno_id,
          'materia_id' => $mid,
          'puntaje'    => round($pts, 2),
        ], ['%d', '%d', '%d', '%f']);
      }
      $imported++;
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice(array_unique($errors), 0, 80)];
  }

  /** @param array<int, string|int|float|null> $header */
  private static function header_map_items(array $header): array {
    $map = [];
    foreach ($header as $i => $h) {
      $k = self::norm_header_key($h);
      if ($k === '') {
        continue;
      }
      if (in_array($k, ['orden', 'orden_canonico', 'n', 'num', 'item', 'no', 'nro', 'numero'], true)) {
        if (!isset($map['orden'])) {
          $map['orden'] = $i;
        }
      }
      if (preg_match('/^(materia_id|id_?materia|idmat)$/', $k)) {
        $map['materia_id'] = $i;
      }
      if (preg_match('/^materia$/', $k) && !isset($map['materia_id'])) {
        /* nombre materia — not supported in import; skip */
      }
      if (preg_match('/^(enunciado|pregunta|texto|item_?texto)$/', $k)) {
        $map['enunciado'] = $i;
      }
      if (preg_match('/^(opcion_?a|alt_?a|^a)$/', $k)) {
        $map['opcion_a'] = $i;
      }
      if (preg_match('/^(opcion_?b|alt_?b|^b)$/', $k)) {
        $map['opcion_b'] = $i;
      }
      if (preg_match('/^(opcion_?c|alt_?c|^c)$/', $k)) {
        $map['opcion_c'] = $i;
      }
      if (preg_match('/^(opcion_?d|alt_?d|^d)$/', $k)) {
        $map['opcion_d'] = $i;
      }
      if (preg_match('/^(correcta|clave|respuesta|key)$/', $k)) {
        $map['correcta'] = $i;
      }
      if (preg_match('/^(puntos|pts|valor)$/', $k)) {
        $map['puntos'] = $i;
      }
    }
    return $map;
  }

  private static function norm_header_key($h): string {
    $s = strtolower(trim(preg_replace('/\s+/u', ' ', (string) $h)));
    $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $s);
    return preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', $s));
  }

  /** @param array<int,string> $lines */
  private static function detect_medicina_cols(array $lines): array {
    $known = ['GUA','CAS','EST','MAT','FIS','INO','ORG','BIO','ANA'];
    foreach ($lines as $ln) {
      $u = strtoupper((string) $ln);
      if (strpos($u, 'CEDULA') === false || strpos($u, 'TOTAL') === false) continue;
      $tokens = preg_split('/\s+/u', trim($u));
      if (!$tokens) continue;
      $cols = [];
      foreach ($tokens as $t) {
        $k = preg_replace('/[^A-Z]/', '', (string) $t);
        if ($k === 'IND') {
          $k = 'INO';
        }
        if (in_array($k, $known, true)) $cols[] = $k;
      }
      if (!empty($cols)) {
        $uniq = [];
        foreach ($known as $k) {
          if (in_array($k, $cols, true)) $uniq[] = $k;
        }
        if (!empty($uniq)) return $uniq;
      }
    }
    return $known;
  }

  /**
   * @param array<int, array<int,mixed>> $matrix
   * @return array{
   *   columnas: array<int,string>,
   *   rows: array<int, array{ci:string, puntajes: array<string,float>}>,
   *   maximos_detectados: array<string,float>,
   *   estadisticas_parseo: array<string,int>
   * }
   */
  private static function parse_medicina_matrix_puntajes(array $matrix, string $source): array {
    $known = ['GUA','CAS','EST','MAT','FIS','INO','ORG','BIO','ANA'];
    $headerIdx = -1;
    $ciIdx = -1;
    $colIdxByCode = [];

    $scanMax = min(count($matrix), 20);
    for ($ri = 0; $ri < $scanMax; $ri++) {
      $row = isset($matrix[$ri]) && is_array($matrix[$ri]) ? $matrix[$ri] : [];
      if (empty($row)) continue;
      $tmpCi = -1;
      $tmpCols = [];
      foreach ($row as $ci => $v) {
        $hk = self::norm_header_key((string) $v);
        if ($hk === 'ci' || $hk === 'cedula' || $hk === 'cedula_de_identidad' || $hk === 'cedulaidentidad') {
          $tmpCi = (int) $ci;
          continue;
        }
        if ($hk === 'ind') $hk = 'ino';
        if ($hk === 'inorganica') $hk = 'ino';
        if ($hk === 'organica') $hk = 'org';
        if ($hk === 'biologia') $hk = 'bio';
        if ($hk === 'anatomia') $hk = 'ana';
        if ($hk === 'castellano') $hk = 'cas';
        if ($hk === 'guarani') $hk = 'gua';
        if ($hk === 'estudiosparaguayos') $hk = 'est';
        if ($hk === 'matematica') $hk = 'mat';
        if ($hk === 'fisica') $hk = 'fis';
        $code = strtoupper((string) $hk);
        if (in_array($code, $known, true)) {
          $tmpCols[$code] = (int) $ci;
        }
      }
      if ($tmpCi >= 0 && count($tmpCols) >= 3) {
        $headerIdx = $ri;
        $ciIdx = $tmpCi;
        foreach ($known as $k) {
          if (isset($tmpCols[$k])) $colIdxByCode[$k] = $tmpCols[$k];
        }
        break;
      }
    }

    if ($headerIdx < 0 || $ciIdx < 0 || count($colIdxByCode) < 3) {
      throw new RuntimeException('No se detectaron columnas válidas (CI + materias) en el archivo ' . strtoupper($source) . '.');
    }

    $rows = [];
    $maximos = [];
    $seenCi = [];
    $sinDatos = 0;
    for ($ri = $headerIdx + 1; $ri < count($matrix); $ri++) {
      $row = isset($matrix[$ri]) && is_array($matrix[$ri]) ? $matrix[$ri] : [];
      if (empty($row)) continue;
      $ci = self::norm_ci((string) ($row[$ciIdx] ?? ''));
      if ($ci === '') {
        $sinDatos++;
        continue;
      }
      if (isset($seenCi[$ci])) {
        continue;
      }
      $puntajes = [];
      foreach ($known as $k) {
        if (!isset($colIdxByCode[$k])) continue;
        $raw = $row[$colIdxByCode[$k]] ?? 0;
        $val = is_numeric($raw) ? (float) $raw : (float) str_replace(',', '.', (string) $raw);
        if (!is_finite($val)) $val = 0.0;
        $val = round($val, 2);
        $puntajes[$k] = $val;
        if (!isset($maximos[$k]) || $val > $maximos[$k]) $maximos[$k] = $val;
      }
      if (empty($puntajes)) {
        $sinDatos++;
        continue;
      }
      $rows[] = ['ci' => $ci, 'puntajes' => $puntajes];
      $seenCi[$ci] = 1;
    }

    if (empty($rows)) {
      throw new RuntimeException('No se detectaron filas válidas con CI en el archivo ' . strtoupper($source) . '.');
    }

    $cols = [];
    foreach ($known as $k) {
      if (isset($colIdxByCode[$k])) $cols[] = $k;
    }
    return [
      'columnas' => $cols,
      'rows' => $rows,
      'maximos_detectados' => $maximos,
      'estadisticas_parseo' => [
        'filas_parseadas' => count($rows),
        'lineas_sin_parsear' => $sinDatos,
        'candidatos_linea' => count($matrix),
      ],
    ];
  }

  private static function is_numeric_token(string $s): bool {
    $s = trim($s);
    return (bool) preg_match('/^-?\d+(?:[.,]\d+)?$/', $s);
  }

  /**
   * El PDF suele pegar la primera nota al apellido: "Duarte10", "Lopez9".
   * Separa en dos tokens para que el parseo por columnas funcione.
   *
   * @param array<int,string> $tokens
   * @return array<int,string>
   */
  private static function expand_medicina_glued_name_score_tokens(array $tokens): array {
    $out = [];
    foreach ($tokens as $tok) {
      $tok = (string) $tok;
      if (preg_match('/^([\p{L}]{2,})(\d{1,2})$/u', $tok, $m)) {
        $out[] = $m[1];
        $out[] = $m[2];
      } else {
        $out[] = $tok;
      }
    }
    return $out;
  }

  /**
   * Separa N° (1–3 dígitos) de Cédula cuando vienen pegados antes del nombre.
   * Elige la partición más plausible (cédula ~7 dígitos en PY, sin ceros iniciales artificiales).
   * Si el PDF no deja letra tras la CI (tab, nombre ausente), usa solo el bloque inicial de dígitos.
   *
   * @return array{nro:string, ci:string}|null
   */
  private static function split_compact_nro_y_ci(string $prefix): ?array {
    $prefix = trim((string) $prefix);
    if ($prefix === '') {
      return null;
    }
    $run = '';
    if (preg_match('/^(\d+)(\p{L}.*)$/su', $prefix, $m)) {
      $run = (string) $m[1];
    } elseif (preg_match('/^(\d+)/u', $prefix, $m2)) {
      /* Solo dígitos al inicio (p. ej. "1065715994" + tab, sin nombre en el texto) */
      $run = (string) $m2[1];
    } else {
      return null;
    }
    $runLen = strlen($run);
    if ($runLen < 6) {
      return null;
    }
    /* Evita comerse el total si el PDF pegó números extra al prefijo */
    if ($runLen > 15) {
      $run = substr($run, 0, 15);
      $runLen = strlen($run);
    }

    $best = null;
    $bestScore = PHP_INT_MIN;
    for ($nroLen = 1; $nroLen <= 3; $nroLen++) {
      if ($runLen <= $nroLen) {
        continue;
      }
      $nroS = substr($run, 0, $nroLen);
      $ciR = substr($run, $nroLen);
      if (!preg_match('/^\d+$/', $nroS) || !preg_match('/^\d{5,12}$/', $ciR)) {
        continue;
      }
      $nr = (int) $nroS;
      if ($nr < 1 || $nr > 999) {
        continue;
      }

      $ciLen = strlen($ciR);
      $score = 0;
      if ($ciLen === 7) {
        $score += 100;
      } elseif ($ciLen === 8) {
        $score += 85;
      } elseif ($ciLen === 6) {
        $score += 55;
      } else {
        $score += 35;
      }
      /* Evita “05750350”: suele ser N°+CI mal cortado */
      if ($ciR !== '' && $ciR[0] === '0') {
        $score -= 70;
      }
      if ($nr <= 500) {
        $score += 15;
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $best = ['nro' => $nroS, 'ci' => $ciR];
      }
    }

    return $best;
  }

  /**
   * Extrae filas desde texto corrido buscando patrón tabular completo.
   *
   * @param array<int,string>   $cols
   * @param array<string,float> $maximos
   * @return array<int, array{ci:string, puntajes: array<string,float>}>
   */
  private static function parse_medicina_rows_by_table_regex(string $normalized, array $cols, array &$maximos): array {
    $rows = [];
    $nCols = count($cols);
    if ($normalized === '' || $nCols < 3) {
      return $rows;
    }
    if (!preg_match_all('/(?<!\d)(\d{1,3})\s*(\d{6,8})\s+(.{8,260}?)(?:\s*[↑↓=↔-])?(?=(?:\s+\d{1,3}\s*\d{6,8}\s)|(?:\s*--\s*\d+\s+of\s+\d+\s*--)|$)/u', $normalized, $m, PREG_SET_ORDER)) {
      return $rows;
    }
    foreach ($m as $cap) {
      $ci = self::norm_ci((string) ($cap[2] ?? ''));
      $body = (string) ($cap[3] ?? '');
      if ($ci === '' || $body === '') continue;
      if (!preg_match_all('/\d+(?:[.,]\d+)?/u', $body, $mNums)) continue;
      $nums = isset($mNums[0]) && is_array($mNums[0]) ? $mNums[0] : [];
      if (count($nums) < ($nCols + 1)) continue;
      $tail = array_slice($nums, -($nCols + 1)); // scores + total
      array_pop($tail); // total
      if (count($tail) !== $nCols) continue;
      $puntajes = [];
      foreach ($cols as $idx => $c) {
        $v = isset($tail[$idx]) ? (float) str_replace(',', '.', (string) $tail[$idx]) : 0.0;
        $puntajes[$c] = $v;
        if (!isset($maximos[$c]) || $v > $maximos[$c]) $maximos[$c] = $v;
      }
      $rows[] = ['ci' => $ci, 'puntajes' => $puntajes];
    }
    return $rows;
  }

  /**
   * Recorre el texto con contador secuencial por N° (1,2,3...):
   * extrae CI, ignora nombre/apellido y obtiene notas por materia desde el final de la fila.
   *
   * @param array<string,float> $maximos
   * @param array<string,int>   $stats
   * @return array<int, array{ci:string, puntajes: array<string,float>}>
   */
  private static function parse_medicina_rows_by_counter(string $normalized, int $nCols, array $cols, array &$maximos, array &$stats): array {
    $rows = [];
    $len = strlen($normalized);
    if ($len <= 0) {
      return $rows;
    }
    if (!preg_match_all('/(?<!\d)(\d{1,3})\s*(\d{6,8})(?=(?:\s+\p{L})|(?:\p{L})|(?:\s+\d))/u', $normalized, $mAll, PREG_OFFSET_CAPTURE)) {
      return $rows;
    }
    $starts = [];
    foreach ($mAll[0] as $i => $cap) {
      $start = (int) $cap[1];
      $nro = isset($mAll[1][$i][0]) ? (int) $mAll[1][$i][0] : 0;
      if ($nro <= 0 || $nro > 300) {
        continue;
      }
      if (!isset($starts[$nro])) {
        $starts[$nro] = $start;
      }
    }
    if (empty($starts)) {
      return $rows;
    }
    ksort($starts, SORT_NUMERIC);
    $nros = array_keys($starts);
    $count = count($nros);
    for ($idx = 0; $idx < $count; $idx++) {
      $nro = (int) $nros[$idx];
      $start = (int) $starts[$nro];
      $nextStart = ($idx + 1 < $count) ? (int) $starts[$nros[$idx + 1]] : $len;
      if ($nextStart <= $start) {
        continue;
      }
      $chunk = trim(substr($normalized, $start, $nextStart - $start));
      if ($chunk === '') {
        continue;
      }
      $parsed = self::parse_medicina_row_line($chunk, $nCols, $cols, $maximos);
      if ($parsed) {
        $rows[] = $parsed;
        $stats['contador_encontradas'] = (int) ($stats['contador_encontradas'] ?? 0) + 1;
      } else {
        $stats['contador_descartadas'] = (int) ($stats['contador_descartadas'] ?? 0) + 1;
      }
      if ($nro > (int) ($stats['contador_esperado_hasta'] ?? 0)) {
        $stats['contador_esperado_hasta'] = $nro;
      }
    }
    return $rows;
  }

  /**
   * @param array<string,float> $maximos
   * @return array{ci:string, puntajes: array<string,float>}|null
   */
  private static function parse_medicina_row_line(string $ln, int $nCols, array $cols, array &$maximos): ?array {
    $ln = trim((string) $ln);
    if ($ln === '') return null;
    if (preg_match('/^--\s*\d+\s+of\s+\d+\s*--$/i', $ln)) return null;
    if (stripos($ln, 'cedula') !== false && stripos($ln, 'nombre') !== false) return null;
    /* N° (1–3 dígitos), luego cédula; evita que \d+ coma parte de la CI */
    if (!preg_match('/^(\d{1,3})\s+(\d{5,12})(?=\s|\p{L}|$)/u', $ln, $m)) {
      return self::parse_medicina_row_compact($ln, $nCols, $cols, $maximos);
    }
    $ci = self::norm_ci((string) $m[2]);
    if ($ci === '') return null;

    $after = preg_replace('/^\d{1,3}\s+\d{5,12}\s*/u', '', $ln);
    $tokens = preg_split('/\s+/u', trim((string) $after));
    $tokens = self::expand_medicina_glued_name_score_tokens($tokens);
    if (!$tokens || count($tokens) < ($nCols + 1)) return null;
    while (!empty($tokens)) {
      $last = (string) end($tokens);
      if ($last === '↑' || $last === '↓' || $last === '=' || $last === '-' || $last === '↔') {
        array_pop($tokens);
        continue;
      }
      break;
    }
    if (count($tokens) < ($nCols + 1)) {
      return self::parse_medicina_row_line_by_numeric_tail($ci, (string) $after, $nCols, $cols, $maximos);
    }
    $totalToken = (string) array_pop($tokens); // total (no se usa)
    if (!self::is_numeric_token($totalToken)) {
      return self::parse_medicina_row_line_by_numeric_tail($ci, (string) $after, $nCols, $cols, $maximos);
    }

    $scores = [];
    for ($i = 0; $i < $nCols; $i++) {
      if (empty($tokens)) { $scores = []; break; }
      $tk = (string) array_pop($tokens);
      if (!self::is_numeric_token($tk)) { $scores = []; break; }
      array_unshift($scores, (float) str_replace(',', '.', $tk));
    }
    if (count($scores) !== $nCols) {
      return self::parse_medicina_row_line_by_numeric_tail($ci, (string) $after, $nCols, $cols, $maximos);
    }

    $puntajes = [];
    foreach ($cols as $idx => $c) {
      $v = isset($scores[$idx]) ? (float) $scores[$idx] : 0.0;
      $puntajes[$c] = $v;
      if (!isset($maximos[$c]) || $v > $maximos[$c]) $maximos[$c] = $v;
    }
    return ['ci' => $ci, 'puntajes' => $puntajes];
  }

  /**
   * Fallback para filas con nombre "sucio" o pegado:
   * toma la cola numérica de la fila (9 materias + total) desde la derecha.
   *
   * @param array<string,float> $maximos
   * @return array{ci:string, puntajes: array<string,float>}|null
   */
  private static function parse_medicina_row_line_by_numeric_tail(string $ci, string $after, int $nCols, array $cols, array &$maximos): ?array {
    if ($ci === '' || $after === '') {
      return null;
    }
    if (!preg_match_all('/-?\d+(?:[.,]\d+)?/u', $after, $mNums)) {
      return null;
    }
    $nums = isset($mNums[0]) && is_array($mNums[0]) ? $mNums[0] : [];
    if (count($nums) < ($nCols + 1)) {
      return null;
    }
    $tail = array_slice($nums, -($nCols + 1)); // [scores..., total]
    array_pop($tail); // descarta total
    if (count($tail) !== $nCols) {
      return null;
    }
    $puntajes = [];
    foreach ($cols as $idx => $c) {
      $vRaw = isset($tail[$idx]) ? (string) $tail[$idx] : '0';
      $v = (float) str_replace(',', '.', $vRaw);
      if ($v < 0 || $v > 30) {
        return null;
      }
      $puntajes[$c] = $v;
      if (!isset($maximos[$c]) || $v > $maximos[$c]) $maximos[$c] = $v;
    }
    return ['ci' => $ci, 'puntajes' => $puntajes];
  }

  /**
   * Formato compacto típico de extracción PDF:
   *  "15521545Jean ... 99714111010101090↑"
   * N° y CI suelen ir pegados; hay que partir sin “robar” dígitos de la cédula.
   */
  private static function parse_medicina_row_compact(string $ln, int $nCols, array $cols, array &$maximos): ?array {
    $line = trim((string) $ln);
    if ($line === '' || !preg_match('/^\d{2,}/', $line)) return null;
    /* Cola: 9 notas (1–2 dígitos) + total (1–3). Mín. ~11 caracteres; PDFs a veces acortan. */
    if (!preg_match('/(\d{8,36})(?:\s*[↑↓=↔-])?\s*$/u', $line, $mTail, PREG_OFFSET_CAPTURE)) return null;
    $digits = (string) $mTail[1][0];
    $tailPos = (int) $mTail[1][1];
    $prefix = trim(substr($line, 0, $tailPos));
    if ($prefix === '') return null;

    $split = self::split_compact_nro_y_ci($prefix);
    if ($split === null) return null;
    $ci = self::norm_ci($split['ci']);
    if ($ci === '') return null;

    $scores = self::split_compact_tail_scores($digits, $nCols);
    if (!$scores || count($scores) !== $nCols) return null;
    $puntajes = [];
    foreach ($cols as $idx => $c) {
      $v = isset($scores[$idx]) ? (float) $scores[$idx] : 0.0;
      $puntajes[$c] = $v;
      if (!isset($maximos[$c]) || $v > $maximos[$c]) $maximos[$c] = $v;
    }
    return ['ci' => $ci, 'puntajes' => $puntajes];
  }

  /**
   * Divide cola compacta en [scores..., total] por backtracking.
   * @return array<int,float>|null solo scores (sin total)
   */
  private static function split_compact_tail_scores(string $digits, int $nScores): ?array {
    $digits = preg_replace('/\D+/', '', (string) $digits);
    if (!is_string($digits) || $digits === '') return null;
    $n = strlen($digits);
    for ($totLen = 4; $totLen >= 1; $totLen--) {
      if ($n <= $totLen) continue;
      $total = (int) substr($digits, -$totLen);
      if ($total > 999) {
        continue;
      }
      $head = substr($digits, 0, -$totLen);
      if ($head === '') continue;
      $memo = [];
      $vals = self::split_compact_scores_rec($head, 0, $nScores, $memo);
      if ($vals === null) continue;
      $sum = 0;
      foreach ($vals as $v) $sum += $v;
      if ($sum === $total || abs($sum - $total) <= 5) {
        return array_map('floatval', $vals);
      }
    }
    return null;
  }

  /**
   * @param array<string, array<int,int>|null> $memo
   * @return array<int,int>|null
   */
  private static function split_compact_scores_rec(string $s, int $pos, int $remaining, array &$memo): ?array {
    $key = $pos . ':' . $remaining;
    if (array_key_exists($key, $memo)) return $memo[$key];
    $len = strlen($s);
    $leftChars = $len - $pos;
    if ($remaining === 0) {
      return $memo[$key] = ($leftChars === 0 ? [] : null);
    }
    if ($leftChars < $remaining || $leftChars > ($remaining * 2)) {
      return $memo[$key] = null;
    }
    foreach ([1, 2] as $take) {
      if (($pos + $take) > $len) continue;
      $chunk = substr($s, $pos, $take);
      if ($chunk === '' || !preg_match('/^\d+$/', $chunk)) continue;
      $v = (int) $chunk;
      if ($v < 0 || $v > 20) continue;
      $rest = self::split_compact_scores_rec($s, $pos + $take, $remaining - 1, $memo);
      if ($rest !== null) {
        array_unshift($rest, $v);
        return $memo[$key] = $rest;
      }
    }
    return $memo[$key] = null;
  }

  /**
   * Extrae texto básico de un PDF leyendo streams y operadores Tj/TJ.
   * No pretende ser un parser completo, pero cubre reportes tabulares.
   */
  private static function extract_text_from_pdf(string $pdfRaw): string {
    $out = [];
    if (!preg_match_all('/stream[\r\n](.*?)endstream/s', $pdfRaw, $m, PREG_OFFSET_CAPTURE)) {
      return '';
    }
    foreach ($m[1] as $chunk) {
      $stream = (string) $chunk[0];
      $offset = (int) $chunk[1];
      $dict = self::pdf_nearby_dict($pdfRaw, $offset);
      $decoded = $stream;
      if (stripos($dict, '/FlateDecode') !== false) {
        $decodedTry = @gzuncompress($stream);
        if ($decodedTry === false) $decodedTry = @gzinflate($stream);
        if ($decodedTry === false) $decodedTry = @gzinflate(substr($stream, 2));
        if ($decodedTry !== false && is_string($decodedTry)) $decoded = $decodedTry;
      }
      if (strpos($decoded, 'Tj') === false && strpos($decoded, 'TJ') === false) {
        continue;
      }
      $txt = self::pdf_stream_text_operators($decoded);
      if (trim($txt) !== '') $out[] = $txt;
    }
    return trim(implode("\n", $out));
  }

  private static function pdf_nearby_dict(string $pdfRaw, int $streamCaptureOffset): string {
    $start = max(0, $streamCaptureOffset - 1500);
    $probe = substr($pdfRaw, $start, 1500);
    if (!is_string($probe) || $probe === '') return '';
    if (preg_match('/<<(.*?)>>\s*$/s', $probe, $m)) {
      return (string) $m[1];
    }
    return '';
  }

  private static function pdf_stream_text_operators(string $stream): string {
    $parts = [];
    // Literales: (texto) Tj
    if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)\s*Tj/s', $stream, $m1)) {
      foreach ($m1[0] as $tok) {
        if (preg_match('/^\((.*)\)\s*Tj$/s', $tok, $mm)) {
          $parts[] = self::pdf_unescape_literal((string) $mm[1]);
        }
      }
    }
    // Hex: <....> Tj
    if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/s', $stream, $mh)) {
      foreach ($mh[1] as $hex) {
        $t = self::pdf_decode_hex_text((string) $hex);
        if ($t !== '') $parts[] = $t;
      }
    }
    // Literales en array: [ ... ] TJ
    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $m2)) {
      foreach ($m2[1] as $arr) {
        $line = '';
        if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)/s', (string) $arr, $lit)) {
          foreach ($lit[0] as $l) {
            $line .= self::pdf_unescape_literal(substr((string) $l, 1, -1));
          }
        }
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', (string) $arr, $hexes)) {
          foreach ($hexes[1] as $hx) {
            $line .= self::pdf_decode_hex_text((string) $hx);
          }
        }
        if ($line !== '') $parts[] = $line;
      }
    }
    // Operadores de comilla simple/doble: (texto)' y (texto)"
    if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)\s*[\'\"]/s', $stream, $mq)) {
      foreach ($mq[0] as $tok) {
        if (preg_match('/^\((.*)\)\s*[\'\"]$/s', $tok, $mm)) {
          $parts[] = self::pdf_unescape_literal((string) $mm[1]);
        }
      }
    }
    return implode("\n", $parts);
  }

  private static function pdf_unescape_literal(string $s): string {
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
      return chr(octdec((string) $m[1]));
    }, $s);
    $map = [
      '\\\\n' => "\n",
      '\\\\r' => "\r",
      '\\\\t' => "\t",
      '\\\\b' => "\x08",
      '\\\\f' => "\f",
      '\\\\(' => '(',
      '\\\\)' => ')',
      '\\\\\\\\' => '\\',
    ];
    $s = strtr($s, $map);
    // Quitar códigos de control típicos.
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
  }

  private static function pdf_decode_hex_text(string $hex): string {
    $h = preg_replace('/\s+/', '', $hex);
    if (!is_string($h) || $h === '') return '';
    if ((strlen($h) % 2) === 1) $h .= '0';
    $bin = @hex2bin($h);
    if (!is_string($bin) || $bin === '') return '';
    // UTF-16BE (común en PDFs con cmap)
    if (strlen($bin) >= 2) {
      $bom = substr($bin, 0, 2);
      if ($bom === "\xFE\xFF" || $bom === "\xFF\xFE" || preg_match('/^(?:[\x00-\x7F]\x00|\x00[\x00-\x7F]){3,}/', $bin)) {
        $enc = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        if (is_string($enc) && $enc !== '') return $enc;
      }
    }
    // Latin1 fallback
    $latin = @mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1');
    return is_string($latin) ? $latin : '';
  }

  private static function cell_int($v): int {
    if ($v === null || $v === '') {
      return 0;
    }
    if (is_numeric($v)) {
      return (int) $v;
    }
    $s = preg_replace('/[^\d-]/', '', (string) $v);
    return $s !== '' ? (int) $s : 0;
  }

  private static function table_to_matrix(\PhpOffice\PhpWord\Element\Table $table): array {
    $matrix = [];
    foreach ($table->getRows() as $row) {
      $line = [];
      foreach ($row->getCells() as $cell) {
        $line[] = self::container_text($cell);
      }
      $matrix[] = $line;
    }
    return $matrix;
  }

  /** @param array<int, array<int, mixed>> $matrix */
  private static function rows_from_matrix(array $matrix): array {
    if (count($matrix) < 2) return [];
    $header = array_shift($matrix);
    $map = self::header_map_items($header);
    if (!isset($map['orden']) || !isset($map['materia_id']) || !isset($map['enunciado'])) {
      throw new RuntimeException('Tabla Word: faltan columnas reconocibles (orden, materia_id, enunciado).');
    }
    $out = [];
    foreach ($matrix as $r) {
      if (!is_array($r)) continue;
      while (count($r) < count($header)) $r[] = '';
      $orden = self::cell_int($r[$map['orden']] ?? null);
      if ($orden <= 0) continue;
      $mid = (int) ($r[$map['materia_id']] ?? 0);
      $enun = trim((string) ($r[$map['enunciado']] ?? ''));
      if ($enun === '' && $mid <= 0) continue;
      $out[] = [
        'orden_canonico' => $orden,
        'materia_id'     => $mid,
        'enunciado'      => $enun,
        'opcion_a'       => isset($map['opcion_a']) ? trim((string) ($r[$map['opcion_a']] ?? '')) : '',
        'opcion_b'       => isset($map['opcion_b']) ? trim((string) ($r[$map['opcion_b']] ?? '')) : '',
        'opcion_c'       => isset($map['opcion_c']) ? trim((string) ($r[$map['opcion_c']] ?? '')) : '',
        'opcion_d'       => isset($map['opcion_d']) ? trim((string) ($r[$map['opcion_d']] ?? '')) : '',
        'respuesta_correcta' => isset($map['correcta']) ? (self::norm_respuesta($r[$map['correcta']] ?? 'A') ?: 'A') : 'A',
        'puntos_item'    => isset($map['puntos']) ? max(0, round((float) ($r[$map['puntos']] ?? 1), 2)) : 1.0,
      ];
    }
    return $out;
  }

  private static function parse_items_docx_via_xml(string $path): array {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('No se pudo leer DOCX: faltan PHPWord y ZipArchive en el servidor. Use Excel (.xlsx) o habilite zip.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
      throw new RuntimeException('No se pudo abrir el archivo DOCX.');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml) || trim($xml) === '') {
      throw new RuntimeException('DOCX inválido: falta word/document.xml.');
    }
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xml);
    if (!$ok) {
      throw new RuntimeException('No se pudo parsear el XML del DOCX.');
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $rows = self::parse_structured_docx_paragraphs($xp);
    if (!empty($rows)) {
      return $rows;
    }

    // Segundo intento: tabla con columnas clásicas.
    $tbl = $xp->query('//w:tbl')->item(0);
    if ($tbl) {
      $matrix = [];
      foreach ($xp->query('.//w:tr', $tbl) as $tr) {
        $row = [];
        foreach ($xp->query('./w:tc', $tr) as $tc) {
          $texts = [];
          foreach ($xp->query('.//w:t', $tc) as $t) $texts[] = $t->textContent;
          $row[] = trim(preg_replace('/\s+/', ' ', implode(' ', $texts)));
        }
        $matrix[] = $row;
      }
      if (count($matrix) >= 2) {
        return self::rows_from_matrix($matrix);
      }
    }
    return [];
  }

  /** Parsea DOCX de examen: pregunta numerada + opciones a/b/c/d(/e) con correcta resaltada. */
  private static function parse_structured_docx_paragraphs(DOMXPath $xp): array {
    $out = [];
    $cur = null;
    $curOpt = null;
    $paras = $xp->query('//w:p');
    foreach ($paras as $p) {
      $runs = self::paragraph_runs($xp, $p);
      $line = trim(preg_replace('/\s+/', ' ', implode('', array_map(function ($r) { return $r['text']; }, $runs))));
      if ($line === '') continue;

      if (preg_match('/^\s*(\d{1,3})\s*[\.\)]\s*(.+)?$/u', $line, $m)) {
        if ($cur && isset($cur['orden_canonico']) && $cur['orden_canonico'] > 0) {
          $out[] = self::finalize_structured_item($cur);
        }
        $n = (int) $m[1];
        if ($n <= 0 || $n > 200) { $cur = null; $curOpt = null; continue; }
        $cur = [
          'orden_canonico' => $n,
          'enunciado' => trim((string) ($m[2] ?? '')),
          'opciones' => ['A' => '', 'B' => '', 'C' => '', 'D' => '', 'E' => ''],
          'correcta' => '',
        ];
        $curOpt = null;
        continue;
      }

      if (!$cur) continue;
      if (preg_match('/^\s*([a-eA-E])\s*[\)\.\-:]\s*(.*)$/u', $line, $m)) {
        $curOpt = strtoupper($m[1]);
        $txt = trim((string) ($m[2] ?? ''));
        if ($txt !== '') $cur['opciones'][$curOpt] = trim(($cur['opciones'][$curOpt] . ' ' . $txt));
        if (self::line_has_mark($runs)) $cur['correcta'] = $curOpt;
        continue;
      }

      if ($curOpt && isset($cur['opciones'][$curOpt])) {
        $cur['opciones'][$curOpt] = trim($cur['opciones'][$curOpt] . ' ' . $line);
        if (self::line_has_mark($runs)) $cur['correcta'] = $curOpt;
      } else {
        $cur['enunciado'] = trim($cur['enunciado'] . ' ' . $line);
      }
    }
    if ($cur && isset($cur['orden_canonico']) && $cur['orden_canonico'] > 0) $out[] = self::finalize_structured_item($cur);
    usort($out, function ($a, $b) { return ((int)$a['orden_canonico']) <=> ((int)$b['orden_canonico']); });
    return $out;
  }

  private static function finalize_structured_item(array $cur): array {
    $n = (int) ($cur['orden_canonico'] ?? 0);
    $materia = self::medicina_materia_nombre_por_orden($n);
    $opcE = trim((string) ($cur['opciones']['E'] ?? ''));
    $enun = trim((string) ($cur['enunciado'] ?? ''));
    if ($opcE !== '') {
      $enun .= ' [Opción E: ' . $opcE . ']';
    }
    return [
      'orden_canonico' => $n,
      'materia_id' => 0, // se completa en REST según materia nombre
      'materia_nombre' => $materia,
      'enunciado' => $enun,
      'opcion_a' => trim((string) ($cur['opciones']['A'] ?? '')),
      'opcion_b' => trim((string) ($cur['opciones']['B'] ?? '')),
      'opcion_c' => trim((string) ($cur['opciones']['C'] ?? '')),
      'opcion_d' => trim((string) ($cur['opciones']['D'] ?? '')),
      'respuesta_correcta' => self::norm_respuesta($cur['correcta'] ?? '') ?: 'A',
      'puntos_item' => 1.0,
    ];
  }

  private static function medicina_materia_nombre_por_orden(int $n): string {
    if ($n >= 1 && $n <= 10) return 'Guaraní';
    if ($n >= 11 && $n <= 20) return 'Castellano';
    if ($n >= 21 && $n <= 30) return 'Estudios paraguayos';
    if ($n >= 31 && $n <= 45) return 'Matemática';
    if ($n >= 46 && $n <= 60) return 'Física';
    if ($n >= 61 && $n <= 70) return 'Química Inorgánica';
    if ($n >= 71 && $n <= 80) return 'Química Orgánica';
    if ($n >= 81 && $n <= 90) return 'Biología';
    if ($n >= 91 && $n <= 100) return 'Anatomía';
    return '';
  }

  private static function paragraph_runs(DOMXPath $xp, DOMNode $p): array {
    $out = [];
    foreach ($xp->query('.//w:r', $p) as $r) {
      $text = '';
      foreach ($xp->query('.//w:t', $r) as $t) $text .= $t->textContent;
      if ($text === '') continue;
      $mark = false;
      $hl = $xp->query('./w:rPr/w:highlight', $r)->item(0);
      if ($hl instanceof DOMElement) {
        $val = strtolower((string) $hl->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val'));
        if ($val !== '' && $val !== 'none') $mark = true;
      }
      $shd = $xp->query('./w:rPr/w:shd', $r)->item(0);
      if ($shd instanceof DOMElement) {
        $fill = strtolower((string) $shd->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'fill'));
        if ($fill !== '' && $fill !== 'auto' && $fill !== 'ffffff') $mark = true;
      }
      $out[] = ['text' => $text, 'mark' => $mark];
    }
    return $out;
  }

  private static function line_has_mark(array $runs): bool {
    foreach ($runs as $r) {
      if (!empty($r['mark']) && trim((string)($r['text'] ?? '')) !== '') return true;
    }
    return false;
  }

  private static function container_text($container): string {
    if (!method_exists($container, 'getElements')) {
      return '';
    }
    $parts = [];
    foreach ($container->getElements() as $el) {
      $parts[] = self::element_text($el);
    }
    return trim(implode(' ', array_filter($parts)));
  }

  private static function element_text($el): string {
    if ($el instanceof \PhpOffice\PhpWord\Element\Text) {
      return (string) $el->getText();
    }
    if ($el instanceof \PhpOffice\PhpWord\Element\TextRun) {
      return $el->getText();
    }
    if ($el instanceof \PhpOffice\PhpWord\Element\TextBreak) {
      return ' ';
    }
    if ($el instanceof \PhpOffice\PhpWord\Element\Table) {
      /* nested */
      $rows = [];
      foreach ($el->getRows() as $r) {
        $cells = [];
        foreach ($r->getCells() as $c) {
          $cells[] = self::container_text($c);
        }
        $rows[] = implode(' | ', $cells);
      }
      return implode("\n", $rows);
    }
    if (method_exists($el, 'getElements')) {
      return self::container_text($el);
    }
    return '';
  }
}
