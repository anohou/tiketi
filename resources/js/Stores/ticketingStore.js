import { reactive } from 'vue';

export const ticketingStore = reactive({
    selectedSeat: null,
    suggestedSeats: [],
    selectedFareColor: '#3B82F6',
    showSuggestions: true,

    selectedTripId: null,
    seatMapVersion: 0,
    lastBookedSeat: null,
    lastRevertedSeat: null,
    clickTimestamp: 0,
    selectedDestinationId: '',

    setDestinationFilter(id) {
        this.selectedDestinationId = id;
    },

    selectSeat(seatNumber) {
        this.selectedSeat = seatNumber;
        this.clickTimestamp = Date.now();
    },

    setSelectedTripId(id) {
        this.selectedTripId = id;
    },

    setSuggestions(suggestions) {
        this.suggestedSeats = suggestions || [];
    },

    setFareColor(color) {
        this.selectedFareColor = color || '#3B82F6';
    },

    setShowSuggestions(show) {
        this.showSuggestions = show;
    },

    notifySeatMapChanged() {
        this.seatMapVersion++;
    },

    // Supports single seat or array of seats
    notifySeatBooked(seatOrSeats, color) {
        const seats = Array.isArray(seatOrSeats) ? seatOrSeats : [seatOrSeats];
        this.lastBookedSeat = { seats, color: color || null, ts: Date.now() };
    },

    // Supports single seat or array of seats
    notifySeatReverted(seatOrSeats) {
        const seats = Array.isArray(seatOrSeats) ? seatOrSeats : [seatOrSeats];
        this.lastRevertedSeat = { seats, ts: Date.now() };
    },

    clearSelection() {
        this.selectedSeat = null;
        this.suggestedSeats = [];
    }
});
