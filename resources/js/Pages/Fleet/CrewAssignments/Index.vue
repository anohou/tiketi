<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import { usePage } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';
import Steering from 'vue-material-design-icons/Steering.vue';
import SeatPassenger from 'vue-material-design-icons/SeatPassenger.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue';

const { exportToExcel, printList } = useExportPrint();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

const props = defineProps({
  assignments: {
    type: Object,
    default: () => ({ data: [] }),
  },
  crewMembers: {
    type: Array,
    default: () => [],
  },
  vehicles: {
    type: Array,
    default: () => [],
  },
});

const search = ref('');
const statusFilter = ref('');
const roleFilter = ref('');
const selectedAssignment = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  vehicle_id: '',
  crew_member_id: '',
  role: 'driver',
  assigned_from: '',
  notes: '',
});

// Filtered crew members based on selected role
const filteredCrewForForm = computed(() => {
  return props.crewMembers.filter(m => m.role === form.value.role);
});

const filteredAssignments = computed(() => {
  let assignments = props.assignments?.data || [];

  if (search.value) {
    const searchTerm = search.value.toLowerCase();
    assignments = assignments.filter(a =>
      (a.crew_member?.name || '').toLowerCase().includes(searchTerm) ||
      (a.vehicle?.identifier || '').toLowerCase().includes(searchTerm)
    );
  }

  if (statusFilter.value === 'active') {
    assignments = assignments.filter(a => !a.assigned_to);
  } else if (statusFilter.value === 'closed') {
    assignments = assignments.filter(a => a.assigned_to);
  }

  if (roleFilter.value) {
    assignments = assignments.filter(a => a.role === roleFilter.value);
  }

  return assignments;
});

watch(() => props.assignments, (newAssignments) => {
  if (selectedAssignment.value) {
    const updated = newAssignments.data.find(a => a.id === selectedAssignment.value.id);
    if (updated) {
      selectedAssignment.value = updated;
    } else {
      selectedAssignment.value = null;
    }
  }
}, { deep: true });

const isSelected = (assignment) => selectedAssignment.value?.id === assignment.id;
const selectAssignment = (assignment) => { selectedAssignment.value = assignment; };

const isActive = (assignment) => !assignment.assigned_to;
const getRoleLabel = (role) => role === 'driver' ? 'Chauffeur' : 'Assistant';
const getRoleColor = (role) => role === 'driver' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800';

const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('fr-FR', {
    day: '2-digit', month: 'short', year: 'numeric'
  });
};

const formatDateTime = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleString('fr-FR', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  });
};

const openCreateModal = () => {
  isEditing.value = false;
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  form.value = {
    vehicle_id: '',
    crew_member_id: '',
    role: 'driver',
    assigned_from: now.toISOString().slice(0, 16),
    notes: '',
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedAssignment.value) return;
  isEditing.value = true;
  form.value = {
    vehicle_id: selectedAssignment.value.vehicle_id,
    crew_member_id: selectedAssignment.value.crew_member_id,
    role: selectedAssignment.value.role,
    assigned_from: selectedAssignment.value.assigned_from?.slice(0, 16) || '',
    assigned_to: selectedAssignment.value.assigned_to?.slice(0, 16) || '',
    notes: selectedAssignment.value.notes || '',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = { vehicle_id: '', crew_member_id: '', role: 'driver', assigned_from: '', notes: '' };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('fleet.crew-assignments.update', selectedAssignment.value.id)
    : route('fleet.crew-assignments.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    },
  });
};

const closeAssignment = (id) => {
  if (confirm("Clôturer cette affectation ? L'historique sera conservé.")) {
    router.delete(route('fleet.crew-assignments.destroy', id), {
      onSuccess: () => {
        if (selectedAssignment.value?.id === id) {
          selectedAssignment.value = null;
        }
      },
    });
  }
};

const assignmentColumns = {
  'crew_member.name': 'Membre',
  role: 'Rôle',
  'vehicle.identifier': 'Véhicule',
  assigned_from: 'Début',
  assigned_to: 'Fin',
};

const handleExport = () => {
  const data = filteredAssignments.value.map(a => ({
    ...a,
    role: getRoleLabel(a.role),
    assigned_from: formatDate(a.assigned_from),
    assigned_to: a.assigned_to ? formatDate(a.assigned_to) : 'En cours',
  }));
  exportToExcel(data, assignmentColumns, 'affectations-equipage');
};

