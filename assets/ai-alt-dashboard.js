/**
 * Farlo AI Alt Text Generator (GPT) - Dashboard
 * 
 * Handles dashboard functionality including stats, batch generation,
 * usage tracking, ALT library, and interactive visualizations.
 * 
 * @package Farlo_AI_Alt_GPT
 * @version 3.0.0
 */

(function($){
    const dash = window.AI_ALT_GPT_DASH || {};
    const restEndpoints = {
        generate: dash.rest || (window.AI_ALT_GPT && AI_ALT_GPT.rest) || '',
        stats: dash.restStats || (window.AI_ALT_GPT && AI_ALT_GPT.restStats) || '',
        missing: dash.restMissing || (window.AI_ALT_GPT && AI_ALT_GPT.restMissing) || '',
        all: dash.restAll || (window.AI_ALT_GPT && AI_ALT_GPT.restAll) || ''
    };
    const nonce = dash.nonce || (window.AI_ALT_GPT && AI_ALT_GPT.nonce) || '';
    const $dashboard = $('.ai-alt-dashboard--primary');
    const $usage = $('.ai-alt-dashboard--usage');

    if (!$dashboard.length && !$usage.length) {
        return;
    }

    const palette = {
        accent: '#2271b1',
        warning: '#d63638',
        track: 'rgba(208, 215, 222, 0.6)',
        text: '#1d2327',
        textMuted: '#50575e'
    };

    const LOG_LIMIT = 12;

    const elements = {
        bar: $dashboard.find('.ai-alt-progress__bar span'),
        progressWrap: $dashboard.find('.ai-alt-progress__bar'),
        coverageHint: $dashboard.find('[data-coverage-hint]'),
        coverageValue: $dashboard.find('#ai-alt-coverage-value'),
        coverageSummary: $dashboard.find('#ai-alt-coverage-summary'),
        coverageViz: $dashboard.find('[data-coverage-viz]'),
        coverageBadge: $dashboard.find('[data-coverage-badge]'),
        coverageLegend: $dashboard.find('[data-coverage-legend]'),
        auditBody: $dashboard.find('.ai-alt-audit-rows'),
        progressLog: $dashboard.find('[data-progress-log]'),
        stats: {
            total: $dashboard.find('[data-stat="total"]'),
            withAlt: $dashboard.find('[data-stat="with-alt"]'),
            missing: $dashboard.find('[data-stat="missing"]'),
            generated: $dashboard.find('[data-stat="generated"]'),
            tokens: $dashboard.find('[data-stat="tokens"]')
        },
        recentContainer: $dashboard.find('[data-recent-list]'),
        statusBar: $dashboard.find('[data-progress-status]'),
        buttons: {
            missing: $dashboard.find('[data-action="generate-missing"]'),
            all: $dashboard.find('[data-action="regenerate-all"]')
        },
        usage: {
            requests: $usage.find('.ai-alt-usage__value--requests'),
            prompt: $usage.find('.ai-alt-usage__value--prompt'),
            completion: $usage.find('.ai-alt-usage__value--completion'),
            last: $usage.find('.ai-alt-usage__value--last')
        },
        auditPagination: $usage.find('[data-audit-pagination]')
    };

    const canvas = $dashboard.length ? document.getElementById('ai-alt-coverage') : null;
    const ctx = canvas ? canvas.getContext('2d') : null;

    function parseResponse(res){
        const contentType = (res.headers && res.headers.get) ? (res.headers.get('content-type') || '') : '';
        const isJson = contentType.indexOf('application/json') !== -1;
        const reader = isJson ? res.json() : res.text();

        return reader.then(function(payload){
            if (!res.ok){
                let errorPayload = payload;
                if (!isJson){
                    if (typeof payload === 'string' && payload.trim().length){
                        try {
                            errorPayload = JSON.parse(payload);
                        } catch (e){
                            errorPayload = { message: payload };
                        }
                    } else {
                        errorPayload = { message: payload };
                    }
                }
                if (typeof errorPayload === 'string'){
                    errorPayload = { message: errorPayload };
                }
                if (errorPayload && typeof errorPayload === 'object'){
                    if (!errorPayload.status){ errorPayload.status = res.status; }
                    if (!errorPayload.statusText){ errorPayload.statusText = res.statusText; }
                }
                throw errorPayload;
            }

            if (!isJson && typeof payload === 'string'){ // fallback to JSON when text response actually JSON
                try {
                    return JSON.parse(payload);
                } catch (err){
                    return payload;
                }
            }

            return payload;
        }).catch(function(err){
            if (!res.ok){
                if (typeof err === 'string'){
                    err = { message: err, status: res.status };
                } else if (err && typeof err === 'object' && !err.status){
                    err.status = res.status;
                }
                throw err;
            }
            throw err;
        });
    }

    function formatErrorMessage(err){
        const base = (err && (err.message || (err.data && err.data.message))) || (dash.l10n && dash.l10n.error) || 'Failed to generate ALT.';
        if (err && err.status){
            return base + ' (HTTP ' + err.status + ')';
        }
        return base;
    }

    function fmtNumber(num){
        return (Number(num) || 0).toLocaleString();
    }

    function esc(text){
        return (text === undefined || text === null) ? '' : String(text).replace(/[&<>"']/g, function(ch){
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return map[ch] || ch;
        });
    }

    function clampPercent(value){
        const raw = Number(value || 0);
        return Math.max(0, Math.min(100, raw));
    }

    function updateCoverageUI(stats){
        if (!$dashboard.length){ return; }

        const limited = clampPercent(stats.coverage);
        const display = limited.toFixed(1).replace(/\.0$/, '');
        const percent = display + '%';
        const ariaValue = (dash.l10n && dash.l10n.coverageValue ? dash.l10n.coverageValue : 'ALT coverage at %s').replace('%s', percent);

        if (elements.coverageHint.length){
            const suffix = dash.l10n && dash.l10n.coverageSuffix ? dash.l10n.coverageSuffix : 'coverage';
            elements.coverageHint.text(percent + ' ' + suffix);
        }

        if (elements.coverageValue.length){
            elements.coverageValue.text(percent);
        } else {
            const $fallback = $dashboard.find('.ai-alt-dashboard__coverage strong').first();
            if ($fallback.length){
                $fallback.text(percent);
            }
        }

        if (elements.coverageSummary.length){
            elements.coverageSummary.attr('data-coverage', percent);
        }

        if (elements.bar.length){
            elements.bar.css('width', limited + '%');
        }

        if (elements.progressWrap && elements.progressWrap.length){
            elements.progressWrap.attr({
                'aria-valuenow': limited.toFixed(1),
                'aria-valuetext': ariaValue
            });
        }

        return { limited, percent };
    }

    function drawChart(stats){
        if (!ctx || !canvas){
            return;
        }

        const total = Math.max(0, (stats.with_alt || 0) + (stats.missing || 0));
        const withAltFraction = total ? (stats.with_alt || 0) / total : 0;
        const missingFraction = 1 - withAltFraction;

        const chartSize = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--ai-alt-chart-size')) || 220;
        if (canvas.width !== chartSize || canvas.height !== chartSize) {
            canvas.width = chartSize;
            canvas.height = chartSize;
        }

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const centerX = canvas.width / 2;
        const centerY = canvas.height / 2;
        const radius  = Math.min(centerX, centerY) - 18;
        const lineWidth = 22;
        ctx.lineCap = 'round';

        const baseGradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
        baseGradient.addColorStop(0, 'rgba(226, 232, 240, 0.6)');
        baseGradient.addColorStop(1, 'rgba(203, 213, 225, 0.35)');

        ctx.beginPath();
        ctx.lineWidth = lineWidth;
        ctx.strokeStyle = baseGradient;
        ctx.shadowColor = 'rgba(15, 23, 42, 0.12)';
        ctx.shadowBlur = 12;
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.stroke();
        ctx.shadowBlur = 0;

        function gradientFor(colorA, colorB){
            const grad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            grad.addColorStop(0, colorA);
            grad.addColorStop(1, colorB);
            return grad;
        }

        function drawArc(start, fraction, gradient){
            if (fraction <= 0){ return; }
            ctx.beginPath();
            ctx.strokeStyle = gradient;
            ctx.lineWidth = lineWidth;
            ctx.shadowColor = 'rgba(37, 99, 235, 0.3)';
            ctx.shadowBlur = 8;
            ctx.arc(centerX, centerY, radius, start, start + fraction * Math.PI * 2, false);
            ctx.stroke();
            ctx.shadowBlur = 0;
        }

        const accentGradient = gradientFor('#2563eb', '#1d4ed8');
        const warningGradient = gradientFor('#f97316', '#ef4444');

        drawArc(-Math.PI / 2, withAltFraction, accentGradient);
        drawArc(-Math.PI / 2 + withAltFraction * Math.PI * 2, missingFraction, warningGradient);

        ctx.beginPath();
        ctx.fillStyle = 'rgba(255, 255, 255, 0.94)';
        ctx.shadowColor = 'rgba(15, 23, 42, 0.08)';
        ctx.shadowBlur = 10;
        ctx.arc(centerX, centerY, radius - lineWidth + 10, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;

        const coverage = clampPercent(stats.coverage);
        const coverageText = coverage.toFixed(1).replace(/\.0$/, '') + '%';

        ctx.font = '600 28px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = palette.text;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(coverageText, centerX, centerY - 6);

        ctx.font = '13px "Inter", "Segoe UI", sans-serif';
        ctx.fillStyle = palette.textMuted;
        ctx.fillText('coverage', centerX, centerY + 18);
    }

    function updateCards(stats){
        if (!elements.stats){ return; }
        if (elements.stats.total && elements.stats.total.length){
            elements.stats.total.text(fmtNumber(stats.total || 0));
        }
        if (elements.stats.withAlt && elements.stats.withAlt.length){
            elements.stats.withAlt.text(fmtNumber(stats.with_alt || 0));
        }
        if (elements.stats.missing && elements.stats.missing.length){
            elements.stats.missing.text(fmtNumber(stats.missing || 0));
        }
        if (elements.stats.generated && elements.stats.generated.length){
            elements.stats.generated.text(fmtNumber(stats.generated || 0));
        }
        const totalTokens = (stats.usage && stats.usage.total) || stats.total_tokens;
        if (elements.stats.tokens && elements.stats.tokens.length){
            elements.stats.tokens.text(fmtNumber(totalTokens || 0));
        }
    }

    function setStatus(message, active){
        if (!elements.statusBar.length){ return; }
        const display = (typeof message === 'string' && message.length) ? message : '';
        elements.statusBar.text(display);
        elements.statusBar.toggleClass('is-active', !!active && !!display);
    }

    function formatTime(){
        const now = new Date();
        return now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }

    function clearLog(){
        if (elements.progressLog.length){
            elements.progressLog.empty();
        }
    }

    function pushLog(message, type){
        if (!elements.progressLog.length || !message){ return; }
        const $entry = $('<div class="ai-alt-progress-log__item"/>')
            .attr('data-type', type || 'info')
            .text(message);
        elements.progressLog.prepend($entry);
        const $entries = elements.progressLog.find('.ai-alt-progress-log__item');
        if ($entries.length > LOG_LIMIT){
            $entries.slice(LOG_LIMIT).remove();
        }
    }

    function setButtonLoading($button, isLoading){
        if (!$button || !$button.length){ return; }
        const original = $button.data('label-original') || $button.text();
        if (!$button.data('label-original')){
            $button.data('label-original', original);
        }

        if (isLoading){
            const loadingText = (dash.l10n && dash.l10n.loadingButton) || 'Processing…';
            $button.text(loadingText).addClass('is-loading').prop('disabled', true);
        } else {
            const restore = $button.data('label-original');
            if (restore){
                $button.text(restore);
            }
            $button.removeClass('is-loading').prop('disabled', false);
        }
    }

    function updateUsage(usage){
        if (!$usage.length){ return; }
        if (elements.usage.requests.length){ elements.usage.requests.text(fmtNumber(usage.requests || 0)); }
        if (elements.usage.prompt.length){ elements.usage.prompt.text(fmtNumber(usage.prompt || 0)); }
        if (elements.usage.completion.length){ elements.usage.completion.text(fmtNumber(usage.completion || 0)); }
        if (elements.usage.last.length){
            const fallback = (dash.l10n && dash.l10n.noRequests) ? dash.l10n.noRequests : 'None yet';
            const display = usage.last_request_formatted || usage.last_request || fallback;
            elements.usage.last.text(display);
        }
    }

    function renderAudit(rows, meta){
        if (!elements.auditBody.length){ return; }
        const fallback = (dash.l10n && dash.l10n.noAudit) ? dash.l10n.noAudit : 'No usage data recorded yet.';
        elements.auditBody.empty();
        if (!rows || !rows.length){
            elements.auditBody.append('<tr class="ai-alt-audit__empty"><td colspan="6">' + fallback + '</td></tr>');
            if (elements.auditPagination.length){
                const emptyLinks = meta && meta.audit_links ? meta.audit_links : (dash.auditLinks || '');
                elements.auditPagination.html(emptyLinks);
            }
            return;
        }

        rows.forEach(function(row){
            const $tr = $('<tr/>').attr('data-id', row.id);

            const editUrl = row.edit_url || '#';
            const $title = $('<td/>');
            $('<a/>', {
                href: editUrl,
                text: row.title || ('#' + row.id)
            }).appendTo($title);
            $tr.append($title);

            const sourceKey = (row.source || 'unknown').toLowerCase();
            const sourceLabel = row.source_label || sourceKey.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
            const sourceDesc = row.source_description || '';
            $tr.append(
                $('<td/>').addClass('ai-alt-audit__source').append(
                    $('<span/>')
                        .addClass('ai-alt-badge ai-alt-badge--' + sourceKey)
                        .attr('title', sourceDesc)
                        .text(sourceLabel)
                )
            );

            $tr.append($('<td/>').addClass('ai-alt-audit__tokens').text(fmtNumber(row.tokens || 0)));
            $tr.append($('<td/>').text(fmtNumber(row.prompt || 0)));
            $tr.append($('<td/>').text(fmtNumber(row.completion || 0)));
            $tr.append($('<td/>').text(row.generated || ''));

            elements.auditBody.append($tr);
        });

        if (elements.auditPagination.length){
            const paginationHtml = meta && meta.audit_links ? meta.audit_links : (dash.auditLinks || '');
            elements.auditPagination.html(paginationHtml);
        }
    }

    function renderRecent(items, meta){
        if (!elements.recentContainer.length){ return; }
        const $container = elements.recentContainer;
        const $list = $container.find('.ai-alt-recent__list');
        const $empty = $container.find('.ai-alt-recent__empty');
        const $pagination = $container.find('[data-recent-pagination]');

        const currentPage = meta && meta.recent_page ? Number(meta.recent_page) : (dash.recentPage || 1);
        const totalPages = meta && meta.recent_pages ? Number(meta.recent_pages) : (dash.recentPages || 1);

        if (!items || !items.length){
            if ($list.length){ $list.remove(); }
            if ($empty.length){
                $empty.show();
            } else {
                $container.append('<p class="ai-alt-recent__empty">' + (dash.l10n && dash.l10n.noRecent ? dash.l10n.noRecent : 'Generate ALT text for an image to see recent results here.') + '</p>');
            }
            if ($pagination.length){
                const html = meta && meta.recent_links ? meta.recent_links : '';
                $pagination.html(html);
            }
            $container.attr('data-recent-page', currentPage).attr('data-recent-pages', totalPages);
            return;
        }

        $container.find('.ai-alt-recent__empty').remove();
        if (!$list.length){
            $container.append('<ul class="ai-alt-recent__list"></ul>');
        }

        const $target = $container.find('.ai-alt-recent__list');
        $target.empty();

        items.forEach(function(item){
            const thumb = item.thumb ? '<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' : '<span aria-hidden="true">📷</span>';
            const sourceKey = esc(item.source || 'unknown');
            const sourceLabel = esc(item.source_label || item.source || 'unknown');
            const sourceDesc = esc(item.source_description || '');
            const badge = '<span class="ai-alt-badge ai-alt-badge--' + sourceKey + '" title="' + sourceDesc + '">' + sourceLabel + '</span>';
            const li = [
                '<li class="ai-alt-recent__item" data-id="' + esc(item.id) + '">',
                    '<div class="ai-alt-recent__thumb">' + thumb + '</div>',
                    '<div class="ai-alt-recent__body">',
                        '<p class="ai-alt-recent__title"><a href="' + esc(item.edit_url || '#') + '">' + esc(item.title || ('#' + item.id)) + '</a></p>',
                        '<div class="ai-alt-recent__meta">',
                            '<span>' + esc(item.generated || '') + '</span> · ' + badge,
                        '</div>',
                        '<p class="ai-alt-recent__alt">' + esc(item.alt || '') + '</p>',
                        '<div class="ai-alt-recent__actions">',
                            '<button type="button" class="button button-small ai-alt-button ai-alt-button--ghost ai-alt-regenerate-single" data-id="' + esc(item.id) + '">' + (dash.l10n && dash.l10n.regenerate ? dash.l10n.regenerate : 'Regenerate') + '</button>',
                        '</div>',
                    '</div>',
                '</li>'
            ].join('');
            $target.append(li);
        });

        $container.attr('data-recent-page', currentPage).attr('data-recent-pages', totalPages);

        if ($pagination.length){
            const paginationHtml = meta && meta.recent_links ? meta.recent_links : (dash.recentLinks || '');
            $pagination.html(paginationHtml);
        }
    }

    function renderStats(stats){
        if (!stats){ return; }
        updateCards(stats);
        updateCoverageUI(stats);
        const chartStats = {
            with_alt: Number(stats.with_alt || 0),
            missing: Number(stats.missing || 0),
            coverage: Number(stats.coverage || 0)
        };
        drawChart(chartStats);
        const complete = chartStats.missing <= 0;
        if (elements.coverageBadge.length){
            elements.coverageBadge.prop('hidden', !complete);
        }
        if (elements.coverageLegend.length){
            elements.coverageLegend.prop('hidden', complete);
        }
        if (elements.coverageViz.length){
            elements.coverageViz.attr('data-coverage-complete', complete ? '1' : '0');
        }
        updateUsage(stats.usage || {});
        dash.auditPage = Number(stats.audit_page || dash.auditPage || 1);
        dash.auditPages = Number(stats.audit_pages || dash.auditPages || 1);
        dash.auditPerPage = Number(stats.audit_per_page || dash.auditPerPage || 10);
        dash.auditLinks = stats.audit_links || dash.auditLinks || '';
        dash.auditBase = stats.audit_base || dash.auditBase || '';
        renderAudit(stats.audit || [], stats);
        dash.recentPage = Number(stats.recent_page || dash.recentPage || 1);
        dash.recentPages = Number(stats.recent_pages || dash.recentPages || 1);
        dash.recentPerPage = Number(stats.recent_per_page || dash.recentPerPage || 5);
        dash.recentLinks = stats.recent_links || dash.recentLinks || '';
        dash.recentBase = stats.recent_base || dash.recentBase || '';
        renderRecent(stats.recent || [], stats);

        if (elements.buttons.missing.length){
            const disabled = !(stats.missing > 0);
            elements.buttons.missing.prop('disabled', disabled);
            elements.buttons.missing.toggleClass('is-disabled', disabled);
        }
        dash.stats = stats;
    }

    renderStats(dash.stats || {});
    dash.recentPerPage = dash.recentPerPage || (dash.stats && dash.stats.recent_per_page) || 5;
    dash.recentPage = dash.recentPage || (dash.stats && dash.stats.recent_page) || 1;
    dash.recentPages = dash.recentPages || (dash.stats && dash.stats.recent_pages) || 1;
    dash.recentLinks = dash.recentLinks || (dash.stats && dash.stats.recent_links) || '';
    dash.auditPerPage = dash.auditPerPage || (dash.stats && dash.stats.audit_per_page) || 10;
    dash.auditPage = dash.auditPage || (dash.stats && dash.stats.audit_page) || 1;
    dash.auditPages = dash.auditPages || (dash.stats && dash.stats.audit_pages) || 1;
    dash.auditLinks = dash.auditLinks || (dash.stats && dash.stats.audit_links) || '';
    setStatus('', false);

    window.addEventListener('ai-alt-stats-update', function(evt){
        const detail = evt.detail || {};
        const stats = detail.stats || detail;
        renderStats(stats);
        setStatus('', false);
    });

    function fetchStats(){
        if (!restEndpoints.stats){ return; }

        const statsUrl = new URL(restEndpoints.stats, window.location.origin);
        if (dash.recentPage){
            statsUrl.searchParams.set('recent_page', dash.recentPage);
        }
        if (dash.recentPerPage){
            statsUrl.searchParams.set('recent_per_page', dash.recentPerPage);
        }
        if (dash.auditPage){
            statsUrl.searchParams.set('audit_page', dash.auditPage);
        }
        if (dash.auditPerPage){
            statsUrl.searchParams.set('audit_per_page', dash.auditPerPage);
        }

        const headers = { 'Accept': 'application/json' };
        if (nonce){ headers['X-WP-Nonce'] = nonce; }

        fetch(statsUrl.toString(), {
            credentials: 'same-origin',
            headers
        }).then(parseResponse).then(function(data){
            if (data){
                renderStats(data);
                setStatus((dash.l10n && dash.l10n.statusReady) || '');
            }
        }).catch(function(err){
            if (err && err.code === 'duplicate_alt'){ return Promise.resolve(); }
            console.error('AI ALT stats fetch failed', err);
            const errorMsg = formatErrorMessage(err);
            setStatus(errorMsg, false);
            if (window.aiAltToast){
                window.aiAltToast.error('Stats Update Failed', errorMsg);
            }
        });
    }

    function seqGenerate(ids){
        if (!ids || !ids.length){
            const time = formatTime();
            const done = dash.l10n && dash.l10n.batchCompleteAt ? dash.l10n.batchCompleteAt.replace('%s', time) : ('Batch complete at ' + time);
            setStatus(done, false);
            pushLog(done, 'success');
            if (window.aiAltToast){
                window.aiAltToast.success('Batch Complete', 'All ALT text generation tasks finished successfully.');
            }
            fetchStats();
            return Promise.resolve();
        }

        const id = ids.shift();
        const msg = dash.l10n && dash.l10n.processingMissing ? dash.l10n.processingMissing.replace('%d', id) : ('Generating ALT for #' + id + '…');
        setStatus(msg, true);
        pushLog(msg + ' · ' + formatTime(), 'info');

        if (!restEndpoints.generate){
            setStatus((dash.l10n && dash.l10n.restUnavailable) || 'REST endpoint unavailable', false);
            return Promise.reject(false);
        }

        const headers = { 'Accept': 'application/json' };
        if (nonce){ headers['X-WP-Nonce'] = nonce; }

        return fetch(restEndpoints.generate + id, {
            method: 'POST',
            credentials: 'same-origin',
            headers
        }).then(parseResponse).then(function(){
            const done = dash.l10n && dash.l10n.completedItem ? dash.l10n.completedItem.replace('%d', id) : ('Finished #' + id);
            pushLog(done + ' · ' + formatTime(), 'success');
            return seqGenerate(ids);
        }).catch(function(err){
            if (err && err.code === 'duplicate_alt'){
                const next = ids.slice();
                return seqGenerate(next);
            }
            console.error('AI ALT batch generation error', err);
            const message = formatErrorMessage(err);
            setStatus(message, false);
            const fail = dash.l10n && dash.l10n.failedItem ? dash.l10n.failedItem.replace('%d', id) : ('Failed #' + id);
            pushLog(fail + ' · ' + formatTime() + ' — ' + message, 'error');
            if (window.aiAltToast){
                window.aiAltToast.error('Generation Failed', 'Failed to generate ALT for attachment #' + id + '. ' + message);
            }
            fetchStats();
        });
    }

    function requestList(scope){
        const endpoint = scope === 'all' ? restEndpoints.all : restEndpoints.missing;
        if (!endpoint){
            setStatus((dash.l10n && dash.l10n.restUnavailable) || 'REST endpoint unavailable', false);
            return Promise.reject();
        }
        setStatus((dash.l10n && dash.l10n.prepareBatch) || 'Preparing image list…', true);
        const headers = { 'Accept': 'application/json' };
        if (nonce){ headers['X-WP-Nonce'] = nonce; }
        return fetch(endpoint, {
            credentials: 'same-origin',
            headers
        }).then(parseResponse).then(function(data){
            return data;
        });
    }

    function handleGenerate(scope, $button){
        if (!restEndpoints.generate){
            setStatus((dash.l10n && dash.l10n.restUnavailable) || 'REST endpoint unavailable', false);
            if ($button){ setButtonLoading($button, false); }
            return;
        }
        requestList(scope).then(function(payload){
            const ids = Array.isArray(payload.ids) ? payload.ids.slice() : [];
            if (!ids.length){
                setStatus((dash.l10n && dash.l10n.nothingToProcess) || 'No images to process.', false);
                const none = (dash.l10n && dash.l10n.nothingToProcess) || 'No images to process.';
                pushLog(none + ' · ' + formatTime(), 'info');
                fetchStats();
                return Promise.resolve();
            }
            clearLog();
            pushLog(((dash.l10n && dash.l10n.batchStart) || 'Starting batch…') + ' (' + ids.length + ') · ' + formatTime(), 'info');
            return seqGenerate(ids);
        }).catch(function(err){
            if (!err){ return; }
            if (err === false){ return; }
            if (err && err.code === 'duplicate_alt'){ return; }
            console.error('AI ALT request list failed', err);
            const message = formatErrorMessage(err);
            setStatus(message, false);
            pushLog(message + ' · ' + formatTime(), 'error');
        }).finally(function(){
            if ($button){
                setButtonLoading($button, false);
            }
        });
    }

    if (elements.buttons.missing.length){
        elements.buttons.missing.on('click', function(e){
            e.preventDefault();
            if ($(this).prop('disabled')){ return; }
            const $btn = $(this);
            setButtonLoading($btn, true);
            handleGenerate('missing', $btn);
        });
    }

    if (elements.buttons.all.length){
        elements.buttons.all.on('click', function(e){
            e.preventDefault();
            const confirmMessage = (dash.l10n && dash.l10n.confirmRegenerateAll) || 'Regenerate ALT for all images?';
            if (!window.confirm(confirmMessage)){
                return;
            }
            const $btn = $(this);
            setButtonLoading($btn, true);
            handleGenerate('all', $btn);
        });
    }
    // Toast Notification System
    window.aiAltToast = (function(){
        let container = null;
        let toastId = 0;

        function getContainer(){
            if (!container){
                container = document.createElement('div');
                container.className = 'ai-alt-toast-container';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'false');
                document.body.appendChild(container);
            }
            return container;
        }

        function getIcon(type){
            const icons = {
                success: '✓',
                error: '✕',
                warning: '⚠',
                info: 'ℹ'
            };
            return icons[type] || icons.info;
        }

        function show(options){
            const id = ++toastId;
            const type = options.type || 'info';
            const title = options.title || '';
            const message = options.message || '';
            const duration = options.duration !== undefined ? options.duration : 5000;
            const closeable = options.closeable !== false;

            const toast = document.createElement('div');
            toast.className = 'ai-alt-toast ai-alt-toast--' + type;
            toast.setAttribute('role', 'status');
            toast.setAttribute('data-toast-id', id);

            const icon = document.createElement('div');
            icon.className = 'ai-alt-toast__icon';
            icon.textContent = getIcon(type);
            icon.setAttribute('aria-hidden', 'true');

            const content = document.createElement('div');
            content.className = 'ai-alt-toast__content';

            if (title){
                const titleEl = document.createElement('div');
                titleEl.className = 'ai-alt-toast__title';
                titleEl.textContent = title;
                content.appendChild(titleEl);
            }

            if (message){
                const messageEl = document.createElement('p');
                messageEl.className = 'ai-alt-toast__message';
                messageEl.textContent = message;
                content.appendChild(messageEl);
            }

            toast.appendChild(icon);
            toast.appendChild(content);

            if (closeable){
                const closeBtn = document.createElement('button');
                closeBtn.className = 'ai-alt-toast__close';
                closeBtn.setAttribute('type', 'button');
                closeBtn.setAttribute('aria-label', 'Close notification');
                closeBtn.textContent = '×';
                closeBtn.addEventListener('click', function(){
                    remove(id);
                });
                toast.appendChild(closeBtn);
            }

            if (duration > 0){
                const progress = document.createElement('div');
                progress.className = 'ai-alt-toast__progress';
                const progressBar = document.createElement('div');
                progressBar.className = 'ai-alt-toast__progress-bar';
                progressBar.style.width = '100%';
                progressBar.style.transitionDuration = duration + 'ms';
                progress.appendChild(progressBar);
                toast.appendChild(progress);

                requestAnimationFrame(function(){
                    requestAnimationFrame(function(){
                        progressBar.style.width = '0%';
                    });
                });

                setTimeout(function(){
                    remove(id);
                }, duration);
            }

            getContainer().appendChild(toast);

            return id;
        }

        function remove(id){
            const toast = container ? container.querySelector('[data-toast-id="' + id + '"]') : null;
            if (!toast){ return; }

            toast.classList.add('is-leaving');
            setTimeout(function(){
                if (toast.parentNode){
                    toast.parentNode.removeChild(toast);
                }
                if (container && container.children.length === 0){
                    container.parentNode.removeChild(container);
                    container = null;
                }
            }, 200);
        }

        function showWithRetry(title, message, retryCallback){
            const id = ++toastId;
            const toast = document.createElement('div');
            toast.className = 'ai-alt-toast ai-alt-toast--error';
            toast.setAttribute('role', 'alert');
            toast.setAttribute('data-toast-id', id);

            const icon = document.createElement('div');
            icon.className = 'ai-alt-toast__icon';
            icon.textContent = '✕';
            icon.setAttribute('aria-hidden', 'true');

            const content = document.createElement('div');
            content.className = 'ai-alt-toast__content';

            const titleEl = document.createElement('div');
            titleEl.className = 'ai-alt-toast__title';
            titleEl.textContent = title;
            content.appendChild(titleEl);

            if (message){
                const messageEl = document.createElement('p');
                messageEl.className = 'ai-alt-toast__message';
                messageEl.textContent = message;
                content.appendChild(messageEl);
            }

            const retryBtn = document.createElement('button');
            retryBtn.className = 'ai-alt-button ai-alt-button--outline';
            retryBtn.textContent = 'Retry';
            retryBtn.style.marginTop = '8px';
            retryBtn.style.fontSize = '12px';
            retryBtn.style.padding = '6px 14px';
            retryBtn.addEventListener('click', function(){
                remove(id);
                if (typeof retryCallback === 'function'){
                    retryCallback();
                }
            });
            content.appendChild(retryBtn);

            toast.appendChild(icon);
            toast.appendChild(content);

            const closeBtn = document.createElement('button');
            closeBtn.className = 'ai-alt-toast__close';
            closeBtn.setAttribute('type', 'button');
            closeBtn.setAttribute('aria-label', 'Close notification');
            closeBtn.textContent = '×';
            closeBtn.addEventListener('click', function(){
                remove(id);
            });
            toast.appendChild(closeBtn);

            getContainer().appendChild(toast);

            return id;
        }

        return {
            show: show,
            success: function(title, message, duration){
                return show({ type: 'success', title: title, message: message, duration: duration });
            },
            error: function(title, message, duration){
                return show({ type: 'error', title: title, message: message, duration: duration || 7000 });
            },
            warning: function(title, message, duration){
                return show({ type: 'warning', title: title, message: message, duration: duration });
            },
            info: function(title, message, duration){
                return show({ type: 'info', title: title, message: message, duration: duration });
            },
            errorWithRetry: showWithRetry,
            remove: remove
        };
    })();

})(jQuery);
