<!-- components/PhoneInput.vue -->
<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  required: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

const error = ref('')
const localValue = ref(props.modelValue)

const validateAndFormat = (value) => {
  // Remove any non-digit characters
  const cleaned = value.replace(/\D/g, '')
  
  // Format in pairs
  const formatted = cleaned.replace(/(\d{2})(?=\d)/g, '$1 ').trim()
  
  // Validate
  if (props.required && !cleaned) {
    error.value = 'Le numéro est requis'
  } else if (cleaned && cleaned.length !== 10) {
    error.value = 'Le numéro doit contenir 10 chiffres'
  } else if (/^0{10}$/.test(cleaned)) {
    error.value = 'Numéro de téléphone invalide'
  } else {
    error.value = ''
  }

  // Update the formatted value
  localValue.value = formatted.slice(0, 14)
  emit('update:modelValue', cleaned) // Emit cleaned value without spaces
}

watch(() => props.modelValue, (newValue) => {
  if (newValue !== localValue.value.replace(/\s/g, '')) {
    validateAndFormat(newValue)
  }
})

const handleInput = (event) => {
  validateAndFormat(event.target.value)
}
</script>

<template>
  <div>
    <input
      :value="localValue"
      @input="handleInput"
      type="tel"
      class="w-full px-3 py-1.5 border border-orange-200 rounded-lg focus:border-green-500 focus:ring-green-500 text-sm bg-white text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100 dark:focus:border-emerald-400 dark:placeholder:text-slate-500"
      :class="{ 'border-red-500 dark:border-red-500/70': error }"
      placeholder="XX XX XX XX XX"
      
    />
  </div>
</template>