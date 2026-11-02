<?php
if (!defined('ABSPATH')) exit;

/**
 * Importa listas de simulacro desde un Excel con varias aulas lado a lado.
 * Cada celda "AULA K" (u otra aula física) abre una lista; debajo van los nombres.
 */
class NC_Simulacro_Import {

  public static function preview(WP_REST_Request $req) {
    $files = $req->get_file_params();
    if (empty($files['excel']) || !empty($files['excel']['error'])) {
      return new WP_Error('nc_error', 'No se recibió el archivo Excel.', ['status' => 400]);
    }
    $file = $files['excel'];
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) {
      return new WP_Error('nc_error', 'El archivo tiene que ser Excel (.xlsx, .xls).', ['status' => 400]);
    }
    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_readable($path)) {
      return new WP_Error('nc_error', 'No se pudo leer el archivo subido.', ['status' => 400]);
    }

    $autoload = defined('NC_PATH') ? NC_PATH . 'vendor/autoload.php' : '';
    if ($autoload !== '' && is_file($autoload)) {
      require_once $autoload;
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
      return new WP_Error('nc_error', 'Falta PhpSpreadsheet para leer Excel. Ejecutá composer install en el plugin.', ['status' => 503]);
    }

    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    try {
      $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
      if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
      }
      if (method_exists($reader, 'setReadEmptyCells')) {
        $reader->setReadEmptyCells(false);
      }
      $spreadsheet = $reader->load($path);
    } catch (Throwable $e) {
      return new WP_Error('nc_error', 'No se pudo abrir el Excel. Comprobá que el archivo no esté dañado.', ['status' => 400]);
    }

    $parsed = [];
    $sheets = [];
    $active = $spreadsheet->getActiveSheet();
    if ($active) $sheets[] = $active;
    foreach ($spreadsheet->getAllSheets() as $sheet) {
      if ($active && $sheet === $active) continue;
      $sheets[] = $sheet;
    }
    foreach ($sheets as $sheet) {
      $rows = $sheet->toArray(null, true, true, false);
      if (!is_array($rows) || !$rows) continue;
      if (count($rows) > 2500) $rows = array_slice($rows, 0, 2500);
      $lists = self::parse_grid($rows);
      foreach ($lists as $list) {
        $list['hoja'] = (string) $sheet->getTitle();
        $parsed[] = $list;
      }
      // En el formato típico todas las aulas están en la primera hoja.
      if ($parsed) break;
    }
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    if (!$parsed) {
      return new WP_Error('nc_error', 'No encontré listas. Cada aula tiene que estar en una celda que diga AULA y el código, por ejemplo AULA K, y los estudiantes debajo.', ['status' => 400]);
    }

    $aulas = self::aulas_por_codigo();
    $students = self::load_students();
    $index = self::index_students($students);

    $out = [];
    foreach ($parsed as $list) {
      $code = (string) $list['code'];
      $opciones = $aulas[$code] ?? [];
      $aula_id = count($opciones) === 1 ? (int) $opciones[0]['id'] : 0;
      $aula_nombre = count($opciones) === 1 ? (string) $opciones[0]['nombre'] : '';
      $alumnos = [];
      $vistos = [];
      foreach ($list['alumnos'] as $nombre) {
        $row = self::match_nombre($nombre, $students, $index);
        if ($row['estado'] === 'ok' && !empty($row['alumno_id'])) {
          $id = (int) $row['alumno_id'];
          if (isset($vistos[$id])) {
            $row['estado'] = 'repetido';
            $row['omitir'] = true;
          } else {
            $vistos[$id] = true;
            $row['omitir'] = false;
          }
        } else {
          $row['omitir'] = false;
        }
        $alumnos[] = $row;
      }
      $out[] = [
        'hoja' => (string) ($list['hoja'] ?? ''),
        'aula_codigo' => $code,
        'aula_id' => $aula_id,
        'aula_nombre' => $aula_nombre,
        'fecha' => (string) ($list['fecha'] ?? ''),
        'alumnos' => $alumnos,
      ];
    }

    return ['listas' => $out];
  }

  /** @param array<int, array<int, mixed>> $rows */
  private static function parse_grid(array $rows): array {
    $headers = [];
    foreach ($rows as $r => $row) {
      if (!is_array($row)) continue;
      foreach ($row as $c => $value) {
        $header = self::aula_header(self::cell_to_text($value));
        if (!$header) continue;
        $fecha = $header['fecha'];
        if ($fecha === '') {
          for ($dc = 1; $dc <= 3; $dc++) {
            $t = self::row_cell($row, (int) $c + $dc);
            if (self::is_fecha_texto($t)) { $fecha = $t; break; }
            if ($t !== '' && self::aula_header($t)) break;
          }
        }
        $headers[] = ['r' => (int) $r, 'c' => (int) $c, 'code' => $header['code'], 'fecha' => $fecha];
      }
    }

    $lists = [];
    foreach ($headers as $h) {
      $nameCol = self::pick_name_column($rows, $h);
      $alumnos = [];
      $blanks = 0;
      $started = false;
      $max = count($rows);
      for ($r = $h['r'] + 1; $r < $max; $r++) {
        $row = is_array($rows[$r] ?? null) ? $rows[$r] : [];
        $raw = self::row_cell($row, $nameCol);
        if (self::aula_header($raw)) break;
        $name = self::clean_student_name($raw);
        if ($name === '') {
          $blanks++;
          if ($started && $blanks >= 2) break;
          if (!$started && $blanks >= 6) break;
          continue;
        }
        $blanks = 0;
        $started = true;
        $alumnos[] = $name;
        if (count($alumnos) >= 800) break;
      }
      if ($alumnos) {
        $lists[] = ['code' => $h['code'], 'fecha' => $h['fecha'], 'alumnos' => $alumnos];
      }
    }
    return $lists;
  }

  private static function pick_name_column(array $rows, array $h): int {
    $cols = [$h['c'], $h['c'] + 1];
    if ($h['c'] > 0) $cols[] = $h['c'] - 1;
    $best = (int) $h['c'];
    $bestScore = -1;
    $limit = min(count($rows), $h['r'] + 15);
    foreach ($cols as $c) {
      $score = 0;
      for ($r = $h['r'] + 1; $r < $limit; $r++) {
        $row = is_array($rows[$r] ?? null) ? $rows[$r] : [];
        if (self::clean_student_name(self::row_cell($row, (int) $c)) !== '') $score++;
      }
      if ($score > $bestScore) {
        $bestScore = $score;
        $best = (int) $c;
      }
    }
    return $best;
  }

  private static function row_cell(array $row, int $c): string {
    if ($c < 0 || !array_key_exists($c, $row)) return '';
    return self::cell_to_text($row[$c]);
  }

  private static function cell_to_text($value): string {
    if ($value instanceof DateTimeInterface) {
      return $value->format('d/m/Y');
    }
    if (is_numeric($value)) {
      $n = (float) $value;
      if ($n > 20000 && $n < 80000 && class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) {
        try {
          $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
          return $dt->format('d/m/Y');
        } catch (Throwable $e) {
          // Sigue como texto.
        }
      }
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      $value = (string) $value;
    }
    return self::cell_text($value);
  }

  private static function cell_text($value): string {
    $s = str_replace("\xc2\xa0", ' ', (string) $value);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return is_string($s) ? $s : trim((string) $value);
  }

  private static function is_fecha_texto(string $text): bool {
    $s = self::cell_text($text);
    if ($s === '') return false;
    if (preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}$/', $s)) return true;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return true;
    if (preg_match('/^\d{1,2}-[A-Za-z]{3}-?\d{2,4}$/', $s)) return true;
    return false;
  }

  /** @return array{code:string,fecha:string}|null */
  private static function aula_header(string $text): ?array {
    $s = self::cell_text($text);
    if ($s === '' || strpos($s, ',') !== false) return null;
    if (!preg_match('/^aula\s*[:\-]?\s*["\'(]*\s*([a-z0-9]{1,6})\s*["\')]?\s*(.*)$/iu', $s, $m)) {
      return null;
    }
    $rest = trim((string) ($m[2] ?? ''));
    if ($rest !== '' && !self::is_fecha_texto($rest)) return null;
    return [
      'code' => strtoupper((string) $m[1]),
      'fecha' => self::is_fecha_texto($rest) ? $rest : '',
    ];
  }

  private static function clean_student_name(string $text): string {
    $s = self::cell_text($text);
    if ($s === '' || self::aula_header($s) || self::is_fecha_texto($s)) return '';
    $s = preg_replace('/^\d{1,3}\s*[\.\)\-:]\s*/u', '', $s);
    $s = preg_replace('/^\d{1,3}\s+/u', '', (string) $s);
    $s = trim((string) $s);
    if (!preg_match('/[a-záéíóúüñ]/iu', $s)) return '';
    if (mb_strlen($s) < 3) return '';
    return $s;
  }

  private static function aulas_por_codigo(): array {
    global $wpdb;
    $t = NC_DB::table_grupos();
    $rows = $wpdb->get_results("SELECT id, nombre FROM $t WHERE activo=1 ORDER BY nombre ASC", ARRAY_A) ?: [];
    $map = [];
    foreach ($rows as $r) {
      $code = self::aula_codigo((string) ($r['nombre'] ?? ''));
      if ($code === '') continue;
      if (!isset($map[$code])) $map[$code] = [];
      $map[$code][] = [
        'id' => (int) $r['id'],
        'nombre' => (string) ($r['nombre'] ?? ''),
        'codigo' => $code,
      ];
    }
    return $map;
  }

  private static function aula_codigo(string $nombre): string {
    $raw = trim($nombre);
    if ($raw === '') return '';
    $left = trim(explode('->', $raw, 2)[0]);
    $left = trim($left, " \t\"'()");
    if (preg_match('/^([A-Za-z0-9]{1,6})\b/u', $left, $m)) return strtoupper($m[1]);
    return '';
  }

  private static function load_students(): array {
    global $wpdb;
    $t = $wpdb->prefix . 'conducta_alumnos';
    // Solo campos necesarios para el match; evita SELECT *.
    $rows = $wpdb->get_results(
      "SELECT id, nombres, apellidos, ci FROM $t WHERE activo=1 ORDER BY id ASC LIMIT 20000",
      ARRAY_A
    );
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
      $nombres = trim((string) ($r['nombres'] ?? ''));
      $apellidos = trim((string) ($r['apellidos'] ?? ''));
      $tokens = self::tokens(trim($apellidos . ' ' . $nombres));
      if (!$tokens) $tokens = self::tokens(trim($nombres . ' ' . $apellidos));
      if (!$tokens) continue;
      $keyTokens = $tokens;
      sort($keyTokens, SORT_STRING);
      $out[] = [
        'id' => (int) $r['id'],
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'ci' => trim((string) ($r['ci'] ?? '')),
        'tokens' => $tokens,
        'key' => implode(' ', $keyTokens),
      ];
    }
    return $out;
  }

  private static function index_students(array $students): array {
    $byKey = [];
    $byPrefix = [];
    foreach ($students as $i => $st) {
      $byKey[$st['key']][] = $i;
      foreach ($st['tokens'] as $tok) {
        if (strlen($tok) >= 3) {
          $pre = substr($tok, 0, 3);
          $byPrefix[$pre][] = $i;
        }
      }
    }
    return ['byKey' => $byKey, 'byPrefix' => $byPrefix];
  }

  private static function match_nombre(string $nombre, array $students, array $index): array {
    [$apellidos, $nombres] = self::split_nombre($nombre);
    $tokens = self::tokens($nombre);
    $keyTokens = $tokens;
    sort($keyTokens, SORT_STRING);
    $key = implode(' ', $keyTokens);
    $base = [
      'nombre_archivo' => $nombre,
      'apellidos' => $apellidos,
      'nombres' => $nombres,
      'estado' => 'nuevo',
      'alumno_id' => 0,
      'alumno' => null,
      'candidatos' => [],
    ];
    if ($key !== '' && !empty($index['byKey'][$key])) {
      $idxs = array_values(array_unique($index['byKey'][$key]));
      $cands = [];
      foreach ($idxs as $i) $cands[] = self::student_public($students[$i]);
      if (count($cands) === 1) {
        $base['estado'] = 'ok';
        $base['alumno_id'] = (int) $cands[0]['id'];
        $base['alumno'] = $cands[0];
        return $base;
      }
      $base['estado'] = 'revisar';
      $base['candidatos'] = $cands;
      return $base;
    }

    $pool = [];
    // Prioriza el apellido (primer token) para achicar candidatos.
    $priority = [];
    if (!empty($tokens[0]) && strlen($tokens[0]) >= 3) {
      $priority[] = $tokens[0];
    }
    foreach ($tokens as $tok) {
      if (!in_array($tok, $priority, true)) $priority[] = $tok;
    }
    foreach ($priority as $tok) {
      if (strlen($tok) < 3) continue;
      $pre = substr($tok, 0, 3);
      foreach ($index['byPrefix'][$pre] ?? [] as $i) {
        $pool[$i] = true;
        if (count($pool) >= 120) break 2;
      }
    }
    $scored = [];
    foreach (array_keys($pool) as $i) {
      $score = self::score_tokens($tokens, $students[$i]['tokens']);
      if ($score >= 0.78) $scored[] = ['i' => $i, 'score' => $score];
    }
    usort($scored, static function ($a, $b) {
      return $b['score'] <=> $a['score'];
    });
    $scored = array_slice($scored, 0, 5);
    if (!$scored) return $base;
    $base['estado'] = 'revisar';
    foreach ($scored as $s) $base['candidatos'][] = self::student_public($students[$s['i']]);
    return $base;
  }

  private static function score_tokens(array $fileToks, array $stuToks): float {
    if (!$fileToks || !$stuToks) return 0.0;
    $sum = 0.0;
    foreach ($fileToks as $idx => $tok) {
      $hit = self::token_hit($tok, $stuToks);
      if ($idx === 0 && $hit <= 0) return 0.0;
      $sum += $hit;
    }
    return $sum / count($fileToks);
  }

  private static function token_hit(string $fileTok, array $stuToks): float {
    foreach ($stuToks as $st) {
      if ($fileTok === $st) return 1.0;
    }
    if (strlen($fileTok) < 4) return 0.0;
    foreach ($stuToks as $st) {
      if (strlen($st) < 4) continue;
      if (abs(strlen($fileTok) - strlen($st)) > 1) continue;
      if (levenshtein($fileTok, $st) <= 1) return 0.85;
    }
    return 0.0;
  }

  private static function student_public(array $st): array {
    return [
      'id' => (int) $st['id'],
      'nombres' => (string) $st['nombres'],
      'apellidos' => (string) $st['apellidos'],
      'ci' => (string) $st['ci'],
    ];
  }

  /** @return array{0:string,1:string} */
  private static function split_nombre(string $full): array {
    $full = trim($full);
    if (strpos($full, ',') !== false) {
      $parts = explode(',', $full, 2);
      return [trim($parts[0]), trim($parts[1])];
    }
    $toks = preg_split('/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY);
    if (!$toks) return ['', ''];
    if (count($toks) === 1) return [$toks[0], ''];
    $nom = array_pop($toks);
    return [implode(' ', $toks), $nom];
  }

  private static function tokens(string $text): array {
    $txt = strtolower($text);
    if (function_exists('remove_accents')) {
      $txt = remove_accents($txt);
    }
    $parts = preg_split('/[^a-z0-9]+/', $txt, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_values($parts) : [];
  }
}
