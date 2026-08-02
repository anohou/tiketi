import { reactive } from 'vue';

let resolver = null;

export const confirmationStore = reactive({
  show: false,
  title: '',
  message: '',
  confirmLabel: 'Confirmer',
  cancelLabel: 'Annuler',
  tone: 'danger',

  confirm(options = {}) {
    const config = typeof options === 'string' ? { message: options } : options;

    if (resolver) resolver(false);

    this.title = config.title || 'Confirmer cette action';
    this.message = config.message || 'Souhaitez-vous continuer ?';
    this.confirmLabel = config.confirmLabel || 'Confirmer';
    this.cancelLabel = config.cancelLabel || 'Annuler';
    this.tone = config.tone || 'danger';
    this.show = true;

    return new Promise(resolve => { resolver = resolve; });
  },

  accept() {
    this.finish(true);
  },

  cancel() {
    this.finish(false);
  },

  finish(result) {
    this.show = false;
    const pendingResolver = resolver;
    resolver = null;
    if (pendingResolver) pendingResolver(result);
  },
});
