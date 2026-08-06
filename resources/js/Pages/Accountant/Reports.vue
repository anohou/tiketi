<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

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
    const localeKey = t('common.locale_key') || 'fr-FR';
    return new Intl.NumberFormat(localeKey).format(amount);
};

const formatDate = (dateString) => {
    const localeKey = t('common.locale_key') || 'fr-FR';
    return new Date(dateString).toLocaleDateString(localeKey);
};

const formatDateTime = (dateString) => {
    const localeKey = t('common.locale_key') || 'fr-FR';
    return new Date(dateString).toLocaleString(localeKey);
};
</script>

<template>
    <Head :title="$t('accountant.title')" />
    
    <MainNavLayout>
        <div class="max-w-7xl mx-auto space-y-6 text-slate-900 dark:text-slate-100">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3 dark:text-slate-100">
                        <div class="p-2 bg-green-100 rounded-xl dark:bg-green-900/25">
                            <FileDocument class="text-green-600 dark:text-green-400" :size="28" />
                        </div>{{ $t('accountant.title') }}</h1>
                    <p class="text-gray-500 mt-1 dark:text-slate-400">{{ $t('accountant.subtitle') }}</p>
                </div>
                
                <button 
                    @click="exportCsv"
                    class="flex items-center gap-2 px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl font-bold shadow-lg shadow-green-600/20 transition-all active:scale-95"
                >
                    <Download :size="20" />{{ $t('accountant.export_csv') }}</button>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
                <div class="flex items-center gap-2 mb-4 text-gray-700 font-bold dark:text-slate-200">
                    <Filter :size="20" />{{ $t('accountant.filters') }}</div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-slate-300">{{ $t('accountant.start_date') }}</label>
                        <input 
                            type="date" 
                            v-model="filterForm.start_date"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-slate-300">{{ $t('accountant.end_date') }}</label>
                        <input 
                            type="date" 
                            v-model="filterForm.end_date"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-slate-300">{{ $t('accountant.station') }}</label>
                        <select 
                            v-model="filterForm.station_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="">{{ $t('accountant.all_stations') }}</option>
                            <option v-for="station in stations" :key="station.id" :value="station.id">
                                {{ station.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-slate-300">{{ $t('accountant.seller') }}</label>
                        <select 
                            v-model="filterForm.seller_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="">{{ $t('accountant.all_sellers') }}</option>
                            <option v-for="seller in sellers" :key="seller.id" :value="seller.id">
                                {{ seller.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button 
                        @click="applyFilters"
                        class="px-5 py-2 bg-gray-900 hover:bg-gray-800 text-white rounded-lg font-medium transition-colors dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >{{ $t('accountant.apply_filters') }}</button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <Cash :size="24" />
                        </div>
                        <span class="font-bold text-green-100">{{ $t('accountant.total_revenue') }}</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(stats.total_revenue) }}</div>
                    <div class="text-green-100 text-sm mt-1">FCFA</div>
                </div>

                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <Ticket :size="24" />
                        </div>
                        <span class="font-bold text-blue-100">{{ $t('accountant.tickets_sold') }}</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(stats.total_tickets) }}</div>
                    <div class="text-blue-100 text-sm mt-1">{{ $t('accountant.tickets_unit') }}</div>
                </div>

                <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl p-6 text-white shadow-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/20 rounded-xl">
                            <ChartLine :size="24" />
                        </div>
                        <span class="font-bold text-orange-100">{{ $t('accountant.avg_price') }}</span>
                    </div>
                    <div class="text-4xl font-black">{{ formatCurrency(Math.round(stats.avg_ticket_price)) }}</div>
                    <div class="text-orange-100 text-sm mt-1">{{ $t('accountant.avg_price_unit') }}</div>
                </div>
            </div>

            <!-- Revenue Breakdown -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- By Seller -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4 dark:text-slate-100">
                        <Account :size="20" class="text-gray-500 dark:text-slate-400" />{{ $t('accountant.revenue_by_seller') }}</h3>
                    <div class="space-y-3">
                        <div 
                            v-for="item in revenueBySeller" 
                            :key="item.seller_id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-xl dark:bg-slate-800/70"
                        >
                            <div>
                                <div class="font-bold text-gray-900 dark:text-slate-100">{{ item.seller?.name || $t('accountant.unknown') }}</div>
                                <div class="text-sm text-gray-500 dark:text-slate-400">{{ $t('accountant.tickets_count', { count: item.count }) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-black text-green-600">{{ formatCurrency(item.total) }} F</div>
                            </div>
                        </div>
                        <div v-if="revenueBySeller.length === 0" class="text-center py-6 text-gray-400 dark:text-slate-500">{{ $t('common.no_data') }}</div>
                    </div>
                </div>

                <!-- By Station -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
                    <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4 dark:text-slate-100">
                        <OfficeBuilding :size="20" class="text-gray-500 dark:text-slate-400" />{{ $t('accountant.revenue_by_station') }}</h3>
                    <div class="space-y-3">
                        <div 
                            v-for="item in revenueByStation" 
                            :key="item.station_id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-xl dark:bg-slate-800/70"
                        >
                            <div>
                                <div class="font-bold text-gray-900 dark:text-slate-100">{{ item.station_name }}</div>
                                <div class="text-sm text-gray-500 dark:text-slate-400">{{ $t('accountant.tickets_count', { count: item.count }) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-black text-green-600">{{ formatCurrency(item.total) }} F</div>
                            </div>
                        </div>
                        <div v-if="revenueByStation.length === 0" class="text-center py-6 text-gray-400 dark:text-slate-500">{{ $t('common.no_data') }}</div>
                    </div>
                </div>
            </div>

            <!-- Tickets Table -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-slate-800">
                    <h3 class="font-bold text-gray-900 dark:text-slate-100">{{ $t('accountant.ticket_detail') }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 text-left dark:bg-slate-800/60">
                            <tr>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-slate-400">{{ $t('accountant.col_ticket_number') }}</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-slate-400">{{ $t('common.date') }}</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-slate-400">{{ $t('accountant.col_route') }}</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-slate-400">{{ $t('accountant.col_journey') }}</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-slate-400">{{ $t('accountant.seller') }}</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider text-right dark:text-slate-400">{{ $t('accountant.col_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="ticket in tickets.data" :key="ticket.id" class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <div class="font-mono font-bold text-gray-900">{{ ticket.ticket_number }}</div>
                                    <div class="text-xs text-gray-500">{{ $t('accountant.seat_label', { number: ticket.seat_number }) }}</div>
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
                                <td colspan="6" class="px-5 py-12 text-center text-gray-400">{{ $t('accountant.no_tickets_period') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="tickets.links && tickets.links.length > 3" class="px-5 py-4 border-t border-gray-100 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        {{ $t('accountant.showing_results', { from: tickets.from, to: tickets.to, total: tickets.total }) }}
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
