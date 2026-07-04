<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Chart from 'chart.js/auto';
import { useTheme } from '@/Composables/useTheme.js';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue';
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue';
import Cash from 'vue-material-design-icons/CashMultiple.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Gauge from 'vue-material-design-icons/Gauge.vue';
import Routes from 'vue-material-design-icons/Routes.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';

const props = defineProps({
    kpis: Object,
    revenueTrend: Array,
    topRoutes: Array,
    revenueByStation: Array,
    fleetUtilization: Number,
    monthlyRevenue: Array,
    period: String,
    dateRange: Object,
});

const { isDark } = useTheme();

const periods = [
    { value: 'day', label: 'Aujourd\'hui' },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'quarter', label: 'Ce trimestre' },
    { value: 'year', label: 'Cette année' },
];

const selectedPeriod = ref(props.period);

const changePeriod = (period) => {
    selectedPeriod.value = period;
    router.get(route('executive.analytics'), { period }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const formatCurrency = (amount) => {
    if (amount >= 1000000) {
        return (amount / 1000000).toFixed(1) + 'M';
    } else if (amount >= 1000) {
        return (amount / 1000).toFixed(0) + 'K';
    }
    return new Intl.NumberFormat('fr-FR').format(amount);
};

const formatFullCurrency = (amount) => {
    return new Intl.NumberFormat('fr-FR').format(amount);
};

// Chart refs
const revenueTrendChartRef = ref(null);
const stationChartRef = ref(null);

let revenueTrendChart = null;
let stationChart = null;

const renderCharts = () => {
    if (revenueTrendChart) revenueTrendChart.destroy();
    if (stationChart) stationChart.destroy();

    const textColor = isDark.value ? '#94a3b8' : '#64748b';
    const gridColor = isDark.value ? '#334155' : '#e2e8f0';

    // Revenue Trend Chart
    if (revenueTrendChartRef.value && props.revenueTrend.length > 0) {
        revenueTrendChart = new Chart(revenueTrendChartRef.value, {
            type: 'line',
            data: {
                labels: props.revenueTrend.map(item => item.date),
                datasets: [{
                    label: 'Revenus',
                    data: props.revenueTrend.map(item => item.revenue),
                    borderColor: '#059669',
                    backgroundColor: isDark.value ? 'rgba(5, 150, 105, 0.2)' : 'rgba(5, 150, 105, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#059669',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => formatFullCurrency(context.raw) + ' FCFA'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: {
                            callback: (value) => formatCurrency(value),
                            color: textColor
                        }
                    },
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    }
                }
            }
        });
    }

    // Station Revenue Chart (Doughnut)
    if (stationChartRef.value && props.revenueByStation.length > 0) {
        stationChart = new Chart(stationChartRef.value, {
            type: 'doughnut',
            data: {
                labels: props.revenueByStation.slice(0, 5).map(item => item.name),
                datasets: [{
                    data: props.revenueByStation.slice(0, 5).map(item => item.revenue),
                    backgroundColor: [
                        '#059669',
                        '#3B82F6',
                        '#F59E0B',
                        '#EF4444',
                        '#8B5CF6',
                    ],
                    borderColor: isDark.value ? '#1e293b' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            color: textColor
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => formatFullCurrency(context.raw) + ' FCFA'
                        }
                    }
                }
            }
        });
    }
};

onMounted(() => {
    renderCharts();
});

watch(isDark, () => {
    renderCharts();
});
</script>

