<script setup>
defineProps({
  src: String,
  title: {
    type: String,
    default: 'Aperçu',
  },
});
</script>

<template>
  <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-800">
      <span class="h-2.5 w-2.5 rounded-full bg-rose-300"></span>
      <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
      <span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>
      <span class="ml-2 truncate text-[11px] font-bold text-slate-500 dark:text-slate-300">{{ title }}</span>
    </div>
    <div class="bg-slate-100 dark:bg-slate-950">
      <img
        v-if="src"
        :src="src"
        :alt="title"
        class="h-64 w-full object-contain bg-white md:h-72 dark:bg-slate-900"
      />
      <div v-else class="grid h-64 place-items-center bg-white md:h-72 dark:bg-slate-900">
        <div class="w-4/5 space-y-3">
          <div class="h-5 w-2/3 rounded bg-slate-200 dark:bg-slate-700"></div>
          <div class="grid grid-cols-3 gap-2">
            <div class="h-16 rounded border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800"></div>
            <div class="h-16 rounded border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800"></div>
            <div class="h-16 rounded border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800"></div>
          </div>
          <div class="h-3 rounded bg-slate-200 dark:bg-slate-700"></div>
          <div class="h-3 w-3/4 rounded bg-slate-200 dark:bg-slate-700"></div>
        </div>
      </div>
    </div>
  </div>
</template>
