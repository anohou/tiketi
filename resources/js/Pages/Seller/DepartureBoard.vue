<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectBox from '@/Components/SelectBox.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DialogModal from '@/Components/DialogModal.vue';
import TextInput from '@/Components/TextInput.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import BusClock from 'vue-material-design-icons/BusClock.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import Lock from 'vue-material-design-icons/Lock.vue';
import CalendarToday from 'vue-material-design-icons/CalendarToday.vue';
import ArrowLeftRight from 'vue-material-design-icons/ArrowLeftRight.vue';
import { toastStore } from '@/Stores/toastStore.js';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const props = defineProps({
  station: { type: Object, default: null },
  date: { type: String, default: '' },
  trips: { type: Array, default: () => [] },
  vehicles: { type: Array, default: () => [] },
  companyDefaultPolicy: { type: String, default: 'require_real_vehicle' },
});

const loading = ref(false);
const dateFilter = ref(props.date || new Date().toISOString().slice(0, 10));
const assignModalOpen = ref(false);
const deferModalOpen = ref(false);
const currentTrip = ref(null);
const selectedVehicleId = ref('');
const deferReason = ref('');
const processing = ref(false);
const errors = ref({});

const fetchBoard = async () => {
  if (!props.station?.id) return;
  loading.value = true;
  try {
    router.get(route('seller.departure-board.index', props.station.id), { date: dateFilter.value }, {
      preserveState: true,
      preserveScroll: true,
      only: ['trips', 'vehicles'],
      onSuccess: () => { loading.value = false; },
      onError: () => { loading.value = false; toastStore.error('Impossible de charger le tableau des départs.'); },
    });
  } catch (e) {
    loading.value = false;
  }
};

const policyLabel = (policy) =>
  policy === 'allow_planned_capacity' ? 'Vente sur capacité prévue' : 'Car réel obligatoire';

const tripState = (trip) => {
  if (trip.vehicle && !trip.vehicle.is_placeholder) {
    return { label: 'Car réel affecté', tone: 'success', icon: CheckCircle };
  }
  if (trip.sales_ready && trip.allows_planned_capacity) {
    return { label: 'Vente sur capacité prévue — car à affecter', tone: 'warning', icon: AlertCircle };
  }
  return { label: 'Car réel à affecter — ventes fermées', tone: 'danger', icon: Lock };
};

const openAssign = (trip) => {
  currentTrip.value = trip;
  selectedVehicleId.value = '';
  errors.value = {};
  assignModalOpen.value = true;
};

const submitAssign = () => {
  if (!currentTrip.value) return;
  processing.value = true;
  errors.value = {};

  router.post(route('seller.departure-board.assign-vehicle', currentTrip.value.id), {
    vehicle_id: selectedVehicleId.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success('Car réel affecté. Les ventes sont ouvertes et les sièges sans place ont été attribués.');
      assignModalOpen.value = false;
      processing.value = false;
      fetchBoard();
    },
    onError: (err) => {
      errors.value = err;
      processing.value = false;
    },
  });
};

const openDefer = (trip) => {
  currentTrip.value = trip;
  deferReason.value = '';
  errors.value = {};
  deferModalOpen.value = true;
};

const submitDefer = async () => {
  if (!currentTrip.value) return;

  const ok = await confirmationStore.confirm({
    title: 'Vendre sans car pour le moment ?',
    message: `Les ventes du départ ${currentTrip.value.departure_at?.slice(11, 16) || ''} seront ouvertes sur la capacité prévisionnelle, sans numéro de place. Le car réel restera obligatoire avant l’embarquement et le départ.`,
    confirmLabel: 'Vendre sans car',
    tone: 'warning',
  });
  if (!ok) return;

  processing.value = true;
  errors.value = {};

  router.post(route('seller.departure-board.defer-vehicle-assignment', currentTrip.value.id), {
    reason: deferReason.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success('Vente sur capacité planifiée ouverte.');
      deferModalOpen.value = false;
      processing.value = false;
      fetchBoard();
    },
    onError: (err) => {
      errors.value = err;
      processing.value = false;
    },
  });
};

onMounted(fetchBoard);
</script>

