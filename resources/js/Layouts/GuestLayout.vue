<script setup>
import { Link } from '@inertiajs/vue3';
import Receipt from 'vue-material-design-icons/Receipt.vue';

localStorage.getItem('locale') ? localStorage.getItem('locale') : localStorage.setItem('locale', 'fr');
</script>

<template>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-green-50">
        <div>
            <Link href="/" class="flex items-center gap-2">
                <!-- Logo -->
                <div class="flex items-center">
                    <img src="/images/logo.png" alt="TIKÊTI Logo" class="h-12 w-auto object-contain" />
                </div>
            </Link>
        </div>

        <div
            class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg
                   border border-orange-200"
        >
            <slot />
        </div>
    </div>
</template>