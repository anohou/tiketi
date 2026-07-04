import { reactive } from 'vue';

export const toastStore = reactive({
    toasts: [],
    add(message, type = 'success', duration = 3000) {
        const id = Date.now() + Math.random().toString(36).substr(2, 9);
        this.toasts.push({ id, message, type });
        setTimeout(() => this.remove(id), duration);
    },
    success(message, duration) {
        this.add(message, 'success', duration);
    },
    error(message, duration) {
        this.add(message, 'error', duration);
    },
    warning(message, duration) {
        this.add(message, 'warning', duration);
    },
    info(message, duration) {
        this.add(message, 'info', duration);
    },
    remove(id) {
        this.toasts = this.toasts.filter(t => t.id !== id);
    }
});
