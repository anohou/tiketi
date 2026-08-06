<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { setLocale } from '@/i18n.js';

const { t } = useI18n();
const page = usePage();

const currentLocale = computed(() => page.props.locale || 'fr');
const showMenu = ref(false);
const menuRef = ref(null);

const options = [
    { code: 'fr', label: t('common.french') },
    { code: 'en', label: t('common.english') },
];

const currentLabel = computed(
    () => options.find((option) => option.code === currentLocale.value)?.label || t('common.language'),
);

const selectLocale = (code) => {
    if (code === currentLocale.value) {
        showMenu.value = false;
        return;
    }

    setLocale(code);
    showMenu.value = false;

    router.post(route('locale.update'), { locale: code }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => setLocale(code),
    });
};

const onDocumentClick = (event) => {
    if (showMenu.value && menuRef.value && !menuRef.value.contains(event.target)) {
        showMenu.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <div ref="menuRef" class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
            :aria-label="t('common.language')"
            :title="t('common.language')"
            @click="showMenu = !showMenu"
        >
            <span class="hidden sm:inline">{{ currentLabel }}</span>
            <span class="text-[10px]">{{ currentLocale.toUpperCase() }}</span>
        </button>

        <div
            v-if="showMenu"
            class="absolute right-0 top-full z-[70] mt-2 w-[140px] rounded-xl border border-slate-200 bg-white p-1 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
        >
            <button
                v-for="option in options"
                :key="option.code"
                type="button"
                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-200 dark:hover:bg-slate-800"
                :class="{ 'text-emerald-600': option.code === currentLocale }"
                @click="selectLocale(option.code)"
            >
                <span>{{ option.label }}</span>
                <span v-if="option.code === currentLocale" class="text-emerald-600">✓</span>
            </button>
        </div>
    </div>
</template>
