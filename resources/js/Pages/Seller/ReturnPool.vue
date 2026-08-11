<script setup>
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import ArrowLeftRight from 'vue-material-design-icons/ArrowLeftRight.vue';
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue';
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue';
import TicketConfirmation from 'vue-material-design-icons/TicketConfirmation.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import Check from 'vue-material-design-icons/Check.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import { toastStore } from '@/Stores/toastStore.js';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const { t } = useI18n();
const props = defineProps({ journeys: Array, trips: Array, stations: Array });

const filters = reactive({ station_id: '', mode: '', status: '', date: '' });
const assignments = reactive({});
const preferences = reactive({});
props.journeys.forEach((journey) => {
  assignments[journey.id] ??= { trip_id: '', seat_number: '' };
  preferences[journey.id] ??= { desired_travel_date: journey.desired_travel_date || '', desired_departure_time: journey.desired_departure_time || '' };
});

const modeLabels = {
  fixed_schedule: t('ticketing.return_pool.mode_fixed_schedule'),
  date_flexible: t('ticketing.return_pool.mode_date_flexible'),
  open: t('ticketing.return_pool.mode_open'),
};
const statusLabels = {
  pending: t('ticketing.return_pool.status_pending'),
  awaiting_trip: t('ticketing.return_pool.status_awaiting_trip'),
  ready: t('ticketing.return_pool.status_ready'),
  assigned: t('ticketing.return_pool.status_assigned'),
};
const statusClass = (status) => ({
  pending: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  awaiting_trip: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',
  ready: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
  assigned: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
}[status] || 'bg-slate-100 text-slate-600');

const stationOptions = computed(() => [...props.stations].sort((a, b) => a.name.localeCompare(b.name, 'fr')));

const filteredJourneys = computed(() => props.journeys.filter((journey) =>
  (!filters.station_id || journey.from_station_id === filters.station_id)
  && (!filters.mode || journey.selection_mode === filters.mode)
  && (!filters.status || journey.status === filters.status)
  && (!filters.date || (journey.desired_travel_date || '').startsWith(filters.date))
));

const isAtRisk = (journey) => {
  if (journey.status === 'awaiting_trip' && !journey.trip_id) return true;
  if (journey.valid_until && new Date(journey.valid_until) < new Date(Date.now() + 3 * 86400000)) return true;
  return false;
};

const groups = computed(() => ({
  scheduled: filteredJourneys.value.filter((j) => j.selection_mode === 'fixed_schedule'),
  date: filteredJourneys.value.filter((j) => j.selection_mode === 'date_flexible'),
  open: filteredJourneys.value.filter((j) => j.selection_mode === 'open'),
}));

const stats = computed(() => ({
  total: filteredJourneys.value.length,
  awaiting_trip: filteredJourneys.value.filter((j) => j.status === 'awaiting_trip').length,
  ready: filteredJourneys.value.filter((j) => j.status === 'ready').length,
  assigned: filteredJourneys.value.filter((j) => j.status === 'assigned').length,
  at_risk: filteredJourneys.value.filter(isAtRisk).length,
}));

const compatibleTrips = (journey) => props.trips.filter((trip) =>
  trip.origin_station_id === journey.from_station_id
  && trip.destination_station_id === journey.to_station_id
);

const assign = async (journey) => {
  const choice = assignments[journey.id];
  if (!choice?.trip_id) {
    toastStore.error(t('ticketing.return_pool.choose_trip'));
    return;
  }
  try {
    const { data } = await axios.post(route('seller.return-pool.assign', { journey: journey.id }), {
      trip_id: choice.trip_id,
      seat_number: choice.seat_number || null,
    });
    toastStore.success(data.message);
    assignments[journey.id] = { trip_id: '', seat_number: '' };
    window.location.reload();
  } catch (error) {
    toastStore.error(error.response?.data?.message || 'Erreur lors de l’affectation.');
  }
};

