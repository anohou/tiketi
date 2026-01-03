<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Account from 'vue-material-design-icons/Account.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Check from 'vue-material-design-icons/Check.vue';
import Eye from 'vue-material-design-icons/Eye.vue';
import EyeOff from 'vue-material-design-icons/EyeOff.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';

const { exportToExcel, printList } = useExportPrint();

const props = defineProps({
  users: {
    type: Object,
    default: () => ({ data: [] })
  },
  stations: {
    type: Array,
    default: () => []
  }
});

// State
const search = ref('');
const roleFilter = ref('');
const selectedUser = ref(null);
const processing = ref(false);
const errors = ref({});
const showModal = ref(false);
const isEditing = ref(false);
const activeTab = ref('assignments');
const passwordCopied = ref(false);
const showPassword = ref(false);

// Assignment modal state
const showAssignmentModal = ref(false);
const isEditingAssignment = ref(false);
const editingAssignment = ref(null);
const assignmentForm = ref({
  station_id: ''
});

// Reset Password state
const showResetPasswordModal = ref(false);
const newPassword = ref('');
const newPasswordCopied = ref(false);
const passwordSaved = ref(false);

const form = ref({
  name: '',
  email: '',
  telephone: '',
  role: 'seller',
  password: '',
  password_confirmation: ''
});

// Computed
const filteredUsers = computed(() => {
  let users = props.users?.data || [];
  
  // Filter by role
  if (roleFilter.value) {
    users = users.filter(user => user.role === roleFilter.value);
  }
  
  // Filter by search term
  if (search.value) {
    const searchTerm = search.value.toLowerCase();
    users = users.filter(user =>
      user.name.toLowerCase().includes(searchTerm) ||
      user.email.toLowerCase().includes(searchTerm) ||
      user.telephone?.toLowerCase().includes(searchTerm)
    );
  }
  
  return users;
});

// Get stations not already assigned to the user
const availableStations = computed(() => {
  if (!selectedUser.value) return props.stations;
  const assignedIds = new Set(
    (selectedUser.value.station_assignments || []).map(a => a.station_id)
  );
  return props.stations.filter(s => !assignedIds.has(s.id));
});

// Watchers
watch(() => props.users, (newUsers) => {
  if (selectedUser.value) {
    const updatedUser = newUsers.data.find(u => u.id === selectedUser.value.id);
    if (updatedUser) {
      selectedUser.value = updatedUser;
    }
  }
}, { deep: true });

// Methods
const isSelected = (user) => {
  if (!selectedUser.value) return false;
  return selectedUser.value.id === user.id;
};

const selectUser = (user) => {
  selectedUser.value = user;
  activeTab.value = 'assignments';
};

// Generate a random password
// Generate a random password (alphanumeric only)
const generatePassword = () => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  let password = '';
  
  // 10 alphanumeric chars
  for (let i = 0; i < 10; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  
  return password;
};

