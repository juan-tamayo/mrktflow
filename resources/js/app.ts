import { createInertiaApp } from '@inertiajs/vue3'
import { createApp, h, type DefineComponent } from 'vue'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { captureInvitationToken } from './admin/invitation-token'

captureInvitationToken()

void createInertiaApp({
  resolve: (name) =>
    resolvePageComponent<DefineComponent>(
      `./admin/pages/${name}.vue`,
      import.meta.glob<DefineComponent>('./admin/pages/**/*.vue'),
    ),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
  progress: false,
})
