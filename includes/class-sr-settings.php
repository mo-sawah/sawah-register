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
          // base
          'primary' => '#2563eb',
          'bg'      => '#f5f7fb',
          'card'    => '#ffffff',
          'text'    => '#0f172a',
          'muted'   => '#64748b',
          'border'  => '#e5e7eb',
          'danger'  => '#dc2626',
          'success' => '#16a34a',
          // dashboard UI
          'sidebar_bg'         => '#ffffff',
          'sidebar_text'       => '#0f172a',
          'sidebar_muted'      => '#64748b',
          'sidebar_active_bg'  => '#eef2ff',
          'sidebar_active_text'=> '#1d4ed8',
          'topbar_bg'          => '#0f3d2e',
          'topbar_text'        => '#ffffff',
          'topbar_chip_bg'     => 'rgba(255,255,255,0.10)',
          'icon_bg'            => 'rgba(255,255,255,0.14)',
          'shadow'             => 'rgba(15, 23, 42, 0.10)',
        ],
        'dark' => [
          // base
          'primary' => '#60a5fa',
          'bg'      => '#0b1220',
          'card'    => '#0f172a',
          'text'    => '#e5e7eb',
          'muted'   => '#94a3b8',
          'border'  => '#1f2a44',
          'danger'  => '#f87171',
          'success' => '#4ade80',
          // dashboard UI
          'sidebar_bg'         => '#0f172a',
          'sidebar_text'       => '#e5e7eb',
          'sidebar_muted'      => '#94a3b8',
          'sidebar_active_bg'  => 'rgba(96,165,250,0.14)',
          'sidebar_active_text'=> '#60a5fa',
          'topbar_bg'          => '#0b3a2d',
          'topbar_text'        => '#ffffff',
          'topbar_chip_bg'     => 'rgba(255,255,255,0.10)',
          'icon_bg'            => 'rgba(255,255,255,0.14)',
          'shadow'             => 'rgba(0, 0, 0, 0.30)',
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

    foreach (['login','signup','profile','lost'] as $k) {
      if (isset($input['pages'][$k])) $out['pages'][$k] = absint($input['pages'][$k]);
    }

    if (isset($input['redirect_after_login'])) {
      $allowed = ['profile','home','ref'];
      $v = sanitize_text_field($input['redirect_after_login']);
      $out['redirect_after_login'] = in_array($v, $allowed, true) ? $v : 'profile';
    }

    $color_keys = [
      'primary','bg','card','text','muted','border','danger','success',
      'sidebar_bg','sidebar_text','sidebar_muted','sidebar_active_bg','sidebar_active_text',
      'topbar_bg','topbar_text','topbar_chip_bg','icon_bg','shadow'
    ];

    foreach (['light','dark'] as $mode) {
      foreach ($color_keys as $c) {
        if (!isset($input['colors'][$mode][$c])) continue;
        $raw = trim((string)$input['colors'][$mode][$c]);

        // allow hex OR rgba() for a few keys
        if (in_array($c, ['topbar_chip_bg','icon_bg','shadow','sidebar_active_bg'], true)) {
          $out['colors'][$mode][$c] = sanitize_text_field($raw);
          continue;
        }

        $val = sanitize_hex_color($raw);
        if ($val) $out['colors'][$mode][$c] = $val;
      }
    }

    $out['google']['enabled'] = !empty($input['google']['enabled']) ? 1 : 0;
    $out['google']['client_id'] = isset($input['google']['client_id']) ? sanitize_text_field($input['google']['client_id']) : '';
    $out['google']['client_secret'] = isset($input['google']['client_secret']) ? sanitize_text_field($input['google']['client_secret']) : '';

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

    $color_keys = [
      'primary'=>'Primary',
      'bg'=>'Background',
      'card'=>'Card',
      'text'=>'Text',
      'muted'=>'Muted',
      'border'=>'Border',
      'danger'=>'Danger',
      'success'=>'Success',
      'sidebar_bg'=>'Sidebar BG',
      'sidebar_text'=>'Sidebar Text',
      'sidebar_muted'=>'Sidebar Muted',
      'sidebar_active_bg'=>'Sidebar Active BG',
      'sidebar_active_text'=>'Sidebar Active Text',
      'topbar_bg'=>'Topbar BG',
      'topbar_text'=>'Topbar Text',
      'topbar_chip_bg'=>'Topbar Chip BG (rgba ok)',
      'icon_bg'=>'Topbar Icon BG (rgba ok)',
      'shadow'=>'Shadow (rgba ok)',
    ];
    ?>
    <div class="wrap">
      <h1>Sawah Register</h1>
      <form method="post" action="options.php">
        <?php settings_fields('sawah_register_group'); ?>

        <h2 class="title">Pages</h2>
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
              <p class="description">OAuth Redirect URI: <code><?php echo esc_html($google_cb); ?></code></p>
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
              <p class="description">OAuth Redirect URI: <code><?php echo esc_html($fb_cb); ?></code></p>
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

        <?php foreach (['light'=>'Light mode (.s-light)', 'dark'=>'Dark mode (.s-dark)'] as $mode => $title): ?>
          <h3><?php echo esc_html($title); ?></h3>
          <table class="form-table" role="presentation">
            <?php foreach ($color_keys as $key => $label): ?>
              <tr>
                <th scope="row"><?php echo esc_html($label); ?></th>
                <td>
                  <input type="text" class="sr-color"
                    name="<?php echo esc_attr(self::OPT); ?>[colors][<?php echo esc_attr($mode); ?>][<?php echo esc_attr($key); ?>]"
                    value="<?php echo esc_attr($opt['colors'][$mode][$key] ?? ''); ?>">
                  <?php if (in_array($key, ['topbar_chip_bg','icon_bg','shadow','sidebar_active_bg'], true)): ?>
                    <p class="description">rgba() is allowed for this field.</p>
                  <?php endif; ?>
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
            // Color picker only for true hex fields (rgba fields can stay plain text)
            $('.sr-color').each(function(){
              var name = (this.getAttribute('name') || '');
              if (name.includes('[topbar_chip_bg]') || name.includes('[icon_bg]') || name.includes('[shadow]') || name.includes('[sidebar_active_bg]')) return;
              $(this).wpColorPicker();
            });
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

    $mk = function($arr, $key, $fallback){ return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $fallback; };

    $css = "";

    $css .= "body.s-light .sr-wrap{";
    $css .= "--sr-primary:" . esc_attr($mk($l,'primary','#2563eb')) . ";";
    $css .= "--sr-bg:"      . esc_attr($mk($l,'bg','#f5f7fb')) . ";";
    $css .= "--sr-card:"    . esc_attr($mk($l,'card','#ffffff')) . ";";
    $css .= "--sr-text:"    . esc_attr($mk($l,'text','#0f172a')) . ";";
    $css .= "--sr-muted:"   . esc_attr($mk($l,'muted','#64748b')) . ";";
    $css .= "--sr-border:"  . esc_attr($mk($l,'border','#e5e7eb')) . ";";
    $css .= "--sr-danger:"  . esc_attr($mk($l,'danger','#dc2626')) . ";";
    $css .= "--sr-success:" . esc_attr($mk($l,'success','#16a34a')) . ";";
    $css .= "--sr-sidebar-bg:"          . esc_attr($mk($l,'sidebar_bg','#ffffff')) . ";";
    $css .= "--sr-sidebar-text:"        . esc_attr($mk($l,'sidebar_text','#0f172a')) . ";";
    $css .= "--sr-sidebar-muted:"       . esc_attr($mk($l,'sidebar_muted','#64748b')) . ";";
    $css .= "--sr-sidebar-active-bg:"   . esc_attr($mk($l,'sidebar_active_bg','#eef2ff')) . ";";
    $css .= "--sr-sidebar-active-text:" . esc_attr($mk($l,'sidebar_active_text','#1d4ed8')) . ";";
    $css .= "--sr-topbar-bg:"           . esc_attr($mk($l,'topbar_bg','#0f3d2e')) . ";";
    $css .= "--sr-topbar-text:"         . esc_attr($mk($l,'topbar_text','#ffffff')) . ";";
    $css .= "--sr-topbar-chip-bg:"      . esc_attr($mk($l,'topbar_chip_bg','rgba(255,255,255,0.10)')) . ";";
    $css .= "--sr-icon-bg:"             . esc_attr($mk($l,'icon_bg','rgba(255,255,255,0.14)')) . ";";
    $css .= "--sr-shadow:"              . esc_attr($mk($l,'shadow','rgba(15, 23, 42, 0.10)')) . ";";
    $css .= "}\n";

    $css .= "body.s-dark .sr-wrap{";
    $css .= "--sr-primary:" . esc_attr($mk($d,'primary','#60a5fa')) . ";";
    $css .= "--sr-bg:"      . esc_attr($mk($d,'bg','#0b1220')) . ";";
    $css .= "--sr-card:"    . esc_attr($mk($d,'card','#0f172a')) . ";";
    $css .= "--sr-text:"    . esc_attr($mk($d,'text','#e5e7eb')) . ";";
    $css .= "--sr-muted:"   . esc_attr($mk($d,'muted','#94a3b8')) . ";";
    $css .= "--sr-border:"  . esc_attr($mk($d,'border','#1f2a44')) . ";";
    $css .= "--sr-danger:"  . esc_attr($mk($d,'danger','#f87171')) . ";";
    $css .= "--sr-success:" . esc_attr($mk($d,'success','#4ade80')) . ";";
    $css .= "--sr-sidebar-bg:"          . esc_attr($mk($d,'sidebar_bg','#0f172a')) . ";";
    $css .= "--sr-sidebar-text:"        . esc_attr($mk($d,'sidebar_text','#e5e7eb')) . ";";
    $css .= "--sr-sidebar-muted:"       . esc_attr($mk($d,'sidebar_muted','#94a3b8')) . ";";
    $css .= "--sr-sidebar-active-bg:"   . esc_attr($mk($d,'sidebar_active_bg','rgba(96,165,250,0.14)')) . ";";
    $css .= "--sr-sidebar-active-text:" . esc_attr($mk($d,'sidebar_active_text','#60a5fa')) . ";";
    $css .= "--sr-topbar-bg:"           . esc_attr($mk($d,'topbar_bg','#0b3a2d')) . ";";
    $css .= "--sr-topbar-text:"         . esc_attr($mk($d,'topbar_text','#ffffff')) . ";";
    $css .= "--sr-topbar-chip-bg:"      . esc_attr($mk($d,'topbar_chip_bg','rgba(255,255,255,0.10)')) . ";";
    $css .= "--sr-icon-bg:"             . esc_attr($mk($d,'icon_bg','rgba(255,255,255,0.14)')) . ";";
    $css .= "--sr-shadow:"              . esc_attr($mk($d,'shadow','rgba(0,0,0,0.30)')) . ";";
    $css .= "}\n";

    // fallback if theme doesn't set body class
    $css .= "body:not(.s-dark):not(.s-light) .sr-wrap{--sr-primary:#2563eb;--sr-bg:#f5f7fb;--sr-card:#ffffff;--sr-text:#0f172a;--sr-muted:#64748b;--sr-border:#e5e7eb;--sr-danger:#dc2626;--sr-success:#16a34a;--sr-sidebar-bg:#ffffff;--sr-sidebar-text:#0f172a;--sr-sidebar-muted:#64748b;--sr-sidebar-active-bg:#eef2ff;--sr-sidebar-active-text:#1d4ed8;--sr-topbar-bg:#0f3d2e;--sr-topbar-text:#ffffff;--sr-topbar-chip-bg:rgba(255,255,255,0.10);--sr-icon-bg:rgba(255,255,255,0.14);--sr-shadow:rgba(15,23,42,0.10);}";

    return $css;
  }
}
