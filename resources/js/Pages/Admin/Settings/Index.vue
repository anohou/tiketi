<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Router from 'vue-material-design-icons/Router.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import Cash from 'vue-material-design-icons/Cash.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue';
import AccountTie from 'vue-material-design-icons/AccountTie.vue';
import Sitemap from 'vue-material-design-icons/Sitemap.vue';
import Wallet from 'vue-material-design-icons/Wallet.vue';
import CellphoneLink from 'vue-material-design-icons/CellphoneLink.vue';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import CreditCard from 'vue-material-design-icons/CreditCard.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import FileDocument from 'vue-material-design-icons/FileDocument.vue';
import Domain from 'vue-material-design-icons/Domain.vue';
import TagHeart from 'vue-material-design-icons/TagHeart.vue';
import Clipboard from 'vue-material-design-icons/Clipboard.vue';
import GeneralInfoSection from '@/Components/Settings/GeneralInfoSection.vue';
import UserProfileSection from '@/Components/Settings/UserProfileSection.vue';
import ScopeSection from '@/Components/Settings/ScopeSection.vue';
import LoyaltyRewardsSection from '@/Components/Settings/LoyaltyRewardsSection.vue';

const props = defineProps({
  role: {
    type: String,
    default: '',
  },
  stats: {
    type: Object,
    default: () => ({
      stations: 0,
      routes: 0,
      destinations: 0,
      vehicles: 0,
      vehicleTypes: 0,
      trips: 0,
      fares: 0,
      users: 0,
      assignments: 0,
      crewMembers: 0,
      crewAssignments: 0,
    }),
  },
  operationalSettings: {
    type: Object,
    default: null,
  },
  profile: {
    type: Object,
    default: () => ({}),
  },
  company: {
    type: Object,
    default: () => ({}),
  },
  loyalty: {
    type: Object,
    default: () => ({ connected: false, parameters: null, rewards: [], error: null }),
  },
  scope: {
    type: Object,
    default: null,
  },
  directives: {
    type: Array,
    default: () => [],
  },
});

const isManager = computed(() => ['admin', 'supervisor'].includes(props.role));

/* ------------------------------------------------------------------ */
/* Configuration grid (admin & supervisor)                             */
/* ------------------------------------------------------------------ */

const configSections = computed(() => {
  if (props.role === 'supervisor') {
    return [
      {
        category: 'Gares & Utilisateurs',
        items: [
          { name: 'Gares', route: 'supervisor.stations.index', icon: OfficeBuilding, description: 'Gares sous votre supervision', count: props.stats.stations },
          { name: 'Utilisateurs', route: 'supervisor.users.index', icon: AccountMultiple, description: 'Gérer les comptes de votre périmètre', count: props.stats.users },
          { name: 'Assignations', route: 'supervisor.assignments.index', icon: AccountGroup, description: 'Assigner aux gares de votre périmètre', count: props.stats.assignments },
        ],
      },
    ];
  }

  return [
    {
      category: 'Entreprise',
      items: [
        { name: 'Identité & Logo', route: 'admin.settings.enterprise', icon: OfficeBuilding, description: 'Nom, contact et visuel de la compagnie' },
        { name: 'Fidélisation (Okohi)', route: 'admin.settings.loyalty', icon: GiftOutline, description: 'Points de fidélité sur les tickets' },
        { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer, description: "Configuration d'impression" },
        { name: 'Appareils autorisés', route: 'admin.settings.devices.index', icon: ShieldLock, description: 'Contrôler les appareils TIKETI et Control' },
      ],
    },
    {
      category: 'Infrastructure',
      items: [
        { name: 'Villes / Destinations', route: 'admin.destinations.index', icon: MapMarkerRadius, description: 'Gérer les villes desservies', count: props.stats.destinations },
        { name: 'Gares', route: 'admin.stations.index', icon: OfficeBuilding, description: 'Gérer les gares et points de départ', count: props.stats.stations },
      ],
    },
    {
      category: 'Flotte',
      items: [
        { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car, description: 'Configurations des types', count: props.stats.vehicleTypes },
        { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus, description: 'Gérer les véhicules', count: props.stats.vehicles },
        { name: 'Équipages', route: 'fleet.crew-members.index', icon: AccountHardHat, description: 'Gérer les chauffeurs et assistants' },
        { name: 'Affectations Équipages', route: 'fleet.crew-assignments.index', icon: SwapHorizontal, description: 'Affecter les équipages aux véhicules' },
      ],
    },
    {
      category: 'Opérations',
      items: [
        { name: 'Trajets', route: 'admin.routes.index', icon: Router, description: 'Configurer les itinéraires', count: props.stats.routes },
        { name: 'Voyages', route: 'admin.trips.index', icon: Calendar, description: 'Planifier les voyages', count: props.stats.trips },
        { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash, description: 'Définir les prix', count: props.stats.fares },
      ],
    },
    {
      category: 'Utilisateurs',
      items: [
        { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple, description: 'Gérer les comptes', count: props.stats.users },
        { name: 'Assignations', route: 'admin.assignments.index', icon: AccountGroup, description: 'Assigner aux gares', count: props.stats.assignments },
      ],
    },
  ];
});

