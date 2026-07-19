<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Gift from 'vue-material-design-icons/Gift.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue';
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'passenger',
  },
  currentTrip: {
    type: Object,
    default: null,
  },
  selectedSeatNumber: {
    type: [String, Number],
    default: null,
  },
  selectedFare: {
    type: Object,
    default: null,
  },
  availableFares: {
    type: Array,
    default: () => [],
  },
  seatsToBook: {
    type: Array,
    default: () => [],
  },
  passengerForm: {
    type: Object,
    required: true,
  },
  passengerFormErrors: {
    type: Object,
    default: () => ({}),
  },
  processing: {
    type: Boolean,
    default: false,
  },
  ticketQuantity: {
    type: Number,
    default: 1,
  },
  showPassengerFields: {
    type: Boolean,
    default: false,
  },
  okohiIntegrationActive: {
    type: Boolean,
    default: false,
  },
  connectionFares: { type: Array, default: () => [] },
  connectionRoutes: { type: Array, default: () => [] },
  finalDestinationStationId: { type: String, default: null },
  connectionRouteId: { type: String, default: null },
  // Okohi reward claim waiting state
  okohiClaimId: { type: String, default: null },
  okohiRewardTitle: { type: String, default: null },
  okohiClaimExpiresAt: { type: String, default: null },
});

const emit = defineEmits([
  'close',
  'confirm',
  'select-fare',
  'update:ticketQuantity',
  'update:showPassengerFields',
  'update:finalDestinationStationId',
  'update:connectionRouteId',
  'okohi-claim-approved',
  'okohi-claim-rejected',
  'okohi-claim-expired',
  'okohi-success',
]);

const isDestinationMode = computed(() => props.mode === 'destination');
const modalRef = ref(null);
const dragHandleRef = ref(null);
const isDragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const modalPosition = ref({ x: 0, y: 0 });

const STORAGE_KEY = 'tiketi.bookingModal.position';
const DEFAULT_MODAL_WIDTH = 384;
const DEFAULT_MODAL_HEIGHT = 600;
const EDGE_MARGIN = 16;

const getViewportBounds = () => ({
  width: window.innerWidth,
  height: window.innerHeight,
});

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const getDefaultPosition = () => {
  const { width, height } = getViewportBounds();
  return {
    x: clamp(width - DEFAULT_MODAL_WIDTH - 24, EDGE_MARGIN, Math.max(EDGE_MARGIN, width - EDGE_MARGIN - DEFAULT_MODAL_WIDTH)),
    y: clamp(24, EDGE_MARGIN, Math.max(EDGE_MARGIN, height - EDGE_MARGIN - DEFAULT_MODAL_HEIGHT)),
  };
};

const readStoredPosition = () => {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw);
    if (typeof parsed?.x !== 'number' || typeof parsed?.y !== 'number') {
      return null;
    }

    return parsed;
  } catch {
    return null;
  }
};

const persistPosition = () => {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(modalPosition.value));
  } catch {
    // Ignore storage failures.
  }
};

const clampPositionToViewport = () => {
  if (typeof window === 'undefined') {
    return;
  }

  const modalElement = modalRef.value;
  const bounds = getViewportBounds();
  const width = modalElement?.offsetWidth || DEFAULT_MODAL_WIDTH;
  const height = modalElement?.offsetHeight || DEFAULT_MODAL_HEIGHT;

  modalPosition.value = {
    x: clamp(modalPosition.value.x, EDGE_MARGIN, Math.max(EDGE_MARGIN, bounds.width - EDGE_MARGIN - width)),
    y: clamp(modalPosition.value.y, EDGE_MARGIN, Math.max(EDGE_MARGIN, bounds.height - EDGE_MARGIN - height)),
  };
};

const restorePosition = async () => {
  if (typeof window === 'undefined') {
    return;
  }

  const storedPosition = readStoredPosition();
  modalPosition.value = storedPosition || getDefaultPosition();
  await nextTick();
  clampPositionToViewport();
};

const stopDragging = () => {
  if (!isDragging.value) {
    return;
  }

  isDragging.value = false;
  persistPosition();
};

const startDragging = (event) => {
  if (event.button !== 0 || typeof window === 'undefined') {
    return;
  }

  const modalElement = modalRef.value;
  if (!modalElement) {
    return;
  }

  const bounds = modalElement.getBoundingClientRect();
  isDragging.value = true;
  dragOffset.value = {
    x: event.clientX - bounds.left,
    y: event.clientY - bounds.top,
  };

  event.preventDefault();
};

const handleDragging = (event) => {
  if (!isDragging.value) {
    return;
  }

  const modalElement = modalRef.value;
  const width = modalElement?.offsetWidth || DEFAULT_MODAL_WIDTH;
  const height = modalElement?.offsetHeight || DEFAULT_MODAL_HEIGHT;
  const { width: viewportWidth, height: viewportHeight } = getViewportBounds();

  modalPosition.value = {
    x: clamp(event.clientX - dragOffset.value.x, EDGE_MARGIN, Math.max(EDGE_MARGIN, viewportWidth - EDGE_MARGIN - width)),
    y: clamp(event.clientY - dragOffset.value.y, EDGE_MARGIN, Math.max(EDGE_MARGIN, viewportHeight - EDGE_MARGIN - height)),
  };
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      restorePosition();
    } else {
      stopDragging();
      if (okohiRequest.value) {
        cancelOkohiRequest();
      }
    }
  }
);

onMounted(() => {
  restorePosition();
  window.addEventListener('mousemove', handleDragging);
  window.addEventListener('mouseup', stopDragging);
  window.addEventListener('resize', clampPositionToViewport);
});

onBeforeUnmount(() => {
  window.removeEventListener('mousemove', handleDragging);
  window.removeEventListener('mouseup', stopDragging);
  window.removeEventListener('resize', clampPositionToViewport);
  if (okohiRequest.value) {
    cancelOkohiRequest();
  }
});

