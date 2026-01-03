<script setup>
import { computed } from 'vue';

const props = defineProps({
  seatMap: {
    type: Array,
    required: true,
    default: () => []
  },
  orientation: {
    type: String,
    default: 'vertical', // 'vertical' or 'horizontal'
    validator: (value) => ['vertical', 'horizontal'].includes(value)
  }
});

const maxCols = computed(() => {
  if (!props.seatMap || props.seatMap.length === 0) return 0;
  return Math.max(...props.seatMap.map(row => Array.isArray(row) ? row.length : 0));
});
</script>

<template>
  <div class="bg-gray-50 rounded-2xl border border-orange-100 p-6 flex flex-col items-center overflow-auto custom-scrollbar">
    <div :class="[
      'inline-flex gap-2',
      orientation === 'vertical' ? 'flex-col' : 'flex-row-reverse'
    ]">
      <!-- Front Indicator -->
      <div :class="[
        'flex items-center justify-center shrink-0',
        orientation === 'vertical' ? 'w-full mb-4' : 'h-full ml-4 w-12'
      ]">
        <div :class="[
          'bg-gray-200 text-gray-500 text-[10px] font-bold uppercase tracking-widest rounded-full border border-gray-300 px-4 py-1 flex items-center justify-center whitespace-nowrap',
          orientation === 'horizontal' ? '-rotate-90 origin-center min-w-[80px]' : ''
        ]">
          AVANT / FRONT
        </div>
      </div>

      <div v-for="(row, rowIndex) in seatMap" :key="rowIndex" :class="[
        'flex gap-2 justify-center',
        orientation === 'vertical' ? 'flex-row' : 'flex-col'
      ]">
        <template v-if="Array.isArray(row)">
          <div v-for="(cell, cellIndex) in row" :key="cellIndex" 
            class="w-10 h-10 flex items-center justify-center text-xs font-bold rounded-lg transition-all shrink-0"
            :class="[
              cell.type === 'seat' ? 'bg-white border-2 border-green-500 text-green-700 shadow-sm' : '',
              cell.type === 'driver' ? 'bg-blue-600 text-white border-2 border-blue-700 shadow-md' : '',
              cell.type === 'door' ? 'bg-orange-500 text-white border-2 border-orange-600 shadow-md' : '',
              cell.type === 'aisle' ? (orientation === 'vertical' ? 'w-6' : 'h-6 w-10') : '',
              cell.type === 'empty' ? 'bg-gray-100 border border-dashed border-gray-300' : ''
            ]"
          >
            <span v-if="cell.type === 'seat'">{{ cell.number }}</span>
            <span v-else-if="cell.type === 'driver'" class="text-[8px] uppercase">DRV</span>
            <span v-else-if="cell.type === 'door'" class="text-[8px] uppercase">EXIT</span>
          </div>
        </template>
      </div>

      <!-- Back Indicator -->
      <div :class="[
        'flex items-center justify-center shrink-0',
        orientation === 'vertical' ? 'w-full mt-6' : 'h-full mr-6'
      ]">
        <div :class="[
          'text-gray-400 text-[9px] font-bold uppercase tracking-widest border-gray-200 whitespace-nowrap',
          orientation === 'vertical' ? 'border-t-2 pt-2 px-4' : 'border-l-2 pl-2 -rotate-90 origin-center'
        ]">
          ARRIÈRE / BACK
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Ensure consistent sizing regardless of content */
.w-10 { width: 2.5rem; }
.h-10 { height: 2.5rem; }
.w-6 { width: 1.5rem; }
</style>
