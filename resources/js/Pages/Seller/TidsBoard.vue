<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Bus from 'vue-material-design-icons/Bus.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Routes from 'vue-material-design-icons/Routes.vue';

const props = defineProps({
  trips: Array,
  stations: Array,
  selectedStationId: String,
  selectedStationName: String,
});

const page = usePage();
const tenantLogo = computed(() => page.props.tenant?.logo_url || null);
const tenantName = computed(() => page.props.tenant?.name || 'TIKÊTI');
const logoLoadFailed = ref(false);

const currentTime = ref('');
const currentDate = ref('');
const isFullscreen = ref(false);
const showFilter = ref(true);
const localStationId = ref(props.selectedStationId || '');
const isRefreshing = ref(false);

let clockInterval = null;
let pollInterval = null;

const updateClock = () => {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  currentDate.value = now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
};

const changeStation = () => {
  router.visit(route('tids', { station_id: localStationId.value }), {
    preserveState: true,
    preserveScroll: true,
  });
};

const handleLogoError = () => {
  logoLoadFailed.value = true;
};

const refreshData = () => {
  isRefreshing.value = true;
  router.reload({
    only: ['trips'],
    preserveScroll: true,
    preserveState: true,
    onFinish: () => {
      setTimeout(() => {
        isRefreshing.value = false;
      }, 600);
    }
  });
};

const toggleFullscreen = () => {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().then(() => {
      isFullscreen.value = true;
    }).catch(err => {
      console.error(`Erreur d'activation du plein écran : ${err.message}`);
    });
  } else {
    document.exitFullscreen().then(() => {
      isFullscreen.value = false;
    });
  }
};

onMounted(() => {
  updateClock();
  clockInterval = setInterval(updateClock, 1000);

  // Poll server for updates every 25 seconds
  pollInterval = setInterval(refreshData, 25000);

  // Sync fullscreen state if changed via browser hotkeys (ESC)
  const handleFullscreenChange = () => {
    isFullscreen.value = !!document.fullscreenElement;
  };
  document.addEventListener('fullscreenchange', handleFullscreenChange);

  // Auto-subscribe to WebSockets if Echo is present and authenticated
  const echo = window.Echo;
  if (echo && props.selectedStationId) {
    try {
      echo.private(`station.${props.selectedStationId}`)
        .listen('.SeatMapUpdated', () => refreshData())
        .listen('.TripCreated', () => refreshData());
    } catch (e) {
      console.warn('Realtime subscription failed, relying on polling fallback:', e);
    }
  }

  onUnmounted(() => {
    if (clockInterval) clearInterval(clockInterval);
    if (pollInterval) clearInterval(pollInterval);
    document.removeEventListener('fullscreenchange', handleFullscreenChange);
    
    const echo = window.Echo;
    if (echo && props.selectedStationId) {
      echo.leave(`station.${props.selectedStationId}`);
    }
  });
});

const parseRouteName = (trip) => {
  const name = trip.display_name || trip.route?.name || '';
  const separator = name.includes('➔') ? '➔' : (name.includes('->') ? '->' : '->');
  const parts = name.split(separator);
  if (parts.length === 2) {
    return {
      origin: parts[0].trim(),
      destination: parts[1].trim()
    };
  }
  const originName = trip.origin_station?.name || trip.route?.origin_station?.name || '';
  const destName = trip.destination_station?.name || trip.route?.destination_station?.name || name;
  return {
    origin: originName || 'Départ',
    destination: destName
  };
};

