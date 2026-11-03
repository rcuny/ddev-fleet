import './stimulus_bootstrap.js';
import './styles/app.css';

// Turbo cancels a frame request when a newer refresh, a visit or a navigation replaces it, and a cancelled response body
// can still reject with an AbortError that nothing awaits. A cancelled request is not an error: keep it out of the
// console and the page error stream.
window.addEventListener('unhandledrejection', (event) => {
    if (event.reason?.name === 'AbortError') {
        event.preventDefault();
    }
});
