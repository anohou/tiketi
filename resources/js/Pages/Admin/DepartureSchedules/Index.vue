<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SelectBox from '@/Components/SelectBox.vue';
import DialogModal from '@/Components/DialogModal.vue';
import DepartureScheduleFormModal from '@/Components/DepartureScheduleFormModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue';
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue';
import BusClock from 'vue-material-design-icons/BusClock.vue';
import Check from 'vue-material-design-icons/Check.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';
import { toastStore } from '@/Stores/toastStore.js';

const props = defineProps({
  schedules: { type: Object, default: () => ({ data: [] }) },
  stations: { type: Array, default: () => [] },
  routes: { type: Array, default: () => [] },
  vehicleTypes: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
  companyDefaultPolicy: { type: String, default: 'require_real_vehicle' },
  stats: { type: Object, default: () => ({}) },
});

const DAY_NAMES = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

const search = ref('');
const stationFilter = ref(props.filters.station_id || '');
const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const processing = ref(false);
const errors = ref({});
const previewDays = ref([]);
const previewLoading = ref(false);
const previewOpen = ref(false);
const exceptionSchedule = ref(null);
const exceptionForm = ref({ service_date: '', type: 'cancelled', replacement_time: '', replacement_capacity: '', reason: '' });
const exceptionErrors = ref({});

const form = ref(emptyForm());

function emptyForm() {
  return {
    station_id: '',
    route_id: '',
    origin_station_id: '',
    destination_station_id: '',
    departure_time: '08:00',
    days_of_week: [1, 2, 3, 4, 5],
    valid_from: new Date().toISOString().slice(0, 10),
    valid_until: '',
    timezone: 'Africa/Abidjan',
    planned_capacity: '',
    confirmed_return_quota: '',
    default_vehicle_type_id: '',
    vehicle_assignment_policy: '',
    booking_type: 'seat_assignment',
    sales_control: 'closed',
    allows_open_connections: true,
    automatic_connection_allocation: false,
    active: true,
  };
}

const filteredSchedules = computed(() => {
  const items = props.schedules?.data || [];
  if (!search.value) return items;
  const term = search.value.toLowerCase();
  return items.filter((s) =>
    (s.route_label || '').toLowerCase().includes(term) ||
    (s.display_label || '').toLowerCase().includes(term) ||
    (s.station?.name || '').toLowerCase().includes(term) ||
    (s.origin_station?.name || '').toLowerCase().includes(term) ||
    (s.destination_station?.name || '').toLowerCase().includes(term)
  );
});

const openStationAccordions = ref({});

const toggleStationAccordion = (stationId) => {
  openStationAccordions.value[stationId] = !openStationAccordions.value[stationId];
};

const stationAccordionsList = computed(() => {
  const allStations = sortedStations.value || [];
  const schedulesList = filteredSchedules.value || [];
  const stationsById = new Map(allStations.map((station) => [station.id, station]));
  const grouped = new Map();

  schedulesList.forEach((schedule) => {
    const stationId = schedule.station_id || schedule.station?.id;
    const groupId = stationId || 'unassigned';

    if (!grouped.has(groupId)) {
      grouped.set(groupId, {
        station: stationsById.get(stationId)
          || schedule.station
          || { id: 'unassigned', name: 'Non attribué à une gare', city: '' },
        schedules: [],
      });
    }

    grouped.get(groupId).schedules.push(schedule);
  });

  return Array.from(grouped.values())
    .filter((group) => !stationFilter.value || group.station.id === stationFilter.value)
    .sort((a, b) => {
      const countDifference = b.schedules.length - a.schedules.length;
      if (countDifference !== 0) return countDifference;
      return a.station.name.localeCompare(b.station.name, 'fr', { sensitivity: 'base' });
    });
});

const policyLabel = (policy) =>
  policy === 'allow_planned_capacity'
    ? 'Vente sur capacité prévue'
    : 'Car réel obligatoire';

const destinationLabel = (schedule) =>
  schedule.destination_station?.name
  || schedule.route?.destination_station?.name
  || 'Gare non renseignée';

const openCreate = () => {
  isEditing.value = false;
  editingId.value = null;
  errors.value = {};
  form.value = emptyForm();
  showModal.value = true;
};

