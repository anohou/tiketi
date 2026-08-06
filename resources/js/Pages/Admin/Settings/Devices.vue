<script setup>
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue';
import Laptop from 'vue-material-design-icons/Laptop.vue';
import Cellphone from 'vue-material-design-icons/Cellphone.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AccountOff from 'vue-material-design-icons/AccountOff.vue';

const { t } = useI18n();

const props = defineProps({
    devices: { type: Array, default: () => [] },
    restrictions: { type: Object, default: () => ({ web: false, control: false }) },
});

const restrictionForm = useForm({
    web: Boolean(props.restrictions.web),
    control: Boolean(props.restrictions.control),
});

const busy = reactive({});

const groups = computed(() => ({
    pending: props.devices.filter((device) => device.status === 'pending'),
    approved: props.devices.filter((device) => device.status === 'approved'),
    inactive: props.devices.filter((device) => ['rejected', 'revoked'].includes(device.status)),
}));

const saveRestrictions = () => {
    restrictionForm.put(route('admin.settings.devices.restrictions'), {
        preserveScroll: true,
    });
};

const updateDevice = (device, action) => {
    busy[device.id] = action;
    router.patch(route('admin.settings.devices.update', device.id), { action }, {
        preserveScroll: true,
        onFinish: () => { delete busy[device.id]; },
    });
};

const confirmRevocation = (device) => {
    if (window.confirm("Êtes-vous sûr de vouloir révoquer cet appareil ? Il perdra immédiatement l'accès au système.")) {
        updateDevice(device, 'revoke');
    }
};

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : 'Jamais';

const statusLabel = {
    pending: 'En attente',
    approved: 'Autorisé',
    rejected: 'Refusé',
    revoked: 'Révoqué',
};
</script>

