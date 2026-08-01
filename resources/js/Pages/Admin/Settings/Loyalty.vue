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
import GiftIcon from 'vue-material-design-icons/Gift.vue';
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue';
import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';


const props = defineProps({
  settings: Object,
});

const flash = usePage().props.flash ?? {};

// ─── Okohi connect/disconnect ──────────────────────────────────
const processing = ref(false);
const errors = ref({});
const copied = ref(false);
const showConfirm = ref(false);
const showHelp = ref(false);

const isConnected = computed(() => !!props.settings?.okohi_integration_url);
const code = ref('');
const loyaltyParameters = ref(null);
const parametersLoading = ref(false);
const parametersError = ref(null);
const parametersSaving = ref(false);
const parametersSuccess = ref(null);
const parameterFormErrors = ref({});
const parameterForm = reactive({
  loyalty_type: 'points',
  min_transaction_amount: 0,
  points_awarded: 1,
  times_awarded: 1,
});
const loyaltyType = computed(() => loyaltyParameters.value?.loyalty_type === 'frequency' ? 'frequency' : 'points');
const isFrequencyLoyalty = computed(() => loyaltyType.value === 'frequency');
const hydrateParameterForm = (parameters) => {
  if (!parameters) return;
  parameterForm.loyalty_type = parameters.loyalty_type ?? 'points';
  parameterForm.min_transaction_amount = Number(parameters.min_transaction_amount ?? 0);
  parameterForm.points_awarded = Number(parameters.points_awarded ?? 1);
  parameterForm.times_awarded = Number(parameters.times_awarded ?? 1);
};

const fetchLoyaltyParameters = async () => {
  if (!isConnected.value) return;
  parametersLoading.value = true;
  parametersError.value = null;
  try {
    const { data } = await axios.get(route('admin.settings.loyalty.parameters'));
    loyaltyParameters.value = data.data ?? data;
    hydrateParameterForm(loyaltyParameters.value);
  } catch (e) {
    loyaltyParameters.value = null;
    parametersError.value = e.response?.status === 404
      ? 'Aucun mode de fidélité actif n’est défini pour cette entreprise dans Okohi.'
      : (e.response?.data?.error ?? 'Impossible de charger les paramètres de fidélité.');
  } finally {
    parametersLoading.value = false;
  }
};

const parameterPreview = computed(() => {
  const minimum = Number(parameterForm.min_transaction_amount || 0).toLocaleString('fr-FR');

  if (parameterForm.loyalty_type === 'frequency') {
    const visits = Number(parameterForm.times_awarded || 0);
    const threshold = Number(parameterForm.min_transaction_amount || 0) > 0 ? ` d’au moins ${minimum} F CFA` : '';
    return `Chaque voyage${threshold} crédite ${visits} visite${visits > 1 ? 's' : ''}.`;
  }

  const points = Number(parameterForm.points_awarded || 0);
  return `Chaque tranche de ${minimum} F CFA crédite ${points} point${points > 1 ? 's' : ''}.`;
});

const saveLoyaltyParameters = async () => {
  parametersSaving.value = true;
  parametersSuccess.value = null;
  parametersError.value = null;
  parameterFormErrors.value = {};

  const mode = loyaltyParameters.value?.loyalty_type;
  const payload = {
    min_transaction_amount: Number(parameterForm.min_transaction_amount),
    points_awarded: mode === 'points' ? Number(parameterForm.points_awarded) : null,
    times_awarded: mode === 'frequency' ? Number(parameterForm.times_awarded) : null,
  };

  try {
    const { data } = await axios.put(route('admin.settings.loyalty.parameters.update'), payload);
    loyaltyParameters.value = data.data ?? data;
    hydrateParameterForm(loyaltyParameters.value);
    parametersSuccess.value = 'Règle de gain enregistrée dans Okohi.';
  } catch (e) {
    if (e.response?.status === 422) {
      parameterFormErrors.value = e.response.data?.errors ?? {};
    } else {
      parametersError.value = e.response?.data?.error ?? 'Impossible d’enregistrer la règle de gain.';
    }
  } finally {
    parametersSaving.value = false;
  }
};

