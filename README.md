# Ecommerce Reporting

Lightweight WooCommerce reporting plugin that adds aggregated daily metrics, top product performance, and basic marketing attribution (UTM tracking) directly inside wp-admin.

## Features (v1)
- Daily aggregated sales metrics (revenue, orders, refunds, AOV).
- New vs returning customer counts.
- Top products by revenue and quantity.
- Marketing attribution by UTM source/medium/campaign (no GA4 required).
- Daily revenue trend table with quick 7/30/90-day range filtering.
- Monthly customer cohort summary with repeat order and revenue totals.

## Installation
1. Copy this plugin folder into `wp-content/plugins/ecommerce-reporting`.
2. Activate **Ecommerce Reporting** in WordPress.
3. Visit **Ecommerce Reporting** in wp-admin.

## Notes
- Metrics are updated when orders move to **processing** or **completed** status.
- UTM parameters are stored from the session at checkout (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`).
- A daily aggregation job rebuilds the last 30 days of metrics to keep refunds and edits in sync.
- Marketing attribution aggregates are stored in a dedicated daily table for faster reporting.
- Cohort summaries are rebuilt daily for the last 12 months.
