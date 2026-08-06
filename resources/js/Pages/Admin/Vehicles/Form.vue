<template>
  <MainNavLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ vehicle ? 'Modifier le Véhicule' : 'Nouveau Véhicule' }}
      </h2>
    </template>

    <div class="flex h-full">
      <!-- Left Sidebar Menu -->
      <SettingsMenu />

      <!-- Main Content Area -->
      <div class="flex-1 bg-white dark:bg-slate-900 h-full min-h-0">
        <FormPanel @submit="submit" class="max-w-3xl mx-auto h-full">
          <div class="p-6 space-y-6">
            <div>
              <InputLabel for="identifier" value="Numéro d'identification" />
              <TextInput
                id="identifier"
                v-model="form.identifier"
                type="text"
                class="mt-1 block w-full"
                required
              />
              <InputError :message="errors.identifier" class="mt-2" />
            </div>

            <div>
              <InputLabel for="vehicle_type_id" value="Type de Véhicule" />
              <select
                id="vehicle_type_id"
                v-model="form.vehicle_type_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                required
              >
                <option value="">Sélectionner un type</option>
                <option
                  v-for="type in vehicleTypes"
                  :key="type.id"
                  :value="type.id"
                >
                  {{ type.name }} ({{ type.seat_count }} places)
                </option>
              </select>
              <InputError :message="errors.vehicle_type_id" class="mt-2" />
            </div>

            <div>
              <InputLabel for="seat_count" value="Nombre de Places" />
              <TextInput
                id="seat_count"
                v-model="form.seat_count"
                type="number"
                min="1"
                class="mt-1 block w-full"
                required
              />
              <InputError :message="errors.seat_count" class="mt-2" />
            </div>
          </div>

          <template #secondary-actions>
            <Link
              :href="route('admin.vehicles.index')"
              class="text-gray-600 hover:text-gray-800 px-4 py-2 dark:text-slate-400 dark:hover:text-slate-300"
            >
              Annuler
            </Link>
          </template>
          
          <template #actions>
            <button
              type="submit"
              :disabled="processing"
              class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center space-x-2"
            >
              <Loader v-if="processing" class="w-4 h-4 animate-spin" />
              <span>{{ vehicle ? 'Mettre à jour' : 'Créer' }}</span>
            </button>
          </template>
        </FormPanel>
      </div>
    </div>
  </MainNavLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FormPanel from '@/Components/FormPanel.vue';
import Loader from 'vue-material-design-icons/Loading.vue';

const props = defineProps({
  vehicle: {
    type: Object,
    default: null
  },
  vehicleTypes: {
    type: Array,
    default: () => []
  },
  errors: {
    type: Object,
    default: () => ({})
  }
});

// State
const processing = ref(false);

const form = ref({
  identifier: '',
  vehicle_type_id: '',
  seat_count: ''
});

// Initialize form with vehicle data if editing
onMounted(() => {
  if (props.vehicle) {
    form.value = {
      identifier: props.vehicle.identifier,
      vehicle_type_id: props.vehicle.vehicle_type_id,
      seat_count: props.vehicle.seat_count.toString()
    };
  }
});

const submit = () => {
  processing.value = true;

  const url = props.vehicle
    ? route('admin.vehicles.update', props.vehicle.id)
    : route('admin.vehicles.store');

  router.post(url, form.value, {
    onSuccess: () => {
      processing.value = false;
    },
    onError: () => {
      processing.value = false;
    }
  });
};
</script>

