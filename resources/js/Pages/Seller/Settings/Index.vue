<script setup>
import { Link } from '@inertiajs/vue3';
import SellerSettingsLayout from '@/Layouts/SellerSettingsLayout.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import Router from 'vue-material-design-icons/Router.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import Calendar from 'vue-material-design-icons/Calendar.vue';
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue';
import Cog from 'vue-material-design-icons/Cog.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
const props = defineProps({
  stats: {
    type: Object,
    default: () => ({}),
  },
});

const { t } = useI18n();

const cards = computed(() => [
  { route: 'seller.settings.company', label: t('seller_settings.cards.company.title'), description: t('seller_settings.cards.company.description'), icon: OfficeBuilding },
  { route: 'seller.settings.loyalty', label: t('seller_settings.cards.loyalty.title'), description: t('seller_settings.cards.loyalty.description'), icon: GiftOutline },
  { route: 'seller.settings.stations', label: t('seller_settings.cards.stations.title'), description: t('seller_settings.cards.stations.description'), icon: MapMarkerRadius, countKey: 'stations' },
  { route: 'seller.settings.routes', label: t('seller_settings.cards.routes.title'), description: t('seller_settings.cards.routes.description'), icon: Router, countKey: 'routes' },
  { route: 'seller.settings.vehicles', label: t('seller_settings.cards.vehicles.title'), description: t('seller_settings.cards.vehicles.description'), icon: Bus, countKey: 'vehicles' },
  { route: 'seller.settings.team', label: t('seller_settings.cards.team.title'), description: t('seller_settings.cards.team.description'), icon: AccountGroup, countKey: 'team' },
  { route: 'seller.settings.assignments', label: t('seller_settings.cards.assignments.title'), description: t('seller_settings.cards.assignments.description'), icon: AccountMultiple, countKey: 'assignments' },
  { route: 'seller.settings.trips', label: t('seller_settings.cards.trips.title'), description: t('seller_settings.cards.trips.description'), icon: Calendar, countKey: 'trips' },
  { route: 'seller.settings.profile', label: t('seller_settings.cards.profile.title'), description: t('seller_settings.cards.profile.description'), icon: AccountHardHat },
]);
</script>

<template>
  <SellerSettingsLayout
    :title="$t('seller_settings.title')"
    :subtitle="$t('seller_settings.subtitle')"
    :icon="Cog"
    :stats="props.stats"
  >
    <div class="grid flex-1 content-start gap-4 md:grid-cols-2 xl:grid-cols-3">
      <Link
        v-for="card in cards"
        :key="card.route"
        :href="route(card.route)"
        class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-emerald-700"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="rounded-xl bg-emerald-50 p-2.5 dark:bg-emerald-900/25">
            <component :is="card.icon" :size="24" class="text-emerald-600 dark:text-emerald-400" />
          </div>
          <span
            v-if="card.countKey && props.stats[card.countKey] !== undefined"
            class="inline-flex min-w-9 items-center justify-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300"
          >
            {{ props.stats[card.countKey] }}
          </span>
        </div>
        <h2 class="mt-4 text-sm font-bold text-slate-800 dark:text-slate-100">{{ card.label }}</h2>
        <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{{ card.description }}</p>
      </Link>
    </div>
  </SellerSettingsLayout>
</template>
