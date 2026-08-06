<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

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
        category: t('admin_settings.index.categories.stations_users', 'Gares & Utilisateurs'),
        items: [
          { name: t('admin_settings.index.items.stations', 'Gares'), route: 'supervisor.stations.index', icon: OfficeBuilding, description: t('admin_settings.index.descriptions.supervised_stations'), count: props.stats.stations },
          { name: t('admin_settings.index.items.users', 'Utilisateurs'), route: 'supervisor.users.index', icon: AccountMultiple, description: t('admin_settings.index.descriptions.manage_perimeter_users'), count: props.stats.users },
          { name: t('admin_settings.index.items.assignments', 'Assignations'), route: 'supervisor.assignments.index', icon: AccountGroup, description: t('admin_settings.index.descriptions.assign_perimeter_stations'), count: props.stats.assignments },
        ],
      },
    ];
  }

  return [
    {
      category: t('admin_settings.index.categories.company', 'Entreprise'),
      items: [
        { name: t('admin_settings.index.items.identity_logo', 'Identité & Logo'), route: 'admin.settings.enterprise', icon: OfficeBuilding, description: t('admin_settings.index.descriptions.company_identity') },
        { name: t('admin_settings.index.items.loyalty_okohi', 'Fidélisation (Okohi)'), route: 'admin.settings.loyalty', icon: GiftOutline, description: t('admin_settings.index.descriptions.loyalty_points') },
        { name: t('admin_settings.index.items.ticket_settings', 'Paramètres Tickets'), route: 'admin.ticket-settings.index', icon: Printer, description: t('admin_settings.index.descriptions.print_configuration') },
        { name: t('admin_settings.index.items.authorized_devices', 'Appareils autorisés'), route: 'admin.settings.devices.index', icon: ShieldLock, description: t('admin_settings.index.descriptions.control_devices') },
      ],
    },
    {
      category: t('admin_settings.index.categories.infrastructure', 'Infrastructure'),
      items: [
        { name: t('admin_settings.index.items.cities_destinations', 'Villes / Destinations'), route: 'admin.destinations.index', icon: MapMarkerRadius, description: t('admin_settings.index.descriptions.manage_cities'), count: props.stats.destinations },
        { name: t('admin_settings.index.items.stations', 'Gares'), route: 'admin.stations.index', icon: OfficeBuilding, description: t('admin_settings.index.descriptions.manage_stations'), count: props.stats.stations },
      ],
    },
    {
      category: t('admin_settings.index.categories.fleet', 'Flotte'),
      items: [
        { name: t('admin_settings.index.items.vehicle_types', 'Types de Véhicules'), route: 'admin.vehicle-types.index', icon: Car, description: t('admin_settings.index.descriptions.vehicle_type_config'), count: props.stats.vehicleTypes },
        { name: t('admin_settings.index.items.vehicles', 'Véhicules'), route: 'admin.vehicles.index', icon: Bus, description: t('admin_settings.index.descriptions.manage_vehicles'), count: props.stats.vehicles },
        { name: t('admin_settings.index.items.crews', 'Équipages'), route: 'fleet.crew-members.index', icon: AccountHardHat, description: t('admin_settings.index.descriptions.manage_crews') },
        { name: t('admin_settings.index.items.crew_assignments', 'Affectations Équipages'), route: 'fleet.crew-assignments.index', icon: SwapHorizontal, description: t('admin_settings.index.descriptions.assign_crews') },
      ],
    },
    {
      category: t('admin_settings.index.categories.operations', 'Opérations'),
      items: [
        { name: t('admin_settings.index.items.routes', 'Trajets'), route: 'admin.routes.index', icon: Router, description: t('admin_settings.index.descriptions.configure_routes'), count: props.stats.routes },
        { name: t('admin_settings.index.items.trips', 'Voyages'), route: 'admin.trips.index', icon: Calendar, description: t('admin_settings.index.descriptions.plan_trips'), count: props.stats.trips },
        { name: t('admin_settings.index.items.fares', 'Tarifs'), route: 'admin.route-fares.index', icon: Cash, description: t('admin_settings.index.descriptions.set_prices'), count: props.stats.fares },
      ],
    },
    {
      category: t('admin_settings.index.categories.users', 'Utilisateurs'),
      items: [
        { name: t('admin_settings.index.items.users', 'Utilisateurs'), route: 'admin.users.index', icon: AccountMultiple, description: t('admin_settings.index.descriptions.manage_accounts'), count: props.stats.users },
        { name: t('admin_settings.index.items.assignments', 'Assignations'), route: 'admin.assignments.index', icon: AccountGroup, description: t('admin_settings.index.descriptions.assign_to_stations'), count: props.stats.assignments },
      ],
    },
  ];
});