// ─── Okohi claim polling ───────────────────────────────────────
const claimStatus = ref(null); // null | 'pending' | 'approved' | 'rejected' | 'expired'
const claimSecondsLeft = ref(0);
let claimPollInterval = null;
let claimCountdownInterval = null;

const isOkohiWaiting = computed(() => !!props.okohiClaimId && claimStatus.value === 'pending');
const isOkohiApproved = computed(() => claimStatus.value === 'approved');
const isOkohiRejected = computed(() => claimStatus.value === 'rejected' || claimStatus.value === 'expired');

const stopClaimTracking = () => {
  if (claimPollInterval) { clearInterval(claimPollInterval); claimPollInterval = null; }
  if (claimCountdownInterval) { clearInterval(claimCountdownInterval); claimCountdownInterval = null; }
};

const startClaimCountdown = () => {
  if (!props.okohiClaimExpiresAt) { claimSecondsLeft.value = 600; }
  else {
    claimSecondsLeft.value = Math.max(0, Math.floor((new Date(props.okohiClaimExpiresAt) - Date.now()) / 1000));
  }
  claimCountdownInterval = setInterval(() => {
    if (claimSecondsLeft.value > 0) claimSecondsLeft.value--;
    else stopClaimTracking();
  }, 1000);
};

const pollClaim = async () => {
  if (!props.okohiClaimId) return;
  try {
    const { data } = await axios.get(`/api/okohi/claims/${props.okohiClaimId}/status`);
    const status = data?.data?.status ?? data?.status;
    if (status && status !== 'pending') {
      claimStatus.value = status;
      stopClaimTracking();
      if (status === 'approved') emit('okohi-claim-approved', data?.data ?? data);
      else emit('okohi-claim-rejected', data?.data ?? data);
    }
  } catch {
    // silently ignore transient network errors, keep polling
  }
};

watch(() => props.okohiClaimId, (id) => {
  stopClaimTracking();
  if (id) {
    claimStatus.value = 'pending';
    startClaimCountdown();
    claimPollInterval = setInterval(pollClaim, 3000);
  } else {
    claimStatus.value = null;
  }
});

onBeforeUnmount(() => stopClaimTracking());

const claimCountdownLabel = computed(() => {
  const m = Math.floor(claimSecondsLeft.value / 60);
  const s = claimSecondsLeft.value % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
});

const ticketQuantityModel = computed({
  get: () => props.ticketQuantity,
  set: (value) => emit('update:ticketQuantity', Number(value)),
});

const showPassengerFieldsModel = computed({
  get: () => props.showPassengerFields,
  set: (value) => emit('update:showPassengerFields', value),
});

const finalDestinationModel = computed({
  get: () => props.finalDestinationStationId,
  set: (value) => emit('update:finalDestinationStationId', value || null),
});
const connectionRouteModel = computed({
  get: () => props.connectionRouteId,
  set: (value) => emit('update:connectionRouteId', value || null),
});

const currentTripStationIndices = computed(() => {
  const route = props.currentTrip?.route;
  if (!route) return {};

  const stops = route.route_stop_orders || route.routeStopOrders || [];
  const stationIds = [
    route.origin_station_id,
    ...[...stops].sort((a, b) => a.stop_index - b.stop_index).map(stop => stop.station_id),
    route.destination_station_id,
  ].filter((stationId, index, ids) => stationId && ids.indexOf(stationId) === index);

  const originIndex = stationIds.indexOf(props.currentTrip.origin_station_id);
  const destinationIndex = stationIds.indexOf(props.currentTrip.destination_station_id);
  const orderedStationIds = originIndex > destinationIndex ? [...stationIds].reverse() : stationIds;

  return Object.fromEntries(orderedStationIds.map((stationId, index) => [stationId, index]));
});

const isServedByCurrentTripAfter = (transferId, destinationId) => {
  const indices = currentTripStationIndices.value;
  const transferIndex = indices[transferId];
  const destinationIndex = indices[destinationId];
  const tripDestinationIndex = indices[props.currentTrip?.destination_station_id];

  return Number.isInteger(transferIndex)
    && Number.isInteger(destinationIndex)
    && Number.isInteger(tripDestinationIndex)
    && transferIndex < destinationIndex
    && destinationIndex <= tripDestinationIndex;
};

const routeStationIds = (route) => {
  const stops = route.route_stop_orders || route.routeStopOrders || [];

  return [
    route.origin_station_id,
    ...[...stops].sort((a, b) => a.stop_index - b.stop_index).map(stop => stop.station_id),
    route.destination_station_id,
  ].filter((stationId, index, stationIds) => stationId && stationIds.indexOf(stationId) === index);
};

const routeConnectsInTravelDirection = (route, transferId, destinationId) => {
  const stationIds = routeStationIds(route);
  const transferIndex = stationIds.indexOf(transferId);
  const destinationIndex = stationIds.indexOf(destinationId);

  return transferIndex !== -1 && destinationIndex !== -1 && transferIndex < destinationIndex;
};

const availableConnectionFares = computed(() => {
  if (!props.currentTrip?.allows_open_connections || !props.selectedFare?.from_station_id) return [];
  const originId = props.selectedFare.from_station_id;
  const transferId = props.selectedFare.to_station_id;
  return props.connectionFares.flatMap(fare => {
    let isCandidate = false;
    let connDestId = null;
    let connDest = null;

    if (fare.from_station_id === originId && fare.to_station_id !== transferId) {
      isCandidate = true;
      connDestId = fare.to_station_id;
      connDest = fare.to_station;
    } else if (fare.is_bidirectional && fare.to_station_id === originId && fare.from_station_id !== transferId) {
      isCandidate = true;
      connDestId = fare.from_station_id;
      connDest = fare.from_station;
    }

    if (!isCandidate || !connDestId || isServedByCurrentTripAfter(transferId, connDestId)) return [];

    const hasRoute = props.connectionRoutes.some(route => routeConnectsInTravelDirection(route, transferId, connDestId));

    if (!hasRoute) return [];

    return [{ ...fare, connection_destination_id: connDestId, connection_destination: connDest }];
  });
});

const selectedConnectionFare = computed(() => availableConnectionFares.value.find(fare => fare.connection_destination_id === finalDestinationModel.value));

