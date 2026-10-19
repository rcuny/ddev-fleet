import { Controller } from '@hotwired/stimulus';

// The instance list: select-all and the bulk bar, and a refresh of the <turbo-frame id="instances"> when Mercure says
// something changed or every few seconds. The refresh is paused while a dialog or a menu is open or a field has focus,
// and the selection survives it.
//   <div data-controller="instance-list" data-instance-list-url-value="/instances" data-instance-list-mercure-value="<hub url>">
export default class extends Controller {
    static targets = ['form', 'bar', 'count', 'all', 'row', 'ids'];

    static values = {
        url: String,
        mercure: String,
        interval: { type: Number, default: 5000 },
    };

    #selected = new Set();
    #timer = null;
    #source = null;
    #wake = null;

    connect() {
        this.element.addEventListener('turbo:frame-load', this.#restore);
        this.#schedule();
        this.#subscribe();
        this.#restore();
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.#restore);
        clearInterval(this.#timer);
        clearTimeout(this.#wake);
        this.#source?.close();
    }

    toggle() {
        this.#selected = new Set(this.rowTargets.filter((box) => box.checked).map((box) => box.value));
        this.#sync();
    }

    toggleAll(event) {
        this.rowTargets.forEach((box) => {
            box.checked = event.target.checked;
        });
        this.toggle();
    }

    // Filter without the CSRF field in the address: the frame navigates to a clean URL.
    filter(event) {
        if (event.submitter && event.submitter.hasAttribute('formaction')) {
            return;
        }
        event.preventDefault();
        const params = new URLSearchParams();
        for (const name of ['q', 'project', 'state']) {
            const value = this.formTarget.elements[name]?.value ?? '';
            if (value !== '') {
                params.set(name, value);
            }
        }
        const query = params.toString();
        this.#frame().src = this.urlValue + (query === '' ? '' : '?' + query);
    }

    fillConfirm(event) {
        const dialog = event.currentTarget.closest('[data-controller~="dialog"]');
        const list = dialog?.querySelector('[data-instance-list-target="ids"]');
        if (!list) {
            return;
        }
        list.replaceChildren(
            ...[...this.#selected].map((id) => {
                const item = document.createElement('li');
                item.textContent = id;
                return item;
            }),
        );
    }

    #frame() {
        return this.element.querySelector('turbo-frame#instances');
    }

    #paused() {
        if (document.hidden || document.querySelector('dialog[open]')) {
            return true;
        }
        if (this.element.querySelector('[data-slot="dropdown-menu"][data-state="open"]')) {
            return true;
        }
        const active = document.activeElement;
        return !!active && this.element.contains(active) && ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName) && active.type !== 'checkbox';
    }

    refresh = () => {
        const frame = this.#frame();
        if (!frame || this.#paused()) {
            return;
        }
        const url = frame.src || this.urlValue + window.location.search;
        if (frame.src === url) {
            frame.reload();
        } else {
            frame.src = url;
        }
    };

    #schedule() {
        clearInterval(this.#timer);
        this.#timer = setInterval(this.refresh, this.#source ? this.intervalValue * 6 : this.intervalValue);
    }

    #subscribe() {
        if (this.mercureValue === '' || typeof EventSource === 'undefined') {
            return;
        }
        try {
            const hub = new URL(this.mercureValue, window.location.href);
            hub.searchParams.append('topic', '/instances');
            this.#source = new EventSource(hub, { withCredentials: true });
            this.#source.onmessage = () => {
                clearTimeout(this.#wake);
                this.#wake = setTimeout(this.refresh, 300);
            };
            this.#source.onerror = () => {
                this.#source?.close();
                this.#source = null;
                this.#schedule();
            };
            this.#schedule();
        } catch {
            this.#source = null;
        }
    }

    #restore = () => {
        this.rowTargets.forEach((box) => {
            box.checked = this.#selected.has(box.value);
        });
        // Instances that left the list are no longer selected.
        const present = new Set(this.rowTargets.map((box) => box.value));
        this.#selected = new Set([...this.#selected].filter((id) => present.has(id)));
        this.#sync();
    };

    #sync() {
        if (this.hasBarTarget) {
            this.barTarget.hidden = this.#selected.size === 0;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = this.#selected.size + ' selected';
        }
        if (this.hasAllTarget) {
            this.allTarget.checked = this.rowTargets.length > 0 && this.#selected.size === this.rowTargets.length;
        }
    }
}
