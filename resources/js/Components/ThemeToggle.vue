<script setup>
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme.js';

const { isDark, toggleTheme } = useTheme();

const label = computed(() => (isDark.value ? 'Passer en mode clair' : 'Passer en mode sombre'));
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
        :aria-label="label"
        :title="label"
        @click="toggleTheme"
    >
        <svg v-if="isDark" class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3v2.5M12 18.5V21M4.5 12H2M22 12h-2.5M6.4 6.4 4.6 4.6M19.4 19.4l-1.8-1.8M17.6 6.4l1.8-1.8M4.6 19.4l1.8-1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.8" />
        </svg>
        <svg v-else class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M21 12.4A8.5 8.5 0 1 1 11.6 3 7 7 0 0 0 21 12.4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
        </svg>
        <span class="hidden sm:inline">{{ isDark ? 'Light' : 'Dark' }}</span>
    </button>
</template>
