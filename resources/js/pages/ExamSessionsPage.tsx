import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type SessionRow = {
  id: number;
  name: string;
  mode: string;
  status: string;
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  attempts_count?: number;
  settings?: { published_package_id?: number; published_package_version?: number };
  exam?: { id: number; code: string; title: string };
  paper?: { id: number; code: string; title: string };
};

const nowLocal = () => new Date(Date.now() + 60 * 60 * 1000).toISOString().slice(0, 16);
const laterLocal = () => new Date(Date.now() + 3 * 60 * 60 * 1000).toISOString().slice(0, 16);

export function ExamSessionsPage() {
  const [rows, setRows] = useState<SessionRow[]>([]);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState({
    exam_id: '',
    exam_paper_id: '',
    name: '',
    starts_at: nowLocal(),
    ends_at: laterLocal(),
    duration_minutes: '90',
    mode: 'strict',
    status: 'scheduled',
    timezone: 'Asia/Jakarta',
  });
  const [message, setMessage] = useState('');

  async function load() {
    const { data } = await api.get('/admin/exam-sessions');
    setRows(data.data ?? []);
  }

  function resetForm() {
    setEditingId(null);
    setForm({
      exam_id: '',
      exam_paper_id: '',
      name: '',
      starts_at: nowLocal(),
      ends_at: laterLocal(),
      duration_minutes: '90',
      mode: 'strict',
      status: 'scheduled',
      timezone: 'Asia/Jakarta',
    });
  }

  function edit(row: SessionRow) {
    setEditingId(row.id);
    setForm({
      exam_id: String(row.exam?.id ?? ''),
      exam_paper_id: String(row.paper?.id ?? ''),
      name: row.name,
      starts_at: row.starts_at?.slice(0, 16) ?? nowLocal(),
      ends_at: row.ends_at?.slice(0, 16) ?? laterLocal(),
      duration_minutes: String(row.duration_minutes),
      mode: row.mode,
      status: row.status,
      timezone: 'Asia/Jakarta',
    });
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const payload = {
      exam_id: Number(form.exam_id),
      exam_paper_id: form.exam_paper_id ? Number(form.exam_paper_id) : null,
      name: form.name,
      starts_at: form.starts_at,
      ends_at: form.ends_at,
      duration_minutes: Number(form.duration_minutes),
      mode: form.mode,
      status: form.status,
      timezone: form.timezone,
    };

    if (editingId) {
      await api.put(`/admin/exam-sessions/${editingId}`, payload);
      setMessage(`Session #${editingId} updated.`);
    } else {
      const { data } = await api.post('/admin/exam-sessions', payload);
      setMessage(`Session #${data.id} created.`);
    }

    resetForm();
    await load();
  }

  async function remove(row: SessionRow) {
    if (!window.confirm(`Delete session ${row.name}?`)) return;
    await api.delete(`/admin/exam-sessions/${row.id}`);
    setMessage(`Session #${row.id} deleted.`);
    await load();
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <div>
      <PageHeader title="Exam Sessions" eyebrow="Schedule and publish target" />
      {message ? <p className="success">{message}</p> : null}
      <form className="toolbar" onSubmit={submit}>
        <input placeholder="Exam ID" value={form.exam_id} onChange={(event) => setForm({ ...form, exam_id: event.target.value })} />
        <input placeholder="Paper ID" value={form.exam_paper_id} onChange={(event) => setForm({ ...form, exam_paper_id: event.target.value })} />
        <input placeholder="Session name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
        <input type="datetime-local" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} />
        <input type="datetime-local" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} />
        <input placeholder="Duration" value={form.duration_minutes} onChange={(event) => setForm({ ...form, duration_minutes: event.target.value })} />
        <select value={form.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })}><option>strict</option><option>tryout</option></select>
        <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}><option>scheduled</option><option>active</option><option>closed</option><option>cancelled</option></select>
        <button className="primary">{editingId ? 'Update' : 'Create'}</button>
        {editingId ? <button type="button" className="secondary" onClick={resetForm}>Cancel</button> : null}
      </form>
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search session..."
        initialSort={{ key: 'id' }}
        columns={[
          { key: 'id', header: 'ID', accessor: (row) => row.id },
          {
            key: 'session',
            header: 'Session',
            accessor: (row) => `${row.name} ${row.mode} ${row.duration_minutes}`,
            render: (row) => <><strong>{row.name}</strong><br /><span className="muted">{row.mode} / {row.duration_minutes}m</span></>,
          },
          {
            key: 'exam',
            header: 'Exam / Paper',
            accessor: (row) => `${row.exam?.code ?? ''} ${row.exam?.id ?? ''} ${row.paper?.code ?? ''} ${row.paper?.id ?? ''}`,
            render: (row) => <>{row.exam?.code} #{row.exam?.id}<br /><span className="muted">{row.paper?.code ?? '-'} #{row.paper?.id ?? '-'}</span></>,
          },
          {
            key: 'window',
            header: 'Window',
            accessor: (row) => `${row.starts_at} ${row.ends_at}`,
            render: (row) => <>{row.starts_at?.slice(0, 16)}<br />{row.ends_at?.slice(0, 16)}</>,
          },
          { key: 'status', header: 'Status', accessor: (row) => row.status },
          {
            key: 'package',
            header: 'Package',
            accessor: (row) => row.settings?.published_package_id ?? 0,
            render: (row) => row.settings?.published_package_id ? `#${row.settings.published_package_id} v${row.settings.published_package_version ?? '-'}` : '-',
          },
          { key: 'attempts', header: 'Attempts', accessor: (row) => row.attempts_count ?? 0 },
          {
            key: 'actions',
            header: 'Actions',
            sortable: false,
            searchable: false,
            render: (row) => (
              <>
                <button onClick={() => edit(row)}>Edit</button>
                <Link className="secondary compact-button" to={`/proctor/session?session=${row.id}`}>Proctor</Link>
                {(row.attempts_count ?? 0) === 0 ? <button onClick={() => void remove(row)}>Delete</button> : null}
              </>
            ),
          },
        ]}
      />
    </div>
  );
}
