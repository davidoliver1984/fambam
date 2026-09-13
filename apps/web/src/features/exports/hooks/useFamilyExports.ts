import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  authorizeFamilyExportDownload,
  getFamilyExports,
  requestFullFamilyExport,
  requestPersonalFamilyExport,
} from "../api/familyExportApi";
import { familyExportKeys } from "../api/familyExportKeys";

export function useFamilyExportsQuery(familySlug: string, enabled: boolean) {
  return useQuery({
    queryKey: familyExportKeys.all(familySlug),
    queryFn: ({ signal }) => getFamilyExports(familySlug, signal),
    enabled: enabled && familySlug !== "",
    retry: false,
    refetchInterval: (query) =>
      query.state.data?.some((item) =>
        ["pending", "processing"].includes(item.state),
      )
        ? 3_000
        : false,
  });
}

export function useFamilyExportMutations(familySlug: string) {
  const queryClient = useQueryClient();

  return {
    request: useMutation({
      mutationFn: () => requestFullFamilyExport(familySlug),
      onSuccess: () =>
        queryClient.invalidateQueries({
          queryKey: familyExportKeys.all(familySlug),
        }),
    }),
    requestPersonal: useMutation({
      mutationFn: () => requestPersonalFamilyExport(familySlug),
      onSuccess: () =>
        queryClient.invalidateQueries({
          queryKey: familyExportKeys.all(familySlug),
        }),
    }),
    download: useMutation({
      mutationFn: (exportId: string) =>
        authorizeFamilyExportDownload(familySlug, exportId),
    }),
  };
}
