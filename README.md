# AI Alt Text Generator (GPT)

Automatically generate concise, accessible alternative text for WordPress media items using the OpenAI API. The plugin adds smart defaults, media library integrations, REST + WP-CLI support, and a configurable settings screen so editors can keep images compliant without manual busywork.

## Features
- Generate alt text automatically on image upload (optional overwrite of existing text).
- Bulk action inside the Media Library list view (`Generate Alt Text (AI)`).
- Per-image row action that calls a localized REST endpoint for instant results.
- WordPress REST routes for on-demand generation and dashboard batching (`POST /wp-json/ai-alt/v1/generate/<id>` and `/generate-missing`).
- WP-CLI command `wp ai-alt generate --all` or `--post_id=<id>` for scripted workflows.
- Settings page under **Media → AI Alt Text (GPT)** with a polished dashboard: coverage cards, progress bar, donut chart, queue controls, and quick actions for missing/all regeneration.
- Usage tab with API counters, threshold alerts, downloadable CSV audit, and per-attachment token totals.
- Optional background queue for bulky libraries (powered by WP-Cron) with an immediate first batch, manual "Run Batch Now" trigger, force-stop button, and email alerts when jobs finish or stall.
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
4. Configure tone, max words, upload behaviour, optional dry-run mode, background queue usage, and the token alert threshold (set to `0` to disable alerts). Specify a notification email for queue/token events if different from the site admin.
5. Save the settings. They are stored in the `ai_alt_gpt_settings` option.
6. Grant additional roles the `manage_ai_alt_text` capability if they should access the dashboard without full admin rights.

### Filters
- `ai_alt_gpt_model` — adjust the model before requests are sent.
- `ai_alt_gpt_prompt` — customize the prompt builder with additional context.

## Usage
- **Upload Flow**: When enabled, new image attachments automatically trigger an alt text request.
- **Media Library**: Select images and choose `Generate Alt Text (AI)` from bulk actions. Individual items gain a `Generate Alt Text (AI)` link.
- **Dashboard Actions**: On **Media → AI Alt Text (GPT)**, click **Generate ALT for Missing Images** to batch only empty fields or **Regenerate ALT for All Images** to refresh every attachment in place. When the background queue is enabled, jobs continue via WP-Cron so you can leave the page while progress updates live—use the “Run Batch Now” button to kick off the next batch instantly or “Force Stop” to cancel.
- **REST API**: Send a `POST` request to `/wp-json/ai-alt/v1/generate/<attachment_id>` with a valid nonce (`X-WP-Nonce`) from an authorized user.
- **WP-CLI**: Run `wp ai-alt generate --all` to process every image or `wp ai-alt generate --post_id=123` for a specific attachment.
- **Usage Audit / Export**: Review the "Usage Audit" table on the dashboard to see top token consumers, and download the full CSV report for finance/SEO review.
- **Dry Run Mode**: Toggle dry run in settings to capture prompts and counts without altering media—perfect for QA.

All successful generations store the alt text, the source (`auto`, `bulk`, `ajax`, `wpcli`, `dashboard`, `queue`, `dry-run`), the model used, token usage, and a timestamp as attachment meta for auditing. Site-wide token totals and request counts appear on the Usage tab for quick cost checks, and threshold/queue alerts help avoid runaway spend.

## Error Handling
If the OpenAI request fails, the operation surfaces a `WP_Error` with context (status/body). Bulk and CLI commands log failures while continuing to process remaining images.

## Development
- Code is intentionally lightweight and documented inline for easy customization.
- JavaScript enhancements live in `assets/ai-alt-admin.js`; an inline fallback script ensures row actions work even if enqueued assets are blocked.
- Contributions welcome via pull request.

## License
GPL-2.0-or-later. See the GNU General Public License for more details.
