<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const imageError = ref(false);

defineProps({
    canLogin: {
        type: Boolean,
    },
    // We don't need canRegister since we removed the register page/button
});
</script>

<template>
    <Head title="TIKETI - Gestion des Billetteries & Transports" />

    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white overflow-x-hidden">
        
        <!-- Navigation -->
        <header class="absolute inset-x-0 top-0 z-50">
            <nav class="mx-auto flex max-w-7xl items-center justify-between p-6 lg:px-8" aria-label="Global">
                <div class="flex lg:flex-1">
                    <a href="#" class="-m-1.5 p-1.5 flex items-center gap-2">
                        <span class="sr-only">TIKETI</span>
                        <img class="h-20 sm:h-24 w-auto drop-shadow-sm transition-transform hover:scale-105" src="/images/logo-transparent.png" alt="Tiketi Logo" />
                    </a>
                </div>
                
                <div v-if="canLogin" class="flex flex-1 justify-end gap-x-4 h-full items-center">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="text-sm font-semibold leading-6 text-gray-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                    >
                        Dashboard <span aria-hidden="true">&rarr;</span>
                    </Link>

                    <template v-else>
                        <a
                            href="#contact"
                            class="rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 hover:from-blue-500 hover:to-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition-all duration-300 transform hover:-translate-y-0.5"
                        >
                            Contactez-nous
                        </a>
                    </template>
                </div>
            </nav>
        </header>

        <!-- Hero Section with Animation -->
        <div class="relative isolate pt-14">
            <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80" aria-hidden="true">
                <div class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-20 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]" style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"></div>
            </div>
            
            <div class="py-24 sm:py-32 lg:pb-40">
                <div class="mx-auto max-w-7xl px-6 lg:px-8">
                    
                    <div class="grid lg:grid-cols-2 gap-12 items-center">
                        <!-- Hero Text -->
                        <div class="max-w-2xl text-center lg:text-left">
                            <h1 class="text-4xl font-extrabold tracking-tight sm:text-6xl text-gray-900 dark:text-white">
                                Le Futur de la <br />
                                <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-500 to-cyan-500">Gestion de Transport</span>
                            </h1>
                            <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                                L'outil ultime pour vos agents de billetterie, conçu pour la rapidité et la précision. Prenez le contrôle total de votre infrastructure de transport et optimisez vos revenus avec l'attribution intelligente des sièges.
                            </p>
                            <div class="mt-10 flex items-center justify-center lg:justify-start gap-x-6">
                                <a href="#contact" class="rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 hover:from-blue-500 hover:to-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition-all duration-300 transform hover:-translate-y-1">
                                    Demander une Démo
                                </a>
                                <a href="#features" class="text-sm font-semibold leading-6 text-gray-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition group flex items-center gap-2">
                                    Découvrir <span aria-hidden="true" class="inline-block transition-transform group-hover:translate-y-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 rounded-full p-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg></span>
                                </a>
                            </div>
                        </div>

                        <!-- Hero Animation/Image (Seat Assignment) -->
                        <div class="hidden lg:flex justify-center flex-col items-center max-w-lg mx-auto relative perspective-1000 group w-full">
                            <!-- Animated Background glow -->
                            <div class="absolute inset-0 bg-gradient-to-r from-indigo-500 to-cyan-500 rounded-3xl blur-2xl transform rotate-3 opacity-30 group-hover:opacity-50 group-hover:rotate-6 transition duration-700 ease-in-out"></div>
                            
                            <div class="relative w-full bg-white dark:bg-gray-800 rounded-3xl p-3 shadow-2xl border border-gray-100 dark:border-gray-700 overflow-hidden transform group-hover:scale-105 transition-all duration-500 flex flex-col items-center">
                                
                                <div class="bg-gray-100 dark:bg-gray-900 rounded-2xl w-full aspect-[4/3] flex items-center justify-center relative overflow-hidden group-hover:shadow-[inset_0_0_20px_rgba(0,0,0,0.05)] transition-shadow">
                                     <!-- User's Actual Seat Image -->
                                     <img v-show="!imageError" src="/images/seat-map.png" @error="imageError = true" alt="Disposition des sièges" class="absolute inset-0 w-full h-full object-cover opacity-90 transition-opacity hover:opacity-100 z-10" />
                                     
                                     <!-- Fallback text / Empty state if missing -->
                                     <div v-show="imageError" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-100 dark:bg-gray-900 border-2 border-dashed border-indigo-300 dark:border-indigo-700 m-4 rounded-xl z-0 p-6 text-center">
                                         <svg class="w-10 h-10 text-indigo-400 mb-2 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                         </svg>
                                         <span class="text-sm font-semibold text-gray-600 dark:text-gray-400">Si image non trouvée, assurez-vous que <br/><code class="text-indigo-600 bg-indigo-50 dark:text-indigo-300 dark:bg-indigo-900/30 px-1 py-0.5 rounded">seat-map.png</code> est bien dans public/images</span>
                                     </div>
                                </div>

                                <!-- Decorative element -->
                                <div class="absolute bottom-6 right-6 px-4 py-2 bg-white/90 dark:bg-gray-800/90 backdrop-blur shadow-lg rounded-full flex items-center gap-2 transform translate-y-2 opacity-0 group-hover:translate-y-0 group-hover:opacity-100 transition-all duration-300 delay-100 border border-gray-100 dark:border-gray-700">
                                    <span class="relative flex h-3 w-3">
                                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                      <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                                    </span>
                                    <span class="text-xs font-bold text-gray-700 dark:text-gray-200">Attribution en direct</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div id="features" class="py-24 sm:py-32 bg-white dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-800">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Tout-en-un</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                        Des fonctionnalités pensées pour vous
                    </p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Dans un secteur en constante évolution, TIKETI vous offre une longueur d’avance. Fini les pertes de revenus, les guichetiers débordés et le manque de visibilité.
                    </p>
                </div>

                <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
                    <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
                        
                        <!-- Feature 1 -->
                        <div class="flex flex-col p-8 rounded-2xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                            <dt class="flex items-center gap-x-3 text-xl font-semibold leading-7 text-gray-900 dark:text-white">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 dark:bg-indigo-900/30">
                                    <svg class="h-6 w-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z" />
                                    </svg>
                                </div>
                                Billetterie Intelligente
                            </dt>
                            <dd class="mt-6 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-300">
                                <ul class="space-y-3 flex-1 list-disc pl-5">
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Plan de Siège Interactif:</strong> Visualisez l'occupation de vos cars en un coup d'œil.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Impression Bluetooth:</strong> Tickets sécurisés avec QR Code.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Gestion des Escales:</strong> Vendez des trajets complexes sans conflit.</li>
                                </ul>
                            </dd>
                        </div>

                        <!-- Feature 2 -->
                        <div class="flex flex-col p-8 rounded-2xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                            <dt class="flex items-center gap-x-3 text-xl font-semibold leading-7 text-gray-900 dark:text-white">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-100 dark:bg-cyan-900/30">
                                    <svg class="h-6 w-6 text-cyan-600 dark:text-cyan-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                                    </svg>
                                </div>
                                Administration & Finance
                            </dt>
                            <dd class="mt-6 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-300">
                                <ul class="space-y-3 flex-1 list-disc pl-5">
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Dashboard Stratégique:</strong> Visualisation en temps réel du chiffre d’affaires et volume de ventes.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Gestion du Réseau:</strong> Gares, destinations et flexibilité tarifaire.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">Comptabilité:</strong> Rapports de fermeture de caisse par vendeur.</li>
                                </ul>
                            </dd>
                        </div>

                        <!-- Feature 3 -->
                        <div class="flex flex-col p-8 rounded-2xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                            <dt class="flex items-center gap-x-3 text-xl font-semibold leading-7 text-gray-900 dark:text-white">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-purple-100 dark:bg-purple-900/30">
                                    <svg class="h-6 w-6 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                                    </svg>
                                </div>
                                Apps Mobiles Dédiées
                            </dt>
                            <dd class="mt-6 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-300">
                                <ul class="space-y-3 flex-1 list-disc pl-5">
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">App Vendeur (Guichet):</strong> Interface ultra-rapide pour émettre des billets.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">App Superviseur:</strong> Tour de contrôle, scan des QR codes sur le terrain.</li>
                                    <li><strong class="font-semibold text-gray-900 dark:text-gray-100">App Chauffeur:</strong> Manifeste numérique et validation à l'embarquement.</li>
                                </ul>
                            </dd>
                        </div>

                    </dl>
                </div>
            </div>
        </div>

        <!-- Les Roles Section with Image de Guichet -->
        <div id="roles" class="py-24 sm:py-32 bg-gray-50 dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="grid lg:grid-cols-2 gap-16 items-center">
                    
                    <!-- Roles Text & Grid -->
                    <div>
                        <div class="max-w-2xl lg:mx-0">
                            <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Une Solution pour Chaque Acteur</h2>
                            <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">TIKETI structure votre entreprise en définissant clairement les responsabilités.</p>
                        </div>
                        
                        <div class="mt-12 grid max-w-2xl grid-cols-1 gap-8 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-2">
                            <!-- Administrateur -->
                            <div class="flex flex-col p-6 rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:shadow-md transition-shadow">
                                <h3 class="flex items-center gap-x-3 text-lg font-semibold leading-8 text-gray-900 dark:text-white">
                                    <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">1</span>
                                    L'Administrateur
                                </h3>
                                <p class="mt-4 flex-1 text-base leading-7 text-gray-600 dark:text-gray-300">
                                    Crée les lignes, fixe les prix, gère les rôles et analyse la rentabilité globale.
                                </p>
                            </div>

                            <!-- Superviseur -->
                            <div class="flex flex-col p-6 rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:shadow-md transition-shadow">
                                <h3 class="flex items-center gap-x-3 text-lg font-semibold leading-8 text-gray-900 dark:text-white">
                                    <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-600 dark:bg-orange-900/30 dark:text-orange-400">2</span>
                                    Superviseur
                                </h3>
                                <p class="mt-4 flex-1 text-base leading-7 text-gray-600 dark:text-gray-300">
                                    Gestion terrain, contrôle des tickets et supervision des ventes aux guichets.
                                </p>
                            </div>

                            <!-- Vendeur -->
                            <div class="flex flex-col p-6 rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:shadow-md transition-shadow">
                                <h3 class="flex items-center gap-x-3 text-lg font-semibold leading-8 text-gray-900 dark:text-white">
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">3</span>
                                    Le Vendeur
                                </h3>
                                <p class="mt-4 flex-1 text-base leading-7 text-gray-600 dark:text-gray-300">
                                    Interface de vente ultra-rapide. Attribution des sièges en temps réel.
                                </p>
                            </div>

                            <!-- Executif -->
                            <div class="flex flex-col p-6 rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:shadow-md transition-shadow">
                                <h3 class="flex items-center gap-x-3 text-lg font-semibold leading-8 text-gray-900 dark:text-white">
                                    <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-600 dark:bg-rose-900/30 dark:text-rose-400">4</span>
                                    L'Exécutif 
                                </h3>
                                <p class="mt-4 flex-1 text-base leading-7 text-gray-600 dark:text-gray-300">
                                    Audit et vue consolidée : accès aux rapports financiers détaillés et consolidés.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Selling Desk Image Mockup -->
                    <div class="relative w-full aspect-square md:aspect-[4/3] lg:aspect-square overflow-hidden rounded-3xl shadow-2xl group">
                        <!-- Fancy background behind image -->
                        <div class="absolute inset-0 bg-gradient-to-tr from-indigo-500 to-cyan-400 mix-blend-multiply opacity-20 group-hover:opacity-10 transition-opacity duration-300 z-10"></div>
                        <img src="/images/selling-desk.jpeg" alt="Guichet de Vente Tiketi" class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105 animate-slide-wide" />
                        
                        <!-- Floating Badge -->
                        <div class="absolute bottom-6 left-6 z-20 bg-white/90 dark:bg-gray-900/90 backdrop-blur border border-white/20 dark:border-gray-700 shadow-xl rounded-2xl px-6 py-4 transform translate-y-4 opacity-0 group-hover:translate-y-0 group-hover:opacity-100 transition-all duration-500 delay-100">
                            <div class="flex items-center gap-3">
                                <div class="bg-indigo-100 dark:bg-indigo-900/50 p-2 rounded-full">
                                    <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Opérations Fluides</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Sur le terrain</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>

        <!-- Carte de Fidélité Section -->
        <div id="loyalty" class="py-24 sm:py-32 bg-white dark:bg-gray-950 overflow-hidden border-t border-gray-100 dark:border-gray-800">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 sm:gap-y-20 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                    <div class="lg:pr-8 lg:pt-4">
                        <div class="lg:max-w-lg">
                            <div class="mb-6">
                                <img src="/images/okohi-logo.png" alt="OKOHI Logo" class="h-20 w-auto" />
                            </div>
                            <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">
                                Exclusivité OKOHI
                            </h2>
                            <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Fidélisez vos Voyageurs</p>
                            <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                                Grâce à l'intégration native avec l'écosystème **OKOHI**, offrez à vos passagers une expérience de fidélité moderne et 100% numérique.
                            </p>
                            <dl class="mt-10 max-w-xl space-y-8 text-base leading-7 text-gray-600 dark:text-gray-300 lg:max-w-none">
                                <div class="relative pl-9">
                                    <dt class="inline font-semibold text-gray-900 dark:text-white">
                                        <svg class="absolute left-1 top-1 h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h.01M16 12h.01M8 12h.01M12 16h.01M16 16h.01M8 16h.01" />
                                        </svg>
                                        QR Code Intelligent.
                                    </dt>
                                    <dd class="inline"> Un scan rapide à chaque achat pour cumuler des points instantanément.</dd>
                                </div>
                                <div class="relative pl-9">
                                    <dt class="inline font-semibold text-gray-900 dark:text-white">
                                        <svg class="absolute left-1 top-1 h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Récompenses Automatiques.
                                    </dt>
                                    <dd class="inline"> Après 10 voyages, débloquez des réductions ou des trajets gratuits configurés par vos soins.</dd>
                                </div>
                                <div class="relative pl-9">
                                    <dt class="inline font-semibold text-gray-900 dark:text-white">
                                        <svg class="absolute left-1 top-1 h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        Analyse de Rétention.
                                    </dt>
                                    <dd class="inline"> Identifiez vos clients les plus réguliers et adaptez vos offres commerciales.</dd>
                                </div>
                            </dl>
                            <div class="mt-10 pt-6 border-t border-gray-100 dark:border-gray-800">
                                <a href="https://play.google.com/store/apps/details?id=com.anohou.okohi" target="_blank" class="inline-flex items-center gap-x-3 rounded-2xl bg-gray-900 hover:bg-black px-6 py-3 text-sm font-semibold text-white shadow-xl transition-all duration-300 hover:scale-105 active:scale-95">
                                    <svg class="h-6 w-6 fill-current" viewBox="0 0 24 24">
                                        <path d="M5,3L13.5,12L5,21V3M17.66,10.12L20.33,11.5C21,11.84 21,12.16 20.33,12.5L17.66,13.88L14.73,12.3L17.66,10.12M13.5,12L14.73,12.3L4.66,21.64C4.47,21.89 4.23,22 4,22A1,1 0 0,1 3,21V3C3,2.77 3.08,2.54 3.23,2.36L13.5,12Z" />
                                    </svg>
                                    <div class="text-left">
                                        <div class="text-[10px] uppercase leading-none opacity-70">Disponible sur</div>
                                        <div class="text-base leading-none mt-1">Google Play</div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="relative flex items-start justify-center lg:justify-end">
                        <div class="relative rounded-3xl overflow-hidden shadow-2xl ring-1 ring-gray-900/10 dark:ring-white/10 group bg-indigo-50 dark:bg-indigo-900/20 p-8 lg:p-12">
                            <!-- Background glow -->
                            <div class="absolute inset-0 bg-gradient-to-tr from-indigo-500/20 to-cyan-500/20 opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
                            
                            <img src="/images/loyalty-card.png" alt="OKOHI Loyalty Card Screenshot" class="w-[20rem] sm:w-[24rem] rounded-3xl shadow-2xl transition-all duration-700 group-hover:scale-105 group-hover:rotate-2" />
                            
                            <!-- Floating Badge -->
                            <div class="absolute top-12 right-12 bg-white/90 dark:bg-gray-800/90 backdrop-blur-md px-4 py-2 rounded-full shadow-lg border border-white/20 flex items-center gap-2 transform translate-x-4 opacity-0 group-hover:translate-x-0 group-hover:opacity-100 transition-all duration-500">
                                <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                <span class="text-xs font-bold text-gray-700 dark:text-gray-200">Synchronisé OKOHI</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pourquoi TIKETI (Benefits) Section -->
        <div class="bg-indigo-600 dark:bg-indigo-900 py-24 sm:py-32 relative isolate overflow-hidden">
            <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-500 via-indigo-600 to-indigo-800 dark:from-indigo-800 dark:via-indigo-900 dark:to-gray-900"></div>
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Pourquoi TIKETI ?</h2>
                    <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-indigo-100">
                        Maximisez vos profits et modernisez votre image de marque grâce à une solution robuste.
                    </p>
                </div>
                <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
                    <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3 transform hover:scale-105 transition-transform duration-500 ease-out">
                        <div class="flex flex-col items-center text-center">
                            <dt class="text-xl font-semibold leading-7 text-white mt-4 flex flex-col items-center gap-4">
                                <div class="rounded-full bg-white/10 p-3 ring-1 ring-white/20 hover:bg-white/20 transition-colors">
                                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                Temps Réel Absolu
                            </dt>
                            <dd class="mt-4 text-base leading-7 text-indigo-100">Fini les doubles ventes. Quand un ticket est vendu à la gare A, la place est instantanément bloquée pour la gare B.</dd>
                        </div>
                        <div class="flex flex-col items-center text-center">
                            <dt class="text-xl font-semibold leading-7 text-white mt-4 flex flex-col items-center gap-4">
                                <div class="rounded-full bg-white/10 p-3 ring-1 ring-white/20 hover:bg-white/20 transition-colors">
                                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                                    </svg>
                                </div>
                                Optimisation des Revenus
                            </dt>
                            <dd class="mt-4 text-base leading-7 text-indigo-100">Grâce à notre système de tronçons intelligents, une place libérée devient immédiatement revendable pour la suite du trajet.</dd>
                        </div>
                        <div class="flex flex-col items-center text-center">
                            <dt class="text-xl font-semibold leading-7 text-white mt-4 flex flex-col items-center gap-4">
                                <div class="rounded-full bg-white/10 p-3 ring-1 ring-white/20 hover:bg-white/20 transition-colors">
                                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                    </svg>
                                </div>
                                Contrôle Total
                            </dt>
                            <dd class="mt-4 text-base leading-7 text-indigo-100">Suivez chaque centime. Des rapports financiers détaillés aux audits de caisse instantanés, vous avez l’œil sur tout.</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
        
        <!-- Contact Form Section replacing Register -->
        <div id="contact" class="py-24 sm:py-32 bg-white dark:bg-gray-950 border-t border-gray-100 dark:border-gray-800">
             <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Prêt à moderniser votre flotte ?</h2>
                    <p class="mx-auto mt-4 max-w-xl text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Laissez-nous un message et notre équipe d'experts vous contactera pour une démonstration personnalisée.
                    </p>
                </div>
                
                <form class="mx-auto mt-16 max-w-xl sm:mt-20">
                    <div class="grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="company" class="block text-sm font-semibold leading-6 text-gray-900 dark:text-white">Nom de l'entreprise</label>
                            <div class="mt-2.5">
                                <input type="text" name="company" id="company" class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 dark:text-white dark:bg-gray-800 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="Ex: UTB Transports">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="email" class="block text-sm font-semibold leading-6 text-gray-900 dark:text-white">Email professionnel</label>
                            <div class="mt-2.5">
                                <input type="email" name="email" id="email" class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 dark:text-white dark:bg-gray-800 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="vous@entreprise.ci">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="phone" class="block text-sm font-semibold leading-6 text-gray-900 dark:text-white">Numéro de téléphone</label>
                            <div class="mt-2.5">
                                <input type="tel" name="phone" id="phone" class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 dark:text-white dark:bg-gray-800 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="+225 00 00 00 00 00">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="message" class="block text-sm font-semibold leading-6 text-gray-900 dark:text-white">Message (facultatif)</label>
                            <div class="mt-2.5">
                                <textarea name="message" id="message" rows="4" class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 dark:text-white dark:bg-gray-800 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="Parlez-nous de vos besoins..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="mt-10">
                        <button type="button" class="block w-full rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 px-3.5 py-4 text-center text-base font-semibold text-white shadow-lg shadow-indigo-500/30 hover:from-blue-500 hover:to-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 transition-all duration-300 transform hover:-translate-y-1">Demander une démo via WhatsApp</button>
                    </div>
                </form>
             </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800" aria-labelledby="footer-heading">
            <h2 id="footer-heading" class="sr-only">Footer</h2>
            <div class="mx-auto max-w-7xl px-6 pb-8 pt-16 sm:pt-24 lg:px-8 lg:pt-32">
                <div class="xl:grid xl:grid-cols-3 xl:gap-8">
                    <div class="space-y-8">
                        <div class="flex items-center gap-2">
                             <img class="h-16 sm:h-20 w-auto opacity-90 transition-all hover:opacity-100 hover:scale-105" src="/images/logo-transparent.png" alt="Tiketi Logo" />
                        </div>
                        <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">La solution complète pour la gestion de vos billetteries et de votre parc de transport.</p>
                        <div class="flex space-x-6">
                            <!-- Social links if needed -->
                        </div>
                    </div>
                </div>
                <div class="mt-16 border-t border-gray-200 dark:border-gray-800 pt-8 sm:mt-20 lg:mt-24">
                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">&copy; {{ new Date().getFullYear() }} TIKETI. Tous droits réservés.</p>
                </div>
            </div>
        </footer>

    </div>
