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
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Minus from 'vue-material-design-icons/Minus.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';
import { ticketingStore } from '@/Stores/ticketingStore.js';

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
const isTicketingPage = computed(() => route().current('seller.ticketing'));

const emit = defineEmits(['seat-click']);

const fetchTrips = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('trips.index'));
        // Filter for today or future trips
        const now = new Date().setHours(0, 0, 0, 0);
        trips.value = response.data.filter(trip => {
            const departure = new Date(trip.departure_at).getTime();
            return departure >= now;
        });
    } catch (error) {
        console.error("Erreur lors de la récupération des voyages:", error);
    } finally {
        loading.value = false;
    }
};

const fetchSeatMap = async (tripId) => {
    if (!tripId) return;
    seatMapLoading.value = true;
    
    // If on ticketing page, we might need stop filters from the parent
    // For now, fetch standard seat map
    try {
        const response = await axios.get(route('seller.trips.seatmap', { trip: tripId }));
        // Keep the whole object to stay consistent with Ticketing.vue
        seatMap.value = response.data;
    } catch (error) {
        console.error("Erreur lors de la récupération du plan de salle:", error);
        seatMap.value = null;
    } finally {
        seatMapLoading.value = false;
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
            router.visit(route('seller.ticketing', { trip_id: trip.id }), {
                preserveState: true,
                preserveScroll: true,
                replace: true
            });
        }
    }
};

