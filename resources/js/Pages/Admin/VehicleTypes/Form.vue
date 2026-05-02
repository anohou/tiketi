<template>
  <MainNavLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ vehicleType ? 'Modifier le Type' : 'Nouveau Type de Véhicule' }}
      </h2>
    </template>

    <div class="flex h-full">
      <!-- Left Sidebar Menu -->
      <SettingsMenu />

      <!-- Main Content Area -->
      <div class="flex-1 bg-white">
        <div class="max-w-2xl mx-auto p-6">
          <form @submit.prevent="submit" class="space-y-6">
            <div>
              <InputLabel for="name" value="Nom du Type" />
              <TextInput
                id="name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full"
                required
              />
              <InputError :message="errors.name" class="mt-2" />
            </div>

            <div>
              <InputLabel for="seat_count" value="Nombre de Places" />
              <TextInput
                id="seat_count"
                v-model="form.seat_count"
                type="number"
                min="1"
                class="mt-1 block w-full"
                required
              />
              <InputError :message="errors.seat_count" class="mt-2" />
            </div>
            
            <div>
              <InputLabel for="seat_configuration" value="Configuration des Sièges (ex: 2+2)" />
              <TextInput
                id="seat_configuration"
                v-model="form.seat_configuration"
                type="text"
                class="mt-1 block w-full"
                placeholder="2+2"
                required
              />
              <InputError :message="errors.seat_configuration" class="mt-2" />
            </div>

            <div>
              <InputLabel for="door_positions" value="Positions des Portes (séparées par virgule)" />
              <TextInput
                id="door_positions"
                v-model="form.door_positions_text"
                type="text"
                class="mt-1 block w-full"
                placeholder="1, 23, 24"
              />
              <p class="text-xs text-gray-500 mt-1">Entrez les numéros de sièges où se trouvent les portes.</p>
              <InputError :message="errors.door_positions" class="mt-2" />
            </div>

            <div>
              <InputLabel for="last_row_seats" value="Sièges Dernière Rangée (optionnel)" />
              <TextInput
                id="last_row_seats"
                v-model="form.last_row_seats"
                type="number"
                min="1"
                class="mt-1 block w-full"
                placeholder="5"
              />
              <InputError :message="errors.last_row_seats" class="mt-2" />
            </div>

            <!-- Advanced Configuration Toggle -->
            <div class="border-t border-gray-200 pt-6 mt-6">
              <div class="flex items-center justify-between mb-4">
                <div>
                  <h3 class="text-lg font-semibold text-gray-900">Configuration Avancée</h3>
                  <p class="text-sm text-gray-500 mt-1">Personnaliser manuellement le plan des sièges</p>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input 
                    type="checkbox" 
                    v-model="form.use_manual_config"
                    class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500"
                  />
                  <span class="text-sm font-medium text-gray-700">Activer</span>
                </label>
              </div>

              <div v-if="form.use_manual_config" class="space-y-4">
                <!-- Generate from Config Button -->
                <div class="flex items-center gap-3">
                  <button
                    type="button"
                    @click="generateSeatMapJSON"
                    class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 flex items-center gap-2"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Générer depuis la configuration
                  </button>
                  <button
                    type="button"
                    @click="formatJSON"
                    class="px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-lg hover:bg-gray-700 flex items-center gap-2"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    Formater JSON
                  </button>
                </div>

                <!-- JSON Editor -->
                <div>
                  <InputLabel for="manual_seat_map" value="Plan des Sièges (JSON)" />
                  <textarea
                    id="manual_seat_map"
                    v-model="form.manual_seat_map"
                    rows="12"
                    class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                    placeholder='[[{"type":"driver","label":"Chauffeur"},...]]'
                  ></textarea>
                  <InputError :message="errors.manual_seat_map || jsonError" class="mt-2" />
                  <p class="text-xs text-gray-500 mt-1">Modifiez le JSON pour personnaliser le plan des sièges</p>
                </div>

                <!-- Live Preview -->
                <div v-if="previewSeatMap" class="border border-gray-300 rounded-lg p-4 bg-gray-50">
                  <h4 class="text-sm font-semibold text-gray-700 mb-3">Aperçu en Direct</h4>
                  <div class="bg-white rounded-lg p-4 overflow-auto max-h-96">
                    <VehicleSeatMapSVG
                      v-if="previewVehicleType"
                      :vehicle-type="previewVehicleType"
                      :seat-map="previewSeatMap"
                      :suggested-seats="[]"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div class="flex items-center space-x-4">
              <button
                type="submit"
                :disabled="processing"
                class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center space-x-2"
              >
                <Loader v-if="processing" class="w-4 h-4 animate-spin" />
                <span>{{ vehicleType ? 'Mettre à jour' : 'Créer' }}</span>
              </button>
              
              <Link
                :href="route('admin.vehicle-types.index')"
                class="text-gray-600 hover:text-gray-800 px-4 py-2"
              >
                Annuler
              </Link>
            </div>
          </form>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import VehicleSeatMapSVG from '@/Components/VehicleSeatMapSVG.vue';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Loader from 'vue-material-design-icons/Loading.vue';

const props = defineProps({
  vehicleType: {
    type: Object,
    default: null
  },
  errors: {
    type: Object,
    default: () => ({})
  }
});

// State
const processing = ref(false);

const form = ref({
  name: '',
  seat_count: '',
  seat_configuration: '2+2',
  door_positions_text: '',
  last_row_seats: '',
  use_manual_config: false,
  manual_seat_map: ''
});

