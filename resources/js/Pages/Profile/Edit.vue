<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, usePage } from '@inertiajs/vue3';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    assignedStations: {
        type: Array,
        default: () => [],
    },
});

const user = usePage().props.auth.user;
const isSeller = user.role === 'seller';
</script>

<template>
    <Head title="Profile" />

    <MainNavLayout :show-nav="true">
        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Page Header -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-orange-100">
                <h1 class="text-3xl font-black text-gray-900 tracking-tight">Mon Profil</h1>
                <p class="text-gray-500 font-medium">Gérez vos informations personnelles et paramètres de compte</p>
            </div>

            <!-- Assigned Stations Section (for sellers) -->
            <div v-if="isSeller" class="bg-white p-6 shadow-sm rounded-2xl border border-orange-100">
                <section>
                    <header>
                        <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                            <OfficeBuilding class="text-green-600" :size="24" />
                            Stations assignées
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Les stations pour lesquelles vous êtes autorisé à vendre des billets.
                        </p>
                    </header>

                    <div class="mt-6">
                        <div v-if="assignedStations.length > 0" class="space-y-3">
                            <div 
                                v-for="station in assignedStations" 
                                :key="station.id"
                                class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-xl"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-white rounded-lg shadow-sm">
                                        <OfficeBuilding class="text-green-600" :size="20" />
                                    </div>
                                    <div>
                                        <div class="font-bold text-gray-900">{{ station.name }}</div>
                                        <div class="text-xs text-gray-500">Assigné le {{ station.assigned_at }}</div>
                                    </div>
                                </div>
                                <div class="px-3 py-1 bg-green-100 text-green-700 text-xs font-bold rounded-full">
                                    Actif
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8 bg-orange-50 border border-orange-200 rounded-xl">
                            <OfficeBuilding class="text-orange-400 mx-auto mb-3" :size="40" />
                            <h3 class="text-lg font-bold text-gray-700 mb-2">Aucune station assignée</h3>
                            <p class="text-sm text-gray-500 max-w-md mx-auto">
                                Vous n'avez pas encore de station assignée. Veuillez contacter votre superviseur pour pouvoir vendre des billets.
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Profile Information -->
            <div class="bg-white p-6 shadow-sm rounded-2xl border border-orange-100">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>

            <!-- Password Update -->
            <div class="bg-white p-6 shadow-sm rounded-2xl border border-orange-100">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <!-- Delete Account -->
            <div class="bg-white p-6 shadow-sm rounded-2xl border border-orange-100">
                <DeleteUserForm class="max-w-xl" />
            </div>
        </div>
    </MainNavLayout>
</template>
