<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Assistant_Admin {
    private string $page_hook = '';
    private string $help_page_hook = '';

    // FIX: Single source of truth for required capability.
    // manage_options ensures administrators always see the menu.
    // WooCommerce shop managers have manage_woocommerce which implies manage_options is not guaranteed,
    // so we keep manage_options as the base and check woocommerce caps separately where needed.
    private const REQUIRED_CAP = 'manage_options';

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
        if ( $hook !== $this->page_hook && $hook !== $this->help_page_hook ) {
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

    public function render_admin_page(): void {
        // FIX: Uses the unified method – consistent with menu registration.
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $settings        = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );
        $settings        = is_array( $settings ) ? $settings : array();
        $provider_labels = ZenCoupon_AI_Assistant_Bridge::get_provider_labels();
        $provider_models = ZenCoupon_AI_Assistant_Bridge::get_provider_models();
        $ai_provider     = isset( $settings['ai_provider'] ) && isset( $provider_labels[ $settings['ai_provider'] ] )
            ? sanitize_text_field( $settings['ai_provider'] )
            : 'groq';
        $active_api_key  = isset( $settings[ $ai_provider . '_api_key' ] ) ? sanitize_text_field( $settings[ $ai_provider . '_api_key' ] ) : '';

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
                <div class="d-flex flex-column flex-md-row align-items-start justify-content-between mb-4">
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
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                    <p class="text-muted text-uppercase small fw-semibold mb-0"><?php esc_html_e( 'AI Provider Settings', 'zencoupon-ai-assistant' ); ?></p>
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
                                                    <input type="password" id="<?php echo esc_attr( $api_key_name ); ?>" name="<?php echo esc_attr( ZenCoupon_AI_Assistant_Main::OPTION_KEY ); ?>[<?php echo esc_attr( $api_key_name ); ?>]" value="<?php echo esc_attr( $api_value ); ?>" class="form-control zencoupon-api-key-field" autocomplete="off" />
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

                                <?php
                                if ( empty( $active_api_key ) ) :
                                ?>
                                    <div class="alert alert-info mt-3" role="alert">
                                        <?php
                                        printf(
                                            /* translators: %s is the selected provider label. */
                                            esc_html__( 'No API key saved for %s. The API key is required before commands can be processed.', 'zencoupon-ai-assistant' ),
                                            esc_html( $provider_labels[ $ai_provider ] )
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

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

    public function render_help_page(): void {
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistant' ) );
        }

        $current_user = wp_get_current_user();
        $reply_email  = $current_user instanceof WP_User ? $current_user->user_email : get_option( 'admin_email' );
        ?>
        <div class="wrap">
            <div class="container-fluid px-0">
                <div class="d-flex flex-column flex-md-row align-items-start justify-content-between mb-4">
                    <div>
                        <h1 class="h3 mb-1"><?php esc_html_e( 'ZenCoupon Docs & Support', 'zencoupon-ai-assistant' ); ?></h1>
                        <p class="text-muted mb-0"><?php esc_html_e( 'Provider setup, prompt examples, troubleshooting, and direct support.', 'zencoupon-ai-assistant' ); ?></p>
                    </div>
                    <a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ZenCoupon_AI_Assistant_Main::PLUGIN_SLUG ) ); ?>"><?php esc_html_e( 'Back to Console', 'zencoupon-ai-assistant' ); ?></a>
                </div>

                <div class="row g-4 zencoupon-main-grid">
                    <div class="col-lg-7">
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Quick Guide', 'zencoupon-ai-assistant' ); ?></p>
                                <h2 class="h5"><?php esc_html_e( 'Provider setup', 'zencoupon-ai-assistant' ); ?></h2>
                                <p><?php esc_html_e( 'Choose Groq, OpenAI/GPT, or Gemini from the provider settings, save the API key, choose a model, and run a test connection before generating live coupons.', 'zencoupon-ai-assistant' ); ?></p>

                                <h2 class="h5"><?php esc_html_e( 'Model guide', 'zencoupon-ai-assistant' ); ?></h2>
                                <ul>
                                    <li><?php esc_html_e( 'Groq is fast and useful for low-latency coupon commands.', 'zencoupon-ai-assistant' ); ?></li>
                                    <li><?php esc_html_e( 'OpenAI/GPT default is gpt-5.5; gpt-5.4-mini and gpt-5.4-nano are lower-cost options.', 'zencoupon-ai-assistant' ); ?></li>
                                    <li><?php esc_html_e( 'Gemini Flash models are useful for quick structured JSON output.', 'zencoupon-ai-assistant' ); ?></li>
                                </ul>

                                <h2 class="h5"><?php esc_html_e( 'Prompt examples', 'zencoupon-ai-assistant' ); ?></h2>
                                <ul>
                                    <li><code>create coupon 15% discount</code></li>
                                    <li><code>create coupon blackfriday 30% discount</code></li>
                                    <li><code>Create a SAVE20 coupon with 20% off, free shipping, expires next month</code></li>
                                </ul>

                                <h2 class="h5"><?php esc_html_e( 'Troubleshooting', 'zencoupon-ai-assistant' ); ?></h2>
                                <ul>
                                    <li><?php esc_html_e( 'If you see a missing API key error, save the selected provider key again.', 'zencoupon-ai-assistant' ); ?></li>
                                    <li><?php esc_html_e( 'If a model error appears, choose a listed model or enter a valid custom model ID.', 'zencoupon-ai-assistant' ); ?></li>
                                    <li><?php esc_html_e( 'If the AI returns invalid JSON, try the Polish Prompt button and run the command again.', 'zencoupon-ai-assistant' ); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Support', 'zencoupon-ai-assistant' ); ?></p>
                                <form id="zencoupon-support-form">
                                    <?php wp_nonce_field( 'zencoupon_admin', 'zencoupon_support_nonce', true, false ); ?>
                                    <div class="mb-3">
                                        <label class="form-label small text-muted" for="zencoupon-support-email"><?php esc_html_e( 'Reply email', 'zencoupon-ai-assistant' ); ?></label>
                                        <input type="email" id="zencoupon-support-email" name="reply_email" class="form-control" value="<?php echo esc_attr( $reply_email ); ?>" required />
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small text-muted" for="zencoupon-support-cc"><?php esc_html_e( 'CC email', 'zencoupon-ai-assistant' ); ?></label>
                                        <input type="email" id="zencoupon-support-cc" name="cc_email" class="form-control" />
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
        $tool_call = $bridge->call_ai( $command );

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

    public function sanitize_settings( array $input ): array {
        $output          = array();
        $current         = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );
        $current         = is_array( $current ) ? $current : array();
        $provider_labels = ZenCoupon_AI_Assistant_Bridge::get_provider_labels();

        $submitted_provider = isset( $input['ai_provider'] ) ? sanitize_text_field( $input['ai_provider'] ) : 'groq';
        $output['ai_provider'] = isset( $provider_labels[ $submitted_provider ] ) ? $submitted_provider : 'groq';

        foreach ( array_keys( $provider_labels ) as $provider ) {
            $api_key_name = $provider . '_api_key';
            $model_name   = $provider . '_model_name';

            $existing_key  = isset( $current[ $api_key_name ] ) ? sanitize_text_field( $current[ $api_key_name ] ) : '';
            $submitted_key = isset( $input[ $api_key_name ] ) ? sanitize_text_field( $input[ $api_key_name ] ) : '';

            $output[ $api_key_name ] = ( '********' === $submitted_key || '' === $submitted_key )
                ? $existing_key
                : $submitted_key;

            $submitted_model = isset( $input[ $model_name ] ) ? sanitize_text_field( $input[ $model_name ] ) : '';
            if ( 'groq' === $provider && 'llama3-8b-8192' === $submitted_model ) {
                $submitted_model = 'llama-3.1-8b-instant';
            }

            $output[ $model_name ] = '' !== trim( $submitted_model )
                ? $submitted_model
                : ZenCoupon_AI_Assistant_Bridge::get_default_model_for_provider( $provider );
        }

        return $output;
    }
}
