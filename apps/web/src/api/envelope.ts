import type { AxiosResponse } from "axios";

export type ApiEnvelope<T> = {
  data: T;
};

export function unwrap<T>(response: AxiosResponse<ApiEnvelope<T>>): T {
  return response.data.data;
}
