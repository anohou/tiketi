<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import Check from 'vue-material-design-icons/Check.vue';
import ArrowCollapse from 'vue-material-design-icons/ArrowCollapse.vue';
import ArrowExpand from 'vue-material-design-icons/ArrowExpand.vue';
import Fullscreen from 'vue-material-design-icons/Fullscreen.vue';
import FullscreenExit from 'vue-material-design-icons/FullscreenExit.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const props = defineProps({
  fares: { type: Array, default: () => [] },
  stations: { type: Array, default: () => [] },
});

const { exportToExcel, printList } = useExportPrint();
const stationFilter = ref('');
const newFareBidirectional = ref(true);
const cellDrafts = ref({});
const savingCells = ref({});
const savedCells = ref({});
const cellErrors = ref({});
const savedTimers = new Map();
const workspaceExpanded = ref(false);
const isBrowserFullscreen = ref(false);
const hoveredOriginId = ref(null);
const hoveredDestinationId = ref(null);

const setHoveredCell = (originId, destinationId) => {
  hoveredOriginId.value = originId;
  hoveredDestinationId.value = destinationId;
};

const activeTwinKey = computed(() => {
  if (!hoveredOriginId.value || !hoveredDestinationId.value) return null;
  if (hoveredOriginId.value === hoveredDestinationId.value) return null;

  const cell = getCellFare(hoveredOriginId.value, hoveredDestinationId.value);
  if (cell) {
    if (cell.fare.is_bidirectional || cell.mirrored) {
      return cellKey(hoveredDestinationId.value, hoveredOriginId.value);
    }
  } else if (newFareBidirectional.value) {
    return cellKey(hoveredDestinationId.value, hoveredOriginId.value);
  }
  return null;
});

const getFullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

const syncFullscreenState = () => {
  isBrowserFullscreen.value = Boolean(getFullscreenElement());
};

const toggleBrowserFullscreen = async () => {
  try {
    const fullscreenElement = getFullscreenElement();
    if (fullscreenElement) {
      const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
      await exitFullscreen?.call(document);
      return;
    }

    workspaceExpanded.value = true;
    const root = document.documentElement;
    const requestFullscreen = root.requestFullscreen || root.webkitRequestFullscreen;
    await requestFullscreen?.call(root);
  } catch (error) {
    console.error('Impossible de modifier le mode plein écran.', error);
  }
};

onMounted(() => {
  syncFullscreenState();
  document.addEventListener('fullscreenchange', syncFullscreenState);
  document.addEventListener('webkitfullscreenchange', syncFullscreenState);
});

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', syncFullscreenState);
  document.removeEventListener('webkitfullscreenchange', syncFullscreenState);
});

const sortedStations = computed(() => [...props.stations].sort((a, b) =>
  a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' })
));

const matrixOrigins = computed(() => {
  if (!stationFilter.value) return sortedStations.value;
  return sortedStations.value.filter((station) => station.id === stationFilter.value);
});

const matrixDestinations = computed(() => sortedStations.value);
const directFareMap = computed(() => new Map(
  props.fares.map((fare) => [`${fare.from_station_id}:${fare.to_station_id}`, fare])
));

const cellKey = (fromId, toId) => `${fromId}:${toId}`;

const getCellFare = (fromId, toId) => {
  const direct = directFareMap.value.get(cellKey(fromId, toId));
  if (direct) return { fare: direct, mirrored: false };

  const reverse = directFareMap.value.get(cellKey(toId, fromId));
  if (reverse?.is_bidirectional) return { fare: reverse, mirrored: true };

  return null;
};

const syncDrafts = () => {
  const next = {};
  sortedStations.value.forEach((origin) => {
    sortedStations.value.forEach((destination) => {
      if (origin.id === destination.id) return;
      const key = cellKey(origin.id, destination.id);
      const cell = getCellFare(origin.id, destination.id);
      if (cell) next[key] = String(cell.fare.amount);
      else next[key] = '';
    });
  });
  cellDrafts.value = next;
};

