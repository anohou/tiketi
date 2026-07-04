<script setup>
import Modal from './Modal.vue';
import Close from 'vue-material-design-icons/Close.vue';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal
        :show="show"
        :max-width="maxWidth"
        :closeable="closeable"
        @close="close"
    >
        <div class="relative flex-1 overflow-y-auto px-6 py-5">
            <div class="pr-10 text-lg font-semibold text-slate-900 dark:text-slate-100">
                <slot name="title" />
            </div>

            <!-- Absolute positioned Close button -->
            <button
                v-if="closeable"
                @click="close"
                class="absolute right-4 top-4 z-10 shrink-0 rounded-xl p-1.5 text-slate-400 transition-all hover:bg-slate-100 dark:hover:bg-slate-850 hover:text-slate-700 dark:hover:text-slate-200"
                title="Fermer"
            >
                <Close :size="20" />
            </button>

            <div class="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">
                <slot name="content" />
            </div>
        </div>

        <div class="flex flex-row justify-end border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 px-6 py-4 text-end">
            <slot name="footer" />
        </div>
    </Modal>
</template>
