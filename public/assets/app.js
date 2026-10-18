import { Application, Controller } from './vendor/stimulus.js';
const application = Application.start();
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