// Initialize form with vehicleType data if editing
onMounted(() => {
  if (props.vehicleType) {
    form.value = {
      name: props.vehicleType.name,
      seat_count: props.vehicleType.seat_count.toString(),
      seat_configuration: props.vehicleType.seat_configuration || '2+2',
      door_positions_text: props.vehicleType.door_positions ? props.vehicleType.door_positions.join(', ') : '',
      last_row_seats: props.vehicleType.last_row_seats ? props.vehicleType.last_row_seats.toString() : '',
      use_manual_config: props.vehicleType.use_manual_config || false,
      manual_seat_map: props.vehicleType.seat_map ? JSON.stringify(props.vehicleType.seat_map, null, 2) : ''
    };
  }
});

// JSON validation error
const jsonError = ref('');

// Preview computed properties
const previewVehicleType = computed(() => {
  if (!form.value.use_manual_config) return null;
  
  return {
    seat_configuration: form.value.seat_configuration,
    door_positions: form.value.door_positions_text 
      ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
      : []
  };
});

const previewSeatMap = computed(() => {
  if (!form.value.use_manual_config || !form.value.manual_seat_map) return null;
  
  try {
    const parsed = JSON.parse(form.value.manual_seat_map);
    jsonError.value = '';
    return { seat_map: parsed };
  } catch (e) {
    jsonError.value = 'JSON invalide: ' + e.message;
    return null;
  }
});

// Watch for changes to validate JSON
watch(() => form.value.manual_seat_map, () => {
  if (form.value.manual_seat_map) {
    try {
      JSON.parse(form.value.manual_seat_map);
      jsonError.value = '';
    } catch (e) {
      jsonError.value = 'JSON invalide: ' + e.message;
    }
  }
});

// Generate seat map JSON from basic configuration
const generateSeatMapJSON = () => {
  // This would call the backend generateSeatMap logic
  // For now, we'll create a simple example
  const seatCount = parseInt(form.value.seat_count) || 60;
  const config = form.value.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const leftCount = parts[0] || 2;
  const rightCount = parts[1] || 2;
  const doorPositions = form.value.door_positions_text 
    ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
    : [0];
  const lastRowSeats = parseInt(form.value.last_row_seats) || 5;
  
  const seatMap = [];
  let currentSeatNum = 1;
  let rowIndex = 0;
  const seatsToFill = seatCount - lastRowSeats;
  
  while (currentSeatNum <= seatsToFill) {
    const row = [];
    
    // Calculate start slot for this row (1-based)
    const rowStartSlot = (rowIndex - 1) * (leftCount + rightCount) + 1;
    
    // Left Side
    if (rowIndex === 0) {
      row.push({ type: 'driver', label: 'Chauffeur' });
      for (let i = 1; i < leftCount; i++) {
        row.push({ type: 'empty' });
      }
    } else {
      for (let i = 0; i < leftCount; i++) {
        const currentSlot = rowStartSlot + i;
        if (doorPositions.includes(currentSlot)) {
          row.push({ type: 'door' });
        } else if (currentSeatNum <= seatsToFill) {
          row.push({ type: 'seat', number: currentSeatNum.toString() });
          currentSeatNum++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }
    
    // Aisle
    row.push({ type: 'aisle' });
    
    // Right Side
    if (rowIndex === 0) {
      if (doorPositions.includes(0)) {
        for (let i = 1; i < rightCount; i++) {
          row.push({ type: 'empty' });
        }
        row.push({ type: 'door', label: 'Porte' });
      } else {
        for (let i = 0; i < rightCount; i++) {
          if (currentSeatNum <= seatsToFill) {
            row.push({ type: 'seat', number: currentSeatNum.toString() });
            currentSeatNum++;
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
        } else if (currentSeatNum <= seatsToFill) {
          row.push({ type: 'seat', number: currentSeatNum.toString() });
          currentSeatNum++;
        } else {
          row.push({ type: 'empty' });
        }
      }
    }
    
    seatMap.push(row);
    rowIndex++;

    // Safety break
    if (rowIndex > 100) break;
  }
  
  // Last Row
  const lastRow = [];
  for (let i = 0; i < lastRowSeats; i++) {
    lastRow.push({ type: 'seat', number: currentSeatNum.toString() });
    currentSeatNum++;
  }
  seatMap.push(lastRow);
  
  form.value.manual_seat_map = JSON.stringify(seatMap, null, 2);
};

// Format JSON
const formatJSON = () => {
  if (!form.value.manual_seat_map) return;
  
  try {
    const parsed = JSON.parse(form.value.manual_seat_map);
    form.value.manual_seat_map = JSON.stringify(parsed, null, 2);
    jsonError.value = '';
  } catch (e) {
    jsonError.value = 'Impossible de formater: JSON invalide';
  }
};

const submit = () => {
  processing.value = true;

  const url = props.vehicleType
    ? route('admin.vehicle-types.update', props.vehicleType.id)
    : route('admin.vehicle-types.store');

  const payload = {
    ...form.value,
    door_positions: form.value.door_positions_text 
      ? form.value.door_positions_text.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n))
      : [],
    // Remove the text field from payload
    door_positions_text: undefined
  };

  router.post(url, payload, {
    onSuccess: () => {
      processing.value = false;
    },
    onError: () => {
      processing.value = false;
    }
  });
};
</script>