const parameterFieldError = (field) => {
  const error = parameterFormErrors.value[field];
  return Array.isArray(error) ? error[0] : error;
};

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
  if (isConnected.value) { fetchTransactions(1); fetchLoyaltyParameters(); fetchRewards(); }
});
watch(isConnected, (v) => {
  if (v) { fetchTransactions(1); fetchLoyaltyParameters(); fetchRewards(); }
  else {
    transactions.value = []; txMeta.value = null;
    rewards.value = []; rewardsError.value = null;
    loyaltyParameters.value = null; parametersError.value = null;
  }
});

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
  cost_in_times: '',
  stock: '',
  valid_until: '',
});

const rewardTypeOptions = [
  { value: 'free_ticket', label: 'Billet offert' },
  { value: 'percentage_discount', label: 'Réduction en pourcentage' },
  { value: 'fixed_discount', label: 'Réduction d’un montant fixe' },
];

const deletingReward = computed(() => rewards.value.find((r) => r.id === deletingRewardId.value) ?? null);

const valueLabel = computed(() => {
  switch (rewardForm.benefit_type) {
    case 'percentage_discount': return 'Réduction accordée (%)';
    case 'fixed_discount': return 'Montant de la réduction (F CFA)';
    default: return '';
  }
});

const loyaltyCostLabel = computed(() => isFrequencyLoyalty.value ? 'Visites à utiliser' : 'Points à dépenser');
const earningRule = computed(() => {
  if (!loyaltyParameters.value) return '';

  const minimum = Number(loyaltyParameters.value.min_transaction_amount).toLocaleString('fr-FR');
  if (isFrequencyLoyalty.value) {
    const visits = Number(loyaltyParameters.value.times_awarded ?? 0);
    return `${visits} visite${visits > 1 ? 's' : ''} créditée${visits > 1 ? 's' : ''} par achat d’au moins ${minimum} F CFA.`;
  }

  const points = Number(loyaltyParameters.value.points_awarded ?? 0);
  return `${points} point${points > 1 ? 's' : ''} gagné${points > 1 ? 's' : ''} par tranche de ${minimum} F CFA.`;
});
const loyaltyCostHelp = computed(() => {
  const explanation = isFrequencyLoyalty.value
    ? 'Nombre de visites cumulées qui seront utilisées pour obtenir cette récompense.'
    : 'Nombre de points Okohi qui seront débités du solde du client.';

  return earningRule.value ? `${explanation} Règle actuelle : ${earningRule.value}` : explanation;
});

const rewardPreview = computed(() => {
  const cost = isFrequencyLoyalty.value ? rewardForm.cost_in_times : rewardForm.points_required;
  if (cost === '' || Number(cost) <= 0) return '';

  const costText = isFrequencyLoyalty.value
    ? `${cost} visite${Number(cost) > 1 ? 's' : ''}`
    : `${cost} point${Number(cost) > 1 ? 's' : ''}`;

  if (rewardForm.benefit_type === 'free_ticket') {
    return `Le client utilise ${costText} et reçoit un billet offert.`;
  }

  if (rewardForm.benefit_value === '' || Number(rewardForm.benefit_value) <= 0) return '';

  const benefit = rewardForm.benefit_type === 'percentage_discount'
    ? `${rewardForm.benefit_value} % de réduction`
    : `${Number(rewardForm.benefit_value).toLocaleString('fr-FR')} F CFA de réduction`;

  return `Le client utilise ${costText} et reçoit ${benefit}.`;
});

const rewardTypeLabel = (t) => rewardTypeOptions.find((o) => o.value === t)?.label ?? (t ?? '—');

const rewardValue = (r) => {
  if (r.benefit_type === 'percentage_discount') return `${r.benefit_value}%`;
  if (r.benefit_type === 'fixed_discount') return `${Number(r.benefit_value).toLocaleString('fr-FR')} F`;
  if (r.benefit_type === 'free_ticket') return 'Billet gratuit';
  return r.benefit_value != null ? r.benefit_value : '—';
};

