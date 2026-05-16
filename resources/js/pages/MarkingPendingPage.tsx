import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardCheck, UserCheck } from 'lucide-react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Pending = { id: number; question_external_id: string; max_marks: string; submission?: { attempt?: { candidate?: { name: string } } } };
type Assignment = {
  id: number;
  status: string;
  due_at?: string;
  session?: { name: string; exam?: { title: string } };
  marker?: { name: string };
  reviewer?: { name: string };
};

export function MarkingPendingPage() {
  const [rows, setRows] = useState<Pending[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [form, setForm] = useState({ exam_session_id: '', marker_id: '', reviewer_id: '', due_at: '' });
  const [message, setMessage] = useState('');
  const [activeMenu, setActiveMenu] = useState<'pending' | 'assignments'>('pending');

  async function load() {
    const pending = await api.get('/marking/pending');
    setRows(pending.data.data ?? []);
    try {
      const assignmentRows = await api.get('/marking/assignments');
      setAssignments(assignmentRows.data.data ?? []);
    } catch {
      setAssignments([]);
    }
  }

  async function assign(event: FormEvent) {
    event.preventDefault();
    await api.post('/marking/assignments', {
      exam_session_id: Number(form.exam_session_id),
      marker_id: Number(form.marker_id),
      reviewer_id: form.reviewer_id ? Number(form.reviewer_id) : undefined,
      due_at: form.due_at || undefined,
    });
    setMessage('Marker assignment saved.');
    await load();
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <div>
      <PageHeader title="Pending Marking" eyebrow="Manual marks" />
      {message ? <p className="success">{message}</p> : null}
      <div className="menu-card-grid">
        <button className={`menu-card ${activeMenu === 'pending' ? 'active' : ''}`} onClick={() => setActiveMenu('pending')}>
          <ClipboardCheck size={22} />
          <span>Pending answers</span>
          <strong>{rows.length}</strong>
          <small>Jawaban esai/structured yang perlu dikoreksi</small>
        </button>
        <button className={`menu-card ${activeMenu === 'assignments' ? 'active' : ''}`} onClick={() => setActiveMenu('assignments')}>
          <UserCheck size={22} />
          <span>Assignments</span>
          <strong>{assignments.length}</strong>
          <small>Penugasan marker dan reviewer</small>
        </button>
      </div>

      {activeMenu === 'pending' ? (
        <section className="content-panel">
          <h2>Pending answers</h2>
          <DataTable
            rows={rows}
            rowKey={(row) => row.id}
            searchPlaceholder="Search pending answer..."
            columns={[
              { key: 'candidate', header: 'Candidate', accessor: (row) => row.submission?.attempt?.candidate?.name ?? '-' },
              { key: 'question', header: 'Question', accessor: (row) => row.question_external_id },
              { key: 'max', header: 'Max', accessor: (row) => row.max_marks },
              { key: 'action', header: '', sortable: false, searchable: false, render: (row) => <Link to={`/marking/answers/${row.id}`}>Open</Link> },
            ]}
          />
        </section>
      ) : (
        <section className="content-panel">
          <h2>Assign marker</h2>
          <form className="toolbar" onSubmit={assign}>
            <input placeholder="Session ID" value={form.exam_session_id} onChange={(event) => setForm({ ...form, exam_session_id: event.target.value })} />
            <input placeholder="Marker user ID" value={form.marker_id} onChange={(event) => setForm({ ...form, marker_id: event.target.value })} />
            <input placeholder="Reviewer user ID" value={form.reviewer_id} onChange={(event) => setForm({ ...form, reviewer_id: event.target.value })} />
            <input type="datetime-local" value={form.due_at} onChange={(event) => setForm({ ...form, due_at: event.target.value })} />
            <button className="primary">Assign</button>
          </form>
          <h2>Assignments</h2>
          <DataTable
            rows={assignments}
            rowKey={(row) => row.id}
            searchPlaceholder="Search assignment..."
            columns={[
              {
                key: 'session',
                header: 'Session',
                accessor: (row) => `${row.session?.name ?? ''} ${row.session?.exam?.title ?? ''}`,
                render: (row) => <>{row.session?.name}<br /><span className="muted">{row.session?.exam?.title}</span></>,
              },
              { key: 'marker', header: 'Marker', accessor: (row) => row.marker?.name ?? '-' },
              { key: 'reviewer', header: 'Reviewer', accessor: (row) => row.reviewer?.name ?? '-' },
              { key: 'status', header: 'Status', accessor: (row) => row.status },
            ]}
          />
        </section>
      )}
    </div>
  );
}
