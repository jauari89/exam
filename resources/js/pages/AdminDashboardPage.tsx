import { FormEvent, ReactNode, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Activity, AlertTriangle, ClipboardCheck, Database, RefreshCw, ShieldCheck, Users } from 'lucide-react';
import { DataTable } from '../components/DataTable';
import { PageHeader, Stat } from '../components/Layout';
import { adminLogin, api } from '../lib/api';

type DashboardSummary = Record<string, number | null>;

type DashboardPayload = {
  server_time: string;
  summary: DashboardSummary;
  attempt_statuses: Record<string, number>;
  active_sessions: Array<{
    id: number;
    name: string;
    exam?: string;
    mode: string;
    status: string;
    starts_at?: string;
    ends_at?: string;
    duration_minutes: number;
    attempts_count: number;
    submitted_count: number;
    locked_count: number;
    incidents_count: number;
    last_event_at?: string;
    statuses: Record<string, number>;
  }>;
  recent_attempts: Array<{
    id: number;
    candidate?: string;
    candidate_number?: string;
    session?: string;
    exam?: string;
    status: string;
    last_seen_at?: string;
    submitted_at?: string;
    score_status?: string;
  }>;
  recent_events: Array<{ id: number; session?: string; candidate?: string; event_type: string; severity: string; occurred_at?: string }>;
  recent_incidents: Array<{ id: number; title: string; severity: string; status: string; session?: string; candidate?: string; created_at?: string }>;
  recent_audit_logs: Array<{ id: number; action: string; actor: string; occurred_at?: string }>;
};

const emptyDashboard: DashboardPayload = {
  server_time: '',
  summary: {},
  attempt_statuses: {},
  active_sessions: [],
  recent_attempts: [],
  recent_events: [],
  recent_incidents: [],
  recent_audit_logs: [],
};

