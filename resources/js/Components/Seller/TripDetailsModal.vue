<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { toastStore } from '@/Stores/toastStore.js';
import { confirmationStore } from '@/Stores/confirmationStore.js';
import Close from 'vue-material-design-icons/Close.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Account from 'vue-material-design-icons/Account.vue';
import MapMarker from 'vue-material-design-icons/MapMarker.vue';
import Eye from 'vue-material-design-icons/Eye.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import TrashCan from 'vue-material-design-icons/TrashCan.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import Alert from 'vue-material-design-icons/Alert.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';

const props = defineProps({
  visible: Boolean,
  tripId: String,
  assignedStation: String,
  assignedStationId: String,
  currentUserRole: String,
  currentUserId: [String, Number],
  initialTab: {
    type: String,
    default: 'overview'
  }
});

const emit = defineEmits(['close', 'ticket-cancelled']);

const { t } = useI18n();

const activeTab = ref('overview'); // overview, gps, tickets, transit
const tickets = ref([]);
const transitPool = ref([]);
const tripDetails = ref(null);
const latestPosition = ref(null);
const statusReports = ref([]);

const loading = ref(false);
const cancelingTicketId = ref(null);
const compensatingTicketId = ref(null);
const searchQuery = ref('');
const connectionSeats = ref({});
const assigningConnectionId = ref(null);
const markingReadyConnectionId = ref(null);

const canManageTransit = computed(() => {
  if (['admin', 'supervisor'].includes(props.currentUserRole)) return true;
  if (!props.assignedStationId) return false;
  return String(tripDetails.value?.origin_station_id) === String(props.assignedStationId);
});

const tabs = computed(() => {
  const items = [
    { id: 'overview', label: t('ticketing.trip_details.overview') },
    { id: 'gps', label: t('ticketing.trip_details.gps_crew') },
    { id: 'tickets', label: `${t('ticketing.trip_details.tickets_occupancy')} (${tickets.value.length})` }
  ];
  if (transitPool.value.length > 0) {
    items.push({ id: 'transit', label: `${t('ticketing.trip_details.transit_pool')} (${transitPool.value.length})` });
  }
  return items;
});

const markConnectionReady = async (connectionId) => {
  markingReadyConnectionId.value = connectionId;
  try {
    await axios.patch(route('seller.transfer-pool.ready', { connection: connectionId }));
    toastStore.success('Passager marqué présent.');
    await loadTripData();
  } catch (error) {
    console.error('Erreur lors du marquage comme présent:', error);
    toastStore.error(error.response?.data?.message || 'Action impossible.');
  } finally {
    markingReadyConnectionId.value = null;
  }
};

const assignConnectionSeat = async (connectionId) => {
  const seatNumber = connectionSeats.value[connectionId];
  if (!seatNumber) {
    toastStore.warning('Veuillez entrer un numéro de siège.');
    return;
  }
  assigningConnectionId.value = connectionId;
  try {
    await axios.post(route('seller.transfer-pool.assign', { trip: props.tripId }), {
      connection_id: connectionId,
      seat_number: Number(seatNumber)
    });
    toastStore.success('Siège attribué avec succès.');
    delete connectionSeats.value[connectionId];
    emit('ticket-cancelled'); // Notify parent to refresh seat map
    await loadTripData();
  } catch (error) {
    console.error('Erreur lors de l\'attribution du siège:', error);
    toastStore.error(error.response?.data?.message || 'Attribution impossible.');
  } finally {
    assigningConnectionId.value = null;
  }
};

const autoAllocating = ref(false);

const autoAllocateConnections = async () => {
  autoAllocating.value = true;
  try {
    const response = await axios.post(route('seller.transfer-pool.allocate', { trip: props.tripId }));
    toastStore.success(response.data.message || 'Affectation automatique réussie.');
    emit('ticket-cancelled'); // Notify parent to refresh seat map
    await loadTripData();
  } catch (error) {
    console.error('Erreur lors du dispatching automatique:', error);
    toastStore.error(error.response?.data?.message || 'Répartition automatique impossible.');
  } finally {
    autoAllocating.value = false;
  }
};

// Fetch all details for the trip
const loadTripData = async () => {
  if (!props.tripId) return;
  loading.value = true;
  try {
    const [detailsRes, posRes, reportsRes] = await Promise.all([
      axios.get(route('seller.trips.details', { trip: props.tripId })),
      axios.get(route('seller.trips.latest-position', { trip: props.tripId })),
      axios.get(route('seller.trips.status-reports', { trip: props.tripId }))
    ]);

    tripDetails.value = {
      ...detailsRes.data.trip,
      driver: detailsRes.data.driver,
      assistant: detailsRes.data.assistant
    };
    latestPosition.value = posRes.data.report;
    statusReports.value = reportsRes.data.reports;
    tickets.value = detailsRes.data.occupancies || [];
    transitPool.value = detailsRes.data.transit_pool || [];
    if (transitPool.value.length === 0 && activeTab.value === 'transit') {
      activeTab.value = 'overview';
    }
  } catch (error) {
    console.error('Erreur lors du chargement des détails du voyage:', error);
    toastStore.error('Erreur lors de la récupération des informations du voyage.');
  } finally {
    loading.value = false;
  }
};