watch([() => props.fares, sortedStations], syncDrafts, { immediate: true, deep: true });

const setSaving = (key, value, reverseKey = null) => {
  const next = { ...savingCells.value, [key]: value };
  if (reverseKey) next[reverseKey] = value;
  savingCells.value = next;
};

const setError = (key, message = '') => {
  cellErrors.value = { ...cellErrors.value, [key]: message };
};

const markSaved = (key, reverseKey = null) => {
  const next = { ...savedCells.value, [key]: true };
  if (reverseKey) next[reverseKey] = true;
  savedCells.value = next;

  if (savedTimers.has(key)) window.clearTimeout(savedTimers.get(key));
  if (reverseKey && savedTimers.has(reverseKey)) window.clearTimeout(savedTimers.get(reverseKey));

  const timer = window.setTimeout(() => {
    const current = { ...savedCells.value };
    delete current[key];
    if (reverseKey) delete current[reverseKey];
    savedCells.value = current;
    savedTimers.delete(key);
    if (reverseKey) savedTimers.delete(reverseKey);
  }, 1600);

  savedTimers.set(key, timer);
  if (reverseKey) savedTimers.set(reverseKey, timer);
};

const farePayload = (fare, overrides = {}) => ({
  from_station_id: fare.from_station_id,
  to_station_id: fare.to_station_id,
  amount: Number(fare.amount),
  is_bidirectional: Boolean(fare.is_bidirectional),
  active: fare.active !== false,
  ...overrides,
});

const requestOptions = (key, reverseKey = null, onSuccess = null) => ({
  preserveScroll: true,
  preserveState: true,
  only: ['fares'],
  onSuccess: () => {
    setError(key);
    if (reverseKey) setError(reverseKey);
    markSaved(key, reverseKey);
    onSuccess?.();
  },
  onError: (errors) => {
    const message = errors.amount || errors.from_station_id || errors.to_station_id || 'Enregistrement impossible.';
    setError(key, message);
    if (reverseKey) setError(reverseKey, message);
  },
  onFinish: () => setSaving(key, false, reverseKey),
});

const saveCell = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const reverseKey = cellKey(destination.id, origin.id);
  if (savingCells.value[key]) return;

  const raw = String(cellDrafts.value[key] ?? '').trim();
  const cell = getCellFare(origin.id, destination.id);

  if (raw === '') {
    if (cell) cellDrafts.value[key] = String(cell.fare.amount);
    setError(key);
    return;
  }

  const amount = Number(raw);
  if (!Number.isInteger(amount) || amount < 0) {
    setError(key, 'Saisissez un montant entier positif.');
    return;
  }
  if (cell && amount === Number(cell.fare.amount)) return;

  const isBidi = cell ? Boolean(cell.fare.is_bidirectional) : newFareBidirectional.value;
  const targetReverseKey = isBidi ? reverseKey : null;

  setSaving(key, true, targetReverseKey);
  setError(key);
  if (targetReverseKey) setError(targetReverseKey);

  if (cell) {
    router.put(
      route('admin.route-fares.update', cell.fare.id),
      farePayload(cell.fare, { amount }),
      requestOptions(key, targetReverseKey)
    );
    return;
  }

  router.post(
    route('admin.route-fares.store'),
    {
      from_station_id: origin.id,
      to_station_id: destination.id,
      amount,
      is_bidirectional: newFareBidirectional.value,
      active: true,
    },
    requestOptions(key, targetReverseKey)
  );
};

const toggleDirection = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const reverseKey = cellKey(destination.id, origin.id);
  const cell = getCellFare(origin.id, destination.id);
  if (!cell || savingCells.value[key]) return;

  setSaving(key, true, reverseKey);
  router.put(
    route('admin.route-fares.update', cell.fare.id),
    farePayload(cell.fare, { is_bidirectional: !cell.fare.is_bidirectional }),
    requestOptions(key, reverseKey)
  );
};

