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
  <MainNavLayout :fullHeight="true">
    <div class="grid grid-cols-12 gap-4 h-full min-h-0 w-full overflow-hidden px-6 pt-6 pb-6">
      <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
        <FleetMenu />
      </div>

      <div class="col-span-12 md:col-span-10 flex flex-col h-full min-h-0">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden h-full">
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
