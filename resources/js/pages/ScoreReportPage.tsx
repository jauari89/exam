import { FormEvent, useEffect, useMemo, useState } from 'react';
import { BarChart3, ChevronDown, Download, FileArchive, FileText, ListChecks, Search, UserRound } from 'lucide-react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type SessionRow = {
  id: number;
  name: string;
  status: string;
  starts_at?: string;
  attempts_count?: number;
  exam?: { code: string; title: string };
};

type ScoreRow = {
  id: number;
  total_score: string | number;
  max_score: string | number;
  status: string;
  submission?: {
    attempt?: {
      status?: string;
      candidate?: { id: number; name: string; candidate_number: string };
    };
  };
};

type ItemAnalysisRow = {
  question_external_id: string;
  answer_type?: string;
  type?: string;
  topic?: string | null;
  average_score: string | number;
  max_marks: string | number;
  attempted?: number;
  responses?: number;
  facility_index?: number | null;
  manual_pending?: number;
};

type TopicRow = {
  topic?: string | null;
  average_score: string | number;
  max_marks: string | number;
};

type StudentAnalysis = {
  candidate: { id: number; name: string; candidate_number: string };
  attempts: Array<{ id: number; status: string; autosave_count: number; last_autosave_at?: string; score?: ScoreRow }>;
  topic_progress: Array<{ topic: string; earned_marks: number; max_marks: number; answered: number; total: number }>;
  question_breakdown: Array<{ question_external_id: string; type: string; topic?: string | null; earned_marks: number; max_marks: number; requires_manual_marking: boolean }>;
};

type ReportPayload = {
  score_report: ScoreRow[];
  submission_status: Record<string, number>;
  incidents: Record<string, number>;
  item_analysis: ItemAnalysisRow[];
  topic_progress: TopicRow[];
};

const emptyReport: ReportPayload = {
  score_report: [],
  submission_status: {},
  incidents: {},
  item_analysis: [],
  topic_progress: [],
};

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

function n(value: string | number | null | undefined) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function pct(earned: string | number, max: string | number) {
  const denominator = n(max);
  return denominator > 0 ? Math.round((n(earned) / denominator) * 100) : 0;
}

