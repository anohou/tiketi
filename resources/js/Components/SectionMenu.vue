<template>
  <div
    :class="[
      'hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 md:block',
      collapsible ? 'settings-section-menu' : '',
      collapsed ? 'settings-menu--collapsed p-2' : 'settings-menu--expanded p-2.5',
    ]"
  >
    <div
      :class="[
        'sticky top-0 z-20 mb-2.5 flex min-h-10 items-center bg-white dark:bg-slate-900',
        collapsed ? 'justify-center' : 'justify-between gap-2',
      ]"
    >
      <h2 v-if="!collapsed" class="pl-0.5 text-base font-semibold text-slate-800 dark:text-slate-100">{{ title }}</h2>
      <button
        v-if="collapsible"
        type="button"
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-emerald-700 dark:hover:bg-emerald-950/30 dark:hover:text-emerald-300"
        :title="collapsed ? 'Déployer le menu Paramètres' : 'Réduire le menu Paramètres'"
        :aria-label="collapsed ? 'Déployer le menu Paramètres' : 'Réduire le menu Paramètres'"
        :aria-expanded="!collapsed"
        @click="toggleCollapsed"
      >
        <ChevronRight v-if="collapsed" :size="20" />
        <ChevronLeft v-else :size="20" />
      </button>
    </div>
    <nav class="space-y-0.5">
      <Link
        v-for="item in items"
        :key="item.route"
        :href="route(item.route)"
        :class="[
          'flex items-center rounded-xl py-2 text-sm transition-colors',
          collapsed ? 'justify-center px-2' : 'gap-3 px-3',
          route().current(item.route)
          ? 'bg-emerald-50 text-emerald-700 font-medium dark:bg-emerald-900/30 dark:text-emerald-300'
            : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-emerald-300'
        ]"
        :title="collapsed ? item.name + (item.count !== null && item.count !== undefined ? ` (${item.count})` : '') : undefined"
        :aria-label="collapsed ? item.name : undefined"
      >
        <component :is="item.icon" class="w-5 h-5 shrink-0" />
        <div v-if="!collapsed" class="flex min-w-0 flex-1 items-center justify-between gap-3">
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
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  items: {
    type: Array,
    default: () => [],
  },
  collapsible: {
    type: Boolean,
    default: false,
  },
  storageKey: {
    type: String,
    default: 'tiketi.section-menu.collapsed',
  },
});

const selectedRoute = ref('');
const collapsed = ref(false);

onMounted(() => {
  selectedRoute.value = props.items.find(item => route().current(item.route))?.route || props.items[0]?.route || '';
  if (props.collapsible) {
    collapsed.value = window.localStorage.getItem(props.storageKey) === 'true';
  }
});

const navigateToRoute = () => {
  router.visit(route(selectedRoute.value));
};

const toggleCollapsed = () => {
  collapsed.value = !collapsed.value;
  window.localStorage.setItem(props.storageKey, String(collapsed.value));
};
</script>

<style>
@media (min-width: 768px) {
  .grid.grid-cols-12:has(> * .settings-section-menu) {
    grid-template-columns: minmax(17rem, 18rem) repeat(10, minmax(0, 1fr));
  }

  .grid.grid-cols-12:has(> * .settings-menu--collapsed) {
    grid-template-columns: 4.75rem repeat(10, minmax(0, 1fr));
  }

  .grid.grid-cols-12:has(> * .settings-section-menu) > :has(.settings-section-menu) {
    grid-column: span 1 / span 1 !important;
    min-width: 0;
  }

}
</style>
