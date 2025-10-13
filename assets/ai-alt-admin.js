(function($){
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

    function restoreButton(btn){
        var original = btn.data('original-text');
        var fallback = typeof original !== 'undefined' ? original : 'Generate Alt';
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
            var scoped = context ? context.find(sel) : $(sel);
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
                try {
                    attachment.set('alt', value);
                } catch (e) {}
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
            if (window.console){
                console.warn('AI ALT: regenerate aborted because REST endpoint is missing.', {
                    AI_ALT_GPT: window.AI_ALT_GPT,
                    AI_ALT_GPT_DASH: window.AI_ALT_GPT_DASH,
                    wpApiSettings: window.wpApiSettings
                });
            }
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
            if (r && r.alt){
                var context = btn.closest('.compat-item, .attachment-details, .media-modal');
                updateAltField(id, r.alt, context.length ? context : null);
                pushNotice('success', 'ALT generated: ' + r.alt + ' — ' + (AI_ALT_GPT && AI_ALT_GPT.l10n && AI_ALT_GPT.l10n.reviewCue ? AI_ALT_GPT.l10n.reviewCue : 'Review it in the ALT Library to confirm it matches the image.'));
                refreshDashboardStats();
                if (!context.length){ location.reload(); }
            } else if (r && r.code === 'ai_alt_dry_run'){
                pushNotice('info', r.message || 'Dry run enabled. Prompt stored for review.');
                refreshDashboardStats();
            } else {
                var message = (r && (r.message || (r.data && r.data.message))) || 'Failed to generate ALT';
                pushNotice('error', message);
            }
        }).fail(function(xhr){
            var json = xhr.responseJSON || {};
            var message = json.message || (json.data && json.data.message) || 'Error communicating';
            pushNotice('error', message);
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
    });
})(jQuery);
