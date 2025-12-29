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
import MapClock from 'vue-material-design-icons/MapClock.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';

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

const form = ref({
  route_id: '',
  vehicle_id: '',
  departure_at: '',
  status: 'scheduled'
});

// Status options
const statusOptions = [
  { value: 'scheduled', label: 'Programmé', color: 'bg-blue-100 text-blue-800' },
  { value: 'boarding', label: 'Embarquement', color: 'bg-yellow-100 text-yellow-800' },
  { value: 'departed', label: 'Effectué', color: 'bg-purple-100 text-purple-800' },
  { value: 'arrived', label: 'Arrivé', color: 'bg-green-100 text-green-800' },
  { value: 'cancelled', label: 'Annulé', color: 'bg-red-100 text-red-800' }
];

// Unique departures and arrivals for filters
const uniqueDepartures = computed(() => {
  const stations = new Map();
  props.routes.forEach(r => {
    if (r.origin_station) {
      stations.set(r.origin_station.id, r.origin_station);
    }
  });
  return Array.from(stations.values());
});

const uniqueArrivals = computed(() => {
  const stations = new Map();
  props.routes.forEach(r => {
    if (r.destination_station) {
      stations.set(r.destination_station.id, r.destination_station);
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
      trip.route?.origin_station?.id === departureFilter.value
    );
  }
  
  // Filter by arrival station
  if (arrivalFilter.value) {
    trips = trips.filter(trip => 
      trip.route?.destination_station?.id === arrivalFilter.value
    );
  }
  
  return trips;
});

// Get status display info - past trips cannot be "scheduled"
const getStatusInfo = (status, departureAt) => {
  // If departure is in the past and status is still scheduled, show as "Effectué"
  if (departureAt && new Date(departureAt) < new Date() && status === 'scheduled') {
    return { value: 'departed', label: 'Effectué', color: 'bg-purple-100 text-purple-800' };
  }
  return statusOptions.find(s => s.value === status) || { label: status, color: 'bg-gray-100 text-gray-800' };
};

// Calculate destination breakdown with percentage
const destinationBreakdown = computed(() => {
  if (!selectedTrip.value?.tickets) return [];
  
  const totalTickets = selectedTrip.value.tickets.length;
  const breakdown = new Map();
  selectedTrip.value.tickets.forEach(ticket => {
    const destName = ticket.to_stop?.name || 'Inconnu';
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
  if (!selectedTrip.value?.tickets) return [];
  
  // Build stop index map from route (handle both snake_case and camelCase)
  const stopIndexMap = new Map();
  const stopOrders = selectedTrip.value.route?.route_stop_orders || selectedTrip.value.route?.routeStopOrders || [];
  
  stopOrders.forEach(order => {
    // Map both possible field names
    const stopId = order.stop_id || order.stopId;
    const stopIndex = order.stop_index ?? order.stopIndex ?? 999;
    if (stopId) {
      stopIndexMap.set(stopId, stopIndex);
    }
  });
  
  return [...selectedTrip.value.tickets].sort((a, b) => {
    let comparison = 0;
    
    switch (ticketSortBy.value) {
      case 'distance':
        // Try getting the stop_id from both the direct field and the loaded relation
        const stopIdA = a.to_stop_id || a.toStopId || a.to_stop?.id;
        const stopIdB = b.to_stop_id || b.toStopId || b.to_stop?.id;
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
  const occupied = selectedTrip.value.occupied_seats || 0;
  const total = selectedTrip.value.vehicle.seat_count;
  return Math.round((occupied / total) * 100);
});

// Total revenue
const totalRevenue = computed(() => {
  if (!selectedTrip.value?.tickets) return 0;
  return selectedTrip.value.tickets.reduce((sum, t) => sum + (t.price || 0), 0);
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
    route_id: '',
    vehicle_id: '',
    departure_at: '',
    status: 'scheduled'
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedTrip.value) return;
  isEditing.value = true;
  form.value = {
    route_id: selectedTrip.value.route_id,
    vehicle_id: selectedTrip.value.vehicle_id,
    departure_at: selectedTrip.value.departure_at.slice(0, 16),
    status: selectedTrip.value.status || 'scheduled'
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    route_id: '',
    vehicle_id: '',
    departure_at: '',
    status: 'scheduled'
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

// Export/Print configuration
const tripColumns = {
  'route.name': 'Route',
  departure_at: 'Départ',
  'vehicle.identifier': 'Véhicule',
  status: 'Statut',
  tickets_count: 'Tickets'
};

const handleExport = () => {
  const data = filteredTrips.value.map(trip => ({
    ...trip,
    departure_at: formatDate(trip.departure_at),
    status: getStatusInfo(trip.status, trip.departure_at).label
  }));
  exportToExcel(data, tripColumns, 'voyages');
};

const handlePrint = () => {
  const data = filteredTrips.value.map(trip => ({
    ...trip,
    departure_at: formatDate(trip.departure_at),
    status: getStatusInfo(trip.status, trip.departure_at).label
  }));
  printList(data, tripColumns, 'Liste des Voyages');
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
              <Calendar class="text-green-600" :size="28" />
            </div>
            Gestion des Voyages
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

        <!-- Middle Column - Trips List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouveau Voyage">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              
              <!-- Filters -->
              <div class="grid grid-cols-3 gap-2">
                <input 
                  type="date" 
                  v-model="dateFilter"
                  class="px-2 py-1 border border-orange-200 rounded text-xs focus:outline-none focus:border-orange-400"
                  title="Filtrer par date"
                />
                <select 
                  v-model="departureFilter"
                  class="px-2 py-1 border border-orange-200 rounded text-xs focus:outline-none focus:border-orange-400"
                >
                  <option value="">Départ</option>
                  <option v-for="station in uniqueDepartures" :key="station.id" :value="station.id">
                    {{ station.name }}
                  </option>
                </select>
                <select 
                  v-model="arrivalFilter"
                  class="px-2 py-1 border border-orange-200 rounded text-xs focus:outline-none focus:border-orange-400"
                >
                  <option value="">Arrivée</option>
                  <option v-for="station in uniqueArrivals" :key="station.id" :value="station.id">
                    {{ station.name }}
                  </option>
                </select>
              </div>
              <div class="flex items-center justify-between mt-2">
                <button 
                  v-if="dateFilter || departureFilter || arrivalFilter"
                  @click="clearFilters" 
                  class="text-xs text-orange-600 hover:text-orange-800"
                >
                  Effacer les filtres
                </button>
                <div class="ml-auto">
                  <ExportPrintButtons 
                    :disabled="filteredTrips.length === 0"
                    small
                    @export="handleExport"
                    @print="handlePrint"
                  />
                </div>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1">
              <div v-if="filteredTrips.length === 0" class="p-4 text-center text-gray-500">
                Aucun voyage trouvé.
              </div>
              <div v-else>
                <div v-for="trip in filteredTrips" :key="trip.id" 
                  @click="selectTrip(trip)"
                  class="p-3 cursor-pointer transition-colors"
                  :style="{
                    backgroundColor: isSelected(trip) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(trip) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['font-semibold truncate', isSelected(trip) ? 'text-green-800' : 'text-gray-800']">
                        {{ trip.route?.name }}
                      </h3>
                      <p class="text-xs text-gray-500 mt-1">{{ formatShortDate(trip.departure_at) }}</p>
                      <p class="text-xs text-gray-400">{{ trip.vehicle?.identifier }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        getStatusInfo(trip.status, trip.departure_at).color
                      ]">
                        {{ getStatusInfo(trip.status, trip.departure_at).label }}
                      </span>
                      <span class="text-xs text-gray-500">
                        {{ trip.tickets_count || 0 }} tickets
                      </span>
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
          <div v-if="!selectedTrip" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <MapClock class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez un voyage pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez un nouveau voyage
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <div>
                  <h2 class="text-2xl font-bold text-gray-800">{{ selectedTrip.route?.name }}</h2>
                  <p class="text-sm text-gray-500">{{ formatDate(selectedTrip.departure_at) }}</p>
                </div>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteTrip(selectedTrip.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Stats Row -->
              <div class="grid grid-cols-4 gap-4 mb-6">
                <div class="bg-blue-50 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-blue-700">{{ selectedTrip.tickets_count || 0 }}</p>
                  <p class="text-xs text-blue-600">Tickets vendus</p>
                </div>
                <div class="bg-green-50 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-green-700">{{ fillPercentage }}%</p>
                  <p class="text-xs text-green-600">Remplissage</p>
                </div>
                <div class="bg-orange-50 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-orange-700">{{ selectedTrip.vehicle?.seat_count || 0 }}</p>
                  <p class="text-xs text-orange-600">Places totales</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-purple-700">{{ formatMoney(totalRevenue) }}</p>
                  <p class="text-xs text-purple-600">Revenus</p>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-4">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-1">VÉHICULE</span>
                  <div class="text-lg font-medium text-gray-900">
                    {{ selectedTrip.vehicle?.identifier }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-1">STATUT</span>
                  <span :class="[
                    'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                    getStatusInfo(selectedTrip.status, selectedTrip.departure_at).color
                  ]">
                    {{ getStatusInfo(selectedTrip.status, selectedTrip.departure_at).label }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Destination Breakdown -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
              <h3 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                <Ticket class="h-5 w-5 text-green-600" />
                Répartition par Destination
              </h3>
              
              <div v-if="destinationBreakdown.length === 0" class="text-center py-4 text-gray-400">
                Aucun ticket vendu
              </div>
              
              <div v-else class="space-y-2">
                <div 
                  v-for="dest in destinationBreakdown" 
                  :key="dest.name"
                  class="flex items-center justify-between p-2 bg-gray-50 rounded-lg"
                >
                  <div class="flex items-center gap-3 flex-1">
                    <span class="font-medium text-gray-800">{{ dest.name }}</span>
                    <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-bold rounded-full">
                      {{ dest.count }}
                    </span>
                    <span class="text-xs text-gray-500">({{ dest.percentage }}%)</span>
                  </div>
                  <span class="text-sm text-gray-600 font-medium">{{ formatMoney(dest.revenue) }}</span>
                </div>
              </div>
            </div>

            <!-- Tickets List -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
              <h3 class="font-semibold text-gray-700 mb-3">Liste des Tickets</h3>
              
              <div v-if="!selectedTrip.tickets || selectedTrip.tickets.length === 0" class="text-center py-4 text-gray-400">
                Aucun ticket vendu
              </div>
              
              <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">N°</th>
                      <th 
                        class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                        @click="toggleTicketSort('seat')"
                      >
                        <span class="flex items-center gap-1">
                          Place
                          <span :class="ticketSortBy === 'seat' ? 'text-green-600' : 'text-gray-300'">
                            {{ ticketSortBy === 'seat' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                      <th 
                        class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                        @click="toggleTicketSort('distance')"
                      >
                        <span class="flex items-center gap-1">
                          Destination
                          <span :class="ticketSortBy === 'distance' ? 'text-green-600' : 'text-gray-300'">
                            {{ ticketSortBy === 'distance' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                      <th 
                        class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                        @click="toggleTicketSort('price')"
                      >
                        <span class="flex items-center justify-end gap-1">
                          Prix
                          <span :class="ticketSortBy === 'price' ? 'text-green-600' : 'text-gray-300'">
                            {{ ticketSortBy === 'price' ? (ticketSortAsc ? '↑' : '↓') : '↕' }}
                          </span>
                        </span>
                      </th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-for="ticket in orderedTickets" :key="ticket.id" class="hover:bg-gray-50">
                      <td class="px-3 py-2 font-mono text-xs">{{ ticket.ticket_number }}</td>
                      <td class="px-3 py-2">{{ ticket.seat_number }}</td>
                      <td class="px-3 py-2">{{ ticket.to_stop?.name || '-' }}</td>
                      <td class="px-3 py-2 text-right font-medium">{{ formatMoney(ticket.price) }}</td>
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
    <DialogModal :show="showModal" @close="closeModal">
      <template #title>
        {{ isEditing ? 'Modifier le Voyage' : 'Nouveau Voyage' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="route_id" value="Route" />
            <select
              id="route_id"
              v-model="form.route_id"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="">Sélectionner une route</option>
              <option
                v-for="r in routes"
                :key="r.id"
                :value="r.id"
              >
                {{ r.name }} ({{ r.origin_station?.name }} → {{ r.destination_station?.name }})
              </option>
            </select>
            <InputError :message="errors.route_id" />
          </div>

          <div>
            <InputLabel for="vehicle_id" value="Véhicule" />
            <select
              id="vehicle_id"
              v-model="form.vehicle_id"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="">Sélectionner un véhicule</option>
              <option
                v-for="vehicle in vehicles"
                :key="vehicle.id"
                :value="vehicle.id"
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
            <InputLabel for="status" value="Statut" />
            <select
              id="status"
              v-model="form.status"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
            >
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <InputError :message="errors.status" />
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
