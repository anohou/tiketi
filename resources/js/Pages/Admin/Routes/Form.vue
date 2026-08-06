<script setup>
import SettingsLayout from '@/Layouts/SettingsLayout.vue'
import FormPanel from '@/Components/FormPanel.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({ routeItem: Object, stations: Array })

const form = useForm({
  name: props.routeItem?.name || '',
  origin_station_id: props.routeItem?.origin_station_id || '',
  destination_station_id: props.routeItem?.destination_station_id || '',
  active: props.routeItem?.active ?? true,
})

const submit = () => {
  if (props.routeItem) {
    form.put(`/admin/routes/${props.routeItem.id}`)
  } else {
    form.post('/admin/routes')
  }
}
</script>
<template>
  <SettingsLayout>
    <div class='grid grid-cols-12 gap-4'>
      <div class='col-span-12 md:col-span-6 bg-white rounded shadow border border-slate-200 h-[600px]'>
        <FormPanel @submit="submit">
          <template #header>
            <div class='font-semibold text-green-700 px-2 py-1'>{{ props.routeItem ? 'Modifier une route' : 'Créer une route' }}</div>
          </template>
          <div class='p-4 space-y-4'>
            <label class="block">Nom<input class='border rounded w-full p-2 mt-1' v-model='form.name'/></label>
            <label class="block">Origine
              <select v-model='form.origin_station_id' class='border rounded w-full p-2 mt-1'>
                <option v-for='s in props.stations' :key='s.id' :value='s.id'>{{ s.name }}</option>
              </select>
            </label>
            <label class="block">Destination
              <select v-model='form.destination_station_id' class='border rounded w-full p-2 mt-1'>
                <option v-for='s in props.stations' :key='s.id' :value='s.id'>{{ s.name }}</option>
              </select>
            </label>
            <label class='flex items-center gap-2 mt-2'><input type='checkbox' v-model='form.active'/> Actif</label>
          </div>
          <template #actions>
            <button type="submit" :disabled="form.processing" class='px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50'>Enregistrer</button>
          </template>
        </FormPanel>
      </div>
      <div class='col-span-12 md:col-span-6'>
        <!-- Placeholder pane for stops and fares management (next step) -->
      </div>
    </div>
  </SettingsLayout>
</template>
