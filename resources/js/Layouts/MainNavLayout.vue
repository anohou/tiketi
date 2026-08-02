<template>
    <div>
    <div class="h-screen w-screen flex overflow-hidden bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
      <!-- Left Content Column -->
      <div class="flex-1 flex flex-col min-w-0 h-full">
        <!-- Top Header -->
        <header v-if="!focusMode" id="MainNav"
          :class="[
            'h-[70px] flex items-center justify-between px-4 shrink-0 transition-all shadow-sm border-b',
            isLandlord 
              ? 'bg-slate-900 border-slate-800 text-white' 
              : 'bg-white border-slate-200 dark:bg-slate-900 dark:border-slate-800'
          ]">

          <!-- Left: Logo & Context Title -->
          <div id="NavLeft" class="flex items-center gap-4 h-full">
            <Link :href="route('dashboard')" :class="['flex items-center gap-2 pr-4 lg:border-r h-full py-2', isLandlord ? 'border-slate-800' : 'border-slate-200 dark:border-slate-800']">
              <img :src="$page.props.tenant?.logo_url || (isDark ? '/images/logo-white.png' : '/images/logo.png')" alt="TIKÊTI Logo" class="h-10 w-auto object-contain" />
              <div v-if="isLandlord" class="hidden sm:flex flex-col ml-1">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] leading-none mb-1">Portail</span>
                <span class="text-xs font-black text-white uppercase tracking-wider leading-none">Central</span>
              </div>
              <div v-else-if="$page.props.tenant" class="hidden sm:flex flex-col ml-1">
                <span class="text-xs font-black text-emerald-700 uppercase tracking-wider leading-none">{{ $page.props.tenant.name }}</span>
              </div>
            </Link>

            <!-- Mobile Hamburger Menu (Main Nav) -->
            <button @click="isNavOpen = !isNavOpen" class="lg:hidden w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 transition-all dark:text-slate-300 dark:hover:bg-slate-800">
                <MenuIcon :size="28" />
            </button>
          </div>

          <!-- Center: Desktop Navigation Icons (Restored) -->
          <div v-if="showNav" id="NavCenter" class="hidden lg:flex items-center justify-center gap-1 xl:gap-4 px-4 h-full">
            <Link v-for="item in navItems" :key="item.route" :href="route(item.route)" :class="[
              'flex flex-col items-center justify-center px-4 rounded-xl transition-all h-[56px] relative group',
              isNavItemActive(item)
                ? 'bg-emerald-50/80 text-emerald-700 shadow-sm'
                : 'text-slate-500 hover:bg-emerald-50/40 hover:text-emerald-600'
            ]">
              <component :is="item.icon" class="transition-transform group-hover:scale-110" :size="24"
                :fillColor="isNavItemActive(item) ? '#059669' : '#64748b'" />
              <span class="text-[10px] font-bold mt-1 uppercase tracking-wider">
                {{ item.label }}
              </span>
              <div v-if="isNavItemActive(item)"
                class="absolute -bottom-[7px] left-2 right-2 border-b-4 border-emerald-600 rounded-full" />
            </Link>
          </div>

          <!-- Right: Utilities & User Profile -->
          <div :class="['flex items-center gap-2 lg:gap-4 h-full lg:pl-4 lg:border-l', isLandlord ? 'border-slate-800' : 'border-slate-200 dark:border-slate-800']">
            <!-- Utility Area (Grouped) -->
          <div :class="['flex items-center gap-2 pr-4 lg:border-r h-full', isLandlord ? 'border-slate-800' : 'border-slate-200 dark:border-slate-800']">
                <!-- Optional Header Actions Slot -->
                <slot name="header-actions" />

                <!-- Help Button (Desktop & Mobile) -->
                <button @click="openHelp" class="p-2 border rounded-full text-slate-500 border-slate-300 hover:bg-slate-100 transition-all flex items-center justify-center cursor-help dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" title="Aide">
                   <HelpCircleOutline :size="20" />
                </button>
            </div>


            <div ref="userMenuRef" class="flex items-center justify-center relative">
              <button @click="toggleUserMenu" class="flex items-center gap-2 bg-slate-50 p-1.5 pr-3 rounded-full border border-slate-200 hover:border-emerald-200 hover:bg-emerald-50 transition-all dark:border-slate-700 dark:bg-slate-900 dark:hover:border-emerald-700 dark:hover:bg-slate-800">
                <img class="rounded-full w-8 h-8 cursor-pointer border-2 border-slate-200 shadow-sm"
                   src="/images/blank.png" :alt="user.name">
                <span class="text-xs font-bold text-emerald-800 hidden lg:block dark:text-emerald-300">{{ user.name }}</span>
                <ChevronDown :size="16" class="text-slate-400 group-hover:rotate-180 transition-transform dark:text-slate-400" />
              </button>

              <!-- User Menu Dropdown -->
              <div v-if="showMenu"
                class="absolute bg-white shadow-2xl top-12 right-0 w-[260px] rounded-2xl p-1.5 border border-slate-200 mt-2 z-[60] animate-in fade-in zoom-in duration-200 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/40">

                <!-- User Info Header -->
                <div class="px-4 py-3 border-b border-slate-100 mb-1 dark:border-slate-800">
                  <div class="font-bold text-gray-900 text-sm dark:text-slate-100">{{ user.name }}</div>
                  <div class="text-xs text-gray-500 dark:text-slate-400">{{ user.email }}</div>
                  <div class="mt-2 flex items-center gap-2">
                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-full uppercase">
                      {{ user.role }}
                    </span>
                  </div>
                </div>

                <!-- Assigned Stations (for sellers) -->
                <div v-if="user.role === 'seller' && assignedStations.length > 0" class="px-2 py-2 border-b border-slate-100 mb-1 dark:border-slate-800">
                  <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-2 px-2 dark:text-slate-500">Stations assignées</div>
                  <div v-for="station in assignedStations" :key="station.id"
                       class="flex items-center gap-2 px-2 py-1.5 rounded-lg border mb-1"
                       :style="getStationBadgeStyle(station)">
                    <OfficeBuilding :size="14" />
                    <span class="text-xs font-bold">{{ station.name }}</span>
                  </div>
                </div>
                <div v-else-if="user.role === 'seller'" class="px-2 py-2 border-b border-slate-100 mb-1 dark:border-slate-800">
                  <div class="flex items-center gap-2 px-2 py-1.5 bg-slate-50 rounded-lg border border-slate-100 dark:border-slate-800 dark:bg-slate-800">
                    <OfficeBuilding :size="14" class="text-slate-500 dark:text-slate-400" />
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Aucune station assignée</span>
                  </div>
                </div>

                <button
                  type="button"
                  @click="toggleTheme(); showMenu = false"
                  class="w-full flex items-center gap-3 hover:bg-emerald-50 p-3 rounded-xl transition-colors dark:hover:bg-slate-800 text-left"
                >
                  <div class="text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <svg v-if="isDark" class="w-[22px] h-[22px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3v2.5M12 18.5V21M4.5 12H2M22 12h-2.5M6.4 6.4 4.6 4.6M19.4 19.4l-1.8-1.8M17.6 6.4l1.8-1.8M4.6 19.4l1.8-1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        <circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.8" />
                    </svg>
                    <svg v-else class="w-[22px] h-[22px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M21 12.4A8.5 8.5 0 1 1 11.6 3 7 7 0 0 0 21 12.4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                    </svg>
                  </div>
                  <span class="text-slate-700 font-bold text-sm dark:text-slate-100">
                    {{ isDark ? 'Mode Clair' : 'Mode Sombre' }}
                  </span>
                </button>

                <Link :href="route('profile.edit')" @click="showMenu = false">
                <div class="flex items-center gap-3 hover:bg-emerald-50 p-3 rounded-xl transition-colors dark:hover:bg-slate-800">
                  <AccountCircle :size="22" class="text-emerald-600 dark:text-emerald-400" />
                  <span class="text-slate-700 font-bold text-sm dark:text-slate-100">Mon Profil</span>
                </div>
                </Link>

                <div class="h-px bg-slate-100 my-1 mx-2 dark:bg-slate-800"></div>

                <Link class="w-full" :href="route('logout')" as="button" method="post" @click="showMenu = false">
                <div class="flex items-center gap-3 hover:bg-rose-50 p-3 rounded-xl transition-colors text-rose-600 dark:hover:bg-rose-500/10">
                  <Logout :size="22" />
                  <span class="font-bold text-sm">Déconnexion</span>
                </div>
                </Link>
              </div>
            </div>
          </div>
        </header>

        <!-- Main Scrollable Content -->
        <main :class="['flex-1 overflow-x-hidden relative', fullHeight ? 'overflow-hidden' : 'overflow-y-auto']">
          <div :class="[
              'mx-auto w-full',
              fullHeight ? 'h-full flex flex-col p-0 max-w-full' : 'p-4 md:p-6 lg:p-8 max-w-[1600px]'
          ]">
            <slot />
          </div>
        </main>
      </div>

      <!-- Main Navigation Mobile Sidebar -->
      <div v-if="isNavOpen" class="lg:hidden fixed inset-0 z-[110]" @click="isNavOpen = false">
          <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
          <div class="absolute inset-y-0 left-0 w-[280px] bg-white shadow-2xl transform transition-transform duration-300 dark:bg-slate-900"
            :class="isNavOpen ? 'translate-x-0' : '-translate-x-full'"
            @click.stop>
              <div class="h-full flex flex-col">
                  <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-white pt-6 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center gap-2">
                             <img :src="isDark ? '/images/logo-white.png' : '/images/logo.png'" alt="TIKÊTI Logo" class="h-8 w-auto object-contain" />
                        </div>
                        <button @click="isNavOpen = false" class="p-2 hover:bg-gray-100 rounded-xl dark:hover:bg-slate-800">
                            <Close :size="24" class="text-gray-400 dark:text-slate-400" />
                        </button>
                  </div>

                  <div class="flex-1 overflow-y-auto p-4 space-y-2">
                            <Link v-for="item in navItems" :key="item.route" :href="route(item.route)"
                          @click="isNavOpen = false"
                          :class="[
                              'flex items-center gap-4 p-3.5 rounded-2xl transition-all',
                              isNavItemActive(item)
                                  ? 'bg-emerald-50 text-emerald-700 font-black shadow-sm'
                                  : 'text-slate-600 hover:bg-slate-50 hover:text-slate-800'
                          ]"
                      >
                          <component :is="item.icon" :size="24"
                              :fillColor="isNavItemActive(item) ? '#059669' : '#64748b'" />
                          <span class="text-sm font-bold uppercase tracking-wider">{{ item.label }}</span>
                      </Link>

                      <div class="pt-6 mt-6 border-t border-slate-100 space-y-4 dark:border-slate-800">
                          <div class="px-4 text-[10px] font-black text-slate-400 uppercase tracking-widest dark:text-slate-500">Utilitaires</div>
 
                          <button @click="toggleTheme" class="w-full flex items-center gap-4 p-3.5 rounded-2xl text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 text-left">
                              <div class="text-[#64748b] dark:text-slate-350 flex items-center justify-center">
                                <svg v-if="isDark" class="w-[24px] h-[24px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 3v2.5M12 18.5V21M4.5 12H2M22 12h-2.5M6.4 6.4 4.6 4.6M19.4 19.4l-1.8-1.8M17.6 6.4l1.8-1.8M4.6 19.4l1.8-1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                    <circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.8" />
                                </svg>
                                <svg v-else class="w-[24px] h-[24px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M21 12.4A8.5 8.5 0 1 1 11.6 3 7 7 0 0 0 21 12.4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                                </svg>
                              </div>
                              <span class="text-sm font-bold uppercase tracking-wider">{{ isDark ? 'Mode Clair' : 'Mode Sombre' }}</span>
                          </button>

                          <button @click="openHelp" class="w-full flex items-center gap-4 p-3.5 rounded-2xl text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 text-left">
                              <HelpCircleOutline :size="24" fillColor="#64748b" />
                              <span class="text-sm font-bold uppercase tracking-wider">Aide</span>
                          </button>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <!-- Right Trip Sidebar Column (Persistent & Full Height) - Hidden for accountant/executive -->
      <aside
        v-if="showTripSidebar"
        :class="[
          'h-screen shrink-0 border-l border-slate-200 bg-white shadow-xl z-50 dark:border-slate-800 dark:bg-slate-900',
          focusMode ? 'block w-[420px] 2xl:w-[440px]' : 'hidden xl:block w-[320px]'
        ]"
      >
        <TripSidebar />
      </aside>

      <HelpPanel :show="isHelpOpen" :topic="currentHelpTopic" @close="isHelpOpen = false" />

      <!-- Mobile Trip Sidebar Overlay -->
      <div v-if="isSidebarOpen" class="xl:hidden fixed inset-0 z-[100]" @click="isSidebarOpen = false">
          <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
          <div class="absolute inset-y-0 right-0 w-[300px] bg-white shadow-2xl transform transition-transform duration-300 dark:bg-slate-900"
            :class="isSidebarOpen ? 'translate-x-0' : 'translate-x-full'"
            @click.stop>
              <div class="h-full flex flex-col">
                  <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-emerald-50/30 dark:border-slate-800 dark:bg-slate-900">
                      <span class="font-black text-emerald-800 uppercase tracking-tight dark:text-emerald-300">Vue 360° Voyages</span>
                      <button @click="isSidebarOpen = false" class="p-2 hover:bg-white rounded-xl shadow-sm dark:hover:bg-slate-800">
                          <Close :size="24" class="text-slate-400 dark:text-slate-300" />
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
  <ToastContainer />
  <ConfirmationDialogHost />
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import TripSidebar from '@/Components/TripSidebar.vue';
import HelpPanel from '@/Components/HelpPanel.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import ConfirmationDialogHost from '@/Components/ConfirmationDialogHost.vue';
import { useTheme } from '@/Composables/useTheme.js';
import { findHelpTopic } from '@/Support/helpContent.js';
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Close from 'vue-material-design-icons/Close.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue';
import Logout from 'vue-material-design-icons/Logout.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import MenuIcon from 'vue-material-design-icons/Menu.vue';
import Receipt from 'vue-material-design-icons/Receipt.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import FileDocument from 'vue-material-design-icons/FileDocument.vue';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';

