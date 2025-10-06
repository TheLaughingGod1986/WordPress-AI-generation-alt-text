# AI Alt Text Generator (GPT)

Automatically generate concise, accessible alternative text for WordPress media items using the OpenAI API. The plugin adds smart defaults, media library integrations, REST + WP-CLI support, and a configurable settings screen so editors can keep images compliant without manual busywork.

## Features
- Generate alt text automatically on image upload (optional overwrite of existing text).
- Bulk action inside the Media Library list view (`Generate Alt Text (AI)`).
- Per-image row action that calls a localized REST endpoint for instant results.
- WordPress REST route (`POST /wp-json/ai-alt/v1/generate/<id>`) secured with user capabilities + nonces.
- WP-CLI command `wp ai-alt generate --all` or `--post_id=<id>` for scripted workflows.
- Settings page under **Media → AI Alt Text (GPT)** to manage API credentials, model, tone, language, word cap, and behavior.
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
3. Activate **AI Alt Text Generator (GPT)** from the WordPress Plugins admin page.

> 💡 A `docker-compose.yml` is included to spin up a local WordPress + MySQL environment (`docker compose up`), mounting the plugin into the container for rapid testing.

## Configuration
1. Navigate to **Media → AI Alt Text (GPT)**.
2. Enter your OpenAI API key and preferred model (e.g., `gpt-4o-mini`, `gpt-4o`, `gpt-4.1-mini`).
3. Set language, tone, max words, and whether to overwrite existing alt text or auto-run on upload.
4. Save the settings. They are stored in the `ai_alt_gpt_settings` option.

### Filters
- `ai_alt_gpt_model` — adjust the model before requests are sent.
- `ai_alt_gpt_prompt` — customize the prompt builder with additional context.

## Usage
- **Upload Flow**: When enabled, new image attachments automatically trigger an alt text request.
- **Media Library**: Select images and choose `Generate Alt Text (AI)` from bulk actions. Individual items gain a `Generate Alt Text (AI)` link.
- **REST API**: Send a `POST` request to `/wp-json/ai-alt/v1/generate/<attachment_id>` with a valid nonce (`X-WP-Nonce`) from an authorized user.
- **WP-CLI**: Run `wp ai-alt generate --all` to process every image or `wp ai-alt generate --post_id=123` for a specific attachment.

All successful generations store the alt text, the source (`auto`, `bulk`, `ajax`, `wpcli`), the model used, and a timestamp as attachment meta for auditing.

## Error Handling
If the OpenAI request fails, the operation surfaces a `WP_Error` with context (status/body). Bulk and CLI commands log failures while continuing to process remaining images.

## Development
- Code is intentionally lightweight and documented inline for easy customization.
- JavaScript enhancements live in `assets/ai-alt-admin.js`; an inline fallback script ensures row actions work even if enqueued assets are blocked.
- Contributions welcome via pull request.

## License
GPL-2.0-or-later. See the GNU General Public License for more details.
