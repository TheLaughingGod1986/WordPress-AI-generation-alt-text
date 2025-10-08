(function($){
    function pushNotice(type, message){
        if (window.wp && wp.data && wp.data.dispatch){
            try {
                wp.data.dispatch('core/notices').createNotice(type, message, { isDismissible: true });
                return;
            } catch (err) {}
        }
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
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
        if (typeof original !== 'undefined') {
            btn.text(original);
        }
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

    $(document).on('click', '.ai-alt-generate', function(e){
        e.preventDefault();
        if (!window.AI_ALT_GPT || !AI_ALT_GPT.rest){
            pushNotice('error', 'AI ALT: REST URL missing.');
            return;
        }

        var btn = $(this);
        var id = btn.data('id');
        if (!id){
            pushNotice('error', 'AI ALT: Attachment ID missing.');
            return;
        }

        if (typeof btn.data('original-text') === 'undefined'){
            btn.data('original-text', btn.text());
        }

        btn.text('Generating…');
        if (btn.is('button, input')){
            btn.prop('disabled', true);
        }

        $.ajax({
            url: AI_ALT_GPT.rest + id,
            method: 'POST',
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', AI_ALT_GPT.nonce); },
        }).done(function(r){
            if (r && r.alt){
                var context = btn.closest('.compat-item, .attachment-details, .media-modal');
                updateAltField(id, r.alt, context.length ? context : null);
                pushNotice('success', 'ALT generated: ' + r.alt);
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
    });

    function toggleLanguageCustom(){
        var select = $('.ai-alt-language-select');
        if (!select.length){ return; }
        select.each(function(){
            var $sel = $(this);
            var $custom = $sel.closest('td').find('.ai-alt-language-custom');
            var val = $sel.val();
            if (!$custom.length){ return; }
            if (val === 'custom'){
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
