<?php
if (!defined('ABSPATH')) exit;

class SR_Auth {

  public static function init() {
    add_action('init', [__CLASS__, 'add_rewrites']);
    add_filter('query_vars', function($vars){
      $vars[] = 'sr_provider';
      $vars[] = 'sr_action';
      return $vars;
    });

    add_action('template_redirect', [__CLASS__, 'handle_routes']);
  }

  public static function add_rewrites() {
    add_rewrite_rule('^sawah-auth/(google|facebook)/?$', 'index.php?sr_provider=$matches[1]&sr_action=start', 'top');
    add_rewrite_rule('^sawah-auth/(google|facebook)/callback/?$', 'index.php?sr_provider=$matches[1]&sr_action=callback', 'top');
  }

  public static function start_url($provider, $redirect_to = '') {
    $u = home_url('/sawah-auth/' . rawurlencode($provider) . '/');
    if (!empty($redirect_to)) $u = add_query_arg('redirect_to', rawurlencode($redirect_to), $u);
    return $u;
  }

  public static function callback_url($provider) {
    return home_url('/sawah-auth/' . rawurlencode($provider) . '/callback/');
  }

  public static function handle_routes() {
    $provider = get_query_var('sr_provider');
    $action   = get_query_var('sr_action');

    if (!$provider || !$action) return;

    $provider = sanitize_text_field($provider);
    $action   = sanitize_text_field($action);

    if ($action === 'start') {
      self::oauth_start($provider);
      exit;
    }

    if ($action === 'callback') {
      self::oauth_callback($provider);
      exit;
    }
  }

  private static function oauth_start($provider) {
    $opt = SR_Settings::get();

    $redirect_to = isset($_GET['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['redirect_to']))) : '';
    $state = wp_generate_password(24, false, false);

    set_transient('sr_oauth_state_' . $state, [
      'provider' => $provider,
      'redirect_to' => $redirect_to,
      'time' => time(),
    ], 15 * MINUTE_IN_SECONDS);

    if ($provider === 'google') {
      if (empty($opt['google']['enabled']) || empty($opt['google']['client_id']) || empty($opt['google']['client_secret'])) {
        wp_die('Google login is not configured.');
      }

      $auth = add_query_arg([
        'client_id' => $opt['google']['client_id'],
        'redirect_uri' => self::callback_url('google'),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
        'access_type' => 'online',
      ], 'https://accounts.google.com/o/oauth2/v2/auth');

      wp_safe_redirect($auth);
      exit;
    }

    if ($provider === 'facebook') {
      if (empty($opt['facebook']['enabled']) || empty($opt['facebook']['app_id']) || empty($opt['facebook']['app_secret'])) {
        wp_die('Facebook login is not configured.');
      }

      $auth = add_query_arg([
        'client_id' => $opt['facebook']['app_id'],
        'redirect_uri' => self::callback_url('facebook'),
        'response_type' => 'code',
        'scope' => 'email,public_profile',
        'state' => $state,
      ], 'https://www.facebook.com/v19.0/dialog/oauth');

      wp_safe_redirect($auth);
      exit;
    }

    wp_die('Unknown provider.');
  }

  private static function oauth_callback($provider) {
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';

    if (!$state || !$code) {
      self::fail_redirect('Missing OAuth state/code.');
    }

    $st = get_transient('sr_oauth_state_' . $state);
    delete_transient('sr_oauth_state_' . $state);

    if (empty($st) || !is_array($st) || ($st['provider'] ?? '') !== $provider) {
      self::fail_redirect('Invalid OAuth state.');
    }

    $opt = SR_Settings::get();

    if ($provider === 'google') {
      $token_res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body' => [
          'code' => $code,
          'client_id' => $opt['google']['client_id'],
          'client_secret' => $opt['google']['client_secret'],
          'redirect_uri' => self::callback_url('google'),
          'grant_type' => 'authorization_code',
        ]
      ]);

      if (is_wp_error($token_res)) self::fail_redirect('Google token request failed.');
      $token_body = json_decode(wp_remote_retrieve_body($token_res), true);
      $access = $token_body['access_token'] ?? '';

      if (!$access) self::fail_redirect('Google access token missing.');

      $ui = wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
        'timeout' => 20,
        'headers' => ['Authorization' => 'Bearer ' . $access]
      ]);

      if (is_wp_error($ui)) self::fail_redirect('Google userinfo failed.');
      $user = json_decode(wp_remote_retrieve_body($ui), true);

      $email = sanitize_email($user['email'] ?? '');
      $name  = sanitize_text_field($user['name'] ?? '');

      if (!$email) self::fail_redirect('Google did not return an email address.');

      self::login_or_create($email, $name, 'google', $st['redirect_to'] ?? '');
      exit;
    }

    if ($provider === 'facebook') {
      $token_url = add_query_arg([
        'client_id' => $opt['facebook']['app_id'],
        'redirect_uri' => self::callback_url('facebook'),
        'client_secret' => $opt['facebook']['app_secret'],
        'code' => $code,
      ], 'https://graph.facebook.com/v19.0/oauth/access_token');

      $token_res = wp_remote_get($token_url, ['timeout' => 20]);
      if (is_wp_error($token_res)) self::fail_redirect('Facebook token request failed.');

      $token_body = json_decode(wp_remote_retrieve_body($token_res), true);
      $access = $token_body['access_token'] ?? '';
      if (!$access) self::fail_redirect('Facebook access token missing.');

      $me_url = add_query_arg([
        'fields' => 'id,name,email',
        'access_token' => $access,
      ], 'https://graph.facebook.com/me');

      $me_res = wp_remote_get($me_url, ['timeout' => 20]);
      if (is_wp_error($me_res)) self::fail_redirect('Facebook userinfo failed.');

      $me = json_decode(wp_remote_retrieve_body($me_res), true);
      $email = sanitize_email($me['email'] ?? '');
      $name  = sanitize_text_field($me['name'] ?? '');

      if (!$email) self::fail_redirect('Facebook did not return an email address (ensure email permission + app review if needed).');

      self::login_or_create($email, $name, 'facebook', $st['redirect_to'] ?? '');
      exit;
    }

    self::fail_redirect('Unknown provider callback.');
  }

  private static function login_or_create($email, $name, $provider, $redirect_to) {
    $user = get_user_by('email', $email);

    if (!$user) {
      $base = sanitize_user(current(explode('@', $email)), true);
      if (!$base) $base = 'user';
      $username = $base;
      $i = 1;
      while (username_exists($username)) {
        $username = $base . $i;
        $i++;
      }

      $pass = wp_generate_password(24, true, true);
      $uid = wp_insert_user([
        'user_login' => $username,
        'user_email' => $email,
        'display_name' => $name ?: $username,
        'user_pass' => $pass,
        'role' => 'subscriber',
      ]);

      if (is_wp_error($uid)) {
        self::fail_redirect('Could not create user.');
      }

      $user = get_user_by('id', $uid);
      update_user_meta($uid, '_sr_provider', sanitize_text_field($provider));
    } else {
      // Ensure subscriber at minimum (don’t downgrade higher roles)
      if (in_array('subscriber', (array)$user->roles, true) === false && !current_user_can('manage_options')) {
        // do nothing
      }
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    $final = SR_Forms::resolve_redirect_after_login($redirect_to);
    wp_safe_redirect($final);
    exit;
  }

  private static function fail_redirect($msg) {
    $login = SR_Pages::get_page_url('login');
    if (!$login) wp_die(esc_html($msg));
    $login = add_query_arg('sr_error', rawurlencode($msg), $login);
    wp_safe_redirect($login);
    exit;
  }
}
