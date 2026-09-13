import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { FamilyExport, FamilyExportDownload } from "../types/familyExport";

function base(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/exports`;
}

export async function getFamilyExports(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyExport[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyExport[]>>(base(familySlug), {
      signal,
    }),
  );
}

export async function requestFullFamilyExport(
  familySlug: string,
): Promise<FamilyExport> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyExport>>(`${base(familySlug)}/full`),
  );
}

export async function requestPersonalFamilyExport(
  familySlug: string,
): Promise<FamilyExport> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyExport>>(
      `${base(familySlug)}/personal`,
    ),
  );
}

export async function authorizeFamilyExportDownload(
  familySlug: string,
  exportId: string,
): Promise<FamilyExportDownload> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyExportDownload>>(
      `${base(familySlug)}/${encodeURIComponent(exportId)}/download`,
    ),
  );
}
