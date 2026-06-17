"use client"

/**
 * Bid Evaluation page.
 *
 * Accessible to:
 *   - Procurement_Officer: criteria config + rankings + winner selection
 *   - Tenant_Admin:        criteria config + score entry + rankings + winner selection
 *   - Committee_Member:    score entry + rankings (read-only)
 *
 * Sections:
 *   1. Criteria Configuration Panel  (Procurement_Officer / Tenant_Admin only)
 *   2. Score Entry Grid              (Committee_Member / Tenant_Admin)
 *   3. Ranked Comparison Table + Recharts BarChart (all roles)
 *   4. Winner Selection              (Procurement_Officer / Tenant_Admin only)
 *
 * Validates: Requirements 9.4, 9.5, 22.5
 */

import { use, useState, useMemo, useCallback } from "react"
import { useForm, useFieldArray, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { motion, AnimatePresence } from "framer-motion"
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell,
} from "recharts"
import {
  ArrowLeft,
  Plus,
  Trash2,
  RefreshCw,
  CheckCircle2,
  Trophy,
  AlertCircle,
  ClipboardList,
  BarChart3,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Separator } from "@/components/ui/separator"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  useEvaluationCriteria,
  useConfigureCriteria,
  useSubmitScore,
  useRankings,
  useSelectWinner,
} from "@/hooks/useEvaluation"
import { useTender } from "@/hooks/useTenders"
import { useAuthStore } from "@/store/authStore"
import {
  criteriaConfigSchema,
  winnerJustificationSchema,
  type CriteriaConfigFormData,
  type WinnerJustificationFormData,
} from "@/lib/validations/evaluations"
import { formatCurrency } from "@/lib/utils"
import type { EvaluationCriteria, RankingEntry } from "@/types/evaluation"

// ─── Role constants ───────────────────────────────────────────────────────────

const OFFICER_ROLES = ["Procurement_Officer", "Tenant_Admin"]
const EVALUATOR_ROLES = ["Committee_Member", "Tenant_Admin"]

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

const fadeInDelayed = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { duration: 0.4, delay: 0.1 } },
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatScore(score: string | null): string {
  if (score === null) return "—"
  const n = parseFloat(score)
  return isNaN(n) ? "—" : n.toFixed(2)
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function PageSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <Skeleton className="h-8 w-72" />
      <Skeleton className="h-48 rounded-xl" />
      <Skeleton className="h-64 rounded-xl" />
      <Skeleton className="h-80 rounded-xl" />
    </div>
  )
}

// ─── Section 1: Criteria Configuration Panel ─────────────────────────────────

interface CriteriaPanelProps {
  tenderId: string
  criteria: EvaluationCriteria[]
  isConfigured: boolean
}

