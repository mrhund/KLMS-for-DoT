import QrScanner from 'qr-scanner';
import 'bootstrap/js/dist/modal';

// QR-Scanner Worker-Pfad setzen
QrScanner.WORKER_PATH = new URL('qr-scanner/qr-scanner-worker.min.js', import.meta.url).toString();

// DOM-Elemente
const video = document.getElementById('qr-video');
const deviceSelect = document.getElementById('device-select');

// Scanner-State
let scanner;
let lastCode;

// Scanner initialisieren und starten
async function initScanner() {
    if (!video) return;
    
    // Scanner erstellen
    scanner = new QrScanner(video, handleCode, {
        highlightScanRegion: true,
        highlightCodeOutline: true,
        preferredCamera: 'environment'
    });

    // Kamera-Auswahl
    const cameras = await QrScanner.listCameras(true);
    if (deviceSelect && cameras.length > 1) {
        deviceSelect.innerHTML = cameras
            .map(cam => `<option value="${cam.id}">${cam.label || 'Kamera'}</option>`)
            .join('');
        deviceSelect.onchange = () => scanner.setCamera(deviceSelect.value);
        deviceSelect.disabled = false;
    }

    scanner.start();
}

// QR-Code verarbeiten
async function handleCode(result) {
    const code = result?.data || result;
    if (!code || code === lastCode) return;
    
    lastCode = code;
    scanner.stop();

    try {
        // URL aus Template erstellen
        const template = document.getElementById('checkin-root')?.dataset?.showUrlTemplate 
            || '/payment/code/CODE';
        const url = template.replace('CODE', encodeURIComponent(code));
        
        // Modal öffnen
        await showModal(url);
    } catch {
        lastCode = null;
        scanner.start();
    }
}

// Modal anzeigen
async function showModal(url) {
    try {
        const res = await fetch(url);
        if (!res.ok) throw new Error();

        const container = document.querySelector('#ajaxModal') || 
            document.body.appendChild(document.createElement('div'));
        container.id = 'ajaxModal';
        container.innerHTML = await res.text();

        const modalElement = container.querySelector('.modal');
        if (!modalElement) {
            window.location.href = url;
            return;
        }

        const $ = window.jQuery;
        if (!($ && $.fn?.modal)) {
            window.location.href = url;
            return;
        }

        const $modal = $(modalElement);
        $modal.one('hidden.bs.modal', () => {
            lastCode = null;
            scanner?.start();
        });
        $modal.modal('show');
    } catch {
        window.location.href = url;
    }
}

// Start-Button Handler
document.getElementById('start-btn')?.addEventListener('click', () => scanner?.start());

// Automatisch starten
document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', initScanner)
    : initScanner();