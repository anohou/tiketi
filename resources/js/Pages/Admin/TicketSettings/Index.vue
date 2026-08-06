<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import FormPanel from '@/Components/FormPanel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import { toastStore } from '@/Stores/toastStore.js';

const props = defineProps({
  settings: Object,
  previewTicket: Object
});

// State
const processing = ref(false);
const errors = ref({});
const page = usePage();

const form = ref({
  company_name: props.settings?.company_name || 'TEST TRANSPORT',
  phone_numbers: props.settings?.phone_numbers || ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
  cc_label: props.settings?.cc_label || '',
  footer_messages: props.settings?.footer_messages || ['Valable pour ce voyage', 'Non remboursable'],
  baggage_policy_message: props.settings?.baggage_policy_message || "La perte des bagages transportés doit faire l'objet d'une déclaration aux agences de la société.",
  baggage_policy_message_2: props.settings?.baggage_policy_message_2 || "Les objets de valeur doivent faire l'objet d'une déclaration en sus de l'enregistrement avec pièces justificatives avant le départ.",
  print_qr_code: props.settings?.print_qr_code || false,
});

const shouldShowPreviewQrCode = computed(() => form.value.print_qr_code);
const tenantLogo = computed(() => page.props.tenant?.logo_url || null);
const previewTicket = computed(() => props.previewTicket || null);
const previewTicketNumber = computed(() => previewTicket.value?.ticket_number || previewTicket.value?.ticketNumber || 'TKT-EXAMPLE');
const previewFromStation = computed(() => previewTicket.value?.from_station?.name || previewTicket.value?.fromStation?.name || 'Gare Nord (Adjamé)');
const previewToStation = computed(() => previewTicket.value?.to_station?.name || previewTicket.value?.toStation?.name || 'Gare 1 Yakro');
const previewDateTime = computed(() => {
  const createdAt = previewTicket.value?.created_at || previewTicket.value?.createdAt;
  if (createdAt) {
    return new Date(createdAt).toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  return '23/05/2026 20:51';
});
const previewTimestamp = computed(() => {
  const createdAt = previewTicket.value?.created_at || previewTicket.value?.createdAt;
  if (createdAt) {
    return new Date(createdAt).toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  }

  return '23/05/2026 13:44:05';
});
const previewDepartureDate = computed(() => {
  const departureAt = previewTicket.value?.trip?.departure_at;
  return departureAt ? new Date(departureAt).toLocaleDateString('fr-FR') : '23/05/2026';
});
const previewDepartureTime = computed(() => {
  const departureAt = previewTicket.value?.trip?.departure_at;
  return departureAt
    ? new Date(departureAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    : '20:51';
});
const previewVehicleNumber = computed(() => previewTicket.value?.trip?.vehicle?.identifier || 'N/A');
const previewSeatNumber = computed(() => previewTicket.value?.seat_number || previewTicket.value?.seatNumber || 41);
const previewPrice = computed(() => {
  const price = previewTicket.value?.price ?? previewTicket.value?.amount;
  if (price === null || price === undefined || price === '') return '5 000';
  return Number(price).toLocaleString('fr-FR');
});
const previewBluetoothPrice = computed(() => {
  const price = previewTicket.value?.price ?? previewTicket.value?.amount;
  return String(Number(price || 0));
});
const previewFormats = [
  {
    key: '80mm',
    label: '80 mm',
    description: 'Imprimante thermique standard',
    width: '302px',
  },
  {
    key: '58mm',
    label: '58 mm',
    description: 'Bluetooth ESC/POS — 32 caractères',
    width: '219px',
  },
];
const activePreviewFormatKey = ref('80mm');
const activePreviewFormat = computed(() => (
  previewFormats.find((format) => format.key === activePreviewFormatKey.value) || previewFormats[0]
));
const fitEscPos = (value, width = 32) => {
  const text = String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();

  return text.length > width ? `${text.slice(0, Math.max(0, width - 1))}.` : text;
};
const wrapEscPos = (value, width = 32) => {
  const words = String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .split(' ')
    .filter(Boolean);
  const lines = [];
  let currentLine = '';

  words.forEach((word) => {
    const candidate = currentLine ? `${currentLine} ${word}` : word;
    if (candidate.length > width && currentLine) {
      lines.push(currentLine);
      currentLine = word;
    } else {
      currentLine = candidate;
    }
  });

  if (currentLine) lines.push(currentLine);
  return lines;
};
const previewBluetoothBaggageLines = computed(() => {
  const lines1 = wrapEscPos(`1. ${form.value.baggage_policy_message || ''}`, 32);
  const lines2 = form.value.baggage_policy_message_2
    ? wrapEscPos(`2. ${form.value.baggage_policy_message_2}`, 32)
    : [];
  return [...lines1, ...lines2];
});
const bluetoothPreviewTimestamp = new Date().toLocaleString('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
});

// Methods
const addPhone = () => {
  form.value.phone_numbers.push('');
};

const removePhone = (index) => {
  if (form.value.phone_numbers.length > 1) {
    form.value.phone_numbers.splice(index, 1);
  }
};

const addFooter = () => {
  form.value.footer_messages.push('');
};

const removeFooter = (index) => {
  if (form.value.footer_messages.length > 1) {
    form.value.footer_messages.splice(index, 1);
  }
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  router.put(route('admin.ticket-settings.update'), form.value, {
    onSuccess: () => {
      processing.value = false;
      toastStore.success('Paramètres enregistrés avec succès.');
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 dark:bg-emerald-950 rounded-xl">
              <Printer class="text-green-600" :size="28" />
            </div>
            Impression des Tickets
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Paramètres du système</p>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Preview -->
        <div class="col-span-12 md:col-span-5 h-full overflow-y-auto custom-scrollbar">
          <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/80 p-3 dark:border-slate-800 dark:bg-slate-950/60 shrink-0">
              <h2 class="text-lg font-semibold text-green-700 dark:text-emerald-400">Aperçu du Ticket</h2>
              <div class="flex rounded-lg border border-slate-200 bg-white/80 p-1 shadow-sm dark:border-slate-700 dark:bg-slate-950/70" aria-label="Format du ticket">
                <button
                  v-for="format in previewFormats"
                  :key="format.key"
                  type="button"
                  class="rounded-md px-3 py-1.5 text-xs font-black transition-colors"
                  :class="activePreviewFormatKey === format.key
                    ? 'bg-green-600 text-white shadow-sm dark:bg-emerald-500 dark:text-slate-950'
                    : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100'"
                  :aria-pressed="activePreviewFormatKey === format.key"
                  @click="activePreviewFormatKey = format.key"
                >
                  {{ format.label }}
                </button>
              </div>
            </div>

            <div class="p-6">
              <section class="flex flex-col items-center">
                <p class="mb-3 text-xs font-medium text-slate-500 dark:text-slate-400">
                  {{ activePreviewFormat.description }}
                </p>

                <!-- Ticket Preview -->
                <div
                  v-if="activePreviewFormat.key === '80mm'"
                  class="ticket-preview border-2 border-dashed border-slate-300 rounded-lg p-4 bg-white font-sans text-xs mx-auto text-black"
                  :class="`ticket-preview--${activePreviewFormat.key}`"
                  :style="{ width: activePreviewFormat.width, maxWidth: '100%' }"
                >
                <div class="grid grid-cols-[1fr_auto] gap-3 mb-3">
                  <div>
                    <div class="preview-company-name font-black text-lg leading-none border-b-2 border-black inline-block pb-1">
                      {{ form.company_name || 'TEST TRANSPORT' }}
                    </div>
                    <div class="text-[10px] leading-tight mt-2">
                      <div v-for="(phone, index) in form.phone_numbers" :key="index">
                        {{ phone || '[Numéro]' }}
                      </div>
                    </div>
                  </div>

                  <div v-if="tenantLogo" class="preview-logo flex h-20 w-20 items-center justify-center">
                    <img :src="tenantLogo" :alt="`${form.company_name || 'Entreprise'} logo`" class="max-h-full max-w-full object-contain" />
                  </div>
                </div>

                <div class="border-b border-black text-center py-2 mb-2">
                  <div class="text-[10px] font-black underline">N° TICKET</div>
                  <div class="preview-ticket-number text-xl font-black leading-tight mt-1">{{ previewTicketNumber }}</div>
                </div>

                <div v-if="form.cc_label?.trim()" class="border-2 border-black px-2 py-1 font-black mb-2">
                  {{ form.cc_label }}
                </div>

                <div>
                  <div class="text-[11px] leading-tight mb-1.5">
                    <div><span class="font-black">Depart:</span> {{ previewFromStation }}</div>
                  </div>

                  <div class="text-center py-1.5 mb-1.5">
                    <div class="text-[10px] uppercase font-black">Destination</div>
                    <div class="preview-destination text-lg leading-tight font-black uppercase mt-1">{{ previewToStation }}</div>
                  </div>

                  <div class="preview-date-grid grid grid-cols-[2fr_1fr] border-y border-black text-center font-black mb-2">
                    <div class="preview-date-value py-2">{{ previewDateTime }}</div>
                    <div class="preview-info-price px-1 py-2">
                      <span class="block text-[7px] uppercase leading-none">Prix</span>
                      <span class="mt-1 block leading-tight">{{ previewPrice }}</span>
                    </div>
                  </div>

                  <div class="preview-summary flex items-center justify-center gap-6 font-black">
                    <div class="flex flex-col items-center justify-center text-center whitespace-nowrap">
                      <span class="mb-1 text-[10px] uppercase">Siege</span>
                      <span class="inline-flex items-center justify-center border-2 border-black rounded-full min-w-8 h-8 px-2 text-base">{{ previewSeatNumber }}</span>
                    </div>
                    <div v-if="shouldShowPreviewQrCode" class="preview-qr h-16 w-16 shrink-0 p-1 grid grid-cols-4 gap-0.5">
                      <span v-for="dot in 16" :key="dot" :class="dot % 3 === 0 || dot === 1 || dot === 4 || dot === 13 ? 'bg-black' : 'bg-white'" />
                    </div>
                  </div>
                </div>

                <div class="text-[10px] leading-tight mt-3 pb-4">
                  <div class="font-bold mb-2">
                    <div v-for="(message, index) in form.footer_messages" :key="index">
                      {{ message || '[Message]' }}
                    </div>
                  </div>
                  <div class="text-[9px] text-justify">
                    1. {{ form.baggage_policy_message || '[Message bagages]' }}
                    <br />
                    2. {{ form.baggage_policy_message_2 || '[Message objets de valeur]' }}
                  </div>
                  <div class="text-center text-[10px] mt-3">{{ previewTimestamp }}</div>
                </div>
              </div>

                <div
                  v-else
                  class="bluetooth-ticket-preview border-2 border-dashed border-slate-300 rounded-lg bg-white font-mono mx-auto text-black"
                  :style="{ width: activePreviewFormat.width, maxWidth: '100%' }"
                >
                  <div class="bluetooth-company">{{ fitEscPos(form.company_name || 'TEST TRANSPORT', 16) }}</div>
                  <div class="bluetooth-normal text-center">
                    <div v-for="(phone, index) in form.phone_numbers.slice(0, 2)" :key="index">{{ fitEscPos(phone, 32) }}</div>
                  </div>

                  <div class="bluetooth-separator"></div>
                  <div class="bluetooth-normal text-center font-black">N TICKET</div>
                  <div class="bluetooth-ticket-number">{{ fitEscPos(previewTicketNumber, 32) }}</div>

                  <div class="bluetooth-separator"></div>
                  <div class="bluetooth-pair">
                    <span>DEPART</span>
                    <span>{{ fitEscPos(previewFromStation, 19) }}</span>
                  </div>
                  <div class="bluetooth-normal mt-1 text-center font-black">DESTINATION</div>
                  <div class="bluetooth-destination">{{ fitEscPos(previewToStation, 16) }}</div>
                  <div class="bluetooth-date">{{ fitEscPos(`${previewDepartureDate} ${previewDepartureTime}`, 16) }}</div>
                  <div class="bluetooth-pair">
                    <span>VEHICULE</span>
                    <span>{{ fitEscPos(previewVehicleNumber, 20) }}</span>
                  </div>

                  <div class="bluetooth-separator"></div>
                  <div class="bluetooth-place">PLACE {{ previewSeatNumber }}</div>
                  <div class="bluetooth-price">{{ fitEscPos(previewBluetoothPrice, 12) }} FCFA</div>
                  <div class="bluetooth-separator"></div>

                  <div v-if="shouldShowPreviewQrCode" class="bluetooth-qr" aria-label="Aperçu QR code">
                    <span v-for="dot in 100" :key="dot" :class="dot % 3 === 0 || dot % 7 === 0 || [1, 2, 9, 10, 11, 19, 20, 81, 82, 91, 92].includes(dot) ? 'bg-black' : 'bg-white'" />
                  </div>

                  <div class="bluetooth-footer">
                    <div v-for="(message, index) in form.footer_messages.slice(0, 2)" :key="index">{{ fitEscPos(message, 32) }}</div>
                    <div v-if="form.baggage_policy_message" class="bluetooth-baggage">
                      <div v-for="(line, index) in previewBluetoothBaggageLines" :key="index">{{ line }}</div>
                    </div>
                    <div class="mt-2">{{ fitEscPos(bluetoothPreviewTimestamp, 32) }}</div>
                  </div>
                </div>
              </section>
            </div>
          </div>
        </div>

        <!-- Right Column - Form -->
        <div class="col-span-12 md:col-span-5 h-full">
          <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden h-full">
            <FormPanel @submit="submit">
              <template #header>
                <h2 class="text-lg font-semibold text-green-700 dark:text-emerald-400 px-4 py-3">
                  Configuration
                </h2>
              </template>

              <div class="p-6 space-y-6">
                <!-- Company Name -->
                <div>
                  <InputLabel for="company_name" value="Nom de l'entreprise" />
                  <TextInput 
                    v-model="form.company_name" 
                    id="company_name" 
                    placeholder="TEST TRANSPORT"
                    :class="{ 'border-red-500': errors.company_name }" 
                  />
                  <InputError class="mt-2" :message="errors.company_name" />
                </div>

                <!-- Phone Numbers -->
                <div>
                  <div class="flex items-center justify-between mb-2">
                     <InputLabel value="Numéros de téléphone" />
                     <button
                        @click="addPhone"
                        type="button"
                        class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-green-200 bg-green-50 px-3 text-xs font-bold uppercase leading-none text-green-600 transition-colors hover:bg-green-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900"
                      >
                        <Plus :size="14" class="inline-flex shrink-0 items-center justify-center leading-none" />
                        Ajouter
                      </button>
                  </div>
                  <div class="space-y-2">
                    <div v-for="(phone, index) in form.phone_numbers" :key="index" class="flex gap-2">
                      <TextInput 
                        v-model="form.phone_numbers[index]" 
                        placeholder="+225 XX XX XX XX XX"
                        class="flex-1"
                      />
                      <button
                        v-if="form.phone_numbers.length > 1"
                        @click="removePhone(index)"
                        type="button"
                        class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors dark:hover:bg-rose-950/50 dark:hover:text-rose-300"
                      >
                        <Delete class="w-5 h-5" />
                      </button>
                    </div>
                  </div>
                </div>

                <div>
                  <InputLabel for="cc_label" value="Libellé CC" />
                  <TextInput 
                    v-model="form.cc_label" 
                    id="cc_label" 
                    placeholder="CC"
                    :class="{ 'border-red-500': errors.cc_label }" 
                  />
                  <InputError class="mt-2" :message="errors.cc_label" />
                </div>

                <!-- Footer Messages -->
                <div>
                  <div class="flex items-center justify-between mb-2">
                    <InputLabel value="Messages de pied de page" />
                    <button
                      @click="addFooter"
                      type="button"
                      class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-green-200 bg-green-50 px-3 text-xs font-bold uppercase leading-none text-green-600 transition-colors hover:bg-green-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900"
                    >
                      <Plus :size="14" class="inline-flex shrink-0 items-center justify-center leading-none" />
                      Ajouter
                    </button>
                  </div>
                  <div class="space-y-2">
                    <div v-for="(message, index) in form.footer_messages" :key="index" class="flex gap-2">
                      <TextInput 
                        v-model="form.footer_messages[index]" 
                        placeholder="Message"
                        class="flex-1"
                      />
                      <button
                        v-if="form.footer_messages.length > 1"
                        @click="removeFooter(index)"
                        type="button"
                        class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors dark:hover:bg-rose-950/50 dark:hover:text-rose-300"
                      >
                        <Delete class="w-5 h-5" />
                      </button>
                    </div>
                  </div>
                </div>

                <div>
                  <InputLabel for="baggage_policy_message" value="Message déclaration bagages (Point 1)" />
                  <textarea
                    v-model="form.baggage_policy_message"
                    id="baggage_policy_message"
                    rows="3"
                    class="w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-600"
                    :class="{ 'border-red-500': errors.baggage_policy_message }"
                    placeholder="La perte des bagages transportés..."
                  />
                  <InputError class="mt-2" :message="errors.baggage_policy_message" />
                </div>

                <div>
                  <InputLabel for="baggage_policy_message_2" value="Message déclaration bagages (Point 2)" />
                  <textarea
                    v-model="form.baggage_policy_message_2"
                    id="baggage_policy_message_2"
                    rows="3"
                    class="w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-600"
                    :class="{ 'border-red-500': errors.baggage_policy_message_2 }"
                    placeholder="Les objets de valeur doivent faire l'objet..."
                  />
                  <InputError class="mt-2" :message="errors.baggage_policy_message_2" />
                </div>

                <!-- QR Code Settings -->
                <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 dark:border-slate-800 dark:bg-slate-950/60">
                  <div class="flex items-center mb-4">
                    <input 
                      type="checkbox" 
                      v-model="form.print_qr_code" 
                      id="print_qr_code" 
                      class="rounded border-slate-300 text-emerald-600 shadow-sm focus:ring focus:ring-emerald-200"
                    />
                    <InputLabel for="print_qr_code" value="Activer le QR Code" class="ml-2 font-bold" />
                  </div>

                </div>


                <!-- Link to loyalty page -->
                <div class="bg-green-50 rounded-xl border border-green-100 p-4 flex items-center justify-between dark:border-emerald-900/70 dark:bg-emerald-950/30">
                  <div>
                    <p class="text-sm font-bold text-green-800 dark:text-emerald-300">Fidélisation Okohi</p>
                    <p class="text-xs text-green-600 mt-0.5 dark:text-emerald-400">Configurer les points de fidélité sur les tickets</p>
                  </div>
                  <Link
                    :href="route('admin.settings.loyalty')"
                    class="px-3 py-1.5 bg-green-600 text-white text-xs font-bold rounded-lg hover:bg-green-700 transition-colors"
                  >
                    Configurer →
                  </Link>
                </div>
              </div>

              <template #actions>
              <button
                type="submit"
                class="w-full py-3 bg-green-600 text-white font-bold rounded-xl hover:bg-green-700 transition-colors shadow-lg shadow-green-200 flex items-center justify-center gap-2 dark:bg-emerald-600 dark:shadow-none dark:hover:bg-emerald-500"
                :disabled="processing"
              >
                <Loader v-if="processing" class="w-5 h-5 animate-spin" />
                <Printer v-else class="w-5 h-5" />
                <span>{{ processing ? 'Enregistrement...' : 'Enregistrer les paramètres' }}</span>
              </button>
              </template>
            </FormPanel>
          </div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

:global(.dark) .custom-scrollbar::-webkit-scrollbar-thumb {
  background: #334155;
}

:global(.dark) .custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #475569;
}

.ticket-preview {
  box-sizing: border-box;
}

.ticket-preview--58mm {
  padding: 0.65rem;
  font-size: 9px;
}

.ticket-preview--58mm .preview-company-name {
  font-size: 14px;
}

.ticket-preview--58mm .preview-qr {
  width: 3.5rem;
  height: 3.5rem;
}

.ticket-preview--58mm .preview-logo {
  width: 3.5rem;
  height: 3.5rem;
}

.ticket-preview--58mm .preview-ticket-number {
  font-size: 16px;
}

.ticket-preview--58mm .preview-destination {
  font-size: 14px;
}

.ticket-preview--58mm .preview-date-grid {
  grid-template-columns: minmax(0, 1fr) minmax(4rem, auto);
}

.preview-date-value {
  font-size: 16px;
}

.preview-info-price {
  font-size: 18px;
}

.ticket-preview--58mm .preview-date-value {
  font-size: 12px;
}

.ticket-preview--58mm .preview-info-price {
  font-size: 14px;
}

.ticket-preview--58mm .preview-summary {
  gap: 1rem;
}

.bluetooth-ticket-preview {
  box-sizing: border-box;
  padding: 12px 10px 20px;
  font-size: 12px;
  line-height: 1.15;
}

.bluetooth-company {
  overflow: hidden;
  text-align: center;
  font-size: 23px;
  font-weight: 900;
  line-height: 1.1;
  white-space: nowrap;
}

.bluetooth-normal {
  font-size: 12px;
  line-height: 1.25;
}

.bluetooth-separator {
  margin: 9px 0;
  border-top: 1px dashed #000;
}

.bluetooth-ticket-number {
  margin-top: 3px;
  overflow-wrap: anywhere;
  text-align: center;
  font-size: 16px;
  font-weight: 900;
}

.bluetooth-pair {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 6px;
  font-size: 11px;
}

.bluetooth-pair > :last-child {
  overflow: hidden;
  text-align: right;
  white-space: nowrap;
}

.bluetooth-destination,
.bluetooth-date,
.bluetooth-place,
.bluetooth-price {
  overflow-wrap: anywhere;
  text-align: center;
  font-size: 24px;
  font-weight: 900;
  line-height: 1.05;
}

.bluetooth-date {
  margin-top: 3px;
  font-size: 21px;
}

.bluetooth-place,
.bluetooth-price {
  font-size: 28px;
}

.bluetooth-qr {
  display: grid;
  width: 112px;
  height: 112px;
  grid-template-columns: repeat(10, minmax(0, 1fr));
  margin: 8px auto 12px;
}

.bluetooth-footer {
  text-align: center;
  font-size: 13px;
  line-height: 1.35;
}

.bluetooth-baggage {
  margin-top: 8px;
  text-align: left;
  font-size: 11px;
  line-height: 1.25;
}
</style>