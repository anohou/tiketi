<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Bus from 'vue-material-design-icons/Bus.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Seat from 'vue-material-design-icons/Seat.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Minus from 'vue-material-design-icons/Minus.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Lock from 'vue-material-design-icons/Lock.vue';
import LockOpen from 'vue-material-design-icons/LockOpen.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import RouteSchemaDiagram from '@/Components/RouteSchemaDiagram.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';
import { ticketingStore } from '@/Stores/ticketingStore.js';
import SkeletonLoader from '@/Components/SkeletonLoader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { toastStore } from '@/Stores/toastStore.js';

const props = defineProps({
    initialSelectedTripId: {
        type: [String, Number],
        default: null
    }
});

const trips = ref([]);
const loading = ref(false);
const selectedTripId = ref(props.initialSelectedTripId);
const seatMap = ref(null);
const seatMapLoading = ref(false);
const selectedVehicleId = ref('');
const assigningVehicle = ref(false);
const showVehicleAssignmentModal = ref(false);
const availableVehiclesLoading = ref(false);
const assignableVehicles = ref([]);
const vehiclePoolStation = ref(null);
const vehiclePoolDate = ref(null);
const showRouteSchemaModal = ref(false);
const selectedRouteSchemaTrip = ref(null);
let realtimeFallbackInterval = null;
let realtimeChannels = [];

const isSameTripId = (a, b) => String(a) === String(b);

// Zoom controls
const zoomLevel = ref(1);
const minZoom = 0.5;
const maxZoom = 2;
const zoomStep = 0.25;

const zoomIn = () => {
    if (zoomLevel.value < maxZoom) {
        zoomLevel.value = Math.min(maxZoom, zoomLevel.value + zoomStep);
    }
};

const zoomOut = () => {
    if (zoomLevel.value > minZoom) {
        zoomLevel.value = Math.max(minZoom, zoomLevel.value - zoomStep);
    }
};

const resetZoom = () => {
    zoomLevel.value = 1;
};

const page = usePage();
const isTicketingPage = computed(() => route().current('seller.ticketing') || route().current('seller.ticketing.focus'));
const currentTicketingRoute = computed(() => route().current('seller.ticketing.focus')
    ? 'seller.ticketing.focus'
    : 'seller.ticketing');

const emit = defineEmits(['seat-click']);

const fetchTrips = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('trips.index'));
        const now = Date.now();

        trips.value = response.data
            .filter(trip => {
                const departure = new Date(trip.departure_at).getTime();
                return departure >= new Date().setHours(0, 0, 0, 0);
            })
            .sort((a, b) => {
                const aDeparture = new Date(a.departure_at).getTime();
                const bDeparture = new Date(b.departure_at).getTime();
                const aPast = aDeparture < now;
                const bPast = bDeparture < now;

                if (aPast !== bPast) {
                    return aPast ? 1 : -1;
                }

                return aDeparture - bDeparture;
            });
    } catch (error) {
        console.error("Erreur lors de la récupération des voyages:", error);
    } finally {
        loading.value = false;
    }
};

const fetchSeatMap = async (tripId) => {
    if (!tripId) return;

    const trip = trips.value.find(item => isSameTripId(item.id, tripId));
    if (trip && !trip.vehicle) {
        seatMap.value = null;
        seatMapLoading.value = false;
        return;
    }

    seatMapLoading.value = true;
    
    // If on ticketing page, we might need stop filters from the parent
    // For now, fetch standard seat map
    try {
        const response = await axios.get(route('seller.trips.seatmap', { trip: tripId }));
        // Keep the whole object to stay consistent with Ticketing.vue
        seatMap.value = response.data;
    } catch (error) {
        if (error.response?.status !== 409 || !error.response?.data?.vehicle_required) {
            console.error("Erreur lors de la récupération du plan de salle:", error);
        }
        seatMap.value = null;
    } finally {
        seatMapLoading.value = false;
    }
};

const getAssignedStationIds = () => {
    const assignments = page.props.auth.user?.station_assignments || [];
    return [...new Set(assignments
        .map(assignment => assignment.station_id)
        .filter(Boolean)
        .map(stationId => String(stationId)))];
};

const getOrderedRouteStationIds = (routeObj) => {
    if (!routeObj) return [];

    const stops = [...(routeObj.route_stop_orders || routeObj.routeStopOrders || [])]
        .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));

    return [
        routeObj.origin_station_id || routeObj.originStation?.id || routeObj.origin_station?.id,
        ...stops.map(stop => stop.station_id || stop.station?.id),
        routeObj.destination_station_id || routeObj.destinationStation?.id || routeObj.destination_station?.id,
    ].filter((stationId, index, stationIds) => stationId && stationIds.indexOf(stationId) === index);
};

