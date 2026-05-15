# ZenCoupon AI Assistant

**Contributors:** Tusher Ikbal  
**Tags:** woocommerce, coupon, ai, automation, artificial-intelligence  
**Requires at least:** 5.0  
**Tested up to:** 6.9  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.1  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Generate and manage WooCommerce coupons using AI with natural language commands.

## Description

ZenCoupon AI Assistant is a powerful WordPress plugin that integrates with WooCommerce and AI providers to help you generate and manage coupons using simple, natural language commands.

### Features

- **AI-Powered Coupon Generation:** Create coupons using natural language instructions
- **Multiple AI Providers:** Support for Groq and Google Gemini AI, Currently working with Groq AI only, others will added soon
- **Easy Management:** List, delete, and manage your AI-generated coupons
- **Flexible Discount Types:** Percent or fixed amount discounts
- **Advanced Settings:** 
  - Minimum/Maximum cart amounts
  - Product and category restrictions
  - Email restrictions
  - Usage limits per user
  - Free shipping options
  - Expiration dates
  - Exclude sale items
  - Individual use settings

- **HPOS Compatible:** Full support for WooCommerce High-Performance Order Storage
- **REST API:** JSON-RPC 2.0 compatible API for integration

### Supported AI Providers

  **Groq AI** (Recommended for speed)
   - Model: llama3-8b-8192
   - Fast inference with excellent accuracy



### Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WooCommerce 3.0 or higher
- Active API key from Groq or Google Gemini

## Installation

### From WordPress Plugin Directory

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "ZenCoupon AI Assistant"
3. Click **Install Now** and then **Activate**

### Manual Installation

1. Download the plugin as a ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**


## Configuration

### 1. Get API Keys

**For Groq AI:**
- Visit [console.groq.com](https://console.groq.com)
- Create an account or sign in
- Generate an API key


### 2. Configure Plugin

1. In WordPress admin, go to **Wordpress Dashboard > ZenCoupon AI**
2. Select your preferred AI provider (Groq or Gemini)
3. Enter your API key
4. (Optional) Change the model name
5. Click **Save Settings**

## Usage

### Via REST API

The plugin provides a JSON-RPC 2.0 compatible REST endpoint at:
```
POST /wp-json/zencoupon/v1/mcp
```

#### Creating a Coupon

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
      "expiry_date": "2025-12-31"
    }
  }'
```

#### Listing Coupons

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "list_coupons"
  }'
```

#### Listing AI-Generated Coupons

```bash
curl -X POST https://yoursite.com/wp-json/zencoupon/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "list_generated_coupons"
  }'
```

#### Deleting a Coupon

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

### Coupon Parameters

When creating a coupon, you can use these parameters:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `code` | string | ✓ | Unique coupon code |
| `amount` | float | ✓ | Discount amount |
| `discount_type` | string | ✓ | `percent`, `fixed_cart`, or `fixed_product` |
| `expiry_date` | string | | Date in YYYY-MM-DD format |
| `minimum_amount` | float | | Minimum cart total |
| `maximum_amount` | float | | Maximum cart total |
| `exclude_sale_items` | boolean | | Exclude sale items from discount |
| `individual_use` | boolean | | Use coupon only once |
| `usage_limit` | int | | Total times coupon can be used |
| `usage_limit_per_user` | int | | Times per customer |
| `free_shipping` | boolean | | Grant free shipping |
| `email_restrictions` | array | | Allowed customer emails |
| `product_ids` | array | | Specific product IDs |
| `excluded_product_ids` | array | | Excluded product IDs |
| `product_categories` | array | | Specific category IDs |
| `excluded_product_categories` | array | | Excluded category IDs |

## Troubleshooting

### "Missing API Key" Error

**Solution:** Ensure you have:
1. Generated an API key from your chosen AI provider
2. Entered it in the plugin settings
3. Saved the settings

### "API returned HTTP 429" (Rate Limited)

**Solution:** 
- The AI provider is rate-limiting your requests
- The plugin will automatically retry with exponential backoff
- If it persists, try switching AI providers or wait a few minutes
- Consider upgrading your API plan with the provider

### "Model not found" Error

**Solution:**
- The model name may be deprecated
- The plugin auto-updates deprecated models to current versions
- Try switching between Groq and Gemini to find a working option

### "Invalid JSON Response" Error

**Solution:**
- The AI model returned unexpected format
- This is usually temporary - try again
- If persistent, check your API key validity
- Consider using a different AI provider

## API Response Examples

### Success Response

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

### Error Response

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

## Security

- All API keys are stored encrypted in WordPress options
- REST endpoint requires `manage_shop_coupons` or `manage_woocommerce` capability
- Only authenticated users with proper permissions can access the API
- HTTPS is recommended for all API calls

## Performance Tips

1. **Use Groq AI** for faster responses (recommended)
2. **Set lower temperatures** (0.0-0.3) for more consistent results
3. **Batch operations** when creating multiple coupons
4. **Monitor rate limits** and adjust request frequency

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## Changelog

### 1.0.0
- Initial release
- Groq AI integration
- WooCommerce HPOS support
- REST API endpoint
- Coupon management features

## License

This plugin is licensed under the GPL-2.0-or-later license. See the LICENSE file for details.

## Credits

- Built with ❤️ for WooCommerce users
- AI integrations: Groq & Google Gemini
- WordPress & WooCommerce community

---

**Made with ❤️ for WordPress & WooCommerce Communities**
