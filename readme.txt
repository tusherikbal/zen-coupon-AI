=== ZenCoupon AI Assistant ===
Contributors: tusherikbal
Tags: woocommerce, coupon, ai, automation, artificial-intelligence
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate and manage WooCommerce coupons using AI with natural language commands.

== Description ==

ZenCoupon AI Assistant is a powerful WordPress plugin that integrates with WooCommerce and AI providers to help you generate and manage coupons using simple, natural language commands.

=== Features ===

* **AI-Powered Coupon Generation:** Create coupons using natural language instructions
* **Multiple AI Providers:** Support for Groq and Google Gemini AI. Currently working with Groq AI only, others will be added soon.
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

* **Groq AI (Recommended for speed)**
  * Model: llama3-8b-8192
  * Fast inference with excellent accuracy

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
2. Select your preferred AI provider (Groq or Gemini)
3. Enter your API key
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