<template>
    <div class="flex h-full items-center justify-center text-xs text-gray-500">
        <!-- Centred empty-state text that fills its box; the inner <p> keeps inline children (e.g. <code>) in the sentence. -->
        <p class="text-center"><slot /></p>
    </div>
</template>
