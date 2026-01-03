<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextArea from '@/Components/TextArea.vue';
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
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import Account from 'vue-material-design-icons/Account.vue';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  stations: {
    type: Object,
    default: () => ({ data: [] })
  },
  destinations: {
    type: Array, // Passed from controller
    default: () => []
  }
});

// State
const search = ref('');
const selectedStation = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const activeTab = ref('destinations');

const form = ref({
  code: '',
  name: '',
  city: '',
  address: '',
  active: true
});

// Tabs configuration - only related tables, not details
const tabs = [
  { id: 'destinations', label: 'Destinations', icon: MapMarkerRadius },
  { id: 'routes', label: 'Trajets', icon: Routes },
  { id: 'sellers', label: 'Vendeurs', icon: Account, countKey: 'user_assignments_count' },
];

// Computed
const filteredStations = computed(() => {
  const stations = props.stations?.data || [];
  if (!search.value) return stations;

  const searchTerm = search.value.toLowerCase();
  return stations.filter(station =>
    station.name.toLowerCase().includes(searchTerm) ||
    station.code?.toLowerCase().includes(searchTerm) ||
    station.city?.toLowerCase().includes(searchTerm)
  );
});

// Get all unique destinations that can be served from/to this station (bidirectional)
const servedDestinations = computed(() => {
  if (!selectedStation.value) return [];
  
  const destinationsMap = new Map();
  const stationId = selectedStation.value.id;
  
  // Find all routes that pass through stops belonging to this station
  const stationStops = selectedStation.value.stops || [];
  
  stationStops.forEach(stop => {
    const stopRouteOrders = stop.route_stop_orders || stop.routeStopOrders || [];
    
    stopRouteOrders.forEach(myStopOrder => {
      const route = myStopOrder.route;
      if (!route) return;
      
      const myIndex = myStopOrder.stop_index ?? myStopOrder.stopIndex ?? 0;
      
      // Get all stops on this route
      const allStopOrders = route.route_stop_orders || route.routeStopOrders || [];
      
      // Process all stops on this route (both forward and backward)
      allStopOrders.forEach(order => {
        const orderIndex = order.stop_index ?? order.stopIndex ?? 0;
        
        // Skip if it's the same position (our stop) or same station
        if (orderIndex === myIndex || !order.stop || order.stop.station_id === stationId) {
          return;
        }
        
        const stopId = order.stop.id;
        
        // Only add if not already in the map
        if (!destinationsMap.has(stopId)) {
          destinationsMap.set(stopId, {
            id: stopId,
            name: order.stop.name,
            city: order.stop.station?.city || 'N/A'
          });
        }
      });
    });
  });
  
  // Sort alphabetically by name
  return Array.from(destinationsMap.values()).sort((a, b) => {
    return a.name.localeCompare(b.name, 'fr');
  });
});

// Get all routes that pass through this station (via its stops)
const allRoutes = computed(() => {
  if (!selectedStation.value) return [];
  
  const routesMap = new Map();
  const stationStops = selectedStation.value.stops || [];
  
  stationStops.forEach(stop => {
    const stopRouteOrders = stop.route_stop_orders || stop.routeStopOrders || [];
    
    stopRouteOrders.forEach(stopOrder => {
      const route = stopOrder.route;
      if (route && !routesMap.has(route.id)) {
        routesMap.set(route.id, {
          id: route.id,
          name: route.name,
          origin: route.origin_station?.name || 'N/A',
          destination: route.destination_station?.name || 'N/A',
          active: route.active
        });
      }
    });
  });
  
  // Sort alphabetically by name
  return Array.from(routesMap.values()).sort((a, b) => {
    return a.name.localeCompare(b.name, 'fr');
  });
});

// Watchers
watch(() => props.stations, (newStations) => {
  if (selectedStation.value) {
    const updatedStation = newStations.data.find(s => s.id === selectedStation.value.id);
    if (updatedStation) {
      selectedStation.value = updatedStation;
    }
  }
}, { deep: true });

// Reset tab when selecting new station
watch(selectedStation, () => {
  activeTab.value = 'destinations';
});

// Methods
const isSelected = (station) => {
  if (!selectedStation.value) return false;
  return selectedStation.value.id === station.id;
};

const selectStation = (station) => {
  selectedStation.value = station;
};

