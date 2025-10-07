(function($){
    const dash = window.AI_ALT_GPT_DASH || {};
    const $container = $('.ai-alt-dashboard');

    if (!$container.length || !dash.stats) {
        return;
    }

    let totals = { processed: 0, errors: 0 };
    let running = false;

    const $button = $container.find('.ai-alt-generate-missing');
    const $status = $container.find('.ai-alt-dashboard__status');
    const $bar    = $container.find('.ai-alt-progress__bar span');
    const canvas  = document.getElementById('ai-alt-coverage');
    const ctx     = canvas ? canvas.getContext('2d') : null;

    const l10n = dash.l10n || {};
    const labels = dash.labels || {};
    const startLabel = labels.start || 'Generate ALT for Missing Images';
    const runningLabel = labels.running || 'Generating…';
    const allDoneLabel = labels.allDone || 'All images have ALT text';
    const restUnavailable = l10n.restUnavailable || 'REST endpoint unavailable';

    function setStatus(message, active){
        $status.text(message || '').toggleClass('is-active', !!active);
    }

    function drawChart(stats){
        if (!ctx || !canvas) {
            return;
        }
        const total = Math.max(0, (stats.with_alt || 0) + (stats.missing || 0));
        const withAlt = total ? (stats.with_alt || 0) / total : 0;
        const missing = 1 - withAlt;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const centerX = canvas.width / 2;
        const centerY = canvas.height / 2;
        const radius  = Math.min(centerX, centerY) - 10;
        const lineWidth = 26;

        ctx.lineCap = 'round';

        function drawArc(start, fraction, color){
            ctx.beginPath();
            ctx.strokeStyle = color;
            ctx.lineWidth = lineWidth;
            ctx.arc(centerX, centerY, radius, start, start + fraction * Math.PI * 2, false);
            ctx.stroke();
        }

        // background track
        ctx.beginPath();
        ctx.lineWidth = lineWidth;
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.2)';
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.stroke();

        drawArc(-Math.PI / 2, withAlt, '#3b82f6');
        drawArc(-Math.PI / 2 + withAlt * Math.PI * 2, missing, '#f97316');

        const coverageNumber = Number(stats.coverage || 0);
        const coverageText = coverageNumber.toFixed(1).replace(/\.0$/, '') + '%';

        ctx.font = '600 28px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = '#0f172a';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(coverageText, centerX, centerY - 8);

        ctx.font = '13px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = '#64748b';
        ctx.fillText('coverage', centerX, centerY + 18);
    }

    function renderStats(stats){
        if (!stats) {
            return;
        }
        const coverageNumber = Number(stats.coverage || 0);
        const coverageText = coverageNumber.toFixed(1).replace(/\.0$/, '');

        $container.attr('data-stats', JSON.stringify(stats));
        const values = $container.find('.ai-alt-card__value');
        values.eq(0).text((stats.total || 0).toLocaleString());
        values.eq(1).text((stats.with_alt || 0).toLocaleString());
        values.eq(2).text((stats.missing || 0).toLocaleString());
        values.eq(3).text((stats.generated || 0).toLocaleString());

        $container.find('.ai-alt-card__hint').eq(1).text(coverageText + '% coverage');
        $container.find('.ai-alt-progress p').html('<strong>' + coverageText + '%</strong> of images currently include ALT text.');
        $bar.css('width', coverageNumber + '%');

        drawChart({
            total: stats.total,
            with_alt: stats.with_alt,
            missing: stats.missing,
            coverage: coverageNumber
        });

        const missingCount = stats.missing || 0;
        $button.prop('disabled', missingCount <= 0);
        $button.attr('data-total', missingCount);
        if (!running) {
            $button.text(missingCount > 0 ? startLabel : allDoneLabel);
        }
    }

    function stopProcessing(){
        running = false;
        $button.prop('disabled', false).text(startLabel);
    }

    function finishProcessing(){
        running = false;
        const summary = (l10n.summary || 'Generated %1$d images (%2$d errors).')
            .replace('%1$d', totals.processed)
            .replace('%2$d', totals.errors);
        setStatus(summary + ' ' + (l10n.complete || 'All missing ALT text processed!'), false);
        $button.prop('disabled', false).text(startLabel);
    }

    function requestBatch(){
        if (!running) {
            return;
        }
        setStatus(l10n.processing || 'Generating ALT text…', true);
        $.ajax({
            url: dash.restMissing,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ batch: 5 }),
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', dash.nonce); }
        }).done(function(res){
            if (!res || typeof res !== 'object'){
                throw new Error('Invalid response');
            }
            totals.processed += res.processed || 0;
            totals.errors += res.errors || 0;
            renderStats(res.stats);
            const suffix = ' (' + totals.processed + ' processed, ' + totals.errors + ' errors)';
            $button.text(runningLabel + suffix);
            if (res.messages && res.messages.length){
                console.warn('AI ALT', res.messages);
            }
            if (res.stats && res.stats.missing > 0 && res.processed > 0) {
                setTimeout(requestBatch, 400);
            } else if (res.stats && res.stats.missing > 0 && res.processed === 0 && !res.completed){
                setStatus(l10n.error || 'Something went wrong. Check console for details.', false);
                stopProcessing();
            } else {
                finishProcessing();
            }
        }).fail(function(xhr){
            console.error('AI ALT', xhr);
            setStatus(l10n.error || 'Something went wrong. Check console for details.', false);
            stopProcessing();
        });
    }

    if (!$button.length || !dash.restMissing) {
        if ($button.length) {
            $button.prop('disabled', true).text(restUnavailable);
        }
        renderStats(dash.stats);
        return;
    }

    $button.on('click', function(){
        if (running){
            return;
        }
        totals = { processed: 0, errors: 0 };
        running = true;
        $button.prop('disabled', true).text(runningLabel);
        requestBatch();
    });

    renderStats(dash.stats);
})(jQuery);
