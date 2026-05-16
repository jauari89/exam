import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Braces, FileUp, ListChecks, PackagePlus, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type QuestionType = 'objective' | 'checkbox' | 'numerical' | 'essay' | 'structured';
type QuestionFormat = QuestionType | 'true_false';
type CognitiveLevel = 'lots' | 'mots' | 'hots';
type BloomLevel = 'remember' | 'understand' | 'apply' | 'analyze' | 'evaluate' | 'create';

type QuestionBankOption = {
  id?: number;
  external_id: string;
  content?: { text?: string; image?: string };
  is_correct?: boolean;
  marks?: string | number;
};

type QuestionBankRubric = {
  id?: number;
  criterion: string;
  max_marks?: string | number;
  descriptors?: Record<string, string>;
};

type QuestionBankItem = {
  id: number;
  external_id: string;
  type: QuestionType;
  difficulty: string;
  topic?: string;
  max_marks: string;
  stem?: { text?: string; image?: string; math?: string };
  correct_answer?: { value?: number | string; option_ids?: string | string[] };
  validation_rules?: { tolerance?: number | string; max_length?: number | string };
  metadata?: {
    question_format?: QuestionFormat;
    cognitive_level?: CognitiveLevel;
    bloom_level?: BloomLevel;
    taxonomy_note?: string;
  };
  options?: QuestionBankOption[];
  rubrics?: QuestionBankRubric[];
};

type QuestionBank = {
  id: number;
  code: string;
  title: string;
  subject?: string;
  level?: string;
  status: string;
  items_count?: number;
  items?: QuestionBankItem[];
};

type QuestionForm = {
  item_id?: number;
  external_id: string;
  type: QuestionType;
  question_format: QuestionFormat;
  difficulty: string;
  cognitive_level: CognitiveLevel;
  bloom_level: BloomLevel;
  topic: string;
  max_marks: string;
  stem_text: string;
  stem_image: string;
  stem_math: string;
  numerical_answer: string;
  tolerance: string;
  max_length: string;
  rubric_criterion: string;
  rubric_high: string;
  rubric_mid: string;
  rubric_low: string;
  options: Array<{ external_id: string; text: string; image: string; is_correct: boolean; marks: string }>;
};

const questionFormats: Array<{ value: QuestionFormat; label: string; description: string; type: QuestionType }> = [
  { value: 'objective', label: 'Opsi / ABCD', description: 'Satu jawaban benar, koreksi otomatis.', type: 'objective' },
  { value: 'checkbox', label: 'Multi jawaban', description: 'Lebih dari satu jawaban benar.', type: 'checkbox' },
  { value: 'true_false', label: 'T/F', description: 'Benar atau salah, tetap diskor otomatis.', type: 'objective' },
  { value: 'numerical', label: 'Numerik', description: 'Jawaban angka dengan toleransi.', type: 'numerical' },
  { value: 'essay', label: 'Esai', description: 'Jawaban panjang, perlu marking manual.', type: 'essay' },
  { value: 'structured', label: 'Structured', description: 'Jawaban bertahap dengan rubrik.', type: 'structured' },
];

const cognitiveLevels: Array<{ value: CognitiveLevel; label: string; bloomHint: BloomLevel }> = [
  { value: 'lots', label: 'LOTS', bloomHint: 'remember' },
  { value: 'mots', label: 'MOTS', bloomHint: 'apply' },
  { value: 'hots', label: 'HOTS', bloomHint: 'analyze' },
];

const bloomLevels: Array<{ value: BloomLevel; label: string }> = [
  { value: 'remember', label: 'Remember' },
  { value: 'understand', label: 'Understand' },
  { value: 'apply', label: 'Apply' },
  { value: 'analyze', label: 'Analyze' },
  { value: 'evaluate', label: 'Evaluate' },
  { value: 'create', label: 'Create' },
];