</template>

<style scoped>
:root {
  --seat-avail: #E5E7EB;
  --seat-booked: #9CA3AF;
  --seat-highlight: #4F46E5;
}
.dark {
  --seat-avail: #374151;
  --seat-booked: #1F2937;
  --seat-highlight: #6366F1;
}

/* Base Seat Styles */
.svg-seat {
    transition: all 0.4s ease;
}

/* Available seats */
.available {
    fill: var(--seat-avail);
}

/* Already Booked seats */
.booked {
    fill: var(--seat-booked);
}

/* Simulated booking animations (sequencing) */
.booking-1 {
    animation: bookSeat 6s infinite 1s;
    fill: var(--seat-avail);
}
.booking-2 {
    animation: bookSeat 6s infinite 2.5s;
    fill: var(--seat-avail);
}
.booking-3 {
    animation: bookSeat 6s infinite 3.5s;
    fill: var(--seat-avail);
}
.booking-4 {
    animation: bookSeat 6s infinite 4.2s;
    fill: var(--seat-avail);
}

/* Tooltip animation bound to booking-1 */
.animate-tooltip-1 {
    animation: showTooltip 6s infinite 1s;
    opacity: 0;
}

/* Seat Assignment Animation */
@keyframes bookSeat {
    0% { fill: var(--seat-avail); transform: scale(1); }
    10% { fill: #10B981; transform: scale(1.15); } /* Flash Green */
    20% { fill: var(--seat-highlight); transform: scale(1); } /* Then Indigo mapped to assigned */
    70% { fill: var(--seat-highlight); transform: scale(1); }
    80% { fill: var(--seat-avail); transform: scale(1); }
    100% { fill: var(--seat-avail); transform: scale(1); }
}

@keyframes showTooltip {
    0% { opacity: 0; transform: translateY(5px); }
    10% { opacity: 1; transform: translateY(0); }
    30% { opacity: 1; transform: translateY(0); }
    40% { opacity: 0; transform: translateY(-5px); }
    100% { opacity: 0; transform: translateY(-5px); }
}

@keyframes scrollBg {
    from { transform: translateY(0); }
    to { transform: translateY(40px); }
}

.animate-scroll-bg {
    animation: scrollBg 2s linear infinite;
}

.animate-pulse-slow {
    animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Perspective wrapper for 3D effect */
.perspective-1000 {
    perspective: 1000px;
}

/* Wide Image Sliding Animation */
@keyframes slideMain {
    0% { object-position: 0% 50%; }
    50% { object-position: 100% 50%; }
    100% { object-position: 0% 50%; }
}

.animate-slide-wide {
    animation: slideMain 12s ease-in-out infinite;
}
</style>