const adminQuickStats = computed(() => [
  { label: 'Gares', value: props.stats.stations, route: 'admin.stations.index' },
  { label: 'Trajets', value: props.stats.routes, route: 'admin.routes.index' },
  { label: 'Destinations', value: props.stats.destinations, route: 'admin.destinations.index' },
  { label: 'Véhicules', value: props.stats.vehicles, route: 'admin.vehicles.index' },
  { label: 'Utilisateurs', value: props.stats.users, route: 'admin.users.index' },
  { label: 'Tarifs', value: props.stats.fares, route: 'admin.route-fares.index' },
]);

const supervisorQuickStats = computed(() => [
  { label: 'Gares', value: props.stats.stations, route: 'supervisor.stations.index' },
  { label: 'Utilisateurs', value: props.stats.users, route: 'supervisor.users.index' },
  { label: 'Assignations', value: props.stats.assignments, route: 'supervisor.assignments.index' },
]);

/* ------------------------------------------------------------------ */
/* Consultation workspace (non-admin roles)                            */
/* ------------------------------------------------------------------ */

const menuSections = computed(() => [
  { id: 'profile', label: 'Mon profil', icon: AccountTie },
  { id: 'company', label: 'Entreprise', icon: OfficeBuilding },
  { id: 'perimeter', label: 'Mon périmètre', icon: Sitemap },
  { id: 'loyalty', label: 'Fidélité', icon: GiftOutline },
  { id: 'directives', label: 'Directives', icon: Clipboard },
]);

const activeSection = ref('profile');

const activeSectionLabel = computed(() => menuSections.value.find((s) => s.id === activeSection.value)?.label || '');

const perimeterItems = computed(() => {
  switch (props.role) {
    case 'seller':
      return [
        { id: 'scope-stations', label: 'Mes gares de vente', icon: OfficeBuilding },
        { id: 'scope-routes', label: 'Mes trajets accessibles', icon: Router },
        { id: 'scope-payments', label: 'Moyens de paiement', icon: CashMultiple },
        { id: 'scope-compensation', label: 'Compensation vendeur', icon: Wallet },
        { id: 'scope-devices', label: 'Appareils', icon: CellphoneLink },
      ];
    case 'fleet_manager':
      return [
        { id: 'scope-fleet', label: 'Flotte & pools', icon: Bus },
        { id: 'scope-crews', label: 'Équipages', icon: AccountHardHat },
      ];
    case 'accountant':
      return [
        { id: 'scope-payments', label: 'Paiements comptabilisés', icon: CreditCard },
        { id: 'scope-rules', label: 'Règles de clôture', icon: Clock },
        { id: 'scope-reports', label: 'Rapports disponibles', icon: FileDocument },
        { id: 'scope-contacts', label: 'Administrateurs', icon: AccountTie },
      ];
    case 'executive':
      return [
        { id: 'scope-network', label: 'Réseau', icon: Domain },
        { id: 'scope-policies', label: 'Politiques commerciales', icon: TagHeart },
        { id: 'scope-services', label: 'Services actifs', icon: CellphoneLink },
        { id: 'scope-supervisors', label: 'Superviseurs', icon: AccountTie },
      ];
    default:
      return [];
  }
});

const listItems = computed(() => {
  switch (activeSection.value) {
    case 'profile':
      return [{ id: 'profile', label: 'Votre profil professionnel', icon: AccountTie }];
    case 'company':
      return [{ id: 'company', label: 'Informations générales', icon: OfficeBuilding }];
    case 'perimeter':
      return perimeterItems.value;
    case 'loyalty':
      return [{ id: 'loyalty', label: 'Programme Okohi', icon: GiftOutline }];
    case 'directives':
      return [{ id: 'directives', label: 'Directives & procédures', icon: Clipboard }];
    default:
      return [];
  }
});