const handlePrint = () => {
  const data = filteredAssignments.value.map(a => ({
    ...a,
    role: getRoleLabel(a.role),
    assigned_from: formatDate(a.assigned_from),
    assigned_to: a.assigned_to ? formatDate(a.assigned_to) : 'En cours',
  }));
  printList(data, assignmentColumns, 'Affectations Équipage');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <SwapHorizontal class="text-emerald-600" :size="28" />
            </div>
            Affectations Équipage
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Historique des affectations chauffeurs/assistants aux véhicules</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu v-if="isAdmin" />
          <FleetMenu v-else />
        </div>

        <!-- Middle Column - Assignments List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input
                    type="text"
                    v-model="search"
                    placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100"
                  />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle Affectation">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons
                  :disabled="filteredAssignments.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
              <div class="flex items-center gap-2">
                <select
                  v-model="statusFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded text-[11px] focus:outline-none focus:border-emerald-400 dark:bg-slate-950 dark:text-slate-100"
                >
                  <option value="">Tous les statuts</option>
                  <option value="active">En cours</option>
                  <option value="closed">Clôturées</option>
                </select>
                <select
                  v-model="roleFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded text-[11px] focus:outline-none focus:border-emerald-400 dark:bg-slate-950 dark:text-slate-100"
                >
                  <option value="">Tous les rôles</option>
                  <option value="driver">Chauffeurs</option>
                  <option value="assistant">Assistants</option>
                </select>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredAssignments.length === 0" class="p-4 text-center text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">
                Aucune affectation trouvée.
              </div>
              <div v-else>
                <div
                  v-for="assignment in filteredAssignments"
                  :key="assignment.id"
                  @click="selectAssignment(assignment)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(assignment) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : isActive(assignment) ? 'bg-white dark:bg-slate-900 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex items-center gap-3">
                      <div :class="[
                        'w-8 h-8 rounded-full flex items-center justify-center shrink-0',
                        assignment.role === 'driver' ? 'bg-blue-100' : 'bg-purple-100'
                      ]">
                        <component
                          :is="assignment.role === 'driver' ? Steering : SeatPassenger"
                          :class="assignment.role === 'driver' ? 'text-blue-600' : 'text-purple-600'"
                          :size="16"
                        />
                      </div>
                      <div>
                        <h3 :class="['font-semibold text-sm', isSelected(assignment) ? 'text-emerald-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">
                          {{ assignment.crew_member?.name || 'Inconnu' }}
                        </h3>
                        <p class="text-[10px] text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 flex items-center gap-1 mt-0.5">
                          <Bus :size="12" class="text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500" />
                          {{ assignment.vehicle?.identifier || 'Véhicule inconnu' }}
                        </p>
                        <p class="text-[10px] text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-0.5">
                          {{ formatDate(assignment.assigned_from) }}
                          <template v-if="assignment.assigned_to"> → {{ formatDate(assignment.assigned_to) }}</template>
                        </p>
                      </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="['px-2 py-0.5 rounded-full text-[9px] font-medium', getRoleColor(assignment.role)]">
                        {{ getRoleLabel(assignment.role) }}
                      </span>
                      <span :class="[
                        'flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-medium',
                        isActive(assignment) ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600 dark:text-slate-350 dark:text-slate-350'
                      ]">
                        <component :is="isActive(assignment) ? CheckCircle : CloseCircle" :size="10" />
                        {{ isActive(assignment) ? 'En cours' : 'Clôturée' }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Details -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedAssignment" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500 dark:text-slate-400 dark:text-slate-500">
            <SwapHorizontal class="h-16 w-16 text-slate-300 mb-4" />
            <p class="text-lg">Sélectionnez une affectation pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez une nouvelle affectation
            </button>
          </div>

          <!-- Details -->
          <div v-else class="space-y-4">
            <!-- Assignment Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">Détails de l'Affectation</h2>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold',
                    isActive(selectedAssignment) ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600 dark:text-slate-350 dark:text-slate-350'
                  ]">
                    <component :is="isActive(selectedAssignment) ? CheckCircle : CloseCircle" :size="14" />
                    {{ isActive(selectedAssignment) ? 'En cours' : 'Clôturée' }}
                  </span>
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 dark:bg-blue-950/30 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button
                    v-if="isActive(selectedAssignment)"
                    @click="closeAssignment(selectedAssignment.id)"
                    class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                    title="Clôturer"
                  >
                    <CloseCircle class="h-5 w-5" />
                  </button>
                  <button
                    v-else
                    @click="closeAssignment(selectedAssignment.id)"
                    class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                    title="Supprimer"
                  >
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Crew Member -->
              <div class="grid grid-cols-2 gap-6">
                <div class="col-span-2 md:col-span-1">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">MEMBRE D'ÉQUIPAGE</span>
                  <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                    <div :class="[
                      'w-10 h-10 rounded-full flex items-center justify-center',
                      selectedAssignment.role === 'driver' ? 'bg-blue-100' : 'bg-purple-100'
                    ]">
                      <component
                        :is="selectedAssignment.role === 'driver' ? Steering : SeatPassenger"
                        :class="selectedAssignment.role === 'driver' ? 'text-blue-600' : 'text-purple-600'"
                        :size="20"
                      />
                    </div>
                    <div>
                      <p class="font-semibold text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ selectedAssignment.crew_member?.name }}</p>
                      <span :class="['px-2 py-0.5 rounded-full text-[10px] font-medium', getRoleColor(selectedAssignment.role)]">
                        {{ getRoleLabel(selectedAssignment.role) }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Vehicle -->
                <div class="col-span-2 md:col-span-1">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">VÉHICULE</span>
                  <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                    <Bus class="text-emerald-600" :size="20" />
                    <div>
                      <p class="font-semibold text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ selectedAssignment.vehicle?.identifier }}</p>
                      <p class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">{{ selectedAssignment.vehicle?.vehicle_type?.name || '' }}</p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Dates -->
              <div class="grid grid-cols-2 gap-6 mt-6">
                <div>
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">DÉBUT</span>
                  <p class="text-lg font-medium text-gray-900 dark:text-slate-100">{{ formatDateTime(selectedAssignment.assigned_from) }}</p>
                </div>
                <div>
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">FIN</span>
                  <p class="text-lg font-medium" :class="selectedAssignment.assigned_to ? 'text-gray-900 dark:text-slate-100' : 'text-emerald-600'">
                    {{ selectedAssignment.assigned_to ? formatDateTime(selectedAssignment.assigned_to) : 'En cours' }}
                  </p>
                </div>
              </div>

              <!-- Notes -->
              <div v-if="selectedAssignment.notes" class="mt-6 pt-6 border-t border-gray-100 dark:border-slate-800">
                <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">NOTES</span>
                <p class="text-gray-700 dark:text-slate-300 dark:text-slate-300 text-sm">{{ selectedAssignment.notes }}</p>
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
          <div v-if="!isEditing" class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
            <strong>Note :</strong> Si ce véhicule a déjà un {{ form.role === 'driver' ? 'chauffeur' : 'assistant' }} en cours,
            l'ancienne affectation sera automatiquement clôturée.
          </div>

          <div>
            <InputLabel for="role" value="Rôle" />
            <select
              id="role"
              v-model="form.role"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              :disabled="isEditing"
            >
              <option value="driver">Chauffeur</option>
              <option value="assistant">Assistant</option>
            </select>
            <InputError :message="errors.role" />
          </div>

          <div>
            <InputLabel for="crew_member_id" :value="form.role === 'driver' ? 'Chauffeur' : 'Assistant'" />
            <select
              id="crew_member_id"
              v-model="form.crew_member_id"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="">Sélectionner...</option>
              <option v-for="member in filteredCrewForForm" :key="member.id" :value="member.id">
                {{ member.name }} {{ member.phone ? `(${member.phone})` : '' }}
              </option>
            </select>
            <InputError :message="errors.crew_member_id" />
          </div>

          <div>
            <InputLabel for="vehicle_id" value="Véhicule" />
            <select
              id="vehicle_id"
              v-model="form.vehicle_id"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="">Sélectionner un véhicule</option>
              <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">
                {{ vehicle.identifier }} {{ vehicle.maker ? `(${vehicle.maker})` : '' }}
              </option>
            </select>
            <InputError :message="errors.vehicle_id" />
          </div>

          <div :class="isEditing ? 'grid grid-cols-2 gap-4' : ''">
            <div>
              <InputLabel for="assigned_from" value="Date de début" />
              <TextInput v-model="form.assigned_from" id="assigned_from" type="datetime-local" class="w-full" />
              <InputError :message="errors.assigned_from" />
            </div>
            <div v-if="isEditing">
              <InputLabel for="assigned_to" value="Date de fin (laisser vide = en cours)" />
              <TextInput v-model="form.assigned_to" id="assigned_to" type="datetime-local" class="w-full" />
              <InputError :message="errors.assigned_to" />
            </div>
          </div>

          <div>
            <InputLabel for="notes" value="Notes (optionnel)" />
            <textarea
              id="notes"
              v-model="form.notes"
              rows="2"
              class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              placeholder="Informations complémentaires..."
            ></textarea>
            <InputError :message="errors.notes" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submit" :disabled="processing">
          {{ isEditing ? 'Mettre à jour' : 'Affecter' }}
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