// Copy password to clipboard
const copyPasswordToClipboard = async () => {
  if (!form.value.password) return;
  
  try {
    await navigator.clipboard.writeText(form.value.password);
    passwordCopied.value = true;
    setTimeout(() => {
      passwordCopied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy password:', err);
  }
};

const openCreateModal = () => {
  isEditing.value = false;
  const generatedPassword = generatePassword();
  form.value = {
    name: '',
    email: '',
    telephone: '',
    role: 'seller',
    password: generatedPassword,
    password_confirmation: generatedPassword
  };
  errors.value = {};
  passwordCopied.value = false;
  showPassword.value = false;
  showModal.value = true;
};

const openEditModal = () => {
  if (!selectedUser.value) return;
  isEditing.value = true;
  form.value = {
    name: selectedUser.value.name,
    email: selectedUser.value.email,
    telephone: selectedUser.value.telephone || '',
    role: selectedUser.value.role,
    password: '',
    password_confirmation: ''
  };
  errors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  form.value = {
    name: '',
    email: '',
    telephone: '',
    role: 'seller',
    password: '',
    password_confirmation: ''
  };
  errors.value = {};
};

const submit = () => {
  processing.value = true;
  errors.value = {};

  const url = isEditing.value
    ? route('admin.users.update', selectedUser.value.id)
    : route('admin.users.store');

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

const deleteUser = (id) => {
  if (confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')) {
    router.delete(route('admin.users.destroy', id), {
      onSuccess: () => {
        if (selectedUser.value?.id === id) {
          selectedUser.value = null;
        }
      },
    });
  }
};

// Assignment methods
const openAssignmentModal = () => {
  isEditingAssignment.value = false;
  editingAssignment.value = null;
  assignmentForm.value = { station_id: '' };
  errors.value = {};
  showAssignmentModal.value = true;
};

const openEditAssignmentModal = (assignment) => {
  isEditingAssignment.value = true;
  editingAssignment.value = assignment;
  assignmentForm.value = { station_id: assignment.station_id };
  errors.value = {};
  showAssignmentModal.value = true;
};

const closeAssignmentModal = () => {
  showAssignmentModal.value = false;
  isEditingAssignment.value = false;
  editingAssignment.value = null;
  assignmentForm.value = { station_id: '' };
  errors.value = {};
};

const addAssignment = () => {
  if (!selectedUser.value || !assignmentForm.value.station_id) return;
  processing.value = true;
  
  if (isEditingAssignment.value && editingAssignment.value) {
    // Update existing assignment
    router.put(route('admin.assignments.update', editingAssignment.value.id), {
      user_id: selectedUser.value.id,
      station_id: assignmentForm.value.station_id,
      active: editingAssignment.value.active
    }, {
      preserveScroll: true,
      onSuccess: () => {
        processing.value = false;
        closeAssignmentModal();
      },
      onError: (err) => {
        processing.value = false;
        errors.value = err;
      }
    });
  } else {
    // Create new assignment
    router.post(route('admin.assignments.store'), {
      user_id: selectedUser.value.id,
      station_id: assignmentForm.value.station_id
    }, {
      preserveScroll: true,
      onSuccess: () => {
        closeAssignmentModal();
        processing.value = false; // Ensure processing is reset
      },
      onError: (err) => {
        processing.value = false;
        console.error(err);
        errors.value = err; 
      }
    });
  }
};

const openResetPasswordModal = () => {
  newPassword.value = generatePassword();
  newPasswordCopied.value = false;
  passwordSaved.value = false;
  showResetPasswordModal.value = true;
};

const copyNewPassword = async () => {
  try {
    await navigator.clipboard.writeText(newPassword.value);
    newPasswordCopied.value = true;
    setTimeout(() => {
      newPasswordCopied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy password:', err);
  }
};

const saveNewPassword = () => {
  processing.value = true;
  router.put(route('admin.users.update', selectedUser.value.id), {
    name: selectedUser.value.name,
    email: selectedUser.value.email,
    telephone: selectedUser.value.telephone,
    role: selectedUser.value.role,
    password: newPassword.value,
    password_confirmation: newPassword.value
  }, {
    preserveScroll: true,
    onSuccess: () => {
      passwordSaved.value = true;
      processing.value = false;
    },
    onError: () => {
      processing.value = false;
    }
  });
};

const removeAssignment = (assignmentId) => {
  if (!confirm('Retirer cette affectation ?')) return;
  router.delete(route('admin.assignments.destroy', assignmentId), {
    preserveScroll: true
  });
};

const toggleAssignmentActive = (assignment) => {
  router.put(route('admin.assignments.update', assignment.id), {
    station_id: assignment.station_id,
    active: !assignment.active
  }, {
    preserveScroll: true
  });
};

const toggleUserActive = (user, event) => {
  let targetUser = user;
  let targetEvent = event;

  // Handle case where first arg is Event (implicit call)
  if (user && user.target && !user.id) {
    targetEvent = user;
    targetUser = selectedUser.value;
  } else if (!user) {
    targetUser = selectedUser.value;
  }

  if (!targetUser) return;

  const action = targetUser.active !== false ? 'désactiver' : 'activer';
  
  if (!confirm(`Êtes-vous sûr de vouloir ${action} cet utilisateur ?`)) {
    if (targetEvent && targetEvent.target) {
      targetEvent.target.checked = !targetEvent.target.checked;
    }
    return;
  }

  router.put(route('admin.users.toggle-active', targetUser.id), {}, {
    preserveScroll: true,
    onError: () => {
      if (targetEvent && targetEvent.target) {
        targetEvent.target.checked = !targetEvent.target.checked;
      }
    }
  });
};

const getRoleLabel = (role) => {
  const labels = {
    admin: 'Administrateur',
    supervisor: 'Superviseur',
    seller: 'Vendeur'
  };
  return labels[role] || role;
};

const getRoleColor = (role) => {
  const colors = {
    admin: 'bg-red-100 text-red-800',
    supervisor: 'bg-blue-100 text-blue-800',
    seller: 'bg-green-100 text-green-800'
  };
  return colors[role] || 'bg-gray-100 text-gray-800';
};

// Export/Print configuration
const userColumns = {
  name: 'Nom',
  email: 'Email',
  telephone: 'Téléphone',
  role: 'Rôle',
  active: 'Actif'
};

const handleExport = () => {
  const data = filteredUsers.value.map(user => ({
    ...user,
    role: getRoleLabel(user.role),
    active: user.active !== false
  }));
  exportToExcel(data, userColumns, 'utilisateurs');
};

const handlePrint = () => {
  const data = filteredUsers.value.map(user => ({
    ...user,
    role: getRoleLabel(user.role),
    active: user.active !== false
  }));
  printList(data, userColumns, 'Liste des Utilisateurs');
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
              <AccountMultiple class="text-green-600" :size="28" />
            </div>
            Gestion des Utilisateurs
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

        <!-- Middle Column - Users List -->
        <div class="col-span-12 md:col-span-4 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-orange-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-orange-400" />
                </div>
                <button @click="openCreateModal" class="p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors" title="Nouvel Utilisateur">
                  <Plus class="h-5 w-5" />
                </button>
              </div>
              <!-- Role Filter -->
              <div class="flex gap-1 overflow-x-auto pb-1 no-scrollbar">
                <button 
                  @click="roleFilter = ''"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === '' ? 'bg-green-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'
                  ]"
                >
                  Tous
                </button>
                <button 
                  @click="roleFilter = 'admin'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'admin' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-700 hover:bg-purple-100'
                  ]"
                >
                  Admin
                </button>
                <button 
                  @click="roleFilter = 'supervisor'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'supervisor' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-700 hover:bg-blue-100'
                  ]"
                >
                  Superviseur
                </button>
                <button 
                  @click="roleFilter = 'seller'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'seller' ? 'bg-gray-600 text-white' : 'bg-gray-50 text-gray-700 hover:bg-gray-100'
                  ]"
                >
                  Vendeur
                </button>
              </div>
              <div class="flex justify-end mt-2">
                <ExportPrintButtons 
                  :disabled="filteredUsers.length === 0"
                  small
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredUsers.length === 0" class="p-4 text-center text-gray-500">
                Aucun utilisateur trouvé.
              </div>
              <div v-else>
                <div v-for="user in filteredUsers" :key="user.id" 
                  @click="selectUser(user)"
                  :class="[
                    'p-3 cursor-pointer transition-colors border-b border-gray-50 last:border-0',
                    user.active === false ? 'opacity-60' : ''
                  ]"
                  :style="{
                    backgroundColor: isSelected(user) ? '#f0fdf4' : '#ffffff',
                    borderLeft: isSelected(user) ? '4px solid #16a34a' : '4px solid #fed7aa'
                  }"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <h3 :class="['text-sm font-semibold truncate', isSelected(user) ? 'text-green-800' : 'text-gray-800', user.active === false ? 'line-through' : '']">{{ user.name }}</h3>
                        <span v-if="user.active === false" class="px-1.5 py-0.5 bg-red-100 text-red-600 text-[8px] rounded shrink-0">Inactif</span>
                      </div>
                      <p class="text-[10px] text-gray-500 mt-1 truncate">{{ user.email }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-2">
                      <!-- Role Badge -->
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[9px] font-medium',
                        user.role === 'admin' ? 'bg-purple-100 text-purple-800' : 
                        user.role === 'supervisor' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'
                      ]">
                        {{ user.role }}
                      </span>
                      <!-- Active Toggle in List -->
                      <label @click.stop class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                        <input 
                          type="checkbox" 
                          :checked="user.active !== false"
                          @change="toggleUserActive(user, $event)"
                          class="sr-only peer" 
                        />
                        <div class="w-8 h-4 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-green-600"></div>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column - Workspace -->
        <div class="col-span-12 md:col-span-6 h-full overflow-y-auto custom-scrollbar pb-20">
          <!-- Empty State -->
          <div v-if="!selectedUser" class="bg-white rounded-lg border border-orange-200 shadow-sm p-8 text-center h-full flex flex-col items-center justify-center text-gray-500">
            <Account class="h-16 w-16 text-orange-200 mb-4" />
            <p class="text-lg">Sélectionnez un utilisateur pour voir les détails</p>
            <button @click="openCreateModal" class="mt-4 text-green-600 hover:text-green-700 font-medium">
              ou créez un nouvel utilisateur
            </button>
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-6">
                <h2 class="text-2xl font-bold text-gray-800">{{ selectedUser.name }}</h2>
                <div class="flex gap-2">
                  <button @click="openEditModal" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Modifier">
                    <Pencil class="h-5 w-5" />
                  </button>
                  <button @click="deleteUser(selectedUser.id)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 class="h-5 w-5" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-6">
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">EMAIL</span>
                  <div class="text-lg font-medium text-gray-900 break-all">
                    {{ selectedUser.email }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">TÉLÉPHONE</span>
                  <div class="text-lg font-medium text-gray-900">
                    {{ selectedUser.telephone || 'Non renseigné' }}
                  </div>
                </div>
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">RÔLE</span>
                  <div>
                    <span :class="[
                       'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                      getRoleColor(selectedUser.role)
                    ]">
                      {{ getRoleLabel(selectedUser.role) }}
                    </span>
                  </div>
                </div>
                
                <!-- Active Status -->
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">STATUT</span>
                  <div class="flex items-center gap-3">
                    <span :class="[
                      'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                      selectedUser.active !== false ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    ]">
                      {{ selectedUser.active !== false ? 'Actif' : 'Inactif' }}
                    </span>
                    <label class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                      <input 
                        type="checkbox" 
                        :checked="selectedUser.active !== false"
                        @change="toggleUserActive(null, $event)"
                        class="sr-only peer" 
                      />
                      <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                    </label>
                  </div>
                </div>

                <!-- Password Reset -->
                <div class="col-span-12">
                  <span class="text-xs text-gray-500 uppercase tracking-wider font-bold block mb-2">SÉCURITÉ</span>
                  <button 
                    @click="openResetPasswordModal"
                    class="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium w-full md:w-auto justify-center"
                  >
                    <Refresh class="h-4 w-4" />
                    Générer un nouveau mot de passe
                  </button>
                </div>
              </div>
            </div>

            <!-- Related Tables - Tabbed Section -->
            <div class="bg-white rounded-lg border border-orange-200 shadow-sm overflow-hidden">
              <!-- Tabs Header -->
              <div class="flex border-b border-orange-200 bg-gradient-to-r from-green-50 to-orange-50/30">
                <button 
                  @click="activeTab = 'assignments'"
                  :class="[
                    'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                    activeTab === 'assignments' 
                      ? 'border-green-600 text-green-700 bg-white' 
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  ]"
                >
                  <OfficeBuilding class="h-4 w-4" />
                  Affectations ({{ (selectedUser.station_assignments || []).length }})
                </button>
              </div>

              <!-- Tab Content -->
              <div class="p-4">
                <!-- Assignments Tab -->
                <div v-if="activeTab === 'assignments'">
                  <!-- Add Button -->
                  <div class="flex justify-between items-center mb-4">
                    <p class="text-sm text-gray-500">Gares où cet utilisateur peut vendre des billets</p>
                    <button 
                      @click="openAssignmentModal" 
                      class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors"
                    >
                      <Plus class="h-4 w-4" />
                      Ajouter
                    </button>
                  </div>

                  <!-- Empty State -->
                  <div v-if="(selectedUser.station_assignments || []).length === 0" class="text-center py-8 text-gray-400">
                    <OfficeBuilding class="h-12 w-12 mx-auto mb-2 opacity-50" />
                    <p>Aucune gare affectée</p>
                  </div>

                  <!-- Assignment List -->
                  <div v-else class="space-y-2">
                    <div 
                      v-for="assignment in selectedUser.station_assignments" 
                      :key="assignment.id"
                      :class="[
                        'flex items-center justify-between p-3 rounded-lg border',
                        assignment.active !== false ? 'bg-gray-50 border-gray-100' : 'bg-gray-100 border-gray-200 opacity-60'
                      ]"
                    >
                      <div class="flex items-center gap-3 border-orange-50">
                        <div :class="[
                          'w-8 h-8 flex items-center justify-center rounded-full',
                          assignment.active !== false ? 'bg-orange-100 text-orange-700' : 'bg-gray-200 text-gray-500'
                        ]">
                          <OfficeBuilding class="h-4 w-4" />
                        </div>
                        <div>
                          <p :class="['font-medium text-sm', assignment.active !== false ? 'text-gray-800' : 'text-gray-500']">
                            {{ assignment.station?.name }}
                          </p>
                          <p class="text-[10px] text-gray-500">{{ assignment.station?.city }}</p>
                        </div>
                      </div>
                      <div class="flex items-center gap-2">
                        <!-- Edit Button -->
                        <button 
                          @click="openEditAssignmentModal(assignment)" 
                          class="p-1.5 text-blue-400 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors"
                          title="Modifier"
                        >
                          <Pencil class="h-4 w-4" />
                        </button>
                        <!-- Active Toggle -->
                        <label class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                          <input 
                            type="checkbox" 
                            :checked="assignment.active !== false"
                            @change="toggleAssignmentActive(assignment)"
                            class="sr-only peer" 
                          />
                          <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600"></div>
                        </label>
                        <!-- Delete Button -->
                        <button 
                          @click="removeAssignment(assignment.id)" 
                          class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                          title="Retirer"
                        >
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
      </div>
    </div>

    <!-- User Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? 'Modifier l\'Utilisateur' : 'Nouvel Utilisateur' }}
      </template>
      <template #content>
        <div class="space-y-4">
          <!-- Password field when creating (hidden by default with show/copy/regenerate) -->
          <div v-if="!isEditing" class="p-4 bg-gray-50 rounded-lg border border-gray-100 mb-4">
            <InputLabel for="password" value="Mot de passe généré" class="text-green-700" />
            <div class="flex gap-2 mt-1">
              <div class="relative flex-1">
                <TextInput 
                  v-model="form.password" 
                  id="password" 
                  :type="showPassword ? 'text' : 'password'" 
                  class="w-full font-mono pr-20 bg-white" 
                  readonly 
                />
                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                  <button 
                    type="button"
                    @click="showPassword = !showPassword"
                    class="p-1 text-gray-400 hover:text-gray-600 transition-colors"
                    :title="showPassword ? 'Masquer' : 'Afficher'"
                  >
                    <EyeOff v-if="showPassword" class="h-5 w-5" />
                    <Eye v-else class="h-5 w-5" />
                  </button>
                  <button 
                    type="button"
                    @click="copyPasswordToClipboard"
                    class="p-1 text-gray-400 hover:text-green-600 transition-colors"
                    :title="passwordCopied ? 'Copié!' : 'Copier'"
                  >
                    <Check v-if="passwordCopied" class="h-5 w-5 text-green-600" />
                    <ContentCopy v-else class="h-5 w-5" />
                  </button>
                </div>
              </div>
              <button 
                type="button"
                @click="() => { const pw = generatePassword(); form.password = pw; form.password_confirmation = pw; passwordCopied = false; }"
                class="p-2 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg text-gray-600 transition-colors"
                title="Générer un nouveau mot de passe"
              >
                <Refresh class="h-5 w-5" />
              </button>
            </div>
            <p v-if="passwordCopied" class="text-xs text-green-600 mt-1 font-medium">Mot de passe copié!</p>
            <InputError :message="errors.password" />
          </div>


          <div>
            <InputLabel for="name" value="Nom complet" />
            <TextInput v-model="form.name" id="name" class="w-full" placeholder="Ex: Jean Dupont" />
            <InputError :message="errors.name" />
          </div>

          <div>
            <InputLabel for="email" value="Adresse email" />
            <TextInput v-model="form.email" id="email" type="email" class="w-full" placeholder="Ex: jean.dupont@example.com" />
            <InputError :message="errors.email" />
          </div>

          <div>
            <InputLabel for="telephone" value="Numéro de téléphone" />
            <TextInput v-model="form.telephone" id="telephone" type="tel" class="w-full" placeholder="Ex: 06 12 34 56 78" />
            <InputError :message="errors.telephone" />
          </div>

          <div>
            <InputLabel for="role" value="Rôle" />
            <select
              id="role"
              v-model="form.role"
              class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="seller">Vendeur</option>
              <option value="supervisor">Superviseur</option>
              <option value="admin">Administrateur</option>
            </select>
            <InputError :message="errors.role" />
          </div>

          <div v-if="isEditing" class="border-t border-gray-100 pt-4 mt-4">
            <h3 class="text-sm font-medium text-gray-900 mb-3">Sécurité</h3>
            <div class="space-y-4">
              <!-- Password field when editing (hidden, optional) -->
              <div>
                <InputLabel for="password" value="Nouveau mot de passe (optionnel)" />
                <TextInput v-model="form.password" id="password" type="password" class="w-full"
                  placeholder="Laisser vide pour ne pas changer" />
                <InputError :message="errors.password" />
              </div>

              <div v-if="form.password">
                <InputLabel for="password_confirmation" value="Confirmer le mot de passe" />
                <TextInput v-model="form.password_confirmation" id="password_confirmation" type="password" class="w-full" 
                  placeholder="Répéter le mot de passe" />
                <InputError :message="errors.password_confirmation" />
              </div>
            </div>
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

    <!-- Assignment Modal -->
    <DialogModal :show="showAssignmentModal" @close="closeAssignmentModal" maxWidth="md">
      <template #title>{{ isEditingAssignment ? 'Modifier l\'Affectation' : 'Affecter une Gare' }}</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="station_id" value="Sélectionner une gare" />
            <select
              id="station_id"
              v-model="assignmentForm.station_id"
              class="w-full px-3 py-2 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm"
              required
            >
              <option value="">Choisir une gare...</option>
              <!-- When editing, show all stations (including current one) -->
              <option 
                v-for="station in (isEditingAssignment ? stations : availableStations)" 
                :key="station.id" 
                :value="station.id"
              >
                {{ station.name }} - {{ station.city }}
              </option>
            </select>
            <InputError :message="errors.station_id" />
          </div>
          
          <div v-if="!isEditingAssignment && availableStations.length === 0" class="text-center py-4 text-gray-500">
            <p>Toutes les gares sont déjà affectées à cet utilisateur.</p>
          </div>
        </div>
      </template>
      <template #footer>
        <SecondaryButton @click="closeAssignmentModal">Annuler</SecondaryButton>
        <PrimaryButton 
          class="ml-3" 
          @click="addAssignment" 
          :disabled="processing || !assignmentForm.station_id"
        >
          {{ isEditingAssignment ? 'Mettre à jour' : 'Affecter' }}
        </PrimaryButton>
      </template>
    </DialogModal>

    <!-- Reset Password Modal -->
    <DialogModal :show="showResetPasswordModal" @close="showResetPasswordModal = false" maxWidth="md">
      <template #title>Générer un nouveau mot de passe</template>
      <template #content>
        <div class="space-y-4">
          <div v-if="passwordSaved" class="p-4 bg-green-50 text-green-700 rounded-lg flex items-center gap-2 mb-4">
            <Check class="h-5 w-5" />
            <p>Le mot de passe a été mis à jour avec succès.</p>
          </div>

          <div class="p-4 bg-gray-50 rounded-lg border border-gray-100">
            <h4 class="text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe</h4>
            <div class="flex gap-2">
              <div class="relative flex-1">
                <TextInput 
                  v-model="newPassword" 
                  type="text" 
                  class="w-full font-mono pr-10 bg-white" 
                  readonly 
                />
                <button 
                  type="button"
                  @click="copyNewPassword"
                  class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-green-600 transition-colors"
                  :title="newPasswordCopied ? 'Copié!' : 'Copier'"
                >
                  <Check v-if="newPasswordCopied" class="h-5 w-5 text-green-600" />
                  <ContentCopy v-else class="h-5 w-5" />
                </button>
              </div>
              <button 
                v-if="!passwordSaved"
                type="button"
                @click="newPassword = generatePassword(); newPasswordCopied = false;"
                class="p-2 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg text-gray-600 transition-colors"
                title="Générer un autre"
              >
                <Refresh class="h-5 w-5" />
              </button>
            </div>
            <p v-if="newPasswordCopied" class="text-xs text-green-600 mt-1 font-medium">Mot de passe copié!</p>
          </div>
          
          <div class="text-sm text-gray-500">
            <p class="flex items-start gap-2" v-if="!passwordSaved">
              <span class="text-orange-500 mt-0.5">⚠️</span>
              En enregistrant, le mot de passe actuel de l'utilisateur sera remplacé par ce nouveau mot de passe. Assurez-vous de le communiquer à l'utilisateur.
            </p>
            <p v-else class="font-medium text-gray-700">
              Veuillez copier le mot de passe ci-dessus avant de fermer cette fenêtre. Il ne sera plus visible après.
            </p>
          </div>
        </div>
      </template>
      <template #footer>
        <template v-if="!passwordSaved">
          <SecondaryButton @click="showResetPasswordModal = false">Annuler</SecondaryButton>
          <PrimaryButton class="ml-3" @click="saveNewPassword" :disabled="processing">
            Enregistrer le nouveau mot de passe
          </PrimaryButton>
        </template>
        <template v-else>
          <PrimaryButton @click="showResetPasswordModal = false">
            Fermer
          </PrimaryButton>
        </template>
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
  background: #fed7aa;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #fdba74;
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
