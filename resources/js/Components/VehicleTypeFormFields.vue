<script setup>
import { ref, onMounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SeatMapPreview from '@/Components/SeatMapPreview.vue';
import FormPanel from '@/Components/FormPanel.vue';
import Loader from 'vue-material-design-icons/Loading.vue';

const props = defineProps({
  vehicleType: { type: Object, default: null },
  errors: { type: Object, default: () => ({}) },
  submitUrl: { type: String, required: true },
  backUrl: { type: String, required: true },
  submitMethod: { type: String, default: 'post' },
  submitLabel: { type: String, default: 'Enregistrer' },
  cancelLabel: { type: String, default: 'Annuler' },
  onSuccess: { type: Function, default: null },
  onCancel: { type: Function, default: null },
  hideHeader: { type: Boolean, default: false },
  hideHeader: { type: Boolean, default: false },
});

const { t } = useI18n();
const processing = ref(false);

const form = ref({
  name: '',
  seat_count: '',
  seat_configuration: '2+2',
  door_positions_text: '',
  door_side: 'right',
  door_width: 2,
  last_row_seats: '',
  active: true,
});

onMounted(() => {
  if (!props.vehicleType) return;

  form.value = {
    name: props.vehicleType.name ?? '',
    seat_count: props.vehicleType.seat_count?.toString?.() ?? '',
    seat_configuration: props.vehicleType.seat_configuration || '2+2',
    door_positions_text: props.vehicleType.door_positions ? props.vehicleType.door_positions.join(', ') : '',
    door_side: props.vehicleType.door_side || 'right',
    door_width: props.vehicleType.door_width || 2,
    last_row_seats: props.vehicleType.last_row_seats !== null && props.vehicleType.last_row_seats !== undefined
      ? props.vehicleType.last_row_seats.toString()
      : '',
    active: props.vehicleType.active !== undefined ? Boolean(props.vehicleType.active) : true,
  };
});

const liveSeatMap = computed(() => {
  const seatCount = parseInt(form.value.seat_count) || 0;
  const configStr = form.value.seat_configuration || '2+2';
  const doorPositions = form.value.door_positions_text
    ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
    : [];
  const parts = configStr.split('+').map(Number);
  const leftCount = parts[0] || 2;
  const rightCount = parts[1] || 2;
  const slotsPerRow = leftCount + rightCount;
  const parsedLastRowSeats = parseInt(form.value.last_row_seats);
  const lastRowSeats = Number.isNaN(parsedLastRowSeats) ? 5 : Math.max(0, parsedLastRowSeats);

  const seatMap = [];
  let currentSeatNum = 1;
  let rowIndex = 0;
  let filledSeats = 0;
  const targetSeats = seatCount;
  const seatsBeforeLast = targetSeats - lastRowSeats;

  if (seatCount <= 0 || slotsPerRow <= 0) {
    return seatMap;
  }

  while (filledSeats < seatsBeforeLast) {
    const row = [];
    const rowStartSlot = (rowIndex - 1) * slotsPerRow + 1;

    if (rowIndex === 0) {
      row.push({ type: 'driver', label: t('fleet.crew.role_driver') });
      for (let i = 1; i < leftCount; i++) row.push({ type: 'empty' });
    } else {
      for (let i = 0; i < leftCount; i++) {
        const currentSlot = rowStartSlot + i;
        if (doorPositions.includes(currentSlot)) {
          row.push({ type: 'door' });
        } else if (filledSeats < seatsBeforeLast) {
          row.push({ type: 'seat', number: (currentSeatNum++).toString() });
          filledSeats++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }

    row.push({ type: 'aisle' });

    if (rowIndex === 0) {
      if (doorPositions.includes(0)) {
        for (let i = 1; i < rightCount; i++) row.push({ type: 'empty' });
        row.push({ type: 'door', label: t('fleet.vehicle_type_form.door') });
      } else {
        for (let i = 0; i < rightCount; i++) {
          if (filledSeats < seatsBeforeLast) {
            row.push({ type: 'seat', number: (currentSeatNum++).toString() });
            filledSeats++;
          } else {
            row.push({ type: 'empty' });
          }
        }
      }
    } else {
      for (let i = 0; i < rightCount; i++) {
        const currentSlot = rowStartSlot + leftCount + i;
        if (doorPositions.includes(currentSlot)) {
          row.push({ type: 'door' });
        } else if (filledSeats < seatsBeforeLast) {
          row.push({ type: 'seat', number: (currentSeatNum++).toString() });
          filledSeats++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }

    seatMap.push(row);
    rowIndex++;
    if (rowIndex > 100) break;
  }

  const remaining = targetSeats - filledSeats;
  if (lastRowSeats === 0 && targetSeats > 0) {
    const rearSpaceRow = [];
    for (let i = 0; i < slotsPerRow + 1; i++) {
      rearSpaceRow.push({ type: 'empty' });
    }
    seatMap.push(rearSpaceRow);
  } else if (remaining > 0) {
    const lastRow = [];
    for (let i = 0; i < remaining; i++) {
      lastRow.push({ type: 'seat', number: (currentSeatNum++).toString() });
    }
    seatMap.push(lastRow);
  }

  return seatMap;
});

const recalculateMetadata = () => {
  const seatCount = parseInt(form.value.seat_count) || 0;
  const configStr = form.value.seat_configuration || '2+2';
  const doorSide = form.value.door_side || 'right';
  const doorWidth = parseInt(form.value.door_width) || 2;

  const parts = configStr.split('+').map(Number);
  const leftCount = parts[0] || 2;
  const rightCount = parts[1] || 2;
  const slotsPerRow = leftCount + rightCount;

  if (seatCount <= 0 || slotsPerRow <= 0) {
    return;
  }

  const approxRows = Math.max(2, Math.ceil(seatCount / slotsPerRow));
  const doorPositions = [0];

  const middleRow = Math.floor(approxRows / 2);
  const mStart = Math.max(1, (middleRow - 1) * slotsPerRow + 1);
  if (doorSide === 'right') {
    for (let i = 0; i < Math.min(doorWidth, rightCount); i++) {
      doorPositions.push(mStart + leftCount + i);
    }
  } else {
    for (let i = 0; i < Math.min(doorWidth, leftCount); i++) {
      doorPositions.push(mStart + i);
    }
  }

  const backRow = approxRows - 4;
  if (backRow > middleRow) {
    const bStart = Math.max(1, (backRow - 1) * slotsPerRow + 1);
    if (doorSide === 'right') {
      for (let i = 0; i < Math.min(doorWidth, rightCount); i++) {
        doorPositions.push(bStart + leftCount + i);
      }
    } else {
      for (let i = 0; i < Math.min(doorWidth, leftCount); i++) {
        doorPositions.push(bStart + i);
      }
    }
  }

  form.value.door_positions_text = [...new Set(doorPositions)].sort((a, b) => a - b).join(', ');
};

const submit = () => {
  processing.value = true;

  const payload = {
    ...form.value,
    seat_count: form.value.seat_count ? parseInt(form.value.seat_count) : form.value.seat_count,
    door_width: form.value.door_width ? parseInt(form.value.door_width) : form.value.door_width,
    last_row_seats: (() => {
      const parsed = form.value.last_row_seats === '' ? 5 : parseInt(form.value.last_row_seats);
      return Number.isNaN(parsed) ? 5 : Math.max(0, parsed);
    })(),
    door_positions: form.value.door_positions_text
      ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
      : [],
    door_positions_text: undefined,
  };

  router[props.submitMethod](props.submitUrl, payload, {
    onSuccess: () => {
      processing.value = false;
      props.onSuccess?.();
    },
    onError: () => {
      processing.value = false;
    }
  });
};

const handleCancel = () => {
  if (props.onCancel) {
    props.onCancel();
  }
};
</script>

<template>
  <FormPanel id="vehicle-type-form" @submit="submit">
    <!-- Header with title on left and Save/Cancel buttons on right -->
    <template #header v-if="!hideHeader">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-emerald-100 rounded-xl shrink-0">
          <svg class="text-emerald-600 w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 7h16v10H4z" />
          </svg>
        </div>
        <h1 class="text-2xl font-black text-gray-900">
          {{ vehicleType ? $t('fleet.vehicle_type_form.title_edit') : $t('fleet.vehicle_type_form.title_new') }}
        </h1>
      </div>
    </template>
    
    <template #secondary-actions>
      <Link v-if="!onCancel" :href="backUrl" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm font-medium">
        {{ cancelLabel === 'Annuler' ? $t('common.cancel') : cancelLabel }}
      </Link>
      <button v-else type="button" @click="handleCancel" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm font-medium">
        {{ cancelLabel === 'Annuler' ? $t('common.cancel') : cancelLabel }}
      </button>
    </template>
    
    <template #actions>
      <button type="submit" :disabled="processing" class="px-4 py-2 rounded-xl bg-green-600 text-white hover:bg-green-700 text-sm font-medium flex items-center space-x-2">
        <Loader v-if="processing" class="w-4 h-4 animate-spin" />
        <span>{{ submitLabel === 'Enregistrer' ? $t('common.save') : submitLabel }}</span>
      </button>
    </template>

    <!-- Main Container: Top Form, Bottom Seat Map -->
    <div class="space-y-6 pt-4 px-6">
      <!-- Top Section: Form inputs in a compact, structured card -->
      <div class="bg-gray-50/60 dark:bg-slate-900/40 p-5 rounded-2xl border border-gray-200/50 dark:border-slate-800">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
          <!-- Nom du Type -->
          <div class="md:col-span-4">
            <InputLabel for="name" :value="$t('fleet.vehicle_type_form.name')" />
            <TextInput v-model="form.name" id="name" class="w-full text-sm mt-1" :placeholder="$t('fleet.vehicle_type_form.name_placeholder')" required />
            <InputError :message="errors.name" class="mt-1" />
          </div>

          <!-- Places -->
          <div class="md:col-span-2">
            <InputLabel for="seat_count" :value="$t('fleet.vehicle_type_form.seats')" />
            <TextInput v-model="form.seat_count" id="seat_count" type="number" min="1" class="w-full text-sm mt-1" />
            <InputError :message="errors.seat_count" class="mt-1" />
          </div>

          <!-- Config -->
          <div class="md:col-span-2">
            <InputLabel for="seat_configuration" :value="$t('fleet.vehicle_type_form.config')" />
            <TextInput v-model="form.seat_configuration" id="seat_configuration" class="w-full text-sm mt-1" placeholder="2+2" />
            <InputError :message="errors.seat_configuration" class="mt-1" />
          </div>

          <!-- Dernière Rangée -->
          <div class="md:col-span-2">
            <InputLabel for="last_row_seats" :value="$t('fleet.vehicle_type_form.last_row')" />
            <TextInput v-model="form.last_row_seats" id="last_row_seats" type="number" min="0" class="w-full text-sm mt-1" placeholder="5" />
            <InputError :message="errors.last_row_seats" class="mt-1" />
          </div>

          <!-- Type actif Checkbox -->
          <div class="md:col-span-2 flex items-center h-full pb-3">
            <label class="flex items-center text-sm text-gray-700 dark:text-slate-350 cursor-pointer select-none">
              <input type="checkbox" v-model="form.active" id="type_active" class="rounded border-gray-300 dark:border-slate-700 dark:bg-slate-950 text-green-600 shadow-sm focus:ring-green-500 w-4 h-4">
              <span class="ml-2 font-bold text-gray-800 dark:text-slate-200">{{ $t('fleet.vehicle_type_form.active') }}</span>
            </label>
          </div>

          <!-- Côté Portes -->
          <div class="md:col-span-3">
            <InputLabel for="door_side" :value="$t('fleet.vehicle_type_form.door_side')" />
            <select v-model="form.door_side" id="door_side" class="w-full text-sm mt-1 border-gray-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-green-500 focus:ring-green-500 rounded-md shadow-sm">
              <option value="right">{{ $t('fleet.vehicle_type_form.door_right') }}</option>
              <option value="left">{{ $t('fleet.vehicle_type_form.door_left') }}</option>
            </select>
            <InputError :message="errors.door_side" class="mt-1" />
          </div>

          <!-- Largeur Porte -->
          <div class="md:col-span-3">
            <InputLabel for="door_width" :value="$t('fleet.vehicle_type_form.door_width')" />
            <TextInput v-model="form.door_width" id="door_width" type="number" min="1" max="3" class="w-full text-sm mt-1" />
            <InputError :message="errors.door_width" class="mt-1" />
          </div>

          <!-- Positions portes -->
          <div class="md:col-span-6">
            <div class="flex items-center justify-between">
              <InputLabel for="door_positions" :value="$t('fleet.vehicle_type_form.door_positions')" />
              <button type="button" @click="recalculateMetadata" class="text-[10px] text-green-600 hover:text-green-700 font-black flex items-center gap-1 bg-green-50 dark:bg-emerald-950/20 px-2 py-0.5 rounded border border-green-200 dark:border-emerald-900/30 transition-colors" :title="$t('fleet.vehicle_type_form.suggest_title')">
                <span>↺</span> {{ $t('fleet.vehicle_type_form.suggest_btn') }}
              </button>
            </div>
            <TextInput v-model="form.door_positions_text" id="door_positions" class="w-full text-sm mt-1" placeholder="Ex: 0, 11, 12" />
            <InputError :message="errors.door_positions" class="mt-1" />
          </div>
        </div>
        <p class="mt-3 text-[10px] text-gray-400 dark:text-slate-500 leading-normal border-t border-gray-100 dark:border-slate-800 pt-2">
          {{ $t('fleet.vehicle_type_form.note') }}
        </p>
      </div>

      <!-- Bottom Section: Horizontal Live Preview (taking 100% width) -->
      <div class="bg-gray-50/50 dark:bg-slate-900/40 rounded-2xl border border-gray-100 dark:border-slate-800 p-5 flex flex-col">
        <div class="w-full overflow-x-auto custom-scrollbar flex justify-center py-2">
          <div v-if="liveSeatMap.length > 0" class="w-full flex justify-center">
            <SeatMapPreview
              :seatMap="liveSeatMap"
              :seatConfiguration="form.seat_configuration"
              orientation="horizontal"
            />
          </div>
          <div v-else class="text-center text-gray-400 dark:text-slate-500">
            <p class="text-[10px]">{{ $t('fleet.vehicle_type_form.empty_preview') }}</p>
          </div>
        </div>
      </div>
    </div>
  </FormPanel>
</template>
