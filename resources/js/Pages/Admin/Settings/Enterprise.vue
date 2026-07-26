<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
  tenant: Object,
  stats: {
    type: Object,
    default: () => ({}),
  },
  operationalSettings: Object,
});

const form = useForm({
  name: props.tenant.name || '',
  email: props.tenant.email || '',
  phone: props.tenant.phone || '',
  logo: null,
  automatic_connection_allocation: props.operationalSettings?.automatic_connection_allocation || false,
  connection_transfer_buffer_minutes: props.operationalSettings?.connection_transfer_buffer_minutes ?? 15,
  seller_compensation_enabled: props.operationalSettings?.settings?.seller_compensation_enabled || false,
  seller_compensation_max_amount: props.operationalSettings?.settings?.seller_compensation_max_amount ?? 0,
});

const logoPreview = ref(props.tenant.logo_url || null);
const logoName = ref('');
const logoMessage = ref('');
const MAX_UPLOAD_BYTES = 1.8 * 1024 * 1024;

const compressImage = (file) => new Promise((resolve, reject) => {
  const reader = new FileReader();

  reader.onerror = () => reject(new Error('Impossible de lire le fichier.'));
  reader.onload = () => {
    const image = new Image();
    image.onerror = () => reject(new Error('Impossible de charger l’image.'));
    image.onload = async () => {
      try {
        const maxDimension = 1400;
        const scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
        const width = Math.max(1, Math.round(image.width * scale));
        const height = Math.max(1, Math.round(image.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');
        if (! context) {
          resolve(file);
          return;
        }

        context.drawImage(image, 0, 0, width, height);

        const targetType = 'image/webp';
        const toBlob = () => new Promise((blobResolve) => {
          canvas.toBlob((blob) => blobResolve(blob), targetType, 0.88);
        });

        let blob = await toBlob();
        if (! blob) {
          resolve(file);
          return;
        }

        if (blob.size > MAX_UPLOAD_BYTES) {
          blob = await new Promise((blobResolve) => {
            canvas.toBlob((nextBlob) => blobResolve(nextBlob), targetType, 0.7);
          }) || blob;
        }

        if (blob.size > MAX_UPLOAD_BYTES) {
          reject(new Error('Le logo dépasse encore la limite de 2 Mo après optimisation.'));
          return;
        }

        resolve(new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.webp`, { type: targetType }));
      } catch (error) {
        reject(error);
      }
    };
    image.src = reader.result;
  };

  reader.readAsDataURL(file);
});

const handleLogoChange = async (e) => {
  const file = e.target.files[0];
  if (!file) return;

  try {
    form.clearErrors('logo');
    logoMessage.value = file.size > MAX_UPLOAD_BYTES
      ? 'Fichier supérieur à 2 Mo. Il sera compressé automatiquement avant envoi.'
      : '';

    const optimizedFile = file.size > MAX_UPLOAD_BYTES && file.type.startsWith('image/')
      ? await compressImage(file)
      : file;

    form.logo = optimizedFile;
    logoName.value = optimizedFile.name === file.name ? file.name : `${file.name} → ${optimizedFile.name}`;
    if (file.size > MAX_UPLOAD_BYTES) {
      logoMessage.value = 'Fichier compressé pour respecter la limite d’upload du serveur (2 Mo).';
    }
  } catch (error) {
    logoMessage.value = '';
    form.setError('logo', error?.message || 'Le logo est trop volumineux. Essayez un fichier plus petit que 2 Mo.');
    return;
  }

  const reader = new FileReader();
  reader.onload = (event) => {
    logoPreview.value = event.target.result;
  };
  reader.readAsDataURL(form.logo);
};

const submit = () => {
  form.post(route('admin.settings.enterprise.update'), {
    preserveScroll: true,
    forceFormData: true,
  });
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-green-100 dark:bg-emerald-950/40 rounded-xl">
              <OfficeBuilding class="text-green-600 dark:text-emerald-450" :size="28" />
            </div>
            Informations Entreprise
          </h1>
          <p class="text-gray-500 dark:text-slate-450 mt-1">Personnalisez l'identité de votre compagnie de transport sur la plateforme.</p>
        </div>
        <div class="flex gap-2">
          <Link
            :href="route('admin.settings.index')"
            class="px-4 py-2 rounded-xl border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800"
          >
            Retour
          </Link>
        </div>
      </div>

      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu :stats="props.stats" />
        </div>

        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm p-6 h-full flex flex-col">
            <h3 class="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest mb-4">Logo de l'entreprise</h3>
            <div class="flex-1 flex flex-col items-center justify-center">
              <div class="relative group">
                <div class="w-40 h-40 bg-gray-50 dark:bg-slate-950 rounded-2xl border-2 border-dashed border-gray-200 dark:border-slate-800 flex items-center justify-center overflow-hidden transition-all group-hover:border-green-300 dark:group-hover:border-emerald-800">
                  <template v-if="logoPreview">
                    <img :src="logoPreview" class="w-full h-full object-contain" alt="Logo preview" />
                  </template>
                  <template v-else>
                    <OfficeBuilding :size="64" class="text-gray-200 dark:text-slate-800" />
                  </template>
                </div>
                <label class="absolute inset-0 cursor-pointer flex items-center justify-center bg-black/0 group-hover:bg-black/10 transition-all rounded-2xl">
                  <input type="file" @change="handleLogoChange" class="hidden" accept="image/*" />
                </label>
              </div>

              <div class="mt-5 w-full rounded-xl border border-gray-100 dark:border-slate-800 bg-gray-50 dark:bg-slate-950/40 p-4 space-y-3">
                <div class="flex items-start gap-3">
                  <CloudUpload class="text-green-600 dark:text-emerald-450 shrink-0 mt-0.5" :size="20" />
                  <div>
                    <p class="text-sm font-bold text-gray-900 dark:text-slate-100">Logo et identité visuelle</p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-1 leading-relaxed">
                      Cliquez sur la zone pour remplacer le logo de l'entreprise. Le rendu est utilisé dans l'ensemble de l'application.
                    </p>
                  </div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-950 px-3 py-2">
                  <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-slate-450">Fichier sélectionné</p>
                  <p class="mt-1 text-sm text-gray-700 dark:text-slate-300 truncate">{{ logoName || 'Aucun fichier sélectionné' }}</p>
                </div>
                <p v-if="logoMessage" class="text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/40 rounded-lg px-3 py-2">
                  {{ logoMessage }}
                </p>
                <InputError :message="form.errors.logo" />
              </div>
            </div>

            <p class="text-xs text-gray-400 dark:text-slate-500 text-center mt-4 leading-relaxed">
              Format recommandé: PNG ou SVG. Un logo carré ou horizontal fonctionne le mieux.
            </p>
          </div>
        </div>

        <div class="col-span-12 md:col-span-6 h-full min-h-0 overflow-y-auto pr-2 custom-scrollbar">
          <form
            @submit.prevent="submit"
            enctype="multipart/form-data"
            class="min-h-full rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"
          >
            <div class="flex items-start justify-between gap-4 mb-6">
              <div>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-100">Paramètres de la compagnie</h2>
                <p class="text-sm text-gray-500 dark:text-slate-450 mt-1">Mettre à jour le nom, les coordonnées et le logo.</p>
              </div>
              <div v-if="form.recentlySuccessful" class="hidden lg:flex items-center gap-2 text-green-600 dark:text-green-400 font-bold text-sm shrink-0">
                <CheckCircle :size="20" />
                Enregistré
              </div>
            </div>

            <div class="grid grid-cols-1 gap-5">
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

              <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                <label class="flex items-start gap-3 cursor-pointer">
                  <input v-model="form.automatic_connection_allocation" type="checkbox" class="mt-1 rounded border-emerald-300 text-emerald-600" />
                  <span>
                    <span class="block text-sm font-bold text-emerald-900 dark:text-emerald-200">Allocation automatique des correspondances</span>
                    <span class="block text-xs text-emerald-700 dark:text-emerald-400">Politique par défaut de la compagnie. Chaque trajet peut hériter, l’activer ou la désactiver.</span>
                  </span>
                </label>
                <div class="mt-3">
                  <InputLabel for="connection_buffer" value="Marge minimale de correspondance (minutes)" />
                  <TextInput id="connection_buffer" v-model.number="form.connection_transfer_buffer_minutes" type="number" min="0" max="240" class="mt-1 block w-full" />
                  <InputError class="mt-2" :message="form.errors.connection_transfer_buffer_minutes" />
                </div>
              </div>
              <div class="rounded-xl border border-violet-100 bg-violet-50 p-4 dark:border-violet-900/40 dark:bg-violet-950/20">
                <label class="flex items-start gap-3 cursor-pointer">
                  <input v-model="form.seller_compensation_enabled" type="checkbox" class="mt-1 rounded border-violet-300 text-violet-600" />
                  <span><span class="block text-sm font-bold text-violet-900 dark:text-violet-200">Autoriser les compensations par les vendeurs</span><span class="block text-xs text-violet-700 dark:text-violet-400">Sinon, toute demande sera transmise à un superviseur.</span></span>
                </label>
                <div class="mt-3"><InputLabel for="compensation_limit" value="Plafond vendeur (FCFA, 0 = sans plafond)" /><TextInput id="compensation_limit" v-model.number="form.seller_compensation_max_amount" type="number" min="0" class="mt-1 block w-full" /></div>
              </div>
            </div>

            <div class="mt-6 flex items-center justify-end border-t border-gray-100 pt-6 dark:border-slate-800">
              <PrimaryButton
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
                class="bg-green-600 dark:bg-emerald-600 hover:bg-green-700 dark:hover:bg-emerald-700 shadow-lg shadow-green-600/20 dark:shadow-emerald-950/20 px-8 py-3 rounded-xl"
              >
                Enregistrer les modifications
              </PrimaryButton>
            </div>
          </form>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
