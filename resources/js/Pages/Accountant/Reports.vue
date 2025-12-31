<script setup>
import { ref, computed, watch } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FileDocument from 'vue-material-design-icons/FileDocument.vue';
import Download from 'vue-material-design-icons/Download.vue';
import Cash from 'vue-material-design-icons/CashMultiple.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Filter from 'vue-material-design-icons/Filter.vue';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import Account from 'vue-material-design-icons/Account.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';

const props = defineProps({
    tickets: Object,
    stats: Object,
    revenueBySeller: Array,
    revenueByStation: Array,
    dailyRevenue: Array,
    filters: Object,
    stations: Array,
    sellers: Array,
});

// Filter form
const filterForm = ref({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    station_id: props.filters.station_id || '',
    seller_id: props.filters.seller_id || '',
});

const applyFilters = () => {
    router.get(route('accountant.reports'), {
        start_date: filterForm.value.start_date,
        end_date: filterForm.value.end_date,
        station_id: filterForm.value.station_id || undefined,
        seller_id: filterForm.value.seller_id || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const exportCsv = () => {
    const params = new URLSearchParams({
        start_date: filterForm.value.start_date,
        end_date: filterForm.value.end_date,
    });
    if (filterForm.value.station_id) params.append('station_id', filterForm.value.station_id);
    if (filterForm.value.seller_id) params.append('seller_id', filterForm.value.seller_id);
    
    window.location.href = route('accountant.export') + '?' + params.toString();
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR').format(amount);
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('fr-FR');
};

const formatDateTime = (dateString) => {
    return new Date(dateString).toLocaleString('fr-FR');
};
</script>

<template>
    <Head title="Rapports Financiers" />
    
    <MainNavLayout>
        <div class="max-w-7xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
                        <div class="p-2 bg-green-100 rounded-xl">
                            <FileDocument class="text-green-600" :size="28" />
                        </div>
                        Rapports Financiers
                    </h1>
                    <p class="text-gray-500 mt-1">Analyse détaillée des ventes et revenus</p>
                </div>
                
                <button 
                    @click="exportCsv"
                    class="flex items-center gap-2 px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl font-bold shadow-lg shadow-green-600/20 transition-all active:scale-95"
                >
                    <Download :size="20" />
                    Exporter CSV
                </button>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 text-gray-700 font-bold">
                    <Filter :size="20" />
                    Filtres
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date début</label>
                        <input 
                            type="date" 
                            v-model="filterForm.start_date"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date fin</label>
                        <input 
                            type="date" 
                            v-model="filterForm.end_date"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Station</label>
                        <select 
                            v-model="filterForm.station_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        >
                            <option value="">Toutes les stations</option>
                            <option v-for="station in stations" :key="station.id" :value="station.id">
                                {{ station.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vendeur</label>
                        <select 
                            v-model="filterForm.seller_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500"
                        >
                            <option value="">Tous les vendeurs</option>
                            <option v-for="seller in sellers" :key="seller.id" :value="seller.id">
                                {{ seller.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button 
                        @click="applyFilters"
                        class="px-5 py-2 bg-gray-900 hover:bg-gray-800 text-white rounded-lg font-medium transition-colors"
                    >
                        Appliquer les filtres
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <Cash :size="24" />
                        </div>
                        <span class="font-bold text-green-100">Total Revenus</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(stats.total_revenue) }}</div>
                    <div class="text-green-100 text-sm mt-1">FCFA</div>
                </div>

                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <Ticket :size="24" />
                        </div>
                        <span class="font-bold text-blue-100">Tickets Vendus</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(stats.total_tickets) }}</div>
                    <div class="text-blue-100 text-sm mt-1">billets</div>
                </div>

                <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <ChartLine :size="24" />
                        </div>
                        <span class="font-bold text-orange-100">Prix Moyen</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(Math.round(stats.avg_ticket_price)) }}</div>
                    <div class="text-orange-100 text-sm mt-1">FCFA / ticket</div>
                </div>
            </div>

            <!-- Revenue Breakdown -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- By Seller -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4">
                        <Account :size="20" class="text-gray-500" />
                        Revenus par Vendeur
                    </h3>
                    <div class="space-y-3">
                        <div 
                            v-for="item in revenueBySeller" 
                            :key="item.seller_id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-xl"
                        >
                            <div>
                                <div class="font-bold text-gray-900">{{ item.seller?.name || 'Inconnu' }}</div>
                                <div class="text-sm text-gray-500">{{ item.count }} tickets</div>
                            </div>
                            <div class="text-right">
                                <div class="font-black text-green-600">{{ formatCurrency(item.total) }} F</div>
                            </div>
                        </div>
                        <div v-if="revenueBySeller.length === 0" class="text-center py-6 text-gray-400">
                            Aucune donnée disponible
                        </div>
                    </div>
                </div>

                <!-- By Station -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4">
                        <OfficeBuilding :size="20" class="text-gray-500" />
                        Revenus par Station
                    </h3>
                    <div class="space-y-3">
                        <div 
                            v-for="item in revenueByStation" 
                            :key="item.station_id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-xl"
                        >
                            <div>
                                <div class="font-bold text-gray-900">{{ item.station_name }}</div>
                                <div class="text-sm text-gray-500">{{ item.count }} tickets</div>
                            </div>
                            <div class="text-right">
                                <div class="font-black text-green-600">{{ formatCurrency(item.total) }} F</div>
                            </div>
                        </div>
                        <div v-if="revenueByStation.length === 0" class="text-center py-6 text-gray-400">
                            Aucune donnée disponible
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tickets Table -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900">Détail des Tickets</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">N° Ticket</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Route</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Trajet</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Vendeur</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="ticket in tickets.data" :key="ticket.id" class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <div class="font-mono font-bold text-gray-900">{{ ticket.ticket_number }}</div>
                                    <div class="text-xs text-gray-500">Place {{ ticket.seat_number }}</div>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600">
                                    {{ formatDateTime(ticket.created_at) }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-900 font-medium">
                                    {{ ticket.trip?.route?.name || '-' }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600">
                                    {{ ticket.from_station?.name }} → {{ ticket.to_station?.name }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600">
                                    {{ ticket.seller?.name || '-' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <span class="font-bold text-green-600">{{ formatCurrency(ticket.price) }} F</span>
                                </td>
                            </tr>
                            <tr v-if="tickets.data.length === 0">
                                <td colspan="6" class="px-5 py-12 text-center text-gray-400">
                                    Aucun ticket trouvé pour cette période
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="tickets.links && tickets.links.length > 3" class="px-5 py-4 border-t border-gray-100 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        Affichage de {{ tickets.from }} à {{ tickets.to }} sur {{ tickets.total }} résultats
                    </div>
                    <div class="flex gap-1">
                        <Link 
                            v-for="(link, index) in tickets.links" 
                            :key="index"
                            :href="link.url || '#'"
                            v-html="link.label"
                            :class="[
                                'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                                link.active 
                                    ? 'bg-green-600 text-white' 
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
                                !link.url ? 'opacity-50 cursor-not-allowed' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>
    </MainNavLayout>
</template>
