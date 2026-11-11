import { Controller } from '@hotwired/stimulus';

// The "Adjust allotments" sheet of the capacity tab (FLE-318). Twig renders the empty sheet and one row <template>;
// this controller loads the allotments as JSON, keeps slider and number in sync, computes the totals and the donut
// (inline SVG, no dependency), applies the quick actions and saves every change in one request.
const SVG = 'http://www.w3.org/2000/svg';
const RADIUS = 40;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export default class extends Controller {
    static targets = [
        'scroll', 'donut', 'ring', 'centre', 'threadsLine', 'slotsLine', 'legend', 'banner', 'bannerTitle', 'errorBox',
        'errorText', 'prioProject', 'prioPct', 'status', 'rows', 'slices', 'changes', 'save', 'rowTemplate',
    ];
    static values = { url: String, saveUrl: String, csrf: String, autoload: Boolean };

    #data = null;
    #state = [];
    #frame = 0;
    #saving = false;
    #slotMax = 8;

    connect() {
        if (this.autoloadValue) {
            this.load();
        }
    }

    async load() {
        this.statusTarget.textContent = 'Loading…';
        this.statusTarget.classList.remove('hidden');
        this.#hideError();
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            this.#data = await response.json();
        } catch (error) {
            this.statusTarget.textContent = 'The allotments could not be loaded. Close this panel and try again.';
            return;
        }
        this.#state = this.#data.projects.map((project) => ({
            ...project,
            saved: { t: project.cpu_threads, s: project.slots },
            cur: { t: project.cpu_threads, s: project.slots },
        }));
        this.statusTarget.classList.add('hidden');
        // No host-wide slot total exists: the slider reaches twice the largest value in play (at least 8).
        this.#slotMax = Math.max(8, 2 * Math.max(0, ...this.#state.flatMap((p) => [p.saved.s ?? 0, p.floor.slots ?? 0])));
        this.#buildRows();
        this.#fillPriorities();
        const slices = this.#data.not_under_slice;
        this.slicesTarget.classList.toggle('hidden', slices < 1);
        this.slicesTarget.textContent = slices > 0
            ? `${slices} instance${1 === slices ? '' : 's'} not under a slice keep running without a CPU limit until redeployed or restarted.`
            : '';
        this.#refresh();
        this.rowsTarget.querySelector('[data-threads-number]')?.focus();
    }

    cancel() {
        if (this.#changeCount() > 0 && !window.confirm('Discard the unsaved changes?')) {
            return;
        }
        this.dispatch('close');
    }

    restore() {
        this.#state.forEach((p) => { p.cur = { ...p.saved }; });
        this.#syncRows();
        this.#refresh();
    }

    evenSplit() {
        const n = this.#state.length;
        if (n < 1) {
            return;
        }
        const each = Math.max(1, Math.floor(this.#data.allocatable_threads / n));
        this.#state.forEach((p) => { p.cur.t = each; });
        this.#syncRows();
        this.#refresh();
    }

    prioritise() {
        const chosen = this.#state.find((p) => p.name === this.prioProjectTarget.value);
        if (!chosen) {
            return;
        }
        const alloc = this.#data.allocatable_threads;
        const first = Math.max(1, Math.round(alloc * Number(this.prioPctTarget.value) / 100));
        const others = this.#state.filter((p) => p !== chosen);
        const weight = (p) => p.cur.t ?? alloc;
        const sum = others.reduce((acc, p) => acc + weight(p), 0);
        const remainder = Math.max(0, alloc - first);
        chosen.cur.t = first;
        others.forEach((p) => { p.cur.t = Math.max(1, Math.floor(sum > 0 ? remainder * weight(p) / sum : remainder / others.length)); });
        this.#syncRows();
        this.#refresh();
    }

    async save() {
        if (this.#saving) {
            return;
        }
        const changed = this.#state.filter((p) => this.#isChanged(p));
        if (changed.length < 1) {
            return;
        }
        this.#saving = true;
        this.saveTarget.disabled = true;
        this.#hideError();
        this.rowsTarget.querySelectorAll('[data-threads-error], [data-slots-error]').forEach((el) => { el.textContent = ''; });
        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify({ projects: changed.map((p) => ({ name: p.name, cpu_threads: p.cur.t, slots: p.cur.s })) }),
            });
            const body = await response.json().catch(() => ({}));
            if (response.ok && body.redirect) {
                window.location.assign(body.redirect);
                return;
            }
            if (422 === response.status && body.errors) {
                this.#showErrors(body.errors);
            } else {
                this.#showError(body.error || 'The allotments could not be saved. Reload the page and try again.');
            }
        } catch (error) {
            this.#showError('The allotments could not be saved. Check the connection and try again.');
        }
        this.#saving = false;
        this.#refresh();
    }

    // Rows ------------------------------------------------------------------------------------------------------

    #buildRows() {
        this.rowsTarget.replaceChildren();
        this.#state.forEach((project, index) => {
            const row = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
            project.row = row;
            const id = `allotments-${index}`;
            const colour = `var(--chart-${(project.colour_index % 8) + 1})`;
            row.style.setProperty('--swatch', colour);
            const swatch = row.querySelector('[data-swatch]');
            swatch.style.background = colour;
            swatch.textContent = this.#letter(project);
            row.querySelector('[data-name]').textContent = project.name;
            const label = row.querySelector('[data-threads-label]');
            const number = row.querySelector('[data-threads-number]');
            const range = row.querySelector('[data-threads-range]');
            label.setAttribute('for', `${id}-threads`);
            number.id = `${id}-threads`;
            number.max = String(this.#data.max);
            range.max = String(this.#data.allocatable_threads * 2);
            range.style.setProperty('--zone', `${50}%`);
            range.setAttribute('aria-labelledby', label.id = `${id}-threads-label`);
            row.querySelector('[data-slots-label]').id = `${id}-slots-label`;
            row.querySelector('[data-slots-number]').setAttribute('aria-labelledby', `${id}-slots-label`);
            number.setAttribute('aria-describedby', `${id}-hint`);
            row.querySelector('[data-threads-hint]').id = `${id}-hint`;

            range.addEventListener('input', () => { project.cur.t = 0 === Number(range.value) ? null : Number(range.value); this.#afterEdit(project); });
            number.addEventListener('input', () => {
                const v = number.value.trim();
                project.cur.t = '' === v || 0 === Number(v) ? null : Math.max(1, Math.round(Number(v)));
                this.#afterEdit(project, number);
            });
            const slots = row.querySelector('[data-slots-number]');
            const unlimited = row.querySelector('[data-slots-unlimited]');
            const slotsRange = row.querySelector('[data-slots-range]');
            slotsRange.max = String(this.#slotMax);
            slotsRange.style.setProperty('--zone', '100%');
            slotsRange.setAttribute('aria-labelledby', `${id}-slots-label`);
            slotsRange.addEventListener('input', () => { project.cur.s = Math.max(1, Number(slotsRange.value)); this.#afterEdit(project); });
            slots.addEventListener('input', () => { project.cur.s = Math.max(1, Math.round(Number(slots.value) || 1)); this.#afterEdit(project, slots); });
            row.querySelector('[data-slots-minus]').addEventListener('click', () => { project.cur.s = Math.max(1, (project.cur.s ?? 1) - 1); this.#afterEdit(project); });
            row.querySelector('[data-slots-plus]').addEventListener('click', () => { project.cur.s = Math.min(this.#data.max, (project.cur.s ?? 0) + 1); this.#afterEdit(project); });
            unlimited.addEventListener('change', () => { project.cur.s = unlimited.checked ? null : Math.max(1, project.saved.s ?? 1); this.#afterEdit(project); });
            row.querySelector('[data-reset]').addEventListener('click', () => { project.cur = { ...project.saved }; this.#afterEdit(project); });

            const list = row.querySelector('[data-templates-list]');
            const count = project.templates.length;
            row.querySelector('[data-templates-summary]').textContent = `${count} template${1 === count ? '' : 's'} (read-only)`;
            row.querySelector('[data-templates-box]').classList.toggle('hidden', 0 === count);
            project.templates.forEach((template) => {
                const item = document.createElement('li');
                item.textContent = `${template.name}: threads ${template.cpu_threads ?? 'inherited'}, slots ${template.slots ?? 'inherited'}`;
                list.append(item);
            });
            this.rowsTarget.append(row);
            this.#syncRow(project);
        });
    }

    #syncRows() {
        this.#state.forEach((p) => this.#syncRow(p));
    }

    #syncRow(project, except = null) {
        const row = project.row;
        if (!row) {
            return;
        }
        const alloc = this.#data.allocatable_threads;
        const number = row.querySelector('[data-threads-number]');
        const range = row.querySelector('[data-threads-range]');
        if (number !== except) {
            number.value = null === project.cur.t ? '' : String(project.cur.t);
        }
        number.placeholder = 'unlimited';
        range.value = String(null === project.cur.t ? 0 : Math.min(project.cur.t, alloc * 2));
        range.setAttribute('aria-valuetext', null === project.cur.t ? 'unlimited' : `${project.cur.t} threads of ${alloc}`);
        range.classList.toggle('opacity-50', null === project.cur.t);
        const beyond = null !== project.cur.t && project.cur.t > alloc;
        range.style.setProperty('--thumb', beyond ? 'var(--color-signal)' : 'var(--swatch)');
        number.classList.toggle('text-signal-darker', beyond);
        number.classList.toggle('dark:text-signal', beyond);
        number.classList.toggle('font-semibold', beyond);

        const slots = row.querySelector('[data-slots-number]');
        const unlimited = row.querySelector('[data-slots-unlimited]');
        unlimited.checked = null === project.cur.s;
        if (slots !== except) {
            slots.value = null === project.cur.s ? '' : String(project.cur.s);
        }
        slots.disabled = null === project.cur.s;
        const slotsRange = row.querySelector('[data-slots-range]');
        slotsRange.disabled = null === project.cur.s;
        slotsRange.value = String(Math.min(project.cur.s ?? 1, this.#slotMax));
        slotsRange.style.setProperty('--thumb', 'var(--swatch)');
        slotsRange.setAttribute('aria-valuetext', null === project.cur.s ? 'unlimited' : `${project.cur.s} slots`);
        row.querySelector('[data-slots-minus]').disabled = null === project.cur.s;
        row.querySelector('[data-slots-plus]').disabled = null === project.cur.s;

        // Hints: the saved value, the run-time cap and the floor set by a template (the server enforces it anyway).
        const parts = [];
        if (this.#isChanged(project)) {
            parts.push(`was ${this.#show(project.saved.t)} threads, ${this.#show(project.saved.s)} slots`);
        }
        if (null !== project.cur.t && project.cur.t > alloc) {
            parts.push(`⚠ above the ${alloc} allocatable threads: capped at ${alloc} at run time`);
        }
        row.querySelector('[data-threads-hint]').textContent = parts.join(' · ');
        const threadsLow = null !== project.cur.t && null !== project.floor.cpu_threads && project.cur.t < project.floor.cpu_threads;
        const slotsLow = null !== project.cur.s && null !== project.floor.slots && project.cur.s < project.floor.slots;
        number.classList.toggle('text-danger', threadsLow);
        slots.classList.toggle('text-danger', slotsLow);
        row.querySelector('[data-threads-error]').textContent = threadsLow ? `Minimum ${project.floor.cpu_threads} (template ${project.floor_template.cpu_threads}).` : '';
        row.querySelector('[data-slots-error]').textContent = slotsLow ? `Minimum ${project.floor.slots} (template ${project.floor_template.slots}).` : '';

        const changed = this.#isChanged(project);
        row.classList.toggle('bg-muted/40', changed);
        row.style.borderInlineStartColor = changed ? `var(--swatch)` : '';
        number.classList.toggle('font-medium', changed);
        row.querySelector('[data-reset]').classList.toggle('invisible', !changed);
    }

    #afterEdit(project, except = null) {
        this.#syncRow(project, except);
        this.#refresh();
    }

    // Totals, donut, counters -------------------------------------------------------------------------------------

    #refresh() {
        cancelAnimationFrame(this.#frame);
        this.#frame = requestAnimationFrame(() => this.#paint());
        this.#paint();
    }

    #paint() {
        if (!this.#data) {
            return;
        }
        const alloc = this.#data.allocatable_threads;
        let total = 0;
        let unlimitedThreads = 0;
        let slotsTotal = 0;
        let unlimitedSlots = 0;
        this.#state.forEach((p) => {
            if (null === p.cur.t) { unlimitedThreads += 1; total += alloc; } else { total += p.cur.t; }
            if (null === p.cur.s) { unlimitedSlots += 1; } else { slotsTotal += p.cur.s; }
        });
        const pct = alloc > 0 ? Math.round(total / alloc * 100) : 0;
        const over = total > alloc;
        this.threadsLineTarget.textContent = `Threads ${total} / ${alloc} allocatable (${pct}%)${unlimitedThreads ? `, ${unlimitedThreads} unlimited` : ''}`;
        this.slotsLineTarget.textContent = `Slots ${slotsTotal}${unlimitedSlots ? `, ${unlimitedSlots} unlimited` : ''}`;
        this.bannerTarget.classList.toggle('hidden', !over);
        this.bannerTitleTarget.textContent = `Over-allocated: ${total} of ${alloc} threads (${pct}%)`;
        this.#drawDonut(total, alloc, pct, over);

        const count = this.#changeCount();
        this.changesTarget.textContent = `${count} change${1 === count ? '' : 's'}`;
        this.saveTarget.disabled = 0 === count || this.#saving;
    }

    #drawDonut(total, alloc, pct, over) {
        const ring = this.ringTarget;
        ring.replaceChildren();
        const base = Math.max(total, alloc, 1);
        let offset = 0;
        const labels = [];
        const legend = [];
        const arc = (length, stroke, radius = RADIUS, width = 14, dashOffset = 0) => {
            const circle = document.createElementNS(SVG, 'circle');
            const circumference = 2 * Math.PI * radius;
            circle.setAttribute('cx', '50');
            circle.setAttribute('cy', '50');
            circle.setAttribute('r', String(radius));
            circle.setAttribute('fill', 'none');
            circle.setAttribute('stroke', stroke);
            circle.setAttribute('stroke-width', String(width));
            circle.setAttribute('stroke-dasharray', `${Math.max(0, length * circumference)} ${circumference}`);
            circle.setAttribute('stroke-dashoffset', String(-dashOffset * circumference));
            ring.append(circle);
            return circle;
        };
        this.#state.forEach((p) => {
            const value = p.cur.t ?? alloc;
            const share = value / base;
            if (share <= 0) {
                return;
            }
            const colour = `var(--chart-${(p.colour_index % 8) + 1})`;
            arc(share, colour, RADIUS, 14, offset);
            if (null === p.cur.t) {
                arc(share, 'url(#allotments-hatch)', RADIUS, 14, offset).setAttribute('opacity', '0.55');
            }
            labels.push({ letter: this.#letter(p), angle: (offset + share / 2) * 2 * Math.PI, share });
            legend.push(`${this.#letter(p)} ${p.name} ${null === p.cur.t ? 'unlimited' : `${p.cur.t} threads`} ${Math.round(share * 100)} %`);
            offset += share;
        });
        if (total < alloc) {
            arc((alloc - total) / base, 'var(--color-hairline)', RADIUS, 14, offset);
            legend.push(`free ${alloc - total} threads`);
        }
        if (over) {
            arc((total - alloc) / total, 'var(--color-danger)', 47, 4, 0);
        }
        // Letters sit outside the rotated group so they stay upright.
        this.donutTarget.querySelectorAll('[data-letter]').forEach((el) => el.remove());
        labels.forEach((label) => {
            if (label.share < 0.07) {
                return;
            }
            const text = document.createElementNS(SVG, 'text');
            const angle = label.angle - Math.PI / 2;
            text.setAttribute('x', String(50 + RADIUS * Math.cos(angle)));
            text.setAttribute('y', String(50 + RADIUS * Math.sin(angle)));
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('fill', '#fff');
            text.setAttribute('font-size', '8');
            text.setAttribute('font-weight', '700');
            text.setAttribute('data-letter', '');
            text.textContent = label.letter;
            this.donutTarget.append(text);
        });
        this.centreTarget.textContent = `${pct}%`;
        this.centreTarget.classList.toggle('fill-danger', over);
        this.donutTarget.setAttribute('aria-label', `Threads allotted ${total} of ${alloc} allocatable, ${pct} percent${over ? ', over-allocated' : ''}`);
        this.legendTarget.replaceChildren(...legend.map((text) => { const li = document.createElement('li'); li.textContent = text; return li; }));
    }

    // Helpers -----------------------------------------------------------------------------------------------------

    #fillPriorities() {
        this.prioProjectTarget.replaceChildren(...this.#state.map((p) => new Option(`${this.#letter(p)} ${p.name}`, p.name)));
    }

    #showErrors(errors) {
        let first = null;
        Object.entries(errors).forEach(([name, fields]) => {
            const project = this.#state.find((p) => p.name === name);
            if (!project) {
                return;
            }
            const t = project.row.querySelector('[data-threads-error]');
            const s = project.row.querySelector('[data-slots-error]');
            if (fields.cpu_threads) { t.textContent = fields.cpu_threads; }
            if (fields.slots) { s.textContent = fields.slots; }
            first ??= project.row;
        });
        this.#showError(`Nothing was saved: ${Object.keys(errors).length} project${1 === Object.keys(errors).length ? '' : 's'} need attention.`);
        first?.scrollIntoView({ block: 'center' });
    }

    #showError(message) {
        this.errorTextTarget.textContent = message;
        this.errorBoxTarget.classList.remove('hidden');
    }

    #hideError() {
        this.errorBoxTarget.classList.add('hidden');
    }

    #isChanged(p) {
        return p.cur.t !== p.saved.t || p.cur.s !== p.saved.s;
    }

    #changeCount() {
        return this.#state.filter((p) => this.#isChanged(p)).length;
    }

    #letter(project) {
        return String.fromCharCode(65 + (project.colour_index % 26));
    }

    #show(value) {
        return null === value ? 'unlimited' : String(value);
    }
}
