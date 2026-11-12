// Independent element: scanner selection and manual selection share native change events.
export class LocationWidget extends HTMLElement {
    connectedCallback() {
        this.storeInput = document.getElementById(this.dataset.storeInput || '');
        this.articleInput = document.getElementById(this.dataset.articleInput || '');
        this.label = this.querySelector('[data-location-label]');
        this.field = this.querySelector('[data-location-field]');
        this.button = this.querySelector('[data-location-save]');
        this.editor = this.querySelector('[data-location-editor]');
        this.feedback = this.querySelector('[data-location-feedback]');
        this.onChange = () => this.load();
        this.onSave = () => this.save();
        this.storeInput?.addEventListener('change', this.onChange);
        this.articleInput?.addEventListener('change', this.onChange);
        this.button?.addEventListener('click', this.onSave);
        this.load();
    }
    disconnectedCallback() {
        this.sequence = (this.sequence || 0) + 1;
        this.abort?.abort();
        this.storeInput?.removeEventListener('change', this.onChange);
        this.articleInput?.removeEventListener('change', this.onChange);
        this.button?.removeEventListener('click', this.onSave);
    }
    pair() { return [this.storeInput?.value || this.dataset.storeId || '', this.articleInput?.value || this.dataset.articleId || '']; }
    url(pair) { return `/api/v1/stores/${encodeURIComponent(pair[0])}/articles/${encodeURIComponent(pair[1])}/location`; }
    lock() { if (this.field) this.field.disabled = true; if (this.button) this.button.disabled = true; }
    render(data) {
        this.current = data;
        this.label.textContent = data.location || 'Non renseigné';
        const editable = this.dataset.editable === 'true' && data.can_edit === true;
        if (this.editor) this.editor.hidden = !editable;
        if (this.field) { this.field.value = data.location || ''; this.field.disabled = !editable; }
        if (this.button) this.button.disabled = !editable;
    }
    async load() {
        const sequence = this.sequence = (this.sequence || 0) + 1;
        this.abort?.abort(); this.abort = new AbortController();
        this.current = null; this.lock();
        if (this.editor) this.editor.hidden = true;
        this.feedback.textContent = '';
        const pair = this.pair();
        if (!pair[0] || !pair[1]) { this.label.textContent = 'Choisissez le magasin et l’article pour afficher le casage.'; return; }
        this.label.textContent = 'Chargement du casage…';
        try {
            const response = await fetch(this.url(pair), { credentials: 'same-origin', cache: 'no-store', signal: this.abort.signal });
            if (sequence !== this.sequence || !this.isConnected) return;
            if (!response.ok) throw new Error('Casage indisponible pour cette sélection.');
            const result = await response.json();
            if (sequence !== this.sequence || !this.isConnected) return;
            this.render(result.data);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== this.sequence || !this.isConnected) return;
            this.label.textContent = 'Casage indisponible. Vérifiez la sélection et rechargez la page.';
        }
    }
    async save() {
        if (!this.current?.can_edit || !this.field || this.button.disabled) return;
        const pair = this.pair(), sequence = this.sequence;
        const body = {location: this.field.value.trim() || null, version: this.current.version};
        this.lock(); this.feedback.textContent = 'Enregistrement du casage…';
        try {
            const csrfResponse = await fetch('/api/v1/auth/csrf', { credentials: 'same-origin', cache: 'no-store' });
            if (!csrfResponse.ok) throw new Error('Votre session a expiré. Reconnectez-vous.');
            const csrf = await csrfResponse.json();
            if (sequence !== this.sequence || !this.isConnected) return;
            const response = await fetch(this.url(pair), { method: 'PATCH', credentials: 'same-origin', headers: {'Content-Type':'application/json', 'X-CSRF-Token':csrf.token}, body: JSON.stringify(body) });
            const result = await response.json();
            if (sequence !== this.sequence || !this.isConnected) return;
            if (response.status === 409) { await this.load(); this.feedback.textContent = 'Le casage avait changé. La valeur actuelle a été rechargée.'; return; }
            if (!response.ok) throw new Error(result.detail || 'Impossible d’enregistrer le casage.');
            this.render(result.data); this.feedback.textContent = 'Casage enregistré pour ce magasin.';
        } catch (error) {
            if (sequence !== this.sequence || !this.isConnected) return;
            this.feedback.textContent = error.message || 'Impossible d’enregistrer le casage.';
            if (this.current?.can_edit) { this.field.disabled = false; this.button.disabled = false; }
        }
    }
}
if (!customElements.get('stock-location')) customElements.define('stock-location', LocationWidget);
