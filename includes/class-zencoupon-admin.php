<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Assistant_Admin {
    private string $page_hook = '';
    private string $woo_automation_page_hook = '';
    private string $campaign_page_hook = '';
    private string $settings_page_hook = '';
    private string $help_page_hook = '';

    // FIX: Single source of truth for required capability.
    // manage_woocommerce is granted to both administrators and shop managers,
    // so the menu, page guards, AJAX handlers, and the MCP REST permission check
    // now all resolve to the same capability instead of drifting apart.
    private const REQUIRED_CAP = 'manage_woocommerce';

    // Placeholder rendered in place of a saved API key so the secret is never
    // exposed in page source. sanitize_settings() treats this exact value as
    // "keep the existing key", so submitting an untouched form preserves it.
    private const API_KEY_MASK = '********';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_execute_command', array( $this, 'ajax_execute_command' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_delete_coupon', array( $this, 'ajax_delete_coupon' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_refresh_generated_coupons', array( $this, 'ajax_refresh_generated_coupons' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_refresh_dashboard_stats', array( $this, 'ajax_refresh_dashboard_stats' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_send_support', array( $this, 'ajax_send_support' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_generate_campaign', array( $this, 'ajax_generate_campaign' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_start_campaign', array( $this, 'ajax_start_campaign' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_toggle_campaign', array( $this, 'ajax_toggle_campaign' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_send_test_email', array( $this, 'ajax_send_test_email' ) );
        add_action( 'wp_ajax_zencoupon_ai_assistant_regenerate_recipients', array( $this, 'ajax_regenerate_recipients' ) );
    }

    public function register_menu(): void {
        // FIX: Use the same REQUIRED_CAP constant so menu visibility
        // and access control are always in sync.
        $this->page_hook = add_menu_page(
            __( 'ZenCoupon AI Assistant', 'zencoupon-ai-assistant' ),
            __( 'ZenCoupon AI', 'zencoupon-ai-assistant' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG,
            array( $this, 'render_admin_page' ),
            'dashicons-smartphone',
            58
        );

        $this->woo_automation_page_hook = add_submenu_page(
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG,
            __( 'Woo Automation', 'zencoupon-ai-assistant' ),
            __( 'Woo Automation', 'zencoupon-ai-assistant' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-woo-automation',
            array( $this, 'render_woo_automation_page' )
        );

        $this->campaign_page_hook = add_submenu_page(
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG,
            __( 'AI Campaign Builder', 'zencoupon-ai-assistant' ),
            __( 'AI Campaign Builder', 'zencoupon-ai-assistant' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-campaigns',
            array( $this, 'render_campaign_builder_page' )
        );

        $this->settings_page_hook = add_submenu_page(
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG,
            __( 'Settings', 'zencoupon-ai-assistant' ),
            __( 'Settings', 'zencoupon-ai-assistant' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-settings',
            array( $this, 'render_settings_page' )
        );

        $this->help_page_hook = add_submenu_page(
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG,
            __( 'Docs & Support', 'zencoupon-ai-assistant' ),
            __( 'Docs & Support', 'zencoupon-ai-assistant' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help',
            array( $this, 'render_help_page' )
        );
    }

    // FIX: Now checks REQUIRED_CAP + WooCommerce caps consistently.
    // Both menu registration and all ajax handlers use this same method.
    private function current_user_can_manage_coupons(): bool {
        return current_user_can( self::REQUIRED_CAP )
            || current_user_can( 'manage_woocommerce' )
            || current_user_can( 'manage_shop_coupons' );
    }

    public function register_settings(): void {
        register_setting(
            'zencoupon_ai_assistant_settings',
            ZenCoupon_AI_Assistant_Main::OPTION_KEY,
            array(
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
            )
        );

    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->page_hook && $hook !== $this->woo_automation_page_hook && $hook !== $this->campaign_page_hook && $hook !== $this->settings_page_hook && $hook !== $this->help_page_hook ) {
            return;
        }

        wp_enqueue_style(
            'zencoupon-admin-style',
            ZENCOUPON_AI_ASSISTANT_URL . 'assets/css/admin.css',
            array(),
            ZenCoupon_AI_Assistant_Main::VERSION
        );

        wp_enqueue_script(
            'zencoupon-admin-script',
            ZENCOUPON_AI_ASSISTANT_URL . 'assets/js/admin.js',
            array(),
            ZenCoupon_AI_Assistant_Main::VERSION,
            true
        );

        wp_localize_script( 'zencoupon-admin-script', 'ZenCouponAIAssistantData', array(
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'zencoupon_admin' ),
            'provider_models'  => ZenCoupon_AI_Assistant_Bridge::get_provider_models(),
            'provider_labels'  => ZenCoupon_AI_Assistant_Bridge::get_provider_labels(),
            'help_url'         => admin_url( 'admin.php?page=' . ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help' ),
        ) );
    }

    /**
     * Renders the shared in-plugin top navigation bar.
     *
     * Mirrors the WordPress submenu items so users can switch between plugin
     * pages without leaving the plugin content area.
     *
     * @param string $current_slug The page slug currently being viewed.
     */
    private function render_top_nav( string $current_slug ): void {
        $items = array(
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG                     => __( 'Coupon Generator', 'zencoupon-ai-assistant' ),
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-woo-automation' => __( 'Woo Automation', 'zencoupon-ai-assistant' ),
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-campaigns'      => __( 'AI Campaign Builder', 'zencoupon-ai-assistant' ),
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-settings'       => __( 'Settings', 'zencoupon-ai-assistant' ),
            ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help'           => __( 'Docs & Support', 'zencoupon-ai-assistant' ),
        );
        ?>
        <nav class="zencoupon-topnav" aria-label="<?php esc_attr_e( 'ZenCoupon navigation', 'zencoupon-ai-assistant' ); ?>">
            <div class="zencoupon-topnav-brand">
                <span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
                <span><?php esc_html_e( 'ZenCoupon AI', 'zencoupon-ai-assistant' ); ?></span>
            </div>
            <div class="zencoupon-topnav-links">
                <?php foreach ( $items as $slug => $label ) : ?>
                    <a class="zencoupon-topnav-link <?php echo $current_slug === $slug ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"<?php echo $current_slug === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php
    }

    public function render_admin_page(): void {
        // FIX: Uses the unified method – consistent with menu registration.
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $product_categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );

        $actions           = new ZenCoupon_AI_Assistant_Actions();
        $generated_coupons = $actions->list_generated_coupons();
        $all_coupons       = $actions->list_coupons();

        $active_coupons   = count( $all_coupons['coupons'] ?? array() );
        $expiring_soon    = 0;
        $highest_discount = 0;
        $now              = time();

        foreach ( $all_coupons['coupons'] ?? array() as $coupon ) {
            if ( ! empty( $coupon['expiry_date'] ) ) {
                $expires = strtotime( $coupon['expiry_date'] );
                if ( $expires && $expires > $now && $expires <= $now + DAY_IN_SECONDS * 7 ) {
                    $expiring_soon++;
                }
            }

            if ( isset( $coupon['amount'] ) && is_numeric( $coupon['amount'] ) ) {
                $highest_discount = max( $highest_discount, floatval( $coupon['amount'] ) );
            }
        }

        $recent_activity = array();
        /* FIX: Replaced FILTER_SANITIZE_STRING with sanitize_text_field() */
        $settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
        if ( 'true' === $settings_updated ) {
            $recent_activity[] = array(
                'label'       => __( 'Settings updated', 'zencoupon-ai-assistant' ),
                'code'        => 'settings',
                'description' => __( 'AI provider settings were saved successfully.', 'zencoupon-ai-assistant' ),
            );
        }

        if ( ! empty( $generated_coupons['coupons'] ) ) {
            foreach ( array_slice( $generated_coupons['coupons'], 0, 5 ) as $coupon ) {
                $recent_activity[] = array(
                    'label'       => __( 'Coupon created', 'zencoupon-ai-assistant' ),
                    'code'        => $coupon['code'],
                    /* translators: %s is the coupon expiration date. */
                    'description' => sprintf( __( 'Expires: %s', 'zencoupon-ai-assistant' ), $coupon['expiry_date'] ?: __( 'Never', 'zencoupon-ai-assistant' ) ),
                );
            }
        }

        $alert_type    = '';
        $alert_message = '';

        /* FIX: Replaced FILTER_SANITIZE_STRING with sanitize_text_field() */
        $settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
        if ( 'true' === $settings_updated ) {
            $alert_type    = 'success';
            $alert_message = __( 'Settings saved successfully.', 'zencoupon-ai-assistant' );
        }

        /* FIX: Replaced FILTER_SANITIZE_STRING with sanitize_text_field() */
        $error_message = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        if ( $error_message ) {
            $alert_type    = 'danger';
            $alert_message = sanitize_text_field( $error_message );
        }
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <?php $this->render_top_nav( ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG ); ?>
                <div class="zencoupon-page-header d-flex flex-column flex-md-row align-items-start justify-content-between">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'ZenCoupon AI Assistant', 'zencoupon-ai-assistant' ); ?></h1>
                        <span class="badge bg-secondary">v<?php echo esc_html( ZenCoupon_AI_Assistant_Main::VERSION ); ?></span>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Active coupons', 'zencoupon-ai-assistant' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-active-coupons"><?php echo esc_html( $active_coupons ); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Expiring soon', 'zencoupon-ai-assistant' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-expiring-soon"><?php echo esc_html( $expiring_soon ); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Highest discount', 'zencoupon-ai-assistant' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-highest-discount"><?php echo esc_html( $highest_discount ); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4 zencoupon-main-grid">
                    <div class="col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <p class="text-muted text-uppercase small fw-semibold mb-0"><?php esc_html_e( 'Command Console', 'zencoupon-ai-assistant' ); ?></p>
                                </div>

                                <ul class="nav zencoupon-tabs mb-4" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="zencoupon-console-tab" type="button" data-zencoupon-target="#zencoupon-console" role="tab" aria-controls="zencoupon-console" aria-selected="true"><?php esc_html_e( 'Command Console', 'zencoupon-ai-assistant' ); ?></button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="zencoupon-rules-tab" type="button" data-zencoupon-target="#zencoupon-rules" role="tab" aria-controls="zencoupon-rules" aria-selected="false"><?php esc_html_e( 'Coupon Rules', 'zencoupon-ai-assistant' ); ?></button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="zencoupon-generated-tab" type="button" data-zencoupon-target="#zencoupon-generated" role="tab" aria-controls="zencoupon-generated" aria-selected="false"><?php esc_html_e( 'Generated Coupons', 'zencoupon-ai-assistant' ); ?></button>
                                    </li>
                                </ul>

                                <div class="tab-content">
                                    <div class="tab-pane active" id="zencoupon-console" role="tabpanel" aria-labelledby="zencoupon-console-tab">
                                        <form id="zencoupon-command-form" class="row g-3" novalidate>
                                            <?php wp_nonce_field( 'zencoupon_admin', 'zencoupon_nonce', true, false ); ?>
                                            <div class="col-12">
                                                <label class="form-label small text-muted" for="zencoupon-command"><?php esc_html_e( 'Natural language command', 'zencoupon-ai-assistant' ); ?></label>
                                                <textarea id="zencoupon-command" name="command" class="form-control" rows="4" placeholder="<?php esc_attr_e( 'Create a 15% discount for Summer Sale', 'zencoupon-ai-assistant' ); ?>"></textarea>
                                                <div class="mt-2 p-3 bg-info-subtle rounded">
                                                    <p class="mb-1 small fw-semibold"><?php esc_html_e( 'Suggested prompts', 'zencoupon-ai-assistant' ); ?>:</p>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <button type="button" class="btn btn-sm btn-outline-primary zencoupon-suggested-prompt" data-prompt="create coupon 15% discount"><?php esc_html_e( 'create coupon 15% discount', 'zencoupon-ai-assistant' ); ?></button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary zencoupon-suggested-prompt" data-prompt="create coupon blackfriday 30% discount"><?php esc_html_e( 'create coupon blackfriday 30% discount', 'zencoupon-ai-assistant' ); ?></button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-minimum-amount"><?php esc_html_e( 'Minimum spend', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="number" id="zencoupon-minimum-amount" name="minimum_amount" class="form-control" min="0" step="0.01" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-maximum-amount"><?php esc_html_e( 'Maximum spend', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="number" id="zencoupon-maximum-amount" name="maximum_amount" class="form-control" min="0" step="0.01" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-usage-limit"><?php esc_html_e( 'Usage limit', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="number" id="zencoupon-usage-limit" name="usage_limit" class="form-control" min="0" step="1" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-usage-limit-per-user"><?php esc_html_e( 'Usage limit per user', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="number" id="zencoupon-usage-limit-per-user" name="usage_limit_per_user" class="form-control" min="0" step="1" />
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-email-restrictions"><?php esc_html_e( 'Email restrictions', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="text" id="zencoupon-email-restrictions" name="email_restrictions" class="form-control" placeholder="<?php esc_attr_e( 'customer@example.com', 'zencoupon-ai-assistant' ); ?>" />
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-expiry-date"><?php esc_html_e( 'Expiry date', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="date" id="zencoupon-expiry-date" name="expiry_date" class="form-control" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-product-categories"><?php esc_html_e( 'Product categories', 'zencoupon-ai-assistant' ); ?></label>
                                                <select id="zencoupon-product-categories" name="product_categories[]" class="form-select" multiple>
                                                    <?php foreach ( $product_categories as $category ) : ?>
                                                        <option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-excluded-product-categories"><?php esc_html_e( 'Excluded categories', 'zencoupon-ai-assistant' ); ?></label>
                                                <select id="zencoupon-excluded-product-categories" name="excluded_product_categories[]" class="form-select" multiple>
                                                    <?php foreach ( $product_categories as $category ) : ?>
                                                        <option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted"><?php esc_html_e( 'Coupon options', 'zencoupon-ai-assistant' ); ?></label>
                                                <div class="d-flex flex-column gap-2">
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-individual-use">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-individual-use" name="individual_use" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Individual use only', 'zencoupon-ai-assistant' ); ?></span>
                                                    </label>
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-free-shipping">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-free-shipping" name="free_shipping" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Free shipping', 'zencoupon-ai-assistant' ); ?></span>
                                                    </label>
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-exclude-sale-items">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-exclude-sale-items" name="exclude_sale_items" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Exclude sale items', 'zencoupon-ai-assistant' ); ?></span>
                                                    </label>
                                                </div>
                                            </div>

                                            <?php if ( ! empty( $alert_message ) ) : ?>
                                                <div class="col-12">
                                                    <div class="alert alert-<?php echo esc_attr( $alert_type ); ?> zencoupon-alert" role="alert">
                                                        <?php echo esc_html( $alert_message ); ?>
                                                        <button type="button" class="zencoupon-close-button" aria-label="<?php esc_attr_e( 'Close', 'zencoupon-ai-assistant' ); ?>">&times;</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="col-12 d-flex gap-2 flex-wrap align-items-center">
                                                <button type="button" id="zencoupon-run-button" class="btn btn-primary zencoupon-btn"><?php esc_html_e( 'Send Command', 'zencoupon-ai-assistant' ); ?></button>
                                                <button type="button" id="zencoupon-polish-button" class="btn btn-outline-primary zencoupon-btn-secondary"><?php esc_html_e( 'Polish Prompt', 'zencoupon-ai-assistant' ); ?></button>
                                                <button type="button" id="zencoupon-reset-button" class="btn btn-outline-secondary zencoupon-btn-secondary"><?php esc_html_e( 'Reset', 'zencoupon-ai-assistant' ); ?></button>
                                                <a class="btn btn-outline-secondary zencoupon-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help' ) ); ?>"><?php esc_html_e( 'Docs', 'zencoupon-ai-assistant' ); ?></a>
                                                <div id="zencoupon-success-message" class="ms-auto text-success small fw-semibold" style="display:none;"></div>
                                            </div>

                                            <div class="col-12">
                                                <div id="zencoupon-status" class="text-muted"></div>
                                            </div>
                                            <div class="col-12">
                                                <div id="zencoupon-result"></div>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="tab-pane" id="zencoupon-rules" role="tabpanel" aria-labelledby="zencoupon-rules-tab">
                                        <p class="small text-muted mb-3"><?php esc_html_e( 'Use these rules to restrict generated coupons and enforce checkout policies.', 'zencoupon-ai-assistant' ); ?></p>
                                        <ul class="list-unstyled mb-0">
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Individual Use', 'zencoupon-ai-assistant' ); ?></span><?php esc_html_e( 'Restrict the coupon to one order per customer.', 'zencoupon-ai-assistant' ); ?></li>
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Free Shipping', 'zencoupon-ai-assistant' ); ?></span><?php esc_html_e( 'Apply free shipping when the coupon is used.', 'zencoupon-ai-assistant' ); ?></li>
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Exclude Sale Items', 'zencoupon-ai-assistant' ); ?></span><?php esc_html_e( 'Disable coupon application to sale-priced products.', 'zencoupon-ai-assistant' ); ?></li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="zencoupon-generated" role="tabpanel" aria-labelledby="zencoupon-generated-tab">
                                        <div id="zencoupon-generated-coupons-wrapper">
                                            <?php $this->render_generated_coupons_panel( $generated_coupons ); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Recent Activity', 'zencoupon-ai-assistant' ); ?></p>
                                <div id="zencoupon-recent-activity">
                                    <?php $this->render_recent_activity( $recent_activity ); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $settings         = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );
        $settings         = is_array( $settings ) ? $settings : array();
        $provider_labels  = ZenCoupon_AI_Assistant_Bridge::get_provider_labels();
        $provider_models  = ZenCoupon_AI_Assistant_Bridge::get_provider_models();
        $ai_provider      = isset( $settings['ai_provider'] ) && isset( $provider_labels[ $settings['ai_provider'] ] )
            ? sanitize_text_field( $settings['ai_provider'] )
            : 'groq';
        $active_api_key   = isset( $settings[ $ai_provider . '_api_key' ] ) ? sanitize_text_field( $settings[ $ai_provider . '_api_key' ] ) : '';
        $settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <?php $this->render_top_nav( ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-settings' ); ?>
                <div class="zencoupon-page-header d-flex flex-column flex-md-row align-items-start justify-content-between">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'Settings', 'zencoupon-ai-assistant' ); ?></h1>
                        <p class="text-muted mb-0"><?php esc_html_e( 'Manage shared ZenCoupon settings used by the coupon generator, campaign builder, and automation modules.', 'zencoupon-ai-assistant' ); ?></p>
                    </div>
                </div>

                <?php if ( 'true' === $settings_updated ) : ?>
                    <div class="alert alert-success zencoupon-alert" role="alert">
                        <?php esc_html_e( 'Settings saved successfully.', 'zencoupon-ai-assistant' ); ?>
                        <button type="button" class="zencoupon-close-button" aria-label="<?php esc_attr_e( 'Close', 'zencoupon-ai-assistant' ); ?>">&times;</button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                    <div>
                                        <p class="text-muted text-uppercase small fw-semibold mb-1"><?php esc_html_e( 'AI Integration', 'zencoupon-ai-assistant' ); ?></p>
                                        <h2 class="h4 mb-0"><?php esc_html_e( 'Provider & Model', 'zencoupon-ai-assistant' ); ?></h2>
                                    </div>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help' ) ); ?>"><?php esc_html_e( 'Docs & Support', 'zencoupon-ai-assistant' ); ?></a>
                                </div>

                                <form method="post" action="options.php">
                                    <?php settings_fields( 'zencoupon_ai_assistant_settings' ); ?>
                                    <div class="mb-3">
                                        <label class="form-label small text-muted" for="zencoupon-ai-provider"><?php esc_html_e( 'AI Provider', 'zencoupon-ai-assistant' ); ?></label>
                                        <select id="zencoupon-ai-provider" name="<?php echo esc_attr( ZenCoupon_AI_Assistant_Main::OPTION_KEY ); ?>[ai_provider]" class="form-select">
                                            <?php foreach ( $provider_labels as $provider_key => $provider_label ) : ?>
                                                <option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $ai_provider, $provider_key ); ?>><?php echo esc_html( $provider_label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <?php foreach ( $provider_labels as $provider_key => $provider_label ) : ?>
                                        <?php
                                        $api_key_name  = $provider_key . '_api_key';
                                        $model_name_id = $provider_key . '_model_name';
                                        $api_value     = isset( $settings[ $api_key_name ] ) ? sanitize_text_field( $settings[ $api_key_name ] ) : '';
                                        $model_value   = isset( $settings[ $model_name_id ] ) ? sanitize_text_field( $settings[ $model_name_id ] ) : ZenCoupon_AI_Assistant_Bridge::get_default_model_for_provider( $provider_key );
                                        if ( 'groq' === $provider_key && 'llama3-8b-8192' === $model_value ) {
                                            $model_value = 'llama-3.1-8b-instant';
                                        }
                                        $is_known_model = isset( $provider_models[ $provider_key ][ $model_value ] );
                                        ?>
                                        <div class="zencoupon-provider-settings" data-provider="<?php echo esc_attr( $provider_key ); ?>" style="display: <?php echo $ai_provider === $provider_key ? 'block' : 'none'; ?>;">
                                            <div class="mb-3">
                                                <label class="form-label small text-muted" for="<?php echo esc_attr( $api_key_name ); ?>"><?php echo esc_html( $provider_label ); ?> <?php esc_html_e( 'API Key', 'zencoupon-ai-assistant' ); ?></label>
                                                <div class="input-group">
                                                    <input type="password" id="<?php echo esc_attr( $api_key_name ); ?>" name="<?php echo esc_attr( ZenCoupon_AI_Assistant_Main::OPTION_KEY ); ?>[<?php echo esc_attr( $api_key_name ); ?>]" value="<?php echo esc_attr( '' !== $api_value ? self::API_KEY_MASK : '' ); ?>" class="form-control zencoupon-api-key-field" autocomplete="off" />
                                                    <button type="button" class="btn btn-outline-secondary zencoupon-toggle-api-key" data-field="<?php echo esc_attr( $api_key_name ); ?>"><?php esc_html_e( 'Show', 'zencoupon-ai-assistant' ); ?></button>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label small text-muted" for="<?php echo esc_attr( $model_name_id ); ?>_select"><?php echo esc_html( $provider_label ); ?> <?php esc_html_e( 'Model', 'zencoupon-ai-assistant' ); ?></label>
                                                <select id="<?php echo esc_attr( $model_name_id ); ?>_select" class="form-select zencoupon-model-select" data-input="<?php echo esc_attr( $model_name_id ); ?>">
                                                    <?php foreach ( $provider_models[ $provider_key ] as $model_id => $model_label ) : ?>
                                                        <option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model_value, $model_id ); ?>><?php echo esc_html( $model_label ); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom__" <?php selected( ! $is_known_model ); ?>><?php esc_html_e( 'Custom model', 'zencoupon-ai-assistant' ); ?></option>
                                                </select>
                                                <input type="text" id="<?php echo esc_attr( $model_name_id ); ?>" name="<?php echo esc_attr( ZenCoupon_AI_Assistant_Main::OPTION_KEY ); ?>[<?php echo esc_attr( $model_name_id ); ?>]" value="<?php echo esc_attr( $model_value ); ?>" class="form-control mt-2 zencoupon-model-input" data-provider="<?php echo esc_attr( $provider_key ); ?>" style="display: <?php echo $is_known_model ? 'none' : 'block'; ?>;" />
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Settings', 'zencoupon-ai-assistant' ); ?></button>
                                        <button type="button" class="btn btn-outline-secondary" id="zencoupon-test-connection"><?php esc_html_e( 'Test Connection', 'zencoupon-ai-assistant' ); ?></button>
                                        <span id="zencoupon-test-connection-result" class="small text-muted"></span>
                                    </div>
                                </form>

                                <?php if ( empty( $active_api_key ) ) : ?>
                                    <div class="alert alert-info mt-3" role="alert">
                                        <?php
                                        printf(
                                            /* translators: %s is the selected provider label. */
                                            esc_html__( 'No API key saved for %s. The API key is required before AI features can be processed.', 'zencoupon-ai-assistant' ),
                                            esc_html( $provider_labels[ $ai_provider ] )
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Settings Modules', 'zencoupon-ai-assistant' ); ?></p>
                                <p class="text-muted mb-0"><?php esc_html_e( 'More plugin settings can be added here later without crowding the coupon generator page.', 'zencoupon-ai-assistant' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Woo Automation settings page.
     */
    public function render_woo_automation_page(): void {
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $definitions      = ZenCoupon_AI_Assistant_Woo_Automation::get_automation_definitions();
        $settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
        $option_key       = ZenCoupon_AI_Assistant_Main::OPTION_KEY;
        $recent_events    = ZenCoupon_AI_Assistant_Woo_Automation::get_recent_events( 8 );
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <?php $this->render_top_nav( ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-woo-automation' ); ?>
                <div class="zencoupon-page-header d-flex flex-column flex-md-row align-items-start justify-content-between">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'Woo Automation', 'zencoupon-ai-assistant' ); ?></h1>
                        <p class="text-muted mb-0"><?php esc_html_e( 'Run coupon emails from saved WooCommerce triggers. No live AI request runs inside checkout, cart, or order hooks.', 'zencoupon-ai-assistant' ); ?></p>
                    </div>
                </div>

                <?php if ( 'true' === $settings_updated ) : ?>
                    <div class="alert alert-success zencoupon-alert" role="alert">
                        <?php esc_html_e( 'Woo Automation settings saved successfully.', 'zencoupon-ai-assistant' ); ?>
                        <button type="button" class="zencoupon-close-button" aria-label="<?php esc_attr_e( 'Close', 'zencoupon-ai-assistant' ); ?>">&times;</button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm zencoupon-automation-card">
                    <div class="card-body">
                        <div class="zencoupon-automation-layout">
                            <nav class="zencoupon-automation-nav" aria-label="<?php esc_attr_e( 'Woo automation campaigns', 'zencoupon-ai-assistant' ); ?>">
                                <?php $index = 0; ?>
                                <?php foreach ( $definitions as $automation_key => $definition ) : ?>
                                    <?php $automation = ZenCoupon_AI_Assistant_Woo_Automation::get_automation_settings( $automation_key ); ?>
                                    <button type="button" class="zencoupon-automation-nav-item <?php echo 0 === $index ? 'active' : ''; ?>" data-zencoupon-target="#zencoupon-automation-<?php echo esc_attr( $automation_key ); ?>" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>">
                                        <span><?php echo esc_html( $definition['label'] ); ?></span>
                                        <small><?php echo 'yes' === ( $automation['enabled'] ?? 'no' ) ? esc_html__( 'Enabled', 'zencoupon-ai-assistant' ) : esc_html__( 'Disabled', 'zencoupon-ai-assistant' ); ?></small>
                                    </button>
                                    <?php $index++; ?>
                                <?php endforeach; ?>
                            </nav>

                            <div class="zencoupon-automation-content">
                                <form method="post" action="options.php">
                                    <?php settings_fields( 'zencoupon_ai_assistant_settings' ); ?>
                                    <?php $index = 0; ?>
                                    <?php foreach ( $definitions as $automation_key => $definition ) : ?>
                                        <?php $automation = ZenCoupon_AI_Assistant_Woo_Automation::get_automation_settings( $automation_key ); ?>
                                        <?php $automation_prefix = $option_key . '[automations][' . $automation_key . ']'; ?>
                                        <div class="tab-pane <?php echo 0 === $index ? 'active' : ''; ?>" id="zencoupon-automation-<?php echo esc_attr( $automation_key ); ?>" role="tabpanel">
                                            <div class="zencoupon-automation-heading">
                                                <div>
                                                    <p class="text-muted text-uppercase small fw-semibold mb-1"><?php echo esc_html( $definition['label'] ); ?></p>
                                                    <h2 class="h4 mb-1"><?php echo esc_html( $definition['title'] ); ?></h2>
                                                    <p class="text-muted mb-0"><?php echo esc_html( $definition['description'] ); ?></p>
                                                </div>
                                                <span class="badge <?php echo 'yes' === ( $automation['enabled'] ?? 'no' ) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>"><?php echo 'yes' === ( $automation['enabled'] ?? 'no' ) ? esc_html__( 'Enabled', 'zencoupon-ai-assistant' ) : esc_html__( 'Disabled', 'zencoupon-ai-assistant' ); ?></span>
                                            </div>

                                            <div class="zencoupon-automation-section">
                                                <label class="d-flex align-items-center gap-2" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-enabled">
                                                    <input type="checkbox" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-enabled" name="<?php echo esc_attr( $automation_prefix ); ?>[enabled]" value="yes" <?php checked( $automation['enabled'], 'yes' ); ?> />
                                                    <span><?php esc_html_e( 'Enable this automation', 'zencoupon-ai-assistant' ); ?></span>
                                                </label>
                                            </div>

                                            <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ORDER_STATUS === $automation_key ) : ?>
                                                <?php $status_rules = isset( $automation['status_rules'] ) && is_array( $automation['status_rules'] ) ? $automation['status_rules'] : array(); ?>
                                                <div class="zencoupon-automation-section">
                                                    <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Status Rules', 'zencoupon-ai-assistant' ); ?></p>
                                                    <?php foreach ( $status_rules as $rule_key => $rule ) : ?>
                                                        <?php $rule_prefix = $automation_prefix . '[status_rules][' . sanitize_key( $rule_key ) . ']'; ?>
                                                        <details class="zencoupon-automation-accordion" <?php echo 'yes' === ( $rule['enabled'] ?? 'no' ) ? 'open' : ''; ?>>
                                                            <summary><?php echo esc_html( ucwords( str_replace( '_', ' ', sanitize_key( $rule_key ) ) ) ); ?> <?php esc_html_e( 'rule', 'zencoupon-ai-assistant' ); ?></summary>
                                                            <div class="zencoupon-automation-accordion-body">
                                                                <div class="row g-3">
                                                                    <div class="col-12">
                                                                        <label class="d-flex align-items-center gap-2" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-enabled">
                                                                            <input type="checkbox" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-enabled" name="<?php echo esc_attr( $rule_prefix ); ?>[enabled]" value="yes" <?php checked( $rule['enabled'], 'yes' ); ?> />
                                                                            <span><?php esc_html_e( 'Enable this status rule', 'zencoupon-ai-assistant' ); ?></span>
                                                                        </label>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-status"><?php esc_html_e( 'When order becomes', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <select id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-status" name="<?php echo esc_attr( $rule_prefix ); ?>[trigger_status]" class="form-select">
                                                                            <?php foreach ( ZenCoupon_AI_Assistant_Woo_Automation::get_order_status_options( false, false ) as $status_key => $status_label ) : ?>
                                                                                <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $rule['trigger_status'], $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-delay"><?php esc_html_e( 'Send after hours', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-delay" name="<?php echo esc_attr( $rule_prefix ); ?>[delay_hours]" class="form-control" min="0" max="720" step="1" value="<?php echo esc_attr( $rule['delay_hours'] ?? '0' ); ?>" />
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-discount-type"><?php esc_html_e( 'Discount type', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <select id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-discount-type" name="<?php echo esc_attr( $rule_prefix ); ?>[discount_type]" class="form-select">
                                                                            <option value="percent" <?php selected( $rule['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'zencoupon-ai-assistant' ); ?></option>
                                                                            <option value="fixed_cart" <?php selected( $rule['discount_type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'zencoupon-ai-assistant' ); ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-discount-amount"><?php esc_html_e( 'Discount amount', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-discount-amount" name="<?php echo esc_attr( $rule_prefix ); ?>[discount_amount]" class="form-control" min="0.01" step="0.01" value="<?php echo esc_attr( $rule['discount_amount'] ); ?>" />
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-expiry-days"><?php esc_html_e( 'Expiry days', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-expiry-days" name="<?php echo esc_attr( $rule_prefix ); ?>[expiry_days]" class="form-control" min="1" step="1" value="<?php echo esc_attr( $rule['expiry_days'] ); ?>" />
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-coupon-prefix"><?php esc_html_e( 'Coupon code prefix', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <input type="text" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-coupon-prefix" name="<?php echo esc_attr( $rule_prefix ); ?>[coupon_prefix]" class="form-control" value="<?php echo esc_attr( $rule['coupon_prefix'] ); ?>" maxlength="24" />
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-email-subject"><?php esc_html_e( 'Email subject', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <input type="text" id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-email-subject" name="<?php echo esc_attr( $rule_prefix ); ?>[email_subject]" class="form-control" value="<?php echo esc_attr( $rule['email_subject'] ); ?>" />
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-email-body"><?php esc_html_e( 'Email body', 'zencoupon-ai-assistant' ); ?></label>
                                                                        <textarea id="zencoupon-<?php echo esc_attr( $automation_key . '-' . $rule_key ); ?>-email-body" name="<?php echo esc_attr( $rule_prefix ); ?>[email_body]" class="form-control" rows="7"><?php echo esc_textarea( $rule['email_body'] ); ?></textarea>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </details>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                            <div class="zencoupon-automation-section">
                                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Trigger & Coupon Rules', 'zencoupon-ai-assistant' ); ?></p>
                                                <div class="row g-3">
                                                    <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ACCOUNT_CREATED !== $automation_key && ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ABANDONED_CART !== $automation_key ) : ?>
                                                        <?php
                                                        $status_options = ZenCoupon_AI_Assistant_Woo_Automation::get_order_status_options(
                                                            ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_FIRST_ORDER === $automation_key,
                                                            ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_FIRST_ORDER !== $automation_key
                                                        );
                                                        ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-trigger"><?php esc_html_e( 'Order status filter', 'zencoupon-ai-assistant' ); ?></label>
                                                            <select id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-trigger" name="<?php echo esc_attr( $automation_prefix ); ?>[trigger_status]" class="form-select">
                                                                <?php foreach ( $status_options as $status_key => $status_label ) : ?>
                                                                    <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $automation['trigger_status'] ?? '', $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-discount-type"><?php esc_html_e( 'Discount type', 'zencoupon-ai-assistant' ); ?></label>
                                                        <select id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-discount-type" name="<?php echo esc_attr( $automation_prefix ); ?>[discount_type]" class="form-select">
                                                            <option value="percent" <?php selected( $automation['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'zencoupon-ai-assistant' ); ?></option>
                                                            <option value="fixed_cart" <?php selected( $automation['discount_type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'zencoupon-ai-assistant' ); ?></option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-discount-amount"><?php esc_html_e( 'Discount amount', 'zencoupon-ai-assistant' ); ?></label>
                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-discount-amount" name="<?php echo esc_attr( $automation_prefix ); ?>[discount_amount]" class="form-control" min="0.01" step="0.01" value="<?php echo esc_attr( $automation['discount_amount'] ); ?>" />
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-expiry-days"><?php esc_html_e( 'Expiry days', 'zencoupon-ai-assistant' ); ?></label>
                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-expiry-days" name="<?php echo esc_attr( $automation_prefix ); ?>[expiry_days]" class="form-control" min="1" step="1" value="<?php echo esc_attr( $automation['expiry_days'] ); ?>" />
                                                    </div>
                                                    <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ACCOUNT_CREATED !== $automation_key && ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ABANDONED_CART !== $automation_key ) : ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-delay-hours"><?php esc_html_e( 'Send after hours', 'zencoupon-ai-assistant' ); ?></label>
                                                            <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-delay-hours" name="<?php echo esc_attr( $automation_prefix ); ?>[delay_hours]" class="form-control" min="0" max="720" step="1" value="<?php echo esc_attr( $automation['delay_hours'] ?? '0' ); ?>" />
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-usage-limit"><?php esc_html_e( 'Total usage limit', 'zencoupon-ai-assistant' ); ?></label>
                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-usage-limit" name="<?php echo esc_attr( $automation_prefix ); ?>[usage_limit]" class="form-control" min="1" step="1" value="<?php echo esc_attr( $automation['usage_limit'] ); ?>" />
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-usage-limit-per-user"><?php esc_html_e( 'Usage limit per user', 'zencoupon-ai-assistant' ); ?></label>
                                                        <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-usage-limit-per-user" name="<?php echo esc_attr( $automation_prefix ); ?>[usage_limit_per_user]" class="form-control" min="1" step="1" value="<?php echo esc_attr( $automation['usage_limit_per_user'] ); ?>" />
                                                    </div>
                                                    <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ABANDONED_CART !== $automation_key ) : ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-minimum-amount"><?php esc_html_e( 'Minimum spend', 'zencoupon-ai-assistant' ); ?></label>
                                                            <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-minimum-amount" name="<?php echo esc_attr( $automation_prefix ); ?>[minimum_amount]" class="form-control" min="0" step="0.01" value="<?php echo esc_attr( $automation['minimum_amount'] ); ?>" />
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-coupon-prefix"><?php esc_html_e( 'Coupon code prefix', 'zencoupon-ai-assistant' ); ?></label>
                                                        <input type="text" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-coupon-prefix" name="<?php echo esc_attr( $automation_prefix ); ?>[coupon_prefix]" class="form-control" value="<?php echo esc_attr( $automation['coupon_prefix'] ); ?>" maxlength="24" />
                                                    </div>
                                                </div>
                                            </div>

                                            <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ABANDONED_CART === $automation_key ) : ?>
                                                <div class="zencoupon-automation-section">
                                                    <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Cart Recovery Settings', 'zencoupon-ai-assistant' ); ?></p>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-abandoned-after-mins"><?php esc_html_e( 'Mark cart abandoned after (minutes)', 'zencoupon-ai-assistant' ); ?></label>
                                                            <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-abandoned-after-mins" name="<?php echo esc_attr( $automation_prefix ); ?>[abandoned_after_mins]" class="form-control" min="5" step="1" value="<?php echo esc_attr( $automation['abandoned_after_mins'] ?? '30' ); ?>" />
                                                            <small class="text-muted"><?php esc_html_e( 'Cart is considered abandoned after this period of inactivity', 'zencoupon-ai-assistant' ); ?></small>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-delay-hours"><?php esc_html_e( 'Send recovery email after (hours)', 'zencoupon-ai-assistant' ); ?></label>
                                                            <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-delay-hours" name="<?php echo esc_attr( $automation_prefix ); ?>[email_delay_hours]" class="form-control" min="0" max="24" step="1" value="<?php echo esc_attr( $automation['email_delay_hours'] ?? '0' ); ?>" />
                                                            <small class="text-muted"><?php esc_html_e( 'Delay before sending recovery email (0 = send immediately)', 'zencoupon-ai-assistant' ); ?></small>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-max-emails"><?php esc_html_e( 'Maximum recovery emails per cart', 'zencoupon-ai-assistant' ); ?></label>
                                                            <input type="number" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-max-emails" name="<?php echo esc_attr( $automation_prefix ); ?>[max_emails_per_cart]" class="form-control" min="1" step="1" value="<?php echo esc_attr( $automation['max_emails_per_cart'] ?? '3' ); ?>" />
                                                            <small class="text-muted"><?php esc_html_e( 'Maximum number of recovery emails to send for each abandoned cart', 'zencoupon-ai-assistant' ); ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="zencoupon-automation-section">
                                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Email', 'zencoupon-ai-assistant' ); ?></p>
                                                <div class="mb-3">
                                                    <label class="form-label small text-muted" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-subject"><?php esc_html_e( 'Email subject', 'zencoupon-ai-assistant' ); ?></label>
                                                    <input type="text" id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-subject" name="<?php echo esc_attr( $automation_prefix ); ?>[email_subject]" class="form-control" value="<?php echo esc_attr( $automation['email_subject'] ); ?>" />
                                                </div>

                                                <details class="zencoupon-automation-accordion" open>
                                                    <summary><?php esc_html_e( 'Email Body', 'zencoupon-ai-assistant' ); ?></summary>
                                                    <div class="zencoupon-automation-accordion-body">
                                                        <label class="screen-reader-text" for="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-body"><?php esc_html_e( 'Email body', 'zencoupon-ai-assistant' ); ?></label>
                                                        <textarea id="zencoupon-<?php echo esc_attr( $automation_key ); ?>-email-body" name="<?php echo esc_attr( $automation_prefix ); ?>[email_body]" class="form-control" rows="9"><?php echo esc_textarea( $automation['email_body'] ); ?></textarea>
                                                    </div>
                                                </details>

                                                <details class="zencoupon-automation-accordion">
                                                    <summary><?php esc_html_e( 'Placeholders', 'zencoupon-ai-assistant' ); ?></summary>
                                                    <div class="zencoupon-automation-accordion-body">
                                                        <div class="zencoupon-placeholder-chips">
                                                            <code>{customer_name}</code>
                                                            <code>{coupon_code}</code>
                                                            <code>{discount}</code>
                                                            <code>{expiry_date}</code>
                                                            <code>{store_name}</code>
                                                            <?php if ( ZenCoupon_AI_Assistant_Woo_Automation::AUTOMATION_ABANDONED_CART !== $automation_key ) : ?>
                                                                <code>{order_id}</code>
                                                                <code>{old_status}</code>
                                                                <code>{new_status}</code>
                                                            <?php else : ?>
                                                                <code>{cart_total}</code>
                                                                <code>{cart_items}</code>
                                                                <code>{recovery_link}</code>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </details>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php $index++; ?>
                                    <?php endforeach; ?>

                                    <div class="zencoupon-automation-actions">
                                        <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Woo Automation', 'zencoupon-ai-assistant' ); ?></button>
                                    </div>
                                </form>

                                <div class="zencoupon-automation-section mt-4">
                                    <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Recent Automation Events', 'zencoupon-ai-assistant' ); ?></p>
                                    <?php if ( ! empty( $recent_events ) ) : ?>
                                        <div class="table-responsive zc-table-wrap">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th><?php esc_html_e( 'Time', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'Automation', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'Event', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'Reason', 'zencoupon-ai-assistant' ); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $recent_events as $event ) : ?>
                                                        <tr>
                                                            <td><?php echo esc_html( $event['created_at'] ?? '' ); ?></td>
                                                            <td><?php echo esc_html( $definitions[ $event['automation_key'] ]['label'] ?? $event['automation_key'] ?? '' ); ?></td>
                                                            <td><?php echo esc_html( $event['event_type'] ?? '' ); ?></td>
                                                            <td><?php echo esc_html( $event['reason'] ?? '' ); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else : ?>
                                        <p class="text-muted mb-0"><?php esc_html_e( 'No automation events logged yet.', 'zencoupon-ai-assistant' ); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the AI Campaign Builder page.
     */
    public function render_campaign_builder_page(): void {
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $builder   = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $campaigns = array_reverse( $builder->get_campaigns() );
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <?php $this->render_top_nav( ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-campaigns' ); ?>
                <div class="zencoupon-page-header d-flex flex-column flex-md-row align-items-start justify-content-between">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'AI Campaign Builder', 'zencoupon-ai-assistant' ); ?></h1>
                        <p class="text-muted mb-0"><?php esc_html_e( 'Describe a campaign idea. AI drafts the copy and coupon rule; WooCommerce builds the customer list and sends the emails after you review and start it.', 'zencoupon-ai-assistant' ); ?></p>
                    </div>
                </div>

                <div class="notice notice-info zencoupon-beta-notice" style="margin: 0 0 1.25rem; padding: 12px 16px; border-left-width: 4px;">
                    <p style="margin: 0; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                        <span>
                            <strong><?php esc_html_e( 'AI Campaign Builder is currently in beta.', 'zencoupon-ai-assistant' ); ?></strong>
                            <?php esc_html_e( 'Our developers are actively working on improving this feature.', 'zencoupon-ai-assistant' ); ?>
                        </span>
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Campaign Idea', 'zencoupon-ai-assistant' ); ?></p>
                                <form id="zencoupon-campaign-form" class="row g-3" novalidate>
                                    <?php wp_nonce_field( 'zencoupon_admin', 'zencoupon_campaign_nonce', true, false ); ?>
                                    <div class="col-12">
                                        <label class="form-label small text-muted" for="zencoupon-campaign-idea"><?php esc_html_e( 'Describe your campaign', 'zencoupon-ai-assistant' ); ?></label>
                                        <textarea id="zencoupon-campaign-idea" name="idea" class="form-control" rows="4" placeholder="<?php esc_attr_e( 'E.g., Give 10% to inactive customers, or 15% to T-shirt buyers, or 20% to first-time buyers', 'zencoupon-ai-assistant' ); ?>"></textarea>
                                        <div class="small text-muted mt-2">
                                            <?php esc_html_e( 'Describe who should get the discount and by how much. Works best with: inactive customers, product/category buyers, or all customers.', 'zencoupon-ai-assistant' ); ?>
                                        </div>
                                    </div>
                                    <div class="col-12 d-flex gap-2 flex-wrap align-items-center">
                                        <button type="button" id="zencoupon-generate-campaign" class="btn btn-primary"><?php esc_html_e( 'Generate Draft', 'zencoupon-ai-assistant' ); ?></button>
                                        <span id="zencoupon-campaign-status" class="small text-muted"></span>
                                    </div>
                                </form>

                                <div id="zencoupon-campaign-draft" class="mt-4" style="display:none;">
                                    <p class="small text-muted mb-3"><?php esc_html_e( 'Review and edit the AI draft, then start the campaign. The targeted customer list is on the right.', 'zencoupon-ai-assistant' ); ?></p>

                                    <div class="zencoupon-automation-section" id="zencoupon-segment-detection" style="display:none;">
                                        <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Who Gets This Discount?', 'zencoupon-ai-assistant' ); ?></p>
                                        <div class="alert alert-info" role="alert">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-8">
                                                    <label for="zencoupon-segment-type-select" class="form-label small mb-1">
                                                        <?php esc_html_e( 'Customer Type', 'zencoupon-ai-assistant' ); ?>
                                                        <br><span class="text-secondary"><?php esc_html_e( 'AI suggested:', 'zencoupon-ai-assistant' ); ?> <strong id="zencoupon-segment-label"></strong></span>
                                                    </label>
                                                    <select id="zencoupon-segment-type-select" class="form-select form-select-sm">
                                                        <option value="winback"><?php esc_html_e( 'Win-back (inactive customers)', 'zencoupon-ai-assistant' ); ?></option>
                                                        <option value="category"><?php esc_html_e( 'Category buyers', 'zencoupon-ai-assistant' ); ?></option>
                                                        <option value="product"><?php esc_html_e( 'Product buyers', 'zencoupon-ai-assistant' ); ?></option>
                                                        <option value="tag"><?php esc_html_e( 'Tag buyers', 'zencoupon-ai-assistant' ); ?></option>
                                                        <option value="never_ordered"><?php esc_html_e( 'Never ordered (new customers)', 'zencoupon-ai-assistant' ); ?></option>
                                                        <option value="all_customers"><?php esc_html_e( 'All customers', 'zencoupon-ai-assistant' ); ?></option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="button" id="zencoupon-regenerate-recipients" class="btn btn-sm btn-outline-secondary w-100"><?php esc_html_e( 'Update List', 'zencoupon-ai-assistant' ); ?></button>
                                                </div>
                                            </div>
                                            <div id="zencoupon-regenerate-status" class="small mt-2"></div>
                                        </div>
                                    </div>

                                    <form id="zencoupon-campaign-review" class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label small text-muted" for="zencoupon-draft-name"><?php esc_html_e( 'Campaign name', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="text" id="zencoupon-draft-name" name="name" class="form-control" />
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted" for="zencoupon-draft-discount-type"><?php esc_html_e( 'Discount type', 'zencoupon-ai-assistant' ); ?></label>
                                            <select id="zencoupon-draft-discount-type" name="discount_type" class="form-select">
                                                <option value="percent"><?php esc_html_e( 'Percentage discount', 'zencoupon-ai-assistant' ); ?></option>
                                                <option value="fixed_cart"><?php esc_html_e( 'Fixed cart discount', 'zencoupon-ai-assistant' ); ?></option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted" for="zencoupon-draft-discount-amount"><?php esc_html_e( 'Discount amount', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="number" id="zencoupon-draft-discount-amount" name="discount_amount" class="form-control" min="0.01" step="0.01" />
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted" for="zencoupon-draft-expiry"><?php esc_html_e( 'Expiry days', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="number" id="zencoupon-draft-expiry" name="expiry_days" class="form-control" min="1" step="1" />
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted" for="zencoupon-draft-usage"><?php esc_html_e( 'Total usage limit', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="number" id="zencoupon-draft-usage" name="usage_limit" class="form-control" min="1" step="1" />
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted" for="zencoupon-draft-usage-user"><?php esc_html_e( 'Usage per user', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="number" id="zencoupon-draft-usage-user" name="usage_limit_per_user" class="form-control" min="1" step="1" />
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted" for="zencoupon-draft-subject"><?php esc_html_e( 'Email subject', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="text" id="zencoupon-draft-subject" name="email_subject" class="form-control" />
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted" for="zencoupon-draft-body"><?php esc_html_e( 'Email body', 'zencoupon-ai-assistant' ); ?></label>
                                            <textarea id="zencoupon-draft-body" name="email_body" class="form-control" rows="8"></textarea>
                                            <div class="zencoupon-placeholder-chips mt-2">
                                                <code>{customer_name}</code><code>{coupon_code}</code><code>{discount}</code><code>{expiry_date}</code><code>{store_name}</code>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted" for="zencoupon-draft-social"><?php esc_html_e( 'Social post copy (for your own use)', 'zencoupon-ai-assistant' ); ?></label>
                                            <textarea id="zencoupon-draft-social" name="social_copy" class="form-control" rows="3"></textarea>
                                        </div>
                                        <input type="hidden" id="zencoupon-segment-type" name="segment_type" value="winback" />
                                        <div id="zencoupon-segment-params-inputs"></div>
                                        <div class="col-12 d-flex gap-2 flex-wrap align-items-end">
                                            <div class="flex-grow-1">
                                                <label for="zencoupon-test-email" class="form-label small text-muted"><?php esc_html_e( 'Test Email Address', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="email" id="zencoupon-test-email" class="form-control form-control-sm" placeholder="your@email.com" />
                                            </div>
                                            <button type="button" id="zencoupon-send-test-email" class="btn btn-outline-secondary btn-sm"><?php esc_html_e( 'Send Test Email', 'zencoupon-ai-assistant' ); ?></button>
                                        </div>
                                        <div id="zencoupon-test-email-status" class="small mt-2"></div>
                                        <div class="col-12 d-flex gap-2 flex-wrap align-items-center">
                                            <button type="button" id="zencoupon-start-campaign" class="btn btn-primary"><?php esc_html_e( 'Start Campaign', 'zencoupon-ai-assistant' ); ?></button>
                                            <span id="zencoupon-start-status" class="small text-muted"></span>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card shadow-sm" id="zencoupon-audience-card" style="display:none;">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <p class="text-muted text-uppercase small fw-semibold mb-0"><?php esc_html_e( 'Targeted Customers', 'zencoupon-ai-assistant' ); ?></p>
                                    <span class="badge bg-primary-subtle text-primary"><strong id="zencoupon-campaign-count">0</strong></span>
                                </div>
                                <p class="small text-muted mb-3"><?php esc_html_e( 'Remove anyone you do not want to email before starting the campaign.', 'zencoupon-ai-assistant' ); ?></p>
                                <div id="zencoupon-audience-list" class="zencoupon-audience-list"></div>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Campaigns', 'zencoupon-ai-assistant' ); ?></p>
                                <div id="zencoupon-campaign-list">
                                    <?php if ( ! empty( $campaigns ) ) : ?>
                                        <div class="table-responsive zc-table-wrap">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th><?php esc_html_e( 'Campaign', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'Status', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'Sent', 'zencoupon-ai-assistant' ); ?></th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $campaigns as $campaign ) : ?>
                                                        <?php $campaign_status = $campaign['status'] ?? ''; ?>
                                                        <tr data-campaign-id="<?php echo esc_attr( $campaign['id'] ?? '' ); ?>">
                                                            <td><?php echo esc_html( $campaign['name'] ?? '' ); ?></td>
                                                            <td><span class="badge zencoupon-campaign-status-badge <?php echo 'completed' === $campaign_status ? 'bg-success-subtle text-success' : ( 'paused' === $campaign_status ? 'bg-secondary-subtle text-secondary' : 'bg-primary-subtle text-primary' ); ?>"><?php echo esc_html( ucfirst( $campaign_status ) ); ?></span></td>
                                                            <td><?php echo esc_html( absint( $campaign['sent'] ?? 0 ) . ' / ' . absint( $campaign['total'] ?? 0 ) ); ?></td>
                                                            <td>
                                                                <?php if ( 'completed' !== $campaign_status ) : ?>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm zencoupon-toggle-campaign" data-campaign-id="<?php echo esc_attr( $campaign['id'] ?? '' ); ?>"><?php echo 'running' === $campaign_status ? esc_html__( 'Pause', 'zencoupon-ai-assistant' ) : esc_html__( 'Resume', 'zencoupon-ai-assistant' ); ?></button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else : ?>
                                        <p class="text-muted mb-0"><?php esc_html_e( 'No campaigns yet. Generate a draft to get started.', 'zencoupon-ai-assistant' ); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_help_page(): void {
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $current_user = wp_get_current_user();
        $reply_email  = $current_user instanceof WP_User ? $current_user->user_email : get_option( 'admin_email' );
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <?php $this->render_top_nav( ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG . '-help' ); ?>
                <div class="zencoupon-page-header d-flex flex-column flex-md-row align-items-start justify-content-between">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'ZenCoupon Docs & Support', 'zencoupon-ai-assistant' ); ?></h1>
                        <p class="text-muted mb-0"><?php esc_html_e( 'Provider setup, prompt examples, troubleshooting, and direct support.', 'zencoupon-ai-assistant' ); ?></p>
                    </div>
                </div>

                <div class="card shadow-sm zencoupon-docs-card">
                    <div class="card-body">
                        <div class="zencoupon-docs-layout">
                            <nav class="zencoupon-docs-nav" aria-label="<?php esc_attr_e( 'Documentation sections', 'zencoupon-ai-assistant' ); ?>">
                                <button type="button" class="zencoupon-docs-nav-item active" data-zencoupon-docs-target="#zencoupon-docs-started" aria-controls="zencoupon-docs-started" aria-selected="true"><?php esc_html_e( 'Getting Started', 'zencoupon-ai-assistant' ); ?></button>
                                <button type="button" class="zencoupon-docs-nav-item" data-zencoupon-docs-target="#zencoupon-docs-providers" aria-controls="zencoupon-docs-providers" aria-selected="false"><?php esc_html_e( 'AI Providers', 'zencoupon-ai-assistant' ); ?></button>
                                <button type="button" class="zencoupon-docs-nav-item" data-zencoupon-docs-target="#zencoupon-docs-commands" aria-controls="zencoupon-docs-commands" aria-selected="false"><?php esc_html_e( 'Coupon Commands', 'zencoupon-ai-assistant' ); ?></button>
                                <button type="button" class="zencoupon-docs-nav-item" data-zencoupon-docs-target="#zencoupon-docs-automation" aria-controls="zencoupon-docs-automation" aria-selected="false"><?php esc_html_e( 'Woo Automation', 'zencoupon-ai-assistant' ); ?></button>
                                <button type="button" class="zencoupon-docs-nav-item" data-zencoupon-docs-target="#zencoupon-docs-troubleshooting" aria-controls="zencoupon-docs-troubleshooting" aria-selected="false"><?php esc_html_e( 'Troubleshooting', 'zencoupon-ai-assistant' ); ?></button>
                                <button type="button" class="zencoupon-docs-nav-item" data-zencoupon-docs-target="#zencoupon-docs-support" aria-controls="zencoupon-docs-support" aria-selected="false"><?php esc_html_e( 'Support', 'zencoupon-ai-assistant' ); ?></button>
                            </nav>

                            <div class="zencoupon-docs-content">
                                <section id="zencoupon-docs-started" class="zencoupon-docs-section active" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Getting Started', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'Set up ZenCoupon AI', 'zencoupon-ai-assistant' ); ?></h2>
                                    <ol>
                                        <li><?php esc_html_e( 'Choose your AI provider from the ZenCoupon AI Settings page.', 'zencoupon-ai-assistant' ); ?></li>
                                        <li><?php esc_html_e( 'Save the provider API key and model.', 'zencoupon-ai-assistant' ); ?></li>
                                        <li><?php esc_html_e( 'Run Test Connection before generating live coupons.', 'zencoupon-ai-assistant' ); ?></li>
                                        <li><?php esc_html_e( 'Use the Command Console or Woo Automation depending on your workflow.', 'zencoupon-ai-assistant' ); ?></li>
                                    </ol>
                                </section>

                                <section id="zencoupon-docs-providers" class="zencoupon-docs-section" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'AI Providers', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'Provider guide', 'zencoupon-ai-assistant' ); ?></h2>
                                    <details class="zencoupon-docs-accordion" open>
                                        <summary><?php esc_html_e( 'Model guide', 'zencoupon-ai-assistant' ); ?></summary>
                                        <div class="zencoupon-docs-accordion-body">
                                            <ul>
                                                <li><?php esc_html_e( 'Groq is fast and useful for low-latency coupon commands.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'OpenAI/GPT is useful for high-quality structured coupon output.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'Gemini Flash models are useful for quick structured JSON output.', 'zencoupon-ai-assistant' ); ?></li>
                                            </ul>
                                        </div>
                                    </details>
                                    <details class="zencoupon-docs-accordion">
                                        <summary><?php esc_html_e( 'Connection checklist', 'zencoupon-ai-assistant' ); ?></summary>
                                        <div class="zencoupon-docs-accordion-body">
                                            <ul>
                                                <li><?php esc_html_e( 'Confirm the selected provider has a saved API key.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'Use a listed model unless you know the exact custom model ID.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'If a provider fails, save settings again and test the connection.', 'zencoupon-ai-assistant' ); ?></li>
                                            </ul>
                                        </div>
                                    </details>
                                </section>

                                <section id="zencoupon-docs-commands" class="zencoupon-docs-section" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Coupon Commands', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'Prompt examples', 'zencoupon-ai-assistant' ); ?></h2>
                                    <div class="zencoupon-docs-code-list">
                                        <code>create coupon 15% discount</code>
                                        <code>create coupon blackfriday 30% discount</code>
                                        <code>Create a SAVE20 coupon with 20% off, free shipping, expires next month</code>
                                        <code>Update recent coupon to 20% discount</code>
                                    </div>
                                </section>

                                <section id="zencoupon-docs-automation" class="zencoupon-docs-section" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Woo Automation', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'First-order coupon automation', 'zencoupon-ai-assistant' ); ?></h2>
                                    <p><?php esc_html_e( 'Woo Automation can send a unique coupon after a customer places their first successful order. Choose Processing or Completed as the trigger status, then configure the coupon and email copy.', 'zencoupon-ai-assistant' ); ?></p>
                                    <details class="zencoupon-docs-accordion">
                                        <summary><?php esc_html_e( 'Email placeholders', 'zencoupon-ai-assistant' ); ?></summary>
                                        <div class="zencoupon-docs-accordion-body">
                                            <div class="zencoupon-placeholder-chips">
                                                <code>{customer_name}</code>
                                                <code>{coupon_code}</code>
                                                <code>{discount}</code>
                                                <code>{expiry_date}</code>
                                                <code>{store_name}</code>
                                                <code>{order_id}</code>
                                            </div>
                                        </div>
                                    </details>
                                </section>

                                <section id="zencoupon-docs-troubleshooting" class="zencoupon-docs-section" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Troubleshooting', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'Common fixes', 'zencoupon-ai-assistant' ); ?></h2>
                                    <details class="zencoupon-docs-accordion" open>
                                        <summary><?php esc_html_e( 'AI command issues', 'zencoupon-ai-assistant' ); ?></summary>
                                        <div class="zencoupon-docs-accordion-body">
                                            <ul>
                                                <li><?php esc_html_e( 'If you see a missing API key error, save the selected provider key again.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'If a model error appears, choose a listed model or enter a valid custom model ID.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'If the AI returns invalid JSON, try the Polish Prompt button and run the command again.', 'zencoupon-ai-assistant' ); ?></li>
                                            </ul>
                                        </div>
                                    </details>
                                    <details class="zencoupon-docs-accordion">
                                        <summary><?php esc_html_e( 'Automation email issues', 'zencoupon-ai-assistant' ); ?></summary>
                                        <div class="zencoupon-docs-accordion-body">
                                            <ul>
                                                <li><?php esc_html_e( 'Confirm Woo Automation is enabled and saved.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'Confirm the order reached the selected trigger status.', 'zencoupon-ai-assistant' ); ?></li>
                                                <li><?php esc_html_e( 'If mail is not delivered, check WordPress mail or SMTP configuration.', 'zencoupon-ai-assistant' ); ?></li>
                                            </ul>
                                        </div>
                                    </details>
                                </section>

                                <section id="zencoupon-docs-support" class="zencoupon-docs-section" role="tabpanel">
                                    <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Support', 'zencoupon-ai-assistant' ); ?></p>
                                    <h2 class="h4"><?php esc_html_e( 'Send a support request', 'zencoupon-ai-assistant' ); ?></h2>
                                    <form id="zencoupon-support-form">
                                        <?php wp_nonce_field( 'zencoupon_admin', 'zencoupon_support_nonce', true, false ); ?>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-support-email"><?php esc_html_e( 'Reply email', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="email" id="zencoupon-support-email" name="reply_email" class="form-control" value="<?php echo esc_attr( $reply_email ); ?>" required />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-support-cc"><?php esc_html_e( 'CC email', 'zencoupon-ai-assistant' ); ?></label>
                                                <input type="email" id="zencoupon-support-cc" name="cc_email" class="form-control" />
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="zencoupon-support-subject"><?php esc_html_e( 'Subject', 'zencoupon-ai-assistant' ); ?></label>
                                            <input type="text" id="zencoupon-support-subject" name="subject" class="form-control" required />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="zencoupon-support-message"><?php esc_html_e( 'Message', 'zencoupon-ai-assistant' ); ?></label>
                                            <textarea id="zencoupon-support-message" name="message" class="form-control" rows="7" required></textarea>
                                        </div>
                                        <p class="small text-muted mb-3">
                                            <?php esc_html_e( 'If the form does not send, email support directly at', 'zencoupon-ai-assistant' ); ?>
                                            <a href="mailto:tusherikbal20@gmail.com">tusherikbal20@gmail.com</a>.
                                        </p>
                                        <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Send Support Request', 'zencoupon-ai-assistant' ); ?></button>
                                        <div id="zencoupon-support-result" class="small text-muted mt-3"></div>
                                    </form>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $submitted_settings = isset( $_POST[ ZenCoupon_AI_Assistant_Main::OPTION_KEY ] ) && is_array( $_POST[ ZenCoupon_AI_Assistant_Main::OPTION_KEY ] )
            ? wp_unslash( $_POST[ ZenCoupon_AI_Assistant_Main::OPTION_KEY ] )
            : array();
        $settings = $this->sanitize_settings( (array) $submitted_settings );

        $bridge = new ZenCoupon_AI_Assistant_Bridge();
        $result = $bridge->test_connection( $settings );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
        }

        wp_send_json_success( array( 'message' => __( 'Connection successful. The selected provider returned valid tool JSON.', 'zencoupon-ai-assistant' ) ) );
    }

    public function ajax_send_support(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $reply_email = isset( $_POST['reply_email'] ) ? sanitize_email( wp_unslash( $_POST['reply_email'] ) ) : '';
        $cc_email    = isset( $_POST['cc_email'] ) ? sanitize_email( wp_unslash( $_POST['cc_email'] ) ) : '';
        $subject     = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $message     = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( $reply_email ) || ! is_email( $reply_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid reply email.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        if ( ! empty( $cc_email ) && ! is_email( $cc_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid CC email.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        if ( empty( $subject ) || empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Subject and message are required.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        $site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $admin_email = sanitize_email( get_option( 'admin_email' ) );
        if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
            $admin_email = $reply_email;
        }
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $reply_email,
        );

        if ( ! empty( $cc_email ) ) {
            $headers[] = 'Cc: ' . $cc_email;
        }

        $body = sprintf(
            "Site: %s\nAdmin URL: %s\nReply Email: %s\n\n%s",
            home_url(),
            admin_url(),
            $reply_email,
            $message
        );

        $sent = wp_mail(
            'tusherikbal20@gmail.com',
            '[ZenCoupon Support] ' . $subject,
            $body,
            $headers
        );

        if ( ! $sent ) {
            wp_send_json_error( array( 'message' => __( 'Support email could not be sent. Please check WordPress mail configuration.', 'zencoupon-ai-assistant' ) ), 500 );
        }

        wp_send_json_success( array( 'message' => __( 'Support request sent successfully.', 'zencoupon-ai-assistant' ) ) );
    }

    public function ajax_refresh_generated_coupons(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $actions           = new ZenCoupon_AI_Assistant_Actions();
        $generated_coupons = $actions->list_generated_coupons();

        ob_start();
        $this->render_generated_coupons_panel( $generated_coupons );
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    public function ajax_refresh_dashboard_stats(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $actions           = new ZenCoupon_AI_Assistant_Actions();
        $all_coupons       = $actions->list_coupons();
        $generated_coupons = $actions->list_generated_coupons();

        $active_coupons   = count( $all_coupons['coupons'] ?? array() );
        $expiring_soon    = 0;
        $highest_discount = 0;
        $now              = time();

        foreach ( $all_coupons['coupons'] ?? array() as $coupon ) {
            if ( ! empty( $coupon['expiry_date'] ) ) {
                $expires = strtotime( $coupon['expiry_date'] );
                if ( $expires && $expires > $now && $expires <= $now + DAY_IN_SECONDS * 7 ) {
                    $expiring_soon++;
                }
            }

            if ( isset( $coupon['amount'] ) && is_numeric( $coupon['amount'] ) ) {
                $highest_discount = max( $highest_discount, floatval( $coupon['amount'] ) );
            }
        }

        $recent_activity = array();
        if ( ! empty( $generated_coupons['coupons'] ) ) {
            foreach ( array_slice( $generated_coupons['coupons'], 0, 5 ) as $coupon ) {
                $recent_activity[] = array(
                    'label'       => __( 'Coupon created', 'zencoupon-ai-assistant' ),
                    'code'        => $coupon['code'],
                    /* translators: %s is the coupon expiration date. */
                    'description' => sprintf( __( 'Expires: %s', 'zencoupon-ai-assistant' ), $coupon['expiry_date'] ?: __( 'Never', 'zencoupon-ai-assistant' ) ),
                );
            }
        }

        ob_start();
        $this->render_recent_activity( $recent_activity );
        $recent_html = ob_get_clean();

        wp_send_json_success( array(
            'stats'       => array(
                'active_coupons'   => $active_coupons,
                'expiring_soon'    => $expiring_soon,
                'highest_discount' => $highest_discount,
            ),
            'recent_html' => $recent_html,
        ) );
    }

    private function render_recent_activity( array $recent_activity ): void {
        ?>
        <?php if ( ! empty( $recent_activity ) ) : ?>
            <?php foreach ( $recent_activity as $item ) : ?>
                <div class="mb-3 pb-2 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted"><?php echo esc_html( $item['label'] ); ?></span>
                        <?php if ( ! empty( $item['code'] ) ) : ?>
                            <span class="badge bg-primary-subtle text-primary font-monospace"><?php echo esc_html( $item['code'] ); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 small text-muted"><?php echo esc_html( $item['description'] ); ?></p>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p class="small text-muted mb-0"><?php esc_html_e( 'No recent activity available.', 'zencoupon-ai-assistant' ); ?></p>
        <?php endif; ?>
        <?php
    }

    private function render_generated_coupons_panel( array $generated_coupons ): void {
        ?>
        <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-3 gap-3">
            <div>
                <p class="text-muted text-uppercase small fw-semibold mb-1"><?php esc_html_e( 'Generated Coupons', 'zencoupon-ai-assistant' ); ?></p>
            </div>
            <div class="w-100 w-md-50">
                <input type="search" id="zencoupon-search-generated" class="form-control form-control-sm" placeholder="<?php esc_attr_e( 'Search coupons', 'zencoupon-ai-assistant' ); ?>" />
            </div>
        </div>

        <?php if ( ! empty( $generated_coupons['coupons'] ) ) : ?>
            <div class="table-responsive zc-table-wrap">
                <table class="table table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'ID', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Code', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Amount', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Type', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Categories', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Expires', 'zencoupon-ai-assistant' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Actions', 'zencoupon-ai-assistant' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $generated_coupons['coupons'] as $coupon ) : ?>
                            <tr data-coupon-id="<?php echo esc_attr( $coupon['coupon_id'] ); ?>">
                                <td><?php echo esc_html( $coupon['coupon_id'] ); ?></td>
                                <td><span class="badge bg-primary-subtle text-primary font-monospace"><?php echo esc_html( $coupon['code'] ); ?></span></td>
                                <td><?php echo esc_html( $coupon['amount'] ); ?></td>
                                <td><span class="badge bg-success-subtle text-success"><?php echo esc_html( $coupon['discount_type'] ); ?></span></td>
                                <td><?php
                                    $category_names = array();
                                    foreach ( (array) $coupon['product_categories'] as $category_id ) {
                                        $term = get_term( absint( $category_id ), 'product_cat' );
                                        if ( $term && ! is_wp_error( $term ) ) {
                                            $category_names[] = $term->name;
                                        }
                                    }
                                    echo esc_html( implode( ', ', $category_names ) );
                                ?></td>
                                <td><?php echo esc_html( $coupon['expiry_date'] ); ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm zencoupon-delete-coupon-button" data-coupon-id="<?php echo esc_attr( $coupon['coupon_id'] ); ?>"><?php esc_html_e( 'Delete', 'zencoupon-ai-assistant' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="mb-0 text-muted"><?php esc_html_e( 'No generated coupons found yet.', 'zencoupon-ai-assistant' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function ajax_execute_command(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $command      = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';
        $restrictions = array();

        if ( isset( $_POST['restrictions'] ) ) {
            $restrictions_raw = sanitize_text_field( wp_unslash( $_POST['restrictions'] ) );
            $restrictions     = json_decode( $restrictions_raw, true );
            if ( ! is_array( $restrictions ) ) {
                $restrictions = array();
            }
        }

        if ( empty( $command ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a command.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        $bridge    = new ZenCoupon_AI_Assistant_Bridge();
        $tool_call = $bridge->call_coupon_generator( $command );

        if ( is_wp_error( $tool_call ) ) {
            wp_send_json_error( array( 'message' => $tool_call->get_error_message() ), 500 );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool call received: ' . print_r( $tool_call, true ) );
        }

        if ( $this->is_update_command( $command ) ) {
            $tool_call = $this->force_update_tool_call( $tool_call, $command );

            if ( is_wp_error( $tool_call ) ) {
                wp_send_json_error( array( 'message' => $tool_call->get_error_message() ), 400 );
            }
        }

        if ( isset( $tool_call['name'], $tool_call['arguments'] ) && in_array( $tool_call['name'], array( 'create_coupon', 'update_coupon' ), true ) ) {
            $tool_call['arguments'] = array_merge( (array) $tool_call['arguments'], $restrictions );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool call after restrictions merge: ' . print_r( $tool_call, true ) );
        }

        $mcp    = new ZenCoupon_AI_Assistant_MCP();
        $result = $mcp->execute_tool_call( $tool_call );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool execution result: ' . print_r( $result, true ) );
        }

        wp_send_json_success( array( 'tool_call' => $tool_call, 'result' => $result ) );
    }

    private function is_update_command( string $command ): bool {
        return (bool) preg_match( '/\b(edit|update|change|modify|revise|adjust|existing|recent|latest|last)\b/i', $command );
    }

    private function force_update_tool_call( array $tool_call, string $command ) {
        $arguments = isset( $tool_call['arguments'] ) && is_array( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();
        $target    = $this->extract_update_target_from_command( $command );

        if ( empty( $target ) ) {
            return new WP_Error(
                'missing_update_target',
                __( 'Please mention a coupon ID/code, or use "recent/latest coupon" after at least one generated coupon exists.', 'zencoupon-ai-assistant' )
            );
        }

        unset( $arguments['code'] );
        $arguments = array_merge( $arguments, $target );

        return array(
            'name'      => 'update_coupon',
            'arguments' => $arguments,
        );
    }

    private function extract_update_target_from_command( string $command ): array {
        if ( preg_match( '/\b(?:coupon\s*)?(?:id|#)\s*:?\s*(\d+)\b/i', $command, $matches ) ) {
            return array( 'coupon_id' => absint( $matches[1] ) );
        }

        if ( preg_match( '/\b(recent|latest|last)\b/i', $command ) ) {
            return $this->get_latest_generated_coupon_target();
        }

        if ( preg_match( '/\b(?:code|coupon code)\s*:?\s*([A-Z0-9_-]{3,40})\b/i', $command, $matches ) ) {
            return array( 'code' => sanitize_text_field( $matches[1] ) );
        }

        return array();
    }

    private function get_latest_generated_coupon_target(): array {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to target the latest AI-generated coupon.
        $coupons = get_posts( array(
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'zencoupon_generated',
                    'value' => 'yes',
                ),
            ),
        ) );

        if ( empty( $coupons[0] ) ) {
            return array();
        }

        return array( 'coupon_id' => absint( $coupons[0] ) );
    }

    public function ajax_delete_coupon(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $coupon_id = isset( $_POST['coupon_id'] ) ? absint( wp_unslash( $_POST['coupon_id'] ) ) : 0;

        if ( $coupon_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid coupon ID.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        $actions = new ZenCoupon_AI_Assistant_Actions();
        $result  = $actions->delete_coupon( $coupon_id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ), 500 );
        }

        wp_send_json_success( $result );
    }

    public function ajax_generate_campaign(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $idea    = isset( $_POST['idea'] ) ? sanitize_textarea_field( wp_unslash( $_POST['idea'] ) ) : '';

        $builder = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $draft   = $builder->generate_draft( $idea );

        if ( is_wp_error( $draft ) ) {
            wp_send_json_error( array( 'message' => $draft->get_error_message() ), 400 );
        }

        $segment_type   = $draft['segment_type'] ?? 'winback';
        $segment_params = $draft['segment_params'] ?? array();
        $recipients     = $builder->resolve_segment( $segment_type, $segment_params );

        if ( is_wp_error( $recipients ) ) {
            wp_send_json_error( array( 'message' => $recipients->get_error_message() ), 400 );
        }

        // Cap the rows rendered in the review panel; the count still reflects all.
        $list = array();
        foreach ( array_slice( $recipients, 0, 200 ) as $recipient ) {
            $list[] = array(
                'name'  => $recipient['name'],
                'email' => $recipient['email'],
            );
        }

        wp_send_json_success(
            array(
                'draft'           => $draft,
                'total'           => count( $recipients ),
                'recipients'      => $list,
                'segment_type'    => $segment_type,
                'segment_params'  => $segment_params,
            )
        );
    }

    public function ajax_toggle_campaign(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
        if ( '' === $campaign_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid campaign.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        $builder  = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $campaign = $builder->toggle_campaign( $campaign_id );

        if ( is_wp_error( $campaign ) ) {
            wp_send_json_error( array( 'message' => $campaign->get_error_message() ), 400 );
        }

        wp_send_json_success(
            array(
                'status' => $campaign['status'],
                /* translators: %s is the new campaign status. */
                'message' => sprintf( __( 'Campaign %s.', 'zencoupon-ai-assistant' ), 'running' === $campaign['status'] ? __( 'resumed', 'zencoupon-ai-assistant' ) : __( 'paused', 'zencoupon-ai-assistant' ) ),
            )
        );
    }

    public function ajax_start_campaign(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $segment_params = array();
        if ( isset( $_POST['segment_params'] ) && is_array( wp_unslash( $_POST['segment_params'] ) ) ) {
            foreach ( wp_unslash( $_POST['segment_params'] ) as $key => $val ) {
                $segment_params[ sanitize_key( $key ) ] = is_numeric( $val ) ? absint( $val ) : sanitize_text_field( $val );
            }
        }

        $input = array(
            'name'                 => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'segment_type'         => isset( $_POST['segment_type'] ) ? sanitize_key( wp_unslash( $_POST['segment_type'] ) ) : 'winback',
            'segment_params'       => $segment_params,
            'discount_type'        => isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'percent',
            'discount_amount'      => isset( $_POST['discount_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_amount'] ) ) : '10',
            'expiry_days'          => isset( $_POST['expiry_days'] ) ? absint( wp_unslash( $_POST['expiry_days'] ) ) : 30,
            'usage_limit'          => isset( $_POST['usage_limit'] ) ? absint( wp_unslash( $_POST['usage_limit'] ) ) : 1,
            'usage_limit_per_user' => isset( $_POST['usage_limit_per_user'] ) ? absint( wp_unslash( $_POST['usage_limit_per_user'] ) ) : 1,
            'email_subject'        => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
            'email_body'           => isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '',
            'social_copy'          => isset( $_POST['social_copy'] ) ? sanitize_textarea_field( wp_unslash( $_POST['social_copy'] ) ) : '',
            'excluded_emails'      => isset( $_POST['excluded_emails'] ) && is_array( $_POST['excluded_emails'] )
                ? array_map( 'sanitize_email', wp_unslash( $_POST['excluded_emails'] ) )
                : array(),
        );

        $builder  = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $campaign = $builder->start_campaign( $input );

        if ( is_wp_error( $campaign ) ) {
            wp_send_json_error( array( 'message' => $campaign->get_error_message() ), 400 );
        }

        wp_send_json_success(
            array(
                /* translators: 1: campaign name, 2: number of customers. */
                'message' => sprintf( __( 'Campaign "%1$s" started for %2$d customers. Emails are being sent in the background.', 'zencoupon-ai-assistant' ), $campaign['name'], absint( $campaign['total'] ) ),
            )
        );
    }

    public function ajax_send_test_email(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $test_email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
        if ( ! is_email( $test_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'zencoupon-ai-assistant' ) ), 400 );
        }

        $campaign_data = array(
            'email_subject'        => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
            'email_body'           => isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '',
            'discount_type'        => isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'percent',
            'discount_amount'      => isset( $_POST['discount_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_amount'] ) ) : '10',
            'expiry_days'          => isset( $_POST['expiry_days'] ) ? absint( wp_unslash( $_POST['expiry_days'] ) ) : 30,
        );

        $builder = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $result  = $builder->send_test_email( $campaign_data, $test_email );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( $result );
    }

    public function ajax_regenerate_recipients(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistant' ) ), 403 );
        }

        $segment_type = isset( $_POST['segment_type'] ) ? sanitize_key( wp_unslash( $_POST['segment_type'] ) ) : 'winback';
        $segment_params = array();

        if ( isset( $_POST['segment_params'] ) && is_array( wp_unslash( $_POST['segment_params'] ) ) ) {
            foreach ( wp_unslash( $_POST['segment_params'] ) as $key => $val ) {
                $segment_params[ sanitize_key( $key ) ] = is_numeric( $val ) ? absint( $val ) : sanitize_text_field( $val );
            }
        }

        // For winback, use default days if not provided
        if ( 'winback' === $segment_type && empty( $segment_params['days'] ) ) {
            $segment_params['days'] = ZenCoupon_AI_Assistant_Campaign_Builder::default_segment_days();
        }

        $builder   = new ZenCoupon_AI_Assistant_Campaign_Builder();
        $recipients = $builder->resolve_segment( $segment_type, $segment_params );

        if ( is_wp_error( $recipients ) ) {
            wp_send_json_error( array( 'message' => $recipients->get_error_message() ), 400 );
        }

        // Cap to 200 for display
        $list = array();
        foreach ( array_slice( $recipients, 0, 200 ) as $recipient ) {
            $list[] = array(
                'name'  => $recipient['name'],
                'email' => $recipient['email'],
            );
        }

        wp_send_json_success(
            array(
                'total'      => count( $recipients ),
                'recipients' => $list,
            )
        );
    }

    public function sanitize_settings( array $input ): array {
        $output          = array();
        $current         = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );
        $current         = is_array( $current ) ? $current : array();
        $provider_labels = ZenCoupon_AI_Assistant_Bridge::get_provider_labels();

        $submitted_provider = isset( $input['ai_provider'] )
            ? sanitize_text_field( $input['ai_provider'] )
            : ( isset( $current['ai_provider'] ) ? sanitize_text_field( $current['ai_provider'] ) : 'groq' );
        $output['ai_provider'] = isset( $provider_labels[ $submitted_provider ] ) ? $submitted_provider : 'groq';

        foreach ( array_keys( $provider_labels ) as $provider ) {
            $api_key_name = $provider . '_api_key';
            $model_name   = $provider . '_model_name';

            $existing_key  = isset( $current[ $api_key_name ] ) ? sanitize_text_field( $current[ $api_key_name ] ) : '';
            $submitted_key = isset( $input[ $api_key_name ] ) ? sanitize_text_field( $input[ $api_key_name ] ) : '';

            $output[ $api_key_name ] = ( self::API_KEY_MASK === $submitted_key || '' === $submitted_key )
                ? $existing_key
                : $submitted_key;

            $submitted_model = isset( $input[ $model_name ] )
                ? sanitize_text_field( $input[ $model_name ] )
                : ( isset( $current[ $model_name ] ) ? sanitize_text_field( $current[ $model_name ] ) : '' );
            if ( 'groq' === $provider && 'llama3-8b-8192' === $submitted_model ) {
                $submitted_model = 'llama-3.1-8b-instant';
            }

            $output[ $model_name ] = '' !== trim( $submitted_model )
                ? $submitted_model
                : ZenCoupon_AI_Assistant_Bridge::get_default_model_for_provider( $provider );
        }

        $current_automations = isset( $current['automations'] ) && is_array( $current['automations'] ) ? $current['automations'] : array();
        $input_automations   = isset( $input['automations'] ) && is_array( $input['automations'] ) ? $input['automations'] : $current_automations;
        $output['automations'] = ZenCoupon_AI_Assistant_Woo_Automation::sanitize_automations(
            $input_automations,
            $current_automations
        );

        return $output;
    }
}
