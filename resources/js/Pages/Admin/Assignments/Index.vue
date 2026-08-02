<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Account from 'vue-material-design-icons/Account.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';
import { FULL_PERMISSIONS } from '@/Support/permissions.js';

const props = defineProps({
  assignments: { type: Object, default: () => ({ data: [] }) },
  users: { type: Array, default: () => [] },
  stations: { type: Array, default: () => [] },
  routes: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  permissions: { type: Object, default: () => ({ ...FULL_PERMISSIONS }) },
  hideTripSidebar: { type: Boolean, default: false },
  filterRoute: { type: String, default: '' },
});

const page = usePage();
const routePrefix = computed(() => page.props.auth.user?.role === 'supervisor' ? 'supervisor' : 'admin');
const { exportToExcel, printList } = useExportPrint();
const selectedStationId = ref(props.filters.station_id || '');
const selectedUserId = ref(props.filters.user_id || '');
const statusFilter = ref(props.filters.status || '');
const search = ref(props.filters.search || '');
const showModal = ref(false);
const editing = ref(null);
const processing = ref(false);
const errors = ref({});
const blankForm = () => ({ user_id: '', station_id: '', active: true, route_ids: [] });
const form = ref(blankForm());
const visibleAssignments = computed(() => props.assignments?.data || []);
const hasFilters = computed(() => Boolean(selectedStationId.value || selectedUserId.value || statusFilter.value || search.value));
let filterTimer = null;

const filteredRoutesForSelectedStation = computed(() => {
  if (!form.value.station_id) return [];
  return props.routes.filter(item => item.origin_station_id === form.value.station_id
    || item.destination_station_id === form.value.station_id
    || item.route_stop_orders?.some(stop => stop.station_id === form.value.station_id));
});
const getRouteName = routeId => props.routes.find(item => item.id === routeId)?.name || 'Trajet inconnu';

watch(() => form.value.station_id, (newStationId, oldStationId) => {
  if (newStationId !== oldStationId) {
    const validIds = filteredRoutesForSelectedStation.value.map(item => item.id);
    form.value.route_ids = form.value.route_ids.filter(id => validIds.includes(id));
  }
});

const applyFilters = () => {
  router.get(props.filterRoute || route(`${routePrefix.value}.assignments.index`), {
    station_id: selectedStationId.value || undefined,
    user_id: selectedUserId.value || undefined,
    status: statusFilter.value || undefined,
    search: search.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true, only: ['assignments', 'filters'] });
};
watch([selectedStationId, selectedUserId, statusFilter, search], () => {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(applyFilters, 350);
});
onBeforeUnmount(() => clearTimeout(filterTimer));

const resetFilters = () => {
  selectedStationId.value = '';
  selectedUserId.value = '';
  statusFilter.value = '';
  search.value = '';
};

const openCreate = () => {
  editing.value = null;
  form.value = blankForm();
  errors.value = {};
  showModal.value = true;
};
const openEdit = assignment => {
  editing.value = assignment;
  form.value = {
    user_id: assignment.user_id,
    station_id: assignment.station_id,
    active: assignment.active,
    route_ids: assignment.route_ids || [],
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
    router.put(route(`${routePrefix.value}.assignments.update`, editing.value.id), form.value, options);
  } else {
    router.post(route(`${routePrefix.value}.assignments.store`), form.value, options);
  }
};
const remove = async assignment => {
  const confirmed = await confirmationStore.confirm({
    title: 'Supprimer l’affectation',
    message: `Retirer ${assignment.user?.name} de ${assignment.station?.name} ? Ses autorisations de trajets pour cette gare seront également supprimées.`,
    confirmLabel: 'Supprimer',
    tone: 'danger',
  });
  if (confirmed) router.delete(route(`${routePrefix.value}.assignments.destroy`, assignment.id), { preserveScroll: true });
};

const columns = { 'station.name': 'Gare', 'user.name': 'Vendeur', 'user.email': 'Email', routes_label: 'Trajets', active_label: 'Statut' };
const exportRows = () => visibleAssignments.value.map(assignment => ({
  ...assignment,
  routes_label: assignment.route_ids?.length ? `${assignment.route_ids.length} trajet(s) spécifique(s)` : 'Tous les trajets',
  active_label: assignment.active ? 'Active' : 'Inactive',
}));
const handleExport = () => exportToExcel(exportRows(), columns, 'affectations-guichets');
const handlePrint = () => printList(exportRows(), columns, 'Affectations aux guichets');
</script>

