<script setup>
import { toastStore } from '@/Stores/toastStore.js';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import Alert from 'vue-material-design-icons/Alert.vue';
import Close from 'vue-material-design-icons/Close.vue';
</script>

<template>
  <div class="fixed top-5 right-5 z-[2000] flex flex-col gap-3 w-full max-w-sm pointer-events-none">
    <TransitionGroup
      enter-active-class="transform ease-out duration-300 transition"
      enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
      enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
      leave-active-class="transition ease-in duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-for="toast in toastStore.toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-xl border border-slate-100 p-4 relative dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/30"
      >
        <div class="flex items-start gap-3 w-full">
          <div class="shrink-0 mt-0.5">
            <CheckCircle v-if="toast.type === 'success'" class="text-emerald-500" :size="20" />
            <AlertCircle v-else-if="toast.type === 'error'" class="text-rose-500" :size="20" />
            <Alert v-else-if="toast.type === 'warning'" class="text-amber-500" :size="20" />
            <AlertCircle v-else class="text-blue-500" :size="20" />
          </div>
          <div class="flex-1">
            <p class="text-sm font-semibold text-slate-800 leading-snug dark:text-slate-100">{{ toast.message }}</p>
          </div>
          <button @click="toastStore.remove(toast.id)" class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg shrink-0 dark:hover:text-slate-200">
            <Close :size="16" />
          </button>
        </div>
      </div>
    </TransitionGroup>
  </div>
</template>
