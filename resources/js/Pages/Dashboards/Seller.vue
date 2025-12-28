<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import { ref } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import Bus from 'vue-material-design-icons/Bus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import MenuOpen from 'vue-material-design-icons/MenuOpen.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Modal from '@/Components/Modal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'

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
  status: 'scheduled'
})

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
        <div class="bg-white border border-orange-200 p-12 rounded-3xl flex flex-col items-center text-center shadow-lg max-w-lg">
          <div class="p-5 bg-orange-50 rounded-full shadow-sm mb-6">
            <MapMarker class="w-16 h-16 text-orange-500" />
          </div>
          <h2 class="text-2xl font-black text-gray-900 mb-3">Aucune station assignée</h2>
          <p class="text-gray-600 mb-6 leading-relaxed">
            Vous n'avez pas encore de station assignée. Vous ne pouvez pas vendre de billets tant qu'un superviseur ne vous a pas assigné à une station.
          </p>
          <div class="space-y-3 w-full">
            <p class="text-sm text-gray-500">
              Contactez votre superviseur pour être assigné à une station.
            </p>
            <Link 
              :href="route('profile.edit')" 
              class="inline-flex items-center gap-2 px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-bold transition-colors"
            >
              Voir mon profil
            </Link>
          </div>
        </div>
      </div>

      <!-- Main content (only shown if seller has assigned station or user is admin/supervisor) -->
      <template v-else>
        <!-- Workplace Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 bg-white p-6 rounded-2xl shadow-sm border border-orange-100">
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-3xl font-black text-gray-900 tracking-tight">Tableau de Bord</h1>
              <div v-if="assignedStation" class="px-3 py-1 bg-green-50 text-green-700 text-xs font-black rounded-full border border-green-100 flex items-center gap-1.5 shadow-sm">
                  <MapMarker :size="14" />
                  {{ assignedStation }}
              </div>
            </div>
            <p class="text-gray-500 font-medium">Gestion quotidienne de la billetterie et des départs</p>
          </div>
          <button 
             @click="showCreateTripModal = true"
             class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg shadow-green-600/20 transition-all active:scale-95"
          >
            <Plus :size="20" />
            Nouveau Voyage
          </button>
        </div>

      <!-- Main Section: Voyages Disponibles -->
      <section class="bg-white rounded-2xl shadow-sm border border-orange-100 overflow-hidden">
        <div class="p-6 border-b border-orange-50 bg-gradient-to-r from-white to-orange-50/30 flex items-center justify-between">
            <h2 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                <Bus :size="24" class="text-green-600" />
                Voyages disponibles
            </h2>
            <Link :href="route('seller.ticketing')" class="text-sm font-bold text-green-700 hover:underline">
                Voir tout
            </Link>
        </div>
        
        <div class="p-6">
          <div v-if="trips && trips.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div v-for="trip in trips" :key="trip.id" 
                class="group bg-gray-50 rounded-2xl p-5 border border-transparent hover:border-green-200 hover:bg-white hover:shadow-xl transition-all duration-300 cursor-pointer"
                @click="router.visit(route('seller.ticketing', { trip_id: trip.id }))"
            >
              <div class="flex justify-between items-start mb-4">
                <div class="p-2 bg-white rounded-xl shadow-sm group-hover:bg-green-50 transition-colors">
                    <Bus :size="24" class="text-green-600" />
                </div>
                <div class="text-right">
                    <span class="text-xs font-black text-orange-600 uppercase tracking-widest">{{ trip.vehicle?.identifier }}</span>
                    <div class="text-lg font-black text-gray-900">{{ formatTime(trip.departure_at) }}</div>
                </div>
              </div>
              
              <div class="space-y-3 mb-6">
                <div class="flex items-center gap-2">
                    <MapMarker :size="16" class="text-gray-400" />
                    <span class="font-bold text-gray-700 truncate">{{ trip.display_name || trip.route?.name }}</span>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex-1 bg-white rounded-lg p-2 border border-orange-100">
                        <div class="text-[10px] text-gray-400 font-bold uppercase">Places</div>
                        <div class="flex items-end gap-1">
                            <span class="text-lg font-black text-gray-900">{{ trip.total_seats }}</span>
                            <span class="text-[10px] text-gray-500 mb-1">CAP</span>
                        </div>
                    </div>
                    <div class="flex-1 bg-white rounded-lg p-2 border border-orange-100">
                        <div class="text-[10px] text-gray-400 font-bold uppercase">Restantes</div>
                        <div class="flex items-end gap-1">
                            <span class="text-lg font-black text-green-600">{{ trip.available_seats }}</span>
                            <span class="text-[10px] text-green-600/70 mb-1">LIB</span>
                        </div>
                    </div>
                </div>
              </div>

              <div class="flex items-center justify-between pt-4 border-t border-dashed border-gray-200">
                <span class="text-xs font-medium text-gray-500">{{ formatDate(trip.departure_at) }}</span>
                <span class="flex items-center gap-1 text-xs font-bold text-green-700 group-hover:translate-x-1 transition-transform">
                    Ouvrir la billetterie
                    <ChevronRight :size="16" />
                </span>
              </div>
            </div>
          </div>
          
          <div v-else class="text-center py-16 bg-gray-50 rounded-2xl border border-dashed border-gray-200">
            <Bus :size="48" class="text-gray-300 mx-auto mb-4" />
            <h3 class="text-lg font-bold text-gray-500">Aucun voyage actif</h3>
            <p class="text-gray-400 text-sm max-w-xs mx-auto">Commencez par créer un nouveau voyage pour aujourd'hui.</p>
          </div>
        </div>
      </section>

      <!-- Bottom Grid: Ventes & Autre Mem -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Ventes Section -->
        <section class="bg-white rounded-2xl shadow-sm border border-orange-100 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-orange-50 bg-green-50/50 flex items-center gap-3">
                <Cash :size="24" class="text-green-600" />
                <h2 class="text-lg font-bold text-gray-800">Mes Ventes</h2>
            </div>
            <div class="p-6 flex-1 flex flex-col items-center justify-center text-center space-y-4">
                <div class="w-16 h-16 bg-green-100 rounded-2xl flex items-center justify-center text-green-600 mb-2">
                    <Cash :size="32" />
                </div>
                <div>
                    <div class="text-3xl font-black text-gray-900">
                        {{ (todaySales || 0).toLocaleString('fr-FR') }} 
                        <span class="text-lg font-bold text-gray-400 uppercase">FCFA</span>
                    </div>
                    <p class="text-sm text-gray-500">Total cumulé aujourd'hui</p>
                </div>
                <Link :href="route('seller.tickets.index')" class="w-full py-3 bg-gray-50 hover:bg-gray-100 text-gray-700 text-center font-bold rounded-xl border border-gray-200 transition-colors">
                    Détails des transactions
                </Link>
            </div>
        </section>

        <!-- Autre Menu Section -->
        <section class="bg-white rounded-2xl shadow-sm border border-orange-100 overflow-hidden flex flex-col">
            <div class="p-5 border-b border-orange-50 bg-orange-50/50 flex items-center gap-3">
                <MenuOpen :size="24" class="text-orange-600" />
                <h2 class="text-lg font-bold text-gray-800">Autres Menus</h2>
            </div>
            <div class="p-6 flex-1 grid grid-cols-2 gap-4">
                <button class="flex flex-col items-start p-4 bg-gray-50 hover:bg-orange-50 hover:border-orange-200 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white rounded-lg shadow-sm mb-3 group-hover:text-orange-600 transition-colors">
                        <AccountGroup :size="20" />
                    </div>
                    <span class="font-bold text-gray-700 text-sm">Passagers</span>
                    <span class="text-[10px] text-gray-400">Liste & manifeste</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-gray-50 hover:bg-orange-50 hover:border-orange-200 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white rounded-lg shadow-sm mb-3 group-hover:text-orange-600 transition-colors">
                        <MapMarker :size="20" />
                    </div>
                    <span class="font-bold text-gray-700 text-sm">Arrêts</span>
                    <span class="text-[10px] text-gray-400">Gérer les stations</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-gray-50 hover:bg-orange-50 hover:border-orange-200 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white rounded-lg shadow-sm mb-3 group-hover:text-orange-600 transition-colors">
                        <Clock :size="20" />
                    </div>
                    <span class="font-bold text-gray-700 text-sm">Horaires</span>
                    <span class="text-[10px] text-gray-400">Plannings fixes</span>
                </button>
                <button class="flex flex-col items-start p-4 bg-gray-50 hover:bg-orange-50 hover:border-orange-200 border border-transparent rounded-2xl transition-all group">
                    <div class="p-2 bg-white rounded-lg shadow-sm mb-3 group-hover:text-orange-600 transition-colors">
                        <Bus :size="20" />
                    </div>
                    <span class="font-bold text-gray-700 text-sm">Flotte</span>
                    <span class="text-[10px] text-gray-400">État des camions</span>
                </button>
            </div>
        </section>
      </div>
      </template>

    </div>

    <!-- Create Trip Modal -->
    <Modal :show="showCreateTripModal" @close="showCreateTripModal = false" max-width="md">
      <div class="p-6">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-black text-gray-900 flex items-center gap-2">
            <Plus :size="24" class="text-green-600" />
            Nouveau Voyage
          </h2>
          <button @click="showCreateTripModal = false" class="p-2 hover:bg-gray-100 rounded-lg">
            <Close :size="24" class="text-gray-400" />
          </button>
        </div>

        <form @submit.prevent="createTrip" class="space-y-4">
          <div>
            <InputLabel for="route" value="Trajet" />
            <select
                id="route"
                v-model="createTripForm.route_id"
                class="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-xl shadow-sm"
                required
            >
                <option value="" disabled>Sélectionnez un trajet</option>
                <option v-for="busRoute in routes" :key="busRoute.id" :value="busRoute.id">
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
                class="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-xl shadow-sm"
                required
            >
                <option value="" disabled>Sélectionnez un véhicule</option>
                <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">
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
                class="px-4 py-2 text-sm font-bold text-gray-500 hover:text-gray-700"
            >
              Annuler
            </button>
            <button
                type="submit"
                :disabled="createTripForm.processing"
                class="px-6 py-2.5 bg-green-600 text-white font-bold rounded-xl shadow-lg shadow-green-600/20 hover:bg-green-700 transition-all disabled:opacity-50"
            >
              Créer le Voyage
            </button>
          </div>
        </form>
      </div>
    </Modal>
  </MainNavLayout>
</template>
