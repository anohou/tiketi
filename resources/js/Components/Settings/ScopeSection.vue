<script setup>
import { computed } from 'vue';
import Sitemap from 'vue-material-design-icons/Sitemap.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import Router from 'vue-material-design-icons/Router.vue';
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue';
import Car from 'vue-material-design-icons/Car.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import Truck from 'vue-material-design-icons/Truck.vue';
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue';
import Wrench from 'vue-material-design-icons/Wrench.vue';
import Wallet from 'vue-material-design-icons/Wallet.vue';
import CellphoneLink from 'vue-material-design-icons/CellphoneLink.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue';
import FileDocument from 'vue-material-design-icons/FileDocument.vue';
import Domain from 'vue-material-design-icons/Domain.vue';
import CreditCard from 'vue-material-design-icons/CreditCard.vue';
import Clock from 'vue-material-design-icons/Clock.vue';
import AccountTie from 'vue-material-design-icons/AccountTie.vue';

const props = defineProps({
  scope: {
    type: Object,
    default: () => ({ type: '' }),
  },
});

const isSeller = computed(() => props.scope.type === 'seller');
const isSupervisor = computed(() => props.scope.type === 'supervisor');
const isFleet = computed(() => props.scope.type === 'fleet_manager');
const isAccountant = computed(() => props.scope.type === 'accountant');
const isExecutive = computed(() => props.scope.type === 'executive');

const activeBadge = (active) => active
  ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
  : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400';
</script>