const compatibleConnectionRoutes = computed(() => {
  if (!finalDestinationModel.value || !props.selectedFare?.to_station_id) return [];
  const transferId = props.selectedFare.to_station_id;
  return props.connectionRoutes.filter(route => routeConnectsInTravelDirection(route, transferId, finalDestinationModel.value));
});

watch(availableConnectionFares, (fares) => {
  if (finalDestinationModel.value && !fares.some(fare => fare.connection_destination_id === finalDestinationModel.value)) {
    finalDestinationModel.value = null;
  }
});

watch(compatibleConnectionRoutes, (routes) => {
  if (!routes.some(route => route.id === connectionRouteModel.value)) {
    connectionRouteModel.value = routes[0]?.id || null;
  }
}, { immediate: true });

const seatLabel = computed(() => {
  if (props.seatsToBook.length > 1) {
    return `Places ${props.seatsToBook.join(', ')}`;
  }
  return `Place ${props.selectedSeatNumber}`;
});

const routeLabel = computed(() => {
  const from = props.selectedFare?.from_station?.name;
  const to = props.selectedFare?.to_station?.name;
  const finalDestination = props.selectedFare?.sale_destination?.name;
  if (from && to && finalDestination) {
    return `${from} → ${finalDestination} (correspondance à ${to})`;
  }
  if (from && to) {
    return `${from} → ${to}`;
  }
  return props.currentTrip?.route?.name || props.currentTrip?.display_name || '---';
});

const amountLabel = computed(() => {
  if (useOkohi.value && okohiAmounts.value) {
    return `${okohiAmounts.value.net.toLocaleString('fr-FR')} FCFA`;
  }
  const amount = selectedConnectionFare.value?.amount ?? props.selectedFare?.amount ?? 0;
  return `${amount.toLocaleString('fr-FR')} FCFA`;
});

const totalLabel = computed(() => {
  if (useOkohi.value && okohiAmounts.value) {
    return `${okohiAmounts.value.net.toLocaleString('fr-FR')} FCFA`;
  }
  const amount = selectedConnectionFare.value?.amount ?? props.selectedFare?.amount ?? 0;
  return `${(amount * props.ticketQuantity).toLocaleString('fr-FR')} FCFA`;
});

// Okohi Integration States
const useOkohi = ref(false);
const okohiCardNumber = ref('OKH-');
const isOkohiSearching = ref(false);
const okohiSearchError = ref(null);
const okohiCustomer = ref(null);
const selectedReward = ref(null);
const okohiRequest = ref(null);
const countdown = ref(180);
const processingOkohi = ref(false);
const idempotencyKey = ref('');
const confirmingCash = ref(false);

let countdownInterval = null;
let pollingInterval = null;

const preg_match_js = (pattern, str) => {
  return pattern.test(str);
};

const okohiAmounts = computed(() => {
  if (!props.selectedFare || !useOkohi.value) return null;
  const gross = selectedConnectionFare.value?.amount ?? props.selectedFare?.amount ?? 0;
  if (!selectedReward.value) return { gross, discount: 0, net: gross };

  const title = selectedReward.value.title;
  let discount = gross; // Default to free ticket

  if (preg_match_js(/50\s*%/i, title)) {
    discount = Math.floor(gross * 0.5);
  } else if (preg_match_js(/25\s*%/i, title)) {
    discount = Math.floor(gross * 0.25);
  } else if (preg_match_js(/75\s*%/i, title)) {
    discount = Math.floor(gross * 0.75);
  }

  return { gross, discount, net: gross - discount };
});

const generateIdempotencyKey = () => {
  return 'idemp-' + Math.random().toString(36).substring(2, 15) + '-' + Date.now();
};

const resetOkohi = () => {
  useOkohi.value = false;
  okohiCardNumber.value = 'OKH-';
  isOkohiSearching.value = false;
  okohiSearchError.value = null;
  okohiCustomer.value = null;
  selectedReward.value = null;
  okohiRequest.value = null;
  if (countdownInterval) clearInterval(countdownInterval);
  if (pollingInterval) clearInterval(pollingInterval);
};

watch(useOkohi, (val) => {
  if (val) {
    ticketQuantityModel.value = 1;
    idempotencyKey.value = generateIdempotencyKey();
  } else {
    resetOkohi();
  }
});

const eligibleRewards = computed(() => {
  if (!okohiCustomer.value || !okohiCustomer.value.rewards) return [];
  return okohiCustomer.value.rewards.filter(r => r.can_grant);
});

const okohiCustomerName = computed(() => {
  const customer = okohiCustomer.value?.customer;
  if (!customer) return 'Client Okohi';
  return customer.name || [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Client Okohi';
});

const okohiBalanceLabel = computed(() => {
  const balance = okohiCustomer.value?.balance;
  if (!balance) return '0 point';
  if (balance.loyalty_type === 'frequency') {
    const visits = balance.visits_balance ?? 0;
    return `${visits} voyage${visits > 1 ? 's' : ''}`;
  }
  const points = balance.points_balance ?? 0;
  return `${points} point${points > 1 ? 's' : ''}`;
});

const isOkohiExpanded = computed(() => useOkohi.value && !!okohiCustomer.value && !okohiRequest.value);
let modalExpansionTimer = null;

const formatOkohiTripDate = (value) => {
  if (!value) return 'Date non renseignée';
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value));
};

watch(() => props.okohiIntegrationActive, (active) => {
  if (!active && useOkohi.value) resetOkohi();
});

watch(isOkohiExpanded, async () => {
  await nextTick();
  clampPositionToViewport();
  if (modalExpansionTimer) window.clearTimeout(modalExpansionTimer);
  modalExpansionTimer = window.setTimeout(clampPositionToViewport, 320);
});

