<?php
/**
 * Plugin Name: Farlo AI Alt Text Generator (GPT)
 * Description: Automatically generates concise, accessible ALT text for images using the OpenAI API. Includes auto-on-upload, Media Library bulk action, REST + WP-CLI, and a settings page.
 * Version: 1.0.0
 * Author: Ben O
 * License: GPL2
 */

if (!defined('ABSPATH')) { exit; }

class AI_Alt_Text_Generator_GPT {
    const OPTION_KEY = 'ai_alt_gpt_settings';
    const NONCE_KEY  = 'ai_alt_gpt_nonce';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_attachment', [$this, 'maybe_generate_on_upload'], 20);

        add_filter('bulk_actions-upload', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);

        add_filter('media_row_actions', [$this, 'row_action_link'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields_to_edit'], 15, 2);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('ai-alt', [$this, 'wpcli_command']);
        }
    }

    public function activate() {
        $defaults = [
            'api_key'          => '',
            'model'            => 'gpt-4o-mini',
            'max_words'        => 16,
            'language'         => 'en',
            'enable_on_upload' => true,
            'tone'             => 'professional, accessible',
            'force_overwrite'  => false,
        ];
        $existing = get_option(self::OPTION_KEY, []);
        update_option(self::OPTION_KEY, wp_parse_args($existing, $defaults), false);
    }

    public function add_settings_page() {
        add_media_page(
            'AI Alt Text (GPT)',
            'AI Alt Text (GPT)',
            'manage_options',
            'ai-alt-gpt',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('ai_alt_gpt_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => function($input){
                $out = [];
                $out['api_key']          = sanitize_text_field($input['api_key'] ?? '');
                $out['model']            = sanitize_text_field($input['model'] ?? 'gpt-4o-mini');
                $out['max_words']        = max(4, intval($input['max_words'] ?? 16));
                $out['language']         = sanitize_text_field($input['language'] ?? 'en');
                $out['enable_on_upload'] = !empty($input['enable_on_upload']);
                $out['tone']             = sanitize_text_field($input['tone'] ?? 'professional, accessible');
                $out['force_overwrite']  = !empty($input['force_overwrite']);
                return $out;
            }
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $opts = get_option(self::OPTION_KEY, []);
        $nonce = wp_create_nonce(self::NONCE_KEY);
        $has_key = !empty($opts['api_key']);
        ?>
        <div class="wrap">
            <h1>Farlo AI Alt Text Generator (GPT)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ai_alt_gpt_group'); ?>
                <?php $o = wp_parse_args($opts, []); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_key">OpenAI API Key</label></th>
                        <td>
                            <input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]" id="api_key" value="<?php echo esc_attr($o['api_key'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Stored in wp_options. Ensure only trusted admins have access.</p>
                            <?php if ($has_key) : ?>
                                <div class="notice notice-success inline"><p><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php echo esc_html__('Connected. Key saved in settings.', 'ai-alt-gpt'); ?></p></div>
                            <?php else : ?>
                                <div class="notice notice-warning inline"><p><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html__('Enter an OpenAI API key to enable generation.', 'ai-alt-gpt'); ?></p></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="model">Model</label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[model]" id="model" value="<?php echo esc_attr($o['model'] ?? 'gpt-4o-mini'); ?>" class="regular-text" />
                            <p class="description">e.g. gpt-4o-mini, gpt-4o, gpt-4.1-mini. Filterable via <code>ai_alt_gpt_model</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_words">Max words</label></th>
                        <td>
                            <input type="number" min="4" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_words]" id="max_words" value="<?php echo esc_attr($o['max_words'] ?? 16); ?>" />
                            <p class="description">Keep ALT short and descriptive (recommended 8–16 words).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="language">Language</label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[language]" id="language" value="<?php echo esc_attr($o['language'] ?? 'en'); ?>" />
                            <p class="description">ISO code or language name (e.g. en, fr, de, es).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Behaviour</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_on_upload]" <?php checked(!empty($o['enable_on_upload'])); ?>/> Generate on upload</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[force_overwrite]" <?php checked(!empty($o['force_overwrite'])); ?>/> Overwrite existing ALT text</label><br>
                            <input type="hidden" id="ai_alt_gpt_nonce" value="<?php echo esc_attr($nonce); ?>"/>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tone">Tone / Style</label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tone]" id="tone" value="<?php echo esc_attr($o['tone'] ?? 'professional, accessible'); ?>" class="regular-text" />
                            <p class="description">e.g. "professional, accessible" or "friendly, concise".</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr/>
            <h2>Bulk Generate</h2>
            <p>Use the Media Library bulk action “Generate Alt Text (AI)” or run via WP-CLI: <code>wp ai-alt generate --all</code></p>
        </div>
        <?php
    }