function CriteriaPanel({ tenderId, criteria, isConfigured }: CriteriaPanelProps) {
  const [formOpen, setFormOpen] = useState(false)
  const [success, setSuccess] = useState(false)
  const configureMutation = useConfigureCriteria(tenderId)

  const {
    control,
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<CriteriaConfigFormData>({
    resolver: zodResolver(criteriaConfigSchema),
    defaultValues: {
      criteria:
        isConfigured && criteria.length > 0
          ? criteria.map((c) => ({
              name: c.name,
              weight: c.weight,
              description: c.description ?? "",
            }))
          : [{ name: "", weight: 0, description: "" }],
    },
  })

  const { fields, append, remove } = useFieldArray({
    control,
    name: "criteria",
  })

  const watchedCriteria = watch("criteria")
  const totalWeight = useMemo(
    () => watchedCriteria.reduce((sum, c) => sum + (Number(c.weight) || 0), 0),
    [watchedCriteria],
  )
  const weightOk = Math.abs(totalWeight - 100) < 0.01

  const onSubmit = handleSubmit(async (data) => {
    setSuccess(false)
    try {
      await configureMutation.mutateAsync({
        criteria: data.criteria.map((c) => ({
          name: c.name,
          weight: c.weight,
          description: c.description,
        })),
      })
      setSuccess(true)
      setFormOpen(false)
    } catch {
      // error surfaced via mutation state
    }
  })

  const mutationErrorMessage = (
    configureMutation.error as { response?: { data?: { message?: string } } }
  )?.response?.data?.message

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between gap-2 flex-wrap">
          <CardTitle className="flex items-center gap-2 text-base">
            <ClipboardList className="size-4" aria-hidden="true" />
            Evaluation Criteria
          </CardTitle>
          {!formOpen && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => { setSuccess(false); setFormOpen(true) }}
              aria-label="Configure evaluation criteria"
            >
              {isConfigured ? "Reconfigure Criteria" : "Configure Criteria"}
            </Button>
          )}
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Success banner */}
        {success && (
          <Alert className="border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30" role="status">
            <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
            <AlertDescription className="text-emerald-700 dark:text-emerald-300">
              Criteria configured successfully.
            </AlertDescription>
          </Alert>
        )}

        {/* Existing criteria table */}
        {!formOpen && criteria.length > 0 && (
          <div className="rounded-lg border border-border overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Criterion</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right w-24">Weight (%)</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {criteria.map((c) => (
                  <TableRow key={c.id}>
                    <TableCell className="font-medium text-sm">{c.name}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {c.description ?? "—"}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm font-semibold">
                      {c.weight}%
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        {!formOpen && criteria.length === 0 && (
          <p className="text-sm text-muted-foreground">
            No criteria configured yet. Click &ldquo;Configure Criteria&rdquo; to define the
            weighted scoring model.
          </p>
        )}

        {/* Configuration form */}
        {formOpen && (
          <form onSubmit={onSubmit} noValidate className="space-y-4">
            {/* Weight sum indicator */}
            <div
              className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${
                weightOk
                  ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"
                  : "bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300"
              }`}
              role="status"
              aria-live="polite"
            >
              {weightOk ? (
                <CheckCircle2 className="size-4 shrink-0" aria-hidden="true" />
              ) : (
                <AlertCircle className="size-4 shrink-0" aria-hidden="true" />
              )}
              Total weight: {totalWeight.toFixed(0)}% {!weightOk && "(must equal 100%)"}
            </div>

            {/* Root-level criteria array error */}
            {errors.criteria && !Array.isArray(errors.criteria) && (
              <p role="alert" className="text-xs text-destructive">
                {errors.criteria.message}
              </p>
            )}

            {/* Criteria rows */}
            <div className="space-y-3">
              {fields.map((field, index) => (
                <div
                  key={field.id}
                  className="grid gap-3 rounded-lg border border-border p-3"
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                      Criterion {index + 1}
                    </span>
                    {fields.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7 text-muted-foreground hover:text-destructive"
                        onClick={() => remove(index)}
                        aria-label={`Remove criterion ${index + 1}`}
                      >
                        <Trash2 className="size-3.5" aria-hidden="true" />
                      </Button>
                    )}
                  </div>

                  <div className="grid gap-3 sm:grid-cols-3">
                    {/* Name */}
                    <div className="sm:col-span-2 space-y-1">
                      <Label htmlFor={`criteria-name-${index}`}>
                        Name <span aria-hidden="true" className="text-destructive">*</span>
                      </Label>
                      <Input
                        id={`criteria-name-${index}`}
                        placeholder="e.g. Technical Compliance"
                        aria-invalid={!!errors.criteria?.[index]?.name}
                        aria-describedby={
                          errors.criteria?.[index]?.name
                            ? `criteria-name-error-${index}`
                            : undefined
                        }
                        {...register(`criteria.${index}.name`)}
                      />
                      {errors.criteria?.[index]?.name && (
                        <p
                          id={`criteria-name-error-${index}`}
                          role="alert"
                          className="text-xs text-destructive"
                        >
                          {errors.criteria[index]?.name?.message}
                        </p>
                      )}
                    </div>

                    {/* Weight */}
                    <div className="space-y-1">
                      <Label htmlFor={`criteria-weight-${index}`}>
                        Weight (%) <span aria-hidden="true" className="text-destructive">*</span>
                      </Label>
                      <Controller
                        control={control}
                        name={`criteria.${index}.weight`}
                        render={({ field: f }) => (
                          <Input
                            id={`criteria-weight-${index}`}
                            type="number"
                            min={1}
                            max={100}
                            placeholder="0"
                            aria-invalid={!!errors.criteria?.[index]?.weight}
                            aria-describedby={
                              errors.criteria?.[index]?.weight
                                ? `criteria-weight-error-${index}`
                                : undefined
                            }
                            value={f.value === 0 ? "" : f.value}
                            onChange={(e) =>
                              f.onChange(
                                e.target.value === "" ? 0 : Number(e.target.value),
                              )
                            }
                          />
                        )}
                      />
                      {errors.criteria?.[index]?.weight && (
                        <p
                          id={`criteria-weight-error-${index}`}
                          role="alert"
                          className="text-xs text-destructive"
                        >
                          {errors.criteria[index]?.weight?.message}
                        </p>
                      )}
                    </div>
                  </div>

                  {/* Description */}
                  <div className="space-y-1">
                    <Label htmlFor={`criteria-desc-${index}`}>Description</Label>
                    <Input
                      id={`criteria-desc-${index}`}
                      placeholder="Optional description…"
                      {...register(`criteria.${index}.description`)}
                    />
                  </div>
                </div>
              ))}
            </div>

            {/* Add criterion */}
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => append({ name: "", weight: 0, description: "" })}
              aria-label="Add criterion"
            >
              <Plus className="size-3.5 mr-1" aria-hidden="true" />
              Add Criterion
            </Button>

            {/* Mutation error */}
            {configureMutation.isError && (
              <Alert variant="destructive" role="alert">
                <AlertDescription>
                  {mutationErrorMessage ?? "Failed to configure criteria. Please try again."}
                </AlertDescription>
              </Alert>
            )}

            {/* Actions */}
            <div className="flex items-center gap-2 justify-end pt-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setFormOpen(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                size="sm"
                disabled={configureMutation.isPending || !weightOk}
                aria-label="Save criteria configuration"
              >
                {configureMutation.isPending ? "Saving…" : "Save Criteria"}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}

// ─── Section 2: Score Entry Grid ──────────────────────────────────────────────

interface ScoreGridProps {
  tenderId: string
  bids: RankingEntry[]
  criteria: EvaluationCriteria[]
  evaluationComplete: boolean
}

/** Tracks local scores per (bidId, criteriaId) before submission */
type LocalScores = Record<string, Record<string, string>>
/** Tracks which bids have been submitted */
type SubmittedBids = Record<string, boolean>

function ScoreGrid({ tenderId, bids, criteria, evaluationComplete }: ScoreGridProps) {
  const submitScoreMutation = useSubmitScore(tenderId)

  const [localScores, setLocalScores] = useState<LocalScores>({})
  const [submittedBids, setSubmittedBids] = useState<SubmittedBids>({})
  const [scoreErrors, setScoreErrors] = useState<Record<string, string>>({})

  function getScore(bidId: string, criteriaId: string): string {
    return localScores[bidId]?.[criteriaId] ?? ""
  }

  function setScore(bidId: string, criteriaId: string, value: string) {
    setLocalScores((prev) => ({
      ...prev,
      [bidId]: { ...(prev[bidId] ?? {}), [criteriaId]: value },
    }))
    // Clear error for this bid on any change
    setScoreErrors((prev) => {
      const next = { ...prev }
      delete next[bidId]
      return next
    })
  }

  const handleSubmitRow = useCallback(
    async (bidId: string) => {
      // Validate all scores for this bid
      const missing: string[] = []
      const scorePayloads: { criteria_id: string; score: number }[] = []

      for (const c of criteria) {
        const raw = localScores[bidId]?.[c.id] ?? ""
        const n = Number(raw)
        if (raw === "" || isNaN(n) || n < 0 || n > 100) {
          missing.push(c.name)
        } else {
          scorePayloads.push({ criteria_id: c.id, score: n })
        }
      }

      if (missing.length > 0) {
        setScoreErrors((prev) => ({
          ...prev,
          [bidId]: `Enter valid scores (0–100) for: ${missing.join(", ")}`,
        }))
        return
      }

      try {
        await submitScoreMutation.mutateAsync({
          bidId,
          payload: { scores: scorePayloads },
        })
        setSubmittedBids((prev) => ({ ...prev, [bidId]: true }))
      } catch {
        setScoreErrors((prev) => ({
          ...prev,
          [bidId]: "Failed to submit scores. Please try again.",
        }))
      }
    },
    [criteria, localScores, submitScoreMutation],
  )

  if (criteria.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-sm text-muted-foreground">
            Evaluation criteria have not been configured yet. Scores cannot be
            entered until criteria are defined.
          </p>
        </CardContent>
      </Card>
    )
  }

  if (bids.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-sm text-muted-foreground">No bids to evaluate.</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Score Entry</CardTitle>
        {evaluationComplete && (
          <Alert className="border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30 mt-2" role="status">
            <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
            <AlertDescription className="text-emerald-700 dark:text-emerald-300">
              Evaluation complete — all scores submitted. Rankings are now visible.
            </AlertDescription>
          </Alert>
        )}
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto rounded-lg border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="min-w-[180px]">Supplier</TableHead>
                <TableHead className="min-w-[120px] text-right">Bid Amount</TableHead>
                {criteria.map((c) => (
                  <TableHead key={c.id} className="min-w-[120px] text-center">
                    <span className="block text-xs">{c.name}</span>
                    <span className="block text-xs text-muted-foreground font-normal">
                      ({c.weight}%)
                    </span>
                  </TableHead>
                ))}
                <TableHead className="min-w-[100px]">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {bids.map((bid) => {
                const isSubmitted = submittedBids[bid.bid_id] ?? bid.scores_submitted
                return (
                  <TableRow key={bid.bid_id}>
                    {/* Supplier */}
                    <TableCell>
                      <span className="text-sm font-medium">{bid.supplier_name}</span>
                    </TableCell>

                    {/* Bid amount */}
                    <TableCell className="text-right tabular-nums text-sm">
                      {formatCurrency(bid.total_amount, bid.currency)}
                    </TableCell>

                    {/* Score inputs per criterion */}
                    {criteria.map((c) => (
                      <TableCell key={c.id} className="text-center p-2">
                        {isSubmitted ? (
                          <div className="flex items-center justify-center gap-1 text-emerald-600" aria-label="Score submitted">
                            <CheckCircle2 className="size-4" aria-hidden="true" />
                            <span className="text-xs sr-only">Submitted</span>
                          </div>
                        ) : (
                          <Input
                            type="number"
                            min={0}
                            max={100}
                            step={1}
                            className="w-20 text-center mx-auto h-8 text-sm"
                            placeholder="0–100"
                            value={getScore(bid.bid_id, c.id)}
                            onChange={(e) => setScore(bid.bid_id, c.id, e.target.value)}
                            aria-label={`Score for ${bid.supplier_name} — ${c.name}`}
                          />
                        )}
                      </TableCell>
                    ))}

                    {/* Submit button */}
                    <TableCell>
                      {isSubmitted ? (
                        <Badge
                          variant="outline"
                          className="text-xs border-emerald-300 text-emerald-700 dark:text-emerald-400"
                        >
                          Submitted
                        </Badge>
                      ) : (
                        <Button
                          size="sm"
                          variant="outline"
                          className="text-xs h-7 px-2"
                          onClick={() => handleSubmitRow(bid.bid_id)}
                          disabled={submitScoreMutation.isPending}
                          aria-label={`Submit scores for ${bid.supplier_name}`}
                        >
                          Submit
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>

        {/* Row-level score errors */}
        {Object.entries(scoreErrors).map(([bidId, msg]) => {
          const bid = bids.find((b) => b.bid_id === bidId)
          return (
            <p key={bidId} role="alert" className="mt-2 text-xs text-destructive">
              {bid?.supplier_name}: {msg}
            </p>
          )
        })}
      </CardContent>
    </Card>
  )
}

// ─── Section 3: Ranked Comparison Table + Chart ───────────────────────────────

interface RankingsProps {
  rankings: RankingEntry[]
  evaluationComplete: boolean
  priceOnlyMode: boolean
  tenderId: string
  isOfficer: boolean
  tenderStatus: string
  onSelectWinner: (bid: RankingEntry) => void
}

function RankingsSection({
  rankings,
  evaluationComplete,
  priceOnlyMode,
  isOfficer,
  tenderStatus,
  onSelectWinner,
}: RankingsProps) {
  // Chart data: use weighted scores when available, otherwise bid amounts
  const chartData = useMemo(() => {
    return rankings.map((r) => ({
      name:
        r.supplier_name.length > 16
          ? r.supplier_name.slice(0, 14) + "…"
          : r.supplier_name,
      value:
        r.weighted_score !== null
          ? parseFloat(r.weighted_score)
          : parseFloat(r.total_amount),
      rank: r.rank,
    }))
  }, [rankings])

  const showChart = evaluationComplete || priceOnlyMode
  const chartLabel = priceOnlyMode ? "Bid Amount" : "Weighted Score"
  const isAwarded = tenderStatus === "awarded"

  const CHART_COLORS = [
    "#10b981", // rank 1 — emerald
    "#3b82f6", // rank 2 — blue
    "#8b5cf6", // rank 3 — violet
    "#f59e0b", // rank 4 — amber
    "#ef4444", // rank 5+ — red
  ]

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center gap-2">
          <BarChart3 className="size-4" aria-hidden="true" />
          <CardTitle className="text-base">Ranked Comparison</CardTitle>
          {!evaluationComplete && !priceOnlyMode && (
            <Badge variant="secondary" className="text-xs ml-1">
              Evaluation Pending
            </Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {rankings.length === 0 ? (
          <p className="text-sm text-muted-foreground">No bids to rank yet.</p>
        ) : (
          <>
            {/* Rankings table */}
            <div className="overflow-x-auto rounded-lg border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-16">Rank</TableHead>
                    <TableHead>Supplier</TableHead>
                    <TableHead className="text-right">Bid Amount</TableHead>
                    <TableHead className="text-right">
                      {priceOnlyMode ? "Price Score" : "Weighted Score"}
                    </TableHead>
                    {isOfficer && !isAwarded && evaluationComplete && (
                      <TableHead className="w-32">Action</TableHead>
                    )}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rankings.map((r) => (
                    <TableRow
                      key={r.bid_id}
                      className={r.is_winner ? "bg-emerald-50/50 dark:bg-emerald-950/20" : ""}
                    >
                      {/* Rank */}
                      <TableCell>
                        <span
                          className={`inline-flex size-7 items-center justify-center rounded-full text-xs font-bold ${
                            r.rank === 1
                              ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300"
                              : "bg-muted text-muted-foreground"
                          }`}
                          aria-label={`Rank ${r.rank}`}
                        >
                          {r.rank}
                        </span>
                      </TableCell>

                      {/* Supplier */}
                      <TableCell>
                        <span className="text-sm font-medium">{r.supplier_name}</span>
                        {r.is_winner && (
                          <Badge
                            className="ml-2 text-xs bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300 border-0"
                            aria-label="Winner"
                          >
                            <Trophy className="size-3 mr-1" aria-hidden="true" />
                            Winner
                          </Badge>
                        )}
                      </TableCell>

                      {/* Bid amount */}
                      <TableCell className="text-right tabular-nums text-sm">
                        {formatCurrency(r.total_amount, r.currency)}
                      </TableCell>

                      {/* Weighted score */}
                      <TableCell className="text-right tabular-nums text-sm">
                        {r.weighted_score !== null ? (
                          <span className="font-semibold">
                            {parseFloat(r.weighted_score).toFixed(2)}
                          </span>
                        ) : (
                          <Badge variant="outline" className="text-xs text-muted-foreground">
                            Pending
                          </Badge>
                        )}
                      </TableCell>

                      {/* Select Winner button — rank #1 only */}
                      {isOfficer && !isAwarded && evaluationComplete && (
                        <TableCell>
                          {r.rank === 1 && !r.is_winner && (
                            <Button
                              size="sm"
                              className="h-7 text-xs px-2"
                              onClick={() => onSelectWinner(r)}
                              aria-label={`Select ${r.supplier_name} as winner`}
                            >
                              <Trophy className="size-3 mr-1" aria-hidden="true" />
                              Select Winner
                            </Button>
                          )}
                        </TableCell>
                      )}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {/* Chart — only shown when evaluation complete or price-only mode */}
            <AnimatePresence>
              {showChart && chartData.length > 0 && (
                <motion.div
                  key="eval-chart"
                  variants={fadeInDelayed}
                  initial="hidden"
                  animate="visible"
                  className="pt-2"
                  aria-label={`${chartLabel} bar chart`}
                >
                  <p className="mb-3 text-sm font-medium text-muted-foreground">
                    {chartLabel} Comparison
                  </p>
                  <div className="h-56">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart
                        data={chartData}
                        layout="vertical"
                        margin={{ top: 4, right: 24, left: 8, bottom: 4 }}
                      >
                        <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                        <XAxis
                          type="number"
                          tick={{ fontSize: 11 }}
                          tickFormatter={(v) =>
                            priceOnlyMode
                              ? Intl.NumberFormat("en", { notation: "compact" }).format(v)
                              : v.toFixed(0)
                          }
                        />
                        <YAxis
                          type="category"
                          dataKey="name"
                          width={100}
                          tick={{ fontSize: 11 }}
                        />
                        <Tooltip
                          formatter={(value: number) =>
                            priceOnlyMode
                              ? [Intl.NumberFormat("en").format(value), "Bid Amount"]
                              : [value.toFixed(2), "Weighted Score"]
                          }
                        />
                        <Bar dataKey="value" radius={[0, 4, 4, 0]}>
                          {chartData.map((entry, index) => (
                            <Cell
                              key={entry.name}
                              fill={CHART_COLORS[Math.min(index, CHART_COLORS.length - 1)]}
                            />
                          ))}
                        </Bar>
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </motion.div>
              )}
            </AnimatePresence>

            {/* Blinding note */}
            {!showChart && (
              <p className="text-xs text-muted-foreground text-center pt-2">
                The comparison chart will be visible once all evaluators have submitted their
                scores.
              </p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}

// ─── Section 4: Winner Selection Modal ───────────────────────────────────────

interface WinnerModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  tenderId: string
  bid: RankingEntry | null
  onSuccess: () => void
}

function WinnerSelectionModal({
  open,
  onOpenChange,
  tenderId,
  bid,
  onSuccess,
}: WinnerModalProps) {
  const selectWinnerMutation = useSelectWinner(tenderId)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<WinnerJustificationFormData>({
    resolver: zodResolver(winnerJustificationSchema),
    defaultValues: { justification: "" },
  })

  const mutationErrorMessage = (
    selectWinnerMutation.error as { response?: { data?: { message?: string } } }
  )?.response?.data?.message

  const onSubmit = handleSubmit(async (data) => {
    if (!bid) return
    try {
      await selectWinnerMutation.mutateAsync({
        bid_id: bid.bid_id,
        justification: data.justification,
      })
      reset()
      onSuccess()
      onOpenChange(false)
    } catch {
      // error surfaced via mutation state
    }
  })

  function handleClose(isOpen: boolean) {
    if (!isOpen) {
      reset()
      selectWinnerMutation.reset()
    }
    onOpenChange(isOpen)
  }

  if (!bid) return null

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Trophy className="size-5 text-emerald-600" aria-hidden="true" />
            Select Winner
          </DialogTitle>
          <DialogDescription>
            Confirm your selection and provide a mandatory justification for the audit log.
          </DialogDescription>
        </DialogHeader>

        {/* Winner summary */}
        <div className="rounded-lg bg-muted/40 p-4 space-y-1.5 text-sm">
          <div className="flex justify-between gap-2">
            <span className="text-muted-foreground">Supplier</span>
            <span className="font-semibold text-right">{bid.supplier_name}</span>
          </div>
          <div className="flex justify-between gap-2">
            <span className="text-muted-foreground">Bid Amount</span>
            <span className="tabular-nums font-semibold text-right">
              {formatCurrency(bid.total_amount, bid.currency)}
            </span>
          </div>
          {bid.weighted_score !== null && (
            <div className="flex justify-between gap-2">
              <span className="text-muted-foreground">Weighted Score</span>
              <span className="tabular-nums font-semibold text-right">
                {formatScore(bid.weighted_score)}
              </span>
            </div>
          )}
        </div>

        <form onSubmit={onSubmit} noValidate id="winner-form" className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="winner-justification">
              Justification{" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="winner-justification"
              rows={4}
              placeholder="Explain the basis for selecting this supplier as the winner…"
              aria-invalid={!!errors.justification}
              aria-describedby={
                errors.justification ? "winner-justification-error" : undefined
              }
              {...register("justification")}
            />
            {errors.justification && (
              <p
                id="winner-justification-error"
                role="alert"
                className="text-xs text-destructive"
              >
                {errors.justification.message}
              </p>
            )}
          </div>

          {selectWinnerMutation.isError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>
                {mutationErrorMessage ??
                  "Failed to select winner. Please try again."}
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            size="sm"
            onClick={() => handleClose(false)}
            type="button"
          >
            Cancel
          </Button>
          <Button
            form="winner-form"
            type="submit"
            size="sm"
            disabled={selectWinnerMutation.isPending}
            aria-label="Confirm winner selection"
          >
            {selectWinnerMutation.isPending ? "Confirming…" : "Confirm Selection"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function BidEvaluationPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id: tenderId } = use(params)
  const role = useAuthStore((s) => s.role)

  const isOfficer = role !== null && OFFICER_ROLES.includes(role)
  const isEvaluator = role !== null && EVALUATOR_ROLES.includes(role)

  // Data queries
  const tenderQuery = useTender(tenderId)
  const criteriaQuery = useEvaluationCriteria(tenderId)
  const rankingsQuery = useRankings(tenderId)

  const [winnerModalOpen, setWinnerModalOpen] = useState(false)
  const [selectedBid, setSelectedBid] = useState<RankingEntry | null>(null)
  const [winnerSuccess, setWinnerSuccess] = useState(false)

  const tender = tenderQuery.data?.data
  const criteria = criteriaQuery.data?.data?.data ?? []
  const priceOnlyMode = criteriaQuery.data?.data?.price_only_mode ?? true
  const rankings = rankingsQuery.data?.data?.data ?? []
  const evaluationComplete = rankingsQuery.data?.data?.evaluation_complete ?? false

  // Find winner entry (if tender is already awarded)
  const winnerEntry = rankings.find((r) => r.is_winner)
  const isAwarded = tender?.status === "awarded"

  const isLoading =
    tenderQuery.isLoading || criteriaQuery.isLoading || rankingsQuery.isLoading
  const isError =
    tenderQuery.isError || criteriaQuery.isError || rankingsQuery.isError

  function handleRefetch() {
    tenderQuery.refetch()
    criteriaQuery.refetch()
    rankingsQuery.refetch()
  }

  function handleSelectWinner(bid: RankingEntry) {
    setSelectedBid(bid)
    setWinnerModalOpen(true)
  }

  // ── Loading / error ────────────────────────────────────────────────────────

  if (isLoading) return <PageSkeleton />

  if (isError || !tender) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">Failed to load evaluation data.</p>
        <Button variant="outline" size="sm" onClick={handleRefetch}>
          <RefreshCw className="size-3.5 mr-1" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back navigation */}
      <a
        href={`/tenders/${tenderId}`}
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
        aria-label="Back to tender detail"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Tender
      </a>

      {/* Page header */}
      <div className="space-y-1">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Bid Evaluation</h1>
          <Badge variant="outline" className="font-mono text-xs">
            {tender.reference_number}
          </Badge>
        </div>
        <p className="text-muted-foreground">{tender.title}</p>
      </div>

      {/* Winner Selected banner — shown when tender is awarded */}
      {isAwarded && winnerEntry && (
        <Alert className="border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30" role="status">
          <Trophy className="size-4 text-emerald-600" aria-hidden="true" />
          <AlertDescription className="text-emerald-700 dark:text-emerald-300">
            <strong>Winner Selected:</strong> {winnerEntry.supplier_name} —{" "}
            {formatCurrency(winnerEntry.total_amount, winnerEntry.currency)}
          </AlertDescription>
        </Alert>
      )}

      {/* Post-modal winner success banner */}
      {winnerSuccess && !isAwarded && (
        <Alert className="border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30" role="status">
          <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
          <AlertDescription className="text-emerald-700 dark:text-emerald-300">
            Winner selected successfully.
          </AlertDescription>
        </Alert>
      )}

      {/* ── Section 1: Criteria Configuration ── officer/admin only */}
      {isOfficer && (
        <section aria-labelledby="criteria-section-heading">
          <h2 id="criteria-section-heading" className="sr-only">
            Criteria Configuration
          </h2>
          <CriteriaPanel
            tenderId={tenderId}
            criteria={criteria}
            isConfigured={criteria.length > 0}
          />
        </section>
      )}

      <Separator />

      {/* ── Section 2: Score Entry Grid ── evaluator/admin only */}
      {isEvaluator && !isAwarded && (
        <section aria-labelledby="score-entry-heading">
          <h2 id="score-entry-heading" className="mb-3 text-base font-semibold">
            Score Entry
          </h2>
          <ScoreGrid
            tenderId={tenderId}
            bids={rankings}
            criteria={criteria}
            evaluationComplete={evaluationComplete}
          />
        </section>
      )}

      {isEvaluator && !isAwarded && <Separator />}

      {/* ── Section 3: Ranked Comparison Table + Chart ── all roles */}
      <section aria-labelledby="rankings-section-heading">
        <h2 id="rankings-section-heading" className="mb-3 text-base font-semibold">
          Ranked Comparison
        </h2>
        <RankingsSection
          tenderId={tenderId}
          rankings={rankings}
          evaluationComplete={evaluationComplete}
          priceOnlyMode={priceOnlyMode}
          isOfficer={isOfficer}
          tenderStatus={tender.status}
          onSelectWinner={handleSelectWinner}
        />
      </section>

      {/* ── Section 4: Read-only winner card (awarded state) ── officer only */}
      {isOfficer && isAwarded && winnerEntry && (
        <>
          <Separator />
          <section aria-labelledby="winner-card-heading">
            <h2 id="winner-card-heading" className="mb-3 text-base font-semibold">
              Selected Winner
            </h2>
            <Card className="border-emerald-200 dark:border-emerald-800">
              <CardContent className="pt-5">
                <div className="flex items-center gap-3 flex-wrap">
                  <div className="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                    <Trophy className="size-5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                  </div>
                  <div>
                    <p className="font-semibold text-lg">{winnerEntry.supplier_name}</p>
                    <p className="text-sm text-muted-foreground">
                      {formatCurrency(winnerEntry.total_amount, winnerEntry.currency)}
                      {winnerEntry.weighted_score !== null && (
                        <> &middot; Score: {formatScore(winnerEntry.weighted_score)}</>
                      )}
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </section>
        </>
      )}

      {/* Winner Selection Modal */}
      <WinnerSelectionModal
        open={winnerModalOpen}
        onOpenChange={setWinnerModalOpen}
        tenderId={tenderId}
        bid={selectedBid}
        onSuccess={() => setWinnerSuccess(true)}
      />
    </motion.div>
  )
}
