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

// Filter out trailing empty rows
const validRows = computed(() => {
  if (!props.seatMap.seat_map) return [];
  const rows = [...props.seatMap.seat_map];
  // Remove trailing rows that have no seats
  while (rows.length > 0) {
    const lastRow = rows[rows.length - 1];
    const hasSeats = lastRow.some(item => item.type === 'seat');
    if (!hasSeats) {
      rows.pop();
    } else {
      break;
    }
  }
  return rows;
});

// Calculer les dimensions du SVG
const svgDimensions = computed(() => {
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  const aisles = parts.length - 1;
  
  const width = MARGIN * 2 + (seatsPerRow * SEAT_WIDTH) + ((seatsPerRow - 1) * SEAT_SPACING) + (aisles * (AISLE_WIDTH - SEAT_SPACING));
  const rows = validRows.value.length;
  // Calculate height: Margins + Cabin + (Remaining rows * (Height + Spacing))
  // Row 0 is inside the cabin, so we only add height for rows 1..N
  const height = MARGIN * 2 + DRIVER_CABIN_HEIGHT + (Math.max(0, rows - 1) * (SEAT_HEIGHT + ROW_SPACING));
  
  console.log('SVG dimensions:', { width, height, rows });
  return { width, height };
});

// Générer les positions des sièges
const seatPositions = computed(() => {
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  const aisles = parts.length - 1;
  const positions = [];
  
  validRows.value.forEach((row, rowIndex) => {
    // Check if this is a standard row (matches config structure)
    // A standard row in 2+2 has 5 elements (2 seats + aisle + 2 seats)
    // But our stored map might have different structure.
    // Let's count actual seats in this row
    const seatsInRow = row.filter(s => s.type === 'seat').length;
    const totalSlots = row.length;
    
    // Calculate Y position for this row
    // Row 0 is the driver row, place it inside the cabin
    // Subsequent rows start after the cabin
    const y = rowIndex === 0 
      ? MARGIN + 10 
      : MARGIN + DRIVER_CABIN_HEIGHT + ((rowIndex - 1) * (SEAT_HEIGHT + ROW_SPACING));

    // Special handling for rows that don't match the standard layout (e.g. last row with more seats)
    // Standard 2+2 has 4 seats. If we have more, or if the structure is just a flat list of seats (like last row often is)
    const isStandardRow = row.some(s => s.type === 'aisle');
    
    if (!isStandardRow && seatsInRow > 0) {
      // Distribute seats evenly across the width
      // Calculate total available width (excluding margins)
      const totalWidth = (seatsPerRow * SEAT_WIDTH) + ((seatsPerRow - 1) * SEAT_SPACING) + (aisles * (AISLE_WIDTH - SEAT_SPACING));
      
      // Calculate spacing for this specific row
      // We want to span the full width. 
      // If we have N seats, we have N items and N-1 spaces.
      // But we also want to align somewhat if possible. 
      // Simple approach: Center them or Justify them.
      // Let's try to justify them to fill the width.
      
      const rowWidth = (seatsInRow * SEAT_WIDTH);
      const remainingSpace = totalWidth - rowWidth;
      const spacePerGap = seatsInRow > 1 ? remainingSpace / (seatsInRow - 1) : 0;
      
      let currentX = MARGIN;
      let seatCount = 0;
      
      row.forEach((item) => {
        if (item.type === 'seat') {
          positions.push({
            ...item,
            x: currentX,
            y: y,
            sectionIndex: 0 // Treat as one section
          });
          currentX += SEAT_WIDTH + spacePerGap;
          seatCount++;
        }
      });
    } else {
      // Standard row processing
      // The stored map from VehicleTypeController has explicit 'aisle' items.
      // It looks like: [seat, seat, aisle, seat, seat]
      
      let currentX = MARGIN;
      
      row.forEach((item) => {
        if (item.type === 'seat') {
          positions.push({
            ...item,
            x: currentX,
            y: y,
            sectionIndex: 0
          });
          currentX += SEAT_WIDTH + SEAT_SPACING;
        } else if (item.type === 'empty') {
           currentX += SEAT_WIDTH + SEAT_SPACING;
        } else if (item.type === 'aisle') {
           currentX += (AISLE_WIDTH - SEAT_SPACING);
        }
      });
    }
  });
  
  return positions;
});

const visibleSeats = computed(() => {
  return seatPositions.value;
});

