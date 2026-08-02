<script setup>
import { computed } from 'vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Phone from 'vue-material-design-icons/Phone.vue';
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue';
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import InfoOutline from 'vue-material-design-icons/InformationOutline.vue';

const props = defineProps({
  company: {
    type: Object,
    default: () => ({}),
  },
});

const rows = computed(() => [
  { label: 'Nom de l’entreprise', value: props.company.name || '—', icon: OfficeBuilding },
  { label: 'Email de contact', value: props.company.email || '—', icon: EmailOutline },
  { label: 'Téléphone', value: props.company.phone || '—', icon: Phone },
  { label: 'Devise', value: props.company.currency || 'F CFA', icon: CurrencyUsd },
  { label: 'Fuseau horaire', value: props.company.timezone || 'UTC', icon: Clock },
]);
</script>

<template>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 dark:bg-slate-900 dark:border-slate-800">
    <div class="flex items-center gap-3 mb-5">
      <div class="p-2 bg-emerald-100 rounded-xl dark:bg-emerald-900/25">
        <OfficeBuilding class="text-emerald-600 dark:text-emerald-400" :size="22" />
      </div>
      <div>
        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Informations générales</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">Identité de votre compagnie de transport</p>
      </div>
    </div>

    <div v-if="company.logo_url" class="mb-5">
      <img :src="company.logo_url" :alt="company.name || 'Logo'" class="h-14 w-auto object-contain" />
    </div>

    <div class="space-y-3">
      <div v-for="row in rows" :key="row.label" class="flex items-start gap-3">
        <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
          <component :is="row.icon" :size="16" class="text-slate-500 dark:text-slate-400" />
        </div>
        <div class="min-w-0">
          <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ row.label }}</div>
          <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">{{ row.value }}</div>
        </div>
      </div>
    </div>

    <template v-if="company.support && (company.support.email || company.support.phone_numbers?.length)">
      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-6 mb-3 dark:text-slate-500">Assistance</h3>
      <div class="space-y-3">
        <div v-if="company.support.email" class="flex items-start gap-3">
          <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
            <EmailOutline :size="16" class="text-emerald-600 dark:text-emerald-400" />
          </div>
          <div class="min-w-0">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Email</div>
            <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">{{ company.support.email }}</div>
          </div>
        </div>
        <div v-if="company.support.phone_numbers?.length" class="flex items-start gap-3">
          <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
            <Phone :size="16" class="text-emerald-600 dark:text-emerald-400" />
          </div>
          <div class="min-w-0">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Numéros</div>
            <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">
              <span v-for="(number, index) in company.support.phone_numbers" :key="index">
                {{ number }}<span v-if="index < company.support.phone_numbers.length - 1">, </span>
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <template v-if="company.policies">
      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-6 mb-3 dark:text-slate-500">Politiques</h3>
      <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 space-y-3 dark:bg-slate-950/40 dark:border-slate-800">
        <div class="flex items-start gap-3">
          <InfoOutline :size="18" class="text-slate-400 shrink-0 mt-0.5" />
          <div>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Vente</p>
            <p class="text-xs text-slate-500 mt-0.5 dark:text-slate-400">{{ company.policies.sale || '—' }}</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <InfoOutline :size="18" class="text-slate-400 shrink-0 mt-0.5" />
          <div>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Annulation</p>
            <p class="text-xs text-slate-500 mt-0.5 dark:text-slate-400">{{ company.policies.cancellation || '—' }}</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <InfoOutline :size="18" class="text-slate-400 shrink-0 mt-0.5" />
          <div>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Bagages</p>
            <p class="text-xs text-slate-500 mt-0.5 dark:text-slate-400">{{ company.policies.baggage || '—' }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