const props = defineProps({
  showNav: {
    type: Boolean,
    default: true
  },
      fullHeight: {
        type: Boolean,
        default: false
      },
      hideTripSidebar: {
        type: Boolean,
        default: false
      },
      focusMode: {
        type: Boolean,
        default: false
      }
  });

const showMenu = ref(false);
const isMenuOpen = ref(false);
const isSidebarOpen = ref(false);
const isNavOpen = ref(false);
const isHelpOpen = ref(false);
const userMenuRef = ref(null);

const { isDark, toggleTheme } = useTheme();

const page = usePage();
const user = page.props.auth.user || {};

const isLandlord = computed(() => ['superadmin', 'super_admin'].includes(user.role));

// Get assigned stations from page props (populated by HandleInertiaRequests middleware)
const assignedStations = computed(() => page.props.assignedStations || []);

const currentRouteName = computed(() => {
  try {
    const current = route().current();
    if (typeof current === 'string') {
      return current;
    }
  } catch (error) {
    return null;
  }

  return null;
});

const currentHelpTopic = computed(() => findHelpTopic({
  routeName: currentRouteName.value,
  path: typeof window !== 'undefined' ? window.location.pathname : '',
  role: user.role,
}));

const openHelp = () => {
  isNavOpen.value = false;
  isHelpOpen.value = true;
};