const getAirportStatus = (trip) => {
  if (trip.status === 'cancelled') {
    return { 
      label: 'ANNULÉ', 
      color: 'text-rose-500 border border-rose-950 bg-rose-950/20 text-glow-red' 
    };
  }
  if (trip.status === 'delayed') {
    return { 
      label: 'RETARDÉ', 
      color: 'text-amber-500 border border-amber-950 bg-amber-950/20 animate-pulse text-glow-amber' 
    };
  }
  if (trip.status === 'boarding') {
    return { 
      label: 'EMBARQUEMENT', 
      color: 'text-orange-500 border border-orange-950 bg-orange-950/30 animate-pulse text-glow-orange' 
    };
  }
  if (trip.status === 'departed' || trip.status === 'arrived') {
    return { 
      label: 'PARTI', 
      color: 'text-slate-400 border border-slate-800 bg-slate-900/40' 
    };
  }
  if (trip.available_seats <= 0) {
    return { 
      label: 'COMPLET', 
      color: 'text-red-500 border border-red-950 bg-red-950/15' 
    };
  }
  return { 
    label: 'À L\'HEURE', 
    color: 'text-emerald-500 border border-emerald-950 bg-emerald-950/20 text-glow-green' 
  };
};

const sortedTrips = computed(() => {
  if (!props.trips) return [];
  return [...props.trips].sort((a, b) => {
    return new Date(a.departure_at) - new Date(b.departure_at);
  });
});

const filteredTripsForDisplay = computed(() => {
  if (!props.trips) return [];
  const now = new Date();
  
  return props.trips.filter(trip => {
    // If departed, arrived or cancelled, hide if the departure was more than 15 minutes ago
    if (['departed', 'arrived', 'cancelled'].includes(trip.status)) {
      const departureTime = new Date(trip.departure_at);
      const diffMinutes = (now - departureTime) / (60 * 1000);
      return diffMinutes <= 15;
    }
    return true;
  });
});

const sortedTripsForDisplay = computed(() => {
  return [...filteredTripsForDisplay.value].sort((a, b) => {
    return new Date(a.departure_at) - new Date(b.departure_at);
  });
});

const formatTime = (dateString) => {
  return new Date(dateString).toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit'
  });
};

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit'
  });
};
</script>

