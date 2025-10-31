import QrScanner from 'qr-scanner';

// Use import.meta.url so Encore/Vite can resolve the worker path
QrScanner.WORKER_PATH = new URL('qr-scanner/qr-scanner-worker.min.js', import.meta.url).toString();

const els = {
    video: document.getElementById('qr-video'),
    startBtn: document.getElementById('start-btn'),
    stopBtn: document.getElementById('stop-btn'),
    deviceSelect: document.getElementById('device-select'),
    torchBtn: document.getElementById('torch-btn'),
    result: document.getElementById('result'),
    error: document.getElementById('result-msg'),
    fileInput: document.getElementById('file-input'),
    checkinBtn: document.getElementById('checkin-btn'),
    scanNextBtn: document.getElementById('scan-next-btn'),
};

let scanner;
let currentDeviceId = null;
let lastCode = null;

function showAlert(msg, type = 'danger') {
    const area = document.getElementById('alert-area');
    area.innerHTML = `<div class="alert alert-${type}" role="alert">${msg}</div>`;
    setTimeout(() => area.innerHTML = '', 4000);
}

async function listCameras() {
    try {
        const devices = await QrScanner.listCameras(true);
        if (!els.deviceSelect) return;
        els.deviceSelect.innerHTML = '';
        devices.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.label || `Kamera ${els.deviceSelect.length + 1}`;
            els.deviceSelect.appendChild(opt);
        });
        const back = devices.find(d => /back|rück|rear|environment/i.test(d.label || '')) || devices.find(d => d.facingMode === 'environment');
        currentDeviceId = (back && back.id) || (devices[0] && devices[0].id) || null;
        if (currentDeviceId && els.deviceSelect) els.deviceSelect.value = currentDeviceId;
        if (els.deviceSelect) els.deviceSelect.disabled = devices.length < 2;
    } catch (e) {
        console.warn('listCameras failed', e);
    }
}

async function updateTorchCapability() {
    try {
        const track = els.video.srcObject?.getVideoTracks?.()[0];
        const capabilities = track?.getCapabilities?.();
        const canTorch = !!capabilities?.torch;
        if (els.torchBtn) els.torchBtn.hidden = !canTorch;
    } catch {
        if (els.torchBtn) els.torchBtn.hidden = true;
    }
}

async function initScanner() {
    if (!els.video) return;
    scanner = new QrScanner(els.video, onDecode, {
        highlightScanRegion: true,
        highlightCodeOutline: true,
        preferredCamera: 'environment'
    });

    await listCameras();
    await updateTorchCapability();
}

async function startScan() {
    if (!scanner) return;
    try {
        document.getElementById('scanner-fallback')?.classList.add('d-none');
        await scanner.start();
        if (currentDeviceId) await scanner.setCamera(currentDeviceId);
        if (els.startBtn) els.startBtn.disabled = true;
        if (els.stopBtn) els.stopBtn.disabled = false;
        await updateTorchCapability();
    } catch (e) {
        console.error('startScan', e);
        document.getElementById('scanner-fallback')?.classList.remove('d-none');
    }
}

function stopScan() {
    if (!scanner) return;
    scanner.stop();
    if (els.startBtn) els.startBtn.disabled = false;
    if (els.stopBtn) els.stopBtn.disabled = true;
}

async function onDecode(result) {
    const text = result?.data || result;
    if (!text) return;
    // prevent double handling
    if (text === lastCode) return;
    lastCode = text;
    stopScan();
    try {
        const data = await sendFind(text);
        if (!data.ok) {
            showAlert(data.error || 'Ticket nicht gefunden');
            setTimeout(() => { lastCode = null; startScan(); }, 1500);
            return;
        }
        renderResult(data);
    } catch (e) {
        console.error(e);
        showAlert('Server-Fehler');
        setTimeout(() => { lastCode = null; startScan(); }, 1500);
    }
}

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function sendFind(code) {
    const body = new URLSearchParams();
    body.append('code', code);
    const resp = await fetch('/payment/quick-checkin/find', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': getCsrf() },
        body
    });
    return await resp.json();
}

async function sendPunch(code) {
    const body = new URLSearchParams();
    body.append('code', code);
    const resp = await fetch('/payment/quick-checkin/punch', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': getCsrf() },
        body
    });
    return await resp.json();
}

function renderResult(data) {
    const card = document.getElementById('result');
    if (!card) return;
    card.classList.remove('d-none');
    document.getElementById('result-code').textContent = `Ticket: ${data.ticket.code}`;
    document.getElementById('result-state').textContent = `Status: ${data.ticket.state ?? 'UNKNOWN'}`;
    const ru = document.getElementById('result-user');
    if (data.ticket.redeemer) {
        const u = data.ticket.redeemer;
        ru.innerHTML = `<strong>${u.nickname ?? u.email}</strong> ${u.firstname || ''} ${u.surname || ''}`;
    } else {
        ru.innerHTML = `<em>Ticket nicht eingelöst</em>`;
    }
    const rs = document.getElementById('result-seats');
    rs.innerHTML = data.ticket.seats?.length ? `Sitzplatz: ${data.ticket.seats.join(', ')}` : '';
}

function resetResult() {
    document.getElementById('result')?.classList.add('d-none');
    document.getElementById('result-code').textContent = '';
    document.getElementById('result-state').textContent = '';
    document.getElementById('result-user').innerHTML = '';
    document.getElementById('result-seats').innerHTML = '';
    lastCode = null;
}

// wire UI
els.startBtn?.addEventListener('click', startScan);
els.stopBtn?.addEventListener('click', stopScan);
els.deviceSelect?.addEventListener('change', async (e) => {
    currentDeviceId = e.target.value;
    if (scanner && scanner.isScanning()) await scanner.setCamera(currentDeviceId);
});

els.torchBtn?.addEventListener('click', async () => {
    try {
        const on = !(await scanner.isTorchOn());
        await scanner.toggleTorch(on);
        els.torchBtn.textContent = on ? 'Licht aus' : 'Licht an';
    } catch (e) {
        console.warn('Torch nicht verfügbar', e);
    }
});

els.fileInput?.addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
        const text = await QrScanner.scanImage(file, { returnDetailedScanResult: false });
        const data = await sendFind(text);
        if (!data.ok) return showAlert('Kein QR im Bild erkannt');
        renderResult(data);
    } catch (err) {
        showAlert('Fehler beim Verarbeiten des Bildes');
    } finally {
        e.target.value = '';
    }
});

els.checkinBtn?.addEventListener('click', async () => {
    if (!lastCode) return showAlert('Kein Code verfügbar');
    const res = await sendPunch(lastCode);
    if (res.ok) {
        showAlert('Check-In erfolgreich', 'success');
        resetResult();
        startScan();
    } else {
        showAlert('Check-In fehlgeschlagen: ' + (res.error || res.message || 'unknown'));
    }
});

els.scanNextBtn?.addEventListener('click', () => {
    resetResult();
    startScan();
});

document.addEventListener('DOMContentLoaded', () => {
    initScanner();
});
