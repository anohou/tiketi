<script setup>
import { computed } from 'vue';

const props = defineProps({
    stops: {
        type: Array,
        default: () => [],
    },
    variant: {
        type: String,
        default: 'endpoints',
        validator: (value) => ['endpoints', 'colored'].includes(value),
    },
    height: {
        type: Number,
        default: 240,
    },
    viewBoxWidth: {
        type: Number,
        default: 1000,
    },
    viewBoxHeight: {
        type: Number,
        default: 240,
    },
    lineColor: {
        type: String,
        default: '#CBD5E1',
    },
    activeColor: {
        type: String,
        default: '#10B981',
    },
    mutedColor: {
        type: String,
        default: '#E2E8F0',
    },
    donutInnerFill: {
        type: String,
        default: 'rgba(255,255,255,0.8)',
    },
    consultedColor: {
        type: String,
        default: '#10B981',
    },
    consultedStroke: {
        type: String,
        default: '#059669',
    },
    highlightStationId: {
        type: [String, Number],
        default: null,
    },
});

const nodes = computed(() => {
    const stops = props.stops || [];
    if (stops.length === 0) return [];

    const usableWidth = props.viewBoxWidth - 120;
    const step = stops.length > 1 ? usableWidth / (stops.length - 1) : 0;
    const compact = stops.length > 6;
    const mini = stops.length > 10;
    const labelSize = mini ? 10 : compact ? 11 : 13;
    const maxNameLength = mini ? 12 : compact ? 16 : 22;

    const shorten = (value, limit) => {
        if (!value) return '';
        return value.length > limit ? `${value.slice(0, Math.max(0, limit - 1))}…` : value;
    };

    return stops.map((stop, index) => {
        const isFirst = index === 0;
        const isLast = index === stops.length - 1;
        const isConsulted = Boolean(
            stop.isConsulted
            || (props.highlightStationId !== null && props.highlightStationId !== undefined && String(stop.id) === String(props.highlightStationId))
        );
        const fallbackFill = props.variant === 'colored'
            ? (stop.color || props.activeColor)
            : (isFirst || isLast ? props.activeColor : props.mutedColor);
        const highlightedFill = props.consultedColor;
        const highlightedStroke = props.consultedStroke;
        const fill = isConsulted
            ? highlightedFill
            : (stop.fill || fallbackFill);
        const stroke = isConsulted
            ? highlightedStroke
            : (stop.stroke || fill);

        return {
            ...stop,
            x: 60 + (index * step),
            labelY: index % 2 === 0 ? 82 : 170,
            labelSize,
            displayName: shorten(stop.name, maxNameLength),
            isFirst,
            isLast,
            isConsulted,
            fill,
            stroke,
            textColor: stop.textColor || null,
            ringFill: isConsulted ? highlightedFill : props.donutInnerFill,
        };
    });
});
</script>

<template>
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-gradient-to-b from-slate-50 to-white dark:from-slate-950 dark:to-slate-900 overflow-x-auto">
        <svg
            class="block min-w-[920px] w-full"
            width="100%"
            :height="height"
            :viewBox="`0 0 ${viewBoxWidth} ${viewBoxHeight}`"
            preserveAspectRatio="xMinYMin meet"
        >
            <line
                x1="60"
                :y1="viewBoxHeight / 2"
                :x2="nodes[nodes.length - 1]?.x || (viewBoxWidth - 60)"
                :y2="viewBoxHeight / 2"
                class="stroke-slate-300 dark:stroke-slate-600"
                stroke-width="10"
                stroke-linecap="round"
            />

            <g v-for="node in nodes" :key="node.id">
                <circle
                    :cx="node.x"
                    :cy="viewBoxHeight / 2"
                    r="19"
                    :fill="node.isConsulted ? consultedColor : node.fill"
                    :stroke="node.isConsulted ? consultedStroke : node.stroke"
                    stroke-width="3.5"
                />
                <circle
                    :cx="node.x"
                    :cy="viewBoxHeight / 2"
                    r="8"
                    :fill="node.isConsulted ? donutInnerFill : node.ringFill"
                />
                <text
                    :x="node.x"
                    :y="node.labelY"
                    text-anchor="middle"
                    :style="{ fontSize: `${node.labelSize}px` }"
                    class="fill-current font-semibold text-slate-900 dark:text-slate-100"
                >
                    {{ node.displayName }}
                </text>
            </g>
        </svg>
    </div>
</template>
