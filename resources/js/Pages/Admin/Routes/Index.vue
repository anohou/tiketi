<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import SettingsMenu from '@/Components/SettingsMenu.vue'
import { ref, computed, watch, onMounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Trash2 from 'vue-material-design-icons/Delete.vue'
import Loader from 'vue-material-design-icons/Loading.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Cash from 'vue-material-design-icons/Cash.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import Bus from 'vue-material-design-icons/Bus.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import DialogModal from '@/Components/DialogModal.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import RouteSchemaDiagram from '@/Components/RouteSchemaDiagram.vue'
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue'
import AccordionSection from '@/Components/UI/AccordionSection.vue'
import { useExportPrint } from '@/Composables/useExportPrint'
import Router from 'vue-material-design-icons/Router.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { confirmationStore } from '@/Stores/confirmationStore.js'
import { FULL_PERMISSIONS } from '@/Support/permissions.js'

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  routes: {
    type: Object,
    default: () => ({ data: [] })
  },
  destinations: {
    type: Array, // Passed from controller
    default: () => []
  },
  stations: {
    type: Array,
    default: () => []
  },
  // Stops removed
  fares: {
    type: Array,
    default: () => []
  },
  permissions: {
    type: Object,
    default: () => ({ ...FULL_PERMISSIONS })
  },
  hideTripSidebar: {
    type: Boolean,
    default: false
  }
});

// State
const search = ref('');
const selectedRoute = ref(null);
const processing = ref(false);
const errors = ref({});

// Modals State
const showRouteModal = ref(false);
const showStopModal = ref(false);
const showFareModal = ref(false);
const showRouteDiagramModal = ref(false);
const isEditingRoute = ref(false);

// Foldable Sections State
const showStops = ref(false);
const showFares = ref(false);
const showTrips = ref(false);

// Forms
const routeForm = ref({
  name: '',
  origin_destination_id: '',
  target_destination_id: '',
  estimated_duration_minutes: 120,
  automatic_connection_allocation: null,
  active: true
});

const stopForm = ref({
  station_id: '', // Changed from stop_id
  stop_index: 0
});

const fareForm = ref({
  from_station_id: '', // Changed from from_stop_id
  to_station_id: '', // Changed from to_stop_id
  amount: '',
  is_bidirectional: true
});

// Computed
const filteredRoutes = computed(() => {
  const routes = props.routes?.data || [];
  if (!search.value) return routes;

  const searchTerm = search.value.toLowerCase();
  return routes.filter(route =>
    route.name.toLowerCase().includes(searchTerm)
  );
});

// Compute fares that match the selected route's stops
const matchedFares = computed(() => {
  if (!selectedRoute.value) return [];
  
  // Get all station IDs for this route
  const routeStops = selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || [];
  const stationIds = new Set(routeStops.map(rs => rs.station?.id).filter(Boolean));
  
  if (stationIds.size === 0) return [];
  
  // Find fares where both from_station and to_station are in the route
  return props.fares.filter(fare => {
    const fromInRoute = stationIds.has(fare.from_station_id);
    const toInRoute = stationIds.has(fare.to_station_id);
    
    if (fromInRoute && toInRoute) {
        // Strict Filter: Fare MUST start at the Route's Origin (Destination's Station? No, let's relax this for now as Origin is a City)
        // Or we just check if it's within the route set.
        return true;
    }
    
    // Also check bidirectional (reverse direction)
    if (fare.is_bidirectional) {
      const reverseFromInRoute = stationIds.has(fare.to_station_id);
      const reverseToInRoute = stationIds.has(fare.from_station_id);
      if (reverseFromInRoute && reverseToInRoute) return true;
    }
    
    return false;
  });
});

