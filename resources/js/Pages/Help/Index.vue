<script setup>
import { Link } from '@inertiajs/vue3';
import { useTheme } from '@/Composables/useTheme.js';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import HelpContent from './Content.vue';

defineProps({
  public: {
    type: Boolean,
    default: false,
  },
});

const { isDark } = useTheme();
</script>

<template>
  <!-- Documentation publique : accessible avant connexion (tenant ou domaine central) -->
  <div v-if="public" class="flex h-screen w-screen flex-col overflow-hidden bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <header class="shrink-0 border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
      <div class="mx-auto flex max-w-[1500px] items-center justify-between gap-4">
        <div class="flex min-w-0 items-center gap-3">
          <img
            :src="isDark ? '/images/logo-white.png' : '/images/logo.png'"
            alt="TIKÊTI Logo"
            class="h-10 w-auto object-contain"
          />
          <span class="hidden text-xs font-black uppercase tracking-[0.18em] text-emerald-600 dark:text-emerald-400 sm:block">
            Documentation utilisateur
          </span>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <ThemeToggle />
          <Link
            :href="route('login')"
            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700"
          >
            Se connecter
          </Link>
        </div>
      </div>
    </header>
    <div class="min-h-0 flex-1 overflow-hidden">
      <HelpContent :public="true" />
    </div>
  </div>

  <!-- Utilisateur connecté : layout de navigation habituel -->
  <MainNavLayout v-else :fullHeight="true">
    <HelpContent :public="false" />
  </MainNavLayout>
</template>
