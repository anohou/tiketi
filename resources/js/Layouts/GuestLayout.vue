<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';

onMounted(() => {
    if (!localStorage.getItem('locale')) {
        localStorage.setItem('locale', 'fr');
    }
});
</script>

<template>
    <div class="min-h-screen bg-[#f8faf8] text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute -top-32 left-1/4 h-72 w-72 rounded-full bg-emerald-200/35 blur-3xl dark:bg-emerald-500/10"></div>
            <div class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-slate-200/40 blur-3xl dark:bg-slate-700/20"></div>
        </div>

        <div class="relative min-h-screen flex flex-col items-center justify-center px-4 py-10">
            <div class="mb-8 flex w-full max-w-md items-center justify-between">
                <Link href="/" class="flex items-center gap-3">
                    <ApplicationLogo class="h-14 w-auto sm:h-16 drop-shadow-[0_4px_10px_rgba(15,23,42,0.08)] dark:drop-shadow-[0_0_12px_rgba(255,255,255,0.14)]" />
                </Link>

                <div class="flex items-center gap-2">
                    <!-- Accès à la documentation avant connexion -->
                    <Link
                        :href="'/documentation'"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-emerald-700 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:bg-slate-900 dark:text-emerald-300 dark:hover:border-emerald-600"
                        :title="'Documentation'"
                    >
                        <HelpCircleOutline :size="16" />
                        Documentation
                    </Link>
                    <ThemeToggle />
                </div>
            </div>

            <div class="w-full max-w-md rounded-3xl border border-white/70 bg-white/92 px-6 py-8 shadow-[0_20px_60px_rgba(15,23,42,0.08)] backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-black/20">
                <slot />
            </div>
        </div>

        <!-- Global Toast Container -->
        <ToastContainer />
    </div>
</template>
