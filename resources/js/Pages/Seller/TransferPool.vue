<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import AccountClock from 'vue-material-design-icons/AccountClock.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';

const { t } = useI18n();

const props = defineProps({ connections: Array, trips: Array, stations: Array });
const filters = reactive({ station_id: '', route_id: '', destination_id: '', status: '' });
const expandedGroup = ref(null);
const assignments = reactive({});
const initializeAssignments = (connections) => connections.forEach(connection => {
  assignments[connection.id] ??= { trip_id: '', seat_number: '' };
});
initializeAssignments(props.connections);
watch(() => props.connections, initializeAssignments, { deep: true });

const statusLabels = { pending: t('ticketing.transfer_pool.status_pending'), ready: t('ticketing.transfer_pool.status_ready'), assigned: t('ticketing.transfer_pool.status_assigned'), boarded: t('ticketing.transfer_pool.status_boarded'), missed: t('ticketing.transfer_pool.status_missed') };
const statusClass = (status) => ({
  pending: 'bg-amber-100 text-amber-700', ready: 'bg-emerald-100 text-emerald-700',
  assigned: 'bg-blue-100 text-blue-700', boarded: 'bg-violet-100 text-violet-700', missed: 'bg-rose-100 text-rose-700',
}[status] || 'bg-slate-100 text-slate-700');

const routeOptions = computed(() => [...new Map(props.connections.filter(c => c.route).map(c => [c.route.id, c.route])).values()]);
const destinationOptions = computed(() => [...new Map(props.connections.filter(c => c.destination_station).map(c => [c.destination_station.id, c.destination_station])).values()]);
const filteredConnections = computed(() => props.connections.filter(connection =>
  (!filters.station_id || connection.transfer_station_id === filters.station_id)
  && (!filters.route_id || connection.route_id === filters.route_id)
  && (!filters.destination_id || connection.destination_station_id === filters.destination_id)
  && (!filters.status || connection.status === filters.status)
));

const stats = computed(() => ({
  total: filteredConnections.value.length,
  pending: filteredConnections.value.filter(c => c.status === 'pending').length,
  ready: filteredConnections.value.filter(c => c.status === 'ready').length,
  assigned: filteredConnections.value.filter(c => ['assigned', 'boarded'].includes(c.status)).length,
  conflicts: filteredConnections.value.filter(c => c.settings?.has_conflict).length,
}));

const panorama = computed(() => {
  const stations = new Map();
  filteredConnections.value.forEach(connection => {
    const stationKey = connection.transfer_station_id;
    const routeKey = connection.route_id || 'unassigned';
    const destinationKey = connection.destination_station_id;
    if (!stations.has(stationKey)) stations.set(stationKey, { id: stationKey, station: connection.transfer_station, routes: new Map(), count: 0 });
    const station = stations.get(stationKey); station.count++;
    if (!station.routes.has(routeKey)) station.routes.set(routeKey, { id: routeKey, route: connection.route, destinations: new Map(), connections: [], count: 0 });
    const routeGroup = station.routes.get(routeKey); routeGroup.count++; routeGroup.connections.push(connection);
    if (!routeGroup.destinations.has(destinationKey)) routeGroup.destinations.set(destinationKey, { station: connection.destination_station, connections: [] });
    routeGroup.destinations.get(destinationKey).connections.push(connection);
  });
  return [...stations.values()].map(station => ({ ...station, routes: [...station.routes.values()].map(route => ({ ...route, destinations: [...route.destinations.values()] })) }));
});

const actionableConnections = computed(() => filteredConnections.value.filter(item =>
  ['pending', 'ready'].includes(item.status) || (item.status === 'assigned' && item.settings?.has_conflict)
));
const assigned = computed(() => filteredConnections.value.filter(item => ['assigned', 'boarded'].includes(item.status)));
const compatibleTrips = (connection) => props.trips.filter(trip => {
  const stops = [...(trip.route?.route_stop_orders || trip.route?.routeStopOrders || [])]
    .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0));
  const ids = [
    trip.origin_station_id,
    ...stops.map(stop => stop.station_id || stop.station?.id),
    trip.destination_station_id
  ].filter(Boolean);

  const transferIdx = ids.indexOf(connection.transfer_station_id);
  const destIdx = ids.indexOf(connection.destination_station_id);
  return transferIdx !== -1 && destIdx !== -1 && transferIdx < destIdx;
});
const markReady = (connection) => router.patch(route('seller.transfer-pool.ready', connection.id), {}, { preserveScroll: true });
const assign = (connection) => {
  const data = assignments[connection.id] || {};
  if (!data.trip_id || !data.seat_number) return;
  router.post(route('seller.transfer-pool.assign', data.trip_id), { connection_id: connection.id, seat_number: Number(data.seat_number) }, { preserveScroll: true });
};
const markDeparted = async (trip) => { await axios.patch(route('seller.trips.depart', trip.id)); router.reload({ preserveScroll: true }); };
</script>

