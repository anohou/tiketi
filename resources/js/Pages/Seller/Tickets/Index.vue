<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    tickets: Object
});

const printTicket = (ticketId) => {
    window.open(route('tickets.print', { ticket: ticketId }), '_blank');
};

const cancelTicket = (ticketId) => {
    if (confirm('Êtes-vous sûr de vouloir annuler ce ticket ?')) {
        router.delete(route('seller.tickets.destroy', { ticket: ticketId }), {
            onSuccess: () => alert('Ticket annulé avec succès'),
            onError: () => alert('Erreur lors de l\'annulation')
        });
    }
};
</script>

<template>
    <Head title="Mes Tickets" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Mes Tickets Vendus
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 bg-white border-b border-gray-200">
                        <div v-if="tickets.data.length === 0" class="text-center py-8 text-gray-500">
                            <Ticket class="w-12 h-12 mx-auto mb-2 opacity-50" />
                            <p>Aucun ticket vendu pour le moment.</p>
                            <Link :href="route('seller.ticketing')" class="text-green-600 hover:underline mt-2 inline-block">
                                Aller à la billetterie
                            </Link>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">N° Ticket</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voyage</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Passager</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trajet</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="ticket in tickets.data" :key="ticket.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ ticket.ticket_number }}
                                            <div class="text-xs text-gray-500">Place {{ ticket.seat_number }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ ticket.trip?.route?.name }}
                                            <div class="text-xs">{{ ticket.trip?.vehicle?.identifier }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ ticket.passenger_name || 'Anonyme' }}
                                            <div class="text-xs">{{ ticket.passenger_phone }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ ticket.from_station?.name }} → {{ ticket.to_station?.name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-bold">
                                            {{ ticket.price }} FCFA
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ new Date(ticket.created_at).toLocaleString() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                            <button @click="printTicket(ticket.id)" class="text-blue-600 hover:text-blue-900" title="Imprimer">
                                                <Printer />
                                            </button>
                                            <button @click="cancelTicket(ticket.id)" class="text-red-600 hover:text-red-900" title="Annuler">
                                                <Delete />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="mt-4 flex justify-between items-center" v-if="tickets.links.length > 3">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <Link v-if="tickets.prev_page_url" :href="tickets.prev_page_url" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Précédent
                                </Link>
                                <Link v-if="tickets.next_page_url" :href="tickets.next_page_url" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Suivant
                                </Link>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Affichage de <span class="font-medium">{{ tickets.from }}</span> à <span class="font-medium">{{ tickets.to }}</span> sur <span class="font-medium">{{ tickets.total }}</span> résultats
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                        <Link v-for="(link, index) in tickets.links" 
                                              :key="index"
                                              :href="link.url || '#'"
                                              v-html="link.label"
                                              :class="[
                                                  'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                  link.active ? 'z-10 bg-green-50 border-green-500 text-green-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
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
        </div>
    </AuthenticatedLayout>
</template>