const importSample = JSON.stringify({
  questions: [
    {
      external_id: 'NEW-Q1',
      type: 'objective',
      difficulty: 'easy',
      topic: 'Measurements',
      max_marks: 1,
      stem: { text: 'Which instrument measures mass?' },
      metadata: { question_format: 'objective', cognitive_level: 'lots', bloom_level: 'remember' },
      options: [
        { external_id: 'A', content: { text: 'Balance' }, is_correct: true, marks: 1 },
        { external_id: 'B', content: { text: 'Stopwatch' }, is_correct: false, marks: 0 },
      ],
    },
  ],
}, null, 2);

function emptyQuestionForm(): QuestionForm {
  return {
    external_id: '',
    type: 'objective',
    question_format: 'objective',
    difficulty: 'medium',
    cognitive_level: 'mots',
    bloom_level: 'apply',
    topic: '',
    max_marks: '1',
    stem_text: '',
    stem_image: '',
    stem_math: '',
    numerical_answer: '',
    tolerance: '0',
    max_length: '8000',
    rubric_criterion: 'Knowledge, method, and explanation',
    rubric_high: 'Complete and accurate answer.',
    rubric_mid: 'Mostly correct with minor gaps.',
    rubric_low: 'Limited but relevant answer.',
    options: ['A', 'B', 'C', 'D'].map((letter) => ({ external_id: letter, text: '', image: '', is_correct: false, marks: letter === 'A' ? '1' : '0' })),
  };
}

function questionToForm(item: QuestionBankItem): QuestionForm {
  const firstRubric = item.rubrics?.[0];
  const trueFalseMarkers = (item.options ?? []).map((option) => `${option.external_id} ${option.content?.text ?? ''}`.toLowerCase());
  const looksTrueFalse = item.type === 'objective'
    && item.options?.length === 2
    && trueFalseMarkers.some((value) => value.includes('true') || value.includes('benar'))
    && trueFalseMarkers.some((value) => value.includes('false') || value.includes('salah'));
  const format = item.metadata?.question_format ?? (looksTrueFalse ? 'true_false' : item.type);
  const options = item.options?.length
    ? item.options.map((option) => ({
      external_id: option.external_id,
      text: option.content?.text ?? '',
      image: option.content?.image ?? '',
      is_correct: Boolean(option.is_correct),
      marks: String(option.marks ?? (option.is_correct ? item.max_marks : '0')),
    }))
    : emptyQuestionForm().options;

  return {
    item_id: item.id,
    external_id: item.external_id,
    type: item.type,
    question_format: format,
    difficulty: item.difficulty ?? 'medium',
    cognitive_level: item.metadata?.cognitive_level ?? 'mots',
    bloom_level: item.metadata?.bloom_level ?? 'apply',
    topic: item.topic ?? '',
    max_marks: String(item.max_marks ?? '1'),
    stem_text: item.stem?.text ?? '',
    stem_image: item.stem?.image ?? '',
    stem_math: item.stem?.math ?? '',
    numerical_answer: String(item.correct_answer?.value ?? ''),
    tolerance: String(item.validation_rules?.tolerance ?? '0'),
    max_length: String(item.validation_rules?.max_length ?? (item.type === 'essay' ? 8000 : 12000)),
    rubric_criterion: firstRubric?.criterion ?? 'Knowledge, method, and explanation',
    rubric_high: firstRubric?.descriptors?.high ?? 'Complete and accurate answer.',
    rubric_mid: firstRubric?.descriptors?.mid ?? 'Mostly correct with minor gaps.',
    rubric_low: firstRubric?.descriptors?.low ?? 'Limited but relevant answer.',
    options,
  };
}

