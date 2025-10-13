# AI Alt Text Generator (GPT)

Automatically generate concise, accessible alternative text for WordPress media items using the OpenAI API. The plugin adds smart defaults, media library integrations, REST + WP-CLI support, and a configurable settings screen so editors can keep images compliant without manual busywork.

## Quick Start
1. Install/activate the plugin in `wp-content/plugins/ai-alt-gpt`.
2. Visit **Media → AI Alt Text (GPT)**, paste your OpenAI API key, and hit **Save Settings**.
3. Toggle “Generate on upload” (and optionally “Overwrite existing ALT text”) to automate new media.
4. Use the per-image **Generate Alt** button (row action or attachment sidebar) to refresh any existing images.
5. Monitor the dashboard coverage cards and the “Recently Generated” panel to confirm progress, and review token usage on the Usage tab. Use the one-click “Generate ALT for Missing Images” button for a supervised pass that processes each item sequentially.

## Why this plugin?
- **Editorial guardrails**: keep content compliant without forcing writers to learn prompt engineering.
- **Production-friendly**: usage alerts, Media Library bulk actions, and REST/CLI access fit real editorial workflows.
- **Extensible foundation**: REST, WP-CLI, and filters make it easy to wire into bespoke review flows.

## Features
- Generate alt text automatically on image upload (optional overwrite of existing text).
- Bulk action inside the Media Library list view (`Generate Alt Text (AI)`).
- Per-image row action that calls a localized REST endpoint for instant results.
- WordPress REST route for on-demand generation (`POST /wp-json/ai-alt/v1/generate/<id>`), now including the image itself so GPT can describe actual visual content.
- WP-CLI command `wp ai-alt generate --post_id=<id>` for scripted workflows.
- Settings page under **Media → AI Alt Text (GPT)** with a polished dashboard: coverage cards, progress bar, donut chart, per-image quick actions, plus a “Recently Generated” gallery with thumbnails.
- Usage tab with API counters, threshold alerts, downloadable CSV audit, and per-attachment token totals.
- Token usage tracking with configurable alert threshold, dry-run mode for auditing prompts, and downloadable CSV for finance/SEO reviews.
- Language presets (defaults to English UK; US/custom locales available), tone control, and overwrite toggles.
- Custom capability `manage_ai_alt_text` so you can delegate access without granting full `manage_options`.
- Extensible via filters like `ai_alt_gpt_model` and `ai_alt_gpt_prompt`.

## Requirements
- WordPress 6.0+ (tested with latest core).
- PHP 7.4+.
- OpenAI API key with access to the chosen model (default `gpt-4o-mini`).

## Installation
1. Clone or download this repository into `wp-content/plugins/ai-alt-gpt`.
2. Ensure the plugin files live at:
   - `wp-content/plugins/ai-alt-gpt/ai-alt-gpt.php`
   - `wp-content/plugins/ai-alt-gpt/assets/ai-alt-admin.js`
   - `wp-content/plugins/ai-alt-gpt/assets/ai-alt-dashboard.js`
   - `wp-content/plugins/ai-alt-gpt/assets/ai-alt-dashboard.css`
3. Activate **AI Alt Text Generator (GPT)** from the WordPress Plugins admin page.

## Configuration
1. Navigate to **Media → AI Alt Text (GPT)**.
2. Enter your OpenAI API key and preferred model (e.g., `gpt-4o-mini`, `gpt-4o`, `gpt-4.1-mini`).
3. Choose a language preset (defaults to English UK; switch to **English (US)** or **Custom…** for other locales).
4. Configure tone, max words, upload behaviour, optional dry-run mode, and the token alert threshold (set to `0` to disable alerts). Specify a notification email for token events if different from the site admin.
5. Save the settings. They are stored in the `ai_alt_gpt_settings` option.
6. Grant additional roles the `manage_ai_alt_text` capability if they should access the dashboard without full admin rights.

### Filters
- `ai_alt_gpt_model` — adjust the model before requests are sent.
- `ai_alt_gpt_prompt` — customize the prompt builder with additional context.

```php
add_filter('ai_alt_gpt_model', function($model){
    return defined('WP_DEBUG') && WP_DEBUG ? 'gpt-4o-mini' : 'gpt-4.1-mini';
});

add_filter('ai_alt_gpt_prompt', function($prompt, $attachment_id){
    $keywords = get_post_meta($attachment_id, '_seo_focus_keywords', true);
    return $keywords ? "Focus on: {$keywords}\n\n" . $prompt : $prompt;
}, 10, 2);
```

## Usage
- **Upload Flow**: When enabled, new image attachments automatically trigger an alt text request.
- **Media Library**: Select images and choose `Generate Alt Text (AI)` from bulk actions. Individual items gain a `Generate Alt Text (AI)` link.
- **Dashboard Overview**: Use the coverage cards and audit table on **Media → AI Alt Text (GPT)** to spot gaps, then open each attachment (or use the row action) to refresh its ALT text.
- **REST API**: Send a `POST` request to `/wp-json/ai-alt/v1/generate/<attachment_id>` with a valid nonce (`X-WP-Nonce`) from an authorized user.
- **WP-CLI**: Run `wp ai-alt generate --post_id=123` for a specific attachment.
- **Usage Audit / Export**: Review the "Usage Audit" table on the dashboard to see top token consumers, and download the full CSV report for finance/SEO review.
- **Dry Run Mode**: Toggle dry run in settings to capture prompts and counts without altering media—perfect for QA.

### Automation examples
- **Missing-only sweep**:
  ```bash
  wp media list --fields=ID --format=ids \
    --meta_key=_wp_attachment_image_alt --meta_compare='=' --meta_value='' \
    | tr ' ' '\n' \
    | while read id; do wp ai-alt generate --post_id="$id"; done
  ```

## Operations & Limits
- **Token & rate limits**: Large libraries can hit OpenAI rate ceilings. Spread manual requests over time or run smaller CLI loops to stay within limits.
- **Manual cadence**: Because generation now happens per attachment, plan review sessions (or scripted loops) that mirror your editorial workflow instead of long unattended batches.
- **Costs**: Track token totals in the Usage tab and set an alert threshold to receive email notices before budgets are exceeded.

All successful generations store the alt text, the source (`auto`, `bulk`, `ajax`, `wpcli`, `dashboard`, `dry-run`), the model used, token usage, and a timestamp as attachment meta for auditing. Site-wide token totals and request counts appear on the Usage tab for quick cost checks, and threshold alerts help avoid runaway spend.

## Error Handling
If the OpenAI request fails, the operation surfaces a `WP_Error` with context (status/body). Bulk and CLI commands log failures while continuing to process remaining images.

## Troubleshooting
- **No ALT text generated**: Confirm the API key is valid and the selected model is available to your account; dry-run mode will intentionally skip writes.
- **Rate-limit errors**: Pause between manual regenerations or throttle CLI loops. Persistent `429` responses usually clear within a minute.
- **Capability denied**: Grant trusted roles the `manage_ai_alt_text` capability, or fall back to `manage_options` if only administrators should access the dashboard.

## Development
- Code is intentionally lightweight and documented inline for easy customization.
- JavaScript enhancements live in `assets/ai-alt-admin.js`; an inline fallback script ensures row actions work even if enqueued assets are blocked.
- Contributions welcome via pull request.

## License
GPL-2.0-or-later. See the GNU General Public License for more details.