function formatTime(value?: string) {
  if (!value) return '-';
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

function pct(value: number | null | undefined) {
  return typeof value === 'number' ? `${value}%` : '-';
}

function StatusChip({ value }: { value: string }) {
  return <span className={`status-chip ${value}`}>{value.replaceAll('_', ' ')}</span>;
}

function MiniStatus({ statuses }: { statuses: Record<string, number> }) {
  const entries = Object.entries(statuses);
  if (!entries.length) return <span className="muted">No attempts</span>;
  return (
    <div className="mini-status">
      {entries.map(([status, count]) => <span key={status}>{status.replaceAll('_', ' ')}: {count}</span>)}
    </div>
  );
}

const chartColors = ['#2563eb', '#0f9f6e', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2'];

function ChartPanel({ title, icon, children, meta }: { title: string; icon: ReactNode; children: ReactNode; meta?: string }) {
  return (
    <section className="dashboard-panel chart-panel">
      <div className="panel-title">{icon} {title}{meta ? <span>{meta}</span> : null}</div>
      {children}
    </section>
  );
}

function HorizontalBars({ rows }: { rows: Array<{ label: string; value: number; color?: string }> }) {
  const max = Math.max(1, ...rows.map((row) => row.value));

  return (
    <div className="bar-chart">
      {rows.map((row, index) => (
        <div className="bar-row" key={row.label}>
          <span>{row.label}</span>
          <div><i style={{ width: `${Math.max(4, (row.value / max) * 100)}%`, background: row.color ?? chartColors[index % chartColors.length] }} /></div>
          <strong>{row.value}</strong>
        </div>
      ))}
      {!rows.length ? <p className="muted">No chart data yet.</p> : null}
    </div>
  );
}

function DonutChart({ rows }: { rows: Array<{ label: string; value: number; color: string }> }) {
  const total = rows.reduce((sum, row) => sum + row.value, 0);
  let cursor = 0;
  const gradient = total
    ? rows.map((row) => {
      const start = cursor;
      cursor += (row.value / total) * 100;
      return `${row.color} ${start}% ${cursor}%`;
    }).join(', ')
    : '#e5e7eb 0% 100%';

  return (
    <div className="donut-wrap">
      <div className="donut-chart" style={{ background: `conic-gradient(${gradient})` }}>
        <span>{total}</span>
      </div>
      <div className="chart-legend">
        {rows.map((row) => (
          <span key={row.label}><i style={{ background: row.color }} /> {row.label} <strong>{row.value}</strong></span>
        ))}
      </div>
    </div>
  );
}

export function AdminDashboardPage() {
  const [email, setEmail] = useState('admin@example.test');
  const [password, setPassword] = useState('password');
  const [user, setUser] = useState<unknown>(null);
  const [dashboard, setDashboard] = useState<DashboardPayload>(emptyDashboard);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const summary = dashboard.summary;
  const health = useMemo(() => {
    const openIncidents = Number(summary.open_incidents ?? 0);
    const disconnected = Number(summary.disconnected_attempts ?? 0);
    const warnings = Number(summary.warning_events_24h ?? 0);
    if (openIncidents || disconnected || warnings) return 'Needs attention';
    return 'Normal';
  }, [summary]);
  const attemptChart = useMemo(() => Object.entries(dashboard.attempt_statuses).map(([label, value], index) => ({
    label: label.replaceAll('_', ' '),
    value,
    color: chartColors[index % chartColors.length],
  })), [dashboard.attempt_statuses]);
  const systemChart = useMemo(() => [
    { label: 'Series', value: Number(summary.series ?? 0) },
    { label: 'Exams', value: Number(summary.exams ?? 0) },
    { label: 'Sessions', value: Number(summary.sessions ?? 0) },
    { label: 'Candidates', value: Number(summary.candidates ?? 0) },
    { label: 'Question items', value: Number(summary.question_bank_items ?? 0) },
    { label: 'Packages', value: Number(summary.packages ?? 0) },
  ].filter((row) => row.value > 0), [summary]);
  const riskChart = useMemo(() => [
    { label: 'Disconnected', value: Number(summary.disconnected_attempts ?? 0), color: '#f59e0b' },
    { label: 'Open incidents', value: Number(summary.open_incidents ?? 0), color: '#dc2626' },
    { label: 'Warnings 24h', value: Number(summary.warning_events_24h ?? 0), color: '#7c3aed' },
    { label: 'Submitted', value: Number(summary.submitted_attempts ?? 0), color: '#0f9f6e' },
  ], [summary]);
  const sessionChart = useMemo(() => dashboard.active_sessions.slice(0, 6).map((session) => ({
    label: `#${session.id} ${session.name}`,
    value: session.submitted_count,
  })), [dashboard.active_sessions]);

  async function loadDashboard() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/admin/dashboard');
      setDashboard(data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Dashboard gagal dimuat.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    api.get('/auth/me')
      .then(({ data }) => {
        setUser(data.user);
        void loadDashboard();
      })
      .catch(() => null);
  }, []);

  useEffect(() => {
    if (!user) return undefined;
    const timer = window.setInterval(() => void loadDashboard(), 15000);
    return () => window.clearInterval(timer);
  }, [user]);

  async function login(event: FormEvent) {
    event.preventDefault();
    const loggedIn = await adminLogin(email, password);
    setUser(loggedIn);
    await loadDashboard();
  }

  return (
    <div>
      <div className="dashboard-head">
        <PageHeader title="Operations Dashboard" eyebrow="Live monitoring" />
        {user ? (
          <button className="secondary" onClick={() => void loadDashboard()} disabled={loading}>
            <RefreshCw size={18} /> Refresh
          </button>
        ) : null}
      </div>

      {!user ? (
        <form className="toolbar" onSubmit={login}>
          <input value={email} onChange={(event) => setEmail(event.target.value)} />
          <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} />
          <button className="primary">Login</button>
        </form>
      ) : null}

      {error ? <p className="error">{error}</p> : null}

      <div className="dashboard-analytics">
        <ChartPanel title="Attempt status" icon={<ClipboardCheck size={18} />} meta={`${Number(summary.in_progress_attempts ?? 0)} in progress`}>
          <DonutChart rows={attemptChart} />
        </ChartPanel>
        <ChartPanel title="System totals" icon={<Database size={18} />} meta={`${Number(summary.candidates ?? 0)} candidates`}>
          <HorizontalBars rows={systemChart} />
        </ChartPanel>
        <ChartPanel title="Risk monitor" icon={<AlertTriangle size={18} />} meta={health}>
          <HorizontalBars rows={riskChart} />
        </ChartPanel>
        <ChartPanel title="Submitted per session" icon={<Activity size={18} />} meta="Top active">
          <HorizontalBars rows={sessionChart} />
        </ChartPanel>
      </div>

      <div className="stat-grid">
        <Stat label="Active sessions" value={summary.active_sessions ?? 0} />
        <Stat label="In progress" value={summary.in_progress_attempts ?? 0} />
        <Stat label="Submitted" value={summary.submitted_attempts ?? 0} />
        <Stat label="Disconnected" value={summary.disconnected_attempts ?? 0} />
        <Stat label="Pending manual" value={summary.pending_manual_answers ?? 0} />
        <Stat label="Open incidents" value={summary.open_incidents ?? 0} />
        <Stat label="Question items" value={summary.question_bank_items ?? 0} />
        <Stat label="Average score" value={pct(summary.average_score_percent)} />
      </div>

      <div className="dashboard-grid">
        <section className="dashboard-panel span-2">
          <div className="panel-title"><Activity size={18} /> Live sessions <span>{health}</span></div>
          <DataTable
            rows={dashboard.active_sessions}
            rowKey={(session) => session.id}
            searchPlaceholder="Search session..."
            columns={[
              {
                key: 'session',
                header: 'Session',
                accessor: (session) => `${session.id} ${session.name} ${session.mode}`,
                render: (session) => <><strong>{session.name}</strong><br /><span className="muted">#{session.id} / {session.mode}</span></>,
              },
              { key: 'exam', header: 'Exam', accessor: (session) => session.exam ?? '-' },
              {
                key: 'window',
                header: 'Window',
                accessor: (session) => `${session.starts_at} ${session.ends_at}`,
                render: (session) => <>{formatTime(session.starts_at)}<br />{formatTime(session.ends_at)}</>,
              },
              {
                key: 'attempts',
                header: 'Attempts',
                accessor: (session) => session.attempts_count,
                render: (session) => <>{session.attempts_count} total<br />{session.submitted_count} submitted</>,
              },
              {
                key: 'state',
                header: 'State',
                accessor: (session) => Object.entries(session.statuses).map(([key, value]) => `${key} ${value}`).join(' '),
                render: (session) => <MiniStatus statuses={session.statuses} />,
              },
              {
                key: 'action',
                header: 'Action',
                sortable: false,
                searchable: false,
                render: (session) => <Link className="secondary compact-button" to={`/proctor/session?session=${session.id}`}>Proctor</Link>,
              },
            ]}
          />
        </section>

        <section className="dashboard-panel">
          <div className="panel-title"><Database size={18} /> System totals</div>
          <div className="metric-list">
            <span>Series <strong>{summary.series ?? 0}</strong></span>
            <span>Exams <strong>{summary.exams ?? 0}</strong></span>
            <span>Sessions <strong>{summary.sessions ?? 0}</strong></span>
            <span>Candidates <strong>{summary.candidates ?? 0}</strong></span>
            <span>Banks <strong>{summary.question_banks ?? 0}</strong></span>
            <span>Packages <strong>{summary.packages ?? 0}</strong></span>
          </div>
        </section>

        <section className="dashboard-panel">
          <div className="panel-title"><ClipboardCheck size={18} /> Attempt status</div>
          <div className="metric-list">
            {Object.entries(dashboard.attempt_statuses).map(([status, count]) => (
              <span key={status}><StatusChip value={status} /> <strong>{count}</strong></span>
            ))}
          </div>
        </section>

        <section className="dashboard-panel span-2">
          <div className="panel-title"><Users size={18} /> Recent attempts <span>Auto-refresh 15s</span></div>
          <DataTable
            rows={dashboard.recent_attempts}
            rowKey={(attempt) => attempt.id}
            searchPlaceholder="Search attempt..."
            columns={[
              { key: 'candidate', header: 'Candidate', accessor: (attempt) => `${attempt.candidate_number ?? ''} ${attempt.candidate ?? ''}`, render: (attempt) => <>{attempt.candidate_number} / {attempt.candidate}</> },
              { key: 'session', header: 'Session', accessor: (attempt) => `${attempt.session ?? ''} ${attempt.exam ?? ''}`, render: (attempt) => <>{attempt.session}<br /><span className="muted">{attempt.exam}</span></> },
              { key: 'status', header: 'Status', accessor: (attempt) => attempt.status, render: (attempt) => <StatusChip value={attempt.status} /> },
              { key: 'last_seen', header: 'Last seen', accessor: (attempt) => attempt.last_seen_at ?? '', render: (attempt) => formatTime(attempt.last_seen_at) },
              { key: 'submitted', header: 'Submitted', accessor: (attempt) => attempt.submitted_at ?? '', render: (attempt) => formatTime(attempt.submitted_at) },
            ]}
          />
        </section>

        <section className="dashboard-panel">
          <div className="panel-title"><AlertTriangle size={18} /> Incidents</div>
          <div className="feed-list">
            {dashboard.recent_incidents.map((incident) => (
              <div key={incident.id}>
                <strong>{incident.title}</strong>
                <span>{incident.severity} / {incident.status}</span>
                <small>{incident.session ?? '-'} / {formatTime(incident.created_at)}</small>
              </div>
            ))}
            {!dashboard.recent_incidents.length ? <p className="muted">No incident reports.</p> : null}
          </div>
        </section>

        <section className="dashboard-panel">
          <div className="panel-title"><ShieldCheck size={18} /> Proctor events</div>
          <div className="feed-list">
            {dashboard.recent_events.map((event) => (
              <div key={event.id}>
                <strong>{event.event_type}</strong>
                <span>{event.severity} / {event.candidate ?? event.session ?? '-'}</span>
                <small>{formatTime(event.occurred_at)}</small>
              </div>
            ))}
            {!dashboard.recent_events.length ? <p className="muted">No proctor events yet.</p> : null}
          </div>
        </section>

        <section className="dashboard-panel">
          <div className="panel-title"><ShieldCheck size={18} /> Audit trail</div>
          <div className="feed-list">
            {dashboard.recent_audit_logs.map((log) => (
              <div key={log.id}>
                <strong>{log.action}</strong>
                <span>{log.actor}</span>
                <small>{formatTime(log.occurred_at)}</small>
              </div>
            ))}
          </div>
        </section>
      </div>

      <p className="muted">Server time: {formatTime(dashboard.server_time)} {loading ? '/ loading...' : ''}</p>
    </div>
  );
}
