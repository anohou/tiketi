<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Vérification de l'e-mail" />

        <div class="mx-auto max-w-md">
            <div class="mb-8 text-center">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-600 dark:text-emerald-400">Validation</p>
                <h1 class="mt-3 text-2xl font-black text-slate-900 dark:text-white">Vérifiez votre adresse e-mail</h1>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-300">
                    Un lien de validation vous a été envoyé. Cliquez dessus pour activer votre compte.
                </p>
            </div>

            <div
                class="mb-4 text-sm font-medium text-emerald-600"
                v-if="verificationLinkSent"
            >
                Un nouveau lien de vérification a été envoyé à l'adresse e-mail fournie lors de l'inscription.
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex items-center justify-between pt-2">
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                    >
                        Déconnexion
                    </Link>

                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                    >
                        Renvoyer le lien
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
