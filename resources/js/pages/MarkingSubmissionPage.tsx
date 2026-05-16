import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Answer = { id: number; question_external_id: string; answer?: unknown; max_marks: string; question?: { stem?: { text?: string }; rubrics?: { criterion: string; max_marks: string }[] } };

export function MarkingSubmissionPage() {
  const { answerId } = useParams();
  const [answer, setAnswer] = useState<Answer | null>(null);
  const [earned, setEarned] = useState('0');
  const [comments, setComments] = useState('');

  useEffect(() => {
    api.get(`/marking/answers/${answerId}`).then(({ data }) => {
      setAnswer(data);
      setEarned(String(data.manual_score ?? 0));
    });
  }, [answerId]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    await api.post(`/marking/answers/${answerId}/marks`, { earned_marks: Number(earned), comments });
  }

  if (!answer) return <p>Loading...</p>;

  return (
    <div>
      <PageHeader title={`Mark ${answer.question_external_id}`} eyebrow="Marker workspace" />
      <div className="split">
        <section><h2>Question</h2><p>{answer.question?.stem?.text}</p><h2>Answer</h2><pre>{JSON.stringify(answer.answer, null, 2)}</pre></section>
        <form className="stack" onSubmit={submit}>
          <label>Earned marks<input value={earned} onChange={(event) => setEarned(event.target.value)} inputMode="decimal" /></label>
          <label>Comments<textarea value={comments} onChange={(event) => setComments(event.target.value)} /></label>
          <button className="primary">Save mark</button>
        </form>
      </div>
    </div>
  );
}
