<?php
if (!defined('ABSPATH')) exit;

class NC_Roles {

  /**
   * Inicializar hooks para prevenir bucles de redirección
   */
  public static function init() {
    // Limpiar redirect_to anidados después del login exitoso
    add_action('wp_login', [__CLASS__, 'cleanup_redirect_after_login'], 10, 2);
    add_action('login_redirect', [__CLASS__, 'prevent_redirect_loop'], 1, 3);
    
    // Hook específico para Nextend Social Login - interceptar antes de enforce_access_or_die
    add_action('template_redirect', [__CLASS__, 'handle_post_social_login'], 1);
  }

  /**
   * Limpiar redirect_to anidados después del login
   */
  public static function cleanup_redirect_after_login($user_login, $user) {
    // Si hay redirect_to en la URL con múltiples niveles de anidación, limpiarlo
    if (isset($_GET['redirect_to'])) {
      $redirect_to = $_GET['redirect_to'];
      $redirect_count = substr_count($redirect_to, 'redirect_to');
      
      // Si hay más de 2 redirect_to anidados, limpiar
      if ($redirect_count > 2) {
        // Extraer solo el path base sin parámetros redirect_to
        $parsed = parse_url($redirect_to);
        $clean_path = $parsed['path'] ?? '/';
        $_GET['redirect_to'] = home_url($clean_path);
      }
    }
  }

  /**
   * Prevenir bucles de redirección en login_redirect
   */
  public static function prevent_redirect_loop($redirect_to, $requested_redirect_to, $user) {
    if (empty($redirect_to)) {
      return $redirect_to;
    }
    
    // Contar redirect_to anidados
    $redirect_count = substr_count($redirect_to, 'redirect_to');
    
    // Si hay más de 2 redirect_to anidados, limpiar y redirigir a home
    if ($redirect_count > 2) {
      $parsed = parse_url($redirect_to);
      $clean_path = $parsed['path'] ?? '/';
      return home_url($clean_path);
    }
    
    return $redirect_to;
  }
  /**
   * Manejar redirecciones después del login social (Nextend Social Login)
   * Se ejecuta antes de enforce_access_or_die para limpiar redirect_to anidados
   */
  public static function handle_post_social_login() {
    // Detectar si venimos de Nextend Social Login
    $is_social_login = isset($_GET['loginSocial']) || 
                       (isset($_GET['action']) && $_GET['action'] === 'loginSocial');
    
    if ($is_social_login && is_user_logged_in()) {
      // Limpiar redirect_to anidados de la URL actual
      if (isset($_GET['redirect_to'])) {
        $redirect_to = $_GET['redirect_to'];
        $redirect_count = substr_count($redirect_to, 'redirect_to');
        
        if ($redirect_count > 1) {
          // Limpiar y redirigir a URL base sin redirect_to anidados
          $parsed = parse_url($redirect_to);
          $clean_path = $parsed['path'] ?? '/';
          wp_safe_redirect(home_url($clean_path));
          exit;
        }
      }
    }
  }

  /**
   * ✅ RECOMENDADO: Slugs reales de roles permitidos
   * (esto es lo más estable: no depende del nombre visible)
   */
  public static function allowed_role_slugs(): array {
    return [
      'administrator',
      'Administrator',
      'funcionarios_de_oficina',
      'funcionarios_administrativos',
      'funcionarios-administrativos',
      'direccion',
      'docente',
      // 👇 Agregá aquí los slugs reales de tus roles custom:
      // Ejemplos típicos (cambian según plugin):
    ];
  }

  /**
   * Roles administrativos (pueden ver todo y editar configuraciones)
   */
  public static function admin_role_slugs(): array {
    return [
      'administrator',
      'Administrator',
      'funcionarios_administrativos',
      'funcionarios-administrativos',
      'direccion',
    ];
  }

  /**
   * Verifica si el usuario tiene permisos administrativos
   */
  public static function user_is_admin(?int $user_id = null): bool {
    if (current_user_can('manage_options')) return true;
    
    if (!is_user_logged_in()) return false;

    $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
    if (!$user || empty($user->roles)) return false;

    // Normalizar a minúsculas para comparar slugs de forma robusta
    $admin_slugs = array_map('strtolower', self::admin_role_slugs());
    
    // Check por SLUG
    foreach ($user->roles as $role_slug) {
      if (in_array(strtolower((string) $role_slug), $admin_slugs, true)) return true;
    }

    // Fallback por NOMBRE visible
    global $wp_roles;
    if (!$wp_roles) $wp_roles = wp_roles();

    $admin_names = [
      'Funcionarios Administrativos',
      'Funcionarios administrativos',
      'Direccion',
      'Administrator',
    ];
    $admin_names_lc = array_map('strtolower', $admin_names);

    foreach ($user->roles as $role_slug) {
      $role_obj  = $wp_roles->roles[$role_slug] ?? null;
      $role_name = $role_obj['name'] ?? null;
      $role_name_norm = $role_name !== null ? strtolower((string) $role_name) : '';
      if ($role_name_norm && in_array($role_name_norm, $admin_names_lc, true)) return true;
    }

    return false;
  }

  /**
   * Puede ver la sección "Reportes de asistencia" (solo admin/funcionarios administrativos/dirección).
   * Docentes y funcionarios de oficina no ven Reportes.
   */
  public static function user_can_view_reportes_asistencia(?int $user_id = null): bool {
    return self::user_is_admin($user_id);
  }

