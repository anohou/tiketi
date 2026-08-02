<script setup>
import { ref } from 'vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { toastStore } from '@/Stores/toastStore.js';

const props = defineProps({
    tickets: Object
});

const printTicket = (ticketId) => {
    window.open(route('tickets.print', { ticket: ticketId }), '_blank');
};

const showCancelModal = ref(false);
const ticketIdToCancel = ref(null);

const confirmCancelTicket = (ticketId) => {
    ticketIdToCancel.value = ticketId;
    showCancelModal.value = true;
};

const cancelTicket = () => {
    if (!ticketIdToCancel.value) return;
    showCancelModal.value = false;
    
    router.delete(route('seller.tickets.destroy', { ticket: ticketIdToCancel.value }), {
        onSuccess: () => {
            toastStore.success('Ticket annulé avec succès');
            ticketIdToCancel.value = null;
        },
        onError: () => {
            toastStore.error('Erreur lors de l\'annulation');
            ticketIdToCancel.value = null;
        }
    });
};
</script>

<template>
    <Head title="Mes Tickets" />

    <MainNavLayout :show-nav="true">
        <div class="space-y-6">
            <!-- Header Page -->
            <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800">
                <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 tracking-tight">Mes Tickets Vendus</h1>
                <p class="text-gray-500 dark:text-slate-450 font-medium">Historique des ventes de tickets en temps réel</p>
            </div>

            <!-- Content Area -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-6">
                    <div v-if="tickets.data.length === 0" class="py-8">
                        <EmptyState
                            title="Aucun ticket vendu"
                            message="Vous n'avez pas encore vendu de ticket pour le moment."
                            :icon="Ticket"
                            actionText="Aller à la billetterie"
                            @action="router.visit(route('seller.ticketing'))"
                        />
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800">
                            <thead>
                                <tr class="bg-slate-50/50 dark:bg-slate-950/40">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">N° Ticket</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Voyage</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Passager</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Trajet</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Prix</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-4 text-right text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-slate-900 divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="ticket in tickets.data" :key="ticket.id" class="hover:bg-slate-50/30 dark:hover:bg-slate-950/30 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900 dark:text-slate-100">
                                        <div class="font-bold text-slate-800 dark:text-slate-200">{{ ticket.ticket_number }}</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-450 font-medium">Place {{ ticket.seat_number }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">
                                        <div class="font-bold">{{ ticket.trip?.route?.name }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500 font-medium">{{ ticket.trip?.vehicle?.identifier }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">
                                        <div class="font-bold text-slate-800 dark:text-slate-200">{{ ticket.passenger_name || 'Anonyme' }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500 font-medium">{{ ticket.passenger_phone }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 dark:text-slate-300 font-semibold">
                                        {{ ticket.from_station?.name }} → {{ ticket.to_station?.name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-950 dark:text-slate-100 font-black">
                                        {{ ticket.price.toLocaleString('fr-FR') }} F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 dark:text-slate-400 font-medium">
                                        {{ new Date(ticket.created_at).toLocaleString('fr-FR') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                        <button @click="printTicket(ticket.id)" class="p-2 text-slate-400 dark:text-slate-500 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-xl transition-all" title="Imprimer">
                                            <Printer :size="20" />
                                        </button>
                                        <button @click="confirmCancelTicket(ticket.id)" class="p-2 text-slate-400 dark:text-slate-500 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-xl transition-all" title="Annuler">
                                            <Delete :size="20" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6 flex justify-between items-center" v-if="tickets.links.length > 3">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <Link v-if="tickets.prev_page_url" :href="tickets.prev_page_url" class="relative inline-flex items-center px-4 py-2 border border-slate-300 dark:border-slate-700 text-sm font-medium rounded-xl text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800">
                                Précédent
                            </Link>
                            <Link v-if="tickets.next_page_url" :href="tickets.next_page_url" class="ml-3 relative inline-flex items-center px-4 py-2 border border-slate-300 dark:border-slate-700 text-sm font-medium rounded-xl text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800">
                                Suivant
                            </Link>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-450 font-medium">
                                    Affichage de <span class="font-bold text-slate-800 dark:text-slate-200">{{ tickets.from }}</span> à <span class="font-bold text-slate-800 dark:text-slate-200">{{ tickets.to }}</span> sur <span class="font-bold text-slate-800 dark:text-slate-200">{{ tickets.total }}</span> résultats
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-xl shadow-sm -space-x-px" aria-label="Pagination">
                                    <Link v-for="(link, index) in tickets.links" 
                                          :key="index"
                                          :href="link.url || '#'"
                                          v-html="link.label"
                                          :class="[
                                              'relative inline-flex items-center px-4 py-2 border text-sm font-medium transition-colors first:rounded-l-xl last:rounded-r-xl',
                                              link.active ? 'z-10 bg-emerald-50 dark:bg-emerald-950/30 border-emerald-500 dark:border-emerald-700 text-emerald-600 dark:text-emerald-400 font-bold' : 'bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-900',
                                              !link.url ? 'cursor-not-allowed opacity-50' : ''
                                          ]"
                                    />
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainNavLayout>

    <!-- Custom Confirmation Modal for Cancel Ticket -->
    <ConfirmationModal :show="showCancelModal" variant="danger" @close="showCancelModal = false">
        <template #title>
            Annuler le ticket
        </template>
        <template #content>
            Êtes-vous sûr de vouloir annuler ce ticket ? Cette action est irréversible et libérera immédiatement la place réservée dans le bus.
        </template>
        <template #footer>
            <SecondaryButton @click="showCancelModal = false">
                Garder le ticket
            </SecondaryButton>
            <DangerButton class="ml-3" @click="cancelTicket">
                Oui, Annuler
            </DangerButton>
        </template>
    </ConfirmationModal>
</template>
