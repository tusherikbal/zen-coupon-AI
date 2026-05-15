<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZenCoupon_AI_Bridge {

    private const GROQ_API_ENDPOINT    = 'https://api.groq.com/openai/v1/chat/completions';
    private const GEMINI_API_ENDPOINT  = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const GROQ_DEFAULT_MODEL   = 'llama3-8b-8192';
    private const GEMINI_DEFAULT_MODEL = 'gemini-2.5-flash';

    public function call_ai( string $input ) {

        $provider = $this->get_ai_provider();

        if ( 'gemini' === $provider ) {
            return $this->call_gemini( $input );
        }

        return $this->call_groq( $input );
    }

    public function call_groq( string $input ) {

        $api_key = trim( $this->get_groq_api_key() );
        $model   = $this->get_groq_model_name();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'missing_api_key',
                __( 'Groq API key is not configured.', 'zencoupon-ai-assistance' )
            );
        }

        $body = array(
            'model'       => $model,
            'messages'    => array(
                array(
                    'role'    => 'user',
                    'content' => $this->get_prompt_payload( $input ),
                ),
            ),
            'temperature' => 0.0,
            'max_tokens'  => 512,
        );

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        );

        $response = $this->remote_post_with_retry(
            self::GROQ_API_ENDPOINT,
            $args,
            'groq'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if (
            200 !== $status_code ||
            ! isset( $data['choices'][0]['message']['content'] )
        ) {

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log(
                    'Groq Error Response: ' . $response_body
                );
            }

            return new WP_Error(
                'groq_error',
                sprintf(
                    /* translators: %s: API service name */
                    __( '%s API returned an unexpected response.', 'zencoupon-ai-assistance' ),
                    'Groq'
                )
            );
        }

        $ai_output = $data['choices'][0]['message']['content'];

        return $this->parse_tool_call(
            $this->clean_ai_response( $ai_output )
        );
    }

    public function call_gemini( string $input ) {

        $api_key = trim( $this->get_gemini_api_key() );
        $model   = $this->get_gemini_model_name();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'missing_api_key',
                __( 'Gemini API key is not configured.', 'zencoupon-ai-assistance' )
            );
        }

        // Properly format the model name and construct the URL
        $model_path = str_replace( '/', '-', $model );

        $url = self::GEMINI_API_ENDPOINT .
            $model_path .
            ':generateContent?key=' .
            rawurlencode( $api_key );

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $this->get_prompt_payload( $input ),
                        ),
                    ),
                ),
            ),
            'generationConfig' => array(
                'temperature'     => 0.1,
                'maxOutputTokens' => 1024,
            ),
        );

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        );

        $response = $this->remote_post_with_retry(
            $url,
            $args,
            'gemini'
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $status_code ) {

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log(
                    'Gemini Full Error Response: ' .
                    print_r( $data, true )
                );
            }

            return new WP_Error(
                'gemini_error',
                sprintf(
                    /* translators: 1: HTTP status code, 2: API service name */
                    __( '%2$s API returned HTTP %1$d.', 'zencoupon-ai-assistance' ),
                    $status_code,
                    'Gemini'
                )
            );
        }

        if ( isset( $data['error'] ) ) {

            return new WP_Error(
                'gemini_error',
                $data['error']['message']
            );
        }

        if ( ! isset( $data['candidates'] ) || ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log(
                    'Gemini Invalid Structure: ' .
                    print_r( $data, true )
                );
            }

            return new WP_Error(
                'gemini_error',
                __( 'Gemini response format invalid.', 'zencoupon-ai-assistance' )
            );
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'];

        if ( empty( $text ) ) {

            return new WP_Error(
                'gemini_error',
                __( 'Gemini returned empty response.', 'zencoupon-ai-assistance' )
            );
        }

        return $this->parse_tool_call(
            $this->clean_ai_response( $text )
        );
    }

    private function get_ai_provider(): string {

        $settings = get_option(
            ZenCoupon_AI_Assistant::OPTION_KEY
        );

        return $settings['ai_provider'] ?? 'groq';
    }

    private function get_groq_api_key(): string {

        $settings = get_option(
            ZenCoupon_AI_Assistant::OPTION_KEY
        );

        return $settings['groq_api_key'] ?? '';
    }

    private function get_groq_model_name(): string {

        $settings = get_option(
            ZenCoupon_AI_Assistant::OPTION_KEY
        );

        return ! empty( $settings['groq_model_name'] )
            ? $settings['groq_model_name']
            : self::GROQ_DEFAULT_MODEL;
    }

    private function get_gemini_api_key(): string {

        $settings = get_option(
            ZenCoupon_AI_Assistant::OPTION_KEY
        );

        return $settings['gemini_api_key'] ?? '';
    }

    private function get_gemini_model_name(): string {

        $settings = get_option(
            ZenCoupon_AI_Assistant::OPTION_KEY
        );

        $model = $settings['gemini_model_name'] ?? '';

        if (
            empty( $model ) ||
            'gemini-pro' === $model
        ) {
            return self::GEMINI_DEFAULT_MODEL;
        }

        return $model;
    }

    private function remote_post_with_retry(
        string $url,
        array $args,
        string $provider = 'groq'
    ) {

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

            if (
                in_array(
                    $status_code,
                    array( 429, 500, 502, 503, 504 ),
                    true
                )
            ) {

                if ( $attempt === $max_attempts ) {

                    return new WP_Error(
                        $provider . '_error',
                        sprintf(
                            /* translators: 1: API service name, 2: HTTP status code */
                            __( '%1$s API returned HTTP %2$d.', 'zencoupon-ai-assistance' ),
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
                __( '%s API request failed.', 'zencoupon-ai-assistance' ),
                ucfirst( $provider )
            )
        );
    }

    private function get_prompt_payload( string $input ): string {

        return $this->get_system_prompt()
            . "\n\nUser instruction:\n"
            . $input;
    }

    private function clean_ai_response( string $response ): string {

        $response = trim( $response );

        $response = preg_replace(
            '/^```(?:json)?\s*/i',
            '',
            $response
        );

        $response = preg_replace(
            '/\s*```$/',
            '',
            $response
        );

        if (
            preg_match(
                '/\{(?:[^{}]|(?R))*\}/s',
                $response,
                $matches
            )
        ) {
            return trim( $matches[0] );
        }

        return $response;
    }

    private function get_system_prompt(): string {

        return 'You are a WooCommerce coupon management assistant. '
            . 'You must respond with ONLY valid JSON in this exact format: {"name":"tool_name","arguments":{}} '
            . 'Do not explain anything. Do not use markdown. '
            . 'Available tools: '
            . '1. create_coupon: Creates a new WooCommerce coupon. Arguments: '
            . '{"code":"string","amount":"number","discount_type":"percent|fixed_cart|fixed_product","expiry_date":"YYYY-MM-DD","minimum_amount":"number","maximum_amount":"number","usage_limit":"number","usage_limit_per_user":"number","individual_use":"boolean","free_shipping":"boolean","exclude_sale_items":"boolean","email_restrictions":["email1","email2"],"product_categories":[1,2],"excluded_product_categories":[3,4]} '
            . '2. list_coupons: Lists all coupons. Arguments: {} '
            . '3. list_generated_coupons: Lists AI-generated coupons. Arguments: {} '
            . '4. delete_coupon: Deletes a coupon. Arguments: {"coupon_id":"number"} '
            . 'Example: {"name":"create_coupon","arguments":{"code":"SAVE10","amount":10,"discount_type":"percent"}}';
    }

    private function parse_tool_call( string $response ) {

        $decoded = json_decode( $response, true );

        if (
            ! is_array( $decoded ) ||
            empty( $decoded['name'] )
        ) {

            return new WP_Error(
                'invalid_tool_response',
                __( 'Invalid AI JSON response.', 'zencoupon-ai-assistance' )
            );
        }

        return array(
            'name'      => $decoded['name'],
            'arguments' => $decoded['arguments'] ?? array(),
        );
    }
}