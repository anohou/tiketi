<script setup>
import { onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import FleetMenu from '@/Components/FleetMenu.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Bus from 'vue-material-design-icons/Bus.vue';

const props = defineProps({
  vehicle: { type: Object, default: null },
  vehicleTypes: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
});

const processing = ref(false);
const form = ref({ identifier: '', maker: '', vehicle_type_id: '', seat_count: '', active: true, inactive_reason: '' });

onMounted(() => {
  if (props.vehicle) {
    form.value = {
      identifier: props.vehicle.identifier,
      maker: props.vehicle.maker || '',
      vehicle_type_id: props.vehicle.vehicle_type_id,
      seat_count: String(props.vehicle.seat_count || ''),
      active: props.vehicle.active !== false,
      inactive_reason: props.vehicle.inactive_reason || '',
    };
  }
});

const submit = () => {
  processing.value = true;
  const url = props.vehicle ? route('fleet.vehicles.update', props.vehicle.id) : route('fleet.vehicles.store');
  const method = props.vehicle ? 'put' : 'post';
  router[method](url, form.value, {
    onFinish: () => { processing.value = false; },
  });
};
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex flex-col h-full w-full overflow-hidden">
      <div class="px-6 pt-6 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
        <div>
          <h1 class="text-3xl font-black text-gray-900 flex items-center gap-3">
            <div class="p-2 bg-green-100 rounded-xl">
              <Bus class="text-green-600" :size="28" />
            </div>
            {{ vehicle ? 'Modifier le véhicule' : 'Nouveau véhicule' }}
          </h1>
          <p class="text-gray-500 mt-1">Enregistrement, statut et affectation des véhicules</p>
        </div>
        <div class="flex gap-2">
          <Link
            :href="route('fleet.vehicles.index')"
            class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50"
          >
            Retour
          </Link>
        </div>
      </div>

      <div class="grid grid-cols-12 gap-4 flex-1 min-h-0 px-6 pb-6">
        <div class="col-span-12 md:col-span-2 overflow-y-auto h-full pr-2 custom-scrollbar">
          <FleetMenu />
        </div>

        <div class="col-span-12 md:col-span-10 flex flex-col h-full min-h-0">
          <div class="bg-white rounded-lg border border-orange-200 shadow-sm p-6">
            <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submit">
              <div>
                <InputLabel value="Identifiant" />
                <TextInput v-model="form.identifier" class="w-full" />
                <InputError :message="errors.identifier" />
              </div>
              <div>
                <InputLabel value="Fabricant" />
                <TextInput v-model="form.maker" class="w-full" />
                <InputError :message="errors.maker" />
              </div>
              <div>
                <InputLabel value="Type" />
                <select v-model="form.vehicle_type_id" class="w-full border-gray-300 rounded-lg">
                  <option value="">Sélectionner</option>
                  <option v-for="type in vehicleTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
                <InputError :message="errors.vehicle_type_id" />
              </div>
              <div>
                <InputLabel value="Places" />
                <TextInput v-model="form.seat_count" type="number" min="1" class="w-full" />
                <InputError :message="errors.seat_count" />
              </div>
              <div class="md:col-span-2 flex items-center gap-2">
                <input id="active" v-model="form.active" type="checkbox" class="rounded border-gray-300 text-sky-600" />
                <label for="active" class="text-sm text-gray-700">Véhicule actif</label>
              </div>
              <div v-if="form.active === false" class="md:col-span-2">
                <InputLabel value="Raison de l'inactivité" />
                <TextInput v-model="form.inactive_reason" class="w-full" />
                <InputError :message="errors.inactive_reason" />
              </div>

              <div class="md:col-span-2 flex items-center gap-3 pt-2">
                <PrimaryButton :disabled="processing">{{ vehicle ? 'Mettre à jour' : 'Créer' }}</PrimaryButton>
                <Link
                  :href="route('fleet.vehicles.index')"
                  class="inline-flex items-center justify-center px-4 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50"
                >
                  Annuler
                </Link>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