const unassign = async (journey) => {
  const ok = await confirmationStore.confirm({
    title: t('ticketing.return_pool.unassign_title'),
    message: t('ticketing.return_pool.unassign_message', { number: journey.ticket?.ticket_number }),
    confirmLabel: t('ticketing.return_pool.unassign_confirm'),
    tone: 'danger',
  });
  if (!ok) return;
  try {
    const { data } = await axios.delete(route('seller.return-pool.unassign', { journey: journey.id }));
    toastStore.success(data.message);
    window.location.reload();
  } catch (error) {
    toastStore.error(error.response?.data?.message || 'Erreur.');
  }
};

const savePreference = async (journey) => {
  const pref = preferences[journey.id];
  try {
    const { data } = await axios.patch(route('seller.return-pool.preference', { journey: journey.id }), {
      desired_travel_date: pref.desired_travel_date || null,
      desired_departure_time: pref.desired_departure_time || null,
    });
    toastStore.success(data.message);
  } catch (error) {
    toastStore.error(error.response?.data?.message || 'Erreur.');
  }
};

const formatDate = (date) => (date ? String(date).slice(0, 10) : '—');
const formatTime = (time) => (time ? String(time).slice(0, 5) : '—');

const journeyKey = (j) => `${j.direction}-${j.ticket_id}`;
const journeyTitle = (j) => `${j.ticket?.ticket_number || '—'} · ${j.from_station?.name || '?'} → ${j.to_station?.name || '?'}`;
</script>

