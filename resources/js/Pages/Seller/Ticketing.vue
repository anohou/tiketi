<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { toastStore } from '@/Stores/toastStore.js';
import { router, Link, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';
import BookingModal from '@/Components/Seller/BookingModal.vue';
import TicketInspectionModal from '@/Components/Supervisor/TicketInspectionModal.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Check from 'vue-material-design-icons/Check.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Bluetooth from 'vue-material-design-icons/Bluetooth.vue';
import Account from 'vue-material-design-icons/Account.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import History from 'vue-material-design-icons/History.vue';
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue';
import FileExcel from 'vue-material-design-icons/FileExcel.vue';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import { useExportPrint } from '@/Composables/useExportPrint.js';
import { useTicketing } from '@/Composables/useTicketing.js';
import axios from 'axios';

const props = defineProps({
  trips: [Array, Object],
  routeFares: Array,
  routes: Array,
  vehicles: Array,
  hasActiveAssignment: Boolean,
  assignedStationId: String,
  assignedStation: String,
  destinations: {
    type: Array,
    default: () => []
  }
});

// Setup shared ticketing logic (vertical layout supports pagination and segment params)
const ticketing = useTicketing(props, { supportsPagination: true, sendsSegmentParams: true });

// Destructure from the unifed composable
const {
  trips: tripsRef,
  pagination,
  loadingMore,
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
  showPassengerModal,
  showDestinationModal,
  selectedSeatNumber,
  seatSelectionMode,
  seatFirstFlow,
  selectedSeatColor,
  passengerForm,
  passengerFormErrors,
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
  isTripPassed,
  seatsToBook,
  totalAmount,
  canBookTickets,
  seatStats,
  getOccupancyRate,
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
  initiateBookingFlow,
  autoSelectOptimalSeat,
  confirmBooking,
  cancelBooking,
  createTrip,
  fallbackToBrowserPrint,
  moveTripUp,
  moveTripDown,
  isDraggable,
  dragOverIndex,
  dragStart,
  dragEnter,
  dragEnd,
  dragDrop,
  isSalesClosedForSeller,
  hasFreedSeatsForSeller,
  isFareDisabled,
  getAssignedStationPalette,
  page,
} = ticketing;

const assignedStationPalette = computed(() => getAssignedStationPalette());

// Aliases and reactive states specific to the vertical paginated layout
const trips = tripsRef;
const showHistory = ref(false);
const isMobile = ref(window.innerWidth < 768);
const showTripSelectionModal = ref(false);

const selectedDestinationId = computed({
  get: () => ticketingStore.selectedDestinationId,
  set: (val) => ticketingStore.setDestinationFilter(val)
});

// Toggle history effect (loads from backend on history toggle)
watch(showHistory, (val) => {
    loadingMore.value = false;
    router.get(window.location.pathname, {
        show_history: val,
        trip_id: selectedTripId.value
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['trips']
    });
});

// Infinite Scroll pagination
const loadMore = () => {
    if (!pagination.value?.next_page_url || loadingMore.value) return;
    
    loadingMore.value = true;
    router.get(pagination.value.next_page_url, {
        show_history: showHistory.value,
        trip_id: selectedTripId.value
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['trips']
    });
};

const scrollToSeats = () => {
  setTimeout(() => {
    const el = document.getElementById('mobile-seat-map');
    if (el) el.scrollIntoView({ behavior: 'smooth' });
  }, 100);
};

// Exports feature setup
const { exportToExcel } = useExportPrint();
const exportExcelLoadingTripId = ref(null);
const exportPdfLoadingTripId = ref(null);

const exportTicketsToExcel = async (tripId) => {
  exportExcelLoadingTripId.value = tripId;
  try {
    const params = { trip_id: tripId };
    const response = await axios.get(route('seller.tickets.export'), { params });
    
    if (response.data?.data?.length > 0) {
      const columns = {
        n_ticket: 'N° Ticket',
        date: 'Date',
        heure: 'Heure',
        ligne: 'Ligne',
        depart: 'Départ',
        arrivee: 'Arrivée',
        place: 'Place',
        zone_embarquement: 'Zone',
        prix_fcfa: 'Prix (FCFA)',
        vendeur: 'Vendeur',
        passager: 'Passager',
        telephone: 'Téléphone',
        statut: 'Statut',
        date_voyage: 'Date Voyage',
        vehicule: 'Véhicule',
      };
      exportToExcel(response.data.data, columns, 'rapport_tickets');
    } else {
      toastStore.warning('Aucun ticket à exporter pour aujourd\'hui.');
    }
  } catch (error) {
    console.error('Erreur export Excel:', error);
    toastStore.error('Erreur lors de l\'export Excel. Veuillez réessayer.');
  } finally {
    exportExcelLoadingTripId.value = null;
  }
};

const exportTicketsToPdf = (tripId) => {
  exportPdfLoadingTripId.value = tripId;
  try {
    const params = new URLSearchParams();
    params.set('trip_id', tripId);
    const url = route('tickets.export-pdf') + '?' + params.toString();
    window.open(url, '_blank');
  } catch (error) {
    console.error('Erreur export PDF:', error);
    toastStore.error('Erreur lors de l\'export PDF. Veuillez réessayer.');
  } finally {
    exportPdfLoadingTripId.value = null;
  }
};

onMounted(() => {
  window.addEventListener('resize', () => {
    isMobile.value = window.innerWidth < 768;
  });
});
</script>

<template>
  <MainNavLayout :show-nav="!isMobile">
    <template #header-actions>
      <!-- Bluetooth Printer Toggle moved to Header -->
      <button 
        @click="toggleBluetoothPrinter" 
        :class="[
          'p-2 border rounded-full text-sm font-medium flex items-center justify-center transition-all',
          useBluetoothPrinter && bluetoothPrinterConnected 
            ? 'border-emerald-500 bg-emerald-100 text-emerald-700' 
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
        ]"
        :title="bluetoothPrinterConnected ? `Connecté: ${bluetoothPrinterName}` : 'Connecter imprimante Bluetooth'"
      >
        <Bluetooth :class="bluetoothPrinterConnected ? 'text-emerald-600' : 'text-gray-500'" class="w-5 h-5" />
      </button>
    </template>

    <div class="flex-1 flex flex-col gap-4 min-h-0 bg-slate-50/70 dark:bg-slate-950">
          
          <!-- Full-page blocking message if no station assigned (for sellers and supervisors) -->
          <div v-if="['seller', 'supervisor'].includes($page.props.auth.user.role) && !hasActiveAssignment" 
               class="flex-1 flex items-center justify-center">
            <div class="bg-white border border-slate-200 p-12 rounded-3xl flex flex-col items-center text-center shadow-sm max-w-lg dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
              <div class="p-5 bg-emerald-50 rounded-full shadow-sm mb-6 dark:bg-emerald-900/25">
                <OfficeBuilding class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
              </div>
              <h2 class="text-2xl font-black text-gray-900 mb-3 dark:text-slate-100">Aucune station assignée</h2>
              <p class="text-gray-600 mb-6 leading-relaxed dark:text-slate-400">
                Vous n'avez pas encore de station assignée. Vous ne pouvez pas vendre de billets tant qu'un superviseur ne vous a pas assigné à une station.
              </p>
              <div class="space-y-3 w-full">
                <p class="text-sm text-gray-500 dark:text-slate-500">
                  Contactez votre Administrateur pour être assigné à une station.
                </p>
                <Link 
                  :href="route('profile.edit')" 
                  class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                >
                  Voir mon profil
                </Link>
              </div>
            </div>
          </div>
 
          <!-- Main content (only shown if seller has assigned station or user is admin/supervisor) -->
          <template v-else>
          <!-- Workplace Header (Synced with Dashboard) -->
          <div class="bg-white p-4 md:p-6 rounded-3xl shadow-sm border border-slate-200 shrink-0 relative dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="z-10">
                <div class="flex items-center gap-3">
                  <h1 class="text-3xl font-black text-gray-900 tracking-tight dark:text-slate-100">Billetterie</h1>
                  <div
                    v-if="assignedStation || ['admin', 'executive', 'supervisor'].includes($page.props.auth.user.role)"
                    class="px-3 py-1 text-xs font-black rounded-full border flex items-center gap-1.5 shadow-sm"
                    :style="assignedStationPalette ? {
                      backgroundColor: assignedStationPalette.bg,
                      color: assignedStationPalette.fg || '#FFFFFF',
                      borderColor: assignedStationPalette.bg,
                    } : null"
                  >
                      <OfficeBuilding :size="14" />
                      {{ assignedStation || 'Toutes les gares' }}
                  </div>
                </div>
                <p class="text-gray-500 font-medium dark:text-slate-400">Vente de tickets en temps réel</p>
              </div>
 
              <!-- Absolute Centered Clock on Desktop -->
              <div class="hidden md:block absolute left-1/2 -translate-x-1/2 text-center z-0">
                <div class="text-4xl font-black text-gray-900 tracking-tight leading-none dark:text-slate-100">{{ currentTime }}</div>
                <div class="text-[10px] font-bold text-gray-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
              </div>
 
              <!-- Clock and Button aligned on mobile / Button on right on Desktop -->
              <div class="flex items-center justify-between md:justify-end gap-4 md:gap-6 mt-2 md:mt-0 w-full md:w-auto z-10 shrink-0">
                <!-- Mobile Clock -->
              <div class="text-left md:hidden">
                  <div class="text-2xl font-black text-gray-900 tracking-tight leading-none dark:text-slate-100">{{ currentTime }}</div>
                  <div class="text-[10px] font-bold text-gray-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
                </div>
                <button
                  @click="showCreateTripModal = true"
                  class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-xl font-bold shadow-lg shadow-emerald-600/20 transition-all active:scale-95 flex-shrink-0"
                >
                  <Plus :size="20" />
                  <span>Nouveau Voyage</span>
                </button>
              </div>
            </div>
          </div>
 
          <!-- Content Area: Voyages + Tronçons (Full width grid) -->
          <div class="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-3 md:gap-4 min-h-0">
            <!-- Voyages -->
            <div class="lg:col-span-7 xl:col-span-8 flex flex-col min-h-0 overflow-hidden">
              <div class="bg-white rounded-3xl border border-slate-200 shadow-sm flex flex-col h-full overflow-hidden dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
                <div class="px-5 py-3 border-b border-slate-100 bg-emerald-50/40 flex flex-col md:flex-row md:items-center justify-between gap-3 dark:border-slate-800 dark:bg-slate-800/60">
                  <div class="flex flex-col md:flex-row md:items-center gap-3 w-full md:w-auto">
                    <!-- Mobile Group: Title + Badges -->
                    <div class="flex items-center justify-between w-full md:w-auto">
                      <h2 class="text-base font-semibold text-emerald-700 flex items-center shrink-0 dark:text-emerald-300">
                        <Bus class="mr-2 w-5 h-5" />
                        Voyages
                      </h2>
                      <!-- Badges on Mobile -->
                      <div class="flex items-center gap-2 md:hidden">
                        <span class="px-2 py-0.5 bg-emerald-600 text-white rounded-full text-xs font-black shadow-sm dark:bg-emerald-500">
                          {{ trips.length }} en cours
                        </span>
                      </div>
                    </div>
                    
                    <!-- Destination Filter + Changer on Mobile & Desktop -->
                    <div class="flex items-center gap-2 w-full md:w-auto">
                       <select v-model="selectedDestinationId" class="flex-1 md:w-48 border-slate-200 text-slate-700 rounded-lg text-sm px-3 py-1.5 focus:border-emerald-500 focus:ring-emerald-500 bg-white shadow-sm font-semibold dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                          <option value="">Toutes les destinations</option>
                          <option v-for="dest in destinations" :key="dest.id" :value="dest.id">{{ dest.name }}</option>
                       </select>
  
                       <!-- History Toggle -->
  
                       <!-- History Toggle -->
                       <button 
                         v-if="['admin', 'supervisor', 'superadmin'].includes(page.props.auth.user.role)"
                         @click="showHistory = !showHistory"
                         :class="['p-1.5 rounded-lg border transition-all flex items-center justify-center gap-1 shadow-sm', showHistory ? 'bg-slate-900 border-slate-900 text-white dark:bg-slate-100 dark:border-slate-100 dark:text-slate-900' : 'bg-white border-slate-200 text-gray-500 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:bg-slate-800']"
                         :title="showHistory ? 'Masquer l\'historique' : 'Voir l\'historique (48h)'"
                       >
                         <History :size="20" />
                       </button>
  
                       <button 
                         @click="showTripSelectionModal = true"
                         class="px-3 py-1.5 bg-white border border-emerald-500 text-emerald-700 rounded-lg text-sm font-bold shadow-sm whitespace-nowrap active:bg-emerald-50 flex items-center justify-center gap-1.5 hover:bg-emerald-50 transition-colors dark:bg-emerald-900/20 dark:text-emerald-300 dark:hover:bg-emerald-900/30"
                       >
                         <Magnify v-if="!isMobile" :size="18" />
                         <span>Tous les voyages</span>
                       </button>
                     </div>
                   </div>
                   
                   <div class="hidden md:flex items-center gap-2 shrink-0">
                     <span class="px-2.5 py-1 bg-emerald-600 text-white rounded-full text-sm font-black shadow-sm">
                       {{ trips.length }} en cours
                     </span>
                   </div>
                 </div>
                 <div class="flex-1 p-3 overflow-y-auto">
                   <!-- Mobile: Show only selected trip -->
                   <div v-if="isMobile && currentTrip" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-3 shadow-sm relative overflow-hidden">
                     <div class="flex items-start justify-between mb-2">
                       <div class="flex-1 min-w-0">
                         <div class="flex items-center gap-2 mb-1">
                           <div :class="['w-2 h-2 rounded-full shrink-0', new Date(currentTrip.departure_at) < new Date() ? 'bg-gray-400' : 'bg-emerald-500 animate-pulse']"></div>
                           <div :class="['text-[10px] uppercase font-bold tracking-wider', new Date(currentTrip.departure_at) < new Date() ? 'text-gray-500' : 'text-emerald-600 dark:text-emerald-400']">
                             {{ new Date(currentTrip.departure_at) < new Date() ? 'Voyage Passé' : 'En cours' }}
                           </div>
                         </div>
                         <div class="flex items-center gap-2 flex-wrap">
                           <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-black tracking-wider">
                             {{ currentTrip.code || 'Code en attente' }}
                           </span>
                           <span class="text-xs font-semibold text-gray-500 dark:text-slate-400">Trajet</span>
                         </div>
                         <div class="text-base font-black text-gray-900 dark:text-slate-100 leading-tight whitespace-normal break-words">
                           {{ currentTrip.display_name }}
                         </div>
                       </div>
                         <div class="text-right shrink-0 ml-3 flex flex-col items-end gap-2">
                           <div class="flex items-center gap-1.5">
                             <Link
                               :href="route('seller.ticketing.horizontal', { trip_id: currentTrip.id })"
                               class="p-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors disabled:opacity-50"
                               title="Vue horizontale"
                             >
                               <svg viewBox="0 0 24 24" aria-hidden="true" class="w-4 h-4">
                                 <path
                                   fill="currentColor"
                                   d="M4 4h7v2H7.41l3.3 3.3-1.42 1.4L6 7.41V11H4V4Zm16 0v7h-2V7.41l-3.29 3.29-1.42-1.4L16.59 6H13V4h7Zm0 16h-7v-2h3.59l-3.3-3.3 1.42-1.4L18 16.59V13h2v7Zm-16 0v-7h2v3.59l3.29-3.29 1.42 1.4L7.41 18H11v2H4Z"
                                 />
                               </svg>
                               <span class="sr-only">Vue horizontale</span>
                             </Link>
                             <button
                               @click.stop="exportTicketsToExcel(currentTrip.id)"
                               :disabled="exportExcelLoadingTripId === currentTrip.id"
                               class="p-1.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 rounded-lg hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition-colors disabled:opacity-50"
                               title="Exporter ce voyage en Excel"
                             >
                               <FileExcel :size="16" />
                             </button>
                             <button
                               @click.stop="exportTicketsToPdf(currentTrip.id)"
                               :disabled="exportPdfLoadingTripId === currentTrip.id"
                               class="p-1.5 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-855 text-rose-700 dark:text-rose-300 rounded-lg hover:bg-rose-100 dark:hover:bg-rose-900/40 transition-colors disabled:opacity-50"
                               title="Exporter ce voyage en PDF"
                             >
                               <FilePdfBox :size="16" />
                             </button>
                           </div>
                           <div>
                          <div class="text-xl font-black text-slate-900 dark:text-slate-100">
                               {{ new Date(currentTrip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}
                           </div>
                           <div class="text-[10px] text-slate-500 dark:text-slate-400">{{ currentTrip.vehicle?.identifier }}</div>
                         </div>
                       </div>
                     </div>
                     <!-- Seat Stats Row -->
                     <div v-if="seatStats.total > 0" class="flex items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                       <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-rose-50 dark:bg-rose-950/30 rounded-lg">
                         <span class="text-lg font-black text-rose-600 dark:text-rose-450">{{ seatStats.available }}</span>
                         <span class="text-[10px] text-rose-600 dark:text-rose-400 font-medium">restantes</span>
                       </div>
                       <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-emerald-50 dark:bg-emerald-950/30 rounded-lg">
                         <span class="text-lg font-black text-emerald-600 dark:text-emerald-450">{{ seatStats.total }}</span>
                         <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">total</span>
                       </div>
                       <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-slate-50 dark:bg-slate-800 rounded-lg">
                         <span class="text-lg font-black text-slate-700 dark:text-slate-300">{{ getOccupancyRate(seatStats.available, seatStats.total) }}%</span>
                         <span class="text-[10px] text-slate-600 dark:text-slate-400 font-medium">Taux de remplissage</span>
                       </div>
                     </div>
                   </div>
 
                   <!-- Desktop: Show all trips with highlighted selected -->
                   <div v-if="!isMobile && filteredTrips.length > 0" class="space-y-3">
                     <div
                       v-for="(trip, index) in filteredTrips"
                       :key="trip.id"
                       @click="selectTrip(trip.id)"
                       draggable="true"
                       @dragstart="dragStart($event, index)"
                       @dragover.prevent
                       @dragenter="dragEnter($event, index)"
                       @dragend="dragEnd"
                       @drop="dragDrop($event, index)"
                       :class="[
                         'p-4 rounded-3xl cursor-grab active:cursor-grabbing transition-all duration-300 border-2',
                         selectedTripId === trip.id
                           ? 'bg-emerald-50 border-emerald-500 dark:bg-emerald-950/20 dark:border-emerald-800 shadow-lg scale-[1.01]'
                           : ticketingStore.tripHighlights?.[trip.id]
                             ? 'bg-amber-50 border-amber-400 dark:bg-amber-950/20 dark:border-amber-800 shadow-xl shadow-amber-200/40 dark:shadow-amber-950/20 scale-[1.01]'
                           : 'bg-slate-50 border-transparent dark:bg-slate-900/50 hover:border-emerald-200 dark:hover:border-emerald-800 hover:bg-white dark:hover:bg-slate-800 hover:shadow-md',
                         dragOverIndex === index ? 'border-dashed border-emerald-500 dark:border-emerald-600 bg-emerald-100/30 dark:bg-emerald-950/30 scale-[1.01]' : ''
                       ]"
                     >
                       <div class="flex items-center justify-between">
                         <div class="flex items-center gap-4 flex-1 min-w-0">
                           <div :class="[
                             'p-2 rounded-xl shadow-sm transition-colors',
                             selectedTripId === trip.id ? 'bg-white dark:bg-slate-950' : 'bg-white dark:bg-slate-950 group-hover:bg-emerald-50 dark:group-hover:bg-emerald-950/20'
                           ]">
                             <Bus :size="24" :class="selectedTripId === trip.id ? 'text-emerald-600' : 'text-gray-400 dark:text-slate-500'" />
                           </div>
                           <div class="min-w-0">
                             <div class="flex items-center gap-2">
                               <div v-if="selectedTripId === trip.id" :class="['w-2 h-2 rounded-full', new Date(trip.departure_at) < new Date() ? 'bg-gray-400' : 'bg-emerald-500 animate-pulse']"></div>
                               <div v-else-if="ticketingStore.tripHighlights?.[trip.id]" class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div>
                               <div :class="['font-bold tracking-tight whitespace-normal break-words', new Date(trip.departure_at) < new Date() ? 'text-gray-500 italic' : 'text-gray-900 dark:text-slate-100']">
                                 <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-black tracking-wider mr-2">
                                   {{ trip.code || 'Code en attente' }}
                                 </span>
                                 <span class="align-middle">{{ trip.display_name }}</span>
                                 <span v-if="new Date(trip.departure_at) < new Date()" class="ml-2 text-[10px] font-black bg-gray-100 dark:bg-slate-800 text-gray-500 px-1.5 py-0.5 rounded uppercase">Passé</span>
                               </div>
                               <span
                                 :title="trip.sales_control === 'open' ? 'Ventes intermédiaires autorisées' : 'Ventes origine uniquement'"
                                 class="text-xs shrink-0"
                               >{{ trip.sales_control === 'open' ? '🔓' : '🔒' }}</span>
                             </div>
                             <div class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest mt-0.5">
                               {{ trip.vehicle?.identifier }} • {{ trip.vehicle?.vehicle_type?.name }}
                             </div>
                           </div>
                         </div>
                         <div class="text-right shrink-0 ml-3 flex flex-col items-end gap-2">
                           <div class="flex items-center gap-1.5">
                             <Link
                               :href="route('seller.ticketing.horizontal', { trip_id: trip.id })"
                               @dragstart.stop.prevent
                               class="p-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-350 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-750 transition-colors disabled:opacity-50"
                               title="Vue horizontale"
                             >
                               <svg viewBox="0 0 24 24" aria-hidden="true" class="w-4 h-4">
                                 <path
                                   fill="currentColor"
                                   d="M4 4h7v2H7.41l3.3 3.3-1.42 1.4L6 7.41V11H4V4Zm16 0v7h-2V7.41l-3.29 3.29-1.42-1.4L16.59 6H13V4h7Zm0 16h-7v-2h3.59l-3.3-3.3 1.42-1.4L18 16.59V13h2v7Zm-16 0v-7h2v3.59l3.29-3.29 1.42 1.4L7.41 18H11v2H4Z"
                                 />
                               </svg>
                               <span class="sr-only">Vue horizontale</span>
                             </Link>
                             <button
                               @click.stop="exportTicketsToExcel(trip.id)"
                               :disabled="exportExcelLoadingTripId === trip.id"
                               @dragstart.stop.prevent
                               class="p-1.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 rounded-lg hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition-colors disabled:opacity-50"
                               title="Exporter ce voyage en Excel"
                             >
                               <FileExcel :size="16" />
                             </button>
                             <button
                               @click.stop="exportTicketsToPdf(trip.id)"
                               :disabled="exportPdfLoadingTripId === trip.id"
                               @dragstart.stop.prevent
                               class="p-1.5 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 rounded-lg hover:bg-rose-100 dark:hover:bg-rose-900/40 transition-colors disabled:opacity-50"
                               title="Exporter ce voyage en PDF"
                             >
                               <FilePdfBox :size="16" />
                             </button>
                           </div>
                           <div>
                             <div class="text-xl font-black text-gray-900 dark:text-slate-100">
                               {{ new Date(trip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}
                             </div>
                             <div class="text-[10px] text-gray-500 dark:text-slate-400 font-bold capitalize">
                               {{ new Date(trip.departure_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' }) }}
                             </div>
                           </div>
                         </div>
                       </div>
                       <!-- Seat Stats for all trips -->
                       <div class="flex items-center gap-3 mt-4 pt-4 border-t border-dashed" :class="selectedTripId === trip.id ? 'border-emerald-200 dark:border-emerald-800/80' : 'border-slate-200 dark:border-slate-800/40'">
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">Restantes</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-rose-600 dark:text-rose-400">{{ trip.available_seats || 0 }}</span>
                                 <span class="text-[9px] text-rose-600/70 dark:text-rose-400/70 mb-0.5 font-bold uppercase">Lib</span>
                             </div>
                         </div>
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">Total</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-slate-700 dark:text-slate-300">{{ trip.total_seats || 0 }}</span>
                                 <span class="text-[9px] text-slate-500 dark:text-slate-400 mb-0.5 font-bold uppercase">Cap</span>
                             </div>
                         </div>
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">Taux de remplissage</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-slate-700 dark:text-slate-300">{{ getOccupancyRate(trip.available_seats || 0, trip.total_seats || 0) }}%</span>
                                 <span class="text-[9px] text-slate-500/70 dark:text-slate-400/70 mb-0.5 font-bold uppercase">Occ</span>
                             </div>
                         </div>
                       </div>
                     </div>
                   </div>
 
                   <!-- No trip selected / No trips -->
                   <div v-if="isMobile && !currentTrip" class="h-full flex flex-col items-center justify-center bg-slate-50 dark:bg-slate-950/20 rounded-xl border-2 border-dashed border-slate-200 dark:border-slate-800 py-10">
                     <div class="bg-white dark:bg-slate-900 p-6 rounded-full shadow-md mb-6 relative">
                        <Bus class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
                        <div class="absolute -top-2 -right-2 bg-rose-500 text-white text-xs font-bold px-2 py-1 rounded-full border-2 border-white dark:border-slate-800 shadow-sm">
                          {{ trips.length }}
                        </div>
                     </div>
                     <h3 class="text-2xl font-black text-gray-900 dark:text-slate-100 mb-2">{{ trips.length }} voyages en cours</h3>
                     <p class="text-gray-500 dark:text-slate-400 text-sm max-w-[250px] text-center mb-6">Sélectionnez le voyage pour lequel vous souhaitez vendre des billets.</p>
                     <button 
                       @click="showTripSelectionModal = true"
                       class="px-8 py-3 bg-emerald-600 text-white rounded-xl text-lg font-black shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 hover:scale-105 transition-all transform active:scale-95"
                     >
                       Choisir un voyage
                     </button>
                   </div>
                 </div>
               </div>
             </div>
 
             <!-- Tronçons / Destinations -->
             <div :class="[
               'lg:col-span-5 xl:col-span-4 flex flex-col min-h-0 overflow-hidden',
               isMobile && !autoSelectOptimal && selectedFare ? 'order-3' : 'order-2'
             ]">
               <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
                 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-between">
                   <h2 class="text-base font-bold text-slate-700 dark:text-slate-200 flex items-center">
                     <Routes class="mr-2 w-5 h-5" />
                     Destinations
                   </h2>
                   
                   <div class="flex items-center gap-3">
                     <!-- Seats modal button for mobile (scrolls down) -->
                     <button 
                       v-if="currentTrip"
                       @click="scrollToSeats"
                       class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl shadow-sm text-xs font-bold flex items-center justify-center gap-1.5 transition-colors md:hidden"
                     >
                       <Bus :size="16" />
                       <span>Sièges</span>
                     </button>
 
                     <!-- Auto toggle moved here -->
                     <label class="flex items-center gap-2 cursor-pointer bg-emerald-50 dark:bg-emerald-950/20 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-900/40 shadow-sm hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                       <input 
                         type="checkbox" 
                         v-model="autoSelectOptimal"
                         class="h-5 w-5 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500 accent-emerald-600"
                       />
                       <span class="text-xs font-semibold text-emerald-800 dark:text-emerald-300">⚡ Auto</span>
                     </label>
                   </div>
                 </div>
                 <div class="flex-1 overflow-y-auto p-2">
                   <div v-if="currentTrip" class="space-y-2">
                       <!-- Sales Closed Warning Banner -->
                       <template v-if="isSalesClosedForSeller">
                         <div v-if="hasFreedSeatsForSeller" class="mb-3 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900/40 rounded-2xl p-3 text-xs text-emerald-800 dark:text-emerald-300 flex items-start gap-2 shadow-sm">
                           <span class="text-base leading-none">🔓</span>
                           <div>
                             <strong>Places libérées disponibles :</strong> Ce voyage est fermé aux ventes intermédiaires, mais des places libérées (passagers descendus) sont disponibles à votre gare.
                           </div>
                         </div>
                         <div v-else class="mb-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 rounded-2xl p-3 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2 shadow-sm">
                           <span class="text-base leading-none">🔒</span>
                           <div>
                             <strong>Ventes fermées :</strong> Ce voyage est fermé aux ventes intermédiaires. Aucune place n'a été libérée à votre gare pour le moment.
                           </div>
                         </div>
                       </template>

                      <div v-for="fare in availableFares" :key="fare.id"
                          @click="(!isTripPassed && !isFareDisabled(fare)) && (selectedFare = fare)"
                          :class="[
                    'relative overflow-hidden rounded-3xl transition-all duration-300 border-2 shadow-sm',
                    (isTripPassed || isFareDisabled(fare)) ? 'opacity-50 cursor-not-allowed grayscale' : 'cursor-pointer active:scale-[0.98]',
                    selectedFare?.id === fare.id 
                              ? 'ring-2 ring-offset-2 scale-[1.02] shadow-xl border-emerald-500 ring-emerald-500' 
                              : 'border-transparent hover:shadow-lg'
                          ]"
                          :style="{
                            backgroundColor: fare.color || '#0f766e',
                            '--tw-ring-color': selectedFare?.id === fare.id ? '#10b981' : (fare.color || '#0f766e')
                          }"
                     >
                       <!-- Horizontal Layout: Destination Left, Price Right -->
                       <div class="p-3 flex items-center justify-between">
                         <div class="flex-1 min-w-0 mr-3">
                           <div class="text-base font-bold truncate" :style="{ color: fare.textColor || '#FFFFFF' }">
                             {{ fare.to_station?.name }}
                           </div>
                           <div class="text-[10px] font-medium" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">
                             → depuis {{ fare.from_station?.name?.split(' - ')[1] || fare.from_station?.name }}
                           </div>
                         </div>
                         <div class="text-right shrink-0 flex items-center gap-2">
                           <!-- Checkmark removed as requested -->
                           <div>
                             <div class="text-2xl font-black" :style="{ color: fare.textColor || '#FFFFFF' }">
                               {{ fare.amount.toLocaleString('fr-FR') }}
                             </div>
                             <div class="text-[10px] font-bold" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">FCFA</div>
                           </div>
                         </div>
                       </div>
                       <div v-if="ticketQuantity > 1" class="bg-black/10 px-3 py-1 text-white/90 text-[10px] font-bold">
                         ×{{ ticketQuantity }} = {{ (fare.amount * ticketQuantity).toLocaleString('fr-FR') }} F
                       </div>
                     </div>
                   </div>
                   <div v-else class="p-8 text-center text-slate-400 dark:text-slate-500">
                     <p>Sélectionnez un voyage pour voir les destinations.</p>
                   </div>
 
                   <!-- Passed Trip Message -->
                   <div v-if="currentTrip && isTripPassed" class="mx-3 mt-4 p-4 bg-slate-100 dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 rounded-3xl flex flex-col items-center text-center">
                     <div class="p-2 bg-slate-200 dark:bg-slate-800 rounded-full mb-3">
                       <Clock :size="20" class="text-slate-500 dark:text-slate-400" />
                     </div>
                     <div class="text-xs font-black text-slate-900 dark:text-slate-200 uppercase tracking-widest mb-1">Ventes Fermées</div>
                     <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">Ce voyage est déjà parti le {{ new Date(currentTrip.departure_at).toLocaleDateString('fr-FR') }} à {{ new Date(currentTrip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}. Les réservations ne sont plus possibles.</p>
                   </div>
                 </div>
                 
                 <!-- Mobile Seat Map inline -->
                 <div id="mobile-seat-map" v-if="seatMap && currentTrip?.vehicle?.vehicle_type" class="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/20 flex flex-col items-center overflow-x-hidden md:hidden">
                   <h3 class="text-sm font-bold text-slate-700 dark:text-slate-250 mb-8 w-full flex items-center justify-center gap-2">
                      <Bus class="w-5 h-5 text-emerald-600 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-slate-800 rounded p-0.5 shadow-sm" />
                      Avant du bus
                   </h3>
                   
                   <div class="w-full flex items-center justify-center py-4 overflow-x-auto">
                     <div class="scale-100 origin-top transition-transform">
                       <VehicleSeatMapSVG
                         :key="'mobile-' + currentTrip.id"
                         :vehicle-type="currentTrip.vehicle.vehicle_type"
                         :seat-map="seatMap"
                         :suggested-seats="suggestedSeats"
                       :show-suggestions="ticketingStore.showSuggestions && !!selectedFare && suggestedSeats.length > 0"
                       :selected-seat="selectedSeatNumber"
                       :selected-color="selectedSeatColor"
                       :sellable-seat-numbers="currentStationSellableSeatNumbers"
                       :sellable-seat-border-color="currentStationSellableSeatBorderColor"
                       :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role) || isSalesClosedForSeller"
                       @seat-click="handleSeatClick"
                       class="w-full h-auto"
                       />
                     </div>
                   </div>
                   
                   <div class="mt-8 text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest flex items-center gap-2">
                      Arrière du bus
                   </div>
                 </div>
                 
               </div>
             </div>
         </div>
          </template>
    </div>

    <BookingModal
      :visible="showPassengerModal || showDestinationModal"
      :mode="showDestinationModal ? 'destination' : 'passenger'"
      :current-trip="currentTrip"
      :selected-seat-number="selectedSeatNumber"
      :selected-fare="selectedFare"
      :available-fares="availableFares"
      :seats-to-book="seatsToBook"
      :passenger-form="passengerForm"
      :passenger-form-errors="passengerFormErrors"
      :processing="processing"
      v-model:ticketQuantity="ticketQuantity"
      v-model:showPassengerFields="showPassengerFields"
      @close="cancelBooking"
      @select-fare="selectFareForSeat"
      @confirm="confirmBooking"
    />

    <!-- Modal de création de voyage -->
    <div v-if="showCreateTripModal" class="fixed inset-0 z-[1000] flex h-full w-full items-center justify-center overflow-y-auto bg-slate-900/35 p-4 backdrop-blur-sm">
      <div class="relative w-full max-w-md overflow-hidden rounded-3xl border border-white/70 bg-white/95 dark:bg-slate-900 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.16)] dark:shadow-black/40">
        <div class="p-5">
          <h3 class="text-lg leading-6 font-semibold text-slate-900 dark:text-slate-100">Créer un nouveau voyage</h3>
          <form @submit.prevent="createTrip" class="mt-2 space-y-4">
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
                required
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

            <!-- Sales Control Toggle -->
            <div class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-4 border border-slate-200 dark:border-slate-800">
              <div class="flex items-center justify-between">
                <div>
                  <label for="sales_control" class="text-sm font-medium text-slate-900 dark:text-slate-100">
                    Ventes intermédiaires
                  </label>
                  <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ createTripForm.sales_control === 'open' 
                       ? '🔓 Les stations intermédiaires peuvent vendre' 
                       : '🔒 Seule la station d\'origine peut vendre' }}
                  </p>
                </div>
                <button
                  type="button"
                  @click="createTripForm.sales_control = createTripForm.sales_control === 'open' ? 'closed' : 'open'"
                  :class="[
                    'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2',
                    createTripForm.sales_control === 'open' ? 'bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800'
                  ]"
                >
                  <span
                    :class="[
                      'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                      createTripForm.sales_control === 'open' ? 'translate-x-5' : 'translate-x-0'
                    ]"
                  />
                </button>
              </div>
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
              {{ currentTrip?.display_name }} - {{ currentTrip?.vehicle?.identifier }}
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
              :key="'modal-' + (currentTrip?.id || 'default')"
              v-if="currentTrip?.vehicle?.vehicle_type"
              :vehicle-type="currentTrip.vehicle.vehicle_type"
              :seat-map="seatMap"
              :suggested-seats="suggestedSeats"
              :show-suggestions="ticketingStore.showSuggestions && !!selectedFare && suggestedSeats.length > 0"
              :selected-seat="selectedSeatNumber"
              :selected-color="selectedSeatColor"
              :sellable-seat-numbers="currentStationSellableSeatNumbers"
              :sellable-seat-border-color="currentStationSellableSeatBorderColor"
              :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role) || isSalesClosedForSeller"
              :vertical-mode="true"
              @seat-click="handleSeatClick"
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

    <!-- Trip Selection Modal -->
    <div v-if="showTripSelectionModal" class="fixed inset-0 z-[1020] flex h-full w-full items-center justify-center overflow-y-auto bg-slate-900/35 dark:bg-black/60 p-4 backdrop-blur-sm">
      <div class="relative flex w-full max-w-4xl max-h-[90vh] flex-col overflow-hidden rounded-3xl border border-white/70 dark:border-slate-800 bg-white/95 dark:bg-slate-900 shadow-[0_24px_70px_rgba(15,23,42,0.16)] transition-all">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-emerald-100 dark:border-slate-800 bg-emerald-50 dark:bg-emerald-950/30 px-6 py-4">
          <div>
            <h3 class="text-xl font-bold text-emerald-700 dark:text-emerald-300">Sélectionner un voyage</h3>
            <p class="text-sm text-emerald-600 dark:text-emerald-400">Choisissez le départ pour lequel vous vendez des billets</p>
          </div>
          <button @click="showTripSelectionModal = false" class="p-2 text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-350 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-full transition-colors">
            <Close class="w-6 h-6" />
          </button>
        </div>

        <!-- Destination Filter -->
        <div class="border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
          <div class="flex flex-col md:flex-row gap-3">
            <!-- Destination Filter -->
            <div class="relative flex-1">
              <select 
                v-model="selectedDestinationId"
              class="w-full pl-10 py-3 bg-slate-50 dark:bg-slate-950 border-0 focus:ring-2 focus:ring-emerald-500 rounded-xl text-sm transition-all font-bold text-slate-800 dark:text-slate-100 cursor-pointer"
              >
                <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Toutes les destinations</option>
                <option v-for="dest in destinations" :key="dest.id" :value="dest.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">{{ dest.name }}</option>
              </select>
              <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-emerald-600 dark:text-emerald-400 pointer-events-none">
                 <Routes class="w-5 h-5" />
              </div>
            </div>
            
            <!-- History Toggle -->

            <!-- History Toggle -->
            <button 
               v-if="['admin', 'supervisor', 'superadmin'].includes(page.props.auth.user.role)"
               @click="showHistory = !showHistory"
               :class="['px-4 py-3 rounded-xl border-2 transition-all flex items-center justify-center gap-2 font-bold text-sm shadow-sm', showHistory ? 'bg-slate-900 border-slate-900 text-white dark:bg-slate-100 dark:border-slate-100 dark:text-slate-900' : 'bg-white border-slate-200 text-gray-500 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-350 dark:hover:bg-slate-800']"
               :title="showHistory ? 'Masquer l\'historique' : 'Voir l\'historique (48h)'"
            >
               <History :size="20" />
               <span v-if="!isMobile">{{ showHistory ? 'Masquer historique' : 'Historique' }}</span>
            </button>
          </div>
        </div>

        <!-- Trip List -->
        <div class="flex-1 overflow-y-auto bg-slate-50 dark:bg-slate-950/20 p-4">
          <div v-if="filteredTrips.length > 0">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div v-for="trip in filteredTrips" :key="trip.id"
                   @click="selectTrip(trip.id)"
                   :class="[
                     'group p-4 rounded-xl border-2 cursor-pointer transition-all duration-200 bg-white dark:bg-slate-900 hover:shadow-lg',
                     selectedTripId === trip.id 
                       ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/20 shadow-md ring-4 ring-emerald-500/10' 
                       : 'border-transparent dark:border-slate-800 hover:border-emerald-200 dark:hover:border-slate-700'
                   ]">
                <div class="flex items-start justify-between mb-3">
                  <div class="bg-emerald-100 dark:bg-emerald-950/50 p-2 rounded-lg group-hover:bg-emerald-200 dark:group-hover:bg-emerald-900/30 transition-colors">
                    <Bus class="w-6 h-6 text-emerald-600 dark:text-emerald-400" />
                  </div>
                  <div v-if="selectedTripId === trip.id" class="bg-emerald-500 text-white p-1 rounded-full">
                    <Check class="w-4 h-4" />
                  </div>
                  <div v-else-if="new Date(trip.departure_at) < new Date()" class="bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-450 px-2 py-0.5 rounded text-[10px] font-bold uppercase">
                    Passé
                  </div>
                </div>
                
                <div class="font-bold text-gray-900 dark:text-slate-100 text-base mb-2 leading-tight">
                  {{ trip.display_name }}
                </div>
                
                <div class="space-y-2">
                  <div class="flex items-center text-sm text-gray-600 dark:text-slate-400">
                    <Clock class="w-4 h-4 mr-2 text-emerald-500 dark:text-emerald-450" />
                    <span class="capitalize">{{ new Date(trip.departure_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' }) }} - {{ new Date(trip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</span>
                  </div>
                  <div class="flex items-center text-sm text-gray-600 dark:text-slate-400">
                    <OfficeBuilding class="w-4 h-4 mr-2 text-emerald-500 dark:text-emerald-450" />
                    <span>Bus: {{ trip.vehicle?.identifier }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Pagination / Load More -->
            <div v-if="pagination?.next_page_url" class="mt-8 flex justify-center pb-4">
              <button 
                @click="loadMore"
                :disabled="loadingMore"
                class="px-8 py-3 bg-white dark:bg-slate-900 border-2 border-emerald-500 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 font-bold rounded-xl hover:bg-emerald-50 dark:hover:bg-slate-800 transition-all shadow-sm active:scale-95 disabled:opacity-50 flex items-center gap-2"
              >
                <Refresh v-if="loadingMore" class="animate-spin" />
                <span>{{ loadingMore ? 'Chargement...' : 'Afficher plus de voyages' }}</span>
              </button>
            </div>
          </div>
          <div v-else class="h-64 flex flex-col items-center justify-center text-gray-400 dark:text-slate-500">
            <Bus class="w-16 h-16 mb-4 opacity-20" />
            <p class="text-lg font-medium text-slate-900 dark:text-slate-300">Aucun voyage trouvé</p>
            <p class="text-sm text-slate-500 dark:text-slate-400">Essayez une autre recherche ou créez un nouveau voyage</p>
          </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end">
           <button 
             @click="showTripSelectionModal = false"
             class="px-6 py-2 bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-350 font-bold rounded-lg hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
           >
             Fermer
           </button>
        </div>
      </div>
    </div>

    <!-- Supervisor Inspection Modal -->
    <TicketInspectionModal
        :show="showInspectionModal"
        :validation="selectedTicketForInspection"
        @close="showInspectionModal = false"
        @approve="() => { /* No-op for inspection */ }"
        @decline="() => { showInspectionModal = false; }"
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
  background: transparent;
}

::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}
.dark ::-webkit-scrollbar-thumb {
  background: #475569;
}

::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
.dark ::-webkit-scrollbar-thumb:hover {
  background: #64748b;
}
</style>
