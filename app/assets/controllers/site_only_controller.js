import { Controller } from '@hotwired/stimulus';

// The "Site access only" checkbox of the user form: a site-only user has no email and is never an administrator, and
// its password only protects instance sites, so the email and administrator rows are hidden and the weak-password
// warning shown while the box is checked. The server enforces the same rules; this only keeps the form honest.
//   <form data-controller="site-only"> ... box / email / admin / warning targets ... </form>
export default class extends Controller {
    static targets = ['box', 'email', 'admin', 'warning'];

    connect() {
        this.toggle();
    }

    toggle() {
        const on = this.boxTarget.checked;
        this.emailTargets.forEach((row) => { row.hidden = on; });
        this.adminTargets.forEach((row) => { row.hidden = on; });
        this.warningTargets.forEach((row) => { row.hidden = !on; });
    }
}
