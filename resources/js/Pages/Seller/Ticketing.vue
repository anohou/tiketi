<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { toastStore } from '@/Stores/toastStore.js';
import { confirmationStore } from '@/Stores/confirmationStore.js';
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
import Eye from 'vue-material-design-icons/Eye.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue';
import TripDetailsModal from '@/Components/Seller/TripDetailsModal.vue';
import TripConnectionSummary from '@/Components/Seller/TripConnectionSummary.vue';
import Dropdown from '@/Components/Dropdown.vue';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import { useExportPrint } from '@/Composables/useExportPrint.js';
import { useTicketing } from '@/Composables/useTicketing.js';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
  trips: [Array, Object],
  routeFares: Array,
  connectionFares: { type: Array, default: () => [] },
  connectionRoutes: { type: Array, default: () => [] },
  routes: Array,
  vehicles: Array,
  hasActiveAssignment: Boolean,
  assignedStationIds: Array,
  assignedStationId: String,
  assignedStation: String,
  canSelectTripOrigin: { type: Boolean, default: false },
  originStations: { type: Array, default: () => [] },
  focusMode: { type: Boolean, default: false },
  okohiIntegrationActive: { type: Boolean, default: false },
  destinations: {
    type: Array,
    default: () => []
  },
  replicableTrips: {
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
  isEditingTrip,
  showTripDetailsModal,
  selectedDetailsTripId,
  openTripDetails,
  showPassengerModal,
  showDestinationModal,
  selectedSeatNumber,
  activeOkohiRequest,
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
  printQueue,
  printQueueRunning,
  retryPrint,
  printInBrowser,
  dismissPrintEntry,
  bookingSidePanelOpen,
  currentTrip,
  operationalStationId,
  isTripPassed,
  seatsToBook,
  canBookTickets,
  totalAmount,
  seatStats,
  getOccupancyRate,
  filteredTrips,
  availableFares,
  availableRouteOptions,
  availableDestinationOptions,
  buildTripStationIndices,
  getStationColor,
  currentStationSellableSeatNumbers,
  maxSellableQuantity,
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
  continueSalesAfterOkohiRequest,
  handleOkohiSuccess,
  createTrip,
  openCreateTrip,
  openEditTrip,
  applyReplicableTemplate,
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
    pendingOkohiRequestsForCurrentTrip,
    pendingOkohiCountdowns,
    openPendingOkohiModal,
    stopPendingOkohiPolling,
    page,
} = ticketing;

const assignedStationPalette = computed(() => getAssignedStationPalette());
const showPrintPool = ref(false);
const printPoolAttentionCount = computed(() => printQueue.value.filter(
  (entry) => ['pending', 'printing', 'ready', 'failed'].includes(entry.status)
).length);

const togglePrintPool = () => {
  showPrintPool.value = !showPrintPool.value;
};

watch(printPoolAttentionCount, (count) => {
  if (count === 0) {
    showPrintPool.value = false;
  }
});

const missingFareDestinations = computed(() => {
  if (!currentTrip.value) return [];

  const stationIndices = buildTripStationIndices(currentTrip.value);
  const originId = operationalStationId.value || currentTrip.value.origin_station_id;
  const originIndex = stationIndices[originId];
  if (originIndex === undefined) return [];

  const stations = new Map();
  const addStation = (station) => {
    if (station?.id) stations.set(station.id, station);
  };
  const routeObj = currentTrip.value.route || {};
  addStation(routeObj.origin_station || routeObj.originStation);
  [...(routeObj.route_stop_orders || routeObj.routeStopOrders || [])]
    .forEach((stop) => addStation(stop.station || stop));
  addStation(routeObj.destination_station || routeObj.destinationStation);
  addStation(currentTrip.value.origin_station || currentTrip.value.originStation);
  addStation(currentTrip.value.destination_station || currentTrip.value.destinationStation);

  const hasFare = (destinationId) => (props.routeFares || []).some((fare) => {
    const fromId = fare.from_station_id || fare.from_station?.id || fare.fromStation?.id;
    const toId = fare.to_station_id || fare.to_station?.id || fare.toStation?.id;

    return (fromId === originId && toId === destinationId)
      || (fare.is_bidirectional && toId === originId && fromId === destinationId);
  });

  return [...stations.values()]
    .filter((station) => stationIndices[station.id] > originIndex && !hasFare(station.id))
    .sort((a, b) => stationIndices[a.id] - stationIndices[b.id]);
});

const basinDestinations = computed(() => {
  const directFare = selectedFare.value;
  if (!directFare?.has_connections || directFare.is_connection) return [];

  const transferId = directFare.to_station_id;
  const originId = directFare.from_station_id || props.assignedStationId;
  const currentRouteId = currentTrip.value?.route_id || currentTrip.value?.route?.id;
  const connectionRoutes = (props.connectionRoutes || []).filter((route) => route.id !== currentRouteId);

  const destIds = new Set();
  connectionRoutes.forEach(route => {
    const stops = [...(route.route_stop_orders || route.routeStopOrders || [])]
      .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
    const stationIds = [
      route.origin_station_id,
      ...stops.map(stop => stop.station_id || stop.station?.id),
      route.destination_station_id,
    ].filter(Boolean);

    if (stationIds.includes(transferId)) {
      stationIds.forEach((stationId) => {
        if (stationId !== transferId) destIds.add(stationId);
      });
    }
  });

  const currentTripIndices = buildTripStationIndices(currentTrip.value);
  const tripDestinationIndex = currentTripIndices[currentTrip.value.destination_station_id];
  const transferIndex = currentTripIndices[transferId];

  const destinationsList = [];
  destIds.forEach(destId => {
    if (destId === originId) return;

    const servedByCurrentTrip = transferIndex !== undefined
      && currentTripIndices[destId] !== undefined
      && tripDestinationIndex !== undefined
      && transferIndex < currentTripIndices[destId]
      && currentTripIndices[destId] <= tripDestinationIndex;

    if (servedByCurrentTrip) return;

    let stationObj = null;
    connectionRoutes.forEach(r => {
      if (r.origin_station_id === destId && r.originStation) stationObj = r.originStation;
      if (r.destination_station_id === destId && r.destinationStation) stationObj = r.destinationStation;
      const stops = r.route_stop_orders || r.routeStopOrders || [];
      stops.forEach(s => {
        const st = s.station || s;
        if (st && st.id === destId) stationObj = st;
      });
    });

    if (!stationObj) return;

    let price = null;
    let details = '';
    let globalFare = props.connectionFares.find(fare =>
      (fare.from_station_id === originId && fare.to_station_id === destId) ||
      (fare.is_bidirectional && fare.to_station_id === originId && fare.from_station_id === destId)
    );

    if (globalFare) {
      price = globalFare.amount;
      details = 'Tarif global direct';
    } else {
      let firstSegment = props.routeFares.find(fare =>
        (fare.from_station_id === originId && fare.to_station_id === transferId) ||
        (fare.is_bidirectional && fare.to_station_id === originId && fare.from_station_id === transferId)
      );
      let secondSegment = props.connectionFares.find(fare =>
        (fare.from_station_id === transferId && fare.to_station_id === destId) ||
        (fare.is_bidirectional && fare.to_station_id === transferId && fare.from_station_id === destId)
      );

      if (firstSegment && secondSegment) {
        price = firstSegment.amount + secondSegment.amount;
        details = `Somme des segments (${firstSegment.amount} F + ${secondSegment.amount} F)`;
      } else {
        details = 'Tarif manquant (non disponible)';
      }
    }

    const matchingRoute = connectionRoutes.find((route) => {
      const stops = [...(route.route_stop_orders || route.routeStopOrders || [])]
        .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
      const stationIds = [
        route.origin_station_id,
        ...stops.map(stop => stop.station_id || stop.station?.id),
        route.destination_station_id,
      ].filter(Boolean);
      const routeTransferIndex = stationIds.indexOf(transferId);
      const routeDestinationIndex = stationIds.indexOf(destId);

      return routeTransferIndex !== -1
        && routeDestinationIndex !== -1
        && routeTransferIndex !== routeDestinationIndex;
    });

    destinationsList.push({
      station: stationObj,
      price,
      details,
      route: matchingRoute
    });
  });

  return destinationsList;
});

const handleFareClick = (fare) => {
  if (isTripPassed.value || isFareDisabled(fare)) return;
  selectedFare.value = fare;
};

const tripDetailsModalTab = ref('overview');

const openTripDetailsWithOverview = (tripId) => {
  tripDetailsModalTab.value = 'overview';
  openTripDetails(tripId);
};

const openTripTransitPool = (tripId) => {
  tripDetailsModalTab.value = 'transit';
  openTripDetails(tripId);
};

