<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { router, Link, useForm, usePage } from '@inertiajs/vue3'
import Bus from 'vue-material-design-icons/Bus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import MenuOpen from 'vue-material-design-icons/MenuOpen.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Modal from '@/Components/Modal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'
import { ticketingStore } from '@/Stores/ticketingStore.js'
import {
  buildTripCreationDestinationOptions,
  buildTripCreationRouteOptions,
} from '@/Support/tripCreationDestinations.js'

const props = defineProps({
    trips: Array,
    routes: Array,
    vehicles: Array,
    todaySales: Number,
    hasActiveAssignment: Boolean,
    assignedStation: String,
    canSelectTripOrigin: { type: Boolean, default: false },
    originStations: { type: Array, default: () => [] },
})

const showCreateTripModal = ref(false)
const createTripForm = useForm({
  route_id: '',
  origin_station_id: '',
  destination_station_id: '',
  vehicle_id: '',
  departure_at: '',
})

const availableRouteOptions = computed(() => buildTripCreationRouteOptions(
  props.routes,
  createTripForm.origin_station_id,
));

const availableDestinationOptions = computed(() => buildTripCreationDestinationOptions(
  props.routes,
  createTripForm.origin_station_id,
  createTripForm.route_id,
));

watch([() => createTripForm.origin_station_id, () => createTripForm.route_id], () => {
  if (createTripForm.route_id
    && !availableRouteOptions.value.some((option) => option.value === createTripForm.route_id)) {
    createTripForm.route_id = '';
  }
  if (createTripForm.destination_station_id
    && !availableDestinationOptions.value.some((option) => option.value === createTripForm.destination_station_id)) {
    createTripForm.destination_station_id = '';
  }
}, { immediate: true });


const openCreateTripModal = () => {
  const stationAssignments = page.props.auth.user?.station_assignments || []
  const stationIds = [...new Set(stationAssignments.map(assignment => String(assignment.station_id)).filter(Boolean))]
  createTripForm.origin_station_id = stationIds[0] || ''
  createTripForm.destination_station_id = ''
  createTripForm.route_id = ''
  createTripForm.vehicle_id = ''
  createTripForm.departure_at = ''
  showCreateTripModal.value = true
}

const currentTime = ref('')
const currentDate = ref('')
let clockInterval = null
let subscribedChannels = []
const page = usePage()

const updateClock = () => {
  const now = new Date()
  currentTime.value = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  currentDate.value = now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).toUpperCase()
}
updateClock()

onMounted(() => {
  clockInterval = setInterval(updateClock, 1000)

  const echo = window.Echo
  const stationAssignments = page.props.auth.user?.station_assignments || []
  const stationIds = [...new Set(stationAssignments.map(assignment => String(assignment.station_id)).filter(Boolean))]

  if (echo) {
    stationIds.forEach((stationId) => {
      echo.private(`station.${stationId}`).listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push(`station.${stationId}`)
    })

    if (['admin', 'executive'].includes(page.props.auth.user?.role)) {
      echo.private('trips.global').listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push('trips.global')
    }

    if (['admin', 'executive', 'supervisor', 'seller'].includes(page.props.auth.user?.role)) {
      echo.private('network.global').listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push('network.global')
    }
  }
})

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval)
  const echo = window.Echo
  if (echo) {
    subscribedChannels.forEach((channelName) => {
      echo.leave(channelName)
    })
  }
  subscribedChannels = []
})

const isTripHighlighted = (tripId) => !!ticketingStore.tripHighlights?.[String(tripId)]

const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    })
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit'
    })
}

const getCleanDestination = (trip) => {
  const name = trip.display_name || trip.route?.name || '';
  return name.replace('->', '➔').replace('->', '➔');
}