const openCreateForStation = (stationId) => {
  isEditing.value = false;
  editingId.value = null;
  errors.value = {};
  form.value = emptyForm();
  if (stationId && stationId !== 'unassigned') {
    form.value.station_id = stationId;
    form.value.origin_station_id = stationId;
  }
  showModal.value = true;
};

const openEdit = (schedule) => {
  isEditing.value = true;
  editingId.value = schedule.id;
  errors.value = {};
  form.value = {
    station_id: schedule.station_id,
    route_id: schedule.route_id,
    origin_station_id: schedule.origin_station_id,
    destination_station_id: schedule.destination_station_id,
    departure_time: schedule.departure_time?.slice(0, 5) || '08:00',
    days_of_week: [...(schedule.days_of_week || [])],
    valid_from: String(schedule.valid_from || '').slice(0, 10),
    valid_until: String(schedule.valid_until || '').slice(0, 10),
    timezone: schedule.timezone || 'Africa/Abidjan',
    planned_capacity: schedule.planned_capacity ?? '',
    confirmed_return_quota: schedule.confirmed_return_quota ?? '',
    default_vehicle_type_id: schedule.default_vehicle_type_id,
    vehicle_assignment_policy: schedule.vehicle_assignment_policy || '',
    booking_type: schedule.booking_type || 'seat_assignment',
    sales_control: schedule.sales_control || 'open',
    allows_open_connections: Boolean(schedule.allows_open_connections),
    automatic_connection_allocation: Boolean(schedule.automatic_connection_allocation),
    active: Boolean(schedule.active),
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const payload = {
    ...form.value,
    planned_capacity: form.value.planned_capacity === '' ? null : Number(form.value.planned_capacity),
    confirmed_return_quota: form.value.confirmed_return_quota === '' ? null : Number(form.value.confirmed_return_quota),
    vehicle_assignment_policy: form.value.vehicle_assignment_policy || null,
  };

  const options = {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success(isEditing.value ? 'Programme mis à jour.' : 'Programme créé.');
      closeModal();
      processing.value = false;
    },
    onError: (err) => {
      errors.value = err;
      processing.value = false;
    },
  };

  if (isEditing.value) {
    router.put(route('admin.departure-schedules.update', editingId.value), payload, options);
  } else {
    router.post(route('admin.departure-schedules.store'), payload, options);
  }
};

const confirmDelete = async (schedule) => {
  const ok = await confirmationStore.confirm({
    title: 'Supprimer ce programme de départ ?',
    message: `Le programme « ${schedule.display_label} » (${schedule.route_label}) sera supprimé. Les voyages déjà matérialisés conservent leur historique.`,
    confirmLabel: 'Supprimer',
    tone: 'danger',
  });
  if (!ok) return;

  router.delete(route('admin.departure-schedules.destroy', schedule.id), {
    preserveScroll: true,
    onSuccess: () => toastStore.success('Programme supprimé.'),
  });
};

const applyStationFilter = () => {
  router.get(route('admin.departure-schedules.index'), { station_id: stationFilter.value || undefined }, {
    preserveState: true,
    replace: true,
  });
};

const loadPreview = async () => {
  previewLoading.value = true;
  previewDays.value = [];
  try {
    const response = await fetch(route('admin.departure-schedules.preview'));
    const data = await response.json();
    previewDays.value = data.occurrences || [];
    previewOpen.value = true;
  } catch (e) {
    toastStore.error('Impossible de charger l’aperçu des départs.');
  } finally {
    previewLoading.value = false;
  }
};

const openExceptions = (schedule) => {
  exceptionSchedule.value = schedule;
  exceptionForm.value = { service_date: '', type: 'cancelled', replacement_time: '', replacement_capacity: '', reason: '' };
  exceptionErrors.value = {};
};

const closeExceptions = () => {
  exceptionSchedule.value = null;
};

const submitException = () => {
  if (!exceptionSchedule.value) return;
  processing.value = true;
  exceptionErrors.value = {};

  router.post(route('admin.departure-schedules.exceptions.store', exceptionSchedule.value.id), {
    ...exceptionForm.value,
    replacement_time: exceptionForm.value.replacement_time || null,
    replacement_capacity: exceptionForm.value.replacement_capacity === '' ? null : Number(exceptionForm.value.replacement_capacity),
  }, {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success('Exception calendaire enregistrée.');
      processing.value = false;
    },
    onError: (err) => {
      exceptionErrors.value = err;
      processing.value = false;
    },
  });
};

