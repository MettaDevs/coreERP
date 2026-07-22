import * as React from "react"
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react"

import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuLabel,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from "@/components/ui/context-menu"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { cn } from "@/lib/utils"

type SortValue = string | number
type SortDirection = "asc" | "desc"

type DataTableColumn<T> = {
  id: string
  header: string
  cell: (row: T) => React.ReactNode
  sortValue?: (row: T) => SortValue
  width?: number
  minWidth?: number
  align?: "left" | "center" | "right"
}

type DataTableRowAction = {
  id: string
  label: string
  destructive?: boolean
  separatorBefore?: boolean
}

type DataTableProps<T> = {
  columns: DataTableColumn<T>[]
  data: T[]
  getRowKey: (row: T) => React.Key
  actions?: DataTableRowAction[]
  getRowLabel?: (row: T) => string
  onRowAction?: (actionId: string, row: T) => void
  emptyMessage?: string
  showRowNumbers?: boolean
  className?: string
}

function DataTable<T>({
  columns,
  data,
  getRowKey,
  actions = [],
  getRowLabel,
  onRowAction,
  emptyMessage = "No results.",
  showRowNumbers = true,
  className,
}: DataTableProps<T>) {
  const [sort, setSort] = React.useState<{
    columnId: string
    direction: SortDirection
  } | null>(null)
  const [widths, setWidths] = React.useState<Record<string, number>>(() =>
    Object.fromEntries(
      columns.map((column) => [
        column.id,
        Math.max(column.width ?? 160, column.minWidth ?? 80),
      ])
    )
  )
  const tableRef = React.useRef<HTMLTableElement | null>(null)
  const resize = React.useRef<{
    columnId: string
    nextColumnId: string
    startX: number
    startWidth: number
    nextStartWidth: number
    minWidth: number
    nextMinWidth: number
  } | null>(null)

  const measureWidths = () => {
    const headerCells = tableRef.current?.querySelectorAll("thead th")
    const offset = showRowNumbers ? 1 : 0
    const measured = Object.fromEntries(
      columns.map((column, index) => [
        column.id,
        Math.max(
          headerCells?.[index + offset]?.getBoundingClientRect().width ??
            widths[column.id] ??
            column.width ??
            160,
          column.minWidth ?? 80
        ),
      ])
    )

    setWidths(measured)
    return measured
  }

  const resizePair = (
    current: NonNullable<typeof resize.current>,
    delta: number
  ) => {
    const pairWidth = current.startWidth + current.nextStartWidth
    const currentWidth = Math.min(
      Math.max(current.startWidth + delta, current.minWidth),
      pairWidth - current.nextMinWidth
    )

    setWidths((value) => ({
      ...value,
      [current.columnId]: currentWidth,
      [current.nextColumnId]: pairWidth - currentWidth,
    }))
  }

  const sortedData = React.useMemo(() => {
    if (!sort) return data

    const column = columns.find((item) => item.id === sort.columnId)
    if (!column?.sortValue) return data

    return [...data].sort((left, right) => {
      const a = column.sortValue!(left)
      const b = column.sortValue!(right)
      const result =
        typeof a === "number" && typeof b === "number"
          ? a - b
          : String(a).localeCompare(String(b), undefined, {
              numeric: true,
              sensitivity: "base",
            })

      return sort.direction === "asc" ? result : -result
    })
  }, [columns, data, sort])

  const toggleSort = (columnId: string) => {
    setSort((current) => ({
      columnId,
      direction:
        current?.columnId === columnId && current.direction === "asc"
          ? "desc"
          : "asc",
    }))
  }

  const renderRow = (row: T, index: number) => (
    <TableRow
      key={getRowKey(row)}
      className={cn(
        "border-0 hover:bg-accent/40",
        actions.length && "cursor-context-menu"
      )}
    >
      {showRowNumbers && (
        <TableCell className="h-9 px-2 text-center text-xs tabular-nums text-muted-foreground">
          {index + 1}
        </TableCell>
      )}
      {columns.map((column) => (
        <TableCell
          key={column.id}
          className={cn(
            "h-9 overflow-hidden px-3 text-ellipsis",
            column.align === "center" && "text-center",
            column.align === "right" && "text-right"
          )}
        >
          {column.cell(row)}
        </TableCell>
      ))}
    </TableRow>
  )

  return (
    <div className={cn("overflow-hidden rounded-md border bg-background", className)}>
      <Table
        ref={tableRef}
        className="table-fixed border-separate border-spacing-0"
        style={{
          width: "100%",
          minWidth: columns.reduce(
            (total, column) => total + (widths[column.id] ?? column.width ?? 160),
            showRowNumbers ? 44 : 0
          ),
        }}
      >
        <colgroup>
          {showRowNumbers && <col style={{ width: 44 }} />}
          {columns.map((column) => (
            <col
              key={column.id}
              style={{ width: widths[column.id] ?? column.width ?? 160 }}
            />
          ))}
        </colgroup>
        <TableHeader className="[&_tr]:border-0">
          <TableRow className="border-0 hover:bg-transparent">
            {showRowNumbers && (
              <TableHead className="h-9 border-r border-b bg-muted/70 px-2 text-center text-xs text-muted-foreground">
                #
              </TableHead>
            )}
            {columns.map((column, columnIndex) => {
              const nextColumn = columns[columnIndex + 1]
              const isSorted = sort?.columnId === column.id
              const SortIcon = !isSorted
                ? ArrowUpDown
                : sort.direction === "asc"
                  ? ArrowUp
                  : ArrowDown

              return (
                <TableHead
                  key={column.id}
                  aria-sort={
                    isSorted
                      ? sort.direction === "asc"
                        ? "ascending"
                        : "descending"
                      : undefined
                  }
                  className={cn(
                    "group relative h-9 border-r border-b bg-muted/70 px-3 font-semibold select-none last:border-r-0",
                    column.align === "center" && "text-center",
                    column.align === "right" && "text-right"
                  )}
                >
                  {column.sortValue ? (
                    <button
                      type="button"
                      className={cn(
                        "inline-flex w-full items-center gap-2 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring",
                        column.align === "center" && "justify-center",
                        column.align === "right" && "justify-end"
                      )}
                      onClick={() => toggleSort(column.id)}
                      aria-label={`Sort by ${column.header}`}
                    >
                      {column.header}
                      <SortIcon
                        className={cn(
                          "size-3.5 text-muted-foreground transition-opacity",
                          !isSorted &&
                            "opacity-0 group-hover:opacity-60 group-focus-within:opacity-60"
                        )}
                      />
                    </button>
                  ) : (
                    column.header
                  )}
                  {nextColumn && (
                    <button
                      type="button"
                      aria-label={`Resize divider between ${column.header} and ${nextColumn.header}`}
                      className="absolute inset-y-0 -right-1 z-10 w-2 cursor-col-resize touch-none opacity-0 outline-none group-hover:opacity-100 focus-visible:opacity-100 after:absolute after:inset-y-0 after:left-1/2 after:w-0.5 after:bg-primary"
                      onPointerDown={(event) => {
                        event.preventDefault()
                        event.stopPropagation()
                        event.currentTarget.setPointerCapture(event.pointerId)
                        const measured = measureWidths()
                        resize.current = {
                          columnId: column.id,
                          nextColumnId: nextColumn.id,
                          startX: event.clientX,
                          startWidth: measured[column.id],
                          nextStartWidth: measured[nextColumn.id],
                          minWidth: column.minWidth ?? 80,
                          nextMinWidth: nextColumn.minWidth ?? 80,
                        }
                      }}
                      onPointerMove={(event) => {
                        const current = resize.current
                        if (!current || current.columnId !== column.id) return
                        resizePair(current, event.clientX - current.startX)
                      }}
                      onPointerUp={() => {
                        resize.current = null
                      }}
                      onPointerCancel={() => {
                        resize.current = null
                      }}
                      onKeyDown={(event) => {
                        if (
                          event.key !== "ArrowLeft" &&
                          event.key !== "ArrowRight"
                        )
                          return
                        event.preventDefault()
                        const measured = measureWidths()
                        resizePair(
                          {
                            columnId: column.id,
                            nextColumnId: nextColumn.id,
                            startX: 0,
                            startWidth: measured[column.id],
                            nextStartWidth: measured[nextColumn.id],
                            minWidth: column.minWidth ?? 80,
                            nextMinWidth: nextColumn.minWidth ?? 80,
                          },
                          (event.key === "ArrowRight" ? 1 : -1) *
                            (event.shiftKey ? 32 : 8)
                        )
                      }}
                    />
                  )}
                </TableHead>
              )
            })}
          </TableRow>
        </TableHeader>
        <TableBody>
          {sortedData.length ? (
            sortedData.map((row, index) => {
              if (!actions.length) return renderRow(row, index)

              return (
                <ContextMenu key={getRowKey(row)}>
                  <ContextMenuTrigger asChild>{renderRow(row, index)}</ContextMenuTrigger>
                  <ContextMenuContent>
                    {getRowLabel && <ContextMenuLabel>{getRowLabel(row)}</ContextMenuLabel>}
                    {getRowLabel && <ContextMenuSeparator />}
                    {actions.map((action) => (
                      <React.Fragment key={action.id}>
                        {action.separatorBefore && <ContextMenuSeparator />}
                        <ContextMenuItem
                          variant={action.destructive ? "destructive" : "default"}
                          onSelect={() => onRowAction?.(action.id, row)}
                        >
                          {action.label}
                        </ContextMenuItem>
                      </React.Fragment>
                    ))}
                  </ContextMenuContent>
                </ContextMenu>
              )
            })
          ) : (
            <TableRow>
              <TableCell
                colSpan={columns.length + (showRowNumbers ? 1 : 0)}
                className="h-24 text-center text-muted-foreground"
              >
                {emptyMessage}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  )
}

export { DataTable }
export type { DataTableColumn, DataTableRowAction }
