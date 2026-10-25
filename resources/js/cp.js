import '../css/cp.css';
import Telemetry from './pages/Telemetry.vue';
import Explore from './pages/Explore.vue';
import AreaPage from './pages/AreaPage.vue';
import Doctor from './pages/Doctor.vue';
import TelemetryFieldtype from './fieldtypes/Telemetry.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-telemetry::Telemetry', Telemetry);
    Statamic.$inertia.register('statamic-telemetry::Explore', Explore);
    Statamic.$inertia.register('statamic-telemetry::AreaPage', AreaPage);
    Statamic.$inertia.register('statamic-telemetry::Doctor', Doctor);
    Statamic.$components.register('telemetry-fieldtype', TelemetryFieldtype);
});
