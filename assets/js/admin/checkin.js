document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('qr-video');
    const canvas = document.getElementById('qr-canvas');
    const ctx = canvas.getContext('2d');
    const fallback = document.getElementById('scanner-fallback');
    const manualInput = document.getElementById('manual-code');
    const manualSubmit = document.getElementById('manual-submit');
    const resultCard = document.getElementById('result');
    const resultCode = document.getElementById('result-code');
    const resultState = document.getElementById('result-state');
    const resultUser = document.getElementById('result-user');
    const resultSeats = document.getElementById('result-seats');
    const resultMsg = document.getElementById('result-msg');
    const checkinBtn = document.getElementById('checkin-btn');
    const scanNextBtn = document.getElementById('scan-next-btn');

    let scanning = true;
    let lastScanned = null;

    function showAlert(text, type = 'danger') {
        const area = document.getElementById('alert-area');
        area.innerHTML = `<div class="alert alert-${type}" role="alert">${text}</div>`;
        setTimeout(() => area.innerHTML = '', 4000);
    }

    function resetResult() {
        resultCard.classList.add('d-none');
        resultCode.textContent = '';
        resultState.textContent = '';
        resultUser.innerHTML = '';
        resultSeats.innerHTML = '';
        resultMsg.textContent = '';
        checkinBtn.disabled = false;
    }

    function showResult(data) {
        resultCard.classList.remove('d-none');
        resultCode.textContent = `Ticket: ${data.ticket.code}`;
        resultState.textContent = `Status: ${data.ticket.state ?? 'UNKNOWN'}`;
        if (data.ticket.redeemer) {
            const u = data.ticket.redeemer;
            resultUser.innerHTML = `<strong>${u.nickname ?? u.email}</strong> (${u.firstname || ''} ${u.surname || ''})`;
        } else {
            resultUser.innerHTML = `<em>Ticket nicht eingelöst</em>`;
        }
        if (data.ticket.seats && data.ticket.seats.length) {
            resultSeats.innerHTML = `Sitzplatz: ${data.ticket.seats.join(', ')}`;
        } else {
            resultSeats.innerHTML = '';
        }
    }

    async function fetchTicket(code) {
        try {
            const form = new URLSearchParams();
            form.append('code', code);
            const resp = await fetch(window.location.pathname.replace(/\/payment.*$/, '/payment/quick-checkin/find'), {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: form
            });
            return await resp.json();
        } catch (e) {
            return {ok: false, error: 'network'};
        }
    }

    async function punchTicket(code) {
        try {
            const form = new URLSearchParams();
            form.append('code', code);
            const resp = await fetch(window.location.pathname.replace(/\/payment.*$/, '/payment/quick-checkin/punch'), {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: form
            });
            return await resp.json();
        } catch (e) {
            return {ok: false, error: 'network'};
        }
    }

    checkinBtn.addEventListener('click', async function () {
        if (!lastScanned) return;
        checkinBtn.disabled = true;
        const res = await punchTicket(lastScanned);
        if (res.ok) {
            showAlert('Check-In erfolgreich', 'success');
            resetResult();
            lastScanned = null;
            scanning = true;
        } else {
            showAlert('Check-In fehlgeschlagen: ' + (res.error || res.message || 'unknown'));
            checkinBtn.disabled = false;
        }
    });

    scanNextBtn.addEventListener('click', function () {
        resetResult();
        lastScanned = null;
        scanning = true;
    });

    manualSubmit.addEventListener('click', async function () {
        const code = manualInput.value.trim();
        if (!code) return showAlert('Kein Code eingegeben');
        scanning = false;
        const data = await fetchTicket(code);
        if (!data.ok) {
            showAlert('Ticket nicht gefunden.');
            scanning = true;
            return;
        }
        lastScanned = data.ticket.code;
        showResult(data);
    });

    // BarcodeDetector-based scanning
    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            fallback.classList.remove('d-none');
            return;
        }

        const stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
        video.srcObject = stream;

        if ('BarcodeDetector' in window) {
            const detector = new BarcodeDetector({formats: ['qr_code']});
            const loop = async () => {
                if (!scanning) return requestAnimationFrame(loop);
                try {
                    const barcodes = await detector.detect(video);
                    if (barcodes.length) {
                        const code = barcodes[0].rawValue.trim();
                        if (code && code !== lastScanned) {
                            scanning = false;
                            lastScanned = code;
                            const data = await fetchTicket(code);
                            if (!data.ok) {
                                showAlert('Ticket nicht gefunden.');
                                setTimeout(() => { scanning = true; lastScanned = null; }, 1500);
                            } else {
                                showResult(data);
                            }
                        }
                    }
                } catch (e) {
                    console.error('Detector error', e);
                }
                requestAnimationFrame(loop);
            };
            requestAnimationFrame(loop);
        } else {
            // fallback: sample frames to canvas and try decoding with server-side or manual input
            // show manual input
            fallback.classList.remove('d-none');
        }
    }

    startCamera().catch(err => {
        console.error('camera start failed', err);
        fallback.classList.remove('d-none');
    });
});
