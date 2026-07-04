<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import VehicleTypeFormFields from '@/Components/VehicleTypeFormFields.vue';

const props = defineProps({
  vehicleType: { type: Object, default: null },
  errors: { type: Object, default: () => ({}) },
});
</script>

<template>
  <MainNavLayout>
    <div class="grid grid-cols-12 gap-4">
      <div class="col-span-12 md:col-span-2">
        <FleetMenu />
      </div>

      <div class="col-span-12 md:col-span-10 bg-white rounded-2xl border border-orange-100 shadow-sm p-6">
        <div>
          <VehicleTypeFormFields
            :vehicle-type="vehicleType"
            :errors="errors"
            :submit-url="vehicleType ? route('fleet.vehicle-types.update', vehicleType.id) : route('fleet.vehicle-types.store')"
            :submit-method="vehicleType ? 'put' : 'post'"
            :back-url="route('fleet.vehicle-types.index')"
            submit-label="Enregistrer"
            cancel-label="Annuler"
          />
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
