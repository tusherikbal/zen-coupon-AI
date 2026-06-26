<?php
/**
 * WooCommerce automation framework for ZenCoupon AI Assistant.
 *
 * @package ZenCoupon_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles saved-rule WooCommerce automations.
 */
class ZenCoupon_AI_Assistant_Woo_Automation {
    public const AUTOMATION_FIRST_ORDER     = 'first_order_coupon';
    public const AUTOMATION_ACCOUNT_CREATED = 'account_created_coupon';
    public const AUTOMATION_NEW_ORDER       = 'new_order_coupon';
    public const AUTOMATION_ORDER_STATUS    = 'order_status_coupon';
    public const AUTOMATION_THANK_YOU       = 'thank_you_coupon';

    private const SOURCE       = 'woo_automation';
    private const EVENT_OPTION = 'zencoupon_ai_assistant_automation_events';
    private const CRON_HOOK    = 'zencoupon_ai_assistant_run_delayed_order_automation';

    public function __construct() {
        add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_run_first_order_coupon' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_run_first_order_coupon' ), 20, 1 );
        add_action( 'woocommerce_order_status_pending', array( $this, 'maybe_run_new_order_coupon' ), 20, 1 );
        add_action( 'woocommerce_order_status_on-hold', array( $this, 'maybe_run_new_order_coupon' ), 20, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_run_new_order_coupon' ), 25, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_run_new_order_coupon' ), 25, 1 );
        add_action( 'woocommerce_new_order', array( $this, 'maybe_run_new_order_coupon' ), 20, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_run_order_status_coupon' ), 20, 4 );
        add_action( 'woocommerce_thankyou', array( $this, 'maybe_run_thank_you_coupon' ), 20, 1 );
        add_action( 'user_register', array( $this, 'maybe_run_account_created_coupon' ), 20, 1 );
        add_action( self::CRON_HOOK, array( $this, 'run_delayed_order_automation' ), 20, 4 );
    }

    public static function get_automation_definitions(): array {
        return array(
            self::AUTOMATION_FIRST_ORDER     => array(
                'label'       => __( 'First Order', 'zencoupon-ai-assistant' ),
                'title'       => __( 'First Order Coupon Email', 'zencoupon-ai-assistant' ),
                'description' => __( 'Send a next-order coupon when a customer completes their first paid order.', 'zencoupon-ai-assistant' ),
                'badge'       => __( 'Live', 'zencoupon-ai-assistant' ),
            ),
            self::AUTOMATION_ACCOUNT_CREATED => array(
                'label'       => __( 'Account Created', 'zencoupon-ai-assistant' ),
                'title'       => __( 'Welcome Coupon Email', 'zencoupon-ai-assistant' ),
                'description' => __( 'Send a welcome coupon after a new WordPress or WooCommerce customer account is created.', 'zencoupon-ai-assistant' ),
                'badge'       => __( 'Live', 'zencoupon-ai-assistant' ),
            ),
            self::AUTOMATION_NEW_ORDER       => array(
                'label'       => __( 'New Order', 'zencoupon-ai-assistant' ),
                'title'       => __( 'New Order Coupon Email', 'zencoupon-ai-assistant' ),
                'description' => __( 'Send a coupon when a new WooCommerce order is created.', 'zencoupon-ai-assistant' ),
                'badge'       => __( 'Live', 'zencoupon-ai-assistant' ),
            ),
            self::AUTOMATION_ORDER_STATUS    => array(
                'label'       => __( 'Order Status', 'zencoupon-ai-assistant' ),
                'title'       => __( 'Order Status Coupon Email', 'zencoupon-ai-assistant' ),
                'description' => __( 'Send a coupon when an order changes to a selected status, including cancelled, refunded, failed, or custom WooCommerce statuses.', 'zencoupon-ai-assistant' ),
                'badge'       => __( 'Live', 'zencoupon-ai-assistant' ),
            ),
            self::AUTOMATION_THANK_YOU       => array(
                'label'       => __( 'Thank You', 'zencoupon-ai-assistant' ),
                'title'       => __( 'Thank You Coupon Email', 'zencoupon-ai-assistant' ),
                'description' => __( 'Send a post-purchase coupon from the WooCommerce thank-you flow. Duplicate sends are prevented per order.', 'zencoupon-ai-assistant' ),
                'badge'       => __( 'Live', 'zencoupon-ai-assistant' ),
            ),
        );
    }

    public static function get_default_automations(): array {
        return array(
            self::AUTOMATION_FIRST_ORDER     => self::build_default_settings(
                'ZEN-FIRST',
                __( 'Thanks for your first order! Here is your coupon', 'zencoupon-ai-assistant' ),
                "Hi {customer_name},\n\nThanks for your first order at {store_name}.\n\nUse coupon code {coupon_code} to get {discount} on your next order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
                'completed'
            ),
            self::AUTOMATION_ACCOUNT_CREATED => self::build_default_settings(
                'ZEN-WELCOME',
                __( 'Welcome to {store_name}! Here is your coupon', 'zencoupon-ai-assistant' ),
                "Hi {customer_name},\n\nWelcome to {store_name}.\n\nUse coupon code {coupon_code} to get {discount} on your first order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
                'any'
            ),
            self::AUTOMATION_NEW_ORDER       => self::build_default_settings(
                'ZEN-ORDER',
                __( 'Thanks for your order! Here is a coupon for next time', 'zencoupon-ai-assistant' ),
                "Hi {customer_name},\n\nThanks for your order #{order_id} at {store_name}.\n\nUse coupon code {coupon_code} to get {discount} on your next order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
                'any'
            ),
            self::AUTOMATION_ORDER_STATUS    => self::build_order_status_default_settings(),
            self::AUTOMATION_THANK_YOU       => self::build_default_settings(
                'ZEN-THANKS',
                __( 'Thank you for shopping with us', 'zencoupon-ai-assistant' ),
                "Hi {customer_name},\n\nThank you for shopping with {store_name}.\n\nUse coupon code {coupon_code} to get {discount} on your next order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
                'any'
            ),
        );
    }

    private static function build_default_settings( string $coupon_prefix, string $email_subject, string $email_body, string $trigger_status ): array {
        return array(
            'enabled'              => 'no',
            'trigger_status'       => $trigger_status,
            'discount_type'        => 'percent',
            'discount_amount'      => '10',
            'expiry_days'          => '30',
            'usage_limit'          => '1',
            'usage_limit_per_user' => '1',
            'minimum_amount'       => '',
            'coupon_prefix'        => $coupon_prefix,
            'delay_hours'          => '0',
            'email_subject'        => $email_subject,
            'email_body'           => $email_body,
        );
    }

    private static function build_order_status_default_settings(): array {
        $settings = self::build_default_settings(
            'ZEN-STATUS',
            __( 'A coupon from {store_name}', 'zencoupon-ai-assistant' ),
            "Hi {customer_name},\n\nYour order #{order_id} status changed from {old_status} to {new_status}.\n\nUse coupon code {coupon_code} to get {discount} on your next order.\n\nThis coupon expires on {expiry_date}.\n\nThanks,\n{store_name}",
            'completed'
        );

        $settings['status_rules'] = array(
            'cancelled' => wp_parse_args(
                array(
                    'enabled'        => 'no',
                    'trigger_status' => 'cancelled',
                    'coupon_prefix'  => 'ZEN-CANCEL',
                    'delay_hours'    => '0',
                    'email_subject'  => __( 'A coupon after your cancelled order', 'zencoupon-ai-assistant' ),
                ),
                $settings
            ),
            'refunded'  => wp_parse_args(
                array(
                    'enabled'        => 'no',
                    'trigger_status' => 'refunded',
                    'coupon_prefix'  => 'ZEN-REFUND',
                    'delay_hours'    => '72',
                    'email_subject'  => __( 'A coupon after your refund', 'zencoupon-ai-assistant' ),
                ),
                $settings
            ),
            'failed'    => wp_parse_args(
                array(
                    'enabled'        => 'no',
                    'trigger_status' => 'failed',
                    'coupon_prefix'  => 'ZEN-FAILED',
                    'delay_hours'    => '1',
                    'email_subject'  => __( 'Need help completing your order?', 'zencoupon-ai-assistant' ),
                ),
                $settings
            ),
        );

        return $settings;
    }

    public static function get_default_first_order_email_body(): string {
        $defaults = self::get_default_automations();

        return (string) $defaults[ self::AUTOMATION_FIRST_ORDER ]['email_body'];
    }

    public static function sanitize_automations( array $input, array $current = array() ): array {
        $defaults = self::get_default_automations();
        $output   = array();

        foreach ( $defaults as $automation_key => $automation_defaults ) {
            $has_submission = isset( $input[ $automation_key ] ) && is_array( $input[ $automation_key ] );
            $submitted      = $has_submission ? $input[ $automation_key ] : array();
            $existing       = isset( $current[ $automation_key ] ) && is_array( $current[ $automation_key ] ) ? $current[ $automation_key ] : array();
            $settings       = wp_parse_args( $submitted, wp_parse_args( $existing, $automation_defaults ) );

            $output[ $automation_key ] = array(
                'enabled'              => $has_submission ? ( empty( $submitted['enabled'] ) ? 'no' : 'yes' ) : ( empty( $settings['enabled'] ) ? 'no' : 'yes' ),
                'trigger_status'       => self::sanitize_trigger_status( (string) $settings['trigger_status'], (string) $automation_defaults['trigger_status'] ),
                'discount_type'        => in_array( $settings['discount_type'], array( 'percent', 'fixed_cart' ), true ) ? $settings['discount_type'] : $automation_defaults['discount_type'],
                'discount_amount'      => self::sanitize_positive_decimal( $settings['discount_amount'], $automation_defaults['discount_amount'] ),
                'expiry_days'          => self::sanitize_positive_int( $settings['expiry_days'], $automation_defaults['expiry_days'] ),
                'usage_limit'          => self::sanitize_positive_int( $settings['usage_limit'], $automation_defaults['usage_limit'] ),
                'usage_limit_per_user' => self::sanitize_positive_int( $settings['usage_limit_per_user'], $automation_defaults['usage_limit_per_user'] ),
                'minimum_amount'       => '' === trim( (string) $settings['minimum_amount'] ) ? '' : self::sanitize_positive_decimal( $settings['minimum_amount'], '0' ),
                'coupon_prefix'        => self::sanitize_coupon_prefix( (string) $settings['coupon_prefix'], (string) $automation_defaults['coupon_prefix'] ),
                'delay_hours'          => self::sanitize_non_negative_int( $settings['delay_hours'] ?? '0', $automation_defaults['delay_hours'] ?? '0' ),
                'email_subject'        => '' === trim( (string) $settings['email_subject'] ) ? $automation_defaults['email_subject'] : sanitize_text_field( $settings['email_subject'] ),
                'email_body'           => '' === trim( (string) $settings['email_body'] ) ? $automation_defaults['email_body'] : sanitize_textarea_field( $settings['email_body'] ),
            );

            if ( self::AUTOMATION_ORDER_STATUS === $automation_key ) {
                $output[ $automation_key ]['status_rules'] = self::sanitize_status_rules(
                    isset( $settings['status_rules'] ) && is_array( $settings['status_rules'] ) ? $settings['status_rules'] : array(),
                    isset( $automation_defaults['status_rules'] ) && is_array( $automation_defaults['status_rules'] ) ? $automation_defaults['status_rules'] : array(),
                    $output[ $automation_key ]
                );
            }
        }

        return $output;
    }

    public static function get_automation_settings( string $automation_key ): array {
        $settings    = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );
        $settings    = is_array( $settings ) ? $settings : array();
        $automations = isset( $settings['automations'] ) && is_array( $settings['automations'] ) ? $settings['automations'] : array();
        $defaults    = self::get_default_automations();

        if ( ! isset( $defaults[ $automation_key ] ) ) {
            return array();
        }

        $saved = isset( $automations[ $automation_key ] ) && is_array( $automations[ $automation_key ] )
            ? $automations[ $automation_key ]
            : array();

        return wp_parse_args( $saved, $defaults[ $automation_key ] );
    }

    public static function get_recent_events( int $limit = 8 ): array {
        $events = get_option( self::EVENT_OPTION, array() );
        $events = is_array( $events ) ? $events : array();

        return array_slice( array_reverse( $events ), 0, max( 1, $limit ) );
    }

    public static function get_order_status_options( bool $paid_only = false, bool $include_any = true ): array {
        $options = array();

        if ( $include_any ) {
            $options['any'] = __( 'Any status', 'zencoupon-ai-assistant' );
        }

        if ( $paid_only && function_exists( 'wc_get_is_paid_statuses' ) ) {
            $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
            foreach ( wc_get_is_paid_statuses() as $status ) {
                $status_key             = sanitize_key( $status );
                $label_key              = 'wc-' . $status_key;
                $options[ $status_key ] = isset( $statuses[ $label_key ] ) ? $statuses[ $label_key ] : ucfirst( str_replace( '-', ' ', $status_key ) );
            }

            return $options;
        }

        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( wc_get_order_statuses() as $status_key => $status_label ) {
                $options[ preg_replace( '/^wc-/', '', sanitize_key( $status_key ) ) ] = $status_label;
            }
        }

        if ( count( $options ) <= ( $include_any ? 1 : 0 ) ) {
            $options = array_merge(
                $options,
                array(
                    'pending'    => __( 'Pending payment', 'zencoupon-ai-assistant' ),
                    'processing' => __( 'Processing', 'zencoupon-ai-assistant' ),
                    'on-hold'    => __( 'On hold', 'zencoupon-ai-assistant' ),
                    'completed'  => __( 'Completed', 'zencoupon-ai-assistant' ),
                    'cancelled'  => __( 'Cancelled', 'zencoupon-ai-assistant' ),
                    'refunded'   => __( 'Refunded', 'zencoupon-ai-assistant' ),
                    'failed'     => __( 'Failed', 'zencoupon-ai-assistant' ),
                )
            );
        }

        return $options;
    }

    public function maybe_run_first_order_coupon( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $automation_key = self::AUTOMATION_FIRST_ORDER;
        $settings       = self::get_automation_settings( $automation_key );

        if ( ! $this->can_run_order_automation( $order, $automation_key, $settings, true ) ) {
            return;
        }

        if ( ! $this->is_first_paid_order( $order, sanitize_email( $order->get_billing_email() ) ) ) {
            $this->mark_order_automation_skipped( $order, $automation_key, 'not_first_order' );
            $this->log_event( 'automation_skipped', $automation_key, absint( $order->get_id() ), 0, 'not_first_order' );
            return;
        }

        $this->run_order_coupon_email_automation( $order, $automation_key, $settings );
    }

    public function maybe_run_new_order_coupon( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $automation_key = self::AUTOMATION_NEW_ORDER;
        $settings       = self::get_automation_settings( $automation_key );

        if ( $this->can_run_order_automation( $order, $automation_key, $settings, false ) ) {
            $this->run_order_coupon_email_automation( $order, $automation_key, $settings );
        }
    }

    public function maybe_run_order_status_coupon( int $order_id, string $old_status = '', string $new_status = '', $order = null ): void {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $automation_key = self::AUTOMATION_ORDER_STATUS;
        $settings       = self::get_automation_settings( $automation_key );

        if ( empty( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
            $this->log_event( 'automation_skipped', $automation_key, absint( $order->get_id() ), 0, 'disabled' );
            return;
        }

        $rules = isset( $settings['status_rules'] ) && is_array( $settings['status_rules'] ) ? $settings['status_rules'] : array();
        foreach ( $rules as $rule_key => $rule_settings ) {
            if ( 'yes' !== ( $rule_settings['enabled'] ?? 'no' ) ) {
                continue;
            }

            if ( sanitize_key( $new_status ) !== ( $rule_settings['trigger_status'] ?? '' ) ) {
                continue;
            }

            $rule_automation_key = $automation_key . '_' . sanitize_key( $rule_key );
            if ( ! $this->can_run_order_automation( $order, $rule_automation_key, $rule_settings, false ) ) {
                continue;
            }

            $context = array(
                'automation_key' => $rule_automation_key,
                'old_status'     => sanitize_key( $old_status ),
                'new_status'     => sanitize_key( $new_status ),
                'rule_key'       => sanitize_key( $rule_key ),
            );

            $this->run_order_coupon_email_automation( $order, $rule_automation_key, $rule_settings, $context );
        }
    }

    public function maybe_run_thank_you_coupon( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $automation_key = self::AUTOMATION_THANK_YOU;
        $settings       = self::get_automation_settings( $automation_key );

        if ( $this->can_run_order_automation( $order, $automation_key, $settings, false ) ) {
            $this->run_order_coupon_email_automation( $order, $automation_key, $settings );
        }
    }

    public function maybe_run_account_created_coupon( int $user_id ): void {
        $automation_key = self::AUTOMATION_ACCOUNT_CREATED;
        $settings       = self::get_automation_settings( $automation_key );

        if ( empty( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
            $this->log_event( 'automation_skipped', $automation_key, 0, 0, 'disabled' );
            return;
        }

        if ( $this->has_user_automation_run( $user_id, $automation_key ) ) {
            $this->log_event( 'automation_skipped', $automation_key, 0, 0, 'already_processed' );
            return;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof WP_User ) {
            $this->log_event( 'automation_skipped', $automation_key, 0, 0, 'invalid_user' );
            return;
        }

        if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
            $this->log_event( 'automation_skipped', $automation_key, 0, 0, 'privileged_user' );
            return;
        }

        $email = sanitize_email( $user->user_email );
        if ( empty( $email ) || ! is_email( $email ) ) {
            $this->log_event( 'automation_skipped', $automation_key, 0, 0, 'invalid_email' );
            return;
        }

        $context = array(
            'automation_key' => $automation_key,
            'customer_email' => $email,
            'customer_name'  => $this->get_user_display_name( $user ),
            'order_id'       => '',
            'source_user_id' => absint( $user_id ),
        );

        $this->run_coupon_email_automation( $automation_key, $settings, $context );
    }

    public function run_delayed_order_automation( int $order_id, string $automation_key, array $settings, array $context ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            $this->log_event( 'automation_skipped', sanitize_key( $automation_key ), absint( $order_id ), 0, 'invalid_order' );
            return;
        }

        if ( 'yes' === (string) $order->get_meta( $this->get_order_meta_key( $automation_key, 'sent' ), true ) ) {
            $this->log_event( 'automation_skipped', sanitize_key( $automation_key ), absint( $order_id ), 0, 'already_sent' );
            return;
        }

        $context['is_delayed_run'] = true;
        $this->run_order_coupon_email_automation( $order, sanitize_key( $automation_key ), $settings, $context );
    }

    private function can_run_order_automation( WC_Order $order, string $automation_key, array $settings, bool $require_paid_status ): bool {
        $order_id = absint( $order->get_id() );

        if ( empty( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
            $this->log_event( 'automation_skipped', $automation_key, $order_id, 0, 'disabled' );
            return false;
        }

        $trigger_status = (string) ( $settings['trigger_status'] ?? 'any' );
        if ( 'any' !== $trigger_status && $order->get_status() !== $trigger_status ) {
            $this->log_event( 'automation_skipped', $automation_key, $order_id, 0, 'status_mismatch' );
            return false;
        }

        if ( $require_paid_status && ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ) {
            $this->log_event( 'automation_skipped', $automation_key, $order_id, 0, 'not_paid_status' );
            return false;
        }

        if ( $this->has_order_automation_run( $order, $automation_key ) ) {
            $this->log_event( 'automation_skipped', $automation_key, $order_id, 0, 'already_processed' );
            return false;
        }

        $billing_email = sanitize_email( $order->get_billing_email() );
        if ( empty( $billing_email ) || ! is_email( $billing_email ) ) {
            $order->add_order_note( __( 'ZenCoupon automation skipped: order billing email is missing or invalid.', 'zencoupon-ai-assistant' ) );
            $this->log_event( 'automation_skipped', $automation_key, $order_id, 0, 'invalid_email' );
            return false;
        }

        return true;
    }

    private function run_order_coupon_email_automation( WC_Order $order, string $automation_key, array $settings, array $extra_context = array() ): void {
        $context = array_merge(
            $extra_context,
            array(
            'automation_key'  => $automation_key,
            'customer_email'  => sanitize_email( $order->get_billing_email() ),
            'customer_name'   => $this->get_order_customer_name( $order ),
            'order_id'        => (string) $order->get_id(),
            'source_order_id' => absint( $order->get_id() ),
            )
        );

        $delay_hours = absint( $settings['delay_hours'] ?? 0 );
        if ( $delay_hours > 0 && empty( $context['is_delayed_run'] ) ) {
            $this->schedule_delayed_order_automation( $order, $automation_key, $settings, $context, $delay_hours );
            return;
        }

        $result = $this->run_coupon_email_automation( $automation_key, $settings, $context );

        if ( empty( $result['coupon_id'] ) ) {
            $order->add_order_note( __( 'ZenCoupon automation failed: coupon could not be created.', 'zencoupon-ai-assistant' ) );
            return;
        }

        if ( ! empty( $result['sent'] ) ) {
            $this->mark_order_automation_sent( $order, $automation_key, absint( $result['coupon_id'] ), (string) $result['coupon_code'] );
            $order->add_order_note(
                sprintf(
                    /* translators: %s is the generated coupon code. */
                    __( 'ZenCoupon automation email sent. Coupon code: %s', 'zencoupon-ai-assistant' ),
                    (string) $result['coupon_code']
                )
            );
            return;
        }

        $this->mark_order_automation_coupon_created( $order, $automation_key, absint( $result['coupon_id'] ), (string) $result['coupon_code'] );
        $order->add_order_note(
            sprintf(
                /* translators: %s is the generated coupon code. */
                __( 'ZenCoupon automation created coupon %s, but the email could not be sent.', 'zencoupon-ai-assistant' ),
                (string) $result['coupon_code']
            )
        );
    }

    private function schedule_delayed_order_automation( WC_Order $order, string $automation_key, array $settings, array $context, int $delay_hours ): void {
        $order_id   = absint( $order->get_id() );
        $hook_args  = array( $order_id, sanitize_key( $automation_key ), $settings, $context );
        $timestamp  = time() + ( HOUR_IN_SECONDS * $delay_hours );
        $next_event = wp_next_scheduled( self::CRON_HOOK, $hook_args );

        if ( false === $next_event ) {
            wp_schedule_single_event( $timestamp, self::CRON_HOOK, $hook_args );
        }

        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'scheduled' ), 'yes' );
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'scheduled_for' ), gmdate( 'Y-m-d H:i:s', $timestamp ) );
        $order->save();

        $this->log_event( 'email_queued', $automation_key, $order_id, 0, 'delayed' );
    }

    private function run_coupon_email_automation( string $automation_key, array $settings, array $context ): array {
        $source_order_id = absint( $context['source_order_id'] ?? 0 );
        $this->log_event( 'automation_triggered', $automation_key, $source_order_id );

        $coupon_id = $this->create_coupon_for_context( $settings, $context );
        if ( $coupon_id <= 0 ) {
            $this->log_event( 'automation_skipped', $automation_key, $source_order_id, 0, 'coupon_create_failed' );
            return array();
        }

        $coupon = new WC_Coupon( $coupon_id );
        $this->log_event( 'coupon_created', $automation_key, $source_order_id, $coupon_id );

        $sent = $this->send_coupon_email( $coupon, $settings, $context );
        if ( $sent ) {
            $this->mark_context_sent( $automation_key, $coupon_id, $coupon->get_code(), $context );
            $this->log_event( 'email_sent', $automation_key, $source_order_id, $coupon_id );
        } else {
            $this->log_event( 'email_failed', $automation_key, $source_order_id, $coupon_id );
        }

        return array(
            'coupon_id'   => $coupon_id,
            'coupon_code' => $coupon->get_code(),
            'sent'        => $sent,
        );
    }

    private static function sanitize_positive_decimal( $value, string $fallback ): string {
        $number = wc_format_decimal( $value );

        return '' === $number || floatval( $number ) <= 0 ? $fallback : $number;
    }

    private static function sanitize_positive_int( $value, string $fallback ): string {
        $number = absint( $value );

        return $number <= 0 ? $fallback : (string) $number;
    }

    private static function sanitize_non_negative_int( $value, string $fallback ): string {
        $number = absint( $value );

        return (string) min( $number, 720 );
    }

    private static function sanitize_status_rules( array $input, array $defaults, array $base_settings ): array {
        $output = array();

        foreach ( $defaults as $rule_key => $rule_defaults ) {
            $submitted = isset( $input[ $rule_key ] ) && is_array( $input[ $rule_key ] ) ? $input[ $rule_key ] : array();
            $settings  = wp_parse_args( $submitted, wp_parse_args( $rule_defaults, $base_settings ) );

            $output[ sanitize_key( $rule_key ) ] = array(
                'enabled'              => empty( $submitted['enabled'] ) ? 'no' : 'yes',
                'trigger_status'       => self::sanitize_trigger_status( (string) $settings['trigger_status'], (string) $rule_defaults['trigger_status'] ),
                'discount_type'        => in_array( $settings['discount_type'], array( 'percent', 'fixed_cart' ), true ) ? $settings['discount_type'] : $base_settings['discount_type'],
                'discount_amount'      => self::sanitize_positive_decimal( $settings['discount_amount'], $base_settings['discount_amount'] ),
                'expiry_days'          => self::sanitize_positive_int( $settings['expiry_days'], $base_settings['expiry_days'] ),
                'usage_limit'          => self::sanitize_positive_int( $settings['usage_limit'], $base_settings['usage_limit'] ),
                'usage_limit_per_user' => self::sanitize_positive_int( $settings['usage_limit_per_user'], $base_settings['usage_limit_per_user'] ),
                'minimum_amount'       => '' === trim( (string) $settings['minimum_amount'] ) ? '' : self::sanitize_positive_decimal( $settings['minimum_amount'], '0' ),
                'coupon_prefix'        => self::sanitize_coupon_prefix( (string) $settings['coupon_prefix'], (string) $base_settings['coupon_prefix'] ),
                'delay_hours'          => self::sanitize_non_negative_int( $settings['delay_hours'] ?? '0', $base_settings['delay_hours'] ?? '0' ),
                'email_subject'        => '' === trim( (string) $settings['email_subject'] ) ? $base_settings['email_subject'] : sanitize_text_field( $settings['email_subject'] ),
                'email_body'           => '' === trim( (string) $settings['email_body'] ) ? $base_settings['email_body'] : sanitize_textarea_field( $settings['email_body'] ),
            );
        }

        return $output;
    }

    private static function sanitize_trigger_status( string $value, string $fallback ): string {
        $allowed = array_keys( self::get_order_status_options( false, true ) );

        return in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    private static function sanitize_coupon_prefix( string $value, string $fallback ): string {
        $prefix = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', $value ) );
        $prefix = substr( $prefix, 0, 24 );

        return '' === $prefix ? $fallback : $prefix;
    }

    private function has_order_automation_run( WC_Order $order, string $automation_key ): bool {
        return '' !== (string) $order->get_meta( $this->get_order_meta_key( $automation_key, 'coupon_id' ), true )
            || 'yes' === (string) $order->get_meta( $this->get_order_meta_key( $automation_key, 'sent' ), true )
            || 'yes' === (string) $order->get_meta( $this->get_order_meta_key( $automation_key, 'scheduled' ), true );
    }

    private function has_user_automation_run( int $user_id, string $automation_key ): bool {
        return 'yes' === (string) get_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'sent' ), true )
            || '' !== (string) get_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'coupon_id' ), true );
    }

    private function is_first_paid_order( WC_Order $order, string $billing_email ): bool {
        $paid_statuses = array();

        foreach ( wc_get_is_paid_statuses() as $status ) {
            $paid_statuses[] = 'wc-' . $status;
        }

        $query_args = array(
            'limit'   => 3,
            'status'  => $paid_statuses,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'ASC',
        );

        $customer_id = absint( $order->get_customer_id() );
        if ( $customer_id > 0 ) {
            $query_args['customer_id'] = $customer_id;
        } else {
            $query_args['billing_email'] = $billing_email;
        }

        $orders = wc_get_orders( $query_args );
        $orders = array_map( 'absint', is_array( $orders ) ? $orders : array() );
        $orders = array_values( array_diff( $orders, array( absint( $order->get_id() ) ) ) );

        if ( ! empty( $orders ) ) {
            return false;
        }

        if ( $customer_id <= 0 ) {
            return true;
        }

        $email_query_args                  = $query_args;
        $email_query_args['billing_email'] = $billing_email;
        unset( $email_query_args['customer_id'] );

        $email_orders = wc_get_orders( $email_query_args );
        $email_orders = array_map( 'absint', is_array( $email_orders ) ? $email_orders : array() );
        $email_orders = array_values( array_diff( $email_orders, array( absint( $order->get_id() ) ) ) );

        return empty( $email_orders );
    }

    private function create_coupon_for_context( array $settings, array $context ): int {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return 0;
        }

        $email       = sanitize_email( (string) ( $context['customer_email'] ?? '' ) );
        $prefix      = self::sanitize_coupon_prefix( (string) ( $settings['coupon_prefix'] ?? 'ZEN-AUTO' ), 'ZEN-AUTO' );
        $coupon_code = $this->generate_unique_coupon_code( $prefix );
        $coupon      = new WC_Coupon();

        $coupon->set_code( $coupon_code );
        $coupon->set_discount_type( $settings['discount_type'] );
        $coupon->set_amount( wc_format_decimal( $settings['discount_amount'] ) );
        $coupon->set_individual_use( true );
        $coupon->set_usage_limit( absint( $settings['usage_limit'] ) );
        $coupon->set_usage_limit_per_user( absint( $settings['usage_limit_per_user'] ) );
        $coupon->set_description( sprintf( 'Generated by ZenCoupon Woo Automation: %s', sanitize_key( (string) ( $context['automation_key'] ?? '' ) ) ) );

        if ( ! empty( $email ) && is_email( $email ) ) {
            $coupon->set_email_restrictions( array( $email ) );
        }

        if ( '' !== trim( (string) $settings['minimum_amount'] ) && floatval( $settings['minimum_amount'] ) > 0 ) {
            $coupon->set_minimum_amount( wc_format_decimal( $settings['minimum_amount'] ) );
        }

        $expiry_days = absint( $settings['expiry_days'] );
        if ( $expiry_days > 0 ) {
            $coupon->set_date_expires( strtotime( '+' . $expiry_days . ' days', current_time( 'timestamp' ) ) );
        }

        $coupon_id = absint( $coupon->save() );
        if ( $coupon_id <= 0 ) {
            return 0;
        }

        $automation_key = sanitize_key( (string) ( $context['automation_key'] ?? '' ) );
        update_post_meta( $coupon_id, 'zencoupon_generated', 'yes' );
        update_post_meta( $coupon_id, 'zencoupon_source', self::SOURCE );
        update_post_meta( $coupon_id, 'zencoupon_automation_key', $automation_key );
        update_post_meta( $coupon_id, 'zencoupon_campaign_key', $automation_key . '_default' );

        if ( ! empty( $context['source_order_id'] ) ) {
            update_post_meta( $coupon_id, 'zencoupon_source_order_id', absint( $context['source_order_id'] ) );
        }

        if ( ! empty( $context['source_user_id'] ) ) {
            update_post_meta( $coupon_id, 'zencoupon_source_user_id', absint( $context['source_user_id'] ) );
        }

        if ( ! empty( $email ) ) {
            update_post_meta( $coupon_id, 'zencoupon_customer_email_hash', wp_hash( strtolower( $email ) ) );
        }

        return $coupon_id;
    }

    private function generate_unique_coupon_code( string $prefix ): string {
        $prefix = self::sanitize_coupon_prefix( $prefix, 'ZEN-AUTO' );

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $suffix = strtoupper( wp_generate_password( 6, false, false ) );
            $code   = $prefix . '-' . $suffix;

            if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) <= 0 ) {
                return $code;
            }
        }

        return $prefix . '-' . strtoupper( wp_generate_uuid4() );
    }

    private function send_coupon_email( WC_Coupon $coupon, array $settings, array $context ): bool {
        $to = sanitize_email( (string) ( $context['customer_email'] ?? '' ) );

        if ( empty( $to ) || ! is_email( $to ) ) {
            return false;
        }

        $subject    = $this->render_placeholders( $settings['email_subject'], $coupon, $settings, $context );
        $plain_body = $this->render_placeholders( $settings['email_body'], $coupon, $settings, $context );
        $body       = nl2br( esc_html( $plain_body ) );
        $headers    = array( 'Content-Type: text/html; charset=UTF-8' );

        return (bool) wp_mail( $to, $subject, $body, $headers );
    }

    private function render_placeholders( string $content, WC_Coupon $coupon, array $settings, array $context ): string {
        $expiry_date = $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( wc_date_format() ) : __( 'No expiry', 'zencoupon-ai-assistant' );
        $discount    = 'percent' === $settings['discount_type']
            ? sprintf( '%s%%', wc_format_decimal( $settings['discount_amount'] ) )
            : wp_strip_all_tags( wc_price( $settings['discount_amount'] ) );

        return strtr(
            $content,
            array(
                '{customer_name}' => (string) ( $context['customer_name'] ?? __( 'Customer', 'zencoupon-ai-assistant' ) ),
                '{coupon_code}'   => $coupon->get_code(),
                '{discount}'      => $discount,
                '{expiry_date}'   => $expiry_date,
                '{store_name}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
                '{order_id}'      => (string) ( $context['order_id'] ?? '' ),
                '{old_status}'    => $this->get_order_status_label( (string) ( $context['old_status'] ?? '' ) ),
                '{new_status}'    => $this->get_order_status_label( (string) ( $context['new_status'] ?? '' ) ),
            )
        );
    }

    private function get_order_status_label( string $status ): string {
        $status = sanitize_key( $status );

        if ( '' === $status ) {
            return '';
        }

        $statuses  = self::get_order_status_options( false, false );
        $fallback  = ucwords( str_replace( '-', ' ', $status ) );

        return isset( $statuses[ $status ] ) ? (string) $statuses[ $status ] : $fallback;
    }

    private function get_order_customer_name( WC_Order $order ): string {
        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        return '' === $customer_name ? __( 'Customer', 'zencoupon-ai-assistant' ) : $customer_name;
    }

    private function get_user_display_name( WP_User $user ): string {
        $name = trim( $user->display_name );

        return '' === $name ? __( 'Customer', 'zencoupon-ai-assistant' ) : $name;
    }

    private function mark_order_automation_coupon_created( WC_Order $order, string $automation_key, int $coupon_id, string $coupon_code ): void {
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'coupon_id' ), $coupon_id );
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'coupon_code' ), $coupon_code );
        $order->save();
    }

    private function mark_order_automation_sent( WC_Order $order, string $automation_key, int $coupon_id, string $coupon_code ): void {
        $this->mark_order_automation_coupon_created( $order, $automation_key, $coupon_id, $coupon_code );
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'sent' ), 'yes' );
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'sent_at' ), current_time( 'mysql' ) );
        $order->save();
        update_post_meta( $coupon_id, 'zencoupon_sent_at', current_time( 'mysql' ) );
    }

    private function mark_order_automation_skipped( WC_Order $order, string $automation_key, string $reason ): void {
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'skipped_reason' ), sanitize_key( $reason ) );
        $order->update_meta_data( $this->get_order_meta_key( $automation_key, 'skipped_at' ), current_time( 'mysql' ) );
        $order->save();
    }

    private function mark_context_sent( string $automation_key, int $coupon_id, string $coupon_code, array $context ): void {
        update_post_meta( $coupon_id, 'zencoupon_sent_at', current_time( 'mysql' ) );

        if ( ! empty( $context['source_user_id'] ) ) {
            $user_id = absint( $context['source_user_id'] );
            update_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'sent' ), 'yes' );
            update_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'sent_at' ), current_time( 'mysql' ) );
            update_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'coupon_id' ), $coupon_id );
            update_user_meta( $user_id, $this->get_user_meta_key( $automation_key, 'coupon_code' ), $coupon_code );
        }
    }

    private function get_order_meta_key( string $automation_key, string $suffix ): string {
        return '_zencoupon_automation_' . sanitize_key( $automation_key ) . '_' . sanitize_key( $suffix );
    }

    private function get_user_meta_key( string $automation_key, string $suffix ): string {
        return '_zencoupon_automation_' . sanitize_key( $automation_key ) . '_' . sanitize_key( $suffix );
    }

    private function log_event( string $event_type, string $automation_key, int $order_id = 0, int $coupon_id = 0, string $reason = '' ): void {
        $events = get_option( self::EVENT_OPTION, array() );
        $events = is_array( $events ) ? $events : array();

        $events[] = array(
            'event_type'     => sanitize_key( $event_type ),
            'automation_key' => sanitize_key( $automation_key ),
            'order_id'       => absint( $order_id ),
            'coupon_id'      => absint( $coupon_id ),
            'reason'         => sanitize_key( $reason ),
            'created_at'     => current_time( 'mysql' ),
        );

        if ( count( $events ) > 300 ) {
            $events = array_slice( $events, -300 );
        }

        update_option( self::EVENT_OPTION, $events, 'no' );
    }
}
