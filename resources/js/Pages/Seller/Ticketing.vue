<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';
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
import BluetoothPrinter from '@/Services/BluetoothPrinter.js';
import { ticketingStore } from '@/Stores/ticketingStore.js';

const props = defineProps({
  trips: [Array, Object],
  routeFares: Array,
  routes: Array,
  vehicles: Array,
  hasActiveAssignment: Boolean,
  assignedStation: String,
  destinations: {
    type: Array,
    default: () => []
  }
});

// Get page props for auth user
const page = usePage();

// State
const trips = ref(Array.isArray(props.trips) ? [...props.trips] : [...props.trips.data]);
const pagination = ref(Array.isArray(props.trips) ? null : props.trips);
const loadingMore = ref(false);
const selectedTripId = ref(null);
const selectedFare = ref(null);
const ticketQuantity = ref(1);
const showHistory = ref(false);
const selectedDestinationId = computed({
  get: () => ticketingStore.selectedDestinationId,
  set: (val) => ticketingStore.setDestinationFilter(val)
}); // Bound to global store for Sidebar filtering

// Watch for prop changes (Inertia reloads)
watch(() => props.trips, (newVal) => {
  if (Array.isArray(newVal)) {
    trips.value = [...newVal];
    pagination.value = null;
  } else {
    if (loadingMore.value) {
       const existingIds = new Set(trips.value.map(t => t.id));
       const newItems = newVal.data.filter(t => !existingIds.has(t.id));
       trips.value = [...trips.value, ...newItems];
    } else {
       trips.value = [...newVal.data];
    }
    pagination.value = newVal;
    loadingMore.value = false;
  }
}, { deep: true });

// Toggle history effect
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
  sales_control: 'closed'
});
const createTripErrors = ref({});
const createTripProcessing = ref(false);
const showZoomModal = ref(false);
const autoSelectOptimal = ref(true); // Auto-select optimal seat by default
const showPassengerFields = ref(false); // Hide passenger fields by default
const isMobile = ref(window.innerWidth < 768);

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

// Update isMobile on resize
const scrollToSeats = () => {
  setTimeout(() => {
    const el = document.getElementById('mobile-seat-map');
    if (el) el.scrollIntoView({ behavior: 'smooth' });
  }, 100);
};

onMounted(() => {
  clockInterval = setInterval(updateClock, 1000);
  window.addEventListener('resize', () => {
    isMobile.value = window.innerWidth < 768;
  });

  const echo = window.Echo;
  if (!echo) {
    return;
  }

  // Listen for real-time trip additions
  if (page.props.auth.user.station_assignments) {
    page.props.auth.user.station_assignments.forEach(assignment => {
      echo.private(`station.${assignment.station_id}`)
          .listen('.TripCreated', (e) => {
              // Check if trip already exists
              if (!trips.value.find(t => t.id === e.trip.id)) {
                  // Add new trip to list
                  trips.value.unshift(e.trip);
                  // Optional: Show notification
              }
          });
    });
  }

  // Listen for global updates if Admin or Executive
  if (['admin', 'executive'].includes(page.props.auth.user.role)) {
      echo.private('trips.global')
          .listen('.TripCreated', (e) => {
               // Check if trip already exists
               if (!trips.value.find(t => t.id === e.trip.id)) {
                   // Add new trip to list
                   trips.value.unshift(e.trip);
               }
          });
  }
});
const showTripSelectionModal = ref(false); // Modal for selecting a trip

// Bluetooth Printer state
const bluetoothPrinter = new BluetoothPrinter();
const useBluetoothPrinter = ref(localStorage.getItem('use_bluetooth_printer') === 'true');
const bluetoothPrinterConnected = ref(false);
const bluetoothPrinterName = ref(null);

// WebSocket: canal actif pour les mises à jour du plan de sièges en temps réel
const currentTripChannel = ref(null);

/**
 * S'abonner au canal WebSocket d'un voyage pour recevoir les mises à jour
 * du plan de sièges en temps réel (quand un autre vendeur réserve/annule)
 */
