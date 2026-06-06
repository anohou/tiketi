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
import Lightbulb from 'vue-material-design-icons/Lightbulb.vue';
import QrCode from 'vue-material-design-icons/Qrcode.vue';

const props = defineProps({
  settings: Object,
});

const flash = usePage().props.flash ?? {};

const processing = ref(false);
const errors = ref({});
const copied = ref(false);
const okohiUrl = ref(props.settings?.okohi_url ?? '');
const hasExistingUrl = computed(() => !!props.settings?.okohi_url);

const verifyUrl = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  return `${origin}/api/okohi/verify?ticket_id={ticket_id}`;
});

const copyVerifyUrl = () => {
  navigator.clipboard?.writeText(verifyUrl.value).then(() => {
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  });
};

const save = () => {
  processing.value = true;
  errors.value = {};
  router.put(route('admin.settings.loyalty.update'), { okohi_url: okohiUrl.value.trim() || null }, {
    onSuccess: () => { processing.value = false; },
    onError: (e) => { processing.value = false; errors.value = e; },
  });
};

const deleteIntegration = () => {
  processing.value = true;
  router.put(route('admin.settings.loyalty.update'), { okohi_url: null }, {
    onSuccess: () => { processing.value = false; okohiUrl.value = ''; },
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

      <!-- Body — un seul scroll global -->
      <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar">
        <div class="grid grid-cols-12 gap-4 px-6 pb-6">

          <!-- Left nav — masqué sur mobile, sticky sur desktop -->
          <div class="hidden md:block md:col-span-2">
            <div class="sticky top-0">
              <SettingsMenu />
            </div>
          </div>

          <!-- Mobile nav -->
          <div class="col-span-12 md:hidden">
            <SettingsMenu />
          </div>

          <!-- Contenu principal -->
          <div class="col-span-12 md:col-span-10 grid grid-cols-1 md:grid-cols-2 gap-4">

            <!-- Colonne gauche — formulaire -->
            <div class="space-y-4">

              <!-- Hero card sans position absolute -->
              <div class="bg-green-50 border border-green-100 rounded-xl p-5">
                <div class="flex items-center gap-2 mb-2">
                  <QrCode class="text-green-600 shrink-0" :size="18" />
                  <span class="text-xs font-bold text-green-700 uppercase tracking-wide">Okohi</span>
                </div>
                <p class="font-bold text-gray-800 text-sm">Fidélisez vos voyageurs automatiquement</p>
                <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                  Le QR code imprimé sur chaque ticket contiendra un lien Okohi. Quand le client le scanne avec l'application Okohi, ses points sont crédités instantanément.
                </p>
              </div>

              <!-- URL field -->
              <div class="bg-white rounded-xl border border-orange-200 shadow-sm p-5">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">
                  URL d'intégration Okohi
                </label>
                <textarea
                  v-model="okohiUrl"
                  rows="4"
                  placeholder="https://okohi.anohou.dev/okohi-api-prod-xxxx/api/v1/scan/{company_id}/{type}/{key}/{ticket_id}/{amount}/{timestamp}"
                  class="w-full rounded-lg border-orange-200 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm font-mono resize-none"
                  :class="{ 'border-red-400': errors.okohi_url }"
                />
                <InputError class="mt-1" :message="errors.okohi_url" />
                <p class="text-[11px] text-gray-400 mt-2">
                  Copiez cette URL depuis <strong>Okohi → Mon Établissement → Intégration API</strong>.
                </p>
              </div>

              <!-- Verify URL -->
              <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                <p class="text-xs font-bold text-green-700 uppercase tracking-wide mb-2">
                  Votre URL de vérification (à renseigner dans Okohi)
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
                  Dans Okohi : <strong>Mon Établissement → Intégration API → URL de vérification</strong>.
                </p>
              </div>

              <!-- Tips box -->
              <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex gap-3">
                <Lightbulb class="text-amber-500 shrink-0 mt-0.5" :size="20" />
                <div>
                  <p class="text-xs font-bold text-amber-800 mb-1">Placeholders dynamiques</p>
                  <p class="text-xs text-amber-700 leading-relaxed mb-2">
                    L'URL doit contenir ces 3 placeholders. Tiketi les remplacera automatiquement à chaque ticket imprimé :
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <code class="text-[11px] bg-white border border-amber-200 rounded px-2 py-1 text-amber-700">{ticket_id}</code>
                    <code class="text-[11px] bg-white border border-amber-200 rounded px-2 py-1 text-amber-700">{amount}</code>
                    <code class="text-[11px] bg-white border border-amber-200 rounded px-2 py-1 text-amber-700">{timestamp}</code>
                  </div>
                </div>
              </div>

              <!-- Buttons -->
              <div class="space-y-2">
                <button
                  @click="save"
                  :disabled="processing"
                  class="w-full py-3 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-bold rounded-xl transition-colors shadow-lg shadow-green-100 flex items-center justify-center gap-2"
                >
                  <Loader v-if="processing" :size="20" class="animate-spin" />
                  <GiftOutline v-else :size="20" />
                  {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
                </button>

                <button
                  v-if="hasExistingUrl"
                  @click="deleteIntegration"
                  :disabled="processing"
                  class="w-full py-3 bg-white hover:bg-red-50 disabled:opacity-60 text-red-500 font-bold rounded-xl transition-colors border border-red-200 flex items-center justify-center gap-2"
                >
                  <Delete :size="20" />
                  Supprimer l'intégration
                </button>
              </div>
            </div>

            <!-- Colonne droite — comment ça fonctionne -->
            <div class="space-y-4">
              <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-4">Comment ça fonctionne</p>
                <ol class="space-y-4">
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">1</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Le guichetier imprime un ticket → le QR code contient votre URL Okohi avec le numéro de ticket, le montant et la date.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">2</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Le client scanne le QR code avec l'application <strong>Okohi</strong>.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">3</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Okohi appelle votre URL de vérification avec le <code class="text-[11px] bg-gray-100 px-1 rounded">ticket_id</code> pour confirmer que le ticket existe.
                    </p>
                  </li>
                  <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 font-black text-xs flex items-center justify-center shrink-0">4</span>
                    <p class="text-xs text-gray-600 leading-relaxed pt-0.5">
                      Tiketi répond <code class="text-[11px] bg-gray-100 px-1 rounded">{"success": true}</code> → Okohi attribue les points au client.
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
