<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import InputError from '@/Components/InputError.vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue';
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue';
import HistoryIcon from 'vue-material-design-icons/History.vue';
import AccountIcon from 'vue-material-design-icons/Account.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import GiftIcon from 'vue-material-design-icons/Gift.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import StarOffIcon from 'vue-material-design-icons/StarOff.vue';
import PhoneIcon from 'vue-material-design-icons/Phone.vue';
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue';
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue';


const props = defineProps({
  settings: Object,
});

const flash = usePage().props.flash ?? {};

// ─── Okohi connect/disconnect ──────────────────────────────────
const processing = ref(false);
const errors = ref({});
const copied = ref(false);
const showConfirm = ref(false);

const isConnected = computed(() => !!props.settings?.okohi_integration_url);
const code = ref('');

const verifyUrl = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  return `${origin}/api/okohi/verify?tenant=…&ticket_id={ticket_id}`;
});

const copyVerifyUrl = () => {
  navigator.clipboard?.writeText(verifyUrl.value).then(() => {
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  });
};

const connect = () => {
  processing.value = true;
  errors.value = {};
  router.post(route('admin.settings.loyalty.connect'), { code: code.value.trim() }, {
    onSuccess: () => { processing.value = false; code.value = ''; },
    onError: (e) => { processing.value = false; errors.value = e; },
  });
};

const confirmDisconnect = () => { showConfirm.value = true; };

const disconnect = () => {
  showConfirm.value = false;
  processing.value = true;
  router.delete(route('admin.settings.loyalty.disconnect'), {
    onSuccess: () => { processing.value = false; code.value = ''; },
    onError: () => { processing.value = false; },
  });
};

// ─── Transactions ──────────────────────────────────────────────
const transactions = ref([]);
const txMeta = ref(null);
const txLoading = ref(false);
const txError = ref(null);
const txPage = ref(1);

const fetchTransactions = async (page = 1) => {
  if (!isConnected.value) return;
  txLoading.value = true;
  txError.value = null;
  try {
    const { data } = await axios.get(route('admin.settings.loyalty.transactions'), {
      params: { page, per_page: 10 },
    });
    transactions.value = data.data?.transactions ?? [];
    txMeta.value = data.data?.meta ?? null;
    txPage.value = page;
  } catch (e) {
    txError.value = e.response?.data?.error ?? 'Impossible de charger l\'historique';
  } finally {
    txLoading.value = false;
  }
};

onMounted(() => { if (isConnected.value) fetchTransactions(1); });
watch(isConnected, (v) => {
  if (v) fetchTransactions(1);
  else { transactions.value = []; txMeta.value = null; }
});

// ─── Reward attribution ────────────────────────────────────────
const rewardSearch = ref('OKH-');
const rewardCustomer = ref(null);
const rewardSearchLoading = ref(false);
const rewardSearchError = ref(null);
const grantingId = ref(null);
const grantSuccess = ref(null);
const grantError = ref(null);

const searchCustomer = async () => {
  const num = rewardSearch.value.trim().toUpperCase();
  if (!num) return;
  rewardSearchLoading.value = true;
  rewardSearchError.value = null;
  rewardCustomer.value = null;
  grantSuccess.value = null;
  grantError.value = null;
  try {
    const { data } = await axios.get(
      route('admin.settings.loyalty.customer', { customerNumber: num })
    );
    rewardCustomer.value = data.data ?? null;
  } catch (e) {
    rewardSearchError.value = e.response?.data?.error ?? 'Erreur lors de la recherche.';
  } finally {
    rewardSearchLoading.value = false;
  }
};

const grantReward = async (rewardId) => {
  const num = rewardCustomer.value?.customer?.customer_number ?? rewardSearch.value.trim().toUpperCase();
  grantingId.value = rewardId;
  grantError.value = null;
  grantSuccess.value = null;
  try {
    await axios.post(
      route('admin.settings.loyalty.grant', { customerNumber: num }),
      { reward_id: rewardId }
    );
    grantSuccess.value = 'La récompense a été envoyée au client. Il doit l\'approuver depuis l\'application Okohi.';
    rewardCustomer.value = null;
    rewardSearch.value = 'OKH-';
  } catch (e) {
    grantError.value = e.response?.data?.error ?? 'Erreur lors de l\'attribution.';
  } finally {
    grantingId.value = null;
  }
};

