import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Save, Send } from 'lucide-react';
import { ExamQuestion, RichContent, useCandidateAttempt } from '../hooks/useCandidateAttempt';

function mmss(seconds: number) {
  const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
  const secs = Math.max(0, seconds % 60).toString().padStart(2, '0');
  return `${minutes}:${secs}`;
}

function shortTime(value?: string | null) {
  if (!value) return '';
  return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function syncLabel(state: string, saving: boolean, lastSyncedAt?: string | null, lastLocalSavedAt?: string | null) {
  if (saving || state === 'saving') return 'Saving...';
  if (state === 'synced') return lastSyncedAt ? `Synced ${shortTime(lastSyncedAt)}` : 'Synced';
  if (state === 'offline') return lastLocalSavedAt ? `Offline saved ${shortTime(lastLocalSavedAt)}` : 'Offline saved';
  if (state === 'pending') return lastLocalSavedAt ? `Pending sync ${shortTime(lastLocalSavedAt)}` : 'Pending sync';
  if (state === 'error') return 'Sync issue';
  return state;
}

function Rich({ content }: { content: RichContent }) {
  return (
    <div className="rich">
      {content.image ? (
        <figure className="media-figure">
          <img src={content.image} alt={content.caption ?? 'Question media'} />
          {content.caption ? <figcaption>{content.caption}</figcaption> : null}
        </figure>
      ) : null}
      {content.text ? <p>{content.text}</p> : null}
      {content.math ? <code>{content.math}</code> : null}
      {content.table ? (
        <table>
          <tbody>{content.table.map((row, i) => <tr key={i}>{row.map((cell, j) => <td key={j}>{cell}</td>)}</tr>)}</tbody>
        </table>
      ) : null}
    </div>
  );
}

function QuestionInput({ question, value, onChange }: { question: ExamQuestion; value: unknown; onChange: (value: unknown) => void }) {
  if (question.type === 'objective') {
    return <div className="option-list">{question.options?.map((option) => (
      <label key={option.id} className={value === option.id ? 'selected' : ''}><input type="radio" name={`q-${question.id}`} checked={value === option.id} onChange={() => onChange(option.id)} /><Rich content={option.content} /></label>
    ))}</div>;
  }

  if (question.type === 'checkbox') {
    const selected = Array.isArray(value) ? value as number[] : [];
    return <div className="option-list">{question.options?.map((option) => (
      <label key={option.id} className={selected.includes(option.id) ? 'selected' : ''}><input type="checkbox" checked={selected.includes(option.id)} onChange={(event) => onChange(event.target.checked ? [...selected, option.id] : selected.filter((id) => id !== option.id))} /><Rich content={option.content} /></label>
    ))}</div>;
  }

  if (question.type === 'numerical') {
    return <input className="answer-input" inputMode="decimal" value={(value as string | number | undefined) ?? ''} onChange={(event) => onChange(event.target.value)} />;
  }

  return <textarea className="answer-textarea" value={(value as string | undefined) ?? ''} onChange={(event) => onChange(event.target.value)} maxLength={question.type === 'essay' ? 8000 : 12000} />;
}

export function CandidateExamPage() {
  const { attemptId } = useParams();
  const navigate = useNavigate();
  const {
    paper,
    answers,
    setAnswers,
    secondsRemaining,
    status,
    saving,
    autosave,
    submit,
    heartbeat,
    setAttemptContext,
    syncState,
    syncError,
    lastLocalSavedAt,
    lastSyncedAt,
  } = useCandidateAttempt(attemptId);
  const [index, setIndex] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const question = paper?.questions[index];
  const isAnswered = (item: ExamQuestion) => {
    const value = answers[String(item.id)];
    return value !== null && value !== undefined && value !== '' && (!Array.isArray(value) || value.length > 0);
  };
  const answered = useMemo(() => paper?.questions.filter(isAnswered).length ?? 0, [answers, paper]);
  const progressPercent = paper?.questions.length ? Math.round((answered / paper.questions.length) * 100) : 0;
  const questionContext = useMemo(() => {
    if (!paper || !question) return {};

    return {
      current_question_id: question.id,
      current_question_external_id: question.external_id,
      current_question_position: question.position,
      answered_count: answered,
      question_count: paper.questions.length,
    };
  }, [answered, paper, question]);

  async function move(next: number) {
    await autosave();
    setIndex(next);
  }

  async function finalSubmit() {
    setSubmitting(true);
    setSubmitError('');
    try {
      await submit();
      navigate('/candidate/submitted');
    } catch (error: unknown) {
      setSubmitError(error instanceof Error ? error.message : 'Submit gagal. Jawaban tetap tersimpan lokal.');
    } finally {
      setSubmitting(false);
    }
  }

  useEffect(() => {
    if (status === 'submitted' || status === 'auto_submitted') {
      navigate('/candidate/submitted');
    }
  }, [navigate, status]);

  useEffect(() => {
    setAttemptContext(questionContext);
  }, [questionContext, setAttemptContext]);

  useEffect(() => {
    if (question) {
      void heartbeat({ ...questionContext, activity: 'question_viewed' });
    }
  }, [heartbeat, question, questionContext]);

  if (!paper || !question) {
    return <section className="exam-shell"><p>Loading secure attempt...</p></section>;
  }

  return (
    <section className="exam-shell">
      <header className="exam-topbar">
        <div className="exam-brand">
          <strong>Secure Exam</strong>
          <span>{paper.strict_mode ? 'Strict mode' : 'Tryout mode'}</span>
        </div>
        <div className="exam-progress">
          <div><strong>{answered}/{paper.questions.length}</strong><span> answered</span></div>
          <i><b style={{ width: `${progressPercent}%` }} /></i>
        </div>
        <div className={secondsRemaining < 300 ? 'timer danger' : 'timer'}>{mmss(secondsRemaining)}</div>
        <div className={`save-state ${syncState}`} title={syncError || undefined}>{syncLabel(syncState, saving, lastSyncedAt, lastLocalSavedAt)}</div>
      </header>
      <aside className="question-nav">
        <div className="question-nav-title">Question numbers</div>
        {paper.questions.map((item, itemIndex) => (
          <button
            key={item.id}
            className={`${itemIndex === index ? 'active' : ''} ${isAnswered(item) ? 'answered' : ''} ${item.stem.image ? 'has-media' : ''}`}
            onClick={() => void move(itemIndex)}
          >
            <strong>{item.position}</strong>
          </button>
        ))}
      </aside>
      <main className="question-pane">
        <div className="question-scroll">
          <div className="question-head">
            <div>
              <span>Question {index + 1} of {paper.questions.length}</span>
              <h1>Question {question.position}</h1>
            </div>
            <strong>{question.max_marks} marks</strong>
          </div>
          <div className="question-meta">
            <span>{question.type}</span>
            <span>{question.topic ?? 'general'}</span>
            {question.stem.image ? <span>media</span> : null}
          </div>
          <div className="question-body">
            <Rich content={question.stem} />
            <QuestionInput question={question} value={answers[String(question.id)]} onChange={(value) => setAnswers({ ...answers, [String(question.id)]: value })} />
          </div>
        </div>
      </main>
      <footer className="exam-actions">
        <div className={submitError || syncError ? 'exam-action-status error-text' : 'exam-action-status'}>
          {submitError || syncError || `Question ${index + 1} / ${paper.questions.length}`}
        </div>
        <button className="secondary" disabled={index === 0} onClick={() => void move(index - 1)}><ChevronLeft size={18} /> Previous</button>
        <button className="secondary" onClick={() => void autosave()}><Save size={18} /> Save</button>
        {index < paper.questions.length - 1 ? <button className="primary" onClick={() => void move(index + 1)}>Next <ChevronRight size={18} /></button> : <button className="primary" disabled={submitting} onClick={() => void finalSubmit()}><Send size={18} /> Submit</button>}
      </footer>
    </section>
  );
}