const getTripConnectionDestinations = (trip) => {
    if (!trip?.allows_open_connections) return {};

    const stationIds = getOrderedRouteStationIds(trip.route);
    const originId = trip.origin_station_id || stationIds[0];
    const tripDestinationId = trip.destination_station_id || stationIds.at(-1);
    const tripDestinationIndex = stationIds.indexOf(tripDestinationId);
    const routeFares = page.props.routeFares || [];
    const connectionFares = page.props.connectionFares || [];
    const connectionRoutes = page.props.connectionRoutes || [];
    const destinationsByTransfer = {};

    const orientFareFromOrigin = (fare) => {
        if (fare.from_station_id === originId) {
            return {
                destinationId: fare.to_station_id,
                destination: fare.to_station || fare.toStation,
            };
        }

        if (fare.is_bidirectional && fare.to_station_id === originId) {
            return {
                destinationId: fare.from_station_id,
                destination: fare.from_station || fare.fromStation,
            };
        }

        return null;
    };

    const transferIds = new Set(routeFares
        .map(orientFareFromOrigin)
        .filter(Boolean)
        .map(option => option.destinationId)
        .filter(stationId => stationIds.indexOf(stationId) > stationIds.indexOf(originId)));

    transferIds.forEach((transferId) => {
        const transferIndex = stationIds.indexOf(transferId);

        connectionFares.forEach((fare) => {
            const option = orientFareFromOrigin(fare);
            if (!option || !option.destinationId || option.destinationId === transferId) return;

            const destinationIndex = stationIds.indexOf(option.destinationId);
            const isAlreadyServedAfterTransfer = destinationIndex > transferIndex
                && destinationIndex <= tripDestinationIndex;
            if (isAlreadyServedAfterTransfer) return;

            const compatibleRoute = connectionRoutes.find((routeItem) => {
                const connectionStationIds = getOrderedRouteStationIds(routeItem);
                const routeTransferIndex = connectionStationIds.indexOf(transferId);
                const routeDestinationIndex = connectionStationIds.indexOf(option.destinationId);

                return routeTransferIndex !== -1
                    && routeDestinationIndex !== -1
                    && routeTransferIndex < routeDestinationIndex;
            });
            if (!compatibleRoute) return;

            destinationsByTransfer[transferId] ||= [];
            if (!destinationsByTransfer[transferId].some(destination => String(destination.id) === String(option.destinationId))) {
                destinationsByTransfer[transferId].push({
                    id: option.destinationId,
                    name: option.destination?.name || 'Destination',
                });
            }
        });
    });

    return destinationsByTransfer;
};

const applyRealtimeSeatUpdate = (e = {}) => {
    const tripId = e.trip_id || e.trip?.id;
    if (!tripId) return;

    ticketingStore.pulseTrip(tripId, {
        action: e.action || 'ticket.updated',
        sourceStationId: e.source_station_id || null,
        changedSeats: e.changedSeats || [],
    });

    if (selectedTripId.value && isSameTripId(selectedTripId.value, tripId)) {
        ticketingStore.notifySeatMapChanged();
        syncSelectedTripSilently();
    }
};

const isEchoConnected = () => {
    try {
        return window.Echo?.connector?.pusher?.connection?.state === 'connected';
    } catch (error) {
        return false;
    }
};

const syncSelectedTripSilently = async () => {
    if (!selectedTripId.value) return;
    try {
        const response = await axios.get(route('seller.trips.seatmap', { trip: selectedTripId.value }));
        seatMap.value = response.data;
    } catch (error) {
        console.error('Fallback seat map sync failed:', error);
    }
};

const selectTrip = (trip) => {
    if (selectedTripId.value === trip.id) {
        // Only deselect if not on ticketing page
        if (!isTicketingPage.value) {
            selectedTripId.value = null;
            seatMap.value = null;
        }
    } else {
        selectedTripId.value = trip.id;
        fetchSeatMap(trip.id);
        
        // If on ticketing page, notify parent to sync
        if (isTicketingPage.value) {
            router.visit(route(currentTicketingRoute.value, { trip_id: trip.id }), {
                preserveState: true,
                preserveScroll: true,
                replace: true
            });
        }
    }
};

const handleSeatClick = (seatNumber) => {
    if (isTicketingPage.value && !seatSalesDisabled.value) {
        console.log('[Sidebar] Selecting seat:', seatNumber);
        ticketingStore.selectSeat(seatNumber);
        emit('seat-click', seatNumber);
    }
};

const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    });
};

const areDownstreamSalesActive = (trip) => trip?.status === 'departed'
    ? (!assignedStationId.value || trip?.active_sales_station_id === assignedStationId.value)
    : trip?.sales_control === 'open';

const getSalesControlTitle = (trip) => {
    if (trip?.status === 'departed') {
        return trip?.active_sales_station_id === assignedStationId.value
            ? 'Votre gare a la main sur les ventes'
            : 'En attente du départ de la gare précédente';
    }
    return trip?.sales_control === 'open'
        ? 'Ventes simultanées autorisées'
        : 'Ventes simultanées désactivées jusqu’au départ';
};

onMounted(() => {
    fetchTrips();

    const echo = window.Echo;
    if (echo) {
        const stationIds = getAssignedStationIds();

        stationIds.forEach((stationId) => {
            echo.private(`station.${stationId}`)
                .listen('.SeatMapUpdated', applyRealtimeSeatUpdate)
                .listen('.TripCreated', (e) => {
                    if (e.trip?.id) {
                        ticketingStore.pulseTrip(e.trip.id, { action: 'trip.created' });
                    }
                });
            realtimeChannels.push(`station.${stationId}`);
        });

        echo.private('network.global')
            .listen('.SeatMapUpdated', applyRealtimeSeatUpdate)
            .listen('.TripCreated', (e) => {
                if (e.trip?.id) {
                    ticketingStore.pulseTrip(e.trip.id, { action: 'trip.created' });
                }
            });
        realtimeChannels.push('network.global');
    }

    realtimeFallbackInterval = setInterval(() => {
        if (selectedTripId.value && !isEchoConnected()) {
            syncSelectedTripSilently();
        }
    }, 5000);
});

watch(() => ticketingStore.selectedTripId, (newId) => {
    if (newId && !isSameTripId(newId, selectedTripId.value)) {
        selectedTripId.value = newId;
        fetchSeatMap(newId);
    }
}, { immediate: true });

