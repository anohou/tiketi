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

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Car from 'vue-material-design-icons/Car.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import SeatMapPreview from '@/Components/SeatMapPreview.vue';
import VehicleTypeFormFields from '@/Components/VehicleTypeFormFields.vue';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  vehicleTypes: {
    type: Object,
    default: () => ({ data: [] })
  }
});

// State
const search = ref('');
const selectedVehicleType = ref(null);
const modalVehicleType = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

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
  modalVehicleType.value = null;
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedVehicleType.value) return;
  isEditing.value = true;
  modalVehicleType.value = selectedVehicleType.value;
  errors.value = {};
  showModal.value = true;
};

const duplicateVehicleType = () => {
  if (!selectedVehicleType.value) return;
  isEditing.value = false; // It's a new creation
  modalVehicleType.value = {
    ...selectedVehicleType.value,
    name: selectedVehicleType.value.name + ' (Copie)',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  modalVehicleType.value = null;
  errors.value = {};
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

// Export/Print configuration
const typeColumns = {
  name: 'Nom',
  seat_count: 'Places',
  seat_configuration: 'Configuration',
  last_row_seats: 'Dernière Rangée',
  active: 'Statut'
};

const handleExport = () => {
  const data = filteredVehicleTypes.value.map(t => ({
    ...t,
    active: t.active ? 'Actif' : 'Inactif'
  }));
  exportToExcel(data, typeColumns, 'types-vehicules');
};

const handlePrint = () => {
  const data = filteredVehicleTypes.value.map(t => ({
    ...t,
    active: t.active ? 'Actif' : 'Inactif'
  }));
  printList(data, typeColumns, 'Liste des Types de Véhicules');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <Car class="text-emerald-600" :size="28" />
            </div>
            Gestion des Types de Véhicules
          </h1>
          <p class="text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Vehicle Types List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors" title="Nouveau Type">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              <div class="flex justify-end mt-2">
                <ExportPrintButtons 
                  :disabled="filteredVehicleTypes.length === 0"
                  small
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredVehicleTypes.length === 0" class="p-4 text-center text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                Aucun type de véhicule trouvé.
              </div>
              <div v-else>
                <div v-for="vehicleType in filteredVehicleTypes" :key="vehicleType.id" 
                  @click="selectVehicleType(vehicleType)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(vehicleType) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['font-semibold', isSelected(vehicleType) ? 'text-emerald-800' : 'text-slate-800 dark:text-slate-200 dark:text-slate-200']">{{ vehicleType.name }}</h3>
                      <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">{{ vehicleType.seat_count }} sièges</p>
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
          <div v-if="!selectedVehicleType" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
            <MapMarkerRadius class="h-16 w-16 text-slate-200 mb-4" />
            <p class="text-lg">Sélectionnez un type pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez un nouveau type
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ selectedVehicleType.name }}</h2>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedVehicleType.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]">
                    {{ selectedVehicleType.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="duplicateVehicleType" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
                  <button @click="openEditModal" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteVehicleType(selectedVehicleType.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">CAPACITÉ</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.seat_count }} places
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">CONFIGURATION</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.seat_configuration || '2+2' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">DERNIÈRE RANGÉE</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.last_row_seats ?? 'Standard' }}
                  </div>
                </div>
                <div class="col-span-4">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">PORTES</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.door_positions?.join(', ') || 'Aucune' }}
                  </div>
                </div>
                <div class="col-span-4">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">CÔTÉ</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.door_side === 'left' ? 'Gauche' : 'Droite' }}
                  </div>
                </div>
                <div class="col-span-4">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">LARGEUR</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicleType.door_width || 2 }} slots
                  </div>
                </div>
              </div>

              <!-- Seat Map Preview Section -->
              <div class="mt-8">
                <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-4">PLAN DE SIÈGES / SEAT MAP</span>
                <SeatMapPreview
                  :seatMap="selectedVehicleType.seat_map"
                  :seatConfiguration="selectedVehicleType.seat_configuration"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="6xl">
      <template #title>
        {{ isEditing ? 'Modifier le Type de Véhicule' : 'Nouveau Type de Véhicule' }}
      </template>
      <template #content>
        <VehicleTypeFormFields
          :vehicle-type="modalVehicleType"
          :errors="errors"
          :submit-url="isEditing ? route('admin.vehicle-types.update', selectedVehicleType.id) : route('admin.vehicle-types.store')"
          :submit-method="isEditing ? 'put' : 'post'"
          :back-url="route('admin.vehicle-types.index')"
          submit-label="Enregistrer"
          cancel-label="Annuler"
          :on-success="closeModal"
          :on-cancel="closeModal"
          :hide-header="true"
        />
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" type="submit" form="vehicle-type-form" :disabled="processing">
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
  background: #cbd5e1;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
