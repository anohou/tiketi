<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
  vehicleType: {
    type: Object,
    required: true
  },
  seatMap: {
    type: Object,
    required: true
  },
  suggestedSeats: {
    type: Array,
    default: () => []
  },
  selectedSeat: {
    type: Number,
    default: null
  },
  selectedColor: {
    type: String,
    default: '#A855F7' // Default purple
  },
  showSuggestions: {
    type: Boolean,
    default: false
  },
  allowOccupiedClick: {
    type: Boolean,
    default: false
  },
  verticalMode: {
    type: Boolean,
    default: false
  }
});

// Debug: Log when suggestions change
watch(() => props.suggestedSeats, (newVal) => {
  console.log('[SVG] suggestedSeats changed:', newVal);
}, { immediate: true });

watch(() => props.showSuggestions, (newVal) => {
  console.log('[SVG] showSuggestions changed:', newVal);
}, { immediate: true });

const emit = defineEmits(['seat-click']);

// Constantes pour le design SVG
const SEAT_WIDTH = 40;
const SEAT_HEIGHT = 45;
const SEAT_SPACING = 5;
const AISLE_WIDTH = 50;
const ROW_SPACING = 5;
const MARGIN = 20; // Reduced margin
const DRIVER_CABIN_HEIGHT = 80;

// Filter out trailing empty rows and format into decks
const decksData = computed(() => {
  if (!props.seatMap.seat_map) return [];
  const sourceDecks = [];
  if (Array.isArray(props.seatMap.seat_map)) {
    sourceDecks.push({ name: 'Niveau Unique', rows: [...props.seatMap.seat_map] });
  } else {
    if (props.seatMap.seat_map.lower_deck) {
      sourceDecks.push({ name: 'Niveau Bas', rows: [...props.seatMap.seat_map.lower_deck] });
    }
    if (props.seatMap.seat_map.upper_deck) {
      sourceDecks.push({ name: 'Niveau Haut', rows: [...props.seatMap.seat_map.upper_deck] });
    }
  }
  
  return sourceDecks.map(deck => {
    const rows = [...deck.rows];
    while (rows.length > 0) {
      const lastRow = rows[rows.length - 1];
      const hasSeats = lastRow.some(item => item.type === 'seat');
      if (!hasSeats) rows.pop();
      else break;
    }
    return { ...deck, rows };
  });
});

// Calculer les dimensions du SVG
const svgDimensions = computed(() => {
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  const aisles = parts.length - 1;
  const width = MARGIN * 2 + (seatsPerRow * SEAT_WIDTH) + ((seatsPerRow - 1) * SEAT_SPACING) + (aisles * (AISLE_WIDTH - SEAT_SPACING));
  
  let totalHeight = MARGIN; 
  decksData.value.forEach((deck, index) => {
    if (decksData.value.length > 1) totalHeight += 50; // deck label space
    const hasCabin = index === 0;
    if (hasCabin) totalHeight += DRIVER_CABIN_HEIGHT;
    
    const rowsCount = deck.rows.length;
    const rowHeightMultiplier = hasCabin ? Math.max(0, rowsCount - 1) : rowsCount;
    totalHeight += rowHeightMultiplier * (SEAT_HEIGHT + ROW_SPACING);
    
    if (index < decksData.value.length - 1) totalHeight += 60; // Deck spacing
  });
  
  totalHeight += MARGIN;
  return { width, height: totalHeight };
});

