( function($){
    $(document).ready(function(){
        $('a.ai-alt-generate').on('click', function(e){
            e.preventDefault();
            var id = $(this).data('id');
            var url = AI_ALT_GPT.rest + id;
            var btn = $(this);
            btn.text('Generating…');
            $.ajax({
                url: url,
                method: 'POST',
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', AI_ALT_GPT.nonce); },
            }).done(function(r){
                if(r && r.alt) {
                    alert('ALT: ' + r.alt);
                    location.reload();
                } else {
                    alert('Failed to generate ALT');
                    btn.text('Generate Alt Text (AI)');
                }
            }).fail(function(){
                alert('Error communicating');
                btn.text('Generate Alt Text (AI)');
            });
        });
    });
})(jQuery);