=== Calendly to Formidable Bridge ===
Contributors: saleemsummour
Tags: calendly, formidable, webhook, integration
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync Calendly bookings into Formidable Forms (Form ID 4) using direct webhooks and native Formidable APIs.

== Description ==
Calendly to Formidable Bridge is a production-minded WordPress plugin that receives Calendly webhook events and creates or updates Formidable entries in Form ID 4.

Features:
- Secure admin settings page under Settings -> Calendly to Formidable.
- Stores Calendly Personal Access Token and signing key in wp_options.
- Webhook management buttons for create, refresh, delete.
- REST webhook endpoint at /wp-json/ctfb/v1/webhook.
- Signature validation when signing key is configured.
- Smart mapping for essential fields only.
- Duplicate prevention using internal mapping table.
- Optional cancellation handling.
- Diagnostics and test payload tool.
- Debug logging without exposing secrets.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/ or install from a zip.
2. Activate the plugin in WordPress admin.
3. Go to Settings -> Calendly to Formidable.
4. Enter your Calendly PAT and optional signing key.
5. Save settings.
6. Click Create webhook.
7. Ensure Formidable form ID 4 and required fields exist.

== Frequently Asked Questions ==
= Does this plugin require Zapier? =
No. It uses Calendly API/webhooks and Formidable native entry creation directly.

= What fields are synced? =
Only fields 22, 23, 24, 31, 32, 73, and 26 for v1.

== Changelog ==
= 1.0.0 =
- Initial release.
