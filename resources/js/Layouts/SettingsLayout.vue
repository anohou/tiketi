<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
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

const page = usePage();

const menuItems = computed(() => [
  {
    route: 'admin.stations.index',
    label: 'Gares',
    icon: OfficeBuilding
  },
  {
    route: 'admin.routes.index',
    label: 'Trajets',
    icon: Router
  },
  {
    route: 'admin.vehicles.index',
    label: 'Vehicles',
    icon: Bus
  },
  {
    route: 'admin.vehicle-types.index',
    label: 'Vehicle Types',
    icon: Car
  },
  {
    route: 'admin.trips.index',
    label: 'Trips',
    icon: Calendar
  },
  {
    route: 'admin.assignments.index',
    label: 'Assignments',
    icon: AccountGroup
  }
]);
</script>
<template>
  <div class="min-h-screen bg-green-50">
    <!-- Top Navigation Bar -->
    <div class="bg-white border-b border-orange-200 shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo -->
          <div class="flex items-center">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
              <div class="flex items-center">
                <Earth class="text-green-700" :size="32"/>
                <div class="ml-2">
                  <span class="font-bold text-xl text-green-700">SysGe</span>
                  <span class="font-bold text-xl text-orange-500">Trans</span>
                </div>
              </div>
            </Link>
          </div>

          <!-- Breadcrumb -->
          <div class="flex items-center">
            <nav class="flex" aria-label="Breadcrumb">
              <ol class="inline-flex items-center space-x-1 md:space-x-3">
                <li class="inline-flex items-center">
                  <Link :href="route('dashboard')" class="inline-flex items-center text-sm font-medium text-green-700 hover:text-green-800">
                    <HomeOutline class="mr-2" :size="16"/>
                    Dashboard
                  </Link>
                </li>
                <li>
                  <div class="flex items-center">
                    <ChevronRight class="text-green-400" :size="16"/>
                    <span class="ml-1 text-sm font-medium text-orange-600 md:ml-2">Configurations</span>
                  </div>
                </li>
              </ol>
            </nav>
          </div>

          <!-- User Menu -->
          <div class="flex items-center">
            <Dropdown align="right" width="48">
              <template #trigger>
                <span class="inline-flex rounded-md">
                  <button type="button" class="inline-flex items-center px-3 py-2 border border-orange-200 text-sm leading-4 font-medium rounded-md text-green-700 bg-white hover:text-green-800 hover:bg-green-50 focus:outline-none focus:bg-green-50 transition ease-in-out duration-150">
                    {{ $page.props.auth.user.name }}
                    <svg class="ml-2 -mr-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                  </button>
                </span>
              </template>

              <template #content>
                <DropdownLink :href="route('profile.edit')" class="text-green-700 hover:bg-green-50">
                  Profile
                </DropdownLink>
                <DropdownLink :href="route('logout')" method="post" as="button" class="text-green-700 hover:bg-green-50">
                  Log Out
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
      <div class="w-64 bg-white border-r border-orange-200 min-h-screen">
        <div class="p-4">
          <h2 class="text-lg font-semibold text-green-700 mb-4 flex items-center">
            <Settings class="mr-2" :size="24"/>
            Configurations
          </h2>
          
          <nav class="space-y-2">
            <Link 
              v-for="item in menuItems" 
              :key="item.route"
              :href="route(item.route)"
              :class="[
                'flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                route().current(item.route)
                  ? 'bg-orange-50 text-orange-700 border-r-2 border-orange-500'
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
      <div class="flex-1 bg-green-50">
        <div class="p-6">
          <slot />
        </div>
      </div>

      <!-- Right - Form/Details Panel (if needed) -->
      <div v-if="$slots.sidebar" class="w-96 bg-white border-l border-orange-200">
        <div class="p-6">
          <slot name="sidebar" />
        </div>
      </div>
    </div>
  </div>
</template>