const toggleActive = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const cell = getCellFare(origin.id, destination.id);
  if (!cell || savingCells.value[key]) return;

  setSaving(key, true);
  router.put(
    route('admin.route-fares.update', cell.fare.id),
    farePayload(cell.fare, { active: cell.fare.active === false }),
    requestOptions(key)
  );
};

const deleteCell = async (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const reverseKey = cellKey(destination.id, origin.id);
  const cell = getCellFare(origin.id, destination.id);
  if (!cell || savingCells.value[key]) return;
  if (!await confirmationStore.confirm({ title: 'Supprimer ce tarif', message: `Supprimer le tarif ${cell.fare.from_station?.name} → ${cell.fare.to_station?.name} ?`, confirmLabel: 'Supprimer', tone: 'danger' })) return;

  const targetReverseKey = cell.fare.is_bidirectional ? reverseKey : null;

  setSaving(key, true, targetReverseKey);
  router.delete(route('admin.route-fares.destroy', cell.fare.id), {
    ...requestOptions(key, targetReverseKey, () => {
      cellDrafts.value[key] = '';
      if (cell.fare.is_bidirectional) {
        cellDrafts.value[reverseKey] = '';
      }
    }),
  });
};

const filteredFares = computed(() => {
  if (!stationFilter.value) return props.fares;
  return props.fares.filter((fare) =>
    fare.from_station_id === stationFilter.value || fare.to_station_id === stationFilter.value
  );
});

const configuredPairCount = computed(() => matrixOrigins.value.reduce((total, origin) => (
  total + matrixDestinations.value.reduce((originTotal, destination) => {
    if (origin.id === destination.id) return originTotal;
    return originTotal + (getCellFare(origin.id, destination.id) ? 1 : 0);
  }, 0)
), 0));
const possiblePairCount = computed(() => {
  const count = sortedStations.value.length;
  return stationFilter.value ? Math.max(0, count - 1) : count * (count - 1);
});

const fareColumns = {
  'from_station.name': 'Départ',
  'to_station.name': 'Arrivée',
  amount: 'Montant',
  is_bidirectional: 'Aller-retour',
  active: 'Statut',
};

const exportData = computed(() => filteredFares.value.map((fare) => ({
  ...fare,
  is_bidirectional: fare.is_bidirectional ? 'Oui' : 'Non',
  active: fare.active === false ? 'Inactif' : 'Actif',
})));

const handleExport = () => exportToExcel(exportData.value, fareColumns, 'matrice-tarifs');
const handlePrint = () => printList(exportData.value, fareColumns, 'Matrice des tarifs');
</script>

