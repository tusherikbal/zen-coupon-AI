<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_Admin {
    private string $page_hook = '';

    // FIX: Single source of truth for required capability.
    // manage_options ensures administrators always see the menu.
    // WooCommerce shop managers have manage_woocommerce which implies manage_options is not guaranteed,
    // so we keep manage_options as the base and check woocommerce caps separately where needed.
    private const REQUIRED_CAP = 'manage_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_zencoupon_execute_command', array( $this, 'ajax_execute_command' ) );
        add_action( 'wp_ajax_zencoupon_delete_coupon', array( $this, 'ajax_delete_coupon' ) );
        add_action( 'wp_ajax_zencoupon_refresh_generated_coupons', array( $this, 'ajax_refresh_generated_coupons' ) );
        add_action( 'wp_ajax_zencoupon_refresh_dashboard_stats', array( $this, 'ajax_refresh_dashboard_stats' ) );
    }

    public function register_menu(): void {
        // FIX: Use the same REQUIRED_CAP constant so menu visibility
        // and access control are always in sync.
        $this->page_hook = add_menu_page(
            __( 'ZenCoupon AI Assistant', 'zencoupon-ai-assistance' ),
            __( 'ZenCoupon AI', 'zencoupon-ai-assistance' ),
            self::REQUIRED_CAP,
            ZenCoupon_AI_Assistant::PLUGIN_SLUG,
            array( $this, 'render_admin_page' ),
            'dashicons-smartphone',
            58
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
            ZenCoupon_AI_Assistant::OPTION_KEY,
            array(
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
            )
        );

        add_settings_section(
            'zencoupon_ai_assistant_api',
            __( 'Groq API Settings', 'zencoupon-ai-assistance' ),
            array( $this, 'render_api_section' ),
            'zencoupon_ai_assistant_settings'
        );

        add_settings_field(
            'ai_provider',
            __( 'AI Provider', 'zencoupon-ai-assistance' ),
            array( $this, 'render_ai_provider_field' ),
            'zencoupon_ai_assistant_settings',
            'zencoupon_ai_assistant_api'
        );

        add_settings_field(
            'groq_api_key',
            __( 'Groq API Key', 'zencoupon-ai-assistance' ),
            array( $this, 'render_groq_api_key_field' ),
            'zencoupon_ai_assistant_settings',
            'zencoupon_ai_assistant_api'
        );

        add_settings_field(
            'groq_model_name',
            __( 'Groq Model Name', 'zencoupon-ai-assistance' ),
            array( $this, 'render_groq_model_name_field' ),
            'zencoupon_ai_assistant_settings',
            'zencoupon_ai_assistant_api'
        );

        // add_settings_field(
        //     'gemini_api_key',
        //     __( 'Gemini API Key', 'zencoupon-ai-assistance' ),
        //     array( $this, 'render_gemini_api_key_field' ),
        //     'zencoupon_ai_assistant_settings',
        //     'zencoupon_ai_assistant_api'
        // );

        // add_settings_field(
        //     'gemini_model_name',
        //     __( 'Gemini Model Name', 'zencoupon-ai-assistance' ),
        //     array( $this, 'render_gemini_model_name_field' ),
        //     'zencoupon_ai_assistant_settings',
        //     'zencoupon_ai_assistant_api'
        // );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        wp_enqueue_style(
            'zencoupon-admin-style',
            ZENCUPON_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ZenCoupon_AI_Assistant::VERSION
        );

        wp_enqueue_script(
            'zencoupon-admin-script',
            ZENCUPON_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            ZenCoupon_AI_Assistant::VERSION,
            true
        );

        wp_localize_script( 'zencoupon-admin-script', 'ZenCouponAI', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'zencoupon_admin' ),
        ) );
    }

    public function render_api_section(): void {
        echo '<p>' . esc_html__( 'Configure your AI provider settings for natural language coupon generation.', 'zencoupon-ai-assistance' ) . '</p>';
    }

    public function render_ai_provider_field(): void {
        $settings = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $provider = isset( $settings['ai_provider'] ) ? sanitize_text_field( $settings['ai_provider'] ) : 'groq';

        printf(
            '<select id="ai_provider" name="%s[ai_provider]" class="regular-text">
                <option value="groq" %s>%s</option>
                <option value="gemini" %s>%s</option>
            </select>',
            esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ),
            selected( $provider, 'groq', false ),
            esc_html__( 'Groq', 'zencoupon-ai-assistance' ),
            selected( $provider, 'gemini', false ),
            esc_html__( 'Gemini', 'zencoupon-ai-assistance' )
        );
        echo '<p class="description">' . esc_html__( 'Choose your preferred AI provider for coupon generation.', 'zencoupon-ai-assistance' ) . '</p>';
    }

    public function render_groq_api_key_field(): void {
        $settings = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $api_key = isset( $settings['groq_api_key'] ) ? sanitize_text_field( $settings['groq_api_key'] ) : '';
        $display_value = $api_key ? '********' : '';

        printf(
            '<input type="password" id="groq_api_key" name="%s[groq_api_key]" value="%s" class="regular-text" autocomplete="new-password" placeholder="%s" />',
            esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ),
            esc_attr( $display_value ),
            esc_attr__( 'Enter Groq API key', 'zencoupon-ai-assistance' )
        );
    }

    public function render_groq_model_name_field(): void {
        $settings = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $model_name = isset( $settings['groq_model_name'] ) ? sanitize_text_field( $settings['groq_model_name'] ) : 'openai/gpt-oss-20b';

        printf(
            '<input type="text" id="groq_model_name" name="%s[groq_model_name]" value="%s" class="regular-text" />',
            esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ),
            esc_attr( $model_name )
        );
        echo '<p class="description">' . esc_html__( 'Use a Groq OpenAI-compatible model name, for example openai/gpt-oss-20b.', 'zencoupon-ai-assistance' ) . '</p>';
    }

    public function render_gemini_api_key_field(): void {
        $settings = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $api_key = isset( $settings['gemini_api_key'] ) ? sanitize_text_field( $settings['gemini_api_key'] ) : '';
        $display_value = $api_key ? '********' : '';

        printf(
            '<input type="password" id="gemini_api_key" name="%s[gemini_api_key]" value="%s" class="regular-text" autocomplete="new-password" placeholder="%s" />',
            esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ),
            esc_attr( $display_value ),
            esc_attr__( 'Enter Gemini API key', 'zencoupon-ai-assistance' )
        );
    }

    public function render_gemini_model_name_field(): void {
        $settings = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $model_name = isset( $settings['gemini_model_name'] ) ? sanitize_text_field( $settings['gemini_model_name'] ) : 'gemini-1.5-flash';

        printf(
            '<input type="text" id="gemini_model_name" name="%s[gemini_model_name]" value="%s" class="regular-text" />',
            esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ),
            esc_attr( $model_name )
        );
        echo '<p class="description">' . esc_html__( 'Use a Gemini model name, for example gemini-1.5-flash.', 'zencoupon-ai-assistance' ) . '</p>';
    }

    public function render_admin_page(): void {
        // FIX: Uses the unified method – consistent with menu registration.
        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_die( esc_html__( 'Unauthorized', 'zencoupon-ai-assistance' ) );
        }

        $settings   = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );
        $api_key    = isset( $settings['groq_api_key'] ) ? sanitize_text_field( $settings['groq_api_key'] ) : '';
        $model_name = isset( $settings['groq_model_name'] ) ? sanitize_text_field( $settings['groq_model_name'] ) : 'openai/gpt-oss-20b';

        $product_categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );

        $actions           = new ZenCoupon_Actions();
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
                'label'       => __( 'Settings updated', 'zencoupon-ai-assistance' ),
                'code'        => 'settings',
                'description' => __( 'Groq settings were saved successfully.', 'zencoupon-ai-assistance' ),
            );
        }

        if ( ! empty( $generated_coupons['coupons'] ) ) {
            foreach ( array_slice( $generated_coupons['coupons'], 0, 5 ) as $coupon ) {
                $recent_activity[] = array(
                    'label'       => __( 'Coupon created', 'zencoupon-ai-assistance' ),
                    'code'        => $coupon['code'],
                    /* translators: %s is the coupon expiration date. */
                    'description' => sprintf( __( 'Expires: %s', 'zencoupon-ai-assistance' ), $coupon['expiry_date'] ?: __( 'Never', 'zencoupon-ai-assistance' ) ),
                );
            }
        }

        $alert_type    = '';
        $alert_message = '';

        /* FIX: Replaced FILTER_SANITIZE_STRING with sanitize_text_field() */
        $settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
        if ( 'true' === $settings_updated ) {
            $alert_type    = 'success';
            $alert_message = __( 'Settings saved successfully.', 'zencoupon-ai-assistance' );
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
                        <h1 class="h3 mb-1"><?php esc_html_e( 'ZenCoupon AI Assistant', 'zencoupon-ai-assistance' ); ?></h1>
                        <span class="badge bg-secondary">v<?php echo esc_html( ZenCoupon_AI_Assistant::VERSION ); ?></span>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Active coupons', 'zencoupon-ai-assistance' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-active-coupons"><?php echo esc_html( $active_coupons ); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Expiring soon', 'zencoupon-ai-assistance' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-expiring-soon"><?php echo esc_html( $expiring_soon ); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-2"><?php esc_html_e( 'Highest discount', 'zencoupon-ai-assistance' ); ?></p>
                                <h3 class="mb-0" id="zencoupon-highest-discount"><?php echo esc_html( $highest_discount ); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-7">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <p class="text-muted text-uppercase small fw-semibold mb-0"><?php esc_html_e( 'Command Console', 'zencoupon-ai-assistance' ); ?></p>
                                </div>

                                <ul class="nav zencoupon-tabs mb-4" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="zencoupon-console-tab" type="button" data-zencoupon-target="#zencoupon-console" role="tab" aria-controls="zencoupon-console" aria-selected="true"><?php esc_html_e( 'Command Console', 'zencoupon-ai-assistance' ); ?></button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="zencoupon-rules-tab" type="button" data-zencoupon-target="#zencoupon-rules" role="tab" aria-controls="zencoupon-rules" aria-selected="false"><?php esc_html_e( 'Coupon Rules', 'zencoupon-ai-assistance' ); ?></button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="zencoupon-generated-tab" type="button" data-zencoupon-target="#zencoupon-generated" role="tab" aria-controls="zencoupon-generated" aria-selected="false"><?php esc_html_e( 'Generated Coupons', 'zencoupon-ai-assistance' ); ?></button>
                                    </li>
                                </ul>

                                <div class="tab-content">
                                    <div class="tab-pane active" id="zencoupon-console" role="tabpanel" aria-labelledby="zencoupon-console-tab">
                                        <form id="zencoupon-command-form" class="row g-3" novalidate>
                                            <?php wp_nonce_field( 'zencoupon_admin', 'zencoupon_nonce', true, false ); ?>
                                            <div class="col-12">
                                                <label class="form-label small text-muted" for="zencoupon-command"><?php esc_html_e( 'Natural language command', 'zencoupon-ai-assistance' ); ?></label>
                                                <textarea id="zencoupon-command" name="command" class="form-control" rows="4" placeholder="<?php esc_attr_e( 'Create a 15% discount for Summer Sale', 'zencoupon-ai-assistance' ); ?>"></textarea>
                                                <div class="mt-2 p-3 bg-info-subtle rounded">
                                                    <p class="mb-1 small fw-semibold"><?php esc_html_e( 'Suggested prompts', 'zencoupon-ai-assistance' ); ?>:</p>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <button type="button" class="btn btn-sm btn-outline-primary zencoupon-suggested-prompt" data-prompt="create coupon 15% discount"><?php esc_html_e( 'create coupon 15% discount', 'zencoupon-ai-assistance' ); ?></button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary zencoupon-suggested-prompt" data-prompt="create coupon blackfriday 30% discount"><?php esc_html_e( 'create coupon blackfriday 30% discount', 'zencoupon-ai-assistance' ); ?></button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-minimum-amount"><?php esc_html_e( 'Minimum spend', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="number" id="zencoupon-minimum-amount" name="minimum_amount" class="form-control" min="0" step="0.01" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-maximum-amount"><?php esc_html_e( 'Maximum spend', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="number" id="zencoupon-maximum-amount" name="maximum_amount" class="form-control" min="0" step="0.01" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-usage-limit"><?php esc_html_e( 'Usage limit', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="number" id="zencoupon-usage-limit" name="usage_limit" class="form-control" min="0" step="1" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-usage-limit-per-user"><?php esc_html_e( 'Usage limit per user', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="number" id="zencoupon-usage-limit-per-user" name="usage_limit_per_user" class="form-control" min="0" step="1" />
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-email-restrictions"><?php esc_html_e( 'Email restrictions', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="text" id="zencoupon-email-restrictions" name="email_restrictions" class="form-control" placeholder="<?php esc_attr_e( 'customer@example.com', 'zencoupon-ai-assistance' ); ?>" />
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-expiry-date"><?php esc_html_e( 'Expiry date', 'zencoupon-ai-assistance' ); ?></label>
                                                <input type="date" id="zencoupon-expiry-date" name="expiry_date" class="form-control" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-product-categories"><?php esc_html_e( 'Product categories', 'zencoupon-ai-assistance' ); ?></label>
                                                <select id="zencoupon-product-categories" name="product_categories[]" class="form-select" multiple>
                                                    <?php foreach ( $product_categories as $category ) : ?>
                                                        <option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small text-muted" for="zencoupon-excluded-product-categories"><?php esc_html_e( 'Excluded categories', 'zencoupon-ai-assistance' ); ?></label>
                                                <select id="zencoupon-excluded-product-categories" name="excluded_product_categories[]" class="form-select" multiple>
                                                    <?php foreach ( $product_categories as $category ) : ?>
                                                        <option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small text-muted"><?php esc_html_e( 'Coupon options', 'zencoupon-ai-assistance' ); ?></label>
                                                <div class="d-flex flex-column gap-2">
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-individual-use">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-individual-use" name="individual_use" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Individual use only', 'zencoupon-ai-assistance' ); ?></span>
                                                    </label>
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-free-shipping">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-free-shipping" name="free_shipping" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Free shipping', 'zencoupon-ai-assistance' ); ?></span>
                                                    </label>
                                                    <label class="d-flex align-items-center gap-2" for="zencoupon-exclude-sale-items">
                                                        <input class="form-check-input" type="checkbox" id="zencoupon-exclude-sale-items" name="exclude_sale_items" value="1">
                                                        <span class="small text-muted"><?php esc_html_e( 'Exclude sale items', 'zencoupon-ai-assistance' ); ?></span>
                                                    </label>
                                                </div>
                                            </div>

                                            <?php if ( ! empty( $alert_message ) ) : ?>
                                                <div class="col-12">
                                                    <div class="alert alert-<?php echo esc_attr( $alert_type ); ?> zencoupon-alert" role="alert">
                                                        <?php echo esc_html( $alert_message ); ?>
                                                        <button type="button" class="zencoupon-close-button" aria-label="<?php esc_attr_e( 'Close', 'zencoupon-ai-assistance' ); ?>">&times;</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="col-12 d-flex gap-2 flex-wrap align-items-center">
                                                <button type="button" id="zencoupon-run-button" class="btn btn-primary zencoupon-btn"><?php esc_html_e( 'Send Command', 'zencoupon-ai-assistance' ); ?></button>
                                                <button type="button" id="zencoupon-reset-button" class="btn btn-outline-secondary zencoupon-btn-secondary"><?php esc_html_e( 'Reset', 'zencoupon-ai-assistance' ); ?></button>
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
                                        <p class="small text-muted mb-3"><?php esc_html_e( 'Use these rules to restrict generated coupons and enforce checkout policies.', 'zencoupon-ai-assistance' ); ?></p>
                                        <ul class="list-unstyled mb-0">
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Individual Use', 'zencoupon-ai-assistance' ); ?></span><?php esc_html_e( 'Restrict the coupon to one order per customer.', 'zencoupon-ai-assistance' ); ?></li>
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Free Shipping', 'zencoupon-ai-assistance' ); ?></span><?php esc_html_e( 'Apply free shipping when the coupon is used.', 'zencoupon-ai-assistance' ); ?></li>
                                            <li class="mb-2"><span class="badge bg-success-subtle text-success me-2"><?php esc_html_e( 'Exclude Sale Items', 'zencoupon-ai-assistance' ); ?></span><?php esc_html_e( 'Disable coupon application to sale-priced products.', 'zencoupon-ai-assistance' ); ?></li>
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
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'AI Provider Settings', 'zencoupon-ai-assistance' ); ?></p>
                                <form method="post" action="options.php">
                                    <?php settings_fields( 'zencoupon_ai_assistant_settings' ); ?>
                                    <div class="mb-3">
                                        <label class="form-label small text-muted" for="ai_provider"><?php esc_html_e( 'AI Provider', 'zencoupon-ai-assistance' ); ?></label>
                                        <select id="ai_provider" name="<?php echo esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ); ?>[ai_provider]" class="form-select">
                                            <option value="groq" <?php selected( $settings['ai_provider'] ?? 'groq', 'groq' ); ?>><?php esc_html_e( 'Groq', 'zencoupon-ai-assistance' ); ?></option>
                                            <option value="gemini" <?php selected( $settings['ai_provider'] ?? 'groq', 'gemini' ); ?>><?php esc_html_e( 'Gemini', 'zencoupon-ai-assistance' ); ?></option>
                                        </select>
                                        <div class="form-text"><?php esc_html_e( 'Choose your preferred AI provider for coupon generation.', 'zencoupon-ai-assistance' ); ?></div>
                                    </div>

                                    <div id="groq-settings" class="provider-settings" style="display: <?php echo ( ($settings['ai_provider'] ?? 'groq') === 'groq' ) ? 'block' : 'none'; ?>;">
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="groq_api_key"><?php esc_html_e( 'Groq API Key', 'zencoupon-ai-assistance' ); ?></label>
                                            <div class="input-group">
                                                <input type="password" id="groq_api_key" name="<?php echo esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ); ?>[groq_api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="form-control" autocomplete="off" />
                                                <button type="button" class="btn btn-outline-secondary" id="zencoupon-toggle-api-key"><?php esc_html_e( 'Show', 'zencoupon-ai-assistance' ); ?></button>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="groq_model_name"><?php esc_html_e( 'Groq Model Name', 'zencoupon-ai-assistance' ); ?></label>
                                            <input type="text" id="groq_model_name" name="<?php echo esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ); ?>[groq_model_name]" value="<?php echo esc_attr( $model_name ); ?>" class="form-control" />
                                            <div class="form-text"><?php esc_html_e( 'Use a Groq OpenAI-compatible model name, for example llama3-8b-8192.', 'zencoupon-ai-assistance' ); ?></div>
                                        </div>
                                    </div>

                                    <div id="gemini-settings" class="provider-settings" style="display: <?php echo ( ($settings['ai_provider'] ?? 'groq') === 'gemini' ) ? 'block' : 'none'; ?>;">
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'zencoupon-ai-assistance' ); ?></label>
                                            <div class="input-group">
                                                <input type="password" id="gemini_api_key" name="<?php echo esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ); ?>[gemini_api_key]" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>" class="form-control" autocomplete="off" />
                                                <button type="button" class="btn btn-outline-secondary" id="zencoupon-toggle-gemini-api-key"><?php esc_html_e( 'Show', 'zencoupon-ai-assistance' ); ?></button>
                                            </div>
                                            <div class="form-text"><?php esc_html_e( 'Get your API key from Google AI Studio (https://makersuite.google.com/app/apikey).', 'zencoupon-ai-assistance' ); ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small text-muted" for="gemini_model_name"><?php esc_html_e( 'Gemini Model Name', 'zencoupon-ai-assistance' ); ?></label>
                                            <input type="text" id="gemini_model_name" name="<?php echo esc_attr( ZenCoupon_AI_Assistant::OPTION_KEY ); ?>[gemini_model_name]" value="<?php echo esc_attr( $settings['gemini_model_name'] ?? 'gemini-pro' ); ?>" class="form-control" />
                                            <div class="form-text"><?php esc_html_e( 'Use a Gemini model name, for example gemini-pro.', 'zencoupon-ai-assistance' ); ?></div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Settings', 'zencoupon-ai-assistance' ); ?></button>
                                </form>

                                <?php
                                $current_provider = $settings['ai_provider'] ?? 'groq';
                                $current_api_key = ( $current_provider === 'groq' ) ? $api_key : ( $settings['gemini_api_key'] ?? '' );
                                if ( empty( $current_api_key ) ) :
                                ?>
                                    <div class="alert alert-info mt-3" role="alert">
                                        <?php // translators: %s is the selected AI provider name. ?>
                                        <?php printf( esc_html__( 'No API key saved for %s. The API key is required before commands can be processed.', 'zencoupon-ai-assistance' ), esc_html( ucfirst( $current_provider ) ) ); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div class="card-body">
                                <p class="text-muted text-uppercase small fw-semibold mb-3"><?php esc_html_e( 'Recent Activity', 'zencoupon-ai-assistance' ); ?></p>
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

    public function ajax_refresh_generated_coupons(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistance' ) ), 403 );
        }

        $actions           = new ZenCoupon_Actions();
        $generated_coupons = $actions->list_generated_coupons();

        ob_start();
        $this->render_generated_coupons_panel( $generated_coupons );
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    public function ajax_refresh_dashboard_stats(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistance' ) ), 403 );
        }

        $actions           = new ZenCoupon_Actions();
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
                    'label'       => __( 'Coupon created', 'zencoupon-ai-assistance' ),
                    'code'        => $coupon['code'],
                    /* translators: %s is the coupon expiration date. */
                    'description' => sprintf( __( 'Expires: %s', 'zencoupon-ai-assistance' ), $coupon['expiry_date'] ?: __( 'Never', 'zencoupon-ai-assistance' ) ),
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
            <p class="small text-muted mb-0"><?php esc_html_e( 'No recent activity available.', 'zencoupon-ai-assistance' ); ?></p>
        <?php endif; ?>
        <?php
    }

    private function render_generated_coupons_panel( array $generated_coupons ): void {
        ?>
        <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-3 gap-3">
            <div>
                <p class="text-muted text-uppercase small fw-semibold mb-1"><?php esc_html_e( 'Generated Coupons', 'zencoupon-ai-assistance' ); ?></p>
            </div>
            <div class="w-100 w-md-50">
                <input type="search" id="zencoupon-search-generated" class="form-control form-control-sm" placeholder="<?php esc_attr_e( 'Search coupons', 'zencoupon-ai-assistance' ); ?>" />
            </div>
        </div>

        <?php if ( ! empty( $generated_coupons['coupons'] ) ) : ?>
            <div class="table-responsive zc-table-wrap">
                <table class="table table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'ID', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Code', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Amount', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Type', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Categories', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Expires', 'zencoupon-ai-assistance' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Actions', 'zencoupon-ai-assistance' ); ?></th>
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
                                    <button type="button" class="btn btn-outline-danger btn-sm zencoupon-delete-coupon-button" data-coupon-id="<?php echo esc_attr( $coupon['coupon_id'] ); ?>"><?php esc_html_e( 'Delete', 'zencoupon-ai-assistance' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="mb-0 text-muted"><?php esc_html_e( 'No generated coupons found yet.', 'zencoupon-ai-assistance' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function ajax_execute_command(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistance' ) ), 403 );
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
            wp_send_json_error( array( 'message' => __( 'Please enter a command.', 'zencoupon-ai-assistance' ) ), 400 );
        }

        $bridge    = new ZenCoupon_AI_Bridge();
        $tool_call = $bridge->call_ai( $command );

        if ( is_wp_error( $tool_call ) ) {
            wp_send_json_error( array( 'message' => $tool_call->get_error_message() ), 500 );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool call received: ' . print_r( $tool_call, true ) );
        }

        if ( isset( $tool_call['name'], $tool_call['arguments'] ) && 'create_coupon' === $tool_call['name'] ) {
            $tool_call['arguments'] = array_merge( (array) $tool_call['arguments'], $restrictions );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool call after restrictions merge: ' . print_r( $tool_call, true ) );
        }

        $mcp    = new ZenCoupon_MCP();
        $result = $mcp->execute_tool_call( $tool_call );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Tool execution result: ' . print_r( $result, true ) );
        }

        wp_send_json_success( array( 'tool_call' => $tool_call, 'result' => $result ) );
    }

    public function ajax_delete_coupon(): void {
        check_ajax_referer( 'zencoupon_admin', 'nonce' );

        if ( ! $this->current_user_can_manage_coupons() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zencoupon-ai-assistance' ) ), 403 );
        }

        $coupon_id = isset( $_POST['coupon_id'] ) ? absint( wp_unslash( $_POST['coupon_id'] ) ) : 0;

        if ( $coupon_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid coupon ID.', 'zencoupon-ai-assistance' ) ), 400 );
        }

        $actions = new ZenCoupon_Actions();
        $result  = $actions->delete_coupon( $coupon_id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ), 500 );
        }

        wp_send_json_success( $result );
    }

    public function sanitize_settings( array $input ): array {
        $output       = array();
        $current      = get_option( ZenCoupon_AI_Assistant::OPTION_KEY, array() );

        // AI Provider
        $output['ai_provider'] = isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], array( 'groq', 'gemini' ), true )
            ? sanitize_text_field( $input['ai_provider'] )
            : 'groq';

        // Groq API Key
        $existing_groq_key = isset( $current['groq_api_key'] ) ? sanitize_text_field( $current['groq_api_key'] ) : '';
        $submitted_groq_key = isset( $input['groq_api_key'] ) ? sanitize_text_field( $input['groq_api_key'] ) : '';

        if ( '********' === $submitted_groq_key || '' === $submitted_groq_key ) {
            $output['groq_api_key'] = $existing_groq_key;
        } else {
            $output['groq_api_key'] = $submitted_groq_key;
        }

        $output['groq_model_name'] = isset( $input['groq_model_name'] ) && '' !== trim( $input['groq_model_name'] )
            ? sanitize_text_field( $input['groq_model_name'] )
            : 'llama3-8b-8192';

        // Gemini API Key
        $existing_gemini_key = isset( $current['gemini_api_key'] ) ? sanitize_text_field( $current['gemini_api_key'] ) : '';
        $submitted_gemini_key = isset( $input['gemini_api_key'] ) ? sanitize_text_field( $input['gemini_api_key'] ) : '';

        if ( '********' === $submitted_gemini_key || '' === $submitted_gemini_key ) {
            $output['gemini_api_key'] = $existing_gemini_key;
        } else {
            $output['gemini_api_key'] = $submitted_gemini_key;
        }

        $output['gemini_model_name'] = isset( $input['gemini_model_name'] ) && '' !== trim( $input['gemini_model_name'] )
            ? sanitize_text_field( $input['gemini_model_name'] )
            : 'gemini-1.5-flash';

        return $output;
    }
}