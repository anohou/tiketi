<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import HelpPanel from '@/Components/HelpPanel.vue';
import { findHelpTopic } from '@/Support/helpContent.js';
import Earth from 'vue-material-design-icons/Earth.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Router from 'vue-material-design-icons/Router.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';

const page = usePage();
const { t } = useI18n();

const isHelpOpen = ref(false);
const currentRouteName = computed(() => {
  try {
    const current = route().current();
    return typeof current === 'string' ? current : null;
  } catch (error) {
    return null;
  }
});
const currentHelpTopic = computed(() => findHelpTopic({
  routeName: currentRouteName.value,
  path: typeof window !== 'undefined' ? window.location.pathname : '',
  role: page.props.auth.user?.role,
}));
const openHelp = () => {
  isHelpOpen.value = true;
};

const menuItems = computed(() => [
  {
    route: 'admin.stations.index',
    label: t('layout.stations_mobile'),
    icon: OfficeBuilding
  },
  {
    route: 'admin.routes.index',
    label: t('layout.routes'),
    icon: Router
  },
  {
    route: 'admin.vehicles.index',
    label: t('layout.vehicles'),
    icon: Bus
  },
  {
    route: 'admin.vehicle-types.index',
    label: t('layout.vehicle_types'),
    icon: Car
  },
  {
    route: 'admin.trips.index',
    label: t('layout.trips'),
    icon: Calendar
  },
  {
    route: 'admin.assignments.index',
    label: t('layout.assignments'),
    icon: AccountGroup
  }
]);
</script>
<template>
  <div class="min-h-screen bg-green-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <!-- Top Navigation Bar -->
    <div class="border-b border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo -->
          <div class="flex items-center">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
              <div class="flex items-center">
                <Earth class="text-green-700" :size="32"/>
                <div class="ml-2">
                  <span class="font-bold text-xl text-green-700">SysGe</span>
                  <span class="font-bold text-xl text-emerald-600">Trans</span>
                </div>
              </div>
            </Link>
          </div>

          <!-- Breadcrumb -->
          <div class="flex items-center">
            <nav class="flex" :aria-label="$t('layout.breadcrumb')">
              <ol class="inline-flex items-center space-x-1 md:space-x-3">
                <li class="inline-flex items-center">
                  <Link :href="route('dashboard')" class="inline-flex items-center text-sm font-medium text-green-700 hover:text-green-800">
                    <HomeOutline class="mr-2" :size="16"/>
                    {{ $t('layout.dashboard') }}
                  </Link>
                </li>
                <li>
                  <div class="flex items-center">
                    <ChevronRight class="text-green-400" :size="16"/>
                    <span class="ml-1 text-sm font-medium text-emerald-600 md:ml-2">{{ $t('layout.configurations') }}</span>
                  </div>
                </li>
              </ol>
            </nav>
          </div>

          <!-- User Menu -->
          <div class="flex items-center gap-3">
            <button
              type="button"
              @click="openHelp"
              class="p-2 rounded-full border border-slate-300 text-slate-500 hover:bg-slate-100 transition-all flex items-center justify-center cursor-help dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              :title="t('layout.help')"
            >
              <HelpCircleOutline :size="20" />
            </button>
            <ThemeToggle />
            <Dropdown align="right" width="48">
              <template #trigger>
                <span class="inline-flex rounded-md">
                  <button type="button" class="inline-flex items-center px-3 py-2 border border-slate-200 text-sm leading-4 font-medium rounded-md text-emerald-700 bg-white hover:text-emerald-800 hover:bg-emerald-50 focus:outline-none focus:bg-emerald-50 transition ease-in-out duration-150 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white dark:focus:bg-slate-800">
                    {{ $page.props.auth.user.name }}
                    <svg class="ml-2 -mr-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                  </button>
                </span>
              </template>

              <template #content>
                <DropdownLink :href="route('profile.edit')" class="text-green-700 hover:bg-green-50">
                  {{ $t('layout.profile') }}
                </DropdownLink>
                <DropdownLink :href="route('logout')" method="post" as="button" class="text-green-700 hover:bg-green-50">
                  {{ $t('layout.logout') }}
                </DropdownLink>
              </template>
            </Dropdown>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Area -->
    <div class="flex">
      <!-- Left Sidebar - Configuration Menu -->
      <div class="w-64 bg-white border-r border-slate-200 min-h-screen dark:border-slate-800 dark:bg-slate-900">
        <div class="p-4">
          <h2 class="text-lg font-semibold text-green-700 mb-4 flex items-center dark:text-emerald-300">
            <Settings class="mr-2" :size="24"/>
            {{ $t('layout.configurations') }}
          </h2>
          
          <nav class="space-y-2">
            <Link 
              v-for="item in menuItems" 
              :key="item.route"
              :href="route(item.route)"
              :class="[
                'flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                route().current(item.route)
                  ? 'bg-emerald-50 text-emerald-700 border-r-2 border-emerald-500'
                  : 'text-green-700 hover:bg-green-50 hover:text-green-800'
              ]"
            >
              <component :is="item.icon" class="mr-3" :size="20"/>
              {{ item.label }}
            </Link>
          </nav>
        </div>
      </div>

      <!-- Center - List/Content Area -->
      <div class="flex-1 bg-green-50 dark:bg-slate-950">
        <div class="p-6">
          <slot />
        </div>
      </div>

      <!-- Right - Form/Details Panel (if needed) -->
      <div v-if="$slots.sidebar" class="w-96 bg-white border-l border-slate-200 dark:border-slate-800 dark:bg-slate-900">
        <div class="p-6">
          <slot name="sidebar" />
        </div>
      </div>
    </div>

    <HelpPanel :show="isHelpOpen" :topic="currentHelpTopic" :role="page.props.auth.user?.role" @close="isHelpOpen = false" />
  </div>
</template>
