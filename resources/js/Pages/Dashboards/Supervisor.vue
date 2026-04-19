<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
// Components
import TripControlModal from '@/Components/Supervisor/TripControlModal.vue';
import TicketInspectionModal from '@/Components/Supervisor/TicketInspectionModal.vue';
// Icons
import Bus from 'vue-material-design-icons/Bus.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import Play from 'vue-material-design-icons/Play.vue';
import Bell from 'vue-material-design-icons/Bell.vue';
import SeatReclineNormal from 'vue-material-design-icons/SeatReclineNormal.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';

const props = defineProps({
  departures: Array,
  validations: Array,
  alerts: Array,
  sellers: Array,
  todayStats: Object,
  user_stations: Array,
});

// State for Modals
const showTripModal = ref(false);
const selectedTrip = ref(null);

const showValidationModal = ref(false);
const selectedValidation = ref(null);

// Alert sound
const alertSound = ref(null);
const hasNewCriticalAlert = ref(false);

// Computed counts
const pendingValidationCount = computed(() => props.validations?.length || 0);
const criticalAlertCount = computed(() => props.alerts?.filter(a => a.severity === 'critical').length || 0);

// Format currency
const formatCurrency = (amount) => {
    if (amount >= 1000000) {
        return (amount / 1000000).toFixed(1) + 'M';
    } else if (amount >= 1000) {
        return (amount / 1000).toFixed(0) + 'K';
    }
    return new Intl.NumberFormat('fr-FR').format(amount);
};

// Actions
const openTripControl = (trip) => {
  selectedTrip.value = trip;
  showTripModal.value = true;
};

const openValidation = (validation) => {
  selectedValidation.value = validation;
  showValidationModal.value = true;
};

const handleTripUpdate = (form) => {
  console.log('Trip updated:', form);
  // In real app: router.post(...)
};

const handleValidationApprove = (val) => {
  console.log('Approved:', val.id);
  // In real app: router.post(...)
};

const handleValidationDecline = (val) => {
  console.log('Declined:', val.id);
  // In real app: router.post(...)
};

const handleTripDeparture = (trip) => {
    if (confirm(`Confirmer le départ de ce voyage vers ${trip.destination} ?`)) {
        console.log('Departing trip:', trip.id);
        // In real app: router.post(route('trips.depart', trip.id))
    }
};

const quickDeparture = (trip, event) => {
    event.stopPropagation();
    handleTripDeparture(trip);
};

const refreshData = () => {
    router.reload({
        only: ['departures', 'validations', 'alerts', 'sellers', 'todayStats'],
        preserveScroll: true,
        preserveState: true,
    });
};

// Polling Logic
let pollInterval;

