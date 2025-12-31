<?php
if (!defined('ABSPATH')) exit;

require_once SR_PATH . 'includes/class-sr-settings.php';
require_once SR_PATH . 'includes/class-sr-pages.php';
require_once SR_PATH . 'includes/class-sr-auth.php';
require_once SR_PATH . 'includes/class-sr-forms.php';

class SR_Plugin {
  private static $instance = null;

  public static function instance() {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    SR_Settings::init();
    SR_Pages::init();
    SR_Auth::init();
    SR_Forms::init();

    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

    add_filter('show_admin_bar', function($show){
      if (!is_user_logged_in()) return $show;
      $u = wp_get_current_user();
      if (in_array('subscriber', (array)$u->roles, true)) return false;
      return $show;
    });

    add_action('admin_init', function () {
      if (!is_user_logged_in()) return;
      if (wp_doing_ajax()) return;
      if (current_user_can('edit_posts')) return;

      $u = wp_get_current_user();
      if (in_array('subscriber', (array)$u->roles, true)) {
        $profile = SR_Pages::get_page_url('profile');
        if ($profile) {
          wp_safe_redirect($profile);
          exit;
        }
      }
    });
  }

  public function enqueue_frontend() {
    if (!SR_Pages::is_sr_page()) return;

    // Poppins (Google Fonts)
    wp_enqueue_style(
      'sr-poppins',
      'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap',
      [],
      null
    );

    // Font Awesome
    wp_enqueue_style(
      'sr-fontawesome',
      'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
      [],
      '6.5.0'
    );

    wp_enqueue_style('sawah-register-frontend', SR_URL . 'assets/css/sr-frontend.css', ['sr-poppins','sr-fontawesome'], SR_VERSION);
    wp_enqueue_script('sawah-register-frontend', SR_URL . 'assets/js/sr-frontend.js', ['jquery'], SR_VERSION, true);

    $vars = SR_Settings::get_css_vars();
    wp_add_inline_style('sawah-register-frontend', SR_Settings::build_inline_css($vars));

    wp_localize_script('sawah-register-frontend', 'SR_VARS', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('sr_ajax'),
    ]);
  }

  public function enqueue_admin($hook) {
    if ($hook !== 'settings_page_sawah-register') return;
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_style('sawah-register-admin', SR_URL . 'assets/css/sr-admin.css', [], SR_VERSION);
  }

  public static function activate() {
    SR_Settings::ensure_defaults();
    SR_Pages::create_pages_if_missing();
    SR_Auth::add_rewrites();
    flush_rewrite_rules();
  }

  public static function deactivate() {
    flush_rewrite_rules();
  }
}
