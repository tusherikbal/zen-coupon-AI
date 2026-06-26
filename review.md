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


## Use Cases

- Store owners can quickly create sale coupons.
- Marketing teams can generate campaign coupons.
- Seasonal campaigns such as Black Friday, Eid, Summer Sale, or New Year can be launched faster.
- Customer-specific coupons can be created with email restrictions.
- Category-specific discount campaigns can be created.
- Free shipping campaigns can be set up quickly.
- Limited usage promo codes can be generated.


## Features

