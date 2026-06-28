<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
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
import axios from 'axios';
import BluetoothPrinter from '@/Services/BluetoothPrinter.js';
import { ticketingStore } from '@/Stores/ticketingStore.js';

const props = defineProps({
  trips: Array,
  routeFares: Array,
  routes: Array,
  vehicles: Array,
  hasActiveAssignment: Boolean,
  assignedStation: String,
  destinations: { type: Array, default: () => [] },
});

// Get page props for auth user
const page = usePage();

// State
const trips = ref([...props.trips]);
const selectedTripId = ref(null);
const selectedFare = ref(null);
const ticketQuantity = ref(1);
const selectedDestinationId = ref('');
const seatMap = ref(null);
const seatMapLoading = ref(false);
const suggestedSeats = ref([]);
const bookingType = ref(null);
const occupancyStats = ref(null);
const processing = ref(false);
const errors = ref({});
const showCreateTripModal = ref(false);
const createTripForm = ref({
  route_id: '',
  vehicle_id: '',
  departure_at: '',
  status: 'scheduled',
  sales_control: 'closed',
});
const createTripErrors = ref({});
const createTripProcessing = ref(false);
const showZoomModal = ref(false);
const autoSelectOptimal = ref(true);
const showPassengerFields = ref(false);

// Live clock
const currentTime = ref('');
const currentDate = ref('');
let clockInterval = null;
const updateClock = () => {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  currentDate.value = now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).toUpperCase();
};
updateClock();

// Supervisor Inspection
const showInspectionModal = ref(false);
const selectedTicketForInspection = ref(null);

// Bluetooth Printer state
const bluetoothPrinter = new BluetoothPrinter();
const useBluetoothPrinter = ref(localStorage.getItem('use_bluetooth_printer') === 'true');
const bluetoothPrinterConnected = ref(false);
const bluetoothPrinterName = ref(null);

// WebSocket: canal actif pour les mises à jour du plan de sièges en temps réel
const currentTripChannel = ref(null);

const subscribeTripChannel = (tripId) => {
  unsubscribeTripChannel();
  if (!tripId) return;
  currentTripChannel.value = tripId;
  Echo.private(`trip.${tripId}`)
    .listen('.SeatMapUpdated', (e) => {
      fetchSeatMap();
      ticketingStore.notifySeatMapChanged(); // Also refresh sidebar
      if (selectedFare.value) {
        fetchSeatSuggestions();
      }
      // Update trip card counts from WebSocket event
      const occupied = (e.changedSeats || []).filter(s => s.status === 'occupied').length;
      const freed = (e.changedSeats || []).filter(s => s.status === 'available').length;
      const delta = occupied - freed;
      if (delta !== 0) {
        const idx = trips.value.findIndex(t => t.id === tripId);
        if (idx !== -1) {
          trips.value[idx] = { ...trips.value[idx], available_seats: Math.max(0, (trips.value[idx].available_seats || 0) - delta) };
        }
      }
    });
};

const unsubscribeTripChannel = () => {
  if (currentTripChannel.value) {
    Echo.leave(`trip.${currentTripChannel.value}`);
    currentTripChannel.value = null;
  }
};

// Zoom and Pan state for Places section
const zoomLevel = ref(1);
const panX = ref(0);
const panY = ref(0);
const isDragging = ref(false);
const dragStartX = ref(0);
const dragStartY = ref(0);

// Passenger form modal
const showPassengerModal = ref(false);
const showDestinationModal = ref(false);
const selectedSeatNumber = ref(null);
const seatSelectionMode = ref(false);
const seatFirstFlow = ref(false);

const bookingSidePanelOpen = computed(() => !!selectedTripId.value);

// Keep the active seat selection green until booking confirmation.
const selectedSeatColor = computed(() => '#22C55E');
const passengerForm = ref({
  name: '',
  phone: ''
});
const passengerFormErrors = ref({});

// Seats to book: multi-seat uses suggestions, single uses selected seat
const seatsToBook = computed(() => {
  if (ticketQuantity.value > 1 && suggestedSeats.value.length >= ticketQuantity.value) {
    return suggestedSeats.value.slice(0, ticketQuantity.value).map(s => s.seat_number);
  }
  return selectedSeatNumber.value ? [selectedSeatNumber.value] : [];
});