// Immutable seat map update: replaces seatMap.value entirely to guarantee Vue reactivity
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

// Optimistic local update when seat(s) are booked (no refetch, no reload)
watch(() => ticketingStore.lastBookedSeat, (val) => {
    if (!val) return;
    const seats = val.seats || [val.seat]; // Support both old {seat} and new {seats} format
    seats.forEach(s => updateSeatMapImmutable(s, true, val.color));
});

// Optimistic local revert when a booking fails
watch(() => ticketingStore.lastRevertedSeat, (val) => {
    if (!val) return;
    const seats = val.seats || [val.seat];
    seats.forEach(s => updateSeatMapImmutable(s, false));
});

// Silent refresh for WebSocket updates (another seller booked) — no loading spinner
watch(() => ticketingStore.seatMapVersion, async () => {
    if (!selectedTripId.value) return;
    try {
        const response = await axios.get(route('seller.trips.seatmap', { trip: selectedTripId.value }));
        seatMap.value = response.data;
    } catch (error) {
        console.error("Erreur lors du rafraîchissement silencieux:", error);
    }
});

watch(() => {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('trip_id');
}, (newId) => {
    if (newId && !isSameTripId(newId, selectedTripId.value)) {
        selectedTripId.value = newId;
        fetchSeatMap(newId);
    }
}, { immediate: true });

// Refresh every 5 minutes to save data
let refreshInterval;
onMounted(() => {
    refreshInterval = setInterval(fetchTrips, 300000);
});

import { onUnmounted } from 'vue';
onUnmounted(() => {
    if (realtimeFallbackInterval) clearInterval(realtimeFallbackInterval);
    if (refreshInterval) clearInterval(refreshInterval);
    const echo = window.Echo;
    if (echo) {
        realtimeChannels.forEach((channelName) => {
            echo.leave(channelName);
        });
    }
    realtimeChannels = [];
});

// Combined trips list from both API fetch and Inertia page props
const allTrips = computed(() => {
    const pageTrips = Array.isArray(page.props.trips)
        ? page.props.trips
        : (page.props.trips?.data || []);

    const combined = [...trips.value];
    pageTrips.forEach(pt => {
        if (pt && !combined.some(t => isSameTripId(t.id, pt.id))) {
            combined.push(pt);
        }
    });
    return combined;
});

// The selected trip object (from trips array or page props)
const selectedTrip = computed(() => {
    return allTrips.value.find(t => isSameTripId(t.id, selectedTripId.value));
});

watch(selectedTrip, (trip) => {
    selectedVehicleId.value = trip?.vehicle_id || trip?.vehicle?.id || '';
    if (trip && !trip.vehicle) seatMap.value = null;
}, { immediate: true });

const openVehicleAssignmentModal = async () => {
    if (!selectedTrip.value) return;

    selectedVehicleId.value = '';
    assignableVehicles.value = [];
    vehiclePoolStation.value = null;
    showVehicleAssignmentModal.value = true;
    availableVehiclesLoading.value = true;

    try {
        const response = await axios.get(route('seller.trips.available-vehicles', {
            trip: selectedTrip.value.id,
        }));
        assignableVehicles.value = response.data.vehicles || [];
        vehiclePoolStation.value = response.data.station || null;
        vehiclePoolDate.value = response.data.date || null;
    } catch (error) {
        toastStore.error(error.response?.data?.message || 'Impossible de charger le pool de véhicules.');
        showVehicleAssignmentModal.value = false;
    } finally {
        availableVehiclesLoading.value = false;
    }
};

const closeVehicleAssignmentModal = () => {
    if (!assigningVehicle.value) showVehicleAssignmentModal.value = false;
};

const assignVehicle = async () => {
    if (!selectedTrip.value || !selectedVehicleId.value || assigningVehicle.value) return;

    assigningVehicle.value = true;
    try {
        const response = await axios.patch(route('seller.trips.assign-vehicle', {
            trip: selectedTrip.value.id,
        }), {
            vehicle_id: selectedVehicleId.value,
        });

        const tripIndex = trips.value.findIndex(trip => isSameTripId(trip.id, selectedTrip.value.id));
        if (tripIndex !== -1) {
            trips.value[tripIndex] = {
                ...trips.value[tripIndex],
                ...response.data.trip,
            };
        }

        toastStore.success(response.data.message || 'Véhicule assigné avec succès.');
        showVehicleAssignmentModal.value = false;
        await fetchSeatMap(selectedTrip.value.id);
        router.reload({ only: ['trips', 'vehicles'], preserveScroll: true });
    } catch (error) {
        toastStore.error(error.response?.data?.message || 'Impossible d’assigner ce véhicule.');
    } finally {
        assigningVehicle.value = false;
    }
};

const assignedStation = computed(() => page.props.assignedStations?.[0] || null);
const assignedStationId = computed(() => assignedStation.value?.id || null);
// Administrators can sell without a station assignment ("Toutes les gares").
// In that case, the ticketing page exposes fares from the trip origin, so the
// sidebar must use that same origin instead of treating the missing assignment
// as a missing fare configuration.
const salesStationId = computed(() => assignedStationId.value
    || selectedTrip.value?.origin_station_id
    || selectedTrip.value?.route?.origin_station_id
    || null);
const isTripHighlighted = (tripId) => !!ticketingStore.tripHighlights?.[String(tripId)];

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

    const isReversedTrip = trip?.origin_station_id &&
        routeObj.origin_station_id &&
        trip.origin_station_id !== routeObj.origin_station_id;

    const directionStations = isReversedTrip ? [...orderedStationIds].reverse() : orderedStationIds;

    return directionStations.reduce((map, stationId, index) => {
        map[stationId] = index;
        return map;
    }, {});
};

