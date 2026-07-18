<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
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

const page = usePage();
const user = computed(() => page.props.auth.user || {});

const props = defineProps({
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
      assignments: 0
    })
  }
});

const configSections = computed(() => {
  if (user.value.role === 'supervisor') {
    return [
      {
        category: 'Gares & Utilisateurs',
        items: [
          { name: 'Gares', route: 'supervisor.stations.index', icon: OfficeBuilding, description: 'Gares sous votre supervision', count: props.stats.stations },
          { name: 'Utilisateurs', route: 'supervisor.users.index', icon: AccountMultiple, description: 'Gérer les comptes de votre périmètre', count: props.stats.users },
          { name: 'Assignations', route: 'supervisor.assignments.index', icon: AccountGroup, description: 'Assigner aux gares de votre périmètre', count: props.stats.assignments },
        ]
      }
    ];
  }

  return [
    {
      category: 'Entreprise',
      items: [
        { name: 'Identité & Logo', route: 'admin.settings.enterprise', icon: OfficeBuilding, description: 'Nom, contact et visuel de la compagnie' },
        { name: 'Fidélisation (Okohi)', route: 'admin.settings.loyalty', icon: GiftOutline, description: 'Points de fidélité sur les tickets' },
        { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer, description: "Configuration d'impression" },
      ]
    },
    {
      category: 'Infrastructure',
      items: [
        { name: 'Villes / Destinations', route: 'admin.destinations.index', icon: MapMarkerRadius, description: 'Gérer les villes desservies', count: props.stats.destinations },
        { name: 'Gares', route: 'admin.stations.index', icon: OfficeBuilding, description: 'Gérer les gares et points de départ', count: props.stats.stations },
      ]
    },
    {
      category: 'Flotte',
      items: [
        { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car, description: 'Configurations des types', count: props.stats.vehicleTypes },
        { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus, description: 'Gérer les véhicules', count: props.stats.vehicles },
        { name: 'Équipages', route: 'fleet.crew-members.index', icon: AccountHardHat, description: 'Gérer les chauffeurs et assistants' },
        { name: 'Affectations Équipages', route: 'fleet.crew-assignments.index', icon: SwapHorizontal, description: 'Affecter les équipages aux véhicules' },
      ]
    },
    {
      category: 'Opérations',
      items: [
        { name: 'Trajets', route: 'admin.routes.index', icon: Router, description: 'Configurer les itinéraires', count: props.stats.routes },
        { name: 'Voyages', route: 'admin.trips.index', icon: Calendar, description: 'Planifier les voyages', count: props.stats.trips },
        { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash, description: 'Définir les prix', count: props.stats.fares },
      ]
    },
    {
      category: 'Utilisateurs',
      items: [
        { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple, description: 'Gérer les comptes', count: props.stats.users },
        { name: 'Assignations', route: 'admin.assignments.index', icon: AccountGroup, description: 'Assigner aux gares', count: props.stats.assignments },
      ]
    }
  ];
});
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4 text-slate-900 dark:text-slate-100">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-3xl font-black text-slate-900 flex items-center gap-3 dark:text-slate-100">
            <div class="p-2 bg-emerald-100 rounded-2xl dark:bg-emerald-900/25">
              <Settings class="text-emerald-600 dark:text-emerald-400" :size="28" />
            </div>
            Configuration du Système
          </h1>
          <p class="text-slate-500 mt-1 dark:text-slate-400">Gérez tous les paramètres de votre système de transport</p>
        </div>
      </div>

      <!-- Quick Stats - At top -->
      <div v-if="user.role !== 'supervisor'" class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
        <Link :href="route('admin.stations.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-emerald-600">{{ stats.stations }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Gares</div>
        </Link>
        <Link :href="route('admin.routes.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.routes }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Trajets</div>
        </Link>
        <Link :href="route('admin.destinations.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.destinations }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Destinations</div>
        </Link>
        <Link :href="route('admin.vehicles.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.vehicles }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Véhicules</div>
        </Link>
        <Link :href="route('admin.users.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.users }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Utilisateurs</div>
        </Link>
        <Link :href="route('admin.route-fares.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.fares }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Tarifs</div>
        </Link>
      </div>
      <div v-else class="grid grid-cols-3 gap-4 mb-6 max-w-lg">
        <Link :href="route('supervisor.stations.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-emerald-600">{{ stats.stations }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Gares</div>
        </Link>
        <Link :href="route('supervisor.users.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.users }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Utilisateurs</div>
        </Link>
        <Link :href="route('supervisor.assignments.index')" class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm text-center hover:border-emerald-200 hover:shadow-lg transition-all dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="text-2xl font-black text-slate-700">{{ stats.assignments }}</div>
          <div class="text-xs font-bold text-slate-400 uppercase mt-1">Assignations</div>
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
  </MainNavLayout>
</template>
