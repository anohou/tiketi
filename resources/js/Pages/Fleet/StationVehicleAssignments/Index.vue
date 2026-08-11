<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';
import { toastStore } from '@/Stores/toastStore.js';
import { FULL_PERMISSIONS } from '@/Support/permissions.js';

const props = defineProps({
  assignments: { type: Object, default: () => ({ data: [] }) },
  stations: { type: Array, default: () => [] },
  vehicles: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  operationalSummary: { type: Object, default: () => ({}) },
  stats: { type: Object, default: () => ({}) },
  permissions: { type: Object, default: () => ({ ...FULL_PERMISSIONS }) },
  hideTripSidebar: { type: Boolean, default: false },
  filterRoute: { type: String, default: '' },
  title: { type: String, default: 'Pools de véhicules par gare' },
  subtitle: { type: String, default: 'Définissez les véhicules permanents ou disponibles sur une période donnée.' },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');
const usesSettingsMenu = computed(() => ['admin', 'supervisor', 'seller'].includes(page.props.auth.user?.role));
const search = ref(props.filters.search || '');
const selectedStationId = ref(props.filters.station_id || '');
const selectedVehicleId = ref(props.filters.vehicle_id || '');
const selectedOperationalStatus = ref(props.filters.operational_status || '');
const showModal = ref(false);
const editing = ref(null);
const processing = ref(false);
const errors = ref({});

const blankForm = () => ({
  station_id: '',
  vehicle_id: '',
  permanent: true,
  valid_from: new Date().toISOString().slice(0, 10),
  valid_until: new Date().toISOString().slice(0, 10),
  active: true,
  notes: '',
});

const form = ref(blankForm());

const toggleOperationalFilter = (statusKey) => {
  selectedOperationalStatus.value = selectedOperationalStatus.value === statusKey ? '' : statusKey;
};

const visibleAssignments = computed(() => {
  let list = props.assignments?.data || (Array.isArray(props.assignments) ? props.assignments : []);
  if (selectedOperationalStatus.value) {
    list = list.filter(a => operationalOf(a).status === selectedOperationalStatus.value);
  }
  return list;
});

const hasFilters = computed(() => Boolean(search.value || selectedStationId.value || selectedVehicleId.value || selectedOperationalStatus.value));
let filterTimer = null;

const statusConfig = {
  in_transit: { label: 'En voyage', badge: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300', dot: 'bg-purple-500', card: { title: 'En voyage', accent: 'text-purple-600', bg: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300' } },
  scheduled: { label: 'Programmé', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300', dot: 'bg-blue-500', card: { title: 'Programmés', accent: 'text-blue-600', bg: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' } },
  available: { label: 'Disponible', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', dot: 'bg-emerald-500', card: { title: 'Disponibles', accent: 'text-emerald-600', bg: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' } },
  inactive: { label: 'En panne', badge: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', dot: 'bg-rose-500', card: { title: 'En panne', accent: 'text-rose-600', bg: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' } },
};

const summaryCards = computed(() => [
  { key: 'in_transit', ...statusConfig.in_transit.card, count: props.operationalSummary?.in_transit || 0 },
  { key: 'scheduled', ...statusConfig.scheduled.card, count: props.operationalSummary?.scheduled || 0 },
  { key: 'available', ...statusConfig.available.card, count: props.operationalSummary?.available || 0 },
  { key: 'inactive', ...statusConfig.inactive.card, count: props.operationalSummary?.inactive || 0 },
]);

const operationalOf = assignment => assignment?.operational || assignment?.vehicle?.operational || { status: 'available', trip: null, inactive_reason: null };

const tripLabel = (operational) => {
  const trip = operational?.trip;
  if (!trip) return '';
  const from = trip.origin || '';
  const to = trip.destination || '';
  if (operational.status === 'in_transit') {
    return `${from} → ${to}${trip.departed_at ? ` (Parti à ${trip.departed_at})` : ''}`;
  }
  if (operational.status === 'scheduled') {
    return `${from} → ${to}${trip.departure_time ? ` (Départ ${trip.departure_time})` : ''}`;
  }
  return `${from} → ${to}`;
};

