<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import { ref, onMounted, computed, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Chart from 'chart.js/auto'
import { useTheme } from '@/Composables/useTheme.js'
// Icons
import Settings from 'vue-material-design-icons/Cog.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import Ticket from 'vue-material-design-icons/Ticket.vue'
import Bus from 'vue-material-design-icons/Bus.vue'
import Account from 'vue-material-design-icons/Account.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import Routes from 'vue-material-design-icons/Routes.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Play from 'vue-material-design-icons/Play.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue'
import Printer from 'vue-material-design-icons/Printer.vue'
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue'
import Car from 'vue-material-design-icons/Car.vue'
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue'
import RouterIcon from 'vue-material-design-icons/Router.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ConfigAlertsSection from '@/Components/Settings/ConfigAlertsSection.vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    links: Array,
    stats: Object,
    charts: Object,
    systemHealth: Object,
    configAlerts: Array,
})

const { t, locale } = useI18n()
const { isDark } = useTheme()

const currentLocale = computed(() => (locale.value === 'en' ? 'en-GB' : 'fr-FR'))

const salesChartRef = ref(null)
const routesChartRef = ref(null)

let salesChart = null
let routesChart = null

const formatCurrency = (amount) => {
    if (amount >= 1000000) {
        return (amount / 1000000).toFixed(1) + 'M'
    } else if (amount >= 1000) {
        return (amount / 1000).toFixed(0) + 'K'
    }
    return new Intl.NumberFormat(currentLocale.value).format(amount)
}

// Group links by category matching Settings/Index structure
const configSections = computed(() => [
  {
    category: t('dashboards.admin.config_sections.company.label'),
    items: [
      { name: t('dashboards.admin.config_sections.company.identity.label'), routeName: 'admin.settings.enterprise', icon: OfficeBuilding, description: t('dashboards.admin.config_sections.company.identity.description') },
      { name: t('dashboards.admin.config_sections.company.loyalty.label'), routeName: 'admin.settings.loyalty', icon: GiftOutline, description: t('dashboards.admin.config_sections.company.loyalty.description') },
      { name: t('dashboards.admin.config_sections.company.ticket_settings.label'), routeName: 'admin.ticket-settings.index', icon: Printer, description: t('dashboards.admin.config_sections.company.ticket_settings.description') },
    ]
  },
  {
    category: t('dashboards.admin.config_sections.infrastructure.label'),
    items: [
      { name: t('dashboards.admin.config_sections.infrastructure.destinations.label'), routeName: 'admin.destinations.index', icon: MapMarkerRadius, description: t('dashboards.admin.config_sections.infrastructure.destinations.description'), count: props.stats.totalDestinations },
      { name: t('dashboards.admin.config_sections.infrastructure.stations.label'), routeName: 'admin.stations.index', icon: OfficeBuilding, description: t('dashboards.admin.config_sections.infrastructure.stations.description'), count: props.stats.totalStations },
    ]
  },
  {
    category: t('dashboards.admin.config_sections.fleet.label'),
    items: [
      { name: t('dashboards.admin.config_sections.fleet.vehicles.label'), routeName: 'admin.vehicles.index', icon: Bus, description: t('dashboards.admin.config_sections.fleet.vehicles.description'), count: props.stats.totalVehicles },
      { name: t('dashboards.admin.config_sections.fleet.vehicle_types.label'), routeName: 'admin.vehicle-types.index', icon: Car, description: t('dashboards.admin.config_sections.fleet.vehicle_types.description'), count: props.stats.totalVehicleTypes },
      { name: t('dashboards.admin.config_sections.fleet.crew.label'), routeName: 'fleet.crew-members.index', icon: AccountHardHat, description: t('dashboards.admin.config_sections.fleet.crew.description') },
      { name: t('dashboards.admin.config_sections.fleet.crew_assignments.label'), routeName: 'fleet.crew-assignments.index', icon: SwapHorizontal, description: t('dashboards.admin.config_sections.fleet.crew_assignments.description') },
    ]
  },
  {
    category: t('dashboards.admin.config_sections.operations.label'),
    items: [
      { name: t('dashboards.admin.config_sections.operations.routes.label'), routeName: 'admin.routes.index', icon: RouterIcon, description: t('dashboards.admin.config_sections.operations.routes.description'), count: props.stats.totalRoutes },
      { name: t('dashboards.admin.config_sections.operations.trips.label'), routeName: 'admin.trips.index', icon: Calendar, description: t('dashboards.admin.config_sections.operations.trips.description'), count: props.stats.activeTrips },
      { name: t('dashboards.admin.config_sections.operations.fares.label'), routeName: 'admin.route-fares.index', icon: Cash, description: t('dashboards.admin.config_sections.operations.fares.description'), count: props.stats.totalFares },
    ]
  },
  {
    category: t('dashboards.admin.config_sections.users.label'),
    items: [
      { name: t('dashboards.admin.config_sections.users.users.label'), routeName: 'admin.users.index', icon: AccountMultiple, description: t('dashboards.admin.config_sections.users.users.description'), count: props.stats.totalUsers },
      { name: t('dashboards.admin.config_sections.users.assignments.label'), routeName: 'admin.assignments.index', icon: AccountGroup, description: t('dashboards.admin.config_sections.users.assignments.description'), count: props.stats.totalAssignments },
    ]
  }
])

