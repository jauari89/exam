import { FormEvent, useEffect, useState } from 'react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Exam = {
  id: number;
  code: string;
  title: string;
  mode: string;
  default_duration_minutes: number;
  papers?: Array<{ id: number; code: string; title: string; version: number; status: string }>;
  sessions?: Array<{ id: number; name: string; status: string }>;
};

export function ExamsPage() {
  const [rows, setRows] = useState<Exam[]>([]);
  const [form, setForm] = useState({
    exam_series_id: '',
    code: '',
    title: '',
    mode: 'strict',
    type: 'mixed',
    default_duration_minutes: '90',
    paper_code: 'PAPER-1',
    paper_title: '',
  });
  const [message, setMessage] = useState('');

  async function load() {
    const { data } = await api.get('/admin/exams');
    setRows(data.data ?? []);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const { data } = await api.post('/admin/exams', {
      exam_series_id: Number(form.exam_series_id),
      code: form.code,
      title: form.title,
      mode: form.mode,
      type: form.type,
      default_duration_minutes: Number(form.default_duration_minutes),
      paper: form.paper_code ? {
        code: form.paper_code,
        title: form.paper_title || `${form.title} Paper`,
        duration_minutes: Number(form.default_duration_minutes),
      } : undefined,
    });
    setMessage(`Exam #${data.id} created${data.papers?.[0]?.id ? ` with paper #${data.papers[0].id}` : ''}.`);
    setForm({ exam_series_id: '', code: '', title: '', mode: 'strict', type: 'mixed', default_duration_minutes: '90', paper_code: 'PAPER-1', paper_title: '' });
    await load();
  }

  useEffect(() => { void load(); }, []);

  return (
    <div>
      <PageHeader title="Exams" eyebrow="Paper setup" />
      {message ? <p className="success">{message}</p> : null}
      <form className="toolbar" onSubmit={submit}>
        <input placeholder="Series ID" value={form.exam_series_id} onChange={(event) => setForm({ ...form, exam_series_id: event.target.value })} />
        <input placeholder="Exam code" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
        <input placeholder="Exam title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
        <input placeholder="Duration" value={form.default_duration_minutes} onChange={(event) => setForm({ ...form, default_duration_minutes: event.target.value })} />
        <input placeholder="Paper code" value={form.paper_code} onChange={(event) => setForm({ ...form, paper_code: event.target.value })} />
        <input placeholder="Paper title" value={form.paper_title} onChange={(event) => setForm({ ...form, paper_title: event.target.value })} />
        <select value={form.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })}><option>strict</option><option>tryout</option></select>
        <button className="primary">Create exam + paper</button>
      </form>
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search exam..."
        initialSort={{ key: 'id' }}
        columns={[
          { key: 'id', header: 'ID', accessor: (row) => row.id },
          { key: 'exam', header: 'Exam', accessor: (row) => `${row.code} ${row.title}`, render: (row) => <><strong>{row.code}</strong><br />{row.title}</> },
          { key: 'mode', header: 'Mode', accessor: (row) => `${row.mode} ${row.default_duration_minutes}`, render: (row) => <>{row.mode}<br /><span className="muted">{row.default_duration_minutes}m</span></> },
          {
            key: 'papers',
            header: 'Papers',
            accessor: (row) => row.papers?.map((paper) => `${paper.code} ${paper.id} v${paper.version}`).join(' ') ?? '',
            render: (row) => row.papers?.map((paper) => <div key={paper.id}>{paper.code} #{paper.id} <span className="muted">v{paper.version}</span></div>),
          },
          {
            key: 'sessions',
            header: 'Sessions',
            accessor: (row) => row.sessions?.map((session) => `${session.name} ${session.id} ${session.status}`).join(' ') ?? '',
            render: (row) => row.sessions?.map((session) => <div key={session.id}>{session.name} #{session.id} <span className="muted">{session.status}</span></div>),
          },
        ]}
      />
    </div>
  );
}
