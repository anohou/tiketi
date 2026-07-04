<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Mot de passe oublié" />

        <div class="mx-auto max-w-md">
            <div class="mb-8 text-center">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-600">Réinitialisation</p>
                <h1 class="mt-3 text-2xl font-black text-slate-900">Mot de passe oublié ?</h1>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                    Indiquez votre adresse e-mail et nous vous enverrons un lien sécurisé pour en définir un nouveau.
                </p>
            </div>

            <div
                v-if="status"
                class="mb-4 text-sm font-medium text-emerald-600"
            >
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <InputLabel for="email" value="Adresse e-mail" />

                    <TextInput
                        id="email"
                        type="email"
                        class="mt-1 block w-full"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                    />

                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div class="flex items-center justify-between pt-2">
                    <Link
                        :href="route('login')"
                        class="rounded-md text-sm text-slate-600 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                    >
                        Retour à la connexion
                    </Link>

                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                    >
                        Envoyer le lien
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
