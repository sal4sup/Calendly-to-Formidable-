# Calendly to Formidable Bridge Setup

1. Install and activate the plugin.
2. Open **Settings -> Calendly to Formidable**.
3. Set:
   - Enable sync
   - Calendly Personal Access Token
   - Optional Calendly webhook signing key
   - Fallback company and freight forwarder values
4. Save settings.
5. Click **Create webhook**.
6. Verify diagnostics:
   - Formidable dependency status
   - Form ID 4 readiness
   - Webhook subscription ID
7. Use the **Manual test payload** tool with `samples/sample-calendly-webhook.json`.

Security notes:
- Never paste real tokens into logs or screenshots.
- Rotate token and signing key if leaked.
