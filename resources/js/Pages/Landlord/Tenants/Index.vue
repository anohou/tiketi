<script setup>
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toastStore } from '@/Stores/toastStore.js';

import Database from 'vue-material-design-icons/Database.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Domain from 'vue-material-design-icons/Domain.vue';
import Earth from 'vue-material-design-icons/Earth.vue';
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';

const { t } = useI18n();

const props = defineProps({
  tenants: {
    type: Array,
    default: () => []
  },
  tenantDomainPolicy: {
    type: Object,
    default: () => ({
      platformDomain: 'tiketi.ci',
      reservedLabels: [],
      reservedPrefixes: [],
      reservedMessage: "Ce sous-domaine n'est pas disponible."
    })
  }
});

// State
const search = ref('');
const selectedTenant = ref(null);
const processing = ref(false);
const passwordProcessing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const showDomainModal = ref(false);

const showPasswordConfirmModal = ref(false);
const showDeleteTenantModal = ref(false);
const tenantIdToDelete = ref(null);
const showRemoveDomainModal = ref(false);
const domainIdToRemove = ref(null);

const form = ref({
  id: '',
  name: '',
  email: '',
  phone: '',
  domain: ''
});

const domainForm = ref({
  domain: ''
});

const reservedDomainMessage = computed(() => props.tenantDomainPolicy.reservedMessage || "Ce sous-domaine n'est pas disponible.");
const platformDomain = computed(() => props.tenantDomainPolicy.platformDomain || 'tiketi.ci');
const reservedTiketiLabels = computed(() => props.tenantDomainPolicy.reservedLabels || []);
const reservedTiketiPrefixes = computed(() => props.tenantDomainPolicy.reservedPrefixes || []);

