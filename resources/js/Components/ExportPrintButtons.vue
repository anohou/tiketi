<script setup>
import FileExcel from 'vue-material-design-icons/FileExcel.vue';
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue';

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
                    ? 'bg-slate-100 text-slate-400 cursor-not-allowed' 
                    : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200'
            ]"
            title="Exporter en CSV compatible Excel"
        >
            <FileExcel :size="small ? 14 : 16" />
            <span class="hidden sm:inline">CSV</span>
        </button>
        
        <button 
            @click="emit('print')"
            :disabled="disabled"
            :class="[
                'flex items-center gap-1 rounded-lg font-medium transition-all',
                small ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm',
                disabled 
                    ? 'bg-slate-100 text-slate-400 cursor-not-allowed' 
                    : 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200'
            ]"
            title="Exporter en PDF (choisir 'Enregistrer en PDF' dans la boîte de dialogue d'impression)"
        >
            <FilePdfBox :size="small ? 14 : 16" />
            <span class="hidden sm:inline">PDF</span>
        </button>
    </div>
</template>