watch(() => rewardForm.benefit_type, (t, previousType) => {
  if (t === 'free_ticket') rewardForm.benefit_value = 100;
  else if (previousType === 'free_ticket') rewardForm.benefit_value = '';
});

const fetchRewards = async () => {
  if (!isConnected.value) return;
  rewardsLoading.value = true;
  rewardsError.value = null;
  try {
    const { data } = await axios.get(route('admin.settings.loyalty.rewards.index'));
    const rewardPayload = data.data?.rewards ?? data.rewards ?? [];
    rewards.value = Array.isArray(rewardPayload) ? rewardPayload : (rewardPayload.data ?? []);
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
  rewardForm.cost_in_times = '';
  rewardForm.stock = '';
  rewardForm.valid_until = '';
};

const openCreateReward = () => {
  if (!loyaltyParameters.value) return;
  resetRewardForm();
  editingReward.value = null;
  formErrors.value = {};
  showRewardModal.value = true;
};

const openEditReward = (reward) => {
  if (!loyaltyParameters.value) return;
  resetRewardForm();
  editingReward.value = reward;
  formErrors.value = {};
  rewardForm.title = reward.title ?? '';
  rewardForm.description = reward.description ?? '';
  rewardForm.benefit_type = reward.benefit_type ?? 'percentage_discount';
  rewardForm.benefit_value = reward.benefit_value != null ? reward.benefit_value : '';
  rewardForm.points_required = reward.points_required != null ? reward.points_required : '';
  rewardForm.cost_in_times = reward.cost_in_times != null ? reward.cost_in_times : '';
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
    stock: rewardForm.stock === '' ? null : Number(rewardForm.stock),
    valid_until: rewardForm.valid_until || null,
  };
  if (isFrequencyLoyalty.value) {
    payload.cost_in_times = Number(rewardForm.cost_in_times);
  } else {
    payload.points_required = Number(rewardForm.points_required);
  }
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
        <div class="flex shrink-0 items-start justify-between gap-4 px-6 pb-4 pt-6">
          <div>
            <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
              <div class="p-2 bg-green-100 dark:bg-emerald-950/40 rounded-xl">
                <GiftOutline class="text-green-600 dark:text-emerald-450" :size="28" />
              </div>
              Fidélisation
            </h1>
            <p class="text-gray-500 dark:text-slate-455 mt-1">Intégration Okohi — récompensez vos clients à chaque voyage</p>
          </div>
          <button @click="showHelp = true" type="button" class="mt-1 inline-flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 shadow-sm transition-colors hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-blue-900 dark:hover:bg-blue-950/20 dark:hover:text-blue-300">
            <HelpCircleOutline :size="18" />
            Comment ça fonctionne
          </button>
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

                <!-- Step 1: loyalty earning rule -->
                <div v-if="isConnected" class="overflow-hidden rounded-xl border border-blue-200 bg-white shadow-sm dark:border-blue-900/40 dark:bg-slate-900">
                  <div class="border-b border-blue-100 bg-blue-50 px-5 py-4 dark:border-blue-900/30 dark:bg-blue-950/20">
                    <div class="flex items-start gap-3">
                      <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-black text-white">1</span>
                      <div>
                        <p class="text-sm font-black text-blue-900 dark:text-blue-200">Comment le client gagne sa fidélité</p>
                        <p class="mt-0.5 text-[11px] leading-relaxed text-blue-600 dark:text-blue-400">Cette règle est appliquée par Okohi quand un billet Tiketi est validé.</p>
                      </div>
                    </div>
                  </div>

                  <div v-if="parametersLoading" class="flex items-center justify-center gap-2 px-5 py-10 text-xs font-medium text-blue-600 dark:text-blue-400">
                    <Loader :size="16" class="animate-spin" />
                    Chargement de la règle Okohi…
                  </div>

                  <div v-else-if="!loyaltyParameters" class="p-5">
                    <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-3 dark:border-red-900/30 dark:bg-red-950/20">
                      <AlertCircle :size="17" class="mt-0.5 shrink-0 text-red-400" />
                      <div>
                        <p class="text-xs font-bold text-red-700 dark:text-red-400">Mode de fidélité indisponible</p>
                        <p class="mt-1 text-[11px] leading-relaxed text-red-600 dark:text-red-400/80">{{ parametersError }}</p>
                        <p class="mt-1 text-[11px] text-red-600 dark:text-red-400/80">Définissez d’abord le mode Points ou Visites dans Okohi, puis rechargez cette page.</p>
                      </div>
                    </div>
                  </div>

                  <form v-else class="space-y-4 p-5" @submit.prevent="saveLoyaltyParameters">
                    <div v-if="parametersError" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900/30 dark:bg-red-950/20">
                      <AlertCircle :size="16" class="mt-0.5 shrink-0 text-red-400" />
                      <p class="text-xs text-red-600 dark:text-red-400">{{ parametersError }}</p>
                    </div>
                    <div v-if="parametersSuccess" class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                      <CheckCircle :size="16" class="shrink-0 text-green-500" />
                      <p class="text-xs font-medium text-green-700 dark:text-emerald-300">{{ parametersSuccess }}</p>
                    </div>

                    <div>
                      <p class="mb-1 text-xs font-bold text-gray-700 dark:text-slate-300">Mode défini dans Okohi</p>
                      <div class="flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 px-3.5 py-3 text-blue-800 dark:border-blue-900/40 dark:bg-blue-950/20 dark:text-blue-300">
                        <CheckCircle :size="18" class="shrink-0 text-blue-600 dark:text-blue-400" />
                        <div>
                          <p class="text-xs font-black">{{ isFrequencyLoyalty ? 'Fidélité par visites' : 'Fidélité par points' }}</p>
                          <p class="mt-0.5 text-[10px] leading-snug text-blue-600 dark:text-blue-400">{{ isFrequencyLoyalty ? 'Tiketi configure le seuil et le nombre de visites créditées.' : 'Tiketi configure la manière dont les points sont calculés.' }}</p>
                        </div>
                      </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <div>
                        <label class="mb-1 block text-xs font-bold text-gray-700 dark:text-slate-300">{{ isFrequencyLoyalty ? 'Prix minimum du voyage' : 'Montant d’une tranche' }}</label>
                        <div class="relative">
                          <input v-model="parameterForm.min_transaction_amount" type="number" :min="isFrequencyLoyalty ? 0 : 1" class="w-full rounded-lg border-gray-200 pr-16 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                          <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-gray-400">F CFA</span>
                        </div>
                        <p class="mt-1 text-[10px] leading-snug text-gray-400 dark:text-slate-500">{{ isFrequencyLoyalty ? '0 signifie que tous les voyages sont admissibles.' : 'Le montant du billet est divisé en tranches pour calculer les points.' }}</p>
                        <InputError class="mt-1" :message="parameterFieldError('min_transaction_amount')" />
                      </div>

                      <div v-if="parameterForm.loyalty_type === 'points'">
                        <label class="mb-1 block text-xs font-bold text-gray-700 dark:text-slate-300">Points crédités par tranche</label>
                        <input v-model="parameterForm.points_awarded" type="number" min="1" class="w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <InputError class="mt-1" :message="parameterFieldError('points_awarded')" />
                      </div>

                      <div v-else>
                        <label class="mb-1 block text-xs font-bold text-gray-700 dark:text-slate-300">Visites créditées par voyage</label>
                        <input v-model="parameterForm.times_awarded" type="number" min="1" class="w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <InputError class="mt-1" :message="parameterFieldError('times_awarded')" />
                      </div>
                    </div>

                    <div class="rounded-xl border border-blue-200 bg-blue-50 px-3.5 py-3 dark:border-blue-900/40 dark:bg-blue-950/20">
                      <p class="text-[10px] font-bold uppercase tracking-wide text-blue-500">Règle appliquée</p>
                      <p class="mt-1 text-xs font-bold text-blue-800 dark:text-blue-300">{{ parameterPreview }}</p>
                    </div>

                    <button type="submit" :disabled="parametersSaving" class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 py-2.5 text-sm font-bold text-white transition-colors hover:bg-blue-700 disabled:opacity-60">
                      <Loader v-if="parametersSaving" :size="16" class="animate-spin" />
                      {{ parametersSaving ? 'Enregistrement…' : 'Enregistrer la règle' }}
                    </button>
                  </form>
                </div>

                <!-- Flash success -->
                <div v-if="flash.success" class="bg-green-50 border border-green-200 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-300 rounded-xl px-4 py-3 text-sm text-green-700 font-medium">
                  {{ flash.success }}
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

                <!-- CONNECTED: information + disconnect -->
                <template v-if="isConnected">
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
                          <p class="text-xs font-bold text-gray-700 dark:text-slate-300 uppercase tracking-wide">2. Échanger la fidélité contre des récompenses</p>
                          <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-0.5">Définissez combien de points ou visites donnent droit à chaque avantage</p>
                          <p v-if="loyaltyParameters" class="mt-1 text-[10px] font-medium text-blue-600 dark:text-blue-400">Gain actuel : {{ earningRule }}</p>
                        </div>
                      </div>
                      <button
                        @click="openCreateReward"
                        :disabled="parametersLoading || !loyaltyParameters"
                        class="shrink-0 px-3 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold text-xs rounded-lg transition-colors flex items-center gap-1.5 shadow-sm shadow-green-100 dark:shadow-emerald-950/20"
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
                        :disabled="parametersLoading || !loyaltyParameters"
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold text-xs rounded-lg transition-colors"
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
                            <td class="px-4 py-3 text-gray-600 dark:text-slate-300 font-medium tabular-nums whitespace-nowrap">{{ rewardCost(reward, loyaltyType) }}</td>
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
                                <button @click="openEditReward(reward)" :disabled="!loyaltyParameters" title="Éditer" class="p-1.5 rounded-lg text-gray-400 dark:text-slate-500 hover:text-green-600 dark:hover:text-emerald-400 hover:bg-green-50 dark:hover:bg-emerald-950/20 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
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

    <!-- Loyalty help modal -->
    <Teleport to="body">
      <div v-if="showHelp" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/35 px-4 backdrop-blur-sm" @click.self="showHelp = false">
        <div class="w-full max-w-lg rounded-3xl border border-white/70 bg-white/95 p-6 shadow-[0_24px_70px_rgba(15,23,42,0.16)] dark:border-slate-800 dark:bg-slate-900">
          <div class="mb-5 flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              <div class="shrink-0 rounded-xl bg-blue-100 p-2 dark:bg-blue-950/40">
                <HelpCircleOutline class="text-blue-600 dark:text-blue-400" :size="22" />
              </div>
              <div>
                <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Comment ça fonctionne</h3>
                <p class="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">Parcours de fidélisation Tiketi → Okohi</p>
              </div>
            </div>
            <button type="button" @click="showHelp = false" class="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300">
              <CloseCircle :size="20" />
            </button>
          </div>

          <ol class="space-y-3">
            <li class="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">1</span>
              <p class="pt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300"><strong>Tiketi vend et imprime le billet.</strong> Son QR code contient les informations nécessaires à la fidélisation.</p>
            </li>
            <li class="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">2</span>
              <p class="pt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300"><strong>Le client scanne le QR code dans Okohi.</strong> Okohi demande alors à Tiketi de vérifier le billet.</p>
            </li>
            <li class="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">3</span>
              <p class="pt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300"><strong>Tiketi confirme le billet.</strong> Okohi applique la règle de gain définie pour l’entreprise.</p>
            </li>
            <li class="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-green-100 text-xs font-black text-green-700 dark:bg-emerald-950/40 dark:text-emerald-300">4</span>
              <p class="pt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300"><strong>Le client reçoit ses points ou sa visite.</strong> Il pourra ensuite les échanger contre les récompenses du catalogue.</p>
            </li>
          </ol>

          <button type="button" @click="showHelp = false" class="mt-5 w-full rounded-xl bg-blue-600 py-2.5 text-sm font-bold text-white transition-colors hover:bg-blue-700">J’ai compris</button>
        </div>
      </div>
    </Teleport>

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
                <p class="text-[11px] text-gray-400 dark:text-slate-500">{{ editingReward ? editingReward.title : 'Définissez ce que le client dépense et ce qu’il reçoit' }}</p>
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

            <div class="flex items-start gap-2.5 rounded-xl border border-blue-200 bg-blue-50 px-3.5 py-3 dark:border-blue-900/40 dark:bg-blue-950/20">
              <TagIcon :size="17" class="mt-0.5 shrink-0 text-blue-600 dark:text-blue-400" />
              <div>
                <p class="text-xs font-bold text-blue-800 dark:text-blue-300">Cette récompense sera enregistrée dans Okohi</p>
                <p class="mt-0.5 text-[11px] leading-relaxed text-blue-600 dark:text-blue-400">Choisissez l’avantage reçu par le client, puis le coût prélevé sur son solde fidélité.</p>
              </div>
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
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Avantage offert <span class="text-rose-400">*</span></label>
                <select
                  v-model="rewardForm.benefit_type"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.benefit_type }"
                >
                  <option v-for="opt in rewardTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <InputError class="mt-1" :message="formErrors.benefit_type" />
              </div>

              <div v-if="rewardForm.benefit_type !== 'free_ticket'">
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">{{ valueLabel }} <span class="text-rose-400">*</span></label>
                <input
                  v-model="rewardForm.benefit_value"
                  type="number"
                  :min="rewardForm.benefit_type === 'percentage_discount' ? 1 : 0"
                  :max="rewardForm.benefit_type === 'percentage_discount' ? 100 : null"
                  :placeholder="rewardForm.benefit_type === 'percentage_discount' ? '20' : '1000'"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.benefit_value }"
                />
                <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">{{ rewardForm.benefit_type === 'percentage_discount' ? 'Exemple : 20 signifie 20 % de réduction.' : 'Montant retiré du prix du billet.' }}</p>
                <InputError class="mt-1" :message="formErrors.benefit_value" />
              </div>

              <div v-else class="rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                <p class="text-xs font-bold text-green-700 dark:text-emerald-300">Avantage : un billet offert</p>
                <p class="mt-1 text-[11px] leading-relaxed text-green-600 dark:text-emerald-400">Aucun montant à saisir : Okohi applique automatiquement une prise en charge de 100 %.</p>
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">{{ loyaltyCostLabel }} <span class="text-rose-400">*</span></label>
                <input
                  v-if="isFrequencyLoyalty"
                  v-model="rewardForm.cost_in_times"
                  type="number"
                  min="1"
                  placeholder="5"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.cost_in_times }"
                />
                <input
                  v-else
                  v-model="rewardForm.points_required"
                  type="number"
                  min="1"
                  placeholder="100"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.points_required }"
                />
                <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">{{ loyaltyCostHelp }}</p>
                <InputError class="mt-1" :message="isFrequencyLoyalty ? formErrors.cost_in_times : formErrors.points_required" />
              </div>

              <div>
                <label class="block text-xs font-bold text-gray-600 dark:text-slate-300 mb-1">Nombre de récompenses disponibles</label>
                <input
                  v-model="rewardForm.stock"
                  type="number"
                  min="0"
                  placeholder="Illimité"
                  class="w-full rounded-lg border border-gray-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
                  :class="{ 'border-red-400': formErrors.stock }"
                />
                <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">Chaque attribution consomme une unité. Laissez vide pour un nombre illimité.</p>
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
                <p class="text-[11px] text-gray-400 dark:text-slate-500 mt-1">Laissez vide si la récompense n’expire pas.</p>
                <InputError class="mt-1" :message="formErrors.valid_until" />
              </div>

              <div v-if="rewardPreview" class="sm:col-span-2 rounded-xl border border-green-200 bg-green-50 px-3.5 py-3 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                <p class="text-[10px] font-bold uppercase tracking-wide text-green-600 dark:text-emerald-400">Résumé</p>
                <p class="mt-1 text-xs font-semibold text-green-800 dark:text-emerald-300">{{ rewardPreview }}</p>
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