const routeHuePalette = [
    220,
    270,
    25,
    165,
    330,
    195,
    140,
    350,
];

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
    return '#FFFFFF';
};

const getRouteStationColor = (stationIndex) => {
    const safeIndex = Number.isFinite(stationIndex) && stationIndex >= 0 ? stationIndex : 0;
    const hue = routeHuePalette[safeIndex % routeHuePalette.length];
    const lightness = safeIndex === 0 ? 44 : Math.max(40, 52 - (safeIndex * 2));

    return {
        bg: `hsl(${hue}, 80%, ${lightness}%)`,
        fg: '#FFFFFF',
    };
};

const currentStationPalette = computed(() => {
    if (!selectedTrip.value || !salesStationId.value) return null;

    const stationIndexMap = buildTripStationIndices(selectedTrip.value);
    const stationIndex = stationIndexMap[salesStationId.value];
    if (stationIndex === undefined) return null;

    return getRouteStationColor(stationIndex);
});

const currentStationSellableSeatNumbers = computed(() => {
    if (!salesStationId.value) return [];
    const originStationId = selectedTrip.value?.origin_station_id
        || selectedTrip.value?.route?.origin_station_id;
    const isClosedAtIntermediateStation = selectedTrip.value?.status === 'departed'
        ? selectedTrip.value?.active_sales_station_id !== salesStationId.value
        : selectedTrip.value?.sales_control === 'closed'
            && originStationId !== salesStationId.value;
    const seatsByStation = isClosedAtIntermediateStation
        ? (seatMap.value?.freed_seats_by_station || {})
        : (seatMap.value?.sellable_seats_by_station || {});

    return (seatsByStation[salesStationId.value] || [])
        .map((seatNumber) => Number(seatNumber))
        .filter((seatNumber) => Number.isFinite(seatNumber));
});

const hasPricedDestinationFromAssignedStation = computed(() => {
    if (!selectedTrip.value || !salesStationId.value) return false;

    const routeFares = page.props.routeFares;
    if (!Array.isArray(routeFares)) return true;

    const stationIndices = buildTripStationIndices(selectedTrip.value);
    const originIndex = stationIndices[salesStationId.value];
    if (originIndex === undefined) return false;

    return routeFares.some((fare) => {
        const fromId = fare.from_station_id || fare.from_station?.id || fare.fromStation?.id;
        const toId = fare.to_station_id || fare.to_station?.id || fare.toStation?.id;
        const directDestinationIndex = stationIndices[toId];
        const reverseDestinationIndex = stationIndices[fromId];

        return (fromId === salesStationId.value && directDestinationIndex > originIndex)
            || (fare.is_bidirectional
                && toId === salesStationId.value
                && reverseDestinationIndex > originIndex);
    });
});

const seatSalesDisabled = computed(() => isTicketingPage.value
    && Array.isArray(page.props.routeFares)
    && !hasPricedDestinationFromAssignedStation.value);

const currentStationFreedSeatNumbers = computed(() => {
    if (!salesStationId.value) return [];

    if (selectedTrip.value?.status === 'departed') {
        const stationIndices = buildTripStationIndices(selectedTrip.value);
        const stationIndex = stationIndices[salesStationId.value];
        const activeStationIndex = stationIndices[selectedTrip.value?.active_sales_station_id];

        // Only upcoming stations preview their future released seats. Once the
        // handoff reaches this station, normal sales replace the visual alert.
        if (stationIndex === undefined
            || activeStationIndex === undefined
            || stationIndex <= activeStationIndex) {
            return [];
        }
    }

    return (seatMap.value?.freed_seats_by_station?.[salesStationId.value] || [])
        .map((seatNumber) => Number(seatNumber))
        .filter((seatNumber) => Number.isFinite(seatNumber));
});

const currentStationSellableSeatBorderColor = computed(() => currentStationPalette.value?.bg || null);

const getTripRouteSchema = (trip) => {
    const routeObj = trip?.route;
    if (!routeObj) return [];

    const orderedStationIds = [];
    const orderedStations = [];
    const addStation = (station) => {
        const stationId = station?.id || station?.station_id || station;
        if (!stationId || orderedStationIds.includes(stationId)) return;
        orderedStationIds.push(stationId);
        orderedStations.push(station);
    };

    addStation(routeObj.origin_station || { id: routeObj.origin_station_id, name: routeObj.origin_station?.name });
    const stops = [...(routeObj.route_stop_orders || routeObj.routeStopOrders || [])]
        .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
    stops.forEach((stop) => addStation(stop.station || { id: stop.station_id, name: stop.station?.name }));
    addStation(routeObj.destination_station || { id: routeObj.destination_station_id, name: routeObj.destination_station?.name });

    const stationIndexMap = buildTripStationIndices(trip);
    const connectionDestinations = getTripConnectionDestinations(trip);
    return orderedStations.map((station, index) => {
        const stationId = station?.id || station?.station_id;
        const palette = getRouteStationColor(stationIndexMap[stationId] ?? index);
        return {
            id: stationId,
            name: station?.name || 'Station',
            color: palette.bg,
            textColor: getContrastingTextColor(palette.bg),
            connections: connectionDestinations[stationId] || [],
        };
    });
};

const selectedRouteSchemaStops = computed(() => {
    if (!selectedRouteSchemaTrip.value) return [];
    return getTripRouteSchema(selectedRouteSchemaTrip.value);
});