const getTabCount = (tab) => {
  if (!selectedStation.value) return null;
  
  // Handle computed property counts
  if (tab.id === 'destinations') {
    return servedDestinations.value.length;
  }
  if (tab.id === 'routes') {
    return allRoutes.value.length;
  }
  
  // Handle backend counts
  if (tab.countKey) {
    return selectedStation.value[tab.countKey] || 0;
  }
  
  return null;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    code: '',
    name: '',
    destination_id: '', // New field
    city: '',
    address: '',
    active: true
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedStation.value) return;
  isEditing.value = true;
  form.value = {
    code: selectedStation.value.code,
    name: selectedStation.value.name,
    destination_id: selectedStation.value.destination_id, // Load existing
    city: selectedStation.value.city,
    address: selectedStation.value.address || '',
    active: selectedStation.value.active
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    code: '',
    name: '',
    destination_id: '',
    city: '',
    address: '',
    active: true
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.stations.update', selectedStation.value.id)
    : route('admin.stations.store');

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

const deleteStation = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cette station ?')) {
    router.delete(route('admin.stations.destroy', id), {
      onSuccess: () => {
        if (selectedStation.value?.id === id) {
          selectedStation.value = null;
        }
      },
      onError: (errorResponse) => {
        let errorMessage = 'Impossible de supprimer cette station.';
        if (errorResponse.message) {
          errorMessage = errorResponse.message;
        } else if (errorResponse.error) {
          errorMessage = errorResponse.error;
        }
        alert(errorMessage);
      }
    });
  }
};

// Export/Print configuration
const stationColumns = {
  code: 'Code',
  name: 'Nom',
  city: 'Ville',
  address: 'Adresse',
  active: 'Statut',
  user_assignments_count: 'Vendeurs'
};

const handleExport = () => {
  exportToExcel(filteredStations.value, stationColumns, 'stations');
};

