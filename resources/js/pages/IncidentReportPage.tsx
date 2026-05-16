import { FormEvent, useEffect, useState } from 'react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Incident = { id: number; title: string; severity: string; status: string };

export function IncidentReportPage() {
  const [rows, setRows] = useState<Incident[]>([]);
  const [form, setForm] = useState({ exam_session_id: '1', title: '', severity: 'medium', description: '' });

  async function load() {
    const { data } = await api.get('/incidents');
    setRows(data.data ?? []);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    await api.post('/incidents', { ...form, exam_session_id: Number(form.exam_session_id) });
    await load();
  }

  useEffect(() => { void load(); }, []);

  return (
    <div>
      <PageHeader title="Incidents" eyebrow="Proctor evidence" />
      <form className="toolbar" onSubmit={submit}>
        <input value={form.exam_session_id} onChange={(event) => setForm({ ...form, exam_session_id: event.target.value })} />
        <input placeholder="Title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
        <select value={form.severity} onChange={(event) => setForm({ ...form, severity: event.target.value })}><option>low</option><option>medium</option><option>high</option><option>critical</option></select>
        <input placeholder="Description" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
        <button className="primary">Report</button>
      </form>
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search incident..."
        initialSort={{ key: 'id' }}
        columns={[
          { key: 'id', header: 'ID', accessor: (row) => row.id },
          { key: 'title', header: 'Title', accessor: (row) => row.title },
          { key: 'severity', header: 'Severity', accessor: (row) => row.severity },
          { key: 'status', header: 'Status', accessor: (row) => row.status },
        ]}
      />
    </div>
  );
}
