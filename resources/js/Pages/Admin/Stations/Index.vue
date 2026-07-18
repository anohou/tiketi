<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import GpsMapPicker from '@/Components/GpsMapPicker.vue';
import StationFormModal from '@/Components/StationFormModal.vue';
import DialogModal from '@/Components/DialogModal.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RouteSchemaDiagram from '@/Components/RouteSchemaDiagram.vue';
import { toastStore } from '@/Stores/toastStore.js';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import Account from 'vue-material-design-icons/Account.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';

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
const showRouteDiagramModal = ref(false);
const selectedRouteDiagram = ref(null);

const form = ref({
  code: '',
  name: '',
  destination_id: '',
  city: '',
  address: '',
  latitude: '',
  longitude: '',
  active: true,
  can_sell_tickets: true
});

const stationPreviewCoordinates = computed(() => {
  if (!selectedStation.value) {
    return { latitude: '', longitude: '' };
  }

  return {
    latitude: selectedStation.value.latitude ?? '',
    longitude: selectedStation.value.longitude ?? '',
  };
});

const mapCenter = computed(() => {
  if (selectedStation.value) {
    return {
      latitude: Number(selectedStation.value.latitude) || 7.177201,
      longitude: Number(selectedStation.value.longitude) || -5.635986
    };
  }
  return { latitude: 7.177201, longitude: -5.635986 };
});

const mapZoom = computed(() => {
  return selectedStation.value ? 13 : 6;
});

const stationPreviewPoints = computed(() => {
  return (props.stations.data || [])
    .map((station) => ({
      latitude: station.latitude,
      longitude: station.longitude,
      label: `${station.name}${station.city ? ` - ${station.city}` : ''}`,
    }))
    .filter((point) => Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude)));
});

const selectedDestinationStations = computed(() => {
  const destination = props.destinations.find((item) => item.id === form.value.destination_id);
  const stations = destination?.stations || [];

  return stations
    .filter((station) => station.id !== form.value.id)
    .map((station) => ({
      latitude: station.latitude,
      longitude: station.longitude,
      label: `${station.name}${station.city ? ` - ${station.city}` : ''}`,
    }))
    .filter((point) => Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude)));
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

  const addDestination = (station) => {
    if (!station || station.id === selectedStation.value.id) return;

    if (!destinationsMap.has(station.id)) {
      destinationsMap.set(station.id, {
        id: station.id,
        name: station.name,
        city: station.city || 'N/A'
      });
    }
  };

  const stationStops = selectedStation.value.route_stop_orders || selectedStation.value.routeStopOrders || [];

  stationStops.forEach((stopOrder) => {
    const route = stopOrder.route;
    if (!route) return;

    const allStopOrders = route.route_stop_orders || route.routeStopOrders || [];
    allStopOrders.forEach((order) => addDestination(order.station));
    addDestination(route.originStation);
    addDestination(route.destinationStation);
  });

  const directRoutes = [
    ...(selectedStation.value.origin_routes || selectedStation.value.originRoutes || []),
    ...(selectedStation.value.destination_routes || selectedStation.value.destinationRoutes || [])
  ];

  directRoutes.forEach((route) => {
    addDestination(route.originStation);
    addDestination(route.destinationStation);
    (route.route_stop_orders || route.routeStopOrders || []).forEach((order) => addDestination(order.station));
  });

  // Sort alphabetically by name
  return Array.from(destinationsMap.values()).sort((a, b) => {
    return a.name.localeCompare(b.name, 'fr');
  });
});