<template>
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 dark:bg-slate-900 dark:border-slate-800">
    <div class="flex items-center gap-3 mb-5">
      <div class="p-2 bg-emerald-100 rounded-xl dark:bg-emerald-900/25">
        <Sitemap class="text-emerald-600 dark:text-emerald-400" :size="22" />
      </div>
      <div>
        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Votre périmètre opérationnel</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">Ce que vous pouvez voir et faire dans l'application</p>
      </div>
    </div>

    <!-- SELLER -->
    <template v-if="isSeller">
      <div class="grid gap-5 md:grid-cols-2">
        <div v-if="scope.stations?.length">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Vos gares de vente</h3>
          <div class="space-y-2">
            <div
              v-for="station in scope.stations"
              :key="station.id"
              class="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <OfficeBuilding :size="16" class="text-emerald-600 shrink-0 mt-0.5 dark:text-emerald-400" />
              <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ station.name }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ station.city }}{{ station.address ? ' · ' + station.address : '' }}</div>
                <span v-if="!station.can_sell_tickets" class="inline-flex items-center gap-1 mt-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                  <AlertCircleOutline :size="12" /> Ventes désactivées
                </span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="scope.routes?.length">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Vos trajets accessibles</h3>
          <div class="space-y-2">
            <div
              v-for="route in scope.routes"
              :key="route.id"
              class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <Router :size="16" class="text-slate-400 shrink-0" />
              <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ route.name }}</span>
              <span v-if="route.origin && route.destination" class="text-xs text-slate-500 ml-auto truncate">{{ route.origin }} → {{ route.destination }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-5 grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <div class="flex items-center gap-2 mb-3">
            <CashMultiple :size="18" class="text-emerald-600 dark:text-emerald-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Moyens de paiement</h3>
          </div>
          <div v-if="scope.paymentMethods?.length" class="space-y-2">
            <div v-for="method in scope.paymentMethods" :key="method.value" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <CheckCircle :size="16" class="text-emerald-500 shrink-0" />
              {{ method.label }}
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <div class="flex items-center gap-2 mb-3">
            <Wallet :size="18" class="text-emerald-600 dark:text-emerald-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Compensation vendeur</h3>
          </div>
          <div v-if="scope.compensation" class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
            <div class="flex items-center justify-between">
              <span>Autorisée</span>
              <span class="font-bold" :class="scope.compensation.enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                {{ scope.compensation.enabled ? 'Oui' : 'Non' }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span>Plafond</span>
              <span class="font-bold text-slate-800 dark:text-slate-200">
                {{ scope.compensation.enabled ? scope.compensation.maxAmount + ' F CFA' : '—' }}
              </span>
            </div>
          </div>
        </div>

        <div v-if="scope.deviceRestrictions" class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <div class="flex items-center gap-2 mb-3">
            <CellphoneLink :size="18" class="text-emerald-600 dark:text-emerald-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Appareils</h3>
          </div>
          <div class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
            <div class="flex items-center justify-between">
              <span>TIKETI</span>
              <span class="font-bold" :class="scope.deviceRestrictions.web ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                {{ scope.deviceRestrictions.web ? 'Restreint' : 'Tout appareil' }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span>Control</span>
              <span class="font-bold" :class="scope.deviceRestrictions.control ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                {{ scope.deviceRestrictions.control ? 'Restreint' : 'Tout appareil' }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- SUPERVISOR -->
    <template v-else-if="isSupervisor">
      <div class="grid gap-5 md:grid-cols-2">
        <div v-if="scope.stations?.length">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Gares supervisées</h3>
          <div class="space-y-2">
            <div
              v-for="station in scope.stations"
              :key="station.id"
              class="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <OfficeBuilding :size="16" class="text-emerald-600 shrink-0 mt-0.5 dark:text-emerald-400" />
              <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ station.name }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ station.city }}</div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="scope.sellers?.length">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Vendeurs de votre périmètre</h3>
          <div class="space-y-2">
            <div
              v-for="seller in scope.sellers"
              :key="seller.id"
              class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <div class="flex items-center gap-2">
                <AccountMultiple :size="16" class="text-slate-400 shrink-0" />
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ seller.name }}</span>
              </div>
              <div v-if="seller.stations?.length" class="mt-1.5 flex flex-wrap gap-1.5">
                <span
                  v-for="station in seller.stations"
                  :key="station"
                  class="inline-flex items-center gap-1 rounded-full bg-white border border-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                >
                  {{ station }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="scope.routes?.length" class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Trajets concernés par vos gares</h3>
        <div class="grid gap-2 md:grid-cols-2">
          <div
            v-for="route in scope.routes"
            :key="route.id"
            class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
          >
            <Router :size="16" class="text-slate-400 shrink-0" />
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ route.name }}</span>
            <span v-if="route.origin && route.destination" class="text-xs text-slate-500 ml-auto truncate">{{ route.origin }} → {{ route.destination }}</span>
          </div>
        </div>
      </div>
    </template>

    <!-- FLEET MANAGER -->
    <template v-else-if="isFleet">
      <div class="grid gap-5 md:grid-cols-2">
        <div>
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Véhicules de la flotte ({{ scope.vehicles?.length || 0 }})</h3>
          <div class="space-y-2">
            <div
              v-for="vehicle in scope.vehicles"
              :key="vehicle.id"
              class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <Bus :size="16" class="text-emerald-600 shrink-0 dark:text-emerald-400" />
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ vehicle.identifier }}</span>
                  <span :class="['inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold', activeBadge(vehicle.active)]">
                    {{ vehicle.active ? 'Actif' : 'Inactif' }}
                  </span>
                </div>
                <div class="text-xs text-slate-500 dark:text-slate-400">
                  {{ vehicle.maker || '—' }} · {{ vehicle.type }} · {{ vehicle.seat_count }} places
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-5">
          <div v-if="scope.pools?.length">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Pools par gare</h3>
            <div class="space-y-2">
              <div
                v-for="(pool, index) in scope.pools"
                :key="index"
                class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
              >
                <div class="flex items-center gap-2">
                  <Domain :size="16" class="text-slate-400 shrink-0" />
                  <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ pool.station }}</span>
                </div>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                  <span
                    v-for="vehicle in pool.vehicles"
                    :key="vehicle"
                    class="inline-flex items-center gap-1 rounded-full bg-white border border-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
                  >
                    {{ vehicle }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="scope.unpooledVehicles?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
            <div class="flex items-center gap-2 mb-2">
              <AlertCircleOutline :size="18" class="text-amber-600 dark:text-amber-400" />
              <h3 class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Véhicules sans pool</h3>
            </div>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="vehicle in scope.unpooledVehicles"
                :key="vehicle"
                class="inline-flex items-center rounded-full bg-white border border-amber-200 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-slate-900 dark:border-amber-900/40 dark:text-amber-300"
              >
                {{ vehicle }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Équipages ({{ scope.crews?.length || 0 }})</h3>
        <div class="grid gap-2 md:grid-cols-2">
          <div
            v-for="crew in scope.crews"
            :key="crew.id"
            class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40"
          >
            <div class="p-1.5 bg-white rounded-lg shrink-0 dark:bg-slate-900">
              <AccountTie :size="18" class="text-slate-400" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ crew.name }}</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">{{ crew.role }}</div>
            </div>
            <span v-if="crew.vehicle" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              <Car :size="12" /> {{ crew.vehicle }}
            </span>
          </div>
        </div>
        <div v-if="scope.uncrewedVehicles?.length" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
          <div class="flex items-center gap-2 mb-2">
            <AlertCircleOutline :size="18" class="text-amber-600 dark:text-amber-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Véhicules sans équipage</h3>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="vehicle in scope.uncrewedVehicles"
              :key="vehicle"
              class="inline-flex items-center rounded-full bg-white border border-amber-200 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-slate-900 dark:border-amber-900/40 dark:text-amber-300"
            >
              {{ vehicle }}
            </span>
          </div>
        </div>
      </div>
    </template>

    <!-- ACCOUNTANT -->
    <template v-else-if="isAccountant">
      <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <div class="flex items-center gap-2 mb-3">
            <CreditCard :size="18" class="text-emerald-600 dark:text-emerald-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Paiements comptabilisés</h3>
          </div>
          <div class="space-y-2">
            <div v-for="method in scope.paymentMethods" :key="method.value" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <CheckCircle :size="16" class="text-emerald-500 shrink-0" />
              {{ method.label }}
            </div>
          </div>
        </div>

        <div v-if="scope.perimeters?.length" class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <div class="flex items-center gap-2 mb-3">
            <Domain :size="18" class="text-emerald-600 dark:text-emerald-400" />
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Périmètre</h3>
          </div>
          <div v-for="perimeter in scope.perimeters" :key="perimeter" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <MapMarkerRadius :size="16" class="text-emerald-500 shrink-0" />
            {{ perimeter }}
          </div>
        </div>
      </div>

      <div v-if="scope.closingRules?.length" class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Règles de clôture</h3>
        <div class="space-y-2">
          <div v-for="(rule, index) in scope.closingRules" :key="index" class="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40">
            <Clock :size="16" class="text-slate-400 shrink-0 mt-0.5" />
            <span class="text-sm text-slate-700 dark:text-slate-300">{{ rule }}</span>
          </div>
        </div>
      </div>

      <div v-if="scope.reportTypes?.length" class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Rapports disponibles</h3>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="report in scope.reportTypes"
            :key="report"
            class="inline-flex items-center gap-1 rounded-full bg-white border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
          >
            <FileDocument :size="12" class="text-slate-400" />
            {{ report }}
          </span>
        </div>
      </div>

      <div v-if="scope.contacts?.length" class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Administrateurs</h3>
        <div class="space-y-2">
          <div v-for="contact in scope.contacts" :key="contact.id" class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950/40">
            <AccountTie :size="16" class="text-emerald-600 shrink-0 dark:text-emerald-400" />
            <div class="min-w-0">
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ contact.name }}</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">{{ contact.email }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- EXECUTIVE -->
    <template v-else-if="isExecutive">
      <div v-if="scope.network" class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-950/40">
          <div class="text-2xl font-black text-emerald-600">{{ scope.network.stations }}</div>
          <div class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-1">Gares</div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-950/40">
          <div class="text-2xl font-black text-slate-700">{{ scope.network.routes }}</div>
          <div class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-1">Trajets</div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-950/40">
          <div class="text-2xl font-black text-slate-700">{{ scope.network.vehicles }}</div>
          <div class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-1">Véhicules</div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-950/40">
          <div class="text-2xl font-black text-slate-700">{{ scope.network.trips }}</div>
          <div class="text-xs font-bold uppercase tracking-wide text-slate-400 mt-1">Voyages</div>
        </div>
      </div>

      <div v-if="scope.policies" class="mt-5 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Politiques commerciales</h3>
          <div class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
            <div class="flex items-center justify-between">
              <span>Compensation vendeur</span>
              <span class="font-bold" :class="scope.policies.sellerCompensationEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                {{ scope.policies.sellerCompensationEnabled ? 'Autorisée' : 'Désactivée' }}
              </span>
            </div>
            <div v-if="scope.policies.sellerCompensationEnabled" class="flex items-center justify-between">
              <span>Plafond vendeur</span>
              <span class="font-bold text-slate-800 dark:text-slate-200">{{ scope.policies.sellerCompensationMaxAmount }} F CFA</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Allocation auto. des correspondances</span>
              <span class="font-bold" :class="scope.policies.automaticConnectionAllocation ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                {{ scope.policies.automaticConnectionAllocation ? 'Oui' : 'Non' }}
              </span>
            </div>
            <div v-if="scope.policies.automaticConnectionAllocation" class="flex items-center justify-between">
              <span>Marge de correspondance</span>
              <span class="font-bold text-slate-800 dark:text-slate-200">{{ scope.policies.connectionTransferBufferMinutes }} min</span>
            </div>
          </div>
        </div>

        <div v-if="scope.services" class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
          <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Services actifs</h3>
          <div class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
            <div class="flex items-center justify-between">
              <span>{{ $t('admin_settings.scope.okohi_loyalty') }}</span>
              <span :class="['font-bold', scope.services.okohiConnected ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400']">
                {{ scope.services.okohiConnected ? $t('admin_settings.common.connected') : $t('admin_settings.common.not_connected') }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span>Ventes équipage</span>
              <span :class="['font-bold', scope.services.crewSales ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400']">
                {{ scope.services.crewSales ? 'Autorisées' : 'Désactivées' }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="scope.supervisors?.length" class="mt-5">
        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3 dark:text-slate-500">Superviseurs du réseau</h3>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="supervisor in scope.supervisors"
            :key="supervisor.id"
            class="inline-flex items-center gap-1.5 rounded-full bg-white border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300"
          >
            {{ supervisor.name }}
          </span>
        </div>
      </div>
    </template>

    <!-- FALLBACK -->
    <template v-else>
      <div class="flex flex-col items-center justify-center py-8 text-center">
        <div class="p-4 bg-slate-50 rounded-full text-slate-400 mb-4 shrink-0 dark:bg-slate-800">
          <Wrench :size="36" />
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1 dark:text-slate-100">Aucun périmètre spécifique</h3>
        <p class="text-xs text-slate-500 max-w-sm leading-relaxed dark:text-slate-400">
          Votre profil ne définit pas de périmètre opérationnel particulier pour le moment.
        </p>
      </div>
    </template>
  </div>
</template>