<template>
  <MainNavLayout :title="$t('ticketing.transfer_pool.title')">
    <div class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
      <header class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center gap-3"><AccountClock class="text-emerald-600" /><div><h1 class="text-xl font-bold text-slate-900 dark:text-white">{{ $t('ticketing.transfer_pool.title') }}</h1><p class="text-sm text-slate-500">{{ $t('ticketing.transfer_pool.subtitle') }}</p></div></div>
      </header>

      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div v-for="item in [{k:'total',l: t('ticketing.transfer_pool.total_pool')},{k:'pending',l: t('ticketing.transfer_pool.expected')},{k:'ready',l: t('ticketing.transfer_pool.ready')},{k:'assigned',l: t('ticketing.transfer_pool.assigned_boarded')},{k:'conflicts',l: t('ticketing.transfer_pool.time_alerts')}]" :key="item.k" class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <div class="text-2xl font-black text-slate-900 dark:text-white">{{ stats[item.k] }}</div><div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ item.l }}</div>
        </div>
      </section>

      <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
        <select v-model="filters.station_id" class="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ $t('ticketing.transfer_pool.all_stations') }}</option><option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option></select>
        <select v-model="filters.route_id" class="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ $t('ticketing.transfer_pool.all_routes') }}</option><option v-for="routeItem in routeOptions" :key="routeItem.id" :value="routeItem.id">{{ routeItem.name }}</option></select>
        <select v-model="filters.destination_id" class="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ $t('ticketing.transfer_pool.all_destinations') }}</option><option v-for="destination in destinationOptions" :key="destination.id" :value="destination.id">{{ destination.name }}</option></select>
        <select v-model="filters.status" class="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ $t('ticketing.transfer_pool.all_statuses') }}</option><option v-for="(label,key) in statusLabels" :key="key" :value="key">{{ label }}</option></select>
      </section>

      <section class="space-y-4">
        <div v-for="stationGroup in panorama" :key="stationGroup.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-center justify-between bg-slate-50 px-5 py-4 dark:bg-slate-950/40"><div><h2 class="font-black text-slate-900 dark:text-white">{{ stationGroup.station?.name }}</h2><p class="text-xs text-slate-500">{{ $t('ticketing.transfer_pool.transit_station') }}</p></div><span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-700">{{ stationGroup.count }} {{ $t('ticketing.transfer_pool.passengers') }}</span></div>
          <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <div v-for="routeGroup in stationGroup.routes" :key="routeGroup.id" class="p-4">
              <button @click="expandedGroup = expandedGroup === `${stationGroup.id}-${routeGroup.id}` ? null : `${stationGroup.id}-${routeGroup.id}`" class="flex w-full items-center justify-between text-left">
                <div><div class="font-bold text-slate-800 dark:text-slate-100">{{ routeGroup.route?.name || $t('ticketing.transfer_pool.route_tbd') }}</div><div class="text-xs text-slate-500">{{ routeGroup.destinations.length }} {{ $t('ticketing.transfer_pool.destinations') }} · {{ routeGroup.count }} {{ $t('ticketing.transfer_pool.passengers') }}</div></div>
                <div class="flex flex-wrap justify-end gap-2"><span v-for="destination in routeGroup.destinations" :key="destination.station.id" class="rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">{{ destination.station.name }}: {{ destination.connections.length }}</span></div>
              </button>
              <div v-if="expandedGroup === `${stationGroup.id}-${routeGroup.id}`" class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2">{{ $t('ticketing.transfer_pool.ticket_passenger') }}</th><th>{{ $t('ticketing.transfer_pool.destination') }}</th><th>{{ $t('ticketing.transfer_pool.arrival') }}</th><th>{{ $t('ticketing.transfer_pool.status') }}</th><th>{{ $t('ticketing.transfer_pool.trip') }}</th></tr></thead><tbody><tr v-for="connection in routeGroup.connections" :key="connection.id" :class="connection.settings?.has_conflict ? 'bg-rose-50 dark:bg-rose-950/20' : ''" class="border-t border-slate-100 dark:border-slate-800"><td class="py-3"><strong>{{ connection.ticket.ticket_number }}</strong><span class="block text-xs text-slate-500">{{ connection.ticket.passenger_name }}</span><span v-if="connection.settings?.has_conflict" class="mt-1 flex items-center gap-1 text-xs font-bold text-rose-600"><AlertCircle :size="15" /> {{ $t('ticketing.transfer_pool.conflict_connection') }}</span></td><td>{{ connection.destination_station.name }}</td><td class="text-xs"><span class="block">{{ $t('ticketing.transfer_pool.planned') }} {{ connection.planned_ready_at ? new Date(connection.planned_ready_at).toLocaleString('fr-FR') : '-' }}</span><span v-if="connection.estimated_ready_at" class="font-bold text-emerald-600">{{ $t('ticketing.transfer_pool.re_estimated') }} {{ new Date(connection.estimated_ready_at).toLocaleString('fr-FR') }}</span></td><td><span :class="statusClass(connection.status)" class="rounded-full px-2 py-1 text-xs font-bold">{{ statusLabels[connection.status] }}</span></td><td>{{ connection.trip?.code || $t('ticketing.transfer_pool.unassigned') }}</td></tr></tbody></table></div>
            </div>
          </div>
        </div>
        <div v-if="panorama.length === 0" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500">{{ $t('ticketing.transfer_pool.no_connections_filter') }}</div>
      </section>

      <section class="grid gap-4">
        <h2 class="font-black text-slate-900 dark:text-white">{{ $t('ticketing.transfer_pool.operational_processing') }}</h2>
        <article v-for="connection in actionableConnections" :key="connection.id" :class="connection.settings?.has_conflict ? 'border-rose-300' : 'border-slate-200 dark:border-slate-800'" class="rounded-2xl border bg-white p-4 dark:bg-slate-900"><div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center"><div><strong>{{ connection.ticket.ticket_number }} · {{ connection.ticket.passenger_name }}</strong><p class="text-sm text-slate-500">{{ connection.transfer_station.name }} · {{ connection.route?.name }} · {{ $t('ticketing.transfer_pool.destination_lower') }} {{ connection.destination_station.name }}</p><p v-if="connection.settings?.has_conflict" class="mt-2 flex items-start gap-2 rounded-lg bg-rose-50 p-2 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-300"><AlertCircle :size="17" class="shrink-0" /> {{ connection.settings.conflict_reason }}</p></div><div class="flex flex-wrap items-end gap-2"><button v-if="connection.status === 'pending'" @click="markReady(connection)" class="rounded-xl bg-amber-500 px-3 py-2 text-sm font-bold text-white">{{ $t('ticketing.transfer_pool.mark_ready') }}</button><template v-else><select v-model="assignments[connection.id].trip_id" class="rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-white"><option value="">{{ $t('ticketing.transfer_pool.next_trip') }}</option><option v-for="trip in compatibleTrips(connection)" :key="trip.id" :value="trip.id">{{ trip.code }} · {{ trip.route?.name }}</option></select><input v-model="assignments[connection.id].seat_number" type="number" min="1" :placeholder="$t('ticketing.transfer_pool.seat')" class="w-24 rounded-xl border-slate-200 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-white" /><button @click="assign(connection)" class="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white">{{ connection.status === 'assigned' ? $t('ticketing.transfer_pool.reassign') : $t('ticketing.transfer_pool.assign') }}</button></template></div></div></article>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-white"><Bus /> {{ $t('ticketing.transfer_pool.departures_visible_stations') }}</h2><div v-for="trip in trips" :key="trip.id" class="flex items-center justify-between gap-3 border-t border-slate-100 py-3 text-sm dark:border-slate-800"><div><strong>{{ trip.code }}</strong> · {{ trip.route?.name }}<span class="block text-xs text-slate-500">{{ new Date(trip.departure_at).toLocaleString('fr-FR') }} · {{ $t('ticketing.transfer_pool.arrival_lower') }} {{ trip.planned_arrival_at ? new Date(trip.planned_arrival_at).toLocaleString('fr-FR') : '?' }}</span></div><button @click="markDeparted(trip)" class="rounded-xl bg-slate-900 px-3 py-2 font-bold text-white dark:bg-emerald-600">{{ $t('ticketing.transfer_pool.mark_departure') }}</button></div></section>
    </div>
  </MainNavLayout>
</template>
