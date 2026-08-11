<script setup>
import DialogModal from '@/Components/DialogModal.vue';
import AppDatePicker from '@/Components/AppDatePicker.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Nouveau programme de départ' },
  submitLabel: { type: String, default: 'Créer le programme' },
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  processing: { type: Boolean, default: false },
  stations: { type: Array, default: () => [] },
  routes: { type: Array, default: () => [] },
  destinationOptions: { type: Array, default: null },
  vehicleTypes: { type: Array, default: () => [] },
  companyDefaultPolicy: { type: String, default: 'require_real_vehicle' },
  lockStation: { type: Boolean, default: false },
  lockedStationName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'submit', 'route-change']);

const dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const selectClass = 'mt-1 h-[38px] w-full rounded-xl border-slate-200 bg-white py-1.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950';

const policyLabel = (policy) => policy === 'allow_planned_capacity'
  ? 'Vente sur capacité prévue'
  : 'Car réel obligatoire';

const toggleDay = (day) => {
  const index = props.form.days_of_week.indexOf(day);
  if (index >= 0) props.form.days_of_week.splice(index, 1);
  else props.form.days_of_week.push(day);
  props.form.days_of_week.sort((a, b) => a - b);
};

const handleRouteChange = () => {
  props.form.destination_station_id = '';
  emit('route-change');
};

const handleStationChange = () => {
  props.form.origin_station_id = props.form.station_id;
};

const applyVehicleCapacity = () => {
  const vehicleType = props.vehicleTypes.find((item) => item.id === props.form.default_vehicle_type_id);
  props.form.planned_capacity = vehicleType?.seat_count ?? '';
};

const handleConnectionsChange = () => {
  if (!props.form.allows_open_connections) {
    props.form.automatic_connection_allocation = false;
  }
};
</script>

