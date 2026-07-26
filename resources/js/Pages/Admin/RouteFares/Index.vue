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

const setSaving = (key, value) => {
  savingCells.value = { ...savingCells.value, [key]: value };
};

const setError = (key, message = '') => {
  cellErrors.value = { ...cellErrors.value, [key]: message };
};

const markSaved = (key) => {
  savedCells.value = { ...savedCells.value, [key]: true };
  if (savedTimers.has(key)) window.clearTimeout(savedTimers.get(key));
  savedTimers.set(key, window.setTimeout(() => {
    const next = { ...savedCells.value };
    delete next[key];
    savedCells.value = next;
    savedTimers.delete(key);
  }, 1600));
};

const farePayload = (fare, overrides = {}) => ({
  from_station_id: fare.from_station_id,
  to_station_id: fare.to_station_id,
  amount: Number(fare.amount),
  is_bidirectional: Boolean(fare.is_bidirectional),
  active: fare.active !== false,
  ...overrides,
});

const requestOptions = (key, onSuccess) => ({
  preserveScroll: true,
  preserveState: true,
  only: ['fares'],
  onSuccess: () => {
    setError(key);
    markSaved(key);
    onSuccess?.();
  },
  onError: (errors) => {
    setError(key, errors.amount || errors.from_station_id || errors.to_station_id || 'Enregistrement impossible.');
  },
  onFinish: () => setSaving(key, false),
});

const saveCell = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
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

  setSaving(key, true);
  setError(key);

  if (cell) {
    router.put(
      route('admin.route-fares.update', cell.fare.id),
      farePayload(cell.fare, { amount }),
      requestOptions(key)
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
    requestOptions(key)
  );
};

