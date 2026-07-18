<template>
  <div class="hidden md:block bg-white rounded-2xl border border-slate-200 shadow-sm p-2.5 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="text-base font-semibold text-slate-800 mb-2.5 dark:text-slate-100">{{ title }}</h2>
    <nav class="space-y-0.5">
      <Link
        v-for="item in items"
        :key="item.route"
        :href="route(item.route)"
        :class="[
          'flex items-center gap-3 px-3 py-2 text-sm rounded-xl transition-colors',
          route().current(item.route)
          ? 'bg-emerald-50 text-emerald-700 font-medium dark:bg-emerald-900/30 dark:text-emerald-300'
            : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-emerald-300'
        ]"
      >
        <component :is="item.icon" class="w-5 h-5 shrink-0" />
        <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
          <span class="leading-tight py-0.5">{{ item.name }}</span>
          <span
            v-if="item.count !== null && item.count !== undefined"
            class="inline-flex min-w-7 justify-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300 shrink-0"
          >
            {{ item.count }}
          </span>
        </div>
      </Link>
    </nav>
  </div>

  <div class="md:hidden bg-white rounded-2xl border border-slate-200 shadow-sm p-3 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="text-lg font-semibold text-slate-800 mb-3 dark:text-slate-100">{{ title }}</h2>
    <select
      v-model="selectedRoute"
      @change="navigateToRoute"
      class="w-full rounded-xl border-slate-200 focus:border-emerald-500 focus:ring focus:ring-emerald-200 focus:ring-opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
    >
      <option
        v-for="item in items"
        :key="item.route"
        :value="item.route"
        :selected="route().current(item.route)"
      >
        {{ item.name + (item.count !== null && item.count !== undefined ? ` (${item.count})` : '') }}
      </option>
    </select>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  items: {
    type: Array,
    default: () => [],
  },
});

const selectedRoute = ref('');

onMounted(() => {
  selectedRoute.value = props.items.find(item => route().current(item.route))?.route || props.items[0]?.route || '';
});

const navigateToRoute = () => {
  router.visit(route(selectedRoute.value));
};
</script>
