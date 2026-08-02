<script setup>
import { computed, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue';
import Laptop from 'vue-material-design-icons/Laptop.vue';
import Cellphone from 'vue-material-design-icons/Cellphone.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AccountOff from 'vue-material-design-icons/AccountOff.vue';

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
    <MainNavLayout>
        <Head title="Appareils autorisés" />

        <div class="mx-auto w-full max-w-7xl space-y-6 px-4 text-slate-900 dark:text-slate-100">
            <header class="flex items-start gap-4">
                <div class="rounded-2xl bg-emerald-100 p-3 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    <ShieldLock :size="28" />
                </div>
                <div>
                    <h1 class="text-3xl font-black">Appareils autorisés</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Limitez l’accès aux terminaux explicitement approuvés par votre entreprise.
                    </p>
                </div>
            </header>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="font-black">Restrictions du tenant</h2>
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
                            <span class="block font-bold">TIKETI Web</span>
                            <span class="text-xs text-slate-500">Back-office, vente, supervision et rapports</span>
                        </span>
                        <input v-model="restrictionForm.web" type="checkbox" class="h-5 w-5 rounded text-emerald-600 focus:ring-emerald-500">
                    </label>
                    <label class="flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <span>
                            <span class="block font-bold">TIKETI Control</span>
                            <span class="text-xs text-slate-500">Téléphones et tablettes des équipages</span>
                        </span>
                        <input v-model="restrictionForm.control" type="checkbox" class="h-5 w-5 rounded text-emerald-600 focus:ring-emerald-500">
                    </label>
                </div>
            </section>

            <section v-if="groups.pending.length" class="space-y-3">
                <div>
                    <h2 class="text-lg font-black">Demandes en attente</h2>
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
                                    <span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-black uppercase text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ device.channel }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ device.platform || 'Plateforme inconnue' }} · demande {{ formatDate(device.requested_at) }}</p>
                                <p class="mt-1 font-mono text-[11px] text-slate-400">ID {{ device.id }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button class="flex-1 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-black text-white hover:bg-emerald-700 disabled:opacity-50" :disabled="busy[device.id]" @click="updateDevice(device, 'approve')">Autoriser</button>
                            <button class="rounded-xl border border-rose-200 px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-900" :disabled="busy[device.id]" @click="updateDevice(device, 'reject')">Refuser</button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-black">Appareils autorisés</h2>
                <div v-if="!groups.approved.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
                    Aucun appareil autorisé.
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div v-for="device in groups.approved" :key="device.id" class="flex flex-col gap-4 border-b border-slate-100 p-4 last:border-0 md:flex-row md:items-center dark:border-slate-800">
                        <div class="flex min-w-0 flex-1 gap-3">
                            <CheckCircle :size="24" class="shrink-0 text-emerald-500" />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-black">{{ device.name || 'Appareil sans nom' }}</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black uppercase dark:bg-slate-800">{{ device.channel }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ device.platform || 'Plateforme inconnue' }} · dernière activité {{ formatDate(device.last_seen_at) }}</p>
                                <p class="text-xs text-slate-400">Autorisé par {{ device.approver?.name || 'Administrateur' }} · IP {{ device.last_ip || 'inconnue' }}</p>
                            </div>
                        </div>
                        <button class="rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-900" :disabled="busy[device.id]" @click="updateDevice(device, 'revoke')">Révoquer</button>
                    </div>
                </div>
            </section>

            <details v-if="groups.inactive.length" class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <summary class="cursor-pointer font-black">Historique ({{ groups.inactive.length }})</summary>
                <div class="mt-4 space-y-2">
                    <div v-for="device in groups.inactive" :key="device.id" class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <AccountOff :size="20" class="text-slate-400" />
                        <div class="flex-1">
                            <p class="text-sm font-bold">{{ device.name || 'Appareil sans nom' }}</p>
                            <p class="text-xs text-slate-500">{{ statusLabel[device.status] }} · {{ device.channel }} · {{ formatDate(device.revoked_at) }}</p>
                        </div>
                        <button class="text-xs font-black text-emerald-600" :disabled="busy[device.id]" @click="updateDevice(device, 'approve')">Réautoriser</button>
                    </div>
                </div>
            </details>
        </div>
    </MainNavLayout>
</template>