const parseRouteName = (trip) => {
  const name = trip.display_name || trip.route?.name || '';
  const separator = name.includes('➔') ? '➔' : (name.includes('->') ? '->' : '->');
  const parts = name.split(separator);
  if (parts.length === 2) {
    return {
      origin: parts[0].trim(),
      destination: parts[1].trim()
    };
  }
  const originName = trip.origin_station?.name || trip.route?.origin_station?.name || '';
  const destName = trip.destination_station?.name || trip.route?.destination_station?.name || name;
  return {
    origin: originName || 'Départ',
    destination: destName
  };
}

const getAirportStatus = (trip) => {
  if (trip.status === 'cancelled') {
    return { 
      label: 'ANNULÉ', 
      color: 'text-rose-600 bg-rose-50 border border-rose-200 dark:text-rose-450 dark:bg-rose-950/30 dark:border-rose-900/50' 
    };
  }
  if (trip.status === 'delayed') {
    return { 
      label: 'RETARDÉ', 
      color: 'text-amber-605 bg-amber-50 border border-amber-200 dark:text-amber-400 dark:bg-amber-950/30 dark:border-amber-800/50 animate-pulse' 
    };
  }
  if (trip.status === 'boarding') {
    return { 
      label: 'EMBARQUEMENT', 
      color: 'text-orange-600 bg-orange-50 border border-orange-200 dark:text-orange-405 dark:bg-orange-950/30 dark:border-orange-850/50 font-black animate-pulse' 
    };
  }
  if (trip.status === 'departed' || trip.status === 'arrived') {
    return { 
      label: 'PARTI', 
      color: 'text-slate-600 bg-slate-50 border border-slate-200 dark:text-slate-500 dark:bg-slate-900/40 dark:border-slate-800/50' 
    };
  }
  if (trip.available_seats <= 0) {
    return { 
      label: 'COMPLET', 
      color: 'text-red-600 bg-red-50 border border-red-200 dark:text-red-400 dark:bg-red-950/30 dark:border-red-900/50 font-bold' 
    };
  }
  return { 
    label: 'À L\'HEURE', 
    color: 'text-emerald-600 bg-emerald-50 border border-emerald-250 dark:text-emerald-400 dark:bg-emerald-950/30 dark:border-emerald-800/50' 
  };
}

const sortedTrips = computed(() => {
  if (!props.trips) return [];
  return [...props.trips].sort((a, b) => {
    const getOrderValue = (trip) => {
      if (trip.status === 'boarding') return 0;
      if (trip.status === 'delayed') return 1;
      if (trip.status === 'cancelled') return 4;
      if (trip.status === 'departed' || trip.status === 'arrived') return 3;
      return 2; // scheduled
    };
    
    const orderA = getOrderValue(a);
    const orderB = getOrderValue(b);
    
    if (orderA !== orderB) {
      return orderA - orderB;
    }
    
    return new Date(a.departure_at) - new Date(b.departure_at);
  });
});

const createTrip = () => {
  createTripForm.post(route('seller.trips.store'), {
    preserveState: true,
    onSuccess: () => {
      showCreateTripModal.value = false;
      createTripForm.reset();
    }
  });
};
</script>