function formToPayload(form: QuestionForm) {
  const maxMarks = Number(form.max_marks || 1);
  const stem = {
    text: form.stem_text,
    image: form.stem_image || undefined,
    math: form.stem_math || undefined,
  };
  const base = {
    external_id: form.external_id,
    type: form.type,
    difficulty: form.difficulty,
    topic: form.topic || undefined,
    max_marks: maxMarks,
    stem,
    metadata: {
      question_format: form.question_format,
      cognitive_level: form.cognitive_level,
      bloom_level: form.bloom_level,
      taxonomy_note: `${form.cognitive_level.toUpperCase()} / ${form.bloom_level}`,
    },
  };

  if (form.type === 'objective' || form.type === 'checkbox') {
    const options = form.options
      .filter((option) => option.external_id.trim() && (option.text.trim() || option.image.trim()))
      .map((option, index) => ({
        external_id: option.external_id.trim(),
        position: index + 1,
        content: {
          text: option.text,
          image: option.image || undefined,
        },
        is_correct: option.is_correct,
        marks: option.is_correct ? Number(option.marks || maxMarks) : 0,
      }));
    const correct = options.filter((option) => option.is_correct).map((option) => option.external_id);

    return {
      ...base,
      options,
      correct_answer: { option_ids: form.type === 'objective' ? (correct[0] ?? null) : correct },
    };
  }

  if (form.type === 'numerical') {
    return {
      ...base,
      correct_answer: { value: Number(form.numerical_answer) },
      validation_rules: { tolerance: Number(form.tolerance || 0) },
    };
  }

  return {
    ...base,
    validation_rules: { max_length: Number(form.max_length || (form.type === 'essay' ? 8000 : 12000)) },
    rubrics: [
      {
        criterion: form.rubric_criterion,
        max_marks: maxMarks,
        descriptors: {
          high: form.rubric_high,
          mid: form.rubric_mid,
          low: form.rubric_low,
        },
      },
    ],
  };
}

function questionFormatLabel(item: QuestionBankItem): string {
  const format = item.metadata?.question_format ?? item.type;
  return questionFormats.find((option) => option.value === format)?.label ?? item.type;
}

function cognitiveLabel(item: QuestionBankItem): string {
  const cognitive = item.metadata?.cognitive_level ? item.metadata.cognitive_level.toUpperCase() : '';
  const bloom = item.metadata?.bloom_level ? item.metadata.bloom_level : '';

  return [cognitive, bloom].filter(Boolean).join(' / ') || '-';
}