const tripHref = (operational) => {
  const trip = operational?.trip;
  return trip && isAdmin.value ? route('admin.trips.show', trip.id) : null;
};

const applyFilters = () => {
  router.get(props.filterRoute || route('fleet.station-vehicle-assignments.index'), {
    search: search.value || undefined,
    station_id: selectedStationId.value || undefined,
    vehicle_id: selectedVehicleId.value || undefined,
    operational_status: selectedOperationalStatus.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['assignments', 'filters'],
  });
};

watch([search, selectedStationId, selectedVehicleId, selectedOperationalStatus], () => {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(applyFilters, 350);
});

onBeforeUnmount(() => clearTimeout(filterTimer));

const resetFilters = () => {
  search.value = '';
  selectedStationId.value = '';
  selectedVehicleId.value = '';
  selectedOperationalStatus.value = '';
};

const formatDate = value => value
  ? new Intl.DateTimeFormat('fr-FR').format(new Date(`${String(value).slice(0, 10)}T00:00:00`))
  : null;

const periodLabel = (assignment) => {
  if (!assignment.valid_from && !assignment.valid_until) {
    return assignment.station_id ? 'Permanente' : '—';
  }
  return `${formatDate(assignment.valid_from)} → ${formatDate(assignment.valid_until)}`;
};

const openCreate = () => {
  editing.value = null;
  form.value = blankForm();
  errors.value = {};
  showModal.value = true;
};

const openCreateForVehicle = (vehicleId) => {
  editing.value = null;
  form.value = blankForm();
  if (vehicleId) {
    form.value.vehicle_id = vehicleId;
  }
  errors.value = {};
  showModal.value = true;
};

