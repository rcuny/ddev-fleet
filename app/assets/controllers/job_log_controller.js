import { Controller } from '@hotwired/stimulus';

// The log of a job. Mercure only says "something changed"; the text always comes from the log endpoint, from the byte
// offset the last answer gave. Without Mercure (or after an error) it polls every 2 seconds. It stops when the job is
// done, and follows the end of the log unless the reader scrolled up.
//   <div data-controller="job-log" data-job-log-url-value="/jobs/7/log" data-job-log-mercure-url-value="<hub>"
//        data-job-log-topic-value="/jobs/7" data-job-log-status-value="running">
export default class extends Controller {
    static targets = ['output', 'indicator'];

    static values = {
        url: String,
        from: { type: Number, default: 0 },
        status: String,
        mercureUrl: String,
        topic: String,
        pollMs: { type: Number, default: 2000 },
    };

    #source = null;
    #timer = null;
    #busy = false;
    #again = false;
    #finished = false;
    #follow = true;

    connect() {
        this.outputTarget.addEventListener('scroll', this.#onScroll, { passive: true });
        this.#subscribe();
        this.#fetch();
    }

    disconnect() {
        this.outputTarget.removeEventListener('scroll', this.#onScroll);
        this.#stop();
    }

    #onScroll = () => {
        const box = this.outputTarget;
        this.#follow = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
    };

    #subscribe() {
        if (this.mercureUrlValue === '' || this.topicValue === '' || typeof EventSource === 'undefined') {
            this.#poll();
            return;
        }
        try {
            const hub = new URL(this.mercureUrlValue, window.location.href);
            hub.searchParams.append('topic', this.topicValue);
            this.#source = new EventSource(hub, { withCredentials: true });
            this.#source.onopen = () => this.#say('Live');
            this.#source.onmessage = () => this.#fetch();
            this.#source.onerror = () => {
                this.#source?.close();
                this.#source = null;
                this.#poll();
            };
            // A lost wake-up must not freeze the log.
            this.#timer = setInterval(() => this.#fetch(), this.pollMsValue * 5);
        } catch {
            this.#poll();
        }
    }

    #poll() {
        clearInterval(this.#timer);
        if (!this.#finished) {
            this.#say('Updating every 2 s');
            this.#timer = setInterval(() => this.#fetch(), this.pollMsValue);
        }
    }

    #stop() {
        this.#source?.close();
        this.#source = null;
        clearInterval(this.#timer);
        this.#timer = null;
    }

    #say(text) {
        if (this.hasIndicatorTarget && !this.#finished) {
            this.indicatorTarget.textContent = text;
        }
    }

    async #fetch() {
        if (this.#busy) {
            this.#again = true;
            return;
        }
        this.#busy = true;
        try {
            do {
                this.#again = false;
                const response = await fetch(this.urlValue + '?from=' + this.fromValue, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                if (data.text) {
                    this.outputTarget.append(document.createTextNode(data.text));
                    if (this.#follow) {
                        this.outputTarget.scrollTop = this.outputTarget.scrollHeight;
                    }
                }
                this.fromValue = data.next;
                if (data.done) {
                    this.#finished = true;
                    this.#stop();
                    this.indicatorTarget.textContent = 'Finished';
                    return;
                }
                if (data.next < data.size) {
                    this.#again = true;
                }
            } while (this.#again);
        } catch {
            // The next wake-up or poll tries again.
        } finally {
            this.#busy = false;
        }
    }
}
