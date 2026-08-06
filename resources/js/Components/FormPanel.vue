<script setup>
/**
 * FormPanel — Layout component for long forms.
 *
 * Provides:
 *   - optional header (slot "header")
 *   - scrollable form body (default slot)
 *   - sticky footer with actions (slot "actions")
 *   - optional secondary actions area (slot "secondary-actions")
 *
 * The footer remains visible at the bottom of the panel while the
 * form fields scroll independently. 
 */
defineEmits(['submit']);
</script>

<template>
  <form @submit.prevent="$emit('submit')" class="flex flex-col h-full min-h-0 w-full relative">
    <!-- Optional header -->
    <div v-if="$slots.header" class="shrink-0">
      <slot name="header" />
    </div>

    <!-- Scrollable form area -->
    <div class="flex-1 overflow-y-auto custom-scrollbar relative z-0">
      <slot />
      <!-- Extra space so the last field is never hidden behind the sticky footer -->
      <div class="pb-8" />
    </div>

    <!-- Sticky footer -->
    <div
      v-if="$slots.actions || $slots['secondary-actions']"
      class="shrink-0 border-t border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95 px-6 py-4 z-10 flex flex-col sm:flex-row items-center justify-between gap-4"
    >
      <div class="flex items-center gap-4 w-full sm:w-auto">
        <slot name="secondary-actions" />
      </div>
      <div class="flex items-center justify-end gap-3 w-full sm:w-auto">
        <slot name="actions" />
      </div>
    </div>
  </form>
</template>