const getStationBadgeStyle = (station) => {
  const settings = station?.settings || {};
  const explicitColor = settings.sale_color
    || settings.color
    || settings.badge_color
    || settings.ui_color
    || settings.station_color
    || null;

  const resolveTextColor = (backgroundColor) => {
    if (typeof backgroundColor !== 'string') return '#FFFFFF';
    const color = backgroundColor.trim().toLowerCase();
    const hexMatch = color.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (hexMatch) {
      const hex = hexMatch[1].length === 3
        ? hexMatch[1].split('').map((char) => char + char).join('')
        : hexMatch[1];
      const r = parseInt(hex.slice(0, 2), 16);
      const g = parseInt(hex.slice(2, 4), 16);
      const b = parseInt(hex.slice(4, 6), 16);
      const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
      return luminance > 0.62 ? '#0F172A' : '#FFFFFF';
    }

    const hslMatch = color.match(/^hsl\(\s*[\d.]+,\s*[\d.]+%?,\s*([\d.]+)%\s*\)$/i);
    if (hslMatch) {
      const lightness = Number.parseFloat(hslMatch[1]);
      if (! Number.isNaN(lightness)) {
        return lightness > 62 ? '#0F172A' : '#FFFFFF';
      }
    }

    return '#FFFFFF';
  };

  const seed = String(station?.id || station?.name || 'station')
    .split('')
    .reduce((sum, char) => sum + char.charCodeAt(0), 0);
  const bg = explicitColor || `hsl(${seed % 360}, 80%, 45%)`;

  return {
    backgroundColor: bg,
    color: resolveTextColor(bg),
    borderColor: bg,
  };
};