<template>
    <Head title="Tableau de Bord Exécutif" />
    
    <MainNavLayout>
        <div class="max-w-7xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
                        <div class="p-2 bg-purple-100 dark:bg-purple-950/50 rounded-xl">
                            <ChartLine class="text-purple-600 dark:text-purple-400" :size="28" />
                        </div>
                        Tableau de Bord Exécutif
                    </h1>
                    <p class="text-gray-500 dark:text-slate-400 mt-1">
                        Vue stratégique • {{ dateRange.start }} au {{ dateRange.end }}
                    </p>
                </div>
                
                <!-- Period Selector -->
                <div class="flex gap-2 bg-gray-100 dark:bg-slate-900 p-1 rounded-xl">
                    <button 
                        v-for="p in periods" 
                        :key="p.value"
                        @click="changePeriod(p.value)"
                        :class="[
                            'px-4 py-2 rounded-lg text-sm font-bold transition-all',
                            selectedPeriod === p.value 
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-slate-800 dark:text-slate-100' 
                                : 'text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-200'
                        ]"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Revenue -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-2 bg-green-100 dark:bg-green-950/50 rounded-xl">
                            <Cash :size="24" class="text-green-600 dark:text-emerald-400" />
                        </div>
                        <div :class="[
                            'flex items-center gap-1 text-sm font-bold px-2 py-1 rounded-full',
                            kpis.revenue.growth >= 0 
                                ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' 
                                : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400'
                        ]">
                            <TrendingUp v-if="kpis.revenue.growth >= 0" :size="16" />
                            <TrendingDown v-else :size="16" />
                            {{ Math.abs(kpis.revenue.growth) }}%
                        </div>
                    </div>
                    <div class="text-3xl font-black text-gray-900 dark:text-slate-100">{{ formatCurrency(kpis.revenue.current) }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400 mt-1">Revenus (FCFA)</div>
                </div>

                <!-- Tickets -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-2 bg-blue-100 dark:bg-blue-950/50 rounded-xl">
                            <Ticket :size="24" class="text-blue-600 dark:text-blue-400" />
                        </div>
                        <div :class="[
                            'flex items-center gap-1 text-sm font-bold px-2 py-1 rounded-full',
                            kpis.tickets.growth >= 0 
                                ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' 
                                : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400'
                        ]">
                            <TrendingUp v-if="kpis.tickets.growth >= 0" :size="16" />
                            <TrendingDown v-else :size="16" />
                            {{ Math.abs(kpis.tickets.growth) }}%
                        </div>
                    </div>
                    <div class="text-3xl font-black text-gray-900 dark:text-slate-100">{{ formatCurrency(kpis.tickets.current) }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400 mt-1">Billets vendus</div>
                </div>

                <!-- Average Occupancy -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-2 bg-orange-100 dark:bg-orange-950/50 rounded-xl">
                            <Gauge :size="24" class="text-orange-600 dark:text-orange-400" />
                        </div>
                    </div>
                    <div class="text-3xl font-black text-gray-900 dark:text-slate-100">{{ kpis.avg_occupancy }}%</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400 mt-1">Taux d'occupation moyen</div>
                    <div class="mt-3 h-2 bg-gray-200 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div 
                            class="h-full bg-orange-500 rounded-full transition-all"
                            :style="{ width: kpis.avg_occupancy + '%' }"
                        ></div>
                    </div>
                </div>

                <!-- Fleet Utilization -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-2 bg-purple-100 dark:bg-purple-950/50 rounded-xl">
                            <Bus :size="24" class="text-purple-600 dark:text-purple-400" />
                        </div>
                    </div>
                    <div class="text-3xl font-black text-gray-900 dark:text-slate-100">{{ fleetUtilization }}%</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400 mt-1">Utilisation de la flotte</div>
                    <div class="mt-3 h-2 bg-gray-200 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div 
                            class="h-full bg-purple-500 rounded-full transition-all"
                            :style="{ width: fleetUtilization + '%' }"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Revenue Trend -->
                <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <h3 class="font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2 mb-4">
                        <ChartLine :size="20" class="text-gray-500 dark:text-slate-400" />
                        Tendance des Revenus
                    </h3>
                    <div class="h-[300px]">
                        <canvas ref="revenueTrendChartRef"></canvas>
                    </div>
                </div>

                <!-- Revenue by Station -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                    <h3 class="font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2 mb-4">
                        <OfficeBuilding :size="20" class="text-gray-500 dark:text-slate-400" />
                        Revenus par Station
                    </h3>
                    <div class="h-[300px]">
                        <canvas ref="stationChartRef"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Routes -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-slate-800 p-6 shadow-sm">
                <h3 class="font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2 mb-4">
                    <Routes :size="20" class="text-gray-500 dark:text-slate-400" />
                    Trajets les Plus Performants
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div 
                        v-for="(route, index) in topRoutes" 
                        :key="route.id"
                        class="relative bg-gradient-to-br from-gray-50 to-gray-100 dark:from-slate-950/40 dark:to-slate-900/40 rounded-xl p-4 border border-gray-200 dark:border-slate-800"
                    >
                        <div class="absolute top-2 left-2 w-6 h-6 bg-green-600 text-white rounded-full flex items-center justify-center text-xs font-black">
                            {{ index + 1 }}
                        </div>
                        <div class="pt-4">
                            <div class="font-bold text-gray-900 dark:text-slate-100 text-sm truncate" :title="route.name">
                                {{ route.name }}
                            </div>
                            <div class="text-2xl font-black text-green-600 mt-2">
                                {{ formatCurrency(route.revenue) }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                                {{ route.ticket_count }} billets
                            </div>
                        </div>
                    </div>
                    <div v-if="topRoutes.length === 0" class="col-span-5 text-center py-8 text-gray-400 dark:text-slate-500">
                        Aucune donnée disponible pour cette période
                    </div>
                </div>
            </div>

            <!-- Read-only notice -->
            <div class="text-center text-sm text-gray-400 dark:text-slate-500 py-4">
                <span class="bg-gray-100 dark:bg-slate-900 px-3 py-1 rounded-full">
                    📊 Mode consultation uniquement
                </span>
            </div>
        </div>
    </MainNavLayout>
</template>
