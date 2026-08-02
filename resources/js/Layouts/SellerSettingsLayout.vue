<script setup>
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import SettingsMenu from '@/Components/SettingsMenu.vue';

defineProps({
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: String,
    default: '',
  },
  icon: {
    type: Object,
    default: null,
  },
});
</script>

<template>
  <MainNavLayout :full-height="true" :hide-trip-sidebar="true">
    <div class="flex h-full w-full flex-col overflow-hidden">
      <div class="flex shrink-0 flex-col justify-between gap-4 px-6 pb-4 pt-6 md:flex-row md:items-center">
        <div>
          <h1 class="flex items-center gap-3 text-3xl font-black text-gray-900 dark:text-slate-100">
            <div v-if="icon" class="rounded-xl bg-green-100">
              <component :is="icon" class="text-emerald-600" :size="28" />
            </div>
            {{ title }}
          </h1>
          <p v-if="subtitle" class="mt-1 text-gray-500 dark:text-slate-400">{{ subtitle }}</p>
        </div>
      </div>

      <div class="grid min-h-0 flex-1 grid-cols-12 gap-4 px-6 pb-6">
        <div class="h-full overflow-y-auto pr-2 md:col-span-2">
          <SettingsMenu />
        </div>
        <div class="flex h-full min-h-0 flex-col md:col-span-10">
          <slot />
        </div>
      </div>
    </div>
  </MainNavLayout>
</template>
