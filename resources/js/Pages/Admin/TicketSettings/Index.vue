<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import Printer from 'vue-material-design-icons/Printer.vue';

const props = defineProps({
  settings: Object
});

// State
const processing = ref(false);
const errors = ref({});

const form = ref({
  company_name: props.settings?.company_name || 'TSR CI',
  phone_numbers: props.settings?.phone_numbers || ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
  footer_messages: props.settings?.footer_messages || ['Valable pour ce voyage', 'Non remboursable'],
  qr_code_base_url: props.settings?.qr_code_base_url || '',
  print_qr_code: props.settings?.print_qr_code || false,
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
      alert('Paramètres enregistrés avec succès!');
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
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Printer class="text-green-600" :size="28" />
            </div>
            Impression des Tickets
          </h1>
          <p class="text-gray-500 mt-1">Paramètres du système</p>
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
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm overflow-hidden">
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 sticky top-0 bg-white z-10 shrink-0">
              <h2 class="text-lg font-semibold text-green-700">Aperçu du Ticket</h2>
            </div>

            <div class="p-6">
              <!-- Ticket Preview -->
              <div class="border-2 border-dashed border-orange-300 rounded-lg p-4 bg-gray-50 font-mono text-xs max-w-xs mx-auto">
                <!-- Company Name -->
                <div class="text-center font-bold text-base mb-2">
                  {{ form.company_name }}
                </div>

                <!-- Phone Numbers -->
                <div class="text-center text-[10px] mb-3 leading-tight">
                  <div v-for="(phone, index) in form.phone_numbers" :key="index">
                    Tel: {{ phone || '[Numéro]' }}
                  </div>
                </div>

                <div class="border-t border-gray-400 my-2"></div>

                <!-- Ticket Number -->
                <div class="text-center font-bold text-sm my-2">
                  No: TKT-EXAMPLE
                </div>

                <div class="border-t border-gray-400 my-2"></div>

                <!-- Route -->
                <div class="font-bold mb-1 text-sm">Abidjan → Yamoussoukro</div>

                <!-- Trajet -->
                <div class="mb-2 text-[10px]">
                  <div class="font-bold">Trajet:</div>
                  <div>Depart: Abidjan Gare</div>
                  <div>Arrive: Yamoussoukro Gare</div>
                </div>

                <!-- Date/Time -->
                <div class="mb-2 text-[10px]">30/11/2025   14:30</div>

                <!-- Seat -->
                <div class="text-center font-bold text-base my-2">
                  PLACE: 12
                </div>

                <!-- Price -->
                <div class="text-center font-bold text-sm my-2">
                  5 000 FCFA
                </div>

                <div class="border-t border-gray-400 my-2"></div>

                <!-- QR Code -->
                <div v-if="form.print_qr_code" class="text-center my-2">
                  <div class="inline-block border-2 border-gray-400 p-2">
                    [QR CODE]
                  </div>
                </div>

                <!-- Footer Messages -->
                <div class="text-center text-[10px] leading-tight mt-2">
                  <div v-for="(message, index) in form.footer_messages" :key="index">
                    {{ message || '[Message]' }}
                  </div>
                </div>

                <!-- Timestamp -->
                <div class="text-center text-[9px] mt-2 text-gray-500">
                  30/11/2025 14:30:00
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Form -->
        <div class="col-span-12 md:col-span-5 h-full overflow-y-auto custom-scrollbar">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4 h-full flex flex-col">
            <h2 class="text-lg font-semibold text-green-700 mb-4 shrink-0">
              Configuration
            </h2>

            <form @submit.prevent="submit" class="flex-1 overflow-y-auto pr-2 custom-scrollbar mb-4">
              <div class="space-y-6">
                <!-- Company Name -->
                <div>
                  <InputLabel for="company_name" value="Nom de l'entreprise" />
                  <TextInput 
                    v-model="form.company_name" 
                    id="company_name" 
                    placeholder="TSR CI"
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
                        class="px-2 py-1 bg-green-50 text-green-600 text-[10px] font-bold uppercase rounded border border-green-200 hover:bg-green-100 transition-colors flex items-center"
                      >
                        <Plus class="w-3 h-3 mr-1" />
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
                        class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                      >
                        <Delete class="w-5 h-5" />
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Footer Messages -->
                <div>
                  <div class="flex items-center justify-between mb-2">
                    <InputLabel value="Messages de pied de page" />
                    <button
                      @click="addFooter"
                      type="button"
                      class="px-2 py-1 bg-green-50 text-green-600 text-[10px] font-bold uppercase rounded border border-green-200 hover:bg-green-100 transition-colors flex items-center"
                    >
                      <Plus class="w-3 h-3 mr-1" />
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
                        class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                      >
                        <Delete class="w-5 h-5" />
                      </button>
                    </div>
                  </div>
                </div>

                <!-- QR Code Settings -->
                <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                  <div class="flex items-center mb-4">
                    <input 
                      type="checkbox" 
                      v-model="form.print_qr_code" 
                      id="print_qr_code" 
                      class="rounded border-orange-300 text-green-600 shadow-sm focus:ring focus:ring-green-200"
                    />
                    <InputLabel for="print_qr_code" value="Activer le QR Code" class="ml-2 font-bold" />
                  </div>

                  <div v-if="form.print_qr_code" class="space-y-2 pl-6">
                    <InputLabel for="qr_code_base_url" value="URL de base (optionnel)" />
                    <TextInput 
                      v-model="form.qr_code_base_url" 
                      id="qr_code_base_url" 
                      placeholder="https://tsr-ci.com/verify/"
                      type="url"
                    />
                    <p class="text-[10px] text-gray-500 italic">
                      Sera ajouté avant le code du ticket
                    </p>
                  </div>
                </div>
              </div>
            </form>

            <!-- Submit Button (Fixed at bottom) -->
            <div class="pt-4 border-t border-orange-200 shrink-0">
              <button
                @click="submit"
                class="w-full py-3 bg-green-600 text-white font-bold rounded-xl hover:bg-green-700 transition-colors shadow-lg shadow-green-200 flex items-center justify-center gap-2"
                :disabled="processing"
              >
                <Loader v-if="processing" class="w-5 h-5 animate-spin" />
                <Printer v-else class="w-5 h-5" />
                <span>{{ processing ? 'Enregistrement...' : 'Enregistrer les paramètres' }}</span>
              </button>
            </div>
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
  background: #fed7aa;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #fdba74;
}
</style>