  /** Docente o funcionario de oficina (solo pueden editar/eliminar lo que ellos registraron). */
  public static function user_is_docente_or_oficina(?int $user_id = null): bool {
    if (!is_user_logged_in() && !$user_id) return false;
    $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
    if (!$user || empty($user->roles)) return false;

    $slugs = array_map('strtolower', [
      'docente',
      'funcionarios_de_oficina',
      'funcionario_de_oficina',
      'funcionarios-de-oficina',
    ]);
    foreach ($user->roles as $role_slug) {
      if (in_array(strtolower((string) $role_slug), $slugs, true)) return true;
    }

    global $wp_roles;
    if (!$wp_roles) $wp_roles = wp_roles();
    $names = array_map('strtolower', ['Docente', 'Funcionarios de Oficina', 'Funcionario de Oficina']);
    foreach ($user->roles as $role_slug) {
      $role_obj = $wp_roles->roles[$role_slug] ?? null;
      $role_name = isset($role_obj['name']) ? strtolower((string) $role_obj['name']) : '';
      if ($role_name && in_array($role_name, $names, true)) return true;
    }
    return false;
  }

  /**
   * Puede editar/eliminar cualquier asistencia (administración, dirección, etc.).
   * No aplica a docente ni funcionario de oficina.
   */
  public static function user_can_manage_all_attendance(?int $user_id = null): bool {
    return self::user_is_admin($user_id);
  }

  /**
   * Fallback (compatibilidad): nombres visibles del rol
   * Útil si tus roles vienen de plugins que no controlás o cambian slugs.
   */
  public static function allowed_role_names(): array {
    return [
      'Funcionarios de Oficina',
      'Funcionarios Administrativos',
      'Direccion',
      'Administrator',
      'Docente',
    ];
  }

  public static function user_can_access(?int $user_id = null): bool {
    // Admin WP siempre pasa
    if (current_user_can('manage_options')) return true;

    if (!is_user_logged_in()) return false;

    $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
    if (!$user || empty($user->roles)) return false;

    // Normalizar slugs a minúsculas para tolerar variaciones
    $allowed_slugs = array_map('strtolower', self::allowed_role_slugs());

    // ✅ 1) Check por SLUG (estable)
    foreach ($user->roles as $role_slug) {
      if (in_array(strtolower((string) $role_slug), $allowed_slugs, true)) return true;
    }

    // ✅ 2) Fallback por NOMBRE visible (menos estable pero útil)
    global $wp_roles;
    if (!$wp_roles) $wp_roles = wp_roles();

    $allowed_names = self::allowed_role_names();
    $allowed_names_lc = array_map('strtolower', $allowed_names);

    foreach ($user->roles as $role_slug) {
      $role_obj  = $wp_roles->roles[$role_slug] ?? null;
      $role_name = $role_obj['name'] ?? null;
      $role_name_norm = $role_name !== null ? strtolower((string) $role_name) : '';
      if ($role_name_norm && in_array($role_name_norm, $allowed_names_lc, true)) return true;
    }

    // 🧪 Debug opcional (descomentá si necesitás ver qué roles tiene el user)
    error_log('[NC_Roles] roles usuario: ' . json_encode($user->roles));

    return false;
  }

  public static function enforce_access_or_die() {
    // Nunca bloquear wp-admin (editor, previsualización, etc.)
    if (is_admin()) return;

    // Detectar si estamos en proceso de login social (Nextend Social Login)
    $is_social_login_process = isset($_GET['loginSocial']) || 
                                (isset($_GET['action']) && $_GET['action'] === 'loginSocial');
    
    // Si estamos en proceso de login social y el usuario está logueado, dar tiempo para establecer sesión
    if ($is_social_login_process && is_user_logged_in()) {
      // Verificar acceso - si tiene acceso, permitir continuar
      if (self::user_can_access()) {
        return;
      }
      // Si no tiene acceso pero está logueado, mostrar error (no redirigir)
      wp_die('No tenés permisos para acceder a esta sección.', 'Acceso restringido', ['response' => 403]);
    }

    if (self::user_can_access()) return;

    // Si el usuario está logueado pero no tiene acceso, mostrar error (no redirigir)
    if (is_user_logged_in()) {
      wp_die('No tenés permisos para acceder a esta sección.', 'Acceso restringido', ['response' => 403]);
    }

    // Usuario no logueado: redirigir a login
    // Prevenir bucle de redirección: detectar si ya hay redirect_to anidados en la URL
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
      . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    // Contar cuántas veces aparece "redirect_to" en la URL para detectar bucles
    $redirect_count = substr_count($current_url, 'redirect_to');
    
    // Si hay múltiples redirect_to anidados (más de 1), limpiar y redirigir a home
    if ($redirect_count > 1) {
      wp_safe_redirect(home_url('/'));
      exit;
    }
    
    // Extraer solo el path sin query parameters para evitar redirect_to anidados
    $parsed_url = parse_url($current_url);
    $clean_path = $parsed_url['path'] ?? '/';
    
    // Si el path contiene wp-login.php o login, evitar redirección adicional
    if (strpos($clean_path, 'wp-login.php') !== false || strpos($clean_path, '/login') !== false) {
      wp_safe_redirect(home_url('/'));
      exit;
    }
    
    // Construir URL limpia sin parámetros redirect_to
    $clean_url = home_url($clean_path);
    
    // Redirigir a login con URL limpia (sin redirect_to anidados)
    wp_safe_redirect(wp_login_url($clean_url));
    exit;
  }
}