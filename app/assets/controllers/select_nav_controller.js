import { Controller } from '@hotwired/stimulus';

// A <select> that navigates: each option's value is a path on this site, the change event visits it (Turbo Drive when
// it is loaded). The phone version of a tab strip.
//   <select data-controller="select-nav" data-action="change->select-nav#go">
export default class extends Controller {
    go(event) {
        const target = event.target.value;
        // Only a path on this site: "/x", never "//host" or a scheme.
        if (typeof target !== 'string' || !target.startsWith('/') || target.startsWith('//')) {
            return;
        }
        if (window.Turbo && typeof window.Turbo.visit === 'function') {
            window.Turbo.visit(target);
        } else {
            window.location.assign(target);
        }
    }
}
