( function($){
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
            alert('AI ALT: REST URL missing.');
            return;
        }

        var btn = $(this);
        var id = btn.data('id');
        if (!id){
            alert('AI ALT: Attachment ID missing.');
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

                if (context.length){
                    alert('ALT: ' + r.alt);
                } else {
                    alert('ALT: ' + r.alt);
                    location.reload();
                }
            } else {
                var message = (r && (r.message || (r.data && r.data.message))) || 'Failed to generate ALT';
                alert('AI ALT: ' + message);
            }
        }).fail(function(xhr){
            var json = xhr.responseJSON || {};
            var message = json.message || (json.data && json.data.message) || 'Error communicating';
            alert('AI ALT: ' + message);
        }).always(function(){
            restoreButton(btn);
        });
    });
})(jQuery);
