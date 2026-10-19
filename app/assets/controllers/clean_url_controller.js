import { Controller } from '@hotwired/stimulus';

// Replaces the current address (and history entry) with a clean one, so a one-time token in the query string does
// not stay in the address bar or the history.
//   <div data-controller="clean-url" data-clean-url-url-value="/setup" hidden></div>
export default class extends Controller {
    static values = { url: String };

    connect() {
        try {
            window.history.replaceState(window.history.state, '', this.urlValue);
        } catch (e) {
            // A browser that refuses (sandboxed frame): the page still works, the address just keeps its query.
        }
    }
}
