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
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

const props = defineProps({
  assignments: { type: Object, default: () => ({ data: [] }) },
  users: { type: Array, default: () => [] },
  vehicles: { type: Array, default: () => [] },
});

const { exportToExcel, printList } = useExportPrint();

const search = ref('');
const selectedAssignment = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  user_id: '',
  vehicle_id: '',
  active: true,
});

const filteredAssignments = computed(() => {
  const list = props.assignments?.data || [];
  if (!search.value) return list;

  const q = search.value.toLowerCase();
  return list.filter(assignment =>
    [assignment.user?.name, assignment.user?.email, assignment.vehicle?.identifier, assignment.vehicle?.vehicle_type?.name]
      .filter(Boolean)
      .some(val => String(val).toLowerCase().includes(q))
  );
});

watch(() => props.assignments, (newAssignments) => {
  if (selectedAssignment.value) {
    const updatedAssignment = newAssignments.data.find(a => a.id === selectedAssignment.value.id);
    if (updatedAssignment) {
      selectedAssignment.value = updatedAssignment;
    }
  }
}, { deep: true });

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
    vehicle_id: '',
    active: true,
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedAssignment.value) return;
  isEditing.value = true;
  form.value = {
    user_id: selectedAssignment.value.user_id,
    vehicle_id: selectedAssignment.value.vehicle_id,
    active: selectedAssignment.value.active,
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    user_id: '',
    vehicle_id: '',
    active: true,
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('fleet.assignments.update', selectedAssignment.value.id)
    : route('fleet.assignments.store');

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

const deleteAssignment = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cette affectation ?')) {
    router.delete(route('fleet.assignments.destroy', id), {
      onSuccess: () => {
        if (selectedAssignment.value?.id === id) {
          selectedAssignment.value = null;
        }
      },
      onError: (errorResponse) => {
        console.error('Error deleting assignment:', errorResponse);
      },
    });
  }
};

const assignmentColumns = {
  'user.name': 'Utilisateur',
  'user.email': 'Email',
  'vehicle.identifier': 'Véhicule',
  'vehicle.vehicle_type.name': 'Type',
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
  printList(data, assignmentColumns, 'Affectations Véhicules');
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <AccountGroup class="text-emerald-600" :size="28" />
            </div>
            Affectations véhicules
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Attribuer des véhicules aux fleet managers</p>
        </div>
      </div>

      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu v-if="isAdmin" />
          <FleetMenu v-else />
        </div>

        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input
                    v-model="search"
                    type="text"
                    placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100"
                  />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle affectation">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons
                  :disabled="filteredAssignments.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>

            </div>

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
                  :class="[isSelected(assignment) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-center">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['text-sm font-semibold truncate', isSelected(assignment) ? 'text-emerald-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">
                        {{ assignment.user?.name }}
                      </h3>
                      <p class="text-[10px] text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-0.5">
                        {{ assignment.vehicle?.identifier }} - {{ assignment.vehicle?.vehicle_type?.name }}
                      </p>
                    </div>
                    <span :class="[
                      'shrink-0 ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium',
                      assignment.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ assignment.active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <div v-if="!selectedAssignment" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500 dark:text-slate-400 dark:text-slate-500">
            <AccountGroup class="h-16 w-16 text-slate-300 mb-4" />
            <p class="text-lg">Sélectionnez une affectation pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez une nouvelle affectation
            </button>
          </div>

          <div v-else class="space-y-4">
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">Détails de l'Affectation</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 dark:bg-blue-950/30 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteAssignment(selectedAssignment.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

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
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">VÉHICULE</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedAssignment.vehicle?.identifier }}
                  </div>
                  <div class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">
                    {{ selectedAssignment.vehicle?.vehicle_type?.name }}
                  </div>
                </div>
                <div class="col-span-12 pt-4 border-t border-gray-100 dark:border-slate-800">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">STATUT</span>
                  <div>
                    <span :class="[
                      'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                      selectedAssignment.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ selectedAssignment.active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

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
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
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
            <InputLabel for="vehicle_id" value="Véhicule" />
            <select
              id="vehicle_id"
              v-model="form.vehicle_id"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="">Sélectionner un véhicule</option>
              <option
                v-for="vehicle in vehicles"
                :key="vehicle.id"
                :value="vehicle.id"
              >
                {{ vehicle.identifier }} - {{ vehicle.vehicle_type?.name }}
              </option>
            </select>
            <InputError :message="errors.vehicle_id" />
          </div>

          <div class="flex items-center">
            <input
              id="active"
              v-model="form.active"
              type="checkbox"
              class="rounded border-slate-200 text-emerald-600 shadow-sm focus:ring-emerald-500"
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
