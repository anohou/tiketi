<script setup>
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';

defineProps({ compensations: { type: Array, default: () => [] } });
const approve = async (item) => {
  if (!await confirmationStore.confirm({ title: 'Approuver la compensation', message: `Confirmer l’approbation de la compensation ${item.reference} ?`, confirmLabel: 'Approuver', tone: 'success' })) return;
  await axios.patch(route('seller.compensations.approve', item.id));
  router.reload({ preserveScroll: true });
};
</script>

<template>
  <MainNavLayout title="Compensations">
    <div class="mx-auto max-w-6xl space-y-5 p-6">
      <header><h1 class="text-2xl font-black text-slate-900 dark:text-white">Compensations à valider</h1><p class="text-sm text-slate-500">Demandes dépassant l’autonomie accordée aux vendeurs.</p></header>
      <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-950"><tr><th class="p-4">Référence</th><th>Ticket</th><th>Type</th><th>Motif</th><th>Montant</th><th>Vendeur</th><th></th></tr></thead>
          <tbody><tr v-for="item in compensations" :key="item.id" class="border-t border-slate-100 dark:border-slate-800"><td class="p-4 font-bold">{{ item.reference }}</td><td>{{ item.ticket?.ticket_number }}</td><td>{{ item.compensation_type }}</td><td>{{ item.reason }}</td><td>{{ Number(item.amount).toLocaleString('fr-FR') }} F</td><td>{{ item.requested_by?.name }}</td><td class="p-3 text-right"><button @click="approve(item)" class="rounded-xl bg-emerald-600 px-3 py-2 font-bold text-white">Approuver</button></td></tr>
          <tr v-if="!compensations.length"><td colspan="7" class="p-10 text-center text-slate-500">Aucune compensation en attente.</td></tr></tbody>
        </table>
      </div>
    </div>
  </MainNavLayout>
</template>
