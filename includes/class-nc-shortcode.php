<?php
if (!defined('ABSPATH')) exit;

class NC_Shortcode {

  public static function init() {
    add_shortcode('newton_opm_app', [__CLASS__, 'render']);
    add_shortcode('newton_conducta_app', [__CLASS__, 'render']); // alias legacy

    add_filter('body_class', [__CLASS__, 'body_class']);

    add_action('wp_enqueue_scripts', function () {
      if (!is_page()) return;

      global $post;
      if (!$post) return;

      // Cargar en la página donde está el shortcode (slug "opm" o legacy "conducta")
      $allowed_slugs = ['opm', 'conducta'];
      if (!in_array($post->post_name, $allowed_slugs, true)) return;

      // Bloquear acceso a nivel servidor (página)
      NC_Roles::enforce_access_or_die();

      $js  = NC_URL . 'assets/dist/app.js';
      $css = NC_URL . 'assets/dist/app.css';
      $state_js = NC_URL . 'assets/dist/nc-app-state.js';
      $asistencia_js = NC_URL . 'assets/dist/asistencia.js';
      $examenes_js = NC_URL . 'assets/dist/examenes.js';

      $js_path  = NC_PATH . 'assets/dist/app.js';
      $css_path = NC_PATH . 'assets/dist/app.css';
      $state_js_path = NC_PATH . 'assets/dist/nc-app-state.js';
      $asistencia_js_path = NC_PATH . 'assets/dist/asistencia.js';
      $examenes_js_path = NC_PATH . 'assets/dist/examenes.js';

      $js_ver  = file_exists($js_path) ? filemtime($js_path) : NC_VERSION;
      $css_ver = file_exists($css_path) ? filemtime($css_path) : NC_VERSION;
      $state_ver = file_exists($state_js_path) ? filemtime($state_js_path) : NC_VERSION;
      $asistencia_ver = file_exists($asistencia_js_path) ? filemtime($asistencia_js_path) : NC_VERSION;
      $examenes_ver = file_exists($examenes_js_path) ? filemtime($examenes_js_path) : NC_VERSION;

      wp_enqueue_style('nc-app', $css, [], $css_ver);
      wp_enqueue_script('heic2any', 'https://unpkg.com/heic2any@0.0.4/dist/heic2any.min.js', [], '0.0.4', true);
      wp_enqueue_script('nc-app-state', $state_js, [], $state_ver, true);
      wp_enqueue_script('nc-app', $js, ['heic2any', 'nc-app-state'], $js_ver, true);
      wp_enqueue_script('nc-asistencia', $asistencia_js, ['nc-app'], $asistencia_ver, true);
      wp_enqueue_script('nc-examenes', $examenes_js, ['nc-app'], $examenes_ver, true);
      // Cropper.js para recorte de imágenes
      //wp_enqueue_style('cropperjs', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css', [], '1.6.1');
      //wp_enqueue_script('cropperjs', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js', [], '1.6.1', true);

      $user = wp_get_current_user();
      $display_name = '';
      $role_label = '';
      $avatar_url = '';
      if ($user && (int) $user->ID > 0) {
        $display_name = trim((string) ($user->first_name ?: $user->display_name));
        $role_label = self::role_label_for_user($user);
        $avatar_url = get_avatar_url($user->ID, ['size' => 64]) ?: '';
      }

      wp_localize_script('nc-app', 'NC_APP', [
        'apiUrl'        => '/wp-json/conducta/v1',
        'nonce'         => wp_create_nonce('wp_rest'),
        'siteUrl'       => esc_url_raw(site_url('/')),
        'currentUserId' => get_current_user_id(),
        'displayName'   => $display_name,
        'roleLabel'     => $role_label,
        'avatarUrl'     => $avatar_url,
      ]);
    });
  }

  public static function is_opm_page($post = null): bool {
    if (!$post) {
      if (!is_page()) return false;
      global $post;
    }
    if (!$post) return false;
    return in_array($post->post_name, ['opm', 'conducta'], true);
  }

  public static function body_class(array $classes): array {
    global $post;
    if (self::is_opm_page($post)) {
      $classes[] = 'opm-app-page';
    }
    return $classes;
  }

  public static function role_label_for_user($user): string {
    if (!$user || empty($user->roles)) return '';
    global $wp_roles;
    if (!$wp_roles) $wp_roles = wp_roles();
    $slug = (string) $user->roles[0];
    if (strtolower($slug) === 'administrator') return 'Administrador';
    $name = $wp_roles->roles[$slug]['name'] ?? '';
    return $name !== '' ? (string) $name : $slug;
  }

  public static function render() {
    // Bloquear acceso (shortcode también)
    NC_Roles::enforce_access_or_die();
    return '<div id="opm-root"></div><script>(function(){try{if(localStorage.getItem("opm-theme")==="dark")document.body.classList.add("opm-theme-dark")}catch(e){}})();</script>';
  }
}
