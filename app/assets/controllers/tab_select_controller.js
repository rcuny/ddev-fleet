import { Controller } from '@hotwired/stimulus';

// The phone version of a tab strip: a <select> whose option values are addresses. Choosing one goes there (through
// Turbo when it is loaded). Without script the page lists the same links in a <noscript> block.
//   <select data-controller="tab-select" data-action="change->tab-select#go">
export default class extends Controller {
    go() {
        const url = this.element.value;
        if (!url) {
            return;
        }
        if (window.Turbo && typeof window.Turbo.visit === 'function') {
            window.Turbo.visit(url);
        } else {
            window.location.assign(url);
        }
    }
}