// Générer les positions des sièges
const seatPositions = computed(() => {
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  const aisles = parts.length - 1;
  const positions = [];
  
  let currentY = MARGIN;
  
  decksData.value.forEach((deck, deckIndex) => {
    if (decksData.value.length > 1) {
      positions.push({ type: 'deck_label', label: deck.name, x: MARGIN, y: currentY + 30 });
      currentY += 50;
    }
    
    const hasCabin = deckIndex === 0;
    const deckStartY = currentY;
    
    deck.rows.forEach((row, rowIndex) => {
      const seatsInRow = row.filter(s => s.type === 'seat').length;
      let y;
      if (hasCabin && rowIndex === 0) {
         y = deckStartY + 10;
      } else if (hasCabin) {
         y = deckStartY + DRIVER_CABIN_HEIGHT + ((rowIndex - 1) * (SEAT_HEIGHT + ROW_SPACING));
      } else {
         y = deckStartY + (rowIndex * (SEAT_HEIGHT + ROW_SPACING));
      }

      const isStandardRow = row.some(s => s.type === 'aisle');
      
      if (!isStandardRow && seatsInRow > 0) {
        const totalWidth = (seatsPerRow * SEAT_WIDTH) + ((seatsPerRow - 1) * SEAT_SPACING) + (aisles * (AISLE_WIDTH - SEAT_SPACING));
        const rowWidth = (seatsInRow * SEAT_WIDTH);
        const remainingSpace = totalWidth - rowWidth;
        const spacePerGap = seatsInRow > 1 ? remainingSpace / (seatsInRow - 1) : 0;
        
        let currentX = MARGIN;
        row.forEach((item) => {
          if (item.type === 'seat') {
            positions.push({ ...item, x: currentX, y: y, sectionIndex: 0 });
            currentX += SEAT_WIDTH + spacePerGap;
          }
        });
      } else {
        let currentX = MARGIN;
        row.forEach((item) => {
          if (item.type === 'seat') {
            positions.push({ ...item, x: currentX, y: y, sectionIndex: 0 });
            currentX += SEAT_WIDTH + SEAT_SPACING;
          } else if (item.type === 'empty') {
            currentX += SEAT_WIDTH + SEAT_SPACING;
          } else if (item.type === 'aisle') {
            currentX += (AISLE_WIDTH - SEAT_SPACING);
          }
        });
      }
    });
    
    // Add to Y for next deck
    const rowsCount = deck.rows.length;
    const rowHeightMultiplier = hasCabin ? Math.max(0, rowsCount - 1) : rowsCount;
    currentY += (hasCabin ? DRIVER_CABIN_HEIGHT : 0) + rowHeightMultiplier * (SEAT_HEIGHT + ROW_SPACING) + 60;
  });
  
  return positions;
});

const visibleSeats = computed(() => {
  return seatPositions.value.filter(p => !p.type || p.type === 'seat');
});

const visibleLabels = computed(() => {
  return seatPositions.value.filter(p => p.type === 'deck_label');
});

// Door positions
const doorPositions = computed(() => {
  const doors = [];
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  const dbDoorPositions = props.vehicleType.door_positions || [0];
  
  const sortedPositions = [...dbDoorPositions].sort((a, b) => a - b);
  const groups = [];
  let currentGroup = [];
  
  sortedPositions.forEach((pos, index) => {
    if (currentGroup.length === 0) currentGroup.push(pos);
    else {
      const lastPos = currentGroup[currentGroup.length - 1];
      if (pos === lastPos + 1) currentGroup.push(pos);
      else {
        groups.push(currentGroup);
        currentGroup = [pos];
      }
    }
    if (index === sortedPositions.length - 1) groups.push(currentGroup);
  });
  
  let doorCount = 1;
  const isMultiDeck = decksData.value.length > 1;
  
  // Note: on double deckers, doors are strictly on the lower deck (index 0).
  const deck0StartY = MARGIN + (isMultiDeck ? 50 : 0);

  groups.forEach(group => {
    const startSeat = group[0];
    const isFrontDoor = startSeat === 0;
    
    if (isFrontDoor) {
      const x = svgDimensions.value.width - MARGIN - SEAT_WIDTH;
      doors.push({
        x: x,
        y: deck0StartY, 
        width: SEAT_WIDTH,
        height: SEAT_HEIGHT,
        label: `D${doorCount++}`,
        type: 'front'
      });
    } else {
      const rowIndex = Math.ceil(startSeat / seatsPerRow) - 1;
      const y = deck0StartY + DRIVER_CABIN_HEIGHT + (rowIndex * (SEAT_HEIGHT + ROW_SPACING));
      
      const colIndex = (startSeat - 1) % seatsPerRow + 1;
      let x = MARGIN;
      let remainingCol = colIndex;
      
      for (let i = 0; i < parts.length; i++) {
        if (remainingCol <= parts[i]) {
            x += (remainingCol - 1) * (SEAT_WIDTH + SEAT_SPACING);
            break;
        } else {
            x += parts[i] * (SEAT_WIDTH + SEAT_SPACING);
            x += AISLE_WIDTH - SEAT_SPACING;
            remainingCol -= parts[i];
        }
      }
      
      const groupWidth = (group.length * SEAT_WIDTH) + ((group.length - 1) * SEAT_SPACING);
      doors.push({
        x: x,
        y: y,
        width: groupWidth,
        height: SEAT_HEIGHT,
        label: `D${doorCount++}`,
        type: 'middle'
      });
    }
  });
  
  return doors;
});