<template>
  <div class="tids-board-wrapper min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans select-none overflow-hidden relative">
    <!-- CRT scanning effect overlay -->
    <div class="crt-scanline pointer-events-none absolute inset-0 z-[999] opacity-5"></div>
    <div class="crt-flicker pointer-events-none absolute inset-0 z-[998] opacity-[0.02]"></div>

    <!-- Header Panel -->
    <header class="bg-slate-900/90 border-b-2 border-slate-800 p-6 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0 shadow-lg relative z-10">
      <div class="flex items-center gap-4">
        <!-- Logo Display -->
        <div
          v-if="tenantLogo && !logoLoadFailed"
          class="h-16 w-24 p-2 bg-white/95 border border-slate-700 rounded-2xl shadow-inner flex items-center justify-center"
        >
          <img
            :src="tenantLogo"
            :alt="`${tenantName} logo`"
            class="max-h-full max-w-full object-contain"
            @error="handleLogoError"
          />
        </div>
        <div v-else class="p-3 bg-amber-500/10 border border-amber-500/30 text-amber-500 rounded-2xl shadow-inner animate-pulse-slow">
          <Bus :size="32" class="filter drop-shadow-[0_0_8px_rgba(245,158,11,0.5)]" />
        </div>
        <div>
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-black text-amber-500 uppercase tracking-widest text-glow-amber">
              {{ props.selectedStationName ? `Départs — ${props.selectedStationName}` : 'Tous les Départs' }}
            </h1>
            <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-extrabold bg-emerald-950/80 text-emerald-400 border border-emerald-900 tracking-wider">LIVE BOARD</span>
          </div>
          <p class="text-xs text-slate-500 font-mono tracking-widest uppercase mt-0.5">TRAVEL INFORMATION DISPLAY SYSTEM (TIDS)</p>
        </div>
      </div>

      <!-- Real-time digital clock -->
      <div class="flex items-center gap-6 md:justify-end">
        <div class="text-right shrink-0">
          <div class="text-3xl font-black text-amber-400 tracking-tight leading-none font-mono text-glow-amber">{{ currentTime }}</div>
          <div class="text-[10px] font-bold text-slate-500 tracking-widest mt-1.5 font-mono">{{ currentDate }}</div>
        </div>

        <!-- Utility Buttons -->
        <div class="flex items-center gap-2">
          <!-- Refresh -->
          <button 
            @click="refreshData"
            :disabled="isRefreshing"
            class="p-2.5 rounded-xl border border-slate-800 hover:border-slate-700 bg-slate-950 hover:bg-slate-900 transition-all text-slate-400 hover:text-amber-500 disabled:opacity-50"
            title="Rafraîchir"
          >
            <Refresh :size="20" :class="{ 'animate-spin': isRefreshing }" />
          </button>
          
          <!-- Fullscreen toggle -->
          <button 
            @click="toggleFullscreen"
            class="p-2.5 rounded-xl border border-slate-800 hover:border-slate-700 bg-slate-950 hover:bg-slate-900 transition-all text-slate-400 hover:text-amber-500"
            :title="isFullscreen ? 'Quitter le plein écran' : 'Plein écran'"
          >
            <!-- Custom Inline SVGs for Fullscreen & FullscreenExit -->
            <svg v-if="isFullscreen" viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 14h6v6M20 10h-6V4M14 10l7-7M10 14l-7 7"/>
            </svg>
            <svg v-else viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/>
            </svg>
          </button>
        </div>
      </div>
    </header>

    <!-- Optional station selector (hidden in fullscreen unless toggled) -->
    <div v-if="showFilter && !isFullscreen" class="bg-slate-900/40 border-b border-slate-900 p-4 shrink-0 relative z-10 transition-all">
      <div class="max-w-md flex gap-3">
        <div class="relative flex-1">
          <select 
            v-model="localStationId"
            @change="changeStation"
            class="w-full pl-10 pr-4 py-2.5 bg-slate-950 border border-slate-800 text-slate-200 text-sm rounded-xl focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 block appearance-none font-bold cursor-pointer"
          >
            <option value="">Toutes les gares de départ</option>
            <option v-for="station in stations" :key="station.id" :value="station.id">
              {{ station.name }} ({{ station.city }})
            </option>
          </select>
          <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-500 pointer-events-none">
            <Routes class="w-5 h-5" />
          </div>
        </div>
      </div>
    </div>

    <!-- Board Display Content -->
    <main class="flex-1 overflow-y-auto min-h-0 bg-slate-950/50 p-6 relative z-10">
      <div v-if="sortedTripsForDisplay.length > 0" class="border border-slate-900 rounded-3xl overflow-hidden shadow-2xl">
        <!-- Table Headers (TIDS Yellow style) -->
        <div class="grid grid-cols-12 gap-4 px-8 py-4 bg-slate-900 border-b border-slate-800 text-[11px] font-mono text-amber-500/80 font-black uppercase tracking-widest leading-none">
          <div class="col-span-2">Heure / Date</div>
          <div class="col-span-2">Code Voyage</div>
          <div class="col-span-4">Destination</div>
          <div class="col-span-2">Véhicule</div>
          <div class="col-span-1 text-center">Places</div>
          <div class="col-span-1 text-right">Statut</div>
        </div>

        <!-- Trips Rows -->
        <div class="divide-y divide-slate-900 bg-slate-950/80">
          <div 
            v-for="trip in sortedTripsForDisplay" 
            :key="trip.id" 
            class="grid grid-cols-12 gap-4 items-center px-8 py-5 hover:bg-slate-900/30 transition-all font-sans relative"
          >
            <!-- HEURE & DATE -->
            <div class="col-span-2 flex items-baseline gap-2">
              <span class="font-mono text-xl font-bold text-amber-400 tracking-wider text-glow-amber">{{ formatTime(trip.departure_at) }}</span>
              <span class="text-xs font-mono text-slate-500 font-medium">{{ formatDate(trip.departure_at) }}</span>
            </div>

            <!-- CODE VOYAGE -->
            <div class="col-span-2">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-slate-900 text-slate-350 text-[10px] font-bold font-mono tracking-wider border border-slate-800">
                {{ trip.code || 'CODE EN ATTENTE' }}
              </span>
            </div>

            <!-- DESTINATION -->
            <div class="col-span-4 flex flex-col justify-center min-w-0">
              <span class="text-base font-black text-slate-100 tracking-wide uppercase leading-tight">
                {{ parseRouteName(trip).destination }}
              </span>
              <span class="text-[10px] text-slate-500 font-semibold uppercase mt-1 flex items-center gap-1 font-mono">
                <span class="text-slate-600 font-normal">depuis</span>
                <span class="text-slate-455">{{ parseRouteName(trip).origin }}</span>
              </span>
            </div>

            <!-- VEHICULE -->
            <div class="col-span-2 flex flex-col">
              <span class="font-mono text-sm font-bold text-slate-300 uppercase truncate">
                {{ trip.vehicle?.identifier || 'N/A' }}
              </span>
              <span class="text-[10px] text-slate-500 font-medium lowercase truncate mt-0.5">
                {{ trip.vehicle?.vehicle_type?.name || 'standard' }}
              </span>
            </div>

            <!-- PLACES -->
            <div class="col-span-1 flex flex-col items-center justify-center font-mono">
              <span class="text-base font-black text-slate-200">{{ trip.available_seats }}</span>
              <span class="text-[9px] text-slate-605 uppercase font-black leading-none mt-0.5">LIBRES</span>
            </div>

            <!-- STATUT BADGE -->
            <div class="col-span-1 flex justify-end">
              <span 
                class="inline-flex items-center px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all duration-300 shadow-inner font-mono border"
                :class="getAirportStatus(trip).color"
              >
                <span v-if="['boarding', 'delayed'].includes(trip.status)" class="w-1.5 h-1.5 rounded-full mr-2 animate-ping bg-current"></span>
                {{ getAirportStatus(trip).label }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="h-96 border-2 border-dashed border-slate-900 rounded-3xl flex flex-col items-center justify-center text-slate-500 bg-slate-950/20">
        <Bus :size="64" class="opacity-20 mb-4 text-slate-605" />
        <h3 class="text-lg font-black uppercase tracking-widest text-slate-455">Aucun voyage programmé</h3>
        <p class="text-xs text-slate-605 mt-1 font-mono">LES DÉPARTS SONT MIS À JOUR EN TEMPS RÉEL</p>
      </div>
    </main>
  </div>
</template>

<style scoped>
/* CRT Scanline & flicker animation effects for authentic display monitor look */
.tids-board-wrapper {
  background-color: #030712;
  box-shadow: inset 0 0 100px rgba(0,0,0,0.8);
}

.crt-scanline {
  background: linear-gradient(
    rgba(18, 16, 16, 0) 50%, 
    rgba(0, 0, 0, 0.25) 50%
  );
  background-size: 100% 4px;
}

.crt-flicker {
  animation: crt-flicker-anim 0.15s infinite;
}

@keyframes crt-flicker-anim {
  0% { opacity: 0.015; }
  50% { opacity: 0.025; }
  100% { opacity: 0.015; }
}

/* Glow effects for display aesthetics */
.text-glow-amber {
  text-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
}
.text-glow-green {
  text-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
}
.text-glow-red {
  text-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
}
.text-glow-orange {
  text-shadow: 0 0 8px rgba(249, 115, 22, 0.4);
}

/* Slow and fast pulsate speeds */
.animate-pulse-slow {
  animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
.animate-pulse-fast {
  animation: pulse 1s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Custom scrollbars */
::-webkit-scrollbar {
  width: 8px;
}
::-webkit-scrollbar-track {
  background: #020617;
}
::-webkit-scrollbar-thumb {
  background: #1e293b;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
  background: #334155;
}
</style>
