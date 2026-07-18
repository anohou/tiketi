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
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Steering from 'vue-material-design-icons/Steering.vue';
import SeatPassenger from 'vue-material-design-icons/SeatPassenger.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue';

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
});

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

const filteredVehicles = computed(() => {
  const vehicles = props.vehicles?.data || [];
  if (!search.value) return vehicles;

  const searchTerm = search.value.toLowerCase();
  return vehicles.filter(vehicle =>
    String(vehicle.identifier || '').toLowerCase().includes(searchTerm) ||
    String(vehicle.maker || '').toLowerCase().includes(searchTerm) ||
    String(vehicle.vehicle_type?.name || '').toLowerCase().includes(searchTerm)
  );
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
  showTrips.value = false;
  showCrew.value = false;
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

const endCrewAssignment = (assignmentId) => {
  if (confirm("Clôturer cette affectation ? L'historique sera conservé.")) {
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

const deleteVehicle = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer ce véhicule ?')) {
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
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
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
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        vehicle.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ vehicle.active ? 'Actif' : 'Inactif' }}
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
                <div class="col-span-12" v-if="!selectedVehicle.active">
                  <div class="p-4 rounded-lg bg-rose-50 border border-rose-100">
                    <span class="text-xs text-rose-600 uppercase tracking-wider font-bold block mb-1">MOTIF D'INACTIVITÉ</span>
                    <p class="text-rose-800">{{ selectedVehicle.inactive_reason || 'Raison non spécifiée' }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Crew Section -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
              <div @click="showCrew = !showCrew" class="p-3 bg-gray-50 flex items-center justify-between cursor-pointer hover:bg-gray-100">
                <div class="flex items-center gap-2">
                  <AccountHardHat class="h-5 w-5 text-emerald-600" />
                  <h3 class="font-semibold text-gray-700 dark:text-slate-300 dark:text-slate-300">
                    Équipage Actif ({{ (selectedVehicle.current_crew || selectedVehicle.currentCrew || []).length }})
                  </h3>
                </div>
                <div class="flex items-center gap-2">
                    <button @click.stop="openAddCrewModal" class="p-1 bg-emerald-100 text-emerald-700 rounded hover:bg-emerald-200" title="Assigner un équipage">
                        <Plus class="h-4 w-4" />
                    </button>
                    <component :is="showCrew ? ChevronDown : ChevronRight" class="h-5 w-5 text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500" />
                </div>
              </div>
              
              <div v-if="showCrew" class="p-4 border-t border-slate-100">
                <div class="space-y-2">
                  <div v-if="!(selectedVehicle.current_crew || selectedVehicle.currentCrew || []).length" class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 text-center py-2">
                    Aucun équipage assigné.
                  </div>
                  <div v-for="assignment in (selectedVehicle.current_crew || selectedVehicle.currentCrew || [])" :key="assignment.id" 
                    class="flex items-center justify-between p-2 bg-gray-50 rounded-md border border-gray-100">
                    <div class="flex items-center gap-3">
                      <div :class="[
                        'w-8 h-8 rounded-full flex items-center justify-center shrink-0',
                        assignment.role === 'driver' ? 'bg-blue-100' : 'bg-purple-100'
                      ]">
                        <component
                          :is="assignment.role === 'driver' ? Steering : SeatPassenger"
                          :class="assignment.role === 'driver' ? 'text-blue-600' : 'text-purple-600'"
                          :size="16"
                        />
                      </div>
                      <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ assignment.crew_member?.name || 'Inconnu' }}</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">{{ assignment.role === 'driver' ? 'Chauffeur' : 'Assistant' }}</p>
                      </div>
                    </div>
                    <div class="flex items-center gap-2">
                      <button @click="endCrewAssignment(assignment.id)" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Clôturer l'affectation">
                        <CloseCircle class="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
              <div @click="showTrips = !showTrips" class="p-3 bg-gray-50 flex items-center justify-between cursor-pointer hover:bg-gray-100">
                <div class="flex items-center gap-2">
                  <Bus class="h-5 w-5 text-blue-600" />
                  <h3 class="font-semibold text-gray-700 dark:text-slate-300 dark:text-slate-300">
                    Voyages ({{ selectedVehicle.trips_count || (selectedVehicle.trips || []).length }})
                  </h3>
                </div>
                <component :is="showTrips ? ChevronDown : ChevronRight" class="h-5 w-5 text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500" />
              </div>

              <div v-if="showTrips" class="p-4 border-t border-slate-100">
                <div class="space-y-2">
                  <div v-if="!selectedVehicle.trips || selectedVehicle.trips.length === 0" class="text-sm text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 text-center py-2">
                    Aucun voyage avec ce véhicule.
                  </div>
                  <div
                    v-for="trip in selectedVehicle.trips"
                    :key="trip.id"
                    class="flex items-center justify-between p-2 bg-gray-50 rounded-md border border-gray-100"
                  >
                    <div class="flex items-center gap-3">
                      <Bus class="h-5 w-5 text-blue-500" />
                      <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ trip.route?.name || 'Route' }}</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">
                          {{ new Date(trip.departure_at).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                        </p>
                      </div>
                    </div>
                    <span :class="[
                      'px-2 py-0.5 rounded-full text-[10px] font-medium',
                      getTripStatus(trip) === 'scheduled' ? 'bg-blue-100 text-blue-800' :
                      getTripStatus(trip) === 'departed' ? 'bg-purple-100 text-purple-800' :
                      getTripStatus(trip) === 'arrived' ? 'bg-emerald-100 text-emerald-800' :
                      getTripStatus(trip) === 'cancelled' ? 'bg-rose-100 text-rose-800' :
                      getTripStatus(trip) === 'expired' ? 'bg-gray-100 text-gray-800 dark:text-slate-200 dark:text-slate-200' :
                      'bg-gray-100 text-gray-800 dark:text-slate-200 dark:text-slate-200'
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
              </div>
            </div>
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
            <TextInput v-model="form.insurance_expiry_date" id="insurance_expiry_date" type="date" class="w-full" />
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