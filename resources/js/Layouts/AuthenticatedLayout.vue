<template>
    <div>
        <div class="min-h-screen bg-green-50">
            <nav class="bg-white border-b border-orange-200">
                <!-- Primary Navigation Menu -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <Link :href="route('dashboard')" class="flex items-center gap-2">
                                    <div class="flex items-center">
                                        <Earth class="text-green-700" :size="32"/>
                                        <div class="ml-2">
                                            <span class="font-bold text-xl text-green-700">i</span> - 
                                            <span class="font-bold text-xl text-orange-500">Ticket</span>
                                        </div>
                                    </div>
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                                <NavLink 
                                    :href="route('dashboard')" 
                                    :active="route().current('dashboard')"
                                    class="text-green-700 hover:text-green-800"
                                >
                                    Dashboard
                                </NavLink>
                                
                                <!-- Ticketing Section -->
                                <div class="relative group">
                                    <button class="flex items-center text-green-700 hover:text-green-800 px-3 py-2 rounded-md text-sm font-medium">
                                        <Ticket class="mr-2" :size="20"/>
                                        Ticketing
                                        <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                    <div class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 border border-orange-200">
                                        <div class="py-1">
                                            <Link :href="route('seller.dashboard')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Sell Tickets
                                            </Link>
                                            <Link :href="route('admin.trips.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Manage Trips
                                            </Link>
                                            <Link :href="route('seller.tickets.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                View Tickets
                                            </Link>
                                        </div>
                                    </div>
                                </div>

                                <!-- Configurations Section -->
                                <div class="relative group">
                                    <button class="flex items-center text-green-700 hover:text-green-800 px-3 py-2 rounded-md text-sm font-medium">
                                        <Settings class="mr-2" :size="20"/>
                                        Configurations
                                        <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                    <div class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 border border-orange-200">
                                        <div class="py-1">
                                            <Link :href="route('admin.stations.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Stations
                                            </Link>
                                            <Link :href="route('admin.routes.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Trajets
                                            </Link>
                                            <Link :href="route('admin.vehicles.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Vehicles
                                            </Link>
                                            <Link :href="route('admin.vehicle-types.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Vehicle Types
                                            </Link>
                                            <Link :href="route('admin.trips.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Trips
                                            </Link>
                                            <Link :href="route('admin.assignments.index')" class="block px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                                Assignments
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex sm:items-center sm:ml-6">
                            <!-- Settings Dropdown -->
                            <div class="ml-3 relative">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center px-3 py-2 border border-orange-200 
                                                       text-sm leading-4 font-medium rounded-md text-green-700 
                                                       bg-white hover:text-green-800 hover:bg-green-50 
                                                       focus:outline-none focus:bg-green-50 
                                                       transition ease-in-out duration-150"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="ml-2 -mr-0.5 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink 
                                            :href="route('profile.edit')"
                                            class="text-green-700 hover:bg-green-50"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink 
                                            :href="route('logout')" 
                                            method="post" 
                                            as="button"
                                            class="text-green-700 hover:bg-green-50"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-mr-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center p-2 rounded-md 
                                       text-green-600 hover:text-green-700 hover:bg-green-50 
                                       focus:outline-none focus:bg-green-50 transition duration-150 ease-in-out"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex': !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex': showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                    class="sm:hidden"
                >
                    <div class="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink 
                            :href="route('dashboard')" 
                            :active="route().current('dashboard')"
                            class="text-green-700 hover:text-green-800 hover:bg-green-50"
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        
                        <!-- Mobile Ticketing Section -->
                        <div class="px-4 py-2">
                            <div class="text-sm font-medium text-green-700 mb-2 flex items-center">
                                <Ticket class="mr-2" :size="20"/>
                                Ticketing
                            </div>
                            <div class="ml-4 space-y-1">
                                <ResponsiveNavLink :href="route('seller.dashboard')" class="text-green-700 hover:bg-green-50">
                                    Sell Tickets
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.trips.index')" class="text-green-700 hover:bg-green-50">
                                    Manage Trips
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('seller.tickets.index')" class="text-green-700 hover:bg-green-50">
                                    View Tickets
                                </ResponsiveNavLink>
                            </div>
                        </div>

                        <!-- Mobile Configurations Section -->
                        <div class="px-4 py-2">
                            <div class="text-sm font-medium text-green-700 mb-2 flex items-center">
                                <Settings class="mr-2" :size="20"/>
                                Configurations
                            </div>
                            <div class="ml-4 space-y-1">
                                <ResponsiveNavLink :href="route('admin.stations.index')" class="text-green-700 hover:bg-green-50">
                                    Gares
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.routes.index')" class="text-green-700 hover:bg-green-50">
                                    Routes
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.vehicles.index')" class="text-green-700 hover:bg-green-50">
                                    Vehicles
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.vehicle-types.index')" class="text-green-700 hover:bg-green-50">
                                    Vehicle Types
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.trips.index')" class="text-green-700 hover:bg-green-50">
                                    Trips
                                </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('admin.assignments.index')" class="text-green-700 hover:bg-green-50">
                                    Assignments
                                </ResponsiveNavLink>
                            </div>
                        </div>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="pt-4 pb-1 border-t border-orange-200">
                        <div class="px-4">
                            <div class="font-medium text-base text-green-800">
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="font-medium text-sm text-orange-600">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink 
                                :href="route('profile.edit')"
                                class="text-green-700 hover:bg-green-50"
                            >
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink 
                                :href="route('logout')" 
                                method="post" 
                                as="button"
                                class="text-green-700 hover:bg-green-50"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header class="bg-white shadow border-b border-orange-200" v-if="$slots.header">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import Earth from 'vue-material-design-icons/Earth.vue';
import Ticket from 'vue-material-design-icons/Ticket.vue';
import Settings from 'vue-material-design-icons/Cog.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>