function formatDate(value?: string) {
  if (!value) return '-';
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function sessionLabel(session: SessionRow) {
  return `${session.name} #${session.id} / ${session.exam?.code ?? '-'}`;
}

export function ScoreReportPage() {
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [sessionId, setSessionId] = useState('');
  const [sessionSearch, setSessionSearch] = useState('');
  const [sessionDropdownOpen, setSessionDropdownOpen] = useState(false);
  const [report, setReport] = useState<ReportPayload>(emptyReport);
  const [activeTab, setActiveTab] = useState<'scores' | 'items' | 'topics' | 'student'>('scores');
  const [student, setStudent] = useState<StudentAnalysis | null>(null);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);

  const selectedSession = sessions.find((session) => String(session.id) === sessionId);
  const filteredSessions = useMemo(() => {
    const query = sessionSearch.trim().toLowerCase();
    if (!query) return sessions.slice(0, 10);

    return sessions
      .filter((session) => `${session.name} ${session.id} ${session.exam?.code ?? ''} ${session.exam?.title ?? ''} ${session.status}`.toLowerCase().includes(query))
      .slice(0, 10);
  }, [sessionSearch, sessions]);
  const rows = report.score_report ?? [];
  const summary = useMemo(() => {
    const percentages = rows.map((row) => pct(row.total_score, row.max_score));
    const average = percentages.length ? Math.round(percentages.reduce((sum, value) => sum + value, 0) / percentages.length) : 0;
    const highest = percentages.length ? Math.max(...percentages) : 0;
    const lowest = percentages.length ? Math.min(...percentages) : 0;
    const pendingManual = (report.item_analysis ?? []).reduce((sum, item) => sum + Number(item.manual_pending ?? 0), 0);
    const incidents = Object.values(report.incidents ?? {}).reduce((sum, value) => sum + Number(value), 0);

    return { average, highest, lowest, pendingManual, incidents };
  }, [report.incidents, report.item_analysis, rows]);

  async function loadSessions() {
    const { data } = await api.get('/admin/exam-sessions');
    const sessionRows = data.data ?? [];
    setSessions(sessionRows);
  }

  async function loadReport(id = sessionId) {
    if (!id) return;
    setLoading(true);
    setMessage('');
    setStudent(null);
    try {
      const { data } = await api.get(`/reports/sessions/${id}`);
      setReport({
        score_report: data.score_report ?? [],
        submission_status: data.submission_status ?? {},
        incidents: data.incidents ?? {},
        item_analysis: data.item_analysis ?? [],
        topic_progress: data.topic_progress ?? [],
      });
    } finally {
      setLoading(false);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const selected = selectedSession ?? filteredSessions[0];
    if (!selected) return;
    await chooseSession(selected);
  }

  async function chooseSession(session: SessionRow) {
    setSessionId(String(session.id));
    setSessionSearch(sessionLabel(session));
    setSessionDropdownOpen(false);
    await loadReport(String(session.id));
  }

  function openSessionDropdown() {
    setSessionSearch('');
    setSessionDropdownOpen(true);
  }

  function closeSessionDropdown() {
    window.setTimeout(() => {
      setSessionDropdownOpen(false);
      setSessionSearch((current) => current.trim() === '' && selectedSession ? sessionLabel(selectedSession) : current);
    }, 120);
  }

  async function loadStudent(candidateId?: number) {
    if (!candidateId || !sessionId) return;
    const { data } = await api.get(`/reports/sessions/${sessionId}/students/${candidateId}`);
    setStudent(data);
    setActiveTab('student');
  }

  async function exportEvidence(format: 'json' | 'zip') {
    if (!sessionId) return;
    const { data } = await api.post('/reports/evidence-exports', { exam_session_id: Number(sessionId), format });
    const response = await api.get(`/reports/evidence-exports/${data.id}/download`, { responseType: 'blob' });
    downloadBlob(response.data, `evidence-session-${sessionId}.${format}`);
    setMessage(`Evidence ${format.toUpperCase()} ready: ${data.checksum}`);
  }

  async function downloadPdf() {
    if (!sessionId) return;
    const response = await api.get(`/reports/sessions/${sessionId}/score-report.pdf`, { responseType: 'blob' });
    downloadBlob(response.data, `score-report-session-${sessionId}.pdf`);
  }

  useEffect(() => {
    void loadSessions();
  }, []);

  return (
    <div>
      <PageHeader title="Reports" eyebrow="Score, progress, and item analysis" />
      {message ? <p className="success">{message}</p> : null}

      <div className="report-layout single">
        <main className="report-main">
          <form className="toolbar" onSubmit={submit}>
            <div className="session-picker">
              <label className="session-combobox">
                <Search size={17} />
                <input
                  value={sessionSearch}
                  placeholder="Search session name, exam code, or ID..."
                  onBlur={closeSessionDropdown}
                  onChange={(event) => {
                    setSessionSearch(event.target.value);
                    setSessionDropdownOpen(true);
                  }}
                  onFocus={openSessionDropdown}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && filteredSessions[0]) {
                      event.preventDefault();
                      void chooseSession(filteredSessions[0]);
                    }
                  }}
                />
                <ChevronDown size={17} />
              </label>
              {sessionDropdownOpen ? (
                <div className="session-dropdown">
                  {filteredSessions.map((session) => (
                    <button key={session.id} type="button" className={String(session.id) === sessionId ? 'active' : ''} onClick={() => void chooseSession(session)}>
                      <strong>{session.name}</strong>
                      <span>{session.exam?.code ?? '-'} / #{session.id} / {session.status}</span>
                      <small>{session.attempts_count ?? 0} attempts</small>
                    </button>
                  ))}
                  {!filteredSessions.length ? <p className="muted">No matching sessions.</p> : null}
                </div>
              ) : null}
            </div>
            <button className="primary">Load results</button>
            <button type="button" className="secondary" onClick={() => void downloadPdf()} disabled={!sessionId}><FileText size={18} /> Score PDF</button>
            <button type="button" className="secondary" onClick={() => void exportEvidence('zip')} disabled={!sessionId}><FileArchive size={18} /> Evidence ZIP</button>
            <button type="button" className="secondary" onClick={() => void exportEvidence('json')} disabled={!sessionId}><Download size={18} /> Evidence JSON</button>
          </form>

          <div className="report-hero">
            <div>
              <span>{selectedSession?.exam?.title ?? 'Select a session'}</span>
              <h2>{selectedSession?.name ?? 'No session loaded'}</h2>
              <small>{selectedSession ? `${formatDate(selectedSession.starts_at)} / Session #${selectedSession.id}` : 'Load a session to view results.'}</small>
            </div>
            <strong>{loading ? 'Loading...' : `${rows.length} scores`}</strong>
          </div>

          <div className="report-summary-grid">
            <section><span>Average</span><strong>{summary.average}%</strong></section>
            <section><span>Highest</span><strong>{summary.highest}%</strong></section>
            <section><span>Lowest</span><strong>{summary.lowest}%</strong></section>
            <section><span>Manual pending</span><strong>{summary.pendingManual}</strong></section>
            <section><span>Incidents</span><strong>{summary.incidents}</strong></section>
          </div>

          <div className="report-tabs">
            <button className={activeTab === 'scores' ? 'active' : ''} onClick={() => setActiveTab('scores')}><UserRound size={18} /> Candidate scores</button>
            <button className={activeTab === 'items' ? 'active' : ''} onClick={() => setActiveTab('items')}><ListChecks size={18} /> Item analysis</button>
            <button className={activeTab === 'topics' ? 'active' : ''} onClick={() => setActiveTab('topics')}><BarChart3 size={18} /> Topic progress</button>
            <button className={activeTab === 'student' ? 'active' : ''} onClick={() => setActiveTab('student')} disabled={!student}><UserRound size={18} /> Student detail</button>
          </div>

          {activeTab === 'scores' ? (
            <section className="content-panel">
              <DataTable
                rows={rows}
                rowKey={(row) => row.id}
                searchPlaceholder="Search candidate score..."
                initialSort={{ key: 'score' }}
                columns={[
                  { key: 'candidate_number', header: 'Candidate No.', accessor: (row) => row.submission?.attempt?.candidate?.candidate_number ?? '-' },
                  { key: 'name', header: 'Name', accessor: (row) => row.submission?.attempt?.candidate?.name ?? '-' },
                  {
                    key: 'score',
                    header: 'Score',
                    accessor: (row) => pct(row.total_score, row.max_score),
                    render: (row) => <ScoreCell earned={row.total_score} max={row.max_score} />,
                  },
                  { key: 'status', header: 'Status', accessor: (row) => row.status },
                  {
                    key: 'detail',
                    header: 'Analysis',
                    sortable: false,
                    searchable: false,
                    render: (row) => <button onClick={() => void loadStudent(row.submission?.attempt?.candidate?.id)}>View</button>,
                  },
                ]}
              />
            </section>
          ) : null}

          {activeTab === 'items' ? (
            <section className="content-panel">
              <DataTable
                rows={report.item_analysis ?? []}
                rowKey={(row, index) => `${row.question_external_id}-${index}`}
                searchPlaceholder="Search question item..."
                initialSort={{ key: 'facility' }}
                columns={[
                  { key: 'question', header: 'Question', accessor: (row) => row.question_external_id },
                  { key: 'type', header: 'Type', accessor: (row) => row.answer_type ?? row.type ?? '-' },
                  { key: 'average', header: 'Average', accessor: (row) => n(row.average_score), render: (row) => `${n(row.average_score).toFixed(2)} / ${n(row.max_marks).toFixed(2)}` },
                  { key: 'facility', header: 'Facility', accessor: (row) => Number(row.facility_index ?? pct(row.average_score, row.max_marks) / 100), render: (row) => `${Math.round(Number(row.facility_index ?? pct(row.average_score, row.max_marks) / 100) * 100)}%` },
                  { key: 'manual_pending', header: 'Manual pending', accessor: (row) => row.manual_pending ?? 0 },
                ]}
              />
            </section>
          ) : null}

          {activeTab === 'topics' ? (
            <section className="content-panel">
              <div className="topic-progress-list">
                {(report.topic_progress ?? []).map((topic, index) => (
                  <div key={`${topic.topic ?? 'General'}-${index}`}>
                    <span>{topic.topic ?? 'General'}</span>
                    <div><i style={{ width: `${pct(topic.average_score, topic.max_marks)}%` }} /></div>
                    <strong>{pct(topic.average_score, topic.max_marks)}%</strong>
                  </div>
                ))}
                {!report.topic_progress?.length ? <p className="muted">No topic progress yet.</p> : null}
              </div>
            </section>
          ) : null}

          {activeTab === 'student' ? (
            <section className="content-panel">
              {student ? (
                <div className="student-analysis">
                  <div>
                    <span>Student analysis</span>
                    <h2>{student.candidate.candidate_number} / {student.candidate.name}</h2>
                  </div>
                  <div className="topic-progress-list">
                    {student.topic_progress.map((topic) => (
                      <div key={topic.topic}>
                        <span>{topic.topic}</span>
                        <div><i style={{ width: `${pct(topic.earned_marks, topic.max_marks)}%` }} /></div>
                        <strong>{topic.earned_marks}/{topic.max_marks}</strong>
                      </div>
                    ))}
                  </div>
                  <DataTable
                    rows={student.question_breakdown}
                    rowKey={(row) => row.question_external_id}
                    searchPlaceholder="Search question breakdown..."
                    columns={[
                      { key: 'question', header: 'Question', accessor: (row) => row.question_external_id },
                      { key: 'type', header: 'Type', accessor: (row) => row.type },
                      { key: 'topic', header: 'Topic', accessor: (row) => row.topic ?? '-' },
                      { key: 'score', header: 'Score', accessor: (row) => row.earned_marks, render: (row) => `${row.earned_marks}/${row.max_marks}` },
                      { key: 'manual', header: 'Manual', accessor: (row) => row.requires_manual_marking ? 'yes' : 'no' },
                    ]}
                  />
                </div>
              ) : <p className="muted">Choose a candidate from Candidate scores to view student analysis.</p>}
            </section>
          ) : null}
        </main>
      </div>
    </div>
  );
}

function ScoreCell({ earned, max }: { earned: string | number; max: string | number }) {
  const percent = pct(earned, max);

  return (
    <div className="score-cell">
      <span>{n(earned).toFixed(2)} / {n(max).toFixed(2)}</span>
      <div><i style={{ width: `${percent}%` }} /></div>
      <strong>{percent}%</strong>
    </div>
  );
}