<template>
  <DialogModal :show="show" max-width="6xl" :content-scrollable="false" @close="emit('close')">
    <template #title>{{ title }}</template>

    <template #content>
      <div class="grid grid-cols-1 gap-x-3 gap-y-2.5 lg:grid-cols-4">
        <div class="lg:col-span-2">
          <InputLabel for="schedule-route" value="Trajet" />
          <select id="schedule-route" v-model="form.route_id" :class="selectClass" @change="handleRouteChange">
            <option value="" disabled>Sélectionner un trajet…</option>
            <option v-for="routeItem in routes" :key="routeItem.id" :value="routeItem.id">{{ routeItem.name }}</option>
          </select>
          <InputError :message="errors.route_id" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-station" value="Gare de départ" />
          <div v-if="lockStation" class="mt-1 flex h-[38px] items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
            {{ lockedStationName }}
          </div>
          <select v-else id="schedule-station" v-model="form.station_id" :class="selectClass" @change="handleStationChange">
            <option value="" disabled>Sélectionner une gare…</option>
            <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
          </select>
          <InputError :message="errors.station_id" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-destination" value="Gare de destination" />
          <select id="schedule-destination" v-model="form.destination_station_id" :class="selectClass">
            <option value="" disabled>Sélectionner une gare…</option>
            <option v-for="station in (destinationOptions || stations)" :key="station.id" :value="station.id">{{ station.name }}</option>
          </select>
          <InputError :message="errors.destination_station_id" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-time" value="Heure de départ" />
          <TextInput id="schedule-time" v-model="form.departure_time" type="time" class="mt-1 h-[38px]" />
          <InputError :message="errors.departure_time" class="mt-0.5" />
        </div>

        <div>
          <InputLabel value="Valide du" />
          <AppDatePicker v-model="form.valid_from" :max="form.valid_until || ''" class="mt-1" />
          <InputError :message="errors.valid_from" class="mt-0.5" />
        </div>

        <div>
          <InputLabel value="Valide jusqu’au (optionnel)" />
          <AppDatePicker v-model="form.valid_until" :min="form.valid_from || ''" class="mt-1" />
          <InputError :message="errors.valid_until" class="mt-0.5" />
        </div>

        <div class="lg:col-span-4">
          <InputLabel value="Jours de circulation" />
          <div class="mt-1 grid grid-cols-7 gap-1.5">
            <button
              v-for="(day, index) in dayNames"
              :key="day"
              type="button"
              class="h-8 rounded-lg text-xs font-semibold transition"
              :class="form.days_of_week.includes(index + 1)
                ? 'bg-emerald-600 text-white'
                : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'"
              @click="toggleDay(index + 1)"
            >
              {{ day }}
            </button>
          </div>
          <InputError :message="errors.days_of_week" class="mt-0.5" />
        </div>

        <div class="lg:col-span-2">
          <InputLabel for="schedule-vehicle-type" value="Type de véhicule prévisionnel" />
          <select id="schedule-vehicle-type" v-model="form.default_vehicle_type_id" :class="selectClass" @change="applyVehicleCapacity">
            <option value="">Aucun type prévisionnel</option>
            <option v-for="vehicleType in vehicleTypes" :key="vehicleType.id" :value="vehicleType.id">
              {{ vehicleType.name }} · {{ vehicleType.seat_count }} places
            </option>
          </select>
          <InputError :message="errors.default_vehicle_type_id" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-capacity" value="Capacité prévisionnelle" />
          <TextInput id="schedule-capacity" v-model="form.planned_capacity" type="number" min="1" class="mt-1 h-[38px]" />
          <InputError :message="errors.planned_capacity" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-policy" value="Condition de vente" />
          <select id="schedule-policy" v-model="form.vehicle_assignment_policy" :class="selectClass">
            <option value="">Compagnie ({{ policyLabel(companyDefaultPolicy) }})</option>
            <option value="require_real_vehicle">Car réel obligatoire</option>
            <option value="allow_planned_capacity">Vente sur capacité prévue</option>
          </select>
          <InputError :message="errors.vehicle_assignment_policy" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-priority-ticket-quota" value="Quota de billets prioritaires" />
          <TextInput id="schedule-priority-ticket-quota" v-model="form.confirmed_return_quota" type="number" min="0" class="mt-1 h-[38px]" />
          <InputError :message="errors.confirmed_return_quota" class="mt-0.5" />
        </div>

        <div>
          <InputLabel for="schedule-booking" value="Mode de réservation" />
          <select id="schedule-booking" v-model="form.booking_type" :class="selectClass">
            <option value="seat_assignment">Placement intelligent</option>
            <option value="semi_intelligent">Semi-intelligent</option>
            <option value="bulk">Vrac</option>
          </select>
        </div>

        <div class="lg:col-span-2">
          <InputLabel for="schedule-sales" value="Contrôle des ventes" />
          <select id="schedule-sales" v-model="form.sales_control" :class="selectClass">
            <option value="open">Ouvertes</option>
            <option value="closed">Fermées</option>
          </select>
        </div>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-100 pt-2 lg:col-span-4 dark:border-slate-800">
          <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
            <input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" /> Programme actif
          </label>
          <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
            <input v-model="form.allows_open_connections" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" @change="handleConnectionsChange" /> Autoriser les correspondances
          </label>
          <label
            class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200"
            :class="{ 'opacity-45': !form.allows_open_connections }"
          >
            <input
              v-model="form.automatic_connection_allocation"
              type="checkbox"
              :disabled="!form.allows_open_connections"
              class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
            />
            Affectation automatique des correspondances
          </label>
        </div>
      </div>
    </template>

    <template #footer>
      <SecondaryButton @click="emit('close')">Annuler</SecondaryButton>
      <PrimaryButton class="ml-3" :disabled="processing" @click="emit('submit')">
        {{ processing ? 'Enregistrement…' : submitLabel }}
      </PrimaryButton>
    </template>
  </DialogModal>
</template>
