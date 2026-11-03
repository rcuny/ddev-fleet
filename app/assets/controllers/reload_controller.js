import { Controller } from '@hotwired/stimulus';

// A select whose choice changes the rest of the form (the templates of a project): reload the page with it.
//   <select data-controller="reload" data-action="change->reload#project" data-reload-url-value="/instances/new">
export default class extends Controller {
    static values = { url: String };

    project() {
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('project', this.element.value);
        if (window.Turbo) {
            window.Turbo.visit(url.toString());
        } else {
            window.location.assign(url.toString());
        }
    }
}
