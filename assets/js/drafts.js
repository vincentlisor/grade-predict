(function () {
    const DB_NAME = 'spcc-grade-drafts';
    const STORE = 'scores';
    const RECORD_STORE = 'records';
    const meta = function (name) {
        const tag = document.querySelector('meta[name="' + name + '"]');
        return tag ? tag.content : '';
    };
    const draftUrl = meta('draft-url');
    const swUrl = meta('sw-url');
    const swScope = meta('sw-scope');

    let dbPromise = null;
    let pending = [];
    let office = [];
    let flushing = false;
    let armed = false;
    let needsSignIn = false;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            const request = indexedDB.open(DB_NAME, 2);
            request.onupgradeneeded = function () {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(RECORD_STORE)) {
                    db.createObjectStore(RECORD_STORE, { keyPath: 'id' });
                }
            };
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
        return dbPromise;
    }

    function withStore(mode, run) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE, mode);
                const result = run(tx.objectStore(STORE));
                tx.oncomplete = function () { resolve(result); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function readAll() {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE, 'readonly');
                const request = tx.objectStore(STORE).getAll();
                request.onsuccess = function () { resolve(request.result || []); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function readRecords() {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                if (!db.objectStoreNames.contains(RECORD_STORE)) {
                    resolve([]);
                    return;
                }
                const tx = db.transaction(RECORD_STORE, 'readonly');
                const request = tx.objectStore(RECORD_STORE).getAll();
                request.onsuccess = function () { resolve(request.result || []); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function withRecords(mode, run) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(RECORD_STORE, mode);
                const result = run(tx.objectStore(RECORD_STORE));
                tx.oncomplete = function () { resolve(result); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function refresh() {
        return Promise.all([readAll(), readRecords()]).then(function (parts) {
            pending = parts[0];
            office = parts[1];
            paint();
            return parts[0];
        }).catch(function () {
            pending = [];
            office = [];
            paint();
            return [];
        });
    }

    function bar() {
        let node = document.getElementById('draftBar');
        if (node) return node;
        node = document.createElement('div');
        node.id = 'draftBar';
        node.className = 'draft-bar';
        node.hidden = true;
        node.innerHTML = '<p id="draftBarText"></p><button type="button" class="btn btn-sm" id="draftBarSync">Sync now</button>';
        document.body.appendChild(node);
        node.querySelector('#draftBarSync').addEventListener('click', function () {
            flush();
        });
        return node;
    }

    function paint() {
        const node = bar();
        const text = node.querySelector('#draftBarText');
        const button = node.querySelector('#draftBarSync');
        const scoreCount = pending.length;
        const recordCount = office.length;
        const count = scoreCount + recordCount;
        const waiting = pending.filter(function (row) { return !row.failed; }).length
            + office.filter(function (row) { return !row.failed; }).length;
        const noun = recordCount && !scoreCount
            ? (count === 1 ? 'record' : 'records')
            : (scoreCount && !recordCount ? (count === 1 ? 'score' : 'scores') : (count === 1 ? 'change' : 'changes'));
        const offline = !navigator.onLine;
        if (!offline && count === 0) {
            node.hidden = true;
            return;
        }
        node.hidden = false;
        node.classList.toggle('is-offline', offline);
        button.hidden = offline || waiting === 0;
        if (offline && count === 0) {
            text.textContent = document.querySelector('[data-gradebook]')
                ? 'You are offline. Scores you enter stay on this device.'
                : (location.pathname.indexOf('/admin/') !== -1
                    ? 'You are offline. Office pages saved on this device stay open.'
                    : 'You are offline. Open Gradebook still opens the classes saved on this device.');
        } else if (offline) {
            text.textContent = count === 1
                ? '1 ' + noun + ' is saved on this device. It will sync when you reconnect.'
                : count + ' ' + noun + ' are saved on this device. They will sync when you reconnect.';
        } else if (needsSignIn) {
            text.textContent = 'Sign in to save ' + (count === 1 ? 'the saved ' + noun : count + ' saved ' + noun) + ' to the college record.';
            button.hidden = true;
        } else if (waiting === 0) {
            text.textContent = count === 1
                ? '1 saved ' + noun + ' could not be saved to the college record.'
                : count + ' saved ' + noun + ' could not be saved to the college record.';
        } else {
            text.textContent = waiting === 1
                ? 'Syncing the saved ' + (recordCount && !scoreCount ? 'record' : (scoreCount && !recordCount ? 'score' : 'change')) + '.'
                : 'Syncing all ' + waiting + ' saved ' + (recordCount && !scoreCount ? 'records' : (scoreCount && !recordCount ? 'scores' : 'changes')) + '.';
        }
    }

    function put(record) {
        record.updatedAt = Date.now();
        record.failed = false;
        return withStore('readwrite', function (store) {
            store.put(record);
        }).then(refresh);
    }

    function remove(id) {
        return withStore('readwrite', function (store) {
            store.delete(id);
        }).then(refresh);
    }

    async function flush() {
        if (flushing || !armed) return;
        if (!navigator.onLine) {
            needsSignIn = false;
            paint();
            return;
        }
        flushing = true;
        needsSignIn = false;
        try {
            const rows = (await readAll()).filter(function (row) { return !row.failed; });
            const officeRows = (await readRecords()).filter(function (row) { return !row.failed; });
            pending = await readAll();
            office = await readRecords();
            if (rows.length === 0 && officeRows.length === 0) {
                paint();
                return;
            }
            paint();
            if (rows.length) {
                let session = await fetch(draftUrl, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
                });
                if (session.status === 401 && await resumeServerSession()) {
                    session = await fetch(draftUrl, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
                    });
                }
                if (session.status === 401) {
                    needsSignIn = true;
                } else {
                    const sessionPayload = await session.json().catch(function () { return { ok: false }; });
                    if (session.ok && sessionPayload.ok && sessionPayload.csrf) {
                        const scoreUrl = (rows[0] && rows[0].url) || draftUrl.replace('draft.php', 'score.php');
                        async function postBatch(csrf) {
                            const body = new FormData();
                            body.set('csrf_token', csrf);
                            body.set('kind', 'batch');
                            body.set('scores', JSON.stringify(rows.map(function (row) {
                                return { id: row.id, fields: row.fields || {} };
                            })));
                            const response = await fetch(scoreUrl, { method: 'POST', body: body, credentials: 'same-origin' });
                            const payload = await response.json().catch(function () { return { ok: false }; });
                            return { response: response, payload: payload };
                        }
                        let batch = await postBatch(sessionPayload.csrf);
                        if (batch.response.status === 401 && await resumeServerSession()) {
                            const csrf = await freshCsrf();
                            if (csrf) batch = await postBatch(csrf);
                        }
                        if (batch.response.status === 401) {
                            needsSignIn = true;
                        } else if (batch.response.ok && batch.payload.ok && Array.isArray(batch.payload.results)) {
                            const saved = [];
                            const failed = [];
                            batch.payload.results.forEach(function (result) {
                                if (result.ok) saved.push(result);
                                else failed.push(result);
                            });
                            document.dispatchEvent(new CustomEvent('spcc-drafts-synced', { detail: { results: saved } }));
                            if (failed.length) {
                                document.dispatchEvent(new CustomEvent('spcc-drafts-failed', { detail: { results: failed } }));
                            }
                            await withStore('readwrite', function (store) {
                                saved.forEach(function (result) { store.delete(result.id); });
                                failed.forEach(function (result) {
                                    const item = rows.find(function (row) { return row.id === result.id; });
                                    if (!item) return;
                                    item.failed = true;
                                    item.error = result.message || 'The score was not accepted.';
                                    store.put(item);
                                });
                            });
                        }
                    }
                }
            }
            if (!needsSignIn && officeRows.length) {
                await flushOffice(officeRows);
            }
        } catch (error) {
            needsSignIn = false;
        } finally {
            flushing = false;
            await refresh();
        }
    }

    async function pageToken(url) {
        const page = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        if (page.url.indexOf('login.php') !== -1) return '';
        const html = await page.text();
        return (html.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
    }

    async function flushOffice(rows) {
        let savedAny = false;
        let failedAny = false;
        for (let index = 0; index < rows.length; index += 1) {
            const row = rows[index];
            let token = await pageToken(row.url);
            if (!token && await resumeServerSession()) token = await pageToken(row.url);
            if (!token) {
                needsSignIn = true;
                return;
            }
            const body = new FormData();
            Object.keys(row.fields || {}).forEach(function (key) {
                body.append(key, row.fields[key]);
            });
            body.set('csrf_token', token);
            const response = await fetch(row.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                redirect: 'follow',
                cache: 'no-store'
            });
            const html = await response.text();
            if (!response.ok || response.url.indexOf('login.php') !== -1 || html.indexOf('alert-error') !== -1) {
                failedAny = true;
                row.failed = true;
                row.error = ((html.match(/class="alert alert-error"[^>]*>([^<]+)/) || [])[1] || 'The record was not accepted.').trim();
                await withRecords('readwrite', function (store) { store.put(row); });
                if (typeof toast === 'function') toast(row.error, 'error');
                continue;
            }
            savedAny = true;
            await withRecords('readwrite', function (store) { store.delete(row.id); });
        }
        if (savedAny && !failedAny && document.querySelector('form[data-offline-save]')) {
            location.reload();
        }
    }

    function arm() {
        if (armed) return;
        armed = true;
        flush();
    }

    function cellId(fields) {
        return String(fields.enrollment_id || '') + ':' + String(fields.component_id || '');
    }

    let warming = false;

    function offlineList() {
        const node = document.getElementById('offlinePages');
        if (!node) return [];
        try {
            const urls = JSON.parse(node.textContent || '[]');
            return Array.isArray(urls) ? urls : [];
        } catch (error) {
            return [];
        }
    }

    async function warmUrls(urls) {
        let index = 0;
        let failed = false;
        async function next() {
            while (index < urls.length) {
                const url = urls[index];
                index += 1;
                try {
                    const response = await fetch(url, { credentials: 'same-origin', cache: 'reload' });
                    if (response.body && response.body.cancel) await response.body.cancel();
                    const saved = await caches.match(url);
                    if (!response.ok || !saved) failed = true;
                } catch (error) {
                    failed = true;
                }
            }
        }
        await Promise.all([next(), next()]);
        return !failed && navigator.onLine;
    }

    function startWarm() {
        if (warming || !navigator.onLine || !navigator.serviceWorker || !navigator.serviceWorker.controller) return;
        const urls = offlineList();
        if (urls.length === 0) return;
        const warmKey = location.pathname.indexOf('/admin/') !== -1 ? 'spcc-offline-warm-admin-at' : 'spcc-offline-warm-at';
        const last = Number(localStorage.getItem(warmKey) || 0);
        if (Date.now() - last < 10 * 60 * 1000) return;
        warming = true;
        warmUrls(urls).then(function (ok) {
            if (ok) localStorage.setItem(warmKey, String(Date.now()));
        }).finally(function () {
            warming = false;
        });
    }

    if ('serviceWorker' in navigator && swUrl && window.isSecureContext) {
        navigator.serviceWorker.register(swUrl, { scope: swScope || undefined }).then(function () {
            return navigator.serviceWorker.ready;
        }).then(function () {
            startWarm();
        }).catch(function () {});
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            startWarm();
        });
    }

    const LOGIN_DB = 'spcc-device-login';
    const LOGIN_STORE = 'accounts';
    const LOGIN_ROUNDS = 150000;

    function openLoginDb() {
        return new Promise(function (resolve, reject) {
            const request = indexedDB.open(LOGIN_DB, 1);
            request.onupgradeneeded = function () {
                const db = request.result;
                if (!db.objectStoreNames.contains(LOGIN_STORE)) {
                    db.createObjectStore(LOGIN_STORE, { keyPath: 'username' });
                }
            };
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    function loginStore(mode, run) {
        return openLoginDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(LOGIN_STORE, mode);
                const result = run(tx.objectStore(LOGIN_STORE));
                tx.oncomplete = function () { resolve(result); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function rememberSyncLogin(username, password, portal) {
        const current = savedSyncLogin();
        sessionStorage.setItem('spcc-sync-auth', JSON.stringify({
            username: username,
            password: password,
            portal: portal || (current && current.portal) || 'professor'
        }));
    }

    function clearSyncLogin() {
        sessionStorage.removeItem('spcc-sync-auth');
    }

    function savedSyncLogin() {
        try {
            const saved = JSON.parse(sessionStorage.getItem('spcc-sync-auth') || 'null');
            if (!saved || !saved.username || !saved.password) return null;
            return saved;
        } catch (error) {
            return null;
        }
    }

    async function freshCsrf() {
        const session = await fetch(draftUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        });
        const payload = await session.json().catch(function () { return { ok: false }; });
        return session.ok && payload.ok ? payload.csrf : '';
    }

    async function resumeServerSession() {
        const saved = savedSyncLogin();
        const portal = saved && saved.portal === 'admin' ? 'admin' : 'professor';
        const loginUrl = portal === 'admin'
            ? meta('admin-login')
            : (meta('professor-login') || meta('draft-url').replace(/api\/draft\.php(?:\?.*)?$/, 'professor/login.php'));
        if (!saved || !loginUrl || loginUrl === meta('draft-url')) return false;
        try {
            const page = await fetch(loginUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (page.url.indexOf('dashboard.php') !== -1) return true;
            const html = await page.text();
            const token = (html.match(/name="csrf_token" value="([^"]+)"/) || [])[1];
            if (!token) return false;
            const body = new FormData();
            body.set('csrf_token', token);
            body.set('username', saved.username);
            body.set('password', saved.password);
            const response = await fetch(loginUrl, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                redirect: 'follow'
            });
            return response.url.indexOf('dashboard.php') !== -1;
        } catch (error) {
            return false;
        }
    }

    function accountKey(username) {
        return String(username || '').trim().toLowerCase();
    }

    function bytesToB64(bytes) {
        let text = '';
        bytes.forEach(function (byte) { text += String.fromCharCode(byte); });
        return btoa(text);
    }

    function b64ToBytes(value) {
        const text = atob(value);
        const bytes = new Uint8Array(text.length);
        for (let i = 0; i < text.length; i += 1) bytes[i] = text.charCodeAt(i);
        return bytes;
    }

    function bytesEqual(left, right) {
        if (left.length !== right.length) return false;
        let diff = 0;
        for (let i = 0; i < left.length; i += 1) diff |= left[i] ^ right[i];
        return diff === 0;
    }

    function derivePassword(password, salt, iterations) {
        return crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode(password),
            'PBKDF2',
            false,
            ['deriveBits']
        ).then(function (key) {
            return crypto.subtle.deriveBits({
                name: 'PBKDF2',
                salt: salt,
                iterations: iterations,
                hash: 'SHA-256'
            }, key, 256);
        }).then(function (bits) {
            return new Uint8Array(bits);
        });
    }

    function readAccount(username) {
        const key = accountKey(username);
        if (!key) return Promise.resolve(null);
        return openLoginDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(LOGIN_STORE, 'readonly');
                const request = tx.objectStore(LOGIN_STORE).get(key);
                request.onsuccess = function () { resolve(request.result || null); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function hasDeviceLogin() {
        return openLoginDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const request = db.transaction(LOGIN_STORE, 'readonly').objectStore(LOGIN_STORE).count();
                request.onsuccess = function () { resolve(request.result > 0); };
                request.onerror = function () { reject(request.error); };
            });
        }).catch(function () { return false; });
    }

    function saveDeviceLogin(username, password) {
        const key = accountKey(username);
        if (!key || !password || !window.crypto || !crypto.subtle) return Promise.resolve();
        const salt = crypto.getRandomValues(new Uint8Array(16));
        return derivePassword(password, salt, LOGIN_ROUNDS).then(function (hash) {
            return loginStore('readwrite', function (store) {
                store.put({
                    username: key,
                    salt: bytesToB64(salt),
                    hash: bytesToB64(hash),
                    iterations: LOGIN_ROUNDS
                });
            });
        });
    }

    function unlockOffline(username, password) {
        if (!window.crypto || !crypto.subtle) {
            return Promise.resolve('Offline sign-in needs a secure page (HTTPS).');
        }
        return readAccount(username).then(function (row) {
            if (!row) {
                return 'Sign in once while connected. After that, this device can open your gradebook without internet.';
            }
            return derivePassword(password, b64ToBytes(row.salt), row.iterations).then(function (hash) {
                if (!bytesEqual(hash, b64ToBytes(row.hash))) {
                    return 'The username or password is not correct.';
                }
                return '';
            });
        });
    }

    function showLoginNote(form, message, error) {
        let note = document.getElementById('offlineLoginNote');
        if (!message) {
            if (note) note.remove();
            return;
        }
        if (!note) {
            note = document.createElement('div');
            note.id = 'offlineLoginNote';
            const anchor = form.querySelector('.auth-field');
            form.insertBefore(note, anchor || form.querySelector('button'));
        }
        note.className = error ? 'alert alert-error' : 'alert alert-info';
        note.hidden = false;
        note.textContent = message;
    }

    function replaceWithResponse(response) {
        return response.text().then(function (html) {
            document.open();
            document.write(html);
            document.close();
        });
    }

    function bindPortalLogin(marker, portal, savedNote, newNote) {
        const form = document.querySelector('form.auth-form');
        if (!form || form.dataset.offlineBound) return;
        const action = form.getAttribute('action') || form.action || '';
        if (action.indexOf(marker) === -1) return;
        form.dataset.offlineBound = '1';
        clearSyncLogin();
        const dashboard = form.action.replace(/login\.php(?:\?.*)?$/, 'dashboard.php');

        function refreshNote() {
            if (navigator.onLine) {
                showLoginNote(form, '', false);
                return;
            }
            hasDeviceLogin().then(function (saved) {
                if (navigator.onLine) return;
                showLoginNote(form, saved ? savedNote : newNote, false);
            });
        }
        refreshNote();
        window.addEventListener('offline', refreshNote);
        window.addEventListener('online', refreshNote);

        form.addEventListener('submit', function (event) {
            const username = (form.querySelector('[name="username"]').value || '').trim();
            const password = form.querySelector('[name="password"]').value || '';
            const button = form.querySelector('[type="submit"]');
            if (!navigator.onLine) {
                event.preventDefault();
                event.stopPropagation();
                unlockOffline(username, password).then(function (message) {
                    if (message) showLoginNote(form, message, true);
                    else {
                        rememberSyncLogin(username, password, portal);
                        location.href = dashboard;
                    }
                }).catch(function () {
                    showLoginNote(form, 'Offline sign-in could not be checked on this device.', true);
                });
                return;
            }
            event.preventDefault();
            if (button) button.disabled = true;
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'follow'
            }).then(function (response) {
                if (response.url.indexOf('dashboard.php') !== -1) {
                    rememberSyncLogin(username, password, portal);
                    return saveDeviceLogin(username, password).then(function () {
                        location.href = dashboard;
                    });
                }
                return replaceWithResponse(response);
            }).catch(function () {
                return unlockOffline(username, password).then(function (message) {
                    if (message) {
                        showLoginNote(form, message, true);
                        if (button) button.disabled = false;
                        return;
                    }
                    rememberSyncLogin(username, password, portal);
                    location.href = dashboard;
                });
            });
        });
    }

    function bindPasswordUpdate() {
        const form = document.querySelector('form[data-device-password]');
        if (!form || form.dataset.offlineBound) return;
        const username = form.getAttribute('data-device-password') || '';
        if (!username) return;
        form.dataset.offlineBound = '1';
        form.addEventListener('submit', function (event) {
            if (!navigator.onLine) return;
            event.preventDefault();
            const next = (form.querySelector('[name="new_password"]').value || '');
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'follow'
            }).then(function (response) {
                if (response.url.indexOf('dashboard.php') !== -1) {
                    rememberSyncLogin(username, next, form.getAttribute('data-device-portal') === 'admin' ? 'admin' : 'professor');
                    return saveDeviceLogin(username, next).then(function () {
                        location.href = response.url;
                    });
                }
                return replaceWithResponse(response);
            }).catch(function () {
                form.submit();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        refresh();
        bindPortalLogin(
            'professor/login.php',
            'professor',
            'No internet. Sign in with the faculty account already used on this device.',
            'No internet. Sign in once while connected, and this device can open your gradebook after that.'
        );
        bindPortalLogin(
            'admin/login.php',
            'admin',
            'No internet. Sign in with the office account already used on this device.',
            'No internet. Sign in once while connected, and this device can open office pages after that.'
        );
        bindPasswordUpdate();
        if (!document.querySelector('[data-gradebook]')) arm();
    });
    window.addEventListener('online', function () { flush(); });
    window.addEventListener('offline', function () { paint(); });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-submit-scope]');
        if (!button || button.disabled || pending.length === 0) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const message = 'Sync the draft scores before submitting. They are still only on this device.';
        if (typeof toast === 'function') toast(message, 'error');
    }, true);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form || !form.matches || !form.matches('form[data-offline-save]')) return;
        if (navigator.onLine) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const fields = {};
        new FormData(form).forEach(function (value, key) {
            if (key === 'csrf_token' || typeof value !== 'string') return;
            fields[key] = value;
        });
        const label = form.getAttribute('data-offline-label') || 'Record';
        const id = fields.id
            ? label + ':' + fields.id
            : (fields.student_number
                ? label + ':' + fields.student_number
                : (fields.username
                    ? label + ':' + fields.username
                    : (fields.code
                        ? label + ':' + fields.code
                        : label + ':' + (fields.action || 'save') + ':' + Date.now())));
        withRecords('readwrite', function (store) {
            store.put({
                id: id,
                url: form.action,
                fields: fields,
                label: label,
                failed: false,
                updatedAt: Date.now()
            });
        }).then(refresh).then(function () {
            if (typeof toast === 'function') toast(label + ' saved on this device. It will sync when you reconnect.', 'ok');
        });
    }, true);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        const action = form && form.action ? form.action : '';
        if (action.indexOf('professor/login.php') !== -1 || action.indexOf('admin/login.php') !== -1) return;
        if (form && form.matches && form.matches('form[data-offline-save]')) return;
        if (navigator.onLine) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const message = document.querySelector('[data-gradebook]')
            ? 'That change needs a connection. Scores you type stay on this device.'
            : 'That change needs a connection.';
        if (typeof toast === 'function') toast(message, 'error');
    }, true);

    window.addEventListener('online', function () {
        startWarm();
    });

    window.SpccDrafts = {
        put: function (fields, url) {
            return put({
                id: cellId(fields),
                url: url,
                fields: fields
            });
        },
        remove: remove,
        all: readAll,
        count: function () { return pending.length; },
        arm: arm,
        flush: flush
    };
})();
