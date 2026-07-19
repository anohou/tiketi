<script setup>
defineProps({
  summary: {
    type: Object,
    default: () => ({}),
  },
  isPast: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['manage-connections']);
</script>

<template>
  <div
    v-if="summary?.outgoing?.total || summary?.incoming?.total || summary?.pool?.total"
    class="mt-3 grid gap-2 border-t border-dashed border-violet-200 pt-3 dark:border-violet-900/70 w-full"
  >
    <!-- 1. Pool de Transit en attente à cette gare (Affiché pour le trajet de correspondance) -->
    <div
      v-if="summary.pool?.total"
      class="rounded-xl border border-violet-200 bg-violet-50/80 px-3 py-2.5 dark:border-violet-900/50 dark:bg-violet-950/40 w-full flex flex-col gap-2 animate-fadeIn"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="text-[10px] font-black uppercase tracking-wider text-violet-750 dark:text-violet-300">
          Correspondances : {{ summary.pool.total }}
        </span>
        <button
          v-if="!isPast"
          @click="$emit('manage-connections')"
          class="shrink-0 px-2.5 py-1 bg-violet-600 hover:bg-violet-700 text-white font-bold text-[9px] rounded-lg transition-all active:scale-95 flex items-center gap-1 shadow-sm"
        >
          <span>Gérer les correspondances</span>
          <span>➡️</span>
        </button>
      </div>
      <div class="mt-1 flex flex-wrap gap-2 w-full">
        <div
          v-for="destination in summary.pool.destinations"
          :key="destination.id || destination.name"
          class="flex items-center justify-between gap-3 bg-white dark:bg-slate-950 border border-violet-200 dark:border-violet-800 px-3 py-1.5 rounded-xl shadow-sm min-w-[130px] flex-1 sm:flex-initial"
        >
          <span class="font-extrabold text-violet-750 dark:text-violet-300 text-[10px]">{{ destination.name }}</span>
          <span class="font-black text-rose-700 dark:text-rose-200 text-[11px] bg-rose-100 dark:bg-rose-900/70 px-2.5 py-0.5 rounded-full border border-rose-200 dark:border-rose-800/70">
            {{ destination.count }}
          </span>
        </div>
      </div>
    </div>

    <!-- 2. Outgoing Connections (Transit - Info pour trajet principal de départ) -->
    <div
      v-if="summary.outgoing?.total"
      class="rounded-xl border border-slate-250 bg-slate-50/90 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-950/20 w-full flex flex-col gap-2"
    >
      <div class="flex flex-wrap items-center justify-between gap-1">
        <span class="text-[10px] font-black uppercase tracking-wider text-slate-600 dark:text-slate-400">
          Correspondances : {{ summary.outgoing.total }}
        </span>
      </div>
      <div class="mt-1 flex flex-wrap gap-2 w-full">
        <div
          v-for="destination in summary.outgoing.destinations"
          :key="destination.id || destination.name"
          class="flex flex-col bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 px-3 py-1.5 rounded-xl shadow-sm min-w-[140px] flex-1 sm:flex-initial"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="font-extrabold text-slate-800 dark:text-slate-200 text-[10px]">{{ destination.name }}</span>
            <span class="font-black text-slate-700 dark:text-slate-200 text-[11px] bg-slate-100 dark:bg-slate-800 px-2.5 py-0.5 rounded-full border border-slate-200 dark:border-slate-700">
              {{ destination.count }}
            </span>
          </div>
          <!-- Compact Seat/Trip Details if assigned -->
          <div v-if="destination.assigned_trips?.length" class="mt-1 border-t border-slate-100 dark:border-slate-800 pt-1 text-[8px] text-slate-450 dark:text-slate-500 font-medium">
            <div v-for="tGroup in destination.assigned_trips" :key="tGroup.trip_code" class="flex items-center justify-between gap-1">
              <span>{{ tGroup.trip_code }}</span>
              <span>sièges {{ tGroup.seats.join(', ') }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. Incoming Connections (Déjà affectées à ce voyage) -->
    <div
      v-if="summary.incoming?.total"
      class="rounded-xl border border-blue-250 bg-blue-50/80 px-3 py-2.5 dark:border-blue-900/50 dark:bg-blue-950/20 w-full flex flex-col gap-2"
    >
      <div class="flex flex-wrap items-center justify-between gap-1">
        <span class="text-[10px] font-black uppercase tracking-wider text-blue-750 dark:text-blue-300">
          Correspondances : {{ summary.incoming.total }}
        </span>
      </div>
      <div class="mt-1 flex flex-wrap gap-2 w-full">
        <div
          v-for="destination in summary.incoming.destinations"
          :key="destination.id || destination.name"
          class="flex flex-col bg-white dark:bg-slate-950 border border-blue-200 dark:border-blue-800 px-3 py-1.5 rounded-xl shadow-sm min-w-[140px] flex-1 sm:flex-initial"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="font-extrabold text-blue-750 dark:text-blue-300 text-[10px]">{{ destination.name }}</span>
            <span class="font-black text-blue-700 dark:text-white text-[11px] bg-blue-100 dark:bg-blue-600 px-2.5 py-0.5 rounded-full border border-blue-200 dark:border-transparent">
              {{ destination.count }}
            </span>
          </div>
          <!-- Compact Seat/Trip Details if assigned -->
          <div v-if="destination.assigned_trips?.length" class="mt-1 border-t border-slate-100 dark:border-slate-800 pt-1 text-[8px] text-slate-400 dark:text-slate-500 font-medium">
            <div v-for="tGroup in destination.assigned_trips" :key="tGroup.trip_code" class="flex items-center justify-between gap-1">
              <span>{{ tGroup.trip_code }}</span>
              <span>sièges {{ tGroup.seats.join(', ') }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
