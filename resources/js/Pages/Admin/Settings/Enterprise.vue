<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
  tenant: Object
});

const form = useForm({
  name: props.tenant.name || '',
  email: props.tenant.email || '',
  phone: props.tenant.phone || '',
  logo: null,
});

const logoPreview = ref(props.tenant.logo_url || null);

const handleLogoChange = (e) => {
  const file = e.target.files[0];
  if (file) {
    form.logo = file;
    const reader = new FileReader();
    reader.onload = (e) => {
      logoPreview.value = e.target.result;
    };
    reader.readAsDataURL(file);
  }
};

const submit = () => {
    // We use a POST request even for updates when uploading files
    form.post(route('admin.settings.enterprise.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Optional: show a toast or success message
        }
    });
};
</script>

<template>
  <MainNavLayout>
    <div class="max-w-4xl mx-auto px-4 py-8">
      <!-- Back Link -->
      <Link :href="route('admin.settings.index')" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-green-600 mb-6 transition-colors">
        <ChevronLeft :size="20" />
        Retour aux paramètres
      </Link>

      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
          <div class="p-2 bg-blue-100 rounded-xl">
            <OfficeBuilding class="text-blue-600" :size="28" />
          </div>
          Informations Enterprise
        </h1>
        <p class="text-gray-500 mt-2">Personnalisez l'identité de votre compagnie de transport sur la plateforme.</p>
      </div>

      <div class="grid gap-8 grid-cols-1 md:grid-cols-3">
        <!-- Left Column: Logo Preview -->
        <div class="md:col-span-1">
          <h3 class="text-sm font-bold text-gray-400 uppercase tracking-widest mb-4">Logo de l'entreprise</h3>
          <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm flex flex-col items-center">
            <div class="relative group">
              <div class="w-40 h-40 bg-gray-50 rounded-2xl border-2 border-dashed border-gray-200 flex items-center justify-center overflow-hidden transition-all group-hover:border-green-300">
                <template v-if="logoPreview">
                  <img :src="logoPreview" class="w-full h-full object-contain" alt="Logo preview" />
                </template>
                <template v-else>
                  <OfficeBuilding :size="64" class="text-gray-200" />
                </template>
              </div>
              <label class="absolute inset-0 cursor-pointer flex items-center justify-center bg-black/0 group-hover:bg-black/10 transition-all rounded-2xl">
                <input type="file" @change="handleLogoChange" class="hidden" accept="image/*" />
              </label>
            </div>
            <p class="text-xs text-gray-400 text-center mt-4 leading-relaxed">
              Cliquez sur l'image pour charger un nouveau logo.<br> Format recommandé: PNG ou SVG (carré ou horizontal).
            </p>
          </div>
        </div>

        <!-- Right Column: Form -->
        <div class="md:col-span-2">
           <form @submit.prevent="submit" class="bg-white p-8 rounded-2xl border border-gray-100 shadow-sm space-y-6">
              <div class="grid grid-cols-1 gap-6">
                <!-- Name -->
                <div>
                  <InputLabel for="name" value="Nom de l'entreprise" />
                  <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    placeholder="Ex: Transport Express"
                  />
                  <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <!-- Email -->
                <div>
                  <InputLabel for="email" value="Email de contact" />
                  <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    placeholder="contact@entreprise.com"
                  />
                  <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <!-- Phone -->
                <div>
                  <InputLabel for="phone" value="Téléphone" />
                  <TextInput
                    id="phone"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.phone"
                    placeholder="+225 ..."
                  />
                  <InputError class="mt-2" :message="form.errors.phone" />
                </div>
              </div>

              <!-- Action Buttons -->
              <div class="pt-6 border-t border-gray-50 flex items-center justify-between">
                <div v-if="form.recentlySuccessful" class="flex items-center gap-2 text-green-600 font-bold text-sm animate-in fade-in slide-in-from-left-4">
                  <CheckCircle :size="20" />
                  Paramètres enregistrés !
                </div>
                <div v-else></div>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing" class="bg-green-600 hover:bg-green-700 shadow-lg shadow-green-600/20 px-8 py-3 rounded-xl">
                  Enregistrer les modifications
                </PrimaryButton>
              </div>
           </form>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
