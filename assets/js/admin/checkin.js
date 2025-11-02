import QrScanner from 'qr-scanner';

// Use import.meta.url so Encore can resolve the worker path
QrScanner.WORKER_PATH = new URL('qr-scanner/qr-scanner-worker.min.js', import.meta.url).toString();

const els = {
    video: document.getElementById('qr-video'),
    startBtn: document.getElementById('start-btn'),
    deviceSelect: document.getElementById('device-select'),
    result: document.getElementById('result'),
    error: document.getElementById('result-msg')
};

let scanner;
let currentDeviceId = null;
let lastCode = null;
// fallback template used when no data-show-url-template is present on the page
// matches controller class route '/payment' + '/code/{code}'
let SHOW_URL_TEMPLATE = '/payment/code/CODE';

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

async function initScanner() {
    if (!els.video) return;
    scanner = new QrScanner(els.video, onDecode, {
        highlightScanRegion: true,
        highlightCodeOutline: true,
        preferredCamera: 'environment'
    });

    await listCameras();
}

async function startScan() {
    if (!scanner) return;
    try {
        document.getElementById('scanner-fallback')?.classList.add('d-none');
        await scanner.start();
        if (currentDeviceId) await scanner.setCamera(currentDeviceId);
        if (els.startBtn) els.startBtn.disabled = true;
        if (els.stopBtn) els.stopBtn.disabled = false;
    } catch (e) {
        console.error('startScan', e);
        document.getElementById('scanner-fallback')?.classList.remove('d-none');
    }
}

async function onDecode(result) {
    const text = result?.data || result;
    if (!text) return;
    // prevent double handling
    if (text === lastCode) return;
    lastCode = text;
    stopScan();
    try {
        const root = document.getElementById('checkin-root');
        const template = root?.dataset?.showUrlTemplate || SHOW_URL_TEMPLATE;
        // insert the scanned code into the URL template and open the existing show-modal
        const url = template.replace('CODE', encodeURIComponent(String(text)));
        const a = document.createElement('a');
        a.href = url;
        a.setAttribute('data-toggle', 'ajaxModal');
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        a.remove();

        // resume scanning when modal closed
        if (window.jQuery) {
            $(document).one('hidden.bs.modal', '#ajaxModal .modal', function() {
                lastCode = null;
                if (els.result) els.result.innerHTML = '';
                if (els.error) els.error.textContent = '';
                startScan();
            });
        } else {
            // fallback: resume after short delay
            setTimeout(() => { lastCode = null; startScan(); }, 2000);
        }
        return;
    } catch (e) {
        console.error(e);
        showAlert('Server-Fehler');
        setTimeout(() => { lastCode = null; startScan(); }, 1500);
    }
}

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}




// wire UI
els.startBtn?.addEventListener('click', startScan);
els.stopBtn?.addEventListener('click', stopScan);
els.deviceSelect?.addEventListener('change', async (e) => {
    currentDeviceId = e.target.value;
    if (scanner && scanner.isScanning()) await scanner.setCamera(currentDeviceId);
});