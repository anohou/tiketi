<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    options: {
        type: Array,
        required: true
    },
    valueKey: {
        type: String,
        default: 'id'
    },
    labelKey: {
        type: String,
        default: 'name'
    },
    placeholder: {
        type: String,
        default: 'Select an option'
    },
    disabled: {
        type: Boolean,
        default: false
    },
    code: {
        type: Boolean,
        default: false
    },
    first_name: {
        type: Boolean,
        default: false
    },
    amount: {
        type: Boolean,
        default: false
    },
    showAllOption: {
        type: Boolean,
        default: false
    },
    allOptionLabel: {
        type: String,
        default: 'Tous'
    },
    allOptionValue: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue']);

const select = ref(null);

onMounted(() => {
    if (select.value.hasAttribute('autofocus')) {
        select.value.focus();
    }
});

defineExpose({ focus: () => select.value.focus() });
</script>

<template>
    <select ref="select"
        class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm bg-white text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100 dark:focus:border-emerald-400"
        :class="{ 'text-slate-400 dark:text-slate-500': !modelValue }" :value="modelValue" :disabled="disabled"
        @change="$emit('update:modelValue', $event.target.value)">
        <option value="" disabled selected class="bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-400">{{ placeholder }}</option>
        <option v-if="showAllOption" :value="allOptionValue" class="bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100">{{ allOptionLabel }}</option>
        <option v-for="option in options" :key="option[valueKey]" :value="option[valueKey]" class="bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100">
            <span v-if="code" class="text-slate-400 dark:text-slate-500 text-xs">{{ option["code"] }} : </span>
            <span v-if="first_name">{{ option["last_name"] }} {{ option["first_name"] }} </span>
            <span v-else-if="amount">{{ option[labelKey].toLocaleString() }} FCFA </span>
            <span v-else>{{ option[labelKey] }}</span>
        </option>
    </select>
</template>
