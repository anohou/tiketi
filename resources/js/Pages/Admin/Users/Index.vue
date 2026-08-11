<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import DialogModal from '@/Components/DialogModal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import AccordionSection from '@/Components/UI/AccordionSection.vue';
import { toastStore } from '@/Stores/toastStore.js';
import ExportPrintButtons from '@/Components/ExportPrintButtons.vue';
import { useExportPrint } from '@/Composables/useExportPrint';

import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Pencil from 'vue-material-design-icons/Pencil.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import Account from 'vue-material-design-icons/Account.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import History from 'vue-material-design-icons/History.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import Refresh from 'vue-material-design-icons/Refresh.vue';
import Check from 'vue-material-design-icons/Check.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import { FULL_PERMISSIONS } from '@/Support/permissions.js';

const { exportToExcel, printList } = useExportPrint();
const page = usePage();

const routePrefix = computed(() => {
  return page.props.auth.user.role === 'supervisor' ? 'supervisor' : 'admin';
});

const props = defineProps({
  users: {
    type: Object,
    default: () => ({ data: [] })
  },
  stations: {
    type: Array,
    default: () => []
  },
  permissions: {
    type: Object,
    default: () => ({ ...FULL_PERMISSIONS })
  },
  hideTripSidebar: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: 'Gestion des Utilisateurs'
  },
  subtitle: {
    type: String,
    default: 'Paramètres du système'
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

// Accordéons (harmonisation plateforme) : tous pliés par défaut
const showAssignments = ref(false);
const showHistory = ref(false);

const resetAccordions = () => {
  showAssignments.value = false;
  showHistory.value = false;
};

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

const showCreatedPasswordModal = ref(false);
const createdPassword = ref('');
const createdPasswordCopied = ref(false);

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
  resetAccordions();
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

const openCreateModal = () => {
  isEditing.value = false;
  form.value = {
    name: '',
    email: '',
    telephone: '',
    role: 'seller',
    password: '',
    password_confirmation: ''
  };
  errors.value = {};
  showModal.value = true;
};

const copyCreatedPasswordToClipboard = async () => {
  if (!createdPassword.value) return;

  try {
    await navigator.clipboard.writeText(createdPassword.value);
    createdPasswordCopied.value = true;
    setTimeout(() => {
      createdPasswordCopied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy password:', err);
  }
};

const closeCreatedPasswordModal = () => {
  showCreatedPasswordModal.value = false;
  createdPassword.value = '';
  createdPasswordCopied.value = false;
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
    ? route(`${routePrefix.value}.users.update`, selectedUser.value.id)
    : route(`${routePrefix.value}.users.store`);

  const method = isEditing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => {
      processing.value = false;
      closeModal();
      setTimeout(() => {
        const generatedPassword = page.props.flash?.created_user_password;
        if (!generatedPassword) return;

        createdPassword.value = generatedPassword;
        createdPasswordCopied.value = false;
        showCreatedPasswordModal.value = true;
      }, 250);
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const showDeleteUserModal = ref(false);
const userIdToDelete = ref(null);

const confirmDeleteUser = (id) => {
  userIdToDelete.value = id;
  showDeleteUserModal.value = true;
};

const deleteUser = () => {
  if (!userIdToDelete.value) return;
  showDeleteUserModal.value = false;
  router.delete(route(`${routePrefix.value}.users.destroy`, userIdToDelete.value), {
    onSuccess: () => {
      if (selectedUser.value?.id === userIdToDelete.value) {
        selectedUser.value = null;
      }
      toastStore.success('Utilisateur supprimé avec succès');
      userIdToDelete.value = null;
    },
  });
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
    router.put(route(`${routePrefix.value}.assignments.update`, editingAssignment.value.id), {
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
    router.post(route(`${routePrefix.value}.assignments.store`), {
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
  router.put(route(`${routePrefix.value}.users.update`, selectedUser.value.id), {
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

const showRemoveAssignmentModal = ref(false);
const assignmentIdToRemove = ref(null);

const confirmRemoveAssignment = (assignmentId) => {
  assignmentIdToRemove.value = assignmentId;
  showRemoveAssignmentModal.value = true;
};

const removeAssignment = () => {
  if (!assignmentIdToRemove.value) return;
  showRemoveAssignmentModal.value = false;
  router.delete(route(`${routePrefix.value}.assignments.destroy`, assignmentIdToRemove.value), {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success('Affectation retirée avec succès');
      assignmentIdToRemove.value = null;
    }
  });
};

const toggleAssignmentActive = (assignment) => {
  router.put(route(`${routePrefix.value}.assignments.update`, assignment.id), {
    station_id: assignment.station_id,
    active: !assignment.active
  }, {
    preserveScroll: true
  });
};

const showToggleActiveModal = ref(false);
const userToToggleActive = ref(null);
const toggleActiveEvent = ref(null);

const confirmToggleUserActive = (user, event) => {
  let targetUser = user;
  let targetEvent = event;

  if (user && user.target && !user.id) {
    targetEvent = user;
    targetUser = selectedUser.value;
  } else if (!user) {
    targetUser = selectedUser.value;
  }

  if (!targetUser) return;

  userToToggleActive.value = targetUser;
  toggleActiveEvent.value = targetEvent;
  showToggleActiveModal.value = true;
};

const cancelToggleUserActive = () => {
  showToggleActiveModal.value = false;
  if (toggleActiveEvent.value && toggleActiveEvent.value.target) {
    toggleActiveEvent.value.target.checked = !toggleActiveEvent.value.target.checked;
  }
  userToToggleActive.value = null;
  toggleActiveEvent.value = null;
};

const toggleUserActive = () => {
  if (!userToToggleActive.value) return;
  const targetUser = userToToggleActive.value;
  const targetEvent = toggleActiveEvent.value;
  
  showToggleActiveModal.value = false;
  
  router.put(route(`${routePrefix.value}.users.toggle-active`, targetUser.id), {}, {
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success(`Statut de l'utilisateur mis à jour`);
      userToToggleActive.value = null;
      toggleActiveEvent.value = null;
    },
    onError: () => {
      if (targetEvent && targetEvent.target) {
        targetEvent.target.checked = !targetEvent.target.checked;
      }
      userToToggleActive.value = null;
      toggleActiveEvent.value = null;
    }
  });
};

const getRoleLabel = (role) => {
  const labels = {
    admin: 'Administrateur',
    supervisor: 'Superviseur',
    seller: 'Vendeur',
    accountant: 'Comptable',
    executive: 'Direction',
    fleet_manager: 'Gestionnaire de flotte'
  };
  return labels[role] || role;
};

const getRoleColor = (role) => {
  const colors = {
    admin: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    supervisor: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    seller: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    accountant: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    executive: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    fleet_manager: 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-400'
  };
  return colors[role] || 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300';
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
  <MainNavLayout :fullHeight="true" :hide-trip-sidebar="hideTripSidebar">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <!-- Header with padding -->
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 dark:text-slate-100 flex items-center gap-3">
            <div class="p-2 bg-emerald-100 dark:bg-emerald-950/50 rounded-2xl shadow-sm">
              <AccountMultiple class="text-emerald-600 dark:text-emerald-400" :size="28" />
            </div>
            {{ props.title }}
          </h1>
          <p class="mt-1 text-gray-500 dark:text-slate-400">{{ props.subtitle }}</p>
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
          <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col h-full overflow-hidden">
            <!-- List Header -->
            <div class="border-b border-slate-200/80 dark:border-slate-800 p-3 bg-gradient-to-r from-emerald-50 via-white to-cyan-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-900/50 shrink-0">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="relative flex-1">
                  <input type="text" v-model="search" placeholder="Rechercher..."
                    class="w-full px-4 py-2 pl-10 pr-4 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:border-emerald-400 text-sm bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-100 shadow-sm" />
                  <Magnify class="absolute left-3 top-2.5 h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                </div>
                <button v-if="permissions.canCreate" @click="openCreateModal" class="p-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-colors shadow-sm shrink-0" title="Nouvel Utilisateur">
                  <Plus class="h-5 w-5" />
                </button>
                <ExportPrintButtons 
                  v-if="permissions.canExport"
                  :disabled="filteredUsers.length === 0"
                  @export="handleExport"
                  @print="handlePrint"
                />
              </div>
              <!-- Role Filter -->
              <div class="flex gap-1 overflow-x-auto pb-1 no-scrollbar">
                <button 
                  @click="roleFilter = ''"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === '' ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                  ]"
                >
                  Tous
                </button>
                <button 
                  @click="roleFilter = 'admin'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'admin' ? 'bg-emerald-600 text-white' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                  ]"
                >
                  Admin
                </button>
                <button 
                  @click="roleFilter = 'supervisor'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'supervisor' ? 'bg-emerald-600 text-white' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                  ]"
                >
                  Superviseur
                </button>
                <button 
                  @click="roleFilter = 'seller'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'seller' ? 'bg-emerald-600 text-white' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                  ]"
                >
                  Vendeur
                </button>
                <button 
                  @click="roleFilter = 'fleet_manager'"
                  :class="[
                    'px-2 py-0.5 text-[10px] rounded-full transition-colors shrink-0',
                    roleFilter === 'fleet_manager' ? 'bg-emerald-600 text-white' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                  ]"
                >
                  Gestionnaire de flotte
                </button>
              </div>

            </div>

            <!-- List Content -->
            <div class="overflow-y-auto flex-1 custom-scrollbar">
              <div v-if="filteredUsers.length === 0" class="flex min-h-72 items-center justify-center p-4">
                <EmptyState
                  title="Aucun utilisateur trouvé"
                  message="Modifiez les critères de recherche ou créez un utilisateur avec le bouton '+'."
                  :icon="Account"
                  plain
                />
              </div>
              <div v-else>
                <div v-for="user in filteredUsers" :key="user.id" 
                  @click="selectUser(user)"
                  :class="[
                    'p-3 cursor-pointer transition-colors border-b border-slate-50 dark:border-slate-800/30 last:border-0 border-l-4',
                    user.active === false ? 'opacity-60' : '',
                    isSelected(user) ? 'bg-emerald-50/50 dark:bg-emerald-950/20 border-l-emerald-500' : 'bg-white dark:bg-slate-900 border-l-slate-200 dark:border-l-slate-800'
                  ]"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <h3 :class="['text-sm font-semibold truncate', isSelected(user) ? 'text-green-800' : 'text-gray-800 dark:text-slate-200 dark:text-slate-200', user.active === false ? 'line-through' : '']">{{ user.name }}</h3>
                        <span v-if="user.active === false" class="px-1.5 py-0.5 bg-rose-100 text-rose-600 text-[8px] rounded shrink-0">Inactif</span>
                      </div>
                      <p class="text-[10px] text-gray-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 mt-1 truncate">{{ user.email }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-2">
                      <!-- Role Badge -->
                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[9px] font-medium',
                        user.role === 'admin' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' : 
                        user.role === 'supervisor' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' :
                        user.role === 'seller' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' :
                        user.role === 'accountant' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' :
                        user.role === 'executive' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' :
                        user.role === 'fleet_manager' ? 'bg-emerald-100 dark:bg-emerald-950/45 text-emerald-800 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                      ]">
                        {{ getRoleLabel(user.role) }}
                      </span>
                      <!-- Active Toggle in List -->
                      <label v-if="permissions.canUpdate" @click.stop class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                        <input 
                          type="checkbox" 
                          :checked="user.active !== false"
                          @change="confirmToggleUserActive(user, $event)"
                          class="sr-only peer" 
                        />
                        <div class="w-8 h-4 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-600"></div>
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
          <div v-if="!selectedUser" class="h-full bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-center">
            <EmptyState
              title="Sélectionnez un utilisateur"
              message="Sélectionnez un utilisateur dans la liste pour consulter ses informations."
              :icon="Account"
              plain
            />
          </div>

          <!-- View Details -->
          <div v-else class="space-y-4">
            <!-- Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-5">
              <!-- Header Row -->
              <div class="flex justify-between items-start mb-4">
                <h2 class="text-xl font-bold text-gray-800 dark:text-slate-200">{{ selectedUser.name }}</h2>
                <div class="flex gap-2">
                  <button v-if="permissions.canUpdate" @click="openEditModal" class="p-2 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-slate-800 rounded-lg transition-colors" title="Modifier">
                    <Pencil :size="20" />
                  </button>
                  <button v-if="permissions.canDelete" @click="confirmDeleteUser(selectedUser.id)" class="p-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800 rounded-lg transition-colors" title="Supprimer">
                    <Trash2 :size="20" />
                  </button>
                </div>
              </div>

              <!-- Details Row -->
              <div class="grid grid-cols-12 gap-4">
                <div class="col-span-6">
                  <span class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">EMAIL</span>
                  <div class="text-base font-medium text-gray-900 dark:text-slate-100 break-all">
                    {{ selectedUser.email }}
                  </div>
                </div>
                <div class="col-span-6">
                  <span class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1">TÉLÉPHONE</span>
                  <div class="text-base font-medium text-gray-900 dark:text-slate-100">
                    {{ selectedUser.telephone || 'Non renseigné' }}
                  </div>
                </div>
                
                <div class="col-span-6 lg:col-span-4">
                  <span class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1.5">RÔLE</span>
                  <div>
                    <span :class="[
                       'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                      getRoleColor(selectedUser.role)
                    ]">
                      {{ getRoleLabel(selectedUser.role) }}
                    </span>
                  </div>
                </div>
                
                <!-- Active Status -->
                <div class="col-span-6 lg:col-span-4">
                  <span class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1.5">STATUT</span>
                  <div class="flex items-center gap-2">
                    <span :class="[
                      'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                      selectedUser.active !== false ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    ]">
                      {{ selectedUser.active !== false ? 'Actif' : 'Inactif' }}
                    </span>
                  <div class="flex items-center gap-2">
                    <label v-if="permissions.canUpdate" class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                      <input 
                        type="checkbox" 
                        :checked="selectedUser.active !== false"
                        @change="confirmToggleUserActive(null, $event)"
                        class="sr-only peer" 
                      />
                      <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                    </label>
                  </div>
                </div>

                <!-- Password Reset -->
                <div class="col-span-12 lg:col-span-4">
                  <span class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wider font-bold block mb-1.5">SÉCURITÉ</span>
                  <div>
                    <button 
                      v-if="permissions.canUpdate"
                      @click="openResetPasswordModal"
                      class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg transition-colors text-xs font-medium w-full justify-center"
                    >
                      <Refresh :size="14" />
                      Réinitialiser le MDP
                    </button>
                  </div>
                </div>
              </div>
            </div>
            </div>

            <!-- Sections liées : 2 accordéons verticaux (harmonisation plateforme) -->
            <div class="space-y-4">
              <!-- 1. Gares d'affectation -->
              <AccordionSection
                v-model:open="showAssignments"
                :icon="MapMarkerRadius"
                title="Gares d'affectation du vendeur"
                :count="(selectedUser.station_assignments || []).length"
                :show-add="permissions.canUpdate"
                add-label="Ajouter une gare"
                @add="openAssignmentModal"
              >
                <div v-if="(selectedUser.station_assignments || []).length === 0" class="text-center py-8 text-slate-400">
                  <OfficeBuilding class="h-12 w-12 mx-auto mb-2 opacity-50" />
                  <p>Aucune gare affectée</p>
                </div>

                <div v-else class="space-y-2">
                  <div
                    v-for="assignment in selectedUser.station_assignments"
                    :key="assignment.id"
                    :class="[
                      'flex items-center justify-between p-3 rounded-lg border',
                      assignment.active !== false
                        ? 'bg-slate-50 dark:bg-slate-950/30 border-slate-100 dark:border-slate-800/50'
                        : 'bg-slate-100 dark:bg-slate-900/30 border-slate-200 dark:border-slate-800/50 opacity-60'
                    ]"
                  >
                    <div class="flex items-center gap-3 min-w-0">
                      <div :class="[
                        'w-8 h-8 flex items-center justify-center rounded-full shrink-0',
                        assignment.active !== false
                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                          : 'bg-slate-200 text-slate-500 dark:bg-slate-800 dark:text-slate-500'
                      ]">
                        <OfficeBuilding :size="16" />
                      </div>
                      <div class="min-w-0">
                        <p :class="['font-medium text-sm truncate', assignment.active !== false ? 'text-slate-800 dark:text-slate-200' : 'text-slate-500 dark:text-slate-500']">
                          {{ assignment.station?.name }}
                        </p>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">{{ assignment.station?.city }}</p>
                      </div>
                    </div>
                    <div v-if="permissions.canUpdate" class="flex items-center gap-2 shrink-0">
                      <!-- Edit Button -->
                      <button
                        @click="openEditAssignmentModal(assignment)"
                        class="p-1.5 text-emerald-500 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-slate-800 rounded transition-colors"
                        title="Modifier"
                      >
                        <Pencil :size="16" />
                      </button>
                      <!-- Active Toggle -->
                      <label class="relative inline-flex items-center cursor-pointer" title="Activer/Désactiver">
                        <input
                          type="checkbox"
                          :checked="assignment.active !== false"
                          @change="toggleAssignmentActive(assignment)"
                          class="sr-only peer"
                        />
                        <div class="w-9 h-5 bg-slate-200 dark:bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600 dark:peer-checked:bg-emerald-600"></div>
                      </label>
                      <!-- Delete Button -->
                      <button
                        @click="confirmRemoveAssignment(assignment.id)"
                        class="p-1.5 text-rose-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800 rounded transition-colors"
                        title="Retirer"
                      >
                        <Trash2 :size="16" />
                      </button>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <!-- 2. Historique d'activité & Ventes -->
              <AccordionSection
                v-model:open="showHistory"
                :icon="History"
                title="Historique d'activité & Ventes"
                :count="selectedUser.sales_count || 0"
              >
                <div class="grid grid-cols-3 gap-3 mb-4">
                  <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-950/30 border border-slate-100 dark:border-slate-800/50 text-center">
                    <p class="text-2xl font-black text-slate-900 dark:text-slate-100">{{ selectedUser.sales_count || 0 }}</p>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase tracking-wider font-bold mt-1">Billets vendus</p>
                  </div>
                  <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-950/30 border border-slate-100 dark:border-slate-800/50 text-center">
                    <p class="text-2xl font-black text-emerald-700 dark:text-emerald-400">{{ (selectedUser.sales_total || 0).toLocaleString() }}</p>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase tracking-wider font-bold mt-1">FCFA encaissés</p>
                  </div>
                  <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-950/30 border border-slate-100 dark:border-slate-800/50 text-center">
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100 break-words">{{ selectedUser.last_sale_at ? new Date(selectedUser.last_sale_at).toLocaleDateString('fr-FR') : '—' }}</p>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase tracking-wider font-bold mt-1">Dernière vente</p>
                  </div>
                </div>
                <div v-if="!selectedUser.sales_count" class="text-center py-4 text-slate-400">
                  <Ticket class="h-10 w-10 mx-auto mb-2 opacity-50" />
                  <p>Aucune vente enregistrée pour cet utilisateur</p>
                </div>
              </AccordionSection>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- User Modal -->
    <DialogModal :show="showModal" @close="closeModal" maxWidth="md">
      <template #title>
        {{ isEditing ? "Modifier l'Utilisateur" : "Nouvel Utilisateur" }}
      </template>
      <template #content>
        <div class="space-y-4">
          <div v-if="!isEditing" class="p-4 bg-gray-50 rounded-lg border border-gray-100 mb-4">
            <p class="text-sm text-gray-600 dark:text-slate-350 dark:text-slate-350">
              L'administration générera automatiquement le mot de passe du compte à la création.
              Vous pourrez le copier juste après l'enregistrement.
            </p>
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

          <div v-if="page.props.auth.user.role !== 'supervisor'">
            <InputLabel for="role" value="Rôle" />
            <select
              id="role"
              v-model="form.role"
              class="w-full px-3 py-1.5 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
              required
            >
              <option value="seller">Vendeur</option>
              <option value="supervisor">Superviseur</option>
              <option value="admin">Administrateur</option>
              <option value="fleet_manager">Gestionnaire de flotte</option>
              <option value="accountant">Comptable</option>
              <option value="executive">Direction</option>
            </select>
            <InputError :message="errors.role" />
          </div>

          <div v-if="isEditing" class="border-t border-gray-100 dark:border-slate-800 pt-4 mt-4">
            <h3 class="text-sm font-medium text-gray-900 dark:text-slate-100 mb-3">Sécurité</h3>
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

    <!-- Created Password Modal -->
    <DialogModal :show="showCreatedPasswordModal" @close="closeCreatedPasswordModal" maxWidth="md">
      <template #title>Utilisateur créé avec succès</template>
      <template #content>
        <div class="text-center py-4">
            <div class="mb-4">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-emerald-100 mb-4">
              <Check class="h-6 w-6 text-emerald-600" />
            </div>
            <h3 class="text-lg leading-6 font-medium text-slate-900 dark:text-slate-100">Compte utilisateur créé</h3>
            <div class="mt-2 px-7 py-3">
              <p class="text-sm text-slate-500 dark:text-slate-400">
                Voici le mot de passe généré pour ce nouvel utilisateur. Copiez-le avant de fermer cette fenêtre.
              </p>
            </div>
          </div>

          <div class="relative mt-2 rounded-md shadow-sm max-w-sm mx-auto">
            <TextInput
              v-model="createdPassword"
              type="text"
              class="w-full pr-12 text-center font-mono bg-slate-50"
              readonly
            />
            <div class="absolute inset-y-0 right-0 flex items-center">
              <button
                type="button"
                @click="copyCreatedPasswordToClipboard"
                class="h-full px-3 text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:text-slate-350 dark:text-slate-350 hover:bg-slate-100 rounded-r-md transition-colors border-l"
                :class="{ 'text-emerald-500 hover:text-emerald-600': createdPasswordCopied }"
                title="Copier"
              >
                <Check v-if="createdPasswordCopied" class="h-5 w-5" />
                <ContentCopy v-else class="h-5 w-5" />
              </button>
            </div>
          </div>

          <p v-if="createdPasswordCopied" class="text-xs text-emerald-600 mt-2 font-medium">
            Mot de passe copié !
          </p>
        </div>
      </template>
      <template #footer>
        <PrimaryButton @click="closeCreatedPasswordModal">
          Terminer
        </PrimaryButton>
      </template>
    </DialogModal>

    <!-- Assignment Modal -->
    <DialogModal :show="showAssignmentModal" @close="closeAssignmentModal" maxWidth="md">
      <template #title>{{ isEditingAssignment ? "Modifier l'Affectation" : "Affecter une Gare" }}</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <InputLabel for="station_id" value="Sélectionner une gare" />
            <select
              id="station_id"
              v-model="assignmentForm.station_id"
              class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 focus:ring-emerald-500 text-sm"
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
          
          <div v-if="!isEditingAssignment && availableStations.length === 0" class="text-center py-4 text-gray-500 dark:text-slate-400">
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
          <div v-if="passwordSaved" class="p-4 bg-emerald-50 text-emerald-700 rounded-lg flex items-center gap-2 mb-4">
            <Check class="h-5 w-5" />
            <p>Le mot de passe a été mis à jour avec succès.</p>
          </div>

          <div class="p-4 bg-slate-50 dark:bg-slate-950 rounded-lg border border-slate-100">
            <h4 class="text-sm font-medium text-slate-700 dark:text-slate-300 dark:text-slate-300 mb-2">Nouveau mot de passe</h4>
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
                  class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-slate-400 dark:text-slate-500 dark:text-slate-500 dark:text-slate-400 dark:text-slate-500 hover:text-emerald-600 transition-colors"
                  :title="newPasswordCopied ? 'Copié!' : 'Copier'"
                >
                  <Check v-if="newPasswordCopied" class="h-5 w-5 text-emerald-600" />
                  <ContentCopy v-else class="h-5 w-5" />
                </button>
              </div>
              <button 
                v-if="!passwordSaved"
                type="button"
                @click="newPassword = generatePassword(); newPasswordCopied = false;"
                class="p-2 bg-white border border-slate-200 hover:bg-slate-50 dark:bg-slate-950 rounded-lg text-slate-600 dark:text-slate-350 dark:text-slate-350 transition-colors"
                title="Générer un autre"
              >
                <Refresh class="h-5 w-5" />
              </button>
            </div>
            <p v-if="newPasswordCopied" class="text-xs text-emerald-600 mt-1 font-medium">Mot de passe copié!</p>
          </div>
          
          <div class="text-sm text-slate-500 dark:text-slate-400">
            <p class="flex items-start gap-2" v-if="!passwordSaved">
              <span class="text-amber-500 mt-0.5">⚠️</span>
              En enregistrant, le mot de passe actuel de l'utilisateur sera remplacé par ce nouveau mot de passe. Assurez-vous de le communiquer à l'utilisateur.
            </p>
            <p v-else class="font-medium text-slate-700 dark:text-slate-300 dark:text-slate-300">
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
    <!-- Custom Confirmation Modals -->
    <ConfirmationModal :show="showDeleteUserModal" variant="danger" @close="showDeleteUserModal = false">
        <template #title>Supprimer l'utilisateur</template>
        <template #content>Êtes-vous sûr de vouloir supprimer cet utilisateur de manière définitive ?</template>
        <template #footer>
            <SecondaryButton @click="showDeleteUserModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="deleteUser">Oui, Supprimer</DangerButton>
        </template>
    </ConfirmationModal>

    <ConfirmationModal :show="showRemoveAssignmentModal" variant="danger" @close="showRemoveAssignmentModal = false">
        <template #title>Retirer l'affectation</template>
        <template #content>Êtes-vous sûr de vouloir retirer cette affectation de gare pour cet utilisateur ?</template>
        <template #footer>
            <SecondaryButton @click="showRemoveAssignmentModal = false">Annuler</SecondaryButton>
            <DangerButton class="ml-3" @click="removeAssignment">Oui, Retirer</DangerButton>
        </template>
    </ConfirmationModal>

    <ConfirmationModal :show="showToggleActiveModal" @close="cancelToggleUserActive">
        <template #title>Modifier le statut</template>
        <template #content>Êtes-vous sûr de vouloir changer le statut d'activation de cet utilisateur ?</template>
        <template #footer>
            <SecondaryButton @click="cancelToggleUserActive">Annuler</SecondaryButton>
            <PrimaryButton class="ml-3" @click="toggleUserActive">Confirmer</PrimaryButton>
        </template>
    </ConfirmationModal>
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
