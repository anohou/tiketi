<script setup>
import SectionMenu from '@/Components/SectionMenu.vue';
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';

const fleetMenu = [
  { name: 'Accueil', route: 'fleet.dashboard', icon: HomeOutline },
  { name: 'Types de véhicules', route: 'fleet.vehicle-types.index', icon: Car },
  { name: 'Véhicules', route: 'fleet.vehicles.index', icon: Bus },
  { name: 'Affectations', route: 'fleet.assignments.index', icon: AccountGroup },
  { name: 'Équipage', route: 'fleet.crew-members.index', icon: AccountHardHat },
  { name: 'Affect. équipage', route: 'fleet.crew-assignments.index', icon: SwapHorizontal },
];
</script>

<template>
  <SectionMenu title="Gestion de flotte" :items="fleetMenu" />
</template>

