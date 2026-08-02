<script setup>
import { Link } from '@inertiajs/vue3';
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';

defineProps({
  alerts: {
    type: Array,
    default: () => [],
  },
});
</script>

<template>
  <div v-if="alerts.length" class="space-y-3">
    <div class="flex items-center gap-3">
      <div class="p-2 bg-amber-100 rounded-xl dark:bg-amber-900/25">
        <AlertOutline class="text-amber-600 dark:text-amber-400" :size="22" />
      </div>
      <div>
        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Points d'attention</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">Résolutions conseillées avant de lancer le service</p>
      </div>
    </div>

    <div
      v-for="alert in alerts"
      :key="alert.id"
      class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20"
    >
      <div class="p-1.5 bg-white rounded-lg shrink-0 dark:bg-slate-900">
        <AlertOutline :size="20" class="text-amber-600 dark:text-amber-400" />
      </div>
      <div class="min-w-0 flex-1">
        <div class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ alert.title }}</div>
        <p class="text-xs text-amber-700 mt-0.5 dark:text-amber-300/90">{{ alert.message }}</p>
      </div>
      <Link
        v-if="alert.route"
        :href="route(alert.route)"
        class="inline-flex items-center gap-1 rounded-xl border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-700 transition-all hover:bg-amber-100 shrink-0 dark:border-amber-800 dark:bg-slate-900 dark:text-amber-300 dark:hover:bg-slate-800"
      >
        Résoudre
        <ChevronRight :size="14" />
      </Link>
    </div>
  </div>

  <div
    v-else
    class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
  >
    <div class="p-1.5 bg-white rounded-lg shrink-0 dark:bg-slate-900">
      <CheckCircle :size="20" class="text-emerald-600 dark:text-emerald-400" />
    </div>
    <div>
      <div class="text-sm font-bold text-emerald-900 dark:text-emerald-200">Aucun point d'attention</div>
      <p class="text-xs text-emerald-700 mt-0.5 dark:text-emerald-300/90">La configuration de votre plateforme ne signale aucun point à résoudre.</p>
    </div>
  </div>
</template>
