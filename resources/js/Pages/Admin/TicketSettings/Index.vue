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
  <MainNavLayout>
    <div class="w-full px-4">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
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
      <div class="grid grid-cols-12 gap-4">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Preview -->
        <div class="col-span-12 md:col-span-5">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm">
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30">
              <h2 class="text-lg font-semibold text-green-700">Aperçu du Ticket</h2>
            </div>

            <div class="p-6">
              <!-- Ticket Preview -->
              <div class="border-2 border-dashed border-orange-300 rounded-lg p-4 bg-gray-50 font-mono text-sm">
                <!-- Company Name -->
                <div class="text-center font-bold text-lg mb-2">
                  {{ form.company_name }}
                </div>

                <!-- Phone Numbers -->
                <div class="text-center text-xs mb-3">
                  <div v-for="(phone, index) in form.phone_numbers" :key="index">
                    Tel: {{ phone || '[Numéro]' }}
                  </div>
                </div>

                <div class="border-t border-gray-400 my-2"></div>

                <!-- Ticket Number -->
                <div class="text-center font-bold text-base my-2">
                  No: TKT-EXAMPLE
                </div>

                <div class="border-t border-gray-400 my-2"></div>

                <!-- Route -->
                <div class="font-bold mb-1">Abidjan → Yamoussoukro</div>

                <!-- Trajet -->
                <div class="mb-2">
                  <div class="font-bold">Trajet:</div>
                  <div>Depart: Abidjan Gare</div>
                  <div>Arrive: Yamoussoukro Gare</div>
                </div>

                <!-- Date/Time -->
                <div class="mb-2">30/11/2025   14:30</div>

                <!-- Seat -->
                <div class="text-center font-bold text-lg my-2">
                  PLACE: 12
                </div>

                <!-- Price -->
                <div class="text-center font-bold text-base my-2">
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
                <div class="text-center text-xs">
                  <div v-for="(message, index) in form.footer_messages" :key="index">
                    {{ message || '[Message]' }}
                  </div>
                </div>

                <!-- Timestamp -->
                <div class="text-center text-xs mt-2">
                  30/11/2025 14:30:00
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Form -->
        <div class="col-span-12 md:col-span-5">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
            <h2 class="text-lg font-semibold text-green-700 mb-4">
              Configuration
            </h2>

            <form @submit.prevent="submit">
              <div class="space-y-4">
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
                  <InputLabel value="Numéros de téléphone" />
                  <div v-for="(phone, index) in form.phone_numbers" :key="index" class="flex gap-2 mb-2">
                    <TextInput 
                      v-model="form.phone_numbers[index]" 
                      placeholder="+225 XX XX XX XX XX"
                      class="flex-1"
                    />
                    <button
                      v-if="form.phone_numbers.length > 1"
                      @click="removePhone(index)"
                      type="button"
                      class="px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                    >
                      <Delete class="w-5 h-5" />
                    </button>
                  </div>
                  <button
                    @click="addPhone"
                    type="button"
                    class="mt-1 px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center text-sm"
                  >
                    <Plus class="w-4 h-4 mr-1" />
                    Ajouter
                  </button>
                </div>

                <!-- Footer Messages -->
                <div>
                  <InputLabel value="Messages de pied de page" />
                  <div v-for="(message, index) in form.footer_messages" :key="index" class="flex gap-2 mb-2">
                    <TextInput 
                      v-model="form.footer_messages[index]" 
                      placeholder="Message"
                      class="flex-1"
                    />
                    <button
                      v-if="form.footer_messages.length > 1"
                      @click="removeFooter(index)"
                      type="button"
                      class="px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                    >
                      <Delete class="w-5 h-5" />
                    </button>
                  </div>
                  <button
                    @click="addFooter"
                    type="button"
                    class="mt-1 px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center text-sm"
                  >
                    <Plus class="w-4 h-4 mr-1" />
                    Ajouter
                  </button>
                </div>

                <!-- QR Code Settings -->
                <div>
                  <div class="flex items-center mb-2">
                    <input 
                      type="checkbox" 
                      v-model="form.print_qr_code" 
                      id="print_qr_code" 
                      class="rounded border-orange-300 text-green-600 shadow-sm focus:border-orange-300 focus:ring focus:ring-orange-200 focus:ring-opacity-50"
                    />
                    <InputLabel for="print_qr_code" value="Activer le QR Code" class="ml-2" />
                  </div>

                  <div v-if="form.print_qr_code">
                    <InputLabel for="qr_code_base_url" value="URL de base (optionnel)" />
                    <TextInput 
                      v-model="form.qr_code_base_url" 
                      id="qr_code_base_url" 
                      placeholder="https://tsr-ci.com/verify/"
                      type="url"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                      Sera ajouté avant le code du ticket
                    </p>
                  </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-3 flex justify-end border-t border-orange-200">
                  <button
                    type="submit"
                    class="px-6 py-2 bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors"
                    :disabled="processing"
                  >
                    <span v-if="processing" class="flex items-center">
                      <Loader class="w-5 h-5 mr-2 animate-spin" />
                      Enregistrement...
                    </span>
                    <span v-else>
                      Enregistrer
                    </span>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