const toggleUserMenu = () => {
  showMenu.value = !showMenu.value;
};

const handleDocumentClick = (event) => {
  if (!showMenu.value) return;
  if (userMenuRef.value && !userMenuRef.value.contains(event.target)) {
    showMenu.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick);
});

// Should show trip sidebar? (Not for accountant, and not on admin parameter pages)
const showTripSidebar = computed(() => {
    if (props.hideTripSidebar) return false;

    // Hide sidebar for super admin (Landlord)
    if (['superadmin', 'super_admin'].includes(user.role)) return false;

    if (user.role === 'accountant') return false;
    if (user.role === 'fleet_manager') return false;
    
    // For admin and supervisor, only show on ticketing pages
    if (['admin', 'supervisor'].includes(user.role)) {
        const path = window.location.pathname;
        if (!path.startsWith('/seller/ticketing') && !path.startsWith('/supervisor/ticketing')) {
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
      // Logic split below to avoid duplicates


      // Secondary Menu
      if (user.role === 'seller') {
          baseItems.push({
              route: 'seller.dashboard',
              label: 'Accueil',
              icon: HomeOutline
          });
          baseItems.push({
              route: 'seller.ticketing',
              label: 'Billetterie',
              icon: Ticket,
              activePrefixes: ['seller.ticketing', 'seller.tickets', 'seller.okohi', 'seller.trips']
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
  } else if (user.role === 'fleet_manager') {
      baseItems.push({
          route: 'fleet.dashboard',
          label: 'Accueil',
          icon: HomeOutline
      });
      baseItems.push({
          route: 'fleet.vehicles.index',
          label: 'Véhicules',
          icon: Bus
      });
  } else if (['superadmin', 'super_admin'].includes(user.role)) {
      // Landlord (Superadmin) navigation
      baseItems.push({
          route: 'landlord.tenants.index',
          label: 'Accueil',
          icon: HomeOutline
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

  // Add settings menu for all authenticated tenant roles (read-only for non-admin)
  if (!['superadmin', 'super_admin'].includes(user.role)) {
    baseItems.push({
      route: user.role === 'seller' ? 'seller.settings.index' : 'settings.index',
      label: 'Paramétrage',
      icon: Settings,
      activePrefixes: user.role === 'seller' ? ['seller.settings'] : ['settings', 'admin.settings']
    });
  }

  return baseItems;
});

const isNavItemActive = (item) => {
  const current = route().current();
  if (item.activePrefixes) {
    return item.activePrefixes.some((prefix) => current === prefix || current.startsWith(prefix + '.'));
  }
  return current === item.route;
};
</script>