<template>
  <MainNavLayout
    :fullHeight="true"
    :focusMode="workspaceExpanded"
    :hideTripSidebar="true"
  >
    <div class="flex h-full w-full flex-col overflow-hidden">
      <div class="flex shrink-0 flex-col gap-4 px-6 pb-4 pt-6 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="flex items-center gap-3 text-3xl font-black text-slate-900 dark:text-slate-100">
            <span class="rounded-xl bg-emerald-100 p-2 dark:bg-emerald-950/50">
              <CashMultiple class="text-emerald-600 dark:text-emerald-400" :size="28" />
            </span>
            Matrice des tarifs
          </h1>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Cliquez dans une cellule pour créer ou modifier un tarif.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-emerald-300 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-emerald-700 dark:hover:text-emerald-400"
            :title="workspaceExpanded ? 'Afficher les menus' : 'Agrandir la matrice'"
            @click="workspaceExpanded = !workspaceExpanded"
          >
            <ArrowCollapse v-if="workspaceExpanded" :size="19" />
            <ArrowExpand v-else :size="19" />
          </button>
          <button
            type="button"
            class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-emerald-300 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-emerald-700 dark:hover:text-emerald-400"
            :title="isBrowserFullscreen ? 'Quitter le plein écran' : 'Plein écran navigateur'"
            @click="toggleBrowserFullscreen"
          >
            <FullscreenExit v-if="isBrowserFullscreen" :size="20" />
            <Fullscreen v-else :size="20" />
          </button>
          <ExportPrintButtons
            :disabled="filteredFares.length === 0"
            @export="handleExport"
            @print="handlePrint"
          />
        </div>
      </div>

      <div
        class="grid min-h-0 flex-1 grid-cols-12 gap-4 pb-6"
        :class="workspaceExpanded ? 'px-4' : 'px-6'"
      >
        <aside v-if="!workspaceExpanded" class="col-span-12 h-full overflow-y-auto pr-2 md:col-span-2">
          <SettingsMenu />
        </aside>

        <main
          class="col-span-12 flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
          :class="workspaceExpanded ? 'md:col-span-12' : 'md:col-span-10'"
        >
          <div class="flex shrink-0 flex-col gap-3 border-b border-slate-200 bg-slate-50/80 p-3 dark:border-slate-800 dark:bg-slate-950/60 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex flex-1 flex-wrap items-center gap-3">
              <label class="block min-w-44">
                <span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Filtrer par gare de départ</span>
                <select
                  v-model="stationFilter"
                  class="w-full rounded-xl border-slate-200 bg-white py-1.5 text-xs font-bold text-slate-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                >
                  <option value="">Toutes les gares</option>
                  <option v-for="station in sortedStations" :key="station.id" :value="station.id">
                    {{ station.name }}{{ station.city ? ` · ${station.city}` : '' }}
                  </option>
                </select>
              </label>

              <div>
                <span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Direction à la création</span>
                <div class="flex rounded-xl border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900">
                  <button
                    type="button"
                    class="rounded-lg px-2.5 py-1 text-xs font-black transition"
                    :class="newFareBidirectional ? 'bg-emerald-600 text-white shadow-sm ring-1 ring-emerald-200 dark:ring-emerald-800' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                    :aria-pressed="newFareBidirectional"
                    @click="newFareBidirectional = true"
                  >
                    ↔ Bidirectionnel
                  </button>
                  <button
                    type="button"
                    class="rounded-lg px-2.5 py-1 text-xs font-black transition"
                    :class="!newFareBidirectional ? 'bg-amber-400 text-slate-950 shadow-sm ring-1 ring-amber-200 dark:bg-amber-500 dark:ring-amber-800' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                    :aria-pressed="!newFareBidirectional"
                    @click="newFareBidirectional = false"
                  >
                    → Sens unique
                  </button>
                </div>
              </div>

              <div>
                <span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Relations configurées</span>
                <div class="flex h-[32px] items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                  <span class="text-xs font-black text-emerald-700 dark:text-emerald-400">{{ configuredPairCount }}</span>
                  <span v-if="possiblePairCount" class="text-[10px] font-bold text-slate-400">/ {{ possiblePairCount }} relations</span>
                </div>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-[10px] font-bold text-slate-500 dark:text-slate-400">
              <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-emerald-500"></i> Actif</span>
              <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-600"></i> À définir</span>
              <span>Entrée / Tab pour enregistrer</span>
            </div>
          </div>

          <div v-if="sortedStations.length < 2" class="flex flex-1 items-center justify-center p-8 text-center text-slate-500">
            Ajoutez au moins deux gares pour construire la matrice tarifaire.
          </div>

          <div v-else class="matrix-scroll flex-1 overflow-auto">
            <table class="w-full border-separate border-spacing-0 text-sm">
              <thead>
                <tr>
                  <th class="sticky left-0 top-0 z-30 min-w-[140px] w-36 border-b border-r border-slate-400 bg-slate-100 p-2 text-left dark:border-slate-600 dark:bg-slate-800">
                    <span class="text-[9px] font-black uppercase tracking-wider text-slate-500">Départ ↓ / Arrivée →</span>
                  </th>
                  <th
                    v-for="destination in matrixDestinations"
                    :key="destination.id"
                    class="sticky top-0 z-20 min-w-[125px] border-b border-r border-slate-400 p-1.5 text-left transition-colors cursor-pointer dark:border-slate-600 hover:bg-emerald-100 dark:hover:bg-emerald-900/80"
                    :class="hoveredDestinationId === destination.id ? 'bg-emerald-100 text-emerald-950 shadow-sm dark:bg-emerald-900/80 dark:text-emerald-100 ring-1 ring-emerald-400' : 'bg-slate-100 dark:bg-slate-800'"
                    @mouseenter="setHoveredCell(null, destination.id)"
                    @mouseleave="setHoveredCell(null, null)"
                  >
                    <div class="line-clamp-2 text-xs font-black leading-tight text-slate-800 dark:text-slate-100 break-words" :title="destination.name">{{ destination.name }}</div>
                    <div class="truncate text-[9px] font-medium text-slate-400">{{ destination.city || '—' }}</div>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="origin in matrixOrigins" :key="origin.id">
                  <th
                    class="sticky left-0 z-10 min-w-[140px] w-36 border-b border-r border-slate-300 p-2 text-left transition-colors cursor-pointer dark:border-slate-700 hover:bg-emerald-100 dark:hover:bg-emerald-900/80"
                    :class="hoveredOriginId === origin.id ? 'bg-emerald-100 text-emerald-950 shadow-sm dark:bg-emerald-900/80 dark:text-emerald-100 ring-1 ring-emerald-400' : 'bg-white dark:bg-slate-900'"
                    @mouseenter="setHoveredCell(origin.id, null)"
                    @mouseleave="setHoveredCell(null, null)"
                  >
                    <div class="line-clamp-2 text-xs font-black leading-tight text-slate-800 dark:text-slate-100 break-words" :title="origin.name">{{ origin.name }}</div>
                    <div class="truncate text-[9px] font-medium text-slate-400">{{ origin.city || '—' }}</div>
                  </th>

                  <td
                    v-for="destination in matrixDestinations"
                    :key="destination.id"
                    class="h-11 min-w-[125px] border-b border-r border-slate-300 p-1 align-middle transition-all dark:border-slate-700"
                    :class="[
                      origin.id === destination.id ? 'bg-slate-100/80 dark:bg-slate-950/70' : '',
                      hoveredOriginId && hoveredDestinationId && cellKey(origin.id, destination.id) === cellKey(hoveredOriginId, hoveredDestinationId)
                        ? 'ring-2 ring-emerald-500 bg-emerald-100/90 dark:bg-emerald-900/70 z-10 shadow-md scale-[1.02]'
                        : cellKey(origin.id, destination.id) === activeTwinKey
                          ? 'ring-2 ring-amber-400 bg-amber-100/90 dark:bg-amber-950/80 z-10 shadow-md animate-pulse'
                          : (origin.id === hoveredOriginId || destination.id === hoveredDestinationId)
                            ? 'bg-emerald-50/80 dark:bg-emerald-950/40'
                            : (origin.id !== destination.id ? 'bg-white dark:bg-slate-900' : ''),
                      savedCells[cellKey(origin.id, destination.id)] ? 'ring-2 ring-emerald-400 bg-emerald-100 dark:bg-emerald-900/80' : ''
                    ]"
                    @mouseenter="setHoveredCell(origin.id, destination.id)"
                    @mouseleave="setHoveredCell(null, null)"
                  >
                    <div v-if="origin.id === destination.id" class="flex h-full items-center justify-center text-sm font-light text-slate-300 dark:text-slate-700">—</div>

                    <div v-else class="flex h-full items-center gap-1">
                      <!-- Direction toggle/indicator -->
                      <button
                        v-if="getCellFare(origin.id, destination.id)"
                        type="button"
                        class="flex h-6 w-5 shrink-0 items-center justify-center rounded text-[11px] font-black transition"
                        :class="getCellFare(origin.id, destination.id).fare.is_bidirectional ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-950/70 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'"
                        :title="getCellFare(origin.id, destination.id).fare.is_bidirectional ? 'Tarif bidirectionnel (↔) - Cliquer pour rendre sens unique' : 'Tarif sens unique (→) - Cliquer pour rendre bidirectionnel'"
                        @click="toggleDirection(origin, destination)"
                      >
                        {{ getCellFare(origin.id, destination.id).fare.is_bidirectional ? '↔' : '→' }}
                      </button>
                      <span
                        v-else
                        class="flex h-6 w-5 shrink-0 items-center justify-center text-[11px] font-black text-slate-300 dark:text-slate-600 select-none"
                        :title="newFareBidirectional ? 'Sera créé en tarif bidirectionnel (↔)' : 'Sera créé en tarif sens unique (→)'"
                      >
                        {{ newFareBidirectional ? '↔' : '→' }}
                      </span>

                      <!-- Input amount -->
                      <div class="relative min-w-0 flex-1">
                        <input
                          v-model="cellDrafts[cellKey(origin.id, destination.id)]"
                          type="text"
                          inputmode="numeric"
                          pattern="[0-9]*"
                          placeholder="Prix"
                          class="w-full rounded border px-1.5 py-0.5 text-right text-xs font-black focus:ring-1"
                          :class="[
                            getCellFare(origin.id, destination.id)
                              ? getCellFare(origin.id, destination.id)?.mirrored
                                ? 'border-emerald-300 bg-emerald-50/90 text-emerald-900 focus:border-emerald-500 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                : 'border-emerald-200 bg-emerald-50/60 text-emerald-800 focus:border-emerald-500 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-300'
                              : 'border-dashed border-slate-300 bg-slate-50 text-slate-700 focus:border-emerald-500 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200',
                            getCellFare(origin.id, destination.id)?.fare.active === false ? 'opacity-50' : '',
                            cellErrors[cellKey(origin.id, destination.id)] ? 'border-rose-500 bg-rose-50 text-rose-800' : ''
                          ]"
                          :title="getCellFare(origin.id, destination.id)?.mirrored ? 'Tarif repris du sens inverse' : cellErrors[cellKey(origin.id, destination.id)] || ''"
                          @focus="$event.target.select()"
                          @blur="saveCell(origin, destination)"
                          @keydown.enter.prevent="$event.target.blur()"
                        />
                      </div>

                      <!-- Action button (Delete) -->
                      <div v-if="getCellFare(origin.id, destination.id)" class="flex shrink-0 items-center">
                        <button
                          type="button"
                          class="rounded p-0.5 text-slate-300 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30"
                          title="Supprimer le tarif"
                          @click="deleteCell(origin, destination)"
                        >
                          <Trash2 :size="13" />
                        </button>
                      </div>

                      <!-- Saving status -->
                      <div class="flex shrink-0 items-center">
                        <Refresh v-if="savingCells[cellKey(origin.id, destination.id)]" :size="12" class="animate-spin text-emerald-600" />
                        <Check v-else-if="savedCells[cellKey(origin.id, destination.id)]" :size="12" class="text-emerald-600" />
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="h-5" aria-hidden="true"></div>
          </div>
        </main>
      </div>
    </div>
  </MainNavLayout>
</template>

<style scoped>
.matrix-scroll {
  scrollbar-gutter: stable;
  scrollbar-color: #94a3b8 transparent;
  scrollbar-width: thin;
}

.matrix-scroll::-webkit-scrollbar {
  width: 10px;
  height: 10px;
}

.matrix-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.matrix-scroll::-webkit-scrollbar-thumb {
  border: 2px solid transparent;
  border-radius: 999px;
  background: #94a3b8;
  background-clip: padding-box;
}
</style>
