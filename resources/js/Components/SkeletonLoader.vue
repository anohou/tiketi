<script setup>
defineProps({
  type: {
    type: String,
    default: 'list' // list, card, text
  },
  count: {
    type: Number,
    default: 3
  }
});
</script>

<template>
  <div class="animate-pulse space-y-3 w-full">
    <template v-if="type === 'list'">
      <div v-for="i in count" :key="i" class="flex items-center gap-3 p-3 bg-slate-100/80 border border-slate-100 rounded-xl">
        <div class="w-10 h-10 bg-slate-200 rounded-lg shrink-0"></div>
        <div class="flex-1 space-y-2">
          <div class="h-3 bg-slate-200 rounded w-1/3"></div>
          <div class="h-2 bg-slate-200 rounded w-1/4"></div>
        </div>
      </div>
    </template>
    
    <template v-else-if="type === 'card'">
      <div v-for="i in count" :key="i" class="p-4 bg-slate-100/80 border border-slate-100 rounded-2xl space-y-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3 flex-1">
            <div class="w-10 h-10 bg-slate-200 rounded-xl shrink-0"></div>
            <div class="flex-1 space-y-2">
              <div class="h-3 bg-slate-200 rounded w-2/3"></div>
              <div class="h-2 bg-slate-200 rounded w-1/3"></div>
            </div>
          </div>
          <div class="w-16 h-8 bg-slate-200 rounded-lg shrink-0"></div>
        </div>
        <div class="h-px bg-slate-200/50"></div>
        <div class="flex gap-2">
          <div class="h-10 bg-slate-200 rounded-xl flex-1"></div>
          <div class="h-10 bg-slate-200 rounded-xl flex-1"></div>
        </div>
      </div>
    </template>
    
    <template v-else-if="type === 'text'">
      <div class="space-y-2">
        <div class="h-4 bg-slate-200 rounded w-3/4"></div>
        <div class="h-3 bg-slate-200 rounded w-1/2"></div>
        <div class="h-3 bg-slate-200 rounded w-5/6"></div>
      </div>
    </template>
  </div>
</template>