const openRouteSchemaModal = (trip) => {
    if (!trip) return;
    selectedRouteSchemaTrip.value = trip;
    showRouteSchemaModal.value = true;
};

const closeRouteSchemaModal = () => {
    showRouteSchemaModal.value = false;
};

const filteredTrips = computed(() => {
    let result = allTrips.value;
    const destName = ticketingStore.selectedDestinationId;
    if (destName) {
        result = result.filter(trip => {
            if (trip.route?.destination_station?.city === destName) return true;
            const stops = trip.route?.route_stop_orders || trip.route?.routeStopOrders || [];
            return stops.some(stop => stop.station?.city === destName);
        });
    }
    return result;
});

// Key counter: forces VehicleSeatMapSVG to fully re-render on every seatMap change
const seatMapKey = ref(0);
watch(seatMap, () => { seatMapKey.value++; });

// Vehicle type: prefer seatMap response (always available), fallback to trip data
const vehicleType = computed(() => {
    return seatMap.value?.vehicle_type
        || seatMap.value?.type
        || selectedTrip.value?.vehicle?.vehicle_type
        || selectedTrip.value?.vehicle?.vehicleType
        || null;
});

const resolvedVehicleType = computed(() => {
    if (vehicleType.value) return vehicleType.value;

    return {
        seat_configuration: '2+2',
        door_positions: [],
        seat_map: [],
        seat_count: seatMap.value?.total_seats || selectedTrip.value?.vehicle?.seat_count || 0,
    };
});

const seatMapReady = computed(() => Boolean(seatMap.value && seatStats.value));

const selectedSeatColor = '#22C55E';

// Stats for the selected trip
const seatStats = computed(() => {
    if (!seatMap.value || !seatMap.value.seat_map) return null;
    return {
        total: seatMap.value.total_seats || 0,
        soldTickets: seatMap.value.sold_tickets_count || seatMap.value.tickets_count || 0,
        occupiedSeats: seatMap.value.occupied_seats_count || seatMap.value.occupied_seats || 0,
        available: seatMap.value.available_seats_count || seatMap.value.available_seats || 0
    };
});

const getOccupancyRate = (available, total) => {
    if (!total) return 0;
    const occupied = Math.max(0, total - available);
    return Math.round((occupied / total) * 100);
};
</script>

