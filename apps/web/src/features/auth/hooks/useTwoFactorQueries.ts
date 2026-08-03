import { useQuery } from "@tanstack/react-query";

import { getTwoFactorQrCode } from "../api/twoFactorApi";
import { twoFactorKeys } from "../api/twoFactorKeys";

export function useTwoFactorQrCodeQuery(enabled: boolean) {
  return useQuery({
    queryKey: twoFactorKeys.qrCode(),
    queryFn: ({ signal }) => getTwoFactorQrCode(signal),
    enabled,
    staleTime: Number.POSITIVE_INFINITY,
  });
}
