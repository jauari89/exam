import { FormEvent, useEffect, useState } from 'react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Series = { id: number; code: string; title: string; status: string };

export function ExamSeriesPage() {
  const [rows, setRows] = useState<Series[]>([]);
  const [form, setForm] = useState({ code: '', title: '', status: 'active' });

  async function load() {
    const { data } = await api.get('/admin/exam-series');
    setRows(data.data ?? []);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    await api.post('/admin/exam-series', form);
    setForm({ code: '', title: '', status: 'active' });
    await load();
  }

  useEffect(() => { void load(); }, []);

  return (
    <div>
      <PageHeader title="Exam Series" eyebrow="Series management" />
      <form className="toolbar" onSubmit={submit}>
        <input placeholder="Code" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
        <input placeholder="Title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
        <button className="primary">Create</button>
      </form>
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search series..."
        initialSort={{ key: 'code' }}
        columns={[
          { key: 'code', header: 'Code', accessor: (row) => row.code },
          { key: 'title', header: 'Title', accessor: (row) => row.title },
          { key: 'status', header: 'Status', accessor: (row) => row.status },
        ]}
      />
    </div>
  );
}