const searchOkohiCustomer = async () => {
  if (!okohiCardNumber.value || okohiCardNumber.value.trim() === 'OKH-') return;
  isOkohiSearching.value = true;
  okohiSearchError.value = null;
  okohiCustomer.value = null;
  selectedReward.value = null;

  try {
    const response = await axios.get(route('seller.okohi.customer', { customerNumber: okohiCardNumber.value.trim().toUpperCase() }));
    okohiCustomer.value = response.data;
  } catch (error) {
    console.error(error);
    okohiSearchError.value = error.response?.data?.error || 'Client introuvable ou erreur de connexion.';
  } finally {
    isOkohiSearching.value = false;
  }
};

const initiateOkohiRequest = async () => {
  if (!selectedReward.value) return;

  processingOkohi.value = true;
  okohiSearchError.value = null;

  try {
    const payload = {
      trip_id: props.currentTrip.id,
      from_station_id: props.selectedFare.from_station_id,
      to_station_id: props.selectedFare.to_station_id,
      seat_number: props.selectedSeatNumber,
      customer_number: okohiCardNumber.value.trim().toUpperCase(),
      reward_id: selectedReward.value.id,
      idempotency_key: idempotencyKey.value,
    };

    const response = await axios.post(route('seller.okohi.requests.store'), payload);
    okohiRequest.value = response.data;

    startCountdown();
    startPolling();
  } catch (error) {
    console.error(error);
    okohiSearchError.value = error.response?.data?.error || 'Erreur lors de l\'envoi de la demande.';
  } finally {
    processingOkohi.value = false;
  }
};

const startCountdown = () => {
  if (countdownInterval) clearInterval(countdownInterval);
  countdown.value = 180;
  countdownInterval = setInterval(() => {
    if (countdown.value > 0) {
      countdown.value--;
    } else {
      handleTimeout();
    }
  }, 1000);
};

const handleTimeout = () => {
  okohiSearchError.value = 'La demande a expiré sans validation de la part du client.';
  cancelOkohiRequest();
};

const startPolling = () => {
  if (pollingInterval) clearInterval(pollingInterval);
  pollingInterval = setInterval(async () => {
    if (!okohiRequest.value) return;
    try {
      const response = await axios.get(route('seller.okohi.requests.show', { request: okohiRequest.value.id }));
      const req = response.data;
      okohiRequest.value = req;

      if (req.status === 'confirmed') {
        clearInterval(countdownInterval);
        clearInterval(pollingInterval);
        emit('okohi-success', req.ticket_id);
      } else if (req.status === 'approved_pending_cash') {
        clearInterval(countdownInterval);
      } else if (req.status === 'rejected' || req.status === 'expired' || req.status === 'failed') {
        clearInterval(countdownInterval);
        clearInterval(pollingInterval);
        okohiSearchError.value = req.status === 'rejected'
          ? 'Demande refusée par le client.'
          : (req.status === 'expired' ? 'Demande expirée.' : 'Échec de la validation: ' + (req.last_error || ''));
        okohiRequest.value = null;
      }
    } catch (error) {
      console.error('Polling error', error);
    }
  }, 3000);
};

const cancelOkohiRequest = async () => {
  if (!okohiRequest.value) return;
  const requestId = okohiRequest.value.id;

  clearInterval(countdownInterval);
  clearInterval(pollingInterval);
  okohiRequest.value = null;

  try {
    await axios.delete(route('seller.okohi.requests.destroy', { request: requestId }));
  } catch (error) {
    console.error('Failed to cancel request on server', error);
  }
};

const confirmCashPayment = async () => {
  if (!okohiRequest.value) return;
  confirmingCash.value = true;
  const requestId = okohiRequest.value.id;
  try {
    const response = await axios.post(route('seller.okohi.requests.confirm-cash', { request: requestId }));
    clearInterval(countdownInterval);
    clearInterval(pollingInterval);
    emit('okohi-success', response.data.ticket_id);
  } catch (error) {
    console.error('Failed to confirm cash payment', error);
    okohiSearchError.value = "Erreur lors de l'encaissement : " + (error.response?.data?.error || error.message);
  } finally {
    confirmingCash.value = false;
  }
};

const formatTime = (seconds) => {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
};

onBeforeUnmount(() => {
  if (countdownInterval) clearInterval(countdownInterval);
  if (pollingInterval) clearInterval(pollingInterval);
  if (modalExpansionTimer) window.clearTimeout(modalExpansionTimer);
});
</script>