const activeItem = ref('profile');

watch(activeSection, () => {
  activeItem.value = listItems.value[0]?.id ?? null;
});

const activeItemLabel = computed(() => listItems.value.find((item) => item.id === activeItem.value)?.label || '');
</script>

<template>
  <MainNavLayout :fullHeight="isManager ? false : true">
    <!-- ================= CONFIGURATION GRID (ADMIN / SUPERVISOR) ================= -->
    <template v-if="isManager">
      <div class="w-full px-4 text-slate-900 dark:text-slate-100">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
          <div>
            <h1 class="text-3xl font-black text-slate-900 flex items-center gap-3 dark:text-slate-100">
              <div class="p-2 bg-emerald-100 rounded-2xl dark:bg-emerald-900/25">
                <Settings class="text-emerald-600 dark:text-emerald-400" :size="28" />
              </div>
              Configuration du Système
            </h1>
            <p class="text-slate-500 mt-1 dark:text-slate-400">
              {{ role === 'supervisor' ? 'Gérez la configuration de votre périmètre' : 'Gérez tous les paramètres de votre système de transport' }}
            </p>
          </div>
        </div>

        <!-- Quick Stats -->
        <div
          v-if="role !== 'supervisor'"
          class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6"
        >
          <Link
            v-for="stat in adminQuickStats"
            :key="stat.route"
            :href="route(stat.route)"
            class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20"
          >
            <div class="text-2xl font-black text-emerald-600">{{ stat.value }}</div>
            <div class="text-xs font-bold text-slate-400 uppercase mt-1">{{ stat.label }}</div>
          </Link>
        </div>
        <div v-else class="grid grid-cols-3 gap-4 mb-6 max-w-lg">
          <Link
            v-for="stat in supervisorQuickStats"
            :key="stat.route"
            :href="route(stat.route)"
            class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20"
          >
            <div class="text-2xl font-black text-emerald-600">{{ stat.value }}</div>
            <div class="text-xs font-bold text-slate-400 uppercase mt-1">{{ stat.label }}</div>
          </Link>
        </div>

        <!-- Configuration Grid -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <div
            v-for="section in configSections"
            :key="section.category"
            class="space-y-3"
          >
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wide dark:text-slate-500">{{ section.category }}</h3>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
              <Link
                v-for="item in section.items"
                :key="item.route"
                :href="route(item.route)"
                class="block p-3 bg-white rounded-2xl border border-slate-100 hover:border-emerald-200 hover:shadow-lg transition-all group dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20"
              >
                <div class="flex items-start gap-3">
                  <div class="p-2 bg-slate-100 group-hover:bg-emerald-100 rounded-xl transition-colors shrink-0 dark:bg-slate-800 dark:group-hover:bg-emerald-900/25">
                    <component :is="item.icon" :size="20" class="text-slate-500 group-hover:text-emerald-600 dark:text-slate-300 dark:group-hover:text-emerald-400" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                      <div class="flex items-center gap-2 min-w-0">
                        <span class="font-bold text-slate-900 group-hover:text-emerald-700 text-sm leading-tight dark:text-slate-100 dark:group-hover:text-emerald-300">{{ item.name }}</span>
                        <span
                          v-if="item.count !== null && item.count !== undefined"
                          class="inline-flex min-w-7 justify-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300"
                        >
                          {{ item.count }}
                        </span>
                      </div>
                      <ChevronRight :size="18" class="text-slate-300 group-hover:text-emerald-500 shrink-0 dark:text-slate-600" />
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1 leading-relaxed dark:text-slate-400">{{ item.description }}</p>
                  </div>
                </div>
              </Link>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ================= CONSULTATION WORKSPACE (NON-ADMIN ROLES) ================= -->
    <template v-else>
      <div class="flex flex-col h-full w-full overflow-hidden">
        <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
          <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
              <div class="p-2 bg-emerald-100 rounded-2xl dark:bg-emerald-900/25">
                <Settings class="text-emerald-600 dark:text-emerald-400" :size="28" />
              </div>
              Paramétrage
            </h1>
            <p class="text-slate-500 mt-1 dark:text-slate-400">
              Consultez les informations et réglages de votre espace de travail
            </p>
          </div>
        </div>

        <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
          <!-- LEFT SIDE MENU -->
          <div class="col-span-12 md:col-span-3 lg:col-span-2 min-h-0">
            <div class="hidden md:flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
              <h2 class="pl-0.5 pb-2 pt-0.5 text-base font-semibold text-slate-800 dark:text-slate-100">Paramètres</h2>
              <button
                v-for="section in menuSections"
                :key="section.id"
                type="button"
                @click="activeSection = section.id"
                :class="[
                  'flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors text-left',
                  activeSection === section.id
                    ? 'bg-emerald-50 text-emerald-700 font-medium dark:bg-emerald-900/30 dark:text-emerald-300'
                    : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-emerald-300',
                ]"
              >
                <component :is="section.icon" class="w-5 h-5 shrink-0" />
                <span class="leading-tight py-0.5">{{ section.label }}</span>
              </button>
            </div>

            <div class="md:hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
              <h2 class="text-lg font-semibold text-slate-800 mb-3 dark:text-slate-100">Paramètres</h2>
              <select
                :value="activeSection"
                @change="activeSection = $event.target.value"
                class="w-full rounded-xl border-slate-200 focus:border-emerald-500 focus:ring focus:ring-emerald-200 focus:ring-opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
              >
                <option v-for="section in menuSections" :key="section.id" :value="section.id">{{ section.label }}</option>
              </select>
            </div>
          </div>

          <!-- MIDDLE LIST SECTION -->
          <div class="col-span-12 md:col-span-9 lg:col-span-4 min-h-0 flex flex-col">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-2.5 flex flex-col overflow-hidden dark:border-slate-800 dark:bg-slate-900">
              <h3 class="px-3 pb-2 pt-1 text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ activeSectionLabel }}
              </h3>
              <div class="flex-1 overflow-y-auto space-y-0.5 custom-scrollbar">
                <button
                  v-for="item in listItems"
                  :key="item.id"
                  type="button"
                  @click="activeItem = item.id"
                  :class="[
                    'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors text-left',
                    activeItem === item.id
                      ? 'bg-emerald-50 text-emerald-700 font-medium dark:bg-emerald-900/30 dark:text-emerald-300'
                      : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-emerald-300',
                  ]"
                >
                  <component :is="item.icon" class="w-5 h-5 shrink-0" />
                  <span class="leading-tight py-0.5 flex-1">{{ item.label }}</span>
                  <ChevronRight :size="18" class="shrink-0 opacity-50" />
                </button>
              </div>
            </div>
          </div>

          <!-- RIGHT DISPLAY SECTION -->
          <div class="col-span-12 lg:col-span-6 min-h-0 overflow-y-auto pr-2 custom-scrollbar">
            <template v-if="activeItem === 'profile'">
              <UserProfileSection :profile="profile" />
            </template>

            <template v-else-if="activeItem === 'company'">
              <GeneralInfoSection :company="company" />
            </template>

            <template v-else-if="activeItem === 'loyalty'">
              <LoyaltyRewardsSection :loyalty="loyalty" />
            </template>

            <template v-else-if="activeItem === 'directives'">
              <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 dark:bg-slate-900 dark:border-slate-800">
                <div class="flex items-center gap-3 mb-5">
                  <div class="p-2 bg-emerald-100 rounded-xl dark:bg-emerald-900/25">
                    <Clipboard class="text-emerald-600 dark:text-emerald-400" :size="22" />
                  </div>
                  <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Directives & procédures</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Bonnes pratiques à suivre pour votre fonction</p>
                  </div>
                </div>
                <div v-if="directives.length" class="grid gap-3 md:grid-cols-2">
                  <div
                    v-for="(directive, index) in directives"
                    :key="index"
                    class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40"
                  >
                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">{{ directive.title }}</h3>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed dark:text-slate-400">{{ directive.content }}</p>
                  </div>
                </div>
                <p v-else class="text-xs text-slate-500 dark:text-slate-400">Aucune directive définie pour votre fonction.</p>
              </div>
            </template>

            <template v-else-if="activeItem && activeItem.startsWith('scope-')">
              <ScopeSection :scope="scope" />
            </template>

            <div v-else class="flex flex-col items-center justify-center py-16 text-center">
              <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 dark:bg-slate-800">
                <Settings :size="36" />
              </div>
              <h3 class="text-base font-bold text-slate-800 mb-1 dark:text-slate-100">Sélectionnez un élément</h3>
              <p class="text-xs text-slate-500 max-w-sm leading-relaxed dark:text-slate-400">
                Choisissez une rubrique dans le menu puis un élément dans la liste pour consulter son détail.
              </p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </MainNavLayout>
</template>