export function QuestionBankPage() {
  const { bankId } = useParams();
  const navigate = useNavigate();
  const [banks, setBanks] = useState<QuestionBank[]>([]);
  const [selected, setSelected] = useState<QuestionBank | null>(null);
  const [bankForm, setBankForm] = useState({ code: '', title: '', subject: '', level: '' });
  const [questionForm, setQuestionForm] = useState<QuestionForm>(emptyQuestionForm());
  const [activeTab, setActiveTab] = useState<'questions' | 'form' | 'import'>('questions');
  const [importJson, setImportJson] = useState(importSample);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [buildForm, setBuildForm] = useState({ exam_paper_id: '', question_count: '10', publish_session_id: '' });
  const [uploadingImage, setUploadingImage] = useState('');
  const [message, setMessage] = useState('');

  const selectedItem = useMemo(
    () => selected?.items?.find((item) => item.id === questionForm.item_id),
    [questionForm.item_id, selected?.items],
  );

  async function loadBanks() {
    const { data } = await api.get('/admin/question-banks');
    setBanks(data.data ?? data);
  }

  async function loadBank(id: number) {
    const { data } = await api.get(`/admin/question-banks/${id}`);
    setSelected(data);
  }

  useEffect(() => {
    if (bankId) {
      void loadBank(Number(bankId));
    } else {
      setSelected(null);
      void loadBanks();
    }
  }, [bankId]);

  async function createBank(event: FormEvent) {
    event.preventDefault();
    const { data } = await api.post('/admin/question-banks', bankForm);
    setMessage(`Bank ${data.code} dibuat.`);
    setBankForm({ code: '', title: '', subject: '', level: '' });
    navigate(`/admin/question-banks/${data.id}`);
  }

  function editQuestion(item: QuestionBankItem) {
    setActiveTab('form');
    setQuestionForm(questionToForm(item));
  }

  function resetQuestionForm() {
    setQuestionForm(emptyQuestionForm());
  }

  function applyQuestionFormat(format: QuestionFormat) {
    const selectedFormat = questionFormats.find((item) => item.value === format) ?? questionFormats[0];

    setQuestionForm((current) => {
      const next: QuestionForm = {
        ...current,
        question_format: format,
        type: selectedFormat.type,
      };

      if (format === 'true_false') {
        next.max_marks = current.max_marks || '1';
        next.options = [
          { external_id: 'TRUE', text: 'Benar', image: '', is_correct: true, marks: current.max_marks || '1' },
          { external_id: 'FALSE', text: 'Salah', image: '', is_correct: false, marks: '0' },
        ];
      }

      if (format === 'objective' && current.options.length < 3) {
        next.options = ['A', 'B', 'C', 'D'].map((letter) => ({ external_id: letter, text: '', image: '', is_correct: letter === 'A', marks: letter === 'A' ? (current.max_marks || '1') : '0' }));
      }

      return next;
    });
  }

  function updateCognitiveLevel(level: CognitiveLevel) {
    const bloomHint = cognitiveLevels.find((item) => item.value === level)?.bloomHint ?? 'apply';
    setQuestionForm((current) => ({
      ...current,
      cognitive_level: level,
      bloom_level: bloomHint,
    }));
  }

  async function uploadQuestionImage(file: File, target: { type: 'stem' } | { type: 'option'; index: number }) {
    if (!selected) return;
    const payload = new FormData();
    payload.append('file', file);
    setUploadingImage(target.type === 'stem' ? 'stem' : `option-${target.index}`);

    try {
      const { data } = await api.post(`/admin/question-banks/${selected.id}/media`, payload, { headers: { 'Content-Type': 'multipart/form-data' } });

      if (target.type === 'stem') {
        setQuestionForm((current) => ({ ...current, stem_image: data.url }));
      } else {
        updateOption(target.index, { image: data.url });
      }

      setMessage(`Gambar ${data.file_name ?? ''} siap dipakai.`);
    } finally {
      setUploadingImage('');
    }
  }

  function updateOption(index: number, patch: Partial<QuestionForm['options'][number]>) {
    setQuestionForm((current) => ({
      ...current,
      options: current.options.map((option, optionIndex) => {
        if (optionIndex !== index) return option;
        return { ...option, ...patch };
      }),
    }));
  }

  function toggleCorrect(index: number, checked: boolean) {
    setQuestionForm((current) => ({
      ...current,
      options: current.options.map((option, optionIndex) => ({
        ...option,
        is_correct: current.type === 'objective' ? optionIndex === index && checked : optionIndex === index ? checked : option.is_correct,
      })),
    }));
  }

  function addOption() {
    setQuestionForm((current) => ({
      ...current,
      options: [
        ...current.options,
        { external_id: String.fromCharCode(65 + current.options.length), text: '', image: '', is_correct: false, marks: '0' },
      ],
    }));
  }

  function removeOption(index: number) {
    setQuestionForm((current) => ({
      ...current,
      options: current.options.filter((_, optionIndex) => optionIndex !== index),
    }));
  }

  async function saveQuestion(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    const payload = formToPayload(questionForm);

    if (questionForm.item_id) {
      await api.put(`/admin/question-bank-items/${questionForm.item_id}`, payload);
      setMessage(`Soal ${payload.external_id} diperbarui.`);
    } else {
      await api.post(`/admin/question-banks/${selected.id}/questions`, payload);
      setMessage(`Soal ${payload.external_id} ditambahkan.`);
    }

    resetQuestionForm();
    await loadBank(selected.id);
  }

  async function deleteQuestion(item: QuestionBankItem) {
    if (!window.confirm(`Hapus soal ${item.external_id}?`)) return;
    await api.delete(`/admin/question-bank-items/${item.id}`);
    setMessage(`Soal ${item.external_id} dihapus.`);
    if (selected) await loadBank(selected.id);
  }

  async function importQuestions(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    const { data } = await api.post(`/admin/question-banks/${selected.id}/import`, JSON.parse(importJson));
    setMessage(`Import selesai: ${data.created} baru, ${data.updated} update.`);
    await loadBank(selected.id);
  }

  async function importQuestionFile(event: FormEvent) {
    event.preventDefault();
    if (!selected || !importFile) return;
    const payload = new FormData();
    payload.append('file', importFile);
    payload.append('mode', 'upsert');
    const { data } = await api.post(`/admin/question-banks/${selected.id}/import-file`, payload, { headers: { 'Content-Type': 'multipart/form-data' } });
    setMessage(`File import selesai: ${data.created} baru, ${data.updated} update.`);
    setImportFile(null);
    await loadBank(selected.id);
  }

  async function buildPackage(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    const { data } = await api.post(`/admin/question-banks/${selected.id}/build-package`, {
      exam_paper_id: Number(buildForm.exam_paper_id),
      question_count: Number(buildForm.question_count),
      difficulty_mix: { easy: 4, medium: 4, hard: 2 },
      shuffle_questions: true,
      shuffle_options: true,
      strict_mode: true,
    });
    if (buildForm.publish_session_id) {
      await api.post(`/admin/exam-packages/${data.id}/publish-session`, {
        exam_session_id: Number(buildForm.publish_session_id),
        status: 'active',
      });
    }
    setMessage(`Package #${data.id} dibuat dari ${data.questions.length} soal${buildForm.publish_session_id ? ` dan dipublish ke session #${buildForm.publish_session_id}` : ''}.`);
  }

  if (bankId) {
    return (
      <div>
        <PageHeader
          title={selected ? selected.title : 'Question Bank'}
          eyebrow={selected ? `${selected.code} / ${selected.subject ?? 'No subject'}` : 'Loading bank'}
          actions={<Link className="secondary" to="/admin/question-banks"><ArrowLeft size={18} /> Back to banks</Link>}
        />
        {message ? <p className="success">{message}</p> : null}
        {selected ? (
          <div className="stack">
            <div className="bank-detail-head">
              <div className="stat"><span>Questions</span><strong>{selected.items?.length ?? selected.items_count ?? 0}</strong></div>
              <div className="stat"><span>Status</span><strong>{selected.status}</strong></div>
              <div className="stat"><span>Level</span><strong>{selected.level ?? '-'}</strong></div>
            </div>

            <div className="tab-strip">
              <button className={activeTab === 'questions' ? 'active' : ''} onClick={() => setActiveTab('questions')}><ListChecks size={18} /> Kelola Soal</button>
              <button className={activeTab === 'form' ? 'active' : ''} onClick={() => { resetQuestionForm(); setActiveTab('form'); }}><Plus size={18} /> Tambah Soal</button>
              <button className={activeTab === 'import' ? 'active' : ''} onClick={() => setActiveTab('import')}><Braces size={18} /> Import & Paket</button>
            </div>

            {activeTab === 'questions' ? (
              <div className="question-bank-workspace single">
                <section>
                  <div className="section-title">
                    <h2>Isi bank soal</h2>
                    <button className="secondary compact-button" onClick={() => { resetQuestionForm(); setActiveTab('form'); }}><Plus size={16} /> Soal baru</button>
                  </div>
                  <DataTable
                    rows={selected.items ?? []}
                    rowKey={(item) => item.id}
                    searchPlaceholder="Cari soal, topik, tipe..."
                    initialSort={{ key: 'id' }}
                    columns={[
                      { key: 'id', header: 'ID', accessor: (item) => item.external_id },
                      { key: 'question', header: 'Pertanyaan', accessor: (item) => item.stem?.text ?? '', render: (item) => <span className="question-preview">{item.stem?.image ? <span className="media-badge">Gambar</span> : null}{item.stem?.text ?? '-'}</span> },
                      { key: 'type', header: 'Tipe', accessor: (item) => questionFormatLabel(item) },
                      { key: 'difficulty', header: 'Level', accessor: (item) => item.difficulty },
                      { key: 'cognitive', header: 'Evaluasi', accessor: (item) => cognitiveLabel(item) },
                      { key: 'topic', header: 'Topik', accessor: (item) => item.topic ?? '-' },
                      { key: 'marks', header: 'Nilai', accessor: (item) => item.max_marks },
                      {
                        key: 'actions',
                        header: 'Aksi',
                        sortable: false,
                        searchable: false,
                        render: (item) => (
                          <div className="inline-actions">
                            <button onClick={() => editQuestion(item)}><Pencil size={15} /> Edit</button>
                            <button onClick={() => void deleteQuestion(item)}><Trash2 size={15} /> Hapus</button>
                          </div>
                        ),
                      },
                    ]}
                  />
                </section>
              </div>
            ) : null}

            {activeTab === 'form' ? (
              <div className="question-bank-workspace single">
                <section>
                  <div className="section-title">
                    <h2>{selectedItem ? `Edit ${selectedItem.external_id}` : 'Tambah soal'}</h2>
                  </div>
                  <form className="question-editor" onSubmit={saveQuestion}>
                    <div className="evaluation-picker">
                      {questionFormats.map((format) => (
                        <button
                          key={format.value}
                          type="button"
                          className={questionForm.question_format === format.value ? 'evaluation-card active' : 'evaluation-card'}
                          onClick={() => applyQuestionFormat(format.value)}
                        >
                          <strong>{format.label}</strong>
                          <span>{format.description}</span>
                        </button>
                      ))}
                    </div>
                    <div className="form-grid">
                      <label>Kode soal<input value={questionForm.external_id} onChange={(event) => setQuestionForm({ ...questionForm, external_id: event.target.value })} placeholder="Q-001" /></label>
                      <label>Format soal<select value={questionForm.question_format} onChange={(event) => applyQuestionFormat(event.target.value as QuestionFormat)}>{questionFormats.map((format) => <option key={format.value} value={format.value}>{format.label}</option>)}</select></label>
                      <label>Kesulitan<select value={questionForm.difficulty} onChange={(event) => setQuestionForm({ ...questionForm, difficulty: event.target.value })}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></label>
                      <label>Level evaluasi<select value={questionForm.cognitive_level} onChange={(event) => updateCognitiveLevel(event.target.value as CognitiveLevel)}>{cognitiveLevels.map((level) => <option key={level.value} value={level.value}>{level.label}</option>)}</select></label>
                      <label>Bloom<select value={questionForm.bloom_level} onChange={(event) => setQuestionForm({ ...questionForm, bloom_level: event.target.value as BloomLevel })}>{bloomLevels.map((level) => <option key={level.value} value={level.value}>{level.label}</option>)}</select></label>
                      <label>Nilai maksimal<input value={questionForm.max_marks} onChange={(event) => setQuestionForm({ ...questionForm, max_marks: event.target.value })} /></label>
                      <label>Topik<input value={questionForm.topic} onChange={(event) => setQuestionForm({ ...questionForm, topic: event.target.value })} placeholder="Measurements" /></label>
                      <label>Gambar soal URL<input value={questionForm.stem_image} onChange={(event) => setQuestionForm({ ...questionForm, stem_image: event.target.value })} placeholder="/imported-media/example.png" /></label>
                      <label>Upload gambar soal<input className="file-field" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" disabled={uploadingImage === 'stem'} onChange={(event) => { const file = event.target.files?.[0]; if (file) void uploadQuestionImage(file, { type: 'stem' }); event.currentTarget.value = ''; }} /></label>
                    </div>
                    {questionForm.stem_image ? (
                      <figure className="question-media-preview">
                        <img src={questionForm.stem_image} alt="Preview soal" />
                        <figcaption>{questionForm.stem_image}</figcaption>
                      </figure>
                    ) : null}
                    <label>Pertanyaan<textarea value={questionForm.stem_text} onChange={(event) => setQuestionForm({ ...questionForm, stem_text: event.target.value })} placeholder="Tulis pertanyaan untuk siswa..." /></label>
                    <label>Math / LaTeX<input value={questionForm.stem_math} onChange={(event) => setQuestionForm({ ...questionForm, stem_math: event.target.value })} placeholder="Opsional, contoh: E = mc^2" /></label>

                    {(questionForm.type === 'objective' || questionForm.type === 'checkbox') ? (
                      <div className="form-section">
                        <div className="section-title">
                          <h3>Pilihan jawaban</h3>
                          <button type="button" className="secondary compact-button" onClick={addOption}>Tambah opsi</button>
                        </div>
                        <div className="option-editor">
                          {questionForm.options.map((option, index) => (
                            <div className="option-row" key={`${option.external_id}-${index}`}>
                              <label>Kode<input value={option.external_id} onChange={(event) => updateOption(index, { external_id: event.target.value })} /></label>
                              <label>Jawaban<input value={option.text} onChange={(event) => updateOption(index, { text: event.target.value })} /></label>
                              <label>Gambar opsi<input value={option.image} onChange={(event) => updateOption(index, { image: event.target.value })} /></label>
                              <label>Upload gambar<input className="file-field" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" disabled={uploadingImage === `option-${index}`} onChange={(event) => { const file = event.target.files?.[0]; if (file) void uploadQuestionImage(file, { type: 'option', index }); event.currentTarget.value = ''; }} /></label>
                              <label>Nilai<input value={option.marks} onChange={(event) => updateOption(index, { marks: event.target.value })} /></label>
                              <label className="inline-check"><input type={questionForm.type === 'objective' ? 'radio' : 'checkbox'} name="correct-option" checked={option.is_correct} onChange={(event) => toggleCorrect(index, event.target.checked)} /> Benar</label>
                              <button type="button" onClick={() => removeOption(index)}>Hapus</button>
                              {option.image ? <img className="option-image-thumb" src={option.image} alt={`Preview opsi ${option.external_id}`} /> : null}
                            </div>
                          ))}
                        </div>
                      </div>
                    ) : null}

                    {questionForm.type === 'numerical' ? (
                      <div className="form-grid">
                        <label>Jawaban angka<input value={questionForm.numerical_answer} onChange={(event) => setQuestionForm({ ...questionForm, numerical_answer: event.target.value })} placeholder="0.124" /></label>
                        <label>Toleransi<input value={questionForm.tolerance} onChange={(event) => setQuestionForm({ ...questionForm, tolerance: event.target.value })} placeholder="0.001" /></label>
                      </div>
                    ) : null}

                    {(questionForm.type === 'essay' || questionForm.type === 'structured') ? (
                      <div className="form-section">
                        <div className="form-grid">
                          <label>Batas karakter<input value={questionForm.max_length} onChange={(event) => setQuestionForm({ ...questionForm, max_length: event.target.value })} /></label>
                          <label>Kriteria rubrik<input value={questionForm.rubric_criterion} onChange={(event) => setQuestionForm({ ...questionForm, rubric_criterion: event.target.value })} /></label>
                        </div>
                        <label>Deskripsi nilai tinggi<textarea value={questionForm.rubric_high} onChange={(event) => setQuestionForm({ ...questionForm, rubric_high: event.target.value })} /></label>
                        <label>Deskripsi nilai sedang<textarea value={questionForm.rubric_mid} onChange={(event) => setQuestionForm({ ...questionForm, rubric_mid: event.target.value })} /></label>
                        <label>Deskripsi nilai rendah<textarea value={questionForm.rubric_low} onChange={(event) => setQuestionForm({ ...questionForm, rubric_low: event.target.value })} /></label>
                      </div>
                    ) : null}

                    <div className="toolbar">
                      <button className="primary">{questionForm.item_id ? 'Update soal' : 'Simpan soal'}</button>
                      {questionForm.item_id ? <button type="button" className="secondary" onClick={resetQuestionForm}>Batal edit</button> : null}
                    </div>
                  </form>
                </section>
              </div>
            ) : null}

            {activeTab === 'import' ? (
              <div className="question-bank-workspace">
                <section>
                  <div className="section-title"><h2>Import file</h2><FileUp size={20} /></div>
                  <form className="toolbar" onSubmit={importQuestionFile}>
                    <input type="file" accept=".json,.csv,.tsv,.txt,.xlsx" onChange={(event) => setImportFile(event.target.files?.[0] ?? null)} />
                    <button className="secondary"><Upload size={18} /> Import file</button>
                  </form>
                  <form className="stack" onSubmit={importQuestions}>
                    <label>Import JSON advanced<textarea className="json-box compact" value={importJson} onChange={(event) => setImportJson(event.target.value)} /></label>
                    <button className="secondary"><Braces size={18} /> Import JSON payload</button>
                  </form>
                </section>
                <section>
                  <div className="section-title"><h2>Buat paket soal</h2><PackagePlus size={20} /></div>
                  <form className="question-editor" onSubmit={buildPackage}>
                    <div className="form-grid">
                      <label>Exam paper ID<input placeholder="Contoh: 1" value={buildForm.exam_paper_id} onChange={(event) => setBuildForm({ ...buildForm, exam_paper_id: event.target.value })} /></label>
                      <label>Jumlah soal<input placeholder="10" value={buildForm.question_count} onChange={(event) => setBuildForm({ ...buildForm, question_count: event.target.value })} /></label>
                      <label>Publish ke session ID<input placeholder="Opsional" value={buildForm.publish_session_id} onChange={(event) => setBuildForm({ ...buildForm, publish_session_id: event.target.value })} /></label>
                    </div>
                    <button className="primary"><PackagePlus size={18} /> Build randomized package</button>
                  </form>
                </section>
              </div>
            ) : null}
          </div>
        ) : <p className="muted">Loading question bank...</p>}
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="Question Bank" eyebrow="Authoring and package builder" />
      {message ? <p className="success">{message}</p> : null}
      <div className="split">
        <section>
          <form className="toolbar" onSubmit={createBank}>
            <input placeholder="Code" value={bankForm.code} onChange={(event) => setBankForm({ ...bankForm, code: event.target.value })} />
            <input placeholder="Title" value={bankForm.title} onChange={(event) => setBankForm({ ...bankForm, title: event.target.value })} />
            <input placeholder="Subject" value={bankForm.subject} onChange={(event) => setBankForm({ ...bankForm, subject: event.target.value })} />
            <button className="primary"><Plus size={18} /> Create</button>
          </form>
          <DataTable
            rows={banks}
            rowKey={(bank) => bank.id}
            searchPlaceholder="Search bank..."
            initialSort={{ key: 'code' }}
            columns={[
              { key: 'code', header: 'Code', accessor: (bank) => bank.code },
              { key: 'title', header: 'Title', accessor: (bank) => bank.title },
              { key: 'subject', header: 'Subject', accessor: (bank) => bank.subject ?? '-' },
              { key: 'items', header: 'Items', accessor: (bank) => bank.items_count ?? 0 },
              { key: 'actions', header: '', sortable: false, searchable: false, render: (bank) => <Link className="secondary compact-button" to={`/admin/question-banks/${bank.id}`}>Open</Link> },
            ]}
          />
        </section>
        <section>
          <h2>Alur kerja bank soal</h2>
          <div className="helper-panel">
            <strong>1. Buat atau buka bank soal</strong>
            <span>Kelola soal dengan form HTML supaya tidak perlu menulis JSON.</span>
            <strong>2. Tambah soal sesuai tipe</strong>
            <span>Pilih format soal, level Easy/Medium/Hard, LOTS/MOTS/HOTS, Bloom, lalu isi teks atau gambar.</span>
            <strong>3. Build package</strong>
            <span>Package bisa diacak soal dan jawabannya lalu dipublish ke exam session.</span>
          </div>
        </section>
      </div>
    </div>
  );
}
