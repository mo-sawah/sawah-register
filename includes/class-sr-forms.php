<?php
if (!defined('ABSPATH')) exit;

class SR_Forms {

  public static function init() {
    add_action('init', [__CLASS__, 'handle_post_actions']);

    add_action('template_redirect', function(){
      if (!is_user_logged_in()) return;
      if (!SR_Pages::is_sr_page()) return;

      $pid = get_queried_object_id();
      if ((int)$pid === (int)SR_Pages::get_page_id('login') || (int)$pid === (int)SR_Pages::get_page_id('signup')) {
        $p = SR_Pages::get_page_url('profile');
        if ($p) { wp_safe_redirect($p); exit; }
      }
    });

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

    $user = wp_signon([
      'user_login' => $login,
      'user_password' => $pass,
      'remember' => $remember,
    ], is_ssl());

    if (is_wp_error($user)) {
      $u = SR_Pages::get_page_url('login');
      $msg = wp_strip_all_tags($user->get_error_message());
      wp_safe_redirect(add_query_arg('sr_error', rawurlencode($msg), $u));
      exit;
    }

    wp_safe_redirect(self::resolve_redirect_after_login($redirect_to));
    exit;
  }

  private static function post_signup() {
    $email = sanitize_email(wp_unslash($_POST['sr_email'] ?? ''));
    $name  = sanitize_text_field(wp_unslash($_POST['sr_name'] ?? ''));
    $pass  = (string)($_POST['sr_password'] ?? '');

    if (!$email || !is_email($email)) self::redir_err('signup', 'Please enter a valid email.');
    if (email_exists($email)) self::redir_err('signup', 'This email is already registered. Please login.');
    if (strlen($pass) < 8) self::redir_err('signup', 'Password must be at least 8 characters.');

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

    if (is_wp_error($uid)) self::redir_err('signup', 'Could not create account. Please try again.');

    wp_set_current_user($uid);
    wp_set_auth_cookie($uid, true);

    wp_safe_redirect(self::resolve_redirect_after_login(''));
    exit;
  }

  private static function post_profile_update() {
    if (!is_user_logged_in()) self::redir_err('login', 'Please login.');

    $uid = get_current_user_id();

    // Core profile
    $display = sanitize_text_field(wp_unslash($_POST['sr_display_name'] ?? ''));
    $first   = sanitize_text_field(wp_unslash($_POST['sr_first_name'] ?? ''));
    $last    = sanitize_text_field(wp_unslash($_POST['sr_last_name'] ?? ''));
    $phone   = sanitize_text_field(wp_unslash($_POST['sr_phone'] ?? ''));
    $dob     = sanitize_text_field(wp_unslash($_POST['sr_dob'] ?? '')); // YYYY-MM-DD
    $country = sanitize_text_field(wp_unslash($_POST['sr_country'] ?? ''));
    $city    = sanitize_text_field(wp_unslash($_POST['sr_city'] ?? ''));
    $postal  = sanitize_text_field(wp_unslash($_POST['sr_postal'] ?? ''));

    // Social
    $website = esc_url_raw(wp_unslash($_POST['sr_website'] ?? ''));
    $twitter = esc_url_raw(wp_unslash($_POST['sr_twitter'] ?? ''));
    $facebook= esc_url_raw(wp_unslash($_POST['sr_facebook'] ?? ''));
    $linkedin= esc_url_raw(wp_unslash($_POST['sr_linkedin'] ?? ''));

    $data = ['ID' => $uid];
    if ($display) $data['display_name'] = $display;

    $r = wp_update_user($data);
    if (is_wp_error($r)) self::redir_err('profile', 'Could not update profile.');

    update_user_meta($uid, 'first_name', $first);
    update_user_meta($uid, 'last_name', $last);
    update_user_meta($uid, '_sr_phone', $phone);
    update_user_meta($uid, '_sr_dob', $dob);
    update_user_meta($uid, '_sr_country', $country);
    update_user_meta($uid, '_sr_city', $city);
    update_user_meta($uid, '_sr_postal', $postal);

    update_user_meta($uid, '_sr_website', $website);
    update_user_meta($uid, '_sr_twitter', $twitter);
    update_user_meta($uid, '_sr_facebook', $facebook);
    update_user_meta($uid, '_sr_linkedin', $linkedin);

    // Security (optional)
    $newpass  = (string)($_POST['sr_new_password'] ?? '');
    $newpass2 = (string)($_POST['sr_new_password2'] ?? '');
    if (!empty($newpass) || !empty($newpass2)) {
      if (strlen($newpass) < 8) self::redir_err('profile', 'New password must be at least 8 characters.');
      if ($newpass !== $newpass2) self::redir_err('profile', 'Passwords do not match.');
      wp_set_password($newpass, $uid);
      wp_set_auth_cookie($uid, true);
    }

    $u = SR_Pages::get_page_url('profile');
    wp_safe_redirect(add_query_arg('sr_success', rawurlencode('Profile updated.'), $u) . '#dashboard');
    exit;
  }