const subscribeTripChannel = (tripId) => {
  // Quitter l'ancien canal s'il existe
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
const selectedSeatNumber = ref(null);
const selectedSeatSuggestion = computed(() => {
  if (!selectedSeatNumber.value || !suggestedSeats.value) return null;
  return suggestedSeats.value.find(s => s.seat_number === selectedSeatNumber.value);
});
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

const isTripPassed = computed(() => {
  if (!currentTrip.value) return false;
  return new Date(currentTrip.value.departure_at) < new Date();
});

const filteredTrips = computed(() => {
  let filtered = trips.value;
  
  // Filter by History toggle
  if (!showHistory.value) {
    const nowInstance = new Date();
    // Keep trips in the future OR departed within the last hour
    const limit = new Date(nowInstance.getTime() - 60 * 60 * 1000); 
    filtered = filtered.filter(trip => new Date(trip.departure_at) >= limit);
  }

  // 1. Filter by Destination (City) if selected
  if (selectedDestinationId.value) {
      filtered = filtered.filter(trip => {
          // Check Route Target Target returning true if City matches
          if (trip.route?.destination_station?.city === selectedDestinationId.value) return true;
          
          // Check intermediate stops
          const stops = trip.route?.route_stop_orders || trip.route?.routeStopOrders || [];
          return stops.some(stop => stop.station?.city === selectedDestinationId.value);
      });
  }
  
  return filtered;
});

const availableFares = computed(() => {
    if (!currentTrip.value) return [];
    
    const route = currentTrip.value.route;
    // stops (intermediate)
    // Handle both snake_case (default Laravel) and camelCase (potential JS transform)
    const stops = route?.route_stop_orders || route?.routeStopOrders || [];

    // Build a Set of ALL allowed Station IDs for this route
    // This includes: Origin, Destination, and all Intermediate Stops
    const allowedStationIds = new Set();
    
    if (route.origin_station_id) allowedStationIds.add(route.origin_station_id);
    if (route.destination_station_id) allowedStationIds.add(route.destination_station_id);
    
    // Add intermediate stations
    // Handle both direct station_id and nested station object
    stops.forEach(s => {
        if (s.station_id) allowedStationIds.add(s.station_id);
        if (s.station?.id) allowedStationIds.add(s.station.id);
    });
    
    // Also build IndexMap for direction/ordering logic
    const stationIndexMap = {};
    if (route.origin_station_id) stationIndexMap[route.origin_station_id] = -1; // Start
    
    stops.forEach((s, index) => {
        const sId = s.station_id || s.station?.id;
        if (sId) stationIndexMap[sId] = index;
    });
    
    if (route.destination_station_id) {
         // Ensure Destination is last
         stationIndexMap[route.destination_station_id] = 9999;
    }

    // Check for reversed trip (Trip Origin == Route Destination)
    const isReversedTrip = currentTrip.value.origin_station_id && 
                           route.destination_station_id && 
                           currentTrip.value.origin_station_id === route.destination_station_id;

    // Filter Fares
    const filtered = props.routeFares.filter(fare => {
        // Handle potential naming differences
        const fromStation = fare.from_station || fare.fromStation;
        const toStation = fare.to_station || fare.toStation;

        // IDs from the fare object
        const fareFromId = fare.from_station_id || fromStation?.id;
        const fareToId = fare.to_station_id || toStation?.id;

        if (!fareFromId || !fareToId) return false;

        // STRICT CHECK 1: Both stations must be on the route
        if (!allowedStationIds.has(fareFromId) || !allowedStationIds.has(fareToId)) {
            return false;
        }

        // STRICT CHECK 2: For Admin/No-Assignment, force strict origin comparison?
        // Actually, if we just want valid segments on the route, strict origin might not be needed 
        // if we trust the route set. But typically ticketing starts from the Trip Origin.
        if (!props.assignedStation) {
             const tripOriginId = currentTrip.value.origin_station_id || route.origin_station_id;
             if (tripOriginId && fareFromId !== tripOriginId) {
                 return false;
             }
        }

        // Direction Check using IndexMap
        const fromIdx = stationIndexMap[fareFromId];
        const toIdx = stationIndexMap[fareToId];
        
        if (fromIdx !== undefined && toIdx !== undefined) {
             return isReversedTrip ? fromIdx > toIdx : fromIdx < toIdx;
        }
        
        // Fallback direction check (rare if map is complete)
        return false;
    });

    // Sort by amount (cheapest/closest first)
    const sortedResults = [...filtered].sort((a, b) => a.amount - b.amount);

    return sortedResults.map((fare, index) => {
        // Color based on position in sorted list
        const ratio = sortedResults.length > 1 ? index / (sortedResults.length - 1) : 0;
        const hue = 210 + (ratio * 30);
        const lightness = 75 - (ratio * 40);
        const saturation = 65 + (ratio * 35);
        
        return {
            ...fare,
            color: `hsl(${hue}, ${saturation}%, ${lightness}%)`
        };
    });
});

// Seat statistics computed from seatMap response
const seatStats = computed(() => {
  if (!seatMap.value) {
    return { total: 0, sold: 0, available: 0 };
  }
  // Use the API response fields directly
  const total = seatMap.value.total_seats || 0;
  const sold = seatMap.value.occupied_seats || seatMap.value.occupied_seats_count || 0;
  const available = seatMap.value.available_seats || seatMap.value.available_seats_count || (total - sold);
  return { total, sold, available };
});


const totalAmount = computed(() => {
  if (!selectedFare.value) return 0;
  return selectedFare.value.amount;
});

const canBookTickets = computed(() => {
  return selectedTripId.value && 
         selectedFare.value && 
         !processing.value &&
         !isTripPassed.value;
});

// Watch for prop updates (e.g. inertia navigation)
watch(() => props.trips, (newTrips) => {
    trips.value = [...newTrips];
});

// Methods
const selectTrip = (tripId) => {
  selectedTripId.value = tripId;
  ticketingStore.setSelectedTripId(tripId);
  selectedFare.value = null;
  seatMap.value = null;
  suggestedSeats.value = [];
  showTripSelectionModal.value = false;
};

const fetchSeatMap = async () => {
  if (!selectedTripId.value) return;
  seatMapLoading.value = true;
  
  const params = {
    _t: new Date().getTime()
  };
  
  if (selectedFare.value) {
    // UPDATED: Use station_id
    params.from_station_id = selectedFare.value.from_station_id;
    params.to_station_id = selectedFare.value.to_station_id;
  }
  
  try {
    const response = await axios.get(route('seller.trips.seatmap', { 
      trip: selectedTripId.value
    }), { params });
    seatMap.value = response.data;
  } catch (error) {
    console.error("Erreur lors de la récupération du plan de salle:", error);
    errors.value.seatmap = "Impossible de charger le plan de salle.";
  } finally {
    seatMapLoading.value = false;
  }
};

const fetchSeatSuggestions = async () => {
    if (!selectedTripId.value || !selectedFare.value) return;
    try {
        const response = await axios.get(route('seller.trips.suggest-seats', { 
            trip: selectedTripId.value 
        }), {
            params: {
                // UPDATED: Use station_id
                destination_station_id: selectedFare.value.to_station_id,
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

// New function for the actual booking flow
const initiateBookingFlow = (seatNumber) => {
  if (!selectedFare.value) {
    alert("Veuillez d'abord sélectionner une destination.");
    return;
  }

  selectedSeatNumber.value = seatNumber;
  passengerForm.value = { name: '', phone: '' };
  passengerFormErrors.value = {};
  showPassengerFields.value = false;
  showPassengerModal.value = true;
};

const handleSeatClick = (seatNumber) => {
  if (!seatMap.value || isTripPassed.value) return;

  let seatObj = null;
  const mapData = seatMap.value.seat_map;
  const rows = Array.isArray(mapData) ? mapData : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];
  
  for (const row of rows) {
      const found = row.find(s => s.number === seatNumber);
      if (found) {
          seatObj = found;
          break;
      }
  }

  const isOccupied = seatObj?.isOccupied;

  if (isOccupied) {
      if (['admin', 'supervisor'].includes(page.props.auth.user.role)) {
          selectedTicketForInspection.value = {
              id: 'req-' + seatObj.ticket_id,
              ticket_number: seatObj.ticket_number || 'UNKNOWN',
              seller_name: 'Guichetier (Auto)', // This might need to be fetched or passed from backend
              reason: 'Inspection Directe',
              time_ago: 'À l\'instant',
              seat_number: seatNumber,
              trip_id: selectedTripId.value,
              original_ticket_id: seatObj.ticket_id
          };
          showInspectionModal.value = true;
      }
      return;
  }

  // Normal Booking Flow
  if (selectedSeatNumber.value === seatNumber) {
    selectedSeatNumber.value = null; // Deselect
  } else {
    initiateBookingFlow(seatNumber);
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
  initiateBookingFlow(optimalSeat.seat_number); // Pass seat_number, not the whole object
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

  // Reset fare/destination after booking so the form is clean for the next customer
  selectedFare.value = null;


  try {
    const response = await axios.post(route('seller.tickets.store'), ticketData);
    const data = response.data;
    const ticketIds = data.ticket_ids || [];
    // Print tickets
    if (ticketIds.length > 0) {
      const printId = ticketIds.length > 1 ? ticketIds.join(',') : ticketIds[0];
      if (useBluetoothPrinter.value && bluetoothPrinterConnected.value) {
        printWithBluetooth(ticketIds[0]).catch(() => fallbackToBrowserPrint(ticketIds[0]));
      } else {
        // For multiple tickets, print them all
        ticketIds.forEach(id => fallbackToBrowserPrint(id));
      }
    }

    // Refresh seat map from server (ground truth — works even without WebSocket)
    fetchSeatMap();
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
    const response = await axios.get(route('api.tickets.show', ticketId));
    const ticket = response.data;
    
    // Extract settings from response
    const settings = response.data.settings || {
      company_name: 'TSR CI',
      phone_numbers: ['+225 XX XX XX XX XX'],
      footer_messages: ['Valable pour ce voyage', 'Non remboursable'],
      print_qr_code: false,
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
      vehicle_number: ticket.trip?.vehicle?.registration_number || 'N/A',
      qr_code: ticket.qr_code || null,
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
  selectedFare.value = null;
  selectedSeatNumber.value = null;
  ticketingStore.selectSeat(null); // Clear store selection
  suggestedSeats.value = [];
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
  // Disable wheel zoom on mobile - use +/- buttons only
  if (isMobile.value) return;
  
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

const resetZoom = () => {
  zoomLevel.value = 1;
  panX.value = 0;
  panY.value = 0;
};

// Watchers

// Watch for trip_id in URL to sync state
watch(() => {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('trip_id');
}, (newId) => {
    if (newId && newId !== selectedTripId.value) {
        selectedTripId.value = newId;
        ticketingStore.setSelectedTripId(newId);
        fetchSeatMap();
    }
}, { immediate: true });

watch(selectedTripId, (newVal, oldVal) => {
  // Gérer l'abonnement WebSocket pour le nouveau voyage
  subscribeTripChannel(newVal);

  if (newVal) {
    selectedFare.value = null;
    seatMap.value = null;
    suggestedSeats.value = [];
    fetchSeatMap();
    resetZoom(); // Reset zoom when changing trips
  }
});

watch(selectedFare, (newVal) => {
    if(newVal) {
        ticketingStore.setFareColor(newVal.color);
        // Fetch specific seat map for this segment
        fetchSeatMap();
        
        fetchSeatSuggestions().then(() => {
            // Auto-select optimal seat if enabled
            if (autoSelectOptimal.value && suggestedSeats.value && suggestedSeats.value.length > 0) {
                autoSelectOptimalSeat();
            }
        });
    } else {
        ticketingStore.setFareColor('#3B82F6');
        // Reload full seat map (conservative view)
        fetchSeatMap();
        suggestedSeats.value = [];
        ticketingStore.setSuggestions([]);
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

// Watch for seat selection from the Sidebar
watch(() => ticketingStore.clickTimestamp, () => {
    const newSeat = ticketingStore.selectedSeat;
    if (newSeat) {
        initiateBookingFlow(newSeat);
    }
});

// Auto-reconnect to Bluetooth printer on page load
onMounted(async () => {
  if (useBluetoothPrinter.value && bluetoothPrinter.isSupported()) {
    try {
      // Try to get previously paired devices
      const devices = await navigator.bluetooth.getDevices();
      if (devices && devices.length > 0) {
        // Reconnect to the first device (most recent)
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

  // S'abonner au canal WebSocket du voyage sélectionné (si déjà sélectionné au mount)
  if (selectedTripId.value) {
    subscribeTripChannel(selectedTripId.value);
  }

  /* Auto-selection disabled as requested for a clean workspace */
});

// Nettoyage : quitter le canal WebSocket du voyage au démontage
onUnmounted(() => {
  unsubscribeTripChannel();
  if (clockInterval) clearInterval(clockInterval);
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
            ? 'border-blue-500 bg-blue-100 text-blue-700' 
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
        ]"
        :title="bluetoothPrinterConnected ? `Connecté: ${bluetoothPrinterName}` : 'Connecter imprimante Bluetooth'"
      >
        <Bluetooth :class="bluetoothPrinterConnected ? 'text-blue-600' : 'text-gray-500'" class="w-5 h-5" />
      </button>
    </template>

    <div class="flex-1 flex flex-col gap-4 min-h-0">
          
          <!-- Full-page blocking message if no station assigned (for sellers and supervisors) -->
          <div v-if="['seller', 'supervisor'].includes($page.props.auth.user.role) && !hasActiveAssignment" 
               class="flex-1 flex items-center justify-center">
            <div class="bg-white border border-orange-200 p-12 rounded-3xl flex flex-col items-center text-center shadow-lg max-w-lg">
              <div class="p-5 bg-orange-50 rounded-full shadow-sm mb-6">
                <OfficeBuilding class="w-16 h-16 text-orange-500" />
              </div>
              <h2 class="text-2xl font-black text-gray-900 mb-3">Aucune station assignée</h2>
              <p class="text-gray-600 mb-6 leading-relaxed">
                Vous n'avez pas encore de station assignée. Vous ne pouvez pas vendre de billets tant qu'un superviseur ne vous a pas assigné à une station.
              </p>
              <div class="space-y-3 w-full">
                <p class="text-sm text-gray-500">
                  Contactez votre Administrateur pour être assigné à une station.
                </p>
                <Link 
                  :href="route('profile.edit')" 
                  class="inline-flex items-center gap-2 px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-bold transition-colors"
                >
                  Voir mon profil
                </Link>
              </div>
            </div>
          </div>

          <!-- Main content (only shown if seller has assigned station or user is admin/supervisor) -->
          <template v-else>
          <!-- Workplace Header (Synced with Dashboard) -->
          <div class="bg-white p-4 md:p-6 rounded-2xl shadow-sm border border-orange-100 shrink-0 relative">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="z-10">
                <div class="flex items-center gap-3">
                  <h1 class="text-3xl font-black text-gray-900 tracking-tight">Billetterie</h1>
                  <div v-if="assignedStation || ['admin', 'executive', 'supervisor'].includes($page.props.auth.user.role)" class="px-3 py-1 bg-green-50 text-green-700 text-xs font-black rounded-full border border-green-100 flex items-center gap-1.5 shadow-sm">
                      <OfficeBuilding :size="14" />
                      {{ assignedStation || 'Toutes les gares' }}
                  </div>
                </div>
                <p class="text-gray-500 font-medium">Vente de tickets en temps réel</p>
              </div>

              <!-- Absolute Centered Clock on Desktop -->
              <div class="hidden md:block absolute left-1/2 -translate-x-1/2 text-center z-0">
                <div class="text-4xl font-black text-gray-900 tracking-tight leading-none">{{ currentTime }}</div>
                <div class="text-[10px] font-bold text-gray-400 tracking-widest mt-1">{{ currentDate }}</div>
              </div>

              <!-- Clock and Button aligned on mobile / Button on right on Desktop -->
              <div class="flex items-center justify-between md:justify-end gap-4 md:gap-6 mt-2 md:mt-0 w-full md:w-auto z-10 shrink-0">
                <!-- Mobile Clock -->
                <div class="text-left md:hidden">
                  <div class="text-2xl font-black text-gray-900 tracking-tight leading-none">{{ currentTime }}</div>
                  <div class="text-[10px] font-bold text-gray-400 tracking-widest mt-1">{{ currentDate }}</div>
                </div>
                <button
                  @click="showCreateTripModal = true"
                  class="flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-xl font-bold shadow-lg shadow-green-600/20 transition-all active:scale-95 flex-shrink-0"
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
              <div class="bg-white rounded-2xl border border-orange-100 shadow-sm flex flex-col h-full overflow-hidden">
                <div class="px-5 py-3 border-b border-orange-50 bg-green-50/50 flex flex-col md:flex-row md:items-center justify-between gap-3">
                  <div class="flex flex-col md:flex-row md:items-center gap-3 w-full md:w-auto">
                    <!-- Mobile Group: Title + Badges -->
                    <div class="flex items-center justify-between w-full md:w-auto">
                      <h2 class="text-base font-semibold text-green-700 flex items-center shrink-0">
                        <Bus class="mr-2 w-5 h-5" />
                        Voyages
                      </h2>
                      <!-- Badges on Mobile -->
                      <div class="flex items-center gap-2 md:hidden">
                        <span class="px-2 py-0.5 bg-green-600 text-white rounded-full text-xs font-black shadow-sm">
                          {{ trips.length }} en cours
                        </span>
                      </div>
                    </div>
                    
                    <!-- Destination Filter + Changer on Mobile & Desktop -->
                    <div class="flex items-center gap-2 w-full md:w-auto">
                       <select v-model="selectedDestinationId" class="flex-1 md:w-48 border-green-200 text-green-800 rounded-lg text-sm px-3 py-1.5 focus:border-green-500 focus:ring-green-500 bg-white shadow-sm font-semibold">
                          <option value="">Toutes les destinations</option>
                          <option v-for="dest in destinations" :key="dest.id" :value="dest.id">{{ dest.name }}</option>
                      </select>
                      
                      <!-- History Toggle -->

                      <!-- History Toggle -->
                      <button 
                        v-if="['admin', 'supervisor', 'superadmin'].includes(page.props.auth.user.role)"
                        @click="showHistory = !showHistory"
                        :class="['p-1.5 rounded-lg border transition-all flex items-center justify-center gap-1 shadow-sm', showHistory ? 'bg-orange-600 border-orange-600 text-white' : 'bg-white border-gray-200 text-gray-500 hover:bg-gray-50']"
                        :title="showHistory ? 'Masquer l\'historique' : 'Voir l\'historique (48h)'"
                      >
                        <History :size="20" />
                      </button>

                      <button 
                        @click="showTripSelectionModal = true"
                        class="px-3 py-1.5 bg-white border border-green-500 text-green-700 rounded-lg text-sm font-bold shadow-sm whitespace-nowrap active:bg-green-50 flex items-center justify-center gap-1.5 hover:bg-green-50 transition-colors"
                      >
                        <Magnify v-if="!isMobile" :size="18" />
                        <span>Tous les voyages</span>
                      </button>
                    </div>
                  </div>
                  
                  <div class="hidden md:flex items-center gap-2 shrink-0">
                    <span class="px-2.5 py-1 bg-green-600 text-white rounded-full text-sm font-black shadow-sm">
                      {{ trips.length }} en cours
                    </span>
                  </div>
                </div>
                <div class="flex-1 p-3 overflow-y-auto">
                  <!-- Mobile: Show only selected trip -->
                  <div v-if="isMobile && currentTrip" class="bg-white rounded-xl border border-green-200 p-3 shadow-sm relative overflow-hidden">
                    <div class="flex items-start justify-between mb-2">
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                          <div :class="['w-2 h-2 rounded-full shrink-0', new Date(currentTrip.departure_at) < new Date() ? 'bg-gray-400' : 'bg-green-500 animate-pulse']"></div>
                          <div :class="['text-[10px] uppercase font-bold tracking-wider', new Date(currentTrip.departure_at) < new Date() ? 'text-gray-500' : 'text-green-600']">
                            {{ new Date(currentTrip.departure_at) < new Date() ? 'Voyage Passé' : 'En cours' }}
                          </div>
                        </div>
                        <div class="text-base font-black text-gray-900 leading-tight truncate">{{ currentTrip.display_name }}</div>
                      </div>
                      <div class="text-right shrink-0 ml-3">
                        <div class="text-xl font-black text-gray-900">
                          {{ new Date(currentTrip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}
                        </div>
                        <div class="text-[10px] text-gray-500">{{ currentTrip.vehicle?.identifier }}</div>
                      </div>
                    </div>
                    <!-- Seat Stats Row -->
                    <div v-if="seatStats.total > 0" class="flex items-center gap-2 pt-2 border-t border-gray-100">
                      <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-red-50 rounded-lg">
                        <span class="text-lg font-black text-red-600">{{ seatStats.available }}</span>
                        <span class="text-[10px] text-red-600 font-medium">restantes</span>
                      </div>
                      <div class="flex-1 flex items-center justify-center gap-1 py-1 bg-green-50 rounded-lg">
                        <span class="text-lg font-black text-green-600">{{ seatStats.total }}</span>
                        <span class="text-[10px] text-green-600 font-medium">total</span>
                      </div>
                    </div>
                  </div>

                  <!-- Desktop: Show all trips with highlighted selected -->
                  <div v-if="!isMobile && filteredTrips.length > 0" class="space-y-3">
                    <div
                      v-for="trip in filteredTrips"
                      :key="trip.id"
                      @click="selectTrip(trip.id)"
                      :class="[
                        'p-4 rounded-2xl cursor-pointer transition-all duration-300 border-2',
                        selectedTripId === trip.id
                          ? 'bg-green-50 border-green-500 shadow-lg scale-[1.01]'
                          : 'bg-gray-50 border-transparent hover:border-green-200 hover:bg-white hover:shadow-md'
                      ]"
                    >
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4 flex-1 min-w-0">
                          <div :class="[
                            'p-2 rounded-xl shadow-sm transition-colors',
                            selectedTripId === trip.id ? 'bg-white' : 'bg-white group-hover:bg-green-50'
                          ]">
                            <Bus :size="24" :class="selectedTripId === trip.id ? 'text-green-600' : 'text-gray-400'" />
                          </div>
                          <div class="min-w-0">
                            <div class="flex items-center gap-2">
                              <div v-if="selectedTripId === trip.id" :class="['w-2 h-2 rounded-full', new Date(trip.departure_at) < new Date() ? 'bg-gray-400' : 'bg-green-500 animate-pulse']"></div>
                              <div :class="['font-bold truncate tracking-tight', new Date(trip.departure_at) < new Date() ? 'text-gray-500 italic' : 'text-gray-900']">
                                {{ trip.display_name }}
                                <span v-if="new Date(trip.departure_at) < new Date()" class="ml-2 text-[10px] font-black bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded uppercase">Passé</span>
                              </div>
                              <span
                                :title="trip.sales_control === 'open' ? 'Ventes intermédiaires autorisées' : 'Ventes origine uniquement'"
                                class="text-xs shrink-0"
                              >{{ trip.sales_control === 'open' ? '🔓' : '🔒' }}</span>
                            </div>
                            <div class="text-[10px] font-black text-orange-600 uppercase tracking-widest mt-0.5">
                              {{ trip.vehicle?.identifier }} • {{ trip.vehicle?.vehicle_type?.name }}
                            </div>
                          </div>
                        </div>
                        <div class="text-right shrink-0 ml-3">
                          <div class="text-xl font-black text-gray-900">
                            {{ new Date(trip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}
                          </div>
                          <div class="text-[10px] text-gray-500 font-bold capitalize">
                            {{ new Date(trip.departure_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' }) }}
                          </div>
                        </div>
                      </div>
                      <!-- Seat Stats for all trips -->
                      <div class="flex items-center gap-3 mt-4 pt-4 border-t border-dashed" :class="selectedTripId === trip.id ? 'border-green-200' : 'border-gray-200'">
                        <div class="flex-1 bg-white rounded-xl p-2 border border-orange-100 shadow-sm">
                            <div class="text-[9px] text-gray-400 font-bold uppercase tracking-tighter">Restantes</div>
                            <div class="flex items-end gap-1">
                                <span class="text-base font-black text-red-600">{{ trip.available_seats || 0 }}</span>
                                <span class="text-[9px] text-red-600/70 mb-0.5 font-bold uppercase">Lib</span>
                            </div>
                        </div>
                        <div class="flex-1 bg-white rounded-xl p-2 border border-orange-100 shadow-sm">
                            <div class="text-[9px] text-gray-400 font-bold uppercase tracking-tighter">Total</div>
                            <div class="flex items-end gap-1">
                                <span class="text-base font-black text-gray-700">{{ trip.total_seats || 0 }}</span>
                                <span class="text-[9px] text-gray-500 mb-0.5 font-bold uppercase">Cap</span>
                            </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- No trip selected / No trips -->
                  <div v-if="isMobile && !currentTrip" class="h-full flex flex-col items-center justify-center bg-gray-50 rounded-xl border-2 border-dashed border-gray-200 py-10">
                    <div class="bg-white p-6 rounded-full shadow-md mb-6 relative">
                       <Bus class="w-16 h-16 text-green-600" />
                       <div class="absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full border-2 border-white shadow-sm">
                         {{ trips.length }}
                       </div>
                    </div>
                    <h3 class="text-2xl font-black text-gray-900 mb-2">{{ trips.length }} voyages en cours</h3>
                    <p class="text-gray-500 text-sm max-w-[250px] text-center mb-6">Sélectionnez le voyage pour lequel vous souhaitez vendre des billets.</p>
                    <button 
                      @click="showTripSelectionModal = true"
                      class="px-8 py-3 bg-green-600 text-white rounded-xl text-lg font-black shadow-lg shadow-green-600/20 hover:bg-green-700 hover:scale-105 transition-all transform active:scale-95"
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
              <div class="bg-white rounded-2xl border border-orange-100 shadow-sm flex flex-col h-full overflow-hidden">
                <div class="px-5 py-4 border-b border-orange-50 bg-orange-50/50 flex items-center justify-between">
                  <h2 class="text-base font-bold text-orange-700 flex items-center">
                    <Routes class="mr-2 w-5 h-5" />
                    Destinations
                  </h2>
                  
                  <div class="flex items-center gap-3">
                    <!-- Seats modal button for mobile (scrolls down) -->
                    <button 
                      v-if="currentTrip"
                      @click="scrollToSeats"
                      class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-xl shadow-sm text-xs font-bold flex items-center justify-center gap-1.5 transition-colors md:hidden"
                    >
                      <Bus :size="16" />
                      <span>Sièges</span>
                    </button>

                    <!-- Auto toggle moved here -->
                    <label class="flex items-center gap-2 cursor-pointer bg-white px-3 py-1.5 rounded-xl border border-orange-100 shadow-sm hover:border-green-200 transition-colors">
                      <input 
                        type="checkbox" 
                        v-model="autoSelectOptimal"
                        class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500"
                      />
                      <span class="text-xs text-gray-700 font-medium">⚡ Auto</span>
                    </label>
                  </div>
                </div>
                <div class="flex-1 overflow-y-auto p-2">
                  <div v-if="currentTrip" class="space-y-2">
                    <div v-for="fare in availableFares" :key="fare.id"
                         @click="!isTripPassed && (selectedFare = fare)"
                         :class="[
                           'relative overflow-hidden rounded-2xl transition-all duration-300 border-2 shadow-sm',
                           isTripPassed ? 'opacity-50 cursor-not-allowed grayscale' : 'cursor-pointer active:scale-[0.98]',
                           selectedFare?.id === fare.id 
                             ? 'ring-2 ring-offset-2 scale-[1.02] shadow-xl border-red-500 ring-red-500' 
                             : 'border-transparent hover:shadow-lg'
                         ]"
                         :style="{
                           backgroundColor: fare.color || '#4F46E5',
                           '--tw-ring-color': selectedFare?.id === fare.id ? '#ef4444' : (fare.color || '#4F46E5')
                         }"
                    >
                      <!-- Horizontal Layout: Destination Left, Price Right -->
                      <div class="p-3 flex items-center justify-between">
                        <div class="flex-1 min-w-0 mr-3">
                          <div class="text-white text-base font-bold truncate">
                            {{ fare.to_station?.name }}
                          </div>
                          <div class="text-white/70 text-[10px] font-medium">
                            → depuis {{ fare.from_station?.name?.split(' - ')[1] || fare.from_station?.name }}
                          </div>
                        </div>
                        <div class="text-right shrink-0 flex items-center gap-2">
                          <div>
                            <div class="text-2xl font-black text-white">
                              {{ fare.amount.toLocaleString('fr-FR') }}
                            </div>
                            <div class="text-white/70 text-[10px] font-bold">FCFA</div>
                          </div>
                          <!-- Checkmark removed as requested -->
                        </div>
                      </div>
                      <div v-if="ticketQuantity > 1" class="bg-black/10 px-3 py-1 text-white/90 text-[10px] font-bold">
                        ×{{ ticketQuantity }} = {{ (fare.amount * ticketQuantity).toLocaleString('fr-FR') }} F
                      </div>
                    </div>
                  </div>
                  <div v-else class="p-8 text-center text-gray-400">
                    <p>Sélectionnez un voyage pour voir les destinations.</p>
                  </div>

                  <!-- Passed Trip Message -->
                  <div v-if="currentTrip && isTripPassed" class="mx-3 mt-4 p-4 bg-gray-100 border border-gray-200 rounded-2xl flex flex-col items-center text-center">
                    <div class="p-2 bg-gray-200 rounded-full mb-3">
                      <Clock :size="20" class="text-gray-500" />
                    </div>
                    <div class="text-xs font-black text-gray-900 uppercase tracking-widest mb-1">Ventes Fermées</div>
                    <p class="text-[10px] text-gray-500 font-medium">Ce voyage est déjà parti le {{ new Date(currentTrip.departure_at).toLocaleDateString('fr-FR') }} à {{ new Date(currentTrip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}. Les réservations ne sont plus possibles.</p>
                  </div>
                </div>
                
                <!-- Mobile Seat Map inline -->
                <div id="mobile-seat-map" v-if="seatMap && currentTrip?.vehicle?.vehicle_type" class="p-4 border-t border-orange-100 bg-gray-50 flex flex-col items-center overflow-x-hidden md:hidden">
                  <h3 class="text-sm font-bold text-gray-700 mb-8 w-full flex items-center justify-center gap-2">
                     <Bus class="w-5 h-5 text-green-600 bg-white border border-green-200 rounded p-0.5 shadow-sm" />
                     Avant du bus
                  </h3>
                  
                  <div class="w-full flex items-center justify-center py-4 overflow-x-auto">
                    <div class="scale-100 origin-top transition-transform">
                      <VehicleSeatMapSVG
                        :key="'mobile-' + currentTrip.id"
                        :vehicle-type="currentTrip.vehicle.vehicle_type"
                        :seat-map="seatMap"
                        :suggested-seats="suggestedSeats"
                        :show-suggestions="!!selectedFare && suggestedSeats.length > 0"
                        :selected-seat="selectedSeatNumber"
                        :selected-color="selectedFare?.color"
                        :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role)"
                        @seat-click="handleSeatClick"
                        class="w-full h-auto"
                      />
                    </div>
                  </div>
                  
                  <div class="mt-8 text-xs font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2">
                     Arrière du bus
                  </div>
                </div>
                
              </div>
            </div>
        </div>
          </template>
    </div>

    <!-- Passenger Information Modal -->
    <div v-if="showPassengerModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[60] flex items-center justify-center p-4">
      <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 transform transition-all duration-300 overflow-hidden">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-black text-gray-900 flex items-center gap-2">
                <Account :size="24" class="text-green-600" />
                Informations Passager
              </h3>
              <button @click="cancelBooking" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                <Close class="w-6 h-6 text-gray-400" />
              </button>
            </div>
            
            <!-- Seat Information -->
            <div class="bg-gradient-to-br from-green-50 to-orange-50/50 border border-orange-100 rounded-2xl p-5 mb-6 shadow-sm">
              <div class="text-center">
                <div class="text-3xl font-black text-gray-900 mb-2 leading-none">
                    <span v-if="seatsToBook.length > 1" class="text-blue-600">Places {{ seatsToBook.join(', ') }}</span>
                    <span v-else class="text-blue-600">Place {{ selectedSeatNumber }}</span>
                </div>
                <div v-if="seatsToBook.length > 1" class="bg-white/60 backdrop-blur-sm rounded-xl p-3 mb-3 border border-orange-100 inline-block">
                  <div class="text-[10px] font-black text-green-600 uppercase tracking-widest">{{ seatsToBook.length }} places adjacentes</div>
                </div>
                <div v-else-if="selectedSeatSuggestion" class="bg-white/60 backdrop-blur-sm rounded-xl p-3 mb-3 border border-orange-100 inline-block text-left">
                  <div class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-1">Suggestion</div>
                  <div class="text-xs text-gray-700 leading-snug">
                    <span class="font-bold">Score:</span> {{ selectedSeatSuggestion.score }} •
                    {{ selectedSeatSuggestion.reason }}
                  </div>
                </div>
                <div class="space-y-1">
                    <div class="text-sm font-bold text-gray-700 flex items-center justify-center gap-2">
                        <Bus :size="14" class="text-green-600" />
                        {{ currentTrip?.display_name || '---' }}
                    </div>
                    <div class="text-xs text-gray-500 font-medium">
                        {{ selectedFare?.from_station?.name }} → {{ selectedFare?.to_station?.name }}
                    </div>
                </div>
                <div class="text-2xl font-black text-green-700 mt-4 px-4 py-2 bg-green-100/50 rounded-xl inline-block border border-green-200">
                    {{ (selectedFare?.amount || 0).toLocaleString('fr-FR') }} 
                    <span class="text-sm font-bold opacity-60">FCFA</span>
                </div>
                
                <!-- Quantity Input Moved Here -->
                <div class="mt-6 flex flex-col items-center gap-3 bg-white/40 p-4 rounded-2xl border border-orange-100 shadow-inner">
                   <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Quantité de billets</span>
                   <div class="flex items-center bg-white rounded-xl border border-orange-100 shadow-sm overflow-hidden">
                      <button 
                        type="button"
                        @click="ticketQuantity = Math.max(1, ticketQuantity - 1)"
                        class="px-4 py-2 text-gray-600 hover:bg-orange-50 transition-colors border-r border-orange-50 font-black text-lg"
                      >-</button>
                      <input 
                        v-model.number="ticketQuantity"
                        type="number"
                        min="1"
                        max="10"
                        class="w-16 py-2 text-center border-0 focus:ring-0 text-gray-900 font-black text-xl bg-transparent"
                      />
                      <button 
                        type="button"
                        @click="ticketQuantity = Math.min(10, ticketQuantity + 1)"
                        class="px-4 py-2 text-gray-600 hover:bg-orange-50 transition-colors border-l border-orange-50 font-black text-lg"
                      >+</button>
                   </div>
                   <div v-if="ticketQuantity > 1" class="text-sm font-black text-blue-700 animate-pulse">
                      Total: {{ ((selectedFare?.amount || 0) * ticketQuantity).toLocaleString('fr-FR') }} FCFA
                   </div>
                </div>
              </div>
            </div>
            
            <!-- Toggle for passenger fields -->
            <button
              @click="showPassengerFields = !showPassengerFields"
              class="w-full flex items-center justify-between p-3 bg-gray-50 hover:bg-gray-100 rounded-lg mb-4 transition-colors"
            >
              <span class="text-sm font-medium text-gray-700">Informations passager (optionnel)</span>
              <ChevronDown :class="{ 'rotate-180': showPassengerFields }" class="w-5 h-5 text-gray-500 transition-transform" />
            </button>
            
            <!-- Passenger fields (collapsible) -->
            <div v-show="showPassengerFields" class="space-y-4 mb-4">
              <div>
                <InputLabel for="passenger_name" value="Nom du passager" />
                <TextInput
                  id="passenger_name"
                  v-model="passengerForm.name"
                  type="text"
                  class="mt-1 block w-full rounded-xl border-orange-100 focus:border-green-500 focus:ring-green-500"
                  placeholder="Nom complet"
                />
                <InputError class="mt-2" :message="passengerFormErrors.name" />
              </div>
              
              <div>
                <InputLabel for="passenger_phone" value="Téléphone" />
                <TextInput
                  id="passenger_phone"
                  v-model="passengerForm.phone"
                  type="tel"
                  class="mt-1 block w-full rounded-xl border-orange-100 focus:border-green-500 focus:ring-green-500"
                  placeholder="Ex: 0102030405"
                />
                <InputError class="mt-2" :message="passengerFormErrors.phone" />
              </div>
            </div>
            
            <form @submit.prevent="confirmBooking" class="mt-8 space-y-3">
              <button
                type="submit"
                :disabled="processing"
                class="w-full py-4 bg-green-600 text-white rounded-2xl font-black text-lg flex items-center justify-center gap-2 shadow-lg shadow-green-600/20 hover:bg-green-700 active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <div v-if="processing" class="animate-spin mr-2"><Refresh :size="20" /></div>
                <Printer v-else :size="24" />
                <span>{{ processing ? 'Validation...' : 'Valider & Imprimer' }}</span>
              </button>
              
              <button
                type="button"
                @click="cancelBooking"
                class="w-full py-3 bg-gray-50 text-gray-500 rounded-2xl font-bold text-sm hover:bg-gray-100 transition-colors"
              >
                Annuler
              </button>
            </form>
        </div>
      </div>
    </div>

    <!-- Modal de création de voyage -->
    <div v-if="showCreateTripModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
      <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-5">
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

            <!-- Sales Control Toggle -->
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
              <div class="flex items-center justify-between">
                <div>
                  <label for="sales_control" class="text-sm font-medium text-gray-900">
                    Ventes intermédiaires
                  </label>
                  <p class="text-xs text-gray-500 mt-1">
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
                    createTripForm.sales_control === 'open' ? 'bg-green-600' : 'bg-gray-200'
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
    <div v-if="showZoomModal" class="fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center p-4">
      <div class="relative bg-white rounded-lg shadow-2xl w-full h-full max-w-7xl max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-orange-50">
          <div>
            <h3 class="text-xl font-bold text-gray-900">Plan des Places</h3>
            <p class="text-sm text-gray-600 mt-1">
              {{ currentTrip?.display_name }} - {{ currentTrip?.vehicle?.identifier }}
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
              :key="'modal-' + (currentTrip?.id || 'default')"
              v-if="currentTrip?.vehicle?.vehicle_type"
              :vehicle-type="currentTrip.vehicle.vehicle_type"
              :seat-map="seatMap"
              :suggested-seats="suggestedSeats"
              :show-suggestions="!!selectedFare && suggestedSeats.length > 0"
              :selected-seat="selectedSeatNumber"
              :selected-color="selectedFare?.color"
              :allow-occupied-click="['admin', 'supervisor'].includes($page.props.auth.user.role)"
              :vertical-mode="true"
              @seat-click="handleSeatClick"
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

    <!-- Trip Selection Modal -->
    <div v-if="showTripSelectionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[100] flex items-center justify-center p-4">
      <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden transform transition-all">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-100 bg-green-50 flex items-center justify-between">
          <div>
            <h3 class="text-xl font-bold text-green-700">Sélectionner un voyage</h3>
            <p class="text-sm text-green-600">Choisissez le départ pour lequel vous vendez des billets</p>
          </div>
          <button @click="showTripSelectionModal = false" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full transition-colors">
            <Close class="w-6 h-6" />
          </button>
        </div>

        <!-- Destination Filter -->
        <div class="p-4 border-b border-gray-100 bg-white">
          <div class="flex flex-col md:flex-row gap-3">
            <!-- Destination Filter -->
            <div class="relative flex-1">
              <select 
                v-model="selectedDestinationId"
                class="w-full pl-10 py-3 bg-gray-50 border-0 focus:ring-2 focus:ring-green-500 rounded-xl text-sm transition-all font-bold text-gray-800 cursor-pointer"
              >
                <option value="">Toutes les destinations</option>
                <option v-for="dest in destinations" :key="dest.id" :value="dest.id">{{ dest.name }}</option>
              </select>
              <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-green-600 pointer-events-none">
                 <Routes class="w-5 h-5" />
              </div>
            </div>
            
            <!-- History Toggle -->

            <!-- History Toggle -->
            <button 
               v-if="['admin', 'supervisor', 'superadmin'].includes(page.props.auth.user.role)"
               @click="showHistory = !showHistory"
               :class="['px-4 py-3 rounded-xl border-2 transition-all flex items-center justify-center gap-2 font-bold text-sm shadow-sm', showHistory ? 'bg-orange-600 border-orange-600 text-white' : 'bg-white border-gray-200 text-gray-500 hover:bg-gray-50']"
               :title="showHistory ? 'Masquer l\'historique' : 'Voir l\'historique (48h)'"
            >
               <History :size="20" />
               <span v-if="!isMobile">{{ showHistory ? 'Masquer historique' : 'Historique' }}</span>
            </button>
          </div>
        </div>

        <!-- Trip List -->
        <div class="flex-1 overflow-y-auto p-4 bg-gray-50">
          <div v-if="filteredTrips.length > 0">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div v-for="trip in filteredTrips" :key="trip.id"
                   @click="selectTrip(trip.id)"
                   :class="[
                     'group p-4 rounded-xl border-2 cursor-pointer transition-all duration-200 bg-white hover:shadow-lg',
                     selectedTripId === trip.id 
                       ? 'border-green-500 bg-green-50 shadow-md ring-4 ring-green-500/10' 
                       : 'border-transparent hover:border-green-200'
                   ]">
                <div class="flex items-start justify-between mb-3">
                  <div class="bg-green-100 p-2 rounded-lg group-hover:bg-green-200 transition-colors">
                    <Bus class="w-6 h-6 text-green-600" />
                  </div>
                  <div v-if="selectedTripId === trip.id" class="bg-green-500 text-white p-1 rounded-full">
                    <Check class="w-4 h-4" />
                  </div>
                  <div v-else-if="new Date(trip.departure_at) < new Date()" class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded text-[10px] font-bold uppercase">
                    Passé
                  </div>
                </div>
                
                <div class="font-bold text-gray-900 text-base mb-2 leading-tight">
                  {{ trip.display_name }}
                </div>
                
                <div class="space-y-2">
                  <div class="flex items-center text-sm text-gray-600">
                    <Clock class="w-4 h-4 mr-2 text-green-500" />
                    <span class="capitalize">{{ new Date(trip.departure_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' }) }} - {{ new Date(trip.departure_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</span>
                  </div>
                  <div class="flex items-center text-sm text-gray-600">
                    <OfficeBuilding class="w-4 h-4 mr-2 text-green-500" />
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
                class="px-8 py-3 bg-white border-2 border-green-500 text-green-700 font-bold rounded-xl hover:bg-green-50 transition-all shadow-sm active:scale-95 disabled:opacity-50 flex items-center gap-2"
              >
                <Refresh v-if="loadingMore" class="animate-spin" />
                <span>{{ loadingMore ? 'Chargement...' : 'Afficher plus de voyages' }}</span>
              </button>
            </div>
          </div>
          <div v-else class="h-64 flex flex-col items-center justify-center text-gray-400">
            <Bus class="w-16 h-16 mb-4 opacity-20" />
            <p class="text-lg font-medium">Aucun voyage trouvé</p>
            <p class="text-sm">Essayez une autre recherche ou créez un nouveau voyage</p>
          </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-gray-100 bg-white flex justify-end">
           <button 
             @click="showTripSelectionModal = false"
             class="px-6 py-2 bg-gray-100 text-gray-700 font-bold rounded-lg hover:bg-gray-200 transition-colors"
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
