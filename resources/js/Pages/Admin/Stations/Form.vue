<script setup>
import { reactive } from 'vue'
import SettingsLayout from '@/Layouts/SettingsLayout.vue'
import FormPanel from '@/Components/FormPanel.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({ station: Object })
const form = useForm({
  name: props.station?.name || '',
  code: props.station?.code || '',
  city: props.station?.city || '',
  address: props.station?.address || '',
  phone: props.station?.phone || '',
  latitude: props.station?.latitude || '',
  longitude: props.station?.longitude || '',
  active: props.station?.active ?? true,
  can_sell_tickets: props.station?.can_sell_tickets ?? true
})

const submit = () => {
  if (props.station) {
    form.put(`/admin/stations/${props.station.id}`)
  } else {
    form.post('/admin/stations')
  }
}
</script>
<template>
  <SettingsLayout>
    <div class="h-[600px] bg-white rounded shadow border border-slate-200">
      <FormPanel @submit="submit">
        <template #header>
          <h2 class='text-xl font-semibold text-green-700 px-4 py-2'>{{ props.station ? 'Edit' : 'New' }} Station</h2>
        </template>
        <div class='grid gap-4 md:grid-cols-2 p-6'>
          <label>Name<input class='border rounded w-full p-2' name='name' v-model='form.name'/></label>
          <label>Code<input class='border rounded w-full p-2' name='code' v-model='form.code'/></label>
          <label>City<input class='border rounded w-full p-2' name='city' v-model='form.city'/></label>
          <label>Address<input class='border rounded w-full p-2' name='address' v-model='form.address'/></label>
          <label>Phone<input class='border rounded w-full p-2' name='phone' v-model='form.phone'/></label>
          <label>Latitude<input class='border rounded w-full p-2' name='latitude' type='number' step='any' v-model='form.latitude'/></label>
          <label>Longitude<input class='border rounded w-full p-2' name='longitude' type='number' step='any' v-model='form.longitude'/></label>
          <label class='flex items-center gap-2'>
            <input type='checkbox' name='active' v-model='form.active'/>
            Active
          </label>
          <label class='flex items-center gap-2'>
            <input type='checkbox' name='can_sell_tickets' v-model='form.can_sell_tickets'/>
            Peut vendre des billets
          </label>
        </div>
        <template #actions>
          <button type="submit" :disabled="form.processing" class='px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50'>Save</button>
        </template>
      </FormPanel>
    </div>
  </SettingsLayout>
</template>
