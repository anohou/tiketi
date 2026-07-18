<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import { usePage } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import Steering from 'vue-material-design-icons/Steering.vue';
import SeatPassenger from 'vue-material-design-icons/SeatPassenger.vue';
import Phone from 'vue-material-design-icons/Phone.vue';
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue';
import Bus from 'vue-material-design-icons/Bus.vue';

const { exportToExcel, printList } = useExportPrint();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

const props = defineProps({
  crewMembers: {
    type: Object,
    default: () => ({ data: [] }),
  },
});

const search = ref('');
const roleFilter = ref('');
const selectedMember = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);

const form = ref({
  name: '',
  phone: '',
  pin: '',
  role: 'driver',
  license_number: '',
  license_expiry_date: '',
  active: true,
  notes: '',
});

const filteredMembers = computed(() => {
  let members = props.crewMembers?.data || [];

  if (search.value) {
    const searchTerm = search.value.toLowerCase();
    members = members.filter(m =>
      m.name.toLowerCase().includes(searchTerm) ||
      (m.phone || '').toLowerCase().includes(searchTerm) ||
      (m.license_number || '').toLowerCase().includes(searchTerm)
    );
  }

  if (roleFilter.value) {
    members = members.filter(m => m.role === roleFilter.value);
  }

  return members;
});

watch(() => props.crewMembers, (newMembers) => {
  if (selectedMember.value) {
    const updated = newMembers.data.find(m => m.id === selectedMember.value.id);
    if (updated) {
      selectedMember.value = updated;
    }
  }
}, { deep: true });

const isSelected = (member) => selectedMember.value?.id === member.id;

const selectMember = (member) => {
  selectedMember.value = member;
};

const getRoleLabel = (role) => role === 'driver' ? 'Chauffeur' : 'Assistant';
const getRoleColor = (role) => role === 'driver' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800';

const openCreateModal = () => {
  isEditing.value = false;
  form.value = { name: '', phone: '', pin: '', role: 'driver', license_number: '', license_expiry_date: '', active: true, notes: '' };
  errors.value = {};
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedMember.value) return;
  isEditing.value = true;
  form.value = {
    name: selectedMember.value.name,
    phone: selectedMember.value.phone || '',
    pin: '',
    role: selectedMember.value.role,
    license_number: selectedMember.value.license_number || '',
    license_expiry_date: selectedMember.value.license_expiry_date ? selectedMember.value.license_expiry_date.slice(0, 10) : '',
    active: selectedMember.value.active !== false,
    notes: selectedMember.value.notes || '',
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = { name: '', phone: '', pin: '', role: 'driver', license_number: '', license_expiry_date: '', active: true, notes: '' };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('fleet.crew-members.update', selectedMember.value.id)
    : route('fleet.crew-members.store');

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    },
  });
};

const deleteMember = (id) => {
  if (confirm("Êtes-vous sûr de vouloir supprimer ce membre d'équipage ?")) {
    router.delete(route('fleet.crew-members.destroy', id), {
      onSuccess: () => {
        if (selectedMember.value?.id === id) {
          selectedMember.value = null;
        }
      },
    });
  }
};

const crewColumns = {
  name: 'Nom',
  phone: 'Téléphone',
  role: 'Rôle',
  license_number: 'N° Permis',
  active: 'Statut',
};

const handleExport = () => {
  const data = filteredMembers.value.map(m => ({
    ...m,
    role: getRoleLabel(m.role),
    active: m.active ? 'Actif' : 'Inactif',
  }));
  exportToExcel(data, crewColumns, 'equipage');
};

const handlePrint = () => {
  const data = filteredMembers.value.map(m => ({
    ...m,
    role: getRoleLabel(m.role),
    active: m.active ? 'Actif' : 'Inactif',
  }));
  printList(data, crewColumns, "Liste des Membres d'Équipage");
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('fr-FR');
};

