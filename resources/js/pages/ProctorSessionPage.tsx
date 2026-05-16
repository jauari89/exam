import { FormEvent, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';
import { echo } from '../lib/echo';

type AttemptRow = {
  id: number;
  candidate: { name: string; candidate_number: string };
  status: string;
  last_heartbeat?: string;
  last_autosave_at?: string;
  suspicious_events: number;
};

export function ProctorSessionPage() {
  const [params] = useSearchParams();
  const [sessionId, setSessionId] = useState(params.get('session') ?? '1');
  const [attempts, setAttempts] = useState<AttemptRow[]>([]);

  async function load(id = sessionId) {
    const { data } = await api.get(`/proctor/sessions/${id}`);
    setAttempts(data.attempts ?? []);
  }

  async function action(attemptId: number, endpoint: 'lock' | 'unlock' | 'resume-token') {
    const { data } = await api.post(`/proctor/attempts/${attemptId}/${endpoint}`, endpoint === 'lock' ? { reason: 'Proctor action' } : {});
    if (data.resume_token) window.alert(`Resume token: ${data.resume_token}`);
    await load();
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    void load();
  }

  useEffect(() => {
    void load(sessionId);
  }, []);

  useEffect(() => {
    if (!sessionId) return undefined;
    const channel = echo.private(`exam-session.${sessionId}`).listen('.proctor.event', () => void load());
    return () => { channel.stopListening('.proctor.event'); };
  }, [sessionId]);

  return (
    <div>
      <PageHeader title="Proctor Session" eyebrow="Live dashboard" />
      <form className="toolbar" onSubmit={submit}>
        <input value={sessionId} onChange={(event) => setSessionId(event.target.value)} />
        <button className="primary">Open</button>
      </form>
      <DataTable
        rows={attempts}
        rowKey={(row) => row.id}
        searchPlaceholder="Search candidate status..."
        columns={[
          { key: 'candidate', header: 'Candidate', accessor: (row) => `${row.candidate.candidate_number} ${row.candidate.name}`, render: (row) => <>{row.candidate.candidate_number} / {row.candidate.name}</> },
          { key: 'status', header: 'Status', accessor: (row) => row.status },
          { key: 'heartbeat', header: 'Heartbeat', accessor: (row) => row.last_heartbeat ?? '-' },
          { key: 'autosave', header: 'Autosave', accessor: (row) => row.last_autosave_at ?? '-' },
          { key: 'events', header: 'Events', accessor: (row) => row.suspicious_events },
          {
            key: 'actions',
            header: 'Actions',
            sortable: false,
            searchable: false,
            render: (row) => (
              <>
                <button onClick={() => void action(row.id, 'lock')}>Lock</button>
                <button onClick={() => void action(row.id, 'unlock')}>Unlock</button>
                <button onClick={() => void action(row.id, 'resume-token')}>Resume</button>
              </>
            ),
          },
        ]}
      />
    </div>
  );
}
