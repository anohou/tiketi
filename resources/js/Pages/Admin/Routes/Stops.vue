<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SettingsMenu from '@/Components/SettingsMenu.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import Trash2 from 'vue-material-design-icons/Delete.vue';
import Loader from 'vue-material-design-icons/Loading.vue';
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue';
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue';

const props = defineProps({
  routeModel: Object,
  stops: Array,
  availableStops: Array
});

// State
const processing = ref(false);
const errors = ref({});
const form = ref({
  stop_id: '',
  stop_index: props.stops.length
});

// Methods
const submit = () => {
  processing.value = true;
  errors.value = {};

  router.post(route('admin.routes.stops.store', props.routeModel.id), form.value, {
    onSuccess: () => {
      processing.value = false;
      form.value.stop_id = '';
      form.value.stop_index = props.stops.length; // Reset to end
    },
    onError: (newErrors) => {
      processing.value = false;
      errors.value = newErrors;
    }
  });
};

const deleteStop = (stopOrder) => {
  if (confirm('Êtes-vous sûr de vouloir retirer cette destination de la route ?')) {
    router.delete(route('admin.routes.stops.destroy', [props.routeModel.id, stopOrder.id]), {
      onError: () => alert('Impossible de supprimer cette destination.')
    });
  }
};
</script>

<template>
  <MainNavLayout>
    <div class="w-full px-4">
      <!-- Header -->
      <div class="bg-gradient-to-r from-green-50 to-orange-50/30 border-b border-orange-200 px-4 py-2 mb-4">
        <div class="flex items-center gap-4">
          <Link :href="route('admin.routes.index')" class="text-green-700 hover:text-green-900">
            <ArrowLeft class="w-6 h-6" />
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-green-700">Destinations de la Route</h1>
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
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm">
            <div class="border-b border-orange-200 p-3 bg-gradient-to-r from-green-50 to-orange-50/30">
              <h2 class="text-lg font-semibold text-green-700">Liste des Destinations ({{ stops.length }})</h2>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-orange-200">
                <thead class="bg-green-50">
                  <tr>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">Ordre</th>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">Destination</th>
                    <th class="px-3 py-2 text-left text-sm font-semibold text-green-700">Ville</th>
                    <th class="px-3 py-2 text-right text-sm font-semibold text-green-700">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-orange-200">
                  <tr v-if="stops.length === 0">
                    <td colspan="4" class="px-3 py-3 text-center text-gray-500">
                      <div class="rounded-lg bg-orange-50 p-1 text-orange-700">
                        Aucune destination configurée pour cette route.
                      </div>
                    </td>
                  </tr>
                  <tr v-for="(stopOrder, index) in stops" :key="stopOrder.id" class="hover:bg-green-50">
                    <td class="px-3 py-2 whitespace-nowrap">
                      <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-orange-100 text-orange-800 text-xs font-bold">
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
                      <button @click="deleteStop(stopOrder)" class="text-red-600 hover:text-red-800">
                        <Trash2 class="h-5 w-5" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Right Column - Form -->
        <div class="col-span-12 md:col-span-4">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-4">
            <h2 class="text-lg font-semibold text-green-700 mb-4">
              Ajouter une destination
            </h2>

            <form @submit.prevent="submit">
              <div class="space-y-3">
                <div>
                  <InputLabel for="stop_id" value="Sélectionner une destination" />
                  <select v-model="form.stop_id" id="stop_id"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    :class="{ 'border-red-500': errors.stop_id }">
                    <option value="">Choisir une destination...</option>
                    <option v-for="stop in availableStops" :key="stop.id" :value="stop.id">
                      {{ stop.name }} ({{ stop.city }})
                    </option>
                  </select>
                  <InputError class="mt-2" :message="errors.stop_id" />
                </div>

                <div>
                  <InputLabel for="stop_index" value="Position (Index)" />
                  <input type="number" v-model="form.stop_index" id="stop_index"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    min="0"
                  />
                  <p class="text-xs text-gray-500 mt-1">0 = Début, {{ stops.length }} = Fin</p>
                  <InputError class="mt-2" :message="errors.stop_index" />
                </div>

                <div class="pt-3 flex justify-end border-t border-orange-200">
                  <button type="submit"
                    class="px-4 py-2 bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors flex items-center"
                    :disabled="processing">
                    <span v-if="processing" class="flex items-center">
                      <Loader class="w-5 h-5 mr-2 animate-spin" />
                      Ajout...
                    </span>
                    <span v-else class="flex items-center">
                      <OfficeBuilding class="w-5 h-5 mr-1" />
                      Ajouter la destination
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