const updatingTripStatusId = ref(null);
const updateTripStatus = async (tripId, status) => {
  const trip = trips.value.find((candidate) => candidate.id === tripId);
  const assignedIds = props.assignedStationIds?.length
    ? props.assignedStationIds
    : (props.assignedStationId ? [props.assignedStationId] : []);
  const statusStationId = assignedIds.some(id => String(id) === String(trip?.active_sales_station_id))
    ? trip.active_sales_station_id
    : operationalStationId.value;
  const statusStationName = props.originStations?.find(station => station.id === statusStationId)?.name
    || props.assignedStation
    || 'la gare qui a actuellement la main';
  let confirmMessage = "";
  if (status === 'boarding') {
    confirmMessage = t('ticketing.dashboard.confirm_boarding');
  } else if (status === 'departed') {
    confirmMessage = trip?.status === 'departed'
      ? `${t('ticketing.dashboard.confirm_departure_from')} ${statusStationName}${t('ticketing.dashboard.handover_next_station')}`
      : `${t('ticketing.dashboard.confirm_departure_from')} ${statusStationName}${t('ticketing.dashboard.en_route_next_station')}`;
  } else if (status === 'delayed') {
    confirmMessage = t('ticketing.dashboard.confirm_delayed');
  } else if (status === 'cancelled') {
    confirmMessage = t('ticketing.dashboard.confirm_cancelled');
  } else if (status === 'arrived') {
    confirmMessage = t('ticketing.dashboard.confirm_arrived');
  }

  if (confirmMessage && !await confirmationStore.confirm({
    title: status === 'cancelled' ? t('ticketing.dashboard.cancel_trip') : t('ticketing.dashboard.update_trip'),
    message: confirmMessage,
    confirmLabel: status === 'cancelled' ? t('ticketing.dashboard.cancel_trip') : t('common.confirm'),
    tone: status === 'cancelled' ? 'danger' : 'warning',
  })) {
    return;
  }

  updatingTripStatusId.value = tripId;
  try {
    await axios.patch(route('seller.trips.status', { trip: tripId }), {
      status,
      station_id: status === 'departed' ? statusStationId : undefined,
    });
    toastStore.success(t('ticketing.dashboard.trip_status_updated'));
    router.reload({ preserveScroll: true });
  } catch (error) {
    console.error('Erreur lors de la mise à jour du statut:', error);
    toastStore.error(error.response?.data?.message || t('ticketing.dashboard.status_update_error'));
  } finally {
    updatingTripStatusId.value = null;
  }
};

const canEditStatus = (trip) => {
  if (!trip) return false;
  const isStaff = ['admin', 'supervisor', 'superadmin', 'super_admin', 'executive'].includes(page.props.auth.user.role);
  if (isStaff) {
    return !['arrived'].includes(trip.status);
  }

  // A seller can change the operational status only for the station that has
  // the current hand-off. Simultaneous ticket sales do not grant that right.
  const assignedIds = props.assignedStationIds?.length
    ? props.assignedStationIds
    : (props.assignedStationId ? [props.assignedStationId] : []);
  if (!assignedIds.some(stationId => String(stationId) === String(trip.active_sales_station_id))) {
    return false;
  }

  return !['arrived', 'cancelled'].includes(trip.status);
};

// Aliases and reactive states specific to the vertical paginated layout
const trips = tripsRef;
const showHistory = ref(false);
const isMobile = ref(window.innerWidth < 768);
const showTripSelectionModal = ref(false);
const getTripStationPhase = (trip) => {
  if (!trip) return 'unknown';
  if (trip.status === 'cancelled') return 'cancelled';
  if (trip.status === 'arrived') return 'arrived';
  if (trip.status === 'boarding') return 'boarding';
  if (trip.status === 'delayed') return 'delayed';

  if (trip.status === 'departed') {
    const assignedIds = props.assignedStationIds?.length
      ? props.assignedStationIds
      : (props.assignedStationId ? [props.assignedStationId] : []);
    if (assignedIds.length === 0) return 'en_route';

    const stationIndices = buildTripStationIndices(trip);
    const currentStationId = assignedIds.some(id => String(id) === String(trip.active_sales_station_id))
      ? trip.active_sales_station_id
      : assignedIds
        .filter(id => stationIndices[id] !== undefined)
        .sort((a, b) => stationIndices[a] - stationIndices[b])[0];
    const currentStationIndex = stationIndices[currentStationId];
    const activeStationIndex = stationIndices[trip.active_sales_station_id];

    if (currentStationIndex === undefined || activeStationIndex === undefined) {
      return 'en_route';
    }
    if (currentStationIndex < activeStationIndex) return 'departed_from_station';
    if (currentStationIndex === activeStationIndex) return 'en_route_to_station';

    return 'upcoming_station';
  }

  return new Date(trip.departure_at) < new Date() ? 'past' : 'scheduled';
};

const isTripPastForDisplay = (trip) => ['past', 'arrived', 'cancelled', 'departed_from_station'].includes(getTripStationPhase(trip));

const getTripStationStatusLabel = (trip) => {
  const phase = getTripStationPhase(trip);
  const labels = {
    cancelled: t('ticketing.dashboard.status_cancelled'),
    arrived: t('ticketing.dashboard.status_arrived'),
    boarding: t('ticketing.dashboard.status_boarding'),
    delayed: t('ticketing.dashboard.status_delayed'),
    en_route: t('ticketing.dashboard.status_en_route'),
    en_route_to_station: t('ticketing.dashboard.status_en_route_to_station'),
    departed_from_station: t('ticketing.dashboard.status_departed_from_station'),
    upcoming_station: t('ticketing.dashboard.status_upcoming'),
    past: t('ticketing.dashboard.status_past_trip'),
    scheduled: t('ticketing.dashboard.status_in_progress'),
  };

  return labels[phase] || 'En cours';
};

const getTripStationStatusClass = (trip) => {
  const phase = getTripStationPhase(trip);
  if (phase === 'cancelled') return 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300';
  if (phase === 'delayed') return 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300';
  if (phase === 'boarding') return 'bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-300';
  if (['en_route', 'en_route_to_station'].includes(phase)) return 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300';
  if (['departed_from_station', 'past', 'arrived'].includes(phase)) return 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300';
  if (phase === 'upcoming_station') return 'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300';

  return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300';
};

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

const fullscreenNavigationInProgress = ref(false);

const getFullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

const navigateTicketingFocusMode = (enabled) => {
  const query = currentTrip.value?.id ? { trip_id: currentTrip.value.id } : {};
  const normalRoute = page.props.auth.user.role === 'supervisor'
    ? 'supervisor.ticketing'
    : 'seller.ticketing';

  router.get(route(enabled ? 'seller.ticketing.focus' : normalRoute, query));
};

const toggleTicketingFocusMode = async () => {
  const shouldExit = props.focusMode || Boolean(getFullscreenElement());
  fullscreenNavigationInProgress.value = true;

  try {
    if (shouldExit) {
      const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
      if (getFullscreenElement() && exitFullscreen) {
        await exitFullscreen.call(document);
      }
      navigateTicketingFocusMode(false);
      return;
    }

    const root = document.documentElement;
    const requestFullscreen = root.requestFullscreen || root.webkitRequestFullscreen;

    if (!requestFullscreen) {
      toastStore.error(t('ticketing.dashboard.fullscreen_not_supported'));
      return;
    }

    await requestFullscreen.call(root);
    navigateTicketingFocusMode(true);
  } catch (error) {
    console.error('Impossible d’activer le plein écran de la billetterie.', error);
    toastStore.error(t('ticketing.dashboard.fullscreen_denied'));
  } finally {
    window.setTimeout(() => {
      fullscreenNavigationInProgress.value = false;
    }, 500);
  }
};

const handleTicketingFullscreenChange = () => {
  if (!getFullscreenElement() && props.focusMode && !fullscreenNavigationInProgress.value) {
    navigateTicketingFocusMode(false);
  }
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
      toastStore.warning(t('ticketing.dashboard.no_ticket_export'));
    }
  } catch (error) {
    console.error('Erreur export CSV:', error);
    toastStore.error(t('ticketing.dashboard.export_csv_error'));
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
    toastStore.error(t('ticketing.dashboard.export_pdf_error'));
  } finally {
    exportPdfLoadingTripId.value = null;
  }
};

const selectTripFromModal = (tripId) => {
  showTripSelectionModal.value = false;
  selectTrip(tripId);

  if (isMobile.value) {
    scrollToSeats();
  }
};

const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    })
}

const formatCountdown = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
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

const expandedTripId = ref(null);

const toggleTripDetails = (trip) => {
  if (expandedTripId.value === trip.id) {
    expandedTripId.value = null;
    return;
  }

  expandedTripId.value = trip.id;
  if (selectedTripId.value !== trip.id) {
    selectTrip(trip.id);
  }
};

const getAirportStatus = (trip) => {
  if (trip.status === 'cancelled') {
    return { 
      label: t('ticketing.dashboard.status_upper_cancelled'), 
      color: 'text-rose-600 bg-rose-50 border border-rose-200 dark:text-rose-450 dark:bg-rose-950/30 dark:border-rose-900/50' 
    };
  }
  if (trip.status === 'delayed') {
    return { 
      label: t('ticketing.dashboard.status_upper_delayed'), 
      color: 'text-amber-605 bg-amber-50 border border-amber-200 dark:text-amber-400 dark:bg-amber-950/30 dark:border-amber-800/50 animate-pulse' 
    };
  }
  if (trip.status === 'boarding') {
    return { 
      label: t('ticketing.dashboard.status_upper_boarding'), 
      color: 'text-orange-600 bg-orange-50 border border-orange-200 dark:text-orange-405 dark:bg-orange-950/30 dark:border-orange-850/50 font-black animate-pulse' 
    };
  }
  if (trip.status === 'arrived') {
    return {
      label: t('ticketing.dashboard.status_upper_arrived'),
      color: 'text-slate-600 bg-slate-50 border border-slate-200 dark:text-slate-500 dark:bg-slate-900/40 dark:border-slate-800/50'
    };
  }
  if (trip.status === 'departed') {
    const phase = getTripStationPhase(trip);
    if (phase === 'departed_from_station') {
      return {
        label: t('ticketing.dashboard.status_upper_departed_from_station'),
        color: 'text-slate-600 bg-slate-50 border border-slate-200 dark:text-slate-400 dark:bg-slate-900/40 dark:border-slate-800/50'
      };
    }
    if (phase === 'upcoming_station') {
      return {
        label: t('ticketing.dashboard.status_upper_upcoming'),
        color: 'text-sky-700 bg-sky-50 border border-sky-200 dark:text-sky-300 dark:bg-sky-950/30 dark:border-sky-800/50'
      };
    }

    return {
      label: phase === 'en_route_to_station' ? t('ticketing.dashboard.status_upper_en_route_to_station') : t('ticketing.dashboard.status_upper_en_route'),
      color: 'text-blue-700 bg-blue-50 border border-blue-200 dark:text-blue-300 dark:bg-blue-950/30 dark:border-blue-800/50'
    };
  }
  if (trip.available_seats <= 0) {
    return { 
      label: t('ticketing.dashboard.status_upper_full'), 
      color: 'text-red-600 bg-red-50 border border-red-200 dark:text-red-400 dark:bg-red-950/30 dark:border-red-900/50 font-bold' 
    };
  }
  return { 
    label: t('ticketing.dashboard.status_upper_on_time'), 
    color: 'text-emerald-600 bg-emerald-50 border border-emerald-250 dark:text-emerald-400 dark:bg-emerald-950/30 dark:border-emerald-800/50' 
  };
}

