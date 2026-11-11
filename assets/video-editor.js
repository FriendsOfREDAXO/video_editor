/**
 * Video-Editor – Panel in der Medien-Detailansicht (section[data-video-editor]): Analyse, Optimieren, Poster, Aufträge.
 *
 * Läuft auch in Inhalten, die das Overlay des Cores nachlädt (rex:ready). Keine Inline-Styles und -Handler (CSP),
 * Dialoge mit der Dialog-Komponente des Cores.
 */
(() => {
    'use strict';

    const el = (tag, attrs = {}, ...children) => {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v === null || v === undefined || v === false) {
                continue;
            }
            if (k === 'class') {
                node.className = v;
            } else if (k === 'text') {
                node.textContent = v;
            } else {
                node.setAttribute(k, v === true ? '' : v);
            }
        }
        node.append(...children.flat().filter((c) => c !== null && c !== undefined && c !== false));
        return node;
    };
    const icon = (name) => el('i', { class: 'rex-icon ' + name, 'aria-hidden': 'true' });

    const time = (s) => {
        s = Math.max(0, Math.floor(s));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = String(s % 60).padStart(2, '0');
        return h ? h + ':' + String(m).padStart(2, '0') + ':' + sec : m + ':' + sec;
    };
    // genaue Zeit mit Zehntelsekunden, z. B. 1:05.3
    const clock = (s) => {
        // auf Millisekunden runden: 4.6 kommt als 4.5999… an
        const ms = Math.max(0, Math.round(s * 1000));
        return time(Math.floor(ms / 1000)) + '.' + Math.floor((ms % 1000) / 100);
    };
    const fill = (text, ...args) => text.replace(/\{(\d)\}/g, (_, i) => args[Number(i)] ?? '');
    const mbit = (b) => (b / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' Mbit/s';

    const init = (section) => {
        if (section.dataset.veReady) {
            return;
        }
        section.dataset.veReady = '1';
        const cfg = JSON.parse(section.dataset.videoEditor);
        const t = cfg.i18n;
        let data = null;
        let poll = 0;

        const call = async (action, extra = {}) => {
            const body = new FormData();
            body.append('action', action);
            body.append('file', cfg.file);
            for (const [k, v] of Object.entries(extra)) {
                body.append(k, v);
            }
            const res = await fetch(cfg.api, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const json = await res.json().catch(() => ({ ok: false, error: t.error }));
            if (!json.ok) {
                throw new Error(json.error || t.error);
            }
            return json;
        };

        const alertBox = (variant, text) => el('div', { class: 'rex-alert', 'data-variant': variant, role: 'alert' }, text);

        // ------------------------------------------------------------------------------------------ Darstellung

        // Vorschau: immer dasselbe Element, damit das Neuzeichnen (Auftrags-Abfrage) die Wiedergabe nicht unterbricht
        let preview = null;

        const render = () => {
            const a = data.analysis;
            if (!preview) {
                preview = el('video', { class: 've-preview', src: data.stream || data.url, controls: true, preload: 'metadata', playsinline: true, 'aria-label': t.preview });
            }
            if (data.poster && data.poster.src) {
                preview.poster = data.poster.src;
            } else {
                preview.removeAttribute('poster');
            }
            const parts = [el('h3', { class: 've-title' }, icon('fa-solid fa-film'), ' ' + t.title), preview];

            if (!data.available) {
                parts.push(alertBox('warning', t.no_ffmpeg));
                section.replaceChildren(...parts);
                return;
            }
            if (a) {
                const level = a.score >= 80 ? 'good' : a.score >= 50 ? 'ok' : 'bad';
                parts.push(el('div', { class: 've-score ve-score--' + level },
                    el('span', { class: 've-score-value', text: String(a.score) }),
                    el('span', { class: 've-score-label', text: t.score }),
                ));
                parts.push(el('dl', { class: 've-facts' },
                    el('dt', { text: t.duration }), el('dd', { text: time(a.duration) }),
                    el('dt', { text: t.resolution }), el('dd', { text: a.width + ' × ' + a.height + (a.fps ? ' · ' + a.fps + ' fps' : '') }),
                    el('dt', { text: t.codec }), el('dd', { text: [a.videoCodec, a.audioCodec || t.no_audio].filter(Boolean).join(' · ') }),
                    el('dt', { text: t.bitrate }), el('dd', { text: mbit(a.bitrate) + ' · ' + data.size }),
                    a.faststart === null ? null : [el('dt', { text: 'Start' }), el('dd', { text: a.faststart ? t.faststart_yes : t.faststart_no })],
                ));
                parts.push(a.hints.length
                    ? el('ul', { class: 've-hints' }, a.hints.map((h) => el('li', {},
                        el('span', { text: h.text }),
                        h.preset && data.canEdit ? el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-ve-preset': h.preset }, t.apply) : null,
                    )))
                    : el('p', { class: 've-ok' }, icon('rex-icon-active-true'), ' ' + t.hints_none));
            }

            // ohne Bearbeitungsrecht nur ansehen
            if (data.canEdit) parts.push(el('div', { class: 've-actions' },
                el('button', { type: 'button', class: 'rex-button', 'data-variant': 'primary', 'data-ve-open': 'optimize' }, icon('fa-solid fa-wand-magic-sparkles'), ' ' + t.optimize),
                el('button', { type: 'button', class: 'rex-button', 'data-ve-open': 'cut' }, icon('fa-solid fa-scissors'), ' ' + t.cut),
                el('button', { type: 'button', class: 'rex-button', 'data-ve-open': 'poster' }, icon('fa-regular fa-image'), ' ' + t.poster),
            ));

            if (data.poster) {
                parts.push(el('a', { class: 've-poster', href: data.poster.url },
                    el('img', { src: data.poster.thumb, alt: '' }), el('span', { text: t.poster_current })));
            }
            parts.push(el('div', { class: 've-jobs' }));
            parts.push(el('div', { class: 've-versions' }));
            section.replaceChildren(...parts);
            renderJobs();
        };

        const renderJobs = () => {
            const jobsBox = section.querySelector('.ve-jobs');
            const versionsBox = section.querySelector('.ve-versions');
            if (!jobsBox || !versionsBox) {
                return;
            }
            jobsBox.replaceChildren(...(data.jobs.length ? [
                el('h4', { text: t.jobs }),
                el('ul', { class: 've-job-list' }, data.jobs.map((j) => el('li', { class: 've-job ve-job--' + j.status },
                    el('div', { class: 've-job-head' },
                        el('strong', { text: j.label }),
                        el('span', { class: 've-badge', text: t['status_' + j.status] || j.status }),
                    ),
                    j.status === 'running' || j.status === 'queued'
                        ? el('progress', { max: '100', value: String(j.progress), 'aria-label': j.label + ' ' + j.progress + ' %' })
                        : null,
                    j.message ? el('p', { class: 've-job-message', text: j.message }) : null,
                    el('div', { class: 've-job-actions' },
                        data.canEdit && (j.status === 'running' || j.status === 'queued')
                            ? el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-ve-cancel': j.id }, t.cancel) : null,
                        data.canEdit && (j.status === 'failed' || j.status === 'canceled')
                            ? el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-ve-retry': j.id }, t.retry) : null,
                        j.status === 'done' && j.resultUrl && j.result !== cfg.file
                            ? el('a', { class: 'rex-button', 'data-size': 'xs', href: j.resultUrl }, t.open + ': ' + j.result) : null,
                    ),
                ))),
            ] : []));

            versionsBox.replaceChildren(...(data.versions.length ? [
                el('h4', { text: t.versions }),
                el('ul', { class: 've-version-list' }, data.versions.map((v) => el('li', {},
                    el('a', { href: v.url, text: v.file }), el('span', { class: 've-muted', text: ' · ' + (v.label || '') + ' · ' + v.size }),
                ))),
            ] : []));

            const busy = data.jobs.some((j) => j.status === 'running' || j.status === 'queued');
            clearTimeout(poll);
            if (busy) {
                poll = setTimeout(refreshJobs, 1500);
            }
        };

        const refreshJobs = async () => {
            if (!section.isConnected) {
                return;
            }
            try {
                const json = await call('jobs');
                const finished = data.jobs.some((j) => (j.status === 'running' || j.status === 'queued')
                    && json.jobs.some((n) => n.id === j.id && n.status === 'done'));
                data.jobs = json.jobs;
                data.versions = json.versions;
                // ersetztes Original: Analyse neu laden
                if (finished) {
                    load();
                    return;
                }
                renderJobs();
            } catch {
                poll = setTimeout(refreshJobs, 5000);
            }
        };

        const load = async () => {
            try {
                data = await call('info');
                render();
            } catch (e) {
                section.replaceChildren(alertBox('danger', e.message));
            }
        };

        // ------------------------------------------------------------------------------------------ Dialoge

        const dialog = (title, body, footer, wide = false) => {
            const close = el('button', { type: 'button', class: 'rex-dialog-close', 'aria-label': t.close }, icon('fa-solid fa-xmark'));
            const d = el('dialog', { class: 'rex-dialog ve-dialog' + (wide ? ' ve-dialog--wide' : ''), 'aria-label': title },
                el('div', { class: 'rex-dialog-inner' },
                    el('div', { class: 'rex-dialog-header' }, el('h2', { class: 'rex-dialog-title', text: title }), close),
                    el('div', { class: 'rex-dialog-body' }, body),
                    el('div', { class: 'rex-dialog-footer' }, footer),
                ),
            );
            close.addEventListener('click', () => d.close());
            d.addEventListener('close', () => d.remove());
            (section.closest('dialog') || document.body).append(d);
            d.showModal();
            return d;
        };

        const openOptimize = (preselect) => {
            const presets = data.presets;
            const chosen = preselect || presets.find((p) => p.recommended)?.id || presets[0].id;
            const list = el('fieldset', { class: 've-presets' }, el('legend', { class: 'rex-u-visually-hidden', text: t.optimize_title }),
                presets.map((p) => el('label', { class: 've-preset' },
                    el('input', { type: 'radio', name: 've-preset', value: p.id, checked: p.id === chosen }),
                    el('span', { class: 've-preset-text' },
                        el('strong', { text: p.label }),
                        p.recommended ? el('span', { class: 've-badge ve-badge--accent', text: t.recommended }) : null,
                        p.fast ? el('span', { class: 've-badge', text: t.fast }) : null,
                        el('small', { text: p.text + ' · .' + p.extension }),
                    ),
                )));
            const mode = el('fieldset', { class: 've-mode' }, el('legend', { text: t.mode }),
                el('label', {}, el('input', { type: 'radio', name: 've-mode', value: 'version', checked: true }), ' ' + t.mode_version),
                // Original ersetzen nur mit eigenem Recht
                data.canReplace ? el('label', {}, el('input', { type: 'radio', name: 've-mode', value: 'replace' }), ' ' + t.mode_replace,
                    el('small', { class: 've-muted', text: ' (' + t.mode_replace_note + ')' })) : null,
            );
            const replaceInput = mode.querySelector('input[value="replace"]');
            const sync = () => {
                const p = presets.find((x) => x.id === list.querySelector('input:checked')?.value);
                if (!replaceInput) return;
                replaceInput.disabled = !p?.replace;
                if (replaceInput.disabled && replaceInput.checked) {
                    mode.querySelector('input[value="version"]').checked = true;
                }
            };
            list.addEventListener('change', sync);
            sync();

            const start = el('button', { type: 'button', class: 'rex-button', 'data-variant': 'primary' }, t.start);
            const cancel = el('button', { type: 'button', class: 'rex-button' }, t.cancel);
            const d = dialog(t.optimize_title, [list, mode], [cancel, start]);
            cancel.addEventListener('click', () => d.close());
            start.addEventListener('click', async () => {
                start.disabled = true;
                try {
                    const json = await call('optimize', {
                        preset: list.querySelector('input:checked').value,
                        mode: mode.querySelector('input:checked').value,
                    });
                    data.jobs = json.jobs;
                    d.close();
                    renderJobs();
                } catch (e) {
                    start.disabled = false;
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', e.message));
                }
            });
        };

        const openPoster = () => {
            const video = el('video', { class: 've-poster-video', src: data.stream || data.url, controls: true, preload: 'metadata', playsinline: true, muted: true });
            const position = el('output', { class: 've-poster-time', text: '0:00' });
            const step = (delta) => {
                video.pause();
                video.currentTime = Math.max(0, Math.min(video.duration || 0, video.currentTime + delta));
            };
            const nav = el('div', { class: 've-poster-nav' },
                el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-step': '-1' }, '−1 s'),
                el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-step': '-0.04' }, '−1 Bild'),
                position,
                el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-step': '0.04' }, '+1 Bild'),
                el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-step': '1' }, '+1 s'),
            );
            nav.addEventListener('click', (e) => {
                const b = e.target.closest('[data-step]');
                if (b) {
                    step(Number(b.dataset.step));
                }
            });
            video.addEventListener('timeupdate', () => {
                position.textContent = clock(video.currentTime);
            });
            const take = el('button', { type: 'button', class: 'rex-button', 'data-variant': 'primary' }, icon('fa-regular fa-image'), ' ' + t.poster_take);
            const cancel = el('button', { type: 'button', class: 'rex-button' }, t.cancel);
            const d = dialog(t.poster, [video, nav], [cancel, take]);
            cancel.addEventListener('click', () => d.close());
            take.addEventListener('click', async () => {
                take.disabled = true;
                try {
                    data = await call('poster', { time: String(video.currentTime) });
                    d.close();
                    render();
                } catch (e) {
                    take.disabled = false;
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', e.message));
                }
            });
        };

        // ------------------------------------------------------------------------------------------ Schneiden

        const openCut = () => {
            const duration = data.analysis?.duration || 0;
            const frame = 1 / (data.analysis?.fps || 25);
            const MIN = 0.1;
            let segs = [[0, duration]];
            let sel = 0;
            let history = [];
            let previewing = false;
            let keyframes = [];
            const isExact = () => method.querySelector('input:checked').value === '1';
            // schnell: Abschnitte beginnen am Schlüsselbild davor (wie auf dem Server), Überlappungen verschmelzen
            const effective = () => {
                if (isExact() || !keyframes.length) return segs.map((s) => [...s]);
                const out = [];
                for (const [a, b] of segs) {
                    let k = 0;
                    for (const f of keyframes) {
                        if (f > a + 0.001) break;
                        k = f;
                    }
                    const last = out[out.length - 1];
                    if (last && k <= last[1]) last[1] = Math.max(last[1], b);
                    else out.push([k, b]);
                }
                return out;
            };

            const video = el('video', { class: 've-cut-video', src: data.stream || data.url, preload: 'auto', playsinline: true });
            const strip = el('div', { class: 've-cut-strip', 'aria-hidden': 'true' });
            const ticks = el('div', { class: 've-cut-ticks', 'aria-hidden': 'true' });
            const layer = el('div', { class: 've-cut-layer' });
            const head = el('div', { class: 've-cut-head', 'aria-hidden': 'true' });
            const timeline = el('div', {
                class: 've-cut-timeline', role: 'slider', tabindex: '0', 'aria-label': t.cut_timeline,
                'aria-valuemin': '0', 'aria-valuemax': String(Math.round(duration * 10) / 10),
            }, strip, ticks, layer, head);
            const now = el('output', { class: 've-cut-now', text: clock(0) + ' / ' + clock(duration) });
            const length = el('p', { class: 've-cut-length', 'aria-live': 'polite' });
            const snapNote = el('p', { class: 've-cut-note', role: 'status' });
            const selInfo = el('p', { class: 've-cut-sel' });

            const btn = (label, iconName, attrs = {}) => el('button', { type: 'button', class: 'rex-button', 'data-size': 'sm', ...attrs },
                iconName ? icon(iconName) : null, iconName ? ' ' + label : label);
            const playBtn = btn(t.cut_play, 'fa-solid fa-play', { 'data-cut': 'toggle', 'aria-label': 'Play' });
            playBtn.replaceChildren(icon('fa-solid fa-play'));
            const resultBtn = btn(t.cut_play, 'fa-solid fa-forward', { 'data-cut': 'result' });
            const removeBtn = btn(t.cut_remove, 'fa-solid fa-trash-can', { 'data-cut': 'remove' });
            const undoBtn = btn(t.cut_undo, 'fa-solid fa-rotate-left', { 'data-cut': 'undo' });
            const controls = el('div', { class: 've-cut-controls' },
                el('div', { class: 've-cut-group' },
                    playBtn,
                    btn('−1 s', null, { 'data-step': '-1' }), btn('−1 Bild', null, { 'data-step': String(-frame) }),
                    now,
                    btn('+1 Bild', null, { 'data-step': String(frame) }), btn('+1 s', null, { 'data-step': '1' }),
                ),
                el('div', { class: 've-cut-group' },
                    btn(t.cut_split, 'fa-solid fa-scissors', { 'data-cut': 'split', 'aria-keyshortcuts': 'S' }),
                    btn(t.cut_in, 'fa-solid fa-arrow-right-to-bracket', { 'data-cut': 'in', 'aria-keyshortcuts': 'I' }),
                    btn(t.cut_out, 'fa-solid fa-arrow-right-from-bracket', { 'data-cut': 'out', 'aria-keyshortcuts': 'O' }),
                    removeBtn,
                ),
                el('div', { class: 've-cut-group' }, undoBtn, btn(t.cut_reset, null, { 'data-cut': 'reset' }), resultBtn),
            );

            const ext = (data.extension || '').toLowerCase();
            const method = el('fieldset', { class: 've-mode' }, el('legend', { text: t.cut_method }),
                el('label', {}, el('input', { type: 'radio', name: 've-cut-method', value: '0', checked: true }), ' ' + t.cut_fast,
                    el('small', { class: 've-muted', text: t.cut_fast_text })),
                el('label', {}, el('input', { type: 'radio', name: 've-cut-method', value: '1' }), ' ' + t.cut_exact,
                    el('small', { class: 've-muted', text: t.cut_exact_text })),
            );
            const mode = el('fieldset', { class: 've-mode' }, el('legend', { text: t.mode }),
                el('label', {}, el('input', { type: 'radio', name: 've-cut-mode', value: 'version', checked: true }), ' ' + t.mode_version),
                data.canReplace ? el('label', {}, el('input', { type: 'radio', name: 've-cut-mode', value: 'replace' }), ' ' + t.mode_replace,
                    el('small', { class: 've-muted', text: ' (' + t.mode_replace_note + ')' })) : null,
            );
            const syncMode = () => {
                const replace = mode.querySelector('input[value="replace"]');
                if (!replace) return;
                // genau kodiert neu – nur MP4/MOV/WebM bleiben im eigenen Format
                const exact = method.querySelector('input:checked').value === '1';
                replace.disabled = exact && !['mp4', 'm4v', 'mov', 'webm'].includes(ext);
                if (replace.disabled && replace.checked) mode.querySelector('input[value="version"]').checked = true;
            };
            method.addEventListener('change', () => {
                syncMode();
                render();
            });
            syncMode();

            const pct = (s) => (duration ? (s / duration) * 100 : 0) + '%';
            const at = (clientX) => {
                const r = timeline.getBoundingClientRect();
                return Math.max(0, Math.min(duration, ((clientX - r.left) / r.width) * duration));
            };
            const seek = (s) => {
                video.currentTime = Math.max(0, Math.min(duration, s));
                draw();
            };
            const remember = () => {
                history.push(JSON.stringify(segs));
                if (history.length > 50) history.shift();
            };
            const kept = () => effective().reduce((sum, [a, b]) => sum + (b - a), 0);

            const draw = () => {
                const cur = video.currentTime || 0;
                head.style.left = pct(cur);
                now.textContent = clock(cur) + ' / ' + clock(duration);
                timeline.setAttribute('aria-valuenow', String(Math.round(cur * 10) / 10));
                timeline.setAttribute('aria-valuetext', clock(cur));
            };

            const render = () => {
                const nodes = [];
                // entfernte Bereiche abgedunkelt
                let last = 0;
                for (const [a, b] of [...segs, [duration, duration]]) {
                    if (a - last > 0.001) {
                        const gap = el('div', { class: 've-cut-gap', 'aria-hidden': 'true' });
                        gap.style.left = pct(last);
                        gap.style.width = pct(a - last);
                        nodes.push(gap);
                    }
                    last = b;
                }
                // schnell: was ab dem Schlüsselbild zusätzlich mitkommt (eigentlich entfernte Bereiche)
                let lead = 0;
                if (!isExact() && keyframes.length) {
                    segs.forEach(([sa], i) => {
                        let k = 0;
                        for (const f of keyframes) {
                            if (f > sa + 0.001) break;
                            k = f;
                        }
                        const from = Math.max(k, i > 0 ? segs[i - 1][1] : 0);
                        if (sa - from > 0.001) {
                            lead = Math.max(lead, sa - from);
                            const extra = el('div', { class: 've-cut-lead', 'aria-hidden': 'true' });
                            extra.style.left = pct(from);
                            extra.style.width = pct(sa - from);
                            nodes.push(extra);
                        }
                    });
                }
                const eff = effective();
                const nothing = eff.length === 1 && eff[0][0] < MIN && eff[0][1] > duration - MIN;
                ticks.hidden = isExact();
                snapNote.textContent = nothing && !isExact() && segs.length > 1 ? t.cut_snap_none
                    : lead > 0.3 ? fill(t.cut_snap_note, clock(lead)) : '';
                snapNote.hidden = snapNote.textContent === '';
                segs.forEach(([a, b], i) => {
                    const label = fill(t.cut_segment, i + 1, clock(a), clock(b));
                    const seg = el('button', { type: 'button', class: 've-cut-seg', 'data-seg': String(i), 'aria-pressed': String(i === sel), 'aria-label': label, title: label });
                    seg.style.left = pct(a);
                    seg.style.width = pct(b - a);
                    if (i === sel) {
                        seg.append(el('span', { class: 've-cut-handle', 'data-edge': '0', 'aria-hidden': 'true' }),
                            el('span', { class: 've-cut-handle', 'data-edge': '1', 'aria-hidden': 'true' }));
                    }
                    nodes.push(seg);
                });
                layer.replaceChildren(...nodes);
                const [a, b] = segs[sel];
                selInfo.textContent = fill(t.cut_segment, sel + 1, clock(a), clock(b));
                length.textContent = fill(t.cut_length, clock(kept()), clock(duration));
                removeBtn.disabled = segs.length < 2;
                undoBtn.disabled = history.length === 0;
                start.disabled = nothing;
                draw();
            };

            const indexAt = (s) => segs.findIndex(([a, b]) => s >= a && s < b);
            const ops = {
                split: () => {
                    const cur = video.currentTime;
                    const i = indexAt(cur);
                    if (i < 0 || cur - segs[i][0] < MIN || segs[i][1] - cur < MIN) return;
                    remember();
                    segs.splice(i, 1, [segs[i][0], cur], [cur, segs[i][1]]);
                    sel = i + 1;
                },
                in: () => {
                    const [, b] = segs[sel];
                    const prev = sel > 0 ? segs[sel - 1][1] : 0;
                    const v = Math.max(prev, Math.min(video.currentTime, b - MIN));
                    remember();
                    segs[sel] = [v, b];
                },
                out: () => {
                    const [a] = segs[sel];
                    const next = sel < segs.length - 1 ? segs[sel + 1][0] : duration;
                    const v = Math.min(next, Math.max(video.currentTime, a + MIN));
                    remember();
                    segs[sel] = [a, v];
                },
                remove: () => {
                    if (segs.length < 2) return;
                    remember();
                    segs.splice(sel, 1);
                    sel = Math.min(sel, segs.length - 1);
                },
                undo: () => {
                    if (!history.length) return;
                    segs = JSON.parse(history.pop());
                    sel = Math.min(sel, segs.length - 1);
                },
                reset: () => {
                    remember();
                    segs = [[0, duration]];
                    sel = 0;
                },
                toggle: () => {
                    previewing = false;
                    video.paused ? video.play() : video.pause();
                },
                result: () => {
                    if (previewing && !video.paused) {
                        video.pause();
                        return;
                    }
                    previewing = true;
                    const cur = video.currentTime;
                    if (indexAt(cur) < 0 || cur >= segs[segs.length - 1][1] - 0.05) {
                        const next = segs.find(([a]) => a > cur);
                        video.currentTime = next && cur < segs[segs.length - 1][1] - 0.05 ? next[0] : segs[0][0];
                    }
                    video.play();
                },
            };
            const run = (name) => {
                ops[name]?.();
                render();
            };

            // Wiedergabe: Abspielkopf mitführen, bei „Ergebnis abspielen“ Lücken überspringen
            let raf = 0;
            const tick = () => {
                if (previewing) {
                    const cur = video.currentTime;
                    const i = indexAt(cur);
                    if (i < 0) {
                        const next = segs.find(([a]) => a > cur);
                        if (next) video.currentTime = next[0];
                        else { video.pause(); previewing = false; }
                    } else if (cur >= segs[i][1] - 0.03) {
                        const next = segs[i + 1];
                        if (next) video.currentTime = next[0];
                        else { video.pause(); previewing = false; }
                    }
                }
                draw();
                if (!video.paused) raf = requestAnimationFrame(tick);
            };
            video.addEventListener('play', () => {
                playBtn.replaceChildren(icon('fa-solid fa-pause'));
                resultBtn.replaceChildren(icon(previewing ? 'fa-solid fa-stop' : 'fa-solid fa-forward'), ' ' + (previewing ? t.cut_stop : t.cut_play));
                cancelAnimationFrame(raf);
                raf = requestAnimationFrame(tick);
            });
            video.addEventListener('pause', () => {
                playBtn.replaceChildren(icon('fa-solid fa-play'));
                resultBtn.replaceChildren(icon('fa-solid fa-forward'), ' ' + t.cut_play);
                previewing = false;
                draw();
            });
            video.addEventListener('seeked', draw);
            video.addEventListener('click', () => run('toggle'));

            // Zeiger: Zeitleiste scrubben, Abschnitt wählen, Ränder ziehen
            timeline.addEventListener('pointerdown', (e) => {
                const handle = e.target.closest('.ve-cut-handle');
                const seg = e.target.closest('[data-seg]');
                e.preventDefault();
                timeline.focus({ preventScroll: true });
                video.pause();
                timeline.setPointerCapture(e.pointerId);
                let move;
                if (handle) {
                    const edge = Number(handle.dataset.edge);
                    remember();
                    move = (x) => {
                        const v = at(x);
                        const [a, b] = segs[sel];
                        if (edge === 0) {
                            const prev = sel > 0 ? segs[sel - 1][1] : 0;
                            segs[sel] = [Math.max(prev, Math.min(v, b - MIN)), b];
                            video.currentTime = segs[sel][0];
                        } else {
                            const next = sel < segs.length - 1 ? segs[sel + 1][0] : duration;
                            segs[sel] = [a, Math.min(next, Math.max(v, a + MIN))];
                            video.currentTime = segs[sel][1];
                        }
                        render();
                    };
                } else {
                    if (seg) {
                        sel = Number(seg.dataset.seg);
                        render();
                    }
                    move = (x) => seek(at(x));
                    move(e.clientX);
                }
                const onMove = (ev) => move(ev.clientX);
                const onUp = () => {
                    timeline.removeEventListener('pointermove', onMove);
                    timeline.removeEventListener('pointerup', onUp);
                    timeline.removeEventListener('pointercancel', onUp);
                };
                timeline.addEventListener('pointermove', onMove);
                timeline.addEventListener('pointerup', onUp);
                timeline.addEventListener('pointercancel', onUp);
            });
            // Tastatur auf dem Abschnitt: Enter/Leertaste wählt ihn (Knopf)
            layer.addEventListener('click', (e) => {
                const seg = e.target.closest('[data-seg]');
                if (seg && e.detail === 0) {
                    sel = Number(seg.dataset.seg);
                    seek(segs[sel][0]);
                    render();
                    layer.querySelector('[data-seg="' + sel + '"]')?.focus();
                }
            });

            controls.addEventListener('click', (e) => {
                const b = e.target.closest('button');
                if (!b) return;
                if (b.dataset.step) {
                    video.pause();
                    seek(video.currentTime + Number(b.dataset.step));
                } else if (b.dataset.cut) {
                    run(b.dataset.cut);
                }
            });

            const start = el('button', { type: 'button', class: 'rex-button', 'data-variant': 'primary' }, icon('fa-solid fa-scissors'), ' ' + t.start);
            const cancel = el('button', { type: 'button', class: 'rex-button' }, t.cancel);
            const d = dialog(t.cut_title, [
                el('div', { class: 've-cut-stage' }, video),
                controls,
                timeline,
                el('div', { class: 've-cut-info' }, selInfo, length),
                snapNote,
                el('p', { class: 've-muted ve-cut-help', text: t.cut_help + ' ' + t.cut_keys }),
                el('div', { class: 've-cut-options' }, method, mode),
            ], [cancel, start], true);

            d.addEventListener('keydown', (e) => {
                const field = e.target.closest('input, textarea, select');
                const onButton = e.target.closest('button') && !e.target.closest('.ve-cut-seg');
                const key = e.key.toLowerCase();
                if ((e.metaKey || e.ctrlKey) && key === 'z') {
                    e.preventDefault();
                    run('undo');
                    return;
                }
                if (field || e.metaKey || e.ctrlKey || e.altKey) return;
                const map = { s: 'split', i: 'in', o: 'out', delete: 'remove', backspace: 'remove' };
                if (map[key]) {
                    e.preventDefault();
                    run(map[key]);
                } else if (key === ' ' && !onButton) {
                    e.preventDefault();
                    run('toggle');
                } else if ((key === 'arrowleft' || key === 'arrowright') && !onButton) {
                    e.preventDefault();
                    video.pause();
                    seek(video.currentTime + (key === 'arrowleft' ? -1 : 1) * (e.shiftKey ? 1 : frame));
                } else if ((key === 'home' || key === 'end') && !onButton) {
                    e.preventDefault();
                    seek(key === 'home' ? 0 : duration);
                }
            });
            d.addEventListener('close', () => {
                cancelAnimationFrame(raf);
                video.pause();
            });
            cancel.addEventListener('click', () => d.close());
            start.addEventListener('click', async () => {
                start.disabled = true;
                try {
                    const json = await call('cut', {
                        segments: JSON.stringify(segs.map(([a, b]) => [Math.round(a * 1000) / 1000, Math.round(b * 1000) / 1000])),
                        exact: method.querySelector('input:checked').value,
                        mode: mode.querySelector('input:checked').value,
                    });
                    data.jobs = json.jobs;
                    d.close();
                    renderJobs();
                } catch (e) {
                    start.disabled = false;
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', e.message));
                }
            });

            render();
            timeline.focus({ preventScroll: true });

            // Vorschaubilder der Zeitleiste nachladen
            call('frames').then((json) => {
                strip.replaceChildren(...json.frames.map((src) => el('img', { src, alt: '', loading: 'lazy', decoding: 'async' })));
                keyframes = json.keyframes || [];
                ticks.replaceChildren(...keyframes.map((k) => {
                    const tick = el('span');
                    tick.style.left = pct(k);
                    return tick;
                }));
                render();
            }).catch(() => {});
        };

        // ------------------------------------------------------------------------------------------ Ereignisse

        section.addEventListener('click', async (e) => {
            const open = e.target.closest('[data-ve-open]');
            if (open) {
                ({ poster: openPoster, cut: openCut, optimize: openOptimize })[open.dataset.veOpen]?.();
                return;
            }
            const preset = e.target.closest('[data-ve-preset]');
            if (preset) {
                openOptimize(preset.dataset.vePreset);
                return;
            }
            const cancel = e.target.closest('[data-ve-cancel]');
            const retry = e.target.closest('[data-ve-retry]');
            if (cancel || retry) {
                try {
                    const json = await call(cancel ? 'cancel' : 'retry', { job: (cancel || retry).dataset[cancel ? 'veCancel' : 'veRetry'] });
                    data.jobs = json.jobs;
                    renderJobs();
                } catch (err) {
                    section.prepend(alertBox('danger', err.message));
                }
            }
        });

        load();
    };

    const scan = (root = document) => root.querySelectorAll?.('section[data-video-editor]').forEach(init);
    scan();
    document.addEventListener('DOMContentLoaded', () => scan());
    document.addEventListener('rex:ready', (e) => scan(e.target));
})();