const loyaltyBalance = (balance) => {
  if (!balance) return '—';
  if (balance.loyalty_type === 'frequency') return `${balance.visits_balance} visite${balance.visits_balance !== 1 ? 's' : ''}`;
  return `${balance.points_balance} point${balance.points_balance !== 1 ? 's' : ''}`;
};

const rewardCost = (reward, loyaltyType) => {
  if (loyaltyType === 'frequency') return reward.cost_in_times != null ? `${reward.cost_in_times} visite${reward.cost_in_times !== 1 ? 's' : ''}` : '—';
  return reward.points_required != null ? `${reward.points_required} point${reward.points_required !== 1 ? 's' : ''}` : '—';
};

const formatDate = (dateStr) => {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
};

const statusLabel = (s) => s === 'confirmed' ? 'Confirmé' : s === 'pending' ? 'En attente' : (s ?? '—');
const statusClass = (s) => s === 'confirmed'
  ? 'bg-green-100 text-green-700 dark:bg-emerald-950/40 dark:text-emerald-300'
  : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950/40 dark:text-yellow-300';
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">

        <!-- Header -->
        <div class="px-6 pt-6 pb-4 shrink-0">
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 dark:bg-emerald-950/40 rounded-xl">
              <GiftOutline class="text-green-600 dark:text-emerald-450" :size="28" />
            </div>
            Fidélisation
          </h1>
          <p class="text-gray-500 dark:text-slate-455 mt-1">Intégration Okohi — récompensez vos clients à chaque voyage</p>
        </div>

        <!-- Body -->
        <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar">
          <div class="grid grid-cols-12 gap-4 px-6 pb-6">

            <!-- Left nav -->
            <div class="hidden md:block md:col-span-2">
              <div class="sticky top-0"><SettingsMenu /></div>
            </div>

            <!-- Mobile nav -->
            <div class="col-span-12 md:hidden"><SettingsMenu /></div>

            <!-- Main content -->
            <div class="col-span-12 md:col-span-10 grid grid-cols-1 md:grid-cols-2 gap-4">

              <!-- ══════ Left column ══════ -->
              <div class="space-y-4">

                <!-- Status banner -->
                <div
                  class="border rounded-xl p-4 flex items-center gap-3"
                  :class="isConnected ? 'bg-green-50 border-green-200 dark:bg-emerald-950/20 dark:border-emerald-800' : 'bg-gray-50 border-gray-200 dark:bg-slate-950/40 dark:border-slate-800'"
                >
                  <CheckCircle v-if="isConnected" class="text-green-500 dark:text-green-400 shrink-0" :size="22" />
                  <AlertCircle v-else class="text-gray-400 dark:text-slate-500 shrink-0" :size="22" />
                  <div>
                    <p class="text-sm font-bold" :class="isConnected ? 'text-green-800 dark:text-emerald-300' : 'text-gray-600 dark:text-slate-300'">
                      {{ isConnected ? 'Intégration active' : 'Non connecté' }}
                    </p>
                    <p v-if="!isConnected" class="text-[11px] text-gray-400 dark:text-slate-500 mt-0.5">
                      Saisissez le code Okohi pour activer la fidélisation
                    </p>
                  </div>
                </div>

                <!-- Flash success -->
                <div v-if="flash.success" class="bg-green-50 border border-green-200 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-300 rounded-xl px-4 py-3 text-sm text-green-700 font-medium">
                  {{ flash.success }}
                </div>

                <!-- Grant success (after attribution, back on main) -->
                <div v-if="grantSuccess" class="flex items-center gap-2 bg-green-50 dark:bg-emerald-950/20 border border-green-200 dark:border-emerald-800 rounded-xl px-4 py-3">
                  <CheckCircleOutline class="text-green-500 shrink-0" :size="18" />
                  <p class="text-sm text-green-700 dark:text-emerald-300 font-medium">{{ grantSuccess }}</p>
                </div>

                <!-- NOT connected: connection form -->
                <div v-if="!isConnected" class="bg-white dark:bg-slate-900 rounded-xl border border-orange-200 dark:border-slate-800 shadow-sm p-5 space-y-4">
                  <p class="text-xs font-bold text-gray-500 dark:text-slate-450 uppercase tracking-wide">Connecter Okohi</p>
                  <div>
                    <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Code de connexion (4 chiffres)</label>
                    <input
                      v-model="code"
                      type="text"
                      inputmode="numeric"
                      maxlength="4"
                      placeholder="1234"
                      class="w-full rounded-lg border-orange-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm font-mono tracking-widest text-center text-lg"
                      :class="{ 'border-red-400': errors.code }"
                    />
                    <InputError class="mt-1" :message="errors.code" />
                    <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">
                      Dans Okohi : <strong>Modification de l'établissement → Intégration API → Apps Partenaires → Connecter</strong>.
                    </p>
                  </div>
                  <button
                    @click="connect"
                    :disabled="processing || code.length !== 4"
                    class="w-full py-3 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-bold rounded-xl transition-colors shadow-lg shadow-green-100 dark:shadow-emerald-950/20 flex items-center justify-center gap-2"
                  >
                    <Loader v-if="processing" :size="20" class="animate-spin" />
                    <LinkVariant v-else :size="20" />
                    {{ processing ? 'Connexion…' : 'Connecter' }}
                  </button>
                </div>

                <!-- CONNECTED: search + config -->
                <template v-if="isConnected">

                  <!-- Search bar — attribuer une récompense -->
                  <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm p-4 space-y-3">
                    <div class="flex items-center gap-2">
                      <GiftIcon class="text-green-600 dark:text-emerald-400" :size="16" />
                      <p class="text-xs font-bold text-gray-600 dark:text-slate-300 uppercase tracking-wide">Attribuer une récompense</p>
                    </div>
                    <div class="flex gap-2">
                      <div class="relative flex-1">
                        <MagnifyIcon class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-500 pointer-events-none" :size="15" />
                        <input
                          v-model="rewardSearch"
                          type="text"
                          placeholder="Numéro client (OKH-123456)"
                          class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500 font-mono"
                          @keydown.enter="searchCustomer"
                          @input="e => { if (!e.target.value.toUpperCase().startsWith('OKH-')) rewardSearch = 'OKH-' }"
                          @keydown="e => { if ((e.key === 'Backspace' || e.key === 'Delete') && rewardSearch.length <= 4) e.preventDefault() }"
                        />
                      </div>
                      <button
                        @click="searchCustomer"
                        :disabled="rewardSearchLoading || !rewardSearch.trim()"
                        class="px-3 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-bold text-xs rounded-lg transition-colors flex items-center gap-1.5 whitespace-nowrap"
                      >
                        <Loader v-if="rewardSearchLoading" :size="14" class="animate-spin" />
                        <MagnifyIcon v-else :size="14" />
                        Rechercher
                      </button>
                    </div>
                    <div v-if="rewardSearchError" class="flex items-center gap-2 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 rounded-lg px-3 py-2">
                      <CloseCircle class="text-red-400 shrink-0" :size="16" />
                      <p class="text-xs text-red-600 dark:text-red-400">{{ rewardSearchError }}</p>
                    </div>
                  </div>

                  <!-- Customer result (inline, replaces the removed sections) -->
                  <template v-if="rewardCustomer">

                    <!-- Customer card -->
                    <div class="bg-gray-50 dark:bg-slate-800/50 rounded-xl p-4 flex flex-wrap items-center gap-3">
                      <div class="w-9 h-9 rounded-full bg-green-100 dark:bg-emerald-950/40 flex items-center justify-center shrink-0">
                        <AccountIcon class="text-green-600 dark:text-emerald-400" :size="20" />
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900 dark:text-slate-100 leading-tight">
                          {{ rewardCustomer.customer.first_name }} {{ rewardCustomer.customer.last_name }}
                        </p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 font-mono">{{ rewardCustomer.customer.customer_number }}</p>
                      </div>
                      <div v-if="rewardCustomer.customer.phone" class="flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400">
                        <PhoneIcon :size="13" />
                        {{ rewardCustomer.customer.phone }}
                      </div>
                      <div class="px-3 py-1 bg-green-100 dark:bg-emerald-950/40 rounded-full">
                        <p class="text-xs font-black text-green-700 dark:text-emerald-300">{{ loyaltyBalance(rewardCustomer.balance) }}</p>
                      </div>
                    </div>

                    <!-- Rewards grid -->
                    <div v-if="rewardCustomer.rewards && rewardCustomer.rewards.length > 0" class="space-y-2">
                      <p class="text-xs font-bold text-gray-500 dark:text-slate-450 uppercase tracking-wide">Récompenses</p>
                      <div class="space-y-2">
                        <div
                          v-for="reward in rewardCustomer.rewards"
                          :key="reward.id"
                          class="bg-white dark:bg-slate-900 border rounded-xl p-3 flex items-center gap-3 shadow-sm"
                          :class="reward.can_grant ? 'border-gray-200 dark:border-slate-700' : 'border-gray-100 dark:border-slate-800 opacity-60'"
                        >
                          <!-- Image/icon -->
                          <div class="w-12 h-12 rounded-lg overflow-hidden bg-gray-50 dark:bg-slate-800 flex items-center justify-center shrink-0">
                            <img v-if="reward.image_url" :src="reward.image_url" :alt="reward.title" class="w-full h-full object-cover" />
                            <GiftIcon v-else class="text-gray-300 dark:text-slate-600" :size="22" />
                          </div>
                          <!-- Info -->
                          <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                              <p class="text-sm font-bold text-gray-900 dark:text-slate-100 leading-tight truncate">{{ reward.title }}</p>
                              <StarIcon v-if="reward.can_grant" class="text-yellow-400 shrink-0" :size="13" />
                              <StarOffIcon v-else class="text-gray-300 dark:text-slate-600 shrink-0" :size="13" />
                            </div>
                            <div class="flex flex-wrap gap-1.5 mt-1">
                              <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold"
                                :class="reward.can_grant ? 'bg-green-100 text-green-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'">
                                {{ rewardCost(reward, rewardCustomer.balance?.loyalty_type) }}
                              </span>
                              <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400">
                                {{ reward.stock != null ? `${reward.stock} en stock` : 'Stock illimité' }}
                              </span>
                              <span v-if="reward.valid_until" class="text-[10px] px-1.5 py-0.5 rounded-full bg-orange-50 dark:bg-orange-950/20 text-orange-600 dark:text-orange-400">
                                Expire le {{ formatDate(reward.valid_until) }}
                              </span>
                            </div>
                          </div>
                          <!-- Action -->
                          <button
                            v-if="reward.can_grant"
                            @click="grantReward(reward.id)"
                            :disabled="grantingId !== null"
                            class="shrink-0 px-3 py-1.5 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-bold text-xs rounded-lg transition-colors flex items-center gap-1"
                          >
                            <Loader v-if="grantingId === reward.id" :size="12" class="animate-spin" />
                            <GiftIcon v-else :size="12" />
                            {{ grantingId === reward.id ? '…' : 'Attribuer' }}
                          </button>
                          <div v-else class="shrink-0 px-3 py-1.5 bg-gray-50 dark:bg-slate-800 rounded-lg text-center">
                            <p class="text-[10px] text-gray-400 dark:text-slate-500 font-medium whitespace-nowrap">Solde insuffisant</p>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-center py-6">
                      <GiftOutline class="text-gray-300 dark:text-slate-700 mx-auto mb-2" :size="28" />
                      <p class="text-xs text-gray-400 dark:text-slate-500">Aucune récompense disponible</p>
                    </div>

                  </template>

                  <!-- How it works (shown when no customer result) -->
                  <div v-if="!rewardCustomer" class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm p-5">
                    <p class="text-xs font-bold text-gray-500 dark:text-slate-455 uppercase tracking-wide mb-4">Comment ça fonctionne</p>
                    <ol class="space-y-4">
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">A</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Le guichetier imprime un ticket → le QR code contient le lien Okohi avec le numéro de ticket, le montant et le timestamp.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">B</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Le client scanne le QR code avec l'application <strong>Okohi</strong>.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">C</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Okohi appelle votre URL de vérification pour confirmer que le ticket est valide.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">D</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Tiketi confirme le ticket → Okohi attribue les points au client.</p>
                      </li>
                    </ol>
                  </div>

                  <!-- Disconnect -->
                  <button
                    @click="confirmDisconnect"
                    :disabled="processing"
                    class="w-full py-2 bg-transparent disabled:opacity-40 text-gray-300 dark:text-slate-700 hover:text-gray-400 dark:hover:text-slate-600 text-xs font-medium rounded-lg transition-colors flex items-center justify-center gap-1.5"
                  >
                    <Loader v-if="processing" :size="14" class="animate-spin" />
                    <Delete v-else :size="14" />
                    {{ processing ? 'Déconnexion…' : 'Déconnecter Okohi' }}
                  </button>

                </template>

              </div>

              <!-- ══════ Right column ══════ -->
              <div class="space-y-4">

                <!-- Not connected: guides -->
                <template v-if="!isConnected">
                  <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm p-5">
                    <p class="text-xs font-bold text-gray-500 dark:text-slate-450 uppercase tracking-wide mb-4">Comment connecter Okohi</p>
                    <ol class="space-y-4">
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-green-100 dark:bg-emerald-950/40 text-green-700 dark:text-emerald-300 font-black text-xs flex items-center justify-center shrink-0">1</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Dans l'app <strong>Okohi</strong>, allez dans <strong>Modification de l'établissement → Intégration API → Apps Partenaires</strong> et cliquez <strong>Connecter</strong> à côté de <em>Tiketi</em>.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-green-100 dark:bg-emerald-950/40 text-green-700 dark:text-emerald-300 font-black text-xs flex items-center justify-center shrink-0">2</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Okohi génère un <strong>code à 4 chiffres</strong> valable 24h. Copiez-le.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-green-100 dark:bg-emerald-950/40 text-green-700 dark:text-emerald-300 font-black text-xs flex items-center justify-center shrink-0">3</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Saisissez le code dans le formulaire ci-contre et cliquez <strong>Connecter</strong>.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-green-100 dark:bg-emerald-950/40 text-green-700 dark:text-emerald-300 font-black text-xs flex items-center justify-center shrink-0">4</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">L'intégration est active. Le QR code sur chaque ticket imprimé permet au client de scanner et gagner des points automatiquement.</p>
                      </li>
                    </ol>
                  </div>
                  <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm p-5">
                    <p class="text-xs font-bold text-gray-500 dark:text-slate-455 uppercase tracking-wide mb-4">Comment ça fonctionne</p>
                    <ol class="space-y-4">
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">A</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Le guichetier imprime un ticket → le QR code contient le lien Okohi avec le numéro de ticket, le montant et le timestamp.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">B</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Le client scanne le QR code avec l'application <strong>Okohi</strong>.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">C</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Okohi appelle votre URL de vérification pour confirmer que le ticket est valide.</p>
                      </li>
                      <li class="flex gap-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 font-black text-xs flex items-center justify-center shrink-0">D</span>
                        <p class="text-xs text-gray-600 dark:text-slate-400 leading-relaxed pt-0.5">Tiketi confirme le ticket → Okohi attribue les points au client.</p>
                      </li>
                    </ol>
                  </div>
                </template>

                <!-- Connected: transaction history -->
                <template v-if="isConnected">
                  <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-slate-800 flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <HistoryIcon class="text-green-600 dark:text-emerald-400" :size="18" />
                        <p class="text-xs font-bold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Historique des fidélisations</p>
                      </div>
                      <button @click="fetchTransactions(txPage)" :disabled="txLoading" class="text-[11px] text-gray-400 hover:text-green-600 dark:text-slate-500 dark:hover:text-emerald-400 transition-colors font-medium">
                        {{ txLoading ? 'Chargement…' : 'Actualiser' }}
                      </button>
                    </div>
                    <div v-if="txLoading" class="flex items-center justify-center py-12">
                      <Loader class="text-green-500 animate-spin" :size="28" />
                    </div>
                    <div v-else-if="txError" class="px-5 py-8 text-center">
                      <AlertCircle class="text-red-400 mx-auto mb-2" :size="28" />
                      <p class="text-sm text-red-500 font-medium">{{ txError }}</p>
                    </div>
                    <div v-else-if="transactions.length === 0" class="px-5 py-10 text-center">
                      <GiftOutline class="text-gray-300 dark:text-slate-700 mx-auto mb-2" :size="36" />
                      <p class="text-sm text-gray-400 dark:text-slate-500">Aucune transaction pour l'instant</p>
                      <p class="text-[11px] text-gray-300 dark:text-slate-600 mt-1">Les transactions apparaîtront ici dès que des clients scanneront leurs QR codes</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                      <table class="w-full text-xs">
                        <thead>
                          <tr class="bg-gray-50 dark:bg-slate-800/50">
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Client</th>
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Ticket</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Montant</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Points</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Visites</th>
                            <th class="text-center px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Statut</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Date</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                          <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-gray-50/60 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="px-4 py-3">
                              <div class="flex items-center gap-2">
                                <AccountIcon class="text-gray-300 dark:text-slate-600 shrink-0" :size="16" />
                                <span class="text-gray-700 dark:text-slate-300 font-medium">
                                  {{ tx.customer ? `${tx.customer.first_name} ${tx.customer.last_name}`.trim() : 'Anonyme' }}
                                </span>
                              </div>
                            </td>
                            <td class="px-4 py-3"><span class="font-mono text-gray-600 dark:text-slate-400">{{ tx.ticket_id ?? '—' }}</span></td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-slate-300 font-medium tabular-nums">
                              {{ tx.amount != null ? Number(tx.amount).toLocaleString('fr-FR') : '—' }} F
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-green-600 dark:text-emerald-400 tabular-nums">{{ tx.points_earned ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-bold text-blue-600 dark:text-blue-400 tabular-nums">{{ tx.visits_earned ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                              <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold" :class="statusClass(tx.status)">
                                {{ statusLabel(tx.status) }}
                              </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-500 tabular-nums whitespace-nowrap">{{ formatDate(tx.transaction_date) }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                    <div v-if="txMeta && txMeta.last_page > 1" class="px-5 py-3 border-t border-gray-100 dark:border-slate-800 flex items-center justify-between">
                      <p class="text-[11px] text-gray-400 dark:text-slate-500">Page {{ txMeta.current_page }} / {{ txMeta.last_page }} &nbsp;·&nbsp; {{ txMeta.total }} transaction{{ txMeta.total > 1 ? 's' : '' }}</p>
                      <div class="flex gap-1.5">
                        <button @click="fetchTransactions(txMeta.current_page - 1)" :disabled="txMeta.current_page <= 1 || txLoading" class="p-1.5 rounded-lg border border-gray-200 dark:border-slate-700 disabled:opacity-40 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                          <ChevronLeft :size="14" class="text-gray-500 dark:text-slate-400" />
                        </button>
                        <button @click="fetchTransactions(txMeta.current_page + 1)" :disabled="txMeta.current_page >= txMeta.last_page || txLoading" class="p-1.5 rounded-lg border border-gray-200 dark:border-slate-700 disabled:opacity-40 hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors">
                          <ChevronRight :size="14" class="text-gray-500 dark:text-slate-400" />
                        </button>
                      </div>
                    </div>
                    <div v-else-if="txMeta && transactions.length > 0" class="px-5 py-3 border-t border-gray-100 dark:border-slate-800">
                      <p class="text-[11px] text-gray-400 dark:text-slate-500">{{ txMeta.total }} transaction{{ txMeta.total > 1 ? 's' : '' }} au total</p>
                    </div>
                  </div>
                </template>

              </div>

            </div>
          </div>
        </div>

    </div>

    <!-- Confirmation modal -->
    <Teleport to="body">
      <div v-if="showConfirm" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/35 backdrop-blur-sm px-4">
        <div class="bg-white/95 dark:bg-slate-900 rounded-3xl border border-white/70 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.16)] p-6 w-full max-w-sm">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 bg-rose-100 dark:bg-rose-950/40 rounded-xl shrink-0">
              <AlertOutline class="text-rose-500" :size="22" />
            </div>
            <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Déconnecter Okohi ?</h3>
          </div>
          <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-6">
            Les QR codes des prochains tickets n'auront plus de lien Okohi. Les tickets déjà imprimés ne sont pas affectés.
          </p>
          <div class="flex gap-3">
            <button @click="showConfirm = false" class="flex-1 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-350 font-bold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Annuler</button>
            <button @click="disconnect" class="flex-1 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-sm transition-colors">Déconnecter</button>
          </div>
        </div>
      </div>
    </Teleport>

  </MainNavLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
