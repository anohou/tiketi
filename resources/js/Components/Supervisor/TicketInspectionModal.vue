<script setup>
import { computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SeatReclineNormal from 'vue-material-design-icons/SeatReclineNormal.vue';

const props = defineProps({
    show: Boolean,
    validation: Object,
});

const emit = defineEmits(['close', 'approve', 'decline']);

const isInspection = computed(() => props.validation?.id?.startsWith('req-'));

const timeAgo = computed(() => {
    const raw = props.validation?.created_at;
    if (!raw) return props.validation?.time_ago || null;
    const diff = Date.now() - new Date(raw).getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return "À l'instant";
    if (minutes < 60) return `Il y a ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `Il y a ${hours}h ${minutes % 60}m`;
    return new Date(raw).toLocaleDateString('fr-FR');
});

const close = () => emit('close');
const approve = () => { emit('approve', props.validation); close(); };
const decline = () => { emit('decline', props.validation); close(); };
</script>

<template>
    <Modal :show="show" @close="close">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-6 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                <div class="bg-rose-100 text-rose-600 p-3 rounded-xl">
                    <SeatReclineNormal :size="32" />
                </div>
                <div>
                    <h2 class="text-lg font-black text-slate-900 leading-tight">
                        {{ isInspection ? 'Inspection du billet' : 'Demande d\'annulation' }}
                    </h2>
                    <p class="text-sm text-rose-600 font-bold mt-0.5">
                        Siège #{{ validation?.seat_number ?? '—' }}
                        <span v-if="validation?.ticket_number"> · {{ validation.ticket_number }}</span>
                    </p>
                </div>
            </div>

            <div class="grid gap-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Vendeur</div>
                        <div class="font-bold text-slate-900">{{ validation?.seller_name || 'Inconnu' }}</div>
                        <div v-if="timeAgo" class="text-xs text-slate-500 mt-0.5">{{ timeAgo }}</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Motif</div>
                        <div class="font-bold text-slate-900">{{ validation?.reason || 'Consultation' }}</div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Contexte siège</div>
                    <div class="inline-flex items-center justify-center gap-1.5 bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-1.5 text-sm font-bold text-rose-600 bg-rose-50 px-3 py-2 rounded-lg border border-rose-200">
                            <SeatReclineNormal :size="18" />
                            Siège {{ validation?.seat_number || '?' }}
                        </div>
                    </div>
                </div>

                <div v-if="!isInspection" class="grid grid-cols-2 gap-3 mt-2">
                    <SecondaryButton class="justify-center py-4" @click="decline">
                        Refuser
                    </SecondaryButton>
                    <DangerButton class="justify-center py-4" @click="approve">
                        Accepter l'Annulation
                    </DangerButton>
                </div>

                <div v-else class="flex justify-center mt-2">
                    <SecondaryButton class="justify-center py-3 px-8" @click="close">
                        Fermer
                    </SecondaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>
