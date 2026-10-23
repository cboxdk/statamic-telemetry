import Telemetry from './pages/Telemetry.vue';
import Trace from './pages/Trace.vue';
import ContentAudit from './pages/ContentAudit.vue';
import Doctor from './pages/Doctor.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-telemetry::Telemetry', Telemetry);
    Statamic.$inertia.register('statamic-telemetry::Trace', Trace);
    Statamic.$inertia.register('statamic-telemetry::ContentAudit', ContentAudit);
    Statamic.$inertia.register('statamic-telemetry::Doctor', Doctor);
});
