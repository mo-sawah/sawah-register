<?php
if (!defined('ABSPATH')) exit;

class SR_Forms {

  public static function init() {
    add_action('init', [__CLASS__, 'handle_post_actions']);

    // If user is logged in and visits login/signup => redirect to profile
    add_action('template_redirect', function(){
      if (!is_user_logged_in()) return;
      if (!SR_Pages::is_sr_page()) return;

      $pid = get_queried_object_id();
      if ((int)$pid === (int)SR_Pages::get_page_id('login') || (int)$pid === (int)SR_Pages::get_page_id('signup')) {
        $p = SR_Pages::get_page_url('profile');
        if ($p) { wp_safe_redirect($p); exit; }
      }
    });

    // Ensure wp-login failures redirect nicely
    add_action('wp_login_failed', function(){
      $u = SR_Pages::get_page_url('login');
      if ($u) {
        wp_safe_redirect(add_query_arg('sr_error', rawurlencode('Invalid username or password.'), $u));
        exit;
      }
    });
  }

  public static function handle_post_actions() {
    if (empty($_POST['sr_action'])) return;

    $action = sanitize_text_field(wp_unslash($_POST['sr_action']));
    if (!in_array($action, ['login','signup','profile_update','lost_request','reset_password'], true)) return;

    if (!isset($_POST['_sr_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sr_nonce'])), 'sr_form')) {
      wp_die('Invalid request.');
    }

    switch ($action) {
      case 'login': self::post_login(); break;
      case 'signup': self::post_signup(); break;
      case 'profile_update': self::post_profile_update(); break;
      case 'lost_request': self::post_lost_request(); break;
      case 'reset_password': self::post_reset_password(); break;
    }
  }

  public static function resolve_redirect_after_login($redirect_to = '') {
    $opt = SR_Settings::get();
    $mode = $opt['redirect_after_login'] ?? 'profile';

    if (!empty($redirect_to)) {
      $url = esc_url_raw($redirect_to);
      if ($url) return $url;
    }

    if ($mode === 'home') return home_url('/');
    if ($mode === 'ref' && !empty($_SERVER['HTTP_REFERER'])) {
      $ref = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
      if ($ref) return $ref;
    }

    $p = SR_Pages::get_page_url('profile');
    return $p ?: home_url('/');
  }

  private static function post_login() {
    $login = sanitize_text_field(wp_unslash($_POST['sr_login'] ?? ''));
    $pass  = (string)($_POST['sr_password'] ?? '');
    $remember = !empty($_POST['sr_remember']);

    $redirect_to = isset($_POST['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_POST['redirect_to']))) : '';

    $creds = [
      'user_login' => $login,
      'user_password' => $pass,
      'remember' => $remember,
    ];

    $user = wp_signon($creds, is_ssl());
    if (is_wp_error($user)) {
      $u = SR_Pages::get_page_url('login');
      $msg = $user->get_error_message();
      wp_safe_redirect(add_query_arg('sr_error', rawurlencode(wp_strip_all_tags($msg)), $u));
      exit;
    }

    $final = self::resolve_redirect_after_login($redirect_to);
    wp_safe_redirect($final);
    exit;
  }

  private static function post_signup() {
    $email = sanitize_email(wp_unslash($_POST['sr_email'] ?? ''));
    $name  = sanitize_text_field(wp_unslash($_POST['sr_name'] ?? ''));
    $pass  = (string)($_POST['sr_password'] ?? '');

    if (!$email || !is_email($email)) {
      self::redir_err('signup', 'Please enter a valid email.');
    }
    if (email_exists($email)) {
      self::redir_err('signup', 'This email is already registered. Please login.');
    }
    if (strlen($pass) < 8) {
      self::redir_err('signup', 'Password must be at least 8 characters.');
    }

    $base = sanitize_user(current(explode('@', $email)), true);
    if (!$base) $base = 'user';
    $username = $base;
    $i=1;
    while (username_exists($username)) { $username = $base . $i; $i++; }

    $uid = wp_insert_user([
      'user_login' => $username,
      'user_email' => $email,
      'display_name' => $name ?: $username,
      'user_pass' => $pass,
      'role' => 'subscriber',
    ]);

    if (is_wp_error($uid)) {
      self::redir_err('signup', 'Could not create account. Please try again.');
    }

    wp_set_current_user($uid);
    wp_set_auth_cookie($uid, true);

    $final = self::resolve_redirect_after_login('');
    wp_safe_redirect($final);
    exit;
  }

  private static function post_profile_update() {
    if (!is_user_logged_in()) self::redir_err('login', 'Please login.');

    $uid = get_current_user_id();
    $display = sanitize_text_field(wp_unslash($_POST['sr_display_name'] ?? ''));
    $newpass = (string)($_POST['sr_new_password'] ?? '');
    $newpass2 = (string)($_POST['sr_new_password2'] ?? '');

    $data = ['ID' => $uid];
    if ($display) $data['display_name'] = $display;

    $r = wp_update_user($data);
    if (is_wp_error($r)) {
      self::redir_err('profile', 'Could not update profile.');
    }

    if (!empty($newpass) || !empty($newpass2)) {
      if (strlen($newpass) < 8) self::redir_err('profile', 'New password must be at least 8 characters.');
      if ($newpass !== $newpass2) self::redir_err('profile', 'Passwords do not match.');
      wp_set_password($newpass, $uid);
      wp_set_auth_cookie($uid, true);
    }

    $u = SR_Pages::get_page_url('profile');
    wp_safe_redirect(add_query_arg('sr_success', rawurlencode('Profile updated.'), $u));
    exit;
  }

  private static function post_lost_request() {
    $email = sanitize_email(wp_unslash($_POST['sr_email'] ?? ''));
    if (!$email || !is_email($email)) self::redir_err('lost', 'Please enter a valid email.');

    $user = get_user_by('email', $email);
    if (!$user) {
      // privacy: show success anyway
      $u = SR_Pages::get_page_url('lost');
      wp_safe_redirect(add_query_arg('sr_success', rawurlencode('If an account exists for this email, a reset link has been sent.'), $u));
      exit;
    }

    $key = get_password_reset_key($user);
    if (is_wp_error($key)) self::redir_err('lost', 'Could not generate reset key.');

    $lost = SR_Pages::get_page_url('lost');
    $link = add_query_arg(['key' => $key, 'login' => rawurlencode($user->user_login)], $lost);

    $subject = sprintf('[%s] Password Reset', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
    $message = "Someone requested a password reset for your account.\n\n";
    $message .= "Reset your password here:\n" . $link . "\n\n";
    $message .= "If you did not request this, you can ignore this email.";

    wp_mail($user->user_email, $subject, $message);

    wp_safe_redirect(add_query_arg('sr_success', rawurlencode('If an account exists for this email, a reset link has been sent.'), $lost));
    exit;
  }

  private static function post_reset_password() {
    $login = sanitize_text_field(wp_unslash($_POST['sr_login'] ?? ''));
    $key   = sanitize_text_field(wp_unslash($_POST['sr_key'] ?? ''));
    $p1    = (string)($_POST['sr_password'] ?? '');
    $p2    = (string)($_POST['sr_password2'] ?? '');

    if (!$login || !$key) self::redir_err('lost', 'Invalid reset link.');
    if (strlen($p1) < 8) self::redir_err('lost', 'Password must be at least 8 characters.');
    if ($p1 !== $p2) self::redir_err('lost', 'Passwords do not match.');

    $user = check_password_reset_key($key, $login);
    if (is_wp_error($user)) self::redir_err('lost', 'Reset link is invalid or expired.');

    reset_password($user, $p1);

    $login_url = SR_Pages::get_page_url('login');
    wp_safe_redirect(add_query_arg('sr_success', rawurlencode('Password reset successfully. Please login.'), $login_url));
    exit;
  }

  private static function redir_err($page_key, $msg) {
    $u = SR_Pages::get_page_url($page_key);
    if (!$u) wp_die(esc_html($msg));
    wp_safe_redirect(add_query_arg('sr_error', rawurlencode($msg), $u));
    exit;
  }

  public static function render($view) {
    $view = sanitize_text_field($view);
    $opt = SR_Settings::get();
    $google_on = !empty($opt['google']['enabled']) && !empty($opt['google']['client_id']) && !empty($opt['google']['client_secret']);
    $fb_on     = !empty($opt['facebook']['enabled']) && !empty($opt['facebook']['app_id']) && !empty($opt['facebook']['app_secret']);

    $err = isset($_GET['sr_error']) ? sanitize_text_field(wp_unslash($_GET['sr_error'])) : '';
    $ok  = isset($_GET['sr_success']) ? sanitize_text_field(wp_unslash($_GET['sr_success'])) : '';

    $redirect_to = isset($_GET['redirect_to']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['redirect_to']))) : '';

    ob_start();
    ?>
    <div class="sr-wrap">
      <div class="sr-card">
        <div class="sr-head">
          <div class="sr-brand">
            <div class="sr-logo" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2l8.5 5v10L12 22l-8.5-5V7L12 2z" stroke="currentColor" stroke-width="1.6"/></svg>
            </div>
            <div>
              <div class="sr-title"><?php echo esc_html(self::title_for($view)); ?></div>
              <div class="sr-sub"><?php echo esc_html(self::subtitle_for($view)); ?></div>
            </div>
          </div>
        </div>

        <?php if ($err): ?><div class="sr-alert sr-alert--error"><?php echo esc_html($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="sr-alert sr-alert--ok"><?php echo esc_html($ok); ?></div><?php endif; ?>

        <?php if (in_array($view, ['login','signup'], true) && ($google_on || $fb_on)): ?>
          <div class="sr-social">
            <?php if ($google_on): ?>
              <a class="sr-btn sr-btn--social" href="<?php echo esc_url(SR_Auth::start_url('google', $redirect_to)); ?>">
                <span class="sr-ic" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21.35 11.1H12v2.9h5.35c-.5 2.85-3.2 4.1-5.35 4.1a6 6 0 1 1 0-12c1.7 0 3.05.7 4.05 1.65l2.05-2.05A8.9 8.9 0 0 0 12 3.1a8.9 8.9 0 1 0 0 17.8c5.15 0 8.55-3.6 8.55-8.65 0-.6-.1-1.05-.2-1.15z" fill="currentColor"/></svg>
                </span>
                Continue with Google
              </a>
            <?php endif; ?>

            <?php if ($fb_on): ?>
              <a class="sr-btn sr-btn--social" href="<?php echo esc_url(SR_Auth::start_url('facebook', $redirect_to)); ?>">
                <span class="sr-ic" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14 9h3V6h-3c-2.2 0-4 1.8-4 4v3H7v3h3v6h3v-6h3l1-3h-4v-3c0-.6.4-1 1-1z" fill="currentColor"/></svg>
                </span>
                Continue with Facebook
              </a>
            <?php endif; ?>
          </div>

          <div class="sr-divider"><span>or</span></div>
        <?php endif; ?>

        <div class="sr-body">
          <?php
            if ($view === 'login') self::view_login($redirect_to);
            elseif ($view === 'signup') self::view_signup();
            elseif ($view === 'profile') self::view_profile();
            elseif ($view === 'lost') self::view_lost();
            else echo '<p>Invalid view.</p>';
          ?>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function title_for($view) {
    switch ($view) {
      case 'login': return 'Welcome back';
      case 'signup': return 'Create your account';
      case 'profile': return 'Your profile';
      case 'lost': return 'Password help';
      default: return 'Account';
    }
  }
  private static function subtitle_for($view) {
    switch ($view) {
      case 'login': return 'Login to continue';
      case 'signup': return 'Fast, clean, and secure';
      case 'profile': return 'Manage your details';
      case 'lost': return 'Reset your password securely';
      default: return '';
    }
  }

  private static function view_login($redirect_to) {
    $signup = SR_Pages::get_page_url('signup');
    $lost   = SR_Pages::get_page_url('lost');
    ?>
    <form method="post" class="sr-form">
      <input type="hidden" name="sr_action" value="login">
      <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">
      <?php if (!empty($redirect_to)): ?>
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr(rawurlencode($redirect_to)); ?>">
      <?php endif; ?>

      <label class="sr-field">
        <span>Email or Username</span>
        <input type="text" name="sr_login" required autocomplete="username">
      </label>

      <label class="sr-field">
        <span>Password</span>
        <input type="password" name="sr_password" required autocomplete="current-password">
      </label>

      <div class="sr-row">
        <label class="sr-check">
          <input type="checkbox" name="sr_remember" value="1">
          <span>Remember me</span>
        </label>
        <?php if ($lost): ?><a class="sr-link" href="<?php echo esc_url($lost); ?>">Forgot password?</a><?php endif; ?>
      </div>

      <button class="sr-btn sr-btn--primary" type="submit">Login</button>

      <?php if ($signup): ?>
        <div class="sr-foot">
          <span>New here?</span> <a class="sr-link" href="<?php echo esc_url($signup); ?>">Create an account</a>
        </div>
      <?php endif; ?>
    </form>
    <?php
  }

  private static function view_signup() {
    $login = SR_Pages::get_page_url('login');
    ?>
    <form method="post" class="sr-form">
      <input type="hidden" name="sr_action" value="signup">
      <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">

      <label class="sr-field">
        <span>Name</span>
        <input type="text" name="sr_name" autocomplete="name">
      </label>

      <label class="sr-field">
        <span>Email</span>
        <input type="email" name="sr_email" required autocomplete="email">
      </label>

      <label class="sr-field">
        <span>Password</span>
        <input type="password" name="sr_password" required autocomplete="new-password" minlength="8">
        <small class="sr-help">At least 8 characters.</small>
      </label>

      <button class="sr-btn sr-btn--primary" type="submit">Create account</button>

      <?php if ($login): ?>
        <div class="sr-foot">
          <span>Already have an account?</span> <a class="sr-link" href="<?php echo esc_url($login); ?>">Login</a>
        </div>
      <?php endif; ?>
    </form>
    <?php
  }

  private static function view_profile() {
    if (!is_user_logged_in()) {
      $login = SR_Pages::get_page_url('login');
      echo '<div class="sr-alert sr-alert--error">Please login to view your profile.</div>';
      if ($login) echo '<a class="sr-btn sr-btn--primary" href="' . esc_url($login) . '">Go to Login</a>';
      return;
    }

    $u = wp_get_current_user();
    $logout = wp_logout_url(SR_Pages::get_page_url('login'));
    ?>
    <div class="sr-profile">
      <div class="sr-profileTop">
        <div class="sr-avatar" aria-hidden="true"><?php echo esc_html(strtoupper(substr($u->display_name ?: $u->user_login, 0, 1))); ?></div>
        <div>
          <div class="sr-name"><?php echo esc_html($u->display_name ?: $u->user_login); ?></div>
          <div class="sr-meta"><?php echo esc_html($u->user_email); ?></div>
        </div>
        <a class="sr-btn sr-btn--ghost" href="<?php echo esc_url($logout); ?>">Logout</a>
      </div>

      <form method="post" class="sr-form sr-form--compact">
        <input type="hidden" name="sr_action" value="profile_update">
        <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">

        <label class="sr-field">
          <span>Display name</span>
          <input type="text" name="sr_display_name" value="<?php echo esc_attr($u->display_name); ?>">
        </label>

        <div class="sr-grid2">
          <label class="sr-field">
            <span>New password</span>
            <input type="password" name="sr_new_password" autocomplete="new-password" minlength="8">
          </label>
          <label class="sr-field">
            <span>Confirm new password</span>
            <input type="password" name="sr_new_password2" autocomplete="new-password" minlength="8">
          </label>
        </div>

        <button class="sr-btn sr-btn--primary" type="submit">Save changes</button>
      </form>
    </div>
    <?php
  }

  private static function view_lost() {
    // If reset link parameters exist, show reset form
    $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    $login = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';

    if ($key && $login) {
      ?>
      <form method="post" class="sr-form">
        <input type="hidden" name="sr_action" value="reset_password">
        <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">
        <input type="hidden" name="sr_key" value="<?php echo esc_attr($key); ?>">
        <input type="hidden" name="sr_login" value="<?php echo esc_attr($login); ?>">

        <label class="sr-field">
          <span>New password</span>
          <input type="password" name="sr_password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="sr-field">
          <span>Confirm new password</span>
          <input type="password" name="sr_password2" required minlength="8" autocomplete="new-password">
        </label>

        <button class="sr-btn sr-btn--primary" type="submit">Reset password</button>
      </form>
      <?php
      return;
    }

    ?>
    <form method="post" class="sr-form">
      <input type="hidden" name="sr_action" value="lost_request">
      <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">

      <label class="sr-field">
        <span>Email</span>
        <input type="email" name="sr_email" required autocomplete="email">
        <small class="sr-help">We’ll email you a secure reset link.</small>
      </label>

      <button class="sr-btn sr-btn--primary" type="submit">Send reset link</button>
    </form>
    <?php
  }
}