<template>
  <MainNavLayout>
    <div class="p-6">
      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900 dark:text-slate-100">
            <span class="rounded-xl bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
              <BusClock :size="22" />
            </span>
            Départs du jour — {{ station?.name || '—' }}
          </h1>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Voyages matérialisés la nuit par les programmes de départ. Chaque voyage sans car réel doit d’abord recevoir son car.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <Link :href="route('seller.return-pool.index')" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300">
            <ArrowLeftRight :size="16" />
            Pool des retours
          </Link>
          <AppDatePicker v-model="dateFilter" :clearable="false" class="w-52" />
          <PrimaryButton :disabled="loading" @click="fetchBoard">
            <CalendarToday :size="18" class="mr-1.5" />
            {{ loading ? 'Chargement…' : 'Actualiser' }}
          </PrimaryButton>
        </div>
      </div>

      <div v-if="trips.length === 0" class="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <EmptyState
          title="Aucun départ pour cette journée"
          description="Les voyages sont matérialisés chaque nuit à partir des programmes de départ."
        />
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="trip in trips"
          :key="trip.id"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition dark:border-slate-800 dark:bg-slate-900"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-lg font-bold text-slate-900 dark:text-slate-100">
                  {{ trip.departure_at?.slice(11, 16) }}
                </span>
                <span class="text-sm text-slate-500">{{ trip.route_label }}</span>
                <span v-if="trip.from_schedule" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                  {{ trip.schedule_label }}
                </span>
              </div>
              <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span class="font-medium text-slate-600 dark:text-slate-300">{{ trip.code }}</span>
                <span v-if="trip.vehicle" class="inline-flex items-center gap-1">
                  <Bus :size="14" class="text-emerald-500" />
                  {{ trip.vehicle.identifier }}
                  <span v-if="trip.vehicle.is_placeholder" class="italic">(technique)</span>
                </span>
                <span v-else class="italic">Aucun véhicule</span>
                <span class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 font-medium dark:bg-slate-800">
                  {{ trip.engaged }} engagé(s) / {{ trip.capacity }} places
                </span>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                :class="{
                  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300': tripState(trip).tone === 'success',
                  'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300': tripState(trip).tone === 'warning',
                  'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300': tripState(trip).tone === 'danger',
                }"
              >
                <component :is="tripState(trip).icon" :size="14" />
                {{ tripState(trip).label }}
              </span>

              <template v-if="trip.awaiting_real_vehicle">
                <PrimaryButton @click="openAssign(trip)">
                  <Bus :size="16" class="mr-1.5" />
                  Affecter un car
                </PrimaryButton>
                <SecondaryButton v-if="trip.allows_planned_capacity && !trip.sales_ready" @click="openDefer(trip)">
                  Vendre sans car pour le moment
                </SecondaryButton>
              </template>
              <span v-if="trip.deferred_at" class="text-xs text-amber-600 dark:text-amber-400">
                Report : {{ trip.deferred_reason }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Affectation du car -->
    <DialogModal :show="assignModalOpen" max-width="md" @close="assignModalOpen = false">
      <template #title>
        Affecter un car réel — {{ currentTrip?.departure_at?.slice(11, 16) || '' }} {{ currentTrip?.route_label }}
      </template>
      <template #content>
        <InputLabel value="Car réel" />
        <SelectBox
          v-model="selectedVehicleId"
          :options="vehicles"
          label-key="identifier"
          placeholder="Sélectionner un car…"
          class="mt-1"
        />
        <p class="mt-2 text-xs text-slate-500">
          L’assurance, la capacité ({{ currentTrip?.engaged }} engagement(s) actif(s)) et l’affectation à la gare seront vérifiées.
          Les sièges des billets vendus sans place seront attribués automatiquement.
        </p>
        <InputError :message="errors.vehicle_id" class="mt-2" />
      </template>
      <template #footer>
        <SecondaryButton @click="assignModalOpen = false">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="processing || !selectedVehicleId" @click="submitAssign">
          Affecter le car
        </PrimaryButton>
      </template>
    </DialogModal>

    <!-- Report explicite -->
    <DialogModal :show="deferModalOpen" max-width="md" @close="deferModalOpen = false">
      <template #title>
        Vendre sans car pour le moment — {{ currentTrip?.departure_at?.slice(11, 16) || '' }}
      </template>
      <template #content>
        <InputLabel value="Motif du report (obligatoire, audité)" />
        <TextInput v-model="deferReason" type="text" class="mt-1" placeholder="Ex : car indisponible, affectation en attente…" />
        <InputError :message="errors.reason" class="mt-1" />
        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
          Les billets vendus n’auront pas de numéro de place : la mention « Place attribuée avant l’embarquement » sera imprimée.
          Le car réel reste obligatoire avant l’équipage, l’embarquement et le départ.
        </p>
      </template>
      <template #footer>
        <SecondaryButton @click="deferModalOpen = false">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="processing || !deferReason" @click="submitDefer">
          Vendre sans car pour le moment
        </PrimaryButton>
      </template>
    </DialogModal>
  </MainNavLayout>
</template>
