(function () {
    const sheet = document.querySelector('[data-gradebook]');
    if (!sheet) return;

    const saveUrl = sheet.dataset.saveUrl;
    const token = sheet.dataset.csrf;
    const period = sheet.dataset.period;
    const scale = JSON.parse(document.getElementById('scaleJson').textContent);
    const status = document.getElementById('saveState');

    function round2(n) {
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function equivalent(percent) {
        if (percent === null || Number.isNaN(percent)) return null;
        for (const band of scale) {
            if (percent >= Number(band.min_percent) - 0.001 && percent <= Number(band.max_percent) + 0.001) {
                return Number(band.equivalent);
            }
        }
        return 5;
    }

    function fmt(n) {
        return n === null || Number.isNaN(n) ? '' : Number(n).toFixed(2);
    }

    function addPoints(earned, possible, max, score) {
        if (max <= 0 || score === null || Number.isNaN(score)) return [earned, possible];
        return [earned + score, possible + max];
    }

    function categoryPercent(row, category) {
        const fields = [...row.querySelectorAll('[data-cat="' + category + '"]')];
        let earned = 0;
        let possible = 0;
        fields.forEach(function (field) {
            const max = Number(field.dataset.max);
            if (field.tagName === 'SELECT') {
                const choice = field.value;
                if (choice === 'present') {
                    [earned, possible] = addPoints(earned, possible, max, max);
                } else if (choice === 'late') {
                    [earned, possible] = addPoints(earned, possible, max, round2(max / 2));
                } else if (choice === 'absent') {
                    [earned, possible] = addPoints(earned, possible, max, 0);
                } else if (choice === 'keep' && field.dataset.score !== '') {
                    [earned, possible] = addPoints(earned, possible, max, Number(field.dataset.score));
                }
                return;
            }
            if (!field.classList.contains('score') || field.value.trim() === '') return;
            [earned, possible] = addPoints(earned, possible, max, Number(field.value));
        });
        if (possible <= 0) return null;
        return round2(earned / possible * 100);
    }

    function paintRow(row) {
        if (!row.querySelector('input.score, select.attend')) return;
        const weights = JSON.parse(row.dataset.weights || '{}');
        const parts = {};
        Object.keys(weights).forEach(function (cat) {
            const pct = categoryPercent(row, cat);
            parts[cat] = pct;
            const cell = row.querySelector('[data-ttl="' + cat + '"]');
            if (cell) cell.textContent = fmt(pct);
            const eq = row.querySelector('[data-eqv="' + cat + '"]');
            if (eq) eq.textContent = fmt(equivalent(pct));
        });
        let weightTotal = 0;
        let acc = 0;
        let missing = false;
        Object.keys(weights).forEach(function (cat) {
            const weight = Number(weights[cat] || 0);
            if (weight <= 0) return;
            weightTotal += weight;
            if (parts[cat] === null) {
                missing = true;
                return;
            }
            acc += parts[cat] * weight;
        });
        const adjInput = row.querySelector('input.adj');
        const adj = adjInput ? Number(adjInput.value || 0) : Number(row.dataset.adjustment || 0);
        let percent = null;
        if (!missing && weightTotal > 0) {
            percent = round2(Math.max(0, Math.min(100, acc / 100 + adj)));
        }
        const pctCell = row.querySelector('[data-period-pct]');
        const eqCell = row.querySelector('[data-period-eqv]');
        if (pctCell) pctCell.textContent = fmt(percent);
        if (eqCell) eqCell.textContent = fmt(equivalent(percent));
    }

    function setStatus(text) {
        if (status) status.textContent = text;
    }

    function applyServer(row, payload) {
        const data = payload.periods[period];
        if (!data) return;
        Object.keys(data.categories).forEach(function (cat) {
            const cell = row.querySelector('[data-ttl="' + cat + '"]');
            if (cell) cell.textContent = fmt(data.categories[cat].percent);
            const eq = row.querySelector('[data-eqv="' + cat + '"]');
            if (eq) eq.textContent = fmt(data.categories[cat].equivalent);
        });
        const pctCell = row.querySelector('[data-period-pct]');
        const eqCell = row.querySelector('[data-period-eqv]');
        if (pctCell) pctCell.textContent = fmt(data.percent);
        if (eqCell) eqCell.textContent = fmt(data.equivalent);
    }

    function scoreFields(input) {
        const fields = {
            kind: input.classList.contains('adj') ? 'adjustment' : 'score',
            enrollment_id: input.dataset.enrollment
        };
        if (input.classList.contains('adj')) {
            fields.period = input.dataset.period;
            fields.adjustment = input.value.trim();
        } else if (input.tagName === 'SELECT') {
            fields.component_id = input.dataset.component;
            fields.mark = input.value === 'keep' ? '' : input.value;
        } else {
            fields.component_id = input.dataset.component;
            fields.score = input.value.trim();
        }
        return fields;
    }

    function fieldBody(fields) {
        const body = new FormData();
        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });
        body.set('csrf_token', sheet.dataset.csrf || token);
        return body;
    }

    async function postFields(fields) {
        const response = await fetch(saveUrl, { method: 'POST', body: fieldBody(fields), credentials: 'same-origin' });
        const payload = await response.json().catch(function () { return { ok: false, message: 'The server did not answer.' }; });
        return { response: response, payload: payload };
    }

    async function refreshToken() {
        const endpoint = document.querySelector('meta[name="draft-url"]');
        if (!endpoint) return '';
        const response = await fetch(endpoint.content, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok || !payload.csrf) return '';
        sheet.dataset.csrf = payload.csrf;
        return payload.csrf;
    }

    function cellKey(fields) {
        return String(fields.enrollment_id || '') + ':' + String(fields.component_id || '');
    }

    function findCell(fields) {
        if (!fields.component_id) return null;
        return sheet.querySelector('[data-enrollment="' + fields.enrollment_id + '"][data-component="' + fields.component_id + '"]');
    }

    async function keepDraft(input, fields) {
        if (!window.SpccDrafts || !fields.component_id) {
            input.classList.add('invalid');
            setStatus('Not saved');
            toast('The score was not saved. Connect and try again.', 'error');
            return;
        }
        const queued = Object.assign({}, fields);
        delete queued.csrf_token;
        await window.SpccDrafts.put(queued, saveUrl);
        input.classList.add('is-draft');
        input.classList.remove('invalid');
        setStatus('Draft saved on this device');
    }

    async function save(input) {
        if (input.tagName === 'SELECT' && input.value === 'keep') return;
        const fields = scoreFields(input);
        setStatus('Saving…');
        input.classList.remove('invalid');
        try {
            if (!navigator.onLine) throw new TypeError('offline');
            let result = await postFields(fields);
            if (result.response.status === 403) {
                const fresh = await refreshToken();
                if (fresh) result = await postFields(fields);
            }
            if (!result.response.ok || !result.payload.ok) {
                input.classList.add('invalid');
                setStatus('Not saved');
                toast(result.payload.message || 'The score was not saved.', 'error');
                return;
            }
            if (window.SpccDrafts && fields.component_id) await window.SpccDrafts.remove(cellKey(fields));
            input.classList.remove('is-draft');
            applyServer(input.closest('tr'), result.payload.student);
            setStatus('Saved');
        } catch (error) {
            await keepDraft(input, fields);
        }
    }

    const timers = new WeakMap();
    function paintAttend(select) {
        select.classList.remove('mark-present', 'mark-late', 'mark-absent', 'mark-excused');
        if (select.value && select.value !== 'keep') select.classList.add('mark-' + select.value);
    }

    function showPercent(n) {
        const text = round2(n).toFixed(2);
        return text.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }

    function shareTotal() {
        let total = 0;
        sheet.querySelectorAll('input.share').forEach(function (field) {
            const value = Number(field.value);
            if (!Number.isNaN(value)) total += value;
        });
        return round2(total);
    }

    function restoreShare(input) {
        input.value = input.dataset.saved;
        input.classList.remove('invalid');
    }

    async function noticeOver(input, total) {
        restoreShare(input);
        setStatus('');
        const label = (input.getAttribute('aria-label') || 'This percent').replace(/ percent$/i, '');
        const message = label + ' would make this period add up to ' + showPercent(total) + '%. A period cannot go over 100%.';
        const note = 'The percent was put back to ' + input.dataset.saved + '%. Nothing was saved.';
        if (window.askModal) {
            await window.askModal({
                title: 'That goes over 100%',
                message: message,
                note: note,
                ok: 'OK',
                notice: true
            });
            return;
        }
        toast(message + ' ' + note, 'error');
    }

    function shareRows() {
        return [...sheet.querySelectorAll('input.share')].map(function (field) {
            const cell = field.closest('th');
            const tone = cell && (cell.className.match(/cat-(\w+)/) || [])[1];
            return {
                key: field.dataset.category,
                label: (field.getAttribute('aria-label') || 'This percent').replace(/ percent$/i, ''),
                percent: Number(field.value),
                tone: tone || 'att'
            };
        });
    }

    function rememberWeights(weights) {
        const savedByKey = {};
        Object.keys(weights).forEach(function (key) {
            savedByKey[key] = showPercent(Number(weights[key]));
        });
        sheet.querySelectorAll('input.share').forEach(function (field) {
            const saved = savedByKey[field.dataset.category];
            if (saved == null) return;
            field.dataset.saved = saved;
            field.value = saved;
            field.classList.remove('invalid');
        });
        sheet.querySelectorAll('form[data-shares]').forEach(function (form) {
            let shares = [];
            try { shares = JSON.parse(form.getAttribute('data-shares') || '[]'); } catch (error) { shares = []; }
            shares.forEach(function (row) {
                if (savedByKey[row.key] != null) row.percent = Number(weights[row.key]);
            });
            form.setAttribute('data-shares', JSON.stringify(shares));
            const removeKey = form.getAttribute('data-remove');
            if (removeKey && savedByKey[removeKey] != null) {
                form.setAttribute('data-remove-share', savedByKey[removeKey]);
            }
        });
        sheet.querySelectorAll('tbody tr[data-weights]').forEach(function (row) {
            const rowWeights = JSON.parse(row.dataset.weights || '{}');
            Object.keys(weights).forEach(function (key) {
                if (Object.prototype.hasOwnProperty.call(rowWeights, key)) rowWeights[key] = Number(weights[key]);
            });
            row.dataset.weights = JSON.stringify(rowWeights);
            paintRow(row);
        });
    }

    async function saveWeight(input) {
        const raw = input.value.trim();
        const weight = Number(raw);
        if (raw === '' || Number.isNaN(weight) || weight < 0 || weight > 100) {
            restoreShare(input);
            setStatus('');
            toast('Keep the percent from 0 to 100. Nothing was saved.', 'error');
            return;
        }
        if (showPercent(weight) === input.dataset.saved) {
            input.value = input.dataset.saved;
            return;
        }
        const total = shareTotal();
        if (total > 100) {
            await noticeOver(input, total);
            return;
        }
        if (total < 100) {
            const label = (input.getAttribute('aria-label') || 'This percent').replace(/ percent$/i, '');
            const others = shareRows().filter(function (row) { return row.key !== input.dataset.category; });
            const accepted = window.askModal ? await window.askModal({
                title: 'That leaves the period short',
                message: label + ' is ' + showPercent(weight) + '%. This period now adds up to ' + showPercent(total) + '%.',
                note: 'Set the other percents so they add up to 100%. Nothing is saved until then.',
                ok: 'Save percents',
                tone: 'add',
                shares: shareRows(),
                removeKey: '',
                focusKey: others.length ? others[0].key : input.dataset.category
            }) : false;
            if (!accepted) {
                restoreShare(input);
                setStatus('');
                if (!window.askModal) toast('Put the other percents so this period adds up to 100%. Nothing was saved.', 'error');
                return;
            }
            const fields = document.querySelectorAll('#confirmFields input');
            const body = new FormData();
            body.append('csrf_token', token);
            body.append('kind', 'weights');
            body.append('assignment_id', sheet.dataset.assignment);
            body.append('period', period);
            fields.forEach(function (field) {
                body.append('weight[' + field.dataset.category + ']', field.value);
            });
            setStatus('Saving…');
            let response;
            try {
                response = await fetch(saveUrl, { method: 'POST', body: body, credentials: 'same-origin' });
            } catch (error) {
                restoreShare(input);
                setStatus('');
                toast('Percents need a connection. Nothing was saved on this device.', 'error');
                return;
            }
            const payload = await response.json().catch(function () { return { ok: false, message: 'The server did not answer.' }; });
            if (!response.ok || !payload.ok || !payload.weights) {
                restoreShare(input);
                setStatus('');
                toast((payload && payload.message) || 'The percents were not saved.', 'error');
                return;
            }
            rememberWeights(payload.weights);
            setStatus('Saved');
            return;
        }
        const body = new FormData();
        body.append('csrf_token', token);
        body.append('kind', 'weight');
        body.append('assignment_id', sheet.dataset.assignment);
        body.append('period', period);
        body.append('category', input.dataset.category);
        body.append('weight', raw);
        setStatus('Saving…');
        input.classList.remove('invalid');
        let response;
        try {
            response = await fetch(saveUrl, { method: 'POST', body: body, credentials: 'same-origin' });
        } catch (error) {
            restoreShare(input);
            setStatus('');
            toast('Percents need a connection. Nothing was saved on this device.', 'error');
            return;
        }
        const payload = await response.json().catch(function () { return { ok: false, message: 'The server did not answer.' }; });
        if (!response.ok || !payload.ok) {
            restoreShare(input);
            setStatus('');
            const message = payload.message || 'The percent was not saved.';
            if (/cannot go over/.test(message) && window.askModal) {
                await window.askModal({
                    title: 'That goes over 100%',
                    message: message,
                    note: 'The percent was put back to ' + input.dataset.saved + '%. Nothing was saved.',
                    ok: 'OK',
                    notice: true
                });
            } else {
                toast(message, 'error');
            }
            return;
        }
        rememberWeights({ [input.dataset.category]: payload.weight });
        setStatus('Saved');
    }

    sheet.querySelectorAll('input.share').forEach(function (input) {
        input.dataset.saved = input.value;
        input.addEventListener('change', function () { saveWeight(input); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            }
        });
    });

    sheet.querySelectorAll('input.score, input.adj, select.attend').forEach(function (input) {
        input.addEventListener('input', function () {
            paintRow(input.closest('tr'));
            clearTimeout(timers.get(input));
            timers.set(input, setTimeout(function () { save(input); }, 450));
        });
        input.addEventListener('change', function () {
            if (input.tagName === 'SELECT') paintAttend(input);
            paintRow(input.closest('tr'));
            save(input);
        });
        input.addEventListener('keydown', function (event) {
            const row = input.closest('tr');
            const rows = [...sheet.querySelectorAll('tbody tr')];
            const inputs = [...row.querySelectorAll('input.score, select.attend, input.adj')];
            const col = inputs.indexOf(input);
            const rowIndex = rows.indexOf(row);
            let next = null;
            if (event.key === 'ArrowRight') next = inputs[col + 1];
            if (event.key === 'ArrowLeft') next = inputs[col - 1];
            if (event.key === 'ArrowDown' || event.key === 'Enter') {
                const below = rows[rowIndex + 1];
                next = below ? [...below.querySelectorAll('input.score, select.attend, input.adj')][col] : null;
            }
            if (event.key === 'ArrowUp') {
                const above = rows[rowIndex - 1];
                next = above ? [...above.querySelectorAll('input.score, select.attend, input.adj')][col] : null;
            }
            if (next) {
                event.preventDefault();
                next.focus();
                next.select();
            }
        });
    });

    const submitBtn = document.getElementById('openSubmit');
    const dialog = document.getElementById('submitDialog');
    if (submitBtn && dialog) {
        submitBtn.addEventListener('click', function () { dialog.showModal(); });
    }

    document.addEventListener('spcc-drafts-synced', function (event) {
        const results = (event.detail && event.detail.results) || [];
        results.forEach(function (result) {
            const input = findCell(result.fields || {});
            if (!input) return;
            input.classList.remove('is-draft', 'invalid');
            if (result.student) applyServer(input.closest('tr'), result.student);
        });
        if (results.length) setStatus('Saved');
    });

    document.addEventListener('spcc-drafts-failed', function (event) {
        const results = (event.detail && event.detail.results) || [];
        results.forEach(function (result) {
            const input = findCell(result.fields || {});
            if (input) input.classList.add('invalid');
        });
        if (!results.length) return;
        setStatus('Not saved');
        const message = results.length === 1
            ? (results[0].message || 'The score was not saved.')
            : results.length + ' scores could not be saved.';
        toast(message, 'error');
    });

    if (window.SpccDrafts) {
        window.SpccDrafts.all().then(function (rows) {
            rows.forEach(function (row) {
                const fields = row.fields || {};
                const input = findCell(fields);
                if (!input || input.disabled) return;
                if (input.tagName === 'SELECT') {
                    input.value = fields.mark || '';
                    paintAttend(input);
                } else if (fields.score != null) {
                    input.value = fields.score;
                }
                input.classList.add('is-draft');
                paintRow(input.closest('tr'));
            });
        }).catch(function () {}).then(function () {
            window.SpccDrafts.arm();
        });
    }
})();
