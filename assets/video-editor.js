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
            // Untertitel in der Vorschau (zum Einschalten im Player)
            const trackKey = data.tracks.map((tr) => tr.url).join('|');
            if (preview.dataset.tracks !== trackKey) {
                preview.dataset.tracks = trackKey;
                preview.querySelectorAll('track').forEach((n) => n.remove());
                data.tracks.filter((tr) => tr.url).forEach((tr) => preview.append(el('track', { kind: tr.role, src: tr.url, srclang: tr.lang, label: tr.name })));
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
            // Untertitel und Kapitel
            parts.push(el('div', { class: 've-tracks' },
                el('h4', { text: t.tracks }),
                data.tracks.length
                    ? el('ul', { class: 've-track-list' }, data.tracks.map((tr) => el('li', {},
                        el('span', {}, el('strong', { text: tr.name }),
                            el('span', { class: 've-muted', text: ' · ' + (t['track_role_' + tr.role] || tr.role) + ' · ' + fill(t.track_cues, tr.cues) })),
                        data.canEdit ? el('button', { type: 'button', class: 'rex-button', 'data-size': 'xs', 'data-ve-track': tr.file }, t.track_edit) : null,
                    )))
                    : el('p', { class: 've-muted', text: t.track_none }),
                data.canEdit ? el('button', { type: 'button', class: 'rex-button', 'data-size': 'sm', 'data-ve-track': '' }, icon('fa-regular fa-closed-captioning'), ' ' + t.track_add) : null,
            ));
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

        // ------------------------------------------------------------------------------------------ Untertitel

        // Zeit in ms ↔ Text „1:05.250“; liest auch „65.25“, „00:01:05,250“
        const stamp = (ms) => {
            ms = Math.max(0, Math.round(ms));
            return time(Math.floor(ms / 1000)) + '.' + String(ms % 1000).padStart(3, '0');
        };
        const readStamp = (text) => {
            const m = String(text).trim().replace(',', '.').match(/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d*)?)$/);
            if (!m) return null;
            const parts = [m[1], m[2]].filter((x) => x !== undefined).map(Number);
            const sec = Number(m[3]);
            const [h, min] = parts.length === 2 ? parts : [0, parts[0] || 0];
            return Math.round(((h * 60 + min) * 60 + sec) * 1000);
        };

        const openTrack = async (file) => {
            const existing = data.tracks.find((tr) => tr.file === file) || null;
            let cues = [];
            if (existing) {
                try {
                    cues = (await call('track', { track: existing.file })).cues;
                } catch (e) {
                    section.prepend(alertBox('danger', e.message));
                    return;
                }
            }
            const duration = (data.analysis?.duration || 0) * 1000;
            const frame = 1 / (data.analysis?.fps || 25);

            const video = el('video', { class: 've-track-video', src: data.stream || data.url, preload: 'auto', playsinline: true, controls: true });
            const textTrack = video.addTextTrack('subtitles', t.track_title, existing?.lang || data.lang || 'de');
            textTrack.mode = 'showing';
            const preview = () => {
                for (const c of [...(textTrack.cues || [])]) textTrack.removeCue(c);
                for (const c of cues) {
                    if (c.end > c.start && c.text.trim()) textTrack.addCue(new VTTCue(c.start / 1000, c.end / 1000, c.text));
                }
            };

            const now = el('output', { class: 've-cut-now', text: stamp(0) });
            const btn = (label, iconName, attrs = {}) => el('button', { type: 'button', class: 'rex-button', 'data-size': 'sm', ...attrs },
                iconName ? icon(iconName) : null, iconName && label ? ' ' + label : label);
            const controls = el('div', { class: 've-cut-group' },
                btn('−1 s', null, { 'data-step': '-1' }), btn('−1 Bild', null, { 'data-step': String(-frame) }), now,
                btn('+1 Bild', null, { 'data-step': String(frame) }), btn('+1 s', null, { 'data-step': '1' }),
                btn(t.track_new_cue, 'fa-solid fa-plus', { 'data-cue-new': '', 'aria-keyshortcuts': 'N' }),
            );

            const id = 've-track-' + Math.random().toString(36).slice(2, 8);
            const langInput = el('input', { class: 'rex-input', id: id + '-lang', value: existing?.lang || data.lang || 'de', maxlength: '20', size: '8', autocomplete: 'off', spellcheck: 'false' });
            const roleSelect = el('select', { class: 'rex-input', id: id + '-role' },
                ['subtitles', 'captions', 'chapters'].map((r) => el('option', { value: r, selected: (existing?.role || 'subtitles') === r }, t['track_role_' + r])));
            const labelInput = el('input', { class: 'rex-input', id: id + '-label', value: existing?.label || '', maxlength: '60', autocomplete: 'off' });
            const meta = el('div', { class: 've-track-meta' },
                el('label', { for: id + '-lang' }, el('span', { text: t.track_lang }), langInput),
                el('label', { for: id + '-role' }, el('span', { text: t.track_role }), roleSelect),
                el('label', { for: id + '-label' }, el('span', { text: t.track_label }), labelInput),
            );

            const fileInput = el('input', { type: 'file', accept: '.vtt,.srt,text/vtt', hidden: true });
            const shiftInput = el('input', { class: 'rex-input', type: 'number', step: '0.1', value: '0', id: id + '-shift', size: '6' });
            const tools = el('div', { class: 've-track-tools' },
                btn(t.track_import, 'fa-solid fa-file-import', { 'data-cue-import': '' }), fileInput,
                el('label', { for: id + '-shift', class: 've-track-shift' }, el('span', { text: t.track_shift }), shiftInput),
                btn(t.track_shift_apply, null, { 'data-cue-shift': '' }),
            );

            const list = el('ol', { class: 've-cue-list', 'aria-label': t.track_list });
            const count = el('p', { class: 've-muted ve-track-count', 'aria-live': 'polite' });

            const row = (c, i) => {
                const startIn = el('input', { class: 'rex-input ve-cue-time', value: stamp(c.start), 'aria-label': t.track_from + ' (' + (i + 1) + ')', 'data-cue-field': 'start', spellcheck: 'false' });
                const endIn = el('input', { class: 'rex-input ve-cue-time', value: stamp(c.end), 'aria-label': t.track_to + ' (' + (i + 1) + ')', 'data-cue-field': 'end', spellcheck: 'false' });
                const text = el('textarea', { class: 'rex-input ve-cue-text', rows: '2', 'aria-label': t.track_text + ' (' + (i + 1) + ')', 'data-cue-field': 'text' });
                text.value = c.text;
                return el('li', { class: 've-cue', 'data-cue': String(i) },
                    el('div', { class: 've-cue-head' },
                        el('span', { class: 've-cue-no', text: String(i + 1) }),
                        startIn,
                        btn('', 'fa-solid fa-arrow-right-to-bracket', { 'data-cue-set': 'start', title: t.track_set_start, 'aria-label': t.track_set_start }),
                        el('span', { 'aria-hidden': 'true', text: '–' }),
                        endIn,
                        btn('', 'fa-solid fa-arrow-right-from-bracket', { 'data-cue-set': 'end', title: t.track_set_end, 'aria-label': t.track_set_end }),
                        btn('', 'fa-solid fa-play', { 'data-cue-jump': '', title: t.track_jump, 'aria-label': t.track_jump }),
                        btn('', 'fa-solid fa-trash-can', { 'data-cue-delete': '', title: t.track_delete_cue, 'aria-label': t.track_delete_cue }),
                    ),
                    text,
                );
            };
            const renderList = (focusIndex = null) => {
                cues.sort((a, b) => a.start - b.start || a.end - b.end);
                list.replaceChildren(...cues.map(row));
                count.textContent = fill(t.track_cues, cues.length);
                preview();
                highlight();
                if (focusIndex !== null) {
                    const li = list.querySelector('[data-cue="' + focusIndex + '"]');
                    li?.querySelector('textarea')?.focus();
                    li?.scrollIntoView({ block: 'nearest' });
                }
            };
            const highlight = () => {
                const cur = video.currentTime * 1000;
                now.textContent = stamp(cur);
                list.querySelectorAll('.ve-cue').forEach((li) => {
                    const c = cues[Number(li.dataset.cue)];
                    const on = c && cur >= c.start && cur < c.end;
                    if (on !== li.classList.contains('is-active')) {
                        li.classList.toggle('is-active', on);
                        if (on && !video.paused && !list.contains(document.activeElement)) li.scrollIntoView({ block: 'nearest' });
                    }
                });
            };
            video.addEventListener('timeupdate', highlight);
            video.addEventListener('seeked', highlight);

            const newCue = () => {
                const cur = Math.round(video.currentTime * 1000);
                const next = cues.filter((c) => c.start > cur).sort((a, b) => a.start - b.start)[0];
                const end = Math.min(cur + 2000, next ? next.start : Infinity, duration || Infinity);
                cues.push({ start: cur, end: end > cur ? end : cur + 2000, text: '', settings: '' });
                cues.sort((a, b) => a.start - b.start);
                video.pause();
                renderList(cues.findIndex((c) => c.start === cur && c.text === ''));
            };

            // Eingaben übernehmen – ohne neu zu sortieren, damit der Fokus bleibt
            list.addEventListener('input', (e) => {
                const li = e.target.closest('[data-cue]');
                const c = li && cues[Number(li.dataset.cue)];
                if (!c) return;
                const field = e.target.dataset.cueField;
                if (field === 'text') {
                    c.text = e.target.value;
                } else {
                    const ms = readStamp(e.target.value);
                    e.target.setCustomValidity(ms === null ? t.track_time_invalid : '');
                    if (ms !== null) c[field] = ms;
                }
                preview();
            });
            list.addEventListener('change', (e) => {
                if (e.target.dataset.cueField === 'start' || e.target.dataset.cueField === 'end') {
                    const li = e.target.closest('[data-cue]');
                    const c = cues[Number(li.dataset.cue)];
                    if (c) e.target.value = stamp(c[e.target.dataset.cueField]);
                }
            });
            list.addEventListener('click', (e) => {
                const b = e.target.closest('button');
                const li = e.target.closest('[data-cue]');
                if (!b || !li) return;
                const i = Number(li.dataset.cue);
                const c = cues[i];
                if (b.hasAttribute('data-cue-delete')) {
                    cues.splice(i, 1);
                    renderList();
                } else if (b.hasAttribute('data-cue-jump')) {
                    video.currentTime = c.start / 1000;
                    video.play();
                } else if (b.dataset.cueSet) {
                    c[b.dataset.cueSet] = Math.round(video.currentTime * 1000);
                    if (c.end <= c.start) c.end = c.start + 1000;
                    li.querySelector('[data-cue-field="start"]').value = stamp(c.start);
                    li.querySelector('[data-cue-field="end"]').value = stamp(c.end);
                    preview();
                }
            });
            controls.addEventListener('click', (e) => {
                const b = e.target.closest('button');
                if (!b) return;
                if (b.dataset.step) {
                    video.pause();
                    video.currentTime = Math.max(0, video.currentTime + Number(b.dataset.step));
                } else if (b.hasAttribute('data-cue-new')) {
                    newCue();
                }
            });
            tools.addEventListener('click', async (e) => {
                const b = e.target.closest('button');
                if (!b) return;
                if (b.hasAttribute('data-cue-import')) {
                    fileInput.click();
                } else if (b.hasAttribute('data-cue-shift')) {
                    const delta = Math.round(Number(shiftInput.value || 0) * 1000);
                    if (!delta) return;
                    for (const c of cues) {
                        c.start = Math.max(0, c.start + delta);
                        c.end = Math.max(c.start + 100, c.end + delta);
                    }
                    shiftInput.value = '0';
                    renderList();
                }
            });
            fileInput.addEventListener('change', async () => {
                const f = fileInput.files?.[0];
                if (!f) return;
                try {
                    const json = await call('track_parse', { content: await f.text() });
                    cues = json.cues;
                    renderList();
                } catch (err) {
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', err.message));
                }
                fileInput.value = '';
            });

            const save = el('button', { type: 'button', class: 'rex-button', 'data-variant': 'primary' }, icon('fa-solid fa-check'), ' ' + t.track_save);
            const cancel = el('button', { type: 'button', class: 'rex-button' }, t.cancel);
            const remove = existing ? el('button', { type: 'button', class: 'rex-button ve-track-remove', 'data-variant': 'danger' }, icon('fa-solid fa-trash-can'), ' ' + t.track_remove) : null;
            const d = dialog(existing ? t.track_title + ': ' + existing.name : t.track_add.replace(/\s*…$/, ''), [
                el('div', { class: 've-track-layout' },
                    el('div', { class: 've-track-main' }, video, controls, meta),
                    el('div', { class: 've-track-side' }, tools, count, list),
                ),
                el('p', { class: 've-muted ve-cut-help', text: t.track_keys }),
            ], [remove, cancel, save], true);

            d.addEventListener('keydown', (e) => {
                if (e.target.closest('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) return;
                const key = e.key.toLowerCase();
                if (key === 'n') {
                    e.preventDefault();
                    newCue();
                } else if (key === ' ' && !e.target.closest('button')) {
                    e.preventDefault();
                    video.paused ? video.play() : video.pause();
                } else if ((key === 'arrowleft' || key === 'arrowright') && !e.target.closest('button')) {
                    e.preventDefault();
                    video.pause();
                    video.currentTime = Math.max(0, video.currentTime + (key === 'arrowleft' ? -1 : 1) * (e.shiftKey ? 1 : frame));
                }
            });
            d.addEventListener('close', () => video.pause());
            cancel.addEventListener('click', () => d.close());
            remove?.addEventListener('click', async () => {
                // zweiter Klick bestätigt
                if (!remove.dataset.confirm) {
                    remove.dataset.confirm = '1';
                    remove.replaceChildren(icon('fa-solid fa-trash-can'), ' ' + t.track_remove_confirm);
                    return;
                }
                try {
                    data.tracks = (await call('track_delete', { track: existing.file })).tracks;
                    d.close();
                    render();
                } catch (err) {
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', err.message));
                }
            });
            save.addEventListener('click', async () => {
                save.disabled = true;
                try {
                    const json = await call('track_save', {
                        track: existing?.file || '',
                        lang: langInput.value,
                        role: roleSelect.value,
                        label: labelInput.value,
                        cues: JSON.stringify(cues),
                    });
                    data.tracks = json.tracks;
                    d.close();
                    render();
                } catch (err) {
                    save.disabled = false;
                    d.querySelector('.rex-dialog-body').querySelector(':scope > .rex-alert')?.remove();
                    d.querySelector('.rex-dialog-body').prepend(alertBox('danger', err.message));
                }
            });

            renderList();
            if (!cues.length) newCue();
        };

        // ------------------------------------------------------------------------------------------ Ereignisse

        section.addEventListener('click', async (e) => {
            const open = e.target.closest('[data-ve-open]');
            if (open) {
                ({ poster: openPoster, cut: openCut, optimize: openOptimize })[open.dataset.veOpen]?.();
                return;
            }
            const trackBtn = e.target.closest('[data-ve-track]');
            if (trackBtn) {
                openTrack(trackBtn.dataset.veTrack || null);
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
