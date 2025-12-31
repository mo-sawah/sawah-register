<?php
if (!defined('ABSPATH')) exit;

class SR_Auth_Google {

  public static function init() {
    add_action('admin_post_nopriv_sr_google_callback', [__CLASS__, 'handle_callback']);
    add_action('admin_post_sr_google_callback',        [__CLASS__, 'handle_callback']); // if logged-in for some reason
  }

  public static function callback_url(): string {
    return admin_url('admin-post.php?action=sr_google_callback');
  }

  /**
   * Build Google auth URL (used by SR_Auth::start_url('google', $return)).
   */
  public static function start_url(string $return_url): string {
    $opt = SR_Settings::get();

    if (empty($opt['google']['enabled']) || empty($opt['google']['client_id']) || empty($opt['google']['client_secret'])) {
      return add_query_arg(['sr_error' => rawurlencode('Google login is not configured.')], $return_url ?: home_url('/'));
    }

    $state = wp_generate_password(24, false, false);

    // Store state => return URL for 10 minutes
    set_transient('sr_google_state_' . $state, [
      'return' => esc_url_raw($return_url ?: home_url('/')),
      'ts'     => time(),
    ], 10 * MINUTE_IN_SECONDS);

    $params = [
      'client_id'     => $opt['google']['client_id'],
      'redirect_uri'  => self::callback_url(),
      'response_type' => 'code',
      'scope'         => 'openid email profile',
      'state'         => $state,
      'access_type'   => 'online',
      'prompt'        => 'select_account',
      'include_granted_scopes' => 'true',
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  }

  public static function handle_callback() {
    $opt = SR_Settings::get();

    $return_fallback = home_url('/');

    // Google can return error=access_denied etc.
    if (!empty($_GET['error'])) {
      $msg = sanitize_text_field(wp_unslash($_GET['error']));
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode('Google login failed: ' . $msg),
      ], $return_fallback));
      exit;
    }

    $code  = !empty($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
    $state = !empty($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

    if (!$code || !$state) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode('Google login failed: missing code/state.'),
      ], $return_fallback));
      exit;
    }

    $st = get_transient('sr_google_state_' . $state);
    delete_transient('sr_google_state_' . $state);

    $return_url = (!empty($st['return'])) ? esc_url_raw($st['return']) : $return_fallback;

    if (empty($opt['google']['enabled']) || empty($opt['google']['client_id']) || empty($opt['google']['client_secret'])) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode('Google login is not configured.'),
      ], $return_url));
      exit;
    }

    // Exchange code for tokens
    $token = self::exchange_code_for_token($code, $opt['google']['client_id'], $opt['google']['client_secret']);
    if (is_wp_error($token)) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode($token->get_error_message()),
      ], $return_url));
      exit;
    }

    if (empty($token['access_token'])) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode('Google login failed: missing access token.'),
      ], $return_url));
      exit;
    }

    // Fetch user info
    $u = self::fetch_userinfo($token['access_token']);
    if (is_wp_error($u)) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode($u->get_error_message()),
      ], $return_url));
      exit;
    }

    $email = !empty($u['email']) ? sanitize_email($u['email']) : '';
    if (!$email || empty($u['email_verified'])) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode('Google login failed: email not available/verified.'),
      ], $return_url));
      exit;
    }

    $user_id = self::get_or_create_user($email, $u);

    if (is_wp_error($user_id)) {
      wp_safe_redirect(add_query_arg([
        'sr_auth'  => '1',
        'sr_tab'   => 'login',
        'sr_error' => rawurlencode($user_id->get_error_message()),
      ], $return_url));
      exit;
    }

    // Login user
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true, is_ssl());

    wp_safe_redirect($return_url);
    exit;
  }

  private static function exchange_code_for_token(string $code, string $client_id, string $client_secret) {
    $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
      'timeout' => 20,
      'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
      'body'    => [
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => self::callback_url(),
        'grant_type'    => 'authorization_code',
      ],
    ]);

    if (is_wp_error($resp)) return $resp;

    $code_http = wp_remote_retrieve_response_code($resp);
    $body      = wp_remote_retrieve_body($resp);
    $json      = json_decode($body, true);

    if ($code_http < 200 || $code_http >= 300 || empty($json) || !is_array($json)) {
      return new WP_Error('sr_google_token', 'Google token exchange failed.');
    }

    return $json;
  }

  private static function fetch_userinfo(string $access_token) {
    $resp = wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
      ],
    ]);

    if (is_wp_error($resp)) return $resp;

    $code_http = wp_remote_retrieve_response_code($resp);
    $body      = wp_remote_retrieve_body($resp);
    $json      = json_decode($body, true);

    if ($code_http < 200 || $code_http >= 300 || empty($json) || !is_array($json)) {
      return new WP_Error('sr_google_userinfo', 'Google user profile fetch failed.');
    }

    return $json;
  }

  private static function get_or_create_user(string $email, array $u) {
    $existing = get_user_by('email', $email);
    if ($existing && !empty($existing->ID)) {
      // Link meta
      if (!empty($u['sub'])) update_user_meta($existing->ID, 'sr_google_sub', sanitize_text_field($u['sub']));
      update_user_meta($existing->ID, 'sr_auth_provider', 'google');
      if (!empty($u['picture'])) update_user_meta($existing->ID, 'sr_avatar_url', esc_url_raw($u['picture']));
      return (int) $existing->ID;
    }

    // Create new subscriber
    $base = sanitize_user(current(explode('@', $email)), true);
    if (!$base) $base = 'user';

    $username = $base;
    $i = 1;
    while (username_exists($username)) {
      $username = $base . $i;
      $i++;
    }

    $password = wp_generate_password(24, true, true);

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) return $user_id;

    // Name
    $name = !empty($u['name']) ? sanitize_text_field($u['name']) : '';
    if ($name) {
      wp_update_user([
        'ID'           => $user_id,
        'display_name' => $name,
        'first_name'   => !empty($u['given_name']) ? sanitize_text_field($u['given_name']) : '',
        'last_name'    => !empty($u['family_name']) ? sanitize_text_field($u['family_name']) : '',
      ]);
    }

    // Force role subscriber
    $user = get_user_by('id', $user_id);
    if ($user && $user instanceof WP_User) {
      $user->set_role('subscriber');
    }

    // Link meta
    if (!empty($u['sub'])) update_user_meta($user_id, 'sr_google_sub', sanitize_text_field($u['sub']));
    update_user_meta($user_id, 'sr_auth_provider', 'google');
    if (!empty($u['picture'])) update_user_meta($user_id, 'sr_avatar_url', esc_url_raw($u['picture']));

    return (int) $user_id;
  }
}
