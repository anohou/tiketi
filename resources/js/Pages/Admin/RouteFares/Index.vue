<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import Settings from 'vue-material-design-icons/Cog.vue';

const props = defineProps({
  fares: {
    type: Array,
    default: () => []
  },
  stations: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const selectedFare = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  from_station_id: '',
  to_station_id: '',
  amount: '',
  is_bidirectional: true
});

// Computed
const filteredFares = computed(() => {
  if (!search.value) return props.fares;

  const searchTerm = search.value.toLowerCase();
  return props.fares.filter(fare =>
    fare.from_station?.name.toLowerCase().includes(searchTerm) ||
    fare.to_station?.name.toLowerCase().includes(searchTerm) ||
    fare.from_station?.city?.toLowerCase().includes(searchTerm) ||
    fare.to_station?.city?.toLowerCase().includes(searchTerm)
  );
});

// Filter out selected departure from arrival options
const availableToStations = computed(() => {
  if (!form.value.from_station_id) return props.stations;
  return props.stations.filter(station => station.id !== form.value.from_station_id);
});

// Filter out selected arrival from departure options
const availableFromStations = computed(() => {
  if (!form.value.to_station_id) return props.stations;
  return props.stations.filter(station => station.id !== form.value.to_station_id);
});

// Watchers
watch(() => props.fares, (newFares) => {
  if (selectedFare.value) {
    const updatedFare = newFares.find(f => f.id === selectedFare.value.id);
    if (updatedFare) {
      selectedFare.value = updatedFare;
    }
  }
}, { deep: true });

// Methods
const isSelected = (fare) => {
  if (!selectedFare.value) return false;
  return selectedFare.value.id === fare.id;
};

const selectFare = (fare) => {
  selectedFare.value = fare;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    from_station_id: '',
    to_station_id: '',
    amount: '',
    is_bidirectional: true
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedFare.value) return;
  isEditing.value = true;
  form.value = {
    from_station_id: selectedFare.value.from_station_id,
    to_station_id: selectedFare.value.to_station_id,
    amount: selectedFare.value.amount,
    is_bidirectional: selectedFare.value.is_bidirectional ?? true
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    from_station_id: '',
    to_station_id: '',
    amount: '',
    is_bidirectional: true
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  if (isEditing.value) {
    router.put(route('admin.route-fares.update', selectedFare.value.id), form.value, {
      onSuccess: () => {
        processing.value = false;
        closeModal();
      },
      onError: (newErrors) => {
        processing.value = false;
        errors.value = newErrors;
      }
    });
  } else {
    router.post(route('admin.route-fares.store'), form.value, {
      onSuccess: () => {
        processing.value = false;
        closeModal();
      },
      onError: (newErrors) => {
        processing.value = false;
        errors.value = newErrors;
      }
    });
  }
};

const deleteFare = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce tarif ?')) {
    router.delete(route('admin.route-fares.destroy', id), {
      onSuccess: () => {
        if (selectedFare.value?.id === id) {
          selectedFare.value = null;
        }
      },
      onError: (errorResponse) => {
        alert('Impossible de supprimer ce tarif.');
      }
    });
  }
};

const getStationLabel = (station) => {
  if (station.city) {
    return `${station.name} (${station.city})`;
  }
  return station.name;
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <CashMultiple class="text-green-600" :size="28" />
            </div>
            Gestion des Tarifs
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 shrink-0">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouveau Tarif">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredFares.length === 0" class="p-4 text-center text-gray-500">
                Aucun tarif trouvé.
              </div>
              <div v-else>
                <div v-for="fare in filteredFares" :key="fare.id" 
                  @click="selectFare(fare)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0"
                  :style="{
                    backgroundColor: isSelected(fare) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(fare) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-center">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['text-sm font-semibold truncate', isSelected(fare) ? 'text-green-800' : 'text-gray-800']">
                        {{ fare.from_station?.name }} 
                        <span v-if="fare.is_bidirectional" class="text-orange-500 mx-1">↔</span>
                        <span v-else class="mx-1">→</span>
                        {{ fare.to_station?.name }}
                      </h3>
                      <p class="text-[10px] text-gray-500 mt-1 truncate">
                        {{ fare.from_station?.city || '' }} → {{ fare.to_station?.city || '' }}
                      </p>
                    </div>
                    <div class="ml-3 text-right shrink-0">
                      <span class="text-base font-bold text-green-700">{{ fare.amount?.toLocaleString() }}</span>
                      <span class="text-[10px] text-gray-500 ml-0.5">FCFA</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedFare" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <CashMultiple class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez un tarif pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez un nouveau tarif
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Détails du Tarif</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteFare(selectedFare.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6 border-r border-gray-100 pr-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">DÉPART</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedFare.from_station?.name }}
                  </div>
                  <div class="text-sm text-gray-500 mt-1">
                    {{ selectedFare.from_station?.city }}
                  </div>
                </div>
                <div class="col-span-6 pl-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">ARRIVÉE</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedFare.to_station?.name }}
                  </div>
                  <div class="text-sm text-gray-500 mt-1">
                    {{ selectedFare.to_station?.city }}
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-gray-100">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">MONTANT</span>
                  <div class="text-3xl font-bold text-green-700">
                    {{ selectedFare.amount?.toLocaleString() }} <span class="text-base font-normal text-gray-500">FCFA</span>
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-gray-100">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">DIRECTION</span>
                  <div>
                    <span v-if="selectedFare.is_bidirectional" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-orange-100 text-orange-800">
                      ↔ Bidirectionnel (aller-retour)
                    </span>
                    <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                      → Sens unique
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier le Tarif' : 'Nouveau Tarif' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="from_station_id" value="Départ" />
              <select v-model="form.from_station_id" id="from_station_id"
                class="w-full border-orange-200 rounded-lg shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2"
                :class="{ 'border-red-500': errors.from_station_id }">
                <option value="">Sélectionner une gare</option>
                <option v-for="station in availableFromStations" :key="station.id" :value="station.id">
                  {{ getStationLabel(station) }}
                </option>
              </select>
              <InputError :message="errors.from_station_id" />
            </div>

            <div>
              <InputLabel for="to_station_id" value="Arrivée" />
              <select v-model="form.to_station_id" id="to_station_id"
                class="w-full border-orange-200 rounded-lg shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2"
                :class="{ 'border-red-500': errors.to_station_id }">
                <option value="">Sélectionner une gare</option>
                <option v-for="station in availableToStations" :key="station.id" :value="station.id">
                  {{ getStationLabel(station) }}
                </option>
              </select>
              <InputError :message="errors.to_station_id" />
            </div>
          </div>

          <div>
            <InputLabel for="amount" value="Montant (FCFA)" />
            <TextInput v-model="form.amount" id="amount" type="number" placeholder="Ex: 5000" class="w-full"
              :class="{ 'border-red-500': errors.amount }" />
            <InputError :message="errors.amount" />
          </div>

          <div class="flex items-center justify-between p-3 bg-orange-50 rounded-lg border border-orange-200">
            <div>
              <span class="font-medium text-gray-800">Tarif bidirectionnel</span>
              <p class="text-xs text-gray-500 mt-0.5">Le même tarif s'applique dans les deux sens</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" v-model="form.is_bidirectional" class="sr-only peer" />
              <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submit" :disabled="processing">
          {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
        </PrimaryButton>
      </template>
    </DialogModal>
  </MainNavLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #fed7aa;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #fdba74;
}
</style>
