<script setup>
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

const settingsMenu = [
  { name: 'Entreprise', route: 'admin.settings.enterprise', icon: OfficeBuilding },
  { name: 'Fidélisation (Okohi)', route: 'admin.settings.loyalty', icon: GiftOutline },
  { name: 'Paramètres Tickets', route: 'admin.ticket-settings.index', icon: Printer },
  { name: 'Villes', route: 'admin.destinations.index', icon: MapMarkerRadius },
  { name: 'Gares / Destinations', route: 'admin.stations.index', icon: OfficeBuilding },
  { name: 'Tarifs', route: 'admin.route-fares.index', icon: Cash },
  { name: 'Trajets', route: 'admin.routes.index', icon: Router },
  { name: 'Types de Véhicules', route: 'admin.vehicle-types.index', icon: Car },
  { name: 'Véhicules', route: 'admin.vehicles.index', icon: Bus },
  { name: 'Voyages', route: 'admin.trips.index', icon: Calendar },
  { name: 'Utilisateurs', route: 'admin.users.index', icon: AccountMultiple },
  { name: 'Affectations', route: 'admin.assignments.index', icon: AccountGroup },
  { name: 'Équipages', route: 'fleet.crew-members.index', icon: AccountHardHat },
  { name: 'Affectations Équipages', route: 'fleet.crew-assignments.index', icon: SwapHorizontal },
];
</script>

<template>
  <SectionMenu title="Menu Paramètres" :items="settingsMenu" />
</template>
