<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Assistant_Bridge {

    private const GROQ_API_ENDPOINT   = 'https://api.groq.com/openai/v1/chat/completions';
    private const OPENAI_API_ENDPOINT = 'https://api.openai.com/v1/responses';
    private const GEMINI_API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const DEFAULT_PROVIDER     = 'groq';
    private const GROQ_DEFAULT_MODEL   = 'llama-3.1-8b-instant';
    private const OPENAI_DEFAULT_MODEL = 'gpt-5.5';
    private const GEMINI_DEFAULT_MODEL = 'gemini-2.5-flash';

    public static function get_provider_labels(): array {
        return array(
            'groq'   => __( 'Groq', 'zencoupon-ai-assistant' ),
            'openai' => __( 'OpenAI / GPT', 'zencoupon-ai-assistant' ),
            'gemini' => __( 'Google Gemini', 'zencoupon-ai-assistant' ),
        );
    }

    public static function get_provider_models(): array {
        return array(
            'groq'   => array(
                'llama-3.1-8b-instant'  => 'llama-3.1-8b-instant',
                'llama-3.3-70b-versatile'=> 'llama-3.3-70b-versatile',
                'openai/gpt-oss-20b'     => 'openai/gpt-oss-20b',
                'openai/gpt-oss-120b'    => 'openai/gpt-oss-120b',
            ),
            'openai' => array(
                'gpt-5.5'      => 'gpt-5.5',
                'gpt-5.4'      => 'gpt-5.4',
                'gpt-5.4-mini' => 'gpt-5.4-mini',
                'gpt-5.4-nano' => 'gpt-5.4-nano',
            ),
            'gemini' => array(
                'gemini-3.5-flash'      => 'gemini-3.5-flash',
                'gemini-2.5-flash'      => 'gemini-2.5-flash',
                'gemini-2.5-flash-lite' => 'gemini-2.5-flash-lite',
                'gemini-2.5-pro'        => 'gemini-2.5-pro',
            ),
        );
    }

    public static function get_default_model_for_provider( string $provider ): string {
        switch ( $provider ) {
            case 'openai':
                return self::OPENAI_DEFAULT_MODEL;
            case 'gemini':
                return self::GEMINI_DEFAULT_MODEL;
            case 'groq':
            default:
                return self::GROQ_DEFAULT_MODEL;
        }
    }

    public function call_ai( string $input ) {
        return $this->call_coupon_generator( $input );
    }

    public function call_coupon_generator( string $input ) {
        return $this->call_ai_with_prompt(
            $input,
            $this->get_coupon_generator_system_prompt(),
            'tool_call'
        );
    }

    public function call_campaign_builder( string $input ) {
        return $this->call_ai_with_prompt(
            $input,
            $this->get_campaign_builder_system_prompt(),
            'json'
        );
    }

    private function call_ai_with_prompt( string $input, string $system_prompt, string $response_format = 'tool_call' ) {
        $settings = $this->get_settings();
        $provider = $this->get_ai_provider( $settings );

        switch ( $provider ) {
            case 'openai':
                return $this->call_openai( $input, $settings, $system_prompt, $response_format );
            case 'gemini':
                return $this->call_gemini( $input, $settings, $system_prompt, $response_format );
            case 'groq':
            default:
                return $this->call_groq( $input, $settings, $system_prompt, $response_format );
        }
    }

    public function test_connection( ?array $settings = null ) {
        $settings = is_array( $settings ) ? $settings : $this->get_settings();
        $provider = $this->get_ai_provider( $settings );
        $prompt   = $this->get_coupon_generator_system_prompt();

        switch ( $provider ) {
            case 'openai':
                return $this->call_openai( 'Create a test 10% coupon named TEST10. Return only the tool JSON.', $settings, $prompt, 'tool_call' );
            case 'gemini':
                return $this->call_gemini( 'Create a test 10% coupon named TEST10. Return only the tool JSON.', $settings, $prompt, 'tool_call' );
            case 'groq':
            default:
                return $this->call_groq( 'Create a test 10% coupon named TEST10. Return only the tool JSON.', $settings, $prompt, 'tool_call' );
        }
    }

    private function call_groq( string $input, array $settings, string $system_prompt, string $response_format ) {
        $request = $this->build_groq_request( $input, $settings, $system_prompt );

        if ( is_wp_error( $request ) ) {
            return $request;
        }

        $response = $this->remote_post_with_retry(
            self::GROQ_API_ENDPOINT,
            $request,
            'groq'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_response_text( $this->extract_groq_response_text( $response ), $response_format );
    }

    private function call_openai( string $input, array $settings, string $system_prompt, string $response_format ) {
        $request = $this->build_openai_request( $input, $settings, $system_prompt );

        if ( is_wp_error( $request ) ) {
            return $request;
        }

        $response = $this->remote_post_with_retry(
            self::OPENAI_API_ENDPOINT,
            $request,
            'openai'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_response_text( $this->extract_openai_response_text( $response ), $response_format );
    }

    private function call_gemini( string $input, array $settings, string $system_prompt, string $response_format ) {
        $request = $this->build_gemini_request( $input, $settings, $system_prompt );

        if ( is_wp_error( $request ) ) {
            return $request;
        }

        $response = $this->remote_post_with_retry(
            $request['url'],
            $request['args'],
            'gemini'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_response_text( $this->extract_gemini_response_text( $response ), $response_format );
    }

    private function build_groq_request( string $input, array $settings, string $system_prompt ) {
        $api_key = trim( $settings['groq_api_key'] ?? '' );
        $model   = $this->get_provider_model( 'groq', $settings );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Groq API key is not configured.', 'zencoupon-ai-assistant' ) );
        }

        return array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( array(
                'model'       => $model,
                'messages'    => array(
                    array(
                        'role'    => 'system',
                        'content' => $system_prompt,
                    ),
                    array(
                        'role'    => 'user',
                        'content' => $input,
                    ),
                ),
                'temperature' => 0.0,
                'max_tokens'  => 512,
            ) ),
            'timeout' => 30,
        );
    }

    private function build_openai_request( string $input, array $settings, string $system_prompt ) {
        $api_key = trim( $settings['openai_api_key'] ?? '' );
        $model   = $this->get_provider_model( 'openai', $settings );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'zencoupon-ai-assistant' ) );
        }

        return array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( array(
                'model'       => $model,
                'input'       => array(
                    array(
                        'role'    => 'system',
                        'content' => $system_prompt,
                    ),
                    array(
                        'role'    => 'user',
                        'content' => $input,
                    ),
                ),
                'max_output_tokens' => 512,
            ) ),
            'timeout' => 30,
        );
    }

    private function build_gemini_request( string $input, array $settings, string $system_prompt ) {
        $api_key = trim( $settings['gemini_api_key'] ?? '' );
        $model   = $this->get_provider_model( 'gemini', $settings );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Gemini API key is not configured.', 'zencoupon-ai-assistant' ) );
        }

        return array(
            'url'  => self::GEMINI_API_ENDPOINT . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key ),
            'args' => array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'contents'         => array(
                        array(
                            'parts' => array(
                                array(
                                    'text' => $system_prompt . "\n\nUser instruction:\n" . $input,
                                ),
                            ),
                        ),
                    ),
                    'generationConfig' => array(
                        'temperature'     => 0,
                        'maxOutputTokens' => 512,
                    ),
                ) ),
                'timeout' => 30,
            ),
        );
    }

    private function extract_groq_response_text( $response ) {
        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $status_code || empty( $data['choices'][0]['message']['content'] ) ) {
            return $this->api_error_from_response( $data, $status_code, 'Groq' );
        }

        return $data['choices'][0]['message']['content'];
    }

    private function extract_openai_response_text( $response ) {
        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $status_code ) {
            return $this->api_error_from_response( $data, $status_code, 'OpenAI' );
        }

        $text = '';

        if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
            $text = $data['output_text'];
        } elseif ( ! empty( $data['output'] ) && is_array( $data['output'] ) ) {
            foreach ( $data['output'] as $item ) {
                if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
                    continue;
                }

                foreach ( $item['content'] as $content ) {
                    if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
                        $text .= $content['text'];
                    }
                }
            }
        }

        if ( '' === trim( $text ) ) {
            return $this->api_error_from_response( $data, $status_code, 'OpenAI' );
        }

        return $text;
    }

    private function extract_gemini_response_text( $response ) {
        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $status_code || empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $this->api_error_from_response( $data, $status_code, 'Gemini' );
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    private function parse_response_text( $text, string $response_format ) {
        if ( is_wp_error( $text ) ) {
            return $text;
        }

        if ( 'json' === $response_format ) {
            return $this->parse_plain_json( (string) $text );
        }

        return $this->parse_tool_call_json( (string) $text );
    }

    private function parse_tool_call_json( string $text ) {
        $decoded = json_decode( $this->clean_ai_response( $text ), true );

        if ( ! is_array( $decoded ) || empty( $decoded['name'] ) || ! is_string( $decoded['name'] ) ) {
            return new WP_Error( 'invalid_tool_response', __( 'Invalid AI JSON response.', 'zencoupon-ai-assistant' ) );
        }

        return array(
            'name'      => sanitize_text_field( $decoded['name'] ),
            'arguments' => isset( $decoded['arguments'] ) && is_array( $decoded['arguments'] ) ? $decoded['arguments'] : array(),
        );
    }

    private function parse_plain_json( string $text ) {
        $cleaned = $this->clean_ai_response( $text );
        $decoded = json_decode( $cleaned, true );

        // If JSON parsing failed and response looks incomplete, try to repair it
        if ( ! is_array( $decoded ) && strpos( $cleaned, '{' ) === 0 ) {
            $repaired = $this->repair_incomplete_json( $cleaned );
            $decoded  = json_decode( $repaired, true );
        }

        if ( ! is_array( $decoded ) ) {
            $error_msg = sprintf(
                __( 'Invalid AI JSON response. Raw: %s', 'zencoupon-ai-assistant' ),
                substr( $cleaned, 0, 150 )
            );
            return new WP_Error( 'invalid_json_response', $error_msg );
        }

        return $decoded;
    }

    private function repair_incomplete_json( string $json ): string {
        $open_braces  = substr_count( $json, '{' );
        $close_braces = substr_count( $json, '}' );

        // Close unclosed string at end if needed
        if ( substr( $json, -1 ) !== '"' && substr( $json, -1 ) !== '}' ) {
            $json .= '"';
        }

        // Add missing closing braces
        while ( $close_braces < $open_braces ) {
            $json .= '}';
            $close_braces++;
        }

        return $json;
    }

    private function clean_ai_response( string $response ): string {
        $response = trim( $response );

        // Strip markdown code blocks
        $response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
        $response = preg_replace( '/\s*```\s*$/i', '', $response );

        return trim( $response );
    }

    private function api_error_from_response( $data, int $status_code, string $provider ) {
        $api_message = $this->extract_api_error_message( $data );

        if ( ! empty( $api_message ) ) {
            return new WP_Error( strtolower( $provider ) . '_error', $api_message );
        }

        return new WP_Error(
            strtolower( $provider ) . '_error',
            sprintf(
                /* translators: 1: API service name, 2: HTTP status code */
                __( '%1$s API returned an unexpected response. HTTP status: %2$d.', 'zencoupon-ai-assistant' ),
                $provider,
                $status_code
            )
        );
    }

    private function extract_api_error_message( $data ): string {
        if ( isset( $data['error']['message'] ) ) {
            return sanitize_text_field( $data['error']['message'] );
        }

        if ( isset( $data['error']['error']['message'] ) ) {
            return sanitize_text_field( $data['error']['error']['message'] );
        }

        if ( isset( $data['message'] ) ) {
            return sanitize_text_field( $data['message'] );
        }

        return '';
    }

    private function remote_post_with_retry( string $url, array $args, string $provider = 'groq' ) {
        $max_attempts = 3;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $response = wp_remote_post( $url, $args );

            if ( is_wp_error( $response ) ) {
                if ( $attempt === $max_attempts ) {
                    return $response;
                }

                sleep( 2 );
                continue;
            }

            $status_code = wp_remote_retrieve_response_code( $response );

            if ( in_array( $status_code, array( 429, 500, 502, 503, 504 ), true ) ) {
                if ( $attempt === $max_attempts ) {
                    return new WP_Error(
                        $provider . '_error',
                        sprintf(
                            /* translators: 1: API service name, 2: HTTP status code */
                            __( '%1$s API returned HTTP %2$d.', 'zencoupon-ai-assistant' ),
                            ucfirst( $provider ),
                            $status_code
                        )
                    );
                }

                sleep( 2 );
                continue;
            }

            return $response;
        }

        return new WP_Error(
            $provider . '_error',
            sprintf(
                /* translators: %s: API service name */
                __( '%s API request failed.', 'zencoupon-ai-assistant' ),
                ucfirst( $provider )
            )
        );
    }

    private function get_settings(): array {
        $settings = get_option( ZenCoupon_AI_Assistant_Main::OPTION_KEY, array() );

        return is_array( $settings ) ? $settings : array();
    }

    private function get_ai_provider( array $settings ): string {
        $provider = isset( $settings['ai_provider'] ) ? sanitize_text_field( $settings['ai_provider'] ) : self::DEFAULT_PROVIDER;

        return in_array( $provider, array( 'groq', 'openai', 'gemini' ), true ) ? $provider : self::DEFAULT_PROVIDER;
    }

    private function get_provider_model( string $provider, array $settings ): string {
        $key   = $provider . '_model_name';
        $model = isset( $settings[ $key ] ) ? sanitize_text_field( $settings[ $key ] ) : '';

        if ( 'groq' === $provider && 'llama3-8b-8192' === $model ) {
            return self::GROQ_DEFAULT_MODEL;
        }

        if ( '' === trim( $model ) ) {
            return self::get_default_model_for_provider( $provider );
        }

        return $model;
    }

    private function get_coupon_generator_system_prompt(): string {
        return 'You are a WooCommerce coupon management assistant. '
            . 'Return ONLY valid JSON. Do not use markdown. Do not explain anything. '
            . 'The JSON shape must be exactly {"name":"tool_name","arguments":{}}. '
            . 'Allowed tools are create_coupon, update_coupon, list_coupons, list_generated_coupons, and delete_coupon. '
            . 'For create_coupon, supported arguments are: code string, amount number, discount_type percent|fixed_cart|fixed_product, expiry_date YYYY-MM-DD, minimum_amount number, maximum_amount number, usage_limit number, usage_limit_per_user number, individual_use boolean, free_shipping boolean, exclude_sale_items boolean, email_restrictions array, product_ids array of IDs, excluded_product_ids array of IDs, product_categories array of IDs, excluded_product_categories array of IDs. '
            . 'For update_coupon, supported arguments are: coupon_id number or code string, plus any create_coupon fields that should change. '
            . 'Use update_coupon when the user asks to edit, update, change, modify, revise, adjust, or refers to an existing, recent, latest, or last coupon. Do not create a new coupon for edit or update requests. '
            . 'If the user asks for a percentage discount, use discount_type percent. If they ask for a fixed/cart amount, use fixed_cart. '
            . 'Do not invent product category IDs, excluded category IDs, product IDs, or email restrictions unless they are provided in the user instruction. '
            . 'For create_coupon only, if no coupon code is provided, generate a short uppercase code related to the instruction.';
    }

    private function get_campaign_builder_system_prompt(): string {
        return 'Output ONLY JSON. No markdown. Keep concise. '
            . 'Fields: campaign_name, discount_type, discount_amount, expiry_days, usage_limit, usage_limit_per_user, email_subject, email_body, social_post_copy, segment_type, segment_params. '
            . 'discount_type: "percent" or "fixed_cart". segment_type MUST be: "winback", "category", "product", "tag", "never_ordered", or "all_customers". '
            . 'If the idea doesn\'t clearly fit a segment type, use "winback" (inactive customers). '
            . 'segment_params: {"days":60} for winback, {} for others. email_body short with {customer_name} {coupon_code} {discount}. '
            . 'Example: {"campaign_name":"Sale","discount_type":"percent","discount_amount":10,"expiry_days":30,"usage_limit":1,"usage_limit_per_user":1,"email_subject":"Offer","email_body":"Hi {customer_name}, {coupon_code}","social_post_copy":"Limited","segment_type":"winback","segment_params":{"days":60}}';
    }
}
