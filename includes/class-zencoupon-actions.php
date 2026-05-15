<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_Actions {
    public function create_coupon( array $data ): array {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return array( 'error' => 'WooCommerce is required to create coupons.' );
        }

        $code = isset( $data['code'] ) ? sanitize_text_field( $data['code'] ) : '';
        $amount = isset( $data['amount'] ) ? floatval( $data['amount'] ) : 0.0;
        $discount_type = isset( $data['discount_type'] ) ? sanitize_text_field( $data['discount_type'] ) : '';
        $expiry_date = isset( $data['expiry_date'] ) ? sanitize_text_field( $data['expiry_date'] ) : '';

        if ( empty( $code ) ) {
            return array( 'error' => 'Coupon code is required.' );
        }

        if ( $amount <= 0 ) {
            return array( 'error' => 'Coupon amount must be greater than zero.' );
        }

        $discount_type = $this->normalize_discount_type( $discount_type );
        if ( ! in_array( $discount_type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
            return array( 'error' => 'Discount type must be percentage or fixed.' );
        }

        $coupon_data = array(
            'post_title'  => $code,
            'post_name'   => sanitize_title( $code ),
            'post_status' => 'publish',
            'post_type'   => 'shop_coupon',
        );

        $coupon_id = wp_insert_post( $coupon_data );

        if ( is_wp_error( $coupon_id ) || $coupon_id <= 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ZenCoupon: Failed to create coupon post. Error: ' . ( is_wp_error( $coupon_id ) ? $coupon_id->get_error_message() : 'Invalid ID' ) );
            }
            return array( 'error' => 'Unable to create coupon.' );
        }

        $coupon = new WC_Coupon( $coupon_id );
        $coupon->set_code( $code );
        $coupon->set_discount_type( $discount_type );
        $coupon->set_amount( wc_format_decimal( $amount ) );
        $coupon->set_individual_use( true );
        $coupon->set_usage_limit( 0 );
        $coupon->set_usage_limit_per_user( 0 );
        $coupon->set_free_shipping( false );
        $coupon->set_description( sprintf( 'Generated via ZenCoupon AI Assistant: %s', $code ) );

        if ( isset( $data['minimum_amount'] ) ) {
            $coupon->set_minimum_amount( floatval( $data['minimum_amount'] ) );
        }

        if ( isset( $data['maximum_amount'] ) ) {
            $coupon->set_maximum_amount( floatval( $data['maximum_amount'] ) );
        }

        if ( isset( $data['exclude_sale_items'] ) ) {
            $coupon->set_exclude_sale_items( filter_var( $data['exclude_sale_items'], FILTER_VALIDATE_BOOLEAN ) );
        }

        if ( isset( $data['individual_use'] ) ) {
            $coupon->set_individual_use( filter_var( $data['individual_use'], FILTER_VALIDATE_BOOLEAN ) );
        }

        if ( isset( $data['usage_limit'] ) ) {
            $coupon->set_usage_limit( absint( $data['usage_limit'] ) );
        }

        if ( isset( $data['usage_limit_per_user'] ) ) {
            $coupon->set_usage_limit_per_user( absint( $data['usage_limit_per_user'] ) );
        }

        if ( isset( $data['free_shipping'] ) ) {
            $coupon->set_free_shipping( filter_var( $data['free_shipping'], FILTER_VALIDATE_BOOLEAN ) );
        }

        if ( isset( $data['email_restrictions'] ) ) {
            $coupon->set_email_restrictions( array_map( 'sanitize_email', (array) $data['email_restrictions'] ) );
        }

        if ( isset( $data['product_ids'] ) ) {
            $coupon->set_product_ids( array_filter( wp_parse_id_list( (array) $data['product_ids'] ) ) );
        }

        if ( isset( $data['excluded_product_ids'] ) ) {
            $coupon->set_excluded_product_ids( array_filter( wp_parse_id_list( (array) $data['excluded_product_ids'] ) ) );
        }

        if ( isset( $data['product_categories'] ) ) {
            $coupon->set_product_categories( array_filter( wp_parse_id_list( (array) $data['product_categories'] ) ) );
        }

        if ( isset( $data['excluded_product_categories'] ) ) {
            $coupon->set_excluded_product_categories( array_filter( wp_parse_id_list( (array) $data['excluded_product_categories'] ) ) );
        }

        if ( ! empty( $expiry_date ) ) {
            $expiry_timestamp = strtotime( $expiry_date );
            if ( $expiry_timestamp > 0 ) {
                $coupon->set_date_expires( $expiry_timestamp );
            }
        }

        $save_result = $coupon->save();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Coupon save result: ' . $save_result );
        }

        update_post_meta( $coupon_id, 'zencoupon_generated', 'yes' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ZenCoupon: Coupon created successfully with ID: ' . $coupon_id );
        }

        return array(
            'coupon_id'     => absint( $coupon_id ),
            'code'          => $code,
            'amount'        => $amount,
            'discount_type' => $discount_type,
            'expiry_date'   => $expiry_date,
            'message'       => 'Coupon created successfully.',
        );
    }

    public function list_coupons(): array {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return array( 'error' => 'WooCommerce is required to list coupons.' );
        }

        $coupons = get_posts( array(
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $results = array();
        $now = current_time( 'timestamp' );

        foreach ( $coupons as $coupon_post ) {
            $coupon_data = $this->get_coupon_data( $coupon_post );
            if ( $coupon_data ) {
                $results[] = $coupon_data;
            }
        }

        return array( 'coupons' => $results );
    }

    public function list_generated_coupons(): array {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return array( 'error' => 'WooCommerce is required to list coupons.' );
        }

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Using meta_query to find AI-generated coupons by custom meta key.
        $coupons = get_posts( array(
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'   => 'zencoupon_generated',
                    'value' => 'yes',
                ),
            ),
        ) );

        $results = array();

        foreach ( $coupons as $coupon_post ) {
            $coupon_data = $this->get_coupon_data( $coupon_post );
            if ( $coupon_data ) {
                $results[] = $coupon_data;
            }
        }

        return array( 'coupons' => $results );
    }

    private function get_coupon_data( WP_Post $coupon_post ): ?array {
        $coupon = new WC_Coupon( $coupon_post->ID );
        $expiry_date = $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( 'Y-m-d' ) : '';

        return array(
            'coupon_id'                  => absint( $coupon_post->ID ),
            'code'                       => $coupon->get_code(),
            'amount'                     => wc_format_decimal( $coupon->get_amount() ),
            'discount_type'              => $coupon->get_discount_type(),
            'expiry_date'                => $expiry_date,
            'individual_use'             => $coupon->get_individual_use(),
            'usage_limit'                => absint( $coupon->get_usage_limit() ),
            'usage_limit_per_user'       => absint( $coupon->get_usage_limit_per_user() ),
            'free_shipping'              => $coupon->get_free_shipping(),
            'minimum_amount'             => wc_format_decimal( $coupon->get_minimum_amount() ),
            'maximum_amount'             => wc_format_decimal( $coupon->get_maximum_amount() ),
            'exclude_sale_items'         => $coupon->get_exclude_sale_items(),
            'product_categories'         => $coupon->get_product_categories(),
            'excluded_product_categories'=> $coupon->get_excluded_product_categories(),
            'product_ids'                => $coupon->get_product_ids(),
            'excluded_product_ids'       => $coupon->get_excluded_product_ids(),
            'email_restrictions'         => $coupon->get_email_restrictions(),
            'date_created'               => $coupon_post->post_date,
        );
    }

    public function delete_coupon( $coupon_id ): array {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return array( 'error' => 'WooCommerce is required to delete coupons.' );
        }

        $coupon_id = absint( $coupon_id );

        if ( $coupon_id <= 0 || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
            return array( 'error' => 'Invalid coupon ID.' );
        }

        $deleted = wp_delete_post( $coupon_id, true );

        if ( ! $deleted ) {
            return array( 'error' => 'Unable to delete coupon.' );
        }

        return array(
            'coupon_id' => $coupon_id,
            'deleted'   => true,
            'message'   => 'Coupon deleted successfully.',
        );
    }

    private function normalize_discount_type( string $discount_type ): string {
        $discount_type = strtolower( trim( $discount_type ) );

        if ( in_array( $discount_type, array( 'percent', 'percentage' ), true ) ) {
            return 'percent';
        }

        if ( in_array( $discount_type, array( 'fixed', 'fixed_cart' ), true ) ) {
            return 'fixed_cart';
        }

        if ( 'fixed_product' === $discount_type ) {
            return 'fixed_product';
        }

        return $discount_type;
    
    }
}
