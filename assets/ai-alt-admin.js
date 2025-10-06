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
            '[data-setting="alt"]',
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
            field.val(value).trigger('change');
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
                alert('Failed to generate ALT');
            }
        }).fail(function(){
            alert('Error communicating');
        }).always(function(){
            restoreButton(btn);
        });
    });
})(jQuery);
