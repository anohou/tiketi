<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue';
import Steering from 'vue-material-design-icons/Steering.vue';
import SeatPassenger from 'vue-material-design-icons/SeatPassenger.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const props = defineProps({
  assignments: { type: Object, default: () => ({ data: [] }) },
  crewMembers: { type: Array, default: () => [] },
  vehicles: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');
const { exportToExcel, printList } = useExportPrint();

const selectedVehicleId = ref(props.filters.vehicle_id || '');
const selectedCrewMemberId = ref(props.filters.crew_member_id || '');
const roleFilter = ref(props.filters.role || '');
const statusFilter = ref(props.filters.status || '');
const search = ref(props.filters.search || '');
const showModal = ref(false);
const editing = ref(null);
const processing = ref(false);
const errors = ref({});

const blankForm = () => ({
  vehicle_id: '',
  crew_member_id: '',
  role: 'driver',
  assigned_from: '',
  assigned_to: '',
  notes: '',
});
const form = ref(blankForm());
const visibleAssignments = computed(() => props.assignments?.data || []);
const hasFilters = computed(() => Boolean(selectedVehicleId.value || selectedCrewMemberId.value || roleFilter.value || statusFilter.value || search.value));
const filteredCrewForForm = computed(() => props.crewMembers.filter(member => member.role === form.value.role));
let filterTimer = null;

const applyFilters = () => {
  router.get(route('fleet.crew-assignments.index'), {
    vehicle_id: selectedVehicleId.value || undefined,
    crew_member_id: selectedCrewMemberId.value || undefined,
    role: roleFilter.value || undefined,
    status: statusFilter.value || undefined,
    search: search.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['assignments', 'filters'],
  });
};

watch([selectedVehicleId, selectedCrewMemberId, roleFilter, statusFilter, search], () => {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(applyFilters, 350);
});
onBeforeUnmount(() => clearTimeout(filterTimer));

const resetFilters = () => {
  selectedVehicleId.value = '';
  selectedCrewMemberId.value = '';
  roleFilter.value = '';
  statusFilter.value = '';
  search.value = '';
};

const isActive = assignment => !assignment.assigned_to;
const roleLabel = role => role === 'driver' ? 'Chauffeur' : 'Assistant';
const formatDate = value => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(value)) : '—';
const formatDateTime = value => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';

const openCreate = () => {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  editing.value = null;
  form.value = { ...blankForm(), assigned_from: now.toISOString().slice(0, 16) };
  errors.value = {};
  showModal.value = true;
};

const openEdit = assignment => {
  editing.value = assignment;
  form.value = {
    vehicle_id: assignment.vehicle_id,
    crew_member_id: assignment.crew_member_id,
    role: assignment.role,
    assigned_from: assignment.assigned_from?.slice(0, 16) || '',
    assigned_to: assignment.assigned_to?.slice(0, 16) || '',
    notes: assignment.notes || '',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  if (processing.value) return;
  showModal.value = false;
  editing.value = null;
};

const submit = () => {
  processing.value = true;
  errors.value = {};
  const options = {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; editing.value = null; },
    onError: validationErrors => { errors.value = validationErrors; },
    onFinish: () => { processing.value = false; },
  };
  if (editing.value) {
    router.put(route('fleet.crew-assignments.update', editing.value.id), form.value, options);
  } else {
    router.post(route('fleet.crew-assignments.store'), form.value, options);
  }
};

const remove = async assignment => {
  const active = isActive(assignment);
  const confirmed = await confirmationStore.confirm({
    title: active ? 'Clôturer l’affectation' : 'Supprimer l’affectation',
    message: active
      ? `Clôturer l’affectation de ${assignment.crew_member?.name} au véhicule ${assignment.vehicle?.identifier} ? L’historique sera conservé.`
      : `Supprimer définitivement l’affectation de ${assignment.crew_member?.name} ?`,
    confirmLabel: active ? 'Clôturer' : 'Supprimer',
    tone: active ? 'warning' : 'danger',
  });
  if (confirmed) router.delete(route('fleet.crew-assignments.destroy', assignment.id), { preserveScroll: true });
};

