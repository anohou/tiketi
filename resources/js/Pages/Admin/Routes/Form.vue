<script setup>
import SettingsLayout from '@/Layouts/SettingsLayout.vue'
const props = defineProps({ routeItem: Object, stations: Array })
</script>
<template>
  <SettingsLayout>
    <div class='grid grid-cols-12 gap-4'>
      <div class='col-span-12 md:col-span-6 bg-white rounded shadow border border-slate-200 p-4'>
        <div class='font-semibold text-green-700 mb-3'>{{ props.routeItem ? 'Modifier une route' : 'Créer une route' }}</div>
        <form :action="props.routeItem ? `/admin/routes/${props.routeItem.id}` : '/admin/routes'" method='post'>
          <input type='hidden' name='_token' :value="document.querySelector('meta[name=csrf-token]')?.content"/>
          <template v-if="props.routeItem"><input type='hidden' name='_method' value='PUT'/></template>
          <div class='space-y-3'>
            <label>Nom<input class='border rounded w-full p-2' name='name' :value="props.routeItem?.name || ''"/></label>
            <label>Origine
              <select name='origin_station_id' class='border rounded w-full p-2'>
                <option v-for='s in props.stations' :key='s.id' :value='s.id' :selected='props.routeItem?.origin_station_id===s.id'>{{ s.name }}</option>
              </select>
            </label>
            <label>Destination
              <select name='destination_station_id' class='border rounded w-full p-2'>
                <option v-for='s in props.stations' :key='s.id' :value='s.id' :selected='props.routeItem?.destination_station_id===s.id'>{{ s.name }}</option>
              </select>
            </label>
            <label class='flex items-center gap-2'><input type='checkbox' name='active' :checked='props.routeItem?.active ?? true'/> Actif</label>
          </div>
          <div class='mt-4'><button class='px-3 py-2 bg-green-600 text-white rounded'>Enregistrer</button></div>
        </form>
      </div>
      <div class='col-span-12 md:col-span-6'>
        <!-- Placeholder pane for stops and fares management (next step) -->
      </div>
    </div>
  </SettingsLayout>
</template>
