// The testable half of the Secrets screen (no DOM globals, only
// form.querySelector): encrypt the typed value in the browser, then let htmx
// POST only the ciphertext. The plaintext input has no `name`, so it is never
// part of any request, and it is cleared as soon as the ciphertext exists.
import { encryptSecret } from "./secrets-crypto.mjs";

export async function encryptForm(form, htmxApi) {
  const plain = form.querySelector("#secret-value");
  const armoredField = form.querySelector("#secret-armored");
  const hostKey = form.querySelector("#host-public-key");
  const status = form.querySelector("#secret-client-error");

  status.hidden = true;
  status.textContent = "";
  armoredField.value = "";
  try {
    if (!hostKey) {
      throw new Error("The host public key is not available on this page.");
    }
    const armored = await encryptSecret(hostKey.textContent.trim(), plain.value);
    armoredField.value = armored;
    plain.value = "";
    htmxApi.trigger(form, "fleet:encrypted");
    return true;
  } catch (err) {
    status.textContent = err.message;
    status.hidden = false;
    return false;
  }
}

// Reset the form only after a real 2xx. htmx's `detail.successful` is true for
// 4xx too (ui-errors.js clears isError so the error panel is swapped in), and
// a rejected submission must keep the user's input for correction.
export function shouldResetForm(detail) {
  const status = detail && detail.xhr ? detail.xhr.status : 0;
  return status >= 200 && status < 300;
}
