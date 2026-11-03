import { Controller } from '@hotwired/stimulus';

// Copies the value of a read-only field and says so.
//   <div data-controller="clipboard">
//     <input readonly value="..." data-clipboard-target="source">
//     <button type="button" data-action="clipboard#copy">Copy</button>
//     <span data-clipboard-target="status" aria-live="polite"></span>
//   </div>
export default class extends Controller {
    static targets = ['source', 'status'];

    static values = {
        done: { type: String, default: 'Copied' },
        failed: { type: String, default: 'Select the text and copy it' },
    };

    #timer = null;

    disconnect() {
        clearTimeout(this.#timer);
    }

    async copy(event) {
        event.preventDefault();
        const text = this.sourceTarget.value;
        let copied = false;
        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch (e) {
            // No clipboard access (insecure context, permission): select the text so the person can copy it.
            this.sourceTarget.focus();
            this.sourceTarget.select();
            try {
                copied = document.execCommand('copy');
            } catch (e2) {
                copied = false;
            }
        }
        this.announce(copied ? this.doneValue : this.failedValue);
    }

    announce(message) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.textContent = message;
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => {
            this.statusTarget.textContent = '';
        }, 3000);
    }
}
