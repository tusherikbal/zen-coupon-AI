=== ZenCoupon AI Assistant ===
Contributors: tusherikbal
Tags: woocommerce, coupon, ai, automation, artificial-intelligence
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate and manage WooCommerce coupons using AI-powered natural language commands.

== Description ==

ZenCoupon AI Assistant is a WordPress and WooCommerce plugin that helps store owners create, update, and manage coupons using simple AI-powered commands.

Instead of manually configuring every WooCommerce coupon field, store owners can type a plain-language instruction such as "Create a 20% coupon for Eid sale with minimum spend 1000", and the plugin converts that command into WooCommerce-ready coupon data.

The plugin supports Groq, OpenAI/GPT, and Google Gemini, giving users flexibility to choose their preferred AI provider for coupon generation.

= Key Features =

* Create WooCommerce coupons using natural language commands
* Update existing coupons with AI-powered instructions
* Choose between Groq, OpenAI/GPT, and Google Gemini
* Select from curated AI models or enter a custom model name
* Save and manage provider API keys from the admin panel
* Test AI provider connection from plugin settings
* Generate coupon rules automatically
* View generated coupons from the admin dashboard
* Delete and manage coupons easily
* View recent activity inside the plugin panel
* Docs and support admin page
* Support form powered by wp_mail()
* WooCommerce HPOS compatibility
* REST API and JSON-RPC 2.0 style MCP endpoint for integration

= Coupon Options Supported =

ZenCoupon AI Assistant can create and manage many WooCommerce coupon settings, including:

* Coupon code
* Percentage discount
* Fixed cart discount
* Fixed product discount
* Expiry date
* Minimum spend
* Maximum spend
* Usage limit
* Usage limit per user
* Individual use only
* Free shipping
* Exclude sale items
* Email restrictions
* Product IDs
* Excluded product IDs
* Product categories
* Excluded product categories

= Supported AI Providers =

ZenCoupon AI Assistant supports three AI providers.

**Groq AI**

Groq is useful for fast responses and low-latency coupon command processing.

Recommended model:

* llama-3.1-8b-instant

**OpenAI/GPT**

OpenAI/GPT is useful for high-quality structured coupon output.

Default model:

* gpt-5.5

Other supported options:

* gpt-5.4-mini
* gpt-5.4-nano

**Google Gemini**

Google Gemini is useful for fast structured JSON responses.

Default model:

* gemini-2.5-flash

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* WooCommerce 3.0 or higher
* Active API key from Groq, OpenAI, or Google Gemini

== External Services ==

This plugin connects to external AI APIs to process natural language coupon commands and generate WooCommerce coupon configurations.

The plugin only sends the prompt entered by the admin user, such as coupon requirements, discount rules, expiry instructions, or campaign details. No customer personal data, order data, store financial data, or payment data is intentionally transmitted by the plugin.

= Groq AI =

Service provider: Groq, Inc.

Endpoint used:

https://api.groq.com/openai/v1/chat/completions

Terms of Service:

https://groq.com/terms-of-service/

Privacy Policy:

https://groq.com/privacy-policy/

= OpenAI =

Service provider: OpenAI, L.L.C.

Endpoint used:

https://api.openai.com/v1/responses

Terms of Use:

https://openai.com/policies/terms-of-use/

Privacy Policy:

https://openai.com/policies/privacy-policy/

= Google Gemini =

Service provider: Google LLC.

Endpoint used:

https://generativelanguage.googleapis.com/v1beta/models/

Terms of Service:

https://policies.google.com/terms

Privacy Policy:

https://policies.google.com/privacy

== Installation ==

= From WordPress Plugin Directory =

1. Go to Plugins > Add New in your WordPress admin dashboard.
2. Search for "ZenCoupon AI Assistant".
3. Click Install Now.
4. Click Activate.

= Manual Installation =

1. Download the plugin ZIP file.
2. Go to Plugins > Add New > Upload Plugin.
3. Choose the ZIP file.
4. Click Install Now.
5. Click Activate Plugin.

== Configuration ==

= 1. Get an API Key =

Before using the plugin, you need an API key from at least one supported AI provider.

For Groq AI:

1. Visit https://console.groq.com
2. Create an account or sign in.
3. Generate an API key.
4. Copy the API key.

For OpenAI:

1. Visit https://platform.openai.com
2. Create an account or sign in.
3. Generate an API key.
4. Copy the API key.

For Google Gemini:

1. Visit https://aistudio.google.com
2. Create an account or sign in.
3. Generate an API key.
4. Copy the API key.

= 2. Configure the Plugin =

1. In your WordPress admin dashboard, go to ZenCoupon AI.
2. Select your AI provider.
3. Enter your provider API key.
4. Choose a model or enter a custom model name.
5. Click Save Settings.
6. Use the Test Connection button to check your provider connection.

== Usage ==

= Creating Coupons with AI =

1. Go to WordPress Dashboard > ZenCoupon AI.
2. Open the command console.
3. Type your coupon instruction in natural language.
4. Click the generate button.
5. Review the generated coupon details.
6. Create the coupon.

Example prompts:

* Create a 15% discount coupon for Summer Sale.
* Create BLACKFRIDAY coupon with 30% discount.
* Create a fixed 500 discount coupon with minimum spend 3000.
* Create a free shipping coupon for VIP customers.
* Update recent coupon to 20% discount.
* Create a coupon for category ID 12, expires on 2026-12-31.

