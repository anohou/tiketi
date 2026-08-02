<script setup>
import Modal from './Modal.vue';
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue';
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue';

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
    variant: {
        type: String,
        default: 'warning',
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
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
                <div :class="variant === 'danger' ? 'bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300' : 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300'" class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-2xl sm:mx-0 sm:size-11">
                    <DeleteOutline v-if="variant === 'danger'" :size="24" />
                    <AlertOutline v-else :size="24" />
                </div>

                <div class="mt-3 text-center sm:mt-0 sm:ms-4 sm:text-start">
                    <h3 class="text-lg font-black text-slate-900 dark:text-slate-100">
                        <slot name="title" />
                    </h3>

                    <div class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                        <slot name="content" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-row justify-end border-t border-slate-100 bg-slate-50 px-6 py-4 text-end">
            <slot name="footer" />
        </div>
    </Modal>
</template>
