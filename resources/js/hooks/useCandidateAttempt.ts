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

type SyncState = 'loading' | 'synced' | 'saving' | 'pending' | 'offline' | 'error';

type LocalAttemptDraft = {
  version: 1;
  attemptId: string;
  paper?: CandidatePaper | null;
  answers: Record<string, unknown>;
  context: Record<string, unknown>;
  sequence: number;
  status?: string;
  expiresAt?: string | null;
  secondsRemaining?: number;
  updatedAt: string;
  pendingSync: boolean;
};

function draftKey(attemptId: string) {
  return `candidate_attempt_draft_${attemptId}`;
}

function readDraft(attemptId: string): LocalAttemptDraft | null {
  try {
    const raw = localStorage.getItem(draftKey(attemptId));
    if (!raw) return null;
    const draft = JSON.parse(raw) as LocalAttemptDraft;
    return draft.version === 1 && draft.attemptId === attemptId ? draft : null;
  } catch {
    return null;
  }
}

function writeDraft(draft: LocalAttemptDraft) {
  try {
    localStorage.setItem(draftKey(draft.attemptId), JSON.stringify(draft));
  } catch {
    // Browser storage can be unavailable or full; server autosave remains the primary persistence layer.
  }
}

function removeDraft(attemptId: string) {
  try {
    localStorage.removeItem(draftKey(attemptId));
  } catch {
    // Ignore storage cleanup failures.
  }
}

function answersFromAutosave(normalizedAnswers: Record<string, unknown> | undefined): Record<string, unknown> {
  if (!normalizedAnswers) return {};

  return Object.fromEntries(
    Object.entries(normalizedAnswers).map(([id, value]) => [id, (value as { answer: unknown }).answer]),
  );
}

function isDraftNewer(draft: LocalAttemptDraft | null, serverSavedAt?: string): boolean {
  if (!draft) return false;
  if (draft.pendingSync) return true;
  if (!serverSavedAt) return true;

  return Date.parse(draft.updatedAt) > Date.parse(serverSavedAt);
}

function errorText(error: unknown) {
  return error instanceof Error ? error.message : 'Sinkronisasi gagal.';
}

