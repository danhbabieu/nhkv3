import type { SubmissionResumePlan } from "./resume-policy";

export type ResumeExecutionDependencies = {
  captureId?: string;
  captureKey?: string;
  callCapture: (arguments_: Record<string, unknown>) => Promise<unknown>;
  materializeMedia?: () => Promise<unknown>;
  continueEnrichment?: () => Promise<unknown>;
  retryProjection?: () => Promise<unknown>;
};

/** Execute every planner phase at the canonical owner boundary. */
export async function executeResumePlan(plan: SubmissionResumePlan, dependencies: ResumeExecutionDependencies): Promise<any> {
  switch (plan.phase) {
    case "MATERIALIZE_MEDIA":
      if (!dependencies.materializeMedia) throw new Error("RESUME_MATERIALIZE_HANDLER_UNAVAILABLE");
      return dependencies.materializeMedia();
    case "CONTINUE_ENRICHMENT":
      if (dependencies.continueEnrichment) return dependencies.continueEnrichment();
      return retryCapture(dependencies);
    case "REPAIR_MEDIA_USAGE":
      return retryCapture(dependencies);
    case "RETRY_PROJECTION":
      if (dependencies.retryProjection) return dependencies.retryProjection();
      return retryCapture(dependencies);
    case "NOOP":
      return null;
    default:
      return assertNever(plan.phase);
  }
}

async function retryCapture(dependencies: ResumeExecutionDependencies): Promise<unknown> {
  if (!dependencies.captureId || !dependencies.captureKey) throw new Error("CAPTURE_RETRY_CONTEXT_UNAVAILABLE");
  return dependencies.callCapture({
    capture_id: dependencies.captureId,
    idempotency_key: dependencies.captureKey,
    resume_mode: "RETRY",
  });
}

function assertNever(value: never): never {
  throw new Error(`UNSUPPORTED_RESUME_PHASE:${String(value)}`);
}
