<?php
/**
 * Plugin Name: Farlo AI Alt Text Generator (GPT)
 * Description: Automatically generates concise, accessible ALT text for images using the OpenAI API. Includes auto-on-upload, Media Library bulk action, REST + WP-CLI, and a settings page.
 * Version: 3.0.0
 * Author: Farlo
 * License: GPL2
 */

if (!defined('ABSPATH')) { exit; }

class AI_Alt_Text_Generator_GPT {
    const OPTION_KEY = 'ai_alt_gpt_settings';
    const NONCE_KEY  = 'ai_alt_gpt_nonce';
    const CAPABILITY = 'manage_ai_alt_text';

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
            'guide'     => __('How to Use', 'ai-alt-gpt'),
            'library'   => __('ALT Library', 'ai-alt-gpt'),
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

                <div class="ai-alt-dashboard__viz">
                    <div class="ai-alt-progress" role="group" aria-labelledby="ai-alt-coverage-value">
                        <div class="ai-alt-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($coverage_numeric); ?>" aria-valuetext="<?php echo esc_attr($coverage_value_text); ?>" aria-describedby="ai-alt-coverage-summary">
                            <span style="width: <?php echo esc_attr($coverage_numeric); ?>%" aria-hidden="true"></span>
                        </div>
                        <p id="ai-alt-coverage-summary" class="ai-alt-dashboard__coverage"><strong id="ai-alt-coverage-value"><?php echo esc_html($coverage_text); ?></strong> <?php esc_html_e('of images currently include ALT text.', 'ai-alt-gpt'); ?></p>
                    </div>
                    <div class="ai-alt-chart">
                        <figure class="ai-alt-chart__figure">
                            <canvas id="ai-alt-coverage" width="220" height="220" aria-label="<?php esc_attr_e('Visual coverage chart', 'ai-alt-gpt'); ?>"></canvas>
                            <figcaption class="screen-reader-text"><?php echo esc_html($coverage_value_text); ?></figcaption>
                        </figure>
                        <div class="ai-alt-chart__legend" aria-hidden="true">
                            <span class="ai-alt-chart__dot ai-alt-chart__dot--with"></span> <?php esc_html_e('With ALT', 'ai-alt-gpt'); ?>
                            <span class="ai-alt-chart__dot ai-alt-chart__dot--missing"></span> <?php esc_html_e('Missing', 'ai-alt-gpt'); ?>
                        </div>
                    </div>
                </div>

                <div class="ai-alt-dashboard__actions">
                    <div class="ai-alt-dashboard__actions-buttons">
                        <button type="button" class="button button-primary ai-alt-button ai-alt-button--primary" data-action="generate-missing" <?php echo $stats['missing'] <= 0 ? 'disabled' : ''; ?>><?php esc_html_e('Generate ALT for Missing Images', 'ai-alt-gpt'); ?></button>
                        <button type="button" class="button button-secondary ai-alt-button ai-alt-button--outline" data-action="regenerate-all"><?php esc_html_e('Regenerate ALT for All Images', 'ai-alt-gpt'); ?></button>
                    </div>
                    <p class="ai-alt-dashboard__actions-note"><?php esc_html_e('Run a quick pass for gaps, then jump into the ALT Library to review every generated description before publishing.', 'ai-alt-gpt'); ?></p>
                </div>
                <div class="ai-alt-dashboard__status" data-progress-status role="status" aria-live="polite"><?php esc_html_e('Ready.', 'ai-alt-gpt'); ?></div>

