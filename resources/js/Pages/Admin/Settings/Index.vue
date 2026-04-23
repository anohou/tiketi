<script setup>
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
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';

defineProps({
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

const configSections = [
  {
    category: 'Infrastructure',
    items: [
      { name: 'Villes / Destinations', route: 'admin.destinations.index', icon: MapMarkerRadius, description: 'Gérer les villes desservies' },
      { name: 'Gares', route: 'admin.stations.index', icon: OfficeBuilding, description: 'Gérer les gares et points de départ' },
    ]
  },
  {
    category: 'Flotte',
    items: [
      { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus, description: 'Gérer les véhicules' },
      { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car, description: 'Configurations des types' },
    ]
  },
  {
    category: 'Opérations',
    items: [
      { name: 'Trajets', route: 'admin.routes.index', icon: Router, description: 'Configurer les itinéraires' },
      { name: 'Voyages', route: 'admin.trips.index', icon: Calendar, description: 'Planifier les voyages' },
      { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash, description: 'Définir les prix' },
    ]
  },
  {
    category: 'Utilisateurs',
    items: [
      { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple, description: 'Gérer les comptes' },
      { name: 'Assignations', route: 'admin.assignments.index', icon: AccountGroup, description: 'Assigner aux gares' },
      { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer, description: "Configuration d'impression" },
    ]
  },
  {
    category: 'Entreprise',
    items: [
      { name: 'Identité & Logo', route: 'admin.settings.enterprise', icon: OfficeBuilding, description: 'Gérer le logo et les infos' },
    ]
  }
];
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Settings class="text-green-600" :size="28" />
            </div>
            Configuration du Système
          </h1>
          <p class="text-gray-500 mt-1">Gérez tous les paramètres de votre système de transport</p>
        </div>
      </div>

      <!-- Quick Stats - At top -->
      <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
        <Link :href="route('admin.stations.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-green-600">{{ stats.stations }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Gares</div>
        </Link>
        <Link :href="route('admin.routes.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-blue-600">{{ stats.routes }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Trajets</div>
        </Link>
        <Link :href="route('admin.destinations.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-teal-600">{{ stats.destinations }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Destinations</div>
        </Link>
        <Link :href="route('admin.vehicles.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-orange-600">{{ stats.vehicles }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Véhicules</div>
        </Link>
        <Link :href="route('admin.users.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-purple-600">{{ stats.users }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Utilisateurs</div>
        </Link>
        <Link :href="route('admin.route-fares.index')" class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm text-center hover:border-green-300 hover:shadow-lg transition-all">
          <div class="text-2xl font-black text-red-600">{{ stats.fares }}</div>
          <div class="text-xs font-bold text-gray-400 uppercase mt-1">Tarifs</div>
        </Link>
      </div>

      <!-- Configuration Grid -->
      <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        <div v-for="section in configSections" :key="section.category" class="space-y-3">
          <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wide">{{ section.category }}</h3>
          
          <Link 
            v-for="item in section.items" 
            :key="item.route"
            :href="route(item.route)"
            class="block p-4 bg-white rounded-xl border border-gray-100 hover:border-green-300 hover:shadow-lg transition-all group"
          >
            <div class="flex items-start gap-3">
              <div class="p-2 bg-gray-100 group-hover:bg-green-100 rounded-lg transition-colors shrink-0">
                <component :is="item.icon" :size="20" class="text-gray-500 group-hover:text-green-600" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-gray-900 group-hover:text-green-700">{{ item.name }}</span>
                  <ChevronRight :size="18" class="text-gray-300 group-hover:text-green-500" />
                </div>
                <p class="text-xs text-gray-500 mt-1">{{ item.description }}</p>
              </div>
            </div>
          </Link>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
