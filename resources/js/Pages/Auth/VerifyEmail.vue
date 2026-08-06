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
        <Head :title="$t('auth.verify_email.title')" />

        <div class="mx-auto max-w-md">
            <div class="mb-8 text-center">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-600 dark:text-emerald-400">{{ $t('auth.badge.validation') }}</p>
                <h1 class="mt-3 text-2xl font-black text-slate-900 dark:text-white">{{ $t('auth.verify_email.heading') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-300">
                    {{ $t('auth.verify_email.description') }}
                </p>
            </div>

            <div
                class="mb-4 text-sm font-medium text-emerald-600"
                v-if="verificationLinkSent"
            >
                {{ $t('auth.verify_email.link_sent') }}
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex items-center justify-between pt-2">
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                    >
                        {{ $t('auth.logout') }}
                    </Link>

                    <PrimaryButton
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                    >
                        {{ $t('auth.verify_email.resend_link') }}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
