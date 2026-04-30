/* global abcnorioDeployment */
(function () {
    const { ajaxUrl, triggerNonce, pollNonce, pushToStagingNonce, pollPushNonce, copyMediaNonce, pollCopyMediaNonce, targets = {} } = abcnorioDeployment;

    let pollTimer = null;
    let pushPollTimer = null;
    let buildStartTime = null;
    let timerInterval = null;

    // --- Tab switching ---
    document.querySelectorAll('#deployment-tabs .nav-tab').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const target = tab.dataset.tab;

            document.querySelectorAll('#deployment-tabs .nav-tab').forEach((t) => {
                t.classList.toggle('nav-tab-active', t.dataset.tab === target);
            });

            document.querySelectorAll('.deployment-tab').forEach((panel) => {
                panel.classList.toggle('hidden', panel.id !== 'tab-' + target);
            });
        });
    });

    // --- Spinner ---
    const SPINNER_SVG = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;animation:abcnorio-spin 1s linear infinite" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';

    const styleEl = document.createElement('style');
    styleEl.textContent = '@keyframes abcnorio-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(styleEl);

    // --- Helpers ---
    function setButtonRunning(btn) {
        btn.disabled = true;
        btn.innerHTML = SPINNER_SVG + ' ' + btn.dataset.label + '\u2026';
    }

    function setButtonIdle(btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.label;
    }

    function setStatus(panel, msg) {
        const el = panel.querySelector('.js-build-status');
        if (el) el.textContent = msg;
    }

    function formatElapsed(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        if (mins > 0) {
            return mins + 'm ' + secs + 's';
        }
        return secs + 's';
    }

    function startTimer(panel) {
        buildStartTime = Date.now();
        clearInterval(timerInterval);
        timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - buildStartTime) / 1000);
            setStatus(panel, 'Build running (' + formatElapsed(elapsed) + ')\u2026');
        }, 500);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        buildStartTime = null;
    }

    function showAdminNotice(message, type = 'success') {
        const wrap = document.querySelector('.wrap');
        if (!wrap) return;

        const notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible';
        notice.innerHTML = '<p>' + message + '</p>';
        wrap.insertBefore(notice, wrap.firstChild);
    }

    function redirectToDeploymentNotice(envKey, type = 'success', message = '') {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'abcnorio-deployment');
        url.searchParams.set('tab', envKey);
        url.searchParams.set('deployment_notice', type);
        url.searchParams.set('deployment_env', envKey);
        if (message) {
            url.searchParams.set('deployment_message', message);
        } else {
            url.searchParams.delete('deployment_message');
        }

        if (type === 'success') {
            url.searchParams.set('deployed', envKey);
        } else {
            url.searchParams.delete('deployed');
        }

        url.searchParams.delete('restored');
        window.location.assign(url.toString());
    }

    function getProductionFirstBackupName() {
        const list = document.querySelector('#tab-production .js-backup-list');
        if (!list) return '';
        const first = list.querySelector('li .backup-item-name');
        if (!first) return '';
        return (first.textContent || '').trim();
    }

    function markPendingBackupHighlight() {
        try {
            const previousFirst = getProductionFirstBackupName();
            sessionStorage.setItem('abcnorio.pendingBackupHighlight', '1');
            sessionStorage.setItem('abcnorio.previousProductionFirstBackup', previousFirst);
        } catch {
            // ignore storage issues
        }
    }

    function maybeHighlightNewBackup() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('deployed') !== 'production') return;

        let pending = '';
        let previousFirst = '';
        try {
            pending = sessionStorage.getItem('abcnorio.pendingBackupHighlight') || '';
            previousFirst = sessionStorage.getItem('abcnorio.previousProductionFirstBackup') || '';
        } catch {
            return;
        }

        if (pending !== '1') {
            return;
        }

        const list = document.querySelector('#tab-production .js-backup-list');
        if (!list) return;

        const firstItem = list.querySelector('li');
        const firstNameEl = list.querySelector('li .backup-item-name');
        const currentFirst = firstNameEl ? (firstNameEl.textContent || '').trim() : '';
        if (firstItem && currentFirst && currentFirst !== previousFirst) {
            firstItem.classList.add('backup-item-new');
        }

        try {
            sessionStorage.removeItem('abcnorio.pendingBackupHighlight');
            sessionStorage.removeItem('abcnorio.previousProductionFirstBackup');
        } catch {
            // ignore storage issues
        }
    }

    function showPreview(envKey, panel) {
        const link = panel.querySelector('.js-preview-link[data-env="' + envKey + '"]');
        const url  = targets[envKey] && targets[envKey].previewUrl;
        if (link && url) {
            if (targets[envKey]) {
                targets[envKey].hasBuild = true;
            }
            link.href = url;
            link.classList.remove('hidden');
        }
    }

    function initializePreviewLinks() {
        document.querySelectorAll('.js-preview-link').forEach((link) => {
            const env = link.dataset.env;
            const target = targets[env] || {};
            if (target.previewUrl && target.hasBuild) {
                link.href = target.previewUrl;
                link.classList.remove('hidden');
            }
        });
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPolling(btn, panel, envKey) {
        stopPolling();

        pollTimer = setInterval(() => {
            const body = new URLSearchParams({
                action: 'abcnorio_poll_build_status',
                nonce: pollNonce,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) return;

                    const st = data.data;

                    if (st.status === 'done') {
                        if (st.target && st.target !== envKey) {
                            return;
                        }
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        const doneMessage = envKey === 'production'
                            ? 'Backup and deployment complete.'
                            : envKey === 'preview'
                                ? 'Production preview build complete.'
                                : 'Build complete.';
                        setStatus(panel, doneMessage);
                        setTimeout(() => setStatus(panel, ''), 5000);
                        if (envKey === 'production') {
                            redirectToDeploymentNotice(envKey);
                            return;
                        }
                        if (envKey === 'preview') {
                            redirectToDeploymentNotice('production', 'success', doneMessage);
                            return;
                        }
                        showAdminNotice(doneMessage, 'success');
                        showPreview(envKey, panel);
                    } else if (st.status === 'failed') {
                        if (st.target && st.target !== envKey) {
                            return;
                        }
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        const baseMessage = envKey === 'production'
                            ? 'Backup and deployment failed.'
                            : envKey === 'preview'
                                ? 'Production preview build failed.'
                            : 'Build failed.';
                        const detail = st.message ? ' ' + st.message : ' Check server logs.';
                        const failedMessage = baseMessage + detail;
                        setStatus(panel, failedMessage);
                        redirectToDeploymentNotice(envKey, 'error', failedMessage);
                    }
                })
                .catch(() => {
                    // network hiccup — keep polling
                });
        }, 2500);
    }

    initializePreviewLinks();

    // Highlight only after build-complete redirect when backup list actually changed.
    maybeHighlightNewBackup();

    // --- Build trigger ---
    document.querySelectorAll('.js-trigger-build').forEach((btn) => {
        btn.addEventListener('click', () => {
            const confirmMsg = btn.dataset.confirm;
            if (confirmMsg && !window.confirm(confirmMsg)) return;

            const target = btn.dataset.target;
            const panel  = btn.closest('.deployment-tab');

            if (target === 'production' || target === 'preview') {
                markPendingBackupHighlight();
            }

            setButtonRunning(btn);
            setStatus(panel, 'Starting build\u2026');

            const body = new URLSearchParams({
                action: 'abcnorio_trigger_build',
                nonce: triggerNonce,
                target,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        const errorMessage = data.data && data.data.message ? data.data.message : 'unknown error';
                        setButtonIdle(btn);
                        setStatus(panel, 'Error: ' + errorMessage);
                        redirectToDeploymentNotice(target, 'error', 'Could not start ' + (target === 'production' ? 'deployment' : 'build') + ': ' + errorMessage);
                        return;
                    }

                    startTimer(panel);
                    startPolling(btn, panel, target);
                })
                .catch(() => {
                    stopTimer();
                    setButtonIdle(btn);
                    setStatus(panel, 'Request failed.');
                    redirectToDeploymentNotice(target, 'error', 'Request failed while starting ' + (target === 'production' ? 'deployment' : 'build') + '.');
                });
        });
    });
    // --- Push to staging ---
    const pushBtn = document.querySelector('.js-push-to-staging');
    if (pushBtn) {
        pushBtn.addEventListener('click', () => {
            const panel = pushBtn.closest('.deployment-tab');
            const statusEl = panel && panel.querySelector('.js-push-status');

            setButtonRunning(pushBtn);
            if (statusEl) statusEl.textContent = 'Pushing\u2026';

            const body = new URLSearchParams({
                action: 'abcnorio_push_to_staging',
                nonce: pushToStagingNonce,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        setButtonIdle(pushBtn);
                        const msg = data.data && data.data.message ? data.data.message : 'Unknown error';
                        if (statusEl) statusEl.textContent = 'Error: ' + msg;
                        return;
                    }

                    if (pushPollTimer) clearInterval(pushPollTimer);
                    pushPollTimer = setInterval(() => {
                        const pollBody = new URLSearchParams({
                            action: 'abcnorio_poll_push_status',
                            nonce: pollPushNonce,
                        });
                        fetch(ajaxUrl, { method: 'POST', body: pollBody })
                            .then((r) => r.json())
                            .then((pollData) => {
                                if (!pollData.success) return;
                                const st = pollData.data;
                                if (st.status === 'done') {
                                    clearInterval(pushPollTimer);
                                    pushPollTimer = null;
                                    setButtonIdle(pushBtn);
                                    if (statusEl) statusEl.textContent = st.message || 'Push complete.';
                                    setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 5000);
                                } else if (st.status === 'failed') {
                                    clearInterval(pushPollTimer);
                                    pushPollTimer = null;
                                    setButtonIdle(pushBtn);
                                    if (statusEl) statusEl.textContent = 'Push failed: ' + (st.message || 'check logs.');
                                }
                            })
                            .catch(() => { /* network hiccup — keep polling */ });
                    }, 2000);
                })
                .catch(() => {
                    setButtonIdle(pushBtn);
                    if (statusEl) statusEl.textContent = 'Request failed.';
                });
        });
    }

    // --- Copy staging media to dev ---
    const copyMediaBtn = document.querySelector('.js-copy-media-to-dev');
    if (copyMediaBtn) {
        let copyMediaPollTimer = null;

        copyMediaBtn.addEventListener('click', () => {
            const panel = copyMediaBtn.closest('.deployment-tab');
            const statusEl = panel && panel.querySelector('.js-copy-media-status');

            setButtonRunning(copyMediaBtn);
            if (statusEl) statusEl.textContent = 'Copying…';

            const body = new URLSearchParams({
                action: 'abcnorio_copy_media_to_dev',
                nonce: copyMediaNonce,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        setButtonIdle(copyMediaBtn);
                        const msg = data.data && data.data.message ? data.data.message : 'Unknown error';
                        if (statusEl) statusEl.textContent = 'Error: ' + msg;
                        return;
                    }

                    if (copyMediaPollTimer) clearInterval(copyMediaPollTimer);
                    copyMediaPollTimer = setInterval(() => {
                        const pollBody = new URLSearchParams({
                            action: 'abcnorio_poll_copy_media_status',
                            nonce: pollCopyMediaNonce,
                        });
                        fetch(ajaxUrl, { method: 'POST', body: pollBody })
                            .then((r) => r.json())
                            .then((pollData) => {
                                if (!pollData.success) return;
                                const st = pollData.data;
                                if (st.status === 'done') {
                                    clearInterval(copyMediaPollTimer);
                                    copyMediaPollTimer = null;
                                    setButtonIdle(copyMediaBtn);
                                    if (statusEl) statusEl.textContent = st.message || 'Copy complete.';
                                    setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 5000);
                                } else if (st.status === 'failed') {
                                    clearInterval(copyMediaPollTimer);
                                    copyMediaPollTimer = null;
                                    setButtonIdle(copyMediaBtn);
                                    if (statusEl) statusEl.textContent = 'Copy failed: ' + (st.message || 'check logs.');
                                }
                            })
                            .catch(() => { /* network hiccup — keep polling */ });
                    }, 2000);
                })
                .catch(() => {
                    setButtonIdle(copyMediaBtn);
                    if (statusEl) statusEl.textContent = 'Request failed.';
                });
        });
    }

}());
