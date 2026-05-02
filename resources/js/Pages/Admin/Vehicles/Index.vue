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
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import Checkbox from '@/Components/Checkbox.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  vehicles: {
    type: Object,
    default: () => ({ data: [] })
  },
  vehicleTypes: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const selectedVehicle = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const showTrips = ref(false);

const form = ref({
  identifier: '',
  maker: '',
  vehicle_type_id: '',
  seat_count: '',
  active: true,
  inactive_reason: ''
});

// Computed
const filteredVehicles = computed(() => {
  const vehicles = props.vehicles?.data || [];
  if (!search.value) return vehicles;

  const searchTerm = search.value.toLowerCase();
  return vehicles.filter(vehicle =>
    vehicle.identifier.toLowerCase().includes(searchTerm) ||
    vehicle.maker?.toLowerCase().includes(searchTerm) ||
    vehicle.vehicle_type?.name.toLowerCase().includes(searchTerm)
  );
});

// Watchers
watch(() => props.vehicles, (newVehicles) => {
  if (selectedVehicle.value) {
    const updatedVehicle = newVehicles.data.find(v => v.id === selectedVehicle.value.id);
    if (updatedVehicle) {
      selectedVehicle.value = updatedVehicle;
    }
  }
}, { deep: true });

// Watch for vehicle type change to update seat count
watch(() => form.value.vehicle_type_id, (newTypeId) => {
  if (newTypeId) {
    const selectedType = props.vehicleTypes.find(t => t.id === newTypeId);
    if (selectedType) {
      form.value.seat_count = selectedType.seat_count.toString();
    }
  }
});

// Methods
const isSelected = (vehicle) => {
  if (!selectedVehicle.value) return false;
  return selectedVehicle.value.id === vehicle.id;
};