const renderCharts = () => {
    if (salesChart) salesChart.destroy()
    if (routesChart) routesChart.destroy()

    const textColor = isDark.value ? '#94a3b8' : '#64748b'
    const gridColor = isDark.value ? '#334155' : '#e2e8f0'

    // Sales Trend Chart
    if (salesChartRef.value && props.charts.salesTrend?.length) {
        salesChart = new Chart(salesChartRef.value, {
            type: 'line',
            data: {
                labels: props.charts.salesTrend.map(item => item.date),
                datasets: [{
                    label: t('dashboards.admin.chart_sales_label'),
                    data: props.charts.salesTrend.map(item => item.count),
                    borderColor: '#10b981',
                    backgroundColor: isDark.value ? 'rgba(16, 185, 129, 0.2)' : 'rgba(16, 185, 129, 0.12)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#10b981',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { 
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    }
                }
            }
        })
    }

    // Top Routes Chart
    if (routesChartRef.value && props.charts.topRoutes?.length) {
        routesChart = new Chart(routesChartRef.value, {
            type: 'doughnut',
            data: {
                labels: props.charts.topRoutes.map(item => item.name),
                datasets: [{
                    data: props.charts.topRoutes.map(item => item.trips),
                    backgroundColor: [
                        '#10b981',
                        isDark.value ? '#334155' : '#0f172a',
                        '#64748b',
                        '#ef4444',
                        '#14b8a6'
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
                            padding: 10,
                            color: textColor
                        } 
                    }
                }
            }
        })
    }
}

onMounted(() => {
    renderCharts()
})

watch(isDark, () => {
    renderCharts()
})
</script>

<template>
  <Head :title="$t('dashboards.admin.title')" />
  
  <MainNavLayout>
    <div class="max-w-7xl mx-auto space-y-6 pb-10">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 class="text-3xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 dark:bg-emerald-950/50 rounded-2xl">
              <Settings class="text-emerald-600 dark:text-emerald-400" :size="28" />
            </div>
            {{ $t('dashboards.admin.title') }}
          </h1>
          <p class="text-slate-500 dark:text-slate-400 mt-1">{{ $t('dashboards.admin.subtitle') }}</p>
        </div>
        
        <!-- System Health Badge -->
        <div class="flex items-center gap-3">
          <div 
            :class="[
              'flex items-center gap-2 px-4 py-2 rounded-2xl font-bold text-sm shadow-sm',
              systemHealth.database.status === 'healthy' 
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/20 dark:text-emerald-400 dark:border-emerald-900/60' 
                : 'bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/20 dark:text-rose-400 dark:border-rose-900/60'
            ]"
          >
            <Database :size="18" />
            <span>{{ $t('dashboards.admin.database') }}</span>
            <CheckCircle v-if="systemHealth.database.status === 'healthy'" :size="16" />
            <AlertCircle v-else :size="16" />
            <span class="text-xs opacity-75" v-if="systemHealth.database.latency">
              {{ systemHealth.database.latency }}ms
            </span>
          </div>
        </div>
      </div>

      <!-- Points d'attention -->
      <div v-if="configAlerts?.length" class="max-w-3xl">
        <ConfigAlertsSection :alerts="configAlerts" />
      </div>

      <!-- Quick Stats -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Revenue -->
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-3xl p-5 text-white shadow-lg">
          <div class="flex items-center justify-between mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <CashMultiple :size="22" />
            </div>
            <div v-if="stats.revenueGrowth !== 0" 
             :class="['flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-full', 
                          stats.revenueGrowth >= 0 ? 'bg-white/25' : 'bg-rose-400/50']">
              <TrendingUp v-if="stats.revenueGrowth >= 0" :size="14" />
              <TrendingDown v-else :size="14" />
              {{ Math.abs(stats.revenueGrowth) }}%
            </div>
          </div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.todayRevenue) }}</div>
          <div class="text-sm text-emerald-100 mt-1">{{ $t('dashboards.admin.revenue_today') }}</div>
        </div>

        <!-- Tickets -->
        <div class="bg-gradient-to-br from-slate-700 to-slate-900 rounded-3xl p-5 text-white shadow-lg">
          <div class="flex items-center justify-between mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Ticket :size="22" />
            </div>
            <div v-if="stats.salesGrowth !== 0" 
             :class="['flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-full', 
                          stats.salesGrowth >= 0 ? 'bg-white/25' : 'bg-rose-400/50']">
              <TrendingUp v-if="stats.salesGrowth >= 0" :size="14" />
              <TrendingDown v-else :size="14" />
              {{ Math.abs(stats.salesGrowth) }}%
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.todaySales }}</div>
          <div class="text-sm text-slate-200 mt-1">{{ $t('dashboards.admin.tickets_sold_today') }}</div>
        </div>

        <!-- Active Trips -->
        <div class="bg-gradient-to-br from-emerald-500 to-slate-700 rounded-3xl p-5 text-white shadow-lg">
          <div class="flex items-center gap-2 mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Bus :size="22" />
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.activeTrips }}</div>
          <div class="text-sm text-emerald-100 mt-1">{{ $t('dashboards.admin.active_trips') }}</div>
        </div>

        <!-- Users -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-3xl p-5 text-white shadow-lg">
          <div class="flex items-center gap-2 mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Account :size="22" />
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.totalUsers }}</div>
          <div class="text-sm text-slate-200 mt-1">{{ $t('dashboards.admin.users_active', { count: stats.activeUsers }) }}</div>
        </div>
      </div>

      <!-- System Health Cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm">
          <div class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">{{ $t('dashboards.admin.pending_departures') }}</div>
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ systemHealth.pending_departures }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">{{ $t('dashboards.admin.less_than_2_hours') }}</div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm">
          <div class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">{{ $t('dashboards.admin.trips_today') }}</div>
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ systemHealth.trips_today }}</div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm">
          <div class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">{{ $t('dashboards.admin.active_vehicles') }}</div>
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ stats.activeVehicles }}/{{ stats.totalVehicles }}</div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm">
          <div class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">{{ $t('dashboards.admin.active_stations') }}</div>
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ systemHealth.stations_active }}/{{ stats.totalStations }}</div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm">
          <div class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-1">{{ $t('dashboards.admin.routes_total') }}</div>
          <div class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ stats.totalRoutes }}</div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sales Trend -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
          <h3 class="font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2 mb-4">
            <ChartLine :size="20" class="text-slate-500 dark:text-slate-400" />
            {{ $t('dashboards.admin.sales_trend_7d') }}
          </h3>
          <div class="h-[250px]">
            <canvas ref="salesChartRef"></canvas>
          </div>
        </div>

        <!-- Top Routes -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
          <h3 class="font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2 mb-4">
            <Routes :size="20" class="text-slate-500 dark:text-slate-400" />
            {{ $t('dashboards.admin.popular_routes') }}
          </h3>
          <div class="h-[250px]">
            <canvas ref="routesChartRef"></canvas>
          </div>
        </div>
      </div>

      <!-- User Stats by Role -->
      <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
        <h3 class="font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2 mb-4">
          <Account :size="20" class="text-slate-500 dark:text-slate-400" />
          {{ $t('dashboards.admin.users_by_role') }}
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div class="text-center p-4 bg-slate-50 dark:bg-slate-950/40 rounded-2xl">
            <div class="text-3xl font-black text-slate-900 dark:text-slate-100">{{ stats.admins }}</div>
            <div class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mt-1">{{ $t('dashboards.admin.role_admins') }}</div>
          </div>
          <div class="text-center p-4 bg-slate-50 dark:bg-slate-950/40 rounded-2xl">
            <div class="text-3xl font-black text-slate-700 dark:text-slate-300">{{ stats.supervisors }}</div>
            <div class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mt-1">{{ $t('dashboards.admin.role_supervisors') }}</div>
          </div>
          <div class="text-center p-4 bg-emerald-50 dark:bg-emerald-950/20 rounded-2xl">
            <div class="text-3xl font-black text-emerald-600 dark:text-emerald-400">{{ stats.sellers }}</div>
            <div class="text-xs font-bold text-emerald-500 dark:text-emerald-300 uppercase mt-1">{{ $t('dashboards.admin.role_sellers') }}</div>
          </div>
          <div class="text-center p-4 bg-slate-50 dark:bg-slate-950/40 rounded-2xl">
            <div class="text-3xl font-black text-slate-700 dark:text-slate-300">{{ stats.accountants }}</div>
            <div class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mt-1">{{ $t('dashboards.admin.role_accountants') }}</div>
          </div>
          <div class="text-center p-4 bg-slate-50 dark:bg-slate-950/40 rounded-2xl">
            <div class="text-3xl font-black text-slate-700 dark:text-slate-300">{{ stats.executives }}</div>
            <div class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mt-1">{{ $t('dashboards.admin.role_executives') }}</div>
          </div>
          <div class="text-center p-4 bg-slate-50 dark:bg-slate-950/40 rounded-2xl">
            <div class="text-3xl font-black text-slate-700 dark:text-slate-300">{{ stats.fleetManagers }}</div>
            <div class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mt-1">{{ $t('dashboards.admin.role_fleet_managers') }}</div>
          </div>
        </div>
      </div>

      <!-- Configuration Section -->
      <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
        <div class="mb-6">
          <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
            <Settings :size="22" class="text-slate-500 dark:text-slate-400" />
            {{ $t('dashboards.admin.system_configuration') }}
          </h3>
          <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ $t('dashboards.admin.system_configuration_desc') }}</p>
        </div>
        
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <div
            v-for="section in configSections"
            :key="section.category"
            class="space-y-3"
          >
            <h4 class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-3">{{ section.category }}</h4>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
              <Link
                v-for="item in section.items"
                :key="item.routeName"
                :href="route(item.routeName)"
                class="block p-3 bg-white dark:bg-slate-950/40 rounded-2xl border border-slate-100 dark:border-slate-800 hover:border-emerald-200 dark:hover:border-emerald-800/80 hover:shadow-lg transition-all group"
              >
                <div class="flex items-start gap-3">
                  <div class="p-2 bg-slate-100 dark:bg-slate-800 group-hover:bg-emerald-100 dark:group-hover:bg-emerald-950/60 rounded-xl transition-colors shrink-0">
                    <component :is="item.icon" :size="20" class="text-slate-500 dark:text-slate-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                      <span class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 text-sm leading-tight">{{ item.name }}</span>
                      <div class="flex items-center gap-1.5 shrink-0">
                        <span v-if="item.count !== undefined" class="text-[10px] font-bold bg-slate-100 dark:bg-slate-800 group-hover:bg-emerald-100 dark:group-hover:bg-emerald-950 text-slate-600 dark:text-slate-300 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 px-1.5 py-0.5 rounded-lg">
                          {{ item.count }}
                        </span>
                        <ChevronRight :size="18" class="text-slate-300 dark:text-slate-600 group-hover:text-emerald-500 shrink-0" />
                      </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">{{ item.description }}</p>
                  </div>
                </div>
              </Link>
            </div>
          </div>
        </div>
      </div>

      <!-- Monthly Summary -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-slate-800 to-slate-950 rounded-3xl p-6 text-white">
          <div class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2">{{ $t('dashboards.admin.total_sales_history') }}</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.totalSales) }}</div>
          <div class="text-sm text-slate-400 mt-1">{{ $t('dashboards.admin.tickets_sold_label') }}</div>
        </div>
        <div class="bg-gradient-to-br from-slate-800 to-slate-950 rounded-3xl p-6 text-white">
          <div class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2">{{ $t('dashboards.admin.total_revenue_history') }}</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.totalRevenue) }}</div>
          <div class="text-sm text-slate-400 mt-1">FCFA</div>
        </div>
        <div class="bg-gradient-to-br from-slate-800 to-slate-950 rounded-3xl p-6 text-white">
          <div class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2">{{ $t('dashboards.admin.this_month') }}</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.monthlyRevenue) }}</div>
          <div class="text-sm text-slate-400 mt-1">{{ $t('dashboards.admin.this_month_breakdown', { count: stats.monthlySales }) }}</div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
