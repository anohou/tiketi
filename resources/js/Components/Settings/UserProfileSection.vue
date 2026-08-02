<script setup>
import { computed } from 'vue';
import AccountTie from 'vue-material-design-icons/AccountTie.vue';
import BadgeAccount from 'vue-material-design-icons/BadgeAccount.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import AccountSupervisor from 'vue-material-design-icons/AccountSupervisor.vue';
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue';
import Phone from 'vue-material-design-icons/Phone.vue';
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue';

const props = defineProps({
  profile: {
    type: Object,
    default: () => ({}),
  },
});

const roleBadge = computed(() => {
  const map = {
    admin: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    supervisor: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    seller: 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300',
    accountant: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    executive: 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300',
    fleet_manager: 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300',
  };
  return map[props.profile.role] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
});
</script>

<template>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 dark:bg-slate-900 dark:border-slate-800">
    <div class="flex items-center gap-3 mb-5">
      <div class="p-2 bg-emerald-100 rounded-xl dark:bg-emerald-900/25">
        <AccountTie class="text-emerald-600 dark:text-emerald-400" :size="22" />
      </div>
      <div>
        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Votre profil professionnel</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">Informations du compte et de votre périmètre</p>
      </div>
    </div>

    <div class="flex items-center gap-3 mb-5">
      <div class="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 dark:bg-emerald-900/25">
        <span class="text-lg font-black text-emerald-700 dark:text-emerald-300">
          {{ (profile.name || '?').charAt(0).toUpperCase() }}
        </span>
      </div>
      <div class="min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="font-bold text-slate-900 dark:text-slate-100">{{ profile.name }}</span>
          <span :class="['inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold', roleBadge]">{{ profile.roleLabel }}</span>
        </div>
        <div class="flex items-center gap-1.5 mt-0.5">
          <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold',
            profile.active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400']">
            <span :class="['w-1.5 h-1.5 rounded-full', profile.active ? 'bg-emerald-500' : 'bg-slate-400']" />
            {{ profile.statusLabel }}
          </span>
        </div>
      </div>
    </div>

    <div class="space-y-3">
      <div v-if="profile.email" class="flex items-center gap-3">
        <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
          <EmailOutline :size="16" class="text-slate-500 dark:text-slate-400" />
        </div>
        <div class="min-w-0">
          <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Email</div>
          <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">{{ profile.email }}</div>
        </div>
      </div>
      <div v-if="profile.telephone" class="flex items-center gap-3">
        <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
          <Phone :size="16" class="text-slate-500 dark:text-slate-400" />
        </div>
        <div class="min-w-0">
          <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Téléphone</div>
          <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">{{ profile.telephone }}</div>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <div class="p-1.5 bg-slate-100 rounded-lg shrink-0 dark:bg-slate-800">
          <BadgeAccount :size="16" class="text-slate-500 dark:text-slate-400" />
        </div>
        <div class="min-w-0">
          <div class="text-[11px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Fonction</div>
          <div class="text-sm font-semibold text-slate-800 mt-0.5 dark:text-slate-200">{{ profile.roleLabel }}</div>
        </div>
      </div>
    </div>

    <template v-if="profile.stations?.length">
      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-6 mb-3 dark:text-slate-500">Mes gares</h3>
      <div class="space-y-2">
        <div
          v-for="station in profile.stations"
          :key="station.id"
          class="flex items-center gap-2 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
        >
          <MapMarkerRadius :size="16" class="text-emerald-600 shrink-0 dark:text-emerald-400" />
          <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ station.name }}</span>
          <span v-if="station.code" class="text-[11px] font-bold text-slate-400 uppercase">{{ station.code }}</span>
        </div>
      </div>
    </template>

    <template v-if="profile.supervisors?.length">
      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-6 mb-3 dark:text-slate-500">Vos superviseurs</h3>
      <div class="space-y-2">
        <div
          v-for="supervisor in profile.supervisors"
          :key="supervisor.id"
          class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
        >
          <div class="p-1.5 bg-emerald-100 rounded-lg shrink-0 dark:bg-emerald-900/25">
            <AccountSupervisor :size="18" class="text-emerald-600 dark:text-emerald-400" />
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ supervisor.name }}</div>
            <div v-if="supervisor.telephone" class="text-xs text-slate-500 dark:text-slate-400">{{ supervisor.telephone }}</div>
          </div>
        </div>
      </div>
    </template>

    <template v-if="profile.referent">
      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-6 mb-3 dark:text-slate-500">Administrateur référent</h3>
      <div class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40">
        <div class="p-1.5 bg-emerald-100 rounded-lg shrink-0 dark:bg-emerald-900/25">
          <ShieldLock :size="18" class="text-emerald-600 dark:text-emerald-400" />
        </div>
        <div class="min-w-0">
          <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ profile.referent.name }}</div>
          <div v-if="profile.referent.email" class="text-xs text-slate-500 dark:text-slate-400">{{ profile.referent.email }}</div>
        </div>
      </div>
    </template>
  </div>
</template>
