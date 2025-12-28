<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
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

const props = defineProps({
  departures: Array,
  validations: Array,
  sellers: Array, // Active sellers mock
  user_stations: Array,
});

// State for Modals
const showTripModal = ref(false);
const selectedTrip = ref(null);

const showValidationModal = ref(false);
const selectedValidation = ref(null);

// Computed counts
const pendingValidationCount = computed(() => props.validations.length);

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

// Polling Logic
let pollInterval;

onMounted(() => {
    pollInterval = setInterval(() => {
        router.reload({
            only: ['departures', 'validations', 'sellers'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 30000); // 30 seconds
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
  <Head title="Supervision" />

  <MainNavLayout>
    <div class="max-w-3xl mx-auto space-y-6 pb-20">
      
      <!-- Top Bar: Station Scope -->
      <div class="flex items-center justify-between px-2 pt-2">
        <div>
          <h1 class="text-2xl font-black text-gray-900 tracking-tight">Tour de Contrôle</h1>
          <p class="text-sm text-gray-500 font-medium truncate max-w-[250px]">{{ user_stations.join(', ') }}</p>
        </div>
        
        <!-- Quick Stats / Status -->
        <div class="flex gap-2">
           <div class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold flex items-center gap-1 shadow-sm border border-green-200">
             <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
             Live
           </div>
        </div>
      </div>

      <!-- Zone C: Validation Alert (if any) -->
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

      <!-- Zone A: Live Feed (Departures) -->
      <div class="space-y-4 px-1">
        <div class="flex items-center justify-between px-1">
           <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
             <Bus class="text-gray-400" /> Prochains Départs
           </h2>
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
                          <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider" v-if="trip.status === 'boarding'">
                              Embarquement
                          </div>
                      </div>
                      <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mt-1 flex items-center gap-1">
                        <span>{{ trip.origin }}</span>
                        <span class="text-gray-300">➜</span> 
                        <span class="text-gray-900">{{ trip.destination }}</span>
                      </div>
                   </div>
                   
                   <!-- Vehicle Badge -->
                   <div v-if="trip.license_plate" class="bg-gray-50 px-2.5 py-1.5 rounded-xl text-xs font-mono font-bold text-gray-600 border border-gray-100 flex items-center gap-2">
                       <Bus :size="14" class="text-gray-400" />
                       {{ trip.license_plate }}
                   </div>
                   <button v-else class="bg-red-50 px-2 py-1.5 rounded-xl text-xs font-bold text-red-600 border border-red-100 flex items-center gap-1 animate-pulse">
                      <AlertCircle :size="14" /> Assigner Car
                   </button>
                </div>

                <!-- Occupancy Bar -->
                <div>
                   <div class="flex justify-between text-xs font-medium mb-2">
                      <span :class="trip.occupancy_percent < 50 && trip.status !== 'created' ? 'text-red-600 font-bold' : 'text-gray-600'">
                        {{ trip.occupancy_percent }}% Rempli
                      </span>
                      <span class="text-gray-400">{{ trip.available_seats }} places libres</span>
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

      <!-- Zone B: Cash Control Preview -->
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
                     {{ seller.cash_balance.toLocaleString() }} <span class="text-xs text-gray-400">F</span>
                   </div>
                   <div class="text-[10px] text-green-600 font-bold bg-green-50 px-1.5 py-0.5 rounded inline-block">
                     Online
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
