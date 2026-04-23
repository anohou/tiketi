<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    canLogin: { type: Boolean },
    isTenant: { type: Boolean, default: false },
    tenant: { type: Object, default: null },
    users: { type: Array, default: () => [] },
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const imageError = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const fillCredentials = (user) => {
    form.email = user.email;
    form.password = 'password';
};
</script>

<template>
    <Head :title="isTenant ? (tenant?.name + ' - Connexion') : 'TIKETI - Gestion Billetterie'" />

    <!-- TENANT PORTAL: CLEAN VERSION -->
    <div v-if="isTenant" class="h-screen w-full flex overflow-hidden bg-gray-50 dark:bg-gray-900">
        
        <!-- LEFT PANEL: Static & Simple -->
        <div class="hidden lg:flex lg:w-1/2 bg-indigo-600 dark:bg-indigo-900 items-center justify-center p-12">
            <div class="max-w-lg w-full text-center">
                <h2 class="text-3xl font-bold text-white mb-8">
                    Gestion intelligente <br/> 
                    de la répartition des sièges
                </h2>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl p-4 shadow-xl">
                    <img v-show="!imageError" src="/images/seat-map.png" @error="imageError = true" alt="Disposition des sièges" class="w-full h-auto rounded-lg" />
                    <div v-show="imageError" class="py-20 text-gray-400">
                        Visualisation Dashboard
                    </div>
                </div>
                
                <p class="mt-8 text-indigo-100 text-sm opacity-80">
                    Synchronisation instantanée entre toutes vos gares.
                </p>
            </div>
        </div>

        <!-- RIGHT PANEL: Login Form -->
        <div class="w-full lg:w-1/2 flex flex-col bg-white dark:bg-gray-950 px-8 py-12 lg:px-20 justify-center">
            <div class="max-w-md w-full mx-auto">
                
                <!-- Brand -->
                <div class="mb-12 flex items-center gap-4">
                    <img :src="tenant?.logo_url || '/images/logo.png'" alt="Logo" class="h-12 w-auto" />
                    <div class="border-l border-gray-200 dark:border-gray-800 pl-4">
                        <span class="block text-[10px] font-bold text-indigo-600 uppercase tracking-widest leading-none mb-1">Espace Partenaire</span>
                        <span class="text-lg font-bold text-gray-900 dark:text-white uppercase">{{ tenant?.name }}</span>
                    </div>
                </div>

                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Connexion</h1>
                    <p class="text-gray-500 text-sm mt-1">Veuillez entrer vos identifiants pour accéder au système.</p>
                </div>

                <div v-if="status" class="mb-6 p-4 bg-green-50 text-green-700 text-sm rounded-lg border border-green-100 font-medium">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <InputLabel for="email" value="Email" class="text-xs font-bold text-gray-400 uppercase mb-1" />
                        <TextInput
                            id="email"
                            type="email"
                            class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            v-model="form.email"
                            required
                            autofocus
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <InputLabel for="password" value="Mot de passe" class="text-xs font-bold text-gray-400 uppercase mb-1" />
                            <Link v-if="canResetPassword" :href="route('password.request')" class="text-xs text-indigo-600 hover:underline">Oublié ?</Link>
                        </div>
                        <TextInput
                            id="password"
                            type="password"
                            class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            v-model="form.password"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="flex items-center">
                        <Checkbox name="remember" v-model:checked="form.remember" class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        <span class="ms-2 text-sm text-gray-600">Rester connecté</span>
                    </div>

                    <PrimaryButton
                        class="w-full justify-center bg-indigo-600 hover:bg-indigo-700 py-3 text-sm font-bold uppercase tracking-wider"
                        :class="{ 'opacity-50': form.processing }"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Connexion...' : 'Se connecter' }}
                    </PrimaryButton>
                </form>

            </div>
        </div>
    </div>

    <!-- LANDLORD PAGE (unchanged for marketing) -->
    <div v-else class="min-h-screen bg-gray-100 text-gray-900">
        <div class="flex items-center justify-center min-h-screen">
             <div class="text-center">
                 <img src="/images/logo.png" class="h-20 mx-auto mb-8" />
                 <h1 class="text-4xl font-black">TIKETI</h1>
                 <p class="mt-4 text-gray-600">Solution de gestion de billetterie pro.</p>
                 <div class="mt-8">
                     <Link :href="route('login')" class="bg-indigo-600 text-white px-8 py-3 rounded-full font-bold">Connexion Admin</Link>
                 </div>
             </div>
        </div>
    </div>
</template>
