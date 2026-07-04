<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import { ref, onMounted, onUnmounted } from 'vue'
import { router, Link, useForm, usePage } from '@inertiajs/vue3'
import Bus from 'vue-material-design-icons/Bus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import MenuOpen from 'vue-material-design-icons/MenuOpen.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Modal from '@/Components/Modal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'
import { ticketingStore } from '@/Stores/ticketingStore.js'

const props = defineProps({
    trips: Array,
    routes: Array,
    vehicles: Array,
    todaySales: Number,
    hasActiveAssignment: Boolean,
    assignedStation: String
})

const showCreateTripModal = ref(false)
const createTripForm = useForm({
  route_id: '',
  vehicle_id: '',
  departure_at: '',
})

const currentTime = ref('')
const currentDate = ref('')
let clockInterval = null
let subscribedChannels = []
const page = usePage()

const updateClock = () => {
  const now = new Date()
  currentTime.value = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  currentDate.value = now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).toUpperCase()
}
updateClock()

onMounted(() => {
  clockInterval = setInterval(updateClock, 1000)

  const echo = window.Echo
  const stationAssignments = page.props.auth.user?.station_assignments || []
  const stationIds = [...new Set(stationAssignments.map(assignment => String(assignment.station_id)).filter(Boolean))]

  if (echo) {
    stationIds.forEach((stationId) => {
      echo.private(`station.${stationId}`).listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push(`station.${stationId}`)
    })

    if (['admin', 'executive'].includes(page.props.auth.user?.role)) {
      echo.private('trips.global').listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push('trips.global')
    }

    if (['admin', 'executive', 'supervisor', 'seller'].includes(page.props.auth.user?.role)) {
      echo.private('network.global').listen('.SeatMapUpdated', (e) => {
        const tripId = e.trip_id || e.trip?.id
        if (tripId) {
          ticketingStore.pulseTrip(tripId, {
            action: e.action || 'ticket.updated',
            sourceStationId: e.source_station_id || null,
            changedSeats: e.changedSeats || [],
          })
        }
      })
      subscribedChannels.push('network.global')
    }
  }
})

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval)
  const echo = window.Echo
  if (echo) {
    subscribedChannels.forEach((channelName) => {
      echo.leave(channelName)
    })
  }
  subscribedChannels = []
})

const isTripHighlighted = (tripId) => !!ticketingStore.tripHighlights?.[String(tripId)]

const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    })
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit'
    })
}

const createTrip = () => {
  createTripForm.post(route('seller.trips.store'), {
    preserveState: true,
    onSuccess: () => {
      showCreateTripModal.value = false;
      createTripForm.reset();
    }
  });
};
</script>

