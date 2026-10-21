(function () {
    const query = window.matchMedia('(max-width: 760px)');
    function pinSheetHead() {
        document.querySelectorAll('.grade-grid').forEach(function (grid) {
            if (!query.matches) {
                grid.style.removeProperty('--sheet-head');
                return;
            }
            const cell = grid.querySelector('thead tr:first-child th:not([rowspan])');
            if (!cell) return;
            grid.style.setProperty('--sheet-head', Math.ceil(cell.getBoundingClientRect().height) + 'px');
        });
    }
    pinSheetHead();
    window.addEventListener('resize', pinSheetHead);
    if (query.addEventListener) query.addEventListener('change', pinSheetHead);
})();

(function () {
    const toggle = document.getElementById('navToggle');
    const scrim = document.getElementById('navScrim');
    function closeNav() { document.body.classList.remove('nav-open'); }
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.body.classList.toggle('nav-open');
        });
    }
    if (scrim) scrim.addEventListener('click', closeNav);
    document.querySelectorAll('.sidenav a').forEach(function (link) {
        link.addEventListener('click', closeNav);
    });
})();

(function () {
    const tip = document.createElement('div');
    tip.className = 'hover-tip';
    tip.hidden = true;
    document.body.appendChild(tip);
    let current = null;

    function place(el) {
        const text = el.getAttribute('data-tip');
        if (!text) return;
        current = el;
        tip.textContent = text;
        tip.hidden = false;
        const rect = el.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
        let top = rect.top - tipRect.height - 8;
        if (top < 8) top = rect.bottom + 8;
        left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function hide(el) {
        if (el && current !== el) return;
        current = null;
        tip.hidden = true;
    }

    document.addEventListener('mouseover', function (event) {
        const el = event.target.closest('[data-tip]');
        if (el) place(el);
    });
    document.addEventListener('mouseout', function (event) {
        const el = event.target.closest('[data-tip]');
        if (!el || el.contains(event.relatedTarget)) return;
        hide(el);
    });
    document.addEventListener('focusin', function (event) {
        const el = event.target.closest('[data-tip]');
        if (el) place(el);
    });
    document.addEventListener('focusout', function (event) {
        const el = event.target.closest('[data-tip]');
        if (el) hide(el);
    });
    window.addEventListener('scroll', function () {
        if (current) place(current);
    }, true);
})();

function toast(message, type) {
    const host = document.getElementById('toaster');
    if (!host) return;
    const el = document.createElement('div');
    el.className = 'toast' + (type ? ' ' + type : '');
    el.textContent = message;
    host.appendChild(el);
    setTimeout(function () { el.remove(); }, 3800);
}

(function () {
    const modal = document.getElementById('confirmModal');
    if (!modal) return;
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    const noteEl = document.getElementById('confirmNote');
    const fieldsEl = document.getElementById('confirmFields');
    const okBtn = document.getElementById('confirmOk');
    const cancelBtn = document.getElementById('confirmCancel');
    let pending = null;
    let lastFocus = null;
    let activeForm = null;

    function focusable() {
        return [...modal.querySelectorAll('button, input')].filter(function (el) {
            return !el.disabled && el.offsetParent !== null;
        });
    }

    function round2(n) {
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function showPercent(n) {
        const text = round2(n).toFixed(2);
        return text.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }

    function refreshRebalance() {
        let total = 0;
        fieldsEl.querySelectorAll('input').forEach(function (input) {
            const value = Number(input.value);
            if (!Number.isNaN(value)) total += value;
        });
        total = round2(total);
        const totalEl = fieldsEl.querySelector('[data-rebalance-total]');
        const ready = Math.abs(total - 100) <= 0.01 && fieldsEl.querySelector('input');
        if (totalEl) {
            totalEl.textContent = showPercent(total) + '%';
            totalEl.classList.toggle('is-ok', !!ready);
            totalEl.classList.toggle('is-short', !ready);
        }
        okBtn.disabled = !ready;
    }

    function renderRebalance(shares, removeKey) {
        const kept = shares.filter(function (row) { return row.key !== removeKey; });
        fieldsEl.innerHTML = '';
        if (kept.length === 0) {
            fieldsEl.hidden = true;
            okBtn.disabled = true;
            return;
        }
        const list = document.createElement('div');
        list.className = 'rebalance';
        kept.forEach(function (row) {
            const line = document.createElement('div');
            line.className = 'rebalance-row tone-' + (row.tone || 'att');
            const name = document.createElement('label');
            const inputId = 'rebalance-' + row.key;
            name.setAttribute('for', inputId);
            name.textContent = row.label;
            const wrap = document.createElement('span');
            const input = document.createElement('input');
            input.id = inputId;
            input.type = 'number';
            input.min = '0';
            input.max = '100';
            input.step = '0.01';
            input.inputMode = 'decimal';
            input.dataset.category = row.key;
            input.value = showPercent(Number(row.percent) || 0);
            input.setAttribute('aria-label', row.label + ' percent');
            input.addEventListener('input', refreshRebalance);
            const mark = document.createElement('span');
            mark.textContent = '%';
            wrap.append(input, mark);
            line.append(name, wrap);
            list.appendChild(line);
        });
        const total = document.createElement('div');
        total.className = 'rebalance-total';
        const label = document.createElement('span');
        label.textContent = 'Total';
        const value = document.createElement('span');
        value.dataset.rebalanceTotal = '1';
        total.append(label, value);
        fieldsEl.append(list, total);
        fieldsEl.hidden = false;
        refreshRebalance();
    }

    function closeModal(accepted) {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        cancelBtn.hidden = false;
        const resolve = pending;
        pending = null;
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        lastFocus = null;
        if (resolve) resolve(accepted);
    }

    function askModal(options) {
        return new Promise(function (resolve) {
            if (pending) closeModal(false);
            pending = resolve;
            lastFocus = document.activeElement;
            titleEl.textContent = options.title || 'Please confirm';
            messageEl.textContent = options.message || '';
            noteEl.textContent = options.note || '';
            noteEl.hidden = !options.note;
            okBtn.textContent = options.ok || 'Confirm';
            okBtn.disabled = false;
            cancelBtn.hidden = !!options.notice;
            okBtn.classList.toggle('is-remove', options.tone === 'remove');
            modal.classList.toggle('is-remove', options.tone === 'remove');
            modal.classList.toggle('is-rebalance', !!options.shares);
            fieldsEl.innerHTML = '';
            fieldsEl.hidden = true;
            if (options.shares) renderRebalance(options.shares, options.removeKey || '');
            modal.hidden = false;
            document.body.classList.add('modal-open');
            const named = options.focusKey ? fieldsEl.querySelector('input[data-category="' + options.focusKey + '"]') : null;
            const first = fieldsEl.querySelector('input');
            (named || first || (options.notice ? okBtn : (options.tone === 'remove' ? cancelBtn : okBtn))).focus();
        });
    }

    window.askModal = askModal;

    okBtn.addEventListener('click', function () { closeModal(true); });
    cancelBtn.addEventListener('click', function () { closeModal(false); });
    modal.querySelector('[data-modal-cancel]').addEventListener('click', function () { closeModal(false); });
    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal(false);
            return;
        }
        if (event.key !== 'Tab') return;
        const items = focusable();
        const index = items.indexOf(document.activeElement);
        event.preventDefault();
        const next = event.shiftKey ? (index <= 0 ? items.length - 1 : index - 1) : (index === items.length - 1 || index < 0 ? 0 : index + 1);
        items[next].focus();
    });

    function selectedText(select) {
        return select && select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
    }

    function buildConfirm(form, submitter) {
        if (form.classList.contains('add-bar')) {
            if (!submitter) return null;
            const where = selectedText(form.elements.new_period);
            const nameInput = form.elements.new_label;
            const custom = nameInput ? nameInput.value.trim() : '';
            const noun = submitter.getAttribute('data-noun') || 'column';
            const title = custom !== '' ? 'Add “' + custom + '”' : (submitter.getAttribute('data-ask') || 'Add a column');
            const message = custom !== ''
                ? 'This adds “' + custom + '” to ' + where + '.'
                : 'This adds the next ' + noun + ' to ' + where + '.';
            return { title: title, message: message, note: '', ok: submitter.getAttribute('data-ok') || 'Add', tone: 'add' };
        }
        if (form.getAttribute('data-confirm-kind') === 'restore') {
            if (!submitter) return null;
            const where = selectedText(form.elements.new_period);
            const label = submitter.getAttribute('data-add-label') || 'This category';
            let shares = [];
            try { shares = JSON.parse(form.getAttribute('data-shares') || '[]'); } catch (error) { shares = []; }
            shares = shares.filter(function (row) { return row.key !== submitter.value; });
            shares.push({
                key: submitter.value,
                label: label,
                percent: Number(submitter.getAttribute('data-add-share') || 0),
                tone: submitter.getAttribute('data-add-tone') || 'att'
            });
            return {
                title: 'Add ' + label.toLowerCase() + ' back',
                message: label + ' comes back on ' + where + ', with empty columns.',
                note: 'Set every category so they add up to 100%.',
                ok: 'Add ' + label.toLowerCase(),
                tone: 'add',
                shares: shares,
                removeKey: ''
            };
        }
        if (form.getAttribute('data-confirm-kind') === 'rebalance') {
            let shares = [];
            try { shares = JSON.parse(form.getAttribute('data-shares') || '[]'); } catch (error) { shares = []; }
            const label = form.getAttribute('data-remove-label') || 'This category';
            const share = form.getAttribute('data-remove-share') || '0';
            const where = form.getAttribute('data-period-label') || 'this period';
            const kept = shares.filter(function (row) { return row.key !== form.getAttribute('data-remove'); });
            return {
                title: 'Remove ' + label.toLowerCase(),
                message: label + ' is ' + share + '% of ' + where + '. Its columns and scores will be deleted.',
                note: kept.length
                    ? 'Set the categories that stay so they add up to 100%.'
                    : 'This is the only category left, so it stays on the sheet.',
                ok: form.getAttribute('data-confirm-ok') || 'Remove',
                tone: 'remove',
                shares: shares,
                removeKey: form.getAttribute('data-remove') || ''
            };
        }
        if (form.getAttribute('data-confirm-kind') === 'clear') {
            const category = selectedText(form.elements.category);
            const where = selectedText(form.elements.column_period);
            return {
                title: 'Remove ' + category.toLowerCase(),
                message: 'Remove ' + category + ' and its percent from ' + where + '.',
                note: 'Every column in that category is deleted, including scores. Set the other percents so the period still adds up to 100.',
                ok: 'Remove ' + category.toLowerCase(),
                tone: 'remove'
            };
        }
        const message = form.getAttribute('data-confirm');
        if (!message) return null;
        return {
            title: form.getAttribute('data-confirm-title') || 'Please confirm',
            message: message,
            note: form.getAttribute('data-confirm-note') || '',
            ok: form.getAttribute('data-confirm-ok') || 'Confirm',
            tone: form.getAttribute('data-confirm-tone') || 'add'
        };
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.skipConfirm === '1') {
            delete form.dataset.skipConfirm;
            return;
        }
        const built = buildConfirm(form, event.submitter);
        if (!built) return;
        event.preventDefault();
        activeForm = form;
        askModal(built).then(function (accepted) {
            activeForm = null;
            if (!accepted) return;
            if (built.shares) {
                form.querySelectorAll('input[data-weight-field]').forEach(function (el) { el.remove(); });
                fieldsEl.querySelectorAll('input').forEach(function (input) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'weight[' + input.dataset.category + ']';
                    hidden.value = input.value;
                    hidden.dataset.weightField = '1';
                    form.appendChild(hidden);
                });
            }
            form.dataset.skipConfirm = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(event.submitter || undefined);
            else form.submit();
        });
    });
})();