const handleSeatClick = (seatNumber) => {
    if (isTicketingPage.value) {
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

onMounted(() => {
    fetchTrips();
});

watch(() => ticketingStore.selectedTripId, (newId) => {
    if (newId && newId !== selectedTripId.value) {
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
    if (newId && newId !== selectedTripId.value) {
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
    if (refreshInterval) clearInterval(refreshInterval);
});

// The selected trip object (from trips array)
const selectedTrip = computed(() => {
    return trips.value.find(t => t.id === selectedTripId.value);
});

const filteredTrips = computed(() => {
    let result = trips.value;
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
    return seatMap.value?.vehicle_type || selectedTrip.value?.vehicle?.vehicle_type;
});

// Stats for the selected trip
const seatStats = computed(() => {
    if (!seatMap.value || !seatMap.value.seat_map) return null;
    return {
        total: seatMap.value.total_seats || 0,
        occupied: seatMap.value.occupied_seats_count || seatMap.value.occupied_seats || 0,
        available: seatMap.value.available_seats_count || seatMap.value.available_seats || 0
    };
});
</script>

<template>
    <div class="flex flex-col h-full bg-white border-l border-orange-100 overflow-hidden shadow-xl w-[320px]">
        <!-- Header -->
        <div class="p-5 bg-gradient-to-br from-green-50 to-orange-50/30 border-b border-orange-50 flex items-center justify-between shrink-0">
            <div>
                <h2 class="text-base font-black text-gray-800 flex items-center gap-2">
                    <Bus :size="20" class="text-green-600" />
                    Voyages
                </h2>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-wider">Plan & Occupations</p>
            </div>
            <button @click="fetchTrips" :disabled="loading" class="p-2 hover:bg-white rounded-xl shadow-sm border border-transparent hover:border-orange-100 transition-all text-gray-400 hover:text-green-600 disabled:opacity-50">
                <Refresh :size="18" :class="{ 'animate-spin': loading }" />
            </button>
        </div>

        <!-- TICKETING PAGE: Show only selected trip seat map (workspace mode) -->
        <template v-if="isTicketingPage">
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Compact selected trip info -->
                <div v-if="selectedTrip" class="px-3 py-2 bg-green-50/50 border-b border-green-100">
                    <div class="flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse shrink-0"></div>
                        <div class="text-xs font-bold text-gray-900 truncate">{{ selectedTrip.display_name || selectedTrip.route?.name }}</div>
                    </div>
                    <div class="flex items-center gap-2 mt-0.5 pl-3.5">
                        <span class="text-[10px] font-black text-orange-600 uppercase tracking-widest">{{ selectedTrip.vehicle?.identifier }}</span>
                        <span class="text-[10px] font-bold text-gray-400">{{ formatTime(selectedTrip.departure_at) }}</span>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="seatMapLoading" class="flex-1 flex flex-col items-center justify-center">
                    <div class="animate-spin mb-2"><Refresh :size="24" class="text-green-600" /></div>
                    <span class="text-xs text-gray-500">Chargement du plan...</span>
                </div>

                <!-- Seat Map -->
                <template v-else-if="seatMap && seatStats && vehicleType">
                    <!-- Stats Row -->
                    <div class="px-3 py-2 flex items-center justify-between bg-gray-50 border-b border-gray-100 shrink-0">
                        <div class="flex items-center gap-1 text-xs">
                            <span class="font-bold text-gray-500">Cap</span>
                            <span class="font-black text-gray-800">{{ seatStats.total }}</span>
                            <span class="mx-1 text-gray-300">|</span>
                            <span class="font-bold text-red-500">Occ</span>
                            <span class="font-black text-red-600">{{ seatStats.occupied }}</span>
                            <span class="mx-1 text-gray-300">|</span>
                            <span class="font-bold text-green-500">Lib</span>
                            <span class="font-black text-green-600">{{ seatStats.available }}</span>
                        </div>
                        <!-- Zoom Controls -->
                        <div class="flex items-center gap-0.5 bg-white rounded border border-gray-200">
                            <button @click="zoomOut" :disabled="zoomLevel <= minZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all" title="Zoom -">
                                <Minus :size="12" class="text-gray-600" />
                            </button>
                            <span class="text-[10px] font-bold text-gray-500 px-1 min-w-[32px] text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                            <button @click="zoomIn" :disabled="zoomLevel >= maxZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all" title="Zoom +">
                                <Plus :size="12" class="text-gray-600" />
                            </button>
                        </div>
                    </div>

                    <!-- Seat Map SVG - Full remaining height -->
                    <div class="flex-1 bg-white relative overflow-auto">
                        <div class="w-full h-full flex items-center justify-center"
                             :style="{ transform: `scale(${zoomLevel})`, transformOrigin: 'center center' }">
                            <VehicleSeatMapSVG
                                :key="seatMapKey"
                                :seat-map="seatMap"
                                :vehicle-type="vehicleType"
                                :suggested-seats="ticketingStore.suggestedSeats"
                                :selected-seat="ticketingStore.selectedSeat"
                                :selected-color="ticketingStore.selectedFareColor"
                                :show-suggestions="ticketingStore.showSuggestions"
                                @seat-click="handleSeatClick"
                                class="w-full h-full"
                            />
                        </div>
                    </div>
                </template>

                <!-- No trip selected -->
                <div v-else class="flex-1 flex flex-col items-center justify-center text-gray-400 px-4">
                    <Bus :size="32" class="mb-2 opacity-30" />
                    <p class="text-xs text-center">Sélectionnez un voyage pour voir le plan</p>
                </div>
            </div>
        </template>

        <!-- OTHER PAGES: Show full trip list with expandable seat maps -->
        <div v-else class="flex-1 overflow-y-auto p-3 space-y-3">
            <div v-if="loading && trips.length === 0" class="flex flex-col items-center justify-center py-10 text-gray-400">
                <div class="animate-spin mb-2"><Refresh :size="32" /></div>
                <span>Chargement des voyages...</span>
            </div>

            <div v-else-if="trips.length === 0" class="text-center py-10 text-gray-500 italic px-4">
                Aucun voyage disponible pour le moment.
            </div>

            <div v-else-if="filteredTrips.length === 0" class="text-center py-10 text-gray-500 italic px-4">
                Aucun voyage ne correspond à cette destination.
            </div>

            <div v-else v-for="trip in filteredTrips" :key="trip.id"
                class="border-2 rounded-2xl overflow-hidden transition-all duration-300"
                :class="selectedTripId === trip.id ? 'border-green-500 shadow-lg' : 'border-transparent bg-gray-50 hover:border-orange-200 hover:bg-white hover:shadow-md'"
            >
                <!-- Trip Summary Header -->
                <div @click="selectTrip(trip)"
                    class="p-3 cursor-pointer"
                    :class="selectedTripId === trip.id ? 'bg-green-50/50' : ''"
                >
                    <div class="flex items-center gap-2 mb-1">
                        <Bus :size="16" :class="selectedTripId === trip.id ? 'text-green-600' : 'text-gray-400'" />
                        <div class="text-sm font-bold text-gray-900 tracking-tight leading-snug">{{ trip.display_name || trip.route?.name }}</div>
                        <span
                            :title="trip.sales_control === 'open' ? 'Ventes intermédiaires autorisées' : 'Ventes origine uniquement'"
                            class="text-xs shrink-0"
                        >{{ trip.sales_control === 'open' ? '🔓' : '🔒' }}</span>
                        <span v-if="selectedTripId === trip.id" class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                    </div>
                    <div class="flex items-center gap-3 pl-6">
                        <span class="text-[10px] font-black text-orange-600 uppercase tracking-widest">
                            {{ trip.vehicle?.identifier }}
                        </span>
                        <span class="text-[10px] font-bold text-gray-400">
                            {{ formatTime(trip.departure_at) }}
                        </span>
                        <ChevronRight v-if="selectedTripId !== trip.id" :size="14" class="text-gray-400 ml-auto" />
                    </div>
                </div>

                <!-- Expanded Content (Seat Map) -->
                <div v-if="selectedTripId === trip.id" class="border-t border-green-100 bg-white">
                    <div v-if="seatMapLoading" class="flex flex-col items-center justify-center py-8">
                        <div class="animate-spin mb-2"><Refresh :size="24" class="text-green-600" /></div>
                        <span class="text-xs text-gray-500">Chargement du plan...</span>
                    </div>

                    <div v-else-if="seatMap">
                        <!-- Compact Stats Row -->
                        <div class="px-3 py-2 flex items-center justify-between bg-gray-50 border-b border-gray-100">
                            <div class="flex items-center gap-1 text-xs">
                                <span class="font-bold text-gray-500">Cap</span>
                                <span class="font-black text-gray-800">{{ seatStats.total }}</span>
                                <span class="mx-1 text-gray-300">|</span>
                                <span class="font-bold text-red-500">Occ</span>
                                <span class="font-black text-red-600">{{ seatStats.occupied }}</span>
                                <span class="mx-1 text-gray-300">|</span>
                                <span class="font-bold text-green-500">Lib</span>
                                <span class="font-black text-green-600">{{ seatStats.available }}</span>
                            </div>
                            <div class="flex items-center gap-0.5 bg-white rounded border border-gray-200">
                                <button @click="zoomOut" :disabled="zoomLevel <= minZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all" title="Zoom -">
                                    <Minus :size="12" class="text-gray-600" />
                                </button>
                                <span class="text-[10px] font-bold text-gray-500 px-1 min-w-[32px] text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                                <button @click="zoomIn" :disabled="zoomLevel >= maxZoom" class="p-1 hover:bg-gray-100 disabled:opacity-30 transition-all" title="Zoom +">
                                    <Plus :size="12" class="text-gray-600" />
                                </button>
                            </div>
                        </div>

                        <!-- Interactive Seat Map -->
                        <div class="bg-white relative overflow-auto" style="height: calc(100vh - 220px); min-height: 400px;">
                            <div class="w-full h-full flex items-center justify-center"
                                 :style="{ transform: `scale(${zoomLevel})`, transformOrigin: 'center center' }">
                                <VehicleSeatMapSVG
                                    v-if="vehicleType"
                                    :key="seatMapKey"
                                    :seat-map="seatMap"
                                    :vehicle-type="vehicleType"
                                    :suggested-seats="ticketingStore.suggestedSeats"
                                    :selected-seat="ticketingStore.selectedSeat"
                                    :selected-color="ticketingStore.selectedFareColor"
                                    :show-suggestions="ticketingStore.showSuggestions"
                                    @seat-click="handleSeatClick"
                                    class="w-full h-full"
                                />
                            </div>
                        </div>

                        <!-- "Vendre" button -->
                        <div class="p-3 border-t border-gray-100">
                            <button
                                @click="router.visit(route('seller.ticketing', { trip_id: trip.id }))"
                                class="w-full bg-green-600 text-white text-xs font-bold py-2 rounded-lg hover:bg-green-700 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2"
                            >
                                <Seat :size="14" />
                                Vendre sur ce voyage
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
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
    background: rgba(234, 88, 12, 0.1);
    border-radius: 10px;
}
div:hover::-webkit-scrollbar-thumb {
    background: rgba(234, 88, 12, 0.2);
}
</style>