<template>
  <MainNavLayout>
    <div class="max-w-6xl mx-auto space-y-6">
      
      <!-- Full-page blocking message if no station assigned (for sellers only) -->
      <div v-if="$page.props.auth.user.role === 'seller' && !hasActiveAssignment" 
           class="min-h-[70vh] flex items-center justify-center">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-12 rounded-3xl flex flex-col items-center text-center shadow-lg max-w-lg">
          <div class="p-5 bg-emerald-50 dark:bg-emerald-950/40 rounded-full shadow-sm mb-6">
            <OfficeBuilding class="w-16 h-16 text-emerald-600 dark:text-emerald-400" />
          </div>
          <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 mb-3">Aucune station assignée</h2>
          <p class="text-slate-600 dark:text-slate-400 mb-6 leading-relaxed">
            Vous n'avez pas encore de station assignée. Vous ne pouvez pas vendre de billets tant qu'un superviseur ne vous a pas assigné à une station.
          </p>
          <div class="space-y-3 w-full">
            <p class="text-sm text-slate-500 dark:text-slate-500">
              Contactez votre superviseur pour être assigné à une station.
            </p>
            <Link 
              :href="route('profile.edit')" 
              class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl font-bold transition-colors"
            >
              Voir mon profil
            </Link>
          </div>
        </div>
      </div>

      <!-- Main content (only shown if seller has assigned station or user is admin/supervisor) -->
      <template v-else>
        <!-- Workplace Header (Synced with Ticketing) -->
        <div class="bg-white p-4 md:p-6 rounded-3xl shadow-sm border border-slate-200 shrink-0 relative dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="z-10">
              <div class="flex items-center gap-3">
                <h1 class="text-3xl font-black text-slate-900 dark:text-slate-100 tracking-tight">Tableau de Bord</h1>
                <div v-if="assignedStation" class="px-3 py-1 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 text-xs font-black rounded-full border border-emerald-100 dark:border-emerald-800 flex items-center gap-1.5 shadow-sm">
                    <OfficeBuilding :size="14" />
                    {{ assignedStation }}
                </div>
              </div>
              <p class="text-slate-500 dark:text-slate-450 font-medium">Gestion quotidienne de la billetterie et des départs</p>
            </div>

            <!-- Absolute Centered Clock on Desktop -->
            <div class="hidden md:block absolute left-1/2 -translate-x-1/2 text-center z-0">
              <div class="text-4xl font-black text-slate-900 dark:text-slate-100 tracking-tight leading-none">{{ currentTime }}</div>
              <div class="text-[10px] font-bold text-slate-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
            </div>

            <!-- Clock and Button aligned on mobile / Button on right on Desktop -->
            <div class="flex items-center justify-between md:justify-end gap-4 md:gap-6 mt-2 md:mt-0 w-full md:w-auto z-10 shrink-0">
              <!-- Mobile Clock -->
              <div class="text-left md:hidden">
                <div class="text-2xl font-black text-slate-900 dark:text-slate-100 tracking-tight leading-none">{{ currentTime }}</div>
                <div class="text-[10px] font-bold text-slate-400 tracking-widest mt-1 dark:text-slate-500">{{ currentDate }}</div>
              </div>
              <button 
                 @click="showCreateTripModal = true"
                 class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-xl font-bold shadow-lg shadow-emerald-600/20 transition-all active:scale-95 flex-shrink-0"
              >
                <Plus :size="20" />
                <span>Nouveau Voyage</span>
              </button>
            </div>
          </div>
        </div>

      <!-- Main Section: Voyages Disponibles -->
      <section class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-white to-emerald-50/40 dark:from-slate-900 dark:to-emerald-950/20 flex items-center justify-between">
            <h2 class="text-xl font-bold text-slate-800 dark:text-slate-200 flex items-center gap-3">
                <Bus :size="24" class="text-emerald-600" />
                Voyages disponibles
            </h2>
            <Link :href="route('seller.ticketing')" class="text-sm font-bold text-emerald-700 dark:text-emerald-400 hover:underline">
                Voir tout
            </Link>
        </div>
        
        <div class="p-6">
          <div v-if="trips && trips.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div v-for="trip in trips" :key="trip.id" 
                class="group bg-slate-50 dark:bg-slate-950/40 rounded-3xl p-5 border border-transparent hover:border-emerald-200 dark:hover:border-emerald-850 hover:bg-white dark:hover:bg-slate-900 hover:shadow-xl transition-all duration-300 cursor-pointer"
                :class="isTripHighlighted(trip.id) ? 'ring-2 ring-amber-200 dark:ring-amber-900/40 shadow-xl shadow-amber-200/30 dark:shadow-amber-950/20' : ''"
                @click="router.visit(route('seller.ticketing', { trip_id: trip.id }))"
            >
              <div class="flex justify-between items-start mb-4">
                <div class="p-2 bg-white dark:bg-slate-900 rounded-xl shadow-sm group-hover:bg-emerald-50 dark:group-hover:bg-emerald-950/30 transition-colors">
                    <Bus :size="24" class="text-emerald-600" />
                </div>
                <div class="text-right">
                    <span class="text-xs font-black text-emerald-700 dark:text-emerald-400 uppercase tracking-widest">{{ trip.vehicle?.identifier }}</span>
                    <div class="text-lg font-black text-slate-900 dark:text-slate-100">{{ formatTime(trip.departure_at) }}</div>
                </div>
              </div>
              <div v-if="isTripHighlighted(trip.id)" class="mb-3 inline-flex items-center gap-1.5 rounded-full bg-amber-50 dark:bg-amber-950/20 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-amber-700 dark:text-amber-300">
                <div class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></div>
                Activité temps réel
              </div>
              
              <div class="space-y-3 mb-6">
                <div class="flex items-start gap-2">
                    <OfficeBuilding :size="16" class="text-slate-400 dark:text-slate-500" />
                    <span class="min-w-0 text-sm font-bold text-slate-700 dark:text-slate-300 whitespace-normal break-words leading-snug">
                        {{ trip.display_name || trip.route?.name }}
                    </span>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex-1 bg-white dark:bg-slate-950/30 rounded-xl p-2 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase">Places</div>
                        <div class="flex items-end gap-1">
                            <span class="text-lg font-black text-slate-900 dark:text-slate-100">{{ trip.total_seats }}</span>
                            <span class="text-[10px] text-slate-500 dark:text-slate-400 mb-1">CAP</span>
                        </div>
                    </div>
                    <div class="flex-1 bg-white dark:bg-slate-950/30 rounded-xl p-2 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase">Restantes</div>
                        <div class="flex items-end gap-1">
                            <span class="text-lg font-black text-emerald-600 dark:text-emerald-405">{{ trip.available_seats }}</span>
                            <span class="text-[10px] text-emerald-600/70 dark:text-emerald-400/70 mb-1">LIB</span>
                        </div>
                    </div>
                </div>
              </div>

              <div class="flex items-center justify-between pt-4 border-t border-dashed border-slate-200 dark:border-slate-800">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-450">{{ formatDate(trip.departure_at) }}</span>
                <span class="flex items-center gap-1 text-xs font-bold text-emerald-700 dark:text-emerald-405 group-hover:translate-x-1 transition-transform">
                    Ouvrir la billetterie
                    <ChevronRight :size="16" />
                </span>
              </div>
            </div>
          </div>
          
          <div v-else class="text-center py-16 bg-slate-50 dark:bg-slate-950/30 rounded-3xl border border-dashed border-slate-200 dark:border-slate-800">
            <Bus :size="48" class="text-slate-300 dark:text-slate-700 mx-auto mb-4" />
            <h3 class="text-lg font-bold text-slate-500 dark:text-slate-400">Aucun voyage actif</h3>
            <p class="text-slate-400 dark:text-slate-500 text-sm max-w-xs mx-auto">Commencez par créer un nouveau voyage pour aujourd'hui.</p>
          </div>
        </div>
      </section>

      <!-- Bottom Grid: Ventes & Autre Mem -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Ventes Section -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 bg-emerald-50/50 dark:bg-slate-800/50 flex items-center gap-3">
                <Cash :size="24" class="text-emerald-600" />
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200">Mes Ventes</h2>
            </div>
            <div class="p-6 flex-1 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/30 rounded-2xl flex items-center justify-center text-emerald-600 mb-2">
                    <Cash :size="32" />
                </div>
                <div>
                    <div class="text-3xl font-black text-slate-900 dark:text-slate-100">
                        {{ (todaySales || 0).toLocaleString('fr-FR') }} 
                        <span class="text-lg font-bold text-slate-400 dark:text-slate-500 uppercase">FCFA</span>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Total cumulé aujourd'hui</p>
                </div>
                <Link :href="route('seller.tickets.index')" class="w-full py-3 bg-slate-50 dark:bg-slate-950 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 text-center font-bold rounded-xl border border-slate-200 dark:border-slate-800 transition-colors">
                    Détails des transactions
                </Link>
            </div>
        </section>

        <!-- Autre Menu Section -->
        <section class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex items-center gap-3">
                <MenuOpen :size="24" class="text-slate-600" />
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200">Autres Menus</h2>
            </div>
            <div class="p-6 flex-1 grid grid-cols-2 gap-4">
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <AccountGroup :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Passagers</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Liste & manifeste</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <OfficeBuilding :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Arrêts</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Gérer les stations</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <Clock :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Horaires</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Plannings fixes</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-slate-50 dark:bg-slate-950/40 hover:bg-emerald-50 dark:hover:bg-slate-900 hover:border-emerald-200 dark:hover:border-emerald-900/50 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white dark:bg-slate-900 rounded-lg shadow-sm mb-3 group-hover:text-emerald-600 dark:group-hover:text-emerald-450 transition-colors">
                        <Bus :size="20" />
                    </div>
                    <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Flotte</span>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500">État des véhicules</span>
                </button>
            </div>
        </section>
      </div>
      </template>

    </div>

    <!-- Create Trip Modal -->
    <Modal :show="showCreateTripModal" @close="showCreateTripModal = false" max-width="md">
      <div class="p-6 bg-white dark:bg-slate-900 rounded-2xl">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-2">
            <Plus :size="24" class="text-emerald-600 dark:text-emerald-450" />
            Nouveau Voyage
          </h2>
          <button @click="showCreateTripModal = false" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">
            <Close :size="24" class="text-slate-400 dark:text-slate-500" />
          </button>
        </div>

        <form @submit.prevent="createTrip" class="space-y-4">
          <div>
            <InputLabel for="route" value="Trajet" />
            <select
                id="route"
                v-model="createTripForm.route_id"
                class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
                required
            >
                <option value="" disabled class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Sélectionnez un trajet</option>
                <option v-for="busRoute in routes" :key="busRoute.id" :value="busRoute.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ busRoute.display_name || busRoute.name }}
                </option>
            </select>
            <InputError :message="createTripForm.errors.route_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="vehicle" value="Véhicule" />
            <select
                id="vehicle"
                v-model="createTripForm.vehicle_id"
                class="mt-1 block w-full border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 focus:border-emerald-500 focus:ring-emerald-500 rounded-xl shadow-sm"
                required
            >
                <option value="" disabled class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">Sélectionnez un véhicule</option>
                <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100">
                    {{ vehicle.identifier }} ({{ vehicle.vehicle_type?.name }} - {{ vehicle.vehicle_type?.seat_count }} places)
                </option>
            </select>
            <InputError :message="createTripForm.errors.vehicle_id" class="mt-2" />
          </div>

          <div>
            <InputLabel for="departure_at" value="Date et Heure de Départ" />
            <TextInput
                id="departure_at"
                type="datetime-local"
                class="mt-1 block w-full"
                v-model="createTripForm.departure_at"
                required
            />
            <InputError :message="createTripForm.errors.departure_at" class="mt-2" />
          </div>

          <div class="pt-4 flex items-center justify-end gap-3">
            <button
                type="button"
                @click="showCreateTripModal = false"
                class="px-4 py-2 text-sm font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
            >
              Annuler
            </button>
            <button
                type="submit"
                :disabled="createTripForm.processing"
                class="px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 transition-all disabled:opacity-50"
            >
              Créer le Voyage
            </button>
          </div>
        </form>
      </div>
    </Modal>
  </MainNavLayout>
</template>
