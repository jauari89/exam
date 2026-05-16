import { ReactNode, useMemo, useState } from 'react';
import { ArrowDownUp, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Search } from 'lucide-react';

type SortDirection = 'asc' | 'desc';

export type DataTableColumn<T> = {
  key: string;
  header: ReactNode;
  accessor?: (row: T) => unknown;
  render?: (row: T) => ReactNode;
  sortValue?: (row: T) => unknown;
  searchValue?: (row: T) => unknown;
  sortable?: boolean;
  searchable?: boolean;
  className?: string;
};

type DataTableProps<T> = {
  rows: T[];
  columns: DataTableColumn<T>[];
  rowKey: (row: T, index: number) => string | number;
  searchPlaceholder?: string;
  emptyText?: string;
  initialSort?: { key: string; direction?: SortDirection };
  pageSize?: number;
};

function valueText(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (Array.isArray(value)) return value.map(valueText).join(' ');
  if (value instanceof Date) return value.toISOString();
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function comparable(value: unknown): string | number {
  const text = valueText(value).trim();
  if (!text) return '';

  const numeric = Number(text.replace('%', ''));
  if (Number.isFinite(numeric) && /^-?\d+(\.\d+)?%?$/.test(text)) {
    return numeric;
  }

  const timestamp = Date.parse(text);
  if (Number.isFinite(timestamp) && /\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4}/.test(text)) {
    return timestamp;
  }

  return text.toLowerCase();
}

function firstSortableKey<T>(columns: DataTableColumn<T>[]): string | null {
  return columns.find((column) => column.sortable !== false)?.key ?? null;
}

export function DataTable<T>({
  rows,
  columns,
  rowKey,
  searchPlaceholder = 'Search table...',
  emptyText = 'No data found.',
  initialSort,
  pageSize = 10,
}: DataTableProps<T>) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ key: string; direction: SortDirection } | null>(
    initialSort
      ? { key: initialSort.key, direction: initialSort.direction ?? 'asc' }
      : firstSortableKey(columns)
        ? { key: firstSortableKey(columns) as string, direction: 'asc' }
        : null,
  );

  const visibleRows = useMemo(() => {
    const query = search.trim().toLowerCase();
    const searchableColumns = columns.filter((column) => column.searchable !== false);
    const filtered = query
      ? rows.filter((row) => searchableColumns.some((column) => {
        const value = column.searchValue?.(row) ?? column.accessor?.(row);
        return valueText(value).toLowerCase().includes(query);
      }))
      : [...rows];

    if (!sort) return filtered;

    const column = columns.find((item) => item.key === sort.key);
    if (!column) return filtered;

    return filtered
      .map((row, index) => ({ row, index }))
      .sort((left, right) => {
        const leftValue = comparable(column.sortValue?.(left.row) ?? column.accessor?.(left.row));
        const rightValue = comparable(column.sortValue?.(right.row) ?? column.accessor?.(right.row));
        let result = 0;

        if (typeof leftValue === 'number' && typeof rightValue === 'number') {
          result = leftValue - rightValue;
        } else {
          result = String(leftValue).localeCompare(String(rightValue), 'id-ID', { numeric: true, sensitivity: 'base' });
        }

        if (result === 0) result = left.index - right.index;

        return sort.direction === 'asc' ? result : -result;
      })
      .map((item) => item.row);
  }, [columns, rows, search, sort]);
  const totalPages = Math.max(1, Math.ceil(visibleRows.length / pageSize));
  const currentPage = Math.min(page, totalPages);
  const pageRows = visibleRows.slice((currentPage - 1) * pageSize, currentPage * pageSize);
  const firstRow = visibleRows.length ? (currentPage - 1) * pageSize + 1 : 0;
  const lastRow = Math.min(currentPage * pageSize, visibleRows.length);

  function toggleSort(column: DataTableColumn<T>) {
    if (column.sortable === false) return;
    setPage(1);
    setSort((current) => {
      if (current?.key !== column.key) return { key: column.key, direction: 'asc' };
      if (current.direction === 'asc') return { key: column.key, direction: 'desc' };
      return { key: column.key, direction: 'asc' };
    });
  }

  return (
    <div className="table-shell">
      <div className="table-tools">
        <label className="table-search">
          <Search size={16} />
          <input value={search} placeholder={searchPlaceholder} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
        </label>
        <span>{firstRow}-{lastRow} / {visibleRows.length} rows</span>
      </div>
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((column) => {
              const active = sort?.key === column.key;
              const SortIcon = active ? (sort?.direction === 'asc' ? ChevronUp : ChevronDown) : ArrowDownUp;

              return (
                <th key={column.key} className={column.className}>
                  {column.sortable === false ? (
                    <span>{column.header}</span>
                  ) : (
                    <button type="button" className={`sort-button ${active ? 'active' : ''}`} onClick={() => toggleSort(column)}>
                      <span>{column.header}</span>
                      <SortIcon size={14} />
                    </button>
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {pageRows.map((row, index) => (
            <tr key={rowKey(row, (currentPage - 1) * pageSize + index)}>
              {columns.map((column) => (
                <td key={column.key} className={column.className}>
                  {column.render ? column.render(row) : valueText(column.accessor?.(row))}
                </td>
              ))}
            </tr>
          ))}
          {!visibleRows.length ? (
            <tr>
              <td colSpan={columns.length} className="empty-table">{emptyText}</td>
            </tr>
          ) : null}
        </tbody>
      </table>
      <div className="table-pagination">
        <span>Page {currentPage} of {totalPages} / 10 per page</span>
        <div>
          <button type="button" disabled={currentPage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}><ChevronLeft size={16} /> Prev</button>
          <button type="button" disabled={currentPage >= totalPages} onClick={() => setPage((value) => Math.min(totalPages, value + 1))}>Next <ChevronRight size={16} /></button>
        </div>
      </div>
    </div>
  );
}
