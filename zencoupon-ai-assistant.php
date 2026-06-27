<?php
/**
 * Plugin Name: ZenCoupon AI Assistant
 * Description: Generate and manage WooCommerce coupons using AI with natural language commands.
 * Version: 1.0.2
 * Author: Tusher Ikbal
 * Author URI: https://tusherikbal.online
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zencoupon-ai-assistant
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: 8.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce HPOS Compatibility
 */
add_action( 'before_woocommerce_init', function () {

    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/**
 * Main Plugin Class
 */
final class ZenCoupon_AI_Assistant_Main {

    public const VERSION        = '1.0.2';
    public const PLUGIN_SLUG    = 'zencoupon-ai-assistant';
    public const OPTION_KEY     = 'zencoupon_ai_assistant_settings';
    public const TEXT_DOMAIN    = 'zencoupon-ai-assistant';
    public const REST_NAMESPACE = 'zencoupon/v1';
    public const REST_ROUTE     = 'mcp';

    private static ?ZenCoupon_AI_Assistant_Main $instance = null;

    /**
     * Singleton Instance
     */
    public static function instance(): ZenCoupon_AI_Assistant_Main {

        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {

        $this->define_constants();

        add_action(
            'plugins_loaded',
            array( $this, 'on_plugins_loaded' )
        );
    }

    /**
     * Define Plugin Constants
     */
    private function define_constants(): void {

        if ( ! defined( 'ZENCOUPON_AI_ASSISTANT_DIR' ) ) {

            define(
                'ZENCOUPON_AI_ASSISTANT_DIR',
                plugin_dir_path( __FILE__ )
            );
        }

        if ( ! defined( 'ZENCOUPON_AI_ASSISTANT_URL' ) ) {

            define(
                'ZENCOUPON_AI_ASSISTANT_URL',
                plugin_dir_url( __FILE__ )
            );
        }
    }

    /**
     * On Plugins Loaded
     */
    public function on_plugins_loaded(): void {

        if ( ! class_exists( 'WooCommerce' ) ) {

            add_action(
                'admin_notices',
                array( $this, 'render_missing_woocommerce_notice' )
            );

            return;
        }

        $this->includes();

        add_action(
            'init',
            array( $this, 'init' )
        );
    }

    /**
     * Include Required Files
     */
    private function includes(): void {

        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-actions.php';
        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-woo-automation.php';
        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-mcp.php';
        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-ai-bridge.php';
        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-campaign-builder.php';
        require_once ZENCOUPON_AI_ASSISTANT_DIR . 'includes/class-zencoupon-admin.php';
    }

    /**
     * Init Plugin
     */
    public function init(): void {

        new ZenCoupon_AI_Assistant_MCP();
        new ZenCoupon_AI_Assistant_Woo_Automation();
        new ZenCoupon_AI_Assistant_Campaign_Builder();

        if ( is_admin() ) {
            new ZenCoupon_AI_Assistant_Admin();
        }
    }

    /**
     * Missing WooCommerce Notice
     */
    public function render_missing_woocommerce_notice(): void {

        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error is-dismissible">';
        echo '<p>';

        echo esc_html__(
            'ZenCoupon AI Assistant requires WooCommerce. Please install and activate WooCommerce before using this plugin.',
            'zencoupon-ai-assistant'
        );

        echo '</p>';
        echo '</div>';
    }
}

/**
 * Plugin Instance Helper
 */
function zencoupon_ai_assistant(): ZenCoupon_AI_Assistant_Main {

    return ZenCoupon_AI_Assistant_Main::instance();
}

/**
 * Plugin Activation
 */
function zencoupon_ai_assistant_activate(): void {

    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {

        deactivate_plugins( plugin_basename( __FILE__ ) );

        wp_die(
            esc_html__(
                'ZenCoupon AI Assistant requires PHP 7.4 or higher.',
                'zencoupon-ai-assistant'
            )
        );
    }

    /**
     * Existing Settings
     */
    $existing = get_option(
        ZenCoupon_AI_Assistant_Main::OPTION_KEY,
        array()
    );

    /**
     * Default Settings
     */
    $defaults = array(

        'ai_provider'       => 'groq',
        'groq_api_key'      => '',
        'groq_model_name'   => 'llama-3.1-8b-instant',
        'openai_api_key'    => '',
        'openai_model_name' => 'gpt-5.5',
        'gemini_api_key'    => '',
        'gemini_model_name' => 'gemini-2.5-flash',
        'automations'       => array(
            'first_order_coupon' => array(
                'enabled'              => 'no',
                'trigger_status'       => 'completed',
                'discount_type'        => 'percent',
                'discount_amount'      => '10',
                'expiry_days'          => '30',
                'usage_limit'          => '1',
                'usage_limit_per_user' => '1',
                'minimum_amount'       => '',
                'email_subject'        => __( 'Thanks for your first order! Here is your coupon', 'zencoupon-ai-assistant' ),
                'email_body'           => "Hi {customer_name},\n\nThanks for your first order at {store_name}.\n\nUse coupon code {coupon_code} to get {discount} on your next order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
            ),
        ),
    );

    /**
     * Merge Existing + Defaults
     */
    $settings = wp_parse_args(
        $existing,
        $defaults
    );

    /**
     * Force Replace Deprecated Groq Model
     */
    if (
        empty( $settings['groq_model_name'] ) ||
        'llama3-8b-8192' === $settings['groq_model_name']
    ) {

        $settings['groq_model_name'] = 'llama-3.1-8b-instant';
    }

    update_option(
        ZenCoupon_AI_Assistant_Main::OPTION_KEY,
        $settings
    );
}

/**
 * Plugin Deactivate
 */
function zencoupon_ai_assistant_deactivate(): void {

    delete_transient(
        'zencoupon_ai_assistant_stats'
    );

    delete_option(
        'zencoupon_ai_assistant_automation_events'
    );

    // Clear the recurring abandoned-cart processing event so it does not
    // linger in WP-Cron after the plugin is switched off.
    $abandoned_cart_cron = wp_next_scheduled( 'zencoupon_ai_assistant_check_abandoned_carts' );
    if ( false !== $abandoned_cart_cron ) {
        wp_unschedule_event( $abandoned_cart_cron, 'zencoupon_ai_assistant_check_abandoned_carts' );
    }
    wp_clear_scheduled_hook( 'zencoupon_ai_assistant_check_abandoned_carts' );
}

/**
 * Plugin Uninstall
 */
function zencoupon_ai_assistant_uninstall(): void {

    delete_option(
        ZenCoupon_AI_Assistant_Main::OPTION_KEY
    );

    delete_option(
        'zencoupon_ai_assistant_automation_events'
    );

    delete_option(
        'zencoupon_ai_assistant_abandoned_carts'
    );

    delete_option(
        'zencoupon_ai_assistant_campaigns'
    );

    delete_transient(
        'zencoupon_ai_assistant_stats'
    );

    wp_clear_scheduled_hook( 'zencoupon_ai_assistant_check_abandoned_carts' );
}

/**
 * Hooks
 */
register_activation_hook(
    __FILE__,
    'zencoupon_ai_assistant_activate'
);

register_deactivation_hook(
    __FILE__,
    'zencoupon_ai_assistant_deactivate'
);

register_uninstall_hook(
    __FILE__,
    'zencoupon_ai_assistant_uninstall'
);

/**
 * Boot Plugin
 */
zencoupon_ai_assistant();