const columns = {
  'crew_member.name': 'Membre', role: 'Rôle', 'vehicle.identifier': 'Véhicule', assigned_from: 'Début', assigned_to: 'Fin',
};
const exportRows = () => visibleAssignments.value.map(assignment => ({
  ...assignment,
  role: roleLabel(assignment.role),
  assigned_from: formatDateTime(assignment.assigned_from),
  assigned_to: assignment.assigned_to ? formatDateTime(assignment.assigned_to) : 'En cours',
}));
const handleExport = () => exportToExcel(exportRows(), columns, 'affectations-equipage');
const handlePrint = () => printList(exportRows(), columns, 'Affectations Équipage');
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex h-full w-full flex-col overflow-hidden">
      <header class="flex shrink-0 flex-col justify-between gap-4 px-6 pb-4 pt-6 md:flex-row md:items-center">
        <div>
          <h1 class="flex items-center gap-3 text-3xl font-black text-slate-900 dark:text-slate-100">
            <span class="rounded-xl bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><SwapHorizontal :size="28" /></span>
            Affectations Équipage
          </h1>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Gérez et consultez les chauffeurs et assistants affectés à chaque véhicule.</p>
        </div>
        <div class="flex items-center gap-2">
          <ExportPrintButtons :disabled="!visibleAssignments.length" @export="handleExport" @print="handlePrint" />
          <button @click="openCreate" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
            <Plus :size="18" /> Nouvelle affectation
          </button>
        </div>
      </header>

      <div class="grid min-h-0 flex-1 grid-cols-12 gap-4 px-6 pb-6">
        <aside class="col-span-12 h-full overflow-y-auto pr-2 md:col-span-2">
          <SettingsMenu v-if="isAdmin" />
          <FleetMenu v-else />
        </aside>

        <main class="col-span-12 flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 md:col-span-10">
          <div class="border-b border-slate-100 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-950/40">
            <div class="mb-3 flex items-center justify-between gap-3">
              <div><h2 class="text-sm font-black text-slate-800 dark:text-slate-100">Liste des affectations</h2><p class="text-xs text-slate-500">{{ assignments.total || 0 }} affectation(s) trouvée(s)</p></div>
              <button v-if="hasFilters" @click="resetFilters" class="text-xs font-bold text-emerald-700 dark:text-emerald-300">Réinitialiser les filtres</button>
            </div>
            <div class="grid gap-3 xl:grid-cols-[1fr_1fr_.8fr_.8fr_1.35fr]">
              <select v-model="selectedVehicleId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                <option value="">Tous les véhicules</option><option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">{{ vehicle.identifier }} · {{ vehicle.vehicle_type?.name }}</option>
              </select>
              <select v-model="selectedCrewMemberId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                <option value="">Tout l’équipage</option><option v-for="member in crewMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
              </select>
              <select v-model="roleFilter" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                <option value="">Tous les rôles</option><option value="driver">Chauffeurs</option><option value="assistant">Assistants</option>
              </select>
              <select v-model="statusFilter" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                <option value="">Tous les statuts</option><option value="active">En cours</option><option value="closed">Clôturées</option>
              </select>
              <div class="relative"><Magnify class="absolute left-3 top-2.5 text-emerald-500 dark:text-emerald-400" :size="18" /><input v-model="search" type="search" placeholder="Mot-clé : nom, téléphone, véhicule, note…" class="w-full rounded-xl border-slate-200 bg-white py-2 pl-10 pr-3 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white" /></div>
            </div>
          </div>

          <div class="flex-1 overflow-auto">
            <div v-if="!visibleAssignments.length" class="flex h-full min-h-72 flex-col items-center justify-center text-center text-slate-400">
              <SwapHorizontal :size="48" class="mb-3 opacity-30" /><p class="font-bold">Aucune affectation ne correspond à votre recherche</p>
              <button v-if="hasFilters" @click="resetFilters" class="mt-2 text-sm font-bold text-emerald-600">Effacer les filtres</button><button v-else @click="openCreate" class="mt-2 text-sm font-bold text-emerald-600">Créer la première affectation</button>
            </div>
            <table v-else class="w-full min-w-[1050px] border-collapse text-left">
              <thead class="sticky top-0 z-10 bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500 shadow-[0_1px_0_rgba(148,163,184,0.2)] dark:bg-slate-950 dark:text-slate-400">
                <tr><th class="px-4 py-3">Membre d’équipage</th><th class="px-4 py-3">Rôle</th><th class="px-4 py-3">Véhicule</th><th class="px-4 py-3">Période</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3">Notes</th><th class="px-4 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="assignment in visibleAssignments" :key="assignment.id" class="transition hover:bg-emerald-50/40 dark:hover:bg-emerald-950/10">
                  <td class="px-4 py-3.5"><div class="flex items-center gap-3"><span :class="assignment.role === 'driver' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"><component :is="assignment.role === 'driver' ? Steering : SeatPassenger" :size="19" /></span><span><strong class="block text-sm text-slate-900 dark:text-slate-100">{{ assignment.crew_member?.name }}</strong><span class="text-xs text-slate-500">{{ assignment.crew_member?.phone || 'Téléphone non renseigné' }}</span></span></div></td>
                  <td class="px-4 py-3.5"><span :class="assignment.role === 'driver' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'" class="rounded-full px-2.5 py-1 text-xs font-black">{{ roleLabel(assignment.role) }}</span></td>
                  <td class="px-4 py-3.5"><div class="flex items-center gap-2"><Bus :size="18" class="text-emerald-600" /><span><strong class="block text-sm text-slate-900 dark:text-slate-100">{{ assignment.vehicle?.identifier }}</strong><span class="text-xs text-slate-500">{{ assignment.vehicle?.vehicle_type?.name || assignment.vehicle?.maker || 'Type non renseigné' }}</span></span></div></td>
                  <td class="px-4 py-3.5"><strong class="block text-xs text-slate-700 dark:text-slate-200">Depuis {{ formatDate(assignment.assigned_from) }}</strong><span class="text-xs text-slate-500">{{ assignment.assigned_to ? `Jusqu’au ${formatDate(assignment.assigned_to)}` : 'Sans date de fin' }}</span></td>
                  <td class="px-4 py-3.5"><span :class="isActive(assignment) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-black"><span :class="isActive(assignment) ? 'bg-emerald-500' : 'bg-slate-400'" class="h-1.5 w-1.5 rounded-full"></span>{{ isActive(assignment) ? 'En cours' : 'Clôturée' }}</span></td>
                  <td class="max-w-52 px-4 py-3.5"><p class="truncate text-xs text-slate-500" :title="assignment.notes || ''">{{ assignment.notes || '—' }}</p></td>
                  <td class="px-4 py-3.5"><div class="flex justify-end gap-1"><button @click="openEdit(assignment)" class="rounded-lg p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30" title="Modifier l’affectation" aria-label="Modifier l’affectation"><Pencil :size="18" /></button><button @click="remove(assignment)" class="rounded-lg p-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30" :title="isActive(assignment) ? 'Clôturer l’affectation' : 'Supprimer l’affectation'" :aria-label="isActive(assignment) ? 'Clôturer l’affectation' : 'Supprimer l’affectation'"><Delete :size="18" /></button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
          <nav v-if="assignments.last_page > 1" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3 dark:border-slate-800" aria-label="Pagination"><Link v-for="link in assignments.links" :key="link.label" :href="link.url || '#'" preserve-scroll v-html="link.label" :class="['rounded-lg px-3 py-1.5 text-xs font-bold', link.active ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', !link.url ? 'pointer-events-none opacity-40' : '']" /></nav>
        </main>
      </div>
    </div>

    <DialogModal :show="showModal" maxWidth="md" @close="closeModal">
      <template #title>{{ editing ? 'Modifier l’affectation' : 'Nouvelle affectation' }}</template>
      <template #content><div class="space-y-4">
        <div v-if="!editing" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"><strong>À savoir :</strong> l’affectation active occupant déjà ce rôle sera automatiquement clôturée.</div>
        <div><InputLabel for="role" value="Rôle" /><select id="role" v-model="form.role" :disabled="Boolean(editing)" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="driver">Chauffeur</option><option value="assistant">Assistant</option></select><InputError :message="errors.role" /></div>
        <div><InputLabel for="crew_member_id" :value="form.role === 'driver' ? 'Chauffeur' : 'Assistant'" /><select id="crew_member_id" v-model="form.crew_member_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required><option value="">Sélectionner</option><option v-for="member in filteredCrewForForm" :key="member.id" :value="member.id">{{ member.name }}{{ member.phone ? ` · ${member.phone}` : '' }}</option></select><InputError :message="errors.crew_member_id" /></div>
        <div><InputLabel for="vehicle_id" value="Véhicule" /><select id="vehicle_id" v-model="form.vehicle_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required><option value="">Sélectionner un véhicule</option><option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">{{ vehicle.identifier }}{{ vehicle.maker ? ` · ${vehicle.maker}` : '' }}</option></select><InputError :message="errors.vehicle_id" /></div>
        <div :class="editing ? 'grid grid-cols-2 gap-3' : ''"><div><InputLabel for="assigned_from" value="Date de début" /><TextInput id="assigned_from" v-model="form.assigned_from" type="datetime-local" class="w-full" /><InputError :message="errors.assigned_from" /></div><div v-if="editing"><InputLabel for="assigned_to" value="Date de fin" /><TextInput id="assigned_to" v-model="form.assigned_to" type="datetime-local" class="w-full" /><InputError :message="errors.assigned_to" /></div></div>
        <div><InputLabel for="notes" value="Notes (facultatif)" /><textarea id="notes" v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Informations complémentaires…"></textarea><InputError :message="errors.notes" /></div>
      </div></template>
      <template #footer><SecondaryButton @click="closeModal">Annuler</SecondaryButton><PrimaryButton class="ml-3" :disabled="processing || !form.vehicle_id || !form.crew_member_id || !form.assigned_from" @click="submit">{{ processing ? 'Enregistrement…' : editing ? 'Mettre à jour' : 'Affecter' }}</PrimaryButton></template>
    </DialogModal>
  </MainNavLayout>
</template>