const adminQuickStats = computed(() => [
  { label: t('admin_settings.index.items.stations', 'Gares'), value: props.stats.stations, route: 'admin.stations.index' },
  { label: t('admin_settings.index.items.routes', 'Trajets'), value: props.stats.routes, route: 'admin.routes.index' },
  { label: t('admin_settings.index.items.destinations', 'Destinations'), value: props.stats.destinations, route: 'admin.destinations.index' },
  { label: t('admin_settings.index.items.vehicles', 'Véhicules'), value: props.stats.vehicles, route: 'admin.vehicles.index' },
  { label: t('admin_settings.index.items.users', 'Utilisateurs'), value: props.stats.users, route: 'admin.users.index' },
  { label: t('admin_settings.index.items.fares', 'Tarifs'), value: props.stats.fares, route: 'admin.route-fares.index' },
]);

const supervisorQuickStats = computed(() => [
  { label: t('admin_settings.index.items.stations', 'Gares'), value: props.stats.stations, route: 'supervisor.stations.index' },
  { label: t('admin_settings.index.items.users', 'Utilisateurs'), value: props.stats.users, route: 'supervisor.users.index' },
  { label: t('admin_settings.index.items.assignments', 'Assignations'), value: props.stats.assignments, route: 'supervisor.assignments.index' },
]);

/* ------------------------------------------------------------------ */
/* Consultation workspace (non-admin roles)                            */
/* ------------------------------------------------------------------ */

const menuSections = computed(() => [
  { id: 'profile', label: t('admin_settings.index.menu.my_profile', 'Mon profil'), icon: AccountTie },
  { id: 'company', label: t('admin_settings.index.categories.company', 'Entreprise'), icon: OfficeBuilding },
  { id: 'perimeter', label: t('admin_settings.index.menu.my_perimeter', 'Mon périmètre'), icon: Sitemap },
  { id: 'loyalty', label: t('admin_settings.index.menu.loyalty', 'Fidélité'), icon: GiftOutline },
  { id: 'directives', label: t('admin_settings.index.menu.directives', 'Directives'), icon: Clipboard },
]);

const activeSection = ref('profile');

const activeSectionLabel = computed(() => menuSections.value.find((s) => s.id === activeSection.value)?.label || '');

