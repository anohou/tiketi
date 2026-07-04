<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ latitude: '', longitude: '' }),
  },
  visible: {
    type: Boolean,
    default: true,
  },
  height: {
    type: String,
    default: '320px',
  },
  center: {
    type: Object,
    default: () => ({ latitude: 7.177201, longitude: -5.635986 }),
  },
  zoom: {
    type: Number,
    default: 6,
  },
  fitBoundsMaxZoom: {
    type: Number,
    default: 14,
  },
  bounds: {
    type: Array,
    default: () => [
      [4.0, -8.8],
      [10.8, -2.0],
    ],
  },
  referencePoints: {
    type: Array,
    default: () => [],
  },
  interactive: {
    type: Boolean,
    default: true,
  },
});

const emit = defineEmits(['update:modelValue']);

const mapEl = ref(null);
let map = null;
let marker = null;
let referenceLayer = null;

const defaultMarkerIcon = L.icon({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
});

const parseCoordinate = (value) => {
  if (value === '' || value === null || value === undefined) {
    return null;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
};

const defaultCenter = computed(() => {
  const lat = parseCoordinate(props.modelValue?.latitude);
  const lng = parseCoordinate(props.modelValue?.longitude);

  if (lat !== null && lng !== null) {
    return { latitude: lat, longitude: lng };
  }

  return {
    latitude: Number(props.center.latitude) || 7.177201,
    longitude: Number(props.center.longitude) || -5.635986,
  };
});

const toLatLng = () => {
  const latitude = parseCoordinate(props.modelValue?.latitude);
  const longitude = parseCoordinate(props.modelValue?.longitude);

  if (latitude === null || longitude === null) {
    return null;
  }

  return { latitude, longitude };
};

const normalizeReferencePoints = () => {
  return (props.referencePoints || [])
    .map((point) => {
      const latitude = parseCoordinate(point?.latitude);
      const longitude = parseCoordinate(point?.longitude);

      if (latitude === null || longitude === null) {
        return null;
      }

      return {
        latitude,
        longitude,
        label: point?.label || point?.name || '',
      };
    })
    .filter(Boolean);
};

const syncReferenceMarkers = () => {
  if (!map) return;

  if (!referenceLayer) {
    referenceLayer = L.layerGroup().addTo(map);
  }

  referenceLayer.clearLayers();

  normalizeReferencePoints().forEach((point) => {
    L.circleMarker([point.latitude, point.longitude], {
      radius: 6,
      color: '#16a34a',
      weight: 2,
      opacity: 0.9,
      fillColor: '#22c55e',
      fillOpacity: 0.85,
    })
      .addTo(referenceLayer)
      .bindTooltip(point.label || 'Point de repère', {
        direction: 'top',
        opacity: 0.9,
      });
  });
};

const applyDefaultView = () => {
  if (!map) return;

  const current = toLatLng();
  const referencePoints = normalizeReferencePoints();
  const viewPoints = [
    ...referencePoints.map((point) => [point.latitude, point.longitude]),
  ];

  if (current) {
    syncMarker(current.latitude, current.longitude);
    viewPoints.push([current.latitude, current.longitude]);
  }

  syncReferenceMarkers();

  // If we are in interactive mode (form modal) and no coordinates are set yet:
  // center on the city (defaultCenter) with zoom 13 and a marker
  if (props.interactive && !current) {
    const cityLat = defaultCenter.value.latitude;
    const cityLng = defaultCenter.value.longitude;
    syncMarker(cityLat, cityLng);
    map.setView([cityLat, cityLng], 13, { animate: false });
    return;
  }

  // Otherwise (preview mode or coordinates already set):
  // If we don't have coordinates set yet (and not interactive), make sure to remove any stale marker
  if (!current && marker) {
    map.removeLayer(marker);
    marker = null;
  }

  if (viewPoints.length >= 2) {
    map.fitBounds(L.latLngBounds(viewPoints), {
      padding: [24, 24],
      maxZoom: props.fitBoundsMaxZoom,
    });
    return;
  }

  if (viewPoints.length === 1) {
    map.setView(viewPoints[0], Math.max(props.zoom, 13), { animate: false });
    return;
  }

  if (Array.isArray(props.bounds) && props.bounds.length === 2) {
    map.fitBounds(props.bounds, { padding: [18, 18] });
    return;
  }

  map.setView([defaultCenter.value.latitude, defaultCenter.value.longitude], props.zoom, { animate: false });
};

const setCoordinates = (latitude, longitude) => {
  emit('update:modelValue', {
    latitude: Number(latitude.toFixed(6)),
    longitude: Number(longitude.toFixed(6)),
  });
};

const syncMarker = (latitude, longitude, panTo = false) => {
  if (!map) return;

  const latLng = [latitude, longitude];

  if (!marker) {
    marker = L.marker(latLng, { draggable: props.interactive, icon: defaultMarkerIcon }).addTo(map);
    if (props.interactive) {
      marker.on('dragend', () => {
        const position = marker.getLatLng();
        setCoordinates(position.lat, position.lng);
      });
    }
  } else {
    marker.setLatLng(latLng);
  }

  if (panTo) {
    map.setView(latLng, map.getZoom(), { animate: true });
  }
};

const initMap = async () => {
  if (!mapEl.value || map) return;

  await nextTick();

  map = L.map(mapEl.value, {
    zoomControl: true,
    dragging: props.interactive,
    scrollWheelZoom: props.interactive,
    doubleClickZoom: props.interactive,
    touchZoom: props.interactive,
    boxZoom: props.interactive,
    keyboard: props.interactive,
    minZoom: 5,
    maxZoom: 18,
  }).setView([defaultCenter.value.latitude, defaultCenter.value.longitude], props.zoom);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
  }).addTo(map);

  if (props.interactive) {
    map.on('click', (event) => {
      setCoordinates(event.latlng.lat, event.latlng.lng);
      syncMarker(event.latlng.lat, event.latlng.lng);
    });
  }

  window.setTimeout(() => {
    if (!map) return;
    map.invalidateSize();
    applyDefaultView();
  }, 100);
};

