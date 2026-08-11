<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import GpsMapPicker from '@/Components/GpsMapPicker.vue';
import StationFormModal from '@/Components/StationFormModal.vue';
import DepartureScheduleFormModal from '@/Components/DepartureScheduleFormModal.vue';
import DialogModal from '@/Components/DialogModal.vue';
import AccordionSection from '@/Components/UI/AccordionSection.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RouteSchemaDiagram from '@/Components/RouteSchemaDiagram.vue';
import { toastStore } from '@/Stores/toastStore.js';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import Account from 'vue-material-design-icons/Account.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import BusClock from 'vue-material-design-icons/BusClock.vue';
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue';
import { Link } from '@inertiajs/vue3';
import { FULL_PERMISSIONS } from '@/Support/permissions.js';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  stations: {
    type: Object,
    default: () => ({ data: [] })
  },
  destinations: {
    type: Array, // Passed from controller
    default: () => []
  },
  vehicles: {
    type: Array,
    default: () => []
  },
  departureSchedules: {
    type: Array,
    default: () => []
  },
  stationOptions: {
    type: Array,
    default: () => []
  },
  routeOptions: {
    type: Array,
    default: () => []
  },
  vehicleTypes: {
    type: Array,
    default: () => []
  },
  sellerOptions: {
    type: Array,
    default: () => []
  },
  permissions: {
    type: Object,
    default: () => ({ ...FULL_PERMISSIONS })
  },
  hideTripSidebar: {
    type: Boolean,
    default: false
  }
});

// State
const search = ref('');
const selectedStation = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const showRouteDiagramModal = ref(false);
const selectedRouteDiagram = ref(null);
const showQuickRouteModal = ref(false);
const showQuickScheduleModal = ref(false);
const showQuickSellerModal = ref(false);
const showQuickVehicleModal = ref(false);
const quickProcessing = ref(false);
const quickErrors = ref({});

const quickRouteForm = ref({
  name: '',
  origin_destination_id: '',
  target_destination_id: '',
  estimated_duration_minutes: 120,
  automatic_connection_allocation: null,
  active: true,
});

const quickScheduleForm = ref({
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
});

const quickSellerForm = ref({ user_id: '' });
const quickVehicleForm = ref({
  vehicle_id: '',
  permanent: true,
  valid_from: new Date().toISOString().slice(0, 10),
  valid_until: new Date().toISOString().slice(0, 10),
  active: true,
  notes: '',
});

// Accordéons : tous pliés par défaut pour permettre de voir l'ensemble des sous-menus au premier coup d'œil
const showDestinations = ref(false);
const showRoutes = ref(false);
const showSchedules = ref(false);
const showSellers = ref(false);
const showAssignedVehicles = ref(false);
const showCurrentPool = ref(false);

const resetAccordions = () => {
  showDestinations.value = false;
  showRoutes.value = false;
  showSchedules.value = false;
  showSellers.value = false;
  showAssignedVehicles.value = false;
  showCurrentPool.value = false;
};

const form = ref({
  code: '',
  name: '',
  destination_id: '',
  city: '',
  address: '',
  latitude: '',
  longitude: '',
  active: true,
  can_sell_tickets: true
});

const hasValidCoordinates = (item) => {
  if (!item || item.latitude === null || item.latitude === '' || item.latitude === undefined) {
    return false;
  }

  if (item.longitude === null || item.longitude === '' || item.longitude === undefined) {
    return false;
  }

  return Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude));
};

const stationPreviewCoordinates = computed(() => {
  if (!selectedStation.value) {
    return { latitude: '', longitude: '' };
  }

  return {
    latitude: selectedStation.value.latitude ?? '',
    longitude: selectedStation.value.longitude ?? '',
  };
});

const mapCenter = computed(() => {
  if (selectedStation.value) {
    return {
      latitude: Number(selectedStation.value.latitude) || 7.177201,
      longitude: Number(selectedStation.value.longitude) || -5.635986
    };
  }
  return { latitude: 7.177201, longitude: -5.635986 };
});

const mapZoom = computed(() => {
  return selectedStation.value ? 13 : 6;
});

const stationPreviewPoints = computed(() => {
  return (props.stations.data || [])
    .filter(hasValidCoordinates)
    .map((station) => ({
      latitude: station.latitude,
      longitude: station.longitude,
      label: `${station.name}${station.city ? ` - ${station.city}` : ''}`,
    }));
});

const selectedDestinationStations = computed(() => {
  const destination = props.destinations.find((item) => item.id === form.value.destination_id);
  const stations = destination?.stations || [];

  return stations
    .filter(hasValidCoordinates)
    .filter((station) => station.id !== form.value.id)
    .map((station) => ({
      latitude: station.latitude,
      longitude: station.longitude,
      label: `${station.name}${station.city ? ` - ${station.city}` : ''}`,
    }));
});

