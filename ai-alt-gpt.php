<?php
/**
 * Plugin Name: Farlo AI Alt Text Generator (GPT)
 * Description: Automatically generates concise, accessible ALT text for images using the OpenAI API. Includes auto-on-upload, Media Library bulk action, REST + WP-CLI, and a settings page.
 * Version: 2.0.0
 * Author: Ben O
 * License: GPL2
 */

if (!defined('ABSPATH')) { exit; }

class AI_Alt_Text_Generator_GPT {
    const OPTION_KEY = 'ai_alt_gpt_settings';
    const NONCE_KEY  = 'ai_alt_gpt_nonce';
    const QUEUE_OPTION = 'ai_alt_gpt_queue';
    const CRON_HOOK = 'ai_alt_gpt_process_queue';
    const CRON_WATCH_HOOK = 'ai_alt_gpt_queue_watchdog';
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
        add_action(self::CRON_HOOK, [$this, 'process_queue']);
        add_action(self::CRON_WATCH_HOOK, [$this, 'queue_watchdog']);
        add_action('admin_init', [$this, 'maybe_display_threshold_notice']);
        add_action('admin_post_ai_alt_usage_export', [$this, 'handle_usage_export']);
        add_action('init', [$this, 'ensure_capability']);
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);

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

    private function get_queue(){
        $queue = get_option(self::QUEUE_OPTION, []);
        return (is_array($queue) && !empty($queue['scope'])) ? $queue : [];
    }

    private function save_queue($queue){
        if (empty($queue)){
            delete_option(self::QUEUE_OPTION);
        } else {
            update_option(self::QUEUE_OPTION, $queue, false);
        }
        $this->stats_cache = null;
    }

    private function start_queue($scope, $batch = 5){
        $queue = [
            'scope'      => $scope,
            'batch'      => max(1, intval($batch)),
            'cursor'     => 0,
            'processed'  => 0,
            'errors'     => 0,
            'attempted'  => 0,
            'last_messages' => [],
            'last_run'   => null,
            'started_at' => current_time('mysql'),
        ];
        $this->save_queue($queue);
        // Kick off immediately so the queue advances even if WP-Cron is disabled.
        $this->process_queue();

        if (!empty($this->get_queue()) && !wp_next_scheduled(self::CRON_HOOK)){
            wp_schedule_single_event(time() + 1, self::CRON_HOOK);
        }
    }

    public function process_queue(){
        $queue = $this->get_queue();
        if (empty($queue)){
            return;
        }

        $batch = max(1, intval($queue['batch'] ?? 5));
        $prevErrors = intval($queue['errors'] ?? 0);
        $prevProcessed = intval($queue['processed'] ?? 0);
        $ids = [];

        $cursorBase = intval($queue['cursor'] ?? 0);
        if ($queue['scope'] === 'all'){
            $ids = $this->get_all_attachment_ids($batch, $cursorBase);
        } else {
            $ids = $this->get_missing_attachment_ids($batch);
        }

        if (empty($ids)){
            $this->queue_finish($queue, [__('Queue empty or attachments already processed.', 'ai-alt-gpt')]);
            return;
        }

        $messages = [];
        $retries = intval($queue['retries'] ?? 0);
        $processedThisBatch = 0;

        foreach ($ids as $index => $aid){
            $queue['attempted'] = isset($queue['attempted']) ? $queue['attempted'] + 1 : 1;

            $res = $this->generate_and_save($aid, 'queue');
            if (!is_wp_error($res)){
                $queue['processed'] = isset($queue['processed']) ? $queue['processed'] + 1 : 1;
                $processedThisBatch++;
                $queue['retries'] = 0;
                continue;
            }

            $error_code = $res->get_error_code();
            $message = $res->get_error_message();
            $data = $res->get_error_data();

            if ($error_code === 'ai_alt_dry_run'){
                $queue['processed'] = isset($queue['processed']) ? $queue['processed'] + 1 : 1;
                $processedThisBatch++;
                $queue['retries'] = 0;
                $messages[] = sprintf('ID %d dry-run: %s', $aid, $message);
                continue;
            }

            $queue['errors'] = isset($queue['errors']) ? $queue['errors'] + 1 : 1;

            if ($error_code === 'no_api_key'){
                $messages[] = sprintf('ID %d halted: API key missing.', $aid);
                if ($queue['scope'] === 'all'){
                    $queue['cursor'] = $cursorBase + $processedThisBatch;
                }
                $this->queue_finish(
                    $queue,
                    $messages,
                    [
                        'subject' => __('AI Alt Text queue halted (missing API key)', 'ai-alt-gpt'),
                        'body'    => __('The queue stopped because an API key is not configured.', 'ai-alt-gpt'),
                    ]
                );
                return;
            }

            if ($error_code === 'api_error'){
                $detail = isset($data['body']['error']['message']) ? $data['body']['error']['message'] : $message;
                $messages[] = sprintf('ID %d API error: %s', $aid, $detail);

                if ($retries < 3){
                    $queue['retries'] = $retries + 1;
                    if ($queue['scope'] === 'all'){
                        $queue['cursor'] = $cursorBase + $processedThisBatch;
                    }
                    $delay = max(5, 5 * $queue['retries']);
                    $this->queue_reschedule($queue, $messages, $delay);
                    return;
                }

                if ($queue['scope'] === 'all'){
                    $queue['cursor'] = $cursorBase + $processedThisBatch;
                }
                $this->queue_finish(
                    $queue,
                    $messages,
                    [
                        'subject' => __('AI Alt Text queue halted (API error)', 'ai-alt-gpt'),
                        'body'    => sprintf(__('Processing stopped due to an API error on attachment %1$d: %2$s', 'ai-alt-gpt'), $aid, $detail),
                    ]
                );
                return;
            }

            $messages[] = sprintf('ID %d error: %s', $aid, $message);
        }

        if ($queue['scope'] === 'all'){
            $queue['cursor'] = $cursorBase + count($ids);
        }

        $queue['retries'] = 0;

        $this->append_queue_messages($queue, $messages);
        $this->save_queue($queue);

        $stats = $this->get_media_stats();
        $done = false;
        if ($queue['scope'] === 'all'){
            $done = ($queue['cursor'] >= ($stats['total'] ?? 0));
        } else {
            $done = (($stats['missing'] ?? 0) <= 0);
        }

        $errorsThisRun = intval($queue['errors'] ?? 0) - $prevErrors;
        $processedThisRun = intval($queue['processed'] ?? 0) - $prevProcessed;
        if (!$done && $processedThisRun === 0 && $errorsThisRun >= count($ids)){
            // Avoid infinite loops when every attempt fails.
            $this->queue_finish(
                $queue,
                [__('Queue halted after repeated errors in the last batch.', 'ai-alt-gpt')],
                [
                    'subject' => __('AI Alt Text queue halted', 'ai-alt-gpt'),
                    'body'    => __('Background queue stopped because all attempts in the last batch failed. Please review logs or regenerate manually.', 'ai-alt-gpt'),
                ]
            );
            return;
        }

        if ($done){
            if (!empty($queue['processed'])){
                $this->send_notification(
                    __('AI Alt Text queue completed', 'ai-alt-gpt'),
                    sprintf(
                        __('Queue scope: %1$s. Processed %2$d images with %3$d errors.', 'ai-alt-gpt'),
                        $queue['scope'],
                        intval($queue['processed']),
                        intval($queue['errors'])
                    )
                );
            }
            $this->queue_finish($queue);
            return;
        }

        wp_schedule_single_event(time() + max(5, $batch), self::CRON_HOOK);
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
        if (!wp_next_scheduled(self::CRON_WATCH_HOOK)){
            wp_schedule_event(time() + 60, 'ai_alt_gpt_minute', self::CRON_WATCH_HOOK);
        }
    }

    private function append_queue_messages(array &$queue, array $messages){
        $messages = array_filter($messages);
        if (!empty($messages)){
            $existing = (array)($queue['last_messages'] ?? []);
            $queue['last_messages'] = array_slice(array_merge($messages, $existing), 0, 5);
        }
        $queue['last_run'] = current_time('mysql');
    }

    private function queue_finish(array &$queue, array $messages = [], array $notification = []){
        $this->append_queue_messages($queue, $messages);
        $this->save_queue([]);

        if (!empty($notification['subject']) && !empty($notification['body'])){
            $this->send_notification($notification['subject'], $notification['body']);
        }
    }

    private function queue_reschedule(array &$queue, array $messages = [], int $delay = 5){
        $delay = max(1, $delay);
        $this->append_queue_messages($queue, $messages);
        $this->save_queue($queue);
        wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
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

    public function register_cron_schedules($schedules){
        if (!isset($schedules['ai_alt_gpt_minute'])){
            $schedules['ai_alt_gpt_minute'] = [
                'interval' => 60,
                'display'  => __('Every Minute (AI ALT)', 'ai-alt-gpt'),
            ];
        }
        return $schedules;
    }

    public function deactivate(){
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_WATCH_HOOK);
    }

    public function queue_watchdog(){
        $queue = $this->get_queue();
        if (empty($queue) || empty($queue['active'])){
            return;
        }
        $last_run = !empty($queue['last_run']) ? strtotime($queue['last_run']) : 0;
        if (!$last_run || (time() - $last_run) >= 90){
            $this->process_queue();
        }
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
            'use_background_queue' => true,
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

        if (!wp_next_scheduled(self::CRON_WATCH_HOOK)){
            wp_schedule_event(time() + 60, 'ai_alt_gpt_minute', self::CRON_WATCH_HOOK);
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
                $out['use_background_queue'] = !empty($input['use_background_queue']);
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
                $queue_active = !empty($stats['queue']['active']);
                $queue_scope_label = $stats['queue']['scope_label'] ?? '';
                $queue_batch = isset($stats['queue']['batch']) ? number_format_i18n(intval($stats['queue']['batch'])) : '';
                $queue_processed = isset($stats['queue']['processed']) ? number_format_i18n(intval($stats['queue']['processed'])) : '';
                $queue_errors = isset($stats['queue']['errors']) ? number_format_i18n(intval($stats['queue']['errors'])) : '';
                $queue_last_run = $stats['queue']['last_run_formatted'] ?? '';
                $queue_next_run = $stats['queue']['next_run'] ?? '';
                $coverage_numeric = max(0, min(100, floatval($stats['coverage'])));
                $coverage_decimals = $coverage_numeric === floor($coverage_numeric) ? 0 : 1;
                $coverage_display = number_format_i18n($coverage_numeric, $coverage_decimals);
                /* translators: %s: Percentage value */
                $coverage_text = $coverage_display . '%';
                /* translators: %s: Percentage value */
                $coverage_value_text = sprintf(__('ALT coverage at %s', 'ai-alt-gpt'), $coverage_text);
            ?>
            <div class="ai-alt-dashboard ai-alt-dashboard--primary" data-stats='<?php echo esc_attr(wp_json_encode($stats)); ?>'>
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
                    <div class="ai-alt-actions__buttons">
                        <button type="button" class="button button-primary ai-alt-generate-missing" data-total="<?php echo esc_attr($stats['missing']); ?>" <?php disabled($stats['missing'] <= 0); ?>><?php esc_html_e('Generate ALT for Missing Images', 'ai-alt-gpt'); ?></button>
                        <button type="button" class="button ai-alt-regenerate-all" data-total="<?php echo esc_attr($stats['total']); ?>" <?php disabled($stats['total'] <= 0); ?>><?php esc_html_e('Regenerate ALT for All Images', 'ai-alt-gpt'); ?></button>
                        <button type="button" class="button button-secondary ai-alt-stop-queue<?php echo $queue_active ? '' : ' is-hidden'; ?>" data-label-default="<?php esc_attr_e('Force Stop', 'ai-alt-gpt'); ?>" data-label-busy="<?php esc_attr_e('Stopping…', 'ai-alt-gpt'); ?>" <?php disabled(!$queue_active); ?>><?php esc_html_e('Force Stop', 'ai-alt-gpt'); ?></button>
                        <button type="button" class="button ai-alt-queue-run<?php echo $queue_active ? '' : ' is-hidden'; ?>" data-label-default="<?php esc_attr_e('Run Batch Now', 'ai-alt-gpt'); ?>" data-label-busy="<?php esc_attr_e('Running…', 'ai-alt-gpt'); ?>" <?php disabled(!$queue_active); ?>><?php esc_html_e('Run Batch Now', 'ai-alt-gpt'); ?></button>
                    </div>
                    <div class="ai-alt-dashboard__status" role="status" aria-live="polite"></div>
                </div>

                <div class="ai-alt-queue-summary<?php echo $queue_active ? '' : ' is-hidden'; ?>" aria-live="polite">
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Scope', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="scope"><?php echo esc_html($queue_scope_label ?: __('N/A', 'ai-alt-gpt')); ?></span>
                    </div>
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Batch size', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="batch"><?php echo $queue_batch ? esc_html($queue_batch) : esc_html__('N/A', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Processed', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="processed"><?php echo $queue_processed ? esc_html($queue_processed) : esc_html__('N/A', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Errors', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="errors"><?php echo $queue_errors ? esc_html($queue_errors) : esc_html__('N/A', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Last run', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="last_run"><?php echo $queue_last_run ? esc_html($queue_last_run) : esc_html__('Pending', 'ai-alt-gpt'); ?></span>
                    </div>
                    <div class="ai-alt-queue-summary__item">
                        <span class="ai-alt-queue-summary__label"><?php esc_html_e('Next run', 'ai-alt-gpt'); ?></span>
                        <span class="ai-alt-queue-summary__value" data-queue-field="next_run"><?php echo $queue_next_run ? esc_html($queue_next_run) : esc_html__('Waiting for cron', 'ai-alt-gpt'); ?></span>
                    </div>
                </div>

                <div class="ai-alt-queue-log<?php echo $queue_active ? '' : ' is-hidden'; ?>" data-queue-log="true">
                    <h4><?php esc_html_e('Recent Queue Messages', 'ai-alt-gpt'); ?></h4>
                    <p class="description"><?php esc_html_e('Latest status for debugging slow or stalled queues.', 'ai-alt-gpt'); ?></p>
                    <ul>
                        <?php if (!empty($stats['queue']['last_messages'])) : ?>
                            <?php foreach ($stats['queue']['last_messages'] as $msg) : ?><li><?php echo esc_html($msg); ?></li><?php endforeach; ?>
                        <?php else : ?>
                            <li><?php esc_html_e('No messages recorded yet. Trigger “Run Batch Now” to execute a batch immediately.', 'ai-alt-gpt'); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <?php elseif ($tab === 'usage') : ?>
            <?php $audit_rows = $stats['audit'] ?? []; $export_url = wp_nonce_url(admin_url('admin-post.php?action=ai_alt_usage_export'), 'ai_alt_usage_export'); ?>
            <div class="ai-alt-dashboard ai-alt-dashboard--usage" data-stats='<?php echo esc_attr(wp_json_encode($stats)); ?>'>
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
                                            <?php if (!empty($row['url'])) : ?>
                                                <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['title']); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html($row['title']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ai-alt-audit__alt"><?php echo esc_html($row['alt']); ?></td>
                                        <td class="ai-alt-audit__source"><span class="ai-alt-badge ai-alt-badge--<?php echo esc_attr($row['source'] ?: 'unknown'); ?>"><?php echo esc_html($row['source'] ?: __('Unknown', 'ai-alt-gpt')); ?></span></td>
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
                                $alt   = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                                $alt   = $alt ? $alt : __('(empty)', 'ai-alt-gpt');
                                $title = get_the_title($attachment_id);
                                $src   = get_post_meta($attachment_id, '_ai_alt_source', true);
                                $tokens = intval(get_post_meta($attachment_id, '_ai_alt_tokens_total', true));
                                $generated = get_post_meta($attachment_id, '_ai_alt_generated_at', true);
                                $generated = $generated ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $generated) : __('Never', 'ai-alt-gpt');
                                $edit_link = get_edit_post_link($attachment_id);
                                $view_link = wp_get_attachment_url($attachment_id);
                                ?>
                                <tr>
                                    <td>
                                        <div class="ai-alt-library__attachment">
                                            <a href="<?php echo esc_url($edit_link); ?>">
                                                <?php echo esc_html($title ?: sprintf(__('Attachment #%d', 'ai-alt-gpt'), $attachment_id)); ?>
                                            </a>
                                            <div class="ai-alt-library__meta">
                                                <code>#<?php echo esc_html($attachment_id); ?></code>
                                                <?php if ($view_link) : ?>
                                                    · <a href="<?php echo esc_url($view_link); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View', 'ai-alt-gpt'); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ai-alt-library__alt"><?php echo esc_html($alt); ?></td>
                                    <td><span class="ai-alt-badge ai-alt-badge--<?php echo esc_attr($src ?: 'unknown'); ?>"><?php echo esc_html($src ?: __('Unknown', 'ai-alt-gpt')); ?></span></td>
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
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[use_background_queue]" <?php checked(!empty($o['use_background_queue'])); ?>/> <?php esc_html_e('Use background queue for large batches', 'ai-alt-gpt'); ?></label>
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
                            <p class="description"><?php esc_html_e('Email address to notify when queues finish or token thresholds are exceeded.', 'ai-alt-gpt'); ?></p>
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

        $queue = $this->get_queue();
        $next_queue_run = $queue ? wp_next_scheduled(self::CRON_HOOK) : null;
        $queue_scope_label = '';
        if (!empty($queue['scope'])){
            switch ($queue['scope']){
                case 'all':
                    $queue_scope_label = __('All images', 'ai-alt-gpt');
                    break;
                case 'missing':
                    $queue_scope_label = __('Missing ALT only', 'ai-alt-gpt');
                    break;
                default:
                    $queue_scope_label = ucwords(str_replace(['_', '-'], ' ', (string) $queue['scope']));
            }
        }
        $queue_started_formatted = !empty($queue['started_at']) ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $queue['started_at']) : null;
        $queue_last_run_formatted = !empty($queue['last_run']) ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $queue['last_run']) : null;

        $this->stats_cache = [
            'total'     => $total,
            'with_alt'  => $with_alt,
            'missing'   => $missing,
            'generated' => $generated,
            'coverage'  => $coverage,
            'usage'     => $usage,
            'token_limit' => intval($opts['token_limit'] ?? 0),
            'use_background_queue' => !empty($opts['use_background_queue']),
            'queue' => [
                'active'    => !empty($queue),
                'scope'     => $queue['scope'] ?? '',
                'batch'     => intval($queue['batch'] ?? 5),
                'processed' => intval($queue['processed'] ?? 0),
                'errors'    => intval($queue['errors'] ?? 0),
                'attempted' => intval($queue['attempted'] ?? 0),
                'cursor'    => intval($queue['cursor'] ?? 0),
                'started_at'=> $queue['started_at'] ?? null,
                'started_at_formatted' => $queue_started_formatted,
                'next_run'  => $next_queue_run ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_queue_run) : null,
                'timestamp' => $next_queue_run,
                'last_run'  => $queue['last_run'] ?? null,
                'last_run_formatted' => $queue_last_run_formatted,
                'scope_label' => $queue_scope_label,
                'last_messages' => $queue['last_messages'] ?? [],
            ],
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
                ORDER BY CAST(tokens.meta_value AS UNSIGNED) DESC";

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

            return [
                'id'         => intval($row['ID']),
                'title'      => get_the_title($row['ID']),
                'alt'        => $row['alt_text'] ?? '',
                'tokens'     => intval($row['tokens_total'] ?? 0),
                'prompt'     => intval($row['tokens_prompt'] ?? 0),
                'completion' => intval($row['tokens_completion'] ?? 0),
                'source'     => $source,
                'model'      => $row['model'] ?? '',
                'generated'  => $generated,
                'url'        => get_attachment_link($row['ID']),
            ];
        }, $rows);
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

        register_rest_route('ai-alt/v1', '/generate-missing', [
            'methods'  => 'POST',
            'callback' => function($req){
                if (!current_user_can('upload_files')){
                    return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                }

                $batch = max(1, min(20, intval($req->get_param('batch')) ?: 5));
                $scope = $req->get_param('scope') === 'all' ? 'all' : 'missing';
                $cursor = max(0, intval($req->get_param('cursor')));
                $opts = get_option(self::OPTION_KEY, []);
                $use_queue = !empty($opts['use_background_queue']);
                $queue = $this->get_queue();

                if (!$use_queue && !empty($queue)){
                    $this->save_queue([]);
                }

                if ($use_queue){
                    if (empty($queue) || $queue['scope'] !== $scope){
                        $this->start_queue($scope, $batch);
                    }
                    return [
                        'queued' => true,
                        'scope'  => $scope,
                        'stats'  => $this->get_media_stats(),
                        'queue'  => $this->get_queue(),
                    ];
                }

                if ($scope === 'all'){
                    $ids = $this->get_all_attachment_ids($batch, $cursor);
                    $attempted = count($ids);
                } else {
                    $ids = $this->get_missing_attachment_ids($batch);
                    $attempted = count($ids);
                }

                if (empty($ids)){
                    return [
                        'processed'   => 0,
                        'errors'      => 0,
                        'attempted'   => 0,
                        'completed'   => true,
                        'stats'       => $this->get_media_stats(),
                        'scope'       => $scope,
                        'next_cursor' => $scope === 'all' ? $cursor : null,
                    ];
                }

                $processed = 0; $errors = 0; $messages = [];
                foreach ($ids as $aid){
                    $res = $this->generate_and_save($aid, 'dashboard');
                    if (is_wp_error($res)){
                        if ($res->get_error_code() === 'ai_alt_dry_run'){
                            $processed++;
                            $messages[] = sprintf('ID %d: %s', $aid, $res->get_error_message());
                        } elseif ($res->get_error_code() === 'api_error'){
                            $errors++;
                            $messages[] = sprintf('ID %d API error: %s', $aid, $res->get_error_message());
                            return [
                                'processed'   => $processed,
                                'errors'      => $errors,
                                'attempted'   => $attempted,
                                'messages'    => $messages,
                                'completed'   => true,
                                'stats'       => $this->get_media_stats(),
                                'scope'       => $scope,
                                'next_cursor' => null,
                            ];
                        } else {
                            $errors++;
                            $messages[] = sprintf('ID %d: %s', $aid, $res->get_error_message());
                        }
                    } else {
                        $processed++;
                    }
                }

                $this->stats_cache = null;
                $stats = $this->get_media_stats();

                $completed = false;
                $next_cursor = null;
                if ($scope === 'all'){
                    $next_cursor = $cursor + $attempted;
                    $completed = ($next_cursor >= ($stats['total'] ?? 0)) || $attempted < $batch;
                } else {
                    $completed = ($stats['missing'] ?? 0) <= 0 || $attempted < $batch;
                }

                if ($processed === 0 && $errors > 0){
                    $completed = true;
                }

                return [
                    'processed'   => $processed,
                    'errors'      => $errors,
                    'attempted'   => $attempted,
                    'messages'    => $messages,
                    'completed'   => $completed,
                    'stats'       => $stats,
                    'scope'       => $scope,
                    'next_cursor' => $next_cursor,
                ];
            },
            'permission_callback' => '__return_true',
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

        register_rest_route('ai-alt/v1', '/queue', [
            'methods'  => \WP_REST_Server::DELETABLE,
            'callback' => function(){
                if (!$this->user_can_manage()){
                    return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                }
                $this->save_queue([]);
                $this->send_notification(
                    __('AI Alt Text queue force-stopped', 'ai-alt-gpt'),
                    __('The background queue was cancelled from the dashboard.', 'ai-alt-gpt')
                );
                return [
                    'cleared' => true,
                    'stats'   => $this->get_media_stats(),
                ];
            },
            'permission_callback' => function(){ return $this->user_can_manage(); },
        ]);

        register_rest_route('ai-alt/v1', '/queue', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => function(){
                if (!$this->user_can_manage()){
                    return new \WP_Error('forbidden', 'No permission', ['status' => 403]);
                }
                $this->process_queue();
                return [
                    'processed' => true,
                    'stats'     => $this->get_media_stats(),
                ];
            },
            'permission_callback' => function(){ return $this->user_can_manage(); },
        ]);
    }

    public function enqueue_admin($hook){
        $base_path = plugin_dir_path(__FILE__);
        $base_url  = plugin_dir_url(__FILE__);

        if ($hook === 'upload.php'){
            $admin_version = file_exists($base_path . 'assets/ai-alt-admin.js') ? filemtime($base_path . 'assets/ai-alt-admin.js') : '2.0.0';
            wp_enqueue_script('ai-alt-gpt-admin', $base_url . 'assets/ai-alt-admin.js', ['jquery'], $admin_version, true);
            wp_localize_script('ai-alt-gpt-admin', 'AI_ALT_GPT', [
                'nonce' => wp_create_nonce('wp_rest'),
                'rest'  => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restStats' => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restQueue' => esc_url_raw( rest_url('ai-alt/v1/queue') ),
            ]);
        }

        if ($hook === 'media_page_ai-alt-gpt'){
            $css_version = file_exists($base_path . 'assets/ai-alt-dashboard.css') ? filemtime($base_path . 'assets/ai-alt-dashboard.css') : '2.0.0';
            $js_version  = file_exists($base_path . 'assets/ai-alt-dashboard.js') ? filemtime($base_path . 'assets/ai-alt-dashboard.js') : '2.0.0';
            wp_enqueue_style('ai-alt-gpt-dashboard', $base_url . 'assets/ai-alt-dashboard.css', [], $css_version);
            wp_enqueue_script('ai-alt-gpt-dashboard', $base_url . 'assets/ai-alt-dashboard.js', ['jquery'], $js_version, true);
            wp_localize_script('ai-alt-gpt-dashboard', 'AI_ALT_GPT_DASH', [
                'nonce'       => wp_create_nonce('wp_rest'),
                'rest'        => esc_url_raw( rest_url('ai-alt/v1/generate/') ),
                'restMissing' => esc_url_raw( rest_url('ai-alt/v1/generate-missing') ),
                'restStats'   => esc_url_raw( rest_url('ai-alt/v1/stats') ),
                'restQueue'   => esc_url_raw( rest_url('ai-alt/v1/queue') ),
                'stats'       => $this->get_media_stats(),
                'l10n'        => [
                    'processing' => __('Generating ALT text…', 'ai-alt-gpt'),
                    'complete'   => __('All missing ALT text processed!', 'ai-alt-gpt'),
                    'completeAll'=> __('All images regenerated (overwrite successful).', 'ai-alt-gpt'),
                    'error'      => __('Something went wrong. Check console for details.', 'ai-alt-gpt'),
                    'summary'    => __('Generated %1$d images (%2$d errors).', 'ai-alt-gpt'),
                    'restUnavailable' => __('REST endpoint unavailable', 'ai-alt-gpt'),
                    'coverageCopy'    => __('of images currently include ALT text.', 'ai-alt-gpt'),
                    'noRequests'      => __('None yet', 'ai-alt-gpt'),
                    'queueQueued'     => __('Queued for background processing.', 'ai-alt-gpt'),
                    'queueMessage'    => __('Background processing in progress.', 'ai-alt-gpt'),
                    'queueProcessed'  => __('images processed so far.', 'ai-alt-gpt'),
                    'queueCleared'    => __('Background queue cancelled.', 'ai-alt-gpt'),
                    'stopLabel'       => __('Force Stop', 'ai-alt-gpt'),
                    'stopProgress'    => __('Stopping…', 'ai-alt-gpt'),
                    'stopError'       => __('Unable to stop queue.', 'ai-alt-gpt'),
                    'runBatch'        => __('Run Batch Now', 'ai-alt-gpt'),
                    'runBatchBusy'    => __('Running…', 'ai-alt-gpt'),
                    'runBatchMessage' => __('Batch processed via dashboard trigger.', 'ai-alt-gpt'),
                    'autoRetry'       => __('No progress detected. Attempting automatic retry…', 'ai-alt-gpt'),
                    'noMessages'      => __('No queue messages recorded yet.', 'ai-alt-gpt'),
                    'noAudit'         => __('No usage data recorded yet.', 'ai-alt-gpt'),
                ],
                'labels'      => [
                    'missingStart'   => __('Generate ALT for Missing Images', 'ai-alt-gpt'),
                    'missingAllDone' => __('All images have ALT text', 'ai-alt-gpt'),
                    'missingRunning' => __('Generating…', 'ai-alt-gpt'),
                    'allStart'       => __('Regenerate ALT for All Images', 'ai-alt-gpt'),
                    'allRunning'     => __('Regenerating…', 'ai-alt-gpt'),
                    'queueRunning'   => __('Processing…', 'ai-alt-gpt'),
                ],
                'autoRetryInterval' => 60000,
            ]);
        }
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
            if (is_wp_error($res)) {
                if ($res->get_error_code() === 'ai_alt_dry_run') {
                    $count++; \WP_CLI::log("ID $aid: " . $res->get_error_message());
                } else {
                    $errors++; \WP_CLI::warning("ID $aid: " . $res->get_error_message());
                }
            }
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