const toggleDirection = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const cell = getCellFare(origin.id, destination.id);
  if (!cell || savingCells.value[key]) return;

  setSaving(key, true);
  router.put(
    route('admin.route-fares.update', cell.fare.id),
    farePayload(cell.fare, { is_bidirectional: !cell.fare.is_bidirectional }),
    requestOptions(key)
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

const deleteCell = (origin, destination) => {
  const key = cellKey(origin.id, destination.id);
  const cell = getCellFare(origin.id, destination.id);
  if (!cell || savingCells.value[key]) return;
  if (!window.confirm(`Supprimer le tarif ${cell.fare.from_station?.name} → ${cell.fare.to_station?.name} ?`)) return;

  setSaving(key, true);
  router.delete(route('admin.route-fares.destroy', cell.fare.id), {
    ...requestOptions(key, () => {
      cellDrafts.value[key] = '';
      if (cell.fare.is_bidirectional) {
        cellDrafts.value[cellKey(destination.id, origin.id)] = '';
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
          <div class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-right shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-lg font-black text-emerald-700 dark:text-emerald-400">{{ configuredPairCount }}</div>
            <div class="text-[9px] font-bold uppercase tracking-wider text-slate-400">
              relations configurées<span v-if="possiblePairCount"> / {{ possiblePairCount }} relations</span>
            </div>
          </div>
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
          <div class="flex shrink-0 flex-col gap-3 border-b border-slate-200 bg-slate-50/80 p-4 dark:border-slate-800 dark:bg-slate-950/60 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Filtrer par gare de départ</span>
                <select
                  v-model="stationFilter"
                  class="w-full rounded-xl border-slate-200 bg-white text-sm font-bold text-slate-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                >
                  <option value="">Toutes les gares</option>
                  <option v-for="station in sortedStations" :key="station.id" :value="station.id">
                    {{ station.name }}{{ station.city ? ` · ${station.city}` : '' }}
                  </option>
                </select>
              </label>

              <div>
                <span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Direction à la création</span>
                <div class="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">
                  <button
                    type="button"
                    class="flex-1 rounded-lg px-3 py-2 text-xs font-black transition"
                    :class="newFareBidirectional ? 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-200 dark:ring-emerald-800' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                    :aria-pressed="newFareBidirectional"
                    @click="newFareBidirectional = true"
                  >
                    ↔ Bidirectionnel
                  </button>
                  <button
                    type="button"
                    class="flex-1 rounded-lg px-3 py-2 text-xs font-black transition"
                    :class="!newFareBidirectional ? 'bg-amber-400 text-slate-950 shadow-sm ring-2 ring-amber-200 dark:bg-amber-500 dark:ring-amber-800' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                    :aria-pressed="!newFareBidirectional"
                    @click="newFareBidirectional = false"
                  >
                    → Sens unique
                  </button>
                </div>
                <p class="mt-1 text-[9px] font-bold" :class="newFareBidirectional ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'">
                  Prochaine cellule : {{ newFareBidirectional ? 'tarif valable dans les deux sens' : 'tarif valable dans un seul sens' }}
                </p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-[10px] font-bold text-slate-500 dark:text-slate-400">
              <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-emerald-500"></i> Actif</span>
              <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-slate-300 dark:bg-slate-600"></i> À définir</span>
              <span>Entrée ou Tab pour enregistrer</span>
            </div>
          </div>

          <div v-if="sortedStations.length < 2" class="flex flex-1 items-center justify-center p-8 text-center text-slate-500">
            Ajoutez au moins deux gares pour construire la matrice tarifaire.
          </div>

          <div v-else class="matrix-scroll flex-1 overflow-auto">
            <table class="min-w-max border-separate border-spacing-0 text-sm">
              <thead>
                <tr>
                  <th class="sticky left-0 top-0 z-30 min-w-40 border-b border-r border-slate-400 bg-slate-100 p-2 text-left dark:border-slate-600 dark:bg-slate-800">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">Départ ↓ / Destination →</span>
                  </th>
                  <th
                    v-for="destination in matrixDestinations"
                    :key="destination.id"
                    class="sticky top-0 z-20 min-w-36 border-b border-r border-slate-400 bg-slate-100 p-2 text-left dark:border-slate-600 dark:bg-slate-800"
                  >
                    <div class="max-w-32 truncate text-xs font-black text-slate-800 dark:text-slate-100" :title="destination.name">{{ destination.name }}</div>
                    <div class="max-w-32 truncate text-[9px] font-medium text-slate-400">{{ destination.city || '—' }}</div>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="origin in matrixOrigins" :key="origin.id">
                  <th class="sticky left-0 z-10 border-b border-r border-slate-300 bg-white p-2 text-left dark:border-slate-700 dark:bg-slate-900">
                    <div class="max-w-36 truncate text-xs font-black text-slate-800 dark:text-slate-100" :title="origin.name">{{ origin.name }}</div>
                    <div class="max-w-36 truncate text-[9px] font-medium text-slate-400">{{ origin.city || '—' }}</div>
                  </th>

                  <td
                    v-for="destination in matrixDestinations"
                    :key="destination.id"
                    class="h-20 min-w-36 border-b border-r border-slate-300 p-1 align-top dark:border-slate-700"
                    :class="origin.id === destination.id ? 'bg-slate-100/80 dark:bg-slate-950/70' : 'bg-white dark:bg-slate-900'"
                  >
                    <div v-if="origin.id === destination.id" class="flex h-full items-center justify-center text-2xl font-light text-slate-300 dark:text-slate-700">—</div>

                    <div v-else class="flex h-full flex-col">
                      <div class="relative">
                        <input
                          v-model="cellDrafts[cellKey(origin.id, destination.id)]"
                          type="number"
                          min="0"
                          step="100"
                          placeholder="Ajouter"
                          class="w-full rounded-md border px-2 py-1 pr-9 text-right text-xs font-black focus:ring-2"
                          :class="[
                            getCellFare(origin.id, destination.id)
                              ? 'border-emerald-200 bg-emerald-50/60 text-emerald-800 focus:border-emerald-500 focus:ring-emerald-200 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-300'
                              : 'border-dashed border-slate-300 bg-slate-50 text-slate-700 focus:border-emerald-500 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200',
                            getCellFare(origin.id, destination.id)?.fare.active === false ? 'opacity-55' : ''
                          ]"
                          @focus="$event.target.select()"
                          @blur="saveCell(origin, destination)"
                          @keydown.enter.prevent="$event.target.blur()"
                        />
                        <span class="pointer-events-none absolute right-2 top-1.5 text-[8px] font-black text-slate-400">FCFA</span>
                      </div>

                      <div class="mt-0.5 min-h-3">
                        <p v-if="cellErrors[cellKey(origin.id, destination.id)]" class="line-clamp-1 text-[8px] font-bold leading-tight text-rose-600">
                          {{ cellErrors[cellKey(origin.id, destination.id)] }}
                        </p>
                        <p v-else-if="getCellFare(origin.id, destination.id)?.mirrored" class="text-[8px] font-bold text-emerald-600 dark:text-emerald-400">
                          Repris du sens inverse
                        </p>
                        <p v-else-if="!getCellFare(origin.id, destination.id)" class="text-[8px] font-medium text-slate-400">
                          Cellule vide
                        </p>
                      </div>

                      <div class="mt-auto flex items-center justify-between">
                        <div v-if="getCellFare(origin.id, destination.id)" class="flex items-center gap-0.5">
                          <button
                            type="button"
                            class="rounded px-0.5 py-0.5 text-[8px] font-black transition hover:bg-slate-100 dark:hover:bg-slate-800"
                            :class="getCellFare(origin.id, destination.id).fare.is_bidirectional ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500'"
                            :title="getCellFare(origin.id, destination.id).fare.is_bidirectional ? 'Passer en sens unique' : 'Rendre bidirectionnel'"
                            @click="toggleDirection(origin, destination)"
                          >
                            {{ getCellFare(origin.id, destination.id).fare.is_bidirectional ? '↔ Deux sens' : '→ Un sens' }}
                          </button>
                          <button
                            type="button"
                            class="h-4 w-4 rounded-full border-2 transition"
                            :class="getCellFare(origin.id, destination.id).fare.active === false ? 'border-slate-300 bg-slate-200 dark:border-slate-600 dark:bg-slate-700' : 'border-emerald-200 bg-emerald-500 dark:border-emerald-800'"
                            :title="getCellFare(origin.id, destination.id).fare.active === false ? 'Activer le tarif' : 'Désactiver le tarif'"
                            @click="toggleActive(origin, destination)"
                          ></button>
                          <button
                            type="button"
                            class="rounded-md p-0.5 text-slate-300 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30"
                            title="Supprimer"
                            @click="deleteCell(origin, destination)"
                          >
                            <Trash2 :size="13" />
                          </button>
                        </div>
                        <span v-else class="text-[8px] font-bold" :class="newFareBidirectional ? 'text-emerald-500' : 'text-slate-400'">
                          {{ newFareBidirectional ? '↔ à la création' : '→ à la création' }}
                        </span>

                        <Refresh v-if="savingCells[cellKey(origin.id, destination.id)]" :size="14" class="animate-spin text-emerald-600" />
                        <Check v-else-if="savedCells[cellKey(origin.id, destination.id)]" :size="14" class="text-emerald-600" />
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
