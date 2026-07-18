<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import GpsMapPicker from '@/Components/GpsMapPicker.vue';
import StationFormModal from '@/Components/StationFormModal.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import City from 'vue-material-design-icons/City.vue'; // Using City icon or MapMarker
import TrainCar from 'vue-material-design-icons/TrainCar.vue'; // Icon for stations
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';

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
  description: 'Description',
  region: 'Région',
  latitude: 'Latitude',
  longitude: 'Longitude',
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
    printList(data, destinationColumns, 'Liste des Villes');
};

const search = ref(props.filters.search);
const selectedDestination = ref(null);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const errors = ref({});

const form = ref({
  name: '',
  description: '', // Description of the Destination/City
  region: '',
  latitude: '',
  longitude: '',
  is_active: true
});

const destinationCoordinates = computed({
  get: () => ({
    latitude: form.value.latitude,
    longitude: form.value.longitude,
  }),
  set: (value) => {
    form.value.latitude = value?.latitude ?? '';
    form.value.longitude = value?.longitude ?? '';
  },
});

const destinationPreviewCoordinates = computed(() => {
  if (!selectedDestination.value) {
    return { latitude: '', longitude: '' };
  }

  return {
    latitude: selectedDestination.value.latitude ?? '',
    longitude: selectedDestination.value.longitude ?? '',
  };
});

const mapCenter = computed(() => {
  if (selectedDestination.value) {
    return {
      latitude: Number(selectedDestination.value.latitude) || 7.177201,
      longitude: Number(selectedDestination.value.longitude) || -5.635986
    };
  }
  return { latitude: 7.177201, longitude: -5.635986 };
});

const mapZoom = computed(() => {
  return selectedDestination.value ? 13 : 6;
});

const destinationPreviewPoints = computed(() => {
  return (props.destinations.data || [])
    .map((destination) => ({
      latitude: destination.latitude,
      longitude: destination.longitude,
      label: destination.name,
    }))
    .filter((point) => Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude)));
});

