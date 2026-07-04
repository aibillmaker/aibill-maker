<?php
/**
 * Plugin Name: AiBill Maker
 * Plugin URI: https://aibill.app/bill-maker
 * Description: Admin-only AI invoice maker for small business owners. Generate, view, edit, print, and download invoices from a prompt using the WordPress AI Client.
 * Version: 1.0.0
 * Author: AiBill
 * Author URI: https://aibill.app/
 * Text Domain: aibill-maker
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIBIMA_VERSION', '1.0.0');
define('AIBIMA_ASSET_VERSION', '1.0.0.2');
define('AIBIMA_FILE', __FILE__);
define('AIBIMA_DIR', plugin_dir_path(__FILE__));
define('AIBIMA_URL', plugin_dir_url(__FILE__));

require_once AIBIMA_DIR . 'includes/class-aibima-parser.php';
require_once AIBIMA_DIR . 'includes/class-aibima-ai.php';
require_once AIBIMA_DIR . 'includes/class-aibima-renderer.php';
require_once AIBIMA_DIR . 'includes/class-aibima-pdf.php';
require_once AIBIMA_DIR . 'includes/class-aibima.php';

register_activation_hook(__FILE__, array('AIBIMA_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('AIBIMA_Plugin', 'deactivate'));

add_action('plugins_loaded', static function () {
    AIBIMA_Plugin::instance();
});