    private function build_prompt($attachment_id, $opts){
        $file     = get_attached_file($attachment_id);
        $filename = $file ? wp_basename($file) : get_the_title($attachment_id);
        $title    = get_the_title($attachment_id);
        $caption  = wp_get_attachment_caption($attachment_id);
        $parent   = get_post_field('post_title', wp_get_post_parent_id($attachment_id));
        $lang     = $opts['language'] ?? 'en';
        $tone     = $opts['tone'] ?? 'professional, accessible';
        $max      = max(4, intval($opts['max_words'] ?? 16));

        $context_bits = array_filter([$title, $caption, $parent]);
        $context = $context_bits ? ("Context: " . implode(' | ', $context_bits)) : '';

        $prompt = "Write concise, descriptive ALT text in {$lang} for an image. "
               . "Limit to {$max} words. Tone: {$tone}. "
               . "Avoid phrases like 'image of' or 'photo of'. "
               . "Prefer proper nouns if present. Return only the ALT text.\n"
               . "Filename: {$filename}\n{$context}";
        return apply_filters('ai_alt_gpt_prompt', $prompt, $attachment_id, $opts);
    }

    private function is_image($attachment_id){
        $mime = get_post_mime_type($attachment_id);
        return strpos((string)$mime, 'image/') === 0;
    }

    public function maybe_generate_on_upload($attachment_id){
        $opts = get_option(self::OPTION_KEY, []);
        if (empty($opts['enable_on_upload'])) return;
        if (!$this->is_image($attachment_id)) return;
        $existing = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($existing && empty($opts['force_overwrite'])) return;
        $this->generate_and_save($attachment_id, 'auto');
    }

    public function generate_and_save($attachment_id, $source='manual'){
        $opts = get_option(self::OPTION_KEY, []);
        $api_key = $opts['api_key'] ?? '';
        if (!$api_key) return new \WP_Error('no_api_key', 'OpenAI API key missing.');
        if (!$this->is_image($attachment_id)) return new \WP_Error('not_image', 'Attachment is not an image.');

        $model = apply_filters('ai_alt_gpt_model', $opts['model'] ?? 'gpt-4o-mini', $attachment_id, $opts);
        $prompt = $this->build_prompt($attachment_id, $opts);

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You write short, descriptive, accessible image ALT text.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens'  => 80,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 300 || empty($data['choices'][0]['message']['content'])) {
            return new \WP_Error('api_error', 'OpenAI API error', ['status' => $code, 'body' => $data]);
        }