const isLicenseExpired = (member) => {
  if (member.role !== 'driver' || !member.license_expiry_date) return false;
  return new Date(member.license_expiry_date).setHours(0,0,0,0) < new Date().setHours(0,0,0,0);
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 rounded-xl">
              <AccountHardHat class="text-emerald-600" :size="28" />
            </div>
            Gestion de l'Équipage
          </h1>
          <p class="text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1">Chauffeurs et assistants</p>
        </div>
        <div class="flex gap-2">
          <button @click="openCreateModal" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
            <Plus class="inline mr-1" :size="18" /> Nouveau Membre
          </button>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <SettingsMenu v-if="isAdmin" />
          <FleetMenu v-else />
        </div>

        <!-- Middle Column - Members List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200 dark:border-slate-800 p-3 bg-gradient-to-r from-slate-50 to-emerald-50/40 dark:from-slate-950 dark:to-emerald-950/20 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input
                    type="text"
                    v-model="search"
                    placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-emerald-400 text-sm dark:bg-slate-950 dark:text-slate-100"
                  />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors shrink-0" title="Nouveau Membre">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons
                  :disabled="filteredMembers.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
              <div class="flex items-center justify-between">
                <select
                  v-model="roleFilter"
                  class="px-2 py-1 border border-slate-200 dark:border-slate-700 rounded text-[11px] focus:outline-none focus:border-emerald-400 dark:bg-slate-950 dark:text-slate-100"
                >
                  <option value="">Tous les rôles</option>
                  <option value="driver">Chauffeurs</option>
                  <option value="assistant">Assistants</option>
                </select>
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredMembers.length === 0" class="p-4 text-center text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400">
                Aucun membre d'équipage trouvé.
              </div>
              <div v-else>
                <div
                  v-for="member in filteredMembers"
                  :key="member.id"
                  @click="selectMember(member)"
                  class="p-3 cursor-pointer transition-colors border-b border-gray-50 dark:border-slate-800/30 dark:border-slate-800/30 last:border-0"
                  :class="[isSelected(member) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800']"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex items-center gap-3">
                      <div :class="[
                        'w-9 h-9 rounded-full flex items-center justify-center shrink-0',
                        member.role === 'driver' ? 'bg-blue-100' : 'bg-purple-100'
                      ]">
                        <component
                          :is="member.role === 'driver' ? Steering : SeatPassenger"
                          :class="member.role === 'driver' ? 'text-blue-600' : 'text-purple-600'"
                          :size="18"
                        />
                      </div>
                      <div>
                        <h3 :class="['font-semibold', isSelected(member) ? 'text-emerald-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200']">
                          {{ member.name }}
                        </h3>
                        <p class="text-[10px] text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-0.5">{{ member.phone || 'Pas de téléphone' }}</p>
                      </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <span :class="['px-2 py-0.5 rounded-full text-[9px] font-medium', getRoleColor(member.role)]">
                        {{ getRoleLabel(member.role) }}
                      </span>
                      <span v-if="member.role === 'driver' && member.license_expiry_date && isLicenseExpired(member)" class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-rose-100 text-rose-800 text-center">
                        Permis exp.
                      </span>
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[9px] font-medium',
                        member.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                      ]">
                        {{ member.active ? 'Actif' : 'Inactif' }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Details -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedMember" class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500 dark:text-slate-400 dark:text-slate-500">
            <AccountHardHat class="h-16 w-16 text-slate-300 mb-4" />
            <p class="text-lg">Sélectionnez un membre pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-emerald-600 hover:text-emerald-700 font-medium">
              ou ajoutez un nouveau membre
            </button>
          </div>

          <!-- Details -->
          <div v-else class="space-y-4">
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-6">
              <!-- Header -->
              <div class="flex justify-between items-start mb-6">
                <div class="flex items-center gap-4">
                  <div :class="[
                    'w-14 h-14 rounded-full flex items-center justify-center',
                    selectedMember.role === 'driver' ? 'bg-blue-100' : 'bg-purple-100'
                  ]">
                    <component
                      :is="selectedMember.role === 'driver' ? Steering : SeatPassenger"
                      :class="selectedMember.role === 'driver' ? 'text-blue-600' : 'text-purple-600'"
                      :size="28"
                    />
                  </div>
                  <div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-slate-200 dark:text-slate-200">{{ selectedMember.name }}</h2>
                    <span :class="['inline-flex items-center px-3 py-0.5 rounded-full text-xs font-medium mt-1', getRoleColor(selectedMember.role)]">
                      {{ getRoleLabel(selectedMember.role) }}
                    </span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span :class="[
                    'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                    selectedMember.active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                  ]">
                    {{ selectedMember.active ? 'Actif' : 'Inactif' }}
                  </span>
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 dark:bg-blue-950/30 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteMember(selectedMember.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Info Grid -->
              <div class="grid grid-cols-2 gap-6">
                <div>
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">TÉLÉPHONE</span>
                  <div class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-slate-100">
                    <Phone class="text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500" :size="18" />
                    {{ selectedMember.phone || 'Non renseigné' }}
                  </div>
                </div>
                <div>
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">N° PERMIS</span>
                  <div class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-slate-100">
                    <CardAccountDetails class="text-gray-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500" :size="18" />
                    {{ selectedMember.license_number || 'Non renseigné' }}
                  </div>
                </div>
                <div v-if="selectedMember.role === 'driver'">
                  <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">EXPIRATION PERMIS</span>
                  <div class="flex items-center gap-2 text-lg font-medium">
                    <span :class="[
                      isLicenseExpired(selectedMember) ? 'text-red-600 font-bold' : 'text-gray-900 dark:text-slate-100'
                    ]">
                      {{ selectedMember.license_expiry_date ? formatDate(selectedMember.license_expiry_date) : 'Non renseignée' }}
                      <span v-if="selectedMember.license_expiry_date && isLicenseExpired(selectedMember)" class="text-xs font-bold text-red-600 ml-1">(Expiré)</span>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Current Vehicle Assignment -->
              <div class="mt-6 pt-6 border-t border-gray-100 dark:border-slate-800">
                <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-3">VÉHICULE ACTUEL</span>
                <div v-if="selectedMember.current_assignment" class="flex items-center gap-3 p-3 bg-emerald-50 dark:bg-emerald-950/30 rounded-lg border border-emerald-100">
                  <Bus class="text-emerald-600" :size="22" />
                  <div>
                    <p class="font-semibold text-emerald-800">{{ selectedMember.current_assignment.vehicle?.identifier }}</p>
                    <p class="text-xs text-emerald-600">
                      Depuis le {{ new Date(selectedMember.current_assignment.assigned_from).toLocaleDateString('fr-FR') }}
                    </p>
                  </div>
                </div>
                <div v-else class="p-3 bg-gray-50 rounded-lg border border-gray-100 text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 text-sm">
                  Aucun véhicule assigné actuellement
                </div>
              </div>

              <!-- Notes -->
              <div v-if="selectedMember.notes" class="mt-6 pt-6 border-t border-gray-100 dark:border-slate-800">
                <span class="text-xs text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-2">NOTES</span>
                <p class="text-gray-700 dark:text-slate-300 dark:text-slate-300 text-sm">{{ selectedMember.notes }}</p>
              </div>
            </div>

            <!-- Stats -->
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 shadow-sm p-4">
              <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-blue-700">{{ selectedMember.vehicle_assignments_count || 0 }}</p>
                  <p class="text-xs text-blue-600">Affectations totales</p>
                </div>
                <div class="bg-emerald-50 dark:bg-emerald-950/30 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-emerald-700">{{ selectedMember.current_assignment ? '1' : '0' }}</p>
                  <p class="text-xs text-emerald-600">Affectation en cours</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? "Modifier le Membre" : "Nouveau Membre d'Équipage" }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="name" value="Nom complet" />
            <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Koné Ibrahim" />
            <InputError :message="errors.name" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="phone" value="Téléphone" />
              <TextInput v-model="form.phone" id="phone" class="w-full" placeholder="Ex: +225 07 00 00 00" />
              <InputError :message="errors.phone" />
            </div>
            <div>
              <InputLabel for="role" value="Rôle" />
              <select
                id="role"
                v-model="form.role"
                class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              >
                <option value="driver">Chauffeur</option>
                <option value="assistant">Assistant</option>
              </select>
              <InputError :message="errors.role" />
            </div>
          </div>

          <div>
            <InputLabel for="pin" :value="isEditing ? 'Nouveau code PIN (laisser vide pour conserver)' : 'Code PIN'" />
            <TextInput
              v-model="form.pin"
              id="pin"
              type="password"
              inputmode="numeric"
              autocomplete="new-password"
              class="w-full"
              placeholder="6 à 12 chiffres"
            />
            <p class="mt-1 text-xs text-gray-500">Le changement du PIN déconnecte automatiquement tous les appareils.</p>
            <InputError :message="errors.pin" />
          </div>

          <div v-if="form.role === 'driver'" class="grid grid-cols-2 gap-4">
            <div>
              <InputLabel for="license_number" value="Numéro de Permis" />
              <TextInput v-model="form.license_number" id="license_number" class="w-full" placeholder="Ex: CI-12345678" />
              <InputError :message="errors.license_number" />
            </div>
            <div>
              <InputLabel for="license_expiry_date" value="Date d'expiration du permis" />
              <TextInput v-model="form.license_expiry_date" id="license_expiry_date" type="date" class="w-full" />
              <InputError :message="errors.license_expiry_date" />
            </div>
          </div>

          <div class="flex items-center">
            <label class="flex items-center text-sm text-gray-700 dark:text-slate-300 dark:text-slate-300 cursor-pointer">
              <input v-model="form.active" type="checkbox" class="rounded border-gray-300 text-emerald-600" />
              <span class="ml-2">Membre actif</span>
            </label>
          </div>

          <div>
            <InputLabel for="notes" value="Notes (optionnel)" />
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              placeholder="Informations complémentaires..."
            ></textarea>
            <InputError :message="errors.notes" />
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeModal">Annuler</SecondaryButton>
        <PrimaryButton class="ml-3" @click="submit" :disabled="processing">
          {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
        </PrimaryButton>
      </template>
    </DialogModal>
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
</style>
