<script setup>
defineProps({
  src: String,
  title: {
    type: String,
    default: 'Aperçu',
  },
});
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-orange-200 bg-white shadow-sm">
    <div class="flex items-center gap-1.5 border-b border-orange-100 bg-gray-50 px-3 py-2">
      <span class="h-2.5 w-2.5 rounded-full bg-red-300"></span>
      <span class="h-2.5 w-2.5 rounded-full bg-yellow-300"></span>
      <span class="h-2.5 w-2.5 rounded-full bg-green-300"></span>
      <span class="ml-2 truncate text-[11px] font-bold text-gray-500">{{ title }}</span>
    </div>
    <div class="bg-gray-100">
      <img
        v-if="src"
        :src="src"
        :alt="title"
        class="h-64 w-full object-contain bg-white md:h-72"
      />
      <div v-else class="grid h-64 place-items-center bg-white md:h-72">
        <div class="w-4/5 space-y-3">
          <div class="h-5 w-2/3 rounded bg-gray-200"></div>
          <div class="grid grid-cols-3 gap-2">
            <div class="h-16 rounded border border-gray-200 bg-gray-50"></div>
            <div class="h-16 rounded border border-gray-200 bg-gray-50"></div>
            <div class="h-16 rounded border border-gray-200 bg-gray-50"></div>
          </div>
          <div class="h-3 rounded bg-gray-200"></div>
          <div class="h-3 w-3/4 rounded bg-gray-200"></div>
        </div>
      </div>
    </div>
  </div>
</template>