        $alt = trim($data['choices'][0]['message']['content']);
        $alt = preg_replace('/^[\"\\' . "'" . ']+|[\"\\' . "'" . ']+$/', '', $alt);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));

        update_post_meta($attachment_id, '_ai_alt_source', $source);
        update_post_meta($attachment_id, '_ai_alt_model', $model);
        update_post_meta($attachment_id, '_ai_alt_generated_at', current_time('mysql'));

        return $alt;
    }

    public function register_bulk_action($bulk_actions){
        $bulk_actions['ai_alt_generate'] = __('Generate Alt Text (AI)', 'ai-alt-gpt');
        return $bulk_actions;
    }

    public function handle_bulk_action($redirect_to, $doaction, $post_ids){
        if ($doaction !== 'ai_alt_generate') return $redirect_to;
        $count = 0; $errors = 0;
        foreach ($post_ids as $id){
            $res = $this->generate_and_save($id, 'bulk');
            if (is_wp_error($res)) { $errors++; } else { $count++; }
        }
        $redirect_to = add_query_arg(['ai_alt_generated' => $count, 'ai_alt_errors' => $errors], $redirect_to);
        return $redirect_to;
    }

    public function row_action_link($actions, $post){
        if ($post->post_type === 'attachment' && $this->is_image($post->ID)){
            $actions['ai_alt_generate_single'] = '<a href="#" class="ai-alt-generate" data-id="' . intval($post->ID) . '">Generate Alt Text (AI)</a>';
        }
        return $actions;
    }

    public function attachment_fields_to_edit($fields, $post){
        if (!$this->is_image($post->ID)){
            return $fields;
        }

        $button = sprintf(
            '<button type="button" class="button ai-alt-generate" data-id="%1$d">%2$s</button>',
            intval($post->ID),
            esc_html__('Generate Alt', 'ai-alt-gpt')
        );

        $fields['ai_alt_generate'] = [
            'label' => __('AI Alt Text', 'ai-alt-gpt'),
            'input' => 'html',
            'html'  => $button . '<p class="description">' . esc_html__('Use AI to suggest alternative text for this image.', 'ai-alt-gpt') . '</p>',
        ];

        return $fields;
    }

    public function register_rest_routes(){
        register_rest_route('ai-alt/v1', '/generate/(?P<id>\d+)', [
            'methods'  => 'POST',
            'callback' => function($req){
                if (!current_user_can('upload_files')) return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                $id = intval($req['id']);
                $alt = $this->generate_and_save($id, 'ajax');
                if (is_wp_error($alt)) return $alt;
                return ['id' => $id, 'alt' => $alt];
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public function enqueue_admin($hook){
        if ($hook !== 'upload.php') return;
        wp_enqueue_script('ai-alt-gpt-admin', plugin_dir_url(__FILE__) . 'assets/ai-alt-admin.js', ['jquery'], '1.0.0', true);
        wp_localize_script('ai-alt-gpt-admin', 'AI_ALT_GPT', [
            'nonce' => wp_create_nonce(self::NONCE_KEY),
            'rest'  => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
        ]);
    }

    public function wpcli_command($args, $assoc){
        if (!class_exists('WP_CLI')) return;
        $all = isset($assoc['all']);
        $id  = isset($assoc['post_id']) ? intval($assoc['post_id']) : 0;
        if (!$all && !$id){
            \WP_CLI::error('Provide --all or --post_id=123');
        }
        $q = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ($id){ $ids = [$id]; }
        else    { $ids = get_posts($q); }

        $count=0; $errors=0;
        foreach ($ids as $aid){
            $res = $this->generate_and_save($aid, 'wpcli');
            if (is_wp_error($res)) { $errors++; \WP_CLI::warning("ID $aid: " . $res->get_error_message()); }
            else { $count++; \WP_CLI::log("ID $aid: $res"); }
        }
        \WP_CLI::success("Generated ALT for $count images; $errors errors.");
    }
}

new AI_Alt_Text_Generator_GPT();

// Inline JS fallback to add row-action behaviour
add_action('admin_footer-upload.php', function(){
    ?>
    <script>
    (function($){
        function restore(btn){
            var original = btn.data('original-text');
            btn.text(original || 'Generate Alt');
            if (btn.is('button, input')){
                btn.prop('disabled', false);
            }
        }

        function updateAltField(id, value, context){
            var selectors = [
                '#attachment_alt',
                '[data-setting="alt"]',
                '[name="attachments[' + id + '][image_alt]"]'
            ];
            var field;
            selectors.some(function(sel){
                var scoped = context && context.length ? context.find(sel) : $(sel);
                if (scoped.length){
                    field = scoped.first();
                    return true;
                }
                return false;
            });
            if (field && field.length){
                field.val(value).trigger('change');
            }
        }

        $(document).on('click', '.ai-alt-generate', function(e){
            e.preventDefault();
            if (!window.AI_ALT_GPT || !AI_ALT_GPT.rest){
                return alert('AI ALT: REST URL missing.');
            }

            var btn = $(this);
            var id = btn.data('id');
            if (!id){ return alert('AI ALT: Attachment ID missing.'); }

            if (typeof btn.data('original-text') === 'undefined'){
                btn.data('original-text', btn.text());
            }

            btn.text('Generating…');
            if (btn.is('button, input')){
                btn.prop('disabled', true);
            }

            var headers = {'X-WP-Nonce': (AI_ALT_GPT.nonce || (window.wpApiSettings ? wpApiSettings.nonce : ''))};
            var context = btn.closest('.compat-item, .attachment-details, .media-modal');

            fetch(AI_ALT_GPT.rest + id, { method:'POST', headers: headers })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data && data.alt){
                        updateAltField(id, data.alt, context);
                        alert('ALT generated: ' + data.alt);
                        if (!context.length){
                            location.reload();
                        }
                    } else {
                        alert('Failed to generate ALT');
                    }
                })
                .catch(function(){ alert('Request failed.'); })
                .then(function(){ restore(btn); });
        });
    })(jQuery);
    </script>
    <?php
});
