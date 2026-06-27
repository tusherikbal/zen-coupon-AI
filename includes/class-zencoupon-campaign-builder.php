<?php
/**
 * AI Campaign Builder for ZenCoupon AI Assistant.
 *
 * The AI is used ONCE, on admin request, to draft campaign copy and a coupon
 * rule. Customer segmentation, coupon creation, and email delivery are handled
 * entirely by WooCommerce/PHP. No AI call ever runs inside checkout, cart, or
 * order hooks.
 *
 * @package ZenCoupon_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Assistant_Campaign_Builder {
    private const OPTION_KEY    = 'zencoupon_ai_assistant_campaigns';
    private const CRON_HOOK     = 'zencoupon_ai_assistant_run_campaign_batch';
    private const SOURCE        = 'campaign_builder';
    private const BATCH_SIZE    = 25;
    private const BATCH_GAP     = 60; // Seconds between batches.
    private const MAX_RECIPIENTS = 2000;

    public function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'run_campaign_batch' ), 10, 1 );
    }

    /**
     * Default win-back lookback in days.
     */
    public static function default_segment_days(): int {
        return 60;
    }

    /* ---------------------------------------------------------------------
     * Segmentation (WooCommerce queries — never sent to the AI).
     * ------------------------------------------------------------------- */

    /**
     * Resolves a segment_type + params into an actual customer list.
     * Dispatches to the appropriate query method.
     *
     * @param string $segment_type One of: winback, category, product, tag, never_ordered, all_customers
     * @param array<string, mixed> $segment_params Type-specific params (e.g., category_id, product_id, days)
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function resolve_segment( string $segment_type, array $segment_params = array() ) {
        $segment_type = sanitize_key( $segment_type );

        switch ( $segment_type ) {
            case 'category':
                $category_id = absint( $segment_params['category_id'] ?? 0 );
                if ( $category_id <= 0 ) {
                    return new WP_Error( 'invalid_category', __( 'Category ID is required for category segment.', 'zencoupon-ai-assistant' ) );
                }
                return $this->get_category_buyers( $category_id );

            case 'product':
                $product_id = absint( $segment_params['product_id'] ?? 0 );
                if ( $product_id <= 0 ) {
                    return new WP_Error( 'invalid_product', __( 'Product ID is required for product segment.', 'zencoupon-ai-assistant' ) );
                }
                return $this->get_product_buyers( $product_id );

            case 'tag':
                $tag_id = absint( $segment_params['tag_id'] ?? 0 );
                if ( $tag_id <= 0 ) {
                    return new WP_Error( 'invalid_tag', __( 'Tag ID is required for tag segment.', 'zencoupon-ai-assistant' ) );
                }
                return $this->get_tag_buyers( $tag_id );

            case 'never_ordered':
                return $this->get_never_ordered_customers();

            case 'all_customers':
                return $this->get_all_customers();

            case 'winback':
            default:
                $days = absint( $segment_params['days'] ?? self::default_segment_days() );
                return $this->get_winback_recipients( max( 1, $days ) );
        }
    }

    /**
     * Returns win-back recipients: customers with at least one past paid order
     * who have NOT placed a paid order within the last $days days.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_winback_recipients( int $days = 60, int $limit = self::MAX_RECIPIENTS ): array {
        if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_is_paid_statuses' ) ) {
            return array();
        }

        $days   = max( 1, $days );
        $limit  = max( 1, min( $limit, self::MAX_RECIPIENTS ) );
        $paid   = wc_get_is_paid_statuses();
        $cutoff = time() - ( $days * DAY_IN_SECONDS );

        // Emails that are still active (paid order within the window) are excluded.
        $active_orders = wc_get_orders(
            array(
                'status'       => $paid,
                'date_created' => '>' . $cutoff,
                'limit'        => -1,
                'return'       => 'objects',
            )
        );

        $active_emails = array();
        foreach ( $active_orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }
            $email = strtolower( sanitize_email( $order->get_billing_email() ) );
            if ( '' !== $email ) {
                $active_emails[ $email ] = true;
            }
        }

        // Candidates: customers with a paid order on/before the cutoff date.
        $old_orders = wc_get_orders(
            array(
                'status'       => $paid,
                'date_created' => '<=' . $cutoff,
                'limit'        => -1,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'return'       => 'objects',
            )
        );

        $recipients = array();
        foreach ( $old_orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $email = strtolower( sanitize_email( $order->get_billing_email() ) );
            if ( '' === $email || ! is_email( $email ) ) {
                continue;
            }

            if ( isset( $active_emails[ $email ] ) || isset( $recipients[ $email ] ) ) {
                continue;
            }

            $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

            $recipients[ $email ] = array(
                'email'       => $email,
                'name'        => '' === $name ? __( 'Customer', 'zencoupon-ai-assistant' ) : $name,
                'customer_id' => absint( $order->get_customer_id() ),
                'last_order'  => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
            );

            if ( count( $recipients ) >= $limit ) {
                break;
            }
        }

        return array_values( $recipients );
    }

    /**
     * Returns customers who have purchased from a specific category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_category_buyers( int $category_id, int $limit = self::MAX_RECIPIENTS ): array {
        global $wpdb;

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        // Get all order IDs with items from this category.
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT oi.order_id
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id
                 WHERE oi.order_item_type = 'line_item'
                   AND oim.meta_key = 'product_id'
                   AND oim2.meta_key = '_product_id'
                   AND oim.meta_value IN (
                     SELECT ID FROM {$wpdb->prefix}posts p
                     INNER JOIN {$wpdb->prefix}term_relationships tr ON p.ID = tr.object_id
                     WHERE p.post_type = 'product' AND tr.term_taxonomy_id IN (
                       SELECT term_taxonomy_id FROM {$wpdb->prefix}term_taxonomy WHERE term_id = %d
                     )
                   )
                 LIMIT %d",
                $category_id,
                $limit
            )
        );

        return $this->extract_customer_data_from_orders( array_map( 'absint', $order_ids ?: array() ) );
    }

    /**
     * Returns customers who have purchased a specific product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_product_buyers( int $product_id, int $limit = self::MAX_RECIPIENTS ): array {
        global $wpdb;

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT oi.order_id
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 WHERE oi.order_item_type = 'line_item'
                   AND oim.meta_key = 'product_id'
                   AND oim.meta_value = %d
                 LIMIT %d",
                $product_id,
                $limit
            )
        );

        return $this->extract_customer_data_from_orders( array_map( 'absint', $order_ids ?: array() ) );
    }

    /**
     * Returns customers who have purchased products with a specific tag.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_tag_buyers( int $tag_id, int $limit = self::MAX_RECIPIENTS ): array {
        global $wpdb;

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT oi.order_id
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 WHERE oi.order_item_type = 'line_item'
                   AND oim.meta_key = 'product_id'
                   AND oim.meta_value IN (
                     SELECT ID FROM {$wpdb->prefix}posts p
                     INNER JOIN {$wpdb->prefix}term_relationships tr ON p.ID = tr.object_id
                     WHERE p.post_type = 'product' AND tr.term_taxonomy_id IN (
                       SELECT term_taxonomy_id FROM {$wpdb->prefix}term_taxonomy WHERE term_id = %d
                     )
                   )
                 LIMIT %d",
                $tag_id,
                $limit
            )
        );

        return $this->extract_customer_data_from_orders( array_map( 'absint', $order_ids ?: array() ) );
    }

    /**
     * Returns customers who have registered but never ordered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_never_ordered_customers( int $limit = self::MAX_RECIPIENTS ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        // Get all users with orders.
        $users_with_orders = wc_get_orders(
            array(
                'limit'  => -1,
                'return' => 'ids',
            )
        );

        $ordered_customer_ids = array();
        foreach ( $users_with_orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order ) {
                $customer_id = absint( $order->get_customer_id() );
                if ( $customer_id > 0 ) {
                    $ordered_customer_ids[ $customer_id ] = true;
                }
            }
        }

        // Get registered users excluding those who ordered.
        $users = get_users(
            array(
                'number' => $limit,
                'order'  => 'DESC',
                'orderby' => 'user_registered',
            )
        );

        $recipients = array();
        foreach ( $users as $user ) {
            if ( isset( $ordered_customer_ids[ $user->ID ] ) ) {
                continue;
            }

            $email = strtolower( sanitize_email( $user->user_email ) );
            if ( '' === $email || ! is_email( $email ) ) {
                continue;
            }

            $recipients[] = array(
                'email'       => $email,
                'name'        => '' === trim( $user->display_name ) ? __( 'Customer', 'zencoupon-ai-assistant' ) : $user->display_name,
                'customer_id' => $user->ID,
                'last_order'  => __( 'Never', 'zencoupon-ai-assistant' ),
            );
        }

        return $recipients;
    }

    /**
     * Returns all customers (everyone who has ordered).
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_all_customers( int $limit = self::MAX_RECIPIENTS ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $orders = wc_get_orders(
            array(
                'status'       => 'any',
                'limit'        => -1,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'return'       => 'objects',
            )
        );

        $recipients = array();
        $seen       = array();

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $email = strtolower( sanitize_email( $order->get_billing_email() ) );
            if ( '' === $email || ! is_email( $email ) || isset( $seen[ $email ] ) ) {
                continue;
            }

            $seen[ $email ] = true;
            $recipients[]   = array(
                'email'       => $email,
                'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: __( 'Customer', 'zencoupon-ai-assistant' ),
                'customer_id' => absint( $order->get_customer_id() ),
                'last_order'  => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
            );

            if ( count( $recipients ) >= $limit ) {
                break;
            }
        }

        return $recipients;
    }

    /**
     * Helper: extract customer data from a list of order IDs.
     *
     * @param array<int> $order_ids
     * @return array<int, array<string, mixed>>
     */
    private function extract_customer_data_from_orders( array $order_ids ): array {
        $recipients = array();
        $seen       = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $email = strtolower( sanitize_email( $order->get_billing_email() ) );
            if ( '' === $email || ! is_email( $email ) || isset( $seen[ $email ] ) ) {
                continue;
            }

            $seen[ $email ]  = true;
            $recipients[]    = array(
                'email'       => $email,
                'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: __( 'Customer', 'zencoupon-ai-assistant' ),
                'customer_id' => absint( $order->get_customer_id() ),
                'last_order'  => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
            );

            if ( count( $recipients ) >= self::MAX_RECIPIENTS ) {
                break;
            }
        }

        return $recipients;
    }

    /* ---------------------------------------------------------------------
     * AI draft generation.
     * ------------------------------------------------------------------- */

    /**
     * Generates a normalized campaign draft from a natural-language idea.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function generate_draft( string $idea ) {
        $idea = trim( $idea );
        if ( '' === $idea ) {
            return new WP_Error( 'empty_idea', __( 'Please describe the campaign idea.', 'zencoupon-ai-assistant' ) );
        }

        $bridge = new ZenCoupon_AI_Assistant_Bridge();
        $raw    = $bridge->call_campaign_builder( $idea );

        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        return $this->normalize_draft( is_array( $raw ) ? $raw : array() );
    }

    /**
     * Maps the loose AI JSON into the fixed fields this plugin uses, with safe
     * defaults so a partial AI response never produces an invalid campaign.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize_draft( array $raw ): array {
        $coupon_rule = isset( $raw['coupon_rule'] ) && is_array( $raw['coupon_rule'] ) ? $raw['coupon_rule'] : array();

        $discount_type = $this->first_value( array( $coupon_rule['discount_type'] ?? '', $raw['discount_type'] ?? '' ) );
        $discount_type = in_array( $discount_type, array( 'percent', 'fixed_cart' ), true ) ? $discount_type : 'percent';

        $amount = $this->first_value(
            array(
                $coupon_rule['amount'] ?? '',
                $coupon_rule['discount_amount'] ?? '',
                $coupon_rule['value'] ?? '',
                $raw['discount_amount'] ?? '',
            )
        );
        $amount = is_numeric( $amount ) && floatval( $amount ) > 0 ? (string) floatval( $amount ) : '10';

        $usage_limits         = isset( $raw['usage_limits'] ) && is_array( $raw['usage_limits'] ) ? $raw['usage_limits'] : array();
        $usage_limit          = absint( $this->first_value( array( $usage_limits['total'] ?? '', $coupon_rule['usage_limit'] ?? '', '1' ) ) );
        $usage_limit_per_user = absint( $this->first_value( array( $usage_limits['per_user'] ?? '', $coupon_rule['usage_limit_per_user'] ?? '', '1' ) ) );

        $expiry_days = absint( $this->first_value( array( $coupon_rule['expiry_days'] ?? '', $raw['expiry_days'] ?? '' ) ) );
        if ( $expiry_days <= 0 ) {
            $expiry_days = $this->expiry_days_from_date( (string) ( $raw['expiry_date'] ?? '' ) );
        }
        if ( $expiry_days <= 0 ) {
            $expiry_days = 30;
        }

        $segment_type = sanitize_key( (string) ( $raw['segment_type'] ?? 'winback' ) );
        $segment_type = in_array( $segment_type, array( 'winback', 'category', 'product', 'tag', 'never_ordered', 'all_customers' ), true )
            ? $segment_type
            : 'winback';

        $segment_params = is_array( $raw['segment_params'] ?? null ) ? $raw['segment_params'] : array();

        return array(
            'name'                 => sanitize_text_field( (string) $this->first_value( array( $raw['campaign_name'] ?? '', __( 'Campaign', 'zencoupon-ai-assistant' ) ) ) ),
            'segment_type'         => $segment_type,
            'segment_params'       => $segment_params,
            'segment_label'        => sanitize_text_field( (string) ( $raw['target_customer_segment'] ?? __( 'Customers', 'zencoupon-ai-assistant' ) ) ),
            'discount_type'        => $discount_type,
            'discount_amount'      => $amount,
            'expiry_days'          => (string) $expiry_days,
            'usage_limit'          => (string) max( 1, $usage_limit ),
            'usage_limit_per_user' => (string) max( 1, $usage_limit_per_user ),
            'coupon_prefix'        => 'ZEN-CAMP',
            'email_subject'        => sanitize_text_field( (string) $this->first_value( array( $raw['email_subject'] ?? '', __( 'We have a special offer for you', 'zencoupon-ai-assistant' ) ) ) ),
            'email_body'           => sanitize_textarea_field( (string) $this->first_value( array( $raw['email_body'] ?? '', $this->default_email_body() ) ) ),
            'social_copy'          => sanitize_textarea_field( (string) ( $raw['social_post_copy'] ?? '' ) ),
        );
    }

    private function first_value( array $candidates ): string {
        foreach ( $candidates as $candidate ) {
            if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
                return (string) $candidate;
            }
        }

        return '';
    }

    private function expiry_days_from_date( string $date ): int {
        $date = trim( $date );
        if ( '' === $date ) {
            return 0;
        }

        $timestamp = strtotime( $date );
        if ( false === $timestamp ) {
            return 0;
        }

        $diff = (int) ceil( ( $timestamp - time() ) / DAY_IN_SECONDS );

        return $diff > 0 ? $diff : 0;
    }

    private function default_email_body(): string {
        return "Hi {customer_name},\n\nWe miss you at {store_name}!\n\nHere is a special coupon just for you: {coupon_code} for {discount} off your next order.\n\nHurry, it expires on {expiry_date}.\n\nSee you soon,\n{store_name}";
    }

    /**
     * Sends a test email to the admin to preview the campaign.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function send_test_email( array $campaign_data, string $test_email ) {
        $test_email = sanitize_email( $test_email );
        if ( ! is_email( $test_email ) ) {
            return new WP_Error( 'invalid_email', __( 'Invalid email address for test.', 'zencoupon-ai-assistant' ) );
        }

        // Create a temporary coupon for preview.
        $coupon_id = $this->create_campaign_coupon(
            $campaign_data,
            array(
                'name'        => __( 'Admin', 'zencoupon-ai-assistant' ),
                'email'       => $test_email,
                'customer_id' => 0,
            )
        );

        if ( $coupon_id <= 0 ) {
            return new WP_Error( 'coupon_failed', __( 'Could not create test coupon.', 'zencoupon-ai-assistant' ) );
        }

        $coupon = new WC_Coupon( $coupon_id );

        $subject = $this->render_placeholders(
            $campaign_data['email_subject'],
            $campaign_data,
            array(
                'name'  => __( 'Admin', 'zencoupon-ai-assistant' ),
                'email' => $test_email,
            ),
            $coupon
        );

        $body = nl2br( esc_html( $this->render_placeholders(
            $campaign_data['email_body'],
            $campaign_data,
            array(
                'name'  => __( 'Admin', 'zencoupon-ai-assistant' ),
                'email' => $test_email,
            ),
            $coupon
        ) ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = (bool) wp_mail( $test_email, $subject, $body, $headers );

        // Clean up test coupon.
        wp_delete_post( $coupon_id, true );

        if ( ! $sent ) {
            return new WP_Error( 'mail_failed', __( 'Could not send test email. Check WordPress mail configuration.', 'zencoupon-ai-assistant' ) );
        }

        return array(
            'success' => true,
            /* translators: %s is the test email address. */
            'message' => sprintf( __( 'Test email sent to %s', 'zencoupon-ai-assistant' ), $test_email ),
        );
    }

    /* ---------------------------------------------------------------------
     * Campaign storage.
     * ------------------------------------------------------------------- */

    public function get_campaigns(): array {
        $campaigns = get_option( self::OPTION_KEY, array() );

        return is_array( $campaigns ) ? $campaigns : array();
    }

    public function get_campaign( string $id ): ?array {
        $campaigns = $this->get_campaigns();

        return isset( $campaigns[ $id ] ) && is_array( $campaigns[ $id ] ) ? $campaigns[ $id ] : null;
    }

    private function save_campaign_record( array $campaign ): void {
        $campaigns = $this->get_campaigns();
        $campaigns[ $campaign['id'] ] = $campaign;
        update_option( self::OPTION_KEY, $campaigns, false );
    }

    /**
     * Sanitizes an admin-reviewed draft and starts the campaign by snapshotting
     * the recipient list and scheduling the first cron batch.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function start_campaign( array $input ) {
        $segment_type   = sanitize_key( (string) ( $input['segment_type'] ?? 'winback' ) );
        $segment_params = isset( $input['segment_params'] ) && is_array( $input['segment_params'] ) ? $input['segment_params'] : array();

        // For backward compat: if segment_type is winback, use segment_days.
        if ( 'winback' === $segment_type ) {
            $segment_params['days'] = absint( $input['segment_days'] ?? self::default_segment_days() );
            $segment_params['days'] = $segment_params['days'] > 0 ? $segment_params['days'] : self::default_segment_days();
        }

        $recipients = $this->resolve_segment( $segment_type, $segment_params );

        if ( is_wp_error( $recipients ) ) {
            return $recipients;
        }

        // Drop any customers the admin removed in the review panel.
        $excluded = array();
        if ( isset( $input['excluded_emails'] ) && is_array( $input['excluded_emails'] ) ) {
            foreach ( $input['excluded_emails'] as $email ) {
                $email = strtolower( sanitize_email( (string) $email ) );
                if ( '' !== $email ) {
                    $excluded[ $email ] = true;
                }
            }
        }

        if ( ! empty( $excluded ) ) {
            $recipients = array_values(
                array_filter(
                    $recipients,
                    static function ( $recipient ) use ( $excluded ) {
                        return ! isset( $excluded[ strtolower( (string) $recipient['email'] ) ] );
                    }
                )
            );
        }

        if ( empty( $recipients ) ) {
            return new WP_Error( 'no_recipients', __( 'No customers matched this segment, so the campaign was not started.', 'zencoupon-ai-assistant' ) );
        }

        $discount_type = in_array( $input['discount_type'] ?? '', array( 'percent', 'fixed_cart' ), true ) ? $input['discount_type'] : 'percent';
        $amount        = is_numeric( $input['discount_amount'] ?? null ) && floatval( $input['discount_amount'] ) > 0 ? (string) floatval( $input['discount_amount'] ) : '10';

        $campaign = array(
            'id'                   => 'camp_' . wp_generate_password( 10, false, false ),
            'name'                 => sanitize_text_field( (string) ( $input['name'] ?? __( 'Campaign', 'zencoupon-ai-assistant' ) ) ),
            'status'               => 'running',
            'segment_type'         => $segment_type,
            'segment_params'       => $segment_params,
            'discount_type'        => $discount_type,
            'discount_amount'      => $amount,
            'expiry_days'          => (string) max( 1, absint( $input['expiry_days'] ?? 30 ) ),
            'usage_limit'          => (string) max( 1, absint( $input['usage_limit'] ?? 1 ) ),
            'usage_limit_per_user' => (string) max( 1, absint( $input['usage_limit_per_user'] ?? 1 ) ),
            'coupon_prefix'        => $this->sanitize_prefix( (string) ( $input['coupon_prefix'] ?? 'ZEN-CAMP' ) ),
            'email_subject'        => sanitize_text_field( (string) ( $input['email_subject'] ?? '' ) ),
            'email_body'           => sanitize_textarea_field( (string) ( $input['email_body'] ?? $this->default_email_body() ) ),
            'social_copy'          => sanitize_textarea_field( (string) ( $input['social_copy'] ?? '' ) ),
            'recipients'           => $recipients,
            'total'                => count( $recipients ),
            'sent'                 => 0,
            'cursor'               => 0,
            'created_at'           => current_time( 'mysql' ),
        );

        if ( '' === trim( $campaign['email_subject'] ) ) {
            $campaign['email_subject'] = __( 'We have a special offer for you', 'zencoupon-ai-assistant' );
        }

        $this->save_campaign_record( $campaign );
        $this->schedule_next( $campaign['id'], 5 );

        return $campaign;
    }

    /**
     * Pauses a running campaign or resumes a paused one.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function toggle_campaign( string $campaign_id ) {
        $campaign = $this->get_campaign( $campaign_id );

        if ( null === $campaign ) {
            return new WP_Error( 'not_found', __( 'Campaign not found.', 'zencoupon-ai-assistant' ) );
        }

        if ( 'completed' === ( $campaign['status'] ?? '' ) ) {
            return new WP_Error( 'completed', __( 'This campaign has already finished.', 'zencoupon-ai-assistant' ) );
        }

        if ( 'running' === ( $campaign['status'] ?? '' ) ) {
            $campaign['status'] = 'paused';
            $this->save_campaign_record( $campaign );
            wp_clear_scheduled_hook( self::CRON_HOOK, array( $campaign_id ) );
        } else {
            $campaign['status'] = 'running';
            $this->save_campaign_record( $campaign );
            $this->schedule_next( $campaign_id, 5 );
        }

        return $campaign;
    }

    private function schedule_next( string $campaign_id, int $delay ): void {
        wp_clear_scheduled_hook( self::CRON_HOOK, array( $campaign_id ) );
        wp_schedule_single_event( time() + max( 1, $delay ), self::CRON_HOOK, array( $campaign_id ) );
    }

    /* ---------------------------------------------------------------------
     * Cron batch execution.
     * ------------------------------------------------------------------- */

    public function run_campaign_batch( string $campaign_id ): void {
        $campaign = $this->get_campaign( $campaign_id );

        if ( null === $campaign || 'running' !== ( $campaign['status'] ?? '' ) ) {
            return;
        }

        $recipients = isset( $campaign['recipients'] ) && is_array( $campaign['recipients'] ) ? $campaign['recipients'] : array();
        $cursor     = absint( $campaign['cursor'] ?? 0 );
        $batch      = array_slice( $recipients, $cursor, self::BATCH_SIZE );

        foreach ( $batch as $recipient ) {
            $coupon_id = $this->create_campaign_coupon( $campaign, $recipient );
            if ( $coupon_id > 0 && $this->send_campaign_email( $campaign, $recipient, $coupon_id ) ) {
                $campaign['sent'] = absint( $campaign['sent'] ) + 1;
            }
        }

        $campaign['cursor'] = $cursor + self::BATCH_SIZE;

        if ( $campaign['cursor'] < count( $recipients ) ) {
            $this->save_campaign_record( $campaign );
            $this->schedule_next( $campaign_id, self::BATCH_GAP );
            return;
        }

        $campaign['status']       = 'completed';
        $campaign['completed_at'] = current_time( 'mysql' );
        $this->save_campaign_record( $campaign );
    }

    private function create_campaign_coupon( array $campaign, array $recipient ): int {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return 0;
        }

        $email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
        if ( '' === $email || ! is_email( $email ) ) {
            return 0;
        }

        $coupon = new WC_Coupon();
        $coupon->set_code( $this->generate_unique_code( $campaign['coupon_prefix'] ) );
        $coupon->set_discount_type( $campaign['discount_type'] );
        $coupon->set_amount( wc_format_decimal( $campaign['discount_amount'] ) );
        $coupon->set_individual_use( true );
        $coupon->set_usage_limit( absint( $campaign['usage_limit'] ) );
        $coupon->set_usage_limit_per_user( absint( $campaign['usage_limit_per_user'] ) );
        $coupon->set_email_restrictions( array( $email ) );
        $coupon->set_description( sprintf( 'Generated by ZenCoupon AI Campaign Builder: %s', sanitize_text_field( $campaign['name'] ) ) );

        $expiry_days = absint( $campaign['expiry_days'] );
        if ( $expiry_days > 0 ) {
            $coupon->set_date_expires( strtotime( '+' . $expiry_days . ' days', current_time( 'timestamp' ) ) );
        }

        $coupon_id = absint( $coupon->save() );
        if ( $coupon_id <= 0 ) {
            return 0;
        }

        update_post_meta( $coupon_id, 'zencoupon_generated', 'yes' );
        update_post_meta( $coupon_id, 'zencoupon_source', self::SOURCE );
        update_post_meta( $coupon_id, 'zencoupon_campaign_id', sanitize_text_field( $campaign['id'] ) );
        update_post_meta( $coupon_id, 'zencoupon_customer_email_hash', wp_hash( $email ) );

        return $coupon_id;
    }

    private function send_campaign_email( array $campaign, array $recipient, int $coupon_id ): bool {
        $to = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
        if ( '' === $to || ! is_email( $to ) ) {
            return false;
        }

        $coupon  = new WC_Coupon( $coupon_id );
        $subject = $this->render_placeholders( $campaign['email_subject'], $campaign, $recipient, $coupon );
        $body    = nl2br( esc_html( $this->render_placeholders( $campaign['email_body'], $campaign, $recipient, $coupon ) ) );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        return (bool) wp_mail( $to, $subject, $body, $headers );
    }

    private function render_placeholders( string $content, array $campaign, array $recipient, WC_Coupon $coupon ): string {
        $expiry   = $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( wc_date_format() ) : __( 'No expiry', 'zencoupon-ai-assistant' );
        $discount = 'percent' === $campaign['discount_type']
            ? sprintf( '%s%%', wc_format_decimal( $campaign['discount_amount'] ) )
            : wp_strip_all_tags( wc_price( $campaign['discount_amount'] ) );

        return strtr(
            $content,
            array(
                '{customer_name}' => (string) ( $recipient['name'] ?? __( 'Customer', 'zencoupon-ai-assistant' ) ),
                '{coupon_code}'   => $coupon->get_code(),
                '{discount}'      => $discount,
                '{expiry_date}'   => $expiry,
                '{store_name}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            )
        );
    }

    private function generate_unique_code( string $prefix ): string {
        $prefix = $this->sanitize_prefix( $prefix );

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $code = $prefix . '-' . strtoupper( wp_generate_password( 6, false, false ) );
            if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) <= 0 ) {
                return $code;
            }
        }

        return $prefix . '-' . strtoupper( wp_generate_uuid4() );
    }

    private function sanitize_prefix( string $value ): string {
        $prefix = substr( strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', $value ) ), 0, 24 );

        return '' === $prefix ? 'ZEN-WINBACK' : $prefix;
    }
}