const getSeatColor = (seat) => {
  if (seat.isOccupied) return seat.color || '#EF4444';
  if (isSelected(seat.number)) return props.selectedColor || '#A855F7';
  return '#94A3B8';
};

const isSuggested = (seatNumber) => {
  if (!props.showSuggestions || !props.suggestedSeats || props.suggestedSeats.length === 0) return false;
  return props.suggestedSeats.some(s => {
    const sNum = s.seat_number !== undefined ? s.seat_number : s;
    return Number(sNum) === Number(seatNumber);
  });
};

const getSuggestionRank = (seatNumber) => {
  if (!props.showSuggestions || !props.suggestedSeats || props.suggestedSeats.length === 0) return 0;
  const index = props.suggestedSeats.findIndex(s => {
    const sNum = s.seat_number !== undefined ? s.seat_number : s;
    return Number(sNum) === Number(seatNumber);
  });
  return index >= 0 ? index + 1 : 0;
};

const isSelected = (seatNumber) => props.selectedSeat === seatNumber;

const handleSeatClick = (seat) => {
  if (!seat.isOccupied || props.allowOccupiedClick) {
    emit('seat-click', seat.number);
  }
};
</script>

<template>
  <div class="vehicle-svg-container">
    <svg 
      :width="svgDimensions.width" 
      :height="svgDimensions.height"
      :viewBox="`0 0 ${svgDimensions.width} ${svgDimensions.height}`"
      class="h-full w-auto max-w-full"
      xmlns="http://www.w3.org/2000/svg"
    >
      <!-- Fond intérieur -->
      <rect 
        :x="15" 
        :y="15" 
        :width="svgDimensions.width - 30" 
        :height="svgDimensions.height - 30"
        fill="#E0F2FE"
        rx="18"
      />
      
      <!-- Cabine du conducteur (avant) -->
      <g>
        <!-- Zone conducteur -->
        <rect 
          :x="MARGIN" 
          :y="MARGIN" 
          :width="SEAT_WIDTH * 2 + SEAT_SPACING" 
          :height="DRIVER_CABIN_HEIGHT - 10"
          fill="#CBD5E1"
          stroke="#64748B"
          stroke-width="2"
          rx="8"
        />
        
        <!-- Volant -->
        <circle 
          :cx="MARGIN + 25" 
          :cy="MARGIN + 30" 
          r="15"
          fill="none"
          stroke="#475569"
          stroke-width="3"
        />
        <circle 
          :cx="MARGIN + 25" 
          :cy="MARGIN + 30" 
          r="4"
          fill="#475569"
        />
        
        <!-- Label AVANT -->
        <text 
          :x="MARGIN + SEAT_WIDTH + 10" 
          :y="MARGIN + DRIVER_CABIN_HEIGHT - 20" 
          text-anchor="middle" 
          class="text-xs font-bold fill-slate-700"
          :transform="verticalMode ? `rotate(-90 ${MARGIN + SEAT_WIDTH + 10} ${MARGIN + DRIVER_CABIN_HEIGHT - 20})` : undefined"
        >
          AVANT
        </text>
      </g>
      
      <!-- Deck Labels -->
      <g v-for="(label, idx) in visibleLabels" :key="`label-${idx}`">
        <text 
          :x="label.x" 
          :y="label.y" 
          class="text-sm font-black fill-slate-800 uppercase tracking-widest"
          :transform="verticalMode ? `rotate(-90 ${label.x} ${label.y})` : undefined"
        >
          {{ label.label }}
        </text>
      </g>

      <!-- Sièges -->
      <g v-for="seat in visibleSeats" :key="`seat-${seat.number}`">
        <!-- 1. VISUAL LAYER -->
        
        <!-- Suggestion Glow (Behind seat) -->
        <rect 
          v-if="isSuggested(seat.number) && !seat.isOccupied"
          :x="seat.x - 4" 
          :y="seat.y - 4" 
          :width="SEAT_WIDTH + 8" 
          :height="SEAT_HEIGHT + 8"
          fill="none"
          stroke="#22C55E"
          stroke-width="3"
          rx="10"
          class="suggestion-pulse pointer-events-none"
          :style="{ animationDelay: `${getSuggestionRank(seat.number) * 0.1}s` }"
        />

        <!-- Seat Base (Color) -->
        <rect 
          :x="seat.x" 
          :y="seat.y" 
          :width="SEAT_WIDTH" 
          :height="SEAT_HEIGHT"
          :fill="isSuggested(seat.number) && !seat.isOccupied ? '#4ADE80' : getSeatColor(seat)"
          :stroke="isSelected(seat.number) ? '#FFFFFF' : (isSuggested(seat.number) && !seat.isOccupied ? '#16A34A' : '#475569')"
          :stroke-width="isSelected(seat.number) ? 3 : (isSuggested(seat.number) ? 3 : 2)"
          rx="6"
          class="pointer-events-none"
        />
        
        <!-- Seat Back -->
        <rect 
          :x="seat.x + 3" 
          :y="seat.y + 3" 
          :width="SEAT_WIDTH - 6" 
          :height="SEAT_HEIGHT * 0.4"
          :fill="isSuggested(seat.number) && !seat.isOccupied ? '#4ADE80' : getSeatColor(seat)"
          :stroke="isSelected(seat.number) ? '#FFFFFF' : (isSuggested(seat.number) && !seat.isOccupied ? '#16A34A' : '#334155')"
          stroke-width="1"
          rx="4"
          class="pointer-events-none"
        />
        
        <!-- Seat Cushion -->
        <rect 
          :x="seat.x + 3" 
          :y="seat.y + SEAT_HEIGHT * 0.45" 
          :width="SEAT_WIDTH - 6" 
          :height="SEAT_HEIGHT * 0.5"
          :fill="isSuggested(seat.number) && !seat.isOccupied ? '#4ADE80' : getSeatColor(seat)"
          :stroke="isSelected(seat.number) ? '#FFFFFF' : (isSuggested(seat.number) && !seat.isOccupied ? '#16A34A' : '#334155')"
          stroke-width="1"
          rx="3"
          class="pointer-events-none"
        />

        <!-- Suggestion Badge (On Top of Seat Visuals) -->
        <g v-if="isSuggested(seat.number) && !seat.isOccupied" class="pointer-events-none">
          <circle
            :cx="seat.x + SEAT_WIDTH - 2"
            :cy="seat.y + 2"
            r="10"
            fill="#22C55E"
            stroke="white"
            stroke-width="2"
          />
          <text
            :x="seat.x + SEAT_WIDTH - 2"
            :y="seat.y + 6"
            text-anchor="middle"
            class="text-[10px] font-black fill-white select-none"
            :transform="verticalMode ? `rotate(-90 ${seat.x + SEAT_WIDTH - 2} ${seat.y + 6})` : undefined"
          >
            {{ getSuggestionRank(seat.number) }}
          </text>
        </g>
        
        <!-- Seat Number -->
        <text 
          :x="seat.x + SEAT_WIDTH / 2" 
          :y="seat.y + SEAT_HEIGHT / 2 + 6" 
          text-anchor="middle" 
          class="text-lg font-black fill-white pointer-events-none select-none"
          :transform="verticalMode ? `rotate(-90 ${seat.x + SEAT_WIDTH / 2} ${seat.y + SEAT_HEIGHT / 2 + 6})` : undefined"
        >
          {{ seat.number }}
        </text>

        <!-- 2. INTERACTION LAYER (Transparent Click Catcher on TOP) -->
        <rect 
          :x="seat.x" 
          :y="seat.y" 
          :width="SEAT_WIDTH" 
          :height="SEAT_HEIGHT"
          fill="white"
          fill-opacity="0"
          style="pointer-events: all !important;"
          class="cursor-pointer hover:bg-black hover:bg-opacity-10"
          :class="[
            seat.isOccupied ? 'cursor-not-allowed' : 'cursor-pointer',
            isSelected(seat.number) ? 'border-2 border-white' : ''
          ]"
          @click.stop="handleSeatClick(seat)"
        >
          <title>{{ seat.isOccupied ? `Occupé - ${seat.destination_name}` : (isSuggested(seat.number) ? `⭐ SUGGÉRÉ #${getSuggestionRank(seat.number)} - Place ${seat.number}` : `Place ${seat.number} - Disponible`) }}</title>
        </rect>
      </g>
      
      <!-- Doors (rendered after seats so they appear on top) -->
      <g v-for="door in doorPositions" :key="`door-${door.type}`">
        <!-- Door frame -->
        <rect 
          :x="door.x" 
          :y="door.y" 
          :width="door.width" 
          :height="door.height"
          fill="white"
          stroke="#10B981"
          stroke-width="3"
          rx="4"
        />
        
        <!-- Glass panels -->
        <rect
          :x="door.x + 3"
          :y="door.y + 3"
          :width="door.width - 6"
          :height="(door.height / 2) - 4"
          fill="#E0F2FE" 
          stroke="#10B981"
          stroke-width="1"
          rx="2"
        />
        <rect
          :x="door.x + 3"
          :y="door.y + (door.height / 2) + 1"
          :width="door.width - 6"
          :height="(door.height / 2) - 4"
          fill="#E0F2FE"
          stroke="#10B981"
          stroke-width="1"
          rx="2"
        />
        
        <!-- Door label -->
        <text 
          :x="door.x + door.width / 2" 
          :y="door.y + door.height / 2 + 5" 
          text-anchor="middle" 
          class="text-xs font-bold fill-green-700"
          :transform="verticalMode ? `rotate(-90 ${door.x + door.width / 2} ${door.y + door.height / 2 + 5})` : undefined"
        >
          {{ door.label }}
        </text>
      </g>
    </svg>
  </div>
</template>

<style scoped>
.vehicle-svg-container {
  @apply w-full h-full flex items-start justify-center bg-white rounded-lg p-0;
}

/* Pulsing animation for suggested seats */
@keyframes suggestion-pulse {
  0%, 100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.5;
    transform: scale(1.05);
  }
}

.suggestion-pulse {
  animation: suggestion-pulse 1.5s ease-in-out infinite;
  transform-origin: center;
  transform-box: fill-box;
}

.suggested-seat {
  filter: drop-shadow(0 0 6px rgba(34, 197, 94, 0.6));
}
</style>
