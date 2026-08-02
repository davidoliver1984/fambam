import axios from "axios";

const baseURL = import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8082";

export const api = axios.create({
  baseURL,
  headers: { Accept: "application/json" },
  withCredentials: true,
  withXSRFToken: true,
});

export async function csrf(): Promise<void> {
  await api.get("/sanctum/csrf-cookie");
}