<template>
  <MainNavLayout>
    <div class="max-w-6xl mx-auto space-y-6">
      
      <!-- Full-page blocking message if no station assigned (for sellers only) -->
      <div v-if="$page.props.auth.user.role === 'seller' && !hasActiveAssignment" 
           class="min-h-[70vh] flex items-center justify-center">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-12 rounded-3xl flex flex-col items-center text-center shadow-lg max-w-lg">
          <div class="p-5 bg-emerald-50 dark:bg-emerald-950/40 rounded-full shadow-sm mb-6">
            <OfficeBuilding class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
          </div>
          <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 mb-3">Aucune station assignée</h2>
          <p class="text-slate-600 dark:text-slate-400 mb-6 leading-relaxed">
            Vous n'avez pas encore de station assignée. Vous ne pouvez pas vendre de billets tant qu'un superviseur ne vous a pas assigné à une station.
          </p>
          <div class="space-y-3 w-full">
            <p class="text-sm text-slate-500 dark:text-slate-500">
              Contactez votre superviseur pour être assigné à une station.
            </p>
            <Link 
              :href="route('profile.edit')" 
              class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl font-bold transition-colors"
            >
              Voir mon profil
            </Link>
          </div>
        </div>
      </div>

      <!-- Main content (only shown if seller has assigned station or user is admin/supervisor) -->
      <template v-else>
        <!-- Workplace Header (Synced with Ticketing) -->
        <div class="bg-white p-4 md:p-6 rounded-3xl shadow-sm border border-slate-200 shrink-0 relative dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="z-10">
              <div class="flex items-center gap-3">
                <h1 class="text-3xl font-black text-slate-900 dark:text-slate-100 tracking-tight">Tableau de Bord</h1>
                <div v-if="assignedStation" class="px-3 py-1 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-xs font-black rounded-full border border-emerald-100 dark:border-emerald-800 flex items-center gap-1.5 shadow-sm">
                    <OfficeBuilding :size="14" />
                    {{ assignedStation }}
                </div>
              </div>
              <p class="text-slate-500 dark:text-slate-450 font-medium">Gestion quotidienne de la billetterie et des départs</p>
            </div>

            <!-- Absolute Centered Clock on Desktop -->
            <div class="hidden md:block absolute left-1/2 -translate-x-1/2 text-center z-0">
              <div class="text-4xl font-black text-slate-900 dark:text-slate-100 tracking-tight leading-none">{{ currentTime }}</div>
              <div class="text-[10px] font-bold text-slate-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
            </div>

            <!-- Clock and Button aligned on mobile / Button on right on Desktop -->
            <div class="flex items-center justify-between md:justify-end gap-4 md:gap-6 mt-2 md:mt-0 w-full md:w-auto z-10 shrink-0">
              <!-- Mobile Clock -->
              <div class="text-left md:hidden">
                <div class="text-2xl font-black text-slate-900 dark:text-slate-100 tracking-tight leading-none">{{ currentTime }}</div>
                <div class="text-[10px] font-bold text-slate-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
              </div>
              <button 
                 @click="openCreateTripModal()"
                 class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-xl font-bold shadow-lg shadow-emerald-600/20 transition-all active:scale-95 flex-shrink-0"
              >
                <Plus :size="20" />
                <span>Nouveau Voyage</span>
              </button>
            </div>
          </div>
        </div>

      <!-- Main Section: Voyages Disponibles (FIDS Airport Board) -->
      <section class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-900 rounded-3xl shadow-sm dark:shadow-2xl dark:shadow-black/40 overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-900 bg-slate-50/50 dark:bg-slate-950 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-amber-550/10 dark:bg-amber-500/10 border border-amber-550/20 dark:border-amber-500/25 text-amber-600 dark:text-amber-500 rounded-2xl shadow-inner animate-pulse-slow">
                    <Bus :size="24" />
                </div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 dark:text-slate-100 uppercase tracking-widest flex items-center gap-2">
                        Panneau d'affichage
                        <span class="text-[9px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-900/60 px-2 py-0.5 rounded tracking-normal">DEPARTS LIVE</span>
                    </h2>
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-mono uppercase tracking-wider mt-0.5">FLIGHT INFORMATION DISPLAY SYSTEM (FIDS)</p>
                </div>
            </div>
            <Link :href="route('seller.ticketing')" class="w-full sm:w-auto text-center px-4 py-2 bg-slate-105 hover:bg-slate-200 dark:bg-slate-900 dark:hover:bg-slate-850 border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-750 text-xs font-mono text-slate-700 dark:text-amber-400 font-bold rounded-xl uppercase tracking-wider transition-colors">
                Voir tout
            </Link>
        </div>
        
        <div>
          <div class="hidden md:grid grid-cols-12 gap-4 px-6 py-3 bg-slate-50 dark:bg-slate-950/80 border-b border-slate-100 dark:border-slate-900 text-[10px] font-mono text-slate-400 dark:text-slate-500 uppercase tracking-wider">
             <div class="col-span-1">Heure</div>
             <div class="col-span-2">Code Voyage</div>
             <div class="col-span-4">Destination</div>
             <div class="col-span-2">Véhicule</div>
             <div class="col-span-1 text-center">Places</div>
             <div class="col-span-2">Statut</div>
          </div>

          <!-- Trips List -->
          <div v-if="sortedTrips && sortedTrips.length > 0" class="divide-y divide-slate-100 dark:divide-slate-900 bg-white dark:bg-slate-950">
            <div v-for="trip in sortedTrips" :key="trip.id" 
                class="group transition-all duration-200 cursor-pointer border-l-4 border-l-transparent"
                :class="isTripHighlighted(trip.id) ? 'bg-amber-500/5 dark:bg-amber-950/20 border-l-amber-500 shadow-inner' : 'hover:bg-slate-50/60 dark:hover:bg-slate-900/40'"
                @click="router.visit(route('seller.ticketing', { trip_id: trip.id }))"
            >
              <!-- Desktop Row layout -->
              <div class="hidden md:grid grid-cols-12 gap-4 items-center px-6 py-4">
                 <!-- HEURE & DATE -->
                 <div class="col-span-1 flex flex-col">
                   <span class="font-mono text-base font-bold text-slate-900 dark:text-slate-100 tracking-wider">{{ formatTime(trip.departure_at) }}</span>
                   <span class="text-[10px] font-mono text-slate-400 dark:text-slate-500">{{ formatDate(trip.departure_at) }}</span>
                 </div>
                 <!-- CODE VOYAGE -->
                 <div class="col-span-2">
                   <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-305 text-[10px] font-black tracking-wider uppercase border border-emerald-100 dark:border-emerald-900/30">
                     {{ trip.code || 'Code en attente' }}
                   </span>
                 </div>
                 <!-- DESTINATION -->
                 <div class="col-span-4 flex flex-col justify-center min-w-0 py-1">
                    <span class="text-sm font-black text-slate-800 dark:text-slate-200 tracking-wide uppercase leading-tight">
                       {{ parseRouteName(trip).destination }}
                    </span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-semibold uppercase mt-0.5 flex flex-wrap items-center gap-1">
                       <span class="text-slate-400 font-normal">depuis</span>
                       <span class="text-slate-600 dark:text-slate-300">{{ parseRouteName(trip).origin }}</span>
                    </span>
                 </div>
                 <!-- VEHICULE -->
                 <div class="col-span-2 font-mono text-xs font-bold text-slate-700 dark:text-slate-300 uppercase truncate">
                   {{ trip.vehicle?.identifier || 'N/A' }}
                   <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-sans normal-case truncate mt-0.5">{{ trip.vehicle?.vehicle_type?.name }}</span>
                 </div>
                 <!-- PLACES -->
                 <div class="col-span-1 flex items-center justify-center gap-0.5 font-mono">
                    <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ trip.available_seats }}</span>
                    <span class="text-xs text-slate-400 dark:text-slate-600">/</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ trip.total_seats }}</span>
                 </div>
                 <!-- STATUT -->
                 <div class="col-span-2">
                   <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider shadow-inner transition-all duration-300"
                         :class="getAirportStatus(trip).color">
                     <span v-if="['boarding', 'delayed'].includes(trip.status)" class="w-1.5 h-1.5 rounded-full mr-1.5 animate-ping bg-current"></span>
                     {{ getAirportStatus(trip).label }}
                   </span>
                 </div>
              </div>

              <!-- Mobile Row layout -->
              <div class="md:hidden flex items-center justify-between p-4 hover:bg-slate-55 dark:hover:bg-slate-900/40 transition-colors">
                 <div class="flex items-center gap-3 min-w-0">
                    <!-- HEURE -->
                    <div class="flex flex-col items-center justify-center min-w-[54px] bg-slate-50 dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                       <span class="font-mono text-sm font-bold text-slate-900 dark:text-slate-100">{{ formatTime(trip.departure_at) }}</span>
                       <span class="text-[9px] font-mono text-slate-400 dark:text-slate-500">{{ formatDate(trip.departure_at) }}</span>
                    </div>
                    <!-- DESTINATION & VEHICLE -->
                    <div class="flex flex-col min-w-0">
                       <span class="text-sm font-black text-slate-800 dark:text-slate-200 uppercase leading-tight">
                         {{ parseRouteName(trip).destination }}
                       </span>
                       <span class="text-[9px] font-semibold text-slate-500 dark:text-slate-400 uppercase mt-0.5 flex flex-wrap items-center gap-1 leading-none">
                         <span class="text-slate-400 font-normal">depuis</span>
                         <span class="text-slate-600 dark:text-slate-350">{{ parseRouteName(trip).origin }}</span>
                       </span>
                       <span class="text-[9px] font-mono text-amber-600 dark:text-amber-500/80 uppercase mt-1 leading-none">
                         {{ trip.code || 'Code en attente' }} • {{ trip.vehicle?.identifier || 'N/A' }} <span class="text-slate-455 dark:text-slate-605 font-sans lowercase">({{ trip.vehicle?.vehicle_type?.name }})</span>
                       </span>
                    </div>
                 </div>
                 <!-- STATUT & PLACES -->
                 <div class="flex items-center gap-3 shrink-0 ml-2">
                    <div class="flex flex-col items-end">
                       <span class="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-black tracking-wider shadow-inner mb-1"
                             :class="getAirportStatus(trip).color">
                         {{ getAirportStatus(trip).label }}
                       </span>
                       <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                         <span class="font-bold text-slate-700 dark:text-slate-205">{{ trip.available_seats }}</span>/{{ trip.total_seats }} <span class="text-[9px] text-slate-455 dark:text-slate-605 font-sans">LIB</span>
                       </span>
                    </div>
                    <ChevronRight :size="18" class="text-slate-400 dark:text-slate-500" />
                 </div>
              </div>
            </div>
          </div>
          
          <div v-else class="text-center py-16 bg-white dark:bg-slate-950 rounded-b-3xl border-t border-slate-150 dark:border-slate-900">
            <Bus :size="48" class="text-slate-300 dark:text-slate-850 mx-auto mb-4" />
            <h3 class="text-base font-bold text-slate-400 dark:text-slate-550 uppercase tracking-widest">Aucun voyage actif</h3>
            <p class="text-slate-500 dark:text-slate-600 text-xs max-w-xs mx-auto mt-1">Commencez par créer un nouveau voyage pour aujourd'hui.</p>
          </div>
        </div>
      </section>

      <!-- Bottom Grid: Ventes & Autre Mem -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Ventes Section -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 bg-emerald-50/50 dark:bg-slate-800/50 flex items-center gap-3">
                <Cash :size="24" class="text-emerald-600" />
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200">Mes Ventes</h2>
            </div>
            <div class="p-6 flex-1 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/30 rounded-2xl flex items-center justify-center text-emerald-600 mb-2">
                    <Cash :size="32" />
                </div>
                <div>
                    <div class="text-3xl font-black text-slate-900 dark:text-slate-100">
                        {{ (todaySales || 0).toLocaleString('fr-FR') }} 
                        <span class="text-lg font-bold text-slate-400 dark:text-slate-500 uppercase">FCFA</span>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total cumulé aujourd'hui</p>
                </div>
                <Link :href="route('seller.tickets.index')" class="w-full py-3 bg-slate-50 dark:bg-slate-950 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 text-center font-bold rounded-xl border border-slate-200 dark:border-slate-800 transition-colors">
                    Détails des transactions
                </Link>
            </div>
        </section>

        <!-- Autre Menu Section -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex items-center gap-3">
                <MenuOpen :size="24" class="text-slate-600" />
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200">Autres Menus</h2>
            </div>
            <div class="p-6 flex-1 grid grid-cols-2 gap-4">
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <AccountGroup :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Passagers</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Liste & manifeste</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <OfficeBuilding :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Arrêts</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Gérer les stations</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <Clock :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Horaires</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Plannings fixes</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <Bus :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Flotte</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">État des véhicules</span>
                </button>
            </div>
        </section>
      </div>
      </template>

    </div>

    <!-- Create Trip Modal -->
    <Modal :show="showCreateTripModal" @close="showCreateTripModal = false" max-width="md">
      <div class="p-6 bg-white dark:bg-slate-900 rounded-2xl">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-2">
            <Plus :size="24" class="text-emerald-600 dark:text-emerald-450" />
            Nouveau Voyage
          </h2>
          <button @click="showCreateTripModal = false" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">
            <Close :size="24" class="text-slate-400 dark:text-slate-500" />
          </button>
        </div>

        <form @submit.prevent="createTrip" class="space-y-4">
          <div>
            <InputLabel value="Gare d'origine" />
            <select
              v-if="canSelectTripOrigin"
              v-model="createTripForm.origin_station_id"
              class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
              required
            >
              <option value="">Sélectionnez une gare d'origine</option>
              <option v-for="station in originStations" :key="station.id" :value="station.id">
                {{ station.name }}
              </option>
            </select>
            <div v-else class="mt-1 block w-full px-4 py-2 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 dark:text-slate-100 rounded-xl text-sm font-bold">
              {{ assignedStation }}
            </div>
            <InputError :message="createTripForm.errors.origin_station_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="route" value="Ligne" />
            <select
                id="route"
                v-model="createTripForm.route_id"
                class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
                required
            >
                <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Sélectionnez une ligne</option>
                <option v-for="opt in availableRouteOptions" :key="opt.value" :value="opt.value" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ opt.label }}
                </option>
            </select>
            <InputError :message="createTripForm.errors.route_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="destination" value="Gare de destination" />
            <select
                id="destination"
                v-model="createTripForm.destination_station_id"
                class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
                required
                :disabled="!createTripForm.route_id"
            >
                <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Sélectionnez une destination</option>
                <option v-for="opt in availableDestinationOptions" :key="opt.value" :value="opt.value" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ opt.label }}
                </option>
            </select>
            <InputError :message="createTripForm.errors.destination_station_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="vehicle" value="Véhicule" />
            <select
                id="vehicle"
                v-model="createTripForm.vehicle_id"
                class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
                required
            >
                <option value="" disabled class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Sélectionnez un véhicule</option>
                <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ vehicle.identifier }} ({{ vehicle.vehicle_type?.name }} - {{ vehicle.vehicle_type?.seat_count }} places)
                </option>
            </select>
            <InputError :message="createTripForm.errors.vehicle_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="departure_at" value="Date et Heure de Départ" />
            <TextInput
                id="departure_at"
                type="datetime-local"
                class="mt-1 block w-full"
                v-model="createTripForm.departure_at"
                required
            />
            <InputError :message="createTripForm.errors.departure_at" class="mt-2" />
          </div>

          <div class="pt-4 flex items-center justify-end gap-3">
            <button
                type="button"
                @click="showCreateTripModal = false"
                class="px-4 py-2 text-sm font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
            >
              Annuler
            </button>
            <button
                type="submit"
                :disabled="createTripForm.processing"
                class="px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 transition-all disabled:opacity-50"
            >
              Créer le Voyage
            </button>
          </div>
        </form>
      </div>
    </Modal>
  </MainNavLayout>
</template>