const referenceStations = computed(() => {
  const stations = selectedDestination.value?.stations?.length
    ? selectedDestination.value.stations
    : props.destinations.data.flatMap((destination) => destination.stations || []);

  return stations
    .map((station) => ({
      latitude: station.latitude,
      longitude: station.longitude,
      label: `${station.name}${station.city ? ` - ${station.city}` : ''}`,
    }))
    .filter((point) => Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude)));
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
  form.value = { name: '', description: '', region: '', latitude: '', longitude: '', is_active: true };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = (destination) => {
  selectedDestination.value = destination;
  isEditing.value = true;
  form.value = {
    name: destination.name,
    description: destination.description || '',
    region: destination.region || '',
    latitude: destination.latitude ?? '',
    longitude: destination.longitude ?? '',
    is_active: Boolean(destination.is_active)
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateDestination = () => {
  if (!selectedDestination.value) return;
  isEditing.value = false;
  form.value = {
    name: selectedDestination.value.name + ' (Copie)',
    description: selectedDestination.value.description || '',
    region: selectedDestination.value.region || '',
    latitude: selectedDestination.value.latitude ?? '',
    longitude: selectedDestination.value.longitude ?? '',
    is_active: true
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  errors.value = {};
  if (!isEditing.value) form.value = { name: '', description: '', region: '', latitude: '', longitude: '', is_active: true };
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

const showDeleteDestModal = ref(false);
const destToDelete = ref(null);

const confirmDeleteDestination = (destination) => {
  destToDelete.value = destination;
  showDeleteDestModal.value = true;
};

const deleteDestination = () => {
  if (!destToDelete.value) return;
  showDeleteDestModal.value = false;
  router.delete(route('admin.destinations.destroy', destToDelete.value.id), {
    onSuccess: () => {
      destToDelete.value = null;
    }
  });
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
  latitude: '',
  longitude: '',
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
    latitude: '',
    longitude: '',
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
    latitude: station.latitude ?? '',
    longitude: station.longitude ?? '',
    active: Boolean(station.active),
    destination_id: station.destination_id
  };
  errors.value = {};
  showStationModal.value = true;
};

const closeStationModal = () => {
 showStationModal.value = false;
 stationForm.value = { id: '', name: '', code: '', city: '', address: '', latitude: '', longitude: '', active: true, destination_id: '' };
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

const showDeleteStationModal = ref(false);
const stationToDelete = ref(null);

const confirmDeleteStation = (station) => {
  stationToDelete.value = station;
  showDeleteStationModal.value = true;
};

const deleteStation = () => {
  if (!stationToDelete.value) return;
  showDeleteStationModal.value = false;
  router.delete(route('admin.stations.destroy', stationToDelete.value.id), {
    onSuccess: () => {
      stationToDelete.value = null;
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
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <City class="text-emerald-600" :size="28" />
            </div>
            Gestion des Villes
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Gérez les villes desservies</p>
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
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
             <!-- List Header -->
             <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="relative flex-1">
                        <input v-model="search" type="text" placeholder="Rechercher..."
                               class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100">
                        <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                    </div>
                    <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle Ville">
                       <Plus class="h-5 w-5" />
                    </button>
                    <ExportPrintButtons 
                      :disabled="destinations.data.length === 0"
                      @export="handleExport"
                      @print="handlePrint"
                    />
                </div>

             </div>

              <div class="overflow-y-auto flex-1 custom-scrollbar">
                <div v-if="destinations.data.length === 0" class="p-4">
                    <EmptyState
                      title="Aucune ville trouvée"
                      message="Vous pouvez en créer une en cliquant sur le bouton '+'"
                      :icon="City"
                    />
                </div>
                <div v-else>
                    <div v-for="dest in destinations.data" :key="dest.id" 
                         @click="selectDestination(dest)"
                         class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                         :class="[selectedDestination?.id === dest.id ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']">
                         
                         <div class="flex justify-between items-start">
                            <div class="flex-1 min-w-0">
                                 <div class="flex items-center gap-2">
                                     <h3 :class="['font-semibold truncate', selectedDestination?.id === dest.id ? 'text-emerald-800' : 'text-slate-800 dark:text-slate-200']">{{ dest.name }}</h3>
                                     <MapMarkerRadius v-if="dest.latitude != null && dest.longitude != null" class="text-emerald-500 dark:text-emerald-400 shrink-0" :size="14" title="Coordonnées GPS disponibles" />
                                 </div>
                                 <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                     {{ dest.description ? dest.description + ' - ' : '' }}{{ dest.region || 'Région non définie' }}
                                 </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs text-orange-400">{{ dest.stations_count || 0 }} gares</span>
                                <span :class="['px-2 py-0.5 rounded-full text-[10px] font-medium', dest.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800']">
                                    {{ dest.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                         </div>
                    </div>
                </div>
             </div>
             
             <!-- Simple Pagination -->
             <div v-if="destinations.links && destinations.links.length > 3" class="p-3 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 shrink-0">
                 <div class="flex justify-center gap-1">
                     <template v-for="(link, k) in destinations.links" :key="k">
                        <button v-if="link.url && !link.label.includes('Previous') && !link.label.includes('Next')" 
                                @click="router.visit(link.url, { preserveState: true })"
                                :class="['px-2 py-1 text-xs rounded', link.active ? 'bg-emerald-600 text-white' : 'bg-white border border-slate-300 text-slate-600 dark:text-slate-350 dark:text-slate-350 hover:bg-slate-100']"
                                v-html="link.label">
                        </button>
                     </template>
                 </div>
             </div>
          </div>
        </div>

        <!-- Right Content: Details View - Independent Scroll -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar">
          <div class="space-y-4">
             <div v-if="selectedDestination" class="space-y-4">
                 <!-- Details Card -->
                <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ selectedDestination.name }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 flex items-center gap-1">
                                <MapMarkerRadius :size="14"/> {{ selectedDestination.description }}{{ selectedDestination.region ? ' (' + selectedDestination.region + ')' : '' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                             <span :class="['px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide', selectedDestination.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800']">
                                {{ selectedDestination.is_active ? 'Active' : 'Inactive' }}
                             </span>
                             <button @click="duplicateDestination" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                                <ContentCopy class="h-5 w-5" />
                             </button>
                             <button @click="openEditModal(selectedDestination)" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                                <Pencil class="h-5 w-5" />
                             </button>
                             <button @click="confirmDeleteDestination(selectedDestination)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                                <Trash2 class="h-5 w-5" />
                             </button>
                        </div>
                    </div>
                </div>

                <!-- Stations List -->
                <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="p-3 bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <h3 class="font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200 flex items-center gap-2">
                            <TrainCar class="text-emerald-500" /> Gares associées 
                            <span class="bg-emerald-100 text-emerald-800 text-xs px-2 py-0.5 rounded-full">{{ selectedDestination.stations?.length || 0 }}</span>
                        </h3>
                        <button @click="openAddStationModal" class="p-1.5 bg-emerald-100 text-emerald-700 rounded-lg hover:bg-emerald-200 transition-colors text-xs font-bold flex items-center gap-1">
                            <Plus :size="16" /> Ajouter
                        </button>
                    </div>
                    
                    <div class="p-4">
                        <div v-if="selectedDestination.stations && selectedDestination.stations.length > 0" class="grid grid-cols-1 gap-2">
                            <div v-for="station in selectedDestination.stations" :key="station.id" class="px-4 py-2 border border-slate-100 dark:border-slate-800 rounded-xl bg-slate-50 dark:bg-slate-950/30 hover:border-slate-300 dark:hover:border-slate-700 transition-colors relative group flex items-center justify-between gap-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    <!-- Status Indicator Dot -->
                                    <div class="w-2.5 h-2.5 rounded-full shrink-0" :class="station.active ? 'bg-emerald-500' : 'bg-rose-500'"></div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-slate-900 dark:text-slate-100 text-sm truncate">{{ station.name }}</div>
                                            <span v-if="station.code" class="px-1.5 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[10px] font-bold rounded uppercase tracking-wider shrink-0 border border-slate-200 dark:border-slate-800">{{ station.code }}</span>
                                            <span v-if="station.phone" class="text-[10px] text-slate-500 dark:text-slate-400 font-medium px-1.5 py-0.5 bg-slate-100 dark:bg-slate-800 rounded-md shrink-0">{{ station.phone }}</span>
                                        </div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500 truncate mt-0.5">{{ station.address || 'Aucune adresse' }}</div>
                                    </div>
                                </div>
                                
                                <div class="flex items-center gap-3 shrink-0 mr-8">
                                    <span :class="['px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider', station.active ? 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-400' : 'bg-rose-100 dark:bg-rose-950/40 text-rose-800 dark:text-rose-450']">
                                        {{ station.active ? 'Active' : 'Inactif' }}
                                    </span>
                                </div>

                                <!-- Action Buttons -->
                                <div class="absolute right-3.5 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1 bg-slate-50 dark:bg-slate-950/80 pl-2">
                                    <button @click.stop="openEditStationModal(station)" class="p-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-slate-850 shadow-sm" title="Modifier">
                                        <Pencil :size="13" />
                                    </button>
                                    <button @click.stop="confirmDeleteStation(station)" class="p-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-slate-850 shadow-sm" title="Supprimer">
                                        <Trash2 :size="13" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="py-4">
                            <EmptyState
                              title="Aucune gare associée"
                              message="Aucune gare n'est associée à cette destination."
                              actionText="Créer une gare"
                              @action="openAddStationModal"
                              :icon="TrainCar"
                            />
                        </div>
                    </div>
                </div>

             </div>

             <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
               <div class="flex items-center justify-between gap-3 mb-3">
                 <div>
                   <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200">
                     Carte des villes
                   </h2>
                   <p class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                     Les villes déjà enregistrées sur la carte.
                   </p>
                 </div>
                 <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">
                   {{ destinationPreviewPoints.length }} points
                 </span>
               </div>

               <GpsMapPicker
                 :modelValue="destinationPreviewCoordinates"
                 :reference-points="destinationPreviewPoints"
                 :interactive="false"
                 :visible="true"
                 height="360px"
                 :center="mapCenter"
                 :zoom="mapZoom"
                 :fit-bounds-max-zoom="14"
               />
             </div>
         </div>
        </div>
      </div>
  </div>

    <!-- Modal Destination -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="6xl">
      <template #title>
        {{ isEditing ? 'Modifier la Ville' : 'Nouvelle Ville' }}
      </template>
      <template #content>
        <div class="grid gap-6 lg:grid-cols-2 items-stretch">
           <div class="space-y-4 min-h-[520px]">
             <div>
               <InputLabel for="name" value="Nom de la Ville" />
               <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Abidjan" />
               <InputError :message="errors.name" />
             </div>

             <div class="grid grid-cols-2 gap-4">
                 <div>
                    <InputLabel for="description" value="Description" />
                    <TextArea v-model="form.description" id="description" class="w-full" placeholder="Ex: Métropole économique" />
                    <InputError :message="errors.description" />
                 </div>
                 <div>
                   <InputLabel for="region" value="Région (Optionnel)" />
                   <TextInput v-model="form.region" id="region" class="w-full" placeholder="Ex: Lagunes" />
                   <InputError :message="errors.region" />
                 </div>
             </div>

             <div class="grid grid-cols-2 gap-4">
                 <div>
                   <InputLabel for="latitude" value="Latitude (Optionnel)" />
                   <TextInput v-model="form.latitude" id="latitude" type="number" step="any" class="w-full" placeholder="Ex: 5.35995" />
                   <InputError :message="errors.latitude" />
                 </div>
                 <div>
                   <InputLabel for="longitude" value="Longitude (Optionnel)" />
                   <TextInput v-model="form.longitude" id="longitude" type="number" step="any" class="w-full" placeholder="Ex: -4.00826" />
                   <InputError :message="errors.longitude" />
                 </div>
             </div>

             <div class="flex items-center">
                    <input type="checkbox" v-model="form.is_active" id="active" class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                    <label for="active" class="ml-2 text-sm text-slate-600 dark:text-slate-350 dark:text-slate-350">Ville Active</label>
             </div>
           </div>

           <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex flex-col h-full">
             <InputLabel value="Coordonnées GPS (cliquer sur la carte)" />
             <div class="mt-3 flex-1">
               <GpsMapPicker
                 v-model="destinationCoordinates"
                 :visible="showModal"
                 height="520px"
                 :reference-points="referenceStations"
                 :center="{
                   latitude: Number(form.latitude) || 7.177201,
                   longitude: Number(form.longitude) || -5.635986
                 }"
               />
             </div>
             <InputError class="mt-2" :message="errors.latitude || errors.longitude" />
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

    <StationFormModal
      :show="showStationModal"
      :title="isEditingStation ? 'Modifier la Gare' : 'Nouvelle Gare'"
      :form="stationForm"
      :errors="errors"
      :processing="processing"
      :reference-points="selectedDestination?.stations || []"
      :center="{
        latitude: Number(stationForm.latitude) || Number(selectedDestination?.latitude) || 7.177201,
        longitude: Number(stationForm.longitude) || Number(selectedDestination?.longitude) || -5.635986
      }"
      :map-visible="showStationModal"
      destination-mode="hidden"
      @close="closeStationModal"
      @submit="submitStation"
    />

    <!-- Custom Confirmation Modals -->
    <ConfirmationModal :show="showDeleteDestModal" @close="showDeleteDestModal = false">
        <template #title>Supprimer la destination</template>
        <template #content>Êtes-vous sûr de vouloir supprimer cette destination ? Cette action supprimera également toutes les gares associées de manière définitive.</template>
        <template #footer>
            <SecondaryButton @click="showDeleteDestModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="deleteDestination">Oui, Supprimer</DangerButton>
        </template>
    </ConfirmationModal>

    <ConfirmationModal :show="showDeleteStationModal" @close="showDeleteStationModal = false">
        <template #title>Supprimer la gare</template>
        <template #content>Êtes-vous sûr de vouloir supprimer cette gare ? Cette action est irréversible.</template>
        <template #footer>
            <SecondaryButton @click="showDeleteStationModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="deleteStation">Oui, Supprimer</DangerButton>
        </template>
    </ConfirmationModal>
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