const handlePrint = () => {
  printList(filteredStations.value, stationColumns, 'Liste des Stations');
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
              <OfficeBuilding class="text-green-600" :size="28" />
            </div>
            Gestion des Stations
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Stations List -->
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
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouvelle Station">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              <div class="flex justify-end">
                <ExportPrintButtons 
                  :disabled="filteredStations.length === 0"
                  small
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredStations.length === 0" class="p-4 text-center text-gray-500">
                Aucune station trouvée.
              </div>
              <div v-else>
                <div v-for="station in filteredStations" :key="station.id" 
                  @click="selectStation(station)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0"
                  :style="{
                    backgroundColor: isSelected(station) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(station) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['font-semibold truncate', isSelected(station) ? 'text-green-800' : 'text-gray-800']">{{ station.name }}</h3>
                      <p class="text-xs text-gray-500 mt-1">
                        {{ station.city }} ({{ station.code }})
                      </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                      <span class="text-xs text-gray-400">{{ station.user_assignments_count || 0 }} vendeurs</span>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        station.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]">
                        {{ station.active ? 'Active' : 'Inactive' }}
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
          <div v-if="!selectedStation" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <OfficeBuilding class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez une station pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez une nouvelle station
            </button>
          </div>

          <!-- View Details (when station selected) -->
          <div v-else class="space-y-4">
            <!-- Details Card (always visible) -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
              <div class="flex justify-between items-start mb-4">
                <div>
                  <h2 class="text-2xl font-bold text-gray-800">{{ selectedStation.name }}</h2>
                  <p class="text-sm text-gray-500">{{ selectedStation.city }} - {{ selectedStation.code }}</p>
                </div>
                <div class="flex gap-2">
                  <span :class="[
                    'px-3 py-1 rounded-full text-xs font-semibold',
                    selectedStation.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                  ]">
                    {{ selectedStation.active ? 'Active' : 'Inactive' }}
                  </span>
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteStation(selectedStation.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>
              
              <!-- Details Grid -->
              <div class="grid grid-cols-12 gap-4 pt-2 border-t border-gray-100">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-1">CODE</span>
                  <div class="text-lg font-medium text-gray-900">{{ selectedStation.code }}</div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-1">VILLE</span>
                  <div class="text-lg font-medium text-gray-900">{{ selectedStation.city }}</div>
                </div>
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-1">ADRESSE</span>
                  <div class="text-base text-gray-700">{{ selectedStation.address || 'Non renseignée' }}</div>
                </div>
              </div>
            </div>

            <!-- Related Tables Tabs -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm">
              <!-- Tab Headers -->
              <div class="flex border-b border-orange-200 overflow-x-auto">
                <button
                  v-for="tab in tabs"
                  :key="tab.id"
                  @click="activeTab = tab.id"
                  :class="[
                    'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                    activeTab === tab.id 
                      ? 'border-green-600 text-green-700 bg-green-50/50' 
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50'
                  ]"
                >
                  <component :is="tab.icon" class="h-4 w-4" />
                  {{ tab.label }}
                  <span 
                    v-if="getTabCount(tab) !== null"
                    :class="[
                      'px-1.5 py-0.5 rounded-full text-[10px] font-bold',
                      activeTab === tab.id ? 'bg-green-200 text-green-800' : 'bg-gray-200 text-gray-600'
                    ]"
                  >
                    {{ getTabCount(tab) }}
                  </span>
                </button>
              </div>

              <!-- Tab Content -->
              <div class="p-4">
                <!-- Destinations Tab -->
                <div v-if="activeTab === 'destinations'">
                  <div v-if="servedDestinations.length === 0" class="text-center py-6 text-gray-400">
                    Aucune destination configurée pour cette station
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="dest in servedDestinations" 
                      :key="dest.id"
                      class="flex items-center p-3 bg-gray-50 rounded-lg"
                    >
                      <OfficeBuilding class="h-6 w-6 text-orange-500 mr-3" />
                      <div>
                        <p class="font-medium text-gray-800">{{ dest.name }}</p>
                        <p class="text-xs text-gray-500">{{ dest.city }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Sellers Tab -->
                <div v-else-if="activeTab === 'sellers'">
                  <div v-if="!selectedStation.user_assignments?.length" class="text-center py-6 text-gray-400">
                    Aucun vendeur affecté à cette station
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="assignment in selectedStation.user_assignments" 
                      :key="assignment.id"
                      class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                    >
                      <div class="flex items-center gap-3">
                        <Account class="h-8 w-8 text-gray-400" />
                        <div>
                          <p class="font-medium text-gray-800">{{ assignment.user?.name }}</p>
                          <p class="text-xs text-gray-500">{{ assignment.user?.email }}</p>
                        </div>
                      </div>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        assignment.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]">
                        {{ assignment.active ? 'Actif' : 'Inactif' }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Routes Tab -->
                <div v-else-if="activeTab === 'routes'">
                  <div v-if="allRoutes.length === 0" class="text-center py-6 text-gray-400">
                    Aucune route ne passe par cette station
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="route in allRoutes" 
                      :key="route.id"
                      class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                    >
                      <div class="flex items-center gap-3">
                        <RouteIcon class="h-6 w-6 text-blue-500" />
                        <div>
                          <p class="font-medium text-gray-800">{{ route.name }}</p>
                          <p class="text-xs text-gray-500">
                            {{ route.origin }} → {{ route.destination }}
                          </p>
                        </div>
                      </div>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        route.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]">
                        {{ route.active ? 'Active' : 'Inactive' }}
                      </span>
                    </div>
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
        {{ isEditing ? 'Modifier la Station' : 'Nouvelle Station' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="destination" value="Ville / Destination*" />
              <select id="destination" v-model="form.destination_id" class="w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-md shadow-sm">
                <option value="">Sélectionner...</option>
                <option v-for="dest in destinations" :key="dest.id" :value="dest.id">
                  {{ dest.name }}
                </option>
              </select>
              <InputError :message="errors.destination_id" />
            </div>
            <div>
              <InputLabel for="code" value="Code (unique)" />
              <TextInput v-model="form.code" id="code" class="w-full" placeholder="Ex: ABJ" />
              <InputError :message="errors.code" />
            </div>
          </div>

          <div>
             <InputLabel for="name" value="Nom de la station" />
             <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Gare Nord" />
             <InputError :message="errors.name" />
          </div>

          <!-- Hidden city field, as it is derived from destination now, but kept if user wants custom display city -->
          <!-- <div>
              <InputLabel for="city" value="Ville (Affichage)" />
              <TextInput v-model="form.city" id="city" class="w-full" placeholder="Ex: Abidjan" />
              <InputError :message="errors.city" />
            </div> -->

          <div>
            <InputLabel for="address" value="Adresse" />
            <TextArea v-model="form.address" id="address" rows="2" class="w-full" placeholder="Adresse complète" />
            <InputError :message="errors.address" />
          </div>

          <div class="flex items-center">
            <input type="checkbox" v-model="form.active" id="active" class="rounded border-gray-300 text-green-600 shadow-sm focus:ring-green-500">
            <label for="active" class="ml-2 text-sm text-gray-600">Station Active</label>
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