// Door positions
const doorPositions = computed(() => {
  const doors = [];
  const config = props.vehicleType.seat_configuration || '2+2';
  const parts = config.split('+').map(Number);
  const seatsPerRow = parts.reduce((a, b) => a + b, 0);
  
  // Get door positions from DB or default to [0]
  const dbDoorPositions = props.vehicleType.door_positions || [0];
  
  // Sort and group consecutive seats
  const sortedPositions = [...dbDoorPositions].sort((a, b) => a - b);
  const groups = [];
  let currentGroup = [];
  
  sortedPositions.forEach((pos, index) => {
    if (currentGroup.length === 0) {
      currentGroup.push(pos);
    } else {
      const lastPos = currentGroup[currentGroup.length - 1];
      if (pos === lastPos + 1) {
        currentGroup.push(pos);
      } else {
        groups.push(currentGroup);
        currentGroup = [pos];
      }
    }
    
    if (index === sortedPositions.length - 1) {
      groups.push(currentGroup);
    }
  });
  
  let doorCount = 1;
  
  groups.forEach(group => {
    const startSeat = group[0];
    const isFrontDoor = startSeat === 0;
    
    if (isFrontDoor) {
      // Front door logic (aligned with driver)
      // Position it on the far right edge
      const x = svgDimensions.value.width - MARGIN - SEAT_WIDTH;
      
      doors.push({
        x: x,
        y: MARGIN, // Aligned with the front edge
        width: SEAT_WIDTH,
        height: SEAT_HEIGHT,
        label: `D${doorCount++}`,
        type: 'front'
      });
    } else {
      // Middle/Rear door logic
      // Calculate row index
      const rowIndex = Math.ceil(startSeat / seatsPerRow) - 1;
      const y = MARGIN + DRIVER_CABIN_HEIGHT + (rowIndex * (SEAT_HEIGHT + ROW_SPACING));
      
      // Calculate X based on column
      // Col index (1-based)
      const colIndex = (startSeat - 1) % seatsPerRow + 1;
      
      let x = MARGIN;
      let remainingCol = colIndex;
      
      for (let i = 0; i < parts.length; i++) {
        if (remainingCol <= parts[i]) {
            // It's in this part
            x += (remainingCol - 1) * (SEAT_WIDTH + SEAT_SPACING);
            break;
        } else {
            // Move to next part
            x += parts[i] * (SEAT_WIDTH + SEAT_SPACING); // Width of this part
            x += AISLE_WIDTH - SEAT_SPACING; // Add aisle
            remainingCol -= parts[i];
        }
      }
      
      // Calculate width based on group size
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
  
  console.log('Door positions:', doors);
  return doors;
});

// Obtenir la couleur d'un siège
const getSeatColor = (seat) => {
  if (seat.isOccupied) {
    return seat.color || '#EF4444';
  }
  if (isSelected(seat.number)) {
    return props.selectedColor || '#A855F7';
  }
  return '#94A3B8';
};

// Vérifier si un siège est suggéré (only during active sales)
const isSuggested = (seatNumber) => {
  // Only show suggestions when showSuggestions is true (during active destination selection)
  if (!props.showSuggestions || !props.suggestedSeats || props.suggestedSeats.length === 0) {
    return false;
  }
  return props.suggestedSeats.some(s => {
    const sNum = s.seat_number !== undefined ? s.seat_number : s;
    return Number(sNum) === Number(seatNumber);
  });
};

// Get the suggestion rank (1 = best, 2 = second best, etc.)
const getSuggestionRank = (seatNumber) => {
  if (!props.showSuggestions || !props.suggestedSeats || props.suggestedSeats.length === 0) return 0;
  const index = props.suggestedSeats.findIndex(s => {
    const sNum = s.seat_number !== undefined ? s.seat_number : s;
    return Number(sNum) === Number(seatNumber);
  });
  return index >= 0 ? index + 1 : 0;
};

// Vérifier si un siège est sélectionné
const isSelected = (seatNumber) => {
  return props.selectedSeat === seatNumber;
};

// Gérer le clic sur un siège
const handleSeatClick = (seat) => {
  if (!seat.isOccupied || props.allowOccupiedClick) {
    console.log('[SVG] handleSeatClick:', seat.number);
    emit('seat-click', seat.number);
  } else {
    console.log('[SVG] handleSeatClick (Occupied - Blocked):', seat.number);
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
        >
          AVANT
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
