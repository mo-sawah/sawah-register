<?php
if (!defined('ABSPATH')) exit;

class SR_Header_Auth_Modal {

  public static function init() {
    add_action('wp_footer', [__CLASS__, 'render_modal']);
  }

  public static function is_enabled() {
    $opt = SR_Settings::get();
    return !empty($opt['header_auth']['enabled']);
  }

  public static function render_modal() {
    if (!self::is_enabled()) return;

    $opt = SR_Settings::get();

    // Social availability
    $google_on = !empty($opt['google']['enabled']) && !empty($opt['google']['client_id']) && !empty($opt['google']['client_secret']);
    $fb_on     = !empty($opt['facebook']['enabled']) && !empty($opt['facebook']['app_id']) && !empty($opt['facebook']['app_secret']);

    // Image + gradient
    $img = !empty($opt['header_auth']['image_url']) ? esc_url($opt['header_auth']['image_url']) : '';
    $g1  = !empty($opt['header_auth']['grad_from']) ? sanitize_hex_color($opt['header_auth']['grad_from']) : '#2563eb';
    $g2  = !empty($opt['header_auth']['grad_to'])   ? sanitize_hex_color($opt['header_auth']['grad_to'])   : '#38bdf8';

    // Return URL (current page)
    $return = home_url(add_query_arg([], isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'));

    // Messages from redirect
    $err = isset($_GET['sr_error']) ? sanitize_text_field(wp_unslash($_GET['sr_error'])) : '';
    $ok  = isset($_GET['sr_success']) ? sanitize_text_field(wp_unslash($_GET['sr_success'])) : '';

    ?>
    <div class="srh" id="srh-auth" aria-hidden="true" style="--srh-grad-1: <?php echo esc_attr($g1); ?>; --srh-grad-2: <?php echo esc_attr($g2); ?>;">
      <div class="srh__overlay" data-srh-close></div>

      <div class="srh__dialog" role="dialog" aria-modal="true" aria-label="Account">
        <button class="srh__back" type="button" data-srh-back aria-label="Back">
          <i class="fa-solid fa-arrow-left"></i>
        </button>

        <div class="srh__panel srh__panel--left">
          <div class="srh__head">
            <h3 class="srh__title">Sign into your account</h3>
            <p class="srh__sub">Login or create your account to continue.</p>
          </div>

          <div class="srh__tabs" role="tablist" aria-label="Auth Tabs">
            <button class="srh__tab is-active" type="button" data-srh-tab="login" role="tab" aria-selected="true">Log in</button>
            <button class="srh__tab" type="button" data-srh-tab="signup" role="tab" aria-selected="false">Sign up</button>
          </div>

          <?php if ($err): ?><div class="srh__alert srh__alert--error"><?php echo esc_html($err); ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="srh__alert srh__alert--ok"><?php echo esc_html($ok); ?></div><?php endif; ?>

          <div class="srh__body">

            <!-- LOGIN -->
            <div class="srh__pane is-active" data-srh-pane="login" role="tabpanel">
              <form class="srh__form" method="post">
                <input type="hidden" name="sr_action" value="login">
                <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">
                <input type="hidden" name="sr_modal_return" value="<?php echo esc_attr($return); ?>">

                <label class="srh__field">
                  <span>Email or Username</span>
                  <input type="text" name="sr_login" autocomplete="username" required>
                </label>

                <label class="srh__field">
                  <span>Password</span>
                  <div class="srh__pw">
                    <input type="password" name="sr_password" autocomplete="current-password" required>
                    <button class="srh__pwBtn" type="button" data-srh-togglepw aria-label="Toggle password"><i class="fa-regular fa-eye"></i></button>
                  </div>
                </label>

                <div class="srh__row">
                  <label class="srh__check">
                    <input type="checkbox" name="sr_remember" value="1">
                    <span>Remember me</span>
                  </label>

                  <?php $lost = SR_Pages::get_page_url('lost'); ?>
                  <?php if ($lost): ?>
                    <a class="srh__link" href="<?php echo esc_url($lost); ?>">Forgot password?</a>
                  <?php endif; ?>
                </div>

                <button class="srh__btn srh__btn--primary" type="submit">Log in</button>
              </form>

              <?php if ($google_on || $fb_on): ?>
                <div class="srh__divider"><span>or</span></div>
                <div class="srh__social">
                  <?php if ($google_on): ?>
                    <a class="srh__btn srh__btn--social" href="<?php echo esc_url(SR_Auth::start_url('google', $return)); ?>">
                      <i class="fa-brands fa-google"></i><span>Continue with Google</span>
                    </a>
                  <?php endif; ?>
                  <?php if ($fb_on): ?>
                    <a class="srh__btn srh__btn--social" href="<?php echo esc_url(SR_Auth::start_url('facebook', $return)); ?>">
                      <i class="fa-brands fa-facebook-f"></i><span>Continue with Facebook</span>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- SIGNUP -->
            <div class="srh__pane" data-srh-pane="signup" role="tabpanel">
              <form class="srh__form" method="post">
                <input type="hidden" name="sr_action" value="signup">
                <input type="hidden" name="_sr_nonce" value="<?php echo esc_attr(wp_create_nonce('sr_form')); ?>">
                <input type="hidden" name="sr_modal_return" value="<?php echo esc_attr($return); ?>">

                <label class="srh__field">
                  <span>Full name</span>
                  <input type="text" name="sr_name" autocomplete="name">
                </label>

                <label class="srh__field">
                  <span>Email</span>
                  <input type="email" name="sr_email" autocomplete="email" required>
                </label>

                <label class="srh__field">
                  <span>Password</span>
                  <div class="srh__pw">
                    <input type="password" name="sr_password" autocomplete="new-password" minlength="8" required>
                    <button class="srh__pwBtn" type="button" data-srh-togglepw aria-label="Toggle password"><i class="fa-regular fa-eye"></i></button>
                  </div>
                  <small class="srh__help">At least 8 characters.</small>
                </label>

                <button class="srh__btn srh__btn--primary" type="submit">Sign up</button>

                <div class="srh__tos">
                  By signing up, you agree to the <a class="srh__link" href="#">Terms of Service</a> and <a class="srh__link" href="#">Privacy Policy</a>.
                </div>
              </form>

              <?php if ($google_on || $fb_on): ?>
                <div class="srh__divider"><span>or</span></div>
                <div class="srh__social">
                  <?php if ($google_on): ?>
                    <a class="srh__btn srh__btn--social" href="<?php echo esc_url(SR_Auth::start_url('google', $return)); ?>">
                      <i class="fa-brands fa-google"></i><span>Continue with Google</span>
                    </a>
                  <?php endif; ?>
                  <?php if ($fb_on): ?>
                    <a class="srh__btn srh__btn--social" href="<?php echo esc_url(SR_Auth::start_url('facebook', $return)); ?>">
                      <i class="fa-brands fa-facebook-f"></i><span>Continue with Facebook</span>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <div class="srh__panel srh__panel--right" <?php echo $img ? 'style="background-image:url('.esc_url($img).')"' : ''; ?>>
          <div class="srh__quote">
            <div class="srh__quoteTxt">“Get the top notification about misinformation directly to your inbox.”</div>
            <div class="srh__quoteBy">Disinformation Commission</div>
          </div>
          <div class="srh__navArrows">
            <button type="button" class="srh__arrow" data-srh-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
          </div>
        </div>

      </div>
    </div>
    <?php
  }
}
