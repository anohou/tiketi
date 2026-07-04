<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

const props = defineProps({
    auth: Object
});

onMounted(() => {
    // Redirect to appropriate dashboard based on role
    if (props.auth?.user?.role === 'superadmin') {
        router.visit(route('landlord.tenants.index'));
    } else if (props.auth?.user?.role === 'admin') {
        router.visit(route('admin.dashboard'));
    } else if (props.auth?.user?.role === 'fleet_manager') {
        router.visit(route('fleet.dashboard'));
    } else if (props.auth?.user?.role === 'supervisor') {
        router.visit(route('supervisor.dashboard'));
    } else if (props.auth?.user?.role === 'seller') {
        router.visit(route('seller.dashboard'));
    } else if (props.auth?.user?.role === 'accountant') {
        router.visit(route('accountant.reports'));
    } else if (props.auth?.user?.role === 'executive') {
        router.visit(route('executive.analytics'));
    } else {
        // Default fallback - should not happen
        console.warn('Unknown role:', props.auth?.user?.role);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <MainNavLayout>
        <div class="w-full px-4">
            <div class="bg-gradient-to-r from-emerald-50 to-slate-50 border-b border-slate-200 px-4 py-2 mb-4 rounded-2xl">
                <h1 class="text-2xl font-bold text-slate-800">Redirection...</h1>
                <p class="mt-1 text-sm text-slate-500">Redirection vers votre tableau de bord</p>
            </div>
            
            <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-6 text-center">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-emerald-600 mx-auto mb-4"></div>
                <p class="text-slate-600">Chargement en cours...</p>
            </div>
        </div>
    </MainNavLayout>
</template>