const viewTicket = (ticketId) => {
  const viewUrl = route('tickets.view', { ticket: ticketId });
  const viewWindow = window.open(viewUrl, '_blank', 'width=480,height=760');
  if (!viewWindow) {
    toastStore.warning(t('ticketing.trip_details.view_popup_warning'));
  }
};

// Print ticket (uses browser print fallback)
const printTicket = (ticketId) => {
  const printUrl = route('tickets.print', { ticket: ticketId });
  const printWindow = window.open(printUrl, '_blank', 'width=400,height=600');
  if (!printWindow) {
    toastStore.warning('Veuillez autoriser les popups pour imprimer le ticket.');
  }
};

// Cancel ticket
const cancelTicket = async (ticketId, seatNumber) => {
  if (!await confirmationStore.confirm({ title: 'Annuler ce ticket', message: `Annuler le ticket pour la place ${seatNumber} ? La place sera libérée.`, confirmLabel: 'Annuler le ticket', tone: 'danger' })) {
    return;
  }
  cancelingTicketId.value = ticketId;
  try {
    await axios.delete(route('seller.tickets.destroy', { ticket: ticketId }));
    toastStore.success(`Le ticket pour la place ${seatNumber} a été annulé.`);
    
    // Notify parent to refresh seat map
    emit('ticket-cancelled');
    
    // Reload local data
    await loadTripData();
  } catch (error) {
    console.error('Erreur lors de l\'annulation du ticket:', error);
    const msg = error.response?.data?.message || 'Impossible d\'annuler le ticket.';
    toastStore.error(msg);
  } finally {
    cancelingTicketId.value = null;
  }
};

// Check if user can cancel a specific ticket
const canCancel = (ticket) => {
  if (['admin', 'supervisor'].includes(props.currentUserRole)) {
    return true;
  }
  // Vendeur can cancel only their own tickets
  return String(ticket.seller_id) === String(props.currentUserId);
};

const compensateTicket = async (ticket) => {
  const type = prompt('Type : refund, credit, free_rebooking, fare_adjustment ou exceptional_care', 'credit');
  if (!type) return;
  const reason = prompt('Motif obligatoire de la compensation :');
  if (!reason) return;
  const amount = Number(prompt('Montant de la compensation (FCFA) :', '0') || 0);
  const payload = { incident_type: 'commercial', compensation_type: type, reason, amount };
  if (type === 'free_rebooking') {
    payload.replacement_trip_id = prompt('Identifiant du voyage de remplacement :');
    payload.replacement_seat_number = Number(prompt('Numéro du siège de remplacement :'));
  }
  compensatingTicketId.value = ticket.id;
  try {
    const response = await axios.post(route('seller.tickets.compensations.store', { ticket: ticket.id }), payload);
    toastStore.success(response.data.message);
    await loadTripData();
  } catch (error) {
    toastStore.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Compensation impossible.');
  } finally {
    compensatingTicketId.value = null;
  }
};

// Filtered tickets based on query
const filteredTickets = computed(() => {
  if (!searchQuery.value) return tickets.value;
  const query = searchQuery.value.toLowerCase().trim();
  return tickets.value.filter(t => {
    const seatMatch = String(t.seat_number) === query;
    const nameMatch = t.passenger_name?.toLowerCase().includes(query);
    const phoneMatch = t.passenger_phone?.includes(query);
    const tktNumMatch = t.ticket_number?.toLowerCase().includes(query);
    const destinationMatch = t.final_destination?.name?.toLowerCase().includes(query);
    const connectionTripMatch = t.connection_trip?.code?.toLowerCase().includes(query);
    return seatMatch || nameMatch || phoneMatch || tktNumMatch || destinationMatch || connectionTripMatch;
  });
});

// Format date for humans
const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
};

// Format time
const formatTime = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
};

// Dynamic trip status mapping
const getStatusLabel = (status) => {
  const map = {
    scheduled: 'Programmé',
    boarding: 'Embarquement',
    departed: 'Parti',
    arrived: 'Arrivé',
    cancelled: 'Annulé'
  };
  return map[status] || status;
};

const getStatusClass = (status) => {
  const map = {
    scheduled: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    boarding: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 animate-pulse',
    departed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    arrived: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
    cancelled: 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300'
  };
  return map[status] || 'bg-slate-100 text-slate-800';
};

const getTicketStatusLabel = (ticket) => {
  if (ticket.status === 'cancelled') return 'Annulé';
  if (ticket.connection_has_conflict) return 'Conflit horaire';
  if (ticket.journey_type === 'direct') return 'Valide';

  const labels = {
    pending: 'Attendu',
    ready: 'Présent',
    assigned: 'Affecté',
    boarded: 'Embarqué',
    completed: 'Terminé',
    missed: 'Correspondance manquée',
    cancelled: 'Annulé'
  };
  return labels[ticket.connection_status] || 'Correspondance';
};

// GPS report status mapping
const getReportStatusLabel = (status) => {
  const map = {
    normal: 'Normal',
    traffic_jam: 'Embouteillage',
    accident: 'Accident',
    mechanical_trouble: 'Panne mécanique'
  };
  return map[status] || status;
};

const getReportStatusClass = (status) => {
  const map = {
    normal: 'bg-emerald-500',
    traffic_jam: 'bg-yellow-500',
    accident: 'bg-rose-500',
    mechanical_trouble: 'bg-orange-500'
  };
  return map[status] || 'bg-slate-500';
};

