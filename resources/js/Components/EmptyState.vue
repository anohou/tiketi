<script setup>
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue';

defineProps({
  title: {
    type: String,
    required: true
  },
  message: {
    type: String,
    default: ''
  },
  icon: {
    type: [Object, Function],
    default: AlertCircle
  },
  actionText: {
    type: String,
    default: ''
  }
});

defineEmits(['action']);
</script>

<template>
  <div class="flex flex-col items-center justify-center text-center p-8 bg-white border border-dashed border-slate-200 rounded-2xl shadow-sm w-full dark:border-slate-700 dark:bg-slate-900">
    <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 shadow-inner dark:bg-slate-800 dark:text-slate-300">
      <component :is="icon" :size="36" />
    </div>
    <h3 class="text-base font-bold text-slate-800 mb-1 leading-snug dark:text-slate-100">{{ title }}</h3>
    <p v-if="message" class="text-xs text-slate-500 max-w-sm mb-5 leading-relaxed dark:text-slate-400">{{ message }}</p>
    <button
      v-if="actionText"
      @click="$emit('action')"
      class="inline-flex items-center justify-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-md active:scale-95 shrink-0"
    >
      {{ actionText }}
    </button>
  </div>
</template>
