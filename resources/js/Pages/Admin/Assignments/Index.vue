<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();

const routePrefix = computed(() => {
  return page.props.auth.user.role === 'supervisor' ? 'supervisor' : 'admin';
});
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
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue';
import Settings from 'vue-material-design-icons/Cog.vue';

const props = defineProps({
  assignments: {
    type: Object,
    default: () => ({ data: [] })
  },
  users: {
    type: Array,
    default: () => []
  },
  stations: {
    type: Array,
    default: () => []
  },
  routes: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const selectedAssignment = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  user_id: '',
  station_id: '',
  active: true,
  route_ids: []
});

const filteredRoutesForSelectedStation = computed(() => {
  if (!form.value.station_id) return [];
  const sId = form.value.station_id;
  return props.routes.filter(route => 
    route.origin_station_id === sId ||
    route.destination_station_id === sId ||
    (route.route_stop_orders && route.route_stop_orders.some(stop => stop.station_id === sId))
  );
});

const getRouteName = (routeId) => {
  const route = props.routes.find(r => r.id === routeId);
  return route ? route.name : 'Trajet inconnu';
};

watch(() => form.value.station_id, (newStationId, oldStationId) => {
  if (newStationId !== oldStationId) {
    const validRouteIdsForStation = filteredRoutesForSelectedStation.value.map(r => r.id);
    form.value.route_ids = form.value.route_ids.filter(id => validRouteIdsForStation.includes(id));
  }
});

// Export/Print
const { exportToExcel, printList } = useExportPrint();

const assignmentColumns = {
  'user.name': 'Utilisateur',
  'user.email': 'Email',
  'user.role': 'Rôle',
  'station.name': 'Gare',
  'station.city': 'Ville',
  active: 'Statut',
};

const handleExport = () => {
  const data = filteredAssignments.value.map(a => ({
    ...a,
    active: a.active ? 'Actif' : 'Inactif',
  }));
  exportToExcel(data, assignmentColumns, 'affectations');
};

const handlePrint = () => {
  const data = filteredAssignments.value.map(a => ({
    ...a,
    active: a.active ? 'Actif' : 'Inactif',
  }));
  printList(data, assignmentColumns, 'Affectations Utilisateurs');
};

// Computed
const filteredAssignments = computed(() => {
  const assignments = props.assignments?.data || [];
  if (!search.value) return assignments;

  const searchTerm = search.value.toLowerCase();
  return assignments.filter(assignment =>
    assignment.user?.name.toLowerCase().includes(searchTerm) ||
    assignment.user?.email.toLowerCase().includes(searchTerm) ||
    assignment.station?.name.toLowerCase().includes(searchTerm) ||
    assignment.station?.city?.toLowerCase().includes(searchTerm)
  );
});

// Watchers
watch(() => props.assignments, (newAssignments) => {
  if (selectedAssignment.value) {
    const updatedAssignment = newAssignments.data.find(a => a.id === selectedAssignment.value.id);
    if (updatedAssignment) {
      selectedAssignment.value = updatedAssignment;
    }
  }
}, { deep: true });

// Methods
const isSelected = (assignment) => {
  if (!selectedAssignment.value) return false;
  return selectedAssignment.value.id === assignment.id;
};