const orderedTrips = computed(() => {
  return [...filteredTrips.value].sort((a, b) => {
    const aPast = isTripPastForDisplay(a);
    const bPast = isTripPastForDisplay(b);
    const aDeparture = new Date(a.departure_at).getTime();
    const bDeparture = new Date(b.departure_at).getTime();

    if (aPast !== bPast) {
      return aPast ? 1 : -1;
    }

    // Upcoming trips: nearest first. Past trips: most recently passed first.
    return aPast ? bDeparture - aDeparture : aDeparture - bDeparture;
  });
});

const sortedTripsForModal = computed(() => orderedTrips.value);

onMounted(() => {
  document.addEventListener('fullscreenchange', handleTicketingFullscreenChange);
  document.addEventListener('webkitfullscreenchange', handleTicketingFullscreenChange);
  window.addEventListener('resize', () => {
    isMobile.value = window.innerWidth < 768;
  });
});

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', handleTicketingFullscreenChange);
  document.removeEventListener('webkitfullscreenchange', handleTicketingFullscreenChange);
});
</script>

<template>
  <MainNavLayout :show-nav="!isMobile" :full-height="true" :focus-mode="focusMode">
    <div class="flex-1 flex flex-col gap-4 min-h-0 bg-slate-50/70 dark:bg-slate-950 p-4 md:p-6 lg:p-8">
          
          <!-- Full-page blocking message if no station assigned (for sellers and supervisors) -->
          <div v-if="['seller', 'supervisor'].includes($page.props.auth.user.role) && !hasActiveAssignment" 
               class="flex-1 flex items-center justify-center">
            <div class="bg-white border border-slate-200 p-12 rounded-3xl flex flex-col items-center text-center shadow-sm max-w-lg dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
              <div class="p-5 bg-emerald-50 rounded-full shadow-sm mb-6 dark:bg-emerald-900/25">
                <OfficeBuilding class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
              </div>
              <h2 class="text-2xl font-black text-gray-900 mb-3 dark:text-slate-100">{{ $t('ticketing.dashboard.no_assigned_station') }}</h2>
              <p class="text-gray-600 mb-6 leading-relaxed dark:text-slate-400">
                {{ $t('ticketing.dashboard.no_assigned_station_desc') }}
              </p>
              <div class="space-y-3 w-full">
                <p class="text-sm text-gray-500 dark:text-slate-500">
                  {{ $t('ticketing.dashboard.contact_admin_assign') }}
                </p>
                <Link 
                  :href="route('profile.edit')" 
                  class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                >
                  {{ $t('ticketing.dashboard.view_profile') }}
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
                  <h1 class="text-3xl font-black text-gray-900 tracking-tight dark:text-slate-100">{{ $t('ticketing.dashboard.ticketing_title') }}</h1>
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
                      {{ assignedStation || $t('ticketing.dashboard.all_stations') }}
                  </div>
                </div>
                <p class="text-gray-500 font-medium dark:text-slate-400">{{ $t('ticketing.dashboard.realtime_sales') }}</p>
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
                  @click="openCreateTrip"
                  class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-xl font-bold shadow-lg shadow-emerald-600/20 transition-all active:scale-95 flex-shrink-0"
                >
                  <Plus :size="20" />
                  <span>{{ $t('ticketing.dashboard.new_trip') }}</span>
                </button>
                <button
                  type="button"
                  @click="toggleTicketingFocusMode"
                  class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 md:h-12 md:w-12 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:hover:border-emerald-700 dark:hover:bg-emerald-950/30 dark:hover:text-emerald-300"
                  :title="focusMode ? 'Quitter le plein écran' : 'Afficher la billetterie en plein écran'"
                  :aria-label="focusMode ? 'Quitter le plein écran' : 'Afficher la billetterie en plein écran'"
                >
                  <Close v-if="focusMode" :size="22" aria-hidden="true" />
                  <svg v-else viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3" />
                    <path d="M16 3h3a2 2 0 0 1 2 2v3" />
                    <path d="M8 21H5a2 2 0 0 1-2-2v-3" />
                    <path d="M16 21h3a2 2 0 0 0 2-2v-3" />
                  </svg>
                </button>
                <button 
                  @click="toggleBluetoothPrinter" 
                  :class="[
                    'h-10 w-10 md:h-12 md:w-12 border rounded-full flex items-center justify-center transition-all flex-shrink-0 shadow-sm',
                    useBluetoothPrinter && bluetoothPrinterConnected 
                      ? 'border-emerald-500 bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' 
                      : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-750'
                  ]"
                  :title="bluetoothPrinterConnected ? `Connecté: ${bluetoothPrinterName}` : 'Connecter imprimante Bluetooth'"
                >
                  <Bluetooth :class="bluetoothPrinterConnected ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400'" :size="20" />
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
                        {{ $t('ticketing.dashboard.trips') }}
                      </h2>
                      <!-- Badges on Mobile -->
                      <div class="flex items-center gap-2 md:hidden">
                        <span class="px-2 py-0.5 bg-emerald-600 text-white rounded-full text-xs font-black shadow-sm dark:bg-emerald-500">
                          {{ trips.length }} {{ $t('ticketing.dashboard.in_progress') }}
                        </span>
                      </div>
                    </div>
                    
                    <!-- Destination Filter + Changer on Mobile & Desktop -->
                    <div class="flex items-center gap-2 w-full md:w-auto">
                       <select v-model="selectedDestinationId" class="flex-1 md:w-48 border-slate-200 text-slate-700 rounded-lg text-sm px-3 py-1.5 focus:border-emerald-500 focus:ring-emerald-500 bg-white shadow-sm font-semibold dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                          <option value="">
                          {{ $t('ticketing.dashboard.all_destinations') }}
                          </option>
                          <option v-for="dest in destinations" :key="dest.id" :value="dest.id">{{ dest.name }}</option>
                       </select>
  
                       <!-- History Toggle -->
  
                       <!-- History Toggle -->
                       <button 
                         v-if="['admin', 'supervisor', 'superadmin', 'super_admin'].includes(page.props.auth.user.role)"
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
                         <span>
                         {{ $t('ticketing.dashboard.all_trips') }}
                       </span>
                       </button>
                     </div>
                   </div>
                   
                   <div class="hidden md:flex items-center gap-2 shrink-0">
                     <span class="px-2.5 py-1 bg-emerald-600 text-white rounded-full text-sm font-black shadow-sm">
                       {{ trips.length }} {{ $t('ticketing.dashboard.in_progress') }}
                        </span>
                   </div>
                 </div>
                 <div class="flex-1 p-3 overflow-y-auto">
                   <!-- Mobile: Show only selected trip -->
                   <div v-if="isMobile && currentTrip" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-3 shadow-sm relative overflow-visible">
                     <div class="flex items-start justify-between mb-2">
                       <div class="flex-1 min-w-0">
                         <div class="flex items-center gap-2 mb-1">
                           <div :class="['w-2 h-2 rounded-full shrink-0', currentTrip.status === 'cancelled' ? 'bg-rose-500' : (isTripPastForDisplay(currentTrip) ? 'bg-gray-400' : 'bg-emerald-500 animate-pulse')]"></div>
                            <div :class="['text-[10px] uppercase font-bold tracking-wider', currentTrip.status === 'cancelled' ? 'text-rose-600 dark:text-rose-400' : (currentTrip.status === 'delayed' ? 'text-amber-600 dark:text-amber-400' : (currentTrip.status === 'boarding' ? 'text-orange-600 dark:text-orange-400' : (isTripPastForDisplay(currentTrip) ? 'text-gray-500' : 'text-emerald-600 dark:text-emerald-400')))]">
                              {{ getTripStationStatusLabel(currentTrip) }}
                            </div>
                         </div>
                         <div class="flex items-center gap-2 flex-wrap">
                           <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-black tracking-wider">
                             {{ currentTrip.code || $t('ticketing.dashboard.pending_code') }}
                           </span>
                           <span v-if="currentTrip.allows_open_connections" class="inline-flex items-center px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950/40 text-violet-750 dark:text-violet-300 text-[10px] font-black tracking-wider uppercase">
                             {{ $t('ticketing.dashboard.connections') }}
                            </span>
                           <span v-else class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-350 text-[10px] font-black tracking-wider uppercase">
                             {{ $t('ticketing.dashboard.direct') }}
                            </span>
                           <span class="text-xs font-semibold text-gray-500 dark:text-slate-400">{{ $t('ticketing.dashboard.journey') }}</span>
                         </div>
                         <div class="text-base font-black text-gray-900 dark:text-slate-100 leading-tight whitespace-normal break-words">
                           {{ currentTrip.display_name }}
                         </div>
                       </div>
                         <div class="text-right shrink-0 ml-3 flex flex-col items-end gap-2">
                           <div class="flex items-center gap-1.5">
                             <button
                               v-if="printPoolAttentionCount > 0"
                               type="button"
                               class="relative rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-400 dark:hover:bg-emerald-950/30 dark:hover:text-emerald-300"
                               :class="showPrintPool ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : ''"
                               :title="$t('ticketing.dashboard.show_print_pool')"
                               :aria-label="$t('ticketing.dashboard.show_print_pool')"
                               :aria-expanded="showPrintPool"
                               @click.stop="togglePrintPool"
                             >
                               <Printer :size="20" />
                               <span
                                 v-if="printPoolAttentionCount > 0"
                                 class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-black text-white"
                               >
                                 {{ printPoolAttentionCount > 9 ? '9+' : printPoolAttentionCount }}
                               </span>
                             </button>
                             <div @click.stop class="relative">
                               <Dropdown align="right" width="48">
                                 <template #trigger>
                                   <button class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-lg transition-colors" title="Actions">
                                     <svg viewBox="0 0 24 24" class="w-5 h-5" aria-hidden="true">
                                       <path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2s-2 .9-2 2s.9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2s2-.9 2-2s-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2s2-.9 2-2s-.9-2-2-2z" />
                                     </svg>
                                   </button>
                                 </template>
                                 <template #content>
                                   <div class="py-1">
                                     <button
                                       v-if="!['departed', 'arrived', 'cancelled'].includes(currentTrip.status)"
                                       @click="openEditTrip(currentTrip)"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Pencil :size="16" class="text-sky-600 dark:text-sky-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.edit_trip') }}</span>
                                     </button>
                                     <button
                                       @click="openTripDetailsWithOverview(currentTrip.id)"
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Eye :size="16" class="text-blue-600 dark:text-blue-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.details_tickets') }}</span>
                                     </button>
                                     <button
                                       v-if="currentTrip?.has_connections"
                                       @click="openTripTransitPool(currentTrip.id)"
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Routes :size="16" class="text-violet-600 dark:text-violet-400 shrink-0" />
                                       <span>
                             {{ $t('ticketing.dashboard.connections') }}
                            </span>
                                     </button>
                                     <button
                                       @click="exportTicketsToExcel(currentTrip.id)"
                                       :disabled="exportExcelLoadingTripId === currentTrip.id"
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left disabled:opacity-50"
                                     >
                                       <FileExcel :size="16" class="text-emerald-600 dark:text-emerald-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.export_excel') }}</span>
                                     </button>
                                     <button
                                       @click="exportTicketsToPdf(currentTrip.id)"
                                       :disabled="exportPdfLoadingTripId === currentTrip.id"
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left disabled:opacity-50"
                                     >
                                       <FilePdfBox :size="16" class="text-rose-600 dark:text-rose-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.export_pdf') }}</span>
                                     </button>
                                   </div>
                                 </template>
                               </Dropdown>
                             </div>
                           </div>
                           <div>
                          <div class="text-xl font-black text-slate-900 dark:text-slate-100">
                               {{ new Date(currentTrip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}
                           </div>
                           <div class="text-[10px] text-slate-500 dark:text-slate-400">{{ currentTrip.vehicle?.identifier }}</div>
                         </div>
                       </div>
                     </div>
                     <TripConnectionSummary :summary="currentTrip.connection_summary" :is-past="isTripPastForDisplay(currentTrip)" @manage-connections="openTripTransitPool(currentTrip.id)" />
                      <!-- Status actions / Seat Stats Row -->
                      <div v-if="canEditStatus(currentTrip)" class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button
                          v-if="['scheduled', 'delayed'].includes(currentTrip.status)"
                          @click.stop="updateTripStatus(currentTrip.id, 'boarding')"
                          :disabled="updatingTripStatusId === currentTrip.id"
                          class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                        >
                           {{ $t('ticketing.dashboard.status_boarding') }}
                          </button>
                        <button
                          v-if="['scheduled', 'boarding', 'delayed', 'departed'].includes(currentTrip.status)"
                          @click.stop="updateTripStatus(currentTrip.id, 'departed')"
                          :disabled="updatingTripStatusId === currentTrip.id"
                          class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                        >
                           {{ $t('ticketing.dashboard.declare_departure') }}
                          </button>
                        <button
                          v-if="['scheduled', 'boarding'].includes(currentTrip.status)"
                          @click.stop="updateTripStatus(currentTrip.id, 'delayed')"
                          :disabled="updatingTripStatusId === currentTrip.id"
                          class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                        >
                           {{ $t('ticketing.dashboard.status_delayed') }}
                          </button>
                        <button
                          v-if="['scheduled', 'boarding', 'delayed', 'departed'].includes(currentTrip.status)"
                          @click.stop="updateTripStatus(currentTrip.id, 'cancelled')"
                          :disabled="updatingTripStatusId === currentTrip.id"
                          class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-rose-600 hover:bg-rose-700 dark:bg-rose-800 dark:hover:bg-rose-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                        >
                           {{ $t('ticketing.dashboard.status_cancelled') }}
                          </button>
                        <button
                          v-if="['departed'].includes(currentTrip.status) && ['admin', 'supervisor', 'superadmin', 'super_admin', 'executive'].includes($page.props.auth.user.role)"
                          @click.stop="updateTripStatus(currentTrip.id, 'arrived')"
                          :disabled="updatingTripStatusId === currentTrip.id"
                          class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                        >
                           {{ $t('ticketing.dashboard.status_arrived') }}
                          </button>
                      </div>
                      <div v-else-if="seatStats.total > 0" class="flex items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-rose-50 dark:bg-rose-950/30 rounded-lg">
                          <span class="text-lg font-black text-rose-600 dark:text-rose-450">{{ seatStats.available }}</span>
                          <span class="text-[10px] text-rose-600 dark:text-rose-400 font-medium">{{ $t('ticketing.dashboard.remaining') }}</span>
                        </div>
                        <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-emerald-50 dark:bg-emerald-950/30 rounded-lg">
                          <span class="text-lg font-black text-emerald-600 dark:text-emerald-450">{{ seatStats.total }}</span>
                          <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">{{ $t('ticketing.dashboard.total') }}</span>
                        </div>
                        <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-slate-50 dark:bg-slate-800 rounded-lg">
                          <span class="text-lg font-black text-slate-700 dark:text-slate-300">{{ getOccupancyRate(seatStats.available, seatStats.total) }}%</span>
                          <span class="text-[10px] text-slate-600 dark:text-slate-400 font-medium">{{ $t('ticketing.dashboard.occupancy_rate') }}</span>
                        </div>
                      </div>
                   </div>
 
                   <!-- Desktop: Show all trips with highlighted selected -->
                   <div v-if="!isMobile && orderedTrips.length > 0" class="space-y-2">
                     <template
                       v-for="(trip, index) in orderedTrips"
                       :key="trip.id"
                     >
                       <div
                         v-if="isTripPastForDisplay(trip) && (index === 0 || !isTripPastForDisplay(orderedTrips[index - 1]))"
                         class="flex items-center gap-3 px-2 pb-1 pt-4"
                       >
                         <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 dark:text-slate-500">
                           {{ $t('ticketing.dashboard.past_trips') }}
                          </span>
                         <span class="h-px flex-1 bg-slate-200 dark:bg-slate-800"></span>
                         <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                           {{ orderedTrips.filter(isTripPastForDisplay).length }}
                         </span>
                       </div>
                     <div
                       @click="toggleTripDetails(trip)"
                       @keydown.enter.prevent="toggleTripDetails(trip)"
                       @keydown.space.prevent="toggleTripDetails(trip)"
                       draggable="true"
                       role="button"
                       tabindex="0"
                       :aria-expanded="expandedTripId === trip.id"
                       @dragstart="dragStart($event, index)"
                       @dragover.prevent
                       @dragenter="dragEnter($event, index)"
                       @dragend="dragEnd"
                       @drop="dragDrop($event, index)"
                       :class="[
                         'relative rounded-2xl cursor-pointer transition-all duration-300 border-2 overflow-visible focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2',
                         selectedTripId === trip.id
                           ? 'bg-white border-emerald-500 dark:bg-slate-900 dark:border-emerald-700 shadow-md'
                           : ticketingStore.tripHighlights?.[trip.id]
                             ? 'bg-amber-50 border-amber-400 dark:bg-amber-950/20 dark:border-amber-800 shadow-lg shadow-amber-200/30 dark:shadow-amber-950/20'
                           : 'bg-white border-slate-200 dark:bg-slate-900 dark:border-slate-800 hover:border-emerald-300 dark:hover:border-emerald-800 hover:shadow-sm',
                         dragOverIndex === index ? 'border-dashed border-emerald-500 dark:border-emerald-600 bg-emerald-100/30 dark:bg-emerald-950/30 scale-[1.01]' : ''
                       ]"
                     >
                       <div class="flex items-center gap-4 p-3.5 md:p-4">
                         <div :class="[
                           'flex w-[78px] shrink-0 flex-col items-center justify-center rounded-xl border px-2 py-2.5',
                           isTripPastForDisplay(trip)
                             ? 'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-400'
                             : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                         ]">
                           <span class="text-2xl font-black leading-none tracking-tight tabular-nums">{{ formatTime(trip.departure_at) }}</span>
                           <span class="mt-1 text-[9px] font-bold uppercase tracking-wide opacity-70">
                             {{ new Date(trip.departure_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' }) }}
                           </span>
                         </div>

                         <div class="min-w-0 flex-1">
                           <div class="flex min-w-0 items-center gap-2">
                             <div v-if="selectedTripId === trip.id" :class="['h-2 w-2 shrink-0 rounded-full', isTripPastForDisplay(trip) ? 'bg-slate-400' : 'bg-emerald-500 animate-pulse']"></div>
                             <div v-else-if="ticketingStore.tripHighlights?.[trip.id]" class="h-2 w-2 shrink-0 rounded-full bg-amber-500 animate-pulse"></div>
                             <p :class="['min-w-0 truncate text-lg font-black leading-tight tracking-tight md:text-xl', isTripPastForDisplay(trip) ? 'text-slate-500' : 'text-slate-900 dark:text-white']">
                               {{ parseRouteName(trip).destination }}
                             </p>
                             <span
                               :title="trip.sales_control === 'open' ? 'Ventes simultanées autorisées' : 'Ventes à la gare d\'origine uniquement'"
                               class="shrink-0 text-sm"
                               aria-label="Mode de vente"
                             >{{ trip.sales_control === 'open' ? '🔓' : '🔒' }}</span>
                             <ChevronDown
                               :size="20"
                               :class="['shrink-0 text-slate-400 transition-transform duration-200 dark:text-slate-500', expandedTripId === trip.id ? 'rotate-180 text-emerald-600 dark:text-emerald-400' : '']"
                               aria-hidden="true"
                             />
                           </div>
                           <p class="mt-1 truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                             {{ $t('ticketing.dashboard.departure') }} {{ parseRouteName(trip).origin }}
                           </p>
                           <div class="mt-2 flex flex-wrap items-center gap-1.5">
                             <span :class="['rounded-full px-2 py-0.5 text-[9px] font-black uppercase', getTripStationStatusClass(trip)]">
                               {{ getTripStationStatusLabel(trip) }}
                             </span>
                             <span v-if="trip.allows_open_connections" class="rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-black uppercase text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">{{ $t('ticketing.dashboard.connections') }}</span>
                             <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-black uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                             {{ $t('ticketing.dashboard.direct') }}
                            </span>
                           </div>
                         </div>
                         <div class="ml-auto flex shrink-0 items-center gap-1">
                           <div class="flex items-center gap-1.5">
                             <button
                               v-if="selectedTripId === trip.id && printPoolAttentionCount > 0"
                               type="button"
                               class="relative rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-400 dark:hover:bg-emerald-950/30 dark:hover:text-emerald-300"
                               :class="showPrintPool ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : ''"
                               :title="$t('ticketing.dashboard.show_print_pool')"
                               :aria-label="$t('ticketing.dashboard.show_print_pool')"
                               :aria-expanded="showPrintPool"
                               @click.stop="togglePrintPool"
                               @dragstart.stop.prevent
                             >
                               <Printer :size="20" />
                               <span
                                 v-if="printPoolAttentionCount > 0"
                                 class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-black text-white"
                               >
                                 {{ printPoolAttentionCount > 9 ? '9+' : printPoolAttentionCount }}
                               </span>
                             </button>
                             <div @click.stop class="relative">
                               <Dropdown align="right" width="48">
                                 <template #trigger>
                                   <button class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-lg transition-colors" title="Actions">
                                     <svg viewBox="0 0 24 24" class="w-5 h-5" aria-hidden="true">
                                       <path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2s-2 .9-2 2s.9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2s2-.9 2-2s-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2s2-.9 2-2s-.9-2-2-2z" />
                                     </svg>
                                   </button>
                                 </template>
                                 <template #content>
                                   <div class="py-1">
                                     <button
                                       v-if="!['departed', 'arrived', 'cancelled'].includes(trip.status)"
                                       @click="openEditTrip(trip)"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Pencil :size="16" class="text-sky-600 dark:text-sky-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.edit_trip') }}</span>
                                     </button>
                                     <button
                                       @click="openTripDetailsWithOverview(trip.id)"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Eye :size="16" class="text-blue-600 dark:text-blue-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.details_tickets') }}</span>
                                     </button>
                                     <button
                                       v-if="trip.has_connections"
                                       @click="openTripTransitPool(trip.id)"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left"
                                     >
                                       <Routes :size="16" class="text-violet-600 dark:text-violet-400 shrink-0" />
                                       <span>
                             {{ $t('ticketing.dashboard.connections') }}
                            </span>
                                     </button>
                                     <button
                                       @click="exportTicketsToExcel(trip.id)"
                                       :disabled="exportExcelLoadingTripId === trip.id"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left disabled:opacity-50"
                                     >
                                       <FileExcel :size="16" class="text-emerald-600 dark:text-emerald-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.export_excel') }}</span>
                                     </button>
                                     <button
                                       @click="exportTicketsToPdf(trip.id)"
                                       :disabled="exportPdfLoadingTripId === trip.id"
                                       @dragstart.stop.prevent
                                       class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors text-left disabled:opacity-50"
                                     >
                                       <FilePdfBox :size="16" class="text-rose-600 dark:text-rose-400 shrink-0" />
                                       <span>{{ $t('ticketing.dashboard.export_pdf') }}</span>
                                     </button>
                                   </div>
                                 </template>
                               </Dropdown>
                             </div>
                           </div>
                         </div>
                       </div>
                       <div v-if="expandedTripId === trip.id" class="border-t border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-950/40" @click.stop>
                         <div class="mb-3 flex flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                           <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">{{ trip.code || $t('ticketing.dashboard.pending_code') }}</span>
                           <span>{{ trip.vehicle?.identifier || $t('ticketing.dashboard.unassigned_vehicle') }}</span>
                           <span v-if="trip.vehicle?.vehicle_type?.name">• {{ trip.vehicle.vehicle_type.name }}</span>
                           <span :title="trip.sales_control === 'open' ? 'Ventes simultanées autorisées' : 'Ventes origine uniquement'">
                             {{ trip.sales_control === 'open' ? $t('ticketing.dashboard.sales_simultaneous') : $t('ticketing.dashboard.sales_origin_only') }}
                           </span>
                         </div>

                         <TripConnectionSummary :summary="trip.connection_summary" :is-past="isTripPastForDisplay(trip)" @manage-connections="openTripTransitPool(trip.id)" />
                       <!-- Status actions / Seat Stats Row -->
                       <div v-if="selectedTripId === trip.id && canEditStatus(trip)" class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-dashed border-emerald-200 dark:border-emerald-800/80">
                         <button
                           v-if="['scheduled', 'delayed'].includes(trip.status)"
                           @click.stop="updateTripStatus(trip.id, 'boarding')"
                           :disabled="updatingTripStatusId === trip.id"
                           class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                         >
                           {{ $t('ticketing.dashboard.status_boarding') }}
                          </button>
                         <button
                           v-if="['scheduled', 'boarding', 'delayed', 'departed'].includes(trip.status)"
                           @click.stop="updateTripStatus(trip.id, 'departed')"
                           :disabled="updatingTripStatusId === trip.id"
                           class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                         >
                           {{ $t('ticketing.dashboard.declare_departure') }}
                          </button>
                         <button
                           v-if="['scheduled', 'boarding'].includes(trip.status)"
                           @click.stop="updateTripStatus(trip.id, 'delayed')"
                           :disabled="updatingTripStatusId === trip.id"
                           class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                         >
                           {{ $t('ticketing.dashboard.status_delayed') }}
                          </button>
                         <button
                           v-if="['scheduled', 'boarding', 'delayed', 'departed'].includes(trip.status)"
                           @click.stop="updateTripStatus(trip.id, 'cancelled')"
                           :disabled="updatingTripStatusId === trip.id"
                           class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-rose-600 hover:bg-rose-700 dark:bg-rose-800 dark:hover:bg-rose-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                         >
                           {{ $t('ticketing.dashboard.status_cancelled') }}
                          </button>
                         <button
                          v-if="['departed'].includes(trip.status) && ['admin', 'supervisor', 'superadmin', 'super_admin', 'executive'].includes($page.props.auth.user.role)"
                           @click.stop="updateTripStatus(trip.id, 'arrived')"
                           :disabled="updatingTripStatusId === trip.id"
                           class="flex-1 min-w-[80px] text-center px-2 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-bold text-[10px] rounded-lg transition-all shadow-sm"
                         >
                           {{ $t('ticketing.dashboard.status_arrived') }}
                          </button>
                       </div>
                       <div v-else class="flex items-center gap-3 mt-4 pt-4 border-t border-dashed" :class="selectedTripId === trip.id ? 'border-emerald-200 dark:border-emerald-800/80' : 'border-slate-200 dark:border-slate-800/40'">
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">{{ $t('ticketing.dashboard.remaining_upper') }}</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-rose-600 dark:text-rose-400">{{ trip.available_seats || 0 }}</span>
                                 <span class="text-[9px] text-rose-600/70 dark:text-rose-400/70 mb-0.5 font-bold uppercase">{{ $t('ticketing.dashboard.free_seats') }}</span>
                             </div>
                         </div>
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">{{ $t('ticketing.dashboard.total_upper') }}</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-slate-700 dark:text-slate-300">{{ trip.total_seats || 0 }}</span>
                                 <span class="text-[9px] text-slate-500 dark:text-slate-400 mb-0.5 font-bold uppercase">{{ $t('ticketing.dashboard.capacity') }}</span>
                             </div>
                         </div>
                         <div class="flex-1 bg-white dark:bg-slate-950/45 rounded-xl p-2 border border-slate-200 dark:border-slate-800 shadow-sm">
                             <div class="text-[9px] text-slate-400 dark:text-slate-500 font-bold uppercase tracking-tighter">{{ $t('ticketing.dashboard.occupancy_rate') }}</div>
                             <div class="flex items-end gap-1">
                                 <span class="text-base font-black text-slate-700 dark:text-slate-300">{{ getOccupancyRate(trip.available_seats || 0, trip.total_seats || 0) }}%</span>
                                 <span class="text-[9px] text-slate-500/70 dark:text-slate-400/70 mb-0.5 font-bold uppercase">Occ</span>
                             </div>
                         </div>
                       </div>
                       </div>
                     </div>
                     </template>
                   </div>
 
                   <!-- No trip selected / No trips -->
                   <div v-if="isMobile && !currentTrip" class="h-full flex flex-col items-center justify-center bg-slate-50 dark:bg-slate-950/20 rounded-xl border-2 border-dashed border-slate-200 dark:border-slate-800 py-10">
                     <div class="bg-white dark:bg-slate-900 p-6 rounded-full shadow-md mb-6 relative">
                        <Bus class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
                        <div class="absolute -top-2 -right-2 bg-rose-500 text-white text-xs font-bold px-2 py-1 rounded-full border-2 border-white dark:border-slate-800 shadow-sm">
                          {{ trips.length }}
                        </div>
                     </div>
                     <h3 class="text-2xl font-black text-gray-900 dark:text-slate-100 mb-2">{{ trips.length }} voyages {{ $t('ticketing.dashboard.in_progress') }}
                        </h3>
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
                 <div class="flex-1 overflow-y-auto min-h-0">
                   <div class="p-2">
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
                          @click="handleFareClick(fare)"
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
                             {{ (fare.sale_destination || fare.to_station)?.name }}
                           </div>
                           <div class="flex items-center gap-2 text-[10px] font-medium" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">
                             <span v-if="fare.is_connection" class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                               <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
                               <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white/40"></span>
                             </span>
                             <template v-if="fare.is_connection">Correspondance à {{ fare.transfer_station?.name }}</template>
                             <template v-else>→ depuis {{ fare.from_station?.name?.split(' - ')[1] || fare.from_station?.name }}</template>
                           </div>
                           <div class="flex items-center gap-2 mt-1">
                             <span v-if="fare.has_connections" class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[9px] font-bold bg-white/20 text-white border border-white/10 uppercase tracking-wider">
                               🔗 Correspondance
                             </span>
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
                     <div
                       v-if="availableFares.length === 0 && !isTripPassed"
                       class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 text-left shadow-sm dark:border-amber-800 dark:bg-amber-950/30"
                     >
                       <div class="flex items-start gap-3">
                         <span class="mt-0.5 text-xl" aria-hidden="true">⚠️</span>
                         <div class="min-w-0">
                           <p class="text-sm font-black text-amber-900 dark:text-amber-200">
                             Vente impossible depuis {{ assignedStation || 'cette gare' }}
                           </p>
                           <p class="mt-1 text-xs leading-relaxed text-amber-800 dark:text-amber-300">
                             Aucun tarif n’est configuré vers les destinations restantes de ce trajet. Les sièges ne peuvent pas être sélectionnés tant que ces tarifs manquent.
                           </p>
                           <div v-if="missingFareDestinations.length" class="mt-3 flex flex-wrap gap-1.5">
                             <span
                               v-for="destination in missingFareDestinations"
                               :key="destination.id"
                               class="rounded-full border border-amber-300 bg-white px-2.5 py-1 text-[10px] font-black text-amber-800 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200"
                             >
                               {{ assignedStation || 'Cette gare' }} → {{ destination.name }}
                             </span>
                           </div>
                           <p class="mt-3 rounded-xl bg-amber-100 px-3 py-2 text-xs font-bold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                             Demandez à votre superviseur d’ajouter les tarifs correspondants.
                           </p>
                         </div>
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

                   <!-- Trip Summary / Sold Tickets Quick Panel -->
                   <div v-if="currentTrip" class="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/20 flex flex-col gap-3">
                     <div class="flex items-center justify-between text-xs font-bold text-slate-500 dark:text-slate-400">
                       <span>Remplissage :</span>
                               <span class="text-slate-800 dark:text-slate-200">
                         {{ seatStats.soldTickets }} / {{ seatStats.total }} Sièges ({{ getOccupancyRate(seatStats.available, seatStats.total) }}%)
                       </span>
                     </div>
                     <div class="w-full bg-slate-250 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
                       <div class="bg-emerald-500 h-full rounded-full transition-all duration-300" :style="{ width: `${getOccupancyRate(seatStats.available, seatStats.total)}%` }"></div>
                     </div>
                     <div class="flex gap-2 w-full">
                               <button 
                         @click="openTripDetailsWithOverview(currentTrip.id)"
                         class="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-slate-900 hover:bg-slate-850 dark:bg-slate-800 dark:hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition-all active:scale-95 shadow-sm"
                       >
                         <Eye :size="16" />
                         <span>Détails</span>
                       </button>
                                               <button 
                          v-if="currentTrip?.has_connections && !['departed', 'arrived', 'cancelled'].includes(currentTrip.status)"
                          @click="openTripTransitPool(currentTrip.id)"
                          class="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-bold transition-all active:scale-95 shadow-sm"
                        >
                          <Routes :size="16" />
                          <span>
                             {{ $t('ticketing.dashboard.connections') }}
                            </span>
                        </button>
                      </div>

                      <!-- Pending Okohi Rewards Widget -->
                      <div v-if="pendingOkohiRequestsForCurrentTrip.length > 0" class="mt-2 p-3 bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-2xl space-y-2">
                        <div class="text-[10px] font-black uppercase tracking-wider text-amber-800 dark:text-amber-300 flex items-center justify-between">
                          <span class="flex items-center gap-1">
                            <span>
                            🎁 {{ pendingOkohiRequestsForCurrentTrip.length }} {{ $t('ticketing.dashboard.pending_okohi') }}
                          </span>
                          </span>
                        </div>
                        <div v-for="req in pendingOkohiRequestsForCurrentTrip" :key="req.id" class="flex items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-xl border border-amber-200/60 dark:border-amber-800/40 text-xs shadow-sm">
                          <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                              <span class="font-black text-amber-700 dark:text-amber-400">{{ $t('ticketing.dashboard.seat') }} {{ req.seat_number }}</span>
                              <span class="text-slate-500 text-[10px] font-semibold">({{ req.customer_number }})</span>
                            </div>
                            <div class="flex items-center gap-1.5 mt-1">
                              <Clock :size="12" class="text-amber-500 shrink-0" />
                              <span class="font-mono text-[10px] font-bold" :class="(pendingOkohiCountdowns[req.id] || 0) < 30 ? 'text-red-500 animate-pulse' : 'text-amber-600'">
                                {{ formatCountdown(pendingOkohiCountdowns[req.id] || 0) }}
                              </span>
                              <div class="flex-1 max-w-[60px] bg-amber-100 dark:bg-amber-900/40 rounded-full h-1.5">
                                <div class="bg-amber-500 h-1.5 rounded-full transition-all duration-1000" :style="{ width: `${Math.min(100, ((pendingOkohiCountdowns[req.id] || 0) / 300) * 100)}%` }"></div>
                              </div>
                            </div>
                          </div>
                          <button
                            @click="openPendingOkohiModal(req)"
                            class="shrink-0 px-2.5 py-1 bg-amber-500 hover:bg-amber-600 active:scale-95 text-white font-bold text-[10px] rounded-lg shadow-sm transition-all flex items-center gap-1"
                          >
                            <span>
                            {{ $t('common.manage') }}
                          </span>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <!-- Mobile Seat Map inline -->
                 <div id="mobile-seat-map" v-if="seatMap && currentTrip?.vehicle?.vehicle_type" class="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/20 flex flex-col items-center overflow-x-hidden md:hidden">
                   <h3 class="text-sm font-bold text-slate-700 dark:text-slate-250 mb-8 w-full flex items-center justify-center gap-2">
                      <Bus class="w-5 h-5 text-emerald-600 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-slate-800 rounded p-0.5 shadow-sm" />
                      {{ $t('ticketing.dashboard.front_of_bus') }}
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
                       :disabled="availableFares.length === 0"
                       :sellable-seat-numbers="currentStationSellableSeatNumbers"
                       :sellable-seat-border-color="currentStationSellableSeatBorderColor"
                       :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role) || isSalesClosedForSeller"
                       @seat-click="handleSeatClick"
                       class="w-full h-auto"
                       />
                     </div>
                   </div>
                   
                   <div class="mt-8 text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest flex items-center gap-2">
                      {{ $t('ticketing.dashboard.back_of_bus') }}
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
      :initial-okohi-request="activeOkohiRequest"
      :selected-fare="selectedFare"
      :available-fares="availableFares"
      :connection-options="basinDestinations"
      :seats-to-book="seatsToBook"
      :max-sellable-quantity="maxSellableQuantity"
      :seat-first-flow="seatFirstFlow"
      :passenger-form="passengerForm"
      :passenger-form-errors="passengerFormErrors"
      :processing="processing"
      :okohi-integration-active="okohiIntegrationActive"
      v-model:ticketQuantity="ticketQuantity"
      v-model:showPassengerFields="showPassengerFields"
      v-model:finalDestinationStationId="finalDestinationStationId"
      v-model:connectionRouteId="connectionRouteId"
      @close="cancelBooking"
      @continue-sales="continueSalesAfterOkohiRequest"
      @select-fare="selectFareForSeat"
      @confirm="confirmBooking"
      @okohi-success="handleOkohiSuccess"
    />

    <!-- Modal de création de voyage -->
    <div v-if="showCreateTripModal" class="fixed inset-0 z-[1000] flex h-full w-full items-center justify-center overflow-y-auto bg-slate-900/35 p-4 backdrop-blur-sm">
      <div class="relative w-full max-w-2xl overflow-hidden rounded-3xl border border-white/70 bg-white/95 dark:bg-slate-900 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.16)] dark:shadow-black/40">
        <div class="p-5">
          <h3 class="text-lg leading-6 font-semibold text-slate-900 dark:text-slate-100">{{ isEditingTrip ? $t('ticketing.dashboard.edit_trip') : $t('ticketing.dashboard.create_new_trip') }}</h3>
          <form @submit.prevent="createTrip" class="mt-2 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div v-if="!isEditingTrip && props.replicableTrips && props.replicableTrips.length > 0" class="md:col-span-2">
                <InputLabel for="template_select" :value="$t('ticketing.dashboard.select_recurring_template')" />
                <select
                  id="template_select"
                  v-model="selectedTemplate"
                  class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                >
                  <option :value="null">{{ $t('ticketing.dashboard.custom_trip') }}</option>
                  <option v-for="t in props.replicableTrips" :key="t.id" :value="t">
                    {{ getRouteName(t.route_id) }} (Départ : {{ t.time }})
                  </option>
                </select>
              </div>

              <div>
                <InputLabel for="origin_station_display" :value="$t('ticketing.dashboard.origin_station')" />
                <select
                  v-if="props.canSelectTripOrigin"
                  id="origin_station_display"
                  v-model="createTripForm.origin_station_id"
                  class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                  required
                  :disabled="isEditingTrip"
                >
                  <option value="">{{ $t('ticketing.dashboard.select_origin_station') }}</option>
                  <option v-for="station in props.originStations" :key="station.id" :value="station.id">
                    {{ station.name }}
                  </option>
                </select>
                <div v-else class="mt-1 block w-full px-3 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-500 dark:text-slate-400 text-sm font-semibold select-none">
                  {{ props.assignedStation || $t('ticketing.dashboard.all_stations') }}
                </div>
                <InputError class="mt-2" :message="createTripErrors.origin_station_id" />
              </div>

              <div>
                <InputLabel for="route_id" :value="$t('ticketing.dashboard.route_line')" />
                <select
                  id="route_id"
                  v-model="createTripForm.route_id"
                  class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                  required
                >
                  <option value="">{{ $t('ticketing.dashboard.select_route_line') }}</option>
                  <option v-for="opt in availableRouteOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
                <InputError class="mt-2" :message="createTripErrors.route_id" />
              </div>

              <div>
                <InputLabel for="destination_station_id" :value="$t('ticketing.dashboard.arrival_station')" />
                <select
                  id="destination_station_id"
                  v-model="createTripForm.destination_station_id"
                  class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                  required
                  :disabled="!createTripForm.route_id"
                >
                  <option value="">{{ $t('ticketing.dashboard.select_destination') }}</option>
                  <option v-for="opt in availableDestinationOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
                <InputError class="mt-2" :message="createTripErrors.destination_station_id" />
              </div>

              <div>
                <InputLabel for="vehicle_id" :value="$t('ticketing.dashboard.vehicle')" />
                <select
                  id="vehicle_id"
                  v-model="createTripForm.vehicle_id"
                  class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                >
                  <option value="">{{ $t('ticketing.dashboard.select_vehicle') }}</option>
                  <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">
                    {{ vehicle.identifier }} ({{ vehicle.seat_count }} places)
                  </option>
                </select>
                <InputError class="mt-2" :message="createTripErrors.vehicle_id" />
              </div>

              <div>
                <InputLabel for="departure_at" :value="$t('ticketing.dashboard.departure_time')" />
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
                <InputLabel for="code" :value="$t('ticketing.dashboard.trip_code_number')" />
                <TextInput
                  id="code"
                  v-model="createTripForm.code"
                  type="text"
                  class="mt-1 block w-full rounded-lg border-slate-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-slate-950 dark:text-slate-100"
                  :placeholder="$t('ticketing.dashboard.auto_generated_code')"
                />
                <InputError class="mt-2" :message="createTripErrors.code" />
              </div>

              <!-- Sales Control Toggle -->
              <div class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-4 border border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                  <div>
                    <label for="sales_control" class="text-sm font-medium text-slate-900 dark:text-slate-100">
                      {{ $t('ticketing.dashboard.simultaneous_sales') }}
                    </label>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      {{ createTripForm.sales_control === 'open'
                         ? '🔓 Les stations intermédiaires peuvent vendre simultanément'
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

              <div class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-4 border border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between gap-4">
                  <div>
                    <label class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $t('ticketing.dashboard.open_connections') }}</label>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      {{ $t('ticketing.dashboard.open_connections_desc') }}
                    </p>
                  </div>
                  <button type="button" @click="createTripForm.allows_open_connections = !createTripForm.allows_open_connections"
                    :class="['relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors', createTripForm.allows_open_connections ? 'bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800']">
                    <span :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition', createTripForm.allows_open_connections ? 'translate-x-5' : 'translate-x-0']" />
                  </button>
                </div>
              </div>

              <div v-if="createTripForm.allows_open_connections" class="md:col-span-2">
                <InputLabel for="trip_auto_allocation" :value="$t('ticketing.dashboard.auto_allocate_connections')" />
                <select id="trip_auto_allocation" v-model="createTripForm.automatic_connection_allocation" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100">
                  <option :value="null">{{ $t('ticketing.dashboard.inherit_route_company') }}</option>
                  <option :value="true">{{ $t('ticketing.dashboard.enable_for_trip') }}</option>
                  <option :value="false">{{ $t('ticketing.dashboard.disable_for_trip') }}</option>
                </select>
              </div>

              <div v-if="['admin', 'supervisor'].includes($page.props.auth.user.role)" class="bg-slate-55 dark:bg-slate-950/40 rounded-lg p-4 border border-slate-200 dark:border-slate-800 md:col-span-2">
                <div class="flex items-center justify-between gap-4">
                  <div>
                    <label class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $t('ticketing.dashboard.replicable_trip') }}</label>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                      {{ $t('ticketing.dashboard.replicable_trip_desc') }}
                    </p>
                  </div>
                  <button type="button" @click="createTripForm.is_replicable = !createTripForm.is_replicable"
                    :class="['relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors', createTripForm.is_replicable ? 'bg-emerald-600' : 'bg-slate-200 dark:bg-slate-800']">
                    <span :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition', createTripForm.is_replicable ? 'translate-x-5' : 'translate-x-0']" />
                  </button>
                </div>
              </div>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4">
              <button
                type="button"
                @click="showCreateTripModal = false"
                class="px-4 py-2 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-700 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                type="submit"
                :disabled="createTripProcessing"
                class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50"
                >
                {{ createTripProcessing ? (isEditingTrip ? $t('common.modifying') : $t('common.creating')) : (isEditingTrip ? $t('common.save') : $t('common.create')) }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Trip Selection Modal -->
    <div v-if="showTripSelectionModal" class="fixed inset-0 z-[1020] flex h-full w-full items-center justify-center overflow-y-auto bg-slate-900/35 dark:bg-black/60 p-4 backdrop-blur-sm">
      <div class="relative flex w-full max-w-5xl max-h-[90vh] flex-col overflow-hidden rounded-3xl border border-white/70 dark:border-slate-800 bg-white/95 dark:bg-slate-900 shadow-[0_24px_70px_rgba(15,23,42,0.16)] transition-all">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-emerald-100 dark:border-slate-800 bg-emerald-50 dark:bg-emerald-950/30 px-6 py-4">
          <div>
            <h3 class="text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ $t('ticketing.dashboard.select_a_trip') }}</h3>
            <p class="text-sm text-emerald-600 dark:text-emerald-400">{{ $t('ticketing.dashboard.choose_departure') }}</p>
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
                <option value="" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                          {{ $t('ticketing.dashboard.all_destinations') }}
                          </option>
                <option v-for="dest in destinations" :key="dest.id" :value="dest.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">{{ dest.name }}</option>
              </select>
              <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-emerald-600 dark:text-emerald-400 pointer-events-none">
                 <Routes class="w-5 h-5" />
              </div>
            </div>
            
            <!-- History Toggle -->

            <!-- History Toggle -->
            <button 
                         v-if="['admin', 'supervisor', 'superadmin', 'super_admin'].includes(page.props.auth.user.role)"
               @click="showHistory = !showHistory"
               :class="['px-4 py-3 rounded-xl border-2 transition-all flex items-center justify-center gap-2 font-bold text-sm shadow-sm', showHistory ? 'bg-slate-900 border-slate-900 text-white dark:bg-slate-100 dark:border-slate-100 dark:text-slate-900' : 'bg-white border-slate-200 text-gray-500 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-350 dark:hover:bg-slate-800']"
               :title="showHistory ? 'Masquer l\'historique' : 'Voir l\'historique (48h)'"
            >
               <History :size="20" />
               <span v-if="!isMobile">{{ showHistory ? $t('ticketing.dashboard.hide_history_short') : $t('ticketing.dashboard.history') }}</span>
            </button>
          </div>
        </div>

        <!-- Trip List (FIDS style) -->
        <div class="flex-1 overflow-y-auto bg-white dark:bg-slate-950">
          <div v-if="filteredTrips.length > 0">
            <!-- Desktop Table Headers -->
            <div class="hidden md:grid grid-cols-12 gap-4 px-6 py-3 bg-slate-50 dark:bg-slate-950/80 border-b border-slate-100 dark:border-slate-900 text-[10px] font-mono text-slate-400 dark:text-slate-500 uppercase tracking-wider">
               <div class="col-span-1">{{ $t('ticketing.dashboard.time') }}</div>
               <div class="col-span-2">{{ $t('ticketing.dashboard.trip_code') }}</div>
               <div class="col-span-4">{{ $t('ticketing.dashboard.destination') }}</div>
               <div class="col-span-2">{{ $t('ticketing.dashboard.vehicle') }}</div>
               <div class="col-span-1 text-center">{{ $t('ticketing.dashboard.seats_header') }}</div>
               <div class="col-span-2">{{ $t('ticketing.dashboard.status') }}</div>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-slate-900 bg-white dark:bg-slate-950">
              <template v-for="(trip, index) in sortedTripsForModal" :key="trip.id">
                <div
                  v-if="isTripPastForDisplay(trip) && (index === 0 || !isTripPastForDisplay(sortedTripsForModal[index - 1]))"
                  class="flex items-center gap-3 border-y border-slate-200 bg-slate-50 px-6 py-2 dark:border-slate-800 dark:bg-slate-900/70"
                >
                  <span class="text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
                           {{ $t('ticketing.dashboard.past_trips') }}
                          </span>
                  <span class="h-px flex-1 bg-slate-200 dark:bg-slate-700"></span>
                  <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-black text-slate-500 shadow-sm dark:bg-slate-800 dark:text-slate-300">
                    {{ sortedTripsForModal.filter(isTripPastForDisplay).length }}
                  </span>
                </div>
              <div
                   @click="selectTripFromModal(trip.id)"
                   class="group transition-all duration-200 cursor-pointer border-l-4"
                   :class="[
                     selectedTripId === trip.id 
                       ? 'bg-emerald-500/5 dark:bg-emerald-950/20 border-l-emerald-500 shadow-inner' 
                       : 'hover:bg-slate-50/60 dark:hover:bg-slate-900/40 border-l-transparent'
                   ]">
                <!-- Desktop Row layout -->
                <div class="hidden md:grid grid-cols-12 gap-4 items-center px-6 py-4">
                   <!-- HEURE & DATE -->
                   <div class="col-span-1 flex flex-col">
                     <span class="font-mono text-base font-bold text-slate-900 dark:text-slate-100 tracking-wider">{{ formatTime(trip.departure_at) }}</span>
                     <span class="text-[10px] font-mono text-slate-400 dark:text-slate-500">{{ formatDate(trip.departure_at) }}</span>
                   </div>
                   <!-- CODE VOYAGE -->
                   <div class="col-span-2 flex flex-col gap-1 items-start">
                     <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-305 text-[10px] font-black tracking-wider uppercase border border-emerald-100 dark:border-emerald-900/30">
                       {{ trip.code || $t('ticketing.dashboard.pending_code') }}
                     </span>
                     <span v-if="trip.allows_open_connections" class="inline-flex items-center px-1.5 py-0.5 rounded bg-violet-100 dark:bg-violet-950/40 text-violet-750 dark:text-violet-300 text-[8px] font-black tracking-wider uppercase">
                       Correspondance
                     </span>
                     <span v-else class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-350 text-[8px] font-black tracking-wider uppercase">
                             {{ $t('ticketing.dashboard.direct') }}
                            </span>
                   </div>
                   <!-- DESTINATION -->
                   <div class="col-span-4 flex flex-col justify-center min-w-0 py-1">
                      <span class="text-sm font-black text-slate-800 dark:text-slate-200 tracking-wide uppercase leading-tight">
                         {{ parseRouteName(trip).destination }}
                      </span>
                      <span class="text-[10px] text-slate-500 dark:text-slate-400 font-semibold uppercase mt-0.5 flex flex-wrap items-center gap-1">
                         <span class="text-slate-400 font-normal">{{ $t('ticketing.dashboard.from') }}</span>
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
                           <span class="text-slate-400 font-normal">{{ $t('ticketing.dashboard.from') }}</span>
                           <span class="text-slate-600 dark:text-slate-350">{{ parseRouteName(trip).origin }}</span>
                         </span>
                         <span class="text-[9px] font-mono text-amber-600 dark:text-amber-500/80 uppercase mt-1 leading-none">
                            {{ trip.code || $t('ticketing.dashboard.pending_code') }} • {{ trip.vehicle?.identifier || 'N/A' }} <span class="text-slate-455 dark:text-slate-605 font-sans lowercase">({{ trip.vehicle?.vehicle_type?.name }})</span>
                            <span v-if="trip.allows_open_connections" class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded bg-violet-100 dark:bg-violet-950/40 text-violet-700 dark:text-violet-300 text-[8px] font-black tracking-wider uppercase">
                               {{ $t('ticketing.dashboard.corresp') }}
                            </span>
                            <span v-else class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-350 text-[8px] font-black tracking-wider uppercase">
                             {{ $t('ticketing.dashboard.direct') }}
                            </span>
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
                           <span class="font-bold text-slate-700 dark:text-slate-205">{{ trip.available_seats }}</span>/{{ trip.total_seats }} <span class="text-[9px] text-slate-455 dark:text-slate-605 font-sans font-medium">LIB</span>
                         </span>
                      </div>
	                      <ChevronRight :size="18" class="text-slate-400 dark:text-slate-500" />
		                </div>
		              </div>
                </div>
              </template>
		            </div>

            <!-- Pagination / Load More -->
            <div v-if="pagination?.next_page_url" class="py-6 flex justify-center bg-white dark:bg-slate-950 border-t border-slate-100 dark:border-slate-900">
              <button 
                @click="loadMore"
                :disabled="loadingMore"
                class="px-8 py-2.5 bg-slate-100 dark:bg-slate-900 hover:bg-slate-200 dark:hover:bg-slate-850 border border-slate-200 dark:border-slate-800 text-slate-705 dark:text-amber-400 font-mono text-xs font-bold rounded-xl uppercase tracking-wider transition-all shadow-sm active:scale-95 disabled:opacity-50 flex items-center gap-2"
              >
                <Refresh v-if="loadingMore" class="animate-spin" />
                <span>{{ loadingMore ? $t('common.loading') : $t('ticketing.dashboard.load_more_trips') }}</span>
              </button>
            </div>
          </div>
          <div v-else class="h-64 flex flex-col items-center justify-center text-slate-400 dark:text-slate-600 bg-white dark:bg-slate-950">
            <Bus class="w-16 h-16 mb-4 opacity-20 text-slate-405 dark:text-slate-800" />
            <p class="text-base font-bold uppercase tracking-widest text-slate-405 dark:text-slate-550">{{ $t('ticketing.dashboard.no_trips_found') }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-600 mt-1">{{ $t('ticketing.dashboard.try_another_dest') }}</p>
          </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end">
           <button 
             @click="showTripSelectionModal = false"
             class="px-6 py-2 bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-slate-350 font-bold rounded-lg hover:bg-gray-200 dark:hover:bg-slate-700 transition-colors"
           >
             {{ $t('common.close') }}
           </button>
        </div>
      </div>
    </div>

    <aside
      v-if="showPrintPool"
      class="fixed bottom-4 right-4 z-[1100] w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white/95 shadow-2xl backdrop-blur dark:border-slate-700 dark:bg-slate-900/95"
      aria-live="polite"
    >
      <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
        <div class="flex items-center gap-2">
          <Printer :size="19" class="text-emerald-600" />
          <span class="text-sm font-black text-slate-900 dark:text-white">{{ $t('ticketing.dashboard.prints') }}</span>
        </div>
        <div class="flex items-center gap-2">
          <span v-if="printQueueRunning" class="text-[11px] font-bold text-amber-600">{{ $t('common.in_progress') }}</span>
          <button
            type="button"
            class="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
            :aria-label="$t('ticketing.dashboard.close_print_pool')"
            @click="showPrintPool = false"
          >
            <Close :size="17" />
          </button>
        </div>
      </div>
      <div v-if="printQueue.length === 0" class="p-6 text-center text-sm font-semibold text-slate-500 dark:text-slate-400">
        {{ $t('ticketing.dashboard.no_print_pending') }}
      </div>
      <div v-else class="max-h-64 space-y-2 overflow-y-auto p-3">
        <div
          v-for="entry in printQueue"
          :key="entry.id"
          class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-950/60"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-xs font-black text-slate-800 dark:text-slate-100">
                Ticket {{ entry.ticketNumber || '—' }}
              </p>
              <p class="mt-0.5 text-[11px] font-semibold"
                 :class="entry.status === 'failed' ? 'text-rose-600' : entry.status === 'printed' ? 'text-emerald-600' : 'text-slate-500'">
                <template v-if="entry.status === 'pending'">{{ $t('ticketing.dashboard.bluetooth_pending') }}</template>
                <template v-else-if="entry.status === 'printing'">{{ $t('ticketing.dashboard.bluetooth_printing') }}</template>
                <template v-else-if="entry.status === 'printed'">{{ $t('ticketing.dashboard.print_started') }}</template>
                <template v-else-if="entry.status === 'ready'">{{ $t('ticketing.dashboard.ready_browser_print') }}</template>
                <template v-else>{{ $t('ticketing.dashboard.failed') }} {{ entry.error || $t('ticketing.dashboard.printer_unavailable') }}</template>
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700 dark:hover:bg-slate-800"
              :aria-label="$t('ticketing.dashboard.hide_print')"
              @click="dismissPrintEntry(entry.id)"
            >
              <DeleteOutline :size="16" />
            </button>
          </div>
          <div v-if="['ready', 'failed'].includes(entry.status)" class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-lg bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-slate-700 dark:bg-slate-700"
              @click="printInBrowser(entry.id)"
            >
              {{ $t('ticketing.dashboard.print_in_browser') }}
            </button>
            <button
              v-if="entry.status === 'failed' && useBluetoothPrinter"
              type="button"
              class="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
              @click="retryPrint(entry.id)"
            >
              <Refresh :size="13" /> {{ $t('ticketing.dashboard.retry_bluetooth') }}
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- Supervisor Inspection Modal -->
    <TicketInspectionModal
        :show="showInspectionModal"
        :validation="selectedTicketForInspection"
        @close="showInspectionModal = false"
        @approve="() => { /* No-op for inspection */ }"
        @decline="() => { showInspectionModal = false; }"
    />

    <!-- Trip Details & Sold Tickets Modal -->
    <TripDetailsModal
      :visible="showTripDetailsModal"
      :trip-id="selectedDetailsTripId"
      :assigned-station="assignedStation"
      :assigned-station-id="operationalStationId || assignedStationId"
      :current-user-role="page.props.auth.user.role"
      :current-user-id="page.props.auth.user.id"
      :initial-tab="tripDetailsModalTab"
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