<template>
  <div
    v-if="visible"
    class="fixed inset-0 z-[1010] bg-black/10 dark:bg-black/40 p-3 md:p-4"
  >
    <div
      ref="modalRef"
      class="absolute w-[calc(100vw-1.5rem)] bg-white/90 dark:bg-slate-900/90 rounded-3xl shadow-[0_24px_70px_rgba(15,23,42,0.16)] dark:shadow-black/40 border border-white/60 dark:border-slate-800/80 max-h-[84vh] overflow-hidden backdrop-blur-sm transition-[width] duration-300 ease-out md:max-w-[calc(100vw-2rem)]"
      :class="isDestinationMode
        ? 'max-w-6xl md:w-[64rem] md:max-w-none'
        : isOkohiExpanded
          ? 'md:w-[52rem]'
          : 'max-w-xl md:w-[24rem] md:max-w-none'"
      :style="{
        left: `${modalPosition.x}px`,
        top: `${modalPosition.y}px`,
      }"
    >
      <div
        ref="dragHandleRef"
        class="p-4 border-b border-white/60 dark:border-slate-800/80 flex items-center justify-between bg-white/70 dark:bg-slate-900/70 cursor-move select-none"
        @mousedown="startDragging"
      >
        <div>
          <h3 class="text-lg font-black text-gray-900 dark:text-slate-100">
            {{ isDestinationMode ? 'Choisir une destination' : 'Informations Passager' }}
          </h3>
          <p class="text-xs text-gray-500 dark:text-slate-400">
            Siège {{ selectedSeatNumber }} sélectionné
          </p>
        </div>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-pointer">
          <Close class="w-6 h-6" />
        </button>
      </div>

      <div
        class="overflow-y-auto p-3 md:p-4 max-h-[calc(84vh-76px)]"
        :class="isDestinationMode ? 'md:grid md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] md:items-start md:gap-4' : ''"
      >
        <div v-if="isDestinationMode" class="min-w-0 space-y-3 md:order-2">
          <div v-if="availableFares.length > 0" class="space-y-3">
            <button
              v-for="fare in availableFares"
              :key="fare.id"
              type="button"
              @click="$emit('select-fare', fare)"
              class="w-full text-left relative overflow-hidden rounded-2xl transition-all duration-200 border-2 border-transparent shadow-sm hover:shadow-lg active:scale-[0.99]"
              :style="{ backgroundColor: fare.color || '#0f766e' }"
            >
              <div class="p-3 md:p-4 flex items-center justify-between gap-3 md:gap-4">
                <div class="min-w-0">
                  <div class="text-base md:text-lg font-black truncate" :style="{ color: fare.textColor || '#FFFFFF' }">
                    {{ (fare.sale_destination || fare.to_station)?.name }}
                  </div>
                  <div class="flex items-center gap-2 text-[11px] md:text-xs font-medium" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">
                    <span v-if="fare.is_connection" class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                      <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
                      <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white/40"></span>
                    </span>
                    <template v-if="fare.is_connection">Correspondance à {{ fare.transfer_station?.name }}</template>
                    <template v-else>→ depuis {{ fare.from_station?.name?.split(' - ')[1] || fare.from_station?.name }}</template>
                  </div>
                </div>
                <div class="text-right shrink-0">
                  <div class="text-xl md:text-2xl font-black" :style="{ color: fare.textColor || '#FFFFFF' }">
                    {{ fare.amount.toLocaleString('fr-FR') }}
                  </div>
                  <div class="text-[10px] font-bold" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">FCFA</div>
                </div>
              </div>
            </button>
          </div>
          <div v-else class="p-8 text-center text-gray-500 dark:text-slate-400">
            Aucune destination disponible pour ce voyage.
          </div>
        </div>

        <div class="min-w-0" :class="isDestinationMode ? 'md:order-1' : ''">
          <!-- ── Okohi claim: approved ── -->
          <div v-if="isOkohiApproved" class="p-5 text-center space-y-3">
          <div class="w-14 h-14 rounded-full bg-green-100 dark:bg-emerald-950/40 flex items-center justify-center mx-auto">
            <CheckCircle class="text-green-500" :size="32" />
          </div>
          <p class="text-base font-black text-gray-900 dark:text-slate-100">Récompense approuvée !</p>
          <p class="text-xs text-gray-500 dark:text-slate-400">Le client a accepté. Vous pouvez maintenant valider et imprimer le ticket.</p>
          <button
            @click="$emit('confirm')"
            :disabled="processing"
            class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2"
          >
            <div v-if="processing" class="animate-spin"><Refresh :size="18" /></div>
            <Printer v-else :size="18" />
            {{ processing ? 'Validation...' : 'Valider & Imprimer' }}
          </button>
        </div>

        <!-- ── Okohi claim: rejected / expired ── -->
          <div v-else-if="isOkohiRejected" class="p-5 text-center space-y-3">
          <div class="w-14 h-14 rounded-full bg-red-100 dark:bg-red-950/40 flex items-center justify-center mx-auto">
            <CloseCircleIcon class="text-red-400" :size="32" />
          </div>
          <p class="text-base font-black text-gray-900 dark:text-slate-100">
            {{ claimStatus === 'expired' ? 'Demande expirée' : 'Récompense refusée' }}
          </p>
          <p class="text-xs text-gray-500 dark:text-slate-400">
            {{ claimStatus === 'expired' ? 'Le client n\'a pas répondu dans les 10 minutes.' : 'Le client a refusé la récompense.' }}
          </p>
          <button @click="$emit('close')" class="w-full py-2.5 border border-gray-300 dark:border-slate-700 rounded-xl text-gray-700 dark:text-slate-300 text-sm font-bold hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
            Fermer
          </button>
        </div>

        <!-- ── Okohi claim: pending ── -->
          <div v-else-if="isOkohiWaiting" class="p-5 text-center space-y-4">
          <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-950/20 border-2 border-amber-200 dark:border-amber-800 flex items-center justify-center mx-auto relative">
            <Gift class="text-amber-500" :size="28" />
            <div class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-amber-400 animate-ping opacity-75" />
          </div>
          <div>
            <p class="text-sm font-black text-gray-900 dark:text-slate-100">En attente de validation</p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">
              <span class="font-medium">{{ okohiRewardTitle }}</span>
            </p>
            <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Le client doit approuver depuis l'application Okohi.</p>
          </div>
          <div class="flex items-center justify-center gap-2 text-amber-600 dark:text-amber-400">
            <ClockIcon :size="16" />
            <span class="text-sm font-black font-mono tabular-nums">{{ claimCountdownLabel }}</span>
          </div>
          <div class="w-full bg-gray-100 dark:bg-slate-800 rounded-full h-1.5">
            <div
              class="bg-amber-400 h-1.5 rounded-full transition-all duration-1000"
              :style="{ width: `${Math.min(100, (claimSecondsLeft / 600) * 100)}%` }"
            />
          </div>
          <button @click="$emit('close')" class="w-full py-2 text-xs text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 transition-colors">
            Annuler
          </button>
        </div>

          <div v-else>
          <!-- ÉCRAN D'ATTENTE ASYNCHRONE OKOHI PENDING -->
          <div v-if="okohiRequest" class="bg-white/50 dark:bg-slate-950/30 border border-white/60 dark:border-slate-800/80 rounded-2xl p-6 text-center shadow-sm space-y-4">
            <!-- State 1: Approved, Pending Cash -->
            <div v-if="okohiRequest.status === 'approved_pending_cash'" class="space-y-4">
              <div class="flex justify-center">
                <div class="rounded-full bg-amber-50 dark:bg-amber-950/40 p-3 text-amber-600 dark:text-amber-400">
                  <CheckCircle :size="32" class="animate-pulse" />
                </div>
              </div>
              <div>
                <h4 class="text-lg font-black text-gray-900 dark:text-slate-100">Privilège validé !</h4>
                <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                  Veuillez encaisser le montant restant en espèces avant d'émettre le billet.
                </p>
              </div>

              <!-- Privilege info -->
              <div class="bg-amber-50/50 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/30 rounded-xl p-3 text-left space-y-1">
                <div class="text-xs text-gray-500 dark:text-slate-450 mt-1 flex justify-between">
                  <span>Prix normal :</span>
                  <span class="font-mono text-gray-800 dark:text-slate-200">{{ okohiAmounts?.gross.toLocaleString('fr-FR') }} FCFA</span>
                </div>
                <div class="text-xs text-gray-500 dark:text-slate-450 flex justify-between">
                  <span>Réduction Okohi :</span>
                  <span class="font-mono text-emerald-600">-{{ okohiAmounts?.discount.toLocaleString('fr-FR') }} FCFA</span>
                </div>
                <div class="h-px bg-amber-200/50 dark:bg-amber-900/30 my-1"></div>
                <div class="text-sm font-bold text-gray-800 dark:text-slate-200 flex justify-between">
                  <span>À encaisser (Espèces) :</span>
                  <span class="font-mono text-amber-600 text-lg">{{ okohiAmounts?.amountCollected.toLocaleString('fr-FR') }} FCFA</span>
                </div>
              </div>

              <div class="pt-2 flex flex-col gap-2">
                <button
                  type="button"
                  @click="confirmCashPayment"
                  :disabled="confirmingCash"
                  class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-xl transition-all shadow-sm flex items-center justify-center gap-1 active:scale-[0.99] disabled:opacity-50"
                >
                  <Refresh v-if="confirmingCash" class="animate-spin mr-1" :size="16" />
                  <span>Encaisser et émettre le ticket</span>
                </button>
                <button
                  type="button"
                  @click="cancelOkohiRequest"
                  class="w-full py-2 bg-red-50 hover:bg-red-100 dark:bg-red-950/20 dark:hover:bg-red-950/40 text-red-600 dark:text-red-400 font-bold text-sm rounded-xl border border-red-200/50 dark:border-red-900/30 transition-all"
                >
                  Annuler la vente
                </button>
              </div>
            </div>

            <!-- State 2: Standard Waiting -->
            <div v-else class="space-y-4">
              <div class="flex justify-center">
                <div class="relative">
                  <div class="animate-ping absolute inline-flex h-12 w-12 rounded-full bg-emerald-400 opacity-20"></div>
                  <div class="relative rounded-full bg-emerald-50 dark:bg-emerald-950/40 p-3 text-emerald-600 dark:text-emerald-400">
                    <Gift :size="32" class="animate-pulse" />
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-lg font-black text-gray-900 dark:text-slate-100">Attente de validation</h4>
                <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                  Demande envoyée sur l'application mobile Okohi du client <strong>{{ okohiCardNumber }}</strong>.
                </p>
              </div>

              <!-- Privilege info -->
              <div v-if="selectedReward" class="bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30 rounded-xl p-3 text-left">
                <span class="text-[10px] font-black uppercase text-emerald-600 dark:text-emerald-400 tracking-wider">Avantage sélectionné</span>
                <p class="text-sm font-bold text-gray-800 dark:text-slate-200 mt-0.5">{{ selectedReward.title }}</p>
                <div v-if="okohiAmounts" class="text-xs text-gray-500 dark:text-slate-450 mt-1 flex justify-between">
                  <span>Prix normal : {{ okohiAmounts.gross.toLocaleString('fr-FR') }} FCFA</span>
                  <span class="font-bold text-emerald-600">Réduction : -{{ okohiAmounts.discount.toLocaleString('fr-FR') }} FCFA</span>
                </div>
              </div>

              <div class="flex items-center justify-center gap-2 text-2xl font-black text-emerald-600 dark:text-emerald-400 font-mono">
                <Refresh class="animate-spin text-emerald-600" :size="24" />
                <span>{{ formatTime(countdown) }}</span>
              </div>

              <div class="pt-2 flex flex-col gap-2">
                <button
                  type="button"
                  @click="cancelOkohiRequest"
                  class="w-full py-2.5 bg-red-50 hover:bg-red-100 dark:bg-red-950/20 dark:hover:bg-red-950/40 text-red-600 dark:text-red-400 font-bold text-sm rounded-xl border border-red-200/50 dark:border-red-900/30 transition-all active:scale-[0.99]"
                >
                  Annuler la demande
                </button>
              </div>
            </div>
          </div>

          <!-- PARCOURS DE VENTE NORMAL / FORMULAIRE OKOHI -->
          <div v-else class="bg-white/50 dark:bg-slate-950/30 border border-white/60 dark:border-slate-800/80 rounded-2xl p-3 md:p-4 mb-3 md:mb-4 shadow-sm">
            <div
              class="text-center"
              :class="isOkohiExpanded ? 'md:grid md:grid-cols-[18rem_minmax(0,1fr)] md:gap-x-5 md:text-left' : ''"
            >
              <div class="text-2xl md:text-3xl font-bold text-slate-800 dark:text-slate-100 mb-1 md:mb-2">{{ seatLabel }}</div>
              <div class="text-xs md:text-sm text-gray-600 dark:text-slate-300 mb-2">{{ routeLabel }}</div>

              <!-- Toggle Okohi Integration -->
              <div v-if="okohiIntegrationActive" class="mb-4 inline-flex items-center bg-slate-100 dark:bg-slate-850 p-1 rounded-xl border border-white/50 dark:border-slate-800/50 shadow-inner">
                <button
                  type="button"
                  @click="useOkohi = false"
                  class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all"
                  :class="!useOkohi ? 'bg-white dark:bg-slate-700 text-gray-800 dark:text-slate-100 shadow-sm' : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'"
                >
                  Paiement Espèces
                </button>
                <button
                  type="button"
                  @click="useOkohi = true"
                  class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1"
                  :class="useOkohi ? 'bg-white dark:bg-slate-700 text-emerald-600 dark:text-emerald-400 shadow-sm' : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'"
                >
                  <Gift :size="13" />
                  Privilège Okohi
                </button>
              </div>

              <!-- Price tag -->
              <div class="text-xl md:text-2xl font-bold mt-2" :class="useOkohi ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-800 dark:text-slate-100'">
                {{ amountLabel }}
              </div>

              <!-- CORRESPONDANCE (Uniquement hors Okohi pour simplifier le MVP) -->
              <div v-if="!useOkohi && currentTrip?.allows_open_connections && availableConnectionFares.length" class="mt-4 text-left">
                <InputLabel for="final_destination" value="Destination finale avec correspondance (optionnel)" />
                <select id="final_destination" v-model="finalDestinationModel" class="mt-1 block w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500">
                  <option :value="null">Trajet direct — {{ selectedFare?.to_station?.name }}</option>
                  <option v-for="fare in availableConnectionFares" :key="fare.id" :value="fare.connection_destination_id">
                    {{ fare.connection_destination?.name }} — tarif {{ selectedFare?.from_station?.name }} → {{ fare.connection_destination?.name }} ({{ fare.amount.toLocaleString('fr-FR') }} FCFA), changement à {{ selectedFare?.to_station?.name }}
                  </option>
                </select>
                <p v-if="finalDestinationModel" class="mt-1 text-xs text-amber-700 dark:text-amber-300">Un seul ticket sera imprimé. Le voyage suivant sera attribué au point de correspondance.</p>
                <div v-if="finalDestinationModel" class="mt-3">
                  <InputLabel for="connection_route" value="Trajet de reprise" />
                  <select id="connection_route" v-model="connectionRouteModel" required class="mt-1 block w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                    <option value="">Sélectionner le trajet</option>
                    <option v-for="routeItem in compatibleConnectionRoutes" :key="routeItem.id" :value="routeItem.id">{{ routeItem.name }}</option>
                  </select>
                  <p v-if="compatibleConnectionRoutes.length === 0" class="mt-1 text-xs text-red-600">Aucun trajet ne dessert cette correspondance.</p>
                </div>
              </div>

              <!-- BLOC INTEGRATION OKOHI SELLER -->
              <div
                v-if="useOkohi"
                class="mt-4 space-y-3 border-t border-gray-150 pt-4 text-left dark:border-slate-800"
                :class="isOkohiExpanded ? 'md:contents' : ''"
              >
                <div :class="isOkohiExpanded ? 'md:col-start-1 md:mt-4' : ''">
                  <InputLabel for="okohi_card" value="Numéro de carte fidélité Okohi" />
                  <div class="mt-1 flex gap-2">
                    <div class="relative flex-1">
                      <TextInput
                        id="okohi_card"
                        v-model="okohiCardNumber"
                        type="text"
                        class="block w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 font-bold uppercase"
                        placeholder="OKH-XXXXXX"
                        @input="e => { if (!e.target.value.toUpperCase().startsWith('OKH-')) okohiCardNumber = 'OKH-' }"
                      />
                    </div>
                    <button
                      type="button"
                      :disabled="isOkohiSearching || okohiCardNumber.trim() === 'OKH-'"
                      @click="searchOkohiCustomer"
                      class="px-4 py-2 bg-slate-800 dark:bg-slate-700 text-white rounded-xl hover:bg-slate-700 disabled:opacity-50 font-bold text-xs flex items-center gap-1 transition-colors"
                    >
                      <Refresh v-if="isOkohiSearching" :size="14" class="animate-spin" />
                      <span>{{ isOkohiSearching ? 'Vérification...' : 'Vérifier' }}</span>
                    </button>
                  </div>
                </div>

                <!-- Okohi error -->
                <div v-if="okohiSearchError" class="bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 rounded-xl p-3 flex gap-2 items-start text-xs text-red-600 dark:text-red-400" :class="isOkohiExpanded ? 'md:col-start-1' : ''">
                  <AlertCircle :size="16" class="shrink-0 mt-0.5" />
                  <span>{{ okohiSearchError }}</span>
                </div>

                <!-- Customer found details -->
                <div
                  v-if="okohiCustomer"
                  class="rounded-xl border border-emerald-200 bg-slate-50 p-4 text-left shadow-sm dark:border-emerald-900/50 dark:bg-slate-900"
                  :class="isOkohiExpanded ? 'md:col-start-2 md:row-start-1 md:row-span-6 md:space-y-4' : 'space-y-3'"
                >
                  <div class="flex items-center gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div class="rounded-lg bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400">
                      <Gift :size="18" />
                    </div>
                    <div>
                      <p class="text-sm font-black text-slate-800 dark:text-slate-100">Vérification Okohi</p>
                      <p class="text-[11px] text-slate-500 dark:text-slate-400">Identité, voyages récents et privilèges disponibles</p>
                    </div>
                  </div>
                  <div class="flex justify-between items-center">
                    <div>
                      <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Client</p>
                      <p class="text-sm font-bold text-gray-800 dark:text-slate-100">{{ okohiCustomerName }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Solde</p>
                      <p class="text-sm font-black text-emerald-600 dark:text-emerald-400">
                        {{ okohiBalanceLabel }}
                      </p>
                    </div>
                  </div>

                  <div class="border-t border-slate-200 pt-3 dark:border-slate-800">
                    <div class="mb-2 flex items-center justify-between gap-2">
                      <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-slate-400">Voyages récents</p>
                      <span class="text-[11px] text-gray-400">Vérification client</span>
                    </div>
                    <div v-if="okohiCustomer.recent_trips?.length" class="max-h-44 space-y-2 overflow-y-auto pr-1">
                      <div
                        v-for="trip in okohiCustomer.recent_trips"
                        :key="trip.id"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950"
                      >
                        <div class="flex items-start justify-between gap-3">
                          <div class="min-w-0">
                            <p class="truncate font-bold text-slate-700 dark:text-slate-200">{{ trip.route_label || trip.title || 'Voyage Tiketi' }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-slate-400">Ticket {{ trip.ticket_id || 'non renseigné' }}</p>
                          </div>
                          <div class="shrink-0 text-right">
                            <p class="font-semibold text-slate-600 dark:text-slate-300">{{ formatOkohiTripDate(trip.travelled_at) }}</p>
                            <p v-if="trip.amount !== null" class="mt-0.5 text-[11px] text-slate-400">{{ Number(trip.amount).toLocaleString('fr-FR') }} FCFA</p>
                          </div>
                        </div>
                      </div>
                    </div>
                    <p v-else class="rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/70 dark:text-slate-400">
                      Aucun voyage confirmé trouvé pour cette compagnie.
                    </p>
                  </div>

                  <!-- Rewards dropdown selector -->
                  <div>
                    <InputLabel for="okohi_reward" value="Privilège à appliquer" />
                    <select
                      id="okohi_reward"
                      v-model="selectedReward"
                      class="mt-1 block w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 text-xs font-bold"
                    >
                      <option :value="null">Sélectionner un privilège...</option>
                      <option v-for="reward in eligibleRewards" :key="reward.id" :value="reward">
                        {{ reward.title }} (Coût: {{ reward.points_required ?? reward.cost_in_times }} pts)
                      </option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- QUANTITY SELECTOR (Uniquement pour paiement espèces) -->
              <div v-if="!useOkohi" class="mt-3 md:mt-4 flex items-center justify-center gap-2 md:gap-3 bg-white/35 dark:bg-slate-900/35 rounded-2xl p-2.5 md:p-3 border border-white/60 dark:border-slate-800/80">
                <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Quantité:</span>
                <div class="flex items-center bg-white/85 dark:bg-slate-900/85 rounded-xl border border-white/70 dark:border-slate-800/80 shadow-sm overflow-hidden">
                  <button
                    type="button"
                    @click="ticketQuantityModel = Math.max(1, ticketQuantityModel - 1)"
                    class="px-2.5 py-1 text-gray-600 dark:text-slate-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-l-xl border-r border-white/70 dark:border-slate-800/80"
                  >-</button>
                  <input
                    v-model.number="ticketQuantityModel"
                    type="number"
                    min="1"
                    max="10"
                    class="w-12 py-1 text-center border-0 focus:ring-0 text-gray-900 dark:text-slate-100 bg-transparent font-bold"
                  />
                  <button
                    type="button"
                    @click="ticketQuantityModel = Math.min(10, ticketQuantityModel + 1)"
                    class="px-2.5 py-1 text-gray-600 dark:text-slate-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-r-xl border-l border-white/70 dark:border-slate-800/80"
                  >+</button>
                </div>
              </div>
              <div v-if="!useOkohi && ticketQuantityModel > 1" class="text-xs md:text-sm font-bold text-slate-700 dark:text-slate-300 mt-2">
                Total: {{ totalLabel }}
              </div>
            </div>

            <!-- INFORMATIONS PASSAGER (Seulement pour paiement espèces) -->
            <button
              v-if="!useOkohi"
              @click="showPassengerFieldsModel = !showPassengerFieldsModel"
              class="w-full flex items-center justify-between p-3 bg-white/55 dark:bg-slate-950/40 hover:bg-white/75 dark:hover:bg-slate-900/50 rounded-xl mt-4 mb-3 transition-colors border border-white/60 dark:border-slate-800/80"
            >
              <span class="text-xs md:text-sm font-medium text-gray-700 dark:text-slate-300">Informations passager (optionnel)</span>
              <ChevronDown :class="{ 'rotate-180': showPassengerFieldsModel }" class="w-5 h-5 text-gray-500 dark:text-slate-400 transition-transform" />
            </button>

            <div v-show="!useOkohi && showPassengerFieldsModel" class="space-y-3 mb-3 md:mb-4">
              <div>
                <InputLabel for="passenger_name" value="Nom du passager" />
                <TextInput
                  id="passenger_name"
                  v-model="passengerForm.name"
                  type="text"
                  class="mt-1 block w-full rounded-xl border-slate-200 focus:border-emerald-500 focus:ring-emerald-500"
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
                  class="mt-1 block w-full rounded-xl border-slate-200 focus:border-emerald-500 focus:ring-emerald-500"
                  placeholder="Ex: 0102030405"
                />
                <InputError class="mt-2" :message="passengerFormErrors.phone" />
              </div>
            </div>

            <!-- ACTION FOOTER BAR -->
            <div class="sticky bottom-0 bg-white/95 dark:bg-slate-900/95 backdrop-blur-sm pt-4 pb-2 border-t border-gray-150 dark:border-slate-800 mt-4">
              <div class="flex items-center justify-end space-x-3">
                <button
                  type="button"
                  @click="$emit('close')"
                  class="px-4 py-2 border border-gray-300 dark:border-slate-700 rounded-lg text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 text-sm"
                >
                  Annuler
                </button>

                <!-- BUTTON FOR OKOHI VALIDATION -->
                <button
                  v-if="useOkohi"
                  type="button"
                  :disabled="processingOkohi || !selectedReward"
                  @click="initiateOkohiRequest"
                  class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg disabled:opacity-50 disabled:cursor-not-allowed flex items-center text-sm font-bold shadow-sm transition-colors cursor-pointer"
                >
                  <Refresh v-if="processingOkohi" :size="16" class="animate-spin mr-2" />
                  <Gift v-else :size="16" class="mr-2" />
                  <span>{{ processingOkohi ? 'Envoi...' : 'Utiliser ce privilège' }}</span>
                </button>

                <!-- BUTTON FOR CASH VALIDATION (STANDARD) -->
                <button
                  v-else
                  type="button"
                  @click="$emit('confirm')"
                  :disabled="processing"
                  class="px-5 py-2 bg-slate-900 dark:bg-slate-800 text-white hover:bg-slate-850 dark:hover:bg-slate-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed flex items-center text-sm font-bold cursor-pointer"
                >
                  <div v-if="processing" class="animate-spin mr-2"><Refresh :size="16" /></div>
                  <Printer v-else :size="16" class="mr-2" />
                  <span>{{ processing ? 'Validation...' : 'Valider & Imprimer' }}</span>
                </button>
              </div>
            </div>
          </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