const statusConfig = {
  in_transit: { label: 'En voyage', badge: 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300', dot: 'bg-purple-500' },
  scheduled: { label: 'Programmé', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300', dot: 'bg-blue-500' },
  available: { label: 'Disponible', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', dot: 'bg-emerald-500' },
  inactive: { label: 'En panne', badge: 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300', dot: 'bg-rose-500' },
};

const stationDepartureSchedules = computed(() => {
  if (!selectedStation.value) return [];
  const stationId = selectedStation.value.id;
  return (props.departureSchedules || []).filter((s) => s.station_id === stationId);
});

const availableSellerOptions = computed(() => {
  const assignedIds = new Set((selectedStation.value?.user_assignments || [])
    .map((assignment) => assignment.user_id || assignment.user?.id));

  return props.sellerOptions.filter((seller) => !assignedIds.has(seller.id));
});

const quickScheduleRouteOptions = computed(() => {
  const stationRouteIds = new Set(allRoutes.value.map((routeItem) => routeItem.id));
  return props.routeOptions.filter((routeItem) => stationRouteIds.has(routeItem.id));
});

const quickScheduleDestinationOptions = computed(() => {
  const selectedRoute = props.routeOptions.find((item) => item.id === quickScheduleForm.value.route_id);
  if (!selectedRoute) return props.stationOptions.filter((station) => station.id !== selectedStation.value?.id);

  const candidates = [
    selectedRoute.origin_station,
    selectedRoute.originStation,
    selectedRoute.destination_station,
    selectedRoute.destinationStation,
    ...(selectedRoute.route_stop_orders || selectedRoute.routeStopOrders || []).map((order) => order.station),
  ].filter(Boolean);
  const unique = new Map(candidates.map((station) => [station.id, station]));

  return [...unique.values()].filter((station) => station.id !== selectedStation.value?.id);
});

const closeQuickModal = (modal) => {
  modal.value = false;
  quickProcessing.value = false;
  quickErrors.value = {};
};

const closeQuickRoute = () => closeQuickModal(showQuickRouteModal);
const closeQuickSchedule = () => closeQuickModal(showQuickScheduleModal);
const closeQuickSeller = () => closeQuickModal(showQuickSellerModal);
const closeQuickVehicle = () => closeQuickModal(showQuickVehicleModal);

const openQuickRouteModal = () => {
  if (!selectedStation.value) return;
  quickRouteForm.value = {
    name: '',
    origin_destination_id: selectedStation.value.destination_id || '',
    target_destination_id: '',
    estimated_duration_minutes: 120,
    automatic_connection_allocation: null,
    active: true,
  };
  quickErrors.value = {};
  showQuickRouteModal.value = true;
};

const submitQuickRoute = () => {
  quickProcessing.value = true;
  quickErrors.value = {};
  router.post(route('admin.routes.store'), quickRouteForm.value, {
    preserveScroll: true,
    onSuccess: () => {
      closeQuickModal(showQuickRouteModal);
      toastStore.success('Trajet créé avec succès.');
    },
    onError: (validationErrors) => {
      quickErrors.value = validationErrors;
      quickProcessing.value = false;
    },
    onFinish: () => { quickProcessing.value = false; },
  });
};

const openQuickScheduleModal = () => {
  if (!selectedStation.value) return;
  quickScheduleForm.value = {
    station_id: selectedStation.value.id,
    route_id: '',
    origin_station_id: selectedStation.value.id,
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
  quickErrors.value = {};
  showQuickScheduleModal.value = true;
};

const submitQuickSchedule = () => {
  quickProcessing.value = true;
  quickErrors.value = {};
  const payload = {
    ...quickScheduleForm.value,
    planned_capacity: quickScheduleForm.value.planned_capacity === '' ? null : Number(quickScheduleForm.value.planned_capacity),
    confirmed_return_quota: quickScheduleForm.value.confirmed_return_quota === '' ? null : Number(quickScheduleForm.value.confirmed_return_quota),
    vehicle_assignment_policy: quickScheduleForm.value.vehicle_assignment_policy || null,
    valid_until: quickScheduleForm.value.valid_until || null,
  };

  router.post(route('admin.departure-schedules.store'), payload, {
    preserveScroll: true,
    onSuccess: () => {
      closeQuickModal(showQuickScheduleModal);
      toastStore.success('Programme de départ créé.');
    },
    onError: (validationErrors) => {
      quickErrors.value = validationErrors;
      quickProcessing.value = false;
    },
    onFinish: () => { quickProcessing.value = false; },
  });
};

const openQuickSellerModal = () => {
  quickSellerForm.value = { user_id: '' };
  quickErrors.value = {};
  showQuickSellerModal.value = true;
};

const submitQuickSeller = () => {
  if (!selectedStation.value) return;
  quickProcessing.value = true;
  quickErrors.value = {};
  router.post(route('admin.assignments.store'), {
    user_id: quickSellerForm.value.user_id,
    station_id: selectedStation.value.id,
    active: true,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      closeQuickModal(showQuickSellerModal);
      toastStore.success('Vendeur affecté à la gare.');
    },
    onError: (validationErrors) => {
      quickErrors.value = validationErrors;
      quickProcessing.value = false;
    },
    onFinish: () => { quickProcessing.value = false; },
  });
};

const openQuickVehicleModal = () => {
  quickVehicleForm.value = {
    vehicle_id: '',
    permanent: true,
    valid_from: new Date().toISOString().slice(0, 10),
    valid_until: new Date().toISOString().slice(0, 10),
    active: true,
    notes: '',
  };
  quickErrors.value = {};
  showQuickVehicleModal.value = true;
};

const submitQuickVehicle = () => {
  if (!selectedStation.value) return;
  quickProcessing.value = true;
  quickErrors.value = {};
  router.post(route('fleet.station-vehicle-assignments.store'), {
    ...quickVehicleForm.value,
    station_id: selectedStation.value.id,
  }, {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'vehicleAssignment',
    onSuccess: (page) => {
      const pageErrors = page?.props?.errors || {};
      const returnedErrors = pageErrors.vehicleAssignment || pageErrors;
      if (Object.keys(returnedErrors).length > 0) {
        quickErrors.value = returnedErrors;
        showQuickVehicleModal.value = true;
        toastStore.error(returnedErrors.vehicle_id || 'Impossible d’affecter ce véhicule à la gare.');
        return;
      }
      closeQuickModal(showQuickVehicleModal);
      toastStore.success('Véhicule ajouté au pool de la gare.');
    },
    onError: (validationErrors) => {
      quickErrors.value = validationErrors;
      showQuickVehicleModal.value = true;
      quickProcessing.value = false;
      toastStore.error(validationErrors.vehicle_id || 'Impossible d’affecter ce véhicule à la gare.');
    },
    onFinish: () => { quickProcessing.value = false; },
  });
};

const assignedVehicles = computed(() => {
  if (!selectedStation.value) return [];
  const stationId = selectedStation.value.id;
  return (props.vehicles || []).filter((v) => v.current_station_assignment?.station_id === stationId);
});

const currentPoolVehicles = computed(() => {
  if (!selectedStation.value) return [];
  const stationId = selectedStation.value.id;
  return (props.vehicles || []).filter((v) => {
    const currLocId = v.operational?.current_location?.id;
    const homeStationId = v.current_station_assignment?.station_id;
    return currLocId === stationId || (!currLocId && homeStationId === stationId);
  });
});

// Computed
const filteredStations = computed(() => {
  const stations = props.stations?.data || [];
  if (!search.value) return stations;

  const searchTerm = search.value.toLowerCase();
  return stations.filter(station =>
    station.name.toLowerCase().includes(searchTerm) ||
    station.code?.toLowerCase().includes(searchTerm) ||
    station.city?.toLowerCase().includes(searchTerm)
  );
});

// Get all unique destinations that can be served from/to this station (bidirectional)
const servedDestinations = computed(() => {
  if (!selectedStation.value) return [];
  
  const destinationsMap = new Map();

  const addDestination = (station) => {
    if (!station || station.id === selectedStation.value.id) return;

    if (!destinationsMap.has(station.id)) {
      destinationsMap.set(station.id, {
        id: station.id,
        name: station.name,
        city: station.city || 'N/A'
      });
    }
  };

  const stationStops = selectedStation.value.route_stop_orders || selectedStation.value.routeStopOrders || [];

  stationStops.forEach((stopOrder) => {
    const route = stopOrder.route;
    if (!route) return;

    const allStopOrders = route.route_stop_orders || route.routeStopOrders || [];
    allStopOrders.forEach((order) => addDestination(order.station));
    addDestination(route.originStation);
    addDestination(route.destinationStation);
  });

  const directRoutes = [
    ...(selectedStation.value.origin_routes || selectedStation.value.originRoutes || []),
    ...(selectedStation.value.destination_routes || selectedStation.value.destinationRoutes || [])
  ];

  directRoutes.forEach((route) => {
    addDestination(route.originStation);
    addDestination(route.destinationStation);
    (route.route_stop_orders || route.routeStopOrders || []).forEach((order) => addDestination(order.station));
  });

  // Sort alphabetically by name
  return Array.from(destinationsMap.values()).sort((a, b) => {
    return a.name.localeCompare(b.name, 'fr');
  });
});

// Get all routes that pass through this station (via its stops and direct endpoints)
const allRoutes = computed(() => {
  if (!selectedStation.value) return [];

  const routesMap = new Map();
  const stationStops = selectedStation.value.route_stop_orders || selectedStation.value.routeStopOrders || [];
  const directRoutes = [
    ...(selectedStation.value.origin_routes || selectedStation.value.originRoutes || []),
    ...(selectedStation.value.destination_routes || selectedStation.value.destinationRoutes || [])
  ];

  stationStops.forEach((stopOrder) => {
    const route = stopOrder.route;
    if (!route || routesMap.has(route.id)) return;

    routesMap.set(route.id, {
      id: route.id,
      name: route.name,
      origin: route.origin_station?.name || route.originStation?.name || 'N/A',
      destination: route.destination_station?.name || route.destinationStation?.name || 'N/A',
      active: route.active,
      stops: route.route_stop_orders || route.routeStopOrders || [],
      route_stop_orders: route.route_stop_orders || route.routeStopOrders || [],
      routeStopOrders: route.route_stop_orders || route.routeStopOrders || []
    });
  });

  directRoutes.forEach((route) => {
    if (!route || routesMap.has(route.id)) return;

    routesMap.set(route.id, {
      id: route.id,
      name: route.name,
      origin: route.origin_station?.name || route.originStation?.name || 'N/A',
      destination: route.destination_station?.name || route.destinationStation?.name || 'N/A',
      active: route.active,
      stops: route.route_stop_orders || route.routeStopOrders || [],
      route_stop_orders: route.route_stop_orders || route.routeStopOrders || [],
      routeStopOrders: route.route_stop_orders || route.routeStopOrders || []
    });
  });

  return Array.from(routesMap.values()).sort((a, b) => a.name.localeCompare(b.name, 'fr'));
});

const routeDiagramStops = computed(() => {
  if (!selectedRouteDiagram.value) return [];

  const routeStops = selectedRouteDiagram.value.stops
    || selectedRouteDiagram.value.route_stop_orders
    || selectedRouteDiagram.value.routeStopOrders
    || [];

  return [...routeStops]
    .sort((a, b) => {
      const aIndex = Number(a.stop_index ?? a.stopIndex ?? 0);
      const bIndex = Number(b.stop_index ?? b.stopIndex ?? 0);
      return aIndex - bIndex;
    })
    .map((order, index) => {
      const station = order.station || {};

      return {
        id: order.id || station.id || `${selectedRouteDiagram.value.id}-${index}`,
        name: station.name || 'Arrêt',
        code: station.code || '',
        isConsulted: selectedStation.value?.id && station.id === selectedStation.value.id,
      };
    });
});

const routeDiagramNodes = computed(() => {
  const stops = routeDiagramStops.value;
  const usableWidth = 880;
  const step = stops.length > 1 ? usableWidth / (stops.length - 1) : 0;
  const compact = stops.length > 6;
  const mini = stops.length > 10;
  const labelSize = mini ? 10 : compact ? 11 : 13;
  const maxNameLength = mini ? 12 : compact ? 16 : 22;

  const shorten = (value, limit) => {
    if (!value) return '';
    return value.length > limit ? `${value.slice(0, Math.max(0, limit - 1))}…` : value;
  };

  return stops.map((stop, index) => ({
    ...stop,
    x: 60 + (index * step),
    labelY: index % 2 === 0 ? 82 : 170,
    labelSize,
    displayName: shorten(stop.name, maxNameLength),
    isFirst: index === 0,
    isLast: index === stops.length - 1,
  }));
});

// Watchers
watch(() => props.stations, (newStations) => {
  if (selectedStation.value) {
    const updatedStation = newStations.data.find(s => s.id === selectedStation.value.id);
    if (updatedStation) {
      selectedStation.value = updatedStation;
    }
  }
}, { deep: true });

// Reset accordions only when switching to another station, not when an
// inline create refreshes the selected station's data.
watch(() => selectedStation.value?.id, () => {
  resetAccordions();
  closeRouteDiagramModal();
});

// Methods
const isSelected = (station) => {
  if (!selectedStation.value) return false;
  return selectedStation.value.id === station.id;
};

const selectStation = (station) => {
  selectedStation.value = station;
};

const openRouteDiagramModal = (route) => {
  selectedRouteDiagram.value = route;
  showRouteDiagramModal.value = true;
};

const closeRouteDiagramModal = () => {
  showRouteDiagramModal.value = false;
  selectedRouteDiagram.value = null;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    code: '',
    name: '',
    destination_id: '', // New field
    city: '',
    address: '',
    latitude: '',
    longitude: '',
    active: true,
    can_sell_tickets: true
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedStation.value) return;
  isEditing.value = true;
  const destination = props.destinations.find((item) => item.id === selectedStation.value.destination_id);
  const isDestinationOnlyStation = (destination?.stations || [])
    .filter((station) => station.id !== selectedStation.value.id)
    .length === 0;
  const defaultLatitude = isDestinationOnlyStation ? destination?.latitude : '';
  const defaultLongitude = isDestinationOnlyStation ? destination?.longitude : '';
  const stationLatitude = selectedStation.value.latitude;
  const stationLongitude = selectedStation.value.longitude;
  form.value = {
    code: selectedStation.value.code,
    name: selectedStation.value.name,
    destination_id: selectedStation.value.destination_id, // Load existing
    city: selectedStation.value.city,
    address: selectedStation.value.address || '',
    latitude: stationLatitude !== null && stationLatitude !== '' ? stationLatitude : (defaultLatitude ?? ''),
    longitude: stationLongitude !== null && stationLongitude !== '' ? stationLongitude : (defaultLongitude ?? ''),
    active: selectedStation.value.active,
    can_sell_tickets: selectedStation.value.can_sell_tickets !== false
  };
  errors.value = {};
  showModal.value = true;
};

const duplicateStation = () => {
  if (!selectedStation.value) return;
  isEditing.value = false;
  form.value = {
    code: selectedStation.value.code + '-COPY',
    name: selectedStation.value.name + ' (Copie)',
    destination_id: selectedStation.value.destination_id,
    city: selectedStation.value.city,
    address: selectedStation.value.address || '',
    latitude: selectedStation.value.latitude ?? '',
    longitude: selectedStation.value.longitude ?? '',
    active: true,
    can_sell_tickets: selectedStation.value.can_sell_tickets !== false
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    code: '',
    name: '',
    destination_id: '',
    city: '',
    address: '',
    latitude: '',
    longitude: '',
    active: true,
    can_sell_tickets: true
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.stations.update', selectedStation.value.id)
    : route('admin.stations.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const showDeleteModal = ref(false);
const stationIdToDelete = ref(null);

const confirmDeleteStation = (id) => {
  stationIdToDelete.value = id;
  showDeleteModal.value = true;
};

const deleteStation = () => {
  if (!stationIdToDelete.value) return;
  showDeleteModal.value = false;
  router.delete(route('admin.stations.destroy', stationIdToDelete.value), {
    onSuccess: () => {
      if (selectedStation.value?.id === stationIdToDelete.value) {
        selectedStation.value = null;
      }
      toastStore.success('Station supprimée avec succès');
      stationIdToDelete.value = null;
    },
    onError: (errorResponse) => {
      let errorMessage = 'Impossible de supprimer cette station.';
      if (errorResponse.message) {
        errorMessage = errorResponse.message;
      } else if (errorResponse.error) {
        errorMessage = errorResponse.error;
      }
      toastStore.error(errorMessage);
      stationIdToDelete.value = null;
    }
  });
};

// Export/Print configuration
const stationColumns = {
  code: 'Code',
  name: 'Nom',
  city: 'Ville',
  address: 'Adresse',
  active: 'Statut',
  user_assignments_count: 'Vendeurs'
};

const handleExport = () => {
  const data = filteredStations.value.map(s => ({
    ...s,
    active: s.active ? 'Actif' : 'Inactif'
  }));
  exportToExcel(data, stationColumns, 'stations');
};

const handlePrint = () => {
  const data = filteredStations.value.map(s => ({
    ...s,
    active: s.active ? 'Actif' : 'Inactif'
  }));
  printList(data, stationColumns, 'Liste des Stations');
};
</script>

<template>
  <MainNavLayout :fullHeight="true" :hide-trip-sidebar="hideTripSidebar">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <OfficeBuilding class="text-emerald-600" :size="28" />
            </div>
            Gestion des Gares / Destinations
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres des lieux de prise en charge et dépose</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Stations List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
             <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button v-if="permissions.canCreate" @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouvelle Station">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  v-if="permissions.canExport"
                  :disabled="filteredStations.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>

            </div>

            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredStations.length === 0" class="p-4">
                <EmptyState
                  title="Aucune gare trouvée"
                  :message="permissions.canCreate ? 'Vous pouvez en créer une en cliquant sur le bouton +' : 'Aucune gare ne correspond à votre recherche'"
                  :icon="OfficeBuilding"
                />
              </div>
              <div v-else>
                <div v-for="station in filteredStations" :key="station.id" 
                  @click="selectStation(station)"
                  class="p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(station) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h3 :class="['basis-full whitespace-normal break-words font-semibold leading-snug', isSelected(station) ? 'text-green-800' : 'text-slate-800 dark:text-slate-200']">{{ station.name }}</h3>
                        <MapMarkerRadius
                          v-if="hasValidCoordinates(station)"
                          class="text-emerald-500 dark:text-emerald-400 shrink-0"
                          :size="14"
                          title="Coordonnées GPS disponibles"
                        />
                        <span v-if="station.code" class="px-1.5 py-0.5 bg-slate-100 dark:bg-slate-850 text-slate-700 dark:text-slate-300 text-[10px] font-bold rounded uppercase tracking-wider shrink-0 border border-slate-200 dark:border-slate-800">{{ station.code }}</span>
                        <span :class="['px-1.5 py-0.5 text-[10px] font-bold rounded uppercase tracking-wider shrink-0 border', station.can_sell_tickets !== false ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200']">
                          {{ station.can_sell_tickets !== false ? 'Vente' : 'Arrêt' }}
                        </span>
                      </div>
                      <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ station.city }}
                      </p>
                      <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">
                        {{ station.destination?.name || 'Destination non liée' }}
                      </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                      <span class="text-xs text-emerald-600">{{ station.user_assignments_count || 0 }} vendeurs</span>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        station.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ station.active ? 'Active' : 'Inactive' }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <div class="space-y-3">
          <div v-if="selectedStation" class="space-y-3">
            <!-- Details Card (always visible) -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
              <div class="flex justify-between items-start mb-3 gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-200 truncate">{{ selectedStation.name }}</h2>
                    <span
                      :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                        selectedStation.can_sell_tickets !== false ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                      ]"
                    >
                      {{ selectedStation.can_sell_tickets !== false ? 'Vend billets' : 'Simple arrêt' }}
                    </span>
                  </div>
                </div>
                <div class="flex gap-2">
                  <div class="flex items-center gap-2">
                    <span :class="[
                      'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                      selectedStation.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ selectedStation.active ? 'Active' : 'Inactive' }}
                    </span>
                    <div v-if="permissions.canCreate || permissions.canUpdate || permissions.canDelete" class="flex items-center gap-2">
                      <button v-if="permissions.canCreate" @click="duplicateStation" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Dupliquer">
                        <ContentCopy class="h-5 w-5" />
                      </button>
                      <button v-if="permissions.canUpdate" @click="openEditModal" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Modifier">
                        <Pencil class="h-5 w-5" />
                      </button>
                      <button v-if="permissions.canDelete" @click="confirmDeleteStation(selectedStation.id)" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Supprimer">
                        <Trash2 class="h-5 w-5" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Details Grid -->
              <div class="grid grid-cols-1 gap-x-4 gap-y-3 border-t border-slate-100 pt-3 dark:border-slate-800/50 sm:grid-cols-3">
                <div>
                  <span class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">VILLE</span>
                  <div class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ selectedStation.destination?.name || 'Non liée' }}</div>
                </div>
                <div>
                  <span class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">CODE</span>
                  <div class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ selectedStation.code || '—' }}</div>
                </div>
                <div>
                  <span class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">QUARTIER / NOM PRÉCIS</span>
                  <div class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ selectedStation.city || '—' }}</div>
                </div>
                <div class="sm:col-span-3">
                  <span class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">ADRESSE</span>
                  <div class="text-sm text-slate-700 dark:text-slate-300">{{ selectedStation.address || 'Non renseignée' }}</div>
                </div>
              </div>
            </div>

            <!-- Sections liées : 5 accordéons verticaux (harmonisation plateforme) -->
            <div class="space-y-3">
              <!-- 1. Destinations desservies -->
              <AccordionSection
                v-model:open="showDestinations"
                :icon="OfficeBuilding"
                title="Destinations desservies"
                :count="servedDestinations.length"
              >
                <div v-if="servedDestinations.length === 0" class="text-center py-6 text-slate-400">
                  Aucune destination déduite à partir des trajets pour cette station
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="dest in servedDestinations"
                    :key="dest.id"
                    class="flex items-center p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                  >
                    <OfficeBuilding class="h-6 w-6 text-emerald-500 mr-3 shrink-0" />
                    <div class="min-w-0">
                      <p class="font-medium text-slate-800 dark:text-slate-200 truncate">{{ dest.name }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">{{ dest.city }}</p>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <!-- 2. Trajets passant par cette gare -->
              <AccordionSection
                v-model:open="showRoutes"
                :icon="Routes"
                title="Trajets passant par cette gare"
                :count="allRoutes.length"
                :can-add="permissions.canCreate"
                add-tooltip="Créer un nouveau trajet"
                @add="openQuickRouteModal"
              >
                <div v-if="allRoutes.length === 0" class="text-center py-6 text-slate-400">
                  Aucune route ne passe par cette gare
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="route in allRoutes"
                    :key="route.id"
                    class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg cursor-pointer transition-colors hover:bg-slate-100 dark:hover:bg-slate-900"
                    @click="openRouteDiagramModal(route)"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <Routes class="h-6 w-6 text-emerald-500 shrink-0" />
                      <div class="min-w-0">
                        <p class="font-medium text-slate-800 dark:text-slate-200 truncate">{{ route.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate">
                          {{ route.origin }} → {{ route.destination }}
                        </p>
                      </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-medium',
                        route.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ route.active ? 'Active' : 'Inactive' }}
                      </span>
                      <span class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                        Voir schéma
                      </span>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <!-- 3. Programmes de départ -->
              <AccordionSection
                v-model:open="showSchedules"
                :icon="CalendarClock"
                title="Programmes de départ"
                :count="stationDepartureSchedules.length"
                :can-add="permissions.canCreate"
                add-tooltip="Créer un programme pour cette gare"
                @add="openQuickScheduleModal"
              >
                <div v-if="stationDepartureSchedules.length === 0" class="text-center py-6 text-slate-400">
                  Aucun programme de départ configuré pour cette gare
                  <button v-if="permissions.canCreate" type="button" @click="openQuickScheduleModal" class="block mx-auto mt-2 text-xs font-bold text-emerald-600 hover:underline">
                    + Ajouter le premier programme
                  </button>
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="schedule in stationDepartureSchedules"
                    :key="schedule.id"
                    class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <CalendarClock class="h-6 w-6 text-emerald-500 shrink-0" />
                      <div class="min-w-0">
                        <p class="font-bold text-slate-900 dark:text-slate-100 truncate">
                          {{ schedule.departure_time?.slice(0, 5) }} — {{ schedule.origin_station?.name || selectedStation?.name || 'Gare de départ' }} → {{ schedule.destination_station?.name || 'Gare de destination' }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate">
                          Capacité : {{ schedule.planned_capacity ? schedule.planned_capacity + ' places' : '—' }}
                        </p>
                      </div>
                    </div>
                    <Link
                      :href="route('admin.departure-schedules.index', { station_id: selectedStation.id })"
                      class="text-xs font-bold text-emerald-700 hover:underline dark:text-emerald-300 shrink-0"
                    >
                      Gérer →
                    </Link>
                  </div>
                </div>
              </AccordionSection>

              <!-- 4. Vendeurs affectés -->
              <AccordionSection
                v-model:open="showSellers"
                :icon="Account"
                title="Vendeurs affectés"
                :count="(selectedStation.user_assignments || []).length"
                :can-add="permissions.canCreate"
                add-tooltip="Affecter un vendeur à cette gare"
                @add="openQuickSellerModal"
              >
                <div v-if="!selectedStation.user_assignments?.length" class="text-center py-6 text-slate-400">
                  Aucun vendeur affecté à cette station
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="assignment in selectedStation.user_assignments"
                    :key="assignment.id"
                    class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <Account class="h-8 w-8 text-emerald-500 shrink-0" />
                      <div class="min-w-0">
                        <p class="font-medium text-slate-800 dark:text-slate-200 truncate">{{ assignment.user?.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ assignment.user?.email }}</p>
                      </div>
                    </div>
                    <span :class="[
                      'px-2 py-0.5 rounded-full text-[10px] font-medium shrink-0',
                      assignment.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ assignment.active ? 'Actif' : 'Inactif' }}
                    </span>
                  </div>
                </div>
              </AccordionSection>

              <!-- 5. Cars rattachés - Gare d'attache -->
              <AccordionSection
                v-model:open="showAssignedVehicles"
                :icon="Bus"
                title="Cars rattachés - Gare d'attache"
                :count="assignedVehicles.length"
                :can-add="permissions.canCreate"
                add-tooltip="Affecter un véhicule à cette gare"
                @add="openQuickVehicleModal"
              >
                <div v-if="assignedVehicles.length === 0" class="text-center py-6 text-slate-400">
                  Aucun car rattaché à cette gare d'attache
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="vehicle in assignedVehicles"
                    :key="vehicle.id"
                    class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <Bus class="h-7 w-7 text-emerald-600 shrink-0" />
                      <div class="min-w-0">
                        <p class="font-bold text-slate-900 dark:text-slate-100 truncate">{{ vehicle.identifier }} <span class="text-xs font-normal text-slate-500">({{ vehicle.maker || 'Toyota' }})</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ vehicle.vehicle_type?.name }} · {{ vehicle.seat_count }} places</p>
                      </div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 shrink-0">
                      Gare d'attache
                    </span>
                  </div>
                </div>
              </AccordionSection>

              <!-- 5. Pool actuel en gare - Cars disponibles -->
              <AccordionSection
                v-model:open="showCurrentPool"
                :icon="BusClock"
                title="Pool actuel en gare - Cars disponibles"
                :count="currentPoolVehicles.length"
              >
                <div v-if="currentPoolVehicles.length === 0" class="text-center py-6 text-slate-400">
                  Aucun car présent dans le pool actuel de cette gare
                </div>
                <div v-else class="space-y-2">
                  <div
                    v-for="vehicle in currentPoolVehicles"
                    :key="vehicle.id"
                    class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-950 rounded-lg"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <BusClock class="h-7 w-7 text-blue-600 shrink-0" />
                      <div class="min-w-0">
                        <p class="font-bold text-slate-900 dark:text-slate-100 truncate">{{ vehicle.identifier }} <span class="text-xs font-normal text-slate-500">({{ vehicle.maker || 'Toyota' }})</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                          {{ vehicle.vehicle_type?.name }} · {{ vehicle.seat_count }} places
                          <template v-if="vehicle.operational?.current_location">
                            · 📍 {{ vehicle.operational.current_location.name }}
                          </template>
                        </p>
                      </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="statusConfig[vehicle.operational?.status]?.badge || statusConfig.available.badge" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-black">
                        {{ statusConfig[vehicle.operational?.status]?.label || 'Disponible' }}
                      </span>
                      <span v-if="vehicle.current_station_assignment?.station_id === selectedStation.id" class="text-[10px] text-slate-400 font-semibold">
                        (Gare d'attache)
                      </span>
                      <span v-else class="text-[10px] text-blue-600 font-semibold">
                        (Arrivé sur voyage)
                      </span>
                    </div>
                  </div>
                </div>
              </AccordionSection>
            </div>
          </div>

          <div v-else class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
              <div>
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200">
                  Sélectionnez une gare
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                  La carte du réseau reste visible avant l'ouverture d'une fiche.
                </p>
              </div>
              <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">
                {{ stationPreviewPoints.length }} points
              </span>
            </div>

            <GpsMapPicker
              :modelValue="stationPreviewCoordinates"
              :reference-points="stationPreviewPoints"
              :interactive="false"
              :visible="true"
              height="360px"
              :center="mapCenter"
              :zoom="mapZoom"
              :fit-bounds-max-zoom="14"
            />
          </div>
          </div>
        </div>
      </div>
    <StationFormModal
      :show="showModal"
      :title="isEditing ? 'Modifier la Gare / Destination' : 'Nouvelle Gare / Destination'"
      :form="form"
      :errors="errors"
      :processing="processing"
      :reference-points="selectedDestinationStations"
      :center="{
        latitude: Number(form.latitude) || 7.177201,
        longitude: Number(form.longitude) || -5.635986
      }"
      :map-visible="showModal"
      destination-mode="select"
      destination-label="Ville*"
      :destination-options="destinations"
      :destination-value-label="''"
      @close="closeModal"
      @submit="submit"
    />

    <DialogModal :show="showQuickRouteModal" @close="closeQuickRoute" maxWidth="lg">
      <template #title>Nouveau trajet depuis {{ selectedStation?.name }}</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="quick-route-name" value="Nom du trajet" />
            <TextInput id="quick-route-name" v-model="quickRouteForm.name" class="mt-1 w-full" placeholder="Ex. Abidjan ↔ Gagnoa" />
            <InputError :message="quickErrors.name" class="mt-1" />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <InputLabel for="quick-route-origin" value="Ville de départ" />
              <select id="quick-route-origin" v-model="quickRouteForm.origin_destination_id" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950">
                <option value="" disabled>Choisir…</option>
                <option v-for="destination in destinations" :key="destination.id" :value="destination.id">{{ destination.name }}</option>
              </select>
              <InputError :message="quickErrors.origin_destination_id" class="mt-1" />
            </div>
            <div>
              <InputLabel for="quick-route-target" value="Ville d'arrivée" />
              <select id="quick-route-target" v-model="quickRouteForm.target_destination_id" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950">
                <option value="" disabled>Choisir…</option>
                <option v-for="destination in destinations" :key="destination.id" :value="destination.id" :disabled="destination.id === quickRouteForm.origin_destination_id">{{ destination.name }}</option>
              </select>
              <InputError :message="quickErrors.target_destination_id" class="mt-1" />
            </div>
          </div>
          <div>
            <InputLabel for="quick-route-duration" value="Durée habituelle (minutes)" />
            <TextInput id="quick-route-duration" v-model.number="quickRouteForm.estimated_duration_minutes" type="number" min="1" max="2880" class="mt-1 w-full" />
            <InputError :message="quickErrors.estimated_duration_minutes" class="mt-1" />
          </div>
          <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
            <input v-model="quickRouteForm.active" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" /> Trajet actif
          </label>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeQuickRoute">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="quickProcessing" @click="submitQuickRoute">{{ quickProcessing ? 'Création…' : 'Créer le trajet' }}</PrimaryButton>
      </template>
    </DialogModal>

    <DepartureScheduleFormModal
      :show="showQuickScheduleModal"
      :title="`Nouveau programme de départ · ${selectedStation?.name || ''}`"
      submit-label="Créer le programme"
      :form="quickScheduleForm"
      :errors="quickErrors"
      :processing="quickProcessing"
      :stations="stationOptions"
      :routes="quickScheduleRouteOptions"
      :destination-options="quickScheduleDestinationOptions"
      :vehicle-types="vehicleTypes"
      lock-station
      :locked-station-name="selectedStation?.name || ''"
      @close="closeQuickSchedule"
      @submit="submitQuickSchedule"
    />

    <DialogModal :show="showQuickSellerModal" @close="closeQuickSeller" maxWidth="md">
      <template #title>Affecter un vendeur · {{ selectedStation?.name }}</template>
      <template #content>
        <div>
          <InputLabel for="quick-seller" value="Vendeur" />
          <select id="quick-seller" v-model="quickSellerForm.user_id" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950">
            <option value="" disabled>Sélectionner un vendeur…</option>
            <option v-for="seller in availableSellerOptions" :key="seller.id" :value="seller.id">{{ seller.name }} · {{ seller.email }}</option>
          </select>
          <InputError :message="quickErrors.user_id || quickErrors.station_id" class="mt-1" />
          <p v-if="availableSellerOptions.length === 0" class="mt-3 text-sm text-slate-500">Tous les vendeurs actifs sont déjà affectés à cette gare.</p>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeQuickSeller">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="quickProcessing || !quickSellerForm.user_id" @click="submitQuickSeller">{{ quickProcessing ? 'Affectation…' : 'Affecter le vendeur' }}</PrimaryButton>
      </template>
    </DialogModal>

    <DialogModal :show="showQuickVehicleModal" @close="closeQuickVehicle" maxWidth="lg">
      <template #title>Affecter un véhicule · {{ selectedStation?.name }}</template>
      <template #content>
        <div class="space-y-4">
          <div v-if="quickErrors.vehicle_id" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300" role="alert">
            {{ quickErrors.vehicle_id }}
          </div>
          <div>
            <InputLabel for="quick-vehicle" value="Véhicule" />
            <select id="quick-vehicle" v-model="quickVehicleForm.vehicle_id" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950">
              <option value="" disabled>Sélectionner un véhicule…</option>
              <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">{{ vehicle.identifier }} · {{ vehicle.vehicle_type?.name }} · {{ vehicle.seat_count }} places</option>
            </select>
            <InputError :message="quickErrors.station_id" class="mt-1" />
          </div>
          <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <input v-model="quickVehicleForm.permanent" type="checkbox" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
            <span><strong class="block text-sm text-slate-800 dark:text-slate-100">Affectation permanente</strong><span class="text-xs text-slate-500">Le véhicule reste dans le pool jusqu'à modification.</span></span>
          </label>
          <div v-if="!quickVehicleForm.permanent" class="grid grid-cols-2 gap-3">
            <div><InputLabel value="Du" /><AppDatePicker v-model="quickVehicleForm.valid_from" :max="quickVehicleForm.valid_until || ''" class="mt-1" /><InputError :message="quickErrors.valid_from" /></div>
            <div><InputLabel value="Au" /><AppDatePicker v-model="quickVehicleForm.valid_until" :min="quickVehicleForm.valid_from || ''" class="mt-1" /><InputError :message="quickErrors.valid_until" /></div>
          </div>
          <div>
            <InputLabel for="quick-vehicle-notes" value="Note facultative" />
            <textarea id="quick-vehicle-notes" v-model="quickVehicleForm.notes" rows="2" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950"></textarea>
            <InputError :message="quickErrors.notes" class="mt-1" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeQuickVehicle">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" :disabled="quickProcessing || !quickVehicleForm.vehicle_id" @click="submitQuickVehicle">{{ quickProcessing ? 'Affectation…' : 'Affecter le véhicule' }}</PrimaryButton>
      </template>
    </DialogModal>

    <DialogModal :show="showRouteDiagramModal" @close="closeRouteDiagramModal" maxWidth="5xl">
      <template #title>
        <div class="flex flex-col gap-2">
          <span class="text-2xl font-bold text-slate-900 dark:text-slate-100">Schéma du trajet</span>
          <span class="text-sm text-slate-500 dark:text-slate-400">
            La gare mise en évidence est la gare consultée, pas forcément la gare de départ du trajet.
          </span>
        </div>
      </template>
      <template #content>
        <div v-if="!selectedRouteDiagram" class="py-8 text-center text-slate-500 dark:text-slate-400">
          Aucun trajet sélectionné.
        </div>
        <div v-else-if="routeDiagramStops.length === 0" class="py-8 text-center text-slate-500 dark:text-slate-400">
          Aucun arrêt n’est encore configuré pour ce trajet.
        </div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700">
              Trajet: {{ selectedRouteDiagram.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900">
              Gare consultée: {{ selectedStation.name }}
            </span>
            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700">
              {{ routeDiagramStops.length }} arrêt(s)
            </span>
          </div>
          <RouteSchemaDiagram
            :stops="routeDiagramStops"
            variant="endpoints"
            :highlight-station-id="selectedStation?.id"
          />
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeRouteDiagramModal">Fermer</SecondaryButton>
      </template>
    </DialogModal>

    <!-- Custom Confirmation Modal -->
    <ConfirmationModal :show="showDeleteModal" variant="danger" @close="showDeleteModal = false">
        <template #title>Supprimer la gare</template>
        <template #content>Êtes-vous sûr de vouloir supprimer cette gare ? Cette action est irréversible.</template>
        <template #footer>
            <SecondaryButton @click="showDeleteModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="deleteStation">Oui, Supprimer</DangerButton>
        </template>
    </ConfirmationModal>
    </div>
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
