<?php
if (!defined('ABSPATH')) exit;

class SR_Pages {

  public static function init() {
    add_shortcode('sawah_register_login', [__CLASS__, 'sc_login']);
    add_shortcode('sawah_register_signup', [__CLASS__, 'sc_signup']);
    add_shortcode('sawah_register_profile', [__CLASS__, 'sc_profile']);
    add_shortcode('sawah_register_lost', [__CLASS__, 'sc_lost']);

    // Redirect default WP URLs to our pages
    add_filter('login_url', [__CLASS__, 'filter_login_url'], 10, 3);
    add_filter('register_url', [__CLASS__, 'filter_register_url'], 10, 1);
    add_filter('lostpassword_url', [__CLASS__, 'filter_lostpassword_url'], 10, 2);
    add_filter('edit_profile_url', [__CLASS__, 'filter_profile_url'], 10, 3);

    // Hard redirect wp-login.php to our pages (keeps WP screens hidden)
    add_action('init', [__CLASS__, 'maybe_redirect_wp_login']);
  }

  public static function options() {
    return SR_Settings::get();
  }

  public static function get_page_id($key) {
    $opt = self::options();
    return absint($opt['pages'][$key] ?? 0);
  }

  public static function get_page_url($key) {
    $id = self::get_page_id($key);
    return $id ? get_permalink($id) : '';
  }

  public static function is_sr_page() {
    if (!is_page()) return false;
    $opt = self::options();
    $ids = array_map('absint', array_values($opt['pages'] ?? []));
    $pid = get_queried_object_id();
    return in_array((int)$pid, $ids, true);
  }

  public static function create_pages_if_missing() {
    $opt = SR_Settings::get();
    $pages = $opt['pages'] ?? [];

    $map = [
      'login' => ['title' => 'Login', 'shortcode' => '[sawah_register_login]'],
      'signup' => ['title' => 'Sign Up', 'shortcode' => '[sawah_register_signup]'],
      'profile' => ['title' => 'My Profile', 'shortcode' => '[sawah_register_profile]'],
      'lost' => ['title' => 'Lost Password', 'shortcode' => '[sawah_register_lost]'],
    ];

    foreach ($map as $k => $cfg) {
      $id = absint($pages[$k] ?? 0);
      if ($id && get_post($id)) continue;

      $existing = get_page_by_title($cfg['title']);
      if ($existing && !empty($existing->ID)) {
        $pages[$k] = (int)$existing->ID;
        continue;
      }

      $new_id = wp_insert_post([
        'post_title' => $cfg['title'],
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => $cfg['shortcode'],
      ]);

      if (!is_wp_error($new_id)) {
        $pages[$k] = (int)$new_id;
      }
    }

    $opt['pages'] = $pages;
    update_option(SR_Settings::OPT, $opt);
  }

  // URL filters
  public static function filter_login_url($login_url, $redirect, $force_reauth) {
    $url = self::get_page_url('login');
    if (!$url) return $login_url;
    if (!empty($redirect)) $url = add_query_arg('redirect_to', rawurlencode($redirect), $url);
    return $url;
  }

  public static function filter_register_url($register_url) {
    $url = self::get_page_url('signup');
    return $url ?: $register_url;
  }

  public static function filter_lostpassword_url($lostpassword_url, $redirect) {
    $url = self::get_page_url('lost');
    if (!$url) return $lostpassword_url;
    if (!empty($redirect)) $url = add_query_arg('redirect_to', rawurlencode($redirect), $url);
    return $url;
  }

  public static function filter_profile_url($url, $user_id, $scheme) {
    $p = self::get_page_url('profile');
    return $p ?: $url;
  }

  public static function maybe_redirect_wp_login() {
    if (is_admin()) return;
    if (!isset($_SERVER['REQUEST_URI'])) return;

    $req = wp_unslash($_SERVER['REQUEST_URI']);
    if (stripos($req, 'wp-login.php') === false) return;

    // Allow WP core logout endpoint (we’ll still redirect after it happens)
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

    // For password reset actions we handle on our Lost Password page
    if (in_array($action, ['lostpassword','rp','resetpass','register','login'], true) || $action === '') {
      // If already logged in and trying to hit wp-login, send to profile
      if (is_user_logged_in()) {
        $profile = self::get_page_url('profile');
        if ($profile) { wp_safe_redirect($profile); exit; }
      }

      if ($action === 'register') {
        $u = self::get_page_url('signup');
        if ($u) { wp_safe_redirect($u); exit; }
      }

      if ($action === 'lostpassword' || $action === 'rp' || $action === 'resetpass') {
        $u = self::get_page_url('lost');
        if ($u) {
          // Keep key/login if present
          $args = [];
          if (!empty($_GET['key'])) $args['key'] = sanitize_text_field(wp_unslash($_GET['key']));
          if (!empty($_GET['login'])) $args['login'] = sanitize_text_field(wp_unslash($_GET['login']));
          $u = !empty($args) ? add_query_arg($args, $u) : $u;
          wp_safe_redirect($u); exit;
        }
      }

      // default login redirect
      $u = self::get_page_url('login');
      if ($u) {
        if (!empty($_GET['redirect_to'])) {
          $u = add_query_arg('redirect_to', rawurlencode(wp_unslash($_GET['redirect_to'])), $u);
        }
        wp_safe_redirect($u); exit;
      }
    }
  }

  // Shortcodes
  public static function sc_login()   { return SR_Forms::render('login'); }
  public static function sc_signup()  { return SR_Forms::render('signup'); }
  public static function sc_profile() { return SR_Forms::render('profile'); }
  public static function sc_lost()    { return SR_Forms::render('lost'); }
}