const routeDiagramStops = computed(() => {
  if (!selectedRoute.value) return [];

  const routeStops = selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || [];
  return [...routeStops]
    .sort((a, b) => {
      const aIndex = Number(a.stop_index ?? a.stopIndex ?? 0);
      const bIndex = Number(b.stop_index ?? b.stopIndex ?? 0);
      return aIndex - bIndex;
    })
    .map((order, index) => ({
      id: order.id,
      index: index + 1,
      name: order.station?.name || 'Arrêt',
      city: order.station?.city || order.station?.destination?.name || 'Ville inconnue',
      code: order.station?.code || ''
    }));
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

// Methods - Route Selection
const isSelected = (route) => {
  if (!selectedRoute.value) return false;
  return selectedRoute.value.id === route.id;
};

const selectRoute = (route) => {
  selectedRoute.value = route;
  showStops.value = false;
  showFares.value = false;
  showTrips.value = false;
  showRouteDiagramModal.value = false;
};

onMounted(() => {
  if (!selectedRoute.value && props.routes?.data?.length > 0) {
    selectedRoute.value = props.routes.data[0];
  }
});

// Methods - Route Actions
const openCreateRouteModal = () => {
  isEditingRoute.value = false;
  routeForm.value = {
    name: '',
    origin_station_id: '',
    destination_station_id: '',
    estimated_duration_minutes: 120,
    automatic_connection_allocation: null,
    active: true
  };
  errors.value = {};
  processing.value = false;
  showRouteModal.value = true;
};

const openEditRouteModal = () => {
  if (!selectedRoute.value) return;
  isEditingRoute.value = true;
  routeForm.value = {
    name: selectedRoute.value.name,
    origin_destination_id: selectedRoute.value.origin_destination_id,
    target_destination_id: selectedRoute.value.target_destination_id,
    estimated_duration_minutes: selectedRoute.value.estimated_duration_minutes || 120,
    automatic_connection_allocation: selectedRoute.value.automatic_connection_allocation,
    active: selectedRoute.value.active
  };
  errors.value = {};
  processing.value = false;
  showRouteModal.value = true;
};

const duplicateRoute = () => {
  if (!selectedRoute.value) return;
  isEditingRoute.value = false;
  routeForm.value = {
    name: selectedRoute.value.name + ' (Copie)',
    origin_destination_id: selectedRoute.value.origin_destination_id,
    target_destination_id: selectedRoute.value.target_destination_id,
    estimated_duration_minutes: selectedRoute.value.estimated_duration_minutes || 120,
    automatic_connection_allocation: selectedRoute.value.automatic_connection_allocation,
    active: true
  };
  errors.value = {};
  processing.value = false;
  showRouteModal.value = true;
};

const closeRouteModal = () => {
  showRouteModal.value = false;
  routeForm.value = {
    name: '',
    origin_station_id: '',
    destination_station_id: '',
    estimated_duration_minutes: 120,
    automatic_connection_allocation: null,
    active: true
  };
  errors.value = {};
  processing.value = false;
};

const openRouteDiagramModal = () => {
  if (!selectedRoute.value) return;
  showRouteDiagramModal.value = true;
};

const closeRouteDiagramModal = () => {
  showRouteDiagramModal.value = false;
};

const submitRoute = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditingRoute.value
    ? route('admin.routes.update', selectedRoute.value.id)
    : route('admin.routes.store');

  const method = isEditingRoute.value ? 'put' : 'post';

  router[method](url, routeForm.value, {
    onSuccess: () => {
      closeRouteModal();
      // If created, we might want to select it, but for now let's just close
    },
    onError: (newErrors) => {
      errors.value = newErrors;
    },
    onFinish: () => {
      processing.value = false;
    }
  });
};

const deleteRoute = async (id) => {
  if (await confirmationStore.confirm({ title: 'Supprimer ce trajet', message: 'Cette action supprimera définitivement ce trajet.', confirmLabel: 'Supprimer', tone: 'danger' })) {
    router.delete(route('admin.routes.destroy', id), {
      onSuccess: () => {
        if (selectedRoute.value?.id === id) {
          selectedRoute.value = null;
        }
      }
    });
  }
};

// Methods - Stops
const openAddStopModal = () => {
  stopForm.value = { station_id: '', stop_index: 0 };
  errors.value = {};
  showStopModal.value = true;
};

const closeStopModal = () => {
  showStopModal.value = false;
  stopForm.value = { station_id: '', stop_index: 0 };
  errors.value = {};
};

const addStop = () => {
  if (!selectedRoute.value) return;
  processing.value = true;
  
  // Auto-calculate index if not set (append to end)
  const nextIndex = (selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || []).length;
  
  router.post(route('admin.routes.stops.store', selectedRoute.value.id), {
    ...stopForm.value,
    stop_index: nextIndex
  }, {
    preserveScroll: true,
    onSuccess: () => {
      processing.value = false;
      closeStopModal();
    },
    onError: (err) => {
      processing.value = false;
      errors.value = err;
    }
  });
};

const removeStop = async (stopOrder) => {
  if (!await confirmationStore.confirm({ title: 'Retirer cette destination', message: 'La destination sera retirée de ce trajet.', confirmLabel: 'Retirer', tone: 'danger' })) return;
  router.delete(route('admin.routes.stops.destroy', [selectedRoute.value.id, stopOrder.id]), {
    preserveScroll: true
  });
};

const moveStopUp = (idx) => {
  if (idx <= 0 || !selectedRoute.value) return;
  
  const stops = selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || [];
  if (stops.length < 2) return;
  
  // Swap actual stop_index values between adjacent stops
  const currentStop = stops[idx];
  const prevStop = stops[idx - 1];
  
  const orders = [
    { id: currentStop.id, stop_index: prevStop.stop_index },
    { id: prevStop.id, stop_index: currentStop.stop_index }
  ];
  
  router.put(route('admin.routes.stops.reorder', selectedRoute.value.id), { orders }, {
    preserveScroll: true
  });
};

const moveStopDown = (idx) => {
  if (!selectedRoute.value) return;
  
  const stops = selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || [];
  if (idx >= stops.length - 1 || stops.length < 2) return;
  
  // Swap actual stop_index values between adjacent stops
  const currentStop = stops[idx];
  const nextStop = stops[idx + 1];
  
  const orders = [
    { id: currentStop.id, stop_index: nextStop.stop_index },
    { id: nextStop.id, stop_index: currentStop.stop_index }
  ];
  
  router.put(route('admin.routes.stops.reorder', selectedRoute.value.id), { orders }, {
    preserveScroll: true
  });
};

// Methods - Fares
const openAddFareModal = () => {
  // Auto-set the origin from the route's first stop (origin station)
  const routeStops = selectedRoute.value.route_stop_orders || selectedRoute.value.routeStopOrders || [];
  const firstStop = routeStops.length > 0 ? routeStops[0].station : null;
  
  fareForm.value = { 
    from_station_id: firstStop?.id || '', 
    to_station_id: '', 
    amount: '',
    is_bidirectional: true
  };
  errors.value = {};
  showFareModal.value = true;
};

const closeFareModal = () => {
  showFareModal.value = false;
  fareForm.value = { from_station_id: '', to_station_id: '', amount: '', is_bidirectional: true };
  errors.value = {};
};

const addFare = () => {
  if (!selectedRoute.value) return;
  processing.value = true;
  
  router.post(route('admin.route-fares.store'), fareForm.value, {
    preserveScroll: true,
    onSuccess: () => {
      processing.value = false;
      closeFareModal();
    },
    onError: (err) => {
      processing.value = false;
      errors.value = err;
    }
  });
};

const removeFare = async (fareId) => {
  if (!await confirmationStore.confirm({ title: 'Supprimer ce tarif', message: 'Ce tarif sera définitivement supprimé.', confirmLabel: 'Supprimer', tone: 'danger' })) return;
  router.delete(route('admin.route-fares.destroy', fareId), {
    preserveScroll: true
  });
};

// Watchers
watch(() => props.routes, (newRoutes) => {
  if (selectedRoute.value) {
    const updatedRoute = newRoutes.data.find(r => r.id === selectedRoute.value.id);
    if (updatedRoute) {
      selectedRoute.value = updatedRoute;
    }
  }
}, { deep: true });

// Export/Print configuration
const routeColumns = {
  name: 'Nom',
  'origin_destination.name': 'Départ',
  'target_destination.name': 'Arrivée',
  active: 'Statut',
  trips_count: 'Voyages'
};

const handleExport = () => {
  const data = filteredRoutes.value.map(r => ({
    ...r,
    active: r.active ? 'Active' : 'Inactive'
  }));
  exportToExcel(data, routeColumns, 'routes');
};

const handlePrint = () => {
  const data = filteredRoutes.value.map(r => ({
    ...r,
    active: r.active ? 'Active' : 'Inactive'
  }));
  printList(data, routeColumns, 'Liste des Trajets');
};

</script>

<template>
  <MainNavLayout :fullHeight="true" :hide-trip-sidebar="hideTripSidebar">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Router class="text-emerald-600" :size="28" />
            </div>
            Gestion des Trajets
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Routes List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button v-if="permissions.canCreate" @click="openCreateRouteModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle Route">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  v-if="permissions.canExport"
                  :disabled="filteredRoutes.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>

            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredRoutes.length === 0" class="p-4 text-center text-gray-500 dark:text-slate-400">
                Aucune route trouvée.
              </div>
              <div v-else>
                <div v-for="routeItem in filteredRoutes" :key="routeItem.id" 
                  @click="selectRoute(routeItem)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(routeItem) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['font-semibold', isSelected(routeItem) ? 'text-green-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">{{ routeItem.name }}</h3>
                      <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                        {{ routeItem.origin_destination?.name }} → {{ routeItem.target_destination?.name }}
                      </p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <span :class="[
                          'px-2 py-0.5 rounded-full text-[10px] font-medium',
                          routeItem.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                        ]">
                          {{ routeItem.active ? 'Actif' : 'Inactif' }}
                        </span>
                        <span class="text-[10px] text-emerald-600">
                          {{ routeItem.trips_count || 0 }} voyages
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
          <div v-if="!selectedRoute" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-slate-500 dark:text-slate-400">
            <OfficeBuilding class="h-16 w-16 text-slate-200 mb-4" />
            <p class="text-lg">Sélectionnez une route pour voir les détails</p>
            <button v-if="permissions.canCreate" @click="openCreateRouteModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez une nouvelle route
            </button>
          </div>

          <!-- View Details & Related -->
          <div v-else class="space-y-4">
            <!-- Route Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ selectedRoute.name }}</h2>
                <div class="flex items-center gap-2">
                  <button @click="openRouteDiagramModal" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-800 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-950 transition-colors">
                    Schéma
                  </button>
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedRoute.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]">
                    {{ selectedRoute.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button v-if="permissions.canCreate" @click="duplicateRoute" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
                  <button v-if="permissions.canUpdate" @click="openEditRouteModal" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button v-if="permissions.canDelete" @click="deleteRoute(selectedRoute.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">DÉPART</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedRoute.origin_destination?.name }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">ARRIVÉE</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedRoute.target_destination?.name }}
                  </div>
                </div>
              </div>

            </div>

            <!-- Stops Section -->
            <AccordionSection
              v-model:open="showStops"
              :icon="OfficeBuilding"
              title="Stations Escale"
              :count="(selectedRoute.route_stop_orders || selectedRoute.routeStopOrders || []).length"
              :show-add="permissions.canManageStops"
              add-label="Ajouter une destination"
              @add="openAddStopModal"
            >
              <div class="space-y-2">
                <div v-if="(selectedRoute.route_stop_orders || selectedRoute.routeStopOrders || []).length === 0" class="text-sm text-slate-500 dark:text-slate-400 text-center py-2">
                  Aucune destination configurée.
                </div>
                <div v-for="(order, idx) in (selectedRoute.route_stop_orders || selectedRoute.routeStopOrders || [])" :key="order.id" 
                  class="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-950/20 rounded-md border border-slate-100 dark:border-slate-800/40">
                  <div class="flex items-center gap-3">
                    <span class="w-6 h-6 flex items-center justify-center bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full">
                      {{ idx + 1 }}
                    </span>
                    <div>
                      <p class="text-sm font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ order.station?.name }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">{{ order.station?.destination?.name || order.station?.city || 'Ville inconnue' }}</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-1" v-if="permissions.canManageStops">
                    <!-- Move Up Button -->
                    <button 
                      v-if="idx > 0"
                      @click="moveStopUp(idx)" 
                      class="p-1 text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 hover:text-emerald-600 hover:bg-emerald-50 rounded"
                      title="Monter">
                      <ArrowUp class="h-4 w-4" />
                    </button>
                    <div v-else class="w-6"></div>
                    <!-- Move Down Button -->
                    <button 
                      v-if="idx < (selectedRoute.route_stop_orders || selectedRoute.routeStopOrders || []).length - 1"
                      @click="moveStopDown(idx)" 
                      class="p-1 text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 hover:text-emerald-600 hover:bg-emerald-50 rounded"
                      title="Descendre">
                      <ArrowDown class="h-4 w-4" />
                    </button>
                    <div v-else class="w-6"></div>
                    <!-- Delete Button -->
                    <button @click="removeStop(order)" class="p-1 text-red-400 hover:text-red-600 hover:bg-red-50 rounded ml-1">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </div>
            </AccordionSection>

            <!-- Fares Section -->
            <AccordionSection
              v-model:open="showFares"
              :icon="Cash"
              title="Tarifs"
              :count="matchedFares.length"
              :show-add="permissions.canManageFares"
              add-label="Ajouter un tarif"
              @add="openAddFareModal"
            >
              <div class="space-y-2">
                <div v-if="matchedFares.length === 0" class="text-sm text-slate-500 dark:text-slate-400 text-center py-2">
                  Aucun tarif correspondant aux destinations de cette route.
                </div>
                <div v-for="fare in matchedFares" :key="fare.id" 
                  class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 p-2 bg-slate-50 dark:bg-slate-950/20 rounded-md border border-slate-100 dark:border-slate-800/40">
                  <div class="text-sm min-w-0">
                    <span class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ fare.from_station?.name }}</span>
                    <span v-if="fare.is_bidirectional" class="text-emerald-500 mx-1">↔</span>
                    <span v-else class="text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mx-1">→</span>
                    <span class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ fare.to_station?.name }}</span>
                  </div>
                  <div class="flex items-center gap-3 shrink-0">
                    <span class="font-bold text-emerald-700 whitespace-nowrap">{{ fare.amount?.toLocaleString() }} FCFA</span>
                    <button v-if="permissions.canManageFares" @click="removeFare(fare.id)" class="text-rose-400 hover:text-rose-600">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </div>
            </AccordionSection>

            <!-- Trips/Voyages Section -->
            <AccordionSection
              v-model:open="showTrips"
              :icon="Bus"
              title="Voyages"
              :count="selectedRoute.trips_count || (selectedRoute.trips || []).length"
            >
              <div class="space-y-2">
                <div v-if="!selectedRoute.trips || selectedRoute.trips.length === 0" class="text-sm text-slate-500 dark:text-slate-400 text-center py-2">
                  Aucun voyage programmé sur cette route.
                </div>
                <div v-for="trip in selectedRoute.trips" :key="trip.id" 
                  class="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-950/20 rounded-md border border-slate-100 dark:border-slate-800/40">
                  <div class="flex items-center gap-3">
                    <Bus class="h-5 w-5 text-emerald-500" />
                    <div>
                      <p class="text-sm font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ trip.vehicle?.identifier || 'Véhicule' }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ new Date(trip.departure_at).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                      </p>
                    </div>
                  </div>
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-medium',
                    trip.status === 'scheduled' ? 'bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300' :
                    trip.status === 'departed' ? 'bg-emerald-100 text-emerald-800' :
                    trip.status === 'arrived' ? 'bg-emerald-100 text-emerald-800' :
                    trip.status === 'cancelled' ? 'bg-rose-100 text-rose-800' :
                    'bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300'
                  ]">
                    {{ trip.status === 'scheduled' ? 'Programmé' :
                       trip.status === 'departed' ? 'Effectué' :
                       trip.status === 'arrived' ? 'Arrivé' :
                       trip.status === 'cancelled' ? 'Annulé' :
                       trip.status }}
                  </span>
                </div>
              </div>
            </AccordionSection>
          </div>
        </div>
      </div>
    </div>

    <!-- Route Modal -->
    <DialogModal :show="showRouteModal" @close="closeRouteModal" maxWidth="md">
      <template #title>
        {{ isEditingRoute ? 'Modifier la Route' : 'Nouvelle Route' }}
      </template>
      <template #content>
        <div class="space-y-4">
            <div>
            <InputLabel for="name" value="Nom de la route" />
            <TextInput v-model="routeForm.name" id="name" class="w-full" />
            <InputError :message="errors.name" />
          </div>
          
          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="origin" value="Ville de Départ" />
              <select v-model="routeForm.origin_destination_id" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100">
                <option value="">Choisir...</option>
                <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
              <InputError :message="errors.origin_destination_id" />
            </div>
            <div>
              <InputLabel for="dest" value="Ville d'Arrivée" />
              <select v-model="routeForm.target_destination_id" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100">
                <option value="">Choisir...</option>
                <option v-for="d in destinations" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
              <InputError :message="errors.target_destination_id" />
            </div>
          </div>

          <div>
            <InputLabel for="estimated_duration_minutes" value="Durée habituelle du trajet (minutes)" />
            <TextInput id="estimated_duration_minutes" v-model.number="routeForm.estimated_duration_minutes" type="number" min="1" max="2880" class="w-full" />
            <InputError :message="errors.estimated_duration_minutes" />
          </div>

          <div>
            <InputLabel for="automatic_connection_allocation" value="Allocation automatique des correspondances" />
            <select id="automatic_connection_allocation" v-model="routeForm.automatic_connection_allocation" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm dark:bg-slate-950 dark:text-slate-100">
              <option :value="null">Hériter de la politique de la compagnie</option>
              <option :value="true">Toujours activer sur ce trajet</option>
              <option :value="false">Toujours désactiver sur ce trajet</option>
            </select>
            <InputError :message="errors.automatic_connection_allocation" />
          </div>

          <div class="flex items-center">
            <input type="checkbox" v-model="routeForm.active" id="active" class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
            <label for="active" class="ml-2 text-sm text-slate-600 dark:text-slate-350 dark:text-slate-350">Route Active</label>
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeRouteModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submitRoute" :disabled="processing">
          {{ isEditingRoute ? 'Mettre à jour' : 'Enregistrer' }}
        </PrimaryButton>
      </template>
    </DialogModal>

    <!-- Stop Modal -->
    <DialogModal :show="showStopModal" @close="closeStopModal" maxWidth="md">
      <template #title>Ajouter une Destination</template>
      <template #content>
        <div>
          <InputLabel for="stop" value="Sélectionner une station" />
          <select v-model="stopForm.station_id" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 mt-1 dark:bg-slate-950 dark:text-slate-100">
            <option value="">Choisir...</option>
            <option v-for="station in stations" :key="station.id" :value="station.id">
              {{ station.name }} ({{ station.city }})
            </option>
          </select>
          <InputError :message="errors.station_id" class="mt-2" />
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeStopModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="addStop" :disabled="processing">Ajouter</PrimaryButton>
      </template>
    </DialogModal>

    <!-- Fare Modal -->
    <DialogModal :show="showFareModal" @close="closeFareModal" maxWidth="md">
      <template #title>Ajouter un Tarif</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="from" value="Gare de départ" />
            <select v-model="fareForm.from_station_id" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 bg-slate-100 dark:bg-slate-900 dark:text-slate-450" disabled>
              <option value="">Choisir...</option>
              <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
            </select>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Le départ est automatiquement défini.</p>
            <InputError :message="errors.from_station_id" />
          </div>
          <div>
            <InputLabel for="to" value="Gare d'arrivée" />
            <select v-model="fareForm.to_station_id" class="w-full border-slate-200 dark:border-slate-800 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100">
              <option value="">Choisir une station...</option>
              <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
            </select>
            <InputError :message="errors.to_station_id" />
          </div>
          <div>
            <InputLabel for="amount" value="Montant (FCFA)" />
            <TextInput v-model="fareForm.amount" type="number" class="w-full" placeholder="Ex: 5000" />
            <InputError :message="errors.amount" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeFareModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="addFare" :disabled="processing">Ajouter</PrimaryButton>
      </template>
    </DialogModal>

    <!-- Route Diagram Modal -->
    <DialogModal :show="showRouteDiagramModal" @close="closeRouteDiagramModal" maxWidth="5xl">
      <template #title>
        Schéma du trajet - {{ selectedRoute?.name }}
      </template>
      <template #content>
        <div v-if="routeDiagramStops.length === 0" class="py-8 text-center text-slate-500 dark:text-slate-400">
          Aucun arrêt n’est encore configuré pour cette route.
        </div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">
              Départ: {{ selectedRoute.origin_destination?.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
              Arrivée: {{ selectedRoute.target_destination?.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
              {{ routeDiagramStops.length }} arrêt(s)
            </span>
          </div>
          <RouteSchemaDiagram
            :stops="routeDiagramStops"
            variant="endpoints"
          />
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeRouteDiagramModal">Fermer</SecondaryButton>
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
