<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Bus from 'vue-material-design-icons/Bus.vue';
import Car from 'vue-material-design-icons/Car.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue';
import ClipboardText from 'vue-material-design-icons/ClipboardText.vue';

const props = defineProps({
  stats: Object,
  recentAssignments: Array,
  managerCoverage: Array,
});

const statCards = [
  { key: 'totalVehicles', labelKey: 'dashboards.fleet.stat_vehicles', icon: Bus, tone: 'from-sky-500 to-sky-600' },
  { key: 'activeVehicles', labelKey: 'dashboards.fleet.stat_active', icon: CheckCircle, tone: 'from-emerald-500 to-emerald-600' },
  { key: 'inactiveVehicles', labelKey: 'dashboards.fleet.stat_inactive', icon: AlertCircle, tone: 'from-rose-500 to-rose-600' },
  { key: 'vehicleTypes', labelKey: 'dashboards.fleet.stat_vehicle_types', icon: Car, tone: 'from-emerald-500 to-emerald-600' },
];
</script>

<template>
  <Head :title="$t('dashboards.fleet.title')" />
  <MainNavLayout>
    <div class="max-w-7xl mx-auto space-y-6 pb-10">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-sky-100 rounded-xl">
              <Bus class="text-sky-600" :size="28" />
            </div>
            {{ $t('dashboards.fleet.title') }}
          </h1>
          <p class="text-gray-500 mt-1">{{ $t('dashboards.fleet.subtitle') }}</p>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div v-for="card in statCards" :key="card.key" :class="['rounded-2xl p-5 text-white shadow-lg bg-gradient-to-br', card.tone]">
          <div class="flex items-center justify-between mb-3">
            <div class="p-2 bg-white/20 rounded-xl">
              <component :is="card.icon" :size="22" />
            </div>
          </div>
          <div class="text-3xl font-black">{{ stats[card.key] }}</div>
          <div class="text-sm text-white/80 mt-1">{{ $t(card.labelKey) }}</div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <section class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="p-5 border-b border-slate-100 bg-gradient-to-r from-emerald-50 to-slate-50 flex items-center justify-between">
            <h2 class="text-lg font-bold text-gray-800 flex items-center gap-3">
              <AccountGroup :size="22" class="text-sky-600" />
              {{ $t('dashboards.fleet.quick_management') }}
            </h2>
            <Link :href="route('fleet.assignments.index')" class="text-sm font-bold text-sky-700 hover:underline">{{ $t('dashboards.fleet.view_assignments') }}</Link>
          </div>
          <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <Link :href="route('fleet.vehicles.create')" class="rounded-2xl border border-sky-100 bg-sky-50/60 p-5 hover:bg-sky-50 transition-colors">
              <Bus :size="24" class="text-sky-600 mb-3" />
              <div class="font-black text-gray-900">{{ $t('dashboards.fleet.register_vehicle') }}</div>
              <div class="text-sm text-gray-500 mt-1">{{ $t('dashboards.fleet.register_vehicle_desc') }}</div>
            </Link>
            <Link :href="route('fleet.vehicle-types.create')" class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 hover:bg-emerald-50 transition-colors">
              <Car :size="24" class="text-emerald-600 mb-3" />
              <div class="font-black text-gray-900">{{ $t('dashboards.fleet.register_vehicle_type') }}</div>
              <div class="text-sm text-gray-500 mt-1">{{ $t('dashboards.fleet.register_vehicle_type_desc') }}</div>
            </Link>
            <Link :href="route('fleet.assignments.index')" class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 hover:bg-emerald-50 transition-colors">
              <AccountGroup :size="24" class="text-emerald-600 mb-3" />
              <div class="font-black text-gray-900">{{ $t('dashboards.fleet.assign_vehicles') }}</div>
              <div class="text-sm text-gray-500 mt-1">{{ $t('dashboards.fleet.assign_vehicles_desc') }}</div>
            </Link>
          </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="p-5 border-b border-slate-100 bg-emerald-50/50">
            <h2 class="text-lg font-bold text-gray-800 flex items-center gap-3">
              <ClipboardText :size="22" class="text-emerald-600" />
              {{ $t('dashboards.fleet.recent_assignments') }}
            </h2>
          </div>
          <div class="p-5 space-y-3 max-h-[28rem] overflow-auto">
            <div v-for="assignment in recentAssignments" :key="assignment.id" class="rounded-xl border border-gray-100 p-3">
              <div class="font-bold text-gray-900">{{ assignment.vehicle_identifier }}</div>
              <div class="text-sm text-gray-500">{{ assignment.user_name }}</div>
              <div class="mt-2 text-xs">
                <span :class="['px-2 py-0.5 rounded-full font-bold', assignment.active ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700']">
                  {{ assignment.active ? $t('common.active') : $t('common.inactive') }}
                </span>
              </div>
            </div>
            <div v-if="!recentAssignments || recentAssignments.length === 0" class="text-sm text-gray-500">
              {{ $t('dashboards.fleet.no_assignments') }}
            </div>
          </div>
        </section>
      </div>
    </div>
  </MainNavLayout>
</template>