(function () {
    const form = document.querySelector('form.filters[data-live="students"]');
    const results = document.getElementById('student-results');
    if (!form || !results) return;
    const search = form.querySelector('#q');
    const program = form.querySelector('#program');
    const year = form.querySelector('#year');
    const section = form.querySelector('#section');
    const type = form.querySelector('#type');
    const professor = form.querySelector('#professor');
    const assignment = form.querySelector('#assignment');
    let timer = 0;
    let requestId = 0;

    function queryString() {
        const params = new URLSearchParams();
        const typed = search ? search.value.trim() : '';
        if (typed !== '') params.set('q', typed);
        if (program && program.value !== '0') params.set('program', program.value);
        if (year && year.value !== '') params.set('year', year.value);
        if (section && section.value !== '0') params.set('section', section.value);
        if (type && type.value !== '') params.set('type', type.value);
        if (professor && professor.value !== '0' && (!assignment || assignment.value !== 'unassigned')) params.set('professor', professor.value);
        if (assignment && assignment.value !== '') params.set('assignment', assignment.value);
        const query = params.toString();
        return query === '' ? form.action : form.action + '?' + query;
    }

    function copyOptions(fresh, current) {
        if (!fresh || !current) return;
        const wanted = current.value;
        current.innerHTML = fresh.innerHTML;
        const stillThere = Array.prototype.some.call(current.options, function (option) {
            return option.value === wanted;
        });
        current.value = stillThere ? wanted : (current === section ? '0' : '');
    }

    function run() {
        const url = queryString();
        const current = ++requestId;
        results.setAttribute('aria-busy', 'true');
        fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('search failed');
                return response.text();
            })
            .then(function (html) {
                if (current !== requestId) return;
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const freshResults = doc.getElementById('student-results');
                if (freshResults) results.innerHTML = freshResults.innerHTML;
                copyOptions(doc.getElementById('year'), year);
                copyOptions(doc.getElementById('section'), section);
                history.replaceState(null, '', url);
            })
            .catch(function () {})
            .then(function () {
                if (current === requestId) results.removeAttribute('aria-busy');
            });
    }

    function schedule(immediate) {
        window.clearTimeout(timer);
        timer = window.setTimeout(run, immediate ? 0 : 200);
    }

    function shapeSearch() {
        if (!search) return;
        const value = search.value;
        if (/[^\d\s-]/.test(value)) return;
        const digits = value.replace(/\D/g, '').slice(0, 9);
        let formatted = digits;
        if (digits.length > 4) formatted = digits.slice(0, 2) + '-' + digits.slice(2, 4) + '-' + digits.slice(4);
        else if (digits.length > 2) formatted = digits.slice(0, 2) + '-' + digits.slice(2);
        if (formatted === value) return;
        const caret = search.selectionStart == null ? value.length : search.selectionStart;
        const digitsBefore = value.slice(0, caret).replace(/\D/g, '').length;
        search.value = formatted;
        let pos = 0;
        let seen = 0;
        while (pos < formatted.length && seen < digitsBefore) {
            if (/\d/.test(formatted.charAt(pos))) seen += 1;
            pos += 1;
        }
        search.setSelectionRange(pos, pos);
    }

    if (search) {
        search.addEventListener('input', function () {
            shapeSearch();
            schedule(false);
        });
        search.addEventListener('search', function () {
            shapeSearch();
            schedule(true);
        });
    }
    if (year) year.addEventListener('change', function () { schedule(true); });
    if (section) section.addEventListener('change', function () { schedule(true); });
    if (type) type.addEventListener('change', function () { schedule(true); });
    if (professor) professor.addEventListener('change', function () { schedule(true); });
    if (assignment) assignment.addEventListener('change', function () { schedule(true); });
    if (program) {
        program.addEventListener('change', function () {
            if (section) section.value = '0';
            schedule(true);
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        schedule(true);
    });
})();
