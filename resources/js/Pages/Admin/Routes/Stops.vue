<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import { confirmationStore } from '@/Stores/confirmationStore.js';
import { toastStore } from '@/Stores/toastStore.js';

const { t } = useI18n();

const props = defineProps({
  routeModel: Object,
  stops: Array,
  availableStops: Array
});

// State
const processing = ref(false);
const errors = ref({});
const form = ref({
  station_id: '',
  stop_index: props.stops.length
});

// Methods
const submit = () => {
  processing.value = true;
  errors.value = {};

  router.post(route('admin.routes.stops.store', props.routeModel.id), form.value, {
    onSuccess: () => {
      processing.value = false;
      form.value.station_id = '';
      form.value.stop_index = props.stops.length; // Reset to end
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const deleteStop = async (stopOrder) => {
  if (await confirmationStore.confirm({ title: t('fleet.routes.stops.remove_destination'), message: t('fleet.routes.stops.remove_destination_confirm'), confirmLabel: t('fleet.routes.stops.remove'), tone: 'danger' })) {
    router.delete(route('admin.routes.stops.destroy', [props.routeModel.id, stopOrder.id]), {
      onError: () => toastStore.error(t('fleet.routes.stops.remove_error'))
    });
  }
};

const moveUp = (index) => {
  if (index === 0) return;
  const currentStop = props.stops[index];
  const prevStop = props.stops[index - 1];

  processing.value = true;
  router.post(route('admin.routes.stops.reorder', props.routeModel.id), {
    orders: [
      { id: currentStop.id, stop_index: index - 1 },
      { id: prevStop.id, stop_index: index }
    ]
  }, {
    onSuccess: () => { processing.value = false; },
    onError: () => {
      processing.value = false;
      toastStore.error(t('fleet.routes.stops.reorder_error'));
    }
  });
};

const moveDown = (index) => {
  if (index === props.stops.length - 1) return;
  const currentStop = props.stops[index];
  const nextStop = props.stops[index + 1];

  processing.value = true;
  router.post(route('admin.routes.stops.reorder', props.routeModel.id), {
    orders: [
      { id: currentStop.id, stop_index: index + 1 },
      { id: nextStop.id, stop_index: index }
    ]
  }, {
    onSuccess: () => { processing.value = false; },
    onError: () => {
      processing.value = false;
      toastStore.error(t('fleet.routes.stops.reorder_error'));
    }
  });
};
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4">
      <!-- Header -->
      <div class="bg-gradient-to-r from-emerald-50 to-slate-50 border-b border-slate-200 px-4 py-2 mb-4">
        <div class="flex items-center gap-4">
          <Link :href="route('admin.routes.index')" class="text-green-700 hover:text-green-900">
            <ArrowLeft class="w-6 h-6" />
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-green-700">{{ $t('fleet.routes.stops.route_destinations') }}</h1>
            <p class="mt-1 text-sm text-green-600">{{ routeModel.name }}</p>
          </div>
        </div>
      </div>

      <!-- Three Column Layout -->
      <div class="grid grid-cols-12 gap-4">
        <!-- Left Column - Navigation -->
        <div class="col-span-12 md:col-span-2">
          <SettingsMenu />
        </div>

        <!-- Middle Column - Stops List -->
        <div class="col-span-12 md:col-span-6">
          <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
            <div class="border-b border-slate-200 p-3 bg-gradient-to-r from-emerald-50 to-slate-50">
              <h2 class="text-lg font-semibold text-green-700">{{ $t('fleet.routes.stops.destinations_list', { count: stops.length }) }}</h2>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-green-50">
                  <tr>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">{{ $t('fleet.routes.stops.order') }}</th>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">{{ $t('fleet.routes.stops.destination') }}</th>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">{{ $t('fleet.routes.stops.city') }}</th>
                    <th class="px-3 py-2 text-right text-sm font-semibold text-green-700">{{ $t('common.actions') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <tr v-if="stops.length === 0">
                    <td colspan="4" class="px-3 py-3 text-center text-gray-500">
                      <div class="rounded-lg bg-emerald-50 p-1 text-emerald-700">
                        {{ $t('fleet.routes.stops.no_destinations') }}
                      </div>
                    </td>
                  </tr>
                  <tr v-for="(stopOrder, index) in stops" :key="stopOrder.id" class="hover:bg-green-50">
                    <td class="px-3 py-2 whitespace-nowrap">
                      <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs font-bold">
                        {{ index + 1 }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-sm font-medium text-gray-900">
                      {{ stopOrder.stop.name }}
                    </td>
                    <td class="px-3 py-2 text-sm text-gray-600">
                      {{ stopOrder.stop.city }}
                    </td>
                    <td class="px-3 py-2 text-sm text-right">
                      <div class="flex items-center justify-end gap-2">
                        <!-- Up Arrow -->
                        <button 
                          @click="moveUp(index)" 
                          :disabled="index === 0 || processing"
                          class="p-1 rounded text-green-600 hover:bg-green-100 disabled:text-gray-300 disabled:hover:bg-transparent transition-colors"
                          :title="$t('fleet.routes.stops.move_up')"
                        >
                          <ChevronUp class="w-5 h-5" />
                        </button>
                        <!-- Down Arrow -->
                        <button 
                          @click="moveDown(index)" 
                          :disabled="index === stops.length - 1 || processing"
                          class="p-1 rounded text-green-600 hover:bg-green-100 disabled:text-gray-300 disabled:hover:bg-transparent transition-colors"
                          :title="$t('fleet.routes.stops.move_down')"
                        >
                          <ChevronDown class="w-5 h-5" />
                        </button>
                        <!-- Delete Button -->
                        <button 
                          @click="deleteStop(stopOrder)" 
                          :disabled="processing"
                          class="p-1 rounded text-red-600 hover:bg-red-100 disabled:text-gray-300 disabled:hover:bg-transparent transition-colors"
                          :title="$t('fleet.routes.stops.remove')"
                        >
                          <Trash2 class="h-5 w-5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Right Column - Form -->
        <div class="col-span-12 md:col-span-4">
          <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-4">
            <h2 class="text-lg font-semibold text-green-700 mb-4">
              {{ $t('fleet.routes.stops.add_destination') }}
            </h2>

            <form @submit.prevent="submit">
              <div class="space-y-3">
                <div>
                  <InputLabel for="station_id" :value="$t('fleet.routes.stops.select_destination')" />
                  <select v-model="form.station_id" id="station_id"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    :class="{ 'border-red-500': errors.station_id }">
                    <option value="">{{ $t('fleet.routes.stops.choose_destination') }}</option>
                    <option v-for="stop in availableStops" :key="stop.id" :value="stop.id">
                      {{ stop.name }} ({{ stop.city }})
                    </option>
                  </select>
                  <InputError class="mt-2" :message="errors.station_id" />
                </div>

                <div>
                  <InputLabel for="stop_index" :value="$t('fleet.routes.stops.position_index')" />
                  <input type="number" v-model="form.stop_index" id="stop_index"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    min="0"
                  />
                  <p class="text-xs text-gray-500 mt-1">{{ $t('fleet.routes.stops.position_help', { count: stops.length }) }}</p>
                  <InputError class="mt-2" :message="errors.stop_index" />
                </div>

                <div class="pt-3 flex justify-end border-t border-slate-200">
                  <button type="submit"
                    class="px-4 py-2 bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors flex items-center"
                    :disabled="processing">
                    <span v-if="processing" class="flex items-center">
                      <Loader class="w-5 h-5 mr-2 animate-spin" />
                      {{ $t('fleet.routes.stops.adding') }}
                    </span>
                    <span v-else class="flex items-center">
                      <OfficeBuilding class="w-5 h-5 mr-1" />
                      {{ $t('fleet.routes.stops.add_destination_button') }}
                    </span>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