// Computed
const currentTrip = computed(() => {
  return trips.value.find(trip => trip.id === selectedTripId.value);
});

const buildTripStationIndices = (trip) => {
    const route = trip?.route;
    if (!route) {
        return {};
    }

    const orderedStationIds = [];
    const addStation = (stationId) => {
        if (stationId && !orderedStationIds.includes(stationId)) {
            orderedStationIds.push(stationId);
        }
    };

    addStation(route.origin_station_id);

    const stops = [...(route.route_stop_orders || route.routeStopOrders || [])]
        .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));

    stops.forEach((stop) => addStation(stop.station_id || stop.station?.id));
    addStation(route.destination_station_id);

    const isReversedTrip = trip.origin_station_id &&
        route.origin_station_id &&
        trip.origin_station_id !== route.origin_station_id;

    const directionStations = isReversedTrip ? [...orderedStationIds].reverse() : orderedStationIds;

    return directionStations.reduce((map, stationId, index) => {
        map[stationId] = index;
        return map;
    }, {});
};

const getStationColor = (stationIndex, totalStations) => {
    if (!Number.isFinite(stationIndex) || totalStations <= 1) {
        return {
            bg: '#DBEAFE',
            fg: '#1D4ED8',
            muted: '#1E40AF',
        };
    }

    const ratio = stationIndex / (totalStations - 1);
    const lightness = 90 - (ratio * 34);
    const textColor = lightness > 70 ? '#1D4ED8' : '#FFFFFF';
    const mutedColor = lightness > 70 ? '#1E40AF' : 'rgba(255,255,255,0.8)';

    return {
        bg: `hsl(220, 100%, ${Number(lightness.toFixed(2))}%)`,
        fg: textColor,
        muted: mutedColor,
    };
};

