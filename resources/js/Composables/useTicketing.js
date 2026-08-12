/**
 * useTicketing — Business logic for the seller ticketing page.
 *
 * Extracts all the common business logic (state, seat map, booking flow,
 * Bluetooth printing, WebSocket, zoom/pan and trip creation.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import BluetoothPrinter from '@/Services/BluetoothPrinter.js';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import { toastStore } from '@/Stores/toastStore.js';
import { i18n } from '@/i18n.js';
import {
  buildTripCreationDestinationOptions,
  buildTripCreationRouteOptions,
} from '@/Support/tripCreationDestinations.js';

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

  // =============================================
  // Aller-retour (Phase 3)
  // =============================================
  // Point 4 : le flag serveur gouverne la fonctionnalité côté interface.
  const roundTripSalesEnabled = computed(() => props.roundTripSalesEnabled !== false);
  const journeyType = ref('one_way');
  // Produit : UN SEUL mode de retour — le client fixe une DATE, l'heure du
  // retour sera déterminée à la gare le jour du départ.
  const returnMode = ref('date_flexible');
  // Point 3 : identité Okohi VÉRIFIÉE (numéro canonique retourné par le
  // serveur après vérification). La saisie brute du vendeur vit dans
  // BookingModal (okohiCardNumber) et n'est JAMAIS transmise telle quelle.
  const verifiedOkohiCustomerNumber = ref(null);
  const returnScheduleId = ref('');
  const returnDate = ref('');
  const returnTime = ref('');

  // Point 4 : flag désactivé → jamais d'aller-retour côté interface, même si
  // un état précédent persistait (changement de tenant en session).
  watch(roundTripSalesEnabled, (enabled) => {
    if (!enabled && journeyType.value !== 'one_way') {
      journeyType.value = 'one_way';
      returnMode.value = '';
      returnScheduleId.value = '';
      returnDate.value = '';
      returnTime.value = '';
    }
  }, { immediate: true });

  const returnSchedules = computed(() => props.returnSchedules || []);

  // Remise globale aller-retour (montant fixe en FCFA) configurée dans les
  // réglages : soustraite du total normal (aller + retour) quel que soit le
  // trajet. 0 = aucune remise.
  const roundTripDiscountAmount = computed(() => Number(props.roundTripDiscountAmount) || 0);

  // Tarif retour inverse (B → A) : tarif direct de la paire inverse, sinon
  // le tarif bidirectionnel de l'aller (même prix dans les deux sens).
  const returnFareAmount = computed(() => {
    if (!selectedFare.value) return 0;
    const from = selectedFare.value.to_station_id;
    const to = selectedFare.value.from_station_id;

    const direct = (props.routeFares || []).find(
      (fare) => fare.from_station_id === from && fare.to_station_id === to
    );
    if (direct?.amount != null) return Number(direct.amount) || 0;

    const viaConnections = (props.connectionFares || []).find(
      (fare) => fare.from_station_id === from && fare.to_station_id === to
    );
    if (viaConnections?.amount != null) return Number(viaConnections.amount) || 0;

    // Tarif bidirectionnel de l'aller : valable dans les deux sens.
    if (selectedFare.value.is_bidirectional) {
      return Number(selectedFare.value.amount) || 0;
    }

    return 0;
  });

  // Prix par passager selon le type de billet (jamais un simple aller × 2
  // quand un tarif retour inverse existe — point L). En aller-retour, la
  // remise globale est soustraite du total normal.
  const perTicketAmount = computed(() => {
    if (!selectedFare.value) return 0;
    if (journeyType.value === 'round_trip') {
      const oneWay = Number(selectedFare.value.amount) || 0;
      const back = returnFareAmount.value;
      const normalTotal = oneWay + (back > 0 ? back : oneWay);
      return Math.max(0, normalTotal - roundTripDiscountAmount.value);
    }
    return Number(selectedFare.value.amount) || 0;
  });

  const roundTripSavings = computed(() => {
    if (journeyType.value !== 'round_trip' || roundTripDiscountAmount.value <= 0) return 0;
    return roundTripDiscountAmount.value;
  });

  // Programmes de retour compatibles (retour : B → A).
  const compatibleReturnSchedules = computed(() => {
    if (!selectedFare.value) return [];
    const from = selectedFare.value.to_station_id;
    const to = selectedFare.value.from_station_id;
    return returnSchedules.value.filter(
      (schedule) =>
        schedule.origin_station_id === from &&
        schedule.destination_station_id === to
    );
  });

  const resetRoundTripState = () => {
    journeyType.value = 'one_way';
    returnMode.value = 'date_flexible';
    returnScheduleId.value = '';
    returnDate.value = '';
    returnTime.value = '';
  };

  // Create trip modal
  const showCreateTripModal = ref(false);
  const editingTripId = ref(null);
  const isEditingTrip = computed(() => editingTripId.value !== null);
  const createTripForm = ref({
    code: '',
    route_id: '',
    origin_station_id: '',
    destination_station_id: '',
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
  const activeOkohiRequest = ref(null);
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
  // Print Queue
  // =============================================
  const printQueue = ref([]);
  const printQueueRunning = ref(false);
  let printQueueSequence = 0;

  const updatePrintEntry = (entry, changes) => {
    Object.assign(entry, changes);
    printQueue.value = [...printQueue.value];
  };

  const schedulePrintedEntryCleanup = (entryId) => {
    setTimeout(() => {
      printQueue.value = printQueue.value.filter(entry => entry.id !== entryId);
    }, 15000);
  };

  const hydratePrintEntryTicketNumber = async (entry) => {
    if (!entry || entry.ticketNumber) return;

    try {
      const response = await axios.get(route('seller.tickets.show-data', { ticket: entry.ticketId }));
      if (response.data?.ticket_number) {
        updatePrintEntry(entry, { ticketNumber: response.data.ticket_number });
      }
    } catch {
      // Printing remains available even if the display label cannot be hydrated.
    }
  };

  const enqueuePrint = (ticket) => {
    const ticketId = typeof ticket === 'object' ? ticket.id : ticket;
    const ticketNumber = typeof ticket === 'object' ? ticket.ticket_number : null;
    const existing = printQueue.value.find(entry => String(entry.ticketId) === String(ticketId));
    if (existing) {
      if (!existing.ticketNumber && ticketNumber) {
        updatePrintEntry(existing, { ticketNumber });
      }
      return existing;
    }

    const entry = {
      id: `print-${Date.now()}-${++printQueueSequence}`,
      ticketId,
      ticketNumber,
      status: useBluetoothPrinter.value ? 'pending' : 'ready',
      error: null,
    };
    printQueue.value = [...printQueue.value, entry];
    if (!entry.ticketNumber) hydratePrintEntryTicketNumber(entry);
    if (entry.status === 'pending') processPrintQueue();
    return entry;
  };

  const processPrintQueue = async () => {
    if (printQueueRunning.value) return;
    printQueueRunning.value = true;

    try {
      while (printQueue.value.some(e => e.status === 'pending')) {
        const entry = printQueue.value.find(e => e.status === 'pending');
        if (!entry) break;

        updatePrintEntry(entry, { status: 'printing', error: null });

        try {
          const connected = await ensureBluetoothPrinterConnected({ allowPrompt: false });
          if (!connected) {
            throw new Error(i18n.global.t('composable.ticketing.bluetooth_unavailable'));
          }
          await printWithBluetooth(entry.ticketId);
          updatePrintEntry(entry, { status: 'printed' });
          toastStore.success(i18n.global.t('composable.ticketing.ticket_printed_success'));
          schedulePrintedEntryCleanup(entry.id);
        } catch (error) {
          updatePrintEntry(entry, {
            status: 'failed',
            error: error?.message || i18n.global.t('composable.ticketing.bluetooth_print_failed'),
          });
        }
      }
    } finally {
      printQueueRunning.value = false;
    }
  };

  const retryPrint = (entryId) => {
    const entry = printQueue.value.find(candidate => candidate.id === entryId);
    if (!entry || ['pending', 'printing'].includes(entry.status)) return;
    updatePrintEntry(entry, {
      status: useBluetoothPrinter.value ? 'pending' : 'ready',
      error: null,
    });
    if (entry.status === 'pending') processPrintQueue();
  };

  const printInBrowser = (entryId) => {
    const entry = printQueue.value.find(candidate => candidate.id === entryId);
    if (!entry) return;
    const opened = fallbackToBrowserPrint(entry.ticketId);
    if (!opened) {
      updatePrintEntry(entry, {
        status: 'failed',
        error: i18n.global.t('composable.ticketing.browser_blocked_print'),
      });
      return;
    }
    updatePrintEntry(entry, { status: 'printed', error: null });
    schedulePrintedEntryCleanup(entry.id);
  };

  const dismissPrintEntry = (entryId) => {
    printQueue.value = printQueue.value.filter(entry => entry.id !== entryId);
  };

  const printTickets = (tickets) => tickets.map(ticket => enqueuePrint(ticket));

  // =============================================
  // Bluetooth Printer
  // =============================================
  const bluetoothPrinter = new BluetoothPrinter();
  const useBluetoothPrinter = ref(localStorage.getItem('use_bluetooth_printer') === 'true');
  const bluetoothPrinterConnected = ref(false);
  const bluetoothPrinterName = ref(null);

  const syncBluetoothStatus = () => {
    const status = bluetoothPrinter.getStatus();
    bluetoothPrinterConnected.value = status.connected;
    bluetoothPrinterName.value = status.deviceName;
  };

  const connectBluetoothPrinter = async () => {
    try {
      bluetoothPrinter.setDisconnectCallback(() => {
        bluetoothPrinterConnected.value = false;
        bluetoothPrinterName.value = null;
      });
      await bluetoothPrinter.connect();
      syncBluetoothStatus();
      toastStore.success(i18n.global.t('composable.ticketing.printer_connected', { name: bluetoothPrinterName.value }));
    } catch (error) {
      toastStore.error(i18n.global.t('composable.ticketing.printer_connection_failed'));
    }
  };

  const disconnectBluetoothPrinter = () => {
    bluetoothPrinter.setDisconnectCallback(null);
    bluetoothPrinter.disconnect();
    bluetoothPrinterConnected.value = false;
    bluetoothPrinterName.value = null;
  };

  const ensureBluetoothPrinterConnected = async ({ allowPrompt = false } = {}) => {
    syncBluetoothStatus();
    if (bluetoothPrinterConnected.value) return true;
    if (!bluetoothPrinter.isSupported()) return false;
    if (bluetoothPrinter.device) {
      const ok = await bluetoothPrinter.reconnect();
      if (ok) {
        syncBluetoothStatus();
        return true;
      }
    }
    const restored = await bluetoothPrinter.restoreAuthorizedDevice();
    if (restored) {
      syncBluetoothStatus();
      return true;
    }
    if (!allowPrompt) return false;
    await bluetoothPrinter.connect();
    syncBluetoothStatus();
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
      baggage_policy_message_2: "Les objets de valeur doivent faire l'objet d'une declaration en sus de l'enregistrement avec pieces justificatives avant le depart.",
      print_qr_code: true,
      qr_code_base_url: null,
    };
    const ticketData = {
      ticket_number: ticket.ticket_number || 'N/A',
      route_name: ticket.trip?.route?.name || 'N/A',
      from_stop: ticket.from_station?.name || 'N/A',
      to_stop: ticket.final_destination_station?.name || ticket.to_station?.name || 'N/A',
      transfer_stop: ticket.final_destination_station
        ? (ticket.transfer_station?.name || ticket.to_station?.name || null)
        : null,
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
      toastStore.warning(i18n.global.t('composable.ticketing.allow_popups'));
      return false;
    }
    return true;
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
    const idsFromProps = assignedStationIds.value;
    if (idsFromProps && idsFromProps.length > 0) {
      return idsFromProps;
    }
    const assignments = page.props.auth.user?.station_assignments || page.props.auth.user?.stationAssignments || [];
    return [...new Set(assignments
      .filter(a => a.active !== false)
      .map(assignment => assignment.station_id || assignment.station?.id)
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

  const sortTripsChronologically = (tripsList) => {
    const now = Date.now();
    return [...tripsList].sort((a, b) => {
      const aTime = new Date(a.departure_at || 0).getTime();
      const bTime = new Date(b.departure_at || 0).getTime();
      const aPast = aTime < now;
      const bPast = bTime < now;
      if (aPast !== bPast) return aPast ? 1 : -1;
      return aTime - bTime;
    });
  };

  const applyRealtimeTripCreated = (e = {}) => {
    if (hasRecentlyProcessedRealtimeEvent({ ...e, action: 'trip.created' })) return;

    if (!e.trip?.id) return;

    const userRole = page.props.auth.user?.role;
    if (userRole !== 'admin') {
      const sellerStationIds = assignedStationIds.value;
      if (sellerStationIds.length > 0) {
        const tripStationIndices = buildTripStationIndices(e.trip);
        const servedStationIds = Object.keys(tripStationIndices);
        const originId = e.trip.origin_station_id || e.trip.route?.origin_station_id;
        const destId = e.trip.destination_station_id || e.trip.route?.destination_station_id;
        const allTripStations = [...new Set([...servedStationIds, originId, destId].filter(Boolean).map(String))];
        const servesSeller = sellerStationIds.some(id => allTripStations.includes(String(id)));
        if (!servesSeller) return;
      }
    }

    if (!trips.value.some(t => isSameTripId(t.id, e.trip.id))) {
      trips.value = sortTripsChronologically([...trips.value, e.trip]);
    }

    ticketingStore.pulseTrip(e.trip.id, {
      action: 'trip.created',
      duration: 30000,
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

  const okohiNotifications = ref([]);

  const dismissOkohiNotif = (id) => {
    okohiNotifications.value = okohiNotifications.value.filter(n => n.id !== id);
  };

  const applyRealtimeOkohiClaimUpdate = (e = {}) => {
    fetchSeatMap({ silent: true });
    ticketingStore.notifySeatMapChanged();

    const status = e.status || e.payload?.status || 'approved';
    const localStatus = e.payload?.local_status || status;
    const seatNum = e.seat_number || e.payload?.seat_number;
    const claimId = e.claim_id;

    if (localStatus === 'approved_pending_cash') {
      toastStore.success(i18n.global.t('composable.ticketing.okohi_approved_cash', { seat: seatNum || '' }));
      okohiNotifications.value.unshift({
        id: claimId || Date.now(),
        seatNumber: seatNum,
        tripId: e.trip_id,
        status: 'approved_pending_cash',
        message: i18n.global.t('composable.ticketing.okohi_approved_cash_notification', { seat: seatNum ? '#' + seatNum : '' }),
      });
    } else if (localStatus === 'confirmed') {
      toastStore.success(i18n.global.t('composable.ticketing.okohi_confirmed_ticket', { seat: seatNum || '' }));
      okohiNotifications.value.unshift({
        id: claimId || Date.now(),
        seatNumber: seatNum,
        tripId: e.trip_id,
        status: 'confirmed',
        message: i18n.global.t('composable.ticketing.okohi_ticket_issued_notification', { seat: seatNum ? '#' + seatNum : '' }),
      });
    } else if (status === 'rejected' || status === 'expired') {
      const requestKey = status === 'expired'
        ? 'composable.ticketing.okohi_request_expired'
        : 'composable.ticketing.okohi_request_rejected';
      toastStore.warning(i18n.global.t(requestKey, { seat: seatNum || '' }));
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

    const tenantId = page.props.tenant?.id || page.props.auth?.user?.tenant_id;
    if (tenantId) {
      echo.channel(`tenant.${tenantId}.okohi`)
        .listen('.claim.updated', applyRealtimeOkohiClaimUpdate);
      currentStationChannels.value = [...currentStationChannels.value, `tenant.${tenantId}.okohi`];
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
  // Computed: Trip & Fare helpers
  // =============================================
  const bookingSidePanelOpen = computed(() => !!selectedTripId.value);

  const currentTrip = computed(() => trips.value.find(trip => trip.id === selectedTripId.value));

  const assignedStationIds = computed(() => {
    const ids = props.assignedStationIds?.length
      ? props.assignedStationIds
      : (props.assignedStationId ? [props.assignedStationId] : []);
    return [...new Set(ids.filter(Boolean).map(id => String(id)))];
  });

  const isTripPassed = computed(() => {
    if (!currentTrip.value) return false;

    if (['arrived', 'cancelled'].includes(currentTrip.value.status)) {
      return true;
    }

    // Boarding, delayed and en-route trips remain sellable according to the
    // station/seat policy. The scheduled time alone must not disable fares.
    if (['boarding', 'delayed', 'departed'].includes(currentTrip.value.status)) {
      return false;
    }

    return new Date(currentTrip.value.departure_at) < new Date();
  });

  const isTripDeparted = computed(() => currentTrip.value?.status === 'departed');

  const hasAssignedStation = (stationId) => {
    if (!stationId) return false;
    return assignedStationIds.value.includes(String(stationId));
  };

  const operationalStationId = computed(() => {
    const activeStationId = currentTrip.value?.active_sales_station_id;
    if (isTripDeparted.value && hasAssignedStation(activeStationId)) {
      return activeStationId;
    }

    const fareStationId = selectedFare.value?.from_station_id;
    if (hasAssignedStation(fareStationId)) {
      return fareStationId;
    }

    const originStationId = currentTrip.value?.origin_station_id
      || currentTrip.value?.route?.origin_station_id;
    if (hasAssignedStation(originStationId)) {
      return originStationId;
    }

    return assignedStationIds.value[0] || null;
  });

  const isWaitingForSalesTurn = computed(() => {
    if (!isTripDeparted.value) return false;
    const activeStation = currentTrip.value?.active_sales_station_id;
    if (!activeStation) return false;
    return !hasAssignedStation(activeStation);
  });

  const isSalesClosedForSeller = computed(() => {
    if (!currentTrip.value) return false;
    if (assignedStationIds.value.length === 0) return false;

    const originStationId = currentTrip.value.origin_station_id
      || currentTrip.value.route?.origin_station_id;
    const isAtOrigin = originStationId
      && String(operationalStationId.value) === String(originStationId);

    if (isTripDeparted.value) {
      return isWaitingForSalesTurn.value;
    }

    return !isAtOrigin && currentTrip.value.sales_control === 'closed';
  });

  const getStationFreedSeatNumbers = (fare) => {
    if (!fare) return [];

    const stationSeats = seatMap.value?.freed_seats_by_station?.[fare.from_station_id] || [];

    return [...new Set(stationSeats.map(sn => Number(sn)).filter(Number.isFinite))];
  };

  const hasFreedSeatsForSeller = computed(() => {
    if (!currentTrip.value) return false;
    if (isWaitingForSalesTurn.value) return false;
    return availableFares.value.some(fare => getStationFreedSeatNumbers(fare).length > 0);
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

    if (isWaitingForSalesTurn.value) {
      return [];
    }

    // A closed trip only allows seats released by passengers getting off at
    // this station. Empty seats must not be mistaken for released seats.
    const originStationId = currentTrip.value?.origin_station_id
      || currentTrip.value?.route?.origin_station_id;
    const fareSalesClosed = !isTripDeparted.value
      && currentTrip.value?.sales_control === 'closed'
      && String(fare.from_station_id) !== String(originStationId);

    if (fareSalesClosed || isSalesClosedForSeller.value) {
      return getStationFreedSeatNumbers(fare);
    }

    const stationSeats = seatMap.value?.sellable_seats_by_station?.[fare.from_station_id]
      || seatMap.value?.freed_seats_by_station?.[fare.from_station_id]
      || [];

    // At the origin, and at intermediate stations when simultaneous sales
    // are open, every seat empty on the requested segment is sellable. The
    // closed/intermediate case returned above remains restricted to freed
    // seats only.
    const seats = [...stationSeats, ...emptySeatNumbers.value];

    return [...new Set(seats.map(sn => Number(sn)).filter(Number.isFinite))];
  };

  const getFreedSeatCountForFare = (fare) => getStationFreedSeatNumbers(fare).length;

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
          if (seat?.type === 'seat' && !seat.isOccupied && !seat.isOkohiPending && allowedSeats.has(Number(seat.number))) {
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
      reason: i18n.global.t('composable.ticketing.seat_available_reason'),
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
    if (assignedStationIds.value.length === 0) return false;

    if (isWaitingForSalesTurn.value) return true;
    const originStationId = currentTrip.value.origin_station_id
      || currentTrip.value.route?.origin_station_id;
    const fareSalesClosed = !isTripDeparted.value
      && currentTrip.value.sales_control === 'closed'
      && String(fare.from_station_id) !== String(originStationId);
    if (!fareSalesClosed && !isSalesClosedForSeller.value) return false;

    const stationSeats = getStationFreedSeatNumbers(fare);

    return !Array.isArray(stationSeats) || stationSeats.length <= 0;
  };

  const seatsToBook = computed(() => {
    if (ticketQuantity.value <= 1) {
      return selectedSeatNumber.value ? [selectedSeatNumber.value] : [];
    }
    if (selectedSeatNumber.value !== null) {
      const manualSeat = Number(selectedSeatNumber.value);
      const rest = [...new Set(suggestedSeats.value
        .map(s => Number(s.seat_number))
        .filter(seatNumber => Number.isFinite(seatNumber) && seatNumber !== manualSeat))]
        .slice(0, ticketQuantity.value - 1);
      return [manualSeat, ...rest];
    }
    return [...new Set(suggestedSeats.value
      .map(s => Number(s.seat_number))
      .filter(Number.isFinite))]
      .slice(0, ticketQuantity.value);
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

    if (tripOriginIndex !== -1 && tripDestinationIndex !== -1) {
      if (tripOriginIndex <= tripDestinationIndex) {
        // Forward direction
        const sliced = orderedStationIds.slice(tripOriginIndex, tripDestinationIndex + 1);
        return sliced.reduce((map, stationId, index) => {
          map[stationId] = index;
          return map;
        }, {});
      } else {
        // Reversed direction
        const sliced = orderedStationIds.slice(tripDestinationIndex, tripOriginIndex + 1);
        sliced.reverse();
        return sliced.reduce((map, stationId, index) => {
          map[stationId] = index;
          return map;
        }, {});
      }
    }

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

  const isAdmin = computed(() => ['admin', 'superadmin', 'super_admin', 'executive'].includes(page.props.auth.user?.role));

  const availableFares = computed(() => {
    if (!currentTrip.value) return [];
    const routeObj = currentTrip.value.route;
    const stationIndexMap = buildTripStationIndices(currentTrip.value);
    const totalStations = Object.keys(stationIndexMap).length;
    const allowedStationIds = new Set(Object.keys(stationIndexMap));

    const effectiveOriginStationId = assignedStationIds.value[0]
      || currentTrip.value.origin_station_id
      || routeObj?.origin_station_id;

    const filtered = props.routeFares.filter(fare => {
      const fromStation = fare.from_station || fare.fromStation;
      const toStation = fare.to_station || fare.toStation;
      const fareFromId = fare.from_station_id || fromStation?.id;
      const fareToId = fare.to_station_id || toStation?.id;
      if (!fareFromId || !fareToId) return false;
      if (!allowedStationIds.has(fareFromId) || !allowedStationIds.has(fareToId)) return false;

      if (!isAdmin.value && assignedStationIds.value.length > 0 && !hasAssignedStation(fareFromId)) {
        return false;
      }

      if (isTripDeparted.value && currentTrip.value.active_sales_station_id) {
        if (String(fareFromId) !== String(currentTrip.value.active_sales_station_id)) {
          return false;
        }
      } else if (effectiveOriginStationId && String(fareFromId) !== String(effectiveOriginStationId)) {
        return false;
      }

      const fromIdx = stationIndexMap[fareFromId];
      const toIdx = stationIndexMap[fareToId];
      if (fromIdx !== undefined && toIdx !== undefined) {
        return fromIdx < toIdx;
      }
      return false;
    });

    const directFares = [...filtered].sort((a, b) => {
      const aDestinationId = a.to_station_id || a.to_station?.id || a.toStation?.id;
      const bDestinationId = b.to_station_id || b.to_station?.id || b.toStation?.id;
      return (stationIndexMap[aDestinationId] ?? Number.MAX_SAFE_INTEGER)
        - (stationIndexMap[bDestinationId] ?? Number.MAX_SAFE_INTEGER);
    }).map((fare) => {
      const fromStation = fare.from_station || fare.fromStation;
      const toStation = fare.to_station || fare.toStation;
      const fareFromId = fare.from_station_id || fromStation?.id;
      const fareToId = fare.to_station_id || toStation?.id;
      const fromIdx = stationIndexMap[fareFromId];
      const toIdx = stationIndexMap[fareToId];
      const palette = getStationColor(fromIdx, toIdx, totalStations);

      // A connection exists only when another configured route passes through
      // this station and opens access to a destination not already served
      // after it by the current trip. Timetables and seats are intentionally
      // not checked here: this is the configured connection network.
      const hasConnections = !!currentTrip.value.allows_open_connections && (props.connectionRoutes || []).some(route => {
        const currentRouteId = currentTrip.value.route_id || routeObj?.id;
        if (route.id === currentRouteId) return false;

        const stops = [...(route.route_stop_orders || route.routeStopOrders || [])]
          .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
        const stationIds = [
          route.origin_station_id,
          ...stops.map(stop => stop.station_id || stop.station?.id),
          route.destination_station_id,
        ].filter(Boolean);

        if (!stationIds.includes(fareToId)) return false;

        return stationIds.some((candidateId) => {
          if (candidateId === fareToId || candidateId === fareFromId) return false;
          const candidateIndexOnCurrentTrip = stationIndexMap[candidateId];
          return candidateIndexOnCurrentTrip === undefined || candidateIndexOnCurrentTrip <= toIdx;
        });
      });

      return {
        ...fare,
        color: palette.bg,
        textColor: palette.fg,
        mutedColor: palette.muted,
        has_connections: hasConnections,
      };
    });

    return directFares;
  });

  const getAssignedStationPalette = () => {
    if (props.assignedStationColor?.bg) {
      return props.assignedStationColor;
    }

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
    const stationIds = selectedFare.value?.from_station_id
      ? [selectedFare.value.from_station_id]
      : (operationalStationId.value ? [operationalStationId.value] : assignedStationIds.value);
    if (stationIds.length === 0 || isWaitingForSalesTurn.value) return [];
    const seatsByStation = isSalesClosedForSeller.value
      ? (seatMap.value?.freed_seats_by_station || {})
      : (seatMap.value?.sellable_seats_by_station || {});
    const aggregated = new Set();
    stationIds.forEach((sid) => {
      const seats = seatsByStation[sid] || [];
      seats.forEach((sn) => aggregated.add(Number(sn)));
    });

    return [...aggregated].filter((sn) => Number.isFinite(sn));
  });

  const maxSellableQuantity = computed(() => {
    if (!selectedFare.value) return 0;

    const allowedSeats = new Set(getStationSellableSeatNumbers(selectedFare.value));
    if (!seatMap.value?.seat_map) return allowedSeats.size;

    const mapData = seatMap.value.seat_map;
    const rows = Array.isArray(mapData)
      ? mapData
      : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];
    let count = 0;

    rows.forEach((row) => {
      row.forEach((seat) => {
        if (seat?.type === 'seat'
          && allowedSeats.has(Number(seat.number))
          && !seat.isOccupied
          && !seat.isOkohiPending) {
          count += 1;
        }
      });
    });

    return count;
  });

  const currentStationFreedSeatNumbers = computed(() => {
    const stationIds = selectedFare.value?.from_station_id
      ? [selectedFare.value.from_station_id]
      : (operationalStationId.value ? [operationalStationId.value] : assignedStationIds.value);
    if (stationIds.length === 0) return [];

    if (isTripDeparted.value) {
      const stationIndices = buildTripStationIndices(currentTrip.value);
      const activeStationIndex = stationIndices[currentTrip.value?.active_sales_station_id];

      const validStationIds = stationIds.filter((sid) => {
        const stationIndex = stationIndices[sid];
        if (stationIndex === undefined || activeStationIndex === undefined) return false;
        return stationIndex > activeStationIndex;
      });

      if (validStationIds.length === 0) return [];

      const aggregated = new Set();
      validStationIds.forEach((sid) => {
        const seats = seatMap.value?.freed_seats_by_station?.[sid] || [];
        seats.forEach((sn) => aggregated.add(Number(sn)));
      });
      return [...aggregated].filter((sn) => Number.isFinite(sn));
    }

    const aggregated = new Set();
    stationIds.forEach((sid) => {
      const seats = seatMap.value?.freed_seats_by_station?.[sid] || [];
      seats.forEach((sn) => aggregated.add(Number(sn)));
    });
    return [...aggregated].filter((sn) => Number.isFinite(sn));
  });

  const currentStationSellableSeatBorderColor = computed(() => getAssignedStationPalette()?.bg || null);

  const pendingOkohiRequestsForCurrentTrip = ref([]);
  const pendingOkohiCountdowns = ref({});
  let pendingOkohiPollInterval = null;
  let pendingOkohiCountdownInterval = null;

  const computePendingOkohiCountdowns = () => {
    const map = {};
    const now = Date.now();
    for (const req of pendingOkohiRequestsForCurrentTrip.value) {
      if (req.expires_at) {
        map[req.id] = Math.max(0, Math.floor((new Date(req.expires_at).getTime() - now) / 1000));
      } else {
        map[req.id] = 300;
      }
    }
    return map;
  };

  const startPendingOkohiCountdown = () => {
    stopPendingOkohiCountdown();
    pendingOkohiCountdowns.value = computePendingOkohiCountdowns();
    pendingOkohiCountdownInterval = setInterval(() => {
      pendingOkohiCountdowns.value = computePendingOkohiCountdowns();
    }, 1000);
  };

  const stopPendingOkohiCountdown = () => {
    if (pendingOkohiCountdownInterval) {
      clearInterval(pendingOkohiCountdownInterval);
      pendingOkohiCountdownInterval = null;
    }
  };

  const startPendingOkohiPolling = (tripId) => {
    stopPendingOkohiPolling();
    if (!tripId) return;
    const poll = async () => {
      try {
        const response = await axios.get(route('seller.okohi.requests.pending-trip'), {
          params: { trip_id: tripId },
        });
        const freshData = response.data || [];
        const oldIds = new Set(pendingOkohiRequestsForCurrentTrip.value.map(r => r.id));
        const newIds = new Set(freshData.map(r => r.id));
        const changed = freshData.length !== pendingOkohiRequestsForCurrentTrip.value.length
          || freshData.some(r => !oldIds.has(r.id))
          || pendingOkohiRequestsForCurrentTrip.value.some(r => !newIds.has(r.id));
        if (changed) {
          pendingOkohiRequestsForCurrentTrip.value = freshData;
          pendingOkohiCountdowns.value = computePendingOkohiCountdowns();
        }
      } catch (error) {
        console.error('Failed to poll pending Okohi requests for trip:', error);
      }
    };
    poll();
    startPendingOkohiCountdown();
    pendingOkohiPollInterval = setInterval(poll, 5000);
  };

  const stopPendingOkohiPolling = () => {
    stopPendingOkohiCountdown();
    if (pendingOkohiPollInterval) {
      clearInterval(pendingOkohiPollInterval);
      pendingOkohiPollInterval = null;
    }
  };

  const fetchPendingOkohiRequestsForTrip = async () => {
    if (!selectedTripId.value) {
      pendingOkohiRequestsForCurrentTrip.value = [];
      return;
    }
    try {
      const response = await axios.get(route('seller.okohi.requests.pending-trip'), {
        params: { trip_id: selectedTripId.value },
      });
      pendingOkohiRequestsForCurrentTrip.value = response.data || [];
    } catch (error) {
      console.error('Failed to fetch pending Okohi requests for trip:', error);
    }
  };

  const openPendingOkohiModal = (req) => {
    activeOkohiRequest.value = req;
    selectedSeatNumber.value = req.seat_number;
    showDestinationModal.value = false;
    resetPassengerModalState();
    showPassengerModal.value = true;
  };

  // =============================================
  // API Methods
  // =============================================
  function fetchSeatMap({ silent = false } = {}) {
    if (!selectedTripId.value) return Promise.resolve();
    if (currentTrip.value && !currentTrip.value.vehicle_id && !currentTrip.value.vehicle) {
      seatMap.value = null;
      errors.value.seatmap = null;
      if (!silent) seatMapLoading.value = false;
      return Promise.resolve();
    }

    if (!silent) seatMapLoading.value = true;

    fetchPendingOkohiRequestsForTrip();

    const params = { _t: new Date().getTime() };
    if (sendsSegmentParams && selectedFare.value) {
      params.from_station_id = selectedFare.value.from_station_id;
      params.to_station_id = selectedFare.value.to_station_id;
    }

    return axios.get(route('seller.trips.seatmap', { trip: selectedTripId.value }), { params })
      .then((response) => { seatMap.value = response.data; })
      .catch((error) => {
        if (error.response?.status === 409 && error.response?.data?.vehicle_required) {
          seatMap.value = null;
          errors.value.seatmap = null;
          return;
        }

        console.error('Erreur lors de la récupération du plan de salle:', error);
        if (!silent) errors.value.seatmap = i18n.global.t('composable.ticketing.seatmap_load_error');
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
        suggestedSeats.value = apiSuggestions.filter(s => {
          const seatNum = Number(s.seat_number);
          const mapData = seatMap.value?.seat_map;
          const rows = Array.isArray(mapData) ? mapData : [...(mapData?.lower_deck || []), ...(mapData?.upper_deck || [])];
          for (const row of rows) {
            const seat = row.find(sg => Number(sg.number) === seatNum);
            if (seat && (seat.isOccupied || seat.isOkohiPending)) return false;
          }
          return true;
        });
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
    resetRoundTripState();
  };

  const openPassengerModal = () => {
    activeOkohiRequest.value = null;
    resetPassengerModalState();
    showPassengerModal.value = true;
  };

  const openDestinationModalForSeat = (seatNumber) => {
    activeOkohiRequest.value = null;
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
    if (selectedSeatNumber.value !== null) {
      openPassengerModal();
    }
  };

  const initiateBookingFlow = (seatNumber) => {
    if (!selectedFare.value) {
      openDestinationModalForSeat(seatNumber);
      return;
    }
    selectedSeatNumber.value = seatNumber;
    openPassengerModal();
  };

  // Vente sans car (point D) : voyage sur capacité planifiée, siège différé.
  // selectedSeatNumber reste null, quantitySale = true, la vente transmet
  // `quantity` sans tableau `seats`.
  const quantitySale = ref(false);

  const initiateQuantityBookingFlow = () => {
    const trip = currentTrip.value;
    if (!trip) return;
    quantitySale.value = true;
    selectedSeatNumber.value = null;
    if (!selectedFare.value) {
      openDestinationModalForSeat(null);
      return;
    }
    openPassengerModal();
  };

  const cancelQuantityBooking = () => {
    quantitySale.value = false;
    selectedSeatNumber.value = null;
  };

  const canSellWithoutVehicle = computed(() => {
    const trip = currentTrip.value;
    return !!trip && trip.sell_mode === 'quantity' && trip.sales_ready;
  });

  const handleSeatClick = (seatNumber) => {
    if (!seatMap.value || isTripPassed.value || availableFares.value.length === 0) return;

    let seatObj = null;
    const mapData = seatMap.value.seat_map;
    const rows = Array.isArray(mapData) ? mapData : [...(mapData.lower_deck || []), ...(mapData.upper_deck || [])];

    for (const row of rows) {
      const found = row.find(s => s.number === seatNumber);
      if (found) { seatObj = found; break; }
    }

    if (seatObj?.isOkohiPending) {
      const pendingRequest = pendingOkohiRequestsForCurrentTrip.value.find(
        request => Number(request.seat_number) === Number(seatNumber)
      ) || {
        id: seatObj.okohiRewardRequestId,
        seat_number: seatNumber,
        status: 'pending',
      };
      openPendingOkohiModal(pendingRequest);
      return;
    }

    const seatNum = Number(seatNumber);
    const canBookThisSeat = !isSalesClosedForSeller.value || freedSeatNumbersForSeller.value.has(seatNum);

    if (seatObj?.isOccupied && !canBookThisSeat) {
      if (['admin', 'supervisor'].includes(page.props.auth.user.role)) {
        selectedTicketForInspection.value = {
          id: 'req-' + seatObj.ticket_id,
          ticket_number: seatObj.ticket_number || null,
          seller_name: seatObj.seller_name || null,
          reason: i18n.global.t('composable.ticketing.inspection_reason_direct'),
          created_at: seatObj.created_at || null,
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

  let restoringBookingContext = false;

  const confirmBooking = async () => {
    // The seat-first flow opens the modal before a destination is selected.
    if (!selectedFare.value) return;
    if (!quantitySale.value && selectedSeatNumber.value === null) return;
    if (!quantitySale.value && seatsToBook.value.length !== ticketQuantity.value) {
      toastStore.error(i18n.global.t('composable.ticketing.seat_selection_incomplete'));
      return;
    }
    if (seatFirstFlow.value && ticketQuantity.value > maxSellableQuantity.value) {
      toastStore.error(i18n.global.t('composable.ticketing.only_seats_sellable', { count: maxSellableQuantity.value }));
      return;
    }

    // Validate
    passengerFormErrors.value = {};
    if (showPassengerFields.value && passengerForm.value.name && passengerForm.value.name.trim().length < 2) {
      passengerFormErrors.value.name = i18n.global.t('composable.ticketing.name_min_length');
    }
    if (showPassengerFields.value && passengerForm.value.phone && !/^[0-9]{9,15}$/.test(passengerForm.value.phone.replace(/\s/g, ''))) {
      passengerFormErrors.value.phone = i18n.global.t('composable.ticketing.invalid_phone');
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

    const quantityValue = quantitySale.value ? (ticketQuantity.value || 1) : null;
    const totalAmt = quantitySale.value
      ? perTicketAmount.value * quantityValue
      : journeyType.value === 'round_trip'
        ? perTicketAmount.value * allSeats.length
        : (connectionFare?.amount ?? selectedFare.value.amount) * allSeats.length;

    const ticketData = {
      trip_id: selectedTripId.value,
      from_station_id: selectedFare.value.from_station_id,
      to_station_id: selectedFare.value.to_station_id,
      seats: quantitySale.value ? undefined : allSeats,
      quantity: quantityValue,
      amount: totalAmt,
      journey_type: journeyType.value,
      // Point 3 : identité Okohi VÉRIFIÉE retournée par le serveur — la
      // simple saisie du vendeur n'est JAMAIS transmise (voir BookingModal).
      ...(verifiedOkohiCustomerNumber.value ? { okohi_customer_number: verifiedOkohiCustomerNumber.value } : {}),
    };
    if (journeyType.value === 'round_trip') {
      // Mode unique : date fixée à la vente ; l'heure du retour est
      // déterminée à la gare le jour du départ (jamais transmise ici).
      ticketData.return_mode = 'date_flexible';
      if (returnDate.value) {
        ticketData.return_date = returnDate.value;
      }
    }
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

    // Snapshot context before optimistic mutations (for restoration on failure)
    const snapshot = {
      fare: selectedFare.value,
      selectedSeatNumber: selectedSeatNumber.value,
      seats: [...allSeats],
      quantity: ticketQuantity.value,
      suggestions: suggestedSeats.value.map(suggestion => ({ ...suggestion })),
      finalDestinationStationId: finalDestinationStationId.value,
      connectionRouteId: connectionRouteId.value,
      passengerForm: { ...passengerForm.value },
      passengerFormErrors: { ...passengerFormErrors.value },
      showPassengerFields: showPassengerFields.value,
      seatFirstFlow: seatFirstFlow.value,
      tripAvailable: (() => {
        const t = trips.value.find(t => t.id === selectedTripId.value);
        return t ? t.available_seats : null;
      })(),
    };

    // Optimistic: close modal + mark seats occupied
    const fareColor = selectedFare.value?.color;
    showPassengerModal.value = false;
    if (!quantitySale.value) {
      allSeats.forEach(seat => markSeatOccupied(seat, fareColor));
      ticketingStore.notifySeatBooked(allSeats, fareColor);
    }
    selectedSeatNumber.value = null;
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
    ticketQuantity.value = 1;
    quantitySale.value = false;

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

    let saleSucceeded = false;
    let ticketIds = [];
    let ticketsToPrint = [];

    try {
      const response = await axios.post(route('seller.tickets.store'), ticketData);
      const data = response.data;
      ticketIds = data.ticket_ids || [];
      ticketsToPrint = Array.isArray(data.tickets) && data.tickets.length > 0
        ? data.tickets.map(ticket => ({
            id: ticket.id,
            ticket_number: ticket.ticket_number,
          }))
        : ticketIds;
      saleSucceeded = true;
    } catch (error) {
      // Restore context on sale failure
      allSeats.forEach(seat => revertSeatAvailable(seat));
      ticketingStore.notifySeatReverted(allSeats);
      restoringBookingContext = true;
      selectedFare.value = snapshot.fare;
      selectedSeatNumber.value = snapshot.selectedSeatNumber;
      ticketQuantity.value = snapshot.quantity;
      suggestedSeats.value = snapshot.suggestions;
      ticketingStore.setSuggestions(snapshot.suggestions);
      finalDestinationStationId.value = snapshot.finalDestinationStationId;
      connectionRouteId.value = snapshot.connectionRouteId;
      passengerForm.value = snapshot.passengerForm;
      passengerFormErrors.value = snapshot.passengerFormErrors;
      showPassengerFields.value = snapshot.showPassengerFields;
      seatFirstFlow.value = snapshot.seatFirstFlow;
      showDestinationModal.value = false;
      showPassengerModal.value = true;
      queueMicrotask(() => {
        restoringBookingContext = false;
      });
      const revertIdx = trips.value.findIndex(t => t.id === selectedTripId.value);
      if (revertIdx !== -1) {
        trips.value[revertIdx] = {
          ...trips.value[revertIdx],
          available_seats: snapshot.tripAvailable ?? (trips.value[revertIdx].available_seats || 0) + allSeats.length,
        };
      }
      const message = error.response?.data?.message || i18n.global.t('composable.ticketing.ticket_creation_error');
      toastStore.error(message);
    }

    if (saleSucceeded) {
      if (ticketsToPrint.length > 0) {
        printTickets(ticketsToPrint);
      }
      toastStore.success(i18n.global.t('composable.ticketing.sale_registered'));
      fetchSeatMap({ silent: true });
      ticketingStore.notifySeatMapChanged();
    }

    seatFirstFlow.value = false;
    ticketingStore.setShowSuggestions(true);
    processing.value = false;
  };

  const cancelBooking = () => {
    showPassengerModal.value = false;
    showDestinationModal.value = false;
    seatSelectionMode.value = false;
    seatFirstFlow.value = false;
    selectedFare.value = null;
    finalDestinationStationId.value = null;
    connectionRouteId.value = null;
    selectedSeatNumber.value = null;
    quantitySale.value = false;
    activeOkohiRequest.value = null;
    ticketingStore.selectSeat?.(null);
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
    ticketingStore.setShowSuggestions(true);
    fetchSeatMap({ silent: true });
  };

  const continueSalesAfterOkohiRequest = async () => {
    showPassengerModal.value = false;
    showDestinationModal.value = false;
    seatSelectionMode.value = false;
    seatFirstFlow.value = false;
    selectedSeatNumber.value = null;
    activeOkohiRequest.value = null;
    selectedFare.value = null;
    resetPassengerModalState();
    ticketingStore.selectSeat?.(null);
    suggestedSeats.value = [];
    ticketingStore.setSuggestions([]);
    ticketingStore.setShowSuggestions(true);
    ticketingStore.notifySeatMapChanged();

    await fetchSeatMap({ silent: true });
    ticketingStore.notifySeatMapChanged();
  };

  const handleOkohiSuccess = async (ticketId) => {
    toastStore.success(i18n.global.t('composable.ticketing.okohi_payment_validated'));
    showPassengerModal.value = false;
    selectedSeatNumber.value = null;
    activeOkohiRequest.value = null;
    selectedFare.value = null;
    ticketQuantity.value = 1;

    if (ticketId) {
      await printTickets([ticketId]);
    }

    fetchSeatMap({ silent: true });
    ticketingStore.notifySeatMapChanged();
  };

  // =============================================
  const applyReplicableTemplate = (template) => {
    if (!template) return;
    createTripForm.value.route_id = template.route_id;
    createTripForm.value.origin_station_id = template.origin_station_id || '';
    createTripForm.value.destination_station_id = template.destination_station_id || '';
    createTripForm.value.allows_open_connections = !!template.allows_open_connections;
    createTripForm.value.automatic_connection_allocation = template.automatic_connection_allocation;
    createTripForm.value.is_replicable = true;

    const currentDatePart = createTripForm.value.departure_at
      ? createTripForm.value.departure_at.split('T')[0]
      : new Date().toISOString().split('T')[0];

    createTripForm.value.departure_at = `${currentDatePart}T${template.time}`;
  };

  const openCreateTrip = () => {
    editingTripId.value = null;
    createTripErrors.value = {};
    createTripForm.value = {
      code: '',
      route_id: '',
      origin_station_id: props.assignedStationId || '',
      destination_station_id: '',
      vehicle_id: '',
      departure_at: '',
      status: 'scheduled',
      sales_control: 'closed',
      allows_open_connections: false,
      automatic_connection_allocation: null,
      is_replicable: false
    };
    showCreateTripModal.value = true;
  };

  const openEditTrip = (trip) => {
    if (!trip) return;
    editingTripId.value = trip.id;
    createTripErrors.value = {};
    const routeObj = props.routes?.find(r => r.id === trip.route_id);
    createTripForm.value = {
      code: trip.code || '',
      route_id: trip.route_id,
      origin_station_id: trip.origin_station_id || routeObj?.origin_station_id || '',
      destination_station_id: trip.destination_station_id || routeObj?.destination_station_id || '',
      vehicle_id: trip.vehicle_id || '',
      departure_at: trip.departure_at?.slice(0, 16) || '',
      status: trip.status || 'scheduled',
      sales_control: trip.sales_control || 'closed',
      allows_open_connections: !!trip.allows_open_connections,
      automatic_connection_allocation: trip.automatic_connection_allocation,
      is_replicable: !!trip.is_replicable,
    };
    showCreateTripModal.value = true;
  };

  // Trip Creation
  // =============================================
  const createTrip = () => {
    createTripProcessing.value = true;
    createTripErrors.value = {};
    const routeName = isEditingTrip.value ? 'seller.trips.update' : 'seller.trips.store';
    const routeParams = isEditingTrip.value ? { trip: editingTripId.value } : undefined;
    const url = route(routeName, routeParams);
    const options = {
      preserveState: true,
      onSuccess: () => {
        showCreateTripModal.value = false;
        editingTripId.value = null;
        createTripForm.value = {
          code: '',
          route_id: '',
          origin_station_id: props.assignedStationId || '',
          destination_station_id: '',
          vehicle_id: '',
          departure_at: '',
          status: 'scheduled',
          sales_control: 'closed',
          allows_open_connections: false,
          automatic_connection_allocation: null,
          is_replicable: false
        };
      },
      onError: (errs) => { createTripErrors.value = errs; },
      onFinish: () => { createTripProcessing.value = false; },
    };

    if (isEditingTrip.value) {
      router.put(url, createTripForm.value, options);
    } else {
      router.post(url, createTripForm.value, options);
    }
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
  }

  const availableRouteOptions = computed(() => buildTripCreationRouteOptions(
    props.routes,
    createTripForm.value.origin_station_id,
  ));

  const availableDestinationOptions = computed(() => buildTripCreationDestinationOptions(
    props.routes,
    createTripForm.value.origin_station_id,
    createTripForm.value.route_id,
    props.stations,
  ));

  watch([() => createTripForm.value.origin_station_id, () => createTripForm.value.route_id], () => {
    if (createTripForm.value.route_id
      && !availableRouteOptions.value.some((option) => option.value === createTripForm.value.route_id)) {
      createTripForm.value.route_id = '';
    }
    if (createTripForm.value.destination_station_id
      && !availableDestinationOptions.value.some((option) => option.value === createTripForm.value.destination_station_id)) {
      createTripForm.value.destination_station_id = '';
    }
  }, { immediate: true });

  watch([() => createTripForm.value.origin_station_id, () => createTripForm.value.destination_station_id, () => createTripForm.value.departure_at], ([originId, destId, departureAt]) => {
    if (isEditingTrip.value) return;
    if (originId && destId && departureAt) {
      const stationsMap = new Map();
      (props.routes || []).forEach(r => {
        if (r.origin_station) stationsMap.set(r.origin_station.id, r.origin_station);
        if (r.originStation) stationsMap.set(r.originStation.id, r.originStation);
        if (r.destination_station) stationsMap.set(r.destination_station.id, r.destination_station);
        if (r.destinationStation) stationsMap.set(r.destinationStation.id, r.destinationStation);
        const stops = r.route_stop_orders || r.routeStopOrders || [];
        stops.forEach(s => {
          const st = s.station || s;
          if (st && st.id) stationsMap.set(st.id, st);
        });
      });

      const origin = stationsMap.get(originId);
      const destination = stationsMap.get(destId);

      const originCode = origin?.code || (origin ? origin.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'TRP');
      const destinationCode = destination?.code || (destination ? destination.name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase() : 'DST');

      const timePart = departureAt.split('T')[1] ? departureAt.split('T')[1].replace(':', '') : '0000';
      const cleanTime = timePart.substring(0, 4);

      createTripForm.value.code = `${originCode}-${destinationCode}-${cleanTime}`;
    }
  });

  watch(() => createTripForm.value.allows_open_connections, (allowsConnections) => {
    if (!allowsConnections) {
      createTripForm.value.automatic_connection_allocation = null;
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
      activeOkohiRequest.value = null;
      startPendingOkohiPolling(newVal);
      fetchSeatMap();
      ensureRealtimeFallback();
    } else {
      stopPendingOkohiPolling();
      if (realtimeFallbackInterval) {
        clearInterval(realtimeFallbackInterval);
        realtimeFallbackInterval = null;
      }
    }
  });

  // Fare change → fetch suggestions + segment-specific seat map
  watch(selectedFare, (newVal) => {
    if (restoringBookingContext) return;
    if (newVal) {
      finalDestinationStationId.value = newVal.is_connection ? newVal.connection_destination_id : null;
      connectionRouteId.value = newVal.is_connection ? newVal.connection_route_id : null;
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

  // Internal booking resets clear the fare in the same tick, so they do not
  // trigger a suggestion fetch. Every subsequent user change remains observable.
  watch(ticketQuantity, () => {
    if (restoringBookingContext) return;
    if (selectedFare.value) fetchSeatSuggestions();
  });

  watch(maxSellableQuantity, (maximum) => {
    if (maximum > 0 && ticketQuantity.value > maximum) {
      ticketQuantity.value = maximum;
    }
  });

  // Listen for seat selection from Sidebar
  watch(() => ticketingStore.clickTimestamp, () => {
    const newSeat = ticketingStore.selectedSeat;
    if (newSeat && availableFares.value.length > 0) initiateBookingFlow(newSeat);
  });

  // =============================================
  // Lifecycle
  // =============================================
  onMounted(async () => {
    clockInterval = setInterval(updateClock, 1000);

    // Auto-reconnect Bluetooth
    if (useBluetoothPrinter.value && bluetoothPrinter.isSupported()) {
      try {
        bluetoothPrinter.setDisconnectCallback(() => {
          bluetoothPrinterConnected.value = false;
          bluetoothPrinterName.value = null;
        });
        const restored = await bluetoothPrinter.restoreAuthorizedDevice();
        if (restored) syncBluetoothStatus();
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
    stopPendingOkohiPolling();
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
    editingTripId,
    isEditingTrip,
    createTripForm,
    createTripErrors,
    createTripProcessing,
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

    // Bluetooth
    useBluetoothPrinter,
    bluetoothPrinterConnected,
    bluetoothPrinterName,
    connectBluetoothPrinter,
    disconnectBluetoothPrinter,
    toggleBluetoothPrinter,

    // Computed
    bookingSidePanelOpen,
    currentTrip,
    assignedStationIds,
    operationalStationId,
    isTripPassed,
    seatsToBook,
    totalAmount,
    canBookTickets,
    seatStats,
    getOccupancyRate,
    filteredTrips,
    availableFares,

    // Aller-retour (Phase 3)
    journeyType,
    returnMode,
    verifiedOkohiCustomerNumber,
    roundTripSalesEnabled,
    returnScheduleId,
    returnDate,
    returnTime,
    returnSchedules,
    roundTripDiscountAmount,
    returnFareAmount,
    perTicketAmount,
    roundTripSavings,
    compatibleReturnSchedules,
    resetRoundTripState,

  availableRouteOptions,
  availableDestinationOptions,

    // Helpers
    buildTripStationIndices,
    getStationColor,
    getAssignedStationPalette,
    currentStationSellableSeatNumbers,
    maxSellableQuantity,
    currentStationFreedSeatNumbers,
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
    initiateQuantityBookingFlow,
    cancelQuantityBooking,
    canSellWithoutVehicle,
    quantitySale,
    autoSelectOptimalSeat,
    confirmBooking,
    cancelBooking,
    continueSalesAfterOkohiRequest,
    handleOkohiSuccess,
    createTrip,
    openCreateTrip,
    openEditTrip,
    applyReplicableTemplate,
    printTickets,
    printQueue,
    printQueueRunning,
    retryPrint,
    printInBrowser,
    dismissPrintEntry,
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
    isWaitingForSalesTurn,
    hasFreedSeatsForSeller,
    isFareDisabled,
    getFreedSeatCountForFare,

    // Okohi Realtime Notifications
    okohiNotifications,
    dismissOkohiNotif,
    pendingOkohiRequestsForCurrentTrip,
    pendingOkohiCountdowns,
    fetchPendingOkohiRequestsForTrip,
    openPendingOkohiModal,
    stopPendingOkohiPolling,

    // Page
    page,
  };
}
