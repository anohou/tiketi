<script setup>
import FileExcel from 'vue-material-design-icons/FileExcel.vue';
import Printer from 'vue-material-design-icons/Printer.vue';

defineProps({
    disabled: {
        type: Boolean,
        default: false
    },
    small: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['export', 'print']);
</script>

<template>
    <div class="flex items-center gap-2">
        <button 
            @click="emit('export')"
            :disabled="disabled"
            :class="[
                'flex items-center gap-1 rounded-lg font-medium transition-all',
                small ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm',
                disabled 
                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                    : 'bg-green-50 text-green-700 hover:bg-green-100 border border-green-200'
            ]"
            title="Exporter en Excel"
        >
            <FileExcel :size="small ? 14 : 16" />
            <span class="hidden sm:inline">Excel</span>
        </button>
        
        <button 
            @click="emit('print')"
            :disabled="disabled"
            :class="[
                'flex items-center gap-1 rounded-lg font-medium transition-all',
                small ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm',
                disabled 
                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                    : 'bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200'
            ]"
            title="Imprimer la liste"
        >
            <Printer :size="small ? 14 : 16" />
            <span class="hidden sm:inline">Imprimer</span>
        </button>
    </div>
</template>
