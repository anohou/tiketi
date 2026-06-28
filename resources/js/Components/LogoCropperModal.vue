<template>
    <teleport to="body">
        <div class="fixed inset-0 z-[10000]">
            <div class="fixed inset-0 bg-black bg-opacity-50" @click="$emit('showModal', false)"></div>
            <div class="fixed inset-0 z-[10001] overflow-y-auto">
                <div class="flex flex-col min-h-full justify-center items-center py-2">
                    <div
                        class="transform overflow-hidden rounded-lg bg-white shadow-2xl transition-all max-w-xl w-full mx-4">
                        <!-- Modal Header -->
                        <div class="flex items-center py-4 px-4 border-b border-b-gray-300">
                            <div class="text-lg font-bold text-gray-800 w-full">
                                {{ modalTitle }}
                            </div>
                            <button @click="$emit('showModal', false)"
                                class="rounded-full p-1.5 bg-gray-200 hover:bg-gray-300 cursor-pointer" type="button">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Modal Body -->
                        <div class="flex flex-col items-center bg-white px-4 pb-4 pt-2">
                            <!-- Upload Button -->
                            <div class="w-full mb-4">
                                <label for="logo-image-input"
                                    class="flex items-center justify-center gap-2 bg-green-50 hover:bg-green-100 font-medium p-3 rounded-lg text-green-600 w-full cursor-pointer border border-green-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Sélectionner une image
                                </label>
                                <input type="file" id="logo-image-input" ref="fileInput" class="hidden" accept="image/*"
                                    @change="getUploadedImage">
                            </div>

                            <!-- Cropper Container -->
                            <div class="w-full max-w-md mx-auto"
                                :class="{ 'h-64': !uploadedImage, 'min-h-64': uploadedImage }">
                                <div v-if="!uploadedImage"
                                    class="w-full h-full flex items-center justify-center bg-gray-100 rounded-lg border border-gray-200">
                                    <div class="text-gray-400 text-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" class="w-16 h-16 mx-auto mb-2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                                        </svg>
                                        <p>Aucune image sélectionnée</p>
                                    </div>
                                </div>
                                <Cropper v-if="uploadedImage" class="object-cover rounded-lg" ref="cropper"
                                    :stencil-props="{ aspectRatio }" :src="uploadedImage" />
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-4 w-full mt-4">
                                <button @click="$emit('showModal', false)" type="button"
                                    class="flex-1 justify-center rounded-md py-2 text-gray-600 hover:text-gray-800 font-medium hover:shadow-sm hover:bg-gray-100 focus:outline-none focus:ring-0 border border-gray-300">
                                    Annuler
                                </button>
                                <button v-if="uploadedImage" @click="uploadLogo" type="button"
                                    class="flex-1 rounded-md bg-green-600 py-2 text-white font-medium shadow-sm hover:bg-green-700 focus:outline-none focus:ring-0"
                                    :disabled="uploading">
                                    <span v-if="uploading" class="flex items-center justify-center">
                                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        Uploading...
                                    </span>
                                    <span v-else>Appliquer</span>
                                </button>
                            </div>

                            <!-- Error Message -->
                            <div v-if="error" class="mt-3 w-full text-center text-red-500 text-sm">
                                {{ error }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
import { ref } from 'vue';
import { Cropper } from 'vue-advanced-cropper';
import { router } from '@inertiajs/vue3';
import 'vue-advanced-cropper/dist/style.css';

const props = defineProps({
    cityId: {
        type: [String, Number],
        required: true
    },
    modalTitle: {
        type: String,
        default: 'Modifier l\'image'
    },
    aspectRatio: {
        type: Number,
        default: 1
    }
});

const emit = defineEmits(['showModal', 'logo-updated']);

// Refs
const fileInput = ref(null);
const cropper = ref(null);
const uploadedImage = ref(null);
const uploading = ref(false);
const error = ref(null);

// Handle file selection
const getUploadedImage = (e) => {
    const file = e.target.files[0];
    if (!file) return;

    // Clear any previous errors
    error.value = null;

    // Validate file type
    if (!file.type.match('image.*')) {
        error.value = 'Veuillez sélectionner une image valide.';
        return;
    }

    // Validate file size (2MB max)
    if (file.size > 2 * 1024 * 1024) {
        error.value = 'L\'image ne doit pas dépasser 2 Mo.';
        return;
    }

    uploadedImage.value = URL.createObjectURL(file);
};

// Upload the cropped logo directly
const uploadLogo = () => {
    if (!cropper.value) return;

    uploading.value = true;
    error.value = null;

    const { canvas } = cropper.value.getResult();

    // Convert canvas to blob
    canvas.toBlob(async (blob) => {
        try {
            // Create a FormData object
            const formData = new FormData();

            // Create file from blob
            const file = new File([blob], 'logo.png', { type: 'image/png' });
            formData.append('logo', file);

            // Send to server using Inertia
            router.post(route('settings.city.update-logo', props.cityId), formData, {
                preserveScroll: true,
                onSuccess: (page) => {
                    // Make sure these values are defined before emitting
                    if (page.props.flash && page.props.flash.logo_path) {
                        // Emit success event with proper data
                        emit('logo-updated', {
                            logoPath: page.props.flash.logo_path
                        });
                        // Close modal
                        emit('showModal', false);
                    } else {
                        emit('showModal', false);
                        console.error('Logo path not found in response');
                        error.value = 'Could not retrieve logo path from server response';
                    }
                },
                onError: (errors) => {
                    // Display error message
                    error.value = errors.logo || 'Failed to upload logo';
                },
                onFinish: () => {
                    uploading.value = false;
                }
            });
        } catch (err) {
            error.value = 'Error uploading image: ' + err.message;
            uploading.value = false;
        }
    }, 'image/png');
};
</script>