const confirmDeleteException = async (exception) => {
  if (!exceptionSchedule.value) return;
  const ok = await confirmationStore.confirm({
    title: 'Supprimer cette exception ?',
    message: `L’exception du ${exception.service_date} sera supprimée.`,
    confirmLabel: 'Supprimer',
    tone: 'danger',
  });
  if (!ok) return;

  router.delete(route('admin.departure-schedules.exceptions.destroy', [exceptionSchedule.value.id, exception.id]), {
    preserveScroll: true,
    onSuccess: () => toastStore.success('Exception supprimée.'),
  });
};

const typeLabel = (type) => ({
  cancelled: 'Annulé',
  time_changed: 'Horaire modifié',
  suspended: 'Suspendu',
  capacity_changed: 'Capacité modifiée',
}[type] || type);

const sortedStations = computed(() =>
  [...props.stations].sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }))
);
const sortedRoutes = computed(() =>
  [...props.routes].sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }))
);
const sortedVehicleTypes = computed(() =>
  [...props.vehicleTypes].sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }))
);
</script>

<template>
  <MainNavLayout>
    <div class="flex min-h-screen">
      <SettingsMenu :stats="stats" />

      <div class="flex-1 overflow-x-auto p-6">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900 dark:text-slate-100">
              <span class="rounded-xl bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
                <CalendarClock :size="22" />
              </span>
              Programmes de départ
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
              Règles de départ théoriques par gare. Les voyages datés sont matérialisés chaque nuit par la fenêtre opérationnelle.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <SecondaryButton @click="loadPreview" :disabled="previewLoading">
              <BusClock :size="18" class="mr-1.5 text-emerald-500" />
              {{ previewLoading ? 'Chargement…' : 'Aperçu prochain jour' }}
            </SecondaryButton>
            <PrimaryButton @click="openCreate">
              <Plus :size="18" class="mr-1.5" />
              Nouveau programme
            </PrimaryButton>
          </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3">
          <div class="relative w-full max-w-xs">
            <Magnify class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-emerald-500 dark:text-emerald-400" :size="18" />
            <TextInput
              v-model="search"
              type="text"
              placeholder="Rechercher un programme…"
              class="pl-9"
            />
          </div>
          <SelectBox
            v-model="stationFilter"
            :options="sortedStations"
            labelKey="name"
            placeholder="Toutes les gares"
            show-all-option
            class="w-56"
            @update:model-value="applyStationFilter"
          />
          <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            Politique par défaut : {{ policyLabel(companyDefaultPolicy) }}
          </span>
        </div>

        <!-- Aperçu prochain jour -->
        <div v-if="previewOpen" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-emerald-800 dark:text-emerald-300">
              <CalendarPlus :size="18" />
              Occurrences à matérialiser pour la prochaine journée opérationnelle
            </h2>
            <button class="rounded-xl p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" @click="previewOpen = false" title="Fermer">
              ✕
            </button>
          </div>
          <div v-if="previewDays.length === 0" class="text-sm text-emerald-700 dark:text-emerald-400">
            Aucune occurrence à matérialiser.
          </div>
          <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="(occ, i) in previewDays"
              :key="i"
              class="flex items-center justify-between rounded-xl border border-emerald-200 bg-white px-3 py-2 text-sm dark:border-emerald-900 dark:bg-slate-900"
            >
              <div>
                <div class="font-medium text-slate-800 dark:text-slate-200">{{ occ.time }} — {{ occ.route_label }}</div>
                <div class="text-xs text-slate-500">{{ occ.schedule_label }}</div>
              </div>
              <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">
                {{ occ.capacity ? occ.capacity + ' places' : 'Capacité —' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Liste par gares sous forme d'accordéons collapsibles -->
        <div v-if="stationAccordionsList.length === 0" class="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <EmptyState
            title="Aucun programme de départ"
            description="Créez un premier programme pour qu’un voyage soit matérialisé chaque nuit."
          />
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="group in stationAccordionsList"
            :key="group.station.id"
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
          >
            <!-- Accordion Header -->
            <div
              @click="toggleStationAccordion(group.station.id)"
              class="flex cursor-pointer items-center justify-between border-b border-slate-100 bg-slate-50/80 px-4 py-3.5 transition hover:bg-slate-100/80 dark:border-slate-800 dark:bg-slate-950/60 dark:hover:bg-slate-900"
            >
              <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                  <OfficeBuilding :size="20" />
                </span>
                <div>
                  <h3 class="font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                    {{ group.station.name }}
                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-black text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                      {{ group.schedules.length }} {{ group.schedules.length > 1 ? 'programmes' : 'programme' }}
                    </span>
                  </h3>
                  <p v-if="group.station.city" class="text-xs text-slate-500 dark:text-slate-400">{{ group.station.city }}</p>
                </div>
              </div>

              <div class="flex items-center gap-3" @click.stop>
                <button
                  v-if="group.station.id !== 'unassigned'"
                  @click="openCreateForStation(group.station.id)"
                  class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700"
                  title="Ajouter directement un programme pour cette gare"
                >
                  <Plus :size="15" /> Nouveau programme
                </button>
                <button
                  @click="toggleStationAccordion(group.station.id)"
                  class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                >
                  <ChevronDown v-if="openStationAccordions[group.station.id]" :size="20" />
                  <ChevronRight v-else :size="20" />
                </button>
              </div>
            </div>

            <!-- Accordion Content Table -->
            <div v-if="openStationAccordions[group.station.id]">
              <div v-if="group.schedules.length === 0" class="p-6 text-center text-sm text-slate-400">
                Aucun programme de départ configuré pour cette gare.
                <button v-if="group.station.id !== 'unassigned'" @click="openCreateForStation(group.station.id)" class="ml-1 font-bold text-emerald-600 hover:underline">
                  Ajouter le premier programme
                </button>
              </div>
              <table v-else class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50/50 dark:bg-slate-950/50">
                  <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <th class="px-4 py-3">Horaire</th>
                    <th class="px-4 py-3">Destination</th>
                    <th class="px-4 py-3">Jours de circulation</th>
                    <th class="px-4 py-3">Capacité</th>
                    <th class="px-4 py-3">Condition de vente</th>
                    <th class="px-4 py-3">État</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                  <tr
                    v-for="schedule in group.schedules"
                    :key="schedule.id"
                    class="text-sm text-slate-700 transition hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800/40"
                  >
                    <td class="px-4 py-3 font-semibold text-slate-900 dark:text-slate-100">
                      {{ schedule.departure_time?.slice(0, 5) }}
                    </td>
                    <td class="px-4 py-3 font-semibold text-slate-900 dark:text-slate-100">
                      → {{ destinationLabel(schedule) }}
                    </td>
                    <td class="px-4 py-3 text-xs">
                      <div class="flex gap-1">
                        <span
                          v-for="(day, idx) in DAY_NAMES"
                          :key="idx"
                          class="flex h-6 w-6 items-center justify-center rounded-md text-[10px] font-medium"
                          :class="(schedule.days_of_week || []).includes(idx + 1)
                            ? 'bg-emerald-500 text-white'
                            : 'bg-slate-100 text-slate-400 dark:bg-slate-800'"
                        >
                          {{ day[0] }}
                        </span>
                      </div>
                    </td>
                    <td class="px-4 py-3 font-bold">{{ schedule.planned_capacity ?? '—' }}</td>
                    <td class="px-4 py-3">
                      <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="schedule.vehicle_assignment_policy === 'allow_planned_capacity'
                          ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300'
                          : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                      >
                        {{ policyLabel(schedule.vehicle_assignment_policy || companyDefaultPolicy) }}
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="schedule.active
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'
                          : 'bg-slate-100 text-slate-500 dark:bg-slate-800'"
                      >
                        <Check v-if="schedule.active" :size="12" />
                        <AlertCircle v-else :size="12" />
                        {{ schedule.active ? 'Actif' : 'Inactif' }}
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <div class="flex items-center justify-end gap-1">
                        <button
                          class="rounded-xl p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                          title="Exceptions calendaires"
                          @click="openExceptions(schedule)"
                        >
                          <CalendarPlus :size="18" />
                        </button>
                        <button
                          class="rounded-xl p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-emerald-600 dark:hover:bg-slate-800"
                          title="Modifier"
                          @click="openEdit(schedule)"
                        >
                          <Pencil :size="18" />
                        </button>
                        <button
                          class="rounded-xl p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-red-600 dark:hover:bg-slate-800"
                          title="Supprimer"
                          @click="confirmDelete(schedule)"
                        >
                          <Delete :size="18" />
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Formulaire partagé entre cette page et la fiche d'une gare -->
    <DepartureScheduleFormModal
      :show="showModal"
      :title="isEditing ? 'Modifier le programme de départ' : 'Nouveau programme de départ'"
      :submit-label="isEditing ? 'Enregistrer' : 'Créer le programme'"
      :form="form"
      :errors="errors"
      :processing="processing"
      :stations="sortedStations"
      :routes="sortedRoutes"
      :vehicle-types="sortedVehicleTypes"
      :company-default-policy="companyDefaultPolicy"
      @close="closeModal"
      @submit="submit"
    />

    <!-- Exceptions calendaires -->
    <DialogModal :show="Boolean(exceptionSchedule)" max-width="2xl" @close="closeExceptions">
      <template #title>
        Exceptions — {{ exceptionSchedule?.display_label }}
      </template>
      <template #content>
        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <InputLabel value="Date de service" />
            <AppDatePicker v-model="exceptionForm.service_date" :clearable="false" class="mt-1" />
            <InputError :message="exceptionErrors.service_date" class="mt-1" />
          </div>
          <div>
            <InputLabel value="Type" />
            <SelectBox
              v-model="exceptionForm.type"
              :options="[
                { id: 'cancelled', name: 'Annulé' },
                { id: 'suspended', name: 'Suspendu' },
                { id: 'time_changed', name: 'Horaire modifié' },
                { id: 'capacity_changed', name: 'Capacité modifiée' },
              ]"
              label-key="name"
              class="mt-1"
            />
            <InputError :message="exceptionErrors.type" class="mt-1" />
          </div>
          <div v-if="exceptionForm.type === 'time_changed'">
            <InputLabel value="Nouvel horaire" />
            <TextInput v-model="exceptionForm.replacement_time" type="time" class="mt-1" />
            <InputError :message="exceptionErrors.replacement_time" class="mt-1" />
          </div>
          <div v-if="exceptionForm.type === 'capacity_changed'">
            <InputLabel value="Nouvelle capacité" />
            <TextInput v-model="exceptionForm.replacement_capacity" type="number" min="1" class="mt-1" />
            <InputError :message="exceptionErrors.replacement_capacity" class="mt-1" />
          </div>
          <div class="sm:col-span-2">
            <InputLabel value="Motif (optionnel)" />
            <TextInput v-model="exceptionForm.reason" type="text" class="mt-1" />
          </div>
          <div class="sm:col-span-2">
            <PrimaryButton :disabled="processing" @click="submitException">
              Enregistrer l’exception
            </PrimaryButton>
          </div>
        </div>

        <div v-if="(exceptionSchedule?.exceptions || []).length === 0" class="text-sm text-slate-500">
          Aucune exception enregistrée.
        </div>
        <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
          <div
            v-for="exc in exceptionSchedule.exceptions"
            :key="exc.id"
            class="flex items-center justify-between py-2 text-sm"
          >
            <div>
              <span class="font-medium text-slate-800 dark:text-slate-200">{{ exc.service_date }}</span>
              <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                {{ typeLabel(exc.type) }}
              </span>
              <span v-if="exc.replacement_time" class="ml-2 text-xs text-slate-500">{{ exc.replacement_time?.slice(0, 5) }}</span>
              <span v-if="exc.replacement_capacity" class="ml-2 text-xs text-slate-500">{{ exc.replacement_capacity }} places</span>
              <span v-if="exc.reason" class="ml-2 text-xs italic text-slate-400">{{ exc.reason }}</span>
            </div>
            <button
              class="rounded-xl p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-red-600 dark:hover:bg-slate-800"
              title="Supprimer l’exception"
              @click="confirmDeleteException(exc)"
            >
              <Delete :size="16" />
            </button>
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeExceptions">Fermer</SecondaryButton>
      </template>
    </DialogModal>
  </MainNavLayout>
</template>
