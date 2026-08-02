<script setup>
import { computed } from 'vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue';
import TagHeart from 'vue-material-design-icons/TagHeart.vue';
import InfoOutline from 'vue-material-design-icons/InformationOutline.vue';

const props = defineProps({
  loyalty: {
    type: Object,
    default: () => ({ connected: false, parameters: null, rewards: [], error: null }),
  },
});

const parameters = computed(() => {
  const p = props.loyalty.parameters;
  if (!p || typeof p !== 'object') return null;
  if (Array.isArray(p)) return p[0] || null;
  return p;
});

const rewardList = computed(() => {
  const rewards = props.loyalty.rewards;
  if (!Array.isArray(rewards)) return [];
  return rewards;
});
</script>

<template>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 dark:bg-slate-900 dark:border-slate-800">
    <div class="flex items-center gap-3 mb-5">
      <div class="p-2 bg-emerald-100 rounded-xl dark:bg-emerald-900/25">
        <GiftOutline class="text-emerald-600 dark:text-emerald-400" :size="22" />
      </div>
      <div>
        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Fidélisation (Okohi)</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">Programme de récompenses sur vos billets</p>
      </div>
      <span
        :class="[
          'ml-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold shrink-0',
          loyalty.connected
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
        ]"
      >
        <span :class="['w-1.5 h-1.5 rounded-full', loyalty.connected ? 'bg-emerald-500' : 'bg-slate-400']" />
        {{ loyalty.connected ? 'Programme connecté' : 'Non connecté' }}
      </span>
    </div>

    <div v-if="loyalty.error" class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
      <AlertCircleOutline :size="18" class="text-amber-600 shrink-0 mt-0.5 dark:text-amber-400" />
      <p class="text-xs font-medium text-amber-800 dark:text-amber-300">{{ loyalty.error }}</p>
    </div>

    <template v-if="!loyalty.connected">
      <div class="flex flex-col items-center justify-center py-8 text-center">
        <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 dark:bg-slate-800">
          <GiftOutline :size="36" />
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1 dark:text-slate-100">Programme non actif</h3>
        <p class="text-xs text-slate-500 max-w-sm leading-relaxed dark:text-slate-400">
          Le programme de fidélité Okohi n'est pas configuré pour votre compagnie. Seul l'administrateur peut l'activer.
        </p>
      </div>
    </template>

    <template v-else>
      <template v-if="parameters">
        <div class="mb-5 grid gap-4 md:grid-cols-2">
          <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
            <div class="flex items-center gap-2 mb-2">
              <InfoOutline :size="18" class="text-emerald-600 dark:text-emerald-400" />
              <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Règle de gain</h3>
            </div>
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">
              {{ parameters.label || parameters.name || 'Gain de récompenses par billet' }}
            </p>
            <p v-if="parameters.description" class="text-xs text-slate-500 mt-1 leading-relaxed dark:text-slate-400">{{ parameters.description }}</p>
            <div v-if="parameters.rate_per_point || parameters.points_per_amount" class="mt-2 text-xs text-slate-600 dark:text-slate-300">
              <template v-if="parameters.rate_per_point">
                Taux : <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ parameters.rate_per_point }}</span>
              </template>
              <template v-if="parameters.points_per_amount">
                Points : <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ parameters.points_per_amount }}</span>
              </template>
            </div>
          </div>

          <div v-if="parameters.catalog_rule || parameters.minimum_spend || parameters.expiry_days" class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Conditions</h3>
            <div class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
              <div v-if="parameters.catalog_rule" class="flex items-center justify-between">
                <span>Règle du catalogue</span>
                <span class="font-bold text-slate-800 dark:text-slate-200">{{ parameters.catalog_rule }}</span>
              </div>
              <div v-if="parameters.minimum_spend" class="flex items-center justify-between">
                <span>Achat minimum</span>
                <span class="font-bold text-slate-800 dark:text-slate-200">{{ parameters.minimum_spend }}</span>
              </div>
              <div v-if="parameters.expiry_days" class="flex items-center justify-between">
                <span>Expiration</span>
                <span class="font-bold text-slate-800 dark:text-slate-200">{{ parameters.expiry_days }} jours</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <template v-if="rewardList.length">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Récompenses disponibles ({{ rewardList.length }})</h3>
        <div class="grid gap-3 md:grid-cols-2">
          <div
            v-for="reward in rewardList"
            :key="reward.id || reward.name || reward"
            class="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950/40"
          >
            <div class="p-1.5 bg-white rounded-lg shrink-0 dark:bg-slate-900">
              <TagHeart :size="18" class="text-emerald-600 dark:text-emerald-400" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="text-sm font-bold text-slate-800 dark:text-slate-200">{{ reward.label || reward.name || reward }}</div>
              <div v-if="reward.description" class="text-xs text-slate-500 mt-0.5 leading-relaxed dark:text-slate-400">{{ reward.description }}</div>
              <div v-if="reward.points" class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                <CheckCircle :size="12" />
                {{ reward.points }} points
              </div>
            </div>
          </div>
        </div>
      </template>

      <div v-else-if="!loyalty.error" class="flex flex-col items-center justify-center py-8 text-center">
        <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 dark:bg-slate-800">
          <GiftOutline :size="36" />
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1 dark:text-slate-100">Aucune récompense affichée</h3>
        <p class="text-xs text-slate-500 max-w-sm leading-relaxed dark:text-slate-400">
          Le catalogue des récompenses n'est pas disponible pour le moment.
        </p>
      </div>
    </template>
  </div>
</template>