// Get all routes that pass through this station (via its stops and direct endpoints)
const allRoutes = computed(() => {
  if (!selectedStation.value) return [];

  const routesMap = new Map();
  const stationStops = selectedStation.value.route_stop_orders || selectedStation.value.routeStopOrders || [];
  const directRoutes = [
    ...(selectedStation.value.origin_routes || selectedStation.value.originRoutes || []),
    ...(selectedStation.value.destination_routes || selectedStation.value.destinationRoutes || [])
  ];

  stationStops.forEach((stopOrder) => {
    const route = stopOrder.route;
    if (!route || routesMap.has(route.id)) return;

    routesMap.set(route.id, {
      id: route.id,
      name: route.name,
      origin: route.origin_station?.name || route.originStation?.name || 'N/A',
      destination: route.destination_station?.name || route.destinationStation?.name || 'N/A',
      active: route.active,
      stops: route.route_stop_orders || route.routeStopOrders || [],
      route_stop_orders: route.route_stop_orders || route.routeStopOrders || [],
      routeStopOrders: route.route_stop_orders || route.routeStopOrders || []
    });
  });

  directRoutes.forEach((route) => {
    if (!route || routesMap.has(route.id)) return;

    routesMap.set(route.id, {
      id: route.id,
      name: route.name,
      origin: route.origin_station?.name || route.originStation?.name || 'N/A',
      destination: route.destination_station?.name || route.destinationStation?.name || 'N/A',
      active: route.active,
      stops: route.route_stop_orders || route.routeStopOrders || [],
      route_stop_orders: route.route_stop_orders || route.routeStopOrders || [],
      routeStopOrders: route.route_stop_orders || route.routeStopOrders || []
    });
  });

  return Array.from(routesMap.values()).sort((a, b) => a.name.localeCompare(b.name, 'fr'));
});

const routeDiagramStops = computed(() => {
  if (!selectedRouteDiagram.value) return [];

  const routeStops = selectedRouteDiagram.value.stops
    || selectedRouteDiagram.value.route_stop_orders
    || selectedRouteDiagram.value.routeStopOrders
    || [];

  return [...routeStops]
    .sort((a, b) => {
      const aIndex = Number(a.stop_index ?? a.stopIndex ?? 0);
      const bIndex = Number(b.stop_index ?? b.stopIndex ?? 0);
      return aIndex - bIndex;
    })
    .map((order, index) => {
      const station = order.station || {};

      return {
        id: order.id || station.id || `${selectedRouteDiagram.value.id}-${index}`,
        name: station.name || 'Arrêt',
        code: station.code || '',
        isConsulted: selectedStation.value?.id && station.id === selectedStation.value.id,
      };
    });
});