onMounted(() => {
    pollInterval = setInterval(() => {
        router.reload({
            only: ['departures', 'validations', 'alerts', 'sellers', 'todayStats'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 300000); // 5 minutes
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});

// Watch for critical alerts
watch(() => props.alerts, (newAlerts) => {
    const criticalCount = newAlerts?.filter(a => a.severity === 'critical').length || 0;
    if (criticalCount > 0) {
        hasNewCriticalAlert.value = true;
        // Play sound (if we had an audio element)
        // alertSound.value?.play();
    }
}, { deep: true });
</script>

<template>
  <Head title="Supervision" />

  <MainNavLayout>
    <div class="max-w-4xl mx-auto space-y-6 pb-20">
      
      <!-- Top Bar: Station Scope + Refresh -->
      <div class="flex items-center justify-between px-2 pt-2">
        <div>
          <h1 class="text-2xl font-black text-gray-900 tracking-tight">Tour de Contrôle</h1>
          <p class="text-sm text-gray-500 font-medium truncate max-w-[250px]">{{ user_stations.join(', ') }}</p>
        </div>
        
        <!-- Controls -->
        <div class="flex gap-2 items-center">
           <button 
               @click="refreshData"
               class="p-2 bg-gray-100 hover:bg-gray-200 rounded-xl text-gray-600 transition-colors"
               title="Actualiser"
           >
               <Refresh :size="20" />
           </button>
           <div class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold flex items-center gap-1 shadow-sm border border-green-200">
             <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
             Live
           </div>
        </div>
      </div>

      <!-- Today's Stats Cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 px-2">
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-4 text-white shadow-lg">
            <div class="flex items-center gap-2 mb-2">
                <CashMultiple :size="18" class="opacity-80" />
                <span class="text-xs font-bold text-green-100 uppercase tracking-wide">Recettes</span>
            </div>
            <div class="text-2xl font-black">{{ formatCurrency(todayStats.total_revenue) }}</div>
            <div class="text-xs text-green-200">FCFA aujourd'hui</div>
        </div>
        
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-4 text-white shadow-lg">
            <div class="flex items-center gap-2 mb-2">
                <Ticket :size="18" class="opacity-80" />
                <span class="text-xs font-bold text-blue-100 uppercase tracking-wide">Billets</span>
            </div>
            <div class="text-2xl font-black">{{ todayStats.tickets_sold }}</div>
            <div class="text-xs text-blue-200">vendus aujourd'hui</div>
        </div>
        
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl p-4 text-white shadow-lg">
            <div class="flex items-center gap-2 mb-2">
                <Bus :size="18" class="opacity-80" />
                <span class="text-xs font-bold text-orange-100 uppercase tracking-wide">Voyages</span>
            </div>
            <div class="text-2xl font-black">{{ todayStats.trips_today }}</div>
            <div class="text-xs text-orange-200">programmés</div>
        </div>
        
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-4 text-white shadow-lg">
            <div class="flex items-center gap-2 mb-2">
                <Play :size="18" class="opacity-80" />
                <span class="text-xs font-bold text-purple-100 uppercase tracking-wide">Départs</span>
            </div>
            <div class="text-2xl font-black">{{ todayStats.trips_departed }}</div>
            <div class="text-xs text-purple-200">effectués</div>
        </div>
      </div>

      <!-- Alerts Section -->
      <div v-if="alerts && alerts.length > 0" class="px-2 space-y-2">
        <div class="flex items-center gap-2 text-red-600 font-bold text-sm">
            <Bell :size="18" class="animate-bounce" />
            <span>{{ alerts.length }} Alerte(s) Active(s)</span>
        </div>
        <div class="space-y-2">
          <div 
            v-for="alert in alerts" 
            :key="alert.id"
            @click="openTripControl(departures.find(d => d.id === alert.trip_id))"
            :class="[
              'rounded-2xl p-4 flex items-center justify-between cursor-pointer transition-all active:scale-[0.98]',
              alert.severity === 'critical' 
                ? 'bg-red-50 border-2 border-red-300 shadow-lg shadow-red-100' 
                : 'bg-orange-50 border border-orange-200'
            ]"
          >
            <div class="flex items-center gap-3">
              <div :class="[
                'p-2 rounded-xl',
                alert.severity === 'critical' ? 'bg-red-500 text-white' : 'bg-orange-400 text-white'
              ]">
                <Bus v-if="alert.icon === 'bus'" :size="20" />
                <SeatReclineNormal v-else-if="alert.icon === 'seat'" :size="20" />
                <AlertCircle v-else :size="20" />
              </div>
              <div>
                <div :class="[
                  'font-bold',
                  alert.severity === 'critical' ? 'text-red-900' : 'text-orange-900'
                ]">{{ alert.title }}</div>
                <div :class="[
                  'text-xs font-medium',
                  alert.severity === 'critical' ? 'text-red-700' : 'text-orange-700'
                ]">{{ alert.message }}</div>
              </div>
            </div>
            <div :class="[
              'text-xs font-bold px-2 py-1 rounded-lg',
              alert.severity === 'critical' ? 'bg-red-200 text-red-800' : 'bg-orange-200 text-orange-800'
            ]">
              {{ alert.time }}
            </div>
          </div>
        </div>
      </div>

      <!-- Validation Alert (if any) -->
      <div v-if="pendingValidationCount > 0" class="mx-2">
        <button @click="openValidation(validations[0])" 
                class="w-full bg-red-50 border border-red-200 rounded-2xl p-4 flex items-center justify-between shadow-sm animate-pulse-slow active:scale-95 transition-transform">
           <div class="flex items-center gap-3">
             <div class="bg-red-500 text-white p-2 rounded-xl">
               <AlertCircle :size="24" />
             </div>
             <div class="text-left">
               <div class="font-black text-red-900">Validations Requises</div>
               <div class="text-xs text-red-700 font-medium">{{ pendingValidationCount }} demande(s) en attente</div>
             </div>
           </div>
           <div class="bg-white px-3 py-1.5 rounded-lg text-xs font-bold text-red-700 shadow-sm border border-red-100 uppercase tracking-wide">
             Gérer
           </div>
        </button>
      </div>

      <!-- Live Feed (Departures) -->
      <div class="space-y-4 px-1">
        <div class="flex items-center justify-between px-1">
           <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
             <Bus class="text-gray-400" /> Prochains Départs
           </h2>
           <span class="text-xs text-gray-400 font-medium">{{ departures.length }} voyage(s)</span>
        </div>

        <div v-if="departures.length === 0" class="text-center py-12 text-gray-400 bg-white rounded-3xl border-2 border-dashed border-gray-100">
           <div class="bg-gray-50 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
              <Bus class="text-gray-300" :size="24" />
           </div>
           <span class="font-medium">Pas de départs prévus</span>
        </div>

        <div v-else class="grid gap-3">
          <div v-for="trip in departures" :key="trip.id" 
               @click="openTripControl(trip)"
               class="bg-white rounded-3xl p-4 border shadow-sm relative overflow-hidden transition-all active:scale-[0.98] group cursor-pointer"
               :class="{
                 'border-red-300 ring-4 ring-red-50': trip.alert_level === 'critical',
                 'border-orange-300 ring-2 ring-orange-50': trip.alert_level === 'warning',
                 'border-gray-100 hover:border-green-200 hover:shadow-md': trip.alert_level === 'normal'
               }"
          >
             <!-- Status Stripe -->
             <div class="absolute top-0 left-0 w-1.5 h-full"
                  :class="{
                    'bg-red-500': trip.alert_level === 'critical',
                    'bg-orange-400': trip.alert_level === 'warning',
                    'bg-green-500': trip.alert_level === 'normal'
                  }"
             ></div>

             <div class="pl-3 flex flex-col gap-4">
                <!-- Header: Time & Dest -->
                <div class="flex justify-between items-start">
                   <div>
                      <div class="flex items-baseline gap-2">
                          <div class="text-3xl font-black text-gray-900 leading-none">
                            {{ trip.departure_time }}
                          </div>
                          <div class="text-[10px] font-bold uppercase tracking-wider"
                               :class="{
                                 'text-green-600 bg-green-50 px-1.5 py-0.5 rounded': trip.status === 'boarding',
                                 'text-gray-400': trip.status !== 'boarding'
                               }" 
                               v-if="trip.status === 'boarding' || trip.mins_to_departure <= 30"
                          >
                              <span v-if="trip.status === 'boarding'">Embarquement</span>
                              <span v-else>Dans {{ trip.mins_to_departure }} min</span>
                          </div>
                      </div>
                      <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mt-1 flex items-center gap-1">
                        <span>{{ trip.origin }}</span>
                        <span class="text-gray-300">➜</span> 
                        <span class="text-gray-900">{{ trip.destination }}</span>
                      </div>
                   </div>
                   
                   <!-- Quick Actions -->
                   <div class="flex items-center gap-2">
                       <!-- Vehicle Badge -->
                       <div v-if="trip.license_plate" class="bg-gray-50 px-2.5 py-1.5 rounded-xl text-xs font-mono font-bold text-gray-600 border border-gray-100 flex items-center gap-2">
                           <Bus :size="14" class="text-gray-400" />
                           {{ trip.license_plate }}
                       </div>
                       <button v-else class="bg-red-50 px-2 py-1.5 rounded-xl text-xs font-bold text-red-600 border border-red-100 flex items-center gap-1 animate-pulse">
                          <AlertCircle :size="14" /> Assigner Car
                       </button>
                       
                       <!-- Quick Departure Button (only show when ready) -->
                       <button 
                           v-if="trip.license_plate && trip.occupancy_percent >= 50 && trip.mins_to_departure <= 15"
                           @click="quickDeparture(trip, $event)"
                           class="bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1 shadow-lg shadow-green-200 transition-all hover:scale-105"
                       >
                          <Play :size="14" /> Départ
                       </button>
                   </div>
                </div>

                <!-- Occupancy Bar -->
                <div>
                   <div class="flex justify-between text-xs font-medium mb-2">
                      <span :class="trip.occupancy_percent < 50 && trip.mins_to_departure < 30 ? 'text-red-600 font-bold' : 'text-gray-600'">
                        {{ trip.sold_seats || 0 }}/{{ trip.total_seats }} places ({{ trip.occupancy_percent }}%)
                      </span>
                      <span class="text-gray-400">{{ trip.available_seats }} libres</span>
                   </div>
                   <!-- Multi-segment bar -->
                   <div class="h-3 bg-gray-100 rounded-full overflow-hidden flex">
                      <div class="h-full transition-all duration-500 rounded-l-full relative"
                           :style="{ width: trip.occupancy_percent + '%' }"
                           :class="{
                             'bg-red-500': trip.occupancy_percent < 20,
                             'bg-orange-400': trip.occupancy_percent >= 20 && trip.occupancy_percent < 50,
                             'bg-green-500': trip.occupancy_percent >= 50
                           }"
                      >
                         <!-- Glossy effect -->
                         <div class="absolute inset-0 bg-white/20"></div>
                      </div>
                   </div>
                </div>
             </div>
             
             <!-- Action Hint (visible on hover or generic) -->
             <div class="absolute right-4 bottom-4 text-gray-300 group-hover:text-green-500 transition-colors">
                 <DotsVertical :size="20" />
             </div>
          </div>
        </div>
      </div>

      <!-- Cash Control Preview -->
      <div v-if="sellers.length > 0" class="px-1">
        <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2 mb-3 px-1">
           <CashMultiple class="text-gray-400" /> Caisses Vendeurs
        </h2>
        <div class="bg-white rounded-3xl p-4 border border-gray-100 shadow-sm space-y-3">
           <div v-for="seller in sellers" :key="seller.id" class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
              <div class="flex items-center gap-3">
                 <div class="w-10 h-10 rounded-full bg-green-50 flex items-center justify-center text-green-700 font-black text-xs uppercase border-2 border-white shadow-sm">
                    {{ seller.name.substring(0,2) }}
                 </div>
                 <div>
                    <div class="text-sm font-bold text-gray-900">{{ seller.name }}</div>
                    <div class="text-[10px] text-gray-400 uppercase font-bold tracking-wide">{{ seller.station }}</div>
                 </div>
              </div>
              <div class="text-right">
                   <div class="font-mono font-black text-gray-700 text-base">
                     {{ formatCurrency(seller.cash_balance) }} <span class="text-xs text-gray-400">F</span>
                   </div>
                   <div class="text-[10px] text-gray-500 font-bold">
                     {{ seller.tickets_sold }} billets
                   </div>
              </div>
           </div>
        </div>
      </div>

    </div>

    <!-- Modals -->
    <TripControlModal 
        :show="showTripModal" 
        :trip="selectedTrip"
        @close="showTripModal = false"
        @update="handleTripUpdate"
        @depart="handleTripDeparture"
    />

    <TicketInspectionModal 
        :show="showValidationModal" 
        :validation="selectedValidation"
        @close="showValidationModal = false"
        @approve="handleValidationApprove"
        @decline="handleValidationDecline"
    />

  </MainNavLayout>
</template>

<style scoped>
.animate-pulse-slow {
  animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