const selectVehicle = (vehicle) => {
  selectedVehicle.value = vehicle;
  showTrips.value = false;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    identifier: '',
    maker: '',
    vehicle_type_id: '',
    seat_count: '',
    active: true,
    inactive_reason: ''
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedVehicle.value) return;
  isEditing.value = true;
  form.value = {
    identifier: selectedVehicle.value.identifier,
    maker: selectedVehicle.value.maker,
    vehicle_type_id: selectedVehicle.value.vehicle_type_id,
    seat_count: selectedVehicle.value.seat_count.toString(),
    active: selectedVehicle.value.active !== false,
    inactive_reason: selectedVehicle.value.inactive_reason || ''
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateVehicle = () => {
  if (!selectedVehicle.value) return;
  isEditing.value = false;
  form.value = {
    identifier: selectedVehicle.value.identifier + ' (Copie)',
    maker: selectedVehicle.value.maker,
    vehicle_type_id: selectedVehicle.value.vehicle_type_id,
    seat_count: selectedVehicle.value.seat_count.toString(),
    active: true,
    inactive_reason: ''
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    identifier: '',
    maker: '',
    vehicle_type_id: '',
    seat_count: '',
    active: true,
    inactive_reason: ''
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.vehicles.update', selectedVehicle.value.id)
    : route('admin.vehicles.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
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

const deleteVehicle = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce véhicule ?')) {
    router.delete(route('admin.vehicles.destroy', id), {
      onSuccess: () => {
        if (selectedVehicle.value?.id === id) {
          selectedVehicle.value = null;
        }
      },
      onError: (errorResponse) => {
        console.error('Error deleting vehicle:', errorResponse);
      }
    });
  }
};

// Export/Print configuration
const vehicleColumns = {
  identifier: 'Immatriculation',
  maker: 'Fabricant',
  'vehicle_type.name': 'Type',
  seat_count: 'Places',
  trips_count: 'Voyages',
  active: 'Statut'
};

const handleExport = () => {
  const data = filteredVehicles.value.map(v => ({
    ...v,
    active: v.active ? 'Actif' : 'Inactif'
  }));
  exportToExcel(data, vehicleColumns, 'vehicules');
};

const handlePrint = () => {
  const data = filteredVehicles.value.map(v => ({
    ...v,
    active: v.active ? 'Actif' : 'Inactif'
  }));
  printList(data, vehicleColumns, 'Liste des Véhicules');
};

const getTripStatus = (trip) => {
  if (trip.status === 'scheduled') {
    const departure = new Date(trip.departure_at);
    if (departure < new Date()) {
      return 'expired';
    }
  }
  return trip.status;
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Bus class="text-green-600" :size="28" />
            </div>
            Gestion des Véhicules
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Vehicles List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouveau Véhicule">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              <div class="flex justify-end mt-2">
                <ExportPrintButtons 
                  :disabled="filteredVehicles.length === 0"
                  small
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredVehicles.length === 0" class="p-4 text-center text-gray-500">
                Aucun véhicule trouvé.
              </div>
              <div v-else>
                <div v-for="vehicle in filteredVehicles" :key="vehicle.id" 
                  @click="selectVehicle(vehicle)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0"
                  :style="{
                    backgroundColor: isSelected(vehicle) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(vehicle) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['text-base font-bold', isSelected(vehicle) ? 'text-green-800' : 'text-gray-800']">{{ vehicle.identifier }}</h3>
                      <p class="text-sm text-gray-500 mt-1">{{ vehicle.vehicle_type?.name }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        vehicle.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]">
                        {{ vehicle.active ? 'Actif' : 'Inactif' }}
                      </span>
                      <span class="text-[10px] text-gray-400">
                        {{ vehicle.trips_count || 0 }} voyages
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedVehicle" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <MapMarkerRadius class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez un véhicule pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez un nouveau véhicule
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800">{{ selectedVehicle.identifier }}</h2>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedVehicle.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                  ]">
                    {{ selectedVehicle.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="duplicateVehicle" class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteVehicle(selectedVehicle.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">FABRICANT</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicle.maker || 'Non spécifié' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">TYPE</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicle.vehicle_type?.name }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">CAPACITÉ</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedVehicle.seat_count }} places
                  </div>
                </div>
                
                <div class="col-span-12" v-if="!selectedVehicle.active">
                   <div class="p-4 rounded-lg bg-red-50 border border-red-100">
                      <span class="text-xs text-red-600 uppercase tracking-wider font-bold block mb-1">MOTIF D'INACTIVITÉ</span>
                      <p class="text-red-800">{{ selectedVehicle.inactive_reason || 'Raison non spécifiée' }}</p>
                   </div>
                </div>
              </div>
            </div>

            <!-- Trips/Voyages Section -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm overflow-hidden">
              <div @click="showTrips = !showTrips" class="p-3 bg-gray-50 flex items-center justify-between cursor-pointer hover:bg-gray-100">
                <div class="flex items-center gap-2">
                  <Bus class="h-5 w-5 text-blue-600" />
                  <h3 class="font-semibold text-gray-700">
                    Voyages ({{ selectedVehicle.trips_count || (selectedVehicle.trips || []).length }})
                  </h3>
                </div>
                <component :is="showTrips ? ChevronDown : ChevronRight" class="h-5 w-5 text-gray-400" />
              </div>
              
              <div v-if="showTrips" class="p-4 border-t border-orange-100">
                <div class="space-y-2">
                  <div v-if="!selectedVehicle.trips || selectedVehicle.trips.length === 0" class="text-sm text-gray-500 text-center py-2">
                    Aucun voyage avec ce véhicule.
                  </div>
                  <div v-for="trip in selectedVehicle.trips" :key="trip.id" 
                    class="flex items-center justify-between p-2 bg-gray-50 rounded-md border border-gray-100">
                    <div class="flex items-center gap-3">
                      <Bus class="h-5 w-5 text-blue-500" />
                      <div>
                        <p class="text-sm font-medium text-gray-800">{{ trip.route?.name || 'Route' }}</p>
                        <p class="text-xs text-gray-500">
                          {{ new Date(trip.departure_at).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                        </p>
                      </div>
                    </div>
                    <span :class="[
                      'px-2 py-0.5 rounded-full text-[10px] font-medium',
                      getTripStatus(trip) === 'scheduled' ? 'bg-blue-100 text-blue-800' :
                      getTripStatus(trip) === 'departed' ? 'bg-purple-100 text-purple-800' :
                      getTripStatus(trip) === 'arrived' ? 'bg-green-100 text-green-800' :
                      getTripStatus(trip) === 'cancelled' ? 'bg-red-100 text-red-800' :
                      getTripStatus(trip) === 'expired' ? 'bg-gray-100 text-gray-800' :
                      'bg-gray-100 text-gray-800'
                    ]">
                      {{ getTripStatus(trip) === 'scheduled' ? 'Programmé' :
                         getTripStatus(trip) === 'departed' ? 'Effectué' :
                         getTripStatus(trip) === 'arrived' ? 'Arrivé' :
                         getTripStatus(trip) === 'cancelled' ? 'Annulé' :
                         getTripStatus(trip) === 'expired' ? 'Passé' :
                         trip.status }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier le Véhicule' : 'Nouveau Véhicule' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="identifier" value="Numéro d'identification" />
            <TextInput v-model="form.identifier" id="identifier" class="w-full" placeholder="Ex: 1234 AB 01" />
            <InputError :message="errors.identifier" />
          </div>

          <div>
            <InputLabel for="maker" value="Fabricant" />
            <TextInput v-model="form.maker" id="maker" class="w-full" placeholder="Ex: Toyota" />
            <InputError :message="errors.maker" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="vehicle_type_id" value="Type de Véhicule" />
              <select
                id="vehicle_type_id"
                v-model="form.vehicle_type_id"
                class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
                required
              >
                <option value="">Sélectionner...</option>
                <option
                  v-for="type in vehicleTypes"
                  :key="type.id"
                  :value="type.id"
                >
                  {{ type.name }} ({{ type.seat_count }} pl.)
                </option>
              </select>
              <InputError :message="errors.vehicle_type_id" />
            </div>
            <div>
              <InputLabel for="seat_count" value="Nombre de Places" />
              <TextInput 
                v-model="form.seat_count" 
                id="seat_count" 
                type="number" 
                class="w-full bg-gray-100 font-bold cursor-not-allowed" 
                readonly
                placeholder="Dérivé du type..."
              />
              <InputError :message="errors.seat_count" />
            </div>
          </div>
          
          <div class="flex items-center">
             <label class="flex items-center text-sm text-gray-700 cursor-pointer">
                <Checkbox v-model:checked="form.active" />
                <span class="ml-2">Véhicule Actif</span>
             </label>
          </div>

          <div v-if="!form.active">
            <InputLabel for="inactive_reason" value="Motif d'inactivité" />
            <textarea
              id="inactive_reason"
              v-model="form.inactive_reason"
              rows="3"
              class="w-full px-3 py-2 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              placeholder="Expliquez pourquoi le véhicule est inactif (panne, garage, etc.)"
              required
            ></textarea>
            <InputError :message="errors.inactive_reason" />
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

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #fed7aa;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #fdba74;
}
</style>
