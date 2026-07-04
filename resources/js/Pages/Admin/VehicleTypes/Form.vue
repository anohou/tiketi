<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import VehicleTypeFormFields from '@/Components/VehicleTypeFormFields.vue';

const props = defineProps({
  vehicleType: {
    type: Object,
    default: null
  },
  errors: {
    type: Object,
    default: () => ({})
  }
});
</script>

<template>
  <MainNavLayout>
    <div class="flex h-full">
      <SettingsMenu />

      <div class="flex-1 bg-white">
        <div class="p-6">
          <VehicleTypeFormFields
            :vehicle-type="vehicleType"
            :errors="errors"
            :submit-url="vehicleType ? route('admin.vehicle-types.update', vehicleType.id) : route('admin.vehicle-types.store')"
            :submit-method="vehicleType ? 'put' : 'post'"
            :back-url="route('admin.vehicle-types.index')"
            submit-label="Enregistrer"
            cancel-label="Annuler"
          />
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
