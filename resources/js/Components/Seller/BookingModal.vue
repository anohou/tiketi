<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';

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
});

const emit = defineEmits([
  'close',
  'confirm',
  'select-fare',
  'update:ticketQuantity',
  'update:showPassengerFields',
]);

const isDestinationMode = computed(() => props.mode === 'destination');
const modalRef = ref(null);
const dragHandleRef = ref(null);
const isDragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const modalPosition = ref({ x: 0, y: 0 });

const STORAGE_KEY = 'tiketi.bookingModal.position';
const DEFAULT_MODAL_WIDTH = 416;
const DEFAULT_MODAL_HEIGHT = 640;
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
});

const ticketQuantityModel = computed({
  get: () => props.ticketQuantity,
  set: (value) => emit('update:ticketQuantity', Number(value)),
});

const showPassengerFieldsModel = computed({
  get: () => props.showPassengerFields,
  set: (value) => emit('update:showPassengerFields', value),
});

const seatLabel = computed(() => {
  if (props.seatsToBook.length > 1) {
    return `Places ${props.seatsToBook.join(', ')}`;
  }
  return `Place ${props.selectedSeatNumber}`;
});

const routeLabel = computed(() => {
  const from = props.selectedFare?.from_station?.name;
  const to = props.selectedFare?.to_station?.name;
  if (from && to) {
    return `${from} → ${to}`;
  }
  return props.currentTrip?.route?.name || props.currentTrip?.display_name || '---';
});

const amountLabel = computed(() => {
  const amount = props.selectedFare?.amount || 0;
  return `${amount.toLocaleString('fr-FR')} FCFA`;
});

const totalLabel = computed(() => {
  const amount = props.selectedFare?.amount || 0;
  return `${(amount * props.ticketQuantity).toLocaleString('fr-FR')} FCFA`;
});
</script>

<template>
  <div
    v-if="visible"
    class="fixed inset-0 z-[1010] bg-black/10 p-4"
  >
    <div
      ref="modalRef"
      class="absolute bg-white/85 rounded-2xl shadow-2xl border border-white/50 w-full max-w-2xl md:w-[26rem] md:max-w-none max-h-[90vh] overflow-hidden backdrop-blur-sm"
      :style="{
        left: `${modalPosition.x}px`,
        top: `${modalPosition.y}px`,
      }"
    >
      <div
        ref="dragHandleRef"
        class="p-5 border-b border-white/60 flex items-center justify-between bg-white/70 cursor-move select-none"
        @mousedown="startDragging"
      >
        <div>
          <h3 class="text-xl font-black text-gray-900">
            {{ isDestinationMode ? 'Choisir une destination' : 'Informations Passager' }}
          </h3>
          <p class="text-sm text-gray-500">
            Siège {{ selectedSeatNumber }} sélectionné
          </p>
        </div>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 cursor-pointer">
          <Close class="w-6 h-6" />
        </button>
      </div>

      <div class="overflow-y-auto p-4 max-h-[calc(90vh-88px)]">
        <template v-if="isDestinationMode">
          <div v-if="availableFares.length > 0" class="grid gap-3">
            <button
              v-for="fare in availableFares"
              :key="fare.id"
              type="button"
              @click="$emit('select-fare', fare)"
              class="text-left relative overflow-hidden rounded-2xl transition-all duration-200 border-2 border-transparent shadow-sm hover:shadow-lg active:scale-[0.99]"
              :style="{ backgroundColor: fare.color || '#4F46E5' }"
            >
              <div class="p-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <div class="text-lg font-black truncate" :style="{ color: fare.textColor || '#FFFFFF' }">
                    {{ fare.to_station?.name }}
                  </div>
                  <div class="text-xs font-medium" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">
                    → depuis {{ fare.from_station?.name?.split(' - ')[1] || fare.from_station?.name }}
                  </div>
                </div>
                <div class="text-right shrink-0">
                  <div class="text-2xl font-black" :style="{ color: fare.textColor || '#FFFFFF' }">
                    {{ fare.amount.toLocaleString('fr-FR') }}
                  </div>
                  <div class="text-[10px] font-bold" :style="{ color: fare.mutedColor || 'rgba(255,255,255,0.7)' }">FCFA</div>
                </div>
              </div>
            </button>
          </div>
          <div v-else class="p-8 text-center text-gray-500">
            Aucune destination disponible pour ce voyage.
          </div>
        </template>

        <div v-else class="bg-white/50 border border-white/60 rounded-2xl p-4 mb-4 shadow-sm">
          <div class="text-center">
            <div v-if="seatsToBook.length > 1" class="text-3xl font-bold text-blue-600 mb-2">{{ seatLabel }}</div>
            <div v-else class="text-3xl font-bold text-blue-600 mb-2">{{ seatLabel }}</div>
            <div class="text-sm text-gray-600">{{ routeLabel }}</div>
            <div class="text-2xl font-bold text-green-600 mt-2">{{ amountLabel }}</div>

            <div class="mt-4 flex items-center justify-center gap-3 bg-white/35 rounded-2xl p-3 border border-white/60">
              <span class="text-sm font-medium text-gray-700">Quantité:</span>
              <div class="flex items-center bg-white/85 rounded-xl border border-white/70 shadow-sm overflow-hidden">
                <button
                  type="button"
                  @click="ticketQuantityModel = Math.max(1, ticketQuantityModel - 1)"
                  class="px-3 py-1 text-gray-600 hover:bg-green-50 rounded-l-xl border-r border-white/70"
                >-</button>
                <input
                  v-model.number="ticketQuantityModel"
                  type="number"
                  min="1"
                  max="10"
                  class="w-12 py-1 text-center border-0 focus:ring-0 text-gray-900 font-bold"
                />
                <button
                  type="button"
                  @click="ticketQuantityModel = Math.min(10, ticketQuantityModel + 1)"
                  class="px-3 py-1 text-gray-600 hover:bg-green-50 rounded-r-xl border-l border-white/70"
                >+</button>
              </div>
            </div>
            <div v-if="ticketQuantityModel > 1" class="text-sm font-bold text-blue-700 mt-2">
              Total: {{ totalLabel }}
            </div>
          </div>

          <button
            @click="showPassengerFieldsModel = !showPassengerFieldsModel"
            class="w-full flex items-center justify-between p-3 bg-white/55 hover:bg-white/75 rounded-xl mb-4 transition-colors border border-white/60"
          >
            <span class="text-sm font-medium text-gray-700">Informations passager (optionnel)</span>
            <ChevronDown :class="{ 'rotate-180': showPassengerFieldsModel }" class="w-5 h-5 text-gray-500 transition-transform" />
          </button>

          <div v-show="showPassengerFieldsModel" class="space-y-4 mb-4">
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

          <form @submit.prevent="$emit('confirm')" class="sticky bottom-0 bg-white/95 backdrop-blur-sm pt-4 pb-2">
            <div class="flex items-center justify-end space-x-3">
              <button
                type="button"
                @click="$emit('close')"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
              >
                Annuler
              </button>
              <button
                type="submit"
                :disabled="processing"
                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
              >
                <div v-if="processing" class="animate-spin mr-2"><Refresh :size="20" /></div>
                <Printer v-else :size="16" class="mr-2" />
                <span>{{ processing ? 'Validation...' : 'Valider & Imprimer' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
