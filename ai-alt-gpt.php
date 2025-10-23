<?php
/**
 * Plugin Name: Farlo AI Alt Text Generator (GPT)
 * Description: Automatically generates concise, accessible ALT text for images using the OpenAI API. Includes auto-on-upload, Media Library bulk action, REST + WP-CLI, and a settings page.
 * Version: 3.0.0
 * Author: Farlo
 * Author URI: https://farlo.co
 * Plugin URI: https://farlo.co/ai-alt-gpt
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-alt-gpt
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class AI_Alt_Text_Generator_GPT {
    const OPTION_KEY = 'ai_alt_gpt_settings';
    const NONCE_KEY  = 'ai_alt_gpt_nonce';
    const CAPABILITY = 'manage_ai_alt_text';

    private const QA_MAX_RETRY = 1;
    private const QA_RETRY_THRESHOLD = 70;

    private $stats_cache = null;
    private $token_notice = null;

    private function user_can_manage(){
        return current_user_can(self::CAPABILITY) || current_user_can('manage_options');
    }

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_attachment', [$this, 'maybe_generate_on_upload'], 20);

        add_filter('bulk_actions-upload', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);

        add_filter('media_row_actions', [$this, 'row_action_link'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields_to_edit'], 15, 2);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('admin_init', [$this, 'maybe_display_threshold_notice']);
        add_action('admin_post_ai_alt_usage_export', [$this, 'handle_usage_export']);
        add_action('init', [$this, 'ensure_capability']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('ai-alt', [$this, 'wpcli_command']);
        }
    }

    private function default_usage(){
        return [
            'prompt'      => 0,
            'completion'  => 0,
            'total'       => 0,
            'requests'    => 0,
            'last_request'=> null,
        ];
    }

    private function record_usage($usage){
        $prompt     = isset($usage['prompt']) ? max(0, intval($usage['prompt'])) : 0;
        $completion = isset($usage['completion']) ? max(0, intval($usage['completion'])) : 0;
        $total      = isset($usage['total']) ? max(0, intval($usage['total'])) : ($prompt + $completion);

        if (!$prompt && !$completion && !$total){
            return;
        }

        $opts = get_option(self::OPTION_KEY, []);
        $current = $opts['usage'] ?? $this->default_usage();
        $current['prompt']     += $prompt;
        $current['completion'] += $completion;
        $current['total']      += $total;
        $current['requests']   += 1;
        $current['last_request'] = current_time('mysql');

        $opts['usage'] = $current;
        $opts['token_alert_sent'] = $opts['token_alert_sent'] ?? false;
        $opts['token_limit'] = $opts['token_limit'] ?? 0;

        if (!empty($opts['token_limit']) && !$opts['token_alert_sent'] && $current['total'] >= $opts['token_limit']){
            $opts['token_alert_sent'] = true;
            set_transient('ai_alt_gpt_token_notice', [
                'total' => $current['total'],
                'limit' => $opts['token_limit'],
            ], DAY_IN_SECONDS);
            $this->send_notification(
                __('AI Alt Text token usage alert', 'ai-alt-gpt'),
                sprintf(
                    __('Cumulative token usage has reached %1$d (threshold %2$d). Consider reviewing your OpenAI usage.', 'ai-alt-gpt'),
                    $current['total'],
                    $opts['token_limit']
                )
            );
        }

        update_option(self::OPTION_KEY, $opts, false);
        $this->stats_cache = null;
    }

    private function send_notification($subject, $message){
        $opts = get_option(self::OPTION_KEY, []);
        $email = $opts['notify_email'] ?? get_option('admin_email');
        $email = is_email($email) ? $email : get_option('admin_email');
        if (!$email){
            return;
        }
        wp_mail($email, $subject, $message);
    }

    public function ensure_capability(){
        $role = get_role('administrator');
        if ($role && !$role->has_cap(self::CAPABILITY)){
            $role->add_cap(self::CAPABILITY);
        }
    }

    public function maybe_display_threshold_notice(){
        if (!$this->user_can_manage()){
            return;
        }
        $data = get_transient('ai_alt_gpt_token_notice');
        if ($data){
            $this->token_notice = $data;
            add_action('admin_notices', [$this, 'render_token_notice']);
        }
    }

    public function render_token_notice(){
        if (empty($this->token_notice)){
            return;
        }
        delete_transient('ai_alt_gpt_token_notice');
        $total = number_format_i18n($this->token_notice['total'] ?? 0);
        $limit = number_format_i18n($this->token_notice['limit'] ?? 0);
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf(__('AI Alt Text Generator has used %1$s tokens (threshold %2$s). Consider reviewing usage.', 'ai-alt-gpt'), $total, $limit)) . '</p></div>';
        $this->token_notice = null;
    }

    public function deactivate(){
    }

    public function activate() {
        global $wpdb;
        
        // Create database indexes for performance
        $this->create_performance_indexes();
        
        $defaults = [
            'api_key'          => '',
            'model'            => 'gpt-4o-mini',
            'max_words'        => 16,
            'language'         => 'en-GB',
            'language_custom'  => '',
            'enable_on_upload' => true,
            'tone'             => 'professional, accessible',
            'force_overwrite'  => false,
            'token_limit'      => 0,
            'token_alert_sent' => false,
            'dry_run'          => false,
            'custom_prompt'    => '',
            'notify_email'     => get_option('admin_email'),
            'usage'            => $this->default_usage(),
        ];
        $existing = get_option(self::OPTION_KEY, []);
        update_option(self::OPTION_KEY, wp_parse_args($existing, $defaults), false);

        $role = get_role('administrator');
        if ($role && !$role->has_cap(self::CAPABILITY)){
            $role->add_cap(self::CAPABILITY);
        }
    }
    
    private function create_performance_indexes() {
        global $wpdb;
        
        // Suppress errors for better compatibility across MySQL versions
        $wpdb->suppress_errors(true);
        
        // Index for _ai_alt_generated_at (used in sorting and stats)
        $wpdb->query("
            CREATE INDEX idx_ai_alt_generated_at 
            ON {$wpdb->postmeta} (meta_key(50), meta_value(50))
        ");
        
        // Index for _ai_alt_source (used in stats aggregation)
        $wpdb->query("
            CREATE INDEX idx_ai_alt_source 
            ON {$wpdb->postmeta} (meta_key(50), meta_value(50))
        ");
        
        // Index for _wp_attachment_image_alt (used in coverage stats)
        $wpdb->query("
            CREATE INDEX idx_wp_attachment_alt 
            ON {$wpdb->postmeta} (meta_key(50), meta_value(100))
        ");
        
        // Composite index for attachment queries
        $wpdb->query("
            CREATE INDEX idx_posts_attachment_image 
            ON {$wpdb->posts} (post_type(20), post_mime_type(20), post_status(20))
        ");
        
        $wpdb->suppress_errors(false);
    }

    public function add_settings_page() {
        $cap = current_user_can(self::CAPABILITY) ? self::CAPABILITY : 'manage_options';
        add_media_page(
            'AI Alt Text (GPT)',
            'AI Alt Text (GPT)',
            $cap,
            'ai-alt-gpt',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('ai_alt_gpt_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => function($input){
                $existing = get_option(self::OPTION_KEY, []);
                $out = [];
                $out['api_key']          = sanitize_text_field($input['api_key'] ?? '');
                $out['model']            = sanitize_text_field($input['model'] ?? 'gpt-4o-mini');
                $out['max_words']        = max(4, intval($input['max_words'] ?? 16));
                $lang_input = sanitize_text_field($input['language'] ?? 'en-GB');
                $custom_input = sanitize_text_field($input['language_custom'] ?? '');
                if ($lang_input === 'custom'){
                    $out['language'] = $custom_input ? $custom_input : 'en-GB';
                    $out['language_custom'] = $custom_input;
                } else {
                    $out['language'] = $lang_input ?: 'en-GB';
                    $out['language_custom'] = '';
                }
                $out['enable_on_upload'] = !empty($input['enable_on_upload']);
                $out['tone']             = sanitize_text_field($input['tone'] ?? 'professional, accessible');
                $out['force_overwrite']  = !empty($input['force_overwrite']);
                $out['token_limit']      = max(0, intval($input['token_limit'] ?? 0));
                if ($out['token_limit'] === 0){
                    $out['token_alert_sent'] = false;
                } elseif (intval($existing['token_limit'] ?? 0) !== $out['token_limit']){
                    $out['token_alert_sent'] = false;
                } else {
                    $out['token_alert_sent'] = !empty($existing['token_alert_sent']);
                }
                $out['dry_run'] = !empty($input['dry_run']);
                $out['custom_prompt'] = wp_kses_post($input['custom_prompt'] ?? '');
                $notify = sanitize_text_field($input['notify_email'] ?? ($existing['notify_email'] ?? get_option('admin_email')));
                $out['notify_email'] = is_email($notify) ? $notify : ($existing['notify_email'] ?? get_option('admin_email'));
                $out['usage']            = $existing['usage'] ?? $this->default_usage();
                return $out;
            }
        ]);
    }

    public function render_settings_page() {
        if (!$this->user_can_manage()) return;
        $opts  = get_option(self::OPTION_KEY, []);
        $stats = $this->get_media_stats();
        $nonce = wp_create_nonce(self::NONCE_KEY);
        $has_key = !empty($opts['api_key']);
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $tabs = [
            'dashboard' => __('Dashboard', 'ai-alt-gpt'),
            'usage'     => __('Usage & Reports', 'ai-alt-gpt'),
            'library'   => __('ALT Library', 'ai-alt-gpt'),
            'guide'     => __('How to Use', 'ai-alt-gpt'),
            'settings'  => __('Settings', 'ai-alt-gpt'),
        ];
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=ai_alt_usage_export'), 'ai_alt_usage_export');
        $audit_rows = $stats['audit'] ?? [];
        ?>
        <div class="wrap">
            <h1>Farlo AI Alt Text Generator (GPT)</h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) :
                    $url = esc_url(add_query_arg(['tab' => $slug]));
                    $active = $tab === $slug ? ' nav-tab-active' : '';
                ?>
                    <a href="<?php echo $url; ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <?php if ($tab === 'dashboard') : ?>
            <?php
                $coverage_numeric = max(0, min(100, floatval($stats['coverage'])));
                $coverage_decimals = $coverage_numeric === floor($coverage_numeric) ? 0 : 1;
                $coverage_display = number_format_i18n($coverage_numeric, $coverage_decimals);
                /* translators: %s: Percentage value */
                $coverage_text = $coverage_display . '%';
                /* translators: %s: Percentage value */
                $coverage_value_text = sprintf(__('ALT coverage at %s', 'ai-alt-gpt'), $coverage_text);
            ?>
            <div class="ai-alt-dashboard ai-alt-dashboard--primary" data-stats='<?php echo esc_attr(wp_json_encode($stats)); ?>'>
                <div class="ai-alt-dashboard__intro">
                    <h2><?php esc_html_e('Your accessibility command centre', 'ai-alt-gpt'); ?></h2>
                    <p><?php
                        $library_link = esc_url(add_query_arg(['tab' => 'library'], admin_url('upload.php?page=ai-alt-gpt')));
                        $intro_text = sprintf(
                            /* translators: %s: URL to ALT Library tab */
                            __('Run the quick actions below to generate coverage, then hop to the <a href="%s">ALT Library</a> to review every sentence before publishing.', 'ai-alt-gpt'),
                            $library_link
                        );
                        echo wp_kses_post($intro_text);
                    ?></p>
                </div>
                <div class="ai-alt-dashboard__microcards">
                    <div class="ai-alt-microcard">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Last regenerated', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-microcard__value"><?php echo esc_html($stats['latest_generated'] ?: __('No generations yet', 'ai-alt-gpt')); ?></strong>
                    </div>
                    <div class="ai-alt-microcard">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Top source (all time)', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-microcard__value"><?php echo $stats['top_source_key'] ? esc_html($this->format_source_label($stats['top_source_key'])) . ' · ' . esc_html(number_format_i18n($stats['top_source_count'])) : esc_html__('No data yet', 'ai-alt-gpt'); ?></strong>
                    </div>
                    <div class="ai-alt-microcard">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Dry run mode', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-microcard__value ai-alt-microcard__value--status <?php echo $stats['dry_run_enabled'] ? 'is-on' : 'is-off'; ?>"><?php echo $stats['dry_run_enabled'] ? esc_html__('ON', 'ai-alt-gpt') : esc_html__('OFF', 'ai-alt-gpt'); ?></strong>
                    </div>
                </div>
                <div class="ai-alt-dashboard__grid">
                    <div class="ai-alt-card">
                        <span class="ai-alt-card__label"><?php esc_html_e('Images', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-card__value"><?php echo esc_html(number_format_i18n($stats['total'])); ?></span>
                        <span class="ai-alt-card__hint"><?php esc_html_e('Total image attachments', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-card">
                        <span class="ai-alt-card__label"><?php esc_html_e('With ALT', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-card__value"><?php echo esc_html(number_format_i18n($stats['with_alt'])); ?></span>
                        <span class="ai-alt-card__hint"><?php echo esc_html($stats['coverage']); ?>% <?php esc_html_e('coverage', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-card ai-alt-card--warning">
                        <span class="ai-alt-card__label"><?php esc_html_e('Missing', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-card__value"><?php echo esc_html(number_format_i18n($stats['missing'])); ?></span>
                        <span class="ai-alt-card__hint"><?php esc_html_e('Needs attention', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-card">
                        <span class="ai-alt-card__label"><?php esc_html_e('AI Generated', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-card__value"><?php echo esc_html(number_format_i18n($stats['generated'])); ?></span>
                        <span class="ai-alt-card__hint"><?php esc_html_e('Tracked by plugin', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-card ai-alt-card--tokens">
                        <span class="ai-alt-card__label"><?php esc_html_e('Tokens Used', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-card__value ai-alt-card__value--tokens"><?php echo esc_html(number_format_i18n($stats['usage']['total'] ?? 0)); ?></span>
                        <span class="ai-alt-card__hint"><?php esc_html_e('Across all API requests', 'ai-alt-gpt'); ?></span>
                    </div>
                </div>

                <?php $coverage_note = $stats['missing'] > 0
                    ? __('Run a quick pass for gaps, then jump into the ALT Library to review every generated description before publishing.', 'ai-alt-gpt')
                    : __('Great job! Keep reviewing new uploads in the ALT Library before publishing.', 'ai-alt-gpt');
                ?>
                <div class="ai-alt-coverage-card" role="group" aria-labelledby="ai-alt-coverage-value">
                    <div class="ai-alt-coverage-card__summary">
                        <div class="ai-alt-progress" aria-labelledby="ai-alt-coverage-summary">
                            <div class="ai-alt-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($coverage_numeric); ?>" aria-valuetext="<?php echo esc_attr($coverage_value_text); ?>">
                                <span style="width: <?php echo esc_attr($coverage_numeric); ?>%" aria-hidden="true"></span>
                            </div>
                            <p id="ai-alt-coverage-summary" class="ai-alt-dashboard__coverage"><strong id="ai-alt-coverage-value"><?php echo esc_html($coverage_text); ?></strong> <?php esc_html_e('of images currently include ALT text.', 'ai-alt-gpt'); ?></p>
                        </div>
                        <div class="ai-alt-coverage-card__actions">
                            <button type="button" class="button button-primary ai-alt-button ai-alt-button--primary" data-action="generate-missing" <?php echo $stats['missing'] <= 0 ? 'disabled' : ''; ?>><?php esc_html_e('Generate ALT for Missing Images', 'ai-alt-gpt'); ?></button>
                            <button type="button" class="button button-secondary ai-alt-button ai-alt-button--outline" data-action="regenerate-all"><?php esc_html_e('Regenerate ALT for All Images', 'ai-alt-gpt'); ?></button>
                        </div>
                        <div class="ai-alt-progress-log" data-progress-log aria-live="polite"></div>
                        <p class="ai-alt-coverage-card__note"><?php echo esc_html($coverage_note); ?></p>
                    </div>
                    <div class="ai-alt-coverage-card__viz" data-coverage-viz data-coverage-complete="<?php echo $stats['missing'] > 0 ? '0' : '1'; ?>">
                        <figure class="ai-alt-chart__figure">
                            <canvas id="ai-alt-coverage" aria-label="<?php esc_attr_e('Visual coverage chart', 'ai-alt-gpt'); ?>"></canvas>
                            <figcaption class="screen-reader-text"><?php echo esc_html($coverage_value_text); ?></figcaption>
                            <div class="ai-alt-chart__legend" aria-hidden="true" data-coverage-legend<?php echo $stats['missing'] > 0 ? '' : ' hidden'; ?>>
                                <span class="ai-alt-chart__dot ai-alt-chart__dot--with"></span> <?php esc_html_e('With ALT', 'ai-alt-gpt'); ?>
                                <span class="ai-alt-chart__dot ai-alt-chart__dot--missing"></span> <?php esc_html_e('Missing', 'ai-alt-gpt'); ?>
                            </div>
                        </figure>
                        <div class="ai-alt-coverage-card__badge" data-coverage-badge<?php echo $stats['missing'] > 0 ? ' hidden' : ''; ?>><?php esc_html_e('All images have ALT text', 'ai-alt-gpt'); ?></div>
                    </div>
                </div>
                <div class="ai-alt-dashboard__status" data-progress-status role="status" aria-live="polite"></div>

            </div>
            <?php elseif ($tab === 'usage') : ?>
            <?php $audit_rows = $stats['audit'] ?? []; $export_url = wp_nonce_url(admin_url('admin-post.php?action=ai_alt_usage_export'), 'ai_alt_usage_export'); ?>
            <div class="ai-alt-dashboard ai-alt-dashboard--usage" data-stats='<?php echo esc_attr(wp_json_encode($stats)); ?>'>
                <div class="ai-alt-dashboard__intro ai-alt-usage__intro">
                    <h2 id="ai-alt-usage-heading"><?php esc_html_e('Usage snapshot', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('This tab highlights recent activity and token spend. For a full review and regeneration workflow, head to the ALT Library.', 'ai-alt-gpt'); ?></p>
                </div>
                <div class="ai-alt-dashboard__microcards ai-alt-usage__grid" role="group" aria-labelledby="ai-alt-usage-heading">
                    <div class="ai-alt-microcard ai-alt-usage__card">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('API requests', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-microcard__value ai-alt-usage__value ai-alt-usage__value--requests"><?php echo esc_html(number_format_i18n($stats['usage']['requests'] ?? 0)); ?></span>
                        <span class="ai-alt-usage__hint"><?php esc_html_e('Total calls recorded', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-microcard ai-alt-usage__card">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Prompt tokens', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-microcard__value ai-alt-usage__value ai-alt-usage__value--prompt"><?php echo esc_html(number_format_i18n($stats['usage']['prompt'] ?? 0)); ?></span>
                        <span class="ai-alt-usage__hint"><?php esc_html_e('Tokens sent in prompts', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-microcard ai-alt-usage__card">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Completion tokens', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-microcard__value ai-alt-usage__value ai-alt-usage__value--completion"><?php echo esc_html(number_format_i18n($stats['usage']['completion'] ?? 0)); ?></span>
                        <span class="ai-alt-usage__hint"><?php esc_html_e('Tokens returned by OpenAI', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-microcard ai-alt-usage__card">
                        <span class="ai-alt-microcard__label"><?php esc_html_e('Last request', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-microcard__value ai-alt-usage__value ai-alt-usage__value--last"><?php echo esc_html($stats['usage']['last_request_formatted'] ?? ($stats['usage']['last_request'] ?? __('None yet', 'ai-alt-gpt'))); ?></span>
                        <span class="ai-alt-usage__hint"><?php esc_html_e('Most recent ALT generation', 'ai-alt-gpt'); ?></span>
                    </div>
                </div>
                <div class="ai-alt-usage__cta">
                    <span class="ai-alt-usage__cta-label"><?php esc_html_e('Need deeper insight?', 'ai-alt-gpt'); ?></span>
                    <a href="https://platform.openai.com/usage" target="_blank" rel="noopener noreferrer" class="ai-alt-usage__cta-link"><?php esc_html_e('View detailed usage in OpenAI dashboard', 'ai-alt-gpt'); ?></a>
                </div>
                <div class="ai-alt-audit">
                    <div class="ai-alt-audit__header">
                        <h3><?php esc_html_e('Usage Audit', 'ai-alt-gpt'); ?></h3>
                        <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary ai-alt-export"><?php esc_html_e('Download CSV', 'ai-alt-gpt'); ?></a>
                    </div>
                    <table class="ai-alt-audit__table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Attachment', 'ai-alt-gpt'); ?></th>
                                <th scope="col"><?php esc_html_e('Source', 'ai-alt-gpt'); ?></th>
                                <th scope="col"><?php esc_html_e('Tokens', 'ai-alt-gpt'); ?></th>
                                <th scope="col"><?php esc_html_e('Prompt', 'ai-alt-gpt'); ?></th>
                                <th scope="col"><?php esc_html_e('Completion', 'ai-alt-gpt'); ?></th>
                                <th scope="col"><?php esc_html_e('Last Generated', 'ai-alt-gpt'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="ai-alt-audit-rows">
                            <?php if (!empty($audit_rows)) : ?>
                                <?php foreach ($audit_rows as $row) : ?>
                                    <tr data-id="<?php echo esc_attr($row['id']); ?>">
                                        <td>
                                            <div class="ai-alt-audit__attachment">
                                                <?php if (!empty($row['thumb'])) : ?>
                                                    <a href="<?php echo esc_url($row['details_url']); ?>" class="ai-alt-audit__thumb" aria-hidden="true"><img src="<?php echo esc_url($row['thumb']); ?>" alt="" loading="lazy" /></a>
                                                <?php endif; ?>
                                                <div class="ai-alt-audit__details">
                                                    <a href="<?php echo esc_url($row['details_url']); ?>" class="ai-alt-audit__details-link"><?php esc_html_e('Attachment', 'ai-alt-gpt'); ?></a>
                                                    <div class="ai-alt-audit__meta"><code>#<?php echo esc_html($row['id']); ?></code><?php if (!empty($row['view_url'])) : ?><a href="<?php echo esc_url($row['view_url']); ?>" target="_blank" rel="noopener noreferrer" class="ai-alt-audit__preview"><?php esc_html_e('Preview', 'ai-alt-gpt'); ?></a><?php endif; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="ai-alt-audit__source"><span class="ai-alt-badge ai-alt-badge--<?php echo esc_attr($row['source'] ?: 'unknown'); ?>" title="<?php echo esc_attr($row['source_description']); ?>"><?php echo esc_html($row['source_label']); ?></span></td>
                                        <td class="ai-alt-audit__tokens"><?php echo esc_html(number_format_i18n($row['tokens'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n($row['prompt'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n($row['completion'])); ?></td>
                                        <td><?php echo esc_html($row['generated']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr class="ai-alt-audit__empty"><td colspan="6"><?php esc_html_e('No usage data recorded yet.', 'ai-alt-gpt'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif ($tab === 'guide') : ?>
            <div class="ai-alt-guide ai-alt-panel">
                <div class="ai-alt-guide__header">
                    <h2><?php esc_html_e('Master the AI ALT workflow in four moves', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('Generate descriptions, run automated QA, and triage everything inside the ALT Library with filters, scores, and review summaries.', 'ai-alt-gpt'); ?></p>
                </div>

                <div class="ai-alt-guide__grid">
                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('1. Connect & calibrate', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Open the Settings tab (last tab) to add your OpenAI API key and pick the default model used for generation and QA review.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Set the language, tone, and word limit so every description matches your editorial voice.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Configure token alerts and notification email to stay ahead of large automation runs.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>

                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('2. Generate with guardrails', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Upload media or click “Generate ALT for Missing Images” on the dashboard to seed the library.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Use the Regenerate buttons in the ALT Library or Media modal; preview edits before accepting them.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Dry run mode logs prompts without writing changes—perfect for rehearsals or prompt testing.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>

                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('3. Let QA scoring triage the queue', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Every regeneration triggers an automated review that grades accuracy, detail, and tone.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Quality badges surface reviewer notes—use the issue list to rewrite anything flagged.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Click the summary cards (Healthy, Needs review, Critical) to filter the table and clear the backlog fast.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>

                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('4. Monitor & share progress', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Visit Usage & Reports for request counts, token spend, and a downloadable CSV audit.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Use the dashboard coverage cards to track portfolio health and spot gaps before launch.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Need bulk control? Use the Media Library bulk action or run wp ai-alt generate --all via WP-CLI.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>
                </div>

                <div class="ai-alt-guide__footer">
                    <p><?php esc_html_e('Tip: ALT Library summary cards double as filters—click them to focus on Needs review or Critical items, then regenerate and re-run QA.', 'ai-alt-gpt'); ?></p>
                </div>
            </div>
            <?php elseif ($tab === 'library') : ?>
            <?php
            $per_page = max(5, min(200, intval($_GET['per_page'] ?? 50)));
            $page_num = max(1, intval($_GET['paged'] ?? 1));
            $offset   = ($page_num - 1) * $per_page;
            $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $per_page,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'fields'         => 'ids',
            ];

            if ($search){
                $args['s'] = $search;
            }

            $query = new \WP_Query($args);
            $ids   = $query->posts;
            $total_posts = intval($query->found_posts);
            $total_pages = $per_page > 0 ? ceil($total_posts / $per_page) : 1;

            $library_rows = [];
            $library_summary = [
                'total'     => 0,
                'score_sum' => 0,
                'healthy'   => 0,
                'review'    => 0,
                'critical'  => 0,
            ];

            if ($ids) {
                foreach ($ids as $attachment_id) {
                    $raw_alt = trim(get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
                    $alt     = $raw_alt !== '' ? $raw_alt : __('(empty)', 'ai-alt-gpt');
                    $title   = get_the_title($attachment_id);
                    $tokens  = intval(get_post_meta($attachment_id, '_ai_alt_tokens_total', true));
                    $generated_raw = get_post_meta($attachment_id, '_ai_alt_generated_at', true);
                    $generated = $generated_raw ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $generated_raw) : __('Never', 'ai-alt-gpt');
                    $edit_link = get_edit_post_link($attachment_id);
                    $view_link = add_query_arg('item', $attachment_id, admin_url('upload.php')) . '#attachment_alt';
                    $thumb     = wp_get_attachment_image_src($attachment_id, 'thumbnail');

                    $is_missing = $raw_alt === '';
                    $is_recent  = $generated_raw ? ( time() - strtotime($generated_raw) ) <= apply_filters('ai_alt_gpt_recent_highlight_window', 30 * MINUTE_IN_SECONDS ) : false;
                    $row_classes = ['ai-alt-library__row'];
                    if ($is_recent) {
                        $row_classes[] = 'ai-alt-library__row--recent';
                    }
                    if ($is_missing) {
                        $row_classes[] = 'ai-alt-library__row--missing';
                    }

                    $analysis = $this->evaluate_alt_health($attachment_id, $raw_alt);
                    $library_summary['total']++;
                    $library_summary['score_sum'] += $analysis['score'];
                    if (in_array($analysis['status'], ['great', 'good'], true)) {
                        $library_summary['healthy']++;
                    } elseif ($analysis['status'] === 'review') {
                        $library_summary['review']++;
                    } else {
                        $library_summary['critical']++;
                    }

                    $library_rows[] = [
                        'id'          => $attachment_id,
                        'alt'         => $alt,
                        'raw_alt'     => $raw_alt,
                        'title'       => $title,
                        'thumb'       => $thumb,
                        'edit_link'   => $edit_link,
                        'view_link'   => $view_link,
                        'is_missing'  => $is_missing,
                        'is_recent'   => $is_recent,
                        'row_classes' => $row_classes,
                        'tokens'      => $tokens,
                        'generated'   => $generated,
                        'analysis'    => $analysis,
                    ];
                }
            }
            ?>
            <div class="ai-alt-library">
                <div class="ai-alt-library__intro">
                    <h2><?php esc_html_e('ALT Library review queue', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('Use this table to read every generated description before publishing. Regenerate anything that feels off, and use the “View” link to jump straight to the attachment details.', 'ai-alt-gpt'); ?></p>
                </div>
                <form class="ai-alt-library__filters" method="get">
                    <input type="hidden" name="page" value="ai-alt-gpt" />
                    <input type="hidden" name="tab" value="library" />
                    <label for="ai-alt-search" class="screen-reader-text"><?php esc_html_e('Search ALT text', 'ai-alt-gpt'); ?></label>
                    <input type="search" id="ai-alt-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search media title or ALT text…', 'ai-alt-gpt'); ?>" />
                    <label for="ai-alt-per-page" class="screen-reader-text"><?php esc_html_e('Items per page', 'ai-alt-gpt'); ?></label>
                    <select name="per_page" id="ai-alt-per-page">
                        <?php foreach ([25, 50, 100, 200] as $option) : ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>><?php echo esc_html(sprintf(__('%d per page', 'ai-alt-gpt'), $option)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'ai-alt-gpt'); ?></button>
                </form>
                <?php if ($library_summary['total'] > 0) :
                    $average_score = round($library_summary['score_sum'] / max(1, $library_summary['total']));
                ?>
                <div class="ai-alt-library__summary" data-library-summary>
                    <div class="ai-alt-library__stat" data-summary-filter="clear" role="button" tabindex="0" aria-label="<?php esc_attr_e('Show all ALT text entries', 'ai-alt-gpt'); ?>">
                        <span><?php esc_html_e('Average score', 'ai-alt-gpt'); ?></span>
                        <strong data-library-summary-average><?php echo esc_html(number_format_i18n($average_score)); ?></strong>
                    </div>
                    <div class="ai-alt-library__stat" data-summary-filter="healthy" role="button" tabindex="0" aria-label="<?php esc_attr_e('Show healthy ALT text entries', 'ai-alt-gpt'); ?>">
                        <span><?php esc_html_e('Healthy', 'ai-alt-gpt'); ?></span>
                        <strong data-library-summary-healthy><?php echo esc_html(number_format_i18n($library_summary['healthy'])); ?></strong>
                    </div>
                    <div class="ai-alt-library__stat" data-summary-filter="review" role="button" tabindex="0" aria-label="<?php esc_attr_e('Show ALT text that needs review', 'ai-alt-gpt'); ?>">
                        <span><?php esc_html_e('Needs review', 'ai-alt-gpt'); ?></span>
                        <strong data-library-summary-review><?php echo esc_html(number_format_i18n($library_summary['review'])); ?></strong>
                    </div>
                    <div class="ai-alt-library__stat" data-summary-filter="critical" role="button" tabindex="0" aria-label="<?php esc_attr_e('Show critical ALT text entries', 'ai-alt-gpt'); ?>">
                        <span><?php esc_html_e('Critical', 'ai-alt-gpt'); ?></span>
                        <strong data-library-summary-critical><?php echo esc_html(number_format_i18n($library_summary['critical'])); ?></strong>
                    </div>
                    <div class="ai-alt-library__stat">
                        <span><?php esc_html_e('Items on this page', 'ai-alt-gpt'); ?></span>
                        <strong data-library-summary-total><?php echo esc_html(number_format_i18n($library_summary['total'])); ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <table class="ai-alt-library__table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Attachment', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('ALT Text', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Quality', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Tokens', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Last Generated', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'ai-alt-gpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($library_rows) : ?>
                            <?php foreach ($library_rows as $row) :
                                $row_class_attr = $row['row_classes'] ? ' class="' . esc_attr(implode(' ', $row['row_classes'])) . '"' : '';
                                $analysis = $row['analysis'];
                                ?>
                                <tr<?php echo $row_class_attr; ?> data-id="<?php echo esc_attr($row['id']); ?>">
                                    <td>
                                        <div class="ai-alt-library__attachment">
                                            <?php if (!empty($row['thumb'])) : ?>
                                                <a href="<?php echo esc_url($row['view_link']); ?>" class="ai-alt-library__thumb" aria-hidden="true"><img src="<?php echo esc_url($row['thumb'][0]); ?>" alt="" loading="lazy" /></a>
                                            <?php endif; ?>
                                            <div class="ai-alt-library__details">
                                                <a href="<?php echo esc_url($row['edit_link']); ?>">
                                                    <?php esc_html_e('Attachment', 'ai-alt-gpt'); ?>
                                                </a>
                                            <div class="ai-alt-library__meta">
                                                <code>#<?php echo esc_html($row['id']); ?></code>
                                                    <?php if ($row['view_link']) : ?>
                                                        · <a href="<?php echo esc_url($row['view_link']); ?>" class="ai-alt-library__details-link"><?php esc_html_e('View', 'ai-alt-gpt'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($row['is_recent']) : ?>
                                                    · <span class="ai-alt-library__recent-badge"><?php esc_html_e('New', 'ai-alt-gpt'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ai-alt-library__alt">
                                        <?php echo esc_html($row['alt']); ?>
                                        <?php if ($row['is_missing']) : ?>
                                            <span class="ai-alt-library__flag"><?php esc_html_e('Needs ALT review', 'ai-alt-gpt'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ai-alt-library__score" data-score="<?php echo esc_attr($analysis['score']); ?>" data-status="<?php echo esc_attr($analysis['status']); ?>">
                                        <div class="ai-alt-score">
                                            <span class="ai-alt-score-badge ai-alt-score-badge--<?php echo esc_attr($analysis['status']); ?>"><?php echo esc_html(number_format_i18n($analysis['score'])); ?></span>
                                            <span class="ai-alt-score-label"><?php echo esc_html($analysis['grade']); ?></span>
                                        </div>
                                        <?php if (!empty($analysis['issues'])) : ?>
                                            <ul class="ai-alt-score-issues">
                                                <?php foreach ($analysis['issues'] as $issue) : ?>
                                                    <li><?php echo esc_html($issue); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ai-alt-library__tokens"><?php echo esc_html(number_format_i18n($row['tokens'])); ?></td>
                                    <td class="ai-alt-library__generated"><?php echo esc_html($row['generated']); ?></td>
                                    <td>
                                        <button type="button" class="button button-small ai-alt-regenerate-single" data-id="<?php echo esc_attr($row['id']); ?>"><?php esc_html_e('Regenerate', 'ai-alt-gpt'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="6" class="ai-alt-library__empty"><?php esc_html_e('No attachments found matching your criteria.', 'ai-alt-gpt'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="ai-alt-library__pagination">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg(['tab' => 'library', 'per_page' => $per_page, 's' => $search, 'paged' => '%#%']),
                            'format'    => '',
                            'current'   => $page_num,
                            'total'     => $total_pages,
                            'prev_text' => __('« Previous', 'ai-alt-gpt'),
                            'next_text' => __('Next »', 'ai-alt-gpt'),
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div class="ai-alt-guide ai-alt-panel ai-alt-settings-panel">
                <div class="ai-alt-guide__header">
                    <h2><?php esc_html_e('Tune your AI ALT workflow', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('Set the defaults for generation, automated QA scoring, automation rules, and alerts. Changes apply immediately across the dashboard, ALT Library, and bulk tools.', 'ai-alt-gpt'); ?></p>
                </div>

                <form method="post" action="options.php" class="ai-alt-settings__form">
                    <?php settings_fields('ai_alt_gpt_group'); ?>
                    <?php $o = wp_parse_args($opts, []); ?>
                    <div class="ai-alt-settings__grid">
                        <section class="ai-alt-settings__card">
                            <h3><?php esc_html_e('OpenAI connection', 'ai-alt-gpt'); ?></h3>
                            <p class="ai-alt-settings__hint"><?php esc_html_e('The same key powers ALT generation and the post-generation QA reviewer.', 'ai-alt-gpt'); ?></p>
                            <div class="ai-alt-settings__field">
                                <label for="api_key"><?php esc_html_e('API key', 'ai-alt-gpt'); ?></label>
                                <input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]" id="api_key" value="<?php echo esc_attr($o['api_key'] ?? ''); ?>" class="regular-text" autocomplete="off" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Stored in wp_options. Only trusted administrators should have access.', 'ai-alt-gpt'); ?></p>
                                <?php if ($has_key) : ?>
                                    <div class="notice notice-success inline"><p><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php echo esc_html__('Connected. Key saved in settings.', 'ai-alt-gpt'); ?></p></div>
                                <?php else : ?>
                                    <div class="notice notice-warning inline"><p><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html__('Add an OpenAI API key to enable generation and QA review.', 'ai-alt-gpt'); ?></p></div>
                                <?php endif; ?>
                                <input type="hidden" id="ai_alt_gpt_nonce" value="<?php echo esc_attr($nonce); ?>"/>
                            </div>
                        </section>

                        <section class="ai-alt-settings__card">
                            <h3><?php esc_html_e('Generation defaults', 'ai-alt-gpt'); ?></h3>
                            <p class="ai-alt-settings__hint"><?php esc_html_e('Pick the default model and writing parameters used whenever the plugin generates ALT text.', 'ai-alt-gpt'); ?></p>
                            <div class="ai-alt-settings__field">
                                <label for="model"><?php esc_html_e('Default model', 'ai-alt-gpt'); ?></label>
                                <?php
                                $model = $o['model'] ?? 'gpt-4o-mini';
                                $models = [
                                    'gpt-4o-mini'  => __('gpt-4o-mini - fast, low cost, ideal default', 'ai-alt-gpt'),
                                    'gpt-4o'       => __('gpt-4o - higher quality, more descriptive, higher cost', 'ai-alt-gpt'),
                                    'gpt-4.1-mini' => __('gpt-4.1-mini - stronger reasoning with moderate cost', 'ai-alt-gpt'),
                                ];
                                ?>
                                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[model]" id="model">
                                    <?php foreach ($models as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($model, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="ai-alt-settings__description"><?php esc_html_e('Keep gpt-4o-mini for balanced speed and spend, or step up to gpt-4o for richer detail.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <div class="ai-alt-settings__field">
                                <label for="max_words"><?php esc_html_e('Word limit', 'ai-alt-gpt'); ?></label>
                                <input type="number" min="4" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_words]" id="max_words" value="<?php echo esc_attr($o['max_words'] ?? 16); ?>" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Recommended: 8–16 words. The reviewer penalises ultra-short placeholder text.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <div class="ai-alt-settings__field">
                                <label for="tone"><?php esc_html_e('Tone / style', 'ai-alt-gpt'); ?></label>
                                <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tone]" id="tone" value="<?php echo esc_attr($o['tone'] ?? 'professional, accessible'); ?>" class="regular-text" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Describe the overall voice, e.g. “professional, accessible” or “friendly, concise”.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <div class="ai-alt-settings__field">
                                <label for="custom_prompt"><?php esc_html_e('Additional instructions (optional)', 'ai-alt-gpt'); ?></label>
                                <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_prompt]" id="custom_prompt" rows="4" class="large-text code"><?php echo esc_textarea($o['custom_prompt'] ?? ''); ?></textarea>
                                <p class="ai-alt-settings__description"><?php esc_html_e('Prepended to every request. Great for accessibility reminders or brand-specific rules.', 'ai-alt-gpt'); ?></p>
                            </div>
                        </section>

                        <section class="ai-alt-settings__card">
                            <h3><?php esc_html_e('Language & automation', 'ai-alt-gpt'); ?></h3>
                            <div class="ai-alt-settings__field">
                                <label for="language"><?php esc_html_e('Language', 'ai-alt-gpt'); ?></label>
                                <?php $current_lang = $o['language'] ?? 'en-GB'; $is_custom_lang = !in_array($current_lang, ['en','en-GB'], true); ?>
                                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[language]" id="language" class="ai-alt-language-select">
                                    <option value="en" <?php selected($current_lang, 'en'); ?>><?php esc_html_e('English (US)', 'ai-alt-gpt'); ?></option>
                                    <option value="en-GB" <?php selected($current_lang, 'en-GB'); ?>><?php esc_html_e('English (UK)', 'ai-alt-gpt'); ?></option>
                                    <option value="custom" <?php selected(true, $is_custom_lang); ?>><?php esc_html_e('Custom…', 'ai-alt-gpt'); ?></option>
                                </select>
                                <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[language_custom]" id="language_custom" value="<?php echo esc_attr($is_custom_lang ? $current_lang : ($o['language_custom'] ?? '')); ?>" class="regular-text ai-alt-language-custom" placeholder="fr, de, es" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Choose a preset or enter any ISO code / language name.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <div class="ai-alt-settings__field ai-alt-settings__field--checkboxes">
                                <span class="ai-alt-settings__label"><?php esc_html_e('Automation', 'ai-alt-gpt'); ?></span>
                                <label class="ai-alt-settings__checkbox"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_on_upload]" <?php checked(!empty($o['enable_on_upload'])); ?>/> <?php esc_html_e('Generate on upload', 'ai-alt-gpt'); ?></label>
                                <label class="ai-alt-settings__checkbox"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[force_overwrite]" <?php checked(!empty($o['force_overwrite'])); ?>/> <?php esc_html_e('Overwrite existing ALT text', 'ai-alt-gpt'); ?></label>
                                <label class="ai-alt-settings__checkbox"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[dry_run]" <?php checked(!empty($o['dry_run'])); ?>/> <?php esc_html_e('Dry run (log prompts but do not update ALT text)', 'ai-alt-gpt'); ?></label>
                                <?php if (!empty($o['dry_run'])) : ?>
                                    <div class="notice notice-warning inline"><p><?php esc_html_e('Dry run is enabled. Requests will log prompts but will not update ALT text.', 'ai-alt-gpt'); ?></p></div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="ai-alt-settings__card">
                            <h3><?php esc_html_e('Alerts & reporting', 'ai-alt-gpt'); ?></h3>
                            <p class="ai-alt-settings__hint"><?php esc_html_e('Stay ahead of usage by combining in-app metrics with notifications.', 'ai-alt-gpt'); ?></p>
                            <div class="ai-alt-settings__field">
                                <label for="token_limit"><?php esc_html_e('Token alert threshold', 'ai-alt-gpt'); ?></label>
                                <input type="number" min="0" step="100" name="<?php echo esc_attr(self::OPTION_KEY); ?>[token_limit]" id="token_limit" value="<?php echo esc_attr($o['token_limit'] ?? 0); ?>" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Send an admin notice once cumulative tokens exceed this value. Use 0 to disable.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <div class="ai-alt-settings__field">
                                <label for="notify_email"><?php esc_html_e('Alert email', 'ai-alt-gpt'); ?></label>
                                <input type="email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_email]" id="notify_email" value="<?php echo esc_attr($o['notify_email'] ?? get_option('admin_email')); ?>" class="regular-text" />
                                <p class="ai-alt-settings__description"><?php esc_html_e('Where to send token threshold notifications and other automated alerts.', 'ai-alt-gpt'); ?></p>
                            </div>
                            <p class="ai-alt-settings__note"><?php esc_html_e('Need a bulk refresh? Use the Media Library bulk action “Generate Alt Text (AI)” or run wp ai-alt generate --all via WP-CLI.', 'ai-alt-gpt'); ?></p>
                        </section>
                    </div>

                    <div class="ai-alt-settings__footer">
                        <?php submit_button(__('Save settings', 'ai-alt-gpt')); ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_prompt($attachment_id, $opts, $existing_alt = '', bool $is_retry = false, array $feedback = []){
        $file     = get_attached_file($attachment_id);
        $filename = $file ? wp_basename($file) : get_the_title($attachment_id);
        $title    = get_the_title($attachment_id);
        $caption  = wp_get_attachment_caption($attachment_id);
        $parent   = get_post_field('post_title', wp_get_post_parent_id($attachment_id));
        $lang_raw = $opts['language'] ?? 'en-GB';
        if ($lang_raw === 'custom' && !empty($opts['language_custom'])){
            $lang = sanitize_text_field($opts['language_custom']);
        } else {
            $lang = $lang_raw;
        }
        $tone     = $opts['tone'] ?? 'professional, accessible';
        $max      = max(4, intval($opts['max_words'] ?? 16));

        $existing_alt = is_string($existing_alt) ? trim($existing_alt) : '';
        $context_bits = array_filter([$title, $caption, $parent, $existing_alt ? ('Existing ALT: ' . $existing_alt) : '']);
        $context = $context_bits ? ("Context: " . implode(' | ', $context_bits)) : '';

        $custom = trim($opts['custom_prompt'] ?? '');
        $instruction = "Write concise, descriptive ALT text in {$lang} for an image. "
               . "Limit to {$max} words. Tone: {$tone}. "
               . "Describe the primary subject, include at least one observable colour or texture, and mention relevant background or context when visible. "
               . "Only describe details that can be seen; do not speculate about occupations, intentions, or locations unless obvious in the image. "
               . "Avoid phrases like 'image of' or 'photo of' and never output placeholders such as 'test' or 'sample'. "
               . "Return only the ALT text sentence.";

        if ($existing_alt){
            $instruction .= " The previous ALT text is provided for context and must be improved upon.";
        }

        if ($is_retry){
            $instruction .= " The previous attempt was rejected; ensure this version corrects the issues listed below and adds concrete, specific detail.";
        }

        $feedback_lines = array_filter(array_map('trim', $feedback));
        $feedback_block = '';
        if ($feedback_lines){
            $feedback_block = "\nReviewer feedback:";
            foreach ($feedback_lines as $line){
                $feedback_block .= "\n- " . sanitize_text_field($line);
            }
            $feedback_block .= "\n";
        }

        $prompt = ($custom ? $custom . "\n\n" : '')
               . $instruction
               . "\nFilename: {$filename}\n{$context}\n" . $feedback_block;
        return apply_filters('ai_alt_gpt_prompt', $prompt, $attachment_id, $opts);
    }

    private function is_image($attachment_id){
        $mime = get_post_mime_type($attachment_id);
        return strpos((string)$mime, 'image/') === 0;
    }

    private function invalidate_stats_cache(){
        wp_cache_delete('ai_alt_stats', 'ai_alt_gpt');
        delete_transient('ai_alt_stats_v3');
        $this->stats_cache = null;
    }

    private function get_media_stats(){
        // Check in-memory cache first
        if (is_array($this->stats_cache)){
            return $this->stats_cache;
        }

        // Check object cache (Redis/Memcached if available)
        $cache_key = 'ai_alt_stats';
        $cache_group = 'ai_alt_gpt';
        $cached = wp_cache_get($cache_key, $cache_group);
        if (false !== $cached && is_array($cached)){
            $this->stats_cache = $cached;
            return $cached;
        }

        // Check transient cache (5 minute TTL for DB queries)
        $transient_key = 'ai_alt_stats_v3';
        $cached = get_transient($transient_key);
        if (false !== $cached && is_array($cached)){
            // Also populate object cache for next request
            wp_cache_set($cache_key, $cached, $cache_group, 5 * MINUTE_IN_SECONDS);
            $this->stats_cache = $cached;
            return $cached;
        }

        global $wpdb;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'inherit' AND post_mime_type LIKE %s",
            'attachment', 'image/%'
        ));

        $with_alt = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE 'image/%'
               AND m.meta_key = '_wp_attachment_image_alt'
               AND TRIM(m.meta_value) <> ''"
        );

        $generated = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_ai_alt_generated_at'"
        );

        $coverage = $total ? round(($with_alt / $total) * 100, 1) : 0;
        $missing  = max(0, $total - $with_alt);

        $opts = get_option(self::OPTION_KEY, []);
        $usage = $opts['usage'] ?? $this->default_usage();
        if (!empty($usage['last_request'])){
            $usage['last_request_formatted'] = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $usage['last_request']);
        }

        $latest_generated_raw = $wpdb->get_var(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ai_alt_generated_at' ORDER BY meta_value DESC LIMIT 1"
        );
        $latest_generated = $latest_generated_raw ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $latest_generated_raw) : '';

        $top_source_row = $wpdb->get_row(
            "SELECT meta_value AS source, COUNT(*) AS count
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_ai_alt_source' AND meta_value <> ''
             GROUP BY meta_value
             ORDER BY COUNT(*) DESC
             LIMIT 1",
            ARRAY_A
        );
        $top_source_key = sanitize_key($top_source_row['source'] ?? '');
        $top_source_count = intval($top_source_row['count'] ?? 0);

        $this->stats_cache = [
            'total'     => $total,
            'with_alt'  => $with_alt,
            'missing'   => $missing,
            'generated' => $generated,
            'coverage'  => $coverage,
            'usage'     => $usage,
            'token_limit' => intval($opts['token_limit'] ?? 0),
            'latest_generated' => $latest_generated,
            'latest_generated_raw' => $latest_generated_raw,
            'top_source_key' => $top_source_key,
            'top_source_count' => $top_source_count,
            'dry_run_enabled' => !empty($opts['dry_run']),
            'audit' => $this->get_usage_rows(10),
        ];

        // Cache for 5 minutes (300 seconds)
        wp_cache_set($cache_key, $this->stats_cache, $cache_group, 5 * MINUTE_IN_SECONDS);
        set_transient($transient_key, $this->stats_cache, 5 * MINUTE_IN_SECONDS);

        return $this->stats_cache;
    }

    private function prepare_attachment_snapshot($attachment_id){
        $attachment_id = intval($attachment_id);
        if ($attachment_id <= 0){
            return [];
        }

        $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $tokens = intval(get_post_meta($attachment_id, '_ai_alt_tokens_total', true));
        $prompt = intval(get_post_meta($attachment_id, '_ai_alt_tokens_prompt', true));
        $completion = intval(get_post_meta($attachment_id, '_ai_alt_tokens_completion', true));
        $generated_raw = get_post_meta($attachment_id, '_ai_alt_generated_at', true);
        $generated = $generated_raw ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $generated_raw) : '';
        $source_key = sanitize_key(get_post_meta($attachment_id, '_ai_alt_source', true) ?: 'unknown');
        if (!$source_key){
            $source_key = 'unknown';
        }

        $analysis = $this->evaluate_alt_health($attachment_id, $alt);

        return [
            'id' => $attachment_id,
            'alt' => $alt,
            'tokens' => $tokens,
            'prompt' => $prompt,
            'completion' => $completion,
            'generated_raw' => $generated_raw,
            'generated' => $generated,
            'source_key' => $source_key,
            'source_label' => $this->format_source_label($source_key),
            'source_description' => $this->format_source_description($source_key),
            'score' => $analysis['score'],
            'score_grade' => $analysis['grade'],
            'score_status' => $analysis['status'],
            'score_issues' => $analysis['issues'],
            'score_summary' => $analysis['review']['summary'] ?? '',
            'analysis' => $analysis,
        ];
    }

    private function hash_alt_text(string $alt): string{
        $alt = strtolower(trim((string) $alt));
        $alt = preg_replace('/\s+/', ' ', $alt);
        return wp_hash($alt);
    }

    private function purge_review_meta(int $attachment_id): void{
        $keys = [
            '_ai_alt_review_score',
            '_ai_alt_review_status',
            '_ai_alt_review_grade',
            '_ai_alt_review_summary',
            '_ai_alt_review_issues',
            '_ai_alt_review_model',
            '_ai_alt_reviewed_at',
            '_ai_alt_review_alt_hash',
        ];
        foreach ($keys as $key){
            delete_post_meta($attachment_id, $key);
        }
    }

    private function get_review_snapshot(int $attachment_id, string $current_alt = ''): ?array{
        $score = intval(get_post_meta($attachment_id, '_ai_alt_review_score', true));
        if ($score <= 0){
            return null;
        }

        $stored_hash = get_post_meta($attachment_id, '_ai_alt_review_alt_hash', true);
        if ($current_alt !== ''){
            $current_hash = $this->hash_alt_text($current_alt);
            if ($stored_hash && !hash_equals($stored_hash, $current_hash)){
                $this->purge_review_meta($attachment_id);
                return null;
            }
        }

        $status     = sanitize_key(get_post_meta($attachment_id, '_ai_alt_review_status', true));
        $grade_raw  = get_post_meta($attachment_id, '_ai_alt_review_grade', true);
        $summary    = get_post_meta($attachment_id, '_ai_alt_review_summary', true);
        $model      = get_post_meta($attachment_id, '_ai_alt_review_model', true);
        $reviewed_at = get_post_meta($attachment_id, '_ai_alt_reviewed_at', true);

        $issues_raw = get_post_meta($attachment_id, '_ai_alt_review_issues', true);
        $issues = [];
        if ($issues_raw){
            $decoded = json_decode($issues_raw, true);
            if (is_array($decoded)){
                foreach ($decoded as $issue){
                    if (is_string($issue)){
                        $issue = sanitize_text_field($issue);
                        if ($issue !== ''){
                            $issues[] = $issue;
                        }
                    }
                }
            }
        }

        return [
            'score'   => max(0, min(100, $score)),
            'status'  => $status ?: null,
            'grade'   => is_string($grade_raw) ? sanitize_text_field($grade_raw) : null,
            'summary' => is_string($summary) ? sanitize_text_field($summary) : '',
            'issues'  => $issues,
            'model'   => is_string($model) ? sanitize_text_field($model) : '',
            'reviewed_at' => is_string($reviewed_at) ? $reviewed_at : '',
            'hash_present' => !empty($stored_hash),
        ];
    }

    private function evaluate_alt_health(int $attachment_id, string $alt): array{
        $alt = trim((string) $alt);
        if ($alt === ''){
            return [
                'score' => 0,
                'grade' => __('Missing', 'ai-alt-gpt'),
                'status' => 'critical',
                'issues' => [__('ALT text is missing.', 'ai-alt-gpt')],
                'heuristic' => [
                    'score' => 0,
                    'grade' => __('Missing', 'ai-alt-gpt'),
                    'status' => 'critical',
                    'issues' => [__('ALT text is missing.', 'ai-alt-gpt')],
                ],
                'review' => null,
            ];
        }

        $score = 100;
        $issues = [];

        $normalized = strtolower(trim($alt));
        $placeholder_pattern = '/^(test|testing|sample|example|dummy|placeholder|alt(?:\s+text)?|image|photo|picture|n\/a|none|lorem)$/';
        if ($normalized === '' || preg_match($placeholder_pattern, $normalized)){
            return [
                'score' => 0,
                'grade' => __('Critical', 'ai-alt-gpt'),
                'status' => 'critical',
                'issues' => [__('ALT text looks like placeholder content and must be rewritten.', 'ai-alt-gpt')],
                'heuristic' => [
                    'score' => 0,
                    'grade' => __('Critical', 'ai-alt-gpt'),
                    'status'=> 'critical',
                    'issues'=> [__('ALT text looks like placeholder content and must be rewritten.', 'ai-alt-gpt')],
                ],
                'review' => null,
            ];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($alt) : strlen($alt);
        if ($length < 45){
            $score -= 25;
            $issues[] = __('Too short – add a richer description (45+ characters).', 'ai-alt-gpt');
        } elseif ($length > 160){
            $score -= 15;
            $issues[] = __('Very long – trim to keep the description concise (under 160 characters).', 'ai-alt-gpt');
        }

        if (preg_match('/\b(image|picture|photo|screenshot)\b/i', $alt)){
            $score -= 10;
            $issues[] = __('Contains generic filler words like “image” or “photo”.', 'ai-alt-gpt');
        }

        if (preg_match('/\b(test|testing|sample|example|dummy|placeholder|lorem|alt text)\b/i', $alt)){
            $score = min($score - 60, 10);
            $issues[] = __('Contains placeholder wording such as “test” or “sample”. Replace with a real description.', 'ai-alt-gpt');
        }

        $word_count = str_word_count($alt, 0, '0123456789');
        if ($word_count < 4){
            $score -= 60;
            $score = min($score, 10);
            $issues[] = __('ALT text is extremely brief – add meaningful descriptive words.', 'ai-alt-gpt');
        } elseif ($word_count < 6){
            $score -= 40;
            $score = min($score, 25);
            $issues[] = __('ALT text is too short to convey the subject in detail.', 'ai-alt-gpt');
        } elseif ($word_count < 8){
            $score -= 30;
            $score = min($score, 45);
            $issues[] = __('ALT text could use a few more descriptive words.', 'ai-alt-gpt');
        }

        if ($score > 40 && $length < 30){
            $score = min($score, 40);
            $issues[] = __('Expand the description with one or two concrete details.', 'ai-alt-gpt');
        }

        $normalize = static function($value){
            $value = strtolower((string) $value);
            $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
            return trim(preg_replace('/\s+/', ' ', $value));
        };

        $normalized_alt = $normalize($alt);
        $title = get_the_title($attachment_id);
        if ($title && $normalized_alt !== ''){
            $normalized_title = $normalize($title);
            if ($normalized_title !== '' && $normalized_alt === $normalized_title){
                $score -= 12;
                $issues[] = __('Matches the attachment title – add more unique detail.', 'ai-alt-gpt');
            }
        }

        $file = get_attached_file($attachment_id);
        if ($file && $normalized_alt !== ''){
            $base = pathinfo($file, PATHINFO_FILENAME);
            $normalized_base = $normalize($base);
            if ($normalized_base !== '' && $normalized_alt === $normalized_base){
                $score -= 20;
                $issues[] = __('Matches the file name – rewrite it to describe the image.', 'ai-alt-gpt');
            }
        }

        if (!preg_match('/[a-z]{4,}/i', $alt)){
            $score -= 15;
            $issues[] = __('Lacks descriptive language – include meaningful nouns or adjectives.', 'ai-alt-gpt');
        }

        if (!preg_match('/\b[a-z]/i', $alt)){
            $score -= 20;
        }

        $score = max(0, min(100, $score));

        $status = $this->status_from_score($score);
        $grade  = $this->grade_from_status($status);

        if ($status === 'review' && empty($issues)){
            $issues[] = __('Give this ALT another look to ensure it reflects the image details.', 'ai-alt-gpt');
        } elseif ($status === 'critical' && empty($issues)){
            $issues[] = __('ALT text should be rewritten for accessibility.', 'ai-alt-gpt');
        }

        $heuristic = [
            'score' => $score,
            'grade' => $grade,
            'status'=> $status,
            'issues'=> array_values(array_unique($issues)),
        ];

        $review = $this->get_review_snapshot($attachment_id, $alt);
        if ($review && empty($review['hash_present']) && $heuristic['score'] < $review['score']){
            $review = null;
        }
        if ($review){
            $final_score = min($heuristic['score'], $review['score']);
            $review_status = $review['status'] ?: $this->status_from_score($review['score']);
            $final_status = $this->worst_status($heuristic['status'], $review_status);
            $final_grade  = $review['grade'] ?: $this->grade_from_status($final_status);

            $combined_issues = [];
            if (!empty($review['summary'])){
                $combined_issues[] = $review['summary'];
            }
            if (!empty($review['issues'])){
                $combined_issues = array_merge($combined_issues, $review['issues']);
            }
            $combined_issues = array_merge($combined_issues, $heuristic['issues']);
            $combined_issues = array_values(array_unique(array_filter($combined_issues)));

            return [
                'score' => $final_score,
                'grade' => $final_grade,
                'status'=> $final_status,
                'issues'=> $combined_issues,
                'heuristic' => $heuristic,
                'review'    => $review,
            ];
        }

        return [
            'score' => $heuristic['score'],
            'grade' => $heuristic['grade'],
            'status'=> $heuristic['status'],
            'issues'=> $heuristic['issues'],
            'heuristic' => $heuristic,
            'review'    => null,
        ];
    }

    private function status_from_score(int $score): string{
        if ($score >= 90){
            return 'great';
        }
        if ($score >= 75){
            return 'good';
        }
        if ($score >= 60){
            return 'review';
        }
        return 'critical';
    }

    private function grade_from_status(string $status): string{
        switch ($status){
            case 'great':
                return __('Excellent', 'ai-alt-gpt');
            case 'good':
                return __('Strong', 'ai-alt-gpt');
            case 'review':
                return __('Needs review', 'ai-alt-gpt');
            default:
                return __('Critical', 'ai-alt-gpt');
        }
    }

    private function worst_status(string $first, string $second): string{
        $weights = [
            'great' => 1,
            'good' => 2,
            'review' => 3,
            'critical' => 4,
        ];
        $first_weight = $weights[$first] ?? 2;
        $second_weight = $weights[$second] ?? 2;
        return $first_weight >= $second_weight ? $first : $second;
    }

    private function get_missing_attachment_ids($limit = 5){
        global $wpdb;
        $limit = intval($limit);
        if ($limit <= 0){
            $limit = 5;
        }

        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m
               ON (p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt')
             WHERE p.post_type = %s
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE %s
               AND (m.meta_value IS NULL OR TRIM(m.meta_value) = '')
             ORDER BY p.ID DESC
             LIMIT %d",
            'attachment', 'image/%', $limit
        );

        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    private function get_all_attachment_ids($limit = 5, $offset = 0){
        global $wpdb;
        $limit  = max(1, intval($limit));
        $offset = max(0, intval($offset));

        $sql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} gen ON gen.post_id = p.ID AND gen.meta_key = '_ai_alt_generated_at'
             WHERE p.post_type = %s
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE %s
             ORDER BY
                 CASE WHEN gen.meta_value IS NOT NULL THEN gen.meta_value ELSE p.post_date END DESC,
                 p.ID DESC
             LIMIT %d OFFSET %d",
            'attachment', 'image/%', $limit, $offset
        );

        $rows = $wpdb->get_col($sql);
        return array_map('intval', (array) $rows);
    }

    private function get_usage_rows($limit = 10, $include_all = false){
        global $wpdb;
        $limit = max(1, intval($limit));
        $sql = "SELECT p.ID,
                       tokens.meta_value AS tokens_total,
                       prompt.meta_value AS tokens_prompt,
                       completion.meta_value AS tokens_completion,
                       alt.meta_value AS alt_text,
                       src.meta_value AS source,
                       model.meta_value AS model,
                       gen.meta_value AS generated_at
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} tokens ON tokens.post_id = p.ID AND tokens.meta_key = '_ai_alt_tokens_total'
                LEFT JOIN {$wpdb->postmeta} prompt ON prompt.post_id = p.ID AND prompt.meta_key = '_ai_alt_tokens_prompt'
                LEFT JOIN {$wpdb->postmeta} completion ON completion.post_id = p.ID AND completion.meta_key = '_ai_alt_tokens_completion'
                LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
                LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_ai_alt_source'
                LEFT JOIN {$wpdb->postmeta} model ON model.post_id = p.ID AND model.meta_key = '_ai_alt_model'
                LEFT JOIN {$wpdb->postmeta} gen ON gen.post_id = p.ID AND gen.meta_key = '_ai_alt_generated_at'
                WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
                ORDER BY
                    CASE WHEN gen.meta_value IS NOT NULL THEN gen.meta_value ELSE p.post_date END DESC,
                    CAST(tokens.meta_value AS UNSIGNED) DESC";

        if (!$include_all){
            $sql .= $wpdb->prepare(' LIMIT %d', $limit);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)){
            return [];
        }

        return array_map(function($row){
            $generated = $row['generated_at'] ?? '';
            if ($generated){
                $generated = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $generated);
            }

            $source = sanitize_key($row['source'] ?? 'unknown');
            if (!$source){ $source = 'unknown'; }

            $thumb = wp_get_attachment_image_src($row['ID'], 'thumbnail');

            return [
                'id'         => intval($row['ID']),
                'title'      => get_the_title($row['ID']),
                'alt'        => $row['alt_text'] ?? '',
                'tokens'     => intval($row['tokens_total'] ?? 0),
                'prompt'     => intval($row['tokens_prompt'] ?? 0),
                'completion' => intval($row['tokens_completion'] ?? 0),
                'source'     => $source,
                'source_label' => $this->format_source_label($source),
                'source_description' => $this->format_source_description($source),
                'model'      => $row['model'] ?? '',
                'generated'  => $generated,
                'thumb'      => $thumb ? $thumb[0] : '',
                'details_url'=> add_query_arg('item', $row['ID'], admin_url('upload.php')) . '#attachment_alt',
                'view_url'   => get_attachment_link($row['ID']),
            ];
        }, $rows);
    }

    private function get_source_meta_map(){
        return [
            'auto'     => [
                'label' => __('Auto (upload)', 'ai-alt-gpt'),
                'description' => __('Generated automatically when the image was uploaded.', 'ai-alt-gpt'),
            ],
            'ajax'     => [
                'label' => __('Media Library (single)', 'ai-alt-gpt'),
                'description' => __('Triggered from the Media Library row action or attachment details screen.', 'ai-alt-gpt'),
            ],
            'bulk'     => [
                'label' => __('Media Library (bulk)', 'ai-alt-gpt'),
                'description' => __('Generated via the Media Library bulk action.', 'ai-alt-gpt'),
            ],
            'dashboard' => [
                'label' => __('Dashboard quick actions', 'ai-alt-gpt'),
                'description' => __('Generated from the dashboard buttons.', 'ai-alt-gpt'),
            ],
            'wpcli'    => [
                'label' => __('WP-CLI', 'ai-alt-gpt'),
                'description' => __('Generated via the wp ai-alt CLI command.', 'ai-alt-gpt'),
            ],
            'manual'   => [
                'label' => __('Manual / custom', 'ai-alt-gpt'),
                'description' => __('Generated by custom code or integration.', 'ai-alt-gpt'),
            ],
            'unknown'  => [
                'label' => __('Unknown', 'ai-alt-gpt'),
                'description' => __('Source not recorded for this ALT text.', 'ai-alt-gpt'),
            ],
        ];
    }

    private function format_source_label($key){
        $map = $this->get_source_meta_map();
        $key = sanitize_key($key ?: 'unknown');
        return $map[$key]['label'] ?? $map['unknown']['label'];
    }

    private function format_source_description($key){
        $map = $this->get_source_meta_map();
        $key = sanitize_key($key ?: 'unknown');
        return $map[$key]['description'] ?? $map['unknown']['description'];
    }

    public function handle_usage_export(){
        if (!$this->user_can_manage()){
            wp_die(__('You do not have permission to export usage data.', 'ai-alt-gpt'));
        }
        check_admin_referer('ai_alt_usage_export');

        $rows = $this->get_usage_rows(10, true);
        $filename = 'ai-alt-usage-' . gmdate('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Attachment ID', 'Title', 'ALT Text', 'Source', 'Tokens (Total)', 'Tokens (Prompt)', 'Tokens (Completion)', 'Model', 'Generated At']);
        foreach ($rows as $row){
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['alt'],
                $row['source'],
                $row['tokens'],
                $row['prompt'],
                $row['completion'],
                $row['model'],
                $row['generated'],
            ]);
        }
        fclose($output);
        exit;
    }

    private function redact_api_token($message){
        if (!is_string($message) || $message === ''){
            return $message;
        }

        $mask = function($token){
            $len = strlen($token);
            if ($len <= 8){
                return str_repeat('*', $len);
            }
            return substr($token, 0, 4) . str_repeat('*', $len - 8) . substr($token, -4);
        };

        $message = preg_replace_callback('/(Incorrect API key provided:\s*)(\S+)/i', function($matches) use ($mask){
            return $matches[1] . $mask($matches[2]);
        }, $message);

        $message = preg_replace_callback('/(sk-[A-Za-z0-9]{4})([A-Za-z0-9]{10,})([A-Za-z0-9]{4})/i', function($matches){
            return $matches[1] . str_repeat('*', strlen($matches[2])) . $matches[3];
        }, $message);

        return $message;
    }

    private function extract_json_object(string $content){
        $content = trim($content);
        if ($content === ''){
            return null;
        }

        if (stripos($content, '```') !== false){
            $content = preg_replace('/```json/i', '', $content);
            $content = str_replace('```', '', $content);
            $content = trim($content);
        }

        if ($content !== '' && $content[0] !== '{'){
            $start = strpos($content, '{');
            $end   = strrpos($content, '}');
            if ($start !== false && $end !== false && $end > $start){
                $content = substr($content, $start, $end - $start + 1);
            }
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)){
            return $decoded;
        }

        return null;
    }

    private function should_retry_without_image($error){
        if (!is_wp_error($error)){
            return false;
        }

        if ($error->get_error_code() !== 'api_error'){
            return false;
        }

        $message = strtolower($error->get_error_message());
        $needles = [
            'error while downloading', 
            'failed to download', 
            'unsupported image url',
            'http 500',
            'http 404',
            'http 403',
            'server error',
            'not found',
            'forbidden',
            'timeout',
            'connection refused'
        ];
        foreach ($needles as $needle){
            if (strpos($message, $needle) !== false){
                return true;
            }
        }

        $data = $error->get_error_data();
        if (is_array($data)){
            if (!empty($data['message']) && is_string($data['message'])){
                $msg = strtolower($data['message']);
                foreach ($needles as $needle){
                    if (strpos($msg, $needle) !== false){
                        return true;
                    }
                }
            }
            if (!empty($data['body']['error']['message']) && is_string($data['body']['error']['message'])){
                $msg = strtolower($data['body']['error']['message']);
                foreach ($needles as $needle){
                    if (strpos($msg, $needle) !== false){
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function build_inline_image_payload($attachment_id){
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)){
            return new \WP_Error('inline_image_missing', __('Unable to locate the image file for inline embedding.', 'ai-alt-gpt'));
        }

        $size = filesize($file);
        if ($size === false || $size <= 0){
            return new \WP_Error('inline_image_size', __('Unable to read the image size for inline embedding.', 'ai-alt-gpt'));
        }

        $limit = apply_filters('ai_alt_gpt_inline_image_limit', 1024 * 1024 * 2, $attachment_id, $file);
        if ($size > $limit){
            return new \WP_Error('inline_image_too_large', __('Image exceeds the inline embedding size limit.', 'ai-alt-gpt'), ['size' => $size, 'limit' => $limit]);
        }

        $contents = $this->download_image_with_fallback($file);
        if ($contents === false){
            return new \WP_Error('inline_image_read_failed', __('Unable to read the image file for inline embedding.', 'ai-alt-gpt'));
        }

        $mime = get_post_mime_type($attachment_id);
        if (empty($mime)){
            $mime = function_exists('mime_content_type') ? mime_content_type($file) : 'image/jpeg';
        }

        $base64 = base64_encode($contents);
        if (!$base64){
            return new \WP_Error('inline_image_encode_failed', __('Failed to encode the image for inline embedding.', 'ai-alt-gpt'));
        }

        unset($contents);

        return [
            'payload' => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $base64,
                ],
            ],
        ];
    }

    /**
     * Make OpenAI API request with rate limit handling and retry logic
     */
    private function make_openai_request(string $url, array $args, int $max_retries = 3): mixed {
        $retry_count = 0;
        $base_delay = 1; // Start with 1 second delay
        
        while ($retry_count <= $max_retries) {
            $response = wp_remote_post($url, $args);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);
            
            // Handle rate limiting (HTTP 429)
            if ($code === 429) {
                $retry_after = $this->extract_retry_after($response, $data);
                $delay = $retry_after ?: ($base_delay * pow(2, $retry_count)); // Exponential backoff
                
                if ($retry_count < $max_retries) {
                    $this->log_rate_limit($retry_count + 1, $delay, $data);
                    sleep($delay);
                    $retry_count++;
                    continue;
                } else {
                    return new \WP_Error(
                        'rate_limit_exceeded',
                        sprintf(
                            __('Rate limit exceeded. Please try again in %d seconds. Visit %s to check your usage limits.', 'ai-alt-gpt'),
                            $retry_after ?: 60,
                            'https://platform.openai.com/account/rate-limits'
                        )
                    );
                }
            }
            
            // Handle other HTTP errors
            if ($code >= 400) {
                $error_message = $data['error']['message'] ?? sprintf(__('HTTP %d error', 'ai-alt-gpt'), $code);
                return new \WP_Error('openai_api_error', $error_message);
            }
            
            // Success - return the response
            return $response;
        }
        
        return new \WP_Error('max_retries_exceeded', __('Maximum retry attempts exceeded', 'ai-alt-gpt'));
    }
    
    /**
     * Extract retry-after header or calculate from rate limit info
     */
    private function extract_retry_after($response, array $data): int {
        $headers = wp_remote_retrieve_headers($response);
        $retry_after = $headers['retry-after'] ?? null;
        
        if ($retry_after) {
            return intval($retry_after);
        }
        
        // Parse rate limit info from error response
        if (isset($data['error']['message'])) {
            $message = $data['error']['message'];
            if (preg_match('/try again in (\d+)ms/', $message, $matches)) {
                return intval($matches[1]) / 1000; // Convert ms to seconds
            }
            if (preg_match('/try again in (\d+)s/', $message, $matches)) {
                return intval($matches[1]);
            }
        }
        
        return 0; // No specific retry time found
    }
    
    /**
     * Log rate limit events for debugging
     */
    private function log_rate_limit(int $attempt, int $delay, array $data): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[AI Alt GPT] Rate limit hit - Attempt %d, waiting %d seconds. Error: %s',
                $attempt,
                $delay,
                $data['error']['message'] ?? 'Unknown rate limit error'
            ));
        }
    }

    /**
     * Download image with multiple fallback strategies for better reliability
     */
    private function download_image_with_fallback(string $file_path): string|false {
        // Strategy 1: Try file_get_contents for local files
        if (!filter_var($file_path, FILTER_VALIDATE_URL)) {
            $contents = @file_get_contents($file_path);
            if ($contents !== false) {
                return $contents;
            }
        }
        
        // Strategy 2: Use wp_remote_get for remote URLs with proper headers
        if (filter_var($file_path, FILTER_VALIDATE_URL)) {
            $response = wp_remote_get($file_path, [
                'timeout' => 30,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                'headers' => [
                    'Accept' => 'image/*',
                    'Cache-Control' => 'no-cache'
                ],
                'sslverify' => false, // Allow self-signed certificates
                'redirection' => 5
            ]);
            
            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200) {
                    return wp_remote_retrieve_body($response);
                } else {
                    $this->log_image_download_error($file_path, "HTTP {$code}", $response);
                }
            } else {
                $this->log_image_download_error($file_path, $response->get_error_message(), $response);
            }
        }
        
        // Strategy 3: Try cURL as last resort
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $file_path,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: image/*',
                    'Cache-Control: no-cache'
                ]
            ]);
            
            $contents = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($contents !== false && $http_code === 200) {
                return $contents;
            } else {
                $this->log_image_download_error($file_path, "cURL error: {$curl_error} (HTTP {$http_code})", null);
            }
        }
        
        return false;
    }
    
    /**
     * Log image download errors for debugging
     */
    private function log_image_download_error(string $file_path, string $error, $response = null): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = sprintf(
                '[AI Alt GPT] Image download failed for %s: %s',
                $file_path,
                $error
            );
            
            if ($response && is_array($response)) {
                $headers = wp_remote_retrieve_headers($response);
                $log_message .= ' | Headers: ' . wp_json_encode($headers->getAll());
            }
            
            error_log($log_message);
        }
    }

    private function review_alt_text_with_model(int $attachment_id, string $alt, string $image_strategy, $image_payload_used, array $opts, string $api_key){
        $alt = trim((string) $alt);
        if ($alt === ''){
            return new \WP_Error('review_skipped', __('ALT text is empty; skipped review.', 'ai-alt-gpt'));
        }

        $review_model = $opts['review_model'] ?? ($opts['model'] ?? 'gpt-4o-mini');
        $review_model = apply_filters('ai_alt_gpt_review_model', $review_model, $attachment_id, $opts);
        if (!$review_model){
            return new \WP_Error('review_model_missing', __('No review model configured.', 'ai-alt-gpt'));
        }

        $image_payload = $image_payload_used;
        if (!$image_payload) {
            if ($image_strategy === 'inline') {
                $inline = $this->build_inline_image_payload($attachment_id);
                if (!is_wp_error($inline)) {
                    $image_payload = $inline['payload'];
                }
            } else {
                $url = wp_get_attachment_url($attachment_id);
                if ($url) {
                    $image_payload = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $url,
                        ],
                    ];
                }
            }
        }

        $title = get_the_title($attachment_id);
        $file_path = get_attached_file($attachment_id);
        $filename = $file_path ? wp_basename($file_path) : '';

        $context_lines = [];
        if ($title){
            $context_lines[] = sprintf(__('Media title: %s', 'ai-alt-gpt'), $title);
        }
        if ($filename){
            $context_lines[] = sprintf(__('Filename: %s', 'ai-alt-gpt'), $filename);
        }

        $quoted_alt = str_replace('"', '\"', $alt);

        $instructions = "You are an accessibility QA assistant. Review the provided ALT text for the accompanying image. "
            . "Flag hallucinated details, inaccurate descriptions, missing primary subjects, demographic assumptions, or awkward phrasing. "
            . "Confirm the sentence mentions the main subject and at least one visible attribute such as colour, texture, motion, or background context. "
            . "Score strictly: reward ALT text only when it accurately and concisely describes the image. "
            . "If the ALT text contains placeholder wording (for example ‘test’, ‘sample’, ‘dummy text’, ‘image’, ‘photo’) anywhere in the sentence, or omits the primary subject, score it 10 or lower. "
            . "Extremely short descriptions (fewer than six words) should rarely exceed a score of 30.";

        $text_block = $instructions . "\n\n"
            . "ALT text candidate: \"" . $quoted_alt . "\"\n";

        if ($context_lines){
            $text_block .= implode("\n", $context_lines) . "\n";
        }

        $text_block .= "\nReturn valid JSON with keys: "
            . "score (integer 0-100), verdict (excellent, good, review, or critical), "
            . "summary (short sentence), and issues (array of short strings). "
            . "Do not include any additional keys or explanatory prose.";

        $user_content = [
            [
                'type' => 'text',
                'text' => $text_block,
            ],
        ];

        if ($image_payload){
            $user_content[] = $image_payload;
        }

        $body = [
            'model' => $review_model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an impartial accessibility QA reviewer. Always return strict JSON and be conservative when scoring.',
                ],
                [
                    'role' => 'user',
                    'content' => $user_content,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 280,
        ];

        $response = $this->make_openai_request('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 45,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)){
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code >= 300 || empty($data['choices'][0]['message']['content'])){
            $api_message = isset($data['error']['message']) ? $data['error']['message'] : ($raw_body ?: 'OpenAI review failed.');
            $api_message = $this->redact_api_token($api_message);
            return new \WP_Error('review_api_error', $api_message, ['status' => $code, 'body' => $data]);
        }

        $content = $data['choices'][0]['message']['content'];
        $parsed = $this->extract_json_object($content);
        if (!$parsed){
            return new \WP_Error('review_parse_failed', __('Unable to parse review response.', 'ai-alt-gpt'), ['response' => $content]);
        }

        $score = isset($parsed['score']) ? intval($parsed['score']) : 0;
        $score = max(0, min(100, $score));

        $verdict = isset($parsed['verdict']) ? strtolower(trim((string) $parsed['verdict'])) : '';
        $status_map = [
            'excellent' => 'great',
            'great'     => 'great',
            'good'      => 'good',
            'strong'    => 'good',
            'review'    => 'review',
            'needs review' => 'review',
            'warning'   => 'review',
            'critical'  => 'critical',
            'fail'      => 'critical',
            'poor'      => 'critical',
        ];
        $status = $status_map[$verdict] ?? null;
        if (!$status){
            $status = $this->status_from_score($score);
        }

        $summary = isset($parsed['summary']) ? sanitize_text_field($parsed['summary']) : '';
        if (!$summary && isset($parsed['justification'])){
            $summary = sanitize_text_field($parsed['justification']);
        }

        $issues = [];
        if (!empty($parsed['issues']) && is_array($parsed['issues'])){
            foreach ($parsed['issues'] as $issue){
                $issue = sanitize_text_field($issue);
                if ($issue !== ''){
                    $issues[] = $issue;
                }
            }
        }

        $issues = array_values(array_unique($issues));

        $usage_summary = [
            'prompt'     => intval($data['usage']['prompt_tokens'] ?? 0),
            'completion' => intval($data['usage']['completion_tokens'] ?? 0),
            'total'      => intval($data['usage']['total_tokens'] ?? 0),
        ];

        return [
            'score'   => $score,
            'status'  => $status,
            'grade'   => $this->grade_from_status($status),
            'summary' => $summary,
            'issues'  => $issues,
            'model'   => $review_model,
            'usage'   => $usage_summary,
            'verdict' => $verdict,
        ];
    }

    private function persist_generation_result(int $attachment_id, string $alt, array $usage_summary, string $source, string $model, string $image_strategy, $review_result): void{
        update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));
        update_post_meta($attachment_id, '_ai_alt_source', $source);
        update_post_meta($attachment_id, '_ai_alt_model', $model);
        update_post_meta($attachment_id, '_ai_alt_generated_at', current_time('mysql'));
        update_post_meta($attachment_id, '_ai_alt_tokens_prompt', $usage_summary['prompt']);
        update_post_meta($attachment_id, '_ai_alt_tokens_completion', $usage_summary['completion']);
        update_post_meta($attachment_id, '_ai_alt_tokens_total', $usage_summary['total']);

        if ($image_strategy === 'remote'){
            delete_post_meta($attachment_id, '_ai_alt_image_reference');
        } else {
            update_post_meta($attachment_id, '_ai_alt_image_reference', $image_strategy);
        }

        if (!is_wp_error($review_result)){
            update_post_meta($attachment_id, '_ai_alt_review_score', $review_result['score']);
            update_post_meta($attachment_id, '_ai_alt_review_status', $review_result['status']);
            update_post_meta($attachment_id, '_ai_alt_review_grade', $review_result['grade']);
            update_post_meta($attachment_id, '_ai_alt_review_summary', $review_result['summary']);
            update_post_meta($attachment_id, '_ai_alt_review_issues', wp_json_encode($review_result['issues']));
            update_post_meta($attachment_id, '_ai_alt_review_model', $review_result['model']);
            update_post_meta($attachment_id, '_ai_alt_reviewed_at', current_time('mysql'));
            update_post_meta($attachment_id, '_ai_alt_review_alt_hash', $this->hash_alt_text($alt));
            delete_post_meta($attachment_id, '_ai_alt_review_error');
            if (!empty($review_result['usage'])){
                $this->record_usage($review_result['usage']);
            }
        } else {
            update_post_meta($attachment_id, '_ai_alt_review_error', $review_result->get_error_message());
        }

        // Invalidate stats cache after persisting all generation data
        $this->invalidate_stats_cache();
    }

    public function maybe_generate_on_upload($attachment_id){
        $opts = get_option(self::OPTION_KEY, []);
        if (empty($opts['enable_on_upload'])) return;
        if (!$this->is_image($attachment_id)) return;
        $existing = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($existing && empty($opts['force_overwrite'])) return;
        $this->generate_and_save($attachment_id, 'auto');
    }

    public function generate_and_save($attachment_id, $source='manual', int $retry_count = 0, array $feedback = []){
        $opts = get_option(self::OPTION_KEY, []);
        $api_key = $opts['api_key'] ?? '';
        if (!$api_key) return new \WP_Error('no_api_key', 'OpenAI API key missing.');
        if (!$this->is_image($attachment_id)) return new \WP_Error('not_image', 'Attachment is not an image.');

        $model = apply_filters('ai_alt_gpt_model', $opts['model'] ?? 'gpt-4o-mini', $attachment_id, $opts);
        $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $prompt = $this->build_prompt($attachment_id, $opts, $existing_alt, $retry_count > 0, $feedback);

        if (!empty($opts['dry_run'])){
            update_post_meta($attachment_id, '_ai_alt_last_prompt', $prompt);
            update_post_meta($attachment_id, '_ai_alt_source', 'dry-run');
            update_post_meta($attachment_id, '_ai_alt_model', $model);
            update_post_meta($attachment_id, '_ai_alt_generated_at', current_time('mysql'));
            $this->stats_cache = null;
            return new \WP_Error('ai_alt_dry_run', __('Dry run enabled. Prompt stored for review; ALT text not updated.', 'ai-alt-gpt'), ['prompt' => $prompt]);
        }

        $include_image = apply_filters('ai_alt_gpt_include_image_url', true, $attachment_id, $opts);
        $image_url = '';
        if ($include_image){
            $image_url = wp_get_attachment_url($attachment_id) ?: '';
        }

        $make_request = function($image_payload) use ($prompt, $model, $api_key){
            $user_content = [
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ];

            if ($image_payload){
                $user_content[] = $image_payload;
            }

            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You write short, descriptive, accessible image ALT text.'],
                    ['role' => 'user',   'content' => $user_content],
                ],
                'temperature' => 0.3,
                'max_tokens'  => 80,
            ];

            $response = $this->make_openai_request('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 30,
                'body'    => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);

            if ($code >= 300 || empty($data['choices'][0]['message']['content'])) {
                $api_message = 'OpenAI API error';
                if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                    $api_message = $data['error']['message'];
                } elseif (isset($data['choices'][0]['message']['content'])) {
                    $api_message = $data['choices'][0]['message']['content'];
                } elseif (is_string($raw_body) && $raw_body !== ''){
                    $api_message = $raw_body;
                }

                $error_data = ['status' => $code];
                if (!empty($data)) {
                    $error_data['body'] = $data;
                } else {
                    $error_data['message'] = $raw_body;
                }

                $api_message = $this->redact_api_token($api_message);
                return new \WP_Error('api_error', $api_message, $error_data);
            }

            $alt = trim($data['choices'][0]['message']['content']);
            $alt = preg_replace('/^[\"\\' . "'" . ']+|[\"\\' . "'" . ']+$/', '', $alt);

            $usage_summary = [
                'prompt'     => intval($data['usage']['prompt_tokens'] ?? 0),
                'completion' => intval($data['usage']['completion_tokens'] ?? 0),
                'total'      => intval($data['usage']['total_tokens'] ?? 0),
            ];

            return [
                'alt'   => $alt,
                'usage' => $usage_summary,
            ];
        };

        $attempts = [];

        if ($include_image && $image_url){
            $attempts[] = function() use ($image_url){
                return [
                    'payload'  => [
                        'type' => 'image_url',
                        'image_url' => [ 'url' => $image_url ],
                    ],
                    'strategy' => 'remote',
                ];
            };
        }

        if ($include_image){
            $attempts[] = function() use ($attachment_id){
                $inline = $this->build_inline_image_payload($attachment_id);
                if (is_wp_error($inline)){
                    return $inline;
                }
                return [
                    'payload'  => $inline['payload'],
                    'strategy' => 'inline',
                ];
            };
        }

        $attempts[] = function(){
            return [
                'payload'  => null,
                'strategy' => 'omitted',
            ];
        };

        $result = null;
        $image_strategy = 'omitted';
        $image_payload_used = null;
        $last_error = null;

        foreach ($attempts as $builder){
            $candidate = $builder();
            if (is_wp_error($candidate)){
                $last_error = $candidate;
                continue;
            }

            $image_strategy = $candidate['strategy'];
            $result = $make_request($candidate['payload']);
            if (!is_wp_error($result)){
                $image_payload_used = $candidate['payload'];
            }

            if (!is_wp_error($result) && $existing_alt){
                $generated = trim($result['alt']);
                if (strcasecmp($generated, trim($existing_alt)) === 0){
                    $result = new \WP_Error('duplicate_alt', __('Generated ALT text matched the existing value.', 'ai-alt-gpt'));
                }
            }

            if (!is_wp_error($result)){
                break;
            }

            $last_error = $result;

            if ($result->get_error_code() !== 'duplicate_alt' && !$this->should_retry_without_image($result)){
                break;
            }
        }

        if (is_wp_error($result)){
            return $last_error ?: $result;
        }

        $usage_summary = $result['usage'];
        $alt = $result['alt'];

        $this->record_usage($usage_summary);

        $review_result = $this->review_alt_text_with_model($attachment_id, $alt, $image_strategy, $image_payload_used, $opts, $api_key);

        $should_retry = !is_wp_error($review_result)
            && $retry_count < self::QA_MAX_RETRY
            && (int) $review_result['score'] < self::QA_RETRY_THRESHOLD;

        if ($should_retry){
            $feedback_messages = [];
            if (!empty($review_result['summary'])){
                $feedback_messages[] = $review_result['summary'];
            }
            if (!empty($review_result['issues'])){
                $feedback_messages = array_merge($feedback_messages, $review_result['issues']);
            }
            $feedback_messages[] = sprintf(__('Previous attempt produced: “%s”', 'ai-alt-gpt'), $alt);

            return $this->generate_and_save($attachment_id, $source, $retry_count + 1, $feedback_messages);
        }

        $this->persist_generation_result($attachment_id, $alt, $usage_summary, $source, $model, $image_strategy, $review_result);

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
            if (is_wp_error($res)) {
                if ($res->get_error_code() === 'ai_alt_dry_run') { $count++; }
                else { $errors++; }
            } else { $count++; }
        }
        $redirect_to = add_query_arg(['ai_alt_generated' => $count, 'ai_alt_errors' => $errors], $redirect_to);
        return $redirect_to;
    }

    public function row_action_link($actions, $post){
        if ($post->post_type === 'attachment' && $this->is_image($post->ID)){
            $has_alt = (bool) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
            $generate_label   = __('Generate Alt Text (AI)', 'ai-alt-gpt');
            $regenerate_label = __('Regenerate Alt Text (AI)', 'ai-alt-gpt');
            $text = $has_alt ? $regenerate_label : $generate_label;
            $actions['ai_alt_generate_single'] = '<a href="#" class="ai-alt-generate" data-id="' . intval($post->ID) . '" data-has-alt="' . ($has_alt ? '1' : '0') . '" data-label-generate="' . esc_attr($generate_label) . '" data-label-regenerate="' . esc_attr($regenerate_label) . '">' . esc_html($text) . '</a>';
        }
        return $actions;
    }

    public function attachment_fields_to_edit($fields, $post){
        if (!$this->is_image($post->ID)){
            return $fields;
        }

        $has_alt = (bool) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
        $label_generate   = __('Generate Alt', 'ai-alt-gpt');
        $label_regenerate = __('Regenerate Alt', 'ai-alt-gpt');
        $current_label    = $has_alt ? $label_regenerate : $label_generate;
        $button = sprintf(
            '<button type="button" class="button ai-alt-generate" data-id="%1$d" data-has-alt="%2$d" data-label-generate="%3$s" data-label-regenerate="%4$s">%5$s</button>',
            intval($post->ID),
            $has_alt ? 1 : 0,
            esc_attr($label_generate),
            esc_attr($label_regenerate),
            esc_html($current_label)
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
                if (is_wp_error($alt)) {
                    if ($alt->get_error_code() === 'ai_alt_dry_run'){
                        return [
                            'id'      => $id,
                            'code'    => $alt->get_error_code(),
                            'message' => $alt->get_error_message(),
                            'prompt'  => $alt->get_error_data()['prompt'] ?? '',
                            'stats'   => $this->get_media_stats(),
                        ];
                    }
                    return $alt;
                }
                return [
                    'id'   => $id,
                    'alt'  => $alt,
                    'meta' => $this->prepare_attachment_snapshot($id),
                    'stats'=> $this->get_media_stats(),
                ];
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-alt/v1', '/list', [
            'methods'  => 'GET',
            'callback' => function($req){
                if (!$this->user_can_manage()){
                    return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                }

                $scope = $req->get_param('scope') === 'all' ? 'all' : 'missing';
                $limit = max(1, min(500, intval($req->get_param('limit') ?: 100)));

                if ($scope === 'missing'){
                    $ids = $this->get_missing_attachment_ids($limit);
                } else {
                    $ids = $this->get_all_attachment_ids($limit, 0);
                }

                return ['ids' => array_map('intval', $ids)];
            },
            'permission_callback' => function(){ return $this->user_can_manage(); },
        ]);

        register_rest_route('ai-alt/v1', '/stats', [
            'methods'  => 'GET',
            'callback' => function(){
                if (!$this->user_can_manage()){
                    return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                }
                return $this->get_media_stats();
            },
            'permission_callback' => function(){ return $this->user_can_manage(); },
        ]);
    }

    public function enqueue_admin($hook){
        $base_path = plugin_dir_path(__FILE__);
        $base_url  = plugin_dir_url(__FILE__);
        
        // Use minified assets in production, full versions when debugging
        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
        
        $l10n_common = [
            'reviewCue'           => __('Visit the ALT Library to double-check the wording.', 'ai-alt-gpt'),
            'statusReady'         => '',
            'previewAltHeading'   => __('Review generated ALT text', 'ai-alt-gpt'),
            'previewAltHint'      => __('Review the generated description before applying it to your media item.', 'ai-alt-gpt'),
            'previewAltApply'     => __('Use this ALT', 'ai-alt-gpt'),
            'previewAltCancel'    => __('Keep current ALT', 'ai-alt-gpt'),
            'previewAltDismissed' => __('Preview dismissed. Existing ALT kept.', 'ai-alt-gpt'),
            'previewAltShortcut'  => __('Shift + Enter for newline.', 'ai-alt-gpt'),
        ];

        if ($hook === 'upload.php'){
            $admin_file = "assets/ai-alt-admin{$suffix}.js";
            $admin_version = file_exists($base_path . $admin_file) ? filemtime($base_path . $admin_file) : '3.0.0';
            wp_enqueue_script('ai-alt-gpt-admin', $base_url . $admin_file, ['jquery'], $admin_version, true);
            wp_localize_script('ai-alt-gpt-admin', 'AI_ALT_GPT', [
                'nonce'     => wp_create_nonce('wp_rest'),
                'rest'      => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats' => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restMissing'=> esc_url_raw( add_query_arg(['scope' => 'missing'], rest_url('ai-alt/v1/list')) ),
                'restAll'    => esc_url_raw( add_query_arg(['scope' => 'all'], rest_url('ai-alt/v1/list')) ),
                'l10n'      => $l10n_common,
            ]);
        }

        if ($hook === 'media_page_ai-alt-gpt'){
            $css_file = "assets/ai-alt-dashboard{$suffix}.css";
            $js_file = "assets/ai-alt-dashboard{$suffix}.js";
            $admin_file = "assets/ai-alt-admin{$suffix}.js";
            
            $css_version = file_exists($base_path . $css_file) ? filemtime($base_path . $css_file) : '3.0.0';
            $js_version  = file_exists($base_path . $js_file) ? filemtime($base_path . $js_file) : '3.0.0';
            $admin_version = file_exists($base_path . $admin_file) ? filemtime($base_path . $admin_file) : '3.0.0';
            
            wp_enqueue_style('ai-alt-gpt-dashboard', $base_url . $css_file, [], $css_version);
            wp_enqueue_script('ai-alt-gpt-admin', $base_url . $admin_file, ['jquery'], $admin_version, true);
            wp_localize_script('ai-alt-gpt-admin', 'AI_ALT_GPT', [
                'nonce'     => wp_create_nonce('wp_rest'),
                'rest'      => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats' => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restMissing'=> esc_url_raw( add_query_arg(['scope' => 'missing'], rest_url('ai-alt/v1/list')) ),
                'restAll'    => esc_url_raw( add_query_arg(['scope' => 'all'], rest_url('ai-alt/v1/list')) ),
                'l10n'      => $l10n_common,
            ]);

            $stats_data = $this->get_media_stats();
            wp_enqueue_script('ai-alt-gpt-dashboard', $base_url . $js_file, ['jquery'], $js_version, true);
            wp_localize_script('ai-alt-gpt-dashboard', 'AI_ALT_GPT_DASH', [
                'nonce'       => wp_create_nonce('wp_rest'),
                'rest'        => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats'   => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restMissing' => esc_url_raw( add_query_arg(['scope' => 'missing'], rest_url('ai-alt/v1/list')) ),
                'restAll'     => esc_url_raw( add_query_arg(['scope' => 'all'], rest_url('ai-alt/v1/list')) ),
                'stats'       => $stats_data,
                'l10n'        => array_merge([
                    'processing'         => __('Generating ALT text…', 'ai-alt-gpt'),
                    'processingMissing'  => __('Generating ALT for #%d…', 'ai-alt-gpt'),
                    'error'              => __('Something went wrong. Check console for details.', 'ai-alt-gpt'),
                    'summary'            => __('Generated %1$d images (%2$d errors).', 'ai-alt-gpt'),
                    'restUnavailable'    => __('REST endpoint unavailable', 'ai-alt-gpt'),
                    'prepareBatch'       => __('Preparing image list…', 'ai-alt-gpt'),
                    'coverageCopy'       => __('of images currently include ALT text.', 'ai-alt-gpt'),
                    'noRequests'         => __('None yet', 'ai-alt-gpt'),
                    'noAudit'            => __('No usage data recorded yet.', 'ai-alt-gpt'),
                    'nothingToProcess'   => __('No images to process.', 'ai-alt-gpt'),
                    'batchStart'         => __('Starting batch…', 'ai-alt-gpt'),
                    'batchComplete'      => __('Batch complete.', 'ai-alt-gpt'),
                    'batchCompleteAt'    => __('Batch complete at %s', 'ai-alt-gpt'),
                    'completedItem'      => __('Finished #%d', 'ai-alt-gpt'),
                    'failedItem'         => __('Failed #%d', 'ai-alt-gpt'),
                    'loadingButton'      => __('Processing…', 'ai-alt-gpt'),
                ], $l10n_common),
            ]);
        }
    }

    public function wpcli_command($args, $assoc){
        if (!class_exists('WP_CLI')) return;
        $id  = isset($assoc['post_id']) ? intval($assoc['post_id']) : 0;
        if (!$id){
            \WP_CLI::error('Provide --post_id=<attachment_id>');
        }

        $res = $this->generate_and_save($id, 'wpcli');
        if (is_wp_error($res)) {
            if ($res->get_error_code() === 'ai_alt_dry_run') {
                \WP_CLI::success("ID $id dry-run: " . $res->get_error_message());
            } else {
                \WP_CLI::error($res->get_error_message());
            }
        } else {
            \WP_CLI::success("Generated ALT for $id: $res");
        }
    }
}

