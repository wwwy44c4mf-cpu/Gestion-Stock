import { Application, Controller } from './vendor/stimulus.js';
import ScannerController from './scanner-controller.js?v=ec45348a56043617';
const application = Application.start();
application.register('scanner', ScannerController);
application.register('navigation', class extends Controller {
    static targets = ['menu', 'toggle'];
    connect() {
        this.media = window.matchMedia('(max-width: 760px)');
        this.resize = () => this.setOpen(!this.media.matches);
        this.media.addEventListener('change', this.resize);
        this.resize();
    }
    disconnect() { this.media.removeEventListener('change', this.resize); }
    toggle() { this.setOpen(this.menuTarget.hidden); }
    setOpen(open) {
        this.menuTarget.hidden = !open;
        this.toggleTarget.setAttribute('aria-expanded', String(open));
    }
});
application.register('confirmation', class extends Controller {
    static targets = ['dialog'];
    open(event) { this.trigger = event.currentTarget; this.dialogTarget.showModal(); }
    close() { this.dialogTarget.close(); this.trigger?.focus(); }
});
application.register('curatif-navigation', class extends Controller {
    connect() {
        this.selects = Array.from(this.element.querySelectorAll('#consumption-store, #deposit-store, #ca-store, #history-store'));
        this.update = () => {
            const global = this.element.querySelector('#magasin');
            const store = this.selects[0]?.value || global?.value || '';
            const options = Array.from(global?.options || []);
            const option = options.find(item => item.value === store);
            const visible = store === '' ? options.some(item => item.dataset.curatif === '1') : option?.dataset.curatif === '1';
            this.element.querySelectorAll('[data-ca-operation], [data-ca-group]').forEach(item => { item.hidden = !visible; });
        };
        this.selects.forEach(select => select.addEventListener('change', this.update));
        this.update();
    }
    disconnect() { this.selects.forEach(select => select.removeEventListener('change', this.update)); }
});
application.register('store-articles', class extends Controller {
    static targets = ['source', 'status'];
    static values = { store: String, select: String };
    connect() {
        this.store = document.getElementById(this.storeValue);
        this.select = document.getElementById(this.selectValue);
        this.pool = Array.from(this.sourceTarget.content.querySelectorAll('option'));
        this.submit = this.element.closest('form')?.querySelector('button[type="submit"]');
        this.locked = this.submit?.disabled === true;
        this.change = () => this.update(true);
        this.store?.addEventListener('change', this.change);
        this.update(false);
    }
    disconnect() { this.store?.removeEventListener('change', this.change); }
    update(changed) {
        if (!this.select) return;
        const store = this.store?.value || '', previous = this.select.value;
        const options = this.pool.filter(option => JSON.parse(option.dataset.stores).includes(store));
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = store ? 'Choisir un article de ce magasin' : 'Choisir d’abord un magasin';
        this.select.replaceChildren(placeholder, ...options.map(option => option.cloneNode(true)));
        this.select.value = options.some(option => option.value === previous) ? previous : '';
        this.select.disabled = store === '' || options.length === 0;
        if (this.submit) this.submit.disabled = this.locked || this.select.disabled;
        this.statusTarget.textContent = !store ? 'Choisissez le magasin pour afficher ses articles.' : options.length === 0 ? 'Aucun article référencé ici. Ajoutez-le par une entrée ou un retour.' : 'Articles de ce magasin, y compris ceux à zéro.';
        if (changed) {
            const quantity = this.element.closest('form')?.querySelector('[name="quantity"]');
            if (quantity) quantity.value = '';
            this.select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
});
