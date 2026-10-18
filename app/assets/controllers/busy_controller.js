import { Controller } from '@hotwired/stimulus';

// On a long-running form (Finish): once the form is sent, disable the button and show a progress note, so the
// person neither double-submits nor thinks the page froze.
//   <form data-controller="busy" data-action="submit->busy#start">
//     <button data-busy-target="button">Finish</button>
//     <p data-busy-target="note" hidden>Working...</p>
export default class extends Controller {
    static targets = ['button', 'note'];

    start() {
        // After the browser has built the form data: disabling a submitter earlier can drop the submission.
        setTimeout(() => {
            this.buttonTargets.forEach((button) => {
                button.disabled = true;
            });
            this.noteTargets.forEach((note) => {
                note.hidden = false;
            });
        }, 0);
    }
}
