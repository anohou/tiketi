<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
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
import City from 'vue-material-design-icons/City.vue'; // Using City icon or MapMarker
import TrainCar from 'vue-material-design-icons/TrainCar.vue'; // Icon for stations
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';

const props = defineProps({
  destinations: {
    type: Object,
    default: () => ({ data: [] })
  },
  filters: {
    type: Object,
    default: () => ({ search: '' })
  }
});

const { exportToExcel, printList } = useExportPrint();

const destinationColumns = {
  name: 'Nom',
  region: 'Région',
  is_active: 'Statut',
  stations_count: 'Gares'
};

const handleExport = () => {
    const data = props.destinations.data.map(d => ({
        ...d,
        is_active: d.is_active ? 'Active' : 'Inactive'
    }));
    exportToExcel(data, destinationColumns, 'destinations');
};

const handlePrint = () => {
    const data = props.destinations.data.map(d => ({
        ...d,
        is_active: d.is_active ? 'Active' : 'Inactive'
    }));
    printList(data, destinationColumns, 'Liste des Destinations');
};

const search = ref(props.filters.search);
const selectedDestination = ref(null);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const errors = ref({});

const form = ref({
  name: '',
  city: '', // Optional distinct city name if different from Destination name
  region: '',
  is_active: true
});

watch(() => props.destinations, (newDestinations) => {
    if (selectedDestination.value) {
        const updated = newDestinations.data.find(d => d.id === selectedDestination.value.id);
        if (updated) {
            selectedDestination.value = updated;
        } else {
            // If the selected destination was deleted, reset selection
            selectedDestination.value = null;
        }
    }
}, { deep: true });

// Debounced search watcher
let timeout = null;
watch(search, (newSearch) => {
  clearTimeout(timeout);
  timeout = setTimeout(() => {
    router.get(route('admin.destinations.index'), { search: newSearch }, {
      preserveState: true,
      preserveScroll: true,
      replace: true
    });
  }, 300);
});

const openCreateModal = () => {
  isEditing.value = false;
  form.value = { name: '', city: '', region: '', is_active: true };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = (destination) => {
  selectedDestination.value = destination;
  isEditing.value = true;
  form.value = {
    name: destination.name,
    city: destination.city || '',
    region: destination.region || '',
    is_active: Boolean(destination.is_active)
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  errors.value = {};
  if (!isEditing.value) form.value = { name: '', city: '', region: '', is_active: true };
};

const submit = () => {
  processing.value = true;
  const url = isEditing.value
    ? route('admin.destinations.update', selectedDestination.value.id)
    : route('admin.destinations.store');
  
  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      closeModal();
      processing.value = false;
    },
    onError: (err) => {
      errors.value = err;
      processing.value = false;
    }
  });
};

const deleteDestination = (destination) => {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette destination ?')) return;
  router.delete(route('admin.destinations.destroy', destination.id));
};

const selectDestination = (destination) => {
  selectedDestination.value = destination;
};

// Auto-select first item if exists
// Station Management
const showStationModal = ref(false);
const isEditingStation = ref(false);
const stationForm = ref({
  id: '',
  name: '',
  code: '',
  city: '',
  address: '',
  active: true,
  destination_id: ''
});

const openAddStationModal = () => {
  if (!selectedDestination.value) return;
  isEditingStation.value = false;
  stationForm.value = {
    id: '',
    name: '',
    code: '',
    city: selectedDestination.value.name, // Default to destination name
    address: '',
    active: true,
    destination_id: selectedDestination.value.id
  };
  errors.value = {};
  showStationModal.value = true;
};

const openEditStationModal = (station) => {
  isEditingStation.value = true;
  stationForm.value = {
    id: station.id,
    name: station.name,
    code: station.code,
    city: station.city,
    address: station.address || '',
    active: Boolean(station.active),
    destination_id: station.destination_id
  };
  errors.value = {};
  showStationModal.value = true;
};

const closeStationModal = () => {
 showStationModal.value = false;
 stationForm.value = { id: '', name: '', code: '', city: '', address: '', active: true, destination_id: '' };
 errors.value = {};
};

const submitStation = () => {
  processing.value = true;
  const url = isEditingStation.value
    ? route('admin.stations.update', stationForm.value.id)
    : route('admin.stations.store');
  
  const method = isEditingStation.value ? 'put' : 'post';

  router[method](url, stationForm.value, {
    onSuccess: () => {
      closeStationModal();
      processing.value = false;
      // Refresh the selected destination to show new station? 
      // Inertia reload might handle it, but selectedDestination ref might be stale.
      // We rely on the watcher on props.destinations to update selectedDestination.
    },
    onError: (err) => {
      errors.value = err;
      processing.value = false;
    }
  });
};

