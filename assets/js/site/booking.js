/**
 * Booking Frontend JavaScript
 * Loads availability per resource and allows booking/cancelling slots via the JSON API.
 */

import '../../css/modules/booking.scss';

class BookingApp {
    constructor(root) {
        this.root = root;
        this.csrfTokenUrl = root.dataset.csrfTokenUrl || '';
        this.csrfToken = null;
        this.init();
    }

    async init() {
        await this.loadCsrfToken();
        this.root.querySelectorAll('.booking-slots').forEach((container) => this.loadSlots(container));
        this.registerCancelHandlers();
    }

    async loadCsrfToken() {
        if (!this.csrfTokenUrl) {
            return;
        }
        try {
            const response = await fetch(this.csrfTokenUrl, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                const data = await response.json();
                this.csrfToken = data?.token || null;
            }
        } catch (error) {
            // fail silently, booking actions will be rejected server-side without a valid token
        }
    }

    async loadSlots(container) {
        const resourceId = container.dataset.resourceId;
        try {
            const response = await fetch(`/booking/${resourceId}/availability`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                container.innerHTML = '<p class="text-muted">Keine Zeiten verfügbar.</p>';
                return;
            }
            const data = await response.json();
            this.renderSlots(container, resourceId, data?.items || []);
        } catch (error) {
            container.innerHTML = '<p class="text-danger">Zeiten konnten nicht geladen werden.</p>';
        }
    }

    renderSlots(container, resourceId, slots) {
        if (!slots.length) {
            container.innerHTML = '<p class="text-muted">Aktuell keine Zeiten verfügbar.</p>';
            return;
        }

        container.innerHTML = '';
        const list = document.createElement('div');
        list.className = 'booking-slot-list';

        slots.forEach((slot) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm booking-slot-btn ' + (slot.free > 0 ? 'btn-outline-success' : 'btn-outline-secondary');
            button.disabled = slot.free <= 0;
            const time = new Date(slot.start).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            button.textContent = `${time} (${slot.free}/${slot.total})`;
            button.addEventListener('click', () => this.book(resourceId, slot.start, button));
            list.appendChild(button);
        });

        container.appendChild(list);
    }

    async book(resourceId, start, button) {
        if (!this.csrfToken || button.disabled) {
            return;
        }
        button.disabled = true;

        try {
            const response = await fetch(`/booking/${resourceId}/book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ start, csrfToken: this.csrfToken }),
            });
            const data = await response.json().catch(() => null);

            if (!response.ok) {
                window.alert(data?.Error?.message || 'Buchung nicht möglich.');
                button.disabled = false;
                return;
            }

            window.location.reload();
        } catch (error) {
            window.alert('Buchung nicht möglich.');
            button.disabled = false;
        }
    }

    registerCancelHandlers() {
        this.root.querySelectorAll('.booking-cancel-btn').forEach((btn) => {
            btn.addEventListener('click', () => this.cancel(btn));
        });
    }

    async cancel(button) {
        if (!this.csrfToken) {
            return;
        }
        if (!window.confirm('Buchung wirklich stornieren?')) {
            return;
        }

        const bookingId = button.dataset.bookingId;
        button.disabled = true;

        try {
            const response = await fetch(`/booking/reservation/${bookingId}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ csrfToken: this.csrfToken }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => null);
                window.alert(data?.Error?.message || 'Stornierung nicht möglich.');
                button.disabled = false;
                return;
            }

            window.location.reload();
        } catch (error) {
            window.alert('Stornierung nicht möglich.');
            button.disabled = false;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('booking-app');
    if (root) {
        new BookingApp(root);
    }
});
