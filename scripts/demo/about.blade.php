<x-demo::layout :title="'About'" :base="$base">
    <h1 id="title">About</h1>
    <p>This demo is built by <code>scripts/demo-build.php</code> from the package itself: the manifest, the service worker, the client and the Blade components are the real ones, rendered with the demo's configuration.</p>
    <div class="card">
        <h2>Installed app</h2>
        <p class="muted">In the installed app on a desktop, the Back and Reload buttons appear in the header (<code>&lt;x-pwa::nav/&gt;</code>), because the browser does not show its own in standalone mode.</p>
    </div>
</x-demo::layout>
