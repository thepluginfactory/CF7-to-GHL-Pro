<?php
/**
 * Plugin Name: CF7 to HighLevel Pro
 * Plugin URI: https://github.com/thepluginfactory/CF7-to-GHL-Pro
 * Description: Pro add-on for CF7 to HighLevel - adds per-form field mapping with support for all HighLevel contact fields and custom fields.
 * Version: 1.1.2
 * Author: The Plugin Factory
 * Author URI: https://github.com/thepluginfactory
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-to-highlevel-pro
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CF7_TO_GHL_PRO_VERSION', '1.1.2' );
define( 'CF7_TO_GHL_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF7_TO_GHL_PRO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Pro plugin class.
 */
final class CF7_To_HighLevel_Pro {

    /**
     * Single instance of the class.
     *
     * @var CF7_To_HighLevel_Pro
     */
    private static $instance = null;

    /**
     * Get the single instance.
     *
     * @return CF7_To_HighLevel_Pro
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
    }

    /**
     * Initialize the Pro plugin after all plugins are loaded.
     */
    public function init() {
        // Check if the free plugin is active.
        if ( ! class_exists( 'CF7_To_HighLevel' ) ) {
            add_action( 'admin_notices', array( $this, 'free_plugin_missing_notice' ) );
            return;
        }

        // Check if Contact Form 7 is active.
        if ( ! class_exists( 'WPCF7' ) ) {
            return; // Free plugin already shows a notice for this.
        }

        $this->includes();
        $this->init_components();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once CF7_TO_GHL_PRO_DIR . 'includes/class-pro-form-settings.php';
        require_once CF7_TO_GHL_PRO_DIR . 'includes/class-pro-api-handler.php';
    }

    /**
     * Initialize Pro components.
     */
    private function init_components() {
        CF7_To_GHL_Pro_Form_Settings::get_instance();
        CF7_To_GHL_Pro_API_Handler::get_instance();
    }

    /**
     * Admin notice when the free plugin is not active.
     */
    public function free_plugin_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: plugin name */
                    esc_html__( 'CF7 to HighLevel Pro requires the free %s plugin to be installed and activated.', 'cf7-to-highlevel-pro' ),
                    '<strong>CF7 to HighLevel</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Initialize the Pro plugin.
 *
 * @return CF7_To_HighLevel_Pro
 */
function cf7_to_highlevel_pro() {
    return CF7_To_HighLevel_Pro::get_instance();
}

// Start the Pro plugin.
cf7_to_highlevel_pro();