const openEdit = (assignment) => {
  editing.value = assignment;
  const permanent = !assignment.valid_from && !assignment.valid_until;
  form.value = {
    station_id: assignment.station_id,
    vehicle_id: assignment.vehicle_id,
    permanent,
    valid_from: assignment.valid_from?.slice(0, 10) || new Date().toISOString().slice(0, 10),
    valid_until: assignment.valid_until?.slice(0, 10) || new Date().toISOString().slice(0, 10),
    active: assignment.active,
    notes: assignment.notes || '',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  if (processing.value) return;
  showModal.value = false;
};

const submit = () => {
  processing.value = true;
  errors.value = {};
  const options = {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'vehicleAssignment',
    onSuccess: (page) => {
      const pageErrors = page?.props?.errors || {};
      const returnedErrors = pageErrors.vehicleAssignment || pageErrors;
      if (Object.keys(returnedErrors).length > 0) {
        processing.value = false;
        errors.value = returnedErrors;
        showModal.value = true;
        toastStore.error(returnedErrors.vehicle_id || 'Impossible d’affecter ce véhicule à la gare.');
        return;
      }
      processing.value = false;
      showModal.value = false;
    },
    onError: validationErrors => {
      processing.value = false;
      errors.value = validationErrors;
      showModal.value = true;
      toastStore.error(validationErrors.vehicle_id || 'Impossible d’affecter ce véhicule à la gare.');
    },
    onFinish: () => { processing.value = false; },
  };

  if (editing.value) {
    router.put(route('fleet.station-vehicle-assignments.update', editing.value.id), form.value, options);
  } else {
    router.post(route('fleet.station-vehicle-assignments.store'), form.value, options);
  }
};

const remove = async (assignment) => {
  if (!await confirmationStore.confirm({ title: 'Retirer le véhicule du pool', message: `Retirer ${assignment.vehicle?.identifier} du pool de ${assignment.station?.name} ?`, confirmLabel: 'Retirer', tone: 'danger' })) return;
  router.delete(route('fleet.station-vehicle-assignments.destroy', assignment.id), { preserveScroll: true });
};
</script>

<template>
  <MainNavLayout :fullHeight="true" :hide-trip-sidebar="hideTripSidebar">
    <div class="flex h-full w-full flex-col overflow-hidden">
      <header class="flex shrink-0 items-center justify-between gap-4 px-6 pb-4 pt-6">
        <div>
          <h1 class="flex items-center gap-3 text-3xl font-black text-slate-900 dark:text-slate-100">
            <span class="rounded-xl bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><MapMarkerRadius :size="28" /></span>
            {{ props.title }}
          </h1>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ props.subtitle }}</p>
        </div>
        <button v-if="permissions.canCreate" @click="openCreate" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
          <Plus :size="18" /> Nouvelle affectation
        </button>
      </header>

      <div class="grid min-h-0 flex-1 grid-cols-12 gap-4 px-6 pb-6">
        <aside class="col-span-12 h-full overflow-y-auto pr-2 md:col-span-2">
          <SettingsMenu v-if="usesSettingsMenu" :stats="stats" />
          <FleetMenu v-else />
        </aside>

        <main class="col-span-12 flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 md:col-span-10">
          <div class="grid gap-3 border-b border-slate-100 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-950/40 sm:grid-cols-2 lg:grid-cols-4">
            <button
              v-for="card in summaryCards"
              :key="card.key"
              type="button"
              @click="toggleOperationalFilter(card.key)"
              :class="[
                'flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition-all duration-150 cursor-pointer select-none',
                selectedOperationalStatus === card.key
                  ? 'border-emerald-500 bg-emerald-50/70 ring-2 ring-emerald-500/30 dark:bg-emerald-950/50 dark:border-emerald-500'
                  : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800'
              ]"
            >
              <span :class="card.bg" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-base font-black">{{ card.count }}</span>
              <div class="min-w-0">
                <p class="truncate text-sm font-black text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                  {{ card.title }}
                  <span v-if="selectedOperationalStatus === card.key" class="text-xs text-emerald-600 dark:text-emerald-400 font-bold">✓</span>
                </p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ card.key === 'inactive' ? 'véhicules à l’arrêt' : 'véhicules' }}</p>
              </div>
            </button>
          </div>

          <div class="border-b border-slate-100 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-950/40">
            <div class="mb-3 flex items-center justify-between gap-3">
              <div>
                <h2 class="text-sm font-black text-slate-800 dark:text-slate-100">Liste des affectations</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ assignments.total || 0 }} affectation(s) trouvée(s)</p>
              </div>
              <button v-if="hasFilters" @click="resetFilters" class="text-xs font-bold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300">Réinitialiser les filtres</button>
            </div>
            <div class="grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(180px,1fr)_minmax(220px,1.5fr)]">
              <select v-model="selectedStationId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                <option value="">Toutes les gares</option>
                <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }} · {{ station.city }}</option>
              </select>
              <select v-model="selectedVehicleId" class="w-full rounded-xl border-slate-200 bg-white py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                <option value="">Tous les véhicules</option>
                <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">{{ vehicle.identifier }} · {{ vehicle.vehicle_type?.name }}</option>
              </select>
              <div class="relative">
                <Magnify class="absolute left-3 top-2.5 text-emerald-500 dark:text-emerald-400" :size="18" />
                <input
                  v-model="search"
                  type="search"
                  placeholder="Mot-clé : immatriculation, marque, type, note…"
                  class="w-full rounded-xl border-slate-200 bg-white py-2 pl-10 pr-3 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white"
                />
              </div>
            </div>
          </div>

          <div class="flex-1 overflow-auto">
            <div v-if="!visibleAssignments.length" class="flex h-full min-h-72 flex-col items-center justify-center text-center text-slate-400">
              <Bus :size="48" class="mb-3 opacity-30" />
              <p class="font-bold">Aucune affectation ne correspond à votre recherche</p>
              <button v-if="hasFilters" @click="resetFilters" class="mt-2 text-sm font-bold text-emerald-600">Effacer les filtres</button>
              <button v-else-if="permissions.canCreate" @click="openCreate" class="mt-2 text-sm font-bold text-emerald-600">Créer la première affectation</button>
            </div>

            <table v-else class="w-full min-w-[900px] border-collapse text-left">
              <thead class="sticky top-0 z-10 bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500 shadow-[0_1px_0_rgba(148,163,184,0.2)] dark:bg-slate-950 dark:text-slate-400">
                <tr>
                  <th class="px-4 py-3">Gare d'attache</th>
                  <th class="px-4 py-3">Véhicule</th>
                  <th class="px-4 py-3">Type et capacité</th>
                  <th class="px-4 py-3">Période</th>
                  <th class="px-4 py-3">Statut affectation</th>
                  <th class="px-4 py-3">Position & Pool actuel / Voyage</th>
                  <th v-if="permissions.canUpdate || permissions.canDelete" class="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="assignment in visibleAssignments" :key="assignment.id" class="group transition hover:bg-emerald-50/40 dark:hover:bg-emerald-950/10">
                  <td class="px-4 py-3.5">
                    <div v-if="assignment.station" class="flex items-center gap-3">
                      <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><MapMarkerRadius :size="19" /></span>
                      <span class="min-w-0">
                        <strong class="block truncate text-sm text-slate-900 dark:text-slate-100">{{ assignment.station.name }}</strong>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ assignment.station.city }}<template v-if="assignment.station.code"> · {{ assignment.station.code }}</template></span>
                      </span>
                    </div>
                    <div v-else class="flex items-center gap-3">
                      <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500"><MapMarkerRadius :size="19" /></span>
                      <span class="min-w-0">
                        <strong class="block truncate text-sm italic text-slate-500 dark:text-slate-400">Non affecté</strong>
                        <span class="block text-xs text-slate-400 dark:text-slate-500">Pool Général</span>
                      </span>
                    </div>
                  </td>
                  <td class="px-4 py-3.5">
                    <strong class="block text-sm font-black text-slate-900 dark:text-slate-100">{{ assignment.vehicle?.identifier }}</strong>
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ assignment.vehicle?.maker || 'Marque non renseignée' }}</span>
                  </td>
                  <td class="px-4 py-3.5">
                    <span class="block text-sm font-semibold text-slate-700 dark:text-slate-200">{{ assignment.vehicle?.vehicle_type?.name }}</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ assignment.vehicle?.seat_count || assignment.vehicle?.vehicle_type?.seat_count || '—' }} places</span>
                  </td>
                  <td class="px-4 py-3.5"><span class="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ periodLabel(assignment) }}</span></td>
                  <td class="px-4 py-3.5">
                    <span v-if="assignment.station_id" :class="assignment.active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-black">
                      <span :class="assignment.active ? 'bg-emerald-500' : 'bg-rose-500'" class="h-1.5 w-1.5 rounded-full"></span>{{ assignment.active ? 'Active' : 'Inactive' }}
                    </span>
                    <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                      <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>Non affecté
                    </span>
                  </td>
                  <td class="px-4 py-3.5">
                    <div class="flex flex-col items-start gap-1">
                      <div class="flex items-center gap-1.5">
                        <span :class="statusConfig[operationalOf(assignment).status]?.badge || statusConfig.available.badge" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-black">
                          <span :class="statusConfig[operationalOf(assignment).status]?.dot || statusConfig.available.dot" class="h-1.5 w-1.5 rounded-full"></span>{{ statusConfig[operationalOf(assignment).status]?.label || 'Disponible' }}
                        </span>
                        <span v-if="operationalOf(assignment).current_location" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300" :title="'Position opérationnelle actuelle du car'">
                          📍 {{ operationalOf(assignment).current_location.name }}
                        </span>
                      </div>
                      <Link v-if="tripHref(operationalOf(assignment))" :href="tripHref(operationalOf(assignment))" class="max-w-56 truncate text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-300">
                        {{ tripLabel(operationalOf(assignment)) }}
                      </Link>
                      <span v-else class="max-w-56 truncate text-xs text-slate-500 dark:text-slate-400">{{ operationalOf(assignment).status === 'inactive' && operationalOf(assignment).inactive_reason ? operationalOf(assignment).inactive_reason : tripLabel(operationalOf(assignment)) || 'Aucun voyage en cours ni programmé' }}</span>
                    </div>
                  </td>
                  <td v-if="permissions.canUpdate || permissions.canDelete" class="px-4 py-3.5">
                    <div v-if="assignment.station_id" class="flex justify-end gap-1">
                      <button v-if="permissions.canUpdate" @click="openEdit(assignment)" class="rounded-lg p-2 text-blue-600 transition hover:bg-blue-50 dark:hover:bg-blue-950/30" title="Modifier l’affectation"><Pencil :size="18" /></button>
                      <button v-if="permissions.canDelete" @click="remove(assignment)" class="rounded-lg p-2 text-rose-600 transition hover:bg-rose-50 dark:hover:bg-rose-950/30" title="Retirer du pool"><Delete :size="18" /></button>
                    </div>
                    <div v-else-if="permissions.canCreate" class="flex justify-end">
                      <button @click="openCreateForVehicle(assignment.vehicle_id)" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700">
                        <Plus :size="14" /> Affecter
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <nav v-if="assignments.last_page > 1" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3 dark:border-slate-800" aria-label="Pagination">
            <Link
              v-for="link in assignments.links"
              :key="link.label"
              :href="link.url || '#'"
              preserve-scroll
              v-html="link.label"
              :class="[
                'rounded-lg px-3 py-1.5 text-xs font-bold',
                link.active ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                !link.url ? 'pointer-events-none opacity-40' : '',
              ]"
            />
          </nav>
        </main>
      </div>
    </div>

    <DialogModal :show="showModal" maxWidth="lg" @close="closeModal">
      <template #title>{{ editing ? 'Modifier le pool de gare' : 'Affecter un véhicule à une gare' }}</template>
      <template #content>
        <div class="space-y-4">
          <div v-if="errors.vehicle_id" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300" role="alert">
            {{ errors.vehicle_id }}
          </div>
          <div>
            <label class="mb-1 block text-xs font-black uppercase tracking-wider text-slate-500">Gare</label>
            <select v-model="form.station_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required>
              <option value="" disabled>Sélectionner une gare</option>
              <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }} · {{ station.city }}</option>
            </select>
            <InputError :message="errors.station_id" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-black uppercase tracking-wider text-slate-500">Véhicule</label>
            <select v-model="form.vehicle_id" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" required>
              <option value="" disabled>Sélectionner un véhicule</option>
              <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">{{ vehicle.identifier }} · {{ vehicle.vehicle_type?.name }} · {{ vehicle.seat_count }} places</option>
            </select>
          </div>
          <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <input v-model="form.permanent" type="checkbox" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
            <span><strong class="block text-sm text-slate-800 dark:text-slate-100">Affectation permanente</strong><span class="text-xs text-slate-500">Le véhicule reste dans le pool jusqu’à modification.</span></span>
          </label>
          <div v-if="!form.permanent" class="grid grid-cols-2 gap-3">
            <div><label class="mb-1 block text-xs font-bold text-slate-500">Du</label><AppDatePicker v-model="form.valid_from" :max="form.valid_until || ''" /><InputError :message="errors.valid_from" /></div>
            <div><label class="mb-1 block text-xs font-bold text-slate-500">Au</label><AppDatePicker v-model="form.valid_until" :min="form.valid_from || ''" /><InputError :message="errors.valid_until" /></div>
          </div>
          <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">Note facultative</label>
            <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Ex. Renfort du week-end"></textarea>
            <InputError :message="errors.notes" />
          </div>
          <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200"><input v-model="form.active" type="checkbox" class="rounded text-emerald-600" /> Affectation active</label>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="processing || !form.station_id || !form.vehicle_id" @click="submit">{{ processing ? 'Enregistrement…' : 'Enregistrer' }}</PrimaryButton>
      </template>
    </DialogModal>
  </MainNavLayout>
</template>