const routeDiagramNodes = computed(() => {
  const stops = routeDiagramStops.value;
  const usableWidth = 880;
  const step = stops.length > 1 ? usableWidth / (stops.length - 1) : 0;
  const compact = stops.length > 6;
  const mini = stops.length > 10;
  const labelSize = mini ? 10 : compact ? 11 : 13;
  const maxNameLength = mini ? 12 : compact ? 16 : 22;

  const shorten = (value, limit) => {
    if (!value) return '';
    return value.length > limit ? `${value.slice(0, Math.max(0, limit - 1))}…` : value;
  };

  return stops.map((stop, index) => ({
    ...stop,
    x: 60 + (index * step),
    labelY: index % 2 === 0 ? 82 : 170,
    labelSize,
    displayName: shorten(stop.name, maxNameLength),
    isFirst: index === 0,
    isLast: index === stops.length - 1,
  }));
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
  closeRouteDiagramModal();
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

const openRouteDiagramModal = (route) => {
  selectedRouteDiagram.value = route;
  showRouteDiagramModal.value = true;
};

const closeRouteDiagramModal = () => {
  showRouteDiagramModal.value = false;
  selectedRouteDiagram.value = null;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    code: '',
    name: '',
    destination_id: '', // New field
    city: '',
    address: '',
    latitude: '',
    longitude: '',
    active: true,
    can_sell_tickets: true
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
    latitude: selectedStation.value.latitude ?? '',
    longitude: selectedStation.value.longitude ?? '',
    active: selectedStation.value.active,
    can_sell_tickets: selectedStation.value.can_sell_tickets !== false
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateStation = () => {
  if (!selectedStation.value) return;
  isEditing.value = false;
  form.value = {
    code: selectedStation.value.code + '-COPY',
    name: selectedStation.value.name + ' (Copie)',
    destination_id: selectedStation.value.destination_id,
    city: selectedStation.value.city,
    address: selectedStation.value.address || '',
    latitude: selectedStation.value.latitude ?? '',
    longitude: selectedStation.value.longitude ?? '',
    active: true,
    can_sell_tickets: selectedStation.value.can_sell_tickets !== false
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
    latitude: '',
    longitude: '',
    active: true,
    can_sell_tickets: true
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

const showDeleteModal = ref(false);
const stationIdToDelete = ref(null);

const confirmDeleteStation = (id) => {
  stationIdToDelete.value = id;
  showDeleteModal.value = true;
};

const deleteStation = () => {
  if (!stationIdToDelete.value) return;
  showDeleteModal.value = false;
  router.delete(route('admin.stations.destroy', stationIdToDelete.value), {
    onSuccess: () => {
      if (selectedStation.value?.id === stationIdToDelete.value) {
        selectedStation.value = null;
      }
      toastStore.success('Station supprimée avec succès');
      stationIdToDelete.value = null;
    },
    onError: (errorResponse) => {
      let errorMessage = 'Impossible de supprimer cette station.';
      if (errorResponse.message) {
        errorMessage = errorResponse.message;
      } else if (errorResponse.error) {
        errorMessage = errorResponse.error;
      }
      toastStore.error(errorMessage);
      stationIdToDelete.value = null;
    }
  });
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
  const data = filteredStations.value.map(s => ({
    ...s,
    active: s.active ? 'Actif' : 'Inactif'
  }));
  exportToExcel(data, stationColumns, 'stations');
};

const handlePrint = () => {
  const data = filteredStations.value.map(s => ({
    ...s,
    active: s.active ? 'Actif' : 'Inactif'
  }));
  printList(data, stationColumns, 'Liste des Stations');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <OfficeBuilding class="text-emerald-600" :size="28" />
            </div>
            Gestion des Gares / Destinations
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres des lieux de prise en charge et dépose</p>
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
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
             <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button v-if="$page.props.auth.user.role !== 'supervisor'" @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle Station">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  :disabled="filteredStations.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>

            </div>

            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredStations.length === 0" class="p-4">
                <EmptyState
                  title="Aucune gare trouvée"
                  message="Vous pouvez en créer une en cliquant sur le bouton '+'"
                  :icon="OfficeBuilding"
                />
              </div>
              <div v-else>
                <div v-for="station in filteredStations" :key="station.id" 
                  @click="selectStation(station)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(station) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <h3 :class="['font-semibold truncate', isSelected(station) ? 'text-green-800' : 'text-slate-800 dark:text-slate-200']">{{ station.name }}</h3>
                        <span v-if="station.code" class="px-1.5 py-0.5 bg-slate-100 dark:bg-slate-850 text-slate-700 dark:text-slate-300 text-[10px] font-bold rounded uppercase tracking-wider shrink-0 border border-slate-200 dark:border-slate-800">{{ station.code }}</span>
                        <span :class="['px-1.5 py-0.5 text-[10px] font-bold rounded uppercase tracking-wider shrink-0 border', station.can_sell_tickets !== false ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200']">
                          {{ station.can_sell_tickets !== false ? 'Vente' : 'Arrêt' }}
                        </span>
                      </div>
                      <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ station.city }}
                      </p>
                      <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">
                        {{ station.destination?.name || 'Destination non liée' }}
                      </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                      <span class="text-xs text-orange-400">{{ station.user_assignments_count || 0 }} vendeurs</span>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        station.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
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
          <div class="space-y-4">
          <div v-if="selectedStation" class="space-y-4">
            <!-- Details Card (always visible) -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
              <div class="flex justify-between items-start mb-4">
                <div class="min-w-0">
              <div class="flex items-center gap-3 flex-wrap">
                    <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-200 truncate">{{ selectedStation.name }}</h2>
                    <span v-if="selectedStation.code" class="px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 text-xs font-extrabold rounded-md uppercase tracking-wider shrink-0 border border-slate-200 dark:border-slate-700">{{ selectedStation.code }}</span>
                    <span
                      :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                        selectedStation.can_sell_tickets !== false ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                      ]"
                    >
                      {{ selectedStation.can_sell_tickets !== false ? 'Vend billets' : 'Simple arrêt' }}
                    </span>
                  </div>
                  <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ selectedStation.city }}</p>
                </div>
                <div class="flex gap-2">
                  <div class="flex items-center gap-2">
                    <span :class="[
                      'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                      selectedStation.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ selectedStation.active ? 'Active' : 'Inactive' }}
                    </span>
                    <div v-if="$page.props.auth.user.role !== 'supervisor'" class="flex items-center gap-2">
                      <button @click="duplicateStation" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                        <ContentCopy class="h-5 w-5" />
                      </button>
                      <button @click="openEditModal" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                        <Pencil class="h-5 w-5" />
                      </button>
                      <button @click="confirmDeleteStation(selectedStation.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                        <Trash2 class="h-5 w-5" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Details Grid -->
              <div class="grid grid-cols-12 gap-4 pt-2 border-t border-slate-100 dark:border-slate-800/50 dark:border-slate-800/50">
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">VILLE</span>
                  <div class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ selectedStation.destination?.name || 'Non liée' }}</div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">CODE</span>
                  <div class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ selectedStation.code }}</div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">VENTE</span>
                  <div class="text-lg font-medium text-slate-900 dark:text-slate-100">
                    {{ selectedStation.can_sell_tickets !== false ? 'Peut vendre' : 'Ne vend pas' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">QUARTIER / NOM PRÉCIS</span>
                  <div class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ selectedStation.city }}</div>
                </div>
                <div class="col-span-12">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">ADRESSE</span>
                  <div class="text-base text-slate-700 dark:text-slate-300 dark:text-slate-300">{{ selectedStation.address || 'Non renseignée' }}</div>
                </div>
              </div>
            </div>

            <!-- Related Tables Tabs -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm">
              <!-- Tab Headers -->
              <div class="flex border-b border-slate-200 overflow-x-auto">
                <button
                  v-for="tab in tabs"
                  :key="tab.id"
                  @click="activeTab = tab.id"
                  :class="[
                    'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                    activeTab === tab.id
                      ? 'border-emerald-600 text-emerald-700 bg-emerald-50/50'
                      : 'border-transparent text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:text-slate-300 hover:bg-slate-50'
                  ]"
                >
                  <component :is="tab.icon" class="h-4 w-4" />
                  {{ tab.label }}
                  <span 
                    v-if="getTabCount(tab) !== null"
                    :class="[
                      'px-1.5 py-0.5 rounded-full text-[10px] font-bold',
                      activeTab === tab.id ? 'bg-emerald-200 text-emerald-800' : 'bg-slate-200 text-slate-600 dark:text-slate-350 dark:text-slate-350'
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
                  <div v-if="servedDestinations.length === 0" class="text-center py-6 text-orange-400">
                    Aucune destination déduite à partir des trajets pour cette station
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="dest in servedDestinations" 
                      :key="dest.id"
                      class="flex items-center p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                    >
                      <OfficeBuilding class="h-6 w-6 text-emerald-500 mr-3" />
                      <div>
                        <p class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ dest.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">{{ dest.city }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Sellers Tab -->
                <div v-else-if="activeTab === 'sellers'">
                  <div v-if="!selectedStation.user_assignments?.length" class="text-center py-6 text-orange-400">
                    Aucun vendeur affecté à cette station
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="assignment in selectedStation.user_assignments" 
                      :key="assignment.id"
                      class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                    >
                      <div class="flex items-center gap-3">
                        <Account class="h-8 w-8 text-orange-400" />
                        <div>
                          <p class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ assignment.user?.name }}</p>
                          <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">{{ assignment.user?.email }}</p>
                        </div>
                      </div>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        assignment.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ assignment.active ? 'Actif' : 'Inactif' }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Routes Tab -->
                <div v-else-if="activeTab === 'routes'">
                  <div v-if="allRoutes.length === 0" class="text-center py-6 text-orange-400">
                    Aucune route ne passe par cette gare
                  </div>
                  <div v-else class="space-y-2">
                    <div 
                      v-for="route in allRoutes" 
                      :key="route.id"
                      class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg cursor-pointer transition-colors hover:bg-slate-100 dark:hover:bg-slate-900"
                      @click="openRouteDiagramModal(route)"
                    >
                      <div class="flex items-center gap-3">
                        <Routes class="h-6 w-6 text-emerald-500" />
                        <div>
                          <p class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ route.name }}</p>
                          <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                            {{ route.origin }} → {{ route.destination }}
                          </p>
                        </div>
                      </div>
                      <div class="flex items-center gap-2">
                        <span :class="[
                          'px-2 py-0.5 rounded-full text-[10px] font-medium',
                          route.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                        ]">
                          {{ route.active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                          Voir schéma
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
              <div>
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200">
                  Carte des gares
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                  Les gares déjà enregistrées sur la carte.
                </p>
              </div>
              <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">
                {{ stationPreviewPoints.length }} points
              </span>
            </div>

            <GpsMapPicker
              :modelValue="stationPreviewCoordinates"
              :reference-points="stationPreviewPoints"
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
    <StationFormModal
      :show="showModal"
      :title="isEditing ? 'Modifier la Gare / Destination' : 'Nouvelle Gare / Destination'"
      :form="form"
      :errors="errors"
      :processing="processing"
      :reference-points="selectedDestinationStations"
      :center="{
        latitude: Number(form.latitude) || 7.177201,
        longitude: Number(form.longitude) || -5.635986
      }"
      :map-visible="showModal"
      destination-mode="select"
      destination-label="Ville*"
      :destination-options="destinations"
      :destination-value-label="''"
      @close="closeModal"
      @submit="submit"
    />

    <DialogModal :show="showRouteDiagramModal" @close="closeRouteDiagramModal" maxWidth="5xl">
      <template #title>
        <div class="flex flex-col gap-2">
          <span class="text-2xl font-bold text-slate-900 dark:text-slate-100">Schéma du trajet</span>
          <span class="text-sm text-slate-500 dark:text-slate-400">
            La gare mise en évidence est la gare consultée, pas forcément la gare de départ du trajet.
          </span>
        </div>
      </template>
      <template #content>
        <div v-if="!selectedRouteDiagram" class="py-8 text-center text-slate-500 dark:text-slate-400">
          Aucun trajet sélectionné.
        </div>
        <div v-else-if="routeDiagramStops.length === 0" class="py-8 text-center text-slate-500 dark:text-slate-400">
          Aucun arrêt n’est encore configuré pour ce trajet.
        </div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700">
              Trajet: {{ selectedRouteDiagram.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900">
              Gare consultée: {{ selectedStation.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-orange-50 text-orange-700 border border-orange-100 dark:bg-orange-950/30 dark:text-orange-300 dark:border-orange-900">
              {{ routeDiagramStops.length }} arrêt(s)
            </span>
          </div>
          <RouteSchemaDiagram
            :stops="routeDiagramStops"
            variant="endpoints"
            :highlight-station-id="selectedStation?.id"
          />
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeRouteDiagramModal">Fermer</SecondaryButton>
      </template>
    </DialogModal>

    <!-- Custom Confirmation Modal -->
    <ConfirmationModal :show="showDeleteModal" @close="showDeleteModal = false">
        <template #title>Supprimer la gare</template>
        <template #content>Êtes-vous sûr de vouloir supprimer cette gare ? Cette action est irréversible.</template>
        <template #footer>
            <SecondaryButton @click="showDeleteModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="deleteStation">Oui, Supprimer</DangerButton>
        </template>
    </ConfirmationModal>
    </div>
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
