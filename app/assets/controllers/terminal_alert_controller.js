import { Controller } from '@hotwired/stimulus';

// FLE-289: shows a toast for every terminal write sent over the API (the Mercure update carries ids and sizes, never the
// typed text). Administrators only (the template renders nothing for anyone else). Stays until dismissed; the audit view
// holds the record.
//   <div data-controller="terminal-alert" data-terminal-alert-mercure-value="<hub url>" data-terminal-alert-topic-value="/alerts/terminal-write" data-terminal-alert-audit-value="/admin/audit">
export default class extends Controller {
    static targets = ['list'];

    static values = { mercure: String, topic: String, audit: String };

    #source = null;

    connect() {
        if (this.mercureValue === '' || typeof EventSource === 'undefined') {
            return;
        }
        try {
            const hub = new URL(this.mercureValue, window.location.href);
            hub.searchParams.append('topic', this.topicValue);
            this.#source = new EventSource(hub, { withCredentials: true });
            this.#source.onmessage = (event) => this.#show(event.data);
            // A refused subscription (no cookie yet) closes the stream for good; the next page load tries again.
            this.#source.onerror = () => {
                if (this.#source?.readyState === EventSource.CLOSED) {
                    this.#source = null;
                }
            };
        } catch {
            this.#source = null;
        }
    }

    disconnect() {
        this.#source?.close();
        this.#source = null;
    }

    #show(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        const failed = data.outcome === 'failed';
        const toast = document.createElement('div');
        toast.className = 'rounded-md border border-transparent bg-signal-faded p-3 text-ink-body shadow-lg';
        toast.dataset.testid = 'terminal-alert-toast';

        const text = document.createElement('p');
        text.className = 'text-signal-darker dark:text-signal [overflow-wrap:anywhere]';
        const what = failed ? 'Terminal input may have been typed (the send failed)' : 'Terminal input was sent';
        text.textContent = `${what}: ${data.instance} ${data.pane}, ${data.length} bytes, by ${data.actor}.`;

        const actions = document.createElement('div');
        actions.className = 'mt-2 flex items-center gap-4';
        const link = document.createElement('a');
        link.className = 'inline-flex h-11 items-center underline md:h-8';
        link.href = this.auditValue;
        link.textContent = 'Read it in the audit view';
        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'inline-flex h-11 items-center underline md:h-8';
        dismiss.textContent = 'Dismiss';
        dismiss.addEventListener('click', () => toast.remove());
        actions.append(link, dismiss);

        toast.append(text, actions);
        this.listTarget.prepend(toast);
        while (this.listTarget.children.length > 5) {
            this.listTarget.lastElementChild.remove();
        }
    }
}
