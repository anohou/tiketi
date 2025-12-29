<template>
  <div>
    <div class="h-screen w-screen flex overflow-hidden bg-gray-50">
      <!-- Left Content Column -->
      <div class="flex-1 flex flex-col min-w-0 h-full">
        <!-- Top Header -->
        <header id="MainNav"
          class="h-[70px] bg-white shadow-sm border-b border-orange-200 flex items-center justify-between px-4 shrink-0 transition-all">
          
          <!-- Left: Logo & Context Title -->
          <div id="NavLeft" class="flex items-center gap-4 h-full">
            <Link :href="route('dashboard')" class="flex items-center gap-2 pr-4 lg:border-r border-orange-100 h-full">
              <Receipt class="text-green-700 font-bold" :size="32" />
              <div>
                <span class="font-black text-xl text-green-700">i-</span>
                <span class="font-black text-xl text-orange-500 uppercase tracking-tighter">Ticket</span>
              </div>
            </Link>
            
            <!-- Mobile Hamburger Menu (Main Nav) -->
            <button @click="isNavOpen = !isNavOpen" class="lg:hidden w-10 h-10 flex items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 transition-all">
                <MenuIcon :size="28" />
            </button>
          </div>

          <!-- Center: Desktop Navigation Icons (Restored) -->
          <div v-if="showNav" id="NavCenter" class="hidden lg:flex items-center justify-center gap-1 xl:gap-4 px-4 h-full">
            <Link v-for="item in navItems" :key="item.route" :href="route(item.route)" :class="[
              'flex flex-col items-center justify-center px-4 rounded-xl transition-all h-[56px] relative group',
              route().current(item.route)
                ? 'bg-orange-50/80 text-orange-700 shadow-sm'
                : 'text-gray-500 hover:bg-orange-50/40 hover:text-orange-600'
            ]">
              <component :is="item.icon" class="transition-transform group-hover:scale-110" :size="24"
                :fillColor="route().current(item.route) ? '#EA580C' : '#9CA3AF'" />
              <span class="text-[10px] font-bold mt-1 uppercase tracking-wider">
                {{ item.label }}
              </span>
              <div v-if="route().current(item.route)"
                class="absolute -bottom-[7px] left-2 right-2 border-b-4 border-green-600 rounded-full" />
            </Link>
          </div>

          <!-- Right: Utilities & User Profile -->
          <div class="flex items-center gap-2 lg:gap-4 h-full lg:pl-4 lg:border-l border-orange-100">
            <!-- Utility Area (Grouped) -->
            <div class="flex items-center gap-2 pr-4 lg:border-r border-orange-100 h-full">
                <!-- Mobile Trip Sidebar Toggle (Vue 360°) -->
                <button @click="isSidebarOpen = !isSidebarOpen" class="xl:hidden w-10 h-10 flex items-center justify-center rounded-xl bg-orange-600 text-white shadow-lg active:scale-95 transition-all">
                    <Bus :size="24" />
                </button>
                <!-- Optional Header Actions Slot -->
                <slot name="header-actions" />
                
                <!-- Desktop Help Button -->
                <button class="p-2 border rounded-full text-gray-500 border-gray-300 hover:bg-orange-50 transition-all hidden lg:flex items-center justify-center cursor-help" title="Aide">
                   <HelpCircleOutline :size="20" />
                </button>
            </div>


            <div class="flex items-center justify-center relative">
              <button @click="showMenu = !showMenu" class="flex items-center gap-2 bg-gray-50 p-1.5 pr-3 rounded-full border border-orange-200 hover:border-orange-300 hover:bg-orange-50 transition-all">
                <img class="rounded-full w-8 h-8 cursor-pointer border-2 border-orange-300 shadow-sm"
                   src="/images/blank.png" :alt="user.name">
                <span class="text-xs font-bold text-green-800 hidden lg:block">{{ user.name }}</span>
                <ChevronDown :size="16" class="text-gray-400 group-hover:rotate-180 transition-transform" />
              </button>
              
              <!-- User Menu Dropdown -->
              <div v-if="showMenu"
                class="absolute bg-white shadow-2xl top-12 right-0 w-[260px] rounded-2xl p-1.5 border border-orange-200 mt-2 z-[60] animate-in fade-in zoom-in duration-200">
                
                <!-- User Info Header -->
                <div class="px-4 py-3 border-b border-orange-100 mb-1">
                  <div class="font-bold text-gray-900 text-sm">{{ user.name }}</div>
                  <div class="text-xs text-gray-500">{{ user.email }}</div>
                  <div class="mt-2 flex items-center gap-2">
                    <span class="px-2 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full uppercase">
                      {{ user.role }}
                    </span>
                  </div>
                </div>

                <!-- Assigned Stations (for sellers) -->
                <div v-if="user.role === 'seller' && assignedStations.length > 0" class="px-2 py-2 border-b border-orange-100 mb-1">
                  <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2 px-2">Stations assignées</div>
                  <div v-for="station in assignedStations" :key="station.id" 
                       class="flex items-center gap-2 px-2 py-1.5 bg-green-50 rounded-lg border border-green-100 mb-1">
                    <MapMarker :size="14" class="text-green-600" />
                    <span class="text-xs font-bold text-green-700">{{ station.name }}</span>
                  </div>
                </div>
                <div v-else-if="user.role === 'seller'" class="px-2 py-2 border-b border-orange-100 mb-1">
                  <div class="flex items-center gap-2 px-2 py-1.5 bg-orange-50 rounded-lg border border-orange-100">
                    <MapMarker :size="14" class="text-orange-500" />
                    <span class="text-xs font-medium text-orange-600">Aucune station assignée</span>
                  </div>
                </div>

                <Link :href="route('profile.edit')" @click="showMenu = !showMenu">
                <div class="flex items-center gap-3 hover:bg-green-50 p-3 rounded-xl transition-colors">
                  <AccountCircle :size="22" class="text-green-600" />
                  <span class="text-gray-700 font-bold text-sm">Mon Profil</span>
                </div>
                </Link>

                <div class="h-px bg-orange-100 my-1 mx-2"></div>

                <Link class="w-full" :href="route('logout')" as="button" method="post" @click="showMenu = !showMenu">
                <div class="flex items-center gap-3 hover:bg-red-50 p-3 rounded-xl transition-colors text-red-600">
                  <Logout :size="22" />
                  <span class="font-bold text-sm">Déconnexion</span>
                </div>
                </Link>
              </div>
            </div>
          </div>
        </header>

        <!-- Main Scrollable Content -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden relative">
          <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto">
            <slot />
          </div>
        </main>
      </div>

      <!-- Main Navigation Mobile Sidebar -->
      <div v-if="isNavOpen" class="lg:hidden fixed inset-0 z-[110]" @click="isNavOpen = false">
          <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
          <div class="absolute inset-y-0 left-0 w-[280px] bg-white shadow-2xl transform transition-transform duration-300"
            :class="isNavOpen ? 'translate-x-0' : '-translate-x-full'"
            @click.stop>
              <div class="h-full flex flex-col">
                  <div class="p-4 border-b border-orange-100 flex items-center justify-between bg-white pt-6">
                        <div class="flex items-center gap-2">
                             <Receipt class="text-green-700 font-bold" :size="28" />
                             <span class="font-black text-xl text-green-700">i-Ticket</span>
                        </div>
                        <button @click="isNavOpen = false" class="p-2 hover:bg-gray-100 rounded-xl">
                            <Close :size="24" class="text-gray-400" />
                        </button>
                  </div>
                  
                  <div class="flex-1 overflow-y-auto p-4 space-y-2">
                      <Link v-for="item in navItems" :key="item.route" :href="route(item.route)" 
                          @click="isNavOpen = false"
                          :class="[
                              'flex items-center gap-4 p-3.5 rounded-2xl transition-all',
                              route().current(item.route)
                                  ? 'bg-green-50 text-green-700 font-black shadow-sm'
                                  : 'text-gray-600 hover:bg-orange-50/50 hover:text-orange-700'
                          ]"
                      >
                          <component :is="item.icon" :size="24"
                              :fillColor="route().current(item.route) ? '#15803d' : '#9CA3AF'" />
                          <span class="text-sm font-bold uppercase tracking-wider">{{ item.label }}</span>
                      </Link>

                      <div class="pt-6 mt-6 border-t border-orange-50 space-y-4">
                          <div class="px-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Utilitaires</div>

                          <button class="w-full flex items-center gap-4 p-3.5 rounded-2xl text-gray-600 hover:bg-orange-50/50">
                              <HelpCircleOutline :size="24" fillColor="#9CA3AF" />
                              <span class="text-sm font-bold uppercase tracking-wider">Aide</span>
                          </button>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <!-- Right Trip Sidebar Column (Persistent & Full Height) - Hidden for accountant/executive -->
      <aside v-if="showTripSidebar" class="hidden xl:block w-[320px] h-screen shrink-0 border-l border-orange-200 bg-white shadow-xl z-50">
        <TripSidebar />
      </aside>
      
      <!-- Mobile Trip Sidebar Overlay - Hidden for accountant/executive -->
      <div v-if="showTripSidebar && isSidebarOpen" class="xl:hidden fixed inset-0 z-[100]" @click="isSidebarOpen = false">
          <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
          <div class="absolute inset-y-0 right-0 w-[300px] bg-white shadow-2xl transform transition-transform duration-300" 
            :class="isSidebarOpen ? 'translate-x-0' : 'translate-x-full'"
            @click.stop>
              <div class="h-full flex flex-col">
                  <div class="p-4 border-b border-orange-100 flex items-center justify-between bg-green-50/30">
                      <span class="font-black text-green-800 uppercase tracking-tight">Vue 360° Voyages</span>
                      <button @click="isSidebarOpen = false" class="p-2 hover:bg-white rounded-xl shadow-sm">
                          <Close :size="24" class="text-gray-400" />
                      </button>
                  </div>
                  <div class="flex-1 overflow-hidden">
                      <TripSidebar class="border-l-0 w-full" />
                  </div>
              </div>
          </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