const normalizeDomain = (value) => String(value || '')
  .trim()
  .toLowerCase()
  .replace(/^https?:\/\//i, '')
  .replace(/[/?#].*$/, '')
  .replace(/^\.+|\.+$/g, '');

const isReservedTiketiDomain = (value) => {
  const domain = normalizeDomain(value);
  if (domain === platformDomain.value) return true;
  if (!domain.endsWith(`.${platformDomain.value}`)) return false;

  const label = domain.slice(0, -(`.${platformDomain.value}`).length).split('.')[0] || '';
  return reservedTiketiLabels.value.includes(label) || reservedTiketiPrefixes.value.some(prefix => label.startsWith(prefix));
};

const createDomainReservedError = computed(() => (!isEditing.value && isReservedTiketiDomain(form.value.domain)) ? reservedDomainMessage.value : '');
const addDomainReservedError = computed(() => isReservedTiketiDomain(domainForm.value.domain) ? reservedDomainMessage.value : '');
const createDomainErrorMessage = computed(() => createDomainReservedError.value || errors.value.domain);
const addDomainErrorMessage = computed(() => addDomainReservedError.value || errors.value.domain);

// Computed
const filteredTenants = computed(() => {
  if (!search.value) return props.tenants;

  const searchTerm = search.value.toLowerCase();
  return props.tenants.filter(tenant =>
    tenant.id.toLowerCase().includes(searchTerm) ||
    tenant.name.toLowerCase().includes(searchTerm) ||
    tenant.email?.toLowerCase().includes(searchTerm) ||
    tenant.domains?.some(d => d.domain.toLowerCase().includes(searchTerm))
  );
});

// Methods
const isSelected = (tenant) => {
  return selectedTenant.value?.id === tenant.id;
};

const selectTenant = (tenant) => {
  selectedTenant.value = tenant;
};

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    id: '',
    name: '',
    email: '',
    phone: '',
    domain: ''
  };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedTenant.value) return;
  isEditing.value = true;
  form.value = {
    id: selectedTenant.value.id,
    name: selectedTenant.value.name,
    email: selectedTenant.value.email || '',
    phone: selectedTenant.value.phone || '',
    domain: ''
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = { id: '', name: '', email: '', phone: '', domain: '' };
  errors.value = {};
};

const submit = () => {
  if (createDomainReservedError.value) {
    errors.value = { ...errors.value, domain: createDomainReservedError.value };
    return;
  }

  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('landlord.tenants.update', selectedTenant.value.id)
    : route('landlord.tenants.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const confirmDeleteTenant = (id) => {
  tenantIdToDelete.value = id;
  showDeleteTenantModal.value = true;
};

const deleteTenant = () => {
  if (!tenantIdToDelete.value) return;
  const id = tenantIdToDelete.value;
  showDeleteTenantModal.value = false;

  router.delete(route('landlord.tenants.destroy', id), {
    onSuccess: () => {
      if (selectedTenant.value?.id === id) {
        selectedTenant.value = null;
      }
      tenantIdToDelete.value = null;
    },
  });
};

const confirmRegenerateTenantPassword = () => {
  if (!selectedTenant.value) return;
  showPasswordConfirmModal.value = true;
};

const regenerateTenantPassword = () => {
  if (!selectedTenant.value) return;
  showPasswordConfirmModal.value = false;
  passwordProcessing.value = true;

  router.post(route('landlord.tenants.password.regenerate', selectedTenant.value.id), {}, {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => {
      passwordProcessing.value = false;
    },
    onError: () => {
      passwordProcessing.value = false;
    }
  });
};

// Domain management
const openDomainModal = () => {
  domainForm.value = { domain: '' };
  errors.value = {};
  showDomainModal.value = true;
};

const closeDomainModal = () => {
  showDomainModal.value = false;
  domainForm.value = { domain: '' };
  errors.value = {};
};

const addDomain = () => {
  if (!selectedTenant.value) return;
  if (addDomainReservedError.value) {
    errors.value = { ...errors.value, domain: addDomainReservedError.value };
    return;
  }

  processing.value = true;

  router.post(route('landlord.tenants.domains.store', selectedTenant.value.id), domainForm.value, {
    onSuccess: () => {
      processing.value = false;
      closeDomainModal();
      // Refresh tenant data
      router.reload({ only: ['tenants'] });
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const confirmRemoveDomain = (domainId) => {
  if (!selectedTenant.value) return;
  if (selectedTenant.value.domains?.length <= 1) {
    toastStore.warning('Un tenant doit avoir au moins un domaine.');
    return;
  }
  domainIdToRemove.value = domainId;
  showRemoveDomainModal.value = true;
};

const removeDomain = () => {
  if (!selectedTenant.value || !domainIdToRemove.value) return;
  const domainId = domainIdToRemove.value;
  showRemoveDomainModal.value = false;

  router.delete(route('landlord.tenants.domains.destroy', { tenant: selectedTenant.value.id, domain: domainId }), {
    preserveScroll: true,
    onSuccess: () => {
      domainIdToRemove.value = null;
      router.reload({ only: ['tenants'] });
    }
  });
};
import Check from 'vue-material-design-icons/Check.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';

const page = usePage();
const showPasswordModal = ref(false);
const generatedPassword = ref('');
const passwordCopied = ref(false);

watch(() => page.props.flash?.tenant_admin_password, (newPassword) => {
  if (newPassword) {
    generatedPassword.value = newPassword;
    showPasswordModal.value = true;
    passwordCopied.value = false;
  }
});

const copyPassword = async () => {
  try {
    await navigator.clipboard.writeText(generatedPassword.value);
    passwordCopied.value = true;
    setTimeout(() => {
      passwordCopied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy password', err);
  }
};

const closePasswordModal = () => {
  showPasswordModal.value = false;
  // Clear the flash message via a manual visit or just hide model.
  // Inertia usually clears flash on next visit.
  generatedPassword.value = '';
}
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4 h-[calc(100vh-140px)] flex flex-col">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-purple-100 rounded-xl">
              <Domain class="text-purple-600"
                      :size="28" />
            </div>{{ $t('landlord.title') }}</h1>
          <p class="text-gray-500 mt-1">{{ $t('landlord.subtitle') }}</p>
        </div>
      </div>

      <!-- Two Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0">
        <!-- Left Column - Tenants List -->
        <div class="col-span-12 md:col-span-5 flex flex-col h-full">
          <div class="bg-white rounded-lg border border-purple-200 shadow-sm flex flex-col h-full">
            <!-- List Header -->
            <div class="border-b border-slate-200 p-3 bg-gradient-to-r from-emerald-50 to-slate-50">
              <div class="flex items-center justify-between gap-2">
                <div class="relative flex-1">
                  <input type="text"
                         v-model="search"
                         :placeholder="$t('landlord.search_placeholder')"
                         class="w-full px-4 py-2 pl-10 pr-4 border border-purple-200 rounded-lg focus:outline-none focus:border-purple-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button @click="openCreateModal"
                        class="p-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"
                        :title="$t('landlord.new_tenant')">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1">
              <div v-if="filteredTenants.length === 0"
                   class="p-4 text-center text-gray-500">{{ $t('landlord.no_tenants') }}</div>
              <div v-else>
                <div v-for="tenant in filteredTenants"
                     :key="tenant.id"
                     @click="selectTenant(tenant)"
                     class="p-4 cursor-pointer transition-colors border-b border-gray-100"
                     :style="{
                      backgroundColor: isSelected(tenant) ? '#faf5ff' : '#ffffff',
                      borderLeft: isSelected(tenant) ? '4px solid #9333ea' : '4px solid #e9d5ff'
                    }">
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <h3 :class="['font-semibold truncate text-lg', isSelected(tenant) ? 'text-purple-800' : 'text-gray-800']">
                        {{ tenant.name }}
                      </h3>
                      <p class="text-xs text-gray-500 mt-1 font-mono">ID: {{ tenant.id }}</p>
                      <!-- Domains -->
                      <div class="flex flex-wrap gap-1 mt-2">
                        <span v-for="domain in tenant.domains"
                              :key="domain.id"
                              class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full">
                          {{ domain.domain }}
                        </span>
                      </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-2">
                      <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full flex items-center gap-2">
                        <Database class="h-3 w-3" />
                        DB: {{ tenant.id }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-7 h-full overflow-y-auto pb-6">
          <!-- Empty State -->
          <div v-if="!selectedTenant"
               class="bg-white rounded-lg border border-purple-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <Domain class="h-16 w-16 text-purple-200 mb-4" />
            <p class="text-lg">{{ $t('landlord.select_tenant_hint') }}</p>
            <button @click="openCreateModal"
                    class="mt-4 text-purple-600 hover:text-purple-700 font-medium">{{ $t('landlord.or_create_tenant') }}</button>
          </div>

          <!-- View Details -->
          <div v-else
               class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-purple-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <div>
                  <h2 class="text-2xl font-bold text-gray-800">{{ selectedTenant.name }}</h2>
                  <p class="text-sm text-gray-500 font-mono">ID: {{ selectedTenant.id }}</p>
                </div>
                <div class="flex gap-2">
                  <button @click="confirmRegenerateTenantPassword"
                          class="px-3 py-2 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-lg transition-colors inline-flex items-center gap-2"
                          :disabled="passwordProcessing"
                          :title="$t('landlord.regenerate_password')">
                    <Refresh class="h-5 w-5" :class="{ 'animate-spin': passwordProcessing }" />
                    <span class="text-sm font-medium">{{ $t('landlord.regenerate') }}</span>
                  </button>
                  <button @click="openEditModal"
                          class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                          title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="confirmDeleteTenant(selectedTenant.id)"
                          class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                          title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">{{ $t('landlord.email_header') }}</span>
                  <div class="text-lg font-medium text-gray-900 break-all">
                    {{ selectedTenant.email || 'Non renseigné' }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">{{ $t('landlord.phone_header') }}</span>
                  <div class="text-lg font-medium text-gray-900">
                    {{ selectedTenant.phone || 'Non renseigné' }}
                  </div>
                </div>
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">{{ $t('landlord.database_header') }}</span>
                  <div class="flex items-center gap-2">
                    <span class="px-3 py-1 bg-green-100 text-green-700 rounded-lg font-mono text-sm flex items-center gap-3">
                      <Database class="h-4 w-4" />
                      DB: {{ selectedTenant.id }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Domains Section -->
            <div class="bg-white rounded-lg border border-purple-200 shadow-sm overflow-hidden">
              <div class="flex items-center justify-between p-4 border-b border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50/30">
                <div class="flex items-center gap-2">
                  <Earth class="h-5 w-5 text-purple-600" />
                  <h3 class="font-semibold text-gray-800">Domaines ({{ selectedTenant.domains?.length || 0 }})</h3>
                </div>
                <button @click="openDomainModal"
                        class="inline-flex items-center gap-2 px-3 py-1.5 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors">
                  <Plus class="h-4 w-4" />
                  Ajouter
                </button>
              </div>

              <div class="p-4">
                <p class="text-sm text-gray-500 mb-4">{{ $t('landlord.domains_associated') }}</p>

                <div v-if="(selectedTenant.domains || []).length === 0"
                     class="text-center py-8 text-gray-400">
                  <Earth class="h-12 w-12 mx-auto mb-2 opacity-50" />
                  <p>{{ $t('landlord.no_domain_configured') }}</p>
                </div>

                <div v-else
                     class="space-y-2">
                  <div v-for="domain in selectedTenant.domains"
                       :key="domain.id"
                       class="flex items-center justify-between p-3 rounded-lg border bg-gray-50 border-gray-100">
                    <div class="flex items-center gap-4">
                      <div class="w-8 h-8 flex items-center justify-center rounded-full bg-blue-100 text-blue-700">
                        <LinkVariant class="h-4 w-4" />
                      </div>
                      <div>
                        <p class="font-medium text-gray-800">{{ domain.domain }}</p>
                        <a :href="(domain.domain.includes('localhost') ? 'http://' + domain.domain + ':8000' : 'https://' + domain.domain)"
                           target="_blank"
                           class="text-xs text-blue-500 hover:underline">{{ $t('landlord.open_link') }}</a>
                      </div>
                    </div>
                    <button @click="confirmRemoveDomain(domain.id)"
                            class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                            title="Supprimer"
                            :disabled="(selectedTenant.domains || []).length <= 1"
                            :class="{ 'opacity-50 cursor-not-allowed': (selectedTenant.domains || []).length <= 1 }">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tenant Modal -->
    <DialogModal :show="showModal"
                 @close="closeModal">
      <template #title>
        {{ isEditing ? 'Modifier le Tenant' : 'Nouveau Tenant' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <!-- ID (only for create) -->
          <div v-if="!isEditing">
            <InputLabel for="id"
                        :value="$t('landlord.tenant_id_label')" />
            <TextInput v-model="form.id"
                       id="id"
                       type="text"
                       class="w-full mt-1"
                       placeholder="alpha-transport"
                       required />
            <p class="text-xs text-gray-500 mt-1">{{ $t('landlord.slug_hint') }}</p>
            <InputError :message="errors.id"
                        class="mt-1" />
          </div>

          <div>
            <InputLabel for="name"
                        :value="$t('landlord.company_name')" />
            <TextInput v-model="form.name"
                       id="name"
                       type="text"
                       class="w-full mt-1"
                       placeholder="Alpha Transport" />
            <InputError :message="errors.name"
                        class="mt-1" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="email"
                          :value="$t('landlord.email')" />
              <TextInput v-model="form.email"
                         id="email"
                         type="email"
                         class="w-full mt-1"
                         placeholder="contact@company.com" />
              <InputError :message="errors.email"
                          class="mt-1" />
            </div>
            <div>
              <InputLabel for="phone"
                          value="Téléphone" />
              <TextInput v-model="form.phone"
                         id="phone"
                         type="tel"
                         class="w-full mt-1"
                         placeholder="+225 00 00 00 00" />
              <InputError :message="errors.phone"
                          class="mt-1" />
            </div>
          </div>

          <!-- Domain (only for create) -->
          <div v-if="!isEditing">
            <InputLabel for="domain"
                        :value="$t('landlord.primary_domain')" />
            <TextInput v-model="form.domain"
                       id="domain"
                       type="text"
                       class="w-full mt-1"
                       placeholder="alpha.tiketi.ci ou alpha-express.com" />
            <p class="text-xs text-gray-500 mt-1">{{ $t('landlord.domain_hint') }}</p>
            <InputError :message="createDomainErrorMessage"
                        class="mt-1" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton @click="submit"
                       :disabled="processing || Boolean(createDomainReservedError)"
                       class="ml-3">
          {{ isEditing ? 'Enregistrer' : 'Créer le Tenant' }}
        </PrimaryButton>
      </template>
    </DialogModal>

    <!-- Add Domain Modal -->
    <DialogModal :show="showDomainModal"
                 @close="closeDomainModal">
      <template #title>{{ $t('landlord.add_domain') }}</template>
      <template #content>
        <div>
          <InputLabel for="new-domain"
                      :value="$t('landlord.domain')" />
          <TextInput v-model="domainForm.domain"
                     id="new-domain"
                     type="text"
                     class="w-full mt-1"
                     placeholder="beta.tiketi.ci" />
          <p class="text-xs text-gray-500 mt-1">{{ $t('landlord.domain_sub_hint') }}</p>
          <InputError :message="addDomainErrorMessage"
                      class="mt-1" />
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeDomainModal">Annuler</SecondaryButton>
        <PrimaryButton @click="addDomain"
                       :disabled="processing || Boolean(addDomainReservedError)"
                       class="ml-3">
          Ajouter
        </PrimaryButton>
      </template>
    </DialogModal>
    <!-- Password Display Modal -->
    <DialogModal :show="showPasswordModal"
                 @close="closePasswordModal">
      <template #title>{{ $t('landlord.admin_password') }}</template>
      <template #content>
        <div class="text-center py-4">
          <div class="mb-4">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
              <Check class="h-6 w-6 text-green-600" />
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900">{{ $t('landlord.generated_password') }}</h3>
            <div class="mt-2 px-7 py-3">
              <p class="text-sm text-gray-500">
                Voici le mot de passe généré pour l'administrateur du tenant.
                Veuillez le copier maintenant, car il ne sera plus affiché.
              </p>
            </div>
          </div>

          <div class="relative mt-2 rounded-md shadow-sm max-w-sm mx-auto">
            <input type="text"
                   readonly
                   :value="generatedPassword"
                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pr-12 text-2xl font-mono text-center border-gray-300 rounded-md tracking-widest bg-gray-50 py-3" />
            <div class="absolute inset-y-0 right-0 flex items-center">
              <button @click="copyPassword"
                      class="h-full px-3 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-r-md transition-colors border-l"
                      :class="{ 'text-green-500 hover:text-green-600': passwordCopied }"
                      :title="$t('landlord.copy')">
                <Check v-if="passwordCopied"
                       class="h-5 w-5" />
                <ContentCopy v-else
                             class="h-5 w-5" />
              </button>
            </div>
          </div>
          <p v-if="passwordCopied"
             class="text-xs text-green-600 mt-2 font-medium">{{ $t('landlord.password_copied') }}</p>
        </div>
      </template>
      <template #footer>
        <PrimaryButton @click="closePasswordModal">{{ $t('landlord.finish') }}</PrimaryButton>
      </template>
    </DialogModal>

    <!-- Confirmation Modal for Password Regeneration -->
    <ConfirmationModal :show="showPasswordConfirmModal" @close="showPasswordConfirmModal = false">
      <template #title>{{ $t('landlord.regenerate_password') }}</template>
      <template #content>{{ $t('landlord.confirm_regenerate_content') }}</template>
      <template #footer>
        <SecondaryButton @click="showPasswordConfirmModal = false">
          Annuler
        </SecondaryButton>
        <PrimaryButton class="ml-3" @click="regenerateTenantPassword">{{ $t('landlord.regenerate') }}</PrimaryButton>
      </template>
    </ConfirmationModal>

    <!-- Confirmation Modal for Delete Tenant -->
    <ConfirmationModal :show="showDeleteTenantModal" variant="danger" @close="showDeleteTenantModal = false">
      <template #title>{{ $t('landlord.delete_tenant_title') }}</template>
      <template #content>{{ $t('landlord.confirm_delete_tenant') }}</template>
      <template #footer>
        <SecondaryButton @click="showDeleteTenantModal = false">
          Annuler
        </SecondaryButton>
        <DangerButton class="ml-3" @click="deleteTenant">{{ $t('landlord.delete_permanently') }}</DangerButton>
      </template>
    </ConfirmationModal>

    <!-- Confirmation Modal for Remove Domain -->
    <ConfirmationModal :show="showRemoveDomainModal" variant="danger" @close="showRemoveDomainModal = false">
      <template #title>{{ $t('landlord.delete_domain_title') }}</template>
      <template #content>{{ $t('landlord.confirm_delete_domain') }}</template>
      <template #footer>
        <SecondaryButton @click="showRemoveDomainModal = false">
          Annuler
        </SecondaryButton>
        <DangerButton class="ml-3" @click="removeDomain">
          Supprimer
        </DangerButton>
      </template>
    </ConfirmationModal>
  </MainNavLayout>
</template>
