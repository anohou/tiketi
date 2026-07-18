/**
 * useTicketing — Shared composable for Ticketing and TicketingHorizontal pages.
 *
 * Extracts all the common business logic (state, seat map, booking flow,
 * Bluetooth printing, WebSocket, zoom/pan, trip creation) so that the two
 * page components only contain their layout-specific templates.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import BluetoothPrinter from '@/Services/BluetoothPrinter.js';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import { toastStore } from '@/Stores/toastStore.js';

/**
 * @param {Object} props - Component props (trips, routeFares, routes, vehicles, hasActiveAssignment, assignedStation, destinations)
 * @param {Object} [options] - Layout-specific options
 * @param {boolean} [options.supportsPagination=false] - Whether to handle paginated trip responses
 * @param {boolean} [options.sendsSegmentParams=false] - Whether fetchSeatMap sends from/to station params
 */
export function useTicketing(props, options = {}) {
  const { supportsPagination = false, sendsSegmentParams = false } = options;

  const page = usePage();

  // =============================================
  // Core State
  // =============================================
  const trips = ref(
    supportsPagination
      ? (Array.isArray(props.trips) ? [...props.trips] : [...props.trips.data])
      : [...(Array.isArray(props.trips) ? props.trips : props.trips?.data || props.trips)]
  );
  const pagination = ref(
    supportsPagination && !Array.isArray(props.trips) ? props.trips : null
  );
  const loadingMore = ref(false);

  const selectedTripId = ref(null);
  const selectedFare = ref(null);
  const ticketQuantity = ref(1);
  const seatMap = ref(null);
  const seatMapLoading = ref(false);
  const suggestedSeats = ref([]);
  const bookingType = ref(null);
  const occupancyStats = ref(null);
  const processing = ref(false);
  const errors = ref({});
  const autoSelectOptimal = ref(true);
  const showPassengerFields = ref(false);

  // Create trip modal
  const showCreateTripModal = ref(false);
  const createTripForm = ref({
    code: '',
    route_id: '',
    vehicle_id: '',
    departure_at: '',
    status: 'scheduled',
    sales_control: 'closed',
    allows_open_connections: false,
    automatic_connection_allocation: null,
    is_replicable: false,
  });
  const createTripErrors = ref({});
  const createTripProcessing = ref(false);

  // Zoom modal
  const showZoomModal = ref(false);

  // Trip details modal
  const showTripDetailsModal = ref(false);
  const selectedDetailsTripId = ref(null);

  const openTripDetails = (tripId) => {
    selectedDetailsTripId.value = tripId;
    showTripDetailsModal.value = true;
  };

  // Passenger form modal
  const showPassengerModal = ref(false);
  const showDestinationModal = ref(false);
  const selectedSeatNumber = ref(null);
  const seatSelectionMode = ref(false);
  const seatFirstFlow = ref(false);
  const selectedSeatColor = computed(() => '#22C55E');
  const passengerForm = ref({ name: '', phone: '' });
  const passengerFormErrors = ref({});
  const finalDestinationStationId = ref(null);
  const connectionRouteId = ref(null);

  // Supervisor Inspection
  const showInspectionModal = ref(false);
  const selectedTicketForInspection = ref(null);

  // =============================================
  // Live Clock
  // =============================================
  const currentTime = ref('');
  const currentDate = ref('');
  let clockInterval = null;

  const updateClock = () => {
    const now = new Date();
    currentTime.value = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    currentDate.value = now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).toUpperCase();
  };
  updateClock();

  // =============================================
  // Bluetooth Printer
  // =============================================
  const bluetoothPrinter = new BluetoothPrinter();
  const useBluetoothPrinter = ref(localStorage.getItem('use_bluetooth_printer') === 'true');
  const bluetoothPrinterConnected = ref(false);
  const bluetoothPrinterName = ref(null);

  const connectBluetoothPrinter = async () => {
    try {
      await bluetoothPrinter.connect();
      bluetoothPrinterConnected.value = true;
      const status = bluetoothPrinter.getStatus();
      bluetoothPrinterName.value = status.deviceName;
      toastStore.success(`Imprimante connectée: ${status.deviceName}`);
    } catch (error) {
      console.error('Failed to connect Bluetooth printer:', error);
      toastStore.error('Échec de la connexion à l\'imprimante Bluetooth. Veuillez réessayer.');
    }
  };

  const disconnectBluetoothPrinter = () => {
    bluetoothPrinter.disconnect();
    bluetoothPrinterConnected.value = false;
    bluetoothPrinterName.value = null;
  };

  const ensureBluetoothPrinterConnected = async () => {
    if (bluetoothPrinterConnected.value) return true;
    if (!bluetoothPrinter.isSupported()) return false;
    await bluetoothPrinter.connect();
    bluetoothPrinterConnected.value = true;
    const status = bluetoothPrinter.getStatus();
    bluetoothPrinterName.value = status.deviceName;
    return true;
  };

  const toggleBluetoothPrinter = () => {
    useBluetoothPrinter.value = !useBluetoothPrinter.value;
    localStorage.setItem('use_bluetooth_printer', useBluetoothPrinter.value.toString());
    if (useBluetoothPrinter.value && !bluetoothPrinterConnected.value) {
      connectBluetoothPrinter();
    }
  };

  const printWithBluetooth = async (ticketId) => {
    const response = await axios.get(route('seller.tickets.show-data', { ticket: ticketId }));
    const ticket = response.data;
    const settings = response.data.settings || {
      company_name: 'TEST TRANSPORT',
      phone_numbers: ['+225 XX XX XX XX XX'],
      footer_messages: ['Valable pour ce voyage', 'Non remboursable'],
      baggage_policy_message: "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
      print_qr_code: true,
      qr_code_base_url: null,
    };
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
      timestamp: new Date().toLocaleString('fr-FR'),
    };
    await bluetoothPrinter.printTicket(ticketData, settings);
  };

  const fallbackToBrowserPrint = (ticketId) => {
    const printUrl = route('tickets.print', { ticket: ticketId });
    const printWindow = window.open(printUrl, '_blank', 'width=400,height=600');
    if (!printWindow) {
      toastStore.warning('Veuillez autoriser les popups pour imprimer le ticket.');
    }
  };

  const printTickets = async (ticketIds) => {
    if (useBluetoothPrinter.value) {
      try {
        const connected = await ensureBluetoothPrinterConnected();
        if (connected) {
          for (const id of ticketIds) {
            await printWithBluetooth(id);
          }
          return;
        }
      } catch (error) {
        console.error('Bluetooth print failed, falling back to browser print:', error);
      }
    }
    ticketIds.forEach(id => fallbackToBrowserPrint(id));
  };

  // =============================================
  // WebSocket
  // =============================================
  const currentTripChannel = ref(null);
  const currentStationChannels = ref([]);
  const recentRealtimeEventSignatures = new Map();
  let realtimeFallbackInterval = null;

  const isSameTripId = (a, b) => String(a) === String(b);

  const hasRecentlyProcessedRealtimeEvent = (e = {}) => {
    const tripId = e.trip_id || e.trip?.id || 'unknown';
    const seatsSignature = JSON.stringify(e.changedSeats || e.changed_seats || []);
    const signature = `${e.action || 'ticket.updated'}:${tripId}:${e.source_station_id || e.station_id || ''}:${seatsSignature}`;
    const now = Date.now();
    const lastSeen = recentRealtimeEventSignatures.get(signature);

    if (lastSeen && (now - lastSeen) < 1200) {
      return true;
    }

    recentRealtimeEventSignatures.set(signature, now);
    setTimeout(() => {
      recentRealtimeEventSignatures.delete(signature);
    }, 3000);

    return false;
  };

  const extractAssignedStationIds = () => {
    const assignments = page.props.auth.user?.station_assignments || [];
    return [...new Set(assignments
      .map(assignment => assignment.station_id)
      .filter(Boolean)
      .map(stationId => String(stationId)))];
  };

  const applyRealtimeSeatMapUpdate = (e = {}) => {
    if (hasRecentlyProcessedRealtimeEvent(e)) return;

    const tripId = e.trip_id || e.trip?.id;
    if (!tripId) return;

    const changedSeats = e.changedSeats || e.changed_seats || [];
    const occupied = changedSeats.filter(s => s.status === 'occupied').length;
    const freed = changedSeats.filter(s => s.status === 'available').length;
    const delta = occupied - freed;

    if (isSameTripId(selectedTripId.value, tripId)) {
      fetchSeatMap({ silent: true });
      ticketingStore.notifySeatMapChanged();
      if (selectedFare.value) {
        fetchSeatSuggestions();
      }
    }

    const idx = trips.value.findIndex(t => t.id === tripId);
    if (idx !== -1 && delta !== 0) {
      trips.value[idx] = {
        ...trips.value[idx],
        available_seats: Math.max(0, (trips.value[idx].available_seats || 0) - delta),
      };
    }

    ticketingStore.pulseTrip(tripId, {
      action: e.action || 'ticket.updated',
      sourceStationId: e.source_station_id || e.station_id || null,
      changedSeats,
    });
  };

  const applyRealtimeTripCreated = (e = {}) => {
    if (hasRecentlyProcessedRealtimeEvent({ ...e, action: 'trip.created' })) return;

    if (!e.trip?.id) return;

    if (!trips.value.find(t => t.id === e.trip.id)) {
      trips.value.unshift(e.trip);
    }

    ticketingStore.pulseTrip(e.trip.id, {
      action: 'trip.created',
      sourceStationId: e.source_station_id || null,
    });
  };

  const subscribeTripChannel = (tripId) => {
    unsubscribeTripChannel();
    if (!tripId) return;
    currentTripChannel.value = tripId;
    const echo = window.Echo;
    if (!echo) return;

    echo.private(`trip.${tripId}`)
      .listen('.SeatMapUpdated', (e) => {
        applyRealtimeSeatMapUpdate(e);
      });
  };

  const unsubscribeTripChannel = () => {
    const echo = window.Echo;
    if (currentTripChannel.value && echo) {
      echo.leave(`trip.${currentTripChannel.value}`);
      currentTripChannel.value = null;
    }
  };

  const subscribeStationChannels = () => {
    const echo = window.Echo;
    if (!echo) return;

    unsubscribeStationChannels();

    const stationIds = extractAssignedStationIds();
    stationIds.forEach((stationId) => {
      echo.private(`station.${stationId}`)
        .listen('.SeatMapUpdated', applyRealtimeSeatMapUpdate)
        .listen('.TripCreated', applyRealtimeTripCreated);
    });
    currentStationChannels.value = stationIds;

    if (['admin', 'executive'].includes(page.props.auth.user?.role)) {
      echo.private('trips.global')
        .listen('.SeatMapUpdated', applyRealtimeSeatMapUpdate)
        .listen('.TripCreated', applyRealtimeTripCreated);
      currentStationChannels.value = [...currentStationChannels.value, 'trips.global'];
    }

    if (['admin', 'executive', 'supervisor', 'seller'].includes(page.props.auth.user?.role)) {
      echo.private('network.global')
        .listen('.SeatMapUpdated', applyRealtimeSeatMapUpdate)
        .listen('.TripCreated', applyRealtimeTripCreated);
      currentStationChannels.value = [...currentStationChannels.value, 'network.global'];
    }
  };

  const unsubscribeStationChannels = () => {
    const echo = window.Echo;
    if (!echo) return;

    currentStationChannels.value.forEach((channelName) => {
      echo.leave(channelName);
    });
    currentStationChannels.value = [];
  };

  const isEchoConnected = () => {
    try {
      return window.Echo?.connector?.pusher?.connection?.state === 'connected';
    } catch (error) {
      return false;
    }
  };

  const syncCurrentTripSilently = async () => {
    if (!selectedTripId.value) return;

    try {
      const response = await axios.get(route('seller.trips.seatmap', { trip: selectedTripId.value }));
      seatMap.value = response.data;
    } catch (error) {
      console.error('Sync seat map fallback failed:', error);
    }
  };

  const ensureRealtimeFallback = () => {
    if (realtimeFallbackInterval) {
      clearInterval(realtimeFallbackInterval);
      realtimeFallbackInterval = null;
    }

    if (!selectedTripId.value) return;

    realtimeFallbackInterval = setInterval(() => {
      if (!isEchoConnected()) {
        syncCurrentTripSilently();
      }
    }, 5000);
  };

  // =============================================
  // Zoom & Pan
  // =============================================
  const zoomLevel = ref(1);
  const panX = ref(0);
  const panY = ref(0);
  const isDragging = ref(false);
  const dragStartX = ref(0);
  const dragStartY = ref(0);

  const handleWheel = (event) => {
    event.preventDefault();
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    zoomLevel.value = Math.max(0.5, Math.min(3, zoomLevel.value + delta));
  };
  const zoomIn = () => { zoomLevel.value = Math.min(3, zoomLevel.value + 0.2); };
  const zoomOut = () => { zoomLevel.value = Math.max(0.5, zoomLevel.value - 0.2); };

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
  const handleMouseUp = () => { isDragging.value = false; };

  function resetZoom() {
    zoomLevel.value = 1;
    panX.value = 0;
    panY.value = 0;
  }

  // =============================================
  // Computed: Trip & Fare helpers
  // =============================================
  const bookingSidePanelOpen = computed(() => !!selectedTripId.value);

  const currentTrip = computed(() => trips.value.find(trip => trip.id === selectedTripId.value));

  const isTripPassed = computed(() => {
    if (!currentTrip.value) return false;
    return new Date(currentTrip.value.departure_at) < new Date();
  });

  const isTripDeparted = computed(() => currentTrip.value?.status === 'departed');

  const isSalesClosedForSeller = computed(() => {
    if (!currentTrip.value) return false;
    if (!props.assignedStation) return false;

    const originName = currentTrip.value.route?.origin_station?.name;
    const isAtOrigin = originName === props.assignedStation;

    return !isAtOrigin && currentTrip.value.sales_control === 'closed';
  });

  const hasFreedSeatsForSeller = computed(() => {
    if (!currentTrip.value) return false;
    return availableFares.value.some(fare => getStationSellableSeatNumbers(fare).length > 0);
  });

  const emptySeatNumbers = computed(() => {
    const seatNumbers = new Set();
    if (!seatMap.value?.seat_map) return seatNumbers;

    const mapData = seatMap.value.seat_map;
    const rows = Array.isArray(mapData)
      ? mapData
      : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];

    rows.forEach((row) => {
      row.forEach((seat) => {
        if (seat?.type === 'seat' && !seat.isOccupied) {
          seatNumbers.add(Number(seat.number));
        }
      });
    });

    return seatNumbers;
  });

  const getStationSellableSeatNumbers = (fare) => {
    if (!fare) return [];

    const stationSeats = seatMap.value?.sellable_seats_by_station?.[fare.from_station_id]
      || seatMap.value?.freed_seats_by_station?.[fare.from_station_id]
      || [];

    const seats = isTripDeparted.value
      ? [...stationSeats, ...emptySeatNumbers.value]
      : stationSeats;

    return [...new Set(seats.map(sn => Number(sn)).filter(Number.isFinite))];
  };

  const buildFallbackSuggestions = (seatNumbers) => {
    const allowedSeats = new Set(seatNumbers.map(sn => Number(sn)));
    const orderedSeats = [];

    if (seatMap.value?.seat_map) {
      const mapData = seatMap.value.seat_map;
      const rows = Array.isArray(mapData)
        ? mapData
        : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];

      rows.forEach((row) => {
        row.forEach((seat) => {
          if (seat?.type === 'seat' && allowedSeats.has(Number(seat.number))) {
            orderedSeats.push(Number(seat.number));
          }
        });
      });
    }

    const uniqueOrderedSeats = [...new Set(
      orderedSeats.length > 0
        ? orderedSeats
        : seatNumbers.map(sn => Number(sn)).filter(Number.isFinite).sort((a, b) => a - b)
    )];

    return uniqueOrderedSeats.slice(0, ticketQuantity.value).map((seat_number, index) => ({
      seat_number,
      score: 1000 - (index * 10),
      reason: 'Place disponible à votre gare',
    }));
  };

  // Collect all seat numbers available to the seller's station
  const freedSeatNumbersForSeller = computed(() => {
    if (!currentTrip.value) return new Set();
    const seatNumbers = new Set();

    for (const fare of availableFares.value) {
      getStationSellableSeatNumbers(fare).forEach(sn => seatNumbers.add(Number(sn)));
    }
    return seatNumbers;
  });

  const isFareDisabled = (fare) => {
    if (!currentTrip.value) return false;
    if (!props.assignedStation) return false;

    const originName = currentTrip.value.route?.origin_station?.name;
    const isAtOrigin = originName === props.assignedStation;

    if (isAtOrigin) return false;
    if (currentTrip.value.sales_control === 'open') return false;

    const stationSeats = getStationSellableSeatNumbers(fare);

    return !Array.isArray(stationSeats) || stationSeats.length <= 0;
  };

  const seatsToBook = computed(() => {
    if (ticketQuantity.value > 1 && suggestedSeats.value.length >= ticketQuantity.value) {
      return suggestedSeats.value.slice(0, ticketQuantity.value).map(s => s.seat_number);
    }
    return selectedSeatNumber.value ? [selectedSeatNumber.value] : [];
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

  const seatStats = computed(() => {
    if (!seatMap.value) return { total: 0, soldTickets: 0, occupiedSeats: 0, available: 0 };
    const total = seatMap.value.total_seats || 0;
    const occupiedSeats = seatMap.value.occupied_seats_count
      ?? seatMap.value.occupied_seats
      ?? 0;
    const soldTickets = seatMap.value.sold_tickets_count
      ?? seatMap.value.tickets_count
      ?? seatMap.value.soldTicketsCount
      ?? 0;
    const available = seatMap.value.available_seats
      ?? seatMap.value.available_seats_count
      ?? (total - occupiedSeats);
    return { total, soldTickets, occupiedSeats, available };
  });

  const getOccupancyRate = (available, total) => {
    if (!total) return 0;
    const occupied = Math.max(0, total - available);
    return Math.round((occupied / total) * 100);
  };

  // =============================================
  // Station Index / Color Helpers (unified)
  // =============================================
  const buildTripStationIndices = (trip) => {
    const routeObj = trip?.route;
    if (!routeObj) return {};

    const orderedStationIds = [];
    const addStation = (stationId) => {
      if (stationId && !orderedStationIds.includes(stationId)) {
        orderedStationIds.push(stationId);
      }
    };

    addStation(routeObj.origin_station_id);
    const stops = [...(routeObj.route_stop_orders || routeObj.routeStopOrders || [])]
      .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
    stops.forEach((stop) => addStation(stop.station_id || stop.station?.id));
    addStation(routeObj.destination_station_id);

    const tripOriginIndex = orderedStationIds.indexOf(trip.origin_station_id);
    const tripDestinationIndex = orderedStationIds.indexOf(trip.destination_station_id);
    const isReversedTrip = tripOriginIndex !== -1 &&
      tripDestinationIndex !== -1 &&
      tripOriginIndex > tripDestinationIndex;

    const directionStations = isReversedTrip ? [...orderedStationIds].reverse() : orderedStationIds;

    return directionStations.reduce((map, stationId, index) => {
      map[stationId] = index;
      return map;
    }, {});
  };

  /**
   * Gradient color for destination fare cards.
   * Same hue per origin station, lightness varies by destination distance.
   * Nearest destination = lightest, farthest = darkest.
   */
  const getContrastingTextColor = (backgroundColor) => {
    if (typeof backgroundColor !== 'string') return '#FFFFFF';

    const color = backgroundColor.trim().toLowerCase();
    const hexMatch = color.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (hexMatch) {
      const hex = hexMatch[1].length === 3
        ? hexMatch[1].split('').map((char) => char + char).join('')
        : hexMatch[1];
      const r = parseInt(hex.slice(0, 2), 16);
      const g = parseInt(hex.slice(2, 4), 16);
      const b = parseInt(hex.slice(4, 6), 16);
      const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
      return luminance > 0.62 ? '#0F172A' : '#FFFFFF';
    }

    const hslMatch = color.match(/^hsl\(\s*[\d.]+,\s*[\d.]+%?,\s*([\d.]+)%\s*\)$/i);
    if (hslMatch) {
      const lightness = Number.parseFloat(hslMatch[1]);
      if (!Number.isNaN(lightness)) {
        return lightness > 62 ? '#0F172A' : '#FFFFFF';
      }
    }

    return '#FFFFFF';
  };

  const getMutedTextColor = (backgroundColor) => {
    const textColor = getContrastingTextColor(backgroundColor);
    return textColor === '#FFFFFF' ? 'rgba(255,255,255,0.7)' : 'rgba(15,23,42,0.65)';
  };

  const routeHuePalette = [
    220, // Blue
    270, // Purple
    25,  // Orange
    165, // Teal
    330, // Rose
    195, // Cyan
    140, // Green
    350, // Red
  ];

  const getRouteStationColor = (stationIndex) => {
    const safeIndex = Number.isFinite(stationIndex) && stationIndex >= 0 ? stationIndex : 0;
    const hue = routeHuePalette[safeIndex % routeHuePalette.length];
    const lightness = safeIndex === 0 ? 44 : Math.max(40, 52 - (safeIndex * 2));

    return {
      bg: `hsl(${hue}, 80%, ${lightness}%)`,
      fg: '#FFFFFF',
      muted: 'rgba(255,255,255,0.7)',
    };
  };

  const getStationColor = (fromIdx, toIdx, totalStops) => {
    const hues = [
      220, // 0: Blue (Origin)
      270, // 1: Purple
      25,  // 2: Orange
      165, // 3: Teal
      330, // 4: Rose
      195, // 5: Cyan
      140, // 6: Green
      350, // 7: Red
    ];

    const safeFromIdx = Number.isFinite(fromIdx) && fromIdx >= 0 ? fromIdx : 0;
    const safeToIdx = Number.isFinite(toIdx) && toIdx >= 0 ? toIdx : 0;
    const safeTotalStops = Number.isFinite(totalStops) && totalStops > 0 ? totalStops : 1;

    const hue = hues[safeFromIdx % hues.length];

    // Calculate ratio: 0 = nearest destination, 1 = farthest destination
    const remainingStops = safeTotalStops - 1 - safeFromIdx;
    let ratio = 0;
    if (remainingStops > 1) {
      ratio = (safeToIdx - safeFromIdx - 1) / (remainingStops - 1);
      ratio = Math.max(0, Math.min(1, ratio));
    }

    // Wide lightness range for clear visual gradient: 75% (light) → 35% (dark)
    const lightness = 75 - (ratio * 40);

    return {
      bg: `hsl(${hue}, 80%, ${Number(lightness.toFixed(1))}%)`,
      fg: '#FFFFFF',
      muted: 'rgba(255,255,255,0.65)',
    };
  };

  // =============================================
  // Local sorting order (stored in localStorage)
  // =============================================
  const tripsOrder = ref([]);
  try {
    tripsOrder.value = JSON.parse(localStorage.getItem('tiketi.tripsOrder') || '[]');
  } catch (e) {
    tripsOrder.value = [];
  }

  const saveTripsOrder = (newOrder) => {
    tripsOrder.value = newOrder;
    localStorage.setItem('tiketi.tripsOrder', JSON.stringify(newOrder));
  };

  const moveTripUp = (tripId) => {
    const currentFilteredIds = filteredTrips.value.map(t => t.id);
    const index = currentFilteredIds.indexOf(tripId);
    if (index <= 0) return; // Cannot move up if at the top or not found

    // Swap
    const newFilteredIds = [...currentFilteredIds];
    const temp = newFilteredIds[index];
    newFilteredIds[index] = newFilteredIds[index - 1];
    newFilteredIds[index - 1] = temp;

    const nonVisibleIds = tripsOrder.value.filter(id => !currentFilteredIds.includes(id));
    saveTripsOrder([...newFilteredIds, ...nonVisibleIds]);
  };

  const moveTripDown = (tripId) => {
    const currentFilteredIds = filteredTrips.value.map(t => t.id);
    const index = currentFilteredIds.indexOf(tripId);
    if (index === -1 || index >= currentFilteredIds.length - 1) return; // Cannot move down if at the bottom

    // Swap
    const newFilteredIds = [...currentFilteredIds];
    const temp = newFilteredIds[index];
    newFilteredIds[index] = newFilteredIds[index + 1];
    newFilteredIds[index + 1] = temp;

    const nonVisibleIds = tripsOrder.value.filter(id => !currentFilteredIds.includes(id));
    saveTripsOrder([...newFilteredIds, ...nonVisibleIds]);
  };

  // Drag and Drop reordering state & methods
  const isDraggable = ref(false);
  const dragIndex = ref(null);
  const dragOverIndex = ref(null);

  const dragStart = (event, index) => {
    dragIndex.value = index;
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      // Set empty drag image or styling if needed, standard is fine
    }
  };

  const dragEnter = (event, index) => {
    dragOverIndex.value = index;
  };

  const dragEnd = () => {
    dragOverIndex.value = null;
    isDraggable.value = false;
    dragIndex.value = null;
  };

  const dragDrop = (event, dropIndex) => {
    if (dragIndex.value === null || dragIndex.value === dropIndex) {
      dragEnd();
      return;
    }

    const currentFiltered = [...filteredTrips.value];
    const draggedTrip = currentFiltered[dragIndex.value];

    // Move item in array
    currentFiltered.splice(dragIndex.value, 1);
    currentFiltered.splice(dropIndex, 0, draggedTrip);

    // Save the new order
    const newOrderIds = currentFiltered.map(t => t.id);
    const nonVisibleIds = tripsOrder.value.filter(id => !newOrderIds.includes(id));
    saveTripsOrder([...newOrderIds, ...nonVisibleIds]);

    dragEnd();
  };

  // =============================================
  // Computed: Filtered Trips & Available Fares
  // =============================================
  const filteredTrips = computed(() => {
    let filtered = trips.value;
    // Filter by destination city if selected
    const destFilter = ticketingStore.selectedDestinationId;
    if (destFilter) {
      filtered = filtered.filter(trip => {
        if (trip.route?.destination_station?.city === destFilter) return true;
        const stops = trip.route?.route_stop_orders || trip.route?.routeStopOrders || [];
        return stops.some(stop => stop.station?.city === destFilter);
      });
    }

    // Apply manual sorting order if present
    if (tripsOrder.value && tripsOrder.value.length > 0) {
      const orderMap = {};
      tripsOrder.value.forEach((id, index) => {
        orderMap[id] = index;
      });
      return [...filtered].sort((a, b) => {
        const aIndex = orderMap[a.id] !== undefined ? orderMap[a.id] : 999999;
        const bIndex = orderMap[b.id] !== undefined ? orderMap[b.id] : 999999;
        return aIndex - bIndex;
      });
    }

    return filtered;
  });

  const availableFares = computed(() => {
    if (!currentTrip.value) return [];
    const routeObj = currentTrip.value.route;
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
        const tripOriginId = currentTrip.value.origin_station_id || routeObj.origin_station_id;
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
      const fromStation = fare.from_station || fare.fromStation;
      const toStation = fare.to_station || fare.toStation;
      const fareFromId = fare.from_station_id || fromStation?.id;
      const fareToId = fare.to_station_id || toStation?.id;
      const fromIdx = stationIndexMap[fareFromId];
      const toIdx = stationIndexMap[fareToId];
      const palette = getStationColor(fromIdx, toIdx, totalStations);
      return {
        ...fare,
        color: palette.bg,
        textColor: palette.fg,
        mutedColor: palette.muted,
      };
    });
  });

  const getAssignedStationPalette = () => {
    if (!currentTrip.value) {
      return null;
    }

    // Prefer the active fare color, then the first available fare color.
    // This keeps the station tag aligned with the color family shown in the sales cards.
    const activeFareColor = selectedFare.value?.color;
    const fallbackFareColor = availableFares.value?.[0]?.color;
    const paletteColor = activeFareColor || fallbackFareColor;

    if (paletteColor) {
      return {
        bg: paletteColor,
        fg: getContrastingTextColor(paletteColor),
        muted: getMutedTextColor(paletteColor),
      };
    }

    if (!props.assignedStation) {
      return null;
    }

    const stationIndexMap = buildTripStationIndices(currentTrip.value);
    const stationId = props.assignedStationId || null;
    if (!stationId) return null;

    const stationIndex = stationIndexMap[stationId];
    if (stationIndex === undefined) return null;

    return getRouteStationColor(stationIndex);
  };

  const currentStationSellableSeatNumbers = computed(() => {
    const stationId = props.assignedStationId;
    const sellableSeatsByStation = seatMap.value?.sellable_seats_by_station || {};
    const seatNumbers = sellableSeatsByStation[stationId] || [];

    return seatNumbers
      .map((seatNumber) => Number(seatNumber))
      .filter((seatNumber) => Number.isFinite(seatNumber));
  });

  const currentStationSellableSeatBorderColor = computed(() => getAssignedStationPalette()?.bg || null);

  // =============================================
  // API Methods
  // =============================================
  function fetchSeatMap({ silent = false } = {}) {
    if (!selectedTripId.value) return Promise.resolve();
    if (!silent) seatMapLoading.value = true;

    const params = { _t: new Date().getTime() };
    if (sendsSegmentParams && selectedFare.value) {
      params.from_station_id = selectedFare.value.from_station_id;
      params.to_station_id = selectedFare.value.to_station_id;
    }

    return axios.get(route('seller.trips.seatmap', { trip: selectedTripId.value }), { params })
      .then((response) => { seatMap.value = response.data; })
      .catch((error) => {
        console.error('Erreur lors de la récupération du plan de salle:', error);
        if (!silent) errors.value.seatmap = 'Impossible de charger le plan de salle.';
      })
      .finally(() => { if (!silent) seatMapLoading.value = false; });
  }

  const fetchSeatSuggestions = async () => {
    if (!selectedTripId.value || !selectedFare.value) return;
    try {
      const response = await axios.get(route('seller.trips.suggest-seats', {
        trip: selectedTripId.value,
      }), {
        params: {
          _t: Date.now(),
          destination_station_id: selectedFare.value.to_station_id,
          boarding_station_id: selectedFare.value.from_station_id,
          quantity: ticketQuantity.value,
        },
      });
      const apiSuggestions = response.data.suggested_seats || response.data.data?.suggestions || [];
      if (apiSuggestions.length > 0) {
        suggestedSeats.value = apiSuggestions;
      } else {
        const fallbackSeatNumbers = getStationSellableSeatNumbers(selectedFare.value);
        suggestedSeats.value = fallbackSeatNumbers.length > 0
          ? buildFallbackSuggestions(fallbackSeatNumbers)
          : [];
      }
      ticketingStore.setSuggestions(suggestedSeats.value);
      bookingType.value = response.data.booking_type;
      occupancyStats.value = response.data.occupancy;
    } catch (error) {
      console.error('Erreur lors de la récupération des suggestions:', error);
      const fallbackSeatNumbers = getStationSellableSeatNumbers(selectedFare.value);
      if (fallbackSeatNumbers.length > 0) {
        suggestedSeats.value = buildFallbackSuggestions(fallbackSeatNumbers);
        ticketingStore.setSuggestions(suggestedSeats.value);
        bookingType.value = null;
        occupancyStats.value = null;
        return;
      }
      suggestedSeats.value = [];
      ticketingStore.setSuggestions([]);
      bookingType.value = null;
      occupancyStats.value = null;
    }
  };

  // =============================================
  // Seat Map Update Helpers (immutable for Vue reactivity)
  // =============================================
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
      sold_tickets_count: (seatMap.value.sold_tickets_count || seatMap.value.tickets_count || 0) + delta,
    };
  };

  const markSeatOccupied = (seatNumber, color) => updateSeatMapImmutable(seatNumber, true, color);
  const revertSeatAvailable = (seatNumber) => updateSeatMapImmutable(seatNumber, false);

  // =============================================
  // Booking Flow
  // =============================================
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

  const initiateBookingFlow = (seatNumber) => {
    if (!selectedFare.value) {
      openDestinationModalForSeat(seatNumber);
      return;
    }
    selectedSeatNumber.value = seatNumber;
    openPassengerModal();
  };

  const handleSeatClick = (seatNumber) => {
    if (!seatMap.value || isTripPassed.value) return;

    let seatObj = null;
    const mapData = seatMap.value.seat_map;
    const rows = Array.isArray(mapData) ? mapData : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];

    for (const row of rows) {
      const found = row.find(s => s.number === seatNumber);
      if (found) { seatObj = found; break; }
    }

    const seatNum = Number(seatNumber);
    const canBookThisSeat = !isSalesClosedForSeller.value || freedSeatNumbersForSeller.value.has(seatNum);

    if (seatObj?.isOccupied && !canBookThisSeat) {
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

    // When sales are closed for this seller:
    // - before departure: only seats freed at this station
    // - after departure: empty seats + seats freed at this station
    if (isSalesClosedForSeller.value && !freedSeatNumbersForSeller.value.has(seatNum)) {
      return; // Block click on non-available seats
    }

    if (selectedSeatNumber.value === seatNumber) {
      selectedSeatNumber.value = null;
    } else {
      initiateBookingFlow(seatNumber);
    }
  };

  const autoSelectOptimalSeat = () => {
    if (!selectedFare.value) return;
    if (!suggestedSeats.value || suggestedSeats.value.length === 0) return;
    const optimalSeat = suggestedSeats.value[0];
    initiateBookingFlow(optimalSeat.seat_number);
  };

  let skipQuantityWatch = false;

  const confirmBooking = async () => {
    // Validate
    passengerFormErrors.value = {};
    if (showPassengerFields.value && passengerForm.value.name && passengerForm.value.name.trim().length < 2) {
      passengerFormErrors.value.name = 'Le nom doit contenir au moins 2 caractères';
    }
    if (showPassengerFields.value && passengerForm.value.phone && !/^[0-9]{9,15}$/.test(passengerForm.value.phone.replace(/\s/g, ''))) {
      passengerFormErrors.value.phone = 'Numéro de téléphone invalide (9-15 chiffres)';
    }
    if (Object.keys(passengerFormErrors.value).length > 0) return;

    processing.value = true;

    const allSeats = seatsToBook.value.length > 0 ? [...seatsToBook.value] : [selectedSeatNumber.value];
    const connectionFare = finalDestinationStationId.value
      ? (props.connectionFares || []).find(fare =>
        (fare.from_station_id === selectedFare.value.from_station_id && fare.to_station_id === finalDestinationStationId.value)
        || (fare.is_bidirectional && fare.to_station_id === selectedFare.value.from_station_id && fare.from_station_id === finalDestinationStationId.value)
      )
      : null;
    const totalAmt = (connectionFare?.amount ?? selectedFare.value.amount) * allSeats.length;

    const ticketData = {
      trip_id: selectedTripId.value,
      from_station_id: selectedFare.value.from_station_id,
      to_station_id: selectedFare.value.to_station_id,
      seats: allSeats,
      amount: totalAmt,
    };
    if (finalDestinationStationId.value) {
      ticketData.final_destination_station_id = finalDestinationStationId.value;
      ticketData.connection_route_id = connectionRouteId.value;
    }

    if (showPassengerFields.value && passengerForm.value.name) {
      ticketData.passenger_name = passengerForm.value.name.trim();
    }
    if (showPassengerFields.value && passengerForm.value.phone) {
      ticketData.passenger_phone = passengerForm.value.phone.replace(/\s/g, '');
    }

    // Optimistic: close modal + mark seats occupied
    const fareColor = selectedFare.value?.color;
    showPassengerModal.value = false;
    allSeats.forEach(seat => markSeatOccupied(seat, fareColor));
    ticketingStore.notifySeatBooked(allSeats, fareColor);
    selectedSeatNumber.value = null;
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
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

    // Reset fare for next customer
    selectedFare.value = null;
    finalDestinationStationId.value = null;
    connectionRouteId.value = null;

    try {
      const response = await axios.post(route('seller.tickets.store'), ticketData);
      const data = response.data;
      const ticketIds = data.ticket_ids || [];
      if (ticketIds.length > 0) {
        await printTickets(ticketIds);
      }
      // Refresh seat map from server
      fetchSeatMap({ silent: true });
      ticketingStore.notifySeatMapChanged();
    } catch (error) {
      allSeats.forEach(seat => revertSeatAvailable(seat));
      ticketingStore.notifySeatReverted(allSeats);
      const revertIdx = trips.value.findIndex(t => t.id === selectedTripId.value);
      if (revertIdx !== -1) {
        trips.value[revertIdx] = {
          ...trips.value[revertIdx],
          available_seats: (trips.value[revertIdx].available_seats || 0) + allSeats.length,
        };
      }
      const message = error.response?.data?.message || 'Erreur lors de la création du ticket.';
      toastStore.error(message);
    } finally {
      seatFirstFlow.value = false;
      ticketingStore.setShowSuggestions(true);
      processing.value = false;
    }
  };

  const cancelBooking = () => {
    showPassengerModal.value = false;
    showDestinationModal.value = false;
    seatSelectionMode.value = false;
    seatFirstFlow.value = false;
    selectedFare.value = null;
    selectedSeatNumber.value = null;
    ticketingStore.selectSeat?.(null);
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
    ticketingStore.setShowSuggestions(true);
  };

  // =============================================
  const applyReplicableTemplate = (template) => {
    if (!template) return;
    createTripForm.value.route_id = template.route_id;
    createTripForm.value.allows_open_connections = !!template.allows_open_connections;
    createTripForm.value.automatic_connection_allocation = template.automatic_connection_allocation;
    createTripForm.value.is_replicable = true;

    const currentDatePart = createTripForm.value.departure_at
      ? createTripForm.value.departure_at.split('T')[0]
      : new Date().toISOString().split('T')[0];

    createTripForm.value.departure_at = `${currentDatePart}T${template.time}`;
  };

  // Trip Creation
  // =============================================
  const createTrip = () => {
    createTripProcessing.value = true;
    createTripErrors.value = {};
    router.post(route('seller.trips.store'), createTripForm.value, {
      preserveState: true,
      onSuccess: () => {
        showCreateTripModal.value = false;
        createTripForm.value = { code: '', route_id: '', vehicle_id: '', departure_at: '', status: 'scheduled', sales_control: 'closed', allows_open_connections: false, automatic_connection_allocation: null, is_replicable: false };
      },
      onError: (errs) => { createTripErrors.value = errs; },
      onFinish: () => { createTripProcessing.value = false; },
    });
  };

  // =============================================
  // Trip Selection
  // =============================================
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

  // =============================================
  watch([() => createTripForm.value.route_id, () => createTripForm.value.departure_at], ([routeId, departureAt]) => {
    if (routeId && departureAt) {
      const routeObj = props.routes?.find(r => r.id === routeId);
      if (routeObj) {
        const origin = routeObj.origin_station || routeObj.originStation;
        const destination = routeObj.destination_station || routeObj.destinationStation;

        const originCode = origin?.code || (origin ? origin.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'TRP');
        const destinationCode = destination?.code || (destination ? destination.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'DST');

        const timePart = departureAt.split('T')[1] ? departureAt.split('T')[1].replace(':', '') : '0000';
        const cleanTime = timePart.substring(0, 4);

        createTripForm.value.code = `${originCode}-${destinationCode}-${cleanTime}`;
      }
    }
  });

  // Watchers
  // =============================================

  // Watch for trip_id in URL
  watch(() => {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('trip_id');
  }, (tripId) => {
    if (tripId && !isSameTripId(tripId, selectedTripId.value)) {
      selectTrip(tripId);
    }
  }, { immediate: true });

  // Watch for prop changes (Inertia reloads)
  watch(() => props.trips, (newVal) => {
    if (supportsPagination) {
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
    } else {
      trips.value = Array.isArray(newVal) ? [...newVal] : [...(newVal?.data || newVal)];
    }
  }, { deep: true });

  // Auto-select if there's only one trip
  watch(trips, (newTrips) => {
    if (selectedTripId.value || !Array.isArray(newTrips) || newTrips.length !== 1) return;
    selectTrip(newTrips[0].id);
  }, { immediate: true, deep: true });

  // Trip change → reset state
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
      resetZoom();
      ensureRealtimeFallback();
    } else if (realtimeFallbackInterval) {
      clearInterval(realtimeFallbackInterval);
      realtimeFallbackInterval = null;
    }
  });

  // Fare change → fetch suggestions + segment-specific seat map
  watch(selectedFare, (newVal) => {
    if (newVal) {
      ticketingStore.setFareColor?.('#22C55E');
      ticketingStore.setShowSuggestions(!seatFirstFlow.value);
      const manualSeatFlow = seatFirstFlow.value && selectedSeatNumber.value;
      (async () => {
        if (sendsSegmentParams) {
          await fetchSeatMap();
        }

        await fetchSeatSuggestions();

        if (manualSeatFlow) {
          openPassengerModal();
          return;
        }
        if (autoSelectOptimal.value && suggestedSeats.value?.length > 0) {
          autoSelectOptimalSeat();
        }
      })();
    } else {
      ticketingStore.setFareColor?.('#22C55E');
      ticketingStore.setShowSuggestions(true);
      if (sendsSegmentParams) fetchSeatMap();
      suggestedSeats.value = [];
      ticketingStore.setSuggestions([]);
    }
  });

  // Quantity change → re-fetch suggestions
  watch(ticketQuantity, () => {
    if (skipQuantityWatch) { skipQuantityWatch = false; return; }
    if (selectedFare.value) fetchSeatSuggestions();
  });

  // Listen for seat selection from Sidebar
  watch(() => ticketingStore.clickTimestamp, () => {
    const newSeat = ticketingStore.selectedSeat;
    if (newSeat) initiateBookingFlow(newSeat);
  });

  // =============================================
  // Lifecycle
  // =============================================
  onMounted(async () => {
    clockInterval = setInterval(updateClock, 1000);

    // Auto-reconnect Bluetooth
    if (useBluetoothPrinter.value && bluetoothPrinter.isSupported()) {
      try {
        const devices = await navigator.bluetooth.getDevices();
        if (devices?.length > 0) {
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

    subscribeStationChannels();
  });

  onUnmounted(() => {
    unsubscribeTripChannel();
    unsubscribeStationChannels();
    if (realtimeFallbackInterval) clearInterval(realtimeFallbackInterval);
    if (clockInterval) clearInterval(clockInterval);
  });

  // =============================================
  // Return public API
  // =============================================
  return {
    // State
    trips,
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

    // Bluetooth
    useBluetoothPrinter,
    bluetoothPrinterConnected,
    bluetoothPrinterName,
    connectBluetoothPrinter,
    disconnectBluetoothPrinter,
    toggleBluetoothPrinter,

    // Zoom/Pan
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

    // Computed
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

    // Helpers
    buildTripStationIndices,
    getStationColor,
    getAssignedStationPalette,
    currentStationSellableSeatNumbers,
    currentStationSellableSeatBorderColor,

    // Methods
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
    applyReplicableTemplate,
    printTickets,
    fallbackToBrowserPrint,
    printWithBluetooth,
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

    // Page
    page,
  };
}