const selectAssignment = (assignment) => {
  selectedAssignment.value = assignment;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    user_id: '',
    station_id: '',
    active: true,
    route_ids: []
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedAssignment.value) return;
  isEditing.value = true;
  form.value = {
    user_id: selectedAssignment.value.user_id,
    station_id: selectedAssignment.value.station_id,
    active: selectedAssignment.value.active,
    route_ids: selectedAssignment.value.route_ids || []
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    user_id: '',
    station_id: '',
    active: true,
    route_ids: []
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route(`${routePrefix.value}.assignments.update`, selectedAssignment.value.id)
    : route(`${routePrefix.value}.assignments.store`);

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

const deleteAssignment = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cette affectation ?')) {
    router.delete(route(`${routePrefix.value}.assignments.destroy`, id), {
      onSuccess: () => {
        if (selectedAssignment.value?.id === id) {
          selectedAssignment.value = null;
        }
      },
      onError: (errorResponse) => {
        console.error('Error deleting assignment:', errorResponse);
      }
    });
  }
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <AccountCheck class="text-green-600" :size="28" />
            </div>
            Affectations Utilisateurs
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Assignments List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-orange-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-orange-200 dark:border-slate-800 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 dark:from-slate-950 dark:to-emerald-950/10 shrink-0">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-orange-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shrink-0" title="Nouvelle Affectation">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  :disabled="filteredAssignments.length === 0"
                  @export="handleExport" 
                  @print="handlePrint" 
                />
              </div>

            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredAssignments.length === 0" class="p-4 text-center text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
                Aucune affectation trouvée.
              </div>
              <div v-else>
                <div v-for="assignment in filteredAssignments" :key="assignment.id" 
                  @click="selectAssignment(assignment)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(assignment) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-green-600' : 'bg-white dark:bg-slate-900 border-l-orange-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-center">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['text-sm font-semibold truncate', isSelected(assignment) ? 'text-green-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">
                        {{ assignment.user?.name }}
                      </h3>
                      <p class="text-[10px] text-gray-500 dark:text-slate-450 mt-0.5">
                        {{ assignment.station?.name }} - {{ assignment.station?.city }}
                        <span v-if="assignment.route_ids && assignment.route_ids.length > 0" class="text-emerald-600 dark:text-emerald-400 font-semibold block">
                          {{ assignment.route_ids.length }} trajet(s) spécifique(s)
                        </span>
                        <span v-else class="text-gray-400 dark:text-slate-500 block">
                          Tous les trajets (Accès complet)
                        </span>
                      </p>
                    </div>
                    <span :class="[
                      'shrink-0 ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium',
                      assignment.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    ]">
                      {{ assignment.active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedAssignment" class="bg-white dark:bg-slate-900 rounded-lg border border-orange-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-orange-400">
            <AccountCheck class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez une affectation pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez une nouvelle affectation
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-orange-200 dark:border-slate-800 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">Détails de l'Affectation</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 dark:bg-blue-950/30 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteAssignment(selectedAssignment.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6 border-r border-gray-100 pr-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">UTILISATEUR</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedAssignment.user?.name }}
                  </div>
                  <div class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                    {{ selectedAssignment.user?.email }}
                  </div>
                  <div class="mt-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-800 uppercase tracking-tight">
                      {{ selectedAssignment.user?.role }}
                    </span>
                  </div>
                </div>
                <div class="col-span-6 pl-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">GARE</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedAssignment.station?.name }}
                  </div>
                  <div class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                    {{ selectedAssignment.station?.city }}
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-gray-100 dark:border-slate-800">
                  <span class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wider font-bold block mb-2">STATUT</span>
                  <div>
                    <span :class="[
                       'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                      selectedAssignment.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    ]">
                      {{ selectedAssignment.active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-gray-100 dark:border-slate-800">
                  <span class="text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wider font-bold block mb-2">TRAJETS AUTORISÉS</span>
                  <div v-if="selectedAssignment.route_ids && selectedAssignment.route_ids.length > 0" class="flex flex-wrap gap-2">
                    <span v-for="rId in selectedAssignment.route_ids" :key="rId" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                      {{ getRouteName(rId) }}
                    </span>
                  </div>
                  <div v-else class="text-sm text-gray-500 dark:text-slate-400 italic">
                    Tous les trajets de la gare (Accès complet)
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
        {{ isEditing ? "Modifier l'Affectation" : "Nouvelle Affectation" }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="user_id" value="Utilisateur" />
            <select
              id="user_id"
              v-model="form.user_id"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="">Sélectionner un utilisateur</option>
              <option
                v-for="user in users"
                :key="user.id"
                :value="user.id"
              >
                {{ user.name }} ({{ user.email }}) - {{ user.role }}
              </option>
            </select>
            <InputError :message="errors.user_id" />
          </div>

          <div>
            <InputLabel for="station_id" value="Gare" />
            <select
              id="station_id"
              v-model="form.station_id"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="">Sélectionner une gare</option>
              <option
                v-for="station in stations"
                :key="station.id"
                :value="station.id"
              >
                {{ station.name }} - {{ station.city }}
              </option>
            </select>
            <InputError :message="errors.station_id" />
          </div>

          <div v-if="form.station_id && filteredRoutesForSelectedStation.length > 0">
            <InputLabel value="Trajets autorisés" />
            <p class="text-xs text-gray-550 dark:text-slate-450 mb-2">Sélectionnez les trajets spécifiques autorisés pour cette gare. Laissez vide pour autoriser tous les trajets.</p>
            <div class="max-h-48 overflow-y-auto border border-orange-200 rounded-lg p-3 space-y-2 dark:border-slate-800 dark:bg-slate-950">
              <div v-for="route in filteredRoutesForSelectedStation" :key="route.id" class="flex items-start">
                <input
                  :id="'route_' + route.id"
                  type="checkbox"
                  :value="route.id"
                  v-model="form.route_ids"
                  class="rounded border-orange-200 text-green-600 shadow-sm focus:ring-green-500 mt-0.5"
                />
                <label :for="'route_' + route.id" class="ml-2 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
                  {{ route.name }}
                  <span class="text-xs text-gray-400 block">
                    {{ route.origin_station?.name }} &rarr; {{ route.destination_station?.name }}
                  </span>
                </label>
              </div>
            </div>
          </div>
          <div v-else-if="form.station_id" class="text-xs text-gray-500 italic">
            Aucun trajet ne dessert cette gare.
          </div>

          <div class="flex items-center">
            <input
              id="active"
              v-model="form.active"
              type="checkbox"
              class="rounded border-orange-200 text-green-600 shadow-sm focus:ring-green-500"
            />
            <InputLabel for="active" value="Actif" class="ml-2" />
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
