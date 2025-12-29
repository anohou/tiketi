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
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Settings from 'vue-material-design-icons/Cog.vue';

const props = defineProps({
  stops: {
    type: Object,
    default: () => ({ data: [] })
  },
  stations: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const selectedStop = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  name: '',
  station_id: ''
});

// Computed
const filteredStops = computed(() => {
  const stops = props.stops?.data || [];
  if (!search.value) return stops;

  const searchTerm = search.value.toLowerCase();
  return stops.filter(stop =>
    stop.name.toLowerCase().includes(searchTerm) ||
    stop.station?.name?.toLowerCase().includes(searchTerm)
  );
});

// Watchers
watch(() => props.stops, (newStops) => {
  if (selectedStop.value) {
    const updatedStop = newStops.data.find(s => s.id === selectedStop.value.id);
    if (updatedStop) {
      selectedStop.value = updatedStop;
    }
  }
}, { deep: true });

// Methods
const isSelected = (stop) => {
  if (!selectedStop.value) return false;
  return selectedStop.value.id === stop.id;
};

const selectStop = (stop) => {
  selectedStop.value = stop;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    name: '',
    station_id: ''
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedStop.value) return;
  isEditing.value = true;
  form.value = {
    name: selectedStop.value.name,
    station_id: selectedStop.value.station_id || ''
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    name: '',
    station_id: ''
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.stops.update', selectedStop.value.id)
    : route('admin.stops.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const deleteStop = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cette destination ?')) {
    router.delete(route('admin.stops.destroy', id), {
      onSuccess: () => {
        if (selectedStop.value?.id === id) {
          selectedStop.value = null;
        }
      },
    });
  }
};
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4 h-[calc(100vh-80px)]">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <MapMarkerRadius class="text-green-600" :size="28" />
            </div>
            Gestion des Destinations
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 h-full">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Stops List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouvelle Destination">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1">
              <div v-if="filteredStops.length === 0" class="p-4 text-center text-gray-500">
                Aucune destination trouvée.
              </div>
              <div v-else>
                <div v-for="stop in filteredStops" :key="stop.id" 
                  @click="selectStop(stop)"
                  class="p-3 cursor-pointer transition-colors"
                  :style="{
                    backgroundColor: isSelected(stop) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(stop) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['font-semibold', isSelected(stop) ? 'text-green-800' : 'text-gray-800']">{{ stop.name }}</h3>
                      <p v-if="stop.station" class="text-xs text-gray-500 mt-1">{{ stop.station.name }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto pb-20">
          <!-- Empty State -->
          <div v-if="!selectedStop" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <MapMarkerRadius class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez une destination pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez une nouvelle destination
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800">{{ selectedStop.name }}</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteStop(selectedStop.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">STATION DE RATTACHEMENT</span>
                  <div class="text-xl font-bold text-gray-900 leading-tight">
                    {{ selectedStop.station?.name || 'Aucune' }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal">
      <template #title>
        {{ isEditing ? 'Modifier la Destination' : 'Nouvelle Destination' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="name" value="Nom de la destination" />
            <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Carrefour Jeunesse" />
            <InputError :message="errors.name" />
          </div>
          
          <div>
            <InputLabel for="station_id" value="Station de rattachement (Optionnel)" />
            <select v-model="form.station_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-green-500 focus:ring-green-500">
              <option value="">Aucune station</option>
              <option v-for="s in stations" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <InputError :message="errors.station_id" />
            <p class="mt-1 text-xs text-gray-500">Lier cet arrêt à une station principale permet de le grouper géographiquement.</p>
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