const deleteStation = (station) => {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette gare ?')) return;
  router.delete(route('admin.stations.destroy', station.id), {
      onSuccess: () => {
          // ensure UI updates if needed
      }
  });
};

import { onMounted } from 'vue';
// No auto-selection by default
onMounted(() => {
    // Keep list unselected initially
});
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-blue-100 rounded-xl">
              <City class="text-blue-600" :size="28" />
            </div>
            Gestion des Destinations
          </h1>
          <p class="text-gray-500 mt-1">Gérez les destinations desservies</p>
        </div>
      </div>

      <!-- Content Grid -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Sidebar Menu - Fixed height same as parent -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column: List of Destinations - Scrollable internal lane -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full overflow-hidden">
             <!-- List Header -->
             <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 shrink-0">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="relative flex-1">
                        <input v-model="search" type="text" placeholder="Rechercher..."
                               class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                        <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                    </div>
                    <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouvelle Destination">
                       <Plus class="h-5 w-5" />
                    </button>
                </div>
                <div class="flex justify-end">
                    <ExportPrintButtons 
                      :disabled="destinations.data.length === 0"
                      small
                      @export="handleExport"
                      @print="handlePrint"
                    />
                </div>
             </div>

             <!-- Destination List - This is the scrollable part -->
             <div class="overflow-y-auto flex-1 custom-scrollbar">
                <div v-if="destinations.data.length === 0" class="p-4 text-center text-gray-500">
                    Aucune destination trouvée.
                </div>
                <div v-else>
                    <div v-for="dest in destinations.data" :key="dest.id" 
                         @click="selectDestination(dest)"
                         class="p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0"
                         :style="{
                            backgroundColor: selectedDestination?.id === dest.id ? '#f0fdf4' : '#ffffff',
                            borderLeft: selectedDestination?.id === dest.id ? '4px solid #16a34a' : '4px solid #fed7aa'
                         }">
                         
                         <div class="flex justify-between items-start">
                            <div class="flex-1 min-w-0">
                                 <h3 :class="['font-semibold truncate', selectedDestination?.id === dest.id ? 'text-green-800' : 'text-gray-800']">{{ dest.name }}</h3>
                                 <p class="text-xs text-gray-500 mt-1">
                                     {{ dest.region || 'Région non définie' }}
                                 </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs text-gray-400">{{ dest.stations_count || 0 }} gares</span>
                                <span :class="['px-2 py-0.5 rounded-full text-[10px] font-medium', dest.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800']">
                                    {{ dest.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                         </div>
                    </div>
                </div>
             </div>
             
             <!-- Simple Pagination -->
             <div v-if="destinations.links && destinations.links.length > 3" class="p-3 border-t border-orange-200 bg-gray-50 shrink-0">
                 <div class="flex justify-center gap-1">
                     <template v-for="(link, k) in destinations.links" :key="k">
                        <button v-if="link.url && !link.label.includes('Previous') && !link.label.includes('Next')" 
                                @click="router.visit(link.url, { preserveState: true })"
                                :class="['px-2 py-1 text-xs rounded', link.active ? 'bg-green-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-100']"
                                v-html="link.label">
                        </button>
                     </template>
                 </div>
             </div>
          </div>
        </div>

        <!-- Right Content: Details View - Independent Scroll -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar">
             <!-- Empty State -->
             <div v-if="!selectedDestination" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
                 <div class="p-4 bg-orange-50 rounded-full mb-4">
                     <City class="h-16 w-16 text-orange-200" />
                 </div>
                 <p class="text-lg font-medium">Sélectionnez une destination</p>
                 <p class="text-sm">Cliquez sur une destination à gauche pour voir les détails.</p>
             </div>

             <div v-else class="space-y-4">
                 <!-- Details Card -->
                 <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">{{ selectedDestination.name }}</h2>
                            <p class="text-sm text-gray-500 flex items-center gap-1">
                                <MapMarkerRadius :size="14"/> {{ selectedDestination.region || 'Région non définie' }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                             <span :class="['px-3 py-1 rounded-full text-xs font-semibold', selectedDestination.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800']">
                                {{ selectedDestination.is_active ? 'Active' : 'Inactive' }}
                             </span>
                             <button @click="openEditModal(selectedDestination)" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                                <Pencil class="h-5 w-5" />
                             </button>
                             <button @click="deleteDestination(selectedDestination)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                <Trash2 class="h-5 w-5" />
                             </button>
                        </div>
                    </div>
                </div>

                <!-- Stations List -->
                <div class="bg-white rounded-lg border border-orange-200 shadow-sm overflow-hidden">
                    <div class="p-3 bg-gray-50 border-b border-orange-200 flex justify-between items-center">
                        <h3 class="font-bold text-gray-800 flex items-center gap-2">
                            <TrainCar class="text-orange-500" /> Gares associées 
                            <span class="bg-orange-100 text-orange-800 text-xs px-2 py-0.5 rounded-full">{{ selectedDestination.stations?.length || 0 }}</span>
                        </h3>
                        <button @click="openAddStationModal" class="p-1.5 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors text-xs font-bold flex items-center gap-1">
                            <Plus :size="16" /> Ajouter
                        </button>
                    </div>
                    
                    <div class="p-4">
                        <div v-if="selectedDestination.stations && selectedDestination.stations.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div v-for="station in selectedDestination.stations" :key="station.id" class="p-3 border border-orange-100 rounded-lg bg-orange-50/30 hover:border-orange-300 transition-colors relative group">
                                
                                <div class="font-bold text-gray-900">{{ station.name }}</div>
                                <div class="text-xs text-gray-500 mt-1">{{ station.address || 'Aucune adresse' }}</div>
                                <div class="mt-2 text-xs flex gap-2">
                                    <span v-if="station.phone" class="bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded">{{ station.phone }}</span>
                                    <span :class="['px-1.5 py-0.5 rounded text-[10px]', station.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800']">{{ station.active ? 'Active' : 'Inactif' }}</span>
                                </div>

                                <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
                                    <button @click.stop="openEditStationModal(station)" class="p-1 bg-white border border-gray-200 rounded text-blue-600 hover:bg-blue-50 shadow-sm">
                                        <Pencil :size="14" />
                                    </button>
                                    <button @click.stop="deleteStation(station)" class="p-1 bg-white border border-gray-200 rounded text-red-600 hover:bg-red-50 shadow-sm">
                                        <Trash2 :size="14" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8 text-gray-400">
                            <p>Aucune gare n'est associée à cette destination.</p>
                            <button @click="openAddStationModal" class="text-green-600 text-sm font-bold hover:underline mt-2 inline-block">
                                Créer une gare
                            </button>
                        </div>
                    </div>
                </div>
             </div>
        </div>
      </div>
    </div>

    <!-- Modal Destination -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier la Destination' : 'Nouvelle Destination' }}
      </template>
      <template #content>
        <div class="space-y-4">
           <div>
             <InputLabel for="name" value="Nom de la Destination" />
             <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Abidjan" />
             <InputError :message="errors.name" />
           </div>
           
           <div class="grid grid-cols-2 gap-4">
               <div>
                 <InputLabel for="region" value="Région (Optionnel)" />
                 <TextInput v-model="form.region" id="region" class="w-full" placeholder="Ex: Lagunes" />
                 <InputError :message="errors.region" />
               </div>
               <div class="flex items-center pt-6">
                 <input type="checkbox" v-model="form.is_active" id="active" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                 <label for="active" class="ml-2 text-sm text-gray-600">Destination Active</label>
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

    <!-- Modal Station -->
    <DialogModal :show="showStationModal" @close="closeStationModal" maxWidth="md">
      <template #title>
        {{ isEditingStation ? 'Modifier la Gare' : 'Nouvelle Gare' }}
      </template>
      <template #content>
        <div class="space-y-4">
           <div class="grid grid-cols-2 gap-4">
                <div>
                   <InputLabel for="station_name" value="Nom de la Gare" />
                   <TextInput v-model="stationForm.name" id="station_name" class="w-full" placeholder="Ex: Gare Nord" />
                   <InputError :message="errors.name" />
                </div>
                <div>
                   <InputLabel for="station_code" value="Code" />
                   <TextInput v-model="stationForm.code" id="station_code" class="w-full" placeholder="Ex: G-NO" />
                   <InputError :message="errors.code" />
                </div>
           </div>

           <div>
             <InputLabel for="station_city" value="Ville" />
             <TextInput v-model="stationForm.city" id="station_city" class="w-full" />
             <InputError :message="errors.city" />
           </div>

           <div>
             <InputLabel for="station_address" value="Adresse" />
             <TextInput v-model="stationForm.address" id="station_address" class="w-full" />
             <InputError :message="errors.address" />
           </div>
           
           <div class="flex items-center">
             <input type="checkbox" v-model="stationForm.active" id="station_active" class="rounded border-gray-300 text-green-600 shadow-sm focus:ring-green-500">
             <label for="station_active" class="ml-2 text-sm text-gray-600">Gare Active</label>
           </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeStationModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submitStation" :disabled="processing">
          {{ isEditingStation ? 'Mettre à jour' : 'Enregistrer' }}
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
