<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

onMounted(() => {
    if (!localStorage.getItem('locale')) {
        localStorage.setItem('locale', 'fr');
    }
});
</script>

<template>
    <div class="min-h-screen bg-[#f7f5f0] text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="relative min-h-screen flex flex-col items-center justify-center px-4 py-10">
            <div class="mb-8 flex w-full max-w-md items-center justify-between">
                <Link href="/" class="flex items-center gap-3">
                    <img src="/images/logo.png" alt="TIKÊTI Logo" class="h-12 w-auto object-contain" />
                </Link>

                <ThemeToggle />
            </div>

            <div class="w-full max-w-md rounded-3xl border border-white/70 bg-white/90 px-6 py-8 shadow-[0_20px_60px_rgba(15,23,42,0.08)] backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-black/20">
                <slot />
            </div>
        </div>
    </div>
</template>
