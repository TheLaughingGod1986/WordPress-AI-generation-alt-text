/**
 * Farlo AI Alt Text Generator (GPT) - Admin Integration
 * 
 * Handles media library and attachment details integration,
 * including single ALT text generation and preview modal.
 * 
 * @package Farlo_AI_Alt_GPT
 * @version 3.0.0
 */

(function($){
    function fmtNumber(value){
        var num = Number(value);
        if (!isFinite(num)){
            return value;
        }
        if (typeof Intl !== 'undefined' && Intl.NumberFormat){
            try {
                return new Intl.NumberFormat().format(num);
            } catch (err){}
        }
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function showAltPreview(initialAlt, onApply, onCancel){

        var heading = (window.AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.previewAltHeading) || 'Review generated ALT text';
        var description = (window.AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.previewAltHint) || 'Review the generated description before applying it to your media item.';
        var applyLabel = (window.AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.previewAltApply) || 'Use this ALT';
        var cancelLabel = (window.AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.previewAltCancel) || 'Keep current ALT';
        var dismissNotice = (window.AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.previewAltDismissed) || 'Preview dismissed. Existing ALT kept.';

        var $overlay = $('<div class="ai-alt-preview-overlay" role="dialog" aria-modal="true"></div>');
        var $modal = $('<div class="ai-alt-preview"></div>').appendTo($overlay);
        $('<h3/>').text(heading).appendTo($modal);
        $('<p/>').text(description).appendTo($modal);
        var $preview = $('<div class="ai-alt-preview__text" aria-live="polite"></div>').text(initialAlt).appendTo($modal);
        var $meta = $('<div class="ai-alt-preview__meta"></div>').appendTo($modal);
        $('<span/>').text(initialAlt.length + ' characters').appendTo($meta);
        var $actions = $('<div class="ai-alt-preview__actions"></div>').appendTo($modal);
        var $cancel = $('<button type="button" class="button ai-alt-preview__cancel"></button>').text(cancelLabel).appendTo($actions);
        var $apply = $('<button type="button" class="button ai-alt-preview__apply"></button>').text(applyLabel).appendTo($actions);

        function remove(){
            $(document).off('keydown.aiAltPreview');
            $overlay.remove();
        }

        $cancel.on('click', function(){
            remove();
            if (typeof onCancel === 'function'){ onCancel(dismissNotice); }
        });
        $apply.on('click', function(){
            remove();
            if (typeof onApply === 'function'){ onApply(initialAlt); }
        });

        $(document).on('keydown.aiAltPreview', function(evt){
            if (evt.key === 'Escape'){
                evt.preventDefault();
                remove();
                if (typeof onCancel === 'function'){ onCancel(dismissNotice); }
            }
        });

        $overlay.on('click', function(evt){
            if (evt.target === $overlay.get(0)){
                remove();
                if (typeof onCancel === 'function'){ onCancel(dismissNotice); }
            }
        });

        $('body').append($overlay);
    }

    function createNoticeElement(type, message){
        return $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
    }

    function pushNotice(type, message){
        if (window.wp && wp.data && wp.data.dispatch){
            try {
                wp.data.dispatch('core/notices').createNotice(type, message, { isDismissible: true });
                return;
            } catch (err) {}
        }
        var $notice = createNoticeElement(type, message);
        var $target = $('#wpbody-content').find('.wrap').first();
        if ($target.length){
            $target.prepend($notice);
        } else {
            $('#wpbody-content').prepend($notice);
        }
    }

    function refreshDashboardStats(){
        if (!window.AI_ALT_GPT || !AI_ALT_GPT.restStats || !window.fetch){
            return;
        }
        fetch(AI_ALT_GPT.restStats, {
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': AI_ALT_GPT.nonce,
                'Accept': 'application/json'
            }
        })
        .then(function(res){ return res.ok ? res.json() : null; })
        .then(function(data){
            if (!data){ return; }
            if (typeof window.dispatchEvent === 'function'){
                try {
                    window.dispatchEvent(new CustomEvent('ai-alt-stats-update', { detail: data }));
                } catch (err) {}
            }
        })
        .catch(function(){});
    }

    function recalcLibrarySummary(){
        var $summary = $('[data-library-summary]');
        if (!$summary.length){ return; }

        var totals = {
            total: 0,
            sum: 0,
            healthy: 0,
            review: 0,
            critical: 0
        };

        $('.ai-alt-library__score').each(function(){
            var $cell = $(this);
            var rawScore = $cell.attr('data-score');
            var score = Number(typeof rawScore !== 'undefined' ? rawScore : $cell.data('score'));
            if (!isFinite(score)){ return; }
            totals.total += 1;
            totals.sum += score;
            var status = ($cell.attr('data-status') || $cell.data('status') || '').toString();
            if (status === 'great' || status === 'good'){
                totals.healthy += 1;
            } else if (status === 'review'){
                totals.review += 1;
            } else {
                totals.critical += 1;
            }
        });

        if (!totals.total){ return; }
        var average = Math.round(totals.sum / totals.total);
        $summary.find('[data-library-summary-average]').text(fmtNumber(average));
        $summary.find('[data-library-summary-healthy]').text(fmtNumber(totals.healthy));
        $summary.find('[data-library-summary-review]').text(fmtNumber(totals.review));
        $summary.find('[data-library-summary-critical]').text(fmtNumber(totals.critical));
        $summary.find('[data-library-summary-total]').text(fmtNumber(totals.total));
    }

    function restoreButton(btn){
        var original = btn.data('original-text');
        var fallback;
        if (btn.is('a')){
            fallback = btn.data('label-regenerate') || btn.data('label-generate') || btn.text() || 'Regenerate Alt Text (AI)';
        } else {
            fallback = typeof original !== 'undefined' ? original : (btn.data('label-generate') || 'Generate Alt');
        }
        btn.text(fallback);
        if (btn.is('button, input')) {
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
            var scoped = context && context.length ? context.find(sel) : $();
            if (!scoped.length){
                scoped = $(sel);
            }
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
            field.trigger('input').trigger('change').trigger('keyup');
        }

        if (window.wp && wp.media && typeof wp.media.attachment === 'function' && id){
            var attachment = wp.media.attachment(id);
            if (attachment){
                try {
                    attachment.set('alt', value);
                    if (typeof attachment.save === 'function'){ attachment.save(); }
                } catch (e) {
                    // Silent fail - not critical
                }
            }
        }

        if (window.wp && wp.data && wp.data.dispatch){
            try {
                wp.data.dispatch('core').editEntityRecord('postType', 'attachment', id, { alt_text: value });
            } catch (err) {
                // Silent fail - not critical
            }
        }
    }

    function resolveConfig(){
        if (window.AI_ALT_GPT && AI_ALT_GPT.rest){
            return {
                rest: AI_ALT_GPT.rest,
                nonce: AI_ALT_GPT.nonce || (window.wpApiSettings ? wpApiSettings.nonce : '')
            };
        }

        if (window.AI_ALT_GPT_DASH && AI_ALT_GPT_DASH.rest){
            return {
                rest: AI_ALT_GPT_DASH.rest,
                nonce: AI_ALT_GPT_DASH.nonce || (window.wpApiSettings ? wpApiSettings.nonce : '')
            };
        }

        if (window.wpApiSettings && wpApiSettings.root){
            return {
                rest: wpApiSettings.root + 'ai-alt/v1/generate/',
                nonce: wpApiSettings.nonce
            };
        }

        return null;
    }

    function regenerate(id, btn){
        var config = resolveConfig();
        if (!config || !config.rest){
            pushNotice('error', 'AI ALT: REST URL missing.');
            return;
        }

        btn.text('Generating…');
        if (btn.is('button, input')){
            btn.prop('disabled', true);
        }

        $.ajax({
            url: config.rest + id,
            method: 'POST',
            beforeSend: function(xhr){
                if (config.nonce){
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                }
            },
        }).done(function(r){
            if (r && r.code === 'duplicate_alt'){
                regenerate(id, btn); // immediate retry to grab a different phrasing
                return;
            }

            if (r && r.alt){
                showAltPreview(r.alt, function(finalAlt){
                    var context = btn.closest('.compat-item, .attachment-details, .media-modal');
                    updateAltField(id, finalAlt, context.length ? context : null);
                    if (window.aiAltToast){
                        window.aiAltToast.success('ALT Text Generated', finalAlt, 6000);
                    } else {
                        pushNotice('success', 'ALT generated: ' + finalAlt + ' - ' + (AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.reviewCue ? AI_ALT_GPT.l10n.reviewCue : 'Review it in the ALT Library to confirm it matches the image.'));
                    }
                    refreshDashboardStats();
                    var handledInline = context.length > 0;
                    btn.attr('data-has-alt', '1');
                    if (btn.is('a')){
                        btn.text(btn.data('label-regenerate') || 'Regenerate Alt Text (AI)');
                    } else {
                        var regenLabel = btn.data('label-regenerate') || 'Regenerate Alt';
                        btn.data('original-text', regenLabel);
                        btn.text(regenLabel);
                    }

                    var $libraryRow = btn.closest('.ai-alt-library__row');
                    if ($libraryRow.length){
                        handledInline = true;
                        $libraryRow.removeClass('ai-alt-library__row--missing');
                        var $altCell = $libraryRow.find('.ai-alt-library__alt');
                        if ($altCell.length){
                            $altCell.empty().text(finalAlt);
                        }
                        $libraryRow.find('.ai-alt-library__flag').remove();

                        if (r.meta){
                            if (typeof r.meta.tokens !== 'undefined'){
                                var $tokensCell = $libraryRow.find('.ai-alt-library__tokens');
                                if ($tokensCell.length){
                                    $tokensCell.text(fmtNumber(r.meta.tokens));
                                }
                            }
                            if (typeof r.meta.generated !== 'undefined'){
                                var $generatedCell = $libraryRow.find('.ai-alt-library__generated');
                                if ($generatedCell.length){
                                    $generatedCell.text(r.meta.generated || '');
                                }
                            }
                            if (typeof r.meta.score !== 'undefined'){
                                var $scoreCell = $libraryRow.find('.ai-alt-library__score');
                                if ($scoreCell.length){
                                    var status = r.meta.score_status || '';
                                    $scoreCell.attr('data-score', r.meta.score).attr('data-status', status);
                                    var $badge = $scoreCell.find('.ai-alt-score-badge');
                                    if ($badge.length){
                                        $badge
                                            .text(fmtNumber(r.meta.score))
                                            .attr('class', 'ai-alt-score-badge ai-alt-score-badge--' + status);
                                    }
                                    var $label = $scoreCell.find('.ai-alt-score-label');
                                    if ($label.length){
                                        $label.text(r.meta.score_grade || '');
                                    }
                                    var $issuesList = $scoreCell.find('.ai-alt-score-issues');
                                    if (r.meta.score_issues && r.meta.score_issues.length){
                                        if (!$issuesList.length){
                                            $issuesList = $('<ul class="ai-alt-score-issues"></ul>').appendTo($scoreCell);
                                        } else {
                                            $issuesList.empty();
                                        }
                                        r.meta.score_issues.forEach(function(issue){
                                            $('<li/>').text(issue).appendTo($issuesList);
                                        });
                                    } else if ($issuesList.length){
                                        $issuesList.remove();
                                    }
                                }
                            }
                        }
                        recalcLibrarySummary();
                    }

                    var $recentItem = btn.closest('.ai-alt-recent__item');
                    if ($recentItem.length){
                        handledInline = true;
                        $recentItem.find('.ai-alt-recent__alt').text(finalAlt);
                    }

                    if (!handledInline){
                        location.reload();
                    }
                }, function(msg){
                    if (msg){ pushNotice('info', msg); }
                });
            } else if (r && r.code === 'ai_alt_dry_run'){
                if (window.aiAltToast){
                    window.aiAltToast.info('Dry Run Mode', r.message || 'Prompt stored for review.');
                } else {
                    pushNotice('info', r.message || 'Dry run enabled. Prompt stored for review.');
                }
                refreshDashboardStats();
            } else {
                var message = (r && (r.message || (r.data && r.data.message))) || 'Failed to generate ALT';
                if (window.aiAltToast){
                    window.aiAltToast.error('Generation Failed', message);
                } else {
                    pushNotice('error', message);
                }
            }
        }).fail(function(xhr){
            var json = xhr.responseJSON || {};
            if (json && json.code === 'duplicate_alt'){
                regenerate(id, btn);
                return;
            }
            var message = json.message || (json.data && json.data.message) || 'Error communicating';
            if (window.aiAltToast){
                window.aiAltToast.error('Request Failed', message);
            } else {
                pushNotice('error', message);
            }
        }).always(function(){
            restoreButton(btn);
        });
    }

    $(document).on('click', '.ai-alt-generate', function(e){
        e.preventDefault();

        var btn = $(this);
        var id = btn.data('id');
        if (!id){
            pushNotice('error', 'AI ALT: Attachment ID missing.');
            return;
        }

        if (typeof btn.data('original-text') === 'undefined'){
            btn.data('original-text', btn.text());
        }

        if (btn.data('has-alt')){
            if (btn.is('a')){
                btn.text(btn.data('label-regenerate') || btn.text());
            } else {
                btn.text(btn.data('label-regenerate') || btn.data('original-text') || 'Regenerate Alt');
            }
        }

        regenerate(id, btn);
    });

    $(document).on('click', '.ai-alt-regenerate-single', function(){
        var btn = $(this);
        var id = btn.data('id');
        if (!id){ return; }
        if (typeof btn.data('original-text') === 'undefined'){
            btn.data('original-text', btn.text());
        }
        regenerate(id, btn.text('Regenerating…').prop('disabled', true));
    });

    function applyLibraryFilter(status){
        var $rows = $('.ai-alt-library__table tbody tr');
        var $stats = $('[data-library-summary] [data-summary-filter]');

        if (!status || status === 'clear'){
            $rows.show();
            $stats.removeClass('is-active');
            $('[data-summary-filter="clear"]').addClass('is-active');
            return;
        }

        $rows.each(function(){
            var $row = $(this);
            var rowStatus = ($row.find('.ai-alt-library__score').attr('data-status') || '').toString();
            if (!rowStatus){
                $row.toggle(status === 'healthy');
                return;
            }
            if (status === 'healthy'){
                $row.toggle(rowStatus === 'great' || rowStatus === 'good');
            } else {
                $row.toggle(rowStatus === status);
            }
        });

        $stats.removeClass('is-active');
        $('[data-summary-filter="' + status + '"]').addClass('is-active');
    }

    $(document).on('click keypress', '[data-summary-filter]', function(evt){
        if (evt.type === 'keypress' && evt.which !== 13 && evt.which !== 32){
            return;
        }
        evt.preventDefault();
        var status = $(this).data('summary-filter');
        applyLibraryFilter(status);
    });

    function toggleLanguageCustom(){
        var select = $('.ai-alt-language-select');
        if (!select.length){ return; }
        select.each(function(){
            var $sel = $(this);
            var $custom = $sel.closest('td').find('.ai-alt-language-custom');
            var val = $sel.val();
            if (!$custom.length){ return; }
            var isCustom = val === 'custom';
            $custom.toggleClass('is-visible', isCustom);
            if (isCustom){
                $custom.show();
            } else {
                $custom.hide();
            }
        });
    }

    $(document).on('change', '.ai-alt-language-select', toggleLanguageCustom);

    $(document).ready(function(){
        toggleLanguageCustom();
        recalcLibrarySummary();
        applyLibraryFilter('clear');
    });
})(jQuery);