// Check if trip is reversed relative to the route
const isReversed = computed(() => {
  if (!tripDetails.value) return false;
  const route = tripDetails.value.route;
  if (!route) return false;

  const tripOriginId = tripDetails.value.origin_station_id;
  const tripDestId = tripDetails.value.destination_station_id;

  if (!tripOriginId || !tripDestId) return false;

  const stops = route.route_stop_orders || [];
  const originStop = stops.find(s => s.station_id === tripOriginId);
  const destStop = stops.find(s => s.station_id === tripDestId);

  if (originStop && destStop) {
    return originStop.stop_index > destStop.stop_index;
  }

  return route.origin_station_id && tripOriginId !== route.origin_station_id;
});

// Dynamic stops timeline
const timelineStops = computed(() => {
  if (!tripDetails.value) return [];
  const route = tripDetails.value.route;
  if (!route) return [];

  // Re-build standard list of stations: origin -> middle stops -> destination
  const orderedStationIds = [];
  const orderedStops = [];
  const addStation = (station, stopOrder = null) => {
    const stationId = station?.id || station;
    if (!stationId || orderedStationIds.includes(stationId)) return;
    orderedStationIds.push(stationId);
    orderedStops.push(stopOrder || {
      id: stationId,
      station: station,
      stop_index: orderedStops.length
    });
  };

  // Add route origin station if defined
  if (route.origin_station || route.origin_station_id) {
    addStation(route.origin_station || { id: route.origin_station_id, name: route.origin_station?.name });
  }

  // Add all stop orders sorted by stop_index
  const stops = [...(route.route_stop_orders || [])]
    .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
  stops.forEach(stop => {
    if (stop.station) {
      addStation(stop.station, stop);
    }
  });

  // Add route destination station if defined
  if (route.destination_station || route.destination_station_id) {
    addStation(route.destination_station || { id: route.destination_station_id, name: route.destination_station?.name });
  }

  // If reversed trip, reverse the timeline
  if (isReversed.value) {
    orderedStops.reverse();
  }

  return orderedStops;
});

// Get count of valid tickets departing from a specific station
const getTicketsCountForStation = (stationId) => {
  if (!tickets.value) return 0;
  return tickets.value.filter(t => 
    t.status !== 'cancelled' && 
    String(t.from_station_id) === String(stationId)
  ).length;
};

// Watchers
watch(() => props.visible, (val) => {
  if (val) {
    activeTab.value = props.initialTab || 'overview';
    loadTripData();
  }
});
</script>

