/* global abcnorioDeployment */
(function () {
    const { ajaxUrl, triggerNonce, pollNonce, previewUrls, buildExists = {} } = abcnorioDeployment;

    let pollTimer = null;
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

    function showPreview(envKey, panel) {
        const link = panel.querySelector('.js-preview-link');
        const url  = previewUrls[envKey];
        if (link && url) {
            buildExists[envKey] = true;
            link.href = url;
            link.classList.remove('hidden');
        }
    }

    function initializePreviewLinks() {
        document.querySelectorAll('.js-preview-link').forEach((link) => {
            const env = link.dataset.env;
            const url = previewUrls[env];
            if (url && buildExists[env]) {
                link.href = url;
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
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        setStatus(panel, 'Build complete.');
                        showPreview(envKey, panel);
                    } else if (st.status === 'failed') {
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        setStatus(panel, 'Build failed — check server logs.');
                    }
                })
                .catch(() => {
                    // network hiccup — keep polling
                });
        }, 2500);
    }

    initializePreviewLinks();

    // --- Build trigger ---
    document.querySelectorAll('.js-trigger-build').forEach((btn) => {
        btn.addEventListener('click', () => {
            const confirmMsg = btn.dataset.confirm;
            if (confirmMsg && !window.confirm(confirmMsg)) return;

            const target = btn.dataset.target;
            const panel  = btn.closest('.deployment-tab');

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
                        setButtonIdle(btn);
                        setStatus(panel, 'Error: ' + (data.data && data.data.message ? data.data.message : 'unknown error'));
                        return;
                    }

                    startTimer(panel);
                    startPolling(btn, panel, target);
                })
                .catch(() => {
                    stopTimer();
                    setButtonIdle(btn);
                    setStatus(panel, 'Request failed.');
                });
        });
    });
}());
