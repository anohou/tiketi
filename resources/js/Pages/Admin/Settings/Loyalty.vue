<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import InputError from '@/Components/InputError.vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Check from 'vue-material-design-icons/Check.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue';

const props = defineProps({
  settings: Object,
});

const flash = usePage().props.flash ?? {};

const processing = ref(false);
const errors = ref({});
const copied = ref(false);

const isConnected = computed(() => !!props.settings?.okohi_integration_url);

const okohiBaseUrl = ref(props.settings?.okohi_base_url ?? '');
const code = ref('');

const verifyUrl = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  return `${origin}/api/okohi/verify`;
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
  router.post(route('admin.settings.loyalty.connect'), {
    okohi_base_url: okohiBaseUrl.value.trim(),
    code: code.value.trim(),
  }, {
    onSuccess: () => { processing.value = false; code.value = ''; },
    onError: (e) => { processing.value = false; errors.value = e; },
  });
};

const disconnect = () => {
  processing.value = true;
  router.delete(route('admin.settings.loyalty.disconnect'), {
    onSuccess: () => { processing.value = false; okohiBaseUrl.value = ''; code.value = ''; },
    onError: () => { processing.value = false; },
  });
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">

      <!-- Header -->
      <div class="px-6 pt-6 pb-4 shrink-0">
        <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
          <div class="p-2 bg-green-100 rounded-xl">
            <GiftOutline class="text-green-600" :size="28" />
          </div>
          Fidélisation
        </h1>
        <p class="text-gray-500 mt-1">Intégration Okohi — récompensez vos clients à chaque voyage</p>
      </div>

      <!-- Body -->
      <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar">
        <div class="grid grid-cols-12 gap-4 px-6 pb-6">

          <!-- Left nav -->
          <div class="hidden md:block md:col-span-2">
            <div class="sticky top-0">
              <SettingsMenu />
            </div>
          </div>

          <!-- Mobile nav -->
          <div class="col-span-12 md:hidden">
            <SettingsMenu />
          </div>

          <!-- Main content -->
          <div class="col-span-12 md:col-span-10 grid grid-cols-1 md:grid-cols-2 gap-4">

            <!-- Left column -->
            <div class="space-y-4">

              <!-- Status banner -->
              <div
                :class="isConnected
                  ? 'bg-green-50 border-green-200'
                  : 'bg-gray-50 border-gray-200'"
                class="border rounded-xl p-4 flex items-center gap-3"
              >
                <CheckCircle v-if="isConnected" class="text-green-500 shrink-0" :size="22" />
                <AlertCircle v-else class="text-gray-400 shrink-0" :size="22" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-bold" :class="isConnected ? 'text-green-800' : 'text-gray-600'">
                    {{ isConnected ? 'Intégration active' : 'Non connecté' }}
                  </p>
                  <p v-if="isConnected" class="text-[11px] text-green-600 truncate mt-0.5">
                    {{ settings.okohi_integration_url }}
                  </p>
                  <p v-else class="text-[11px] text-gray-400 mt-0.5">
                    Saisissez le code Okohi pour activer la fidélisation
                  </p>
                </div>
              </div>

              <!-- Flash success -->
              <div v-if="flash.success" class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 text-sm text-green-700 font-medium">
                {{ flash.success }}
              </div>

              <!-- Connection form (shown when not connected) -->
              <div v-if="!isConnected" class="bg-white rounded-xl border border-orange-200 shadow-sm p-5 space-y-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Connecter Okohi</p>

                <!-- Base URL -->
                <div>
                  <label class="block text-xs font-bold text-gray-600 mb-1">URL de base Okohi</label>
                  <input
                    v-model="okohiBaseUrl"
                    type="url"
                    placeholder="https://okohi.anohou.dev/okohi-api-prod-xxxx"
                    class="w-full rounded-lg border-orange-200 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"
                    :class="{ 'border-red-400': errors.okohi_base_url }"
                  />
                  <InputError class="mt-1" :message="errors.okohi_base_url" />
                  <p class="text-[11px] text-gray-400 mt-1">
                    Trouvez cette URL dans <strong>Okohi → Mon Établissement → Intégration API</strong>.
                  </p>
                </div>

                <!-- Code -->
                <div>
                  <label class="block text-xs font-bold text-gray-600 mb-1">Code de connexion (4 chiffres)</label>
                  <input
                    v-model="code"
                    type="text"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="1234"
                    class="w-full rounded-lg border-orange-200 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm font-mono tracking-widest text-center text-lg"
                    :class="{ 'border-red-400': errors.code }"
                  />
                  <InputError class="mt-1" :message="errors.code" />
                  <p class="text-[11px] text-gray-400 mt-1">
                    Dans Okohi : <strong>Modification de l'établissement → Intégration API → Apps Partenaires → Connecter</strong>.
                  </p>
                </div>

                <button
                  @click="connect"
                  :disabled="processing || !okohiBaseUrl || code.length !== 4"
                  class="w-full py-3 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-bold rounded-xl transition-colors shadow-lg shadow-green-100 flex items-center justify-center gap-2"
                >
                  <Loader v-if="processing" :size="20" class="animate-spin" />
                  <LinkVariant v-else :size="20" />
                  {{ processing ? 'Connexion…' : 'Connecter' }}
                </button>
              </div>

              <!-- Connected state: verify URL + disconnect -->
              <div v-if="isConnected" class="space-y-4">

                <!-- Verify URL -->
                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                  <p class="text-xs font-bold text-green-700 uppercase tracking-wide mb-2">
                    Votre URL de vérification
                  </p>
                  <div class="flex items-center gap-2">
                    <code class="flex-1 text-[11px] bg-white border border-green-100 rounded-lg px-3 py-2 font-mono break-all text-gray-800 leading-relaxed select-all">{{ verifyUrl }}</code>
                    <button
                      @click="copyVerifyUrl"
                      type="button"
                      :title="copied ? 'Copié !' : 'Copier'"
                      class="shrink-0 flex items-center justify-center gap-1.5 px-3 py-2 h-9 rounded-lg border border-green-300 bg-white hover:bg-green-100 text-green-600 font-bold text-[11px] transition-colors whitespace-nowrap"
                    >
                      <Check v-if="copied" :size="14" />
                      <ContentCopy v-else :size="14" />
                      <span>{{ copied ? 'Copié !' : 'Copier' }}</span>
                    </button>
                  </div>
                  <p class="text-[11px] text-green-700 mt-2 leading-relaxed">
                    Okohi appellera cette URL pour valider les tickets avant de créditer les points.
                  </p>
                </div>

                <!-- Disconnect -->
                <button
                  @click="disconnect"
                  :disabled="processing"
                  class="w-full py-3 bg-white hover:bg-red-50 disabled:opacity-60 text-red-500 font-bold rounded-xl transition-colors border border-red-200 flex items-center justify-center gap-2"
                >
                  <Loader v-if="processing" :size="20" class="animate-spin" />
                  <Delete v-else :size="20" />
                  {{ processing ? 'Déconnexion…' : 'Déconnecter Okohi' }}
                </button>
              </div>

            </div>

            <!-- Right column — how it works -->
            <div class="space-y-4">
              <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-4">Comment connecter Okohi</p>
                <ol class="space-y-4">
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">1</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Dans l'app <strong>Okohi</strong>, allez dans <strong>Modification de l'établissement → Intégration API → Apps Partenaires</strong> et cliquez <strong>Connecter</strong> à côté de <em>Tiketi</em>.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">2</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Okohi génère un <strong>code à 4 chiffres</strong> valable 24h. Copiez-le.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">3</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Collez l'URL de base Okohi et le code dans le formulaire ci-contre, puis cliquez <strong>Connecter</strong>.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">4</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      L'intégration est active. Le QR code sur chaque ticket imprimé permet au client de scanner et gagner des points automatiquement.
                    </p>
                  </li>
                </ol>
              </div>

              <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-4">Comment ça fonctionne</p>
                <ol class="space-y-4">
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-black text-xs flex items-center justify-center shrink-0">A</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Le guichetier imprime un ticket → le QR code contient le lien Okohi avec le numéro de ticket, le montant et le timestamp.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-black text-xs flex items-center justify-center shrink-0">B</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Le client scanne le QR code avec l'application <strong>Okohi</strong>.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-black text-xs flex items-center justify-center shrink-0">C</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Okohi appelle votre URL de vérification pour confirmer que le ticket est valide.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-black text-xs flex items-center justify-center shrink-0">D</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Tiketi répond <code class="text-[11px] bg-gray-100 px-1 rounded">"valid": true</code> → Okohi attribue les points au client.
                    </p>
                  </li>
                </ol>
              </div>
            </div>

          </div>
        </div>
      </div>

    </div>
  </MainNavLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #fed7aa; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #fdba74; }
</style>