export function useCandidateAttempt(attemptId: string | undefined) {
  const [paper, setPaper] = useState<CandidatePaper | null>(null);
  const [answers, setAnswers] = useState<Record<string, unknown>>({});
  const [secondsRemaining, setSecondsRemaining] = useState(0);
  const [status, setStatus] = useState('loading');
  const [saving, setSaving] = useState(false);
  const [syncState, setSyncState] = useState<SyncState>('loading');
  const [syncError, setSyncError] = useState('');
  const [lastLocalSavedAt, setLastLocalSavedAt] = useState<string | null>(null);
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  const sequence = useRef(1);
  const context = useRef<Record<string, unknown>>({});
  const answersRef = useRef<Record<string, unknown>>({});
  const paperRef = useRef<CandidatePaper | null>(null);
  const statusRef = useRef('loading');
  const expiresAtRef = useRef<string | null>(null);
  const secondsRemainingRef = useRef(0);
  const hydrating = useRef(false);

  const headers = useMemo(() => (attemptId ? candidateSessionHeaders(attemptId) : {}), [attemptId]);

  const persistDraft = useCallback((nextAnswers: Record<string, unknown>, pendingSync: boolean) => {
    if (!attemptId) return;
    const updatedAt = new Date().toISOString();
    writeDraft({
      version: 1,
      attemptId,
      paper: paperRef.current,
      answers: nextAnswers,
      context: context.current,
      sequence: sequence.current,
      status: statusRef.current,
      expiresAt: expiresAtRef.current,
      secondsRemaining: secondsRemainingRef.current,
      updatedAt,
      pendingSync,
    });
    setLastLocalSavedAt(updatedAt);
    setSyncState((current) => (pendingSync ? (navigator.onLine ? 'pending' : 'offline') : current));
  }, [attemptId]);

  const load = useCallback(async () => {
    if (!attemptId) return;
    const localDraft = readDraft(attemptId);
    let data;

    try {
      const response = await api.get(`/candidate/attempts/${attemptId}`, { headers });
      data = response.data;
    } catch (error: unknown) {
      if (localDraft?.paper) {
        hydrating.current = true;
        paperRef.current = localDraft.paper;
        statusRef.current = localDraft.status ?? 'offline';
        expiresAtRef.current = localDraft.expiresAt ?? null;
        secondsRemainingRef.current = Number(localDraft.secondsRemaining ?? 0);
        sequence.current = Math.max(sequence.current, Number(localDraft.sequence ?? 1));
        context.current = localDraft.context ?? {};
        setPaper(localDraft.paper);
        setAnswers(localDraft.answers);
        setStatus(localDraft.status ?? 'offline');
        setSecondsRemaining(Number(localDraft.secondsRemaining ?? 0));
        setLastLocalSavedAt(localDraft.updatedAt);
        setSyncState('offline');
        setSyncError('Koneksi putus. Draft lokal dibuka dari browser ini.');
        setLoaded(true);

        return;
      }

      throw error;
    }

    const latestAutosave = data.latest_autosave;
    const serverAnswers = answersFromAutosave(latestAutosave?.normalized_answers);
    const useLocalDraft = isDraftNewer(localDraft, latestAutosave?.saved_at);
    const restoredAnswers = useLocalDraft ? localDraft?.answers ?? {} : serverAnswers;

    hydrating.current = true;
    paperRef.current = data.paper;
    statusRef.current = data.attempt.status;
    expiresAtRef.current = data.attempt.expires_at ?? null;
    secondsRemainingRef.current = data.seconds_remaining;
    context.current = localDraft?.context ?? {};
    setPaper(data.paper);
    setSecondsRemaining(data.seconds_remaining);
    setStatus(data.attempt.status);
    setAnswers(restoredAnswers);
    setLoaded(true);

    sequence.current = Math.max(
      sequence.current,
      Number(latestAutosave?.client_sequence ?? 0) + 1,
      Number(localDraft?.sequence ?? 1),
    );

    if (localDraft) {
      setLastLocalSavedAt(localDraft.updatedAt);
    }

    if (latestAutosave?.saved_at) {
      setLastSyncedAt(latestAutosave.saved_at);
    }

    setSyncState(useLocalDraft ? (navigator.onLine ? 'pending' : 'offline') : 'synced');
  }, [attemptId, headers]);

  const syncTime = useCallback(async () => {
    if (!attemptId) return;
    try {
      const { data } = await api.get(`/candidate/attempts/${attemptId}/time`, { headers });
      secondsRemainingRef.current = data.seconds_remaining;
      expiresAtRef.current = data.expires_at ?? expiresAtRef.current;
      statusRef.current = data.status;
      setSecondsRemaining(data.seconds_remaining);
      setStatus(data.status);
      if (syncState === 'offline') {
        setSyncState('pending');
      }
    } catch {
      persistDraft(answersRef.current, true);
      setSyncState('offline');
      setSyncError('Koneksi putus. Timer server akan disinkronkan ulang saat online.');
    }
  }, [attemptId, headers, persistDraft, syncState]);

  const setAttemptContext = useCallback((nextContext: Record<string, unknown>) => {
    context.current = nextContext;
  }, []);

  const heartbeat = useCallback(async (overrideContext: Record<string, unknown> = {}) => {
    if (!attemptId || status === 'submitted' || status === 'auto_submitted') return false;

    try {
      await api.post(
        `/candidate/attempts/${attemptId}/heartbeat`,
        {
          visibility: document.visibilityState,
          network: navigator.onLine ? 'online' : 'offline',
          ...context.current,
          ...overrideContext,
        },
        { headers },
      );

      return true;
    } catch {
      persistDraft(answersRef.current, true);
      setSyncState(navigator.onLine ? 'pending' : 'offline');

      return false;
    }
  }, [attemptId, headers, persistDraft, status]);

  const autosave = useCallback(async () => {
    if (!attemptId || status === 'submitted' || status === 'auto_submitted') return false;
    persistDraft(answersRef.current, true);

    if (!navigator.onLine) {
      setSyncError('Koneksi putus. Jawaban tersimpan lokal dan akan dikirim saat online.');
      setSyncState('offline');
      return false;
    }

    setSaving(true);
    setSyncState('saving');
    setSyncError('');
    try {
      const { data } = await api.post(
        `/candidate/attempts/${attemptId}/autosave`,
        { client_sequence: sequence.current, answers: answersRef.current, context: context.current },
        { headers },
      );
      sequence.current += 1;
      persistDraft(answersRef.current, false);
      setLastSyncedAt(data.server_time ?? new Date().toISOString());
      setSyncState('synced');
      return true;
    } catch (error: unknown) {
      persistDraft(answersRef.current, true);
      setSyncError(navigator.onLine ? errorText(error) : 'Koneksi putus. Jawaban tersimpan lokal.');
      setSyncState(navigator.onLine ? 'pending' : 'offline');
      return false;
    } finally {
      setSaving(false);
    }
  }, [attemptId, headers, persistDraft, status]);

  const submit = useCallback(async () => {
    if (!attemptId) return;
    await autosave();
    if (!navigator.onLine) {
      throw new Error('Koneksi belum tersambung. Jawaban sudah tersimpan lokal dan akan disinkronkan saat online.');
    }
    const idempotency = crypto.randomUUID();
    try {
      const { data } = await api.post(
        `/candidate/attempts/${attemptId}/submit`,
        { answers: answersRef.current, idempotency_key: idempotency },
        { headers: { ...headers, 'Idempotency-Key': idempotency } },
      );
      removeDraft(attemptId);
      setStatus(data.submission.status);
      setSyncState('synced');
      return data.submission;
    } catch (error: unknown) {
      persistDraft(answersRef.current, true);
      setSyncError(errorText(error));
      setSyncState(navigator.onLine ? 'pending' : 'offline');
      throw error;
    }
  }, [attemptId, autosave, headers, persistDraft]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    answersRef.current = answers;
  }, [answers]);

  useEffect(() => {
    paperRef.current = paper;
  }, [paper]);

  useEffect(() => {
    statusRef.current = status;
  }, [status]);

  useEffect(() => {
    secondsRemainingRef.current = secondsRemaining;
  }, [secondsRemaining]);

  useEffect(() => {
    if (!attemptId || !loaded || status === 'submitted' || status === 'auto_submitted') return;

    if (hydrating.current) {
      hydrating.current = false;
      return;
    }

    persistDraft(answers, true);
  }, [answers, attemptId, loaded, persistDraft, status]);

  useEffect(() => {
    const timer = window.setInterval(() => void syncTime(), 15000);
    return () => window.clearInterval(timer);
  }, [syncTime]);

  useEffect(() => {
    const timer = window.setInterval(() => {
      setSecondsRemaining((current) => {
        const next = Math.max(0, current - 1);
        secondsRemainingRef.current = next;
        return next;
      });
    }, 1000);

    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const timer = window.setInterval(() => void autosave(), 30000);
    return () => window.clearInterval(timer);
  }, [autosave]);

  useEffect(() => {
    const timer = window.setInterval(() => void heartbeat(), 20000);
    void heartbeat();
    return () => window.clearInterval(timer);
  }, [heartbeat]);

  useEffect(() => {
    const handleOnline = () => {
      if (status !== 'submitted' && status !== 'auto_submitted') {
        void autosave();
        void heartbeat({ activity: 'reconnected' });
      }
    };
    const handleOffline = () => {
      persistDraft(answersRef.current, true);
      setSyncState('offline');
      setSyncError('Koneksi putus. Jawaban tetap tersimpan di browser ini.');
    };
    const handleBeforeUnload = () => persistDraft(answersRef.current, syncState !== 'synced');

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    window.addEventListener('beforeunload', handleBeforeUnload);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [autosave, heartbeat, persistDraft, status, syncState]);

  return {
    paper,
    answers,
    setAnswers,
    secondsRemaining,
    status,
    saving,
    autosave,
    submit,
    syncTime,
    heartbeat,
    setAttemptContext,
    syncState,
    syncError,
    lastLocalSavedAt,
    lastSyncedAt,
  };
}
