<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirmer le mot de passe" />

        <div class="mx-auto max-w-md">
            <div class="mb-8 text-center">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-600 dark:text-emerald-400">Sécurité</p>
                <h1 class="mt-3 text-2xl font-black text-slate-900 dark:text-white">Confirmez votre mot de passe</h1>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-300">
                    Cette zone est protégée. Merci de confirmer votre mot de passe pour continuer.
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <InputLabel for="password" value="Mot de passe" />
                    <TextInput
                        id="password"
                        type="password"
                        class="mt-1 block w-full"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        autofocus
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex justify-end pt-2">
                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                    >
                        Confirmer
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