new AI_Alt_Text_Generator_GPT();

// Inline JS fallback to add row-action behaviour
add_action('admin_footer-upload.php', function(){
    ?>
    <script>
    (function($){
        function refreshDashboard(){
            if (!window.AI_ALT_GPT || !AI_ALT_GPT.restStats || !window.fetch){
                return;
            }
            fetch(AI_ALT_GPT.restStats, {
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': (AI_ALT_GPT.nonce || (window.wpApiSettings ? wpApiSettings.nonce : '')),
                    'Accept': 'application/json'
                }
            })
            .then(function(res){ return res.ok ? res.json() : null; })
            .then(function(data){
                if (!data){ return; }
                if (typeof window.dispatchEvent === 'function'){
                    try { window.dispatchEvent(new CustomEvent('ai-alt-stats-update', { detail: data })); } catch(e){}
                }
            })
            .catch(function(){});
        }

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
                '#attachments-' + id + '-alt',
                '[data-setting="alt"] textarea',
                '[data-setting="alt"] input',
                '[name="attachments[' + id + '][alt]"]',
                '[name="attachments[' + id + '][_wp_attachment_image_alt]"]',
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
                field.val(value);
                field.text(value);
                field.attr('value', value);
                field.trigger('input').trigger('change');
            }

            if (window.wp && wp.media && typeof wp.media.attachment === 'function'){
                var attachment = wp.media.attachment(id);
                if (attachment){
                    try { attachment.set('alt', value); } catch (err) {}
                }
            }
        }

        function pushNotice(type, message){
            if (window.wp && wp.data && wp.data.dispatch){
                try {
                    wp.data.dispatch('core/notices').createNotice(type, message, { isDismissible: true });
                    return;
                } catch(err) {}
            }
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            var $target = $('#wpbody-content').find('.wrap').first();
            if ($target.length){
                $target.prepend($notice);
            } else {
                $('#wpbody-content').prepend($notice);
            }
        }

        $(document).on('click', '.ai-alt-generate', function(e){
            e.preventDefault();
            if (!window.AI_ALT_GPT || !AI_ALT_GPT.rest){
                return pushNotice('error', 'AI ALT: REST URL missing.');
            }

            var btn = $(this);
            var id = btn.data('id');
            if (!id){ return pushNotice('error', 'AI ALT: Attachment ID missing.'); }

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
                        pushNotice('success', 'ALT generated: ' + data.alt);
                        if (!context.length){
                        location.reload();
                        }
                        refreshDashboard();
                    } else if (data && data.code === 'ai_alt_dry_run'){
                        pushNotice('info', data.message || 'Dry run enabled. Prompt stored for review.');
                        refreshDashboard();
                    } else {
                        var message = (data && (data.message || (data.data && data.data.message))) || 'Failed to generate ALT';
                        pushNotice('error', message);
                    }
                })
                .catch(function(err){
                    var message = (err && err.message) ? err.message : 'Request failed.';
                    pushNotice('error', message);
                })
                .then(function(){ restore(btn); });
        });
    })(jQuery);
    </script>
    <?php
});
