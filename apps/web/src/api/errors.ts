import axios from "axios";

export type AppError = {
  message: string;
  status: number | null;
};

export function toAppError(error: unknown): AppError {
  if (axios.isAxiosError(error)) {
    return {
      message: "Something went wrong. Please try again.",
      status: error.response?.status ?? null,
    };
  }

  return {
    message: "Something went wrong. Please try again.",
    status: null,
  };
}

export function toLaravelFieldErrors(
  error: unknown,
): Partial<Record<string, string>> {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return {};

  const data: unknown = error.response.data;
  if (typeof data !== "object" || data === null || !("errors" in data))
    return {};
  const errors = data.errors;
  if (typeof errors !== "object" || errors === null) return {};

  return Object.fromEntries(
    Object.entries(errors).flatMap(([field, messages]) =>
      Array.isArray(messages) && typeof messages[0] === "string"
        ? [[field, messages[0]]]
        : [],
    ),
  );
}