<template>
  <MainNavLayout :fullHeight="true" :hide-trip-sidebar="hideTripSidebar">
    <div class="flex h-full w-full flex-col overflow-hidden">
      <header class="flex shrink-0 flex-col justify-between gap-4 px-6 pb-4 pt-6 md:flex-row md:items-center">
        <div>
          <h1 class="flex items-center gap-3 text-3xl font-black text-slate-900 dark:text-slate-100">
            <span class="rounded-xl bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><AccountCheck :size="28" /></span>
            Affectations aux guichets
          </h1>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Gérez les vendeurs autorisés dans chaque gare et leur périmètre de vente.</p>
        </div>
        <div class="flex items-center gap-2">
          <ExportPrintButtons v-if="permissions.canExport" :disabled="!visibleAssignments.length" @export="handleExport" @print="handlePrint" />
          <button v-if="permissions.canCreate" @click="openCreate" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700"><Plus :size="18" /> Nouvelle affectation</button>
        </div>
      </header>

      <div class="grid min-h-0 flex-1 grid-cols-12 gap-4 px-6 pb-6">
        <aside class="col-span-12 h-full overflow-y-auto pr-2 md:col-span-2"><SettingsMenu /></aside>
        <main class="col-span-12 flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 md:col-span-10">
          <div class="border-b border-slate-100 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-950/40">
            <div class="mb-3 flex items-center justify-between gap-3">
              <div><h2 class="text-sm font-black text-slate-800 dark:text-slate-100">Liste des affectations</h2><p class="text-xs text-slate-500">{{ assignments.total || 0 }} affectation(s) trouvée(s)</p></div>
              <button v-if="hasFilters" @click="resetFilters" class="text-xs font-bold text-emerald-700 dark:text-emerald-300">Réinitialiser les filtres</button>
            </div>
            <div class="grid gap-3 xl:grid-cols-[1fr_1fr_.75fr_1.4fr]">
              <select v-model="selectedStationId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">Toutes les gares</option><option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }} · {{ station.city }}</option></select>
              <select v-model="selectedUserId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">Tous les vendeurs</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select>
              <select v-model="statusFilter" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900 dark:text-white"><option value="">Tous les statuts</option><option value="active">Actives</option><option value="inactive">Inactives</option></select>
              <div class="relative"><Magnify class="absolute left-3 top-2.5 text-emerald-500 dark:text-emerald-400" :size="18" /><input v-model="search" type="search" placeholder="Mot-clé : nom, email, gare, ville, code…" class="w-full rounded-xl border-slate-200 bg-white py-2 pl-10 pr-3 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white" /></div>
            </div>
          </div>

          <div class="flex-1 overflow-auto">
            <div v-if="!visibleAssignments.length" class="flex h-full min-h-72 flex-col items-center justify-center text-center text-slate-400"><AccountCheck :size="48" class="mb-3 opacity-30" /><p class="font-bold">Aucune affectation ne correspond à votre recherche</p><button v-if="hasFilters" @click="resetFilters" class="mt-2 text-sm font-bold text-emerald-600">Effacer les filtres</button><button v-else-if="permissions.canCreate" @click="openCreate" class="mt-2 text-sm font-bold text-emerald-600">Créer la première affectation</button></div>
            <table v-else class="w-full min-w-[1000px] border-collapse text-left">
              <thead class="sticky top-0 z-10 bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500 shadow-[0_1px_0_rgba(148,163,184,0.2)] dark:bg-slate-950 dark:text-slate-400"><tr><th class="px-4 py-3">Gare</th><th class="px-4 py-3">Vendeur</th><th class="px-4 py-3">Rôle</th><th class="px-4 py-3">Périmètre de vente</th><th class="px-4 py-3">Statut</th><th v-if="permissions.canUpdate || permissions.canDelete" class="px-4 py-3 text-right">Actions</th></tr></thead>
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="assignment in visibleAssignments" :key="assignment.id" class="transition hover:bg-emerald-50/40 dark:hover:bg-emerald-950/10">
                  <td class="px-4 py-3.5"><div class="flex items-center gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><MapMarkerRadius :size="19" /></span><span><strong class="block text-sm text-slate-900 dark:text-slate-100">{{ assignment.station?.name }}</strong><span class="text-xs text-slate-500">{{ assignment.station?.city }}<template v-if="assignment.station?.code"> · {{ assignment.station.code }}</template></span></span></div></td>
                  <td class="px-4 py-3.5"><div class="flex items-center gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><Account :size="19" /></span><span><span class="flex items-center gap-2"><strong class="block text-sm text-slate-900 dark:text-slate-100">{{ assignment.user?.name }}</strong><span v-if="assignment.is_self" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">Vous</span></span><span class="text-xs text-slate-500">{{ assignment.user?.email }}</span></span></div></td>
                  <td class="px-4 py-3.5"><span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-black text-blue-700">{{ assignment.user?.role === 'supervisor' ? 'Superviseur' : 'Vendeur' }}</span></td>
                  <td class="px-4 py-3.5"><div class="flex items-center gap-2"><Routes :size="18" class="text-emerald-600" /><span><strong class="block text-sm text-slate-700 dark:text-slate-200">{{ assignment.route_ids?.length ? `${assignment.route_ids.length} trajet(s) spécifique(s)` : 'Tous les trajets' }}</strong><span class="text-xs text-slate-500">{{ assignment.route_ids?.length ? assignment.route_ids.slice(0, 2).map(getRouteName).join(' · ') : 'Accès complet depuis cette gare' }}</span></span></div></td>
                  <td class="px-4 py-3.5"><span :class="assignment.active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-black"><span :class="assignment.active ? 'bg-emerald-500' : 'bg-rose-500'" class="h-1.5 w-1.5 rounded-full"></span>{{ assignment.active ? 'Active' : 'Inactive' }}</span></td>
                  <td v-if="permissions.canUpdate || permissions.canDelete" class="px-4 py-3.5"><div class="flex justify-end gap-1"><button v-if="permissions.canUpdate" @click="openEdit(assignment)" class="rounded-lg p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30" title="Modifier l’affectation" aria-label="Modifier l’affectation"><Pencil :size="18" /></button><button v-if="permissions.canDelete" @click="remove(assignment)" class="rounded-lg p-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30" title="Supprimer l’affectation" aria-label="Supprimer l’affectation"><Delete :size="18" /></button></div></td>
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
        <div><InputLabel for="station_id" value="Gare" /><select id="station_id" v-model="form.station_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required><option value="">Sélectionner une gare</option><option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }} · {{ station.city }}</option></select><InputError :message="errors.station_id" /></div>
        <div><InputLabel for="user_id" value="Vendeur" /><select id="user_id" v-model="form.user_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required><option value="">Sélectionner un vendeur</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select><InputError :message="errors.user_id" /></div>
        <div v-if="form.station_id && filteredRoutesForSelectedStation.length"><InputLabel value="Trajets autorisés" /><p class="mb-2 text-xs text-slate-500">Laissez vide pour autoriser tous les trajets de cette gare.</p><div class="max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950"><label v-for="item in filteredRoutesForSelectedStation" :key="item.id" class="flex cursor-pointer items-start gap-2"><input v-model="form.route_ids" type="checkbox" :value="item.id" class="mt-0.5 rounded border-slate-300 text-emerald-600" /><span class="text-sm text-slate-700 dark:text-slate-200">{{ item.name }}<span class="block text-xs text-slate-400">{{ item.origin_station?.name }} → {{ item.destination_station?.name }}</span></span></label></div></div>
        <p v-else-if="form.station_id" class="text-xs italic text-slate-500">Aucun trajet ne dessert cette gare.</p>
        <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200"><input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-emerald-600" /> Affectation active</label>
      </div></template>
      <template #footer><SecondaryButton @click="closeModal">Annuler</SecondaryButton><PrimaryButton class="ml-3" :disabled="processing || !form.station_id || !form.user_id" @click="submit">{{ processing ? 'Enregistrement…' : editing ? 'Mettre à jour' : 'Enregistrer' }}</PrimaryButton></template>
    </DialogModal>
  </MainNavLayout>
</template>