import Receipt from 'vue-material-design-icons/Receipt.vue';
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Logout from 'vue-material-design-icons/Logout.vue';
import MenuIcon from 'vue-material-design-icons/Menu.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';
import Bluetooth from 'vue-material-design-icons/Bluetooth.vue';
import MapMarker from 'vue-material-design-icons/MapMarker.vue';
import FileDocument from 'vue-material-design-icons/FileDocument.vue';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import TripSidebar from '@/Components/TripSidebar.vue';

const props = defineProps({
  showNav: {
    type: Boolean,
    default: true
  }
});

const showMenu = ref(false);
const isMenuOpen = ref(false);
const isSidebarOpen = ref(false);
const isNavOpen = ref(false);

const page = usePage();
const user = page.props.auth.user || {};

// Get assigned stations from page props (populated by HandleInertiaRequests middleware)
const assignedStations = computed(() => page.props.assignedStations || []);

// Should show trip sidebar? (Not for accountant, and not on admin parameter pages)
const showTripSidebar = computed(() => {
    if (user.role === 'accountant') return false;
    // Hide on admin parameter pages (URL starts with /admin/ and is not dashboard)
    if (user.role === 'admin') {
        const path = window.location.pathname;
        if (path.startsWith('/admin/') && path !== '/admin' && path !== '/admin/') {
            return false;
        }
    }
    return true;
});

