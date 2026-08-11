<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import { usePage } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import AccordionSection from '@/Components/UI/AccordionSection.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Steering from 'vue-material-design-icons/Steering.vue';
import SeatPassenger from 'vue-material-design-icons/SeatPassenger.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';

const { exportToExcel, printList } = useExportPrint();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

const props = defineProps({
  vehicles: {
    type: Object,
    default: () => ({ data: [] }),
  },
  vehicleTypes: {
    type: Array,
    default: () => [],
  },
  crewMembers: {
    type: Array,
    default: () => [],
  },
  operationalSummary: {
    type: Object,
    default: () => ({}),
  },
});

const statusConfig = {
  in_transit: { label: 'En voyage', badge: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300', dot: 'bg-purple-500' },
  scheduled: { label: 'Programmé', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300', dot: 'bg-blue-500' },
  available: { label: 'Disponible', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', dot: 'bg-emerald-500' },
  inactive: { label: 'En panne', badge: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', dot: 'bg-rose-500' },
};

const summaryCards = computed(() => [
  { key: 'in_transit', title: 'En voyage', accent: 'text-purple-600 dark:text-purple-300', bg: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300', count: props.operationalSummary?.in_transit || 0 },
  { key: 'scheduled', title: 'Programmés', accent: 'text-blue-600 dark:text-blue-300', bg: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300', count: props.operationalSummary?.scheduled || 0 },
  { key: 'available', title: 'Disponibles', accent: 'text-emerald-600 dark:text-emerald-300', bg: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', count: props.operationalSummary?.available || 0 },
  { key: 'inactive', title: 'En panne', accent: 'text-rose-600 dark:text-rose-300', bg: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', count: props.operationalSummary?.inactive || 0 },
]);

const operationalOf = vehicle => vehicle.operational || { status: 'available', trip: null, inactive_reason: null };

const tripHref = (vehicle) => {
  const trip = operationalOf(vehicle).trip;
  return trip && isAdmin.value ? route('admin.trips.show', trip.id) : null;
};

const tripLabel = (vehicle) => {
  const trip = operationalOf(vehicle).trip;
  if (!trip) return '';
  const from = trip.origin || '';
  const to = trip.destination || '';
  if (operationalOf(vehicle).status === 'in_transit') {
    return `${from} → ${to}${trip.departed_at ? ` · Parti à ${trip.departed_at}` : ''}`;
  }
  if (operationalOf(vehicle).status === 'scheduled') {
    return `${from} → ${to}${trip.departure_time ? ` · Départ ${trip.departure_time}` : ''}`;
  }
  return `${from} → ${to}`;
};

const search = ref('');
const selectedVehicle = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const showTrips = ref(false);
const showCrew = ref(false);
const showCrewModal = ref(false);

const form = ref({
  identifier: '',
  maker: '',
  vehicle_type_id: '',
  seat_count: '',
  active: true,
  inactive_reason: '',
  insurance_expiry_date: '',
});

const crewForm = ref({
  crew_member_id: '',
  role: 'driver',
  assigned_from: '',
  notes: '',
});

const selectedOperationalStatus = ref('');

const toggleOperationalFilter = (statusKey) => {
  selectedOperationalStatus.value = selectedOperationalStatus.value === statusKey ? '' : statusKey;
};

const filteredVehicles = computed(() => {
  const vehicles = props.vehicles?.data || (Array.isArray(props.vehicles) ? props.vehicles : []);
  let result = vehicles;

  if (selectedOperationalStatus.value) {
    result = result.filter(v => operationalOf(v).status === selectedOperationalStatus.value);
  }

  if (search.value) {
    const searchTerm = search.value.toLowerCase();
    result = result.filter(vehicle =>
      String(vehicle.identifier || '').toLowerCase().includes(searchTerm) ||
      String(vehicle.maker || '').toLowerCase().includes(searchTerm) ||
      String(vehicle.vehicle_type?.name || '').toLowerCase().includes(searchTerm)
    );
  }

  return result;
});

watch(() => props.vehicles, (newVehicles) => {
  if (selectedVehicle.value) {
    const updatedVehicle = newVehicles.data.find(v => v.id === selectedVehicle.value.id);
    if (updatedVehicle) {
      selectedVehicle.value = updatedVehicle;
    }
  }
}, { deep: true });

const isSelected = (vehicle) => {
  if (!selectedVehicle.value) return false;
  return selectedVehicle.value.id === vehicle.id;
};

const selectVehicle = (vehicle) => {
  selectedVehicle.value = vehicle;
  // Accordéons pliés par défaut (Équipage, Voyages)
  showCrew.value = false;
  showTrips.value = false;
};

const openAddCrewModal = () => {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  crewForm.value = {
    crew_member_id: '',
    role: 'driver',
    assigned_from: now.toISOString().slice(0, 16),
    notes: '',
  };
  errors.value = {};
  showCrewModal.value = true;
};

const closeCrewModal = () => {
  showCrewModal.value = false;
};

const filteredCrewForVehicleForm = computed(() => {
  return (props.crewMembers || []).filter(m => m.role === crewForm.value.role);
});

const submitCrewAssignment = () => {
  if (!selectedVehicle.value) return;
  processing.value = true;
  errors.value = {};

  router.post(route('fleet.crew-assignments.store'), {
    vehicle_id: selectedVehicle.value.id,
    crew_member_id: crewForm.value.crew_member_id,
    role: crewForm.value.role,
    assigned_from: crewForm.value.assigned_from,
    notes: crewForm.value.notes,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      processing.value = false;
      closeCrewModal();
    },
    onError: (err) => {
      processing.value = false;
      errors.value = err;
    },
  });
};

const endCrewAssignment = async (assignmentId) => {
  if (await confirmationStore.confirm({ title: 'Clôturer l’affectation', message: 'L’affectation prendra fin, mais son historique sera conservé.', confirmLabel: 'Clôturer', tone: 'warning' })) {
    router.delete(route('fleet.crew-assignments.destroy', assignmentId), {
      preserveScroll: true,
    });
  }
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    identifier: '',
    maker: '',
    vehicle_type_id: '',
    seat_count: '',
    active: true,
    inactive_reason: '',
    insurance_expiry_date: '',
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedVehicle.value) return;
  isEditing.value = true;
  form.value = {
    identifier: selectedVehicle.value.identifier,
    maker: selectedVehicle.value.maker,
    vehicle_type_id: selectedVehicle.value.vehicle_type_id,
    seat_count: selectedVehicle.value.seat_count.toString(),
    active: selectedVehicle.value.active !== false,
    inactive_reason: selectedVehicle.value.inactive_reason || '',
    insurance_expiry_date: selectedVehicle.value.insurance_expiry_date ? selectedVehicle.value.insurance_expiry_date.slice(0, 10) : '',
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateVehicle = () => {
  if (!selectedVehicle.value) return;
  isEditing.value = false;
  form.value = {
    identifier: selectedVehicle.value.identifier + ' (Copie)',
    maker: selectedVehicle.value.maker,
    vehicle_type_id: selectedVehicle.value.vehicle_type_id,
    seat_count: selectedVehicle.value.seat_count.toString(),
    active: true,
    inactive_reason: '',
    insurance_expiry_date: selectedVehicle.value.insurance_expiry_date ? selectedVehicle.value.insurance_expiry_date.slice(0, 10) : '',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    identifier: '',
    maker: '',
    vehicle_type_id: '',
    seat_count: '',
    active: true,
    inactive_reason: '',
    insurance_expiry_date: '',
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('fleet.vehicles.update', selectedVehicle.value.id)
    : route('fleet.vehicles.store');

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

const deleteVehicle = async (id) => {
  if (await confirmationStore.confirm({ title: 'Supprimer ce véhicule', message: 'Cette action supprimera définitivement le véhicule.', confirmLabel: 'Supprimer', tone: 'danger' })) {
    router.delete(route('fleet.vehicles.destroy', id), {
      onSuccess: () => {
        if (selectedVehicle.value?.id === id) {
          selectedVehicle.value = null;
        }
      },
      onError: (errorResponse) => {
        console.error('Error deleting vehicle:', errorResponse);
      },
    });
  }
};

const vehicleColumns = {
  identifier: 'Immatriculation',
  maker: 'Fabricant',
  'vehicle_type.name': 'Type',
  seat_count: 'Places',
  trips_count: 'Voyages',
  active: 'Statut',
};

const handleExport = () => {
  const data = filteredVehicles.value.map(v => ({
    ...v,
    active: v.active ? 'Actif' : 'Inactif',
  }));
  exportToExcel(data, vehicleColumns, 'vehicules');
};

const handlePrint = () => {
  const data = filteredVehicles.value.map(v => ({
    ...v,
    active: v.active ? 'Actif' : 'Inactif',
  }));
  printList(data, vehicleColumns, 'Liste des Véhicules');
};

const getTripStatus = (trip) => {
  if (trip.status === 'scheduled') {
    const departure = new Date(trip.departure_at);
    if (departure < new Date()) {
      return 'expired';
    }
  }
  return trip.status;
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('fr-FR');
};

const isInsuranceExpired = (vehicle) => {
  if (!vehicle.insurance_expiry_date) return false;
  return new Date(vehicle.insurance_expiry_date).setHours(0,0,0,0) < new Date().setHours(0,0,0,0);
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <Bus class="text-emerald-600" :size="28" />
            </div>
            Gestion des Véhicules
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
        </div>
        <div class="flex gap-2">
          <Link :href="route('fleet.dashboard')" class="px-4 py-2 rounded-xl border border-slate-200 text-gray-700 dark:text-slate-300 dark:text-slate-300 hover:bg-gray-50">
            Retour
          </Link>
          <button @click="openCreateModal" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
            <Plus class="inline mr-1" :size="18" /> Nouveau Véhicule
          </button>
        </div>
      </div>

      <div class="grid gap-3 px-6 pb-4 sm:grid-cols-2 lg:grid-cols-4 shrink-0">
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
            <p class="truncate text-sm font-black text-gray-800 dark:text-slate-100 flex items-center gap-1.5">
              {{ card.title }}
              <span v-if="selectedOperationalStatus === card.key" class="text-xs text-emerald-600 dark:text-emerald-400 font-bold">✓</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-slate-400">{{ card.key === 'inactive' ? 'véhicules à l’arrêt' : 'véhicules' }}</p>
          </div>
        </button>
      </div>

      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu v-if="isAdmin" />
          <FleetMenu v-else />
        </div>

        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input
                    type="text"
                    v-model="search"
                    placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100"
                  />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouveau Véhicule">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons
                  :disabled="filteredVehicles.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>

            </div>

            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredVehicles.length === 0" class="p-4 text-center text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">
                Aucun véhicule trouvé.
              </div>
              <div v-else>
                <div
                  v-for="vehicle in filteredVehicles"
                  :key="vehicle.id"
                  @click="selectVehicle(vehicle)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(vehicle) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div>
                      <h3 :class="['text-base font-bold', isSelected(vehicle) ? 'text-emerald-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">{{ vehicle.identifier }}</h3>
                      <p class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">{{ vehicle.vehicle_type?.name }}</p>
                      <p class="text-xs text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">{{ vehicle.maker || 'Fabricant non renseigné' }}</p>
                      <p v-if="vehicle.current_station_assignment?.station" class="text-xs font-bold text-emerald-700 dark:text-emerald-300 mt-1 flex items-center gap-1">
                        <MapMarkerRadius :size="13" /> {{ vehicle.current_station_assignment.station.name }}
                      </p>
                      <p v-else class="text-xs font-medium text-slate-400 dark:text-slate-500 mt-1 flex items-center gap-1">
                        <MapMarkerRadius :size="13" /> Pool Général
                      </p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        vehicle.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ vehicle.active ? 'Actif' : 'Inactif' }}
                      </span>
                      <span :class="statusConfig[operationalOf(vehicle).status]?.badge" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold">
                        <span :class="statusConfig[operationalOf(vehicle).status]?.dot" class="h-1.5 w-1.5 rounded-full"></span>{{ statusConfig[operationalOf(vehicle).status]?.label }}
                      </span>
                      <span v-if="vehicle.insurance_expiry_date && isInsuranceExpired(vehicle)" class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-rose-100 text-rose-800 text-center">
                        Assur. exp.
                      </span>
                      <span class="text-[10px] text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500">
                        {{ vehicle.trips_count || 0 }} voyages
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <div v-if="!selectedVehicle" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500 dark:text-slate-400 dark:text-slate-500">
            <MapMarkerRadius class="h-16 w-16 text-slate-300 mb-4" />
            <p class="text-lg">Sélectionnez un véhicule pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou créez un nouveau véhicule
            </button>
          </div>

          <div v-else class="space-y-4">
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ selectedVehicle.identifier }}</h2>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedVehicle.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]">
                    {{ selectedVehicle.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="duplicateVehicle" class="p-2 text-emerald-600 hover:bg-emerald-50 dark:bg-emerald-950/30 rounded-lg transition-colors" title="Dupliquer">
                    <ContentCopy class="h-5 w-5" />
                  </button>
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 dark:bg-blue-950/30 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteVehicle(selectedVehicle.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <div class="mb-6 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div class="mb-2 flex items-center justify-between gap-3">
                  <span :class="statusConfig[operationalOf(selectedVehicle).status]?.badge" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-black">
                    <span :class="statusConfig[operationalOf(selectedVehicle).status]?.dot" class="h-1.5 w-1.5 rounded-full"></span>{{ statusConfig[operationalOf(selectedVehicle).status]?.label }}
                  </span>
                  <Link v-if="tripHref(selectedVehicle)" :href="tripHref(selectedVehicle)" class="text-xs font-bold text-emerald-700 hover:underline dark:text-emerald-300">
                    Ouvrir le détail du voyage →
                  </Link>
                </div>
                <p v-if="operationalOf(selectedVehicle).status === 'inactive'" class="text-sm text-rose-700 dark:text-rose-300">
                  {{ operationalOf(selectedVehicle).inactive_reason || 'Véhicule non spécifié.' }}
                </p>
                <p v-else-if="operationalOf(selectedVehicle).trip" class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                  {{ tripLabel(selectedVehicle) }}
                  <span class="ml-1 font-normal text-slate-500 dark:text-slate-400">— {{ operationalOf(selectedVehicle).trip.code }}</span>
                </p>
                <p v-else class="text-sm text-slate-500 dark:text-slate-400">Aucun voyage en cours ni programmé. Véhicule disponible.</p>
              </div>

              <div class="grid grid-cols-12 gap-6 mb-6">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">FABRICANT</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicle.maker || 'Non spécifié' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">TYPE</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicle.vehicle_type?.name }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">CAPACITÉ</span>
                  <div class="text-xl font-bold text-gray-900 dark:text-slate-100 leading-tight">
                    {{ selectedVehicle.seat_count }} places
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">EXPIRATION ASSURANCE</span>
                  <div class="text-xl font-bold leading-tight">
                    <span v-if="selectedVehicle.insurance_expiry_date" :class="[
                      isInsuranceExpired(selectedVehicle) ? 'text-rose-600' : 'text-gray-900 dark:text-slate-100'
                    ]">
                      {{ formatDate(selectedVehicle.insurance_expiry_date) }}
                      <span v-if="isInsuranceExpired(selectedVehicle)" class="text-xs font-bold text-rose-600 ml-1">(Expirée)</span>
                    </span>
                    <span v-else class="text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500">Non renseignée</span>
                  </div>
                </div>
                <div class="col-span-12">
                  <div class="p-4 rounded-xl border border-emerald-100 bg-emerald-50/50 dark:border-emerald-950/40 dark:bg-emerald-950/20">
                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <MapMarkerRadius class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                        <span class="text-xs font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">AFFECTATION AU POOL DE GARE</span>
                      </div>
                      <Link :href="route('fleet.station-vehicle-assignments.index', { vehicle_id: selectedVehicle.id })" class="text-xs font-bold text-emerald-700 hover:underline dark:text-emerald-300">
                        Gérer les pools →
                      </Link>
                    </div>
                    <div class="mt-2 text-base font-black text-slate-900 dark:text-slate-100">
                      <template v-if="selectedVehicle.current_station_assignment?.station">
                        📍 {{ selectedVehicle.current_station_assignment.station.name }}
                        <span class="text-xs font-normal text-slate-500">({{ selectedVehicle.current_station_assignment.station.city }})</span>
                      </template>
                      <template v-else>
                        📍 Pool Général (Non affecté à une gare spécifique)
                      </template>
                    </div>
                  </div>
                </div>
                <div class="col-span-12" v-if="!selectedVehicle.active">
                  <div class="p-4 rounded-lg bg-rose-50 border border-rose-100">
                    <span class="text-xs text-rose-600 uppercase tracking-wider font-bold block mb-1">MOTIF D'INACTIVITÉ</span>
                    <p class="text-rose-800">{{ selectedVehicle.inactive_reason || 'Raison non spécifiée' }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Crew Section -->
            <AccordionSection
              v-model:open="showCrew"
              :icon="AccountHardHat"
              title="Équipage Actif"
              :count="(selectedVehicle.current_crew || selectedVehicle.currentCrew || []).length"
              show-add
              add-label="Assigner un équipage"
              @add="openAddCrewModal"
            >
              <div class="space-y-2">
                <div v-if="!(selectedVehicle.current_crew || selectedVehicle.currentCrew || []).length" class="text-sm text-slate-500 dark:text-slate-400 text-center py-2">
                  Aucun équipage assigné.
                </div>
                <div v-for="assignment in (selectedVehicle.current_crew || selectedVehicle.currentCrew || [])" :key="assignment.id" 
                  class="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-950/20 rounded-md border border-slate-100 dark:border-slate-800/40">
                  <div class="flex items-center gap-3">
                    <div :class="[
                      'w-8 h-8 rounded-full flex items-center justify-center shrink-0',
                      assignment.role === 'driver' ? 'bg-emerald-100' : 'bg-slate-200'
                    ]">
                      <component
                        :is="assignment.role === 'driver' ? Steering : SeatPassenger"
                        :class="assignment.role === 'driver' ? 'text-emerald-600' : 'text-slate-600 dark:text-slate-350 dark:text-slate-350'"
                        :size="16"
                      />
                    </div>
                    <div>
                      <p class="text-sm font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ assignment.crew_member?.name || 'Inconnu' }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">{{ assignment.role === 'driver' ? 'Chauffeur' : 'Assistant' }}</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <button @click="endCrewAssignment(assignment.id)" class="text-rose-500 hover:text-rose-700 p-1 rounded hover:bg-rose-50" title="Clôturer l'affectation">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </div>
            </AccordionSection>

            <!-- Trips/Voyages Section -->
            <AccordionSection
              v-model:open="showTrips"
              :icon="Bus"
              title="Voyages"
              :count="selectedVehicle.trips_count || (selectedVehicle.trips || []).length"
            >
              <div class="space-y-2">
                <div v-if="!selectedVehicle.trips || selectedVehicle.trips.length === 0" class="text-sm text-slate-500 dark:text-slate-400 text-center py-2">
                  Aucun voyage avec ce véhicule.
                </div>
                <div
                  v-for="trip in selectedVehicle.trips"
                  :key="trip.id"
                  class="flex items-center justify-between p-2 bg-slate-50 dark:bg-slate-950/20 rounded-md border border-slate-100 dark:border-slate-800/40"
                >
                  <div class="flex items-center gap-3">
                    <Bus class="h-5 w-5 text-emerald-500" />
                    <div>
                      <p class="text-sm font-medium text-slate-800 dark:text-slate-200 dark:text-slate-200">{{ trip.route?.name || 'Route' }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ new Date(trip.departure_at).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                      </p>
                    </div>
                  </div>
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-medium',
                    getTripStatus(trip) === 'scheduled' ? 'bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300' :
                    getTripStatus(trip) === 'departed' ? 'bg-emerald-100 text-emerald-800' :
                    getTripStatus(trip) === 'arrived' ? 'bg-emerald-100 text-emerald-800' :
                    getTripStatus(trip) === 'cancelled' ? 'bg-rose-100 text-rose-800' :
                    getTripStatus(trip) === 'expired' ? 'bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300' :
                    'bg-slate-100 text-slate-700 dark:text-slate-300 dark:text-slate-300'
                  ]">
                    {{ getTripStatus(trip) === 'scheduled' ? 'Programmé' :
                       getTripStatus(trip) === 'departed' ? 'Effectué' :
                       getTripStatus(trip) === 'arrived' ? 'Arrivé' :
                       getTripStatus(trip) === 'cancelled' ? 'Annulé' :
                       getTripStatus(trip) === 'expired' ? 'Passé' :
                       trip.status }}
                  </span>
                </div>
              </div>
            </AccordionSection>
          </div>
        </div>
      </div>
    </div>

    <!-- Vehicle Create/Edit Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier le Véhicule' : 'Nouveau Véhicule' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="identifier" value="Numéro d'identification" />
            <TextInput v-model="form.identifier" id="identifier" class="w-full" placeholder="Ex: 1234 AB 01" />
            <InputError :message="errors.identifier" />
          </div>

          <div>
            <InputLabel for="maker" value="Fabricant" />
            <TextInput v-model="form.maker" id="maker" class="w-full" placeholder="Ex: Toyota" />
            <InputError :message="errors.maker" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="vehicle_type_id" value="Type de Véhicule" />
              <select
                id="vehicle_type_id"
                v-model="form.vehicle_type_id"
                class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
                required
              >
                <option value="">Sélectionner...</option>
                <option
                  v-for="type in vehicleTypes"
                  :key="type.id"
                  :value="type.id"
                >
                  {{ type.name }} ({{ type.seat_count }} pl.)
                </option>
              </select>
              <InputError :message="errors.vehicle_type_id" />
            </div>
            <div>
              <InputLabel for="seat_count" value="Nombre de Places" />
              <TextInput
                v-model="form.seat_count"
                id="seat_count"
                type="number"
                class="w-full bg-gray-100 font-bold cursor-not-allowed"
                readonly
                placeholder="Dérivé du type..."
              />
              <InputError :message="errors.seat_count" />
            </div>
          </div>

          <div class="flex items-center">
            <label class="flex items-center text-sm text-gray-700 dark:text-slate-300 dark:text-slate-300 cursor-pointer">
              <input v-model="form.active" type="checkbox" class="rounded border-gray-300 text-emerald-600" />
              <span class="ml-2">Véhicule Actif</span>
            </label>
          </div>

          <div>
            <InputLabel for="insurance_expiry_date" value="Date d'expiration de l'assurance" />
            <AppDatePicker v-model="form.insurance_expiry_date" id="insurance_expiry_date" class="w-full" />
            <InputError :message="errors.insurance_expiry_date" />
          </div>

          <div v-if="!form.active">
            <InputLabel for="inactive_reason" value="Motif d'inactivité" />
            <textarea
              id="inactive_reason"
              v-model="form.inactive_reason"
              rows="3"
              class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              placeholder="Expliquez pourquoi le véhicule est inactif (panne, garage, etc.)"
              required
            ></textarea>
            <InputError :message="errors.inactive_reason" />
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

    <!-- Add Crew Assignment Modal -->
    <DialogModal :show="showCrewModal" @close="closeCrewModal" maxWidth="md">
      <template #title>
        Assigner un membre d'équipage à {{ selectedVehicle?.identifier }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
            <strong>Note :</strong> Si ce véhicule a déjà un {{ crewForm.role === 'driver' ? 'chauffeur' : 'assistant' }} en cours, l'ancienne affectation sera automatiquement clôturée.
          </div>

          <div>
            <InputLabel for="crew_role" value="Rôle" />
            <select
              id="crew_role"
              v-model="crewForm.role"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
            >
              <option value="driver">Chauffeur</option>
              <option value="assistant">Assistant</option>
            </select>
            <InputError :message="errors.role" />
          </div>

          <div>
            <InputLabel for="crew_member_id" :value="crewForm.role === 'driver' ? 'Chauffeur' : 'Assistant'" />
            <select
              id="crew_member_id"
              v-model="crewForm.crew_member_id"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="">Sélectionner...</option>
              <option v-for="member in filteredCrewForVehicleForm" :key="member.id" :value="member.id">
                {{ member.name }} {{ member.phone ? `(${member.phone})` : '' }}
              </option>
            </select>
            <InputError :message="errors.crew_member_id" />
          </div>

          <div>
            <InputLabel for="assigned_from" value="Date de début d'affectation" />
            <TextInput v-model="crewForm.assigned_from" id="assigned_from" type="datetime-local" class="w-full" />
            <InputError :message="errors.assigned_from" />
          </div>

          <div>
            <InputLabel for="crew_notes" value="Notes (optionnel)" />
            <textarea
              id="crew_notes"
              v-model="crewForm.notes"
              rows="2"
              class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              placeholder="Notes concernant l'affectation..."
            ></textarea>
            <InputError :message="errors.notes" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeCrewModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submitCrewAssignment" :disabled="processing">
          Assigner
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
