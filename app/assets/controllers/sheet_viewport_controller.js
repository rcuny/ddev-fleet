import { Controller } from '@hotwired/stimulus';

// Closes the phone "More" sheet whenever it can no longer be seen: the viewport grows to the desktop
// layout (rotation, window resize; the sheet root is md:hidden) or Turbo is about to cache the page.
// An open modal <dialog> that is display:none still makes the rest of the page inert, so it must be
// closed explicitly. Put it on an element inside the Sheet root.
//   <nav data-controller="sheet-viewport">
const DESKTOP = '(min-width: 48rem)'; // Tailwind's md breakpoint

export default class extends Controller {
    connect() {
        this.media = window.matchMedia(DESKTOP);
        this.onChange = (event) => {
            if (event.matches) {
                this.closeSheet();
            }
        };
        this.onBeforeCache = () => this.closeSheet();
        this.media.addEventListener('change', this.onChange);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        if (this.media.matches) {
            this.closeSheet();
        }
    }

    disconnect() {
        this.media.removeEventListener('change', this.onChange);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    closeSheet() {
        const root = this.element.closest('[data-slot="sheet"]');
        const dialog = root?.querySelector('dialog');
        if (!dialog?.open) {
            return;
        }
        // Immediate and not animated: the dialog is usually hidden already, so no transition would end.
        dialog.removeAttribute('data-closing');
        dialog.close();
        root.querySelector('[data-dialog-target="trigger"]')?.setAttribute('aria-expanded', 'false');
    }
}