const perimeterItems = computed(() => {
  switch (props.role) {
    case 'seller':
      return [
        { id: 'scope-stations', label: t('admin_settings.index.perimeter.sale_stations', 'Mes gares de vente'), icon: OfficeBuilding },
        { id: 'scope-routes', label: t('admin_settings.index.perimeter.accessible_routes', 'Mes trajets accessibles'), icon: Router },
        { id: 'scope-payments', label: t('admin_settings.index.perimeter.payment_methods', 'Moyens de paiement'), icon: CashMultiple },
        { id: 'scope-compensation', label: t('admin_settings.index.perimeter.seller_compensation', 'Compensation vendeur'), icon: Wallet },
        { id: 'scope-devices', label: t('admin_settings.index.perimeter.devices', 'Appareils'), icon: CellphoneLink },
      ];
    case 'fleet_manager':
      return [
        { id: 'scope-fleet', label: t('admin_settings.index.perimeter.fleet_pools', 'Flotte & pools'), icon: Bus },
        { id: 'scope-crews', label: t('admin_settings.index.perimeter.crews', 'Équipages'), icon: AccountHardHat },
      ];
    case 'accountant':
      return [
        { id: 'scope-payments', label: t('admin_settings.index.perimeter.accounted_payments', 'Paiements comptabilisés'), icon: CreditCard },
        { id: 'scope-rules', label: t('admin_settings.index.perimeter.closing_rules', 'Règles de clôture'), icon: Clock },
        { id: 'scope-reports', label: t('admin_settings.index.perimeter.available_reports', 'Rapports disponibles'), icon: FileDocument },
        { id: 'scope-contacts', label: t('admin_settings.index.perimeter.administrators', 'Administrateurs'), icon: AccountTie },
      ];
    case 'executive':
      return [
        { id: 'scope-network', label: t('admin_settings.index.perimeter.network', 'Réseau'), icon: Domain },
        { id: 'scope-policies', label: t('admin_settings.index.perimeter.commercial_policies', 'Politiques commerciales'), icon: TagHeart },
        { id: 'scope-services', label: t('admin_settings.index.perimeter.active_services', 'Services actifs'), icon: CellphoneLink },
        { id: 'scope-supervisors', label: t('admin_settings.index.perimeter.supervisors', 'Superviseurs'), icon: AccountTie },
      ];
    default:
      return [];
  }
});

const listItems = computed(() => {
  switch (activeSection.value) {
    case 'profile':
      return [{ id: 'profile', label: t('admin_settings.index.list.professional_profile', 'Votre profil professionnel'), icon: AccountTie }];
    case 'company':
      return [{ id: 'company', label: t('admin_settings.index.list.general_information', 'Informations générales'), icon: OfficeBuilding }];
    case 'perimeter':
      return perimeterItems.value;
    case 'loyalty':
      return [{ id: 'loyalty', label: t('admin_settings.index.list.okohi_program', 'Programme Okohi'), icon: GiftOutline }];
    case 'directives':
      return [{ id: 'directives', label: t('admin_settings.index.list.directives_procedures', 'Directives & procédures'), icon: Clipboard }];
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
              </div>{{ $t('admin_settings.index.title') }}</h1>
            <p class="text-slate-500 mt-1 dark:text-slate-400">
              {{ role === 'supervisor' ? $t('admin_settings.index.supervisor_subtitle') : $t('admin_settings.index.admin_subtitle') }}
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
              </div>{{ $t('admin_settings.index.workspace_title') }}</h1>
            <p class="text-slate-500 mt-1 dark:text-slate-400">{{ $t('admin_settings.index.workspace_subtitle') }}</p>
          </div>
        </div>

        <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
          <!-- LEFT SIDE MENU -->
          <div class="col-span-12 md:col-span-3 lg:col-span-2 min-h-0">
            <div class="hidden md:flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
              <h2 class="pl-0.5 pb-2 pt-0.5 text-base font-semibold text-slate-800 dark:text-slate-100">{{ $t('admin_settings.index.menu_title') }}</h2>
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
              <h2 class="text-lg font-semibold text-slate-800 mb-3 dark:text-slate-100">{{ $t('admin_settings.index.menu_title') }}</h2>
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
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ $t('admin_settings.index.list.directives_procedures') }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $t('admin_settings.index.directives.subtitle') }}</p>
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
                <p v-else class="text-xs text-slate-500 dark:text-slate-400">{{ $t('admin_settings.index.directives.empty') }}</p>
              </div>
            </template>

            <template v-else-if="activeItem && activeItem.startsWith('scope-')">
              <ScopeSection :scope="scope" />
            </template>

            <div v-else class="flex flex-col items-center justify-center py-16 text-center">
              <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 dark:bg-slate-800">
                <Settings :size="36" />
              </div>
              <h3 class="text-base font-bold text-slate-800 mb-1 dark:text-slate-100">{{ $t('admin_settings.index.empty.title') }}</h3>
              <p class="text-xs text-slate-500 max-w-sm leading-relaxed dark:text-slate-400">{{ $t('admin_settings.index.empty.message') }}</p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </MainNavLayout>
</template>