<template>
  <div v-if="visible" class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-900/60 dark:bg-black/70 backdrop-blur-sm transition-all duration-300">
    <div class="relative w-full max-w-4xl h-[85vh] flex flex-col bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.18)] overflow-hidden">
      
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-850 px-6 py-4 bg-slate-50/70 dark:bg-slate-950/20">
        <div>
          <h3 class="text-xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-2">
            <Bus class="text-emerald-600 dark:text-emerald-400" />
            <span>{{ $t('ticketing.trip_details.trip_details_title') }}</span>
          </h3>
          <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-0.5">
            {{ tripDetails?.code || '-' }} • {{ tripDetails?.display_name || '-' }}
          </p>
        </div>
        <div class="flex items-center gap-3">
          <button @click="loadTripData" :disabled="loading" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 rounded-xl transition-all active:scale-95 disabled:opacity-50">
            <Refresh :class="loading ? 'animate-spin' : ''" :size="20" />
          </button>
          <button @click="emit('close')" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-500 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-400 rounded-xl transition-all active:scale-95">
            <Close :size="20" />
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="flex border-b border-slate-200 dark:border-slate-850 px-6 bg-slate-50/30 dark:bg-slate-950/10">
        <button 
          v-for="tab in tabs" 
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'py-3.5 px-4 font-bold text-sm border-b-2 transition-all relative -mb-[2px]',
            activeTab === tab.id 
              ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' 
              : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
          ]"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Content Area -->
      <div class="flex-1 overflow-y-auto p-6 min-h-0 bg-slate-50/30 dark:bg-slate-900/10">
        <div v-if="loading && !tripDetails" class="h-full flex items-center justify-center flex-col gap-3">
          <div class="w-10 h-10 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin"></div>
          <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $t('common.loading_data') }}</span>
        </div>

        <template v-else-if="tripDetails">
          
          <!-- TAB 1: OVERVIEW -->
          <div v-if="activeTab === 'overview'" class="space-y-6 animate-fadeIn">
            
            <!-- Trip Quick Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="bg-white dark:bg-slate-950/40 p-4 border border-slate-250/60 dark:border-slate-800/80 rounded-2xl flex items-center gap-4">
                <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400 rounded-xl">
                  <Bus :size="24" />
                </div>
                <div>
                  <div class="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.vehicle') }}</div>
                  <div class="text-base font-black text-slate-800 dark:text-slate-200">{{ tripDetails.vehicle?.identifier || $t('ticketing.trip_details.unassigned') }}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">{{ tripDetails.vehicle?.vehicle_type?.name || '-' }}</div>
                </div>
              </div>

              <div class="bg-white dark:bg-slate-950/40 p-4 border border-slate-250/60 dark:border-slate-800/80 rounded-2xl flex items-center gap-4">
                <div class="p-3 bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400 rounded-xl">
                  <Clock :size="24" />
                </div>
                <div>
                  <div class="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.departure') }}</div>
                  <div class="text-base font-black text-slate-800 dark:text-slate-200">{{ formatTime(tripDetails.departure_at) }}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(tripDetails.departure_at) }}</div>
                </div>
              </div>

              <div class="bg-white dark:bg-slate-950/40 p-4 border border-slate-250/60 dark:border-slate-800/80 rounded-2xl flex items-center gap-4">
                <div class="p-3 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 rounded-xl">
                  <CheckCircle :size="24" />
                </div>
                <div>
                  <div class="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.trip_status') }}</div>
                  <div class="mt-1">
                    <span :class="['px-2.5 py-1 rounded-full text-xs font-black', getStatusClass(tripDetails.status)]">
                      {{ getStatusLabel(tripDetails.status) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Occupancy Rate and Financial Recap Card -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="bg-white dark:bg-slate-950/45 p-5 border border-slate-200 dark:border-slate-800/60 rounded-2xl">
                <h4 class="text-sm font-black text-slate-700 dark:text-slate-300 mb-3 uppercase tracking-wider">{{ $t('ticketing.trip_details.occupancy_rate') }}</h4>
                <div class="flex items-end justify-between mb-2">
                  <div class="text-2xl font-black text-slate-800 dark:text-slate-100">
                    {{ tickets.length }} / {{ tripDetails.total_seats }}
                  </div>
                  <div class="text-sm font-bold text-slate-500 dark:text-slate-400">
                    {{ Math.round((tickets.length / (tripDetails.total_seats || 1)) * 100) }}% {{ $t('ticketing.trip_details.occupied') }}
                  </div>
                </div>
                <div class="w-full bg-slate-100 dark:bg-slate-800 h-2.5 rounded-full overflow-hidden">
                  <div class="bg-emerald-500 h-full rounded-full transition-all duration-500" :style="{ width: `${(tickets.length / (tripDetails.total_seats || 1)) * 100}%` }"></div>
                </div>
                <div class="grid grid-cols-2 gap-2 mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/40 text-xs font-semibold text-slate-500 dark:text-slate-400">
                  <div>{{ $t('ticketing.trip_details.occupied_seats') }} <span class="font-bold text-slate-800 dark:text-slate-200">{{ tickets.length }}</span></div>
                  <div>{{ $t('ticketing.trip_details.available_seats') }} <span class="font-bold text-slate-800 dark:text-slate-200">{{ Math.max(0, tripDetails.total_seats - tickets.length) }}</span></div>
                </div>
              </div>

              <!-- Crew Assignments Card -->
              <div class="bg-white dark:bg-slate-950/45 p-5 border border-slate-200 dark:border-slate-800/60 rounded-2xl flex flex-col justify-between">
                <h4 class="text-sm font-black text-slate-700 dark:text-slate-300 mb-3 uppercase tracking-wider">{{ $t('ticketing.trip_details.crew_onboard') }}</h4>
                <div class="space-y-3">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-400">
                      <Account :size="18" />
                    </div>
                    <div>
                      <div class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.driver') }}</div>
                      <div class="text-xs font-bold text-slate-800 dark:text-slate-200">
                        {{ tripDetails.driver?.name || $t('ticketing.trip_details.no_driver_assigned') }}
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-400">
                      <Account :size="18" />
                    </div>
                    <div>
                      <div class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.assistant') }}</div>
                      <div class="text-xs font-bold text-slate-800 dark:text-slate-200">
                        {{ tripDetails.assistant?.name || $t('ticketing.trip_details.no_assistant_assigned') }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Route Stop Timeline -->
            <div class="bg-white dark:bg-slate-950/45 p-5 border border-slate-200 dark:border-slate-800/60 rounded-2xl">
              <h4 class="text-sm font-black text-slate-700 dark:text-slate-300 mb-6 uppercase tracking-wider">{{ $t('ticketing.trip_details.route_timeline') }}</h4>
              
              <!-- Visuelle Timeline -->
              <div class="relative flex flex-col md:flex-row items-stretch md:items-center justify-between gap-6 pl-4 md:pl-0">
                
                <!-- Line background for desktop -->
                <div class="hidden md:block absolute left-4 right-4 h-0.5 bg-slate-200 dark:bg-slate-800 z-0"></div>
                
                <!-- Line background for mobile -->
                <div class="md:hidden absolute left-2 top-2 bottom-2 w-0.5 bg-slate-200 dark:bg-slate-800 z-0"></div>

                <!-- Dynamic Stops Timeline -->
                <template v-if="timelineStops.length > 0">
                  <div 
                    v-for="(stop, idx) in timelineStops" 
                    :key="stop.id" 
                    class="relative z-10 flex md:flex-col items-center gap-3 md:gap-2 text-left md:text-center flex-1"
                  >
                    <!-- Color circle based on index -->
                    <div 
                      v-if="idx === 0" 
                      class="w-4 h-4 rounded-full bg-emerald-500 ring-4 ring-emerald-100 dark:ring-emerald-950"
                    ></div>
                    <div 
                      v-else-if="idx === timelineStops.length - 1" 
                      class="w-4 h-4 rounded-full bg-indigo-500 ring-4 ring-indigo-100 dark:ring-indigo-950"
                    ></div>
                    <div 
                      v-else 
                      class="w-3.5 h-3.5 rounded-full bg-blue-500 ring-4 ring-blue-50 dark:ring-blue-950"
                    ></div>

                    <div>
                      <div class="text-xs font-black text-slate-800 dark:text-slate-200">
                        {{ stop.station?.name || stop.name || 'Station' }}
                      </div>
                      <div class="text-[10px] font-bold text-slate-450 dark:text-slate-500 uppercase">
                        <span v-if="idx === 0">{{ $t('ticketing.trip_details.original_departure') }}</span>
                        <span v-else-if="idx === timelineStops.length - 1">{{ $t('ticketing.trip_details.terminus') }}</span>
                        <span v-else>{{ $t('ticketing.trip_details.stop_number') }}{{ idx }}</span>
                      </div>
                      <div v-if="idx < timelineStops.length - 1" class="mt-1">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-black bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900/40">
                          {{ getTicketsCountForStation(stop.station?.id || stop.id) }} {{ $t('ticketing.trip_details.tickets_sold') }}
                        </span>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Fallback if no stops configured in timeline -->
                <template v-else>
                  <!-- Origin Station -->
                  <div class="relative z-10 flex md:flex-col items-center gap-3 md:gap-2 text-left md:text-center flex-1">
                    <div class="w-4 h-4 rounded-full bg-emerald-500 ring-4 ring-emerald-100 dark:ring-emerald-950"></div>
                    <div>
                      <div class="text-xs font-black text-slate-800 dark:text-slate-200">
                        {{ tripDetails.origin_station?.name || tripDetails.route?.origin_station?.name || 'Départ' }}
                      </div>
                      <div class="text-[10px] font-bold text-slate-450 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.original_departure') }}</div>
                      <div class="mt-1">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-black bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900/40">
                          {{ getTicketsCountForStation(tripDetails.origin_station_id || tripDetails.route?.origin_station_id) }} {{ $t('ticketing.trip_details.tickets_sold') }}
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- Destination Station -->
                  <div class="relative z-10 flex md:flex-col items-center gap-3 md:gap-2 text-left md:text-center flex-1">
                    <div class="w-4 h-4 rounded-full bg-indigo-500 ring-4 ring-indigo-100 dark:ring-indigo-950"></div>
                    <div>
                      <div class="text-xs font-black text-slate-800 dark:text-slate-200">
                        {{ tripDetails.destination_station?.name || tripDetails.route?.destination_station?.name || 'Terminus' }}
                      </div>
                      <div class="text-[10px] font-bold text-slate-450 dark:text-slate-500 uppercase">{{ $t('ticketing.trip_details.terminus') }}</div>
                    </div>
                  </div>
                </template>

              </div>
            </div>

          </div>

          <!-- TAB 2: POSITION & INCIDENTS (CREW APP SYNC) -->
          <div v-if="activeTab === 'gps'" class="space-y-6 animate-fadeIn">
            
            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/30 rounded-2xl p-4 flex gap-3 text-sm text-blue-800 dark:text-blue-300">
              <Alert class="shrink-0 mt-0.5 text-blue-600 dark:text-blue-450" />
              <div>
                <strong>
                {{ $t('ticketing.trip_details.crew_sync_title') }}
                </strong> {{ $t('ticketing.trip_details.crew_sync_desc') }}
              </div>
            </div>

            <!-- Live GPS Status Card -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              
              <!-- Left: Current Live Status Widget -->
              <div class="md:col-span-1 bg-white dark:bg-slate-950/45 p-5 border border-slate-200 dark:border-slate-800/60 rounded-2xl flex flex-col justify-between">
                <div>
                  <h4 class="text-sm font-black text-slate-700 dark:text-slate-300 mb-4 uppercase tracking-wider">{{ $t('ticketing.trip_details.current_status_road') }}</h4>
                  <div v-if="latestPosition" class="text-center py-6">
                    <div class="inline-flex p-4 rounded-full mb-3" :class="[latestPosition.status === 'normal' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/45 dark:text-emerald-400' : 'bg-rose-50 text-rose-600 dark:bg-rose-950/45 dark:text-rose-400']">
                      <Bus :size="48" />
                    </div>
                    <div class="text-lg font-black text-slate-800 dark:text-slate-100 flex items-center justify-center gap-2">
                      <span class="w-3 h-3 rounded-full" :class="getReportStatusClass(latestPosition.status)"></span>
                      {{ getReportStatusLabel(latestPosition.status) }}
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                      {{ $t('ticketing.trip_details.reported_by') }} <span class="font-bold text-slate-700 dark:text-slate-300">{{ latestPosition.crew_member?.name || $t('ticketing.trip_details.crew') }}</span> {{ $t('ticketing.trip_details.at') }} {{ formatTime(latestPosition.reported_at) }}
                    </p>
                    <div v-if="latestPosition.note" class="mt-4 p-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-400 text-left rounded-xl italic">
                      " {{ latestPosition.note }} "
                    </div>
                  </div>
                  <div v-else class="text-center py-10 text-slate-400 dark:text-slate-500">
                    <MapMarker :size="48" class="mx-auto mb-3 opacity-30" />
                    <p class="text-sm font-semibold">{{ $t('ticketing.trip_details.no_gps_signal') }}</p>
                    <p class="text-xs mt-1">{{ $t('ticketing.trip_details.no_position_report') }}</p>
                  </div>
                </div>

                <div v-if="latestPosition && latestPosition.latitude" class="pt-4 border-t border-slate-100 dark:border-slate-850/60 mt-4">
                  <a 
                    :href="`https://www.google.com/maps/search/?api=1&query=${latestPosition.latitude},${latestPosition.longitude}`" 
                    target="_blank"
                    class="w-full inline-flex items-center justify-center gap-2 py-2.5 bg-slate-900 hover:bg-slate-850 dark:bg-slate-800 dark:hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition-all"
                  >
                    <MapMarker :size="16" />
                    {{ $t('ticketing.trip_details.view_on_maps') }}
                  </a>
                </div>
              </div>

              <!-- Right: Reports History List -->
              <div class="md:col-span-2 bg-white dark:bg-slate-950/45 p-5 border border-slate-200 dark:border-slate-800/60 rounded-2xl flex flex-col">
                <h4 class="text-sm font-black text-slate-700 dark:text-slate-300 mb-4 uppercase tracking-wider">{{ $t('ticketing.trip_details.reports_history') }}</h4>
                
                <div v-if="statusReports.length > 0" class="flex-1 overflow-y-auto space-y-4 pr-1 max-h-[300px] scrollbar-thin">
                  <div 
                    v-for="report in statusReports" 
                    :key="report.id" 
                    class="p-3 border-l-4 rounded-r-xl bg-slate-50/50 dark:bg-slate-900/30 flex items-start justify-between gap-3 text-xs"
                    :class="[
                      report.status === 'normal' ? 'border-emerald-500' : 
                      report.status === 'traffic_jam' ? 'border-yellow-500' : 
                      report.status === 'mechanical_trouble' ? 'border-orange-500' : 'border-rose-500'
                    ]"
                  >
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="font-black text-slate-800 dark:text-slate-200">{{ getReportStatusLabel(report.status) }}</span>
                        <span class="text-[10px] text-slate-400">{{ formatTime(report.reported_at) }} ({{ formatDate(report.reported_at) }})</span>
                      </div>
                      <p v-if="report.note" class="text-slate-600 dark:text-slate-400 mt-1 italic">" {{ report.note }} "</p>
                      <div class="text-[10px] text-slate-500 mt-1">{{ $t('ticketing.trip_details.coordinates') }} {{ report.latitude || '-' }}, {{ report.longitude || '-' }}</div>
                    </div>
                    <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 shrink-0">
                      {{ $t('ticketing.trip_details.by') }} {{ report.crew_member?.name || $t('ticketing.trip_details.crew') }}
                    </div>
                  </div>
                </div>

                <div v-else class="flex-1 flex flex-col items-center justify-center py-10 text-slate-400 dark:text-slate-500">
                  <p class="text-sm font-semibold">{{ $t('ticketing.trip_details.no_incident_reported') }}</p>
                  <p class="text-xs mt-1">{{ $t('ticketing.trip_details.all_signals_blank') }}</p>
                </div>
              </div>

            </div>

          </div>

          <!-- TAB 3: TICKETS LIST -->
          <div v-if="activeTab === 'tickets'" class="space-y-4 flex flex-col h-full min-h-0 animate-fadeIn">
            
            <!-- Search & Actions Bar -->
            <div class="flex flex-col sm:flex-row items-center gap-3">
              <div class="relative w-full sm:flex-1">
                <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none text-emerald-500 dark:text-emerald-400">
                  <Magnify :size="18" />
                </div>
                <input 
                  type="text" 
                  v-model="searchQuery" 
                  :placeholder="$t('ticketing.trip_details.search_placeholder')" 
                  class="w-full pl-10 pr-4 py-2 border border-slate-200 dark:border-slate-800 rounded-xl bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 text-xs font-semibold focus:ring-emerald-500 focus:border-emerald-500"
                />
              </div>
            </div>

            <!-- Table Card Container -->
            <div class="border border-slate-200 dark:border-slate-850 rounded-2xl overflow-hidden bg-white dark:bg-slate-950/20 flex-1 min-h-[300px] flex flex-col">
              <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr class="bg-slate-50 dark:bg-slate-950/60 border-b border-slate-200 dark:border-slate-850 font-bold text-slate-500 uppercase text-[10px]">
                      <th class="py-3 px-4 w-16">{{ $t('ticketing.trip_details.seat') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.ticket') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.passenger') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.journey') }}</th>
                      <th class="py-3 px-4 text-right">{{ $t('ticketing.trip_details.fare') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.seller') }}</th>
                      <th class="py-3 px-4 text-center">{{ $t('ticketing.trip_details.status') }}</th>
                      <th class="py-3 px-4 text-center w-24">{{ $t('common.actions') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-150 dark:divide-slate-850">
                    <tr 
                      v-for="ticket in filteredTickets" 
                      :key="ticket.id" 
                      class="hover:bg-slate-50/50 dark:hover:bg-slate-900/35 transition-colors font-medium text-slate-700 dark:text-slate-350"
                    >
                      <td class="py-3 px-4">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 font-black text-xs">
                          {{ ticket.seat_number }}
                        </span>
                      </td>
                      <td class="py-3 px-4">
                        <div class="font-bold text-slate-900 dark:text-slate-200">{{ ticket.ticket_number }}</div>
                        <div class="text-[9px] text-slate-400 mt-0.5">{{ $t('ticketing.trip_details.sold_at') }} {{ formatTime(ticket.created_at) }}</div>
                        <span
                          v-if="ticket.journey_type !== 'direct'"
                          class="mt-1 inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-black text-violet-700 dark:bg-violet-950/40 dark:text-violet-300"
                        >{{ ticket.journey_type === 'connection' ? 'Correspondance' : 'Trajet avec transit' }}</span>
                      </td>
                      <td class="py-3 px-4">
                        <div class="font-bold text-slate-800 dark:text-slate-200">{{ ticket.passenger_name || $t('ticketing.trip_details.anonymous') }}</div>
                        <div class="text-[10px] text-slate-500 mt-0.5">{{ ticket.passenger_phone || '-' }}</div>
                      </td>
                      <td class="py-3 px-4">
                        <div class="font-bold text-slate-800 dark:text-slate-300">
                          {{ ticket.from_station?.name?.split(' - ')[1] || ticket.from_station?.name }} 
                          → 
                          {{ ticket.to_station?.name?.split(' - ')[1] || ticket.to_station?.name }}
                        </div>
                        <div v-if="ticket.journey_type === 'connection_origin'" class="mt-1 text-[10px] leading-snug text-violet-700 dark:text-violet-300">
                          {{ $t('ticketing.trip_details.final_destination') }} <strong>{{ ticket.final_destination?.name || ticket.connection_destination?.name }}</strong>
                          <span class="block">{{ $t('ticketing.trip_details.transit') }} {{ ticket.transfer_station?.name }}</span>
                          <span class="block" v-if="ticket.connection_trip">{{ $t('ticketing.trip_details.next_leg') }} {{ ticket.connection_trip.code }}</span>
                          <span class="block" v-else>{{ $t('ticketing.trip_details.next_leg') }} {{ $t('ticketing.trip_details.pending_assignment') }}</span>
                        </div>
                      </td>
                      <td class="py-3 px-4 text-right font-black text-slate-900 dark:text-slate-100">
                        {{ ticket.price?.toLocaleString('fr-FR') }} F
                      </td>
                      <td class="py-3 px-4">
                        <div class="text-[11px] font-semibold text-slate-800 dark:text-slate-300">{{ ticket.seller?.name || '-' }}</div>
                      </td>
                      <td class="py-3 px-4 text-center">
                        <span 
                          :class="[
                            'px-2 py-0.5 rounded-full text-[9px] font-black',
                            ticket.connection_has_conflict
                              ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300'
                              : ticket.journey_type !== 'direct'
                                ? 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300'
                                : ticket.status === 'cancelled'
                                  ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300'
                                  : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                          ]"
                          :title="ticket.connection_conflict_reason || ''"
                        >
                          {{ getTicketStatusLabel(ticket) }}
                        </span>
                        <div v-if="ticket.connection_conflict_reason" class="mt-1 max-w-32 text-[9px] leading-tight text-rose-600 dark:text-rose-400">
                          {{ ticket.connection_conflict_reason }}
                        </div>
                      </td>
                      <td class="py-3 px-4 text-center">
                        <div class="flex items-center justify-center gap-1.5">
                          <button
                            @click="viewTicket(ticket.id)"
                            class="p-1 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:hover:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 rounded-lg transition-all active:scale-90"
                            :title="$t('ticketing.trip_details.view_ticket')"
                            :aria-label="$t('ticketing.trip_details.view_ticket')"
                          >
                            <Eye :size="16" />
                          </button>
                          <button 
                            @click="printTicket(ticket.id)" 
                            class="p-1 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg transition-all active:scale-90"
                            :title="$t('ticketing.trip_details.print_ticket')"
                            :aria-label="$t('ticketing.trip_details.print_ticket')"
                          >
                            <Printer :size="16" />
                          </button>
                          <button
                            v-if="ticket.status !== 'cancelled'"
                            @click="compensateTicket(ticket)"
                            :disabled="compensatingTicketId === ticket.id"
                            class="p-1 bg-violet-50 hover:bg-violet-100 dark:bg-violet-950/40 text-violet-600 dark:text-violet-400 rounded-lg disabled:opacity-50"
                            :title="$t('ticketing.trip_details.compensate_ticket')"
                          ><Alert :size="16" /></button>
                          <button 
                            v-if="ticket.status !== 'cancelled' && canCancel(ticket)"
                            @click="cancelTicket(ticket.id, ticket.seat_number)" 
                            :disabled="cancelingTicketId === ticket.id"
                            class="p-1 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-lg transition-all active:scale-90 disabled:opacity-50"
                            :title="$t('ticketing.trip_details.cancel_ticket')"
                          >
                            <TrashCan :size="16" />
                          </button>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="filteredTickets.length === 0">
                      <td colspan="8" class="text-center py-10 text-slate-400 dark:text-slate-500 italic">
                        {{ $t('ticketing.trip_details.no_ticket_found') }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- TAB 4: TRANSIT POOL -->
          <div v-if="activeTab === 'transit'" class="space-y-4 flex flex-col h-full min-h-0 animate-fadeIn">
            
            <div class="bg-violet-50 dark:bg-violet-950/20 border border-violet-200 dark:border-violet-900/30 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-sm text-violet-850 dark:text-violet-300">
              <div class="flex gap-3">
                <Alert class="shrink-0 mt-0.5 text-violet-600 dark:text-violet-450" />
                <div>
                  <strong>
                  {{ $t('ticketing.trip_details.transit_passengers_title') }}
                  </strong> {{ $t('ticketing.trip_details.transit_passengers_desc') }}
                </div>
              </div>
              <button
                v-if="canManageTransit && transitPool.length > 0"
                @click="autoAllocateConnections"
                :disabled="autoAllocating"
                class="shrink-0 px-4 py-2 bg-violet-600 hover:bg-violet-700 disabled:opacity-50 text-white font-bold text-xs rounded-xl flex items-center gap-1.5 transition-all shadow-sm shadow-violet-100 dark:shadow-none"
              >
                <span v-if="autoAllocating" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span>{{ $t('ticketing.trip_details.auto_allocate') }}</span>
              </button>
            </div>

            <!-- Table Card Container -->
            <div class="border border-slate-200 dark:border-slate-850 rounded-2xl overflow-hidden bg-white dark:bg-slate-950/20 flex-1 min-h-[300px] flex flex-col">
              <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse text-xs font-semibold">
                  <thead>
                    <tr class="bg-slate-50 dark:bg-slate-950/60 border-b border-slate-200 dark:border-slate-850 font-bold text-slate-500 uppercase text-[10px]">
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.ticket') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.passenger') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.origin') }}</th>
                      <th class="py-3 px-4">{{ $t('ticketing.trip_details.destination') }}</th>
                      <th class="py-3 px-4 text-center">{{ $t('ticketing.trip_details.state') }}</th>
                      <th class="py-3 px-4 text-right w-60">{{ $t('ticketing.trip_details.assignment_actions') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-150 dark:divide-slate-850">
                    <tr 
                      v-for="connection in transitPool" 
                      :key="connection.id" 
                      class="hover:bg-slate-50/50 dark:hover:bg-slate-900/35 transition-colors text-slate-700 dark:text-slate-350"
                    >
                      <td class="py-3 px-4 font-bold text-slate-900 dark:text-slate-200">
                        {{ connection.ticket_number }}
                      </td>
                      <td class="py-3 px-4">
                        <div class="font-bold text-slate-800 dark:text-slate-200">{{ connection.passenger_name || 'Passager' }}</div>
                        <div class="text-[10px] text-slate-500 mt-0.5">{{ connection.passenger_phone || '-' }}</div>
                      </td>
                      <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                        {{ connection.from_station_name }}
                      </td>
                      <td class="py-3 px-4 font-semibold text-slate-850 dark:text-slate-200">
                        {{ connection.destination_station_name }}
                      </td>
                      <td class="py-3 px-4 text-center">
                        <span 
                          :class="[
                            'px-2 py-0.5 rounded-full text-[9px] font-black',
                            connection.status === 'ready'
                              ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                              : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'
                          ]"
                        >
                          {{ connection.status === 'ready' ? 'Présent' : 'Attendu' }}
                        </span>
                      </td>
                      <td class="py-3 px-4">
                        <div v-if="canManageTransit" class="flex items-center justify-end gap-2">
                          <!-- Mark Ready Button -->
                          <button
                            v-if="connection.status === 'pending'"
                            @click="markConnectionReady(connection.id)"
                            :disabled="markingReadyConnectionId === connection.id"
                            class="px-2.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-[10px] font-bold disabled:opacity-50"
                          >
                            {{ markingReadyConnectionId === connection.id ? $t('ticketing.trip_details.waiting') : $t('ticketing.trip_details.mark_ready') }}
                          </button>
                          
                          <!-- Assign Seat Form -->
                          <div class="flex items-center gap-1">
                            <input
                              type="number"
                              v-model="connectionSeats[connection.id]"
                              min="1"
                              placeholder="Siège"
                              class="w-16 px-2 py-1 text-xs border border-slate-200 dark:border-slate-800 dark:bg-slate-900 rounded-lg text-slate-850 dark:text-slate-100"
                            />
                            <button
                              @click="assignConnectionSeat(connection.id)"
                              :disabled="assigningConnectionId === connection.id"
                              class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold disabled:opacity-50"
                            >
                              {{ $t('ticketing.trip_details.assign') }}
                            </button>
                          </div>
                        </div>
                        <div v-else class="text-right text-slate-400 text-[10px]">
                          {{ $t('ticketing.trip_details.readonly_station_required') }}
                        </div>
                      </td>
                    </tr>
                    <tr v-if="transitPool.length === 0">
                      <td colspan="6" class="text-center py-10 text-slate-400 dark:text-slate-500 italic">
                        {{ $t('ticketing.trip_details.no_transit_passengers') }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>

        </template>
      </div>

    </div>
  </div>
</template>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.animate-fadeIn {
  animation: fadeIn 0.25s ease-out forwards;
}

/* Custom scrollbar */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
.dark ::-webkit-scrollbar-thumb {
  background: #475569;
}
</style>
