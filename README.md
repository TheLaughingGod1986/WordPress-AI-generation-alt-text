# Farlo AI Alt Text Generator (GPT)

Automatically generate concise, accessible ALT text for your WordPress images using OpenAI's GPT models. Improve accessibility and SEO with AI-powered image descriptions.

## Features

### 🤖 Intelligent ALT Text Generation
- **Automatic on Upload** - Generate ALT text when images are uploaded
- **Bulk Processing** - Handle multiple images at once via Media Library
- **Manual Control** - Generate or regenerate for individual images
- **Smart Context** - Uses image filename, title, caption, and parent post for better descriptions

### 📊 Comprehensive Dashboard
- **Coverage Tracking** - Visual charts showing ALT text coverage across your media library
- **Usage Metrics** - Monitor API requests, token usage, and generation history
- **Quality Scoring** - Automated QA review of generated descriptions
- **ALT Library** - Review, filter, and manage all your ALT text in one place

### 🎨 Modern Interface
- **Intuitive Design** - Clean, professional interface with accessibility features
- **Real-time Updates** - Live progress tracking for batch operations
- **Interactive Charts** - Visual coverage indicators and statistics
- **Mobile Responsive** - Works seamlessly on all devices

### 🔧 Developer-Friendly
- **REST API** - Integrate ALT generation into your workflows
- **WP-CLI Support** - Command-line tools for bulk operations
- **Hooks & Filters** - Customize prompts and behavior
- **Dry Run Mode** - Test configurations without updating images

### ⚙️ Flexible Configuration
- **Model Selection** - Choose between gpt-4o-mini, gpt-4o, or gpt-4.1-mini
- **Language Support** - Generate ALT text in any language
- **Tone Control** - Set the writing style (professional, friendly, concise, etc.)
- **Word Limit** - Control description length (recommended: 8-16 words)
- **Custom Prompts** - Add your own instructions to every generation

### 📈 Usage Controls
- **Token Alerts** - Get notified when approaching usage limits
- **Usage Audit** - Detailed CSV export of all API usage
- **Source Tracking** - Know how each ALT text was generated (auto, bulk, manual, etc.)
- **Recent Activity** - View and regenerate recently processed images

## Installation

1. Upload the plugin files to `/wp-content/plugins/ai-alt-gpt/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Media → AI ALT Text** to configure your OpenAI API key
4. Start generating accessible ALT text!

## Configuration

### Getting Started

1. **Obtain an OpenAI API Key**
   - Visit [OpenAI Platform](https://platform.openai.com/api-keys)
   - Create a new API key
   - Copy the key (you won't be able to see it again)

2. **Configure the Plugin**
   - Go to **Media → AI ALT Text → Settings**
   - Paste your API key
   - Select your preferred model (gpt-4o-mini recommended for best cost/quality balance)
   - Set language and tone preferences
   - Save settings

3. **Generate ALT Text**
   - **Automatic**: Enable "Generate on upload" in settings
   - **Bulk**: Select images in Media Library → Bulk Actions → "Generate Alt Text (AI)"
   - **Individual**: Click "Generate Alt Text (AI)" on any image
   - **Dashboard**: Use quick actions to process missing or all images

## Usage

### Dashboard Quick Actions

Access the dashboard at **Media → AI ALT Text**:

- **Generate ALT for Missing Images** - Processes only images without ALT text
- **Regenerate ALT for All Images** - Processes entire media library (use with caution)
- **View Coverage** - Visual chart showing your ALT text coverage percentage
- **Recent Activity** - See recently generated ALT text with regenerate options

### ALT Library

Review and manage all generated ALT text:

- **Filter by Quality** - Show healthy, needs review, or critical entries
- **Search** - Find images by title or ALT text
- **Quality Scores** - Automated QA ratings (0-100) with improvement suggestions
- **One-Click Regenerate** - Instantly regenerate any description
- **Bulk Actions** - Process multiple images at once

### Media Library Integration

- **Row Actions** - "Generate Alt Text (AI)" link on each image
- **Bulk Actions** - Select multiple images and generate in one click
- **Edit Modal** - Generate or regenerate from the attachment details screen

### WP-CLI Commands

```bash
# Generate ALT for all images
wp ai-alt generate --all

