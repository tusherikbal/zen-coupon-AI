<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Assistant_MCP {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        register_rest_route(
            ZenCoupon_AI_Assistant_Main::REST_NAMESPACE,
            '/' . ZenCoupon_AI_Assistant_Main::REST_ROUTE,
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_request' ),
                'permission_callback' => array( $this, 'permission_check' ),
            )
        );
    }

    public function permission_check(): bool {
        return current_user_can( 'manage_shop_coupons' ) || current_user_can( 'manage_woocommerce' );
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body ) || ! is_array( $body ) ) {
            return rest_ensure_response( $this->error_response( null, -32700, __( 'Invalid JSON-RPC body.', 'zencoupon-ai-assistant' ) ) );
        }

        $id = $body['id'] ?? null;
        $jsonrpc = $body['jsonrpc'] ?? '';
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? array();

        if ( '2.0' !== $jsonrpc ) {
            return rest_ensure_response( $this->error_response( $id, -32600, __( 'Invalid JSON-RPC version.', 'zencoupon-ai-assistant' ) ) );
        }

        if ( empty( $method ) ) {
            return rest_ensure_response( $this->error_response( $id, -32600, __( 'Method is required.', 'zencoupon-ai-assistant' ) ) );
        }

        $result = $this->process_method( $method, $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $this->error_response( $id, $result->get_error_code() ?: -32000, $result->get_error_message() ) );
        }

        return rest_ensure_response( $this->success_response( $id, $result ) );
    }

    public function execute_tool_call( array $tool_call ) {
        if ( empty( $tool_call['name'] ) || ! is_string( $tool_call['name'] ) ) {
            return new WP_Error( 'invalid_tool_call', __( 'Tool call name is required.', 'zencoupon-ai-assistant' ) );
        }

        $method = sanitize_text_field( $tool_call['name'] );
        $params = isset( $tool_call['arguments'] ) && is_array( $tool_call['arguments'] ) ? $tool_call['arguments'] : array();

        $result = $this->process_method( $method, $params );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }

    private function process_method( string $method, $params ) {
        $actions = new ZenCoupon_AI_Assistant_Actions();

        switch ( $method ) {
            case 'create_coupon':
                return $actions->create_coupon( (array) $params );
            case 'update_coupon':
                return $actions->update_coupon( (array) $params );
            case 'list_coupons':
                return $actions->list_coupons();
            case 'list_generated_coupons':
                return $actions->list_generated_coupons();
            case 'delete_coupon':
                return $actions->delete_coupon( $params['coupon_id'] ?? $params['id'] ?? 0 );
            default:
                return new WP_Error( 'method_not_found', sprintf( /* translators: %s is the requested tool/method name. */ __( 'Unknown tool: %s', 'zencoupon-ai-assistant' ), esc_html( $method ) ) );
        }
    }

    private function success_response( $id, $result ): array {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    private function error_response( $id, int $code, string $message ): array {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array(
                'code'    => $code,
                'message' => $message,
            ),
        );
    }
}