const filteredTrips = computed(() => {
  let filtered = trips.value;

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

const availableFares = computed(() => {
    if (!currentTrip.value) return [];

    const route = currentTrip.value.route;
    const stationIndexMap = buildTripStationIndices(currentTrip.value);
    const totalStations = Object.keys(stationIndexMap).length;
    const allowedStationIds = new Set(Object.keys(stationIndexMap));

    const filtered = props.routeFares.filter(fare => {
        const fromStation = fare.from_station || fare.fromStation;
        const toStation = fare.to_station || fare.toStation;
        const fareFromId = fare.from_station_id || fromStation?.id;
        const fareToId = fare.to_station_id || toStation?.id;
        if (!fareFromId || !fareToId) return false;
        if (!allowedStationIds.has(fareFromId) || !allowedStationIds.has(fareToId)) return false;

        if (!props.assignedStation) {
            const tripOriginId = currentTrip.value.origin_station_id || route.origin_station_id;
            if (tripOriginId && fareFromId !== tripOriginId) return false;
        }

        const fromIdx = stationIndexMap[fareFromId];
        const toIdx = stationIndexMap[fareToId];
        if (fromIdx !== undefined && toIdx !== undefined) {
            return fromIdx < toIdx;
        }
        return false;
    });

    return [...filtered].sort((a, b) => a.amount - b.amount).map((fare) => {
        const toStation = fare.to_station || fare.toStation;
        const fareToId = fare.to_station_id || toStation?.id;
        const palette = getStationColor(stationIndexMap[fareToId], totalStations);

        return {
            ...fare,
            color: palette.bg,
            textColor: '#FFFFFF',
            mutedColor: 'rgba(255,255,255,0.75)',
        };
    });
});

const totalAmount = computed(() => {
  if (!selectedFare.value) return 0;
  return selectedFare.value.amount;
});

const canBookTickets = computed(() => {
  return selectedTripId.value && 
         selectedFare.value && 
         !processing.value;
});

// Watch for prop updates (e.g. inertia navigation)
watch(() => props.trips, (newTrips) => {
    trips.value = [...newTrips];
});

// Auto-select the trip passed through the URL when opening the horizontal view
watch(
  () => {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('trip_id');
  },
  (tripId) => {
    if (!tripId) return;

    if (selectedTripId.value !== tripId) {
      selectTrip(tripId);
    }
  },
  { immediate: true }
);

// Fallback: when there is only one trip, open it automatically
watch(
  trips,
  (newTrips) => {
    if (selectedTripId.value || !Array.isArray(newTrips) || newTrips.length !== 1) return;
    selectTrip(newTrips[0].id);
  },
  { immediate: true, deep: true }
);

// Methods
function selectTrip(tripId) {
  selectedTripId.value = tripId;
  ticketingStore.setSelectedTripId(tripId);
  selectedFare.value = null;
  seatMap.value = null;
  suggestedSeats.value = [];
  ticketingStore.setSuggestions([]);
  subscribeTripChannel(tripId);
  fetchSeatMap();
  resetZoom();
}

function fetchSeatMap({ silent = false } = {}) {
  if (!selectedTripId.value) return;
  if (!silent) seatMapLoading.value = true;
  return axios.get(route('seller.trips.seatmap', {
    trip: selectedTripId.value,
    _t: new Date().getTime() // Cache busting
  }))
    .then((response) => {
      seatMap.value = response.data;
    })
    .catch((error) => {
      console.error("Erreur lors de la récupération du plan de salle:", error);
      if (!silent) errors.value.seatmap = "Impossible de charger le plan de salle.";
    })
    .finally(() => {
      if (!silent) seatMapLoading.value = false;
    });
}

const fetchSeatSuggestions = async () => {
    if (!selectedTripId.value || !selectedFare.value) return;
    try {
        const response = await axios.get(route('seller.trips.suggest-seats', {
            trip: selectedTripId.value
        }), {
            params: {
                destination_station_id: selectedFare.value.to_station_id,
                boarding_station_id: selectedFare.value.from_station_id,
                quantity: ticketQuantity.value
            }
        });
        suggestedSeats.value = response.data.suggested_seats || [];
        ticketingStore.setSuggestions(suggestedSeats.value);
        bookingType.value = response.data.booking_type;
        occupancyStats.value = response.data.occupancy;
    } catch (error) {
        console.error("Erreur lors de la récupération des suggestions:", error);
        suggestedSeats.value = [];
        ticketingStore.setSuggestions([]);
        bookingType.value = null;
        occupancyStats.value = null;
    }
};

const resetPassengerModalState = () => {
  passengerForm.value = { name: '', phone: '' };
  passengerFormErrors.value = {};
  showPassengerFields.value = false;
};

const openPassengerModal = () => {
  resetPassengerModalState();
  showPassengerModal.value = true;
};

const openDestinationModalForSeat = (seatNumber) => {
  selectedSeatNumber.value = seatNumber;
  seatSelectionMode.value = true;
  seatFirstFlow.value = true;
  showPassengerModal.value = false;
  resetPassengerModalState();
  showDestinationModal.value = true;
  ticketingStore.setShowSuggestions(false);
};

const selectFareForSeat = (fare) => {
  selectedFare.value = fare;
  showDestinationModal.value = false;
  seatSelectionMode.value = false;
};

const handleSeatClick = (seatNumber) => {
  if (!seatMap.value) return;

  let seatObj = null;
  const mapData = seatMap.value.seat_map;
  const rows = Array.isArray(mapData) ? mapData : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];
  
  for (const row of rows) {
    const found = row.find(s => s.number === seatNumber);
    if (found) { seatObj = found; break; }
  }

  if (seatObj?.isOccupied) {
    if (['admin', 'supervisor'].includes(page.props.auth.user.role)) {
      selectedTicketForInspection.value = {
        id: 'req-' + seatObj.ticket_id,
        ticket_number: seatObj.ticket_number || 'UNKNOWN',
        seller_name: 'Guichetier (Auto)',
        reason: 'Inspection Directe',
        time_ago: 'À l\'instant',
        seat_number: seatNumber,
        trip_id: selectedTripId.value,
        original_ticket_id: seatObj.ticket_id,
      };
      showInspectionModal.value = true;
    }
    return;
  }

  if (!selectedFare.value) {
    openDestinationModalForSeat(seatNumber);
    return;
  }

  if (selectedSeatNumber.value === seatNumber) {
    selectedSeatNumber.value = null;
  } else {
    selectedSeatNumber.value = seatNumber;
    openPassengerModal();
  }
};

const autoSelectOptimalSeat = () => {
  if (!selectedFare.value) {
    return;
  }
  
  if (!suggestedSeats.value || suggestedSeats.value.length === 0) {
    return;
  }
  
  // Auto-select the first (best) suggested seat and open modal
  const optimalSeat = suggestedSeats.value[0];
  handleSeatClick(optimalSeat.seat_number);
};

// Immutable update: replaces seatMap.value entirely to guarantee Vue reactivity
const updateSeatMapImmutable = (seatNumber, isOccupied, color) => {
  if (!seatMap.value?.seat_map) return;
  const seatNum = String(seatNumber);
  const delta = isOccupied ? 1 : -1;
  
  const mapRow = (row) => row.map(cell =>
    cell.type === 'seat' && String(cell.number) === seatNum
      ? { ...cell, isOccupied, ...(color ? { color } : {}) }
      : cell
  );

  let newSeatMapData;
  if (Array.isArray(seatMap.value.seat_map)) {
    newSeatMapData = seatMap.value.seat_map.map(mapRow);
  } else {
    newSeatMapData = {};
    if (seatMap.value.seat_map.lower_deck) newSeatMapData.lower_deck = seatMap.value.seat_map.lower_deck.map(mapRow);
    if (seatMap.value.seat_map.upper_deck) newSeatMapData.upper_deck = seatMap.value.seat_map.upper_deck.map(mapRow);
  }

  seatMap.value = {
    ...seatMap.value,
    seat_map: newSeatMapData,
    occupied_seats: (seatMap.value.occupied_seats || 0) + delta,
    available_seats: (seatMap.value.available_seats || 0) - delta,
    occupied_seats_count: (seatMap.value.occupied_seats_count || 0) + delta,
    available_seats_count: (seatMap.value.available_seats_count || 0) - delta,
  };
};

const markSeatOccupied = (seatNumber, color) => {
  updateSeatMapImmutable(seatNumber, true, color);
};

const revertSeatAvailable = (seatNumber) => {
  updateSeatMapImmutable(seatNumber, false);
};

const confirmBooking = async () => {
  // Validate passenger form
  passengerFormErrors.value = {};

  if (showPassengerFields.value && passengerForm.value.name && passengerForm.value.name.trim().length < 2) {
    passengerFormErrors.value.name = 'Le nom doit contenir au moins 2 caractères';
  }

  if (showPassengerFields.value && passengerForm.value.phone && !/^[0-9]{9,15}$/.test(passengerForm.value.phone.replace(/\s/g, ''))) {
    passengerFormErrors.value.phone = 'Numéro de téléphone invalide (9-15 chiffres)';
  }

  if (Object.keys(passengerFormErrors.value).length > 0) {
    return;
  }

  // Determine all seats to book
  const allSeats = seatsToBook.value.length > 0 ? [...seatsToBook.value] : [selectedSeatNumber.value];
  const totalAmount = selectedFare.value.amount * allSeats.length;

  const ticketData = {
    trip_id: selectedTripId.value,
    from_station_id: selectedFare.value.from_station_id,
    to_station_id: selectedFare.value.to_station_id,
    seats: allSeats,
    amount: totalAmount,
  };

  if (showPassengerFields.value && passengerForm.value.name) {
    ticketData.passenger_name = passengerForm.value.name.trim();
  }
  if (showPassengerFields.value && passengerForm.value.phone) {
    ticketData.passenger_phone = passengerForm.value.phone.replace(/\s/g, '');
  }

  // Optimistic: close modal + mark ALL seats occupied instantly with fare color
  const fareColor = selectedFare.value?.color;
  showPassengerModal.value = false;
  allSeats.forEach(seat => markSeatOccupied(seat, fareColor));
  ticketingStore.notifySeatBooked(allSeats, fareColor);
  selectedSeatNumber.value = null;
  // Clear stale suggestions immediately
  suggestedSeats.value = [];
  ticketingStore.setSuggestions([]);
  // Reset quantity back to 1 for next booking (skip watcher to avoid re-fetching suggestions)
  skipQuantityWatch = true;
  ticketQuantity.value = 1;
  // Optimistic: update trip card seat counts
  const tripIdx = trips.value.findIndex(t => t.id === selectedTripId.value);
  if (tripIdx !== -1) {
    trips.value[tripIdx] = {
      ...trips.value[tripIdx],
      available_seats: Math.max(0, (trips.value[tripIdx].available_seats || 0) - allSeats.length),
    };
  }

  try {
    const response = await axios.post(route('seller.tickets.store'), ticketData);
    const data = response.data;
    const ticketIds = data.ticket_ids || [];
    // Reset fare/destination after booking so the form is clean for the next customer
    selectedFare.value = null;
    // Print tickets
    if (ticketIds.length > 0) {
      if (useBluetoothPrinter.value && bluetoothPrinterConnected.value) {
        printWithBluetooth(ticketIds[0]).catch(() => fallbackToBrowserPrint(ticketIds[0]));
      } else {
        ticketIds.forEach(id => fallbackToBrowserPrint(id));
      }
    }

    // Refresh seat map from server (silent — no loading spinner)
    fetchSeatMap({ silent: true });
    ticketingStore.notifySeatMapChanged(); // Tell sidebar to refetch too
  } catch (error) {
    allSeats.forEach(seat => {
      revertSeatAvailable(seat);
    });
    ticketingStore.notifySeatReverted(allSeats);
    // Revert trip card seat counts
    const revertIdx = trips.value.findIndex(t => t.id === selectedTripId.value);
    if (revertIdx !== -1) {
      trips.value[revertIdx] = {
        ...trips.value[revertIdx],
        available_seats: (trips.value[revertIdx].available_seats || 0) + allSeats.length,
      };
    }
    const message = error.response?.data?.message || 'Erreur lors de la création du ticket.';
    alert(message);
  } finally {
    seatFirstFlow.value = false;
    ticketingStore.setShowSuggestions(true);
    processing.value = false;
  }
};

// Bluetooth Printer Methods
const connectBluetoothPrinter = async () => {
  try {
    await bluetoothPrinter.connect();
    bluetoothPrinterConnected.value = true;
    const status = bluetoothPrinter.getStatus();
    bluetoothPrinterName.value = status.deviceName;
    alert(`Imprimante connectée: ${status.deviceName}`);
  } catch (error) {
    console.error('Failed to connect Bluetooth printer:', error);
    alert('Échec de la connexion à l\'imprimante Bluetooth. Veuillez réessayer.');
  }
};

const disconnectBluetoothPrinter = () => {
  bluetoothPrinter.disconnect();
  bluetoothPrinterConnected.value = false;
  bluetoothPrinterName.value = null;
};

const toggleBluetoothPrinter = () => {
  useBluetoothPrinter.value = !useBluetoothPrinter.value;
  localStorage.setItem('use_bluetooth_printer', useBluetoothPrinter.value.toString());
  
  if (useBluetoothPrinter.value && !bluetoothPrinterConnected.value) {
    connectBluetoothPrinter();
  }
};

const printWithBluetooth = async (ticketId) => {
  try {
    // Fetch ticket data
    const response = await axios.get(route('seller.tickets.show-data', { ticket: ticketId }));
    const ticket = response.data;
    
    // Extract settings from response
    const settings = response.data.settings || {
      company_name: 'TSR CI',
      phone_numbers: ['+225 XX XX XX XX XX'],
      footer_messages: ['Valable pour ce voyage', 'Non remboursable'],
      print_qr_code: true,
      qr_code_base_url: null
    };
    
    // Format ticket data for thermal printer
    const ticketData = {
      ticket_number: ticket.ticket_number || 'N/A',
      route_name: ticket.trip?.route?.name || 'N/A',
      from_stop: ticket.from_station?.name || 'N/A',
      to_stop: ticket.to_station?.name || 'N/A',
      date: ticket.trip?.departure_at ? new Date(ticket.trip.departure_at).toLocaleDateString('fr-FR') : 'N/A',
      time: ticket.trip?.departure_at ? new Date(ticket.trip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'N/A',
      class: ticket.trip?.vehicle?.vehicle_type?.name || 'Standard',
      seat_number: ticket.seat_number || 'N/A',
      boarding_group: ticket.boarding_group || '1',
      price: String(ticket.price || 0),
      vehicle_number: ticket.trip?.vehicle?.identifier || 'N/A',
      qr_code: ticket.qr_payload_string || ticket.qr_code || null,
      timestamp: new Date().toLocaleString('fr-FR')
    };
    
    await bluetoothPrinter.printTicket(ticketData, settings);
  } catch (error) {
    console.error('Bluetooth print error:', error);
    throw error;
  }
};

const fallbackToBrowserPrint = (ticketId) => {
  const printUrl = route('tickets.print', { ticket: ticketId });
  const printWindow = window.open(printUrl, '_blank', 'width=400,height=600');
  if (!printWindow) {
    alert('Veuillez autoriser les popups pour imprimer le ticket.');
  }
};

const cancelBooking = () => {
  showPassengerModal.value = false;
  showDestinationModal.value = false;
  seatSelectionMode.value = false;
  seatFirstFlow.value = false;
  selectedFare.value = null;
  selectedSeatNumber.value = null;
  suggestedSeats.value = [];
  ticketingStore.setSuggestions([]);
  ticketingStore.setShowSuggestions(true);
};

const createTrip = () => {
  createTripProcessing.value = true;
  createTripErrors.value = {};
  
  router.post(route('seller.trips.store'), createTripForm.value, {
    preserveState: true,
    onSuccess: () => {
      showCreateTripModal.value = false;
      createTripForm.value = {
        route_id: '',
        vehicle_id: '',
        departure_at: '',
        status: 'scheduled'
      };
    },
    onError: (errors) => {
      createTripErrors.value = errors;
    },
    onFinish: () => {
      createTripProcessing.value = false;
    }
  });
};

// Zoom and Pan methods
const handleWheel = (event) => {
  event.preventDefault();
  const delta = event.deltaY > 0 ? -0.1 : 0.1;
  zoomLevel.value = Math.max(0.5, Math.min(3, zoomLevel.value + delta));
};

const zoomIn = () => {
  zoomLevel.value = Math.min(3, zoomLevel.value + 0.2);
};

const zoomOut = () => {
  zoomLevel.value = Math.max(0.5, zoomLevel.value - 0.2);
};

const handleMouseDown = (event) => {
  isDragging.value = true;
  dragStartX.value = event.clientX - panX.value;
  dragStartY.value = event.clientY - panY.value;
};

const handleMouseMove = (event) => {
  if (isDragging.value) {
    panX.value = event.clientX - dragStartX.value;
    panY.value = event.clientY - dragStartY.value;
  }
};

const handleMouseUp = () => {
  isDragging.value = false;
};

function resetZoom() {
  zoomLevel.value = 1;
  panX.value = 0;
  panY.value = 0;
}

// Watchers
watch(selectedTripId, (newVal) => {
  subscribeTripChannel(newVal);
  if (newVal) {
    selectedFare.value = null;
    seatMap.value = null;
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
    ticketingStore.setShowSuggestions(true);
    showPassengerModal.value = false;
    showDestinationModal.value = false;
    seatSelectionMode.value = false;
    seatFirstFlow.value = false;
    selectedSeatNumber.value = null;
    fetchSeatMap();
    resetZoom(); // Reset zoom when changing trips
  }
}, { immediate: true });

watch(selectedFare, (newVal) => {
    if(newVal) {
        ticketingStore.setShowSuggestions(!seatFirstFlow.value);
        const manualSeatFlow = seatFirstFlow.value && selectedSeatNumber.value;
        fetchSeatSuggestions().then(() => {
            if (manualSeatFlow) {
                openPassengerModal();
                return;
            }
            if (autoSelectOptimal.value && suggestedSeats.value && suggestedSeats.value.length > 0) {
                autoSelectOptimalSeat();
            }
        });
    } else {
        suggestedSeats.value = [];
        ticketingStore.setSuggestions([]);
        ticketingStore.setShowSuggestions(true);
    }
});

// Re-fetch suggestions when ticket quantity changes (skip programmatic resets)
let skipQuantityWatch = false;
watch(ticketQuantity, () => {
    if (skipQuantityWatch) { skipQuantityWatch = false; return; }
    if (selectedFare.value) {
        fetchSeatSuggestions();
    }
});

// Auto-reconnect to Bluetooth printer on page load
onMounted(async () => {
  clockInterval = setInterval(updateClock, 1000);
  if (useBluetoothPrinter.value && bluetoothPrinter.isSupported()) {
    try {
      const devices = await navigator.bluetooth.getDevices();
      if (devices && devices.length > 0) {
        bluetoothPrinter.device = devices[0];
        const server = await bluetoothPrinter.device.gatt.connect();
        const service = await server.getPrimaryService('000018f0-0000-1000-8000-00805f9b34fb');
        bluetoothPrinter.characteristic = await service.getCharacteristic('00002af1-0000-1000-8000-00805f9b34fb');
        bluetoothPrinter.connected = true;
        bluetoothPrinterConnected.value = true;
        bluetoothPrinterName.value = bluetoothPrinter.device.name;
      }
    } catch (error) {
      // Silently fail - user can manually reconnect
    }
  }

  // Subscribe to WebSocket for selected trip
  if (selectedTripId.value) {
    subscribeTripChannel(selectedTripId.value);
  }

  // Listen for real-time trip additions
  if (page.props.auth.user.station_assignments) {
    page.props.auth.user.station_assignments.forEach(assignment => {
      Echo.private(`station.${assignment.station_id}`)
        .listen('.TripCreated', (e) => {
          if (!trips.value.find(t => t.id === e.trip.id)) {
            trips.value.unshift(e.trip);
          }
        });
    });
  }

  if (['admin', 'executive'].includes(page.props.auth.user.role)) {
    Echo.private('trips.global')
      .listen('.TripCreated', (e) => {
        if (!trips.value.find(t => t.id === e.trip.id)) {
          trips.value.unshift(e.trip);
        }
      });
  }
});

onUnmounted(() => {
  unsubscribeTripChannel();
  if (clockInterval) clearInterval(clockInterval);
});

</script>

<template>
  <MainNavLayout :fullHeight="true" :hideTripSidebar="true">
    <template #header-actions>
      <button
        @click="toggleBluetoothPrinter"
        :class="[
          'p-2 border rounded-full text-sm font-medium flex items-center justify-center transition-all',
          useBluetoothPrinter && bluetoothPrinterConnected
            ? 'border-blue-500 bg-blue-50 text-blue-700'
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
        ]"
        :title="bluetoothPrinterConnected ? `Connecté: ${bluetoothPrinterName}` : 'Connecter imprimante Bluetooth'"
      >
        <Bluetooth :class="bluetoothPrinterConnected ? 'text-blue-600' : 'text-gray-500'" class="w-5 h-5" />
      </button>
    </template>

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
                class="w-full pl-28 pr-10 py-2.5 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block p-2.5 appearance-none"
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
          <Link :href="route('seller.ticketing')" class="w-10 h-10 border border-blue-200 text-blue-700 rounded-xl bg-blue-50 hover:bg-blue-100 text-sm font-medium flex items-center justify-center shadow-sm transition-colors" title="Vue verticale">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="w-[18px] h-[18px]">
              <path
                fill="currentColor"
                d="M6 6h5v2H8.41l3.31 3.31-1.42 1.41L7 9.41V11H5V6h1Zm12 0v5h-2V9.41l-3.29 3.29-1.42-1.41L15.59 8H14V6h4Zm0 12h-4v-2h1.59l-3.31-3.31 1.42-1.41L17 14.59V13h1v5Zm-12 0v-5h2v1.59l3.29-3.29 1.42 1.41L8.41 16H10v2H6Z"
              />
            </svg>
          </Link>
          <button @click="showCreateTripModal = true" class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 flex items-center shadow-sm transition-colors">
            <Calendar class="w-4 h-4 mr-2" />
            Nouveau Voyage
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
              Tronçons disponibles <span v-if="currentTrip" class="ml-2 text-2xl font-bold text-gray-700">Départ : <span class="text-orange-600">{{ currentTrip.route?.origin_station?.name }}</span></span>
            </h2>
            
            <div class="flex items-center gap-4">


               <!-- Auto Select Toggle -->
               <label class="flex items-center gap-2 cursor-pointer select-none px-3 py-1.5 rounded-lg bg-green-50 border border-green-200 shadow-sm hover:bg-green-100 hover:border-green-300 transition-colors">
                  <input 
                    type="checkbox" 
                    v-model="autoSelectOptimal"
                    class="h-5 w-5 rounded border-green-400 text-green-600 focus:ring-green-500 accent-green-600"
                  />
                  <span class="text-sm font-semibold text-green-800">Placement auto</span>
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
                      ? 'scale-105 shadow-lg border-red-500 bg-red-50' 
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
                <div v-if="selectedFare?.id === fare.id" class="absolute inset-0 rounded-xl border-2 border-red-500 pointer-events-none"></div>
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
                    class="p-1.5 hover:bg-gray-100 rounded text-green-700 text-xs font-medium disabled:opacity-40 disabled:cursor-not-allowed"
                    title="Agrandir le plan"
                  >
                    <Magnify class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <div v-if="seatMapLoading" class="flex flex-col items-center">
                 <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-600 mb-3"></div>
                 <p class="text-gray-500 font-medium">Chargement du plan...</p>
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
                   :show-suggestions="ticketingStore.showSuggestions && !!selectedFare && suggestedSeats.length > 0"
                   :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role)"
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
    <div v-if="showCreateTripModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[1000]">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg leading-6 font-medium text-gray-900">Créer un nouveau voyage</h3>
          <form @submit.prevent="createTrip" class="mt-2 space-y-4">
            <div>
              <InputLabel for="route_id" value="Route" />
              <select
                id="route_id"
                v-model="createTripForm.route_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-green-500 focus:ring-green-500"
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
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-green-500 focus:ring-green-500"
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
                class="mt-1 block w-full"
                required
              />
              <InputError class="mt-2" :message="createTripErrors.departure_at" />
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4">
              <button
                type="button"
                @click="showCreateTripModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
              >
                Annuler
              </button>
              <button
                type="submit"
                :disabled="createTripProcessing"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
              >
                {{ createTripProcessing ? 'Création...' : 'Créer' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Zoom Modal for Seat Map -->
    <div v-if="showZoomModal" class="fixed inset-0 bg-black bg-opacity-75 z-[1000] flex items-center justify-center p-4">
      <div class="relative bg-white rounded-lg shadow-2xl w-full h-full max-w-7xl max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-orange-50">
          <div>
            <h3 class="text-xl font-bold text-gray-900">Plan des Places</h3>
            <p class="text-sm text-gray-600 mt-1">
              {{ currentTrip?.code || 'Code en attente' }} - {{ currentTrip?.display_name }} - {{ currentTrip?.vehicle?.identifier }}
            </p>
          </div>
          <button @click="showZoomModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
            <Close class="w-8 h-8" />
          </button>
        </div>

        <!-- Legend Removed -->

        <!-- Seat Map with Scroll -->
        <div class="flex-1 overflow-auto p-6 bg-gray-100 flex items-center justify-center">
          <div class="transform rotate-90">
            <VehicleSeatMapSVG
              v-if="currentTrip?.vehicle?.vehicle_type"
              :vehicle-type="currentTrip.vehicle.vehicle_type"
              :seat-map="seatMap"
              :suggested-seats="suggestedSeats"
              @seat-click="bookSeat"
              class="scale-125"
            />
          </div>
        </div>

        <!-- Instructions -->
        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 text-center text-sm text-gray-600">
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