watch(
  () => props.visible,
  (visible) => {
    if (visible && !map) {
      initMap();
      return;
    }

    if (!map) return;

    if (visible) {
      window.setTimeout(() => {
        map?.invalidateSize();
        applyDefaultView();
      }, 120);
    }
  }
);

watch(
  () => props.center,
  () => {
    if (map && props.visible) {
      const hasModelValue = props.modelValue?.latitude && props.modelValue?.longitude;
      if (!hasModelValue) {
        window.setTimeout(() => {
          map?.invalidateSize();
          applyDefaultView();
        }, 120);
      }
    }
  },
  { deep: true }
);

watch(
  () => props.referencePoints,
  () => {
    if (!map || !props.visible) return;

    window.setTimeout(() => {
      map?.invalidateSize();
      applyDefaultView();
    }, 120);
  },
  { deep: true }
);

watch(
  () => props.modelValue,
  (value) => {
    if (!map) return;

    const latitude = Number(value?.latitude);
    const longitude = Number(value?.longitude);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
    syncMarker(latitude, longitude, false);
  },
  { deep: true }
);

onMounted(() => {
  if (props.visible) {
    initMap();
  }
});

onBeforeUnmount(() => {
  if (map) {
    map.remove();
    map = null;
    marker = null;
    referenceLayer = null;
  }
});
</script>

<template>
  <div class="space-y-3">
    <div class="rounded-xl border border-gray-200 dark:border-slate-800 overflow-hidden bg-gray-50 dark:bg-slate-900">
      <div
        ref="mapEl"
        class="w-full"
        :style="{ height }"
      />
    </div>
    <p class="text-xs text-gray-500 dark:text-slate-400">
      Clique sur la carte pour définir les coordonnées, ou déplace le marqueur.
    </p>
  </div>
</template>

<style scoped>
:deep(.dark .leaflet-tile) {
  filter: invert(100%) hue-rotate(180deg) brightness(95%) contrast(90%);
}
:deep(.dark .leaflet-container) {
  background: #0f172a;
}
:deep(.dark .leaflet-bar a) {
  background-color: #1e293b;
  border-bottom: 1px solid #334155;
  color: #f1f5f9;
}
:deep(.dark .leaflet-bar a:hover) {
  background-color: #334155;
}
</style>
