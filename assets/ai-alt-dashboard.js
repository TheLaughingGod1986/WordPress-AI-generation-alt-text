(function($){
    const dash = window.AI_ALT_GPT_DASH || {};
    const $dashboard = $('.ai-alt-dashboard--primary');
    const $usage = $('.ai-alt-dashboard--usage');

    if ((!$dashboard.length && !$usage.length) || !dash.stats) {
        return;
    }

    let currentStats = dash.stats;
    let running = false;
    let runScope = null;
    let totals = { processed: 0, errors: 0, attempted: 0 };
    let cursor = 0;
    let targetTotal = 0;
    let pollTimer = null;
    let lastQueueActive = !!(currentStats.queue && currentStats.queue.active);

    const restMissing = dash.restMissing || null;
    const restStats = dash.restStats || null;
    const restQueue = dash.restQueue || null;

    const palette = {
        accent: '#2271b1',
        missing: '#d63638',
        track: 'rgba(208, 215, 222, 0.6)',
        text: '#1d2327',
        textMuted: '#50575e'
    };

    const l10n = $.extend({
        processing: 'Generating ALT text…',
        complete: 'All missing ALT text processed!',
        completeAll: 'All images regenerated.',
        error: 'Something went wrong. Check console for details.',
        restUnavailable: 'REST endpoint unavailable',
        coverageCopy: 'of images currently include ALT text.',
        coverageSuffix: 'coverage',
        coverageValue: 'ALT coverage at %s',
        noRequests: 'None yet',
        summary: 'Generated %1$d images (%2$d errors).',
        queueQueued: 'Queued for background processing.',
        queueMessage: 'Background processing in progress.',
        queueProcessed: 'images processed so far.',
        queueCleared: 'Background queue cancelled.',
        stopLabel: 'Force Stop',
        stopProgress: 'Stopping…',
        stopError: 'Unable to stop queue.',
        runBatch: 'Run Batch Now',
        runBatchBusy: 'Running…',
        runBatchMessage: 'Batch processed via dashboard trigger.',
        autoRetry: 'No progress detected. Attempting automatic retry…',
        noMessages: 'No queue messages yet.',
        noAudit: 'No usage data recorded yet.',
        pending: 'Pending',
        waitingForCron: 'Waiting for cron',
        notAvailable: 'N/A'
    }, dash.l10n || {});

    const labels = $.extend({
        missingStart: 'Generate ALT for Missing Images',
        missingAllDone: 'All images have ALT text',
        missingRunning: 'Generating…',
        allStart: 'Regenerate ALT for All Images',
        allRunning: 'Regenerating…',
        queueRunning: 'Processing…'
    }, dash.labels || {});

    const elements = {
        status: $dashboard.find('.ai-alt-dashboard__status'),
        progressWrap: $dashboard.find('.ai-alt-progress__bar'),
        bar: $dashboard.find('.ai-alt-progress__bar span'),
        btnMissing: $dashboard.find('.ai-alt-generate-missing'),
        btnAll: $dashboard.find('.ai-alt-regenerate-all'),
        stopQueue: $dashboard.find('.ai-alt-stop-queue'),
        runBatch: $dashboard.find('.ai-alt-queue-run'),
        queueSummary: $dashboard.find('.ai-alt-queue-summary'),
        queueLog: $dashboard.find('.ai-alt-queue-log')
    };

    const usageEls = {
        requests: $usage.find('.ai-alt-usage__value--requests'),
        prompt: $usage.find('.ai-alt-usage__value--prompt'),
        completion: $usage.find('.ai-alt-usage__value--completion'),
        last: $usage.find('.ai-alt-usage__value--last'),
        auditBody: $usage.find('.ai-alt-audit-rows')
    };

    const canvas = $dashboard.length ? document.getElementById('ai-alt-coverage') : null;
    const ctx = canvas ? canvas.getContext('2d') : null;

    let lastProgress = {
        processed: currentStats.queue ? currentStats.queue.processed || 0 : 0,
        errors: currentStats.queue ? currentStats.queue.errors || 0 : 0,
        timestamp: Date.now()
    };
    let autoRetryCooldown = false;
    const autoRetryInterval = parseInt(dash.autoRetryInterval || 60000, 10);

    function fmtNumber(num){
        return (Number(num) || 0).toLocaleString();
    }

    function setStatus(message, active){
        if (!elements.status.length){ return; }
        elements.status.text(message || '').toggleClass('is-active', !!active);
    }

    function clearPoll(){
        if (pollTimer){
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function drawChart(stats){
        if (!ctx || !canvas){
            return;
        }
        const total = Math.max(0, (stats.with_alt || 0) + (stats.missing || 0));
        const withAltFraction = total ? (stats.with_alt || 0) / total : 0;
        const missingFraction = 1 - withAltFraction;

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

        ctx.beginPath();
        ctx.lineWidth = lineWidth;
        ctx.strokeStyle = palette.track;
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.stroke();

        drawArc(-Math.PI / 2, withAltFraction, palette.accent);
        drawArc(-Math.PI / 2 + withAltFraction * Math.PI * 2, missingFraction, palette.missing);

        const coverageNumber = Number(stats.coverage || 0);
        const coverageText = coverageNumber.toFixed(1).replace(/\.0$/, '') + '%';

        ctx.font = '600 28px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = palette.text;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(coverageText, centerX, centerY - 8);

        ctx.font = '13px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = palette.textMuted;
        ctx.fillText('coverage', centerX, centerY + 18);
    }

    function renderAudit(rows){
        if (!usageEls.auditBody.length){ return; }
        usageEls.auditBody.empty();
        if (!rows || !rows.length){
            usageEls.auditBody.append('<tr class="ai-alt-audit__empty"><td colspan="6">' + (l10n.noAudit || 'No usage data recorded yet.') + '</td></tr>');
            return;
        }
        rows.forEach(function(row){
            const $tr = $('<tr/>').attr('data-id', row.id);
            const $title = $('<td/>');
            if (row.url){
                $('<a/>', {
                    href: row.url,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }).text(row.title || ('#' + row.id)).appendTo($title);
            } else {
                $title.text(row.title || ('#' + row.id));
            }
            $tr.append($title);
            $tr.append($('<td/>').addClass('ai-alt-audit__alt').text(row.alt || ''));
            var source = (row.source || 'unknown').toLowerCase();
            var sourceLabel = source.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
            $tr.append(
                $('<td/>').addClass('ai-alt-audit__source').append(
                    $('<span/>')
                        .addClass('ai-alt-badge ai-alt-badge--' + source)
                        .text(sourceLabel)
                )
            );
            $tr.append($('<td/>').addClass('ai-alt-audit__tokens').text(fmtNumber(row.tokens || 0)));
            $tr.append($('<td/>').text(fmtNumber(row.prompt || 0)));
            $tr.append($('<td/>').text(fmtNumber(row.completion || 0)));
            $tr.append($('<td/>').text(row.generated || ''));
            usageEls.auditBody.append($tr);
        });
    }

    function updateUsage(usage){
        if (!usage){ return; }
        if (usageEls.requests.length){ usageEls.requests.text(fmtNumber(usage.requests || 0)); }
        if (usageEls.prompt.length){ usageEls.prompt.text(fmtNumber(usage.prompt || 0)); }
        if (usageEls.completion.length){ usageEls.completion.text(fmtNumber(usage.completion || 0)); }
        if (usageEls.last.length){
            const lastDisplay = usage.last_request_formatted || usage.last_request || l10n.noRequests;
            usageEls.last.text(lastDisplay);
        }
    }

    function renderQueueSummary(queue){
        if (!$dashboard.length){ return; }
        const $summary = elements.queueSummary.length ? elements.queueSummary : $dashboard.find('.ai-alt-queue-summary');
        if (!$summary.length){ return; }

        if (!queue || !queue.active){
            $summary.addClass('is-hidden');
            return;
        }

        const fallback = l10n.notAvailable || 'N/A';
        const pending = l10n.pending || 'Pending';
        const waiting = l10n.waitingForCron || 'Waiting for cron';

        $summary.removeClass('is-hidden');

        const map = {
            scope: queue.scope_label || queue.scope || fallback,
            batch: typeof queue.batch !== 'undefined' ? fmtNumber(queue.batch || 0) : fallback,
            processed: typeof queue.processed !== 'undefined' ? fmtNumber(queue.processed || 0) : fallback,
            errors: typeof queue.errors !== 'undefined' ? fmtNumber(queue.errors || 0) : fallback,
            last_run: queue.last_run_formatted || queue.last_run || pending,
            next_run: queue.next_run || waiting
        };

        Object.keys(map).forEach(function(key){
            const $field = $summary.find('[data-queue-field="' + key + '"]');
            if ($field.length){
                $field.text(map[key]);
            }
        });
    }

    function progressSuffix(){
        if (!targetTotal){ return ''; }
        const done = Math.min(totals.processed, targetTotal);
        return ' (' + done + '/' + targetTotal + ')';
    }

    function updateButtons(queueActive){
        const missing = currentStats.missing || 0;
        const total   = currentStats.total || 0;

        if (elements.stopQueue.length){
            if (queueActive){
                elements.stopQueue.removeClass('is-hidden').prop('disabled', false).text(l10n.stopLabel);
            } else {
                elements.stopQueue.addClass('is-hidden').prop('disabled', true).text(l10n.stopLabel);
            }
        }

        if (elements.runBatch.length){
            if (queueActive){
                elements.runBatch.removeClass('is-hidden').prop('disabled', false).text(l10n.runBatch || 'Run Batch Now');
            } else {
                elements.runBatch.addClass('is-hidden').prop('disabled', true).text(l10n.runBatch || 'Run Batch Now');
            }
        }

        if (!elements.btnMissing.length && !elements.btnAll.length){
            return;
        }

        if (queueActive){
            elements.btnMissing.prop('disabled', true).text(labels.queueRunning);
            elements.btnAll.prop('disabled', true).text(labels.queueRunning);
            return;
        }

        if (!running){
            if (elements.btnMissing.length){
                elements.btnMissing.prop('disabled', missing <= 0).text(missing > 0 ? labels.missingStart : labels.missingAllDone);
            }
            if (elements.btnAll.length){
                elements.btnAll.prop('disabled', total <= 0).text(labels.allStart);
            }
        } else {
            if (runScope === 'missing' && elements.btnMissing.length){
                elements.btnMissing.prop('disabled', true).text(labels.missingRunning + progressSuffix());
            }
            if (runScope === 'all' && elements.btnAll.length){
                elements.btnAll.prop('disabled', true).text(labels.allRunning + progressSuffix());
            }
        }
    }

    function updateQueue(queue, wasActive){
        renderQueueSummary(queue);
        if (!queue || !queue.active){
            clearPoll();
            updateButtons(false);
            if (wasActive && l10n.queueCleared){
                setStatus(l10n.queueCleared, false);
            } else if (!running){
                setStatus('', false);
            }
            return;
        }

        const processed = fmtNumber(queue.processed || 0);
        const errors = queue.errors ? ' (' + fmtNumber(queue.errors) + ' errors)' : '';
        const nextRun = queue.next_run ? ' · ' + queue.next_run : '';
        let message = [
            (l10n.queueMessage || 'Background processing in progress.'),
            processed,
            (l10n.queueProcessed || 'images processed so far.')
        ].join(' ') + errors + nextRun;
        if (queue.last_messages && queue.last_messages.length){
            message += ' · ' + queue.last_messages.slice(0, 2).join(' | ');
        }

        setStatus(message, true);
        updateButtons(true);

        if (restStats){
            pollTimer = setTimeout(fetchLatestStats, 5000);
        }
    }

    function runQueueBatch(manual){
        if (!restQueue){ return; }
        const $btn = elements.runBatch;
        if (manual && $btn.length){
            $btn.prop('disabled', true).text(l10n.runBatchBusy || 'Running…');
        }
        fetch(restQueue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': dash.nonce,
                'Accept': 'application/json'
            }
        })
        .then(function(res){ return res.ok ? res.json() : null; })
        .then(function(data){
            if (data && data.stats){
                renderStats(data.stats);
                if (manual){
                    setStatus(l10n.runBatchMessage || 'Batch processed via dashboard trigger.', true);
                }
            }
        })
        .catch(function(err){ console.error('AI ALT queue run', err); })
        .finally(function(){
            if (manual && $btn.length){
                $btn.prop('disabled', false).text(l10n.runBatch || 'Run Batch Now');
            }
            autoRetryCooldown = false;
            lastProgress.timestamp = Date.now();
        });
    }

    function renderStats(stats){
        if (!stats){ return; }
        const wasActive = lastQueueActive;
        currentStats = stats;
        lastQueueActive = !!(stats.queue && stats.queue.active);

        if ($dashboard.length){
            const values = $dashboard.find('.ai-alt-card__value');
            values.eq(0).text(fmtNumber(stats.total || 0));
            values.eq(1).text(fmtNumber(stats.with_alt || 0));
            values.eq(2).text(fmtNumber(stats.missing || 0));
            values.eq(3).text(fmtNumber(stats.generated || 0));
            $dashboard.find('.ai-alt-card__value--tokens').text(fmtNumber((stats.usage && stats.usage.total) || 0));

            const coverageNumberRaw = Number(stats.coverage || 0);
            const coverageNumber = Math.max(0, Math.min(100, coverageNumberRaw));
            const coverageDisplay = coverageNumber.toFixed(1).replace(/\.0$/, '');
            const coveragePercent = coverageDisplay + '%';
            const coverageValueText = (l10n.coverageValue || 'ALT coverage at %s').replace('%s', coveragePercent);

            $dashboard.find('.ai-alt-card__hint').eq(1).text(coveragePercent + ' ' + (l10n.coverageSuffix || 'coverage'));

            const $coverageSummary = $dashboard.find('#ai-alt-coverage-summary');
            if ($coverageSummary.length){
                $dashboard.find('#ai-alt-coverage-value').text(coveragePercent);
            } else {
                $dashboard.find('.ai-alt-dashboard__coverage strong').first().text(coveragePercent);
            }

            if (elements.bar.length){
                elements.bar.css('width', coverageNumber + '%');
            }
            if (elements.progressWrap && elements.progressWrap.length){
                elements.progressWrap.attr({
                    'aria-valuenow': coverageNumber.toFixed(1),
                    'aria-valuetext': coverageValueText
                });
            }
            drawChart(stats);
        }

        renderQueueSummary(stats.queue || {});
        updateUsage(stats.usage || {});
        renderAudit(stats.audit || []);
        updateQueue(stats.queue || {}, wasActive);

        if (stats.queue && stats.queue.active){
            if (stats.queue.processed !== lastProgress.processed || stats.queue.errors !== lastProgress.errors){
                lastProgress.processed = stats.queue.processed || 0;
                lastProgress.errors = stats.queue.errors || 0;
                lastProgress.timestamp = Date.now();
                autoRetryCooldown = false;
            } else if (!running && restQueue && !autoRetryCooldown && (Date.now() - lastProgress.timestamp) >= autoRetryInterval){
                autoRetryCooldown = true;
                setStatus(l10n.autoRetry || 'No progress detected. Attempting automatic retry…', true);
                runQueueBatch(false);
            }
        } else {
            lastProgress = {
                processed: stats.queue ? stats.queue.processed || 0 : 0,
                errors: stats.queue ? stats.queue.errors || 0 : 0,
                timestamp: Date.now()
            };
            autoRetryCooldown = false;
        }

        if ($dashboard.length){
            const $logWrap = elements.queueLog.length ? elements.queueLog : $dashboard.find('.ai-alt-queue-log');
            const $logList = $logWrap.find('ul');
            if ($logWrap.length){
                $logWrap.toggleClass('is-hidden', !(stats.queue && stats.queue.active));
            }
            if ($logList.length){
                $logList.empty();
                if (stats.queue && stats.queue.last_messages && stats.queue.last_messages.length){
                    stats.queue.last_messages.forEach(function(msg){
                        $logList.append($('<li/>').text(msg));
                    });
                } else {
                    $logList.append($('<li/>').text(l10n.noMessages || 'No queue messages yet.'));
                }
            }
        }
    }

    function fetchLatestStats(){
        if (!restStats){ return; }
        fetch(restStats, {
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': dash.nonce,
                'Accept': 'application/json'
            }
        })
        .then(function(res){ return res.ok ? res.json() : null; })
        .then(function(data){
            if (data){
                renderStats(data);
            }
        })
        .catch(function(err){ console.error('AI ALT stats fetch', err); });
    }

    function handleQueueResponse(res){
        if (res && res.queued){
            running = false;
            runScope = null;
            totals = { processed: 0, errors: 0, attempted: 0 };
            cursor = 0;
            targetTotal = 0;
            renderStats(res.stats || currentStats);
            setStatus(l10n.queueQueued || 'Queued for background processing.', true);
            return true;
        }
        return false;
    }

    function requestBatch(){
        if (!running || !restMissing){
            return;
        }

        setStatus((l10n.processing || 'Generating ALT text…') + progressSuffix(), true);

        const payload = { batch: 5, scope: runScope };
        if (runScope === 'all'){
            payload.cursor = cursor;
        }

        $.ajax({
            url: restMissing,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', dash.nonce); }
        }).done(function(res){
            if (handleQueueResponse(res)){
                return;
            }

            if (!res || typeof res !== 'object'){
                throw new Error('Invalid response');
            }

            totals.processed += res.processed || 0;
            totals.errors   += res.errors || 0;
            totals.attempted += res.attempted || 0;

            if (res.stats){
                renderStats(res.stats);
            }

            if (runScope === 'all' && typeof res.next_cursor === 'number'){
                cursor = res.next_cursor;
            }

            if (res.messages && res.messages.length){
                console.warn('AI ALT', res.messages);
            }

            if (!res.completed && (res.processed > 0 || (runScope === 'all' && (res.attempted || 0) > 0))){
                setTimeout(requestBatch, 350);
            } else {
                const summary = (l10n.summary || 'Generated %1$d images (%2$d errors).')
                    .replace('%1$d', totals.processed)
                    .replace('%2$d', totals.errors);
                const completeText = runScope === 'all'
                    ? (l10n.completeAll || 'All images regenerated.')
                    : (l10n.complete || 'All missing ALT text processed!');
                setStatus(summary + ' ' + completeText, false);
                stopProcessing();
                fetchLatestStats();
            }
        }).fail(function(xhr){
            console.error('AI ALT', xhr);
            setStatus(l10n.error || 'Something went wrong. Check console for details.', false);
            stopProcessing();
        });
    }

    function stopProcessing(){
        running = false;
        runScope = null;
        cursor = 0;
        targetTotal = 0;
        updateButtons(!!(currentStats.queue && currentStats.queue.active));
    }

    function startProcessing(scope){
        if (!restMissing || running || (currentStats.queue && currentStats.queue.active)){
            return;
        }
        totals = { processed: 0, errors: 0, attempted: 0 };
        runScope = scope;
        cursor = 0;
        targetTotal = scope === 'all' ? (currentStats.total || 0) : (currentStats.missing || 0);
        running = true;
        updateButtons(false);
        requestBatch();
    }

    function initButtons(){
        if (!restMissing){
            if (elements.btnMissing.length){
                elements.btnMissing.prop('disabled', true).text(l10n.restUnavailable);
            }
            if (elements.btnAll.length){
                elements.btnAll.prop('disabled', true).text(l10n.restUnavailable);
            }
            return;
        }
        if (elements.btnMissing.length){
            elements.btnMissing.on('click', function(){ startProcessing('missing'); });
        }
        if (elements.btnAll.length){
            elements.btnAll.on('click', function(){ startProcessing('all'); });
        }
    }

    function initStopQueue(){
        if ((!elements.stopQueue.length && !elements.runBatch.length) || !restQueue){
            return;
        }
        elements.stopQueue.on('click', function(){
            const $btn = $(this);
            if ($btn.prop('disabled')){ return; }
            $btn.prop('disabled', true).text(l10n.stopProgress || 'Stopping…');
            fetch(restQueue, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': dash.nonce,
                    'Accept': 'application/json'
                }
            })
            .then(function(res){ return res.ok ? res.json() : null; })
            .then(function(data){
                if (!data){ throw new Error('empty'); }
                if (data.stats){
                    renderStats(data.stats);
                }
                setStatus(l10n.queueCleared || 'Background queue cancelled.', false);
            })
            .catch(function(){
                $btn.prop('disabled', false).text(l10n.stopLabel || 'Force Stop');
                setStatus(l10n.stopError || 'Unable to stop queue.', false);
            });
            });

        elements.runBatch.on('click', function(){ runQueueBatch(true); });
    }

    window.addEventListener('ai-alt-stats-update', function(evt){
        if (evt && evt.detail){
            renderStats(evt.detail);
        }
    });

    initButtons();
    initStopQueue();
    renderStats(currentStats);
})(jQuery);
