<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import FileExcel from 'vue-material-design-icons/FileExcel.vue';
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue';
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue';

defineProps({
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['export', 'print']);

const isOpen = ref(false);
const dropdownRef = ref(null);

const toggleDropdown = () => {
    isOpen.value = !isOpen.value;
};

const handleExport = () => {
    emit('export');
    isOpen.value = false;
};

const handlePrint = () => {
    emit('print');
    isOpen.value = false;
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        isOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="relative inline-block text-left" ref="dropdownRef">
        <button 
            @click="toggleDropdown"
            :disabled="disabled"
            type="button"
            :class="[
                'p-2 rounded-lg transition-colors border shadow-sm flex items-center justify-center',
                disabled 
                    ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed dark:bg-slate-800 dark:border-slate-700 dark:text-slate-600' 
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800'
            ]"
            title="Options d'exportation"
        >
            <DotsVertical :size="20" />
        </button>

        <transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div 
                v-if="isOpen" 
                class="absolute right-0 mt-2 w-48 rounded-xl bg-white dark:bg-slate-900 shadow-lg border border-slate-200 dark:border-slate-800 py-1 z-50 origin-top-right focus:outline-none"
            >
                <button
                    @click="handleExport"
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800 flex items-center gap-2 transition-colors font-medium"
                >
                    <FileExcel class="text-emerald-600 dark:text-emerald-500" :size="16" />
                    <span>Exporter en CSV</span>
                </button>
                
                <button
                    @click="handlePrint"
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800 flex items-center gap-2 transition-colors font-medium"
                >
                    <FilePdfBox class="text-rose-600 dark:text-rose-500" :size="16" />
                    <span>Imprimer en PDF</span>
                </button>
            </div>
        </transition>
    </div>
</template>
