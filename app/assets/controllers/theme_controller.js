// symfony-design-system:start
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'fleet-theme';
const MODES = ['light', 'dark', 'system'];

function stored() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return MODES.includes(value) ? value : 'system';
    } catch (e) {
        return 'system';
    }
}

function resolve(mode) {
    return mode === 'system' ? window.matchMedia('(prefers-color-scheme: dark)').matches : mode === 'dark';
}

// Light/dark switch for the whole page: the class lives on <html>, the choice in localStorage.
//   <button data-controller="theme" data-action="theme#toggle">          flips light <-> dark
//   <button data-controller="theme" data-action="theme#set" data-theme-mode-param="system">
export default class extends Controller {
    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.onSystemChange = () => {
            if ('system' === stored()) {
                this.apply('system');
            }
        };
        this.media.addEventListener('change', this.onSystemChange);
        this.apply(stored());
    }

    disconnect() {
        this.media.removeEventListener('change', this.onSystemChange);
    }

    toggle() {
        this.save(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
    }

    set(event) {
        const mode = event.params.mode;
        if (MODES.includes(mode)) {
            this.save(mode);
        }
    }

    save(mode) {
        try {
            window.localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {
            // Storage can be blocked: the choice then lasts for this page only.
        }
        this.apply(mode);
    }

    apply(mode) {
        const dark = resolve(mode);
        document.documentElement.classList.toggle('dark', dark);
        if ('BUTTON' === this.element.tagName) {
            this.element.setAttribute('aria-pressed', dark ? 'true' : 'false');
        }
        this.element.querySelectorAll('[data-theme-mode]').forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.themeMode === mode ? 'true' : 'false');
        });
    }
}
// symfony-design-system:end
