<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SectionMenu from '@/Components/SectionMenu.vue';
import Router from 'vue-material-design-icons/Router.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Cash from 'vue-material-design-icons/Cash.vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';

const props = defineProps({
  stats: {
    type: Object,
    default: () => ({}),
  },
});

const page = usePage();

const resolvedStats = computed(() => {
  if (props.stats && Object.keys(props.stats).length > 0) {
    return props.stats;
  }

  return page.props.settingsStats || {};
});

const settingsMenu = computed(() => {
  const user = page.props.auth.user || {};
  if (user.role === 'supervisor') {
    return [
      { name: 'Gares', route: 'supervisor.stations.index', icon: OfficeBuilding, count: resolvedStats.value.stations },
      { name: 'Utilisateurs', route: 'supervisor.users.index', icon: AccountMultiple, count: resolvedStats.value.users },
      { name: 'Affectations', route: 'supervisor.assignments.index', icon: AccountGroup, count: resolvedStats.value.assignments },
    ];
  }

  return [
    { name: 'Entreprise', route: 'admin.settings.enterprise', icon: OfficeBuilding },
    { name: 'Fidélisation (Okohi)', route: 'admin.settings.loyalty', icon: GiftOutline },
    { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer },
    { name: 'Villes', route: 'admin.destinations.index', icon: MapMarkerRadius, count: resolvedStats.value.destinations },
    { name: 'Gares / Destinations', route: 'admin.stations.index', icon: OfficeBuilding, count: resolvedStats.value.stations },
    { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash, count: resolvedStats.value.fares },
    { name: 'Trajets', route: 'admin.routes.index', icon: Router, count: resolvedStats.value.routes },
    { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car, count: resolvedStats.value.vehicleTypes },
    { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus, count: resolvedStats.value.vehicles },
    { name: 'Voyages', route: 'admin.trips.index', icon: Calendar, count: resolvedStats.value.trips },
    { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple, count: resolvedStats.value.users },
    { name: 'Affectations', route: 'admin.assignments.index', icon: AccountGroup, count: resolvedStats.value.assignments },
    { name: 'Équipages', route: 'fleet.crew-members.index', icon: AccountHardHat, count: resolvedStats.value.crewMembers },
    { name: 'Affectations Équipages', route: 'fleet.crew-assignments.index', icon: SwapHorizontal, count: resolvedStats.value.crewAssignments },
  ];
});
</script>

<template>
  <SectionMenu title="Menu Paramètres" :items="settingsMenu" />
</template>
