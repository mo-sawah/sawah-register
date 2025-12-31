<?php
if (!defined('ABSPATH')) exit;

class SR_Settings {
  const OPT = 'sawah_register_options';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  public static function ensure_defaults() {
    $opt = get_option(self::OPT, []);
    if (!is_array($opt)) $opt = [];

    $defaults = [
      'pages' => [
        'login' => 0,
        'signup' => 0,
        'profile' => 0,
        'lost' => 0,
      ],
      'colors' => [
        'light' => [
          'primary' => '#2563eb',
          'bg'      => '#ffffff',
          'card'    => '#ffffff',
          'text'    => '#0f172a',
          'muted'   => '#64748b',
          'border'  => '#e5e7eb',
          'danger'  => '#dc2626',
          'success' => '#16a34a',
        ],
        'dark' => [
          'primary' => '#60a5fa',
          'bg'      => '#0b1220',
          'card'    => '#0f172a',
          'text'    => '#e5e7eb',
          'muted'   => '#94a3b8',
          'border'  => '#1f2a44',
          'danger'  => '#f87171',
          'success' => '#4ade80',
        ],
      ],
      'redirect_after_login' => 'profile', // profile | home | ref
      'google' => [
        'enabled' => 0,
        'client_id' => '',
        'client_secret' => '',
      ],
      'facebook' => [
        'enabled' => 0,
        'app_id' => '',
        'app_secret' => '',
      ],
    ];

    $merged = array_replace_recursive($defaults, $opt);
    update_option(self::OPT, $merged);
  }

  public static function get() {
    $opt = get_option(self::OPT, []);
    if (!is_array($opt)) $opt = [];
    return $opt;
  }

  public static function admin_menu() {
    add_options_page(
      'Sawah Register',
      'Sawah Register',
      'manage_options',
      'sawah-register',
      [__CLASS__, 'render_settings_page']
    );
  }

