import { Controller } from '@hotwired/stimulus';

// Chunked, resumable file upload into a project's assets (the Assets tab).
//
//   <div data-controller="asset-upload"
//        data-asset-upload-url-value="/admin/projects/blog/assets/uploads"
//        data-asset-upload-csrf-value="..." data-asset-upload-path-value="dumps"
//        data-asset-upload-chunk-size-value="8388608">
//     <input type="file" multiple data-asset-upload-target="input" data-action="change->asset-upload#start">
//     <ul data-asset-upload-target="list"></ul>
//     <div id="asset-listing" data-asset-upload-target="listing">...</div>
//   </div>
//
// Per file: POST {url} (begin; the server answers the chunk size and which chunks it already holds, so choosing the
// same file again resumes), POST {url}/{id}/chunks/{n} for every missing chunk (the raw bytes), POST {url}/{id}/complete
// (the server assembles the file and answers its sha256). Files go one after the other. File names are only ever put
// in the page with textContent. The chunk size value may be lowered (tests do), never above what the server allows.
export default class extends Controller {
    static targets = ['input', 'list', 'listing'];

    static values = {
        url: String,
        csrf: String,
        path: { type: String, default: '' },
        chunkSize: { type: Number, default: 8388608 },
        maxBytes: { type: Number, default: 0 },
    };

    #queue = Promise.resolve();

    start() {
        const files = Array.from(this.inputTarget.files ?? []);
        // Clear the field so choosing the same file again (to resume) fires another change event.
        this.inputTarget.value = '';
        for (const file of files) {
            const item = this.#addItem(file.name);
            this.#queue = this.#queue.then(() => this.#upload(file, item));
        }
    }

    async #upload(file, item) {
        item.state('uploading', 'Starting...');
        try {
            let state = await this.#begin(file, false);
            if (state.conflict) {
                if (!window.confirm(`"${file.name}" already exists here. Replace it?`)) {
                    item.state('error', 'Skipped: a file with that name exists.');

                    return;
                }
                state = await this.#begin(file, true);
            }

            const done = new Set(state.received);
            let sent = 0;
            for (let index = 0; index < state.chunks; index++) {
                const from = index * state.chunkSize;
                const to = Math.min(from + state.chunkSize, file.size);
                if (!done.has(index)) {
                    await this.#sendChunk(state, index, file.slice(from, to));
                }
                sent += to - from;
                item.progress(sent / file.size);
                item.state('uploading', `Uploading ${index + 1} of ${state.chunks}...`);
            }

            item.state('uploading', 'Assembling...');
            const result = await this.#call('POST', `${this.urlValue}/${state.id}/complete`);
            if (!result.ok) {
                throw new Error(result.json?.error ?? `The server answered ${result.status}.`);
            }
            item.progress(1);
            item.done(result.json);
            await this.#refreshListing();
        } catch (error) {
            item.state('error', `${error.message} Choose the same file again to resume.`);
        }
    }

    async #begin(file, overwrite) {
        const response = await this.#call(
            'POST',
            this.urlValue,
            JSON.stringify({
                path: this.pathValue,
                name: file.name,
                size: file.size,
                chunkSize: this.chunkSizeValue,
                fingerprint: `${file.name}:${file.size}:${file.lastModified}`,
                overwrite,
            }),
            'application/json',
        );
        if (response.status === 409 && !overwrite) {
            return { conflict: true };
        }
        if (!response.ok) {
            throw new Error(response.json?.error ?? `The server answered ${response.status}.`);
        }

        return response.json;
    }

    async #sendChunk(state, index, blob) {
        let failure = null;
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt > 0) {
                await new Promise((resolve) => setTimeout(resolve, 500 * attempt));
            }
            try {
                const response = await this.#call('POST', `${this.urlValue}/${state.id}/chunks/${index}`, blob, 'application/octet-stream');
                if (response.ok) {
                    return;
                }
                failure = new Error(response.json?.error ?? `The server answered ${response.status}.`);
                // A refused chunk (4xx) will not get better by sending it again.
                if (response.status < 500) {
                    throw failure;
                }
            } catch (error) {
                if (error === failure) {
                    throw error;
                }
                failure = new Error('The connection was lost.');
            }
        }
        throw failure;
    }

    async #call(method, url, body = undefined, contentType = undefined) {
        const headers = { 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' };
        if (contentType) {
            headers['Content-Type'] = contentType;
        }
        const response = await fetch(url, { method, body, headers, credentials: 'same-origin' });
        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            json = null;
        }

        return { ok: response.ok, status: response.status, json };
    }

    // The listing is server-rendered and escaped; swap in a fresh copy of it without leaving the page.
    async #refreshListing() {
        if (!this.hasListingTarget) {
            return;
        }
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            const html = await response.text();
            const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(this.listingTarget.id);
            if (fresh) {
                this.listingTarget.innerHTML = fresh.innerHTML;
            }
        } catch (error) {
            // The listing stays as it was; Rescan reloads it.
        }
    }

    #addItem(name) {
        const li = document.createElement('li');
        li.className = 'grid gap-1 rounded-md border border-hairline p-3 text-sm';
        li.dataset.testid = 'asset-upload-item';

        const title = document.createElement('span');
        title.className = 'font-medium [overflow-wrap:anywhere]';
        title.textContent = name;

        const status = document.createElement('span');
        status.className = 'text-ink-muted';
        status.dataset.testid = 'asset-upload-status';

        const bar = document.createElement('progress');
        bar.max = 1;
        bar.value = 0;
        bar.className = 'h-2 w-full';
        bar.setAttribute('aria-label', `Upload progress of ${name}`);

        li.append(title, status, bar);
        this.listTarget.append(li);

        return {
            state: (state, text) => {
                li.dataset.state = state;
                status.textContent = text;
                status.className = state === 'error' ? 'text-signal-darker dark:text-signal' : 'text-ink-muted';
                if (state === 'error') {
                    status.setAttribute('role', 'alert');
                }
            },
            progress: (fraction) => {
                bar.value = fraction;
            },
            done: (result) => {
                li.dataset.state = 'done';
                status.textContent = `Uploaded, ${result.sizeLabel}.`;
                const sha = document.createElement('code');
                sha.className = 'text-xs [overflow-wrap:anywhere]';
                sha.dataset.testid = 'asset-sha256';
                sha.textContent = result.sha256;
                const label = document.createElement('span');
                label.className = 'text-xs text-ink-muted';
                label.textContent = 'sha256';
                bar.remove();
                li.append(label, sha);
            },
        };
    }
}
