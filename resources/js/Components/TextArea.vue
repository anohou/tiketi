<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: String,
    rows: {
        type: [Number, String],
        default: 3
    }
});

defineEmits(['update:modelValue']);

const textarea = ref(null);

onMounted(() => {
    if (textarea.value.hasAttribute('autofocus')) {
        textarea.value.focus();
    }
});

defineExpose({ focus: () => textarea.value.focus() });
</script>

<template>
    <textarea
        ref="textarea"
        class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm bg-white text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100 dark:focus:border-emerald-400"
        :value="modelValue"
        :rows="rows"
        @input="$emit('update:modelValue', $event.target.value)"
    ></textarea>
</template>