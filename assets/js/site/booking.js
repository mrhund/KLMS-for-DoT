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
        this.registerResourceSelection();
        this.registerCancelHandlers();
    }

    registerResourceSelection() {
        const cards = this.root.querySelectorAll('.booking-resource-select');
        const panel = this.root.querySelector('#booking-selected-resource-panel');
        const slotsContainer = this.root.querySelector('#booking-selected-slots');
        const title = this.root.querySelector('#booking-selected-resource-title');

        if (!cards.length || !panel || !slotsContainer || !title) {
            return;
        }

        const selectCard = (card) => {
            cards.forEach((item) => item.classList.remove('is-selected'));
            card.classList.add('is-selected');

            const resourceId = card.dataset.resourceId;
            const resourceName = card.dataset.resourceName || 'Ressource';

            title.textContent = `Verfügbare Slots: ${resourceName}`;
            panel.classList.remove('d-none');
            slotsContainer.dataset.resourceId = resourceId;
            slotsContainer.innerHTML = '<p class="text-muted">Verfügbare Zeiten werden geladen...</p>';
            this.loadSlots(slotsContainer);
        };

        cards.forEach((card) => {
            card.addEventListener('click', () => selectCard(card));
            card.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectCard(card);
                }
            });
        });

        if (cards[0]) {
            selectCard(cards[0]);
        }
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
        if (!resourceId) {
            container.innerHTML = '<p class="text-muted">Wähle eine Ressource aus, um verfügbare Zeiten zu sehen.</p>';
            return;
        }

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
            const startTime = new Date(slot.start).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            const endTime = new Date(slot.end).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            const bufferText = slot.bufferMinutes > 0 ? ` + ${slot.bufferMinutes}m Puffer` : '';
            button.textContent = `${startTime} - ${endTime} (${slot.free}/${slot.total})${bufferText}`;
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
            await fetch(`/booking/${resourceId}/book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ start, csrfToken: this.csrfToken }),
            });
            window.location.reload();
        } catch (error) {
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
            await fetch(`/booking/reservation/${bookingId}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ csrfToken: this.csrfToken }),
            });

            window.location.reload();
        } catch (error) {
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