= Via REST API =

The plugin provides a JSON-RPC 2.0 compatible REST endpoint at:

`POST /wp-json/zencoupon/v1/mcp`

= Creating a Coupon =

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "create_coupon",
    "params": {
      "code": "SAVE20",
      "amount": 20,
      "discount_type": "percent",
      "expiry_date": "2026-12-31"
    }
  }'
```

= Listing Coupons =

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "list_coupons"
  }'
```

= Listing AI-Generated Coupons =

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "list_generated_coupons"
  }'
```

= Deleting a Coupon =

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 4,
    "method": "delete_coupon",
    "params": {
      "coupon_id": 123
    }
  }'
```

== Coupon Parameters ==

| Parameter                   | Type    | Required | Description                                      |
| --------------------------- | ------- | -------- | ------------------------------------------------ |
| code                        | string  | Yes      | Unique coupon code                               |
| amount                      | float   | Yes      | Discount amount                                  |
| discount_type               | string  | Yes      | percent, fixed_cart, or fixed_product            |
| expiry_date                 | string  | No       | Date in YYYY-MM-DD format                        |
| minimum_amount              | float   | No       | Minimum cart total                               |
| maximum_amount              | float   | No       | Maximum cart total                               |
| exclude_sale_items          | boolean | No       | Exclude sale items from discount                 |
| individual_use              | boolean | No       | Allow the coupon to be used individually only    |
| usage_limit                 | int     | No       | Total number of times the coupon can be used     |
| usage_limit_per_user        | int     | No       | Number of times each customer can use the coupon |
| free_shipping               | boolean | No       | Grant free shipping                              |
| email_restrictions          | array   | No       | Allowed customer emails                          |
| product_ids                 | array   | No       | Specific product IDs                             |
| excluded_product_ids        | array   | No       | Excluded product IDs                             |
| product_categories          | array   | No       | Specific category IDs                            |
| excluded_product_categories | array   | No       | Excluded category IDs                            |

== API Response Examples ==

= Success Response =

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "coupon_id": 123,
    "code": "SAVE20",
    "amount": 20,
    "discount_type": "percent",
    "message": "Coupon created successfully."
  }
}
```

= Error Response =

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32600,
    "message": "Coupon code is required."
  }
}
```

== Security ==

ZenCoupon AI Assistant follows standard WordPress security practices.

* Direct file access is blocked with ABSPATH checks.
* Admin actions are protected with nonce verification.
* Capability checks are used for coupon and WooCommerce management.
* Input is sanitized before processing.
* REST API access is permission protected.
* API keys are stored in WordPress options.
* Support form submissions are sanitized and nonce protected.
* HTTPS is recommended for all API calls.

== Troubleshooting ==

= Missing API Key Error =

This error usually means the selected AI provider does not have a saved API key.

Please check that you have:

* Generated an API key from your selected provider
* Entered the API key in the plugin settings
* Saved the settings successfully

= API Returned HTTP 429 Error =

This means the AI provider is rate-limiting your requests.

Possible solutions:

* Wait a few minutes and try again
* Switch to another AI provider
* Check your provider usage limit
* Upgrade your API plan if needed

= Model Not Found Error =

This usually means the selected model name is not available or has been deprecated.

Possible solutions:

* Choose a recommended model from the dropdown
* Enter a currently supported custom model name
* Switch to another AI provider

= Invalid JSON Response Error =

This means the AI provider returned an unexpected response format.

Possible solutions:

* Try the same prompt again
* Use a clearer coupon command
* Check your API key
* Try another AI provider

== Performance Tips ==

* Use Groq for faster responses.
* Use OpenAI/GPT for high-quality structured output.
* Use Gemini Flash models for fast structured JSON responses.
* Use clear prompts for more accurate coupon generation.
* Keep temperature low for more consistent results.
* Monitor provider rate limits when generating many coupons.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. ZenCoupon AI Assistant is built for WooCommerce coupon creation and management.

= Does this plugin send customer data to AI providers? =

No. The plugin sends only the admin-provided coupon prompt to the selected AI provider. It does not intentionally send customer personal data, order data, payment data, or store financial data.

= Can I use my own AI model name? =

Yes. The plugin includes curated model options and also supports custom model names.

= Can I manage existing coupons? =

Yes. The plugin supports listing, deleting, and updating coupons.

= Is the plugin HPOS compatible? =

Yes. ZenCoupon AI Assistant supports WooCommerce High-Performance Order Storage.

== Contributing ==

Contributions are welcome. Please fork the repository and submit a pull request.

== Changelog ==

= 1.0.2 =

* Improved the README structure and made the documentation easier to read.
* Cleaned up REST API examples and coupon parameter formatting.
* Clarified the external services disclosure for Groq, OpenAI, and Gemini.
* Updated plugin version metadata for the new release.

= 1.0.1 =

* Fixed unique prefix constraints and refactored the plugin structure.
* Updated the text domain to match the plugin slug.
* Added missing external services disclosure for Groq, OpenAI, and Gemini.

= 1.0.0 =

* Initial release.
* Added Groq AI integration.
* Added WooCommerce HPOS support.
* Added REST API endpoint.
* Added coupon management features.
