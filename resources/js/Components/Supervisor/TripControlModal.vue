<script setup>
import { ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import AccountTie from 'vue-material-design-icons/AccountTie.vue';
import ClockTimeFive from 'vue-material-design-icons/ClockTimeFive.vue';
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue';

const props = defineProps({
    show: Boolean,
    trip: Object,
});

const emit = defineEmits(['close', 'update']);

const form = ref({
    vehicle_id: props.trip?.vehicle_id,
    driver_id: props.trip?.driver_id,
});

const close = () => {
    emit('close');
};

const save = () => {
    // Implement save logic (emit update)
    emit('update', form.value);
    close();
};
</script>

<template>
    <Modal :show="show" @close="close">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-black text-gray-900 flex items-center gap-2">
                        <Bus class="text-green-600" :size="28" />
                        Gestion du Voyage
                    </h2>
                    <div class="text-sm text-gray-500 font-bold mt-1">
                        {{ trip?.origin }} ➜ {{ trip?.destination }}
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-black text-gray-900">{{ trip?.departure_time }}</div>
                    <div class="text-xs bg-green-100 text-green-700 font-bold px-2 py-0.5 rounded-full inline-block">
                        {{ trip?.occupancy_percent }}% Rempli
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid gap-6">
                
                <!-- Vehicle Assignment -->
                <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2 flex items-center gap-2">
                        <Bus :size="16" /> Véhicule
                    </label>
                    <div v-if="trip?.license_plate" class="flex items-center justify-between bg-white p-3 rounded-xl border border-gray-200">
                        <div class="font-mono font-bold text-gray-900 text-lg">{{ trip.license_plate }}</div>
                        <button class="text-xs font-bold text-orange-600 hover:text-orange-700">Changer</button>
                    </div>
                    <button v-else class="w-full py-3 bg-red-50 border-2 border-dashed border-red-200 rounded-xl text-red-600 font-bold flex items-center justify-center gap-2 hover:bg-red-100 transition-colors">
                        <Bus :size="20" /> Assigner un Car
                    </button>
                </div>

                <!-- Driver Assignment (Future) -->
                <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 opacity-75">
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2 flex items-center gap-2">
                        <AccountTie :size="16" /> Chauffeur
                    </label>
                    <div class="text-sm font-medium text-gray-400 italic">Non assigné</div>
                </div>

                <!-- Manifest Button -->
                <button class="w-full py-4 bg-gray-900 text-white rounded-2xl font-bold flex items-center justify-center gap-3 shadow-lg hover:bg-gray-800 active:scale-[0.98] transition-all">
                    <ClipboardList :size="24" />
                    Voir le Manifeste Passagers
                </button>

                <!-- Departure Action -->
                <div v-if="trip?.status !== 'departed'" class="pt-2">
                    <PrimaryButton class="w-full justify-center py-4 bg-green-600 hover:bg-green-700 shadow-green-200" @click="emit('depart', trip)">
                        <ClockTimeFive class="mr-2" /> Lancer le Départ
                    </PrimaryButton>
                </div>

                <!-- Footer Actions -->
                <div class="flex gap-3 mt-4 pt-4 border-t border-gray-100">
                    <SecondaryButton class="flex-1 justify-center py-3" @click="close">
                        Fermer
                    </SecondaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>
