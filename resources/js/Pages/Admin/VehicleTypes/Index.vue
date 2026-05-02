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
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  name: '',
  seat_count: '',
  seat_configuration: '2+2',
  door_positions_text: '',
  last_row_seats: '',
  active: true
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
    last_row_seats: '',
    active: true
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
    last_row_seats: selectedVehicleType.value.last_row_seats ? selectedVehicleType.value.last_row_seats.toString() : '',
    active: selectedVehicleType.value.active !== undefined ? Boolean(selectedVehicleType.value.active) : true
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateVehicleType = () => {
  if (!selectedVehicleType.value) return;
  isEditing.value = false; // It's a new creation
  form.value = {
    name: selectedVehicleType.value.name + ' (Copie)',
    seat_count: selectedVehicleType.value.seat_count.toString(),
    seat_configuration: selectedVehicleType.value.seat_configuration || '2+2',
    door_positions_text: selectedVehicleType.value.door_positions ? selectedVehicleType.value.door_positions.join(', ') : '',
    last_row_seats: selectedVehicleType.value.last_row_seats ? selectedVehicleType.value.last_row_seats.toString() : '',
    active: true
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
    last_row_seats: '',
    active: true
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

// Live Preview Logic (Ported from SeatMapService.php)
const liveSeatMap = computed(() => {
  const seatCount = parseInt(form.value.seat_count) || 0;
  if (seatCount <= 0) return [];

  const configStr = form.value.seat_configuration || '2+2';
  const doorPositions = form.value.door_positions_text 
    ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
    : [];
  const lastRowSeats = parseInt(form.value.last_row_seats) || 5;

  const parts = configStr.split('+').map(Number);
  const leftCount = parts[0] || 2;
  const rightCount = parts[1] || 2;

  const seatMap = [];
  let currentSeatNum = 1;
  let rowIndex = 0;
  const seatsToFill = seatCount - lastRowSeats;
  let filledSeats = 0;
  const slotsPerRow = leftCount + rightCount;

  while (filledSeats < seatsToFill) {
    const row = [];
    const rowStartSlot = (rowIndex - 1) * slotsPerRow + 1;

    if (rowIndex === 0) {
      row.push({ type: 'driver', label: 'Chauffeur' });
      for (let i = 1; i < leftCount; i++) row.push({ type: 'empty' });
    } else {
      for (let i = 0; i < leftCount; i++) {
        const currentSlot = rowStartSlot + i;
        if (doorPositions.includes(currentSlot)) {
          row.push({ type: 'door' });
        } else if (filledSeats < seatsToFill) {
          row.push({ type: 'seat', number: (currentSeatNum++).toString() });
          filledSeats++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }

    row.push({ type: 'aisle' });

    if (rowIndex === 0) {
      if (doorPositions.includes(0)) {
        // Place empty cells first, then door at outer edge
        for (let i = 1; i < rightCount; i++) row.push({ type: 'empty' });
        row.push({ type: 'door', label: 'Porte' });
      } else {
        for (let i = 0; i < rightCount; i++) {
          if (filledSeats < seatsToFill) {
            row.push({ type: 'seat', number: (currentSeatNum++).toString() });
            filledSeats++;
          } else {
            row.push({ type: 'empty' });
          }
        }
      }
    } else {
      for (let i = 0; i < rightCount; i++) {
        const currentSlot = rowStartSlot + leftCount + i;
        if (doorPositions.includes(currentSlot)) {
          row.push({ type: 'door' });
        } else if (filledSeats < seatsToFill) {
          row.push({ type: 'seat', number: (currentSeatNum++).toString() });
          filledSeats++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }
    seatMap.push(row);
    rowIndex++;
  }

  const remaining = seatCount - filledSeats;
  if (remaining > 0) {
    const lastRow = [];
    for (let i = 0; i < remaining; i++) {
      lastRow.push({ type: 'seat', number: (currentSeatNum++).toString() });
    }
    seatMap.push(lastRow);
  }

  return seatMap;
});

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
  last_row_seats: 'Dernière Rangée'
};

const handleExport = () => {
  exportToExcel(filteredVehicleTypes.value, typeColumns, 'types-vehicules');
};

const handlePrint = () => {
  printList(filteredVehicleTypes.value, typeColumns, 'Liste des Types de Véhicules');
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
              <Car class="text-green-600" :size="28" />
            </div>
            Gestion des Types de Véhicules
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

        <!-- Middle Column - Vehicle Types List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 shrink-0">
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
              <div v-if="filteredVehicleTypes.length === 0" class="p-4 text-center text-gray-500">
                Aucun type de véhicule trouvé.
              </div>
              <div v-else>
                <div v-for="vehicleType in filteredVehicleTypes" :key="vehicleType.id" 
                  @click="selectVehicleType(vehicleType)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0"
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
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
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
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedVehicleType.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                  ]">
                    {{ selectedVehicleType.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="duplicateVehicleType" class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
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

              <!-- Seat Map Preview Section -->
              <div class="mt-8">
                <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-4">PLAN DE SIÈGES / SEAT MAP</span>
                <SeatMapPreview :seatMap="selectedVehicleType.seat_map" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="3xl">
      <template #title>
        {{ isEditing ? 'Modifier le Type de Véhicule' : 'Nouveau Type de Véhicule' }}
      </template>
      <template #content>
        <div class="space-y-6">
          <!-- Top Section: Form Fields -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
            <div class="md:col-span-1">
              <InputLabel for="name" value="Nom du Type" />
              <TextInput v-model="form.name" id="name" class="w-full text-sm" placeholder="Ex: Autocar Standard" />
              <InputError :message="errors.name" />
            </div>

            <div class="grid grid-cols-2 gap-4 md:col-span-1">
              <div>
                <InputLabel for="seat_count" value="Places" />
                <TextInput v-model="form.seat_count" id="seat_count" type="number" min="1" class="w-full text-sm" />
                <InputError :message="errors.seat_count" />
              </div>
              <div>
                <InputLabel for="seat_configuration" value="Config" />
                <TextInput v-model="form.seat_configuration" id="seat_configuration" class="w-full text-sm" placeholder="2+2" />
                <InputError :message="errors.seat_configuration" />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4 md:col-span-1">
              <div>
                <InputLabel for="door_positions" value="Portes" />
                <TextInput v-model="form.door_positions_text" id="door_positions" class="w-full text-sm" placeholder="1, 23" />
                <InputError :message="errors.door_positions" />
              </div>
              <div>
                <InputLabel for="last_row_seats" value="Dernière R." />
                <TextInput v-model="form.last_row_seats" id="last_row_seats" type="number" class="w-full text-sm" placeholder="5" />
                <InputError :message="errors.last_row_seats" />
              </div>
            </div>

            <div class="flex items-center">
              <input type="checkbox" v-model="form.active" id="type_active" class="rounded border-gray-300 text-green-600 shadow-sm focus:ring-green-500">
              <label for="type_active" class="ml-2 text-sm text-gray-600">Type de Véhicule Actif</label>
            </div>
          </div>

          <!-- Bottom Section: Horizontal Live Preview -->
          <div class="bg-gray-50/50 rounded-2xl border border-gray-100 p-4 flex flex-col h-[400px]">
            <div class="flex items-center justify-between mb-3 shrink-0">
              <span class="text-[10px] text-gray-400 uppercase tracking-widest font-black">APERÇU DU PLAN DE SIÈGES (VUE HORIZONTALE)</span>
              <div v-if="liveSeatMap.length > 0" class="px-2 py-0.5 bg-green-100 text-green-700 text-[9px] font-black rounded uppercase">
                Généré en direct
              </div>
            </div>
            
            <div class="flex-1 overflow-auto custom-scrollbar flex items-center justify-center">
              <div v-if="liveSeatMap.length > 0" class="w-full py-2">
                <SeatMapPreview :seatMap="liveSeatMap" orientation="horizontal" />
              </div>
              <div v-else class="text-center text-gray-400">
                <p class="text-[10px]">Entrez les paramètres pour voir le plan</p>
              </div>
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
