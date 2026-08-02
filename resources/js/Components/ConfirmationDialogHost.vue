<script setup>
import { computed } from 'vue';
import DialogModal from '@/Components/DialogModal.vue';
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue';
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue';
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const toneConfig = computed(() => ({
  danger: {
    icon: DeleteOutline,
    iconClass: 'bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300',
    buttonClass: 'bg-rose-600 hover:bg-rose-700 focus:ring-rose-500',
  },
  warning: {
    icon: AlertOutline,
    iconClass: 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300',
    buttonClass: 'bg-amber-500 hover:bg-amber-600 focus:ring-amber-500',
  },
  success: {
    icon: CheckCircleOutline,
    iconClass: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    buttonClass: 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-500',
  },
}[confirmationStore.tone] || {}));
</script>

<template>
  <DialogModal :show="confirmationStore.show" maxWidth="md" @close="confirmationStore.cancel()">
    <template #title>
      <div class="flex items-center gap-3">
        <span :class="toneConfig.iconClass" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl">
          <component :is="toneConfig.icon" :size="23" />
        </span>
        <span class="text-lg font-black text-slate-900 dark:text-slate-100">{{ confirmationStore.title }}</span>
      </div>
    </template>
    <template #content>
      <p class="pl-14 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ confirmationStore.message }}</p>
    </template>
    <template #footer>
      <button type="button" @click="confirmationStore.cancel()" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
        {{ confirmationStore.cancelLabel }}
      </button>
      <button type="button" @click="confirmationStore.accept()" :class="toneConfig.buttonClass" class="ml-3 rounded-xl px-4 py-2 text-sm font-black text-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2">
        {{ confirmationStore.confirmLabel }}
      </button>
    </template>
  </DialogModal>
</template>
