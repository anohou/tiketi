import { reactive } from 'vue';
import { i18n } from '@/i18n.js';

let resolver = null;

export const confirmationStore = reactive({
  show: false,
  title: '',
  message: '',
  confirmLabel: i18n.global.t('stores.confirm.confirm_label'),
  cancelLabel: i18n.global.t('stores.confirm.cancel_label'),
  tone: 'danger',

  confirm(options = {}) {
    const config = typeof options === 'string' ? { message: options } : options;

    if (resolver) resolver(false);

    this.title = config.title || i18n.global.t('stores.confirm.title');
    this.message = config.message || i18n.global.t('stores.confirm.message');
    this.confirmLabel = config.confirmLabel || i18n.global.t('stores.confirm.confirm_label');
    this.cancelLabel = config.cancelLabel || i18n.global.t('stores.confirm.cancel_label');
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
