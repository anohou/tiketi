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
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';

const { exportToExcel, printList } = useExportPrint();

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
  is_bidirectional: true,
  active: true
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
    amount: '',
    is_bidirectional: true,
    active: true
  };
  errors.value = {};
  processing.value = false;
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedFare.value) return;
  isEditing.value = true;
  form.value = {
    from_station_id: selectedFare.value.from_station_id,
    to_station_id: selectedFare.value.to_station_id,
    amount: selectedFare.value.amount,
    is_bidirectional: selectedFare.value.is_bidirectional ?? true,
    active: selectedFare.value.active !== undefined ? Boolean(selectedFare.value.active) : true
  };
  errors.value = {};
  processing.value = false;
  showModal.value = true;
};

const duplicateFare = () => {
  if (!selectedFare.value) return;
  isEditing.value = false;
  form.value = {
    from_station_id: selectedFare.value.from_station_id,
    to_station_id: selectedFare.value.to_station_id,
    amount: selectedFare.value.amount,
    is_bidirectional: selectedFare.value.is_bidirectional ?? true,
    active: true
  };
  errors.value = {};
  processing.value = false;
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    amount: '',
    is_bidirectional: true,
    active: true
  };
  errors.value = {};
  processing.value = false;
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  if (isEditing.value) {
    router.put(route('admin.route-fares.update', selectedFare.value.id), form.value, {
      onSuccess: () => {
        closeModal();
      },
      onError: (newErrors) => {
        errors.value = newErrors;
      },
      onFinish: () => {
        processing.value = false;
      }
    });
  } else {
    router.post(route('admin.route-fares.store'), form.value, {
      onSuccess: () => {
        closeModal();
      },
      onError: (newErrors) => {
        errors.value = newErrors;
      },
      onFinish: () => {
        processing.value = false;
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

// Export/Print configuration
const fareColumns = {
  'from_station.name': 'Départ',
  'to_station.name': 'Arrivée',
  'amount': 'Montant',
  'is_bidirectional': 'Aller-Retour',
  'active': 'Statut'
};

const handleExport = () => {
  const data = filteredFares.value.map(f => ({
    ...f,
    active: f.active ? 'Actif' : 'Inactif'
  }));
  exportToExcel(data, fareColumns, 'tarifs');
};

const handlePrint = () => {
  const data = filteredFares.value.map(f => ({
    ...f,
    active: f.active ? 'Actif' : 'Inactif'
  }));
  printList(data, fareColumns, 'Liste des Tarifs');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <CashMultiple class="text-emerald-600" :size="28" />
            </div>
            Gestion des Tarifs
          </h1>
          <p class="text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
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
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors" title="Nouveau Tarif">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              <div class="flex justify-end mt-2">
                <ExportPrintButtons 
                  :disabled="filteredFares.length === 0"
                  small
                  @export="handleExport" 
                  @print="handlePrint" 
                />
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredFares.length === 0" class="p-4 text-center text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                Aucun tarif trouvé.
              </div>
              <div v-else>
                <div v-for="fare in filteredFares" :key="fare.id" 
                  @click="selectFare(fare)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(fare) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['text-sm font-semibold truncate', isSelected(fare) ? 'text-emerald-800' : 'text-slate-800 dark:text-slate-200 dark:text-slate-200']">
                        {{ fare.from_station?.name }} 
                        <span v-if="fare.is_bidirectional" class="text-emerald-500 mx-1">↔</span>
                        <span v-else class="text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mx-1">→</span>
                        {{ fare.to_station?.name }}
                      </h3>
                      <p class="text-[10px] text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1 truncate">
                        {{ fare.from_station?.city || '' }} → {{ fare.to_station?.city || '' }}
                      </p>
                    </div>
                    <div class="text-right shrink-0 whitespace-nowrap">
                      <span class="text-base font-bold text-emerald-700">{{ fare.amount?.toLocaleString() }}</span>
                      <span class="text-[10px] text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 ml-0.5">FCFA</span>
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
          <div v-if="!selectedFare" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
            <CashMultiple class="h-16 w-16 text-slate-200 mb-4" />
            <p class="text-lg">Sélectionnez un tarif pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez un nouveau tarif
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-200 dark:text-slate-200">Détails du Tarif</h2>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedFare.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]">
                    {{ selectedFare.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="duplicateFare" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
                  <button @click="openEditModal" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteFare(selectedFare.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">DÉPART</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedFare.from_station?.name }}
                  </div>
                  <div class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                    {{ selectedFare.from_station?.city }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">ARRIVÉE</span>
                  <div class="text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">
                    {{ selectedFare.to_station?.name }}
                  </div>
                  <div class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                    {{ selectedFare.to_station?.city }}
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-slate-100 dark:border-slate-800/50 dark:border-slate-800/50">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">MONTANT</span>
                  <div class="text-3xl font-bold text-emerald-700">
                    {{ selectedFare.amount?.toLocaleString() }} <span class="text-base font-normal text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">FCFA</span>
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-slate-100 dark:border-slate-800/50 dark:border-slate-800/50">
                  <span class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">DIRECTION</span>
                  <div>
                    <span v-if="selectedFare.is_bidirectional" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-emerald-100 text-emerald-800">
                      ↔ Bidirectionnel (aller-retour)
                    </span>
                    <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300">
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
                class="w-full border-slate-200 dark:border-slate-800 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 dark:bg-slate-950 dark:text-slate-100"
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
                class="w-full border-slate-200 dark:border-slate-800 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm py-2 dark:bg-slate-950 dark:text-slate-100"
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

          <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg border border-slate-100 dark:border-slate-800/60">
            <div>
              <span class="font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">Tarif bidirectionnel</span>
              <p class="text-xs text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-0.5">Le même tarif s'applique dans les deux sens</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" v-model="form.is_bidirectional" class="sr-only peer" />
              <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
            </label>
          </div>

          <div class="flex items-center">
            <input type="checkbox" v-model="form.active" id="fare_active" class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
            <label for="fare_active" class="ml-2 text-sm text-slate-600 dark:text-slate-350 dark:text-slate-350">Tarif Actif</label>
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
  background: #cbd5e1;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
