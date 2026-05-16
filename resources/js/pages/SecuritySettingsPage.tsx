import { PageHeader } from '../components/Layout';

export function SecuritySettingsPage() {
  return (
    <div>
      <PageHeader title="Security Settings" eyebrow="Deployment posture" />
      <div className="settings-grid">
        <section><strong>Authentication</strong><span>Sanctum bearer and SPA cookies, admin login throttling, active-user status checks.</span></section>
        <section><strong>Candidate access</strong><span>One-time hashed tokens, HMAC lookup, server-side exam window, rotated resume tokens.</span></section>
        <section><strong>Timing</strong><span>Attempt start and expiry are stored server-side; browser timers are display-only.</span></section>
        <section><strong>Integrity</strong><span>Attempt package snapshots reject unknown questions/options and clamp all awarded marks.</span></section>
        <section><strong>Infrastructure</strong><span>Redis-ready cache/session/queue/rate limiting, Reverb broadcasting, Nginx/PHP-FPM compatible public entrypoint.</span></section>
      </div>
    </div>
  );
}
