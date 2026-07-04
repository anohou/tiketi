<script setup>
import { computed } from 'vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import GpsMapPicker from '@/Components/GpsMapPicker.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: '',
  },
  processing: {
    type: Boolean,
    default: false,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
  form: {
    type: Object,
    required: true,
  },
  destinationMode: {
    type: String,
    default: 'select', // select | locked | hidden
  },
  destinationOptions: {
    type: Array,
    default: () => [],
  },
  destinationLabel: {
    type: String,
    default: 'Destination',
  },
  destinationValueLabel: {
    type: String,
    default: '',
  },
  referencePoints: {
    type: Array,
    default: () => [],
  },
  center: {
    type: Object,
    default: () => ({ latitude: 7.177201, longitude: -5.635986 }),
  },
  mapHeight: {
    type: String,
    default: '520px',
  },
  mapVisible: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'submit']);

const close = () => emit('close');
const submit = () => emit('submit');

const coordinates = computed({
  get: () => ({
    latitude: props.form.latitude,
    longitude: props.form.longitude,
  }),
  set: (value) => {
    props.form.latitude = value?.latitude ?? '';
    props.form.longitude = value?.longitude ?? '';
  },
});

const mapCenter = computed(() => {
  // If there are coordinates in the form, use them
  if (Number(props.form.latitude) && Number(props.form.longitude)) {
    return {
      latitude: Number(props.form.latitude),
      longitude: Number(props.form.longitude)
    };
  }

  // If a destination is selected, use its coordinates
  if (props.form.destination_id) {
    const selectedDest = props.destinationOptions.find(d => d.id === props.form.destination_id);
    if (selectedDest && Number(selectedDest.latitude) && Number(selectedDest.longitude)) {
      return {
        latitude: Number(selectedDest.latitude),
        longitude: Number(selectedDest.longitude)
      };
    }
  }

  // Otherwise, fall back to the prop center
  return props.center;
});
</script>

<template>
  <DialogModal :show="show" @close="close" maxWidth="6xl">
    <template #title>
      {{ title }}
    </template>

    <template #content>
      <div class="grid gap-6 lg:grid-cols-2 items-stretch">
        <div class="space-y-4 min-h-[520px]">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="station_name" value="Nom de la Gare" />
              <TextInput v-model="form.name" id="station_name" class="w-full" placeholder="Ex: Gare Nord" />
              <InputError :message="errors.name" />
            </div>
            <div>
              <InputLabel for="station_code" value="Code" />
              <TextInput v-model="form.code" id="station_code" class="w-full" placeholder="Ex: G-NO" />
              <InputError :message="errors.code" />
            </div>
          </div>

          <div v-if="destinationMode !== 'hidden'">
            <InputLabel for="station_destination" :value="destinationLabel" />
            <select
              v-if="destinationMode === 'select'"
              id="station_destination"
              v-model="form.destination_id"
              class="w-full border-slate-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
            >
              <option value="">Sélectionner une destination</option>
              <option
                v-for="destination in destinationOptions"
                :key="destination.id"
                :value="destination.id"
              >
                {{ destination.name }}
              </option>
            </select>
            <TextInput
              v-else
              id="station_destination"
              class="w-full bg-slate-50"
              :model-value="destinationValueLabel"
              disabled
            />
            <InputError :message="errors.destination_id" />
          </div>

          <div>
            <InputLabel for="station_city" value="Quartier / Emplacement précis (Optionnel)" />
            <TextInput v-model="form.city" id="station_city" class="w-full" placeholder="Ex: Adjamé, Centre-ville" />
            <InputError :message="errors.city" />
          </div>

          <div>
            <InputLabel for="station_address" value="Adresse" />
            <TextInput v-model="form.address" id="station_address" class="w-full" />
            <InputError :message="errors.address" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="station_latitude" value="Latitude (Optionnel)" />
              <TextInput
                v-model="form.latitude"
                id="station_latitude"
                type="number"
                step="any"
                class="w-full"
                placeholder="Ex: 5.35995"
              />
              <InputError :message="errors.latitude" />
            </div>
            <div>
              <InputLabel for="station_longitude" value="Longitude (Optionnel)" />
              <TextInput
                v-model="form.longitude"
                id="station_longitude"
                type="number"
                step="any"
                class="w-full"
                placeholder="Ex: -4.00826"
              />
              <InputError :message="errors.longitude" />
            </div>
          </div>

          <div class="flex items-center">
            <input
              type="checkbox"
              v-model="form.active"
              id="station_active"
              class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500"
            >
            <label for="station_active" class="ml-2 text-sm text-slate-600">Gare Active</label>
          </div>

          <div class="flex items-center">
            <input
              type="checkbox"
              v-model="form.can_sell_tickets"
              id="station_can_sell_tickets"
              class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500"
            >
            <label for="station_can_sell_tickets" class="ml-2 text-sm text-slate-600">
              Cette gare peut vendre des billets
            </label>
          </div>
        </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex flex-col h-full">
            <InputLabel value="Coordonnées GPS (cliquer sur la carte)" />
            <div class="mt-3 flex-1">
              <GpsMapPicker
                v-model="coordinates"
                :visible="mapVisible"
                :reference-points="referencePoints"
                :center="mapCenter"
                :height="mapHeight"
              />
            </div>
          <InputError class="mt-2" :message="errors.latitude || errors.longitude" />
          </div>
        </div>
      </template>

    <template #footer>
      <SecondaryButton @click="close">Annuler</SecondaryButton>
      <PrimaryButton class="ml-3" @click="submit" :disabled="processing">
        {{ title.includes('Modifier') ? 'Mettre à jour' : 'Enregistrer' }}
      </PrimaryButton>
    </template>
  </DialogModal>
</template>
