import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, candidateSessionHeaders } from '../lib/api';

export type RichContent = {
  text?: string;
  image?: string;
  caption?: string;
  math?: string;
  table?: string[][];
};

export type ExamQuestion = {
  id: number;
  external_id: string;
  type: 'objective' | 'checkbox' | 'numerical' | 'essay' | 'structured';
  position: number;
  topic?: string | null;
  max_marks: number;
  stem: RichContent;
  options?: { id: number; external_id: string; content: RichContent }[];
  rubrics?: { criterion: string; max_marks: number; descriptors?: unknown }[];
};

export type CandidatePaper = {
  duration_minutes: number;
  strict_mode: boolean;
  total_marks: number;
  questions: ExamQuestion[];
};

export function useCandidateAttempt(attemptId: string | undefined) {
  const [paper, setPaper] = useState<CandidatePaper | null>(null);
  const [answers, setAnswers] = useState<Record<string, unknown>>({});
  const [secondsRemaining, setSecondsRemaining] = useState(0);
  const [status, setStatus] = useState('loading');
  const [saving, setSaving] = useState(false);
  const sequence = useRef(1);

  const headers = useMemo(() => (attemptId ? candidateSessionHeaders(attemptId) : {}), [attemptId]);

  const load = useCallback(async () => {
    if (!attemptId) return;
    const { data } = await api.get(`/candidate/attempts/${attemptId}`, { headers });
    setPaper(data.paper);
    setSecondsRemaining(data.seconds_remaining);
    setStatus(data.attempt.status);
    if (data.latest_autosave?.normalized_answers) {
      const restored = Object.fromEntries(
        Object.entries(data.latest_autosave.normalized_answers).map(([id, value]) => [id, (value as { answer: unknown }).answer]),
      );
      setAnswers(restored);
    }
  }, [attemptId, headers]);

  const syncTime = useCallback(async () => {
    if (!attemptId) return;
    const { data } = await api.get(`/candidate/attempts/${attemptId}/time`, { headers });
    setSecondsRemaining(data.seconds_remaining);
    setStatus(data.status);
  }, [attemptId, headers]);

  const heartbeat = useCallback(async () => {
    if (!attemptId || status === 'submitted' || status === 'auto_submitted') return;
    await api.post(
      `/candidate/attempts/${attemptId}/heartbeat`,
      { visibility: document.visibilityState, network: navigator.onLine ? 'online' : 'offline' },
      { headers },
    );
  }, [attemptId, headers, status]);

  const autosave = useCallback(async () => {
    if (!attemptId || status === 'submitted' || status === 'auto_submitted') return;
    setSaving(true);
    try {
      await api.post(
        `/candidate/attempts/${attemptId}/autosave`,
        { client_sequence: sequence.current++, answers },
        { headers },
      );
    } finally {
      setSaving(false);
    }
  }, [attemptId, answers, headers, status]);

  const submit = useCallback(async () => {
    if (!attemptId) return;
    await autosave();
    const idempotency = crypto.randomUUID();
    const { data } = await api.post(
      `/candidate/attempts/${attemptId}/submit`,
      { answers, idempotency_key: idempotency },
      { headers: { ...headers, 'Idempotency-Key': idempotency } },
    );
    setStatus(data.submission.status);
    return data.submission;
  }, [attemptId, answers, autosave, headers]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const timer = window.setInterval(() => void syncTime(), 15000);
    return () => window.clearInterval(timer);
  }, [syncTime]);

  useEffect(() => {
    const timer = window.setInterval(() => void autosave(), 30000);
    return () => window.clearInterval(timer);
  }, [autosave]);

  useEffect(() => {
    const timer = window.setInterval(() => void heartbeat(), 20000);
    void heartbeat();
    return () => window.clearInterval(timer);
  }, [heartbeat]);

  return { paper, answers, setAnswers, secondsRemaining, status, saving, autosave, submit, syncTime };
}
