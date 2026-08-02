<script setup>
import { computed } from 'vue';

const props = defineProps({
  seatMap: {
    type: Array,
    required: true,
    default: () => []
  },
  seatConfiguration: {
    type: String,
    default: '',
  },
  orientation: {
    type: String,
    default: 'vertical', // 'vertical' or 'horizontal'
    validator: (value) => ['vertical', 'horizontal'].includes(value)
  }
});

const getNormalLastRowCapacity = () => {
  const config = (props.seatConfiguration || '').trim();
  if (!config) return null;

  const parts = config.split(/[+xX]/).map(part => parseInt(part, 10)).filter(Number.isFinite);
  if (parts.length < 2) return null;

  return parts.reduce((sum, part) => sum + part, 0) + 1;
};

const dynamicCellSize = computed(() => {
  if (props.orientation === 'vertical') {
    return 40; // Vertical is scrollable or fits vertically
  }
  const colCount = props.seatMap.length;
  if (colCount <= 12) return 40;
  // Scale down linearly for wide horizontal layout
  return Math.max(20, Math.floor(540 / colCount));
});

const getCellStyle = (cell, row, rowIndex) => {
  const size = dynamicCellSize.value;

  const style = {
    width: `${size}px`,
    height: `${size}px`,
    fontSize: `${Math.max(8, Math.round(size * 0.32))}px`,
  };

  if (cell.type === 'aisle') {
    if (props.orientation === 'vertical') {
      style.width = `${Math.round(size * 0.6)}px`;
    } else {
      style.height = `${Math.round(size * 0.6)}px`;
    }
  }

  // Last row crowded capacity check
  const isLastRow = rowIndex === props.seatMap.length - 1;
  const hasStandardLayout = row.some(c => c.type === 'aisle' || c.type === 'driver' || c.type === 'door');
  if (isLastRow && !hasStandardLayout && cell.type === 'seat') {
    const seatCount = row.filter(c => c.type === 'seat').length;
    const normalLastRowCapacity = getNormalLastRowCapacity();
    if (normalLastRowCapacity && seatCount > normalLastRowCapacity) {
      const compactSize = Math.max(18, Math.min(size, Math.round((size * normalLastRowCapacity) / seatCount)));
      style.width = `${compactSize}px`;
      style.height = `${compactSize}px`;
      style.fontSize = `${Math.max(8, Math.round(compactSize * 0.32))}px`;
    }
  }

  return style;
};
</script>

<template>
  <div class="bg-gray-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 p-6 flex flex-col items-center overflow-hidden w-full">
    <div :class="[
      'inline-flex gap-1.5 justify-center max-w-full',
      orientation === 'vertical' ? 'flex-col' : 'flex-row-reverse'
    ]">
      <!-- Front Indicator -->
      <div :class="[
        'flex items-center justify-center shrink-0',
        orientation === 'vertical' ? 'w-full mb-4' : 'h-full ml-4 w-12'
      ]">
        <div :class="[
          'bg-gray-200 dark:bg-slate-800 text-gray-500 dark:text-slate-400 text-[10px] font-bold uppercase tracking-widest rounded-full border border-gray-300 dark:border-slate-700 px-4 py-1 flex items-center justify-center whitespace-nowrap',
          orientation === 'horizontal' ? '-rotate-90 origin-center min-w-[80px]' : ''
        ]">
          AVANT / FRONT
        </div>
      </div>

      <div v-for="(row, rowIndex) in seatMap" :key="rowIndex" :class="[
        'flex gap-1.5 justify-center',
        orientation === 'vertical' ? 'flex-row' : 'flex-col'
      ]">
        <template v-if="Array.isArray(row)">
          <div v-for="(cell, cellIndex) in row" :key="cellIndex"
            class="flex items-center justify-center font-bold rounded-lg transition-all shrink-0"
            :style="getCellStyle(cell, row, rowIndex)"
            :class="[
              cell.type === 'seat' ? 'bg-white dark:bg-slate-900 border-2 border-green-500 dark:border-green-600 text-green-700 dark:text-green-400 shadow-sm' : '',
              cell.type === 'driver' ? 'bg-blue-600 dark:bg-blue-700 text-white border-2 border-blue-700 dark:border-blue-800 shadow-md' : '',
              cell.type === 'door' ? 'bg-orange-500 dark:bg-orange-600 text-white border-2 border-orange-600 dark:border-orange-700 shadow-md' : '',
              cell.type === 'empty' ? 'bg-gray-100 dark:bg-slate-900/40 border border-dashed border-gray-300 dark:border-slate-800' : ''
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
          'text-gray-400 dark:text-slate-500 text-[9px] font-bold uppercase tracking-widest border-gray-200 dark:border-slate-800 whitespace-nowrap',
          orientation === 'vertical' ? 'border-t-2 pt-2 px-4' : 'border-l-2 pl-2 -rotate-90 origin-center'
        ]">
          ARRIÈRE / BACK
        </div>
      </div>
    </div>
  </div>
</template>
