import axios from 'axios';

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('admin_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export type ApiError = { message?: string; errors?: Record<string, string[]> };

export function candidateSessionHeaders(attemptId: string | number) {
  const sessionKey = localStorage.getItem(`candidate_session_${attemptId}`);
  return sessionKey ? { 'X-Candidate-Session': sessionKey } : {};
}

export function storeCandidateSession(attemptId: string | number, sessionKey: string) {
  localStorage.setItem(`candidate_session_${attemptId}`, sessionKey);
}

export async function adminLogin(email: string, password: string) {
  const { data } = await api.post('/auth/login', { email, password, device_name: 'secure-exam-admin' });
  localStorage.setItem('admin_token', data.token);
  return data.user;
}

export async function fetchJson<T>(url: string): Promise<T> {
  const { data } = await api.get(url);
  return data;
}
