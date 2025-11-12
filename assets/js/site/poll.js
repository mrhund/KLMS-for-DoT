class PollWidget {
    constructor(root) {
        this.root = root;
        this.fetchUrl = root.dataset.fetchUrl || '';
        this.voteUrl = root.dataset.voteUrl || '';
        this.element = document.createElement('div');
        this.element.className = 'poll-widget is-hidden';
        root.appendChild(this.element);
        this.state = null;
        this.isVoting = false;
        this.dismissStorageKey = 'pollWidgetDismissed';
        this.load();
    }

    async load() {
        if (!this.fetchUrl) {
            return;
        }

        try {
            const response = await fetch(this.fetchUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            if (!data) {
                return;
            }
            this.state = data;
            this.render();
        } catch (error) {
            // the widget should fail silently if the API is not available
        }
    }

    render() {
        if (!this.state || !this.state.poll) {
            this.element.classList.add('is-hidden');
            this.element.innerHTML = '';
            return;
        }

        const { poll, hasVoted, isClosed, results, isAuthenticated } = this.state;
        if (this.isDismissed(poll.id)) {
            this.element.classList.add('is-hidden');
            this.element.innerHTML = '';
            return;
        }
        const canVote = !poll.onlyRegistered || Boolean(isAuthenticated);

        const header = `
            <header>
                <div>Community Umfrage</div>
                <button type="button" class="poll-widget-close" aria-label="Umfrage schließen">&times;</button>
            </header>
        `;

        let body = '<div class="poll-body">';
        body += `<div class="poll-question">${this.escape(poll.question)}</div>`;

        if (hasVoted || isClosed) {
            body += this.renderResults(results);
        } else {
            body += this.renderOptions(poll, canVote);
        }

        const metaParts = [];
        if (poll.onlyRegistered) {
            metaParts.push('Nur angemeldete Nutzer können abstimmen');
        }
        if (results && typeof results.total === 'number') {
            metaParts.push(`${results.total} Stimme${results.total === 1 ? '' : 'n'}`);
        }

        if (metaParts.length) {
            body += `<div class="poll-meta">${metaParts.join('<br/>')}</div>`;
        }

        body += '</div>';

        this.element.innerHTML = header + body;
        this.element.classList.remove('is-hidden');
        this.registerCloseHandler();
        if (!hasVoted && !isClosed && canVote) {
            this.registerOptionHandlers();
        }
    }

    renderOptions(poll, canVote) {
        if (!Array.isArray(poll.options)) {
            return '';
        }

        const options = poll.options.map(option => {
            const disabledAttr = canVote ? '' : ' disabled="disabled"';
            const disabledClass = canVote ? '' : ' is-disabled';
            return `<button type="button" class="${disabledClass}" data-option-id="${option.id}"${disabledAttr}>${this.escape(option.label)}</button>`;
        }).join('');

        return `<div class="poll-options">${options}</div>`;
    }

    renderResults(results) {
        if (!results || !Array.isArray(results.options)) {
            return '<div class="poll-results">Keine Stimmen vorhanden.</div>';
        }

        const rows = results.options.map(option => {
            const width = Math.min(100, Math.max(0, option.percentage || 0));
            const percentageLabel = this.formatPercentage(option.percentage);
            const classes = ['poll-result-row'];
            if (option.isSelected) {
                classes.push('is-selected');
            }
            return `
                <div class="${classes.join(' ')}">
                    <strong>
                        <span>${this.escape(option.label)}</span>
                        <span>${option.votes} (${percentageLabel}%)</span>
                    </strong>
                    <div class="poll-result-bar"><span style="width: ${width}%"></span></div>
                </div>
            `;
        }).join('');

        return `<div class="poll-results">${rows}</div>`;
    }

    registerOptionHandlers() {
        const buttons = this.element.querySelectorAll('button[data-option-id]');
        buttons.forEach(button => {
            button.addEventListener('click', () => this.vote(parseInt(button.dataset.optionId, 10)));
        });
    }

    registerCloseHandler() {
        const closeButton = this.element.querySelector('.poll-widget-close');
        if (!closeButton) {
            return;
        }

        closeButton.addEventListener('click', () => {
            this.dismiss();
        });
    }

    async vote(optionId) {
        if (this.isVoting || !this.voteUrl || !this.state || !this.state.poll) {
            return;
        }

        this.isVoting = true;
        try {
            const response = await fetch(this.voteUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    pollId: this.state.poll.id,
                    optionId
                })
            });

            if (!response.ok) {
                if (response.status === 403) {
                    alert('Bitte melde dich an, um abzustimmen.');
                } else if (response.status === 409) {
                    alert('Du hast bereits abgestimmt.');
                }
                return;
            }

            const data = await response.json();
            if (data) {
                this.state = data;
                this.render();
            }
        } catch (error) {
            alert('Die Stimme konnte nicht gespeichert werden. Bitte versuche es später erneut.');
        } finally {
            this.isVoting = false;
        }
    }

    formatDateRange(start, end) {
        const startFormatted = this.formatDate(start);
        if (!end) {
            return startFormatted;
        }

        const endFormatted = this.formatDate(end);
        if (startFormatted === endFormatted) {
            return startFormatted;
        }

        return `${startFormatted} – ${endFormatted}`;
    }

    formatDate(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    }

    formatPercentage(value) {
        const formatter = new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 1
        });

        return formatter.format(Number.isFinite(value) ? value : 0);
    }

    escape(text) {
        return String(text ?? '').replace(/[&<>'"]/g, match => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[match]);
    }

    dismiss() {
        const pollId = this.state && this.state.poll ? this.state.poll.id : null;
        this.storeDismissedPollId(pollId);
        this.element.classList.add('is-hidden');
        this.element.innerHTML = '';
    }

    isDismissed(pollId) {
        if (!pollId) {
            return false;
        }

        const dismissed = this.getDismissedPollIds();
        return dismissed.includes(pollId);
    }

    storeDismissedPollId(pollId) {
        if (!pollId) {
            return;
        }

        const dismissed = this.getDismissedPollIds();
        if (dismissed.includes(pollId)) {
            return;
        }

        dismissed.push(pollId);
        try {
            sessionStorage.setItem(this.dismissStorageKey, JSON.stringify(dismissed));
        } catch (error) {
            // ignore storage issues, dismissal is non-critical
        }
    }

    getDismissedPollIds() {
        try {
            const raw = sessionStorage.getItem(this.dismissStorageKey);
            if (!raw) {
                return [];
            }

            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }
}

function bootstrapPollWidget() {
    const root = document.getElementById('poll-widget-root');
    if (!root) {
        return;
    }

    new PollWidget(root);
}

document.addEventListener('DOMContentLoaded', bootstrapPollWidget);
