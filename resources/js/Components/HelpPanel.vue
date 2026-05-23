<script setup>
import { Link } from '@inertiajs/vue3';
import Close from 'vue-material-design-icons/Close.vue';
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue';
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue';
import HelpScreenshot from '@/Components/HelpScreenshot.vue';

defineProps({
  show: Boolean,
  topic: Object,
});

const emit = defineEmits(['close']);
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-[120]">
    <div class="absolute inset-0 bg-black/30 backdrop-blur-sm" @click="emit('close')"></div>
    <aside class="absolute inset-y-0 right-0 flex w-full max-w-[420px] flex-col bg-white shadow-2xl">
      <div class="flex items-start justify-between border-b border-orange-100 p-5">
        <div class="flex gap-3">
          <div class="grid h-10 w-10 place-items-center rounded-xl bg-green-100 text-green-700">
            <HelpCircleOutline :size="22" />
          </div>
          <div>
            <div class="text-xs font-black uppercase tracking-widest text-orange-600">Aide contextuelle</div>
            <h2 class="mt-1 text-xl font-black leading-tight text-gray-900">{{ topic?.title }}</h2>
          </div>
        </div>
        <button class="rounded-xl p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700" @click="emit('close')">
          <Close :size="24" />
        </button>
      </div>

      <div class="flex-1 overflow-y-auto p-5">
        <p class="text-sm leading-6 text-gray-600">{{ topic?.description }}</p>

        <HelpScreenshot class="mt-5" :src="topic?.image" :title="topic?.title" />

        <div class="mt-6 space-y-5">
          <section v-for="section in topic?.sections" :key="section.title" class="rounded-lg border border-orange-100 bg-orange-50/30 p-4">
            <h3 class="font-black text-gray-900">{{ section.title }}</h3>
            <p class="mt-2 text-sm leading-6 text-gray-600">{{ section.body }}</p>
            <div v-if="section.links?.length" class="mt-3 grid gap-2">
              <a
                v-for="link in section.links"
                :key="link.url"
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm font-black text-green-700 hover:text-green-800 hover:underline"
              >
                {{ link.label }}
              </a>
            </div>
            <ol v-if="section.steps?.length" class="mt-3 space-y-2">
              <li v-for="(step, index) in section.steps" :key="step" class="flex gap-3 text-sm leading-5 text-gray-700">
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-green-600 text-[10px] font-black text-white">{{ index + 1 }}</span>
                <span>{{ step }}</span>
              </li>
            </ol>
          </section>
        </div>
      </div>

      <div class="border-t border-orange-100 p-4">
        <Link :href="route('help.index')" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3 text-sm font-black text-white shadow-lg shadow-green-100 hover:bg-green-700" @click="emit('close')">
          <OpenInNew :size="18" />
          Ouvrir le centre d’aide
        </Link>
      </div>
    </aside>
  </div>
</template>
