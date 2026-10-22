import Telemetry from './pages/Telemetry.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-telemetry::Telemetry', Telemetry);
});