# Generate only for missing ALT
wp ai-alt generate --missing

# Dry run (test without saving)
wp ai-alt generate --all --dry-run

# Get stats
wp ai-alt stats
```

## Settings

### OpenAI Connection
- **API Key**: Your OpenAI API key
- **Model**: gpt-4o-mini (recommended), gpt-4o, or gpt-4.1-mini

### Generation Defaults
- **Word Limit**: Target length (4-30 words, recommended: 8-16)
- **Tone/Style**: Overall voice (e.g., "professional, accessible")
- **Language**: en-GB, en, or custom (any language/locale)
- **Custom Prompt**: Additional instructions prepended to every request

### Automation
- **Generate on Upload**: Automatically create ALT text for new images
- **Overwrite Existing**: Replace existing ALT text when regenerating
- **Dry Run Mode**: Log prompts without updating ALT text (for testing)

### Alerts & Reporting
- **Token Alert Threshold**: Get notified when usage exceeds this limit
- **Alert Email**: Where to send notifications (defaults to admin email)

## Best Practices

### For Accessibility
- Review generated ALT text before publishing
- Keep descriptions concise (8-16 words ideal)
- Describe what's visible, not what's implied
- Avoid phrases like "image of" or "photo of"

### For Quality
- Use the ALT Library to review automated QA scores
- Regenerate descriptions with low quality scores
- Customize tone and prompts for your brand voice
- Test settings in dry run mode first

### For Cost Control
- Use gpt-4o-mini for most use cases (excellent quality, low cost)
- Set token alert thresholds
- Monitor usage in the Usage & Reports tab
- Enable "Generate on upload" only if needed

## Privacy & Security

- **API Key Storage**: Keys are stored in WordPress options table
- **Data Transmission**: Only image metadata (filename, title, caption) sent to OpenAI
- **No Image Upload**: Images themselves are never sent to OpenAI
- **User Permissions**: Only administrators can manage settings by default
- **Custom Capability**: `manage_ai_alt_text` for granular permission control

## Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **OpenAI API Key**: Active account with available credits
- **Permissions**: Administrator or custom `manage_ai_alt_text` capability

## Frequently Asked Questions

**Q: How much does it cost?**  
A: Costs depend on OpenAI pricing. Using gpt-4o-mini, expect ~$0.001-0.003 per image. Monitor usage in the dashboard.

**Q: Can I use this for existing images?**  
A: Yes! Use bulk actions or the dashboard quick actions to process your entire media library.

**Q: What if I don't like the generated ALT text?**  
A: Simply click "Regenerate" for a different description, or manually edit the ALT text field.

**Q: Is my OpenAI API key secure?**  
A: Yes, it's stored in your WordPress database and only accessible to administrators.

**Q: Can I generate ALT text in languages other than English?**  
A: Absolutely! Set your preferred language in Settings (supports any language).

**Q: Will this work with page builders?**  
A: Yes, the plugin updates the standard WordPress ALT text field used by all themes and page builders.

**Q: Can I customize the prompts?**  
A: Yes, use the "Additional instructions" field in Settings to add custom requirements.

## Support

For support, feature requests, or bug reports:
- **Documentation**: Review this README and in-app help text
- **Dashboard**: Check the "How to Use" tab for guidance
- **OpenAI Status**: Check [status.openai.com](https://status.openai.com) for API issues

## Changelog

See `CHANGELOG.md` for version history and updates.

## License

GPL-2.0-or-later

## Credits

Developed by **Farlo** with ❤️ for the WordPress community.

---

**Make your site more accessible, one image at a time.** 🌟
