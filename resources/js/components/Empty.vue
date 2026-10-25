<template>
    <!-- Centred empty-state text that fills its box; the inner <p> keeps inline children (e.g. <code>) in the sentence. -->
    <div class="flex h-full items-center justify-center text-xs text-gray-500">
        <p class="text-center"><slot /></p>
    </div>
</template>
