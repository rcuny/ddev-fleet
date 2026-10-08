// Secrets screen wiring (browser only): intercepts the form submit, hands the
// form to encryptForm, and lets htmx POST the resulting ciphertext
// (the form has hx-post + hx-trigger="fleet:encrypted"). Event delegation, like
// bulk.js, so it keeps working after htmx swaps parts of the page.
import { encryptForm } from "./secrets-form.mjs";

document.addEventListener("submit", (evt) => {
  const form = evt.target;
  if (!form || form.id !== "secret-form") return;
  evt.preventDefault();
  void encryptForm(form, window.htmx);
});

document.addEventListener("change", (evt) => {
  const select = evt.target;
  if (select && select.id === "secrets-project" && select.form) {
    select.form.submit();
  }
});

document.addEventListener("htmx:afterRequest", (evt) => {
  const form = evt.target;
  if (!form || form.id !== "secret-form") return;
  const armoredField = form.querySelector("#secret-armored");
  if (armoredField) armoredField.value = "";
  if (evt.detail && evt.detail.successful) form.reset();
});