  private static function post_lost_request() {
    $email = sanitize_email(wp_unslash($_POST['sr_email'] ?? ''));
    if (!$email || !is_email($email)) self::redir_err('lost', 'Please enter a valid email.');

    $user = get_user_by('email', $email);
    $lost = SR_Pages::get_page_url('lost');

    if (!$user) {
      wp_safe_redirect(add_query_arg('sr_success', rawurlencode('If an account exists for this email, a reset link has been sent.'), $lost));
      exit;
    }

    $key = get_password_reset_key($user);
    if (is_wp_error($key)) self::redir_err('lost', 'Could not generate reset key.');

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
    <div class="sr-wrap <?php echo $view === 'profile' ? 'sr-wrap--app' : ''; ?>">
      <?php if ($view !== 'profile'): ?>
        <div class="sr-card">
          <div class="sr-head">
            <div class="sr-brand">
              <div class="sr-logo" aria-hidden="true">
                <i class="fa-solid fa-shield-halved"></i>
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
                  <span class="sr-ic"><i class="fa-brands fa-google"></i></span>
                  Continue with Google
                </a>
              <?php endif; ?>
              <?php if ($fb_on): ?>
                <a class="sr-btn sr-btn--social" href="<?php echo esc_url(SR_Auth::start_url('facebook', $redirect_to)); ?>">
                  <span class="sr-ic"><i class="fa-brands fa-facebook-f"></i></span>
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
              elseif ($view === 'lost') self::view_lost();
              else echo '<p>Invalid view.</p>';
            ?>
          </div>
        </div>
      <?php else: ?>
        <?php self::view_profile_app($err, $ok); ?>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function title_for($view) {
    switch ($view) {
      case 'login': return 'Welcome back';
      case 'signup': return 'Create your account';
      case 'lost': return 'Password help';
      default: return 'Account';
    }
  }
  private static function subtitle_for($view) {
    switch ($view) {
      case 'login': return 'Login to continue';
      case 'signup': return 'Fast, clean, and secure';
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

  private static function view_lost() {
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

  private static function has_woocommerce() {
    return class_exists('WooCommerce') && function_exists('wc_get_orders');
  }

  private static function view_profile_app($err, $ok) {
    if (!is_user_logged_in()) {
      $login = SR_Pages::get_page_url('login');
      echo '<div class="sr-alert sr-alert--error">Please login to view your profile.</div>';
      if ($login) echo '<a class="sr-btn sr-btn--primary" href="' . esc_url($login) . '">Go to Login</a>';
      return;
    }

    $uid = get_current_user_id();
    $u = wp_get_current_user();

    $first = (string)get_user_meta($uid, 'first_name', true);
    $last  = (string)get_user_meta($uid, 'last_name', true);
    $phone = (string)get_user_meta($uid, '_sr_phone', true);
    $dob   = (string)get_user_meta($uid, '_sr_dob', true);
    $country = (string)get_user_meta($uid, '_sr_country', true);
    $city    = (string)get_user_meta($uid, '_sr_city', true);
    $postal  = (string)get_user_meta($uid, '_sr_postal', true);

    $website = (string)get_user_meta($uid, '_sr_website', true);
    $twitter = (string)get_user_meta($uid, '_sr_twitter', true);
    $facebook= (string)get_user_meta($uid, '_sr_facebook', true);
    $linkedin= (string)get_user_meta($uid, '_sr_linkedin', true);

    $roles = array_map('sanitize_text_field', (array)$u->roles);
    $role_label = $roles ? ucfirst(str_replace('_',' ', $roles[0])) : 'Member';

    $avatar = get_avatar_url($uid, ['size' => 96]);
    $logout = wp_logout_url(SR_Pages::get_page_url('login'));
    $has_wc = self::has_woocommerce();

    // For dashboard quick stats
    $liked = (array)get_user_meta($uid, '_sr_liked_posts', true);
    $saved = (array)get_user_meta($uid, '_sr_saved_posts', true);
    $liked_count = is_array($liked) ? count($liked) : 0;
    $saved_count = is_array($saved) ? count($saved) : 0;

    ?>
    <div class="sr-app">
      <aside class="sr-side">
        <div class="sr-side__logo">
          <a href="<?php echo esc_url(home_url('/')); ?>" class="sr-logoLink">
            <?php
              if (function_exists('the_custom_logo') && has_custom_logo()) {
                echo get_custom_logo();
              } else {
                echo '<span class="sr-logoText">' . esc_html(get_bloginfo('name')) . '</span>';
              }
            ?>
          </a>
        </div>

        <nav class="sr-nav">
          <a class="sr-nav__item" href="#dashboard" data-sr-tab="dashboard"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>

          <div class="sr-nav__label">Tools</div>
          <a class="sr-nav__item" href="#factcheck" data-sr-tab="factcheck"><i class="fa-solid fa-magnifying-glass-chart"></i><span>Fact Check</span></a>
          <a class="sr-nav__item" href="#misinfo" data-sr-tab="misinfo"><i class="fa-solid fa-triangle-exclamation"></i><span>Trending Misinformation</span></a>

          <?php if ($has_wc): ?>
            <div class="sr-nav__label">Commerce</div>
            <a class="sr-nav__item" href="#orders" data-sr-tab="orders"><i class="fa-solid fa-bag-shopping"></i><span>Orders</span></a>
            <a class="sr-nav__item" href="#promotions" data-sr-tab="promotions"><i class="fa-solid fa-tags"></i><span>Promotions</span></a>
          <?php endif; ?>

          <div class="sr-nav__label">Reading</div>
          <a class="sr-nav__item" href="#liked" data-sr-tab="liked"><i class="fa-solid fa-heart"></i><span>Liked Articles</span></a>
          <a class="sr-nav__item" href="#saved" data-sr-tab="saved"><i class="fa-solid fa-bookmark"></i><span>Saved for Later</span></a>

          <div class="sr-nav__label">Info</div>
          <a class="sr-nav__item" href="<?php echo esc_url(home_url('/contact/')); ?>"><i class="fa-solid fa-envelope"></i><span>Contact</span></a>
          <a class="sr-nav__item" href="<?php echo esc_url(home_url('/about/')); ?>"><i class="fa-solid fa-circle-info"></i><span>About</span></a>
        </nav>

        <div class="sr-side__user">
          <div class="sr-userRow">
            <img class="sr-userAvatar" src="<?php echo esc_url($avatar); ?>" alt="">
            <div class="sr-userMeta">
              <div class="sr-userName"><?php echo esc_html($u->display_name ?: $u->user_login); ?></div>
              <div class="sr-userEmail"><?php echo esc_html($u->user_email); ?></div>
            </div>
          </div>
          <a class="sr-logout" href="<?php echo esc_url($logout); ?>"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
        </div>
      </aside>

      <main class="sr-main">
        <header class="sr-topbar">
          <div class="sr-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search..." aria-label="Search">
          </div>
          <div class="sr-topbar__right">
            <div class="sr-date" data-sr-date>—</div>
            <button class="sr-iconBtn" type="button" title="Messages" aria-label="Messages"><i class="fa-regular fa-message"></i></button>
            <button class="sr-iconBtn" type="button" title="Notifications" aria-label="Notifications"><i class="fa-regular fa-bell"></i></button>
          </div>
        </header>

        <section class="sr-content">
          <?php if ($err): ?><div class="sr-alert sr-alert--error"><?php echo esc_html($err); ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="sr-alert sr-alert--ok"><?php echo esc_html($ok); ?></div><?php endif; ?>

          <div class="sr-panel" data-sr-panel="dashboard">
            <div class="sr-pageTitle">My Profile</div>

            <div class="sr-cardBlock sr-cardBlock--hero">
              <div class="sr-hero">
                <img class="sr-heroAvatar" src="<?php echo esc_url($avatar); ?>" alt="">
                <div class="sr-heroInfo">
                  <div class="sr-heroName"><?php echo esc_html($u->display_name ?: $u->user_login); ?></div>
                  <div class="sr-heroMeta"><?php echo esc_html($role_label); ?><?php echo ($city || $country) ? ' · ' . esc_html(trim($city . ($country ? ', ' . $country : ''))) : ''; ?></div>
                </div>
                <div class="sr-heroStats">
                  <div class="sr-stat"><div class="sr-statNum"><?php echo (int)$liked_count; ?></div><div class="sr-statLbl">Liked</div></div>
                  <div class="sr-stat"><div class="sr-statNum"><?php echo (int)$saved_count; ?></div><div class="sr-statLbl">Saved</div></div>
                </div>
              </div>
            </div>

            <form method="post" class="sr-profileForm">
              <input type="hidden" name="sr_action" value="profile_update">
              <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">

              <div class="sr-grid">
                <div class="sr-cardBlock" data-sr-card="personal">
                  <div class="sr-cardHead">
                    <div class="sr-cardTitle">Personal Information</div>
                    <div class="sr-cardActions">
                      <button type="button" class="sr-editBtn" data-sr-edit="personal"><i class="fa-solid fa-pen"></i> Edit</button>
                      <button type="submit" class="sr-saveBtn" data-sr-save="personal"><i class="fa-solid fa-check"></i> Save</button>
                      <button type="button" class="sr-cancelBtn" data-sr-cancel="personal"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    </div>
                  </div>

                  <div class="sr-fields">
                    <div class="sr-field">
                      <label>First Name</label>
                      <input disabled name="sr_first_name" value="<?php echo esc_attr($first); ?>">
                    </div>
                    <div class="sr-field">
                      <label>Last Name</label>
                      <input disabled name="sr_last_name" value="<?php echo esc_attr($last); ?>">
                    </div>
                    <div class="sr-field">
                      <label>Email Address</label>
                      <input disabled value="<?php echo esc_attr($u->user_email); ?>">
                    </div>
                    <div class="sr-field">
                      <label>Phone Number</label>
                      <input disabled name="sr_phone" value="<?php echo esc_attr($phone); ?>">
                    </div>
                    <div class="sr-field">
                      <label>Date of Birth</label>
                      <input disabled name="sr_dob" placeholder="YYYY-MM-DD" value="<?php echo esc_attr($dob); ?>">
                    </div>
                    <div class="sr-field">
                      <label>User Role</label>
                      <input disabled value="<?php echo esc_attr($role_label); ?>">
                    </div>
                    <div class="sr-field sr-field--full">
                      <label>Display Name</label>
                      <input disabled name="sr_display_name" value="<?php echo esc_attr($u->display_name); ?>">
                    </div>
                  </div>
                </div>

                <div class="sr-cardBlock" data-sr-card="address">
                  <div class="sr-cardHead">
                    <div class="sr-cardTitle">Address</div>
                    <div class="sr-cardActions">
                      <button type="button" class="sr-editBtn" data-sr-edit="address"><i class="fa-solid fa-pen"></i> Edit</button>
                      <button type="submit" class="sr-saveBtn" data-sr-save="address"><i class="fa-solid fa-check"></i> Save</button>
                      <button type="button" class="sr-cancelBtn" data-sr-cancel="address"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    </div>
                  </div>

                  <div class="sr-fields">
                    <div class="sr-field">
                      <label>Country</label>
                      <input disabled name="sr_country" value="<?php echo esc_attr($country); ?>">
                    </div>
                    <div class="sr-field">
                      <label>City</label>
                      <input disabled name="sr_city" value="<?php echo esc_attr($city); ?>">
                    </div>
                    <div class="sr-field">
                      <label>Postal Code</label>
                      <input disabled name="sr_postal" value="<?php echo esc_attr($postal); ?>">
                    </div>
                  </div>
                </div>

                <div class="sr-cardBlock" data-sr-card="social">
                  <div class="sr-cardHead">
                    <div class="sr-cardTitle">Social Links</div>
                    <div class="sr-cardActions">
                      <button type="button" class="sr-editBtn" data-sr-edit="social"><i class="fa-solid fa-pen"></i> Edit</button>
                      <button type="submit" class="sr-saveBtn" data-sr-save="social"><i class="fa-solid fa-check"></i> Save</button>
                      <button type="button" class="sr-cancelBtn" data-sr-cancel="social"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    </div>
                  </div>

                  <div class="sr-fields">
                    <div class="sr-field">
                      <label>Website</label>
                      <input disabled name="sr_website" value="<?php echo esc_attr($website); ?>" placeholder="https://">
                    </div>
                    <div class="sr-field">
                      <label>Twitter/X</label>
                      <input disabled name="sr_twitter" value="<?php echo esc_attr($twitter); ?>" placeholder="https://">
                    </div>
                    <div class="sr-field">
                      <label>Facebook</label>
                      <input disabled name="sr_facebook" value="<?php echo esc_attr($facebook); ?>" placeholder="https://">
                    </div>
                    <div class="sr-field">
                      <label>LinkedIn</label>
                      <input disabled name="sr_linkedin" value="<?php echo esc_attr($linkedin); ?>" placeholder="https://">
                    </div>
                  </div>
                </div>

                <div class="sr-cardBlock" data-sr-card="security">
                  <div class="sr-cardHead">
                    <div class="sr-cardTitle">Security</div>
                    <div class="sr-cardActions">
                      <button type="button" class="sr-editBtn" data-sr-edit="security"><i class="fa-solid fa-pen"></i> Change</button>
                      <button type="submit" class="sr-saveBtn" data-sr-save="security"><i class="fa-solid fa-check"></i> Save</button>
                      <button type="button" class="sr-cancelBtn" data-sr-cancel="security"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    </div>
                  </div>

                  <div class="sr-fields">
                    <div class="sr-field">
                      <label>New Password</label>
                      <input disabled type="password" name="sr_new_password" minlength="8" autocomplete="new-password">
                    </div>
                    <div class="sr-field">
                      <label>Confirm New Password</label>
                      <input disabled type="password" name="sr_new_password2" minlength="8" autocomplete="new-password">
                    </div>
                    <div class="sr-note sr-field--full">
                      <i class="fa-solid fa-shield"></i>
                      Use 8+ characters. Save will keep you logged in.
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>

          <div class="sr-panel" data-sr-panel="factcheck">
            <div class="sr-pageTitle">Fact Check</div>
            <div class="sr-cardBlock">
              <?php echo do_shortcode('[ai_factcheck_search]'); ?>
            </div>
          </div>

          <div class="sr-panel" data-sr-panel="misinfo">
            <div class="sr-pageTitle">Trending Misinformation</div>
            <div class="sr-cardBlock">
              <?php echo do_shortcode('[ai_verify_intelligence_dashboard]'); ?>
            </div>
          </div>

          <?php if ($has_wc): ?>
            <div class="sr-panel" data-sr-panel="orders">
              <div class="sr-pageTitle">Orders</div>
              <div class="sr-cardBlock">
                <?php
                  $orders = wc_get_orders([
                    'customer_id' => $uid,
                    'limit' => 10,
                    'orderby' => 'date',
                    'order' => 'DESC',
                  ]);
                ?>
                <?php if (!empty($orders)): ?>
                  <div class="sr-tableWrap">
                    <table class="sr-table">
                      <thead>
                        <tr>
                          <th>Order</th>
                          <th>Date</th>
                          <th>Status</th>
                          <th>Total</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($orders as $o): ?>
                          <tr>
                            <td>#<?php echo (int)$o->get_id(); ?></td>
                            <td><?php echo esc_html(wc_format_datetime($o->get_date_created())); ?></td>
                            <td><?php echo esc_html(wc_get_order_status_name($o->get_status())); ?></td>
                            <td><?php echo wp_kses_post($o->get_formatted_order_total()); ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php else: ?>
                  <div class="sr-empty">No orders yet.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="sr-panel" data-sr-panel="promotions">
              <div class="sr-pageTitle">Promotions</div>
              <div class="sr-cardBlock">
                <div class="sr-empty">No promotions right now.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="sr-panel" data-sr-panel="liked">
            <div class="sr-pageTitle">Liked Articles</div>
            <div class="sr-cardBlock">
              <?php self::render_post_list_from_meta($uid, '_sr_liked_posts', 'No liked articles yet.'); ?>
            </div>
          </div>

          <div class="sr-panel" data-sr-panel="saved">
            <div class="sr-pageTitle">Saved for Later</div>
            <div class="sr-cardBlock">
              <?php self::render_post_list_from_meta($uid, '_sr_saved_posts', 'Nothing saved yet.'); ?>
            </div>
          </div>

        </section>
      </main>
    </div>
    <?php
  }

  private static function render_post_list_from_meta($uid, $meta_key, $empty_text) {
    $ids = get_user_meta($uid, $meta_key, true);
    if (!is_array($ids) || empty($ids)) {
      echo '<div class="sr-empty">' . esc_html($empty_text) . '</div>';
      return;
    }

    $ids = array_values(array_filter(array_map('absint', $ids)));
    if (empty($ids)) {
      echo '<div class="sr-empty">' . esc_html($empty_text) . '</div>';
      return;
    }

    $q = new WP_Query([
      'post_type' => 'any',
      'post__in' => $ids,
      'orderby' => 'post__in',
      'posts_per_page' => 20,
      'post_status' => 'publish',
    ]);

    if (!$q->have_posts()) {
      echo '<div class="sr-empty">' . esc_html($empty_text) . '</div>';
      return;
    }

    echo '<div class="sr-list">';
    while ($q->have_posts()) {
      $q->the_post();
      echo '<a class="sr-listItem" href="' . esc_url(get_permalink()) . '">';
      echo '<div class="sr-listTitle">' . esc_html(get_the_title()) . '</div>';
      echo '<div class="sr-listMeta">' . esc_html(get_the_date()) . '</div>';
      echo '</a>';
    }
    echo '</div>';
    wp_reset_postdata();
  }
}
