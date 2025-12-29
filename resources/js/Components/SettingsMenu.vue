<template>
    <!-- Desktop Menu -->
    <div class="hidden md:block bg-white rounded-lg border border-orange-200 shadow-sm p-3">
        <h2 class="text-lg font-semibold text-green-700 mb-3">Menu Paramètres</h2>
        <nav class="space-y-1">
            <Link v-for="item in settingsMenu" :key="item.route" :href="route(item.route)" :class="[
                'flex items-center px-3 py-2 text-sm rounded-lg transition-colors',
                route().current(item.route)
                    ? 'bg-orange-100 text-orange-700 font-medium'
                    : 'text-gray-600 hover:bg-green-50 hover:text-green-700'
            ]">
                <component :is="item.icon" class="w-5 h-5 mr-2" />
                {{ item.name }}
            </Link>
        </nav>
    </div>

    <!-- Mobile Menu -->
    <div class="md:hidden bg-white rounded-lg border border-orange-200 shadow-sm p-3">
        <h2 class="text-lg font-semibold text-green-700 mb-3">Menu Paramètres</h2>
        <select v-model="selectedRoute" 
                @change="navigateToRoute"
                class="w-full rounded-lg border-orange-200 focus:border-orange-400 focus:ring focus:ring-orange-200 focus:ring-opacity-50">
            <option v-for="item in settingsMenu" 
                    :key="item.route" 
                    :value="item.route"
                    :selected="route().current(item.route)">
                {{ item.name }}
            </option>
        </select>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MapMarker from 'vue-material-design-icons/MapMarker.vue';
import Router from 'vue-material-design-icons/Router.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Cash from 'vue-material-design-icons/Cash.vue';

const selectedRoute = ref('');

const settingsMenu = [
    { name: 'Gares', route: 'admin.stations.index', icon: MapMarker },
    { name: 'Trajets', route: 'admin.routes.index', icon: Router },
    { name: 'Destinations', route: 'admin.stops.index', icon: MapMarkerRadius },
    { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus },
    { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car },
    { name: 'Voyages', route: 'admin.trips.index', icon: Calendar },
    { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash },
    { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple },
    { name: 'Affectations', route: 'admin.assignments.index', icon: AccountGroup },
    { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer },
];

onMounted(() => {
    selectedRoute.value = settingsMenu.find(item => route().current(item.route))?.route || settingsMenu[0].route;
});

const navigateToRoute = () => {
    router.visit(route(selectedRoute.value));
};
</script>