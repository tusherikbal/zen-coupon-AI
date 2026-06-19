# ZenCoupon AI Assistant Review

## Plugin Overview

**ZenCoupon AI Assistant** is a WordPress and WooCommerce plugin that helps store owners create, update, and manage coupons using AI-powered natural language commands. Instead of manually configuring every WooCommerce coupon field, users can type a simple instruction such as "Create a 20% coupon for Eid sale with minimum spend 1000", and the plugin converts that command into WooCommerce-ready coupon data.

## Short Marketing Copy

ZenCoupon AI Assistant helps WooCommerce store owners create and manage coupons using simple AI-powered commands. Choose Groq, OpenAI/GPT, or Google Gemini, write what kind of discount you want, and let the plugin generate WooCommerce-ready coupons with limits, expiry dates, free shipping, category rules, and more.

## Built With

- WordPress plugin architecture
- PHP 7.4+
- WooCommerce coupon API / `WC_Coupon`
- WordPress Admin Menu and Settings API
- WordPress AJAX API
- WordPress REST API
- JSON-RPC 2.0 style MCP endpoint
- Plain JavaScript, no React/Vue/build system
- Plain CSS with custom utility classes
- External AI APIs:
  - Groq
  - OpenAI/GPT
  - Google Gemini

## Main Features

- Natural language coupon creation
- Existing coupon update/edit support
- AI provider switch: Groq, OpenAI/GPT, Gemini
- Model dropdown with custom model fallback
- API key save option
- Test Connection button
- Local Polish Prompt button
- Generated coupon list
- Recent activity panel
- Coupon delete action
- Coupon rules overview
- Docs & Support admin page
- Support form via `wp_mail()`
- WooCommerce HPOS compatibility
- REST/MCP endpoint for external integration

## Coupon Functionality

The plugin can handle these WooCommerce coupon options:

- Coupon code
- Percentage discount
- Fixed cart discount
- Fixed product discount
- Expiry date
- Minimum spend
- Maximum spend
- Usage limit
- Usage limit per user
- Individual use only
- Free shipping
- Exclude sale items
- Email restrictions
- Product IDs
- Excluded product IDs
- Product categories
- Excluded product categories

## AI Provider System

The plugin supports three AI providers:

- **Groq**: useful for fast responses and low-latency coupon commands.
- **OpenAI/GPT**: useful for high-quality structured output.
- **Google Gemini**: useful for fast structured JSON responses.

Users can select the provider, save the API key, choose a curated model, or enter a custom model name from the admin panel. Only the selected provider's settings are shown, keeping the UI clean.

## AI Bridge Architecture

The AI Bridge separates provider-specific logic into independent request builders and response parsers:

- Groq request builder and response parser
- OpenAI request builder and response parser
- Gemini request builder and response parser

All provider responses are normalized into a tool-call format:

```json
{
  "name": "create_coupon",
  "arguments": {}
}
```

For updates:

```json
{
  "name": "update_coupon",
  "arguments": {}
}
```

This architecture makes it easier to add more providers in the future, such as Claude, DeepSeek, or local AI models.

## Admin UI Features

- Dashboard stats:
  - Active coupons
  - Expiring soon
  - Highest discount
- Command Console
- Suggested prompts
- Polish Prompt
- Coupon Rules tab
- Generated Coupons tab
- AI Provider Settings
- Recent Activity
- Docs & Support page

## Security & Permission

- Direct file access blocked via `ABSPATH`
- Admin actions protected with nonce checks
- Capability checks for coupon/WooCommerce management
- Input sanitization
- Permission-protected REST endpoint
- API keys saved in WordPress options
- Support form sanitization and nonce validation

## Use Cases

- Store owners can quickly create sale coupons.
- Marketing teams can generate campaign coupons.
- Seasonal campaigns such as Black Friday, Eid, Summer Sale, or New Year can be launched faster.
- Customer-specific coupons can be created with email restrictions.
- Category-specific discount campaigns can be created.
- Free shipping campaigns can be set up quickly.
- Limited usage promo codes can be generated.

## Example Prompts

- Create a 15% discount coupon for Summer Sale.
- Create BLACKFRIDAY coupon with 30% discount.
- Create a fixed 500 discount coupon with minimum spend 3000.
- Create a free shipping coupon for VIP customers.
- Update recent coupon to 20% discount.
- Create a coupon for category ID 12, expires on 2026-12-31.

## Future Feature Ideas

1. AI Campaign Builder 

	- Coupon
	- Campaign name
	- Expiry date
	- Usage limit
	- Customer target
	- Suggested email text
	- Social post copy

2. Abandoned Cart Coupon Automation
	- Customer cart abandon করলে automatic coupon generate করে email/send করা।
3. First Order Coupon
  - New customer-এর জন্য automatic first-order coupon।
4. Customer Segment Based Coupons
    AI দিয়ে segment বানানো:
    	- VIP customers
    	- Inactive customers
    	- High-spending customers
    	- New customers
    	- Repeat buyers
    	- Category-based buyers

5. Birthday / Anniversary Coupon
  - Customer birthday বা registration anniversary অনুযায়ী coupon auto-create/send।

6. Bulk Coupon Generator
  - একসাথে 100/500/1000 unique coupon generate করার option।
Useful for:
	- Influencer campaign
	- Affiliate campaign
	- Offline flyer
	- Event campaign
	- Email marketing
	- Giveaway

7. Auto Apply Coupon
	- Customer coupon code type না করলেও condition match করলে automatic apply হবে।
8. URL Coupon / Coupon Link
	- একটা special link দিলে coupon auto apply হবে।
9. AI Email Copy Generator
	- Coupon create করার পর plugin email copy generate করবে।

10. AI Social Post Generator
	- Coupon campaign থেকে Facebook/Instagram/WhatsApp post auto-generate।
11. Analytics Features
12. AI Recommendation Features
13. Integration Ideas (crm/rest api)


## One-Line Pitch

Create smarter WooCommerce coupons in seconds using AI-powered natural language commands.

## Tagline Ideas

- AI Coupon Automation for WooCommerce
- Create WooCommerce Coupons with a Simple Prompt
- Turn Marketing Ideas into Coupons Instantly
- Smart Coupon Generation for Modern WooCommerce Stores
- AI-Powered Discounts, Built for WooCommerce

## Client-Friendly Explanation

ZenCoupon AI Assistant saves time for WooCommerce store owners. Normally, creating a WooCommerce coupon requires manually setting many fields. This plugin understands a plain-language instruction and prepares the coupon amount, type, expiry, usage limit, free shipping, category restriction, and other rules automatically. It helps launch marketing campaigns faster, reduces manual errors, and lets users choose their preferred AI provider.
