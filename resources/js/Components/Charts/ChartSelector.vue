<template>
  <!-- Desktop Layout -->
  <div class="hidden md:block">
    <div class="grid grid-cols-12 gap-4">
      <!-- Left Column - Selector -->
      <div class="col-span-2 bg-white p-4 rounded-lg shadow-md border border-slate-200">
        <div class="space-y-2">
          <h3 class="text-lg font-semibold text-gray-800 mb-4">Analytics</h3>
          <!-- Chart Options - Scrollable container -->
          <div class="space-y-1 max-h-[300px] overflow-y-auto custom-scrollbar pr-2">
            <button 
              v-for="chart in availableCharts" 
              :key="chart.id"
              @click="selectChart(chart.id)"
              class="w-full text-left px-3 py-2 rounded-md text-sm transition-colors"
              :class="[
                selectedChart === chart.id 
                  ? 'bg-green-100 text-green-700 font-medium' 
                  : 'hover:bg-green-50 text-gray-600'
              ]"
            >
              {{ chart.title }}
            </button>
          </div>
        </div>
      </div>

      <!-- Middle Column - Current Chart -->
      <div class="col-span-5 bg-white p-4 rounded-lg shadow-md border border-slate-200">
        <Transition
          name="chart"
          mode="out-in"
        >
          <div 
            :key="selectedChart + '-1'"
            class="h-[250px]"
          >
            <component 
              :is="currentChart?.component"
              v-if="currentChart"
            />
          </div>
        </Transition>
      </div>

      <!-- Right Column - Previous Chart -->
      <div class="col-span-5 bg-white p-4 rounded-lg shadow-md border border-slate-200">
        <Transition
          name="chart"
          mode="out-in"
        >
          <div 
            :key="selectedChart + '-2'"
            class="h-[250px]"
          >
            <component 
              :is="previousChart?.component"
              v-if="previousChart"
            />
          </div>
        </Transition>
      </div>
    </div>
  </div>

  <!-- Mobile Layout -->
  <div class="md:hidden space-y-4">
    <!-- Chart Selector -->
    <div class="bg-white p-4 rounded-lg shadow-md border border-slate-200">
      <h3 class="text-lg font-semibold text-gray-800 mb-4">Sellectionez une statistique</h3>
      <select
        v-model="selectedChart"
        class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-transparent"
      >
        <option 
          v-for="chart in availableCharts"
          :key="chart.id"
          :value="chart.id"
        >
          {{ chart.title }}
        </option>
      </select>
    </div>

    <!-- Single Chart Display -->
    <div class="bg-white p-4 rounded-lg shadow-md border border-slate-200">
      <Transition
        name="chart"
        mode="out-in"
      >
        <div 
          :key="selectedChart"
          class="h-[250px]"
        >
          <component 
            :is="currentChart?.component"
            v-if="currentChart"
          />
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import MonthlyCollectionsChart from './types/MonthlyCollectionsChart.vue';
import TaxDistributionChart from './types/TaxDistributionChart.vue';
import CollectorPerformanceChart from './types/CollectorPerformanceChart.vue';
import DailyCollectionsChart from './types/DailyCollectionsChart.vue';
import QuarterlyTrendChart from './types/QuarterlyTrendChart.vue';
import CollectionRateChart from './types/CollectionRateChart.vue';
import TaxpayerGrowthChart from './types/TaxpayerGrowthChart.vue';

const selectedChart = ref('daily-collections');
const previousChartId = ref(null);

const availableCharts = [
  { id: 'daily-collections', title: 'Collectes Journalières', component: DailyCollectionsChart },
  { id: 'monthly-collections', title: 'Collectes Mensuelles', component: MonthlyCollectionsChart },
  { id: 'tax-distribution', title: 'Repartition des Taxes', component: TaxDistributionChart },
  { id: 'collector-performance', title: 'Performance Collecteurs', component: CollectorPerformanceChart },
  { id: 'quarterly-trend', title: 'Tendance Trimestrielle', component: QuarterlyTrendChart },
  { id: 'collection-rate', title: 'Taux de Recouvrement', component: CollectionRateChart },
  { id: 'taxpayer-growth', title: 'Croissance Contribuables', component: TaxpayerGrowthChart }
];

const currentChart = computed(() => {
  return availableCharts.find(chart => chart.id === selectedChart.value);
});

const previousChart = computed(() => {
  return availableCharts.find(chart => chart.id === previousChartId.value);
});

const selectChart = (chartId) => {
  if (chartId !== selectedChart.value) {
    previousChartId.value = selectedChart.value;
    selectedChart.value = chartId;
  }
};

onMounted(() => {
  previousChartId.value = availableCharts[1].id;
});
</script>

<style scoped>
.chart-enter-active,
.chart-leave-active {
  transition: all 0.3s ease;
}

.chart-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.chart-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}

.chart-enter-to,
.chart-leave-from {
  opacity: 1;
  transform: translateX(0);
}

.custom-scrollbar {
  scrollbar-width: thin;
  scrollbar-color: rgba(34, 197, 94, 0.3) transparent;
}

.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(34, 197, 94, 0.3);
  border-radius: 3px;
}
</style>
