<script setup>
import Settings from 'vue-material-design-icons/Cog.vue';

const props = defineProps({
    title: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    },
    icon: {
        type: Object,
        default: () => Settings
    }
});
</script>

<template>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 flex items-center gap-3">
                <div class="p-2 bg-emerald-100 rounded-2xl">
                    <component :is="icon" class="text-emerald-600" :size="28" />
                </div>
                {{ title }}
            </h1>
            <p v-if="subtitle" class="text-slate-500 mt-1">{{ subtitle }}</p>
        </div>
        <div class="flex items-center gap-3">
            <slot name="actions" />
        </div>
    </div>
</template>