<template>
    <MainNavLayout :fullHeight="true">
        <Head :title="$t('admin_settings.common.authorized_devices')" />

        <div class="flex flex-col h-full w-full overflow-hidden">
            <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
                <div>
                    <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
                        <div class="p-2 bg-emerald-100 dark:bg-emerald-900/30 rounded-xl">
                            <ShieldLock class="text-emerald-700 dark:text-emerald-300" :size="28" />
                        </div>
                        {{ $t('admin_settings.common.authorized_devices') }}
                    </h1>
                    <p class="text-gray-500 dark:text-slate-450 mt-1">
                        Limitez l’accès aux terminaux explicitement approuvés par votre entreprise.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link
                        :href="route('settings.index')"
                        class="px-4 py-2 rounded-xl border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800"
                    >
                        Retour
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
                <div class="col-span-12 lg:col-span-3 xl:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
                    <SettingsMenu />
                </div>

                <div class="col-span-12 lg:col-span-9 xl:col-span-10 h-full min-h-0 overflow-y-auto pr-2 custom-scrollbar space-y-6">

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="font-black">{{ $t('admin_settings.devices.tenant_restrictions_title') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                            L’activation Web autorise automatiquement cet appareil pour éviter de verrouiller votre administration.
                            Control demandera ensuite une validation pour chaque téléphone ou tablette.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-black text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="restrictionForm.processing"
                        @click="saveRestrictions"
                    >
                        {{ restrictionForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                    </button>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    <label class="flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <span>
                            <span class="block font-bold">{{ $t('admin_settings.devices.tiketi_web') }}</span>
                            <span class="text-xs text-slate-500">{{ $t('admin_settings.devices.tiketi_web_hint') }}</span>
                        </span>
                        <input v-model="restrictionForm.web" type="checkbox" class="h-5 w-5 rounded text-emerald-600 focus:ring-emerald-500">
                    </label>
                    <label class="flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <span>
                            <span class="block font-bold">{{ $t('admin_settings.devices.tiketi_control') }}</span>
                            <span class="text-xs text-slate-500">{{ $t('admin_settings.devices.tiketi_control_hint') }}</span>
                        </span>
                        <input v-model="restrictionForm.control" type="checkbox" class="h-5 w-5 rounded text-emerald-600 focus:ring-emerald-500">
                    </label>
                </div>
            </section>

            <section v-if="groups.pending.length" class="space-y-3">
                <div>
                    <h2 class="text-lg font-black">{{ $t('admin_settings.devices.pending_requests') }}</h2>
                    <p class="text-sm text-slate-500">Vérifiez l’utilisateur et l’appareil avant de l’autoriser.</p>
                </div>
                <div class="grid gap-3 lg:grid-cols-2">
                    <article v-for="device in groups.pending" :key="device.id" class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <div class="flex gap-3">
                            <Cellphone v-if="device.channel === 'control'" :size="24" class="text-amber-600" />
                            <Laptop v-else :size="24" class="text-amber-600" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-black">{{ device.name || 'Appareil sans nom' }}</h3>
                                    <span v-if="device.requester" class="text-sm text-slate-600 dark:text-slate-400">({{ device.requester.name }})</span>
                                    <span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-black uppercase text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ device.channel }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ device.platform || 'Plateforme inconnue' }} · demande par {{ device.requester?.name || 'Inconnu' }} le {{ formatDate(device.requested_at) }}</p>
                                <p class="mt-1 font-mono text-[11px] text-slate-400">ID {{ device.id }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button class="flex-1 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-black text-white hover:bg-emerald-700 disabled:opacity-50" :disabled="busy[device.id]" @click="updateDevice(device, 'approve')">{{ $t('admin_settings.devices.approve') }}</button>
                            <button class="rounded-xl border border-rose-200 px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-900" :disabled="busy[device.id]" @click="updateDevice(device, 'reject')">{{ $t('admin_settings.devices.reject') }}</button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-black">{{ $t('admin_settings.common.authorized_devices') }}</h2>
                <div v-if="!groups.approved.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">{{ $t('admin_settings.devices.no_approved_device') }}</div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div v-for="device in groups.approved" :key="device.id" class="flex flex-col gap-4 border-b border-slate-100 p-4 last:border-0 md:flex-row md:items-center dark:border-slate-800">
                        <div class="flex min-w-0 flex-1 gap-3">
                            <CheckCircle :size="24" class="shrink-0 text-emerald-500" />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-black">{{ device.name || 'Appareil sans nom' }}</span>
                                    <span v-if="device.requester" class="text-sm text-slate-600 dark:text-slate-400">({{ device.requester.name }})</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black uppercase dark:bg-slate-800">{{ device.channel }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ device.platform || 'Plateforme inconnue' }} · dernière activité {{ formatDate(device.last_seen_at) }}</p>
                                <p class="text-xs text-slate-400">Utilisateur: {{ device.requester?.name || 'Inconnu' }} · Autorisé par {{ device.approver?.name || 'Administrateur' }} · IP {{ device.last_ip || 'inconnue' }}</p>
                            </div>
                        </div>
                        <button class="rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-900" :disabled="busy[device.id]" @click="confirmRevocation(device)">{{ $t('admin_settings.devices.revoke') }}</button>
                    </div>
                </div>
            </section>

            <details v-if="groups.inactive.length" class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <summary class="cursor-pointer font-black">Historique ({{ groups.inactive.length }})</summary>
                <div class="mt-4 space-y-2">
                    <div v-for="device in groups.inactive" :key="device.id" class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <AccountOff :size="20" class="text-slate-400" />
                        <div class="flex-1">
                            <p class="text-sm font-bold">{{ device.name || 'Appareil sans nom' }} <span v-if="device.requester" class="font-normal text-slate-500">({{ device.requester.name }})</span></p>
                            <p class="text-xs text-slate-500">{{ statusLabel[device.status] }} · {{ device.channel }} · Utilisateur: {{ device.requester?.name || 'Inconnu' }} · {{ formatDate(device.revoked_at) }}</p>
                        </div>
                        <button class="text-xs font-black text-emerald-600" :disabled="busy[device.id]" @click="updateDevice(device, 'approve')">{{ $t('admin_settings.devices.reapprove') }}</button>
                    </div>
                </div>
            </details>
                </div>
            </div>
        </div>
    </MainNavLayout>
</template>
