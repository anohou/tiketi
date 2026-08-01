<script setup>
import { computed, ref, reactive, onMounted, watch } from 'vue';
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
import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';


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

onMounted(() => {
  if (isConnected.value) { fetchTransactions(1); fetchRewards(); }
});
watch(isConnected, (v) => {
  if (v) { fetchTransactions(1); fetchRewards(); }
  else {
    transactions.value = []; txMeta.value = null;
    rewards.value = []; rewardsError.value = null;
  }
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

// ─── Rewards catalogue ─────────────────────────────────────────
const rewards = ref([]);
const rewardsLoading = ref(false);
const rewardsError = ref(null);
const showRewardModal = ref(false);
const editingReward = ref(null);
const showDeleteRewardModal = ref(false);
const deletingRewardId = ref(null);
const formProcessing = ref(false);
const formErrors = ref({});
const deleteError = ref(null);

const rewardForm = reactive({
  title: '',
  description: '',
  benefit_type: 'percentage_discount',
  benefit_value: '',
  points_required: '',
  stock: '',
  valid_until: '',
});

const rewardTypeOptions = [
  { value: 'percentage_discount', label: 'Réduction %' },
  { value: 'fixed_discount', label: 'Montant fixe CFA' },
  { value: 'free_ticket', label: 'Billet gratuit' },
];

const deletingReward = computed(() => rewards.value.find((r) => r.id === deletingRewardId.value) ?? null);

const valueLabel = computed(() => {
  switch (rewardForm.benefit_type) {
    case 'percentage_discount': return 'Valeur (en %)';
    case 'fixed_discount': return 'Valeur (en F CFA)';
    default: return 'Valeur';
  }
});

const rewardTypeLabel = (t) => rewardTypeOptions.find((o) => o.value === t)?.label ?? (t ?? '—');

const rewardValue = (r) => {
  if (r.benefit_type === 'percentage_discount') return `${r.benefit_value}%`;
  if (r.benefit_type === 'fixed_discount') return `${Number(r.benefit_value).toLocaleString('fr-FR')} F`;
  if (r.benefit_type === 'free_ticket') return 'Billet gratuit';
  return r.benefit_value != null ? r.benefit_value : '—';
};

const rewardPoints = (r) => (r.points_required != null ? `${r.points_required} point${r.points_required > 1 ? 's' : ''}` : '—');

watch(() => rewardForm.benefit_type, (t) => {
  if (t === 'free_ticket') rewardForm.benefit_value = 100;
});

const fetchRewards = async () => {
  if (!isConnected.value) return;
  rewardsLoading.value = true;
  rewardsError.value = null;
  try {
    const { data } = await axios.get(route('admin.settings.loyalty.rewards.index'));
    rewards.value = data.data?.rewards ?? [];
  } catch (e) {
    rewardsError.value = e.response?.data?.error ?? 'Impossible de charger le catalogue des récompenses.';
  } finally {
    rewardsLoading.value = false;
  }
};

const resetRewardForm = () => {
  rewardForm.title = '';
  rewardForm.description = '';
  rewardForm.benefit_type = 'percentage_discount';
  rewardForm.benefit_value = '';
  rewardForm.points_required = '';
  rewardForm.stock = '';
  rewardForm.valid_until = '';
};

const openCreateReward = () => {
  resetRewardForm();
  editingReward.value = null;
  formErrors.value = {};
  showRewardModal.value = true;
};

const openEditReward = (reward) => {
  resetRewardForm();
  editingReward.value = reward;
  formErrors.value = {};
  rewardForm.title = reward.title ?? '';
  rewardForm.description = reward.description ?? '';
  rewardForm.benefit_type = reward.benefit_type ?? 'percentage_discount';
  rewardForm.benefit_value = reward.benefit_value != null ? reward.benefit_value : '';
  rewardForm.points_required = reward.points_required != null ? reward.points_required : '';
  rewardForm.stock = reward.stock != null ? reward.stock : '';
  rewardForm.valid_until = reward.valid_until ?? '';
  if (rewardForm.benefit_type === 'free_ticket') rewardForm.benefit_value = 100;
  showRewardModal.value = true;
};

const closeRewardModal = () => {
  if (formProcessing.value) return;
  showRewardModal.value = false;
  editingReward.value = null;
  formErrors.value = {};
};

const submitReward = async () => {
  formProcessing.value = true;
  formErrors.value = {};
  const payload = {
    title: rewardForm.title.trim(),
    description: rewardForm.description.trim() || null,
    benefit_type: rewardForm.benefit_type,
    benefit_value: Number(rewardForm.benefit_value),
    points_required: Number(rewardForm.points_required),
    stock: rewardForm.stock === '' ? null : Number(rewardForm.stock),
    valid_until: rewardForm.valid_until || null,
  };
  try {
    if (editingReward.value) {
      await axios.put(route('admin.settings.loyalty.rewards.update', { id: editingReward.value.id }), payload);
    } else {
      await axios.post(route('admin.settings.loyalty.rewards.store'), payload);
    }
    showRewardModal.value = false;
    editingReward.value = null;
    fetchRewards();
  } catch (e) {
    if (e.response?.status === 422) {
      formErrors.value = e.response.data?.errors ?? {};
    } else {
      formErrors.value = { _global: e.response?.data?.error ?? 'Erreur lors de l\'enregistrement.' };
    }
  } finally {
    formProcessing.value = false;
  }
};

const confirmDeleteReward = (reward) => {
  deleteError.value = null;
  deletingRewardId.value = reward.id;
  showDeleteRewardModal.value = true;
};

const closeDeleteRewardModal = () => {
  if (formProcessing.value) return;
  showDeleteRewardModal.value = false;
  deletingRewardId.value = null;
  deleteError.value = null;
};

const deleteReward = async () => {
  if (!deletingRewardId.value) return;
  formProcessing.value = true;
  deleteError.value = null;
  try {
    await axios.delete(route('admin.settings.loyalty.rewards.destroy', { id: deletingRewardId.value }));
    showDeleteRewardModal.value = false;
    deletingRewardId.value = null;
    fetchRewards();
  } catch (e) {
    deleteError.value = e.response?.data?.error ?? 'Erreur lors de la suppression.';
  } finally {
    formProcessing.value = false;
  }
};
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
                <div v-if="!isConnected" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm p-5 space-y-4">
                  <p class="text-xs font-bold text-gray-500 dark:text-slate-450 uppercase tracking-wide">Connecter Okohi</p>
                  <div>
                    <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Code de connexion (4 chiffres)</label>
                    <input
                      v-model="code"
                      type="text"
                      inputmode="numeric"
                      maxlength="4"
                      placeholder="1234"
                      class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm font-mono tracking-widest text-center text-lg"
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

                <!-- Connected: Rewards catalogue (in right column) -->
                <template v-if="isConnected">
                  <div class="bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden">
                    <!-- Header -->
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-slate-800 flex items-center justify-between gap-3 flex-wrap">
                      <div class="flex items-center gap-2.5">
                        <div class="p-1.5 bg-green-100 dark:bg-emerald-950/40 rounded-lg shrink-0">
                          <GiftIcon class="text-green-600 dark:text-emerald-400" :size="18" />
                        </div>
                        <div>
                          <p class="text-xs font-bold text-gray-700 dark:text-slate-300 uppercase tracking-wide">Catalogue des Récompenses</p>
                          <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-0.5">Gérez les récompenses proposées à vos clients fidèles</p>
                        </div>
                      </div>
                      <button
                        @click="openCreateReward"
                        class="shrink-0 px-3 py-2 bg-green-600 hover:bg-green-700 text-white font-bold text-xs rounded-lg transition-colors flex items-center gap-1.5 shadow-sm shadow-green-100 dark:shadow-emerald-950/20"
                      >
                        <PlusIcon :size="14" />
                        Nouvelle Récompense
                      </button>
                    </div>

                    <!-- Loading -->
                    <div v-if="rewardsLoading" class="flex items-center justify-center py-16">
                      <Loader class="text-green-500 animate-spin" :size="28" />
                    </div>

                    <!-- Error -->
                    <div v-else-if="rewardsError" class="px-5 py-12 text-center">
                      <AlertCircle class="text-red-400 mx-auto mb-2" :size="28" />
                      <p class="text-sm text-red-500 font-medium">{{ rewardsError }}</p>
                      <button
                        @click="fetchRewards"
                        :disabled="rewardsLoading"
                        class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 text-red-600 dark:text-red-400 font-bold text-xs rounded-lg hover:bg-red-100 transition-colors disabled:opacity-60"
                      >
                        <Loader v-if="rewardsLoading" :size="12" class="animate-spin" />
                        Réessayer
                      </button>
                    </div>

                    <!-- Empty -->
                    <div v-else-if="rewards.length === 0" class="px-5 py-14 text-center">
                      <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gray-50 dark:bg-slate-800 flex items-center justify-center">
                        <GiftOutline class="text-gray-300 dark:text-slate-600" :size="28" />
                      </div>
                      <p class="text-sm font-bold text-gray-600 dark:text-slate-300">Aucune récompense créée pour le moment</p>
                      <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Créez votre première récompense pour démarrer la fidélisation</p>
                      <button
                        @click="openCreateReward"
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white font-bold text-xs rounded-lg transition-colors"
                      >
                        <PlusIcon :size="14" />
                        Nouvelle Récompense
                      </button>
                    </div>

                    <!-- Table -->
                    <div v-else class="overflow-x-auto">
                      <table class="w-full text-xs">
                        <thead>
                          <tr class="bg-gray-50 dark:bg-slate-800/50">
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Titre</th>
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Type</th>
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Coût</th>
                            <th class="text-left px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Valeur</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Stock</th>
                            <th class="text-center px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Statut</th>
                            <th class="text-right px-4 py-2.5 font-bold text-gray-500 dark:text-slate-400 uppercase tracking-wide text-[10px]">Actions</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-slate-800">
                          <tr v-for="reward in rewards" :key="reward.id" class="hover:bg-gray-50/60 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="px-4 py-3">
                              <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-lg overflow-hidden bg-gray-50 dark:bg-slate-800 flex items-center justify-center shrink-0">
                                  <img v-if="reward.image_url" :src="reward.image_url" :alt="reward.title" class="w-full h-full object-cover" />
                                  <GiftIcon v-else class="text-gray-300 dark:text-slate-600" :size="16" />
                                </div>
                                <div class="min-w-0">
                                  <p class="text-sm font-bold text-gray-900 dark:text-slate-100 truncate">{{ reward.title }}</p>
                                  <p v-if="reward.description" class="text-[11px] text-gray-400 dark:text-slate-500 truncate max-w-[220px]">{{ reward.description }}</p>
                                </div>
                              </div>
                            </td>
                            <td class="px-4 py-3">
                              <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400">
                                {{ rewardTypeLabel(reward.benefit_type) }}
                              </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-300 font-medium tabular-nums whitespace-nowrap">{{ rewardPoints(reward) }}</td>
                            <td class="px-4 py-3 text-gray-700 dark:text-slate-300 font-medium tabular-nums whitespace-nowrap">{{ rewardValue(reward) }}</td>
                            <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-400 tabular-nums">
                              {{ reward.stock != null ? reward.stock : 'Illimité' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                              <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold" :class="reward.is_available ? 'bg-green-100 text-green-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'">
                                {{ reward.is_available ? 'Actif' : 'Inactif' }}
                              </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                              <div class="inline-flex items-center gap-1">
                                <button @click="openEditReward(reward)" title="Éditer" class="p-1.5 rounded-lg text-gray-400 dark:text-slate-500 hover:text-green-600 dark:hover:text-emerald-400 hover:bg-green-50 dark:hover:bg-emerald-950/20 transition-colors">
                                  <PencilIcon :size="15" />
                                </button>
                                <button @click="confirmDeleteReward(reward)" title="Supprimer" class="p-1.5 rounded-lg text-gray-400 dark:text-slate-500 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/20 transition-colors">
                                  <Delete :size="15" />
                                </button>
                              </div>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </template>

              </div>

              <!-- ══════ Full width bottom: Historique ══════ -->
              <template v-if="isConnected">
                <div class="col-span-1 md:col-span-2 bg-white dark:bg-slate-900 rounded-xl border border-gray-100 dark:border-slate-800 shadow-sm overflow-hidden mt-2">
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

    <!-- Reward create/edit modal -->
    <Teleport to="body">
      <div v-if="showRewardModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/35 backdrop-blur-sm px-4 py-8">
        <div class="bg-white/95 dark:bg-slate-900 rounded-3xl border border-white/70 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.16)] p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto custom-scrollbar">
          <div class="flex items-start justify-between gap-3 mb-5">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-green-100 dark:bg-emerald-950/40 rounded-xl shrink-0">
                <GiftIcon class="text-green-600 dark:text-emerald-400" :size="22" />
              </div>
              <div>
                <h3 class="text-base font-black text-slate-900 dark:text-slate-100">{{ editingReward ? 'Modifier la récompense' : 'Nouvelle récompense' }}</h3>
                <p class="text-[11px] text-gray-400 dark:text-slate-500">{{ editingReward ? editingReward.title : 'Ajoutez une récompense au catalogue Okohi' }}</p>
              </div>
            </div>
            <button @click="closeRewardModal" :disabled="formProcessing" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 transition-colors disabled:opacity-50 shrink-0">
              <CloseCircle :size="20" />
            </button>
          </div>

          <form @submit.prevent="submitReward" class="space-y-4">
            <div v-if="formErrors._global" class="flex items-center gap-2 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 rounded-lg px-3 py-2">
              <AlertCircle class="text-red-400 shrink-0" :size="16" />
              <p class="text-xs text-red-600 dark:text-red-400">{{ formErrors._global }}</p>
            </div>

            <div>
              <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Titre <span class="text-rose-400">*</span></label>
              <input
                v-model="rewardForm.title"
                type="text"
                placeholder="Ex : Réduction 20% sur un billet"
                class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                :class="{ 'border-red-400': formErrors.title }"
              />
              <InputError class="mt-1" :message="formErrors.title" />
            </div>

            <div>
              <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Description</label>
              <textarea
                v-model="rewardForm.description"
                rows="2"
                placeholder="Description optionnelle de la récompense"
                class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500 resize-none"
                :class="{ 'border-red-400': formErrors.description }"
              ></textarea>
              <InputError class="mt-1" :message="formErrors.description" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Type de récompense <span class="text-rose-400">*</span></label>
                <select
                  v-model="rewardForm.benefit_type"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.benefit_type }"
                >
                  <option v-for="opt in rewardTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <InputError class="mt-1" :message="formErrors.benefit_type" />
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Points requis <span class="text-rose-400">*</span></label>
                <input
                  v-model="rewardForm.points_required"
                  type="number"
                  min="1"
                  placeholder="100"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.points_required }"
                />
                <InputError class="mt-1" :message="formErrors.points_required" />
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">{{ valueLabel }} <span class="text-rose-400">*</span></label>
                <input
                  v-model="rewardForm.benefit_value"
                  type="number"
                  :min="rewardForm.benefit_type === 'percentage_discount' ? 1 : 0"
                  :max="rewardForm.benefit_type === 'percentage_discount' ? 100 : null"
                  :disabled="rewardForm.benefit_type === 'free_ticket'"
                  :placeholder="rewardForm.benefit_type === 'percentage_discount' ? '20' : rewardForm.benefit_type === 'fixed_discount' ? '1000' : '100'"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.benefit_value, 'opacity-60 cursor-not-allowed': rewardForm.benefit_type === 'free_ticket' }"
                />
                <p v-if="rewardForm.benefit_type === 'free_ticket'" class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">Billet gratuit — valeur fixée à 100%</p>
                <InputError class="mt-1" :message="formErrors.benefit_value" />
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Stock</label>
                <input
                  v-model="rewardForm.stock"
                  type="number"
                  min="0"
                  placeholder="Illimité"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.stock }"
                />
                <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">Laisser vide pour un stock illimité</p>
                <InputError class="mt-1" :message="formErrors.stock" />
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Valable jusqu'au</label>
                <input
                  v-model="rewardForm.valid_until"
                  type="date"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.valid_until }"
                />
                <InputError class="mt-1" :message="formErrors.valid_until" />
              </div>
            </div>

            <div class="flex gap-3 pt-2">
              <button type="button" @click="closeRewardModal" :disabled="formProcessing" class="flex-1 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-350 font-bold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors disabled:opacity-50">Annuler</button>
              <button type="submit" :disabled="formProcessing" class="flex-1 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-bold text-sm transition-colors flex items-center justify-center gap-2">
                <Loader v-if="formProcessing" :size="16" class="animate-spin" />
                {{ editingReward ? 'Enregistrer' : 'Créer la récompense' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Reward delete confirmation modal -->
    <Teleport to="body">
      <div v-if="showDeleteRewardModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/35 backdrop-blur-sm px-4">
        <div class="bg-white/95 dark:bg-slate-900 rounded-3xl border border-white/70 dark:border-slate-800 shadow-[0_24px_70px_rgba(15,23,42,0.16)] p-6 w-full max-w-sm">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 bg-rose-100 dark:bg-rose-950/40 rounded-xl shrink-0">
              <AlertOutline class="text-rose-500" :size="22" />
            </div>
            <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Supprimer la récompense ?</h3>
          </div>
          <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-6">
            <span class="font-bold text-slate-800 dark:text-slate-200">{{ deletingReward?.title }}</span> sera supprimée du catalogue Okohi. Cette action est irréversible.
          </p>
          <div v-if="deleteError" class="mb-4 flex items-center gap-2 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 rounded-lg px-3 py-2">
            <AlertCircle class="text-red-400 shrink-0" :size="16" />
            <p class="text-xs text-red-600 dark:text-red-400">{{ deleteError }}</p>
          </div>
          <div class="flex gap-3">
            <button @click="closeDeleteRewardModal" :disabled="formProcessing" class="flex-1 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-350 font-bold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors disabled:opacity-50">Annuler</button>
            <button @click="deleteReward" :disabled="formProcessing" class="flex-1 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 disabled:opacity-60 text-white font-bold text-sm transition-colors flex items-center justify-center gap-2">
              <Loader v-if="formProcessing" :size="16" class="animate-spin" />
              Supprimer
            </button>
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
