import { ref } from 'vue'
import { jobsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { FollowUp, Job } from '@/api/types'
import { confirmAction } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { formatDuration } from '@/utils/time'

/**
 * Starten, Pausieren, Fortsetzen, Abschliessen und Wiedereroeffnen –
 * mit Rueckfrage, Rueckmeldung und anschliessendem Umplanungsvorschlag.
 * Waehrend eine Aktion laeuft, ist die Arbeit gesperrt (busyId), damit
 * Mehrfachklicks nichts ausloesen.
 */
export function useJobActions(onChanged: (job?: Job) => unknown) {
  const auth = useAuthStore()
  const busyId = ref<number | null>(null)
  const followUp = ref<FollowUp | null>(null)

  async function run(
    job: Job,
    action: () => Promise<Job>,
    success: (result: Job) => string,
  ): Promise<Job | null> {
    if (busyId.value !== null) return null
    busyId.value = job.id
    try {
      const result = await action()
      toast(success(result), 'success')
      await onChanged(result)
      return result
    } catch (e) {
      toast(errorMessage(e), 'error')
      await onChanged()
      return null
    } finally {
      busyId.value = null
    }
  }

  const start = (job: Job) =>
    run(
      job,
      () => jobsApi.start(job.id),
      () =>
        job.status === 'in_arbeit'
          ? `„${job.title}“ fortgesetzt.`
          : `„${job.title}“ gestartet – die Zeit läuft.`,
    )

  const pause = (job: Job) =>
    run(
      job,
      () => jobsApi.pause(job.id),
      (result) => `Pausiert. Bisher gearbeitet: ${formatDuration(result.actualSeconds)}.`,
    )

  async function complete(job: Job): Promise<void> {
    const ok = await confirmAction({
      title: 'Arbeit abschließen?',
      message: job.started
        ? `„${job.title}“ wird abgeschlossen${job.running ? ' und die laufende Zeit gestoppt' : ''}.`
        : `„${job.title}“ wurde nie gestartet. Sie wird ohne erfasste Arbeitszeit abgeschlossen.`,
      confirmLabel: 'Abschließen',
      tone: 'success',
    })
    if (!ok) return

    const result = await run(
      job,
      () => jobsApi.complete(job.id),
      (done) => `Abgeschlossen. Ist-Zeit: ${formatDuration(done.actualSeconds)}.`,
    )
    if (result) await offerFollowUp(result)
  }

  async function reopen(job: Job): Promise<void> {
    const ok = await confirmAction({
      title: 'Arbeit wieder öffnen?',
      message:
        'Die bisher erfasste Arbeitszeit bleibt erhalten. Danach kann weitergearbeitet werden.',
      confirmLabel: 'Wieder öffnen',
    })
    if (ok)
      await run(
        job,
        () => jobsApi.reopen(job.id),
        () => `„${job.title}“ ist wieder offen.`,
      )
  }

  /** Nach dem Abschluss: koennen Folgearbeiten vorgezogen werden? */
  async function offerFollowUp(job: Job): Promise<void> {
    if (!job.scheduled) return
    try {
      const preview = await jobsApi.followUp(job.id)
      if (!preview || preview.moves.length === 0) return
      if (auth.isPlanner) followUp.value = preview
      else
        toast(
          'Früher bzw. später fertig als im Kalender eingetragen – die Planung kann die Folgetermine anpassen.',
          'info',
        )
    } catch {
      /* Vorschlag ist optional */
    }
  }

  return { busyId, followUp, start, pause, complete, reopen, offerFollowUp }
}
