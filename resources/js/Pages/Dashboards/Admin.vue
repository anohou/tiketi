<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue'
import { ref, onMounted, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Chart from 'chart.js/auto'
// Icons
import Settings from 'vue-material-design-icons/Cog.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import Ticket from 'vue-material-design-icons/Ticket.vue'
import Bus from 'vue-material-design-icons/Bus.vue'
import Account from 'vue-material-design-icons/Account.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import Routes from 'vue-material-design-icons/Routes.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Play from 'vue-material-design-icons/Play.vue'

const props = defineProps({
    links: Array,
    stats: Object,
    charts: Object,
    systemHealth: Object,
})

const salesChartRef = ref(null)
const routesChartRef = ref(null)

const formatCurrency = (amount) => {
    if (amount >= 1000000) {
        return (amount / 1000000).toFixed(1) + 'M'
    } else if (amount >= 1000) {
        return (amount / 1000).toFixed(0) + 'K'
    }
    return new Intl.NumberFormat('fr-FR').format(amount)
}

// Group links by category
const configLinks = computed(() => {
    const categories = {
        'Réseau': ['Gares', 'Arrêts', 'Trajets'],
        'Flotte': ['Types de Véhicules', 'Véhicules', 'Voyages'],
        'Commercial': ['Tarifs', 'Config. Tickets'],
        'Équipe': ['Utilisateurs', 'Assignations'],
    }
    
    const grouped = {}
    for (const [cat, labels] of Object.entries(categories)) {
        grouped[cat] = props.links.filter(l => labels.includes(l.label))
    }
    return grouped
})

onMounted(() => {
    // Sales Trend Chart
    if (salesChartRef.value && props.charts.salesTrend?.length) {
        new Chart(salesChartRef.value, {
            type: 'line',
            data: {
                labels: props.charts.salesTrend.map(item => item.date),
                datasets: [{
                    label: 'Ventes',
                    data: props.charts.salesTrend.map(item => item.count),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
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
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        })
    }

    // Top Routes Chart
    if (routesChartRef.value && props.charts.topRoutes?.length) {
        new Chart(routesChartRef.value, {
            type: 'doughnut',
            data: {
                labels: props.charts.topRoutes.map(item => item.name),
                datasets: [{
                    data: props.charts.topRoutes.map(item => item.trips),
                    backgroundColor: [
                        '#059669',
                        '#ea580c',
                        '#3b82f6',
                        '#8b5cf6',
                        '#ec4899'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, padding: 10 } }
                }
            }
        })
    }
})
</script>

<template>
  <Head title="Administration" />
  
  <MainNavLayout>
    <div class="max-w-7xl mx-auto space-y-6 pb-10">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Settings class="text-green-600" :size="28" />
            </div>
            Administration
          </h1>
          <p class="text-gray-500 mt-1">Vue d'ensemble et configuration du système</p>
        </div>
        
        <!-- System Health Badge -->
        <div class="flex items-center gap-3">
          <div 
            :class="[
              'flex items-center gap-2 px-4 py-2 rounded-xl font-bold text-sm',
              systemHealth.database.status === 'healthy' 
                ? 'bg-green-100 text-green-700 border border-green-200' 
                : 'bg-red-100 text-red-700 border border-red-200'
            ]"
          >
            <Database :size="18" />
            <span>Base de données</span>
            <CheckCircle v-if="systemHealth.database.status === 'healthy'" :size="16" />
            <AlertCircle v-else :size="16" />
            <span class="text-xs opacity-75" v-if="systemHealth.database.latency">
              {{ systemHealth.database.latency }}ms
            </span>
          </div>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Revenue -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-5 text-white shadow-lg">
          <div class="flex items-center justify-between mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <CashMultiple :size="22" />
            </div>
            <div v-if="stats.revenueGrowth !== 0" 
                 :class="['flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-full', 
                          stats.revenueGrowth >= 0 ? 'bg-white/30' : 'bg-red-400/50']">
              <TrendingUp v-if="stats.revenueGrowth >= 0" :size="14" />
              <TrendingDown v-else :size="14" />
              {{ Math.abs(stats.revenueGrowth) }}%
            </div>
          </div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.todayRevenue) }}</div>
          <div class="text-sm text-green-100 mt-1">Recettes aujourd'hui</div>
        </div>

        <!-- Tickets -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-5 text-white shadow-lg">
          <div class="flex items-center justify-between mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Ticket :size="22" />
            </div>
            <div v-if="stats.salesGrowth !== 0" 
                 :class="['flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-full', 
                          stats.salesGrowth >= 0 ? 'bg-white/30' : 'bg-red-400/50']">
              <TrendingUp v-if="stats.salesGrowth >= 0" :size="14" />
              <TrendingDown v-else :size="14" />
              {{ Math.abs(stats.salesGrowth) }}%
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.todaySales }}</div>
          <div class="text-sm text-blue-100 mt-1">Billets vendus aujourd'hui</div>
        </div>

        <!-- Active Trips -->
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl p-5 text-white shadow-lg">
          <div class="flex items-center gap-2 mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Bus :size="22" />
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.activeTrips }}</div>
          <div class="text-sm text-orange-100 mt-1">Voyages actifs</div>
        </div>

        <!-- Users -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg">
          <div class="flex items-center gap-2 mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <Account :size="22" />
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats.totalUsers }}</div>
          <div class="text-sm text-purple-100 mt-1">Utilisateurs ({{ stats.activeUsers }} actifs)</div>
        </div>
      </div>

      <!-- System Health Cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Départs en attente</div>
          <div class="text-2xl font-black text-gray-900">{{ systemHealth.pending_departures }}</div>
          <div class="text-xs text-gray-500">< 2 heures</div>
        </div>
        <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Voyages du jour</div>
          <div class="text-2xl font-black text-gray-900">{{ systemHealth.trips_today }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Véhicules Actifs</div>
          <div class="text-2xl font-black text-gray-900">{{ stats.activeVehicles }}/{{ stats.totalVehicles }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Gares Actives</div>
          <div class="text-2xl font-black text-gray-900">{{ systemHealth.stations_active }}/{{ stats.totalStations }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">Trajets</div>
          <div class="text-2xl font-black text-gray-900">{{ stats.totalRoutes }}</div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sales Trend -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
          <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4">
            <ChartLine :size="20" class="text-gray-500" />
            Tendance des Ventes (7 jours)
          </h3>
          <div class="h-[250px]">
            <canvas ref="salesChartRef"></canvas>
          </div>
        </div>

        <!-- Top Routes -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
          <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4">
            <Routes :size="20" class="text-gray-500" />
            Trajets Populaires
          </h3>
          <div class="h-[250px]">
            <canvas ref="routesChartRef"></canvas>
          </div>
        </div>
      </div>

      <!-- User Stats by Role -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
        <h3 class="font-bold text-gray-900 flex items-center gap-2 mb-4">
          <Account :size="20" class="text-gray-500" />
          Utilisateurs par Rôle
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div class="text-center p-4 bg-gray-50 rounded-xl">
            <div class="text-3xl font-black text-gray-900">{{ stats.admins }}</div>
            <div class="text-xs font-bold text-gray-500 uppercase mt-1">Admins</div>
          </div>
          <div class="text-center p-4 bg-blue-50 rounded-xl">
            <div class="text-3xl font-black text-blue-600">{{ stats.supervisors }}</div>
            <div class="text-xs font-bold text-blue-500 uppercase mt-1">Superviseurs</div>
          </div>
          <div class="text-center p-4 bg-green-50 rounded-xl">
            <div class="text-3xl font-black text-green-600">{{ stats.sellers }}</div>
            <div class="text-xs font-bold text-green-500 uppercase mt-1">Vendeurs</div>
          </div>
          <div class="text-center p-4 bg-orange-50 rounded-xl">
            <div class="text-3xl font-black text-orange-600">{{ stats.accountants }}</div>
            <div class="text-xs font-bold text-orange-500 uppercase mt-1">Comptables</div>
          </div>
          <div class="text-center p-4 bg-purple-50 rounded-xl">
            <div class="text-3xl font-black text-purple-600">{{ stats.executives }}</div>
            <div class="text-xs font-bold text-purple-500 uppercase mt-1">Exécutifs</div>
          </div>
        </div>
      </div>

      <!-- Configuration Section -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
        <div class="mb-6">
          <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <Settings :size="22" class="text-gray-500" />
            Configuration du Système
          </h3>
          <p class="text-sm text-gray-500 mt-1">Gérez tous les paramètres de votre système de transport</p>
        </div>
        
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
          <div v-for="(items, category) in configLinks" :key="category" class="space-y-2">
            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-3">{{ category }}</h4>
            <Link 
              v-for="link in items" 
              :key="link.href"
              :href="link.href"
              class="flex items-center justify-between p-3 rounded-xl border border-gray-100 hover:border-green-300 hover:bg-green-50 transition-all group"
            >
              <div class="flex items-center gap-3">
                <div class="p-2 bg-gray-100 group-hover:bg-green-100 rounded-lg transition-colors">
                  <MapMarker v-if="link.icon === 'station'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <MapMarker v-else-if="link.icon === 'stop'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Routes v-else-if="link.icon === 'route'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Bus v-else-if="link.icon === 'vehicle' || link.icon === 'vehicle-type'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Play v-else-if="link.icon === 'trip'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Ticket v-else-if="link.icon === 'fare'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Account v-else-if="link.icon === 'user' || link.icon === 'assignment'" :size="18" class="text-gray-500 group-hover:text-green-600" />
                  <Settings v-else :size="18" class="text-gray-500 group-hover:text-green-600" />
                </div>
                <span class="font-medium text-gray-900 group-hover:text-green-700">{{ link.label }}</span>
              </div>
              <span v-if="link.count !== undefined" class="text-xs font-bold bg-gray-100 group-hover:bg-green-200 text-gray-600 group-hover:text-green-700 px-2 py-1 rounded-lg">
                {{ link.count }}
              </span>
            </Link>
          </div>
        </div>
      </div>

      <!-- Monthly Summary -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-6 text-white">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">Total Ventes (Historique)</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.totalSales) }}</div>
          <div class="text-sm text-gray-400 mt-1">billets vendus</div>
        </div>
        <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-6 text-white">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">Total Revenus (Historique)</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.totalRevenue) }}</div>
          <div class="text-sm text-gray-400 mt-1">FCFA</div>
        </div>
        <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-6 text-white">
          <div class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">Ce Mois</div>
          <div class="text-3xl font-black">{{ formatCurrency(stats.monthlyRevenue) }}</div>
          <div class="text-sm text-gray-400 mt-1">FCFA ({{ stats.monthlySales }} billets)</div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
