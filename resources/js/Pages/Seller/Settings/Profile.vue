<script setup>
import SellerSettingsLayout from '@/Layouts/SellerSettingsLayout.vue';
import UserProfileSection from '@/Components/Settings/UserProfileSection.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue';

const props = defineProps({
  profile: {
    type: Object,
    default: () => ({}),
  },
  directives: {
    type: Array,
    default: () => [],
  },
  stats: {
    type: Object,
    default: () => ({}),
  },
});
</script>

<template>
  <SellerSettingsLayout
    :title="$t('seller_settings.cards.profile.title')"
    :subtitle="$t('seller_settings.cards.profile.description') + ' (' + $t('common.read_only', 'lecture seule') + ')'"
    :icon="AccountHardHat"
  >
    <div class="grid max-w-4xl content-start gap-4 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <UserProfileSection :profile="profile" />
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center gap-3 mb-5">
          <div class="rounded-xl bg-emerald-100 p-2 dark:bg-emerald-900/25">
            <InformationOutline class="text-emerald-600 dark:text-emerald-400" :size="22" />
          </div>
          <div>
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ $t('admin_settings.directives.title', 'Directives de vente') }}</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $t('admin_settings.directives.subtitle') }}</p>
          </div>
        </div>

        <div v-if="directives.length" class="space-y-3">
          <div
            v-for="directive in directives"
            :key="directive.title"
            class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40"
          >
            <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ directive.title }}</h3>
            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">{{ directive.content }}</p>
          </div>
        </div>
      </div>
    </div>
  </SellerSettingsLayout>
</template>
