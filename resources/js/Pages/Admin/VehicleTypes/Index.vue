<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Car from 'vue-material-design-icons/Car.vue';

const props = defineProps({
  vehicleTypes: {
    type: Object,
    default: () => ({ data: [] })
  }
});

// State
const search = ref('');
const selectedVehicleType = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  name: '',
  seat_count: '',
  seat_configuration: '2+2',
  door_positions_text: '',
  last_row_seats: ''
});

// Computed
const filteredVehicleTypes = computed(() => {
  const vehicleTypes = props.vehicleTypes?.data || [];
  if (!search.value) return vehicleTypes;

  const searchTerm = search.value.toLowerCase();
  return vehicleTypes.filter(vehicleType =>
    vehicleType.name.toLowerCase().includes(searchTerm)
  );
});

// Watchers
watch(() => props.vehicleTypes, (newVehicleTypes) => {
  if (selectedVehicleType.value) {
    const updatedType = newVehicleTypes.data.find(t => t.id === selectedVehicleType.value.id);
    if (updatedType) {
      selectedVehicleType.value = updatedType;
    }
  }
}, { deep: true });

// Methods
const isSelected = (vehicleType) => {
  if (!selectedVehicleType.value) return false;
  return selectedVehicleType.value.id === vehicleType.id;
};

const selectVehicleType = (vehicleType) => {
  selectedVehicleType.value = vehicleType;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    name: '',
    seat_count: '',
    seat_configuration: '2+2',
    door_positions_text: '',
    last_row_seats: ''
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedVehicleType.value) return;
  isEditing.value = true;
  form.value = {
    name: selectedVehicleType.value.name,
    seat_count: selectedVehicleType.value.seat_count.toString(),
    seat_configuration: selectedVehicleType.value.seat_configuration || '2+2',
    door_positions_text: selectedVehicleType.value.door_positions ? selectedVehicleType.value.door_positions.join(', ') : '',
    last_row_seats: selectedVehicleType.value.last_row_seats ? selectedVehicleType.value.last_row_seats.toString() : ''
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    name: '',
    seat_count: '',
    seat_configuration: '2+2',
    door_positions_text: '',
    last_row_seats: ''
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const payload = {
    ...form.value,
    door_positions: form.value.door_positions_text 
      ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
      : [],
    door_positions_text: undefined
  };

  const url = isEditing.value
    ? route('admin.vehicle-types.update', selectedVehicleType.value.id)
    : route('admin.vehicle-types.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, payload, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const deleteVehicleType = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce type de véhicule ?')) {
    router.delete(route('admin.vehicle-types.destroy', id), {
      onSuccess: () => {
        if (selectedVehicleType.value?.id === id) {
          selectedVehicleType.value = null;
        }
      },
      onError: (errorResponse) => {
        console.error('Error deleting vehicle type:', errorResponse);
      }
    });
  }
};
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4 h-[calc(100vh-80px)]">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Car class="text-green-600" :size="28" />
            </div>
            Gestion des Types de Véhicules
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 h-full">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Vehicle Types List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouveau Type">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1">
              <div v-if="filteredVehicleTypes.length === 0" class="p-4 text-center text-gray-500">
                Aucun type de véhicule trouvé.
              </div>
              <div v-else>
                <div v-for="vehicleType in filteredVehicleTypes" :key="vehicleType.id" 
                  @click="selectVehicleType(vehicleType)"
                  class="p-3 cursor-pointer transition-colors"
                  :style="{
                    backgroundColor: isSelected(vehicleType) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(vehicleType) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['font-semibold', isSelected(vehicleType) ? 'text-green-800' : 'text-gray-800']">{{ vehicleType.name }}</h3>
                      <p class="text-xs text-gray-500 mt-1">{{ vehicleType.seat_count }} sièges</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto pb-20">
          <!-- Empty State -->
          <div v-if="!selectedVehicleType" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <MapMarkerRadius class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez un type pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez un nouveau type
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800">{{ selectedVehicleType.name }}</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteVehicleType(selectedVehicleType.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">CAPACITÉ</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicleType.seat_count }} places
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">CONFIGURATION</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicleType.seat_configuration || '2+2' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">DERNIÈRE RANGÉE</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicleType.last_row_seats || 'Standard' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">PORTES</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicleType.door_positions?.join(', ') || 'Aucune' }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal">
      <template #title>
        {{ isEditing ? 'Modifier le Type de Véhicule' : 'Nouveau Type de Véhicule' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="name" value="Nom du Type" />
            <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Autocar Standard" />
            <InputError :message="errors.name" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="seat_count" value="Nombre de Places" />
              <TextInput v-model="form.seat_count" id="seat_count" type="number" min="1" class="w-full" />
              <InputError :message="errors.seat_count" />
            </div>
            <div>
              <InputLabel for="seat_configuration" value="Configuration (ex: 2+2)" />
              <TextInput v-model="form.seat_configuration" id="seat_configuration" class="w-full" placeholder="2+2" />
              <InputError :message="errors.seat_configuration" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="door_positions" value="Positions Portes (ex: 1, 23)" />
              <TextInput v-model="form.door_positions_text" id="door_positions" class="w-full" placeholder="1, 23" />
              <InputError :message="errors.door_positions" />
            </div>
            <div>
              <InputLabel for="last_row_seats" value="Sièges Dernière Rangée" />
              <TextInput v-model="form.last_row_seats" id="last_row_seats" type="number" class="w-full" placeholder="5" />
              <InputError :message="errors.last_row_seats" />
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submit" :disabled="processing">
          {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
        </PrimaryButton>
      </template>
    </DialogModal>
  </MainNavLayout>
</template>