            </div>
            <?php elseif ($tab === 'usage') : ?>
            <?php $audit_rows = $stats['audit'] ?? []; $export_url = wp_nonce_url(admin_url('admin-post.php?action=ai_alt_usage_export'), 'ai_alt_usage_export'); ?>
            <div class="ai-alt-dashboard ai-alt-dashboard--usage" data-stats='<?php echo esc_attr(wp_json_encode($stats)); ?>'>
                <div class="ai-alt-usage__intro">
                    <h2><?php esc_html_e('Usage snapshot', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('This tab highlights recent activity and token spend. For a full review and regeneration workflow, head to the ALT Library.', 'ai-alt-gpt'); ?></p>
                </div>
                <div class="ai-alt-usage" role="group" aria-label="<?php esc_attr_e('API usage summary', 'ai-alt-gpt'); ?>">
                    <div class="ai-alt-usage__metric">
                        <span><?php esc_html_e('API requests', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-usage__value ai-alt-usage__value--requests"><?php echo esc_html(number_format_i18n($stats['usage']['requests'] ?? 0)); ?></strong>
                    </div>
                    <div class="ai-alt-usage__metric">
                        <span><?php esc_html_e('Prompt tokens', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-usage__value ai-alt-usage__value--prompt"><?php echo esc_html(number_format_i18n($stats['usage']['prompt'] ?? 0)); ?></strong>
                    </div>
                    <div class="ai-alt-usage__metric">
                        <span><?php esc_html_e('Completion tokens', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-usage__value ai-alt-usage__value--completion"><?php echo esc_html(number_format_i18n($stats['usage']['completion'] ?? 0)); ?></strong>
                    </div>
                    <div class="ai-alt-usage__metric">
                        <span><?php esc_html_e('Last request', 'ai-alt-gpt'); ?></span>
                        <strong class="ai-alt-usage__value ai-alt-usage__value--last"><?php echo esc_html($stats['usage']['last_request_formatted'] ?? ($stats['usage']['last_request'] ?? __('None yet', 'ai-alt-gpt'))); ?></strong>
                    </div>
                    <div class="ai-alt-usage__link">
                        <a href="https://platform.openai.com/usage" target="_blank" rel="noopener noreferrer" class="ai-alt-usage__cta"><?php esc_html_e('View detailed usage in OpenAI dashboard', 'ai-alt-gpt'); ?></a>
                    </div>
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
                                <th scope="col"><?php esc_html_e('ALT Text', 'ai-alt-gpt'); ?></th>
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
                                                    <a href="<?php echo esc_url($row['details_url']); ?>"><?php echo esc_html($row['title'] ?: sprintf(__('Attachment #%d', 'ai-alt-gpt'), $row['id'])); ?></a>
                                                    <div class="ai-alt-audit__meta"><code>#<?php echo esc_html($row['id']); ?></code><?php if (!empty($row['view_url'])) : ?> · <a href="<?php echo esc_url($row['view_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Preview', 'ai-alt-gpt'); ?></a><?php endif; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="ai-alt-audit__alt"><?php echo esc_html($row['alt']); ?></td>
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
                    <h2><?php esc_html_e('Ship accurate ALT text in three quick passes', 'ai-alt-gpt'); ?></h2>
                    <p><?php esc_html_e('Start on the dashboard, let the plugin batch-generate suggestions, then spot-check everything in the ALT Library before publishing.', 'ai-alt-gpt'); ?></p>
                </div>

                <div class="ai-alt-guide__grid">
                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('1. Configure & connect', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Paste your OpenAI API key in Settings and choose a default model.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Pick a language and word limit that match your editorial tone.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Enable “Generate on upload” if you want new images handled automatically.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>

                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('2. Generate with guardrails', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Upload images or trigger the “Generate ALT for Missing Images” button on the dashboard.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Use the Regenerate buttons in the ALT Library or Media row actions for targeted updates.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Dry run mode is perfect for rehearsals—turn it off when you expect real writes.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>

                    <section class="ai-alt-guide__card">
                        <h3><?php esc_html_e('3. Review before you ship', 'ai-alt-gpt'); ?></h3>
                        <ol class="ai-alt-guide__steps">
                            <li><?php esc_html_e('Open the ALT Library to scan every generated sentence and tweak anything the AI missed.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Use the Usage & Reports tab to monitor token spend and export a CSV audit if stakeholders need visibility.', 'ai-alt-gpt'); ?></li>
                            <li><?php esc_html_e('Keep an eye on the dashboard coverage cards—stay close to 100% before you hit publish.', 'ai-alt-gpt'); ?></li>
                        </ol>
                    </section>
                </div>

                <div class="ai-alt-guide__footer">
                    <p><?php esc_html_e('Tip: after each generation pass, head to the ALT Library table to double-check the copy reads naturally and reflects the actual image content.', 'ai-alt-gpt'); ?></p>
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

                <table class="ai-alt-library__table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Attachment', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('ALT Text', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Source', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Tokens', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Last Generated', 'ai-alt-gpt'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'ai-alt-gpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ids) : ?>
                            <?php foreach ($ids as $attachment_id) :
                                $raw_alt = trim(get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
                                $alt     = $raw_alt !== '' ? $raw_alt : __('(empty)', 'ai-alt-gpt');
                                $title   = get_the_title($attachment_id);
                                $src_key = sanitize_key(get_post_meta($attachment_id, '_ai_alt_source', true) ?: 'unknown');
                                $src_label = $this->format_source_label($src_key);
                                $tokens  = intval(get_post_meta($attachment_id, '_ai_alt_tokens_total', true));
                                $generated_raw = get_post_meta($attachment_id, '_ai_alt_generated_at', true);
                                $generated = $generated_raw ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $generated_raw) : __('Never', 'ai-alt-gpt');
                                $edit_link = get_edit_post_link($attachment_id);
                                $view_link = add_query_arg('item', $attachment_id, admin_url('upload.php')) . '#attachment_alt';
                                $thumb     = wp_get_attachment_image_src($attachment_id, 'thumbnail');

                                $is_missing = $raw_alt === '';
                                $is_recent  = $generated_raw ? ( time() - strtotime($generated_raw) ) <= apply_filters('ai_alt_gpt_recent_highlight_window', 30 * MINUTE_IN_SECONDS ) : false;
                                $row_classes = [];
                                if ($is_recent) {
                                    $row_classes[] = 'ai-alt-library__row--recent';
                                }
                                if ($is_missing) {
                                    $row_classes[] = 'ai-alt-library__row--missing';
                                }
                                $row_class_attr = $row_classes ? ' class="' . esc_attr(implode(' ', $row_classes)) . '"' : '';
                                ?>
                                <tr<?php echo $row_class_attr; ?> data-id="<?php echo esc_attr($attachment_id); ?>">
                                    <td>
                                        <div class="ai-alt-library__attachment">
                                            <?php if ($thumb) : ?>
                                                <a href="<?php echo esc_url($view_link); ?>" class="ai-alt-library__thumb" aria-hidden="true"><img src="<?php echo esc_url($thumb[0]); ?>" alt="" loading="lazy" /></a>
                                            <?php endif; ?>
                                            <div class="ai-alt-library__details">
                                                <a href="<?php echo esc_url($edit_link); ?>">
                                                    <?php echo esc_html($title ?: sprintf(__('Attachment #%d', 'ai-alt-gpt'), $attachment_id)); ?>
                                                </a>
                                            <div class="ai-alt-library__meta">
                                                <code>#<?php echo esc_html($attachment_id); ?></code>
                                                    <?php if ($view_link) : ?>
                                                        · <a href="<?php echo esc_url($view_link); ?>" class="ai-alt-library__details-link"><?php esc_html_e('View', 'ai-alt-gpt'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($is_recent) : ?>
                                                    · <span class="ai-alt-library__recent-badge"><?php esc_html_e('New', 'ai-alt-gpt'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ai-alt-library__alt">
                                        <?php echo esc_html($alt); ?>
                                        <?php if ($is_missing) : ?>
                                            <span class="ai-alt-library__flag"><?php esc_html_e('Needs ALT review', 'ai-alt-gpt'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="ai-alt-badge ai-alt-badge--<?php echo esc_attr($src_key); ?>" title="<?php echo esc_attr($this->format_source_description($src_key)); ?>"><?php echo esc_html($src_label); ?></span></td>
                                    <td class="ai-alt-library__tokens"><?php echo esc_html(number_format_i18n($tokens)); ?></td>
                                    <td><?php echo esc_html($generated); ?></td>
                                    <td>
                                        <button type="button" class="button button-small ai-alt-regenerate-single" data-id="<?php echo esc_attr($attachment_id); ?>"><?php esc_html_e('Regenerate', 'ai-alt-gpt'); ?></button>
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
            <form method="post" action="options.php" class="ai-alt-settings">
                <?php settings_fields('ai_alt_gpt_group'); ?>
                <?php $o = wp_parse_args($opts, []); ?>
                <?php if (!empty($o['dry_run'])) : ?>
                    <div class="notice notice-warning"><p><?php esc_html_e('Dry run mode is enabled. Requests will log prompts but will not update ALT text.', 'ai-alt-gpt'); ?></p></div>
                <?php endif; ?>
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
                        <th scope="row"><label for="model"><?php esc_html_e('Model', 'ai-alt-gpt'); ?></label></th>
                        <td>
                            <?php
                            $model = $o['model'] ?? 'gpt-4o-mini';
                            $models = [
                                'gpt-4o-mini'  => __('gpt-4o-mini — fast, low cost, ideal default', 'ai-alt-gpt'),
                                'gpt-4o'       => __('gpt-4o — higher quality, more descriptive, higher cost', 'ai-alt-gpt'),
                                'gpt-4.1-mini' => __('gpt-4.1-mini — stronger reasoning with moderate cost', 'ai-alt-gpt'),
                            ];
                            ?>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[model]" id="model">
                                <?php foreach ($models as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($model, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Pick the OpenAI model used for generation. Keep gpt-4o-mini for balanced speed and spend, or step up to larger models when you need richer descriptions.', 'ai-alt-gpt'); ?></p>
                            <p class="description"><strong><?php esc_html_e('Cost guide', 'ai-alt-gpt'); ?>:</strong> <?php esc_html_e('gpt-4o-mini ≈ lowest tokens, fast responses. gpt-4o ≈ ~5× cost but best clarity. gpt-4.1-mini ≈ 2–3× cost, better reasoning. Actual billing follows OpenAI’s pricing.', 'ai-alt-gpt'); ?></p>
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
                            <?php $current_lang = $o['language'] ?? 'en-GB'; $is_custom_lang = !in_array($current_lang, ['en','en-GB'], true); ?>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[language]" id="language" class="ai-alt-language-select">
                                <option value="en" <?php selected($current_lang, 'en'); ?>><?php esc_html_e('English (US)', 'ai-alt-gpt'); ?></option>
                                <option value="en-GB" <?php selected($current_lang, 'en-GB'); ?>><?php esc_html_e('English (UK)', 'ai-alt-gpt'); ?></option>
                                <option value="custom" <?php selected(true, $is_custom_lang); ?>><?php esc_html_e('Custom…', 'ai-alt-gpt'); ?></option>
                            </select>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[language_custom]" id="language_custom" value="<?php echo esc_attr($is_custom_lang ? $current_lang : ($o['language_custom'] ?? '')); ?>" class="regular-text ai-alt-language-custom" placeholder="fr, de, es" />
                            <p class="description"><?php esc_html_e('Choose a preset or enter any ISO code/language name.', 'ai-alt-gpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Behaviour</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_on_upload]" <?php checked(!empty($o['enable_on_upload'])); ?>/> <?php esc_html_e('Generate on upload', 'ai-alt-gpt'); ?></label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[force_overwrite]" <?php checked(!empty($o['force_overwrite'])); ?>/> <?php esc_html_e('Overwrite existing ALT text', 'ai-alt-gpt'); ?></label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[dry_run]" <?php checked(!empty($o['dry_run'])); ?>/> <?php esc_html_e('Dry run (log prompts but do not update ALT text)', 'ai-alt-gpt'); ?></label>
                            <input type="hidden" id="ai_alt_gpt_nonce" value="<?php echo esc_attr($nonce); ?>"/>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="token_limit">Token alert threshold</label></th>
                        <td>
                            <input type="number" min="0" step="100" name="<?php echo esc_attr(self::OPTION_KEY); ?>[token_limit]" id="token_limit" value="<?php echo esc_attr($o['token_limit'] ?? 0); ?>" />
                            <p class="description"><?php esc_html_e('Send an admin notice when cumulative tokens exceed this value. Leave 0 to disable.', 'ai-alt-gpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="notify_email"><?php esc_html_e('Alert notifications', 'ai-alt-gpt'); ?></label></th>
                        <td>
                            <input type="email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_email]" id="notify_email" value="<?php echo esc_attr($o['notify_email'] ?? get_option('admin_email')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Email address to notify when token thresholds are exceeded.', 'ai-alt-gpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tone">Tone / Style</label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tone]" id="tone" value="<?php echo esc_attr($o['tone'] ?? 'professional, accessible'); ?>" class="regular-text" />
                            <p class="description">e.g. "professional, accessible" or "friendly, concise".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="custom_prompt"><?php esc_html_e('Additional prompt instructions', 'ai-alt-gpt'); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_prompt]" id="custom_prompt" rows="4" class="large-text code"><?php echo esc_textarea($o['custom_prompt'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Optional. Prepended to the AI instruction for every request. Supports plain text and basic Markdown.', 'ai-alt-gpt'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'ai-alt-gpt')); ?>
            </form>
            <hr/>
            <h2><?php esc_html_e('Other Tools', 'ai-alt-gpt'); ?></h2>
            <p><?php esc_html_e('Use the Media Library bulk action “Generate Alt Text (AI)” or run via WP-CLI: wp ai-alt generate --all', 'ai-alt-gpt'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_prompt($attachment_id, $opts){
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

        $context_bits = array_filter([$title, $caption, $parent]);
        $context = $context_bits ? ("Context: " . implode(' | ', $context_bits)) : '';

        $custom = trim($opts['custom_prompt'] ?? '');
        $instruction = "Write concise, descriptive ALT text in {$lang} for an image. "
               . "Limit to {$max} words. Tone: {$tone}. "
                    . "Only describe visible details; do not infer or speculate about the subject's industry, profession, or purpose unless explicitly provided in the context. "
               . "Avoid phrases like 'image of' or 'photo of'. "
                    . "Prefer proper nouns if present. Return only the ALT text.";

        $prompt = ($custom ? $custom . "\n\n" : '')
               . $instruction
               . "\nFilename: {$filename}\n{$context}";
        return apply_filters('ai_alt_gpt_prompt', $prompt, $attachment_id, $opts);
    }

    private function is_image($attachment_id){
        $mime = get_post_mime_type($attachment_id);
        return strpos((string)$mime, 'image/') === 0;
    }

    private function get_media_stats(){
        if (is_array($this->stats_cache)){
            return $this->stats_cache;
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

        return $this->stats_cache;
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
        $limit  = max(1, intval($limit));
        $offset = max(0, intval($offset));

        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return array_map('intval', $ids);
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
                ORDER BY gen.meta_value DESC, CAST(tokens.meta_value AS UNSIGNED) DESC";

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
            $image_url = wp_get_attachment_url($attachment_id);
        }

        $user_content = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        if ($image_url){
            $user_content[] = [
                'type' => 'image_url',
                'image_url' => [ 'url' => $image_url ],
            ];
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
            $api_message = 'OpenAI API error';
            if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                $api_message = $data['error']['message'];
            } elseif (isset($data['choices'][0]['message']['content'])) {
                $api_message = $data['choices'][0]['message']['content'];
            }

            $error_data = ['status' => $code];
            if (!empty($data)) {
                $error_data['body'] = $data;
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
        $this->record_usage($usage_summary);

        update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));

        update_post_meta($attachment_id, '_ai_alt_source', $source);
        update_post_meta($attachment_id, '_ai_alt_model', $model);
        update_post_meta($attachment_id, '_ai_alt_generated_at', current_time('mysql'));
        update_post_meta($attachment_id, '_ai_alt_tokens_prompt', $usage_summary['prompt']);
        update_post_meta($attachment_id, '_ai_alt_tokens_completion', $usage_summary['completion']);
        update_post_meta($attachment_id, '_ai_alt_tokens_total', $usage_summary['total']);
        $this->stats_cache = null;

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
                return ['id' => $id, 'alt' => $alt, 'stats' => $this->get_media_stats()];
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
        $l10n_common = [
            'reviewCue'  => __('Visit the ALT Library to double-check the wording.', 'ai-alt-gpt'),
            'statusReady'=> __('Ready.', 'ai-alt-gpt'),
        ];

        if ($hook === 'upload.php'){
            $admin_version = file_exists($base_path . 'assets/ai-alt-admin.js') ? filemtime($base_path . 'assets/ai-alt-admin.js') : '2.0.0';
            wp_enqueue_script('ai-alt-gpt-admin', $base_url . 'assets/ai-alt-admin.js', ['jquery'], $admin_version, true);
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
            $css_version = file_exists($base_path . 'assets/ai-alt-dashboard.css') ? filemtime($base_path . 'assets/ai-alt-dashboard.css') : '2.0.0';
            $js_version  = file_exists($base_path . 'assets/ai-alt-dashboard.js') ? filemtime($base_path . 'assets/ai-alt-dashboard.js') : '2.0.0';
            wp_enqueue_style('ai-alt-gpt-dashboard', $base_url . 'assets/ai-alt-dashboard.css', [], $css_version);

            $admin_version = file_exists($base_path . 'assets/ai-alt-admin.js') ? filemtime($base_path . 'assets/ai-alt-admin.js') : '2.0.0';
            wp_enqueue_script('ai-alt-gpt-admin', $base_url . 'assets/ai-alt-admin.js', ['jquery'], $admin_version, true);
            wp_localize_script('ai-alt-gpt-admin', 'AI_ALT_GPT', [
                'nonce'     => wp_create_nonce('wp_rest'),
                'rest'      => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats' => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restMissing'=> esc_url_raw( add_query_arg(['scope' => 'missing'], rest_url('ai-alt/v1/list')) ),
                'restAll'    => esc_url_raw( add_query_arg(['scope' => 'all'], rest_url('ai-alt/v1/list')) ),
                'l10n'      => $l10n_common,
            ]);

            $stats_data = $this->get_media_stats();
            wp_enqueue_script('ai-alt-gpt-dashboard', $base_url . 'assets/ai-alt-dashboard.js', ['jquery'], $js_version, true);
            wp_localize_script('ai-alt-gpt-dashboard', 'AI_ALT_GPT_DASH', [
                'nonce'       => wp_create_nonce('wp_rest'),
                'rest'        => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats'   => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restMissing' => esc_url_raw( add_query_arg(['scope' => 'missing'], rest_url('ai-alt/v1/list')) ),
                'restAll'     => esc_url_raw( add_query_arg(['scope' => 'all'], rest_url('ai-alt/v1/list')) ),
                'stats'       => $stats_data,
                'l10n'        => array_merge([
                    'processing'       => __('Generating ALT text…', 'ai-alt-gpt'),
                    'error'            => __('Something went wrong. Check console for details.', 'ai-alt-gpt'),
                    'summary'          => __('Generated %1$d images (%2$d errors).', 'ai-alt-gpt'),
                    'restUnavailable'  => __('REST endpoint unavailable', 'ai-alt-gpt'),
                    'coverageCopy'     => __('of images currently include ALT text.', 'ai-alt-gpt'),
                    'noRequests'       => __('None yet', 'ai-alt-gpt'),
                    'noAudit'          => __('No usage data recorded yet.', 'ai-alt-gpt'),
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
