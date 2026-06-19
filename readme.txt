=== ZenCoupon AI Assistant ===
Contributors: tusherikbal
Tags: woocommerce, coupon, ai, automation, artificial-intelligence
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate and manage WooCommerce coupons using AI with natural language commands.

== External Services ==
This plugin connects to external AI APIs (Groq, OpenAI, and Google Gemini) to process natural language commands and generate WooCommerce coupon configurations. 

* Data Sent: User-provided natural language prompts describing coupon requirements. No user personal data, customer data, or store financial data is transmitted.
* Groq AI: Service provided by Groq, Inc. Endpoint: https://api.groq.com/openai/v1/chat/completions. [Terms of Service](https://groq.com/terms-of-service/) | [Privacy Policy](https://groq.com/privacy-policy/)
* OpenAI: Service provided by OpenAI, L.L.C. Endpoint: https://api.openai.com/v1/responses. [Terms of Use](https://openai.com/policies/terms-of-use/) | [Privacy Policy](https://openai.com/policies/privacy-policy/)
* Google Gemini: Service provided by Google LLC. Endpoint: https://generativelanguage.googleapis.com/v1beta/models/. [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

== Description ==

ZenCoupon AI Assistant is a powerful WordPress plugin that integrates with WooCommerce and AI providers to help you generate and manage coupons using simple, natural language commands.

=== Features ===

* **AI-Powered Coupon Generation:** Create coupons using natural language instructions
* **Multiple AI Providers:** Generate coupon configurations using Groq, OpenAI/GPT, or Google Gemini.
* **Easy Management:** List, delete, and manage your AI-generated coupons
* **Flexible Discount Types:** Percent or fixed amount discounts
* **Advanced Settings:** 
  * Minimum/Maximum cart amounts
  * Product and category restrictions
  * Email restrictions
  * Usage limits per user
  * Free shipping options
  * Expiration dates
  * Exclude sale items
  * Individual use settings
* **HPOS Compatible:** Full support for WooCommerce High-Performance Order Storage
* **REST API:** JSON-RPC 2.0 compatible API for integration

=== Supported AI Providers ===

**Groq AI** (Recommended for speed)
* Model: llama-3.1-8b-instant
* Fast inference with excellent accuracy

**OpenAI/GPT**
* Default model: gpt-5.5
* Low-cost model options: gpt-5.4-mini and gpt-5.4-nano

**Google Gemini**
* Default model: gemini-2.5-flash
* Flash models are useful for fast structured responses

=== Requirements ===

* WordPress 5.0 or higher
* PHP 7.4 or higher
* WooCommerce 3.0 or higher
* Active API key from Groq, OpenAI, or Google Gemini

== Installation ==

=== From WordPress Plugin Directory ===

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "ZenCoupon AI Assistant"
3. Click **Install Now** and then **Activate**

=== Manual Installation ===

1. Download the plugin as a ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

== Configuration ==

=== 1. Get API Keys ===

**For Groq AI:**
* Visit [console.groq.com](https://console.groq.com)
* Create an account or sign in
* Generate an API key

=== 2. Configure Plugin ===

1. In WordPress admin, go to **Wordpress Dashboard > ZenCoupon AI**
2. Select your AI provider
3. Enter your provider API key
4. (Optional) Change the model name
5. Click **Save Settings**

== Usage ==

=== Via REST API ===

The plugin provides a JSON-RPC 2.0 compatible REST endpoint at:
`POST /wp-json/zencoupon/v1/mcp`

#### Creating a Coupon

```bash
curl -X POST [https://yoursite.com/wp-json/zencoupon/v1/mcp](https://yoursite.com/wp-json/zencoupon/v1/mcp) \
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

  Listing Coupons:
 curl -X POST [https://yoursite.com/wp-json/zencoupon/v1/mcp](https://yoursite.com/wp-json/zencoupon/v1/mcp) \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "list_coupons"
  }'

  Listing AI-Generated Coupons:

  curl -X POST [https://yoursite.com/wp-json/zencoupon/v1/mcp](https://yoursite.com/wp-json/zencoupon/v1/mcp) \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "list_generated_coupons"
  }'

  Deleting a Coupon:
  curl -X POST [https://yoursite.com/wp-json/zencoupon/v1/mcp](https://yoursite.com/wp-json/zencoupon/v1/mcp) \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 4,
    "method": "delete_coupon",
    "params": {
      "coupon_id": 123
    }
  }'

 === Coupon Parameters ===ParameterTypeRequiredDescriptioncodestring✓Unique coupon codeamountfloat✓Discount amountdiscount_typestring✓percent, fixed_cart, or fixed_productexpiry_datestringDate in YYYY-MM-DD formatminimum_amountfloatMinimum cart totalmaximum_amountfloatMaximum cart totalexclude_sale_itemsbooleanExclude sale items from discountindividual_usebooleanUse coupon only onceusage_limitintTotal times coupon can be usedusage_limit_per_userintTimes per customerfree_shippingbooleanGrant free shippingemail_restrictionsarrayAllowed customer emailsproduct_idsarraySpecific product IDsexcluded_product_idsarrayExcluded product IDsproduct_categoriesarraySpecific category IDsexcluded_product_categoriesarrayExcluded category IDs

 == Troubleshooting ==

=== "Missing API Key" Error ===

Solution: Ensure you have:

Generated an API key from your chosen AI provider

Entered it in the plugin settings

Saved the settings

=== "API returned HTTP 429" (Rate Limited) ===

Solution:

The AI provider is rate-limiting your requests

The plugin will automatically retry with exponential backoff

If it persists, try switching AI providers or wait a few minutes

Consider upgrading your API plan with the provider

=== "Model not found" Error ===

Solution:

The model name may be deprecated

The plugin auto-updates deprecated models to current versions

Try using a currently supported Groq model name.

=== "Invalid JSON Response" Error ===

Solution:

The AI model returned unexpected format

This is usually temporary - try again

If persistent, check your API key validity

Consider using a different AI provider

== API Response Examples ==

=== Success Response ===

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

=== Error Response ===

{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32600,
    "message": "Coupon code is required."
  }
}

== Security ==

All API keys are stored encrypted in WordPress options

REST endpoint requires manage_shop_coupons or manage_woocommerce capability

Only authenticated users with proper permissions can access the API

HTTPS is recommended for all API calls

== Performance Tips ==

Use Groq AI for faster responses, OpenAI/GPT for high-quality structured output, or Gemini Flash models for fast structured responses

Set lower temperatures (0.0-0.3) for more consistent results

Batch operations when creating multiple coupons

Monitor rate limits and adjust request frequency

== Contributing ==

Contributions are welcome! Please fork the repository and submit a pull request.

== Changelog ==

=== 1.0.1 ===

Fixed unique prefix constraints and refactored plugin structure.

Updated text domain to match plugin slug.

Added missing external services disclosure for Groq, OpenAI, and Gemini.

=== 1.0.0 ===

Initial release

Groq AI integration

WooCommerce HPOS support

REST API endpoint

Coupon management features
