<script setup>
import { ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import TicketAccount from 'vue-material-design-icons/TicketAccount.vue';
import SeatReclineNormal from 'vue-material-design-icons/SeatReclineNormal.vue';

const props = defineProps({
    show: Boolean,
    validation: Object,
});

const emit = defineEmits(['close', 'approve', 'decline']);

const close = () => {
    emit('close');
};

const approve = () => {
    emit('approve', props.validation);
    close();
};

const decline = () => {
    emit('decline', props.validation);
    close();
};
</script>

<template>
    <Modal :show="show" @close="close">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center gap-4 mb-6 bg-red-50 p-4 rounded-2xl border border-red-100">
                <div class="bg-red-100 text-red-600 p-3 rounded-xl">
                    <AlertCircle :size="32" />
                </div>
                <div>
                    <h2 class="text-lg font-black text-gray-900 leading-tight">Demande d'Annulation</h2>
                    <p class="text-sm text-red-600 font-bold mt-0.5">Ticket #{{ validation?.ticket_number }}</p>
                </div>
            </div>

            <div class="grid gap-6">
                <!-- Reason & Seller -->
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Motif</div>
                        <div class="font-bold text-gray-900">{{ validation?.reason }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Vendeur</div>
                        <div class="font-bold text-gray-900">{{ validation?.seller_name }}</div>
                        <div class="text-xs text-gray-500">{{ validation?.time_ago }}</div>
                    </div>
                </div>

                <!-- Seat Context (Mock Visual) -->
                <div class="border rounded-2xl p-4 bg-gray-50 text-center">
                    <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Contexte Siège</div>
                    
                    <!-- Simplified Visualizer -->
                    <div class="inline-flex gap-2 items-center justify-center bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
                        <div class="w-10 h-10 bg-gray-200 rounded text-gray-400 flex items-center justify-center font-bold">1</div>
                        <div class="w-10 h-10 bg-red-100 border-2 border-red-500 rounded text-red-600 flex items-center justify-center font-bold relative">
                            2
                            <div class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full p-0.5">
                                <TicketAccount :size="12" />
                            </div>
                        </div>
                        <div class="w-10 h-10 bg-gray-200 rounded text-gray-400 flex items-center justify-center font-bold">3</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2 font-medium">Ce siège a été vendu il y a 2h 15m</p>
                </div>

                <!-- Actions -->
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <SecondaryButton class="justify-center py-4" @click="decline">
                        Refuser
                    </SecondaryButton>
                    <DangerButton class="justify-center py-4" @click="approve">
                        Accepter l'Annulation
                    </DangerButton>
                </div>
            </div>
        </div>
    </Modal>
</template>
