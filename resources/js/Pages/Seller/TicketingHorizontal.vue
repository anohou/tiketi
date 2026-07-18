<script setup>
import { ref, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SkeletonLoader from '@/Components/SkeletonLoader.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';
import BookingModal from '@/Components/Seller/BookingModal.vue';
import TicketInspectionModal from '@/Components/Supervisor/TicketInspectionModal.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Bluetooth from 'vue-material-design-icons/Bluetooth.vue';
import Eye from 'vue-material-design-icons/Eye.vue';
import TripDetailsModal from '@/Components/Seller/TripDetailsModal.vue';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import { useTicketing } from '@/Composables/useTicketing.js';

const props = defineProps({
  trips: Array,
  routeFares: Array,
  connectionFares: { type: Array, default: () => [] },
  connectionRoutes: { type: Array, default: () => [] },
  routes: Array,
  vehicles: Array,
  hasActiveAssignment: Boolean,
  assignedStationId: String,
  assignedStation: String,
  assignedStationColor: {
    type: Object,
    default: () => ({})
  },
  destinations: { type: Array, default: () => [] },
  replicableTrips: { type: Array, default: () => [] },
});

// Use the shared composable (horizontal layout does NOT send segment params)
const ticketing = useTicketing(props, { supportsPagination: false, sendsSegmentParams: false });

// Destructure everything the template needs
const {
  trips: tripsRef,
  selectedTripId,
  selectedFare,
  ticketQuantity,
  seatMap,
  seatMapLoading,
  suggestedSeats,
  bookingType,
  occupancyStats,
  processing,
  errors,
  autoSelectOptimal,
  showPassengerFields,
  showCreateTripModal,
  createTripForm,
  createTripErrors,
  createTripProcessing,
  showZoomModal,
  showTripDetailsModal,
  selectedDetailsTripId,
  openTripDetails,
  showPassengerModal,
  showDestinationModal,
  selectedSeatNumber,
  seatSelectionMode,
  seatFirstFlow,
  selectedSeatColor,
  passengerForm,
  passengerFormErrors,
  finalDestinationStationId,
  connectionRouteId,
  showInspectionModal,
  selectedTicketForInspection,
  currentTime,
  currentDate,
  useBluetoothPrinter,
  bluetoothPrinterConnected,
  bluetoothPrinterName,
  toggleBluetoothPrinter,
  zoomLevel,
  panX,
  panY,
  isDragging,
  handleWheel,
  zoomIn,
  zoomOut,
  handleMouseDown,
  handleMouseMove,
  handleMouseUp,
  resetZoom,
  bookingSidePanelOpen,
  currentTrip,
  seatsToBook,
  totalAmount,
  canBookTickets,
  filteredTrips,
  availableFares,
  buildTripStationIndices,
  getStationColor,
  currentStationSellableSeatNumbers,
  currentStationSellableSeatBorderColor,
  selectTrip,
  fetchSeatMap,
  fetchSeatSuggestions,
  handleSeatClick,
  openPassengerModal,
  openDestinationModalForSeat,
  selectFareForSeat,
  autoSelectOptimalSeat,
  confirmBooking,
  cancelBooking,
  createTrip,
  applyReplicableTemplate,
  fallbackToBrowserPrint,
  page,
} = ticketing;

// Horizontal-specific: destination filter is local (not shared via store like Ticketing.vue)
const selectedDestinationId = ref('');

const selectedTemplate = ref(null);
watch(selectedTemplate, (newTemplate) => {
  if (newTemplate) {
    applyReplicableTemplate(newTemplate);
  }
});
watch(showCreateTripModal, (isOpen) => {
  if (!isOpen) {
    selectedTemplate.value = null;
  }
});
const getRouteName = (routeId) => {
  const r = props.routes?.find(route => route.id === routeId);
  return r ? (r.display_name || r.name) : 'Route inconnue';
};

// Override filteredTrips to use the local destination filter
const filteredTripsLocal = computed(() => {
  let filtered = tripsRef.value;
  if (selectedDestinationId.value) {
    filtered = filtered.filter(trip => {
      if (trip.route?.target_destination_id === selectedDestinationId.value) return true;
      if (trip.route?.destination_station?.destination_id === selectedDestinationId.value) return true;
      const stops = trip.route?.routeStopOrders || trip.route?.route_stop_orders || [];
      return stops.some(stop => stop.station?.destination_id === selectedDestinationId.value);
    });
  }
  return filtered;
});

// Alias for template compatibility
const trips = tripsRef;
</script>

<template>
  <MainNavLayout :fullHeight="true" :hideTripSidebar="true">
    <div class="w-full h-full min-h-0 flex flex-col overflow-hidden bg-gray-50">
      <!-- Top Header: Title, Trip Select, Actions -->
      <div class="shrink-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between shadow-sm z-20">
        <div class="flex items-center gap-6 flex-1">
          <div>
            <h1 class="text-xl font-bold text-gray-900">Billetterie</h1>
            <p class="text-sm text-gray-500">Vente de tickets</p>
          </div>

          <!-- Live Clock -->
          <div class="text-center">
            <div class="text-3xl font-black text-gray-900 tracking-tight leading-none">{{ currentTime }}</div>
            <div class="text-[9px] font-bold text-gray-400 tracking-widest mt-0.5">{{ currentDate }}</div>
          </div>

          <!-- Trip Selection Dropdown -->
          <div class="flex-1 max-w-2xl">
            <div class="relative flex items-center">
              <div class="absolute left-3 flex items-center pointer-events-none text-gray-500">
                <Bus class="w-5 h-5 mr-2" />
                <span class="text-sm font-medium mr-2">Voyage:</span>
              </div>
              <select 
                v-model="selectedTripId"
                class="w-full pl-28 pr-10 py-2.5 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-emerald-500 focus:border-emerald-500 block p-2.5 appearance-none"
              >
                <option :value="null">Sélectionner un voyage...</option>
                <option v-for="trip in trips" :key="trip.id" :value="trip.id">
                  {{ trip.code || 'Code en attente' }} - {{ trip.display_name }} - {{ new Date(trip.departure_at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) }} - {{ trip.vehicle?.identifier }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <Link :href="route('seller.ticketing')" class="w-10 h-10 border border-slate-200 text-slate-700 rounded-xl bg-slate-50 hover:bg-slate-100 text-sm font-medium flex items-center justify-center shadow-sm transition-colors" title="Vue verticale">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="w-[18px] h-[18px]">
              <path
                fill="currentColor"
                d="M6 6h5v2H8.41l3.31 3.31-1.42 1.41L7 9.41V11H5V6h1Zm12 0v5h-2V9.41l-3.29 3.29-1.42-1.41L15.59 8H14V6h4Zm0 12h-4v-2h1.59l-3.31-3.31 1.42-1.41L17 14.59V13h1v5Zm-12 0v-5h2v1.59l3.29-3.29 1.42 1.41L8.41 16H10v2H6Z"
              />
            </svg>
          </Link>
          <button 
            @click="openTripDetails(selectedTripId)"
            :disabled="!selectedTripId"
            class="px-4 py-2 border border-slate-200 text-slate-700 bg-slate-50 hover:bg-slate-100 dark:bg-slate-800 dark:border-slate-750 dark:text-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg flex items-center shadow-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            title="Détails & Tickets du voyage"
          >
            <Eye class="w-4 h-4 mr-2" />
            Détails
          </button>
          <button @click="showCreateTripModal = true" class="px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 flex items-center shadow-sm transition-colors">
            <Calendar class="w-4 h-4 mr-2" />
            Nouveau Voyage
          </button>
          <button 
            @click="toggleBluetoothPrinter" 
            :class="[
              'w-10 h-10 border rounded-full text-sm font-medium flex items-center justify-center transition-all shadow-sm shrink-0',
              useBluetoothPrinter && bluetoothPrinterConnected 
                ? 'border-emerald-500 bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' 
                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
            ]"
            :title="bluetoothPrinterConnected ? `Connecté: ${bluetoothPrinterName}` : 'Connecter imprimante Bluetooth'"
          >
            <Bluetooth :class="bluetoothPrinterConnected ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400'" :size="20" />
          </button>
        </div>
      </div>

      <!-- Main Content Area -->
      <div class="flex-1 min-h-0 flex flex-col overflow-hidden">
        
        <!-- Tronçons (Fares) - Horizontal Scroll -->
        <div class="order-1 shrink-0 bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-10 relative">
          <div class="flex items-center justify-between mb-3">

            <h2 class="text-sm font-semibold text-gray-700 flex items-center">
              <Routes class="mr-2 w-4 h-4 text-gray-500" />
              Tronçons disponibles <span v-if="currentTrip" class="ml-2 text-2xl font-bold text-gray-700">Départ : <span class="text-emerald-700">{{ currentTrip.route?.origin_station?.name }}</span></span>
            </h2>
            
            <div class="flex items-center gap-4">


               <!-- Auto Select Toggle -->
               <label class="flex items-center gap-2 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 shadow-sm hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                  <input 
                    type="checkbox" 
                    v-model="autoSelectOptimal"
                    class="h-5 w-5 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500 accent-emerald-600"
                  />
                  <span class="text-sm font-semibold text-emerald-800">Placement auto</span>
               </label>
            </div>
          </div>

          <!-- Horizontal List -->
          <div v-if="selectedTripId" class="flex gap-3 overflow-x-auto pb-2 pl-2 pt-2 scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent">
             <div v-for="fare in availableFares" :key="fare.id"
                  @click="selectedFare = fare"
                  :class="[
                    'flex-shrink-0 w-48 p-2.5 rounded-xl cursor-pointer transition-all duration-200 group relative overflow-hidden shadow-sm border-2',
                    selectedFare?.id === fare.id 
                      ? 'scale-105 shadow-lg border-emerald-500 bg-emerald-50' 
                      : 'hover:shadow-md hover:scale-102 border-transparent'
                  ]"
                  :style="{
                    backgroundColor: fare.color
                  }"
             >
                <div class="flex flex-col items-center justify-center h-full" :style="{ color: fare.textColor || '#FFFFFF' }">
                  <div class="font-bold text-lg mb-1 transition-colors">
                    {{ fare.to_station?.name }}
                  </div>
                  <div class="text-xl font-extrabold" :style="{ color: fare.textColor || '#FFFFFF' }">
                    {{ fare.amount.toLocaleString('fr-FR') }} F
                  </div>
                </div>
                <div v-if="selectedFare?.id === fare.id" class="absolute inset-0 rounded-xl border-2 border-emerald-500 pointer-events-none"></div>
             </div>
             
             <div v-if="availableFares.length === 0" class="w-full text-center py-4 text-gray-500 text-sm italic">
                Aucun tronçon disponible pour ce voyage.
             </div>
          </div>
          <div v-else class="text-center py-6 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-300">
             <p class="text-sm">Veuillez sélectionner un voyage ci-dessus pour voir les tarifs.</p>
          </div>
        </div>

        <!-- Bus Seat Map - Wide & Centered -->
        <div class="order-2 flex-1 min-h-0 relative bg-gray-100 overflow-hidden flex flex-col lg:flex-row">
           <!-- The Map -->
           <div
              :class="[
                'relative w-full h-full flex flex-1 items-center justify-center overflow-hidden cursor-grab active:cursor-grabbing pb-16',
                bookingSidePanelOpen ? 'lg:pr-[28rem]' : 'lg:pr-0'
              ]"
              @wheel="handleWheel"
              @mousedown="handleMouseDown"
              @mousemove="handleMouseMove"
              @mouseup="handleMouseUp"
              @mouseleave="handleMouseUp"
           >
              <!-- Map Controls / Legend Bar -->
              <div class="absolute top-4 left-4 z-10 pointer-events-none">
                <div class="bg-white/90 backdrop-blur-sm p-1.5 rounded-lg shadow-sm border border-gray-200 pointer-events-auto flex flex-col gap-1">
                  <button @click="zoomIn" class="p-1.5 hover:bg-gray-100 rounded text-gray-600" title="Zoom +"><span class="text-lg leading-none">+</span></button>
                  <button @click="zoomOut" class="p-1.5 hover:bg-gray-100 rounded text-gray-600" title="Zoom -"><span class="text-lg leading-none">−</span></button>
                  <button @click="resetZoom" class="p-1.5 hover:bg-gray-100 rounded text-gray-600 text-xs font-medium" title="Reset">100%</button>
                  <button
                    @click="showZoomModal = true"
                    :disabled="!currentTrip || !seatMap"
                    class="p-1.5 hover:bg-gray-100 rounded text-emerald-700 text-xs font-medium disabled:opacity-40 disabled:cursor-not-allowed"
                    title="Agrandir le plan"
                  >
                    <Magnify class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <div v-if="seatMapLoading" class="w-full max-w-xl p-6 bg-white rounded-2xl shadow-sm">
                 <SkeletonLoader type="card" :count="1" />
              </div>
              <div
                 v-else-if="currentTrip && seatMap"
                 :style="{
                   transform: `translate(${panX}px, ${panY}px) scale(${zoomLevel}) rotate(90deg)`,
                   transition: isDragging ? 'none' : 'transform 0.1s ease-out'
                 }"
                 class="origin-center"
              >
                 <VehicleSeatMapSVG
                   v-if="currentTrip.vehicle?.vehicle_type"
                   :vehicle-type="currentTrip.vehicle.vehicle_type"
                   :seat-map="seatMap"
                 :suggested-seats="suggestedSeats"
                 :selected-seat="selectedSeatNumber"
                 :selected-color="selectedSeatColor"
                 :sellable-seat-numbers="currentStationSellableSeatNumbers"
                 :sellable-seat-border-color="currentStationSellableSeatBorderColor"
                 :show-suggestions="ticketingStore.showSuggestions && !!selectedFare && suggestedSeats.length > 0"
                 :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role) || isSalesClosedForSeller"
                 @seat-click="handleSeatClick"
                 />
              </div>
              <div v-else class="text-center text-gray-400">
                 <Bus class="w-16 h-16 mx-auto mb-3 opacity-20" />
                 <p class="text-lg font-medium opacity-50">Sélectionnez un voyage pour voir le plan</p>
              </div>
           </div>
         </div>

         <BookingModal
           :visible="showPassengerModal || showDestinationModal"
           :mode="showDestinationModal ? 'destination' : 'passenger'"
           :current-trip="currentTrip"
           :selected-seat-number="selectedSeatNumber"
           :selected-fare="selectedFare"
           :available-fares="availableFares"
           :connection-fares="connectionFares"
           :connection-routes="connectionRoutes"
           :seats-to-book="seatsToBook"
           :passenger-form="passengerForm"
           :passenger-form-errors="passengerFormErrors"
           :processing="processing"
           v-model:ticketQuantity="ticketQuantity"
           v-model:showPassengerFields="showPassengerFields"
           v-model:finalDestinationStationId="finalDestinationStationId"
           v-model:connectionRouteId="connectionRouteId"
           @close="cancelBooking"
           @select-fare="selectFareForSeat"
           @confirm="confirmBooking"
         />

    <!-- Modal de création de voyage -->
    <div v-if="showCreateTripModal" class="fixed inset-0 z-[1000] flex h-full w-full items-center justify-center overflow-y-auto bg-slate-900/35 p-4 backdrop-blur-sm">
      <div class="relative w-full max-w-md overflow-hidden rounded-3xl border border-white/70 bg-white/95 dark:bg-slate-900 dark:border-slate-800 p-5 shadow-[0_24px_70px_rgba(15,23,42,0.16)] dark:shadow-black/40">
        <div class="mt-3">
          <h3 class="text-lg leading-6 font-semibold text-slate-900 dark:text-slate-100">Créer un nouveau voyage</h3>
          <form @submit.prevent="createTrip" class="mt-2 space-y-4">
            <div v-if="props.replicableTrips && props.replicableTrips.length > 0">
              <InputLabel for="template_select_horizontal" value="Sélectionner un modèle de voyage récurrent" />
              <select
                id="template_select_horizontal"
                v-model="selectedTemplate"
                class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
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
                v-model="createTripForm.route_id"
                class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                required
              >
                <option value="">Sélectionner une route</option>
                <option v-for="route in routes" :key="route.id" :value="route.id">
                  {{ route.display_name || route.name }}
                </option>
              </select>
              <InputError class="mt-2" :message="createTripErrors.route_id" />
            </div>

            <div>
              <InputLabel for="vehicle_id" value="Véhicule" />
              <select
                id="vehicle_id"
                v-model="createTripForm.vehicle_id"
                class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
              >
                <option value="">Sélectionner un véhicule</option>
                <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">
                  {{ vehicle.identifier }} ({{ vehicle.seat_count }} places)
                </option>
              </select>
              <InputError class="mt-2" :message="createTripErrors.vehicle_id" />
            </div>

            <div>
              <InputLabel for="departure_at" value="Heure de départ" />
              <TextInput
                id="departure_at"
                v-model="createTripForm.departure_at"
                type="datetime-local"
                class="mt-1 block w-full rounded-lg border-slate-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                required
              />
              <InputError class="mt-2" :message="createTripErrors.departure_at" />
            </div>

            <div>
              <InputLabel for="code" value="Code / Numéro de voyage" />
              <TextInput
                id="code"
                v-model="createTripForm.code"
                type="text"
                class="mt-1 block w-full rounded-lg border-slate-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                placeholder="Sera généré automatiquement (Ex: ABJ-BKE-0800)"
              />
              <InputError class="mt-2" :message="createTripErrors.code" />
            </div>

            <div>
              <InputLabel for="trip_auto_allocation_horizontal" value="Allocation automatique sur ce voyage" />
              <select id="trip_auto_allocation_horizontal" v-model="createTripForm.automatic_connection_allocation" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100">
                <option :value="null">Hériter du trajet et de la compagnie</option>
                <option :value="true">Activer pour ce voyage</option>
                <option :value="false">Désactiver pour ce voyage</option>
              </select>
            </div>

            <div class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200 dark:border-slate-800 flex items-center justify-between gap-4">
              <div>
                <label class="text-xs font-semibold text-slate-900 dark:text-slate-100">Correspondances ouvertes</label>
                <p class="text-[10px] text-slate-500 dark:text-slate-400">
                  Autoriser une destination finale au-delà de l’arrivée.
                </p>
              </div>
              <button type="button" @click="createTripForm.allows_open_connections = !createTripForm.allows_open_connections"
                :class="['relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors', createTripForm.allows_open_connections ? 'bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800']">
                <span :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition', createTripForm.allows_open_connections ? 'translate-x-4' : 'translate-x-0']" />
              </button>
            </div>

            <div v-if="['admin', 'supervisor'].includes($page.props.auth.user.role)" class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200 dark:border-slate-800 flex items-center justify-between gap-4">
              <div>
                <label class="text-xs font-semibold text-slate-900 dark:text-slate-100">Voyage réplicable (récurrent)</label>
                <p class="text-[10px] text-slate-500 dark:text-slate-400">
                  Recréer ce voyage chaque jour à minuit (sans bus ni équipage affectés).
                </p>
              </div>
              <button type="button" @click="createTripForm.is_replicable = !createTripForm.is_replicable"
                :class="['relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors', createTripForm.is_replicable ? 'bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800']">
                <span :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition', createTripForm.is_replicable ? 'translate-x-4' : 'translate-x-0']" />
              </button>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4">
              <button
                type="button"
                @click="showCreateTripModal = false"
                class="px-4 py-2 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-700 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                Annuler
              </button>
              <button
                type="submit"
                :disabled="createTripProcessing"
                class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50"
              >
                {{ createTripProcessing ? 'Création...' : 'Créer' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Zoom Modal for Seat Map -->
    <div v-if="showZoomModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/55 dark:bg-black/60 p-4 backdrop-blur-[2px]">
      <div class="relative flex h-full w-full max-h-[90vh] max-w-7xl flex-col overflow-hidden rounded-3xl border border-white/70 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-[0_24px_70px_rgba(15,23,42,0.18)]">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 bg-gradient-to-r from-emerald-50 to-slate-50 dark:from-slate-950/20 dark:to-slate-900/20 px-6 py-4">
          <div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-slate-100">Plan des Places</h3>
            <p class="text-sm text-gray-600 dark:text-slate-400 mt-1">
              {{ currentTrip?.code || 'Code en attente' }} - {{ currentTrip?.display_name }} - {{ currentTrip?.vehicle?.identifier }}
            </p>
          </div>
          <button @click="showZoomModal = false" class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-350 transition-colors">
            <Close class="w-8 h-8" />
          </button>
        </div>

        <!-- Legend Removed -->

        <!-- Seat Map with Scroll -->
        <div class="flex flex-1 items-center justify-center overflow-auto bg-slate-50 dark:bg-slate-950/40 p-6">
          <div class="transform rotate-90">
            <VehicleSeatMapSVG
              v-if="currentTrip?.vehicle?.vehicle_type"
              :vehicle-type="currentTrip.vehicle.vehicle_type"
              :seat-map="seatMap"
              :suggested-seats="suggestedSeats"
              :show-suggestions="ticketingStore.showSuggestions && !!selectedFare && suggestedSeats.length > 0"
              :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role) || isSalesClosedForSeller"
              :sellable-seat-numbers="currentStationSellableSeatNumbers"
              :sellable-seat-border-color="currentStationSellableSeatBorderColor"
              @seat-click="bookSeat"
              class="scale-125"
            />
          </div>
        </div>

        <!-- Instructions -->
        <div class="border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 px-6 py-3 text-center text-sm text-slate-600 dark:text-slate-400">
          Le véhicule est affiché horizontalement. Utilisez le défilement pour voir toutes les places. Cliquez sur une place pour réserver.
        </div>
      </div>
    </div>
    </div>
    </div>
    <!-- Supervisor Inspection Modal -->
    <TicketInspectionModal
      :show="showInspectionModal"
      :validation="selectedTicketForInspection"
      @close="showInspectionModal = false"
      @approve="() => { showInspectionModal = false; }"
      @decline="() => { showInspectionModal = false; }"
    />

    <!-- Trip Details & Sold Tickets Modal -->
    <TripDetailsModal
      :visible="showTripDetailsModal"
      :trip-id="selectedDetailsTripId"
      :assigned-station="assignedStation"
      :assigned-station-id="assignedStationId"
      :current-user-role="page.props.auth.user.role"
      :current-user-id="page.props.auth.user.id"
      @close="showTripDetailsModal = false"
      @ticket-cancelled="fetchSeatMap({ silent: true })"
    />
  </MainNavLayout>
</template>

<style scoped>
/* Custom scrollbar for better UX */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}

::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}

::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
