import { reactive } from 'vue';

export const ticketingStore = reactive({
    selectedSeat: null,
    suggestedSeats: [],
    selectedFareColor: '#22C55E',
    showSuggestions: true,

    selectedTripId: null,
    seatMapVersion: 0,
    lastBookedSeat: null,
    lastRevertedSeat: null,
    clickTimestamp: 0,
    selectedDestinationId: '',
    tripHighlights: {},
    _tripHighlightTimers: {},

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
        this.selectedFareColor = color || '#22C55E';
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

    pulseTrip(tripId, payload = {}) {
        if (!tripId) return;

        const key = String(tripId);
        this.tripHighlights = {
            ...this.tripHighlights,
            [key]: {
                ...payload,
                ts: Date.now(),
            },
        };

        if (this._tripHighlightTimers[key]) {
            clearTimeout(this._tripHighlightTimers[key]);
        }

        this._tripHighlightTimers[key] = setTimeout(() => {
            const nextHighlights = { ...this.tripHighlights };
            delete nextHighlights[key];
            this.tripHighlights = nextHighlights;
            delete this._tripHighlightTimers[key];
        }, 4000);
    },

    clearSelection() {
        this.selectedSeat = null;
        this.suggestedSeats = [];
    }
});