  public static function register_settings() {
    register_setting('sawah_register_group', self::OPT, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize'],
      'default' => [],
    ]);
  }

  public static function sanitize($input) {
    $out = self::get();

    // pages
    foreach (['login','signup','profile','lost'] as $k) {
      if (isset($input['pages'][$k])) $out['pages'][$k] = absint($input['pages'][$k]);
    }

    // redirect
    if (isset($input['redirect_after_login'])) {
      $allowed = ['profile','home','ref'];
      $v = sanitize_text_field($input['redirect_after_login']);
      $out['redirect_after_login'] = in_array($v, $allowed, true) ? $v : 'profile';
    }

    // colors
    foreach (['light','dark'] as $mode) {
      foreach (['primary','bg','card','text','muted','border','danger','success'] as $c) {
        if (isset($input['colors'][$mode][$c])) {
          $val = sanitize_hex_color($input['colors'][$mode][$c]);
          if ($val) $out['colors'][$mode][$c] = $val;
        }
      }
    }

    // google
    $out['google']['enabled'] = !empty($input['google']['enabled']) ? 1 : 0;
    $out['google']['client_id'] = isset($input['google']['client_id']) ? sanitize_text_field($input['google']['client_id']) : '';
    $out['google']['client_secret'] = isset($input['google']['client_secret']) ? sanitize_text_field($input['google']['client_secret']) : '';

    // facebook
    $out['facebook']['enabled'] = !empty($input['facebook']['enabled']) ? 1 : 0;
    $out['facebook']['app_id'] = isset($input['facebook']['app_id']) ? sanitize_text_field($input['facebook']['app_id']) : '';
    $out['facebook']['app_secret'] = isset($input['facebook']['app_secret']) ? sanitize_text_field($input['facebook']['app_secret']) : '';

    return $out;
  }

  public static function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $opt = self::get();
    $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'asc']);

    $google_cb = esc_url(SR_Auth::callback_url('google'));
    $fb_cb     = esc_url(SR_Auth::callback_url('facebook'));
    ?>
    <div class="wrap">
      <h1>Sawah Register</h1>
      <form method="post" action="options.php">
        <?php settings_fields('sawah_register_group'); ?>

        <h2 class="title">Pages</h2>
        <p>Select the pages used by the plugin (created automatically on activation, but you can reassign them).</p>

        <table class="form-table" role="presentation">
          <?php foreach (['login'=>'Login','signup'=>'Sign Up','profile'=>'Profile','lost'=>'Lost Password'] as $k => $label): ?>
            <tr>
              <th scope="row"><?php echo esc_html($label); ?></th>
              <td>
                <select name="<?php echo esc_attr(self::OPT); ?>[pages][<?php echo esc_attr($k); ?>]">
                  <option value="0">— Select —</option>
                  <?php foreach ($pages as $p): ?>
                    <option value="<?php echo (int)$p->ID; ?>" <?php selected((int)($opt['pages'][$k] ?? 0), (int)$p->ID); ?>>
                      <?php echo esc_html($p->post_title); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>

          <tr>
            <th scope="row">Redirect after login</th>
            <td>
              <select name="<?php echo esc_attr(self::OPT); ?>[redirect_after_login]">
                <option value="profile" <?php selected($opt['redirect_after_login'] ?? 'profile', 'profile'); ?>>Profile page</option>
                <option value="home" <?php selected($opt['redirect_after_login'] ?? 'profile', 'home'); ?>>Homepage</option>
                <option value="ref" <?php selected($opt['redirect_after_login'] ?? 'profile', 'ref'); ?>>Back to referring page</option>
              </select>
            </td>
          </tr>
        </table>

        <hr />

        <h2 class="title">Social Login</h2>

        <h3>Google</h3>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Enable Google login</th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[google][enabled]" value="1" <?php checked(!empty($opt['google']['enabled'])); ?> />
                Enabled
              </label>
              <p class="description">OAuth Redirect URI (add this in Google Cloud Console): <code><?php echo esc_html($google_cb); ?></code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Client ID</th>
            <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[google][client_id]" value="<?php echo esc_attr($opt['google']['client_id'] ?? ''); ?>"></td>
          </tr>
          <tr>
            <th scope="row">Client Secret</th>
            <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[google][client_secret]" value="<?php echo esc_attr($opt['google']['client_secret'] ?? ''); ?>"></td>
          </tr>
        </table>

        <h3>Facebook</h3>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Enable Facebook login</th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[facebook][enabled]" value="1" <?php checked(!empty($opt['facebook']['enabled'])); ?> />
                Enabled
              </label>
              <p class="description">OAuth Redirect URI (add this in Meta App settings): <code><?php echo esc_html($fb_cb); ?></code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">App ID</th>
            <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[facebook][app_id]" value="<?php echo esc_attr($opt['facebook']['app_id'] ?? ''); ?>"></td>
          </tr>
          <tr>
            <th scope="row">App Secret</th>
            <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[facebook][app_secret]" value="<?php echo esc_attr($opt['facebook']['app_secret'] ?? ''); ?>"></td>
          </tr>
        </table>

        <hr />

        <h2 class="title">Colors</h2>
        <p>These map to CSS variables used by the forms.</p>

        <?php foreach (['light'=>'Light mode (.s-light)', 'dark'=>'Dark mode (.s-dark)'] as $mode => $title): ?>
          <h3><?php echo esc_html($title); ?></h3>
          <table class="form-table" role="presentation">
            <?php foreach (['primary','bg','card','text','muted','border','danger','success'] as $c): ?>
              <tr>
                <th scope="row"><?php echo esc_html(ucfirst($c)); ?></th>
                <td>
                  <input type="text" class="sr-color" data-default-color="<?php echo esc_attr($opt['colors'][$mode][$c] ?? ''); ?>"
                    name="<?php echo esc_attr(self::OPT); ?>[colors][<?php echo esc_attr($mode); ?>][<?php echo esc_attr($c); ?>]"
                    value="<?php echo esc_attr($opt['colors'][$mode][$c] ?? ''); ?>">
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endforeach; ?>

        <?php submit_button('Save Changes'); ?>
      </form>

      <script>
        (function($){
          $(function(){
            $('.sr-color').wpColorPicker();
          });
        })(jQuery);
      </script>
    </div>
    <?php
  }

  public static function get_css_vars() {
    $opt = self::get();
    return $opt['colors'] ?? [];
  }

  public static function build_inline_css($vars) {
    $l = $vars['light'] ?? [];
    $d = $vars['dark'] ?? [];

    $css = "";
    $css .= "body.s-light .sr-wrap{";
    $css .= "--sr-primary:" . esc_attr($l['primary'] ?? '#2563eb') . ";";
    $css .= "--sr-bg:"      . esc_attr($l['bg'] ?? '#ffffff') . ";";
    $css .= "--sr-card:"    . esc_attr($l['card'] ?? '#ffffff') . ";";
    $css .= "--sr-text:"    . esc_attr($l['text'] ?? '#0f172a') . ";";
    $css .= "--sr-muted:"   . esc_attr($l['muted'] ?? '#64748b') . ";";
    $css .= "--sr-border:"  . esc_attr($l['border'] ?? '#e5e7eb') . ";";
    $css .= "--sr-danger:"  . esc_attr($l['danger'] ?? '#dc2626') . ";";
    $css .= "--sr-success:" . esc_attr($l['success'] ?? '#16a34a') . ";";
    $css .= "}\n";

    $css .= "body.s-dark .sr-wrap{";
    $css .= "--sr-primary:" . esc_attr($d['primary'] ?? '#60a5fa') . ";";
    $css .= "--sr-bg:"      . esc_attr($d['bg'] ?? '#0b1220') . ";";
    $css .= "--sr-card:"    . esc_attr($d['card'] ?? '#0f172a') . ";";
    $css .= "--sr-text:"    . esc_attr($d['text'] ?? '#e5e7eb') . ";";
    $css .= "--sr-muted:"   . esc_attr($d['muted'] ?? '#94a3b8') . ";";
    $css .= "--sr-border:"  . esc_attr($d['border'] ?? '#1f2a44') . ";";
    $css .= "--sr-danger:"  . esc_attr($d['danger'] ?? '#f87171') . ";";
    $css .= "--sr-success:" . esc_attr($d['success'] ?? '#4ade80') . ";";
    $css .= "}\n";

    // fallback if theme doesn't set body class
    $css .= "body:not(.s-dark):not(.s-light) .sr-wrap{--sr-primary:#2563eb;--sr-bg:#ffffff;--sr-card:#ffffff;--sr-text:#0f172a;--sr-muted:#64748b;--sr-border:#e5e7eb;--sr-danger:#dc2626;--sr-success:#16a34a;}\n";

    return $css;
  }
}