// Navigation items based on user role
const navItems = computed(() => {
  const baseItems = [];
  
  // Customize for Seller AND Supervisor
  // Both roles want their Dashboard as Home screen
  if (['seller', 'supervisor'].includes(user.role)) {
      // Seller: Dashboard as home, Ticketing as secondary
      if (user.role === 'seller') {
          baseItems.push({
              route: 'seller.dashboard',
              label: 'Accueil',
              icon: HomeOutline
          });
          baseItems.push({
              route: 'seller.ticketing',
              label: 'Voyages',
              icon: Bus
          });
      } else if (user.role === 'supervisor') {
          // Supervisor: Dashboard (control tower) as home, Ticketing as secondary
          baseItems.push({
              route: 'supervisor.dashboard',
              label: 'Accueil',
              icon: HomeOutline
          });
          baseItems.push({
              route: 'supervisor.ticketing',
              label: 'Billetterie',
              icon: Bus
          });
      }
  } else if (user.role === 'accountant') {
      // Accountant navigation
      baseItems.push({
          route: 'accountant.reports',
          label: 'Rapports',
          icon: FileDocument
      });
  } else if (user.role === 'executive') {
      // Executive navigation
      baseItems.push({
          route: 'executive.analytics',
          label: 'Tableau de Bord',
          icon: ChartLine
      });
  } else {
      // Admin - Statistiques/Dashboard as home
      baseItems.push({
        route: 'admin.dashboard',
        label: 'Accueil',
        icon: HomeOutline
      });
  }

  // Add ticketing for Admin only (Supervisor/Seller has it in their menu)
  if (['admin'].includes(user.role)) {
    baseItems.push({
      route: 'seller.ticketing',
      label: 'Billetterie',
      icon: Ticket
    });
  }

  // Add accountant reports for Admin
  if (['admin'].includes(user.role)) {
    baseItems.push({
      route: 'accountant.reports',
      label: 'Comptabilité',
      icon: FileDocument
    });
  }

  // Add executive analytics for Admin
  if (['admin'].includes(user.role)) {
    baseItems.push({
      route: 'executive.analytics',
      label: 'Analytics',
      icon: ChartLine
    });
  }

  // Add settings menu only for admin
  if (['admin'].includes(user.role)) {
    baseItems.push({
      route: 'admin.settings.index',
      label: 'Paramétrage',
      icon: Settings
    });
  }

  return baseItems;
});
</script>