<template>
  <MainNavLayout>
    <div class="p-6">
      <div class="mb-6">
        <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900 dark:text-slate-100">
          <span class="rounded-xl bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
            <ArrowLeftRight :size="22" />
          </span>
          {{ $t('ticketing.return_pool.title') }}
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t('ticketing.return_pool.subtitle') }}</p>
      </div>

      <!-- Stats -->
      <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ stats.total }}</div>
          <div class="text-xs font-semibold text-slate-500">{{ $t('ticketing.return_pool.stats_total') }}</div>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
          <div class="text-2xl font-black text-amber-700 dark:text-amber-300">{{ stats.awaiting_trip }}</div>
          <div class="text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $t('ticketing.return_pool.stats_awaiting') }}</div>
        </div>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-3 dark:border-blue-900/50 dark:bg-blue-950/30">
          <div class="text-2xl font-black text-blue-700 dark:text-blue-300">{{ stats.ready }}</div>
          <div class="text-xs font-semibold text-blue-600 dark:text-blue-400">{{ $t('ticketing.return_pool.stats_ready') }}</div>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/30">
          <div class="text-2xl font-black text-emerald-700 dark:text-emerald-300">{{ stats.assigned }}</div>
          <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ $t('ticketing.return_pool.stats_assigned') }}</div>
        </div>
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-3 dark:border-rose-900/50 dark:bg-rose-950/30">
          <div class="text-2xl font-black text-rose-700 dark:text-rose-300">{{ stats.at_risk }}</div>
          <div class="text-xs font-semibold text-rose-600 dark:text-rose-400">{{ $t('ticketing.return_pool.stats_at_risk') }}</div>
        </div>
      </div>

      <!-- Filtres -->
      <div class="mb-5 flex flex-wrap gap-2">
        <select v-model="filters.station_id" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
          <option value="">{{ $t('ticketing.return_pool.filter_all_stations') }}</option>
          <option v-for="station in stationOptions" :key="station.id" :value="station.id">{{ station.name }}</option>
        </select>
        <select v-model="filters.mode" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
          <option value="">{{ $t('ticketing.return_pool.filter_all_modes') }}</option>
          <option v-for="(label, key) in modeLabels" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filters.status" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
          <option value="">{{ $t('ticketing.return_pool.filter_all_status') }}</option>
          <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
        </select>
        <AppDatePicker v-model="filters.date" class="min-w-44 text-xs" />
      </div>

      <!-- Groupes -->
      <div v-for="(items, groupKey) in groups" :key="groupKey" class="mb-6">
        <div class="mb-2 flex items-center gap-2">
          <span class="rounded-lg p-1.5" :class="{
            'bg-amber-100 text-amber-600 dark:bg-amber-900/50': groupKey === 'scheduled',
            'bg-blue-100 text-blue-600 dark:bg-blue-900/50': groupKey === 'date',
            'bg-slate-100 text-slate-600 dark:bg-slate-800': groupKey === 'open',
          }">
            <CalendarClock v-if="groupKey === 'scheduled'" :size="16" />
            <CalendarBlank v-else-if="groupKey === 'date'" :size="16" />
            <TicketConfirmation v-else :size="16" />
          </span>
          <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200">
            {{ $t(`ticketing.return_pool.group_${groupKey}`) }}
          </h2>
          <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ items.length }}</span>
        </div>

        <div v-if="items.length === 0" class="rounded-xl border border-dashed border-slate-200 p-4 text-center text-xs text-slate-400 dark:border-slate-800">
          {{ $t('ticketing.return_pool.empty_group') }}
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="journey in items"
            :key="journey.id"
            class="rounded-xl border p-3"
            :class="isAtRisk(journey)
              ? 'border-rose-200 bg-rose-50/60 dark:border-rose-900/40 dark:bg-rose-950/20'
              : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900'"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ journeyTitle(journey) }}</span>
                  <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusClass(journey.status)">{{ statusLabels[journey.status] }}</span>
                  <span v-if="isAtRisk(journey)" class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-700 dark:bg-rose-900/50 dark:text-rose-300">
                    <AlertCircle :size="11" /> {{ $t('ticketing.return_pool.at_risk') }}
                  </span>
                </div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                  <span>{{ $t('ticketing.return_pool.mode') }} : {{ modeLabels[journey.selection_mode] }}</span>
                  <span v-if="journey.desired_travel_date">{{ $t('ticketing.return_pool.date') }} : {{ formatDate(journey.desired_travel_date) }}</span>
                  <span v-if="journey.desired_departure_time">{{ $t('ticketing.return_pool.time') }} : {{ formatTime(journey.desired_departure_time) }}</span>
                  <span v-if="journey.trip">{{ $t('ticketing.return_pool.trip') }} : {{ journey.trip.origin_station?.name || '' }} {{ formatTime(journey.trip.departure_at) }}</span>
                  <span v-if="journey.seat_number">{{ $t('ticketing.return_pool.seat') }} : {{ journey.seat_number }}</span>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <!-- Préférence (date flexible / open) -->
                <template v-if="journey.selection_mode !== 'fixed_schedule'">
                  <AppDatePicker v-model="preferences[journey.id].desired_travel_date" class="w-44 text-xs" />
                  <button class="rounded-lg bg-slate-100 p-1.5 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300" title="Enregistrer la préférence" @click="savePreference(journey)">
                    <Check :size="14" />
                  </button>
                </template>

                <!-- Affectation -->
                <select v-if="journey.status !== 'assigned'" v-model="assignments[journey.id].trip_id" class="rounded-lg border border-slate-200 px-1.5 py-1 text-xs dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                  <option value="">{{ $t('ticketing.return_pool.choose_trip') }}</option>
                  <option v-for="trip in compatibleTrips(journey)" :key="trip.id" :value="trip.id">
                    {{ formatTime(trip.departure_at) }} · {{ trip.vehicle?.identifier || '—' }}
                  </option>
                </select>
                <input v-if="journey.status !== 'assigned'" v-model="assignments[journey.id].seat_number" type="number" min="1" placeholder="Siège" class="w-16 rounded-lg border border-slate-200 px-1.5 py-1 text-xs dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                <button v-if="journey.status !== 'assigned'" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2 py-1 text-xs font-bold text-white hover:bg-emerald-700" @click="assign(journey)">
                  <Refresh :size="12" /> {{ $t('ticketing.return_pool.assign') }}
                </button>

                <button v-if="journey.status === 'assigned'" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="unassign(journey)">
                  <Close :size="12" /> {{ $t('ticketing.return_pool.unassign') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
