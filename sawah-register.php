<?php
/**
 * Plugin Name: Sawah Register
 * Description: Clean, theme-aware signup/login/profile plugin with Google & Facebook login, custom pages, and full color controls.
 * Version: 1.0.7
 * Author: Sawah Solutions
 * Text Domain: sawah-register
 */

if (!defined('ABSPATH')) exit;

define('SR_VERSION', '1.0.7');
define('SR_PATH', plugin_dir_path(__FILE__));
define('SR_URL', plugin_dir_url(__FILE__));

require_once SR_PATH . 'includes/class-sr-plugin.php';

register_activation_hook(__FILE__, ['SR_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SR_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
  SR_Plugin::instance();
});
