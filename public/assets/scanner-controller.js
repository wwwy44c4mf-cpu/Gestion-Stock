import { Controller } from './vendor/stimulus.js';
import { ScanCamera } from './scan-camera.js';

export default class extends Controller {
    static targets = ['dialog', 'video', 'status', 'previewStatus', 'code', 'start'];
    static values = { mode: String, select: String, quantity: String, textarea: String, returnUrl: String, csrf: String };
    connect() {
        this.sequence = 0;
        this.camera = new ScanCamera({
            video: this.videoTarget,
            mediaDevices: navigator.mediaDevices,
            createReader: async () => {
                const { BrowserMultiFormatReader } = await import('./vendor/zxing.js');
                return new BrowserMultiFormatReader(undefined, { delayBetweenScanAttempts: 150 });
            },
            onResult: code => { this.close(); this.accept(code); },
            onError: error => {
                this.close();
                this.message(error?.name === 'NotAllowedError'
                    ? 'Accès à la caméra refusé. Autorisez-le dans votre navigateur ou choisissez l’article manuellement.'
                    : 'Caméra indisponible. Fermez les autres applications qui l’utilisent, ou choisissez l’article manuellement.');
            },
        });
        this.hidden = () => { if (document.hidden) this.close(); };
        this.leaving = () => this.close();
        document.addEventListener('visibilitychange', this.hidden);
        window.addEventListener('pagehide', this.leaving);
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            this.startTarget.disabled = true;
            this.message('La lecture caméra demande un navigateur compatible et une connexion HTTPS. La saisie manuelle reste disponible.');
        }
    }
    disconnect() {
        this.sequence++;
        this.request?.abort();
        this.close();
        document.removeEventListener('visibilitychange', this.hidden);
        window.removeEventListener('pagehide', this.leaving);
    }
    open(event) {
        this.sequence++;
        this.request?.abort();
        this.trigger = event.currentTarget;
        this.previewStatusTarget.textContent = 'Autorisez la caméra, puis placez le code-barres ou QR code entièrement dans l’image.';
        this.dialogTarget.showModal();
        this.camera.start();
    }
    close() {
        this.camera?.stop();
        if (this.dialogTarget.open) this.dialogTarget.close();
        this.trigger?.focus();
    }
    cancel(event) { event.preventDefault(); this.close(); }
    message(text) { this.statusTarget.textContent = text; }
    manual(event) {
        if (event.type === 'keydown' && event.key !== 'Enter') return;
        event.preventDefault();
        this.close();
        this.accept(this.codeTarget.value);
    }
    async accept(raw) {
        const code = raw.trim();
        const sequence = ++this.sequence;
        this.request?.abort();
        if (!code || [...code].length > 512 || /[\x00-\x1f\x7f]/u.test(code)) {
            this.message('Ce code est vide, trop long ou contient des caractères non pris en charge. Choisissez l’article manuellement.'); return;
        }
        this.codeTarget.value = code;
        if (this.modeValue === 'register') {
            const textarea = document.getElementById(this.textareaValue);
            const existing = textarea.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean);
            if (existing.includes(code)) { this.message('Ce code est déjà présent dans la liste.'); return; }
            if (existing.length >= 50) { this.message('La limite de 50 codes par article est atteinte.'); return; }
            textarea.value = [...existing, code].join('\n');
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            this.message('Code ajouté à la liste. Enregistrez le formulaire pour conserver cette association.');
            textarea.focus(); return;
        }
        const select = this.hasSelectValue ? document.getElementById(this.selectValue) : null;
        const previous = select?.value;
        if (select) select.value = '';
        this.message('Recherche de l’article…');
        this.request = new AbortController();
        try {
            const response = await fetch('/api/v1/articles/resolve-code', {
                method: 'POST', credentials: 'same-origin', signal: this.request.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify({ code }),
            });
            const body = await response.json();
            if (sequence !== this.sequence || !this.element.isConnected) return;
            if (!response.ok) { this.message(body.detail || 'Code non reconnu. Choisissez l’article manuellement.'); return; }
            const article = body.data;
            if (this.modeValue === 'return') {
                const url = new URL(this.returnUrlValue, window.location.origin);
                url.searchParams.set('article_id', article.id);
                window.location.assign(url.href); return;
            }
            if ((this.modeValue !== 'deposit' && !article.active) || !Array.from(select.options).some(option => option.value === article.id)) {
                this.message('Cet article est désactivé ou indisponible pour cette opération.'); return;
            }
            select.value = article.id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            const quantity = document.getElementById(this.quantityValue);
            if (previous !== article.id) quantity.value = '';
            quantity.focus();
            this.message(`${article.sku} · ${article.name} sélectionné. Saisissez la quantité, puis confirmez le formulaire.`);
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === this.sequence) this.message('Recherche impossible. Vérifiez votre connexion ou choisissez l’article manuellement.');
        }
    }
}
