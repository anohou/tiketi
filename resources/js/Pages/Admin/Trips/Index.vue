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
import { toastStore } from '@/Stores/toastStore.js';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapClock from 'vue-material-design-icons/MapClock.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import FileExcel from 'vue-material-design-icons/FileExcel.vue';
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  trips: {
    type: Object,
    default: () => ({ data: [] })
  },
  routes: {
    type: Array,
    default: () => []
  },
  vehicles: {
    type: Array,
    default: () => []
  },
  replicableTrips: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const dateFilter = ref('');
const departureFilter = ref('');
const arrivalFilter = ref('');
const selectedTrip = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const exportingTripExcel = ref(false);
const exportingTripPdf = ref(false);

const form = ref({
  code: '',
  route_id: '',
  vehicle_id: '',
  departure_at: '',
  status: 'scheduled',
  allows_open_connections: false,
  automatic_connection_allocation: null,
  is_replicable: false
});

// Status options
const statusOptions = [
  { value: 'scheduled', label: 'Programmé', color: 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300' },
  { value: 'boarding', label: 'Embarquement', color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-400' },
  { value: 'departed', label: 'Effectué', color: 'bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300' },
  { value: 'arrived', label: 'Arrivé', color: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300' },
  { value: 'cancelled', label: 'Annulé', color: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-400' }
];

// Unique departures and arrivals for filters
const uniqueDepartures = computed(() => {
  const stations = new Map();
  props.routes.forEach(r => {
    const routeStops = r.route_stop_orders || r.routeStopOrders || [];
    const origin = r.origin_station || r.originStation || routeStops[0]?.station;
    if (origin) {
      stations.set(origin.id, origin);
    }
  });
  return Array.from(stations.values());
});

const uniqueArrivals = computed(() => {
  const stations = new Map();
  props.routes.forEach(r => {
    const routeStops = r.route_stop_orders || r.routeStopOrders || [];
    const destination = r.destination_station || r.destinationStation || routeStops[routeStops.length - 1]?.station;
    if (destination) {
      stations.set(destination.id, destination);
    }
  });
  return Array.from(stations.values());
});

// Computed
const filteredTrips = computed(() => {
  let trips = props.trips?.data || [];
  
  // Filter by search
  if (search.value) {
    const searchTerm = search.value.toLowerCase();
    trips = trips.filter(trip =>
      trip.route?.name.toLowerCase().includes(searchTerm) ||
      trip.vehicle?.identifier.toLowerCase().includes(searchTerm)
    );
  }
  
  // Filter by date
  if (dateFilter.value) {
    trips = trips.filter(trip => {
      const tripDate = new Date(trip.departure_at).toISOString().split('T')[0];
      return tripDate === dateFilter.value;
    });
  }
  
  // Filter by departure station
  if (departureFilter.value) {
    trips = trips.filter(trip => 
      (trip.origin_station?.id || trip.originStation?.id || trip.route?.origin_station?.id || trip.route?.originStation?.id) === departureFilter.value
    );
  }
  
  // Filter by arrival station
  if (arrivalFilter.value) {
    trips = trips.filter(trip => 
      (trip.destination_station?.id || trip.destinationStation?.id || trip.route?.destination_station?.id || trip.route?.destinationStation?.id) === arrivalFilter.value
    );
  }
  
  return trips;
});

// Get status display info - past trips cannot be "scheduled"
const getStatusInfo = (status, departureAt) => {
  // If departure is in the past and status is still scheduled, show as "Effectué"
  if (departureAt && new Date(departureAt) < new Date() && status === 'scheduled') {
    return { value: 'departed', label: 'Effectué', color: 'bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300' };
  }
  return statusOptions.find(s => s.value === status) || { label: status, color: 'bg-gray-100 text-gray-800 dark:text-slate-200 dark:text-slate-200 dark:bg-slate-800 dark:text-slate-300' };
};

// Calculate destination breakdown with percentage
const visibleTickets = computed(() => {
  return (selectedTrip.value?.tickets || []).filter(ticket => ticket.status !== 'cancelled');
});

const hasVisibleTickets = computed(() => visibleTickets.value.length > 0);

const destinationBreakdown = computed(() => {
  if (!visibleTickets.value.length) return [];
  
  const totalTickets = visibleTickets.value.length;
  const breakdown = new Map();
  visibleTickets.value.forEach(ticket => {
    const destName = ticket.to_station?.name || ticket.toStation?.name || 'Inconnu';
    const current = breakdown.get(destName) || { count: 0, revenue: 0 };
    current.count++;
    current.revenue += ticket.price || 0;
    breakdown.set(destName, current);
  });
  
  return Array.from(breakdown.entries()).map(([name, data]) => ({
    name,
    count: data.count,
    revenue: data.revenue,
    percentage: totalTickets > 0 ? Math.round((data.count / totalTickets) * 100) : 0
  })).sort((a, b) => b.count - a.count);
});

// Ticket sort state
const ticketSortBy = ref('distance'); // 'distance', 'destination', 'seat', 'price'
const ticketSortAsc = ref(true);

const toggleTicketSort = (field) => {
  if (ticketSortBy.value === field) {
    ticketSortAsc.value = !ticketSortAsc.value;
  } else {
    ticketSortBy.value = field;
    ticketSortAsc.value = true;
  }
};

// Tickets ordered by selected field
const orderedTickets = computed(() => {
  if (!visibleTickets.value.length) return [];
  
  // Build stop index map from route (handle both snake_case and camelCase)
  const stopIndexMap = new Map();
  const stopOrders = selectedTrip.value.route?.route_stop_orders || selectedTrip.value.route?.routeStopOrders || [];
  
  stopOrders.forEach(order => {
    // Map both possible field names
    const stopId = order.station_id || order.stationId || order.station?.id || order.stop_id || order.stopId;
    const stopIndex = order.stop_index ?? order.stopIndex ?? 999;
    if (stopId) {
      stopIndexMap.set(stopId, stopIndex);
    }
  });
  
  return [...visibleTickets.value].sort((a, b) => {
    let comparison = 0;
    
    switch (ticketSortBy.value) {
      case 'distance':
        // Try getting the stop_id from both the direct field and the loaded relation
        const stopIdA = a.to_station_id || a.toStationId || a.to_station?.id || a.toStation?.id;
        const stopIdB = b.to_station_id || b.toStationId || b.to_station?.id || b.toStation?.id;
        const indexA = stopIndexMap.get(stopIdA) ?? 999;
        const indexB = stopIndexMap.get(stopIdB) ?? 999;
        comparison = indexA - indexB;
        break;
      case 'seat':
        comparison = (a.seat_number ?? a.seatNumber ?? 0) - (b.seat_number ?? b.seatNumber ?? 0);
        break;
      case 'price':
        comparison = (a.price || 0) - (b.price || 0);
        break;
    }
    
    return ticketSortAsc.value ? comparison : -comparison;
  });
});

// Fill percentage
const fillPercentage = computed(() => {
  if (!selectedTrip.value?.vehicle?.seat_count) return 0;
  const occupied = selectedTrip.value.occupied_seats_count || selectedTrip.value.occupied_seats || 0;
  const total = selectedTrip.value.vehicle.seat_count;
  return Math.round((occupied / total) * 100);
});

// Total revenue
const totalRevenue = computed(() => {
  if (!visibleTickets.value.length) return 0;
  return visibleTickets.value.reduce((sum, t) => sum + (t.price || 0), 0);
});

// Watchers
watch(() => props.trips, (newTrips) => {
  if (selectedTrip.value) {
    const updatedTrip = newTrips.data.find(t => t.id === selectedTrip.value.id);
    if (updatedTrip) {
      selectedTrip.value = updatedTrip;
    }
  }
}, { deep: true });

watch([() => form.value.route_id, () => form.value.departure_at], ([routeId, departureAt]) => {
  if (isEditing.value) {
    return;
  }
  
  if (routeId && departureAt) {
    const routeObj = props.routes.find(r => r.id === routeId);
    if (routeObj) {
      const origin = routeObj.origin_station || routeObj.originStation;
      const destination = routeObj.destination_station || routeObj.destinationStation;
      
      const originCode = origin?.code || (origin ? origin.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'TRP');
      const destinationCode = destination?.code || (destination ? destination.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'DST');
      
      const timePart = departureAt.split('T')[1] ? departureAt.split('T')[1].replace(':', '') : '0000';
      const cleanTime = timePart.substring(0, 4);
      
      form.value.code = `${originCode}-${destinationCode}-${cleanTime}`;
    }
  }
});

const selectedTemplate = ref(null);
watch(selectedTemplate, (newTemplate) => {
  if (newTemplate) {
    applyReplicableTemplate(newTemplate);
  }
});
watch(showModal, (isOpen) => {
  if (!isOpen) {
    selectedTemplate.value = null;
  }
});
const getRouteName = (routeId) => {
  const r = props.routes?.find(route => route.id === routeId);
  return r ? (r.display_name || r.name) : 'Route inconnue';
};
const applyReplicableTemplate = (template) => {
  if (!template) return;
  form.value.route_id = template.route_id;
  form.value.allows_open_connections = !!template.allows_open_connections;
  form.value.automatic_connection_allocation = template.automatic_connection_allocation;
  form.value.is_replicable = true;
  
  const currentDatePart = form.value.departure_at
    ? form.value.departure_at.split('T')[0]
    : new Date().toISOString().split('T')[0];
    
  form.value.departure_at = `${currentDatePart}T${template.time}`;
};

// Methods
const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const formatShortDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleString('fr-FR', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const formatMoney = (amount) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' F';
};

const clearFilters = () => {
  dateFilter.value = '';
  departureFilter.value = '';
  arrivalFilter.value = '';
};

const isSelected = (trip) => {
  if (!selectedTrip.value) return false;
  return selectedTrip.value.id === trip.id;
};

const selectTrip = (trip) => {
  selectedTrip.value = trip;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    code: '',
    route_id: '',
    vehicle_id: '',
    departure_at: '',
    status: 'scheduled',
    allows_open_connections: false,
    automatic_connection_allocation: null,
    is_replicable: false
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedTrip.value) return;
  isEditing.value = true;
  form.value = {
    code: selectedTrip.value.code || '',
    route_id: selectedTrip.value.route_id,
    vehicle_id: selectedTrip.value.vehicle_id || '',
    departure_at: selectedTrip.value.departure_at.slice(0, 16),
    status: selectedTrip.value.status || 'scheduled',
    allows_open_connections: !!selectedTrip.value.allows_open_connections,
    automatic_connection_allocation: selectedTrip.value.automatic_connection_allocation,
    is_replicable: !!selectedTrip.value.is_replicable
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    code: '',
    route_id: '',
    vehicle_id: '',
    departure_at: '',
    status: 'scheduled',
    allows_open_connections: false,
    automatic_connection_allocation: null,
    is_replicable: false
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.trips.update', selectedTrip.value.id)
    : route('admin.trips.store');

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

const deleteTrip = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce voyage ?')) {
    router.delete(route('admin.trips.destroy', id), {
      onSuccess: () => {
        if (selectedTrip.value?.id === id) {
          selectedTrip.value = null;
        }
      }
    });
  }
};

const ticketColumns = {
  ticket_number: 'N° Ticket',
  created_at: 'Date vente',
  from_station_name: 'Départ',
  to_station_name: 'Arrivée',
  seat_number: 'Place',
  boarding_group: 'Zone',
  price: 'Prix (FCFA)',
  seller_name: 'Vendeur',
  passenger_name: 'Passager',
  passenger_phone: 'Téléphone',
  status_label: 'Statut'
};

const buildSelectedTripTicketData = () => {
  return visibleTickets.value.map(ticket => ({
    ticket_number: ticket.ticket_number || '-',
    created_at: formatDate(ticket.created_at),
    from_station_name: ticket.fromStation?.name || ticket.from_station?.name || '-',
    to_station_name: ticket.toStation?.name || ticket.to_station?.name || '-',
    seat_number: ticket.seat_number ?? ticket.seatNumber ?? '-',
    boarding_group: ticket.boarding_group || '-',
    price: ticket.price || 0,
    seller_name: ticket.seller?.name || '-',
    passenger_name: ticket.passenger_name || 'Anonyme',
    passenger_phone: ticket.passenger_phone || '-',
    status_label: ticket.status === 'cancelled' ? 'Annulé' : 'Valide'
  }));
};

const exportSelectedTicketsToExcel = () => {
  if (!hasVisibleTickets.value) {
    toastStore.warning('Aucun ticket à exporter pour ce voyage');
    return;
  }

  exportingTripExcel.value = true;
  try {
    exportToExcel(
      buildSelectedTripTicketData(),
      ticketColumns,
      `tickets_${selectedTrip.value.code || selectedTrip.value.id}`
    );
  } finally {
    exportingTripExcel.value = false;
  }
};

const exportSelectedTicketsToPdf = () => {
  if (!hasVisibleTickets.value) {
    toastStore.warning('Aucun ticket à exporter pour ce voyage');
    return;
  }

  exportingTripPdf.value = true;
  try {
    const url = route('tickets.export-pdf', { trip_id: selectedTrip.value.id });
    window.open(url, '_blank', 'noopener,noreferrer');
  } finally {
    window.setTimeout(() => {
      exportingTripPdf.value = false;
    }, 600);
  }
};

// Export/Print configuration
const tripColumns = {
  code: 'Code',
  'route.name': 'Route',
  departure_at: 'Départ',
  'vehicle.identifier': 'Véhicule',
  status: 'Statut',
  tickets_count: 'Tickets vendus'
};

const handleExport = () => {
  const data = filteredTrips.value.map(trip => ({
    ...trip,
    code: trip.code || '-',
    departure_at: formatDate(trip.departure_at),
    status: getStatusInfo(trip.status, trip.departure_at).label
  }));
  exportToExcel(data, tripColumns, 'voyages');
};

const handlePrint = () => {
  const data = filteredTrips.value.map(trip => ({
    ...trip,
    code: trip.code || '-',
    departure_at: formatDate(trip.departure_at),
    status: getStatusInfo(trip.status, trip.departure_at).label
  }));
  printList(data, tripColumns, 'Liste des trajets');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden bg-slate-50 dark:bg-slate-950">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 dark:bg-emerald-950/50 rounded-2xl shadow-sm">
              <Calendar class="text-green-600 dark:text-green-400" :size="28" />
            </div>
            Gestion des Voyages
          </h1>
          <p class="text-slate-500 dark:text-slate-400 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Trips List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200/80 dark:border-slate-800 p-3 bg-gradient-to-r from-emerald-50 via-white to-cyan-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-900/50 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:border-emerald-400 text-sm bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100 shadow-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-colors shadow-sm shrink-0" title="Nouveau Voyage">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  :disabled="filteredTrips.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
              
              <!-- Filters -->
              <div class="grid grid-cols-3 gap-2">
                <input 
                  type="date" 
                  v-model="dateFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] focus:outline-none focus:border-emerald-400 bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100"
                  title="Filtrer par date"
                />
                <select 
                  v-model="departureFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] focus:outline-none focus:border-emerald-400 bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100"
                >
                  <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Départ</option>
                  <option v-for="station in uniqueDepartures" :key="station.id" :value="station.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ station.name }}
                  </option>
                </select>
                <select 
                  v-model="arrivalFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] focus:outline-none focus:border-emerald-400 bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100"
                >
                  <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Arrivée</option>
                  <option v-for="station in uniqueArrivals" :key="station.id" :value="station.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ station.name }}
                  </option>
                </select>
              </div>
              <div v-if="dateFilter || departureFilter || arrivalFilter" class="flex items-center justify-between mt-2">
                <button 
                  @click="clearFilters" 
                  class="text-[10px] text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300"
                >
                  Effacer les filtres
                </button>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredTrips.length === 0" class="p-4 text-center text-slate-500 dark:text-slate-400">
                Aucun voyage trouvé.
              </div>
              <div v-else>
                <div v-for="trip in filteredTrips" :key="trip.id" 
                  @click="selectTrip(trip)"
                  class="p-3 cursor-pointer transition-all border-b border-slate-100 dark:border-slate-800/40 last:border-0 hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                  :class="[
                    isSelected(trip) 
                      ? 'bg-emerald-50/70 dark:bg-emerald-950/20 border-l-4 border-l-emerald-500' 
                      : 'bg-white/80 dark:bg-slate-900/50 border-l-4 border-l-transparent'
                  ]"
                >
                  <div class="flex justify-between items-start">
                      <div class="flex-1 min-w-0">
                        <h3 :class="['font-semibold truncate', isSelected(trip) ? 'text-emerald-800 dark:text-emerald-300' : 'text-slate-800 dark:text-slate-200']">
                          {{ trip.route?.name }}
                        </h3>
                        <p class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold mt-1">
                          {{ trip.code || 'Code en attente' }}
                        </p>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-1">{{ formatShortDate(trip.departure_at) }}</p>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500">{{ trip.vehicle?.identifier }}</p>
                      </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[9px] font-medium',
                        getStatusInfo(trip.status, trip.departure_at).color
                      ]">
                        {{ getStatusInfo(trip.status, trip.departure_at).label }}
                      </span>
                      <span class="text-[10px] text-slate-500 dark:text-slate-400">
                        {{ trip.tickets_count || 0 }} tickets vendus
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
          <div v-if="!selectedTrip" class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-slate-500 dark:text-slate-400">
            <MapClock class="h-16 w-16 text-emerald-200 dark:text-slate-700 mb-4" />
            <p class="text-lg">Sélectionnez un voyage pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 font-medium">
              ou créez un nouveau voyage
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
              <div class="p-6">
              <!-- Header Row -->
              <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4 mb-6">
                <div>
                  <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold mb-3 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <Ticket class="h-4 w-4" />
                    Détail du voyage
                  </div>
                  <h2 class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ selectedTrip.route?.name }}</h2>
                  <p class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">{{ selectedTrip.code || 'Code en attente' }}</p>
                  <p class="text-sm text-slate-500 dark:text-slate-400">{{ formatDate(selectedTrip.departure_at) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <button
                    @click="exportSelectedTicketsToExcel"
                    :disabled="exportingTripExcel || !hasVisibleTickets"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                    title="Exporter les tickets en CSV compatible Excel"
                  >
                    <FileExcel :size="18" />
                    <span class="text-sm font-medium">CSV</span>
                  </button>
                  <button
                    @click="exportSelectedTicketsToPdf"
                    :disabled="exportingTripPdf || !hasVisibleTickets"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                    title="Exporter les tickets en PDF"
                  >
                    <FilePdfBox :size="18" />
                    <span class="text-sm font-medium">PDF</span>
                  </button>
                  <button @click="openEditModal" class="p-2 text-sky-600 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-950/40 rounded-xl transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteTrip(selectedTrip.id)" class="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 rounded-xl transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Stats Row -->
              <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <div class="rounded-2xl p-4 text-center bg-gradient-to-br from-blue-50 to-blue-100/80 dark:from-blue-950/30 dark:to-blue-950/10 border border-blue-100 dark:border-blue-900/30">
                  <p class="text-2xl font-black text-blue-700 dark:text-blue-300">{{ selectedTrip.tickets_count || 0 }}</p>
                  <p class="text-xs font-medium text-blue-600 dark:text-blue-400">Tickets vendus</p>
                </div>
                <div class="rounded-2xl p-4 text-center bg-gradient-to-br from-emerald-50 to-emerald-100/80 dark:from-emerald-950/30 dark:to-emerald-950/10 border border-emerald-100 dark:border-emerald-900/30">
                  <p class="text-2xl font-black text-emerald-700 dark:text-emerald-300">{{ selectedTrip.occupied_seats_count || 0 }}/{{ selectedTrip.vehicle?.seat_count || 0 }}</p>
                  <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Sièges occupés</p>
                </div>
                <div class="rounded-2xl p-4 text-center bg-gradient-to-br from-amber-50 to-amber-100/80 dark:from-amber-950/30 dark:to-amber-950/10 border border-amber-100 dark:border-amber-900/30">
                  <p class="text-2xl font-black text-amber-700 dark:text-amber-300">{{ fillPercentage }}%</p>
                  <p class="text-xs font-medium text-amber-600 dark:text-amber-400">Remplissage</p>
                </div>
                <div class="rounded-2xl p-4 text-center bg-gradient-to-br from-fuchsia-50 to-fuchsia-100/80 dark:from-fuchsia-950/30 dark:to-fuchsia-950/10 border border-fuchsia-100 dark:border-fuchsia-900/30 col-span-2">
                  <p class="text-2xl font-black text-fuchsia-700 dark:text-fuchsia-300">{{ formatMoney(totalRevenue) }}</p>
                  <p class="text-xs font-medium text-fuchsia-600 dark:text-fuchsia-400">Revenus</p>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-4">
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 uppercase tracking-wider font-bold block mb-1">VÉHICULE</span>
                  <div class="text-lg font-medium text-slate-900 dark:text-slate-100">
                    {{ selectedTrip.vehicle?.identifier }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 uppercase tracking-wider font-bold block mb-1">STATUT</span>
                  <span :class="[
                    'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                    getStatusInfo(selectedTrip.status, selectedTrip.departure_at).color
                  ]">
                    {{ getStatusInfo(selectedTrip.status, selectedTrip.departure_at).label }}
                  </span>
                </div>
              </div>
              </div>
            </div>

            <!-- Destination Breakdown -->
            <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-4">
              <h3 class="font-semibold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                <Ticket class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                Répartition par Destination
              </h3>
              
              <div v-if="destinationBreakdown.length === 0" class="text-center py-4 text-slate-400 dark:text-slate-500">
                Aucun ticket vendu
              </div>
              
              <div v-else class="space-y-2">
                <div 
                  v-for="dest in destinationBreakdown" 
                  :key="dest.name"
                  class="flex items-center justify-between p-3 bg-slate-50/80 dark:bg-slate-950/40 rounded-xl"
                >
                  <div class="flex items-center gap-3 flex-1">
                    <span class="font-medium text-slate-800 dark:text-slate-200 text-sm">{{ dest.name }}</span>
                    <span class="px-2 py-0.5 bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-bold rounded-full">
                      {{ dest.count }}
                    </span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400">({{ dest.percentage }}%)</span>
                  </div>
                  <span class="text-sm text-slate-600 dark:text-slate-300 font-medium">{{ formatMoney(dest.revenue) }}</span>
                </div>
              </div>
            </div>

            <!-- Tickets List -->
            <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-4">
              <div class="flex items-center justify-between gap-3 mb-3">
                <h3 class="font-semibold text-slate-700 dark:text-slate-200">Liste des Tickets</h3>
                <span class="text-xs text-slate-500 dark:text-slate-400">
                  {{ visibleTickets.length }} ticket(s)
                </span>
              </div>
              
              <div v-if="!hasVisibleTickets" class="text-center py-4 text-slate-400 dark:text-slate-500">
                Aucun ticket vendu
              </div>
              
              <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-slate-50 dark:bg-slate-950/50">
                    <tr>
                      <th class="px-3 py-2 text-left text-[10px] font-medium text-slate-500 uppercase">N°</th>
                      <th 
                        class="px-3 py-2 text-left text-[10px] font-medium text-slate-500 uppercase cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 select-none"
                        @click="toggleTicketSort('seat')"
                      >
                        <span class="flex items-center gap-1">
                          Place
                          <span :class="ticketSortBy === 'seat' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-300 dark:text-slate-600'">
                            {{ ticketSortBy === 'seat' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                      <th 
                        class="px-3 py-2 text-left text-[10px] font-medium text-slate-500 uppercase cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 select-none"
                        @click="toggleTicketSort('distance')"
                      >
                        <span class="flex items-center gap-1">
                          Destination
                          <span :class="ticketSortBy === 'distance' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-300 dark:text-slate-600'">
                            {{ ticketSortBy === 'distance' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                      <th 
                        class="px-3 py-2 text-right text-[10px] font-medium text-slate-500 uppercase cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 select-none"
                        @click="toggleTicketSort('price')"
                      >
                        <span class="flex items-center justify-end gap-1">
                          Prix
                          <span :class="ticketSortBy === 'price' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-300 dark:text-slate-600'">
                            {{ ticketSortBy === 'price' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    <tr v-for="ticket in orderedTickets" :key="ticket.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <td class="px-3 py-2 font-mono text-slate-800 dark:text-slate-200">{{ ticket.ticket_number }}</td>
                      <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ ticket.seat_number }}</td>
                      <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ ticket.to_station?.name || ticket.toStation?.name || '-' }}</td>
                      <td class="px-3 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ formatMoney(ticket.price) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier le Voyage' : 'Nouveau Voyage' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div v-if="props.replicableTrips && props.replicableTrips.length > 0">
            <InputLabel for="template_select_admin" value="Sélectionner un modèle de voyage récurrent" />
            <select
              id="template_select_admin"
              v-model="selectedTemplate"
              class="w-full px-3 py-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
            >
              <option :value="null">-- Voyage personnalisé (créer de zéro) --</option>
              <option v-for="t in props.replicableTrips" :key="t.id" :value="t">
                {{ getRouteName(t.route_id) }} (Départ : {{ t.time }})
              </option>
            </select>
          </div>

          <div>
            <InputLabel for="route_id" value="Route" />
            <select
              id="route_id"
              v-model="form.route_id"
              class="w-full px-3 py-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100">Sélectionner une route</option>
              <option
                v-for="r in routes"
                :key="r.id"
                :value="r.id"
                class="bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100"
              >
                {{ r.name }} ({{ r.origin_station?.name || r.originStation?.name || r.route_stop_orders?.[0]?.station?.name || r.routeStopOrders?.[0]?.station?.name || 'Départ' }} → {{ r.destination_station?.name || r.destinationStation?.name || r.route_stop_orders?.[r.route_stop_orders.length - 1]?.station?.name || r.routeStopOrders?.[r.routeStopOrders.length - 1]?.station?.name || 'Arrivée' }})
              </option>
            </select>
            <InputError :message="errors.route_id" />
          </div>

          <div>
            <InputLabel for="vehicle_id" value="Véhicule" />
            <select
              id="vehicle_id"
              v-model="form.vehicle_id"
              class="w-full px-3 py-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
            >
              <option value="" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100">Sélectionner un véhicule</option>
              <option
                v-for="vehicle in vehicles"
                :key="vehicle.id"
                :value="vehicle.id"
                class="bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100"
              >
                {{ vehicle.identifier }}
              </option>
            </select>
            <InputError :message="errors.vehicle_id" />
          </div>

          <div>
            <InputLabel for="departure_at" value="Date et Heure de Départ" />
            <TextInput v-model="form.departure_at" id="departure_at" type="datetime-local" class="w-full" />
            <InputError :message="errors.departure_at" />
          </div>

          <div>
            <InputLabel for="code" value="Code / Numéro de Voyage" />
            <TextInput v-model="form.code" id="code" type="text" class="w-full" placeholder="Ex: ABJ-BKE-0800" />
            <InputError :message="errors.code" />
          </div>

          <div>
            <InputLabel for="status" value="Statut" />
            <select
              id="status"
              v-model="form.status"
              class="w-full px-3 py-1.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
            >
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100">
                {{ opt.label }}
              </option>
            </select>
            <InputError :message="errors.status" />
          </div>

          <label class="flex items-start gap-3 rounded-xl border border-slate-200 dark:border-slate-700 p-3 cursor-pointer">
            <input v-model="form.allows_open_connections" type="checkbox" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
            <span>
              <span class="block text-sm font-medium text-slate-900 dark:text-slate-100">Correspondances ouvertes</span>
              <span class="block text-xs text-slate-500 dark:text-slate-400">Les billets peuvent indiquer une destination finale au-delà de l’arrivée de ce voyage.</span>
            </span>
          </label>

          <label class="flex items-start gap-3 rounded-xl border border-slate-200 dark:border-slate-700 p-3 cursor-pointer">
            <input v-model="form.is_replicable" type="checkbox" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
            <span>
              <span class="block text-sm font-medium text-slate-900 dark:text-slate-100">Voyage réplicable (récurrent)</span>
              <span class="block text-xs text-slate-500 dark:text-slate-400">Ce voyage sera automatiquement recréé chaque jour à minuit (sans bus ni équipage affectés).</span>
            </span>
          </label>

          <div>
            <InputLabel for="admin_trip_auto_allocation" value="Allocation automatique" />
            <select id="admin_trip_auto_allocation" v-model="form.automatic_connection_allocation" class="w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
              <option :value="null">Hériter du trajet et de la compagnie</option>
              <option :value="true">Activer pour ce voyage</option>
              <option :value="false">Désactiver pour ce voyage</option>
            </select>
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
  background: #cbd5e1;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