<template>
    <div class="flex h-full w-full flex-col overflow-hidden border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/30">
        <!-- Header -->
        <div class="p-5 bg-gradient-to-br from-emerald-50 to-slate-50 border-b border-slate-100 flex items-center justify-between shrink-0 dark:border-slate-800 dark:from-slate-900 dark:to-slate-950">
            <div>
                <h2 class="text-base font-black text-slate-800 flex items-center gap-2 dark:text-slate-100">
                    <Bus :size="20" class="text-emerald-600 dark:text-emerald-400" />
                    Voyages
                </h2>
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wider dark:text-slate-400">Plan & Occupations</p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="fetchTrips" :disabled="loading" class="p-2 hover:bg-white rounded-xl shadow-sm border border-transparent hover:border-slate-200 transition-all text-slate-400 hover:text-emerald-600 disabled:opacity-50 dark:hover:bg-slate-800">
                    <Refresh :size="18" :class="{ 'animate-spin': loading }" />
                </button>
            </div>
        </div>

        <!-- TICKETING PAGE: Show only selected trip seat map (workspace mode) -->
        <template v-if="isTicketingPage">
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Compact selected trip info -->
                <div v-if="selectedTrip" class="px-3 py-2 bg-emerald-50/60 border-b border-emerald-100 dark:border-emerald-900/40 dark:bg-emerald-900/10">
                    <div class="flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></div>
                        <div class="text-xs font-bold text-slate-900 whitespace-normal break-words leading-snug dark:text-slate-100">{{ selectedTrip.display_name || selectedTrip.route?.name }}</div>
                    </div>
                    <div class="flex items-center gap-2 mt-0.5 pl-3.5 flex-wrap">
                        <span class="text-[10px] font-black text-emerald-700 uppercase tracking-widest dark:text-emerald-300">{{ selectedTrip.vehicle?.identifier }}</span>
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400">{{ formatTime(selectedTrip.departure_at) }}</span>
                        <button
                            type="button"
                            @click.stop="openRouteSchemaModal(selectedTrip)"
                            class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-white/80 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-emerald-700 shadow-sm transition-all hover:border-emerald-300 hover:bg-white hover:text-emerald-800 dark:border-emerald-900/60 dark:bg-slate-950/40 dark:text-emerald-300 dark:hover:border-emerald-700"
                            title="Voir le schéma du trajet"
                        >
                            <Routes :size="12" />
                            Schéma
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="seatMapLoading" class="flex-1 p-4 bg-white dark:bg-slate-900">
                    <SkeletonLoader type="list" :count="4" />
                </div>

                <!-- Seat Map -->
                <template v-else-if="seatMapReady">
                    <!-- Stats Row -->
                    <div class="px-2.5 py-1.5 flex items-center justify-between gap-1.5 bg-gray-50 border-b border-gray-100 shrink-0 dark:border-slate-800 dark:bg-slate-800/60">
                        <div class="flex min-w-0 flex-1 items-center gap-0.5 sm:gap-1 overflow-x-auto whitespace-nowrap text-[10px] sm:text-[11px] [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden pr-1">
                            <span class="font-bold text-gray-500 dark:text-slate-400">Cap</span>
                            <span class="font-black text-gray-800 dark:text-slate-100">{{ seatStats.total }}</span>
                            <span class="text-gray-300 dark:text-slate-600">|</span>
                            <span class="font-bold text-blue-500">Bil</span>
                            <span class="font-black text-blue-600">{{ seatStats.soldTickets }}</span>
                            <span class="text-gray-300 dark:text-slate-600">|</span>
                            <span class="font-bold text-red-500 dark:text-rose-400/80">Occ</span>
                            <span class="font-black text-red-600 dark:text-rose-400/80">{{ seatStats.occupiedSeats }}</span>
                            <span class="text-gray-300 dark:text-slate-600">|</span>
                            <span class="font-bold text-emerald-500">Lib</span>
                            <span class="font-black text-emerald-600">{{ seatStats.available }}</span>
                            <span class="text-gray-300 dark:text-slate-600">|</span>
                            <span class="font-bold text-sky-500">% Occ</span>
                            <span class="font-black text-sky-600">{{ getOccupancyRate(seatStats.available, seatStats.total) }}%</span>
                        </div>
                        <!-- Zoom Controls -->
                        <div class="flex shrink-0 items-center gap-0.5 bg-white rounded border border-gray-200 dark:border-slate-700 dark:bg-slate-900">
                            <button @click="zoomOut" :disabled="zoomLevel <= minZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all dark:hover:bg-slate-800" title="Zoom -">
                                <Minus :size="12" class="text-gray-600" />
                            </button>
                            <button @click="resetZoom" class="text-[10px] font-extrabold text-gray-500 px-1 text-center hover:text-emerald-600 dark:text-slate-400 dark:hover:text-emerald-400" :title="`Zoom : ${Math.round(zoomLevel * 100)}% (Réinitialiser)`">%</button>
                            <button @click="zoomIn" :disabled="zoomLevel >= maxZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all dark:hover:bg-slate-800" title="Zoom +">
                                <Plus :size="12" class="text-gray-600" />
                            </button>
                        </div>
                    </div>

                    <!-- Seat Map SVG - Full remaining height -->
                    <div class="flex-1 bg-white relative overflow-auto dark:bg-slate-900">
                        <div class="w-full h-full flex items-center justify-center"
                             :style="{ transform: `scale(${zoomLevel})`, transformOrigin: 'center center' }">
                            <VehicleSeatMapSVG
                                v-if="seatMapReady"
                                :key="seatMapKey"
                                :seat-map="seatMap"
                                :vehicle-type="resolvedVehicleType"
                                :suggested-seats="ticketingStore.suggestedSeats"
                                :selected-seat="ticketingStore.selectedSeat"
                                :selected-color="selectedSeatColor"
                                :show-suggestions="ticketingStore.showSuggestions"
                                :disabled="seatSalesDisabled"
                                :sellable-seat-numbers="currentStationSellableSeatNumbers"
                                :released-seat-numbers="currentStationFreedSeatNumbers"
                                :sellable-seat-border-color="currentStationSellableSeatBorderColor"
                                @seat-click="handleSeatClick"
                                class="w-full h-full"
                            />
                        </div>
                    </div>
                </template>

                <!-- Trip without a vehicle -->
                <div v-else-if="selectedTrip && !selectedTrip.vehicle" class="flex-1 flex flex-col items-center justify-center px-5 text-center dark:text-slate-300">
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300">
                        <Bus :size="30" />
                    </div>
                    <h3 class="text-sm font-black text-slate-800 dark:text-slate-100">Véhicule non assigné</h3>
                    <p class="mt-1 max-w-[250px] text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                        Assignez un véhicule pour afficher le plan de sièges et ouvrir les ventes.
                    </p>
                    <button
                        type="button"
                        @click="openVehicleAssignmentModal"
                        class="mt-5 w-full max-w-[260px] rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black text-white shadow-sm transition hover:bg-emerald-700"
                    >
                        Choisir dans le pool de la gare
                    </button>
                </div>

                <!-- No trip selected -->
                <div v-else class="flex-1 flex flex-col items-center justify-center text-gray-400 px-4 dark:text-slate-500">
                    <Bus :size="32" class="mb-2 opacity-30" />
                    <p class="text-xs text-center">Sélectionnez un voyage pour voir le plan</p>
                </div>
            </div>
        </template>

        <!-- OTHER PAGES: Show full trip list with expandable seat maps -->
        <div v-else class="flex-1 overflow-y-auto p-3 space-y-3">
            <div v-if="loading && trips.length === 0" class="p-4 bg-white rounded-2xl dark:bg-slate-900">
                <SkeletonLoader type="list" :count="5" />
            </div>

            <div v-else-if="trips.length === 0" class="py-6">
                <EmptyState
                    title="Aucun voyage disponible"
                    message="Aucun voyage n'est programmé pour le moment."
                    :icon="Bus"
                />
            </div>

            <div v-else-if="filteredTrips.length === 0" class="py-6">
                <EmptyState
                    title="Aucun résultat"
                    message="Aucun voyage ne correspond à la destination sélectionnée."
                    :icon="Bus"
                />
            </div>

            <div v-else v-for="trip in filteredTrips" :key="trip.id"
                class="border-2 rounded-2xl overflow-hidden transition-all duration-300"
                :class="[
                  selectedTripId === trip.id
                    ? 'border-emerald-500 shadow-lg'
                    : isTripHighlighted(trip.id)
                      ? 'border-amber-400 shadow-xl shadow-amber-200/60 ring-2 ring-amber-200 dark:ring-amber-900/40 dark:shadow-amber-950/20'
                      : 'border-transparent bg-slate-50 hover:border-emerald-200 hover:bg-white hover:shadow-md dark:bg-slate-900 dark:hover:border-emerald-800 dark:hover:bg-slate-800'
                ]"
            >
                <!-- Trip Summary Header -->
                <div @click="selectTrip(trip)"
                    class="p-3 cursor-pointer"
                    :class="[
                      selectedTripId === trip.id ? 'bg-emerald-50/50 dark:bg-emerald-900/10' : '',
                      isTripHighlighted(trip.id) ? 'bg-amber-50/70 dark:bg-amber-950/10' : ''
                    ]"
                >
                    <div class="flex items-center gap-2 mb-1">
                        <Bus :size="16" :class="selectedTripId === trip.id ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'" />
                        <div class="text-sm font-bold text-slate-900 tracking-tight leading-snug whitespace-normal break-words dark:text-slate-100">{{ trip.display_name || trip.route?.name }}</div>
                        <span
                            :title="getSalesControlTitle(trip)"
                            :class="['inline-flex shrink-0 items-center', areDownstreamSalesActive(trip) ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400']"
                        >
                            <LockOpen v-if="areDownstreamSalesActive(trip)" :size="18" aria-hidden="true" />
                            <Lock v-else :size="18" aria-hidden="true" />
                        </span>
                        <span v-if="isTripHighlighted(trip.id)" class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        <span v-if="selectedTripId === trip.id" class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <button
                            type="button"
                            @click.stop="openRouteSchemaModal(trip)"
                            class="ml-auto inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-slate-600 shadow-sm transition-all hover:border-emerald-200 hover:text-emerald-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:border-emerald-800 dark:hover:text-emerald-300"
                            title="Voir le schéma du trajet"
                        >
                            <Routes :size="12" />
                            Schéma
                        </button>
                    </div>
                    <div class="flex items-center gap-3 pl-6">
                        <span class="text-[10px] font-black text-emerald-700 uppercase tracking-widest dark:text-emerald-300">
                            {{ trip.vehicle?.identifier }}
                        </span>
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500">
                            {{ formatTime(trip.departure_at) }}
                        </span>
                        <ChevronRight v-if="selectedTripId !== trip.id" :size="14" class="text-slate-400 ml-auto dark:text-slate-500" />
                    </div>
                </div>

                <!-- Expanded Content (Seat Map) -->
                <div v-if="selectedTripId === trip.id" class="border-t border-emerald-100 bg-white dark:border-emerald-900/40 dark:bg-slate-900">
                    <div v-if="seatMapLoading" class="p-4 bg-white dark:bg-slate-900">
                        <SkeletonLoader type="list" :count="3" />
                    </div>

                    <div v-else-if="seatMap">
                        <!-- Compact Stats Row -->
                        <div class="px-2.5 py-1.5 flex items-center justify-between gap-1.5 bg-slate-50 border-b border-slate-100 dark:border-slate-800 dark:bg-slate-800/60">
                            <div class="flex min-w-0 flex-1 items-center gap-0.5 sm:gap-1 overflow-x-auto whitespace-nowrap text-[10px] sm:text-[11px] [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden pr-1">
                                <span class="font-bold text-slate-500 dark:text-slate-400">Cap</span>
                                <span class="font-black text-slate-800 dark:text-slate-100">{{ seatStats.total }}</span>
                                <span class="text-slate-300 dark:text-slate-600">|</span>
                                <span class="font-bold text-blue-500">Bil</span>
                                <span class="font-black text-blue-600">{{ seatStats.soldTickets }}</span>
                                <span class="text-slate-300 dark:text-slate-600">|</span>
                                <span class="font-bold text-rose-500 dark:text-rose-400/80">Occ</span>
                                <span class="font-black text-rose-600 dark:text-rose-400/80">{{ seatStats.occupiedSeats }}</span>
                                <span class="text-slate-300 dark:text-slate-600">|</span>
                                <span class="font-bold text-emerald-500">Lib</span>
                                <span class="font-black text-emerald-600">{{ seatStats.available }}</span>
                                <span class="text-slate-300 dark:text-slate-600">|</span>
                                <span class="font-bold text-sky-500">% Occ</span>
                                <span class="font-black text-sky-600">{{ getOccupancyRate(seatStats.available, seatStats.total) }}%</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-0.5 bg-white rounded border border-slate-200 dark:border-slate-700 dark:bg-slate-900">
                                <button @click="zoomOut" :disabled="zoomLevel <= minZoom" class="p-1 hover:bg-slate-100 disabled:opacity-30 transition-all dark:hover:bg-slate-800" title="Zoom -">
                                    <Minus :size="12" class="text-slate-600" />
                                </button>
                                <button @click="resetZoom" class="text-[10px] font-extrabold text-slate-500 px-1 text-center hover:text-emerald-600 dark:text-slate-400 dark:hover:text-emerald-400" :title="`Zoom : ${Math.round(zoomLevel * 100)}% (Réinitialiser)`">%</button>
                                <button @click="zoomIn" :disabled="zoomLevel >= maxZoom" class="p-1 hover:bg-slate-100 disabled:opacity-30 transition-all dark:hover:bg-slate-800" title="Zoom +">
                                    <Plus :size="12" class="text-slate-600" />
                                </button>
                            </div>
                        </div>

                        <!-- Interactive Seat Map -->
                        <div class="bg-white relative overflow-auto dark:bg-slate-900" style="height: calc(100vh - 220px); min-height: 400px;">
                            <div class="w-full h-full flex items-center justify-center"
                                 :style="{ transform: `scale(${zoomLevel})`, transformOrigin: 'center center' }">
                                <VehicleSeatMapSVG
                                v-if="seatMapReady"
                                :key="seatMapKey"
                                :seat-map="seatMap"
                                :vehicle-type="resolvedVehicleType"
                                :suggested-seats="ticketingStore.suggestedSeats"
                                :selected-seat="ticketingStore.selectedSeat"
                                :selected-color="selectedSeatColor"
                                :show-suggestions="ticketingStore.showSuggestions"
                                :disabled="seatSalesDisabled"
                                :sellable-seat-numbers="currentStationSellableSeatNumbers"
                                :released-seat-numbers="currentStationFreedSeatNumbers"
                                :sellable-seat-border-color="currentStationSellableSeatBorderColor"
                                @seat-click="handleSeatClick"
                                class="w-full h-full"
                                />
                            </div>
                        </div>

                        <!-- "Vendre" button -->
                        <div class="p-3 border-t border-slate-100 dark:border-slate-800">
                            <button
                                @click="router.visit(route(currentTicketingRoute, { trip_id: trip.id }))"
                                class="w-full bg-emerald-600 text-white text-xs font-bold py-2 rounded-lg hover:bg-emerald-700 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2"
                            >
                                <Seat :size="14" />
                                Vendre sur ce voyage
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <DialogModal :show="showVehicleAssignmentModal" @close="closeVehicleAssignmentModal" maxWidth="lg">
            <template #title>
                <div>
                    <div class="text-lg font-black">Assigner un véhicule</div>
                    <div v-if="vehiclePoolStation" class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                        Pool de {{ vehiclePoolStation.name }} · voyage du {{ vehiclePoolDate }}
                    </div>
                </div>
            </template>
            <template #content>
                <div v-if="availableVehiclesLoading" class="py-8">
                    <SkeletonLoader type="list" :count="3" />
                </div>
                <div v-else-if="!assignableVehicles.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center dark:border-amber-900/50 dark:bg-amber-950/20">
                    <Bus :size="32" class="mx-auto mb-2 text-amber-500" />
                    <p class="font-black text-amber-900 dark:text-amber-200">Aucun véhicule disponible dans ce pool</p>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Un administrateur ou gestionnaire de flotte doit affecter un véhicule à cette gare pour cette date.</p>
                </div>
                <div v-else class="space-y-2">
                    <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Seuls les véhicules affectés à cette gare pour la date du voyage sont proposés.</p>
                    <label
                        v-for="vehicle in assignableVehicles"
                        :key="vehicle.id"
                        :class="selectedVehicleId === vehicle.id ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/20' : 'border-slate-200 dark:border-slate-700'"
                        class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3 transition hover:border-emerald-300"
                    >
                        <input v-model="selectedVehicleId" type="radio" :value="vehicle.id" class="text-emerald-600 focus:ring-emerald-500" />
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800"><Bus :size="22" /></span>
                        <span class="min-w-0 flex-1">
                            <strong class="block text-sm text-slate-900 dark:text-slate-100">{{ vehicle.identifier }}</strong>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ vehicle.vehicle_type?.name || vehicle.vehicleType?.name }} · {{ vehicle.seat_count }} places<span v-if="vehicle.maker"> · {{ vehicle.maker }}</span></span>
                        </span>
                    </label>
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="closeVehicleAssignmentModal">Annuler</SecondaryButton>
                <button
                    type="button"
                    :disabled="!selectedVehicleId || assigningVehicle"
                    @click="assignVehicle"
                    class="ml-3 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black text-white disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ assigningVehicle ? 'Assignation…' : 'Assigner ce véhicule' }}
                </button>
            </template>
        </DialogModal>

        <DialogModal :show="showRouteSchemaModal" @close="closeRouteSchemaModal" maxWidth="5xl">
            <template #title>
                <div class="flex flex-col gap-1">
                    <span class="text-2xl font-bold text-slate-900 dark:text-slate-100">Schéma du trajet</span>
                    <span v-if="selectedRouteSchemaTrip" class="text-sm text-slate-500 dark:text-slate-400">
                        {{ selectedRouteSchemaTrip.display_name || selectedRouteSchemaTrip.route?.name }}
                    </span>
                </div>
            </template>
            <template #content>
                <div v-if="!selectedRouteSchemaTrip" class="py-8 text-center text-slate-500 dark:text-slate-400">
                    Aucun voyage sélectionné.
                </div>
                <div v-else-if="selectedRouteSchemaStops.length === 0" class="py-8 text-center text-slate-500 dark:text-slate-400">
                    Aucun arrêt n’est encore configuré pour ce trajet.
                </div>
                <div v-else class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700">
                            Trajet: {{ selectedRouteSchemaTrip.display_name || selectedRouteSchemaTrip.route?.name }}
                        </span>
                        <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900">
                            {{ selectedRouteSchemaStops.length }} gare(s)
                        </span>
                    </div>
                    <RouteSchemaDiagram
                        :stops="selectedRouteSchemaStops"
                        variant="colored"
                        class="shadow-sm"
                    />
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="closeRouteSchemaModal">Fermer</SecondaryButton>
            </template>
        </DialogModal>
    </div>
</template>

<style scoped>
/* Translucent scrollbar for modern feel */
div::-webkit-scrollbar {
    width: 4px;
}
div::-webkit-scrollbar-track {
    background: transparent;
}
div::-webkit-scrollbar-thumb {
    background: rgba(16, 185, 129, 0.12);
    border-radius: 10px;
}
div:hover::-webkit-scrollbar-thumb {
    background: rgba(16, 185, 129, 0.24);
}
</style>
