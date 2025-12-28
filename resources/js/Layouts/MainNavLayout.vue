<template>
  <div>
    <div id="MainNav"
      :class="[
        'fixed z-50 w-full flex items-center justify-between bg-green-50 shadow-xl border-b border-orange-200 transition-all',
        showNav ? 'h-[70px]' : 'h-[56px] px-2'
      ]">
      <!-- Left Section -->
      <div id="NavLeft" class="flex items-center justify-start">
        <template v-if="showNav">
          <Link :href="route('dashboard')" class="pl-4 flex items-center gap-2">
          <div class="flex items-center">
            <Earth class="text-green-700" :size="32" />
            <div class="ml-2">
              <span class="font-bold text-2xl text-green-700">i - </span>
              <span class="font-bold text-2xl text-orange-500 ml-0">Ticket</span>
            </div>
          </div>
          </Link>
          <button @click="isMenuOpen = !isMenuOpen" class="lg:hidden p-2 ml-4 hover:bg-green-100 rounded-lg">
            <Menu v-if="!isMenuOpen" class="text-green-700" :size="28" />
            <Close v-else class="text-green-700" :size="28" />
          </button>
        </template>
        <template v-else>
           <!-- Compact mode: logo + hamburger menu -->
           <Link :href="route('dashboard')" class="flex items-center gap-1.5 ml-2">
             <Earth class="text-green-700" :size="24" />
             <span class="font-bold text-lg text-green-700">i-</span>
             <span class="font-bold text-lg text-orange-500">Ticket</span>
           </Link>
           <button @click="isMenuOpen = !isMenuOpen" class="lg:hidden p-2 hover:bg-green-100 rounded-lg ml-auto mr-2">
             <Menu v-if="!isMenuOpen" class="text-green-700" :size="24" />
             <Close v-else class="text-green-700" :size="24" />
           </button>
        </template>
      </div>

      <!-- Center Section - Desktop Navigation -->
      <div v-if="showNav" id="NavCenter" class="hidden lg:flex items-center justify-center w-8/12 max-w-[600px] gap-2">
        <Link v-for="item in navItems" :key="item.route" :href="route(item.route)" :class="[
          'flex flex-col items-center justify-center w-full py-3 px-2 rounded-lg transition-colors h-[64px] relative',
          route().current(item.route)
            ? 'bg-orange-50'
            : 'hover:bg-orange-50/50'
        ]">
        <div class="flex flex-col items-center">
          <component :is="item.icon" class="mx-auto" :size="34"
            :fillColor="route().current(item.route) ? '#EA580C' : '#FB923C'" />
          <span :class="[
            'text-base font-medium mt-1.5',
            route().current(item.route) ? 'text-orange-700' : 'text-gray-600'
          ]">
            {{ item.label }}
          </span>
        </div>
        <div v-if="route().current(item.route)"
          class="absolute bottom-0 left-0 right-0 mx-2 border-b-4 border-green-600 rounded-md" />
        </Link>
      </div>

      <!-- Right Section -->
      <div class="flex items-center justify-end gap-3 mr-4">
        <!-- Optional Header Actions Slot -->
        <slot name="header-actions" />

        <div class="flex items-center justify-center relative">
          <button @click="showMenu = !showMenu">
            <img class="rounded-full min-w-[40px] max-h-[40px] cursor-pointer border-2 border-orange-300"
              src="images/blank.png" :alt="user.name">
          </button>
          <!-- User Menu Dropdown -->
          <div v-if="showMenu"
            class="absolute bg-green-50 shadow-xl top-10 right-0 w-[330px] rounded-lg p-1 border border-orange-200 mt-1">
            <Link :href="route('dashboard')" @click="showMenu = !showMenu">
            <div class="flex items-center gap-3 hover:bg-green-100 p-2 rounded-lg">
              <img class="rounded-full ml-1 min-w-[35px] max-h-[35px] cursor-pointer border-2 border-orange-300"
                src="images/blank.png" :alt="user.name">
              <span class="text-green-800">{{ user.name }}</span>
            </div>
            </Link>

            <Link class="w-full" :href="route('logout')" as="button" method="post" @click="showMenu = !showMenu">
            <div class="flex items-center gap-3 hover:bg-green-100 px-2 py-2.5 rounded-lg">
              <Logout class="pl-2" :size="30" fillColor="#EA580C" />
              <span class="text-green-800">Déconnexion</span>
            </div>
            </Link>
            <div class="text-xs font-semibold p-2 pt-3 border-t border-orange-200 mt-1 text-green-700">
              &copy; SysGeTrans {{ new Date().getFullYear() }} Ver.1.0.1
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile Menu Panel -->
    <div v-show="isMenuOpen" class="lg:hidden fixed inset-0 z-[100]" @click="isMenuOpen = false">
      <!-- Backdrop -->
      <div class="fixed inset-0 bg-black/30"></div>

      <!-- Menu Panel -->
      <div class="fixed inset-y-0 left-0 w-72 bg-green-50 shadow-lg transform transition-transform duration-300"
        :class="isMenuOpen ? 'translate-x-0' : '-translate-x-full'" @click.stop>
        <!-- Header Space -->
        <div class="h-[80px] flex items-center px-4 border-b border-orange-200">
          <span class="text-lg font-semibold text-green-700">Menu</span>
        </div>

        <!-- Menu Items -->
        <div class="p-4">
          <div class="space-y-2">
            <Link v-for="item in navItems" :key="item.route" :href="route(item.route)" :class="[
              'flex items-center p-3 rounded-lg transition-all relative',
              route().current(item.route)
                ? 'bg-orange-50'
                : 'hover:bg-orange-50/50'
            ]" @click="isMenuOpen = false">

            <component :is="item.icon" :size="28" :fillColor="route().current(item.route) ? '#EA580C' : '#FB923C'" />
            <span :class="[
              'ml-3 font-medium',
              route().current(item.route) ? 'text-orange-700' : 'text-gray-600'
            ]">

              {{ item.label }}

            </span>
            <div v-if="route().current(item.route)"
              class="absolute right-0 top-0 bottom-0 w-1 bg-green-600 rounded-l-md" />

            </Link>
          </div>
        </div>
      </div>
    </div>

    <div class="min-h-screen bg-green-50/10">
      <div :class="showNav ? 'pt-[70px]' : 'pt-[56px]'">
        <div class="max-w-[1920px] mx-auto">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import Close from 'vue-material-design-icons/Close.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import Earth from 'vue-material-design-icons/Earth.vue';
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue';
import Logout from 'vue-material-design-icons/Logout.vue';
import Menu from 'vue-material-design-icons/Menu.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';

const props = defineProps({
  showNav: {
    type: Boolean,
    default: true
  }
});

const showMenu = ref(false);
const isMenuOpen = ref(false);

const page = usePage();
const user = page.props.auth.user || {};

// Navigation items based on user role
const navItems = computed(() => {
  const baseItems = [
    {
      route: 'home',
      label: 'Accueil',
      icon: HomeOutline
    }
  ];

  // Add statistics menu for admin and supervisor
  if (['admin', 'supervisor'].includes(user.role)) {
    baseItems.push({
      route: 'admin.dashboard',
      label: 'Statistiques',
      icon: Settings
    });
  }

  // Add ticketing for all roles
  if (['admin', 'supervisor', 'seller'].includes(user.role)) {
    baseItems.push({
      route: 'seller.ticketing',
      label: 'Billetterie',
      icon: Ticket
    });
  }

  // Add settings menu only for admin
  if (['admin'].includes(user.role)) {
    baseItems.push({
      route: 'admin.stations.index',
      label: 'Paramétrage',
      icon: Settings
    });
  }

  return baseItems;
});
</script>